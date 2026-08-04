# Remediation plan — orphan inventory + four decided items (2026-07-30)

Branch: `claude/wave3-fixes` @ `aa6f2dac`. Input: `.claude/orphan-inventory-2026-07-30.md`
(base `9aabb42c`) + owner decisions #1661 / #1668 / #1671 / #1679. This plan is written to be
executed verbatim by Sonnet-tier agents; every design decision they would otherwise have to
re-derive is made here, with `file:line` evidence. Verification was re-done from code, not from
the inventory — three corrections to the inputs are recorded in §0.

---

## 0. Corrections to the inputs (verified this session — do NOT re-propagate the errors)

1. **Two findings are ALREADY FIXED on this branch since the inventory was written.**
   `8c990266` wired `bulk_tag_detach` to a real "Remove tag…" button + `bulkTagDetach()` client
   method (inventory §2.7 — closed). `06169c7c` removed the four phantom OpenAPI paths AND landed
   `tests/php/test-openapi-actions-exist.php`, a tokeniser-based, mutation-tested guard for the
   phantom-docs class (inventory §6.1 + §8.1-check-2 — closed). Do not re-schedule either;
   Batch 0 verifies them. Consequence: the five docs that claimed `bulk_tag_detach` shipped
   (CHANGELOG.md:6 etc.) are now TRUE — no doc edit needed there.

2. **Inventory §6.3 is wrong about `access_alpha` / `access_beta`.** They ARE checked — at
   `includes/channel_gate.php:107` (`userHasEntitlement($entitlement, $role)`, with the name
   assigned via a ternary at :92 that a literal-string scan cannot see). The check sits behind a
   deliberate, documented early `return;` at channel_gate.php:83 ("TEMPORARILY DISABLED").
   These two are **dormant-by-design, not decorative** — they go on the guard's allowlist, not the
   fix list. The decorative-entitlement count drops from 11 to **9**. (Lesson for the guard: an
   entitlement key must count as "checked" wherever it appears as a quoted string in enforcement
   code, not only as a literal argument to `userHasEntitlement(`.)

3. **The #1668 situation is WORSE than the ask states — there are THREE org-licence stores and
   the resolver reads the wrong two.** Verified:
   - `getUserEffectiveLicences()` (includes/licences.php:60) reads `tblContentLicences`
     (:117, :162 — a table with **no writer anywhere**, inventory §3.2) plus the LEGACY
     `tblOrganisations.LicenceType/LicenceNumber/LicenceExpiresAt` columns (:187-199).
   - The **live org-licence UI** — `/manage/my-organisations.php:217/261/291` — and the
     `org_admin_licence_add/_change/_remove` API trio (api.php:13567/13652/13720) and the admin
     org-update path (api.php:12977-12996) ALL write **`tblOrganisationLicences`**
     (schema.sql:1039), which `getUserEffectiveLicences()` **never reads**.
   - `serviceMode_presenceCcliNumber()` (includes/service_mode.php:824-854) reads ONLY the legacy
     `tblOrganisations` columns.
   So an org that entered its CCLI licence through the shipped UI gets NO effective-licence
   contribution AND no Service-Mode unlock. The unified resolver (Batch 2) must consolidate all
   three stores, not just fix the personal-verified split. (`migrate-organisation-licences.php:172`
   backfills tblOrganisationLicences FROM the legacy columns — confirming it is the intended
   canonical org store.)

   Minor factual note: the ask says `tblUsers.SettingsJson`; the actual column is
   **`tblUsers.Settings`** (api.php:3147/3179).

   All other inventory claims a plan decision hinges on were spot-verified and held:
   the personal-only CCLI checks (content_gating.php:92-111, api.php:9422-9428), the unverified
   personal branch (licences.php:137-152 — no `CcliVerified`), the 50-cap
   (api.php:2954 `userSyncCap($body['setlists'], 50)`), `user_access`'s doomed
   `tblUserGroupMembers` UNION (api.php:5685 vs schema.sql:1118 with no migration), the
   `ON UPDATE CASCADE` FK fan-out on `tblSongs(SongId)` (25+ constraints in schema.sql),
   `tblSongs.PublicId` (schema.sql:165), the api2-contract test's deliberate server-only
   exclusion (tests/php/test-editor-api2-contract.php:152-156), zero `push`/`notificationclick`
   handlers and zero VAPID code anywhere, and the dead settings seeds (schema.sql:1712-1722).

---

## 1. Executive summary

**11 batches** (0–10). Batch 0 is verification + issue filing; Batch 1 is the permanent CI guard
and MUST land first; Batch 2 (CCLI) is the security-critical fix; Batches 3–5 are independent and
parallelisable; 6–10 are feature build-out in descending urgency. Total: **41 work items**
(9 S, 23 M, 4 L, 5 verify/file-only). At typical agent throughput this is roughly 4–6 working
sessions of implementation plus one live-verification pass per batch that touches the DB.

The philosophy, per the owner's "we don't want to be back here again": the **guard lands before
the fixes**, so every subsequent batch is executed under the protection it is supposed to prove;
every deliberate orphan ends up in a count-exact allowlist with a written reason; and everything
that "looks shipped" either becomes genuinely shipped (wired + UI) or is deleted (endpoint + docs
+ schema together, never one without the others).

---

## 2. Work-item table

Effort: S ≈ ≤half a session, M ≈ one session, L ≈ multi-session. Risk = what goes wrong if the
item is done badly (the trap to brief the implementing agent on).

| ID | Item | Files (primary) | Effort | Risk if done wrong |
|---|---|---|---|---|
| **B0.1** | Verify `8c990266` + `06169c7c` (run both new guards; click-path review of the Remove button wiring) | tests/php/test-openapi-actions-exist.php, manage/editor/editor2.php, v2/api-client.js | S | Trusting a green first run (rule #34) |
| **B0.2** | File the issue set: 1 epic + child issues for every batch item not already covered (#1669/#1670/#1671/#1435 exist; §2.3 new pair, §2.5 six, §2.8 trio, §3.1 four, §3.3 pair, §6.2 chains, §6.3, §6.4, §6.5, org-licence split are NOT filed) | GitHub | S | Findings recorded only in this file are lost (CLAUDE.md standing tasks §2) |
| **G1** | Orphan CI guard `tests/php/test-orphan-inventory.php` + `tests/php/fixtures/orphan-allowlist.php` (design §4.1) | new files + shared `tests/php/lib/dispatch_parser.php` extracted from test-openapi-actions-exist.php | L | A wrong-but-green scanner is worse than none (§0 of the inventory; rule #34) |
| **G2** | Derived PHP-test runner: replace the 67 hand-listed `php tests/php/test-*.php` lines in `.github/workflows/test.yml` with one glob runner (mirror of `tools/run-node-tests.js:48`) | .github/workflows/test.yml, new tools/run-php-tests.php | M | A new guard added but not CI-registered = rule #35's npm-vs-CI drift, again |
| **G3** | Make the api2-contract exclusion structural: orphan guard auto-discovers api2.php as a dispatch surface, so its server-only actions need allowlist entries with reasons | orphan-allowlist.php | S | Re-implementing the case parser a third time instead of sharing it |
| **C1** | `userHasValidCcli(?int): bool` — the ONE resolver — + tighten licences.php branch (b) to require `CcliVerified = 1` | includes/licences.php:137-152 | M | Tightening is an access change — see §4.3 behaviour note |
| **C2** | New branch (f): `getUserEffectiveLicences()` reads `tblOrganisationLicences` (IsActive=1, unexpired) for the whole org chain | includes/licences.php | M | Missing the existence-gate → STRICT throw on an exotic install (#1228 class); missing the inherited-org id set |
| **C3** | Repoint the 2 duplicate personal-CCLI checks onto C1: `contentGating_userHasCcli()` body delegates; `tier_check` inline query deleted | includes/content_gating.php:92-111, api.php:9412-9431 | S | Leaving one copy behind recreates the fork; add the one-copy scan (§4.3) |
| **C4** | `serviceMode_presenceCcliNumber()` also accepts a live `tblOrganisationLicences` ccli row | includes/service_mode.php:824-854 | M | Breaking the Channel scoping (rule #26) while editing the SQL |
| **C5** | Remove the `ccli_validation_enabled` seed (schema.sql:1722) + cleanup migration deleting the row | appWeb/.sql/, migration-registry.php | S | Registry entry + probe (row absent) or the pending counter sticks (rule #19) |
| **C6** | Resolver tests: pure-logic where possible + tree-derived "no second personal-CCLI query exists" scan, mutation-tested | tests/php/ (new) | M | A guard that can't fail (rule #34) |
| **R1** | `songRelocate()` — the ONE songbook-move re-key helper (design §4.4) | new includes/song_relocate.php | M | Soft references + both write funnels; ordering inside the txn |
| **R2** | Wire `editorSaveSongCore()`: detect `$prevRow['SongbookAbbr'] !== $songbookAbbr` → relocate → reuse the existing `assignedId`/`previousId` response contract (#1380) | manage/editor/save_song_core.php:352-457 | M | The upsert must run with the NEW id; both books' SongCount refresh (#791, :950-960) |
| **R3** | Wire api2 `metadata_field_update` `field='songbook'` (api2.php:864-872 via ED2_META_FIELDS:177) → relocate + client handles the id rename; EXCLUDE `songbook` from `ed2_applySongSnapshot()`'s scalar restore (api2.php:543) | manage/editor/api2.php, v2 client + shell | M | The second live write path is the whole reason #1679 exists; snapshot-restore is the sneaky third |
| **R4** | `song_detail` follows `songRedirectResolve()` before 404-ing (emits `redirectedFrom`); today only the HTML fragment does (includes/pages/song.php:44) | api.php song_detail case | M | Native apps + stale SongsJson ids depend on this; check ETag/cacheability of the JSON path |
| **R5** | Tests: move-chain follow (extend tests/php/test-song-redirects.php), structural assert both funnels call `songRelocate` (mutation-tested), grep-derived consumer sweep of `normalizeSongId`/OG-image (rule #33) | tests/ | M | An unswept consumer = a silently broken deep link |
| **S1** | One-pass schema batch: `tblUserSetlistTombstones` + `tblUserSetlists.ExpiresAt DATETIME NULL` (design §4.2) — ONE migration + registry entry + schema.sql byte-identical | appWeb/.sql/, schema.sql, migration-registry.php | M | Rule #19/#20 discipline; DATETIME not TIMESTAMP for the TTL |
| **S2** | Sync protocol v2: explicit `deleted:[]`, tombstone write + anti-resurrection, cap REMOVED, absence-based deletion retired for protocol-2 clients, `tombstones:[]` in the response | api.php:2930-3110, includes/user_sync.php | L | The #1649 data-loss class; `tests/php/test-user-sync-guard.php` locks current behaviour and must be updated in the SAME commit |
| **S3** | Expiry: lazy server-side conversion expired→tombstone(`Reason='expired'`) at read time; `expiresAt` round-trips in the payload | api.php user_setlists + sync | M | Server clock only; column-existence-gated (migrations are web-run, 3 docroots share one MySQL) |
| **S4** | Web client: delete → explicit `deleted`; expiry picker + badge in setlist UI; prune local on `tombstones` | js/modules/setlist.js | M | The `_syncReady` arming order (project-rules §15.2) must not regress |
| **S5** | Native apps: verify tolerance (they never send `deleted` → legacy path with #1649 guards; extra response keys ignored by Codable); file Apple + Android adoption issues | appApple/…/CachedSetlist.swift etc. — read-only | S | A strict decoder crashing on a new response field — verify, don't assume |
| **S6** | Tests: tombstone/anti-resurrection/expiry unit coverage on the pure helpers | tests/php/ | M | — |
| **E1** | Wire the **9** decorative entitlements to real checks with defaults matching today's role gates (map: §4.6) | editor api.php:3541, api2.php:125, manage/users.php, manage/setup-database.php:191/459 | L | ANY default that differs from the current raw-role gate is a live privilege change; `bulk_edit_songs` map must first be ALIGNED to reality (editor+) |
| **E2** | Label the 10 checked-but-unlabelled keys in `$ENTITLEMENT_LABELS` + groups | manage/entitlements.php:77-129 | S | — |
| **E3** | PHP↔JS entitlement-map parity guard (derived from both files, mutation-tested) | tests/ (new), includes/entitlements.php, js/modules/entitlements.js | M | rule #35: the two maps have nothing holding them together today |
| **X1** | Delete v1 editor translations trio (`get_translations` editor/api.php:369, `add_translation` :405, `remove_translation` :448) + their 3 yaml paths | manage/editor/api.php, api-docs.yaml | S | test-openapi-actions-exist keeps yaml honest; check test-v1-consumer-deorphan |
| **X2** | Delete `includes/pdf_export.php` (zero callers, never required) | one file | S | — |
| **X3** | Delete `places-api.php?action=get` branch (:197) + fix the header claim (:27-29) | manage/places-api.php | S | — |
| **X4** | Legacy scaffold: remove `tblSessions`(:750) `tblUserPurchases`(:944) `tblUserPermissions`(:1138) `tblMigrations`(:1500) from schema.sql + extend the existing `drop-legacy` manual card | schema.sql, setup-database drop-legacy, migration-registry | M | Destructive: manual + confirm-gated; probe = tables absent; owner veto window (§6.D2) |
| **X5** | #1670: `user_access` drops the `tblUserGroupMembers` UNION arm (api.php:5680-5686); remove the table from schema.sql (+ drop-legacy) | api.php, schema.sql | S | The endpoint STAYS (dormant family) — fixing the 500 ≠ giving it a caller; keep its allowlist entry |
| **X6** | Seed-description hygiene: rewrite `captcha_*` (schema.sql:1715-1717) + `ads_*` (:1718-1720) descriptions to the RESERVED/NOT-WIRED pattern of :1722; keep the `app_status` emit (api.php:5771-5772) untouched (native decoders may require the fields) | schema.sql + row-update migration | S | Removing an emitted field is a native-contract change — don't |
| **X7** | §2.5 six undocumented-dormant public actions (`song_by_identifier`, `person_by_identifier`, `songs_list`, `my_organisations`, `songs_by_tag`, `song_revisions`) → add explicit dormancy notes in yaml (the §2.4 pattern, yaml:3564) + allowlist entries | api-docs.yaml, orphan-allowlist.php | S | Documented-as-live-called-by-nothing is inventory finding §7; make the docs tell the truth |
| **X8** | #1669: give `tblSongAlternativeTitles` its writer — alt-titles chip-list in the v2 editor + api2 endpoints (SongData readers at SongData.php:955/966/2946 already consume) | manage/editor/ (v2), api2.php | M | Follow the external-links chip-list pattern; new api2 actions need callers or they're day-one orphans |
| **F1** | Devices screen (server complete at api.php:4292-4401 incl. CSRF + rate limit) — settings-page UI listing + sign-out | js/modules/settings.js + fragment | M | `device_signout` needs `csrf_token`/`X-Requested-With` — client must send what the server checks (#1677 class) |
| **F2** | "My requests" view (server complete at api.php:5201-5226) — surface on the request page for signed-in users | includes/pages/request-a-song.php + its ES module | M | Rule #30: NO inline scripts in the fragment — extend the existing module |
| **F3** | Musical keys #298: v2 editor key/tempo/time-sig fields (writes `song_key_save`, api.php:7474) + public song-page display + transpose default from `song_key` | v2 editor, song page module | M | `song_key_save` gates on raw roles — E1 touches the same line; sequence after E1 or coordinate |
| **F4** | Setlist templates #301 (server complete at api.php:7672-7799 incl. IDOR guard): create-from-template + save-as-template UI | js/modules/setlist.js | M | — |
| **F5** | user_preferences re-think (design §4.5): namespaced `user_settings` merge; DELETE `user_preferences`/`user_preferences_sync` (api.php:8329/8359) + yaml; manual drop card for `tblUserPreferences` (schema.sql:1649) | api.php, js/modules/settings.js, migrations | M | Whole-blob replace vs namespace merge back-compat; drop is manual+confirm |
| **F6** | Web Push #1435 (design §4.5): VAPID keys via the existing secret-crypto machinery, `includes/web_push.php` sender, SW `push`+`notificationclick` handlers, settings opt-in, admin broadcast page under the existing `manage_notifications` entitlement | SW, includes/, manage/ (new page), settings | L | ES256/JWT crypto is fiddly; the SW is the one legit native-fetch context (rule #31); notification kinds = VARCHAR/app-map vocabulary (rule #20) |
| **F7** | `custom_tags` GET (api.php:4943): keep as native/API-parity read → allowlist entry + yaml dormancy note | orphan-allowlist.php, yaml | S | — |
| **F8** | Retire the superseded `setlist_schedule`/`setlist_schedule_save` pair (api.php:7529/7587 — same `tblSetlistSchedule` the live set/clear/current/upcoming family uses, api.php:9675-9685) + yaml | api.php, api-docs.yaml | S | Confirm the new family covers the org-scoped read the old GET offered before deleting |
| **M1** | Wire `MusicXmlImporter` (includes/MusicXmlImporter.php) into the import sniffers alongside `PptxImporter` (song_importers.php:3803 pattern) — both editors' import flows | includes/song_importers.php + import UIs | M | The tested-but-unreachable state is the inventory's "most deceptive"; wiring must include a UI path, or it stays an orphan with extra steps |
| **M2** | Build `motd` (seeded schema.sql:1712, emitted api.php:5769): admin field in /manage/configuration + client banner module reading `app_status.motd` | manage/configuration.php, js module | M | Home is a shared-cache fragment — the banner is CLIENT-rendered from app_status, never server-personalised (rule #6/#30) |
| **M3** | Admin UI for `song_requests_enabled` + `max_song_requests_per_day` (enforced api.php:10424/10438, currently raw-SQL-only) | manage/configuration.php | S | — |

---

## 3. Batching, sequencing, parallelism

```
Batch 0  B0.1 B0.2                 — verify + file issues.        SEQUENTIAL FIRST (half a session)
Batch 1  G1 G2 G3                  — THE GUARD.                   MUST land before Batches 2–10
Batch 2  C1–C6                     — CCLI resolver.               After 1. deep-architect lane (security)
Batch 3  X1–X7, F7, F8             — deletions + doc-truth.       After 1. quick-edits lane. PARALLEL-OK
Batch 4  R1–R5                     — #1679 re-key.                After 1. PARALLEL-OK with 3, 5
Batch 5  S1–S6                     — #1661 setlists.              After 1. PARALLEL-OK with 3, 4
Batch 6  E1–E3                     — entitlement truth-up.        After 1 (guard check 4 protects it). deep-architect lane
Batch 7  F1 F2 F5                  — #1671 quick wins.            After 1. PARALLEL-OK
Batch 8  F3 F4 X8                  — editor-adjacent features.    After 6 (F3 touches a line E1 edits)
Batch 9  M1 M2 M3                  — misc shipped-but-dead.       Anytime after 1
Batch 10 F6                        — Web Push.                    Last (largest, least urgent)
```

**Why this order.** Batch 1 first is the whole strategy: every later batch adds/removes actions,
tables, labels and yaml paths, and each of those edits must be exercised against the guard
(adding an endpoint without a caller in Batch 8 should go red immediately, not in the next
audit). Batch 2 second because it is the only live-correctness/security item and because C1–C4
are the single place gating semantics change — nothing else may touch `hasCcli` paths until it
lands. Batches 3/4/5 are file-disjoint (3 = deletions across api.php cases + yaml + schema; 4 =
editor save paths + song_redirects; 5 = setlist sync paths) and can run as parallel agents; the
only shared file is `api.php`, so if run truly in parallel, land as separate commits and rebase —
or simply run them serially within one session, which is cheaper than merge-conflict management.
Batch 6 is sequenced alone because it edits authorisation on pages other batches touch (editor
APIs, setup-database). Batch 8 waits for 6 only because F3 (musical keys) writes through
`song_key_save` whose gate E1 rewrites (api.php:7481).

**One PR** per CLAUDE.md, commits batch-by-batch, each batch's commit individually revertable.
Every schema-touching batch (S1, X4, X5, C5, F5) needs its migration card RUN on each env via
`/manage/setup-database` after deploy — migrations are web-run, never auto-applied.

---

## 4. Designs

### 4.1 The permanent orphan CI guard (G1–G3) — the highest-leverage item

**Files.**
- `tests/php/test-orphan-inventory.php` — the guard.
- `tests/php/fixtures/orphan-allowlist.php` — returns `['actions' => [...], 'tables_reader_no_writer' => [...], 'tables_writer_no_reader' => [...], 'entitlements' => [...]]`.
- `tests/php/lib/dispatch_parser.php` — the tokenising switch-walker **extracted from
  `test-openapi-actions-exist.php`** (which already solved the `T_CURLY_OPEN` brace-depth trap and
  carries three positive controls for the page-vs-action distinction). One parser, two consumers
  — do not write a second one.

**The derive() pass (single source of truth, rebuilt from the tree every run).**
1. *Dispatch surfaces*: every PHP file under `appWeb/public_html` matching
   `$_GET|$_POST|$_REQUEST['action']` is a surface — auto-discovered, never hand-listed, so a new
   dispatch file is covered the day it appears. Cases extracted via the shared parser
   (`token_get_all`, brace-depth-aware incl. `T_CURLY_OPEN`/`T_DOLLAR_OPEN_CURLY_BRACES`);
   if-chain dispatches (`$action === '…'`) via one regex pass. This is how G3 subsumes the
   api2-contract test's deliberate exclusion (test-editor-api2-contract.php:152-156): that test
   keeps its client→server direction untouched; THIS guard covers the server→caller direction for
   api2.php automatically because api2.php matches the surface pattern — no coordination between
   the two files is required, which is the rule-#35-compliant shape.
2. *Corpus*: a plain-PHP `RecursiveDirectoryIterator` walk of the repo root with an explicit
   skip-list `{.git, node_modules, vendor}` ONLY — **dot-directories are included**
   (`appWeb/.sql/` was invisible to `rg` and cost the inventory a finding; the incident is
   §0.1 of the inventory and MUST be restated in this file's header). **Never `rg`, never a
   shell-out** — the multi-root drop and hidden-dir default are both documented reproductions.
3. *Caller shapes* (a hit in any of these, in a first-party bucket, is a caller):
   `'name'` / `"name"` quoted literal (subsumes JS object values `{ action: 'name' }`, helper
   args `postJson('name')`, Swift `Endpoint(action: "name")`, `.value = 'name'` assignments);
   `[?&]action=name` inside any string; **plus the §2.6 chain rule** — an `action=name` URL
   emitted inside a DISPATCH file counts as a caller-by-proxy (`skipped_csv_url`/`poll_url`,
   editor/api.php:2845 / api2.php:2153, are reachable through response data and must never be
   flagged).
4. *Buckets by path prefix* (derived map, each asserted non-empty so a path rename can't silently
   empty a bucket): first-party = `appWeb/public_html/js`, `appWeb/public_html/manage`,
   `appWeb/public_html/includes`, `appWeb/public_html/*.php`, `appApple/`, `appAndroid/`, the
   service worker. NOT callers: the dispatch file itself, `api-docs.yaml`, `*.md`, `help/`,
   `wiki/`, `tests/`, `tools/`.
5. *Tables*: `CREATE TABLE` list from `appWeb/.sql/schema.sql`; whole-text multi-line
   reader (`FROM|JOIN`) / writer (`INSERT|UPDATE|DELETE|REPLACE|TRUNCATE`) scans zoned
   APP / MIGRATION / SCHEMA-ADMIN / TEST; a **dynamic-writer allow-map**
   (`tblWorkExternalLinks => includes/external_link_helpers.php`, …) where the guard ALSO asserts
   the named file still contains the table literal, so the mapping cannot rot.
6. *Entitlements*: keys of `$ENTITLEMENT_LABELS` (manage/entitlements.php); a key counts as
   enforced when it appears as a quoted string in any `appWeb/public_html` `*.php`
   (comments stripped first) outside the three map files — this deliberately catches BOTH the
   `manage/index.php:27-40` array indirection AND the `channel_gate.php:92` ternary that §6.3's
   scan missed (§0.2 above).

**The four checks.**
1. Every dispatched action has ≥1 first-party caller OR an exact allowlist entry.
2. *(Already landed as `test-openapi-actions-exist.php` — the guard does NOT duplicate it.)*
3. Every table with an APP reader has an APP or MIGRATION writer OR an allowlist entry; every
   table with an APP writer has a reader OR an allowlist entry.
4. Every labelled entitlement is enforced somewhere OR allowlisted.

**The allowlist stays honest** (copied from the proven `test-fragment-inline-scripts.php`
pattern): count-exact AND name-exact — an entry whose orphan got wired FAILS the build with
"stale allowlist entry — delete it" (self-cleaning); every entry carries a `reason` string that
must contain either `#<digits>` or the word `deliberate` plus a rule reference — asserted by the
guard itself; no `until` dates that silently expire.

**Initial population** = exactly the post-remediation residue, so the guard lands green with the
debt explicit: the §2.1 admin-parity 53 (`reason: 'deliberate API-first surface #719; Swagger
console consumer; owner-reviewed 2026-07'`), the §2.2 gating 11 (`'dormant by design, rule
#28/#20'` — including `user_access` even after its X5 fix, since fixing the 500 does not give it
a caller), the §2.4 device-code + control-token 4 (`'#1511 live-dormant'`), the §2.5 six
(`'API parity; yaml dormancy note added X7'`), `custom_tags` (F7), the rule-#20 dormant tables
of §3.1, the four `include=` extras tables + `tblContentLicences` (§3.2, with `'#1066 one-pass
dormant'` / `'licence model consolidation #<new issue>'`), `tblLyricWords`+`tblLyricSyllables`
(§3.3 `'timing ingest, read path future — lyrics strategy'`), and `access_alpha`/`access_beta`
(`'enforced at channel_gate.php:107, gate deliberately disabled :83'`). Items scheduled for
deletion in Batch 3 (v1 trio, old schedule pair, places `get`, `user_preferences` pair) are NOT
allowlisted — if Batch 3 hasn't run yet, they go red, which is correct pressure; land Batch 3 in
the same PR.

**Mutation self-tests (rule #34), run unconditionally at the end of every invocation, in-memory,
never touching the tree**: inject a fake `case 'zz_orphan_probe':` into the parsed action set →
check 1 must go red; delete a known caller (`user-auth.js`'s `custom_tags_sync` string) from the
corpus copy → red; delete `includes/lyrics_ingest.php` from the corpus copy → the
`tblLyricLines` writer disappears → check 3 red; delete a known enforcement string → check 4
red; corrupt the allowlist reason format → red. Any mutation staying green fails the suite.
Additionally, positive controls pinned as assertions: `auth_verify_email` has exactly its known
caller; `tblSongLinks` is not flagged; `bulk_import_skipped_csv` is caller-by-proxy.

**What it cannot catch — stated in its header, verbatim from inventory §8.4**: out-of-repo
consumers (API-key partners — runtime `tblApiKeyUsage` evidence is the only closure, needs
MySQL); mounted-but-unreachable UI; semantic emptiness (an `include=` that works but is forever
empty); action names assembled dynamically (none exist today; if one appears the guard
under-reports and the header says so).

**G2** exists because the guard is worthless if CI never runs it: `tools/run-php-tests.php`
glob-runs `tests/php/test-*.php` exactly as `tools/run-node-tests.js:48` does for node, and the
67 hand-listed workflow lines collapse to one call. (Two current tests intentionally need
argv/fixtures? — the implementing agent must run the globbed suite locally first and allowlist
any test that cannot run bare, with a reason, inside the runner.)

### 4.2 Setlist tombstones + expiry + no-cap (#1661, Batch 5)

**Schema (ONE migration, rule #19/#20 discipline — design final, no dribble):**
```sql
CREATE TABLE IF NOT EXISTS tblUserSetlistTombstones (
    UserId     INT UNSIGNED  NOT NULL,
    SetlistId  VARCHAR(100)  NOT NULL COMMENT 'The client-generated id of the deleted setlist',
    DeletedAt  DATETIME      NOT NULL COMMENT 'DB clock (userSyncNow frame) at deletion',
    Reason     VARCHAR(20)   NOT NULL DEFAULT 'user' COMMENT 'user | expired | admin — VARCHAR not ENUM (rule #20)',
    PRIMARY KEY (UserId, SetlistId),
    CONSTRAINT fk_SetlistTomb_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tblUserSetlists ADD COLUMN ExpiresAt DATETIME NULL DEFAULT NULL
    COMMENT 'Optional expiry (#1661); NULL = never expires. DATETIME not TIMESTAMP (rule #20 TTL convention)';
```
Multi-object migration → OR-probe (`!tableExists(tblUserSetlistTombstones) ||
!columnExists(tblUserSetlists, ExpiresAt)`); byte-identical mirror into schema.sql; ONE
registry entry.

**Protocol v2** (`user_setlists_sync`, api.php:2930):
- Request gains `deleted: [id, …]` (optional) and per-setlist `expiresAt` (optional,
  validated by the same 19-char shape as `userSyncParseSince`, api.php includes/user_sync.php:144).
  **Presence of the `deleted` key — even `[]` — marks a protocol-2 client.**
- Server, inside the existing handler, in order:
  1. **Explicit deletes**: for each sanitised id in `deleted` — upsert a tombstone
     (`DeletedAt = userSyncNow($db)`, `Reason='user'`) and DELETE the row. (Tombstone even when
     no row existed — the delete may be racing another device's create.)
  2. **Anti-resurrection**: the upsert loop (api.php:3011) skips any incoming id that has a
     tombstone. **A tombstoned id is dead forever; "re-create" mints a fresh client id.** No
     timestamp comparison — the client's `updatedAt` is client-clock and untrustworthy, and a
     deterministic rule beats a skew-sensitive one. (Undo-before-sync never reaches the server;
     a cross-device genuine recreate under the same id is the rare case we consciously spend.)
  3. **Cap removed**: drop `userSyncCap($body['setlists'], 50)` (api.php:2954) — no slice at
     all; `truncated` is hard-false. Abuse guard = the existing per-item sanitisation + a new
     generous whole-body byte cap (e.g. 4 MiB, 413 on excess) — a REJECTION, never a silent
     truncation; truncation-as-data-loss is exactly what #1649/#1661 killed. Response `cap`
     field: emit `null` (keep the key — native decoders may read it).
  4. **Absence-based deletion RETIRED for protocol-2 clients**: `userSyncDeletableIds()` gains a
     `$explicitProtocol` arg → returns `[]` when true. Legacy clients (native apps, which never
     send `deleted`) keep exactly today's #1649-guarded behaviour — the truncation guard is now
     vacuous (no cap) and the `since` watermark guard still protects the cross-device race.
  5. **Expiry (lazy, server-side only)**: at the top of `user_setlists` and before the final
     re-read of the sync — one query converts this user's rows with
     `ExpiresAt IS NOT NULL AND ExpiresAt <= NOW()` into tombstones (`Reason='expired'`) +
     deletes them. Column-existence-gated (INFORMATION_SCHEMA probe, memoised) so an un-migrated
     env degrades to "no expiry" rather than a STRICT throw. **An expired setlist thereby becomes
     a tombstone** — one deletion concept, propagated by the same mechanism. Clients render the
     upcoming expiry from `expiresAt` (display only, device clock); the server is the sole
     authority on the actual conversion.
  6. Response gains `tombstones: [{id, deletedAt, reason}]` — filtered to `> since` when a
     watermark was sent, else the full set (bounded: tombstones per user ≈ deletions, and a
     future prune of >1-year-old tombstones is a one-line addition — note in code, don't build).
- Client (`js/modules/setlist.js`): delete queues the id into a persisted pending-`deleted` list,
  cleared on 200; every sync sends `deleted` (even empty — the protocol marker); on response,
  remove local copies of tombstoned ids; expiry picker writes `expiresAt`; `syncedAt`/`since`
  flow unchanged (#1649).
- **`tests/php/test-user-sync-guard.php` locks the CURRENT semantics** — it must be extended in
  the same commit (cap removal, the `$explicitProtocol` refusal, tombstone precedence), and each
  new assertion mutation-checked.
- Collab/share/schedule surfaces (api.php:1947, 7623, 9665, 10042) all key on the
  `tblUserSetlists` row existing, so tombstoned/expired setlists degrade exactly like today's
  deletions — verify the share-link 410 path (:2438-2458) still reads sensibly.

### 4.3 The unified CCLI resolver (#1668, Batch 2)

**Signature + home:**
```php
/** includes/licences.php — ONE answer to "does this user hold a valid CCLI licence?" (#1668).
 *  Personal path REQUIRES CcliVerified=1; org path requires a LIVE (IsActive, unexpired)
 *  'ccli' licence on ANY org in the user's inheritance chain, read from BOTH org stores. */
function userHasValidCcli(?int $userId): bool
```
Implementation: `in_array('ccli', getUserEffectiveLicenceTypes($userId), true)` — it builds on
the existing org-aware, per-request-cached `getUserEffectiveLicences()` (licences.php:60), which
gets two corrections first:
1. **Branch (b) tightened** (licences.php:137-152): `SELECT CcliNumber, CcliVerified … AND
   IsActive = 1`; contribute `'ccli'` only when number non-empty AND `CcliVerified` truthy.
   (`tblUsers.CcliVerified` is schema.sql:727 and read by live code at api.php:9428 — safe to
   reference unguarded.)
2. **New branch (f)**: for the already-computed `$allOrgIds` chain, read `tblOrganisationLicences`
   rows (`IsActive = 1 AND (ExpiresAt IS NULL OR ExpiresAt > NOW())`) — the store the live UI
   actually writes (§0.3). Existence-gate the read the same way my-organisations.php:385 does
   (the table is in schema.sql:1039 + a registered migration, but rule-C cheapness applies).

**Call sites to repoint (complete list, from a tree-wide scan of
`contentGating_userHasCcli|userHasEffectiveLicence|getUserEffectiveLicenceTypes|resolveEffectiveTier`):**
- `includes/content_gating.php:92-111` — `contentGating_userHasCcli()` body becomes
  `try { return userHasValidCcli($userId); } catch (\Throwable) { return false; }` (keeps the
  deny-on-error direction). Its two internal callers (:177, :352) and
  `includes/pages/song.php:223-224` then need no edit.
- `api.php:9416-9428` (`tier_check`) — delete the inline `SELECT CcliNumber, CcliVerified`
  duplicate; `$hasCcli = userHasValidCcli((int)$authUser['Id']);`.
- `includes/content_access.php:143` — fixed transitively via licences.php; no edit.
- `api.php:10762` (`licence_check`, dormant) — already routes through
  `userHasEffectiveLicence()`; fixed transitively.
- `includes/service_mode.php:824-854` (**C4**) — extend the query so a live
  `tblOrganisationLicences` ccli row on the session's org also satisfies the Phase-3 unlock
  (prefer its LicenceNumber over the legacy column when both exist). Preserve the `Channel`
  filter and the join shape EXACTLY (rule #26 — an unfiltered query is the cross-env leak class).
- NOT in scope: `resolveEffectiveTier()` (tier inheritance ≠ licence validity);
  `validateCcliNumber()` (format check).

**Behaviour-change note (must go in the commit body + CHANGELOG):** (i) an UNVERIFIED personal
CCLI number stops counting as a licence — a tightening that bites only where
`content_gating_enabled='1'` AND `require_licence:ccli` rows exist. All three envs are believed
dormant (`'0'`), but that is a runtime fact this container cannot verify — **flagged for the
live-verify pass**. (ii) org members (both org stores) now correctly pass — the intended fix.
(iii) `tier_check` now returns `hasCcli`-derived allowances for org members — a native-visible
loosening in the intended direction.

**C5**: delete the `ccli_validation_enabled` seed row (schema.sql:1722 — its own text says
"remove or wire it via #1668"; we remove) + a cleanup migration deleting the row on existing
installs; registry entry with probe = row absent (fresh installs pass trivially).

**C6 guard**: a tree-derived scan asserting the personal-CCLI SQL
(`CcliNumber` + `CcliVerified` in one SELECT) exists in **at most one** file
(includes/licences.php) — mutation-tested by injecting the old inline query into an api.php
corpus copy and requiring red. This is the mechanism (rule #35) that stops the fork regrowing.

### 4.4 Songbook-move re-key + redirect (#1679, Batch 4)

**The helper** — `includes/song_relocate.php`:
```php
/** Re-key a song into a new songbook (#1679). MUST be called inside the caller's transaction.
 *  Returns ['songId' => new, 'previousId' => old, 'renamed' => bool]. */
function songRelocate(\mysqli $db, string $oldSongId, string $targetAbbr, ?int $userId): array
```
1. No-op (renamed=false) when the song's current `SongbookAbbr` already equals `$targetAbbr`.
2. Validate the target book exists; read its `IsOfficial`.
3. Mint `<TargetAbbr>-NNNN` via `_bulkImport_nextSongNumberFor()` + the collision walk — the
   EXACT loop save_song_core already uses for draft mints (save_song_core.php:326-338); require
   `includes/song_importers.php`.
4. **One statement does the fan-out**:
   `UPDATE tblSongs SET SongId = ?, SongbookAbbr = ?, Number = NULL WHERE SongId = ?` —
   every child FK carries `ON UPDATE CASCADE` (25+ constraints verified in schema.sql:
   writers/composers/components/lyrics/media/tags/links/favorites/history/revisions/keys/…), so
   children re-key atomically, **including `tblSongRedirects.NewSongId`** (schema.sql:1976) —
   existing redirect chains repoint themselves; `songRedirectRepoint()` is NOT needed here.
5. `songRedirectWrite($db, $old, $new, 'move', $userId)` (includes/song_redirects.php:105) —
   `'move'` is new vocabulary for the VARCHAR `Reason` (schema.sql:1967 comment lists
   `merge | delete | rename`; update the comment — VARCHAR means no ALTER, rule #20 working
   as designed). If `songRedirectsTableReady()` is false (un-migrated env), proceed with the
   re-key and write an activity-log warning — never block a save on the redirect table.
6. **Number policy: CLEAR to NULL** (owner's stated default = v1's behaviour, editor.js:6441-6452,
   which also dodges target-book collisions). The id tail carries the minted slot; `Number`
   stays NULL for the curator to assign. Trivially changeable later (an `$opts['adoptSlot']`).
7. `PublicId` untouched — it exists precisely to survive this (schema.sql:165).

**The THREE write funnels** (all found by tracing `SongbookAbbr` writers — rule #33's grep-first):
- **R2** `editorSaveSongCore()`: after the `$prevRow` fetch (save_song_core.php:354-362), if
  `$prevRow !== null && $prevRow['SongbookAbbr'] !== $songbookAbbr` → `songRelocate()`, then
  `$songId = new`, `$assignedId = new`, `$number = null`, and the normal upsert proceeds under
  the NEW id (hits the ON-DUPLICATE update path against the just-renamed row). The response's
  existing `assignedId`/`previousId` contract (#1380) is reused verbatim — **the v1/v2 clients
  already relabel on it**, which is the "machinery already exists" point of #1679. Both books'
  SongCount refresh (:950-960) already handles previous-vs-current abbr — verify it receives
  the new id.
- **R3a** api2 `metadata_field_update` with `field='songbook'` (api2.php:864 + ED2_META_FIELDS
  api2.php:177): branch before the generic scalar UPDATE → wrap in a transaction (the generic
  path has none) → `songRelocate()` → respond `{ok, songId, previousId}`; the v2 shell updates
  its in-memory id + URL on `previousId !== songId`.
- **R3b** `ed2_applySongSnapshot()` (api2.php:537-553): **exclude the `songbook` field from the
  scalar restore loop.** A revision-restore must not teleport a song between books by side
  effect; restoring keeps the current home (documented in the function header). Without this, a
  restore would re-introduce the exact SongId↔SongbookAbbr mismatch #1679 exists to kill.
- v1 bulk move (editor.js:6440-6477) funnels through whole-song save → R2. Importers are
  INSERT-only (no move). `duplicate-songs` merge already writes its own redirects.

**R4 — JSON reads follow redirects.** Today only the HTML fragment resolves
(`includes/pages/song.php:44`). Add `songRedirectResolve()` to `song_detail`'s not-found path:
redirected+target → serve the target record + `"redirectedFrom": "<oldId>"`; tombstone → 410.
This is the safety net for every SOFT reference the cascade cannot touch: `SongsJson` blobs in
`tblUserSetlists`/`tblSetlistTemplates`, native-app local caches, PWA offline stores, old
bookmarks. Sweep (rule #33) the other SongId-parsing consumers — `normalizeSongId` in the PWA
router, the OG-image endpoint, `related_songs`/`remove_favorite`/`song_view` validators (rule
#27 list) — and confirm each either follows or degrades safely; the deep-links test derives
what it can.

**Traps for the implementing agent**: mysqli STRICT throws (no false-checks); the cascade is one
UPDATE but fires FK maintenance across ~25 child tables — fine per-song, do NOT build a bulk
re-key loop UI on top without batching thought; `component_upsert`'s `isset()` clear-semantics
(project-rules §20.5) are untouched but nearby — don't refactor drive-by.

### 4.5 #1671 extensibility — what "built for iLyricsDB reuse" concretely means (Batches 7–10)

**One preference store, namespaced (F5 — the "re-think").** Storage stays
`tblUsers.Settings` + the `user_settings` GET/POST pair (api.php:3138-3189) — a JSON blob on the
user row, already synced, already whitelist-fed by `settings.js`. What changes is the WRITE
contract, because whole-blob replace cannot survive a second product:
- POST accepts optional `namespace` (`^[a-z][a-z0-9_]{1,31}(\.[a-z][a-z0-9_]{1,31})?$`,
  e.g. `ihymns.web`, `ilyricsdb`, `apple`): the server **merges only that subtree**
  (`$settings[$ns] = $incoming`), leaving sibling namespaces untouched; per-namespace 16 KB cap.
  Absent `namespace` = today's whole-blob replace (back-compat with the current settings.js and
  any native caller). GET accepts optional `?namespace=` returning the subtree.
- The property that survives a second product: **iLyricsDB's clients write their own subtree
  with zero coordination protocol against iHymns keys** — no key-collision convention to police,
  no lockstep client releases. The web client migrates to `namespace='ihymns.web'` at leisure.
- `user_preferences` / `user_preferences_sync` (api.php:8329/8359) are DELETED (cases + yaml)
  — they were the un-namespaced duplicate of exactly this, on a parallel table. `tblUserPreferences`
  (schema.sql:1649) drops via a **manual, confirm-gated** card (the C6/#1235 destructive-drop
  convention) once the endpoints are gone; the guard's table check keeps it honest meanwhile.
- Out-of-repo callers of the deleted pair would now get 400 — accepted; the guard's stated
  blind spot, and the yaml has documented them as superseded.

**Web Push (F6) — extensible shape, not just a wire.** The full chain, each piece the smallest
reusable unit: VAPID keypair generated/stored via the EXISTING secret-crypto machinery
(`test-secret-crypto.php` proves it exists — do not invent a second secrets store);
`includes/web_push.php` (ES256 JWT via openssl + `aes128gcm` payload encryption — no Composer;
budget real time, this is the L in the estimate); SW `push` + `notificationclick` handlers
(the SW is the ONE legitimate native-`fetch` context, rule #31); a **`PUSH_KINDS` PHP map**
(kind key → label/default, VARCHAR-vocabulary discipline rule #20) with per-user opt-ins stored
in the namespaced settings (`ihymns.push`); sender fan-out reading `tblPushSubscriptions`
(schema.sql:1664, endpoints api.php:9163/9219 already live) with dead-subscription pruning on
404/410 responses; an admin broadcast + "send test to my devices" page under the EXISTING
`manage_notifications` entitlement. A new kind is one map line + one opt-in checkbox — the same
one-line-registry shape as `TIER_CAPS` (rule #28), which is the codebase's proven answer to
"this will need additional functionality as we expand".

**The other four** are deliberately thin — the servers are already complete and hardened
(devices: api.php:4292-4401 with CSRF + per-user rate limit; my-requests: 5201-5226; templates:
7672-7799 with the IDOR guard; keys: 7438-7518). Build the UIs against them, changing servers
only where E1's entitlement wiring touches a gate. Every new UI follows the standing
constraints: fragments carry no inline scripts (rule #30), modules use `apiFetch` (rule #31),
state-changing calls send what `validateCsrfRequest()` checks (rule #29, the #1677 lesson).

### 4.6 Entitlement truth-up (Batch 6) — the exact wiring map

Principle: **wire the check so the default role-map answer equals today's raw-role gate** — zero
live behaviour change until an operator overrides via `/manage/entitlements`, which is the point
(today their overrides silently do nothing, inventory §6.3).

| Key | Enforcement point today | Change |
|---|---|---|
| `delete_songs` | v1 editor/api.php:3541 + api2.php:125 escalate delete to raw `admin` role | `userHasEntitlement('delete_songs', $role)` (default map admin+ga — matches) |
| `bulk_edit_songs` | bulk ops gate on plain `editor` role | **First align the MAP to reality** (add `editor` to its roles in includes/entitlements.php:38 + entitlements.js:18 + the labels text at manage/entitlements.php:102 which claims admin-only) — then wire the check. Changing the code to match the map instead would strip working curators mid-flight |
| `edit_users`, `delete_users`, `change_user_roles` | manage/users.php page-gates on `view_users` only | Per-action `userHasEntitlement` checks in the POST handlers (defaults admin+ga — match the page population) |
| `assign_global_admin` | role-comparison logic inside the role-change handler | Wrap the promote-to-ga branch in the entitlement check (default ga-only — matches) |
| `run_db_migrate`, `run_db_restore` | setup-database.php raw `global_admin` (:191, :459) | Entitlement checks (defaults ga-only — match) |
| `run_db_backup` | same raw role | Entitlement check (default admin+ga per entitlements.js:33 — **verify the current raw gate is ga; if so align map to ga first**, same rule as bulk_edit_songs) |
| `access_alpha`, `access_beta` | channel_gate.php:107 (disabled at :83) | **No change — allowlist** (§0.2) |

Plus E2 (label the 10 checked-but-unlabelled keys: `manage_api_keys`,
`manage_duplicate_songs`, `manage_external_link_types`, `manage_feature_gating`,
`manage_own_organisation`, `manage_user_licences`, `manage_works`, `request_api_keys`,
`view_ccli_report`, `view_licence_audit`) and E3 (a derived PHP↔JS map-parity guard — the two
mirrors at includes/entitlements.php:37-53 and js/modules/entitlements.js currently agree by
luck, rule #35's named failure shape). Security lane: every gate change reviewed at
deep-architect tier (project-rules §17).

---

## 5. What should NOT be fixed (and the allowlist entry that documents each)

- **The 53-action admin/org API-parity family (§2.1)** — keep. It is a documented, entitlement-
  gated, deliberately API-first surface (#719) with a real mounted consumer (the Swagger
  try-it-out console at `/manage/api-docs`) and plausible future native-admin consumers.
  Deleting 53 documented endpoints is a contract-breaking act nobody asked for; duplicating the
  manage pages onto them is make-work. Allowlist: `deliberate API-first surface #719`. The
  residual risk (permanently untested writes) is Decision D1 below — with a stated default.
- **The content-gating/licensing 11 (§2.2)** — dormant by design per rules #20/#28; the entire
  program is behind `content_gating_enabled='0'`. Fix only `user_access`'s 500 (X5). Allowlist:
  `dormant by design, rule #28`.
- **The #1511 device-code + control-token pairs (§2.4)** — the approve side is live
  (device-link.js:54/65); the device side awaits the tvOS client. Documented live-dormant in the
  yaml (:3564). Allowlist: `#1511 live-dormant; consumer = tvOS`.
- **The rule-#20 one-pass dormant tables (§3.1 first group)** — `tblLyricsConflicts`,
  `tblPresentation*`, `tblSongEmbeddings`, the ExternalRefs family, etc. They are the DESIGNED
  forward-looking batches; deleting them re-opens schema families and forces the second
  migration rule #20 forbids. Allowlist: `one-pass dormant (#1066/#1088 pattern)`. Same for the
  four half-wired `include=` extras tables (`tblSongArrangements`, `tblSongRoyaltyIds`,
  `tblSongScriptureRefs`, `tblVocalParts`) — their empty reads are harmless and the write side
  is future feature work, now tracked by a B0.2 issue.
- **`tblLyricWords` / `tblLyricSyllables` write-only (§3.3)** — timing data deliberately accrues
  at ingest for the future karaoke/sync read path in the lyrics strategy. Deleting the writes
  throws away data we are collecting on purpose. Allowlist: `deliberate, lyrics strategy §…`.
- **`tblSongbookEntries` / `tblSongLanguages` / `tblSongbookLanguages` (migration-written,
  never app-read)** — additive junctions awaiting their read features (tblSongbookEntries'
  header says "strictly additive and not yet read", schema.sql:225-227). Allowlist as dormant.
- **`tblCreditPersonLinks` legacy fallback reader** — dead on migrated installs BY DESIGN
  (`if (empty($linksUnified))`, index.php:541); it is the un-migrated-install safety net.
- **The `app_status` emit of `captcha_provider` / `ads_enabled` / `motd`** — do not remove
  emitted fields (native decoder risk); X6 fixes the seed DESCRIPTIONS, M2 makes `motd` real.
- **`ihymns-full.sql` seeded `tblContentLicences` rows** — leave; the licence-store
  consolidation is a filed follow-up (B0.2), and Batch 2's branch (f) makes the LIVE store
  authoritative without a data migration.
- **v1 editor + its API** — out of scope; owned by epic #1601's retirement plan
  (project-rules §20.4), already guarded by `test-v1-consumer-deorphan.php`.
- **`schema-audit.php:36-37`'s comment** — actually accurate on close read (the includes exist
  so the endpoints can call them; the endpoints do call them; nothing calls the endpoints —
  which is the §2.1 story, not this file's).

---

## 6. Owner decisions still needed

Only two rise to genuine decisions; both carry defaults so **nothing blocks**.

**D1 — The 53 untested admin-parity endpoints: keep, cull, or keep-with-evidence?**
1. *Decision*: whether the admin/org JSON API surface (§2.1) stays as-is, gets culled to the
   subset with plausible consumers, or stays plus a runtime-evidence check.
2. *Why it needs you*: it is a product/exposure judgement — the code cannot tell us whether the
   native admin app and API-key partners you intend justify 53 permanently-untested,
   entitlement-gated write endpoints.
3. *Options*: **A** keep + allowlist (cost: the untested surface persists, but gated + now
   explicitly inventoried). **B** cull to reads-only (cost: breaks the published yaml contract;
   any out-of-repo consumer dies silently). **C** keep + schedule a `tblApiKeyUsage`-driven
   usage report so next audit has runtime evidence (cost: a small M work item, needs MySQL).
4. *Recommendation*: **A now, C when convenient** — the guard makes the debt explicit and
   count-exact, which was the actual danger; culling published API is the only irreversible
   option and the one with silent-breakage risk.
5. *Reply needed*: "A", "B", or "A+C". **Default if no reply: A** (allowlist entries note
   `owner-default 2026-07-30`).

**D2 — Dropping the four legacy scaffold tables (X4), specifically `tblUserPurchases`.**
1. *Decision*: confirm dropping `tblSessions`, `tblUserPermissions`, `tblMigrations`, **and**
   `tblUserPurchases` (schema.sql:944 — a guessed 2019-vintage purchases/monetisation shape,
   referenced by zero code).
2. *Why it needs you*: `tblUserPurchases` is the one with product implications — if monetisation
   is near-term, you may prefer to keep the placeholder.
3. *Options*: **A** drop all four (a future purchases feature designs its own one-pass schema
   per rule #20 — a stale guessed table is exactly the "guessed bridge" that rule bans).
   **B** drop three, keep `tblUserPurchases` (cost: one permanent allowlist entry + a table the
   Schema Audit will keep surfacing). Cost of nothing: fresh installs keep creating four tables
   no code has ever referenced.
4. *Recommendation*: **A** — the drop card is manual + confirm-gated + reversible from backup,
   and rule #20 says design the real thing when it's real.
5. *Reply needed*: "A" or "B". **Default: A**, with the drop card left UN-RUN on production
   until you nod (the card being manual makes the veto free).

**Stated defaults on sub-questions the owner has not addressed** (all trivially changeable,
none blocking): #1679 moved songs get `Number = NULL` (owner's own stated default — v1 parity);
Web Push v1 triggers = admin broadcast + test-notification only (kinds registry makes additions
one-liners); tombstoned setlist ids are never resurrected under the same id (§4.2 step 2);
`bulk_edit_songs` map aligns to code (editor+) rather than code to map (§4.6); captcha/ads seeds
get RESERVED descriptions rather than deletion (X6).

---

## 7. Definition of done — what makes "we don't want to be back here" actually hold

1. **The guard is live, registered, and proven falsifiable**: `test-orphan-inventory.php` +
   `test-openapi-actions-exist.php` run in CI via the DERIVED runner (G2, so no hand-list can
   omit them); their mutation self-tests run on every invocation; each was watched to FAIL at
   least once against a real injected regression before its first green was believed (rule #34).
2. **Zero unexplained orphans**: every dispatched action, every schema table, every entitlement
   label is either wired to a first-party consumer or carries a count-exact allowlist entry with
   a written reason — and the allowlist is self-cleaning, so the debt can only shrink or be
   consciously re-justified, never silently grow.
3. **The four decided items are runtime-verified, not just merged** — this container has no
   MySQL and no browser, so each is flagged for the live pass: (#1668) on alpha with gating
   flipped ON in a controlled window: an org member of a CCLI-licensed org (licence entered via
   /manage/my-organisations) passes `tier_check` + sees gated content; an unverified personal
   number does not. (#1661) two-device tombstone propagation + an expiry observed converting +
   61 setlists surviving a sync. (#1679) a real songbook move: old URL 301s, new id everywhere,
   favourites/history intact, editor relabels without reload. (#1671) each feature exercised
   end-to-end (a push received on a real device is the F6 bar).
4. **Migrations converged on all three docroots**: every new card (S1, C5, X4, X5, F5) applied
   via /manage/setup-database on alpha → beta → prod; the pending counter reads zero; the Schema
   Audit page shows no orphan divergence. The destructive cards (X4/X5 drops, F5 drop) run ONCE,
   manually, after the code referencing them is live everywhere.
5. **Docs tell the truth**: yaml contains no phantom or silently-dead paths (guarded);
   `tblAppSettings` seed descriptions match reality (X6/C5/M2); CHANGELOG/PROJECT_STATUS/
   ProjectBrief claims are evidence-backed; the Wiki pages (API/Schema/Setup) updated per
   standing-tasks; and every finding here exists as a GitHub issue (B0.2) — the tracker, not
   this file, is the point of truth going forward.
6. **The residual blind spots are on record, not forgotten**: out-of-repo API consumers
   (closable only via `tblApiKeyUsage` runtime evidence — filed), mounted-but-unreachable UI,
   and dynamic action assembly are stated limitations in the guard's header, so the NEXT audit
   starts from an honest baseline instead of rediscovering them.
