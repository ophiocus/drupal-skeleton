# PROTOCOL

The rules of engagement for this project, plus the running decision
log. Every architectural choice gets a one-paragraph entry — future
you needs the *why*, not just the *what*.

## §1 — Working principle: DDEV-only

> All code operations (composer, npm, phpunit, vitest, drush,
> phpstan, phpcs, …) run inside DDEV. No bare-host fallback path.

This is non-negotiable. PHP versions, Node versions, ext-mongodb,
GD library quirks, locale settings — every "works on my machine"
trap dissolves once the only machine that exists is the DDEV
container.

Practical implications:

- Every `package.json` script is meant to be invoked via
  `ddev exec npm run <script>` (or `ddev npm run <script>`).
- Every `composer.json` script likewise: `ddev composer …`.
- `ddev drush ...` for Drush. Never bare `drush`.
- If a tool isn't available in DDEV's container, install it inside
  via `webimage_extra_packages:` or a custom Dockerfile under
  `.ddev/web-build/Dockerfile.example` — never `apt install` on
  the host as a workaround.

## §2 — Repo identity

Set in the project's gitconfig (or inherited from
`~/.gitconfig.ssdnodes` via path-conditional `includeIf` in the
host's `~/.gitconfig`). Do not commit identity-bearing config files
into the repo.

## §3 — Commit discipline

- Every commit ends with `Co-Authored-By: Claude Opus <...>` when
  AI-assisted.
- New work: NEW commit. Rebase/squash sparingly and only if it
  preserves traceability of decisions.
- Sensitive files (`.env`, `*.pem`, `.ddev/.env`) never in the
  staging area — `.gitignore` is belt-and-suspenders, never the
  only line of defense.

## §4 — Documentation hygiene

Three living documents, in order of read frequency:

1. **`README.md`** — "How do I work this?" Five-minute orientation.
2. **`CHANGELOG.md`** — what changed, when. Append-only.
3. **`docs/PROTOCOL.md`** (this file) — the rules + decision log.
4. **`docs/BATTLE_SCARS.md`** — the lessons that cost time. Append
   anything that took >30 minutes to figure out.

Project-shape-specific docs (THESIS, ARCHITECTURE, EDITORIAL,
SUBJECT, etc.) live alongside but vary per project; don't
prescribe them here.

## §5 — Decision log

Record every architectural choice as `Dn` (development), `En`
(epic-level), `Cn` (content/editorial). One paragraph: question,
chosen answer, one line of reasoning. The format is:

```
### D1 — <one-line question>

Answer: <one phrase>.

Reason: <one paragraph>.
```

Decisions are append-only. If a decision is overturned, add a
new entry referencing the old (`D7 supersedes D3`); never edit
the original.

### D1 — Drupal version

Answer: Drupal 11.4 on PHP 8.3 (refreshed 2026-08; was 11.3 at 2026-05).

Reason: Drupal 11 is the supported major; the composer constraint tracks
the current minor (`^11.4`) so `composer update` follows patch releases.
PHP 8.3 is what the contrib ecosystem expects and what the production
images run; moving to 8.4 is a fleet-wide decision (image base + every
property), not a skeleton-only bump.

### D2 — Local DB engine

Answer: MariaDB 11.4.

Reason: DDEV's default; well-tested with Drupal 11; easier MySQL
compatibility than 10.x. Switch to PostgreSQL only if a project
specifically needs it.

### D3 — Local web server

Answer: nginx-fpm.

Reason: Lower resource use than apache-fpm for local dev; faster
DDEV startup. Production-bound projects with Traefik routing
should switch to apache-fpm before deploying — see
`docs/BATTLE_SCARS.md` §"nginx vs apache behind Traefik."

### D4 — Stability

Answer: `minimum-stability: dev`, `prefer-stable: true`.

Reason: Several useful contrib modules (AI providers, paragraphs,
some recent additions) ship long-running RCs or dev branches
that are production-stable in practice but composer-unstable in
theory. The `prefer-stable: true` keeps stable releases when
they exist; the `dev` floor lets RCs resolve when they don't.

### D5 — Test runner (PHP)

Answer: PHPUnit 10.5 with strict mode on.

Reason: Drupal core's own bootstrap; failing-on-warning catches
deprecations early; risky-test failures catch tests that don't
actually assert anything.

### D6 — Production container base image pinning

Answer: `FROM drupal:11-php8.3-apache` — never the floating
`drupal:11-apache` meta-tag.

Reason: The unversioned `drupal:11-apache` tag tracks whatever PHP
version the Drupal maintainers default to. Today that's 8.3; when
they advance it to 8.4, every property silently rolls forward on
next `docker compose pull` — likely fine, occasionally a problem
(contrib module compatibility, JIT changes, deprecations). The
explicit `php8.3-apache` tag still tracks 8.3.x patch releases
(security fixes apply automatically) but won't cross the 8.4
boundary without a deliberate edit. Local DDEV matches via
`php_version: "8.3"`. Codified 2026-05-15 as platform-wide standard;
see webrunners `docs/PROPERTY_PROTOCOL.md` §1a.

<!-- Append project-specific decisions below this line. -->

### D13 — Test runner version (supersedes D5)

Answer: PHPUnit 11.5.

Reason: Drupal 11.3.3+ core-dev requires `phpunit/phpunit ^11.5`; the prior
`^10.5` pin (D5) makes `composer install` unsolvable on current Drupal 11.3.
D13 supersedes D5.

### D14 — Contrib repository

Answer: Bundle the `packages.drupal.org/8` composer repository.

Reason: Contrib modules are not on Packagist; without the drupal.org repo a
project installs core but no contrib. Baked in so every spawned project can
`composer require drupal/<contrib>` immediately.

### D15 — DDEV project type

Answer: `type: drupal` (version-agnostic).

Reason: `type: drupal11` is rejected by older DDEV (v1.23.5); `type: drupal`
is portable and auto-detects core 11.

### D16 — Node runtime line

Answer: the current active Node LTS — `nodejs_version: "22"` as of 2026-08
(Node 20 went EOL 2026-04-30). Bump when the line goes EOL, not before.

Reason: the container Node only drives theme tooling (vite, eslint,
stylelint) — nothing production-facing — so tracking the LTS line is cheap
and keeps toolchains installable. Pin a specific line, never `lts/*`, so two
clones on different days build the same assets. The same rule applies to CI:
every GitHub Action `uses:` stays on its Node-24 major (checkout@v7 …);
GitHub deprecated the Node 20 action runtime in 2025-09.

### D17 — Production deploy scaffold ships with the skeleton (supersedes D3)

Answer: `Dockerfile`, `.dockerignore`, `deploy/{docker-compose.yaml,
.env.example, settings.prod.php, entrypoint.sh}`, `config/sync/` and
`.github/workflows/build-and-push.yml` are part of the foundation and are
used **unchanged** by every project minted from it. Per-property values live
in the host `.env` and in three GitHub Actions settings (`PROD_URL`,
`DEPLOY_HOST`, `VPS_DEPLOY_KEY`) — never edited into the files. Local DDEV
runs `apache-fpm` to match the image (D3 said nginx-fpm locally + "switch
before deploying"; that switch was the first thing to drift).

Reason: the deploy path was the one part of a property that was copied by
hand from a sibling and then evolved in place. Four properties, four
Dockerfiles, three workflow variants, two of them without the health gate
that had already saved a third. When the fix for a shared problem (an audit
advisory, a deprecated action runtime, a missing bind-mount chown) has to be
re-derived per property, it gets applied to some of them. Baking the path
into the skeleton makes a new property correct by construction, and makes
"what does the fleet's deploy look like?" a single file to read. The gates
in the workflow — audit, image build, HTTP health — are the ones that have
each caught a real incident (BATTLE_SCARS §18–§21). The contract is
`docs/DEPLOY.md`.

### D18 — Outbound mail: one DSN, asserted defaults, loud failure

Answer: `drupal/symfony_mailer_lite` with a single `dsn` transport entity whose
`dsn` stays **empty in config** and is filled at runtime from `MAILER_DSN`.
`scripts/setup_mail.php` creates it, points `mailsystem.settings` defaults at
`symfony_mailer_lite`, and stands up the anonymous-accessible contact form at
`/contact`. `SITE_MAIL` and `CONTACT_RECIPIENT` are env-driven too. With
`MAILER_DSN` unset the site keeps the native transport and mail fails loudly —
never the `null` transport.

Reason: three things had to be true at once. **(1) No secret in git** — a DSN is
one string, so unlike the `smtp` module's host/port/user/pass (which stores the
password in `smtp.settings`, i.e. in `config/sync`) there is nothing to
accidentally export. **(2) Provider-agnostic** — the same env var takes
SendGrid, Mailgun, SES, Postmark or a plain relay, so switching providers is an
`.env` edit, not a config change. **(3) Failure must be visible** — the runtime
image has no MTA, and Drupal's contact form reports "Your message has been sent"
regardless, so the default-off state has to be noisy rather than convenient.
Chosen over `drupal/smtp` (password-in-config) and full `symfony_mailer`
(replaces the mail API wholesale — more surface than a property needs).
