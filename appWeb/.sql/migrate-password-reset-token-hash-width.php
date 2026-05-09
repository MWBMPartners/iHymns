<?php

declare(strict_types=1);

/**
 * iHymns - Password Reset Token Hash Width Migration (#898 follow-up)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Widens tblPasswordResetTokens.Token from VARCHAR(48) to CHAR(64) so
 * the SHA-256 hex hash (64 chars) is stored at full width rather than
 * silently truncated to 48 chars.
 *
 * @migration-modifies tblPasswordResetTokens.Token
 *
 * Pre-existing rows hold a 48-char prefix; lookups for them stay
 * functional (the lookup query also produces a 64-char hash, but the
 * primary-key match against an old row would now fail). Reset tokens
 * expire in 1 hour, so any in-flight tokens at deploy time naturally
 * cycle out within the hour and the issue self-resolves.
 *
 * Idempotent — re-running is a no-op when the column is already wide.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-password-reset-token-hash-width.php
 *   Web:  /manage/setup-database -> "Password Reset Token Hash Width" button
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migPwResetWidth_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migPwResetWidth_output('ERROR: MySQL credentials not found. Run install.php first.');
    return;
}
require_once $credFile;

_migPwResetWidth_output('');
_migPwResetWidth_output('=== iHymns - Password Reset Token Hash Width Migration (#898 follow-up) ===');
_migPwResetWidth_output('');

$mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB);
if ($mysqli->connect_errno) {
    _migPwResetWidth_output('ERROR: MySQL connection failed: ' . $mysqli->connect_error);
    return;
}
$mysqli->set_charset('utf8mb4');

/* Probe the current column width before touching it. */
$probe = $mysqli->query(
    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblPasswordResetTokens'
        AND COLUMN_NAME  = 'Token'
      LIMIT 1"
);
$row = $probe ? $probe->fetch_assoc() : null;
if ($probe) { $probe->close(); }

if (!$row) {
    _migPwResetWidth_output('SKIP: tblPasswordResetTokens.Token column not found '
                          . '(table may not exist yet — run install.php first).');
    $mysqli->close();
    return;
}

$len  = (int)($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
$type = strtolower((string)$row['DATA_TYPE']);
if ($len >= 64 && $type === 'char') {
    _migPwResetWidth_output('SKIP: tblPasswordResetTokens.Token is already CHAR(64) — no change needed.');
    _migPwResetWidth_output('');
    _migPwResetWidth_output('Migration complete (no changes needed).');
    $mysqli->close();
    return;
}

_migPwResetWidth_output("Current column: {$type}({$len}) — widening to CHAR(64)...");

if (!$mysqli->query('ALTER TABLE tblPasswordResetTokens MODIFY Token CHAR(64) NOT NULL')) {
    _migPwResetWidth_output('ERROR: ALTER TABLE failed: ' . $mysqli->error);
    $mysqli->close();
    return;
}

_migPwResetWidth_output('MODIFIED: tblPasswordResetTokens.Token -> CHAR(64) NOT NULL');
_migPwResetWidth_output('');
_migPwResetWidth_output('Note: any password-reset tokens created before this migration ran');
_migPwResetWidth_output('hold a 48-char prefix and will fail to validate. They expire in 1');
_migPwResetWidth_output('hour, so any user who started a reset just before deploy needs to');
_migPwResetWidth_output('request a fresh link.');
_migPwResetWidth_output('');
_migPwResetWidth_output('Migration complete.');

$mysqli->close();
