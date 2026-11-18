# Review: user accounts, profiles and how personal data syncs (iHymns)

**Scope and method.** I read the code and changed nothing. The other session kept committing while I read (`31ec63c`, `68222cd`, `bda74e8` on `claude/cross-site-settings-sync-2143`, with `settings-sync.js` not yet committed). So every citation gives **text to search for** as well as an approximate line number. Each finding says whether I traced it in the code or am only inferring it. I reproduced nothing on a real device.

---

## 0. Summary in plain words

- **The server side is in better shape than the sync rules.**
  - Every kind of personal content has its own proper table.
  - Set lists already have a good sync method ("protocol 2"). A device names exactly what it deleted, the server keeps a permanent "this was deleted" marker (a **tombstone**), and the server refuses an edit that would overwrite a newer one.
  - Account erasure is worked out automatically from the database's own links between tables, not from a hand-kept list.
- **The weakness is that every kind of data syncs by its own private rules.** There are about seven different methods. None of them is described anywhere a native app could read it.
  - Removed favourites come back.
  - On a shared computer, one person's data merges into the next person's account.
  - The iPhone app can delete a set list made on the web.
  - Two saves to the settings store can overwrite each other.
  - There is no "download my data".
- **Verdict: do not rewrite everything at once.**
  - First, make about seven small safety fixes.
  - Then build **one per-person profile model** on top of what already exists:
    - one server-owned **registry**: a single list, defined in one place, of every kind of personal data and its rules, which any app can download;
    - one new store for small per-person items;
    - one sync method based on each item's identity, a server-kept change counter, and delete markers.
  - Content tables (favourites, set lists) stay where they are. Favourites get the delete markers set lists already have.
  - Native apps use the same `/api` from day one. For them the signed-in account is the only route, because they have no browser cookie.

---

## 1. Current-state map (with evidence)

### 1a. Who you are: accounts, sign-in, sessions

| Item | Where it is stored | Endpoints | Evidence | Notes |
|---|---|---|---|---|
| Account | `tblUsers` (`Username`, `Email`, `DisplayName`, `Role`, `AccessTier`, `Ccli*`, `Status`, `Settings` JSON, `PreferredLanguagesJson`, `AvatarService`) | `auth_register`, `auth_login`, `auth_email_login_*`, `auth_apple`, `auth_update_profile`, `auth_change_*`, `account_delete` | `schema.sql` ~930 `CREATE TABLE IF NOT EXISTS tblUsers` | No channel column, so one account serves live, beta and dev |
| Sign-in token | `tblApiTokens` (sha256 hash, `DeviceName`, `Platform`, `AppVersion`, `LastSeenAt`, `ExpiresAt`) | every authed action; `devices_list`, `device_signout`, `device_rename` | `api.php` ~28185 `function getAuthenticatedUser`; 30-day sliding expiry, text `Sliding expiry (#390)` | Valid on all three sites. Device details are read from the login body (`apiTokenDeviceMetaFromBody`, `includes/api_tokens.php` ~121) |
| Web cookie | `ihymns_auth`: HttpOnly, Lax, Secure, `Domain=.ihymns.app` | cookie fallback in `getAuthBearerToken` (`api.php` ~28081, text `Cookie fallback (#390)`) | `includes/auth_cookie.php` ~94 | Reaches all channels. HttpOnly means page scripts cannot read it |
| Web token copy | localStorage `ihymns_auth_token`, `ihymns_auth_user` | — | `user-auth.js` ~160 `saveCredentials` | Kept per host. `isLoggedIn()` only looks here (~129) |
| Apple token | Keychain service `app.ihymns.token`, `synchronizable: true` by default | Bearer | `KeychainTokenStore.swift` ~61 | Deliberate "global login" through iCloud Keychain: one token shared by all of a person's Apple devices. The environment switch (`APIEnvironment.swift` ~83-89) keeps the same token, so it is the same profile on dev, beta and live |
| tvOS pairing | `tblAuthDeviceCodes` (has a channel column) | `auth_device_code_*` | `schema.sql` ~1035 | Correctly walled per channel |
| Apple identity | `tblUserAuthProviders`, `tblAuthNonces` | `auth_apple`, `auth_provider_unlink` | `schema.sql` ~5345 | — |
| Push (web) | `tblPushSubscriptions` (**no channel**) | `push_subscribe` / `_unsubscribe` | `schema.sql` ~2042 | — |
| Push (Apple) | `tblApnsTokens` | `apns_register` / `_unregister` | `schema.sql` ~4467 | — |
| Push (Android) | `tblPushTokens` (dormant, nothing sends yet) | — | `schema.sql` ~5785 | — |

### 1b. Personal data that is stored on the server

| Data | Storage | Endpoints | Web module | How it syncs | Limits | Has delete markers? |
|---|---|---|---|---|---|---|
| Display settings (14 keys) | `tblUsers.Settings` → namespace `ihymns.web` (a namespace is a named section of the settings store) | `user_settings` GET/POST (`api.php` ~4695) | `settings.js` (`_pushSyncedSettings` ~265, `_pullSyncedSettings` ~348) | Whole section replaced; last writer wins; the pull applies account values over local ones | 16 KB per section, 256 KB total (`user_settings.php` ~112/127) | No |
| List sort orders | same store, namespace `list_sorts` | same | `list-sort.js` ~230/306 | Whole section replaced | 16 KB | No |
| Push preferences | same store, namespace `ihymns.push` (the **server reads it**: `web_push.php` ~241) | same | `push-notifications.js` ~407/440 | Whole section replaced | 16 KB | No |
| Card layouts | same store, top-level key `cardLayouts` (**a second, separate writer**) | `card_layout_*` | `card-layout.js`; server `includes/card_layout.php` ~312-380 | Its own read-modify-write | none | No |
| iLyricsDB | same store, namespace `ilyricsdb` | same | (another product) | Whole section replaced | 16 KB | No |
| Language choice | `tblUsers.PreferredLanguagesJson` (a separate column) | `user_preferred_languages`, `_save` (`api.php` ~11100/11174) | `songbook-language-filter.js` ~481, `settings-language-filter.js` ~72 | The account overwrites local on boot | 32 tags | No |
| Favourites + per-favourite tags | `tblUserFavorites` (link to `tblSongs`) | `favorites`, `favorites_sync`, `favorites_remove` (`api.php` ~6634-6929) | `favorites.js`, `user-auth.js` | "merge" mode (combine) or "replace" mode, plus a `since` **watermark** (the server's own time stamp from the client's last sync, so newer rows are never deleted) | 500 (extra items stay on the device, with a warning) | **No** |
| Set lists (+ service plan) | `tblUserSetlists`, `tblUserSetlistTombstones` | `user_setlists`, `user_setlists_sync` (~3948/4069) | `setlist.js`, `user-auth.js` | **Protocol 2**: an explicit `deleted` list, permanent delete markers, `since`, and a refusal to overwrite a newer edit (#1675) | 4 MiB request; a songs-per-list limit rejected with 413 | Yes |
| Custom tag pool | `tblUserCustomTags` | `custom_tags`, `custom_tags_sync` | `favorites.js` | Merge only on the web (no way to remove a tag) | 200 | No |
| Recently viewed | `tblSongHistory` (one row per view, kept forever) | `song_view` (anonymous allowed), `song_history` (latest 50), `song_history_backfill`, `song_history_clear` (~10544-10893) | `history.js`, `router.js` ~1119 | Every view is added. The device's own list is combined with the server's. A one-time upload of older views is guarded by a flag kept per **device**, not per account | 20 on the device, 50 shown from the server | Clear = delete rows |
| Private song notes | `tblUserSongMarkup` | `user_markup_*` | `song-markup.js` | Each item written directly | — | — |
| Shares and collaborators | `tblSharedSetlists`, `tblSetlistCollaborators` | `setlist_share*`, `setlist_collab_*`, `setlist_token_update` | `setlist.js` | Rule #40 share tokens | — | Revoked, expired |
| Song requests, notifications, schedules, print usage | own tables | various | — | Each item written directly | — | — |
| Searches (people do not know about this) | `tblSearchQueries` (`Query`, `UserId`) | written by `search` (~1162, text `INSERT INTO tblSearchQueries`) | — | Every first-page search is logged with the user's id. **Nothing ever reads the `UserId` column** (I searched the whole tree) | none | Kept forever |
| Audit log | `tblActivityLog` (IP address, browser, country, Details JSON that often holds the username) | `logActivity` | — | — | — | Kept |

### 1c. Data kept only on the device

- **Web (localStorage):**
  - search history (`ihymns_search_history`);
  - recent songbooks;
  - per-song `ihymns_transpose_*`, `ihymns_capo_*`, `ihymns_octave_*`;
  - `ihymns_chord_notation`, `ihymns_chord_columns`;
  - analytics consent;
  - offline-download choices;
  - MIDI pedal;
  - banner and disclaimer flags;
  - the sync bookkeeping keys;
  - `ihymns_owner_id` (anonymous share ownership).
- **Apple (UserDefaults):**
  - `ihRecentlyViewedSongs` (`RecentlyViewedStore.swift`);
  - `ihRecentCatalogueSearches`;
  - `ihAppearanceTheme`, `ihCvdMode`, `ihDyslexiaReadingModeEnabled`, `ihAnalyticsConsentEnabled` (`IHSettingsStore.swift` ~200);
  - `ihLyricsTextScale`, `ihLyricsShowChords` (`SongDetailView.swift` ~95).
- **Android (SharedPreferences):** file `ihymns_prefs`, key `favourite_song_ids` (`SongViewModel.kt` ~60). That is all.

### 1d. Native apps today (asked for by the owner)

**Apple:** these are all the actions it calls, found by searching for `action: "` under `Sources/`.

| Area | What it does | Gap |
|---|---|---|
| Favourites | `favorites_sync` in **merge** mode, one item per tap; `favorites_remove` to delete | fine |
| Set lists | `user_setlists_sync` in **replace** mode with **no `since` and no `deleted`** (`SetlistsEndpoints.swift` ~125 `struct SetlistsSyncRequestBody { mode, setlists }`; `AppRootViewModel+Setlists.swift` ~232) | **Protocol 1**: the server treats "missing from the list" as "deleted" |
| Settings | none (no `user_settings`) | theme, colour filter, text scale and chords stay on the device |
| Language | none (no `user_preferred_languages`, no language header) | — |
| History | no `song_view`, no `song_history` | iPhone views are missing from your history, from "popular" and from any future "most viewed" |
| Account | `auth_login`, `auth_email_login_*`, `auth_me`, `auth_logout`, `account_delete`, `apns_*`, `analytics_ingest` | does not send `deviceName`, `platform` or `appVersion` at sign-in, so the device list cannot label Apple sessions |

**Android:** no network calls and no account at all. It reads bundled song files, and favourites live only in SharedPreferences.

### 1e. Changing devices, signing in and signing out

- **Anonymous → signed in ("claim").**
  - Favourites, set lists and custom tags: everything on the device is merged into the account on **every** page load of a session (`triggerUserDataSync`, `user-auth.js` ~2471). Nothing records whose data it was.
  - History: a one-time upload, guarded per device.
  - Settings: the account wins. Local values are only uploaded when the person next changes a setting.
  - Language: the account wins, and an empty account wipes the local choice (see M2 in section 2).
  - List sorts: the account copy is mirrored onto the device.
- **Sign-out.** `clearCredentials()` (~173) removes only the token and the user record. **Every other piece of personal data stays on the device** (traced: I found nothing that clears it on sign-out).
- **Several devices.**
  - Set lists (protocol 2): good.
  - Favourites: deletes can be undone (see H1).
  - Settings: the whole section is overwritten, last writer wins.
  - History: "clear" does not reach other devices' local copies. This is accepted in the code comments.
- **Export.**
  - Web: `exportUserData()` (`settings.js` ~1180) saves only the favourites, set lists and history **held on this device**.
  - There is no server-side export, and Apple has none at all.
  - The privacy page (~341) promises an export of "your profile, favourites, and set lists".
- **Deletion.**
  - Apple has it (`account_delete`). No web code calls it; the privacy page says a web option is "planned".
  - Admins can erase an account at `/manage/users`.
  - `accountErase()` (`account_lifecycle.php` ~484) works out what to delete from the database's own links to the user. Tokens, favourites, set lists, tags, push records, notes and settings are deleted. History, search and usage rows are unlinked from the person.
  - **It misses** the IP address, browser and country in `tblActivityLog`, and usernames inside its `Details`. `tblActivityLog` is not on the unlink list (`accountEraseBehaviouralColumns` ~159), so its rows stay pointing at the blanked account, with the IP and username still in them.
  - It also cannot reach columns that point at a user without a database link. Most are admin attribution: `tblLyrics.SubmittedBy`, `tblApiKeyRequests.ReviewedBy` and others.

### 1f. Who can read what

| Reader | What they can see |
|---|---|
| The person | everything of their own |
| Global admins | profile fields (`manage/users.php`), the activity log (with IP addresses), and combined analytics (top search texts, top songs). No admin page reads any individual's favourites, set lists, history or settings (searched for every `FROM tblUser*` / `tblSongHistory`) |
| Org admins | their organisation's CCLI usage report. Per-user view counts there are always 0 (`ccli_report.php`). I found no org-admin access to members' personal data |
| Share-link holders | the shared set list; the sharer's name only if they opted in (rule #40) |
| Set-list owners | their collaborators' email addresses (they invited them by email) |

---

## 2. Defects and gaps, ranked

"Traced" means I followed the code path end to end. None of these were reproduced on a device.

### High

**H1. Removed favourites come back (traced).**
- **What goes wrong:** Anna removes a favourite on her laptop, and the server row is deleted. Her phone's browser still has it in its local copy. On the phone's next page load, `triggerFavoritesSync()` (`user-auth.js` ~2423) sends the phone's whole list in **merge** mode. The upsert loop (`api.php` ~6806, text `Upsert each payload favourite`) writes it back. The laptop pulls it back too.
- **Same cause, bigger effect:** "Clear all favourites" (`favorites.js` ~453) is undone the same way.
- **Root cause:** favourites have no delete markers. Set lists got them in #1661; favourites never did.

**H2. On a shared device, one person's data leaks into the next person's account (traced).**
- **What goes wrong:** Ben signs out of the church laptop, and his favourites, set lists and tags stay in localStorage. Carol signs in, and `triggerUserDataSync` merges Ben's data into **Carol's** account. Her "Recently viewed" also shows Ben's songs.
- **Why it is serious:** it is a privacy leak and it damages data, and nothing marks whose data it was.

**H3. The Apple app can delete set lists made elsewhere, and overwrites edits (traced).**
- **What goes wrong:** Anna makes the set list "Easter" on the web. Her iPhone last refreshed before that. She edits any set list on the iPhone, and `pushCurrentSetlistsToServer()` sends a **replace** with no `since` and no `deleted`. `userSyncDeletableIds()` (`user_sync.php` ~257) treats "Easter" as deleted because it is missing from the phone's list.
- **Edits are lost too:** with no `since`, the refusal to overwrite newer edits never runs (`userSyncRowNewerThanWatermark` returns false when `since` is null, ~400). The whole set list is simply last writer wins.

**H4. Two saves to the settings store can overwrite each other (traced).**
- **What happens:** both the namespaced `user_settings` write (`api.php` ~4818, text `Read-modify-write of ONE subtree. Not a transaction`) and `cardLayoutSaveUserOverride()` (`card_layout.php` ~312-336) read the **whole** settings store and write the **whole** store back, with no lock.
- **The comment is wrong:** it says "a race between two PRODUCTS is no longer possible at all" (a race is two requests landing at the same moment and interfering). But two saves to *different* sections that land at the same moment still lose one of them.
- **Who can hit it:** web settings, list sorts, push, card layouts and iLyricsDB all write this one store.

### Medium

**M1. Settings are re-fetched on every page change, and a quick change can be lost (traced; timing-dependent).**
- **Why it happens:** the router sends a "sign-in state changed" event (`EVT_AUTH_CHANGED`) after **every** page change (`router.js` ~1294, text `broadcast the current auth state`). `settings.js` (~211) responds by fetching account settings and writing them over local ones. The upload of a local change waits 1.5 s (`setTimeout(() => this._pushSyncedSettings(), 1500)` ~262).
- **What goes wrong:** change the theme, then navigate within 1.5 s. The old account value overwrites the new choice, and the delayed upload then sends the old value.
- **Same pattern in `list-sort.js`** (~329), which re-fetches on every page change. That is two extra requests per page for every signed-in user.

**M2. A visitor's language choice is wiped when they sign in for the first time (traced).**
- **What goes wrong:** the server returns `languages: []` both when nothing was ever saved and when the person chose "all languages" (`api.php` ~11140, text `$languages = [];`). It does the same when the column is missing on an install that has not been migrated. The client adopts the empty list, and `saveSubtags([])` deletes the local choice (`songbook-language-filter.js` ~111).

**M3. Privacy promises the code does not keep.**
- There is no server-side export, but the privacy page promises one (`privacy.php` ~341).
- The web has no self-service deletion (~342).
- Apple has no export.

**M4. Personal server-side logging that is not disclosed (traced).**
- Every search by a signed-in person is stored with their id, and nothing uses that id (H/M: easy fix).
- Every view is kept forever.
- The privacy page says recent searches stay on the device (§2.2), and that synced content means only favourites and set lists (§2.1).
- Note that #2177 calls search history "the highest privacy cost". That cost is already being paid today, with no benefit to the person.

**M5. Erasure leaves IP addresses and usernames in the audit log (traced).** See 1e. Example: `auth.logout` logs `'username'` (~3907), and `IpAddress` is stored on every row.

**M6. Signing out on the web stops the iPhone getting notifications (traced).** `auth_logout` runs `DELETE FROM tblApnsTokens WHERE UserId = ?` (`api.php` ~3880). That removes every Apple device's push registration, not just this session's.

**M7. One history table does four unrelated jobs** (personal history, "popular", the CCLI report view counts at `ccli_report.php` ~250, and admin analytics):
- "Clear history" **deletes** rows, so it silently lowers popularity and CCLI counts.
- The one-time upload re-inserts views that were already counted anonymously, so they are counted twice.
- There is no rate limit on `song_view` or `song_history_backfill`, so anyone can push a song up "popular".
- Views from the beta and dev sites count in live's "popular" and the CCLI counts.

**M8. "Half signed in" on another subdomain (traced).**
- **What goes wrong:** sign in on www, then open beta. The domain-wide cookie signs in every API call (`getAuthBearerToken` cookie fallback), so views and searches are linked to the person. But the page has no localStorage token, so it shows "signed out" and never syncs.

**M9. The old whole-store settings save still works.** A save without a namespace replaces the entire store (`api.php` ~4865, text `Legacy whole-blob replace`). That wipes `ihymns.push`, which the server uses to decide notifications, plus `list_sorts`, `cardLayouts` and `ilyricsdb`. No current client sends it (the web uses `ihymns.web`; Apple does not call it). It is still reachable through the published API.

### Low

- **L1.** "Import → Replace existing" (`settings.js` ~1238-1267) does not really replace anything for a signed-in person. Removed items come back on the next load, because it never records them as deletes.
- **L2.** Values pulled from the server are never checked against the allowed values. A value only dev understands can reach live through the account.
- **L3.** The whole-section save means a live client silently drops settings keys that only a newer channel (dev or beta) knows about.
- **L4.** The token is copied into localStorage, which undoes the cookie's HttpOnly protection if any script on the page is ever compromised. The enforcing script policy (CSP, #117) makes that unlikely.
- **L5.** `tblPushSubscriptions` has no channel column.
- **L6.** Apple: no view recording, no settings or language sync, no device details at sign-in. The shared iCloud token means signing out on one Apple device signs out all of them, and they show as one session.
- **L7.** Android has no account at all.
- **L8.** `api-docs.yaml` (~19305) says `user_settings` is "the **only** user-preference store". That is false: `PreferredLanguagesJson` is another, and `cardLayouts` cannot even be read by namespace, because its capital "L" fails the name rules (`userSettingsNamespaceValid` requires lower case).
- **L9. Missing guards.** No test covers:
  - favourite resurrection;
  - cross-account merge on a shared device;
  - having exactly one writer for the settings store;
  - export covering every store;
  - JavaScript, PHP and native apps agreeing on the settings list (rule #35).

---

## 3. Is a rewrite justified?

**Three designs compared:**

| Design | What it means | Consequences |
|---|---|---|
| **A. One generic store for everything** | Move favourites and set lists into one table of items | One sync rule and one export, but it is a **live-data migration**. It breaks the links to songs, the share/collaborator/schedule readers and the share re-freeze during erasure, and the Apple app built on today's endpoints. High risk. |
| **B. A registry, plus one store for small items, plus protocol 2 applied everywhere** (recommended) | Content tables stay. New: a server-owned registry, `tblUserProfileItems` for settings and other small per-person state, and a general delete-marker table for favourites and tags | Additive. No existing table moves. Old endpoints keep working and are bridged. Native apps discover the rules through the API. Moderate effort. |
| **C. Targeted fixes only** | Fix H1–M9 within today's methods | Cheapest, but each item from #2175, #2176 and #2177 would add another private rule, and nothing native apps can discover. The rule #35 failures keep coming back. |

**Verdict:** do C's safety fixes **now** (Phase 0, needed whichever design is chosen), then build B in small dormant phases. Not a big-bang rewrite: no live data is moved or dropped.

### Target architecture (Design B)

**1. One server-owned registry: `includes/profile_registry.php`.** Each entry has:
- an identity: section + key, using **platform-neutral** names such as `appearance.theme`;
- a type: bool, int, enum, string, json or list;
- allowed values or ranges, and a default;
- a **scope**:
  - `device` (never leaves the device);
  - `account` (follows the person);
  - `shared` (visible to others, for example through shares);
- **platforms**: `all`, or a subset such as `web`, `apple`, `android`, `tv`;
- storage, sync method, cap, export, erase, claim-at-sign-in and clear-at-sign-out rules;
- a `legacyWebKey` used for the one-time import;
- a schema version.

It is served by **`GET /api?action=profile_schema`**: public, cacheable, ETag (a fingerprint so unchanged copies are not re-sent). Native apps ship a bundled copy for their first offline launch and refresh it later. The JavaScript registry (`settings-sync-registry.js`) stays the web's set of validators, but a test keeps it in lockstep with the PHP registry. So **one list** feeds the server, the web, the cookie and native apps.

**2. Storage.**
- **New `tblUserProfileItems`**, one row per item: per-user change counter `Version`; delete marker `DeletedAt`; the writer's channel and platform kept for diagnosis only.
  - It holds settings, chord preferences, recent songbooks, per-song key/capo/octave, list sorts, card layouts and push preferences.
  - The language column stays the server's source for filtering; it is mirrored into the store.
- **New `tblUserSyncTombstones`** (`Kind` = favorite, custom_tag, …). Set lists keep their own existing marker table.
- **The settings box (`tblUsers.Settings`)** is frozen: no new namespaces. It stays mirrored for old clients and iLyricsDB, and it is written only by the one locked writer from Phase 0.

**3. One sync method: `profile_sync`.**
- **Request:** `{schemaVersion, client:{platform,appVersion}, cursor, changes:[{section,key,op:'set'|'delete',value}]}`.
- **Response:** `{cursor, changes:[{section,key,value,deleted,version}], rejected:[{section,key,reason}]}`.
- **Identity:** each item is known by (section, key), never by its position in a list (rule #51).
- **Order:** the **order in which changes reach the server** decides. Device clocks are never trusted.
- **A client sends only the items it changed** (it keeps a "dirty" list). On a pull it applies everything **except** its own unsent changes. That fixes M1, L3 and H4, and it is how a client **ignores keys it does not know without losing them**: it never sends them, so the server keeps them.
- **Platform-only keys** are stored for the account but applied only on the listed platforms.
- **`device`-scope keys are refused** with reason `device_only`, so the mistake is loud rather than silent.
- **If a platform cannot show a value** (for example a theme it lacks), it shows a fallback but never writes the fallback back.
- **Content** (favourites, set lists) uses protocol 2 everywhere:
  - explicit adds and deletes;
  - `since` watermark;
  - delete markers;
  - the server's copy wins when an edit conflicts.
  - A favourite is identified by its song id, so re-adding it after removal is allowed. The rule: a merge that re-sends a favourite with a delete marker **newer than the client's `since`** is ignored (that device has not seen the delete yet). An **explicit add** removes the marker.

**4. Offline-first.** Each client keeps an outbox of changes that are safe to send twice: the web's `offline-queue.js`, and Apple's `PendingSetlistOp` pattern. It pushes, then pulls with its cursor.

**5. Old app versions.** Old endpoints stay and are bridged:
- `user_settings` with `ihymns.web` writes into items, and the reverse;
- `favorites_remove` also writes a delete marker;
- legacy `favorites_sync` and `user_setlists_sync` behave exactly as today when there is no `since`.

Registry changes only ever add. A rename becomes a new key plus a server-side alias.

**6. Environments.**
- **One profile on all three sites**, because there is one database with no channel column on personal data, and tokens are valid everywhere. This is what the owner wants.
- **Safety measures:**
  - the server checks every value against the registry;
  - an emergency per-channel "personal-data writes off" switch;
  - backups before each risky phase;
  - views tagged with their channel so live statistics can leave out test traffic.

**7. Privacy by construction.** Export and erasure walk the database's live links to the user (the existing `accountEraseFkGraph`) plus the registry. A test fails if any table is neither exported nor excluded with a stated reason.

**8. Native authentication.**
- Bearer token only (64 hex characters, stored hashed, 30-day sliding expiry). Sign in with Apple through `auth_apple`.
- Native apps should send `deviceName`, `platform` and `appVersion` (the server already accepts them).
- Each device should get its **own** session (see decision O6).
- Android follows the same path when it gains accounts: sign in, keep the token in the Android Keystore, use `profile_sync` and the content endpoints.

---

## 4. Phased plan

Rules for every step:
- Run `node tools/run-node-tests.js` and `php tools/run-php-tests.php`; both must pass.
- Doc-blocks and two-level comments on new code; attribution lines on every commit.
- Each step is one commit and can be undone on its own with `git revert`.
- Each guard must be shown to fail: break the thing, watch it go red, restore it.

### Phase 0: safety fixes (no schema change)

**0.1 One locked writer for the settings store (Sonnet)**
- **Files:** `includes/user_settings.php`, `api.php` (`case 'user_settings'`, both branches), `includes/card_layout.php` (both writers).
- **Change:** add `userSettingsMutate(\mysqli $db, int $uid, callable $fn): array`:
  `begin_transaction; SELECT Settings FROM tblUsers WHERE Id=? FOR UPDATE; $new=$fn($cur); check caps with userSettingsRejectReason; UPDATE; commit` (rollback on any error).
  All three writers call it. Correct the "race … not possible" comment.
- **Guard:** `tests/php/test-settings-box-single-writer.php`. Scan every `.php` under `appWeb/` for `UPDATE tblUsers SET` lines that touch `Settings`. Allowed: `user_settings.php`, plus `account_lifecycle.php` (`Settings = NULL`, with a reason). Mutation proof: add a raw UPDATE in `card_layout.php` → red.
- **Acceptance:** existing `test-user-settings-namespace.php` stays green.
- **Commit:** `fix(settings): one locked writer for the account settings store, so two saves cannot overwrite each other`

**0.2 Stop re-fetching settings on every page change (Sonnet)**
- **Files:** `js/modules/settings.js`, `js/modules/list-sort.js`.
- **Change:** keep `this._pulledForUid`. On `EVT_AUTH_CHANGED`, fetch only if the user id changed, or more than 30 minutes have passed since the last fetch. Before fetching, if `this._syncPushTimer` is pending, clear it and `await this._pushSyncedSettings()` first. `list-sort.js` re-fetches only when the user id changes.
- **Guard:** `tests/test-settings-pull-gate.js` (jsdom, a simulated browser page). Two events for the same user → one fetch. Change the theme, then fire the event → the new theme survives. Mutation proof: remove the user-id gate → red.
- **Commit:** `fix(settings): stop re-fetching account settings on every page change, which could undo a fresh change`

**0.3 Keep a visitor's language choice at first sign-in (Sonnet)**
- **Files:** `api.php` (`user_preferred_languages`: add `isSet` = raw value is not null and the column exists), `songbook-language-filter.js`, `settings-language-filter.js`, `api-docs.yaml`.
- **Change:** in the client, if `isSet === false` and the local list is not empty → `saveToAccount(local)` instead of adopting `[]`. If `isSet` is missing (an older server) → today's behaviour.
- **Guard:** a PHP check that the response has `isSet`, and a jsdom test of the claim. Mutation proof: adopt `[]` regardless → red.
- **Commit:** `fix(languages): keep a visitor's language choice when they first sign in`

**0.4 Stop linking searches to people (Haiku)**
- **File:** `api.php` (~1162): bind `null` instead of `$uid`. Update the comment.
- **Guard:** in `tests/php/test-privacy-minimisation.php`, assert the `tblSearchQueries` insert binds a literal null user. Mutation proof: restore `$uid` → red.
- **Commit:** `fix(privacy): stop recording who made each search; nothing used it`

**0.5 "Clear history" unlinks rather than deletes (Haiku; default from decision O3)**
- **File:** `api.php` `song_history_clear`: `UPDATE tblSongHistory SET UserId = NULL WHERE UserId = ?`. The response key `cleared` keeps its meaning.
- **Guard:** an assertion added to the same test.
- **Commit:** `fix(history): clearing your history removes your name from it without changing song popularity`

**0.6 Data-owner tag against shared-device leaks (Sonnet)**
- **Files:** `user-auth.js`, `constants.js` (`STORAGE_DATA_OWNER_UID`, device-only).
- **Change:**
  - Store the user id in `_absorbSetlistSync` / `_absorbFavoritesSync`.
  - At the start of `triggerUserDataSync`: if a stored owner exists and is not the current user, copy local favourites, set lists, tags and history into `ihymns_stash_<owner>` (keep at most one stash), clear those keys, then reconcile.
  - No stored owner = anonymous data, which is still claimed as today.
- **Guard:** `tests/test-account-switch-claim.js`. Seed user A's data, switch to B → B's request contains none of A's ids. Mutation proof: remove the owner check → red.
- **Commit:** `fix(sync): never merge one person's saved songs into another person's account on a shared device`

**0.7 Retire the old whole-store settings save (Sonnet, behind a switch)**
- **File:** `api.php` legacy branch. When `tblAppSettings.user_settings_legacy_blob_writes` is `'0'` (default `'1'` at first, so nothing changes), answer `409 {reason:'namespace_required'}`. Update `api-docs.yaml`.
- **Commit:** `feat(settings): switch to refuse whole-store settings saves that wipe other sections`

### Phase 1: the final schema in one pass (Sonnet)

**1.1 `appWeb/.sql/migrate-profile-sync-foundation.php`** plus a mirror in `schema.sql` that matches byte for byte, plus **one** `migration-registry.php` entry with a multi-object "OR" check. Use `@migration-adds` doc tags for each column, and the `IHYMNS_INCLUDES_DIR` include pattern (rule #41).

```sql
CREATE TABLE IF NOT EXISTS tblUserProfileItems (
  UserId INT UNSIGNED NOT NULL,
  Section VARCHAR(40) NOT NULL COMMENT 'registry section — app-validated, VARCHAR not ENUM (rule #20)',
  ItemKey VARCHAR(191) NOT NULL COMMENT 'item identity (setting key, SongId, songbook, surface) — identity not position (rule #51)',
  ValueJson JSON NULL,
  Version BIGINT UNSIGNED NOT NULL COMMENT 'per-user change counter, assigned under the user row lock',
  UpdatedAt DATETIME(3) NOT NULL COMMENT 'server UTC time accepted',
  DeletedAt DATETIME(3) NULL DEFAULT NULL COMMENT 'non-NULL = delete marker',
  WriterChannel VARCHAR(16) NULL, WriterPlatform VARCHAR(20) NULL,
  PRIMARY KEY (UserId, Section, ItemKey), KEY idx_UserVersion (UserId, Version),
  CONSTRAINT fk_ProfileItems_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE TABLE IF NOT EXISTS tblUserSyncTombstones (
  UserId INT UNSIGNED NOT NULL, Kind VARCHAR(30) NOT NULL, ItemId VARCHAR(191) NOT NULL,
  DeletedAt DATETIME NOT NULL, Reason VARCHAR(20) NOT NULL DEFAULT 'user',
  PRIMARY KEY (UserId, Kind, ItemId),
  CONSTRAINT fk_SyncTomb_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE);
ALTER TABLE tblSongHistory ADD COLUMN Source VARCHAR(20) NOT NULL DEFAULT 'view', ADD COLUMN Channel VARCHAR(16) NULL, ADD COLUMN Platform VARCHAR(20) NULL;
ALTER TABLE tblApnsTokens ADD COLUMN ApiTokenHash VARCHAR(64) NULL;
```

- **Probe:** any table or column missing → pending.
- **Must stay green:** `test-schema-coverage.php`, `test-migration-registry.php`, `test-account-delete-fk-coverage.php`. The new links are CASCADE, so erasure covers them automatically.
- **Risk:** the size of `tblSongHistory` cannot be known from the repo. Check the MySQL version (8.0.12 or later adds these columns instantly). Take a backup first.
- **Commit:** `feat(schema): dormant tables for one per-person profile sync (one migration card)`

### Phase 2: the shared registry and its API (the coordinator's requested phase)

**2.1 `includes/profile_registry.php` (Sonnet)**
- **Functions:** `profileRegistry(): array`, `profileRegistryVersion(): int`, `profileRegistryEntry($section,$key)`, `profileRegistryValidate($section,$key,$value): bool`.
- **Validator types:** enum, int range and step, bool, regex, json shape, list.
- **Seed entries:**
  - the 14 web settings with `legacyWebKey`;
  - `chords.notation` and `chords.columns` (#2176);
  - `reading.textScale`, `reading.showChords`, `reading.dyslexia`, `appearance.cvd` with their platform lists;
  - `device`-scope entries for documentation only: MIDI pedal, offline audio, analytics consent, banner/disclaimer flags, sync bookkeeping, `owner_id`.
- **Guard:** `tests/php/test-profile-registry.php` checks every scope, platform and type is from the allowed list, and that no `device` key has `account` storage. Mutation proof: give MIDI pedal account scope → red.
- **Commit:** `feat(profile): one server-owned list of every personal setting and its rules`

**2.2 `GET ?action=profile_schema` (Sonnet)**
- Public, `Cache-Control` + ETag. Server-only fields removed. Add an `api-docs.yaml` path item.
- **Commit:** `feat(api): let every app download the profile settings list`

**2.3 Lockstep guard between JavaScript and PHP (Sonnet)**
- **Test:** `tests/test-profile-registry-lockstep.js`. Run `php -r` to dump the registry as JSON, import `settings-sync-registry.js`, and assert:
  - every `account`-scope web key has a JS validator, and the reverse;
  - the allowed-value arrays are equal.
- **Mutation proof:** add a theme value only in PHP → red.
- **Commit:** `test(profile): the web's settings list must match the server's`

**2.4 Native snapshot guard (Haiku)**
- Add `appApple/Packages/iHymnsKit/Sources/IHModels/Resources/profile-schema.json`, generated by `tools/dump-profile-schema.php`.
- A Node test asserts it equals the PHP dump. Mutation proof: edit the snapshot → red.
- **Commit:** `test(profile): the Apple app's bundled settings list must match the server's`

### Phase 3: `profile_sync` (dormant behind `profile_sync_enabled`, default `'0'`)

**3.1 `includes/profile_sync.php` core (Sonnet)**
- **Behaviour:** under `SELECT Id FROM tblUsers WHERE Id=? FOR UPDATE`, for each change:
  - look up the registry entry; unknown or `device` scope → rejected with a reason;
  - validate;
  - no-op if the value is unchanged;
  - otherwise `Version = MAX+1`;
  - a delete writes a delete marker;
  - enforce section caps on live items.
- Then return the rows with `Version > cursor`.
- **Truth-table test:** `tests/php/test-profile-sync-core.php`:
  - the later-arriving change wins;
  - an equal value does not bump the version;
  - a delete reaches another cursor;
  - an unknown key is rejected while the others are stored;
  - over-cap is rejected;
  - the cursor only goes up;
  - two interleaved writers lose nothing.
- **Mutation proofs:** `>` → `>=` on the cursor; skip the lock; accept a `device` key → each goes red.
- **Commit:** `feat(profile): per-item sync with delete markers and a server change counter (off by default)`

**3.2 `case 'profile_sync'` in `api.php` (Sonnet)**
- GET pulls and POST pushes then pulls. Bearer or cookie.
- `validateCsrfRequest` on the cookie path; Bearer is exempt, as rule #48 allows.
- Disabled → `409 {reason:'profile_sync_disabled'}`.
- Per-channel `profile_writes_channels` switch (default all channels).
- Add an `api-docs.yaml` entry.
- **Commit:** `feat(api): profile_sync endpoint for web and native apps`

**3.3 Two-way bridge with `ihymns.web` (Sonnet)**
- **Change:** a write through the old `user_settings` to `ihymns.web` updates the matching items, and a `profile_sync` write mirrors back into the namespace. One function in `user_settings.php`, using `legacyWebKey`.
- **Test:** an old-style write appears in a `profile_sync` pull, and the reverse.
- **Commit:** `feat(profile): keep the old settings store and the new profile items in step`

**3.4 One-time import per user, done lazily (Sonnet)**
- On the first `profile_sync` for a user, import `ihymns.web`, `list_sorts`, `cardLayouts`, `ihymns.push` and the language column.
- A `_meta/imported_v1` item marks it done.
- No bulk data migration.
- **Commit:** `feat(profile): bring each person's existing settings into the profile on first use`

### Phase 4: the web moves onto the profile (switch on per channel)

**4.1 `js/modules/profile-sync.js` (Sonnet)**
- Keeps an outbox and cursor keyed by user id, plus the dirty list.
- Pulls at sign-in and when the tab comes back after 30 minutes; pushes after a 1.5 s pause.
- Applies remote values except unsent local changes, and maps canonical ids to localStorage keys.
- `settings.js` uses it when `app_status.profileSync` is true.
- **Commit:** `feat(profile): web settings sync through the profile, one setting at a time`

**4.2 Move list sorts, card layouts and push preferences into sections (Sonnet)**
- **Commit:** `refactor(profile): list sorts, card layouts and alert choices ride the profile`

**4.3 Chord notation and columns, #2176 (Haiku)**
- Two registry entries. `transpose.js` writes call `app.syncStorage(key)`, which also updates the profile.
- **Commit:** `feat(profile): chord notation and two-column charts follow your account (#2176)`

**4.4 Recent songbooks (Sonnet; part of #2177, decision O4)**
- Section `recent_songbooks`, keyed by abbreviation, cap 20. `router.js` `trackRecentSongbook` writes an item.
- **Commit:** `feat(profile): recently used songbooks follow your account (#2177)`

**4.5 Per-song key, capo and octave (Sonnet; if O4 = yes)**
- Section `song_prefs`, keyed by SongId, value `{transpose,capo,octave}`, cap 2000. A full reset = delete.
- **Commit:** `feat(profile): your key and capo for each song follow your account (#2177)`

**4.6 Recognise the shared sign-in cookie (Sonnet, behind a switch; fixes M8)**
- At boot, if there is no local token, call `auth_me` (the cookie travels with it). On 200, enter cookie-session mode (no Authorization header). This also opens the way to stop storing the token in localStorage (L4).
- **Commit:** `fix(auth): a sign-in on one iHymns site is recognised on the others`

### Phase 5: delete markers for favourites

**5.1 Server (Sonnet)**
- `favorites_sync` accepts `added`/`deleted`. Their presence marks protocol 2.
- Markers go in `tblUserSyncTombstones` with `Kind='favorite'`.
- Merge skips ids whose marker is newer than `since`; an explicit add clears the marker; return `tombstones` since the watermark.
- `favorites_remove` writes a marker too (so web devices learn of iPhone deletions).
- Legacy requests are unchanged.
- **Tests:** extend `test-user-sync-guard.php` with a resurrection scenario (red before, green after). Mutation proof: drop the `since` comparison → red.
- **Commit:** `fix(favourites): a removed favourite stays removed on your other devices`

**5.2 Web client (Sonnet)**
- `favorites.js` keeps pending adds and deletes like `setlist.js`, and absorb applies the markers.
- **Commit:** `fix(favourites): web sends what you added and removed, not just your whole list`

**5.3 Import "Replace" (Haiku)**
- Removed set lists and favourites are queued as deletes.
- **Commit:** `fix(import): replacing your data on one device replaces it everywhere`

### Phase 6: activity

**6.1 Tag views; count only real views as popular (Sonnet)**
- `song_view` writes `Channel`/`Platform` and `Source='view'`; the backfill writes `Source='backfill'`.
- `popular_songs` counts only `Source='view'`, and leaves out test channels per decision O2.
- Add rate limits to `song_view` and the backfill (`includes/rate_limit.php`).
- **Commit:** `fix(stats): popular songs count real views once, from the live site`

**6.2 #2175: `my_most_viewed` (Sonnet)**
- `GET`, signed-in only. `GROUP BY SongId WHERE UserId=?` over the chosen period (O5), with the same visibility and language filters as `song_history`. LIMIT 20.
- A home-page card next to Recently Viewed, with an `api-docs.yaml` entry and a registry entry `activity.views` (export yes, clear unlinks, erase unlinks).
- **Guard:** a PHP test that the query has `UserId = ?` and both visibility filters. Mutation proof: drop the user filter → red.
- **Commit:** `feat(history): show each signed-in person their own most-viewed songs (#2175)`

### Phase 7: privacy by construction

**7.1 `account_export` (Sonnet)**
- Walks the live links to the user (`accountEraseFkGraph`) plus the registry.
- A secret-column deny-list: `PasswordHash`, token hashes, Apple refresh token, key hashes.
- At most 10,000 rows per table, returned as a JSON download. Add a "Download my data" button and an `api-docs.yaml` entry.
- **Guard:** `tests/php/test-account-export-coverage.php` parses every link to the user in `schema.sql` and checks each is exported or excluded with a reason. Mutation proof: add an unlisted table → red.
- **Commit:** `feat(account): download everything iHymns holds about you`

**7.2 Web self-service deletion (Sonnet)**
- Uses the existing `account_delete` and its confirm-it's-you step.
- **Commit:** `feat(account): delete your account from the web app`

**7.3 Erasure cleans the audit log (Sonnet; O3)**
- Blank `IpAddress`, `UserAgent`, `Referrer`, `Country`, and the username/email in `Details`, for the person's rows. Extend `test-account-lifecycle.php`.
- **Commit:** `fix(account): erasing an account also removes its addresses from the audit log`

**7.4 Fix sign-out push and sign-out clearing (Sonnet)**
- `apns_register` stores `ApiTokenHash`. `auth_logout` deletes only the APNs tokens linked to that session.
- Sign-out clears synced content according to O1, after flushing the outbox (warn if anything is unsent).
- **Commit:** `fix(auth): signing out on one device no longer affects your other devices`

**7.5 Privacy page (Haiku)**
- Make it match reality: server history, search logging (unlinked), export, deletion, cookie.
- **Commit:** `docs(privacy): describe exactly what is kept about you and how to get or delete it`

### Phase 8: native apps

**8.1 Apple set lists on protocol 2 (Sonnet)**
- Send `since` (kept per user) and `deleted` (pending deletes); absorb delete markers and conflicts.
- **Commit:** `fix(apple): set list sync says what you deleted, so it can't delete others`

**8.2 Apple views and history (Sonnet)**
- Send `song_view` on open, and read `song_history` and `my_most_viewed` when signed in.
- **Commit:** `feat(apple): your iPhone views count in your history and most-viewed`

**8.3 Apple profile adapter (Sonnet)**
- Uses the bundled and fetched schema; maps the `IHSettingsStore` and `@AppStorage` keys to canonical ids; outbox in UserDefaults.
- **Commit:** `feat(apple): theme, colour filter, text size and chord display follow your account`

**8.4 Apple device details (Sonnet)**
- Send `deviceName`, `platform`, `appVersion` at sign-in. If O6 = per-device sessions, swap the synced token for a device-own token on first launch.
- **Commit:** `feat(apple): each device shows in your device list`

**Android:** accounts and sync are a separate epic. The registry, `profile_sync` and protocol 2 are designed so that it only needs sign-in, Keystore storage and the same two endpoints.

### Phase 9: project docs (Haiku)

- Add a new CLAUDE.md rule: one profile registry; one sync method; personal data identified by identity with delete markers; no browser-side content sync.
- Update the wiki, `.OpenAI/`, `CHANGELOG.md`, and `WHATS-NEW.md` when users can see the change.

### `api-docs.yaml` entries to update (rule #48)

- **Existing:**
  - `user_settings` (namespaces, `cardLayouts`, the legacy 409, and drop the "only store" claim);
  - `user_preferred_languages` (`isSet`);
  - `favorites_sync` (`added`, `deleted`, `tombstones`);
  - `favorites_remove` (writes a marker);
  - `user_setlists_sync` (document protocol 2 for native apps);
  - `song_view` (channel and platform, rate limit);
  - `song_history_clear` (unlinks);
  - `popular_songs` (real views only);
  - `auth_logout` (session-scoped APNs);
  - `apns_register`;
  - `auth_login` and the other sign-ins (document `deviceName`/`platform`/`appVersion`).
- **New:** `profile_schema`, `profile_sync`, `my_most_viewed`, `account_export`.

---

## 5. How this fits with the visitor cookie plan (#2143)

- **One registry.** The PHP registry is the master. The JavaScript `settings-sync-registry.js` (already built) stays as the web's validators and is checked against it by test 2.3. The cookie carries only `account`-scope display keys flagged `cookie:true`. Content never travels in it, which matches the plan's rule.
- **One set of validators.** The cookie, the server and native apps all apply the same allowed values.
- **Two merge rules, one per transport, and that is correct:**
  - **The cookie:** newest change wins for each setting. Safe there, because every writer is the same browser with the same clock.
  - **The server:** the order changes arrive decides, because devices have different clocks.
- **At sign-in.**
  - Keys the account does not have are **claimed** from the device; this also fixes M2.
  - Keys the account already has: **the account wins**. That is the plan's D8 default, so the two agree.
- **After sign-in.** The one call site `app.syncStorage(key)` records the change for both the cookie (`recordLocalChange`) and the profile outbox. Values pulled from the account are also written to the cookie, as the plan's step 4 already does.
- **Native apps** have no cookie. `profile_sync` is their only route, and that is by design.
- **No overlap.** The cookie plan does not touch the server; this plan touches the cookie only at that one call site.

---

## 6. Owner decisions

**O1. Should signing out clear your synced songs from that device?**
- **Why it needs deciding:** shared church computers versus offline convenience.
- **Options:**
  - Keep (today): the next person can see your data. Step 0.6 stops the merge, but not the visibility.
  - Always clear: safe, but needs internet to get it back.
  - Ask with a "This is a shared device" tick box: a little more interface.
- **Recommendation:** clear by default after flushing unsent changes. It is all on the server already. Display settings stay.
- **Answer needed:** "keep", "clear" or "ask".
- **Blocks:** step 7.4 only.

**O2. Test sites and live data.**
- **Why it needs deciding:** you want one profile everywhere, but a dev bug can then damage live users' data.
- **Options:**
  - Share as today, plus an emergency write-off switch per channel, plus leaving test-site views out of live "popular" and CCLI counts (recommended).
  - Make dev read-only for personal data.
  - Separate profiles: breaks your stated goal.
- **Recommendation:** the first option.
- **Answer needed:** "share+switch", "dev read-only" or "separate".
- **Blocks:** 3.2 (the switch) and 6.1.

**O3. Privacy defaults, as a bundle.**
- **The four parts:**
  - stop linking searches to people, **and blank the old links**;
  - "Clear history" unlinks rather than deletes;
  - erasure also cleans IP addresses from the audit log;
  - keep personal view history while the account is active.
- **Why it needs deciding:** these are legal and product choices, and blanking old data cannot be undone.
- **Options:** all four; or each separately; or doing nothing, which leaves M4 and M5 in place against what the privacy page promises.
- **Recommendation:** all four (back up first). This is not legal advice.
- **Answer needed:** "all", or which ones to drop.
- **Blocks:** 0.4, 0.5, 7.3. 0.4 can ship without the backfill.

**O4. #2177: which device-only items should follow the account?**
- **Options:**
  - Recent songbooks: tiny, low risk.
  - Per-song key, capo and octave: useful for musicians; capped at 2,000.
  - Search history: sensitive.
- **Recommendation:** the first two yes; search history no.
- **Answer needed:** "songbooks", "+key/capo", "+search".
- **Blocks:** 4.4 and 4.5 only.

**O5. #2175: what period does "most viewed" cover?**
- **Recommendation:** default to the last 12 months, with 30-day and all-time tabs.
- **Answer needed:** "12m+tabs" or a single period.
- **Blocks:** 6.2 only.

**O6. Apple sign-in: one shared iCloud session, or one session per device?**
- **Why it needs deciding:** the shared token means signing out on one device signs out all of them, and the device list cannot tell them apart.
- **Options:**
  - Keep as is.
  - Per-device tokens minted from the synced token: the same convenience, correct sign-out and device list.
- **Recommendation:** per-device tokens.
- **Answer needed:** "keep" or "per-device".
- **Blocks:** 8.4 only.

**Defaults I picked and flagged:** web self-service deletion and export (the privacy page already promises them), and "account wins" for keys the account already has at sign-in (the cookie plan's D8). Both are easy to change.

---

## 7. Effort and risk

**Size:** about 37 small commits.

| Phase | Commits |
|---|---|
| 0 | 7 |
| 1 | 1 |
| 2 | 4 |
| 3 | 4 |
| 4 | 6 |
| 5 | 3 |
| 6 | 2 |
| 7 | 5 |
| 8 | 4 |
| 9 | 1 |

- **Model:** Sonnet for most steps; Haiku for 0.4, 0.5, 2.4, 4.3, 5.3, 7.5 and 9.

**Riskiest steps:**
- 0.6 (clearing or stashing data on the device);
- 1.1 (column additions to `tblSongHistory`, whose size is unknown);
- 3.4 (import into the new store);
- 5.1 (change to the favourites sync method);
- O3's blanking of old data;
- 8.1 (an Apple release that has to coexist with older builds).

**Back up first:** use `/manage/setup-database` → "Download a backup" before 1.1, 3.4, 5.1 and any data blanking. The key tables are `tblUsers`, `tblUserFavorites`, `tblUserSetlists*`, `tblUserCustomTags`, `tblSongHistory`, `tblSharedSetlists`, `tblSearchQueries` and `tblActivityLog`.

**Device checks:** two browsers plus an iPhone and an iPad.
- Remove a favourite → it stays gone everywhere.
- Ben signs out, Carol signs in → nothing of Ben's reaches her.
- Make a set list on the web, then edit another on the iPhone → it survives.
- Change the theme and navigate at once → the change sticks.
- Sign out on the web → the iPhone still gets notifications.
- Export → it contains every listed store.
- Delete the account → the audit log has no IP addresses left.

**Rollout:**
1. alpha with every switch off.
2. Promote to beta.
3. Run setup-database on the one shared database.
4. Turn on `profile_sync` for alpha, then for beta. The two-way bridge keeps live's older code correct meanwhile.
5. Promote to main and turn it on.
6. Ship the Apple app update after the server has been live for one cycle.

**Undo:** each step is reverted on its own. The switches work immediately, with no deploy.

**Cannot be determined from the repo:** row counts in production, whether any outside client still sends the old whole-store settings save, the MySQL version, and whether `beta.ihymns.net` is still live.

**Issues to file before starting (I filed none):** H1, H2, H3, H4, M1, M2, M3+M4 together, M5, M6, M7, M8, M9 and L1. Group them under an "Account and profile sync" epic.
