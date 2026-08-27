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
| `bussnet-export-from-pp.probundle` | bussnet | `doc/reference_samples/RestBildExportFromPP.probundle` | 1,207 | inner `.pro` (`TestBild.pro`, 948 B) decodes to `{}` | `.probundle` layout 2 — a genuine **PP-exported** bundle (`Media/sample-media.png`), the broken-ZIP64-EOCD quirk the tolerant reader (P2) must handle |
| `bussnet-testplaylist.proplaylist` | bussnet | `doc/reference_samples/TestPlaylist.proplaylist` | 5,997 | both inner `.pro`s (`Embedded Song One.pro`, `Embedded Song Two.pro`) decode to `{}` | `.proplaylist` container: `data` (PlaylistDocument) + 2 embedded `.pro` + 1 media entry |
| `bussnet-empty-playlist.proplaylist` | bussnet | `doc/reference_samples/ExamplePlaylists/EmptyPlaylist.proplaylist` | 382 | n/a — no embedded presentation | Playlist edge case: empty playlist, `data` entry only, and itself carries the broken-EOCD quirk (`unzip` needs its "missing 98 bytes" compensation path) |
| `bussnet-sample-service.proplaylist` | bussnet | `doc/reference_samples/ExamplePlaylists/SampleService.proplaylist` | 2,992 | inner `.pro` (`Sample Song.pro`, decodes to `{}`) | Playlist with a single presentation item — the smallest non-empty playlist shape |
| `bussnet-amazing-grace.pro` | bussnet | `doc/reference_samples/all-songs/Amazing Grace.pro` | 3,397 | `{}` — PD hymn (Newton), no ©/CCLI in file | More PD structure coverage |
| `bussnet-doxology.pro` | bussnet | `doc/reference_samples/all-songs/Doxology.pro` | 2,293 | `{}` — PD hymn (Ken), no ©/CCLI in file | More PD structure coverage |
| `bussnet-stille-nacht.pro` | bussnet | `doc/reference_samples/all-songs/Stille Nacht.pro` | 1,300 | `{}` — PD hymn (Mohr/Gruber), no ©/CCLI in file | More PD structure coverage |

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

## `expected/*.decode.json`

One file per `.pro`/`.probundle`/`.proplaylist` fixture above, generated by
`tools/pp7-gen-expected.js` using **protobufjs reflection** — an independent decoder
from `includes/propresenter7_decode.php`'s hand-rolled proto3 wire walker. See that
script's header for the exact shape. `tests/php/test-pp7-decode.php` cross-validates
the PHP decoder's output against these files field-for-field.
