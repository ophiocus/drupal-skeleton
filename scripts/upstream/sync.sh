#!/usr/bin/env bash
# Take the upstream into this site: the paths the upstream owns, exactly; the
# site's own paths, untouched; then the lock, re-resolved against the
# upstream's requirements. docs/UPSTREAM.md is the contract.
#
#   bash scripts/upstream/sync.sh            sync from upstream/master
#   bash scripts/upstream/sync.sh --adopt    first sync of a site that was
#                                            copied, not forked (no shared history)
#   bash scripts/upstream/sync.sh --check    exit 1 if the lock does not match
#                                            upstream-configuration (no changes made)
#
# Env:
#   UPSTREAM_URL   default https://github.com/ophiocus/drupal-skeleton.git
#   UPSTREAM_REF   default master
#   COMPOSER_CMD   default "composer"; locally use "ddev composer"
#
# Why no three-way merge: ownership is exact. A path either belongs to the
# upstream (its version wins, always) or to the site (it is never touched), so
# there is nothing to reconcile and a sync can never conflict. The upstream's
# history is still merged (`-s ours`), so `git log` shows which upstream commit
# a site runs and the next sync knows where it starts from.
#
# Writes "changed=true|false" and "upstream=<sha>" to $GITHUB_OUTPUT when set.
# Leaves a merge commit; pushing it is the caller's business.
set -euo pipefail
cd "$(dirname "$0")/../.."

MODE="${1:-}"
URL="${UPSTREAM_URL:-https://github.com/ophiocus/drupal-skeleton.git}"
REF="${UPSTREAM_REF:-master}"
COMPOSER_CMD="${COMPOSER_CMD:-composer}"
out() { [ -n "${GITHUB_OUTPUT:-}" ] && echo "$1" >> "$GITHUB_OUTPUT"; echo "$1"; }

# The lock is fresh when the metapackage it records requires exactly what
# upstream-configuration/composer.json requires.
lock_fresh() {
  php -r '
    $want = json_decode(file_get_contents("upstream-configuration/composer.json"), TRUE)["require"] ?? [];
    $lock = json_decode(file_get_contents("composer.lock"), TRUE);
    foreach ($lock["packages"] ?? [] as $p) {
      if ($p["name"] === "ophiocus/upstream-configuration") {
        ksort($want); $got = $p["require"] ?? []; ksort($got);
        exit($got == $want ? 0 : 1);
      }
    }
    exit(1);'
}

# drupal/core-dev (root require-dev) is at core's exact version.
core_dev_aligned() {
  php -r '
    $v = [];
    $lock = json_decode(file_get_contents("composer.lock"), TRUE);
    foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $p) { $v[$p["name"]] = $p["version"]; }
    exit(!isset($v["drupal/core-dev"]) || $v["drupal/core-dev"] === ($v["drupal/core"] ?? NULL) ? 0 : 1);'
}

if [ "$MODE" = "--check" ]; then
  if lock_fresh; then echo "lock matches upstream-configuration"; exit 0; fi
  echo "composer.lock does not match upstream-configuration/composer.json:"
  echo "run: COMPOSER_CMD=\"ddev composer\" bash scripts/upstream/sync.sh  (or the upstream-sync workflow)"
  exit 1
fi

# The upstream itself has nothing to sync from.
if php -r 'exit(json_decode(file_get_contents("composer.json"), TRUE)["name"] === "ophiocus/drupal-skeleton" ? 0 : 1);'; then
  echo "this is the upstream itself; nothing to sync"
  out "changed=false"; exit 0
fi

if git remote get-url upstream >/dev/null 2>&1; then
  git remote set-url upstream "$URL"
else
  git remote add upstream "$URL"
fi
git fetch -q upstream "$REF"
UP="$(git rev-parse FETCH_HEAD)"
out "upstream=$UP"

if [ "$MODE" != "--adopt" ] && git merge-base --is-ancestor "$UP" HEAD 2>/dev/null; then
  echo "already at upstream ${UP:0:7}"
  if lock_fresh && core_dev_aligned; then out "changed=false"; exit 0; fi
  echo "…but the lock is stale; re-resolving it"
fi

git diff --quiet && git diff --cached --quiet || { echo "working tree not clean; commit or stash first" >&2; exit 1; }

# Ownership list: the upstream's, not the site's (a new owned path must apply
# on the very sync that introduces it).
mapfile -t OWNED < <(git show "$UP:upstream/OWNERSHIP" | sed -e 's/#.*//' -e 's/[[:space:]]*$//' | grep -v '^$')

if ! git merge-base --is-ancestor "$UP" HEAD 2>/dev/null; then
  extra=(); [ "$MODE" = "--adopt" ] && extra=(--allow-unrelated-histories)
  git merge -q -s ours --no-commit "${extra[@]}" "$UP"
fi

for path in "${OWNED[@]}"; do
  if [[ "$path" == */ ]]; then
    # Directory: drop what the upstream no longer has, then take its tree.
    git ls-files -z -- "$path" | while IFS= read -r -d '' f; do
      git cat-file -e "$UP:$f" 2>/dev/null || git rm -q -- "$f"
    done
    if git cat-file -e "$UP:${path%/}" 2>/dev/null; then
      git checkout "$UP" -- "$path"
    fi
  else
    if git cat-file -e "$UP:$path" 2>/dev/null; then
      git checkout "$UP" -- "$path"
    elif git ls-files --error-unmatch -- "$path" >/dev/null 2>&1; then
      git rm -q -- "$path"
    fi
  fi
done

# Re-resolve the lock when the upstream's requirements moved (or it was stale).
# The smallest change that satisfies them, in widening passes: only the
# packages whose requirement changed; then those with their dependencies; the
# whole baseline only as a last resort. A first version of this script moved
# eleven packages, drupal/core-dev to a beta among them, for a one-line
# baseline change. Lock only: what the image installs is the lock.
if ! lock_fresh; then
  mapfile -t moved < <(php -r '
    $want = json_decode(file_get_contents("upstream-configuration/composer.json"), TRUE)["require"] ?? [];
    $had = [];
    foreach (json_decode(file_get_contents("composer.lock"), TRUE)["packages"] ?? [] as $p) {
      if ($p["name"] === "ophiocus/upstream-configuration") { $had = $p["require"] ?? []; }
    }
    foreach ($want as $n => $c) {
      if (($had[$n] ?? NULL) !== $c && str_contains($n, "/")) { echo $n, PHP_EOL; }
    }')
  echo "baseline requirements changed: ${moved[*]:-none}"
  upd() { $COMPOSER_CMD update "$@" --no-install --no-scripts --no-interaction || echo "pass did not resolve: $*"; }
  upd ophiocus/upstream-configuration "${moved[@]}"
  lock_fresh || upd ophiocus/upstream-configuration "${moved[@]}" --with-dependencies
  lock_fresh || upd ophiocus/upstream-configuration --with-all-dependencies
  lock_fresh || { echo "the lock cannot meet upstream-configuration; resolve by hand" >&2; exit 1; }
  git add composer.lock
fi

# drupal/core-dev is the site's dev tooling (root require-dev); it tracks
# core's exact version, never a newer minor or a beta. Checked on every sync,
# not only when the baseline moved: a lock can drift on its own. Its own
# dependencies (coder, phpstan…) may move with it; none reach the image.
if ! core_dev_aligned; then
  core="$(php -r 'foreach (json_decode(file_get_contents("composer.lock"), TRUE)["packages"] as $p) if ($p["name"] === "drupal/core") echo $p["version"];')"
  echo "drupal/core-dev -> $core (core's version)"
  $COMPOSER_CMD update drupal/core-dev --with "drupal/core-dev:$core" --with-all-dependencies \
    --no-install --no-scripts --no-interaction
  git add composer.lock
fi

if git diff --cached --quiet && ! git rev-parse -q --verify MERGE_HEAD >/dev/null; then
  out "changed=false"; exit 0
fi

{
  echo "upstream: sync to ${UP:0:7}"
  echo
  echo "Paths the upstream owns (upstream/OWNERSHIP), taken as of $UP:"
  git diff --cached --stat HEAD -- "${OWNED[@]}" | sed 's/^/  /'
  if ! git diff --cached --quiet HEAD -- composer.lock; then
    echo
    echo "composer.lock re-resolved against upstream-configuration."
  fi
} > "$(git rev-parse --git-dir)/UPSTREAM_MSG"
git commit -q -F "$(git rev-parse --git-dir)/UPSTREAM_MSG"
rm -f "$(git rev-parse --git-dir)/UPSTREAM_MSG"
out "changed=true"
git log --oneline -1
