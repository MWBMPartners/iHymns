# Native Apps (Apple & Android)

> Architecture, current state, and roadmap for the native applications

---

## Apple App

### Overview

| Property | Value |
|---|---|
| Language | Swift 6.3 |
| Framework | SwiftUI |
| Min deployment | iOS 17, macOS 14, tvOS 17, visionOS 1, watchOS 10 |
| App ID | `Ltd.MWBMPartners.iHymns.Apple` |
| Architecture | `iHymnsKit` SwiftPM package (shared across all targets) + SwiftUI views |
| Status | Phase 1 (web-app parity) + Phase 2 (Live features: watch relay, tvOS projector, Live Activities, App Intents) code-complete, unreleased. Compiled only by CI (`apple.yml`); device matrices + APNs provisioning are owner-gated |
| Issue | [#257](https://github.com/MWBMPartners/iHymns/issues/257) — user accounts & API integration (superseded — see Current Features below; networking, auth, and sync have since landed) |

### Project Structure

```
appApple/iHymns/iHymns/
├── iHymnsApp.swift              # App entry point
├── Models/
│   ├── Song.swift               # Song data model (Codable)
│   ├── SongData.swift           # Song data container
│   └── Songbook.swift           # Songbook metadata
├── Services/
│   ├── SongStore.swift          # Observable data store
│   └── AppInfo.swift            # App metadata
└── Views/
    ├── ContentView.swift         # Root view (TabView/NavigationSplitView)
    ├── SongbookListView.swift    # Songbook browser
    ├── SongDetailView.swift      # Song lyrics
    ├── FavoritesView.swift       # Favourites
    ├── SearchView.swift          # Search
    ├── HelpView.swift            # In-app help
    └── WidgetViews.swift         # Widget UI
```

### Current Features
- Songbook browsing and song detail views, backed by a URLSession networking layer against the live bearer-token API (not bundled data)
- Full-text search by title, lyrics, songbook, number
- Offline cache via **GRDB.swift** (`IHPersistence` module) — on-disk SQLite-backed store with full-text search and versioned migrations for saved songs, setlists, and favourites (not UserDefaults/bundled JSON)
- User authentication, setlist management with custom arrangements, and cross-device sync via the API
- Live Follow and Service Mode (the `.live` tab) — see [[Live Follow & Service Mode]]
- Adaptive UI: `TabView` (iPhone) / `NavigationSplitView` (iPad/Mac)
- Apple TV, Vision Pro, and Apple Watch support; App Shortcuts / Siri phrases for Live Follow
- Home screen widgets (Song of the Day, Recent Favourites)

### Status notes
Phase 1 (web-app parity: the networking/auth/sync work originally tracked as "planned" under #257) and Phase 2 (Live features) are code-complete but **unreleased** — compiled only by CI, not shipped to any app store. Device-matrix QA and APNs provisioning are the remaining owner-gated steps.

---

## Android App

### Overview

| Property | Value |
|---|---|
| Language | Kotlin 2.1 |
| Framework | Jetpack Compose |
| Min SDK | 24 (Android 7.0) |
| App ID | `Ltd.MWBMPartners.iHymns.Android` |
| Architecture | MVVM (ViewModel + StateFlow) |
| Status | Scaffold / in progress — no shared networking or persistence layer yet |
| Issue | [#258](https://github.com/MWBMPartners/iHymns/issues/258) — user accounts & API integration |

### Project Structure

```
appAndroid/app/src/main/java/ltd/mwbmpartners/ihymns/android/
├── MainActivity.kt              # Single-activity entry point
├── models/
│   └── Song.kt                  # Song data class
├── viewmodel/
│   └── SongViewModel.kt        # MVVM ViewModel (StateFlow)
└── ui/
    ├── Navigation.kt            # NavHost routing
    ├── Theme.kt                 # Material 3 theme
    └── screens/
        ├── HomeScreen.kt        # Home with songbook grid
        ├── SongListScreen.kt    # Song list for a songbook
        ├── SongDetailScreen.kt  # Song lyrics
        ├── FavoritesScreen.kt   # Favourites
        ├── SearchScreen.kt      # Search
        └── HelpScreen.kt       # In-app help
```

### Current Features
- Songbook browsing and song detail views
- Full-text search by title, lyrics, number
- Favourites (SharedPreferences persistence)
- Material 3 theming
- Android TV / Fire OS compatible (no Google Play Services)
- Leanback launcher support for TV

### Planned (Issue #258)
- HTTP client (Ktor or Retrofit)
- User authentication (EncryptedSharedPreferences)
- Setlist management with custom arrangements
- Cross-device setlist sync via API
- Password reset flow
- WorkManager for background sync
- Glance widgets for home screen
- Android TV lean-back UI for setlist browsing
- Wear OS support (future)

---

## Shared API

Both native apps will integrate with the same bearer token API as the PWA. See [[API Reference]] for all endpoints.

### Key Integration Points

| Feature | API Endpoint |
|---|---|
| Register | `POST ?action=auth_register` |
| Login | `POST ?action=auth_login` |
| Logout | `POST ?action=auth_logout` |
| Verify token | `GET ?action=auth_me` |
| Forgot password | `POST ?action=auth_forgot_password` |
| Reset password | `POST ?action=auth_reset_password` |
| Get setlists | `GET ?action=user_setlists` |
| Sync setlists | `POST ?action=user_setlists_sync` |
| Share setlist | `POST ?action=setlist_share` |
| Get shared setlist | `GET ?action=setlist_get&id=...` |

### Token Storage Recommendations

| Platform | Storage | Notes |
|---|---|---|
| PWA | `localStorage` | Only option for web |
| iOS | Keychain | More secure; survives app reinstall |
| Android | EncryptedSharedPreferences | Backed by Android Keystore |

---

## Fire OS Compatibility

The Android app has **zero Google Play Services dependencies**, making it fully compatible with Amazon Fire OS:

- Fire tablets, Fire TV, Fire TV Stick
- Uses the same APK as standard Android
- `android.hardware.touchscreen` set to `required="false"` for TV remote navigation
- `LEANBACK_LAUNCHER` intent filter in `AndroidManifest.xml`
- Manual upload to Amazon Developer Console (automated API available for future integration)

---

## Phase 2 Roadmap

The web app already reads live MySQL for every request (epic #1010), and Apple's `iHymnsKit` talks to the same live API with a GRDB offline cache — neither platform bundles `songs.json` today. The still-future **iLyrics dB API** integration is a separate, distinct roadmap item: a curated Christian-songs-only content source layered on top of the existing MySQL-backed catalogue, not a replacement for the current data flow:

- Song data fetched from the iLyrics dB API
- Search performed server-side
- Real-time updates without app updates
- Apple TV Remote Control: iPhone/iPad controls tvOS lyrics display over LAN
