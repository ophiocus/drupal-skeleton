# Managing admins

The people who manage every site have an account on every site, made and kept
by code, never by hand (PROTOCOL D29). The host lists them; on every deploy
each one listed exists, with the `administrator` role.

- **Matched by e-mail**, in any letter case. An account that already has the
  address is that person's: it only gains `administrator` when it lacks it.
  Its name, password, status and other roles stay as its owner left them, and
  a blocked account stays blocked (the run says so).
- **A missing account is created** active, with `administrator` and **no
  password**. Its name is the listed name, or the e-mail when another account
  already holds that name.
- **No other account** is ever changed, and **no mail** is sent.
- Removing a person from the list stops keeping their account; it does not
  delete it or take its role. That is done by hand, on purpose.
- Two accounts sharing a listed e-mail, or a site without an `administrator`
  role, stop the run with an error: which account is meant is not this code's
  guess. The deploy's seed step then fails, loudly.

## Where the list lives

Not in the repository and not in config: the skeleton is public, and the list
is personal data. The host gives it to the container in the site's `.env`:

```
MANAGING_ADMINS=Ana:ana@example.com,Bo Ek:bo@example.com
```

`Name:mail` entries separated by commas. On the fleet's host, one root-owned
file holds the list and `tec-managing-admins` writes it into every site's
`.env` (webrunners `docs/MANAGING_ADMINS.md`). A local copy, CI and a fresh
build have no list, and the command does nothing there.

The container reads `.env` when it starts, so a changed list applies from the
site's next deploy (which recreates the container), or at once with
`docker compose up -d --no-deps drupal` and the command below.

## Running it

The host's seed step runs it on every deploy, after the site's
`property:seed`:

```
drush managing-admins:converge          # converge
drush managing-admins:converge --check  # report what would change, write nothing
```

It prints one line with each listed name and what happened to it (`created`,
`role added`, `unchanged`); never an e-mail address, because deploy logs
keep it.

## Getting in the first time

The account has no password. Its owner opens `/user/password` on the site and
asks for a reset link, which goes to their own mailbox; on the fleet the form
carries Turnstile (`sign_in_guard`). Where `sign_in_guard` requires two-factor
for administrators, the first three sign-ins go to the authenticator setup, and
the fourth needs a code.

## Enabling on a site

`core.extension.yml` gains `managing_admins: 0`. The module ships no config,
so `config:status` stays clean.
