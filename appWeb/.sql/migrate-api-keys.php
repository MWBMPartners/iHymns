<?php

declare(strict_types=1);

/**
 * iHymns — Machine-to-machine API keys (#1064)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Create tblApiKeys so external services (e.g. MeedyaDL #907) can authenticate
 * to the lyrics-ingest endpoint with a per-client, hashed, revocable key
 * rather than a session. Keys are stored as a SHA-256 hash (the raw key is
 * shown once at creation and never persisted); a short non-secret prefix is
 * kept for identification in the admin list. Each key carries a space-
 * separated Scope (e.g. "lyrics:ingest") so the endpoint can authorise.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (CREATE TABLE IF NOT EXISTS).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-api-keys.php
 *   Web:  /manage/setup-database → "API keys (machine-to-machine)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migApiKeys_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migApiKeys_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migApiKeys_output("");
_migApiKeys_output("=== iHymns — Machine-to-machine API keys (#1064) ===");
_migApiKeys_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migApiKeys_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migApiKeys_output("Connected to MySQL: " . DB_NAME);

try {
    _migApiKeys_output("");
    _migApiKeys_output("--- Step 1: tblApiKeys ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblApiKeys (
            Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            Label       VARCHAR(120)    NOT NULL COMMENT 'Human label, e.g. MeedyaDL',
            KeyHash     CHAR(64)        NOT NULL COMMENT 'SHA-256 hex of the raw key (raw never stored)',
            KeyPrefix   VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'Non-secret leading chars for identification',
            Scope       VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Space-separated scopes, e.g. lyrics:ingest',
            Active      TINYINT(1)      NOT NULL DEFAULT 1,
            LastUsedAt  DATETIME        NULL DEFAULT NULL,
            LastUsedIp  VARCHAR(45)     NULL DEFAULT NULL,
            CreatedBy   INT UNSIGNED    NULL DEFAULT NULL COMMENT 'tblUsers.Id of the admin who created it',
            CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY uq_KeyHash (KeyHash),
            INDEX idx_Active (Active),

            CONSTRAINT fk_ApiKeys_CreatedBy
                FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    _migApiKeys_output("  [OK] tblApiKeys ready.");

    _migApiKeys_output("");
    _migApiKeys_output("--- Summary ---");
    _migApiKeys_output("  External services can now hold a hashed, scoped, revocable key.");
    _migApiKeys_output("  Manage keys at /manage/api-keys; the lyrics-ingest endpoint (#1064)");
    _migApiKeys_output("  requires a key with the 'lyrics:ingest' scope.");
    _migApiKeys_output("");
    _migApiKeys_output("Migration complete.");
} catch (\Throwable $e) {
    _migApiKeys_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
