# Reading each tab

Every tab has a date range bar: today, yesterday, 7 days, 28 days (the
default), this month, last month, or a custom from/to (366 days at most). The
"previous range" is the same length, just before (for this month and last
month: the whole month before). Days are counted in the site's time zone (the
ad inventory's setting). Every table has an *Export CSV* link for accounts
with *Export the ad inventory report*.

Two facts hold everywhere:

- **A view** is a slot that was at least half on screen for one continuous
  second, counted only for readers who allowed advertising. Nothing is sent
  before that grant, so readers who decline leave no trace: **no consent rate
  is measured**, and every count is a floor.
- **Sources**: *booking* (a booked creative in force), *network* (a provider's
  unit, `provider:<id>`), *house* (a house creative), *unknown* (a creative
  that is in neither any more, for example after its booking was deleted).

## Overview

KPI cards for the range against the previous one: viewed impressions, clicks,
CTR, active bookings (approved and overlapping the range), sell-through
(booked slot-days ÷ available slot-days, every slot × every day), and the
share of views by source. Money cards when available ([money.md](money.md)). A
daily chart of views and clicks (its numbers are in the *Per day* table), the
top 5 slots and creatives, and the closed gates from the ad inventory's status
page as alerts.

## Inventory

It opens on the **space grid**: one row per slot, grouped by page group (the
slot's group, else its block regions), with a desktop column and a phone
column. Each cell names the format with its size and ratio beside a box drawn
to that ratio, or says "none" where the slot shows nothing on that device (an
email slot has no phone format). Each row also lists the pages the slot
reaches: every page, the paths or content types its enabled blocks are limited
to, and the routes where a template printed it. It also lists the slot's fill
sources. The CSV export carries the same columns without the boxes.

Below the grid, every slot in detail: channel, desktop and phone format with size and ratio, fill
sources, booking kinds, group, network unit per provider (pasted code is shown
as "(code)"), **where it is placed**: block placements per theme and region
with their visibility conditions, and the routes on which a template printed
it (recorded by the parent on a render-cache miss, route names only, kept 90
days), and **per-page controls**: how many pages turn it off, change its
format, or limit its count. Then every format with its size, upload size and
network size per viewport.

## Performance

Views, clicks and CTR per slot (with a sparkline per day; the slot name links
to its daily table), per page group (the slot's group, else its block
region), per creative (with a sparkline) and per booking.

## Fill

Per day and slot, views split by source, and the network units that came back
unfilled (where the network marks them, as AdSense does; a house creative
behind an unfilled unit is then counted as the view). Then views and unfilled
units per provider instance.

## Calendar

Not tied to the range: the next 12 weeks from this week's Monday. A slot ×
week grid: *booked* (advertiser and creative), *free*, or *blocked* by a
stopped booking. Lists of bookings starting and ending in the next 7 days,
drafts awaiting approval (with why they are not approved) and stopped bookings
with the reason (a site's validation rule, or who stopped it).

## Providers

Provider instances in fill order: plugin, enabled, the environment gate (on
or off here), the account id masked to its last four characters, label
compliance (the site label against the labels the network allows), and the
consent it needs. Then every `ads.txt` line a provider declares, and whether
`/ads.txt` holds it.

## Email

Per email slot and day: clicks on `/ad/e/<slot>/go`. Then, per email slot, the
creative in force today and its image address. Image fetches (opens) are not
counted: they reach the edge, not the site, and mail clients prefetch images.

## Health

What is wrong, each with a link to the page where an editor fixes it:
creatives missing a rendition for a format their bookings' slots need,
renditions whose ratio is off by more than 1%, slots configured but never
placed, placements naming unknown slots, slots with no fill source in force
today (an empty box after the grant), and providers whose label, account or
exclusions fail. The Marketeer overwatch cannot open those links: it reports,
someone else acts.
