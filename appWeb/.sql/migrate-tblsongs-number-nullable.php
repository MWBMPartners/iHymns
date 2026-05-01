<?php

declare(strict_types=1);

/**
 * iHymns — Make tblSongs.Number nullable (#783)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongs.Number was originally `INT UNSIGNED NOT NULL DEFAULT 0`
 * because every songbook at the time (CP / JP / MP / SDAH / CH) had
 * numbered rows. With Misc + every curated grouping that's flagged
 * IsOfficial=0 (#392) + the multi-language work in flight (#778),
 * songs in unofficial songbooks legitimately have NO per-songbook
 * number — the internal SongId is the cross-record link instead.
 *
 * The save_song handler in /manage/editor/api.php has bound NULL on
 * unofficial-songbook saves since PR #740, but the schema on existing
 * deployments still says NOT NULL — so every Misc save 1048s with
 * "Column 'Number' cannot be null". This migration aligns the
 * schema with the post-#392 policy.
 *
 * Idempotent — INFORMATION_SCHEMA probe; skip if IS_NULLABLE='YES'.
 * Existing rows with integer Numbers stay valid; the only behaviour
 * change is that NULL becomes a permissible value.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-tblsongs-number-nullable.php
 *   Web: /manage/setup-database → "Make tblSongs.Number nullable"
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

function _migNumNull_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

_migNumNull_out('tblSongs.Number nullable migration starting…');

$db = getDbMysqli();
if (!$db) {
    _migNumNull_out('ERROR: could not connect to database.');
    exit(1);
}

/* Probe current state of the column. */
$stmt = $db->prepare(
    "SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblSongs'
        AND COLUMN_NAME  = 'Number'
      LIMIT 1"
);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    _migNumNull_out('ERROR: tblSongs.Number not found. Run install.php first.');
    exit(1);
}

if (($row['IS_NULLABLE'] ?? '') === 'YES') {
    _migNumNull_out('[skip] tblSongs.Number is already nullable. Nothing to do.');
    _migNumNull_out('tblSongs.Number nullable migration finished.');
    exit(0);
}

_migNumNull_out(sprintf(
    'Current shape: %s, IS_NULLABLE=%s, DEFAULT=%s.',
    $row['COLUMN_TYPE'] ?? '?',
    $row['IS_NULLABLE'] ?? '?',
    $row['COLUMN_DEFAULT'] === null ? 'NULL' : ("'" . $row['COLUMN_DEFAULT'] . "'")
));

/* Apply the ALTER. We re-state the column type to match what's there
   today (INT UNSIGNED) rather than picking a new type — the only
   intent of this migration is to flip the nullability. The default
   moves from 0 to NULL so a row created without an explicit Number
   lands as NULL rather than 0 (which would otherwise look like a
   "real" hymn #0 in any UI that doesn't special-case it). */
$sql = 'ALTER TABLE tblSongs MODIFY COLUMN Number INT UNSIGNED NULL DEFAULT NULL';
if (!$db->query($sql)) {
    _migNumNull_out('ERROR: ALTER failed: ' . $db->error);
    exit(1);
}
_migNumNull_out('[ok  ] tblSongs.Number → NULL DEFAULT NULL.');

/* Verify the change took effect — defence against a silent failure
   on a pre-MySQL-5.6 server or one with a strange SQL_MODE. */
$stmt = $db->prepare(
    "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblSongs'
        AND COLUMN_NAME  = 'Number'
      LIMIT 1"
);
$stmt->execute();
$after = (string)($stmt->get_result()->fetch_row()[0] ?? '');
$stmt->close();
if ($after !== 'YES') {
    _migNumNull_out("WARN: post-ALTER probe shows IS_NULLABLE='{$after}'. The migration ran but the change may not have applied. Check server SQL_MODE.");
} else {
    _migNumNull_out('[verify] confirmed nullable.');
}

_migNumNull_out('tblSongs.Number nullable migration finished.');
