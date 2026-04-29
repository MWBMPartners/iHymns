<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Affiliations Lookup Table Migration (#670)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Closes the "Affiliation lookup table" out-of-scope item from #502.
 * Previously the Affiliation column on tblSongbooks was free-text, which
 * is a duplicate magnet — small typing variations
 * ("Seventh-day Adventist Church" vs "Seventh-Day Adventist Church"
 * vs "SDA Church") fragment any future filter / analytic.
 *
 * Adds tblSongbookAffiliations (Id PK, Name UNIQUE) as a parallel
 * registry. tblSongbooks.Affiliation stays a denormalised VARCHAR (no
 * FK) so existing free-text values that don't yet match the registry
 * are not lost on this migration; the songbook editor's save handler
 * is being updated alongside this script to INSERT IGNORE every
 * non-empty value into the registry, making it self-populate from
 * real use exactly like tblCreditPeople does for credit names (#545).
 *
 * Steps:
 *   1. Probe INFORMATION_SCHEMA for tblSongbookAffiliations; CREATE
 *      if absent.
 *   2. Backfill the registry from every distinct non-empty Affiliation
 *      already in tblSongbooks so the typeahead surfaces existing
 *      values from day one.
 *
 * Idempotent — re-running is safe. The CREATE step skips if the
 * table is already present; the backfill INSERTs use IGNORE so an
 * existing registry row with the same Name is a silent no-op.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-songbook-affiliations.php
 *   Web: /manage/setup-database → "Songbook Affiliations Migration"
 *        (entry point requires global_admin)
 *
 * @migration-adds tblSongbookAffiliations
 * @requires PHP 8.1+ with mysqli extension
 */

if (PHP_SAPI === 'cli') {
    /* CLI bootstrap mirrors the other migrate-*.php files. */
    /* Guarded require — see #652. The dashboard has already loaded
       db_mysql.php via auth.php's bootstrap, so the function already
       exists at this point in dashboard mode; the guard skips the
       re-open that some hosts block from outside public_html/. */
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

function _migAffil_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661):
       the dashboard wraps this require in ob_start() so it can render
       captured output inside its dedicated panel. Calling ob_flush()
       here would punt the buffer past that wrapper. */
    if ($isCli) {
        flush();
    }
}

function _migAffil_tableExists(mysqli $db, string $table): bool
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

_migAffil_out('Songbook Affiliations migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migAffil_out('ERROR: could not connect to database.');
    exit(1);
}

/* The base songbooks table must already exist — this migration backfills
   from it. If a deployment somehow hits this migration before the core
   schema is in place, bail with a clear pointer rather than failing
   later in the SELECT. */
if (!_migAffil_tableExists($mysqli, 'tblSongbooks')) {
    _migAffil_out('ERROR: tblSongbooks not found. Run install.php first.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: create tblSongbookAffiliations if absent
 * ---------------------------------------------------------------------- */
if (_migAffil_tableExists($mysqli, 'tblSongbookAffiliations')) {
    _migAffil_out('[skip] tblSongbookAffiliations already present.');
} else {
    $sql = "CREATE TABLE tblSongbookAffiliations (
        Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        Name        VARCHAR(120)    NOT NULL,
        CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_affiliation_name (Name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        _migAffil_out('ERROR: creating tblSongbookAffiliations failed: ' . $mysqli->error);
        exit(1);
    }
    _migAffil_out('[add ] tblSongbookAffiliations.');
}

/* ----------------------------------------------------------------------
 * Step 2: backfill from existing tblSongbooks.Affiliation values
 *
 * One INSERT…SELECT, with IGNORE so re-runs (or a registry that already
 * has some rows from the live save path) don't 1062-error on duplicate
 * Name. SELECT DISTINCT collapses repeated values across multiple
 * songbooks. The TRIM/LENGTH > 0 guards keep blank / whitespace-only
 * rows out of the registry — the column is nullable but has historically
 * also been written as '' on some code paths.
 * ---------------------------------------------------------------------- */
$sql = "INSERT IGNORE INTO tblSongbookAffiliations (Name)
        SELECT DISTINCT TRIM(Affiliation)
          FROM tblSongbooks
         WHERE Affiliation IS NOT NULL
           AND LENGTH(TRIM(Affiliation)) > 0";
if (!$mysqli->query($sql)) {
    _migAffil_out('WARN: backfill failed: ' . $mysqli->error);
} else {
    $seeded = $mysqli->affected_rows;
    if ($seeded > 0) {
        _migAffil_out("[seed] backfilled {$seeded} affiliation row" . ($seeded === 1 ? '' : 's') . ' from tblSongbooks.');
    } else {
        _migAffil_out('[seed] no affiliation values to backfill (registry already covers all songbooks).');
    }
}

_migAffil_out('Songbook Affiliations migration finished.');
