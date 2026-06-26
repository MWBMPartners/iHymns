<?php

declare(strict_types=1);

/**
 * iHymns — User-Data DB-First Sync Schema (WS-F/G #1018 / #1019)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds the two schema pieces the DB-first + auto-sync rewrite of the user
 * data stores (setlists / favourites / custom tags / history) needs:
 *
 *   1. tblUserCustomTags — per-user pool of custom favourite-tag names,
 *      the DB-first counterpart of the localStorage `ihymns_custom_tags`
 *      array. Distinct from the curator-managed global tblSongTags.
 *   2. tblUserFavorites.Tags — a JSON column carrying each favourite's
 *      per-song tags so they survive a cross-device sync (previously the
 *      favourites sync sent bare song IDs and silently dropped tags).
 *
 * Setlists + favourites + history already have their server tables
 * (tblUserSetlists / tblUserFavorites / tblSongHistory); this migration
 * only fills the two gaps. No data is moved or destroyed — fresh installs
 * read the same shape from schema.sql.
 *
 * DATA-PRESERVING + IDEMPOTENT. Each step guards on existence
 * (CREATE TABLE IF NOT EXISTS; ADD COLUMN gated on SHOW COLUMNS), so the
 * whole script is a no-op once applied and safe to re-run.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-user-data-sync.php
 *   Web:  /manage/setup-database → "User-data DB-first sync" button
 *
 * PREREQUISITES:
 *   - install.php has been run (tblUsers + tblUserFavorites exist).
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migUserDataSync_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* =========================================================================
 * LOAD MYSQL CREDENTIALS
 * ========================================================================= */

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migUserDataSync_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

/* =========================================================================
 * CONNECT TO MYSQL
 * ========================================================================= */

_migUserDataSync_output("");
_migUserDataSync_output("=== iHymns — User-Data DB-First Sync Schema (WS-F/G #1018 / #1019) ===");
_migUserDataSync_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migUserDataSync_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migUserDataSync_output("Connected to MySQL: " . DB_NAME);

/* =========================================================================
 * STEP 1: tblUserCustomTags
 * ========================================================================= */

_migUserDataSync_output("");
_migUserDataSync_output("--- Step 1: tblUserCustomTags (per-user custom tag pool) ---");

try {
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblUserCustomTags (
            UserId          INT UNSIGNED    NOT NULL,
            Tag             VARCHAR(50)     NOT NULL,
            CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (UserId, Tag),
            INDEX idx_User (UserId),

            CONSTRAINT fk_CustomTags_User
                FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    _migUserDataSync_output("  [OK] tblUserCustomTags ready.");
} catch (\Throwable $e) {
    _migUserDataSync_output("  [ERROR] Could not create tblUserCustomTags: " . $e->getMessage());
    $mysql->close();
    return;
}

/* =========================================================================
 * STEP 2: tblUserFavorites.Tags column
 * ========================================================================= */

_migUserDataSync_output("");
_migUserDataSync_output("--- Step 2: tblUserFavorites.Tags (per-favourite tags) ---");

try {
    $colRes = $mysql->query("SHOW COLUMNS FROM tblUserFavorites LIKE 'Tags'");
    if ($colRes && $colRes->num_rows > 0) {
        _migUserDataSync_output("  [SKIP] tblUserFavorites.Tags already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblUserFavorites
                ADD COLUMN Tags JSON NULL
                COMMENT 'Per-favourite user tags (#122) — JSON array of strings; NULL = untagged (WS-G #1019)'
                AFTER SongId"
        );
        _migUserDataSync_output("  [OK] Added tblUserFavorites.Tags.");
    }
} catch (\Throwable $e) {
    _migUserDataSync_output("  [ERROR] Could not add tblUserFavorites.Tags: " . $e->getMessage());
    $mysql->close();
    return;
}

/* =========================================================================
 * SUMMARY
 * ========================================================================= */

_migUserDataSync_output("");
_migUserDataSync_output("--- Summary ---");
_migUserDataSync_output("  tblUserCustomTags created; tblUserFavorites.Tags added.");
_migUserDataSync_output("  Setlists / favourites / custom tags / history can now sync DB-first.");
_migUserDataSync_output("");
_migUserDataSync_output("Migration complete.");

$mysql->close();
return;
