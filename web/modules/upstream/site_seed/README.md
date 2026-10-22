# Site Seed

One seeder for every site. A site keeps the content its code depends on as
seed data (`data/seeds/*.yml`); this module converges that data onto the
database, idempotently, on every deploy. It replaces the seeder each site used
to carry its own copy of (PROTOCOL D27).

## How a site uses it

1. Enable `site_seed` (in `config/sync`, `core.extension`).
2. Keep the site's own `property:seed` command (the alias every deploy runs)
   and make it call the runner with the site's seed file:

   ```php
   $result = \Drupal::service('site_seed.runner')->run($module_path . '/data/seeds/catalogue.yml');
   // $result['tally']: what changed, by kind. $result['unhandled']: sections
   // the site handles itself (e.g. its own appointment services).
   ```

3. Delete the site's own copy of the seeding code.

By hand: `drush site-seed:run <file> [--check]`. `--check` runs the whole seed
inside a transaction that is rolled back, and prints what it would change.

## Sections

| Section | Matched by | Notes |
|---|---|---|
| `stores` | name, or a name under `formerly:` | the default store is born under the uuid `commerce_store.settings` names; address keys the row leaves out are kept |
| `taxonomy` | vocabulary + name, or `formerly:` | keyed by vocabulary id |
| `products` | the variations' SKUs, never the title | price, status, body, stores, category, image; variations titled (see below) |
| `menu` | menu + link URI | |
| `shipping_methods` | name | created once, never converged: fees and zones are the shop's |
| `aliases` | system path | |
| `translations` | .po strings | a string customised in the admin is never overwritten |

A section no handler takes is returned in `unhandled`; a site adds its own
handler by tagging a service `site_seed.handler` (see
`Handler\SeedHandlerInterface`).

**Variation titles.** A variation saved without a title breaks add-to-cart
(the cart message prints its label; a NULL label is a TypeError, a 500). Where
the variation type generates titles, Commerce owns the title and an empty one
is repaired; elsewhere the row's `title` (or the product's) is the title.

## Options

The seed file's `site_seed:` section names fields that differ from the shop
format: `category_field` (`field_categoria`), `category_vocabulary`
(`categoria`), `image_field` (`field_image`), `image_directory`
(`public://productos/seed`), `body_format` (`basic_html`).

## Rules it keeps

No update, post-update or deploy hooks (D25): the seed step runs on every
deploy, and a handler is idempotent. Only the fields a row names are written;
everything else on an entity is the editors'.
