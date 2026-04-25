<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Metadata Migration (#502)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Brings an existing deployment's tblSongbooks up to the #502 schema:
 *   1. Adds five nullable metadata columns:
 *        - IsOfficial      (TINYINT(1) NOT NULL DEFAULT 0)
 *        - Publisher       (VARCHAR(255))
 *        - PublicationYear (VARCHAR(50))
 *        - Copyright       (VARCHAR(500))
 *        - Affiliation     (VARCHAR(120))
 *   2. Marks every existing seed songbook whose Abbreviation is NOT
 *      "Misc" as IsOfficial = 1, matching the real-world state
 *      (the project shipped with five published hymnals + one
 *      unstructured Misc collection). Admins can untick any row
 *      via the edit modal on /manage/songbooks afterwards.
 *
 * PREREQUISITES:
 * Earlier tblSongbooks columns (DisplayOrder, Colour) live in
 * `migrate-account-sync.php` Step 1b — that's the canonical owner.
 * Run "Account Sync Migration" first if your DB pre-dates the admin
 * surface refactor; otherwise the AFTER-clause helper below silently
 * appends the #502 columns at end of the table rather than at their
 * canonical position, which is cosmetic-only but worth knowing.
 *
 * Idempotent — re-running is safe. Columns that already exist are
 * skipped with a "skipped" note; the IsOfficial backfill step
 * `WHERE IsOfficial = 0 AND Abbreviation <> 'Misc'` self-gates so
 * admins who've deliberately unticked rows don't get overridden.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-songbook-meta.php
 *   Web: /manage/setup-database → "Songbook Metadata Migration"
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

function _migSongbookMeta_out(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* Credentials — same path as every other migration script. */
$credPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credPath)) {
    _migSongbookMeta_out('ERROR: db_credentials.php not found — run install.php first.');
    exit(1);
}
if (!defined('DB_HOST')) {
    require_once $credPath;
}

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int)DB_PORT : 3306);
if ($mysqli->connect_errno) {
    _migSongbookMeta_out('ERROR: MySQL connect failed: ' . $mysqli->connect_error);
    exit(1);
}
$mysqli->set_charset('utf8mb4');

_migSongbookMeta_out('=== iHymns Songbook Metadata Migration (#502) ===');
_migSongbookMeta_out('Database: ' . DB_NAME . ' @ ' . DB_HOST);
_migSongbookMeta_out('');

function _migSongbookMeta_colExists(mysqli $db, string $table, string $col): bool {
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

function _migSongbookMeta_tableExists(mysqli $db, string $table): bool {
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

if (!_migSongbookMeta_tableExists($mysqli, 'tblSongbooks')) {
    _migSongbookMeta_out('ERROR: tblSongbooks is missing — run install.php before this migration.');
    exit(1);
}

/* Each step: column name → ALTER statement. Keeping them in a list
   means adding a new column in the future is a one-line edit.

   The `AFTER X` positions match schema.sql for new installs, but the
   loop below strips any `AFTER X` clause whose anchor column doesn't
   actually exist on the target DB — so a deployment that hasn't yet
   run the prerequisite Account Sync Migration (which owns Colour /
   DisplayOrder) still gets the #502 columns added at end of the table
   rather than failing. Column position is cosmetic; what matters is
   the column existing for SongData's SELECT to find. The user is
   nudged to run Account Sync Migration first; this is just a safety
   net for the order-dependence. */
$steps = [
    /* Colour was added to schema.sql in the "Admin surface phases 1-4"
       change. Its forward-migration lives in migrate-account-sync.php
       Step 1b — that's the canonical owner. The catch-up here is left
       in place from #508 as a defence-in-depth backstop; the helper
       below makes the AFTER clause safe even if Colour doesn't yet
       exist on the target DB. */
    ['col' => 'Colour', 'sql' => "ALTER TABLE tblSongbooks
        ADD COLUMN Colour VARCHAR(7) NOT NULL DEFAULT ''
        COMMENT 'Badge colour hex #RRGGBB (empty = theme default)'
        AFTER DisplayOrder"],
    ['col' => 'IsOfficial', 'sql' => "ALTER TABLE tblSongbooks
        ADD COLUMN IsOfficial TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = published hymnal; 0 = curated grouping / pseudo-songbook (#502)'
        AFTER Colour"],
    ['col' => 'Publisher', 'sql' => "ALTER TABLE tblSongbooks
        ADD COLUMN Publisher VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Publisher or originator (e.g. Praise Trust, Hope Publishing) (#502)'
        AFTER IsOfficial"],
    ['col' => 'PublicationYear', 'sql' => "ALTER TABLE tblSongbooks
        ADD COLUMN PublicationYear VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Year / edition range — free-form, e.g. 1986, 1986-2003, 2nd edition 2011 (#502)'
        AFTER Publisher"],
    ['col' => 'Copyright', 'sql' => "ALTER TABLE tblSongbooks
        ADD COLUMN Copyright VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Copyright notice for the collection as a whole (#502)'
        AFTER PublicationYear"],
    ['col' => 'Affiliation', 'sql' => "ALTER TABLE tblSongbooks
        ADD COLUMN Affiliation VARCHAR(120) NULL DEFAULT NULL
        COMMENT 'Denominational / religious affiliation; free-text for now, lookup table later (#502)'
        AFTER Copyright"],
];

/**
 * Strip an `AFTER X` positional clause from an ALTER … ADD COLUMN
 * statement when the anchor column doesn't yet exist on the target
 * database. Lets old schemas missing intermediate columns still
 * migrate one column at a time.
 */
function _migSongbookMeta_resolveAfterClause(mysqli $db, string $tbl, string $sql): string
{
    if (preg_match('/\sAFTER\s+([A-Za-z_][A-Za-z0-9_]*)\b/i', $sql, $m)) {
        if (!_migSongbookMeta_colExists($db, $tbl, $m[1])) {
            return preg_replace('/\sAFTER\s+[A-Za-z_][A-Za-z0-9_]*\b/i', '', $sql);
        }
    }
    return $sql;
}

foreach ($steps as $s) {
    if (_migSongbookMeta_colExists($mysqli, 'tblSongbooks', $s['col'])) {
        _migSongbookMeta_out("[skip] tblSongbooks.{$s['col']} already present.");
        continue;
    }
    $sql = _migSongbookMeta_resolveAfterClause($mysqli, 'tblSongbooks', $s['sql']);
    if (!$mysqli->query($sql)) {
        _migSongbookMeta_out("ERROR: adding {$s['col']} failed: " . $mysqli->error);
        exit(1);
    }
    _migSongbookMeta_out("[add ] tblSongbooks.{$s['col']}.");
}

/* Backfill step: mark every existing non-Misc songbook as official.
   Guarded by IsOfficial = 0 so admins who deliberately unticked a
   row later won't have their edit reverted by a re-run. */
$res = $mysqli->query(
    "UPDATE tblSongbooks
        SET IsOfficial = 1
      WHERE IsOfficial = 0 AND Abbreviation <> 'Misc'"
);
if ($res) {
    _migSongbookMeta_out('[mark] ' . $mysqli->affected_rows
        . ' existing songbook(s) flagged as IsOfficial (non-Misc rows only).');
} else {
    _migSongbookMeta_out('WARN: backfill IsOfficial failed: ' . $mysqli->error);
}

_migSongbookMeta_out('');
_migSongbookMeta_out('Migration complete.');
$mysqli->close();
