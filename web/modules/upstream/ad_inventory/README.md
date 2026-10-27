# Ad inventory

Labelled banner slots for a Drupal site that keep the anonymous page cache:
dated bookings sold directly, network providers (AdSense first) and the site's
own house creatives, filled **in the browser, only after the reader grants
advertising consent**; first-party view and click counts with no reader data;
email slots served as one image and one click address per slot; `/ads.txt`
lines per provider. Submodule `ad_inventory_report` is a read-only dashboard
over all of it (see its README).

An upstream module (PROTOCOL D30, D32): every site carries it and enables it
only where it sells or carries advertising. Nothing in it names a site: which
slot sits where is the site's configuration.

## What it guarantees

| Rule | How |
|---|---|
| **Page-cache safe** | A page with slots is the same HTML for every anonymous reader. No cookie or session is read or started for a slot; no `max-age: 0`; cache contexts are only `user.permissions` (bypass advertising), `route.name` (admin and error pages get no network unit) and the interface language. Two anonymous requests answer `X-Drupal-Cache: HIT` with no `Set-Cookie` (functional test). |
| **Consent first** | Every slot's HTML carries the site's *blocked banner* (fixed text and one link that reopens the consent settings, no image). Creatives and network units wait in an inert `<template>`, whose images and scripts the browser never requests. Only when `window.<object>.<property> === true` does the script fill the box. Declined or unanswered: the blocked banner, everywhere. |
| **Reserved space** | Each slot has a desktop and a phone format. Its box is `width: 100%; max-width: <width>; aspect-ratio: <ratio>`, from a style block generated from the formats; the phone format applies under the breakpoint. Network units take a fixed size per viewport by media query. The blocked banner, a creative and a unit fill the same box, and a caption line is always reserved. |
| **Labelled** | Every slot prints one site-wide label above the box. |
| **Fail closed** | Network units print only where the environment turns them on; account ids come from the environment or `settings.php`, never config; `bypass advertising` removes network units for a role; none on admin routes, error pages or in maintenance mode. |
| **No update hooks** | Configuration ships in `config/install`; data arrives as config and seeds (PROTOCOL D25). |

## Install

1. `drupal/adstxt` is in the upstream baseline (`upstream-configuration/composer.json`),
   so every site already has it. Enable the module:
   `drush en ad_inventory` (it brings `adstxt`, `block`, `datetime`, `image`,
   `media`, `options`).
2. The site needs a media type whose source is an image (core's *Image*):
   renditions are media items.
3. Grant permissions (below). Give **Bypass advertising** to every staff role:
   staff then never see network units (self-clicks end network accounts) but
   still see booked and house creatives, to check them.
4. Settings: **Configuration › Web services › Ad inventory**
   (`/admin/config/services/ad-inventory`). Name the consent bridge first:
   until it is named, every slot shows the blocked banner forever.
5. Read **Status** (`/admin/config/services/ad-inventory/status`, or
   `drush ad-inventory:status`): every closed gate, by name.

Do not enable the contrib `adsense` module beside this one: both would place
AdSense code. This module's AdSense provider reads the same
`ADSENSE_PUBLISHER_ID`.

### Permissions

| Permission | For |
|---|---|
| Administer the ad inventory (restricted) | formats, slots, providers, settings, status |
| Administer pasted ad snippets (restricted) | providers whose code is pasted (it prints on public pages) |
| Manage ad creatives | creatives |
| Manage ad bookings | bookings, as drafts |
| Approve and stop ad bookings | putting a booking in force, taking it off |
| View ad booking reports | each booking's views and clicks, and its CSV |
| Bypass advertising | no network units for this role |
| Use per-page ad slot controls | the per-page controls field on a page's form |

## Configure

### Settings

| Setting | Default | What |
|---|---|---|
| Label | `Sponsored Links` | Printed above every slot. With Google AdSense it must be one of Google's: "Advertisements", "Sponsored Links", "Anuncios", "Enlaces patrocinados" (Status says so otherwise). |
| Breakpoint | 768 | From this viewport width the desktop format applies; 0 uses it everywhere. |
| Time zone | site default | Booking days and count days. |
| Consent bridge | `siteConsent`, `ads`, `site-consent` | `window.siteConsent.ads === true` grants; the `site-consent` event on `document` re-reads it. |
| Blocked banner | text, link text, link address | The link reopens the site's consent settings (an anchor its banner listens for, or a page). |
| Creative kinds | none | `id|Label` lines beside "plain" (e.g. `event|Promoted event`). |
| Email alternative text | the label | Fixed in email snippets. |

**The consent bridge contract.** The module owns no banner and no cookie. The
site's banner, when the reader chooses, sets the object and fires the event:

```js
window.siteConsent = { ads: true };            // or false
document.dispatchEvent(new Event('site-consent'));
```

Before that, nothing of this module requests anything.

### Formats

**Structure › Ad inventory › Ad formats.** Each format is a width, a height
and the network unit size per viewport width (`0 468x60`, `728 728x90`: from
728 px wide the unit is 728×90). Saving a format creates its two image styles,
`ad_inventory_<id>_1x` and `_2x` (scale and crop); deleting it removes them.
Defaults: `leaderboard` 728×90, `mobile_banner` 320×100, `rectangle` 300×250,
`skyscraper` 160×600, `half_page` 300×600, `email` 600×200 (no network unit).

### Slots

**Structure › Ad inventory › Ad slots.** A slot has a channel (`web` or
`email`), a desktop and a phone format (one for email), the alternative
formats per-page controls may pick, the fill sources it allows (`booking`,
`network`, `house`; email never takes a network), the creative kinds a booking
may carry, an optional group (a booking may name `group:<name>` for every slot
in it), *repeatable* (a template may print it several times on one page), and
the network unit of each provider.

Slots are config: export them to `config/sync`.

### Providers

**Structure › Ad inventory › Ad network providers.** An ordered list of
provider instances, each a plugin with its settings. Fill order on a page:
booking in force → providers by weight → house.

Network serving is **off** unless the environment turns it on:

```php
// deploy/settings.site.php, or AD_INVENTORY_NETWORK=1 in the host's .env.
$settings['ad_inventory_network'] = TRUE;
// Accounts, by provider instance id (AdSense also reads ADSENSE_PUBLISHER_ID).
$settings['ad_inventory_accounts']['adsense'] = 'pub-1234567890123456';
```

Saving an enabled provider is refused when it serves a format another enabled
provider forbids on the same site, or forbids one another serves (a pop-under
network beside AdSense).

Shipped plugins:

- **Google AdSense** (`adsense`). Unit fields: the ad unit id
  (`data-ad-slot`) and an optional custom channel (`data-ad-channel`). Prints
  Google's CSS-sized unit (`<ins class="adsbygoogle" style="display:inline-block" …>`),
  never auto-format, overlays or sticky. Declares Google's `ads.txt` line
  (`google.com, pub-…, DIRECT, f08c47fec0942fa0`), its two labels, a site
  verification `<meta name="google-adsense-account">` (same for every reader),
  and forbids pop-unders, push, vignettes, interstitials, exit and slide-in
  widgets beside it. A house creative may sit behind an unfilled unit.
- **Pasted snippet** (`snippet`, restricted). Any network: its unit code per
  slot, a loader URL, the formats it serves, whether a house creative may sit
  behind it, units per page, allowed labels, `ads.txt` lines, consent note.

**Known risk.** Google documents deferring `adsbygoogle.js` until the reader
has chosen, but not inserting units from site JavaScript with one `push()`
each. Bookings, house creatives, email slots and counts do not depend on it;
the AdSense plugin is proven only when a unit loaded this way is seen filled on
a live, approved account. No CSP domain list and no test mode are promised.

The AdSense tag writes first-party cookies on the site's domain (`__gads`,
`__gpi`, `__eoi`). The cache test covers the origin's response; confirm your
edge and page cache ignore these cookies, and name them in the privacy page.

## Placing slots

**As a block.** One block per web slot (*Ad slot: <label>*, category
Advertising). Themes ship their placements in their own `config/install`
(a block names its theme, and a module cannot depend on a theme).

**From a template**, e.g. between items of a list the theme owns:

```php
use Drupal\ad_inventory\Element\AdSlotElement;

// Cacheable: renders (and records its placement) only on a cache miss.
$build['ad_' . $i] = AdSlotElement::build('index_band', $node, $i);
```

or by hand: `['#type' => 'ad_slot', '#slot' => 'index_band', '#entity' => $node, '#delta' => $i, '#scopes' => ['city:12']]`.
A slot that is not repeatable prints only at delta 0.

**Per-page controls.** Add a field of type *Ad slot controls*
(`ad_inventory_overrides`) to the content type of the pages (or use the
`ad_inventory_overrides` form element in any admin form and store its value in
that field). On one page they turn a slot off, pick another allowed format, or
limit how many of a repeatable slot it shows. They are per page, never per
reader. Only roles with *Use per-page ad slot controls* see the field, so a
site decides whether only staff or also the page's holder may use it.

## Creatives and bookings

**Creatives** (*Content › Ad creatives*): one rendition per format (a media
image uploaded at twice the size: 1456×180 for a 728×90 leaderboard), a target
address, alternative text, a caption (one line under the image, reserved in
the layout), an advertiser (account or name), a kind, an optional reference
to the content it promotes, *active*, and *house* (fills any slot allowing
house creatives). A rendition's ratio must match its format within 1%. A phone
rendition is its own design, not the desktop image shrunk.

**Bookings** (*Content › Ad bookings*): a creative, slots (or a group), pages
(empty for all pages, or a page context value a site provides through
`hook_ad_inventory_scope_alter()`: never the reader), first and last day (site
time zone, whole days), exclusive, and a state: `draft`, `approved`,
`stopped`. Approve and stop are separate operations (*Approve and stop ad
bookings*).

Approval checks that every format the slots need, desktop and phone, has a
rendition of the right ratio, that the slots take bookings and the creative's
kind, and then asks the site (`AdCreativeValidateEvent`, below). A refused
booking goes back to draft with the reason; the reason shows in the list.

**The daily run** (cron, once a day; also `drush ad-inventory:day`):
invalidates the slots of every booking that started or ended since the last
run, and re-checks every running booking (a refusal then stops it). A booking
that starts tomorrow appears tomorrow without a deploy, on pages and on the
email image.

## How a page fills

The server prints, per slot: the label, the blocked banner in the reserved
box, an empty caption line, and in a `<template>` the approved bookings in
force today plus the next one (`data-start`, `data-end`, so the browser
switches at midnight without a new render), the network units, and the house
creatives. `js/ad-inventory.js`, after the grant, picks per slot: a booking in
force (an exclusive one first, else one at random), else the first provider
within its per-page limit, else a house creative. Creatives are `<picture>`
elements (phone source by media query, 1x/2x `srcset`, `loading="lazy"`,
`decoding="async"`, width and height) linked through `/ad/c/<creative>` with
`rel="sponsored noopener"`.

**No-fill.** A network unit that comes back unfilled (AdSense marks
`data-ad-status="unfilled"`) keeps its box; where the provider allows, the
house creative behind it shows. Nothing collapses while on screen.

**Viewability.** After the grant only: a slot counts as viewed once at least
half of it has been on screen for one continuous second
(`IntersectionObserver`). On `pagehide` one `sendBeacon` to `POST /ad/v`
carries `{slot, creative}` pairs (a network fill is `provider:<id>`) and the
unfilled units.

## Counting

`ad_inventory_count`: one row per day, slot and creative with views, clicks
and unfilled units. No IP, user agent, URL or identifier is stored; unknown
slot, creative or provider ids are rejected; no route starts a session.

- `POST /ad/v`: the beacon (204, `no-store`).
- `GET /ad/c/{creative}?slot=…`: counts a click, 302 to the target, `no-store`.
- Each booking's report: *Content › Ad bookings › Report* (views and clicks by
  day, CTR) and its CSV. Views are counted only for readers who allowed
  advertising: every figure is a floor.

## Email slots

For each `email` slot, two stable public addresses:

- `/ad/e/<slot>.png`: the creative in force on the day it is fetched (booking,
  else house; never a network: AdSense forbids ads in mail) through the
  format's 2x style; a transparent image of the format's size when nothing is.
  The same answer for every reader: cacheable, tagged with the slot's fill tag,
  so a booking change invalidates the page cache and, where `edge_purge` is
  enabled, purges the edge.
- `/ad/e/<slot>/go`: 302 to that creative's target, counted, `no-store`.

**Snippets** (*Ad slots › Email snippets*): one per mail provider plugin. The
*Generic HTML* plugin gives the label and a linked image with fixed width,
height and alternative text, pasteable into any template. The module sends no
mail. Opens are not counted here (image fetches reach the edge; mail clients
also prefetch).

The web server must hand `/ad/e/*.png` to Drupal instead of answering 404 for
a missing static file (Apache with Drupal's `.htaccess` does; check nginx
rules that serve `*.png` statically).

## ads.txt

`adstxt` serves `/ads.txt` from its admin page (*Configuration › System ›
Ads.txt*). This module adds, through `hook_adstxt()`, the lines every enabled
provider declares (only once its account id is known), so Google's line is
present whenever AdSense is configured. A file that exists without Google's
line makes AdSense reject every request; Status says so loudly.

## Status

`/admin/config/services/ad-inventory/status` and `drush ad-inventory:status`
(exits 1 while any gate is an error) name: the consent bridge, the label, the
network gate, each provider's account, label compliance and exclusions, missing
`ads.txt` lines, formats without their image styles, slots naming unknown
formats or never placed, and running bookings missing a rendition.

## Extend

- **Hooks** (`ad_inventory.api.php`): `hook_ad_inventory_scope_alter()` (page
  context values for scoped bookings), `hook_ad_inventory_report_access()`
  (show an advertiser their own bookings' reports),
  `hook_ad_inventory_provider_info_alter()`,
  `hook_ad_inventory_mail_provider_info_alter()`.
- **Validation event** `AdCreativeValidateEvent::NAME`: dispatched when an
  approved booking is saved (`phase` `save`) and each day (`daily`). A
  subscriber calls `refuse($reason)` (the booking cannot be approved) or
  `stop($reason)` (it leaves the pages at their next render). Example: a legal
  registration code that must match a public register.
- **Provider plugins**: `src/Plugin/AdProvider`, attribute
  `#[AdProvider(id, label, description, restricted)]`, extend
  `AdProviderPluginBase` (account, loader, unit markup, `ads.txt` lines,
  consent, labels, units per page, house behind, kinds served, kinds
  forbidden, verification tag, browser handler, reporting channel). Add the
  plugin's settings schema as `ad_inventory.provider_settings.<plugin id>`.
- **Browser handlers**: `Drupal.adInventory.handlers.<name> = (unit, entry) => {}`
  fills a network unit for providers whose `handler()` returns `<name>`.
- **Mail provider plugins**: `src/Plugin/AdMailProvider`, attribute
  `#[AdMailProvider(id, label, description)]`, `snippet(array $slot)`.
- **Templates**: `ad-slot.html.twig`, `ad-creative.html.twig`.

## Choices made where the spec left them open

- Counts live in one table, `ad_inventory_count` (views, clicks and unfilled
  units per day, slot and creative), rather than separate tables.
- A click from a web slot carries the slot as `?slot=`; clicks without it are
  counted under an empty slot.
- Booking states are `draft`, `approved`, `stopped`; a refusal at save sends a
  booking back to draft, a refusal at the daily run stops it. Both keep the
  reason on the booking.
- Several bookings in force in one slot: an exclusive one wins; otherwise the
  browser picks one at random for each page view. The email image (one answer
  for everyone) takes the exclusive one, then the earliest start.
- Slot groups are a property of the slot; a booking names `group:<name>`.
- Scoped bookings match an exact page context string (`city:12`); the site
  provides the page's values through `hook_ad_inventory_scope_alter()`.
- `ads.txt` lines are added automatically through `hook_adstxt()` instead of
  only listed; Status still lists any declared line the file lacks.
- The per-page controls are one JSON value per entity (field type
  `ad_inventory_overrides`), holding only what differs from each slot.
- Maintenance mode: slots render without network units and are not cached
  while it is on.
- The email image is served by Drupal (the 2x derivative's bytes), not a
  redirect to the derivative, so the edge caches one answer per slot.

## Troubleshooting

- *Every slot shows the blocked banner, even after accepting.* The consent
  object or property is not named, or the site's banner never sets
  `window.<object>.<property> = true`, or never fires the event. Check Status.
- *The box is there but empty after the grant.* Nothing can fill it today: no
  booking in force with a rendition for both its formats, no network unit (the
  gate is off, or the slot names no unit for an enabled provider), no house
  creative with both renditions. The report's Health tab lists such slots.
- *A booking stays in draft.* Its reason says why: a missing or off-ratio
  rendition, a kind the slot does not take, or a validation subscriber.
- *No network unit in the HTML.* `AD_INVENTORY_NETWORK` is not 1 here, the
  account id is missing, the viewer has *Bypass advertising*, or the page is an
  admin or error page.
- *`/ad/e/<slot>.png` answers 404.* The slot is not an email slot, or the web
  server answers static `.png` paths itself.
- *A layout shift on fill.* Check the format styles are in the page head
  (`<style data-ad-inventory="formats">`) and that the theme does not override
  `.ad-slot__box`.
- *A new slot is missing from the block list.* Clear caches once; saving a
  slot clears the block plugin cache.

## Tests

Unit (provider exclusions, labels, ratio, AdSense output, network gate,
beacon parsing, per-page controls, format CSS, `ads.txt` matching, days, PNG),
kernel (formats and styles, approval and renditions, kinds, groups, the
validation event, the daily run, tag invalidation, a pop-under refused beside
AdSense, access, slot markup, network gating, per-page controls, counts,
placements, status gates) and functional (page cache HIT with no cookie on a
page with three slots, network units and bypass, a per-page control on one
page only, clicks and the beacon, the email image and click, admin pages and
approval).

The browser side is not in the gate, which has no browser driver. It was run
once in headless Chrome (155) on a fixture built from a real functional-test
response (three slots: a booking, a network unit with a house creative behind
it, a house creative), at 1280×900 and at 390×844: before the grant no
creative image, network script or beacon was requested and every slot showed
the blocked banner; a refusal kept it; after the grant each slot filled
(booking; network over house, the unit coming back unfilled; house); one
beacon carried the three viewed pairs and the unfilled unit; CLS was 0 before
and after, on both. Repeat it after changing `js/ad-inventory.js` or the
templates.

## Rules it keeps

No update, post-update or deploy hooks (D25). Formats, settings and the counts
table come from `config/install` and `hook_schema()`.
