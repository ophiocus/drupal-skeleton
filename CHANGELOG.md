# Changelog

All notable changes to this skeleton are recorded here. Format
loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Skeletons don't really do semantic versioning — date stamps tell
you whether the foundation you cloned is recent enough.

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
