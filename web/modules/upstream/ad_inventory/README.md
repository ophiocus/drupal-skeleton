# Ad inventory

Banner spaces for a Drupal site whose public pages are cached: booked
banners, network units and house banners. Every reader gets the same HTML,
and nothing is shown or counted before the reader's advertising consent.

Spaces, formats and creatives are managed in the Drupal admin. Bookings are
sold by hand (the module takes no payments). The browser fills a space once
consent is granted. Views and clicks are counted without sessions, cookies
or any record of the reader.

- **Generic:** no site names inside. A site adds its own placements, scope
  keys and validation rules (see "Extending").
- **Page cache safe:** a space never varies by reader. The only variation is
  by role, for staff previewing drafts.
- **Nothing approved means nothing printed:** a space with no booking, no
  approved house banner and no approved provider leaves no markup, not even
  an empty box.

## Installing

1. Require the dependency: `composer require drupal/adstxt:^1.5`.
2. Enable `ad_inventory` (it needs block, media, image, file and adstxt).
   Enable `ad_inventory_snippet` too if you want the pasted-snippet
   provider.
3. The site needs a media type `image` (core's standard one). Every rendition
   of a creative is a media item of that type.

At install, the module creates:

- **Six formats:** leaderboard 728×90, mobile_banner 320×100, rectangle
  300×250, skyscraper 160×600, half_page 300×600 and email 600×200.
- **An image style per format** (`ad_inventory_<format>`), which scales the
  2x upload to its 1x width.
- **The settings.**

adstxt's own install copies its sample file into `/ads.txt`, made-up
exchanges included. `ad_inventory`'s install clears that sample (a site's own
text is kept), so `/ads.txt` answers **404** until an approved provider
declares seller lines.

## Configuring

| Where | What |
|---|---|
| `/admin/structure/ad-inventory` | Settings: the label above every space, the phone breakpoint (px), the consent bridge |
| `/admin/structure/ad-inventory/formats` | Formats (width × height in CSS px) |
| `/admin/structure/ad-inventory/spaces` | Spaces: channel (web or email), desktop and phone format, allowed alternative formats, fill sources (booking / provider / house), creative kinds accepted |
| `/admin/structure/ad-inventory/providers` | Providers: an ordered list of configured plugin instances, each draft or approved, each limited to some spaces or all |
| `/admin/content/ad-inventory/creatives` | Creatives: one upload per format, link, alt text, caption, house flag, state |
| `/admin/content/ad-inventory/bookings` | Bookings: creative, spaces, scope, first and last day (site time zone), exclusive, reference code, state |
| `/admin/content/ad-inventory/report` | Views and clicks per booking and house banner, per day; CSV at `report.csv` |

### Permissions

| Permission | For |
|---|---|
| `administer ad inventory` | formats, spaces, providers, settings (restricted) |
| `manage ad bookings` | creatives, bookings, reports, the mail snippet |
| `preview ad drafts` | see draft creatives, bookings and providers on public pages |
| `bypass advertising` | never receive network units (self-clicks are invalid traffic); booked and house banners still show |
| `use ad snippet provider` | paste network markup and scripts (restricted: arbitrary code on public pages) |

### Placing spaces

- **Block:** every web space is a block named after the space, under the
  Advertising category. Its `devices` setting (both / desktop only / phone
  only) lets one space have a desktop placement and a phone placement in
  different regions.
- **Inside a template (a column, a list):** the theme declares a region its
  page template does not print, and the template prints
  `['#type' => 'ad_inventory_region', '#region' => '<region>']` where the
  region goes. Spaces are placed there as blocks like anywhere else, so code
  names a region and never a space: every space comes from configuration.
- **Conditions:** "Route" (`ad_inventory_route`: route names) and "Page of
  a paged list" (`ad_inventory_pager_page`: first page or later pages, from
  `?page`), beside core's path condition. The pager
  condition is how page 1 and later pages become two different spaces
  that are sold apart.

## Formats and creatives

- Every space has a desktop format and a phone format. The phone one is used
  below the breakpoint (`<picture>` with a media query). Boxes keep the
  format's proportion with `aspect-ratio`, so nothing shifts when a creative
  arrives.
- A creative carries one **rendition per format** it may fill, uploaded at
  twice the format's size, at most 100 KB each. The proportion must match
  within 1%. The original serves the 2x source; the format's image style
  serves the 1x source.
- A booking or house banner fills a space only if it has a rendition for
  **both** of that space's formats (after any per-page override).
- Links go through `/ad/c/{creative}` (counted, then a 302 to the creative's
  link) with `rel="sponsored noopener"`. A link is an `https://` address or
  a path on the site; anything else answers 404.

## Who fills a space

In order, for each space on each page:

1. **A booking in force:** approved, its creative approved, today between
   its first and last day, the space in its list, and its scope matching the
   page. A scoped booking beats a booking for every page; then the earliest
   booked wins.
2. **A provider:** the first enabled, approved provider (in weight order)
   that covers the space and returns a unit for its format. Skipped for
   accounts with `bypass advertising`.
3. **House banners, at random:** every approved house creative allowed in
   the space (up to 8, oldest first) is printed, each inert in its own
   `<template>`. After consent the browser shows one at random and counts
   that one, so the page stays the same HTML for every reader and only the
   picked image is fetched.
4. **Nothing:** the space is not rendered.

Staff with `preview ad drafts` also get drafts at each step; a draft is
marked "draft" on the page. Per-page settings (see "Extending") can switch
a space off on one page or choose another allowed format.

## Consent

The page always prints the label and a notice: "Turned off by your cookie
choice" plus a link that reopens the site's cookie settings. The creative (or
the provider's unit) waits inert inside a `<template>`. `js/ad-inventory.js`
swaps it in when `window[<global>][<category>] === true`, then or after the
`<event>` DOM event. Without that answer, no image, script or count is
requested.

The settings name the site's own signal: `consent.global` (default
`adConsent`), `consent.category` (`ads`), `consent.event` (`ad:consent`) and
`consent.open_attribute` (`data-ad-consent-open`, the attribute that the
site's consent tool listens to on the reopen link).

## Counting

- **Views:** once the creative is in, a space counts as seen when at least
  half of it stays on screen for one second (IntersectionObserver). All seen
  spaces go in one `navigator.sendBeacon` POST to `/ad/v` when the page is
  hidden: `{"v": [[space, creative, source], …]}`, at most 24 entries,
  unknown ids dropped. The answer is 204 `no-store`.
- **Clicks:** `/ad/c/{creative}?s=<space>&f=<source>`.
- **Storage:** table `ad_inventory_count`, totals per day, space, creative and
  source (`booking`, `house`, `email` or `provider:<id>`). No address, browser
  or identifier is stored, no session is opened and no cookie is set.
- Figures are floors: readers who decline consent are never counted.

## Mail

An email space (channel `email`) has two public addresses that a mail
template keeps forever:

- `/ad/e/<space>/banner.png`: the creative in force today, or a 1×1 pixel.
  It is public, with `max-age=600`, and carries the space's cache tags, so
  an edge cache can hold it and a purge by tag renews it. (On a site whose
  `system.performance:cache.page.max_age` is 0, core marks it private.)
- `/ad/e/<space>/go`: counts a click and follows the creative in force.

The snippet to paste is at `/admin/structure/ad-inventory/spaces/<space>/email-snippet`.
Opens are not counted: mail clients prefetch images.

## Dates and cache

Bookings are kept by day in the site time zone. Cron
(`ad_inventory.boundaries`) runs once per day:

- It asks every running booking whether it may continue (the validate event
  below) and stops the ones refused.
- It invalidates `ad_inventory_day`, so bookings that start or end that day
  show or leave at the next render.

Every rendered space carries:

- the tags `ad_inventory_slot:<id>`, `ad_inventory_day`, `ad_booking_list`,
  `ad_creative_list`, `config:ad_provider_list` and
  `ad_override:<type>:<id>`;
- the context `user.permissions`.

Saving a booking, creative or space invalidates the tags of the spaces it
touches. `drush ev "\Drupal::service('ad_inventory.boundaries')->run(TRUE)"`
runs the day's turn on demand.

## Extending

| Need | How |
|---|---|
| Book by page topic (a city, a genre) | A service tagged `ad_inventory.scope_resolver` implementing `Scope\ScopeResolverInterface`: `keys($route_match, $cacheability)` returns the page's scope keys from the route (never from the reader) and adds what they depend on; `options()` returns the keys staff can book |
| Refuse or stop bookings by a site rule | Subscribe to `Event\AdBookingValidateEvent::NAME`. `$event->phase` is `save` (staff approving: call `refuse()`) or `daily` (cron: call `stop()`). `$event->booking` gives the booking and its creative (`kind`, `promoted_type`, `promoted_id`, `caption`, `reference`) |
| Per-page controls | `ad_inventory.overrides` (`OverrideStore::set($entity, $space, ['off' => TRUE, 'desktop' => <format>, 'phone' => <format>])`): stored in `ad_override` entities, so no field of the page changes |
| A network | A plugin with `#[AdProvider(id, label, permission)]` extending `Plugin\AdProviderBase`: `unit()` gives the markup per space and format, `loaders()` the script URLs (https only), `adsTxtLines()` the seller lines, `verificationTag()` a site-ownership meta tag, plus its configuration form. Overlay formats (pop-under, push, vignette, interstitial, exit, slide-in) are refused |

## Troubleshooting

| Symptom | Cause |
|---|---|
| A space never appears | Nothing approved fills it: the booking's or creative's state is draft, the creative lacks a rendition for one of the space's two formats, the booking's days don't include today (site time zone), or its scope key isn't on this page. Check as a user with `preview ad drafts` |
| No space appears anywhere, though the resolver finds a fill | The formats are gone: a config import deletes what the sync folder lacks, the module's installed formats and `image.style.ad_inventory_*` included. Export them (`drush cex`) before the first import on a site that manages config |
| The space shows "turned off" for everyone | The consent signal isn't reaching the script: check `consent.global` / `consent.category` against the site's consent tool, in the browser console |
| `/ads.txt` lists exchanges nobody signed with | adstxt was installed before `ad_inventory`, or its sample was imported in config. Empty `adstxt.settings:content` |
| The mail image is `private` | `system.performance:cache.page.max_age` is 0 |
| Counts stay at zero | The beacon fires only after consent and on page hide; `/ad/v` must not be blocked by the edge or a firewall rule |
