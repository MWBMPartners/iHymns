# Plan: rewriting accounts, profiles and personal-data sync (iHymns)

2026-11-18. Builds on `.claude/account-profile-architecture-review-2026-11-18.md` (the "review") and `.claude/settings-sync-2143-plan.md`. Plan only; nothing built.

---

## 0. Owner decisions, and what they mean here

The owner's numbering differs from the review's. This plan uses the owner's.

| Owner | Decision | Effect on the plan | Review's number |
|---|---|---|---|
| O1 | Fold the safety fixes into the rewrite, but do them first | Phase A below; no separate release | — |
| O2 | Sign-out clears this browser's favourites, set lists and history, but asks first if anything has not reached the account | Step G5 (web), I5 (Apple) | O1 |
| O3 | One account across live, beta and dev, with a per-channel off switch | Step C1–C2 (new switch `account_writes_channels`) | O2 |
| O4 | Privacy bundle in full, including blanking old search links and updating the privacy page | A4, A5, B2, G3, G6 | O3 |
| O5 | "Most viewed" period chosen by each person | F2 (a profile setting, default 12 months) | O5 |
| O6 | One sign-in per device for native apps, Apple first | C3, H1–H2, I2, and section 7 (copyable to SIGNula.id and Clearl1st) | O6 |

**Not answered:** the review's O4 (#2177: which device-only items follow the account). Default taken, easy to change: recent songbooks and per-song key/capo follow the account; **search history does not**. Per-song key/capo is its own step (D9) so it can simply be dropped.

---

## 1. What I checked in the code, and where the review was wrong

**Confirmed:** H1, H2, H3, H4 (the comment at `api.php` ~4816 is wrong), M1, M2, M9, L8, the search insert binding the user id (~1162), "Clear history" deleting rows (~10885), no rate limit on `song_view`, and the Apple token being iCloud-synced by default.

**Corrections:**
1. **M6 is in two places.** `device_signout` (`api.php` ~6171) also deletes *every* Apple push registration, so removing a stale browser from the device list stops the iPhone's notifications.
2. **Sign-in tokens are created in six places:** `auth_register`, `auth_login`, `auth_apple`, `auth_device_code_poll` (`api.php`), and `_completeEmailLoginTxn()`, `mintCrossSurfaceAuthToken()` (`manage/includes/auth.php`). Only three record device details. Per-device sign-in needs one token maker first (C3).
3. **`tblActivityLog` has no database link to `tblUsers` at all**, so anything driven by the database's links (erasure, the proposed export guard) can never see it. The export guard also needs a reviewed list of tables that point at users without a link.
4. `tests/php/test-privacy-minimisation.php` does not exist; it must be created, not extended.
5. The Apple app does not call `auth_apple` yet; Sign in with Apple is only planned there.
6. The settings store has **four** live writers (two in `api.php`, card layout save **and** clear), plus erasure.
7. Minor: favourites re-merge once per app start and on reconnecting, not on every page change.

---

## 2. How the work is organised

**Conventions for every step:**
- One commit per step, Conventional-Commit title (rule #46), two-level comments on new code.
- **Verify** = the step's own test, then `php tools/run-php-tests.php` and `node tools/run-node-tests.js`, plus `php -l` / `node --check` on touched files. **Undo** = `git revert <sha>` unless stated; switches undo instantly.
- Every guard is derived from the tree and shown to fail (rule #34).
- Every new or changed API action: `api-docs.yaml` in the same commit, `getAuthenticatedUser()`, `validateCsrfRequest()` on the cookie path, a shared core (rules #22, #48). Web code uses `apiFetch()` (rule #31); identity, never position (rule #51).

**Tags:** 🔴 = riskiest, needs an Opus review pass before merge. 🟢 = can run in parallel with the other 🟢 steps of its phase (still one branch, one commit after another). 👤 = needs an owner action.

**Delivery:** one working branch, three pull requests one after another (each merged before the next opens, never stacked): **PR 1** = Phases A–C, **PR 2** = Phases D–G, **PR 3** = Phases H–I (server side of native sign-in, then the Apple app). Phase J closes PR 2 and PR 3.

**Dependencies:** A has none. B1 is needed by C1–C2 (logging only), D, E, F, G4, H. C3 is needed by H. C4 is needed by D, F2, G1. D, E, F and G can proceed in any order after B1 + C4.

---

## 3. Steps

### Phase A: safety fixes, first (no schema change)

**A1 🔴 One locked writer for the settings store.** Files: `includes/user_settings.php` (new `userSettingsMutate(\mysqli $db, int $uid, callable $fn): array` — transaction, `SELECT Settings … FOR UPDATE`, apply, cap check via `userSettingsRejectReason`, `UPDATE`, commit; roll back on any error), `api.php` `case 'user_settings'` (both branches), `includes/card_layout.php` (save **and** clear). Fix the wrong comment. Guard: `tests/php/test-settings-box-single-writer.php` scans every `.php` under `appWeb/` for SQL that writes `tblUsers.Settings`; only `user_settings.php` and the erasure in `account_lifecycle.php` may. Mutation: add a raw UPDATE to `card_layout.php` → red. `test-user-settings-namespace.php` must stay green.

**A2 🟢 Stop re-fetching settings on every page change.** Files: `js/modules/settings.js`, `js/modules/list-sort.js`. Pull only when the signed-in user id changes or 30 minutes have passed; before pulling, if an upload is waiting, send it first. Guard: `tests/test-settings-pull-gate.js` (two events for one user → one fetch; change theme then fire event → theme survives). Mutation: remove the user-id check → red.

**A3 🟢 Keep a visitor's language choice at first sign-in.** Files: `api.php` `user_preferred_languages` (add `isSet`), `songbook-language-filter.js`, `settings-language-filter.js`, `api-docs.yaml`. If `isSet === false` and the device has a choice, save it to the account instead of adopting `[]`; if `isSet` is absent (older server), behave as today. Guard: PHP check for `isSet` plus a jsdom claim test. Mutation: always adopt `[]` → red.

**A4 🟢 Stop recording who made each search.** File: `api.php` ~1162, bind `null`. Create `tests/php/test-privacy-minimisation.php` asserting the `tblSearchQueries` insert never binds a user. Mutation: restore `$uid` → red.

**A5 🟢 "Clear history" removes your name, not the rows.** File: `api.php` `song_history_clear` → `UPDATE tblSongHistory SET UserId = NULL WHERE UserId = ?`; `cleared` keeps its meaning. Add to the same test.

**A6 🟢 Signing out a browser no longer stops iPhone notifications (part 1).** Files: `api.php` `auth_logout` and `device_signout`. Read the signed-out token's `tblApiTokens.Platform` first; only delete Apple push rows when that session is an Apple one. (Step G4 finishes this per session.) Guard: a PHP source test that both deletions sit behind the platform check. Mutation: remove the check in `device_signout` → red.

**A7 🔴 Never merge one person's data into another's account.** Files: `js/modules/user-auth.js`, `js/constants.js` (`STORAGE_DATA_OWNER_UID`, device-only). Record the owner's user id when sync data is absorbed. In `triggerUserDataSync`, if the recorded owner is someone else, move favourites, set lists, custom tags, history and watermarks into one stash `ihymns_stash_<uid>` (at most one), clear them, then reconcile. Do the same on an **unplanned** sign-out (401, expiry), so the next person never sees the data; the same person signing back in gets the stash restored first. No recorded owner = anonymous data, claimed as today. Guard: `tests/test-account-switch-claim.js` (A's data, switch to B → B's request has none of A's ids; A back → restored). Mutation: remove the owner check → red.

**A8 🔴 Old app builds can no longer delete set lists by leaving them out.** File: `includes/user_sync.php` `userSyncDeletableIds()`: in replace mode with no `since` and no `deleted`, delete nothing. Behind `sync_absence_delete_requires_since` (default `'1'`, on). Cost: until I1, a set list deleted on an iPhone comes back; visible and recoverable, unlike the silent loss it replaces. Guard: extend `tests/php/test-user-sync-guard.php` with the Easter scenario. Mutation: drop the `since` check → red. Undo: set the switch to `'0'`.

**A9 🟢 Switch to refuse whole-store settings saves.** File: `api.php` legacy branch; `user_settings_legacy_blob_writes` (default `'1'`, nothing changes) → when `'0'`, answer `409 {reason:'namespace_required'}`. Update `api-docs.yaml` (and drop its "only store" claim).

### Phase B: schema, in one pass

**B1 🔴👤 One migration for the whole feature family.** New `appWeb/.sql/migrate-account-profile-foundation.php`, a byte-identical mirror in `schema.sql`, **one** `migration-registry.php` entry with an OR-probe over every object, a `@migration-adds` tag per column, includes resolved through `IHYMNS_INCLUDES_DIR` (rule #41). All VARCHAR vocabularies, no ENUM (rule #20). Everything is dormant until code uses it.

```sql
CREATE TABLE IF NOT EXISTS tblUserProfileItems (
  UserId INT UNSIGNED NOT NULL,
  Section VARCHAR(40) NOT NULL COMMENT 'registry section, app-validated (rule #20)',
  ItemKey VARCHAR(191) NOT NULL COMMENT 'identity, never position (rule #51)',
  ValueJson JSON NULL,
  Version BIGINT UNSIGNED NOT NULL COMMENT 'per-user change counter, set under the user row lock',
  UpdatedAt DATETIME(3) NOT NULL,
  DeletedAt DATETIME(3) NULL DEFAULT NULL COMMENT 'non-NULL = delete marker',
  WriterChannel VARCHAR(16) NULL, WriterPlatform VARCHAR(20) NULL,
  PRIMARY KEY (UserId, Section, ItemKey), KEY idx_UserVersion (UserId, Version),
  CONSTRAINT fk_ProfileItems_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE TABLE IF NOT EXISTS tblUserSyncTombstones (
  UserId INT UNSIGNED NOT NULL, Kind VARCHAR(30) NOT NULL, ItemId VARCHAR(191) NOT NULL,
  DeletedAt DATETIME NOT NULL, Reason VARCHAR(20) NOT NULL DEFAULT 'user',
  PRIMARY KEY (UserId, Kind, ItemId),
  CONSTRAINT fk_SyncTomb_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE TABLE IF NOT EXISTS tblAccountEnrolKeys (
  KeyHash VARCHAR(64) NOT NULL PRIMARY KEY COMMENT 'sha256 of the key; the key itself is never stored',
  UserId INT UNSIGNED NOT NULL, Platform VARCHAR(20) NOT NULL,
  CreatedAt DATETIME NOT NULL, LastUsedAt DATETIME NULL, ExpiresAt DATETIME NOT NULL, RevokedAt DATETIME NULL,
  KEY idx_User (UserId),
  CONSTRAINT fk_EnrolKeys_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE);
ALTER TABLE tblApiTokens ADD COLUMN DeviceInstallId VARCHAR(64) NULL, ADD COLUMN IssuedVia VARCHAR(20) NULL,
  ADD KEY idx_UserInstall (UserId, DeviceInstallId);
ALTER TABLE tblApnsTokens ADD COLUMN ApiTokenHash VARCHAR(64) NULL, ADD KEY idx_ApiToken (ApiTokenHash);
ALTER TABLE tblPushSubscriptions ADD COLUMN Channel VARCHAR(16) NULL;
-- last, because its size is unknown:
ALTER TABLE tblSongHistory ADD COLUMN Source VARCHAR(20) NOT NULL DEFAULT 'view',
  ADD COLUMN Channel VARCHAR(16) NULL, ADD COLUMN Platform VARCHAR(20) NULL;
```

Each object is created only if missing, so an interrupted run can be repeated. Must stay green: `test-schema-coverage.php`, `test-migration-registry.php`, `test-account-delete-fk-coverage.php` (new links are CASCADE). 👤 Owner: check the MySQL version (8.0.12+ adds columns instantly; older copies `tblSongHistory`), back up, run the card once from alpha (the database is shared). Undo: unused until later steps; revert.

**B2 🔴👤 Blank old privacy data (destructive, run once).** New `migrate-privacy-blank-legacy-links.php`, registry entry `'manual' => true` (never in "Apply all", `confirm=1`). In batches of 5,000: `UPDATE tblSearchQueries SET UserId = NULL WHERE UserId IS NOT NULL`; and for audit rows of accounts already erased (`tblUsers.Status = 'deleted'`) blank `IpAddress`, `UserAgent`, `Referrer`, `Country` and remove `$.username` / `$.email` from `Details`. No schema change, so no `schema.sql` edit. Probe: pending while any such row exists. 👤 Owner runs it only after A4 is live on **production** (so no new links appear) and after a backup. Undo: only from the backup.

### Phase C: shared cores

**C1 🔴 One shared per-channel switch, then the account-writes switch (O3).** First extract `includes/channel_switch.php` (`channelSwitchAllows($csv, $channel)`, `channelSwitchEnabledHere($key, $default, $onError)`) and re-point `cross_site_sync.php` unchanged; this shape now has its third copy (rule #22). Then `includes/account_write_gate.php`: `accountWritesAllowedHere()` reading `account_writes_channels`. **Default with no row: all three channels** (one shared account, as decided; deploying changes nothing). **If unreadable:** allowed on production, refused on alpha and beta. Nothing ticked stores `'none'`. Admin card on `/manage/configuration` (`save_account_writes`, mapped `web_only:configuration-secrets` in `test-manage-action-api-coverage.php`); turning **production** off needs a typed confirmation. Guard: truth tables for both helpers.

**C2 🔴 Apply the gate to every personal-data write** (favourites, set lists and their shares, custom tags, settings, profile items, language, song notes, personal history, profile edits, account deletion; not sign-in, sign-out or registration). When off: `409 {reason:'account_writes_off_here'}`; views are recorded without the person. Clients keep the change queued on the device and the web shows one quiet notice. Guard: `tests/php/test-account-write-gate.php` finds every `case` in `api.php` whose body writes to a personal table (list derived from `schema.sql` links to `tblUsers` plus a reviewed extra list) and fails unless it calls the gate. Mutation: remove the call from `favorites_remove` → red.

**C3 🔴 One token maker.** `includes/api_tokens.php`: `apiTokenMint(\mysqli $db, int $uid, array $meta, string $issuedVia): string` (random 32 bytes → 64 hex, store sha256, expiry, device details, `DeviceInstallId` and `IssuedVia` when the columns exist). Re-point all six creation sites with otherwise identical behaviour. Guard: `tests/php/test-single-token-mint.php` bans `INSERT INTO tblApiTokens` anywhere else. Mutation: add one to `api.php` → red.

**C4 🟢 The profile registry.** `includes/profile_registry.php` as in the review's 2.1, plus fields `clearAtSignOut` and `cookie`. Extra seeds: `home.mostViewedPeriod` (`30d|90d|12m|all`, default `12m`, account scope, all platforms), and the content kinds (favourites, set lists, custom tags, history) with `clearAtSignOut: true`, so sign-out clearing reads one list. Guard: `tests/php/test-profile-registry.php`. Mutation: give the MIDI pedal account scope → red.

**C5 🟢 `GET ?action=profile_schema`.** Public, cacheable, ETag, server-only fields removed.

**C6 🟢 Lockstep guards.** `tests/test-profile-registry-lockstep.js` (PHP dump vs `settings-sync-registry.js`: same keys, same allowed values) and the Apple snapshot `appApple/Packages/iHymnsKit/Sources/IHModels/Resources/profile-schema.json` generated by `tools/dump-profile-schema.php`, checked equal. Mutations: a theme only in PHP → red; edit the snapshot → red.

### Phase D: one sync method for small personal items

**D1 🔴 `includes/profile_sync.php` core**, exactly as the review's 3.1, plus the account-writes gate. Guard: `tests/php/test-profile-sync-core.php` truth table, with the review's three mutations.

**D2 `case 'profile_sync'`.** GET pulls; POST pushes then pulls. Off until `profile_sync_channels` (new, default `'none'`, via C1's helper) lists this channel → `409 {reason:'profile_sync_disabled'}`.

**D3 🔴 Two-way bridge with `ihymns.web`.** One function in `user_settings.php` using `legacyWebKey`, called from inside `userSettingsMutate` so old and new stay in step while production still runs old code. Test both directions.

**D4 One-time import per person, on first use.** Brings in `ihymns.web`, `list_sorts`, `cardLayouts`, `ihymns.push` and the language column; marks `_meta/imported_v1`. No bulk migration.

**D5 Web client `js/modules/profile-sync.js`** as the review's 4.1; `app.syncStorage(key)` feeds both the cookie (rule #52) and the outbox. Guard: jsdom test that an unsent local change survives a pull.

**D6** list sorts, card layouts and alert choices become profile sections. **D7 🟢** chord notation and columns (#2176). **D8 🟢** recent songbooks (cap 20). **D9 🟢** per-song key, capo, octave (by SongId, cap 2,000; droppable). Each: a registry entry plus one call site.

**D10 Recognise the shared sign-in cookie (M8).** Behind `account_cookie_session_channels` (default `'none'`): no local token → call `auth_me`; on 200 enter cookie-session mode. Opens the way to stop copying the token into localStorage (L4) later.

### Phase E: favourites that stay removed

**E1 🔴 Server**, as the review's 5.1, also for custom tags (`Kind='custom_tag'`); `favorites_remove` writes a marker too. Legacy requests unchanged. Guard: resurrection scenario in `test-user-sync-guard.php`. Mutation: drop the `since` comparison → red.

**E2 Web client** (`favorites.js`, `user-auth.js`): pending adds and deletes like `setlist.js`. **E3 🟢** Import → Replace queues deletes (L1).

### Phase F: activity

**F1 Count real views once, from the live site.** `song_view` writes `Channel`, `Platform`, `Source='view'`; backfill writes `Source='backfill'`. `popular_songs` and the CCLI report count only `Source='view'` from `production`. Rate limits on `song_view` and `song_history_backfill` through `includes/rate_limit.php`.

**F2 "Your most viewed" (#2175, O5).** `GET ?action=my_most_viewed&period=` — signed-in only; `period` checked against the registry entry, defaulting to the person's `home.mostViewedPeriod`; same visibility and language filters as `song_history`; top 20. Home card next to Recently Viewed with a period picker that writes the setting. Guard: query has `UserId = ?` and both filters. Mutation: drop the user filter → red.

### Phase G: privacy and the account

**G1 🔴 `account_export`** ("Download my data", web and API): the user's live database links, the registry, and the reviewed no-link list (correction 3); secret columns refused by name. Guard: `tests/php/test-account-export-coverage.php` — every link in `schema.sql` and every `UserId`-like column is exported or excluded with a reason. Mutation: add an unlisted table → red.

**G2 🟢 Web self-service deletion** using the existing `account_delete` and its confirm step.

**G3 🟢 Erasure cleans the audit log from now on** (`account_lifecycle.php`; extend `test-account-lifecycle.php`).

**G4 Push tied to the session.** `apns_register` stores `ApiTokenHash`; `auth_logout` and `device_signout` delete only rows for that session (rows with no hash fall back to A6's rule).

**G5 🔴 Sign-out clears this browser, asking first (O2).** In `user-auth.js`: try to send everything waiting (outbox, offline queue, pending set-list and favourite changes, the settings timer). If anything is still unsent, `app.showConfirm()`: "N changes have not reached your account yet" → *Try again* / *Sign out and lose them* / *Stay signed in*. Then remove every `clearAtSignOut` key; display settings stay. Unplanned sign-outs follow A7. Guard: jsdom test — unsent change → dialog shown, nothing cleared until confirmed; nothing unsent → cleared with no dialog. Mutation: skip the unsent check → red.

**G6 🟢 Privacy page** (`includes/pages/privacy.php`): server-side history, searches no longer linked, export, deletion, shared account across sites, the sign-out behaviour.

### Phase H: one sign-in per device (server side of O6)

**H1 🔴 `auth_device_enrol`.** Bearer-authenticated POST with `deviceInstallId`, `deviceName`, `platform`, `appVersion`, optional `wantEnrolKey`. Mints a device token through `apiTokenMint` (`IssuedVia='enrol'`), revoking any older token with the same `deviceInstallId` so reinstalls do not pile up. Optionally returns an **enrol key** (section 7). `auth_enrol_key_redeem` swaps an enrol key for a device token; enrol keys work nowhere else, because `getAuthenticatedUser()` never reads their table. Rate-limited. Guard: truth table, plus a test that an enrol key used as a Bearer token is refused. Mutation: let `getAuthenticatedUser()` read `tblAccountEnrolKeys` → red.

**H2 Device list and "sign out everywhere".** `devices_list` shows how each session was created; "sign out everywhere" also revokes enrol keys.

### Phase I: the Apple app

**I1** set lists on protocol 2 (`since` per user, `deleted`, absorb markers and refusals). **I2 🔴** per-device sign-in (section 7): on first launch after the update, swap the shared token through `auth_device_enrol`; device token in a **non-synced** keychain item, enrol key in the synced one; never delete the old synced item (that would remove it from every device before they swap). **I3 🟢** record views; read history and most-viewed. **I4** profile adapter (bundled schema, `@AppStorage` keys mapped to registry ids, outbox in UserDefaults). **I5** sign-out clears local favourites and set lists, asking first, as G5. 👤 TestFlight, then App Store release.

**Android:** a separate epic; it needs only sign-in, the Android Keystore, `auth_device_enrol`, `profile_sync` and the content endpoints.

### Phase J: project docs

New CLAUDE.md rule (one registry, one sync method, identity plus delete markers, one token maker, the account-writes switch); wiki; `.OpenAI/`; `CHANGELOG.md`; `WHATS-NEW.md` bullets for each visible change.

---

## 4. Switches (all on `/manage/configuration` or `tblAppSettings`)

| Switch | Default | Purpose |
|---|---|---|
| `account_writes_channels` | all three | Stop a test copy writing into real accounts |
| `profile_sync_channels` | `none` | Turn the new sync on per channel |
| `account_cookie_session_channels` | `none` | Cookie sign-in recognition (M8) |
| `sync_absence_delete_requires_since` | `'1'` | A8 protection against old builds |
| `user_settings_legacy_blob_writes` | `'1'` | Refuse whole-store saves when `'0'` |

---

## 5. Rollout

1. **PR 1** (A–C) to alpha; everything but the small Phase A fixes is dormant. 👤 Check the MySQL version, back up, run B1. Promote to beta and production promptly: these are the safety fixes.
2. 👤 Once A4 is on production: back up, run B2.
3. **PR 2** (D–G) to alpha. 👤 Tick `profile_sync_channels` for alpha, test, then beta. D3's bridge keeps production's older code correct meanwhile; E1 fully works only once production is promoted, because old live-site code still re-adds favourites.
4. Promote PR 2; tick production.
5. **PR 3** (H, then I): server through to production first, the Apple build one cycle later. Old Apple builds keep working on the legacy paths.
6. If a test copy misbehaves: untick it in `account_writes_channels`.

---

## 6. Real-device checks

Two browsers, an iPhone, an iPad:
- Remove a favourite → it stays gone everywhere.
- Ben signs out offline (sees the unsent-changes question), Carol signs in → nothing of Ben's reaches her.
- Make a set list on the web, edit another on the iPhone → both survive.
- Change the theme and move page at once → it sticks.
- Sign a browser out from the device list → the iPhone still gets notifications.
- Sign out on the iPhone → the iPad stays signed in; they show as two devices.
- Untick alpha in `account_writes_channels` → a favourite saved on dev never reaches the account.
- Export holds every listed store; an erased account leaves no IP addresses.

---

## 7. Reusable design: one sign-in per device (for iHymns, SIGNula.id and Clearl1st)

**Goal:** each device has its own sign-in, so it can be listed, named and signed out alone, yet someone using iCloud Keychain or a password manager still signs in once.

1. **Device token.** Random 32 bytes, sent as `Authorization: Bearer <64 hex>`; the server stores only its sha256; it expires after 30 days unused, renewed on use. Stored on the device **without** cloud sync (Apple `kSecAttrSynchronizable = false`; Android Keystore). One row per device: name, platform, app version, last seen, how issued, and a random install id the app makes once.
2. **One maker.** Every sign-in route (password, email link, Apple, TV pairing, enrolment) creates tokens through one function; a guard forbids any other.
3. **Optional enrol key** for "sign in once on all my devices": a long-lived secret in the synced keychain that can only be swapped for a new device token. It lives in its own table, so it can never work as a normal sign-in.
4. **Signing out** revokes only that device's token and the push registrations linked to it. The device remembers "signed out here" so it never quietly re-enrols. "Sign out everywhere" revokes all tokens and enrol keys.
5. **Leaving a shared token:** each device swaps it for its own; the old one is never deleted from the synced store and expires once no old app uses it.
6. **Never** show a token again after creation, accept the stored hash as a sign-in, or let a client claim another device's install id.

---

## 8. Before starting

File an "Account and profile sync" epic with an issue per defect (H1–H4, M1–M9 including M6's `device_signout` half, L1) and per decision (O2, O3, O5, O6). None are filed yet.
