# Appointments

Calendly-style booking for a Drupal site: clients pick a service and a free
time on one page, give their details, and get a confirmation e-mail with a
calendar invite and a private link to cancel or move. Staff see a list with
one-click outcomes. Optional rooms run in parallel; optional online payment
goes through Drupal Commerce (submodule `appointments_commerce`).

An upstream module (PROTOCOL D30): every site carries it and enables it only
where it books. Nothing in it is specific to a kind of business: what a site's
clients fill in (a pet's name, a plate number) are fields the site adds.

## Install

1. Enable `appointments` (it needs `field`, `options`, `user`).
2. Give **Book appointments** to the roles that may book (anonymous for a
   public calendar). Give **View and manage appointments** to staff, and
   **Administer appointments** to whoever sets hours, rooms and services.
3. Add services and set the hours (below). The booking page is `/book`; alias
   it as you like (a site's seed can, e.g. `/agendar`).

For online payment also enable `appointments_commerce` (needs Commerce cart,
checkout, order and store). On a site without Commerce it cannot be enabled,
and the test gate leaves it out (`scripts/ci/unmet_upstream.php`).

## Configure

Everything lives under **Content › Appointments** (`/admin/appointments`):

| Tab | What |
|---|---|
| Appointments | upcoming (or past) appointments; confirm, attended, no-show, cancel |
| Services | what can be booked: name, key, duration, buffer after, price, order, bookable |
| Rooms | the parallel calendars and the services each offers |
| Settings | opening hours, closed dates, booking rules, room choice, payment, currency |
| Manage fields / form display | the fields clients fill in (Field UI) |

**Services** are content entities, located by their *key* (a stable machine
key, used by seeds, by rooms, and by `/book?service=<key>`). They are
translatable: name and description per language, everything else shared
(see *Languages*).

**Rooms** are config entities: each takes one appointment at a time and
follows the calendar's hours. A room lists the services it offers (none listed:
all). With no rooms the calendar is one room taking `capacity` appointments at
once (Settings), as before rooms existed.

**Settings:**

- *Opening hours*: ranges per weekday (`08:00-12:00, 14:00-18:00`).
- *Closed dates*: one `YYYY-MM-DD` per line.
- *Minutes between offered start times*, *minimum notice*, *days ahead*,
  *appointments at the same time* (without rooms), *cancel/move cutoff*.
- *With several rooms, the client*: **any** (sees every room's free times,
  can narrow to one, gets a free room assigned) or **required** (picks a room,
  the first preselected). The room list is hidden whenever only one room
  offers the chosen service.
- *Payment*: nothing online, a deposit (percent or fixed), or the full price.
  Needs `appointments_commerce`; services priced 0 never go to checkout.
- *Staff e-mail*: who is told about new, moved and cancelled appointments.

Settings and rooms are config: on a site deployed by config import, export
them after changing them in the admin, or the next import reverts them.

## How booking works

1. `/book` — the picker: a service dropdown (`?service=<key>`), the rooms
   (`?room=<machine name>`), and a week of open days (`?from=YYYY-MM-DD`).
   Links can preselect: `/book?service=<key>`. The per-service address of
   earlier versions, `/book/{service}`, redirects to the picker.
2. `/book/{service id}/{start}` — the client's details. The time is checked
   again on arrival and inside the save.
3. Confirmed at once, or (paid modes) held for `hold` minutes while the client
   pays; placing the order confirms it, an abandoned or lapsed hold frees it.
4. `/appointment/{token}` — the client's page: details, calendar invite
   (`/ics`), choose another time, cancel (until the cutoff).

Every claim on calendar time runs under one lock and re-checks the slot, so two
clients racing for the last free start cannot both get it.

## Caching

The picker (`/book`, aliased or not) is cacheable by Drupal and at the edge,
for exactly as long as what it shows stays true. The pages after it (details,
the client's page, cancel, move, `.ics`) are never cached (`no_cache`).

- **Changes purge it by tag.** It carries `appointment_list` (any booking,
  move, cancellation or lapsed hold cancelled by cron), `appointment_service_list`,
  `config:appointment_room_list`, `config:appointments.settings` and
  `config:system.date`. With `edge_purge` those reach the CDN's purge queue.
- **The clock ends it.** Its lifetime N is the shortest of: the earliest start
  it shows minus the minimum notice minus now (that start then drops out), the
  next midnight in the calendar's time zone (the days move on), and one step
  between start times. A page moving an appointment (`?reschedule=`) has N = 0.
- **It varies by** `?service=`, `?room=`, `?from=`, `?reschedule=` and the
  content language.
- **Headers.** `EventSubscriber\FreshHeaders` runs at -10, after core's
  `FinishResponseSubscriber` (0) and before edge_purge's tag header (-1024):
  - where core made the page public, it sends `Cache-Control: public,
    max-age=N` and `Cloudflare-CDN-Cache-Control: max-age=N`;
  - it always sends `Expires: now + N`, which is what Drupal's page cache keeps
    a page by. That includes a site whose page max-age is 0, where the page
    stays private but core's past `Expires` would keep it forever;
  - when N = 0, it triggers the page cache kill switch and sends `no-cache,
    private`.

  edge_purge sets its own CDN lifetime only where a page set none.
- **Served again, re-aged.** The controller attaches the deadline as a moment
  (an internal `X-Appointments-Fresh-Until` header, never sent), so a copy from
  Drupal's dynamic page cache or page cache still knows when it ends.
  `StackMiddleware\FreshMiddleware`, outside the page cache, sets max-age to
  the seconds left on every copy it serves.
- A stale start is harmless: the details page checks the time again, and the
  save checks it once more under the lock.

## Mail

Keys `<event>.<audience>` through `hook_mail()`: `confirmed`, `rescheduled`,
`cancelled`, `expired` (client only), `conflict` (staff only: a payment arrived
after its hold lapsed and the time was taken). Any mail backend works; the
texts are in `src/Messages.php` and translatable.

## Seeding services

Where `site_seed` is enabled, a seed file's `appointment_services` section
converges the bookable services, matched on `key`:

```yaml
appointment_services:
  - key: consulta
    label: 'Consulta general'
    duration: 30
    buffer: 10
    price: '60000'
    weight: 0
    status: true
    # Optional: the name and description in the site's other languages.
    translations:
      en: { label: 'General consultation', description: 'A full check-up.' }
```

The handler (`SiteSeed\AppointmentServicesHandler`) is registered by
`AppointmentsServiceProvider` only when `site_seed` is installed, so this
module does not depend on it. Services missing from the seed are left alone.
Without `site_seed`, `appointments.seeder`'s `converge($rows)` does the same
(rows: key, label, description, duration, buffer, price, weight, status,
translations).

A row's label and description are the service in the site's default
language. `translations` adds or updates the other languages, which must be
installed, and only label and description; a translation the row does not
name is left alone. A changed translation counts as an update of its service.

Rooms are config: a site ships its rooms in its own `config/sync`.

## Languages

A site with one language needs nothing. On a site with a second language
(e.g. Spanish first, English under `/en`):

- **Services** are translated as content. Enable translation for *Services*
  at *Regional and language › Content language and translation*
  (`content_translation`) and export the config it writes
  (`language.content_settings.appointment_service.appointment_service`). The
  service list then has a *Translate* operation
  (`/admin/appointments/services/{id}/translations`). A seed fills them with
  `translations:` (above).
- **Rooms** are translated as configuration (`config_translation`): *Translate*
  on the room list, `/admin/appointments/rooms/{room}/translate`. The result is
  `config/sync/language/<langcode>/appointments.room.<room>.yml`; export it.
- **Interface text** (buttons, headings, mails) comes from
  `translations/<langcode>.po` (`es.po` ships with the module).
- **Every list renders in the content language, one row per service.** The
  picker, the service list and the room form list services through
  `Calendar::services()`: it queries the default-language rows only, then
  shows each service in the content language, or in its own language when it
  has no translation in it. The order is weight, then the translated name,
  then id. The client's page, the details form, the `.ics` file and the staff
  list read the service through `Appointment::getService()`, translated the
  same way. Mails use the mail's language. A Views listing of services built on
  a site must do the same: filter on *Default translation* (or on the content
  language), render in the content language, and set the query to *distinct*
  when it joins translatable terms.

## Extend

- **Fields clients fill in:** add fields to the Appointment entity (Field UI
  or config); the booking form shows what the form display holds, and staff
  mails list every `field_*` value.
- **Online payment by other means:** register a service `appointments.checkout`
  implementing `CheckoutHandlerInterface::start()`, which receives the saved,
  pending appointment (its `amount` set) and returns where to pay. Call
  `appointments.booking`'s `confirm()` when paid and `cancel(…, 'silent')` when
  abandoned.
- **Translations:** `translations/es.po` (interface translation project
  `appointments`). Status words use the context `appointment status`, so they
  never rename the same English word elsewhere (Commerce's "Completed").

## Troubleshooting

- *No free times shown:* check the day is open (hours, closed dates), within
  the horizon and past the minimum notice, and that a bookable room offers
  the service (with rooms defined, a service no room offers cannot be booked).
- *Online payment is offered nowhere:* `appointments_commerce` is off, or the
  service is priced 0.
- *Settings or rooms reverted after a deploy:* they are config; export them.
- *Status report: "The Service entity type needs to be updated" (or "The Room
  entity type needs to be installed"), or a 500 naming
  `appointment_service_field_data`:* the one-time step below was not run.
- *The picker shows a time that is already taken:* it cannot for longer than
  the page's max-age when the purge works; check the edge purge queue drains
  (`drush edge-purge:status`). The details page refuses the time anyway.
- *Service names in the default language under `/en`:* the service has no
  translation in that language (seed `translations:` or *Translate*).
- *Kernel tests and a stub clock:* set the stub's time before
  `parent::setUp()` and never move it before the install time; a clock at
  zero, or rewound past the install, leaves stale field definitions.

## Rules it keeps

No update, post-update or deploy hooks (D25). A change to how an entity type
is stored reaches a deployed site as a one-time step instead, which the site
runs by hand once, right after the `drush deploy` that brings the code and
before its seed:

```
drush appointments:entity-updates --check   # what is pending; writes nothing
drush appointments:entity-updates           # does it; again: "up to date"
```

It checks what the database has, so it is safe to run twice and does nothing
on a fresh install. The steps it knows (`EntitySchema`):

1. **Rooms (2026-10-26):** records the `appointment_room` entity type. The
   room field ships in `config/install`; a site that installed the module
   before rooms gets it from its own `config/sync` (export it). This replaces
   the `installEntityType` php:eval line of that release.
2. **Translatable services (2026-10-28):** moves services from one table to
   a base table plus `appointment_service_field_data`, with `langcode` and
   `default_langcode`. Every service is copied over in the site's default
   language, with its id, key and values, so appointments keep pointing at
   it. Until the step runs, every page that lists services fails (the code
   reads a table that does not exist yet), so run it in the same deploy.

Rehearse it on a copy of the site's production database first (`--check`,
then the step, then `config:status` and the booking page).

## Adopting a new version on a deployed site

**A site with one language that already books** (services seeded by
`appointment_services`, rooms in `config/sync`):

1. Sync the upstream, then deploy as usual (`drush deploy`).
2. `drush appointments:entity-updates`: services become translatable, nothing
   else changes for a site with one language.
3. `property:seed` as every deploy: expect `unchanged` for every service.
4. The booking page is now cacheable. With `edge_purge` and the purge timer,
   bookings purge it. A site whose page max-age is 0 still sends it
   `private`; Drupal's own page cache keeps it until its deadline.
5. Check: `/book` (or its alias) answers 200 with `Expires` a few minutes
   ahead, `?service=<key>` links still preselect, and a test booking takes its
   start off the page.

**A site with a second language** (Spanish first, English under `/en`): steps
1–3, then:

4. Enable translation for *Services* (`content_translation`) and export
   `language.content_settings.appointment_service.appointment_service`.
   Importing it on the deployed site adds content_translation's own columns
   by itself.
5. Add `translations: { en: { label, description } }` to each service row of
   the seed, then `property:seed`: each service counts one update, and a
   second run says `unchanged`.
6. Rooms: translate them (config_translation) if not done, and export
   `config/sync/language/en/appointments.room.*.yml`.
7. Check: `/en/<alias>` lists every service once, in English, with English
   room names, and the details page title reads "Book: <English name>".
