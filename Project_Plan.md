# 📖 iHymns — Project Plan

> ⚠️ **Architecture note (2026-06, epic #1010):** the JSON-file runtime described in Phase ONE below was replaced by DB-direct MySQL reads. `songs.json` is now a one-time migration input only. Sections marked *(historical)* describe the original plan.

> **A multiplatform Christian lyrics application for worship enhancement**
> Domain: [iHymns.app](https://ihymns.app)

---

## 📋 Table of Contents

- [Project Overview](#-project-overview)
- [Phase ONE — Local Song Data](#-phase-one--local-song-data)
- [Phase TWO — iLyrics dB Integration](#-phase-two--ilyrics-db-integration)
- [Technology Stack](#-technology-stack)
- [Architecture](#-architecture)
- [Song Data Format](#-song-data-format)
- [Platform Delivery Order](#-platform-delivery-order)
- [Feature Requirements](#-feature-requirements)
- [Third-Party Libraries & Components](#-third-party-libraries--components)
- [Project Structure](#-project-structure)
- [Development Standards](#-development-standards)
- [Standing Tasks (Automated)](#-standing-tasks-automated)
- [Milestones & Roadmap](#-milestones--roadmap)

---

## 🎯 Project Overview

**iHymns** is a Christian lyrics application that provides searchable hymn and worship song lyrics from multiple songbooks. The application supports multiple platforms (Web, iOS/iPadOS/tvOS, Android) and is designed in two phases:

- **Phase ONE**: Songs sourced from local `.SourceSongData/` files, parsed into a structured JSON data layer
- **Phase TWO**: Songs sourced from the [iLyrics dB](https://github.com/MWBMPartners/iLyricsDB) API (MySQL backend, Christian songs only)

### 📊 Current Song Library

| Songbook | Abbreviation | Song Count | Audio (MIDI) | Sheet Music (PDF) |
|---|---|---|---|---|
| Carol Praise | CP | 714 | ✅ | ✅ |
| Junior Praise | JP | 1,787 | ✅ | ✅ |
| Mission Praise | MP | 3,517 | ✅ | ✅ |
| Seventh-day Adventist Hymnal | SDAH | 695 | ❌ | ❌ |
| The Church Hymnal | CH | 702 | ❌ | ❌ |
| **Total** | | **~7,415** | | |

---

## 🔷 Phase ONE — Local Song Data

### Approach

1. **Parse** `.SourceSongData/` text files into structured JSON
2. **Build** a song data parser (Node.js script) that:
   - Reads each songbook folder
   - Extracts: title, song number, songbook, verses/stanzas, chorus/refrain, writer/composer
   - Outputs a unified `songs.json` data file
3. **Serve** the JSON to all platform apps
4. **Support** full-text search by: title, lyrics, songbook, song number, writer/composer

### Song Data Structure (JSON Schema)

```json
{
  "songbooks": [
    {
      "id": "CH",
      "name": "The Church Hymnal",
      "songCount": 702
    }
  ],
  "songs": [
    {
      "id": "CH-003",
      "number": 3,
      "title": "Come, Thou Almighty King",
      "songbook": "CH",
      "songbookName": "The Church Hymnal",
      "writers": ["Anonymous"],
      "composers": [],
      "hasAudio": false,
      "hasSheetMusic": false,
      "components": [
        {
          "type": "verse",
          "number": 1,
          "lines": [
            "Come, Thou almighty King,",
            "Help us Thy name to sing,"
          ]
        },
        {
          "type": "chorus",
          "number": null,
          "lines": [
            "Calling today, calling today;"
          ]
        }
      ]
    }
  ]
}
```

---

## 🔶 Phase TWO — iLyrics dB Integration

- Replace local JSON with API calls to iLyrics dB
- Filter for Christian songs only
- MySQL backend (managed by iLyrics dB repo)
- Same frontend UI, different data source
- API endpoint integration via REST

The **#1860 permanent internal-id (ILID) model** — every song/songbook/Work/musician/tune/publisher/
Collection/media row minting a grammar-disjoint permanent id and every relevant read path resolving
either address form — is the live groundwork for this merge: ids are minted now, resolvers are already
dual-addressed, and any cross-database (iLyricsDB-keyed) object stays gated behind the DB-merge decision
this phase represents (CLAUDE.md rule #20).

---

## 🛠 Technology Stack

### 🌐 Web Application (Progressive Web App)

| Component | Technology | Version |
|---|---|---|
| **Markup** | HTML5 | Latest |
| **Styling** | CSS3+ with Bootstrap | 5.3.x |
| **JavaScript** | Vanilla ES2024+ (modules) | Latest |
| **Icons** | Bootstrap Icons | 1.11.x |
| **Animations** | Animate.css + CSS transitions | 4.1.x |
| **Search** | Fuse.js (fuzzy search) | 7.x |
| **Build Tool** | Vite | 6.x |
| **PWA** | Service Worker + Web App Manifest | Latest |
| **Hosting** | Static deployment (Cloudflare Pages / Netlify) | — |

### 🍎 Apple (iOS / iPadOS / tvOS)

| Component | Technology | Version |
|---|---|---|
| **Language** | Swift | 6.3 |
| **UI Framework** | SwiftUI | Latest |
| **Minimum iOS** | iOS 17.0+ | — |
| **Minimum tvOS** | tvOS 17.0+ | — |
| **Data** | Bundled JSON + Swift Codable | — |
| **Search** | Built-in SwiftUI searchable | — |
| **Distribution** | App Store + Direct (signed & notarised) | — |
| **Update Check** | Custom update checker (GitHub Releases API) | — |

### 🤖 Android (Future — Phase ONE)

| Component | Technology | Version |
|---|---|---|
| **Language** | Kotlin | Latest |
| **UI Framework** | Jetpack Compose | Latest |
| **Minimum API** | API 26 (Android 8.0) | — |
| **Data** | Bundled JSON | — |
| **Distribution** | Google Play Store + APK | — |

### 🔧 Build & Data Tools

| Tool | Purpose |
|---|---|
| **Node.js** | Song data parser / build scripts |
| **npm** | Package management |
| **ESLint** | JavaScript linting |
| **Prettier** | Code formatting |
| **HTMLHint** | HTML validation |
| **Stylelint** | CSS linting |

---

## 🏗 Architecture

```text
iHymns/
├── .claude/                    # Claude context, memory, project brief
├── .github/                    # GitHub Actions CI/CD workflows
│   └── workflows/
│       ├── deploy.yml          # SFTP deployment; also classifies + bumps the tag-free version anchor on alpha (#1963/#1965)
│       ├── promotion-deploy-bridge.yml # Fires the deploy when a promotion PR merges to beta/main (#1007)
│       ├── changelog.yml       # Auto-generate changelog
│       ├── release.yml         # Dormant — manual-tag-only GitHub Releases (#1965)
│       └── test.yml            # Lint & validation checks (15 workflows total)
├── .SourceSongData/            # Raw song text files (source of truth)
│   ├── Carol Praise [CP]/
│   ├── Junior Praise [JP]/
│   ├── Mission Praise [MP]/
│   ├── Seventh-day Adventist Hymnal [SDAH]/
│   └── The Church Hymnal [CH]/
├── tools/                      # Build tools & data parsers
│   └── parse-songs.js          # Parses .SourceSongData → JSON
├── data/                       # Generated structured song data
│   └── songs.json              # Unified song database
├── appWeb/                     # Web PWA application
│   ├── public_html/            # 🟢 Web app source (all environments)
│   │   ├── index.php
│   │   ├── api.php             # Server-side API (songs, setlists)
│   │   ├── css/
│   │   ├── js/
│   │   │   ├── app.js          # Main application entry
│   │   │   ├── modules/
│   │   │   │   ├── search.js   # Search engine module
│   │   │   │   ├── songbook.js # Songbook navigation
│   │   │   │   ├── song-view.js# Song display/rendering
│   │   │   │   ├── favorites.js# User favorites (localStorage)
│   │   │   │   ├── setlist.js  # Setlist management & sharing
│   │   │   │   ├── settings.js # User preferences
│   │   │   │   ├── help.js     # In-app help system
│   │   │   │   └── updater.js  # Update checker
│   │   │   └── utils/
│   │   │       └── helpers.js  # Shared utility functions
│   │   ├── assets/             # Images, icons, fonts
│   │   ├── includes/
│   │   │   ├── infoAppVer.php  # Version metadata (auto-bumped)
│   │   │   └── config.php      # App configuration
│   │   ├── service-worker.js.php # PWA offline support
│   │   └── manifest.json       # PWA manifest
│   ├── data_share/             # 📦 Shared data (songs, setlists)
│   │   ├── song_data/          # songs.json (runtime copy) — (historical; runtime reads are now live MySQL, epic #1010)
│   │   └── setlist_json/       # Shared setlist JSON files
│   └── private_html/           # 🔒 PRIVATE (admin tools, song editor)
│       └── editor/             # Song editor tool
├── appApple/                   # Native Apple app (Swift/SwiftUI)
│   └── iHymns/
│       ├── iHymns.xcodeproj
│       ├── iHymns/
│       │   ├── iHymnsApp.swift
│       │   ├── Models/
│       │   ├── Views/
│       │   ├── ViewModels/
│       │   ├── Services/
│       │   ├── Resources/
│       │   └── Help/
│       └── iHymnsTests/
├── appAndroid/                 # Android app (Kotlin/Compose) — future
├── help/                       # Documentation (Markdown)
│   ├── README.md               # Help index
│   ├── getting-started.md
│   ├── searching-songs.md
│   ├── songbooks.md
│   ├── favorites.md
│   ├── troubleshooting.md
│   └── faq.md
├── .gitignore
├── README.md
├── Project_Plan.md
├── PROJECT_STATUS.md
├── CHANGELOG.md
├── DEV_NOTES.md
├── LICENSE
└── package.json                # Node.js project config (for build tools)
```

### Web Deployment Structure

| Directory | Purpose | Deployed To | Trigger |
| --- | --- | --- | --- |
| `appWeb/public_html/` | Web app source (all envs) | Remote `public_html/` | Push to `main` |
| `appWeb/public_html/` | Same source | Remote `public_html_beta/` | Push to `beta` |
| `appWeb/public_html/` | Same source | Remote `public_html_dev/` | Push to `alpha` |
| `appWeb/data_share/` | Shared data (songs, setlists) | Remote `data_share/` | All branches |
| `appWeb/private_html/` | Private (admin/editor) | Private server (SFTP) | Push to `main` |

**Deployment flow:**

1. All development happens in `appWeb/public_html/`
2. Push to `alpha` → SFTP uploads to remote `public_html_dev/`
3. Push to `beta` → auto version bump → minify → SFTP uploads to remote `public_html_beta/`
4. Push to `main` → SFTP uploads to remote `public_html/`
5. All branches deploy `data_share/` alongside (without `--delete` to preserve runtime data)
6. GitHub Actions with `lftp` for SFTP mirroring
7. Credentials via GitHub Secrets (`SFTP_HOST`, `SFTP_KEY`, etc.)
6. `vars.SFTP_ENABLED` kill switch for deployment

### Version Numbering (Tag-Free, #1963 → #1965 — supersedes the earlier tag-derived #1899 scheme)

- Version stored in `appWeb/public_html/includes/infoAppVer.php`; the **committed `MAJOR.MINOR`** (currently `1.1`) is the authoritative anchor — no git tags, no GitHub Releases:
  - **MAJOR.MINOR** — committed directly in `infoAppVer.php`. *Note:* the major digit **no longer encodes a data-source phase** — the old "`v1.x` = local-JSON phase, `v2.x` = iLyrics dB phase" scheme is dead, because reads went DB-direct with epic #1010 (there is no local-JSON phase to be in).
  - **BUILD** (the patch digit at deploy) — the monotonic per-commit git commit count (`git rev-list --count HEAD`), injected by `deploy.yml` on every deploy; `NULL` on an undeployed checkout.
- On `alpha`, a Conventional-Commit prefix on the squash-merge subject decides whether `deploy.yml` bumps `MAJOR.MINOR`: `feat:` → minor, `feat!:`/`fix!:`/any `!`/a line-anchored `BREAKING CHANGE:` → major, everything else → build-number-only. A bump is committed straight back to the file (`[skip ci]`, a normal branch push, never a tag).
- Build metadata (commit SHA, date, URL) injected at deploy time, same mechanism.
- `release.yml` is now **dormant** (fires only on a human-pushed `v*` tag or manual dispatch — nothing in the pipeline pushes one); the old `feat/`-driven minor auto-bump on push to `beta` (`version-bump.yml`) is retired, as is the earlier tag-derived `promotion-deploy-bridge.yml` tag-minting step it superseded.

### Modular Architecture Principles

1. **Separation of Concerns**: Data parsing, UI, and business logic are separate modules
2. **Platform Independence**: Song data (JSON) is shared across all platforms
3. **Single Source of Truth** *(historical — pre-#1010; MySQL is the current source of truth for reads/writes)*: `.SourceSongData/` text files → parsed to `data/songs.json`
4. **Progressive Enhancement**: Web app works without JS for basic content, enhanced with JS
5. **Offline First**: PWA caches songs for offline worship use

---

## 📱 Platform Delivery Order

1. **🌐 Web/Browser PWA** — First priority
   - Accessible at iHymns.app
   - Works on all devices with a modern browser
   - Installable as PWA (home screen icon)
   - Offline capable

2. **🍎 Apple iOS / iPadOS / tvOS** — Second priority
   - Native Swift 6.3 / SwiftUI
   - Universal app (iPhone, iPad, Apple TV)
   - App Store distribution (+ direct signed/notarised download)

3. **🤖 Android** — Third priority
   - Native Kotlin / Jetpack Compose
   - Google Play Store + direct APK download

---

## ✨ Feature Requirements

### Core Features (All Platforms)

- 🔍 **Search**: Full-text search by title, lyrics, songbook, song number, writer/composer
- 📚 **Songbook Browser**: Browse songs by songbook, with song number index
- 📖 **Song Viewer**: Display lyrics with clear verse/chorus formatting
- ⭐ **Favorites**: Save favorite songs for quick access
- 🎵 **Audio Playback**: Play MIDI files where available (MP, JP, CP)
- 📄 **Sheet Music**: View PDF sheet music where available
- 🌙 **Dark Mode**: Light/dark theme toggle
- ♿ **Accessibility**: WCAG 2.1 AA compliant, VoiceOver/TalkBack support
- 📱 **Responsive**: Adapts to all screen sizes
- 🔄 **Auto-Update**: Regularly check for updates (including when app is left open); auto-apply when possible
- ❓ **In-App Help**: Embedded help documentation

### Web-Specific Features

- 📲 **Smart Install Banner**: Shows PWA install prompt on web; once native Apple/Android apps are available, shows link to the relevant app store instead (platform-detected)
- 📴 **Offline Mode**: Service worker caches all song data
- 🔗 **Deep Links**: Direct URL to any song (e.g., `ihymns.app/song/CH-003`)
- 🖨 **Print-Friendly**: Print stylesheet for song lyrics

### 🛠 Song Editor (Phase 1 — Developer Tool)

A built-in song editor accessible to developers/administrators for editing the structured JSON song data:

- ✏️ **Edit song metadata**: Title, song number, songbook assignment
- 🎼 **Edit song structure/arrangement**: Add, remove, reorder verses, choruses, refrains, bridges, etc.
- 👤 **Edit writers/composers**: Add, edit, remove writer and composer credits
- 🔢 **CCLI Numbers**: Add/edit CCLI licence numbers for each song
- 📋 **Bulk operations**: Import/export capabilities
- ✅ **Validation**: Ensures data integrity before saving
- 💾 **Direct JSON editing** *(historical — the editor is MySQL-backed today, via `api2.php`)*: Edits the `data/songs.json` file (or per-songbook JSON files)
- 🔒 **Access controlled**: Developer/admin access only (not end-user facing)

### Apple-Specific Features

- 🎤 **Spotlight Search**: Songs searchable via iOS Spotlight
- 📺 **tvOS Support**: Large-screen optimised lyrics display for congregational use
- ⌚ **Widgets**: Quick access to recent/favorite songs
- 🔔 **Share Sheet**: Share song lyrics via iOS share

### Phase 2 — Additional Features

- 📺🎮 **Apple TV Remote Control**: Control the tvOS lyrics display remotely from the iPhone/iPad app when on the same LAN (via Bonjour/mDNS discovery + local WebSocket/Multipeer Connectivity)
- 🌐 **iLyrics dB API Integration**: Replace local JSON with live API data from iLyrics dB
- 🎵 **Christian Songs Filter**: Phase 2 data is limited to Christian/Worship songs only

---

## 📦 Third-Party Libraries & Components

### Web Application

| Library | Purpose | License |
|---|---|---|
| [Bootstrap 5.3](https://getbootstrap.com) | Responsive UI framework | MIT |
| [Bootstrap Icons](https://icons.getbootstrap.com) | Icon set | MIT |
| [Fuse.js](https://www.fusejs.io) | Fuzzy text search | Apache 2.0 |
| [Animate.css](https://animate.style) | CSS animations | Hippocratic 3.0 |
| [Vite](https://vitejs.dev) | Build tool / dev server | MIT |

### Apple Application

| Library | Purpose | License |
|---|---|---|
| SwiftUI (Apple) | Native UI framework | Apple SDK |
| Foundation (Apple) | Core utilities | Apple SDK |
| AVFoundation (Apple) | Audio playback | Apple SDK |

> **License Note**: All third-party libraries use permissive licenses compatible with proprietary distribution. The iHymns source code itself is proprietary (© MWBM Partners Ltd). Third-party component licenses are preserved and acknowledged.

---

## 📐 Development Standards

### Code Quality

- ✅ **Detailed comments/annotations** on every code block (and ideally every line)
- ✅ **Modular architecture** — each feature in its own module/file
- ✅ **Human-readable formatting** — proper indentation, line breaks, spacing
- ✅ **ESLint + Prettier** for JavaScript
- ✅ **HTMLHint** for HTML validation
- ✅ **Stylelint** for CSS
- ✅ **SwiftLint** for Swift code

### Copyright Header (All Source Files)

```
/**
 * iHymns — Christian Lyrics Application
 * 
 * Copyright © 2026–<current year> MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 * 
 * Third-party components retain their respective licenses.
 */
```

> The `<current year>` is dynamically resolved at build time (not hardcoded).

### Accessibility

- WCAG 2.1 Level AA compliance
- Semantic HTML5 elements
- ARIA labels where needed
- Keyboard navigation support
- Screen reader compatibility
- Sufficient colour contrast ratios

### Git & Version Control

- Commit after each task (no auto-push)
- Descriptive commit messages
- `.gitignore` maintained for VS Code, Xcode, macOS, Windows, Raspberry Pi
- GitHub Issues for task tracking
- CHANGELOG.md updated with every change

---

## ✅ Standing Tasks (Automated After Every Prompt)

1. 🎫 **GitHub Issues**: Create issue/sub-issue before work; close when done
2. 🔍 **Code Quality**: Run syntax, lint, security checks; fix all issues
3. ♿ **Accessibility**: Ensure compliance
4. 📱 **Apple Standards**: Swift 6.3/SwiftUI, App Store guidelines, signed & notarised
5. 🔄 **Auto-Update**: Built-in update checking
6. 📝 **Documentation**: Update README, CHANGELOG, PROJECT_STATUS, DEV_NOTES, help docs, GitHub Issues/Wiki, `.claude/` memory
7. 🙈 **Git**: Update .gitignore, commit changes (no push)
8. 🧹 **Cleanup**: Remove temp files after each prompt

---

## 🗓 Milestones & Roadmap

> **Live completion status is tracked in [PROJECT_STATUS.md](PROJECT_STATUS.md).** The Phase-ONE checklists below are the *original* scoping plan; in reality Milestones 1–3 and 6 (Web PWA core + enhanced, Song Editor) are complete and shipped, Apple (4–5) is Phase 1 + Phase 2 code-complete but unreleased, and Android (7) is a scaffold in progress. The app is at **v1.1.0** (tag-free, Conventional-Commit-driven scheme, #1963 → #1965).

### Milestone 1: Project Setup & Data Pipeline ✅
- [x] Analyse existing repo and song data
- [ ] Clean up old implementation files
- [ ] Set up new project structure
- [ ] Create song data parser
- [ ] Generate `songs.json` from source data
- [ ] Set up documentation framework

### Milestone 2: Web PWA — Core
- [ ] HTML5 layout with Bootstrap 5.3
- [ ] Song list / songbook browser
- [ ] Song detail view with formatted lyrics
- [ ] Search functionality (Fuse.js)
- [ ] Responsive design (mobile-first)

### Milestone 3: Web PWA — Enhanced
- [ ] Dark mode
- [ ] Favorites (localStorage)
- [ ] PWA manifest + Service Worker (offline)
- [ ] Audio playback (MIDI)
- [ ] Sheet music viewer (PDF)
- [ ] Print stylesheet
- [ ] Deep linking
- [ ] In-app help

### Milestone 4: Apple App — Core
- [ ] Xcode project setup (Swift 6.3 / SwiftUI)
- [ ] Song data model (Codable)
- [ ] Song list / songbook browser
- [ ] Song detail view
- [ ] Search
- [ ] Universal app (iPhone, iPad, Apple TV)

### Milestone 5: Apple App — Enhanced
- [ ] Dark mode
- [ ] Favorites
- [ ] Audio playback
- [ ] Spotlight integration
- [ ] Share sheet
- [ ] In-app help
- [ ] Auto-update checker
- [ ] App Store submission preparation
- [ ] Code signing & notarisation

### Milestone 6: Song Editor (Developer Tool)

- [ ] Song editor UI (web-based, developer/admin access)
- [ ] Edit song metadata (title, number, songbook)
- [ ] Edit song structure/arrangement (verses, chorus, refrain, bridge)
- [ ] Edit writers/composers
- [ ] CCLI number support
- [ ] JSON validation and save
- [ ] Bulk import/export

### Milestone 7: Android App

- [ ] Kotlin / Jetpack Compose project setup
- [ ] Full feature parity with Apple app

---

## 🔷 Phase TWO Milestones

### Milestone 8: iLyrics dB Integration

- [ ] API client for iLyrics dB REST endpoints
- [ ] Christian/Worship song filtering
- [ ] Migration from local JSON to API data source
- [ ] Offline cache with API sync
- [ ] Backward compatibility with Phase 1 data

### Milestone 9: Apple TV Remote Control

- [ ] Bonjour/mDNS service discovery on LAN
- [ ] WebSocket or Multipeer Connectivity protocol
- [ ] iPhone/iPad remote control UI (song selection, scroll, font size)
- [ ] tvOS receiver mode (display-only, controlled remotely)
- [ ] Multi-device pairing and session management

### Milestone 10: Phase TWO Platform Updates

- [ ] Update Web PWA for API data source
- [ ] Update Apple app for API + remote control
- [ ] Update Android app for API data source

---

*Last updated: 2026-08-28*
*Version: 0.1.0-planning (this planning document's own version; the app is at v1.1.0 — see PROJECT_STATUS.md)*
