#!/bin/bash
# scripts/edge-purge-drain.sh <property-dir> <apex> <cloudflare-zone-id>
#
# The host half of the edge_purge module (web/modules/custom/edge_purge): drains
# the property's queue of invalidated cache tags into Cloudflare purge-by-tag.
# Run it every minute from the HOST's cron, one line per property, e.g.
#
#   * * * * * deploy /usr/local/bin/edge-purge-drain /srv/<platform>/sites/example.com example.com <zone-id>
#
# Drupal never calls the CDN and never holds its token: this script is the only
# reader of TOKEN_FILE (Zone -> Cache Purge -> Purge, mode 600, written from a
# hidden prompt; never in the repository, the image or .env).
#
# One purge request per run, at most 100 tags: Cloudflare Free allows 5 purge
# requests a minute per zone. A backlog over BACKLOG tags (a config import, a
# bulk save) costs one hostname purge instead of minutes of tag purges.
# Prints nothing when there is nothing to do; never prints the token.
set -uo pipefail

DIR="${1:?usage: edge-purge-drain.sh <property-dir> <apex> <zone-id>}"
APEX="${2:?apex}"
ZONE="${3:?zone id}"
TOKEN_FILE="${EDGE_PURGE_TOKEN_FILE:-/etc/edge-purge/token}"
BACKLOG="${EDGE_PURGE_BACKLOG:-500}"
# -4 when the token is IP-filtered to the host's IPv4 and the host also has
# IPv6: curl prefers IPv6, and the filter then answers "Authentication error"
# (BATTLE_SCARS §40). Set EDGE_PURGE_CURL_IP= to let curl choose.
CURL_IP="${EDGE_PURGE_CURL_IP--4}"

[[ -r "$TOKEN_FILE" ]] || exit 0
exec 9>"/tmp/edge-purge-drain.${APEX}.lock"
flock -n 9 || exit 0

drush() {
    (cd "$DIR" && docker compose exec -T -u www-data drupal \
        /opt/drupal/vendor/bin/drush --root=/opt/drupal "$@" </dev/null 2>/dev/null)
}

purge() {  # purge <json body> -> 0 on success
    local resp
    # The bearer reaches curl as a config line on stdin, never in argv.
    resp="$(printf 'header = "Authorization: Bearer %s"\n' "$(<"$TOKEN_FILE")" \
        | curl $CURL_IP -s --max-time 30 -K - -X POST -H "Content-Type: application/json" \
            --data "$1" "https://api.cloudflare.com/client/v4/zones/${ZONE}/purge_cache")"
    grep -q '"success":true' <<<"$resp" && return 0
    echo "$(date -Is) ${APEX}: purge refused: $(grep -o '"message":"[^"]*"' <<<"$resp" | head -1)" >&2
    return 1
}

out="$(drush edge-purge:claim --limit=100)" || exit 0   # module not enabled
read -r _ through _ remaining <<<"$(grep -m1 '^through ' <<<"$out")"
[[ "${through:-}" =~ ^[0-9]+$ && "${remaining:-}" =~ ^-?[0-9]+$ ]] || exit 0
hashes="$(grep -E '^[0-9a-f]{12}$' <<<"$out")"
[[ -n "$hashes" ]] || exit 0
count="$(wc -l <<<"$hashes")"

if (( remaining > BACKLOG )); then
    purge "{\"hosts\":[\"${APEX}\",\"www.${APEX}\"]}" || exit 0
    drush edge-purge:clear >/dev/null
    echo "$(date -Is) ${APEX}: $((count + remaining)) tags queued; purged the hostnames and cleared the queue"
    exit 0
fi

tags="$(sed 's/.*/"&"/' <<<"$hashes" | paste -sd, -)"
purge "{\"tags\":[${tags}]}" || exit 0
drush edge-purge:ack "$(paste -sd, <<<"$hashes")" --through="$through" >/dev/null
echo "$(date -Is) ${APEX}: purged ${count} tag(s), ${remaining} still queued"
