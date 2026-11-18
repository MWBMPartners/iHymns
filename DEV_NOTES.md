# 🛠 iHymns — Developer Notes

> Technical notes, decisions, deployment setup, and key documentation for contributors.

---

## 📋 Table of Contents

- [Deployment Keys & Secrets](#-deployment-keys--secrets)
- [Song Data Format](#-song-data-format)
- [Architecture Decisions](#-architecture-decisions)
- [Deployment Architecture](#-deployment-architecture)
- [Development Environment](#-development-environment)
- [Test Suites](#-test-suites)
- [Commit Message Conventions](#-commit-message-conventions)
- [Auto-Merge for Alpha PRs](#-auto-merge-for-alpha-prs)
- [Gating Registry — adding a gateable feature (#1352)](#-gating-registry--adding-a-gateable-feature-1352)
- [Content-Gating Enforcement (#1353)](#-content-gating-enforcement-1353)
- [Read Rate Limiting (#1354)](#-read-rate-limiting-1354)
- [API CSRF Model — `validateCsrfRequest()`](#-api-csrf-model--validatecsrfrequest)
- [`save_song` → v2 Editor API (shared core)](#-save_song--v2-editor-api-shared-core)

---

## 🔑 Deployment Keys & Secrets

All automated deployment is handled via GitHub Actions. Secrets and variables are configured in **GitHub Repo Settings → Secrets and Variables → Actions**.

### 🔐 Application runtime secrets (stored in the database)

Distinct from the GitHub-Actions *deploy* secrets below: a few secrets the **running app** needs live in the **`tblAppSettings`** table (one shared MySQL across all 3 docroots), set by a Global Admin via `/manage/configuration` — e.g. the **Sign-in-with-Apple `.p8` private key** (`apple_siwa_private_key`, #1402), the Gmail service-account JSON (`email_gmail_sa_json`), and the SES secret key (`email_ses_secret_key`).

**How the Sign-in-with-Apple key is stored — and why it is *not hashed*:**

- **It can't be hashed.** The backend must *sign with it* (the ES256 `client_secret` JWT for Apple's `/auth/token` + `/auth/revoke`), so it has to stay retrievable/usable at runtime. Hashing is one-way — for values you only ever *verify* (passwords), never recover. A signing key is the opposite.
- **Controls in place:** admin-only write (Global Admin + `manage_configuration` + CSRF); **never echoed** to the form (a `password`-type "secret" field with blank-skip — a blank save keeps the existing value); **never logged** (the activity log records changed key *names* only — values are on the redaction list); **validated** as a PKCS#8 EC P-256 key before save; stored via **prepared statement**; **HTTPS** in transit; **read server-side only** (`includes/apple_siwa.php`) — never sent to any client, API response, or the native app.
- **Caveat — values are still plaintext at rest *today* (encryption ships DORMANT).** The encrypt-at-rest engine (`includes/secret_crypto.php`, #1466) and its decrypt-capable readers are in the codebase, but until the master key is provisioned on all 3 docroots **and** the one-shot encrypt-in-place migration runs, every stored value remains **legacy plaintext** in `tblAppSettings.SettingValue` and the readers pass it through unchanged (a verified no-op — the "readers first" migration gate). Residual risk *until the migration runs* = **DB-level exposure** (a database dump/backup, leaked DB credentials, or a SQL-injection elsewhere). Mitigation until then: protect the **database** (access controls, encrypted backups).
- **Blast radius if leaked:** the `.p8` is a *server-to-server* Apple credential — it signs `client_secret`s for token-exchange/revoke. It **cannot** forge Apple-signed user identity tokens or mint iHymns bearer tokens, and it's **rotatable** at Apple anytime (Certificates, Identifiers & Profiles → Keys → revoke + reissue → re-paste). So: contained + recoverable.
- **Hardening — #1466 (LOCKED design; P1–P5 built, dormant):** **encrypt-at-rest inside `tblAppSettings`**, NOT move-to-file. Each flagged secret is stored as a self-describing `enc:v1:<alg>:<keyid>:<nonce>:<ciphertext+tag>` envelope (libsodium `crypto_secretbox`, or AES-256-GCM fallback) produced by `includes/secret_crypto.php`; the **master key** lives in `appWeb/.auth/secrets_master_key.php` — a sibling of the docroot, git-ignored, never web-served, seeded from the `SECRETS_MASTER_KEY` GitHub Secret if absent (manual SFTP rotation still overrides). Disclosure then needs **two** primitives: a DB read **and** a filesystem read of the key. This keeps the convenient `/manage/configuration` UI (a data write, propagating to all 3 docroots via the shared DB — zero drift) instead of forcing three manual file placements per secret. It does **not** protect a compromised runtime (RCE on any docroot reads both). Rollout is phased and **readers-first**, and **all five phases are now built (dormant) on `feat/apple-universal`**: P1 (engine + decrypt-capable readers, a verified no-op), P2 (deploy seed-if-absent + the Global-Admin Secret-encryption panel/status/generate-key tool), P3 (the gated encrypt-in-place migration + the hardened Rotate/re-encrypt card with the cross-docroot parity gate), P4 (opcache-bust key → `sha256:` hash) and P5 (the operator runbook below). Nothing is encrypted until a master key is provisioned on all 3 docroots **and** the P3 migration is run. The earlier "move the `.p8` out of the DB to a `.auth/` file" proposal was **superseded** by this design. Full plan + honest threat model: `.claude/secret-encryption-strategy.md`.

#### 🔐 Operator runbook — turning on secret encryption (#1466)

Every step below is doable by a **web-only operator** (no shell/SSH on the shared DreamHost host) — the GitHub web UI, `/manage/setup-database`, and hand-SFTP for one file. Full threat model + envelope format: `.claude/secret-encryption-strategy.md`.

1. **Prerequisite — readers first, non-negotiable.** The P1 decrypt-capable readers (`includes/secret_crypto.php` + reader wiring) must already be deployed and live on **all three docroots** (alpha, beta, production — one shared MySQL) before any value is encrypted. Until then every stored value is still legacy plaintext and reads/writes pass through unchanged — a verified no-op. This is the SongbookName prod-stale lesson (strategy §7) applied to secrets: encrypting before every docroot can decrypt would strand a lagging docroot with ciphertext it can't read.
2. **Generate a master key.** Either click **Generate master key** on `/manage/setup-database` → "Secret encryption" panel (CSPRNG `random_bytes`, shown **once** with copy-to-clipboard — it's a generator, not a writer; it never touches `.auth/` itself), or run `openssl rand -hex 32` yourself. The value MUST be **exactly 64 hex chars** — **not base64**. A non-64-hex value is silently rejected by the deploy-time seed step and the feature just stays dormant (this exact mistake was hit locally once already).
3. **Provision the key on all 3 docroots, byte-identical.** Two channels:
   - **(a) GitHub Secret (recommended)** — set it via the **web UI**: Repository → Settings → Secrets and variables → Actions → Secrets → New repository secret, name `SECRETS_MASTER_KEY`, value = the 64-hex key. The next deploy of each branch (alpha/beta/main) runs the "Seed secret-encryption master key if absent" step in `.github/workflows/deploy.yml`, which writes `appWeb/.auth/secrets_master_key.php` **only if that file doesn't already exist** on the remote — so all 3 docroots seed identically from the one secret. The `.auth` mirror explicitly excludes `secrets_master_key.php` (same as `db_credentials.php`), so a manually-placed key is never clobbered by a later deploy. **Note:** the seed step is gated on a code-change deploy (`has_changes`), so setting `SECRETS_MASTER_KEY` for the FIRST time only takes effect on the next deploy that includes an `appWeb/**` / `.sql/` / `.auth/` change — if you set the secret but aren't deploying code, either push a trivial change or hand-SFTP the key file (option b) to provision immediately.
   - **(b) Hand-SFTP** — write `appWeb/.auth/secrets_master_key.php` on each docroot yourself:
     ```php
     <?php
     return ['active' => 'k1', 'keys' => ['k1' => '<64hex>']];
     ```
4. **Verify parity on all 3 docroots.** Open `/manage/setup-database` → "Secret encryption" panel on **each** of alpha, beta, and production, and confirm: master key **present**, the **same** non-secret fingerprint on all three, and the **Sentinel** row shows **green**. If any docroot shows a different fingerprint, or a red/absent sentinel, **STOP** and fix the key before proceeding — a divergent key would strand that docroot the moment the migration runs.
5. **Run the encrypt-in-place migration ONCE.** On any one docroot (it's one shared DB), run the card **"🔐 Encrypt secrets at rest — ENCRYPT-IN-PLACE (#1466)"** on `/manage/setup-database`. It's flagged `manual` (excluded from "Apply all"); its run link carries `confirm=1` automatically but prompts you to type the migration slug (`secret-encrypt-inplace`) to confirm, per the standard manual-migration safety pattern. It **refuses to run** unless a master key is loaded **and** the sentinel round-trips green on that docroot. It encrypts the 8 flagged `tblAppSettings` secrets in place and flips `secret_encryption_active=1`. From then on, every secret saved via `/manage/configuration` is encrypted on write automatically.
6. **Rotate provider-side keys (neutralise old backups).** Encryption protects future DB dumps, not past ones — any backup taken while a secret was still plaintext stays dangerous forever. Rotate **at the provider** any secret that had a plaintext-era backup: SMTP password, SendGrid, Mailgun, SES access+secret key, Azure Graph client secret, Gmail SA JSON. The Sign-in-with-Apple `.p8` was never entered in the plaintext era, so there's nothing to rotate for it (strategy §9).
7. **Later — key rotation.** Generate a new key (step 2). Add it as a **second** keyid (e.g. `k2`) alongside `k1` in `secrets_master_key.php` on **all 3** docroots, then set `'active' => 'k2'` on all 3. Confirm every docroot's panel shows the new keyid present with a matching fingerprint. **Only then** use **Rotate / re-encrypt** on the panel — re-enter your password (re-auth) and type `ROTATE` to confirm — which re-wraps every secret under the new active keyid (audit-logged by key name + actor + timestamp, never values). Finally, remove the retired `k1` entry from all 3 files.
8. **(Independent, P4) Opcache-bust key hardening.** Optionally replace each docroot's `appWeb/.auth/opcache_bust_key.php` plaintext value with a one-way hash: `<?php return 'sha256:' . hash('sha256', '<the-plaintext-key>');`. `opcache-bust.php` now accepts both the `sha256:` hashed form and legacy plaintext. The CI deploy sender is unchanged — it still sends the raw key from the `OPCACHE_BUST_KEY` GitHub secret; only the stored comparison value on each docroot changes.

> **Note:** `SECRETS_MASTER_KEY`, like every other deploy secret, is set via the GitHub **web UI** (Settings → Secrets and variables → Actions), never via `gh secret set` — the plaintext key should never pass through a local shell or clipboard longer than necessary. Since it is already set, steps 1–3 are handled by the deploy seed on the next `appWeb/**` deploy — you are at **step 4 (verify parity) → step 5 (run the migration)**.

#### 🍎 Operator runbook — turning on Sign in with Apple for the WEB (#1470 W0)

Web SIWA is built + merged but **DORMANT**. Activating it is a **MIX** of Apple-portal clicks, ONE database migration, and TWO settings — **NOT all migrations**. Do them in order. (The *native* app's SIWA is separate — see `appApple/dev-docs/Provisioning-Runbook.md` §1.2; this same web runbook is also mirrored there as §1.4.)

1. **[Apple Developer portal — NOT a migration] Create a Services ID (this is decision D1).** developer.apple.com → Certificates, Identifiers & Profiles → **Identifiers → ➕ → Services IDs** → identifier e.g. `app.ihymns.web`; tick **Sign in with Apple** → **Configure** → **Primary App ID** = `app.ihymns`. ⚠️ **It MUST be under the SAME Apple Developer Team as `app.ihymns`** (that's what "D1" means) — a different team gives a different Apple `sub`, breaking account reconciliation across native/web (and later SIGNula). There is **no migration or DB step** that creates this; only Apple's portal can.
2. **[Apple portal] Register the Website URLs — the "Web Authentication Configuration" screen. BOTH fields are mandatory and take DIFFERENT formats — copy each row's middle column into the matching field EXACTLY:**

   | Apple field | Paste EXACTLY this | Format rule |
   |---|---|---|
   | **Domains and Subdomains** | `ihymns.app, www.ihymns.app, dev.ihymns.app, beta.ihymns.app` | bare hostnames — **NO** `https://`, **NO** slashes |
   | **Return URLs** | `https://ihymns.app/, https://www.ihymns.app/, https://dev.ihymns.app/, https://beta.ihymns.app/` | full URLs — **WITH** `https://` **AND** a trailing `/` |

   - ❌ **Do NOT** put a scheme in **Domains** — `https://ihymns.app` is wrong, and `https:///ihymns.app` (which an earlier draft of this doc accidentally implied) is doubly wrong. That field is **host-only**.
   - ❌ **Do NOT** drop the trailing slash in **Return URLs** — `https://ihymns.app` (no `/`) → Apple **`invalid_grant`** and sign-in fails.
   - ⚠️ **Register `www.ihymns.app` too, even though the app is usually reached at the bare root domain `ihymns.app`.** `www.ihymns.app` serves the site directly (HTTP 200, *not* a redirect to `ihymns.app`) and is an allow-listed canonical host (`appCanonicalHost()`), and `_appleSiwaWebHostAllowed()` accepts any `*.ihymns.app` — so a production visitor who lands on `www` makes the server send redirect_uri `https://www.ihymns.app/`; omit it and *their* sign-in fails `invalid_grant`. (Not exercised while only `alpha`→`dev.ihymns.app` is enabled, so it's harmless now — but register it up front so production is ready.)
   - ✅ **Sanity check:** Domains = the **4 bare hosts**; Return URLs = the **same 4 hosts** written as full `https://…/` URLs (each ending in `/`).
   - **Why the trailing slash matters:** Apple does an *exact string match* of the registered Return URL against the `redirect_uri` our code sends, which is the **origin root** — `apple-signin.js` sends `window.location.origin + '/'` and `appleSiwaWebRedirectUri()` builds `'https://' . $host . '/'` (= `APPLE_SIWA_WEB_RETURN_PATH`, `'/'`). It is the origin root, **not** a `/auth/apple/callback`-style path. (The flow is popup mode, but the Return URL must still be registered.)
   - Register **all three** docroots now even though you may only enable one channel at first (step 4) — pre-registering avoids a second portal trip when you widen the rollout.
   - *(If Apple prompts to **verify** a domain, host `apple-developer-domain-association.txt` at that host's `/.well-known/`. For the sign-in popup the URL registration above is sufficient; the domain-association file is chiefly for the separate **private email-relay / SPF sending-domain** step.)*
3. **[/manage/setup-database migration — ONCE, shared DB] Ensure the auth-provider tables exist.** Run the card **"Run Auth Providers Migration"** (`migrate-user-auth-providers.php` → `tblUserAuthProviders` + `tblAuthNonces`) **if it isn't already green** — it ships with the #1402 native backend, so it is likely already applied. This is the *only* migration web SIWA needs.
4. **[/manage/configuration setting — NOT a migration] Save the Services ID + enable a channel.** `/manage/configuration` → **Apple native app** card → **Sign in with Apple — Web** section: paste the Services ID from step 1 into **`apple_siwa_services_id`** (must NOT equal `app.ihymns`), and set **`apple_web_login_enabled`** to the channel(s) you're rolling out — start `alpha`, widen `alpha,beta`, then `all`. These are `tblAppSettings` writes (shared DB), **not** schema migrations. Web SIWA stays dormant until BOTH are set for the current channel.
5. **[Code — already done]** `appleid.cdn-apple.com` is in `index.php`'s CSP `script-src` (#1484) so Apple's JS SDK loads once enabled — no action.
6. **[Verify]** On a docroot in the enabled channel, the auth modal shows a **Sign in with Apple** button; sign-in creates/links an account; Link/Unlink live in Settings → Account & Profile → **Connected accounts**.

> **Why it's "not all migrations":** the Services ID (steps 1–2) is an Apple-portal identity artifact PHP cannot create; the enable/config (step 4) is a settings write, not a schema change. Only step 3 is an actual migration — and it's usually already done.

> **Web sign-in does NOT need the Apple `.p8` / Team ID / Key ID.** The web flow authenticates by verifying Apple's `id_token` against Apple's **public** JWKS (`appleSiwaVerifyIdentityToken()`), which needs no secret — so once steps 1–4 are done, sign-in works. The `authorizationCode`→token **exchange** (which *does* use the `.p8`) is best-effort and only captures a **refresh token**; without it, sign-in still succeeds and only the **account-deletion Apple-revoke** for web users degrades to `skipped_no_token`. Provision the `.p8`/Team ID/Key ID in `/manage/configuration` (same creds as native SIWA) before **production** if you want the revoke path complete — it is **not** a blocker for alpha testing.

#### 🎚 Operator runbook — turning on admin-configurable feature gating (#1481)

Registry + enforcement are built + **DORMANT**. To use it:

1. **[/manage/setup-database migration — ONCE, shared DB]** Run the card **"Admin-configurable feature gating (#1481 P1)"** (`migrate-add-gating-registry.php` → `tblGatingCapabilities` + `tblGatingRules`).
2. **[/manage/feature-gating — Global Admin]** Define a capability (key `Can…`, label, default), then optionally add an enforcement **rule** mapping it to a behaviour kind (`strip_payload_keys` / `drop_media_kinds`). Assign per tier on `/manage/tiers` (the matrix auto-grows a column). Rules affect the `song_detail`/`song_data`/`random` JSON payloads (a coverage note is shown in-page).
3. **[/manage/configuration settings]** In the **Feature gating** card, enable `content_gating_enabled` (the master content-gating switch) **and** `feature_gating_rules_enabled` (admin rules). Rules run only when BOTH are on — a byte-identical no-op until then.

### 🌐 Web/PWA — SFTP Deployment

The web app deploys via SFTP using `lftp` (mirroring the phpWhoIs pattern).

| Secret/Variable | Required | Description |
| --- | --- | --- |
| `SFTP_HOST` | ✅ Secret | SFTP server hostname (e.g., `ftp.ihymns.app` or `ihymns.app`) |
| `SFTP_PORT` | ❌ Secret | SFTP port number. Defaults to `22` if empty/blank/non-numeric |
| `SFTP_USER` | ✅ Secret | SFTP username for authentication |
| `SFTP_KEY` | ⭐ Secret | SSH private key for key-based auth (preferred over password) |
| `SFTP_PASSWORD` | ⭐ Secret | SFTP password (fallback if `SFTP_KEY` is not set) |
| `SFTP_LIVE_PATH` | ✅ Secret | Remote path for production (e.g., `/home/user/ihymns.app/public_html/`) |
| `SFTP_BETA_PATH` | ✅ Secret | Remote path for beta (e.g., `/home/user/beta.ihymns.app/public_html/`) |
| `SFTP_DEV_PATH` | ❌ Secret | Remote path for alpha/dev (e.g., `/home/user/dev.ihymns.app/public_html/`) |
| `SFTP_PRIVATE_PATH` | ❌ Secret | Remote path for private_html (e.g., `/home/user/admin.ihymns.app/`) — song editor, admin tools |
| `SFTP_ENABLED` | ✅ Variable | Set to `true` to enable SFTP deployment (kill switch). **Must be a Variable, not a Secret.** |

> ⭐ Either `SFTP_KEY` or `SFTP_PASSWORD` is required. SSH key auth is preferred.

#### How to get SFTP credentials

1. **DreamHost / shared hosting**: Go to your hosting control panel → Manage Users → create or use an existing SFTP/Shell user. The hostname is typically your domain or a server like `ftp.ihymns.app`.

2. **SSH Key setup**:

   ```bash
   # Generate a dedicated deploy key (no passphrase)
   ssh-keygen -t ed25519 -C "github-deploy@ihymns.app" -f ~/.ssh/ihymns_deploy -N ""

   # Copy the public key to your server
   ssh-copy-id -i ~/.ssh/ihymns_deploy.pub user@ihymns.app

   # The PRIVATE key (~/.ssh/ihymns_deploy) goes into the SFTP_KEY secret
   cat ~/.ssh/ihymns_deploy
   # Copy the entire output (including BEGIN/END lines) into GitHub secret
   ```

3. **Remote paths**: These are the absolute server paths where files should be uploaded. On DreamHost, typically `/home/<username>/<domain>/` (e.g., `/home/ihymns/ihymns.app/`).

---

### 🍎 Apple — App Store, TestFlight, Direct Distribution

The Apple app builds via GitHub Actions with Fastlane on a macOS runner.

| Secret | Required | Description |
| --- | --- | --- |
| `APPLE_TEAM_ID` | ✅ | Apple Developer Team ID (10-character alphanumeric) |
| `ASC_KEY_ID` | ✅ | App Store Connect API Key ID |
| `ASC_ISSUER_ID` | ✅ | App Store Connect API Issuer ID |
| `ASC_API_KEY` | ✅ | App Store Connect API Private Key (`.p8` file contents) |
| `MATCH_GIT_URL` | ✅ | Git repo URL for Fastlane Match certificate storage |
| `MATCH_PASSWORD` | ✅ | Encryption password for Fastlane Match |

#### How to get Apple credentials

##### 1. Apple Developer Team ID

1. Sign in to [Apple Developer](https://developer.apple.com/account)
2. Go to **Membership Details**
3. Your **Team ID** is displayed (e.g., `A1B2C3D4E5`)

##### 2. App Store Connect API Key

1. Sign in to [App Store Connect](https://appstoreconnect.apple.com)
2. Go to **Users and Access → Integrations → App Store Connect API**
3. Click **Generate API Key**
4. Select **Admin** role
5. Download the `.p8` file — this is your `ASC_API_KEY` (paste the full file contents)
6. Note the **Key ID** → `ASC_KEY_ID`
7. Note the **Issuer ID** at the top of the page → `ASC_ISSUER_ID`

> ⚠️ The `.p8` file can only be downloaded ONCE. Store it securely.

##### 3. Fastlane Match (Code Signing)

Fastlane Match stores signing certificates and provisioning profiles in a private Git repo.

```bash
# Install Fastlane
gem install fastlane

# Set up Match (run once, follow prompts)
cd appApple
fastlane match init

# Choose 'git' storage
# Enter the URL of a PRIVATE Git repo for certificate storage
# This URL goes into MATCH_GIT_URL secret

# Generate certificates for each type
fastlane match appstore    # For App Store / TestFlight
fastlane match adhoc       # For direct distribution
fastlane match development # For development

# The password you set during init goes into MATCH_PASSWORD secret
```

##### 4. Notarisation (Direct macOS Distribution)

For distributing `.dmg`/`.pkg` outside the App Store, apps must be notarised:

- Notarisation uses the same App Store Connect API key (`ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_API_KEY`)
- Fastlane's `notarize` action handles this automatically
- Ensure **Hardened Runtime** is enabled in Xcode project settings

---

### 🤖 Android — Google Play Store

The Android app builds via GitHub Actions with Gradle.

| Secret | Required | Description |
| --- | --- | --- |
| `ANDROID_KEYSTORE_BASE64` | ✅ | Release keystore file, base64-encoded |
| `ANDROID_KEYSTORE_PASSWORD` | ✅ | Password for the keystore |
| `ANDROID_KEY_ALIAS` | ✅ | Alias of the signing key within the keystore |
| `ANDROID_KEY_PASSWORD` | ✅ | Password for the specific key alias |
| `PLAY_SERVICE_ACCOUNT_JSON` | ❌ | Google Play Console service account JSON (for automated upload) |
| `PLAY_STORE_ENABLED` | ❌ Variable | Set to `true` to enable Play Store upload |

#### How to get Android credentials

##### 1. Generate a Release Keystore

```bash
# Generate a new keystore (run once, store securely)
keytool -genkey -v \
  -keystore ihymns-release.jks \
  -keyalg RSA -keysize 2048 \
  -validity 10000 \
  -alias ihymns \
  -storepass YOUR_STORE_PASSWORD \
  -keypass YOUR_KEY_PASSWORD \
  -dname "CN=MWBM Partners Ltd, O=MWBM Partners Ltd, C=GB"

# Base64-encode the keystore for the GitHub secret
base64 -i ihymns-release.jks | pbcopy
# Paste the clipboard contents into ANDROID_KEYSTORE_BASE64 secret
```

> ⚠️ **NEVER lose the keystore.** If lost, you cannot update your app on Google Play. Store it in a secure vault (e.g., 1Password, Bitwarden).

##### 2. Google Play Console Service Account

1. Go to [Google Play Console](https://play.google.com/console)
2. Go to **Settings → API Access**
3. Click **Create new service account**
4. Follow the link to **Google Cloud Console**
5. Create a service account with role **Service Account User**
6. Create a JSON key → download the `.json` file
7. Back in Play Console, grant the service account **Release Manager** permission
8. Paste the entire JSON file contents into `PLAY_SERVICE_ACCOUNT_JSON` secret

---

### 🔥 Amazon Fire OS — Amazon Appstore

Fire OS uses the **same APK** as standard Android (no Google Play Services dependency). Distribution to Amazon Appstore is currently manual.

| Requirement | Details |
| --- | --- |
| **Amazon Developer Account** | Sign up at [developer.amazon.com](https://developer.amazon.com) |
| **Signing** | Uses the same Android keystore (`ANDROID_KEYSTORE_BASE64`) |
| **APK** | The release APK built by `build-android.yml` is Fire OS compatible |
| **Upload** | Manual via [Amazon Appstore Developer Console](https://developer.amazon.com/apps-and-games) |

#### How to submit to Amazon Appstore

1. Sign in to [Amazon Developer Console](https://developer.amazon.com)
2. Go to **Apps & Games → Add a New App → Android**
3. Fill in app details (title, description, category, icons, screenshots)
4. Upload the **release APK** from the GitHub Actions build artifact (`android-release-apk`)
5. Set device support: **Fire tablets, Fire TV, Fire TV Stick**
6. Submit for review

> 💡 **Future automation**: Amazon provides the [App Submission API](https://developer.amazon.com/docs/app-submission-api/overview.html) which can be integrated into GitHub Actions. Create a secret `AMAZON_CLIENT_ID` and `AMAZON_CLIENT_SECRET` when ready.

#### Fire OS Compatibility Notes

- The iHymns Android app has **zero Google Play Services dependencies** — fully compatible with Fire OS
- Fire TV uses the **Leanback** library (included in `build.gradle.kts`)
- `AndroidManifest.xml` includes `LEANBACK_LAUNCHER` intent filter for Fire TV
- `android.hardware.touchscreen` is set to `required="false"` for Fire TV (remote-only navigation)

---

### 📋 Quick Setup Checklist

#### Minimum for Web/PWA deployment

- [ ] Set `SFTP_HOST` secret
- [ ] Set `SFTP_USER` secret
- [ ] Set `SFTP_KEY` or `SFTP_PASSWORD` secret
- [ ] Set `SFTP_LIVE_PATH` secret
- [ ] Set `SFTP_BETA_PATH` secret
- [ ] Set `SFTP_ENABLED` **variable** to `true`
- [ ] (Optional) Set `SFTP_PRIVATE_PATH` for song editor/admin tools deployment

#### Minimum for Apple deployment

- [ ] Set `APPLE_TEAM_ID` secret
- [ ] Set `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_API_KEY` secrets
- [ ] Set `MATCH_GIT_URL`, `MATCH_PASSWORD` secrets
- [ ] Run `fastlane match appstore` locally once to generate certificates

#### Minimum for Android deployment

- [ ] Generate release keystore and set `ANDROID_KEYSTORE_BASE64` secret
- [ ] Set `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` secrets
- [ ] (Optional) Set `PLAY_SERVICE_ACCOUNT_JSON` and `PLAY_STORE_ENABLED` for auto-upload

#### Amazon Fire OS

- [ ] Create Amazon Developer account
- [ ] Use the same Android release APK (no separate build needed)
- [ ] Submit manually via Amazon Developer Console

---

## 📂 Song Data Format

### File Naming Convention

Songs are stored in `.SourceSongData/<Songbook Name> [<Abbreviation>]/`

**Filename patterns vary by songbook:**

| Songbook | Pattern | Example |
| --- | --- | --- |
| Church Hymnal (CH) | `NNN (CH) - Title.txt` | `003 (CH) - Come, Thou Almighty King.txt` |
| SDA Hymnal (SDAH) | `NNN (SDAH) - Title.txt` | `001 (SDAH) - Praise to the Lord.txt` |
| Mission Praise (MP) | `NNNN (MP) - Title.txt` | `0001 (MP) - A New Commandment.txt` |
| Junior Praise (JP) | `NNN (JP) - Title.txt` | `001 (JP) - A Boy Gave To Jesus.txt` |
| Carol Praise (CP) | `NNN (CP) - Title.txt` | `001 (CP) - A Baby Was Born In Bethlehem.txt` |

**Companion files** (MP, JP, CP only):

- `*_audio.mid` — MIDI audio file
- `*_music.pdf` — Sheet music PDF

### Text File Structure

```text
"Song Title"            ← Line 1: Title in double quotes

1                       ← Verse number (standalone digit)
First line of verse,
Second line of verse,
...

Refrain                 ← Or "Chorus" — label on its own line
First line of refrain,
...

2                       ← Next verse
...

Words and music by ...  ← Writer/composer credits (some files only)
© Copyright holder      ← Copyright info (some files only)
Language: fr-FR         ← Optional IETF BCP 47 language tag (defaults to songbook language)
```

### Key Observations

1. **Title format**: Always in double quotes on line 1 (except SDAH — no quotes)
2. **Verse numbering**: Standalone integer on its own line
3. **Chorus/Refrain**: Labelled as "Refrain", "Chorus", or similar
4. **Writer credits**: Present in MP, JP, CP songbooks; absent in CH, SDAH
5. **Encoding**: UTF-8, some files contain special characters (curly quotes, em dashes)
6. **Song component order**: Components appear in the order they are sung
7. **No consistent blank line rules**: Some files have extra blank lines, parser must be tolerant
8. **Language tag**: Optional `Language: xx` line (IETF BCP 47 format, e.g., `en`, `fr-FR`, `zh-Hans-CN`). Falls back to songbook default if absent

---

## 🏗 Architecture Decisions

### Why Vanilla JS (not React/Vue/Angular)?

- Simpler build pipeline for PHP shared hosting
- Smaller bundle size (critical for PWA/offline)
- No framework lock-in
- Easy for contributors to understand
- Bootstrap handles responsive layout
- ES modules provide sufficient modularity

### Why PHP (not static-only)?

- Universal support on shared hosting (DreamHost, etc.)
- Server-side version injection (`infoAppVer.php`)
- Dynamic development status detection (Alpha/Beta/Production via directory path)
- Consistent with other MWBM Partners Ltd projects (phpWhoIs/DomainCheckr)

### Why JSON for the original data pipeline (historical — superseded by the DB-direct rewrite, epic #1010)?

- Simplicity — songs.json loaded once, searched in-memory
- Portable — same file used by web, Apple, Android
- ~3,600 songs ≈ ~5 MB JSON (acceptable for PWA cache)
- Fuse.js handles fuzzy search efficiently in-browser
- Phase TWO will move to proper database (iLyrics dB API)

Since 2026-06 every runtime read is live MySQL. `songs.json` is retired entirely (#1617) — there is no tracked corpus file any more, and new song content goes straight into MySQL through the Song Editor's bulk importer (see "MySQL Database Setup").

### Why `appWeb/`, `appApple/`, `appAndroid/` naming?

- Consistent `app<Platform>/` prefix across all platforms
- Clearer separation in the directory tree
- Matches original repo convention

### Catalogue expansion (#1741) — the shared modules you MUST reuse, not re-fork

The MusicBrainz-shaped catalogue rework (Musicians / Works / Tunes / Songs — identifiers,
disambiguation, profile pages, alias URLs; on `claude/wave3-fixes` pre-merge) is built on a small set
of **single-home** modules. The epic's whole point is reuse-don't-duplicate (the modularity rule);
before adding catalogue code, reach for these:

- **Identifier normalise + resolve** — `includes/identifier_normalize.php` (`IHYMNS_ID_SCHEMES`
  registry + `ihymns_canonical_iswc/_ccli/_bowi/_isrc/_isni/_ipi` + `ihymns_normalize_identifier`)
  and `includes/identifier_resolve.php` (`ihymns_resolve_identifier`, table/column-gated, `bind_param`).
  The alias routes `/isrc /iswc /ccli /ipi /isni /bowi` all resolve through these — ONE normaliser,
  ONE resolver, never five near-copies (P3, `dc9b5067`).
- **Recording-ID vocabulary** — `includes/media_identifiers.php` (`RECORDING_EXTERNAL_ID_TYPES` +
  pure validators, no `\mysqli`). The `tblSongExternalIds` key/value store is the comprehensive home
  for recording IDs (MBID / Spotify / Genius / ISRC). The `tblSongs.Isrc` → store **dual-write**
  mirror is `includes/song_external_ids.php::songExternalIdMirrorIsrc()` (SourceRef-keyed ownership —
  manual rows never touched; #1749).
- **Tune find-or-create + metre** — `includes/tune_helpers.php`: `tuneFindOrCreateByName()` is the ONE
  tune lookup funnel; `ihymns_meter_normalize()` folds metre spellings (CM / C.M. / 86.86 → `86.86`).
  In the editor, `manage/editor/api2.php::ed2_songTuneApply()` is the ONE place `tblSongs.TuneName`
  and `TuneId` are written — always together, so a tune edit can never strand the registry link.
  Every `TuneName` write path (whole-song save, bulk import, revision-restore) funnels through it;
  `tests/php/test-tune-lockstep.php` enforces that from the tree (P5c).
- **Typeahead** — `js/modules/place-search.js` is GENERALISED (additive `searchUrl` / `parseResults`
  / `pickMode` / `noun` options, byte-equivalent defaults), not forked, to back both the place pickers
  and the editor tune typeahead. A new typeahead consumer passes options; it does not copy the module.
- **Shared external-links panel** — `includes/partials/external-links-panel.php` for the Work / Tune /
  Musician profile external-links editors (rule #12/#15).

### Musician registry deduplication (#1785) — the shared modules you MUST reuse, not re-fork

Follow-up to #1784 (the invisible-byte reconcile). Before adding anything to a musician merge/dedup
surface, reach for these — never re-fork them per surface (rule #22):

- **The scan** — `includes/musician_duplicates.php`. `musicianDuplicatesFindCandidates()` is PURE (no
  `\mysqli` anywhere in its call graph) — it takes plain `{id, name, disambiguation}` arrays and
  returns id-keyed groups/pairs/stats, so the blocking maths (an exact fold for Bucket A; a combined
  first+last-token metaphone dictionary for Bucket B — the shared key space is what catches a
  comma-reversed "Newton, John" vs "John Newton"; an alias-fold match for Bucket C) is unit-testable
  without a database (`tests/php/test-musician-dup-scan.php`, guard G4). The DB-touching orchestrator
  `musicianFindRegistryDuplicates()` reads the registry + aliases + dismissed pairs, calls the pure
  function, and hydrates only the ids a candidate actually references.
- **The disambiguation payload** — `musicianDisambiguationPayloadBulk()`, same file. ONE bulk hydrator
  (id, slug, type, lifespan, per-role use-counts, link/identifier/alias counts, MBID presence) reused
  verbatim by the review page, the `/manage/musicians` merge-target typeahead + Merge modal preview,
  and `/manage/musicians-bulk-promote`'s match labels — never a second payload builder per surface.
- **The variant classifier** — `musicianNameVariantClass()` / `musicianNameVariantDetail()`
  (`includes/musician_helpers.php`). A pure 6-rung ladder (identical / unicode-normalisation /
  whitespace / case / punctuation / null) that turns a confusing "Eddie James → Eddie James" merge
  prompt into "these differ only by invisible spacing" — every surface that shows two similar-looking
  names renders this SAME badge, never its own wording.
- **The merge core** — `musicianMergeExecute()` (`includes/musician_helpers.php`). The ONE place a
  musician merge happens: re-points all six credit tables (`MUSICIAN_CREDIT_ROLE_TABLES`, also the
  ONE table-list — nine forked five-table copies were re-pointed onto it), migrates chosen links/IPI,
  carries aliases + relations onto the target instead of losing them to the cascade-delete, preserves
  the source name as a target alias, then deletes the source row — owns its own transaction (never
  nest a caller's own transaction around it; a caller that seeds fixtures in a test transaction and
  then calls this function will find the fixtures really committed, not rolled back).
- **The review page** — `/manage/musician-duplicates` (mirrors `/manage/duplicate-songs`, #1215).
  Never re-implement merge/dismiss logic inline here or anywhere else — delegate to the core above and
  to `tblMusicianDuplicatesDismissed` directly (mirrors `tblSongLinkSuggestionsDismissed`'s shape).

### Print pipeline (#1767 remainder) — the shared modules you MUST reuse, not re-fork

Full design: `.claude/print-templates-1767-remainder-plan.md`. Before touching any print/PDF surface:

- **The schema** — `includes/print_template_schema.php` is the ONE block/page-option registry
  (mirrored client-side; agreement CI-guarded by `test-print-block-registry.php`). Add a block *type*
  in both mirrors, never inline.
- **The renderer** — one body renderer serves browser Print, the server PDF (`manage/print-pdf.php` →
  `includes/pdf_renderer.php`, the ONE engine seam — mPDF vendored under `appWeb/private_html/lib/pdf/`
  outside every docroot), the batch set-list PDF, and the admin live preview. Never add a second
  renderer — `test-print-one-renderer.php` bans it.
- **The sanitiser** — `includes/html_sanitizer.php`: every HTML that reaches the renderer (incl.
  uploaded custom layouts, `tblPrintTemplateCustomLayout`) passes its allowlist profiles on save AND
  at render. Never render user HTML unsanitised.
- **The usage writer** — `includes/print_usage.php` is the ONE CCLI print-usage writer; it re-resolves
  the licence server-side (never trust the client's copies claim).

### Gating Model-2 (#1769 P2) — the shared modules you MUST reuse, not re-fork

Full design: `.claude/gating-model-review-1769-plan.md`. `includes/access_context.php` resolves the
viewer ONCE per request; `includes/access_resolver.php` makes every field/media decision;
`includes/licence_registry.php` is the ONE `tblLicenceTypes` reader. `contentGatingApply()` /
`contentGatingMediaAllowed()` (`includes/content_gating.php`) are thin delegates. Entirely dormant
behind `content_gating_enabled='0'` — any change here must be a verified byte-identical no-op in the
off state.

### Publisher cores (#93) — the shared modules you MUST reuse, not re-fork

`includes/publisher_admin.php` (validate / uniqueness / persist / rename-cascade / aliases / merge /
delete) + `includes/publisher_helpers.php` (`IHYMNS_PUBLISHER_KINDS` / `IHYMNS_PUBLISHER_ROLES` VARCHAR
vocabs, slug fold, `publisherFindOrCreateByName()`). `/manage/publishers` and the future
`admin_publisher_*` API both delegate. Full contract in CLAUDE.md rule #37.

### IA reconcile (#94) — the shared modules you MUST reuse, not re-fork

`includes/ia_client.php` — the SSRF-hardened, host-bound archive.org fetcher (same house pattern as
`intapps_client.php` / `cuercode_client.php`) — plus `includes/ia_reconcile.php`, a pure
segmenter/scorer. Read-only for song content; CI-enforced by `tests/php/test-ia-reconcile-guards.php`.

### Public list sort (#1786) & set-list share cores — reuse, not re-fork

- **List sort** — `js/utils/sort-compare.js` (pure comparators, shared with
  `js/modules/admin-table-sort.js`) + `js/modules/list-sort.js` + `includes/partials/list-sort-control.php`.
  Persistence rides the existing `user_settings` namespace `list_sorts` — never a new per-user endpoint.
- **Set-list share** — `includes/SharedSetlist.php` holds the ONE share-id fold
  (`sharedSetlistSafeShareId()`), the scope-aware resolver (`sharedSetlistResolveWire()`), and the write
  core (`sharedSetlistUpdate()`); `setlist_share` / `setlist_token_update` / `_share_list` /
  `_share_revoke` all funnel through these. The edit audience resolves per-write; the mint response is
  the truth, not the request (CLAUDE.md rule #40).

### ILID identity model (#1860) — the shared modules you MUST reuse, not re-fork

`includes/ilyrics_id.php` is the ONE allocator. `IHYMNS_ILID_TYPES` is the ONE prefix → table registry
(song `ILS`, work `ILW`, musician `ILM`, tune `ILT`, publisher `ILP`, catalogue `ILC`, songbook `ILB`,
document/media `ILD`) — every function in the file derives its behaviour from this map; there is no
second hardcoded prefix list anywhere. `ilidAllocate()` mints inside the CALLER's own transaction (no
BEGIN/COMMIT of its own), reading `tblIlyricsIdSequence` with `SELECT … FOR UPDATE` plus a claim-check
against `uq_IlId` so two concurrent creates can never collide. `ilidParse()` gives the tolerant human
form (`'ILS12345'` → canonical `'ILS0000012345'`) and is what every dual-addressing resolver calls.
**Dual-addressing branches on grammar, never on a guess**: a hyphen present means the public
`<letters>-<digits>` SongId form; a hyphen-less token is tried against `ilidParse()` first. The pattern
repeats identically in every resolver that accepts an IL id today (`SongData::getSongById()`,
`includes/pages/{musician,publisher,tune}.php`, `song-media.php`): try/catch-swallowed, gated on an
`INFORMATION_SCHEMA` probe of the entity's `IlId` column, falling through UNCHANGED on any miss (not an
IL id, the column doesn't exist yet, or no row carries it) — so every one of these call sites is a
verified no-op on an un-migrated install (the #1228 lesson). Never re-implement the parse/format fold,
never hardcode a second prefix map, never skip the column-existence gate.

### Report + Editor2 metadata cores (#1861 / #1862) — the shared modules you MUST reuse, not re-fork

- **`includes/ccli_report.php`** — the ONE CCLI-report query core. `ccliReportWindow()` (shared date-range
  parse), `ccliReportSystemRows()` (the system-wide query, with an org/Unattributed narrowing selector),
  `ccliReportOrgRows()` (org-scoped, structurally incapable of an unscoped query — refuses to run without
  a non-empty, membership-derived org-id list). `/manage/ccli-report` and `/manage/my-ccli-report` are
  both thin page consumers.
- **`includes/copyright_display.php`** — `ihymns_copyright_statement()`, the ONE copyright-line
  precedence fold (structured years+holder wins over the legacy free-text `Copyright` column whenever
  either half is non-empty). Shared, via a fixture-driven lockstep test, with the JS twin in
  `manage/editor/v2/metadata-tab.js`'s `ihymnsCopyrightPreview()` — never re-type the fold in JS.
- **`includes/pd_suggest.php`** — the public-domain suggestion fold: a credited contributor's death date
  (life + a fixed 70-year constant) first, falling back to `tblAppSettings.pd_publication_year_threshold`
  (admin-configurable on `/manage/configuration`, default 1900) when no death date is on record. Suggests
  only — never auto-ticks a Public Domain checkbox.
- **`includes/song_media_flags.php`** — `HasAudio`/`HasSheetMusic` auto-maintained from `tblSongMedia`;
  the editor's old manual checkboxes are gone (rule #44 — don't collect what can be derived).

### Medley composition + component labels (#1907, #1860 Phase 5) — the shared modules you MUST reuse, not re-fork

Wired the dormant #1860 work-identity schema and added one column, on commits `417a9160`→`734b6f29`
(branch `claude/ilyrics-identity-work-model`). Full design: `.claude/medley-component-work-1860-phase5-plan.md`;
contract in CLAUDE.md rule #45. Before touching medley/component-metadata code, reach for these:

- **`workMedley*()`** in `includes/work_admin.php` — the ONE medley core
  (`…Ready`/`…Constituents`/`…ConstituentsMap`/`…WouldCycle`[bounded-depth BFS, self-link + cycle guards]/
  `…Attach`[idempotent `ON DUPLICATE KEY UPDATE MedleyWorkId=MedleyWorkId` — keep-existing, NEVER
  overwrites a curator row]/`…Replace`) over `tblWorkComponents(MedleyWorkId, ComponentWorkId, SortOrder)`
  (M:N "contains", deliberately NOT `ParentWorkId` = "is-a-variant-of", rule #14). Both consumers — the
  `/manage/works` "Constituent works (medley)" editor (gate `manage_works`) and the `component_upsert`
  §3.6b.2 additive-only, non-blocking lockstep from `tblSongComponents.SourceWorkId` — delegate to it.
- **The thin-row component metadata** — the NEW `tblSongComponents.Label VARCHAR(100)` (custom section
  name, e.g. "Kyrie"/"isiZulu") and `tblSongComponents.SourceWorkId` (per-section Work provenance) are
  siblings of `Language` (#858), carried on the SAME `component_upsert`/`lyricLinesWriteComponents()`
  funnel — **never the `tblLyricLines` line path** (rule #25 untouched — no line content). `Label` is
  **DISPLAY-ONLY**: `Type` stays authoritative for CSS/chorus-highlight, arrangement resolution, and every
  machine-export keyword, so `format-export.js`/`propresenter-export.js` carry **ZERO `.label`** (a
  free-text label in an exporter breaks re-import). D1 hide-when-equal is server-side in `component_upsert`
  (a label equal to the derived "Type Number" stores NULL, rule #27).
- **The read/write seams** — `includes/lyric_lines_read.php` emits `label` **SPARSELY** in the public
  shape (key present only when set, so the strict-`===` `test-lyric-lines-read.php` contract + 16k-song
  byte-parity hold) and always-present `label`/`sourceWorkId` in the editor shape. The write path is
  **silent-wipe-proof in THREE layers** (handler target-preserve + read-modify-write carry + writer
  provided-flag preserve via `array_key_exists`) because `components_replace` (FIFO carry) and
  `save_song_core` (PF1 carry) each rebuild a fixed shape and would otherwise NULL every label — an
  omitted key means "preserve", explicit `null` means "clear". ONE shared column probe
  (`lyricLinesComponentExtrasPresent()`, rule #35) gates every SELECT so nothing throws under STRICT on
  an un-migrated install. The tree-derived, mutation-proven `tests/test-component-label-sites.js`
  enumerates every deriver render site and asserts each reads `.label` (it already caught a `preview-tab.js`
  gap the typed sweep missed, rule #33).

### ProPresenter 7+ interoperability (epic #1968) — the shared modules you MUST reuse, not re-fork

Import: `includes/propresenter7_decode.php` (a hand-rolled, server-side proto3 wire-walker — not a
browser decoder, since the export side is already forced onto an eval-free static protobuf module by
the enforcing nonce CSP, #1788) + `includes/propresenter7_zip.php` (a tolerant ZIP64 scanner — genuine
PP7 `.probundle` files are ZIP64 with an inconsistent end-of-central-directory that PHP `ZipArchive`
rejects outright, so the reader walks local file headers directly) + `includes/propresenter7_playlist.php`
(`.proplaylist` decode, reusing the same wire-walker). All three funnel into `_bulkImport_processPro7()`
/ `_bulkImport_processProbundle()` / `_bulkImport_processProplaylist()` in `includes/song_importers.php`
— the ONE import pipeline every `.pro`/`.probundle`/`.proplaylist` path uses (also where the dormant
timeline-capture hook and the media-visibility ingest hook attach). Export is
`manage/editor/propresenter-export.js`'s static protobuf encoder; `buildRTF()` never carries a chord
symbol (chords are positioned `custom_attributes[]`, not `[G]` brackets — rule #45's "display-only"
discipline extended to a new axis). **Anti-false-positive rule for this whole epic:** no decoder or
encoder change ships validated only against its own round-trip — every guard cross-checks against an
independently-implemented decoder (protobufjs reflection) and/or a real, third-party ProPresenter file
(`tests/fixtures/propresenter/`, sources + licences recorded in `.claude/propresenter-reference-sources.md`).
Full plan: `.claude/propresenter-interop-1968-plan.md`.

### Organisation-licence core (#1969) — the shared module you MUST reuse, not re-fork

`includes/org_licence_admin.php` — validate / list / upsert / update / delete, plus a
**non-destructive** set-reconcile (never a delete-all-then-reinsert, which had been silently wiping
every licence's number/expiry/notes on every save). The global-admin editor on `/manage/organisations`,
the member self-service editor on `/manage/my-organisations`, and both native-app API actions all
delegate to this one core (rule #22) — a church can hold several licences at once (CCLI for the
lyrics, an MRL for the music, an iHymns plan, …), and the tier resolver (`ccli_validator.php`) honours
each licence's `ExpiresAt`. No schema change — `tblOrganisationLicences` already existed.

### Device auto-naming (#1975) — the one funnel

`apiTokenDeviceMetaStore()` (and its pure helpers `apiTokenBrowserLabelFromUA()` /
`apiTokenWebDeviceFallback()`) is the ONE place a web sign-in derives a friendly device name from the
request `User-Agent` — every web auth path (`auth_login`, `auth_apple`, email-login, registration)
funnels through it, so a new sign-in path never needs its own naming logic. It only fills in
`platform='web'` + a name when the client sent no platform and the UA is a recognised browser; an
explicit platform (a native app) is always left alone. Rename is `device_rename` (own-only, CSRF-gated,
rate-limited) — never a raw `UPDATE tblApiTokens` from a page.

### Per-channel search-engine visibility (#2024/#2025) — the one module you MUST reuse, not re-fork

`includes/search_visibility.php` is the ONE place that answers "should THIS copy of the site (production,
beta, or the alpha/dev channel) show up in search engines right now?" — `searchEngineVisibleHere()` (memoized
per request) and `searchVisibilityEmitNoindexHeader()` (a one-line drop-in for any public endpoint). It reads
one `tblAppSettings` row (`search_visibility_channels`, a CSV of the VISIBLE channels — the same storage shape
as `webhooks_enabled_channels`/`intappsapi_enabled_channels`) via the shared `ihymns_parse_channels_csv()`
(`includes/environment.php`, extracted from `webhooks.php`'s own channel-CSV parser so the two can never drift
apart). The channel is always `ihymns_environment()` — the docroot the code is actually running from — never
the request's `Host:` header, which an attacker can forge. Three consumers key off it: `index.php`/`api.php`/
`og-image.php`/`qr.php`/`org-logo.php`/`song-media.php`/`audio-media.php` all emit `X-Robots-Tag: noindex`
(plus the matching `<meta name="robots">` on the SPA shell) when the current channel is hidden;
`sitemap.xml.php` 404s the whole sitemap (and every paginated child) ahead of the DB/fingerprint work; and the
new `robots.txt.php` (replacing a static `robots.txt` file) drops that channel's `Sitemap:` line. It
deliberately never adds `Disallow: /` — a blocked crawler can never see the `noindex` signal on a page, so
staying crawlable is what makes `noindex` actually work; a CI guard actively bans a bare `Disallow: /` from
ever appearing. Locked defaults (no admin action, no migration needed): production listed, beta and alpha
hidden.

### Dynamic sitemap hardening (#2023) — the shared helpers you MUST reuse, not re-fork

`appWeb/public_html/sitemap.xml.php` (originally #151) is a **sitemap index** at the one `/sitemap.xml` URL;
its children (`static`, `songbooks`, `songs` paginated at 10,000/page, `musicians`, `themes`, `works`,
`publishers`, `tunes`) are served by the same file via `?section=&page=`, routed by an `.htaccess` rule to
`/sitemap-<section>[-<page>].xml`. Every entity's `<lastmod>` comes from that row's own `UpdatedAt` (omitted,
never invented, when unknown) — the old behaviour stamped every URL with "today," every day. The two genuinely
pure helpers (`sitemapPageCount()`, `sitemapLastmod()`) live in the new `includes/sitemap_helpers.php` so a CI
guard can call them directly without executing the parent file's request-handling flow (which ends in `exit;`
on every branch) — the entity-query functions themselves deliberately stay in `sitemap.xml.php`, because two
existing Node tests statically scan that file's own source text for specific literals. Conditional GET
(ETag/Last-Modified/304) runs off cheap per-table `(COUNT, MAX(UpdatedAt))` aggregates, computed once per
request and reused for the index's own per-child `<lastmod>`. Host resolution calls the shared
`appCanonicalHost()` (`includes/config.php`) rather than a second hardcoded host list.

### Languages, translations and language order (#2137) — the shared policy you MUST follow, not re-fork

iHymns follows the shared MWBM language policy, **MWBM-MEDIA-LANG 1.0.0**. The rules themselves are in
[`docs/standards/media-language-bcp47-policy.md`](docs/standards/media-language-bcp47-policy.md) and are
not repeated here; this note says how iHymns applies them and where the code is. That file, the policy's
290 test cases (`tests/fixtures/bcp47-language-policy-v1.json`), its reference data and the shared PHP
implementation (`appWeb/public_html/includes/vendor/media-language/`) are **exact copies** of the master in
MWBMPartners/MeedyaSuite-core, pinned in `docs/standards/MWBM-MEDIA-LANG.lock` (core `aaaa585` since
28 September 2026). CI (`tools/media-lang/check_copies.py` in the `lint` job) fails if a copy is edited
here, and — since that commit — if the three PHP files (`MediaLanguagePolicy.php`, `README.md`,
`tests/run-conformance.php`) are not all listed and laid out as in the master, or the repository tracks
any other `run-conformance.php`. Never edit them: change the master, then run
`python3 tools/media-lang/check_copies.py --update <core commit>`.

- **Where the shared code lives, and why not in `private_html`.** It sits in the web folder's
  `includes/vendor/`, not beside the PDF engine in `appWeb/private_html/lib/`, because the deploy never
  uploads `private_html` (the step needs the `SFTP_PRIVATE_PATH` secret, which is not set — #2138). Without
  these files on the server every language save is refused. `includes/` goes up with every deploy, in each
  channel's own docroot, and `.htaccess` blocks it from the web. `vendor` marks it as code iHymns did not
  write, so the tree-walking source checks skip it. `tests/php/test-media-language-deploy-layout.php`
  copies the folder the way the deploy does and loads the rules from the copy.

- **A language is identified by its BCP 47 tag, never by its name.** `pt-BR` (Brazilian Portuguese) and
  `pt-PT` are two different languages; so are `zh-Hans` and `zh-Hant` (Chinese in Simplified and in
  Traditional characters). Nothing stores or matches a language by name, and nothing cuts a tag down to its
  base language to decide what it *is*. The registry tables (`tblLanguages` and friends) supply **names**
  only.
- **One door into the rules: `includes/media_language.php`.** It loads the shared code once and offers:
  `mediaLanguageTagForStorage()` for a value that is meant to be a tag (typed in an editor, stored by
  iHymns) — tidies letter case (`en-gb` → `en-GB`), replaces retired codes (`iw` → `he`), refuses a name or
  a malformed value, and refuses (never cuts) a tag longer than the 35-character columns;
  `mediaLanguageReadExternal()` for a value read from a file or another system (TTML `xml:lang`, OpenLyrics,
  importers, the lyrics-ingest API, MARC) — also reads old three-letter codes (`eng`), and reports an
  unreadable value instead of guessing; `mediaLanguageSortStored()` / `mediaLanguageSortForReader()` for the
  two orders below; `mediaLanguageOrUnknown()` for "no language given → `und`" (every song save calls it);
  `mediaLanguageIsOrdinaryLanguage()` for "is this a real language, not `und`/`mul`/`zxx`/`mis`/`qaa`–`qtz`/
  private-use/malformed?". Do not write a regular expression for a language tag anywhere else. What the build
  actually checks is narrower than that rule: `tests/php/test-media-language-ihymns.php` scans every PHP file
  under `appWeb/public_html` for the one pattern shape the five retired checkers used (`[a-z]{2,3}(-…`); a
  differently written pattern would not be caught, so reviewers still have to look. Three deliberate
  exceptions exist, each because the shared PHP cannot run there: the SQL `REGEXP` patterns in
  `includes/language_filter.php` (script matching inside the database), its degraded-path readers (used
  only when the shared code is missing), and `scriptOf()` / `isPreferenceTag()` in
  `js/utils/language-tags.js` (the browser).
- **Unknown is `und`, never English.** A song whose language nobody gave is stored as `und` ("not known").
  Nothing fills in `en`, the page's language, or any other default (#2132) — including a brand-new song from
  the v2 editor, whose INSERT writes `und` explicitly (`includes/song_create.php`) so the column default does
  not matter. Songs already stored as `en` by the old default were deliberately left alone: they cannot be
  told apart from real English songs. The language filter always shows `und`, `mul` (several languages) and
  `zxx` (no language) songs, like untagged ones. The "no English" guard in
  `tests/php/test-media-language-ihymns.php` scans the site's PHP and JS for a language value falling back to
  `'en'` in these shapes, each near a `language`/`lang` word: `?? 'en'`, `|| 'en'`, `?: 'en'`, a ternary's
  `: 'en'` (else) and `? 'en' :` (then), `'language' => 'en'` (any letter case), `$language = 'en'` (also
  `??=`), `$x['language']`/`['lang']`/`['Language'] = 'en'`, `$x->language = 'en'`, and `x.language = 'en'`
  (with or without spaces); since round 4 of the review also a JavaScript object key (`language: 'en'`,
  `{'language': 'en'}`, `"language": "en"`), a declaration (`var`/`let`/`const language = 'en'`, any name containing
  `language` or `lang`), and a PHP `match` giving `'en'` to a language variable or key — on one line, or spread over
  several (each PHP file is also read whole for that one shape). Since round 5: "English" is any quoted `en` tag, so
  a regional fallback (`|| 'en-GB'`, `?? 'en-US'`) is caught in each shape listed here; an assignment with no spaces
  where a statement or an argument starts
  (`language='en'`, `if(!lang)lang='en'`, `{ id, language = 'en' }`, `function f(lang = 'en')`); a logical
  assignment (`lang||='en'`, `language??='en'`); and a setter call (`setLanguage('en')`, `setSongLang('en-GB')`).
  Round 5 also said "a language name" is any name CONTAINING `language` or `lang` "in every shape"; **that was not
  true** (the fifth review, finding 4): the `??`/`||`/`?:` pattern and both ternary patterns still needed the word to
  END there, so `song.languageCode || 'en'`, `$row['langCode'] ?? 'en'`, `$row['language_code'] ?? 'en'` and
  `$song->languageCode ?: 'en'` got through. **Since round 6** every pattern takes any name containing `language`
  or `lang` (`languageCode: 'en'`, `targetLang: 'en'`, `'languageCode' => 'en'`, `$x['languageCode'] = 'en'`,
  JSON-LD's `inLanguage: 'en'`, and the three shapes just named); an assignment after `else` is caught
  (`else lang='en';`); a comment opened and closed on the line no longer hides the code after it
  (`lang = /* default */ x.language || 'en'`, `x.language || /* fallback */ 'en'` — the scan used to cut the line
  at the comment); and "English" also means `'en_GB'` (an underscore), `'eng'`, the name `'English'`, and a list
  holding it (`['en']`, `[ 'en-GB', 'fr' ]`) — except inside a `match`, where an arm giving `'English'` or `'eng'`
  is normally a lookup (`'en' => 'English'`), so the two `match` patterns keep to the tag. Each new shape was proven
  by planting it in the real tree (the guard goes red) and by removing each new pattern piece (its own checks go
  red); the whole tree still scans clean. Because `'English'` now counts, a language NAME written as data under a
  language key (`['code' => 'en', 'language' => 'English']`) would be flagged; none exists today, and one that
  appears needs a decision, not a quiet exception. **Since round 7 (the sixth review's decision 5):** English inside
  brackets or a list, wherever it stands in the list (`|| ('en')`, `?? ('en')`, PHP's `array('en')`,
  `['fr', 'en']`, `array('fr', 'en-GB')`); an assignment after a `case …:` or `default:` label (`default:lang='en'`,
  `case '':lang='en'`); and a JavaScript private field (`#lang = 'en'`). Only a REAL comment line is skipped now: a
  line starting `#` used to be skipped in every file, but in PHP `#[` starts an attribute, which is code
  (`#[Pure] public function f(string $lang = 'en')`), and in JavaScript `#` never starts a comment — so a `#` line
  is skipped only in PHP and only when it is not `#[`. Each shape was proven with the sixth review's own list
  (its `enguard.php`, re-pointed at the moved code: every shape the decision names is now caught) and by planting
  it in the real tree; the whole tree still scans clean. **Since round 8 (the seventh review's L4, the lead's
  decision 5):** an item before English in a list need not be quoted text (`?? [$primary, 'en']`, `[null, 'en']`,
  `|| [base, 'en']`, `|| [...base, 'en']`, `[song.lang, 'en']`, `[$x->primary, 'en-GB']`); English as a KEYED value
  (`?? ['default' => 'en']`, `array(0 => 'en')`, `['fr', 'first' => 'en', 'de']`); and a constant for a language
  (`define('DEFAULT_LANGUAGE', 'en')`). And round 7's list rule raised false alarms, now gone: a list that is
  looked in or picked from (`['fr', 'en'].includes(lang)`, `['en', 'en-GB'].indexOf(lang)`, `['fr', 'en'][0]`,
  `(['fr', 'en']).includes(x)`) and English that is compared (`|| ('en' === song.lang && x)`,
  `lang || 'en' === other`) are not fallbacks: English in a list counts only when what follows the list's closing
  bracket is not `.`, `[`, `->`, `::`, a comparison or `&&` (the rest of the list is read in one go, so the check
  cannot be dodged by stopping at an inner bracket), and bare English counts only when it is not compared. The scan
  of a FILE is now a function the test hands a JavaScript and a PHP file of its own: the seventh review's planted
  fault read every file as PHP (the "which language" argument dropped) and no check noticed, because they all
  called the line reader directly; and the whole-file `match` scan reads a `#[` attribute line as code, like the
  line scan (it used to blank every line starting `#`). The widened guard found one line in the tree:
  `manage/songbooks.php`'s fallback list of dropdown CHOICES when `tblLanguages` cannot be read
  (`[['Code' => 'en', 'Name' => 'English', …]]`) — a list of options, not a language given to a song, and nothing on
  the page reads that variable any more; it is a deliberate exception (whole line), not a change. Proven with the
  seventh review's `enguard-rv7.php` (every shape the decision names now answers as it should; round 7's own probe
  gives round 7's answers) and nine planted faults, each red on both servers (the file scan reading every file as
  PHP, its language decided wrongly, the `match` scan blanking `#[` again, list items quoted-only again, no key
  before English, no check after a list, bare English counted when compared, the rest of a list read without the
  atomic group, the `define()` pattern removed); the whole tree still scans clean. **Round 9 (the eighth review's L5
  and L6 and carry-over 2, the lead's decision 5):** round 8 let ANY member through after a list's closing bracket,
  so a fallback list kept and then changed got through — `(x || ['en']).map(f)`, `.join(',')`, `.slice()`,
  `['en'].concat(more)`, `(… ?? ['en'])->toArray()`; now only members that look in the list are let through
  (`.includes(`, `.indexOf(`, `.lastIndexOf(`, `.some(`, `.every(`, `.has(`, `.length`, `->contains(`, `->has(`),
  besides an index, a comparison and `&&` (`x || ['en'] && y` is `x || (['en'] && y)` — `&&` binds tighter than
  `||` and `??`, so the list is never the value; checked in PHP 8.5 and Node). A map from a language code to a
  language NAME is no longer flagged (`['en' => 'English', …]`, `?? [$code => 'English']`,
  `define('LANGUAGE_NAMES', ['en' => 'English', …])`): under a key that is a quoted language code or a variable, a
  value counts only when it is itself a code (`'en'`, `'en-GB'`, `'eng'`), never the name; under any other key
  (`'default' => 'en'`, `0 => 'en'`) any spelling still counts. And a lone English NAME used only as a display label
  is no longer flagged (`$label = $lang ? $names[$lang] : ('English');`, the line the seventh review called a false
  alarm): in a statement that assigns to something named as display text (label, name, title, text, caption,
  heading or display, and not lang/language), a lone 'English' — not an item of a list — is blanked before the
  patterns run. Still flagged: a code (`$label = … ?? 'en'`), a list (`?? ['English']`), and the name assigned to a
  language or to anything else (`$language = … : ('English')`, `$x = $row['language'] ?? 'English'`). Proven with
  the eighth review's `enguard-rv8.php` (every shape the decision names now answers as it should) and six planted
  faults on the guard (round 8's look-in rule back, round 8's keyed rule back, the label rule removed, the label rule
  blanking whatever is assigned to — all red; a real fallback in the label's shape planted in the tree — red; the
  label line itself planted in the tree — green); the eighth review's G1 and G2 stay red; the whole tree still scans
  clean, with `manage/songbooks.php`'s list of choices still a deliberate exception (it holds the code `'en'` under
  the key `'Code'`; removing that unused list is a separate issue).
  **What it still cannot see (its blind spots), so reviewers still look:** English passed through a call or a cast
  (`?? strtolower('EN')`, `?? mediaLanguageTagForStorage('en')`, `|| String('en')`, `?? (string) 'en'`,
  `?? \trim('en')`, `?? collect(['en'])->all()` — round 8, listed by the lead); a value under a variable or
  language-code key that is the English NAME (`[$code => 'English']` — read as a code-to-name map, round 9); a lone
  'English' assigned to something named as display text (read as a label, round 9) — so `$title = $song['language']
  ?? 'English'` is not flagged; a list with more than eight items before English, at any one
  level (`['a', …, 'i', 'en']`); an item before English that is itself a call, an index or an expression
  (`[f(x), 'en']`, `[$row['x'], 'en']`); English picked out of a list by its index (`['en', 'fr'][0]` is
  English, but every index on a list is let through); a destructuring default under a new name
  (`const {lang: songLang = 'en'} = song`); a PHP array key that is exactly
  `'lang' => 'en'` (left out on purpose — see below); a template literal
  (`` language: `en` ``); a value built by joining strings (`'e' + 'n'`); a fallback through a variable or constant
  (`$raw : DEFAULT_LANG`, `const EN = 'en'; … || EN`); a fallback spread over two lines in any shape except the PHP
  `match`; a `match` or ternary whose result is returned or passed on rather than assigned to a language name;
  any call other than a `set…Language(…)` setter (`Object.assign(song, { lang: x })` with `x` holding `'en'`,
  `$map->set('language', 'en')`, `new Map([['language', 'en']])`); a bare `lang: 'en'`-style assignment in the
  middle of a longer expression (`x = (lang='en')` is caught only because of the bracket before it; `a && lang='en'`
  is not); a name that does not contain `language` or `lang` (`$code = 'en'` then stored as the language); English
  spelled any other way (`'en-gb'` is caught, `'English (UK)'`, `'anglais'` or `"\x65n"` are not); code on the same
  line after the END of a comment that spans lines (` */ lang = 'en';` — only a comment opened and closed on ONE line
  is removed, and a line starting with `*` is skipped whole; the opposite case, a line inside a longer comment that
  does not start with `*`, is read as code, which can only flag too much, never too little);
  a comment marker inside a string earlier on the line (`$s = 'a /* b'; $lang = $x ?? 'en'; // */` and
  `$t = ' //'; $lang = $x ?? 'en';` — the scan removes comments by pattern, not by reading strings, so it cuts the
  line there; found by the sixth review, still open); an English tag with a subtag longer than eight characters
  (`'en-123456789'`, not a valid tag anyway); Python's `or` (`language = x.language or 'en'`, not a PHP or
  JavaScript fallback); a trailing `#` comment in PHP is read as code (which can only flag too much);
  and anything outside the site's own PHP and JS (vendor folders, JSON files, the Apple and Android apps, `tools/`).
  Deliberate exceptions are listed in the test by file and the WHOLE line (trimmed) — since round 5 the whole line
  must be equal, because "contains the listed text" also excused a real fallback appended to that line (the fourth
  review's planted fault) — and each must still be found: today only `js/modules/print.js`'s sample song for the
  print editor's preview ("Amazing Grace", really English). (A PHP array key that is exactly `'lang' => 'en'` is
  deliberately not flagged: the geocoder in `manage/places-api.php` asks for place names in English that way,
  which is not a song's language. `'langCode' => 'en'` and every longer name are flagged.)
- **The songbook-language card is a curator's decision** ("Fill in a missing song language from its
  songbook", manual, never part of "Apply all"): it gives a songbook's language ONLY to songs that have no
  language at all, and never changes a song that has any value — a real tag, `und`/`mul`/`zxx`, or a
  malformed value. (An earlier version also rewrote songs in a different language — `yue` in a `zh` book
  became `zh` — with no record; that was removed.) Songs whose language differs from their songbook's are
  listed for review by hand. The dry run lists every fill; a confirmed run records each one in the activity
  log (`migration.song_language_backfill`, old → new), so it can be traced and undone. Its "dry run unless
  confirmed" decision is `migrateBackfillSongLanguageConfirmed()`. Since round 4 of the review: "no language"
  means NULL or only spaces, tabs and line breaks (`mediaLanguageIsBlank()` in `includes/media_language.php`) —
  a no-break or zero-width space is a value, listed, never filled; the card's "pending" check decides with that
  same function (it used to ask the database, whose collation disagreed both ways, so a tab-only song was never
  reported and a no-break-space song kept the card "pending" for ever); each log row records WHO ran it — from
  the web the signed-in user (`UserId`) and `ranBy: "web"`, from the command line `ranBy: "command line"` — and a
  confirmed web run that cannot tell who is signed in changes nothing (`migrateBackfillSongLanguageActor()`); and
  every fill and its log row are one transaction. **If somebody changes a song's language while a confirmed run is
  under way, their change stands** — any change to its bytes, since round 5 of the review. How depends on the
  database: on MySQL (and MariaDB without snapshot isolation) that one song is skipped and the rest filled; on
  MariaDB 11.8, where `innodb_snapshot_isolation` is on by default, the whole run stops with "Record has changed
  since last read" and is rolled back — nothing filled, nothing logged — and can simply be run again. (An earlier
  note said only "that song is skipped".) Until round 5 this note said "their change always stands", and that was
  not true: the check compared the value the card had read by the column's collation, which counts an empty
  value, a space, a no-break space and a zero-width space as the same, so on MySQL 8.4 (and MariaDB with snapshot
  isolation off) a change from empty to one of those spaces, or from a space to empty, was overwritten with the
  songbook's language. The check now compares bytes
  (`CAST(Language AS BINARY) <=> CAST(? AS BINARY)`). What it still cannot see: a change that ends with exactly
  the bytes the card read (somebody changing the value and changing it back) — there is then nothing of theirs to
  lose. A confirmed run also refuses, changing nothing, when the activity log table or its `Details` column is
  missing. All of this is tested against a real database in `tests/php/test-song-language-backfill.php` Part C, on
  both (each concurrent case in both snapshot-isolation modes on MariaDB).
- **Two orders, on purpose, never mixed.**
  - *Stored order* (the policy's Part A) is the same for everyone and is what the site returns and keeps:
    the original's language first, then every other language by its code (`de`, `en`, `es` …), general
    before specific (`zh`, `zh-Hans`, `zh-TW`, `zh-Hant-TW`), countries before multi-country areas (`es-MX`
    before `es-419`). Whole-song translations (`SongData::getSongTranslations()`, `_getTranslations()`) and
    the translations under each lyric line use it. SQL cannot produce it (`ORDER BY` sorts letters), so it
    is done in PHP.
  - *Reader order* (Part B) is what a person sees in a menu or list and differs per person: their own
    languages first, in the order they chose; then the original; then everything else alphabetically by
    name; special codes last. It must never be written back into stored data, and nothing moves because it
    was just selected. The song page's translation picker is a shared cached fragment, so the server sends
    it in no-preference order and `js/modules/song-translations.js` puts the reader's languages first in the
    browser, once, on load. The `song_translations` API action and the language filter lists order on the
    server or in the browser for the person asking.
- **The original comes first.** In a whole-song translation cluster the source song is the original
  (`is_original` / `isOriginal`); the song page marks it "(original)".
- **Names.** `resolveLanguageName()` composes a full English name from the tag's parts — "Portuguese
  (Brazil)", "Chinese (Traditional)", "Spanish (Latin America)" — using CLDR English names loaded into the
  registry tables. It used to fall back to the base language ("Portuguese"), which gave different
  translations the same name. The language's own name ("português") is a second label, never the only one.
  English is the only interface language today (#744 covers others).
- **Text direction comes from the script when the tag names one** (`IHYMNS_RTL_SCRIPTS` in
  `includes/language_names.php`): `pa-Arab` runs right to left, `ar-Latn` (romanised Arabic) left to right.
  Otherwise the base language's registry direction applies.
- **Transliterations should carry their script** (`ja-Latn` for romanised Japanese, `sr-Latn`): without it a
  transliteration cannot be told apart from the original text. This is a SHOULD in the policy, so it is not
  enforced.
- **Preferences** (`tblUsers.PreferredLanguagesJson`, the `X-Preferred-Languages` header, the browser's
  saved list, Song of the Day's `?lang=`) keep full tags in the person's order. Filtering matches by
  language, so a `pt-BR` preference shows `pt` and `pt-PT` songs — except that a preference naming a script
  hides content in another script (`zh-Hans` hides `zh-Hant`, `sr-Latn` hides `sr-Cyrl`; policy MATCH-040).
  The same rule runs in SQL (`applyLanguageFilterSql()`), in PHP (`makeLanguageFilterPredicate()`, via the
  shared `Policy::matchTags()`) and in the browser (`preferenceMatchesTag()`);
  `tests/php/test-language-filter-scripts.php` checks the SQL and PHP agree row for row on a real database
  (MariaDB and MySQL 8.4), including 59 stored values not in standard form and 47 that the column's collation used to bend. For that, the SQL also matches
  the old spellings the shared data file maps to the preferred language (`iw` for `he`, `in` for `id`,
  `zh-yue` for `yue`, `i-klingon` for `tlh`, `sgn-BR` for `bzs`), never treats a spelling that only starts
  like a language as that language (`zh-yue` is Cantonese, not `zh`), treats `und`/`mul`/`mis`/`zxx` as
  preferences that match only themselves (`languageFilterAliasIndex()`), and — since round 4 of the review —
  reads a stored value exactly as the shared rule does: it ignores ASCII letter case and the rule's four trim
  characters (space, tab, carriage return, line feed) at either end, and nothing else. The column's collation
  used to bend more than that: it ignores accents, letter width and some characters entirely, so `én`, full-width
  `ｅｎ`, `ünd`, and `en` followed by a no-break or zero-width space all matched `en` or `und`, a value holding only
  such a space counted as untagged, and MySQL's `TRIM()` left tabs and line breaks on. The SQL now compares BYTES
  (`CAST … AS BINARY` — a binary *collation* is not enough, because `utf8mb4_bin` still ignores trailing spaces),
  turns any non-ASCII character into `?` first (`LOWER()` maps `İ` to `i` on both servers, which would make `İW`
  the Hebrew alias `iw`), and trims by turning tab, CR and LF into spaces and then using `TRIM()` — the same result
  under byte comparisons, and it works on MySQL 5.7, which has no `REGEXP_REPLACE`. Because re-trimming for every
  comparison made the filter 2–5 times slower, a value that is "clean" (only ASCII letters, digits and hyphens —
  almost every stored value) is compared on a fast path that computes exactly the same bytes; only the rest take
  the exact path. (That "exactly" does not hold on MySQL 5.7, corrected in round 5: 5.7's regular expressions stop
  reading at a NUL byte, so a value with a NUL in it can be judged clean and take the fast path, whose bytes then
  differ from the exact path's after the NUL. The results still agree — everything before the NUL is the same on
  both paths, and no comparison can match a NUL — and the review's harness confirmed it on 5.7.44: 99 such values,
  the same answer on both paths for all 28 preference lists, and no difference from the in-memory filter outside
  the documented class below.) The in-memory
  filter trims the same four characters (it used PHP's bare `trim()`, which also strips NUL and a vertical tab).
  **What still differs, stated plainly:** a MALFORMED stored value that begins the way a matching value begins is
  matched in SQL, where the shared rule matches nothing — SQL looks at the start of a value and does not check the
  whole of it is a well-formed tag. For `en`: `en-`, `en--GB`, `en-toolongsubtag`, `en-é`; for `yue`: `zh-yue-` and
  `zh-yue-%` (they start like the old form `zh-yue` followed by more parts); for `zh`/`zh-Hans`: `zh-hant` with a
  line break inside. `tests/php/test-language-filter-scripts.php` pins exactly this list, so any other difference
  turns it red. And the alias lists need the shared rules installed. **Speed** (MariaDB 11.8 and MySQL 8.4 in
  throwaway containers on a development Mac, median of 5): on 300,000 rows a filter of 1–5 preferences takes about
  230–430 ms, within about 30% of the code before round 4 (180–450 ms on the same machine). On the fourth review's
  20,000-row tables (median of 5, MariaDB 11.8 and MySQL 8.4 — corrected in round 5, because an earlier line here
  said "8 preferences take about 40 ms before and after", which is true only for clean values): with clean values,
  8 preferences take 37–46 ms (42–53 ms before round 4) and 32 take 95–140 ms (116–198 ms before); when EVERY value
  needs trimming (a tab at the end — the exact path's worst case), 8 take 117–130 ms (44–50 ms before) and 32 take
  341–394 ms (161–202 ms before). **At most 32 preferences are used** — the
  first 32, in order (`IHYMNS_LANGUAGE_FILTER_MAX_PREFERENCES`, applied in `parsePreferredLanguageSubtags()` and
  `languageFilterUsablePreferences()`): a 2,000-entry header used to build a query of up to 270 KB taking 3.6–21
  seconds (median, depending on the data, whether the preferences name a script, and the server), and now costs
  what 32 do (0.08–0.43 s, the same measurements). The limit also applies to the saved account setting — saving a longer list
  saves its first 32, and the save's answer shows what was kept. **Since round 5 the browser keeps to the same 32**
  (the lead's decision): the settings page's picker (`js/modules/settings-language-filter.js`) and the home/songbooks
  grid's dropdown (`js/modules/songbook-language-filter.js`) refuse a 33rd tick — the box does not tick and they say
  "You can choose up to 32 languages. Untick one to add another."; after an account save the settings page stores
  and ticks exactly what the server's answer says was kept (tidied, never longer than 32), and says so when it kept
  fewer, using only the answer to the newest save; and every browser reader of the saved list — both pickers, the
  grids' filtering and the `X-Preferred-Languages` header — uses its first 32 (`usablePreferenceList()` in
  `js/utils/language-tags.js`, whose `MAX_PREFERENCES` a test checks against the server's constant). So what is
  ticked, what the grids show and what the server-filtered lists show agree. A list saved before the limit keeps its
  extra entries in the browser's storage until the person next changes it; they are never used. The grid's dropdown
  was not named in the decision; it got the same rule because otherwise a 33rd tick there would look on and do
  nothing. `tests/test-language-preference-cap.js` runs the real pickers in jsdom.
  A malformed preference matches nothing — not even the identical malformed value — and a list holding only
  malformed preferences counts as none, so nothing is filtered (policy MATCH-010 / AUTO-010, core
  `aaaa585`); the server judges that with the shared rules, the browser can only rule out values not
  shaped like a tag. Old lists of bare codes stay valid.
- **Whole-song translations accept regional and script tags** once the "Translations: allow regional and
  script languages" migration card has been run (#2131). Until then the song editor skips such a link with a
  warning pointing at the card (`includes/song_translations_schema.php` asks the live database). **A link the
  save cannot write never costs the stored link it may belong with (#2137 review rounds 3 and 4):** when a sent
  link cannot be written — its language is not a code (`English`), the server cannot store or link it yet (no
  #2131 card run), its target song has gone, it points at the song itself, two links were sent for one language,
  or it has no language or no song — the save protects, BY ROW ID, the union of every stored row pointing at the
  same song AND the stored row filed under the same language. Both halves are needed: the song half keeps `pt → T1`
  when a curator's change to `pt-BR → T1` cannot be stored; the language half keeps `English → T1` re-pointed to
  `English → T2`, and `iw → T1` re-pointed to T2 on a server whose registry has `iw` but not `he` (round 3 had
  swapped one half for the other and lost these). A protected row is never deleted — including when two stored
  links clash (`mo → T3`, `ro → T4`: a failed `ro-MD → T3` keeps Ion's `mo` row even though the curator kept `ro`)
  — and never changed: a change that would touch one is itself not made, with a warning, and protects the rows
  sharing ITS song or language in turn (`songTranslationsProtectedIds()`, `songTranslationsPlanSync()`'s
  `blocked`, the repeat in `songTranslationsSaveLinks()`). **What this cannot do:** a failed link is tied back only
  by its song or its language, so if a curator changes BOTH (`pt → T1` becomes `pt-BR → T2`) and that cannot be
  written, the save treats `pt → T1` as removed — it cannot tell that apart from deleting one link and adding
  another. In particular, "a vanished target does not delete the stored link" holds only when the link's
  language is unchanged: a song that no longer exists cannot be pointed at by any stored row, so only the
  language half can match. **Round 5 (the fourth review) closed four more ways to lose a link:** (1) a stored
  language is compared after the SAME trim the save gives what the editor sends (`IHYMNS_TRANSLATION_LINK_TRIM`:
  space, tab, CR, LF, NUL, vertical tab), so `"English "` or `"English\t"` re-pointed to another song is protected
  like `English` — it used to be deleted; (2) the link writes are **all or nothing**: the song save calls
  `songTranslationsSaveLinksAllOrNothing()`, which sets a savepoint, and if any write fails part-way (a stored junk
  row such as `"pt-BR"` + a no-break space that the database counts as the same language as `pt-BR`) runs
  `ROLLBACK TO SAVEPOINT`, so the links are exactly as before, the curator is told "The translation links were
  left unchanged because …", and the rest of the song is still saved — it used to commit whatever had been
  written, losing a link deleted before the failing write (a deadlock, a lock wait timeout, MariaDB's 1020 since
  round 6, and a failed undo still stop the whole save — see "MariaDB's 1020" below); (3) a payload entry that is not a link (not an object, a list such as `["T1", "pt"]`, a song or language
  that is not text, and — since round 6, the fifth review's finding 2 — a non-empty object with neither a `songId`
  nor a `language` key, such as `{"song":"T2","lang":"de"}` or `{"SongId":"T2","Language":"de"}`) makes the whole
  translation save refuse, changing nothing — it used to be skipped and the stored links deleted as if removed
  (an object with those keys but both empty or null still names nothing and is skipped); (4) a successful change of language on the SAME song (`pt → T1`, translator
  Ana, verified, becomes `pt-BR → T1`) now updates the row in place, as `iw → he` already did — it used to delete
  and insert a bare row. Only an unambiguous pair is updated: one stored row whose language nobody sent back and
  one sent link with a new language, for the same song, and the row not protected; two stored rows or two new
  links for one song are still read as "removed one, added one". **What the row keeps (corrected in round 6, the
  lead's decision on the fifth review's finding 3):** the Translator always — it is the same song, so the same
  translation; the Verified flag only when the primary language is the same under the shared rule
  (`pt` → `pt-BR`, `iw` → `he`, `zh` → `zh-Hant` — `mediaLanguageGroup()`); otherwise (`fr` → `de` on T1) the
  Translator stays and the link is no longer verified. (Round 6 said "the Verified flag and its date"; since round
  7 the date stays whenever the row stays with its own song — see "The date" below.) Round 5 kept everything for any language change and called
  it "more precise", which `fr` → `de` is not. **Round 6, the fifth review's finding 1 — a link's details never move
  to a different song.** A stored row's Translator, Verified flag and date belong to the song it links to. When a
  save re-points a language to a different song: if that song has its own stored row that no sent link matches,
  that row is relabelled to the sent language and keeps its own details (its Translator and, since round 7, its
  date always; its Verified flag by the rule just above, since a relabel is a language change of that song), and
  the displaced row for
  the old song is deleted; otherwise the re-pointed row is updated in place with no Translator, not verified.
  The review's case: stored `pt → T1` (Ana, verified) and `pt-BR → T2` (Zed, verified), sent `pt-BR → T1`, used
  to become `pt-BR → T1` credited to Zed and verified; it is now `pt-BR → T1`, Ana, verified, and the T2 row is
  gone. A protected row is never relabelled (the re-point then keeps no details), and a song with two claims on
  its row (a re-point and a new link, or two re-points) is ambiguous, so nobody takes it. Two links that swap
  songs both keep no details — each row's language was sent back, so neither song has a row "no sent link
  matches". **Round 7 (the sixth review's decisions 2 and 3).** Two stored spellings of one language and a
  re-point onto the other spelling's song: stored `iw → T1` (Ana, verified) and `he → T2` (Zed, verified), sent
  `he → T1`. The `iw` row is T1's own row, so it is relabelled `he` and keeps Ana and the verified flag (one primary
  language), and the T2 row goes; it used to be deleted first, leaving `he → T1` with no translator and not
  verified. The relabelled row takes the spelling the curator kept, as the clash rule always has (stored `he → T1`
  and `iw → T2`, sent `iw → T1`, gives `iw → T1`, Ana, verified; the next save tidies it to `he`), and, as with any
  re-point, only when nothing else claims T1 and T1 has exactly one stored row nobody sent back — or, **since
  round 8** (the seventh review's L3, the lead's decision), exactly one such row in the re-point's primary
  language. Stored `iw → T1` (Ana, verified), `he → T2` (Zed, verified) and `de → T1` (Bob), sent `he → T1`: the
  `iw` row is still T1's own row in that language, so it becomes `he → T1`, Ana, verified, its date; Bob's
  `de → T1`, which the curator did not send back, is removed like any other removed link, and so is the T2 row.
  Round 7 read T1's second row as ambiguous and the re-point reached T1 with no translator and not verified
  (reproduced on both servers). The same holds for a single-row re-point (`de → T2`, with `de-AT → T1` and
  `fr → T1` stored, sent `de → T1`: the `de-AT` row takes it). Two of T1's rows in that primary language (`iw` and
  `he-IL`) are still ambiguous, and a protected one is still never relabelled. This is for a re-point only: a NEW
  language for a song with two unsent rows (stored `pt → T1` and `es → T1`, sent `pt-BR → T1`) is still read as
  "removed two, added one", as round 5 decided — the lead's decision named the re-point case, so that one was
  left as it was. **That stays ambiguous on purpose (round 9, the lead's carry-over 1): do not "fix" it by analogy
  with the re-point rule above** — stored `pt → T1` and `es → T1`, sent `pt-BR → T1`, keeps no translator and no
  verified flag, as round 5 pinned it. Tested for
  `iw`/`he`, `in`/`id`, `ji`/`yi` and `mo`/`ro`, both ways round, on both servers. Stored song ids are now compared
  after the same trim as stored languages (`songTranslationsSongKey()`): a stored `'T1 '`, which the database
  accepts as a link to T1 because its collation ignores trailing spaces, re-saved unchanged keeps its translator
  and verified flag (it used to read as a re-point and lose both); the stored value is not rewritten. **The date (changed in round 7, the lead's decision):** the table has no separate date for when a
  link was verified; its only date is `CreatedAt`, when the row was written, which the round-5 notes called "the
  date". It is set to the time of the save only when a row comes to link to a DIFFERENT song — it is then a new
  link in all but its row id (the planner's `clear`). Whenever a row stays with its own song — a same-song language
  change, or the target song's own row relabelled by a re-point — its date is left exactly as it was, whatever
  happens to the Verified flag (`keep` and `unverify` leave it; `songTranslationsDetailsAfterChange()`). Round 6
  made the date follow the flag (reset whenever the flag was cleared), which treated a changed language label as
  a new link.
  Proven against a real database on MariaDB 11 and MySQL 8.4
  (`tests/php/test-song-translations-sync.php` Parts B and C — C runs in its own PHP process against a database
  built the way the table looked BEFORE the card, because `songTranslationsLanguageFkPresent()`'s answer is cached
  for the life of a process). Every one of the fourth review's seventeen planted translation faults turns it red on
  both servers, including the two it did not catch before round 5 (a failed link keyed by its raw spelling, and a
  sent clash protecting only its first link).
- **MariaDB's 1020 ends the whole transaction (#2137 review round 6, the fifth review's finding 6).** With
  `innodb_snapshot_isolation` ON — the default on MariaDB 11.8 — a transaction that read a row, and then tries to
  change it after another session committed a change to it, fails with 1020 ("Record has changed since last
  read"), and MariaDB has then rolled back the WHOLE transaction: checked on MariaDB 11.8.9, `@@in_transaction` is
  0 afterwards, the transaction's own earlier write is gone, and `ROLLBACK TO SAVEPOINT` answers "SAVEPOINT … does
  not exist". MySQL 8.4 does not raise it (its writes read the latest row). Until round 6, 1020 was not on the
  shared list (`songRelocateIsTransactionFatal()`, then in `includes/song_relocate.php` — since round 7 in
  `includes/transaction_fatal.php` — which had 1213 and 1205), so
  the translation links' all-or-nothing wrapper tried its undo, the undo failed, and that "SAVEPOINT … does not
  exist" replaced the 1020 — the error that actually broke the save was never logged (the save did still stop).
  Now 1020 is on the list; the wrapper re-throws it as itself, and when an ordinary write fails it logs the
  ORIGINAL error before trying the undo and, if the undo fails too, throws an error naming both with the original
  as its cause. (Corrected in the same round, finding 8: after a lock wait timeout, 1205, the server's default —
  `innodb_rollback_on_timeout` OFF — rolls back only the failed STATEMENT, not the transaction; the wrapper's
  comment said otherwise. The whole save still stops, because the wrapper re-throws and the caller's outer
  handler rolls the transaction back.) `tests/php/test-song-translations-sync.php` reproduces the 1020 on MariaDB
  (and checks that MySQL simply saves); `tests/php/test-transaction-fatal.php` checks the list. **The song save's
  all-or-nothing call is guarded two ways (#2137 review round 7, the sixth review's decision 4).** Part A7 of
  `test-song-translations-sync.php` reads `save_song_core.php` with PHP's tokenizer: no `try` of its own around
  `songTranslationsSaveLinksAllOrNothing()`, and the transaction's catch rolls back as its FIRST statement (on its
  own, in its own try, or inside an `if` that only checks the connection exists) — so it rolls back on every path,
  not merely somewhere. But a helper function that swallows errors somewhere else cannot be seen by reading one
  file (the review planted `rv6BestEffort()`, and the tokenizer check stayed green). So
  `tests/php/test-song-save-whole-rollback.php` runs the REAL save, `editorSaveSongCore()`, against a database built
  from schema.sql: a 1020 during the link writes (B2; a real one on MariaDB, a stand-in on MySQL) and a failed undo
  (B3) must each answer 500, change no table but the activity log, and leave no transaction open on the save's
  connection. With `rv6BestEffort()` planted, B2 and B3 go red on both servers; so do a catch with no rollback, a
  rollback only for mysqli errors, and an early return before the rollback.
  **Every catch a save can reach from inside its transaction (#2137 review round 7, the sixth review's
  decision 1).** Round 6 looked only at the catches that already called the list (34 of them) and said each now
  passes a 1020 back. That was not enough, and this paragraph replaces round 6's list: a catch that does not call
  the list at all swallows a 1020 just the same. The sixth review proved it with `tuneFindOrCreateByName()`: the
  IL-id step re-throws a 1020 on purpose, the tune funnel caught it again and returned "no tune", and the song
  save carried on with no transaction at all. On MariaDB the save then answered 500 but had already saved the
  song's sections, revision and translation links without its title change; on MySQL (with a stand-in 1020) it
  answered 200 and saved everything (`tests/php/test-song-save-whole-rollback.php`, case B1, failed both ways
  before the fix). Round 7 audited EVERY catch block reachable, directly or through called functions, from inside
  a transaction opened by the song save (`editorSaveSongCore()`), the v2 editor (`manage/editor/api2.php`, 51
  transactions), the works admin page (`manage/works.php`), the songbooks admin page (`manage/songbooks.php`, 5),
  the importers (`includes/song_importers.php` — the bulk saver and the ProPresenter media step — and
  `includes/lyrics_ingest.php`, 2 each), and the transactions those open themselves through the functions they
  call (`workAutolinkSafe()` in its own-transaction mode, `songSoftDelete()`, `songCopyrightHoldersReplace()`,
  `musicianReapOrphanedAutoRow()`, `songIdPrefixProbeAndFixup()`): 67 transactions. (Round 7's notes said 72:
  its audit tool counted each of the five transactions written inside a FUNCTION of those files — the save's
  one, and two each in the importers — twice, once for the file and once for the function. The count is 67.)
  **Since round 8 this audit is a test that runs with the rest:** `tests/php/test-transaction-catch-audit.php`
  (the seventh review's L1, the lead's decision). It reads every PHP file under `appWeb/` with PHP's tokenizer,
  finds those 67 transactions again, works out every catch block that can run inside them, and FAILS if one does
  not start with the guard and is not on its short allow-list, each entry with its reason; an allow-list entry
  that no longer matches such a catch fails it too. **How "can run inside" is worked out:** a transaction runs
  from `begin_transaction()` to the last `commit()` after it in the same block (stopping at the next
  `begin_transaction()` there), or to the end of the block if there is none. Every catch written in that stretch
  counts, and so does every catch in anything it can call: a function by its name; a method by its method name in
  EVERY class that has one (over-counting rather than missing); `new X` (that class's constructor); a function or
  method named in a string (`call_user_func('x')`, `[$this, 'save']` — but not a name handed to
  `function_exists()` and the like, which calls nothing); a closure or arrow function written there; `$name(...)`
  (every closure or arrow function assigned to `$name` in the same file — this is what replaced round 7's
  hand-placed `$persistWorkExtraFields`); and `require`/`include` (the included file's top-level code, found by
  its path). Those last four are the widenings the seventh review's own audit made over round 7's tool. **Round 9
  (the eighth review's L4, the lead's decision 4) widened it to the eighth review's planted shapes:** a begin
  whose own block has no commit after it (`if ($own) { $db->begin_transaction(); }`, then the work, then `if
  ($own) { $db->commit(); }` — the `songCopyrightHoldersReplace()` shape) now runs on to the last commit in the
  whole function (or to its end); a name or a first-class callable assigned to a variable anywhere in the same
  file (`$fn = 'x';`, `$fn = x(...);`, `$fn = $box->run(...);`, `$fn = [$box, 'run'];`) is followed when `$fn()`
  is called inside; a parameter called inside a function (`$work()`) is followed to every closure or arrow
  function written straight into a call of that function in the same file; a name in a string with a leading
  backslash or a namespace (`'\x'`) counts; and the second item of a two-item list (`[$box, 'run']`,
  `array($box, 'run')`) is a method's name however short (elsewhere a string counts as a method's name only above
  three letters). Each turned the test red when the eighth review's planted catch was added through it (AU02,
  AU03, AU06, AU07, AU10, AU12); the real tree still has the same 101 catches. **What it cannot see (each planted
  by the eighth review, and still unseen):** a class's `__call()` / `__callStatic()` (AU04), `require $path` with
  the whole path in a variable (AU05), a callable kept in an object property (AU18), `new $class()` (AU19); also a
  function or class name built at run time, a method named in a string of three letters or fewer outside a
  two-item list, a closure handed in from another file, a transaction in a file none of the audited ones can
  reach, and code that runs after the last `commit()` in a block on a path that skipped that commit. It proves the shape of the code; the
  all-or-nothing behaviour is proven by running the save (`test-song-save-whole-rollback.php`). **Proven:** each
  of the eight guards the seventh review removed without any test noticing (`slideAuthTokenExpiry()`,
  `publisherResolvePickedOrCreate()`, `ed2_touchRevision()`, `workMedleyReplace()`, `songRedirectsTableReady()`,
  `generateUniqueMusicianSlug()`, `adoptApiTokenSession()`'s sliding expiry, `pickAutoSongbookColour()`) turns it
  red; so do a new unguarded catch inside a helper the save calls, a new helper with one called inside the save,
  and one catch reached only through each of the widenings (a `$var()` closure, a function named in a string,
  `new X`, an include); an unguarded catch in a helper nothing calls does not (no false alarm). **The rule now:**
  such a catch starts with `if (songRelocateIsTransactionFatal($e)) { throw $e; }`, and every file holding one
  loads the check's file at its top, so the check is always there and always first (the `function_exists()`
  forms in `lyric_lines_read.php`, `lyric_rounds.php` and `vocal_parts.php`, and the `require_once` inside the
  catch in `ilidStampNewRow()` and `workAutolinkSafe()`, are gone). The check moved from `song_relocate.php` to
  its own file, `includes/transaction_fatal.php`, which loads nothing else: `song_relocate.php` also loads the
  database layer, and loading it in some two dozen more files broke a test that stands in its own
  `getDbMysqli()` (`test-songbook-render-parity.php`). Its name is unchanged and `song_relocate.php` loads the new
  file, so every existing caller works as before. The 101 catch blocks the test finds today (round 7's 95, the
  four activity-log handlers below that its tool could not see, round 8's in `dbTransactionIsOpen()` and round
  9's second one there, around reading the session's access mode), by what each does now (line numbers
  approximate):
  - **Start by passing the error back to the caller (88).**
    `includes/transaction_fatal.php`: `dbTransactionIsOpen()` ~224 (round 9) and ~243 (round 8).
    `includes/activity_log.php`: `activityLogResolveUserId()` ~187; `logActivity()`'s two column probes ~321,
    ~342 (its main catch is in its own group below since round 8);
    `activityLogIpReputation()` ~715. `includes/api_tokens.php`: `apiTokensDeviceMetaColumnsExist()` ~102.
    `includes/arrangement.php`: `arrangementColumnExists()` ~207. `includes/external_link_helpers.php`:
    `loadExternalLinksForRow()` ~365. `includes/ilyrics_id.php`: `ilidSequenceReady()` ~268, `ilidColumnReady()`
    ~405, `ilidStampNewRow()` ~535. `includes/ip_geolocation.php`: `ihymnsGeoCacheGet()` ~163.
    `includes/lyric_lines_read.php`: `lyricLinesMirrorPresent()` ~105,
    `lyricLinesComponentExtrasPresent()` ~172. `includes/lyric_lines_sync.php`: `lyricLinesSyncReady()` ~84,
    `lyricLinesComponentsLangReady()` ~121, `lyricLinesPartTypeSlug()` ~439, `lyricLinesShadowColumnsPresent()`
    ~576, `lyricLinesWriteComponents()` ~817 and ~837, `lyricLinesEnrichmentTablesPresent()` ~1588,
    `lyricLinesVocalTablesPresent()` ~1651, `lyricLinesSnapshotDeletedEnrichment()` ~1927,
    `lyricLinesFlagRoundsAfterLineDelete()` ~1974. `includes/lyric_rounds.php`: `lyricRoundsReady()` ~152.
    `includes/musician_helpers.php`: `musicianNamePartsColumnsExist()` ~666, `musicianMaidenSurnameColumnExists()`
    ~696, `musicianMbidColumnExists()` ~757, `generateUniqueMusicianSlug()` ~1329, `musicianProfileColumnsExist()`
    ~3160. `includes/publisher_helpers.php`: `publisherTableExists()` ~120, `publisherFindOrCreateByName()` ~206,
    `publisherResolvePickedOrCreate()` ~357. `includes/search_fold.php`: `searchFoldReady()` ~111.
    `includes/song_alt_titles.php`: `songAltTitlesTableExists()` ~107. `includes/song_copyright_holders.php`:
    `songCopyrightHoldersTableExists()` ~116, `_songCopyHolders_holderIdColPresent()` ~146,
    `_songCopyHolders_publisherIdExists()` ~180, `songCopyrightHoldersList()` ~229. `includes/song_external_ids.php`:
    `songExternalIdsTableExists()` ~144. `includes/song_importers.php`: `_bulkImport_saveSong()`'s ArrangementJson
    check ~841 and revision record ~1097, `_bulkImport_nextSongNumberFor()` ~2674. `includes/song_public_id.php`:
    `songPublicId_columnReady()` ~79. `includes/song_redirects.php`: `songRedirectsTableReady()` ~80.
    `includes/song_relocate.php`: `songRelocateFkCatalogue()` ~712, `songRelocateCascadeVerdict()` ~856, the move's
    SongCount recompute in `songRelocate()` ~1398. `includes/song_soft_delete.php`:
    `_songSoftDeleteRecountSongbook()` ~441. `includes/song_translations_schema.php`:
    `songTranslationsLanguageFkPresent()` ~74. `includes/song_translations_sync.php`:
    `songTranslationsSaveLinksAllOrNothing()` ~1014 and ~1026. `includes/tune_helpers.php`: `tuneTunesTableExists()`
    ~148, `tuneFindOrCreateByName()` ~254. `includes/vocal_parts.php`: `vocalPartsTablesReady()` ~911,
    `vocalPartsSpansReady()` ~943. `includes/work_admin.php`: `workAdminReady()` ~181, `_workAdminIlIdColumnExists()`
    ~212, `workAutolinkSafe()` inside the caller's transaction ~1077, `workMedleyReady()` ~1202,
    `workMedleyReplace()` ~1514. `manage/editor/api2.php`: `ed2_songIdentityColsPresent()` ~919,
    `ed2_rightsColsPresent()` ~951, `ed2_tuneIdColumnExists()` ~1033, `ed2_worksTableExists()` ~1082,
    `ed2_copyrightHolderIdColPresent()` ~1198, `ed2_touchRevision()` ~2220 (the v2 editor's revision record), and
    in the page code the duplicate-song optional copies ~2446 and ~2460, its enrichment and scripture copy ~2606,
    and the work-medley lockstep in the section save ~3619. `manage/editor/save_song_core.php`, all in
    `editorSaveSongCore()`: the ArrangementJson column check ~570, a ChordsJson column check on the older write
    path ~915 (round 6's notes called this one a "Note" column check; it checks ChordsJson), the component
    Language and ChordsJson column checks ~1204 and ~1224, the revision record ~1416, the songbook SongCount
    recompute ~1452, the external links ~1506 and the translations-table check ~1631.
    `manage/includes/auth.php`: `adoptApiTokenSession()` ~258 and ~310. `manage/includes/songbook-palette.php`:
    `pickAutoSongbookColour()` ~102. Of these, the 52 that did not check the list at all before round 7 were the
    fault; the column and table checks only read `INFORMATION_SCHEMA`, so in practice a 1020 starts in the writes,
    but a deadlock or lock wait timeout can arrive anywhere. `tests/php/test-song-save-whole-rollback.php` runs the
    real save and proves two of them end to end: B1 (the tune funnel, a real 1020 on MariaDB) and B4
    (`logActivity()`, a deadlock while the save writes its own activity-log row); the rest rest on this audit.
    **Round 8 added the guard to five more** that round 7 had listed by hand as safe, so that they need no
    allow-list entry: `activityLogRequestId()` ~141 (makes a random id), `getDbMysqli()` (`db_mysql.php` ~107;
    checks whether its saved connection is still open, no query), `ihymnsGeoViaMaxmind()` ~244 (reads a local
    file), `mediaLanguageReady()` (`media_language.php` ~156; loads the shared language rules) and the
    `\InvalidArgumentException` around a malformed alternative title in `_bulkImport_saveSong()`
    (`song_importers.php` ~1078). None of their tries touches the database today, so nothing changes; but "cannot
    meet a database error" is a claim a later edit to the try can quietly make false, and the guard cannot be.
    `db_mysql.php` and `media_language.php` now load `transaction_fatal.php` at their top for it.
  - **Pass it back only when a transaction was open (1)** — round 8. `logActivity()`'s main catch
    (`activity_log.php` ~434): see "Round 8 … corrected for the two best-effort writes" below.
  - **Write only when no transaction is open, and log every error (2)** — round 9 (the eighth review's L1, the
    lead's decision 1). `ihymnsGeoCachePut()` (`ip_geolocation.php` ~231) and `slideAuthTokenExpiry()`
    (`api.php` ~28167) return before their try whenever `dbTransactionIsOpen()` does not answer "not open", so
    their write runs only outside a transaction, where a deadlock or a lock wait timeout ends nothing but that one
    row: their catch logs it and carries on. See "Round 9" below.
  - **Pass it back already, in their own way (3).** `workFindOrLinkByIdentifier()` (`work_admin.php` ~974)
    re-throws every database error except a duplicate (1062); `musicianReapOrphanedAutoRow()`
    (`musician_helpers.php` ~1886) re-throws every error except a missing table (1146);
    `songTranslationsSaveLinksAllOrNothing()`'s catch around a failed undo (`song_translations_sync.php` ~1040)
    always throws, carrying the original error.
  - **Handle a transaction of their own (3).** `workAutolinkSafe()` in its own-transaction mode (`work_admin.php`
    ~1064, and ~1067, the catch around its own rollback) and `songCopyrightHoldersReplace()` when it owns the
    transaction (`song_copyright_holders.php` ~509) roll back their OWN transaction and answer null /
    `write_failed`; that mode is used only after the caller's own write has committed. When
    `songCopyrightHoldersReplace()` is running inside the caller's transaction it re-throws everything.
  - **Run by PHP at the end of the request, or as it dies (4)** — new in round 8, because the test follows an
    include: the save's transaction loads `manage/includes/auth.php`, whose top level installs the activity log's
    handlers. The end-of-request "request.*" row (a shutdown function, `activity_log.php` ~864), the uncaught-
    exception handler's log row and its hand-over to the handler before it (~934, ~944), and the
    "fatal.php_error" row (a shutdown function, ~985). PHP calls these only after the request's own code has
    finished or an exception has escaped everything, so no save code runs after them; the guard there would only
    turn a failed end-of-request log row into an error after the response.
  The thirteen not passing the error back with the plain guard (every group after the first) are the test's
  allow-list, each with its reason in the test.

  **What this changes outside a transaction.** Most of these helpers are also called where no transaction is
  open. There too, a deadlock, a lock wait timeout or a 1020 is now passed back instead of being logged and
  ignored, so a request that meets one in a best-effort step (an activity-log row, the geo cache, a sign-in
  token's sliding expiry) now fails instead of quietly carrying on. These are rare outside a transaction, but
  they are a change. The song save's own error handler, which runs after its rollback, wraps the two calls in it
  that could now meet one (the failure's activity-log row and the "who is signed in" lookup), so the save's own
  error answer is never replaced; other error handlers were not changed this round.
  **Round 8 (the seventh review's check 2 and L2, the lead's decisions 3 and 2) — corrected for the two
  best-effort writes.** The seventh review showed the change above was real and harmful for the activity log:
  another session holding the log table's last gap (its "purge lock") made a log row written AFTER a page's work
  had committed meet a lock wait timeout (1205), and the request failed — on work already saved, inviting a
  duplicate on retry. Such calls are everywhere: a tokenizer scan found 104 `logActivity()` calls written after a
  `commit()` in the same function or page block with no new `begin_transaction()` between (api.php 31,
  manage/editor/api2.php 50 — `create_song` ~2363 among them —, manage/songbooks.php 5 — the auto-colour audit
  row ~2264 among them —, duplicate-songs.php, publishers.php, tunes.php and vocal-parts-review.php 3 each,
  includes/song_importers.php 2, manage/editor/api.php, manage/includes/auth.php, songbook-series.php and
  works.php 1 each), and more functions write their own log row after their own work (auth.php's user
  functions, for example). So the fix is in `logActivity()` itself, not at a hundred call sites: it asks once,
  BEFORE its first database statement, whether a transaction is open on the shared connection
  (`dbTransactionIsOpen()`, below); its main catch passes a transaction-ending error back only when one was open
  (or it could not tell), and otherwise logs and carries on, as before round 7. It has to ask first: a deadlock
  or a 1020 has already rolled the whole transaction back by the time PHP sees it, so asking in the catch would
  always answer "not open" — `tests/php/test-activity-log-outside-transaction.php` proves that with a REAL
  deadlock on the log row inside a transaction (B8), which a check made afterwards lets through. The user and
  client-address lookups moved inside its try, so their errors reach the same catch. That catch is therefore on
  the audit test's allow-list, with this reason. The ISWC works backfill (`.sql/backfill-works-from-iswc.php`
  ~304) already wrapped its own log row in a catch that carries on; a deadlock there finishes the batch, and the
  test pins it (B7). **The geo cache is never written inside a transaction.** `logActivity()` looks up the
  visitor's country while it writes its row, and `ihymnsGeoCachePut()` wrote the shared `tblIpReputation` row on
  the same connection — inside the song save's transaction, since the save writes its activity row there. On
  MariaDB, when another request wrote the same address's row after the save had begun reading, the save's write
  met a 1020 and the save answered "Failed to save song" over a best-effort cache (on MySQL it wrote the row
  inside the save). Now, while a transaction is open (or that cannot be told), `ihymnsGeoCachePut()` writes
  nothing; the lookup still answers from the local country database, and the row is written by the next lookup
  made outside a transaction. Proven on both servers: the review's race through the real save (B5 in
  `test-song-save-whole-rollback.php` — on round 7's code MariaDB answered 500 and MySQL wrote the row), and at
  the cache write itself (C1–C3 in the new test). `IHYMNS_GEO_MMDB_PATH` can now be defined before
  `ip_geolocation.php` loads, which is how both tests put a stand-in country database in place.
  **How "is a transaction open?" is asked — `dbTransactionIsOpen()`, in `includes/transaction_fatal.php`.** It
  reads the session's own access mode (`@@SESSION.transaction_read_only`, or `tx_read_only` on a server that does
  not know that name — MariaDB 10.11, for one), then runs `SET TRANSACTION READ ONLY` or `SET TRANSACTION READ
  WRITE`, whichever the session already has: without GLOBAL or SESSION that sets only the NEXT transaction's access
  mode, and both servers refuse it with 1568 while a transaction is open — from `begin_transaction()` on, before
  any statement, and with autocommit off once a statement has read or written a table (round 9, the eighth
  review's L9: with autocommit off a statement that touches no table, such as `SELECT 1`, opens nothing, and the
  answer "not open" is then right — nothing could be lost). Checked on MariaDB 11.8.9, MySQL 8.4.11 and MySQL
  5.7.44; a refused SET leaves the open transaction exactly as it was. **What asking changes (round 9, the eighth
  review's L2, the lead's decision 2).** Round 8 ran `SET TRANSACTION READ WRITE` whatever the session's mode, and
  said its only effect was a read-write next transaction "which it would have been anyway"; the eighth review
  showed that in a session set to READ ONLY the next write then went through. Now, when no transaction is open,
  asking KEEPS the session's own access mode and isolation level (the next autocommit statement and the next
  `BEGIN` behave exactly as without asking: a read-only session still refuses a write, a session at READ
  UNCOMMITTED still reads another request's uncommitted change) and a one-off isolation level set with `SET
  TRANSACTION ISOLATION LEVEL …` just before asking. It does NOT keep a one-off access mode set with `SET
  TRANSACTION READ ONLY` (or READ WRITE) just before asking, where that differs from the session's own: the next
  transaction gets the session's mode instead (the server shows a pending one-off nowhere it can be read back;
  nothing in iHymns sets one). Proven on MariaDB 11.8.9, MySQL 8.4.11 and MariaDB 10.11.19 by
  `test-activity-log-outside-transaction.php` A8–A12 (A12 pins what is not kept), and on MySQL 5.7.44 with the
  eighth review's own probe. It now costs two round trips. **"Could not tell" counts as open, and is tested
  (round 9, the eighth review's L3, the lead's decision 3).** When the server's answer cannot be read the question
  says null, never "not open", and every caller treats null as "maybe open"; no test checked that before (the
  eighth review planted four faults against it and all stayed green). `test-activity-log-outside-transaction.php`
  Part E: a connection with an unread result pending, and one killed just before the question's SET, each with
  error reporting on and off, all get null (E1); with the question unanswerable, `logActivity()` passes a deadlock
  on its row back (E2), and neither the geo cache (E3) nor the token's sliding expiry (E4) writes. Rejected: MariaDB's
  `@@in_transaction` (MySQL lacks it — 1193); `information_schema.INNODB_TRX` (MySQL needs the PROCESS
  privilege, which a shared host's account usually lacks); counting `begin_transaction()`/`commit()` in PHP (all
  ~146 places that open one would have to take part, and one that did not would make the answer "not open"
  inside a transaction — the wrong way to be wrong); passing the knowledge in from callers (the activity log and
  the geo cache are called from hundreds of places that do not know). It costs two round trips (one before round
  9) per log row, per cache write and per due token slide. **Planted faults, each turning these tests red on both servers:** the cache check removed,
  `dbTransactionIsOpen()` always answering "not open", `logActivity()`'s catch back to round 7's plain guard,
  back to swallowing everything, and asking only after the error. **Not covered by round 8:** the
  other best-effort helpers round 7 guarded (a sign-in token's sliding expiry and the like) still pass such an
  error back outside a transaction too; they write single rows that rarely wait on a lock, and the decision named
  the activity log and the geo cache.
  **Round 9 (the eighth review's L1, the lead's decision 1) — a write that only ever runs outside a transaction
  never fails the request.** Round 8 left the geo cache's catch passing a deadlock or a lock wait timeout back,
  although since round 8 that write only runs when no transaction is open. Checked on MariaDB 11.8.9 and MySQL
  8.4.11 before the change, with another request holding the same address's cache row for a moment (its insert
  not yet committed): `logActivity()` threw nothing but lost its whole activity row (only "write failed … Lock
  wait timeout" in the error log), and the admin "geolocate" request (`manage/activity-log.php ?action=geo`,
  `api.php`'s `admin_ip_geolocate`, through `activityLogGeoResolveIps()`) failed with the 1205. Now that catch
  logs every error and carries on. **The sign-in token's sliding expiry had the same fault, and now has the same
  treatment.** `logActivity()` asks `getAuthenticatedUser()` (`api.php`) who is signed in, which slides the
  token's expiry once a day (an UPDATE of the token's row). With another request holding that row, checked on
  both servers before the change: `logActivity()` lost its row, and `getAuthenticatedUser()` itself threw the 1205
  (so the request asking who is signed in failed). Now `slideAuthTokenExpiry()` asks `dbTransactionIsOpen()`
  first and writes nothing while a transaction is open (or that cannot be told) — the next sign-in check outside
  one slides the token — and its catch logs every error and carries on. So that the question costs nothing on
  the other days, `getAuthenticatedUser()` reads whether the slide is due with the user (the same comparison as
  the slide's UPDATE) and calls it only then; before, it sent the (usually empty) UPDATE on every call. Tests:
  `test-activity-log-outside-transaction.php` C4 and C5 (the geo cache, a real 1205) and Part D (the token: D1–D2
  a real 1205 outside a transaction, D3 inside one it writes nothing and waits for nothing, D4 the next check
  slides it, D5 nothing is sent when the slide is not due). **Still not changed:** `adoptApiTokenSession()`'s own
  sliding expiry (`manage/includes/auth.php` ~310, used when a /manage page adopts the main app's sign-in) has
  the same shape and still passes such an error back outside a transaction (from reading; not reproduced).
  **Still true from round 6, outside this round's scope:** `.sql/migrate-backfill-vocal-part-suggestions.php`
  stops the batch on a 1020, as it already did on a deadlock, instead of counting that song as "errored" and
  continuing; `includes/vocal_part_review.php` still checks the list through `function_exists()` (it is not
  reachable from the transactions above).
- **Reporting, not rewriting.** The curator audit on `/manage/languages` lists stored tags that are
  malformed, unregistered, retired, or not in standard form (e.g. `en-gb`), and offers a remap. Importers
  report a language they cannot read as an `import.language_unrecognised` row on `/manage/activity-log`.
  Since the #2137 review fixes, a stored tag changes only when a person saves or confirms something: a song
  save tidies the tags it writes (a translation link stored as `iw` is updated in place to `he`, keeping its
  translator and verified flag, and since round 5 so is `pt → T1` changed to `pt-BR → T1` — since round 6 a change
  to a different primary language keeps the translator but not the verified flag, and a link re-pointed to another
  song never takes the old song's details; of two stored links
  that tidy to one language, the one the curator keeps in
  the editor survives and the other is deleted, and if the editor sends both or neither, both stay with a
  warning; two links sent for one language change nothing for it; a link that cannot be written — for any
  reason, including a target song that no longer exists or a new language the server cannot store yet — leaves
  the stored links sharing its song or its language exactly as they were (see the translations bullet above for
  exactly when that holds); the link writes are all or nothing — `songTranslationsSaveLinksAllOrNothing()`
  around `songTranslationsSaveLinks()`, tested against a real database); the
  language picker keeps a tag its boxes cannot show (`en-u-ca-gregory`, `x-hymnal`) instead of rewriting it;
  the songbook-language card fills only empty languages and only when confirmed; the remap is a curator
  action. No migration run by
  "Apply all" changes a stored tag (policy COMPAT-040).
- **Apple app** (#2136): `LanguageDisplay` (`appApple/Packages/iHymnsKit/Sources/IHFeatures/LanguageDisplay.swift`)
  names a tag with the system's own `Locale.localizedString(forIdentifier:)` — in the device's language — and
  sorts lists A to Z by that name, special codes last. Filters still match the exact tag. The app has no
  language-preference setting yet, so "your languages first" does not apply there.


---

## 🚀 Deployment Architecture

### Branch → Directory → Server

| Branch | Source Directory | SFTP Path Secret | Environment |
| --- | --- | --- | --- |
| `main` | `appWeb/public_html/` | `SFTP_LIVE_PATH` | Production |
| `beta` | `appWeb/public_html/` | `SFTP_BETA_PATH` | Beta |
| `alpha` | `appWeb/public_html/` | `SFTP_DEV_PATH` | Alpha/Dev |

### Deployment Flow

1. All development happens in `appWeb/public_html/`
2. Push to `alpha` → SFTP uploads `public_html/` → remote `public_html_dev/`
3. Push to `beta` → auto version bump → minify → SFTP uploads `public_html/` → remote `public_html_beta/`
4. Push to `main` → SFTP uploads `public_html/` → remote `public_html/`
5. All branches also deploy `appWeb/data_share/` → remote `data_share/` (without `--delete`)
6. Uses `lftp mirror --reverse --delete --only-newer` for efficient sync
7. **Media excludes (#1584)** — the docroot mirror runs `--delete`, but song audio (`data/audio/`) and sheet music (`data/music/`) live on the SERVER only (git tracks zero files under `appWeb/public_html/data/`). `--exclude ^data/audio/` and `--exclude ^data/music/` are load-bearing in `deploy.yml`'s shared `$LFTP_EXCLUDES` — without them every deploy silently wiped uploaded/downloadable media (fixed in commit `8ef7b587`).

### Commit Message Flags

| Flag | Effect |
| --- | --- |
| `[deploy all]` | Force full SFTP upload even if no files changed |
| `[skip sync]` | (Deprecated — no longer used) |
| `[skip ci]` | Skip changelog and deploy workflows |

### Version Numbering

**Tag-free, Conventional-Commit-driven scheme (#1963 → #1965 → the 2026-08-30 marketing-version/build-number split, superseding the earlier tag-derived #1899 scheme).** iHymns deploys **direct via SFTP** and cuts **NO git tags and NO GitHub Releases**. Two numbers travel separately and are never folded into one string:

- **The marketing version** — the full `MAJOR.MINOR.PATCH` semver committed as `Version.Number` in `appWeb/public_html/includes/infoAppVer.php` (currently `1.3.0`). This is the authoritative, human-facing "what release is this" anchor (also the Apple major-parity anchor, so it must stay three plain integers `X.Y.Z`). Unlike the pre-split scheme, the patch digit is now a **real, deliberate release level** — see below — not a placeholder the build count overwrites.
- **The build number** — `git rev-list --count HEAD`, a monotonic per-commit id, held in the separate `Version.Build.Number` field. `deploy.yml` injects it for display on **every** deploy, no gate, regardless of whether the marketing version moved. It is surfaced alongside (never merged into) the marketing version — public footer `iHymns v1.3.0 · build <n> · Alpha`, the admin footer, and its own row in Settings → About.
- **The bump level is decided by Conventional-Commit prefixes on the squash-merge subject**, via `.github/workflows/scripts/classify-bump.sh` (truth-tabled by `tests/test-bump-classifier.js`): `feat:` → **minor**, `feat!:` / `fix!:` / any `!` / a line-anchored `BREAKING CHANGE:` → **major**, an explicit whole-line, case-insensitive `Release: patch` footer on the merge message → **patch** (a deliberate "this is a bug-fix release" signal — meaningful for the native app stores even though the web ships continuously), everything else (`fix:`/`chore:`/`docs:`/`refactor:`/`perf:`/`style:`/`test:`/`ci:`/unrecognised, with no `Release: patch` footer) → **build-only** (the safe default — a mislabelled push under-bumps, never over-bumps, and only the always-incrementing build number moves). Precedence across a classified commit range is major > minor > patch > none.
- On `alpha`, when the classifier finds a major/minor/patch signal among the commits since the last change to the committed `Version.Number` line, `deploy.yml` edits that line and commits it back with `[skip ci]` — a normal branch push, **never a tag**. beta/main simply display whatever `Version.Number` travels with the promoted commits (no tag reachability needed — a squash-merged promotion PR carries the file bytes regardless of how it was merged).
- **`release.yml` is now DORMANT** — it only fires on a human-pushed `v*` tag (which nothing in the pipeline does any more) or `workflow_dispatch`; `promotion-deploy-bridge.yml` reverted to being purely the #1007 beta/main SFTP-deploy bridge (it no longer mints a tag or dispatches `release.yml`).
- The load-bearing convention this depends on: **title every PR / squash-merge with a Conventional-Commit prefix.** A feature merged without `feat:` simply won't bump the minor (safe, but a miss); title non-features `fix:`/`chore:`/`refactor:`/`ci:`/`docs:` so they never wrongly bump it. Add a whole-line `Release: patch` footer to the merge body when a fix genuinely warrants a discrete bug-fix release number.
- `api-docs.yaml`'s `info.version` and the `X-Powered-By` header carry the marketing version only, never the build number, kept in lockstep via a CI guard (`test-openapi-actions-exist.php`). CI guard for the whole pipeline: `tests/test-versioning-pipeline.js` (tree-derived, mutation-proven) asserts no `git tag`/`refs/tags`/release dispatch survives, plus a "separation invariant" check that the marketing-version sed never sees the build-number shell variable. `tests/test-bump-classifier.js` carries the full patch-footer truth table (detection, near-misses, precedence).
- **Cache-buster note:** because the marketing version now usually stays put across routine deploys (where the old scheme changed it every single deploy via the folded-in commit count), `index.php`'s CSS/JS `?v=` cache-busters fold in the build number too (`Version.Number . '-' . Build.Number`) so a build-only deploy still gets fresh asset URLs rather than serving stale CSS/JS under `.htaccess`'s max-age.
- **Native apps are NOT on this scheme yet** — Apple/Android store build numbers are already higher than the web's commit count, so adopting the shared build-number scheme now would go backwards and be store-rejected; a follow-up is needed before either platform adopts it.
- **Companion obligation:** every user-visible `feat:` push should also add a plain-language bullet to `WHATS-NEW.md` under the current `## <MAJOR.MINOR.PATCH> — <date>` heading (house style `.claude/whats-new-style.md`) — that file, not `CHANGELOG.md`, feeds the in-app `/whats-new` page.

**Build Number.** Alongside the human-facing marketing version, `infoAppVer.php`'s
`Application.Version.Build.Number` carries a **monotonic per-commit build id** —
`git rev-list --count HEAD`, injected by `deploy.yml`'s "Inject build info into infoAppVer.php" step
using the same sed-injection mechanism as the SHA/date. It is `NULL` on any checkout that hasn't been
through a deploy (local dev, CI). Where the marketing version answers "which release is this" (and can
repeat across many commits between bumps), the build number answers "which commit, precisely" —
monotonically increasing, one per commit, never reset, and never touched by a `Release: patch` bump —
the two move independently. `api-docs.yaml`'s `info.version` is kept in lockstep with the marketing
version by the same workflow (a dedicated step + a CI guard, `test-openapi-actions-exist.php`) — the
build number is NOT part of that lockstep, since it has no equivalent field in the OpenAPI spec.

### Application IDs (per-platform)

| Platform | Application ID |
| --- | --- |
| Web/PWA | `Ltd.MWBMPartners.iHymns.PWA` |
| Apple | `Ltd.MWBMPartners.iHymns.Apple` |
| Android | `Ltd.MWBMPartners.iHymns.Android` |

### CI/CD Workflow Inventory

15 YAML workflows in `.github/workflows/` (there is also a non-workflow `scripts/` subdirectory alongside them):

| Workflow | Purpose |
| --- | --- |
| `deploy.yml` | SFTP deploy on push to `alpha` / `beta` / `main`, incl. the What's New extraction (#1583), media excludes (#1584), build-info injection, and — since #1963/#1965, extended by the marketing-version/build-number split — the tag-free version classify-and-bump step on `alpha` (major/minor/patch/build-only) |
| `changelog.yml` | Regenerates the four `CHANGELOG.md` files from conventional commits on push to `main`/`beta` |
| `release.yml` | **Dormant** since #1965 — only fires on a human-pushed `v*` tag or manual `workflow_dispatch`; nothing in the automated pipeline pushes a tag any more |
| `test.yml` | ESLint, PHP syntax (`php -l`), JSON validation, and HTMLHint on JS/CSS/PHP/HTML changes |
| `lint.yml` | Lints the workflow YAML files themselves with `actionlint` |
| `apple.yml` | Apple CI — SwiftLint, package tests, build (no signing) |
| `apple-deploy.yml` | Builds + uploads to TestFlight Internal/External or the App Store, branch-dependent |
| `apple-dmg.yml` | Builds a signed + notarized macOS `.dmg` as a GitHub Release asset (a distribution channel separate from the App Store) |
| `auto-merge-alpha.yml` | Enables GitHub's native auto-merge on any PR whose base branch is `alpha` |
| `build-android.yml` | Builds/distributes the Android app (Play Store, Amazon Appstore/Fire OS, direct APK) |
| `dependabot-security-backport.yml` | Backports a Dependabot security-fix PR merged to `alpha` onto `beta`/`main` so a CVE fix doesn't wait for the next full promotion |
| `language-registry-refresh.yml` | Monthly BCP 47 / IANA language-subtag + CLDR refresh — a snapshot-sync leg that commits changed registry files to `alpha`, and an independent DB-refresh leg that pokes a keyed endpoint to re-run the import against the live shared DB |
| `maintenance-ha-integrity-audit.yml` | Monthly cross-source integrity audit (#699 Phase C) against the Spanish "Himnario Adventista" (HA) songbook |
| `maintenance-issues-sweep.yml` | Monthly sweep that closes GitHub issues referenced by `closes #N` in commits merged to `alpha` but never auto-closed (GitHub only auto-closes on the default branch) |
| `promotion-deploy-bridge.yml` | Fires the SFTP deploy when a promotion PR is merged into `beta` or `main` (GitHub suppresses the `push` event a `GITHUB_TOKEN` merge produces, #1007). Since #1963 this is its **whole** job again — it no longer mints a release tag or dispatches `release.yml`; that moved to `deploy.yml` running on `alpha` |

---

## 🔧 Development Environment

### Recommended Setup

- **Editor**: VS Code or Xcode (for Apple development)
- **PHP**: 8.1–8.5 (8.1 minimum, tested through 8.5)
- **Node.js**: v22+ (LTS)
- **npm**: v10+
- **Xcode**: 16+ (for Swift 6.3)
- **Android Studio**: Latest (for Kotlin/Compose)
- **OS**: macOS (required for Apple development), also supports Windows and Raspberry Pi

### Quick Start

```bash
# Clone the repository
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns

# Install the repo's git hooks (tools/githooks/pre-push guards against
# resurrecting a deleted branch or pushing the wrong local branch)
git config core.hooksPath tools/githooks

# Start local PHP dev server (web app)
npm run dev
# → http://localhost:8000

# Run unit tests
npm test

# Build web app for deployment
npm run build:web
```

### IDE Extensions (VS Code)

- ESLint
- Prettier
- HTMLHint
- Stylelint
- PHP Intelephense
- Swift (for Apple development)

---

## 🧪 Test Suites

Two glob-driven runners are the **one** list of "which tests exist" for their language — CI calls
these scripts directly, so a new test file cannot silently be added without being run (the disease
this fixed: `npm test` and CI's node step once hardcoded two *different* file lists, so 15 of 22 node
suites ran in neither; the PHP side had the identical problem, with 8 files never invoked in CI at
all, including a 48-assertion login brute-force suite):

```bash
# JavaScript / Node test suites — tests/*.js, non-recursive
node tools/run-node-tests.js      # same as `npm test`
npm test

# PHP test suites — tests/php/*.php, non-recursive (fixtures/ is data, excluded by construction)
php tools/run-php-tests.php

# Fast syntax-only checks (no test logic)
npm run test:php                  # find appWeb -name '*.php' -exec php -l {} \;
npm run test:js                   # find appWeb -name '*.js' -exec node --check {} \;

# Everything CI runs
npm run test:all                  # npm test && npm run test:php && npm run test:js
```

As of this pass: **97 node suites** (`tests/*.js`) and **267 PHP suites** (`tests/php/*.php`).
Both counts grow steadily — check `ls tests/*.js | wc -l` / `ls tests/php/*.php | wc -l` for
the live count rather than trusting a number in prose.

Both runners exit non-zero on any failing suite, so they compose in a shell `&&` chain (as
`test:all` does) or a CI job step. Almost every suite in this repo is **mutation-proven** (CLAUDE.md
rule #34) — written by deliberately breaking the thing it guards, confirming the test goes red, then
reverting — and **tree-derived** rather than a hand-typed file list, so a guard cannot go quietly
stale the way a hardcoded enumeration does (rule #34's whole point, and the reason these two runner
scripts exist in the first place).

There is also a Playwright browser-smoke layer (`npm run test:browser`), and two narrower
composition scripts (`npm run test:export` — just the ProPresenter/export suites) for fast iteration
on one feature area without the full run.

---

## 📝 Commit Message Conventions

This project uses [Conventional Commits](https://www.conventionalcommits.org/) on the PR / squash-merge
subject to decide the marketing-version bump (see "Version Numbering" above for the full contract —
this is a short summary of the same rule, not a second source of truth):

```text
feat: add new feature                     → minor version bump
feat!: breaking change (or any `!`,       → major version bump
  or a line-anchored BREAKING CHANGE:)
Release: patch   (whole-line PR footer)   → patch version bump (a deliberate bug-fix release)
fix: / docs: / refactor: / chore: /       → no version-number bump — only the
  test: / perf: / style: / ci: / unknown    separate, always-incrementing build
  (no "Release: patch" footer)              number moves ("build-only")
```

The safe default is build-only: a mislabelled or bare subject under-bumps (the version simply
doesn't move) rather than over-bumping. A bare `fix:` never implies a patch release on its own —
that needs the explicit `Release: patch` footer.

---

## MySQL Database Setup (v0.10.0+)

Starting with v0.10.0, iHymns uses MySQL as the data store. Since the DB-direct rewrite (epic #1010), MySQL is the **single source of truth** for all song reads and writes — there is no JSON corpus fallback (a DB outage returns a themed 503). MySQL provides full-text search indexing, concurrent write safety, user accounts, and features like popular songs, song tags, and translation linking.

### Prerequisites

- **MySQL 5.7+** or **MariaDB 10.3+** with InnoDB support
- **PHP 8.1–8.5** (8.1 minimum, tested through 8.5) with the `mysqli` extension enabled
- A MySQL database created for iHymns:
  ```sql
  CREATE DATABASE ihymns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

### Step-by-Step Installation

#### Step 1: Run the Interactive Installer

```bash
php appWeb/.sql/install.php
```

The wizard prompts for:

| Prompt | Default | Description |
| --- | --- | --- |
| MySQL Host | `127.0.0.1` | Server hostname or IP |
| MySQL Port | `3306` | Server port |
| Database Name | `ihymns` | Must already exist |
| Username | `ihymns_user` | MySQL user with full privileges on the DB |
| Password | _(none)_ | Hidden input on supported terminals |
| Table Prefix | _(none)_ | Optional prefix (e.g., `ih_`) for shared databases |

The installer will:

1. Test the connection before writing anything
2. Write credentials to `appWeb/.auth/db_credentials.php` (permissions `0600`)
3. Create all tables from `schema.sql` (166 `CREATE TABLE` statements; idempotent — safe to re-run)
4. Seed default data: user groups, 14 languages, 5 access tiers, app settings

> **Manual setup:** Copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php`, edit it, then re-run the installer.

#### Step 2: Add Song Content

There is no `songs.json` bootstrap importer any more — the old `migrate-json.php` script was
retired in #1614 (it depended on a `tblSongComponents.LinesJson` shadow column that a fresh
`schema.sql` install no longer even creates, so it could not have bootstrapped a new install
either). Real content goes straight into MySQL via one of:

- The Song Editor's bulk importer, `/manage/editor/import2.php` — upload a `.zip` of song files
  (OpenSong, OpenLyrics/OpenLP, ProPresenter, VideoPsalm, ChordPro, iHymns interchange JSON, and
  more); it runs as a background job and inserts new songs (existing ones are skipped, never
  overwritten).
- `appWeb/.sql/restore.php`, or the web installer's restore-upload flow, from a real backup.

`npm run parse-songs` still exists to turn `.SourceSongData/` raw text files into a local
`tmp/songs.json` file for inspection, but nothing downstream reads that file any more.

#### Step 3: Create Initial Admin User

Navigate to `/manage/setup` in the browser. The first account becomes the **Global Admin**.

#### Step 4: Verify Installation

Navigate to `/manage/setup-database.php` to see:
- Database connection status
- Table row counts
- Run additional migrations (users, cleanup, backup)

#### Web-Based Setup (No CLI Required)

For shared hosting without SSH:

1. Copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php` and edit
2. Navigate to `/manage/setup-database.php`
3. Click **Run Install** (creates tables), then **Apply all pending** to bring migrations current
4. Visit `/manage/setup` (create admin account)
5. Sign in and use the Song Editor's bulk importer (`/manage/editor/import2.php`) to add song content

#### The old one-shot dump is gone (and was already broken)

`appWeb/.sql/.fulldata/ihymns-full.sql` used to be offered here as a shortcut. It is no longer in
the repository. It was last refreshed on 1 June 2026 and had drifted a long way from reality: it
creates 80 tables against a current schema of 166, and contains no `tblLyricLines` — the table song
lyrics have lived in since #1235. Loading it today produces a database that cannot hold a lyric.
Use the numbered steps above instead.

### DB-Direct Reads (epic #1010, WS-J #1020)

Song reads are **live MySQL** — there is NO `songs.json` corpus cache, no
`songs_json` endpoint, and no server-side JSON fallback for reads. The whole-corpus
materialiser (`SongData::exportAsJson()` / `songsCacheServe()`) and the
`?action=songs_json` endpoint were all **removed in WS-J #1020**. Reads are always
scoped: `getSongsSlimIndex()` (lightweight id/number/title/songbook index, served by
`?action=songs_index`), `getSongs($abbr)` (one songbook, editor `?action=songbook_export`),
`getSongById()` (one full record, `?action=song_detail`).

`songs.json` is retired entirely (#1617) — the old `migrate-json.php` importer that
used to consume it was removed in #1614, and there is no tracked corpus file left in
the repo (`data/` holds only a `.gitkeep`). New song content goes straight into MySQL
through the Song Editor's bulk importer or a real backup restore.

When MySQL is unavailable, the server returns a **themed 503 maintenance page**
(WS-K #1021, `includes/maintenance.php` + the `api.php` `isDbConnectionFailure()`
503 path) — a graceful outage, never stale data. The ONLY offline fallback is the
**client PWA offline cache** (service-worker-cached song pages + the slim index,
queried live against the server on every search — there is no client-side Fuse.js
index any more, #1014/#1015).

### Database Schema Overview

**Song Data (7 tables):**

| Table | Purpose |
| --- | --- |
| `tblSongbooks` | Songbook definitions (CP, JP, MP, SDAH, CH, Misc) |
| `tblSongs` | Core metadata + `LyricsText` for FULLTEXT search |
| `tblSongWriters` | Lyricist credits (many-to-one) |
| `tblSongComposers` | Composer credits (many-to-one) |
| `tblSongComponents` | Verse/chorus/bridge component metadata (the `LinesJson`/`ChordsJson`/`NotesJson` columns are a gated shadow being retired — see below) |
| `tblLyricLines` | **Source of truth for lyric lines** (#1235) — normalised one-row-per-line, the single read + write path |
| `tblSongTranslations` | Links songs to whole-song translations in other languages |

**Discovery & Community (4 tables):**

| Table | Purpose |
| --- | --- |
| `tblSongHistory` | View tracking for popular songs ranking |
| `tblSongTags` | Thematic tag definitions (Easter, Advent, etc.) |
| `tblSongTagMap` | Song-to-tag mapping |
| `tblSongRequests` | User-submitted song requests |

**Languages (1 table):**

| Table | Purpose |
| --- | --- |
| `tblLanguages` | 14 supported languages with text direction (ltr/rtl) |

**User Accounts & Access (10+ tables):**

| Table | Purpose |
| --- | --- |
| `tblUsers` | Accounts with role-based access |
| `tblUserGroups` | Version channel access (Alpha/Beta/RC/RTW) |
| `tblSessions` / `tblApiTokens` | Admin panel sessions and API auth tokens |
| `tblAccessTiers` | Content gating levels (public → pro) |
| `tblOrganisations` | Church/organisation licensing |
| `tblUserSetlists` | Cross-device setlist sync |
| `tblActivityLog` | Audit trail |

**Post-2026-05 schema families (added since the original 6+ table model):**

| Family | Key tables | Notes |
| --- | --- | --- |
| **Per-line lyric enrichment** | `tblLyricLineTranslations`, `tblLyricLineAnnotations` | Per-line translations/romanizations + Genius-style annotations anchored on `tblLyricLines.Id` (#1088) |
| **Works** (#840) | `tblWorks`, `tblWorkSongs`, `tblWorkExternalLinks` | Composition grouping across songbooks (`/manage/works`, public `/work/<slug>`) |
| **External-links registry** (#833/#845) | `tblExternalLinkTypes`, `tblExternalLinkPatterns`, `tblSongExternalLinks`, `tblSongbookExternalLinks`, `tblCreditPersonExternalLinks`, `tblWorkExternalLinks` | Controlled provider vocabulary + URL→provider auto-detect |
| **Duplicate / counterpart linking** (#1215/#1216) | `tblSongLinks`, `tblSongLinkSuggestions`, `tblSongLinkSuggestionsDismissed` | Scored fuzzy + curator-confirmed cross-book links (`/manage/duplicate-songs`) |
| **Service Mode / Live-Follow** (#1323/#1335) | `tblLiveFollowSessions`, `tblLiveFollowJoinCodes`, `tblServicePresence`, `tblOrgVenues`, recurring-schedule tables | Congregation live-follow via venue rotating code (dormant behind `content_gating_enabled='0'`) |
| **Arrangements** | `tblSongArrangements` | One-pass forward-looking arrangement schema (#1066) |
| **Interchange / identity** (#1066) | `tblSongIdentityMap`, `tblApiKeyUsage`, `tblApiKeyIdempotency`, `tblLyricsConflicts`, `tblLyricsReviewQueue`, … | Dormant interchange / ingest / identity batch |
| **Catalogues** (user-labelled "Collections") | `tblCatalogues`, `tblCatalogueSongs` | Curated groupings; internal name stays `catalogue` (#1223) |

Full schema: `appWeb/.sql/schema.sql` (166 `CREATE TABLE` statements). Migrations
are NOT auto-applied on deploy — they are run via the web runner at
`/manage/setup-database` (registry-driven "Apply all pending"; the operator is
web-only, no CLI/SSH on the shared host).

### Key API Endpoints

| Endpoint | Description |
| --- | --- |
| `?action=bulk_songs&songbook=X` | Bulk download: all rendered HTML for a songbook in one response |
| `?action=songs_index` | Slim id/number/title/songbook index (served to the PWA for client-side search) |
| `?action=songbook_export&songbook=X` | One songbook's full records (editor read path) |
| `?action=song_detail&id=X` | One full song record (DB-direct) |
| `?action=song_translations&id=X` | Bidirectional translation lookup |
| `?action=popular_songs&period=month` | Popular songs by view count |
| `?action=tags` | All thematic tags |
| `?page=song&id=X` | Rendered song page HTML (cached by service worker) |

### File Structure

```text
appWeb/
├── .auth/
│   ├── .htaccess                      # Blocks web access
│   ├── db_credentials.example.php     # Template (tracked in git)
│   └── db_credentials.php             # Credentials (NOT in git)
├── .sql/
│   ├── schema.sql                     # Full MySQL schema (166 tables)
│   ├── install.php                    # Interactive table installer
│   ├── migrate-*.php                  # Individual, idempotent migrations
│   ├── migrate-users.php             # Legacy user/setlist migration
│   ├── cleanup.php                    # Token/session cleanup
│   └── backup.php / restore.php       # Database backup / restore
└── public_html/
    ├── includes/
    │   ├── db_mysql.php               # MySQLi connection factory
    │   └── SongData.php               # Song data — DB-direct, scoped reads (no JSON corpus cache)
    └── manage/
        ├── setup.php                  # Initial admin setup
        └── setup-database.php         # Web DB admin dashboard
```

### Troubleshooting

| Issue | Solution |
| --- | --- |
| "Database credentials file not found" | Copy `db_credentials.example.php` to `db_credentials.php` |
| "Failed to connect to MySQL" | Check host, port, username, password in credentials file |
| "Unknown database 'ihymns'" | Create the database: `CREATE DATABASE ihymns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` |
| "Table already exists" | Normal — the installer uses `CREATE TABLE IF NOT EXISTS` |
| "Migration failed — all changes rolled back" | Check error message; fix and re-run |
| Popular Songs shows "Loading..." | Database required for server-side view tracking; falls back to localStorage |
| Browse by Theme missing | Tags must be populated in `tblSongTags` via the admin tools |

### Architecture: Why MySQL (DB-direct)?

MySQL is the single source of truth for **all** reads and writes (epic #1010):
1. **Full-text search** — FULLTEXT indexes on title and lyrics
2. **Concurrent writes** — Multiple editors can safely modify data
3. **User accounts** — Relational storage for users, groups, permissions
4. **View tracking** — Popular songs ranking from `tblSongHistory`
5. **Tags & translations** — Structured relational data

There is **no server-side JSON fallback** for reads. A DB outage returns a
themed 503 maintenance page (#1021), never stale data. Offline support is the
**client PWA cache**:
- Service-worker-cached song pages for offline viewing
- The slim song index (`?action=songs_index`) cached client-side for the offline-download picker; there is no client-side fuzzy-search index — search itself always hits the live server (#1014/#1015)
- `songs.json` no longer exists as a tracked file or a runtime input of any kind (#1617)

### User Groups & Version Access

| Group | Alpha | Beta | RC | RTW |
| --- | --- | --- | --- | --- |
| Developers | Yes | Yes | Yes | Yes |
| Beta Testers | No | Yes | Yes | Yes |
| RC Testers | No | No | Yes | Yes |
| Public | No | No | No | Yes |

Users inherit access from their group. The app checks group permissions to gate access to deployment channels (Alpha = `dev.ihymns.app`, Beta = `beta.ihymns.app`).

---

## Content Access Tiers & CCLI Licensing

### Overview

iHymns uses a tiered content access system to control what songs, media, and features a user can access. Tiers are defined in `tblAccessTiers` and assigned per-user (`tblUsers.AccessTier`) or per-organisation (`tblOrganisations.LicenceType`).

**Content gating is OFF by default** (`content_gating_enabled=0` in `tblAppSettings`). Set to `1` to enforce tier restrictions.

### Default Tiers

| Tier | Level | Lyrics | Copyrighted | Audio | MIDI DL | PDF DL | Offline | CCLI Req'd |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **Public** | 0 | Yes | - | - | - | - | - | - |
| **Free** | 10 | Yes | Yes | - | - | - | - | - |
| **CCLI** | 20 | Yes | Yes | Yes | - | - | - | Yes |
| **Premium** | 30 | Yes | Yes | Yes | Yes | Yes | Yes | - |
| **Pro** | 40 | Yes | Yes | Yes | Yes | Yes | Yes | - |

Each tier inherits all capabilities of lower tiers. The `Level` column determines the hierarchy — higher level = more access.

### Tier Resolution (Personal vs Organisation)

A user's **effective tier** is the **highest** of:

1. Their personal `AccessTier` on `tblUsers`
2. Any tier inherited from their organisation memberships

For example:

- User has personal tier `free` (level 10)
- User belongs to a church with `ihymns_pro` licence (maps to `premium`, level 30)
- **Effective tier = `premium`** (the higher of the two)

This is resolved by `resolveEffectiveTier()` in `ccli_validator.php`.

### Organisation Licence → Tier Mapping

| Org LicenceType | Maps to Tier |
| --- | --- |
| `none` | public |
| `ihymns_basic` | free |
| `ccli` | ccli |
| `ihymns_pro` / `premium` | premium |
| `pro` | pro |

### CCLI Licence Numbers

CCLI (Christian Copyright Licensing International) licence numbers are:
- 5–8 digit numeric identifiers
- Assigned to churches/organisations for copyright compliance
- Validated via `validateCcliNumber()` in `ccli_validator.php`
- Stored on `tblUsers.CcliNumber` with `CcliVerified` flag

When a user enters a valid CCLI number:
1. Format validated (5-8 digits, numeric only)
2. Stored and marked as verified
3. If user is on `free` tier, auto-upgraded to `ccli`

### Adding New Tiers

To create a new tier:

```sql
INSERT INTO tblAccessTiers (Name, DisplayName, Level, Description,
    CanViewLyrics, CanViewCopyrighted, CanPlayAudio,
    CanDownloadMidi, CanDownloadPdf, CanOfflineSave, RequiresCcli)
VALUES ('church_basic', 'Church Basic', 15,
    'Church plan with copyrighted songs and audio.',
    1, 1, 1, 0, 0, 0, 0);
```

That's it — no code change needed. `checkTierAccess()` (`ccli_validator.php`) resolves a tier's
capabilities live from the `tblAccessTiers` row itself (`capsForTierFromRegistry()`), and
`admin_set_user_tier` validates a tier name against the same live table, not a hardcoded list —
both were deliberately rewritten (#1352/#1590, #1769 P0) so a curator-created tier works everywhere
immediately. See CLAUDE.md rule #28. A brand-new *gateable feature* (as opposed to a new tier) is a
one-line addition to the `TIER_CAPS` registry in `includes/access_tier_validation.php` — never a new
column and never a second hardcoded matrix.

### Pricing / payment integration

There is no payments or purchases table in the current schema — a guessed 2019-vintage
`tblUserPurchases` shape existed in `schema.sql` for years with no migration that ever created it
and no code that ever read or wrote it, and was formally dropped in the 2026-07-30 remediation pass.
A future paid-tier feature designs its own schema from scratch (rule #20 — one-pass, forward-looking)
rather than resurrecting that placeholder.

### Restricting Media Access

Audio playback, MIDI downloads, and PDF sheet music downloads are controlled by tier capabilities:

| Media Type | Controlled By | API Check |
| --- | --- | --- |
| Audio playback (MIDI) | `CanPlayAudio` | `?action=tier_check&check=play_audio` |
| MIDI file download | `CanDownloadMidi` | `?action=tier_check&check=download_midi` |
| Sheet music PDF | `CanDownloadPdf` | `?action=tier_check&check=download_pdf` |
| Offline song save | `CanOfflineSave` | `?action=tier_check&check=offline_save` |

The frontend should call `tier_check` before showing media controls. If denied, show an upgrade prompt with the `upgradeTo` tier from the response.

### Admin Management

Tier management is restricted to **Admin** and **Global Admin** roles only.

**API endpoints (Admin/Global Admin):**

| Endpoint | Method | Description |
| --- | --- | --- |
| `admin_set_user_tier` | POST | Set a user's access tier |
| `admin_set_user_ccli` | POST | Validate and set a user's CCLI number |
| `access_tiers` | GET | List all available tiers |

**Web dashboard:** `/manage/setup-database` for database administration.

---

## 🧩 Gating Registry — adding a gateable feature (#1352)

`tblAccessTiers` capabilities used to be **one TINYINT column per feature** — adding a
new gated capability meant an `ALTER TABLE` plus edits in the admin form, both API CRUD
endpoints, the API emit and the content-gating resolver. As of #1352 the capability set
is an **extensible registry**: `TIER_CAPS` in
`appWeb/public_html/includes/access_tier_validation.php` is the single source of truth,
and **new caps live in a `Capabilities` JSON column** (added by the additive
`migrate-add-tier-capabilities-json.php`) rather than getting their own column. The **7
original caps stay as TINYINT columns** because the native-app API contract reads them
straight off their own columns (their camelCase emit keys are the contract — never
re-home an existing column cap into JSON).

### ⭐ The canonical how-to

> **To add a new gateable feature, add ONE line to `TIER_CAPS` in
> `includes/access_tier_validation.php`**, e.g.
>
> ```php
> 'CanRequestSongs' => ['Requests', 'Submit song requests', 'json', 0],
> ```
>
> **then run the JSON-backed tier-capabilities card on `/manage/setup-database`** (the
> `migrate-add-tier-capabilities-json.php` migration). The admin checkbox
> (`manage/tiers.php`), both API CRUD endpoints (`admin_tier_create` /
> `admin_tier_update`), the `access_tiers` API emit (as `canRequestSongs`) and content
> gating (`checkTierAccess`) **all pick it up with NO schema change**.

That's the whole change — one array line plus running an already-shipped migration card.
No `ALTER`, no per-surface edits, no new column.

### Tuple shape

Each `TIER_CAPS` entry is a 4-tuple `[short_label, full_description, storage, default]`:

| Element | Meaning |
| --- | --- |
| `short_label` | Column header on `/manage/tiers.php` |
| `full_description` | Tooltip / checkbox hint |
| `storage` | `'column'` (own TINYINT — the 7 originals) or `'json'` (a key in `tblAccessTiers.Capabilities`) |
| `default` | `0` / `1` — value assumed when the JSON key is absent or the `Capabilities` column hasn't been migrated yet |

`tiers.php` destructures only `[$lbl, $hint]` from each tuple — PHP list-destructuring
binds the first two and ignores the rest, so widening originals to 4-tuples was a no-op.

### Why JSON, not ENUM or one-column-per-feature

This follows **CLAUDE.md rule #20** (a growable vocabulary is JSON/VARCHAR, app-validated
against a central map — never an `ENUM`, never an `ALTER` per value). A new cap is an
additive JSON key, so it works on every env the moment the (single, one-time)
`Capabilities`-column migration has run — and **degrades to its declared default on an
un-migrated install**, never throwing under STRICT.

### Helpers

`access_tier_validation.php` exposes the registry plumbing every surface uses:
`tierCapStorage()` / `tierCapDefault()` (read the 3rd/4th tuple slot), `tierCapColumnKeys()`
/ `tierCapJsonKeys()` (split the registry by storage), `tierCapRead($tierRow, $key)`
(read a cap's effective 0/1 off a fetched row — column-backed reads its own field,
JSON-backed decodes `Capabilities` and falls back to the default), and
`tierCapsColumnExists($db)` (request-cached `INFORMATION_SCHEMA` probe — the CRUD writers
and the emit gate every read/write of the `Capabilities` column on this, because mysqli
runs under STRICT so touching a non-existent column **throws**).

---

## 🔒 Content-Gating Enforcement (#1353)

The gating **tiers** (public/free/ccli/premium/pro) were **advisory** until #1353 — the
API emitted full song data regardless of tier and the native apps were trusted to
self-enforce. #1353 makes the **server** the enforcement point.

### `contentGatingApply()` — the strip

`appWeb/public_html/includes/content_gating.php` exposes
`contentGatingApply($song, $userId, $platform)`, called on the built
`song_detail` / `song_data` / `random` payloads **immediately before they are emitted**
(api.php `song_detail`, `song_data`, and the `random` song path). Given a requester's
effective tier (anonymous → `public`) it strips fields the tier may not see/use:

| Denied cap | What it strips |
| --- | --- |
| `!view_lyrics` (or copyrighted song + `!view_copyrighted`) | the lyric **body** (`components`) + per-line/whole-song `translations` + `annotations` + `vocalParts`; adds `contentRestricted=true` + `restrictionReason`. Metadata (id/number/title/songbook/credits) is **kept** so the client can show a locked card + upgrade prompt |
| `!play_audio` | drops `audio`-kind media; `hasAudio=false` |
| `!download_midi` | drops `midi`-kind media |
| `!download_pdf` | drops `sheet-music` + `musicxml` media; `hasSheetMusic=false` |
| `!offline_save` | `offlineAllowed=false` |

"Copyrighted" keys on the **lyrics axis only** (`lyricsPublicDomain === false`) — never
AND-ed with the music axis (PD gating is per-axis; see MEMORY).

### Registry-driven, not a hardcoded matrix

Per-cap decisions go through `checkTierAccess()` in `ccli_validator.php`, which
**since #1353 resolves caps from the LIVE `tblAccessTiers` row** (via
`capsForTierFromRegistry()`) and overlays them on the fallback matrix. So a curator's edits **and** any new one-line
JSON cap (#1352) are enforced automatically, with no edit to the matrix. A `null` return
(un-migrated DB / unknown tier / read threw) leaves the fallback matrix exactly as before
— byte-identical to pre-#1353. The CCLI-tier gate (verified CCLI number required for
`view_copyrighted` / `play_audio`) is unchanged.

### Entirely dormant + STRICT-safe

Three locked rules (do not relax):

1. **Master switch.** Every public function is a verified **NO-OP** — returns byte-identical
   data — unless `getAppSetting('content_gating_enabled','0') === '1'`. The flag is `'0'`
   by default, so shipping this changed **nothing** on any live env.
2. **Caps come from the registry** (above) — never a hardcoded tier→cap matrix here.
3. **Fail-open.** The 3 docroots share one MySQL and migrations are NOT auto-applied, so a
   request can hit a half-migrated env; every optional read is wrapped and the helper
   returns the song **unchanged** on any uncertainty (the master switch is the real gate —
   this module only trims within an already-opted-in deployment). Failing open here beats
   white-screening a public read (the #1228 lesson).

### `bulk_audio` offline manifest

The PWA offline-audio manifest (api.php `bulk_audio`) is **also entity-gated** when the
flag is on: a restricted song's audio is dropped from the manifest via
`checkBulkAccess('song', …, 'display')` (the entity model, mirroring the gated
`song-media.php` route the manifest sidesteps). NO-OP + fail-open when the flag is off.

**TIER-gated too, since #1388.** `checkBulkAccess()` above only answers "is this song
restricted?" — it says nothing about the caller's tier. `contentGatingMediaAllowed('audio',
$userId, $presenceToken)` (below) is resolved once per request (`play_audio` is a
per-requester cap, not per-song) and, when denied, empties the manifest wholesale rather
than filtering per entry. When `audioSigningEnabled()` is also on (#1358), surviving URLs
are additionally rewritten from the static `/data/audio/<id>.mp3` literal to a short-lived
signed `/audio/<id>.mp3?exp=…&sig=…`.

### `contentGatingMediaAllowed()` — gating the BYTES, not just the payload (#1388)

`contentGatingApply()` above decides which media **links** a `song_detail`/`songbook_export`
payload is allowed to show. Stripping a link from a payload hides the affordance; it does
**not** protect the file — `/song-media/<id>` is a plain URL, bookmarkable, shareable, and
guessable by id, still served long after the row vanished from someone's response. Before
#1388, `song-media.php` applied only the **entity** gate (`checkContentAccess`) and no tier
cap at all — so the instant gating went live, a `public`-tier visitor could still stream
premium audio by hitting the URL directly.

`contentGatingMediaAllowed($kind, $userId, $presenceToken)` closes that gap. It is a
**second gate**, applied AFTER the existing entity gate in `song-media.php` (403 on denial,
generic reason text — it must never disclose which tier would suffice), and mirrors
`contentGatingApply()`'s kind→cap mapping deliberately rather than re-deriving it (rule
#28B — a cap that hides the button but not the file, or vice versa, is worse than neither):

| Kind | Cap |
| --- | --- |
| `audio` | `play_audio` (OR a live Service-Mode presence CCLI unlock, rule #26) |
| `midi` | `download_midi` |
| `sheet-music` / `musicxml` | `download_pdf` |

Same three locked rules as `contentGatingApply()` (master switch / registry-driven /
fail-open-and-log). Consumed by `song-media.php` (the byte-serving route) and the
`bulk_audio` manifest above; `songbook_export` (api.php) uses `contentGatingApply()`
directly, the same resolver `song_detail` uses, so a public-tier requester pulling a whole
songbook in one call is stripped per-song exactly like a single-song read — previously that
endpoint carried an entity gate only and was the widest lyric leak in the API once gating
went live.

### ⚠ Known follow-ups (not yet sealed)

- **The static `/data/audio/<SongId>.mp3` file is directly fetchable.** Gating the
  `bulk_audio` manifest stops a restricted song's audio being **pre-cached**, and #1358's
  signed `/audio/<id>.mp3` route exists, but the static MP3 itself is still served directly
  by Apache until an operator uncomments the `.htaccess` deny block **per environment,
  after verification** (see the block's own comments) — still true as of #1388, which did
  not touch this.

`song.php`'s tier + entity enforcement (previously listed here as a gap) was closed by
#1357 — see "Tier-aware gating on the web + offline path" in the root `CHANGELOG.md`; the
web page and the API now compose the same two axes.

---

## 🚦 Read Rate Limiting (#1354)

The heavy **public** reads are sessionless and were uncapped, so a scraper could hammer
them. #1354 adds a cheap fixed-window counter.

### `enforceReadRateLimit()` + `tblReadRateLimit`

`appWeb/public_html/includes/read_rate_limit.php` exposes
`enforceReadRateLimit($scope, $perMin, $perDay = 0)`, called at the top of the heaviest
public read actions in `api.php`. It counts the request in a per-minute (and optional
per-day) bucket in `tblReadRateLimit` (added by `migrate-add-read-rate-limit.php`); over
the limit it emits **429 + `Retry-After`** (plus `X-RateLimit-Limit` / `X-RateLimit-Window`)
and `exit`s. Each `$scope` is an independent budget sharing one table, so new endpoint
limits need **no further migration** (rule #20).

### Per-endpoint limits (per UTC minute)

| Endpoint | Scope | Limit |
| --- | --- | --- |
| `song_detail` | `song_detail` | 240/min |
| search | `search` | 120/min |
| `songs_index` | `songs_index` | 120/min |
| `related_songs` | `related_songs` | 240/min |
| `songbook_export` | `export` | 60/min |
| bulk (`bulk_songs` / `bulk_audio`) | `bulk` | 60/min |

Limits are deliberately **generous** — real clients never trip them; abusive volume does.

`songbook_export` moved to its own `export` bucket (#1571) — it used to share
`bulk` with `bulk_songs`/`bulk_audio`, so a curator's export click could
contend with a device's background offline sync for the same counter even
though the two are unrelated actors. Same 60/min limit either way; the split
is purely about independence, and `$scope` being a free string (rule #20)
made it a one-word change with no schema impact.

### Keyed per token-or-IP

The bucket key is **per session token where one is present** (`tok:<sha256hex>`), else
**per IP** (`ip:<REMOTE_ADDR>`). Per-token-first because a congregation of native apps
behind one NAT egresses as **one IP** — bucketing those by IP would throttle the whole
room like a single scraper (same NAT lesson as Service Mode rule #26's per-presence
limiting); a per-device token gives each its own budget. The token is accepted only in the
strict 64-hex shape `getAuthBearerToken()` uses and is **not** validated against the
sessions table (that would cost a query per request) — a forged 64-hex token simply gets
its own generous bucket, and the per-IP backstop still covers the no-/junk-token scraper.
`REMOTE_ADDR` only (never `X-Forwarded-For`, which a client can forge to mint unlimited
buckets); the raw token is hashed, never stored.

### Fail-open + dormant until migrated

Every DB touch is `try/catch`'d and the table is existence-gated (request-cached): if
`tblReadRateLimit` is absent (un-migrated install) or **any** error occurs, the request is
**allowed**. A rate limiter must never take the site down (#1228). Window boundaries are
computed **SQL-side** (`DATE_FORMAT(UTC_TIMESTAMP(), …)`), not PHP `time()`, so there's no
per-node clock skew between truncation and comparison. Mirrors `apiKeyEnforceRateLimit()`
in `includes/api_keys.php` (the API-key-auth variant against `tblApiKeyUsage`).

---

## 🛡 API CSRF Model — `validateCsrfRequest()`

The page-form model (`csrfToken()` + `validateCsrf()`, a token baked into the rendered
page and compared against `$_SESSION['csrf_token']`) is fine for short-lived admin
**forms** but went **stale** on long-lived AJAX surfaces — the song editor and the
duplicate-songs page. On those pages the baked token can rotate / be GC'd / differ across
tabs while the page stays open, so a perfectly legitimate POST then carries a stale token
and is rejected. **That is the sporadic "CSRF error" the owner saw on merge / delete /
edits.**

### `validateCsrfRequest(?string $token = null)`

New in `appWeb/public_html/manage/includes/auth.php`. It accepts the request when
**EITHER**:

- **(a)** a valid session token was supplied (back-compat — existing form callers still
  work), **OR**
- **(b)** it is a genuine **same-origin AJAX** request: the custom header
  `X-Requested-With` is present (a browser **cannot** set it on a cross-origin request
  without a CORS preflight this server never grants), **AND** any present `Origin` / `Referer`
  host matches this site (an explicit cross-origin host is rejected).

**Tightened in #1388:** `X-Requested-With` alone is **no longer** sufficient when
**both** `Origin` and `Referer` are absent. The header proves a browser didn't send the
request cross-origin; it does not prove the request came from a browser at all — any
non-browser client can set it freely, and on its own it carries no positive evidence of
origin. Per the Fetch spec, a real browser sends `Origin` on **every** request whose
method is not GET/HEAD, whatever the page's `Referrer-Policy`, so absent-both means "not
a browser POST" and the request now falls back to requiring a valid session token (path
(a) above, already failed if execution reaches this check). The rejection is **logged**
(method + URI), not silent — this tightens the exact path #1590 built to stop the
sporadic editor "CSRF error", so a false rejection surfaces as one clear log line instead
of a mystery.

Either signal alone is an OWASP-recognised CSRF mitigation; together they're robust and —
crucially — **never go stale**. State-changing AJAX endpoints call this instead of
`validateCsrf()` directly.

### Where it's wired

- **Duplicate-songs** merge / delete handlers (`manage/duplicate-songs.php`).
- **Places API** (`manage/places-api.php`).
- A **top-level POST gate on ALL legacy `/manage/editor/api.php` writes** — every
  state-changing POST must pass `validateCsrfRequest($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)`
  or it 403s; GET reads are unaffected. The editor clients already send `X-Requested-With`.
- The **v2 editor `api2.php`** requires this same same-origin signal directly
  (`X-Requested-With: XMLHttpRequest` on every POST) — which is why `save_song` called
  from the **legacy** editor's client (`editor.js`'s `ed2EnrichApi`, which already sent
  it) always worked, and the model #1289 ("Here to Stay" 403s) generalised. **The v2
  editor SHELL's own client did not send it** — until #1677 (P0, fixed 2026-07-30),
  neither write helper in `manage/editor/v2/api-client.js` carried the header, so every
  mutation from the v2 UI (save, create, delete, component writes, bulk actions,
  revision restore, the enrichment endpoints) 403'd while reads (GETs) worked fine.
  Fixed client-side, not by loosening the gate; `tests/php/test-editor-api2-contract.php`
  now derives both the client's header usage and its action list from source so this
  can't regress silently.

---

## 💾 `save_song` → v2 Editor API (shared core)

The whole-song save (the legacy `save_song` action, #394) was extracted out of the legacy
editor `api.php` into a **shared core** so the v2 API and the legacy API run the **same**
save code — no forked save logic, no drift in the project's primary write path (CLAUDE.md
modularity rule).

- **The core:** `editorSaveSongCore()` in
  `appWeb/public_html/manage/editor/save_song_core.php`. A behaviour-preserving extraction
  of the legacy `case 'save_song':` — the SQL, transaction, component/credit/revision/ISWC-Works
  write logic is byte-for-byte the original; the **only** change is response **emission**:
  every inline `http_response_code(N); echo …; break;` now `return`s
  `['status' => N, 'body' => X]` so each caller emits in its own house style.
- **Both APIs serve it:** the legacy `api.php` keeps a back-compat shim, and `api2.php`
  routes `?action=save_song` through `editorSaveSongCore()` (then `ed2_respond()`).
- **The editor POSTs the save to api2** (`window.IHYMNS_EDITOR_API2 + '?action=save_song'`)
  under its `X-Requested-With` CSRF gate — so the save rides the never-stale same-origin
  check (above) rather than the page-baked token.

The core stays Id-stable for lyric lines (`lyricLinesWriteComponents()`, the #1235 P4/C5
inverted write path: `tblLyricLines` authoritative, JSON columns a gated shadow) and
remains schema-tolerant (probes for `ArrangementJson`, `tblSongArtists`, places columns,
the credit-people name-parts columns, etc.) so a partly-migrated install never 500s the
save.

---

## Admin Portal (`/manage/` + `/admin/` alias)

| Route | Purpose | Entitlement |
| --- | --- | --- |
| `/manage/` | Dashboard (library + activity stats) | `view_admin_dashboard` |
| `/manage/editor/` | Song editor — 302-redirects to the v2 editor by default (#1601); `?legacy=1` per-visit or `tblAppSettings.editor_v2_default='0'` fleet-wide reverts to the legacy v1 editor, which is not retired | `edit_songs` |
| `/manage/duplicate-songs` | Duplicate & counterpart detection / cross-book linking / merge (absorbed the old `/manage/song-link-suggestions`, now a 302) | `edit_songs` (Merge = `manage_duplicate_songs`) |
| `/manage/works` | Works (composition grouping across songbooks) | `edit_songs` |
| `/manage/external-link-types` | External-link provider registry + URL patterns | `manage_songbooks` |
| `/manage/catalogues` | Catalogues — user-labelled "Collections" (internal name stays `catalogue`) | `manage_songbooks` |
| `/manage/tags` | Theme/tag vocabulary + canonicalisation merge (CCLI/OpenLyrics standard themes) | `edit_songs` |
| `/manage/venues` | Org venues + recurring service schedules (Service Mode) | org-admin |
| `/manage/service-projection.php` | Service Mode projector page (rotating join code + broadcast) | org-admin |
| `/manage/service-lead.php` | Service Mode leader device (connect-and-drive broadcaster) | org-admin |
| `/manage/users` | Users + roles | `view_users` / `edit_users` |
| `/manage/requests` | Song-request triage queue | `review_song_requests` |
| `/manage/analytics` | Usage analytics with CSV export | `view_analytics` |
| `/manage/entitlements` | Reassign capabilities to roles | `manage_entitlements` |
| `/manage/setup-database` | Install, migrate (registry-driven "Apply all pending"), backup, restore | `run_db_install` etc. |
| `/manage/login` · `/manage/logout` · `/manage/setup` | Auth flow (session-based) | — |

### Entitlements

Defined in `appWeb/public_html/includes/entitlements.php` (PHP, authoritative) and mirrored in `appWeb/public_html/js/modules/entitlements.js` (UI affordance only). Runtime overrides stored in `tblAppSettings.SettingKey = 'entitlements_overrides'` as JSON and merged over the hardcoded defaults by `effectiveEntitlements()`. Admins edit the mapping at `/manage/entitlements` — the form prevents removing `global_admin` from the `manage_entitlements` entitlement so the editor can never be locked out.

Check PHP: `userHasEntitlement($name, $role)`. Check JS: `userHasEntitlement(name, role)` (same signature).

### Channel Gating

`alpha.ihymns.app` and `beta.ihymns.app` require the `access_alpha` / `access_beta` entitlements respectively. Production (`ihymns.app`) is never gated. Gate logic lives in `includes/channel_gate.php` and runs from `index.php`. The gate page embeds the magic-link sign-in form so users can authenticate without leaving.

### Auth + persistence

- Bearer token stored in both `Authorization: Bearer` header (JS fetches) and as `Set-Cookie: ihymns_auth; Domain=.ihymns.app; HttpOnly; SameSite=Lax; Secure` (cross-subdomain).
- Sliding 30-day expiry via `slideAuthTokenExpiry()` — bumps `tblApiTokens.ExpiresAt` at most once per day per token.
- Client `user-auth.js#verify()` only clears credentials on 401/403 (not on any non-ok), so a transient 500 doesn't sign the user out.

### CSRF

Every write-performing admin page (`users`, `setup`, `login`, `setup-database`, `requests`, `entitlements`) uses `csrfToken()` + `validateCsrf()` from `manage/includes/auth.php`. The token is rendered as a hidden input named `csrf_token`.

### Combining with Other Access Controls

Content tiers work alongside other gating mechanisms:

1. **Content Restrictions** (`tblContentRestrictions`) — block specific songs/songbooks by org/user/platform
2. **User Groups** (`tblUserGroups`) — version channel access (Alpha/Beta/RC/RTW)
3. **Organisation Licences** (`tblContentLicences`) — per-org songbook and feature access
4. **Tiers** — broad capability levels for media and content types

The most restrictive rule wins when multiple systems overlap.

---

## 📡 Observability (#1581 / #1582 / #1583)

- **Event-name unification (#1581)** — custom DOM event names live once in `js/constants.js` (the `EVT_*` exports, e.g. `EVT_AUTH_CHANGED = 'ihymns:auth-changed'`). `tests/test-event-names.js` bans raw `ihymns:*` / `iHymns:*` string literals anywhere else in `appWeb/public_html/js/**/*.js` (a small allow-list aside) and cross-checks that every exported constant has both a dispatcher and a listener — this is the guard against the class of bug where a differently-cased spelling silently creates two unrelated events with no error.
- **Client error surfacing (#1582)** — `js/modules/error-monitor.js` catches uncaught errors and unhandled promise rejections. Each one shows a single generic toast and, independently, is scrubbed and beaconed (`POST /api?action=client_error_report`) to `tblActivityLog` (`EntityType='client'`, `Action=client.jserror`) for review at `/manage/activity-log`. Both the toast and the beacon are throttled (the beacon has three independent layers: a per-fingerprint cooldown, a hard cap of 10 beacons per page load, and a minimum gap once past 3 beacons); the server (`includes/client_error_report.php`) re-scrubs every payload rather than trusting the client.
- **What's New page (#1583)** — the deploy workflow extracts the top three `## ` sections of `CHANGELOG.md` into `public_html/data/whats-new.md`, rendered by `/whats-new` through the escape-first `includes/markdown_lite.php`. No database involved; it is a shared-cache fragment (same excerpt for every visitor) reached from the footer version number and the environment-badge dropdown.

---

## 🔌 Shared API Client — `apiFetch()` (#1031)

**Use `apiFetch()` / `apiFetchJson()` from `js/utils/api-client.js` for every request a
page module makes. Never call bare `fetch()` for an app request, and never reinstall a
global `window.fetch` override — that pattern is gone and must not come back.**

### Why not a `window.fetch` monkey-patch

`songbook-language-filter.js` used to attach `X-Preferred-Languages` by **replacing**
`window.fetch` globally. That shipped two failure modes this codebase actually hit:

1. **It sits in front of every request on the site**, so a bug in the header logic isn't
   a missing header — it's a **failed request**, surfacing wherever the caller happens to
   catch it. #1593: the patch read `input.url` on a `URL` object (which exposes `href`,
   not `url`), got `undefined`, called `.startsWith()` on it and threw — ten unrelated
   call sites broke, presenting to a user as "Song of the Day disappears when I pick two
   languages."
2. **It only applies if something installed it.** The patch was installed by
   `bootSongbookLanguageFilter()`, which the router imports only on home/songbooks/settings.
   A cold load of `/search` never installed it, `search.js` sent no `lang=` param, and an
   **anonymous** user's saved language filter was silently ignored — not previously
   reported, found while scoping #1031.

Both are the same species of bug: a cross-cutting concern attached by side effect rather
than by structure. `apiFetch()` reads the preference on every call instead, so it cannot be
half-installed.

### API

- **`apiFetch(input, init)`** — signature-compatible with `fetch()`, plus one extra option:
  `{ auth: true }` requests the injected `Authorization`/cookie headers. Migrating a call
  site is usually just renaming `fetch` → `apiFetch`. Attaches `X-Requested-With` (the
  same-origin signal `validateCsrfRequest()` / rule #29 wants), `X-Preferred-Languages`
  (read fresh from `localStorage` on every call, never cached at install time), and — when
  `auth: true` — the caller's auth headers via an injected provider, all **same-origin
  only**, and **never clobbering a header the caller already set**.
- **`apiFetchJson(input, init)`** — `apiFetch()` + `.json()`, with the #1566 SPA-catch-all
  trap handled: this app's `.htaccess` answers any unmatched path with **200 + the HTML
  shell**, so a wrong URL doesn't 404 — `response.ok` passes and `.json()` throws a
  baffling `SyntaxError`. `apiFetchJson()` detects the non-JSON content type and raises a
  clear error naming the URL instead.
- **`setAuthHeaderProvider(fn)`** — registered once at boot (`app.js`) with
  `() => this.userAuth.authHeaders()`. Auth headers arrive by **injection**, not by
  importing `user-auth.js` directly, because `user-auth.js` → `api-client.js` →
  `user-auth.js` is a cycle bundlers resolve in load order — i.e. sometimes `undefined` at
  call time, non-deterministically.
- **`requestUrlOf(input)`** — resolves the request URL from any of `fetch()`'s three
  accepted shapes (`string | URL | Request`), always returning a string. This is the #1593
  fix, now the one place that logic lives.

### What it deliberately does NOT do

It does not wrap `window.fetch`. Anything still calling bare `fetch()` keeps working
exactly as before — this is additive, never a global override — but new/touched call sites
should use `apiFetch()` so the language filter and CSRF header actually apply. The service
worker (`service-worker.js.php`) deliberately keeps native `fetch`: it runs in a different
global scope, has no `localStorage`, and its requests are not user-scoped.

### Connectivity signalling

`apiFetch()` dispatches `EVT_FETCH_FAILED` / `EVT_FETCH_SUCCEEDED` (from `js/constants.js`,
#1581) on every request — a network-level throw (DNS/offline/CORS block) fires
`EVT_FETCH_FAILED`, and a `503` response (this app's documented maintenance/DB-outage
state, WS-K #1021) fires it too but tagged `maintenance: true` rather than being treated as
a network failure, since the server plainly answered. The offline indicator now has
sitewide reach for free; previously only `search.js` dispatched these by hand, and that
hand-rolled pair was deleted in the same migration to avoid a double-fire.

### Two files deliberately NOT migrated (verified, not assumed)

- **`place-search.js`** — loaded as a classic `<script src>` on seven admin pages with zero
  `import`/`export` statements; a static `import` would break it outright, and a dynamic
  `import()` broke `test-place-search-keyboard.js` under jsdom. It also turned out
  `places-api.php` never reads `X-Preferred-Languages`, so the premise for migrating it —
  language-sensitivity — was simply wrong.
- **`service-broadcast.js`** — has no direct `fetch()` calls; its network access goes
  through an injected `apiCall()` closure defined inside `<script type="module">` blocks in
  two PHP files.

---

## 🤖 Auto-Merge for Alpha PRs

PRs whose **base branch is `alpha`** are automatically merged once all required status checks (and reviews, if configured) pass. PRs targeting `main`, `beta`, or any other branch are **not** affected.

### How it works

The workflow `.github/workflows/auto-merge-alpha.yml` triggers on `pull_request` events scoped to `branches: [alpha]`. It runs a single command:

```bash
gh pr merge --auto --squash "$PR_URL"
```

This flips on GitHub's native **auto-merge** flag for the PR. GitHub itself then watches the PR and merges it the moment all gates are green. If checks fail, the PR stays open — nothing is merged.

Drafts are skipped (`if: github.event.pull_request.draft == false`) because GitHub refuses to enable auto-merge on draft PRs.

### One-time repo configuration (required)

The workflow alone is not enough — auto-merge is a repo-level feature:

1. **Settings → General → Pull Requests → "Allow auto-merge"** must be ticked. Without this, the `gh pr merge --auto` call errors out.
2. **Settings → Branches → Branch protection rule for `alpha`** should require at least one status check (e.g. the `CI Lint & Validation` job from `test.yml`). Without required checks, auto-merge has nothing to wait on and would merge instantly.
3. (Optional) Add **"Require pull request reviews"** to the same `alpha` branch protection rule if you want a human approval gate. Auto-merge respects it.

### Knobs to tweak

| What | Where | How |
| --- | --- | --- |
| Merge strategy | `run:` line in `auto-merge-alpha.yml` | `--squash` → `--merge` (merge commit) or `--rebase` |
| Add more target branches | `on.pull_request.branches` | Add entries, e.g. `- beta` |
| Restrict to specific authors | Add `if:` to the job | e.g. `github.event.pull_request.user.login == 'dependabot[bot]'` |
| Disable for a single PR | Anywhere with write access | `gh pr merge --disable-auto <PR>` |

### How to verify it works

Open a test PR from any branch into `alpha`. Within ~30s the workflow runs, and the PR's merge box will show **"Auto-merge enabled"**. Push a failing commit → it stays unmerged. Fix it → it merges itself.

### Troubleshooting

- **Workflow fails with "auto-merge is not allowed"** → Repo setting #1 above is off.
- **PR merges immediately with no checks** → No required status checks on `alpha` branch protection.
- **Workflow doesn't run** → PR is a draft, or its base isn't `alpha`. Both are intentional.
- **PR has merge conflicts** → Auto-merge waits silently until conflicts are resolved.

---

## 🔗 External Links System (#833) and Auto-Detect Module (#841)

### Schema

External links live in a controlled-vocabulary registry plus per-entity
sibling tables:

| Table | Owns the relationship for |
| --- | --- |
| `tblExternalLinkTypes` | The seeded provider registry (~37 types: Wikipedia, Hymnary.org, MusicBrainz, IMSLP, Spotify, YouTube, …) — see `migrate-external-links.php` |
| `tblSongbookExternalLinks` | `tblSongbooks` ↔ link types |
| `tblSongExternalLinks` | `tblSongs` ↔ link types |
| `tblCreditPersonExternalLinks` | `tblCreditPeople` ↔ link types |
| `tblWorkExternalLinks` | `tblWorks` ↔ link types (#840) |

`tblExternalLinkTypes.AppliesTo` is a `SET('song','songbook','person','work')`
that decides which entity types each provider applies to. The Works
migration (`migrate-works.php`) widens this set + seeds the `'work'`
flag on the appropriate provider rows.

Per-entity rows carry: `Url` (≤ 2048 chars), `Note`, `SortOrder`,
`Verified`, `CreatedAt`, `UpdatedAt`. FK on the entity is
`ON DELETE CASCADE`; FK on the link type is `ON DELETE RESTRICT`
(so the registry can't be accidentally trimmed under live data).

### Read path

`SongData::_externalLinksMap($entityType, $keys)` is the generic
helper. Probe-gated on the relevant `tblXxxExternalLinks` table;
joins `tblExternalLinkTypes` and excludes rows where `IsActive = 0`
(soft delete). Returns a map keyed by Abbreviation / SongId /
CreditPersonId.

### URL → Provider auto-detect (#841 / DB-driven in #845)

Single source of truth in
`appWeb/public_html/js/modules/external-link-detect.js`, exposed on
`window.iHymnsLinkDetect`:

- `detectFromUrl(url)` → slug or `null`
- `slugToOptionValue(selectEl, slug)` → numeric option value or `''`
- `attachAutoDetect(rowEl, opts)` → teardown function

Loaded on every `/manage/*` page by `manage/includes/head-libs.php`,
so each per-page row builder calls
`window.iHymnsLinkDetect.attachAutoDetect(card)` and inherits the
behaviour without an extra script tag.

**Rule source (#845):** patterns live in `tblExternalLinkPatterns`.
Each row is `(LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority,
IsActive, Note)`. Lower `Priority` numbers win, so more-specific
patterns (path-discriminated MusicBrainz, `music.youtube.com`) sit
ahead of broader ones (`youtube.com`).

The pages that ship `window._iHymnsLinkTypes` to their row builders
attach each type's patterns via
`attachExternalLinkPatterns($db, $types)` from
`includes/external_link_helpers.php`. The JS module reads from there
first; on pre-migration deployments (or pages that don't expose
`_iHymnsLinkTypes`) it falls back to the hard-coded `RULES` array
bundled with the module.

**Adding a new provider — either:**
1. Insert a row at `/manage/external-link-types` (no code deploy), or
2. Append to the `RULES` fallback array in the JS module (covers
   pre-migration deployments).

**Manual-choice override:** `data-user-picked="1"` is stamped on the
`<select>` once the user changes it explicitly. The detector reads
that attr and bails when set.

---

## 🎼 Works (#840) — composition grouping

`tblWorks` groups multiple `tblSongs` rows representing the same
composition across different songbooks / arrangements / translations.
Mirrors the MusicBrainz Work ↔ Recording relationship.

### Schema

```
tblWorks
  Id               PK
  ParentWorkId     self-FK, ON DELETE SET NULL → unlimited nesting
  Iswc             CHAR(15), UNIQUE, optional
  Title            VARCHAR(255)
  Slug             VARCHAR(80), UNIQUE
  Notes            TEXT
  CreatedAt / UpdatedAt

tblWorkSongs
  WorkId, SongId   composite PK
  IsCanonical      flag (zero or one per Work, by convention)
  SortOrder
  Note

tblWorkExternalLinks
  same shape as the per-entity link tables (see above).
```

### Read path

`SongData::_worksMap($songIds)` bulk-attaches `works` to song rows;
each Work entry includes its `members` (sibling versions across the
catalogue) and Work-level `links`. `SongData::getWork($slugOrId)`
returns the full Work payload for the public `/work/<slug>` page.

### Cycle prevention

Application-side at update time (`manage/works.php`'s
`$cycleSafe` closure walks the parent chain with a depth cap of 64).
MySQL has no native cycle constraint and we don't otherwise rely on
stored procedures, so this is the cheapest correct guard. The depth
cap doubles as a "table somehow already inconsistent" circuit-breaker.

---

## 📱 Admin list-view responsive convention (#842)

Every admin list table that opts into `.admin-table-responsive` gets
a column-priority hide-on-narrow rule from the global stylesheet
(`appWeb/public_html/css/admin.css`).

Mark each `<th>` AND its corresponding `<td>` with one of:

| Priority | Hides at |
| --- | --- |
| `data-col-priority="primary"` | never |
| `data-col-priority="secondary"` | ≤ 768 px |
| `data-col-priority="tertiary"` | ≤ 992 px |

When adding a new admin list page: add the class to the `<table>`
and the `data-col-priority` attribute to every `<th>` and `<td>`.
That's it — no per-page CSS.

The opt-in shape (rather than auto-applying to every `.table`) keeps
the blast radius zero on existing pages while we roll the convention
forward.

Pages currently opted in: Credit People, Songbooks, Songbook Series,
Works.

---

## 🔌 MWBM-IntAppsAPI gateway integration (Epic #1725)

Server-proxied, cache-first, fail-open client for the MWBM-IntAppsAPI
gateway — an operational feature-flag kill switch, structurally
separate from content gating (`TIER_CAPS`/`checkTierAccess()`/
`contentGatingApply()`/`gatingRulesApply()`/`checkContentAccess()`
never consult it, and vice versa). Shipped **entirely dormant**:
`tblAppSettings.intappsapi_enabled_channels` ships with no row at all,
and with it absent the whole integration is a byte-identical no-op —
proved against the real local instance (merge-base vs branch tip,
`/api.php?action=app_status` + five other endpoints, empty diff; zero
`tblIntAppsSync` references in MySQL's general query log across the
same requests; three mutations — forcing `remoteFeatures` to emit
unconditionally, removing the `intappsEnabled()` guard on the cache
read, and pointing a capture at extensionless `/api` — each proven to
turn the relevant check red before being reverted).

**Where things live:** `includes/intapps_client.php` (the whole
client — signer, single-flight cache, fail-open transport),
`tblIntAppsSync` (one dormant cache/bookkeeping table, migration
`appWeb/.sql/migrate-add-intapps-sync.php`), the admin surfaces
(`/manage/configuration`'s IntAppsAPI card + `/manage/intapps-status`),
the one shipped consumer (`includes/pages/home.php`'s
`intappsFlag($db, 'web.sotd_card', true)`), and the local stub gateway
fixture + e2e suite (`tests/php/fixtures/intapps-stub-gateway.php` +
`tests/php/test-intapps-stub-e2e.php`) — see that fixture's own
docblock for why it can never accidentally deploy.

**THE SIGNER.** Verified against the gateway's own source
(`web/src/Helpers/HmacValidator.php`, `mwbm-intappsapi` repo, pinned
commit `6816ed880c8b37b5814a6a5321c7992d0ee6c007`): the canonical
string is `rawBody . '.' . unixTimestamp`, hex-HMAC-SHA256'd. The
gateway's own five bundled client examples sign
`METHOD|PATH|TIMESTAMP|BODY` instead — **all five are wrong** (filed
upstream as MWBM-intAppsAPI#120). `intappsSign()` is implemented
against the source, never the examples, and the stub-gateway e2e suite
proves the examples' wrong string is rejected by a verifier ported
line-for-line from the same source.

**Enablement is a per-channel allow-list** (`intappsapi_enabled_channels`
— comma-separated `alpha`/`beta`/`production`/`all`), the same
mechanism `apple_web_login_enabled` already uses, so alpha can canary
without touching production — all three docroots share ONE MySQL.

**Accepted residuals (recorded here, not silently assumed away):**

1. **Stub drift (N2).** The local stub gateway certifies the signer
   against gateway commit `6816ed8` only. If the deployed gateway has
   since diverged from that commit, the stub's green result says
   nothing about the live server. No cross-repo CI can enforce this —
   Issue A / #1726's acceptance criteria include recording the live
   `GET /v1/status` version and confirming `HmacValidator.php` is
   unchanged since that SHA before any real channel is enabled.
2. **Per-SAPI winner-pays-3s fallback.** The single-flight refresh is
   scheduled to run AFTER the response is already on the wire —
   `fastcgi_finish_request()` on PHP-FPM, `ignore_user_abort(true)` +
   `flush()` elsewhere. On a SAPI with neither (the built-in `php -S`
   server used for local verification is one), the lock-winning
   request pays its own ≤3s HTTP timeout before the process exits. This
   is a documented, accepted worst case, not a bug — it affects at most
   one request per 300s TTL window per channel.
3. **Real gateway acceptance is unproven.** `api.mwbmpartners.ltd` is
   unreachable from this development container (the proxy answers 403
   to CONNECT for the whole domain — a network-policy fact, not
   evidence about deployment status). The stub verifier and the
   client's signer descend from the SAME source reading; a shared
   misreading, a deployed server that has diverged from `6816ed8`, or
   an env-overridden `HMAC_MAX_AGE_SECONDS` would all pass locally and
   could still fail live. Phase 1 is GET-only so this does not block
   enablement; no write-scoped consumer may trust the signer against
   the real gateway until Issue A (#1726, owner-only) closes and one
   live signed POST succeeds there.

Full design: `.claude/intappsapi-integration-plan.md`. Pre-launch
delta + stress test + commit-by-commit plan:
`.claude/wave4-prelaunch-plan.md` §4–§6.

---

## 🛡 Routing & security hardening (#1905 / #1906)

### Real 404s for unknown routes (#1905)

The `.htaccess` catch-all used to answer **every** unmatched path with `200` + the SPA
shell — so a `/wp-admin/` scanner probe or any typo'd URL returned a soft `200` that read
as "page exists" to crawlers and log analysis. #1905 splits the decision by locality:
**scanner-probe paths 404 at the web-server edge** (cheap, before PHP boots), **every other
unknown path 404s at the front controller** while genuine app routes still receive the
shell. The valid-route list is **derived from the app's own pages** (a new page is
recognised automatically, never a hand-maintained allow-list — rule #34), and a CI guard
keeps it in lockstep with the client router so the two can't drift. This does **not** change
the #1566 static-asset-fetch trap (an unmatched *asset* path can still resolve to the shell
for a browser `fetch()`; keep using root-absolute URLs + `apiFetchJson()`).

### Defensive hardening pass (#1906)

Entirely defensive; **no user-visible behaviour change**. Registration + email-code
brute-force protections now actually engage (the registration throttle was **dead code**;
the email-code check was per-IP only → a **per-email** bucket was added). A
session-fixation gap on cross-surface admin sign-in is closed
(`session_regenerate_id`). The `/manage` admin area and the social-card `og-image.php`
endpoint gained security headers / CSP, and copyrighted lyrics no longer leak via the
share-image endpoint when content-locking is on. Several heavy public endpoints
(`og-image`, `random`, `song_of_the_day`, media) gained rate limits (the #1354 pattern), and
error responses now carry the security headers (`Header always set`). **`X-Powered-By` now
advertises our own `iHymns/<version>` identity while the PHP runtime version is suppressed at
source (`expose_php=Off`).** Owner/host-gated remainder (`Options -Indexes`,
`ServerSignature Off`) still pending an alpha check.

---

## 🩹 Behavioural fixes (#1667 · #1673/#1896 · #1699 · #1710)

- **Org-admin Service Mode nav parity (#1667).** Organisation admins were always *allowed*
  to use the Service Mode tools (Projector Screen, Lead a Service) but the **menu links**
  were gated too broadly, so they never saw them. The nav visibility now matches the page
  gate — the #1587 nav↔gate-parity discipline applied to Service Mode.
- **Bulk-import rights passthrough (#1673 / #1896).** Bulk imports were silently **blanking**
  the copyright line, CCLI number, ISWC and public-domain flags the source file provided, for
  **every** format. They are now carried through — fixing the CCLI-report undercount of
  imported songs and letting an imported song auto-link to its Work by identifier (#1860).
  Writers/composers credits remain a follow-up (#1904).
- **Shared live set-list expiry (#1699).** A shared **live** set-list link now stops serving
  once the **owner's** per-set-list expiry passes; previously it honoured only the link's own
  expiry, so an expired set-list kept serving on the anonymous share/social surfaces. Expired
  → "no longer shared" (410 / empty), **no data deleted** — the resolver reads the set-list's
  own `ExpiresAt`, not just the share token's.
- **Signed-in sync notice (#1710).** A signed-in user was wrongly told to "Sign in to sync…"
  on Settings. `api.php` now resolves `$currentUser` for **non-cacheable** fragments so a
  personalised fragment sees the viewer; **cacheable** fragments stay un-personalised for
  shared-cache safety (rule #6), and a mutation-proven guard keeps that split honest.

---

## 🧩 Conventions & gotchas from the 2026-08-24 batch (`claude/ilyrics-identity-work-model`)

Full feature detail is in `CHANGELOG.md`'s `[unreleased]` section — the notes below are only the *developer* conventions/gotchas this batch introduced or reinforced.

- **Theme-aware admin surfaces (#1713).** Never hardcode Bootstrap `bg-dark` (or other fixed-dark utilities) on a `/manage/*` page — they leave stray dark boxes for anyone on Light / high-contrast / System-light. Use the theme-following tokens (`bg-body-tertiary`, `text-bg-secondary`, `border-secondary`, …). The batch removed 94 such utilities across 19 admin files; the only surfaces that stay deliberately dark are the projector overlay and the share-card canvas. This is the `admin-theme-init.php` discipline (#955) applied to background utilities.
- **Outbound webhooks (#1909) are entirely dormant + channel-walled.** Three additive tables, one migration; nothing emits or delivers until `webhooks_enabled_channels` names a channel (verified byte-identical no-op while off). A subscription is walled to the **channel** it was created on (alpha / beta / prod share one MySQL but never each other's webhook traffic — same trap as Service Mode's `Channel`). Payloads are **identity + metadata only, never content** (no lyrics/media/tokens/join codes). The dialer is SSRF-hardened for an attacker-controlled target (https-only, DNS pre-resolution + private/reserved-range truth table incl. IPv4-mapped/NAT64/6to4/IPv4-compatible IPv6, IP-pin, no redirects); the editor-save emit runs **post-commit**, never inside the save transaction. Retries drain via the key-authed `/webhook-drain.php` (cron / uptime monitor) with a traffic-driven piggyback fallback.
- **`/search` typeahead (#1936) adds no endpoint.** It reuses `?action=search` at a low limit — do **not** reintroduce a `?action=suggest` endpoint or a schema. Its reachability chain (`router → initSearchPage → _initSuggest → panel`) is asserted by `tests/test-search-typeahead.js` — the exact wiring #307 lacked when it shipped "built, reachable from nowhere".
- **Field-level blame (#1122) tolerates three snapshot shapes.** The pure `blameFromSnapshots()` walk over `tblSongRevisions` must fold the historical shapes (a 2022 lowercase `title`, a 2024 `Title`, and the current v2 shape) into one field, and must never confuse a field *absent* in an older shape with one that was *cleared*. Branch on the snapshot shape; never assume the current key casing.

---

> **Platform status:** Web/PWA is the active production app. Apple is
> **Phase 1 + Phase 2 code-complete** (iHymnsKit SwiftPM package; watch relay,
> tvOS projector, Live Activities, App Intents) — consolidated and CI-compiled
> but unreleased, with device matrices and APNs provisioning owner-gated.
> Android (~12 Kotlin files) remains **scaffold / in progress** — the
> deployment-secrets and store-submission sections above describe the intended
> CI/CD pipeline for when it ships.

Last updated: 2026-08-28
