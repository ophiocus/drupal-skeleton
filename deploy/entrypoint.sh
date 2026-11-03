#!/bin/sh
# Container entrypoint — wraps the base image's docker-php-entrypoint.
#
# The two writable paths (public files, private files) are bind-mounted from
# the host by deploy/docker-compose.yaml. On the FIRST boot the host-side
# directories do not exist yet; Docker creates them as root:root, which
# silently overrides the www-data ownership baked into the image — Apache
# then cannot write a single upload, aggregated CSS file or twig cache entry,
# while `drush` (running as root via `compose exec`) works fine, so nothing
# looks wrong until a user hits the site. Fix the ownership of the mount
# points themselves here, on every start, idempotently.
#
# Non-recursive on purpose: a fresh mount is empty and an established one is
# already www-data below the top level; a recursive chown over years of
# uploads on every restart would be slow and pointless.
set -e

WEB_UID="$(id -u www-data)"
for d in /opt/drupal/web/sites/default/files /opt/drupal/private; do
  mkdir -p "$d"
  if [ "$(stat -c %u "$d")" != "$WEB_UID" ]; then
    chown www-data:www-data "$d"
  fi
done

# robots.txt names the sitemap. The file is core's scaffold, served by Apache
# before Drupal sees the request, so the line cannot come from config; and
# the address it needs is the environment's (production, a dev environment,
# a lab), which no committed file knows. APEX does, so the line is added here
# at start, once per container: not when robots.txt already names a sitemap
# (a site's assets/robots-additions.txt may), and not when ROBOTS_SITEMAP is 0
# (a site that serves no sitemap). docs/GOOGLE_BUSINESS.md.
ROBOTS=/opt/drupal/web/robots.txt
if [ -n "${APEX:-}" ] && [ "${ROBOTS_SITEMAP:-1}" != "0" ] && [ -f "$ROBOTS" ] \
   && ! grep -qi '^Sitemap:' "$ROBOTS"; then
  printf '\nSitemap: https://%s/sitemap.xml\n' "$APEX" >> "$ROBOTS"
fi

exec docker-php-entrypoint "$@"
