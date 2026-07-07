# Contract fixtures — live-recorded, not hand-authored

These files are **real response bodies** pulled from the `dev`
deployment (`https://dev.ihymns.app/api?action=…`, no auth needed — every
endpoint here is a public read) on 2026-07-07, then lightly trimmed for
size. They exist so `IHModelsTests`/`IHAPITests` decode against the ACTUAL
shape the live API sends, not an assumed/guessed shape — the whole point of
#1396. Re-record ALL FIVE with `tools/apple-refresh-fixtures.sh` (repo root)
— `song_links.json`/`related_songs.json` (#180) were added to that script
in the same commit that first recorded them by hand
(`curl "https://dev.ihymns.app/api?action=song_links&id=MP-0031"` etc.).

- **`songs_index.json`** — `?action=songs_index`. The live corpus is
  16,084 rows (~3.2 MB); trimmed here to 84 representative rows (a couple
  per songbook, both `hasAudio`/`hasSheetMusic` combinations, several
  languages) **plus every one of the 10 real rows on the live catalogue
  whose `id` does NOT match the `<letters>-<digits>` `SongID` shape**
  (e.g. `AH-1777739808685-j`, `Misc-mqvf82yndnh9` — legacy/manually-keyed
  rows; see `.claude/CLAUDE.md` rule #27 and `MEMORY.md`'s note on the
  "Here To Stay" test fixtures). Every retained row's JSON is byte-for-byte
  what the server returned — only the *set of rows* was reduced, never
  their content. This is what proves `decodeSongsIndex` must tolerate a
  handful of unparsable ids without losing the other 16,000+ (see the
  `LossyElement` wrapper in `Sources/IHAPI/SongsIndexDecoding.swift`).
- **`song_detail.json`** — `?action=song_detail&id=MP-0031` ("Amazing
  grace" in Mission Praise). Picked over `MP-0001` because it has 4
  lyric components instead of 1, giving `SongComponent` array-decoding
  something non-trivial to prove. Full, untrimmed response.
- **`songbooks.json`** — `?action=songbooks`. Full, untrimmed response —
  all 54 songbooks currently on `dev`, including the bibliographic/
  authority-control fields (#672), `series`/`compilers`/`alternativeNames`
  (#831/#832), the self-referential `parent` (translation-of) relationship
  (#782 phase D), and the plural `languages` array (#857) — several of
  these are NOT documented in `appWeb/public_html/api-docs.yaml`'s
  `Songbook` schema (which is stale in a few places — see doc comments on
  `IHModels/Songbook.swift`).

- **`song_links.json`** — `?action=song_links&id=MP-0031` (#180, #807).
  The real, live response is `{"groupId":0,"songs":[]}` — a broad survey
  across ~60 songs (including THREE other songbooks' own copies of
  "Amazing grace" itself: `CH-0295`/`JP-0008`/`SDAH-0108`, none linked)
  found `tblSongLinks` has no curated cross-book counterpart rows on `dev`
  yet. `IHModels/SongRelations.swift`'s `SongLinkedSong` per-row shape is
  therefore modelled from `api-docs.yaml`'s documented schema, unverified
  against a live non-empty payload (same conservative posture as
  `Work.swift`) — this fixture proves the ENVELOPE (`groupId`/`songs` keys,
  the `hasCounterparts` false-when-empty check) decodes correctly, not the
  per-row shape.
- **`related_songs.json`** — `?action=related_songs&id=MP-0031` (#180).
  Genuinely rich, non-empty live data: 10 related songs (shared writer
  "John Newton" / composer "Roland Fudge"), each carrying a `reason` field
  `api-docs.yaml` doesn't document at all, and each MISSING several fields
  (`songbookName`/`language`/`hasAudio`/`hasSheetMusic`/`publicId`) a
  generic `SongSummary` decode would require — proving `RelatedSongSummary`
  needs to be its own purpose-built shape, not a reuse of `SongSummary`.

No token/PII scrubbing was needed: every endpoint here is an unauthenticated
public read, and the payloads are public hymn catalogue metadata.
