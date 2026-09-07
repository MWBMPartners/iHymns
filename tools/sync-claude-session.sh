#!/usr/bin/env bash
# tools/sync-claude-session.sh
#
# Move Claude Code conversation logs between the folder Claude Code uses and
# this project's .claude/sessions/ folder, in EITHER direction.
#
# ---------------------------------------------------------------------------
# Read this first: these logs are no longer committed
# ---------------------------------------------------------------------------
#
# As of 2026-09-07 (#2096) the raw .jsonl logs are in .gitignore. The reason is
# simple and worth understanding, because it explains what this script is now
# for.
#
# Claude Code looks for a conversation in a folder named after THE FULL PATH OF
# THE PROJECT ON THAT PARTICULAR MACHINE. For this repository on this computer
# that folder is:
#
#   ~/.claude/projects/-Users-lance-manasse-Projects-...-iHymns
#
# The name is the absolute path with every character that is not a letter or a
# number replaced by a dash. So on a different computer, a different user
# account, or even the same computer with the project in a different folder,
# the name is different and Claude Code looks somewhere else entirely.
#
# That is why committing the logs never worked. A log sitting in the repository
# is in the wrong place by definition, and until now this script only ever
# copied ONE way — into the repo, never back out. Twelve logs totalling 162 MB
# were carried in every clone and never once read by anything.
#
# What DOES carry work across machines is the hand-written .claude/sessions/
# <date>-HANDOFF.md notes. Ten of those come to about 224 KB, they are plain
# text, and every .claude/ document already points at them. Write one of those.
#
# ---------------------------------------------------------------------------
# What this script is for now
# ---------------------------------------------------------------------------
#
#   export   Take a copy of a live conversation log, scrubbed of anything that
#            looks like a password or key, and put it in .claude/sessions/ so
#            you can archive it, inspect it, or hand it to somebody
#            deliberately. It will NOT be committed; git ignores it.
#
#   restore  The half that was always missing. Take a .jsonl file and put it
#            where Claude Code on THIS machine will actually find it, working
#            the folder name out from this repository's own location. This is
#            what makes carrying a conversation to another computer possible at
#            all — copy the file across by any means you like (a USB stick,
#            a file transfer, a cloud drive), then run restore there.
#
# ---------------------------------------------------------------------------
# The scrubber is best-effort. Please read the diff.
# ---------------------------------------------------------------------------
#
# It redacts things that MATCH A KNOWN PATTERN: Anthropic keys, GitHub tokens,
# AWS keys, "Bearer ..." headers, private-key blocks. It cannot catch a password
# you typed during debugging, a customer email in a test file, or a database
# dump pasted into a prompt, because none of those look like anything in
# particular. If you are about to share a log with somebody, read it first.
#
# Usage:
#   tools/sync-claude-session.sh                 # export every log
#   tools/sync-claude-session.sh --dry-run       # show what export would do
#   tools/sync-claude-session.sh --latest        # export only the newest
#   tools/sync-claude-session.sh --restore FILE  # put a log back where Claude
#                                                # Code on this machine finds it
#   tools/sync-claude-session.sh --where         # print that folder and stop
#
# Designed to run from anywhere; it works out the repository root itself.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST_DIR="${REPO_ROOT}/.claude/sessions"

# The folder Claude Code uses for THIS repository on THIS machine. The rule is
# the absolute path with every character that is not a letter or a number turned
# into a dash. That was confirmed by comparing this calculation against the real
# folder, not assumed from documentation.
claude_project_dir() {
    printf '%s/.claude/projects/%s' \
        "${HOME}" \
        "$(printf '%s' "${REPO_ROOT}" | sed 's/[^A-Za-z0-9]/-/g')"
}

# --where: just say where that is, and whether it exists yet.
if [[ "${1:-}" == "--where" ]]; then
    dir="$(claude_project_dir)"
    echo "Claude Code looks for this project's conversations in:"
    echo "  ${dir}"
    if [[ -d "${dir}" ]]; then
        echo "  (exists — ${dir}/*.jsonl: $(ls -1 "${dir}"/*.jsonl 2>/dev/null | wc -l | tr -d ' ') file(s))"
    else
        echo "  (does not exist yet — it is created the first time you run Claude Code here)"
    fi
    exit 0
fi

# --restore FILE: copy a log into that folder so Claude Code can actually open
# it. This is the direction that never existed before, and without it a log in
# the repository could never be used on another machine.
if [[ "${1:-}" == "--restore" ]]; then
    src="${2:-}"
    if [[ -z "${src}" ]]; then
        echo "error: --restore needs a file. Try:" >&2
        echo "  tools/sync-claude-session.sh --restore .claude/sessions/<id>.jsonl" >&2
        exit 2
    fi
    if [[ ! -f "${src}" ]]; then
        echo "error: no such file: ${src}" >&2
        exit 2
    fi
    dir="$(claude_project_dir)"
    mkdir -p "${dir}"
    base="$(basename "${src}")"
    if [[ -e "${dir}/${base}" ]]; then
        echo "error: ${dir}/${base} already exists." >&2
        echo "       Refusing to overwrite a conversation you may still need." >&2
        echo "       Move it aside first if you really mean to replace it." >&2
        exit 1
    fi
    cp "${src}" "${dir}/${base}"
    echo "Restored ${base} into:"
    echo "  ${dir}"
    echo
    echo "Next step: start Claude Code in this project and pick it from the"
    echo "conversation list, or use --resume. Note that a very long conversation"
    echo "will be summarised rather than replayed in full."
    exit 0
fi

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
# Claude Code's current encoding normalises every non-alphanumeric path
# character (`/`, `.`, `_`, space, etc.) to `-` so the result is a flat
# hyphen-delimited folder name. Try that first; fall back to a `/`→`-`
# only encoding for older Claude Code installs that didn't normalise
# dots/spaces, then to content-based matching against the first line.
CANDIDATE_NORMALISED="${CLAUDE_PROJECTS}/$(echo "${REPO_ROOT}" | sed 's|[^A-Za-z0-9]|-|g')"
CANDIDATE_LEGACY="${CLAUDE_PROJECTS}/$(echo "${REPO_ROOT}" | sed 's|/|-|g')"
if [[ -d "${CANDIDATE_NORMALISED}" ]]; then
    PROJECT_HASH_DIR="${CANDIDATE_NORMALISED}"
elif [[ -d "${CANDIDATE_LEGACY}" ]]; then
    PROJECT_HASH_DIR="${CANDIDATE_LEGACY}"
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
CANDIDATE="${CANDIDATE_NORMALISED}"

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
    # Portable replacement for `mapfile -t` (bash 4+ only — macOS ships
    # bash 3.2 by default and many devs run the system bash without
    # noticing). Read newline-separated `ls -t` output into an indexed
    # array via a while-loop.
    SOURCE_FILES=()
    while IFS= read -r _line; do
        [[ -n "${_line}" ]] && SOURCE_FILES+=("${_line}")
    done < <(ls -t "${PROJECT_HASH_DIR}"/*.jsonl 2>/dev/null)
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
    echo "These files are NOT committed — git ignores .claude/sessions/*.jsonl,"
    echo "because a log in the repository is in the wrong folder for Claude Code"
    echo "to ever open it. See the notes at the top of this script."
    echo
    echo "To carry a conversation to another computer:"
    echo "  1. copy the .jsonl across by any means you like"
    echo "  2. run this there:  tools/sync-claude-session.sh --restore <file>"
    echo
    echo "To carry the WORK across, which is usually what you actually want,"
    echo "write a note in .claude/sessions/<date>-HANDOFF.md instead. That is"
    echo "what every .claude/ document points at, and it is plain text anyone"
    echo "can read on any machine."
    echo
    echo "Before sharing a log with anyone, read it. The scrubber only catches"
    echo "things that look like a key or a token; a password you typed or a"
    echo "customer email will still be in there."
fi
