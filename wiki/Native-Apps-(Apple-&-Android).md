# Native Apps (Apple & Android)

> Architecture, current state, and roadmap for the native applications

---

## Apple App

### Overview

| Property | Value |
|---|---|
| Language | Swift 6 language mode (`SWIFT_VERSION = 6.0` in `appApple/Config/Shared.xcconfig`; the shared package declares `swift-tools-version: 6.2` and CI builds with Xcode 26) |
| Framework | SwiftUI |
| Min deployment | iOS 26, macOS 26, tvOS 26, watchOS 26, visionOS 26 (`appApple/Config/Shared.xcconfig` lines 58-64) |
| Bundle identifier | `app.ihymns` (plus `app.ihymns.watchkitapp` and `app.ihymns.widgets`) — `appApple/project.yml` |
| Architecture | `iHymnsKit` SwiftPM package (shared across all targets) + SwiftUI views |
| Status | Phase 1 (web-app parity) + Phase 2 (Live features: watch relay, tvOS projector, Live Activities, App Intents) code-complete, unreleased. Compiled only by CI (`apple.yml`); device matrices + APNs provisioning are owner-gated |
| Issue | [#257](https://github.com/MWBMPartners/iHymns/issues/257) — user accounts & API integration (superseded — see Current Features below; networking, auth, and sync have since landed) |

### Project Structure

Almost nothing lives in the app targets themselves. Each target is a thin entry point; all the
models, views and services sit in the shared `iHymnsKit` SwiftPM package, which every target imports.

```
appApple/
├── project.yml                    # XcodeGen spec — the .xcodeproj is generated, not committed
├── Config/
│   ├── Shared.xcconfig            #   Deployment targets, Swift language mode, shared build settings
│   └── Versioning.xcconfig        #   Marketing version + build number
├── Apps/                          # One folder per target; each holds only its entry point
│   ├── iHymns/Sources/            #   IHymnsApp.swift, IHymnsAppShortcuts.swift, PrivacyInfo.xcprivacy
│   ├── iHymnsTV/Sources/
│   ├── iHymnsWatch/Sources/
│   └── iHymnsWidgets/Sources/
└── Packages/
    └── iHymnsKit/                 # The shared SwiftPM package — where the app actually is
        ├── Package.swift
        └── Sources/
            ├── IHModels/          #   Data models (Song, Setlist, CreditPerson, …)
            ├── IHAPI/             #   URLSession client for the bearer-token API
            ├── IHPersistence/     #   GRDB.swift on-disk cache (saved songs, setlists, favourites)
            ├── IHAuth/            #   Sign-in and token handling
            ├── IHFeatures/        #   The SwiftUI screens and their view models — by far the largest module
            ├── IHLive/            #   Live Follow / Service Mode
            ├── IHLiveActivity/    #   Live Activities
            ├── IHDesign/          #   Shared styling
            ├── IHAppSupport/      #   App-level helpers
            └── IHLog/             #   Logging
```

> **Corrected 2026-09-08.** This tree used to show a flat `appApple/iHymns/iHymns/` directory holding
> `Models/`, `Services/` and `Views/` with named files (`Song.swift`, `ContentView.swift`,
> `SongbookListView.swift`, and so on). **That directory does not exist** — every path in it was
> wrong. The code moved into the package structure above some time ago, and the commit that
> corrected three Apple status documents on 2026-09-07 (`5525602b`) did not touch the wiki, so this
> page kept the old shape. The Apple deployment targets and language version in the table above were
> stale for the same reason: they said iOS 17 / macOS 14 / tvOS 17 / visionOS 1 / watchOS 10 and
> "Swift 6.3", when `Shared.xcconfig` has set every deployment target to 26.0 and nothing in the repo
> mentions a Swift 6.3. Anyone planning device support from the old numbers would have built a matrix
> that cannot compile.

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
| Language | Kotlin 2.4 (the plugins are pinned at `2.4.10` in `appAndroid/build.gradle.kts`) |
| Framework | Jetpack Compose |
| Min SDK | 26 (Android 8.0 Oreo) — `appAndroid/app/build.gradle.kts:66`; compile and target SDK are both 35 |
| Application ID | `ltd.mwbmpartners.ihymns` (debug builds append `.debug`) — `appAndroid/app/build.gradle.kts:63` |
| Architecture | MVVM (ViewModel + StateFlow) |
| Status | Scaffold / in progress — no shared networking or persistence layer yet |
| Issue | [#258](https://github.com/MWBMPartners/iHymns/issues/258) — user accounts & API integration |

### Project Structure

```
appAndroid/app/src/main/java/ltd/mwbmpartners/ihymns/
├── MainActivity.kt              # Single-activity entry point
├── AppInfo.kt                   # App metadata (versionCode/versionName are derived from this)
├── models/
│   └── Song.kt                  # Song data class
├── viewmodel/
│   └── SongViewModel.kt         # MVVM ViewModel (StateFlow)
└── ui/
    ├── Navigation.kt            # NavHost routing
    ├── theme/
    │   └── Theme.kt             # Material 3 theme
    └── screens/
        ├── HomeScreen.kt        # Home with songbook grid
        ├── SongListScreen.kt    # Song list for a songbook
        ├── SongDetailScreen.kt  # Song lyrics
        ├── FavoritesScreen.kt   # Favourites
        ├── SearchScreen.kt      # Search
        └── HelpScreen.kt        # In-app help
```

> **Corrected 2026-09-08.** The path above used to carry an extra `android/` segment
> (`…/ihymns/android/`) that does not exist — the package is `ltd.mwbmpartners.ihymns`, as
> `build.gradle.kts`'s own `namespace` says. `Theme.kt` was shown at `ui/Theme.kt` when it is really
> at `ui/theme/Theme.kt`, and `AppInfo.kt` was shown under a `Services/` folder that does not exist;
> it sits at the package root. The minimum SDK, the application ID and the Kotlin version in the
> table above were wrong for the same reason — see the corrected rows there. (The Kotlin number was
> not only wrong here: `appAndroid/build.gradle.kts`'s own header comment still says "Kotlin 2.1.x"
> three lines above the `version "2.4.10"` it is describing.)

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

**As of the 2026-08-28/29 API-coverage program, "the same API" now covers curator/admin functions too, not just consumer reads/writes.** Previously a native app could reach every `admin_*`/consumer action in `api.php` (it has accepted `Authorization: Bearer` from the start) but had no way to reach the song-editor API — `manage/editor/api2.php` gated on the `/manage` session cookie alone, which a native app has no way to present. That API now accepts `Authorization: Bearer <token>` as well, resolved through the same token verifier `api.php` uses (`apiTokenResolveBearerUser()` in `includes/api_tokens.php`) — see [[Architecture]] § API coverage. In practice this means a native **curator** app is now technically possible against the existing backend: every one of api2's 74 song-editing actions (metadata, structure/lyrics, media, links, credits, tags, bulk ops, revisions) plus the ~90 new `admin_*`/`org_admin_*` registry-CRUD and org-self-service actions are reachable with the same bearer token a signed-in user already holds — subject to that user's own role/entitlements, exactly as on the web. Neither shipped native app has built curator-facing UI against this yet; the seam exists and is guard-tested (`tests/php/test-editor-api-bearer-auth.php`) so a future native editor is a client-side project, not a backend one.

### Push notifications

| Platform | Mechanism | Status |
|---|---|---|
| Apple | APNs, `apns_register`/`apns_unregister` + `includes/apns.php` | Live — implements the real HTTP/2 request end-to-end; dormant only until a device actually registers |
| Web (PWA) | Web Push (VAPID, RFC 8292) + payload encryption (RFC 8291/8188), `push_subscribe`/`push_unsubscribe` | Implemented and RFC-vector-verified; dormant until a site operator generates a keypair |
| Android / Fire OS | FCM (ordinary Android) / ADM (Fire OS — no Google Play Services), `fcm_register`/`fcm_unregister` + `includes/fcm.php` + `tblPushTokens` | **Dormant scaffold only**, added in the 2026-08-28 API-coverage program (C1) |

The Android/FireOS pair is registration-only today: `fcm_register` validates the token + a `Provider` value (`fcm` or `adm` — `VARCHAR`, app-validated, never an `ENUM`, since a third provider some day should be one more allowed string, not a migration) and upserts it into the new `tblPushTokens` table (see [[Database & Migrations]]). `includes/fcm.php`'s `fcmSend()` deliberately performs **no** network I/O in either the unkeyed or the keyed case yet — it always returns a structured `not_configured`/`not_implemented` result. This is a scope boundary, not an oversight: FCM (HTTP v1, service-account JWT → OAuth2 → `POST .../messages:send`) and ADM (Login-With-Amazon `client_id`/`client_secret` → bearer token → send) are two different real protocols that are separately gated later work, needing owner-provisioned credentials (`fcm_server_key`, `adm_client_id`, `adm_client_secret` — all registered as encrypted-at-rest secrets) before either can send a single notification. Guard: `tests/php/test-fcm-scaffold.php`.

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
| Register for Android/FireOS push (dormant) | `POST ?action=fcm_register` |
| Song-editor write (curator; `Authorization: Bearer`) | `POST /manage/editor/api2.php?action=save_song` (+ 73 sibling actions — see [[API Reference]]) |

> **Corrected 2026-09-08.** The editor-API action count on this page said **66** in two places. Run
> against the tree today, the same tokeniser the guards use (`tests/php/lib/dispatch_parser.php`)
> counts **74** — the number [[API Reference]] already carried, so the two pages disagreed with each
> other. Treat any figure like this as orientation rather than a pinned contract: it moves with every
> feature, and the way to get today's number is to run the parser, not to read it here.

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
