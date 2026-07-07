#!/usr/bin/env bash
#
# loc-budget.sh — the modularity tripwire (#1395, strategy §3.3.4).
#
# Fails CI if any non-generated Swift file under appApple/ exceeds the per-file
# line budget. This is the native equivalent of the web project's "extract
# first, use second" rule: when a file grows past the budget it is a signal to
# split responsibilities out into the shared iHymnsKit modules rather than let a
# shell target or a feature file accrete logic. Generated / build artefacts are
# excluded.
#
# Usage: Scripts/loc-budget.sh [budget]   (default 400)
set -euo pipefail

BUDGET="${1:-400}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"   # -> appApple/
fail=0

while IFS= read -r f; do
    lines=$(wc -l < "$f" | tr -d ' ')
    if [ "$lines" -gt "$BUDGET" ]; then
        echo "LOC-BUDGET FAIL: ${f#"$ROOT"/} has ${lines} lines (budget ${BUDGET}) — split it into iHymnsKit."
        fail=1
    fi
done < <(find "$ROOT" -name '*.swift' \
            -not -path '*/.build/*' \
            -not -path '*/DerivedData/*' \
            -not -path '*/*.xcodeproj/*')

if [ "$fail" -eq 0 ]; then
    echo "loc-budget OK — every Swift file is within ${BUDGET} lines."
fi
exit "$fail"
