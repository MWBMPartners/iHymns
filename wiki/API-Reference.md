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

These return HTML fragments loaded into the SPA content area. Several (`home`, `song`, `songbook`, `whats-new`, …) are served from a shared HTTP cache — see [[Architecture]] for why that means they can never carry a per-request nonce or an inline `<script>`.

A not-found or removed entity (`song`, `songbook`, `person`, `work`, `tag`) answers with a themed HTML error fragment and a truthful status — 404, or 410 for a song that's gone for good (soft-deleted or merged with no live replacement) — rather than a generic failure; `router.js` renders that fragment instead of discarding it (#1705). See [[Architecture]] for the fragment/error-page pattern.

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
| `?page=musician&slug=slug` | Musician profile (person / group / character / …) — **canonical** since #1741 P2-B; `?page=person&slug=slug` is kept as an alias |
| `?page=work&slug=slug` | Work (composition grouping) page |
| `?page=publisher&slug=slug` | Publisher page (#93) — songbooks published + registry metadata. Resolves exact-slug → name-fold → alias-fold (rule #33) |
| `?page=tune&slug=slug` | Tune page — songs sharing the tune + registry metadata (metre, credits, external links) |
| `?page=iswc&code=code` | Songs / work sharing an ISWC code |
| `?page=ipi` · `isni` · `ccli` · `bowi` · `isrc` (each `&code=…`) | #1741 P3 alias routes — the five siblings of `iswc`. All resolve through **one** normaliser + resolver (`includes/identifier_normalize.php` `IHYMNS_ID_SCHEMES` + `identifier_resolve.php`) into the shared `includes/pages/identifier.php`; separator-insensitive (`T-034.524.680-C` ≡ `T034524680C`). An identifier that maps to several songs renders a **song-list** view rather than picking one. |
| `?page=help` | Help page |
| `?page=whats-new` | What's New — renders `data/whats-new.md`, extracted from the CHANGELOG on every deploy (#1583) |
| `?page=terms` | Terms of use |
| `?page=privacy` | Privacy policy |
| `?page=request` | Request a song |

---

## Action families

`api.php` exposes roughly **270** `?action=...` endpoints (275 documented OpenAPI paths across the four dispatchers). Hand-maintaining an itemised list of all of them here duplicates the project's own OpenAPI spec and drifts out of sync with it (the modularity rule this wiki otherwise enforces everywhere else). Instead:

> **The complete, always-current reference is `appWeb/public_html/api-docs.yaml`** (OpenAPI 3.0), rendered with interactive Try-it-out at **`/manage/api-docs`** (Swagger UI, requires the `view_api_docs` entitlement).

The table below is an orientation map — one row per family, with a couple of representative actions to search for in the OpenAPI spec.

| Family | Purpose | Example actions |
| --- | --- | --- |
| **Songs** | Search, browse, and read song/songbook data. All list/detail reads are scoped — nothing returns the whole corpus (see [[Architecture]]). | `search`, `songs_index`, `song_detail` (alias `song_data`), `songbooks`, `songs` (400s without `songbook` — the #929 OOM fix) |
| **Catalogue** (#1741) | Read the catalogue entities (musicians, works, tunes) and resolve industry identifiers to them. Editor-side, the tune typeahead + recording-ID store write through `api2.php`. | `work`, `musician` (alias `person`), `musician_by_identifier` (alias `person_by_identifier`); editor: `tune_search`, `song_tune_set`, `song_external_ids` / `song_external_id_add` / `song_external_id_delete` |
| **Auth** | Registration, password/email login, bearer-token session management, device-code pairing for limited-input clients (TV, watch). | `auth_login`, `auth_register`, `auth_me`, `auth_device_code_request` |
| **Setlists** | Setlist CRUD, sharing, scheduling, and collaborator management. | `setlist_share`, `setlist_get`, `setlist_schedule`, `setlist_collab_invite` |
| **Set-list share links** (#1791) | Revocable capability links, view or edit, with a per-link edit audience. The mint response — not the request — is the source of truth (see [[Security]]). | `setlist_share` (`scope: edit`), `setlist_token_update`, `setlist_share_list`, `setlist_share_revoke` |
| **Live Follow** | Any signed-in user broadcasts the song they're viewing to anyone with the code — no venue, no account needed to join. Now includes declaring a session length + extending it live (#1798). | `live_follow_create` (`idleTimeoutMins`), `live_follow_join`, `live_follow_poll`, `live_follow_extend` |
| **Service Mode** | Venue/organisation-based broadcast with rotating join codes and section-level (not just song-level) sync. See [[Live Follow & Service Mode]]. | `service_session_start`, `service_broadcast`, `service_join` |
| **Service driver** (#1770) | Key-authed external control — a presentation app drives the current song through the same broadcast core. | `service_drive`, `service_driver_key_mint`, `service_driver_key_list`, `service_driver_key_revoke` |
| **Print usage** (#1767) | CCLI copies-made bookkeeping for the print / Download-PDF path — resolves the org's licence server-side. | `print_usage_context`, `print_usage_log` |
| **Licence types (admin)** (#1769) | CRUD over the `tblLicenceTypes` vocabulary; thin wrappers over the shared licence-type core. | `admin_licence_type_create`, `admin_licence_type_update`, `admin_licence_type_toggle`, `admin_licence_type_delete` |
| **Admin** | User/group management, activity log, content moderation, exports — all require admin+ role or the matching entitlement. | `admin_users`, `admin_activity_log`, `admin_export` |
| **Telemetry** | `client_error_report` (#1582) — anonymous, rate-limited, privacy-scrubbed browser-crash beacon feeding `tblActivityLog` (`client.jserror`). Not consent-gated analytics. | `client_error_report` |

The song editor has its own write API — `manage/editor/api2.php` (current) with `manage/editor/api.php` retained as a back-compat shim — documented alongside the rest in `api-docs.yaml`.

A few notes on this batch's additions:

- **`search`** accepts a multi-level `sort` param (#1786 — up to three "then by…" levels).
- **`user_settings`** gained a `list_sorts` namespace for syncing a signed-in user's per-surface sort choices (returns 403 for anonymous callers).
- The **IA-reconcile** tool exposes **no public API** — it is deliberately admin-page-local (actions on `/manage/ia-reconcile` only), never an `api.php` action.
- **QR generation** is `/qr.php` (CueRCode-backed), not an `api.php` action — see [[Architecture]].
- **Organisation logo bytes** are `/org-logo.php` (#1830 — mirrors `/qr.php`'s standalone-image-endpoint shape), not an `api.php` action. `my_organisations` gains an additive, migration-gated `logos` field (meta only — kind/variant/Sha256/alt/dimensions, never blob bytes) that the print `logo` block resolves through the endpoint.

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
