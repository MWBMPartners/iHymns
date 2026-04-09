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

---

## Page Endpoints

These return HTML fragments loaded into the SPA content area.

| Parameter | Description |
|---|---|
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

### `GET ?action=search`

Full-text fuzzy search across all fields.

| Parameter | Required | Description |
|---|---|---|
| `q` | Yes | Search query string |
| `songbook` | No | Filter by songbook abbreviation |
| `limit` | No | Max results (default: 50) |

**Response:**
```json
{
  "results": [{ "id": "MP-0001", "number": 1, "title": "...", "songbook": "MP", ... }],
  "total": 42,
  "query": "amazing grace"
}
```

### `GET ?action=search_num`

Search by song number within a songbook.

| Parameter | Required | Description |
|---|---|---|
| `songbook` | Yes | Songbook abbreviation |
| `number` | Yes | Song number |

### `GET ?action=random`

Get a random song.

| Parameter | Required | Description |
|---|---|---|
| `songbook` | No | Filter by songbook |

**Response:**
```json
{ "song": { "id": "...", "title": "...", "components": [...], ... } }
```

### `GET ?action=song_data&id=CP-0001`

Get full song data including lyrics/components.

### `GET ?action=songbooks`

Get all songbooks with metadata.

### `GET ?action=songs`

Get song list (summaries only, no lyrics).

| Parameter | Required | Description |
|---|---|---|
| `songbook` | No | Filter by songbook |

### `GET ?action=stats`

Get collection statistics (song counts, songbook breakdown).

### `GET ?action=songs_json`

Stream the full `songs.json` file. Supports `ETag` and `If-Modified-Since` for conditional caching.

---

## Shared Setlist Endpoints

### `POST ?action=setlist_share`

Create or update a shared setlist.

**Request body (JSON):**
```json
{
  "name": "Sunday Service",
  "songs": ["MP-0001", "CP-0042"],
  "owner": "uuid-string",
  "id": "abc12345",
  "arrangements": {
    "MP-0001": [0, 2, 1, 2]
  }
}
```

| Field | Required | Description |
|---|---|---|
| `name` | Yes | Setlist name (max 200 chars) |
| `songs` | Yes | Array of song IDs (max 200) |
| `owner` | Yes | Owner UUID (for ownership verification) |
| `id` | No | Existing share ID (for updates) |
| `arrangements` | No | Map of song ID to component index arrays |

**Response:**
```json
{ "id": "abc12345", "url": "/setlist/shared/abc12345" }
```

### `GET ?action=setlist_get&id=abc12345`

Retrieve a shared setlist by its 8-character hex ID.

**Response:**
```json
{
  "id": "abc12345",
  "name": "Sunday Service",
  "songs": ["MP-0001", "CP-0042"],
  "arrangements": { "MP-0001": [0, 2, 1, 2] },
  "created": "2026-04-09T10:00:00+00:00",
  "updated": "2026-04-09T10:00:00+00:00"
}
```

---

## Authentication Endpoints

All auth endpoints use JSON request/response bodies. See [[User Accounts & Roles]] for the role hierarchy.

### `POST ?action=auth_register`

Register a new user account.

**Request body:**
```json
{
  "username": "john",
  "password": "securepass123",
  "display_name": "John Smith"
}
```

**Validation:**
- Username: min 3 chars, lowercase letters/numbers/`_`/`-`/`.` only
- Password: min 8 characters
- Display name: defaults to username if omitted

**Response (201):**
```json
{
  "token": "64-char-hex-bearer-token...",
  "user": {
    "id": 1,
    "username": "john",
    "display_name": "John Smith",
    "role": "user"
  }
}
```

**Note:** The very first registered user is automatically assigned the `global_admin` role. All subsequent registrations get the `user` role.

### `POST ?action=auth_login`

Log in with existing credentials.

**Request body:**
```json
{ "username": "john", "password": "securepass123" }
```

**Response (200):**
```json
{
  "token": "64-char-hex-bearer-token...",
  "user": {
    "id": 1,
    "username": "john",
    "display_name": "John Smith",
    "role": "editor"
  }
}
```

**Error (401):**
```json
{ "error": "Invalid username or password." }
```

### `POST ?action=auth_logout`

Invalidate the current bearer token.

**Headers:** `Authorization: Bearer <token>`

**Response:**
```json
{ "ok": true }
```

### `GET ?action=auth_me`

Get the currently authenticated user's info.

**Headers:** `Authorization: Bearer <token>`

**Response:**
```json
{
  "user": {
    "id": 1,
    "username": "john",
    "display_name": "John Smith",
    "role": "editor"
  }
}
```

### `POST ?action=auth_forgot_password`

Request a password reset token. Always returns 200 to prevent user enumeration.

**Request body:**
```json
{ "username": "john" }
```

Accepts username or email address.

**Response (200):**
```json
{
  "ok": true,
  "message": "If an account exists with that username or email, a reset link has been generated.",
  "_dev_token": "48-char-hex-token..."
}
```

**Note:** `_dev_token` is included for development/testing only. In production, the token should be delivered via email.

### `POST ?action=auth_reset_password`

Reset password using a valid reset token.

**Request body:**
```json
{
  "token": "48-char-hex-token...",
  "password": "newSecurePass123"
}
```

**Response (200):**
```json
{ "ok": true, "message": "Password reset successfully. Please sign in with your new password." }
```

**Side effects:** Invalidates all existing API tokens for the user (forces re-login on all devices).

---

## User Setlist Endpoints

Require `Authorization: Bearer <token>` header.

### `GET ?action=user_setlists`

Get all setlists for the authenticated user.

**Response:**
```json
{
  "setlists": [
    {
      "id": "setlist-uuid",
      "name": "Sunday Morning",
      "songs": [
        { "id": "MP-0001", "title": "...", "songbook": "MP", "number": 1 }
      ],
      "createdAt": "2026-04-09T10:00:00+00:00",
      "updatedAt": "2026-04-09T10:00:00+00:00"
    }
  ]
}
```

### `POST ?action=user_setlists_sync`

Merge local setlists with server-side storage. New setlists are inserted; existing ones are updated. Server-only setlists are preserved.

**Request body:**
```json
{
  "setlists": [
    {
      "id": "setlist-uuid",
      "name": "Sunday Morning",
      "createdAt": "2026-04-09T10:00:00+00:00",
      "songs": [
        {
          "id": "MP-0001",
          "title": "A New Commandment",
          "songbook": "MP",
          "number": 1,
          "arrangement": [0, 2, 1, 2]
        }
      ]
    }
  ]
}
```

**Limits:** Max 50 setlists per user, 200 songs per setlist.

**Response:** Same format as `user_setlists` — returns the full merged result.

---

## Bearer Token Details

| Property | Value |
|---|---|
| Format | 64-character lowercase hexadecimal string |
| Expiry | 30 days from creation |
| Storage | `api_tokens` table in SQLite |
| Header | `Authorization: Bearer <token>` |
| Fallback header | `REDIRECT_HTTP_AUTHORIZATION` (for Apache CGI/FCGI) |

---

## Error Responses

All errors follow this format:

```json
{ "error": "Human-readable error message." }
```

| HTTP Code | Meaning |
|---|---|
| 400 | Bad request / validation error |
| 401 | Not authenticated / invalid token |
| 403 | Forbidden / insufficient role |
| 404 | Resource not found |
| 405 | Method not allowed (e.g. GET on POST-only endpoint) |
| 409 | Conflict (e.g. username already taken) |
| 500 | Server error |
