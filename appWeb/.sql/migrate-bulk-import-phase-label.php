<?php

declare(strict_types=1);

/**
 * iHymns — Bulk-Import Phase Label Migration (#907)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds a `PhaseLabel` column to `tblBulkImportJobs` so the bulk-
 * import worker can record its current phase ("walking-zip",
 * "parsing-songs", "flushing-songbooks", etc.) and the polling
 * frontend can surface a human-readable status above the progress
 * bar even at 0% progress.
 *
 * Pre-#907 the curator saw a blank "0%" indicator for the first
 * several seconds while the worker was reading the upload, walking
 * the archive index, and probing the schema — none of which advance
 * the ProcessedEntries counter. The new column lets the frontend
 * render "Walking ZIP archive…" or "Parsing songs…" so the user
 * understands progress is happening even when the percentage isn't
 * moving.
 *
 * Schema-additive: pre-migration code returns null for this column
 * and the response shape stays back-compat.
 *
 * Idempotent: probes INFORMATION_SCHEMA.COLUMNS first; skips if
 * the column already exists.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-bulk-import-phase-label.php
 *   Web: /manage/setup-database → "Bulk-Import Phase Label (#907)"
 *
 * @migration-modifies tblBulkImportJobs (adds PhaseLabel)
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

function _migBulkPhase_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migBulkPhase_columnExists(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migBulkPhase_tableExists(\mysqli $db, string $table): bool
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

_migBulkPhase_out('Bulk-Import Phase Label migration starting (#907)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migBulkPhase_tableExists($mysqli, 'tblBulkImportJobs')) {
    _migBulkPhase_out('ERROR: tblBulkImportJobs not found. Run "Bulk Import Jobs Tracking (#676)" first.');
    return;
}

if (_migBulkPhase_columnExists($mysqli, 'tblBulkImportJobs', 'PhaseLabel')) {
    _migBulkPhase_out('[skip] tblBulkImportJobs.PhaseLabel already present.');
} else {
    /* AFTER ProcessedEntries keeps the phase column adjacent to the
       progress columns it complements. */
    $sql = "ALTER TABLE tblBulkImportJobs
              ADD COLUMN PhaseLabel VARCHAR(64) NULL DEFAULT NULL
              AFTER ProcessedEntries";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('ALTER TABLE tblBulkImportJobs add PhaseLabel failed: ' . $mysqli->error);
    }
    _migBulkPhase_out('[add ] tblBulkImportJobs.PhaseLabel (VARCHAR(64) NULL).');
}

_migBulkPhase_out('Bulk-Import Phase Label migration finished (#907).');
