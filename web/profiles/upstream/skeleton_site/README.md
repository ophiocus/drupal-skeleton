# Skeleton site (install profile)

The install profile of a site whose whole definition is `config/sync`. It
carries no modules, config or install hook of its own: a site is installed
with

```bash
drush site:install --existing-config
```

and `core.extension` in `config/sync` names this profile (`profile:
skeleton_site`, and `skeleton_site: 1000` in its module list).

## Why a profile at all

Core's `standard` profile cannot be told to keep English. On a site whose
default language is not English, `site:install --existing-config` deletes the
English language, and every English config translation with it, unless the
install profile declares `keep_english: true`. The installer's own safeguard
(`install_config_revert_install_changes()`) sets that flag only when it finds
config to revert, and a clean sync has none. Drupal core issue
[#3545306](https://www.drupal.org/project/drupal/issues/3545306) tracks it;
the profile key is the documented workaround.

A site with English as a second language therefore needs this profile; a
site in one language loses nothing by using it.

## Upstream

This directory is upstream-owned (`upstream/OWNERSHIP`, PROTOCOL D33). A
deployed site moves onto it with `scripts/upstream/profile-converge.php`,
because config import refuses a profile change; `docs/INSTALL_PROFILES.md` has
the steps. Keep it empty: a profile that shipped config could not be installed
on a built site.
