#!/usr/bin/env bash
# tools/sync-claude-session.sh
#
# Copy this project's Claude Code session transcripts from
# ~/.claude/projects/<project-hash>/*.jsonl into .claude/sessions/
# so they can be committed + picked up on another machine via /resume.
#
# Each transcript is run through a best-effort regex scrubber that
# redacts common secret patterns (see REDACTORS below). The scrubbing
# is NOT a replacement for reviewing the diff before you commit — a
# password typed during debugging, a customer email in a test file, a
# DB dump pasted into the prompt — none of those match a known
# token regex and won't be redacted automatically. Always run:
#
#   git diff .claude/sessions/
#
# before committing.
#
# Usage:
#   tools/sync-claude-session.sh             # sync every transcript
#   tools/sync-claude-session.sh --dry-run   # show what would be synced
#   tools/sync-claude-session.sh --latest    # sync only the newest
#
# Designed to run from the repo root.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST_DIR="${REPO_ROOT}/.claude/sessions"

# Claude Code hashes the project path; locate the matching directory.
# Fallback: scan every project dir for a JSONL that references this
# repo path in its first line.
CLAUDE_PROJECTS="${HOME}/.claude/projects"

if [[ ! -d "${CLAUDE_PROJECTS}" ]]; then
    echo "No Claude Code projects directory at ${CLAUDE_PROJECTS}." >&2
    echo "Nothing to sync." >&2
    exit 0
fi

mkdir -p "${DEST_DIR}"

# --- find the project hash dir ---------------------------------------
PROJECT_HASH_DIR=""
# Claude Code's current encoding is to replace `/` with `-` in the path;
# try that first before falling back to content-based matching.
CANDIDATE="${CLAUDE_PROJECTS}/$(echo "${REPO_ROOT}" | sed 's|/|-|g')"
if [[ -d "${CANDIDATE}" ]]; then
    PROJECT_HASH_DIR="${CANDIDATE}"
else
    for d in "${CLAUDE_PROJECTS}"/*/; do
        first_jsonl="$(find "$d" -maxdepth 1 -name '*.jsonl' -print -quit 2>/dev/null)"
        [[ -z "${first_jsonl}" ]] && continue
        if head -n 1 "${first_jsonl}" 2>/dev/null | grep -q -- "${REPO_ROOT}"; then
            PROJECT_HASH_DIR="${d%/}"
            break
        fi
    done
fi

if [[ -z "${PROJECT_HASH_DIR}" ]]; then
    echo "Could not locate a Claude Code transcript dir for this repo." >&2
    echo "Expected: ${CANDIDATE}" >&2
    exit 1
fi

echo "Source: ${PROJECT_HASH_DIR}"
echo "Dest:   ${DEST_DIR}"
echo

# --- pick which files to sync ----------------------------------------
DRY_RUN=0
LATEST_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --dry-run)  DRY_RUN=1 ;;
        --latest)   LATEST_ONLY=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

if [[ $LATEST_ONLY -eq 1 ]]; then
    SOURCE_FILES=("$(ls -t "${PROJECT_HASH_DIR}"/*.jsonl 2>/dev/null | head -n 1)")
else
    mapfile -t SOURCE_FILES < <(ls -t "${PROJECT_HASH_DIR}"/*.jsonl 2>/dev/null)
fi

if [[ ${#SOURCE_FILES[@]} -eq 0 || -z "${SOURCE_FILES[0]:-}" ]]; then
    echo "No JSONL transcripts found in ${PROJECT_HASH_DIR}." >&2
    exit 0
fi

# --- scrubbers -------------------------------------------------------
# sed script applied to every JSONL line. Patterns pulled from the
# published token formats of the major providers plus the generic
# Bearer/private-key cases.
scrub() {
    sed -E \
        -e 's|sk-ant-api[0-9]+-[A-Za-z0-9_-]+|sk-ant-api-REDACTED|g' \
        -e 's|sk-ant-[A-Za-z0-9_-]{20,}|sk-ant-REDACTED|g' \
        -e 's|github_pat_[A-Za-z0-9_]{60,}|github_pat_REDACTED|g' \
        -e 's|gh[pousr]_[A-Za-z0-9]{30,}|gh_REDACTED|g' \
        -e 's|AKIA[0-9A-Z]{16}|AKIA_REDACTED|g' \
        -e 's|AIza[0-9A-Za-z_-]{35}|AIza_REDACTED|g' \
        -e 's|ya29\.[A-Za-z0-9_-]{20,}|ya29_REDACTED|g' \
        -e 's|xox[baprs]-[A-Za-z0-9-]{10,}|xox_SLACK_REDACTED|g' \
        -e 's|(Authorization[\\"]*: *)Bearer [A-Za-z0-9_.-]{20,}|\1Bearer REDACTED|g' \
        -e 's|Bearer [A-Za-z0-9_.-]{40,}|Bearer REDACTED|g' \
        -e 's|-----BEGIN [A-Z ]*PRIVATE KEY-----[^-]*-----END [A-Z ]*PRIVATE KEY-----|-----PRIVATE_KEY_REDACTED-----|g' \
        -e 's|(password[\\"]*[: =][\\"]* *)[^\\"\n ]{4,}|\1REDACTED|gI'
}

# --- run -------------------------------------------------------------
count_copied=0
count_scrubbed_lines=0
for src in "${SOURCE_FILES[@]}"; do
    base="$(basename "${src}")"
    dest="${DEST_DIR}/${base}"

    if [[ $DRY_RUN -eq 1 ]]; then
        echo "would copy: ${base}"
        continue
    fi

    # scrub + write
    scrub < "${src}" > "${dest}.tmp"
    # Count how many lines changed so the operator knows something
    # actually triggered a redaction.
    changed="$(diff -U 0 <(cat "${src}") <(cat "${dest}.tmp") 2>/dev/null \
        | grep -Ec '^[+-][^+-]' || true)"
    mv "${dest}.tmp" "${dest}"
    printf '  synced  %s  (lines changed by scrub: %s)\n' "${base}" "${changed}"
    count_copied=$((count_copied + 1))
    count_scrubbed_lines=$((count_scrubbed_lines + ${changed:-0}))
done

if [[ $DRY_RUN -eq 0 ]]; then
    echo
    echo "Done. Copied ${count_copied} transcript(s); scrub touched ${count_scrubbed_lines} line(s)."
    echo
    echo "Next step:"
    echo "  git diff .claude/sessions/"
    echo "  # review — the scrubber is best-effort, a DB password or"
    echo "  # customer email will NOT be caught. Commit only if clean."
fi
