# HA cross-source integrity audit — 2026-09-14

**Tracking issue:** [#699](https://github.com/MWBMPartners/iHymns/issues/699)
**Tooling:** `.importers/scrapers/SDAHymnals_SDAHymnal.org.py --site ha --prefer-source cis`
**Triggered by:** `.github/workflows/maintenance-ha-integrity-audit.yml`

## Headline numbers

| Metric | Count |
|---|---:|
| Total hymns audited | **526** |
| Identical | **1** (0.2%) |
| Differ | **525** (99.8%) |

## Categorisation of the 525 differing hymns

| Category | Count | % of differs |
|---|---:|---:|
| Structural only (chorus repetition / colon punctuation) | 386 | 73.5% |
| Encoding corruption (Latin-1 → Latin-2 mojibake in CIS) | 0 | 0.0% |
| OCR-style errors (digit `0` for letter `Ó`) | 0 | 0.0% |
| Other / mixed (likely title mismatches → different editions) | 139 | 26.5% |

## Interpretation

- **Structural-only differences** are layout-only — same lyric, same hymn, different convention for representing the chorus. No action needed.
- **Encoding + OCR errors** indicate ChristInSong is the corrupted source for the affected hymns; SDAHymnal would be the cleaner re-import target.
- **Other / title-mismatch** suggests the two sources are different *editions* of the Spanish hymnal. If this category is large, the two sources may be complementary rather than redundant — keep both and cross-reference rather than choosing one.

## Per-hymn detail

The full per-hymn diff report is at `.SourceSongData/El Himnario Adventista (1962) [HA]_Spanish-es/_integrity-check.md` on the runner — gitignored, so not included here. To regenerate locally, run:

```sh
python3 .importers/scrapers/ChristInSong.app.py \
    --hymnal es --output .SourceSongData --delay 1.0
# then relocate its output to match book_dir_for_site('ha', ...) —
# see .github/workflows/scripts/ha_audit_paths.py — before running:
python3 .importers/scrapers/SDAHymnals_SDAHymnal.org.py \
    --site ha --prefer-source cis --output .SourceSongData --delay 1.0
```
