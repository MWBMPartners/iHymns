# Orphan inventory — definitive, mechanically derived (2026-07-30)

Branch: `claude/wave3-fixes` @ `9aabb42c`. Method: static analysis only (no MySQL, no browser).
Every list below was **derived from the tree by script**, not typed; the scripts live in the
session scratchpad (`extract-cases.php`, `xref2.py`, `table-xref2.py`, `deeplinks2.py`,
`manage-actions.py`). Per the owner's instruction, **issue bodies, commit messages, CHANGELOG and
`.claude/` docs were treated as hypotheses, never evidence** — issue numbers appear below only as
cross-references, and every doc-vs-code disagreement found is reported as a finding in §7.

---

## 0. Method notes — read these before trusting any list (rule #34)

The first version of almost every scan in this report was **wrong-but-green**. Each failure is
recorded because the CI guard (§9) must not repeat it:

1. **`rg` silently dropped hits twice in this environment.**
   (a) Multi-root invocations (`rg PAT appWeb appApple …`) deterministically omitted the
   `appApple/Packages/**` matches for `apns_register` while single-root invocations found them
   (16 matches with `--stats` or roots reordered, 4 without — reproduced 5×). Root cause not
   fully diagnosed; `-uu` or per-root invocation avoids it.
   (b) `rg PAT appWeb` **skips `appWeb/.sql/` entirely because dot-directories are hidden by
   default** — `ccli_validation_enabled` in `schema.sql:1722` was invisible until plain `grep`
   was used. Any guard built on `rg` defaults will under-report the whole migrations tree.
   Consequence: the final evidence base was rebuilt as a Python full-corpus scan
   (`os.walk`, explicit skip-list `{.git, node_modules, vendor}` only), then spot-verified with
   plain `grep`.
2. **A caller is not always a literal string.** Callers appear as: `?action=X` URLs; quoted
   strings passed to helpers (`postJson('X')`, `apiCall('X')`, Swift `Endpoint(action: "X")`);
   **unquoted JS object keys** (`post({ action: 'link' })` — this false-flagged all 6
   `/manage/api-keys` and all 4 `/manage/duplicate-songs` actions until the pattern was added);
   `input.value = 'update_person'` assignments; and **server-emitted URLs consumed as data**
   (`skipped_csv_url` / `poll_url` — see §2.6). A guard matching only `action=X` will cry wolf.
3. **A "writer" is not always a literal table name.** `external_link_helpers.php:214/230` writes
   four `tbl*ExternalLinks` tables through `{$table}` interpolation from an allow-list map —
   which false-flagged `tblWorkExternalLinks` and `tblSongbookExternalLinks` as writer-less
   until checked by hand.
4. **Every scanner here was proven able to fail**: the zero-hit result for `bulk_tag_detach` was
   confirmed by four independent hand-searches (camelCase variant, dynamic-prefix construction,
   v2 client method table, commit content); positive controls (`auth_verify_email` → exactly
   `user-auth.js:1141`; `tblSongLinks` → not flagged) behaved correctly.

**Residual blind spots** (stated, not hand-waved): §10.

---

## 1. Executive summary

| Category | Finding | Count |
|---|---|---|
| **A** — API actions with **no first-party caller anywhere** (web, admin, Apple, Android, SW) | of 278 dispatched actions (195 `api.php` + 40 `editor/api.php` + 40 `editor/api2.php` + 3 `places-api.php`), plus 23 `page=` fragments audited separately | **93** |
| A.1 — of which: coherent **admin/org API-parity family** (documented, no in-repo consumer) | | 53 |
| A.2 — of which: **content-gating / licensing** family (dormant-by-design per rule #28) | | 11 |
| A.3 — of which: **user-feature endpoints** (#1671 class — 11 already filed, **3 new**) | | 14 |
| A.4 — of which: documented-dormant device-code + control-token pairs (#1511) | | 4 |
| A.5 — of which: misc public-API endpoints, undocumented-dormant | | 6 |
| A.6 — of which: **v1 editor translations trio** (dead code, still in OpenAPI) | | 3 |
| A.7 — of which: **`bulk_tag_detach`** — docs claim it shipped as a restored control; **no UI, no client method, no caller** | | **1** |
| A.8 — of which: `places-api.php?action=get` | | 1 |
| Apple-only callers (NOT orphans — native-app-only endpoints) | `song_links`, `apns_register`, `apns_unregister`, `favorites_remove` | 4 |
| **B** — tables **dormant** (no app read, no app write) | of 141 `tbl*` in schema.sql | **34** |
| B.1 — of which: schema.sql-only, **no migration, no code mention at all** (legacy scaffold) | `tblMigrations`, `tblSessions`, `tblUserPermissions`, `tblUserPurchases` | 4 |
| B.2 — tables with an app **reader but no writer anywhere** (permanently-empty reads) | | **7** (+1 deliberate legacy fallback) |
| B.3 — tables **written but never read** | `tblLyricWords`, `tblLyricSyllables` | 2 |
| B.4 — seed-only reference tables (migration writes, app reads — fine by design) | | 5 |
| **C** — reachable by API but no user-facing path | overlaps A.1/A.2; plus the `?include=` extras that can only ever return empty (§4) | see §4 |
| **D** — dead deep links | **0 new** (the 4 editor params are live and guarded by `tests/test-editor-deep-links.js`) | **0** |
| **E** — new categories nobody had looked at | OpenAPI documents **4 endpoints that don't exist** (400 on call); **3 dead settings chains**; 2 settings with no admin UI; **~11 decorative entitlements**; **2 dead include files** (one of them a fully-tested importer); assorted | §6 |
| **Doc-vs-code disagreements** | | **7** (§7) |

**Honest headline:** most of the 93 caller-less actions are *coherent, deliberate-looking
families* (a documented admin API surface, dormant gating, #1511 live-dormant batches) — not 93
individual bugs. The list of things that genuinely "look shipped but aren't" — the owner's
complaint — is **short and specific**: §2.7 `bulk_tag_detach`, §6.1 the four phantom OpenAPI
endpoints, §6.4 `MusicXmlImporter`, §6.5 `pdf_export`, §3.2's seven read-only tables (incl. the
already-filed #1669/#1670), the three dead settings chains, and the v1 translations trio.

---

## 2. Category A — API actions with no caller

Dispatch surfaces were **found, not assumed**: every `switch` in every PHP file was tokenised
(`token_get_all`, brace-depth-aware so nested switches don't pollute), plus if-chain dispatches
(`$action === '…'`). Surfaces: `api.php` (`$action` ×195, `$page` ×23; `health` is handled
pre-switch at `api.php:423` — deliberately, per its own comment), `manage/editor/api.php` (×40),
`manage/editor/api2.php` (×40), `manage/places-api.php` (if-chain ×3), and 24 self-posting
`manage/*.php` pages (§2.9 — all clean). Evidence-of-absence for each entry: a full-corpus scan
(1,214 files: appWeb + appApple + appAndroid + tests + tools + help + wiki, including hidden
`appWeb/.sql/`) for `'X'` / `"X"` / `[?&]action=X` found **zero matches outside the dispatch
file and pure documentation** (api-docs.yaml, *.md, help). Buckets per hit were recorded; "no
first-party caller" means zero hits in WEB-JS / ADMIN-JS / ADMIN-PHP / WEB-PHP / APPLE / ANDROID
/ service-worker.

### 2.1 The admin/org API-parity family — 53 actions, `api.php`

`admin_activity_log, admin_analytics_searches, admin_cleanup, admin_credit_person_{add,delete,merge,rename,update},
admin_data_health, admin_export, admin_group_{create,delete,update}, admin_group_member_{add,remove}, admin_groups,
admin_migrations_status, admin_organisation_{delete,update}, admin_organisation_member_{add,remove,role_change},
admin_organisations, admin_pending_revisions, admin_revision_review, admin_schema_audit,
admin_song_request_update, admin_song_requests, admin_songbook_{create,delete,delete_cascade,health,update},
admin_songbooks_{auto_colour_fill,auto_colour_reassign,reorder}, admin_tier_{create,delete,update},
admin_user_{create,delete,password_reset,rename,role_change,toggle_active,update}, admin_users,
org_admin_licence_{add,change,remove}, org_admin_member_{add,remove,role_change}` (api.php:6754–14750, 13298–13767)

- Every one is documented in `api-docs.yaml` (only doc-bucket hits). The equivalent `/manage/*`
  pages do **their own direct DB work** (e.g. `manage/users.php:29ff` self-posting handlers;
  `manage/groups.php:52ff`), so nothing first-party calls the JSON twins. One admin JSON action
  IS web-called and proves the classifier can tell the difference: `admin_refresh_iana_cldr`
  (caller `manage/setup-database.php:3727`).
- The four dashboards (`admin_data_health`, `admin_schema_audit`, `admin_migrations_status`,
  `admin_analytics_searches`) duplicate pages that render server-side; `schema-audit.php:36-37`
  even says the shared includes were extracted "so the … API endpoints can call them" — the
  endpoints exist, and then nothing calls them.
- **Assessment:** deliberate API-first surface (`#719` "API parity audit" + the Swagger
  try-it-out console at `/manage/api-docs` are the plausible intended consumers — that is
  cross-reference, not evidence). Whether keeping 53 permanently-untested, entitlement-gated
  write endpoints is worth the attack surface is an **owner decision**; #1671's own framing
  applies ("a permanently untested surface that reads as working").
- Confidence: **high** on "no in-repo caller" (full-corpus, zero hits, no dynamic
  `'admin_' + …` construction found anywhere — searched). Cannot rule out **out-of-repo** API-key
  consumers (§10).

### 2.2 Content-gating / licensing family — 11 actions, dormant-by-design

`user_access` (5663), `content_access` (7202), `admin_restrictions` (7223), `admin_restriction_create` (7251),
`admin_restriction_delete` (7295), `ccli_validate` (9341), `access_tiers` (9439), `admin_set_user_tier` (9508),
`admin_set_user_ccli` (9545), `user_effective_licences` (10723), `licence_check` (10748)

- Consistent with rule #28's "ENTIRELY DORMANT" program; the yaml documents the dormancy.
  BUT: `user_access` is **worse than dormant** — its handler UNIONs
  `tblUserGroupMembers` (api.php:5685), a table that (a) nothing ever writes and (b) exists
  **only in `schema.sql:1118`** with **no `migrate-*.php`** — so on a long-running migrated
  install the query throws under STRICT and the action 500s. Matches already-filed **#1670**
  exactly (verified from code, not from the issue).
- Confidence: high.

### 2.3 User-feature endpoints — 14 actions (#1671 class; 3 not previously filed)

| Action (api.php line) | State | Covered by an issue? |
|---|---|---|
| `push_subscribe` (9163), `push_unsubscribe` (9219) | yaml itself says "Dormant … simply has no caller" (yaml:6192-6196, 6232-6235); SW has **no `push` handler** (verified: `service-worker.js.php` message cases only) | #1435 / #1671 |
| `devices_list` (4292), `device_signout` (4341) | no devices-management screen exists | #1671 |
| `song_key` (7438), `song_key_save` (7474) | zero refs in js/manage — transpose UI does not persist | #1671 ("keys") |
| `setlist_templates` (7672), `setlist_template_save` (7729) | no templates UI | #1671 ("templates") |
| `user_preferences` (8329), `user_preferences_sync` (8359) | superseded by `user_settings` (case 3138; caller `js/modules/settings.js:202,221`) | #1671 (delete recommended) |
| `my_song_requests` (5201) | users can submit (`song_request_submit` is live) but can never see outcomes | #1671 |
| **`custom_tags`** (4943) | **NEW** — only the sync twin is called (`user-auth.js:781` calls `custom_tags_sync`); the GET listing has no caller | **not filed** |
| **`setlist_schedule`** (7529), **`setlist_schedule_save`** (7587) | **NEW** — a superseded older date-range pair; the JS uses the newer `setlist_schedule_set/clear/current/upcoming` family (`setlist.js:2805/2834/2707/2763`) | **not filed** (distinct from #1671's "templates") |

### 2.4 Documented-dormant pairs — 4 actions

- `auth_device_code_request` (6426) / `auth_device_code_poll` (6470): the **device side** of the
  TV-linking flow. The **approve side is live** (`device-link.js:54/65` calls `_link_lookup`,
  `_approve`, `_deny`); the device side's intended consumer (tvOS app) has **no device-code code
  at all** (searched all of `appApple/`). yaml:3564 declares the family live-dormant (#1511).
- `service_control_token_mint` (16085) / `service_control_token_revoke` (16158): yaml:11097
  declares live-dormant (#1511).

### 2.5 Misc public API, undocumented-dormant — 6 actions

`song_by_identifier` (1150), `person_by_identifier` (1187), `songs_list` (1412), `my_organisations` (7026),
`songs_by_tag` (8226), `song_revisions` (8579) — each has exactly one hit: its api-docs.yaml path.
The tag page (`?page=tag`, #1637) made `songs_by_tag` redundant for the web; the rest look like
API-parity spillover. No dormancy note in the yaml for these (unlike §2.4) — i.e.
**documented as if live, called by nothing**.

### 2.6 NOT orphans — the data-driven URL chains (method lesson)

`bulk_import_skipped_csv` (editor/api.php:2845) and `import_zip_skipped_csv` (api2.php:2153)
scored **zero** static callers — yet both are reachable: the server emits their URLs as
**response data** (`skipped_csv_url`, api.php-v1:2803 / api2.php:2141), the poll URL travels the
same way (`poll_url`, v1:1909 / api2:2032), and `bulk-import-progress.js:338/452` consumes both.
Any CI guard must count a self-emitted `action=` URL inside a dispatch file as a caller-by-proxy,
or it will file these as bugs forever.

### 2.7 ★ `bulk_tag_detach` — the one that "shipped" this morning and is dead

- **What:** `manage/editor/api2.php:2281` (`case 'bulk_tag_detach'`).
- **Evidence of absence:** full-corpus zero hits; no `bulkTagDetach` method in the v2 client
  (`manage/editor/v2/api-client.js:132-221` — the method table ends at `detachTag` =
  single-song `tag_detach`, line 186); the v2 bulk bar wires **verify + attach only**
  (`editor2.php:386-406`; the only bulk-tag call is `editorApi.bulkTagAttach`, line 401 — there
  is no Remove button); no dynamic `'bulk_tag_' + …` construction anywhere.
- **Doc-vs-code:** commit `33f583e1` (today) touched **only api2.php** (+62 lines, verified via
  `git show --stat`), yet `CHANGELOG.md:6` says the pass shipped "`bulk_tag_detach` … restoring
  v1 controls v2 had dropped", `PROJECT_STATUS.md:78` and `.claude/ProjectBrief.md:49` repeat
  it, `api-docs.yaml:45-46` calls it a closed parity gap, and the 2026-07-28 handoff (line 122)
  marks #1628 item 3 "✅ DONE". The commit message itself even names the exact failure mode:
  "there is simply no Remove button". **The endpoint is real; the restored capability is not.**
- Confidence: **high**. Category A; the flagship instance of the owner's complaint.

### 2.8 v1 editor translations trio — dead code still documented

`get_translations` (editor/api.php:369), `add_translation` (405), `remove_translation` (448):
only hits are their api-docs.yaml paths (6660/6698/6734). The legacy editor's
`renderTranslations` panel (editor.js:2913ff, mounted at 775) makes **no network calls** — the
cross-song-link UI moved to `get_song_links`/`add_song_link` (#352→#807) and per-line enrichment
went to api2. Dead endpoints + stale docs. (Distinct from the public `song_translations` action,
which is live.)

### 2.9 Clean results, stated for completeness

- **All 24 self-posting `manage/*.php` action handlers have an emitting control** (after
  accounting for unquoted-JS-key and `.value=` emitters — see §0.2). Final count: 0 orphans.
- **All 23 `page=` fragment cases are routed** (`router.js` covers every one;
  `request-a-song` is a served alias of `request`, api.php:716-717; router-only `login` /
  `not-found` are client-side by design, with the `$page` default at api.php:724 serving
  not-found).
- `places-api.php`: `search` (caller `place-search.js`) and `upsert` (`place-search.js:576`)
  live; **`get` (places-api.php:197) has no caller** — its own header (line 27-29) says "Used by
  edit forms that want to re-render a chip from a stored FK", and no edit form does.
- Apple-only endpoints (callers exist, natively): `song_links`, `apns_register`,
  `apns_unregister`, `favorites_remove` (all in
  `appApple/Packages/iHymnsKit/Sources/IHAPI/*.swift`; the Android app is fully local —
  `SongViewModel.kt` loads a bundled `songs.json` asset and calls **no** API at all).
- v1 editor endpoints still called only from the legacy editor UI (reachable via `?legacy=1`)
  are deliberate side-by-side until #1601 scope 3; `tests/php/test-v1-consumer-deorphan.php`
  already guards the five non-editor consumers.

---

## 3. Category B — tables vs read/write paths

Scan: every `CREATE TABLE` in `appWeb/.sql/schema.sql` (141 tables; 0 views defined), matched
against whole-text (multi-line tolerant) `INSERT|UPDATE|DELETE|REPLACE|TRUNCATE` (writers) and
`FROM|JOIN` (readers) across appWeb+tools+tests, zoned APP / MIGRATION / SCHEMA-ADMIN / TEST.
Dynamic `{$table}` writers resolved by hand (§0.3).

### 3.1 Dormant — no app read, no app write (34)

Nothing outside migrations/schema-admin mentions them. Grouped by evident provenance
(provenance from the creating migration file, which is code, not from issues):

- **Forward-looking one-pass batches (rule #20 pattern; deliberate):**
  `tblLyricsConflicts`, `tblLyricsReviewQueue`, `tblSongIdentityMap`, `tblSongEmbeddings`,
  `tblSongQualityFindings`, `tblSongUsageEvents`, `tblSearchSynonyms`,
  `tblLyricsSourceDocuments`, `tblLyricAnnotationVotes`, `tblLyricLineVocalParts`,
  `tblLyricWordVocalParts`, `tblTuneAliases`, `tblCreditPersonIPI`, `tblBibleBooks`,
  `tblExternalSystems`, `tblOrganisationExternalRefs`, `tblOrgVenueExternalRefs`,
  `tblOrgServiceScheduleExternalRefs`, and the 8-table `tblPresentation*` family
  (`migrate-presentation-themes.php`). `tblServicePollCounters` is described as "the dormant
  foundation" in its own migration card (`migration-registry.php:2247`).
- **Migration-written only, never app-read (interesting middle state):** `tblSongLanguages`,
  `tblSongbookLanguages`, `tblSongbookEntries` — backfilled by migrations, then no app code
  reads them.
- **★ Legacy scaffold — schema.sql-only, no migration, no mention anywhere:**
  `tblSessions` (schema.sql:747), `tblUserPurchases` (940), `tblUserPermissions` (1135),
  `tblMigrations` (1497). Fresh installs create four tables the codebase has never referenced.
  Not previously filed as far as the repo's docs show.

### 3.2 App READER exists, writer does NOT exist anywhere (the #1669 shape) — 7 (+1)

| Table | Reader (app) | Writer | Note |
|---|---|---|---|
| `tblSongAlternativeTitles` | `SongData.php:955,966,2946` | none — creating migration has **zero INSERTs** | already filed **#1669** (verified) |
| `tblUserGroupMembers` | `api.php:5685` (`user_access`) | none — membership actually lives in `tblUsers.GroupId` (`api.php` admin_group_member_add UPDATEs tblUsers) | **#1670** (verified; also 500s on migrated installs, §2.2) |
| `tblSongArrangements` | `SongData.php:2215` (via `?include=` extras) | none (the editor's `arrangement_update` writes `tblSongs.ArrangementJson` instead — a different store) | half-wired: read side shipped, write side never did |
| `tblSongRoyaltyIds` | `SongData.php:2230` | none | same |
| `tblSongScriptureRefs` | `SongData.php:2239` | none | same |
| `tblVocalParts` | `SongData.php:2251` (per-Lyrics rows, not a taxonomy) | none — no seed, no writer | same |
| `tblContentLicences` | `includes/licences.php:117,162` | none in repo (catalogue rows exist only in `.sql/.fulldata/ihymns-full.sql`) | gating family; a fresh schema-only install reads an empty catalogue |
| (`tblCreditPersonLinks`) | `index.php:541`, `pages/person.php:287` | none | **deliberate** legacy fallback (`if (empty($linksUnified)) try { … }`) for pre-backfill installs — dead on migrated installs by design |

False positives corrected by hand: `tblWorkExternalLinks`, `tblSongbookExternalLinks` — written
via the `{$table}` allow-list in `external_link_helpers.php:202-231`.

### 3.3 App WRITES, nothing ever reads — 2

`tblLyricWords` (writer `lyrics_ingest.php:358`) and `tblLyricSyllables` (`lyrics_ingest.php:362`):
word/syllable timing is ingested and then never surfaced by any read path. Write-only data.

### 3.4 Seed-only reference tables (fine by design) — 5

`tblLanguageScripts`, `tblLanguageVariants`, `tblRegions`, `tblSongPartTypes`, `tblTunes` —
migrations seed, app reads. Expected shape for reference data.

---

## 4. Category C — reachable by API, no user-facing path

- The entire §2.1 family is invocable by an authenticated admin via the Swagger try-it-out
  console (`/manage/api-docs`, `view_api_docs` entitlement) — a real, mounted UI — but no
  feature UI drives any of them. That is #1671's category generalised to 53 more endpoints.
- **`song_detail?include=…` extras** (api.php:988-996 → `SongData::getSongDetailExtras`,
  SongData.php:2173ff): a documented request surface whose `arrangements` / `royaltyIds` /
  `scriptureRefs` / `vocalParts` blocks read the §3.2 writer-less tables — so they are
  **permanently empty for every caller**, and no first-party client requests them anyway.
  API-reachable, user-invisible, data-impossible: three orphanhoods stacked.
- `audio-media.php` (+ `includes/audio_signing.php`) — reachable only when
  `audio_signing_enabled='1'` + a key file exists; dormant-by-design with an explicit staged
  rollout note in its header (#1358). Not a bug; listed for completeness.

---

## 5. Category D — dead deep links: none new

Scan: every internal `href` / `location(.href)` / `window.open` / `pushState` URL with a query
string or fragment, PHP + JS, attribute-bounded (both quote styles, PHP interpolations
tolerated — the exact truncation traps documented inside `tests/test-editor-deep-links.js:79-94`
were reproduced and avoided). 169 raw candidates → after removing `?>`-tag false matches and
`?v=` cache-busters, every emitted param is read by its destination. Hand-verified the
non-obvious ones: `/manage/credit-people?id=` (read at credit-people.php:186 — the #1641 fix is
in place), `/manage/organisations?edit=` (organisations.php:391), `/manage/groups?edit=`
(groups.php:192), `/manage/analytics?range=&export=` (analytics.php:38,51),
`/request?songbook=&number=` (request-a-song.php:39 + request-a-song.js:244ff),
`/manage/setup-database?action=install|account-sync|users|backup|cleanup|drop-legacy`
(all in `$scriptMap`, setup-database.php:723-736), and the 4 `/manage/editor/?…` params
(handled via the router's QUERY_STRING-preserving 302 → editor2.php; guarded by the existing
test). The fragment wrinkle (`#number=` never reaches PHP) and the router-aimed param
(`?legacy=1`) are both handled where the existing guard says they are.

**Result: 0 dead deep links.** An honest clean category — the #1623/#1628/#1680 class is, at
this commit, closed and guarded.

---

## 6. Category E — categories nobody had looked at

### 6.1 ★ OpenAPI documents four endpoints that DO NOT EXIST

`api-docs.yaml` path entries with **no corresponding `case` in any dispatch file** (diffed
mechanically: 216 yaml `action=` paths vs 275 code cases):

| yaml | Claimed | Reality |
|---|---|---|
| `api-docs.yaml:5479` `/api.php?action=song` | "Full song record" | no such action — falls to `api.php:16219` default → **400 Unknown action**. The real ones are `?page=song` (HTML fragment) / `song_detail` (JSON) |
| `api-docs.yaml:7306` `/api.php?action=writer` | "Songs attributed to a writer" | no such action → 400 (page fragment only) |
| `api-docs.yaml:7334` `/api.php?action=songbook` | "All songs in one songbook" | no such action → 400 (`songs`/`songbook_export` are the real JSON) |
| `api-docs.yaml:7562` `/api.php?action=setlist` | "List the caller's setlists" | no such action → 400 (`user_setlists` is real) |

Every Swagger try-it-out click on these fails; an external API consumer coding against the spec
ships a broken integration. (Legitimate near-misses that are NOT findings: `health` is real but
handled pre-switch; `songs_json` is correctly documented as removed at yaml:1306.)

### 6.2 Dead settings chains (the #1668 shape, three more instances)

All seeded in `schema.sql` (`tblAppSettings`), all emitted to every client via `app_status`
(`api.php:5739` `$publicKeys`), all **enforced/rendered by nothing** in web, Apple or Android
(full-corpus search):

| Key (schema.sql line) | Seeded promise | Reality |
|---|---|---|
| `motd` (1712) | "Message of the day shown on home page" | emitted (`api.php:5769`); **no client reads it, no admin UI sets it**. The described feature does not exist |
| `captcha_provider` (1715) | 8 named providers | emitted (`api.php:5771`); **zero captcha code anywhere** — server verifies nothing, clients render nothing |
| `ads_enabled` (1718) | "Enable advertisement display" | emitted (`api.php:5772`); no ads code anywhere |

`configuration.php:10` itself frames these as "can each become an" admin control — aspirational.
Contrast the healthy neighbours (verified live so this table isn't over-claiming):
`registration_mode` enforced at `api.php:2230`, `email_service` at 3463,
`song_requests_enabled`/`max_song_requests_per_day` read at 10424/10438 (#1641's fix) — though
those two still have **no admin UI** (schema-seed + raw SQL only; the pre-#1481
`content_gating_enabled` lesson, already ⚠️-flagged in the handoff). `ccli_validation_enabled`
(schema.sql:1722) is already self-describing as NOT WIRED and filed as #1668.

### 6.3 Decorative entitlements — labelled and mapped, enforced by nothing

Diffing `$ENTITLEMENT_LABELS` + the role maps (`manage/entitlements.php:77-114`,
`includes/entitlements.php:37-53`, mirrored `js/modules/entitlements.js`) against every
`userHasEntitlement(…)` call site (including **indirect** checks through arrays — the
`manage/index.php:27-40` loop is why `view_admin_dashboard` is NOT on this list):

**Never checked anywhere:** `delete_songs`, `bulk_edit_songs`, `edit_users`,
`change_user_roles`, `assign_global_admin`, `delete_users`, `run_db_migrate`, `run_db_backup`,
`run_db_restore`, `access_alpha`, `access_beta` (11).

What actually gates those operations instead: raw **roles** — v1/v2 editor APIs gate on
`editor` (editor/api.php:47, api2.php:121) with delete escalated to `admin` **role**
(v1:3541, v2:125), bulk ops on plain `editor` (so the map's claim that `bulk_edit_songs` is
admin-only is simply not what the code does); `users.php` gates the whole page on `view_users`
(users.php:29) with no per-action checks (except `assign_user_tier`, line 170);
`setup-database.php` checks `run_db_install` (line 57) but raw `global_admin` role for the rest
(lines 191, 459). Today the default maps coincide with the roles, so there is no live privilege
hole — but `/manage/entitlements` **edits these maps at runtime** (`entitlements_overrides`),
and an operator narrowing `delete_songs` there would change **nothing**, silently. Same species
as #1587's nav-vs-page rule; `tests/php/test-admin-gate-parity.php` covers page gates but not
this "label exists, no enforcement site" direction. (Reverse direction — checked but unlabelled —
is 10 keys, visible in `/manage/entitlements` gaps: `manage_api_keys`, `manage_duplicate_songs`,
`manage_external_link_types`, `manage_feature_gating`, `manage_own_organisation`,
`manage_user_licences`, `manage_works`, `request_api_keys`, `view_ccli_report`,
`view_licence_audit` — UNVERIFIED whether the labels page derives its list elsewhere; lower
priority.)

### 6.4 ★ `includes/MusicXmlImporter.php` — a tested feature no user can reach

A complete MusicXML importer class whose **only** reference in the entire repo is its own test
(`tests/php/test-musicxml-parser.php`). No import flow mentions MusicXML
(`song_importers.php`, `import_file`/`import_zip` sniffers, both editors — searched); its
sibling `PptxImporter.php` shows what wired-in looks like (`song_importers.php:3803`). A **green
test suite is actively vouching for a feature that is not shipped** — the most deceptive
look-shipped state in this whole report, because the usual evidence of life (tests pass) is
present. (The editor UI's "MusicXML" copy at `manage/editor/index.php:1367` is the *media
upload* kind — a different, live feature.)

### 6.5 `includes/pdf_export.php` — dead feature file

"Setlist PDF Export (#302)". `generateSetlistPdf()` / `_buildSimplePdf()` / `_pdfEscape()`
(pdf_export.php:37/87/195) have **zero callers**; the file is never `require`d. Setlist printing
went the browser-print route instead (`js/modules/print.js`). Delete candidate.

### 6.6 Clean sweeps in this category (stated so the absence is a result, not a gap)

- **JS modules**: every file under `js/modules/` + `js/utils/` has a real importer (checked
  import-statement/`<script src>` context, not bare filename mentions). Note
  `songbook-language-filter.js` still exists and is legitimately imported
  (`router.js:795`) — CLAUDE.md rule #31's "now-deleted" refers to the fetch *patch* inside it,
  which is indeed gone; the phrasing is accurate on close read.
- **Service-worker messages**: all 9 inbound `event.data.type` cases have page-side senders,
  and both SW→page messages (`QUEUE_DRAIN`, `CACHE_SIZES`) have listeners.
- **Admin pages vs nav**: every `manage/*.php` is either in `admin-links.php` or deliberately
  contextual (`credit-people-bulk-promote` ← credit-people.php; `gating-noop-verify` ←
  configuration.php; `song-link-suggestions` = the documented #1215 302 stub).
- **Page fragments / partials / includes**: every `includes/pages/*.php` is routed; every
  `includes/partials/*.php` is included; `includes/*.php` orphans are exactly the two in
  §6.4/§6.5.
- **Event names**: already mechanically guarded (`tests/test-event-names.js`, #1581) — not
  re-audited here.

---

## 7. Doc-vs-code disagreements (each is itself a finding)

1. **`bulk_tag_detach`**: CHANGELOG.md:6, PROJECT_STATUS.md:78, ProjectBrief.md:49,
   api-docs.yaml:45-46 and the 07-28 handoff all present a restored *control*; the code has an
   endpoint with no client, no button, no caller (§2.7).
2. **api-docs.yaml documents 4 nonexistent endpoints** (§6.1) — the spec over-promises.
3. **api-docs.yaml documents the dead v1 translations trio** as live paths (§2.8).
4. **`tblAppSettings` seed descriptions promise 3 features that don't exist** (motd on the home
   page, captcha, ads — §6.2); schema.sql is the canonical fresh-install source (rule #19), so
   fresh installs are seeded with false claims. (`ccli_validation_enabled`'s text is already
   fixed; these three are the same disease.)
5. **Entitlement labels/maps describe 11 controls that no code enforces** (§6.3) — and the
   labels page presents them as real to the operator.
6. **`places-api.php`'s own header documents `action=get` consumers that don't exist** (§2.9).
7. **`schema-audit.php:36-37` says shared includes exist "so the API endpoints can call them"**
   — the endpoints (`admin_schema_audit`, `admin_migrations_status`) exist and nothing calls
   them (§2.1). Minor, but it's the pattern in miniature.

Cross-reference to already-filed issues (numbers taken from `.claude/` docs **only as
pointers**; every claim above was re-verified from code): #1669 (§3.2 row 1), #1670 (§2.2/§3.2
row 2), #1671 (11 of §2.3), #1668 (`ccli_validation_enabled`, adjacent to §6.2), #1435
(push pair), #1511 (§2.4 dormant batches), #1601/#1629/#1678 (v1-retirement consumers — already
guarded). **Not covered by any issue found in the repo docs:** §2.7 `bulk_tag_detach`-unwired,
§2.3's `custom_tags` + `setlist_schedule(+_save)`, §2.5's six, §2.8 trio, §2.9 places `get`,
§3.1's legacy-scaffold four, §3.2's four half-wired `include=` tables + `tblContentLicences`,
§3.3 write-only pair, §6.1 phantom four, §6.2 three chains, §6.3 decorative entitlements,
§6.4 MusicXmlImporter, §6.5 pdf_export, and the §2.1/53 as a policy question.

---

## 8. CI guard design — `tests/php/test-orphan-inventory.php` (+ node sibling)

**Goal:** the *inventory* above must be regenerable by CI, and any NEW entry must fail the
build. Deliberate orphans live in a count-exact, reason-annotated allowlist that self-cleans.

### 8.1 What it asserts (four independent checks, one derived data pass)

Single source of truth: a `derive()` step that rebuilds, from the tree on every run,
- **actions**: tokenizer-extracted dispatch cases (the §2 method — `token_get_all`,
  brace-depth-aware; if-chains via one regex; picks up ANY file matching
  `$_GET/POST/REQUEST['action']`, so a new dispatch file is auto-covered, never hand-listed);
- **callers**: full-corpus literal scan with ALL the §0.2 shapes (quoted string, `action=X` URL,
  unquoted JS key, `.value=` assignment, Swift `action: "X"`) **plus** self-emitted
  `action=X` URLs inside dispatch files (the §2.6 chain rule) — implemented in plain PHP
  file-walk, **never `rg`** (§0.1);
- **tables**: schema.sql `CREATE TABLE` list vs whole-text reader/writer regexes, with a
  dynamic-writer allow-map (table ⇒ the allow-list file that writes it, e.g.
  `tbl*ExternalLinks ⇒ external_link_helpers.php` — asserted to still contain the table name, so
  the mapping cannot rot);
- **docs**: yaml `action=` paths.

Checks:
1. **No new caller-less action**: every dispatched action has ≥1 caller bucket OR an allowlist
   entry `['action' => 'admin_users', 'reason' => 'api-parity #719', 'until' => null]`.
2. **No phantom docs**: every yaml `action=` path has a dispatch case (allowlist for
   deliberate tombstones — currently only the `songs_json` comment, which is not a path).
3. **No new reader-without-writer table**: every table with an APP reader has an APP or
   MIGRATION-backfill writer OR an allowlist entry (dormant rule-#20 batches carry
   `reason: 'one-pass dormant (#1066 pattern)'`).
4. **No new labelled-but-unenforced entitlement**: every key in `$ENTITLEMENT_LABELS` appears in
   ≥1 `userHasEntitlement` call **or** in an array literal that feeds one (the
   `manage/index.php:27` indirection is parsed, not special-cased) OR allowlisted.

### 8.2 The allowlist — where it lives, how it stays honest

One file, `tests/fixtures/orphan-allowlist.php`, returning arrays keyed by check. Rules copied
from the proven `test-fragment-inline-scripts.php` pattern:
- **Count-exact and name-exact**: the test fails if an allowlisted name stops being an orphan
  ("stale allowlist entry — delete it") exactly as it fails on a new un-allowlisted orphan. That
  is the self-cleaning property; an allowlist that only suppresses can only grow.
- **Every entry carries a `reason` string containing either an issue number or the word
  `deliberate` + a rule reference** — asserted by the test itself, so an entry cannot be added
  without saying why.
- Initial population = exactly the findings of this report (93 actions, 34+7+2 tables, 11
  entitlements), so the guard lands green on day one **with the debt explicit and enumerated**,
  and every subsequent PR that adds an endpoint/table/label without wiring it goes red.

### 8.3 Mutation tests (rule #34 — prove it can fail, in CI, forever)

The test ships with a self-check mode that mutates **in memory** (never touches the tree):
inject a fake `case 'zz_orphan_probe':` into the parsed action set → expect check 1 red; drop a
known caller (`user-auth.js`'s `custom_tags_sync`) from the corpus → expect the sync twin to go
red; add a fake yaml path → check 2 red; remove `lyrics_ingest.php` from the corpus → the
`tblLyricLines` writer disappears → check 3 red. If any mutation stays green the suite fails.
This is the direct answer to the two wrong-but-green scanners this repo has already shipped
(test-editor-deep-links' two truncation bugs; test-vendor-sri's single-library blindness).

### 8.4 What it CANNOT catch (and therefore must not claim)

- **Out-of-repo consumers**: API-key partners, curl scripts, the not-yet-written native admin
  app. The guard reports "no in-repo caller", never "unused" — runtime evidence
  (`tblApiKeyUsage`, `admin_activity_log`) is the only way to close that gap, and needs MySQL.
- **Mounted-but-unreachable UI**: a caller in an imported module whose triggering button is
  `display:none` or whose feature flag is off. Static xref sees a caller. (The §4 Swagger
  console is the worked example: reachable ≠ product surface.)
- **Semantic emptiness**: `include=arrangements` *works*; the data is just forever empty. The
  table check catches the writer-less table, but only because reads/writes are both static SQL.
- **Values flowing through variables** it doesn't model: an action name assembled from user
  input or DB content (none found today; if one appears, the guard under-reports it — the
  corpus scan of dynamic `action=' + var` sites should stay a manual audit note).
- **Anything behind `rg`'s hidden-dir/multi-root behaviour** if a future editor "simplifies" the
  file-walk back to ripgrep — hence the walk is plain PHP and the §0.1 incident is documented in
  the test's header.

---

## 9. Scripts (for regeneration)

All under the session scratchpad; deterministic, no network, no DB:
`extract-cases.php` (tokenizer), `xref2.py` (action↔caller corpus xref → `xref2.jsonl`),
`table-xref2.py` (table readers/writers → `tables2.jsonl`), `deeplinks2.py` (deep-link scan),
`manage-actions.py` (self-posting page audit). Re-running them against a future commit and
diffing the JSONL outputs is the interim regeneration path until §8's guard lands.

## 10. UNVERIFIED / lower-confidence items

- §2.1 intent ("deliberate API parity") is inferred from coherence + yaml coverage + #719's
  existence; only the owner can confirm intent. The **absence of in-repo callers** is verified;
  the **absence of external callers** is unverifiable statically (no MySQL access to
  `tblApiKeyUsage`).
- §6.3's reverse direction (checked-but-unlabelled entitlements) — not fully verified whether
  `/manage/entitlements` renders overrides for unlabelled keys; needs a page-logic read.
- `tblContentLicences` (§3.2) — `.fulldata/ihymns-full.sql` seeds it; whether production was
  installed from fulldata (making the reader non-empty there) is a runtime fact.
- The rg multi-root anomaly (§0.1a) is characterised by reproduction, not root-caused.
