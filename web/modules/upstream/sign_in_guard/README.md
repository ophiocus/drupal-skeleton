# Sign-in guard

Two guards on signing in, each switched on by the host, never by config:

- **Two-factor sign-in** (`tfa`, TOTP in an authenticator app, with ten
  printed recovery codes) is required for administrators and content editors
  once the environment carries `TFA_ENCRYPTION_KEY`. Each staff account may
  sign in three times without it; every one of those sign-ins goes straight to
  the setup page, and the fourth needs a code.
- **Cloudflare Turnstile** (`captcha` + `turnstile`) guards the sign-in form
  (`/user/login`) and the password-reset form (`/user/password`) once the
  environment carries `TURNSTILE_KEYS`. It replaces whatever challenge those
  two forms had.

Every site carries the module (`web/modules/upstream/`). Enabling it adds two
Key entities and an encryption profile whose values come from the
environment; it changes no form and no account until the host gives a key.
A local copy, CI and a fresh build have no keys, so none of them can lock
itself out by importing production's config.

## Host

| Variable | Value | Made by |
|---|---|---|
| `TFA_ENCRYPTION_KEY` | 32 random bytes, base64 (`openssl rand -base64 32`) | the host, once per site; never changed (it encrypts every account's TOTP seed: a new key invalidates every enrolment) |
| `TURNSTILE_KEYS` | `{"site_key": "…", "secret_key": "…"}` | the Turnstile widget in the Cloudflare dashboard (free); one widget may list every site's hostname |

Both live in the site's `.env`, which the container reads when it starts.

## Enabling on a site

`core.extension.yml` gains `sign_in_guard: 0` and its six dependencies
(`captcha`, `encrypt`, `key`, `real_aes`, `tfa`, `turnstile`); export the
config those modules install. The overrides are runtime only
(`SignInGuardOverrides`), so `config/sync` keeps the contrib's defaults and
`config:status` stays clean on every environment.

A site with a sign-in form of its own adds a CAPTCHA point for it with
challenge `turnstile/Turnstile`.

## Recovering an account

A staff member who lost the phone signs in with a recovery code. Without one:
`drush tfa:reset-user --name=<name>` on the host, then they set it up again.
