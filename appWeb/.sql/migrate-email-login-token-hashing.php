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
 * of the same deploy.
 *
 * @migration-modifies tblEmailLoginTokens (drops unused rows)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-email-login-token-hashing.php
 *   Web:  /manage/setup-database -> "Email Login Token Hashing (#898 follow-up)" button
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

function _migEmailLoginHash_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migEmailLoginHash_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migEmailLoginHash_out('Email Login Token Hashing migration starting (#898 follow-up)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

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
    _migEmailLoginHash_out('[skip] sentinel email_login_token_hashed=1 already set.');
    _migEmailLoginHash_out('Email Login Token Hashing migration finished (#898 follow-up).');
    return;
}

if (!_migEmailLoginHash_tableExists($mysqli, 'tblEmailLoginTokens')) {
    _migEmailLoginHash_out('[skip] tblEmailLoginTokens not present yet — fresh install / install.php first.');
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
            throw new \RuntimeException('DELETE FROM tblEmailLoginTokens failed: ' . $mysqli->error);
        }
        _migEmailLoginHash_out("[purge] cleared {$rowCount} legacy row(s) from tblEmailLoginTokens.");
        _migEmailLoginHash_out('        any user mid-sign-in needs to request a fresh code.');
    } else {
        _migEmailLoginHash_out('[ok  ] tblEmailLoginTokens already empty — nothing to clear.');
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
    _migEmailLoginHash_out('[warn] could not set sentinel (' . $mysqli->error . '); migration body still ran.');
} else {
    _migEmailLoginHash_out('[mark] tblAppSettings.email_login_token_hashed = 1.');
}
$set->close();

_migEmailLoginHash_out('Email Login Token Hashing migration finished (#898 follow-up).');
