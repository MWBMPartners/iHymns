# Architecture

> Technical architecture of the iHymns multiplatform application

---

## Project Structure

```text
iHymns/
├── .claude/                  # Claude AI context & project brief
├── .github/workflows/        # CI/CD: deploy, version bump, changelog, tests
├── .SourceSongData/           # Raw song text files (source of truth — DO NOT MODIFY)
├── tools/                    # Build tools & song data parser
│   ├── parse-songs.js        #   Parses .SourceSongData/ → songs.json
│   └── build-web.js          #   Web build/packaging script
├── data/                     # Generated song data
│   ├── songs.json            #   Canonical song database (single source of truth)
│   └── songs.schema.json     #   JSON Schema (draft 2020-12) for validation
├── tests/                    # 33 unit tests
├── appWeb/                   # Web PWA application
│   ├── public_html/          #   Deployed source (single source for all environments)
│   │   ├── index.php         #     SPA shell — OG tags, CSP, JSON-LD
│   │   ├── api.php           #     AJAX API — pages, search, auth, setlists
│   │   ├── includes/         #     PHP components, pages, config
│   │   ├── js/               #     ES modules architecture
│   │   │   ├── app.js        #       Main app bootstrap (25+ modules)
│   │   │   ├── modules/      #       Feature modules (router, search, setlist, etc.)
│   │   │   └── utils/        #       Utilities (html.js, text.js, components.js)
│   │   ├── css/              #     Stylesheets
│   │   └── manage/           #     Admin area (editor, users, auth)
│   ├── data_share/           #   Shared data (songs.json, setlists, SQLite DB)
│   └── private_html/         #   Private admin tools (song editor — legacy)
├── appApple/                 # Native Apple app (Swift 6.3 / SwiftUI)
├── appAndroid/               # Native Android app (Kotlin 2.1 / Jetpack Compose)
├── help/                     # User documentation (6 guides)
└── wiki/                     # GitHub Wiki source pages
```

---

## Web PWA Architecture

### SPA Pattern

The PWA is a single-page application served from `index.php`. All URLs are rewritten via `.htaccess` to `index.php`, which:

1. Generates a unique CSP nonce per request
2. Detects the URL path for Open Graph meta tags and JSON-LD structured data
3. Renders the HTML shell (header, content area, footer)
4. Loads the JS app which handles client-side routing via History API

### JavaScript Module Architecture

The app uses **ES modules** with a central `iHymnsApp` class that coordinates 25+ feature modules:

```
iHymnsApp
├── Router          — History API routing, AJAX page loading
├── Transitions     — Page transition animations
├── Settings        — Theme, motion, font size, analytics consent
├── Search          — Fuse.js search with TF-IDF related songs
├── Favorites       — Favourite songs (localStorage)
├── SetList         — Setlists with custom arrangements
├── UserAuth        — Bearer token auth, cross-device sync
├── PWA             — Install banner, service worker
├── Audio           — MIDI playback
├── SheetMusic      — PDF sheet music viewer
├── History         — Recently viewed songs
├── Display         — Presentation mode, font prefs
├── Compare         — Side-by-side song comparison
├── Shortcuts       — Keyboard shortcuts overlay
├── Numpad          — Numeric keypad for song number search
├── Share           — Song sharing (Web Share API)
├── Shuffle         — Random song picker
├── Transpose       — Capo/transpose indicator
├── ReadingProgress — Scroll-linked progress bar
├── SongbookIndex   — Alphabetical songbook index
├── SearchHistory   — Recent search terms
├── SongOfTheDay    — Daily featured song
├── OfflineIndicator— Online/offline status
├── StorageBridge   — Cross-domain localStorage sync
├── SubdomainSync   — Subdomain cookie sync
├── Gestures        — Touch swipe navigation
├── Analytics       — GA4, Plausible, Clarity, Matomo, Fathom
└── Request         — Missing song request form
```

### PHP Server Architecture

```
index.php           — SPA shell (OG tags, CSP nonce, JSON-LD)
api.php             — AJAX API (pages, search, auth, setlists)
├── includes/
│   ├── config.php      — Centralised configuration
│   ├── infoAppVer.php  — App version metadata
│   ├── SongData.php    — Song data handler class
│   ├── components/     — Reusable PHP components
│   └── pages/          — Page templates (home, song, setlist, etc.)
└── manage/
    ├── includes/
    │   ├── auth.php     — Authentication middleware (roles, sessions, CSRF)
    │   └── db.php       — Database connection factory (SQLite/MySQL/SQL Server)
    ├── editor/          — Song editor (requires editor+ role)
    ├── users.php        — User management (requires admin+ role)
    ├── setup.php        — First-run Global Admin setup
    ├── login.php        — Admin login page
    └── logout.php       — Admin logout
```

### Data Flow

```
.SourceSongData/ (raw text files)
        │
        ▼  tools/parse-songs.js
data/songs.json (canonical, 5.22 MB)
        │
        ├──▶ appWeb/data_share/song_data/songs.json (deployed copy)
        ├──▶ appApple/ (bundled in app)
        └──▶ appAndroid/ (bundled in assets)
```

---

## Native App Architecture

### Apple (Swift 6.3 / SwiftUI)

- **Pattern**: Observable object (`SongStore`) + SwiftUI views
- **Data**: Bundled `songs.json` from app bundle
- **Persistence**: UserDefaults for favorites
- **Platforms**: Universal — iPhone, iPad, Mac, Apple TV, Vision Pro, Watch
- **UI**: `TabView` (iPhone) / `NavigationSplitView` (iPad/Mac)

### Android (Kotlin 2.1 / Jetpack Compose)

- **Pattern**: MVVM with `SongViewModel` + StateFlow
- **Data**: Bundled `songs.json` from assets
- **Persistence**: SharedPreferences for favorites
- **Platforms**: Phone, Tablet, Android TV, Fire OS
- **UI**: Single-activity, NavHost navigation
- **Compatibility**: Zero Google Play Services dependencies (Fire OS compatible)

---

## Database

SQLite (default) with migration support for MySQL/MariaDB and SQL Server. See [[Database & Migrations]] for details.

---

## Security

- Content Security Policy with per-request nonces
- SRI hashes on all CDN resources
- CSRF tokens for form submissions
- BCRYPT password hashing (cost 12)
- Bearer token auth for public API (64-char hex)
- Session-based auth for admin panel
- See [[Security]] for full details
