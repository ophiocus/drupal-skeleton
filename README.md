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
├── .ddev/config.yaml                 ← PHP 8.3, MariaDB 11.4, nginx-fpm, mutagen, Composer 2, Node 20
├── composer.json                     ← Drupal 11.3 + drush + devel; min-stability dev + prefer-stable; PSR-4 autoload
├── phpunit.xml.dist                  ← unit suite, ready for `ddev exec ../vendor/bin/phpunit`
├── docs/
│   ├── PROTOCOL.md                   ← DDEV-only working principle + decision-log scaffold
│   └── BATTLE_SCARS.md               ← lessons that earn permanent residence
├── web/modules/custom/example_module/   ← contrib-grade module template
└── web/themes/custom/example_theme/     ← Olivero-child theme template
└── optional/vite-bundle/             ← drop-in for themes that want a modern JS pipeline
```

The module and the theme are independent — keep one, both, or
neither (delete the directory; nothing else references it).

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

