#!/usr/bin/env bash
# DDEV post-start hook: give the local site the private files path production
# has. Production sets it in deploy/settings.prod.php (/opt/drupal/private, a
# bind mount). Locally web/sites/default/settings.php is each developer's own
# and gitignored, so without this a module that keeps files there (maper's tile
# cache, private uploads) works in production and not in DDEV.
#
# Idempotent: adds the line only when settings.php sets no private path.
set -eu
cd /var/www/html
mkdir -p private
f=web/sites/default/settings.php
[ -f "$f" ] || exit 0
if grep -qE "^\s*\\\$settings\['file_private_path'\]" "$f"; then
  exit 0
fi
chmod u+w web/sites/default "$f" 2>/dev/null || true
cat >> "$f" <<'PHP'

// Private files, outside the web root, laid out like production
// (/opt/drupal/private). Added by scripts/dev/private-path.sh.
$settings['file_private_path'] = '../private';
PHP
echo "private-path: set file_private_path = ../private in $f"
