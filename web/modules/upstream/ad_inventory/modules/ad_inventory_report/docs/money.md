# Money (optional, never required)

The dashboard shows money only where a site has a source for it. It never
invents a price.

## Network earnings

When the skeleton's `ga4_report` module is installed and its AdSense source is
configured (`GA4_REPORT_ADSENSE_CREDENTIALS` and the AdSense account in its
settings; see the `ga4_report` README), the Overview shows:

- **Network earnings** for the range, in the account's currency;
- **Page RPM**: earnings ÷ page views × 1,000, page views as AdSense counts
  them.

The figures come from `ga4_report`'s last stored AdSense snapshot (refreshed by
its own button, Drush command or cron), so they cover only the days that
snapshot holds; widen `ga4_report`'s dates for a longer range. This is a soft
dependency: the service is optional and checked at run time; without
`ga4_report` the card is replaced by a note.

**eCPM per slot** is not shown: `ga4_report` reads AdSense earnings per day,
not per ad unit, so no per-slot figure can be honest.

## Booked value

Bookings carry no amount. To show one, a site adds a number or price field to
the `ad_booking` entity (a base field from its own module, or a field through
code) and names it in the settings
(`/admin/config/services/ad-inventory/report`, *Booked value from*). The
Overview then shows the sum of that field over approved and stopped bookings
whose **first day** falls in the range. Without a field, nothing is shown.

Price fields read their number; number fields their value. Currencies are not
converted.
