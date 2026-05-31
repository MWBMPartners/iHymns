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
 * Pre-existing rows hold a 48-char prefix; the post-ALTER lookup
 * computes a 64-char hash that won't match a 48-char-prefix row.
 * Reset tokens expire in 1 hour, so any in-flight tokens at deploy
 * time naturally cycle out within the hour and the issue self-resolves.
 *
 * Idempotent — re-running is a no-op when the column is already wide.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-password-reset-token-hash-width.php
 *   Web:  /manage/setup-database -> "Password Reset Token Hash Width (#898 follow-up)" button
 *
 * @requires PHP 8.1+ with mysqli extension
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migPwResetWidth_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

_migPwResetWidth_out('Password Reset Token Hash Width migration starting (#898 follow-up)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* Probe the current column width before touching it. */
$stmt = $mysqli->prepare(
    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblPasswordResetTokens'
        AND COLUMN_NAME  = 'Token'
      LIMIT 1"
);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    _migPwResetWidth_out('[skip] tblPasswordResetTokens.Token column not found '
                       . '(table may not exist yet — run install.php first).');
    _migPwResetWidth_out('Password Reset Token Hash Width migration finished (#898 follow-up).');
    return;
}

$len  = (int)($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
$type = strtolower((string)$row['DATA_TYPE']);
if ($len >= 64 && $type === 'char') {
    _migPwResetWidth_out('[skip] tblPasswordResetTokens.Token is already CHAR(64).');
    _migPwResetWidth_out('Password Reset Token Hash Width migration finished (#898 follow-up).');
    return;
}

_migPwResetWidth_out("Current column: {$type}({$len}) — widening to CHAR(64)…");

if (!$mysqli->query('ALTER TABLE tblPasswordResetTokens MODIFY Token CHAR(64) NOT NULL')) {
    throw new \RuntimeException(
        'ALTER TABLE tblPasswordResetTokens MODIFY Token CHAR(64) failed: ' . $mysqli->error
    );
}

_migPwResetWidth_out('[add ] tblPasswordResetTokens.Token -> CHAR(64) NOT NULL.');
_migPwResetWidth_out('Note: any password-reset tokens created before this migration ran');
_migPwResetWidth_out('hold a 48-char prefix and will fail to validate. They expire in 1');
_migPwResetWidth_out('hour, so any user mid-reset needs to request a fresh link.');
_migPwResetWidth_out('Password Reset Token Hash Width migration finished (#898 follow-up).');
