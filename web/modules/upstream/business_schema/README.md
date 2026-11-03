# Business schema

Tells Google what the business is, from one set of facts:

- **Front page:** one `<script type="application/ld+json">` with an `@graph`
  of an `Organization` and, when the business has an address, the business
  itself under its schema.org types (`Bakery`, `["PetStore",
  "VeterinaryCare"]`…): address, point, phone, hours, special hours,
  departments, price range, cuisine, menu, areas served, the shop-wide
  return policy.
- **Product pages** (`entity.commerce_product.canonical`): a `Product` with
  its `Offer`s, read from the product and its active variations.
- **The "Business details" block:** name, address, phone (a `tel:` link),
  e-mail, hours and a map link, built from the same facts, so what people
  read and what Google reads cannot drift apart. Google wants them to match.

Never a review or an `aggregateRating`: Google allows them only on sites
that review other businesses. A business's stars live on its Business
Profile.

The fleet guide, what Google asks and what each site supplies:
[docs/GOOGLE_BUSINESS.md](../../../../docs/GOOGLE_BUSINESS.md).

## Install

With the recipe, on a local copy, then export:

```bash
drush recipe ../recipes/google-business        # any site
drush recipe ../recipes/google-business-shop   # a Commerce shop (includes the first)
drush cex -y
```

Commerce is optional and never a dependency: the store, products and
variations are read through the entity and field APIs only.

## Where each fact comes from

| Fact | Source |
|---|---|
| Name | `name`, else the site name (`system.site`) |
| URL | `url`, else the front page |
| Logo | `logo` (a path from the Drupal root, `public://…` or a URL), else the default theme's logo; a file that does not exist is left out |
| Address | the default Commerce store's `address` field, else `address` |
| Point (`geo`) | the default store's `field_ubicacion` (shop_delivery's maper point), else `geo` |
| Everything else | `business_schema.settings` |

The default store is the one `commerce_store.settings` names (Commerce's own
`loadDefault()`), or the only store when there is one.

## Configuration: the site's facts, in its config/sync

A site keeps its facts in `config/sync/business_schema.settings.yml`, like
any other configuration: no drush step, no seed. Edit them in the form at
**Configuration » Search and metadata » Business schema**
(`/admin/config/search/business-schema`, permission *administer business
schema*) on a local copy, export, commit. Or write the file directly:

```yaml
types:
  - PetStore
  - VeterinaryCare
name: ''                       # empty: the site name
alternate_name: ''
legal_name: ''
description: ''
url: ''                        # empty: the front page
logo: ''                       # empty: the theme's logo
images:
  - themes/custom/example_theme/images/storefront-16x9.jpg
telephone: '+57 301 000 0000'
email: hola@example.com
tax_id: ''
price_range: $$
same_as:
  - 'https://www.instagram.com/example/'
address:                       # only where no Commerce store holds it
  street_address: ''
  address_locality: ''
  address_region: ''
  postal_code: ''
  address_country: ''
geo:
  latitude: ''
  longitude: ''
opening_hours:
  - days: [Monday, Tuesday, Wednesday, Thursday, Friday]
    opens: '09:00'
    closes: '18:00'
  - days: [Saturday]
    opens: '09:00'
    closes: '13:00'
special_hours:
  - valid_from: '2026-12-25'
    valid_through: '2026-12-25'
    closed: true
    opens: ''
    closes: ''
serves_cuisine: []             # food establishments only
menu: ''                       # food establishments only: /tienda or a URL
area_served: []
departments:
  - types: [VeterinaryCare]
    name: 'Example Veterinaria'   # "{business} {department}"
    url: /veterinaria
    telephone: ''
contact_points:
  - contact_type: wholesale
    telephone: '+57 301 000 0001'
    email: ''
return_policy:
  category: MerchantReturnNotPermitted   # or MerchantReturnFiniteReturnWindow (+ days), MerchantReturnUnlimitedWindow
  days: 0
  country: CO
  method: ''                   # ReturnInStore, ReturnByMail, ReturnAtKiosk
  fees: ''                     # FreeReturn, ReturnFeesCustomerResponsibility, ReturnShippingFees
products:
  enabled: true                # false while the catalogue is placeholders
  item_condition: NewCondition
  default_availability: InStock
  availability_field: ''       # a variation field: boolean, stock number, or a schema.org name
  brand_field: field_brand     # a product field: text or reference
  category_field: ''           # a product field: text or reference
  gtin_field: ''               # a variation field
```

Rules the output follows:

- **Empty is absent.** Whatever is empty is left out of the markup, never
  filled with a guess.
- **LocalBusiness needs an address.** Without one (in the store or the
  settings), the front page describes an `Organization` only.
- Times are `HH:MM` (24 h) in config and `hh:mm:ss` in the markup. A day the
  business is closed is `opens` = `closes` = `00:00:00`, as Google asks.
- **Departments** share the business's address.
- **The return policy** needs its category and its country. A finite window
  also needs its days. It is printed on the `Organization`, and on every
  offer.

## Products

On a product's canonical page, when `products.enabled` is on and the product
is published:

| Product | From |
|---|---|
| `name` | the title |
| `description` | the body's summary, else its text, as plain text (5,000 characters at most) |
| `image` | every image field of the product and of its active variations |
| `brand`, `category` | the fields named in the settings (a reference gives the label) |
| `sku`, `gtin` | on the product when there is one offer, on each offer otherwise |

Each **active** variation is one `Offer`:

- `price` comes from the variation's `price` field (number and currency),
  with trailing zeros dropped; it must be above zero.
- `availability`: from `availability_field`, else `default_availability`.
- `itemCondition` and the `seller` (the business's name and URL).
- `url`: the product page, with `?v=<id>` when there are several variations.
- `hasMerchantReturnPolicy`.

A product without the fields Google requires (a name, an image and an offer
with a price and a currency) gets **no** markup, so Search Console reports no
broken item. A product without photos is not a merchant listing until it has
one.

The price read is the variation's list price; Commerce's price resolvers
(promotions, per-customer prices) are not applied.

## Cacheability

The front page carries `config:business_schema.settings`, `config:system.site`
and the logo's theme-settings tags. With Commerce it also carries the
default store's tags and `commerce_store_list`. A product page carries the
product's, its variations' and its images' tags. Every page varies by
`url.path.is_front` and `route` (both already part of how pages are cached),
and no other page gets this module's tags.

## The block

Place **Business details** (category *Business*) in a footer or on a contact
page. Its settings: show the hours, show the e-mail address, link to a map
(when the business has a point). The template is
`business-details.html.twig`; a theme overrides it as usual. Labels are
translatable.
