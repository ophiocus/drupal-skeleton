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

exec docker-php-entrypoint "$@"
