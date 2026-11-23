# Plan: PR 3 of the account rewrite (phases H and I: one sign-in per device)

Written 2026-11-23, read-only, against `origin/alpha` `8ddc2c00`. Branch `claude/account-pr2c`
(`498e5308`, PR #2204) touches none of these files except `api.php` (it adds `account_export`, shifting
later lines by 64), so places are named by **text to search for**. "Old plan" =
`.claude/account-profile-rewrite-plan.md`. Issue #2185, epic #2180. O6 is final.

## 1. Where the code disagreed with the earlier plans

1. **No Apple build has ever shipped.** `.claude/apple-native-status.md` (2026-09-07): never uploaded to
   TestFlight or the App Store; still no app icon under `appApple/`. Only developer builds hold the shared
   iCloud token. The migration path is still built (O6), but the first public build is per-device from day one.
2. **No sign-in records an install id today.** `apiTokenMint()` reads `$meta['deviceInstallId']`, but
   `apiTokenDeviceMetaFromBody()` never sets it, and the email route mints with `null` meta inside its
   transaction (`_completeEmailLoginTxn()`), so it cannot carry one without a new parameter.
3. **There is no "sign out everywhere" at all**, and four places delete all of a person's tokens by hand:
   `auth_change_password` (keeps the current one), the password reset, `changeUserPassword()` and
   `setUserActive()` (all in `manage/includes/auth.php`). None would revoke an enrol key, so a key in
   iCloud would survive a password reset. That is a security hole PR 3 would create unless fixed in the
   same PR.
4. **`kSecAttrSynchronizable = false` is not enough.** The current store uses
   `kSecAttrAccessibleAfterFirstUnlock`; such items are copied by encrypted backups and phone-to-phone
   transfer, so a "non-synced" token would be cloned onto a restored phone (two devices, one token). The
   device token needs `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`.
5. **The enrol key cannot live in "the synced item".** The synced item *is* the legacy token
   (`app.ihymns.token`), and `KeychainTokenStore.save()` deletes before adding, so any new write there
   would delete the shared token from every device at once. The key needs its own service name.
6. **tvOS.** The store's comment says tvOS must not sync, but `AppRootViewModel.makeLive()` (used by the
   iPhone *and* TV shells) always builds the synced default.
7. **Old builds delete the shared token on any 401** (`restoreFromStorage()`), so they clean iCloud up
   themselves after "sign out everywhere". New builds must treat it vanishing as normal.
8. **Per-session push is switched by `profileSyncEnabledHere()`** (G4: `auth_logout`, `device_signout`,
   `apns_register`). With device sign-in on and profile sync off, one Apple sign-out would still delete
   every Apple push row of the person (the A6 rule), breaking O6.
9. **`getAuthenticatedUser()` accepts the `ihymns_auth` cookie**, so an enrol action built on it alone would
   let a browser mint app tokens. It must require the `Authorization` header.
10. **`devices_list` does not return `IssuedVia`**, and nothing marks the shared older-app sign-in.
11. **The Apple app registers only Live Activity push tokens**; ordinary device push is not built.
12. **Old plan H1 had enrol return the key**, but a device signed in fresh by password holds no legacy
    token, so it needs `auth_enrol_key_create`.
13. **Guards already wait for this:** `test-single-token-mint.php` allows `'enrol'` unused (flip it);
    `test-account-foundation-gating.php` has no ready-check for `tblAccountEnrolKeys` yet.
14. **The Apple app sends no device details**, and `UIDevice.name` is a generic "iPhone" on iOS 16+
    without a special entitlement.
15. **Real-keychain behaviour cannot be tested even in CI** (`apple.yml`: no unlocked keychain).
16. PR 2 moved D10 (recognising the shared web cookie) to PR 3; it is unrelated to O6, so it gets its own PR.

**No new migration is needed.** B1 shipped `tblApiTokens.DeviceInstallId`, `IssuedVia`,
`idx_UserInstall`, `tblAccountEnrolKeys` and `tblApnsTokens.ApiTokenHash`. Stress test: rotation would need
a "grace" column, but this plan does not rotate; "how issued" fits the existing `'enrol'` word; "signed out
here" lives on the device. Every new read and write sits behind a ready-check and falls back if B1 has not run.

## 2. The design in one page

- **Device token:** the existing 64-hex token from `apiTokenMint()`, now bound to a `DeviceInstallId` (a
  random id the app makes once). Minting for an install **deletes that person's other tokens for the same
  install** and moves their Apple push rows across. There is **no periodic rotation**: a lost reply would
  leave the device holding a dead token. 30 days unused, sliding on use (unchanged).
- **Enrol key:** `ihek1_` + 64 hex (the prefix means it can never pass the Bearer shape check
  `^Bearer\s+([a-f0-9]{64})$`). Only its sha256 is stored, in `tblAccountEnrolKeys`, which
  `getAuthenticatedUser()` and `apiTokenResolveBearerUser()` never read. It can only be swapped for a new
  device token. 180 days, extended on each swap; at most 5 live per person and platform (the oldest goes);
  revoked by sign out everywhere, "turn off automatic sign-in", any password change or reset, and
  deactivation; deleted by erasure (its CASCADE link is already in the erasure's link map).
- **The shared legacy token** is swapped, never revoked by a swap, never deleted from iCloud by new code,
  and expires 30 days after the last old build stops using it.
- **The switch:** `device_signin_channels` (default `none`, fails closed, via `channelSwitchEnabledHere()`)
  gates the swap, the key and the web "Sign out everywhere" button. Install-id recording and the
  sign-out-everywhere action are not gated (only new clients use them; sign-out is never gated). Unticking
  stops swaps and keys at once; new apps stay on the legacy token.

### Status codes (the contract, rule #35; `reason` in the body)

| Action | 200 | 400 | 401 | 403 | 409 | 429 |
|---|---|---|---|---|---|---|
| `auth_device_enrol` (POST, Bearer header) | `{token,user,device:{id},enrolKey?,enrolKeyExpiresAt?}` | `bearer_required`, `invalid_install_id`, `invalid_platform` | token dead | CSRF, `install_mismatch`, `not_a_shared_app_sign_in` | `device_signin_disabled`, `device_signin_not_ready`, `already_enrolled` | yes |
| `auth_enrol_key_create` (POST, device-bound Bearer) | `{enrolKey,expiresAt}` | — | token dead | CSRF, `device_token_required`, `enrol_key_platform_unsupported` | disabled, not ready | yes |
| `auth_enrol_key_redeem` (POST, no sign-in) | as enrol, no new key | `malformed_enrol_key`, `invalid_install_id`, `invalid_platform` | `enrol_key_invalid` (one answer for unknown, expired, revoked, wrong platform, inactive account) | CSRF | disabled, not ready | yes |
| `auth_enrol_keys_revoke` (POST) | `{revoked}` | — | not signed in | CSRF | — | yes |
| `auth_signout_everywhere` (POST, `{keepCurrent?}`) | `{tokensRevoked,enrolKeysRevoked,keptCurrent}` | — | not signed in | CSRF | — | yes |

None of these ever sets the sign-in cookie. Rate limits (`checkRateLimit()` + `recordRateLimitHit()`,
the `device_signout` pattern): enrol 10 an hour per person; key create 5 an hour per person; redeem 20 an
hour per address before the lookup and 10 an hour per person after it; revoke and everywhere 10 an hour
per person.

## 3. Steps (one commit each; 🔴 Opus review; 🟢 any order within its group; 👤 owner)

Every step: two-level comments; `api-docs.yaml` in the same commit as any action change; then
`php tests/php/<its test>.php`, `php tools/run-php-tests.php`, `node tools/run-node-tests.js`, `php -l` /
`node --check` on touched files. Undo = `git revert <sha>`.

### Server (phase H)

**H1 🔴🟢 Install id travels with every sign-in, and one device keeps one row.**
- `includes/api_tokens.php`: `apiTokenDeviceMetaFromBody()` adds `deviceInstallId` (via
  `apiTokenCleanInstallId()`). `apiTokenMint(..., ?int &$expiresAtTs = null, ?string $installId = null)`
  uses `$installId ?? $meta['deviceInstallId']`. New `apiTokenReplaceSameInstall($db, $uid, $installId,
  $newHash)`, called inside `apiTokenMint()` after the insert, only when `apiTokensFoundationColumnsExist()`:
  read the other hashes `WHERE UserId = ? AND DeviceInstallId = ? AND Token <> ?`, re-tie their
  `tblApnsTokens` rows to the new hash (when `apnsTokenHashReady()`), delete them.
- `manage/includes/auth.php`: `completeEmailLogin(..., ?string $installId = null)` →
  `_completeEmailLoginTxn($db, $email, $userId, $installId)` → `apiTokenMint($db, $uid, null, 'email',
  installId: $installId)` (a named argument skips the by-reference expiry). `api.php` `auth_email_login_verify` passes `$deviceMeta['deviceInstallId']`.
  `auth_login`, `auth_register`, `auth_apple` get it free through `$deviceMeta`. `auth_device_code_poll`
  passes only the install id (its device-details guard stays).
- Guard: new `tests/php/test-device-signin-core.php` (real database, prints SKIP loudly without
  `IHYMNS_TEST_DSN`), part 1: same-install row replaced; different install kept; a NULL-install row (the
  legacy token) kept; another person's row with the same install id kept; push rows re-tied. Mutation:
  drop `UserId = ?` from the delete → red. Extend `test-api-token-device-meta.php` for the new field.

**H2 🟢 The device sign-in switch, and one "per-session push" answer.**
- New `includes/device_signin_gate.php`, a copy of the `profile_sync_gate.php` shape: `DEVICE_SIGNIN_SETTING_KEY`,
  default `'none'`, `deviceSigninEnabledHere()`, reasons `device_signin_disabled` /
  `device_signin_not_ready`, `deviceSigninDisabledBody()`.
- `/manage/configuration` card `save_device_signin` (copy `save_profile_sync`); map it
  `web_only:configuration-secrets` in `test-manage-action-api-coverage.php`.
- `app_status` emits `deviceSignin` and `profileSync` (booleans); `index.php` config emits
  `deviceSignin: {enabled}` next to `profileSync`.
- `includes/apns.php`: `apnsPerSessionHere()` = `profileSyncEnabledHere() || deviceSigninEnabledHere()`;
  replace the `profileSyncEnabledHere()` calls in `auth_logout`, `device_signout` and `apns_register`.
- Guards: extend `tests/php/test-channel-switch.php` (truth table, fails closed); extend
  `test-apns-session-tie.php` to find every `apnsDeleteForSessions(` call and every `ApiTokenHash` insert
  from the tree and require `apnsPerSessionHere()`. Mutation: put one site back → red.

**H3 🔴🟢 The enrol-key core.**
- New `includes/account_enrol_keys.php`: `accountEnrolKeysReady()` (memoised, INFORMATION_SCHEMA),
  `ACCOUNT_ENROL_KEY_PLATFORMS = ['apple']`, `ACCOUNT_ENROL_KEY_LIFETIME_DAYS = 180`,
  `accountEnrolKeyMint($db, $uid, $platform): array{key, expiresAt}`, `accountEnrolKeyRedeemLocked($db,
  $key, $platform): ?int` (`FOR UPDATE`; live, unexpired, same platform, `tblUsers.IsActive = 1`; sets
  `LastUsedAt`, extends `ExpiresAt`), `accountEnrolKeyRevokeAll($db, $uid, ?$platform): int`,
  `accountEnrolKeysSummary($db, $uid)` (platform, dates; never the hash).
- `test-account-foundation-gating.php`: add `'tblAccountEnrolKeys' => ['accountEnrolKeysReady']`.
- Guard: `test-device-signin-core.php` part 2: raw key never stored; shape; cap of 5; expired, revoked,
  wrong platform and inactive account all refused; a key presented as `Authorization: Bearer` is refused
  by `apiTokenResolveBearerUser()`; a source check that `getAuthBearerToken()`, `getAuthenticatedUser()`
  and `apiTokenResolveBearerUser()` never name `tblAccountEnrolKeys`. Mutations: drop the `RevokedAt`
  check → red; make the resolver read the key table → red.

**H4 🔴 One door to "revoke everything".**
- `includes/api_tokens.php`: `apiTokenRevokeAllForUser($db, $uid, ?string $keepHash, string $why):
  array{tokens, enrolKeys, push}` (one transaction: delete tokens except `$keepHash`; revoke enrol keys;
  Apple push rows not tied to `$keepHash`).
- Re-point the four sites from section 1, item 3 (password change keeps the current token; the others
  keep none). Web push subscriptions are not touched (they were never tied to a sign-in).
- Guard: new `tests/php/test-token-revoke-one-door.php`: from string tokens of every `.php` under
  `appWeb/`, any `DELETE FROM tblApiTokens` whose condition names `UserId` but not `Token` is allowed only
  inside `apiTokenRevokeAllForUser()`; any write to `tblAccountEnrolKeys` only inside
  `account_enrol_keys.php`. The expiry sweep (`WHERE ExpiresAt < ?`) is allowed. Plus a real-database
  run of the core. Mutations: a raw delete back in `auth_change_password` → red; remove the key
  revocation from the core → red.

**H5 🔴 The new actions.**
- New `includes/device_signin.php`, a core that returns `{status, body}` so the test can call it without
  `api.php`: `deviceSigninEnrol($db, $user, $callerHash, $body)`, `deviceSigninKeyCreate(...)`,
  `deviceSigninRedeem($db, $body)`. Enrol rules: caller token with no install id (the shared one) → mint
  with `IssuedVia='enrol'`, keep the caller; caller bound to the same install → 409 `already_enrolled`;
  bound to another install → 403 `install_mismatch`; caller `Platform = 'web'` or `IssuedVia =
  'cross_surface'` → 403 `not_a_shared_app_sign_in`.
- `includes/api_tokens.php`: `apiTokenBearerHeaderValue()` (header only, never the cookie); make
  `apiTokenResolveBearerUser()` use it, so the regex lives once.
- `api.php`: thin cases for the five actions (`validateCsrfRequest(null)`, rate limit, `logActivity()` with
  a token prefix only, `apiAuthSuccessPayload()`). `devices_list` gains `issuedVia`, `boundToDevice` (never
  the id) and `enrolKeys`.
- `test-single-token-mint.php`: `'enrol'` must now be used.
- Guard: `test-device-signin-core.php` part 3: every row of the status table, plus "the shared token
  still works after a swap", "redeem replaces the same install", "a password reset kills the key". Mutation:
  revoke the caller on a swap → red.

**H6 The web device card.**
- `js/modules/devices.js`: labels from `issuedVia`/`boundToDevice`/`platform` ("Older app version, may be
  shared by several of your Apple devices", "Paired TV", "Added automatically"); a **Sign out everywhere**
  button and an "Automatic sign-in for new Apple devices · Turn off" row, both shown only when
  `config.deviceSignin.enabled`.
- `js/modules/user-auth.js`: `logout({ everywhere: true })` asks the unsent-changes question first (rule
  #54), then posts `auth_signout_everywhere`; 200 or 401 → clear as a normal sign-out; network failure or
  5xx → stay signed in and say the other devices were not signed out. `apiFetch()` only; status codes only.
- Guard: new `tests/test-devices-everywhere.js` (jsdom): button hidden when off; question shown before the
  request; nothing cleared on failure. Mutations: skip the question → red; clear on 503 → red.

**H7 🟢 Housekeeping and privacy.**
- The admin cleanup that runs `DELETE FROM tblApiTokens WHERE ExpiresAt < ?` also deletes keys expired, or
  revoked over 30 days ago.
- `includes/pages/privacy.php`: a "Signed-in devices" paragraph, worded "once this is switched on for the
  site" (the page is shared and cached): own sign-in per device; sign out one or everywhere; ends after 30
  days unused; an optional Apple key in iCloud Keychain only adds a sign-in and can be turned off. Extend
  `test-privacy-page-promises.php`: button names match `devices.js` word for word; "30" and "180" match the
  PHP constants.
- `test-account-export-coverage.php` must stay green (the key hash is already denied).

### Apple app (phase I). Nothing here can be compiled or run in this sandbox.

**I1 🟢 Set lists on protocol 2.** `SetlistsEndpoints.swift` `SetlistsSyncRequestBody` gains `since` (the
reply's `syncedAt`, kept per user id in UserDefaults) and `deleted` (a pending-deletes queue in
`OfflineStore`); `AppRootViewModel+Setlists.swift` absorbs `tombstones` and `conflicts`. A8 stays on for any
remaining developer builds. Guard: new `tests/test-apple-setlist-protocol.js` (reads the Swift: both fields
encoded, every `.replace` call passes them). Swift tests in `AppRootViewModelSetlistsTests`.

**I2a 🔴🟢 Keychain custody, by type.** In `IHAuth`:
- `KeychainTokenStore(..., thisDeviceOnly: Bool = false)`; when true, saves with
  `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`; `precondition(!(synchronizable && thisDeviceOnly))`.
- `LegacySharedTokenReader`: wraps service `app.ihymns.token`, `synchronizable: true`, and offers **only**
  `load()`. Deleting or overwriting the shared token becomes impossible to write.
- `DeviceIdentity`: the install id (a UUID), in a this-device-only item `app.ihymns.install-id`.
- `DeviceSignInState`: a this-device-only item `app.ihymns.device-state` holding `signedOutHere`, the
  hashes of legacy tokens already swapped and of dead keys (at most 10 each).
- Device token: `KeychainTokenStore(service: "app.ihymns.device-token", synchronizable: false,
  thisDeviceOnly: true)`. Key: `KeychainTokenStore(service: "app.ihymns.enrol-key")` (synced), built only
  where iCloud Keychain exists (not tvOS, not watchOS).
- `makeLive()` wires these per platform.
- Guard (runs here): new `tests/test-apple-keychain-custody.js`, tree-derived from every non-test
  `.swift` file with comments removed: `app.ihymns.token` appears only in `LegacySharedTokenReader`, which
  has no `save`/`delete`; every synced store's service is `app.ihymns.enrol-key`; the device-token store
  has both `synchronizable: false` and `thisDeviceOnly: true`; the this-device-only branch of `save` uses
  the `ThisDeviceOnly` constant. Mutations: flip the device store to synced → red; add `delete()` to the
  reader → red.

**I2b 🔴 API client.** New `AuthDeviceEndpoints.swift` + `APIClient+DeviceSignIn.swift` for the five
actions. The sign-in bodies (`AuthLoginRequestBody`, email verify) add `deviceInstallId`, `platform: "apple"`,
`deviceName` (generic model: iPhone, iPad, Mac, Apple TV) and `appVersion` (`CFBundleShortVersionString`).
`Endpoint` gets `capturesRefusalReason: Bool = false`; when true, `classifyMachineRefusal()` returns
`.server(status:, message: <reason>)`, leaving existing calls byte-identical (eight tests expect
`message: nil`). `AppStatusDecoding` reads `deviceSignin` and `profileSync` (missing = false).

**I2c 🔴 The session state machine** (`SessionController.swift`; one swap at a time inside the actor):
1. Device token present → `auth_me` as today. On 401: delete the device token (safe: not synced), set
   `signedOutHere`, show signed out.
2. No device token and `signedOutHere` → signed out. Only a manual sign-in clears the flag.
3. Otherwise, if `app_status.deviceSignin`: a key whose hash is not dead → `auth_enrol_key_redeem`; else a
   legacy token whose hash is not swapped → `auth_device_enrol`. Save the new token first, then record the
   hash. 401 → record the hash as dead, signed out. 409/400/offline → **legacy mode**: use the shared
   token in memory only (never copy it into the device store), try again next launch.
4. Manual sign-in → device token saved, flag cleared; if automatic sign-in is on and no live key is in
   iCloud, `auth_enrol_key_create` and save it.
5. Sign out → `auth_logout`, delete the device token, set the flag. In legacy mode: set the flag and forget
   the token, with no server call (revoking it would sign out the other devices). Never touch the shared
   item or the key.
6. Sign out everywhere → `auth_signout_everywhere`, then as 5, plus delete the key from iCloud (it is dead).
7. Delete account → as today, on the device store, plus delete the key.
- Swift tests: extend `SessionControllerTests` with `InMemoryTokenStore` stand-ins and `MockURLProtocol`
  (the existing pattern) for each numbered path. They run in CI (`apple.yml`, `swift test` on macOS), not here.

**I2d AccountView.** "Sign out everywhere" (confirmed first) and a toggle "Sign in automatically on my
other Apple devices" (off = `auth_enrol_keys_revoke` + delete the key from iCloud).

**I3 🟢 Views and history.** `song_view` (`song_id`, `platform: "apple"`, 429 ignored) on song open; read
`song_history` and `my_most_viewed`.

**I4 🟢 Profile adapter.** `profile_sync` with the bundled `profile-schema.json` (C6); map the
`IHSettingsStore`/`@AppStorage` keys to registry ids (platform `apple` only); outbox in UserDefaults per user
id; 409 `profile_sync_disabled` → do nothing. Extend `test-profile-registry-lockstep.js`: every mapped
Swift key is a registry id.

**I5 Sign-out clears local favourites and set lists, asking first** (as G5), only when
`app_status.profileSync` is true.

**I6 Docs.** CLAUDE.md rule (one maker, one revoke door, keys only swap, never touch the shared item), wiki,
`.OpenAI/`, `CHANGELOG.md`, `apple-native-status.md`, a progress table here.

### Order and parallel work

H1, H2 and H3 in any order; H4 after H3; H5 after H1–H4; H6 and H7 after H5. I2a, I1, I3 and I4 in any
order; I2b after I2a; I2c after I2b **and** after H5 is on alpha; I2d and I5 after I2c; I6 last. Opus review:
H1, H3, H4, H5, I2a, I2b, I2c (also the guards behind them).

## 4. What enrolment looks like

- **First launch after the upgrade** (developer devices only today): no device token, not signed out here,
  a shared token in iCloud → one `auth_device_enrol` → own token. The shared token stays for devices still
  on the old build; the list shows this device plus "Older app version".
- **Second Apple device**, never signed in: the key in iCloud → `auth_enrol_key_redeem` → its own row. No
  key (automatic sign-in off): it asks for a sign-in.
- **Phone restored from a backup**: this-device-only items do not come across, so it gets a new install id
  and its own sign-in; the old phone's token is not cloned.
- **Lost phone**: on the web, sign that row out, or "Sign out everywhere" (every token and key). On its next
  open the phone gets a 401, marks "signed out here" and never signs itself back in. The help text also says
  to remove it from the iCloud account (Find My), since a reinstall there could take a later key.
- **Expiry**: 30 days unused ends a device's sign-in; it then needs a manual sign-in (question B).

## 5. Verifying

**Here:** all PHP guards (PHP 8.3 here; CI runs 8.4 and 8.5); the database tests after starting the
installed MariaDB on a spare port with `IHYMNS_TEST_DSN`; all node guards, including the three that read
Swift; `php -l`, `node --check`. **Not here:** compiling Swift, `swift test` (CI runs it on macOS, without
a real keychain), keychain, iCloud, backups.

**👤 On real devices** (two Apple devices on one iCloud account, plus an Apple TV):
1. Old build on both, new build on one: the other stays signed in; both rows listed.
2. Sign out on one: the other stays signed in; the first does not sign itself back in.
3. Fresh install elsewhere signs in through the key; with automatic sign-in off it asks.
4. A restored backup gets its own row.
5. Web "Sign out everywhere": all signed out on next open; none returns by itself.
6. Password reset: the key stops working.
7. Apple TV: own token, no key; does tvOS sync keychain items at all?
8. Airplane mode at first launch after the upgrade: still signed in; swaps next launch.
9. A Live Activity on the iPad keeps updating after the iPhone signs out.

## 6. Rollout, rollback, owner actions

1. PR 3 to alpha. 👤 Check the B1 card shows applied (no new migration).
2. 👤 Tick alpha in `device_signin_channels`; test with a developer build; tick beta.
3. 👤 Promote; **tick production before the first TestFlight or App Store upload** (App Review uses
   production). The upload blockers in epic #2105 (icon #2100, signing #2101) are separate.
4. 👤 With production ticked, add `WHATS-NEW.md` bullets: "Sign out everywhere from Settings" and, when the
   app ships, "Each of your Apple devices now has its own sign-in".

**Rollback:** untick the channel (instant; apps stay on the legacy token). H1 and H4 are not switchable:
`git revert`. Old builds never break, because nothing revokes or deletes the shared token.

## 7. Reusable design: one sign-in per device (copy into SIGNula.id and ClearList)

**Goal.** Each device has its own sign-in, listed, named and signed out alone, while someone using a synced
password store still signs in once.

1. **Device token.** 32 random bytes; the server stores only a hash; 30 days unused, extended on use. Kept
   on the device without cloud sync **and without backup transfer** (Apple: `kSecAttrSynchronizable = false`
   plus a `…ThisDeviceOnly` accessibility; Android: Keystore-backed storage excluded from backup). One row per
   device: name, platform, app version, last seen, how issued, and a random install id made once (stored the
   same way).
2. **One maker, one remover.** Every sign-in route creates tokens through one function; every "revoke all"
   (sign out everywhere, password change or reset, deactivation) goes through one function that also
   revokes enrol keys and push registrations. Guards forbid any other.
3. **One device, one row.** Minting for an install id replaces that person's other tokens for the same
   install. No periodic rotation (a lost reply would strand the device).
4. **Optional enrol key** in the synced store, in its own table, with a prefix that can never pass the
   normal token check. It can only be swapped for a new device token, has a long sliding lifetime, a small
   cap per person, and is revoked by any "revoke all".
5. **Signing out** revokes only that device's token and its push registrations. The device remembers
   "signed out here" (stored like the token) and only a manual sign-in clears it; any rejected token sets
   it, so a lost device never signs itself back in.
6. **Leaving a shared token.** Each device swaps the shared token for its own; the swap never revokes it,
   and new code never deletes or overwrites it (make the type read-only). It expires once no old app uses it.
7. **A per-site switch** gates the swap and the key; when off, new apps keep using the shared token.
8. **Never** show a token or key again after creation, accept a stored hash as a sign-in, set a browser
   cookie from an app route, or let a token bound to one install mint a token for another.

## 8. Questions the code could not settle

**A. Needs the owner.** *Should the automatic sign-in key be on by default on Apple devices?*
- Why: it is a product choice between convenience and caution; the code works either way.
- Options: **On** = new devices sign in by themselves, as the shared token does today; a key sits in iCloud
  Keychain. **Off** = every device needs its own sign-in; nothing extra in iCloud; less convenient than today.
  Doing nothing = on (the default below).
- Recommendation: **on**, because O6's goal is "still signs in once", and the key can only add a device
  sign-in, is listed on the web, and can be turned off.
- Reply needed: "on" or "off". Does not block; one constant.

**B. Needs the owner.** *May a device whose sign-in ended (30 days unused) sign itself back in with the key?*
- Why: the server cannot tell "ran out" from "signed out remotely" without new schema, so allowing one
  allows both.
- Options: **No** = any rejected token means a manual sign-in on that device; a lost or signed-out phone
  never comes back by itself. **Yes, with a clock guess** = convenient for a rarely used iPad, but a lost
  phone left off for a month could return.
- Recommendation: **no**; safer and simpler, and it matches how the web behaves after 30 days.
- Reply needed: "no" or "yes". Does not block.

**Defaults taken (no owner decision needed; each trivially changeable):**

| # | Question | Default |
|---|---|---|
| 1 | Key lifetime and cap | 180 days, extended on each swap; 5 per person and platform |
| 2 | "Sign out everywhere" removes web push subscriptions? | No: never tied to a sign-in |
| 3 | Web "Sign out everywhere" button behind the switch? | Yes, so it arrives with its release note |
| 4 | Install-id recording behind the switch? | No: only new apps send one |
| 5 | Does tvOS sync keychain items? | Treated as no (no key on tvOS); device check 7 |
| 6 | Device names | Generic; renamed on the web; no special entitlement |
| 7 | Delete a dead shared token from iCloud? | Never; new code only reads it |
| 8 | Revoked keys | Deleted 30 days after revocation or expiry |
| 9 | D10 (shared web cookie) | Its own PR after PR 3 |
| 10 | Rate limits | As in section 2 |
