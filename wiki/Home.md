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
| Web PWA | PHP 8.4 / 8.5, MySQL, Bootstrap 5.3.6, vanilla JS (ES modules) | Core + Enhanced complete |
| Apple (iOS/iPadOS/tvOS/visionOS/macOS/watchOS) | Swift 6 language mode, SwiftUI | Phase 1+2 code-complete, unreleased |
| Android (+ Fire OS, Android TV) | Kotlin 2.4, Jetpack Compose | Scaffold / in progress |

> **Corrected 2026-09-08.** Three things in this table were wrong. **Fuse.js** was listed as part of
> the web stack — it and the whole browser-side song corpus were removed in WS-J #1020, and search
> has been a live MySQL full-text query ever since. **PHP 8.5+** overstates the floor: CI runs the
> full PHP suite on 8.4 *and* 8.5, so 8.4 is proven and turning it away would block a perfectly good
> host. And the language versions were both stale — nothing in the repo mentions Swift 6.3
> (`Shared.xcconfig` sets the language mode to 6.0 and the shared package declares tools version
> 6.2), and the Kotlin plugins are pinned at 2.4.10, not 2.1.

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

Versioning is **tag-free** (#1963 → #1965). The authoritative `MAJOR.MINOR.PATCH` is committed in
`includes/infoAppVer.php`; `deploy.yml` classifies the Conventional-Commit prefixes on each alpha
push and, on a clear signal, commits the new version back to the branch — never a git tag. There are
three levels that move it and one that does not:

| Signal on the squash-merge | Effect |
|---|---|
| `feat:` | Minor bump (and the patch digit resets to 0) |
| `feat!:` / `fix!:` / any `!` / a line-anchored `BREAKING CHANGE:` | Major bump |
| A body line reading exactly `Release: patch` (case-insensitive, whole line) | Patch bump — the third digit only, nothing else moves. This is the deliberate "this is a bug-fix release" signal, which matters for the app stores even though the web ships continuously |
| Everything else — `fix`, `chore`, `docs`, `refactor`, `perf`, `ci`, or an unlabelled subject with no `Release: patch` footer | Build-only: the visible version does not move at all, just the build number |

The **build number** is a separate field, `git rev-list --count HEAD`, injected on every deploy and
shown as its own row in Settings → About. It never enters the version's patch digit. See
[[Development Setup]] § Versioning and [[Deployment & CI-CD]] for the full pipeline.

> **Corrected 2026-09-08.** This paragraph described the committed anchor as `MAJOR.MINOR` and said
> "everything else → build-only", which hid a real mechanism: since the marketing-version /
> build-number split the anchor is a full three-part version, and a deliberate patch release can be
> asked for with a `Release: patch` footer (implemented as `re_patch` in
> `.github/workflows/scripts/classify-bump.sh`). Somebody following the old wording would not have
> known a patch release was possible, let alone how to request one.

---

## Copyright

Copyright 2026 MWBM Partners Ltd. All rights reserved.

Proprietary software. Third-party components retain their respective licenses.
