<?php

declare(strict_types=1);

/**
 * iHymns — Places Registry migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Stands up the canonical place registry that backs the live
 * suburb / city / state / country autocomplete in the admin
 * (Credit People edit drawer's Birth place / Death place fields,
 * and follow-up places anywhere else a free-text location field
 * exists). Two pieces:
 *
 *   1. tblPlaces — canonical row per geocoder object. Natural
 *      key is (Provider, OsmType, OsmId); manual entries with
 *      NULL OsmId are allowed but won't collide because MySQL
 *      treats NULL as distinct.
 *
 *   2. tblCreditPeople.BirthPlaceId / DeathPlaceId — nullable
 *      FK columns that point into tblPlaces. The legacy
 *      BirthPlace / DeathPlace VARCHAR columns stay alongside
 *      as denormalised display strings so reports + read paths
 *      don't need a JOIN on every read.
 *
 * Schema additions:
 *   tblPlaces                              (full CREATE TABLE)
 *   tblCreditPeople.BirthPlaceId           INT UNSIGNED NULL
 *   tblCreditPeople.DeathPlaceId           INT UNSIGNED NULL
 *
 * @migration-adds tblCreditPeople.BirthPlaceId
 * @migration-adds tblCreditPeople.DeathPlaceId
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-places.php
 *
 * Idempotent — re-running is safe; columns that already exist
 * are skipped, the table create uses IF NOT EXISTS.
 */

if (PHP_SAPI === 'cli') {
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

function _migPlaces_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migPlaces_tableExists(mysqli $db, string $table): bool
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

function _migPlaces_columnExists(mysqli $db, string $table, string $column): bool
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

_migPlaces_out('Places registry migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migPlaces_out('ERROR: could not connect to database.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: create tblPlaces
 * ----------------------------------------------------------------------
 * Mirrors the canonical declaration in schema.sql line-for-line so a
 * fresh-install and an upgraded install land structurally identical
 * (CI guards this via tests/php/test-schema-coverage.php). */
$createPlaces = "
    CREATE TABLE IF NOT EXISTS tblPlaces (
        Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        Provider        VARCHAR(20)     NOT NULL DEFAULT 'osm' COMMENT 'Geocoder family the OsmType/OsmId pair is namespaced under',
        OsmType         CHAR(1)         NULL COMMENT 'N=node, W=way, R=relation; NULL for manually-entered places',
        OsmId           BIGINT          NULL COMMENT 'OSM object id within OsmType; NULL for manually-entered places',
        DisplayName     VARCHAR(500)    NOT NULL COMMENT 'Full hierarchical label as returned by the geocoder',
        Name            VARCHAR(255)    NULL COMMENT 'Short label (city / village / suburb)',
        Suburb          VARCHAR(255)    NULL,
        City            VARCHAR(255)    NULL COMMENT 'City / town / village level (collapsed from OSM city/town/village/hamlet keys)',
        County          VARCHAR(255)    NULL,
        Region          VARCHAR(255)    NULL COMMENT 'State / province / region',
        Country         VARCHAR(255)    NULL,
        CountryCode     CHAR(2)         NULL COMMENT 'ISO-3166-1 alpha-2, lowercase',
        Latitude        DECIMAL(10,7)   NULL,
        Longitude       DECIMAL(10,7)   NULL,
        PlaceType       VARCHAR(50)     NULL COMMENT 'Geocoder-reported type: city, town, village, state, country, …',
        CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uk_OsmRef (Provider, OsmType, OsmId),
        INDEX idx_DisplayName (DisplayName),
        INDEX idx_CountryCode (CountryCode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";
if (_migPlaces_tableExists($mysqli, 'tblPlaces')) {
    _migPlaces_out('[skip] tblPlaces already present.');
} else {
    if (!$mysqli->query($createPlaces)) {
        _migPlaces_out('ERROR: creating tblPlaces failed: ' . $mysqli->error);
        exit(1);
    }
    _migPlaces_out('[add ] tblPlaces.');
}

/* ----------------------------------------------------------------------
 * Step 2: add tblCreditPeople.BirthPlaceId + DeathPlaceId
 * ----------------------------------------------------------------------
 * The parent table must already exist — the credit-people registry
 * migration is in the dependency chain above this one. We bail with
 * a clear pointer rather than failing later in the ALTER. */
if (!_migPlaces_tableExists($mysqli, 'tblCreditPeople')) {
    _migPlaces_out('ERROR: tblCreditPeople not found. Run migrate-credit-people first.');
    exit(1);
}

if (_migPlaces_columnExists($mysqli, 'tblCreditPeople', 'BirthPlaceId')) {
    _migPlaces_out('[skip] tblCreditPeople.BirthPlaceId already present.');
} else {
    $sql = 'ALTER TABLE tblCreditPeople
            ADD COLUMN BirthPlaceId INT UNSIGNED NULL
            AFTER BirthPlace,
            ADD INDEX idx_BirthPlaceId (BirthPlaceId),
            ADD CONSTRAINT fk_CreditPeople_BirthPlace
                FOREIGN KEY (BirthPlaceId) REFERENCES tblPlaces(Id)
                ON DELETE SET NULL ON UPDATE CASCADE';
    if (!$mysqli->query($sql)) {
        _migPlaces_out('ERROR: adding BirthPlaceId failed: ' . $mysqli->error);
        exit(1);
    }
    _migPlaces_out('[add ] tblCreditPeople.BirthPlaceId.');
}

if (_migPlaces_columnExists($mysqli, 'tblCreditPeople', 'DeathPlaceId')) {
    _migPlaces_out('[skip] tblCreditPeople.DeathPlaceId already present.');
} else {
    $sql = 'ALTER TABLE tblCreditPeople
            ADD COLUMN DeathPlaceId INT UNSIGNED NULL
            AFTER DeathPlace,
            ADD INDEX idx_DeathPlaceId (DeathPlaceId),
            ADD CONSTRAINT fk_CreditPeople_DeathPlace
                FOREIGN KEY (DeathPlaceId) REFERENCES tblPlaces(Id)
                ON DELETE SET NULL ON UPDATE CASCADE';
    if (!$mysqli->query($sql)) {
        _migPlaces_out('ERROR: adding DeathPlaceId failed: ' . $mysqli->error);
        exit(1);
    }
    _migPlaces_out('[add ] tblCreditPeople.DeathPlaceId.');
}

_migPlaces_out('Places registry migration finished.');
