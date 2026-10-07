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

`edge-purge:clear` empties the queue after the whole site was purged by
hostname (a deploy does this: a hostname purge supersedes every queued tag).
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
