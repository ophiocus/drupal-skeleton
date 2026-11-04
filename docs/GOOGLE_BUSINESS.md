# Google and the local business

Every site on this upstream is a local business that sells or books: a shop
with a storefront, a salon, a clinic, a bakery. Google decides how it shows
such a business from four things: its **structured data**, its **crawlable
pages** (sitemap, URLs, descriptions), the **facts printed on the page**,
and the **Google Business Profile**, which belongs to the owner.

The first three are the same work for every site, so they live in the
upstream once. This page says what Google asks, what the upstream gives,
what each site supplies, and how a site graduates from a lab to being
found.

**Google's spec is the rule.** Every field Google lists for the business's
type is filled when the fact exists. A fact that does not exist is left
out, never guessed. No review or rating markup about the business itself:
Google allows it only on sites that review *other* businesses.

## 1. What Google asks

Sources, all first-party (Google Search Central and Business Profile help):

- [Local business](https://developers.google.com/search/docs/appearance/structured-data/local-business)
- [Organization](https://developers.google.com/search/docs/appearance/structured-data/organization)
- [Merchant listing](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing)
- [Business Profile guidelines](https://support.google.com/business/answer/3038177)
- [Local ranking](https://support.google.com/business/answer/7091)

### The business: `LocalBusiness`, on the page that describes it

| Property | Google | Notes |
|---|---|---|
| `@type` | the most specific subtype | `Bakery`, `PetStore`, `BeautySalon`, `GroceryStore`… A business that is two things lists both (`["PetStore", "VeterinaryCare"]`). `additionalType` is not supported. |
| `name` | required | The real-world name, as on the sign, with no taglines. |
| `address` | required | `PostalAddress`, as complete as possible: street, locality, region, postal code, country. |
| `geo` | recommended | Latitude and longitude, at least 5 decimals. |
| `openingHoursSpecification` | recommended | Days, `opens`/`closes` as `hh:mm:ss`; special hours with `validFrom`/`validThrough`; a closed day is `00:00:00`–`00:00:00`. |
| `telephone` | recommended | With the country code. |
| `url` | recommended | The location's own working URL. |
| `priceRange` | recommended | Under 100 characters. |
| `image` | recommended | Several, high resolution (at least 50,000 pixels), 16:9, 4:3 and 1:1. |
| `department` | recommended | One nested business per department, named "{business} {department}". |
| `menu`, `servesCuisine` | food establishments | |
| `review`, `aggregateRating` | **never for the business itself** | |

### The organization: `Organization`

Nothing is required. Google stresses `name`, `alternateName`, `legalName`,
`url`, `logo` (at least 112×112, legible on white), `sameAs` (profiles
elsewhere), `email`, `telephone`, `address`, `contactPoint` and `taxID`.
It is also where **one shop-wide return policy** goes
(`hasMerchantReturnPolicy`).

### Products: merchant listings

Only on a page where the shopper can buy, one product per page:

- **Product, required:** `name`, `image` (crawlable, at least 50,000
  pixels, ideally 16:9, 4:3 and 1:1), `offers`.
- **Offer, required:** `price` above zero and `priceCurrency`. An `Offer`,
  never an `AggregateOffer`.
- **Recommended:**
  - on the offer: `availability`, `itemCondition`, `url`,
    `hasMerchantReturnPolicy`, `shippingDetails`;
  - on the product: `description`, `sku`, `brand`, a GTIN, `category`.
- **Never invented:** a GTIN only where a barcode exists, a brand only
  where one is real.

### Pages

- A sitemap, named in `robots.txt` and submitted in Search Console.
- Readable URLs, one canonical per page.
- A description per page, and Open Graph for links shared elsewhere.
- **The same name, address and phone (NAP)** on the page, in the
  structured data, on the Business Profile and in every directory. Google
  reads the visible page against the markup.

### Ranking, outside the site

Local results rank by relevance, distance and prominence. The site cannot
buy any of it. What counts:

- a verified, complete Business Profile (category, hours, special hours,
  photos, services, attributes);
- reviews, and replies to them;
- NAP consistency;
- indexable pages describing what the business does.

## 2. What the upstream gives

| Piece | What it does |
|---|---|
| `web/modules/upstream/business_schema` | Front page: `Organization` + the business under its types, in one JSON-LD `@graph`. Product pages: `Product` + `Offer`s. The **Business details** block shows the same facts to people. One reader (`BusinessFacts`) feeds both, so the page and the markup cannot disagree. Its [README](../web/modules/upstream/business_schema/README.md) has every setting. |
| `recipes/google-business` | Installs business_schema, simple_sitemap, metatag (+ Open Graph), pathauto and token. Creates the sitemap. Gives global, front-page and category pages a description and Open Graph tags. |
| `recipes/google-business-shop` | `google-business`, plus, for a Commerce shop: `/producto/<title>` and `/<vocabulary>/<term>` URL patterns, product meta tags (description from the body, Open Graph title, description, image, `og:type` product, URL), and every product in the sitemap with its images. It stops on a site without Commerce. |
| `deploy/settings.prod.php` | The sitemap's base URL is `https://<APEX>`. The host runs `drush cron` without `--uri`, and without this every `<loc>` read `http://default`. |
| `deploy/entrypoint.sh` | At container start, `robots.txt` gets `Sitemap: https://<APEX>/sitemap.xml`, unless it already names a sitemap or `ROBOTS_SITEMAP=0`. It also gets `Allow: /core/*.woff2`, `*.woff` and `*.ttf` next to core's own image lines: core disallows `/core/` and allows its CSS, JS and images back, but not its fonts, so Google reported a core theme's fonts as blocked resources. |

**Why robots.txt is written at start.** `robots.txt` is core's scaffold file,
served by Apache before Drupal sees the request. So no module or config
can add to it, and the address it needs is the environment's own
(production, a dev environment, a lab). A static line from the site's
`assets/robots-additions.txt` would carry one address into every
environment. The container knows `APEX`, so it appends the line once per
start, and only when it is absent.

**Where the facts come from.** A site with Commerce already keeps its
address and map point on the default store (`address`, and shop_delivery's
`field_ubicacion`). business_schema reads them there, so there is one copy.
Name and URL come from the site, and the logo from the theme. The rest is
`business_schema.settings`. Commerce is never a dependency: the module reads
stores, products and variations through the entity API, so it installs
anywhere.

**What it does not do (yet).** No `shippingDetails` or `hasShippingService`:
a shop's delivery zones and fees are not yet a source. No `ProductGroup` for
variants: several variations are several offers on one product. Product
prices are the variations' list prices; Commerce's price resolvers
(promotions, customer prices) are not applied. No `hreflang` beyond what
simple_sitemap already prints for translations.

## 3. What each site supplies

Only the site, and the owner, know these. Each one goes in the site's own
`config/sync` (or its store seed), never in the upstream:

1. **Its types**: the most specific schema.org subtype(s) for what the
   business *is*.
2. **Its facts** in `business_schema.settings`:
   - the telephone, the public e-mail address;
   - the hours and special hours (holidays);
   - the price range, the profiles elsewhere (`same_as`), photos of the
     place;
   - departments, cuisine and menu where they apply;
   - the return policy (a perishable-goods shop usually says
     `MerchantReturnNotPermitted`, or a short window);
   - the condition of what it sells (`NewCondition`).
3. **Its address and point** on the default store, complete: street,
   locality, region, **postal code**, country. A placeholder ("PENDIENTE")
   is a placeholder Google will publish: fix the data, not the markup.
4. **Its catalogue**: real names, prices and photos. While products are
   placeholders, set `products.enabled: false`; a placeholder price sent to
   Google is a false price. A product without a photo gets no markup.
5. **A description** that is better than the slogan, for the front page,
   in its `metatag.metatag_defaults.front`.
6. **The Business Profile**, which is the owner's: the same name, address,
   phone and hours as the site, the category, the photos, the services,
   and the site's URL (and a booking page as the booking link). Claiming
   and verifying it is the owner's act.

## 4. Turning it on in a site

On the site's local copy, after its upstream sync brought this:

```bash
ddev drush recipe ../recipes/google-business-shop   # a shop; else ../recipes/google-business
ddev drush cex -y
```

Then set the facts (the form at `/admin/config/search/business-schema`, or
`config/sync/business_schema.settings.yml` directly), place the **Business
details** block (footer or contact page), export again, run the test gate,
and commit. The recipe is applied once. It never runs in a deploy, and
later changes go through `config/sync` (PROTOCOL D33).

Things to know before applying:

- **The recipe sets tags by name.** It writes the front page's and global
  `description` and Open Graph tags. A site that had already written its
  own front-page description reapplies it before exporting (or leaves that
  line out of the commit). Everything else it creates only where the site
  has nothing (`strict: false`). A shop that already chose a product URL
  pattern or product tags keeps them.
- **Two patterns for one bundle compete.** A shop whose own product pattern
  has another id than `producto` deletes one of the two.

After the deploy that brings it, once per site (aliases and sitemaps are
content, not config, so no import makes them):

```bash
drush pathauto:aliases-generate create canonical_entities:commerce_product
drush pathauto:aliases-generate create canonical_entities:taxonomy_term
drush simple-sitemap:generate
```

The old `/product/N` paths keep answering. Every page names its alias as
canonical, so nothing is duplicated.

## 5. Graduation: from a lab to being found

A lab is served under the platform's lab name with `X-Robots-Tag: noindex,
nofollow` from the host's lab override, on purpose: nothing a lab says
reaches Google. The day the business moves to its own domain:

1. **The host:** `APEX` is the new domain, and the lab override (noindex)
   is swapped for the standard one when the owner is ready to be public.
   The sitemap's base URL and the robots.txt line follow `APEX` by
   themselves.
2. **Check from outside:**

   ```bash
   curl -sI https://example.com/ | grep -i x-robots-tag     # nothing
   curl -s https://example.com/robots.txt | grep -i sitemap
   curl -s https://example.com/robots.txt | grep -c 'Allow: /core/\*\.woff'   # 2 (woff2, woff)
   curl -s https://example.com/sitemap.xml | grep -c '<loc>https://example.com'
   curl -s https://example.com/ | grep -o 'application/ld+json'
   ```

3. **Search Console:** verify the domain (a DNS TXT record), and submit
   `https://example.com/sitemap.xml`.
4. **Rich Results Test** (search.google.com/test/rich-results) on the front
   page and on one product page: no errors. Warnings name facts the site
   has not supplied yet.
5. **Business Profile:** its website is now the domain; its booking and
   menu links point at the site's own pages.

## 6. Verifying the markup

The JSON-LD is in the initial HTML, rendered on the server, never injected
by JavaScript. Read it from outside:

```bash
curl -s https://example.com/ \
  | sed -n 's/.*<script type="application\/ld+json">\(.*\)<\/script>.*/\1/p' \
  | python3 -m json.tool
```

Then compare it, field by field, with the **Business details** block on the
same page and with the Business Profile. All three say the same.
