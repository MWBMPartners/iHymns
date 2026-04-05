# 🛠 iHymns — Developer Notes

> Technical notes, decisions, and observations for contributors.

---

## 📂 Song Data Format

### File Naming Convention

Songs are stored in `.SourceSongData/<Songbook Name> [<Abbreviation>]/`

**Filename patterns vary by songbook:**

| Songbook | Pattern | Example |
|---|---|---|
| Church Hymnal (CH) | `NNN (CH) - Title.txt` | `003 (CH) - Come, Thou Almighty King.txt` |
| SDA Hymnal (SDAH) | `NNN (SDAH) - Title.txt` | `001 (SDAH) - Praise to the Lord.txt` |
| Mission Praise (MP) | `NNNN (MP) - Title.txt` | `0001 (MP) - A New Commandment.txt` |
| Junior Praise (JP) | `NNN (JP) - Title.txt` | `001 (JP) - A Boy Gave To Jesus.txt` |
| Carol Praise (CP) | `NNN (CP) - Title.txt` | `001 (CP) - A Baby Was Born In Bethlehem.txt` |

**Companion files** (MP, JP, CP only):
- `*_audio.mid` — MIDI audio file
- `*_music.pdf` — Sheet music PDF

### Text File Structure

```
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

Words and music by ...  ← Writer/composer credits (some files only)
© Copyright holder      ← Copyright info (some files only)
```

### Key Observations

1. **Title format**: Always in double quotes on line 1
2. **Verse numbering**: Standalone integer on its own line
3. **Chorus/Refrain**: Labelled as "Refrain", "Chorus", or similar
4. **Writer credits**: Present in MP songbook, absent in CH/SDAH
5. **Encoding**: UTF-8, some files contain special characters (curly quotes, em dashes)
6. **Song component order**: Components appear in the order they are sung
7. **No consistent blank line rules**: Some files have extra blank lines, parser must be tolerant

---

## 🏗 Architecture Decisions

### Why Vanilla JS (not React/Vue/Angular)?

- Simpler build pipeline
- Smaller bundle size (critical for PWA/offline)
- No framework lock-in
- Easy for contributors to understand
- Bootstrap handles responsive layout
- ES modules provide sufficient modularity

### Why Vite?

- Fast dev server with hot reload
- Efficient production builds
- Native ES module support
- Simple configuration

### Why JSON (not SQLite/IndexedDB) for Phase ONE?

- Simplicity — songs.json loaded once, searched in-memory
- Portable — same file used by web, Apple, Android
- ~7,400 songs ≈ ~10-15 MB JSON (acceptable for PWA cache)
- Fuse.js handles fuzzy search efficiently in-browser
- Phase TWO will move to proper database (iLyrics dB)

### Why `appWeb/` (not `web/`)?

- Consistent with original repo naming convention (`appWeb/`, `appAppleIOS/`)
- Clearer separation between app platforms in the directory tree

---

## 🚀 Deployment Architecture

### Web Directory Structure

| Directory | Purpose | Branch Trigger |
| --- | --- | --- |
| `appWeb/public_html/` | Production (auto-synced from beta) | Push to `main` |
| `appWeb/public_html_beta/` | Beta (primary dev target) | Push to `beta` |
| `appWeb/public_html_dev/` | Alpha/dev (experimental) | Push to `dev` |
| `appWeb/private_html/` | Private (admin tools, song editor) | Push to `main` |

### Deployment Flow (phpWhoIs pattern)

1. Development happens in `appWeb/public_html_beta/`
2. Push to `beta` → auto version bump → minify JS/CSS/HTML → SFTP deploy to beta server
3. Merge `beta` → `main` → rsync beta into `public_html/` → SFTP deploy to live server
4. Uses `lftp mirror --reverse --delete --only-newer` for efficient sync
5. GitHub Secrets for SFTP credentials; `vars.SFTP_ENABLED` as kill switch

### Version Numbering

- **Semver**: `v1.x.x` (Phase 1) / `v2.x.x` (Phase 2)
- Auto-bumped via conventional commits on `beta`:
  - `BREAKING CHANGE` or `!:` → major bump
  - `feat(...):` → minor bump
  - Everything else → patch bump
- Version stored in `appWeb/public_html_beta/includes/infoAppVer.js`
- Build metadata (SHA, date) injected at deploy time
- Git tags trigger GitHub Releases

---

## 🔧 Development Environment

### Recommended Setup

- **Editor**: VS Code or Xcode (for Apple development)
- **Node.js**: v22+ (LTS)
- **npm**: v10+
- **Xcode**: 16+ (for Swift 6.3)
- **OS**: macOS (required for Apple development), also supports Windows and Raspberry Pi

### IDE Extensions (VS Code)

- ESLint
- Prettier
- HTMLHint
- Stylelint
- Live Server (for local testing)

---

*Last updated: 2026-04-05*
