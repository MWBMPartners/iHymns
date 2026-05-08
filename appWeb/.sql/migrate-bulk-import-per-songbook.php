<?php

declare(strict_types=1);

/**
 * iHymns — Bulk-Import Per-Songbook Breakdown Migration (#906)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds a `PerSongbookJson` column to `tblBulkImportJobs` so the bulk-
 * import flow can persist a per-songbook breakdown of created /
 * skipped / failed counts alongside the existing aggregate totals.
 *
 * Pre-#906 the import notification only surfaced "Imported X new
 * (Y skipped)" — the curator couldn't tell whether Y was "songs
 * already in DB" (legitimate skip) or "songs that failed to parse"
 * (real bug). The new column carries enough detail to render a
 * per-songbook table:
 *
 *   [{"abbr":"HA","name":"El Himnario Adventista (1962)",
 *     "created":0,"skipped":527,"failed":0,
 *     "first_errors":[]}, ...]
 *
 * Schema-additive: pre-migration code returns null for this column
 * and the response shape stays back-compat.
 *
 * Idempotent: probes INFORMATION_SCHEMA.COLUMNS first; skips if
 * the column already exists.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-bulk-import-per-songbook.php
 *   Web: /manage/setup-database → "Bulk-Import Per-Songbook Breakdown (#906)"
 *
 * @migration-modifies tblBulkImportJobs (adds PerSongbookJson)
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

function _migBulkPerBook_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migBulkPerBook_columnExists(\mysqli $db, string $table, string $column): bool
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

function _migBulkPerBook_tableExists(\mysqli $db, string $table): bool
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

_migBulkPerBook_out('Bulk-Import Per-Songbook migration starting (#906)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migBulkPerBook_tableExists($mysqli, 'tblBulkImportJobs')) {
    _migBulkPerBook_out('ERROR: tblBulkImportJobs not found. Run "Bulk Import Jobs Tracking (#676)" first.');
    return;
}

if (_migBulkPerBook_columnExists($mysqli, 'tblBulkImportJobs', 'PerSongbookJson')) {
    _migBulkPerBook_out('[skip] tblBulkImportJobs.PerSongbookJson already present.');
} else {
    /* AFTER ErrorsJson keeps the JSON columns adjacent so a
       SHOW CREATE TABLE / DESCRIBE reads in the order the bulk-import
       handler writes them. */
    $sql = "ALTER TABLE tblBulkImportJobs
              ADD COLUMN PerSongbookJson JSON NULL DEFAULT NULL
              AFTER ErrorsJson";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('ALTER TABLE tblBulkImportJobs add PerSongbookJson failed: ' . $mysqli->error);
    }
    _migBulkPerBook_out('[add ] tblBulkImportJobs.PerSongbookJson (JSON NULL).');
}

_migBulkPerBook_out('Bulk-Import Per-Songbook migration finished (#906).');
