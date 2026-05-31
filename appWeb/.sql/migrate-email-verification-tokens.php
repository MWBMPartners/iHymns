<?php

declare(strict_types=1);

/**
 * iHymns - Email Verification Tokens Migration (#898)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates tblEmailVerificationTokens to back the verification email
 * fired on password-based registration (auth_register). Stores the
 * SHA-256 hash of the raw token (raw token only ever lives in the
 * outbound email body); 24-hour expiry; single-use.
 *
 * Idempotent — re-running is a no-op when the table already exists.
 *
 * @migration-creates tblEmailVerificationTokens
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-email-verification-tokens.php
 *   Web:  /manage/setup-database -> "Email Verification Tokens (#898)" button
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

function _migEmailVerify_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migEmailVerify_tableExists(\mysqli $db, string $table): bool
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

_migEmailVerify_out('Email Verification Tokens migration starting (#898)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (_migEmailVerify_tableExists($mysqli, 'tblEmailVerificationTokens')) {
    _migEmailVerify_out('[skip] tblEmailVerificationTokens already exists.');
    _migEmailVerify_out('Email Verification Tokens migration finished (#898).');
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
    throw new \RuntimeException('CREATE TABLE tblEmailVerificationTokens failed: ' . $mysqli->error);
}

_migEmailVerify_out('[add ] tblEmailVerificationTokens (CHAR(64) PK, FK to tblUsers).');
_migEmailVerify_out('Email Verification Tokens migration finished (#898).');
