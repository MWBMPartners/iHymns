<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Bibliographic & Authority-Identifier Migration (#672)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds 13 nullable metadata columns to tblSongbooks for canonical
 * references to the wider bibliographic record (publisher website,
 * Internet Archive, Wikipedia/WikiData, library catalogue identifiers
 * like OCLC / ISBN / ARK / ISNI / VIAF / LCCN, and the LC
 * Classification call number).
 *
 * The columns are all VARCHAR with no CHECK constraints — curators
 * paste the canonical form from a library catalogue or authority
 * file; light client-side validation hints at malformed URLs but
 * the server accepts whatever lands.
 *
 * Schema additions:
 *   tblSongbooks.WebsiteUrl          VARCHAR(500)
 *   tblSongbooks.InternetArchiveUrl  VARCHAR(500)
 *   tblSongbooks.WikipediaUrl        VARCHAR(500)
 *   tblSongbooks.WikidataId          VARCHAR(20)
 *   tblSongbooks.OclcNumber          VARCHAR(30)
 *   tblSongbooks.OcnNumber           VARCHAR(30)
 *   tblSongbooks.LcpNumber           VARCHAR(30)
 *   tblSongbooks.Isbn                VARCHAR(20)
 *   tblSongbooks.ArkId               VARCHAR(80)
 *   tblSongbooks.IsniId              VARCHAR(25)
 *   tblSongbooks.ViafId              VARCHAR(20)
 *   tblSongbooks.Lccn                VARCHAR(20)
 *   tblSongbooks.LcClass             VARCHAR(50)
 *
 * @migration-adds tblSongbooks.WebsiteUrl
 * @migration-adds tblSongbooks.InternetArchiveUrl
 * @migration-adds tblSongbooks.WikipediaUrl
 * @migration-adds tblSongbooks.WikidataId
 * @migration-adds tblSongbooks.OclcNumber
 * @migration-adds tblSongbooks.OcnNumber
 * @migration-adds tblSongbooks.LcpNumber
 * @migration-adds tblSongbooks.Isbn
 * @migration-adds tblSongbooks.ArkId
 * @migration-adds tblSongbooks.IsniId
 * @migration-adds tblSongbooks.ViafId
 * @migration-adds tblSongbooks.Lccn
 * @migration-adds tblSongbooks.LcClass
 *
 * Idempotent — re-running is safe; columns that already exist are
 * skipped via the INFORMATION_SCHEMA probe.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-songbook-bibliographic.php
 *   Web: /manage/setup-database → "Songbook Bibliographic Metadata"
 *        (entry point requires global_admin)
 */

if (PHP_SAPI === 'cli') {
    /* Guarded require — see #652. */
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

function _migSbBib_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migSbBib_columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migSbBib_tableExists(mysqli $db, string $table): bool
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

_migSbBib_out('Songbook bibliographic metadata migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migSbBib_out('ERROR: could not connect to database.');
    exit(1);
}

if (!_migSbBib_tableExists($mysqli, 'tblSongbooks')) {
    _migSbBib_out('ERROR: tblSongbooks not found. Run install.php first.');
    exit(1);
}

/* The 13 new columns, in the order they should appear AFTER existing
   metadata. The migration runs them through one ALTER TABLE per
   missing column — a single ALTER with all 13 ADD COLUMN clauses
   would be marginally faster but breaks the per-column [skip]/[add]
   reporting the dashboard surfaces, and the table is small enough
   that 13 ALTERs are still milliseconds.

   `after` controls the column ordering in SHOW COLUMNS / phpMyAdmin
   listings — purely cosmetic, since SELECT is column-name based. */
$columns = [
    ['name' => 'WebsiteUrl',         'type' => 'VARCHAR(500)', 'after' => 'Affiliation'],
    ['name' => 'InternetArchiveUrl', 'type' => 'VARCHAR(500)', 'after' => 'WebsiteUrl'],
    ['name' => 'WikipediaUrl',       'type' => 'VARCHAR(500)', 'after' => 'InternetArchiveUrl'],
    ['name' => 'WikidataId',         'type' => 'VARCHAR(20)',  'after' => 'WikipediaUrl'],
    ['name' => 'OclcNumber',         'type' => 'VARCHAR(30)',  'after' => 'WikidataId'],
    ['name' => 'OcnNumber',          'type' => 'VARCHAR(30)',  'after' => 'OclcNumber'],
    ['name' => 'LcpNumber',          'type' => 'VARCHAR(30)',  'after' => 'OcnNumber'],
    ['name' => 'Isbn',               'type' => 'VARCHAR(20)',  'after' => 'LcpNumber'],
    ['name' => 'ArkId',              'type' => 'VARCHAR(80)',  'after' => 'Isbn'],
    ['name' => 'IsniId',             'type' => 'VARCHAR(25)',  'after' => 'ArkId'],
    ['name' => 'ViafId',             'type' => 'VARCHAR(20)',  'after' => 'IsniId'],
    ['name' => 'Lccn',               'type' => 'VARCHAR(20)',  'after' => 'ViafId'],
    ['name' => 'LcClass',            'type' => 'VARCHAR(50)',  'after' => 'Lccn'],
];

$added = 0;
$skipped = 0;
foreach ($columns as $col) {
    $name = $col['name'];
    if (_migSbBib_columnExists($mysqli, 'tblSongbooks', $name)) {
        _migSbBib_out("[skip] tblSongbooks.{$name} already present.");
        $skipped++;
        continue;
    }
    /* AFTER clause is concatenated unquoted because both sides are
       known constants — never user-supplied. The column-existence
       probe ensures we don't try to ADD a column that already exists. */
    $sql = "ALTER TABLE tblSongbooks
            ADD COLUMN {$name} {$col['type']} NULL DEFAULT NULL
            AFTER {$col['after']}";
    if (!$mysqli->query($sql)) {
        _migSbBib_out("ERROR: adding {$name} failed: " . $mysqli->error);
        exit(1);
    }
    _migSbBib_out("[add ] tblSongbooks.{$name}.");
    $added++;
}

_migSbBib_out("Songbook bibliographic metadata migration finished. Added {$added}, skipped {$skipped}.");
