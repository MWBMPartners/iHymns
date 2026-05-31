<?php

declare(strict_types=1);

/**
 * iHymns — IETF BCP 47 Language Tagging Migration (#681)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Brings the schema into a state where every Language column on
 * songs, songbooks, translations and song-requests can hold a full
 * IETF BCP 47 tag (language[-script][-region], e.g. zh-Hans-CN,
 * pt-BR, sr-Latn). Adds two reference tables — tblScripts and
 * tblRegions — to back the composite picker's typeahead.
 *
 * Steps (idempotent — every probe / ALTER no-ops if already done):
 *
 *   1. CREATE tblScripts if absent.
 *   2. CREATE tblRegions if absent.
 *   3. Widen tblSongbooks.Language          VARCHAR(10) → VARCHAR(35)
 *   4. Widen tblSongs.Language              VARCHAR(10) → VARCHAR(35)
 *   5. Widen tblSongTranslations.TargetLanguage VARCHAR(10) → VARCHAR(35)
 *   6. Widen tblSongRequests.Language       VARCHAR(10) → VARCHAR(35)
 *   7. Seed tblScripts from the bundled ISO 15924 list.
 *   8. Seed tblRegions from the bundled ISO 3166-1 alpha-2 list.
 *
 * Existing data (`en`, `fr`, `ru`, …) is already a valid BCP 47
 * tag on its own, so no data migration is needed beyond the column
 * widening. The seed data lives in this script (PHP arrays) rather
 * than in schema.sql so a) we don't hand-edit a 250-line VALUES
 * block in the schema and b) re-running the migration extends the
 * reference tables when we add new entries to the array.
 *
 * @migration-adds tblScripts
 * @migration-adds tblRegions
 * @migration-modifies tblSongbooks.Language
 * @migration-modifies tblSongs.Language
 * @migration-modifies tblSongTranslations.TargetLanguage
 * @migration-modifies tblSongRequests.Language
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-ietf-bcp47-language.php
 *   Web: /manage/setup-database → "IETF BCP 47 Language Migration"
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

function _migBcp47_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migBcp47_tableExists(mysqli $db, string $table): bool
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

function _migBcp47_columnWidth(mysqli $db, string $table, string $column): ?int
{
    $stmt = $db->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (int)$row[0] : null;
}

_migBcp47_out('IETF BCP 47 language migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migBcp47_out('ERROR: could not connect to database.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: tblScripts
 * ---------------------------------------------------------------------- */
if (_migBcp47_tableExists($mysqli, 'tblScripts')) {
    _migBcp47_out('[skip] tblScripts already present.');
} else {
    $sql = "CREATE TABLE tblScripts (
        Code        VARCHAR(4)   NOT NULL PRIMARY KEY,
        Name        VARCHAR(100) NOT NULL,
        NativeName  VARCHAR(100) NOT NULL DEFAULT '',
        IsActive    TINYINT(1)   NOT NULL DEFAULT 1,
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        _migBcp47_out('ERROR: creating tblScripts failed: ' . $mysqli->error);
        exit(1);
    }
    _migBcp47_out('[add ] tblScripts.');
}

/* ----------------------------------------------------------------------
 * Step 2: tblRegions
 * ---------------------------------------------------------------------- */
if (_migBcp47_tableExists($mysqli, 'tblRegions')) {
    _migBcp47_out('[skip] tblRegions already present.');
} else {
    $sql = "CREATE TABLE tblRegions (
        Code        VARCHAR(3)   NOT NULL PRIMARY KEY,
        Name        VARCHAR(150) NOT NULL,
        IsActive    TINYINT(1)   NOT NULL DEFAULT 1,
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        _migBcp47_out('ERROR: creating tblRegions failed: ' . $mysqli->error);
        exit(1);
    }
    _migBcp47_out('[add ] tblRegions.');
}

/* ----------------------------------------------------------------------
 * Steps 3-6: widen Language columns to VARCHAR(35)
 *
 * Each ALTER is gated on the current width so re-running the
 * migration is a no-op once the columns are already wide. The
 * MODIFY emits a clear `[skip]` / `[wide]` line per column so the
 * dashboard transcript reads cleanly.
 * ---------------------------------------------------------------------- */
$columnsToWiden = [
    ['table' => 'tblSongbooks',         'column' => 'Language',       'definition' => 'VARCHAR(35) NULL DEFAULT NULL'],
    ['table' => 'tblSongs',             'column' => 'Language',       'definition' => "VARCHAR(35) NOT NULL DEFAULT 'en'"],
    ['table' => 'tblSongTranslations',  'column' => 'TargetLanguage', 'definition' => 'VARCHAR(35) NOT NULL'],
    ['table' => 'tblSongRequests',      'column' => 'Language',       'definition' => "VARCHAR(35) NOT NULL DEFAULT 'en'"],
];
foreach ($columnsToWiden as $col) {
    $width = _migBcp47_columnWidth($mysqli, $col['table'], $col['column']);
    if ($width === null) {
        _migBcp47_out("[skip] {$col['table']}.{$col['column']} not present (parent table missing — run earlier migrations first).");
        continue;
    }
    if ($width >= 35) {
        _migBcp47_out("[skip] {$col['table']}.{$col['column']} already VARCHAR({$width}).");
        continue;
    }
    $sql = "ALTER TABLE {$col['table']} MODIFY COLUMN {$col['column']} {$col['definition']}";
    if (!$mysqli->query($sql)) {
        _migBcp47_out("ERROR: widening {$col['table']}.{$col['column']} failed: " . $mysqli->error);
        exit(1);
    }
    _migBcp47_out("[wide] {$col['table']}.{$col['column']} → VARCHAR(35).");
}

/* ----------------------------------------------------------------------
 * Step 7: seed tblScripts
 *
 * Sourced from the bundled SCRIPTS array below. INSERT IGNORE means
 * re-running adds new entries appended to the array without
 * disturbing rows an admin manually flagged IsActive=0. Chunked into
 * ~30-row INSERTs for readable transcript output (one log line per
 * chunk) and to keep individual prepared-statement payloads small.
 * ---------------------------------------------------------------------- */
require __DIR__ . '/migrate-ietf-bcp47-data.php';   // SCRIPTS + REGIONS

$scriptsAdded = 0;
foreach (array_chunk(SCRIPTS, 30) as $chunk) {
    $placeholders = [];
    $types        = '';
    $values       = [];
    foreach ($chunk as $row) {
        $placeholders[] = '(?, ?, ?)';
        $types         .= 'sss';
        $values[]       = $row['code'];
        $values[]       = $row['name'];
        $values[]       = $row['native'] ?? '';
    }
    $sql = 'INSERT IGNORE INTO tblScripts (Code, Name, NativeName) VALUES '
         . implode(', ', $placeholders);
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $scriptsAdded += $stmt->affected_rows;
    $stmt->close();
}
_migBcp47_out("[seed] tblScripts: {$scriptsAdded} new row(s) (re-runs are silent no-ops).");

/* ----------------------------------------------------------------------
 * Step 8: seed tblRegions
 * ---------------------------------------------------------------------- */
$regionsAdded = 0;
foreach (array_chunk(REGIONS, 50) as $chunk) {
    $placeholders = [];
    $types        = '';
    $values       = [];
    foreach ($chunk as $row) {
        $placeholders[] = '(?, ?)';
        $types         .= 'ss';
        $values[]       = $row['code'];
        $values[]       = $row['name'];
    }
    $sql = 'INSERT IGNORE INTO tblRegions (Code, Name) VALUES '
         . implode(', ', $placeholders);
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $regionsAdded += $stmt->affected_rows;
    $stmt->close();
}
_migBcp47_out("[seed] tblRegions: {$regionsAdded} new row(s) (re-runs are silent no-ops).");

_migBcp47_out('IETF BCP 47 language migration finished.');
