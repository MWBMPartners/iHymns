# iHymns — Secret Encryption-at-Rest Strategy (#1466)

> **Status:** Design locked via the 2026-07-09 security-review workflow (9 agents: 5
> fact-finders → Fable synthesis → 3 adversarial critics, all "refine, don't reverse").
> **Supersedes** the original #1466 file-based `.p8` plan.
> **Build status (2026-07-09):** **ALL of P1–P5 are BUILT and dormant** on
> `feat/apple-universal` (owner approved building past P1) — engine `includes/secret_crypto.php`
> + `includes/secret_crypto_admin.php` (sentinel/inventory/beacon/encrypt-in-place/rotate),
> the readers, the deploy seed-if-absent step + `.auth` mirror-exclude (P2), the Global-Admin
> Secret-encryption panel + generate-key tool (P2), the gated `migrate-secret-encrypt-inplace.php`
> + hardened Rotate/re-encrypt with the code-enforced cross-docroot parity gate (P3), the
> `opcache-bust.php` `sha256:` hash comparison (P4), and the operator runbook in `DEV_NOTES.md`
> (P5). CI: `tests/php/test-secret-crypto.php` (41) + `tests/php/test-secret-crypto-admin.php` (22).
> Adversarially reviewed twice (P1 pass + a P2–P5 pass; 23 findings fixed total). It is a
> **verified no-op** — nothing is encrypted until a master key is provisioned on all 3 docroots
> **and** the P3 encrypt-in-place migration is run (both operator actions). Still uncommitted /
> no PR. **Do NOT run the P3 migration** until P1–P5 are live on all 3 docroots + the §12 open
> items (DB `FILE` privilege check, provider-rotation scope) are settled with the owner.

## 1. Decisions (locked by the owner)

| Decision | Choice | Why |
|---|---|---|
| **Storage** | **Encrypt-at-rest in `tblAppSettings`**, master key file in `appWeb/.auth/` | Keeps the convenient `/manage/configuration` UI (a *data* write, not a code write); one write propagates to all 3 docroots via the shared DB (zero drift); closes the live plaintext-in-DB exposure. |
| **Master-key bootstrap** | **Seed-if-absent from a GitHub Secret + manual SFTP override** | Guarantees byte-identical keys on all 3 docroots on first provision (kills the drift-outage hazard), while manual rotation still works without the deploy clobbering it. |
| **What does NOT move** | `db_credentials.php` stays **manual-only, never in GitHub** | It's the root of trust; keeping it out of GitHub preserves the two-primitive property (a GitHub secret-store leak alone yields the master key but not DB access). |

### Why not the rejected options (honest record)
- **Secret files in `.auth/` (owner's earlier instinct):** a UI that writes files PHP later `require()`s breaks the "`.auth/` is never web-writable" invariant (a 2nd bug → code-execution payoff), and forces 3 manual placements per secret (drift — this project's most expensive failure class). A DB dump yielding *nothing* is a real edge for file-only, but it's outweighed by drift + losing the UI.
- **GitHub Secret per secret:** moves every secret's plaintext into GitHub's trust domain and makes rotation a GitHub-UI + 3-branch redeploy (operational regression for a web-only operator). Retained ONLY as the master-key seed channel.

## 2. Threat model — what this does and does not do

**Protects against (the actual open exposure today):**
- A **DB dump / backup** of the shared MySQL → yields only ciphertext.
- A **SQL-injection** in *any* of the 3 (historically skewed) docroots → yields only ciphertext (assuming the DB user lacks `FILE` privilege — **verify** `secure_file_priv` is restrictive on DreamHost; almost certainly is on shared hosting).
- Disclosure now needs **two primitives**: DB read **and** a filesystem read of `.auth/secrets_master_key.php`.

**Does NOT protect against (state plainly — no overclaiming):**
- **Runtime compromise (RCE) on any docroot** — reads the master key + DB = all plaintext. All 3 docroots are ONE blast radius (shared key + shared DB).
- **The master key sits unencrypted on shared DreamHost disk** — no HSM/KMS on this tier; hosting-staff / SFTP-credential compromise reads it.
- **Admin-account compromise can SUBSTITUTE secrets** (point email at an attacker's key) even though write-only fields never echo — caught only by audit logging.
- **Plaintext in memory when used**, and one TLS POST when set.
- **Pre-encryption backups stay dangerous forever** → see §9 (provider-side rotation). *(Note: the SIWA `.p8` was NEVER entered, so there is no plaintext-era `.p8` backup — a clean start for that one.)*
- **A full CI compromise is game-over regardless** — CI already has SFTP-deploy to all 3 docroots, so it can deploy code that reads everything. This is why seeding the master key into a GitHub Secret adds little marginal risk.

## 3. Secret inventory & disposition

**Encrypt-at-rest in `tblAppSettings` (8 — the live exposure):**
`email_smtp_pass`, `email_sendgrid_api_key`, `email_mailgun_api_key`, `email_ses_access_key`, `email_ses_secret_key`, `email_graph_client_secret`, `email_gmail_sa_json` (large multi-line JSON), **`apple_siwa_private_key`** (the trigger).

**Stay as `.auth/` files, unchanged (2):**
- `db_credentials.php` — MySQL creds; root of trust; manual-only, never in DB/GitHub.
- `audio_signing_key.php` (#1358) — HMAC key; already file-borne + env-local by design.

**Change storage shape (1):**
- `opcache_bust_key.php` / `IHYMNS_OPCACHE_KEY` — the ONLY verify-by-comparison secret (server checks equality of an HTTP header, never needs the plaintext back). → store a **SHA-256 hash** server-side and compare hashes. (`canHash: true` — the one exception.)

**New (1):**
- `secrets_master_key.php` in `.auth/` — the key(set) that encrypts the 8 above.

## 4. Crypto envelope (self-describing, versioned)

Stored value format (a plain string that lives in `tblAppSettings.SettingValue`):

```
enc:v1:<alg>:<keyid>:<base64url(nonce)>:<base64url(ciphertext+tag)>
```

- `v1` — envelope version (future-proofs format changes).
- `<alg>` — `sb` = libsodium `crypto_secretbox` (**preferred**, misuse-resistant) · `gcm` = `openssl` AES-256-GCM (fallback if libsodium absent). Self-describing so any reader decrypts correctly regardless of the install's current preference.
- `<keyid>` — which master key encrypted it (enables safe rotation; see §6).
- `<nonce>` — per-value random (24 bytes for secretbox, 12 for GCM).
- The MAC/tag is authenticated (secretbox is AEAD; GCM tag appended).

**Reader rule:** value starts with `enc:` → parse + decrypt. Otherwise → treat as **legacy plaintext** (the migration fallback — see §7). A libsodium round-trip CI test is mandatory (`tests/php/test-secret-crypto.php`).

## 5. Read & write paths (the ONE helper — no forking)

New shared module `appWeb/public_html/includes/secret_crypto.php`:
- `secretEncrypt(string $plaintext): string` → returns an `enc:v1:…` envelope using the **active** keyid.
- `secretDecrypt(string $stored): ?string` → decrypts an `enc:` envelope; returns the input unchanged if it's legacy plaintext (no `enc:` prefix); returns `null` on decrypt failure / missing key (→ fail-safe dormant).
- `secretCryptoReady(): bool` → master key file present + loadable.
- `secretKeyFingerprint(?string $keyid): string` → a **non-secret** 12-char fingerprint of a key, for the per-docroot consistency check (§8). Never exposes the key.

**Write path:** `manage/configuration.php` `$saveSetting` closure (and the `save_apple` handler) call `secretEncrypt()` before storing the flagged-secret settings. Non-secret settings are stored as-is. Write-only UI is unchanged (blank = keep existing; never echoed; audit-log key names only).

**Read path:** wherever these secrets are consumed (`EmailService.php`, `api.php` SIWA path) the read goes through `secretDecrypt(getAppSetting(...))`. `getAppSetting()` itself stays the memoized DB reader; decryption is a thin layer on top.

**Fail-safe:** master key absent, or decrypt returns `null` → the secret is treated as **unset** → that feature is dormant + an admin banner. Never a fatal/white-screen (matches the `audio_signing.php` house pattern). **Caveat to document loudly:** for SMTP + SIWA, "dormant" after the plaintext has been overwritten = a *silent production outage* on that docroot (no legacy fallback remains). → surface it **actively** as a red health row per docroot on `/manage/setup-database`, not just a passive banner.

## 6. Master key file — rotation-safe from day one

```php
<?php // appWeb/.auth/secrets_master_key.php — gitignored by appWeb/.auth/*
return [
    'active' => 'k1',           // new writes use this keyid
    'keys' => [
        'k1' => '…64 hex (32 bytes)…',
        // 'k2' => '…',          // added during rotation; readers try all keys by keyid
    ],
];
```

- Ordered **keyid → key** map (NOT a single constant) so rotation is safe on the shared-DB fleet: add the new key to all 3 files → re-encrypt the DB under the new keyid → retire the old key from all 3 files. During the overlap, readers decrypt any keyid present in their map.
- **Byte-identical on all 3 docroots** (ciphertext is shared via the one DB). The seed step (§8) guarantees this on first provision.

## 7. Migration sequencing — the prod-stale gate (non-negotiable)

The shared DB is read by 3 docroots running historically **skewed** code. Order matters or we repeat the SongbookName prod-stale incident, this time on secrets:

1. **Ship the readers everywhere first.** Deploy `secret_crypto.php` + the decrypt-capable read path (with legacy-plaintext fallback) through the normal alpha → beta → main promotion train so **all 3 docroots can decrypt** before any value is encrypted. Until the encrypt card runs, every value is still legacy plaintext and reads unchanged — a verified no-op.
2. **Seed + verify the master key on all 3** (§8) — confirm identical fingerprint on dev/beta/prod.
3. **Only then** run the one-shot **encrypt-in-place migration card** on `/manage/setup-database` (writes each flagged secret's ciphertext to the shared DB). It is **`manual` + `confirm=1`-gated** (like the lyrics-drop), excluded from "Apply all".
4. **Rotate provider-side keys** (§9) to neutralise plaintext-era backups.

## 8. Bootstrap: seed-if-absent + manual override (exact mechanics)

The two channels the critics said need *opposite* mirror handling are reconciled like this:

- **`.auth` mirror EXCLUDES `secrets_master_key.php`** (exactly like it already excludes `db_credentials.php` at `deploy.yml:617/637`) → the mirror never overwrites the live key, so a manual rotation is never clobbered, and a stray committed copy can't land (it's gitignored anyway).
- **A dedicated "seed master key if absent" deploy step** (new, runs per-branch/per-docroot) checks the remote for `.auth/secrets_master_key.php` and, **only if absent**, writes it from `${{ secrets.SECRETS_MASTER_KEY }}`. Because all 3 branch deploys read the same secret, all 3 docroots seed identically. If present, it's a no-op → manual override survives.
- **Rotation via GitHub** = update the `SECRETS_MASTER_KEY` secret + delete the file on each server → next deploy re-seeds. Rotation via SFTP = replace the file by hand on all 3.

**Per-docroot consistency canary (hard pre-migration gate):** each docroot's `/manage/setup-database` shows the **active keyid + non-secret fingerprint**. The operator confirms all 3 pages show the **same fingerprint** before the encrypt card will run. Additionally, an encrypted **sentinel row** in the shared DB lets each docroot's health panel prove it can decrypt (ongoing health, catches a later key divergence). **The destructive encrypt card refuses to run unless the sentinel round-trips green.**

## 9. Post-migration provider-side rotation (neutralise old backups)

Encryption protects *future* dumps, not past ones. Any DB backup taken in the plaintext era stays dangerous forever. After migration, **rotate at the provider** the keys that had a plaintext-era backup: SMTP password, SendGrid, Mailgun, SES access+secret, Azure Graph client secret, Gmail SA JSON. *(The SIWA `.p8` was never entered → no plaintext-era copy → nothing to revoke there.)* Owner decides scope based on which backups exist.

## 10. Hardening the re-encrypt / rotation card (top new target)

The re-encrypt card **decrypts every secret at once** to re-wrap them → a single admin-session compromise driving it exfiltrates the whole plaintext set. It must have: re-auth, `validateCsrfRequest()`, fixed scope, and **audit-log every decrypt** (key name + actor + timestamp, never the value). Threat-model it as the single highest-value endpoint the design introduces.

## 10b. Global-Admin "Secret encryption" panel — the web home for status, generate & rotate

A card on `/manage/setup-database` (Global-Admin only — where migrations + the key canary live). Everything the web-only operator needs, no shell:

**Status (per docroot, read-only):** engine (libsodium available, else AES-256-GCM fallback) · master key present? + active keyid + **non-secret fingerprint** (so all 3 docroots can be eyeballed to match) · N encrypted / M legacy-plaintext · sentinel round-trip green/red (proves *this* docroot can decrypt).

**Generate master key** (solves the web-only "can't run `openssl rand`" problem): produces a CSPRNG 32-byte key (`random_bytes`), shows the 64-hex value **once** with copy-to-clipboard + instructions (paste into the `SECRETS_MASTER_KEY` GitHub Secret and/or SFTP into `.auth/` on each docroot). It is a **generator, not a writer** — it never writes `.auth/` itself, so it does NOT reintroduce the web-writable-`.auth/` primitive we rejected.

**Rotate / re-encrypt** (the capstone — honest about the multi-docroot reality): rotation can't be one click, because the NEW key must reach **all 3 docroots before** the shared DB is re-encrypted (or the lagging docroot can't read the new ciphertext). The panel makes each step easy **and gated**:
1. Generate new key → operator adds it as a new keyid on all 3 (deploy the new Secret, or SFTP).
2. Panel shows a **per-docroot fingerprint table**; the **Rotate button stays disabled until all 3 show the new keyid present + matching**.
3. Rotate → re-auth + `validateCsrfRequest()` + audited transaction (logs key name + actor + time, never values) → re-wraps every secret under the new active keyid.
4. Retire the old keyid from all 3 files.

"Easy" = the panel **guides + gates**; it does not bypass the coordination the shared-DB topology requires (that's the safety, not a limitation). This is the P3 hardened card.

## 11. Build phasing (once approved)

1. **P1 — engine + readers (no behaviour change):** `secret_crypto.php` + CI round-trip test + wire `secretDecrypt()` into every reader with legacy-plaintext passthrough. Verified no-op. Promote to all 3 docroots.
2. **P2 — write path + master key + bootstrap:** encrypt-on-save in `configuration.php`/`save_apple`; the seed-if-absent deploy step + mirror exclude; the fingerprint canary + sentinel health panel.
3. **P3 — the gated encrypt-in-place migration card** (manual, confirm=1, all-3-green gate) + the hardened re-encrypt/rotation card.
4. **P4 — opcache-bust key → hash comparison** (independent, small).
5. **P5 — operator runbook** (seed, verify fingerprints, run card, rotate providers) into `DEV_NOTES.md` + the provisioning runbook.

Each phase adversarially reviewed before the next; the destructive card only after P1/P2 are live on all 3 docroots.

## 12. Open items for the owner (post-approval)
- Confirm the DB user lacks `FILE` privilege / `secure_file_priv` restrictive (verifies the two-primitive claim).
- Provider-rotation scope (§9) — which keys + timing.
- Whether `email_ses_access_key` (a semi-public access-key *ID*) rides the encrypted path for uniformity (recommended: yes).
- Timing of P3 relative to the promotion train (owner owns promotions).
