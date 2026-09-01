# Import & Export Fidelity

> **Auto-generated — do not hand-edit.** Regenerate with `php tests/php/test-interchange-roundtrip.php --matrix --write`, then commit the diff.

This page is a CLOSURE report, not an external-correctness report: it shows whether iHymns' own exporters (`format-export.js`) and iHymns' own importers (`song_importers.php`) agree with EACH OTHER on one hand-built fixture song (`tools/interchange-gen-samples.js FIXTURE_SONG`) — never whether either half is correct against a REAL file from another application. The mechanism behind this page is `tests/php/test-interchange-roundtrip.php`; read that file's header for the full doctrine (including why ProPresenter 7+ is deliberately excluded and tested separately by `tests/php/test-pp7-roundtrip.php`).

## Legend

- `✓` **held** — round-trips losslessly through our own exporter -> our own importer
- `~` **reshaped** — the value survives but its role/shape changes (e.g. a composer relabelled a writer)
- `✗` **dropped** — the exporter writes this field but the importer never reads it back — lost on our own round trip
- `·` **n/a** — this format's exporter never attempts to write this field at all — nothing was ever sent

## Closure-tested formats

Every cell below is asserted, on every CI run, against the REAL exporter and REAL importer — this table is a rendering of `tests/php/test-interchange-roundtrip.php`'s own `$FORMATS` expected dicts, not a separate hand-maintained claim.

| Format | title  | writers  | composers  | copyright  | ccli  | tuneName  | alternateTitle  | chords  | language  | label  | notes  | arrangement  | components |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| OpenSong (.xml) | ✓ | ~ | ✗ | ✓ | ✓ | ✗ | · | · | · | · | · | · | ✓ |
| OpenLP / OpenLyrics (.xml) | ✓ | ~ | · | ✓ | ✓ | · | · | · | · | · | · | · | ✓ |
| ProPresenter 6 (.pro6) | ✓ | ~ | · | ✓ | ✓ | · | · | · | · | · | · | · | ✓ |
| Proclaim (.txt) | ✓ | · | · | · | · | · | · | · | · | · | · | · | ✓ |
| VideoPsalm (.json) | ✓ | ✗ | ✗ | ✗ | ✗ | · | · | · | · | · | · | · | ✓ |
| FreeShow (.show) | ✓ | ~ | · | ✓ | ✓ | · | · | · | · | · | · | · | ✓ |
| ChordPro (.cho, .chopro, .crd, .chord) | ✓ | ✗ | ~ | ✓ | ✓ | · | ✗ | ✓ | · | · | · | · | ✓ |

### Per-format notes

- **OpenSong (.xml)**
  - **writers** (~ reshaped): writers+composers joined into one <author> element, comma-split back on import — a composer returns indistinguishable from a writer (format-export.js:121-123,141; song_importers.php:2252)
  - **composers** (✗ dropped): always empty on import — no separate composer channel survives this format at all (song_importers.php:2277)
  - **tuneName** (✗ dropped): DEFECT (4) — <tune> is emitted (format-export.js:147) but never read back; the importer hardcodes tuneName to '' (song_importers.php:2269)
  - **alternateTitle** (· n/a): buildOpenSong() never reads song.alternateTitle — never attempted
  - **chords** (· n/a): buildOpenSong() never reads comp.chords — OpenSong lyric rows have no chord channel in this exporter
  - **language** (· n/a): buildOpenSong() never reads comp.language
  - **label** (· n/a): buildOpenSong() never reads comp.label
  - **notes** (· n/a): buildOpenSong() never reads comp.notes (per-line)
  - **arrangement** (· n/a): this parser's return shape has no arrangement concept at all
  - **components** (✓ held): lyric lines + per-component type/number round-trip exactly
- **OpenLP / OpenLyrics (.xml)**
  - **writers** (~ reshaped): writers+composers both become separate <author> elements, indistinguishable on import (format-export.js:403,408-411; song_importers.php:3251-3257)
  - **composers** (· n/a): this parser's return shape has no separate composers key at all
  - **tuneName** (· n/a): buildOpenLyrics() never reads song.tuneName
  - **alternateTitle** (· n/a): buildOpenLyrics() never emits a second <title> — never attempted
  - **chords** (· n/a): buildOpenLyrics() never emits <chord> — though its OWN importer can read one from a genuine third-party file
  - **language** (· n/a): buildOpenLyrics() never emits a verse lang attribute — same caveat as chords
  - **label** (· n/a): no label channel in OpenLyrics at all
  - **notes** (· n/a): buildOpenLyrics() never emits <comment> — same caveat as chords
  - **arrangement** (· n/a): THE headline documented lesson (see file header): FIXTURE_SONG.arrangement is [2,0,1] but buildOpenLyrics() (format-export.js:419) always emits a natural-order <verseOrder> from component iteration order, never from song.arrangement; re-import resolves the identity permutation and correctly, deliberately OMITS the key (#2062 identity-suppression) rather than storing a no-op. Mutation-proven (m5).
- **ProPresenter 6 (.pro6)**
  - **writers** (~ reshaped): CCLIAuthor is writers+composers joined with ' / ', slash-split back on import — composer folded into writer (format-export.js:575,579; song_importers.php:2283)
  - **composers** (· n/a): this parser's return shape has no composers key at all
  - **tuneName** (· n/a): no tune concept in .pro6
  - **alternateTitle** (· n/a): no subtitle concept in .pro6
  - **chords** (· n/a): no chord channel — .pro6 slide text is plain RTF
  - **language** (· n/a): no language attribute in .pro6
  - **label** (· n/a): no label channel in .pro6
  - **notes** (· n/a): no per-line note channel in .pro6
  - **arrangement** (· n/a): this parser's return shape has no arrangement concept at all
- **Proclaim (.txt)**
  - **writers** (· n/a): Proclaim's .txt format has NO metadata channel at all — always empty regardless of input
  - **composers** (· n/a): same — no metadata channel
  - **copyright** (· n/a): same — no metadata channel
  - **ccli** (· n/a): same — no metadata channel
  - **tuneName** (· n/a): same — no metadata channel
  - **alternateTitle** (· n/a): same — no metadata channel
  - **chords** (· n/a): same — lyrics text only
  - **language** (· n/a): same — no metadata channel
  - **label** (· n/a): same — no metadata channel
  - **notes** (· n/a): same — no metadata channel
  - **arrangement** (· n/a): same — no metadata channel
  - **components** (✓ held): lyric text + section labels are the ONLY thing Proclaim carries
- **VideoPsalm (.json)**
  - **writers** (✗ dropped): DEFECT (1) — buildVideoPsalm() never emits an Author key at all (format-export.js:204-227); the importer reads sRaw['Author'] (song_importers.php:2577)
  - **composers** (✗ dropped): DEFECT (1), continued — no composer channel either
  - **copyright** (✗ dropped): DEFECT (1) — written to Memo1 (format-export.js:224); the importer reads Copyright (song_importers.php:2596)
  - **ccli** (✗ dropped): DEFECT (1) — written to Memo2 as 'CCLI '+ccli (format-export.js:225); the importer reads CCLI (song_importers.php:2593)
  - **tuneName** (· n/a): buildVideoPsalm() never reads song.tuneName
  - **alternateTitle** (· n/a): buildVideoPsalm() never reads song.alternateTitle
  - **chords** (· n/a): no chord channel in VideoPsalm
  - **language** (· n/a): no per-component language channel in VideoPsalm
  - **label** (· n/a): no label channel in VideoPsalm
  - **notes** (· n/a): no per-line note channel in VideoPsalm
  - **arrangement** (· n/a): this parser's return shape has no arrangement concept at all
  - **components** (✓ held): lyric lines + Tag-derived type/number round-trip correctly; NOTE — songbookName also comes back as the SONG'S OWN title, not the exported songbookName input, because VideoPsalm's native unit is a whole songbook and a single-song export reuses the title as the "book" name (format-export.js:230; song_importers.php:2504) — a real quirk, distinct from defect (1), not scored as its own field here
- **FreeShow (.show)**
  - **writers** (~ reshaped): meta.author is writers+composers comma-joined, correctly comma-split back into 3 names on import — names intact, writer/composer ROLE distinction lost (format-export.js:308,316; song_importers.php:7925)
  - **composers** (· n/a): this parser's return shape has no composers key at all
  - **ccli** (✓ held): unprefixed, unlike VideoPsalm's 'CCLI '-prefixed Memo2 (format-export.js:318)
  - **tuneName** (· n/a): no tune concept in FreeShow
  - **alternateTitle** (· n/a): no subtitle concept in FreeShow
  - **chords** (· n/a): no chord channel — plain slide text runs only
  - **language** (· n/a): no per-component language channel in FreeShow
  - **label** (· n/a): no label channel in FreeShow
  - **notes** (· n/a): no per-line note channel in FreeShow
  - **arrangement** (· n/a): this parser's return shape has no arrangement concept at all
- **ChordPro (.cho, .chopro, .crd, .chord)**
  - **writers** (✗ dropped): DEFECT (2) — folded into the SAME {artist:} directive as composers (format-export.js:792,796); the importer's 'artist'/'composer'/'music' arm keeps the whole string as ONE composer entry (song_importers.php:2866-2867) — writers comes back empty. Mutation-proven (m3).
  - **composers** (~ reshaped): DEFECT (2), continued — holds the merged writers+composers string as a single un-split entry
  - **tuneName** (· n/a): buildChordPro() never reads song.tuneName at all
  - **alternateTitle** (✗ dropped): DEFECT (3) — {subtitle:} IS emitted (format-export.js:795), but the importer's directive switch has no case for it; its documented default arm says outright "no target field in the song model" (song_importers.php:2889-2893)
  - **chords** (✓ held): the ONLY format-export.js builder that reads comp.chords at all; both the positioned-STRING cell and the word-aligned ARRAY cell collapse to the same space-joined ordered token list (chordless component correctly carries no 'chords' key)
  - **language** (· n/a): buildChordPro() never reads comp.language
  - **label** (· n/a): buildChordPro() never reads comp.label
  - **notes** (· n/a): buildChordPro() never reads comp.notes (per-line)
  - **arrangement** (· n/a): this parser's return shape has no arrangement concept at all

## Not closure-tested here

Formats `import2.php` accepts with no `format-export.js` exporter (or, for ProPresenter 7+, tested by a dedicated closure test instead) — never scored above, each named so the absence is a documented decision, not a gap:

- **iHymns interchange (.json)** (`ihymns`) — native iHymns interchange JSON (#1633) — import-only, no format-export.js exporter exists for it
- **ProPresenter 7+ (.pro)** (`pro7`) — the ONE binary format deliberately excluded here — closure-tested separately by the sanctioned tests/php/test-pp7-roundtrip.php (see this file's header)
- **ProPresenter 7+ Bundle (.probundle)** (`probundle`) — a ZIP container of .pro entries, pro7-adjacent — import-only, no format-export.js exporter
- **ProPresenter Playlist (.proplaylist)** (`proplaylist`) — a ProPresenter service-order container, pro7-adjacent — import-only, no format-export.js exporter
- **PowerPoint (.pptx)** (`pptx`) — path/archive-based (a zip of slide XML) — no format-export.js exporter; test-import-format-coverage.php names the same exemption for its own fixture-parses-clean check
- **EasyWorship (.db)** (`easyworship`) — path/archive-based (a SQLite Songs.db) — no format-export.js exporter; test-import-format-coverage.php names the same exemption for its own fixture-parses-clean check

## Confirmed defects

Frozen in the expected dicts above with file:line evidence — NOT fixed by this harness; see `tests/php/test-interchange-roundtrip.php`'s header for the full write-up of each:

1. **VideoPsalm** — copyright/ccli/writers all silently dropped on our own round trip (export writes `Memo1`/`Memo2`/no author; import reads `Copyright`/`CCLI`/`Author`).
2. **ChordPro** — writers+composers folded into one `{artist:}` directive; import keeps the whole string as a single composer, writers comes back empty.
3. **Dead exporter inputs** — `song.alternateTitle`/`.key`/`.capo` (ChordPro) and `song.notes` (ProPresenter 7+ export) are never supplied by `SongData::getSongById()`'s real row shape.
4. **OpenSong tune** — `<tune>` is exported but the importer never reads it back.
5. **v2 editor export menu** — has no ChordPro item despite its own comment assuming one, and despite the public export menu offering it.
