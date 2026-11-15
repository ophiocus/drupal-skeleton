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

1. **The bookings in force (the roster):** approved, at least one of its
   creatives approved and fitting the space, today between its first and last
   day, the space in its list, and its scope matching the page, within the
   pages it locked when it was approved (below). A scoped booking beats a
   booking for every page. At the winning rank an **exclusive** booking holds
   the space alone (the earliest booked, if ever two); otherwise **every**
   non-exclusive booking there shares the space (at most 12 printed; a site
   caps its sales lower). See "Rotation".
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
a space off on one page or choose another allowed format. Error pages (the
routes `system.401`, `system.403`, `system.404`, `system.4xx`) show no space
at all.

### Rotation

A booking holds a **set of creatives**, in the client's order (the field
`creatives`; the first is also `creative`). Two levels take turns, both by
the same rule: **the one with the fewest views this week goes next**, the
week being Monday to Sunday in the site's days.

- **Outer rotation:** which booking (placement) a shared space shows.
  Placements that start the same day end the week with even shares.
- **Inner rotation:** which of the chosen booking's creatives it shows.
- Ties go to the earliest booking and, inside a booking, to the client's
  order. An exclusive booking has no outer rotation; its creatives still
  take turns.

The page stays the same HTML for every reader (and cacheable): it prints
every placement × creative, each inert in its own `<template>`. When a space
has more than one, the browser, after consent, POSTs once per page to
`/ad/pick` (`{"p": [[space, [booking, …]], …]}`) and the site answers
`{"space": [booking, creative]}` from this week's counts. With no answer
within two seconds the browser picks at random. Views reach the counts when
readers leave pages, so readers in the same minute may get the same
placement; the shares even out over the day.

`AdBooking::creatives()`, `setCreatives()` and `whereCreative($query, $id)`
(finds bookings that use a creative, old ones included) are the API;
`ad_inventory.rotation` answers `pick()` and `countable()`.

On a site that installed the module before the field existed, the field
arrives with the config import (`field.storage.ad_booking.creatives`,
`field.field.ad_booking.ad_booking.creatives` in config/sync); no schema
update runs. Without it, every booking has its one creative.

### What a booking locks when it is approved

Approving an exclusive booking locks the pages of its scope that are still
free in its spaces and days, and it shows only there:

- **A wider booking first:** a booking for every page holds a space for a
  week; a city booking in that space and week can't be approved ("…already
  holds every page of this booking…"). The same scope twice is refused the
  same way.
- **A narrower booking first:** a city booking holds a space for a week; a
  booking for every page bought afterwards covers every page except that
  city's. The form lists what other bookings hold and asks staff to tick
  «Confirm the pages this booking covers» before it saves. The list shown is
  the list saved: if another booking is saved in between, the form shows the
  new list again.
- **Locked as bought:** the lock is kept as scope keys (in the key-value
  collection `ad_inventory.coverage`), so a page that appears later under
  the scope (a new show in a city) belongs to the booking too. Pages left
  out stay out even when their booking stops; they can be booked again.
- A booking approved before this, or by code, covers its whole scope as
  before. Non-exclusive bookings lock nothing. Deleting a booking frees its
  pages.

How two keys share pages is the site's to say (see "Extending"). Without an
answer, two different keys are read as sharing some pages.

## Consent

The page carries no text of the module's: every reader, and every crawler,
gets the same cached HTML with an empty space that keeps its size. The label,
the notice and the creative reach the page only through the browser, which
reads the reader's choice:

| The reader | The space shows |
|---|---|
| has not answered, or has no JavaScript | nothing |
| refused | the label and a notice: "Turned off by your cookie choice", with a link that reopens the site's cookie settings |
| granted | the label and the creative; only then do its image and any provider script load |

`js/ad-inventory.js` reads `window[<global>][<category>]` when the page loads and
again on the `<event>` DOM event: `true` is granted, `false` refused, anything
else unanswered.

The settings name the site's own signal: `consent.global` (default
`adConsent`), `consent.category` (`ads`), `consent.event` (`ad:consent`) and
`consent.open_attribute` (`data-ad-consent-open`, the attribute that the
site's consent tool listens to on the reopen link).

## Counting

- **Views:** once the creative is in, a space counts as seen when at least
  half of it stays on screen for one second (IntersectionObserver). All seen
  spaces go in one `navigator.sendBeacon` POST to `/ad/v` when the page is
  hidden: `{"v": [[space, creative, source], …]}`, at most 24 entries. The
  answer is 204 `no-store`.
- **Clicks:** `/ad/c/{creative}?s=<space>&f=<source>`.
- **Only real pairs count.** A view or click counts only when the space could
  have shown it: a booking's source must name a booking that is approved, in
  force (today, or yesterday for a page read across midnight), holds the
  space and has the creative in its set; a house banner must house in the
  space; a provider's unit (creative 0) must come from an enabled provider
  covering it. Requests whose user agent is empty or reads as a robot
  (crawlers, link previewers, HTTP libraries) never count. A site should
  also disallow `/ad/` in its robots.txt.
- **Storage:** table `ad_inventory_count`, totals per day, space, creative and
  source: `booking:<id>` (each booking its own, so two bookings sharing a
  creative read their own numbers), `house`, `email` or `provider:<id>`. Rows
  written before per-booking sources carry `booking`, the combined total. No
  address, browser or identifier is stored, no session is opened and no
  cookie is set.
- Figures are floors: readers who decline consent are never counted. They
  are not proofs either: nothing stops a determined script from posting
  plausible pairs, which is why no flood counter keeps readers' addresses.

## Mail

An email space (channel `email`) has two public addresses that a mail
template keeps forever:

- `/ad/e/<space>/banner.png`: the creative in force today, or a 1×1 pixel.
  It is public, with `max-age=600`, and carries the space's cache tags, so
  an edge cache can hold it and a purge by tag renews it. (On a site whose
  `system.performance:cache.page.max_age` is 0, core marks it private.)
- `/ad/e/<space>/go`: counts a click and follows the creative in force (the
  first placement's first creative: a mail cannot rotate). Robots and
  requests other than GET are followed but not counted; mail scanners that
  look like browsers still count, so mail clicks are floors too.

The snippet to paste is at `/admin/structure/ad-inventory/spaces/<space>/email-snippet`.
Opens are not counted: mail clients prefetch images.

## Dates and cache

Bookings are kept by day in the site time zone. Cron
(`ad_inventory.boundaries`) runs once per day:

- It asks every running booking whether it may continue (the validate event
  below) and stops the ones refused.
- It invalidates `ad_inventory_slot:<id>` for the spaces of each booking that
  began or left since its last run, so those bookings show or leave at the next
  render. A day when no booking starts or ends invalidates nothing, and a
  skipped cron is caught up on the next run.

Every rendered space carries:

- the tags `ad_inventory_slot:<id>`, `config:ad_provider_list`,
  `ad_override:<type>:<id>`, the tags of the bookings and creatives it shows,
  and `ad_inventory_house` when house banners could fill it;
- the contexts `user.permissions` and `route.name`.

There is no tag shared by every space: saving a booking invalidates the
spaces it holds (before and after the save), a creative the spaces of the
bookings that use it, a house banner `ad_inventory_house`, and a space its
own tag. `drush ev "\Drupal::service('ad_inventory.boundaries')->run(TRUE)"`
runs the day's turn on demand and renews the spaces of every booking that is
not stopped, whatever its dates.

## Extending

| Need | How |
|---|---|
| Book by page topic (a city, a genre) | A service tagged `ad_inventory.scope_resolver` implementing `Scope\ScopeResolverInterface`: `keys($route_match, $cacheability)` returns the page's scope keys from the route (never from the reader) and adds what they depend on; `options()` returns the keys staff can book |
| Say how scope keys share pages | The scope resolver also implements `Scope\ScopeRelationInterface`: `relation($a, $b)` returns `SAME`, `CONTAINS`, `WITHIN`, `OVERLAPS`, `DISJOINT` or NULL (don't know). `''` (every page) contains every key without asking. It decides what a booking locks when approved |
| Show pages, volume or price before approval | A service tagged `ad_inventory.coverage_summary` implementing `Coverage\CoverageSummaryInterface`: `summary($booking, $coverage)` returns lines. With any line, staff confirm every approval, whole coverage included, and a change in the lines asks again |
| Refuse or stop bookings by a site rule | Subscribe to `Event\AdBookingValidateEvent::NAME`. `$event->phase` is `save` (staff approving: call `refuse()`) or `daily` (cron: call `stop()`). `$event->booking` gives the booking and its creative (`kind`, `promoted_type`, `promoted_id`, `caption`, `reference`) |
| Per-page controls | `ad_inventory.overrides` (`OverrideStore::set($entity, $space, ['off' => TRUE, 'desktop' => <format>, 'phone' => <format>])`): stored in `ad_override` entities, so no field of the page changes |
| A network | A plugin with `#[AdProvider(id, label, permission)]` extending `Plugin\AdProviderBase`: `unit()` gives the markup per space and format, `loaders()` the script URLs (https only), `adsTxtLines()` the seller lines, `verificationTag()` a site-ownership meta tag, plus its configuration form. Overlay formats (pop-under, push, vignette, interstitial, exit, slide-in) are refused |

## Troubleshooting

| Symptom | Cause |
|---|---|
| A space never appears | Nothing approved fills it: the booking's or creative's state is draft, the creative lacks a rendition for one of the space's two formats, the booking's days don't include today (site time zone), or its scope key isn't on this page. Check as a user with `preview ad drafts` |
| A booking is missing from one page of its scope | Another booking held that page when it was approved, and it was left out («Pages it locked» on its edit form). Re-saving it recalculates: pages freed since then are added |
| No space appears anywhere, though the resolver finds a fill | The formats are gone: a config import deletes what the sync folder lacks, the module's installed formats and `image.style.ad_inventory_*` included. Export them (`drush cex`) before the first import on a site that manages config |
| A reader who accepted sees an empty space | The consent signal isn't reaching the script: check `consent.global` / `consent.category` against the site's consent tool, in the browser console |
| `/ads.txt` lists exchanges nobody signed with | adstxt was installed before `ad_inventory`, or its sample was imported in config. Empty `adstxt.settings:content` |
| The mail image is `private` | `system.performance:cache.page.max_age` is 0 |
| Counts stay at zero | The beacon fires only after consent and on page hide; `/ad/v` must not be blocked by the edge or a firewall rule |
