# Appointments

Calendly-style booking on one calendar: bookable services with a duration and a
buffer, opening hours and closures, a public slot picker (`/book`), a
confirmation mail with a calendar invite (`.ics`), and cancel or reschedule by
a private link. Staff manage appointments and services in the admin;
settings at `/admin/appointments/settings`.

An upstream module (PROTOCOL D30): every site carries it and enables it only
where it books. It was first written for one site and has nothing of that site
in it.

## Submodule

`appointments_commerce` takes payment through the Commerce checkout: a deposit
or the full price, chosen in the settings. It needs Commerce; on a site without
Commerce it cannot be enabled, and the test gate leaves it out
(`scripts/ci/unmet_upstream.php`).

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

## Rules it keeps

No update, post-update or deploy hooks (D25).
