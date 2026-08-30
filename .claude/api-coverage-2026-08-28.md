# API Coverage — Gap Analysis + Endpoint Plan (2026-08-28)

**Goal (owner):** every capability reachable through the API, so Web/PWA *and* native apps
(iOS/iPadOS/tvOS, Android/FireOS) all interact with the backend exclusively through it.
**Scope of this doc:** analysis + plan only — no implementation. Every "gap" below was verified
against the live code (grep of the actual `case '…'` switches + page handlers), not against
`api-docs.yaml` alone. Items I could not fully prove are marked **NEEDS-VERIFICATION**.

---

## 1. Executive summary

| Surface | Count | Notes |
|---|---|---|
| `api.php` JSON actions | **223** distinct `case` actions | Bearer-token OR cookie auth (`getAuthenticatedUser()`) — **native-capable today** |
| `api.php` `?page=` fragment routes | **30** | HTML fragments; native apps *can* GET them (content pages) |
| `manage/editor/api2.php` actions | **66** | **session-cookie-only** (`isAuthenticated()`) — NOT native-capable |
| `manage/editor/api.php` (legacy) actions | **40** | session-only; superseded by api2 for most flows |
| Standalone endpoints | `qr.php`, `org-logo.php`, `og-image.php`, `song-media.php`, `audio-media.php`, `manage/places-api.php`, `manage/print-pdf.php`, `webhook-drain.php`, `language-registry-refresh.php`, `opcache-bust.php` | `song-media.php` accepts **Bearer** ✓; `places-api`/`print-pdf` are session-only |
| **Confirmed CONSUMER gaps** | **6** (§4.1) | FCM push, venue discovery, org CCLI report, tune JSON, publisher JSON, print-PDF Bearer |
| **Confirmed ORG-ADMIN gaps** | **4 families** (§4.2) | org settings, logos, brand, venues+schedules |
| **Confirmed ADMIN/CURATOR gaps** | **~17 families** (§4.3) | biggest: publishers, works, tags, catalogues, series, languages, print templates, notifications send, duplicate-song workflow, deleted-song restore |
| **Cross-cutting structural** | **1 keystone** (§3) | api2.php Bearer support would unlock all 66 editor actions at once |
| Deliberately web-only | **12** (§6) | setup-database, diagnostics SQL, configuration secrets, gating switches, etc. |

**Headline finding:** the *consumer* surface is in excellent shape — the public PWA already speaks
exclusively to `/api` (verified by grepping every `fetch`/`apiFetch` target in `js/`; the only
non-api target is `/manage/print-pdf`, itself a deliberate seam). The real gap is concentrated in
(a) Android/FireOS push, (b) the org-admin self-service family, and (c) admin/curator registry CRUD
that exists only as `manage/*.php` form-POST handlers. And one structural decision dominates
everything else: **whether native curator apps are intended** — if yes, adding Bearer auth to
api2.php is worth more than any ten new actions.

---

## 2. Existing supply (what already exists — verified)

### 2.1 `api.php` — auth model (native-ready)

- `getAuthenticatedUser()` accepts `Authorization: Bearer <token>` **or** the `ihymns_auth`
  cookie (api.php:352, doc-block; Vary: Cookie, Authorization at :925). Tokens are minted by
  `auth_login` / `auth_apple` / `auth_email_login_verify` / device-code flow, stored hashed in
  `tblApiTokens` with device metadata (`includes/api_tokens.php`).
- Admin actions gate via `in_array($authUser['Role'], ['admin','global_admin'])` or
  `userHasEntitlement('<key>', $authUser['Role'])` — both work over Bearer. **Native admin
  clients can already call every `admin_*` action.**
- Writes: POST + JSON body + `validateCsrfRequest()` (native clients send
  `X-Requested-With: XMLHttpRequest`, which passes with no Origin header — rule #29).
- Machine ingest: `lyrics_ingest` uses `tblApiKeys` (`X-API-Key`/Bearer) with scopes,
  rate limits, idempotency. `service_drive` uses `tblServiceDriverKeys`.

### 2.2 `api.php` action families (all present — do NOT re-add, see §5)

Auth/account (register, login, logout, me, forgot/reset password, verify email, email-link login,
SIWA + unlink + providers list, device-code x5, profile/avatar/password/username, account delete),
devices (list/signout/rename), APNs (register/unregister), web push (subscribe/unsubscribe),
reads (search, search_num, random, song_of_the_day, song_detail/song_data, songs, songs_list,
songs_index, songbooks incl. series+compilers+links, songbook_export, song_by_identifier,
person/musician_by_identifier, song_links, work, credit_person/musician, stats, missing_songs,
bulk_songs, bulk_audio, related_songs, popular_songs, tags, popular_tags, songs_by_tag,
song_translations, languages/scripts/regions/variants + 4 searches, catalogue_language_subtags,
print_templates, print_usage_context/log), favourites (+sync/remove), custom_tags (+sync),
setlists (user_setlists, sync, share, share_list/revoke, token_update, get, collab x5,
schedule x4, templates x4), song_key (+save), song history (+backfill/clear), song_view,
songs_exist, user_settings, user_preferred_languages (+save), card_layout x4, notifications
(list/mark_read), song_request_submit + my_song_requests + song_correction_submit (corrections
route into tblSongRequests RequestType=correction → triaged by existing admin actions),
live_follow x7, service mode x12 (+control tokens, driver keys), gating (content_access,
tier_check, access_tiers, user_access, ccli_validate, user_effective_licences, licence_check),
app_status, captcha_widget_health, analytics_ingest, client_error_report, organisation (+create),
my_organisations, org_admin members x3 + licences x3, and the full `admin_*` set (§5).

### 2.3 `manage/editor/api2.php` (66 actions — session-only)

Whole-song lifecycle (load/create/duplicate/delete/save), metadata field updates, tune/copyright
holder/work set + autolink, components (upsert/delete/reorder/replace), arrangements, line
translations/annotations, credits, tags (list/search/attach/detach), links (save_all/add/remove/
suggestions/dismiss), external ids, alt titles, media x6, imports (file/zip + status/skipped),
bulk ops (verify/tag/move/delete), searches (credit/user/org/tune/publisher/work), load_index,
easyworship_export, revisions (list/snapshots/get/restore).

### 2.4 Standalone endpoints

- `song-media.php` — media bytes, **Bearer-capable** ✓ (checks header then cookie), gated by
  `contentGatingMediaAllowed()` + presence token.
- `audio-media.php` — legacy `/audio/<id>.mp3` gated route (#1358), dormant-degrading.
- `org-logo.php`, `qr.php`, `og-image.php` — anonymous by design ✓.
- `manage/places-api.php` — **session-only** (401 without manage session).
- `manage/print-pdf.php` — **session-only** (`isAuthenticated()`), 401 JSON, `?ping=1` detect.

---

## 3. Cross-cutting structural findings (decide these FIRST)

### X1 — KEYSTONE: api2.php is session-cookie-only → the entire song-editor surface is not native-capable

`manage/editor/api2.php:491` gates on `isAuthenticated()` (manage PHP session) + the
`X-Requested-With` POST gate. A native curator app cannot present a session cookie.
**Options:**
- **(a)** Teach api2's auth seam to ALSO accept `Authorization: Bearer` (resolve via the same
  `tblApiTokens` path `api.php`/`song-media.php` use), keeping the existing entitlement checks
  (`userHasEntitlement` at api2:524). ONE seam change unlocks all 66 actions for native.
- **(b)** Declare curator editing web-only (owner product decision) — then most of §4.3 drops to LOW.
- **(c)** Re-implement editor actions in api.php — **rejected**: forks 66 handlers (rule #22 violation).

**Recommendation: (a)**, but it is an OWNER DECISION (see §8 Q1). Everything in §4.3 assumes the
*api.php* pattern (Bearer-capable) regardless, so nothing in §4.3 depends on X1.

### X2 — No Android/FireOS push at all

`apns_register`/`apns_unregister` + `includes/apns.php` are Apple-only; `push_subscribe` is Web
Push (VAPID). Zero FCM references anywhere in the tree (verified: `grep -ri fcm|firebase` = none).
A native Android/FireOS app has **no push registration endpoint and no sending pipeline**.
This is a gap bigger than an endpoint (needs an FCM sender in `includes/`, config keys, and a
token table or a `Platform` discriminator on the APNs table pattern).

### X3 — Session-only standalone endpoints

`manage/print-pdf.php` and `manage/places-api.php` follow the manage-session pattern. If native
apps need server PDFs (batch set-list PDF is server-only) or place search (venue forms), each
needs the same Bearer fallback `song-media.php` already demonstrates.

### X4 — `?page=` HTML fragments are a legitimate API for content-only pages

`page=help|terms|privacy|whats-new` return sanitised HTML over GET on `/api` — a native app can
render these in a webview. **Not counted as gaps.** (Optional nicety: a `whats_new` JSON action;
see §7.)

---

## 4. GAP TABLE

Legend: **CONFIRMED** = I grepped api.php + api2.php + standalone endpoints and found no action
covering it. Priority: HIGH (native consumer app blocked), MED (org-admin / curator parity),
LOW (nice-to-have or web-only candidate).

### 4.1 CONSUMER-facing gaps (HIGH unless noted)

| # | Capability | Current reachability | Proposed endpoint(s) | Gating | Shared core to reuse | Status |
|---|---|---|---|---|---|---|
| C1 | **Android/FireOS push registration** | none (APNs + WebPush only) | `fcm_register` / `fcm_unregister` (mirror `apns_register` shape: POST, token+platform+app_version) | authenticated user | new `includes/fcm.php` mirroring `includes/apns.php`; reuse `tblApnsTokens` pattern (or generalise table with `Provider` VARCHAR — rule #20) | CONFIRMED — needs infra, not just endpoint |
| C2 | **Venue discovery for Service Mode operators** — `service_session_start` requires `venueId` but NO API lists venues/schedules (they are server-rendered into `service-lead.php`) | `manage/service-lead.php` page only | `org_venues` (GET: orgs the caller is admin/owner of → venues + service schedules, ids + names + TZ) | org admin/owner OR admin (same check `service_session_start` uses) | queries in `manage/venues.php` / `service-lead.php`; extract `includes/venue_admin.php` read half | CONFIRMED |
| C3 | **Tune public detail JSON** (`/tune/<slug>` has only the HTML fragment) | `?page=tune` fragment | `tune` action (GET `slug`/`id` → tune + songs using it), naming parallel to `work`/`credit_person` | public | `includes/tune_helpers.php` + `includes/pages/tune.php` query | CONFIRMED (MED) |
| C4 | **Publisher public detail JSON** (`/publisher/<slug>`) | `?page=publisher` fragment | `publisher_detail` action (GET `slug` → publisher + songbooks; resolve exact-slug → name-fold → alias-fold, rule #37) | public | `includes/publisher_helpers.php` + `includes/pages/publisher.php` ladder | CONFIRMED (MED) |
| C5 | **My-org CCLI usage report** (org admin reads own usage) | `manage/my-ccli-report.php` page only | `org_ccli_report` (GET org_id + period → per-song usage rows + licence context) | org admin/owner of that org | `includes/ccli_report.php` (exists; api.php never references it — verified) | CONFIRMED (MED) |
| C6 | **Server PDF for native** (song/set-list PDF; batch set-list PDF is server-only) | `manage/print-pdf.php`, session-only | extend print-pdf.php auth: Bearer fallback (the `song-media.php` two-step) — no new endpoint | authenticated (deliberately not entitlement-gated — keep its doc-block contract) | `includes/pdf_renderer.php` unchanged | CONFIRMED (MED — native apps can also render natively; batch PDF is the real need) |

**Verified NOT gaps (consumer):** catalogues surface to consumers as `IsOfficial=0` songbooks via
`getSongbooks()` ✓; songbook JSON includes series/compilers/links/languages ✓ (`SongData.php`
batch maps); corrections triage rides `tblSongRequests` ✓; personal stats = `song_history` +
`stats` ✓; offline = `songs_index`+`bulk_songs`+`bulk_audio`+`songbook_export` ✓; sheet
music/audio bytes = `song-media.php` Bearer ✓; device management ✓; SIWA + device-code TV
sign-in ✓; live-follow + service-mode congregant flows ✓ complete.

### 4.2 ORG-ADMIN self-service gaps (native org-admin parity with `my-organisations.php`) — MED

The `org_admin_member_*` (x3) and `org_admin_licence_*` (x3) actions already exist. The page's
remaining POST actions have **no API equivalent** (verified: no `idle_timeout`/`edit_audience`/
`logo`/`brand` actions in api.php):

| # | Capability | Page action(s) | Proposed endpoint | Gating | Core to reuse |
|---|---|---|---|---|---|
| O1 | Org behaviour settings (Live-Follow idle timeout #1770; set-list edit audience default/enforce #1791; the page's other defaults) | `idle_timeout_update`, `setlist_edit_audience_update` (+ `default`/`none`/`require` sub-values) | `org_admin_settings_update` (POST org_id + the settable keys; server echoes stored values — rule #35/#40) | org admin/owner | `includes/service_mode.php` resolver constraints + `includes/SharedSetlist.php` audience normalisers |
| O2 | Org logo upload / remove / show-hide (+ theme variants #1840) | `logo_upload`, `logo_remove`, `logo_toggle` | `org_admin_logo_upload` (multipart, mirror api2 `media_upload` shape), `org_admin_logo_delete`, `org_admin_logo_set_active` | org admin/owner | `includes/org_logo_admin.php` (`orgLogoUpsert`/`orgLogoDelete`/`orgLogoSetActiveKind`/`orgLogoDeleteKindAll`) — SVGs MUST pass `ihymnsSanitizeSvg()` (rule #42; the core already does) |
| O3 | Org brand colour | `brand_save` | `org_admin_brand_update` | org admin/owner | `ihymnsOrgBrandColourNormalise()` in `includes/organisation_validation.php` (rule #42 — the ONE normaliser) |
| O4 | Venue + service-schedule CRUD | `venue_save/venue_delete/schedule_save/schedule_delete` (venues.php, `manage_organisations`-gated) | `org_admin_venue_save`, `org_admin_venue_delete`, `org_admin_schedule_save`, `org_admin_schedule_delete` | org admin/owner (page today gates on `manage_organisations` — see §8 Q4) | **no core exists** — extract `includes/venue_admin.php` from venues.php FIRST (rule #22), then page + API both delegate |

Same-named `brand_save`/`logo_*` handlers also exist on the global-admin `organisations.php` —
the O2/O3 endpoints should take an `org_id` and allow global-admin OR that org's admin (one
endpoint, two audiences), not two forks.

### 4.3 ADMIN/CURATOR gaps — MED unless noted

| # | Capability (page) | Page actions | Proposed endpoints | Gating (same as page) | Core to reuse | Status |
|---|---|---|---|---|---|---|
| A1 | **Publishers registry** (`publishers.php`) | create/update/delete/merge (+aliases, links inside) | `admin_publisher_create/update/delete/merge` (+`admin_publisher_alias_add/remove` if the page exposes them separately) | `manage_publishers` | `includes/publisher_admin.php` — rule #37 literally anticipates "the future `admin_publisher_*` API" | CONFIRMED |
| A2 | **Works CRUD + medley composition** (`works.php`) | create/update/delete (+ medley constituent editing) | `admin_work_create/update/delete`; medley set via `admin_work_medley_replace` | `manage_works` | `includes/work_admin.php` incl. `workMedley*()` (rule #45 — never fork the cycle-guarded core) | CONFIRMED |
| A3 | **Tags/themes CRUD + merge** (`tags.php`) | create/update/delete/merge (+ #1222 canonicalisation suggestions) | `admin_tag_create/update/delete/merge`, `admin_tag_canonical_suggestions` (read) | `manage_tags` | inline in tags.php — **extract `includes/tag_admin.php` first**; scorer stays `includes/song_similarity.php` | CONFIRMED |
| A4 | **Catalogues CRUD** (`catalogues.php`) | add/update/delete/add_member/remove_member (+marcxml_import) | `admin_catalogue_create/update/delete/member_add/member_remove` (keep `catalogue` naming internally — rule #24) | `manage_songbooks` | inline — extract `includes/catalogue_admin.php` first | CONFIRMED (marcxml import: defer, see A17) |
| A5 | **Songbook series CRUD** (`songbook-series.php`) | create/update/delete (+marcxml_import) | `admin_songbook_series_create/update/delete` | `manage_songbooks` | inline — extract core first | CONFIRMED |
| A6 | **Language registry admin** (`languages.php`) | create/update/delete/toggle_active/remap_tag | `admin_language_create/update/delete/toggle/remap_tag` | `manage_languages` | inline — extract core; keep public reads untouched | CONFIRMED |
| A7 | **External-link types + patterns** (`external-link-types.php`) | save_type_patterns (+type CRUD in page) | `admin_external_link_type_save` (type + patterns in one write, as the page does) | `manage_external_link_types` | inline — extract core | CONFIRMED |
| A8 | **Print templates admin** (`print-templates.php`) | save/clone/delete/set_default/layout_save/layout_delete/import | `admin_print_template_save/clone/delete/set_default`, `admin_print_layout_save/delete` | `manage_songbooks` (page's current gate) | `includes/print_template_schema.php` validation + `includes/html_sanitizer.php` on layout HTML (rule #39 — sanitise on save AND render) | CONFIRMED |
| A9 | **Admin notification/announcement send** (`notifications.php`) | send (audience/user/role targeting, expiry, environment) | `admin_notification_send` (and `admin_notification_list/expire` if page offers) | `manage_notifications` | `includes/notifications.php` | CONFIRMED |
| A10 | **Duplicate-songs workflow** (`duplicate-songs.php`) | merge / link / unlink / dismiss / rebuild / auto_link | `admin_song_merge` (with `force` for same-official-book, #1218), `admin_song_link`/`admin_song_unlink` (cluster-batch), `admin_song_suggestions_rebuild`, `admin_song_auto_link` | view/link/dismiss = `edit_songs`; merge = `manage_duplicate_songs` (rule #22) | page logic → extract `includes/duplicate_song_admin.php`; scoring stays `includes/song_similarity.php`; NOTE api2 already has per-song `song_link_add/remove`, `song_link_suggestions`, `song_link_suggestion_dismiss` — reuse, don't duplicate | CONFIRMED |
| A11 | **Deleted songs restore/purge** (`deleted-songs.php`) | restore / purge (type-to-confirm) | `admin_song_restore`, `admin_song_purge` (require `confirm` echo server-side) | `edit_songs` / delete entitlement the page uses (verify exact gate at impl.) | `includes/song_soft_delete.php` | CONFIRMED |
| A12 | **Songbook disable/enable** (`songbooks.php` `toggle_disable`) | toggle_disable | extend **existing** `admin_songbook_update` with `is_disabled` (verified absent today) — no new action | `manage_songbooks` | existing handler + `includes/songbook_visibility.php` | CONFIRMED |
| A13 | **Musician-duplicate dismiss/undismiss** (`musician-duplicates.php`) | dismiss/undismiss (merge already = `admin_musician_merge` ✓) | `admin_musician_duplicate_dismiss` / `_undismiss` | `edit_songs`-equivalent page gate | `includes/musician_duplicates.php` | CONFIRMED (LOW) |
| A14 | **Analytics extras** (`analytics.php` top_songs/top_books views) | GET views | optional `admin_analytics_top` (period → books+songs) — `popular_songs` (public) + `admin_analytics_searches` already cover most | admin | `includes/analytics_ingest.php` read queries | CONFIRMED (LOW) |
| A15 | **Data-health fix write** (`data-health.php` `disconnect_fallbacks`) | one POST fix | `admin_data_health_fix` (op allow-list, starts with `disconnect_fallbacks`) | admin | page logic → small core | CONFIRMED (LOW) |
| A16 | **Activity-log geo helper** (`activity-log.php?action=geo`) | in-page AJAX | fold into `admin_activity_log` as `?geo=ips` or a tiny `admin_ip_geolocate` | admin | `includes/ip_geolocation.php` | CONFIRMED (LOW) |
| A17 | **MARCXML imports** (songbooks/series/catalogues), **bulk-promote wizards**, **ia-reconcile run**, **family_manifest** | page-only multi-step curator tools | DEFER — wizard-shaped, file-upload flows; API them only if a native curator surface is confirmed (X1/Q1) | various | `includes/marcxml.php`, `includes/ia_reconcile.php` | CONFIRMED but deliberately deferred |
| A18 | **API-key admin** (`api-keys.php`) | create/toggle/delete/set_limits/approve_request/reject_request/request | DEFER pending §8 Q5 — key mint/reveal over API widens secret exposure; `request_api_keys` self-service could be `api_key_request` later | `manage_api_keys` / `request_api_keys` | `includes/api_keys.php` | CONFIRMED gap, deliberate deferral recommended |
| A19 | **Webhook admin** (`webhooks.php`) | create/update/delete/pause/resume/rotate_secret/reveal_secret/send_test/redrive/verify | recommend web-only (secret reveal + infra semantics); revisit on demand | `manage_webhooks` | `includes/webhook_admin.php` | CONFIRMED gap, web-only recommended |

**Verified already-covered admin families (§5) — the implementation pass must NOT re-add them.**

---

## 5. Already covered — do NOT re-add

| Manage page / feature | Existing API |
|---|---|
| users.php (all 8 actions) | `admin_user_create/update/rename/role_change/toggle_active/password_reset/delete`, `admin_set_user_tier`, `admin_set_user_ccli`, `admin_users` |
| groups.php | `admin_groups`, `admin_group_create/update/delete/member_add/member_remove` |
| songbooks.php (except `toggle_disable`/`family_manifest`/`marcxml_import`) | `admin_songbook_create/update/delete/delete_cascade`, `admin_songbooks_reorder`, `admin_songbooks_auto_colour_fill/reassign`, `admin_songbook_health` |
| tiers.php | `admin_tier_create/update/delete`, `access_tiers` |
| licence-types.php | `admin_licence_type_create/update/toggle/delete` |
| restrictions.php | `admin_restrictions`, `admin_restriction_create/delete` |
| organisations.php (CRUD+members; NOT logo/brand) | `organisation_create`, `admin_organisations`, `admin_organisation_update/delete/member_add/member_role_change/member_remove` |
| my-organisations.php (members+licences; NOT settings/logo/brand/venues) | `org_admin_member_add/role_change/remove`, `org_admin_licence_add/change/remove`, `my_organisations` |
| musicians.php + musician-duplicates merge | `admin_credit_person_add/update/rename/merge/delete` (aliases `admin_musician_*`) |
| tunes.php | `admin_tune_add/update/merge/delete` |
| requests.php (triage, incl. corrections — they live in tblSongRequests) | `admin_song_requests`, `admin_song_request_update` |
| revisions.php | `admin_pending_revisions`, `admin_revision_review`, api2 `revision_list/snapshots/get/restore` |
| activity-log.php (read) | `admin_activity_log` |
| analytics.php (searches) | `admin_analytics_searches` |
| data-health.php (read) | `admin_data_health` |
| schema-audit.php / setup-database status (reads) | `admin_schema_audit`, `admin_migrations_status` |
| language registry refresh | `admin_refresh_iana_cldr` |
| curator export | `admin_export` (json/csv/xml/opensong/videopsalm), api2 `easyworship_export` |
| cleanup | `admin_cleanup` |
| all song editing / media / imports / links / credits / components / arrangements / enrichment | api2's 66 actions (session-only — see X1) |
| whole consumer surface | §2.2 list — search, reads, setlists, favourites, offline, live-follow, service-mode, auth, devices, settings, push (Apple/web), notifications read, requests/corrections submit, gating checks |

---

## 6. Deliberately web-only — do NOT expose via API (say why)

| Surface | Why |
|---|---|
| `setup-database.php` (migration runner incl. "Apply all", manual/confirm-gated drops) | schema mutation from a network API is an attack surface; migrations are deliberately web-run, admin-eyes-on (rules #19/#25) |
| `diagnostics.php` (raw SQL runner) | arbitrary SQL — never API-addressable |
| `configuration.php` (SMTP, Apple SIWA keys, CueRCode key, maintenance mode, secrets via `secret_crypto_admin.php`) | secret material write/read; keep to authenticated browser session on the admin origin |
| `gating.php` + `gating-noop-verify.php` + `feature-gating.php` | master content-gating switches + verification harness; flipping gating remotely defeats its dormancy discipline (rule #28) — recommend web-only (feature-gating CRUD can be revisited) |
| `entitlements.php` (role→entitlement matrix save) | the authorization fabric itself; an API write here can self-escalate — web-only recommended |
| `setup.php` (initial install) | pre-auth bootstrap |
| `webhooks.php` secret reveal/rotate (A19) | secret exposure |
| `api-keys.php` mint/reveal (A18) | secret exposure — defer pending Q5 |
| `webhook-drain.php`, `language-registry-refresh.php`, `opcache-bust.php` | infra/cron endpoints, already standalone + keyed/protected — not app functionality |
| `intapps-status.php`, `api-docs.php`, `help.php` (admin), `index.php` dashboard | status/docs/navigation UIs — reads they render largely exist as API already |
| `og-image.php`, `sitemap.xml.php`, `service-worker.js.php` | web-platform artefacts, meaningless to native |
| session-transcript tooling (`.claude/`, sync scripts) | not app functionality |

---

## 7. Considerations (not gaps; optional niceties)

- `whats_new` / `help_topic` / `terms` / `privacy` JSON actions — fragments already serve these
  over GET `/api?page=…`; native webview rendering is acceptable. Only add JSON if the native
  design wants native-typography rendering. LOW.
- `external-link-detect` patterns read for native editors — only relevant if X1 lands.
- A versioned envelope / OpenAPI regeneration: whatever lands must be added to `api-docs.yaml`
  in the same PR (docs are not a mechanism — rule #35 — but they are a deliverable; the
  swagger-UI obligation is in standing-directives).

---

## 8. Owner decisions needed (per the decision-presentation format)

**Q1 — Should native apps do curator/admin editing at all?** (Blocks §4.3 priority + X1, not Batch 1.)
The song-editor API (66 actions) is session-bound. Options: (a) add Bearer auth to api2 —
one seam, unlocks everything, recommended; (b) curator work stays web-only — §4.3 items still
worth building for parity/automation but drop priority; (c) re-implement in api.php — rejected
(fork). **Recommendation: (a)** — the entitlement checks already exist per-action, and
`api.php` proves the Bearer pattern is sound. Smallest reply: "a, b, or c".

**Q2 — Android push provider.** FCM is the default choice but pulls Google Play Services
(FireOS needs ADM — Amazon Device Messaging — instead!). Options: FCM only / FCM+ADM /
unified provider column supporting both. **Recommendation:** provider-column design
(`Provider` VARCHAR — rule #20) with FCM first, ADM second. Non-blocking to everything else.

**Q3 — Server PDFs for native (C6):** extend print-pdf.php with Bearer, or declare native
renders its own printouts? **Recommendation:** add Bearer — the batch set-list PDF and CCLI
usage logging live server-side and shouldn't fork.

**Q4 — Venue CRUD gating (O4):** page gates on `manage_organisations` (global-ish) while
service_session_start accepts org admins. Should org owners/admins manage their OWN venues via
API (recommended), or keep venue CRUD global-admin? Smallest reply: "org-admins yes/no".

**Q5 — API-key + webhook admin over API (A18/A19):** recommend web-only (secret reveal).
Smallest reply: "agree web-only" or "API them".

None of Q1–Q5 blocks Batch 1.

---

## 9. Batched implementation plan (priority order)

Every batch: follow the api.php house pattern — POST JSON writes, `validateCsrfRequest()`,
`getAuthenticatedUser()` + `userHasEntitlement()`, `sendJson()` envelope, entitlement identical
to the page's gate, delegate to the SAME shared core the page uses (extract the core first where
none exists — rule #22), and update `api-docs.yaml` + tests in the same PR. Where a page today
inlines logic, the extraction commit precedes the API commit and the page is re-pointed at the
core in the same PR (no behaviour change).

**Batch 1 — Native consumer unblockers (HIGH):**
1. C2 `org_venues` (+ extract `includes/venue_admin.php` read half).
2. C1 FCM/ADM registration pair + `includes/fcm.php` sender skeleton (per Q2) — the endpoint
   shape mirrors `apns_register` exactly.
3. C6 print-pdf Bearer fallback (mirror `song-media.php`'s resolver).
4. C3 `tune` + C4 `publisher_detail` JSON reads.
5. C5 `org_ccli_report` (core: `includes/ccli_report.php`).

**Batch 2 — Keystone curator transport (pending Q1):**
6. api2.php Bearer seam (X1) + `manage/places-api.php` Bearer fallback (X3).
   Zero new actions; contract tests assert cookie behaviour unchanged.

**Batch 3 — Org-admin self-service parity (MED):**
7. O1 `org_admin_settings_update`; O3 `org_admin_brand_update`.
8. O2 `org_admin_logo_upload/delete/set_active` (multipart; core `org_logo_admin.php`).
9. O4 venue + schedule CRUD (core extracted in Batch 1).

**Batch 4 — Registry admin CRUD parity (MED; cores first where marked):**
10. A1 publishers (core exists). 11. A2 works + medley (core exists).
12. A3 tags (extract core). 13. A4 catalogues + A5 series (extract cores).
14. A6 languages (extract core). 15. A7 external-link types (extract core).
16. A8 print templates. 17. A9 notification send. 18. A12 `admin_songbook_update` +is_disabled.

**Batch 5 — Curator workflows (MED-LOW):**
19. A10 duplicate-songs cluster ops (extract core; reuse api2 per-song link actions).
20. A11 deleted-song restore/purge. 21. A13 musician-duplicate dismiss.
22. A14 analytics top read. 23. A15 data-health fix. 24. A16 activity-log geo fold-in.

**Batch 6 — Deferred / decision-gated:**
25. A17 MARCXML + wizards (only if Q1=native curator yes). 26. A18/A19 per Q5.

**Testing discipline per batch (rule #34):** a tree-derived guard asserting every `manage/*.php`
POST action name has either (a) a mapped api.php/api2 action or (b) an entry on the explicit
web-only allow-list in this doc — mutation-proven (delete one mapping → red). That converts this
one-off audit into a standing mechanism so the next page-only handler cannot land silently.

---

## 10. Verification notes (how each claim was checked)

- Action inventories: `grep -nE "case '"` over api.php (223 actions ≥ line 957, 30 page routes
  697–905), api2.php (66), legacy editor api.php (40).
- Page handler inventories: per-file grep of `$_POST['action']`/`case` over all 58 `manage/*.php`.
- Negative checks (absence proofs) run for: api_key/catalogue/publisher/series/external_link/
  work-CRUD/tag-CRUD/language-CRUD/webhook/notification-send/feature/entitlement/org-logo/brand/
  deleted/restore/print-template-CRUD/venue/IsDisabled/fcm|firebase/ccli_report — all absent from
  api.php (and api2 where relevant).
- Auth transports read from source: api.php Bearer+cookie (`getAuthBearerToken`, Vary header);
  api2.php `isAuthenticated()` only; song-media.php Bearer-then-cookie; print-pdf + places-api
  session-only.
- Consumer fetch targets: grep of every `fetch(`/`apiFetch(` literal under `js/` — all `/api`
  except `/manage/print-pdf` and `/manifest.json`.
- False positives eliminated during analysis: catalogue consumer read (covered via songbooks),
  songbook series consumer read (covered — `SongData` attaches `series`), corrections triage
  (covered via `admin_song_request*`), song merge scorer (api2 covers per-song link half),
  users.php `change_tier` (= `admin_set_user_tier`), analytics searches (= `admin_analytics_searches`),
  stats page (= `action=stats`), musician merge (= `admin_musician_merge`).
