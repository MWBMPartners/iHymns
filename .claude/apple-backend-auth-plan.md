# Apple Backend Auth Plan — `auth_apple` (#1402) + `account_delete` (#1403)

> Deep-planning output (Fable 5, 2026-07-08) for the two **App-Store-review-blocking**
> backend endpoints in `appWeb/` (PHP + mysqli). Companion to
> `.claude/apple-native-strategy.md` §1.6 / §1.9 / §3.2 (the "New-endpoint security"
> acceptance criteria) and the owner runbook `.claude/apple-native-owner-runbook.md`.
> **PLAN ONLY — no production code was written.** Implementer target: Sonnet, per
> strategy §3.4 tiering. Epic #895; issues **#1402** (auth_apple) and **#1403**
> (account_delete); labels `platform:apple`, `backend-for-apple`, `apple-phase-1`.

---

## 0. Ground truth (surveyed from the ACTUAL code — build against these names)

**Dispatcher.** All API actions live in ONE switch in
`appWeb/public_html/api.php` (13,174 lines), keyed on `$_GET['action']`. New cases:
`case 'auth_apple':` (place directly after `case 'auth_email_login_verify':`, ~line 2935)
and `case 'account_delete':` (place after `case 'auth_change_username':`, ~line 4340).
Responses go through `sendJson(array $data, int $statusCode = 200)` (api.php:12977).

**The exact success shape `auth_apple` must return** — byte-identical to
`case 'auth_login'` (api.php:2178):

```json
{ "token": "<64-hex>",
  "user": { "id": 1, "username": "…", "display_name": "…",
            "role": "user", "avatar_service": null } }
```

This is load-bearing: the native client's DTO
`appApple/Packages/iHymnsKit/Sources/IHModels/AuthSession.swift` decodes exactly
`token` + `user.{id, username, display_name, role, avatar_service}` and its doc-block
already declares auth_apple will reuse it. (Note: `auth_email_login_verify` returns a
*slightly different* user object — adds `email`, omits `avatar_service`
(`_completeEmailLoginTxn()`, `manage/includes/auth.php:1712`). **Match `auth_login`,
not the email path.**)

**Bearer-token machinery (reuse, don't reinvent).**
- Table `tblApiTokens` (`appWeb/.sql/schema.sql:771`): `Token VARCHAR(64) PK`
  (= **sha256 hex of the raw token** — raw never stored), `UserId INT UNSIGNED FK→tblUsers
  ON DELETE CASCADE`, `CreatedAt`, `ExpiresAt TIMESTAMP`.
- Mint: `$token = bin2hex(random_bytes(32)); $tokenHash = hash('sha256', $token);
  $expiresAt = gmdate('c', time() + 30*86400);` then
  `INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?,?,?)` with
  `bind_param('sis', …)` (api.php:2151-2158). Cross-subdomain cookie via
  `setAuthTokenCookie($token, $expiresAtTs)` (`includes/auth_cookie.php:93`).
- Validate: `getAuthBearerToken()` (api.php:13023 — `Authorization: Bearer <64-hex>`
  with `ihymns_auth` **cookie fallback** — CSRF implication in §3) →
  `getAuthenticatedUser()` (api.php:13092 — joins tblApiTokens→tblUsers, checks
  `ExpiresAt > now` + `IsActive = 1`, slides expiry via `slideAuthTokenExpiry()`).

**User table.** `tblUsers` (schema.sql:714): `Id INT UNSIGNED`, `Username VARCHAR(100)
UNIQUE`, `Email VARCHAR(255) NOT NULL DEFAULT ''` (**NOT unique** — matters for
email-linking), `EmailVerified TINYINT(1)`, `PasswordHash VARCHAR(255)` (**`''` =
passwordless account** — the email-magic-link path), `DisplayName`, `Role VARCHAR(20)`,
`IsActive`, `AccessTier`, `Settings JSON`, `AvatarService VARCHAR(20) NULL` (#616,
column-existence-gated in both login paths). User creation precedents:
`auth_register` (api.php:1806) and the transactional find-or-create
`completeEmailLogin()` / `_completeEmailLoginTxn()` (`manage/includes/auth.php:1594/1624`)
— username = sanitised email local-part + uniquifier loop, first user → `global_admin`,
whole flow in ONE transaction (#1011). **auth_apple's create path mirrors
`_completeEmailLoginTxn()`.**

**Confirmed absent:** `tblUserAuthProviders` does not exist anywhere in `schema.sql`
or the codebase (grep clean). No JWT/JWKS code exists anywhere in `appWeb`
(only hit: `includes/EmailService.php` uses openssl/curl for mail transports).
**No composer / no vendor dir** → RS256 verify and ES256 signing are hand-rolled on
`openssl_*` (plan §2.3/§3.4). Outbound-HTTP precedent: raw cURL in
`EmailService.php` (`CURLOPT_TIMEOUT 15`, `CURLOPT_CONNECTTIMEOUT 5`),
`includes/ip_geolocation.php`, `manage/places-api.php`. No shared wrapper — the new
`includes/apple_siwa.php` helper owns its own curl calls with those same timeouts.

**Config/secrets plumbing.** `getAppSetting(string $key, ?string $default)` in
`includes/maintenance.php:45` — memoized, swallows all Throwables (DB-outage-safe).
Writes via the `$saveSetting` closure in `manage/configuration.php:166`
(`INSERT … ON DUPLICATE KEY UPDATE` on `tblAppSettings(SettingKey PK, SettingValue TEXT)`).
The **#1401 precedent** (commit `f73c01d3`, on `feat/apple-universal`): the AASA
responder `public_html/.well-known/apple-app-site-association.php` reads
`getAppSetting('apple_team_id')`; the owner sets it on `manage/configuration.php`'s
"Apple native app" card (`$action === 'save_apple'`, line 404, shape-validated
`^[A-Z0-9]{10}$`). Multi-line SECRET precedent: `email_gmail_sa_json` (a service-account
private key!) is a `secret => true` textarea persisted in `tblAppSettings` — value never
echoed back into HTML, blank-on-save = keep existing, key-names-only in the audit log.
Owner-provisioned FILE precedent: `appWeb/.auth/` (gitignored, `.gitignore:190-195`;
`db_credentials.php`, `audio_signing_key.php`) — but files there are written per-docroot
whereas `tblAppSettings` rides the ONE shared MySQL across all 3 docroots. **Decision
§4: the SIWA `.p8` goes in `tblAppSettings` (secret field), not `.auth/`.**

**Migrations.** Registry = `manage/includes/migration-registry.php` (returns
`slug => ['script','card'=>['title','body','button'],'probe']`; array order = bulk-run
order; probe helpers `_migProbe_tableExists`/`_migProbe_columnExists`). Runner =
`/manage/setup-database` (web-run — **migrations are NOT auto-applied on deploy**;
the shared-MySQL / 3-docroot reality means new-table reads must be
existence-gated until the owner runs the card). Script shape = self-contained
idempotent PHP per `appWeb/.sql/migrate-add-read-rate-limit.php` (loads
`appWeb/.auth/db_credentials.php`, `mysqli_report(STRICT)`, `_mig*_tableExists` guard,
`@migration-adds` doctags, CLI + dashboard dual-mode). CI guards:
`tests/php/test-schema-coverage.php` (migrations ⊆ schema.sql, byte-identical DDL)
and `tests/php/test-migration-registry.php` (three facets + no always-true probes).

**Brute-force / rate-limit precedent.** `tblLoginAttempts (IpAddress, Username,
Success, AttemptedAt)` — per-IP window count `>= 10` failures / 15 min → 429
(auth_login api.php:2047-2069); sentinel usernames for non-login counters
(`'email_verify'`, api.php:2838-2854). auth_apple and account_delete each get their own
sentinel (`'auth_apple'`, `'account_delete'`).

**Audit.** `logActivity($action, $entityType, $entityId, $details, $result, $userId)`
(`includes/activity_log.php:163`) → `tblActivityLog` (FK UserId → tblUsers **ON DELETE
SET NULL**). House convention for tokens: only
`substr(hash('sha256', $token), 0, 12)` as `token_prefix`, never the token.

**Account-deletion substrate.** `deleteUser(int $userId)`
(`manage/includes/auth.php:1339`) already relies on the FK graph:
`DELETE FROM tblUsers WHERE Id = ?` cascades/nullifies everything. The complete FK
inventory from schema.sql is enumerated in §3.2 — plus the two PII stragglers FKs do
NOT clean (`tblSongRequests.ContactEmail/IpAddress`, `tblLoginAttempts.Username`).

**CSRF.** `validateCsrfRequest()` (`manage/includes/auth.php:1107`, rule #29):
same-origin `X-Requested-With` (+ Origin/Referer host match) OR a valid session token.
The native client already sends `X-Requested-With` on every POST (strategy §1.5).
Relevant because `getAuthBearerToken()` falls back to the `ihymns_auth` cookie —
without a CSRF gate, `account_delete` would be triggerable cross-site (§3.1 step 0).

**Test harness.** `tests/php/*.php` = standalone, DB-free scripts (exit 0/1) wired
individually into `.github/workflows/test.yml` (~line 200+). Model files:
`test-migration-registry.php` (loads the registry with stubbed probe helpers),
`test-schema-coverage.php` (parses schema.sql via `includes/schema_audit.php`),
`test-song-similarity.php` (pure-function assertion style).

---

## 1. Schema — `tblUserAuthProviders` + `tblAuthNonces` (ONE one-pass migration)

Per rule #20 (one-pass forward-looking): design the FINAL DDL now, ship BOTH tables in
ONE additive, idempotent, dormant migration. The nonce store is a **table**, not a
`tblAppSettings` sentinel — justification: nonce consumption must be an **atomic
INSERT with a UNIQUE key** (duplicate-key = replay detection, race-safe under two
concurrent submissions of the same stolen token); a settings-row read-modify-write has
a TOCTOU race and would bloat one TEXT row unboundedly. Precedent: the
`tblApiKeyIdempotency` / `tblReadRateLimit` pattern (small windowed table + prune).

### 1.1 DDL — `tblUserAuthProviders`

```sql
-- ----------------------------------------------------------------------------
-- tblUserAuthProviders (#1402)
-- External identity-provider links (Sign in with Apple first; forward-looking
-- for any future provider — Provider is VARCHAR, app-validated, never ENUM,
-- rule #20). Identity is ALWAYS (Provider, Subject) — NEVER email (Apple
-- private-relay addresses are per-app aliases the user can disable/rotate).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserAuthProviders (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL COMMENT 'FK to tblUsers — the iHymns account this provider identity signs in as',
    Provider        VARCHAR(30)     NOT NULL COMMENT 'apple | (future: google, …) — app-validated vocabulary, VARCHAR not ENUM (rule #20)',
    Subject         VARCHAR(128)    NOT NULL COMMENT 'Provider-stable subject claim (Apple JWT `sub`, e.g. 001234.<32hex>.5678) — the ONE durable identity key',
    Email           VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Provider-asserted email at link time (may be @privaterelay.appleid.com); INFORMATIONAL ONLY — never an identity key',
    EmailIsPrivateRelay TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = Email is an Apple private-relay alias (host privaterelay.appleid.com)',
    RefreshToken    TEXT            NULL COMMENT 'Provider refresh token (Apple: minted by /auth/token code exchange at first sign-in) — required by account_delete to call Apple /auth/revoke. Useless without the owner-provisioned .p8 client secret; NEVER logged, NEVER echoed by any API',
    IdentityJson    JSON            NULL COMMENT 'Forward-looking provider extras captured at link time: fullName snapshot (Apple sends it ONLY on first authorization), real_user_status, email_verified — additive without a second migration (rule #20)',
    LastLoginAt     TIMESTAMP       NULL DEFAULT NULL COMMENT 'Last successful sign-in through this provider link',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_provider_subject (Provider, Subject),
    UNIQUE KEY uq_user_provider    (UserId, Provider),
    INDEX      idx_User            (UserId),

    CONSTRAINT fk_AuthProviders_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Design notes (adversarially chosen):
- `UNIQUE (Provider, Subject)` — one Apple identity can only ever resolve to one
  iHymns account (the strategy §1.6 requirement); the INSERT race between two
  concurrent first-logins of the same Apple ID collapses to one winner + one
  duplicate-key error the handler retries as a lookup.
- `UNIQUE (UserId, Provider)` — one account holds at most ONE Apple link. Without
  this, account_delete's revoke would silently miss second links, and "link" replays
  would accrete rows. A second-device link attempt for a DIFFERENT Apple ID on the
  same account → 409 (deliberate; unlink/relink is a future admin feature).
- Width maths: `Provider(30) + Subject(128)` = 158 chars × 4 bytes (utf8mb4) = 632 B
  < the 767 B COMPACT-row index cap — safe even on the most conservative shared-host
  MySQL row format. Apple `sub` is ~41-44 chars; 128 leaves headroom for other
  providers (Google's 21-digit sub, OIDC URLs).
- `RefreshToken` deliberately NOT hashed (it must be replayed to Apple at revoke
  time) and deliberately NOT in a separate table (1:1 with the link row; deleting the
  link deletes the token). Residual risk + mitigation in §5.
- FK `ON DELETE CASCADE` — account deletion erases the provider link + refresh token
  with the user row (§3.2), and the FK-coverage test (§6.5) locks this in.

### 1.2 DDL — `tblAuthNonces`

```sql
-- ----------------------------------------------------------------------------
-- tblAuthNonces (#1402)
-- Single-use nonce consumption ledger (anti-replay) for provider sign-in
-- assertions. A nonce is CONSUMED by inserting its hash; the UNIQUE key makes
-- a second consumption a duplicate-key error = replay = reject. Purpose is a
-- VARCHAR discriminator (rule #20) so future flows (siwa_reauth, handoff, …)
-- share the table without a second migration. Rows are prunable after
-- ExpiresAt (DATETIME not TIMESTAMP — the #1066 TTL convention).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblAuthNonces (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Purpose         VARCHAR(30)     NOT NULL COMMENT 'siwa_login | siwa_reauth | … — app-validated vocabulary, VARCHAR not ENUM (rule #20)',
    NonceHash       CHAR(64)        NOT NULL COMMENT 'sha256 hex of the RAW client nonce (raw nonce never stored)',
    UsedAt          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       DATETIME        NOT NULL COMMENT 'Prune horizon (UsedAt + 15 min — longer than any Apple identity-token exp window)',

    UNIQUE KEY uq_purpose_nonce (Purpose, NonceHash),
    INDEX      idx_Expires      (ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Opportunistic pruning (`DELETE FROM tblAuthNonces WHERE ExpiresAt < UTC_TIMESTAMP()
LIMIT 200`) runs inside the auth_apple handler before consumption — the same
in-band prune pattern the presence/rate-limit tables use; no cron exists on the
shared host, so never plan for one.

### 1.3 `schema.sql` insertion point + migration script + registry entry (rule #19)

- **schema.sql:** append BOTH blocks at the END of `appWeb/.sql/schema.sql` (after the
  `tblReadRateLimit` block, line 3766+), under a new banner
  `-- AUTH PROVIDERS (Sign in with Apple) — #1402`. DDL must be **byte-identical**
  between schema.sql and the migration (COMMENT text included) — CI
  `test-schema-coverage.php` enforces.
- **Migration script:** `appWeb/.sql/migrate-user-auth-providers.php`, cloned from the
  shape of `migrate-add-read-rate-limit.php`: `declare(strict_types=1)`, doc-block with
  `@migration-adds tblUserAuthProviders.UserId` and `@migration-adds
  tblAuthNonces.NonceHash` doctags (one per table is enough for CREATE TABLE — the
  scanner keys tables off CREATE), `.auth/db_credentials.php` load, STRICT mysqli,
  `_mig*_tableExists()` guard per table (each independently skippable so a partial
  apply heals on re-run), CLI + dashboard dual-mode output.
- **Registry:** append ONE entry at the END of the array in
  `manage/includes/migration-registry.php`:

```php
'user-auth-providers' => [
    'script' => 'migrate-user-auth-providers.php',
    'card' => [
        'title'  => 'Sign in with Apple — provider links + nonce ledger (#1402)',
        'body'   => 'Creates <code>tblUserAuthProviders</code> (external identity-provider'
                  . ' links — Apple <code>sub</code> → iHymns user, refresh-token custody for'
                  . ' account-deletion revocation) and <code>tblAuthNonces</code> (single-use'
                  . ' sign-in nonce ledger, anti-replay). Additive, dormant until the'
                  . ' <code>auth_apple</code> endpoint is called. Idempotent — safe to re-run.',
        'button' => 'Run Auth Providers Migration',
    ],
    /* Multi-object OR-probe (rule #19): a partial apply must never show green. */
    'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblUserAuthProviders')
                                      || !_migProbe_tableExists($db, 'tblAuthNonces'),
],
```

- **Dormancy under STRICT mysqli (the #1228 lesson):** api.php's new handlers run on
  docroots where the card may not have been run yet. `auth_apple` therefore
  table-existence-probes `tblUserAuthProviders` ONCE at handler entry (same
  INFORMATION_SCHEMA pattern as the AvatarService gate, api.php:2073) and returns
  `503 {"error":"Sign in with Apple is not yet available."}` when absent — never an
  uncaught `mysqli_sql_exception`. Same probe in `account_delete` before touching
  provider rows (absence there just skips the Apple-revoke step).

---

## 2. `?action=auth_apple` — Sign in with Apple (the adversarial core)

### 2.0 Shared helper module — `includes/apple_siwa.php` (ONE module, both endpoints)

Modularity rule: everything Apple-crypto lives in ONE new include,
`appWeb/public_html/includes/apple_siwa.php` (direct-access 403 guard like
`audio_signing.php:53`). Both `auth_apple` and `account_delete` consume it; the
`tests/php/` suite unit-tests its PURE functions without DB or network. Function
inventory (signatures are the testability contract — crypto cores take injected
inputs, never reach for curl/DB themselves):

| Function | Purity | Job |
|---|---|---|
| `appleSiwaDecodeJwtParts(string $jwt): ?array` | pure | Split compact JWS, strict base64url-decode 3 segments, JSON-decode header/payload; null on ANY malformation (segment count ≠ 3, invalid b64url, non-array JSON, jwt > 8192 bytes) |
| `appleSiwaJwkToPem(array $jwk): ?string` | pure | RSA JWK `{kty:RSA, n, e}` → PEM SPKI via hand-built DER (b64url-decode n/e, INTEGER-encode with leading-zero guard, SEQUENCE wrap, `rsaEncryption` OID) — needed because openssl can't consume JWK directly |
| `appleSiwaVerifyIdentityToken(string $jwt, array $jwks, string $expectedAud, string $rawNonce, int $now): array` | pure | THE verifier: returns `['ok'=>true,'claims'=>…]` or `['ok'=>false,'reason'=>'…']` (reason vocabulary drives tests + server-side logging; the client only ever sees a generic 401) |
| `appleSiwaBuildClientSecret(string $teamId, string $keyId, string $clientId, string $p8Pem, int $now): ?string` | pure | ES256 client-secret JWT for Apple server-to-server calls (§3.4) |
| `appleSiwaFetchJwks(): ?array` | curl | GET `https://appleid.apple.com/auth/keys` (timeouts 5/15 like EmailService; TLS verify ON — never set VERIFYPEER/HOST off) |
| `appleSiwaJwksCached(\mysqli $db, ?string $needKid): ?array` | DB+curl | Cache read-through (§2.3) |
| `appleSiwaConsumeNonce(\mysqli $db, string $purpose, string $rawNonce): bool` | DB | Prune + `INSERT` into tblAuthNonces; catches errno **1062** → `false` (= replay) |
| `appleSiwaExchangeCode(string $code, string $clientSecret, string $clientId): ?array` | curl | POST `https://appleid.apple.com/auth/token` (grant_type=authorization_code) → `{refresh_token, …}` or null |
| `appleSiwaRevokeToken(string $token, string $tokenTypeHint, string $clientSecret, string $clientId): bool` | curl | POST `https://appleid.apple.com/auth/revoke` → HTTP 200 = true |

Constant: `const IHYMNS_SIWA_CLIENT_ID = 'app.ihymns';` — the bundle id is public,
identical on every Apple platform (strategy §1.7), already committed throughout
`appApple/`; it is NOT a secret and NOT owner-variable, so it is code, not config.
(Future web-SIWA would add the reserved Services ID `app.ihymns.web` as a second
accepted `aud` — an array constant extension, no schema change.)

### 2.1 Request contract

`POST /api?action=auth_apple` — JSON body (every field type-coerced/validated before use):

| Field | Required | Validation |
|---|---|---|
| `identityToken` | yes | string, 1–8192 bytes, exactly 3 dot-separated base64url segments |
| `nonce` | yes | string (the RAW nonce), 16–255 chars, charset `[A-Za-z0-9._~-]` |
| `authorizationCode` | no | string ≤ 1024, base64url-ish charset — enables the §2.6 refresh-token exchange |
| `email` | no | `filter_var(FILTER_VALIDATE_EMAIL)`, lowercased — client-relayed convenience copy; the JWT `email` claim ALWAYS wins when present |
| `fullName` | no | object `{givenName?, familyName?}`, each `mb_substr(trim(…), 0, 100)` — Apple surfaces the name ONLY in the FIRST authorization's credential (never in the JWT); used for DisplayName on create + snapshotted into IdentityJson |
| `link` | no | `'1'`/`1`/`true` → link mode (§2.5c) — requires a VALID current bearer token |

**Nonce contract with the native client (document in api-docs.yaml + the SIWA client
issue):** client generates ≥32 bytes of CSPRNG randomness → `rawNonce` (hex string);
sets `ASAuthorizationAppleIDRequest.nonce = SHA256-hex(rawNonce)`; sends `rawNonce`
to us. Server recomputes `hash('sha256', $rawNonce)` and compares to the JWT `nonce`
claim with `hash_equals()`. The raw value never transits Apple; the hash never
transits us except inside the signed JWT.

### 2.2 Handler sequence (exact order — each step names its failure exit)

0. `REQUEST_METHOD !== 'POST'` → **405** `POST method required.` (house shape).
1. **Feature gates:** `tblUserAuthProviders` existence probe (§1.3) → **503** if
   un-migrated. (No `apple_team_id`/`.p8` needed for VERIFY — JWKS is public — so
   sign-in works even before the owner provisions the key; only the §2.6 exchange
   silently skips.)
2. **Rate limit:** count `tblLoginAttempts` failures `Username='auth_apple'` for this
   `REMOTE_ADDR`, 15-min window, ≥10 → **429** (mirror api.php:2047; record a
   Success=0 row on every failure exit below, Success=1 on success).
3. **Parse + validate body** (§2.1 table) → **400** with a field-specific message on
   any miss. `json_decode(file_get_contents('php://input'), true)` per house style.
4. **Decode JWT parts** (`appleSiwaDecodeJwtParts`) → **401** `Invalid Apple identity
   token.` on null. Header checks: `typ` absent-or-`JWT`; **`alg` MUST be exactly
   `RS256`** (reject `none`, `HS256` — algorithm-confusion kill); `kid` present,
   string, ≤ 64 chars.
5. **JWKS resolve** (§2.3): cached-or-fetched key set; select key by `kid` (the kid
   is ONLY ever an array lookup into Apple-fetched JWKS — never a URL, file path, or
   query input). Unknown kid after ONE forced refetch → **401**. Apple unreachable
   AND cache empty → **503** + `Retry-After: 30` (the api.php maintenance idiom).
6. **Signature:** `openssl_verify("$h64.$p64", $sigRaw, $pem, OPENSSL_ALGO_SHA256)
   === 1` → else **401**. (`$sigRaw` = base64url-decoded signature; PKCS#1 v1.5 —
   what `openssl_verify` does natively for RSA keys.)
7. **Claims** (all with strict `===` string compares; 60 s clock skew leeway):
   - `iss === 'https://appleid.apple.com'` → else **401**;
   - `aud` — string equal to `IHYMNS_SIWA_CLIENT_ID`, or (spec-tolerant) an array
     that contains it AND nothing unexpected matters → any other value **401**.
     *This is the "aud-confusion" kill: a valid Apple token minted for ANOTHER
     developer's app replayed at us dies here;*
   - `exp` numeric, `> $now - 60` → else **401** (expired);
   - `iat` numeric, `< $now + 60` → else **401** (future-dated);
   - `sub` non-empty string ≤ 128 → else **401**;
   - `nonce` claim present AND `hash_equals($claims['nonce'], hash('sha256', $rawNonce))`
     → else **401**. A token WITHOUT a nonce claim is REJECTED outright (our client
     always sends one; accepting nonce-less tokens would reopen replay via tokens
     harvested from other Apple-SDK contexts).
8. **Anti-replay consume:** `appleSiwaConsumeNonce($db, 'siwa_login', $rawNonce)` →
   `false` = **401** `Invalid Apple identity token.` (deliberately the SAME message as
   other 401s — no replay oracle; the true reason goes to logActivity only).
   Consumption happens AFTER full verification so an attacker cannot burn a victim's
   in-flight nonce with a garbage token carrying the same nonce.
9. **Map `sub` → account** (§2.5) inside ONE transaction (mirror
   `completeEmailLogin()`'s wrapper): resolve/create/link; `IsActive = 0` → **403**
   `Account is disabled.` (auth_login parity, api.php:2129).
10. **Refresh-token exchange** (§2.6) — best-effort, non-fatal, AFTER commit.
11. **Mint the standard token** exactly as api.php:2151-2161 (`bin2hex(random_bytes(32))`,
    sha256 stored, `gmdate('c', +30d)`, `setAuthTokenCookie()`), update
    `tblUsers.LastLoginAt/LoginCount` (api.php:2143) and
    `tblUserAuthProviders.LastLoginAt`.
12. **Audit:** `logActivity('auth.login_apple', 'user', (string)$userId,
    ['username'=>…, 'flow'=>'matched|created|linked', 'private_relay'=>bool,
    'token_prefix'=>substr(hash('sha256',$token),0,12)], 'success', $userId)` —
    NEVER the sub, email claim, nonce, identityToken, or refresh token.
13. **Respond 200** with the §0 auth_login-identical payload (including the
    `AvatarService` column-existence gate, api.php:2073-2082).

### 2.3 JWKS fetch + cache (key-rotation-proof, shared-host-realistic)

No APCu/filesystem cache is dependable on shared DreamHost → cache in the shared DB:
ONE `tblAppSettings` row `apple_jwks_cache` holding
`{"fetchedAt": <unix>, "keys": [...]}` (written with the same
INSERT…ON DUPLICATE KEY UPDATE shape as `$saveSetting`). Read path
(`appleSiwaJwksCached`):

1. Read row (prepared statement; treat parse failure as absent).
2. Cache **fresh** (< 24 h) AND contains `$needKid` → use it.
3. Cache stale, OR `kid` missing from it (— **Apple key rotation**: Apple serves
   multiple concurrent keys and rotates unannounced; a kid-miss on a fresh cache is
   the rotation signal) → refetch, **but at most once per 5 minutes globally**
   (compare `fetchedAt`; a forced refetch updates `fetchedAt` even on miss). This
   caps the "attacker hammers us with made-up kids to make us DoS Apple / ourselves"
   vector: an unknown kid inside the 5-min window is a plain 401, no fetch.
4. Refetch validates shape (`keys` array of `{kty:RSA, kid, n, e}`) before overwrite —
   a bad/empty fetch NEVER clobbers a good cache (poisoning + outage resilience);
   fetch happens ONLY from the hardcoded `https://appleid.apple.com/auth/keys` with
   default TLS verification (the transport IS the trust anchor — never disable peer
   verification, never follow redirects off-host: `CURLOPT_REDIR_PROTOCOLS` https-only,
   `CURLOPT_MAXREDIRS 0`).

### 2.4 RS256 verification core (hand-rolled, minimal, testable)

No JWT library exists in the repo and none is being added (no composer pipeline; a
vendored lib is a bigger review surface than 60 lines of openssl glue). The entire
crypto core is: strict base64url decode (reject `+`, `/`, `=`-padding anomalies —
`strtr` + length-mod check, `base64_decode(..., true)`), the JWK→DER→PEM builder, and
ONE `openssl_verify` call. The §6 tests attack it with tampered/foreign/alg-swapped
tokens generated from a test keypair. Explicitly rejected alternatives: `firebase/php-jwt`
(no vendoring mechanism in this repo), JWT parsing via regex (no), accepting `alg`
from the token to choose the verify function (THE classic vuln — the algorithm is
pinned to RS256 by code, the header merely must agree).

### 2.5 `sub` → account mapping (create-or-link, private-relay-proof)

All inside one transaction (`$db->begin_transaction()` … commit/rollback — the
#1011 pattern), keyed on the verified `sub`:

**(a) Existing link** — `SELECT UserId FROM tblUserAuthProviders WHERE Provider='apple'
AND Subject=?` → hit: fetch user, check `IsActive`, done (flow=`matched`). This is the
99% warm path and it never touches email — **a user who disables their private-relay
alias or flips share-my-email keeps signing in fine** because identity is
`(Provider, Subject)`.

**(b) No link + explicit `link=1` + valid bearer** — the strategy §3.2 requirement
"link-to-existing needs current bearer": attach this Apple identity to the
ALREADY-AUTHENTICATED account (`getAuthenticatedUser()` result; 401 if bearer
absent/invalid in link mode). INSERT the provider row; duplicate `uq_user_provider`
→ **409** `This account is already linked to a different Apple ID.`; duplicate
`uq_provider_subject` (someone else owns this Apple ID) → **409** `This Apple ID is
already linked to another iHymns account.` Flow=`linked`. *An unauthenticated caller
can NEVER link — otherwise a stolen identityToken would let an attacker attach their
Apple ID to a victim account.*

**(c) No link, no link-mode → email auto-link, STRICT conditions** — take the email
from the **JWT claim only** (never the client-relayed field): auto-link to an existing
user IFF (1) JWT `email_verified` is `true`/`'true'`, (2) exactly ONE `tblUsers` row
matches `LOWER(Email) = ?` (Email is NOT unique — 0 or ≥2 matches → no auto-link),
(3) that row has `EmailVerified = 1`, (4) that row has no existing apple link
(`uq_user_provider`). All four hold → INSERT link, flow=`linked`. *Condition (3) is
the account-takeover kill: without it, an attacker pre-registers an unverified iHymns
account with the victim's address and waits for the victim's first SIWA login to
inherit it.* Private-relay addresses structurally never match (unique per Apple ID +
app), so this path is real-email-only by construction.

**(d) Nothing matched → create** — mirror `_completeEmailLoginTxn()`
(`manage/includes/auth.php:1624-1667`): username = sanitised local-part of the claim
email (or `apple_` + `bin2hex(random_bytes(3))` when the email is absent/relay-junk),
uniquifier counter loop, `PasswordHash=''` (passwordless — the established sentinel),
`Email` = JWT claim email or `''`, `EmailVerified = 1` when the claim asserted
verified (Apple relay addresses ARE deliverable + verified), `Role` = `'user'`
(**hard-code — do NOT copy the first-user→global_admin bootstrap**: a fresh-install
race where the first account arrives via a replayable-looking public endpoint should
never mint an admin; the web installer owns bootstrap), `DisplayName` from `fullName`
(given + family, trimmed) else ucfirst(local-part). Then INSERT the provider row with
`EmailIsPrivateRelay = (str_ends_with($claimEmailHost, 'privaterelay.appleid.com'))`,
`IdentityJson = {fullName?, real_user_status?, email_verified}`. Flow=`created`.
A concurrent-duplicate `uq_provider_subject` INSERT error inside (d) → rollback,
re-run (a) once (the race winner's row now exists).

### 2.6 Refresh-token capture (feeds #1403's Apple revoke)

Apple's `/auth/revoke` (required at account deletion since App Review's June-2022
policy) needs a refresh or access token, and refresh tokens are ONLY obtainable by
exchanging the `authorizationCode` at `/auth/token` **within ~5 minutes of the
authorization**. Therefore: when the request carried `authorizationCode` AND the
owner has provisioned the `.p8` trio (§4), then AFTER the mapping transaction
commits: build client_secret (§3.4), POST the exchange (10 s budget), and
`UPDATE tblUserAuthProviders SET RefreshToken=? WHERE Provider='apple' AND Subject=?`.
**Every failure here is non-fatal** (log `auth.apple_token_exchange` failure row,
no body contents): sign-in must never break because Apple's token endpoint hiccuped
or the owner hasn't pasted the key yet. account_delete (§3.3) has a compensating
path for links with `RefreshToken IS NULL`.

### 2.7 Failure-path summary table

| Condition | HTTP | Body `error` |
|---|---|---|
| non-POST | 405 | `POST method required.` |
| un-migrated table | 503 | `Sign in with Apple is not yet available.` |
| per-IP failure budget hit | 429 | `Too many attempts. Please try again later.` |
| body/field validation | 400 | field-specific (house style) |
| malformed JWT / bad alg / unknown kid / bad signature / iss / aud / exp / iat / nonce mismatch / **nonce replay** | 401 | `Invalid Apple identity token.` (ONE generic message; the precise `reason` goes only to tblActivityLog) |
| JWKS unreachable + no cache | 503 (+`Retry-After: 30`) | `Sign in with Apple is temporarily unavailable.` |
| link mode without valid bearer | 401 | `Not authenticated.` |
| link conflicts | 409 | (two messages, §2.5b) |
| account disabled | 403 | `Account is disabled.` |
| success | 200 | §0 payload |

---

## 3. `?action=account_delete` — self-service account deletion (App Review 5.1.1(v))

### 3.1 Request contract + gates (identity is NEVER a parameter)

`POST /api?action=account_delete`. The account to delete is derived **exclusively**
from `getAuthenticatedUser()` — the handler accepts NO user-id/username/email
selector of any kind, which structurally kills "attacker deletes someone else's
account by id". Admin-deletes-other-user stays where it already lives
(`/manage/users` → `deleteUser()`).

Gate order:
0. **405** non-POST. Then **CSRF**: because `getAuthBearerToken()` (api.php:13023)
   falls back to the `ihymns_auth` cookie, a bare cookie-authenticated POST here would
   be cross-site forgeable. Require `validateCsrfRequest()` (rule #29 —
   `manage/includes/auth.php:1107`; require_once the manage auth include exactly the
   way `auth_email_login_request` does at api.php:2717). The native client already
   sends `X-Requested-With` on every POST; the PWA account page does AJAX same-origin.
   Failing → **403** `Cross-origin request rejected.` (Re-auth below is the second,
   independent CSRF barrier — an attacker can't forge a password/code/assertion.)
1. **401** no/invalid bearer (`getAuthenticatedUser()` null).
2. **Rate limit re-auth guesses:** `tblLoginAttempts` sentinel
   `Username='account_delete'`, per-IP, 10 failures/15 min → **429** (a password
   oracle otherwise).
3. **Re-auth (fresh proof-of-owner, exactly ONE required)** — body must satisfy the
   FIRST applicable rung:
   - `password` — allowed when `PasswordHash !== ''`; `password_verify()` against
     `tblUsers.PasswordHash` (the `auth_change_password` pattern, api.php:4234-4243).
     Wrong → **401** `Current password is incorrect.` + a Success=0 sentinel row.
   - `identityToken` + `nonce` — a **fresh SIWA assertion**: run the FULL §2 verify
     (Purpose **`siwa_reauth`** in tblAuthNonces — a login nonce can't be replayed
     into a delete and vice versa), then require the verified `sub` to equal THIS
     user's `tblUserAuthProviders.Subject` (Provider='apple') → else **401**. (Also
     accept optional `authorizationCode` here — §3.3 uses it when no refresh token is
     stored.)
   - `email_code` — for passwordless email accounts: 6-digit code minted by the
     EXISTING `auth_email_login_request` flow to the ACCOUNT's email;
     verify via `verifyEmailLoginCode($authUser['Email'], $code)`
     (`manage/includes/auth.php:1549` — single-use + 10-min expiry already enforced).
     Wrong → **401**.
   - Degenerate account (PasswordHash='' AND Email='' AND no provider row — should
     not exist via any current signup path): accept `confirm === 'DELETE'` with the
     bearer alone (documented; there is no credential to prove).
   - None supplied → **400** `Re-authentication required: provide password,
     identityToken+nonce, or email_code.`
4. **Last-global-admin guard:** if `Role === 'global_admin'` and no OTHER active
   global_admin exists (`SELECT COUNT(*) FROM tblUsers WHERE Role='global_admin' AND
   IsActive=1 AND Id != ?` = 0) → **409** `Transfer Global Admin to another account
   first.` — self-service deletion must never brick `/manage`.

### 3.2 The cascade — exact per-table effect of `DELETE FROM tblUsers WHERE Id = ?`

The FK graph (verified line-by-line in `appWeb/.sql/schema.sql`) already implements
the erase-vs-anonymise split. **Erased with the user (ON DELETE CASCADE):**

| Table | Content erased |
|---|---|
| `tblApiTokens` | every bearer token (ALSO deleted explicitly first — see 3.3 step T2) |
| `tblSessions` | admin-panel sessions |
| `tblPasswordResetTokens`, `tblEmailLoginTokens`, `tblEmailVerificationTokens` | all pending auth tokens |
| `tblUserAuthProviders` **(new)** | Apple link + stored RefreshToken |
| `tblUserFavorites`, `tblUserCustomTags` | favourites + tags |
| `tblUserSetlists`, `tblSetlistSchedule`, `tblSetlistCollaborators` (both roles: `SetlistOwnerId`, `CollaboratorId`) | setlists, schedules, collaborations |
| `tblUserPreferences`, `tblPushSubscriptions`, `tblNotifications` | prefs, push, notifications |
| `tblUserPurchases`, `tblOrganisationMembers`, `tblOrganisationLicences.UserId` rows, `tblUserGroupMembers`, `tblUserPermissions` | entitlement/membership rows |
| `tblLyricAnnotationVotes`, `tblPresentationThemeAssignments`, `tblPrintTemplates` (`OwnerId`) | votes, theme picks, templates |
| `tblLiveFollowSessions` (`HostUserId`) | any hosted live session ends (join codes + presence cascade off the session) |
| `tblApiKeyRequests` (`RequesterId`) | pending partner-key requests |

**Anonymised (ON DELETE SET NULL — contribution/audit history stays, authorship
pseudonymised):** `tblLyrics.SubmittedBy/ApprovedBy`, `tblApiKeys.CreatedBy`,
`tblSharedSetlists.CreatedBy/OwnerUserId` (existing share links keep working as
snapshots — the live-resolve half degrades by design, #1380),
`tblSongRequests.UserId`, `tblActivityLog.UserId`, `tblSetlistTemplates.CreatedBy`,
`tblSongRevisions.UserId/ReviewedBy`, `tblSongHistory.UserId`,
`tblSongTagMap.TaggedBy`, `tblSearchQueries.UserId`, `tblLyricsConflicts.ResolvedBy`,
`tblLyricsReviewQueue.AssignedTo/ReviewedBy`, `tblApiKeyRequests.ReviewedBy`,
`tblSongIdentityMap.VerifiedBy`, `tblLyricLineTranslations` +
`tblLyricLineAnnotations` (SubmittedBy/ApprovedBy/VerifiedBy),
`tblSongUsageEvents.UserId`, `tblSongQualityFindings.AssignedTo/ResolvedBy`,
`tblOrganisationExternalRefs`/`tblOrgVenueExternalRefs`/
`tblOrgServiceScheduleExternalRefs` (CreatedBy).

**PII the FKs do NOT clean — explicit pre-delete scrubs (inside the transaction,
BEFORE the user DELETE while `UserId` still resolves):**
1. `UPDATE tblSongRequests SET ContactEmail = '', IpAddress = '' WHERE UserId = ?`
   — otherwise the request rows keep the deleted user's email forever behind a NULL
   FK (schema.sql:1323-1325).
2. `DELETE FROM tblLoginAttempts WHERE Username = ?` (the account's Username) —
   the table has no FK (schema.sql:1870) and only feeds 15-minute windows; deleting
   is harmless and removes a username-to-IP trail.
3. No-action-by-design, documented: `tblActivityLog.Details` JSON of HISTORICAL rows
   (past logins recorded username/email per house convention) is retained under the
   security-audit legitimate-interest posture — flagged in §5 residuals as an owner
   policy knob (a future retention/pruning job, NOT this endpoint's scope);
   `tblReadRateLimit` rows key on sha256(token) of now-deleted tokens and age out with
   their windows; `tblSharedSetlists.Data` JSON contains song lists, not identity.

### 3.3 Handler sequence, transaction boundaries, idempotency

- **Pre-transaction (no DB writes):** load the user's `tblUserAuthProviders` row
  (Provider='apple', existence-gated §1.3). Determine the revoke token: stored
  `RefreshToken`; if NULL and the §3.1 re-auth was a fresh SIWA assertion carrying
  `authorizationCode` + the `.p8` is provisioned → exchange the code NOW (§2.6
  machinery) purely to obtain a revocable token.
- **A. Apple revoke FIRST, best-effort:** `appleSiwaRevokeToken($token,
  'refresh_token', $clientSecret, IHYMNS_SIWA_CLIENT_ID)` (10 s budget). Outcome ∈
  `ok | failed | skipped_no_key | skipped_no_token | skipped_no_link`. **A failed
  revoke NEVER blocks deletion** — the user's 5.1.1(v) right to delete wins; the
  outcome lands in the audit row so the owner can see systematic failures. Ordering
  rationale: revoke-before-delete, because after the local delete the refresh token
  is gone forever (rollback of the delete is possible; re-obtaining a revoke token
  is not).
- **B. The destructive transaction:**
  - T0 `begin_transaction()`;
  - T1 `SELECT Id FROM tblUsers WHERE Id = ? FOR UPDATE` — row absent → commit-noop
    and jump to the idempotent 200 (see below); this serialises a double-tap:
    the second concurrent request blocks on the lock, then sees no row;
  - T2 `DELETE FROM tblApiTokens WHERE UserId = ?` — explicit even though the FK
    would cascade: revocation is a stated security requirement, not an FK side
    effect, and the deleted-count feeds the audit row;
  - T3 the two PII scrubs (§3.2);
  - T4 `DELETE FROM tblUsers WHERE Id = ?` → the §3.2 graph fires atomically
    (InnoDB cascades are part of the same transaction — a mid-cascade failure rolls
    EVERYTHING back; there is no partial-delete state);
  - T5 `commit()`. Any throw → rollback → **500** house JSON (the api.php global
    handler, api.php:81) — the account remains fully intact and retryable.
- **C. Post-commit:** `clearAuthTokenCookie()`; audit row (below); respond
  `200 {"ok": true, "deleted": true}`.
- **Idempotency:** a repeat call arrives with a token T2 already destroyed →
  `getAuthenticatedUser()` = null → **401** — correct and terminal from the client's
  view (IHAuth treats 401 as signed-out). The T1 no-row branch covers the
  merely-theoretical race of one bearer surviving two overlapping requests: it
  returns the same `200 {"ok":true,"deleted":true}` rather than an error, so retries
  after a dropped response converge.

### 3.4 The Apple `client_secret` (ES256) — exact recipe (`appleSiwaBuildClientSecret`)

- Header: `{"alg":"ES256","kid":"<apple_siwa_key_id>"}`; claims:
  `{"iss":"<apple_team_id>", "iat": now, "exp": now + 300, "aud":
  "https://appleid.apple.com", "sub": "app.ihymns"}` (Apple allows exp ≤ 6 months;
  we mint per-call with 5 min — nothing to store, nothing to leak).
- Sign `base64url(header).".".base64url(claims)` with
  `openssl_sign(…, OPENSSL_ALGO_SHA256)` using the owner-provisioned `.p8` PEM
  (an EC P-256 PKCS#8 key — `openssl_pkey_get_private` consumes it directly).
- **The DER→JOSE trap:** `openssl_sign` returns an ASN.1 DER `SEQUENCE{r,s}`;
  JOSE ES256 requires the RAW 64-byte `r‖s`. Convert: parse the two DER INTEGERs,
  strip leading 0x00 sign bytes, left-pad each to exactly 32 bytes. (§6 tests this
  round-trip against `openssl_verify` with the re-DER-encoded form.)
- Used by BOTH `/auth/token` (exchange, §2.6) and `/auth/revoke` (§3.3A), POSTed as
  `application/x-www-form-urlencoded` (`client_id`, `client_secret`, plus
  `grant_type`+`code` / `token`+`token_type_hint` respectively).

### 3.5 Audit row — NO PII (stricter than the legacy convention, per the issue spec)

`logActivity('account.delete', 'user', (string)$userId, [
  'apple_revoke'   => 'ok|failed|skipped_no_key|skipped_no_token|skipped_no_link',
  'tokens_revoked' => <int>, 'had_apple_link' => <bool>,
  'reauth_method'  => 'password|siwa|email_code|confirm',
], 'success', null)` — numeric user id only (pseudonymous handle, matches the
`deleteUser()` EntityId precedent but WITHOUT its `before` username/email snapshot);
no username, no email, no display name, no tokens, no sub. Failure exits log
`'failure'` rows with `reason` codes only.

### 3.6 Failure-path summary table

| Condition | HTTP | Notes |
|---|---|---|
| non-POST | 405 | |
| CSRF (no X-Requested-With / cross-origin) | 403 | rule #29 gate |
| no/invalid/already-deleted bearer | 401 | = idempotent terminal state |
| re-auth proof missing | 400 | lists the three accepted proofs |
| re-auth proof wrong | 401 | + Success=0 sentinel row (rate-limit feed) |
| re-auth budget exhausted | 429 | 10/15 min per IP |
| last active global_admin | 409 | transfer first |
| DB throw mid-transaction | 500 | full rollback — account intact |
| success (incl. T1 no-row race) | 200 | `{"ok":true,"deleted":true}` |

---

## 4. Secrets & config — owner-provisioned via the web admin ONLY (no CLI/SSH)

**Storage decision:** all three SIWA values live in `tblAppSettings`, set on
`manage/configuration.php`'s existing **"Apple native app"** card (extend the
`save_apple` handler + form, #1401 precedent):

| Key | Field type | Validation on save | Read by |
|---|---|---|---|
| `apple_team_id` | text (EXISTS, #1401) | `^[A-Z0-9]{10}$` | AASA responder + client_secret `iss` |
| `apple_siwa_key_id` | text (NEW) | `^[A-Z0-9]{10}$` | client_secret `kid` header |
| `apple_siwa_private_key` | textarea, **`secret => true`** (NEW) | must parse: starts `-----BEGIN PRIVATE KEY-----`, `openssl_pkey_get_private()` succeeds AND reports `OPENSSL_KEYTYPE_EC`/P-256 — reject anything else with a clear message (catches pasting the ASC *deploy* key or a public key by mistake) | client_secret signer |

Why `tblAppSettings` and not an `appWeb/.auth/` file: the operator is **web-only on
shared DreamHost** (MEMORY.md — no shell; the SFTP deploy pipeline ships only
committed files, and `.auth/` is gitignored, so a key file would have to be
hand-placed per docroot with no mechanism to do it). The DB is the ONE store shared
by all three docroots — one paste covers alpha/beta/prod simultaneously — and the
`secret => true` plumbing (never re-echoed into HTML, blank-on-save keeps the
existing value, key-names-only in the audit log) already guards the exact same class
of material (`email_gmail_sa_json` is a Google service-account private key).
The `.p8` therefore NEVER exists in the repo, in CI, or on disk in a docroot —
consistent with the standing rule (credential files owner-provisioned, never
committed) and the strategy's "no secrets in bundle / `.p8` outside docroot" intent,
transposed to the web tier's storage reality. Residual: a full DB dump exposes it —
noted in §5 with mitigations.

Runtime read: `getAppSetting('apple_siwa_key_id')` etc. — memoized + throw-safe.
A missing/blank key trio ⇒ `appleSiwaBuildClientSecret` returns null ⇒ §2.6 exchange
and §3.3A revoke degrade to `skipped_no_key` — **sign-in and deletion both still
work** (JWKS verification needs no secret). The configuration card shows a
status badge per value (Set / Not set) exactly like the Team ID badge today
(configuration.php:601-604).

### 4.1 Owner runbook — "what to enter where" (feeds `.claude/apple-native-owner-runbook.md`)

1. **developer.apple.com → Certificates, Identifiers & Profiles → Identifiers →
   `app.ihymns`** → confirm the **Sign in with Apple** capability is enabled
   (runbook §A already covers this).
2. **Keys → ⊕ (Create a key)** → name `iHymns SIWA`, tick **Sign in with Apple**,
   Configure → primary App ID `app.ihymns` → Register → **Download the `.p8`**
   (one-time download — file it somewhere safe) and note the **Key ID** (10 chars)
   shown on the key page.
3. **Membership page** → copy your **Team ID** (10 chars) — you already entered this
   for #1401; verify it's saved (green badge).
4. **`https://www.ihymns.app/manage/configuration`** (and it applies to all three
   sites at once — shared database) → **Apple native app** card:
   - *Apple Team ID* → (already set, #1401);
   - *SIWA Key ID* → the 10-char Key ID from step 2;
   - *SIWA private key (.p8)* → open the downloaded `.p8` in a text editor, paste the
     ENTIRE contents including the `-----BEGIN/END PRIVATE KEY-----` lines → Save.
5. **`/manage/setup-database`** → run the **"Sign in with Apple — provider links +
   nonce ledger (#1402)"** card (after the code deploy reaches that docroot).
6. **Do NOT** commit the `.p8`, email it, or add it to GitHub secrets — the ASC
   deploy key (`APPLE_ASC_KEY_P8`, runbook §E) is a DIFFERENT key; never reuse one
   for the other.
7. (Before external TestFlight, already in runbook §C/⑤): register the mail-sending
   domain for **Sign in with Apple email communication** (SPF/DKIM) so mail to
   `@privaterelay.appleid.com` users delivers.

---

## 5. Security review checklist (standing rules → design mapping)

| Standing check | How this design satisfies it |
|---|---|
| Every `$_GET/$_POST/$_REQUEST`/body input type-coerced & validated | §2.1 / §3.1 tables: length caps, charset regexes, `filter_var` email, strict base64url decode; JWT capped at 8 KB; nonce charset-pinned; `fullName` mb-truncated; `link`/`confirm` coerced to exact literals. Nothing from the request reaches SQL, a URL, a header, or crypto without passing its validator. |
| Prepared statements / `bind_param` ONLY | Every query in §1–§3 is a prepared statement with bound params (house rule; the only interpolations are the fixed column-existence-gate SELECT fragments, matching the api.php:2082 precedent). mysqli STRICT throws — no false-checks; optional tables are INFORMATION_SCHEMA-gated (§1.3). |
| No secrets in committed code | `.p8`/Key ID/Team ID live in `tblAppSettings` via the secret-field admin UI (§4); `IHYMNS_SIWA_CLIENT_ID='app.ihymns'` is the only committed identifier and is public by definition. CI's no-secrets posture unaffected. |
| AuthZ — nobody acts on another account | auth_apple link-mode requires the victim-side bearer (§2.5b); email auto-link requires BOTH sides verified + unique match (§2.5c); account_delete takes NO account selector, requires bearer + fresh re-auth + CSRF gate (§3.1); admin deletion stays entitlement-gated in /manage. |
| Replay protection | tblAuthNonces single-use ledger with purpose separation (`siwa_login` vs `siwa_reauth`), consumed post-verification, atomic UNIQUE-key insert (§1.2, §2.2 step 8); Apple `exp`/`iat` enforced with 60 s skew. |
| Audit logging without PII | account_delete's row is id-only + outcome enums (§3.5). auth_apple follows the existing auth.login convention (username + token_prefix) but NEVER sub/email-claim/nonce/identityToken/RefreshToken. Failure rows carry reason codes only. |
| CSRF (rule #29) | account_delete gated by `validateCsrfRequest()` (§3.1 step 0). auth_apple is credential-presenting (the assertion IS the credential) — CSRF-irrelevant by construction, same class as auth_login. |
| Rate limiting | Both endpoints feed/read tblLoginAttempts sentinels (10 fails / 15 min / IP), mirroring auth_login + the #1386 email_verify fix. JWKS refetch capped at 1/5 min globally (§2.3). |
| Fail-open vs fail-closed | Crypto/authn = fail-CLOSED (any verification doubt → 401). Availability shims = fail-SOFT only where harmless: missing migration → 503, missing .p8 → skip exchange/revoke, JWKS outage with warm cache → keep verifying. |

**Named adversarial scenarios → kill points:** JWKS rotation (§2.3 kid-refetch) ·
alg confusion (§2.2 step 4 pins RS256) · kid injection (lookup-only, §2.2 step 5) ·
aud confusion / cross-app token replay (§2.2 step 7) · nonce replay incl.
cross-purpose (§1.2) · replay-oracle avoidance (single 401 message, §2.7) ·
pre-registered-email account takeover (§2.5c condition 3) · private-relay churn
(identity = (Provider,Subject), §1.1) · first-login-only name/email quirk (client
relays once; JWT claim authoritative; snapshot in IdentityJson) · partial delete
(single InnoDB transaction T0–T5, §3.3) · revoke-fails-but-delete-succeeds
(explicit outcome enum, deletion never blocked, §3.3A) · delete-someone-else
(no selector + re-auth + CSRF, §3.1) · double-submit delete (FOR UPDATE + idempotent
200/401, §3.3) · admin lockout (last-global-admin guard, §3.1.4) · forced-refetch
DoS (5-min fetch floor, §2.3) · cache poisoning (hardcoded HTTPS origin, shape
check, no-clobber, §2.3).

**Residual risks (flagged, accepted or deferred):**
1. `.p8` + RefreshTokens readable by a full-DB compromise. Mitigations: the refresh
   token is useless without the client_secret (needs the .p8) AND vice versa is
   scoped to SIWA only; at-rest encryption would need a key… stored on the same
   host (no KMS on shared hosting) — accepted; revisit if hosting changes.
2. Historical `tblActivityLog.Details` retains pre-deletion usernames/emails —
   retention-policy knob for the owner (§3.2.3), not scrubbed by this endpoint.
3. The `ihymns_auth` cookie fallback means browser-held tokens are cookie-exposed
   generally (existing #390 design, unchanged here); account_delete adds the CSRF
   gate so THIS endpoint is not the weak link.
4. No JWKS pinning beyond TLS — matches the deliberate no-cert-pinning stance
   (strategy §3.2) on auto-rotating shared-host infra.

---

## 6. Test plan — `tests/php/` (DB-free, source-tree, exit 0/1, wired into `.github/workflows/test.yml`)

**6.1 `test-apple-siwa-verify.php`** — the RS256 core, attacked. Setup: generate an
RSA-2048 keypair (`openssl_pkey_new`) + a second "wrong" keypair at runtime; build a
JWKS array from the public halves (n/e via `openssl_pkey_get_details`); mint tokens
with a tiny local signer. Cases: happy path (claims round-trip) · tampered payload
(flip one byte → reject) · signature from the wrong key · `alg: none` · `alg: HS256`
with the public-key-as-HMAC-secret confusion attempt · missing/unknown `kid` ·
wrong `iss` · wrong `aud` (another bundle id) · `aud` as array containing ours
(accept) / not containing ours (reject) · expired `exp` (and inside-60 s-leeway
accept) · future `iat` · missing `nonce` claim · nonce hash mismatch · 2-segment and
4-segment JWTs · non-base64url chars · oversized token. Also unit-tests
`appleSiwaJwkToPem` (output loadable by `openssl_pkey_get_public`, verifies a
signature made with the private half) and the strict base64url decoder.

**6.2 `test-apple-client-secret.php`** — ES256 minting: generate a P-256 key,
mint, split, assert header `{alg:ES256, kid}` + claims (`iss`,`sub:'app.ihymns'`,
`aud:'https://appleid.apple.com'`, `exp-iat == 300`); assert raw signature is exactly
64 bytes; re-encode r‖s to DER and `openssl_verify` it with the public half (proves
the DER→JOSE conversion both directions); reject-path: RSA key pasted instead of EC
→ null, garbage PEM → null.

**6.3 `test-auth-response-shape.php`** — parity lock: unit-test the extracted
`apiAuthSuccessPayload()` helper (§8 task B refactor): keys exactly
`['token','user']`, user keys exactly
`['id','username','display_name','role','avatar_service']`, `id` is int — i.e. the
`AuthSession.swift` contract; plus a source-grep assertion that BOTH `case
'auth_login'` and `case 'auth_apple'` call the helper (prevents silent divergence).

**6.4 `test-apple-siwa-sources.php`** — static guards (the
`test-component-json-guard.php` genre): api.php's auth_apple block contains the
tblUserAuthProviders existence probe; `apple_siwa.php` contains no
`CURLOPT_SSL_VERIFYPEER, false` / `VERIFYHOST, 0`; the 1062 duplicate-key catch
exists in `appleSiwaConsumeNonce`; no `error_log`/`logActivity` call in
`apple_siwa.php`/the two handlers references `RefreshToken`, `identityToken` or
`$rawNonce` values.

**6.5 `test-account-delete-fk-coverage.php`** — cascade completeness: parse
`schema.sql` (reuse `includes/schema_audit.php` parsing like
`test-schema-coverage.php`); for EVERY `REFERENCES tblUsers` FK assert `ON DELETE
CASCADE` or `ON DELETE SET NULL` (never RESTRICT/NO ACTION — which would make the
delete throw); assert `tblUserAuthProviders`'s FK is specifically CASCADE; assert
api.php's account_delete block mentions the two §3.2 PII-scrub tables (drift alarm
if someone adds a new PII column keyed by UserId without updating the scrub —
paired with a comment in schema.sql telling future FK-adders to run this test).

**6.6 Existing guards that must stay green:** `test-migration-registry.php`
(three facets + real probe for `user-auth-providers`), `test-schema-coverage.php`
(byte-identical DDL in schema.sql), `php -l` sweep, `node --check` sweep.

**6.7 Manual dress-rehearsal (alpha, before promoting):** real-device SIWA sign-in
(new user, then repeat = matched, then revoke-in-Settings + sign-in again = Apple
re-consents) · replayed identityToken from a proxy capture → 401 · account_delete
each re-auth rung · delete then re-sign-in with Apple → brand-new account (fresh
`sub` mapping row) · `/manage/setup-database` card flips pending→applied ·
`X-IHymns-…` no regressions on the AASA file.

---

## 7. Branch / deploy note (owner decision flagged)

Precedent: the #1401 AASA backend PHP landed on **`feat/apple-universal`** (commit
`f73c01d3` — the current branch), NOT on a web `claude/*`→`alpha` PR branch. That
keeps the whole Apple program reviewable as one unit, but it means **no
backend-for-apple PHP is live on ANY docroot yet** — and the native SIWA client
(strategy P1 "SIWA client" issue) plus §6.7 cannot be end-to-end verified until
`?action=auth_apple` is serving on `dev.ihymns.app`.

**Recommendation:** keep building #1402/#1403 on `feat/apple-universal` (consistency
with #1401; the appWeb changes are additive + dormant — un-run migration = 503/skip
paths, zero behaviour change for the PWA), **but** stage the appWeb subset for an
early ride to `alpha` as ONE web PR (`claude/apple-backend-auth-<suffix>` cut from
latest `origin/alpha`, cherry-picking the appWeb+tests commits) as soon as the owner
wants device testing — the alpha→beta→main promotion pipeline (with the owner running
the migration card + config pastes per §4.1 on each env's /manage) is unchanged.
**OWNER DECIDES:** (a) early web PR to alpha for E2E testing now, vs (b) hold
everything on `feat/apple-universal` until the Apple branch merges toward alpha.
Genuinely ambiguous because the "NO PRs yet" ground rule (strategy header) collides
with the TestFlight delivery gate (strategy §3.1: backend must be promoted before the
build that needs it).

---

## 8. Implementation task breakdown (Sonnet; one issue-linked commit per unit, in order)

| # | Commit / unit | Contents | Depends on |
|---|---|---|---|
| A | `feat(db): tblUserAuthProviders + tblAuthNonces migration (#1402)` | §1 DDL appended to `appWeb/.sql/schema.sql` (byte-identical) + `appWeb/.sql/migrate-user-auth-providers.php` + ONE `migration-registry.php` entry with the OR-probe | — |
| B | `refactor(api): extract apiAuthSuccessPayload() (#1402)` | Pull the api.php:2178 response construction into a shared helper; `auth_login` calls it (verified byte-identical output incl. the AvatarService gate) | — |
| C | `feat(api): includes/apple_siwa.php helper module (#1402)` | §2.0 function inventory: decoder, JWK→PEM, verifier, client_secret, JWKS cache, nonce consume, exchange, revoke; heavily annotated per house two-register style | A (nonce table shape) |
| D | `feat(api): ?action=auth_apple (#1402)` | The §2.2 handler in api.php + `api-docs.yaml` auth_apple entry (the YAML is the native contract source) | A, B, C |
| E | `feat(api): ?action=account_delete (#1403)` | The §3 handler + `api-docs.yaml` entry | A, C (revoke), B not needed |
| F | `feat(manage): SIWA Key ID + .p8 fields on the Apple config card (#1402)` | §4 `configuration.php` extension: two settings + validation + secret handling + status badges | — (parallel) |
| G | `test(php): SIWA + account-delete guards (#1402/#1403)` | §6.1–6.5 five test files + `.github/workflows/test.yml` wiring | C, D, E |
| H | `docs: runbook §SIWA + wiki/API + CHANGELOG/SECURITY (#1402/#1403)` | §4.1 numbered owner steps into `apple-native-owner-runbook.md`; standing-tasks sweep | D, E, F |

Sequencing rationale: A first (everything probes its tables); B/C/F parallelisable;
D before E (E reuses D's verify path for re-auth); G immediately after so CI locks
the crypto core before any promotion conversation; H closes the standing-tasks loop.
Estimated blast radius: api.php (+2 cases + 1 helper call site), 1 new include,
1 migration + registry entry + schema.sql append, configuration.php card, 5 test
files, api-docs.yaml, runbook — no existing endpoint's behaviour changes except the
B refactor (output-identical).

### Open OWNER decisions (explicit)

1. **§7 branch/promotion timing** — early alpha PR for the appWeb subset vs hold on
   `feat/apple-universal`.
2. **§5 residual 2** — activity-log retention policy for pre-deletion PII in
   historical rows (separate future issue if wanted).
3. **§3.1 rung 4** — confirm acceptance that credential-less degenerate accounts
   delete on bearer + `confirm:"DELETE"` alone (theoretical population: zero).
4. **Purchases wording** — `tblUserPurchases` rows are erased on delete; if the
   future paid layer (§strategy 4.1) lands first, the native deletion screen must
   warn "purchases/entitlements are permanently removed" (client copy, not backend).
