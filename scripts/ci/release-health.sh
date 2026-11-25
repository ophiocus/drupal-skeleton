#!/usr/bin/env bash
# Release contract, step "health" (docs/DEPLOY.md §4a). Both performers run this exact file from the
# commit being released: GitHub Actions (build-and-push.yml) and the VPS runner (tec-run).
#
# A green build proves `composer install` succeeded, not that the container serves a page. A property
# once deployed green and then fataled on EVERY request for ~3.5 days (BATTLE_SCARS §19):
#
#   require(/opt/drupal/web/../vendor/autoload_runtime.php): No such file or directory
#
# and answered HTTP 200 with a 500-byte body, so a plain status check would have passed. This gate
# asserts status, a plausible body size and the absence of PHP fatal signatures.
#
# A site the operator put in maintenance answers Drupal's maintenance page with 503. deploy-prod
# restores that state on purpose and never lifts it (lifting is the operator's keystroke), so that
# page is the expected answer. Any other 503, or a fatal, fails.
#
#   bash scripts/ci/release-health.sh <url>
#   env: HEALTH_MIN_BYTES (5000), HEALTH_ATTEMPTS (10), HEALTH_SLEEP_SECONDS (15)
set -u
URL="${1:?usage: release-health.sh <url>}"
MIN_BYTES="${HEALTH_MIN_BYTES:-5000}"
ATTEMPTS="${HEALTH_ATTEMPTS:-10}"
SLEEP_SECONDS="${HEALTH_SLEEP_SECONDS:-15}"
UA='Mozilla/5.0 release-health/1'
body="$(mktemp)"
trap 'rm -f "$body"' EXIT
note() { if [ -n "${GITHUB_ACTIONS:-}" ]; then echo "::$1::$2"; else echo "$2"; fi; }

code=000 bytes=0 fatal=0
for i in $(seq 1 "$ATTEMPTS"); do
  code=$(curl -sL -A "$UA" -o "$body" -w '%{http_code}' --max-time 25 "$URL" || echo 000)
  bytes=$(wc -c < "$body" | tr -d ' ')
  fatal=$(grep -ciE 'Fatal error|Uncaught (Error|Exception)|The website encountered an unexpected error' "$body" || true)
  echo "attempt $i/$ATTEMPTS: http=$code bytes=$bytes fatal_markers=$fatal"
  if [ "$code" = 200 ] && [ "$bytes" -ge "$MIN_BYTES" ] && [ "$fatal" = 0 ]; then
    note notice "Health check passed: $URL is serving ($bytes bytes)."
    exit 0
  fi
  if [ "$code" = 503 ] && [ "$fatal" = 0 ] && grep -q 'class="maintenance-page' "$body"; then
    note notice "$URL answers its maintenance page, as the operator left it. The deploy is verified; lifting maintenance stays the operator's call."
    exit 0
  fi
  [ "$i" -lt "$ATTEMPTS" ] && sleep "$SLEEP_SECONDS"
done

note error "Health check FAILED for $URL after $ATTEMPTS attempts."
echo "Last response: http=$code bytes=$bytes fatal_markers=$fatal"
echo "--- first 800 bytes of the response ---"
head -c 800 "$body"; echo
echo "---------------------------------------"
echo "The image built and deployed, but the site is not serving a healthy page."
echo "Check the container log on the host: docker logs --tail 50 <compose-project>-drupal-1"
exit 1
