#!/usr/bin/env bash
# The test gate: every check a push must pass before its image is built, and
# so before anything is deployed. The same script runs in CI (the `test` job
# of build-and-push.yml, which `build` needs) and by hand in DDEV:
#
#   ddev exec bash scripts/ci/tests.sh            # every gate
#   ddev exec bash scripts/ci/tests.sh unit       # one or more gates by name
#
# Gates, in order (cheap first; all run, so one failure does not hide another):
#   integrity   no merge-conflict marker in any tracked file, and every YAML
#               file in config/sync and custom code parses. The suites below
#               install from config/install, so a broken config/sync file
#               would otherwise pass them all and fail only at `drush cim`
#   phpcs       Drupal + DrupalPractice over custom code (phpcs.xml.dist)
#   phpstan     static analysis over custom code (phpstan.neon)
#   unit        PHPUnit Unit suites of every custom module, submodule, theme
#   kernel      PHPUnit Kernel suites (needs SIMPLETEST_DB)
#   functional  PHPUnit Functional suites (needs SIMPLETEST_DB and a web
#               server answering SIMPLETEST_BASE_URL)
#   js          `npm test` in every custom package.json that defines one
#
# Suites are found by directory, so a new module's tests join the gate by
# existing. A gate with nothing to run passes and says so.
#
# Env (defaults are DDEV's): SIMPLETEST_DB (mysql://db:db@db/db),
# SIMPLETEST_BASE_URL (http://localhost), BROWSERTEST_OUTPUT_DIRECTORY (/tmp).
set -uo pipefail
shopt -s nullglob

cd "$(dirname "$0")/../.." || exit 1

export SIMPLETEST_DB="${SIMPLETEST_DB:-mysql://db:db@db/db}"
export SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://localhost}"
export BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-/tmp}"

ALL=(integrity phpcs phpstan unit kernel functional js)
WANT=("$@")
[ ${#WANT[@]} -eq 0 ] && WANT=("${ALL[@]}")

# In GitHub Actions each gate folds into a group; elsewhere the markers are
# plain lines.
open_group() { [ -n "${GITHUB_ACTIONS:-}" ] && echo "::group::$1" || echo "== $1"; }
close_group() { [ -n "${GITHUB_ACTIONS:-}" ] && echo "::endgroup::"; return 0; }

phpunit_suite() {
  local type="$1"
  # The suite's directories across custom code. An array, not printf: with
  # no match, printf would still print one empty line and PHPUnit would be
  # handed "" as a directory.
  local dirs=(
    web/modules/custom/*/tests/src/"$type"
    web/modules/custom/*/modules/*/tests/src/"$type"
    web/themes/custom/*/tests/src/"$type"
  )
  if [ ${#dirs[@]} -eq 0 ]; then
    echo "no $type tests in custom code"
    return 0
  fi
  # Core's configuration: its bootstrap and extensions are what Drupal's own
  # base test classes expect.
  vendor/bin/phpunit -c web/core "${dirs[@]}"
}

integrity() {
  local ok=0 files markers yaml
  # Tracked files when git can see the repository; inside a container that
  # holds only a worktree's files it cannot, and the project's own
  # directories are searched instead.
  if files="$(git ls-files 2>/dev/null)" && [ -n "$files" ]; then
    markers="$(printf '%s\n' "$files" | grep -v -E '^(vendor|web/core|web/(modules|themes|profiles)/contrib)/' \
      | tr '\n' '\0' | xargs -0 grep -I -n -E '^(<<<<<<<|>>>>>>>)( |$)' 2>/dev/null)"
  else
    markers="$(grep -r -I -n -E '^(<<<<<<<|>>>>>>>)( |$)' \
      --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
      --exclude-dir=core --exclude-dir=contrib --exclude-dir=files --exclude-dir=simpletest . 2>/dev/null)"
  fi
  if [ -n "$markers" ]; then
    echo "merge-conflict markers:"
    echo "$markers"
    ok=1
  else
    echo "no merge-conflict markers"
  fi
  yaml=(config/sync/*.yml web/modules/custom/*.yml web/modules/custom/*/*.yml
        web/modules/custom/*/config/*/*.yml web/modules/custom/*/modules/*/*.yml
        web/modules/custom/*/modules/*/config/*/*.yml
        web/themes/custom/*/*.yml web/themes/custom/*/config/*/*.yml)
  php scripts/ci/yaml_lint.php "${yaml[@]}" || ok=1
  return $ok
}

js_tests() {
  local pkg dir ran=0
  for pkg in web/modules/custom/*/package.json web/modules/custom/*/*/package.json \
             web/themes/custom/*/package.json web/themes/custom/*/*/package.json; do
    case "$pkg" in */node_modules/*) continue ;; esac
    grep -q '"test"[[:space:]]*:' "$pkg" || continue
    dir="$(dirname "$pkg")"
    echo "-- $dir"
    (cd "$dir" && npm ci --no-audit --no-fund --loglevel=error && npm test) || return 1
    ran=1
  done
  [ $ran -eq 1 ] || echo "no package.json with a test script in custom code"
  return 0
}

run_gate() {
  case "$1" in
    integrity)  integrity ;;
    phpcs)     vendor/bin/phpcs ;;
    phpstan)    vendor/bin/phpstan analyse --no-progress --memory-limit=1G ;;
    unit)       phpunit_suite Unit ;;
    kernel)     phpunit_suite Kernel ;;
    functional) phpunit_suite Functional ;;
    js)         js_tests ;;
    *)          echo "unknown gate: $1 (known: ${ALL[*]})"; return 2 ;;
  esac
}

failed=()
for gate in "${WANT[@]}"; do
  open_group "$gate"
  if run_gate "$gate"; then
    close_group
    echo "PASS $gate"
  else
    close_group
    echo "FAIL $gate"
    failed+=("$gate")
  fi
done

if [ ${#failed[@]} -gt 0 ]; then
  echo "Test gate FAILED: ${failed[*]}"
  exit 1
fi
echo "Test gate passed: ${WANT[*]}"
