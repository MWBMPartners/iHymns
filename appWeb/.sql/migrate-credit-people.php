<?php

declare(strict_types=1);

/**
 * iHymns — Credit People Registry Migration (#545)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Brings an existing deployment up to the #545 schema by creating the
 * three tables that back the new /manage/credit-people area:
 *
 *   1. tblCreditPeople — registry row per person credited on songs.
 *      Holds the canonical Name plus optional biographical metadata
 *      (Notes, BirthPlace, BirthDate, DeathPlace, DeathDate). Names
 *      already in use across tblSongWriters / tblSongComposers /
 *      tblSongArrangers / tblSongAdaptors / tblSongTranslators continue
 *      to live there as free-text strings — no FK refactor of those
 *      five tables. The registry is additive: drop these three new
 *      tables and the rest of the system still works exactly as it
 *      does today.
 *
 *   2. tblCreditPersonLinks — child of tblCreditPeople. Multiple
 *      external reference links per person (Wikipedia / official site
 *      / MusicBrainz / Discogs / IMSLP / Hymnary / other), with a
 *      sort-order column for admin-controlled display order.
 *      ON DELETE CASCADE so removing a registry row drops its links.
 *
 *   3. tblCreditPersonIPI — child of tblCreditPeople. Multiple IPI
 *      Name Numbers per person (a single individual can be registered
 *      under more than one IPI when they use multiple performing
 *      names). UNIQUE on (CreditPersonId, IPINumber) prevents
 *      accidental duplicates per person while still allowing the same
 *      IPI number to legitimately attach to two different people if
 *      the data demands it. ON DELETE CASCADE matches the links table.
 *
 * Idempotent — re-running is safe. Tables that already exist are
 * skipped with a "skipped" note.
 *
 * The CREATE TABLE statements use literal table names (no PHP
 * variable interpolation), so the schema-audit scanner picks up
 * every column directly from this file. No @migration-adds doctags
 * needed (cf. migrate-credit-fields.php where the loop-built
 * statements DO require them).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-credit-people.php
 *   Web:  /manage/setup-database → "Credit People Registry Migration"
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

function _migPeople_out(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* Load DB credentials — same path as every other migration script. */
$credPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credPath)) {
    _migPeople_out('ERROR: db_credentials.php not found — run install.php first.');
    exit(1);
}
if (!defined('DB_HOST')) {
    require_once $credPath;
}

/* Strict reporting (#525) — exceptions on every failed query so a
   broken CREATE TABLE doesn't silently no-op. Matches the rest of
   the migration scripts. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int)DB_PORT : 3306);
if ($mysqli->connect_errno) {
    _migPeople_out('ERROR: MySQL connect failed: ' . $mysqli->connect_error);
    exit(1);
}
$mysqli->set_charset('utf8mb4');

_migPeople_out('=== iHymns Credit People Registry Migration (#545) ===');
_migPeople_out('Database: ' . DB_NAME . ' @ ' . DB_HOST);
_migPeople_out('');

/* Helper: does a table exist? Mysqli prepared statement against
   INFORMATION_SCHEMA — same shape as migrate-credit-fields.php. */
function _migPeople_tableExists(mysqli $db, string $table): bool {
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

/* Sanity: require tblSongs to exist. The new tables don't FK to
   tblSongs, but their whole reason to exist is to curate names that
   appear in the song-credit junction tables — without tblSongs (and
   therefore without those junction tables) running this migration
   would be premature. The caller should run install.php first, then
   migrate-credit-fields.php (#497), then this one. */
if (!_migPeople_tableExists($mysqli, 'tblSongs')) {
    _migPeople_out('ERROR: tblSongs is missing — run install.php before this migration.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: create tblCreditPeople (parent registry)
 *
 * Must come before the two child tables — they FK to its Id.
 * ---------------------------------------------------------------------- */
if (_migPeople_tableExists($mysqli, 'tblCreditPeople')) {
    _migPeople_out('[skip] tblCreditPeople already present.');
} else {
    $sql = "CREATE TABLE tblCreditPeople (
        Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        Name        VARCHAR(255)    NOT NULL,
        Notes       TEXT            NULL,
        BirthPlace  VARCHAR(255)    NULL,
        BirthDate   DATE            NULL,
        DeathPlace  VARCHAR(255)    NULL,
        DeathDate   DATE            NULL,
        CreatedAt   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_Name (Name),
        INDEX idx_Name (Name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migPeople_out('ERROR: creating tblCreditPeople failed: ' . $mysqli->error);
        exit(1);
    }
    _migPeople_out('[add ] tblCreditPeople.');
}

/* ----------------------------------------------------------------------
 * Step 2: create tblCreditPersonLinks (multi external links per person)
 * ---------------------------------------------------------------------- */
if (_migPeople_tableExists($mysqli, 'tblCreditPersonLinks')) {
    _migPeople_out('[skip] tblCreditPersonLinks already present.');
} else {
    $sql = "CREATE TABLE tblCreditPersonLinks (
        Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        CreditPersonId  INT UNSIGNED    NOT NULL,
        LinkType        VARCHAR(64)     NOT NULL,
        Url             VARCHAR(2048)   NOT NULL,
        Label           VARCHAR(255)    NULL,
        SortOrder       SMALLINT        NOT NULL DEFAULT 0,
        CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_CreditPersonId (CreditPersonId),
        CONSTRAINT fk_CreditPersonLinks_Person
            FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migPeople_out('ERROR: creating tblCreditPersonLinks failed: ' . $mysqli->error);
        exit(1);
    }
    _migPeople_out('[add ] tblCreditPersonLinks.');
}

/* ----------------------------------------------------------------------
 * Step 3: create tblCreditPersonIPI (multi IPI Name Numbers per person)
 * ---------------------------------------------------------------------- */
if (_migPeople_tableExists($mysqli, 'tblCreditPersonIPI')) {
    _migPeople_out('[skip] tblCreditPersonIPI already present.');
} else {
    $sql = "CREATE TABLE tblCreditPersonIPI (
        Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        CreditPersonId  INT UNSIGNED    NOT NULL,
        IPINumber       VARCHAR(32)     NOT NULL,
        NameUsed        VARCHAR(255)    NULL,
        Notes           VARCHAR(255)    NULL,
        CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_PersonIPI (CreditPersonId, IPINumber),
        INDEX idx_IPINumber (IPINumber),
        CONSTRAINT fk_CreditPersonIPI_Person
            FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migPeople_out('ERROR: creating tblCreditPersonIPI failed: ' . $mysqli->error);
        exit(1);
    }
    _migPeople_out('[add ] tblCreditPersonIPI.');
}

_migPeople_out('');
_migPeople_out('Migration complete.');
$mysqli->close();
