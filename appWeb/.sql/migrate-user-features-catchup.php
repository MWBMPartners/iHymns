<?php

declare(strict_types=1);

/**
 * iHymns — User Features Catch-Up Migration (#517)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Catches up three pieces of user-feature schema that landed in
 * `appWeb/.sql/schema.sql` without an accompanying forward-migration.
 * Fresh installs got them via `install.php`; existing alpha/dev/beta
 * databases didn't, and the gap was only surfaced by the first run of
 * `/manage/schema-audit` (#519). Specifically:
 *
 *   1. tblUserGroups.AllowCardReorder
 *      — TINYINT(1) NOT NULL DEFAULT 1
 *      — Group members may customise dashboard / home card layout (#448).
 *      — Default 1 keeps existing groups permissive (matches install.php).
 *
 *   2. tblUserSetlists
 *      — Per-user set-list storage (Id, UserId, SetlistId, Name,
 *        SongsJson, CreatedAt, UpdatedAt) plus FK to tblUsers.
 *      — `migrate-users.php` already INSERTs into this table, but no
 *        migration ever CREATEd it on existing DBs — accidental gap
 *        from an earlier refactor.
 *
 *   3. tblSearchQueries
 *      — Search analytics table (Id, Query, ResultCount, UserId,
 *        SearchedAt) plus FK to tblUsers.
 *      — `manage/analytics.php` SELECTs from it and even has a
 *        hardcoded "(or the tblSearchQueries table hasn't been created
 *        — re-run install)" fallback message — known-but-untracked.
 *
 * Idempotent — re-running is safe. Each step probes for existence
 * (column or table) before attempting the ALTER / CREATE.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-user-features-catchup.php
 *   Web: /manage/setup-database → "User Features Catch-Up Migration"
 *        (entry point requires global_admin)
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migUserFeatures_out(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* Credentials — same path as every other migration script. */
$credPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credPath)) {
    _migUserFeatures_out('ERROR: db_credentials.php not found — run install.php first.');
    exit(1);
}
if (!defined('DB_HOST')) {
    require_once $credPath;
}

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int)DB_PORT : 3306);
if ($mysqli->connect_errno) {
    _migUserFeatures_out('ERROR: MySQL connect failed: ' . $mysqli->connect_error);
    exit(1);
}
$mysqli->set_charset('utf8mb4');

/* Relax SQL strict mode for this session — schema.sql declares
   `SongsJson MEDIUMTEXT NOT NULL DEFAULT '[]'` which MySQL refuses
   under STRICT_TRANS_TABLES (the default since 5.7) with:
       "BLOB, TEXT, GEOMETRY or JSON column 'X' can't have a default"
   install.php gets away with it because the deployed server's mode
   has historically been permissive; this migration may run on a
   stricter server, so we explicitly drop strict mode for the
   connection only. NO_ENGINE_SUBSTITUTION is the conservative
   replacement (just guards against silently switching engines). */
$mysqli->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

_migUserFeatures_out('=== iHymns User Features Catch-Up Migration (#517) ===');
_migUserFeatures_out('Database: ' . DB_NAME . ' @ ' . DB_HOST);
_migUserFeatures_out('');

function _migUserFeatures_colExists(mysqli $db, string $table, string $col): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function _migUserFeatures_tableExists(mysqli $db, string $table): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/* ----------------------------------------------------------------------
 * Step 1: tblUserGroups.AllowCardReorder column
 * ---------------------------------------------------------------------- */
_migUserFeatures_out('--- Step 1: tblUserGroups.AllowCardReorder column ---');
if (!_migUserFeatures_tableExists($mysqli, 'tblUserGroups')) {
    _migUserFeatures_out('  [WARN] tblUserGroups missing — run install.php first; skipping.');
} elseif (_migUserFeatures_colExists($mysqli, 'tblUserGroups', 'AllowCardReorder')) {
    _migUserFeatures_out('  [skip] tblUserGroups.AllowCardReorder already present.');
} else {
    $sql = "ALTER TABLE tblUserGroups
            ADD COLUMN AllowCardReorder TINYINT(1) NOT NULL DEFAULT 1
            COMMENT 'Group members may customise dashboard / home card layout (#448)'
            AFTER AccessRtw";
    if (!$mysqli->query($sql)) {
        _migUserFeatures_out('  [ERROR] Could not add AllowCardReorder: ' . $mysqli->error);
    } else {
        _migUserFeatures_out('  [add ] tblUserGroups.AllowCardReorder.');
    }
}
_migUserFeatures_out('');

/* ----------------------------------------------------------------------
 * Step 2: tblUserSetlists table
 * ---------------------------------------------------------------------- */
_migUserFeatures_out('--- Step 2: tblUserSetlists table ---');
if (_migUserFeatures_tableExists($mysqli, 'tblUserSetlists')) {
    _migUserFeatures_out('  [skip] tblUserSetlists already present.');
} else {
    $sql = "CREATE TABLE IF NOT EXISTS tblUserSetlists (
        Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        UserId          INT UNSIGNED    NOT NULL,
        SetlistId       VARCHAR(100)    NOT NULL COMMENT 'Client-generated unique ID',
        Name            VARCHAR(200)    NOT NULL,
        SongsJson       MEDIUMTEXT      NOT NULL DEFAULT '[]' COMMENT 'JSON array of song objects',
        CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uq_UserSetlist (UserId, SetlistId),
        INDEX idx_User (UserId),

        CONSTRAINT fk_Setlists_User
            FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migUserFeatures_out('  [ERROR] Could not create tblUserSetlists: ' . $mysqli->error);
    } else {
        _migUserFeatures_out('  [add ] tblUserSetlists.');
    }
}
_migUserFeatures_out('');

/* ----------------------------------------------------------------------
 * Step 3: tblSearchQueries table
 * ---------------------------------------------------------------------- */
_migUserFeatures_out('--- Step 3: tblSearchQueries table ---');
if (_migUserFeatures_tableExists($mysqli, 'tblSearchQueries')) {
    _migUserFeatures_out('  [skip] tblSearchQueries already present.');
} else {
    $sql = "CREATE TABLE IF NOT EXISTS tblSearchQueries (
        Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        Query           VARCHAR(500)    NOT NULL,
        ResultCount     INT UNSIGNED    NOT NULL DEFAULT 0,
        UserId          INT UNSIGNED    NULL,
        SearchedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_SearchedAt (SearchedAt),
        INDEX idx_Query      (Query(191)),
        CONSTRAINT fk_Search_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migUserFeatures_out('  [ERROR] Could not create tblSearchQueries: ' . $mysqli->error);
    } else {
        _migUserFeatures_out('  [add ] tblSearchQueries.');
    }
}
_migUserFeatures_out('');

_migUserFeatures_out('Migration complete.');
$mysqli->close();
