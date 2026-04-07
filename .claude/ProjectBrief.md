# iHymns — Project Brief

> **Claude Context File** — This file ensures continuity across development sessions.

---

## What Is iHymns?

A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship.

- **Domain**: [iHymns.app](https://ihymns.app)
- **Copyright**: (c) 2026 MWBM Partners Ltd
- **License**: Proprietary (third-party components retain their own licenses)
- **GitHub Repo**: https://github.com/MWBMPartners/iHymns
- **Current Version**: 1.2.0 (Apple), 0.1.7 (Web PWA)
- **Apple App Branch**: `Claude/platform-Apple/Phase1`

---

## Two-Phase Approach

### Phase ONE (Current) — v1.x.x

- Songs sourced from local `.SourceSongData/` text files
- Parsed into structured JSON (`data/songs.json`) — single canonical copy
- 6 songbooks, 3,612 songs: CP (243), JP (617), MP (1355), SDAH (695), CH (702), Misc (0)
- Some songbooks include MIDI audio and PDF sheet music
- Song Editor (developer tool) in `appWeb/private_html/editor/`

### Phase TWO (Future) — v2.x.x

- Songs sourced from iLyrics dB API (MySQL backend)
- Apple TV remote control (Bonjour/mDNS + WebSocket/Multipeer)
- Cloud sync and multi-device features

---

## Target Platforms

| Platform | Tech Stack | Status |
|---|---|---|
| **Web / PWA** | PHP 8.3, vanilla JS (ES modules), Bootstrap 5, Fuse.js | Complete |
| **Apple** | Swift 6.3, SwiftUI, Liquid Glass UI | **Phase 1 Complete (49 Swift files)** |
| **Android** | Kotlin, Jetpack Compose, Material Design 3 | Not Started |

---

## Apple App Architecture (Phase 1 Complete)

### File Structure (49 Swift files)
```
appApple/iHymns/iHymns/
├── DesignSystem/          LiquidGlass.swift, Theme.swift
├── Extensions/            Accessibility.swift
├── Models/                Song, Songbook, SongData, SetList
├── Services/
│   ├── API/               APIClient, NetworkMonitor
│   ├── Analytics/         AnalyticsService (Supabase + Plausible)
│   ├── Platform/          PlatformFeatures, HapticManager, ShareService,
│   │                      BackgroundRefresh, CloudKitSync, AirPlayManager,
│   │                      AppIntentsProvider, LiveActivityManager, TipKitProvider,
│   │                      SharePlayManager, FocusFilterProvider, InteractiveWidgets,
│   │                      SwiftDataModels, AppClipSupport
│   ├── AudioPlayer, FuzzySearch, SongOfTheDay, AppInfo
├── ViewModels/            SongStoreViewModel
├── Views/
│   ├── Components/        AudioPlayerView
│   ├── Platform/          VisionOSSpatialView
│   ├── Screens/           SetLists, Settings, Presentation, WriterDetail,
│   │                      Compare, Statistics, MissingSongRequest, Legal,
│   │                      SharedSetList
│   ├── ContentView, SongDetailView, SongListView, SongbookListView,
│   │   SearchView, FavoritesView, HelpView, WidgetViews
├── iHymnsApp.swift, iHymns.entitlements, Info.plist
```

### Phase 1 Issues (ALL 12 COMPLETE: #179-#190)
- Core search (fuzzy), numpad, songbook browsing
- Song display (font scaling, arrangement, progress bar, related songs)
- Favourites with tags, set lists with sequential nav
- Settings (theme, colourblind, accessibility)
- Song of the Day (16 Christian calendar themes)
- Audio (MIDI), Sheet Music (PDF), Transpose
- Navigation (swipe, writer pages, comparison, statistics)
- Sharing (Universal Links, rich previews)
- Offline (background refresh, bundled data)
- Accessibility (VoiceOver, keyboard shortcuts)
- Analytics (Supabase, Plausible, ATT)
- Legal (Privacy, Terms, CCLI disclaimer)

### Apple-Native Features (11 issues: #204-#214)
CloudKit sync, Siri Shortcuts, SharePlay, Interactive Widgets,
Dynamic Island, TipKit, AirPlay, SwiftData, Focus Filters,
visionOS spatial, App Clips

### Infrastructure (#217-#220)
Universal Links for all URLs, entitlements, CI/CD, PWA->App Store banner

---

## Key Configuration

### Songbook Colours (PWA spec)
- CP = Indigo (#4f46e5), JP = Pink (#ec4899), MP = Teal (#14b8a6)
- SDAH = Amber (#f59e0b), CH = Red (#ef4444), Misc = Violet (#8b5cf6)

### API Endpoints
- Base: `https://ihymns.app/api`
- Search: `?action=search&q={query}`
- Song: `?action=song_data&id={id}`
- Songbooks: `?action=songbooks`
- Random: `?action=random`
- Songs JSON: `?action=songs_json` (with ETag)

### Apple App IDs
- Bundle: `ltd.mwbmpartners.ihymns`
- App Group: `group.com.mwbm.ihymns`
- CloudKit: `iCloud.ltd.mwbmpartners.ihymns`
- URL Scheme: `ihymns://`

---

## Standing Tasks
- Always maintain song data parser compatibility
- Keep songs.json as single canonical source
- Conventional commits for auto-versioning
- WCAG 2.1 AA accessibility compliance
- Detailed comments on all code blocks

---

Last updated: 2026-04-08
