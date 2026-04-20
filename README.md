# iHymns

> **A multiplatform Christian lyrics application for worship enhancement**

[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-red.svg)](#-license)
[![Platform: Web](https://img.shields.io/badge/Platform-Web%20PWA-blue.svg)](#-platforms)
[![Platform: iOS](https://img.shields.io/badge/Platform-iOS%20%7C%20iPadOS%20%7C%20tvOS-black.svg)](#-platforms)
[![Platform: Android](https://img.shields.io/badge/Platform-Android-green.svg)](#-platforms)

---

## About

**iHymns** provides searchable hymn and worship song lyrics from multiple songbooks, designed to enhance Christian worship across all devices. Browse, search, and save your favourite hymns — online or offline.

**Website**: [iHymns.app](https://ihymns.app) | **Alpha**: [dev.iHymns.app](https://dev.ihymns.app) | **Beta**: [beta.iHymns.app](https://beta.ihymns.app)

---

## Song Library

| Songbook | Abbr | Songs | Audio | Sheet Music |
| --- | --- | ---: | :---: | :---: |
| Carol Praise | CP | 243 | MIDI | PDF |
| Junior Praise | JP | 617 | MIDI | PDF |
| Mission Praise | MP | 1,355 | MIDI | PDF |
| Seventh-day Adventist Hymnal | SDAH | 695 | — | — |
| The Church Hymnal | CH | 702 | — | — |
| **Total** | | **3,612** | | |

---

## Platforms

| Platform | Technology | Status |
| --- | --- | --- |
| Web PWA | HTML5, CSS3, Bootstrap 5.3, Vanilla JS, PHP 8.1+ | **Alpha** |
| iOS / iPadOS / tvOS | Swift 6.3, SwiftUI | In Progress |
| Android / Fire OS | Kotlin, Jetpack Compose | Planned |

---

## Features

### Song Browsing & Search
- **Full-text search** — by title, lyrics, songbook, song number, writer, composer (Fuse.js client-side + MySQL FULLTEXT)
- **Scripture search** — `Ps 23`, `1 Cor 13`, `Rev 21` etc. match through abbreviation expansion + curated tags (#397)
- **Songbook browser** — organised by songbook with alphabetical index
- **Number search** — numeric keypad with physical keyboard support; configurable live search (off by default)
- **Default songbook** — pre-selects in number search, keyboard quick-jump, and shuffle
- **Formatted lyrics** — verse, chorus, refrain, bridge with optional numbering and chorus highlighting

### Worship Tools
- **Favourites** — save songs with custom tags for quick access
- **Setlists** — create, arrange, and share worship setlists with custom component arrangements
- **Setlist scheduling & collaboration** — schedule setlists for a date/time with an "Up next" overview and invite collaborators with view/edit permissions (#398)
- **Presentation mode** — fullscreen lyrics display with configurable auto-scroll
- **Practice / memorisation mode** — Full / Dimmed / Hidden cycle with tap-to-reveal (#402)
- **Shuffle** — random song from any songbook, highlights your default songbook
- **Translation linking** — songs linked to equivalent translations in other languages
- **Audio playback** — MIDI files where available
- **Sheet music** — PDF viewer where available
- **Transpose** — shift song key up/down (persisted per song)

### Discovery
- **Popular songs** — homepage shows trending songs (server-side view counts with client-side fallback)
- **Browse by theme** — filter songs by thematic tags
- **Related songs** — content-based similarity matching using TF-IDF cosine similarity
- **Song of the Day** — daily featured song on homepage
- **Recently viewed** — quick access to your recent songs

### Offline & PWA
- **Offline downloads** — individual songbooks or all at once (~14 MB)
- **Bulk download API** — optimised endpoint fetches entire songbooks in ~6 requests (not 3,612 individual requests)
- **Offline audio** — opt-in pre-cache of MIDI/OGG so playback works without a connection (#401)
- **Per-songbook size readout + eviction** — Settings shows actual cached bytes per songbook with a remove-from-offline button (#401)
- **Background downloads** — continue when navigating away from Settings
- **Auto-update** — optional automatic update of saved offline songs; service-worker update toast restored (#396)
- **Service worker** — precaches all app assets for instant offline access
- **Offline indicator** — shows connection status in UI
- **JSON fallback** — full functionality when MySQL is unavailable

### Appearance & Accessibility
- **Themes** — light, dark, high contrast, and system-adaptive modes
- **Colour vision deficiency** — accessible palette with pattern-based songbook indicators
- **WCAG 2.1 AA** — screen reader support, ARIA landmarks, keyboard shortcuts (toggleable via Accessibility settings, #406)
- **Responsive songbook names** — full name by default, abbreviation on narrow screens
- **Adjustable font size** — lyrics scale from 14px to 28px
- **Reduced motion** — respects `prefers-reduced-motion` and manual toggle
- **Safe areas** — respects device notch, camera cutout, and home indicator on all screens

### Authentication & Access
- **Magic-link sign-in** — primary auth path (email + 6-digit code); password sign-in available as a fallback (#395)
- **Cross-subdomain cookie** — `HttpOnly`, `SameSite=Lax`, `Secure` auth cookie on `.ihymns.app` with 30-day sliding expiry survives iOS ITP (#390)
- **User accounts** — role-based access (Global Admin, Admin, Editor, User)
- **Entitlements** — capability-based permissions, editable at runtime by a global admin
- **Channel gating** — alpha / beta subdomains require the relevant access entitlement

### Community
- **Song request form** — public, rate-limited, honeypot-protected (#403); admin triage queue at `/manage/requests`

### Administration
- **Song editor** — per-song auto-save, multi-select bulk **delete / verify / tag / move / export** (#399)
- **Revision history** — every save writes `tblSongRevisions`; editor History modal with JSON diff + per-revision Restore + global audit log at `/manage/revisions` (#400)
- **Database setup** — web-accessible installer with backup restore upload, **pre-flight summary**, pre-restore auto-snapshot, and transactional data-load (#405)
- **Content access tiers** — public, free, CCLI, premium, pro with organisation licensing
- **Activity logging** — audit trail for significant actions (logins, admin writes, backup restores)
- **Analytics** — GA4, Plausible, Clarity, Matomo, Fathom with GDPR consent; admin dashboard with top songs/books/queries + zero-result queries + CSV export (#404)

---

## Admin Portal

Accessible at **`/manage/`** (alias: `/admin/`) for users with the appropriate role. Main surfaces:

| Surface | Purpose | Default role |
| --- | --- | --- |
| Dashboard | Library + activity snapshot, quick-links | editor+ |
| Song Editor | Per-song UPSERT + auto-save, multi-select bulk delete/verify/tag/move/export, History modal | editor+ |
| User Management | Roles, passwords, activation | admin+ |
| Song Requests | Triage user-submitted requests | editor+ |
| Analytics | Top songs/books/queries, zero-result queries, CSV export | admin+ |
| Revisions | Global revision audit across every song edit | admin+ |
| Entitlements | Reassign capabilities to roles | global_admin |
| Database Setup | Install schema, migrate, backup, restore (with pre-flight), drop legacy | admin+ |

Every write on these pages is CSRF-protected. DB error messages are never leaked to clients (see server error log).

---

## Quick Start

### Prerequisites

- **PHP 8.1+** with `mysqli` extension
- **MySQL 5.7+** or **MariaDB 10.3+**
- **Node.js** v22+ (for build tools)
- **npm** v10+

### 1. Clone & Install

```bash
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns
npm install
```

### 2. Generate Song Data

```bash
npm run parse-songs    # Generates data/songs.json from .SourceSongData/
```

### 3. Set Up Database

```bash
# Interactive installer — prompts for MySQL credentials, creates tables
php appWeb/.sql/install.php

# Import song data from songs.json into MySQL
php appWeb/.sql/migrate-json.php
```

Or use the **web-based installer** at `/manage/setup-database.php` (accessible during initial setup or as a global admin).

**One-shot alternative** (schema + all data in one command):

```bash
mysql -u user -p ihymns < appWeb/.sql/.fulldata/ihymns-full.sql
```

See [Database Setup](#-database-setup) below for detailed instructions.

### 4. Create Admin User

Visit `/manage/setup` in the browser to create the initial admin account.

### 5. Start Development Server

```bash
npm run dev    # Starts PHP dev server at http://localhost:8000
```

---

## Database Setup

### Prerequisites

- **MySQL 5.7+** or **MariaDB 10.3+** with InnoDB support
- **PHP 8.1+** with `mysqli` extension
- A MySQL database created for iHymns:
  ```sql
  CREATE DATABASE ihymns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

### Step 1: Run the Interactive Installer

```bash
php appWeb/.sql/install.php
```

The wizard prompts for:

| Prompt | Default | Description |
| --- | --- | --- |
| MySQL Host | `127.0.0.1` | Server hostname or IP |
| MySQL Port | `3306` | Server port |
| Database Name | `ihymns` | Must already exist |
| Username | `ihymns_user` | MySQL user with full privileges on the database |
| Password | _(none)_ | Hidden input on supported terminals |
| Table Prefix | _(none)_ | Optional prefix for shared databases |

The installer will:
1. Test the connection before writing anything
2. Write credentials to `appWeb/.auth/db_credentials.php` (permissions `0600`)
3. Create all 30+ tables from `schema.sql` (idempotent — safe to re-run)
4. Seed default data: user groups, languages (14), access tiers, app settings

> **Manual credentials**: If you can't use the interactive installer, copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php` and edit it, then re-run the installer.

### Step 2: Import Song Data

```bash
php appWeb/.sql/migrate-json.php
```

This imports all songs from `data/songs.json` into MySQL. The migration:
- Clears existing song data and re-imports (transaction-wrapped, rolls back on error)
- Populates: songbooks, songs, writers, composers, components, translation links
- Builds `LyricsText` column for MySQL FULLTEXT search

### Step 3: Create Initial Admin User

Navigate to `/manage/setup` in the browser. The first account created becomes the **Global Admin**.

### Step 4: Verify via Web Dashboard

Navigate to `/manage/setup-database.php` to see database connection status, table row counts, and run maintenance tasks.

### Web-Based Setup (No CLI Required)

For shared hosting without SSH access:

1. Copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php` and edit with your MySQL details
2. Navigate to `/manage/setup-database.php`
3. Click **Run Install** to create tables
4. Click **Run Song Migration** to import song data
5. Visit `/manage/setup` to create the admin account

### File Structure

```text
appWeb/
├── .auth/
│   ├── .htaccess                      # Blocks web access (defense-in-depth)
│   ├── db_credentials.example.php     # Template (tracked in git)
│   └── db_credentials.php             # Your credentials (NOT in git)
├── .sql/
│   ├── schema.sql                     # Full MySQL schema (30+ tables)
│   ├── install.php                    # Interactive DB installer
│   ├── migrate-json.php              # JSON-to-MySQL data migration
│   ├── migrate-users.php             # User/setlist migration
│   ├── cleanup.php                    # Token/session cleanup
│   ├── backup.php                     # Database backup
│   └── .fulldata/
│       ├── generate-full-sql.php      # Generates one-shot SQL dump
│       └── ihymns-full.sql            # Pre-built full SQL (~6.8 MB)
└── public_html/
    ├── includes/
    │   ├── db_mysql.php               # MySQLi connection factory
    │   └── SongData.php               # Song data (MySQL with JSON fallback)
    └── manage/
        └── setup-database.php         # Web-based DB admin dashboard
```

---

## Environments

| Branch | Subdomain | Purpose |
| --- | --- | --- |
| `alpha` | dev.ihymns.app | Development / Alpha testing |
| `beta` | beta.ihymns.app | Beta testing |
| `main` | ihymns.app | Production |

Deployment is automated via GitHub Actions (SFTP). See [DEV_NOTES.md](DEV_NOTES.md) for full deployment architecture.

---

## Project Structure

```text
iHymns/
├── .claude/              # Claude AI context & project brief
├── .github/workflows/    # CI/CD: deploy, version bump, changelog
├── .SourceSongData/      # Raw song text files (source of truth)
├── tools/                # Build tools & song data parser
├── data/                 # Generated song data (songs.json, schema)
├── appWeb/               # Web PWA application
│   ├── .auth/            #   Database credentials (not in git)
│   ├── .sql/             #   Schema, installers, migrations
│   ├── public_html/      #   Web app source (deployed)
│   ├── data_share/       #   Shared data (songs.json, setlists)
│   └── private_html/     #   Admin tools, song editor
├── appApple/             # Native Apple app (Swift/SwiftUI)
├── appAndroid/           # Android app (Kotlin/Compose)
├── wiki/                 # GitHub wiki pages
├── help/                 # User documentation
├── Project_Plan.md       # Detailed project plan
├── PROJECT_STATUS.md     # Current status tracker
├── CHANGELOG.md          # Change log
└── DEV_NOTES.md          # Developer notes & deployment setup
```

---

## Documentation

| Document | Description |
| --- | --- |
| [Project Plan](Project_Plan.md) | Architecture, milestones, tech stack |
| [Project Status](PROJECT_STATUS.md) | Current progress |
| [Changelog](CHANGELOG.md) | All changes |
| [Developer Notes](DEV_NOTES.md) | Deployment, secrets, architecture decisions |
| [Wiki](wiki/) | Comprehensive developer & user documentation |

---

## License

Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.

This software is proprietary. Unauthorized copying, modification, or distribution is strictly prohibited.

Third-party components retain their respective licenses (MIT, Apache 2.0, etc.).

---

## Credits

- **MWBM Partners Ltd** — Development & maintenance
- Song data sourced from published hymnals and songbooks

---

Built with love for worship.
