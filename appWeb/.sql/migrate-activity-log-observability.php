<?php

declare(strict_types=1);

/**
 * iHymns — Activity Log observability columns (#1207 + #1208)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The activity log (tblActivityLog, #535) lives in the SHARED database, so it
 * records requests from all three environments (alpha / beta / production) with
 * no way to tell them apart. This migration adds the columns that let the log
 * answer "which environment, which file, from where, and from what country":
 *
 *   tblActivityLog:
 *     - Environment  VARCHAR(16)  — deploy env at log time (alpha|beta|production)
 *     - RequestPath  VARCHAR(512) — requested path (REQUEST_URI minus query)
 *     - Referrer     VARCHAR(2048)— HTTP Referer header (where the hit came from)
 *     - Country      CHAR(2)      — ISO-3166-1 country resolved from the IP AT
 *                                   log time (snapshot; geo→#1208 fills it)
 *   tblIpReputation (per-IP cache, extends the proxy/VPN cache):
 *     - CountryCode  CHAR(2)      — cached geo lookup result
 *     - CountryName  VARCHAR(100)
 *     - GeoLookedUpAt DATETIME    — cache freshness for the geo lookup
 *
 * One-pass forward-looking batch (house rule #20): the geo columns ship dormant
 * now alongside #1207's columns so #1208 (the geo resolver) needs no second
 * migration.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT — every add is guarded by a column/index probe,
 * so re-running on an already-migrated catalogue is a no-op.
 *
 * @migration-adds tblActivityLog.Environment
 * @migration-adds tblActivityLog.RequestPath
 * @migration-adds tblActivityLog.Referrer
 * @migration-adds tblActivityLog.Country
 * @migration-adds tblIpReputation.CountryCode
 * @migration-adds tblIpReputation.CountryName
 * @migration-adds tblIpReputation.GeoLookedUpAt
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Activity Log observability columns"
 *   CLI:  php appWeb/.sql/migrate-activity-log-observability.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migAlObs_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migAlObs_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

/** Add a column only when it's absent (idempotent). $ddl is the column
 *  definition AFTER `ADD COLUMN <name> `. */
function _migAlObs_addColumn(mysqli $db, string $table, string $col, string $ddl): void
{
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    if ($res && $res->num_rows > 0) {
        _migAlObs_out("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
    _migAlObs_out("  [OK] Added {$table}.{$col}.");
}

/** Add an index only when it's absent (idempotent). */
function _migAlObs_addIndex(mysqli $db, string $table, string $index, string $cols): void
{
    $res = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
    if ($res && $res->num_rows > 0) {
        _migAlObs_out("  [SKIP] index {$table}.{$index} already present.");
        return;
    }
    $db->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$cols})");
    _migAlObs_out("  [OK] Added index {$table}.{$index}.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migAlObs_out('=== iHymns — Activity Log observability columns (#1207 + #1208) ===');

try {
    _migAlObs_out('--- tblActivityLog ---');
    _migAlObs_addColumn($db, 'tblActivityLog', 'Environment',
        "VARCHAR(16) NULL DEFAULT NULL COMMENT 'Deploy environment at log time: alpha | beta | production (the DB is shared across all three) (#1207)'");
    _migAlObs_addColumn($db, 'tblActivityLog', 'RequestPath',
        "VARCHAR(512) NULL DEFAULT NULL COMMENT 'Requested path (REQUEST_URI minus query) — which file/route was hit (#1207)'");
    _migAlObs_addColumn($db, 'tblActivityLog', 'Referrer',
        "VARCHAR(2048) NULL DEFAULT NULL COMMENT 'HTTP Referer header — where the request came from (#1207)'");
    _migAlObs_addColumn($db, 'tblActivityLog', 'Country',
        "CHAR(2) NULL DEFAULT NULL COMMENT 'ISO-3166-1 alpha-2 country resolved from IpAddress AT log time (snapshot; geo resolver #1208 populates it)'");
    _migAlObs_addIndex($db, 'tblActivityLog', 'idx_Environment', 'Environment');
    _migAlObs_addIndex($db, 'tblActivityLog', 'idx_RequestPath', 'RequestPath(191)');
    _migAlObs_addIndex($db, 'tblActivityLog', 'idx_Country', 'Country');

    _migAlObs_out('--- tblIpReputation (geo cache, dormant until #1208) ---');
    _migAlObs_addColumn($db, 'tblIpReputation', 'CountryCode',
        "CHAR(2) NULL DEFAULT NULL COMMENT 'ISO-3166-1 alpha-2 from the geo lookup (#1208)'");
    _migAlObs_addColumn($db, 'tblIpReputation', 'CountryName',
        "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Country display name from the geo lookup (#1208)'");
    _migAlObs_addColumn($db, 'tblIpReputation', 'GeoLookedUpAt',
        "DATETIME NULL DEFAULT NULL COMMENT 'When geo was last resolved for this IP — drives the cache TTL (#1208)'");

    _migAlObs_out('');
    _migAlObs_out('Migration complete.');
} catch (\Throwable $e) {
    _migAlObs_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
