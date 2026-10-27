# Install

1. `ad_inventory` must be enabled (this is its submodule).
2. `drush en ad_inventory_report`. This creates:
   - the table `ad_inventory_report_daily` (the rollups);
   - the role `ad_inventory_overwatch`, "Marketeer overwatch"
     ([role.md](role.md));
   - the settings `ad_inventory_report.settings`.
3. Export configuration (`drush cex`): the role and the settings are config. A
   site deployed by config import would otherwise drop them.
4. Make sure cron runs (the host's `drush cron`, or `automated_cron`). Each run
   rolls the raw counts into the dashboard's table. Until the first run the
   dashboard says "No counts yet."
5. Grant the role to the people who watch the advertising
   ([role.md](role.md)).

The dashboard is **Ad inventory** at the top of the admin menu, or
`/admin/reports/ad-inventory`.

## Settings

`/admin/config/services/ad-inventory/report` (permission *Administer the ad
inventory*):

| Setting | Default | What |
|---|---|---|
| Booked value from | none | A number or price field the site added to `ad_booking`. Without one, no booked value is shown ([money.md](money.md)). |
| Days each rollup recomputes | 3 | Cron recomputes this many days up to today from the raw counts; older days stay as rolled. |

## Rolling by hand

`drush cron` runs it with everything else. From PHP:
`\Drupal::service('ad_inventory_report.rollup')->run()`, or `->roll($from, $to)`
to recompute a given period (for example after correcting raw counts).

## Uninstall

`drush pmu ad_inventory_report` removes the role (it carries an enforced
dependency on the module), the settings and the rollup table. The parent's raw
counts stay. Re-enabling rebuilds every day on the next cron run.
