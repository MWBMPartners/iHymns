# 🛠 iHymns — Developer Notes

> Technical notes, decisions, deployment setup, and key documentation for contributors.

---

## 📋 Table of Contents

- [Deployment Keys & Secrets](#-deployment-keys--secrets)
- [Song Data Format](#-song-data-format)
- [Architecture Decisions](#-architecture-decisions)
- [Deployment Architecture](#-deployment-architecture)
- [Development Environment](#-development-environment)
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
2. **[Apple portal] Register the return URL(s).** Same Services ID → Configure → **Website URLs** → add each docroot's domain + the return URL `https://<host>/` (the origin root) for every docroot offering web SIWA (`ihymns.app`, `dev.ihymns.app`, `beta.ihymns.app`). The web flow is popup mode but the return URL must still be registered and equal `APPLE_SIWA_WEB_RETURN_PATH` (`'/'`).
3. **[/manage/setup-database migration — ONCE, shared DB] Ensure the auth-provider tables exist.** Run the card **"Run Auth Providers Migration"** (`migrate-user-auth-providers.php` → `tblUserAuthProviders` + `tblAuthNonces`) **if it isn't already green** — it ships with the #1402 native backend, so it is likely already applied. This is the *only* migration web SIWA needs.
4. **[/manage/configuration setting — NOT a migration] Save the Services ID + enable a channel.** `/manage/configuration` → **Apple native app** card → **Sign in with Apple — Web** section: paste the Services ID from step 1 into **`apple_siwa_services_id`** (must NOT equal `app.ihymns`), and set **`apple_web_login_enabled`** to the channel(s) you're rolling out — start `alpha`, widen `alpha,beta`, then `all`. These are `tblAppSettings` writes (shared DB), **not** schema migrations. Web SIWA stays dormant until BOTH are set for the current channel.
5. **[Code — already done]** `appleid.cdn-apple.com` is in `index.php`'s CSP `script-src` (#1484) so Apple's JS SDK loads once enabled — no action.
6. **[Verify]** On a docroot in the enabled channel, the auth modal shows a **Sign in with Apple** button; sign-in creates/links an account; Link/Unlink live in Settings → Account & Profile → **Connected accounts**.

> **Why it's "not all migrations":** the Services ID (steps 1–2) is an Apple-portal identity artifact PHP cannot create; the enable/config (step 4) is a settings write, not a schema change. Only step 3 is an actual migration — and it's usually already done.

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

### Why JSON (not SQLite/IndexedDB) for Phase ONE?

- Simplicity — songs.json loaded once, searched in-memory
- Portable — same file used by web, Apple, Android
- ~3,600 songs ≈ ~5 MB JSON (acceptable for PWA cache)
- Fuse.js handles fuzzy search efficiently in-browser
- Phase TWO will move to proper database (iLyrics dB API)

### Why `appWeb/`, `appApple/`, `appAndroid/` naming?

- Consistent `app<Platform>/` prefix across all platforms
- Clearer separation in the directory tree
- Matches original repo convention

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

### Commit Message Flags

| Flag | Effect |
| --- | --- |
| `[deploy all]` | Force full SFTP upload even if no files changed |
| `[skip sync]` | (Deprecated — no longer used) |
| `[skip ci]` | Skip changelog, version-bump, and deploy workflows |

### Version Numbering

- **Semver**: `v1.x.x` (Phase 1 — local JSON) / `v2.x.x` (Phase 2 — iLyrics dB)
- Auto-bumped via conventional commits on `beta`:
  - `BREAKING CHANGE` or `!:` → major bump
  - `feat(...):` → minor bump
  - Everything else → patch bump
- Version stored in `appWeb/public_html/includes/infoAppVer.php`
- Build metadata (SHA, date) injected at deploy time
- Git tags `v*` trigger GitHub Releases

### Application IDs (per-platform)

| Platform | Application ID |
| --- | --- |
| Web/PWA | `Ltd.MWBMPartners.iHymns.PWA` |
| Apple | `Ltd.MWBMPartners.iHymns.Apple` |
| Android | `Ltd.MWBMPartners.iHymns.Android` |

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

# Parse song data (generates data/songs.json)
npm run parse-songs

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

## 📝 Commit Message Conventions

This project uses [Conventional Commits](https://www.conventionalcommits.org/) for automated versioning:

```text
feat: add new feature              → minor version bump
fix: fix a bug                     → patch version bump
feat!: breaking change             → major version bump
BREAKING CHANGE: description       → major version bump
docs: update documentation         → patch version bump
refactor: restructure code         → patch version bump
chore: maintenance task            → patch version bump
test: add or update tests          → patch version bump
```

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
3. Create all tables from `schema.sql` (~131 `CREATE TABLE` statements; idempotent — safe to re-run)
4. Seed default data: user groups, 14 languages, 5 access tiers, app settings

> **Manual setup:** Copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php`, edit it, then re-run the installer.

#### Step 2: Migrate Song Data from JSON

```bash
php appWeb/.sql/migrate-json.php
```

This imports all songs from `data/songs.json` into MySQL:
- Clears existing song data and re-imports (transaction-wrapped)
- Populates: songbooks, songs, writers, composers, components
- Imports translation links from `songs[].translations` array
- Builds `LyricsText` column for MySQL FULLTEXT search

> Specify a custom path: `php appWeb/.sql/migrate-json.php --json=/path/to/songs.json`

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
3. Click **Run Install** (creates tables)
4. Click **Run Song Migration** (imports songs)
5. Visit `/manage/setup` (create admin account)

#### One-Shot Alternative

```bash
mysql -u user -p ihymns < appWeb/.sql/.fulldata/ihymns-full.sql
```

### DB-Direct Reads (epic #1010, WS-J #1020)

Song reads are **live MySQL** — there is NO `songs.json` corpus cache, no
`songs_json` endpoint, and no server-side JSON fallback for reads. The whole-corpus
materialiser (`SongData::exportAsJson()` / `songsCacheServe()`) and the
`?action=songs_json` endpoint were all **removed in WS-J #1020**. Reads are always
scoped: `getSongsSlimIndex()` (lightweight id/number/title/songbook index, served by
`?action=songs_index`), `getSongs($abbr)` (one songbook, editor `?action=songbook_export`),
`getSongById()` (one full record, `?action=song_detail`).

`data/songs.json` is now a **migration INPUT only** (consumed by `migrate-json.php`),
never a runtime read source.

When MySQL is unavailable, the server returns a **themed 503 maintenance page**
(WS-K #1021, `includes/maintenance.php` + the `api.php` `isDbConnectionFailure()`
503 path) — a graceful outage, never stale data. The ONLY offline fallback is the
**client PWA offline cache** (service-worker-cached song pages + the slim index for
client-side Fuse.js search).

### Database Schema Overview

**Song Data (6 tables):**

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

Full schema: `appWeb/.sql/schema.sql` (~131 `CREATE TABLE` statements). Migrations
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
│   ├── schema.sql                     # Full MySQL schema
│   ├── install.php                    # Interactive table installer
│   ├── migrate-json.php              # JSON → MySQL migration
│   ├── migrate-users.php             # User/setlist migration
│   ├── cleanup.php                    # Token/session cleanup
│   ├── backup.php                     # Database backup
│   └── .fulldata/
│       ├── generate-full-sql.php      # One-shot SQL generator
│       └── ihymns-full.sql            # Pre-built full SQL (~6.8 MB)
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
- The slim song index (`?action=songs_index`) cached client-side for Fuse.js fuzzy search
- `data/songs.json` is a migration input only, not a runtime read source

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

Then update `checkTierAccess()` in `ccli_validator.php` to include the new tier in the capability matrix, and add the tier name to the `$validTiers` array in the `admin_set_user_tier` API endpoint.

### Setting Up Pricing / Prerequisites

Pricing and payment integration are managed via `tblUserPurchases`:

| Column | Purpose |
| --- | --- |
| `ProductType` | `tier_upgrade`, `songbook_unlock`, `feature_unlock`, `subscription` |
| `TierGranted` | Which tier this purchase unlocks |
| `Amount` / `Currency` | Payment amount (GBP default) |
| `Status` | `active`, `expired`, `refunded`, `cancelled` |
| `ExpiresAt` | NULL for one-off purchases; date for subscriptions |

To set up a paid tier:

1. Create the tier in `tblAccessTiers`
2. Configure your payment processor (Stripe, PayPal, etc.)
3. On successful payment, insert a row into `tblUserPurchases`
4. Update the user's `AccessTier` to the purchased tier

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

### ⚠ Known follow-ups (not yet sealed)

- **`song.php` is entity-model-only.** The server-rendered `/song` page enforces access
  via the **entity** content-restriction model, not the tier-cap field-strip that
  `contentGatingApply()` applies to the API payloads. The two enforcement surfaces aren't
  yet unified.
- **The static `/data/audio/<SongId>.mp3` file is directly fetchable.** Gating the
  `bulk_audio` manifest stops a restricted song's audio being **pre-cached**, but the
  static MP3 itself is still served directly by Apache. Sealing it needs the signed-URL /
  move-behind-`song-media.php` work (tracked separately) — and that must not break the
  browser `<audio>` element.

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
| bulk (`bulk_songs` / `bulk_audio` / …) | `bulk` | 60/min |

Limits are deliberately **generous** — real clients never trip them; abusive volume does.

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
  host matches this site (an explicit cross-origin host is rejected; an **absent**
  Origin/Referer is allowed, since the custom header already proves same-origin and some
  privacy setups strip Referer).

Either signal alone is an OWASP-recognised CSRF mitigation; together they're robust and —
crucially — **never go stale**. State-changing AJAX endpoints call this instead of
`validateCsrf()` directly.

### Where it's wired

- **Duplicate-songs** merge / delete handlers (`manage/duplicate-songs.php`).
- **Places API** (`manage/places-api.php`).
- A **top-level POST gate on ALL legacy `/manage/editor/api.php` writes** — every
  state-changing POST must pass `validateCsrfRequest($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)`
  or it 403s; GET reads are unaffected. The editor clients already send `X-Requested-With`.
- The **v2 editor `api2.php`** uses the same same-origin signal directly
  (`X-Requested-With: XMLHttpRequest` required on every POST) — which is why `save_song`
  over there always worked, and the model #1289 ("Here to Stay" 403s) generalised.

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
| `/manage/editor/` | Song editor | `edit_songs` |
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

> **Platform status:** Web/PWA is the active production app. The Apple
> (~14 Swift files) and Android (~12 Kotlin files) targets are
> **scaffold / in-progress**, not code-complete — the deployment-secrets and
> store-submission sections above describe the intended CI/CD pipeline for when
> those targets ship.

Last updated: 2026-06-24
