# Changelog

All notable changes to this skeleton are recorded here. Format
loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Skeletons don't really do semantic versioning — date stamps tell
you whether the foundation you cloned is recent enough.

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
