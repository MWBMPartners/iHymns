#!/usr/bin/env bash
# install-device-rules.sh — copy the owner's computer-wide AI rules into this computer's
# global instruction files, so they apply in every project, not just iHymns.
#
# In plain words: .claude/device-level-rules.md holds a block of rules between two marker
# lines. This script puts that block into ~/.claude/CLAUDE.md (read by Claude Code in every
# project) and ~/.codex/AGENTS.md (read by Codex in every project). If the block is already
# there, it is replaced with the current version. Nothing else in those files is touched.
# It is safe to run as often as you like.
#
# Why a script and not a note saying "please copy this": a written reminder to keep two
# files in step is exactly what goes stale (project rule #35). Re-running this makes the
# computer's copy match the repo's copy again.
#
# Usage (from anywhere inside the repo):   bash tools/install-device-rules.sh
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_file="${repo_root}/.claude/device-level-rules.md"
begin='<!-- BEGIN DEVICE RULES -->'
end='<!-- END DEVICE RULES -->'

# Pull out just the block, markers included. Fail loudly if the markers are missing, rather
# than quietly installing nothing.
block="$(awk -v b="$begin" -v e="$end" '$0==b{on=1} on{print} $0==e{exit}' "$source_file")"
if [[ -z "$block" || "$block" != *"$end"* ]]; then
  echo "error: could not find the marked block in $source_file" >&2
  exit 1
fi

install_into() {
  local target="$1"
  mkdir -p "$(dirname "$target")"
  touch "$target"
  local tmp blockfile
  # A file saved with Windows line endings would hide the markers from the exact-line checks
  # below, and the script would then add a second copy of the block. Refuse instead.
  if grep -q $'\r' "$target"; then
    echo "error: $target has Windows line endings. Nothing was changed. Convert it, then run this again." >&2
    return 1
  fi
  # Safety check before changing anything. The block is replaced by deleting everything from
  # the BEGIN line to the END line. If the END line were missing (or damaged, e.g. a stray
  # space), that would delete the rest of the file. So we insist on exactly one BEGIN line and
  # exactly one END line, with BEGIN first, or none of either. Otherwise stop and change nothing.
  local nb ne lb le
  nb="$(grep -cxF -- "$begin" "$target" || true)"
  ne="$(grep -cxF -- "$end" "$target" || true)"
  if [[ "$nb" != "$ne" || "$nb" -gt 1 ]]; then
    echo "error: $target has $nb BEGIN and $ne END marker lines (expected 1 and 1, or none)." >&2
    echo "       Nothing was changed. Fix the markers by hand, then run this again." >&2
    return 1
  fi
  if [[ "$nb" -eq 1 ]]; then
    lb="$(grep -nxF -- "$begin" "$target" | cut -d: -f1)"
    le="$(grep -nxF -- "$end" "$target" | cut -d: -f1)"
    if [[ "$lb" -ge "$le" ]]; then
      echo "error: in $target the END marker comes before the BEGIN marker. Nothing was changed." >&2
      return 1
    fi
  fi
  # Temporary files are made only now, after every check has passed, so a refusal leaves
  # nothing behind. The block goes in a file for awk to read: plainer and more portable than
  # feeding it in another way (macOS ships an older bash and a different awk).
  tmp="$(mktemp)"
  blockfile="$(mktemp)"
  printf '%s\n' "$block" > "$blockfile"
  if [[ "$nb" -eq 1 ]]; then
    # Replace the old block in place: copy everything outside the markers, and drop the
    # new block in where the old one began.
    awk -v b="$begin" -v e="$end" -v blockfile="$blockfile" '
      $0==b { while ((getline line < blockfile) > 0) print line; skip=1; next }
      $0==e { skip=0; next }
      !skip { print }
    ' "$target" > "$tmp"
  else
    # First install: keep the file as it is and add the block at the end, after a blank line.
    cat "$target" > "$tmp"
    [[ -s "$target" ]] && printf '\n' >> "$tmp"
    printf '%s\n' "$block" >> "$tmp"
  fi
  # Write back INTO the existing file rather than moving the temporary file over it. That
  # keeps the file's permissions, and if the path is a link (into a dotfiles folder, say)
  # the link stays a link and the real file behind it is updated.
  cat "$tmp" > "$target"
  rm -f "$tmp" "$blockfile"
  echo "installed: $target"
}

# Try both files even if the first one is refused, then report overall failure at the end.
status=0
install_into "${HOME}/.claude/CLAUDE.md" || { status=1; echo "skipped: ${HOME}/.claude/CLAUDE.md" >&2; }
install_into "${HOME}/.codex/AGENTS.md"  || { status=1; echo "skipped: ${HOME}/.codex/AGENTS.md" >&2; }
exit "$status"
