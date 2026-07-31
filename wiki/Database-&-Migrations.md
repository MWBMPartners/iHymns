# Database & Migrations

> MySQL database, schema design, interactive installer, and data migration

---

## Overview

iHymns uses **MySQL** (v5.7+ / MariaDB 10.3+) as the primary data store for all application data:

- **Song data** — songbooks, songs, writers, composers, lyrics components
- **User accounts** — authentication, sessions, API tokens, password resets
- **User groups** — role-based access control with version channel gating
- **User data** — setlists, favorites (server-side sync)
- **Community** — song requests, activity log
- **Multilingual** — languages, song translations
- **Configuration** — runtime app settings

**All** queries — song data, admin panel auth, everything — use **MySQLi with prepared statements** via the single `getDbMysqli()` connection factory. **PDO was fully removed from the codebase** (#554/#555); there is no separate driver for the admin panel.

---

## Setup

### Interactive Installer

The recommended way to set up the database:

```bash
php appWeb/.sql/install.php
```

The installer will:
1. Prompt for MySQL host, port, database name, username, password, and optional table prefix
2. Test the connection
3. Write credentials to `appWeb/.auth/db_credentials.php` (permissions `0600`)
4. Create all tables from `appWeb/.sql/schema.sql`
5. Seed default user groups and languages

### Manual Configuration

If the interactive installer cannot be used (non-interactive shell, web server):

1. Copy `appWeb/.auth/db_credentials.example.php` to `appWeb/.auth/db_credentials.php`
2. Edit the credentials manually
3. Run `php appWeb/.sql/install.php` to create tables

### Data Migration

After table creation, `data/songs.json` can be imported as a **one-time seed** — this is the only role `songs.json` plays; every runtime read is live MySQL (epic #1010):

```bash
php appWeb/.sql/migrate-json.php --confirm
```

Without `--confirm` (CLI) / `?confirm=1` (web, via `/manage/setup-database`), the script only prints a pre-flight summary and does nothing — a bare unconfirmed request must never be able to truncate a live corpus.

---

## Database Schema

The full schema is defined in `appWeb/.sql/schema.sql`.

### Song Data Tables

| Table | Purpose |
|---|---|
| `tblSongbooks` | Songbook definitions (30+ songbooks, e.g. CP, JP, MP, SDAH, CH — see the live list at `/songbooks`) |
| `tblSongs` | Core song metadata + `LyricsText` for full-text search |
| `tblSongWriters` | Song lyricist credits (many-to-one) |
| `tblSongComposers` | Song composer credits (many-to-one) |
| `tblSongComponents` | Verses, choruses with lyrics as JSON lines array |

### User & Access Control Tables

| Table | Purpose |
|---|---|
| `tblUserGroups` | Groups with version channel access flags (Alpha/Beta/RC/RTW) |
| `tblUsers` | Accounts with role, group link, EmailVerified, LastLoginAt, LoginCount, AccessTier, CcliNumber, CcliVerified, and the `Status` / `StatusChangedAt` lifecycle pair (#1698 — `active` / `disabled` / `deleted`; see [[User Accounts & Roles]]) |
| `tblSessions` | Server-side admin panel sessions |
| `tblApiTokens` | Bearer tokens for PWA/native app auth (64-char hex, 30-day expiry) |
| `tblPasswordResetTokens` | Single-use password reset tokens (48-char hex, 1-hour expiry) |
| `tblEmailLoginTokens` | Magic link tokens + 6-digit codes for passwordless email login (10-min expiry) |
| `tblUserGroupMembers` | Many-to-many user-to-group membership |
| `tblUserPermissions` | Fine-grained per-user permission overrides (NULL = inherit from role) |
| `tblLoginAttempts` | Brute force tracking (IP, username, success/failure, timestamp) |
| `tblAccessTiers` | Content access tier definitions (id, name, level, description) |
| `tblUserPurchases` | Purchase and subscription tracking (user, tier, payment reference, expiry) |

### User Data Tables

| Table | Purpose |
|---|---|
| `tblUserSetlists` | Server-side setlist storage for cross-device sync |
| `tblUserFavorites` | Server-side favorites sync (song IDs per user) |

### Language & Translation Tables

| Table | Purpose |
|---|---|
| `tblLanguages` | ISO 639-1 language reference (code, name, native name, text direction) |
| `tblSongTranslations` | Links source songs to translations in other languages |

### Community & Engagement Tables

| Table | Purpose |
|---|---|
| `tblSongRequests` | User-submitted song suggestions with status tracking |
| `tblSongHistory` | Recently viewed songs tracking per user |
| `tblSongTags` | Song categories/themes (Easter, Communion, etc.) |
| `tblSongTagMap` | Many-to-many song-to-tag mapping |
| `tblNotifications` | In-app notification system for users |

### System Tables

| Table | Purpose |
|---|---|
| `tblActivityLog` | Audit trail for admin actions (edits, logins, imports) |
| `tblAppSettings` | Key-value runtime configuration store |
| `tblMigrations` | Schema migration version tracking |

---

## Content Tiers

iHymns uses a tiered content access system to gate premium features such as audio playback, MIDI files, and PDF sheet music.

### The 5 Tiers

| Level | Tier Name | Access |
|---|---|---|
| 0 | **Free** | Lyrics only (browse, search, setlists) |
| 1 | **Basic** | Lyrics + song metadata extras |
| 2 | **Standard** | Basic + MIDI audio playback |
| 3 | **Premium** | Standard + PDF sheet music downloads |
| 4 | **Ultimate** | All content, including future premium features |

### Tier Resolution

Users can have a **personal tier** (set on `tblUsers.AccessTier`) and an **organisation-level tier** (inherited from their user group via `tblAccessTiers`). When both exist, the **highest tier wins** — ensuring that a user belonging to a Premium organisation still gets Premium access even if their personal tier is Free.

Resolution order:

1. Read the user's personal `AccessTier` level from `tblUsers`
2. Read the tier level associated with the user's group(s)
3. Take `MAX(personal_tier, org_tier)` as the effective tier
4. Gate feature access based on the effective tier level

### Related Tables

- `tblAccessTiers` — defines each tier (id, name, level, description)
- `tblUserPurchases` — records purchases/subscriptions that grant a user a specific tier (includes payment reference, start date, expiry date)
- `tblUsers.AccessTier` — the user's current personal tier (FK to `tblAccessTiers`)
- `tblUsers.CcliNumber` — the user's CCLI licence number (validated format)
- `tblUsers.CcliVerified` — whether the CCLI number has been verified (0/1)

---

## Version Access Control

User groups control access to release channels:

| Group | Alpha | Beta | RC | RTW |
|---|---|---|---|---|
| Developers | Yes | Yes | Yes | Yes |
| Beta Testers | No | Yes | Yes | Yes |
| RC Testers | No | No | Yes | Yes |
| Public | No | No | No | Yes |

Access is the **union** of all group memberships — if any group grants access to a channel, the user has it. Users have a primary `group_id` on the `users` table, with additional memberships via `user_group_members`.

---

## Connection Architecture

| Component | Driver | Used By |
|---|---|---|
| All queries — song data, admin panel, auth | **MySQLi** (prepared statements) via `getDbMysqli()` | `SongData.php`, `db_mysql.php`, `manage/includes/auth.php`, `manage/includes/db.php` (thin wrapper) |

`getDbMysqli()` is the **one** connection factory in the codebase; a `new PDO(...)` or a second `new mysqli(...)` outside it is a regression. All queries share credentials from `appWeb/.auth/db_credentials.php`, and mysqli runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` — a failing statement throws rather than returning `false`.

---

## Table Prefix Support

The installer supports an optional table prefix for shared hosting environments. When configured, all table names are prefixed (e.g., `ih_songs`, `ih_users`). The prefix is stored in `DB_PREFIX` in the credentials file.

---

## File Structure

```text
appWeb/
├── .auth/
│   ├── .htaccess                      ← Blocks web access
│   ├── db_credentials.example.php     ← Template (tracked)
│   └── db_credentials.php             ← Credentials (NOT tracked)
├── .sql/
│   ├── schema.sql                     ← Full MySQL schema (canonical for a fresh install)
│   ├── install.php                    ← Interactive installer
│   └── migrate-json.php               ← One-time JSON-to-MySQL seed (confirm-gated)
└── public_html/
    ├── includes/
    │   ├── db_mysql.php               ← getDbMysqli() — the ONE MySQLi connection factory
    │   └── SongData.php                ← Song data handler (scoped live-read methods)
    └── manage/includes/
        ├── db.php                     ← Thin wrapper calling getDbMysqli() (admin panel)
        └── auth.php                   ← Authentication functions
```
