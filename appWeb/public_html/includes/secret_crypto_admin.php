<?php

declare(strict_types=1);

/**
 * iHymns — Secret encryption-at-rest ADMIN / MIGRATION layer (#1466)
 * ============================================================================
 * ELI5: `secret_crypto.php` is the lock-and-key machine (encrypt/decrypt one
 * value). This file is the toolbox that USES that machine against the real
 * database: it checks "is the sentinel healthy?", "how many secrets are still
 * plaintext?", and does the actual one-shot "encrypt everything now" and
 * "rotate to a new key" jobs. It exists as its OWN file (not folded into
 * `secret_crypto.php`) because `secret_crypto.php` is loaded on EVERY public
 * request via `maintenance.php` and must stay completely DB-free; this file
 * touches `tblAppSettings` and is loaded ONLY by the Global-Admin panel on
 * `/manage/setup-database` and by the encrypt-in-place / rotation migration
 * cards themselves.
 *
 * DETAILED / WHY:
 * The three iHymns docroots (dev/beta/prod) share ONE MySQL database, so a
 * secret's ciphertext written on one docroot must be decryptable by all
 * three — see `.claude/secret-encryption-strategy.md` §7 (migration
 * sequencing: readers ship everywhere FIRST, key is seeded + fingerprint-
 * verified on all 3, and ONLY THEN does the encrypt-in-place card run) and §8
 * (the sentinel round-trip is the hard pre-migration gate: a fixed known
 * plaintext is encrypted once and stored; every docroot's health panel proves
 * it holds a working key by decrypting that SAME ciphertext back to the SAME
 * plaintext — cross-docroot key PARITY, not just "a key exists"). §10 covers
 * why the rotate/re-encrypt card is the highest-value endpoint this feature
 * introduces (it decrypts every secret in one pass) and why it must be
 * audit-logged key-name-only, never value-bearing.
 *
 * Two read-only status helpers (`secretSentinelStatus()`, `secretInventory()`)
 * are safe to call on every panel render and NEVER throw — they degrade to a
 * "can't tell" answer on any DB hiccup rather than white-screening the admin
 * page. The two mutating helpers (`secretEncryptInPlace()`,
 * `secretRotateReencrypt()`) run inside a transaction and MAY throw — the
 * migration/rotation HTTP handlers that call them are responsible for
 * catching, rolling the user-facing error into the confirm=1-gated card UI,
 * and never silently swallowing a partial failure.
 *
 * Refs: mysqli transactions https://www.php.net/manual/en/mysqli.begin-transaction.php
 *       PHP prepared statements https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 *       #1466 tracking issue; `.claude/secret-encryption-strategy.md` (locked design)
 *
 * ONE admin layer, no forking (repo modularity rule #25/#28 precedent): the
 * Global-Admin panel, the encrypt-in-place migration, and the rotate/
 * re-encrypt card all call THESE functions — never a bespoke inline query.
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION — included only, never hit directly.
 * ELI5: if someone tries to open this file straight in a browser URL, stop
 * them cold instead of letting stray top-level code (there isn't any here,
 * but future edits might add some) execute outside its expected include
 * context.
 * WHY: mirrors the exact guard in secret_crypto.php (basename-compare against
 * the calling script), which itself mirrors the house convention used by
 * every DB-touching include in this codebase (e.g. db_mysql.php) — a direct
 * hit almost always means a misconfigured web server exposing PHP source or
 * an attacker probing for include-path disclosure.
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* This module BUILDS ON the P1 engine — every encrypt/decrypt/ready/keyid
   call below goes through the one engine, never re-implemented here. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'secret_crypto.php';

/* =========================================================================
 * tblAppSettings KEYS used by this module.
 * ========================================================================= */

/**
 * ELI5: the on/off switch. '1' means "the one-shot encrypt-in-place migration
 * has already run — every secret in the DB should now be ciphertext."
 * WHY: read by `manage/configuration.php`'s $saveSetting closure (gates
 * encrypt-on-save) and by `maintenance.php`'s getAppSetting() decrypt path
 * (both already wired, per the P1 build) — this constant is the single
 * source of truth for that key name so a future edit can't typo two
 * different literals into drift. Never read directly elsewhere; always via
 * secretEncryptionActive().
 */
const SECRET_ENC_ACTIVE_KEY = 'secret_encryption_active';

/**
 * ELI5: a "test message" the app can encrypt and later re-read to prove this
 * docroot has the right key — like sending yourself a locked box and seeing
 * if your key opens it.
 * WHY: strategy §8's cross-docroot parity proof. Only ONE docroot's call to
 * secretSentinelEnsure() ever WRITES this row (idempotent — never
 * overwritten once present), so every docroot's health check decrypts the
 * exact same ciphertext; if a docroot's key differs even slightly it will
 * fail to reproduce SECRET_SENTINEL_PLAINTEXT and its panel shows 'red'.
 */
const SECRET_SENTINEL_KEY = 'secret_encryption_sentinel';

/**
 * ELI5: the fixed, known "test message" itself. It carries no secrecy value
 * on its own — its only job is to round-trip correctly.
 * WHY: fixed (not random-per-install) so the sentinel's PLAINTEXT is a
 * compile-time constant every docroot can compare against without a second
 * DB read; the ciphertext (which IS random per-encryption, per §4's nonce)
 * is what actually gets shared via the row.
 */
const SECRET_SENTINEL_PLAINTEXT = 'ihymns-secret-encryption-sentinel-v1';

/* =========================================================================
 * INTERNAL raw tblAppSettings read/write (NO encryption/decryption here —
 * that's the caller's job; these two helpers only move bytes in and out of
 * the table).
 * ========================================================================= */

/**
 * Read a raw tblAppSettings value (NO decryption) by key, or null if absent.
 * ELI5: "what's literally sitting in the database cell right now" — could be
 * plaintext, could be an enc:v1 envelope, could be missing entirely. Pass
 * `$forUpdate = true` to also grab a row lock while you're looking, so
 * nobody else can sneak in and change that row before you write it back.
 * WHY @internal: every public function in this file needs this primitive
 * (inventory classification needs the RAW value to tell enc/legacy/empty
 * apart; the sentinel functions need the raw envelope to feed secretDecrypt()
 * themselves) — kept private so callers can't accidentally bypass the
 * classification helpers and mis-read a value as plaintext when it's really
 * ciphertext, or vice versa.
 * WHY `$forUpdate` (#1466 review finding 3): `secretEncryptInPlace()` and
 * `secretRotateReencrypt()` each do a read-modify-write per key INSIDE one
 * DB transaction — read the raw value, decrypt/encrypt it in PHP, then
 * UPDATE the same row. Without a lock, a concurrent `manage/configuration.php`
 * save of the SAME setting key (a plain, un-transacted UPDATE) could land
 * between this function's read and the later `_secretAdminPutSetting()`
 * write; the migration/rotation would then overwrite the operator's fresh
 * save with a re-wrap of the STALE value it read first — a classic
 * lost-update race. `SELECT ... FOR UPDATE` takes an exclusive row lock for
 * the rest of the enclosing transaction, so a concurrent save either
 * completes before the transaction starts (its value is what gets read) or
 * blocks until the migration/rotation commits (avoiding the interleave
 * entirely) — see mysqli transactions in the file banner refs. The
 * read-only status helpers (`secretInventory`, `secretSentinelStatus`,
 * `secretEncryptionActive`) have no matching write in the same call, so they
 * keep `$forUpdate = false` (a lock they'd never release promptly, since
 * they aren't wrapped in a transaction, would only hold up other readers
 * for no benefit).
 * @internal
 */
function _secretAdminRawSetting(\mysqli $db, string $key, bool $forUpdate = false): ?string
{
    $sql = 'SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $db->prepare($sql);
    /* $key here is always one of the 8 fixed secretSettingKeys() constants or
       SECRET_SENTINEL_KEY/SECRET_ENC_ACTIVE_KEY — never raw user input — but
       we STILL bind it as a parameter rather than interpolate, per the house
       "every SQL value goes through bind_param" convention (uniformity over
       provenance-based exceptions). */
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row === null ? null : (string)$row[0];
}

/**
 * Write a raw tblAppSettings value (NO encryption) — INSERT ... ON DUPLICATE
 * KEY UPDATE, bound params.
 * ELI5: "put this exact string in the database under this key," no
 * transformation. The caller decides beforehand whether the string being
 * written is plaintext or an enc:v1 envelope.
 * WHY @internal: mirrors the exact INSERT ... ON DUPLICATE KEY UPDATE shape
 * already used by `manage/configuration.php`'s $saveSetting closure (kept
 * byte-for-byte consistent so both code paths hit tblAppSettings the same
 * way — no second write-shape to maintain). Kept private for the same
 * bypass-the-classification reason as the read helper above: public callers
 * always go through secretEncryptInPlace()/secretRotateReencrypt()/
 * secretSentinelEnsure(), which decide what to encrypt BEFORE calling this.
 * @internal
 */
function _secretAdminPutSetting(\mysqli $db, string $key, string $value): void
{
    $stmt = $db->prepare(
        'INSERT INTO tblAppSettings (SettingKey, SettingValue)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

/* =========================================================================
 * PUBLIC STATUS API — safe to call on every panel render, NEVER throws.
 * ========================================================================= */

/**
 * Is the encrypt-in-place cutover flag set? (tblAppSettings.secret_encryption_active === '1')
 * ELI5: "has the big one-time migration already run on this shared database?"
 * — a live, un-cached look at that one row, for the admin panel and the
 * migration card to display/decide against.
 * WHY (#1466 review finding 6 — this docblock previously overclaimed being
 * THE write-path gate; corrected to describe what it actually is): this is
 * a STATUS READER, not the save-path's source of truth. It's used by
 * `secretEncryptInPlace()` (which SETS the flag) and by the admin panel /
 * migration card (which DISPLAY it) so both read the LIVE row via the raw,
 * un-memoized reader rather than getAppSetting()'s per-request cache — that
 * matters here because this file can run mid-request alongside code that
 * already warmed the cache with the PRE-migration value. Separately,
 * `manage/configuration.php`'s `$saveSetting` closure gates whether to
 * encrypt-on-save by calling `getAppSetting('secret_encryption_active')`
 * directly (the ordinary memoized settings path, not this function) — it
 * reads the SAME `tblAppSettings` row this function reads, just through a
 * different accessor, so the two independently arrive at an equivalent
 * result. They are not required to be (and are not) the same call.
 */
function secretEncryptionActive(\mysqli $db): bool
{
    try {
        return _secretAdminRawSetting($db, SECRET_ENC_ACTIVE_KEY) === '1';
    } catch (\Throwable $e) {
        /* Never throw on a status read — the panel degrades to "not active"
           (the safe, pre-migration assumption) rather than crashing. */
        return false;
    }
}

/**
 * Ensure the cross-docroot decryption SENTINEL exists. If the key is ready and
 * no sentinel row exists yet, encrypt SECRET_SENTINEL_PLAINTEXT under the
 * active key and store it in tblAppSettings.SECRET_SENTINEL_KEY. Idempotent —
 * never overwrites an existing sentinel (so all docroots share the ONE
 * ciphertext written first, proving key parity). Returns true if a sentinel
 * row exists after the call, false if it could not be created (no key).
 * NEVER throws.
 *
 * ELI5: the FIRST docroot to call this (with a key already loaded) locks the
 * "test message" and drops it in the shared database; every OTHER docroot
 * just finds it already there and leaves it alone — they only try to UNLOCK
 * it (that's secretSentinelStatus(), below), never re-lock it.
 * WHY idempotent-never-overwrite: if a second docroot re-encrypted the
 * sentinel under ITS OWN active key, the three docroots would each be
 * comparing against a DIFFERENT ciphertext and the parity proof would be
 * meaningless — strategy §8 requires ONE shared ciphertext all three try to
 * open. Once a row exists, only the (currently un-built, out of scope for
 * this file) rotation flow's re-wrap step is allowed to replace it — see
 * secretRotateReencrypt() below, which re-wraps the sentinel alongside the
 * secrets on a genuine key rotation, not on every render.
 */
function secretSentinelEnsure(\mysqli $db): bool
{
    try {
        $existing = _secretAdminRawSetting($db, SECRET_SENTINEL_KEY);
        if ($existing !== null && $existing !== '') {
            return true;
        }
        if (!secretCryptoReady()) {
            /* No key on THIS docroot — can't be the one to mint the sentinel.
               Not an error: some other, already-provisioned docroot will. */
            return false;
        }
        $envelope = secretEncrypt(SECRET_SENTINEL_PLAINTEXT);
        _secretAdminPutSetting($db, SECRET_SENTINEL_KEY, $envelope);
        return true;
    } catch (\Throwable $e) {
        /* Status/bootstrap helper — degrade to "not ensured" rather than
           surfacing a DB/crypto exception to the panel render path. */
        return false;
    }
}

/**
 * This docroot's decryption health against the shared sentinel:
 *   'green'  = sentinel row present AND secretDecrypt() of it === SECRET_SENTINEL_PLAINTEXT
 *              (this docroot holds the SAME key that wrote the sentinel — cross-docroot parity proof)
 *   'red'    = sentinel row present but does NOT decrypt to the expected plaintext
 *              (key absent/wrong/divergent on THIS docroot — DO NOT run the migration)
 *   'absent' = no sentinel row yet (provision the key, then call secretSentinelEnsure)
 * NEVER throws.
 *
 * ELI5: "can THIS server open the shared test box?" Green = yes, exact same
 * key as whoever locked it. Red = this server has A key, but it's the wrong
 * one (or events conspired to corrupt the value) — a loud stop sign. Absent
 * = nobody has locked the box yet.
 * WHY the three-way (not boolean) split: strategy §8 needs the operator to
 * be able to tell "key not provisioned yet" (absent — expected mid-rollout,
 * not alarming) apart from "key IS provisioned but doesn't match the other
 * docroots" (red — the dangerous divergence case the destructive migration
 * card must refuse to run against). Collapsing these into one boolean would
 * hide exactly the failure mode this gate exists to catch.
 */
function secretSentinelStatus(\mysqli $db): string
{
    try {
        $stored = _secretAdminRawSetting($db, SECRET_SENTINEL_KEY);
        if ($stored === null || $stored === '') {
            return 'absent';
        }
        $plain = secretDecrypt($stored);
        return $plain === SECRET_SENTINEL_PLAINTEXT ? 'green' : 'red';
    } catch (\Throwable $e) {
        /* A DB hiccup reading the sentinel is NOT the same claim as "this
           docroot's key is wrong" — but it still must not let the migration
           gate treat it as healthy, so it reports 'red' (fail closed) rather
           than a fourth ambiguous status the caller would have to guess about. */
        return 'red';
    }
}

/**
 * Inventory of the 8 secret keys' storage state. For each key classify its
 * stored value:
 *   'enc'    = non-empty and carries the enc: envelope (secretIsEncrypted)
 *   'legacy' = non-empty and NOT enc: (plaintext at rest — still needs the migration)
 *   'empty'  = absent or empty string (nothing to encrypt)
 * Returns ['encrypted'=>int, 'legacy'=>int, 'empty'=>int, 'keys'=>[key=>'enc'|'legacy'|'empty']].
 * NEVER throws (wrap in try/catch → degrade to all-empty on DB error).
 *
 * ELI5: a report card — "of our 8 secret fields, how many are already locked,
 * how many are still sitting out in the open, and how many are just blank?"
 * WHY: this is what the Global-Admin panel renders as the "N encrypted / M
 * legacy-plaintext" status line (strategy §10b) and what the encrypt-in-place
 * migration uses to decide its own before/after counts. One batched IN(...)
 * query (the ONE allowed dynamic-placeholder pattern per the house SQL rule
 * — see EmailService.php's settings loader for the identical shape) instead
 * of 8 round-trips.
 */
function secretInventory(\mysqli $db): array
{
    $keys = secretSettingKeys();
    $out = ['encrypted' => 0, 'legacy' => 0, 'empty' => 0, 'keys' => []];
    /* Seed every key as 'empty' up front so a key with NO row at all (never
       saved yet) still appears in the report rather than being silently
       omitted — the migration/panel need to see all 8, not just the rows
       that happen to exist. */
    foreach ($keys as $k) {
        $out['keys'][$k] = 'empty';
    }
    try {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare(
            "SELECT SettingKey, SettingValue FROM tblAppSettings WHERE SettingKey IN ({$placeholders})"
        );
        $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        /* DB hiccup — every key stays classified 'empty' (the pre-seeded
           default above), which is the safe "nothing to encrypt" read for a
           status panel that must never throw. */
        return $out;
    }
    foreach ($rows as $row) {
        $key = (string)$row['SettingKey'];
        if (!isSecretSettingKey($key)) {
            continue; // defensive: IN(...) can't actually return an extra key
        }
        $val = (string)$row['SettingValue'];
        if ($val === '') {
            $out['keys'][$key] = 'empty';
        } elseif (secretIsEncrypted($val)) {
            $out['keys'][$key] = 'enc';
            $out['encrypted']++;
        } else {
            $out['keys'][$key] = 'legacy';
            $out['legacy']++;
        }
    }
    /* Recount 'empty' from the final per-key map rather than tracking it
       incrementally above, so it's always exactly (8 - encrypted - legacy)
       regardless of which keys had no row vs an empty-string row. */
    $out['empty'] = count($keys) - $out['encrypted'] - $out['legacy'];
    return $out;
}

/* =========================================================================
 * CROSS-DOCROOT ROTATION PARITY BEACON (#1466 review findings 1/4/7).
 * ELI5: strategy §10b's "per-docroot fingerprint table" is currently PROSE —
 * an operator eyeballs three status panels and decides by hand whether it's
 * safe to press Rotate. A beacon is each docroot leaving itself a Post-it
 * note in the shared database ("I hold these keys, my active one is X, here's
 * my non-secret fingerprint, and here's when I last checked") so that fact
 * can be read back and CHECKED IN CODE instead of just displayed for a human
 * to compare by eye.
 * WHY THIS EXISTS: the `.claude/project_three_docroots_prod_stale_songbookname`
 * incident (2026-06-19, MEMORY.md) is the exact failure shape this guards
 * against — production and beta silently kept running OLD code against the
 * ONE shared database the whole time nobody noticed, because nothing forced
 * a live cross-docroot check before trusting a shared resource. Key rotation
 * has the identical hazard, just one level more dangerous: if `Rotate` runs
 * while even ONE of the three docroots hasn't yet been given the new keyid
 * as a file on disk, that docroot's `secretDecrypt()` immediately starts
 * failing closed (returning null → the feature going dark) on every secret
 * rotated out from under it, the moment the shared row changes — there is no
 * "stale but working" middle state the way there was for SongbookName. A
 * beacon-backed `secretRotationParity()` (below) turns "the operator promises
 * they checked all 3 panels" into a fact the Rotate handler itself can
 * verify against the SAME shared row every docroot writes to.
 * FAIL-SAFE BY CONSTRUCTION: a beacon only proves POSITIVE facts (this
 * docroot recently confirmed it holds keyid X); the ABSENCE of a fresh beacon
 * for an env — because that docroot never called secretKeyBeaconWrite(), has
 * no key loaded, or simply hasn't been rendered recently — makes parity FALSE
 * for that env, never true by default. A keyless or silent docroot can never
 * accidentally look "ready."
 * ========================================================================= */

/** tblAppSettings key prefix for a per-environment beacon row; the actual key
 *  is this + one of SECRET_KEY_BEACON_ENVS (e.g. 'secret_key_beacon_alpha'). */
const SECRET_KEY_BEACON_PREFIX = 'secret_key_beacon_';

/** The three docroots that share this database (mirrors ihymns_environment()'s
 *  return values) — parity requires a fresh, target-holding beacon from EACH. */
const SECRET_KEY_BEACON_ENVS = ['alpha', 'beta', 'production'];

/** A beacon older than this is treated as "that docroot hasn't recently
 *  confirmed anything" — 24h comfortably covers normal admin-panel traffic on
 *  every docroot without ever letting a rotation proceed on months-stale
 *  information the docroot in question may since have lost (key file
 *  reverted, disk wiped, etc). */
const SECRET_KEY_BEACON_FRESH_SECONDS = 86400; // 24h

/**
 * Write THIS docroot's beacon into `tblAppSettings['secret_key_beacon_<env>']`
 * as JSON: `{keyids, active, fp, ts}` — its full loaded keyid list, its
 * current active keyid, its NON-SECRET key fingerprint, and a unix timestamp.
 * Best-effort, called on the Global-Admin panel render; NEVER throws.
 *
 * ELI5: every time an admin opens the secret-encryption panel on a docroot,
 * that docroot quietly writes down "as of right now, here's what keys I have"
 * — so the NEXT time anyone (human or the rotation code) asks "does every
 * docroot have the new key?", there's an up-to-date answer sitting in the
 * shared database instead of having to trust three separate people opening
 * three separate tabs at the same moment.
 * WHY no-op on a keyless docroot: if `secretCryptoReady()` is false there is
 * nothing true to report, and writing a beacon with an empty keyid list would
 * be actively misleading (it would still count as "present", just never
 * "has_target"). Simplest and safest is to write NOTHING — a keyless docroot
 * then has no fresh beacon at all, and `secretRotationParity()` below already
 * treats "no fresh beacon for this env" as failing parity for that env. This
 * is the fail-safe half of the design: a silent docroot can never look ready.
 * WHY never throws: this is called from a page-render path (the panel), not
 * a gated mutation — a JSON-encode hiccup or a transient DB error here must
 * degrade to "beacon just didn't update this time," never break the panel.
 * @see .claude/secret-encryption-strategy.md §10b (the fingerprint table this
 *      digitises) and #1466 review findings 1/4/7.
 */
function secretKeyBeaconWrite(\mysqli $db): void
{
    if (!secretCryptoReady()) {
        return; // nothing true to report; leaving no fresh beacon fails parity safe
    }
    try {
        /* environment.php isn't guaranteed loaded on every include path that
           reaches this admin module (it's normally pulled in by the request
           bootstrap, but this function must also be safe to call from a
           narrower CLI/migration context), so pull it in explicitly here. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php';
        $env = ihymns_environment();
        $payload = json_encode([
            'keyids' => secretAllKeyIds(),
            'active' => secretActiveKeyId(),
            'fp' => secretKeyFingerprint(),
            'ts' => time(),
        ]);
        if ($payload === false) {
            return; // pathological encode failure — just skip this beacon write
        }
        _secretAdminPutSetting($db, SECRET_KEY_BEACON_PREFIX . $env, $payload);
    } catch (\Throwable $e) {
        /* Best-effort — a panel render must never fail because a beacon
           write hiccupped. */
    }
}

/**
 * Read every docroot's beacon. Returns, for EACH env in SECRET_KEY_BEACON_ENVS:
 *   ['keyids'=>string[], 'active'=>?string, 'fp'=>?string, 'ts'=>int, 'age'=>int,
 *    'fresh'=>bool, 'present'=>bool]
 * A missing/unparsable row yields `present=false` (and therefore `fresh=false`).
 * NEVER throws.
 *
 * ELI5: opens all three docroots' Post-it notes and reads them back, working
 * out for each one "is this note even there, and if so, is it recent enough
 * to trust?"
 * WHY per-env rather than a single combined answer: `secretRotationParity()`
 * below needs to report WHICH docroot(s) are missing so the panel can tell
 * the operator "beta hasn't confirmed the new key yet" rather than a bare
 * "not ready" — the same three-way-over-boolean reasoning as
 * `secretSentinelStatus()` above (absent vs stale vs current are different
 * facts an operator needs to act on differently).
 * @return array<string, array{keyids:array<int,string>, active:?string,
 *              fp:?string, ts:int, age:int, fresh:bool, present:bool}>
 */
function secretKeyBeacons(\mysqli $db): array
{
    $now = time();
    $out = [];
    foreach (SECRET_KEY_BEACON_ENVS as $env) {
        $entry = [
            'keyids' => [],
            'active' => null,
            'fp' => null,
            'ts' => 0,
            'age' => PHP_INT_MAX,
            'fresh' => false,
            'present' => false,
        ];
        try {
            $raw = _secretAdminRawSetting($db, SECRET_KEY_BEACON_PREFIX . $env);
            if ($raw !== null && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $ts = isset($decoded['ts']) && is_numeric($decoded['ts']) ? (int)$decoded['ts'] : 0;
                    $age = $now - $ts;
                    $keyids = is_array($decoded['keyids'] ?? null)
                        ? array_values(array_map('strval', $decoded['keyids']))
                        : [];
                    $entry = [
                        'keyids' => $keyids,
                        'active' => isset($decoded['active']) && is_string($decoded['active']) ? $decoded['active'] : null,
                        'fp' => isset($decoded['fp']) && is_string($decoded['fp']) ? $decoded['fp'] : null,
                        'ts' => $ts,
                        'age' => $age,
                        'fresh' => $ts > 0 && $age <= SECRET_KEY_BEACON_FRESH_SECONDS,
                        'present' => true,
                    ];
                }
            }
        } catch (\Throwable $e) {
            /* DB hiccup reading this env's beacon — leave the pre-seeded
               present=false/fresh=false default rather than propagating,
               mirroring every other status helper in this file. */
        }
        $out[$env] = $entry;
    }
    return $out;
}

/**
 * Rotation parity for a candidate target keyid: is EVERY docroot (alpha,
 * beta, production) reporting a FRESH beacon whose keyid list already
 * includes `$targetKeyid`? Returns:
 *   ['ok'=>bool, 'target'=>string, 'channels'=>[env=>['fresh'=>bool,
 *    'has_target'=>bool, 'fp'=>?string, 'present'=>bool]], 'missing'=>string[]]
 * `ok` is true only when every env's `has_target` is true. NEVER throws
 * (fail-closed: any error while computing this → `ok=false`).
 *
 * ELI5: the actual go/no-go answer for "is it safe to press Rotate to this
 * new key yet?" — checks all three Post-it notes and says yes ONLY if all
 * three say "I already have that key," and otherwise hands back exactly
 * which ones are still missing so the operator knows who to go provision.
 * WHY this is the CODE gate strategy §10b only described in prose: before
 * this, "all 3 docroots show the new keyid" was something an operator judged
 * by reading three screens themselves — easy to mis-time (e.g. check beta,
 * get distracted, provision alpha and production, forget to re-check beta,
 * rotate anyway). This function makes the same shared-database row every
 * docroot already writes to (via secretKeyBeaconWrite()) the single source
 * a Rotate handler can assert against programmatically, closing exactly the
 * "we THOUGHT all three were caught up" gap that let the pre-DB-direct
 * SongbookName code run unnoticed against the shared DB for as long as it
 * did (`.claude/project_three_docroots_prod_stale_songbookname`, 2026-06-19)
 * — never trust that a shared resource's consumers are in sync without a
 * live check, because staleness on a SHARED database is invisible from any
 * single docroot's own perspective.
 * FAIL-CLOSED: any exception while reading beacons collapses straight to
 * `ok=false` with every env marked missing — a broken parity CHECK must
 * never be mistaken for a PASSED parity check.
 * @return array{ok:bool, target:string, channels:array<string,array{fresh:bool,has_target:bool,fp:?string,present:bool}>, missing:array<int,string>}
 */
function secretRotationParity(\mysqli $db, string $targetKeyid): array
{
    $result = ['ok' => false, 'target' => $targetKeyid, 'channels' => [], 'missing' => []];
    try {
        $beacons = secretKeyBeacons($db);
    } catch (\Throwable $e) {
        $beacons = []; // fall through to the "every env missing" loop below
    }
    $allOk = true;
    foreach (SECRET_KEY_BEACON_ENVS as $env) {
        $b = $beacons[$env] ?? null;
        $present = is_array($b) && ($b['present'] ?? false);
        $fresh = $present && ($b['fresh'] ?? false);
        $hasTarget = $fresh && in_array($targetKeyid, $b['keyids'] ?? [], true);
        $result['channels'][$env] = [
            'fresh' => $fresh,
            'has_target' => $hasTarget,
            'fp' => $present ? ($b['fp'] ?? null) : null,
            'present' => $present,
        ];
        if (!$hasTarget) {
            $allOk = false;
            $result['missing'][] = $env;
        }
    }
    $result['ok'] = $allOk;
    return $result;
}

/**
 * Does the DATABASE USER have the ability to read files off the server's disk
 * (the MySQL `FILE` privilege / `LOAD_FILE`)? Returns:
 *   ['file_grant'=>?bool, 'secure_file_priv'=>?string, 'can_read_keyfile'=>?bool,
 *    'verdict'=>'safe'|'at_risk'|'unknown']
 * NEVER throws (status helper).
 *
 * ELI5: the whole security promise of #1466 is "you need BOTH the database AND
 * the key file on disk to read a secret." This check makes sure the database
 * user CAN'T sneak a peek at the key file through the database itself — because
 * if it could (via `SELECT LOAD_FILE('…/secrets_master_key.php')`), a single
 * SQL-injection would grab BOTH the ciphertext AND the key, collapsing the two
 * locks into one.
 * WHY three signals: `file_grant` (from SHOW GRANTS) is the definitive capability
 * flag — `FILE` is a GLOBAL-only privilege, so no `FILE` (or `ALL PRIVILEGES`)
 * on `*.*` means LOAD_FILE can NEVER work, full stop (the shared-DreamHost case).
 * `secure_file_priv` is context (a path/NULL further restricts LOAD_FILE even
 * when FILE is granted). `can_read_keyfile` is the GROUND-TRUTH probe: it runs
 * `LOAD_FILE(<the real key path>) IS NULL` and reads ONLY that boolean — the key
 * CONTENT is never selected into the result set — so a "can actually read it"
 * result is proof-positive of the exact vulnerability, accounting for FILE +
 * secure_file_priv + filesystem perms all at once. Strategy §2/§12; #1466 review.
 */
function secretDbFilePrivilegeStatus(\mysqli $db): array
{
    $out = ['file_grant' => null, 'secure_file_priv' => null, 'can_read_keyfile' => null, 'verdict' => 'unknown'];

    /* (1) SHOW GRANTS — FILE is grantable ONLY at the global (*.*) level, and
       `GRANT ALL PRIVILEGES ON *.*` implies it without the literal word. */
    try {
        $res = $db->query('SHOW GRANTS FOR CURRENT_USER()');
        if ($res instanceof \mysqli_result) {
            $hasFile = false;
            while (($row = $res->fetch_row()) !== null) {
                $g = strtoupper((string)$row[0]);
                if (strpos($g, 'ON *.*') !== false
                    && (preg_match('/\bFILE\b/', $g) === 1 || strpos($g, 'ALL PRIVILEGES') !== false)) {
                    $hasFile = true;
                }
            }
            $res->close();
            $out['file_grant'] = $hasFile;
        }
    } catch (\Throwable $e) { /* degrade to null — verdict falls back to the probe */ }

    /* (2) secure_file_priv (server-wide LOAD_FILE/INTO OUTFILE restriction). */
    try {
        $res = $db->query('SELECT @@GLOBAL.secure_file_priv');
        if ($res instanceof \mysqli_result) {
            $row = $res->fetch_row();
            $out['secure_file_priv'] = $row !== null ? ($row[0] === null ? 'NULL (disabled)' : (string)$row[0]) : null;
            $res->close();
        }
    } catch (\Throwable $e) { /* not fatal */ }

    /* (3) Ground-truth: can the DB actually read the master key file? We select
       ONLY `LOAD_FILE(?) IS NULL` — never the file's bytes — so this probe can
       confirm the vulnerability without EVER pulling the key into a result set,
       query log, or this process's memory. Only meaningful when the key file
       exists; if it's absent LOAD_FILE is NULL for a benign reason, so we lean on
       file_grant for the verdict in that case. */
    try {
        $keyPath = secretCryptoKeyFilePath();
        if (is_file($keyPath)) {
            $stmt = $db->prepare('SELECT (LOAD_FILE(?) IS NULL) AS cannot_read');
            $stmt->bind_param('s', $keyPath);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($row !== null) {
                $out['can_read_keyfile'] = ((int)$row[0] === 1) ? false : true; // IS NULL=1 → cannot read → false
            }
        }
    } catch (\Throwable $e) { /* probe unavailable — verdict falls back to file_grant */ }

    /* Verdict: the ground-truth probe wins when available; otherwise the grant
       flag is definitive (no FILE grant ⇒ LOAD_FILE can never work). */
    if ($out['can_read_keyfile'] === true)        { $out['verdict'] = 'at_risk'; }
    elseif ($out['can_read_keyfile'] === false)   { $out['verdict'] = 'safe'; }
    elseif ($out['file_grant'] === false)         { $out['verdict'] = 'safe'; }
    elseif ($out['file_grant'] === true)          { $out['verdict'] = 'at_risk'; }
    return $out;
}

/* =========================================================================
 * PUBLIC MUTATION API — the migration/rotation engines. Transactional, MAY
 * throw (callers on the HTTP handler side catch and surface to the confirm=1
 * gated card UI).
 * ========================================================================= */

/**
 * ENCRYPT-IN-PLACE (used by the migration). For each secret key whose stored
 * value is a non-empty LEGACY plaintext, encrypt it under the active key and
 * UPDATE the row. Idempotent: skips values that are already enc: or empty.
 * Ensures the sentinel exists, then sets SECRET_ENC_ACTIVE_KEY='1' LAST (only
 * after all encryptions succeeded). Runs inside a transaction (begin/commit;
 * rollback on any error, then re-throw).
 * $audit is called ONCE PER KEY that gets encrypted: $audit('secret.encrypt_inplace', $key,
 *   ['keyid'=>secretActiveKeyId()]) — key NAMES only, NEVER the plaintext/ciphertext value.
 * PRECONDITION: caller has already gated on confirm + secretCrypto ready + sentinel green.
 * THROWS \RuntimeException if secretCryptoReady() is false (never silently store plaintext).
 * Returns ['encrypted'=>[keys...], 'skipped'=>[keys...], 'flag_set'=>true].
 *
 * ELI5: the "flip the switch" job. It walks the 8 secret fields, and for any
 * one that's STILL plain text, it locks it and saves the locked version back.
 * Once every field is locked (or was already blank/locked), it writes down
 * "the switch is now ON" so the app starts locking NEW saves automatically
 * too. It writes a one-line note to the audit log for each field it touched
 * — just the field's NAME, never what was actually in it.
 * WHY throw-if-not-ready + transaction: strategy §7 step 3 — this is the
 * one-shot, `confirm=1`-gated card. If the master key vanished mid-flight
 * (or ANY encrypt/UPDATE call fails), rolling back the WHOLE transaction
 * means the DB is left either fully pre-migration (all plaintext, flag
 * unset) or fully post-migration (all ciphertext, flag set) — never a half-
 * migrated state where some fields are locked and others aren't, which would
 * be far harder for an operator to reason about or safely retry. Setting the
 * flag LAST (inside the same transaction, so it either lands with every
 * encryption or not at all) is what makes `secretEncryptionActive()` a
 * trustworthy "is EVERYTHING encrypted" signal rather than "did the job
 * start." The audit callback is invoked ONLY for keys actually re-written
 * (never for 'empty'/already-'enc' skips) — mirrors §10's requirement that
 * every decrypt/encrypt of a real secret leaves a name-only trail.
 */
function secretEncryptInPlace(\mysqli $db, callable $audit): array
{
    if (!secretCryptoReady()) {
        throw new \RuntimeException(
            'secret_crypto_admin: cannot run encrypt-in-place — no master key is loaded on this docroot.'
        );
    }

    $result = ['encrypted' => [], 'skipped' => [], 'flag_set' => false];

    $db->begin_transaction();
    try {
        foreach (secretSettingKeys() as $key) {
            /* forUpdate=true (#1466 review finding 3): this read feeds a
               later write to the SAME row in this SAME transaction — lock it
               now so a concurrent manage/configuration.php save of this key
               can't slip in between our read and our write and get silently
               clobbered by a re-wrap of the value we already read. */
            $raw = _secretAdminRawSetting($db, $key, true);
            if ($raw === null || $raw === '' || secretIsEncrypted($raw)) {
                $result['skipped'][] = $key;
                continue;
            }
            $envelope = secretEncrypt($raw);
            _secretAdminPutSetting($db, $key, $envelope);
            $result['encrypted'][] = $key;
            $audit('secret.encrypt_inplace', $key, ['keyid' => secretActiveKeyId()]);
        }

        /* #1989 — also rewrap TABLE-HELD secrets (tblWebhookSubscriptions.Secret/
           SecretPrevious) BEFORE the cutover flag is written below, so
           "flag_set means EVERYTHING in the DB is ciphertext" (this function's
           own contract, see the doc-block above) stays true of the ONE
           table-held recoverable secret too, not just the tblAppSettings rows
           the loop above covers. */
        _secretAdminRewrapTableSecrets($db, 'encrypt', $audit, $result);

        /* Ensure the sentinel exists (it may already, from an earlier
           canary check) — the migration itself is a legitimate moment to
           mint it if somehow no docroot has yet. */
        secretSentinelEnsure($db);

        _secretAdminPutSetting($db, SECRET_ENC_ACTIVE_KEY, '1');
        $result['flag_set'] = true;

        $db->commit();
        return $result;
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Parse the keyid out of an enc:v1:<alg>:<keyid>:... envelope, or null if not
 * an envelope.
 * @internal
 *
 * ELI5: reads the "which key locked this?" label stamped on a locked value,
 * without trying to unlock it.
 * WHY: rotation needs to know a value's CURRENT keyid before deciding whether
 * it's already under the active key (skip) or needs re-wrapping (decrypt +
 * re-encrypt) — cheaper and side-effect-free compared to attempting a full
 * decrypt just to read a label. Delegates the actual split to the ONE shared
 * `_secretCryptoParseEnvelope()` in `secret_crypto.php` (#1466 review —
 * this function and `secretDecrypt()` used to each carry their own copy of
 * the same `strncmp`/`substr`/`explode` parse; that duplication is exactly
 * what the repo's modularity rule bans, and a future envelope-format change
 * only has to be taught to the one parser now instead of kept in sync by
 * hand across two files).
 */
function _secretAdminEnvelopeKeyid(string $stored): ?string
{
    $p = _secretCryptoParseEnvelope($stored);
    return $p === null ? null : $p[1]; // [alg, keyid, nonce, ciphertext] — keyid is index 1.
}

/* =========================================================================
 * TABLE-HELD SECRET REWRAP (#1989).
 * ELI5: `secretSettingKeys()` above only knows about the 8-ish secrets that
 * live as ROWS in `tblAppSettings`. There is exactly one OTHER recoverable
 * secret in this database that isn't one of those rows: the outbound-webhook
 * HMAC signing secret, which lives as a COLUMN on `tblWebhookSubscriptions`
 * (`Secret`/`SecretPrevious` — see `includes/webhooks.php`'s
 * `webhookSecretForStorage()`/`webhookSecretReveal()`). Every OTHER
 * table-held credential in this codebase (API keys, driver keys, tokens) is
 * a SHA-256 HASH, not a recoverable secret, so this machinery can never
 * apply to them — signing an outgoing request is the one case that needs
 * the plaintext back. `secretEncryptInPlace()`/`secretRotateReencrypt()`
 * never touched this column before #1989, so a pre-cutover install could
 * carry a permanently-plaintext webhook secret even after the settings-side
 * migration ran, and a master-key rotation could never move it to the new
 * key (`.claude/webhooks-1909-design.md` §7.2's "known gap", §A.14).
 * WHY THIS LIVES HERE, NOT `webhooks.php` (design intent, mirrors the module
 * banner above): this file is deliberately kept free of `webhooks.php`'s DB
 * dependencies — `webhookSchemaReady()` there memoizes IGNORING its `$db`
 * argument and checks THREE tables, the wrong shape for a single-table,
 * repeatable-in-tests probe — so `_secretAdminTableExists()` below is a
 * small, self-contained, un-memoized sibling instead of a `require_once`.
 * ========================================================================= */

/**
 * Registry of TABLE-HELD recoverable secrets — the #1989 sibling of
 * `secretSettingKeys()` (which governs `tblAppSettings` ROWS only). ONE entry
 * today. Mirrors the `TIER_CAPS` registry shape (rule #28): a future second
 * table-held secret is one more array line here, never a second hand-rolled
 * walker.
 *
 * ELI5: "besides the password-shaped settings, there's also exactly one more
 * secret hiding in an ordinary table column — and here's precisely where."
 * WHY a registry, not a hardcoded query inline in the walker: both
 * `_secretAdminRewrapTableSecrets()` (below) and the migration idempotency
 * check (`secretTableSecretInventory()`) need the SAME table/pk/column facts;
 * a registry keeps them from drifting apart, and lets a CI guard byte-match
 * the values against `schema.sql` (rule #35 — `tests/php/test-secret-crypto-
 * rewrap.php`).
 *
 * @return array<int,array{table:string,pk:string,columns:array<int,string>}>
 */
function secretTableSecretColumns(): array
{
    return [
        ['table' => 'tblWebhookSubscriptions', 'pk' => 'Id', 'columns' => ['Secret', 'SecretPrevious']],
    ];
}

/**
 * Does $table exist on this install? INFORMATION_SCHEMA.TABLES probe,
 * fail-safe → false on any error. Deliberately UN-MEMOIZED (see the section
 * banner above for why this doesn't reuse `webhookSchemaReady()`/
 * `_apiKeyTableExists()`'s static-cache shape) — this file's own CI guard
 * exercises a scratch table across several assertions in one PHP process,
 * and a stale cached answer from an earlier assertion would be exactly the
 * wrong thing to trust there.
 * @internal
 *
 * ELI5: "is the table even there yet?" — so an un-migrated docroot's rewrap
 * walk quietly does nothing (zero queries against a table that doesn't
 * exist) instead of throwing under `MYSQLI_REPORT_STRICT`.
 */
function _secretAdminTableExists(\mysqli $db, string $table): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $n > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * PURE decision: what should happen to ONE table-held secret's stored value,
 * given the operation mode? Never touches a database, never encrypts or
 * decrypts anything — purely "what's the right action" — so a CI guard can
 * exhaustively TRUTH-TABLE every cell instead of trusting a live-DB test to
 * happen to seed every case.
 *
 * ELI5: "given what's sitting in the column right now, and whether we're
 * doing the one-time 'encrypt everything' job or the 'rotate to a new key'
 * job, what's the ONE right thing to do?" — leave it alone (already correct,
 * or nothing there), or actually rewrite it.
 *
 * ⚠️ THE #1 LOAD-BEARING DECISION IN THIS FEATURE. The single most dangerous
 * mistake this feature could make is treating an ALREADY-ENVELOPED value as
 * if it still needed encrypting — a double-encrypt produces a VALID enc:v1
 * envelope whose PLAINTEXT is the *inner* envelope string, so `secretDecrypt()`
 * hands "enc:v1:…" back as the signing secret, `webhookSecretReveal()` passes
 * that straight to the HMAC, and every outgoing delivery from that moment on
 * is signed with the WRONG key — silently, with no error anywhere, and
 * unrecoverable except by tracking down the original plaintext out of band.
 * The `'encrypt'` branch below is deliberately byte-identical in spirit to
 * the existing settings-loop guards it mirrors: `secretEncryptInPlace()`'s
 * `secretIsEncrypted($raw)` skip (~line 743) and `secretRotateReencrypt()`'s
 * `!secretIsEncrypted($raw)` skip (~line 851). Extracting the decision into
 * its own pure function means that identity can be asserted ONCE, exhaustively,
 * by `tests/php/test-secret-crypto-rewrap.php`'s truth table — not trusted by
 * eye across two independent call sites.
 *
 * mode 'encrypt' (secretEncryptInPlace()'s job — plaintext ⇒ ciphertext):
 *   - null / ''                → 'skip_empty'      nothing to encrypt
 *   - already enc:v1 enveloped → 'skip_enveloped'  THE double-encrypt guard
 *   - anything else (plaintext)→ 'encrypt'
 *
 * mode 'rotate' (secretRotateReencrypt()'s job — move ciphertext to the active key):
 *   - null / ''                       → 'skip_empty'    nothing to rotate
 *   - not an enc:v1 envelope          → 'skip_plaintext' out of scope here —
 *                                        that row belongs to the encrypt-in-
 *                                        place job instead (D.1 makes it
 *                                        re-runnable so it eventually is)
 *   - envelope keyid === $activeKeyId → 'skip_current'  already current
 *   - envelope under an older keyid   → 'rewrap'
 *
 * A malformed `enc:v1:junk` value still carries the `enc:` prefix, so
 * `secretIsEncrypted()` reports it enveloped: mode 'encrypt' correctly
 * `skip_enveloped`s it (never double-encrypts garbage); mode 'rotate' returns
 * `'rewrap'` (its keyid can't be parsed, so it can't equal `$activeKeyId`) and
 * the WALKER (not this pure function) discovers on the actual decrypt attempt
 * that it can't be recovered, reclassifying it `undecryptable` — see
 * `_secretAdminRewrapTableSecrets()` below.
 *
 * @param ?string $stored      The raw column value (NOT decrypted).
 * @param string  $mode        'encrypt' or 'rotate'.
 * @param ?string $activeKeyId `secretActiveKeyId()` — only consulted in 'rotate' mode.
 * @return string One of: 'skip_empty'|'skip_enveloped'|'skip_plaintext'|'skip_current'|'encrypt'|'rewrap'
 */
function secretTableRewrapDecision(?string $stored, string $mode, ?string $activeKeyId): string
{
    if ($stored === null || $stored === '') {
        return 'skip_empty';
    }
    if ($mode === 'rotate') {
        if (!secretIsEncrypted($stored)) {
            return 'skip_plaintext';
        }
        return (_secretAdminEnvelopeKeyid($stored) === $activeKeyId) ? 'skip_current' : 'rewrap';
    }
    /* mode 'encrypt' (secretEncryptInPlace()'s job — the only other caller). */
    return secretIsEncrypted($stored) ? 'skip_enveloped' : 'encrypt';
}

/**
 * THE shared table-secret rewrap walker — called by BOTH
 * `secretEncryptInPlace()` ($mode='encrypt') and `secretRotateReencrypt()`
 * ($mode='rotate'). Runs INSIDE the CALLER's already-open transaction — this
 * function never begins or commits one itself, so a table-secret failure
 * rolls back atomically together with every settings-row change the caller
 * already made in the same pass (partial-run safety: rows+settings+flag are
 * all-or-nothing).
 *
 * ELI5: walks every row of every table in `secretTableSecretColumns()`
 * (today: just the one webhook-subscriptions table), and for each of its
 * registered columns, asks `secretTableRewrapDecision()` "what should happen
 * here?" and does exactly that — skip it, encrypt it, or rewrap it under the
 * new key — folding the outcome into the SAME `$result` arrays
 * (`encrypted`/`rewrapped`/`skipped`/`undecryptable`) the settings loop
 * already fills. Rule #35: one shape, no second consumer to keep in
 * lockstep — every existing consumer (the migration script's counts, the
 * admin panel's rendered labels) picks up webhook rows automatically with
 * nothing new to learn.
 *
 * WHY `FOR UPDATE`: the identical read-modify-write race
 * `_secretAdminRawSetting($db, $key, true)` above already guards against
 * (#1466 review finding 3) — this function reads each row, decides in PHP,
 * and writes back inside the SAME transaction; a concurrent edit of a
 * subscription's secret must either land before this transaction starts or
 * block until it commits.
 *
 * WHY the table/pk/column names in the SQL below come from
 * `secretTableSecretColumns()` ALONE, never request input: those identifiers
 * are HARDCODED PHP-source constants (repo rule #5's carve-out) —
 * `tests/php/test-secret-crypto-rewrap.php` pins the registry's values
 * against `schema.sql` byte-for-byte so a rename on one side is caught
 * immediately (rule #35).
 *
 * A fully-current table (every row already under the active key, or
 * everything already enveloped in 'encrypt' mode) makes ZERO writes — the
 * same verified-no-op discipline the settings loop already keeps.
 *
 * @param \mysqli  $db     Already inside the CALLER's transaction.
 * @param string   $mode   'encrypt' or 'rotate' — see secretTableRewrapDecision().
 * @param callable $audit  The SAME `$audit('event', 'label', [detail])` callback the caller already uses.
 * @param array    $result &-modified in place — the caller's OWN result array; this function only appends
 *                          to `encrypted`/`rewrapped`/`skipped`/`undecryptable`, never replaces it.
 */
function _secretAdminRewrapTableSecrets(\mysqli $db, string $mode, callable $audit, array &$result): void
{
    $activeKeyId = secretActiveKeyId();

    foreach (secretTableSecretColumns() as $entry) {
        $table = $entry['table'];
        $pk = $entry['pk'];
        $columns = $entry['columns'];

        if (!_secretAdminTableExists($db, $table)) {
            continue; // un-migrated install — zero queries, a clean natural no-op
        }

        /* Table/pk/columns are drawn EXCLUSIVELY from the hardcoded registry
           above (rule #5 carve-out) — never request input. FOR UPDATE locks
           every row for the rest of THIS transaction (see the doc-block WHY
           above); the table is tiny (per-host subscription cap), so a
           whole-table lock for the transaction's short duration is fine. */
        $colList = implode(', ', $columns);
        $rows = $db->query("SELECT {$pk}, {$colList} FROM {$table} ORDER BY {$pk} FOR UPDATE");
        if (!($rows instanceof \mysqli_result)) {
            continue;
        }

        while (($row = $rows->fetch_assoc()) !== null) {
            $rowId = (int)$row[$pk];
            $changed = []; // column => new value to WRITE (only columns that actually change)

            foreach ($columns as $col) {
                $stored = $row[$col] === null ? null : (string)$row[$col];
                $label = "{$table}#{$rowId}.{$col}"; // names only — NEVER the value — see repo rule #6

                switch (secretTableRewrapDecision($stored, $mode, $activeKeyId)) {
                    case 'skip_empty':
                        /* Nothing there — not worth a skip label, mirroring
                           secretEncryptInPlace()'s settings loop (which only
                           labels a skip that had a real value to leave alone). */
                        break;

                    case 'skip_enveloped':
                    case 'skip_plaintext':
                    case 'skip_current':
                        $result['skipped'][] = $label;
                        break;

                    case 'encrypt':
                        $changed[$col] = secretEncrypt($stored);
                        $result['encrypted'][] = $label;
                        $audit('secret.encrypt_inplace', $label, [
                            'keyid' => $activeKeyId,
                            'table' => $table,
                            'row_id' => $rowId,
                            'column' => $col,
                        ]);
                        break;

                    case 'rewrap':
                        $fromKeyId = _secretAdminEnvelopeKeyid($stored);
                        $plain = secretDecrypt($stored);
                        if ($plain === null) {
                            /* The key for $fromKeyId isn't loaded here, or the
                               envelope is malformed/tampered — skip-not-abort,
                               mirroring secretRotateReencrypt()'s settings-loop
                               undecryptable path exactly (do NOT hold the rest
                               of the rotation hostage on one bad row). */
                            $result['undecryptable'][] = $label;
                            $audit('secret.rotate_skip_undecryptable', $label, [
                                'from_keyid' => $fromKeyId,
                                'table' => $table,
                                'row_id' => $rowId,
                                'column' => $col,
                            ]);
                            break;
                        }
                        $changed[$col] = secretEncrypt($plain);
                        $result['rewrapped'][] = $label;
                        $audit('secret.rotate_decrypt', $label, [
                            'from_keyid' => $fromKeyId,
                            'to_keyid' => $activeKeyId,
                            'table' => $table,
                            'row_id' => $rowId,
                            'column' => $col,
                        ]);
                        break;
                }
            }

            if ($changed === []) {
                continue; // this row was already fully current — zero writes
            }

            $setList = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($changed)));
            $stmt = $db->prepare("UPDATE {$table} SET {$setList} WHERE {$pk} = ?");
            $params = array_values($changed);
            $params[] = $rowId;
            $stmt->bind_param(str_repeat('s', count($changed)) . 'i', ...$params);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/**
 * Inventory of the TABLE-HELD secrets — the #1989 sibling of
 * `secretInventory()` (which covers `tblAppSettings` rows only; left
 * UNTOUCHED — it's a panel-render contract other code already destructures,
 * so this is a genuinely separate shape rather than an incompatible reshape
 * of an existing one). For each column in `secretTableSecretColumns()`,
 * classify every row's stored value:
 *   'encrypted' = non-empty and secretIsEncrypted()
 *   'legacy'    = non-empty and NOT enveloped (plaintext at rest)
 *   'empty'     = null / '' (nothing to encrypt)
 * Returns ['present'=>bool, 'encrypted'=>int, 'legacy'=>int, 'empty'=>int, 'rows'=>int].
 * `present=false` (every count 0) when NONE of the registry's tables exist
 * yet on this install. NEVER throws (status helper, mirrors secretInventory()'s
 * own contract).
 *
 * ELI5: the webhook-table twin of the "N encrypted / M plaintext" report card
 * `secretInventory()` already produces for the settings rows — this is what
 * lets the encrypt-in-place migration (D.1) know there's STILL a plaintext
 * webhook secret sitting in the table even after the settings side is fully
 * done, so it doesn't refuse to re-run just because the cutover flag is
 * already set.
 *
 * @return array{present:bool,encrypted:int,legacy:int,empty:int,rows:int}
 */
function secretTableSecretInventory(\mysqli $db): array
{
    $out = ['present' => false, 'encrypted' => 0, 'legacy' => 0, 'empty' => 0, 'rows' => 0];
    try {
        foreach (secretTableSecretColumns() as $entry) {
            $table = $entry['table'];
            $pk = $entry['pk'];
            $columns = $entry['columns'];

            if (!_secretAdminTableExists($db, $table)) {
                continue; // un-migrated — contributes nothing to this entry
            }
            $out['present'] = true;

            $colList = implode(', ', $columns);
            $rows = $db->query("SELECT {$pk}, {$colList} FROM {$table}");
            if (!($rows instanceof \mysqli_result)) {
                continue;
            }
            while (($row = $rows->fetch_assoc()) !== null) {
                foreach ($columns as $col) {
                    $out['rows']++;
                    $val = $row[$col];
                    if ($val === null || $val === '') {
                        $out['empty']++;
                    } elseif (secretIsEncrypted((string)$val)) {
                        $out['encrypted']++;
                    } else {
                        $out['legacy']++;
                    }
                }
            }
        }
        return $out;
    } catch (\Throwable $e) {
        /* DB hiccup — degrade to "nothing to see", the safe read for a
           status helper that must never throw. */
        return ['present' => false, 'encrypted' => 0, 'legacy' => 0, 'empty' => 0, 'rows' => 0];
    }
}

/**
 * ROTATE / RE-ENCRYPT (the hardened high-value endpoint). For each secret key
 * whose stored value is an enc: envelope NOT already under the active keyid,
 * DECRYPT it (via secretDecrypt, which reads the envelope's OWN keyid — see
 * `_secretCryptoParseEnvelope()` — and uses ONLY that specific key, never
 * every loaded key) and RE-ENCRYPT under the active keyid, then UPDATE. Also
 * re-wraps the sentinel, and — since #1989 — every TABLE-HELD secret
 * registered in `secretTableSecretColumns()` (currently
 * `tblWebhookSubscriptions.Secret`/`SecretPrevious`) via the shared
 * `_secretAdminRewrapTableSecrets()` walker.
 * Transactional (begin/commit; rollback+re-throw on error).
 * $audit is called ONCE PER KEY DECRYPTED: $audit('secret.rotate_decrypt', $key,
 *   ['from_keyid'=>$oldKeyid, 'to_keyid'=>secretActiveKeyId()]) — NEVER the value.
 * Determine a value's current keyid by parsing the envelope (enc:v1:<alg>:<keyid>:...).
 * If a value fails to decrypt (secretDecrypt returns null → key for its keyid absent)
 * SKIP it, audit $audit('secret.rotate_skip_undecryptable', $key, ['from_keyid'=>$oldKeyid])
 * and continue (do NOT abort the whole rotation for one undecryptable value; report it).
 * THROWS \RuntimeException if secretCryptoReady() is false.
 * Returns ['rewrapped'=>[keys...], 'skipped'=>[keys...], 'undecryptable'=>[keys...]].
 *
 * ELI5: the "swap to a new key" job. For every field that's locked with an
 * OLD key, it unlocks it and re-locks it with the NEW key. If a field is
 * already using the new key, it leaves it alone. If — for some reason — a
 * field can't be unlocked at all (its key is missing here), it does NOT
 * blow up the whole job; it just notes "couldn't touch this one" and moves
 * on, so one bad row doesn't block rotating everything else. It re-locks the
 * shared "test message" too, so the NEXT docroot's health check is testing
 * against the NEW key, not the retired one.
 * WHY skip-not-abort on an undecrypt failure: strategy §10 frames rotation as
 * the single highest-value endpoint in this whole feature — it touches every
 * secret's plaintext in one pass. An operator running this has ALREADY
 * passed the fingerprint-parity gate (§10b step 2: rotate stays disabled
 * until all 3 docroots show the new keyid) for the NORMAL case, but a stray
 * value encrypted under some third, forgotten keyid should be surfaced
 * (`undecryptable`) for manual follow-up rather than aborting a rotation that
 * is otherwise safe for the other 7 keys — an all-or-nothing abort here would
 * let one bad row hold the whole secret set hostage on the old (soon to be
 * retired) key. The audit trail distinguishes an actual decrypt
 * (`rotate_decrypt`) from a skip (`rotate_skip_undecryptable`) so a reviewer
 * of the audit log can tell "we touched the plaintext of X" from "we could
 * not and left X alone" — both are logged, per §10's "audit-log every
 * decrypt" requirement, but only the former actually exposed the value to
 * this process's memory.
 */
function secretRotateReencrypt(\mysqli $db, callable $audit): array
{
    if (!secretCryptoReady()) {
        throw new \RuntimeException(
            'secret_crypto_admin: cannot run rotate/re-encrypt — no master key is loaded on this docroot.'
        );
    }

    $activeKeyId = secretActiveKeyId();
    $result = ['rewrapped' => [], 'skipped' => [], 'undecryptable' => []];

    $db->begin_transaction();
    try {
        foreach (secretSettingKeys() as $key) {
            /* forUpdate=true (#1466 review finding 3) — same read-modify-write
               race as secretEncryptInPlace() above: lock the row for the rest
               of this transaction so a concurrent configuration save of this
               key can't be lost when we write the re-wrapped value back. */
            $raw = _secretAdminRawSetting($db, $key, true);
            if ($raw === null || $raw === '' || !secretIsEncrypted($raw)) {
                /* Empty or still-legacy-plaintext values are out of scope for
                   rotation — they belong to secretEncryptInPlace() instead. */
                $result['skipped'][] = $key;
                continue;
            }
            $fromKeyId = _secretAdminEnvelopeKeyid($raw);
            if ($fromKeyId === $activeKeyId) {
                /* Already under the current key — nothing to do. */
                $result['skipped'][] = $key;
                continue;
            }
            $plain = secretDecrypt($raw);
            if ($plain === null) {
                /* The key for $fromKeyId isn't loaded on this docroot (or the
                   envelope is malformed/tampered) — do NOT abort the whole
                   rotation for one row; report it and move on. */
                $result['undecryptable'][] = $key;
                $audit('secret.rotate_skip_undecryptable', $key, ['from_keyid' => $fromKeyId]);
                continue;
            }
            $rewrapped = secretEncrypt($plain);
            _secretAdminPutSetting($db, $key, $rewrapped);
            $result['rewrapped'][] = $key;
            $audit('secret.rotate_decrypt', $key, [
                'from_keyid' => $fromKeyId,
                'to_keyid' => $activeKeyId,
            ]);
        }

        /* Re-wrap the sentinel too, so the NEXT docroot's parity check
           compares against ciphertext under the NEW active key, not a
           retired one. Only re-wrap if a sentinel already exists and isn't
           already current — mirrors the per-secret skip logic above so the
           sentinel doesn't get a silently different code path. forUpdate=true
           for the same read-modify-write reason as the per-key loop above —
           this read also feeds a same-transaction write to this row. */
        $sentinelRaw = _secretAdminRawSetting($db, SECRET_SENTINEL_KEY, true);
        if ($sentinelRaw !== null && $sentinelRaw !== ''
            && secretIsEncrypted($sentinelRaw)
            && _secretAdminEnvelopeKeyid($sentinelRaw) !== $activeKeyId) {
            $sentinelPlain = secretDecrypt($sentinelRaw);
            if ($sentinelPlain !== null) {
                _secretAdminPutSetting($db, SECRET_SENTINEL_KEY, secretEncrypt($sentinelPlain));
            }
            /* If the sentinel itself can't be decrypted here, leave it as-is
               — a broken sentinel will correctly show 'red' on this
               docroot's health panel afterwards, which is the honest signal,
               rather than us fabricating a new one under a plaintext this
               docroot invented. */
        }

        /* #1989 — also rotate TABLE-HELD secrets (tblWebhookSubscriptions.Secret/
           SecretPrevious) in the SAME pass, folding into the SAME $result
           arrays as the settings loop above (rule #35 — one shape). */
        _secretAdminRewrapTableSecrets($db, 'rotate', $audit, $result);

        $db->commit();
        return $result;
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
