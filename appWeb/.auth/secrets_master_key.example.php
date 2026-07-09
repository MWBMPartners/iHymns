<?php

declare(strict_types=1);

/**
 * iHymns — Secret-encryption MASTER KEY  (EXAMPLE TEMPLATE, #1466)
 * ============================================================================
 * ELI5: This one key locks and unlocks every OTHER secret the app stores in
 * the database (email passwords/API keys, the Sign-in-with-Apple .p8, …). The
 * secrets themselves live encrypted in `tblAppSettings`; this file is the key
 * that decrypts them at runtime. Keep the real file OFF the internet and out
 * of git — this `.example.php` is just the shape to copy.
 *
 * ----------------------------------------------------------------------------
 * THIS IS THE EXAMPLE. The REAL file is `appWeb/.auth/secrets_master_key.php`
 * (same directory, no `.example`). The real file is git-ignored by the
 * `appWeb/.auth/*` rule and MUST NEVER be committed. This template IS tracked
 * (un-ignored) so the format is version-controlled — it contains only fake
 * placeholder keys.
 * ----------------------------------------------------------------------------
 *
 * WHERE IT LIVES & WHY IT'S SAFE THERE
 *   - `appWeb/.auth/` is a SIBLING of the web docroot (`public_html/`), so it
 *     is OUTSIDE everything Apache serves — no URL can ever reach it. It also
 *     carries its own deny-all `.htaccess` as defence-in-depth.
 *   - The deploy mirror for `.auth/` deliberately omits `--delete` AND excludes
 *     this filename, so a file placed here is PRESERVED across every deploy and
 *     is never overwritten by the pipeline (a manual rotation survives).
 *
 * BYTE-IDENTICAL ON ALL THREE DOCROOTS  (dev / beta / prod)
 *   The three environments share ONE MySQL database. A secret encrypted on one
 *   docroot is read by all three, so every docroot must hold the SAME key(s).
 *   The "seed-if-absent" deploy step writes this file from the SECRETS_MASTER_KEY
 *   GitHub Secret the first time it's missing — because all three deploys read
 *   the same secret, all three seed identically. Confirm the fingerprints match
 *   on `/manage/setup-database` (per docroot) BEFORE running the encrypt-in-place
 *   migration — a divergent/missing key silently darkens that environment.
 *
 * FORMAT — a ROTATION-SAFE ordered keyset (NOT a single constant)
 *   `active` = the keyid new encryptions use.
 *   `keys`   = keyid → 64-hex-char (= 32-byte) key. Keep OLD keyids here during
 *              a rotation overlap so already-stored ciphertext still decrypts;
 *              retire an old keyid only AFTER the re-encrypt card has re-wrapped
 *              every secret under the new keyid on the shared DB.
 *   Stored envelope in the DB looks like:
 *              enc:v1:sb:k1:<base64 nonce>:<base64 ciphertext+tag>
 *   so each value records which keyid (`k1`) and algorithm (`sb`=libsodium
 *   secretbox / `gcm`=AES-256-GCM) produced it — decryption is deterministic
 *   regardless of the install's current preference.
 *
 * ⚠️ STATUS: the full engine + tooling (P1–P5) is BUILT and DORMANT (#1466) —
 *   nothing is encrypted until a real key is provisioned on all 3 docroots AND the
 *   gated encrypt-in-place migration runs. The web tooling referenced below (the
 *   "Generate master key" and "Rotate / re-encrypt" cards, the seed-if-absent
 *   deploy step, the encrypt-in-place migration) now EXISTS — see
 *   `.claude/secret-encryption-strategy.md` §11 for phasing and the `DEV_NOTES.md`
 *   operator runbook for the activation sequence.
 *
 * HOW TO GENERATE A KEY
 *   Easiest (no shell): the Global-Admin "Secret encryption → Generate master key"
 *   tool on `/manage/setup-database` (PHP CSPRNG, shown ONCE — it does NOT write
 *   `.auth/`; you place the value yourself).
 *   By shell: 32 random bytes as 64 hex chars:  `openssl rand -hex 32`
 *   Put the SAME value into the `SECRETS_MASTER_KEY` GitHub Secret and/or, for a
 *   manual placement, into the real file on each docroot.
 *
 * ROTATION  (see `.claude/secret-encryption-strategy.md` §6/§8/§10)
 *   1. Generate a new key → add it under a new keyid (e.g. `k2`) to the `keys`
 *      map on ALL THREE docroots (deploy the new GitHub Secret, or SFTP by hand).
 *   2. Set `active` to the new keyid.
 *   3. Run the Global-Admin "Rotate / re-encrypt" card → re-wraps every secret
 *      under the new keyid (re-auth + CSRF + audit-logged; refuses unless all 3
 *      docroots report the same key fingerprints).
 *   4. Remove the retired keyid from all three files.
 *
 * NEVER: commit the real file · paste a key into a UI field that gets stored ·
 * log a key value · put `db_credentials.php` in here (the DB key can't live in
 * the DB it unlocks, and must stay manual-only, never in GitHub).
 */

return [
    /* The keyid that NEW encryptions use. Point this at your newest key. */
    'active' => 'k1',

    /* keyid → 64 hex characters (32 bytes). The values below are OBVIOUSLY FAKE
       placeholders — replace with real CSPRNG output. Keep retired keyids here
       through a rotation overlap so old ciphertext still decrypts. */
    'keys' => [
        'k1' => '0000000000000000000000000000000000000000000000000000000000000000',
        // 'k2' => '1111111111111111111111111111111111111111111111111111111111111111',
    ],
];
