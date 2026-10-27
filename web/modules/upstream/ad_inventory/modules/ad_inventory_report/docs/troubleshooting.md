# Troubleshooting

## "No counts yet", or the chart is flat at zero

- **Cron has not run since the counts arrived.** The dashboard reads only the
  rollups. Run `drush cron` and reload.
- **Nothing has been counted.** A view needs a placed slot, a reader who
  allowed advertising, and that slot half on screen for one second. Check the
  ad inventory's status page: an unnamed consent bridge means every reader
  sees the blocked banner and nothing is ever counted.
- **The range is in the future or too short.** Try 28 days.
- **The chart is missing but the tables are there.** The ECharts file
  (`web/libraries/echarts/dist/echarts.min.js`, from `npm-asset/echarts` in the
  root `composer.json`) is not installed. Every number is still in the tables.

## Views do not split by source

- Network views appear only as `provider:<id>`. A site still running an older
  copy of the parent's script sends a creative id for network fills; update
  the parent.
- *unknown* means a counted creative is neither a house creative nor in a
  booking that covered that slot and day (its booking was deleted, or its
  house flag removed). Re-flagging does not change rolled days older than the
  rollup window; recompute them with
  `\Drupal::service('ad_inventory_report.rollup')->roll($from, $to)`.
- A booking edited after it ran changes the split of the days still in the
  rollup window only.

## Sell-through looks low

It counts every slot (web and email) for every day of the range as available,
and only approved bookings as booked. Slots that are never sold (house only)
still count as available.

## Gates in the Overview alerts

They are the ad inventory's status page, verbatim. The common ones:

- *No consent signal is named*: set the consent object and property in the ad
  inventory's settings.
- *Network serving is off*: `AD_INVENTORY_NETWORK=1` (or
  `$settings['ad_inventory_network']`) is not set here. Bookings and house
  creatives still serve.
- *no account id*: the provider's account is not in `settings.php` or the
  environment.
- *allows only … above its units*: the site label is not one the network
  accepts.
- */ads.txt lacks …*: add the line, or give the provider its account id (the
  module then adds it).

## The overwatch gets 403 on a tab

The account lacks the role or *View the ad inventory report*. CSV links need
*Export the ad inventory report* too. Edit, approve and stop pages are 403 by
design.

## Money cards are missing

See [money.md](money.md): network earnings need `ga4_report` with its AdSense
source; booked value needs an amount field named in the settings.
