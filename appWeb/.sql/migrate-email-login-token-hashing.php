<?php

declare(strict_types=1);

/**
 * iHymns - Email Login Token Hashing Migration (#898 follow-up)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Pre-#898 follow-up tblEmailLoginTokens.Token stored the RAW 48-char
 * hex magic-link token. A DB dump exposed live tokens until they
 * expired (10 min). This migration flips the storage discipline so
 * the column holds sha256(raw_token); the helpers in auth.php now
 * hash on insert and on lookup.
 *
 * Approach: drop any unused-but-still-valid rows so the table is
 * clean post-deploy. Reset tokens are short-lived (10 min) so any
 * in-flight sign-in attempts at deploy time will need a fresh
 * "Send code" click; the cost is bounded.
 *
 * Idempotent via a sentinel row in tblAppSettings:
 *   email_login_token_hashed = '1'
 * Re-runs detect the sentinel and no-op.
 *
 * NOTE: this migration MUST land at the same time as the auth.php
 * helper changes that hash on insert + lookup. Running this without
 * the helper changes deployed leaves new rows unhashed; running the
 * helper changes without this leaves old rows that lookups can't
 * match (they expire in 10 min anyway). Apply the migration as part
 * of the same deploy. (#898 follow-up)
 *
 * @migration-modifies tblEmailLoginTokens (drops unused rows)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-email-login-token-hashing.php
 *   Web:  /manage/setup-database -> "Email Login Token Hashing" button
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migEmailLoginHash_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migEmailLoginHash_output('ERROR: MySQL credentials not found. Run install.php first.');
    return;
}
require_once $credFile;

_migEmailLoginHash_output('');
_migEmailLoginHash_output('=== iHymns - Email Login Token Hashing Migration (#898 follow-up) ===');
_migEmailLoginHash_output('');

$mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB);
if ($mysqli->connect_errno) {
    _migEmailLoginHash_output('ERROR: MySQL connection failed: ' . $mysqli->connect_error);
    return;
}
$mysqli->set_charset('utf8mb4');

/* Sentinel-driven idempotency. */
$sentinelKey = 'email_login_token_hashed';
$check = $mysqli->prepare(
    'SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?'
);
$check->bind_param('s', $sentinelKey);
$check->execute();
$sentinelRow = $check->get_result()->fetch_row();
$check->close();
if ($sentinelRow && (string)$sentinelRow[0] === '1') {
    _migEmailLoginHash_output('SKIP: sentinel email_login_token_hashed=1 already set.');
    _migEmailLoginHash_output('');
    _migEmailLoginHash_output('Migration complete (no changes needed).');
    $mysqli->close();
    return;
}

/* Confirm the table exists; nothing to do if a fresh install already
   has the new schema. */
$probe = $mysqli->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblEmailLoginTokens' LIMIT 1"
);
$tableExists = $probe && $probe->fetch_row();
if ($probe) { $probe->close(); }

if (!$tableExists) {
    _migEmailLoginHash_output('SKIP: tblEmailLoginTokens not present yet — running install.php / fresh install.');
} else {
    /* Drop any rows holding raw tokens. The new helpers will start
       writing sha256-hashed values; mixing the two would let a
       lookup with hash(<raw>) miss a row stored as <raw>. */
    $countRes = $mysqli->query('SELECT COUNT(*) FROM tblEmailLoginTokens');
    $countRow = $countRes ? $countRes->fetch_row() : [0];
    if ($countRes) { $countRes->close(); }
    $rowCount = (int)($countRow[0] ?? 0);
    if ($rowCount > 0) {
        if (!$mysqli->query('DELETE FROM tblEmailLoginTokens')) {
            _migEmailLoginHash_output('ERROR: DELETE failed: ' . $mysqli->error);
            $mysqli->close();
            return;
        }
        _migEmailLoginHash_output("CLEARED: {$rowCount} legacy row(s) from tblEmailLoginTokens.");
        _migEmailLoginHash_output('Any user mid-sign-in will need to request a fresh code.');
    } else {
        _migEmailLoginHash_output('OK: tblEmailLoginTokens already empty — nothing to clear.');
    }
}

/* Set the sentinel so subsequent runs are no-ops. */
$desc = 'tblEmailLoginTokens.Token now stores sha256 hash (#898 follow-up)';
$set = $mysqli->prepare(
    'INSERT INTO tblAppSettings (SettingKey, SettingValue, Description)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue), Description = VALUES(Description)'
);
$one = '1';
$set->bind_param('sss', $sentinelKey, $one, $desc);
if (!$set->execute()) {
    _migEmailLoginHash_output('WARN: could not set sentinel (' . $mysqli->error . '); migration body still ran.');
} else {
    _migEmailLoginHash_output('SENTINEL: tblAppSettings.email_login_token_hashed = 1');
}
$set->close();

_migEmailLoginHash_output('');
_migEmailLoginHash_output('Migration complete.');

$mysqli->close();
