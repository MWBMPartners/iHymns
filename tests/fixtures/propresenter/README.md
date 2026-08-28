# ProPresenter 7+ golden fixtures (epic #1968)

Real, third-party ProPresenter output — **not** files iHymns generated — used to
cross-validate the server-side decoder (`includes/propresenter7_decode.php`) against an
independent implementation (protobufjs reflection). This is the anti-false-positive
core of the import work: the owner's rule is "no more false positives — validate
against real files, never a circular same-schema round-trip" (see
`.claude/propresenter-interop-1968-plan.md` §8, `.claude/propresenter-reference-sources.md`).

Every file below was decoded and its `ccli` metadata inspected **before** committing —
none carries a live copyright holder, CCLI song number, or publisher. See
`.claude/propresenter-reference-sources.md` §2 for the fuller triage (it explicitly
lists the fixtures that were **excluded** for carrying live copyright — e.g.
`TestTranslated.pro` = "Oceans (Where Feet May Fail)" © 2012 Hillsong, CCLI 6428767 —
and the owner's own SDAH v21.4 samples, withheld pending decision D3).

## Licence

Both source projects are MIT-licensed. Full licence text: see each project's own
`LICENSE` file at the commit named below (re-clone with the commands in
`.claude/propresenter-reference-sources.md` §1 if you need to read it directly).

- **ChrisMBarr/propresenter-parser** — MIT, © 2024 Chris Barr.
  <https://github.com/ChrisMBarr/propresenter-parser>, commit `9444a69cf10e4e26375bf18fd5ed71821b6f5df1`.
- **bussnet/propresenter7-php-lib** — MIT, © 2026 Thorsten Buss.
  <https://github.com/bussnet/propresenter7-php-lib>, commit `2e3ed500fe61835bbe438782d076151c59c3e7ac`.

## Fixture inventory

| Committed name | Upstream repo | Original path | Bytes | `ccli` (decoded) | Covers |
|---|---|---|---|---|---|
| `v7-at-the-cross-mac.pro` | ChrisMBarr | `sample-files/v7 - At the Cross.pro` | 4,514 | `{artist_credits:"Hymn", song_title:"At the Cross"}` — PD hymn (Watts/Hudson), no live CCLI# | Mac `\cocoartf` dialect, basic verse/chorus structure |
| `v7-come-thou-fount-mac.pro` | ChrisMBarr | `sample-files/v7 - Come Thou Fount.pro` | 5,966 | `{author:"Robert Robinson \| John Wyeth", song_title:"Come Thou Fount", song_number:108389}` — PD hymn (Robinson 1758); 108389 is CCLI/SongSelect's PD catalogue index, not a live licence | Mac dialect, author + CCLI-number metadata, multi-verse |
| `v7-empty-single-slide.pro` | ChrisMBarr | `sample-files/v7 - Empty Single Slide.pro` | 1,081 | `{}` — synthetic, no title/author at all | Degenerate empty-slide edge case |
| `v7-feature-test-win.pro` | ChrisMBarr | `sample-files/v7 - Feature Test.pro` | 20,048 | `{}` — synthetic feature-coverage file, no © | **Genuine Windows-authored PP 7.13.2** (`platform=PLATFORM_WINDOWS`), `\rtf0` dialect (`\csgenericrgb`, `\par` breaks, no `\cocoartf`), dangling `selected_arrangement` (set, `arrangements[]` empty), empty slides |
| `bussnet-test.pro` | bussnet | `doc/reference_samples/Test.pro` | 7,779 | `{author:"Autor", artist_credits:"Künstler", song_title:"Titel", publisher:"Herausgeber", copyright_year:1234, song_number:123456789, album:"Album"}` — every field is a German placeholder word ("Titel"="title", "Autor"="author", "Herausgeber"="publisher"); `copyright_year:1234` and `song_number:123456789` are obviously synthetic | 2 arrangements / 4 cue groups / repeated Chorus, full CCLI-block field coverage |
| `bussnet-media-macro.pro` | bussnet | `doc/reference_samples/TestMitBildernUndMakro.pro` | 1,609 | `{}` — name `"Moderation"`, no © | `ACTION_TYPE_MEDIA` + `ACTION_TYPE_MACRO` actions interleaved with lyric cues |
| `bussnet-testbild.probundle` | bussnet | `doc/reference_samples/TestBild.probundle` | 1,099 | inner `.pro` (`TestBild.pro`, 767 B) decodes to `{}` — synthetic image-test bundle (`test-background.png`) | `.probundle` layout 1 — media entry named by its **original relative filename** |
| `bussnet-export-from-pp.probundle` | bussnet | `doc/reference_samples/RestBildExportFromPP.probundle` | 1,207 | inner `.pro` (`TestBild.pro`, 948 B) decodes to `{}` | `.probundle` layout 2 — a genuine **PP-exported** bundle (`Media/sample-media.png`). ⚠️ Correction (verified during P2 implementation): despite the name, this small sample does **not** actually carry the broken-ZIP64-EOCD quirk — byte inspection found no ZIP64 EOCD record/locator at all, and `unzip`/`zipfile`/`\ZipArchive` all open it cleanly (see `includes/propresenter7_zip.php`'s file-level doc-block). It's genuine PP7 `.probundle` layout/media-naming coverage, not broken-EOCD coverage — see `synthetic-zip64.probundle` below for that. |
| `bussnet-testplaylist.proplaylist` | bussnet | `doc/reference_samples/TestPlaylist.proplaylist` | 5,997 | both inner `.pro`s (`Embedded Song One.pro`, `Embedded Song Two.pro`) decode to `{}` | `.proplaylist` container: `data` (PlaylistDocument) + 2 embedded `.pro` + 1 media entry |
| `bussnet-empty-playlist.proplaylist` | bussnet | `doc/reference_samples/ExamplePlaylists/EmptyPlaylist.proplaylist` | 382 | n/a — no embedded presentation | Playlist edge case: empty playlist, `data` entry only, and itself carries the broken-EOCD quirk (`unzip` needs its "missing 98 bytes" compensation path) |
| `bussnet-sample-service.proplaylist` | bussnet | `doc/reference_samples/ExamplePlaylists/SampleService.proplaylist` | 2,992 | inner `.pro` (`Sample Song.pro`, decodes to `{}`) | Playlist with a single presentation item — the smallest non-empty playlist shape |
| `bussnet-amazing-grace.pro` | bussnet | `doc/reference_samples/all-songs/Amazing Grace.pro` | 3,397 | `{}` — PD hymn (Newton), no ©/CCLI in file | More PD structure coverage |
| `bussnet-doxology.pro` | bussnet | `doc/reference_samples/all-songs/Doxology.pro` | 2,293 | `{}` — PD hymn (Ken), no ©/CCLI in file | More PD structure coverage |
| `bussnet-stille-nacht.pro` | bussnet | `doc/reference_samples/all-songs/Stille Nacht.pro` | 1,300 | `{}` — PD hymn (Mohr/Gruber), no ©/CCLI in file | More PD structure coverage |

## Synthesised fixtures (not third-party — built from parts already listed above)

Everything above this line is genuine, unmodified third-party output. The file below is
**different in kind**: it is assembled by an iHymns tool from copyright-safe parts already
committed in this directory, deliberately reproducing a real ProPresenter defect none of the
small third-party samples above happen to exhibit (see the correction on
`bussnet-export-from-pp.probundle`, above).

| Committed name | Built by | Built from | Bytes | Covers |
|---|---|---|---|---|
| `synthetic-zip64.probundle` | `tools/pp7-gen-zip64-bundle.js` | `bussnet-test.pro`'s real bytes (STORED) + a synthetic placeholder media entry, both ZIP64-sentineled | 8,431 | **The real broken-ZIP64-EOCD quirk itself** — the central-directory-size field is deliberately overstated by 98 bytes in both the ZIP64 EOCD record and the classic EOCD mirror, the exact, independently-documented magnitude of ProPresenter's own bug (`bussnet/propresenter7-php-lib`'s `Zip64Fixer` + `doc/internal/learnings.md`, arrived at completely independently of this repo). Verified during authoring: PHP `\ZipArchive::open()` returns `ZIPARCHIVE_ER_INCONS` (21) — the same code observed opening the owner's real ~2 MB bundle; Python `zipfile` raises `BadZipFile('Corrupt zip64 end of central directory record')` — the same message `.claude/propresenter-interop-1968-plan.md` §4.1 quotes; `unzip -l` reports "missing 98 bytes… reported length of central directory is 98 bytes too long… Compensating" — the same wording `learnings.md` records for genuine ProPresenter output. `includes/propresenter7_zip.php`'s tolerant reader opens it and decodes the inner `.pro` correctly regardless. |

**Why it exists:** the two genuine third-party `.probundle` fixtures above are both small enough
(~1.1–1.2 KB) that a compliant writer never actually needed ZIP64 at all — neither one exercises
the broken-EOCD code path the tolerant reader was built for (confirmed by byte inspection; see the
correction on `bussnet-export-from-pp.probundle` above). Without this fixture, CI would have zero
coverage of the actual breaking case — only a hand-built, single-entry synthetic byte string
inside the test file itself (`tests/php/test-pp7-zip.php` section (f)) exercised the ZIP64
sentinel-resolution code path, and nothing exercised the "a strict reader rejects this, ours
doesn't" property end to end. `synthetic-zip64.probundle` closes that gap with a fixture that is
real-file-shaped (genuine inner `.pro` bytes, real-PP7-export-style absolute media path,
STORED+ZIP64 throughout) while staying entirely copyright-safe and deterministically regenerable
(`node tools/pp7-gen-zip64-bundle.js`). See `tests/php/test-pp7-zip.php` section (g) for its
dedicated test coverage and mutation-proof.

## Deliberately NOT committed (per the reference-sources triage)

- `bussnet .../TestTranslated.pro` — real content is "Oceans (Where Feet May Fail)",
  © 2012 Hillsong, CCLI 6428767. Live copyright; has a translation-layer (multiple text
  elements per slide) worth studying upstream only.
- `bussnet .../all-songs/Cornerstone.pro` — title tied to a © Hillsong work.
- `ChrisMBarr .../v4/v5/v6 - …` — copyrighted modern-worship songs, and pre-v7 formats
  outside this epic's scope anyway.
- The owner's genuine v21.4 SDAH-vocabulary samples (`_temp/` on alpha, commit
  `2642f28`) — copyrighted lyric content; committing them is owner decision **D3**
  (`.claude/propresenter-interop-1968-plan.md` §12.3), still open.

## Dominant-font lyric selection (#1968 PR-1 correctness-defect fix)

`v7-at-the-cross-mac.pro` and `v7-come-thou-fount-mac.pro` (both ChrisMBarr) carry, in EVERY text
run — real lyrics or the standalone "Blank" cue group alike — a small `\f0\fs24 \cf0 ','` RTF-writer
artifact immediately preceding any real content, in the SAME `Graphics.Text.rtf_data`, no paragraph
break between them. An earlier revision of this corpus's `expected/*.song.json` for these two files
kept this pollution faithfully (a deliberate, documented false-negative, "flagged for future
consideration"), which the epic's own #1 rule ("no more false positives") required fixing for real
rather than leaving enshrined as "expected".

The fix: `_bulkImport_parsePro7()` now treats the run at the **dominant (largest) font size on a
given slide** as the lyric, and drops smaller runs merged into the same `rtf_data` —
`_bulkImport_rtfToText()`'s optional `$minFontHalfPts` param (default 0 = no filtering, so every
pre-existing Pro6/EasyWorship/Proclaim caller is untouched) plus `_bulkImport_pro7RtfMaxFontHalfPts()`
computing the per-element threshold (see both functions' doc-blocks in `includes/song_importers.php`).
This is a **defensible default, trivially tunable**: small sub-dominant-font runs (copyright /
CCLI-display attribution, page numbers, or — as in these two fixtures — an editor/export artifact)
are intentionally never imported as lyric text; a future format that genuinely needs a small-font
run's content (e.g. a real footer credit) can read it back out via a lower/explicit cutoff without
changing the underlying mechanism.

The standalone "Blank" cue group in both files is a SEPARATE case: its entire content, on its own
slide, IS that one small `\fs24` run with no larger sibling to compare it against, so font-size
filtering alone cannot drop it (proven during implementation — widening the comparison scope beyond
one slide breaks real content in `bussnet-test.pro` and `v7-feature-test-win.pro`, see
`_bulkImport_parsePro7()`'s doc-block point 3). "Blank" therefore joined the pre-existing "Song
Title"/"Lyrics Background" non-lyric-group name skip-list — the same mechanism, not a new one.

`expected/v7-at-the-cross-mac.song.json` and `expected/v7-come-thou-fount-mac.song.json` were
regenerated to the clean output (no "','" anywhere, no "Blank" section) and re-eyeballed against
the file's real lyric content; every other fixture's expected JSON is unchanged.

## `expected/*.decode.json`

One file per `.pro` fixture above, generated by `tools/pp7-gen-expected.js` using
**protobufjs reflection** — an independent decoder from
`includes/propresenter7_decode.php`'s hand-rolled proto3 wire walker. See that
script's header for the exact shape. `tests/php/test-pp7-decode.php` cross-validates
the PHP decoder's output against these files field-for-field.

## `expected/*.playlist.json` (#1968 P3)

One file per `.proplaylist` fixture above (`bussnet-testplaylist`/`-empty-playlist`/
`-sample-service`), generated by `tools/pp7-gen-playlist-expected.js` — the same
protobufjs-reflection technique as `expected/*.decode.json`, applied to the
`data` ZIP entry's `rv.data.PlaylistDocument` message instead of a bare `.pro`'s
`rv.data.Presentation`. `tests/php/test-pp7-playlist-decode.php` cross-validates
`includes/propresenter7_playlist.php`'s `pp7ReadPlaylistBundle()` output against
these files directly (no further re-shaping — both sides emit the same camelCase
`{document, proEntries, mediaEntries}` shape). Two things worth knowing if you
regenerate these:
  - The generator loads `propresenter.proto` directly from the vendored
    `proto-7.16/` schema into its OWN throwaway `protobuf.Root()` — it does **not**
    read the shared `proto-bundle.json`, which (verified during #1968 P3) does not
    include the playlist messages at all (`propresenter.proto`/`playlist.proto`
    aren't in `tools/build-proto-bundle.js`'s `ENTRY_POINTS`; adding them is a later
    task, plan §5.2 step 1, since that bundle also feeds the client static export
    module).
  - It patches `arrangement_name = 5` onto `PlaylistItem.Presentation` in memory
    before decoding (never touches any file on disk) — the vendored 7.16 schema
    doesn't declare that field, but all three real fixtures actually carry it on
    the wire (`"normal"`/`"short"`), so decoding without the patch would silently
    drop real data and make these expected files wrong.
