# API Reference

> Complete reference for the iHymns server-side API (`api.php`)

---

## Overview

All API requests go through `api.php`. There are two request types:

- **Page requests** (`?page=...`) — return HTML fragments for AJAX page loading
- **Action requests** (`?action=...`) — return JSON data

All JSON responses include:

- `Content-Type: application/json; charset=UTF-8`
- `X-Content-Type-Options: nosniff`
- `Cache-Control: no-cache, must-revalidate`

### Authentication

Authenticated endpoints require a `Authorization: Bearer <token>` header. Tokens are 64-character hex strings obtained via `auth_login` or `auth_register`.

---

## Page Endpoints

These return HTML fragments loaded into the SPA content area.

| Parameter | Description |
| --- | --- |
| `?page=home` | Home page |
| `?page=songbooks` | Songbook grid |
| `?page=songbook&id=CP` | Song list for songbook |
| `?page=song&id=CP-0001` | Song lyrics |
| `?page=search` | Search page |
| `?page=favorites` | Favourites page |
| `?page=setlist` | Setlist page |
| `?page=setlist-shared` | Shared setlist import page |
| `?page=settings` | Settings page |
| `?page=stats` | Collection statistics |
| `?page=writer&id=slug` | Writer/composer page |
| `?page=help` | Help page |
| `?page=terms` | Terms of use |
| `?page=privacy` | Privacy policy |

---

## Song Data Endpoints

### `?action=search`

Full-text search across titles, lyrics, writers, and composers.

| Param | Required | Description |
| --- | --- | --- |
| `q` | Yes | Search query |
| `songbook` | No | Filter by songbook |
| `limit` | No | Max results (default: 50) |

**Response:** `{ results: [songSummary], total, query }`

### `?action=search_num`

Search by song number within a songbook.

| Param | Required | Description |
| --- | --- | --- |
| `songbook` | Yes | Songbook abbreviation |
| `number` | Yes | Partial or full number |

**Response:** `{ results: [songSummary], total }`

### `?action=song_data`

Get full song data including lyrics components.

| Param | Required | Description |
| --- | --- | --- |
| `id` | Yes | Song ID (e.g., CP-0001) |

**Response:** `{ song: { id, number, title, songbook, songbookName, writers[], composers[], components[], ... } }`

### `?action=random`

Get a random song.

| Param | Required | Description |
| --- | --- | --- |
| `songbook` | No | Limit to songbook |

**Response:** `{ song: {...} }`

### `?action=songbooks`

Get all songbooks.

**Response:** `{ songbooks: [{ id, name, songCount }] }`

### `?action=songs`

Get all songs (summary format).

| Param | Required | Description |
| --- | --- | --- |
| `songbook` | No | Filter by songbook |

**Response:** `{ songs: [songSummary], total }`

### `?action=stats`

Collection statistics.

**Response:** `{ totalSongs, totalSongbooks, songbooks: [{ id, name, songCount }] }`

### `?action=songs_json`

Export full song database as JSON (for PWA offline cache / Fuse.js). Includes ETag caching.

**Response:** Full `{ meta, songbooks, songs }` structure.

### `?action=missing_songs`

Find missing song numbers in a songbook. (#285)

| Param | Required | Description |
| --- | --- | --- |
| `songbook` | Yes | Songbook abbreviation |

**Response:** `{ missing: [int], maxNumber, totalExisting, songbook }`

---

## Language & Translation Endpoints

### `?action=languages`

Get all available languages.

**Response:** `{ languages: [{ code, name, nativeName, textDirection }] }`

### `?action=song_translations`

Get translations available for a specific song. (#281)

| Param | Required | Description |
| --- | --- | --- |
| `id` | Yes | Source song ID |

**Response:** `{ translations: [{ songId, language, languageName, languageNativeName, title, number, translator, verified }], sourceId }`

---

## Setlist Sharing Endpoints

### `?action=setlist_share` (POST)

Create or update a shared setlist (anonymous, file-based).

**Body:** `{ name, songs: [songId], owner: uuid, arrangements?: {}, id?: existingId }`

**Response:** `{ id, url }`

### `?action=setlist_get`

Retrieve a shared setlist by short ID.

| Param | Required | Description |
| --- | --- | --- |
| `id` | Yes | 8-char hex setlist ID |

**Response:** `{ id, name, songs, created, updated, arrangements? }`

---

## Song Request Endpoints

### `?action=song_request` (POST)

Submit a song request (available to all users). (#280)

**Body:**

```json
{
  "title": "Amazing Grace",
  "songbook": "MP",
  "song_number": "123",
  "language": "en",
  "details": "First line: Amazing grace how sweet...",
  "contact_email": "user@example.com"
}
```

Only `title` is required. Rate-limited per IP (configurable via `tblAppSettings`).

**Response:** `{ ok: true, id: 42 }` (201)

### `?action=my_song_requests`

Get the authenticated user's submitted song requests. Requires: Bearer token.

**Response:** `{ requests: [{ id, title, songbook, songNumber, language, details, status, resolvedSongId, createdAt, updatedAt }] }`

---

## Authentication Endpoints

### `?action=auth_register` (POST)

Register a new user account.

**Body:** `{ username, password, display_name? }`

**Response:** `{ token, user: { id, username, display_name, role } }` (201)

### `?action=auth_login` (POST)

Log in and receive a bearer token.

**Body:** `{ username, password }`

**Response:** `{ token, user: { id, username, display_name, role } }`

### `?action=auth_logout` (POST)

Invalidate the current bearer token. Requires: Bearer token.

**Response:** `{ ok: true }`

### `?action=auth_me`

Get current authenticated user info. Requires: Bearer token.

**Response:** `{ user: { id, username, display_name, role } }`

### `?action=auth_update_profile` (POST)

Update user profile. Requires: Bearer token.

**Body:** `{ display_name, email? }`

**Response:** `{ ok: true, user: { id, username, display_name, email, role } }`

### `?action=auth_change_password` (POST)

Change password. Requires: Bearer token. Invalidates all other tokens.

**Body:** `{ current_password, new_password }`

**Response:** `{ ok: true, message }`

### `?action=auth_forgot_password` (POST)

Request a password reset token.

**Body:** `{ username }` (username or email)

**Response:** `{ ok: true, message }` (always 200 to prevent user enumeration)

### `?action=auth_reset_password` (POST)

Reset password using a valid token.

**Body:** `{ token, password }`

**Response:** `{ ok: true, message }`

### `?action=auth_email_login_request` (POST)

Request a magic link and 6-digit code sent to an email address. The code expires after 10 minutes. Rate-limited to 5 per email per hour.

**Body:** `{ email: "user@example.com" }`

**Response:** `{ ok: true, message }` (always 200 to prevent email enumeration)

### `?action=auth_email_login_verify` (POST)

Verify an email login token or code. Returns a bearer token. Auto-creates a new account if the email doesn't have one.

**Body (magic link mode):** `{ token: "<48-char hex from link>" }`

**Body (code entry mode):** `{ email: "user@example.com", code: "482917" }`

**Response:** `{ token, user: { id, username, display_name, email, role } }`

---

## User Data Endpoints (Authenticated)

### `?action=favorites`

Get all favorited song IDs. Requires: Bearer token. (#284)

**Response:** `{ favorites: ["CP-0001", "MP-0042", ...] }`

### `?action=favorites_sync` (POST)

Sync favorites: merge local with server. Requires: Bearer token.

**Body:** `{ favorites: ["CP-0001", ...] }`

**Response:** `{ favorites: [...merged...] }`

### `?action=favorites_remove` (POST)

Remove a song from favorites. Requires: Bearer token.

**Body:** `{ song_id: "CP-0001" }`

**Response:** `{ ok: true }`

### `?action=user_setlists`

Get all setlists for the authenticated user. Requires: Bearer token.

**Response:** `{ setlists: [{ id, name, songs, createdAt, updatedAt }] }`

### `?action=user_setlists_sync` (POST)

Sync local setlists with server storage. Requires: Bearer token.

**Body:** `{ setlists: [{ id, name, songs, createdAt }] }`

**Response:** `{ setlists: [...merged...] }`

### `?action=user_access`

Get the user's group memberships and effective version access level. Requires: Bearer token. (#282)

**Response:** `{ groups: [{ id, name, accessAlpha, accessBeta, accessRc, accessRtw }], effectiveAccess: { alpha, beta, rc, rtw }, role }`

---

## App Status Endpoint

### `?action=app_status`

Get public app status (no auth required). Used by PWA on startup.

**Response:** `{ maintenance: bool, songRequestsEnabled: bool }`

---

## Admin Endpoints (Requires admin+ role via Bearer token)

### `?action=admin_users`

List all users with group info.

**Response:** `{ users: [{ id, username, email, display_name, role, is_active, created_at, group_name }] }`

### `?action=admin_groups`

List all user groups with access flags. (#282)

**Response:** `{ groups: [{ id, name, description, accessAlpha, accessBeta, accessRc, accessRtw }] }`

### `?action=admin_activity_log`

Query the activity log. (#283)

| Param | Required | Description |
| --- | --- | --- |
| `limit` | No | Max entries (default: 50, max: 200) |
| `offset` | No | Pagination offset |
| `action_filter` | No | Filter by action type |
| `user_id` | No | Filter by user ID |

**Response:** `{ entries: [{ id, action, entityType, entityId, details, ipAddress, createdAt, username }], limit, offset }`

### `?action=admin_song_requests`

List all song requests (editor+ role). (#280)

| Param | Required | Description |
| --- | --- | --- |
| `status` | No | Filter: pending/reviewed/added/declined |

**Response:** `{ requests: [{ id, title, songbook, songNumber, language, details, contactEmail, status, adminNotes, resolvedSongId, createdAt, updatedAt, submittedBy }] }`

### `?action=admin_song_request_update` (POST)

Update a song request status (editor+ role).

**Body:** `{ id, status: "pending|reviewed|added|declined", admin_notes?, resolved_song_id? }`

**Response:** `{ ok: true }`

---

## Song Editor API

The song editor has its own API at `/manage/editor/api.php`:

| Endpoint | Method | Description |
| --- | --- | --- |
| `?action=load` | GET | Load all song data from MySQL |
| `?action=save` | POST | Save song data to MySQL (transaction-wrapped) |

Requires authenticated session (admin panel login).

---

## Error Responses

All errors follow the format:

```json
{ "error": "Error message description" }
```

| Status | Meaning |
| --- | --- |
| 400 | Bad request (missing/invalid parameters) |
| 401 | Not authenticated |
| 403 | Forbidden (insufficient role/permissions) |
| 404 | Not found |
| 405 | Method not allowed |
| 429 | Rate limit exceeded |
| 500 | Server error |
