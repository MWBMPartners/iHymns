#!/usr/bin/env python3
"""
ha-audit-aggregate.py — invoked by `.github/workflows/maintenance-ha-integrity-audit.yml`.

Reads the per-hymn `_integrity-check.md` report the SDAHymnal scraper
just emitted — the folder is RESOLVED via ha_audit_paths.py (never
hardcoded here; see that module's doc-block for why a hardcoded guess
is exactly what broke the 2026-07-14 run) — categorises each "differs"
entry, and writes three files:

  - .importers/audits/<YYYY-MM-DD>-ha-full-audit.md   (committable summary)
  - /tmp/commit-msg.txt                                (commit message)
  - /tmp/pr-body.md                                    (PR body)

Plus emits four key=value lines into $GITHUB_OUTPUT so downstream steps
in the workflow can read the headline numbers + the report path.

Lives in this folder rather than inside the workflow's `run:` block
because the report templates contain blank lines, which break YAML's
block-scalar parser when inlined. Keeping the script in a real .py file
sidesteps that entirely — the workflow just calls `python3 path/to/this`.

Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
"""

import datetime
import glob
import os
import re
import sys

# ha_audit_paths.py lives alongside this script and RESOLVES the real
# output path from the scraper's own code (SDAHymnals_SDAHymnal.org.py's
# book_dir_for_site()) rather than us guessing a literal string here — a
# hardcoded guess in this exact spot ("./SourceSongData/Himnario
# Adventista [HA]") is what silently drifted out of sync with the
# scraper's #780 folder-naming change and broke the 2026-07-14 run. See
# ha_audit_paths.py's module doc-block for the full incident writeup.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import ha_audit_paths  # noqa: E402  (must follow the sys.path insert above)

OUTPUT_BASE = ".SourceSongData"
REPORT_DIR  = ha_audit_paths.sdahymnal_ha_book_dir(OUTPUT_BASE)
REPORT_PATH = os.path.join(REPORT_DIR, "_integrity-check.md")


def main() -> int:
    if not os.path.exists(REPORT_PATH):
        # Actionable, not just "it's missing": name BOTH the path we
        # resolved (so a reader can tell this isn't a hardcoded guess —
        # it came from the scraper's own book_dir_for_site()) and what
        # actually exists on the runner right now, so a curator doesn't
        # have to re-run the whole workflow just to see the real layout.
        existing = sorted(
            p for p in glob.glob(os.path.join(OUTPUT_BASE, "*")) if os.path.isdir(p)
        )
        print(
            f"::error::Expected per-hymn report at {REPORT_PATH} but it doesn't "
            f"exist.\n"
            f"That path was resolved from the scraper's own "
            f"book_dir_for_site('ha', {OUTPUT_BASE!r}) — see "
            f".github/workflows/scripts/ha_audit_paths.py — so this is NOT a "
            f"hardcoded-path mismatch (that class of bug was fixed here). The "
            f"likely cause now is that the SDAHymnal --prefer-source cis scan "
            f"found ZERO existing hymn files to compare against (so "
            f"_write_integrity_report() was never called) — check the "
            f"'Pre-fetch the ChristInSong HA extract' step's log for whether "
            f"the relocation into this folder actually happened, and whether "
            f"upstream (himnario.net or the ChristInSong dataset) is rate-"
            f"limiting or has changed shape.\n"
            f"Directories that DO exist under {OUTPUT_BASE}/ on this runner: "
            f"{existing or '(none — .SourceSongData is empty or missing)'}",
            file=sys.stderr,
        )
        return 1

    body = open(REPORT_PATH, encoding="utf-8").read()
    entries = re.findall(
        r'### (\d+) — ([^()]+) \((identical|differs)\)'
        r'(?:.*?```diff\n(.*?)```)?',
        body, re.DOTALL,
    )

    identical = sum(1 for _, _, s, _ in entries if s == "identical")
    differs   = sum(1 for _, _, s, _ in entries if s == "differs")
    total     = identical + differs

    # Categorise the differs by diff content. Heuristics match the
    # 2026-04-30 manual analysis — see
    # `.importers/audits/2026-04-30-cross-source-integrity.md` for
    # the rationale per category.
    cat_struct = cat_enc = cat_ocr = cat_other = 0
    for _, _, status, diff_block in entries:
        if status != "differs" or not diff_block:
            continue
        has_enc    = bool(re.search(r"[ăŕćśľ]", diff_block))
        has_ocr    = bool(re.search(r"^-\s*0\s", diff_block, re.MULTILINE))
        has_struct = (
            diff_block.count("-coro") >= 1
            or "coro:" in diff_block
            or diff_block.count("-chorus") >= 1
        )
        if has_enc:
            cat_enc += 1
        elif has_ocr:
            cat_ocr += 1
        elif has_struct:
            cat_struct += 1
        else:
            cat_other += 1

    today = datetime.date.today().isoformat()
    repo  = os.environ.get("GITHUB_REPOSITORY", "MWBMPartners/iHymns")

    # --- Build the committable audit report (markdown) ---
    pct = lambda n, d: f"{(n * 100 / d):.1f}%" if d else "—"
    lines = [
        f"# HA cross-source integrity audit — {today}",
        "",
        f"**Tracking issue:** [#699](https://github.com/{repo}/issues/699)",
        "**Tooling:** `.importers/scrapers/SDAHymnals_SDAHymnal.org.py --site ha --prefer-source cis`",
        "**Triggered by:** `.github/workflows/maintenance-ha-integrity-audit.yml`",
        "",
        "## Headline numbers",
        "",
        "| Metric | Count |",
        "|---|---:|",
        f"| Total hymns audited | **{total}** |",
        f"| Identical | **{identical}** ({pct(identical, total)}) |",
        f"| Differ | **{differs}** ({pct(differs, total)}) |",
        "",
        f"## Categorisation of the {differs} differing hymns",
        "",
        "| Category | Count | % of differs |",
        "|---|---:|---:|",
        f"| Structural only (chorus repetition / colon punctuation) | {cat_struct} | {pct(cat_struct, differs)} |",
        f"| Encoding corruption (Latin-1 → Latin-2 mojibake in CIS) | {cat_enc} | {pct(cat_enc, differs)} |",
        f"| OCR-style errors (digit `0` for letter `Ó`) | {cat_ocr} | {pct(cat_ocr, differs)} |",
        f"| Other / mixed (likely title mismatches → different editions) | {cat_other} | {pct(cat_other, differs)} |",
        "",
        "## Interpretation",
        "",
        "- **Structural-only differences** are layout-only — same lyric, same hymn, different convention for representing the chorus. No action needed.",
        "- **Encoding + OCR errors** indicate ChristInSong is the corrupted source for the affected hymns; SDAHymnal would be the cleaner re-import target.",
        "- **Other / title-mismatch** suggests the two sources are different *editions* of the Spanish hymnal. If this category is large, the two sources may be complementary rather than redundant — keep both and cross-reference rather than choosing one.",
        "",
        "## Per-hymn detail",
        "",
        f"The full per-hymn diff report is at `{REPORT_PATH}` on the runner — gitignored, so not included here. To regenerate locally, run:",
        "",
        "```sh",
        "python3 .importers/scrapers/ChristInSong.app.py \\",
        "    --hymnal es --output .SourceSongData --delay 1.0",
        "# then relocate its output to match book_dir_for_site('ha', ...) —",
        "# see .github/workflows/scripts/ha_audit_paths.py — before running:",
        "python3 .importers/scrapers/SDAHymnals_SDAHymnal.org.py \\",
        "    --site ha --prefer-source cis --output .SourceSongData --delay 1.0",
        "```",
        "",
    ]
    os.makedirs(".importers/audits", exist_ok=True)
    out_path = f".importers/audits/{today}-ha-full-audit.md"
    with open(out_path, "w", encoding="utf-8") as f:
        f.write("\n".join(lines))

    # --- Build the commit message ---
    commit_lines = [
        f"docs(audit): HA cross-source integrity findings ({today}) (refs #699)",
        "",
        "Auto-generated by .github/workflows/maintenance-ha-integrity-audit.yml.",
        "",
        "Headline:",
        f"- Total audited: {total}",
        f"- Identical:     {identical}",
        f"- Differ:        {differs}",
        "",
        "Full breakdown + categorisation in the committed report.",
        "",
        "Co-Authored-By: github-actions[bot] <noreply@github.com>",
    ]
    with open("/tmp/commit-msg.txt", "w", encoding="utf-8") as f:
        f.write("\n".join(commit_lines))

    # --- Build the PR body ---
    pr_lines = [
        "Auto-generated monthly audit (refs #699).",
        "",
        "## Headline",
        "",
        "| Metric | Count |",
        "|---|---:|",
        f"| Total audited | {total} |",
        f"| Identical | {identical} ({pct(identical, total)}) |",
        f"| Differ | {differs} ({pct(differs, total)}) |",
        "",
        "## Categorisation",
        "",
        "| Category | Count |",
        "|---|---:|",
        f"| Structural only | {cat_struct} |",
        f"| Encoding corruption | {cat_enc} |",
        f"| OCR-style | {cat_ocr} |",
        f"| Other / title-mismatch | {cat_other} |",
        "",
        f"Full report committed at `{out_path}`.",
        "",
        "Triggered by the monthly cron in `.github/workflows/maintenance-ha-integrity-audit.yml`.",
    ]
    with open("/tmp/pr-body.md", "w", encoding="utf-8") as f:
        f.write("\n".join(pr_lines))

    # --- Surface to the workflow ---
    with open(os.environ["GITHUB_OUTPUT"], "a") as f:
        f.write(f"report_path={out_path}\n")
        f.write(f"total={total}\n")
        f.write(f"identical={identical}\n")
        f.write(f"differs={differs}\n")
        f.write(f"date={today}\n")

    print(f"Audit aggregation done. {total} compared, {identical} identical, {differs} differ.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
