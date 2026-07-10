# iHymns Apple app — Owner Provisioning Runbook (§1.2 & §1.3)

> **Click-level, owner-only** Apple provisioning steps for the native Universal app.
> Companion to `.claude/apple-native-owner-runbook.md` (long-lead overview) and epic **#895**.
> Nothing here is executable by the build agent — these are your Apple-account + iHymns-admin actions.
> Last updated **2026-07-10** (in-app account deletion shipped — #1478; web Sign in with Apple W1–W3 + the §1.4 activation steps + its CSP allowance — #1471/#1480/#1484; version now **0.2501.0**; incorporates the owner Q&A corrections: Family Sharing N/A, APNs deferred, private-relay email optional).

---

## Prerequisites
- **Apple Developer Program** membership ($99/yr) as **Account Holder** or **Admin**. (Team: MWBM Partners LTD.)
- You control the domain **`ihymns.app`** and the bundle id **`app.ihymns`** (note: `app.ihymns` is `ihymns.app` reversed, which is why Universal Links line up).
- Two portals: **developer.apple.com/account** and **appstoreconnect.apple.com**.

## Ordering rules
1. **Do §1.3 Step 1 (register the App ID with "Sign in with Apple" enabled) BEFORE §1.2 Step 2** — the key you create in 1.2 attaches to that App ID.
2. **Apple-portal steps** can be done **now**. The **iHymns-admin steps** (§1.2 Steps 4–5) require the branch's PHP to be **live on the docroot** first — that's **§1.1 (deploy)**, which needs a push go-ahead. Until §1.1, the SIWA config fields and new migration cards do not appear in `/manage`.

---

## §1.3 — App ID, capabilities, App Store Connect, CarPlay

### Step 1 — Register the App ID + capabilities  *(do first)*
1. **developer.apple.com/account** → **Certificates, Identifiers & Profiles** → **Identifiers** → **➕**.
2. **App IDs** → **App** → **Continue**.
3. **Description**: `iHymns`. **Bundle ID**: **Explicit** → `app.ihymns`.
4. Tick under **Capabilities**:
   - **Sign in with Apple** (required for §1.2's key + App Review).
   - **Associated Domains** (Universal Links + Handoff + password autofill — the app declares `applinks:ihymns.app`, `webcredentials:ihymns.app`, `activitycontinuation`).
   - *(Tick later, when those features land — NOT blocking now:)* **Push Notifications** (Phase 2 Live Activities/APNs), **App Groups** (Phase 1.5 widgets), **iCloud/CloudKit** (only if CloudKit sync is adopted).
   - *(Background audio is an Info.plist key — already in `project.yml` — NOT an App ID capability. Nothing to tick.)*
5. **Continue** → **Register**.

### Step 2 — App Store Connect app record
1. **appstoreconnect.apple.com** → **Apps** → **➕ New App**.
2. **Platforms**: tick every target platform → one record = one **Universal Purchase** across iOS/iPadOS/macOS/tvOS/watchOS/visionOS.
3. **Name**: `iHymns`. **Primary Language**. **Bundle ID**: `app.ihymns`. **SKU**: e.g. `ihymns-universal`. **User Access**: Full.
4. **Create.**

### Step 3 — Family Sharing  ⚠️ **CORRECTED: nothing to do now**
- Family Sharing is **not shown for a free app with no in-app purchases** — there is nothing purchasable to share.
- It becomes a **per-IAP / per-subscription toggle** set when you create each in-app purchase (**Phase 4, #1434**). You *can* create IAPs ahead of selling, and tick Family Sharing on each at creation — but there's no reason to until the StoreKit code exists.
- **Action now: none. Revisit at #1434.**

### Step 4 — CarPlay entitlement  *(long lead — start early; Phase 3 #1431)*
1. **developer.apple.com/contact/carplay/** (CarPlay entitlement request form).
2. Choose the **Audio** app category, provide the app/bundle-ID, and **accept the CarPlay addendum/terms** (submit is usually gated on that checkbox).
3. ⚠️ **The Audio path does NOT ask you to describe/justify the app** — that free-text step is only for the hand-reviewed categories (navigation, EV, parking, driving-task, communication). For Audio, category + terms **is** the whole request; a missing description prompt is normal, not a skipped step.
4. Confirm you got an **on-screen "submitted" confirmation and/or an email**. Approval (for Audio, often quick) arrives **by email** and makes `com.apple.developer.carplay-audio` available to add to the app's entitlements — only relevant once #1431 is built. Nothing downstream is blocked on it.

### Step 5 — APNs key  ⚠️ **CORRECTED: defer to Phase 2 (#1410)**
- Only needed for **Live Activities / Dynamic Island (#1410 / #1429)** — **unused today**. Recommendation: **don't create it now** (unused credential = extra thing to guard).
- 🔑 **Make it a SEPARATE key from Sign in with Apple — do NOT just tick "APNs" on the SIWA key.** (Apple lets one key enable multiple services, but we keep them single-purpose — see [FAQ: Why separate keys](#faq--why-separate-apple-keys-siwa-vs-apns) at the bottom.)
- **If you create it anyway:**
  - **Environment**: a single **"Sandbox & Production"** key works. The "use separate keys" nudge is defense-in-depth (a leaked dev key can't reach real users) — optional for a small single-app team.
  - **Type**: **Team Scoped (All Topics)** = simplest. **Topic Specific** (scoped to `app.ihymns`'s Live-Activity topic) = least-privilege — adopt *when APNs is actually wired at #1410*.
  - Acceptable placeholder: **Team Scoped + Sandbox & Production**, stored securely (a second `.p8`, downloadable once).

### Step 6 — Verify  *(where exactly)*
- **App ID**: developer.apple.com → **Identifiers** → click **`app.ihymns`** → *Sign in with Apple* + *Associated Domains* show enabled.
- **App Store Connect**: **Apps** → **iHymns** record exists with the platforms.
- **SIWA key**: developer.apple.com → **Keys** → your `iHymns Sign in with Apple` key is listed.
- **CarPlay**: no portal status — Apple replies **by email**.
- *(Family Sharing + APNs: skipped/deferred, so nothing to verify.)*

---

## §1.2 — Sign in with Apple key + iHymns config + migrations

### Step 1 — Team ID
- developer.apple.com/account → **Membership details** → copy the 10-char **Team ID** (e.g. `Y5XK559SV…` truncated — full value on that page). (Also drives the AASA `appID`.)
- ⚠️ **The Team ID lives in TWO independent places that must be IDENTICAL — neither overrides the other:**
  - the **`APPLE_TEAM_ID` GitHub secret** → read at **build time** by `apple-deploy.yml` → Fastlane to **sign the app** (baked into the `.ipa`);
  - the **`apple_team_id` setting** (this UI → `tblAppSettings`) → read at **runtime** by `/.well-known/apple-app-site-association.php` to publish the AASA `appID`.
  - PHP cannot read GitHub secrets, and the **web deploy (`deploy.yml`) never injects the Team ID** — so the runtime AASA value comes *only* from this UI field. If the two differ, the AASA advertises a Team ID the installed app was **not signed with** → Universal Links **silently fail** (links open Safari, not the app). It is **not a secret** (the AASA publishes it), so the DB/UI is a fine home for it — just keep it in lockstep with the secret. (Drift-elimination — have the deploy cross-check/inject it — is tracked as an enhancement idea.)

### Step 2 — Sign in with Apple key + `.p8`  *(requires §1.3 Step 1 done)*
1. **Certificates, Identifiers & Profiles** → **Keys** → **➕**.
2. **Key Name**: `iHymns Sign in with Apple`.
3. Tick **Sign in with Apple** → **Configure** → **Primary App ID** = `app.ihymns` → **Save**.
4. **Continue** → **Register**.
5. **Note the Key ID** (10 chars) → **Download** `AuthKey_XXXXXXXXXX.p8`.
   - 🔐 **Downloadable ONCE.** Store in your password manager/vault. Private key — never email it, never commit it to git.

### Step 3 — Email from `ihymns.app` + Apple private-relay verification  *(owner preference — set up ahead)*

> **This is the "email sources" config, NOT a `.p8` key** — screen: *developer.apple.com → Certificates, Identifiers & Profiles → **More** → **Configure** (Sign in with Apple for Email Communication)*. It's independent of the SIWA key; do it whenever.
>
> **Not required for core auth today** (the app never emails SIWA "Hide My Email" users), but the owner's preference is to make `ihymns.app` a first-class sending domain now so *all* app email (magic links, receipts, future notifications) and the SIWA relay path are ready.

**Why `ihymns.app` shows ❌ SPF while `mwbmpartners.ltd` shows ✅:** Apple does **not** issue an SPF record — it *verifies* that the sending domain already publishes a valid **SPF** (or **DKIM**) record authorizing your mail sender. `mwbmpartners.ltd`'s DNS already has that; `ihymns.app`'s doesn't yet. **The real work is DNS on `ihymns.app`, not Apple's console.**

1. **Access `ihymns.app`'s DNS zone** — most likely the **DreamHost panel** (Domains → Manage → DNS), else the registrar holding `ihymns.app`. (This is the previously-blocked step — the unblock is getting into that zone.)
2. **Choose the sender for `@ihymns.app`** — simplest is the same service the app already sends magic-link mail through. Given the app's Gmail/Workspace integration (`email_gmail_sa_json` service account), that likely means adding `ihymns.app` to **Google Workspace** + Google SPF/DKIM; a transactional provider is the alternative (add `ihymns.app` as a verified sending domain there).
3. **Publish 3 DNS records on `ihymns.app`** (exact values come from the provider):
   - **SPF** — one TXT at `@`, e.g. Google: `v=spf1 include:_spf.google.com ~all`. ⚠️ Only ONE SPF record allowed — **merge**, don't add a second.
   - **DKIM** — the TXT/CNAME the provider issues (Google Workspace: a `google._domainkey` TXT generated in Admin → Apps → Gmail → Authenticate email).
   - **DMARC** — TXT at `_dmarc`, start monitor-only: `v=DMARC1; p=none; rua=mailto:dmarc@ihymns.app` (tighten to `quarantine`/`reject` later).
4. **Register in Apple** (the screen above): add **Domain** `ihymns.app` + specific **Email address** sources (`no-reply@ihymns.app`, `support@ihymns.app`, …). After DNS propagates (minutes–hours) the ❌ flips to ✅ automatically.

> **To get exact records:** tell the build assistant (a) where `ihymns.app` DNS is hosted and (b) which email sender the app uses (Google Workspace vs a transactional provider), and it can write out the precise SPF/DKIM/DMARC values to paste.

### Step 4 — Paste secrets into iHymns  *(after §1.1 deploy)*
1. iHymns admin → **`/manage/configuration`** → **"Apple native app"** card.
2. Enter: **Apple Team ID** (Step 1), **SIWA Key ID** (Step 2), **SIWA Private Key** = the **entire** `.p8` contents **including** the `-----BEGIN PRIVATE KEY-----` and `-----END PRIVATE KEY-----` lines.
3. **Save.** Server validates it as PKCS#8 EC P-256; stores it in `tblAppSettings` (shared across all 3 docroots — done once). Card then shows "set" badges; the key is never echoed back.

#### How the SIWA key is stored (security) — and why it isn't *hashed*

> **It can't be hashed.** A hash is one-way (for values you only ever *verify*, like passwords in `tblUsers.PasswordHash`). The `.p8` must be *used* — the backend signs the ES256 `client_secret` JWT for Apple's `/auth/token` + `/auth/revoke` — so it has to stay retrievable/usable. Encryption (reversible), not hashing, is the right primitive.
>
> **Controls in place today:** admin-only write (Global Admin + `manage_configuration` + CSRF) · never echoed to the form (a `password`-type "secret" field with blank-skip — a blank save keeps the existing value) · never logged (the activity log records changed key *names* only — values are on the redaction list) · validated as EC-P256 before save · stored via prepared statement · HTTPS in transit · **read server-side only** (`includes/apple_siwa.php`) — never sent to any client, API response, or the native app.
>
> **Encryption-at-rest status — the engine is BUILT but DORMANT (#1466).** The `.p8` (and the other 7 secret settings — Gmail SA JSON, SES access+secret, SMTP password, SendGrid/Mailgun keys, Azure Graph client secret) become ciphertext at rest **once** the operator provisions a master key on all 3 docroots **and** runs the encrypt-in-place migration (see the activation note below). **Until then they remain plaintext** in `tblAppSettings.SettingValue` — a verified no-op, the decrypt-capable readers just pass plaintext through unchanged. Residual risk *until activated* = DB-level exposure (a dump/backup, leaked DB credentials, or SQL-injection elsewhere); protect the **database** (access controls, encrypted backups) in the meantime.
>
> **Blast radius if leaked:** a *server-to-server* Apple credential — it signs `client_secret`s for token-exchange/revoke. It **cannot** forge Apple-signed user identity tokens or mint iHymns bearer tokens, and it's **rotatable** at Apple anytime (Keys → revoke + reissue → re-paste). Contained + recoverable.
>
> **Hardening — #1466 (LOCKED design; P1–P5 BUILT + dormant on `feat/apple-universal`).** The `.p8` and the other 7 secrets are **encrypted at rest INSIDE `tblAppSettings`** — NOT moved to a file. (The earlier "move the `.p8` out of the DB to a `.auth/` file" proposal was **superseded**: a UI that writes files PHP later `require()`s breaks the "`.auth/` is never web-writable" invariant, and it forces 3 manual placements per secret = drift, this project's most expensive failure class.) Each value becomes a self-describing `enc:v1:<alg>:<keyid>:<nonce>:<ct>` envelope (libsodium secretbox / AES-256-GCM), decrypted at runtime with a **master key** in `appWeb/.auth/secrets_master_key.php` (sibling of the docroot, git-ignored, never web-served; seeded from the `SECRETS_MASTER_KEY` GitHub Secret if absent, or hand-SFTP'd). Disclosure then needs **two** primitives — a DB read **and** a filesystem read of the key. Does NOT protect a compromised runtime (RCE on any docroot reads both). **Full operator activation runbook: `DEV_NOTES.md` → "🔐 Operator runbook — turning on secret encryption (#1466)"; threat model + phasing: `.claude/secret-encryption-strategy.md`.**
>
> **⚠ Master-key format — it MUST be 64 HEX chars, not base64.** Generate with **`openssl rand -hex 32`** (or the panel's "Generate master key" button on `/manage/setup-database`), **never** `openssl rand -base64 48`. A 64-char *base64* value looks the right length but is 48 bytes with non-hex characters, so the engine's `^[0-9a-fA-F]{64}$` validator rejects it → the feature stays **silently dormant** (fail-safe, no crash), and the deploy seed step refuses it (`exit 1`). The **same** 64-hex value goes into the `SECRETS_MASTER_KEY` GitHub Secret *and* every docroot's `secrets_master_key.php` (all 3 docroots share one DB → one shared key). *(This exact base64-vs-hex mistake was hit once on the dev key.)*
>
> **Two owner confirmations, before/around activation (strategy §12):**
> 1. **The DB user must lack the MySQL `FILE` privilege.** If it had `FILE`, a SQL-injection could `SELECT LOAD_FILE('…/.auth/secrets_master_key.php')` and read the key *through* the database — collapsing the two-primitive protection back to one. Shared DreamHost almost never grants `FILE`, but confirm once: `SHOW GRANTS FOR CURRENT_USER();` (no `FILE`) + `SELECT @@GLOBAL.secure_file_priv;` (a path/NULL = restricted). *(A web-runnable self-check can be added to the Secret-encryption panel if wanted.)*
> 2. **Rotate provider-side keys after activation** for any secret that had a *plaintext-era* DB backup (SMTP password, SendGrid, Mailgun, SES access+secret, Azure Graph client secret, Gmail SA JSON) — encryption protects *future* dumps only, never past ones. The **`.p8` is exempt**: it was only ever entered under this program (no plaintext-era copy to neutralise), and it's rotatable at Apple anytime (see "Blast radius" above).

### Step 5 — Run the migrations  *(after §1.1 deploy)*
1. **`/manage/setup-database`**.
2. Run (once each — shared DB):
   - **"Sign in with Apple — provider links + nonce ledger"** → `tblUserAuthProviders` + `tblAuthNonces`.
   - The **analytics events** card → `tblAppAnalyticsEvents`.
3. Cards flip to **applied/green**.

### Step 6 — Verify
- After §1.1 deploy, load **`https://ihymns.app/.well-known/apple-app-site-association`** → shows your real Team ID in `"appID": "<TeamID>.app.ihymns"` (not the `TEAMID` placeholder).
- Config card = all three values "set"; migration cards = applied.
- **There is no native Sign in with Apple button to tap today.** `auth_apple` exists server-side (#1402), but nothing in `iHymnsKit` calls it yet — native sign-in is **Password or Email-Code** (`LoginView.swift`). Until a native SIWA button exists, these migrations + the Key ID/`.p8` serve two things end-to-end verifiable on a signed device/TestFlight build only (not possible in the build environment): the **refresh-token exchange + account-deletion Apple-revoke** paths (which degrade gracefully until provisioned), and the ***web*** SIWA flow (§1.4), which already has a client button and verifies in a browser.

---

### §1.4 — Web Sign in with Apple — activation

> **Web SIWA** (browser-based sign-in on `ihymns.app` / `dev.ihymns.app` / `beta.ihymns.app`) is **built + merged but DORMANT**. Activating it is a **MIX** of Apple-portal clicks, **ONE** database migration, and **TWO** settings — **NOT all migrations**. This is separate from the *native* app's SIWA key above (§1.2 Step 2) — the web flow authenticates via its own **Services ID**, not the App ID's key. (Full detail + copy-paste steps: `DEV_NOTES.md` → "🍎 Operator runbook — turning on Sign in with Apple for the WEB".)

1. **[Apple Developer portal — NOT a migration] Create a Services ID (decision D1).** developer.apple.com → Certificates, Identifiers & Profiles → **Identifiers → ➕ → Services IDs** → identifier `app.ihymns.web`; tick **Sign in with Apple** → **Configure** → **Primary App ID** = `app.ihymns`. ⚠️ Must be registered under the **SAME Apple Developer Team** as `app.ihymns` (decision D1) — a different team issues a different Apple `sub`, breaking account reconciliation between native and web. Register the **return URL** `https://<host>/` (the origin root) for **every** docroot offering web SIWA (`ihymns.app`, `dev.ihymns.app`, `beta.ihymns.app`).
2. **[/manage/setup-database migration — ONCE, shared DB]** Run **`migrate-user-auth-providers`** (→ `tblUserAuthProviders` + `tblAuthNonces`) if it isn't already green — usually already applied via §1.2 Step 5 above, since it ships with the native SIWA backend.
3. **[/manage/configuration settings — NOT a migration]** `/manage/configuration` → **Apple native app** card → **Sign in with Apple — Web** section: paste the Services ID from Step 1 into **`apple_siwa_services_id`** (must **NOT** equal `app.ihymns`), and set **`apple_web_login_enabled`** to the channel(s) being rolled out — start `alpha`, widen to `alpha,beta`, then `all`. Web SIWA stays dormant until BOTH settings are set for the current channel.
4. **[Code — already done]** `appleid.cdn-apple.com` is already listed in the CSP `script-src` (#1484) so Apple's JS SDK loads once enabled — no action needed.
5. **[Verify]** On a docroot in the enabled channel, the auth modal shows a **Sign in with Apple** button; sign-in creates/links an account; Link/Unlink live in Settings → Account & Profile → **Connected accounts**.

> **Why it's not all migrations:** the Services ID (Step 1) is an Apple-portal identity artifact PHP cannot create; the enable/config (Step 3) is a `tblAppSettings` write, not a schema change. Only Step 2 is an actual migration — and it's usually already done.

---

## Verification walkthrough — where to look & what "good" looks like

Three buckets: what you can verify **now** (Apple portals), what's **blocked on §1.1 deploy**, and what needs a **signed device build**.

### A. Verify now (Apple portals — you look)
| What | Where | "Good" looks like |
|------|-------|-------------------|
| **App ID** | developer.apple.com → Certificates, Identifiers & Profiles → **Identifiers** → click `app.ihymns` | **Sign in with Apple** ✅ + **Associated Domains** ✅ both enabled |
| **SIWA key** | same portal → **Keys** | a key named `iHymns Sign in with Apple` is listed; you hold its `.p8` + 10-char Key ID |
| **Team ID** | developer.apple.com → **Membership details** | 10-char Team ID captured |
| **App Store Connect record** | appstoreconnect.apple.com → **Apps** | an `iHymns` record exists with your platforms |
| **CarPlay** | your inbox | ✅ "request submitted" confirmation received (grant email later) |

### B. Blocked on §1.1 (deploy) — nothing to verify until the branch PHP is live
- **AASA** — after deploy, `curl -s https://ihymns.app/.well-known/apple-app-site-association` must return **HTTP 200** + `Content-Type: application/json` with `"appID": "<TeamID>.app.ihymns"`.
  - ⚠️ **Re-verify per environment — do not assume all three are still `301`.** Checked 2026-07-08, all three envs (`ihymns.app`, `dev.`, `beta.`) returned a `301` redirect (the #1401 AASA responder wasn't deployed yet). Since then the Apple backend merge (#1464, which ships the #1401 responder) has landed on **alpha**, and `alpha` auto-deploys to **`dev.ihymns.app`** — so **`dev.` may now return a direct 200**; **`beta.` and production `ihymns.app` have not had that backend promoted yet and are still expected to 301.** Apple **does NOT follow redirects** for the AASA, so a 301 = Universal Links fail on that env. **Curl each env individually and confirm 200, not 301, before relying on it** — don't infer one env's status from another's.
- **SIWA config card** — `/manage/configuration` → "Apple native app" shows Team ID / Key ID / private-key as **"set."**
- **Migration cards** — `/manage/setup-database` shows **"Sign in with Apple — provider links + nonce ledger"** and the **analytics events** card **applied/green.**

### C. Needs a signed device / TestFlight build (not possible in the build env)
- **Native sign-in + account-deletion Apple-revoke**: the native client authenticates via Password/Email-Code — **there is no native Sign in with Apple button** (backend-only, #1402; `LoginView.swift`). Tap **Delete Account** (Danger Zone, #1478) on a device to confirm the re-auth flow + `account_delete`'s Apple-revoke path exercise the stored SIWA key end-to-end.
- **Universal Link → app**: tap an `ihymns.app/song/<id>` link on a device with the app installed → opens in-app (Safari fallback if not installed).
- **On-device VoiceOver / Dynamic Type** (#1458).

---

## Master verification checklist

> **Status (2026-07-10):** all dev/code-side work is complete. The unticked items below are **owner Apple-portal actions** (App ID, SIWA key, Team ID, App Store Connect record, sending-domain DNS) or post-`§1.1`-deploy config/migration steps — tick each as you complete it. (CarPlay is submitted; APNs + Family Sharing are deferred/N-A per §1.3.)

- [ ] App ID `app.ihymns` registered; **Sign in with Apple** + **Associated Domains** enabled
- [ ] App Store Connect **iHymns** record created (all platforms, Universal Purchase)
- [x] CarPlay entitlement **request submitted** (2026-07-08, confirmation email received) — ⏳ awaiting the separate entitlement-*grant* email before `com.apple.developer.carplay-audio` is usable
- [ ] SIWA key created; `.p8` + **Key ID** stored securely
- [ ] **Team ID** captured
- [ ] *(deferred)* APNs key — only at #1410
- [ ] *(N/A)* Family Sharing — only at #1434 (per-IAP)
- [ ] **`ihymns.app` sending domain** (owner preference, §1.2 Step 3): DNS access → SPF + DKIM + DMARC published → registered + verified (✅) in Apple's email-sources
- [ ] **After §1.1 deploy:** Team ID + Key ID + `.p8` pasted into `/manage/configuration`
- [ ] **After §1.1 deploy:** SIWA + analytics migration cards run
- [ ] **AASA returns 200 JSON** with the real Team ID on **every env** (⚠️ all three `301`-redirected as of 2026-07-08; the #1464 backend merge — which ships the #1401 responder — has since deployed to alpha → `dev.ihymns.app`, so `dev.` may now be 200, but `beta.`/production are not yet promoted and are still expected to 301; re-check each env individually, don't infer)
- [ ] *(device build)* end-to-end native sign-in (Password/Email-Code) verified on TestFlight *(there is no native Sign in with Apple button to verify — backend-only, #1402; see §1.2 Step 6)*

---

## FAQ — Why separate Apple keys (SIWA vs APNs)?

**Q: Can't one iHymns key enable *both* Sign in with Apple and APNs, instead of two keys?**

**A: Technically yes — but we deliberately don't, and single-purpose keys are the recommended practice.**

Apple's key-creation screen lets you tick **multiple services on one key** (Sign in with Apple, APNs, DeviceCheck, MusicKit, …), so a single combined "iHymns" key is possible. We keep them **separate and single-purpose** because:

1. **Least privilege / blast radius.** Both are ES256 `.p8` secrets. A leaked *combined* key hands an attacker **both** the ability to mint Sign-in-with-Apple client-secrets **and** to push notifications to every user. Two single-purpose keys mean one leak compromises only one capability.
2. **Different homes — a combined key doubles the exposure.** The **SIWA key** lives in `tblAppSettings` (read by the `auth_apple`/`account_delete` web endpoints); the **APNs key** is designed to live **outside the web docroot** in the push service (#1410: *"p8 outside docroot, ES256, HTTP/2"*). A shared key would have to be copied into *both* homes → it can now leak from two systems instead of one.
3. **Independent rotation & revocation.** Regenerate a compromised APNs key without disrupting Sign in with Apple, and vice-versa. A shared key forces you to rotate + redeploy both systems at once.
4. **APNs-only scoping.** APNs keys support Sandbox/Production + Topic scoping (least-privilege); SIWA keys don't. Separate keys let you tighten the APNs key without touching auth.
5. **Audit clarity.** `iHymns Sign in with Apple` + `iHymns APNs` make each key's purpose + storage location obvious for custody and incident response; a multi-purpose key muddies both.

**Trade-off:** a combined key is marginally simpler (one secret to manage). For a security-conscious project the isolation wins — and since APNs isn't needed until #1410, there's no combined-key convenience to gain today.

**Bottom line:** create the single-purpose **Sign in with Apple** key now (§1.2 Step 2); create a **separate APNs** key later at #1410 (§1.3 Step 5). Do **not** enable APNs on the SIWA key. *(The same "single-purpose" logic applies if you later add a MusicKit/DeviceCheck key — one key, one job.)*

---

# §2 — Distribution readiness: TestFlight + App Store (+ virtual/load testing)

> **Added 2026-07-10. Tracking issue: #1474.** A grounded readiness assessment of the repo as it stands, so we know exactly what gates a TestFlight upload vs an App Store submission. **DONE** = in the repo/working. **OWNER** = your Apple-portal / App-Store-Connect / GitHub-secret action. **CODE** = a source change we must make first. Every claim is anchored to a file.

## 2.0 What's already built (facts)

| Area | State | Where |
|------|-------|-------|
| **CI (build+test, no signing)** | ✅ SwiftLint + `swift test` (**514** Swift-Testing `@Test`s, up from 495 — #1478 added 5 test files for account deletion) + LOC/no-secrets/**privacy-manifest** guards + macOS build | `.github/workflows/apple.yml` |
| **Deploy pipeline** | ✅ `push alpha → TestFlight INTERNAL`, `push beta → TestFlight EXTERNAL`, `push main → App Store` | `.github/workflows/apple-deploy.yml` |
| **Fastlane lanes** | ✅ `test` / `alpha` / `beta` / `release` — `build_app` (gym) → `upload_to_testflight` (pilot) / `upload_to_app_store` (deliver); **manual signing** (no `match`); ASC API-key auth | `appApple/fastlane/Fastfile` |
| **Binaries uploaded** | ✅ **iOS** (with embedded **Watch** + **Widgets**) + **tvOS** `.ipa` | Fastfile `prepare_signed_archives` |
| **App targets** | ✅ `iHymns` (iOS), `iHymnsTV`, `iHymnsWatch`, `iHymnsWidgets` — each a 1-file `@main` shell over the `iHymnsKit` package (correct thin-shell design). **No separate macOS/visionOS native targets** — those ship via **Universal-Purchase compatibility** ("Designed for iPad" running on Apple-Silicon Mac / visionOS). | `appApple/Apps/*` |
| **Version** | Apple `MARKETING_VERSION = 0.1.0`, `CURRENT_PROJECT_VERSION = 202607070001` (UTC-timestamp build no.) — separate from the web app's `0.2501.0` | `appApple/Config/Versioning.xcconfig` |
| **Deployment target** | ⚠️ **26.0** on every platform (iOS/macOS/tvOS/watchOS/visionOS) | `appApple/Config/Shared.xcconfig` |
| **Privacy manifests** | ✅ per target (`iHymns`/`TV`/`Watch`/`Widgets`) | `appApple/Apps/*/Sources/PrivacyInfo.xcprivacy` |
| **IAP / StoreKit** | ✅ none — **free app** (paid tiers are future #1434/#1411) | (no `import StoreKit`) |
| **App API environment** | `defaultForBuild`: `#if DEBUG → dev`, else (**Release = TestFlight + App Store**) **→ `prod` (`ihymns.app`)** | `iHymnsKit/Sources/IHAPI/APIEnvironment.swift` |

## 2.1 ⛔ The remaining cross-cutting blocker (read first)

1. **The app's backend is only on `alpha` (→ `dev.ihymns.app`).** `auth_apple`, `account_delete`, `analytics_ingest` and the AASA responder merged to **alpha** (#1464) but are **NOT on `beta` or `main`/production**. A **Release build points at `prod` (`ihymns.app`)** — which lacks those endpoints — so **Sign in with Apple, account-sync and account-deletion silently fail on any TestFlight/App-Store build today.** → **Promote the Apple backend `alpha → beta → main` before device testing SIWA**, and it is a **hard prerequisite** for App Store. (Interim: a DEBUG build hits `dev` and works end-to-end on a Mac/simulator; a tester can also override the environment at runtime, but `beta`/`prod` must first *have* the backend.) *(This is the* native *SIWA path — the separate* web *SIWA feature, built dormant per #1471/#1480 + its CSP allowance #1484, is unaffected and is covered in §1.4.)*

✅ **RESOLVED — DONE (#1478): in-app account deletion (App Review §5.1.1(v)).** This was the second cross-cutting blocker: `AccountView.swift` previously had no destructive control beyond "Sign Out". It now has a separate "Danger Zone" section (`AccountView.swift:96-107`) that presents a re-auth-gated `AccountDeleteView` sheet (Password or Email-Code, reusing the login UI) which calls `?action=account_delete`; a wrong/expired credential (401), rate-limit (429), or last-Global-Admin block (409) leaves the session untouched, and only a confirmed 200 clears the local token + favourites + setlists + offline caches, exactly like sign-out. Verified against `AccountView.swift`, `AccountDeleteView.swift`, `AppRootViewModel+Auth.swift:113`, `SessionController.swift:297`. No longer blocks TestFlight or App Store submission.

## 2.2 TestFlight readiness

**Fastest first test = INTERNAL TestFlight** (up to 100 of your own team; **no beta review**).

**OWNER (Apple portal / App Store Connect / GitHub secrets):**
- Create the **App Store Connect app record** (§1.3 Step 2) — nothing uploads without it.
- Create an **Apple Distribution certificate** + export it; the deploy lane signs **manually** (no `match`).
- Set the **GitHub Actions secrets** the deploy reads (`apple-deploy.yml` L94-103): `APPLE_CERTIFICATE` (base64 `.p12`), `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGNING_IDENTITY`, `APPLE_TEAM_ID`, `APPLE_ID`, `APPLE_PASSWORD` (app-specific password), `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_API_KEY`. *(Set these via the GitHub web UI — Settings → Secrets and variables → Actions.)*
- Register the **App ID capabilities** (Sign in with Apple + Associated Domains) — §1.3 Step 1.

**CODE:**
- **Declare export compliance** — add `ITSAppUsesNonExemptEncryption = NO` to the app Info.plist/`project.yml` (the app uses only standard HTTPS/TLS → exempt). Not declared today → App Store Connect prompts **per build** and stalls TestFlight processing.
- **Make TestFlight actually usable** — resolve blocker #1 (§2.1): either promote the backend so `prod` has it, or point the Internal-TF build at a backend-having docroot (the `APIEnvironment` runtime override exists; `defaultForBuild` currently sends Release→`prod`).

**For EXTERNAL testers (beta lane):** additionally requires **`beta.ihymns.app` to have the backend** and a one-time **TestFlight beta-app review** (beta description, contact email, "what to test").

**Verdict:** the *pipeline* is ready; **TestFlight is gated on OWNER provisioning (secrets + ASC record + cert) and the backend-environment blocker.** No fundamental code rewrite needed.

## 2.3 App Store readiness

Everything in §2.2, **plus**:

- ⛔ **Backend live on production `ihymns.app`** (blocker #1, §2.1) — required or SIWA/sync/delete fail for real users.
- ✅ **In-app account deletion** (§5.1.1(v)) — **DONE (#1478)**, see §2.1. No longer a blocker.
- ⛔ **AASA returns HTTP 200 JSON** on production (the runbook §B flagged a **301** on all envs as of 2026-07-08; the #1464 backend merge has since deployed to alpha → `dev.ihymns.app`, so `dev.` may now be 200, but **production `ihymns.app` has not been promoted** and is still expected to 301 — Apple does **not** follow redirects, so Universal Links fail until it's a direct 200 on **production**). Re-verify per env after promotion.
- **App Store metadata — NONE committed** (`appApple/fastlane/metadata/` absent). Need: description, keywords, promotional text, **support URL**, **privacy-policy URL**, **age rating**, and the **App Privacy "nutrition label"** answers (must match the `PrivacyInfo.xcprivacy` manifests + `analytics_ingest`'s "no user-id / device-id / IP" claim). Enter in App Store Connect or commit under `fastlane/metadata` for `deliver`.
- **Screenshots** per device class (iPhone + iPad + Apple TV, at the required display sizes) — needs a signed build in Simulator/on-device to capture.
- ⚠️ **Deployment target 26.0** — only devices on **iOS/tvOS/etc. 26+** can install. App Review permits it, but the **addressable market is tiny**; confirm this is the intended Liquid-Glass/latest-API stance (raise it deliberately, don't discover it post-launch). Lowering it later widens reach but costs back-compat work.
- **Platform coverage** — the initial submission is **iPhone/iPad + Apple TV** (Mac & visionOS via "Designed for iPad" **Universal-Purchase** availability toggles in App Store Connect — verify those toggles; there is no separate Mac/vision binary). watchOS + Widgets ride inside the iOS app.
- **Sign in with Apple presentation (§4.8)** — the native client offers only its own account system (Password / Email-Code, `LoginView.swift`) with **no third-party social login at all** (no Google/Facebook/etc. — and, today, no client-side Apple button either, despite the backend `auth_apple` endpoint existing for #1402). §4.8 only requires SIWA when a third-party/social login is offered without it as an equal option; since none is offered, the app is exempt regardless. Revisit only if a native SIWA (or Google/Facebook) button is later added — SIWA would then need to ship alongside it.
- **Content rights** — hymn/worship lyrics: the gating/CCLI system (#1352/#1353) governs copyrighted content; be ready to answer an App Review content-rights question.

**Verdict:** **not submittable yet** — blocked on backend-to-production, AASA-200 on production, and the full metadata/screenshots set (all listed above). *(In-app account deletion is no longer a blocker — DONE, #1478.)*

## 2.4 Virtual / pre-submission testing (Xcode Simulator)

| Test | Simulator | Needs real signed device / TestFlight |
|------|-----------|----------------------------------------|
| UI / navigation / layout, Dynamic Type, dark mode, each platform's shell | ✅ run each target's scheme in its Simulator | — |
| Unit / package tests (the 514 `@Test`s) | ✅ `swift test` / Xcode test action (macOS host) | — |
| Instruments profiling (memory, hangs, energy) | ✅ (approximate — Sim uses the Mac CPU/GPU, **not** representative of device perf) | ✅ real device for true perf/energy |
| **Sign in with Apple (native)** | **N/A today** — no native SIWA button exists yet (backend-only, #1402); native sign-in is Password/Email-Code and *is* testable in Simulator | — |
| **Universal Links** (`applinks:ihymns.app`) + Handoff | ❌ associated-domains behave differently in Sim | ✅ real device + live 200 AASA |
| Push / Live Activities (future #1410) | ❌ | ✅ |

**macOS** needs no simulator — the "Designed for iPad" build runs natively on an Apple-Silicon Mac.

## 2.5 "Load testing" — the honest answer

**You cannot meaningfully "load test" the client app**, and Xcode is the wrong tool for load: XCTest *performance* tests measure **client** code paths (scroll, parse, launch), not server capacity. The app is a **thin API client** — **the load risk lives on the shared PHP/MySQL backend** (the one DreamHost DB behind all three docroots).

So a realistic pre-launch sequence is:
1. **Client smoke/soak** — run each platform target in Simulator + **Instruments** (Allocations, Time Profiler, Hangs) on a device; catch leaks/hangs/energy regressions.
2. **Backend load test (the real one)** — hit the read-heavy `?action=…` endpoints (`songs_index`, `song_detail`, `search`, `related_songs`, media HEAD/GET) with **k6 / Vegeta / `hey` / JMeter** against a **NON-production docroot** (`dev.` or `beta.`, **never** `ihymns.app`). Respect the backend's **per-IP + per-presence-token rate limits** — aggressive tests will (correctly) get **429**s; either test *at* the documented limits or temporarily raise them on the test docroot, and remember all three docroots share ONE MySQL so a load test on `dev.` still exercises the **production database** — coordinate timing.
3. **Real end-to-end** — Internal TestFlight on physical devices (the only place SIWA + Universal Links + real performance are truthful).

*(Emulation note: there is no Android-style device farm here; simulators + a small physical-device set + TestFlight is the Apple path. visionOS Simulator is heavy — allow time.)*

## 2.6 Readiness checklist

> **Status (2026-07-10):** dev-side is done — in-app **account deletion ✅ (#1478)**, the native app builds with 514 tests green, and web SIWA + the CSP entry shipped. The unticked items are **owner actions** (Apple-portal provisioning, App Store metadata/screenshots), the **backend-to-production promotion** (blocker #1), and one remaining **CODE** item (`ITSAppUsesNonExemptEncryption` — small, ask and I'll do it).

**TestFlight (internal) — minimum to first upload:**
- [ ] App Store Connect app record created (§1.3 Step 2)
- [ ] Apple **Distribution certificate** created + exported to `APPLE_CERTIFICATE`
- [ ] All 9 deploy secrets set in GitHub (`apple-deploy.yml` L94-103)
- [ ] App ID capabilities (SIWA + Associated Domains) registered (§1.3 Step 1)
- [ ] **CODE:** `ITSAppUsesNonExemptEncryption = NO` declared
- [ ] ⛔ Backend promoted so the build's target env has `auth_apple`/`account_delete`/`analytics_ingest` (or Internal-TF build pointed at `dev`)
- [ ] `push alpha` → build appears in TestFlight → install on a device → native sign-in (Password/Email-Code) works *(no native Sign in with Apple button exists to test — backend-only, #1402)*

**App Store (adds):**
- [x] **DONE (#1478):** in-app **Delete Account** flow (§5.1.1(v)) wired to `account_delete`
- [ ] ⛔ Backend live on **production** `ihymns.app`
- [ ] ⛔ AASA returns **200 JSON** (not 301) on production with the real Team ID
- [ ] Metadata: description / keywords / support & privacy URLs / age rating
- [ ] **App Privacy** answers match the manifests + analytics no-PII claim
- [ ] Screenshots per device class (iPhone / iPad / Apple TV)
- [ ] Deployment-target 26.0 confirmed as intended (addressable-market decision)
- [ ] Universal-Purchase availability (Mac / visionOS "Designed for iPad") toggles set
- [ ] `push beta` (external TF + beta review) → soak → `push main` (App Store)
