---
name: iHymns Project Context
description: iHymns is a multiplatform Christian lyrics app. Phase 1 (v0.x.x) uses local JSON with 6 songbooks and 3,612 songs. Phase 2 (v2.x.x) will use iLyrics dB API.
type: project
---

**iHymns** — Multiplatform Christian lyrics application for worship.

**Domain**: iHymns.app | **Repo**: https://github.com/MWBMPartners/iHymns
**Copyright**: MWBM Partners Ltd | **License**: Proprietary
**Current version**: 0.1.0 (pre-release, Phase 1)

**Why:** Enhance worship by providing searchable hymn lyrics across platforms.

**How to apply:**
- Phase ONE (v0.x.x): Parse `.SourceSongData/` text files → JSON, serve to all platforms
- Phase TWO (v2.x.x): Switch to iLyrics dB API (https://github.com/MWBMPartners/iLyricsDB)
- Platform order: Web PWA → Apple (Swift 6.3/SwiftUI) → Android (Kotlin/Compose)
- 6 songbooks: CP (243), JP (617), MP (1355), SDAH (695), CH (702), Misc (0) = 3,612 songs
- `.SourceSongData/` must NEVER be deleted or modified — it is the source of truth
- Directories: `appWeb/`, `appApple/`, `appAndroid/` (consistent `app<Platform>/` prefix)
- Web: PHP 8.5+ on shared hosting, modular PHP components, Bootstrap 5.3
- Deployment: GitHub Actions + lftp SFTP (main→live, beta→beta, alpha→dev)
- Working branch: `beta` (merge to `main` for production release)
- Song editor (dev tool) in `appWeb/private_html/editor/` (HTTP Basic Auth protected)
- Colour scheme: clean neutral slate/grey, NOT bright colours
- Application IDs: Ltd.MWBMPartners.iHymns.PWA / .Apple / .Android
- Phase 1 is a first iteration — don't over-engineer file-based data distribution
