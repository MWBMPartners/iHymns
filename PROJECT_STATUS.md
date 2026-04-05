# 📊 iHymns — Project Status

> **Quick reference for current project state**

---

## 🚦 Overall Status: 🟢 In Progress

| Area | Status | Notes |
| --- | --- | --- |
| 📋 Project Plan | ✅ Complete | See [Project_Plan.md](Project_Plan.md) |
| 🗂 Project Structure | ✅ Complete | Directories, .gitignore, deployment structure |
| 📖 Help Documentation | ✅ Complete | 6 guides in `help/` + in-app help |
| 🎫 GitHub Issues | ✅ Complete | 72 issues created, 31 closed |
| 🔧 Song Data Parser | ✅ Complete | 3,612 songs → 5.22 MB JSON |
| 🌐 Web PWA | ✅ Core + Enhanced | Search, songbooks, lyrics, favourites, dark mode, deep linking, accessibility, colourblind mode |
| 🛠 Song Editor | ✅ Complete | Developer tool in `appWeb/private_html/editor/` |
| 🚀 CI/CD Pipeline | ✅ Complete | 5 workflows: deploy, version-bump, changelog, release, test |
| 🍎 Apple App | 🔲 Not Started | Swift 6.3 / SwiftUI — next priority |
| 🤖 Android App | 🔲 Not Started | Kotlin / Jetpack Compose |

---

## 📌 Completed Milestones

### Milestone 1: Project Setup & Data Pipeline ✅

All tasks complete: project structure, .gitignore, help docs, GitHub Issues, package.json, song parser, songs.json.

### Milestone 2: Web PWA Core ✅

Layout, songbook browser, song detail, search (Fuse.js), responsive design, dark mode, favourites, PWA.

### Milestone 3: Web PWA Enhanced ✅

Deep linking (.htaccess), accessibility (WCAG 2.1 AA), in-app help, colourblind-friendly mode, numpad search, iLyrics dB colour scheme alignment, print stylesheet.

### Milestone 6: Song Editor ✅

Web-based developer tool: edit metadata, structure/arrangement, writers/composers, CCLI numbers, JSON validation/save, bulk import/export, preview.

### Infrastructure ✅

5 GitHub Actions workflows: SFTP deployment, semver bumping, changelog generation, GitHub Releases, CI lint/test.

---

## 📌 Next Milestones

### Milestone 4 & 5: Apple App (Next)

- Xcode project setup (Swift 6.3 / SwiftUI)
- Universal app (iPhone, iPad, Apple TV)
- Song data model, browser, search, detail view
- Favourites, dark mode, Spotlight, share sheet
- App Store submission, signing & notarisation

### Milestone 7: Android App

- Kotlin / Jetpack Compose
- Feature parity with Apple app

---

## 📈 Progress Summary

- **Songs parsed**: 3,612 across 5 songbooks (5.22 MB JSON)
- **Web PWA**: Feature-complete (core + enhanced + editor)
- **GitHub Issues**: 72 created, 31 closed
- **Phase**: ONE (v1.x.x — local song data)
- **Version**: 1.0.0-alpha.1
- **CI/CD**: 5 GitHub Actions workflows ready

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

Last updated: 2026-04-05
