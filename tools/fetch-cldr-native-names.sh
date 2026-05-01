#!/bin/bash
#
# Rebuild appWeb/.sql/data/cldr-native-names.json from the upstream
# Unicode CLDR JSON release. Pulls every base locale's languages.json
# from cldr-localenames-full and extracts the locale's self-name
# (main.<code>.localeDisplayNames.languages.<code>), then merges in a
# small manual block for liturgical / minor languages whose CLDR file
# is missing or stubbed.
#
# Run when CLDR ships a new release and we want to refresh the data.
# The migration (appWeb/.sql/migrate-cldr-native-names.php) re-applies
# the overlay idempotently from /manage/setup-database.
#
# Usage:
#     bash tools/fetch-cldr-native-names.sh
#
# Output:
#     appWeb/.sql/data/cldr-native-names.json (overwritten in place)
#
# Cache:
#     /tmp/cldr-natives-cache/<code>.json — per-locale languages.json
#     downloads. Safe to delete; re-running re-pulls only what's missing.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_FILE="${REPO_ROOT}/appWeb/.sql/data/cldr-native-names.json"
WORKDIR="${WORKDIR:-/tmp/cldr-natives-cache}"
CLDR_REF="${CLDR_REF:-main}"

mkdir -p "$WORKDIR"
> "$WORKDIR/errors.log"

# 1. Pull the directory listing once and extract base codes (no script /
#    region suffix, skip 'und').
LISTING_FILE="$WORKDIR/listing.json"
if [[ ! -s "$LISTING_FILE" ]]; then
    echo "[info] Fetching CLDR locale listing…" >&2
    curl -sL "https://api.github.com/repos/unicode-org/cldr-json/contents/cldr-json/cldr-localenames-full/main?ref=${CLDR_REF}" \
        -o "$LISTING_FILE"
fi

BASES_FILE="$WORKDIR/bases.txt"
python3 -c "
import json
d = json.load(open('$LISTING_FILE'))
bases = sorted({x['name'] for x in d if x['type']=='dir' and '-' not in x['name'] and x['name']!='und'})
open('$BASES_FILE','w').write('\n'.join(bases) + '\n')
"
echo "[info] Base CLDR locales: $(wc -l < "$BASES_FILE" | tr -d ' ')" >&2

# 2. Fetch each languages.json and emit "code\tnative" on stdout.
fetch_one() {
    local code="$1"
    local cache="$WORKDIR/${code}.json"
    if [[ ! -s "$cache" ]]; then
        curl -sL --max-time 20 \
            "https://raw.githubusercontent.com/unicode-org/cldr-json/${CLDR_REF}/cldr-json/cldr-localenames-full/main/${code}/languages.json" \
            -o "$cache" || { rm -f "$cache"; echo "FETCH-FAIL $code" >> "$WORKDIR/errors.log"; return 1; }
    fi
    python3 -c "
import json, sys
try:
    d = json.load(open('$cache'))
    name = d['main']['$code']['localeDisplayNames']['languages']['$code']
    print('$code\t' + name)
except Exception as e:
    print('SKIP $code: ' + str(e), file=sys.stderr)
" 2>>"$WORKDIR/errors.log"
}
export -f fetch_one
export WORKDIR CLDR_REF

TSV="$WORKDIR/cldr-natives.tsv"
cat "$BASES_FILE" | xargs -P 12 -I{} bash -c 'fetch_one "$@"' _ {} > "$TSV"
echo "[info] Pulled $(wc -l < "$TSV" | tr -d ' ') / $(wc -l < "$BASES_FILE" | tr -d ' ') locales." >&2
echo "[info] Errors: $(wc -l < "$WORKDIR/errors.log" | tr -d ' ')" >&2

# 3. Build the JSON, merging a manual block for liturgical / minor
#    languages whose CLDR file is missing or stubbed. Keep the manual
#    block in this script so the data file is fully reproducible from
#    the script alone.
python3 - <<'PY' "$TSV" "$OUT_FILE"
import json, sys, os

tsv_path, out_path = sys.argv[1], sys.argv[2]

data = {}
with open(tsv_path) as f:
    for line in f:
        parts = line.rstrip('\n').split('\t', 1)
        if len(parts) == 2 and parts[0] and parts[1]:
            data[parts[0]] = parts[1]

# Manual additions — codes whose CLDR languages.json was missing or
# returned a stub without the self-name. Sourced from each language's
# own Wikipedia infobox / Ethnologue entry. Extend this block when new
# CLDR-missing languages need a NativeName.
manual = {
    'aa':  'Afaraf',
    'cu':  'Словѣньскъ',          # Old Church Slavonic (liturgical)
    'cop': 'ⲘⲉⲧⲢⲉⲙ̀ⲛⲭⲏⲙⲓ',          # Coptic (liturgical)
    'dv':  'ދިވެހިބަސް',              # Divehi / Maldivian
    'gez': 'ግዕዝ',                  # Geʽez (liturgical)
    'iu':  'ᐃᓄᒃᑎᑐᑦ',              # Inuktitut
    'la':  'Latina',               # Latin (liturgical)
    'ltg': 'latgalīšu',            # Latgalian
    'mww': 'Hmoob',                # Green Hmong
    'nr':  'isiNdebele',           # South Ndebele
    'pi':  'पाऴि',                  # Pali (liturgical)
    'sgs': 'žemaitiu',             # Samogitian
    'tig': 'ትግረ',                  # Tigre
    'ts':  'Xitsonga',             # Tsonga
    've':  'Tshivenḓa',            # Venda
    'vo':  'Volapük',              # Volapük
    'wal': 'ወላይታቱ',                # Wolaytta
    'byn': 'ብሊን',                  # Blin
    'lzz': 'Lazuri',               # Laz
    'nmg': 'Kwasio',               # Kwasio
}
for k, v in manual.items():
    if k not in data:
        data[k] = v

data_sorted = dict(sorted(data.items()))

out = {
    "_meta": {
        "purpose": "NativeName overlay for tblLanguages — populates the NativeName column with each language's self-reference (the language's name as it would appear in its own locale). Read by appWeb/.sql/migrate-cldr-native-names.php and overlaid onto tblLanguages.NativeName for any row whose Code matches.",
        "source": "Unicode CLDR cldr-localenames-full/main/<code>/languages.json — extracted main.<code>.localeDisplayNames.languages.<code> for every base locale, then supplemented with manual entries for liturgical / minor languages whose CLDR file is missing or stubbed (Latin, Pali, Geʽez, Coptic, Old Church Slavonic, Divehi, Inuktitut, etc.).",
        "license": "Unicode Data Files and Software License (https://www.unicode.org/license.html). CLDR is published under this licence and permits redistribution + modification.",
        "rebuild": "Refresh by running tools/fetch-cldr-native-names.sh (committed alongside this file) — it pulls the latest CLDR JSON release and rewrites this file.",
        "scope": "Every base locale CLDR ships in cldr-localenames-full ('full', not 'modern' — includes experimental locales). Coverage extends well beyond the originally-deferred 'worship-relevant' subset; future PRs can extend further by editing the manual block in the rebuild script.",
        "encoding": "UTF-8 with no BOM. Right-to-left scripts (ar, he, fa, ur, ps, dv) are stored as-is; the IETF picker shows them with the browser's default bidi handling.",
        "format": "JSON — top-level 'languages' object keyed by lowercase BCP 47 primary subtag → native-name string.",
        "entries": len(data_sorted),
    },
    "languages": data_sorted,
}

with open(out_path, 'w', encoding='utf-8') as f:
    json.dump(out, f, ensure_ascii=False, indent=4)
    f.write('\n')

print(f"[ok] Wrote {out_path} — {len(data_sorted)} languages, {os.path.getsize(out_path):,} bytes")
PY
