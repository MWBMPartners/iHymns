#!/usr/bin/env bash
#
# apple-refresh-fixtures.sh — re-records the Apple package's live API
# contract fixtures (#1396) from a real iHymns deployment.
#
# `appApple/Packages/iHymnsKit/Tests/Fixtures/{songs_index,song_detail,
# songbooks}.json` are REAL response bodies, not hand-authored JSON — they
# exist so `IHModelsTests`/`IHAPITests` decode against the shape the API
# actually sends, and drift the moment that shape changes server-side. Run
# this whenever `appWeb/public_html/api-docs.yaml`'s Song/Songbook schemas
# change, or just periodically to catch silent drift, then:
#
#   cd appApple/Packages/iHymnsKit && swift test
#
# — a red ContractTests/NetworkedAPIClientTests run means the DTOs in
# `Sources/IHModels/` need updating to match, same as this task (#1396/
# #1397) did the first time.
#
# `songs_index.json` is intentionally TRIMMED (the live corpus is ~16,000
# rows / ~3 MB): this script keeps a couple of rows per songbook plus every
# row whose `id` does NOT match `SongID`'s `<letters>-<digits>` shape (the
# ~10 legacy/manually-keyed rows that exercise the lossy-decode path in
# `Sources/IHAPI/SongsIndexDecoding.swift`). `song_detail.json` and
# `songbooks.json` are recorded in full — both are already small.
#
# Usage:
#     bash tools/apple-refresh-fixtures.sh [environment]
#
# `environment` defaults to `dev` (matches `IHModels.APIEnvironment`'s
# hostnames: dev.ihymns.app / beta.ihymns.app / ihymns.app). All three
# endpoints this script hits are unauthenticated public reads.
set -euo pipefail

ENVIRONMENT="${1:-dev}"
case "$ENVIRONMENT" in
    dev)   HOST="dev.ihymns.app" ;;
    beta)  HOST="beta.ihymns.app" ;;
    prod)  HOST="ihymns.app" ;;
    *) echo "Unknown environment '$ENVIRONMENT' — expected dev, beta, or prod." >&2; exit 1 ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FIXTURES_DIR="$ROOT/appApple/Packages/iHymnsKit/Tests/Fixtures"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "Recording fixtures from https://${HOST} …"

curl -fsSL "https://${HOST}/api?action=songs_index" -o "$TMP_DIR/songs_index_full.json"

# Pick a real song id (with audio + sheet music, so song_detail.json has a
# non-empty media-adjacent flag set) to fetch the full record for.
DETAIL_ID=$(python3 - "$TMP_DIR/songs_index_full.json" <<'PY'
import json, sys
with open(sys.argv[1]) as f:
    songs = json.load(f)["songs"]
for s in songs:
    if s.get("songbook") == "MP" and s.get("hasAudio") and s.get("hasSheetMusic"):
        print(s["id"])
        break
else:
    print(songs[0]["id"])
PY
)
echo "Using song_detail id: ${DETAIL_ID}"

curl -fsSL "https://${HOST}/api?action=song_detail&id=${DETAIL_ID}" -o "$TMP_DIR/song_detail_full.json"
curl -fsSL "https://${HOST}/api?action=songbooks" -o "$TMP_DIR/songbooks_full.json"

python3 - "$TMP_DIR/songs_index_full.json" "$FIXTURES_DIR/songs_index.json" <<'PY'
import json, re, sys

src, dest = sys.argv[1], sys.argv[2]
with open(src) as f:
    songs = json.load(f)["songs"]

pattern = re.compile(r'^[A-Za-z]+-[0-9]+$')
good = [s for s in songs if pattern.match(s["id"])]
malformed = [s for s in songs if not pattern.match(s["id"])]

seen_books: dict[str, int] = {}
selected = []
for s in good:
    book = s["songbook"]
    seen_books.setdefault(book, 0)
    if seen_books[book] < 2 and len(selected) < 70:
        selected.append(s)
        seen_books[book] += 1
selected.extend(malformed)

seen_ids = set()
out = []
for s in selected:
    if s["id"] in seen_ids:
        continue
    seen_ids.add(s["id"])
    out.append(s)

with open(dest, "w", encoding="utf-8") as f:
    json.dump({"songs": out}, f, indent=2, ensure_ascii=False)
    f.write("\n")

print(f"songs_index.json: {len(out)} rows ({len(malformed)} malformed-id rows preserved)")
PY

python3 -c "
import json
with open('$TMP_DIR/song_detail_full.json') as f: d = json.load(f)
with open('$FIXTURES_DIR/song_detail.json', 'w', encoding='utf-8') as out:
    json.dump(d, out, indent=2, ensure_ascii=False)
    out.write('\n')
"

python3 -c "
import json
with open('$TMP_DIR/songbooks_full.json') as f: d = json.load(f)
with open('$FIXTURES_DIR/songbooks.json', 'w', encoding='utf-8') as out:
    json.dump(d, out, indent=2, ensure_ascii=False)
    out.write('\n')
"

echo "Done. Review the diff, then: cd appApple/Packages/iHymnsKit && swift test"
