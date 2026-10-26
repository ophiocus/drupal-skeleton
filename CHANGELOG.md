# Changelog

All notable changes to this skeleton are recorded here. Format
loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Skeletons don't really do semantic versioning — date stamps tell
you whether the foundation you cloned is recent enough.

## 2026-10-26 — site_seed: a store's place on the map

- `stores:` rows take an optional `location: {field, lat, lon}`: the store's
  Maper point field, converged like any seeded field (compared at the
  column's seven decimals; a point moved by hand returns). A shop with a map
  no longer writes a handler of its own for it. Commerce kernel test
  `testStoreLocation`; README updated.

## 2026-10-26 — property:seed is site_seed's; appointments and shop_delivery are upstream (D30)

- `site_seed`: `drush site-seed:site` (alias `property:seed`, `--check`) runs
  `data/seeds/*.yml` of every installed module that is not core or contrib.
  `SeedRunner::runSite()` and `seedFiles()` do the same from PHP, for a site's
  install hook. A site enabling `site_seed` deletes its own seed command. The
  README no longer claims `--check` is a rolled-back transaction. Kernel
  tests `testRunSiteFindsModuleSeeds` and `testRunSiteWithoutSeedFiles`.
- New upstream module `appointments` (+ `appointments_commerce`): booking on
  one calendar. It ships a `site_seed` handler for `appointment_services`,
  registered only where `site_seed` is installed. `ServiceSeeder::converge()`
  takes `$write` for check runs.
- New upstream module `shop_delivery`: home delivery on Commerce (Domicilio
  shipping method, door pin on a maper map, dispatch board, courier page,
  settlement, tracking link). Permissions `dispatch shop deliveries`,
  `settle shop deliveries`, `deliver shop shipments`.
- Test gate: `scripts/ci/unmet_upstream.php` lists upstream extensions whose
  dependencies the codebase lacks. `tests.sh` leaves them out of phpstan and
  PHPUnit, and now also runs upstream submodules' suites.
- **For a site that carries its own copy** of `appointments` (or the delivery
  module under its old name), the pull request that syncs these in also
  deletes the copy (D26: two copies of one module name break discovery).

## 2026-10-26 — managing_admins: the fleet's managing admins on every site

- New upstream module `web/modules/upstream/managing_admins` (PROTOCOL D29).
  `drush managing-admins:converge [--check]` makes every account the host
  lists in `MANAGING_ADMINS` (`Name:mail,…`, the site's `.env`) exist, matched
  by e-mail, with the `administrator` role. New accounts get no password;
  existing ones only gain the role; no other account is touched and no mail
  is sent. It prints names, never addresses.
- The list stays off this public repository: the host is its source. Without
  `MANAGING_ADMINS` (local, CI, a fresh build) the command does nothing.
- `deploy/.env.example` documents the key.
- **A site syncing to this** enables the module in the same pull request
  (`managing_admins: 0` in `core.extension.yml`; it ships no config). The
  host's seed step (`tec-seed`) runs the command on every deploy where it
  exists.

## 2026-10-26 — maper 1.0.0-alpha3: one location field

- `upstream-configuration`: `drupal/maper` 1.0.0-alpha2 → **1.0.0-alpha3**.
  The lock also takes `drupal/metatag` 2.2.1 and `guzzlehttp/guzzle` 7.16.1.
- alpha3 places every node by a Maper point field. Gone: the site-wide
  coordinate fields (`maper.settings` `coordinates`), the block's "site's
  coordinate fields" option, the Views style's `latitude_field` /
  `longitude_field` (now `point_field`), and Maper: Nominatim's
  `town_lat_field` / `town_lon_field` (now `point_field` and
  `town_point_field`). Nothing changes in the database.
- **A site syncing to this brings its config to the new shape in the same
  pull request:** `maper.settings` without `coordinates`, and every Views
  style, block and Nominatim setting on a point field. Maper's release
  notes: drupal.org/project/maper, 1.0.0-alpha3.

## 2026-10-24 — ga4_report: the edge hit rate is readers', the cache warmer counted apart

- `CloudflareSource`: the minute series (the 24 h hit-rate tile, the minute
  card) leaves out the platform's cache warmer (`webrunners-cache-warm/1`,
  `userAgent_neq`), and a second aggregate counts the warmer over the same
  window (`warmRequests`, `warmHitRate`, its own tile). On one site and day,
  the warmer made 53 % of the edge's requests, and the tile read 64.5 % while
  readers got 88 %. The daily dataset cannot be filtered by user agent and
  still includes it; the README says so.

## 2026-10-23 — Kernel tests run in separate processes

- `edge_purge` (`PurgeQueueInvalidatorTest`, `SoftRebuildTest`) and
  `site_seed` (`SeedRunnerCoreTest`, `SeedRunnerCommerceTest`) Kernel tests
  carry `#[RunTestsInSeparateProcesses]`. Without it, Drupal 11.3+ reports a
  deprecation and Drupal 12 throws. Every upstream Kernel and Functional test
  now has it.

## 2026-10-23 — sign_in_guard: two-factor for staff, Turnstile on sign-in (D28)

- New upstream module `web/modules/upstream/sign_in_guard`: TOTP two-factor
  sign-in required for administrators and content editors once the host sets
  `TFA_ENCRYPTION_KEY`, and Cloudflare Turnstile on the sign-in and
  password-reset forms once it sets `TURNSTILE_KEYS`. Runtime overrides only;
  without the keys it changes nothing. Kernel test `SignInGuardTest`; README in
  the module.
- `upstream-configuration`: `drupal/tfa` ^1.13, `drupal/key` ^1.22,
  `drupal/encrypt` ^3.2, `drupal/real_aes` ^2.6, `drupal/captcha` ^2.0,
  `drupal/turnstile` ^1.2, and `drupal/security_review` ^3.1 (for a local
  review, never enabled in `config/sync`).
- New workflow `security-scan.yml` (upstream-owned): a weekly OWASP ZAP
  baseline of the live site at the repository variable `SITE_URL`; passive,
  report as the run's artifact, never fails, opens no issue.
- `deploy/.env.example`: `TFA_ENCRYPTION_KEY`, `TURNSTILE_KEYS`.

## 2026-10-22 — image_fallback: the logo in place of an empty image or media field

- New upstream module `web/modules/upstream/image_fallback`: an empty listed
  field renders a fallback image at render time, inside the field's own
  wrapper plus an `image-fallback` class. `image_fallback.settings` holds a
  `default` (`logo`, the default theme's logo; `path`, a file relative to the
  Drupal root; or `none`) and a list of `fields`, each taking the default or
  naming its own source, path and alt. No field is listed by default, so
  enabling it changes nothing. No file entity, no field config, no update
  hook. Cache tags only on the renders it touches; listing a field
  invalidates that entity type's renders once. Kernel test
  `ImageFallbackTest`; README in the module.

## 2026-10-22 — site_seed: one seeder for every site (D27)

- New upstream module `web/modules/upstream/site_seed`: converges a site's
  seed file (stores, taxonomy, products, menu, shipping methods, aliases,
  translations) onto the database, matched on SKUs and names (with
  `formerly:`), idempotently. `drush site-seed:run <file> [--check]`.
- Every variation gets a title (an untitled one made add-to-cart answer 500),
  and the default store is born under the uuid `config/sync` names.
- Kernel tests: the core handlers everywhere; the Commerce handlers wherever
  Commerce is installed (skipped in the upstream itself).

## 2026-10-22 — ga4_report: no empty page-kinds card

- The report page draws the page-kinds card only when the settings name at
  least one kind. Without kinds, the landing-pages card stands alone with a
  note pointing to the settings. Functional test `testNoKindsNoKindsCard`.

## 2026-10-21 — image: optional assets/ for the scaffold, per-site Apache workers

- `Dockerfile`: `assets/` is copied before `composer install`, so a site whose
  composer scaffold appends a file from it (e.g. `assets/robots-additions.txt`
  onto robots.txt) builds. The copy is a wildcard: a site without `assets/`
  copies nothing and builds as before.
- `Dockerfile` + `build-and-push.yml`: the prefork numbers come from the build
  argument `APACHE_PREFORK`, fed by the repository variable of the same name
  (default `3 2 6 20`, the previous values). A site with a larger container
  sets it, e.g. `4 3 10 40`. The build stops on anything but four integers.
  The rest of the tuning block stays identical in every site.

## 2026-10-21 — maper pin to 1.0.0-alpha2 (PROTOCOL D23)

- `upstream-configuration/composer.json`: `drupal/maper` `1.0.0-alpha1` →
  `1.0.0-alpha2`, still exact. A site already running alpha2 could not adopt
  the upstream without being pulled back to alpha1, and alphas carry no upgrade
  path between them.
- Reinstall note (D23): a site with maper **enabled** on alpha1 follows
  maper's alpha2 release notes when its sync brings this in. A site that only
  carries the code (maper not enabled) needs nothing.
- The lock re-resolution also took Drupal core 11.4.10 → 11.4.11, within the
  `~11.4.10` floor. Production audit clean.

## 2026-10-21 — one cache protocol for every site: edge_purge becomes upstream (PROTOCOL D26)

- `web/modules/custom/edge_purge` → `web/modules/upstream/edge_purge`, now
  upstream-owned, with the current module: `X-Edge-Hash` on every tagged
  response, `Cloudflare-CDN-Cache-Control` from `edge_purge_cdn_max_age`,
  `drush edge-purge:release` (purges only when render code changed, and then
  only the pages whose HTML changed), `drush edge-purge:rebuild` (core's full
  flush with the page, dynamic page and render bins kept unless render code
  changed), `RouterRebuildHold` and the never-queued tags. Kernel and unit
  tests.
- `RenderFingerprint` counts `modules/upstream`; a file moved unchanged
  between roots is not a change.
- `deploy/settings.prod.php`: `EDGE_PURGE_CDN_MAX_AGE` (default 30 days),
  `CACHE_MAX_ROWS_PAGE` / `_DYNAMIC_PAGE` / `_RENDER`, 127.0.0.1 always a
  trusted proxy. `deploy/.env.example` lists them.
- `Dockerfile` platform block: `zend.assertions=-1`.
- `scripts/edge-purge-drain.sh`: drains by tag only, up to 5 requests a run;
  never a hostname purge.
- `docs/DEPLOY.md` §7: the edge and origin checklist. BATTLE_SCARS §44–§46,
  §40 amended by §45.

## 2026-10-20 — one container cache key per image; Apache compresses JSON; BATTLE_SCARS §43

- `Dockerfile` (build stage): a hash of every file under `/opt/drupal` is
  written to `/opt/drupal/deployment-identifier`;
  `deploy/settings.prod.php` sets `$settings['deployment_identifier']` from
  it. A new image compiles its own service container on its first request
  instead of running the previous image's until the deploy rebuilds caches.
- `Dockerfile` (platform tuning block, copy verbatim into every property):
  `conf-enabled/zz-platform-deflate.conf` adds `application/json` to
  mod_deflate. The base image's list has no JSON type.

## 2026-10-20 — a visits report in the admin: ga4_report (PROTOCOL D24)

- `web/modules/upstream/ga4_report`: the visits report (`/admin/reports/ga4`)
  from three sources: Google Analytics 4, Cloudflare (edge cache per minute and
  per day) and AdSense (earnings per day); `drush ga4-report:google-consent`
  mints the AdSense credential. The GA4 report page,
  settings (`/admin/config/services/ga4-report`), `drush ga4-report:refresh`
  and `ga4-report:status`, cron on an interval. Snapshot in key-value; every
  chart also a table. Unit, Kernel and Functional tests.
- `web/modules/upstream/` is upstream-owned (`upstream/OWNERSHIP`); phpcs,
  phpstan, `phpunit.xml.dist` and `scripts/ci/tests.sh` lint and test it.
- `upstream-configuration/composer.json`: `google/auth` `^1.55`.
- Root `composer.json` (starter): `npm-asset/echarts` `6.1.0`.
- `deploy/settings.prod.php`: `GA4_REPORT_CREDENTIALS`,
  `GA4_REPORT_CLOUDFLARE_TOKEN`, `GA4_REPORT_ADSENSE_CREDENTIALS` (paths of
  credential files), `GA4_PROPERTY_ID`, `GA4_REPORT_CLOUDFLARE_ZONE`, and
  `ADSENSE_PUBLISHER_ID` naming the AdSense account; `.env.example` documents
  them.
  `deploy/docker-compose.yaml` mounts `./secrets` read-only at
  `/opt/drupal/secrets`; `/secrets/` is in `.gitignore` and `.dockerignore`.

## 2026-10-19 — one map for the fleet: maper (PROTOCOL D23); deploy rehearsal; BATTLE_SCARS §41–§42

- `upstream-configuration/composer.json`: `drupal/maper` `1.0.0-alpha1`, exact
  (alphas reinstall between versions). It is installed everywhere and enabled
  per site.
- `recipes/maper-map`: maper + maper_openfreemap, cached live fetch on,
  `view maper maps` for anonymous and authenticated. Apply with
  `drush recipe ../recipes/maper-map`.
- `deploy/settings.prod.php`: maper's contact is `MAPER_CONTACT`, else
  `+https://$APEX`; `.env.example` documents it.
- Root `composer.json` (starter): the asset-packagist repository,
  `oomphinc/composer-installers-extender` with `installer-types`, and
  `npm-asset/vue` `3.5.43`, so Vue installs to `web/libraries/vue`. A site
  adopting the upstream adds these to its own root.
- DDEV: `scripts/dev/private-path.sh` (post-start hook) gives the local site
  `<repo>/private` as its private files path, as production has
  `/opt/drupal/private`. `/private/` is in `.gitignore` and `.dockerignore`.
- The lock was re-resolved the way an upstream sync does it
  (`update ophiocus/upstream-configuration -W`). That also refreshed drush to
  13.8.0, guzzle to 7.16.0 and their dependencies. Production audit is clean.
- `docs/DEPLOY.md` §4: rehearse on production's database before a push that
  changes modules.
- BATTLE_SCARS §41: a module whose code leaves composer cannot be uninstalled
  by that deploy; remove it in two deploys, never with a hook. §42: locale rewrites imported
  config's langcode on a non-English site; export what the import produced.

## 2026-10-18 — the skeleton is now an upstream (docs/UPSTREAM.md, PROTOCOL D22)

Sites built on this skeleton keep merging it, so an advisory resolved here once
reaches all of them.

- `upstream-configuration/composer.json`: a metapackage with core (floor
  `~11.4.10`) and the shared contrib. The root `composer.json` requires it with
  one line through a path repository; a site's own packages sit beside it.
- `upstream/OWNERSHIP`: the exact list of paths the upstream owns. Everything
  else belongs to the site.
- `scripts/upstream/sync.sh`: takes upstream-owned paths exactly, never touches
  the site's, re-resolves the lock; `--adopt` for a copied site, `--check` for
  a stale lock.
- `.github/workflows/test-gate.yml`: the test job, now reusable.
  `build-and-push.yml` calls it, and so does the new `upstream-sync.yml`
  (weekly: sync on a branch, audit, test gate, pull request).
- `build-and-push.yml` warns when `DEPLOY_HOST` is unset on master instead of
  building green and deploying nothing.
- `deploy/settings.prod.php` includes `deploy/settings.site.php` last: the
  site's own settings, which no sync touches.

## 2026-10-10 — integrity gate: conflict markers and config YAML (BATTLE_SCARS §37)

- `scripts/ci/tests.sh` gains a first gate, `integrity`: it fails on a
  merge-conflict marker in any tracked file and on any YAML in `config/sync`
  or custom code that does not parse (`scripts/ci/yaml_lint.php`). The other
  suites install from `config/install`, so a broken `config/sync` passed them.

## 2026-10-10 — tests gate every deploy (BATTLE_SCARS §36)

The workflow built and deployed every push without running a test. Now a
`test` job runs first and `build` needs it:

- `scripts/ci/tests.sh` runs phpcs, phpstan, the Unit, Kernel and Functional
  suites of all custom code (modules, submodules, themes; found by directory)
  and `npm test` in custom packages that define one. Every gate runs, and the
  run fails if any did. Locally: `ddev exec bash scripts/ci/tests.sh [gate…]`.
- In CI: PHP 8.3 (setup-php), MariaDB 11.4 as a service, Node 22, PHP's built-in
  server for functional tests. Keep those in step with the image and
  `.ddev/config.yaml`.
- `phpunit.xml.dist`'s `unit` suite now reaches submodules' tests too.

## 2026-10-12 — Behind a CDN: origin certificates, two-proxy client IP, purge traps (BATTLE_SCARS §38–§40)

The edge work was half in the skeleton: the cache settings were, the rest of
the topology was not, and the template still assumed Let's Encrypt.

- **`deploy/docker-compose.yaml`** — the router asks only for `tls=true`; no
  `tls.certresolver` label. Behind a CDN, Traefik serves the CDN's origin
  certificate from its file provider (§38). `TRAEFIK_CERTRESOLVER` removed from
  `.env.example`; a directly exposed host adds the label back (comment shows it).
- **`deploy/settings.prod.php`** — `DRUPAL_REVERSE_PROXY_ADDRESS` takes a
  comma-separated list of IPs/CIDRs; behind a tunnel set the edge network's
  CIDR, or every visitor logs as the tunnel connector (§39). Documented in
  `.env.example`. Max-age comment points at `edge_purge` (86400 once purging).
- **`docs/DEPLOY.md`** — §3 TLS options (origin certificate vs ACME),
  `deploy-dev` hosts are `<slug>--dev.<apex>`, `deploy-prod` purges and warms
  behind a CDN; new **§7 Behind a CDN**: DNS, origin TLS, client IP, cache rule,
  max-age, purge on save and on deploy, sessions, token handling, verification.
- **`scripts/edge-purge-drain.sh`** (new) — the reference host drainer for
  `edge_purge`: claim ≤100 tags, purge by tag, ack; a backlog over 500 becomes
  one hostname purge. Token read from a mode-600 file, passed to curl on stdin,
  `-4` by default (§40).
- **BATTLE_SCARS** §25 scoped to ACME-issuing proxies; **§38** closing the
  origin strands HTTP-01 renewals; **§39** the visitor is two proxies away;
  **§40** a URL purge that purges nothing, and an IPv4 filter IPv6 walks around.

## 2026-10-07 — `edge_purge`: purge the CDN by cache tag

With a page max age of an hour, an editor's save could stay invisible behind
the CDN for an hour. `web/modules/custom/edge_purge` closes that gap so the
max age can go to a day:

- every cacheable response carries a `Cache-Tag` header naming its Drupal
  cache tags, each hashed to 12 characters (Cloudflare's header cap is 16 KB;
  a page carries hundreds of tags);
- every invalidated tag is queued in `edge_purge_queue`, inside the save's
  own transaction;
- the host drains the queue (`drush edge-purge:claim` → purge-by-tag API →
  `drush edge-purge:ack --through=<seq>`). Drupal never holds the CDN token,
  and the host batches to the CDN's rate limit (Cloudflare Free: 5 purge
  requests a minute, 100 tags each).

Enable it through `core.extension.yml` so `drush deploy` installs it, run the
host drainer, then raise `cache.page.max_age`. The contrib `cloudflare` module
was passed over: beta, minimally maintained, needs the Purge framework, and
keeps the token inside Drupal. See the module's README.

## 2026-10-07 — edge cache + one-level names (BATTLE_SCARS §32, §33)

A property behind a CDN served every page from the origin: the CDN does not
cache HTML by default, and Drupal's page max-age of 0 tells it not to anyway.

- **`deploy/settings.prod.php`** — `cache.page.max_age` from
  `DRUPAL_PAGE_MAX_AGE` (default **3600**), so a new site sends
  `Cache-Control: public, max-age=3600` to anonymous visitors from its first
  deploy. 0 disables edge caching. The CDN side (one cache rule that bypasses
  Drupal session cookies and admin/cart paths, tiered caching, browser TTL
  from origin) is described in §32.
- **Trusted hosts** — branch environments are named `<slug>--dev.<apex>`,
  one label under the apex, because a free wildcard edge certificate covers one
  level only (§33). Comment and `.env.example` show the pattern.
- **`deploy/.env.example`** — `DRUPAL_PAGE_MAX_AGE` added.
- **BATTLE_SCARS §34** (CLI-rendered URLs need `drush --uri`, or sitemaps say
  `http://default`) and **§35** (an anonymous session, e.g. from a module writing
  the private tempstore on render, bypasses every cache layer).

## 2026-08-17 — outbound mail + contact form (PROTOCOL D18)

The runtime image has no MTA, so every property minted so far could not send a
single email — and a Drupal contact form says "Your message has been sent"
regardless, so the hole is invisible. Mail is now part of the foundation.

- **`drupal/symfony_mailer_lite`** (+ `mailsystem`) in `composer.json`. Chosen
  over `drupal/smtp`, which keeps the SMTP password in `smtp.settings` — i.e.
  in `config/sync`. A DSN is one string and stays out of git entirely.
- **`scripts/setup_mail.php`** (new, idempotent, the fleet's `setup_*.php`
  convention): enables `contact` + `symfony_mailer_lite`, creates a `dsn`
  transport `env` with an **empty** dsn, **points `mailsystem.settings`
  defaults at `symfony_mailer_lite`**, creates the site-wide contact form,
  grants anonymous access to it, and adds the `/contact` menu link (skipped
  gracefully where `menu_link_content` is absent, e.g. the `minimal` profile).
- **`deploy/settings.prod.php`** — runtime `MAILER_DSN`, `SITE_MAIL` and
  `CONTACT_RECIPIENT` overrides. Unset `MAILER_DSN` leaves the native
  transport so mail fails *loudly*; the `null` transport is deliberately not
  the default.
- **`deploy/.env.example`** — the three keys, with relay examples.
- **docs** — `DEPLOY.md` §6 (incl. the two-sided verification recipe),
  PROTOCOL D18, BATTLE_SCARS §22.

Verified end-to-end in DDEV: anonymous submission of the real form delivered to
Mailpit with zero errors; the same submission against a dead relay produced a
visitor-facing error, `Connection refused` in dblog, and **no** phantom
delivery. That second half is what caught the module being installed but not
actually routing mail (§22).

## 2026-08-17 — production deploy scaffold, by construction

Until now the skeleton stopped at "a module/theme in DDEV"; how a site
reached production was copied property-to-property and drifted (four
properties, four Dockerfiles, three workflow variants, two of them without
a health gate). The skeleton now carries the whole path and is opinionated
about it (PROTOCOL D17). Distilled from the properties that already run it;
verified end-to-end locally — image build, `deploy/docker-compose.yaml` up
against it, `drush site:install` through the stack, a real 200 page,
wrong-Host → 400, `config:export` into `config/sync`.

- **`Dockerfile` + `.dockerignore`** — multi-stage: composer `--no-dev`
  from the committed lock in a build stage, clean runtime stage,
  docroot `/opt/drupal/web`, drush on `PATH`, `deploy/settings.prod.php`
  installed as `settings.php`, `PHP_MEMORY_LIMIT` build-arg (512M
  default). Carries every Dockerfile lesson from the fleet as comments
  (`--classmap-authoritative` is incompatible with Drupal; bcmath and
  mariadb-client are absent from the base image but present in DDEV;
  128M is below the `drush recipe` floor) plus one new: **git + unzip in
  the build stage** — see BATTLE_SCARS §21.
- **`deploy/docker-compose.yaml`** — the host stack (drupal + MariaDB
  11.4, Traefik labels, external edge network, bind-mounted
  `data/{db,drupal-files,drupal-private}`), 100 % interpolated from
  `.env`, identical across properties. Adds a private-files volume the
  fleet was missing.
- **`deploy/.env.example`** — every key, grouped by who reads it.
- **`deploy/settings.prod.php`** — env-driven DB, hash salt, trusted
  hosts, private path; reverse-proxy headers; `config_sync_directory
  = ../config/sync`; unusable fallback host so misconfiguration fails
  loudly. Project settings go below a marker, env-driven the same way.
- **`deploy/entrypoint.sh`** — re-asserts `www-data` ownership of the
  bind-mounted `files/` and `private/` on every start (Docker creates
  missing host dirs as root on first boot — BATTLE_SCARS §21). No manual
  `chown 33:33` on first deploy any more.
- **`.github/workflows/build-and-push.yml`** — audit gate → buildx →
  GHCR → `deploy-dev` (dev/**) / `deploy-prod` (master) over SSH →
  post-deploy HTTP health gate (status + body size + no fatal markers,
  BATTLE_SCARS §19). Every `uses:` on a Node-24 major. Per-property values
  come from repo Actions settings (`PROD_URL`, `DEPLOY_HOST`,
  `VPS_DEPLOY_KEY`); deploy/health steps self-skip until they exist, so a
  fresh clone builds and pushes its image from day one.
- **`config/sync/.gitkeep`** — the deploy source of truth has a home.
- **DDEV `webserver_type` nginx-fpm → apache-fpm** — parity with the
  production image from day one (D17 supersedes D3).
- **docs** — new `docs/DEPLOY.md` (repo ↔ CI ↔ host contract, what each
  gate proves, first deploy, rollback, local parity); README section;
  PROTOCOL D17; BATTLE_SCARS §21.

## 2026-08-17 — full-health baseline

The weekly audit turned red on a runtime advisory (see BATTLE_SCARS §20);
this entry is the sweep that followed. A clone at this date passes all
four health checks — `composer audit --locked` (incl. dev), `composer lint`
with zero errors *and* zero warnings, PHPUnit with zero deprecations, and a
supported Node LTS in the container.

- **Lock fully refreshed within existing constraints** — 36 packages.
  Notables: `drupal/core` 11.4.4 → **11.4.5**, `guzzlehttp/guzzle` 7.15.1 →
  **7.15.3** (clears CVE-2026-69246 / CVE-2026-69245),
  `squizlabs/php_codesniffer` 3.13.5 → **3.13.6** (clears CVE-2026-67434,
  dev-only), phpstan 2.2.8, phpstan-drupal 2.1.2, phpunit 11.5.56, symfony
  7.4.16 line.
- **CI on the Node 24 runtime** — `actions/checkout@v4` → `@v7`
  (`composer-audit.yml`). GitHub deprecated Node 20 for actions; v4 warns
  on every run and will hard-fail when Node 20 is removed.
- **DDEV `nodejs_version` 20 → 22** — Node 20 reached EOL 2026-04-30.
- **PHPUnit 11 idioms** — `#[CoversClass]` attribute instead of the
  deprecated `@coversDefaultClass` doc-comment; `phpunit.xml.dist` schema
  10.5 → 11.5.
- **phpstan.neon** — dropped the deprecated `drupal.drupal_root` parameter
  (phpstan-drupal ≥ 2.1 auto-discovers it and warned on every run).
- **Template code sniffs clean** — `HealthController` injects
  `datetime.time` via `create()` instead of `\Drupal::time()`; test methods
  and the drush helper carry proper doc comments; the open health route
  documents *why* it is public. Zero phpcs errors, zero warnings.
- **PROTOCOL** — D1 refreshed to Drupal 11.4 / PHP 8.3; new D16 (Node
  runtime line).

## 2026-05-22 — linting/sniffing as standard

Code quality is now built in, not bolted on — every project minted
from the skeleton lints from day one (distilled while prepping a module
for Drupal.org, where the GitLab CI runs exactly these checks).

- **`drupal/coder` (phpcs Drupal + DrupalPractice)** + **`mglaman/phpstan-drupal`**
  + **`phpstan/extension-installer`** added to `require-dev`. The
  phpcs + phpstan composer plugins were already allowed; now the tools
  that use them ship too.
- **`phpcs.xml.dist`** — Drupal + DrupalPractice over `web/modules/custom`
  + `web/themes/custom`; excludes vendor/contrib/core/node_modules and
  any `js/vendor` third-party bundles.
- **`phpstan.neon`** — level 1 (the Drupal.org default for new projects),
  `drupal_root: web`, scoped to custom code.
- **Composer scripts:** `composer cs` (sniff), `composer cbf` (auto-fix),
  `composer stan` (analyse), `composer lint` (cs + stan). Run them before
  every commit; they are the same gates Drupal.org enforces.

## 2026-05-10 — initial reduction

What earned permanent residence in the skeleton:

- **DDEV stack baseline.** PHP 8.3, MariaDB 11.4, nginx-fpm with
  apache-fpm fallback notes, mutagen, Composer 2, Node 20.
- **Composer baseline.** Drupal 11.3 + drush 13 + devel; PSR-4
  autoload skeleton; `minimum-stability: dev` + `prefer-stable: true`
  for projects that need bleeding-edge contrib (any AI/embedding
  work, paragraphs RC, etc.).
- **PHPUnit baseline.** Strict mode on; `unit` suite default;
  kernel/functional suites scaffolded but commented (uncomment as
  the project grows).
- **`example_module` template.** Routing (`/example/health`),
  services.yml with logger channel + DI'd service, install hook
  with config baseline, controller with the `(string) $renderer
  ->renderRoot()` cast, Drush 12+ command class with attribute
  discovery and the `line()` helper, unit smoke test.
- **`example_theme` template.** Olivero child by default, libraries
  declaration, `.theme` file, page.html.twig override pattern,
  CSS folder, dist target.
- **`optional/vite-bundle/`.** Drop-in Vite + TypeScript + Vitest
  for themes that want a modern JS pipeline. Not pulled in by
  default; theme without JS doesn't pay any of the cost.
- **`docs/PROTOCOL.md`.** DDEV-only working principle; decision-log
  scaffold (D1, D2, ...).
- **`docs/BATTLE_SCARS.md`.** The lessons that earned permanent
  residence: shell-quoting through Win→WSL→DDEV, ext-mongodb +
  Sury PHP repo, Drupal config dot-keys, RendererInterface return
  cast, DrushCommands writeln() collision, Atlas App Services
  sunset → RESTHeart sidecar, etc.
