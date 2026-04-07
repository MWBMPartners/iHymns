# iHymns Apple — Changelog

## [1.2.0] — 2026-04-07 — Phase 1 Feature Complete (Issues #179–#190, #184)

### #179 Core Song Browsing
- feat: FuzzySearchEngine with weighted Levenshtein scoring (title 3x, writers 2x)
- feat: 300ms debounced search-as-you-type
- feat: numpad number lookup UI with songbook selector chips
- feat: songbook colours corrected to match PWA (CP=indigo, JP=pink, MP=teal, etc.)
- feat: alphabetical jump-to-letter index strip on songbook pages
- feat: sort toggle (by number / A-Z) in SongListView
- feat: random song picker with shuffle-again button

### #180 Song Display
- feat: lyrics font scaling (0.5x–5.0x) from user preferences
- feat: verse/chorus label toggle via showComponentLabels preference
- feat: scroll-linked reading progress bar (songbook accent colour)
- feat: related songs section (scored by shared writers/composers/songbook)
- feat: arrangement order support via arrangedComponents

### #181 Favorites & Set Lists
- feat: FavouriteTag model with 14 predefined tags + custom tags
- feat: tag filter strip in FavoritesView
- feat: batch selection mode with multi-select, bulk tag, bulk remove
- feat: set list rename and duplicate (context menu + swipe)
- feat: JSON backup/restore (UserDataBackup model)

### #182 Settings & Preferences
- feat: theme applied via preferredColorScheme on root view
- feat: reduceTransparency preference added
- feat: LiquidGlass respects reduce motion environment

### #183 Song of the Day
- feat: SongOfTheDayEngine with Anonymous Gregorian Easter calculation
- feat: 16 Christian calendar themes with keyword matching
- feat: title keywords weighted 2x over lyrics matching
- feat: theme label badge on home screen card

### #184 Audio & Sheet Music
- feat: AudioPlayerService with AVMIDIPlayer for MIDI playback
- feat: transport controls (play/pause/stop, seekable progress bar)
- feat: SheetMusicView with PDFKit viewer (zoom, scroll, share)
- feat: MIDI/PDF download with local cache for offline access
- feat: TransposeEngine with chromatic scales and per-song persistence

### #185 Navigation & UX
- feat: swipe left/right gesture for song navigation within songbook
- feat: WriterDetailView showing all songs grouped by songbook
- feat: tappable writer/composer names in credits section
- feat: CompareView with split (iPad) / tabbed (iPhone) layout
- feat: StatisticsView with collection and user activity metrics
- feat: MissingSongRequestView with rate limiting

### #186 Sharing & Social
- feat: song ID normalisation in deep links (MP-1 → MP-0001)
- feat: ShareService with rich link metadata (LPLinkMetadata)
- feat: permalink URL generators for songs and set lists

### #187 Offline Support
- feat: BackgroundRefreshManager with BGTaskScheduler (6-hour interval)
- feat: prompted update alert when autoUpdateSongs is disabled
- feat: silent update on app appear when autoUpdateSongs is enabled

### #188 Accessibility
- feat: VoiceOver accessibility helpers (song, songbook, component)
- feat: colourblind-safe palette (Wong 2011 CVD-safe colours)
- feat: keyboard shortcuts overlay (11 shortcuts across 3 categories)

### #189 Analytics
- feat: AnalyticsService with Supabase + Plausible providers
- feat: ATT prompt via ATTrackingManager
- feat: GDPR-compliant consent toggle in Settings
- feat: event types: screen_view, song_view, search, favourite, setlist, scroll_depth

### #190 Help, Legal & First-Launch
- feat: PrivacyPolicyView (12 sections, bundled offline)
- feat: TermsOfUseView (12 sections, CCLI compliance)
- feat: first-launch CCLI disclaimer (gates app until accepted)
- feat: Privacy/Terms links in Settings

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
