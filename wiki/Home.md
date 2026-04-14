# iHymns Wiki

> A multiplatform Christian lyrics application for worship enhancement

**Website**: [iHymns.app](https://ihymns.app) | **Repo**: [GitHub](https://github.com/MWBMPartners/iHymns) | **Version**: 0.11.0 (Phase 1 pre-release, content tiers + CCLI)

---

## About iHymns

iHymns provides searchable hymn and worship song lyrics from multiple songbooks, designed to enhance Christian worship across all devices. Browse, search, and save your favourite hymns — online or offline.

---

## Song Library

| Songbook | Abbr. | Songs | MIDI Audio | Sheet Music |
|---|---|---|---|---|
| Carol Praise | CP | 243 | Yes | Yes |
| Junior Praise | JP | 617 | Yes | Yes |
| Mission Praise | MP | 1,355 | Yes | Yes |
| SDA Hymnal | SDAH | 695 | — | — |
| The Church Hymnal | CH | 702 | — | — |
| Miscellaneous | Misc | 0 | — | — |
| **Total** | | **3,612** | | |

---

## Platforms

| Platform | Technology | Status |
|---|---|---|
| Web PWA | PHP 8.5+, Bootstrap 5.3.6, Vanilla JS (ES modules), Fuse.js | Core + Enhanced complete |
| Apple (iOS/iPadOS/tvOS/visionOS/macOS/watchOS) | Swift 6.3, SwiftUI | Code complete |
| Android (+ Fire OS, Android TV) | Kotlin 2.1, Jetpack Compose | Code complete |

---

## Quick Links

### For Users
- [[Getting Started]]
- [[PWA Features]]
- [[User Accounts & Roles]]
- [[Setlists & Arrangements]]
- [[Troubleshooting & FAQ]]

### For Developers
- [[Architecture]]
- [[Development Setup]]
- [[API Reference]]
- [[Song Data Format]]
- [[Deployment & CI-CD]]
- [[Native Apps (Apple & Android)]]
- [[Database & Migrations]]
- [[Security]]

---

## Two-Phase Approach

### Phase ONE (Current) — v0.x.x / v1.x.x

- Songs sourced from local `.SourceSongData/` text files
- Parsed into structured JSON (`data/songs.json`) — single canonical copy
- 6 songbooks, 3,612 songs across CP, JP, MP, SDAH, CH, Misc
- Some songbooks include MIDI audio and PDF sheet music
- Song Editor (admin tool) in `/manage/editor/`

### Phase TWO (Future) — v2.x.x

- Songs sourced from iLyrics dB API
- MySQL backend, Christian songs only
- Same frontend UI, different data source
- Apple TV Remote Control: iPhone/iPad controls tvOS lyrics display over LAN

---

## Version Numbering

| Range | Meaning |
|---|---|
| `v0.x.x` | Phase 1 pre-release (current) |
| `v1.x.x` | Phase 1 stable |
| `v2.x.x` | Phase 2 (iLyrics dB integration) |

Auto-bumped via conventional commits on push to `beta` (single source of truth). Alpha builds display commit date timestamp in footer.

---

## Copyright

Copyright 2026 MWBM Partners Ltd. All rights reserved.

Proprietary software. Third-party components retain their respective licenses.
