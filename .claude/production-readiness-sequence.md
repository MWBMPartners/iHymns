# iHymns — Sequenced Path to Production: Apple Native App + Web Sign in with Apple

> **STATUS: DRAFT for owner approval** — plan only, nothing actioned. Per the owner's "plan = draft the doc" rule, no promotion/activation is executed from this doc without explicit per-step go-ahead.
> **Scope:** promote the Apple/web backend `alpha → beta → main`, activate secret-encryption (#1466), widen web SIWA, enable CarPlay, gate TestFlight → App Store.
> **Ground truth verified 2026-07-10** (Fable 5 deep-planning round; branch tips, live curls, workflow files).

---

## 0. Verified state of the world (re-verify before executing)

| Fact | Evidence |
|---|---|
| `beta` == `main` content-wise (both v0.1254.1); `alpha` is **55 commits ahead** of beta | `git rev-list --count origin/beta..origin/alpha` = 55; `origin/main..origin/beta` = 0 |
| `beta` has 6 commits not on alpha (version-bump/changelog) → the promotion PR **will** conflict in `CHANGELOG.md` + `infoAppVer.php` (the known recipe) | `git log origin/alpha..origin/beta` |
| **⚠ AASA is NO LONGER a 301 — it is worse.** All 4 hosts return `200 application/json`, but `dev.` serves the real responder (`"appID": "Y5XK559SV9.app.ihymns"`) while `beta.`, `ihymns.app`, `www.ihymns.app` serve a **stale legacy static file** with the placeholder Team ID **and the wrong bundle id** (`TEAMID.ltd.mwbmpartners.ihymns`). A naive "expect 200" check FALSE-GREENS production. **Every AASA check must assert the BODY, not the status.** | live curls ×4; `git ls-tree origin/beta` shows the static AASA file still committed on beta (alpha replaced it with `apple-app-site-association.php`, #1401) |
| Apple backend endpoints promoted vs not: dev `auth_apple`→`identityToken is required.`; beta→`Unknown action: auth_apple`. (Both need header `X-Requested-With: XMLHttpRequest`, else every env returns the cross-site-POST block — don't misread that as "promoted".) | POST probes ×2 envs |
| The app entitlement declares **all 4 hosts** (`applinks:` ihymns.app/www/beta/dev) — a wrong AASA on any breaks Universal Links there | `origin/alpha:appApple/project.yml` |
| **⚠ Promotion pushes to beta/main will TRIGGER `apple-deploy.yml`** (paths `appApple/**`; the 55-commit delta is full of it) → `fastlane beta` = TestFlight EXTERNAL upload; `fastlane release` = App Store upload. Today they fail (runner lacks iOS/visionOS 26.0 SDKs) — that **failure is currently the only thing preventing accidental store uploads.** Fixing the SDK issue without a kill-switch first = an unguarded promotion uploads to the App Store. | `apple-deploy.yml` `on.push.paths`; Fastfile lanes |
| `promotion-deploy-bridge.yml` exists on alpha, beta AND main (dispatches `deploy.yml` on a merged PR — the GITHUB_TOKEN anti-recursion bridge, #1007/#1008) | `git ls-tree` ×3 |
| The **new** `deploy.yml` (with the `SECRETS_MASTER_KEY` seed-if-absent step + `.auth` mirror-excludes) exists only on alpha; it reaches beta/main **with the promotion**, so the beta/main master keys are seeded **by the promotion deploy itself** | `deploy.yml` on alpha; absent on beta |
| Three `manual` migration cards on alpha: RETIRE tblSongComponents JSON (#1235 P4/C6), Backfill canonical SongIds (#1380), Encrypt secrets at rest (#1466) — all excluded from "Apply all" | `migration-registry.php` |
| Deployment target **26.0 on every platform** — deliberate; needs a go/no-go tick | `Shared.xcconfig` |
| Web SIWA live on alpha only; channel gate `_appleWebLoginEnabledCore()` matches `ihymns_environment()` ∈ alpha\|beta\|production against the comma-list / `all` | `apple_siwa.php` |

**Reference docs this plan SEQUENCES (does not duplicate):** `Provisioning-Runbook.md` §1.2–§1.4, §2.1–§2.6; `DEV_NOTES.md` operator runbooks (#1466 / #1470 / #1481); `.claude/secret-encryption-strategy.md` §7–§10.

---

## 1. Dependency graph / critical path

```
P0 Pre-flight audit (read-only)
 └─► P1 "Make the Apple pipeline shippable" PR on alpha
        (KILL-SWITCH + dev-docs path excludes + SDK install + ITSAppUsesNonExemptEncryption)
        │  ← the KILL-SWITCH is the hard prerequisite for P2:
        │    without it the P2 beta merge fires fastlane beta (TestFlight EXTERNAL)
        │    as a side effect, gated today only by the build happening to fail
        └─► P2 Promote web/PHP backend  alpha ─► beta (soak) ─► main
              ├─► P3 AASA + native-backend verification per env (BODY, not status)
              ├─► P4 Secret-encryption activation (#1466) — needs engine on ALL 3 docroots (= P2)
              └─► P5 Web SIWA widening (alpha → alpha,beta → all)
 P6 CarPlay entitlement (portal tick any time; project.yml AFTER first green build)
 P7 TestFlight internal → external → App Store
```

**Start immediately in parallel:** P0; P1 (PR); all OWNER Apple-portal work; App Store metadata drafting.
**Single critical path to App Store:** P1 → P2(beta) → soak → P2(main) → P3 → P7. P4/P5 hang off P2 but don't block P7 (do before public launch — §5).

---

## 2. Phase-by-phase

Conventions: **[OWNER]** portal/ASC/GitHub-settings/DNS; **[WEB-ADMIN]** Global-Admin on `/manage/*` (web-only, no SSH); **[PR]** I prepare, owner merges; **[DESTRUCTIVE]** irreversible/outward → explicit go-ahead each time. Shared-DB nuance: **migration cards run ONCE against the one shared MySQL**; per-docroot = only the docroot's code (per branch), `.auth/secrets_master_key.php`, `.auth/opcache_bust_key.php`, `.env-channel`.

### P0 — Pre-flight audit (read-only)
1. **[WEB-ADMIN]** `dev.ihymns.app/manage/setup-database` → record every non-green card. `Sign in with Apple — provider links (#1402)` should already be green.
2. **[WEB-ADMIN]** Run "Apply all pending" for non-manual additive cards (all additive/dormant, INFORMATION_SCHEMA-gated → safe against older beta/main code). Do **NOT** run the 3 `manual` cards. *Good:* pending counter = 3 (the manual cards).
3. **[OWNER]** Verify the 9 Apple deploy secrets exist (runbook §2.2.A).
4. **[OWNER]** `APPLE_TEAM_ID` secret == `apple_team_id` setting == `Y5XK559SV9` (Universal Links silently fail otherwise).
5. **[OWNER]** App ID SIWA+Associated-Domains ticked; ASC record; Distribution cert in hand.
6. **[ME]** Confirm last alpha deploy + opcache-bust step returned 200; note whether beta/prod have `.auth/opcache_bust_key.php`.

### P1 — Make the Apple pipeline shippable (one PR to alpha) **[PR]**
One PR, four concerns:
1. **KILL-SWITCH (load-bearing):** gate the `deploy` job in `apple-deploy.yml` on `if: vars.APPLE_DEPLOY_ENABLED == 'true'` (same pattern as `deploy.yml`'s `vars.SFTP_ENABLED`); loud `::notice` skip-summary when disabled. *Why:* the P2 promotion merges `appApple/**` into beta/main → fires the external-TestFlight and App-Store lanes; today "safe" only because the build fails on missing SDKs. A repo Variable, owner-controlled, default-off, decouples web promotion from store distribution.
2. **Paths hygiene:** exclude `appApple/dev-docs/**` from `apple-deploy.yml` + `apple.yml` (docs must not fire signed builds/CI). *(This is the immediate "red-X on doc pushes" fix the owner reported.)*
3. **SDK fix:** after `xcode-select`, `sudo xcodebuild -downloadPlatform iOS` + `tvOS` + `watchOS` + `visionOS`; keep the Xcode-26 pin; accept +5–15 min.
4. **Export compliance:** `INFOPLIST_KEY_ITSAppUsesNonExemptEncryption: NO` in the app targets' `settings.base` in `project.yml`.

*Acceptance:* with the 9 secrets + `APPLE_DEPLOY_ENABLED=true`, a manual `apple-deploy.yml` dispatch (lane alpha) archives iOS+tvOS, uploads to TestFlight internal, reaches "Ready to Test" with no compliance prompt. Until the owner flips the var, every branch-push run shows the skip notice (this is the fix for the owner's current red-X noise).

### P2 — Promote web/PHP backend alpha→beta→main **[PR; OWNER merges; DESTRUCTIVE]**
Not until P1 is merged to alpha (kill-switch must ride along).
**P2a alpha→beta:** cut `promote/alpha-to-beta-v0.2501` from alpha, `git merge origin/beta`; resolve `CHANGELOG.md` = lossless newest-first union, `infoAppVer.php` = take alpha's version; diff-audit the merge vs origin/alpha (only CHANGELOG/version differ). PR base=beta, **MERGE COMMIT (never squash)**. Verify a `deploy.yml` run executed for beta (or dispatch the bridge). Confirm Apple Deploy shows the **skip notice**. **Verify beta (body-asserting):**
```
curl -s https://beta.ihymns.app/.well-known/apple-app-site-association | head -c 400
#  GOOD: "appID":"Y5XK559SV9.app.ihymns"   BAD/stale: "TEAMID.ltd.mwbmpartners.ihymns"
curl -s -X POST 'https://beta.ihymns.app/api?action=auth_apple' -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' -d '{}'
#  GOOD: {"error":"identityToken is required."}   BAD: {"error":"Unknown action: auth_apple"}
```
Plus: beta `/manage` footer v0.2501.x; Secret-encryption panel renders; non-manual cards green; opcache-bust 200 (else hand-SFTP `.auth/opcache_bust_key.php`). Smoke the beta PWA. **Soak 3–7 nights** (owner's call).
**P2b beta→main** (after soak; go-ahead; DESTRUCTIVE=production): same recipe; repeat the full curl matrix against **both `ihymns.app` AND `www.ihymns.app`**; then the **Apple CDN** check `curl -s https://app-site-association.cdn-apple.com/a/v1/ihymns.app` (refresh latency hours→~48h — don't distribute external UL-dependent builds until it shows the new payload). Back-merge beta→alpha afterward. **Migrations in P2: none** (schema ran once in P0); the deploy seeds `.auth/secrets_master_key.php` on beta/main (P4 input).

### P3 — AASA + native backend verified per env **[ME; no changes]**
Per host, never inferred — assert the **body** (`Y5XK559SV9.app.ihymns`) + the 3 endpoint probes (`auth_apple`, `analytics_ingest`, `account_delete`) on dev(✓)/beta(after P2a)/prod+www(after P2b) + Apple CDN (after P2b). **Runbook correction:** §2.1's "expect 200" is now insufficient — beta/prod 200 with the WRONG appID; fold the body-assertion correction into the P1/P2 PR.

### P4 — Secret-encryption activation (#1466) — see §3 (contains a DESTRUCTIVE step)

### P5 — Widen web SIWA **[OWNER portal + WEB-ADMIN settings]**
1. **[OWNER — now]** Services ID `app.ihymns.web` → all 4 Domains + all 4 Return URLs (trailing slash mandatory; `www` is the one that bites prod with `invalid_grant`).
2. **[OWNER→WEB-ADMIN]** Paste Team ID / Key ID / `.p8` into `/manage/configuration` (not needed for web sign-in — JWKS-verified — but required so account-deletion revoke ≠ `skipped_no_token`; before `all`).
3. **[WEB-ADMIN]** After P2a: `apple_web_login_enabled=alpha,beta`; verify on beta.
4. **[WEB-ADMIN — DESTRUCTIVE-ish]** After P2b + beta signal: `=all`; verify on `ihymns.app` AND `www.ihymns.app` (redirect_uri differs per host). Migrations: none.

### P6 — CarPlay entitlement **[OWNER portal + one-line PR]**
1. **[OWNER — now]** Portal tick CarPlay on `app.ihymns` (grant email on file).
2. **[PR — AFTER first green TestFlight build]** add `com.apple.developer.carplay-audio: true` to `project.yml` iHymns target entitlements (isolates signing-failure diagnosis). Verify via `codesign -d --entitlements`. On-device UI = #1431 (out of scope).

### P7 — TestFlight internal → external → App Store **[OWNER-heavy]**
**P7.1 internal** (needs P1 + owner secrets/ASC; NOT P2 — dev-pointed/runtime-override build works): tester group; **[OWNER — outward]** flip `APPLE_DEPLOY_ENABLED=true`; dispatch lane alpha; Processing→Ready to Test; device pass (Password/Email-Code sign-in — no native SIWA button; account deletion #1478; a11y). Then land P6.
**P7.2 external** (adds P2a+P3-on-beta; UL wants P2b+CDN): Test Information first (Beta App Review ~1–2 d auto-fires); go-ahead push to beta.
**P7.3 App Store** (adds P2b+P3-full-on-prod+§5): metadata per runbook §2.3.B–D; go-ahead push main (upload-only, `submit_for_review:false`); **[OWNER — final]** attach build, App Review notes (demo acct, CCLI basis, §4.8 SIWA exemption), Manual release, Submit — only after §5.

---

## 3. Secret-encryption activation (#1466) — detailed sub-sequence
The highest-risk step. The `🔐 Encrypt secrets at rest — ENCRYPT-IN-PLACE` card is `manual` + typed-slug confirm, excluded from "Apply all". It rewrites the 8 flagged `tblAppSettings` secrets as `enc:v1:…` envelopes + flips `secret_encryption_active=1`, once against the shared DB.
**Invariant (strategy §7):** every docroot reading those settings must run decrypt-capable code (#1469) **before** the card and **forever after**. Encrypting while prod runs pre-#1469 code = prod reads ciphertext as a plaintext SMTP password / `.p8` = silent outage. (The SongbookName prod-stale incident, replayed on secrets.)
**Pre-checks (all must pass):** (1) v0.2501.x live on dev+beta+prod; (2) soak passed + owner accepts **no future revert of beta/main below #1469**; (3) master key present per docroot (P2 deploy seeds beta/prod); (4) **fingerprint parity ×3** identical on all panels — any mismatch = STOP + re-seed; (5) **sentinel round-trip green ×3**; (6) DB user lacks MySQL `FILE` (strategy §12); (7) fresh DB backup taken.
**Execution:** run the card on ONE docroot (dev) with the typed-slug confirm → verify N encrypted / 0 legacy-plaintext + sentinel green on all 3 + functional email/SIWA probes per docroot.
**Provider rotation (owner-paced):** rotate + re-paste SMTP/SendGrid/Mailgun/SES/Graph/Gmail-SA (the `.p8` is exempt — no plaintext era); verify delivery one provider at a time.
**Rollback:** no decrypt-in-place card — recovery = re-paste plaintexts via `/manage/configuration` (owner holds originals) + the pre-card backup. Treat as one-way; hence it's LAST after soak. Never revert beta/main below #1469 after it — fix-forward only.

---

## 4. Risk register (top)
| # | Risk | Phase | Mitigation | Rollback |
|---|---|---|---|---|
| R1 | Promotion push → uncontrolled external TestFlight / App Store upload | P2 | P1 kill-switch lands BEFORE P2; verify skip-notice | expire build in ASC; upload-only (submit_for_review:false) |
| R2 | "AASA 200 = fixed" false-green (prod 200s with WRONG appID; CDN caches) | P2/P3 | assert the **body**; check Apple CDN before external distribution | fix origin, wait/re-trigger CDN |
| R3 | Prod-stale: shared DB migrated, one docroot on old code (#1295) | P2/P4 | additive/dormant cards; encrypt-in-place hard-gated on parity×3; verify opcache-bust per env | deploy the lagging docroot |
| R4 | 55-commit merge drops a hunk | P2 | post-merge diff = only CHANGELOG/version; CI+php-l; soak | `git revert -m 1` on beta (safe only pre-P4) |
| R5 | Deploy didn't run on beta/main (GITHUB_TOKEN suppression) | P2 | verify a deploy run per merge; manual dispatch fallback | detect-and-dispatch |
| R6 | OPcache serves stale bytecode post-deploy | P2 | check bust step 200 per env; provision bust key if 503 | re-bust/wait |
| R7 | Encrypt-in-place with divergent/missing key on one docroot → outage | P4 | §3 pre-checks 3–5 (parity×3 + sentinel), card self-refusal | fix key file (data intact) |
| R8 | Post-P4 revert below #1469 → ciphertext read as plaintext | P4 | owner sign-off §3.2; P4 LAST | fix-forward only |
| R9 | First signed build fails non-SDK (cert/ASC_API_KEY base64-vs-raw/profile) | P7.1 | dispatch alpha lane manually first; CarPlay deferred | iterate on alpha lane |
| R10 | External TestFlight before Test Info / prod backend → Beta review rejection | P7.2 | hard ordering; UL waits for CDN | remove build from group |
| R11 | `www` missed in Services ID Return URLs → prod `invalid_grant` | P5 | re-verify all 4; test sign-in on www | add URL (immediate) |
| R12 | Deployment target 26.0 shrinks audience | P7.3 | §5 go/no-go line | n/a |
| R13 | Version-bump `[skip ci]` commits re-create promotion conflicts | P2 | the recipe; back-merge beta→alpha | n/a |

---

## 5. Go/no-go before "Submit for Review" (owner ticks every line)
**Backend/infra:** v0.2501.x on dev+beta+prod · AASA **body** = `Y5XK559SV9.app.ihymns` on ihymns.app AND www (+ dev/beta) · Apple CDN serves the new payload · endpoint probes promoted on prod · non-manual cards green ×3, pending = manual cards only · secret-encryption activated or risk re-accepted · `apple_team_id`==`APPLE_TEAM_ID`.
**App/store:** internal build device-smoked · external soak + Beta review passed · Universal Link tap opens the right song · export compliance present · CarPlay portal ticked + entitlement in build (UI #1431 out of scope) · **26.0 re-confirmed as intended** · metadata/screenshots/age-rating/App-Privacy complete · Mac/visionOS toggles · App Review notes drafted · Manual release.
**Web SIWA:** 4 Domains+4 Return URLs (incl www) · `.p8`/Team ID/Key ID pasted · channel widened + verified per host.

---

## 6. WHO does what
**OWNER-only:** all Apple-portal/ASC/DNS; 9 GitHub secrets + `APPLE_DEPLOY_ENABLED` var; merging every promotion PR; any push to beta/main; flipping `apple_web_login_enabled`; the encrypt-in-place card; provider rotation; DB backup; the 26.0 decision.
**PRs I prepare:** P1 pipeline PR; P2a/P2b promotion branches (+ runbook AASA-body correction + back-merge); P6 CarPlay line; this doc + tracking issue.
**DESTRUCTIVE/outward — explicit go-ahead each time:** merge to beta; merge to main; `APPLE_DEPLOY_ENABLED=true`; first external push; App Store upload; encrypt-in-place card; `apple_web_login_enabled=all`; Submit for Review.

---

## 7. Adversarial self-critique
1. The kill-switch inverts the failure mode — a forgotten flip means uploads silently don't happen; mitigated by the loud skip-notice, but the P7 runsheet must start with "flip the var."
2. P0 runs the remaining additive cards before beta/main get the new code — relies on the INFORMATION_SCHEMA-gating invariant; if one newer migration violates it, beta/prod break before P2. Insurance: grep-audit the beta branch for references to the new tables/columns first, or defer those cards to after P2a.
3. The 55-commit promotion is a mega-release to prod; the soak length is a real decision, not a formality (P4 removes the clean revert once run).
4. Apple CDN refresh timing could embarrass an external launch even with prod fixed — socialise the up-to-~48h wait as normal; gate external distribution on the CDN check.
5. First-signed-build unknowns are stacked late (9 never-end-to-end-exercised secrets; ASC_API_KEY raw-vs-base64); expect 1–3 iterations — debug via manual alpha-lane dispatch only, never by conflating with P2.

---

## 8. Tracking issue + filename
**Doc:** this file (`.claude/production-readiness-sequence.md`).
**Issue title:** "Production readiness: sequenced promotion + activation path — Apple app to App Store, backend alpha→beta→main, secret-encryption #1466 activation, web SIWA widening."
