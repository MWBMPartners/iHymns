<?php

declare(strict_types=1);

/**
 * iHymns — tblBulkImportJobs.SkippedSongIdsJson (#claude/bulk-import-visibility)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Records every SongId the worker decided to skip (existing row, INSERT-only
 * contract) so the curator can audit specifically WHICH songs were left
 * untouched after a re-import. Pre-this-column, the "X skipped" aggregate
 * count was the only signal — fine for verifying the math, useless for
 * confirming that the expected SongIds were the ones skipped.
 *
 * The bulk-import notification's "Download skipped SongIds" button reads
 * this column and streams the contents as a CSV (SongId, Title, Songbook).
 *
 * SHAPE:
 *   JSON array of strings (SongId values). Size grows linearly with skip
 *   count — a 5,000-row skip is ~50 KB of JSON, comfortably under the
 *   column's TEXT/JSON limit. Larger imports (10k+) still fit.
 *
 * @migration-adds tblBulkImportJobs.SkippedSongIdsJson
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-bulk-import-skipped-songids.php
 *   Web: /manage/setup-database → "Bulk-Import Skipped SongIds"
 *        (entry point requires global_admin)
 */

if (PHP_SAPI === 'cli') {
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

function _migBulkSkipIds_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migBulkSkipIds_columnExists(\mysqli $db, string $table, string $col): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migBulkSkipIds_tableExists(\mysqli $db, string $table): bool
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

_migBulkSkipIds_out('Bulk-Import SkippedSongIdsJson migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migBulkSkipIds_tableExists($mysqli, 'tblBulkImportJobs')) {
    _migBulkSkipIds_out('[skip] tblBulkImportJobs not present — run migrate-bulk-import-jobs.php (#676) first.');
    return;
}

if (_migBulkSkipIds_columnExists($mysqli, 'tblBulkImportJobs', 'SkippedSongIdsJson')) {
    _migBulkSkipIds_out('[skip] SkippedSongIdsJson column already present.');
} else {
    $sql = "ALTER TABLE tblBulkImportJobs
              ADD COLUMN SkippedSongIdsJson JSON NULL
                  COMMENT 'JSON array of SongIds the worker skipped because the row already existed. Powers the import notification\'s \"Download skipped SongIds\" CSV button.'
              AFTER ErrorsJson";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('ALTER tblBulkImportJobs ADD SkippedSongIdsJson failed: ' . $mysqli->error);
    }
    _migBulkSkipIds_out('[add ] tblBulkImportJobs.SkippedSongIdsJson.');
}

_migBulkSkipIds_out('Bulk-Import SkippedSongIdsJson migration finished.');
