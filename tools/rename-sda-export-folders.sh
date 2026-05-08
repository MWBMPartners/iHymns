#!/usr/bin/env bash
#
# tools/rename-sda-export-folders.sh
#
# One-shot, idempotent renamer that brings the on-disk
# `SourceSongData/_SDAHymnal Export 2/<book>/` folders into line with
# the canonical native-script titles from sdahymnal.org's "Choose
# another Hymnal" picker — same source the scraper's SITES manifest
# now uses (#901, 2026-05-08).
#
# Why a separate script (vs renaming inside the scraper):
#   - The scraper is non-destructive and resumability-keyed off folder
#     name. Letting the scraper do a "if old folder exists, mv to new"
#     dance every run is wasteful + risky on a half-renamed tree.
#   - This is a one-shot data-migration concern, not a scraping concern.
#
# Idempotent — running twice on a fully-renamed tree is a no-op.
# Per CLAUDE.md, source data never enters git, so no `git mv` needed.
#
# Usage (from repo root):
#   bash tools/rename-sda-export-folders.sh                  # apply
#   bash tools/rename-sda-export-folders.sh --dry-run        # preview only
#
# Exit codes:
#   0  — every renameable folder is at its target name (rename + no-op)
#   1  — at least one rename failed (collision, perms, missing source)
#   2  — base export dir not found

set -u

DRY_RUN=0
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=1
fi

# Located relative to repo root. The scrape output lives outside the repo
# in SourceSongData/, which is .gitignored.
EXPORT_DIR="SourceSongData/_SDAHymnal Export 2"

if [[ ! -d "${EXPORT_DIR}" ]]; then
    echo "ERROR: export directory not found: ${EXPORT_DIR}" >&2
    echo "Run from the repository root." >&2
    exit 2
fi

# Old → new folder names. Keep the language suffix
# (`_<EnglishLangName>-<bcp47>`) intact; only the title before the
# `[<ABBR>]` bracket changes. The IA folder ("Innario Avventista") is
# already canonical so isn't listed here.
#
# Format: one entry per line, `OLD<TAB>NEW`. Bash 3.2 (macOS default)
# doesn't have associative arrays, so we use a here-doc + while-read
# loop for portability.
read -r -d '' RENAMES <<'EOF' || true
Himnario Adventista [HA]_Spanish-es	El Himnario Adventista (1962) [HA]_Spanish-es
Nuevo Himnario Adventista [NHA]_Spanish-es	El Himnario Adventista Nuevo (2010) [NHA]_Spanish-es
Hinario Adventista do Setimo Dia [HASD]_Portuguese-pt	Hinário Adventista [HASD]_Portuguese-pt
Hymnes et Louanges [HL]_French-fr	Hymnes et Louanges, Cantiques [HL]_French-fr
HAC pesmarica [HAC]_Serbian (Latin)-sr-Latn	Hrišćanske adventističke himne [HAC]_Serbian (Latin)-sr-Latn
Hristianski Pesni [HP]_Bulgarian-bg	Християнски песни [HP]_Bulgarian-bg
Hristijanski Pesni [HJP]_Macedonian-mk	Христијански песни [HJP]_Macedonian-mk
Pesmarica [PES]_Serbian (Cyrillic)-sr-Cyrl	Хришћанске адвентистичке химне [PES]_Serbian (Cyrillic)-sr-Cyrl
Krscanske Pjesme [PJ]_Croatian-hr	Kršćanske adventističke himne [PJ]_Croatian-hr
EOF

renamed=0
unchanged=0
failed=0

while IFS=$'\t' read -r old new; do
    [[ -z "${old}" ]] && continue
    src="${EXPORT_DIR}/${old}"
    dst="${EXPORT_DIR}/${new}"

    if [[ -d "${dst}" ]]; then
        # New name already in place. Two sub-cases:
        #   - old also exists → ambiguous; surface as a failure for manual review
        #   - old absent      → already renamed; no-op
        if [[ -d "${src}" ]]; then
            echo "  ⚠  collision (both old + new exist): ${old}" >&2
            failed=$((failed + 1))
        else
            echo "  =  already renamed: ${new}"
            unchanged=$((unchanged + 1))
        fi
        continue
    fi

    if [[ ! -d "${src}" ]]; then
        echo "  ?  source missing — neither old nor new exists: ${old}" >&2
        unchanged=$((unchanged + 1))
        continue
    fi

    if [[ ${DRY_RUN} -eq 1 ]]; then
        echo "  →  [dry-run]  ${old}"
        echo "       →  ${new}"
        renamed=$((renamed + 1))
        continue
    fi

    if mv -- "${src}" "${dst}"; then
        echo "  →  renamed:  ${old}"
        echo "       →  ${new}"
        renamed=$((renamed + 1))
    else
        echo "  ✗  mv failed: ${old}" >&2
        failed=$((failed + 1))
    fi
done <<< "${RENAMES}"

echo ""
if [[ ${DRY_RUN} -eq 1 ]]; then
    echo "Dry-run summary: ${renamed} would be renamed, ${unchanged} already match, ${failed} would need attention."
else
    echo "Summary: ${renamed} renamed, ${unchanged} already at target, ${failed} failed."
fi

[[ ${failed} -gt 0 ]] && exit 1
exit 0
