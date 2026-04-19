# 📖 iHymns

> **A multiplatform Christian lyrics application for worship enhancement**

[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-red.svg)](#-license)
[![Platform: Web](https://img.shields.io/badge/Platform-Web%20PWA-blue.svg)](#-platforms)
[![Platform: iOS](https://img.shields.io/badge/Platform-iOS%20%7C%20iPadOS%20%7C%20tvOS-black.svg)](#-platforms)
[![Platform: Android](https://img.shields.io/badge/Platform-Android-green.svg)](#-platforms)

---

## 🎯 About

**iHymns** provides searchable hymn and worship song lyrics from multiple songbooks, designed to enhance Christian worship across all devices. Browse, search, and save your favourite hymns — online or offline.

🌐 **Website**: [iHymns.app](https://ihymns.app)

---

## 📚 Song Library

| Songbook | Abbreviation | Songs | Audio | Sheet Music |
| --- | --- | --- | --- | --- |
| Carol Praise | CP | 714 | ✅ | ✅ |
| Junior Praise | JP | 1,787 | ✅ | ✅ |
| Mission Praise | MP | 3,517 | ✅ | ✅ |
| Seventh-day Adventist Hymnal | SDAH | 695 | — | — |
| The Church Hymnal | CH | 702 | — | — |
| **Total** | | **~7,415** | | |

---

## 🖥 Platforms

| Platform | Technology | Status |
| --- | --- | --- |
| 🌐 Web PWA | HTML5, CSS3, Bootstrap 5.3, Vanilla JS | ✅ Alpha |
| 🍎 iOS / iPadOS / tvOS | Swift 6.3, SwiftUI | 🔧 In Progress |
| 🤖 Android | Kotlin, Jetpack Compose | 🔲 Planned |

---

## ✨ Features

- 🔍 **Full-text search** — by title, lyrics, songbook, song number, writer, composer
- 📚 **Songbook browser** — organised by songbook with alphabetical index
- 📖 **Formatted lyrics** — verse, chorus, refrain, bridge with optional numbering
- ⭐ **Favourites** — save songs with custom tags for quick access
- 🎵 **Audio playback** — MIDI files where available
- 📄 **Sheet music** — PDF viewer where available
- 🌙 **Themes** — light, dark, high contrast, and system-adaptive modes
- 📴 **Offline mode** — download individual songbooks or all songs for offline use; bulk download completes in seconds via optimised API
- 🔢 **Number search** — numeric keypad with configurable live search (off by default) and default songbook
- 🔀 **Shuffle** — random song from any songbook, pre-selects your default songbook
- 🎤 **Presentation mode** — fullscreen lyrics display with auto-scroll
- 🌐 **Translation linking** — songs linked to translations in other languages
- 🔥 **Popular songs** — homepage shows trending songs (server-side) with client-side fallback
- 🏷️ **Browse by theme** — filter songs by thematic tags
- 📋 **Setlists** — create, arrange, and share worship setlists with custom component arrangements
- ♿ **Accessible** — WCAG 2.1 AA compliant, keyboard shortcuts, screen reader support, colour vision deficiency modes
- 🔄 **Auto-update** — service worker detects updates and prompts to refresh
- 🔑 **Magic-link sign-in** — primary auth path (email + 6-digit code); HttpOnly cross-subdomain cookie with 30-day sliding expiry survives iOS ITP
- 🎓 **Practice mode** — Full / Dimmed / Hidden cycle for memorising hymns with tap-to-reveal
- ✝️ **Scripture search** — `Ps 23`, `1 Cor 13`, `Rev 21` etc. match through abbreviation expansion + curated tags
- 📨 **Request a song** — public form plus admin triage queue
- 📊 **Admin analytics** — top songs, top songbooks, top/zero-result search queries, CSV export (admin+)
- 🧾 **Song revision history** — every save logged to `tblSongRevisions` for audit + future restore
- 🗝 **Entitlements** — capability-based permissions, editable at runtime by global admin
- 🚧 **Channel gating** — alpha / beta subdomains require the relevant access entitlement

---

## 🧑‍💼 Admin Portal

Accessible at **`/manage/`** (alias: `/admin/`) for users with the appropriate role. Main surfaces:

| Surface | Purpose | Default role |
| --- | --- | --- |
| Dashboard | Library + activity snapshot, quick-links | editor+ |
| Song Editor | Per-song UPSERT, multi-select bulk delete, auto-save, tag editor | editor+ |
| User Management | Roles, passwords, activation | admin+ |
| Song Requests | Triage user-submitted requests | editor+ |
| Analytics | Top songs/books/queries, CSV export | admin+ |
| Entitlements | Reassign capabilities to roles | global_admin |
| Database Setup | Install schema, migrate, backup, restore, drop legacy | admin+ |

Every write on these pages is CSRF-protected. DB error messages are never leaked to clients (see server error log).

---

## 🏗 Project Structure

```text
iHymns/
├── .claude/              # Claude AI context & project brief
├── .github/workflows/    # CI/CD: deploy, version bump, changelog, tests
├── .SourceSongData/      # Raw song text files (source of truth)
├── tools/                # Build tools & song data parser
├── data/                 # Generated song data (JSON)
├── appWeb/               # Web PWA application
│   ├── public_html/      #   Web app source (deployed to all environments)
│   ├── data_share/       #   Shared data (songs.json, setlists)
│   └── private_html/     #   Private (admin tools, song editor)
├── appApple/             # Native Apple app (Swift/SwiftUI)
├── appAndroid/           # Android app (future)
├── help/                 # User documentation
├── Project_Plan.md       # Detailed project plan
├── PROJECT_STATUS.md     # Current status tracker
├── CHANGELOG.md          # Change log
└── DEV_NOTES.md          # Developer notes
```

---

## 📋 Project Plan

See [Project_Plan.md](Project_Plan.md) for the full plan including:

- Technology stack details
- Architecture decisions
- Milestones and roadmap
- Development standards

---

## 📊 Current Status

See [PROJECT_STATUS.md](PROJECT_STATUS.md) for real-time project status.

**Current Phase**: Phase ONE (local song data)
**Current Milestone**: Project Setup & Data Pipeline
**Version**: 0.1.0-planning

---

## 🛠 Development

### Prerequisites

- **Node.js** v22+ (LTS)
- **npm** v10+
- **Xcode** 16+ (for Apple development)
- **VS Code** (recommended editor)

### Getting Started

```bash
# Clone the repository
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns

# Install dependencies (once project is set up)
npm install

# Parse song data
npm run parse-songs

# Start web dev server
npm run dev
```

---

## 📖 Documentation

- [Project Plan](Project_Plan.md) — Architecture, milestones, tech stack
- [Project Status](PROJECT_STATUS.md) — Current progress
- [Changelog](CHANGELOG.md) — All changes
- [Developer Notes](DEV_NOTES.md) — Technical decisions & notes
- [Help Documentation](help/) — User-facing docs & FAQs

---

## 📜 License

Copyright © 2026 MWBM Partners Ltd. All rights reserved.

This software is proprietary. Unauthorized copying, modification, or distribution is strictly prohibited.

Third-party components retain their respective licenses (MIT, Apache 2.0, etc.).

---

## 👥 Credits

- **MWBM Partners Ltd** — Development & maintenance
- Song data sourced from published hymnals and songbooks

---

Built with love for worship.
