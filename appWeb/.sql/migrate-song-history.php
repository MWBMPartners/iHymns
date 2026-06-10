<?php

declare(strict_types=1);

/**
 * iHymns — Create tblSongHistory on existing installs (#1236)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * `tblSongHistory` (recently-viewed / most-popular) has long been declared in
 * schema.sql, so FRESH installs get it — but **no migration ever created it for
 * long-running installs** (alpha / beta / production predate the table). The
 * result: the `song_history` API handler hits "table doesn't exist", returns
 * `{ fallback: true }`, and the client silently falls back to per-device
 * `localStorage` — so a signed-in user's Recently-Viewed list differs across
 * devices. The client + API (#546 / #549 / WS-G #1019) are already built; only
 * the table was missing on existing installs.
 *
 * This idempotent migration creates the table (DDL byte-identical to schema.sql)
 * so the server-side, cross-device Recently-Viewed sync works.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (CREATE TABLE IF NOT EXISTS).
 *
 * @migration-adds tblSongHistory
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Recently-Viewed history table"
 *   CLI:  php appWeb/.sql/migrate-song-history.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migSongHistory_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migSongHistory_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migSongHistory_out('=== iHymns — tblSongHistory (recently-viewed) for existing installs (#1236) ===');

try {
    $has = $db->query("SHOW TABLES LIKE 'tblSongHistory'");
    if ($has && $has->num_rows > 0) {
        _migSongHistory_out('  [SKIP] tblSongHistory already present.');
    } else {
        /* DDL byte-identical to appWeb/.sql/schema.sql (rule #19) so a migrated
           install equals a fresh one. */
        $db->query(
            "CREATE TABLE IF NOT EXISTS tblSongHistory (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NULL COMMENT 'NULL for anonymous views',
    SongId          VARCHAR(20)     NOT NULL,
    ViewedAt        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_User      (UserId),
    INDEX idx_Song      (SongId),
    INDEX idx_ViewedAt  (ViewedAt),

    CONSTRAINT fk_History_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_History_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migSongHistory_out('  [OK] Created tblSongHistory.');
    }
    _migSongHistory_out('');
    _migSongHistory_out('Migration complete — signed-in Recently-Viewed now syncs server-side across devices.');
} catch (\Throwable $e) {
    _migSongHistory_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
