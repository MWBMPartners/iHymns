# iHymns — Project Status

> **Quick reference for current project state**

---

## Overall Status: In Progress

| Area | Status | Notes |
| --- | --- | --- |
| Project Plan | Complete | See [Project_Plan.md](Project_Plan.md) |
| Project Structure | Complete | Directories, .gitignore, deployment structure |
| Help Documentation | Complete | 6 guides in `help/` + in-app help |
| GitHub Issues | Active | 220+ issues created |
| Song Data Parser | Complete | 3,612 songs, 6 songbooks, 5.22 MB JSON |
| Web PWA | Complete | Core + Enhanced: search, lyrics, favourites, dark mode, deep linking, accessibility |
| Song Editor | Complete | Developer tool in `appWeb/private_html/editor/` |
| CI/CD Pipeline | Complete | 5 workflows: deploy, version-bump, changelog, release, test |
| **Apple App** | **Phase 1 Complete** | **Swift 6.3 / SwiftUI — 49 Swift files, 9,500+ lines** |
| Android App | Not Started | Kotlin / Jetpack Compose |

---

## Apple App — Phase 1 Feature Status (12 Issues, ALL COMPLETE)

| Issue | Feature | Status |
|---|---|---|
| #179 | Core song browsing: fuzzy search, numpad, songbook colours, sort, random | Complete |
| #180 | Song display: font scaling, verse toggle, progress bar, related songs, arrangement | Complete |
| #181 | Favourites & set lists: tags, batch ops, rename, duplicate, JSON backup, sequential nav | Complete |
| #182 | Settings: theme applied, colourblind palette, reduce motion/transparency | Complete |
| #183 | Song of the Day: 16 calendar themes, Easter calc, keyword matching | Complete |
| #184 | Audio & sheet music: MIDI playback, PDF viewer, transpose, caching | Complete |
| #185 | Navigation: swipe gestures, writer pages, comparison, statistics, keyboard shortcuts | Complete |
| #186 | Sharing: deep links, rich previews, ID normalisation, shared set list viewer | Complete |
| #187 | Offline: bundled data, background refresh, prompted updates | Complete |
| #188 | Accessibility: VoiceOver, colourblind palette, keyboard shortcuts | Complete |
| #189 | Analytics: Supabase, Plausible, ATT consent, session heartbeat, scroll depth | Complete |
| #190 | Help/Legal: Privacy Policy, Terms of Use, CCLI disclaimer, first-launch gate | Complete |

## Apple App — Apple-Native Features (11 Issues, Foundations Built)

| Issue | Feature | Code Status |
|---|---|---|
| #204 | CloudKit sync across devices | Implemented + wired into lifecycle |
| #205 | App Intents / Siri Shortcuts (5 intents) | Implemented with UserDefaults bridge |
| #206 | SharePlay group worship sessions | Framework complete |
| #207 | Interactive WidgetKit (Lock Screen, StandBy) | Intent definitions ready |
| #208 | Dynamic Island / Live Activities | Wired into PresentationView |
| #209 | TipKit feature discovery (7 tips) | Definitions complete |
| #210 | AirPlay lyrics projection | Manager + external display view |
| #211 | SwiftData migration models | 4 models + migration helper |
| #212 | Focus Filters | Intent + reader complete |
| #213 | visionOS spatial experience | SpatialLyricsView complete |
| #214 | App Clips | Entry view + URL parser |

## Infrastructure Issues

| Issue | Feature | Status |
|---|---|---|
| #217 | Universal Links (all ihymns.app URLs) | Complete |
| #218 | Entitlements + Info.plist | Complete |
| #219 | GitHub Actions CI/CD for TestFlight/App Store | Existing workflow ready |
| #220 | PWA banner -> App Store on Apple devices | Complete |

---

## Completed Milestones

### Milestone 1: Project Setup & Data Pipeline
All tasks complete: project structure, .gitignore, help docs, GitHub Issues, package.json, song parser, songs.json.

### Milestone 2: Web PWA Core
Layout, songbook browser, song detail, search (Fuse.js), responsive design, dark mode, favourites, PWA.

### Milestone 3: Web PWA Enhanced
Deep linking (.htaccess), accessibility (WCAG 2.1 AA), in-app help, colourblind-friendly mode, numpad search, colour scheme alignment, print stylesheet.

### Milestone 4 & 5: Apple App — Phase 1 COMPLETE
- 49 Swift files across 6 directories
- Liquid Glass design system with translucent materials
- MVVM architecture with SongStoreViewModel
- Async API client with ETag caching
- Universal app: iOS, iPadOS, macOS, tvOS, watchOS, visionOS
- All 12 Phase 1 feature issues implemented
- 11 Apple-native features with foundations built

### Milestone 6: Song Editor
Web-based developer tool: edit metadata, structure/arrangement, writers/composers, CCLI numbers, JSON validation/save.

### Infrastructure
5 GitHub Actions workflows: SFTP deployment, semver bumping, changelog generation, GitHub Releases, CI lint/test. Apple build workflow ready.

---

## Next Milestones

### Apple App — Phase 1 Polish & Testing
- Full accessibility audit with Xcode Inspector
- Configure Supabase project URL
- Bundle SoundFont for MIDI playback
- Test on physical devices

### Apple App — App Store Submission
- Configure signing with Fastlane Match
- TestFlight beta distribution
- App Store review submission

### Milestone 7: Android App
- Kotlin / Jetpack Compose
- Feature parity with Apple app

---

## Progress Summary

- **Songs parsed**: 3,612 across 6 songbooks (5.22 MB JSON)
- **Web PWA**: Feature-complete (core + enhanced + editor)
- **Apple App**: Phase 1 complete (49 Swift files, 9,500+ lines)
- **GitHub Issues**: 220+ created
- **Phase**: ONE (v1.x.x — local song data)
- **Apple App version**: 1.2.0
- **CI/CD**: 6 GitHub Actions workflows ready

---

Last updated: 2026-04-08
