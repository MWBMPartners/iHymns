# iHymns Apple — Changelog

## [1.1.0] — 2026-04-07 — Phase 1 Architecture & Liquid Glass UI

### Architecture
- feat: Liquid Glass design system — translucent, depth-aware UI components
- feat: MVVM architecture with centralised SongStoreViewModel
- feat: async/await API client with ETag caching for ihymns.app REST API
- feat: network connectivity monitoring with offline-first fallback
- feat: dictionary-indexed O(1) song lookups (replacing linear scans)
- feat: App Group data sharing for widget extensions
- refactor: extracted Theme.swift with design tokens, typography, spacing constants
- refactor: moved SongStore to ViewModels/ with enhanced API integration

### New Features
- feat: worship set list management — create, edit, reorder, share, export
- feat: presentation mode — full-screen lyrics display with auto-scroll
- feat: search history with quick-access chips
- feat: song view history (recently viewed, max 20)
- feat: Song of the Day on home screen (deterministic daily rotation)
- feat: user preferences — theme, font size, line spacing, chorus highlighting
- feat: settings screen with data sync controls and about section
- feat: songbook-specific colour coding across all views
- feat: export/share favourites as text list
- feat: add songs to set lists from song detail toolbar

### Platform Features
- feat: Core Spotlight indexing — songs searchable from system Spotlight
- feat: Home Screen quick actions (Search, Favourites, Random, Set Lists)
- feat: Song of the Day push notification (daily at 8:00 AM)
- feat: Handoff support — continue viewing a song on another Apple device
- feat: Deep linking (ihymns:// scheme + Universal Links)
- feat: Dynamic Island / Live Activity support (iPhone 14 Pro+)
- feat: Touch Bar support (macOS)
- feat: haptic feedback across iOS and watchOS
- feat: platform-adaptive navigation (TabView, SplitView, focus-based)

### UI Improvements
- feat: Liquid Glass cards for songbook grid
- feat: Liquid Glass credits section on song detail
- feat: Liquid Glass search history chips
- feat: Liquid Glass floating controls in presentation mode
- feat: glass-effect songbook badges with per-book colours
- feat: media availability badges (audio, sheet music) on song header
- feat: offline connectivity banner with glass styling
- feat: spring-based animations (liquidGlassSpring, liquidGlassQuick, liquidGlassGentle)

### Multi-Platform
- feat: universal app — iOS, iPadOS, macOS, tvOS, watchOS, visionOS
- feat: tvOS congregational mode with extra-large fonts
- feat: watchOS compact layout with favourite toggle
- feat: visionOS spatial window sizing
- feat: macOS Settings window and print support

## [1.0.0] — 2026-04-06
- feat: Apple universal app — Xcode project setup (Swift 6.3 / SwiftUI)
- feat: Apple — Widgets (Song of the Day + Recent Favourites)
- feat: add Miscellaneous songbook (Misc) for non-published songs
- feat: automated Apple app packaging (App Store, TestFlight, direct)
- fix: set Vendor Parent to NULL — MWBM Partners Ltd is the top-level vendor
- refactor: align infoAppVer.php with phpWhoIs structure + platform info files
- refactor: single canonical songs.json — copy during build/deploy
- refactor: use unique platform-specific Application IDs
