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

Authenticated endpoints accept **either** an `Authorization: Bearer <token>` header **or** the same-origin `ihymns_auth` cookie — `getAuthenticatedUser()` tries Bearer first, then falls back to the cookie (`api.php`, `Vary: Cookie, Authorization`). Tokens are 64-character hex strings obtained via `auth_login`, `auth_register`, `auth_apple`, the email-link login flow, or the device-code flow, and are stored **hashed** (never in plaintext) in `tblApiTokens`. A Bearer-authenticated write is CSRF-immune by construction — a cross-site page cannot attach an explicit `Authorization` header to a forged request — so `validateCsrfRequest()`'s `X-Requested-With` same-origin check applies only to the cookie path; a Bearer POST is accepted without it.

**The song-editor API is Bearer-capable too, as of the 2026-08-28/29 API-coverage program.** `manage/editor/api2.php` (the current editor write path, 66 actions), the legacy `manage/editor/api.php` compat shim, `manage/places-api.php`, and `manage/print-pdf.php` (server-generated PDFs) used to accept only the `/manage` session cookie — no use to a native curator app, which has no cookie jar. All four now resolve `Authorization: Bearer <token>` through the **same** shared verifier, `apiTokenResolveBearerUser()` in `includes/api_tokens.php` (strict `Bearer <64-hex>`, sha256-hashed match against `tblApiTokens` JOIN `tblUsers`, requires `ExpiresAt > now` and `IsActive = 1`, fails closed to `null` on anything else), falling through to the exact pre-existing cookie check, byte-identically, when no Bearer header is present or it doesn't verify. Per-action entitlement checks (`userHasEntitlement()`) are unchanged either way, so a Bearer caller has exactly its user's own privileges — switching transport never widens what an action will do. See [[Architecture]] § API coverage for why this seam exists.

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
| — | **ILID dual addressing (#1860)** — `musician`/`publisher`/`tune`'s `slug` param, plus `?action=song_detail`/`song_data`'s `id` param, all additionally accept the entity's permanent internal id (`IL<letter><digits>` — no separator, grammar-disjoint from every public id form) as a drop-in replacement for the slug/id, resolved via `includes/ilyrics_id.php` and gated on the migrated `IlId` column. See [[Architecture]]. |
| `?page=iswc&code=code` | Songs / work sharing an ISWC code |
| `?page=ipi` · `isni` · `ccli` · `bowi` · `isrc` (each `&code=…`) | #1741 P3 alias routes — the five siblings of `iswc`. All resolve through **one** normaliser + resolver (`includes/identifier_normalize.php` `IHYMNS_ID_SCHEMES` + `identifier_resolve.php`) into the shared `includes/pages/identifier.php`; separator-insensitive (`T-034.524.680-C` ≡ `T034524680C`). An identifier that maps to several songs renders a **song-list** view rather than picking one. |
| `?page=help` | Help page |
| `?page=whats-new` | What's New — renders `data/whats-new.md`, extracted from the CHANGELOG on every deploy (#1583) |
| `?page=terms` | Terms of use |
| `?page=privacy` | Privacy policy |
| `?page=request` | Request a song |

---

## Action families

`api.php` exposes **312** public `?action=...` endpoints (well over 400 documented OpenAPI paths total, including the editor APIs and the page routes above — the action count is verified directly via `tests/php/lib/dispatch_parser.php`, the same tokenising parser `test-openapi-actions-exist.php` uses; both figures grow steadily as new features land, so treat them as orientation, not a pinned contract). The jump from 223 actions is the 2026-08-28/29 **API-coverage program**: roughly 90 new `admin_*`/`org_admin_*` actions that give native apps parity with everything an admin/curator can already do from a `/manage/*.php` page — registry CRUD (publishers, works, tags, catalogues, songbook series, languages, external-link types, print templates, API keys, webhooks), org self-service (settings, logo, brand, venues + schedules), curator workflows (duplicate-song merge/link, restore/purge, musician-duplicate dismiss), and a few consumer reads (`tune`, `publisher_detail`, `org_ccli_report`, `org_venues`) — plus a dormant Android/FireOS push-registration pair. See [[Architecture]] § API coverage for the mechanism that keeps this from drifting again. Hand-maintaining an itemised list of all of them here duplicates the project's own OpenAPI spec and drifts out of sync with it (the modularity rule this wiki otherwise enforces everywhere else). Instead:

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
| **ProPresenter interop** (epic #1968) | Editor-side bulk import of `.pro`/`.probundle`/`.proplaylist` and single-song/set-list export to the same formats; a media row's admin/public publish toggle. Documented on the editor API surface, not `api.php`. | `bulk_import_pro7`, `bulk_import_probundle`, `bulk_import_proplaylist`, `media_set_visibility` |
| **Licence types (admin)** (#1769) | CRUD over the `tblLicenceTypes` vocabulary; thin wrappers over the shared licence-type core. | `admin_licence_type_create`, `admin_licence_type_update`, `admin_licence_type_toggle`, `admin_licence_type_delete` |
| **Admin** | User/group management, activity log, content moderation, exports — all require admin+ role or the matching entitlement. | `admin_users`, `admin_activity_log`, `admin_export` |
| **Admin — Musicians** | Musician-registry group membership, cross-musician relations, and bulk-register (mirrors `/manage/musicians`); merge/duplicate-dismiss for the #1785 dedup workflow. | `admin_musician_member_add`/`_remove`, `admin_musician_relation_add`/`_remove`, `admin_musician_bulk_register`, `admin_musician_merge`, `admin_musician_duplicate_dismiss`/`_undismiss` |
| **Admin — Publishers / Works / Tags / Catalogues / Songbook Series / Languages / External Link Types / Print Templates** (2026-08-28/29 coverage program) | Registry CRUD for every curator-facing vocabulary that previously existed only as a `/manage/*.php` form-POST handler — each thin `admin_*` action delegates to the SAME shared core the page itself now uses (rule #22; several — `includes/tag_admin.php`, `catalogue_admin.php`, `songbook_series_admin.php`, `language_admin.php`, `external_link_type_admin.php`, `print_template_admin.php` — were extracted from inline page logic in this batch, so the page and the API are provably byte-identical, not two parallel implementations). Also includes `admin_work_medley_replace` (the cycle-guarded `workMedley*()` core, rule #45) and `admin_songbook_update`'s new `is_disabled` field. | `admin_publisher_create`/`_merge`, `admin_work_create`, `admin_work_medley_replace`, `admin_tag_create`/`_merge`, `admin_tag_canonical_suggestions`, `admin_catalogue_create`, `admin_songbook_series_create`, `admin_language_create`/`_remap_tag`, `admin_external_link_type_save`, `admin_print_template_save`/`_clone`, `admin_print_layout_save` |
| **Admin — Guided-wizard create twins** (Songbooks / Organisations / External Link Types, #1993/#1996/#1992) | `admin_songbook_create`, `admin_organisation_create` and `admin_external_link_type_create` are the API-side twins of each page's own guided-wizard "create" flow (see [[Architecture]] § Guided-wizard framework) — all POST JSON, Bearer-or-cookie auth, gated on the same entitlement the page itself requires (`manage_songbooks`; `manage_organisations`, with the finer `manage_org_licences` additionally needed before a posted licence row is honoured; `manage_external_link_types`), and delegate to the SAME validate/create core the page's manual form and its wizard both already call (rule #22) — never a forked write path. `admin_organisation_create` accepts optional `licences[]` and `members[]` arrays so a caller can mint an organisation with its first licences and members in one request (each array is row-capped at 50 to bound a single POST); `admin_external_link_type_create` accepts an optional `patterns[]` array to seed the new provider's detection rules at creation time (capped at 100 rows). | `admin_songbook_create`, `admin_organisation_create`, `admin_external_link_type_create` |
| **Admin — Duplicate Songs / Deleted Songs / Data Health** | Curator workflows: duplicate-song merge (irreversible, `force` for the same-official-songbook guard #1218)/link/unlink/suggestion-rebuild/auto-link; soft-delete restore + the irreversible purge (server-enforced type-to-confirm); one data-health fix (`disconnect_fallbacks`); an activity-log IP-geolocation helper. | `admin_song_merge`, `admin_song_link`/`_unlink`, `admin_song_restore`, `admin_song_purge`, `admin_data_health_fix`, `admin_ip_geolocate` |
| **Admin — Notifications** | Send/delete an in-app announcement, plus a Web Push broadcast/test send over the existing VAPID pipeline. | `admin_notification_send`, `admin_notification_delete`, `admin_notification_push_send`, `admin_notification_push_test` |
| **Admin — API Keys / Webhooks** (owner decision Q5; webhook reveal retired #1987) | Management CRUD over `tblApiKeys` and `tblWebhooks`, with a strict **show-once** secret discipline — only the 4 mint/rotate responses (`admin_api_key_create`, the approve-request path, `admin_webhook_create`, `admin_webhook_rotate_secret`) ever carry a plaintext secret. API keys were show-once from the start (only a SHA-256 hash is ever persisted — there is no plaintext to read back). The webhook page's own "reveal an existing secret" action was **retired outright** (#1987), not merely left un-ported — it decrypted and returned a stored signing secret, which is a genuine leak an API key's hash-at-rest design never had. An admin who loses a webhook secret rotates it instead (24h dual-signing grace). | `admin_api_key_create`, `admin_api_key_toggle`, `api_key_request` (self-service), `admin_webhook_create`, `admin_webhook_rotate_secret`, `admin_webhook_send_test`, `admin_webhook_redrive` |
| **Admin — IA Reconcile** | `admin_ia_reconcile_run` delegates to the pure `includes/ia_client.php` + `ia_reconcile.php` pipeline — zero forked fetch/score logic. (Superseded note: this family used to be admin-page-local only; it now has API coverage too. The bulk-promote wizard and a songbook's `family_manifest` stay deliberately web-only — both are interactive preview→confirm flows with no clean single-call shape.) | `admin_ia_reconcile_run` |
| **Org-admin self-service** (parity with `/manage/my-organisations`) | An org admin/owner (or global admin) manages their OWN organisation's Live-Follow idle timeout + set-list edit-audience defaults, logo (upload/delete/show-hide, SVGs sanitised via `ihymnsSanitizeSvg()`, rule #42), brand colour, and venues + recurring service schedules — all gated on `userCanActOnOrg()`, fail-closed for a non-admin member. | `org_admin_settings_update`, `org_admin_logo_upload`/`_delete`/`_set_active`, `org_admin_brand_update`, `org_admin_venue_save`/`_delete`, `org_admin_schedule_save`/`_delete` |
| **Consumer reads (native unblockers)** | Public/gated GET reads a native app needed that previously existed only as an HTML fragment or a `/manage` page: a tune's/publisher's detail JSON (same resolver ladder as the public page, rule #33/#37), an org admin's own CCLI usage report, and the venue + schedule list a Service-Mode operator picks from when starting a session. | `tune`, `publisher_detail`, `org_ccli_report`, `org_venues` |
| **Push** (dormant — API-coverage plan §4.1 C1) | Android/FireOS push-token registration. `Provider` (`fcm`/`adm`, `VARCHAR` not `ENUM`, rule #20) discriminates ordinary-Android Google FCM from Fire-OS-only Amazon ADM. **Registration only** — `includes/fcm.php`'s `fcmSend()` is a structural no-op (`not_configured`/`not_implemented`) until FCM/ADM credentials are provisioned on `/manage/configuration` and the real send protocol is implemented; no push has been sent through this path. Distinct from the existing, live `apns_register` (Apple) and `push_subscribe` (Web Push/VAPID). | `fcm_register`, `fcm_unregister` |
| **Telemetry** | `client_error_report` (#1582) — anonymous, rate-limited, privacy-scrubbed browser-crash beacon feeding `tblActivityLog` (`client.jserror`). Not consent-gated analytics. | `client_error_report` |

The song editor has its own write API — `manage/editor/api2.php` (current) with `manage/editor/api.php` retained as a back-compat shim — documented alongside the rest in `api-docs.yaml`. Both, plus `manage/places-api.php` and `manage/print-pdf.php`, now accept `Authorization: Bearer` as well as the `/manage` session cookie — see Authentication above.

**Standing coverage guard.** `tests/php/test-manage-action-api-coverage.php` enumerates, from the source tree (never a typed list), every state-changing `$_POST`/`$_REQUEST` action across all `manage/*.php` pages and asserts each one maps to either a real `api.php`/`api2.php` action, an explicit `web_only:<reason>` entry (secrets, the setup-database console, feature-gating switches, and a couple of interactive wizard-shaped flows with no clean single-call API shape), or a `native:<reason>` entry for the two pages that went Bearer-capable on themselves (`places-api.php`, `print-pdf.php`) rather than growing an `api.php` twin. As of the 2026-08-28/29 program it reports **zero** uncovered actions. A new admin button that grows a manage page without a mapping entry fails this guard the next time it runs — it is mutation-proven (an injected fake action goes red), so the coverage claim above is a standing, machine-checked fact rather than a one-off audit finding. See [[Architecture]] § API coverage.

A few notes on this batch's additions:

- **`search`** accepts a multi-level `sort` param (#1786 — up to three "then by…" levels).
- **`user_settings`** gained a `list_sorts` namespace for syncing a signed-in user's per-surface sort choices (returns 403 for anonymous callers).
- **QR generation** is `/qr.php` (CueRCode-backed), not an `api.php` action — see [[Architecture]]. It reads through a server-side cache (`tblQrCache`, #1920) before ever calling CueRCode.
- **Organisation logo bytes** are `/org-logo.php` (#1830 — mirrors `/qr.php`'s standalone-image-endpoint shape), not an `api.php` action. `my_organisations` gains an additive, migration-gated `logos` field (meta only — kind/variant/Sha256/alt/dimensions, never blob bytes) that the print `logo` block resolves through the endpoint.
- **`/manage/my-ccli-report`** (#1861) is a `/manage` page, not an `api.php` action — the org-scoped self-serve sibling of the system-wide CCLI Usage Report, backed by the shared `includes/ccli_report.php` query core.
- **`songs_index` supports conditional revalidation (#1921).** A matching `If-None-Match` gets a **304 with no body** — the server skips its whole slim-index query. The `ETag` is an **opaque version-signal token** (`"si<contractVersion>-<hash>"`); echo it back verbatim, never parse it. The PWA's own service worker already does this; other clients get the benefit only if they implement the round-trip themselves — omitting `If-None-Match` simply degrades to today's full-200-every-time behaviour.
- **`songbook_export` has its own `export` read-rate-limit bucket (#1571)**, split from the `bulk` budget `bulk_songs`/`bulk_audio` still share — a curator's export and a native app's background offline sync can no longer contend for the same counter. Same 60/min limit either way; see `api-docs.yaml`'s Rate Limiting section for the full table.

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
