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
| 🌐 Web PWA | HTML5, CSS3, Bootstrap 5.3, Vanilla JS, Vite | 🔲 Not Started |
| 🍎 iOS / iPadOS / tvOS | Swift 6.3, SwiftUI | 🔲 Not Started |
| 🤖 Android | Kotlin, Jetpack Compose | 🔲 Not Started |

---

## ✨ Planned Features

- 🔍 **Full-text search** — by title, lyrics, songbook, song number, writer
- 📚 **Songbook browser** — organised by songbook with number index
- 📖 **Formatted lyrics** — clear verse/chorus/refrain display
- ⭐ **Favorites** — save songs for quick access
- 🎵 **Audio playback** — MIDI files where available
- 📄 **Sheet music** — PDF viewer where available
- 🌙 **Dark mode** — light/dark theme toggle
- 📴 **Offline mode** — PWA caches all data
- ♿ **Accessible** — WCAG 2.1 AA compliant
- 🔄 **Auto-update** — built-in update checking

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
│   ├── public_html/      #   Production release (auto-synced from beta)
│   ├── public_html_beta/ #   Beta release (primary dev target)
│   ├── public_html_dev/  #   Alpha/dev release
│   └── private_html/     #   Private (admin tools, song editor)
├── apple/                # Native Apple app (Swift/SwiftUI)
├── android/              # Android app (future)
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
