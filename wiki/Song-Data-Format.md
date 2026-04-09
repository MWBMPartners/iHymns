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

> **WARNING:** The `.SourceSongData/` directory must NEVER be deleted or modified manually. It is the source of truth for all song data.

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
```

---

## Parsed JSON (`data/songs.json`)

The parser (`tools/parse-songs.js`) converts source files into structured JSON. The output `data/songs.json` is the single canonical copy used by all platforms.

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

The structure is validated against `data/songs.schema.json` (JSON Schema draft 2020-12). Any changes to the song format must update the schema.

---

## Component Types

Songs are divided into components, each with a `type` field. The 12 supported types:

| Type | Short Tag | Colour | Label |
|---|---|---|---|
| `verse` | V | `#3b82f6` (blue) | Verse |
| `chorus` | C | `#f59e0b` (amber) | Chorus |
| `refrain` | R | `#f59e0b` (amber) | Refrain |
| `pre-chorus` | PC | `#ec4899` (pink) | Pre-Chorus |
| `bridge` | B | `#8b5cf6` (purple) | Bridge |
| `tag` | T | `#6b7280` (grey) | Tag |
| `coda` | CD | `#6b7280` (grey) | Coda |
| `intro` | I | `#10b981` (green) | Intro |
| `outro` | O | `#ef4444` (red) | Outro |
| `interlude` | IL | `#06b6d4` (cyan) | Interlude |
| `vamp` | VP | `#f97316` (orange) | Vamp |
| `ad-lib` | AL | `#84cc16` (lime) | Ad-lib |

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

## Parser

Run the parser to regenerate `data/songs.json` from source files:

```bash
npm run parse-songs
# or
node tools/parse-songs.js
```

The parser:
1. Scans `.SourceSongData/` subdirectories
2. Parses each `.txt` file for title, components, writers, composers
3. Detects companion `_audio.mid` and `_music.pdf` files
4. Outputs structured JSON to `data/songs.json`
5. Reports statistics (song count, songbook breakdown)

**33 unit tests** validate the parser in `tests/test-song-parser.js`.
