---
name: iHymns Project Context
description: iHymns is a multiplatform Christian lyrics app (Web PWA, Apple, Android) with ~7,415 songs from 5 songbooks. Phase ONE uses local JSON, Phase TWO uses iLyrics dB API.
type: project
---

**iHymns** — Multiplatform Christian lyrics application for worship.

**Domain**: iHymns.app | **Repo**: https://github.com/MWBMPartners/iHymns
**Copyright**: MWBM Partners Ltd | **License**: Proprietary

**Why:** Enhance worship by providing searchable hymn lyrics across platforms.

**How to apply:**
- Phase ONE (v1.x.x): Parse `.SourceSongData/` text files → JSON, serve to all platforms
- Phase TWO (v2.x.x): Switch to iLyrics dB API (https://github.com/MWBMPartners/iLyricsDB)
- Platform order: Web PWA → Apple (Swift 6.3/SwiftUI) → Android (Kotlin/Compose)
- 5 songbooks: CP (714), JP (1787), MP (3517), SDAH (695), CH (702) = ~7,415 songs
- MP, JP, CP songbooks have companion MIDI audio and PDF sheet music files
- `.SourceSongData/` must NEVER be deleted or modified — it is the source of truth
- Web directory uses `appWeb/` (not `web/`) with public_html/, public_html_beta/, public_html_dev/, private_html/
- Deployment via GitHub Actions + lftp SFTP mirroring (phpWhoIs pattern)
- Song editor (dev tool) lives in `appWeb/private_html/editor/`
- Phase 2 includes Apple TV remote control via Bonjour/LAN
