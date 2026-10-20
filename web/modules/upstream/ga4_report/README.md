# GA4 report

A visits, edge-cache and ad-earnings report in the Drupal admin
(**Reports → Visits (Google Analytics)**, `/admin/reports/ga4`), drawn from
stored snapshots of three sources:

| Source | What it gives | API |
|---|---|---|
| Google Analytics 4 | visits, channels, places, devices, pages, events; visits per minute | Data API `batchRunReports` |
| Cloudflare | requests and cache status per minute (24 h) and per day (≤ 30 days) | GraphQL Analytics API |
| AdSense | estimated earnings, page RPM, impressions, clicks per day | Management API v2 `reports.generate` |

Each source is optional and independent: it refreshes when its ID is set and
its credentials file is named, and it keeps its last good snapshot when a
refresh fails. Snapshots are refreshed by a button, by Drush or by cron;
**viewing the report never calls any of them.**

## What the page shows

Headline numbers from every source, then:

| Card | Chart | Source |
|---|---|---|
| Edge cache and visits, minute by minute | three panels on one time axis: hit rate, requests by cache status, visits; a card per minute | Cloudflare + GA4 |
| Edge cache per day | stacked bars, from cache / not | Cloudflare |
| Visits per day | stacked area: visitors, internal traffic; marked dates | GA4 |
| How visits arrive | Sankey: channel → source → device | GA4 |
| Where visitors are | cities by latitude and longitude in the leading country | GA4 |
| When they come | weekday × hour heatmap | GA4 |
| Devices, systems, browsers | sunburst | GA4 |
| New or returning, by language | mosaic | GA4 |
| What they read | treemap of the most viewed pages, by kind | GA4 |
| Page views by kind of page | bars | GA4 |
| First page of a visit, by channel | stacked bars by kind | GA4 |
| Ad earnings per day | earnings bars over a page-RPM panel | AdSense |
| What Analytics records | table | GA4 |

Every card holds its numbers in a "Show as table" disclosure. Charts are
Apache ECharts 6.1.0; colours are roles in `css/ga4-report.css`, light and
dark (dark follows the admin theme's own dark mode).

## Install

1. The root `composer.json` carries ECharts from asset-packagist (the starter
   does): `"npm-asset/echarts": "6.1.0"`. `google/auth` comes with the
   upstream baseline.
2. Enable it through config (`ga4_report: 0` in `core.extension.yml`) so
   `drush deploy` installs it, or `drush en ga4_report`.
3. Give roles **View the GA4 report** and, if they should, **Refresh the GA4
   report**. **Administer the GA4 report** opens the settings.

## Credentials: by reference, never in config

Each source reads one file, named by **path** in settings.php:

```php
$settings['ga4_report_credentials'] = '/path/outside/the/webroot/ga4-report.json';
$settings['ga4_report_cloudflare_token'] = '/path/outside/the/webroot/cloudflare-analytics.token';
$settings['ga4_report_adsense_credentials'] = '/path/outside/the/webroot/adsense.json';
```

Production sets them from the environment (`deploy/settings.prod.php`):
`GA4_REPORT_CREDENTIALS`, `GA4_REPORT_CLOUDFLARE_TOKEN` and
`GA4_REPORT_ADSENSE_CREDENTIALS` are paths inside the container. The compose
stack mounts `./secrets/` beside it read-only at `/opt/drupal/secrets/`. The
IDs can come from the environment too: `GA4_PROPERTY_ID`,
`GA4_REPORT_CLOUDFLARE_ZONE`, and `ADSENSE_PUBLISHER_ID` (the one the ads
already use).

| Source | File | Kind |
|---|---|---|
| Google Analytics | `ga4_report_credentials` (else `GOOGLE_APPLICATION_CREDENTIALS`) | a service account key; the account is a **Viewer** on the property |
| Cloudflare | `ga4_report_cloudflare_token` | the API token alone, on one line; permission **Zone · Analytics · Read** on the site's zone |
| AdSense | `ga4_report_adsense_credentials` | an authorized-user file (OAuth client + refresh token), minted with `drush ga4-report:google-consent` |

Google files of any other type are refused. The scopes asked for are
`analytics.readonly` and `adsense.readonly`. The settings page says, per
source, whether the file is named and readable; it never reads one out.

### Minting the AdSense file

AdSense reads as a person, so its credential is a refresh token the account's
owner grants once:

```bash
ddev drush ga4-report:google-consent \
  --client=/var/www/html/secrets/oauth-client.json \
  --out=/var/www/html/secrets/adsense.json
```

`--client` is an OAuth client of type **Desktop app** from a Google Cloud
project whose consent screen is **published** ("In production"); a client
left in Testing mints refresh tokens that die after seven days. The command
prints a consent address; open it in the browser signed in as the AdSense
owner, approve, then copy the address the browser fails to load
(`http://127.0.0.1:8765/?…`, which is expected) and paste it back. The file is
written with mode 0600, nothing secret is printed, and the account names it
can see are listed so the settings can name one. `--scope=analytics` mints a
GA file the same way, for a site that has no service account.

## Settings (`/admin/config/services/ga4-report`)

- **Sources**: the GA4 property ID (Admin → Property details), the Cloudflare
  zone ID (the zone's Overview page), the AdSense publisher ID (`pub-…`).
- **Dates**: a date (`2026-01-31`) or `NdaysAgo`, to a date, `today` or
  `yesterday`. Cloudflare's days are capped at the last 30; its minutes are
  always the last 24 hours.
- **Hours between cron refreshes**: 0 leaves refreshing to the button and to
  `drush ga4-report:refresh` (from system cron, say).
- **Internal traffic**, one condition per line, all must match:
  `city: Springfield` and `deviceCategory: desktop`.
- **Kinds of page**, one per line, `id | Label | pattern`; the pattern must
  match the whole path (Google reads it as RE2). A page takes the first kind
  that matches.
- **Marked dates**, `YYYY-MM-DD | Label`.

## Refreshing

```bash
ddev drush ga4-report:refresh                      # every configured source; non-zero if any failed
ddev drush ga4-report:refresh --source=cloudflare  # one source
ddev drush ga4-report:status                       # per source: configured, snapshot, last error
```

Snapshots live in the key-value store (collection `ga4_report`, keys
`<source>.snapshot` and `<source>.meta`), not in State (loaded on every
request) and not in config (data from outside, different in every
environment). Cron retries after the interval, failed attempts included, so a
broken credential is not retried on every cron run.

## How the numbers are counted

- **Edge hit rate** (minute card, tiles): requests served from the edge cache
  (`hit`, `stale`, `updating`, `revalidated`) against those plus `miss`,
  `expired`, `dynamic` and `bypass`. Requests with no cache status, mostly
  Cloudflare's own analytics beacons, are left out and shown apart.
- **Edge cache per day**: Cloudflare's daily totals, beacons included, as its
  dashboard counts them, so a day reads lower than the minutes.
- **Visits per minute** come from GA4 in the property's time zone and are moved
  to UTC to meet Cloudflare's minutes. Google can take hours to process them.
- **Ad earnings** are AdSense's estimate in the account's currency; AdSense
  finalises them at the end of each month.

## City positions

Analytics names cities but never places them. `data/cities.json` holds every
city of 15,000 people or more from [GeoNames](https://www.geonames.org/)
(CC BY 4.0; the page carries the credit), matched on country code and folded
name. A city the table lacks is listed as not placed, never guessed. Rebuild
the table with `scripts/build-cities.php` (instructions inside).

## Tests

Unit (requests, normalising all three sources, dates, kinds, cities, the
settings lines, the HTTP client), Kernel (a refresh of all three sources with
the APIs answered by fixtures, one source failing while the others refresh,
one source alone, cron's interval) and Functional (access, the page drawn from
stored snapshots, the settings form). The fixtures are an invented site.
