#!/usr/bin/env bash
# Release contract, step "audit" (docs/DEPLOY.md §4a). Both performers run this exact file from the
# commit being released: GitHub Actions (build-and-push.yml) and the VPS runner (tec-run).
#
# Audits composer.lock alone (--locked: no install, no PHP extensions needed), production deps only
# (--no-dev: test tooling never reaches the image). An advisory fails the release; an abandoned
# package only warns. The day an advisory lands against a shared runtime dep, every property's next
# release stops here; the fix is a floor in the upstream (docs/UPSTREAM.md), never a per-site bump.
#
#   bash scripts/ci/release-audit.sh          from the repository root
set -euo pipefail
cd "$(dirname "$0")/../.."
exec composer audit --no-dev --abandoned=report --format=plain --locked
