<?php

declare(strict_types=1);

/**
 * iHymns — Email Verification Tokens Migration (#898)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates tblEmailVerificationTokens to back the verification email
 * fired on password-based registration (auth_register). Stores the
 * SHA-256 hash of the raw token (raw token only ever lives in the
 * outbound email body); 24-hour expiry; single-use.
 *
 * @migration-creates tblEmailVerificationTokens
 *
 * Idempotent — re-running is a no-op when the table already exists.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-email-verification-tokens.php
 *   Web:  /manage/setup-database -> "Email Verification Tokens" button
 *         (entry point requires global_admin)
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migEmailVerify_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migEmailVerify_output('ERROR: MySQL credentials not found. Run install.php first.');
    return;
}
require_once $credFile;

_migEmailVerify_output('');
_migEmailVerify_output('=== iHymns - Email Verification Tokens Migration (#898) ===');
_migEmailVerify_output('');

$mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB);
if ($mysqli->connect_errno) {
    _migEmailVerify_output('ERROR: MySQL connection failed: ' . $mysqli->connect_error);
    return;
}
$mysqli->set_charset('utf8mb4');

/* Existence check — keep the migration idempotent. */
$check = $mysqli->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblEmailVerificationTokens' LIMIT 1"
);
$exists = $check && $check->fetch_row();
if ($check) { $check->close(); }

if ($exists) {
    _migEmailVerify_output('SKIP: tblEmailVerificationTokens already exists.');
    _migEmailVerify_output('');
    _migEmailVerify_output('Migration complete (no changes needed).');
    $mysqli->close();
    return;
}

$sql = <<<'SQL'
CREATE TABLE tblEmailVerificationTokens (
    TokenHash       CHAR(64)        NOT NULL PRIMARY KEY COMMENT 'sha256 of raw token',
    UserId          INT UNSIGNED    NOT NULL,
    Email           VARCHAR(255)    NOT NULL COMMENT 'Email at the moment the token was issued',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       TIMESTAMP       NOT NULL,
    Used            TINYINT(1)      NOT NULL DEFAULT 0,

    INDEX idx_User      (UserId),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_VerifyTokens_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$mysqli->query($sql)) {
    _migEmailVerify_output('ERROR: CREATE TABLE failed: ' . $mysqli->error);
    $mysqli->close();
    return;
}
_migEmailVerify_output('CREATED: tblEmailVerificationTokens');
_migEmailVerify_output('');
_migEmailVerify_output('Migration complete.');

$mysqli->close();
