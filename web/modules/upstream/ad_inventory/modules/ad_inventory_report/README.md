# Ad inventory report

A read-only dashboard over everything `ad_inventory` knows: what is
configured (formats, slots and where they are placed, providers, bookings,
creatives), how it performs (views, clicks, fill by source, sell-through), and
what is wrong (closed gates, missing renditions, unplaced slots). A "poor
man's AdSense dashboard" in the Drupal admin, for someone who watches the
advertising and changes nothing: the **Marketeer overwatch** role, which this
submodule creates.

It adds nothing to public pages: no route, block, library or cache context
outside `/admin`.

| Page | What |
|---|---|
| [docs/install.md](docs/install.md) | Install, cron, uninstall |
| [docs/role.md](docs/role.md) | The Marketeer overwatch role and how to grant it |
| [docs/tabs.md](docs/tabs.md) | Reading each tab |
| [docs/money.md](docs/money.md) | Network earnings and booked value (both optional) |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Empty charts, missing source split, gates |

## In short

- Enable: `drush en ad_inventory_report`. It needs `ad_inventory`.
- Dashboard: **Ad inventory** at the top of the admin menu,
  `/admin/reports/ad-inventory`, eight tabs: Overview, Inventory, Performance,
  Fill, Calendar, Providers, Email, Health.
- Every page has a date range (today, yesterday, 7 days, 28 days, this month,
  last month, custom) compared with the range before it; every table exports
  to CSV (cells starting with `= + - @` are prefixed with `'`).
- The role `ad_inventory_overwatch` ("Marketeer overwatch") gets `view ad
  inventory report`, `export ad inventory report`, `view the administration
  theme` and, where the toolbar module runs, `access toolbar`. Nothing that
  edits, approves or stops anything. Uninstalling removes the role.
- Cron rolls the parent's raw counts into `ad_inventory_report_daily`; the
  dashboard reads only that table and configuration, so it stays light on a
  shared server.
- Settings (booked value field, rollup window):
  `/admin/config/services/ad-inventory/report`.

## Extend

- Add a tab: a route `ad_inventory_report.<tab>` with `tab: <tab>`, a local
  task, and a case in `DashboardController::content()`. Each tab returns
  tables as plain rows (header, rows, optional `spark` series and `links`), so
  the CSV export comes for free.
- Read the rollups elsewhere: service `ad_inventory_report.data`
  (`totals()`, `sum()`, `series()`); cache by the tag
  `ad_inventory_report:daily`, which every rollup invalidates.

## Choices made where the spec left them open

- The menu link sits at the top of the admin menu, since the overwatch role
  has no "access site reports" to reach *Reports*.
- `access toolbar` is granted from `hook_install()` (and when toolbar is
  installed later) instead of `config/install`, because a role naming a
  permission of a module the site lacks cannot be installed.
- Sell-through counts approved bookings only; a slot-day is booked once
  however many bookings share it.
- Booked value sums the amount of bookings whose first day falls in the range.
- The rollup recomputes the last `rollup_days` days (default 3) on each cron
  run; the first run computes every day.
- Rows whose creative is neither in a booking nor a house creative any more
  are shown as source "unknown" rather than dropped.
- Sparklines are inline SVG (no script); the one daily chart uses the ECharts
  file `ga4_report` already ships.
- Image fetches of email slots are not measured (they reach the edge, not the
  site), and the dashboard says so.

## Tests

Unit (CSV formula escaping, date ranges, sparklines), kernel (rollup totals
equal the raw counts, a second run changes nothing, sources split, the role's
exact permissions on install and its removal on uninstall), functional (the
overwatch gets 200 on every tab and CSV, 403 on every edit, approve and stop
route of slots, formats, providers, creatives and bookings; anonymous gets
403; every tab explains itself with no data and no provider; a public page
with slots is still a cache hit with no cookie).
