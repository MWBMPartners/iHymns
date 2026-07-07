# Contract fixtures — live-recorded, not hand-authored

These three files are **real response bodies** pulled from the `dev`
deployment (`https://dev.ihymns.app/api?action=…`, no auth needed — all
three are public reads) on 2026-07-07, then lightly trimmed for size. They
exist so `IHModelsTests`/`IHAPITests` decode against the ACTUAL shape the
live API sends, not an assumed/guessed shape — the whole point of #1396.
Re-record with `tools/apple-refresh-fixtures.sh` (repo root).

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

No token/PII scrubbing was needed: every endpoint here is an unauthenticated
public read, and the payloads are public hymn catalogue metadata.
