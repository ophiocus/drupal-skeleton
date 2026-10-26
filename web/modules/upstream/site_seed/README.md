# Site Seed

One seeder for every site. A site keeps the content its code depends on as
seed data (`data/seeds/*.yml`); this module converges that data onto the
database, idempotently, on every deploy. It replaces the seeder each site used
to carry its own copy of (PROTOCOL D27).

## How a site uses it

1. Enable `site_seed` (in `config/sync`, `core.extension`).
2. Put the seed data in the site's custom module, `data/seeds/*.yml`.
3. Delete the site's own seed command. `property:seed`, the alias every deploy
   runs, belongs to this module (`drush site-seed:site`): it runs
   `data/seeds/*.yml` of every installed module that is not core or contrib, in
   path order. A site that defines `property:seed` itself while `site_seed` is
   enabled has two commands under one alias.
4. If the site's install hook seeds, it calls the same thing:

   ```php
   $result = \Drupal::service('site_seed.runner')->runSite();
   // $result['tally']: what changed, by kind. $result['unhandled']: sections
   // no handler took. $result['files']: the seed files run.
   ```

By hand: `drush property:seed --check` for the whole site, or
`drush site-seed:run <file> [--check]` for one file. `--check` compares and
counts as a real run and writes nothing.

Upstream modules never ship `data/seeds/`: seed data is the site's. A module
with a section of its own ships a handler for it instead (below);
`appointments` does this for `appointment_services`.

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

A section no handler takes is returned in `unhandled`, and `property:seed`
warns about it. A module adds a handler for its own section by tagging a
service `site_seed.handler` (see `Handler\SeedHandlerInterface`). A module that
must also work without `site_seed` registers that service from its service
provider only when `site_seed` is installed (see
`appointments\AppointmentsServiceProvider`).

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
