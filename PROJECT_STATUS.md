# 📊 iHymns — Project Status

> **Quick reference for current project state**

---

## 🚦 Overall Status: 🟢 In Progress

| Area | Status | Notes |
| --- | --- | --- |
| 📋 Project Plan | ✅ Complete | See [Project_Plan.md](Project_Plan.md) |
| 🗂 Project Structure | ✅ Complete | Directories, .gitignore, deployment structure |
| 📖 Help Documentation | ✅ Complete | 6 guides in `help/` + in-app help |
| 🎫 GitHub Issues | 🟢 Active | 661+ created, 357+ closed; highest issue #955 |
| 🔧 Song Data | ✅ Active | ~12,370 songs across 30+ songbooks; served via static cache (#933) |
| 🌐 Web PWA | ✅ Core + Enhanced | Search (Fuse.js), songbooks, lyrics, favourites, themes (Light/Dark/High-contrast/CVD/System #956), deep linking, WCAG 2.1 AA, offline support |
| 🛠 Song Editor | ✅ Complete | `appWeb/public_html/manage/editor/` — bulk import (ZIP / VideoPsalm / OpenSong), structure tab, media uploads, per-component language overrides |
| 🛠 Admin Portal | ✅ Active | 39 admin surfaces under `/manage/*` across 7 nav groups (Dashboard / Songs / Catalogue / Access / People / Operations / Help) |
| 🚀 CI/CD Pipeline | ✅ Complete | 5 workflows: deploy, version-bump, changelog, release, test |
| 🍎 Apple App | 🔲 Restart pending | Swift 6.3 / SwiftUI — earlier branch retired; restarting from scratch |
| 🤖 Android App | 🔲 Not Started | Kotlin / Jetpack Compose |

---

## 📌 Completed Milestones

### Milestone 1: Project Setup & Data Pipeline ✅

Project structure, .gitignore, help docs, GitHub Issues, package.json, song parser, songs.json.

### Milestone 2: Web PWA Core ✅

Layout, songbook browser, song detail, search (Fuse.js), responsive design, dark mode, favourites, PWA.

### Milestone 3: Web PWA Enhanced ✅

Deep linking (.htaccess), accessibility (WCAG 2.1 AA), in-app help, colourblind-friendly mode, numpad search, iLyrics dB colour scheme alignment, print stylesheet.

### Milestone 6: Song Editor ✅

Web-based admin tool at `/manage/editor/`: metadata, structure/arrangement, writers/composers, CCLI / ISWC, JSON validation/save, bulk import/export, preview, accompanying media uploads (audio / sheet music / MIDI / MusicXML).

### Infrastructure ✅

5 GitHub Actions workflows: SFTP deployment, semver bumping, changelog generation, GitHub Releases, CI lint/test.

### 2026-05 catalogue & platform work ✅ (highlights)

- **Works composition grouping** (#840) — `tblWorks` self-FK nesting + `tblWorkSongs`; public `/work/<slug>` page; admin CRUD at `/manage/works`.
- **External-links registry** (#833) — MusicBrainz-style `tblExternalLinkTypes` + per-entity `tblXxxExternalLinks`; auto-detect URL → provider via `js/modules/external-link-detect.js`.
- **Activity-log resilience** (#917–#931) — per-request rows, IPv6/proxy/VPN resolution, every PHP fatal mirrored, defensive `bindParamSafe()` helper.
- **Real email delivery** (#922) — magic-link, password reset, register, admin force-reset (closes #898 P0/security).
- **Songs static cache** (#933) — replaces on-demand `exportAsJson()` rebuild; ~140 MB → <2 MB peak; 5.96 MB → 928 KB on gzip-9.
- **Credit-people structured-name split** (#935) — `FirstNames` / `Surname` / `Suffix` alongside canonical `Name`.
- **Quick-wins batch** (#948) — clickable Tune / CCLI / ISWC + Translations section; Catalogues many-to-many grouping (#941); Works ISWC backfill (#942); various UX/bug fixes.
- **Centralised link styling** (#952) — kills Bootstrap default `<a>` blue + underline site-wide; `.song-meta-link` muted convention everywhere.

---

## 📌 Next Milestones

### Milestone 4 & 5: Apple App

- Xcode project setup (Swift 6.3 / SwiftUI), universal app (iPhone / iPad / Apple TV)
- Song data model, browser, search, detail view, favourites
- Spotlight, share sheet, App Store submission, signing & notarisation

### Milestone 7: Android App

- Kotlin / Jetpack Compose
- Feature parity with Apple app

### Major catalogue follow-ups (issue-tracked, full design captured)

- **#943** — Works ISWC API integration (ISWCnet + MusicBrainz + MRO IDs)
- **#944** — UI i18n + Translator role + Roles admin area
- **#945** — Naming cleanup: User Groups / Access Tiers / Roles / Entitlements / Licence Types vocabulary audit
- **#946** — Analytics expansion + external platform integration (GA4 / Plausible / Matomo)
- **#947** — Login forms: Cloudflare Turnstile / reCAPTCHA / hCaptcha admin-configurable

---

## 📈 Progress Summary

- **Songs**: ~12,370 across 30+ songbooks (multilingual: English, Afrikaans, Spanish, French, Swahili, Portuguese, and others)
- **Web PWA**: Feature-complete (core + enhanced + admin portal + editor)
- **GitHub Issues**: 661+ created, 357+ closed; highest #955
- **Phase**: ONE (v0.x.x — pre-release; alpha currently at 0.110.0, main at 0.25.2)
- **Version**: 0.110.0 Alpha
- **CI/CD**: 5 GitHub Actions workflows live

---

## 🔑 Legend

| Symbol | Meaning |
| --- | --- |
| ✅ | Complete |
| 🟢 | In Progress — on track |
| 🟡 | In Progress — needs attention |
| 🔴 | Blocked |
| 🔲 | Not Started |
| ⏳ | Waiting (on external input) |

---

Last updated: 2026-05-10
