<?php

declare(strict_types=1);

/**
 * iHymns — Places adoption sweep
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Second wave of the Places registry — extends the FK pattern
 * established by migrate-places.php to four more entities so the
 * normalised place catalogue spreads beyond Credit People:
 *
 *   tblSongbooks    PublicationCity   VARCHAR(255) NULL
 *                   PublicationCityId INT UNSIGNED NULL  → tblPlaces.Id
 *   tblOrganisations PhysicalCity     VARCHAR(255) NULL
 *                    PhysicalCityId   INT UNSIGNED NULL  → tblPlaces.Id
 *   tblSongs        OriginCity        VARCHAR(255) NULL
 *                   OriginCityId      INT UNSIGNED NULL  → tblPlaces.Id
 *   tblWorks        OriginCity        VARCHAR(255) NULL
 *                   OriginCityId      INT UNSIGNED NULL  → tblPlaces.Id
 *
 * Same pattern in every case: a VARCHAR display-string mirror
 * (cheap to read, no JOIN required) alongside a nullable FK that
 * a future report can JOIN on for country / region filtering.
 *
 * @migration-adds tblSongbooks.PublicationCity
 * @migration-adds tblSongbooks.PublicationCityId
 * @migration-adds tblOrganisations.PhysicalCity
 * @migration-adds tblOrganisations.PhysicalCityId
 * @migration-adds tblSongs.OriginCity
 * @migration-adds tblSongs.OriginCityId
 * @migration-adds tblWorks.OriginCity
 * @migration-adds tblWorks.OriginCityId
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-places-adoption.php
 *
 * Idempotent — re-running is safe; per-column existence is
 * probed before each ALTER.
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

function _migPlacesAdopt_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migPlacesAdopt_tableExists(mysqli $db, string $table): bool
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

function _migPlacesAdopt_columnExists(mysqli $db, string $table, string $column): bool
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

/**
 * Add one VARCHAR mirror + one FK column to a table, in a single
 * ALTER per pair so the row gets rewritten once instead of twice
 * on large tables. Skips either column individually if it already
 * exists (e.g. a re-run after a partial failure).
 */
function _migPlacesAdopt_addPlacePair(
    mysqli $db,
    string $table,
    string $varcharCol,
    string $fkCol,
    string $fkConstraintName,
    string $afterCol
): void {
    $hasVarchar = _migPlacesAdopt_columnExists($db, $table, $varcharCol);
    $hasFk      = _migPlacesAdopt_columnExists($db, $table, $fkCol);

    if ($hasVarchar && $hasFk) {
        _migPlacesAdopt_out("[skip] {$table}.{$varcharCol} + .{$fkCol} already present.");
        return;
    }

    $parts = [];
    if (!$hasVarchar) {
        $parts[] = "ADD COLUMN {$varcharCol} VARCHAR(255) NULL AFTER {$afterCol}";
    }
    if (!$hasFk) {
        /* The FK column is placed AFTER the VARCHAR (which either
           already exists or was just added by this same statement). */
        $parts[] = "ADD COLUMN {$fkCol} INT UNSIGNED NULL AFTER {$varcharCol}";
        $parts[] = "ADD INDEX idx_{$fkCol} ({$fkCol})";
        $parts[] = "ADD CONSTRAINT {$fkConstraintName}
                       FOREIGN KEY ({$fkCol}) REFERENCES tblPlaces(Id)
                       ON DELETE SET NULL ON UPDATE CASCADE";
    }
    $sql = "ALTER TABLE {$table} " . implode(', ', $parts);
    if (!$db->query($sql)) {
        _migPlacesAdopt_out("ERROR: ALTER {$table} failed: " . $db->error);
        exit(1);
    }
    if (!$hasVarchar) _migPlacesAdopt_out("[add ] {$table}.{$varcharCol}.");
    if (!$hasFk)      _migPlacesAdopt_out("[add ] {$table}.{$fkCol} (FK to tblPlaces).");
}

_migPlacesAdopt_out('Places adoption migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migPlacesAdopt_out('ERROR: could not connect to database.');
    exit(1);
}

/* tblPlaces must exist already — migrate-places.php is in the
   dependency chain above this one. Refuse to proceed otherwise so
   the FK constraints below don't blow up halfway. */
if (!_migPlacesAdopt_tableExists($mysqli, 'tblPlaces')) {
    _migPlacesAdopt_out('ERROR: tblPlaces not found. Run migrate-places first.');
    exit(1);
}

_migPlacesAdopt_addPlacePair(
    $mysqli, 'tblSongbooks',
    'PublicationCity', 'PublicationCityId',
    'fk_Songbooks_PublicationCity',
    'PublicationYear'
);

_migPlacesAdopt_addPlacePair(
    $mysqli, 'tblOrganisations',
    'PhysicalCity', 'PhysicalCityId',
    'fk_Organisations_PhysicalCity',
    /* Description is the last reliably-present column from the
       original schema; the post-#640 join-table additions arrive
       in their own migrations. */
    'Description'
);

_migPlacesAdopt_addPlacePair(
    $mysqli, 'tblSongs',
    'OriginCity', 'OriginCityId',
    'fk_Songs_OriginCity',
    /* Copyright is reliably present in every install since #497;
       slotting after it keeps the column order tidy for SHOW
       CREATE TABLE inspection. */
    'Copyright'
);

_migPlacesAdopt_addPlacePair(
    $mysqli, 'tblWorks',
    'OriginCity', 'OriginCityId',
    'fk_Works_OriginCity',
    'Notes'
);

_migPlacesAdopt_out('Places adoption migration finished.');
