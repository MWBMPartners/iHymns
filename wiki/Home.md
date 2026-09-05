# iHymns Wiki

> A multiplatform Christian lyrics application for worship enhancement

**Website**: [iHymns.app](https://ihymns.app) | **Repo**: [GitHub](https://github.com/MWBMPartners/iHymns) | **Version**: see the app footer or the in-app What's New page

---

## About iHymns

iHymns provides searchable hymn and worship song lyrics from multiple songbooks, designed to enhance Christian worship across all devices. Browse, search, and save your favourite hymns — online or offline.

---

## Song Library

~14,000 songs across 30+ songbooks in ~20 languages, served live from the database — browse the current, always-accurate list at [/songbooks](https://ihymns.app/songbooks).

---

## Platforms

| Platform | Technology | Status |
|---|---|---|
| Web PWA | PHP 8.5+, Bootstrap 5.3.6, Vanilla JS (ES modules), Fuse.js | Core + Enhanced complete |
| Apple (iOS/iPadOS/tvOS/visionOS/macOS/watchOS) | Swift 6.3, SwiftUI | Phase 1+2 code-complete, unreleased |
| Android (+ Fire OS, Android TV) | Kotlin 2.1, Jetpack Compose | Scaffold / in progress |

---

## Quick Links

### For Users
- [[Getting Started]]
- [[PWA Features]]
- [[User Accounts & Roles]]
- [[Setlists & Arrangements]]
- [[Live Follow & Service Mode]]
- [[Troubleshooting & FAQ]]

### For Developers
- [[Architecture]]
- [[Development Setup]]
- [[API Reference]]
- [[Song Data Format]]
- [[Import & Export Fidelity]]
- [[Deployment & CI-CD]]
- [[Native Apps (Apple & Android)]]
- [[Database & Migrations]]
- [[Security]]

---

## Two-Phase Approach

> This section describes the **original** project plan (historical). See [[Architecture]] for the current data flow and the Song Library section above for the current catalogue size — the DB-direct rewrite (epic #1010) has since made every runtime read live MySQL, well past the scope described as "Phase ONE" below.

### Phase ONE (original scope) — v0.x.x / v1.x.x

- Songs sourced from local `.SourceSongData/` text files
- Parsed into structured JSON (`data/songs.json`) — one-time migration input; runtime reads are live MySQL (#1010)
- Originally 6 songbooks, 3,612 songs across CP, JP, MP, SDAH, CH, Misc — the catalogue has since grown substantially (see Song Library above)
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
| `v1.x.x` | Phase 1 stable (current) |
| `v2.x.x` | Phase 2 (iLyrics dB integration) |

Versioning is **tag-free** (#1963 → #1965). The authoritative `MAJOR.MINOR` is committed in `includes/infoAppVer.php`; `deploy.yml` classifies the Conventional-Commit prefixes on each alpha push (`feat:` → minor bump, `feat!:`/`BREAKING CHANGE:` → major bump, everything else → build-only) and, on a clear signal, commits the new `MAJOR.MINOR` back to the branch — never a git tag. The displayed **build number** is `git rev-list --count HEAD`, shown alongside the version in Settings → About. See [[Development Setup]] § Versioning and [[Deployment & CI-CD]] for the full pipeline.

---

## Copyright

Copyright 2026 MWBM Partners Ltd. All rights reserved.

Proprietary software. Third-party components retain their respective licenses.
