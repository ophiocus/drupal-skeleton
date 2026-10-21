# Edge Purge

Purges a CDN in front of the site (Cloudflare) by Drupal cache tag, so pages
can be kept at the edge for a day and still change the moment an editor saves.

## How it works

1. **Tag.** Every cacheable GET/HEAD response gets a `Cache-Tag` header: each of
   its Drupal cache tags as a 12-character hash (`EdgeTags::hash()`), so the
   hundreds of tags on a page fit Cloudflare's 16 KB header limit. Cloudflare
   stores the tags with the cached object and strips the header from what the
   visitor receives.
2. **Queue.** Every invalidated cache tag is written to `edge_purge_queue`, one
   row per tag, inside the same transaction as the save that invalidated it.
3. **Purge.** Drupal never calls the CDN and never holds its token. A host
   timer drains the queue at the CDN's rate limit (Cloudflare Free: 5 purge
   requests a minute, 100 tags each):

   ```
   drush edge-purge:claim --limit=100     # "through <seq> remaining <n>", then one hash per line
   # POST {"tags": [...]} to the CDN's purge API
   drush edge-purge:ack <h1,h2,...> --through=<seq>
   ```

   `ack` removes only rows not invalidated again since the claim, so an edit
   made during a purge is purged on the next run.

## What is never queued

- `http_response`, `route_match`, `routes`, `local_task`: `http_response` is
  on every response and only a router rebuild invalidates it, so queuing it
  purged the whole edge on every `drush cr` for no change.
- `<type>:<id>:revisions` and `<type>_values`: the entity storage's own
  cache; Drupal 11.4 invalidates them on every `resetCache()`, so a bulk load
  of unchanged entities (a seeder, an importer) queued thousands.
- Anything else a router rebuild invalidates (every menu's tag), except
  `4xx-response`: the queue is held from the rebuild's first event to its
  last (`RouterRebuildHold`). A menu edited through the UI still purges.
- `$settings['edge_purge_ignore_tags']` adds tags of your own.

## Releases: purge only what changed

A deploy never purges the whole hostname. Run, after `drush deploy`:

```
drush edge-purge:release      # "baseline …" | "unchanged …" | "rendered N of M files changed …"
```

It hashes the files that can change a rendered page (`RenderFingerprint`:
custom themes, custom and upstream modules, plus `composer.lock`) and compares them with the
last release. Changed: it prints `rendered … pages to verify by hash` and
queues nothing; the host then verifies every cached page (below). Unchanged:
nothing; config and content the release changed are queued by their own
tags. The first run only records. `--dry-run` reports without recording;
`--purge-all` also queues `rendered` (every page), by hand.

## Deploys keep Drupal's finished pages

`drush cr` empties every cache bin, the page, dynamic page and render bins
included, so after each deploy every miss rendered from nothing. The host's
deploy runs, in its place, before and after the config import:

```
drush edge-purge:rebuild      # "rebuilt; pages kept …" | "rebuilt; pages emptied (…)"
```

It is core's own full flush (container, router, plugins, Twig, every other
bin), with those three bins shielded from it, only when no file
`RenderFingerprint` counts changed since the last release. Otherwise, and
with `--all`, it empties them as `drush cr` does, so a verify pass compares
fresh renders. Config and content the release changed reach the kept pages
through their own tags. Run it before `edge-purge:release`, which records the
files it compares against. If it fails the host falls back to `drush cr`.

## Verifying pages by hash

Every tagged response carries `X-Edge-Hash`: a hash of its HTML, without the
assets' cache-busting token (renewed by every cache rebuild) and Views' random
DOM ids. The CDN keeps it with its copy. After a release that changed render
code, the host's warmer (verify mode) compares, page by page, the copy's hash
with a fresh render's and purges by URL only the pages whose HTML changed,
fetching each again at once. Every other page keeps its cached copy.

The hash only works if a page renders the same twice. Anything a template
prints in an order the database does not fix (ties in a sort, a list built
from an unordered query) shows up as a change on every release: give such
lists a full order.

Never counted: tests, `Drush`/`Commands`, `QueueWorker`, `StackMiddleware`,
`config`, `node_modules`, `.install`, `.info.yml`, routing, permission and
link YAML, docs. Still counted, because they change what a cached page shows
or the tags it carries: templates, theme files, CSS/JS, `.libraries.yml`,
`.module`/`.theme`, the rest of `src/` (including `Cache/`), `composer.lock`.
`$settings['edge_purge_render_skip']` adds a site's own folder or file names
(for example its sitemap controller); `$settings['edge_purge_render_paths']`
replaces the roots. A file the rules newly leave out is not a change, so
narrowing them never purges by itself; nor is a file moved unchanged from
one root to another (`modules/custom/x` → `modules/upstream/x` when the
upstream takes a module over).

`edge-purge:clear` empties the queue (after a manual hostname purge).
`edge-purge:status` counts the queue; `edge-purge:hash <tag>` names one tag.

## Install

Enable it through config (`edge_purge: 0` in `core.extension.yml`), so
`drush deploy` installs it and creates the table. Then raise the page max age
(`system.performance` `cache.page.max_age`): a long edge lifetime is safe only
once purges reach the CDN.

## Limits

- A page with more than ~1,100 tags drops the rest from its header; those
  tags rotate by max age instead.
- A missed purge (CDN down, token revoked) leaves the page until max age; it
  never fails a request or a save.
