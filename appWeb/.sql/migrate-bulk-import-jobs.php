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
 * @migration-adds tblBulkImportJobs
 *
 * Idempotent — re-running is safe; the INFORMATION_SCHEMA probe
 * skips the CREATE if the table is already present.
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
    $sql = "CREATE TABLE tblBulkImportJobs (
        Id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        UserId                   INT UNSIGNED NULL,
        Filename                 VARCHAR(255) NOT NULL,
        TempPath                 VARCHAR(500) NOT NULL DEFAULT '',
        SizeBytes                BIGINT UNSIGNED NOT NULL DEFAULT 0,
        Status                   ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
        TotalEntries             INT UNSIGNED NOT NULL DEFAULT 0,
        ProcessedEntries         INT UNSIGNED NOT NULL DEFAULT 0,
        SongbooksCreatedJson     JSON NULL,
        SongbooksExistingJson    JSON NULL,
        SongsCreated             INT UNSIGNED NOT NULL DEFAULT 0,
        SongsSkippedExisting     INT UNSIGNED NOT NULL DEFAULT 0,
        SongsFailed              INT UNSIGNED NOT NULL DEFAULT 0,
        ErrorsJson               JSON NULL,
        StartedAt                TIMESTAMP NULL DEFAULT NULL,
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
