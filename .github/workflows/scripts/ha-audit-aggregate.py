#!/usr/bin/env python3
"""
ha-audit-aggregate.py — invoked by `.github/workflows/maintenance-ha-integrity-audit.yml`.

Reads the per-hymn `_integrity-check.md` report the SDAHymnal scraper
just emitted (under `.SourceSongData/Himnario Adventista [HA]/`),
categorises each "differs" entry, and writes three files:

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
import os
import re
import sys

REPORT_DIR  = ".SourceSongData/Himnario Adventista [HA]"
REPORT_PATH = os.path.join(REPORT_DIR, "_integrity-check.md")


def main() -> int:
    if not os.path.exists(REPORT_PATH):
        print(
            f"::error::Expected per-hymn report at {REPORT_PATH} but it doesn't "
            f"exist. Did the scraper write to a different folder?",
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
        "The full per-hymn diff report is at `.SourceSongData/Himnario Adventista [HA]/_integrity-check.md` on the runner — gitignored, so not included here. To regenerate locally, run:",
        "",
        "```sh",
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
