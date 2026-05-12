#!/usr/bin/env bash
# iHymns — Schema audit guardrails (local convenience wrapper)
#
# Runs the two CI guards that protect against the migration-counter +
# schema.sql drift regression classes documented in CLAUDE.md item 19:
#
#   1. tests/php/test-migration-registry.php
#      - every $migrationOrder slug has a matching $migrationProbes entry
#      - no probe is hardcoded `static fn(\mysqli $db) => true`
#
#   2. tests/php/test-schema-coverage.php
#      - every migration-created table is declared in appWeb/.sql/schema.sql
#      - every migration-added column is declared in the same table block
#
# Both run against the source tree only — no DB connection required, no
# composer install, no env vars. Run before pushing a PR that touches
# any migrate-*.php, schema.sql, or setup-database.php.
#
# Exit status 0 = both pass, 1 = at least one failure (matches the
# behaviour CI will use to fail the build).

set -euo pipefail

# Resolve repo root from this script's location so it works whether
# called from anywhere.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &>/dev/null && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." &>/dev/null && pwd)"

cd "$REPO_ROOT"

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php not found on PATH" >&2
    exit 1
fi

failures=0

echo "==> Migration registry sanity"
if ! php tests/php/test-migration-registry.php; then
    failures=$((failures + 1))
fi

echo
echo "==> Schema-coverage drift guard"
if ! php tests/php/test-schema-coverage.php; then
    failures=$((failures + 1))
fi

echo
if [ "$failures" -gt 0 ]; then
    echo "FAIL: $failures audit guard(s) failed. CI will fail too." >&2
    exit 1
fi

echo "All schema audit guards passed."
