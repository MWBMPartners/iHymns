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

All queries use **MySQLi with prepared statements** for song data, and **PDO** for the admin panel authentication system. Both share the same MySQL credentials.

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

After table creation, import song data from `songs.json`:

```bash
php appWeb/.sql/migrate-json.php
```

---

## Database Schema

The full schema is defined in `appWeb/.sql/schema.sql`.

### Song Data Tables

| Table | Purpose |
|---|---|
| `tblSongbooks` | Songbook definitions (CP, JP, MP, SDAH, CH, Misc) |
| `tblSongs` | Core song metadata + `LyricsText` for full-text search |
| `tblSongWriters` | Song lyricist credits (many-to-one) |
| `tblSongComposers` | Song composer credits (many-to-one) |
| `tblSongComponents` | Verses, choruses with lyrics as JSON lines array |

### User & Access Control Tables

| Table | Purpose |
|---|---|
| `tblUserGroups` | Groups with version channel access flags (Alpha/Beta/RC/RTW) |
| `tblUsers` | Accounts with role, group link, EmailVerified, LastLoginAt, LoginCount |
| `tblSessions` | Server-side admin panel sessions |
| `tblApiTokens` | Bearer tokens for PWA/native app auth (64-char hex, 30-day expiry) |
| `tblPasswordResetTokens` | Single-use password reset tokens (48-char hex, 1-hour expiry) |
| `tblEmailLoginTokens` | Magic link tokens + 6-digit codes for passwordless email login (10-min expiry) |
| `tblUserGroupMembers` | Many-to-many user-to-group membership |
| `tblUserPermissions` | Fine-grained per-user permission overrides (NULL = inherit from role) |
| `tblLoginAttempts` | Brute force tracking (IP, username, success/failure, timestamp) |

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
| Song data queries | **MySQLi** (prepared statements) | `SongData.php`, `db_mysql.php` |
| Admin panel auth | **PDO** (MySQL driver) | `auth.php`, `db.php` |

Both share credentials from `appWeb/.auth/db_credentials.php`.

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
│   ├── schema.sql                     ← Full MySQL schema
│   ├── install.php                    ← Interactive installer
│   └── migrate-json.php              ← JSON-to-MySQL data migration
└── public_html/
    ├── includes/
    │   ├── db_mysql.php               ← MySQLi connection factory
    │   └── SongData.php               ← Song data handler (MySQL-backed)
    └── manage/includes/
        ├── db.php                     ← PDO connection factory (admin panel)
        └── auth.php                   ← Authentication functions
```
