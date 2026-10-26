# PROTOCOL

The rules of engagement for this project, plus the running decision
log. Every architectural choice gets a one-paragraph entry — future
you needs the *why*, not just the *what*.

## §1 — Working principle: DDEV-only

> All code operations (composer, npm, phpunit, vitest, drush,
> phpstan, phpcs, …) run inside DDEV. No bare-host fallback path.

This is non-negotiable. PHP versions, Node versions, ext-mongodb,
GD library quirks, locale settings — every "works on my machine"
trap dissolves once the only machine that exists is the DDEV
container.

Practical implications:

- Every `package.json` script is meant to be invoked via
  `ddev exec npm run <script>` (or `ddev npm run <script>`).
- Every `composer.json` script likewise: `ddev composer …`.
- `ddev drush ...` for Drush. Never bare `drush`.
- If a tool isn't available in DDEV's container, install it inside
  via `webimage_extra_packages:` or a custom Dockerfile under
  `.ddev/web-build/Dockerfile.example` — never `apt install` on
  the host as a workaround.

## §2 — Repo identity

Set in the project's gitconfig (or inherited from
`~/.gitconfig.ssdnodes` via path-conditional `includeIf` in the
host's `~/.gitconfig`). Do not commit identity-bearing config files
into the repo.

## §3 — Commit discipline

- Every commit ends with `Co-Authored-By: Claude Opus <...>` when
  AI-assisted.
- New work: NEW commit. Rebase/squash sparingly and only if it
  preserves traceability of decisions.
- Sensitive files (`.env`, `*.pem`, `.ddev/.env`) never in the
  staging area — `.gitignore` is belt-and-suspenders, never the
  only line of defense.

## §4 — Documentation hygiene

Living documents, in order of read frequency:

1. **`README.md`** — "How do I work this?" Five-minute orientation.
2. **`CHANGELOG.md`** — what changed, when. Append-only.
3. **`docs/PROTOCOL.md`** (this file) — the rules + decision log.
4. **`docs/BATTLE_SCARS.md`** — the lessons that cost time. Append
   anything that took >30 minutes to figure out.
5. **`docs/FEATURE_REQUESTS.md`** — the queue: proposed skeleton
   additions not yet built. An entry graduates by shipping, at which
   point it leaves that file and becomes a `Dn` decision here.

Project-shape-specific docs (THESIS, ARCHITECTURE, EDITORIAL,
SUBJECT, etc.) live alongside but vary per project; don't
prescribe them here.

## §5 — Decision log

Record every architectural choice as `Dn` (development), `En`
(epic-level), `Cn` (content/editorial). One paragraph: question,
chosen answer, one line of reasoning. The format is:

```
### D1 — <one-line question>

Answer: <one phrase>.

Reason: <one paragraph>.
```

Decisions are append-only. If a decision is overturned, add a
new entry referencing the old (`D7 supersedes D3`); never edit
the original.

### D1 — Drupal version

Answer: Drupal 11.4 on PHP 8.3 (refreshed 2026-08; was 11.3 at 2026-05).

Reason: Drupal 11 is the supported major; the composer constraint tracks
the current minor (`^11.4`) so `composer update` follows patch releases.
PHP 8.3 is what the contrib ecosystem expects and what the production
images run; moving to 8.4 is a fleet-wide decision (image base + every
property), not a skeleton-only bump.

### D2 — Local DB engine

Answer: MariaDB 11.4.

Reason: DDEV's default; well-tested with Drupal 11; easier MySQL
compatibility than 10.x. Switch to PostgreSQL only if a project
specifically needs it.

### D3 — Local web server

Answer: nginx-fpm.

Reason: Lower resource use than apache-fpm for local dev; faster
DDEV startup. Production-bound projects with Traefik routing
should switch to apache-fpm before deploying — see
`docs/BATTLE_SCARS.md` §"nginx vs apache behind Traefik."

### D4 — Stability

Answer: `minimum-stability: dev`, `prefer-stable: true`.

Reason: Several useful contrib modules (AI providers, paragraphs,
some recent additions) ship long-running RCs or dev branches
that are production-stable in practice but composer-unstable in
theory. The `prefer-stable: true` keeps stable releases when
they exist; the `dev` floor lets RCs resolve when they don't.

### D5 — Test runner (PHP)

Answer: PHPUnit 10.5 with strict mode on.

Reason: Drupal core's own bootstrap; failing-on-warning catches
deprecations early; risky-test failures catch tests that don't
actually assert anything.

### D6 — Production container base image pinning

Answer: `FROM drupal:11-php8.3-apache` — never the floating
`drupal:11-apache` meta-tag.

Reason: The unversioned `drupal:11-apache` tag tracks whatever PHP
version the Drupal maintainers default to. Today that's 8.3; when
they advance it to 8.4, every property silently rolls forward on
next `docker compose pull` — likely fine, occasionally a problem
(contrib module compatibility, JIT changes, deprecations). The
explicit `php8.3-apache` tag still tracks 8.3.x patch releases
(security fixes apply automatically) but won't cross the 8.4
boundary without a deliberate edit. Local DDEV matches via
`php_version: "8.3"`. Codified 2026-05-15 as platform-wide standard;
see webrunners `docs/PROPERTY_PROTOCOL.md` §1a.

<!-- Append project-specific decisions below this line. -->

### D13 — Test runner version (supersedes D5)

Answer: PHPUnit 11.5.

Reason: Drupal 11.3.3+ core-dev requires `phpunit/phpunit ^11.5`; the prior
`^10.5` pin (D5) makes `composer install` unsolvable on current Drupal 11.3.
D13 supersedes D5.

### D14 — Contrib repository

Answer: Bundle the `packages.drupal.org/8` composer repository.

Reason: Contrib modules are not on Packagist; without the drupal.org repo a
project installs core but no contrib. Baked in so every spawned project can
`composer require drupal/<contrib>` immediately.

### D15 — DDEV project type

Answer: `type: drupal` (version-agnostic).

Reason: `type: drupal11` is rejected by older DDEV (v1.23.5); `type: drupal`
is portable and auto-detects core 11.

### D16 — Node runtime line

Answer: the current active Node LTS — `nodejs_version: "22"` as of 2026-08
(Node 20 went EOL 2026-04-30). Bump when the line goes EOL, not before.

Reason: the container Node only drives theme tooling (vite, eslint,
stylelint) — nothing production-facing — so tracking the LTS line is cheap
and keeps toolchains installable. Pin a specific line, never `lts/*`, so two
clones on different days build the same assets. The same rule applies to CI:
every GitHub Action `uses:` stays on its Node-24 major (checkout@v7 …);
GitHub deprecated the Node 20 action runtime in 2025-09.

### D17 — Production deploy scaffold ships with the skeleton (supersedes D3)

Answer: `Dockerfile`, `.dockerignore`, `deploy/{docker-compose.yaml,
.env.example, settings.prod.php, entrypoint.sh}`, `config/sync/` and
`.github/workflows/build-and-push.yml` are part of the foundation and are
used **unchanged** by every project minted from it. Per-property values live
in the host `.env` and in three GitHub Actions settings (`PROD_URL`,
`DEPLOY_HOST`, `VPS_DEPLOY_KEY`) — never edited into the files. Local DDEV
runs `apache-fpm` to match the image (D3 said nginx-fpm locally + "switch
before deploying"; that switch was the first thing to drift).

Reason: the deploy path was the one part of a property that was copied by
hand from a sibling and then evolved in place. Four properties, four
Dockerfiles, three workflow variants, two of them without the health gate
that had already saved a third. When the fix for a shared problem (an audit
advisory, a deprecated action runtime, a missing bind-mount chown) has to be
re-derived per property, it gets applied to some of them. Baking the path
into the skeleton makes a new property correct by construction, and makes
"what does the fleet's deploy look like?" a single file to read. The gates
in the workflow — audit, image build, HTTP health — are the ones that have
each caught a real incident (BATTLE_SCARS §18–§21). The contract is
`docs/DEPLOY.md`.

### D18 — Outbound mail: one DSN, asserted defaults, loud failure

Answer: `drupal/symfony_mailer_lite` with a single `dsn` transport entity whose
`dsn` stays **empty in config** and is filled at runtime from `MAILER_DSN`.
`scripts/setup_mail.php` creates it, points `mailsystem.settings` defaults at
`symfony_mailer_lite`, and stands up the anonymous-accessible contact form at
`/contact`. `SITE_MAIL` and `CONTACT_RECIPIENT` are env-driven too. With
`MAILER_DSN` unset the site keeps the native transport and mail fails loudly —
never the `null` transport.

Reason: three things had to be true at once. **(1) No secret in git** — a DSN is
one string, so unlike the `smtp` module's host/port/user/pass (which stores the
password in `smtp.settings`, i.e. in `config/sync`) there is nothing to
accidentally export. **(2) Provider-agnostic** — the same env var takes
SendGrid, Mailgun, SES, Postmark or a plain relay, so switching providers is an
`.env` edit, not a config change. **(3) Failure must be visible** — the runtime
image has no MTA, and Drupal's contact form reports "Your message has been sent"
regardless, so the default-off state has to be noisy rather than convenient.
Chosen over `drupal/smtp` (password-in-config) and full `symfony_mailer`
(replaces the mail API wholesale — more surface than a property needs).

### D19 — SEO baseline: metatag + simple_sitemap + pathauto, shipped as modules, configured per project

Answer: the skeleton **requires** `drupal/metatag`, `drupal/simple_sitemap` and
`drupal/pathauto` (pulling `token` and `ctools`). It ships **no config for
them**, because every useful setting names a content type the skeleton does not
define. Each project configures its own:

- `pathauto.pattern.<bundle>` — one per bundle that should have a readable URL.
  Without a pattern, nodes stay at `/node/N`, which then goes into the sitemap.
- `simple_sitemap.bundle_settings.default.node.<bundle>` — one per bundle to
  index. **A sitemap with no bundle settings is not broken; it is empty by
  definition** and will contain only the front page.
- `metatag.metatag_defaults.*` — at minimum `global`, `front`, `node`, `403`,
  `404`. The `global` default is what emits `<link rel="canonical">`.

Reason: this was a real fleet-wide gap, not a hypothetical. Five properties
built from a skeleton that shipped none of these ended up with **four different
configurations**: two had the full layer, one had a sitemap but no canonicals
(144 URLs indexed with no canonical tag), and two had nothing at all — no
canonical anywhere and `/sitemap.xml` returning 404. Nobody chose that; it is
what happens when the baseline lives in whoever set the property up rather than
in the scaffold.

**What core does and does not emit — settle this before diagnosing anything.**
Core emits `<link rel="canonical">` on **entity canonical routes** — a node
page, a commerce product page — with no contrib module at all. It emits none on
anything else: the front page, views pages, custom controller routes. Verified
across the fleet: a property with metatag absent still serves a canonical on
`/node/2`, and serves none on `/`.

So metatag is not what makes canonicals exist. What it buys is:

- canonical on the **non-entity** routes core leaves bare,
- `<title>` patterns, Open Graph and Twitter tags,
- per-bundle editorial override.

Practical consequence when auditing: **probe a real entity URL and a non-entity
URL separately, and confirm the entity URL actually exists.** Testing `/node/1`
proves nothing if that node was never created — a 404 carries no canonical
either, which looks identical to the bug you are hunting.

**Verify from outside, not from the admin UI:**

```bash
curl -sL https://example.com/ | grep -oE '<link rel="canonical" href="[^"]*"'
curl -sLo /dev/null -w '%{http_code}\n' https://example.com/sitemap.xml
```

An empty sitemap on a site with no content types is correct — check the site
actually has indexable content before treating one URL as a defect.

**Ordering note:** `pathauto` before `simple_sitemap` generation. Aliases are
*content*, not config, so `config:import` cannot ship them — a pattern only
fires when an entity is saved. Existing nodes predating a pattern keep their
`/node/N` URLs until something regenerates them, and the sitemap will publish
those raw paths. Regenerate them in the site's seeder (it runs after
`drush deploy`, on every deploy): call `pathauto.generator`'s
`updateEntityAlias()` over the affected bundles. It is idempotent, so it is
free on every later deploy. Never in a deploy hook (D25).

### D20 — Language is declared at scaffold time, and everything URL-shaped follows from it

Answer: **every property states its default language once, deliberately, before it
has content.** Monolingual is a legitimate choice and must be an explicit one.
Everything downstream — pathauto prefixes, negotiation, alias langcodes,
canonical URLs — derives from that declaration rather than being decided per
feature by whoever happens to be adding one.

The declaration lives in `config/sync/system.site.yml` (`default_langcode`), is
set when the property is scaffolded, and is **not** left at Drupal's `en`
default by omission. "Nobody changed it" is not a decision.

**If monolingual:** do not install `language`, `locale`, `content_translation`
or `config_translation`. Their absence is the record of the choice. Path
prefixes are written in that one language.

**If multilingual:**

- **Negotiation is `language-url` — path prefixes, not query strings.**
  `/es/…` and `/en/…`. A query-parameter negotiator (`?lang=en`) makes every
  page reachable at two URLs that differ only by a parameter, which doubles the
  sitemap and creates a duplicate-content shape that canonical tags then have to
  paper over. Path prefixes are the shape search engines expect and the one
  hreflang addresses cleanly.
- **Pathauto patterns are per language, via the pattern's `language` selection
  condition.** The `[node:title]` half of a pattern adapts to the translation on
  its own; **the prefix never does.** One pattern therefore means one language's
  words for every translation — `/venues/…` on Spanish content. If the property
  is multilingual, ship paired patterns:

  ```
  venue_es  condition langcode=es  ->  /lugares/[node:title]
  venue_en  condition langcode=en  ->  /venues/[node:title]
  ```

- **Never hand-write a path alias.** `path_alias` entities carry a langcode, and
  pathauto sets it from the entity being aliased. Hand-written aliases are how a
  site ends up with one language's words filed under another language's code.

**Prefix vocabulary follows the default language, not the developer's.** A
property whose `default_langcode` is `es` gets `/relojes/`, `/marca/`,
`/eventos/` — not the English words that came to mind while writing the pattern.

Reason: this was a real fleet-wide divergence, not a hypothetical. Five
properties built from this scaffold ended up with **two defaults `es` and three
`en`, two different negotiation strategies plus two properties with none, and
language modules installed on three of five** — none of it chosen as a fleet
position, because the scaffold had no position to inherit. The specific failure
that surfaced it: pathauto patterns were written with English prefixes on a
property whose content is Spanish-language listings, purely because its
`default_langcode` still said `en` from the installer.

**Verify from outside, per language:**

```bash
curl -sL https://example.com/es/<path> | grep -oE '<link rel="canonical" href="[^"]*"'
curl -sL https://example.com/ | grep -oE '<link rel="alternate" hreflang="[^"]*"'
```

**Change it before there is content.** Default language and prefix vocabulary
are nearly free to change while a property has no published nodes and nothing
indexed; afterwards each change is a redirect map and a re-crawl. Settle it at
scaffold time.

### D21 — amends D20: `default_langcode` is not a switch, and flipping it destroys field data

Answer: **a monolingual site's language cannot be changed by editing
`system.site.default_langcode`.** Without the `language` module there is exactly
one installed language and it stays `en` no matter what that key says. Changing
a property's language is a migration — install `language` + `locale`, add the
language, set it default, migrate content — never a config edit.

D20 said "monolingual ⇒ do not install the language modules" and stopped there.
That is right, but it reads as though the default language is still yours to
pick. It is not, and the way it fails is expensive.

**What actually happens.** Set `default_langcode: es` with no `language` module
and `\Drupal::languageManager()->getDefaultLanguage()` still returns `en`,
because `es` was never installed. Now save any entity with `langcode = es`:
Drupal writes the *`es` translation*, which does not exist, so **every field
comes back empty and the original values are overwritten.** Setting the langcode
back does not restore them — the data is gone, not hidden. There is no error and
no warning; the save succeeds.

Observed on 350 entities: `field_local_date`, `field_city` and `field_capacity`
all blanked. Recovery was a full database re-pull from production.

**The rule that follows:**

- Never write `langcode` on an entity for a language the site has not installed.
  Check `\Drupal::languageManager()->getLanguages()` first, not
  `system.site.default_langcode` — those two disagree exactly when it matters.
- Path *prefixes* are a free, safe change (they are pattern config, and aliases
  regenerate). The site's internal *langcode* is not. A property whose content
  is Spanish can perfectly well run on an `en` langcode with Spanish URLs; that
  is cosmetically odd but harmless, and it is the correct interim state until
  someone does the migration properly.
- Do the migration before the property has content, or budget for it as a
  migration with a database backup taken first.

Reason: this is the second time this class of thing has bitten — a config value
that looks like a setting but is actually a declaration the rest of the system
was built around. Recording it as its own decision so D20's "declare it at
scaffold time" carries the weight it needs: at scaffold time it is a keystroke,
afterwards it is a migration with a data-loss failure mode.

### D22 — The skeleton is an upstream, not only a starter

**Decision.** Sites built on this skeleton keep merging it. The upstream owns an
exact list of paths (`upstream/OWNERSHIP`): the shared Composer baseline as a
metapackage (`upstream-configuration/`), the image, the pipeline, the test gate
and the platform settings. The site owns everything else. `scripts/upstream/sync.sh`
applies the upstream; `upstream-sync.yml` proposes it weekly as a tested pull
request. Contract: [UPSTREAM.md](UPSTREAM.md).

**Why.** Copying a skeleton once gives every site a good start and then lets each
one drift. Each drift is small and has a reason, and together they meant a
security fix had to be applied by hand per site, every site in a different
state: a different deploy gate, a missing test job, an older Dockerfile. A
custom upstream, the Pantheon model, turns "fix it in every site" into "fix it
once, and let the sites merge it".

**Why ownership is exact rather than a three-way merge.** A file that both sides
edit is a merge conflict waiting for the worst moment, which for this pipeline
is a security release. So no file has two owners. A site that needs an upstream
file to differ either sets a variable that file reads, or uses the site-owned
file that file includes (`deploy/settings.site.php`), or changes the upstream
for everyone.

**Why a metapackage.** It keeps the site's root `composer.json` the site's own:
the upstream changes its requirements without ever editing a file the site
owns, which is what keeps the sync conflict-free.

### D23 — One map for the fleet: maper, with private files by construction

**Decision.** A site that shows a map uses `drupal/maper` with its OpenFreeMap
provider. The pieces:
- **Baseline:** `drupal/maper` is in `upstream-configuration/composer.json`,
  installed everywhere and enabled per site, like google_tag and adsense.
- **Enabling:** `recipes/maper-map` installs maper and maper_openfreemap,
  turns cached live fetch on, and grants `view maper maps` to anonymous and
  authenticated (`drush recipe ../recipes/maper-map`).
- **Contact:** `deploy/settings.prod.php` sets maper's contact from
  `MAPER_CONTACT`, else `+https://$APEX`. maper refuses to fetch without one,
  so no site has to remember it. A DDEV site has no contact and never fetches.
- **Vue:** maper's browser library comes from asset-packagist into
  `web/libraries/vue`. Composer reads repositories and plugins from the root
  only, so the starter's root `composer.json` carries the asset-packagist
  repository, `oomphinc/composer-installers-extender` and
  `npm-asset/vue: 3.5.43`. A site adopting the upstream adds them to its own
  root. Without them maper loads Vue from jsDelivr.
- **Private files:** maper keeps its tile cache in the private files path.
  Production has had one by construction since D17: `settings.prod.php`, the
  `./data/drupal-private` volume, the Dockerfile and the entrypoint's chown.
  DDEV now gets `<repo>/private` from `scripts/dev/private-path.sh`, a
  post-start hook, because the local `settings.php` is gitignored.

**Why.** The homogeneity rule: one solved problem applies to every site. A
fleet site's map ran on a contrib module whose advisory kept that site's
composer audit red. That held its core security update back while every other
site took it. Replacing the module also showed what a copied site lacks: it
had no private files path and no volume for one, because it predates D17 and
never adopted the upstream. That is a failure by omission which D17 and D22
exist to make impossible. The two lessons of the swap itself are
BATTLE_SCARS §41 and §42.

**Pinning.** maper is in alpha, and alphas carry no upgrade path (reinstall
between them), so the baseline pins an exact version, never a range. Moving
the pin is a deliberate upstream change, with the reinstall in its notes.

### D24 — A visits report in the admin: ga4_report, the first upstream module

**Decision.** `web/modules/upstream/ga4_report` draws a site's Google Analytics 4
data, its Cloudflare edge cache and its AdSense earnings in its own admin
(`/admin/reports/ga4`) from stored snapshots, one per source. It is the
first module the upstream owns: `web/modules/upstream/` is listed in
`upstream/OWNERSHIP`, so every site receives it and its fixes through the sync,
and enables it per site. The pieces:

- **Data:** one fixed set of GA4 Data API reports (nine, two more when the site
  names internal traffic, one per kind of page), fetched through REST
  `batchRunReports` with core's Guzzle and `google/auth` (in the baseline
  metapackage), normalised once per refresh and stored in the key-value
  collection `ga4_report`. Refreshed by a button, `drush ga4-report:refresh`
  or cron on an interval; a page view never calls Google. A refresh replaces
  the snapshot only when every report arrived.
- **Charts:** Apache ECharts 6.1.0 from asset-packagist, pinned in the root
  `composer.json` like Vue (D23). Every chart's numbers are also a table.
- **Per site, in config:** property ID, dates, refresh interval,
  internal-traffic conditions, kinds of page (path patterns), marked dates.
- **Two more sources, optional and independent:** Cloudflare's GraphQL
  Analytics API (requests by cache status per minute over 24 hours, and per
  day) and the AdSense Management API (earnings per day). Each source keeps
  its own snapshot and its last good one when a refresh fails.
- **Secrets by reference:** settings.php names one credentials file per source
  (`GA4_REPORT_CREDENTIALS`, `GA4_REPORT_CLOUDFLARE_TOKEN`,
  `GA4_REPORT_ADSENSE_CREDENTIALS` in production, mounted read-only from
  `./secrets`). Google files must be a service account key or an
  authorized-user file; the Cloudflare file holds one token with Zone ·
  Analytics · Read. AdSense reads as a person, so its file is a refresh token
  minted once by the owner with `drush ga4-report:google-consent`, from an
  OAuth client whose consent screen is published (Testing tokens die in seven
  days).
- **City positions:** a bundled GeoNames table (cities of 15,000+, CC BY 4.0),
  because Analytics names cities and never places them.

**Why stand alone.** Of the drupal.org modules that read GA4 reports, the
broadest (`google_analytics_reports` 4.0.1) still calls the client class
google/analytics-data removed in v0.22.0 (its fix sat in review through
2026-10) and only caches; `google_analytics_counter` keeps page views per path
and nothing else. Both were looking for maintainers. The report a site needs is
multi-dimensional and must not depend on Google answering at view time.

**Why REST and not google/analytics-data.** One endpoint does not need gRPC,
protobuf and a generated client in beta, whose one breaking surface change is
what broke the contrib module above.

**Why ECharts.** Every form the report draws is native to it (Sankey, sunburst,
treemap, heatmap, scatter, stacked area and bars) except the mosaic, a short
custom series; tooltips, legends, ARIA descriptions and resizing come with it.
d3 would hand-build each chart; Chart.js and Observable Plot lack the Sankey,
sunburst and treemap.

**Why upstream-owned, not site-owned.** `web/modules/custom/` belongs to each
site (D22): a module placed there reaches new sites only. The lint and test
gates (phpcs, phpstan, phpunit, `scripts/ci/tests.sh`) cover
`web/modules/upstream/` too.

### D25 — Never update, post-update or deploy hooks

**Decision (Carlos, standing rule).** No `hook_update_N()`, no
`hook_post_update_NAME()`, no `hook_deploy_NAME()`, in any module or theme
on this platform. Nothing is published that another database depends on, so
`config/install` is the scaffold and `config/sync` is the contract, and data
the site needs ships as a seeder that every deploy runs. A module leaves in
two deploys: uninstall through `config/sync`, then remove the code
(BATTLE_SCARS §41).

**Enforced, not advised.** The rule was written down and still broken more
than once, so the `integrity` gate of `scripts/ci/tests.sh` fails on any such
function, or on any `*.post_update.php` or `*.deploy.php` file, in custom or
upstream code. A site cannot build an image that carries one.

### D26 — One cache protocol for every site: edge_purge is upstream

**Decision (Carlos, 2026-10-20: "the entire cloudflare/apc/mysql cache
protocol — need to be applied to all properties").** Every site behind the CDN
runs the same cache protocol, and it reaches them through the upstream, never
as a per-site copy:

- `edge_purge` lives in `web/modules/upstream/edge_purge` (upstream-owned).
  A site that carried its own copy in `web/modules/custom/` deletes it in its
  adoption pull request; the module keeps its name, so nothing reinstalls.
  `RenderFingerprint` counts upstream modules as render code, and a file moved
  unchanged from `modules/custom` to `modules/upstream` is not a change, so the
  adoption release itself purges nothing.
- `deploy/settings.prod.php`: the CDN keeps tagged pages 30 days
  (`EDGE_PURGE_CDN_MAX_AGE`), bin row limits come from `CACHE_MAX_ROWS_*`,
  127.0.0.1 is a trusted proxy (the verify render).
- `Dockerfile`: `zend.assertions=-1` joins the platform block.
- Per site, in its own config: `automated_cron` interval `0`; the host runs
  `drush cron`.
- The host half (purge timer, warmer, verify pass, soft rebuild in the
  deploy, host cron, saturation probes) belongs to the hosting platform; the
  skeleton ships `scripts/edge-purge-drain.sh` as the minimal purge timer for
  a host without one. `docs/DEPLOY.md` §7 is the checklist.

The scars behind it: BATTLE_SCARS §40 (amended), §43, §44, §45, §46.

### D27 — One seeder for every site: site_seed is upstream

**Decision (Carlos, 2026-10-22).** The seed step (D25: every deploy runs the
site's `property:seed`) is one engine in the upstream, `site_seed`, not a
seeder each site writes. Sites carried their own copies of one shop seeder,
and a fix had to be made in each copy, or was made in one and missing from the
others: variations saved before their product exist came out untitled, and
add-to-cart answered 500 on the sites that lacked the fix.

- `site_seed` converges a seed file section by section (stores, taxonomy,
  products, menu, shipping methods, aliases, translations), matched on stable
  keys (SKUs, names with `formerly:`), never on titles; a second run changes
  nothing; `--check` writes nothing.
- A site keeps only its data (`data/seeds/`); whatever is truly the site's own
  stays a handler of its own (tag `site_seed.handler`). *Amended by D29:* the
  `property:seed` command is the engine's too, not the site's.
- The default store is born under the uuid `config/sync` names, and every
  variation gets a title: both fixes live in the engine once.
- Moving a site onto it is rehearsed on a copy of its production database with
  `--check` first: an existing entity must be adopted, never duplicated.

### D28 — Sign-in is guarded the same way on every site, switched on by the host

**Decision (Carlos, 2026-10-21: "anything allowed in the free tier will be
done", on a security pass whose staff and sign-in items belong upstream).**
Every site's staff accounts can rewrite the site, and every sign-in form faced
the internet with no challenge. One upstream module, `sign_in_guard`, carries
the answer for all of them:

- **Two-factor sign-in** (`tfa`, TOTP plus recovery codes) required for
  `administrator` and `content_editor`; three sign-ins to enrol, then a code
  every time.
- **Cloudflare Turnstile** on `/user/login` and `/user/password`, free, with
  no puzzle for people.
- Each is switched on by the host's environment (`TFA_ENCRYPTION_KEY`,
  `TURNSTILE_KEYS`), through runtime config overrides, never by `config/sync`.
  A local copy, CI or a fresh build has no keys and changes nothing, so no
  environment can lock itself out by importing another's config, and
  `config:status` stays clean everywhere.
- Secrets never enter config: both Key entities read the environment.
- A weekly OWASP ZAP baseline (`security-scan.yml`, passive, report only)
  reads each live site at its repository variable `SITE_URL`;
  `security_review` ships in the baseline for a local review
  (`drush en security_review && drush secrev`, never exported).

### D29 — The managing admins exist on every site, kept by code, listed by the host

**Decision (Carlos, 2026-10-26: the managing admin accounts, "both admin",
seeded into every property).** The people who manage the fleet had accounts
only where someone had made one by hand, under whatever name and role that
day. One upstream module, `managing_admins`, keeps them:

- The host lists them in each site's environment, `MANAGING_ADMINS`
  (`Name:mail,Name:mail`). **Never in this repository or in config:** the
  skeleton is public and the list is personal data. A local copy, CI and a
  fresh build have no list and change nothing.
- On every deploy the host's seed step runs `drush managing-admins:converge`
  after the site's `property:seed` (seeders only, D25): each listed account
  exists, matched by e-mail, with `administrator`. A missing one is created
  active and **without a password**; its owner gets in through a password
  reset to their own mailbox, then enrols two-factor (D28).
- It never touches another account, never changes an existing account's name,
  password, status or other roles, and sends no mail. Leaving the list does not
  remove an account or its role: that is done by hand.
- An ambiguous match (two accounts on one listed e-mail) stops the deploy's
  seed step instead of guessing.

### D30 — Code two sites could run is upstream code; the seed command is the engine's

**Decision (Carlos, 2026-10-23, on a fleet audit of custom modules).** The
audit found the same code in several sites and site modules with nothing of
their site in them. Both are the defect D22 exists to prevent.

- **`property:seed` belongs to `site_seed`** (`drush site-seed:site`). After
  D27, every shop still carried its own seed command and install glue, the
  same file in each but for the brand. The engine now runs `data/seeds/*.yml`
  of every installed site module, so a site keeps its data and nothing else. A
  site that enables `site_seed` deletes its own `property:seed`: two commands
  under one alias are a defect.
- **A module's own seed section is a handler the module ships**, registered
  from its service provider only where `site_seed` is installed, so the module
  does not depend on the engine (`appointments` for `appointment_services`).
- **Generic features move upstream under generic names**: `appointments`
  (with `appointments_commerce`) and `shop_delivery`. A site enables them
  where it uses them. A site whose module moves under the same name deletes
  its copy in the pull request that syncs the upstream copy in. A site whose
  module moves under a new name, and whose module any deployed database holds
  (a lab counts), keeps running its own copy until a planned switch: the
  old module is uninstalled while its code is still there (two deploys,
  BATTLE_SCARS §41), and stored plugin ids move by a data step, never by an
  update hook (D25). Rehearsed: deleting the code in the same deploy stops
  `drush deploy` with "The module … does not exist".
- **An upstream module may need contrib that not every site carries.** The
  test gate leaves such a module out of phpstan and PHPUnit where its
  dependencies are missing (`scripts/ci/unmet_upstream.php`), so a site without
  Commerce stays green while carrying `shop_delivery`. The module stays in the
  codebase either way, because ownership is exact.
