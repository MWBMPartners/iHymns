<?php

declare(strict_types=1);

/**
 * iHymns — ENCRYPT SECRETS IN PLACE (#1466 P3 — the gated encrypt-in-place migration)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * The one-shot cutover step of the #1466 secret-encryption-at-rest program: encrypts
 * the 8 flagged `tblAppSettings` secret values (SMTP password, SendGrid / Mailgun / SES
 * API keys, the Azure Graph client secret, the Gmail service-account JSON, and the
 * Sign-in-with-Apple `.p8` private key — see `secretSettingKeys()` in
 * `includes/secret_crypto.php`) IN PLACE under the master key loaded on this docroot,
 * then flips `tblAppSettings.secret_encryption_active` to `'1'` so every FUTURE save
 * (via `manage/configuration.php`) also encrypts on write. See
 * `.claude/secret-encryption-strategy.md` (the locked design) — §7 for migration
 * sequencing, §8 for the cross-docroot sentinel gate this script enforces, §9 for the
 * post-migration provider-side key rotation this script reminds the operator to do.
 *
 * ⚠ GATED, NOT DESTRUCTIVE. This migration WRITES ciphertext over plaintext rows — it
 * does not drop or delete anything, and the deployed readers (`secretDecrypt()`, live
 * everywhere since #1466 P1) transparently decrypt the new ciphertext, so every read
 * keeps working unchanged the instant it runs. It is still one-shot + `manual` +
 * `confirm=1`-gated (mirroring the #1235 P4/C6 JSON-column drop's Stage-0 model)
 * because running it BEFORE the P1 readers are live on all 3 docroots would strand a
 * lagging docroot with ciphertext it cannot decrypt — the exact prod-stale hazard §7
 * exists to prevent (the SongbookName incident, this time on secrets).
 *
 * PRECONDITION (readers-first, §7): the P1 decrypt-capable read path
 * (`includes/secret_crypto.php` + every reader wired through `secretDecrypt()`) MUST
 * already be live on ALL THREE docroots (alpha, beta, production — one shared MySQL)
 * before this card is ever run. Until it runs, every secret stays legacy plaintext and
 * every read is a verified no-op; running it while any docroot still lacks the P1
 * readers means that docroot immediately starts failing to decrypt secrets it needs
 * (SMTP send, SIWA verify) — a silent production outage, not a loud error.
 *
 * REVERSIBILITY: there is NO automated reverse migration — encrypting a value is
 * one-directional by construction (the plaintext row is simply overwritten with its
 * ciphertext; there is no "undo" button). That said, this is NOT destructive in the
 * data-loss sense: as long as the master key file that did the encrypting still
 * exists on at least one docroot, every value can be decrypted back to plaintext at
 * any time (`secretDecrypt()`, or via `secretRotateReencrypt()`'s decrypt step). The
 * one true "undo" failure mode is KEY LOSS — losing every copy of
 * `appWeb/.auth/secrets_master_key.php` makes every encrypted secret permanently
 * unreadable (the feature then goes dormant per `secret_crypto.php`'s fail-safe
 * design, never a fatal error) — which is exactly why Stage 0 below REFUSES to run
 * unless the cross-docroot sentinel round-trips GREEN: that is proof THIS docroot's
 * key can decrypt the SAME ciphertext another docroot encrypted, i.e. the key is
 * provisioned + consistent BEFORE we bet the only remaining copies of these 8 secrets
 * on it.
 *
 * NOTE ON SCHEMA: this migration creates NO tables/columns — it only UPDATEs existing
 * `tblAppSettings` rows (rewriting their VALUE in place; the key/shape is unchanged)
 * and sets one flag row. No `appWeb/.sql/schema.sql` change and no
 * `@migration-adds` / `@migration-drops` doctag is required or applicable (rule #19
 * only governs schema-SHAPE changes — CREATE TABLE / ADD COLUMN — not data rewrites).
 *
 * USAGE:
 *   Web:  /manage/setup-database → "🔐 Encrypt secrets at rest — ENCRYPT-IN-PLACE (#1466)"
 *         (the card auto-appends &confirm=1 for a `manual` registry entry; see
 *         `appWeb/public_html/manage/includes/migration-registry.php`).
 *   CLI:  php appWeb/.sql/migrate-secret-encrypt-inplace.php
 */

$isCli = (PHP_SAPI === 'cli');
/* Resolve includes/ via the runner's real docroot: the deployed docroot is
   renamed per channel (public_html_dev/_beta), so a literal
   '/public_html/includes/…' is WRONG off main (the #1196 deploy-path trap).
   Repo fallback for a standalone/CLI run. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once $_incDir . '/db_mysql.php';
}
require_once $_incDir . '/secret_crypto_admin.php';

/** Echo helper — mirrors migrate-retire-component-lines-json.php's _migRetire_out(). */
function _migSecEnc_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

_migSecEnc_out('=== iHymns — ENCRYPT SECRETS IN PLACE (#1466 P3) ===');

try {
    $db = function_exists('getDbMysqli') ? getDbMysqli() : null;
    if (!($db instanceof \mysqli)) {
        _migSecEnc_out('[ERROR] no DB — could not connect to the database.');
        return;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    /* --- Idempotency: already fully encrypted (flag set AND 0 legacy left)? --- */
    if (secretEncryptionActive($db)) {
        $existingInv = secretInventory($db);
        if ((int)$existingInv['legacy'] === 0) {
            _migSecEnc_out('[SKIP] already encrypted-at-rest (flag set, 0 legacy values).');
            return;
        }
    }

    /* ================= STAGE 0 — abort-don't-encrypt gate ================= */
    _migSecEnc_out("--- Stage 0: confirm + key-ready + sentinel gate (abort-don't-encrypt) ---");

    /* (0) Explicit confirm gate — a WEB run MUST carry ?confirm=1 (mirrors the
       #1235 P4/C6 destructive-drop model), so this one-shot cutover can NEVER be
       triggered by an "Apply all" bulk fetch (which sends no confirm), independent
       of the registry 'manual' exclusion. A CLI run is already a deliberate
       operator action. */
    if (!$isCli && (($_GET['confirm'] ?? '') !== '1')) {
        _migSecEnc_out('  [REFUSE] destructive/gated — re-run with &confirm=1 (it is intentionally excluded from "Apply all").');
        return;
    }

    /* (a) Master key loaded on THIS docroot. */
    if (!secretCryptoReady()) {
        _migSecEnc_out('  [REFUSE] no master key loaded on this docroot — provision appWeb/.auth/secrets_master_key.php first (all 3 docroots, same fingerprint).');
        return;
    }

    /* (b) Cross-docroot sentinel round-trips green — strategy §8's hard pre-migration gate. */
    $sentinel = secretSentinelStatus($db);
    if ($sentinel !== 'green') {
        _migSecEnc_out("  [REFUSE] sentinel status is {$sentinel} — this docroot cannot prove it shares the key that will encrypt the shared DB. Provision + verify identical fingerprints on ALL 3 docroots, ensure a green sentinel, THEN run.");
        return;
    }
    _migSecEnc_out('  [OK] confirm + master key ready + sentinel green — proceeding.');

    /* ================= DO THE WORK ================= */
    $before = secretInventory($db);
    _migSecEnc_out("  Before: {$before['legacy']} legacy-plaintext, {$before['encrypted']} already-encrypted, {$before['empty']} empty (of 8 tracked secrets).");

    /* Audit callback: key NAMES only, never the plaintext/ciphertext value —
       per strategy §10's "audit-log every decrypt" requirement. */
    $audit = function (string $a, string $k, array $d): void {
        if (function_exists('logActivity')) {
            try { logActivity($a, 'app_setting', $k, $d); } catch (\Throwable $_e) { }
        }
    };
    $report = secretEncryptInPlace($db, $audit);

    $after = secretInventory($db);
    _migSecEnc_out('  Encrypted this run: ' . count($report['encrypted'])
        . (empty($report['encrypted']) ? '' : ' (' . implode(', ', $report['encrypted']) . ')'));
    _migSecEnc_out('  Skipped (already encrypted / empty): ' . count($report['skipped']));
    _migSecEnc_out('  Cutover flag (secret_encryption_active) set: ' . ($report['flag_set'] ? 'yes' : 'no'));
    _migSecEnc_out("  After: {$after['legacy']} legacy-plaintext, {$after['encrypted']} encrypted, {$after['empty']} empty (of 8 tracked secrets).");

    if (function_exists('logActivity')) {
        try {
            logActivity(
                'setup.secret_encrypt_inplace',
                'database',
                '',
                ['encrypted' => count($report['encrypted'])],
                'success'
            );
        } catch (\Throwable $_e) { }
    }

    _migSecEnc_out('[DONE] Secrets encrypted at rest. REMINDER (strategy §9): rotate at the'
        . ' PROVIDER side any secret that had a plaintext-era DB backup (SMTP / SendGrid /'
        . ' Mailgun / SES / Graph / Gmail SA) — encryption protects future dumps only, not'
        . ' past ones. (No plaintext-era backup exists for the SIWA .p8 — it was entered'
        . ' directly under this program, so nothing to rotate there.)');
} catch (\Throwable $e) {
    _migSecEnc_out('[ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
}

return;
