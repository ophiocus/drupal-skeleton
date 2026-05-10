# Changelog

All notable changes to this skeleton are recorded here. Format
loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Skeletons don't really do semantic versioning — date stamps tell
you whether the foundation you cloned is recent enough.

## 2026-05-10 — initial reduction

Distilled from `drupal-three-js-theme` between 2026-05-04 and
2026-05-10. What earned permanent residence:

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
