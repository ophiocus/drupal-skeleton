# The upstream — how sites built on this skeleton stay current

This repository is two things. It is the **starter** a new site is copied from,
and it is the **upstream** every site keeps merging afterwards. The model is a
custom upstream in the Pantheon sense: one repository carries what all sites
share, each site carries only what is its own, and a fix made once in the
upstream reaches every site through a merge, not through five hand edits.

The point is advisories. When a security release lands for something every site
runs, it is resolved here, once, and each site's next sync takes it.

## Who owns what

`upstream/OWNERSHIP` is the list, and it is exact: a path is either the
upstream's or the site's, never both.

| The upstream owns (a sync replaces it exactly) | The site owns (a sync never touches it) |
|---|---|
| `upstream-configuration/composer.json`: core and the shared contrib baseline | root `composer.json`: the site's own packages; `composer.lock` (re-resolved by a sync, never replaced) |
| `Dockerfile`, `.dockerignore`, `deploy/entrypoint.sh`, `deploy/settings.prod.php`, `deploy/docker-compose.yaml`, `deploy/.env.example` | `deploy/settings.site.php`: settings that are this site's alone |
| `.github/workflows/build-and-push.yml`, `composer-audit.yml`, `test-gate.yml`, `upstream-sync.yml` | `config/`: the site's configuration |
| `scripts/ci/`, `scripts/upstream/`, `phpcs.xml.dist`, `phpstan.neon`, `phpunit.xml.dist` | `web/modules/custom/`, `web/themes/custom/`: the site's code |
| `docs/UPSTREAM.md`, `docs/DEPLOY.md` | `.ddev/`, `README.md`, `CHANGELOG.md`, its other docs and seeds |
| `web/modules/upstream/`: modules every site carries and enables as it chooses | |

The skeleton's sample module and theme are copied out when a site is minted and
belong to the site from then on.

Because ownership is exact, a sync is not a three-way merge and cannot
conflict. A site that needs an upstream-owned file to behave differently does
not edit it. It sets a variable the file already reads, or puts the difference
in a site-owned file the upstream file includes (`deploy/settings.site.php` is
included last by `deploy/settings.prod.php`), or it changes the upstream for
everyone.

## Composer: one metapackage, one line

`upstream-configuration/composer.json` is a metapackage
(`ophiocus/upstream-configuration`): core, drush, and the contrib every site
runs. A site's root `composer.json` reaches it through a path repository and one
requirement:

```json
"repositories": {
    "upstream-configuration": { "type": "path", "url": "upstream-configuration" },
    "drupal": { "type": "composer", "url": "https://packages.drupal.org/8" }
},
"require": {
    "ophiocus/upstream-configuration": "^1.0",
    "drupal/some_module_only_this_site_uses": "^1.0"
}
```

The site's own packages sit beside it. `require-dev` (test and analysis
tooling) stays in the root, because a dependency's dev requirements are never
installed.

Core is pinned by a floor within the minor the fleet runs, e.g.
`"drupal/core-recommended": "~11.4.10"` (at least 11.4.10, below 11.5). A
security release raises the floor; a minor upgrade is a separate, deliberate
change.

## Syncing

```bash
# in a site, from its root (DDEV for composer)
COMPOSER_CMD="ddev composer" bash scripts/upstream/sync.sh
```

The script fetches the upstream, records its history (`git merge -s ours`, so
`git log` shows which upstream commit the site runs), takes every
upstream-owned path exactly as the upstream has it, deletions included, and
re-resolves `composer.lock` when the upstream's requirements moved. It leaves
one merge commit; pushing it is a deploy, so that stays a person's decision.

In CI the same script runs weekly in `upstream-sync.yml`: sync on a branch,
production audit, the full test gate, then a pull request (or, where the
repository does not let Actions open pull requests, an issue linking the
branch). Merging the pull request deploys the site the usual way.

`scripts/upstream/sync.sh --check` fails when `composer.lock` no longer matches
`upstream-configuration/composer.json`. The test gate runs it, so a site can
never quietly install a baseline the upstream has moved past.

## An advisory, end to end

1. The advisory turns the composer-audit gate red, in the upstream and in every
   affected site.
2. In the upstream: raise the floor in `upstream-configuration/composer.json`,
   re-resolve the upstream's own lock, push. The upstream's CI goes green.
3. In each site: the next upstream sync (or a manual dispatch of
   `upstream-sync.yml`) proposes the merge with the site's own lock
   re-resolved, already audited and tested.
4. Merge, and the site deploys.

An advisory in a package only one site uses is that site's to resolve, in its
own `composer.json`. The upstream answers for what it ships.

## Adopting a site that was copied, not forked

A site minted by copying shares no history with the upstream and has drifted
from it. Adoption happens once per site, as one pull request:

1. Move the shared requirements out of the site's root `composer.json` into the
   one line above; keep the site's own packages. Commit it (the sync starts
   from a clean tree).
2. Run `bash scripts/upstream/sync.sh --adopt`. It records the upstream as
   merged (`--allow-unrelated-histories`) and brings every upstream-owned file
   to the upstream's version.
3. Read the diff of the upstream-owned files. Anything in them that was the
   site's own (a hostname, a fallback, a value only it needs) moves to
   `deploy/settings.site.php` or to a repository variable, never back into an
   upstream-owned file.
4. Set the repository's deploy variables (`DEPLOY_HOST`, `PROD_URL`; see
   `build-and-push.yml`). Without `DEPLOY_HOST` the build warns and deploys
   nothing.
5. Run the test gate locally (`ddev exec bash scripts/ci/tests.sh`) and make
   the site's own code pass it. A site that had no test gate before gets one
   with the adoption.
