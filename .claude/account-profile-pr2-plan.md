# Plan: PR 2 of the account rewrite (phases D, E, F, G)

Written 2026-11-23 against `origin/alpha` 1.4.1, after PR 1 (#2182) was merged. This is a plan only. "Old plan" means `.claude/account-profile-rewrite-plan.md`.

## Progress (PR 2a, branch `claude/account-pr2a`)

| Step | Commit | Status |
|---|---|---|
| 2a-1 the switch | c9316d73 | done |
| 2a-2 "B1 never assumed" guard | e73187c4 | done |
| 2a-3 delete markers for favourites and custom tags | 545ce356 | done, needs Opus review |
| 2a-4 the web sends what it added and deleted | 27edb404 | done, needs Opus review |
| 2a-5 import goes through the stores | 99f8f7fa | done |
| 2a-6 count real views once | 9cff71f0 | done |
| 2a-7 "your most viewed" | 688c3643 | done |
| 2a-8 push registrations tied to the sign-in | c7d9bdc5 | done |
| Opus review fixes (1 stale marker beaten by a newer row, 2 removal during a sync, 3 views per device, 4 push tie and orphans, 6 claim drops removals, 7 erase lock and transaction inside the try, 8 marker cap, 10 skip-a-row as before) | a2481c94 .. 96c50480 | done |
| Release-notes bullets taken out of this PR | fec45b2e | done, **re-add the five `WHATS-NEW.md` bullets (removed favourites stay removed, your most viewed, a fairer popular list, sign-out only silences that device, importing a backup is remembered) when production is ticked** |

Deviations from the plan (PR 2a):
- 2a-2: the guard has its own small tokeniser instead of `tests/php/lib/php_source_units.php`, because it must check that the ready-check comes BEFORE the statement (the shared library gives per-function views only). The 2a-6 and 2a-8 guards use the shared library.
- 2a-2: the "remove the check from `popular_songs`" proof was done on `includes/api_tokens.php` (the first place that uses a B1 column), and again for `tblSongHistory.Source` in 2a-6, because `popular_songs` only starts naming the new columns through a helper in 2a-6.
- 2a-3: absence deletes sit before the plan/upsert (as the code already did), not after it; they cannot affect each other. An `added` id clears its marker only if it is also in the payload. The reply carries `protocol` (2 when `deleted`/`added` were honoured, else 1) and, on a site with the switch on, `tombstones`.
- 2a-4: the web clears what it sent on any absorbed reply that is not a read-only (paused-site) one, including `protocol: 1` (switch off), so stale deletions never queue up and replay when the switch is later ticked.
- 2a-5: the import also records the new items as deliberate adds (otherwise an imported favourite that has a delete marker would be held back).
- 2a-6: `enforceReadRateLimit()` gained an optional key so the limit is per signed-in person; `song_view` and the backfill body carry an optional `platform` (the web sends `web`).
- 2a-7: the default period is read from `tblUserProfileItems` (dormant until PR 2b writes it), so the orphan inventory needed one allowlist entry that 2b-2 must remove.
- Follow-ups recorded from the Opus review, NOT done now:
  - Finding 5: old-style `since` requests from cached PR 1 web bundles on a switched-on site can re-clear a marker on their second sync. Transitional; goes away as bundles refresh.
  - Finding 9: `mb_strtolower` (used by the pure planners) is not the same as `utf8mb4_unicode_ci` accent-insensitive comparison; markers are not moved when a song id is relocated; the repeated hour at the end of daylight saving can mis-order a marker and a row.
  - Rule-#34 weakness of `test-account-foundation-gating.php`: it only checks that a ready-check appears EARLIER in the same function, not that it guards the statement.
  - Tag markers have no "exists" check (no registry to check against); only the 5,000 cap applies.
- Docs: `api-docs.yaml` named the `song_view` field `songId`; the server reads `song_id`. Corrected.

## Progress (PR 2b, branch `claude/account-pr2b`)

| Step | Commit | Status |
|---|---|---|
| 2b-1 registry version 2 (`map` kind, four new entries) | 63aab863 | done |
| 2b-2 the sync core | 46863e33 | done, needs Opus review |
| 2b-3 the `profile_sync` endpoint | 52332a6f | done |
| 2b-4 keep the old settings box and the profile in step | 162a3f80 | done, needs Opus review |
| 2b-5 the browser's half | 1b0755cd | done, needs Opus review |
| 2b-6 chords | 34e93cd7 | done |
| 2b-6 recent songbooks | df7e839f | done |
| 2b-6 per-song key, capo, octave | 39ad03a4 | done |
| 2b-6 search history | 14d22bb2 | done |
| gating guard: the core opens with its check and has one door | 60be7a76 | done |
| Opus review fix 1: the server refuses recent searches unless opted in (`not_opted_in`) | 0b4390c8 | done |
| Opus review fix 2: a shared browser carries neither the option nor the list to the next person | 2216d612 | done |
| Opus review fix 3: the searches switch changes nothing unless it can be queued; the row follows "is the sync active" | 8ef3da89 | done |
| Opus review fix 4: switching settings sync back on reads first (no `queueAllLocal`) | f5a4149f | done |
| Opus review fix 5: a read and a send never overlap in the browser | 7d9f1c99 | done |
| Opus review fix 6: a valid value always fits the stored size (`maxBytes`) | 8969b5e5 | done |
| Opus review fix 7: delete markers per person and map section are capped at 5,000 | 90bf9819 | done |
| Opus review fix 8: an empty or all-zero per-song value is a removal | 1172a250 | done |
| Opus review fix 9: one letter case (upper) for per-song keys | c6da4d23 | done |
| Opus review fix 10: a refused copy onto the old box cannot let a stale value return | 0a746678 | done |
| Opus review fix 11: a per-person version counter that never goes down | b04d6b18 | done |
| Opus review fix 12: the comment and docs no longer say a Bearer request is exempt; 405 documented | b32c39b8 | done |
| Opus review fix 13: the "one door" guard derives its names from the file and reads real code | 73434149 | done |

Deviations from the plan (PR 2b):
- 2b-1: `library.recentSongbooks` joined `PERSONAL_DATA_KEYS` here, not in 2b-6, because the new lockstep check ("every web key cleared at sign-out is in `PERSONAL_DATA_KEYS`") needs it. The check "every web profile_sync entry has a web key or a hand-written provider" landed with the last provider (search history, 2b-6), because it cannot be true before.
- 2b-2: an item key is compared ignoring letter case (`MP-1` and `mp-1` are one row, as the table treats them). `profileSyncApply()` was split into `profileSyncApplyLocked()` (caller holds the lock) and a wrapper, so 2b-4 could run its steps inside the same transaction.
- 2b-3: rate limit is 120 a minute per signed-in person (`enforceReadRateLimit`), for both methods. A server error is `503 {reason:'profile_sync_unavailable'}`.
- 2b-4: `userSettingsMutate()` was split into `userSettingsMutateLocked()` plus a wrapper (behaviour unchanged); `test-user-settings-mutate.php` was updated to check the split. The JavaScript twin of the converters is its own pure module `js/modules/profile-web-codec.js` (so the lockstep test is built in 2b-4, not 2b-5). Writing that test found a real fault (a list `[]` accepted for an object setting, and the reverse), now fixed.
- 2b-5: the writes to chord style, recent songbooks and the "most viewed" period call `syncStorage` in this commit (planned for 2b-6), because the widened guard in `test-settings-sync-cookie.js` now covers them. That guard now follows names and properties, not only literal keys (it found the chord-style write the old pattern could not see).
- 2b-6 search history: the "Keep my recent searches in my account" row is hidden until the page can say the new sync is active (the settings page is a shared cached page, so it cannot decide per site).

Deviations from the plan (Opus review fixes):
- Fix 6 chose a per-entry byte limit (`maxBytes`, 4096, on `history.searches` only) rather than shrinking `maxItems` x `maxLength`, because even 20 x 50 characters can exceed 4 KiB when a character is written as `\uXXXX` (6 bytes). The registry version stays 2 (an unreleased entry gained a constraint; nothing was added), and the Apple snapshot was regenerated.
- Fix 9 changed the pattern of the unreleased `songPrefs.*` entry to upper case only (the server upper-cases a lower-case key before checking). The Apple snapshot was regenerated.
- Fix 7 prunes OLDEST markers beyond 5,000 per person and section when a marker is written. A device away so long that it never saw a pruned removal keeps that one item until it changes again (the version counter of fix 11 means no number is reused).
- Fix 11 was written after fix 7 in the commit order; fix 7 does not need it (the oldest markers go, never the newest).
- Fix 2 stashes the searches list only while the option is on, and puts a returning person's list back whole (never merged); with the option off the older device-only list is left exactly as it was.
- Fix 3 added a small module (`search-history-sync-row.js`) and a new event (`EVT_PROFILE_ACTIVE_CHANGED`) so the Settings row can be tested and can follow the sync switching on and off.
- Fix 12 is a comment and documentation fix; the real behaviour (a Bearer POST needs `X-Requested-With`, and a foreign `Origin` is refused) was already right and is covered by `test-csrf-same-origin.php`.
- Fix 13: the door of the core is "every function that reaches the table, directly or through another function of the file", except the two public `profileItem*` helpers.

Follow-ups recorded from this work (NOT done):
- The profile outbox and cursor are not yet cleared at sign-out (that is 2c-3). The outbox can hold recent searches if the person switched that option on.
- (Fixed by review fix 1.) `history.searches` is only ever sent while the option is on, and now the SERVER checks that option too.
- Old production code with a stale settings box can still overwrite a newer value until the new code is promoted (as the plan said).
- The bridge copies a delete as the empty text on the old shelf; an old app reads that as "reset to default".

## 0. What the code changed about the old plan

1. **G3 is already done.** `accountErase()` already blanks the person's audit rows, so G3 is dropped.
2. **D3 cannot work as written.** Production runs PR 1 code until PR 2 is promoted, so a hook inside `user_settings` would never see production's saves to the `ihymns.web` settings box. Step 2b-4 replaces it.
3. **Follow-up 4 is settled by the code.** The Apple app adds a favourite with a one-item `merge` and removes one with `favorites_remove`, and it has no custom-tag code. A8 never touches either.
4. **The registry is missing the #2177 items.** It has no entries for recent songbooks, per-song key/capo/octave, or search history. Per-song settings also need a new "map" entry type, which means schema version 2 and a new Apple snapshot.
5. **Real storage shapes.**
   - Recent songbooks keeps 5, not 20.
   - Chord columns are saved as `'1'`.
   - The `ihymns.web` box holds text, while the registry holds real JSON types. So converters are needed in both PHP and JS, with a test that the two agree.
6. **Importing a backup never reaches the account.** `importUserData()` writes straight to local storage.
7. **The favourites actions use no transaction.** They need A1's per-person row lock.
8. **`popular_songs` hides failures.** It turns errors into an empty list, so an unchecked B1 column would quietly blank the home card.
9. **The privacy page is a shared, cached page.** It cannot word itself per site.
10. **The view history has no `(UserId, ViewedAt)` index.**

## 1. Rules for every step

- **The switch.**
  - Everything new checks `profileSyncEnabledHere()`, which reads `profile_sync_channels`.
  - The default is `'none'`. If the setting cannot be read, the answer is "off".
  - Unticking a channel brings back PR 1 behaviour there at once.
- **Never assume B1 has run.** Before touching each B1 object, check it exists, and remember the answer for the rest of the request.

  | Check | Object it covers |
  |---|---|
  | `profileItemsReady()` | `tblUserProfileItems` |
  | `userSyncItemTombstonesReady()` | `tblUserSyncTombstones` |
  | `songHistoryProvenanceReady()` | the three new history columns |
  | `apnsTokenHashReady()` | `ApiTokenHash` |

  If an object is missing, behave as PR 1 does. Pass up any error that ends a transaction (`songRelocateIsTransactionFatal`).
- **Refusals.** A refusal is a `409` with a reason: `profile_sync_disabled`, `profile_sync_not_ready` or `account_writes_off_here`. A request that is too big gets a `413`. Clients branch on the status and the reason, never on the wording (rule #35).
- **Web code.** `apiFetch()` only (rule #31), no inline scripts (rule #30).
- **API actions.** Each action needs all of these (rules #22, #48):
  - `getAuthenticatedUser()`;
  - on a POST, the `X-Requested-With` check plus `validateCsrfRequest(null)`;
  - the brake check before any write;
  - an `api-docs.yaml` entry in the same commit;
  - its logic in a shared core.
- **Checking a step.**
  1. Run the step's own test.
  2. Run `php tools/run-php-tests.php` and `node tools/run-node-tests.js`.
  3. Run `php -l` or `node --check` on each changed file.
  - Database tests use `IHYMNS_TEST_DSN`; CI already runs MariaDB 11.
  - To undo a step, run `git revert`.
- **Guards** take their lists from the code, never a typed list. Each guard must be shown to fail on a deliberately broken copy (rule #34), and that proof goes in the commit message.
- **Marks:** 🔴 needs an Opus review. 🟢 can be done in any order within its PR. 👤 needs the owner.

## 2. PR 2a: favourites stay removed, view counts, push per session

**2a-1 🟢 The switch.**
- **Code:** a new `includes/profile_sync_gate.php`, shaped like `account_write_gate.php`. It calls `channelSwitchEnabledHere(…, false)`.
- **Admin:** a `save_profile_sync` card on `/manage/configuration`, copied from `save_cross_site_sync`. It is mapped `web_only:configuration-secrets`.
- **Page:** `index.php` sets `profileSync.enabled` = switch on AND `profileItemsReady()`.
- **Test:** truth-table rows in `test-channel-switch.php`.
- **Mutation proof:** change the default to `'production'` and the test goes red.

**2a-2 🟢 The "B1 never assumed" guard.**
- **Where the checks live:** in a new `profile_sync.php`, `user_sync.php`, a new `song_history_provenance.php`, and `apns.php`.
- **Test:** a new `test-account-foundation-gating.php`. It reads the object names from the B1 migration's `CREATE TABLE` lines and `@migration-adds` tags. Every SQL string under `public_html` that names one of those objects must sit after the matching check, in the same function or `case`.
- **Mutation proof:** remove the check from `popular_songs` → red.

**2a-3 🔴 Delete markers for favourites and custom tags (E1, server).**
- **New in `user_sync.php`:**
  - `userSyncItemTombstoneWrite`, `…Map`, `…Clear` and `…List($since)`. The list uses `>=`.
  - The kinds list: `favorite` and `custom_tag`.
  - The PURE function `userSyncItemUpsertPlan($payloadIds, $addedIds, $tombMap, ?$since, $protocol2)`.
- **The rule.** A request that carries a `deleted` key is "protocol 2". For a song that has a delete marker:

  | Request | What happens |
  |---|---|
  | Protocol 2 | Skipped, unless the song is in `added` |
  | Old style, with `since` | Skipped if the marker is dated at or after `since` |
  | Old style, without `since` | Counted as a deliberate add, and the marker is cleared (this is how an old Apple app adds a favourite) |

  An add always clears the marker, and a delete always writes one, so the two never exist together.
- **`favorites_sync` order:**
  1. Check the brake.
  2. Begin, and lock the person's row with `FOR UPDATE`.
  3. Explicit deletes: write the marker even when no row exists, then delete the row.
  4. Explicit adds: clear their markers.
  5. Read the markers.
  6. Upsert, following the plan.
  7. Absence deletes, for old-style requests only. These now write markers too.
  8. Commit.

  Before B1 runs, the `deleted` ids are still removed, but no markers are written. The reply gains `tombstones` and `protocol`. The read-only reply on a paused site returns `tombstones` as well.
- **`favorites_remove`:** lock, delete, write a marker.
- **`custom_tags_sync`:** the same rule, server side only.
- **Tests** (`test-user-sync-guard.php`):
  - the plan's truth table;
  - "device A removes, device B sends an older merge" → the song stays removed;
  - a two-connection database test that a row and its marker never exist together.
- **Mutation proofs:** change `>=` to `>`; drop the lock; skip clearing the marker on an add. Each turns a test red.
- **Docs and undo:** update `api-docs.yaml`. To undo, untick the switch.

**2a-4 🔴 The web sends what it added and deleted (E2).**
- **Files:** `favorites.js` and `user-auth.js`, plus new keys `STORAGE_FAVORITES_ADDED` and `STORAGE_FAVORITES_DELETED`. Both keys join `PERSONAL_DATA_KEYS`, so they are stashed with the rest.
- **Recording:** `toggle`, `clearAll` and bulk removal record ids even when signed out, capped at 500 each. They follow the `setlist.js` pattern.
- **Sending:** `syncFavorites()` always sends both lists, and clears only the ids it actually sent.
- **Applying the reply:** `_absorbFavoritesSync()` drops `tombstones` before merging, but never drops an id still waiting in "added".
- **Test:** jsdom, `tests/test-favorites-tombstones.js`.
- **Mutation proof:** merge before dropping → red.

**2a-5 🟢 Import goes through the stores (E3).** "Replace" queues each removed favourite and set list as a delete, matched by id. Both modes then save with `saveAll()`. Test: jsdom.

**2a-6 🟢 Count real views once, from the live site (F1).**
- **New `song_history_provenance.php`:**
  - `songHistoryProvenanceValues($body)` returns Source `'view'`, Channel = `ihymns_environment()`, and the Platform the app sent (checked against the allowed list).
  - `songHistoryCountableSql()` returns `Source='view' AND (Channel='production' OR Channel IS NULL)` when the switch is on and the columns exist, otherwise `1=1`.
- **Used by:** `song_view`; backfill, which writes `'backfill'`; `popular_songs`; and the CCLI view count.
- **Rate limits (only with the switch on):**
  - `song_view`: 60 a minute and 3,000 a day (`enforceReadRateLimit`);
  - backfill: 10 an hour per person (`checkRateLimit` + `recordRateLimitHit`).
- **Test:** `test-song-history-provenance.php`.
- **Mutation proof:** remove the helper from `ccli_report.php` → red.

**2a-7 🟢 "Your most viewed" (F2, #2175).**
- **Request:** `GET ?action=my_most_viewed&period=30d|90d|12m|all`.
- **Replies:**
  - 200: `{songs:[{songId,title,number,songbook,songbookName,language,views}], period}`;
  - 401;
  - 409 `profile_sync_disabled`;
  - 400 `invalid_period`;
  - 429.
- **Default period:** the person's `home.mostViewedPeriod`, otherwise `12m`.
- **Query:** like `song_history`, filtered by `UserId = ?`, `songVisibleSql` and `songServableSql`, counting all sources and channels. Take `LIMIT 60`, apply the language filter, and keep the top 20.
- **Card:** a new `$homeCard('my-most-viewed')`, filled in by `home-page.js`. It has a labelled period picker, saved as `ihymns_most_viewed_period`.
- **Mutation proof:** drop `UserId = ?` → red.

**2a-8 🟢 Push registrations tied to the sign-in (G4).**
- **`apns_register`:** stores `ApiTokenHash`, including when it updates an existing row.
- **New helper `apnsDeleteForSessions()`:** deletes only the rows for the sessions being signed out. Rows with no hash keep the A6 rule.
- **Who calls it:** `auth_logout`, and `device_signout`. `device_signout` must read the full hashes for its prefix **before** it deletes them.
- **Guard:** no blanket `DELETE FROM tblApnsTokens WHERE UserId = ?` anywhere else.
- **Mutation proof:** add that blanket delete back → red.

## 3. PR 2b: one sync method for small personal items (phase D)

**2b-1 🟢 Registry version 2.**
- **New `map` type:** key `'*'`, plus `itemKeyPattern`, `item` and `maxItems`. A section that has a `'*'` entry has no other entries. `profileRegistryEntry()` falls back to `section.*`.
- **New entries:**

  | Entry | What it holds | Web key | Claimed at sign-in? | Cleared at sign-out? |
  |---|---|---|---|---|
  | `library.recentSongbooks` | up to 20 abbreviations | `ihymns_recent_songbooks` | no | yes |
  | `songPrefs.*` | SongId → `{transpose -11..11, capo 0..11, octave -2..2}`, up to 2,000 | none | no | no |
  | `privacy.syncSearchHistory` | on/off, default off | none | – | – |
  | `history.searches` | up to 20 strings of up to 100 characters | none | no | yes |

- **Also:** give `home.mostViewedPeriod` the web key `ihymns_most_viewed_period`.
- **Then:** bump the version to 2 and regenerate the Apple snapshot (`tools/dump-profile-schema.php`).
- **Guards:**
  - `test-profile-registry.php` learns the map rules. Mutation proof: put a `'*'` entry beside a fixed entry → red.
  - The lockstep test checks that every web key cleared at sign-out is in `PERSONAL_DATA_KEYS`, and that every web `profile_sync` entry has a web key or an adapter.

**2b-2 🔴 The sync core (D1).**
- **PURE function:** `profileSyncPlan($rows, $changes, $maxVersion, $platform, $counts)` decides what happens to each change.
- **Rejected changes:** each comes back with one of these reasons:
  - `unknown_key`
  - `device_only`
  - `not_profile_item`
  - `invalid_value`
  - `invalid_item_key`
  - `value_too_large` (over 4 KiB)
  - `cap_reached`

  When a section is full, a new item is refused and nothing is evicted.
- **Accepted changes:**
  - An unchanged value writes nothing. The comparison decodes both values and re-encodes them with sorted keys, because MariaDB stores JSON as text.
  - A delete leaves a marker.
  - Version numbers go up by one per change, in the order the changes arrived.
- **`profileSyncApply()`:**
  1. Begin, and lock the person's row with `FOR UPDATE` (the same lock A1 takes).
  2. Read the highest version, the section counts and the touched keys in three queries. Build the `IN` list from placeholders (rule #5).
  3. Write at most 200 upserts, recording `UTC_TIMESTAMP(3)`.
  4. Commit.
- **`profileSyncPull($uid, $cursor, 500)`:** reads `Version > ?` in version order and skips `_meta`. It sets `more` when there is another page. A cursor that is too high gets `resetCursor:true`.
- **Tests** (`test-profile-sync-core.php`):
  - truth table: the later change wins; an equal value keeps its version; a delete reaches another cursor; an unknown key is refused while the rest are stored; over the cap is refused; the cursor only goes up;
  - a database test where two connections write in turn and lose nothing.
- **Mutation proofs:** `>` becomes `>=`; no lock; a device-only key is accepted. Each turns a test red.

**2b-3 🟢 The endpoint (D2).**
- **`case 'profile_sync'`:**
  - GET `?cursor=` reads.
  - POST takes `{schemaVersion, client:{platform,appVersion}, cursor, changes:[{section,key,op,value}]}`, then reads.
- **Replies:**
  - 200: `{ok, cursor, more, schemaVersion, changes, rejected, applied}`.
  - 400: `invalid_request`.
  - 401, 403, 405.
  - 409: `profile_sync_disabled` or `profile_sync_not_ready`, or `account_writes_off_here` on POST only. GET still works on a paused site.
  - 413: `body_too_large` (over 64 KiB) or `too_many_changes` (over 200).
  - 429.
- **Also:** add `api-docs.yaml` and `test-profile-sync-endpoint.php`.

**2b-4 🔴 Keep the old settings box and the profile in step (replaces D3 and D4).**
- **PURE converters:** `profileWebDecode()` and `profileWebEncode()`. A true/false value is read from `'true'` or `'1'` and written as `'true'` or `'false'`.
- **Old box → profile (lazy).**
  - On every `profile_sync` call, while saving is allowed, `profileSyncReconcileWebShelf()` runs under the lock.
  - It compares a hash of the box with `_meta/webShelfHash`. If the hash changed, it copies each `cookie:true` value that differs into the profile.
  - It never deletes because a key is missing.
  - Its first run is the one-time import.
- **Profile → old box (eager).**
  - Each accepted `cookie:true` write is copied into the box through a new `userSettingsMutateLocked()`. That keeps A1's single-writer guard.
  - The hash is then refreshed, so the copy does not bounce back.
- **Tests:**
  - PURE tests that a change made by old code appears in the next read, that a copy does not bounce back, and that a missing key never deletes;
  - a JS lockstep test comparing the PHP converter (`php -r`) with the JS one on the same sample values.
- **Mutation proof:** let a missing key delete → red.

**2b-5 🔴 The web client (D5).**
- **New modules:** `profile-sync.js` and `profile-sync-adapters.js`. Keys are mapped from the downloaded `profile_schema` (cached by ETag), never from a typed list.
- **Saved on the device:**
  - `ihymns_profile_outbox`, the unsent changes;
  - `ihymns_profile_cursor`, how far the device has read.

  Both are keyed by person and dropped when the device changes hands (A7).
- **`app.syncStorage(key, {origin})`:**
  - `'local'` (the default) updates the cookie and the outbox;
  - `'account'` updates the cookie only, so values from the account are never sent back.
- **Sending:** 1.5 s after the last change. Each entry carries a `seq` number.
- **Reading:**
  - at sign-in, and when the tab comes back after 30 minutes (`createPullGate`);
  - applies everything except keys still in the outbox;
  - on the first sync, claims the `claim:true` values the account lacks;
  - drops replies meant for someone else.
- **Paused site:** on a `409 account_writes_off_here`, keep the outbox and wait 5 minutes.
- **Settings page:** `settings.js` stops its old box push and pull while this is active.
- **Tests:**
  - jsdom: an unsent change survives a read, and an applied value is not echoed;
  - `test-settings-sync-cookie.js` now also requires every write to a profile key under `js/` to call `syncStorage`, with the keys taken from the registry dump.
- **Mutation proofs:** apply a read over the outbox → red; remove a `syncStorage` call → red.

**2b-6 🟢 Sections, one commit each (D7–D9).**
- **Chords:** `transpose.js` calls `syncStorage`, and accepts `'1'` or `'true'`.
- **Recent songbooks:** `trackRecentSongbook` calls `syncStorage`. The key joins `PERSONAL_DATA_KEYS`.
- **Per-song key, capo and octave:** a map adapter over the three key prefixes. All three at zero means delete.
- **Search history:** a Settings switch, "Keep my recent searches in my account", off by default.
  - While it is on, the whole list is sent.
  - Turning it off deletes the server copy.
  - At sign-in, the account's list replaces the device's.

## 4. PR 2c: privacy and the account (phase G)

**2c-1 🔴 "Download my data" (G1).**
- **Request:** `POST ?action=account_export` returns a JSON file.
- **Replies:** 200, 401, 403, `409 profile_sync_disabled`, or 429 (5 an hour).
- **Core:** `includes/account_export.php`.
  - It follows `accountEraseFkGraph()` to every table linked to the person, taking at most 10,000 rows per table and marking any table it cut short.
  - It adds the reviewed no-link list `ACCOUNT_EXPORT_NO_FK` (for example `tblActivityLog.UserId`), the settings box and the profile items.
  - Some tables are excluded with a stated reason. `tblLoginAttempts` is one: it is keyed by username text and may hold other people's addresses.
  - It refuses secret columns by name: password and token hashes, refresh tokens, enrol keys, push tokens and keys.
  - Table and column names come from the database catalogue and must match `^[A-Za-z0-9_]+$`.
- **Web:** a "Download everything we hold about you" button in Settings → Account.
- **Guard:** `test-account-export-coverage.php` checks that:
  - every link in `schema.sql` is exported, or excluded with a reason;
  - every user-id column with no link is listed;
  - every exported column matching `token|hash|secret|password|refresh|p256dh|pushtoken|keyhash` is on the deny list.
- **Mutation proofs:** add an unlisted table → red; remove `PasswordHash` from the deny list → red.

**2c-2 🟢 Delete your account on the web (G2).** A Settings dialog posts to the existing `account_delete` with the password (or `confirm:'DELETE'` where the server allows it). A `409 account_writes_off_here` reply is explained in plain words. Test: jsdom.

**2c-3 🔴 Sign-out clears this browser, asking first (G5, O2).**
- **Send what is waiting first.** `logout()` waits on new `flushNow()` methods in favourites, set lists, settings and profile sync.
- **Then count what is still unsent:**
  - the profile outbox;
  - favourite adds and deletes;
  - set-list deletes;
  - `offlineQueue.count()`;
  - `settings._syncPushPending`.
- **Ask if anything is left.** `app.showChoice()` says "N changes have not reached your account yet". The answers are *Try again* (up to 3 times), *Sign out anyway*, or close the dialog to stay signed in.
- **Clear.** The new `clearPersonalDataAtSignOut()` removes `PERSONAL_DATA_KEYS`, the bookmarks of how far each sync has got, the owner id, the outbox and cursor, and search history if it was synced. Display settings stay.
- **Switch off:** sign-out behaves as in PR 1.
- **Test (jsdom):**
  - unsent changes → the dialog appears, and nothing is cleared until the person confirms;
  - nothing unsent → data is cleared with no dialog;
  - display settings survive.
- **Mutation proof:** skip the count → red.

**2c-4 🟢 The privacy page (G6).** Update sections 2.1, 2.2, 9 and 10:
- view history and "Your most viewed";
- searches are no longer linked to you;
- the search-history option;
- export, and deletion on the web (drop "planned");
- one account across all three sites;
- what sign-out clears.

👤 The page is cached, so promote this change together with ticking production.

**2c-5 Docs (J).** A CLAUDE.md rule, the wiki, `.OpenAI/`, `CHANGELOG.md` and `WHATS-NEW.md`, timed with the production tick.

## 5. How items could be duplicated, brought back or lost

| Risk | What stops it |
|---|---|
| **Duplicates** | Every item has a fixed identity (section and key, SongId, tag), and writes are upserts. Rows and markers are exclusive under the person's row lock. |
| **A removed favourite comes back** | **New web:** markers are applied before the merge. **Old production web:** only partly fixed (a request with `since` skips newer markers), so 👤 promote 2a quickly. **Old Apple:** a merge without `since` is a deliberate add, and `favorites_remove` now leaves a marker. |
| **A deleted setting comes back** | Only changed items are ever sent. |
| **Loss** | No new code deletes because something is missing. The box copy never deletes. Over the cap, a change is refused and kept on the device. The outbox survives offline. Sign-out asks first. |
| **Clock skew** | Versions set the order. Markers use the database clock (`userSyncNow`). No device clock is ever compared. |
| **Two devices change one item** | The later arrival wins, which the review accepted. Old production code with a stale box can overwrite, exactly as it can today, until it is promoted. |
| **Cookie (#2178)** | Values from the account are marked `origin:'account'`. Values from the cookie are never pushed. The account wins at the next read (#2143 decision D8). |
| **Paused site** | GET reads, POST gets a 409, and the read-only favourites reply includes markers. |
| **Shared device (A7)** | New pending keys and recent songbooks are stashed. The outbox and cursor are per person. |
| **A8** | Unchanged. |

**Speed:** favourites add two key-range reads and a row lock; `profile_sync` is about 5 queries plus up to 200 writes, read in pages of 500; "most viewed" scans `idx_User`; export runs one indexed query per table.

## 6. Order, owner actions and how to split the PRs

**Order.**
- **2a:** 2a-1, then 2a-2, 2a-3, 2a-4; then 2a-5 to 2a-8 in any order.
- **2b:** 2b-1 to 2b-5 in order; then 2b-6 in any order.
- **2c:** 2c-1, 2c-2 and 2c-4 in any order; then 2c-3; then 2c-5.

**Owner actions.**
- 👤 Check that the B1 card shows as applied. If it does not, check the MySQL version, take a backup, then run it.
- 👤 For each PR: tick alpha, run the old plan's device checks (its section 6), then tick beta.
- 👤 Promote each PR promptly. Tick production last.

**How to split.** Three PRs, one after another, with one working branch at a time.
- **2a (`fix:`):** fixes H1, the problem people actually notice. It needs no profile sync, and it must reach production early, because old production code is the main cause of favourites coming back.
- **2b (`feat:`):** the riskiest core.
- **2c (`feat:`):** needs both.

**Opus review:** 2a-3, 2a-4, 2b-2, 2b-4, 2b-5, 2c-1 and 2c-3.

**Deferred.**
- **D6:** these already have locked boxes, the server reads `ihymns.push` directly, and no app needs them.
- **D10:** the shared sign-in cookie is sign-in work, so it moves to PR 3.

## 7. Questions the code could not settle (defaults taken; none blocks)

| # | Question | Default |
|---|---|---|
| 1 | Should export and web deletion sit behind the switch too? | Yes, taking "a deploy changes nothing" literally. I recommend un-gating them later, because the privacy page already promises both. |
| 2 | Should an old-style merge without `since` clear a marker? | Yes. Otherwise an iPhone user could never re-favourite a song. |
| 3 | What limits should `song_view` have? | 60 a minute and 3,000 a day, with any 429 ignored. This may undercount large churches that share one address. |
| 4 | Should personal "most viewed" count test sites and uploaded views? | Yes. It is one account everywhere. |
| 5 | Should we add a `(UserId, ViewedAt)` index? | Not yet. Measure first, because it needs a second migration. |
| 6 | Should recent songbooks sync as one whole list, the latest winning? | Yes. Losing one entry is harmless. |
| 7 | What happens to per-song settings at sign-out, at sign-in and at the cap? | Kept at sign-out, not claimed at sign-in, refused past 2,000. |
| 8 | Should the search-history option follow the person? | Yes, off by default. Turning it off deletes the server copy. |
| 9 | May we edit `legacyWebKey` on an existing entry? | Yes, once, because nothing reads it yet. |
| 10 | What should happen to deletion on a paused site (follow-up 2)? | Keep the 409 and explain it. |
| 11 | Should admin analytics filter by channel? | No. Only "popular" and CCLI filter. |
| 12 | Should the CCLI report drop test-site and uploaded views? | Yes, behind the switch. |
| 13 | What about follow-ups 1 (iLyricsDB) and 3 ("all languages")? | Out of scope. Both still need issues. |
