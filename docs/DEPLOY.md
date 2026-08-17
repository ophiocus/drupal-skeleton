# Deploying to production

The skeleton is opinionated about how a site reaches production. The
scaffold below ships in every project minted from it and is meant to be
used **unchanged** — per-property values live in the environment, never in
the files (PROTOCOL D17). This document is the contract between the three
parties involved: the repo, CI, and the host.

```
repo                          CI (GitHub Actions)               host (one dir per property)
────────────────────────────  ────────────────────────────────  ─────────────────────────────
Dockerfile          ───────►  build image                        /srv/<platform>/sites/<apex>/
.dockerignore                 push ghcr.io/<owner>/<repo>:tag     ├── docker-compose.yaml  (= deploy/docker-compose.yaml, verbatim)
deploy/settings.prod.php      ssh DEPLOY_HOST deploy-prod <repo>  ├── .env                 (from deploy/.env.example)
deploy/docker-compose.yaml    curl PROD_URL   (health gate)       └── data/{db,drupal-files,drupal-private}/
deploy/.env.example
config/sync/                  audit gate (composer.lock)          deploy-prod / deploy-dev on PATH
```

## 1. What the repo ships

| File | Role | Edit per property? |
| --- | --- | --- |
| `Dockerfile` | Multi-stage build: `composer install --no-dev` from the committed lock, then a runtime image with the docroot at `/opt/drupal/web`, drush on `PATH`, `deploy/settings.prod.php` installed as `settings.php`. Base pinned to `drupal:11-php8.3-apache` (D6). | Rarely. Add PHP extensions or `--build-arg PHP_MEMORY_LIMIT=…` if the stack needs them. |
| `.dockerignore` | Keeps composer-managed, DDEV-only, per-site and secret paths out of the build context. The Dockerfile does `COPY . /opt/drupal/`, so this file is the whole safety net. | When you add a top-level directory that must not ship. |
| `deploy/settings.prod.php` | The tracked production `settings.php`. Everything from `getenv()`; reverse-proxy aware; `config_sync_directory = ../config/sync`. | Only below the *Project settings* marker, env-driven. |
| `deploy/entrypoint.sh` | Container entrypoint: re-asserts `www-data` ownership of the two bind-mounted writable paths on every start (Docker creates missing host dirs as `root` on first boot), then hands off to the base image's entrypoint. Removes the manual `chown 33:33` from first deploy. | No. |
| `deploy/docker-compose.yaml` | The host-side stack: `drupal` + `db` (MariaDB 11.4), Traefik labels, edge network, bind-mounted state. Fully interpolated from `.env`. | No. |
| `deploy/.env.example` | Every key the stack and the image read. Copy to `.env` on the host and fill in. | On the host, not in git. |
| `.github/workflows/build-and-push.yml` | audit → build → push → deploy → health gate. Deploy/health steps self-skip until the repo variables exist. | No. |
| `.github/workflows/composer-audit.yml` | The standalone floor: same audit on PR + weekly schedule (catches advisories published after merge). | No. |
| `config/sync/` | Exported config, the deploy source of truth (`drush deploy` imports it). | Yes — that is the point. |

`web/sites/default/` is gitignored on purpose; the image never depends on
anything under it except the volume-mounted `files/`.

## 2. What CI needs (once per repo)

GitHub → repo → *Settings → Secrets and variables → Actions*:

| Kind | Name | Value | Effect |
| --- | --- | --- | --- |
| variable | `PROD_URL` | `https://example.com` | enables the post-deploy health gate |
| variable | `DEPLOY_HOST` | `deployuser@host` | enables the deploy steps |
| secret | `VPS_DEPLOY_KEY` | private key accepted by `DEPLOY_HOST` | SSH auth for deploy |

Until `DEPLOY_HOST` exists a push still audits, builds and pushes the image
— the Dockerfile is proven in CI from the first commit — and simply does
not deploy. `GITHUB_TOKEN` is enough to push to GHCR (`packages: write`).

The image is `ghcr.io/<owner>/<repo>` and the site slug passed to the host
is the **repository name**. Keep repo name = image name = the name the
host's `deploy-prod` knows.

## 3. What the host needs (once per property)

1. A shared reverse-proxy network the property attaches to
   (`docker network create <EDGE_NETWORK>`), a Traefik with a certresolver
   named `<TRAEFIK_CERTRESOLVER>`, and `docker login ghcr.io` persisted for
   the deploy user.
2. A property directory, e.g. `/srv/<platform>/sites/<apex>/`, containing
   `docker-compose.yaml` (verbatim copy of `deploy/docker-compose.yaml`) and a
   filled-in `.env` (from `deploy/.env.example`; `openssl rand -hex 32` for
   secrets). `data/` is created on first `up`.
3. Two commands on the deploy user's `PATH`, callable non-interactively over
   SSH with `VPS_DEPLOY_KEY`:

   ```
   deploy-prod <site>              # cd <property dir> && docker compose pull && up -d
                                   #   && drush deploy -y || drush cr
                                   #   (idempotent; never touches data/)
   deploy-dev  <site> <dev-branch> # ephemeral <slug>.dev.<apex> environment
   ```

   These are platform infrastructure, not property code — they map `<site>`
   to the property directory and own the pull/up/deploy sequence. Restrict
   the deploy key with a forced command so CI can run exactly these and
   nothing else.

First deploy is manual: fill `.env`, `docker compose up -d`, then either
`drush site:install … && drush config:import` or restore a dump — after
that, every push to `master` is a release.

Run drush inside the container **as the web user** so anything it writes
under `files/` (twig cache, aggregated assets, uploads via migrations) stays
writable by Apache: `docker compose exec -T -u www-data drupal drush …`.
Root-run drush works, but leaves root-owned files behind that Apache later
cannot overwrite.

## 4. Release flow, and what each gate proves

| Step | Proves | Does not prove |
| --- | --- | --- |
| `composer audit --no-dev --locked` | no known advisory in a runtime dep | anything about dev deps (run the full audit locally — BATTLE_SCARS §20) |
| image build | `composer install` succeeds; scaffold generated | the container serves a page (§19) |
| `deploy-prod` | new image running, `drush deploy` ran or `cr` fell back | that config imported cleanly (read the run log) |
| health gate | HTTP 200 **and** ≥ 5000 bytes **and** no fatal markers, within 10 × 15 s | deeper functional health — add a `/…/health` route (the example module ships one) |

Roll back by re-tagging: `docker compose pull` of a previous `sha-…` tag (set
`IMAGE=` in `.env` temporarily) or push a revert commit. Volumes are never
touched by the pipeline, so rollback is image-only.

## 5. Local parity

DDEV runs `apache-fpm` (D17) so `.htaccess`, rewrites and upstream behaviour
match the image. To exercise the real image locally without a host:

```bash
docker build -t mythingname:local .
docker run --rm -e DRUPAL_TRUSTED_HOSTS='^localhost$' -p 8080:80 mythingname:local
```

(No DB → Drupal will show the installer; the point is that the image boots,
Apache serves `web/`, and drush is on `PATH`: `docker run --rm
mythingname:local drush --version`.)
