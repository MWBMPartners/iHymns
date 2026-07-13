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
- **`song_of_the_day.json`** — `?action=song_of_the_day` (#183, Apple P1
  Home surface), a bare/undated/unauthenticated pull from `dev`. Unlike
  every other fixture in this directory, this endpoint's
  `api-docs.yaml` schema turned out to be ACCURATE — a second pull with
  `&date=2026-12-25` against `ihymns.app` returned the exact same envelope
  shape with a themed `themeLabel` ("Christmas Song of the Day") and a
  different `song`, confirming `hemisphere`/`country`/`date` are real,
  working query parameters even though the YAML only documents `lang`/
  `date` (`appWeb/public_html/api.php`'s `song_of_the_day` case reads
  `hemisphere`/`country` from `$_GET` directly — #1374/#1376 — a docs-
  freshness gap worth its own follow-up issue, same posture as the
  `Songbook.swift`/`SongDetail.swift` findings above). Only the undated
  default pull is committed as a fixture; the themed variant is exercised
  via hand-authored JSON in `IHModelsTests/SongOfTheDayTests.swift` instead
  (matching that file's existing precedent for shapes this task's live
  survey confirmed but didn't need a second full fixture file to prove).

No token/PII scrubbing was needed: every endpoint here is an unauthenticated
public read, and the payloads are public hymn catalogue metadata.

## Apple Phase-2 PR-10 (#1426, #1427) — Live Follow / Service Mode

Nine fixtures for the server-mediated live-sync wire contract
(`.claude/apple-phase2-pr10-spec.md` §7.1). Split honestly between
LIVE-RECORDED and CODE-DERIVED, per that spec's honesty rule (the
`song_links.json`/`Work.swift` precedent above):

**LIVE-RECORDED** (`https://dev.ihymns.app`, 2026-07-13, no auth/session
needed — every one of these is a genuine "wrong/expired code" or "session
gone" answer, which needs no live session to produce):
- **`live_follow_join_not_found.json`** — `?action=live_follow_join&code=ZZZZ99`.
  The real opaque 404 body (`api.php:14160`).
- **`live_follow_poll_inactive.json`** — `?action=live_follow_poll&code=ZZZZ99`.
  `{"active":false}` (`api.php:14226`).
- **`service_join_not_active.json`** — `POST ?action=service_join` with
  `{"code":"ZZZZ99","presenceDeviceId":"fixture-recorder"}`. The real opaque
  404 body (`api.php:14627`), including the live curly-apostrophe in
  "isn't."
- **`service_poll_inactive.json`** — `?action=service_poll&presenceToken=<43
  junk chars>&since=0`. `{"active":false}` (a malformed-shape token short
  -circuits to the same answer as a genuine-shape-but-unknown one,
  `api.php:14698`).

**CODE-DERIVED** (built field-by-field from the `sendJson(...)` call sites
cited in `.claude/apple-phase2-pr10-spec.md` §1.3 — **NOT live-recorded**,
because producing any of these five requires an actual signed-in host or an
operator-started venue session on `dev`, and no dev operator session was
available when this PR was authored):
- **`live_follow_join.json`** — the `live_follow_join` success shape
  (`api.php:14171-14180`): a host code, a follower token, a host display
  name, and an in-progress broadcast.
- **`live_follow_poll_changed.json`** — the `live_follow_poll` `changed:true`
  shape (`api.php:14231-14237`).
- **`service_join.json`** — the `service_join` success shape
  (`api.php:14683-14691`): an opaque 43-char base64url presence token, a
  server-declared `pollIntervalMs`, and an in-progress broadcast.
- **`service_poll_changed.json`** — the `service_poll` `changed:true` shape
  (`api.php:14742-14749`).
- **`service_poll_unchanged.json`** — the `service_poll` `changed:false`
  shape (`api.php:14741`).

**RE-RECORD OBLIGATION:** all five CODE-DERIVED fixtures above must be
replaced with genuine live recordings during the multi-device live verify
(plan §2 gate), once a real host session (for the two `live_follow_*` ones)
and a real operator-started venue session (for the three `service_*` ones)
exist on `dev`. `tools/apple-refresh-fixtures.sh` re-records the four
LIVE-RECORDED negatives above on every run; it deliberately does NOT attempt
the five CODE-DERIVED positives (no session to record against) — re-record
those by hand and replace the files directly. The envelope tests
(`LiveSyncModelTests`/`LiveSyncAPITests`) prove the DTOs decode the
DOCUMENTED shape either way; only the CODE-DERIVED files carry residual risk
that the live shape has quietly drifted from `api.php`'s current source.

Every value in every one of these nine fixtures is either a placeholder
(`ZZZZ99`, `fixture-recorder`, `MP-0031` reused from the catalogue fixtures
above) or synthetically generated (the follow/presence tokens) — none is a
real user's data, a real venue's rotating code, or a real host's display
name.
