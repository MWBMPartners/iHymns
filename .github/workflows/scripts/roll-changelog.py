#!/usr/bin/env python3
# =============================================================================
# iHymns — CHANGELOG "unreleased -> released" rollover (#1899)
#
# Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
# This software is proprietary. Unauthorized copying, modification, or
# distribution is strictly prohibited.
#
# PURPOSE
# -------
# ELI5: when we cut a production release, the pile of notes we had parked under
# "unreleased" now belongs to that release. This script gives that pile the
# release's name and re-opens a fresh empty "unreleased" heading above it — but
# only for the notes that were ACTUALLY released, leaving anything newer (work
# that landed on alpha after the release was branched) still parked as
# unreleased for the NEXT release.
#
# WHY A SCRIPT (not the old awk one-liner). This logic used to live inline in
# version-bump.yml (retired in #1899). The release scheme needs it to do MORE
# than the old rollover: it must SPLIT the unreleased section — entries that
# match the released set move under the version heading, alpha-only entries
# stay unreleased — so a partial promotion never mislabels in-flight alpha work
# as shipped. That is past the comfortable size of an awk program embedded in
# YAML, so it moves here (beside ha-audit-aggregate.py) as pure-stdlib Python.
# CLAUDE.md rule #35: the release TAG is the single agreement point; this
# script only ever PARSES the version string the bridge hands it, never counts.
#
# TWO SUBCOMMANDS
# ---------------
#   extract <changelog> <out>
#       Find the ONE line byte-equal to the verbatim heading
#       `## [unreleased] — alpha` (case-sensitive, em-dash U+2014 — historical
#       `## [Unreleased]` headings must NOT match), write the section body
#       (the `- ` entries) up to the next `## ` heading to <out>.
#       Exit 3 (guard-skip) if the heading is absent, appears more than once,
#       or the section has zero top-level `- ` entries.
#
#   roll <changelog> <released> <version>
#       Rewrite <changelog> in place: keep `## [unreleased] — alpha`, keep only
#       the alpha entries whose normalised bytes match NO entry in <released>,
#       then a blank line and `## [<version>] — <UTC yyyy-mm-dd>` followed by
#       the <released> content verbatim, then the rest of the changelog.
#
# ENTRY UNIT (same as deploy.yml's What's-New awk + the old version-bump step):
#   a line starting `- ` plus its continuation lines (indented sub-bullets,
#   wrapped prose, blank lines) until the next `- ` or `## `. Entries are
#   compared after stripping trailing whitespace/blank lines.
#
# EXIT CODES: 0 = done (file changed), 3 = guard-skip (no change; caller warns
# and carries on), 1 = real error. The bridge treats ANY non-zero as
# "don't commit", so 1 vs 3 is a diagnostic distinction, not a control one.
#
# ⚠️ HISTORICAL `## [1.0.0]` COLLISION (baseline (a), #1899 §2). CHANGELOG.md
# already carries a DEAD-scheme `## [1.0.0] — 2026-04-06` heading. The new
# scheme's first release is ALSO v1.0.0, so a bare `## [1.0.0]` substring test
# would (wrongly) see "this version already exists" and skip, and a bare
# "exactly one `## [1.0.0]` heading" post-condition would (wrongly) count two.
# So the re-run guard and the version-heading post-condition are both
# DATE-QUALIFIED (the exact `## [<version>] — <today-UTC>` line, which differs
# from the 2026-04-06 historical), and the heading-count post-condition is a
# DELTA (`after == before + 1`), never an absolute count of a version string.
# That makes the rollover correct even with the historical heading present.
# (The separate release.yml notes-extraction collision for v1.0.0 is a known
# one-time first-release cosmetic issue — see release.yml's header + #1899.)
#
# Documented, accepted limitation: an entry EDITED on alpha after the release
# was branched won't byte-match the released copy, so it stays unreleased and
# reappears (in edited form) under the next release — self-limiting duplication,
# deliberately preferred over fuzzy matching on the production release path.
#
# Covered by tests/test-changelog-rollover.js (spawns this script against
# tempdir fixtures; mutation-proven).
# https://docs.python.org/3/library/datetime.html
# =============================================================================

import datetime
import os
import sys
import tempfile

# The verbatim, case-sensitive unreleased heading. The em-dash is U+2014; kept
# as a literal in this UTF-8 source so it travels as opaque bytes.
UNRELEASED_HEADING = "## [unreleased] — alpha"

# Exit codes (see header).
EXIT_OK = 0
EXIT_ERROR = 1
EXIT_SKIP = 3


def _read_lines(path):
    """Read a UTF-8 text file into a list of newline-free lines.

    Split on '\n' and drop a trailing '\r' per line so a CRLF checkout is
    handled identically to LF. A trailing empty element from a final newline
    is preserved here and normalised away on write.
    """
    with open(path, "r", encoding="utf-8") as fh:
        content = fh.read()
    return [ln[:-1] if ln.endswith("\r") else ln for ln in content.split("\n")]


def _write_text(path, lines):
    """Write lines back as UTF-8 with a single trailing newline, atomically."""
    body = "\n".join(lines).rstrip("\n") + "\n"
    directory = os.path.dirname(os.path.abspath(path))
    fd, tmp = tempfile.mkstemp(dir=directory, suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(body)
        os.replace(tmp, path)
    except BaseException:
        # Never leave a temp file behind on failure.
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def _parse_entries(lines):
    """Split a body (list of lines) into entries.

    An entry begins at a `- ` line and runs until the next `- ` or `## ` or the
    end. Continuation lines (indentation, wrapped prose, blanks) belong to the
    entry in hand. Content before the first `- ` (e.g. the blank line after a
    heading) is dropped, matching the old rollover.
    """
    entries = []
    cur = None
    for ln in lines:
        if ln.startswith("## "):
            break
        if ln.startswith("- "):
            if cur is not None:
                entries.append(cur)
            cur = [ln]
        elif cur is not None:
            cur.append(ln)
    if cur is not None:
        entries.append(cur)
    return entries


def _norm_entry(entry_lines):
    """Normalise an entry for comparison: strip trailing whitespace/blanks."""
    return "\n".join(entry_lines).rstrip()


def _render_entries(entries):
    """Flatten entries back to lines, trimming each entry's trailing blanks."""
    out = []
    for entry in entries:
        trimmed = list(entry)
        while trimmed and trimmed[-1].strip() == "":
            trimmed.pop()
        out.extend(trimmed)
    return out


def _find_unreleased(lines):
    """Return the index of the sole verbatim unreleased heading, or None."""
    idxs = [i for i, ln in enumerate(lines) if ln == UNRELEASED_HEADING]
    if len(idxs) != 1:
        return None
    return idxs[0]


def _next_heading(lines, start):
    """Index of the next `## ` heading at/after `start`, else len(lines)."""
    for i in range(start, len(lines)):
        if lines[i].startswith("## "):
            return i
    return len(lines)


def cmd_extract(changelog_path, out_path):
    lines = _read_lines(changelog_path)
    u = _find_unreleased(lines)
    if u is None:
        sys.stderr.write(
            "extract: expected exactly one verbatim '%s' heading — none/many found.\n"
            % UNRELEASED_HEADING
        )
        return EXIT_SKIP
    body = lines[u + 1 : _next_heading(lines, u + 1)]
    entries = _parse_entries(body)
    if not entries:
        sys.stderr.write("extract: unreleased section has zero '- ' entries — nothing to roll.\n")
        return EXIT_SKIP
    _write_text(out_path, _render_entries(entries))
    sys.stderr.write("extract: wrote %d released entr%s to %s\n"
                     % (len(entries), "y" if len(entries) == 1 else "ies", out_path))
    return EXIT_OK


def cmd_roll(changelog_path, released_path, version):
    lines = _read_lines(changelog_path)

    # -- Release heading, date-qualified (UTC). See the historical-collision
    #    note in the header: the date is what distinguishes today's v1.0.0 from
    #    the dead-scheme `## [1.0.0] — 2026-04-06`.
    today = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d")
    release_heading = "## [%s] — %s" % (version, today)

    # -- Guard: exactly one verbatim unreleased heading -----------------------
    u = _find_unreleased(lines)
    if u is None:
        sys.stderr.write(
            "roll: expected exactly one verbatim '%s' heading — none/many found.\n"
            % UNRELEASED_HEADING
        )
        return EXIT_SKIP

    # -- Guard: the released set is non-empty (>= 1 entry) --------------------
    with open(released_path, "r", encoding="utf-8") as fh:
        released_text = fh.read()
    released_block = released_text.strip("\n")
    released_lines = [ln[:-1] if ln.endswith("\r") else ln for ln in released_block.split("\n")]
    released_entries = _parse_entries(released_lines)
    if not released_entries:
        sys.stderr.write("roll: released file has zero '- ' entries — nothing to roll.\n")
        return EXIT_SKIP
    released_norm = {_norm_entry(e) for e in released_entries}

    # -- Re-run guard: this exact dated heading already present (belt & braces;
    #    the bridge's points-at guard already stops a genuine re-tag). ---------
    if release_heading in lines:
        sys.stderr.write("roll: '%s' already present — no-op (re-run).\n" % release_heading)
        return EXIT_SKIP

    headings_before = sum(1 for ln in lines if ln.startswith("## "))

    # -- Split alpha's unreleased entries -------------------------------------
    n = _next_heading(lines, u + 1)
    alpha_body = lines[u + 1 : n]
    rest = lines[n:]
    alpha_entries = _parse_entries(alpha_body)
    kept = [e for e in alpha_entries if _norm_entry(e) not in released_norm]
    matched = [e for e in alpha_entries if _norm_entry(e) in released_norm]

    # -- Compose the new file -------------------------------------------------
    out = list(lines[:u])            # anything above the unreleased heading
    out.append(UNRELEASED_HEADING)
    out.append("")
    kept_lines = _render_entries(kept)
    if kept_lines:
        out.extend(kept_lines)
        out.append("")
    out.append(release_heading)
    out.append("")
    out.extend(released_block.split("\n"))
    out.append("")
    out.extend(rest)

    # -- Post-conditions (verified on the composed result, else discard) ------
    unreleased_after = sum(1 for ln in out if ln == UNRELEASED_HEADING)
    release_after = sum(1 for ln in out if ln == release_heading)
    headings_after = sum(1 for ln in out if ln.startswith("## "))
    body_nonempty = any(ln.strip() != "" for ln in out)
    conserved = (len(kept) + len(matched)) == len(alpha_entries)

    if not (
        body_nonempty
        and unreleased_after == 1
        and release_after == 1
        and headings_after == headings_before + 1
        and conserved
    ):
        sys.stderr.write(
            "roll: post-conditions failed (unreleased=%d expect 1, release=%d expect 1, "
            "headings %d->%d expect +1, conserved=%s) — CHANGELOG.md left untouched.\n"
            % (unreleased_after, release_after, headings_before, headings_after, conserved)
        )
        return EXIT_SKIP

    _write_text(changelog_path, out)
    sys.stderr.write(
        "roll: rolled %d released entr%s into '%s'; kept %d alpha-only entr%s unreleased.\n"
        % (len(matched), "y" if len(matched) == 1 else "ies", release_heading,
           len(kept), "y" if len(kept) == 1 else "ies")
    )
    return EXIT_OK


def main(argv):
    if len(argv) >= 4 and argv[1] == "extract" and len(argv) == 4:
        return cmd_extract(argv[2], argv[3])
    if len(argv) == 5 and argv[1] == "roll":
        return cmd_roll(argv[2], argv[3], argv[4])
    sys.stderr.write(
        "usage:\n"
        "  roll-changelog.py extract <changelog> <out>\n"
        "  roll-changelog.py roll <changelog> <released> <version>\n"
    )
    return EXIT_ERROR


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv))
    except FileNotFoundError as exc:
        sys.stderr.write("error: %s\n" % exc)
        sys.exit(EXIT_ERROR)
