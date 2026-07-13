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
# #180 UPDATE — also re-records `song_links.json`/`related_songs.json`
# (both `?action=…&id=MP-0031`, the same song `song_detail.json` uses) —
# `SongLinkGroup`/`RelatedSongSummary` (IHModels/SongRelations.swift) decode
# against these. Both are small, full responses; no trimming needed.
#
# #183 UPDATE — also re-records `song_of_the_day.json` (a bare, undated
# `?action=song_of_the_day` pull) — `SongOfTheDay`/`SongOfTheDayCard`
# (IHModels/SongOfTheDay.swift) decode against it. Small, full response; no
# trimming needed. The separately-verified themed-date shape
# (`&date=2026-12-25`) isn't re-recorded here — see
# `IHModelsTests/SongOfTheDayTests.swift` for that hand-authored variant.
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
curl -fsSL "https://${HOST}/api?action=song_links&id=${DETAIL_ID}" -o "$TMP_DIR/song_links_full.json"
curl -fsSL "https://${HOST}/api?action=related_songs&id=${DETAIL_ID}" -o "$TMP_DIR/related_songs_full.json"
curl -fsSL "https://${HOST}/api?action=song_of_the_day" -o "$TMP_DIR/song_of_the_day_full.json"

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

python3 -c "
import json
with open('$TMP_DIR/song_links_full.json') as f: d = json.load(f)
with open('$FIXTURES_DIR/song_links.json', 'w', encoding='utf-8') as out:
    json.dump(d, out, indent=2, ensure_ascii=False)
    out.write('\n')
"

python3 -c "
import json
with open('$TMP_DIR/related_songs_full.json') as f: d = json.load(f)
with open('$FIXTURES_DIR/related_songs.json', 'w', encoding='utf-8') as out:
    json.dump(d, out, indent=2, ensure_ascii=False)
    out.write('\n')
"

python3 -c "
import json
with open('$TMP_DIR/song_of_the_day_full.json') as f: d = json.load(f)
with open('$FIXTURES_DIR/song_of_the_day.json', 'w', encoding='utf-8') as out:
    json.dump(d, out, indent=2, ensure_ascii=False)
    out.write('\n')
"

# Apple Phase-2 PR-10 (#1426, #1427) — the 4 live-recordable Live Follow /
# Service Mode NEGATIVE envelopes (unauthenticated, no live session needed —
# see `Tests/Fixtures/README.md`'s provenance block for these 4). The 5
# POSITIVE fixtures (`live_follow_join.json`/`live_follow_poll_changed.json`/
# `service_join.json`/`service_poll_changed.json`/`service_poll_unchanged.json`)
# are CODE-DERIVED (no dev operator session was available when they were
# authored) and are NOT re-recorded by this script — re-record them by hand
# during the multi-device live verify (plan §2 gate) once a real host/venue
# session exists on `dev`, then replace the marked placeholder JSON directly.
curl -fsSL "https://${HOST}/api?action=live_follow_join&code=ZZZZ99" -o "$TMP_DIR/live_follow_join_not_found.json" || true
curl -fsSL "https://${HOST}/api?action=live_follow_poll&code=ZZZZ99" -o "$TMP_DIR/live_follow_poll_inactive.json" || true
curl -fsSL -X POST -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
    -d '{"code":"ZZZZ99","presenceDeviceId":"fixture-recorder"}' \
    "https://${HOST}/api?action=service_join" -o "$TMP_DIR/service_join_not_active.json" || true
curl -fsSL "https://${HOST}/api?action=service_poll&presenceToken=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx&since=0" \
    -o "$TMP_DIR/service_poll_inactive.json" || true

for name in live_follow_join_not_found live_follow_poll_inactive service_join_not_active service_poll_inactive; do
    if [ -s "$TMP_DIR/${name}.json" ]; then
        python3 -c "
import json
with open('$TMP_DIR/${name}.json') as f: d = json.load(f)
with open('$FIXTURES_DIR/${name}.json', 'w', encoding='utf-8') as out:
    json.dump(d, out, indent=2, ensure_ascii=False)
    out.write('\n')
"
    fi
done

echo "Done. Review the diff, then: cd appApple/Packages/iHymnsKit && swift test"
