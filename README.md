# drupal-skeleton

A starting point for any Drupal 11 module or theme intended to live —
contrib-grade, test-covered, DDEV-housed from day one.

The skeleton holds the cross-cutting *foundation* that earns its
place on every new Drupal project: the DDEV stack, the composer
baseline, the autoload conventions, the docs/decision-log scaffold,
and a running inventory of battle-scars in `docs/BATTLE_SCARS.md`.
Project-shaped specifics (DB vendor, AI providers, JS pipeline,
domain schemas) are deliberately **not** here — those are decisions
the next project makes for itself.

## What's in the box

```
drupal-skeleton/
├── .ddev/config.yaml                 ← PHP 8.3, MariaDB 11.4, apache-fpm (= prod image), mutagen, Composer 2, Node 22
├── .github/workflows/
│   ├── composer-audit.yml            ← inherited security gate: fails CI on a vulnerable composer.lock (push/PR + weekly)
│   └── build-and-push.yml            ← the production pipeline: audit → image → GHCR → deploy → HTTP health gate
├── Dockerfile / .dockerignore        ← multi-stage production image (drupal:11-php8.3-apache, composer --no-dev, drush on PATH)
├── deploy/
│   ├── docker-compose.yaml           ← host-side stack (drupal + mariadb, Traefik labels), fully .env-interpolated
│   ├── .env.example                  ← every key the stack + image read; copy to .env on the host
│   ├── settings.prod.php             ← tracked production settings.php, 100% env-driven, baked into the image
│   └── entrypoint.sh                 ← fixes bind-mount ownership on start; no manual chown on first deploy
├── config/sync/                      ← exported config = deploy source of truth (`drush deploy` imports it)
├── composer.json                     ← Drupal 11.4 + drush + devel; min-stability dev + prefer-stable; PSR-4 autoload
├── phpunit.xml.dist                  ← unit suite, ready for `ddev exec ../vendor/bin/phpunit`
├── docs/
│   ├── PROTOCOL.md                   ← DDEV-only working principle + decision-log scaffold
│   ├── DEPLOY.md                     ← the repo ↔ CI ↔ host deploy contract
│   └── BATTLE_SCARS.md               ← lessons that earn permanent residence
├── web/modules/custom/example_module/   ← contrib-grade module template
└── web/themes/custom/example_theme/     ← Olivero-child theme template
└── optional/vite-bundle/             ← drop-in for themes that want a modern JS pipeline
```

The module and the theme are independent — keep one, both, or
neither (delete the directory; nothing else references it).

### Production deploy, by construction

The skeleton is opinionated about how a site reaches production: the
Dockerfile, the host compose stack, the env-driven `settings.php`, and the
build → push → deploy → health-gate workflow all ship here and are meant to
be used **unchanged** — per-property values live in the host `.env` and in
three GitHub Actions settings (`PROD_URL`, `DEPLOY_HOST`, `VPS_DEPLOY_KEY`).
Until those exist, every push still audits, builds and pushes the image, so
the Dockerfile is proven in CI from the first commit. The full contract is in
[docs/DEPLOY.md](docs/DEPLOY.md).

### Inherited security gate

`.github/workflows/composer-audit.yml` ships with the skeleton so every property
minted from it is covered **by construction**, not by remembering to bolt an audit
step onto each property's build workflow later. It runs `composer audit --no-dev
--locked` and:

- **fails the build** on push / PR when `composer.lock` carries a known advisory in
  a production dependency — a vulnerable lock cannot merge;
- **runs weekly on a schedule**, so an advisory published *after* code merged is
  surfaced (a failed scheduled run notifies the repo owner) without waiting for
  someone to touch composer by chance.

If a property also has a build-and-push workflow, keep an audit step there too as a
hard pre-deploy gate — this standalone workflow is the floor, not the ceiling.

### Health baseline

A fresh clone passes all four, inside DDEV, before its first commit — and so
should every project minted from it, before every push:

```bash
ddev exec composer audit --locked                       # incl. dev deps
ddev exec composer lint                                 # phpcs + phpstan: 0 errors, 0 warnings
ddev exec vendor/bin/phpunit --testsuite=unit --display-deprecations
ddev exec node -v                                       # a supported LTS line
```

When the weekly audit turns red, remember it is fleet-wide (BATTLE_SCARS §20):
bump the lock in every property that shares the ancestry, not only here.

## Quickstart

```bash
# 1. Clone or rsync this skeleton, rename (replace 'mythingname' with yours).
cp -r drupal-skeleton/ ~/tecnocratica/projects/mythingname/
cd ~/tecnocratica/projects/mythingname/

# 2. Edit composer.json's "name" field, .ddev/config.yaml's project name,
#    and rename web/modules/custom/example_module → my_module (or delete it),
#    rename web/themes/custom/example_theme → my_theme (or delete it).
#    Inside renamed module/theme, search-replace machine names everywhere:
#      example_module → my_module      (file names + .info.yml + namespaces)
#      example_theme  → my_theme       (likewise)
#      ExampleModule  → MyModule       (PHP class names + namespaces)
#      ExampleTheme   → MyTheme        (likewise)

# 3. Boot the testbed.
ddev start
ddev composer install
ddev drush si --account-name=admin --account-pass=admin -y
ddev drush en my_module my_theme -y      # whichever you kept
ddev launch                              # opens the site

# 4. Develop. Tests:
ddev exec ../vendor/bin/phpunit                                  # PHP unit
ddev exec npm test    # if you copied optional/vite-bundle into place
```

## Working principle: DDEV-only

> All code operations (composer, npm, phpunit, vitest, drush) run
> inside DDEV. No bare-host fallback path.

This is non-negotiable. PHP versions, Node versions, ext-mongodb,
GD library quirks, locale settings — every "works on my machine"
trap dissolves once the only machine that exists is the DDEV
container. See `docs/PROTOCOL.md` for the rule, `docs/BATTLE_SCARS.md`
for the bodies that taught it.

## How to read the docs

- **`docs/PROTOCOL.md`** — the rules of engagement and the running
  decision log. Every architectural choice gets a one-paragraph
  entry (D1, D2, ..., E1, E2, ...) with the question, the chosen
  answer, and one line of reasoning. Future-you needs the *why*,
  not just the *what*.
- **`docs/BATTLE_SCARS.md`** — short paragraphs of "we learned this
  the hard way." Add to it whenever something costs more than 30
  minutes to figure out and the next person shouldn't have to
  re-learn it.

## What this skeleton is *not*

- Not a `drush si` profile; you still install Drupal yourself.
- Not opinionated about your data layer. Postgres, MariaDB,
  MongoDB-via-gateway, plain REST — decide for your project and
  document the choice in PROTOCOL.md.
- Not a frontend stack picker. The `optional/vite-bundle/` is for
  themes that want modern JS (Vite + TypeScript + Vitest); plenty
  of themes don't need it.
- Not a substitute for the `drupal-module-spawn` skill at
  `~/.claude/skills/drupal-module-spawn/SKILL.md`, which scaffolds
  short-lived *prototyping testbeds*. This skeleton is for things
  intended to outlast their first sprint.

