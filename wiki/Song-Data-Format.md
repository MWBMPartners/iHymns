# Song Data Format

> Source song files, parsed JSON structure, and component types

---

## Source Files

Songs are stored as plain text files in `.SourceSongData/`, organised by songbook:

```
.SourceSongData/
├── Carol Praise [CP]/
│   ├── 001 (CP) - A Baby Was Born In Bethlehem.txt
│   ├── 001 (CP) - A Baby Was Born In Bethlehem_audio.mid
│   └── 001 (CP) - A Baby Was Born In Bethlehem_music.pdf
├── Junior Praise [JP]/
├── Mission Praise [MP]/
├── SDA Hymnal [SDAH]/
└── The Church Hymnal [CH]/
```

> **WARNING:** The `.SourceSongData/` directory must NEVER be deleted or modified manually. These
> files are the **historical one-time import source**, kept for provenance — the record of where the
> catalogue originally came from.
>
> **They are not the source of truth.** Since the DB-direct rewrite (epic #1010) MySQL is the only
> source of truth for song data at runtime; nothing in the running app reads these files, and new
> content goes in through the Song Editor's bulk importers. The directory is also **gitignored**, so
> it is not in the repo and a fresh clone will not have it.
>
> **Corrected 2026-09-08.** This warning used to call the directory "the source of truth for all song
> data" — contradicting this same page further down, where it correctly says the database is
> canonical at runtime. The do-not-delete instruction stands; it is still archival material.

### File Naming

| Songbook | Pattern | Example |
|---|---|---|
| Carol Praise (CP) | `NNN (CP) - Title.txt` | `001 (CP) - A Baby Was Born In Bethlehem.txt` |
| Junior Praise (JP) | `NNN (JP) - Title.txt` | `001 (JP) - A Boy Gave To Jesus.txt` |
| Mission Praise (MP) | `NNNN (MP) - Title.txt` | `0001 (MP) - A New Commandment.txt` |
| SDA Hymnal (SDAH) | `NNN (SDAH) - Title.txt` | `001 (SDAH) - Praise to the Lord.txt` |
| Church Hymnal (CH) | `NNN (CH) - Title.txt` | `003 (CH) - Come, Thou Almighty King.txt` |

**Companion files** (CP, JP, MP only):
- `*_audio.mid` — MIDI audio file
- `*_music.pdf` — Sheet music PDF

### Text File Structure

```text
"Song Title"            ← Line 1: Title in double quotes

1                       ← Verse number (standalone digit)
First line of verse,
Second line of verse,
...

Refrain                 ← Or "Chorus" — label on its own line
First line of refrain,
...

2                       ← Next verse
...

Writers: Name1, Name2   ← "Writers:" prefix (lyricists)
Composers: Name3        ← "Composers:" prefix (music)
CCLI: 12345             ← Optional CCLI number
Language: fr-FR         ← Optional IETF BCP 47 language tag (defaults to songbook language)
```

---

## Parsed JSON (interchange shape)

The parser (`tools/parse-songs.js`) converts source files into structured JSON matching the interchange shape below. Its output now lands only at a gitignored `tmp/songs.json` local build artefact — **the database is canonical at runtime** (every read is live MySQL; see [[Architecture]]), and the one-time bootstrap script that used to consume a *tracked* `data/songs.json` as a seed, `appWeb/.sql/migrate-json.php`, was retired in #1614 (it was ~4x stale against the live catalogue by the time it went). New song content goes in through the Song Editor's bulk importers or a real backup restore — see [[Database & Migrations]] § Data Migration.

### Song Object

```json
{
  "id": "MP-0001",
  "number": 1,
  "title": "A New Commandment",
  "songbook": "MP",
  "songbookName": "Mission Praise",
  "writers": ["Author Name"],
  "composers": ["Composer Name"],
  "ccli": "12345",
  "hasAudio": true,
  "hasSheetMusic": true,
  "components": [
    {
      "type": "verse",
      "number": 1,
      "lines": [
        "First line of the verse,",
        "Second line of the verse."
      ]
    },
    {
      "type": "chorus",
      "number": null,
      "lines": [
        "This is the chorus line."
      ]
    }
  ]
}
```

### JSON Schema

The structure is validated against `tests/fixtures/songs.schema.json` (JSON Schema draft 2020-12; moved out of `data/` in #1617). Any changes to the song format must update the schema.

---

## Component Types

Songs are divided into components, each with a `type` field. The 11 primary types:

| Type | Short Tag | Colour | Label |
|---|---|---|---|
| `verse` | V | `#3b82f6` (blue) | Verse |
| `chorus` | C | `#f59e0b` (amber) | Chorus |
| `pre-chorus` | PC | `#ec4899` (pink) | Pre-Chorus |
| `bridge` | B | `#8b5cf6` (purple) | Bridge |
| `tag` | T | `#6b7280` (grey) | Tag |
| `coda` | CD | `#6b7280` (grey) | Coda |
| `intro` | I | `#10b981` (green) | Intro |
| `outro` | O | `#ef4444` (red) | Outro |
| `interlude` | IL | `#06b6d4` (cyan) | Interlude |
| `vamp` | VP | `#f97316` (orange) | Vamp |
| `ad-lib` | AL | `#84cc16` (lime) | Ad-lib |

> **Alias:** `refrain` is accepted as an alias for `chorus` (for import compatibility).
> Data using `"type": "refrain"` is valid and displays as "Chorus" in the UI.

### Custom labels & medley provenance (#1907, #1860 Phase 5)

Two optional, additive per-section fields ride the same component metadata (never the lyric-line content itself):

- **`Label`** — an optional custom **display** name for a section (e.g. "Kyrie", "isiZulu") shown instead of the derived "Verse 1 / Chorus". It is **display-only**: `type` stays authoritative for CSS/chorus-highlighting, arrangement resolution, and every machine-export keyword (OpenLyrics `<verse name>`, OpenSong `[V1]`, ProPresenter/VideoPsalm/Proclaim round-trip their `type` back on re-import) — a label is never written into an export.
- **`SourceWorkId`** — links a section to the Work it excerpts, for medleys stitched together from more than one original composition. Setting it records the medley's composition (which Works make up the song, in order) so the song page and a Work's own page can show "Medley of: A, B, C".

### Short Tags

Short tags use industry-standard abbreviations inspired by ProPresenter 7. Numbered variants are supported: `V1`, `V2`, `C1`, `PC1`, etc.

The tag utility is defined in `appWeb/public_html/js/utils/components.js` and shared between the PWA and editor.

---

## Song ID Format

Song IDs follow the pattern `<ABBR>-<NNNN>`:

| Component | Description | Example |
|---|---|---|
| Abbreviation | Songbook abbreviation (uppercase) | `MP`, `CP`, `JP`, `SDAH`, `CH` |
| Number | Zero-padded song number | `0001`, `0042`, `0695` |
| Full ID | Combined | `MP-0001`, `CP-0042`, `SDAH-0695` |

The router supports flexible input: `MP-1` is normalised to `MP-0001`.

---

## Editor Import & Export Formats

Beyond the historical `.SourceSongData/` → MySQL parser pipeline above, the Song Editor's bulk-import/export tooling reads and writes several projection-software formats — and is now the supported way to bring new song content into a live install (see [[Database & Migrations]] § Data Migration). The two sets are **not** the same — a few formats can only be read in, not written out.

**Export (8 formats)** — the list in `includes/partials/export-menu.php`, offered publicly on any
song or songbook page (see [[PWA Features]] § Export & Present): OpenSong, OpenLyrics/OpenLP,
ProPresenter 6, ProPresenter 7+, VideoPsalm, FreeShow, Proclaim, ChordPro.

**Import** — those same eight, plus EasyWorship (`.db`), PowerPoint (`.pptx`), ProPresenter bundles
and playlists (`.probundle` / `.proplaylist`), and iHymns' own interchange JSON.
[[Import & Export Fidelity]]'s "Not closure-tested here" section is the authoritative list of the
import-only ones and says, for each, why it has no exporter.

> **Corrected 2026-09-08.** This paragraph said "Export: the same set (8 formats)", which is not
> true: EasyWorship and PowerPoint are import-only, and OpenSong — a real importer *and* exporter —
> was missing from the import list altogether.

**ProPresenter 7+ (epic #1968)** gets the most detail here because its wire format has a real gotcha for anyone building against it: PP7 does **not** store chords as inline `[G]`-style brackets in the slide text — that's only ProPresenter's own editing metaphor. A chord is a positioned protobuf attribute (a UTF-16 code-unit range + a chord string) layered over otherwise-clean plain lyric text. iHymns' own per-line `chords` cells are already positioned the same way, so the import/export mapping is direct — no inline-bracket parsing on either side. Decoding is a hand-rolled, independently-cross-validated proto3 wire-walker (`includes/propresenter7_decode.php`), never a self-consistent round-trip against iHymns' own exporter alone. See [[Architecture]] § ProPresenter interop and [[Database & Migrations]] for the fuller picture (media ingest, the dormant presentation-timeline schema).

---

## Parser

Run the parser to regenerate its local-only build artefact from source files:

```bash
npm run parse-songs
# or
node tools/parse-songs.js
```

The parser:
1. Scans `.SourceSongData/` subdirectories
2. Parses each `.txt` file for title, components, writers, composers
3. Detects companion `_audio.mid` and `_music.pdf` files
4. Outputs structured JSON to a gitignored `tmp/songs.json` (nothing commits it, and nothing in the running app reads it)
5. Reports statistics (song count, songbook breakdown)

**25 unit tests** validate the interchange format's *shape* — against a small synthetic fixture, not the real catalogue — in `tests/test-song-fixture-shape.js` (renamed from `test-song-parser.js` when the real `data/songs.json` corpus it used to check against was retired, #1617).
