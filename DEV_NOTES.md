# 🛠 iHymns — Developer Notes

> Technical notes, decisions, deployment setup, and key documentation for contributors.

---

## 📋 Table of Contents

- [Deployment Keys & Secrets](#-deployment-keys--secrets)
- [Song Data Format](#-song-data-format)
- [Architecture Decisions](#-architecture-decisions)
- [Deployment Architecture](#-deployment-architecture)
- [Development Environment](#-development-environment)
- [Commit Message Conventions](#-commit-message-conventions)

---

## 🔑 Deployment Keys & Secrets

All automated deployment is handled via GitHub Actions. Secrets and variables are configured in **GitHub Repo Settings → Secrets and Variables → Actions**.

### 🌐 Web/PWA — SFTP Deployment

The web app deploys via SFTP using `lftp` (mirroring the phpWhoIs pattern).

| Secret/Variable | Required | Description |
| --- | --- | --- |
| `SFTP_HOST` | ✅ Secret | SFTP server hostname (e.g., `ftp.ihymns.app` or `ihymns.app`) |
| `SFTP_PORT` | ❌ Secret | SFTP port number. Defaults to `22` if empty/blank/non-numeric |
| `SFTP_USER` | ✅ Secret | SFTP username for authentication |
| `SFTP_KEY` | ⭐ Secret | SSH private key for key-based auth (preferred over password) |
| `SFTP_PASSWORD` | ⭐ Secret | SFTP password (fallback if `SFTP_KEY` is not set) |
| `SFTP_LIVE_PATH` | ✅ Secret | Remote path for production (e.g., `/home/user/ihymns.app/public_html/`) |
| `SFTP_BETA_PATH` | ✅ Secret | Remote path for beta (e.g., `/home/user/beta.ihymns.app/public_html/`) |
| `SFTP_DEV_PATH` | ❌ Secret | Remote path for alpha/dev (e.g., `/home/user/dev.ihymns.app/public_html/`) |
| `SFTP_PRIVATE_PATH` | ❌ Secret | Remote path for private_html (e.g., `/home/user/admin.ihymns.app/`) — song editor, admin tools |
| `SFTP_ENABLED` | ✅ Variable | Set to `true` to enable SFTP deployment (kill switch). **Must be a Variable, not a Secret.** |

> ⭐ Either `SFTP_KEY` or `SFTP_PASSWORD` is required. SSH key auth is preferred.

#### How to get SFTP credentials

1. **DreamHost / shared hosting**: Go to your hosting control panel → Manage Users → create or use an existing SFTP/Shell user. The hostname is typically your domain or a server like `ftp.ihymns.app`.

2. **SSH Key setup**:

   ```bash
   # Generate a dedicated deploy key (no passphrase)
   ssh-keygen -t ed25519 -C "github-deploy@ihymns.app" -f ~/.ssh/ihymns_deploy -N ""

   # Copy the public key to your server
   ssh-copy-id -i ~/.ssh/ihymns_deploy.pub user@ihymns.app

   # The PRIVATE key (~/.ssh/ihymns_deploy) goes into the SFTP_KEY secret
   cat ~/.ssh/ihymns_deploy
   # Copy the entire output (including BEGIN/END lines) into GitHub secret
   ```

3. **Remote paths**: These are the absolute server paths where files should be uploaded. On DreamHost, typically `/home/<username>/<domain>/` (e.g., `/home/ihymns/ihymns.app/`).

---

### 🍎 Apple — App Store, TestFlight, Direct Distribution

The Apple app builds via GitHub Actions with Fastlane on a macOS runner.

| Secret | Required | Description |
| --- | --- | --- |
| `APPLE_TEAM_ID` | ✅ | Apple Developer Team ID (10-character alphanumeric) |
| `ASC_KEY_ID` | ✅ | App Store Connect API Key ID |
| `ASC_ISSUER_ID` | ✅ | App Store Connect API Issuer ID |
| `ASC_API_KEY` | ✅ | App Store Connect API Private Key (`.p8` file contents) |
| `MATCH_GIT_URL` | ✅ | Git repo URL for Fastlane Match certificate storage |
| `MATCH_PASSWORD` | ✅ | Encryption password for Fastlane Match |

#### How to get Apple credentials

##### 1. Apple Developer Team ID

1. Sign in to [Apple Developer](https://developer.apple.com/account)
2. Go to **Membership Details**
3. Your **Team ID** is displayed (e.g., `A1B2C3D4E5`)

##### 2. App Store Connect API Key

1. Sign in to [App Store Connect](https://appstoreconnect.apple.com)
2. Go to **Users and Access → Integrations → App Store Connect API**
3. Click **Generate API Key**
4. Select **Admin** role
5. Download the `.p8` file — this is your `ASC_API_KEY` (paste the full file contents)
6. Note the **Key ID** → `ASC_KEY_ID`
7. Note the **Issuer ID** at the top of the page → `ASC_ISSUER_ID`

> ⚠️ The `.p8` file can only be downloaded ONCE. Store it securely.

##### 3. Fastlane Match (Code Signing)

Fastlane Match stores signing certificates and provisioning profiles in a private Git repo.

```bash
# Install Fastlane
gem install fastlane

# Set up Match (run once, follow prompts)
cd appApple
fastlane match init

# Choose 'git' storage
# Enter the URL of a PRIVATE Git repo for certificate storage
# This URL goes into MATCH_GIT_URL secret

# Generate certificates for each type
fastlane match appstore    # For App Store / TestFlight
fastlane match adhoc       # For direct distribution
fastlane match development # For development

# The password you set during init goes into MATCH_PASSWORD secret
```

##### 4. Notarisation (Direct macOS Distribution)

For distributing `.dmg`/`.pkg` outside the App Store, apps must be notarised:

- Notarisation uses the same App Store Connect API key (`ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_API_KEY`)
- Fastlane's `notarize` action handles this automatically
- Ensure **Hardened Runtime** is enabled in Xcode project settings

---

### 🤖 Android — Google Play Store

The Android app builds via GitHub Actions with Gradle.

| Secret | Required | Description |
| --- | --- | --- |
| `ANDROID_KEYSTORE_BASE64` | ✅ | Release keystore file, base64-encoded |
| `ANDROID_KEYSTORE_PASSWORD` | ✅ | Password for the keystore |
| `ANDROID_KEY_ALIAS` | ✅ | Alias of the signing key within the keystore |
| `ANDROID_KEY_PASSWORD` | ✅ | Password for the specific key alias |
| `PLAY_SERVICE_ACCOUNT_JSON` | ❌ | Google Play Console service account JSON (for automated upload) |
| `PLAY_STORE_ENABLED` | ❌ Variable | Set to `true` to enable Play Store upload |

#### How to get Android credentials

##### 1. Generate a Release Keystore

```bash
# Generate a new keystore (run once, store securely)
keytool -genkey -v \
  -keystore ihymns-release.jks \
  -keyalg RSA -keysize 2048 \
  -validity 10000 \
  -alias ihymns \
  -storepass YOUR_STORE_PASSWORD \
  -keypass YOUR_KEY_PASSWORD \
  -dname "CN=MWBM Partners Ltd, O=MWBM Partners Ltd, C=GB"

# Base64-encode the keystore for the GitHub secret
base64 -i ihymns-release.jks | pbcopy
# Paste the clipboard contents into ANDROID_KEYSTORE_BASE64 secret
```

> ⚠️ **NEVER lose the keystore.** If lost, you cannot update your app on Google Play. Store it in a secure vault (e.g., 1Password, Bitwarden).

##### 2. Google Play Console Service Account

1. Go to [Google Play Console](https://play.google.com/console)
2. Go to **Settings → API Access**
3. Click **Create new service account**
4. Follow the link to **Google Cloud Console**
5. Create a service account with role **Service Account User**
6. Create a JSON key → download the `.json` file
7. Back in Play Console, grant the service account **Release Manager** permission
8. Paste the entire JSON file contents into `PLAY_SERVICE_ACCOUNT_JSON` secret

---

### 🔥 Amazon Fire OS — Amazon Appstore

Fire OS uses the **same APK** as standard Android (no Google Play Services dependency). Distribution to Amazon Appstore is currently manual.

| Requirement | Details |
| --- | --- |
| **Amazon Developer Account** | Sign up at [developer.amazon.com](https://developer.amazon.com) |
| **Signing** | Uses the same Android keystore (`ANDROID_KEYSTORE_BASE64`) |
| **APK** | The release APK built by `build-android.yml` is Fire OS compatible |
| **Upload** | Manual via [Amazon Appstore Developer Console](https://developer.amazon.com/apps-and-games) |

#### How to submit to Amazon Appstore

1. Sign in to [Amazon Developer Console](https://developer.amazon.com)
2. Go to **Apps & Games → Add a New App → Android**
3. Fill in app details (title, description, category, icons, screenshots)
4. Upload the **release APK** from the GitHub Actions build artifact (`android-release-apk`)
5. Set device support: **Fire tablets, Fire TV, Fire TV Stick**
6. Submit for review

> 💡 **Future automation**: Amazon provides the [App Submission API](https://developer.amazon.com/docs/app-submission-api/overview.html) which can be integrated into GitHub Actions. Create a secret `AMAZON_CLIENT_ID` and `AMAZON_CLIENT_SECRET` when ready.

#### Fire OS Compatibility Notes

- The iHymns Android app has **zero Google Play Services dependencies** — fully compatible with Fire OS
- Fire TV uses the **Leanback** library (included in `build.gradle.kts`)
- `AndroidManifest.xml` includes `LEANBACK_LAUNCHER` intent filter for Fire TV
- `android.hardware.touchscreen` is set to `required="false"` for Fire TV (remote-only navigation)

---

### 📋 Quick Setup Checklist

#### Minimum for Web/PWA deployment

- [ ] Set `SFTP_HOST` secret
- [ ] Set `SFTP_USER` secret
- [ ] Set `SFTP_KEY` or `SFTP_PASSWORD` secret
- [ ] Set `SFTP_LIVE_PATH` secret
- [ ] Set `SFTP_BETA_PATH` secret
- [ ] Set `SFTP_ENABLED` **variable** to `true`
- [ ] (Optional) Set `SFTP_PRIVATE_PATH` for song editor/admin tools deployment

#### Minimum for Apple deployment

- [ ] Set `APPLE_TEAM_ID` secret
- [ ] Set `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_API_KEY` secrets
- [ ] Set `MATCH_GIT_URL`, `MATCH_PASSWORD` secrets
- [ ] Run `fastlane match appstore` locally once to generate certificates

#### Minimum for Android deployment

- [ ] Generate release keystore and set `ANDROID_KEYSTORE_BASE64` secret
- [ ] Set `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` secrets
- [ ] (Optional) Set `PLAY_SERVICE_ACCOUNT_JSON` and `PLAY_STORE_ENABLED` for auto-upload

#### Amazon Fire OS

- [ ] Create Amazon Developer account
- [ ] Use the same Android release APK (no separate build needed)
- [ ] Submit manually via Amazon Developer Console

---

## 📂 Song Data Format

### File Naming Convention

Songs are stored in `.SourceSongData/<Songbook Name> [<Abbreviation>]/`

**Filename patterns vary by songbook:**

| Songbook | Pattern | Example |
| --- | --- | --- |
| Church Hymnal (CH) | `NNN (CH) - Title.txt` | `003 (CH) - Come, Thou Almighty King.txt` |
| SDA Hymnal (SDAH) | `NNN (SDAH) - Title.txt` | `001 (SDAH) - Praise to the Lord.txt` |
| Mission Praise (MP) | `NNNN (MP) - Title.txt` | `0001 (MP) - A New Commandment.txt` |
| Junior Praise (JP) | `NNN (JP) - Title.txt` | `001 (JP) - A Boy Gave To Jesus.txt` |
| Carol Praise (CP) | `NNN (CP) - Title.txt` | `001 (CP) - A Baby Was Born In Bethlehem.txt` |

**Companion files** (MP, JP, CP only):

- `*_audio.mid` — MIDI audio file
- `*_music.pdf` — Sheet music PDF

### Text File Structure

```text
"Song Title"            ← Line 1: Title in double quotes

1                       ← Verse number (standalone digit)
First line of verse,
Second line of verse,
...

Refrain                 ← Or "Chorus" — label on its own line
First line of refrain,
...

2                       ← Next verse
...

Words and music by ...  ← Writer/composer credits (some files only)
© Copyright holder      ← Copyright info (some files only)
Language: fr-FR         ← Optional IETF BCP 47 language tag (defaults to songbook language)
```

### Key Observations

1. **Title format**: Always in double quotes on line 1 (except SDAH — no quotes)
2. **Verse numbering**: Standalone integer on its own line
3. **Chorus/Refrain**: Labelled as "Refrain", "Chorus", or similar
4. **Writer credits**: Present in MP, JP, CP songbooks; absent in CH, SDAH
5. **Encoding**: UTF-8, some files contain special characters (curly quotes, em dashes)
6. **Song component order**: Components appear in the order they are sung
7. **No consistent blank line rules**: Some files have extra blank lines, parser must be tolerant
8. **Language tag**: Optional `Language: xx` line (IETF BCP 47 format, e.g., `en`, `fr-FR`, `zh-Hans-CN`). Falls back to songbook default if absent

---

## 🏗 Architecture Decisions

### Why Vanilla JS (not React/Vue/Angular)?

- Simpler build pipeline for PHP shared hosting
- Smaller bundle size (critical for PWA/offline)
- No framework lock-in
- Easy for contributors to understand
- Bootstrap handles responsive layout
- ES modules provide sufficient modularity

### Why PHP (not static-only)?

- Universal support on shared hosting (DreamHost, etc.)
- Server-side version injection (`infoAppVer.php`)
- Dynamic development status detection (Alpha/Beta/Production via directory path)
- Consistent with other MWBM Partners Ltd projects (phpWhoIs/DomainCheckr)

### Why JSON (not SQLite/IndexedDB) for Phase ONE?

- Simplicity — songs.json loaded once, searched in-memory
- Portable — same file used by web, Apple, Android
- ~3,600 songs ≈ ~5 MB JSON (acceptable for PWA cache)
- Fuse.js handles fuzzy search efficiently in-browser
- Phase TWO will move to proper database (iLyrics dB API)

### Why `appWeb/`, `appApple/`, `appAndroid/` naming?

- Consistent `app<Platform>/` prefix across all platforms
- Clearer separation in the directory tree
- Matches original repo convention

---

## 🚀 Deployment Architecture

### Branch → Directory → Server

| Branch | Source Directory | SFTP Path Secret | Environment |
| --- | --- | --- | --- |
| `main` | `appWeb/public_html/` | `SFTP_LIVE_PATH` | Production |
| `beta` | `appWeb/public_html/` | `SFTP_BETA_PATH` | Beta |
| `alpha` | `appWeb/public_html/` | `SFTP_DEV_PATH` | Alpha/Dev |

### Deployment Flow

1. All development happens in `appWeb/public_html/`
2. Push to `alpha` → SFTP uploads `public_html/` → remote `public_html_dev/`
3. Push to `beta` → auto version bump → minify → SFTP uploads `public_html/` → remote `public_html_beta/`
4. Push to `main` → SFTP uploads `public_html/` → remote `public_html/`
5. All branches also deploy `appWeb/data_share/` → remote `data_share/` (without `--delete`)
6. Uses `lftp mirror --reverse --delete --only-newer` for efficient sync

### Commit Message Flags

| Flag | Effect |
| --- | --- |
| `[deploy all]` | Force full SFTP upload even if no files changed |
| `[skip sync]` | (Deprecated — no longer used) |
| `[skip ci]` | Skip changelog, version-bump, and deploy workflows |

### Version Numbering

- **Semver**: `v1.x.x` (Phase 1 — local JSON) / `v2.x.x` (Phase 2 — iLyrics dB)
- Auto-bumped via conventional commits on `beta`:
  - `BREAKING CHANGE` or `!:` → major bump
  - `feat(...):` → minor bump
  - Everything else → patch bump
- Version stored in `appWeb/public_html/includes/infoAppVer.php`
- Build metadata (SHA, date) injected at deploy time
- Git tags `v*` trigger GitHub Releases

### Application IDs (per-platform)

| Platform | Application ID |
| --- | --- |
| Web/PWA | `Ltd.MWBMPartners.iHymns.PWA` |
| Apple | `Ltd.MWBMPartners.iHymns.Apple` |
| Android | `Ltd.MWBMPartners.iHymns.Android` |

---

## 🔧 Development Environment

### Recommended Setup

- **Editor**: VS Code or Xcode (for Apple development)
- **PHP**: 8.5+ (target version)
- **Node.js**: v22+ (LTS)
- **npm**: v10+
- **Xcode**: 16+ (for Swift 6.3)
- **Android Studio**: Latest (for Kotlin/Compose)
- **OS**: macOS (required for Apple development), also supports Windows and Raspberry Pi

### Quick Start

```bash
# Clone the repository
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns

# Parse song data (generates data/songs.json)
npm run parse-songs

# Start local PHP dev server (web app)
npm run dev
# → http://localhost:8000

# Run unit tests
npm test

# Build web app for deployment
npm run build:web
```

### IDE Extensions (VS Code)

- ESLint
- Prettier
- HTMLHint
- Stylelint
- PHP Intelephense
- Swift (for Apple development)

---

## 📝 Commit Message Conventions

This project uses [Conventional Commits](https://www.conventionalcommits.org/) for automated versioning:

```text
feat: add new feature              → minor version bump
fix: fix a bug                     → patch version bump
feat!: breaking change             → major version bump
BREAKING CHANGE: description       → major version bump
docs: update documentation         → patch version bump
refactor: restructure code         → patch version bump
chore: maintenance task            → patch version bump
test: add or update tests          → patch version bump
```

---

## MySQL Database Setup (v0.10.0+)

Starting with v0.10.0, iHymns uses MySQL as the primary data store, with JSON fallback for environments without a database. MySQL provides full-text search indexing, concurrent write safety, user accounts, and features like popular songs, song tags, and translation linking.

### Prerequisites

- **MySQL 5.7+** or **MariaDB 10.3+** with InnoDB support
- **PHP 8.1+** with the `mysqli` extension enabled
- A MySQL database created for iHymns:
  ```sql
  CREATE DATABASE ihymns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

### Step-by-Step Installation

#### Step 1: Run the Interactive Installer

```bash
php appWeb/.sql/install.php
```

The wizard prompts for:

| Prompt | Default | Description |
| --- | --- | --- |
| MySQL Host | `127.0.0.1` | Server hostname or IP |
| MySQL Port | `3306` | Server port |
| Database Name | `ihymns` | Must already exist |
| Username | `ihymns_user` | MySQL user with full privileges on the DB |
| Password | _(none)_ | Hidden input on supported terminals |
| Table Prefix | _(none)_ | Optional prefix (e.g., `ih_`) for shared databases |

The installer will:

1. Test the connection before writing anything
2. Write credentials to `appWeb/.auth/db_credentials.php` (permissions `0600`)
3. Create all 30+ tables from `schema.sql` (idempotent — safe to re-run)
4. Seed default data: user groups, 14 languages, 5 access tiers, app settings

> **Manual setup:** Copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php`, edit it, then re-run the installer.

#### Step 2: Migrate Song Data from JSON

```bash
php appWeb/.sql/migrate-json.php
```

This imports all songs from `data/songs.json` into MySQL:
- Clears existing song data and re-imports (transaction-wrapped)
- Populates: songbooks, songs, writers, composers, components
- Imports translation links from `songs[].translations` array
- Builds `LyricsText` column for MySQL FULLTEXT search

> Specify a custom path: `php appWeb/.sql/migrate-json.php --json=/path/to/songs.json`

#### Step 3: Create Initial Admin User

Navigate to `/manage/setup` in the browser. The first account becomes the **Global Admin**.

#### Step 4: Verify Installation

Navigate to `/manage/setup-database.php` to see:
- Database connection status
- Table row counts
- Run additional migrations (users, cleanup, backup)

#### Web-Based Setup (No CLI Required)

For shared hosting without SSH:

1. Copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php` and edit
2. Navigate to `/manage/setup-database.php`
3. Click **Run Install** (creates tables)
4. Click **Run Song Migration** (imports songs)
5. Visit `/manage/setup` (create admin account)

#### One-Shot Alternative

```bash
mysql -u user -p ihymns < appWeb/.sql/.fulldata/ihymns-full.sql
```

### JSON Fallback Mode

When MySQL is unavailable, the application automatically falls back to reading `data/songs.json`:

| Feature | MySQL Mode | JSON Fallback |
| --- | --- | --- |
| Song browsing & search | FULLTEXT index | Fuse.js client-side |
| Popular songs | Server view counts | Client localStorage history |
| Browse by theme (tags) | Tag tables | Hidden (no data) |
| Song view tracking | tblSongHistory | Silently skipped |
| User accounts | Full auth system | Not available |
| Translation links | tblSongTranslations | From songs.json |
| Song editor | Full CRUD | Read-only |
| Offline downloads | Bulk API | Per-song fallback |

API endpoints gracefully return empty arrays with `fallback: true` flag when the database is unavailable.

### Database Schema Overview

**Song Data (6 tables):**

| Table | Purpose |
| --- | --- |
| `tblSongbooks` | Songbook definitions (CP, JP, MP, SDAH, CH, Misc) |
| `tblSongs` | Core metadata + `LyricsText` for FULLTEXT search |
| `tblSongWriters` | Lyricist credits (many-to-one) |
| `tblSongComposers` | Composer credits (many-to-one) |
| `tblSongComponents` | Verses, choruses with lyrics as JSON lines array |
| `tblSongTranslations` | Links songs to translations in other languages |

**Discovery & Community (4 tables):**

| Table | Purpose |
| --- | --- |
| `tblSongHistory` | View tracking for popular songs ranking |
| `tblSongTags` | Thematic tag definitions (Easter, Advent, etc.) |
| `tblSongTagMap` | Song-to-tag mapping |
| `tblSongRequests` | User-submitted song requests |

**Languages (1 table):**

| Table | Purpose |
| --- | --- |
| `tblLanguages` | 14 supported languages with text direction (ltr/rtl) |

**User Accounts & Access (10+ tables):**

| Table | Purpose |
| --- | --- |
| `tblUsers` | Accounts with role-based access |
| `tblUserGroups` | Version channel access (Alpha/Beta/RC/RTW) |
| `tblSessions` / `tblApiTokens` | Admin panel sessions and API auth tokens |
| `tblAccessTiers` | Content gating levels (public → pro) |
| `tblOrganisations` | Church/organisation licensing |
| `tblUserSetlists` | Cross-device setlist sync |
| `tblActivityLog` | Audit trail |

Full schema: `appWeb/.sql/schema.sql` (30+ tables, ~50 KB)

### Key API Endpoints

| Endpoint | Description |
| --- | --- |
| `?action=bulk_songs&songbook=X` | Bulk download: all rendered HTML for a songbook in one response |
| `?action=songs_json` | Full songs.json export with ETag caching |
| `?action=song_translations&id=X` | Bidirectional translation lookup |
| `?action=popular_songs&period=month` | Popular songs by view count |
| `?action=tags` | All thematic tags |
| `?page=song&id=X` | Rendered song page HTML (cached by service worker) |

### File Structure

```text
appWeb/
├── .auth/
│   ├── .htaccess                      # Blocks web access
│   ├── db_credentials.example.php     # Template (tracked in git)
│   └── db_credentials.php             # Credentials (NOT in git)
├── .sql/
│   ├── schema.sql                     # Full MySQL schema
│   ├── install.php                    # Interactive table installer
│   ├── migrate-json.php              # JSON → MySQL migration
│   ├── migrate-users.php             # User/setlist migration
│   ├── cleanup.php                    # Token/session cleanup
│   ├── backup.php                     # Database backup
│   └── .fulldata/
│       ├── generate-full-sql.php      # One-shot SQL generator
│       └── ihymns-full.sql            # Pre-built full SQL (~6.8 MB)
└── public_html/
    ├── includes/
    │   ├── db_mysql.php               # MySQLi connection factory
    │   └── SongData.php               # Song data (MySQL + JSON fallback)
    └── manage/
        ├── setup.php                  # Initial admin setup
        └── setup-database.php         # Web DB admin dashboard
```

### Troubleshooting

| Issue | Solution |
| --- | --- |
| "Database credentials file not found" | Copy `db_credentials.example.php` to `db_credentials.php` |
| "Failed to connect to MySQL" | Check host, port, username, password in credentials file |
| "Unknown database 'ihymns'" | Create the database: `CREATE DATABASE ihymns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` |
| "Table already exists" | Normal — the installer uses `CREATE TABLE IF NOT EXISTS` |
| "Migration failed — all changes rolled back" | Check error message; fix and re-run |
| Popular Songs shows "Loading..." | Database required for server-side view tracking; falls back to localStorage |
| Browse by Theme missing | Tags must be populated in `tblSongTags` via the admin tools |

### Architecture: Why MySQL + JSON Fallback?

MySQL is the primary store for:
1. **Full-text search** — FULLTEXT indexes on title and lyrics
2. **Concurrent writes** — Multiple editors can safely modify data
3. **User accounts** — Relational storage for users, groups, permissions
4. **View tracking** — Popular songs ranking from `tblSongHistory`
5. **Tags & translations** — Structured relational data

JSON fallback ensures the app works everywhere:
- Shared hosting without MySQL
- Development without database setup
- Offline via service worker cached `songs.json`
- The PWA client always has `songs.json` for Fuse.js fuzzy search

### User Groups & Version Access

| Group | Alpha | Beta | RC | RTW |
| --- | --- | --- | --- | --- |
| Developers | Yes | Yes | Yes | Yes |
| Beta Testers | No | Yes | Yes | Yes |
| RC Testers | No | No | Yes | Yes |
| Public | No | No | No | Yes |

Users inherit access from their group. The app checks group permissions to gate access to deployment channels (Alpha = `dev.ihymns.app`, Beta = `beta.ihymns.app`).

---

## Content Access Tiers & CCLI Licensing

### Overview

iHymns uses a tiered content access system to control what songs, media, and features a user can access. Tiers are defined in `tblAccessTiers` and assigned per-user (`tblUsers.AccessTier`) or per-organisation (`tblOrganisations.LicenceType`).

**Content gating is OFF by default** (`content_gating_enabled=0` in `tblAppSettings`). Set to `1` to enforce tier restrictions.

### Default Tiers

| Tier | Level | Lyrics | Copyrighted | Audio | MIDI DL | PDF DL | Offline | CCLI Req'd |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **Public** | 0 | Yes | - | - | - | - | - | - |
| **Free** | 10 | Yes | Yes | - | - | - | - | - |
| **CCLI** | 20 | Yes | Yes | Yes | - | - | - | Yes |
| **Premium** | 30 | Yes | Yes | Yes | Yes | Yes | Yes | - |
| **Pro** | 40 | Yes | Yes | Yes | Yes | Yes | Yes | - |

Each tier inherits all capabilities of lower tiers. The `Level` column determines the hierarchy — higher level = more access.

### Tier Resolution (Personal vs Organisation)

A user's **effective tier** is the **highest** of:

1. Their personal `AccessTier` on `tblUsers`
2. Any tier inherited from their organisation memberships

For example:

- User has personal tier `free` (level 10)
- User belongs to a church with `ihymns_pro` licence (maps to `premium`, level 30)
- **Effective tier = `premium`** (the higher of the two)

This is resolved by `resolveEffectiveTier()` in `ccli_validator.php`.

### Organisation Licence → Tier Mapping

| Org LicenceType | Maps to Tier |
| --- | --- |
| `none` | public |
| `ihymns_basic` | free |
| `ccli` | ccli |
| `ihymns_pro` / `premium` | premium |
| `pro` | pro |

### CCLI Licence Numbers

CCLI (Christian Copyright Licensing International) licence numbers are:
- 5–8 digit numeric identifiers
- Assigned to churches/organisations for copyright compliance
- Validated via `validateCcliNumber()` in `ccli_validator.php`
- Stored on `tblUsers.CcliNumber` with `CcliVerified` flag

When a user enters a valid CCLI number:
1. Format validated (5-8 digits, numeric only)
2. Stored and marked as verified
3. If user is on `free` tier, auto-upgraded to `ccli`

### Adding New Tiers

To create a new tier:

```sql
INSERT INTO tblAccessTiers (Name, DisplayName, Level, Description,
    CanViewLyrics, CanViewCopyrighted, CanPlayAudio,
    CanDownloadMidi, CanDownloadPdf, CanOfflineSave, RequiresCcli)
VALUES ('church_basic', 'Church Basic', 15,
    'Church plan with copyrighted songs and audio.',
    1, 1, 1, 0, 0, 0, 0);
```

Then update `checkTierAccess()` in `ccli_validator.php` to include the new tier in the capability matrix, and add the tier name to the `$validTiers` array in the `admin_set_user_tier` API endpoint.

### Setting Up Pricing / Prerequisites

Pricing and payment integration are managed via `tblUserPurchases`:

| Column | Purpose |
| --- | --- |
| `ProductType` | `tier_upgrade`, `songbook_unlock`, `feature_unlock`, `subscription` |
| `TierGranted` | Which tier this purchase unlocks |
| `Amount` / `Currency` | Payment amount (GBP default) |
| `Status` | `active`, `expired`, `refunded`, `cancelled` |
| `ExpiresAt` | NULL for one-off purchases; date for subscriptions |

To set up a paid tier:

1. Create the tier in `tblAccessTiers`
2. Configure your payment processor (Stripe, PayPal, etc.)
3. On successful payment, insert a row into `tblUserPurchases`
4. Update the user's `AccessTier` to the purchased tier

### Restricting Media Access

Audio playback, MIDI downloads, and PDF sheet music downloads are controlled by tier capabilities:

| Media Type | Controlled By | API Check |
| --- | --- | --- |
| Audio playback (MIDI) | `CanPlayAudio` | `?action=tier_check&check=play_audio` |
| MIDI file download | `CanDownloadMidi` | `?action=tier_check&check=download_midi` |
| Sheet music PDF | `CanDownloadPdf` | `?action=tier_check&check=download_pdf` |
| Offline song save | `CanOfflineSave` | `?action=tier_check&check=offline_save` |

The frontend should call `tier_check` before showing media controls. If denied, show an upgrade prompt with the `upgradeTo` tier from the response.

### Admin Management

Tier management is restricted to **Admin** and **Global Admin** roles only.

**API endpoints (Admin/Global Admin):**

| Endpoint | Method | Description |
| --- | --- | --- |
| `admin_set_user_tier` | POST | Set a user's access tier |
| `admin_set_user_ccli` | POST | Validate and set a user's CCLI number |
| `access_tiers` | GET | List all available tiers |

**Web dashboard:** `/manage/setup-database` for database administration.

### Combining with Other Access Controls

Content tiers work alongside other gating mechanisms:

1. **Content Restrictions** (`tblContentRestrictions`) — block specific songs/songbooks by org/user/platform
2. **User Groups** (`tblUserGroups`) — version channel access (Alpha/Beta/RC/RTW)
3. **Organisation Licences** (`tblContentLicences`) — per-org songbook and feature access
4. **Tiers** — broad capability levels for media and content types

The most restrictive rule wins when multiple systems overlap.

---

Last updated: 2026-04-16
