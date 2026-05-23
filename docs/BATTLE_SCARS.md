# Battle scars

Lessons that earned permanent residence. Each entry is one
paragraph: what bit, why, the fix, and the file/area where the
fix lives. If something costs more than 30 minutes to figure out,
it goes here.

Append at the bottom. Don't reorder; chronology is part of the
story. If a scar is later contradicted by new evidence, add a new
entry rather than editing the original (`§14 supersedes §3`).

---

## §1 — Shell quoting through Windows → WSL → DDEV

Three shells argue over double quotes and `$`-expansion. PowerShell
on the host re-interprets `$variable`. WSL's bash re-interprets
again. DDEV's container shell re-interprets a third time. Anything
non-trivial — multi-line, mixed-quote, dollar-sign-bearing —
mangles in transit. **Fix:** write the command to a file, transfer
it once, run it via `drush php:script <file>`, `bash -c < file`,
or `git commit -F <file>`. Don't fight three shells. This applies
especially to anything containing PHP heredocs, JSON, or regex.

## §2 — `minimum-stability: dev` is required for several contribs

The AI ecosystem (`drupal/ai`, `drupal/ai_provider_anthropic`,
`drupal/ai_provider_openai`), `drupal/paragraphs` recent versions,
and others ship long-running RCs or `dev` branches that are
production-stable in practice but composer-unstable in theory. A
plain `minimum-stability: stable` will refuse to install them.
**Fix:** in `composer.json`, set `minimum-stability: dev` and
**also** `prefer-stable: true`. The `prefer-stable` keeps stable
releases when they exist; the `dev` floor only kicks in when no
stable matches.

## §3 — Drupal config rejects dots in keys

Tried structuring a config schema with keys like
`item_types.world.global` (so the config tree had natural
dot-namespacing). Drupal's config validator refused — keys cannot
contain dots, period. **Fix:** restructure as a sequence of
objects with an explicit `id` field:

```yaml
item_types:
  - id: world.global
    label: 'Whole-world configuration'
  - id: sector.region
    label: 'Per-sector configuration'
```

The dot can live *in a value*, just not as a key.

## §4 — `RendererInterface::renderRoot()` returns Markup, not string

```php
return new Response($renderer->renderRoot($build));
```

This errors at runtime: Symfony's `Response` constructor wants
`?string`, and `Markup` is an object. **Fix:** cast.

```php
return new Response((string) $renderer->renderRoot($build));
```

The cast invokes `Markup::__toString()`, which returns the safe
HTML string the response wants.

## §5 — `ControllerBase` already has `$entityTypeManager`

Constructor-promoting a readonly `EntityTypeManagerInterface` field
in your custom controller class collides with the protected
`$entityTypeManager` from `ControllerBase`'s parent — fatal at
runtime. **Fix:** drop your DI'd version; use the inherited
accessor `$this->entityTypeManager()` or work with the Drupal
container service directly. If you genuinely need a different
instance, name it differently (`$entityTypes`, etc.).

## §6 — Drush 12+ command class shape

Drush 12 dropped class-based discovery via `drush.services.yml` in
favor of attribute-based discovery. New shape:

```php
namespace Drupal\my_module\Drush\Commands;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Attributes\Command;
use Drush\Commands\DrushCommands;

final class MyCommands extends DrushCommands {
  use StringTranslationTrait;

  #[Command(name: 'my-module:do-thing', aliases: ['mmdt'])]
  public function doThing(): void {
    $this->line('Did the thing.');
  }
}
```

Two scars in one: (a) the file lives at
`src/Drush/Commands/MyCommands.php`, NOT
`drush.services.yml` or `drush/Commands/`. (b) `DrushCommands`
already has a protected `writeln()` from its Symfony Console
parent — naming a private helper `writeln()` collides. Use a
different name (`line()` works as an alias).

## §7 — `ext-mongodb` install + Sury PHP repo GPG fight

PECL install of `mongodb/mongodb` requires `ext-mongodb`, which on
Debian/Ubuntu is built against the Sury PHP repo. As of mid-2025,
Sury's GPG key periodically expires, breaking `apt update`. **Fix
preferred:** if the project has a gateway option (e.g. RESTHeart,
a microservice), avoid the PHP MongoDB driver entirely and speak
HTTPS via Guzzle. **Fix if you really need ext-mongodb:** rotate
the Sury key (`curl -fsSL https://packages.sury.org/php/apt.gpg
| sudo tee /etc/apt/trusted.gpg.d/php.gpg > /dev/null`) and pin
the package source.

## §8 — Drupal theme `regions:` is flat, not nested

The natural-feeling YAML

```yaml
regions:
  hidden_seo:
    label: 'Hidden SEO outlet'
    description: 'Offscreen accessible content'
```

crashes the block module's region-rebuild pass. The schema is
flat: machine name → human label, one line each.

```yaml
regions:
  hidden_seo: 'Hidden SEO outlet'
  content: 'Content'
```

If you need a description, put it in the theme's README.

## §9 — `nginx-fpm` vs `apache-fpm` behind Traefik

Local dev with `nginx-fpm` is fine and faster. Production behind
Traefik (with per-service routing labels), nginx-served Drupal
containers regularly hit "service can't reach upstream" or
502/503 errors — Traefik's network attachment doesn't always
resolve cleanly. **Fix:** switch to `apache-fpm` for any property
that will run behind Traefik. Ideally start there for production-
bound projects so you avoid the migration later. The DDEV
`webserver_type` and the production compose stack must match.

## §10 — Atlas App Services sunset 2025-09-30

Anyone building with MongoDB Atlas after that date: App Services
Functions, Custom HTTPS Endpoints, Triggers — gone. The historical
"App Services Function as HTTPS bridge to Atlas" pattern is dead.
**Fix:** RESTHeart self-hosted as a sidecar speaking HTTPS to
your application, MongoDB driver to Atlas. Or, if you really
must, the official PHP driver (`mongodb/mongodb` + `ext-mongodb`)
direct to Atlas — but mind §7.

## §11 — RESTHeart's defaults need overriding

Two gotchas:

1. **`/mclient/connection-string` defaults to `host.docker.internal`**
   and ignores `MONGO_URI`. **Fix:** override the entire RHO env
   var with explicit path overrides:
   `/mclient/connection-string->"${MONGODB_ATLAS_URI}";/http-listener/host->"0.0.0.0"`.
2. **`/mongo/mongo-mounts` defaults map `/` to `/restheart`**
   (the system DB), so writes to `/yourdb/coll` go to the wrong
   place. **Fix:** add `/mongo/mongo-mounts->[{"what":"*","where":"/"}]`
   to RHO so DB names in the URL are honored.
3. **PUT is update-only** — first write returns 404. **Fix:**
   append `?wm=upsert` to URLs for create-or-replace semantics.

## §12 — Drupal doesn't expand `%env(NAME)%` by default

The Symfony env-var processor isn't enabled out of the box in
Drupal. `%env(MONGODB_URI)%` in `services.yml` resolves to the
literal string. **Fix:** read `getenv('MONGODB_URI')` directly in
your service constructor. The processor *can* be enabled via
`services_overrides`, but the constructor approach is one less
moving part.

## §13 — `web/sites/default/` is local state

After `drush si` runs, `web/sites/default/` accumulates
`settings.php`, `files/`, sometimes `services.yml`, sometimes
secrets. **Never commit it.** `.gitignore` rule:

```
/web/sites/*/files/
/web/sites/*/private/
/web/sites/*/settings.local.php
/web/sites/*/services.yml
/web/sites/default/
```

The skeleton ships `settings.php` only when explicitly desired via
config-sync, never via `drush si`.

## §14 — Composer's `drupal/mongodb` is dev-only

There is no stable release. If a project doesn't actually need it
(see §10's RESTHeart route), drop the dependency. If it does,
ensure `minimum-stability: dev` is in place (see §2).

## §15 — UNC paths from Windows host

When working on WSL projects from Windows-side tooling
(VS Code, Claude Code on Windows), file paths look like
`\\wsl.localhost\Ubuntu\home\<user>\…` and behave fine for most
purposes — but some tools (older PHP installers, some Node
binaries that resolve symlinks weirdly) trip on UNC. **Fix:**
do all heavy lifting from inside WSL (`wsl -- bash -lc '...'`
from PowerShell, or just open the WSL terminal). Reserve
Windows-side file ops for editing source files via the host's
editor — never run binaries from there.

## §16 — DDEV Mutagen + node_modules

Mutagen sync is fast for source code but expensive for
`node_modules/` (tens of thousands of small files). Symptoms:
DDEV start hangs on "Synchronizing." **Fix:** add `node_modules/`
to `.ddev/mutagen.yml` ignore patterns, or run `npm install`
inside the container with `ddev exec` so the install lands
volume-mounted and Mutagen doesn't see it.

<!-- Append new scars below this line. Date them. Mark
     supersession explicitly: "§17 supersedes §3." -->

## Composer: contrib needs the drupal.org repository (2026-05)

A fresh skeleton could `composer install` core but `composer require
drupal/<any-contrib>` failed with "Could not find package" — composer.json had
no `repositories` entry. Drupal *core* packages mirror to Packagist, but
**contrib lives only on packages.drupal.org**. Every real project needs contrib,
so the repo is now baked in:
`"repositories": { "drupal": { "type": "composer", "url": "https://packages.drupal.org/8" } }`.

## DDEV: use `type: drupal`, not `type: drupal11` (2026-05)

`type: drupal11` is rejected by DDEV older than the release that introduced it —
seen failing on DDEV v1.23.5 with "invalid app type: drupal11". The
version-agnostic `type: drupal` auto-detects the core version and is portable
across DDEV releases. Skeleton now ships `type: drupal`.

## Composer: PHPUnit tracks Drupal core-dev (2026-05)

`drupal/core-dev ^11.3` (>= 11.3.3) requires `phpunit/phpunit ^11.5`; pinning
`^10.5` makes `composer install` unsolvable. Bumped to `^11.5` (see PROTOCOL D13).
