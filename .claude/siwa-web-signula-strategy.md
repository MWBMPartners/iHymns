# Sign in with Apple for Web + SIGNula.id federation — strategy (DRAFT for owner review)

> **Status: DRAFT — awaiting owner approval. No implementation, issues, or branches created from this yet.**
> Produced by two sequential deep-planning rounds on **Fable 5** (round 1 = deep draft, round 2 = adversarial verify + refine), then reviewed on Opus. Date: **2026-07-10**.
> **Grounding:** every iHymns claim verified against code on `alpha` (the `feat/apple-universal` auth backend merged via #1464/#1469); every SIGNula claim verified against the public repo `MWBMPartners/SIGNula.id` at **v2.6.0-beta** (`VERSION` confirmed; last push 2026-04-24). File/line refs are to the verified sources.
>
> **Tracking:** iHymns **#1470** (this initiative); SIGNula federation contract (§6.2) filed as **MWBMPartners/SIGNula.id#89**. Owner-approved (2026-07-10): Option C, build W1 backend dormant first.

The three asks (owner, verbatim intent):
1. We set up Sign in with Apple (SIWA) for the Apple **native** app — implement it for **web/PWA** too.
2. How does that impact the **later** plan to integrate iHymns sign-in with **SIGNula.id** (MWBM's universal SSO, which also brokers Apple ID)?
3. Let a pre-existing "native" iHymns account (password / magic-link) **link** to Apple via SIWA, and **later** also link to SIGNula.id.

---

## Adversarial review notes (what was verified / corrected in round 2)

> **Correction (Opus post-review, 2026-07-10):** round-2 finding **#4 (login-CSRF via the unconditional cookie set) is REFUTED.** `api.php` enforces a **global `X-Requested-With: XMLHttpRequest` guard on every POST** (lines 271-322; only `song_request_submit` is exempted, via an Origin/Referer same-origin check). A cross-site POST to `auth_apple` is therefore already blocked — `X-Requested-With` is a forbidden header a cross-origin caller can't set without a CORS preflight the server never grants, and the `text/plain`/form trick can't set it either. The cookie is thus only ever set on same-origin XHR already, so the "conditional cookie" change is redundant and is **DROPPED from W1** (one fewer change to security-critical code; no live vuln). Findings **#1 (redirect_uri), #2 (SIGNula webhook names), #3 (private-relay unlink stranding), #5 (channel rollout), #6 (RefreshToken)** all stand. **O7** (extend a conditional-cookie fix to `auth_login`) is likewise moot for the same reason — dropped.

**Verified against source (not trusted from the first pass):**
- **The decisive claim stands.** SIGNula.id v2.6.0-beta has **no partner-facing OAuth2/OIDC authorization server.** Confirmed: `web/public_html/oauth/authorize.php` is SIGNula acting as an OAuth **client** ("…for delegate email sending", providers `microsoft_graph`/`google_workspace`, purpose `email|signin`); the partner API (`web/public_html/api/v1/index.php`) exposes only `/auth/login` (email+password), `/oauth/providers|linked|link|unlink` — no partner `/authorize` or `/token` issuance; **no `.well-known/openid-configuration`, no JWKS** anywhere (recursive tree search); documented access tokens are `eyJhbGciOiJIUzI1NiIs…` (**HS256, symmetric — not partner-verifiable**), and `AuthController.php` actually mints **opaque** random session tokens. **⇒ "Delegate to SIGNula now" is not buildable today. Recommendation unchanged: Option C.**
- `appleSiwaVerifyIdentityToken()` (`includes/apple_siwa.php:378`) — `$expectedAud` is a parameter; strict single-expected aud check (accepts the RFC 7519 array-containing shape, never "try-both"); RS256 pinned as a reject-gate. Confirmed.
- Nonce contract (`api.php:3087` + `apple_siwa.php:452`): `hash_equals($nonceClaim, hash('sha256',$rawNonce))` — web-compatible. Confirmed.
- Linking guards (`api.php:3170-3183`, `14059-14094`, `13863`): authenticated-bearer requirement for link mode; strict 4-condition email auto-link incl. target `EmailVerified=1`; two named-UNIQUE 409s; transactional rollback + create-race retry. Confirmed.
- `ihymns_auth` is SameSite=Lax (`includes/auth_cookie.php:69`); `api.php` sets no CORS headers. The popup-over-redirect argument holds. Confirmed.
- Schema is provider-generic (`schema.sql:3815`); the two web settings don't exist yet. Confirmed.

**Corrected (first pass was wrong / incomplete):**
1. **`apple_siwa.php` needs ONE change after all.** `appleSiwaExchangeCode()` omits `redirect_uri`; Apple requires it for **web** code exchanges (native ASAuthorization sends none — current code correct for native). Without it every web code exchange fails ⇒ web-first users never capture a `RefreshToken` ⇒ Apple-side revoke on delete/unlink degrades to `skipped_no_token`. Fix: one additive optional `?string $redirectUri = null` appended to the form body when non-null. Verify path unchanged.
2. **SIGNula webhook event names are a docs/code mismatch** — code ships `user.account_deleted` and **no `oauth.*` events**. The §6.2 contract must pin exact names and require the `oauth.*` events be *implemented*, not assumed.
3. **Unlink lockout guard had a private-relay stranding hole.** After unlink+revoke, Apple deactivates the relay alias ⇒ mail bounces forever. The guard must not count a `@privaterelay.appleid.com` email as a surviving sign-in method.
4. **New security finding: login-CSRF via the unconditional cookie set.** `auth_apple` calls `setAuthTokenCookie()` unconditionally (`api.php:3238`) and the cookie is accepted as a bearer. Only set the cookie on **verified same-origin** requests (the rule #29 `X-Requested-With` + Origin/Referer-host machinery). Native uses the JSON bearer and never needed the cookie. (Same latent exposure exists on `auth_login` — optional parity hardening, O7.)
5. **Global toggle → channel allow-list.** One shared DB × three docroots on different code versions means a single global `apple_web_login_enabled='1'` flips all three at once. Use a **channel allow-list** value (`'alpha'` → `'alpha,beta'` → `'all'`) checked against `ihymns_environment()` for safe staged rollout with one row. Fail-closed on un-promoted docroots verified (old code ignores `platform`, web tokens 401).
6. **RefreshToken-at-rest = non-blocking follow-up**, not a dependency. `secretEncrypt/secretDecrypt` (#1466) are value-level, column-agnostic with plaintext fallthrough — clean for this column — but #1466 is itself dormant/awaiting activation. Do **not** sequence web SIWA behind it.

**Walked back:** nothing in the central recommendation. Option C survives intact.

---

## 1. Goals & non-goals

**Goals:** (1) SIWA on Web/PWA with the same account-mapping as native; (2) forward-compat with a later SIGNula SSO at near-zero rework; (3) link/unlink Apple from Settings on an existing account, and later SIGNula too.

**Non-goals (v1):** becoming a SIGNula client today (not buildable — §3); other direct providers (schema supports them; deferred); WebAuthn in iHymns (a federation dividend from SIGNula, not a local build); DB merge; Apple server-to-server revocation notifications (W4); redirect-mode SIWA (W4 fallback).

## 2. Current state (verified)

- `includes/apple_siwa.php` is a complete, reusable SIWA library — audience and client-id are **parameters**; JWKS cache + single-use nonce ledger reuse verbatim. Only the `api.php` call sites hardcode `IHYMNS_SIWA_CLIENT_ID` (`app.ihymns`).
- **One required library change:** `appleSiwaExchangeCode()` gains an optional `redirect_uri` (correction #1).
- Nonce contract is web-compatible (lowercase hex; raw within the server's `[A-Za-z0-9._~-]{16,255}` gate).
- `_authAppleMapAccount`: (a) existing `(apple,sub)`→login; (b) `link=1` w/ authenticated bearer; (c) strict 4-condition email auto-link; (d) create new passwordless account. Transactional + duplicate-race retry. Bearer minted identically to `auth_login`; cookie via `auth_cookie.php` (SameSite=Lax, `.ihymns.app`).
- Schema: `tblUserAuthProviders` + `tblAuthNonces` provider-generic (`schema.sql:3815+`). **No schema change needed for web, nor for SIGNula later.**
- **New for web:** Apple **Services ID** (web aud ≠ App ID `app.ihymns`); registered domains/return URLs; front-end flow; a `platform` discriminator in `auth_apple`; linked-accounts settings UI + unlink endpoint; two `tblAppSettings` rows.
- **Deployment note:** the `auth_apple` backend is now on `alpha` (merged); its `migrate-user-auth-providers` card must be applied on the shared DB before web SIWA can transact (`auth_apple` already 503s cleanly when it isn't).

## 3. Central architectural fork — recommend **Option C**

- **Apple `sub` is stable per (user × Developer Team).** A Services ID in the **same team** as `app.ihymns` yields the **same `sub`** as native ⇒ web and native share `tblUserAuthProviders` rows with zero reconciliation; and if SIGNula's Apple client is in that team too, SIGNula's stored Apple `provider_user_id` equals iHymns' `Subject` ⇒ **deterministic join** at federation time. Different team ⇒ only weak email reconciliation (useless for hide-my-email users). **→ OWNER DECISION D1 (highest leverage): provision SIGNula's Apple Services ID in the same Apple Developer team as `app.ihymns`.**
- **Option A** (direct now, federate later): days to ship, smallest surface, best UX, no lock-in.
- **Option B** (delegate to SIGNula now): **BLOCKED** — no partner SSO flow exists in SIGNula v2.6.0-beta; would require building + hardening partner-SSO in the SIGNula repo first.
- **★ Option C (recommended)** = Option A's build + codify the federation contract (§6.2) as a SIGNula-repo issue + the provider-generic model we already have + D1. Nothing in A is throwaway (verify library, mapping, linking, nonce ledger serve native forever).

## 4. SIWA-for-web design

**Provisioning (owner, W0):** Services ID (suggest `app.ihymns.web`) in the same team, grouped under the App ID; register **every** docroot hostname as domain + return URL; register the outbound mail domain/addresses for private-relay forwarding (this gates *account recovery* for relay users, §5/§8). No new secrets — same Team ID/Key ID/`.p8`.

**Front-end: Apple JS, POPUP mode.** Redirect mode with name/email scope uses a cross-site `form_post`; SameSite=Lax means the session cookie isn't attached ⇒ a redirect *link* flow can't see the session. Popup hands `id_token`+`code` to our JS, which does a **same-origin** POST to `?action=auth_apple` (X-Requested-With) — no new server entry point. Redirect mode is a W4 fallback only.

**Flow:** lazy-load `appleid.auth.js`; mint `rawNonce` + `state`; `AppleID.auth.init({clientId:<services id>, scope:'name email', redirectURI, state, nonce: sha256hex_lowercase(rawNonce), usePopup:true})`; on success POST `{identityToken, nonce: rawNonce, authorizationCode, fullName?, platform:'web', link:0|1}` with `X-Requested-With` (bearer for link mode). Verify `state` client-side before posting.

**Server changes:**
- `apple_siwa.php` — **one change:** `appleSiwaExchangeCode(…, ?string $redirectUri = null)`.
- `api.php auth_apple` — accept `platform` (allow-list `native|web`, default `native`; else 400). Resolve **exactly one** `$expectedAud` (native→`IHYMNS_SIWA_CLIENT_ID`, web→`getAppSetting('apple_siwa_services_id')`). Web requested but unconfigured / channel not in `apple_web_login_enabled` allow-list → 503 dormant. **Never try both auds.** Use the resolved client id + this docroot's registered redirect URI in the code exchange. For `platform=web`: require same-origin markers on **all** requests and **only set the auth cookie when verified same-origin** (correction #4). Audit `platform`.
- **Why one endpoint (not `auth_apple_web`):** resolution only *selects* which of our own two client ids to check; a web token replayed as native fails `bad_aud` and vice-versa; both ids share one team so `(apple,sub)` maps to the same row. A second endpoint would duplicate ~230 lines (rate-limit/gates/mapping/audit) = the real long-term risk. One endpoint, one resolved aud, shared `siwa_login` nonce purpose (single-use, no cross-platform interaction).
- `app_status` — add `appleWebLoginEnabled` (resolved for this channel).
- `configuration.php` — Apple card gains `apple_siwa_services_id` (non-secret; validate shape; reject `== app.ihymns`) + `apple_web_login_enabled` (channel allow-list, default `''` = dormant).
- Front-end — new `js/modules/apple-signin.js` consumed by the auth UI; button gated on `appleWebLoginEnabled`.
- Tests — aud cross-rejection matrix, platform validation, per-channel dormancy no-op, exchange-body `redirect_uri` presence, cookie-only-on-same-origin.

**Private relay:** email never identity; `fullName` only on first authorization (forward it in the popup); relay-alias rotation absorbed by the sub-keyed warm path.

## 5. Account-linking model

(a) existing `(apple,sub)` → login. (b) new identity → strict auto-link else create; **no** interactive "email exists" prompt (enumeration oracle) — UI uses `flow:'created'` to guide "sign in with your password, then link from Settings". (c) explicit `link=1`: authenticated bearer + same-origin; guards verified present (bearer-required, two UNIQUE 409s, rollback); **D4** step-up re-auth before linking = not required v1.

**(d) NEW unlink** — provider-generic `?action=auth_provider_unlink`; bearer + same-origin. **Lockout guard (revised):** refuse unless a *surviving* method remains — `PasswordHash<>''`; **or** (`EmailVerified=1` **and** email is **not** `@privaterelay.appleid.com` **and** email-login is operational); **or** another provider row (different provider). Run under `SELECT … FOR UPDATE` on the account's provider rows (two concurrent unlinks can't each count the other as survivor). Then best-effort Apple revoke (needs the captured RefreshToken — correction #1), delete row, audit. Does not invalidate bearers (unlink ≠ logout). Residual: unlink-then-forgot-password with a dead relay email = admin-recoverable only (document in runbook).

**Extending to SIGNula later:** `Provider='signula'`, `Subject=usr_…`, nonce `Purpose='signula_login'` — zero schema change; same guard logic.

## 6. SIGNula federation impact & migration

**6.1** Identity keys forward-compatible as-is; Apple-sub reconciliation deterministic **iff D1**; one broker per provider button (never Apple-direct + Apple-via-SIGNula for the same button).

**6.2 The federation contract** (file as a SIGNula-repo issue — the entire cost of ask #2 today):
- OAuth2 authorization-code + **PKCE (S256)** for partners; **RS256/ES256 id_tokens** via JWKS + OIDC discovery; `nonce`+`state`; kid rotation.
- **Stable `sub`** — decide **pairwise vs global** (O4).
- Claims: `email`, `email_verified` (defined semantics), and a **linked-provider-subjects** claim/endpoint exposing `provider`+`provider_user_id` (makes the D1 Apple-sub join executable).
- Webhooks: **pin exact event names** (code ships `user.account_deleted`; `oauth.linked`/`oauth.unlinked` must be *added* — correction #2); fixed HMAC header; signed timestamp/replay window.
- **Token revocation** (RFC 7009). RP-initiated/back-channel logout: **optional/deferred** (iHymns mints its own bearers post-exchange — document this session model).
- Client registration; exact-match HTTPS redirect allow-list (no wildcards); id_token ≤10 min + skew rule; rate limits.

**Staged:** F0 = direct build (§4/§5). F1 = add SIGNula as an *additional* button once it ships the contract. F2 = reconciliation matcher (Apple-sub join under D1; email fallback → manual review) + webhook consumer. F3 = optionally re-point the **web** Apple button through SIGNula — **recommend never for native** (App Review + zero marginal cost keep native direct forever), so F3 buys little; park it.

## 7. Schema impact

**NONE.** Both tables already provider-generic; new config = two `tblAppSettings` rows (no migration card for settings). Precondition: `migrate-user-auth-providers` applied on the shared DB.

## 8. Security

- **aud confusion** — one resolved expectedAud from a two-value allow-list of *our own* client ids; never try-both; RS256 reject-gate.
- **Nonce replay** — single-use `UNIQUE(Purpose,NonceHash)` ledger (shared DB, atomic, burn-after-verify); lowercase-hex discipline.
- **CSRF / login-CSRF** — same-origin markers for all `platform=web`; **cookie set only on verified same-origin** (correction #4); link/unlink bearer-bound. **O7:** apply the same conditional-cookie fix to `auth_login`/magic-link (pre-existing exposure; recommend yes, separate commit).
- **Open redirect** — none in v1 (popup only).
- **Email takeover** — verified 4-condition guard.
- **Private relay** — never identity-keyed; **outbound-domain registration gates recovery mail** (W0).
- **RefreshToken at rest** — plaintext TEXT today, useless without the `.p8`, never logged (static-test-guarded). Fold into #1466 (encrypt-on-write + decrypt-on-revoke + a sweep in the encrypt-in-place card) **after** #1466 is owner-activated. **Not a blocker.**
- **SIGNula trust boundary (future)** — asymmetric tokens only; HMAC webhooks; availability isolation (SIGNula down must never block Apple-direct or password login).
- **Third-party JS** — `appleid.auth.js` from Apple CDN: lazy-load, degrades if blocked; **O3** checks CSP.
- Standing checklist 1–9 swept over the new surface.

## 9. Phasing

- **W0** — preconditions (owner): D1–D3; Services ID + domains/return URLs (all 3 docroots); relay outbound-mail registration; confirm migration card applied.
- **W1** — backend (0.5–1d): `platform` param + channel-gated settings + `appleSiwaExchangeCode` redirect_uri + conditional cookie + tests. **Dormant/no-op until configured.**
- **W2** — front-end sign-in (1–2d): `apple-signin.js`, button gating, first-auth `fullName`.
- **W3** — linked-accounts settings UI + unlink with the revised lockout guard (~1d).
- **W4** — hardening: redirect fallback, Apple S2S revocation notifications, RefreshToken encryption (post-#1466), `auth_login` cookie parity (O7).
- **S1** — SIGNula partner-SSO per §6.2 (**weeks, in the SIGNula repo**). **S2** — iHymns↔SIGNula federation (2–4d in iHymns).

W1–W3 = one PR / three commits, **~3–4 dev-days**. Rollout: land on alpha → `apple_web_login_enabled='alpha'` → soak → promote code to beta/prod → widen the channel list (never past the docroots running the new code).

## 10. Open questions / owner decisions

| # | Decision | Recommendation |
|---|---|---|
| **D1** | SIGNula's Apple Services ID in the **same Apple team** as `app.ihymns`? | **Yes** — decide before SIGNula's Apple client gets real users. |
| **D2** | Approve **Option C** + file the §6.2 contract as a SIGNula-repo issue? | Yes. |
| **D3** | Web SIWA under iHymns branding now (not blocked on SIGNula)? | Yes. |
| **D4** | Step-up re-auth before **linking**? | No for v1 (machinery exists to add later). |
| **O1** | Exact 3 docroot hostnames for Apple registration + channel-rollout shape. | Register all three; roll out alpha→beta→prod. |
| **O2** | Private-relay outbound-email domain registered? | Verify/do in W0 (gates recovery mail). |
| **O3** | Any CSP blocking `appleid.cdn-apple.com`? | Audit in W1. |
| **O4** | SIGNula subject pairwise vs global; SDK plans; partner-SSO roadmap. | SIGNula's call; iHymns is agnostic. |
| **O5** | During soak: sign-in-only (suppress `created`) or full sign-up? | Optional; default = parity with native. |
| **O6** | SIGNula `account_tier` → iHymns `TIER_CAPS` (#1352) mapping? | Park until F2. |
| **O7** | Extend conditional-cookie login-CSRF fix to `auth_login`/magic-link? | Yes, W4, separate commit. |

## 11. Effort / value

W0 ≈ 0 code + owner portal · W1 0.5–1d (high — unlocks everything) · W2 1–2d (highest user-visible) · W3 ~1d (unlink lockout = the only genuinely new security logic) · W4 1–2d (low, schedulable) · S1 **weeks in the SIGNula repo** · S2 2–4d in iHymns. **Total for asks #1+#3 ≈ 3–4 dev-days + provisioning; ask #2 = one decision (D1) + one contract document today.**

---

*Planning provenance: 2 sequential Fable-5 deep-planning rounds (round 1 draft, round 2 adversarial verify+refine) + Opus review, 2026-07-10. This is a draft for owner review — no code, issues, or branches have been created from it.*
