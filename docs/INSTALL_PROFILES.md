# Install profiles and recipes

Every site is installed from its own config: `drush site:install --existing-config`,
then the seed step. This document covers the install profile a site runs
(`skeleton_site`), how a deployed site moves onto it from core's `standard`, and
where Drupal recipes fit (PROTOCOL D33). The per-site rollout lives with the
hosting platform, not here.

## 1. The profile: `skeleton_site`

`web/profiles/upstream/skeleton_site/` is upstream-owned (`upstream/OWNERSHIP`).
It is deliberately empty:

| Key | Why |
|---|---|
| `keep_english: true` | The reason it exists (§2). |
| `hidden: true` | The interactive installer never offers it; a site is installed from config. |
| no modules, no `install:` list, no config, no `.install` file | `config/sync` is the site. A profile that installed or configured anything would compete with it, and a profile with config of its own cannot be installed on a built site (§3). |

A site names it in `config/sync/core.extension.yml`:

```yaml
module:
  # ...
  skeleton_site: 1000
profile: skeleton_site
```

Install from config as always (the profile is read from that file):

```bash
ddev drush site:install --existing-config -y
ddev drush property:seed      # or the site's seed step
```

A new site starts on it too:

```bash
ddev drush site:install skeleton_site --locale=es -y   # the site's language (D20)
ddev drush config:export -y
```

**The first install of a non-English site with English keeps writing config,
so export once and install again.** Once English is kept, `locale` writes
English overrides of all shipped config while it finishes translations ("N
configuration objects updated" at `install_finish_translations`; about 160 on a
shop). Those land in the database, not in `config/sync`, and show as "Only in
DB" after the first install. Export them once (`config/sync/language/en/`), then
install again from that export. `config:status` must then say "No differences",
and that second install is the proof. A sync that lacks them yields a site
that differs from its config from the first day; a fleet config without them
came back with 21 more English overrides than its sync after a fresh install.

The installer logs `Translation file not found: …/<project>-<version>.en.po`
for every project. These are notices: there are no English `.po` files to
download.

## 2. Why: English is deleted on a non-English install

On a site whose default language is not English, core's installer deletes the
`en` language, and every English config translation with it, unless the install
profile says `keep_english: true`:

- `install_download_additional_translations_operations()`
  (`core/includes/install.core.inc`) deletes `en` when the install language is
  not English and the profile lacks the key. On an install from config, the
  install language is the sync's `system.site:default_langcode`.
- `install_config_revert_install_changes()` sets the key itself, but only when
  it finds config to revert: when some `hook_install()` changed active config
  during the install. A clean sync has nothing to revert.

So on `standard` the outcome depends on an accident. A site whose modules happen
to change config while installing keeps English. A clean one loses it. Core
tracks this as [#3545306](https://www.drupal.org/project/drupal/issues/3545306)
(postponed, no fix), split from
[#3337864](https://www.drupal.org/project/drupal/issues/3337864); the profile key
is the documented workaround. `standard` cannot carry it, so the fleet carries
its own profile.

Who is exposed:

| Site | Exposed? |
|---|---|
| Non-English default, English as a second language | Yes, today. A rebuild from config (disaster recovery, a fresh-build audit, a new environment) can come back without English and without its English config. |
| Non-English default, one language | Not yet. Exposed the day it adds English. |
| English default | No. The profile is for homogeneity. |

The evidence:

- `KeepEnglishInstallTest` installs a Spanish-default config with English and its
  English overrides through the installer's existing-config path and finds them
  all. With `keep_english` removed, the same test fails: the install comes out
  without `language.entity.en`.
- A fresh install of a real fleet config (Spanish default, English second) on
  core 11.4.11 kept English on both profiles, because one of its modules changed
  config during the install. That is the accidental safeguard above, not a fix.

## 3. Moving a deployed site from `standard`

Config import cannot do it. `ConfigImportSubscriber::validateModules()` rejects
a sync whose profile differs from the active one: *"Cannot change the install
profile from standard to skeleton_site once Drupal is installed."* No update
hook (D25) and no database push either. What core does allow is uninstalling a
profile (`InstallProfileUninstallValidator`), and a profile is a module.
`scripts/upstream/profile-converge.php` (upstream-owned) makes the switch:

1. Uninstall the old profile. Modules it installed stay installed, and config is
   untouched.
2. Name the new profile in `core.extension`.
3. Rebuild the container, because the active profile is the `install_profile`
   parameter and the module list finds a profile only once it is active.
4. Install the new profile, with weight 1000, as the installer would.

Afterwards the next `drush config:import` finds nothing to change in
`core.extension`.

It reads the target from `config/sync`. It refuses, and changes nothing, when:

- the sync names no profile, or a profile its own module list lacks;
- the profile is missing from the codebase, or ships config of its own;
- a module the database has installed is missing from the codebase. The switch
  rebuilds the container, so deploy the code first;
- config depends on the profile being left, since uninstalling would delete it;
- an installed module requires the old profile, or core's uninstall
  validators object.

A site whose profile matches gets one line and no change. A run that stopped
after step 2 is finished by the next. `-- --check` prints the plan and changes
nothing.

```bash
ddev drush php:script scripts/upstream/profile-converge.php -- --check
ddev drush php:script scripts/upstream/profile-converge.php
```

### The steps for one site

0. **The host runs the converge before every config import, once for the
   whole fleet.** The deploy command runs this right after the new container
   is up and before `drush deploy` (or its `config:import`):

   ```bash
   if docker compose exec -T -u www-data drupal test -f /opt/drupal/scripts/upstream/profile-converge.php </dev/null; then
       docker compose exec -T -u www-data drupal bash -lc \
           'cd /opt/drupal && vendor/bin/drush php:script scripts/upstream/profile-converge.php' </dev/null \
           || { echo "install profile converge FAILED; nothing imported" >&2; exit 1; }
   fi
   ```

   On a site whose sync still names `standard` it prints one line. If it fails,
   the deploy fails before the import. Until the host runs it, no site may
   merge step 2.
1. **The upstream sync brings the profile and the script.** A site that carried
   its own copy of the profile (`web/profiles/custom/skeleton_site`) deletes it
   in that same pull request. Two copies under one name are a defect (D30).
2. **The site's change is two lines of `config/sync/core.extension.yml`**,
   `standard: 1000` → `skeleton_site: 1000` and `profile: standard` →
   `profile: skeleton_site`, nothing else.
3. **Rehearse on a copy of production:** production's running code with the
   profile and script added, and its database. The expected sequence:
   - `drush cim` refuses with the message above;
   - `--check` prints the plan, the converge switches, and a second run says
     there is nothing to do;
   - `drush config:status` lists the same differences as before the switch;
   - `drush cim` has nothing to import, and pages answer as before.
4. **Deploy.** The host's converge switches the profile, and `drush deploy`
   then imports as usual.
5. **Local copies.** A copy filled from a production that has not switched yet,
   checked out on a branch that has, runs the converge once
   (`ddev drush php:script scripts/upstream/profile-converge.php`).

Rehearsed on production copies of three Spanish-default sites with English
(core 11.4.11): every step as above. Two observations from those rehearsals:

- A local `origin/master` can be older than production, which may run modules
  that ref lacks. Rehearse on the code production runs. The converge refuses to
  run when an installed module's code is absent, because a half-done switch
  is what that produced before the guard existed.
- A fresh build from a site's config can fail for reasons unrelated to the
  profile, for example a site module's `hook_install()` that does not return
  early while syncing. It fails the same way on both profiles.

**Rollback** is the pre-deploy database backup. Converging back onto
`standard` is not supported: `standard` ships config, and installing it on a
built site collides with the site's own.

## 4. Order of the rollout

1. The upstream: this profile, the script and the tests (one skeleton release).
2. The host: the converge step in the deploy command (step 0).
3. The sites, one per deploy and never two deploys of one site within an hour,
   in exposure order: non-English sites with English first, then non-English
   sites with one language, then English-default sites. A lab site leads each
   group. A site minted from now on starts on the profile.

## 5. Recipes as the per-site install

The question: can Drupal recipes be the differentiated part of a site's install
(shop, bookings, delivery, banners, map, a second language), on top of the
profile and the upstream modules, instead of a kit clone plus setup scripts run
by hand?

### What a recipe is, on core 11.4.11

- A `recipe.yml` lists modules and themes to install, other recipes to apply
  first, config to import (from the recipe's own `config/` or from extensions,
  `config.import`), config actions (`grantPermissions`, `simpleConfigUpdate`,
  `placeBlock`, `createForEachBundle`, …) and default content (`content/`).
  Applied with `drush recipe <dir>` (core's `RecipeCommand`), or at install time
  with `drush site:install <dir>` (the installer's `install_recipe_batch`).
- Core composes its own `standard` this way: `core/recipes/standard` is 27
  recipes.
- `RecipeCommand` takes a config checkpoint and rolls the config back if the
  apply fails.

### What it can do for the fleet

- **Turn a feature on, once, on a built site.** It installs the upstream
  module, imports the config that feature needs and grants its permissions.
  `recipes/appointments` (bookings) is the first: applied on a Spanish
  `skeleton_site` site it installed `appointments` and let visitors book.
  Applied again it changed nothing. A site setting changed in between survived
  (`AppointmentsRecipeTest` keeps that true). `recipes/maper-map` (D23) is the
  same kind.
- **Compose a new site at mint time:** `site:install skeleton_site --locale=…`,
  one `drush recipe` per feature, `config:export`, then the site's seeds. That
  replaces the kit clone and the hand-run setup scripts.

### What it cannot do

- **Remove or update.** There is no uninstall step, and nothing reapplies a
  newer version of a recipe to a site that has the older one. A recipe's
  imported config is `strict` by default (`ConfigConfigurator`; core
  [#3478669](https://www.drupal.org/i/3478669) considers changing that). A site
  that changed any config the recipe imports is refused: *"The configuration
  'appointments.settings' exists already and does not match the recipe's
  configuration"* (seen in the prototype). Changes to a feature after its first
  apply go through `config/sync` like every other change.
- **Be remembered.** Core keeps no list of applied recipes; it only dispatches
  `RecipeAppliedEvent`. An export after applying one does not mention it. What
  a site has is read from `core.extension` and its config, as today.
- **Own content.** Recipe content imports with `Existing::Skip`: created once,
  never updated. Content the code owns stays with `site_seed`, which converges
  on every deploy (D27).
- **Run in production.** A recipe writes active config. Applied on a live site,
  the next `config:import` would undo it, because `config/sync` lacks what it
  made. Recipes run on a local copy, and the export is what deploys.
- **Replace the profile.** A site installed from a recipe has no profile at all
  (`core.extension` has no `profile` key), so nothing can carry `keep_english`.
  In the prototype, a Spanish site installed from a recipe with `language` and
  `locale` came out with Spanish only.

### Non-English sites: one open hazard

On a Spanish `skeleton_site` site, the field config that `appointments` ships
(`langcode: en` in the module) was stored with **no `langcode` at all** when the
module was installed by the recipe. A plain `drush pm:install appointments` on
the same kind of site stored `langcode: es`. Seen on core 11.4.11. The cause is
not established; it is in the family of core
[#3337864](https://www.drupal.org/project/drupal/issues/3337864), whose
comments report recipes and config sync interacting badly on non-English sites.
Until it is understood, check an export made after a recipe for config without
a `langcode` before committing it.

### Recommendation

Use recipes as the feature switch on top of `skeleton_site` and the upstream
modules: one upstream-owned recipe per upstream feature, applied on a local copy
and exported, at mint time or when a site adds a feature. Keep `config/sync` as
the contract, seeds for content and the profile for the install. Do not
install a site from a recipe, run one in a deploy, or use one to change or
remove a feature a site already has. Next recipes, in the order sites need them:
the shop, delivery (`shop_delivery`), banners, and the second language
(`language`, `locale`, `content_translation`, `config_translation`). Each one
gets a kernel test like `AppointmentsRecipeTest`.

The second-language recipe carries one fix every bilingual listing needs. A
view of a translatable entity filters on `langcode`
`***LANGUAGE_language_content***`, sets `rendering_language` to the same, and
turns on `distinct` when it joins translatable terms. Without it, each item is
listed once per translation, always in the default language. The recipe can
apply it with config actions on the views it knows; a site's own views get it
in their config.
