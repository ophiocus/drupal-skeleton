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
key, used by seeds, by rooms, and by `/book?service=<key>`).

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
clients racing for the last free start cannot both get it. The picker is never
cached (`no_cache`), by Drupal or by a CDN.

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
```

The handler (`SiteSeed\AppointmentServicesHandler`) is registered by
`AppointmentsServiceProvider` only when `site_seed` is installed, so this
module does not depend on it. Services missing from the seed are left alone.
Without `site_seed`, `appointments.seeder`'s `converge($rows)` does the same
(rows: key, label, description, duration, buffer, price, weight, status).

Rooms are config: a site ships its rooms in its own `config/sync`.

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
- *Kernel tests and a stub clock:* set the stub's time before
  `parent::setUp()` and never move it before the install time; a clock at
  zero, or rewound past the install, leaves stale field definitions.

## Rules it keeps

No update, post-update or deploy hooks (D25). The room field ships in
`config/install`. A site that installed the module before rooms existed gets
the field from its own `config/sync` (export it). It also has to record the
new `appointment_room` entity type once, as a step of that deploy. Otherwise
the status report says "The Room entity type needs to be installed":

```
drush php:eval "\Drupal::entityDefinitionUpdateManager()->installEntityType(\Drupal::entityTypeManager()->getDefinition('appointment_room'));"
```
