#!/bin/bash
#
# Prune remote branches whose corresponding PR has already been merged.
#
# Background: from PR ~#737 onwards, auto-merge-alpha.yml stopped
# deleting head branches because `gh pr merge --auto --squash` doesn't
# propagate the repo-level delete_branch_on_merge setting (#750). The
# fix in that PR adds --delete-branch so future merges clean up after
# themselves; this script clears the existing backlog of merged-but-
# undeleted branches.
#
# What it does:
#   1. Lists every remote branch under refs/heads/.
#   2. For each, asks GitHub whether a merged PR exists with that
#      branch as its head ref.
#   3. If yes, deletes the remote branch.
#
# What it never deletes:
#   - main / alpha / beta — explicitly skipped, also blocked by the
#     "Keep core branches" ruleset on the repo.
#   - Branches whose PR is open or closed-without-merge.
#   - Branches with no PR.
#
# Usage:
#   tools/prune-merged-branches.sh             # dry-run (default)
#   tools/prune-merged-branches.sh --apply     # actually delete
#
# Requires: gh CLI authenticated against MWBMPartners/iHymns.

set -uo pipefail

REPO="${REPO:-MWBMPartners/iHymns}"
APPLY=0
if [[ "${1:-}" == "--apply" ]]; then
    APPLY=1
fi

PROTECTED=("main" "alpha" "beta")
is_protected() {
    local b="$1"
    for p in "${PROTECTED[@]}"; do
        [[ "$b" == "$p" ]] && return 0
    done
    return 1
}

# Fetch the live remote branch list. `git ls-remote --heads` is the
# authoritative source; output shape is `<sha>\trefs/heads/<branch>`.
# We read line-by-line into an array via a portable while-read loop
# rather than `mapfile`, since macOS still ships bash 3.2 by default.
echo "[info] Fetching remote branch list…" >&2
BRANCHES=()
while IFS= read -r line; do
    [[ -n "$line" ]] && BRANCHES+=("$line")
done < <(git ls-remote --heads "https://github.com/${REPO}.git" \
    | awk '{sub(/^refs\/heads\//,"",$2); print $2}' | sort)

echo "[info] ${#BRANCHES[@]} remote branch(es)." >&2
echo "[info] Mode: $([[ $APPLY -eq 1 ]] && echo APPLY || echo DRY-RUN)" >&2
echo >&2

deleted=0
skipped_protected=0
skipped_no_pr=0
skipped_open=0
errors=0

for b in "${BRANCHES[@]}"; do
    if is_protected "$b"; then
        skipped_protected=$((skipped_protected + 1))
        continue
    fi

    # Lookup the PR (if any) that has this branch as head. We accept
    # the most recent matching PR — if a branch has multiple closed
    # PRs against it, the latest state wins.
    state=$(gh pr list --repo "$REPO" --state all --head "$b" \
        --json state,mergedAt --jq 'sort_by(.mergedAt) | last | .state // ""' 2>/dev/null)

    case "$state" in
        MERGED)
            if [[ $APPLY -eq 1 ]]; then
                if gh api -X DELETE "repos/${REPO}/git/refs/heads/${b}" >/dev/null 2>&1; then
                    printf "[del]  %s\n" "$b"
                    deleted=$((deleted + 1))
                else
                    printf "[err]  %s — DELETE failed\n" "$b"
                    errors=$((errors + 1))
                fi
            else
                printf "[plan] %s — merged PR, would delete\n" "$b"
                deleted=$((deleted + 1))
            fi
            ;;
        OPEN)
            skipped_open=$((skipped_open + 1))
            ;;
        CLOSED|"")
            # CLOSED-without-merge: leave alone — the curator might
            # be planning to reopen / cherry-pick. Empty state means
            # no PR ever existed against this branch (e.g. a draft
            # branch with no PR yet).
            skipped_no_pr=$((skipped_no_pr + 1))
            ;;
    esac
done

echo >&2
echo "[done] Summary:" >&2
echo "  $deleted branch(es) $([[ $APPLY -eq 1 ]] && echo deleted || echo would be deleted)" >&2
echo "  $skipped_protected protected branch(es) skipped (main/alpha/beta)" >&2
echo "  $skipped_open branch(es) with open PR — left alone" >&2
echo "  $skipped_no_pr branch(es) with no merged PR — left alone" >&2
if [[ $errors -gt 0 ]]; then
    echo "  $errors delete error(s) — see [err] lines above" >&2
fi

if [[ $APPLY -eq 0 ]]; then
    echo >&2
    echo "[hint] Re-run with --apply to actually delete." >&2
fi
