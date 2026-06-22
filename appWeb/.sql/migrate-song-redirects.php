<?php

declare(strict_types=1);

/**
 * iHymns — Song permalink redirects (#1343)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Keeps a shared permalink (https://ihymns.app/song/<SongId>) alive after the
 * song is merged, deleted or renamed — instead of a dead 404 (the "Here To Stay"
 * problem). A row maps an OLD SongId to either a replacement (NewSongId, a 301
 * target) or NULL (a tombstone — "removed", a friendly page, not a 404).
 *
 *   - OldSongId is the PK and is NOT a foreign key: the old song row no longer
 *     exists, so it can't reference tblSongs.
 *   - NewSongId IS an FK → tblSongs(SongId) ON DELETE SET NULL: if the target is
 *     itself later deleted, the redirect degrades to a tombstone rather than
 *     dangling (and the merge/delete paths also repoint chains forward).
 *
 * Additive + IDEMPOTENT (table-existence guarded). All readers are
 * INFORMATION_SCHEMA-gated, so the app works whether or not this has run (the
 * #1228 white-screen lesson).
 *
 * @migration-adds tblSongRedirects
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-redirects.php
 *   Web:  /manage/setup-database → "Song permalink redirects" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSR_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSR_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSR_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSR_output("");
_migSR_output("=== iHymns — Song permalink redirects (#1343) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSR_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSR_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migSR_tableExists($mysql, 'tblSongRedirects')) {
        _migSR_output("  [SKIP] tblSongRedirects already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongRedirects (
                OldSongId  VARCHAR(20)  NOT NULL
                           COMMENT 'The dead/merged/renamed SongId whose permalink must keep resolving (#1343). PK; NOT an FK — the old song row is gone.',
                NewSongId  VARCHAR(20)  NULL DEFAULT NULL
                           COMMENT 'Resolve target (FK->tblSongs); NULL = tombstone (removed, no replacement).',
                Reason     VARCHAR(20)  NOT NULL DEFAULT 'merge'
                           COMMENT 'merge | delete | rename — VARCHAR not ENUM (rule #20).',
                Note       VARCHAR(255) NOT NULL DEFAULT '',
                CreatedBy  INT UNSIGNED NULL DEFAULT NULL
                           COMMENT 'tblUsers.Id of the curator who created the redirect, if signed in',
                CreatedAt  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (OldSongId),
                KEY idx_NewSongId (NewSongId),
                CONSTRAINT fk_SongRedirect_New
                    FOREIGN KEY (NewSongId) REFERENCES tblSongs(SongId)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migSR_output("  [OK] Created tblSongRedirects.");
    }
    _migSR_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSR_output("ERROR: " . $e->getMessage());
}
