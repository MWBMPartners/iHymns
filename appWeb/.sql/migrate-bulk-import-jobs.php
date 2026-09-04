<?php

declare(strict_types=1);

/**
 * iHymns — Bulk Import Jobs Table Migration (#676)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates tblBulkImportJobs so the bulk_import_zip endpoint can
 * record long-running import jobs and the browser can poll for
 * progress (live percentage + summary) instead of sitting on a
 * blocked HTTP request for several minutes.
 *
 * NOT DESTRUCTIVE. A single guarded CREATE TABLE; nothing existing is
 * dropped, altered or read. Rolling back is DROP TABLE, and the only
 * loss is the progress/summary record of past imports — the songs the
 * imports created live in tblSongs and are unaffected.
 *
 * Idempotent — re-running is safe; the INFORMATION_SCHEMA probe
 * skips the CREATE if the table is already present.
 *
 * OPERATOR VIEW. Card "Bulk Import Jobs Tracking (#676)" on
 * /manage/setup-database; the registry probe is
 * !tableExists('tblBulkImportJobs'), which matches this migration's one
 * object exactly.
 *
 * ⚠ THIS FILE IS THE ORIGINAL #676 SHAPE AND MUST STAY THAT WAY.
 * appWeb/.sql/schema.sql — which is what a FRESH install reads — declares
 * tblBulkImportJobs with three columns that are deliberately absent here:
 * SkippedSongIdsJson, PerSongbookJson (#906) and PhaseLabel (#907). Each
 * arrived later and has its OWN migration
 * (migrate-bulk-import-skipped-songids.php, -per-songbook.php,
 * -phase-label.php) with its own dashboard card and its own probe. Adding
 * them to the CREATE above would make those three cards report "already
 * applied" on an install that has only ever run this one, so the columns'
 * real migrations would be skipped and any future change to them would
 * land on the wrong install. Long-running installs converge on the
 * schema.sql shape by running all four cards, not by widening this one.
 *
 * Note on the doctag: the schema-coverage scanner
 * (includes/schema_audit.php, "Signal 3") requires the tag to name a
 * table AND a column, dot-separated. The tag below names a table only,
 * so it matches nothing and is inert documentation. Coverage for this
 * table is supplied by the scanner's "Signal 2", which parses the
 * literal CREATE TABLE block. Rule #19's byte-identity requirement still
 * applies to every column declaration this file DOES carry.
 *
 * @migration-adds tblBulkImportJobs
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-bulk-import-jobs.php
 *   Web: /manage/setup-database → "Bulk Import Jobs Migration"
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

function _migBulkJobs_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migBulkJobs_tableExists(mysqli $db, string $table): bool
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

_migBulkJobs_out('Bulk import jobs migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migBulkJobs_out('ERROR: could not connect to database.');
    exit(1);
}

if (_migBulkJobs_tableExists($mysqli, 'tblBulkImportJobs')) {
    _migBulkJobs_out('[skip] tblBulkImportJobs already present.');
} else {
    /* Shape notes for a reader who hasn't seen the async import flow:

       This table exists because the ZIP import outlives an HTTP request.
       bulk_import_zip moves the upload aside, writes its path to
       TempPath, INSERTs a 'queued' row, returns {job_id} to the browser,
       calls fastcgi_finish_request() to release the connection, and then
       keeps working in the freed PHP worker — bumping ProcessedEntries
       as it goes. The row IS the progress channel; the browser polls
       bulk_import_status, which reads it. That is why the counters are
       columns and not, say, a summary written once at the end: a value
       nobody can read until the job finishes would defeat the purpose.

       UserId is NULLable and carries no foreign key. NULL covers a
       global_admin running the import from the CLI, where there is no
       session user; the absent FK means a deleted user leaves their old
       job rows behind rather than cascading them away, which is the
       right trade for what is an operational audit record.

       Status is an ENUM, which predates rule #20 (VARCHAR + an app-side
       allow-list for any vocabulary that might grow) and is
       grandfathered. The cost is real: adding 'cancelled' or 'paused'
       would require an ALTER TABLE — the second migration rule #20
       exists to prevent. Do not copy this into a new table.

       The two indexes serve the two access patterns and nothing else:
       idx_user_status for the polling endpoint (always scoped to one
       user and a set of live statuses), idx_status_updated for the
       ops-side "what has been running for over an hour" sweep. */
    $sql = "CREATE TABLE tblBulkImportJobs (
        Id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        UserId                   INT UNSIGNED NULL COMMENT 'editor who started the import; NULL if global_admin used a CLI invocation',
        Filename                 VARCHAR(255) NOT NULL COMMENT 'Original upload filename (display only)',
        TempPath                 VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Server-side path to the moved temp file; cleared on completion',
        SizeBytes                BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Original upload size in bytes (display only)',
        Status                   ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
        TotalEntries             INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Real .txt entries the worker has classified for processing',
        ProcessedEntries         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Counter the worker bumps every ~50 rows so the polling endpoint can render a percentage',
        SongbooksCreatedJson     JSON NULL COMMENT 'Result summary — list of abbrevs created in this run',
        SongbooksExistingJson    JSON NULL COMMENT 'Result summary — list of abbrevs that already existed',
        SongsCreated             INT UNSIGNED NOT NULL DEFAULT 0,
        SongsSkippedExisting     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'INSERT-only contract: existing SongIds are left untouched',
        SongsFailed              INT UNSIGNED NOT NULL DEFAULT 0,
        ErrorsJson               JSON NULL COMMENT 'Per-entry [{entry, error}, …] from the parser / save path',
        StartedAt                TIMESTAMP NULL DEFAULT NULL COMMENT 'When the worker began processing (post-fastcgi_finish_request)',
        CompletedAt              TIMESTAMP NULL DEFAULT NULL,
        CreatedAt                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_status (UserId, Status),
        INDEX idx_status_updated (Status, UpdatedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        _migBulkJobs_out('ERROR: creating tblBulkImportJobs failed: ' . $mysqli->error);
        exit(1);
    }
    _migBulkJobs_out('[add ] tblBulkImportJobs.');
}

_migBulkJobs_out('Bulk import jobs migration finished.');
