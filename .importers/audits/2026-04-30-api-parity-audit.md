# API parity audit — admin surfaces vs public API

**Date run:** 2026-04-30
**Tracking issue:** [#719](https://github.com/MWBMPartners/iHymns/issues/719) (PR 1 of 5)
**Methodology:** machine-extracted POST `case '<verb>':` from every `appWeb/public_html/manage/*.php` file, cross-referenced against `case '<verb>':` in `appWeb/public_html/api.php` and `appWeb/public_html/manage/editor/api.php`.

## Headline numbers

| Surface | Actions | Covered by API | Missing | Coverage |
|---|---:|---:|---:|---:|
| /manage/songbooks    | 7 | 0 | 7 | 0% |
| /manage/users        | 8 | 1 | 7 | 13% |
| /manage/organisations| 6 | 1 | 5 | 17% |
| /manage/my-organisations | 6 | 0 | 6 | 0% |
| /manage/groups       | 5 | 0 | 5 | 0% |
| /manage/credit-people| 5 | 0 | 5 | 0% |
| /manage/tiers        | 3 | 0 | 3 | 0% |
| /manage/analytics    | 3 | 2 | 1 | 67% |
| /manage/restrictions | 2 | 2 | 0 | 100% |
| **Total**            | **45** | **6** | **39** | **13%** |

39 of 45 admin write actions have no public-API equivalent. Native apps (Apple, Android, FireOS) can read most resources via the existing API but **cannot perform admin operations** — they have to either (a) lean on the web admin in a webview, or (b) re-implement each missing surface against direct DB access (which native clients can't do anyway).

## What's already on the API (existing `admin_*` endpoints)

The following 6 admin verbs DO have working API equivalents — used as the reference shape for the gap-filler PR:

| Admin file | Admin action | API endpoint |
|---|---|---|
| /manage/analytics | `top_books` | `popular_songs` (covers top_songs too) |
| /manage/analytics | `top_songs` | `popular_songs` |
| /manage/organisations | `create` | `organisation_create` |
| /manage/restrictions | `create` | `admin_restriction_create` |
| /manage/restrictions | `delete` | `admin_restriction_delete` |
| /manage/users | `change_tier` | `admin_set_user_tier` |

Plus `admin_users` / `admin_groups` / `admin_organisations` / `admin_song_requests` / `admin_song_request_update` / `admin_pending_revisions` / `admin_revision_review` / `admin_set_user_ccli` / `admin_songbook_health` / `admin_export` / `admin_cleanup` / `admin_activity_log` exist as **read** or **specialised-write** endpoints that don't map 1:1 with a single admin verb but cover related needs.

## Gaps — by domain (suggested PR boundaries)

Each row below = a missing API endpoint native apps need. Suggested verb naming follows the existing `admin_<resource>_<verb>` pattern.

### 1 · Songbooks (7 endpoints) — highest-impact

| Admin verb | Suggested API verb |
|---|---|
| create | `admin_songbook_create` |
| update | `admin_songbook_update` |
| delete | `admin_songbook_delete` |
| delete_cascade | `admin_songbook_delete_cascade` |
| reorder | `admin_songbooks_reorder` |
| auto_colour_fill | `admin_songbooks_auto_colour_fill` |
| auto_colour_reassign | `admin_songbooks_auto_colour_reassign` |

Notes: the read paths (`songbooks` / `songbook`) already return all 13 bibliographic + Language fields per #682's OpenAPI refresh. The write endpoints would mirror the same shape on input.

### 2 · Users + groups + tiers (15 endpoints) — admin core

| Admin verb | Suggested API verb |
|---|---|
| users.create | `admin_user_create` |
| users.update_profile | `admin_user_update` |
| users.rename_user | `admin_user_rename` |
| users.change_role | `admin_user_role_change` |
| users.toggle_active | `admin_user_toggle_active` |
| users.reset_password | `admin_user_password_reset` |
| users.delete | `admin_user_delete` |
| groups.create | `admin_group_create` |
| groups.update | `admin_group_update` |
| groups.delete | `admin_group_delete` |
| groups.add_member | `admin_group_member_add` |
| groups.remove_member | `admin_group_member_remove` |
| tiers.create | `admin_tier_create` |
| tiers.update | `admin_tier_update` |
| tiers.delete | `admin_tier_delete` |

### 3 · Organisations + my-organisations (11 endpoints)

System-admin organisations.php (5 missing):

| Admin verb | Suggested API verb |
|---|---|
| update | `admin_organisation_update` |
| delete | `admin_organisation_delete` |
| add_member | `admin_organisation_member_add` |
| remove_member | `admin_organisation_member_remove` |
| update_member_role | `admin_organisation_member_role_change` |

Org-admin my-organisations.php (6 missing — added via PR #726, server-side only):

| Admin verb | Suggested API verb |
|---|---|
| member_add | `org_admin_member_add` |
| member_role_change | `org_admin_member_role_change` |
| member_remove | `org_admin_member_remove` |
| licence_add | `org_admin_licence_add` |
| licence_change | `org_admin_licence_change` |
| licence_remove | `org_admin_licence_remove` |

The org-admin endpoints need the same row-level `userIsOrgAdminOf()` gate applied at the API layer.

### 4 · Credit People (5 endpoints)

| Admin verb | Suggested API verb |
|---|---|
| add | `admin_credit_person_add` |
| update_person | `admin_credit_person_update` |
| rename | `admin_credit_person_rename` |
| merge | `admin_credit_person_merge` |
| delete_from_registry | `admin_credit_person_delete` |

Existing `person` endpoint is read-only and returns the public `/people/<slug>` page payload.

### 5 · Misc (1 endpoint)

| Admin verb | Suggested API verb |
|---|---|
| analytics.searches | `admin_analytics_searches` |

## Read-side gaps (admin-only data not exposed via API)

Beyond write parity, three admin pages render data that no public API endpoint emits:

- **/manage/data-health** — table-row counts, songs.json fallback state, share JSON file count, SQLite presence. No `admin_data_health` endpoint.
- **/manage/schema-audit** — schema.sql vs live DB drift table. No `admin_schema_audit` endpoint.
- **/manage/setup-database** — migration cards' status (which migrations have been applied). No `admin_migrations_status` endpoint.

These are operational diagnostics — natives may not need them, but a future tooling client (CI, monitoring) probably will.

## Editor-API surface (`/manage/editor/api.php`)

16 actions, all live on a separate API file:

  add_translation, bulk_import_status, bulk_import_zip, bulk_tag,
  credit_search, get_translations, list_revisions, load,
  org_search, remove_translation, restore_revision, save,
  save_song, song_tags, tag_search, user_search

Coverage assessment: these are reasonably complete for their domain (the song editor surface is where most curator effort happens). They should be mirrored to `/api.php` (or aliased) so native clients don't need a separate auth flow per admin file.

The bulk-import endpoints (`bulk_import_zip`, `bulk_import_status`) are already documented in the OpenAPI spec per #682; the song-editor write endpoints (`save_song`, `bulk_tag`, etc.) are NOT yet documented.

## Other observations

1. **Naming inconsistency** — existing endpoints mix `admin_users` (plural, reads) with `admin_set_user_tier` (singular, writes), `admin_restriction_create` vs `admin_restrictions` (also plural for reads). Suggest standardising the gap-fillers as `admin_<resource>_<verb>` (singular resource for write, plural for collection read). Examples are in the tables above.
2. **CSRF + auth** — admin endpoints currently use the `auth_*` bearer token system (existing `admin_users` etc. consult `getAuthenticatedUser()`). The new endpoints should follow the same pattern. **No** session-based auth on the API layer.
3. **Activity Log** — every gap-filler endpoint should call `logActivity('api.admin.<verb>', …)` mirroring the in-app `admin.<surface>.<verb>` convention so `/manage/activity-log` shows API-driven changes alongside web-UI changes.
4. **OpenAPI** — every gap-filler endpoint must land in `appWeb/public_html/api-docs.yaml` in the same PR that adds it. Schema components for Songbook / User / Group / Tier / CreditPerson / Organisation already exist (per #682 refresh) so the path entries can `$ref` them.

## Recommended PR sequence

1. **PR 2a — Songbooks API CRUD.** 7 endpoints, single domain, highest natives need. Smallest discrete chunk that delivers value.
2. **PR 2b — Users + Groups + Tiers API CRUD.** 15 endpoints — admin core. Second-largest chunk.
3. **PR 2c — Organisations + my-organisations API CRUD.** 11 endpoints. Reuses the row-level org-ownership gate from #707.
4. **PR 2d — Credit People API CRUD + analytics.searches + read-side admin endpoints.** 9 endpoints. Cleanup tail.
5. **PR 3 — OpenAPI refresh.** Document every endpoint added in 2a–2d. ~2-3 hours of YAML editing; the schema components already exist.
6. **PR 4 — In-app docs (`/help` + `/manage/help`).** Refresh every section to reflect the new admin features + the API surface.
7. **PR 5 — GitHub Wiki refresh.** Lives in the separate `iHymns.wiki/` repo. Pages: API Reference, Native Client Integration Guide, Schema Diagram, Deployment Runbook, Migration Playbook, ADRs.

Total: ~50-60 hours of focused work spread across 7 PRs. None individually massive, but cumulative.

## Acceptance for this audit (PR 1)

- [x] Every `/manage/*.php` POST action enumerated.
- [x] Every `api.php` + `manage/editor/api.php` endpoint enumerated.
- [x] Cross-reference produces a per-action coverage status.
- [x] Gap list grouped into PR-sized chunks with suggested verb names.
- [x] Read-side gaps and naming-inconsistency observations noted.
- [x] Recommended PR sequence with realistic timeline.

PRs 2a–5 close on the following commits.
