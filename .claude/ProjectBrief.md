# 📋 iHymns — Project Brief

> **Claude Context File** — This file ensures continuity across development sessions.

---

## 🎯 What Is iHymns?

A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship.

- **Domain**: [iHymns.app](https://ihymns.app)
- **Copyright**: © 2026– MWBM Partners Ltd
- **License**: Proprietary (third-party components retain their own licenses)
- **GitHub Repo**: <https://github.com/MWBMPartners/iHymns>

---

## 📐 Two-Phase Approach

### Phase ONE (Current) — v1.x.x

- Songs sourced from local `.SourceSongData/` text files
- Parsed into structured JSON (`data/songs.json`)
- 5 songbooks, ~7,415 songs total
- Songbooks: Carol Praise (CP), Junior Praise (JP), Mission Praise (MP), SDA Hymnal (SDAH), The Church Hymnal (CH)
- Some songbooks include MIDI audio and PDF sheet music
- Includes a **Song Editor** (developer tool) for editing JSON data (structure, writers, CCLI numbers)

### Phase TWO (Future) — v2.x.x

- Songs sourced from iLyrics dB API (<https://github.com/MWBMPartners/iLyricsDB>)
- MySQL backend, Christian songs only
- Same frontend UI, different data source
- **Apple TV Remote Control**: iPhone/iPad controls tvOS lyrics display over LAN (Bonjour/mDNS)

---

## 🖥 Target Platforms (in delivery order)

1. **Web/Browser PWA** — HTML5, CSS3+, Bootstrap 5.3, Vanilla JS (ES2024+), Vite, Fuse.js
2. **Apple iOS/iPadOS/tvOS** — Native Swift 6.3 / SwiftUI, App Store + direct distribution, signed & notarised
3. **Android** — Kotlin / Jetpack Compose

---

## 🚀 Deployment & Versioning

### Web Directory Structure

- `appWeb/public_html/` — Production release (auto-synced from beta on main merge)
- `appWeb/public_html_beta/` — Beta release (primary development target)
- `appWeb/public_html_dev/` — Alpha/dev release (experimental)
- `appWeb/private_html/` — Private (admin tools, song editor)

### Automated Deployment (phpWhoIs pattern)

- GitHub Actions with `lftp` for SFTP mirroring
- Push to `beta` → deploy `public_html_beta/` to beta server
- Push to `main` → sync beta→production → deploy `public_html/` to live server
- Credentials via GitHub Secrets; `vars.SFTP_ENABLED` kill switch

### Version Numbering (Automated Semver)

- `v1.x.x` = Phase 1 (local JSON data)
- `v2.x.x` = Phase 2 (iLyrics dB integration)
- Auto-bumped via conventional commits on push to `beta`

---

## 📏 Development Standards

- **Detailed code annotations**: Comments on every code block (ideally every line)
- **Modular architecture**: Each feature in its own module/file
- **Human-readable formatting**: Proper indentation, line breaks, spacing
- **Automated copyright year**: `© 2026–<current year>` resolved at build time
- **Accessibility**: WCAG 2.1 AA compliant
- **Clean code**: All linting/security checks must pass with zero issues

---

## ✅ Standing Tasks (After Every Prompt)

1. Create GitHub Issue before work; close when done
2. Run syntax/lint/security checks; fix ALL issues
3. Ensure accessibility compliance
4. Apple: Swift 6.3/SwiftUI, App Store guidelines, signed & notarised
5. Build in auto-update checking
6. Update ALL documentation (README, CHANGELOG, PROJECT_STATUS, DEV_NOTES, help docs, GitHub Issues/Wiki, .claude/ memory)
7. Update .gitignore (VS Code, Xcode, macOS, Windows, Raspberry Pi)
8. COMMIT changes (do NOT push — user pushes manually)
9. Clean up temp files

---

## 📂 Song Data Format

Songs are in `.SourceSongData/<Songbook Name> [<Abbreviation>]/`

**Filename pattern**: `<number> (<abbrev>) - <Title>.txt`

- Some use zero-padded numbers (e.g., `0001` for MP, `001` for JP/CP)
- Some songbooks also have `_audio.mid` and `_music.pdf` companion files

**Text file format**:

- Line 1: Title in double quotes
- Blank line
- Verse number (standalone digit) or label ("Refrain", "Chorus")
- Lyrics lines
- Some files end with writer/composer credits

---

## 🗂 Project Structure

See `Project_Plan.md` for full directory tree. Key directories:

- `.claude/` — Claude context, memory, project brief
- `.github/workflows/` — CI/CD pipelines (deploy, version bump, changelog, tests)
- `.SourceSongData/` — Raw song text files (NEVER modify)
- `tools/` — Build tools & data parsers
- `data/` — Generated structured song data (JSON)
- `appWeb/` — Web PWA application (public_html, public_html_beta, public_html_dev, private_html)
- `apple/` — Native Apple app (Swift/SwiftUI)
- `android/` — Android app (future)
- `help/` — User documentation (Markdown)

---

## 📝 Documentation Requirements

- `README.md` — Project overview (includes plan summary)
- `Project_Plan.md` — Detailed project plan
- `PROJECT_STATUS.md` — Current status tracker
- `CHANGELOG.md` — Detailed change log (automated)
- `DEV_NOTES.md` — Developer notes
- `help/` — User-facing documentation (Markdown + in-app)
- `.claude/` — Claude memory, prompts, project brief

All markdown files must be well-formatted with emojis for readability.
All documentation must be updated after every change.

---

Last updated: 2026-04-05
