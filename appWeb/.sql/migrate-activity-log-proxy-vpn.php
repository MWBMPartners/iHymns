<?php

declare(strict_types=1);

/**
 * iHymns — Activity Log Proxy/VPN + Per-Request Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds the columns the per-request activity-log instrumentation +
 * proxy/VPN detection writes to. Without these the new logActivity
 * IP-resolver path silently drops the proxy chain + indicator on
 * every row.
 *
 * Adds three columns to tblActivityLog:
 *   - IpProxyChain      VARCHAR(255) NULL — comma-separated chain of
 *                                            intermediate IPs from
 *                                            X-Forwarded-For (last
 *                                            hop = the proxy that
 *                                            actually delivered the
 *                                            request to PHP).
 *   - ProxyVpnIndicator VARCHAR(50)  NULL — heuristic / external
 *                                            classification:
 *                                            none | cloudflare | xff
 *                                            | datacentre | vpn |
 *                                            tor | proxy.
 *   - ProxyVpnDetail    JSON         NULL — { source, provider,
 *                                            score, headers, … } —
 *                                            populated by the
 *                                            heuristic resolver and
 *                                            (future) external lookup.
 *
 * Plus one new table tblIpReputation:
 *   - cached results from the future external lookup (IPQS / MaxMind
 *     / ipinfo.io). Keyed by IP address; TTL'd per source. Empty on
 *     fresh installs — the resolver writes through it as lookups
 *     complete.
 *
 * Idempotent — re-running is safe.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-activity-log-proxy-vpn.php
 *   Web: /manage/setup-database → "Activity Log Proxy/VPN Migration"
 *
 * @migration-adds tblActivityLog.IpProxyChain
 * @migration-adds tblActivityLog.ProxyVpnIndicator
 * @migration-adds tblActivityLog.ProxyVpnDetail
 * @migration-adds tblIpReputation
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migActPv_out(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credPath)) {
    _migActPv_out('ERROR: db_credentials.php not found — run install.php first.');
    exit(1);
}
if (!defined('DB_HOST')) {
    require_once $credPath;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int)DB_PORT : 3306);
if ($mysqli->connect_errno) {
    _migActPv_out('ERROR: MySQL connect failed: ' . $mysqli->connect_error);
    exit(1);
}
$mysqli->set_charset('utf8mb4');

_migActPv_out('=== iHymns Activity Log Proxy/VPN Migration ===');
_migActPv_out('Database: ' . DB_NAME . ' @ ' . DB_HOST);
_migActPv_out('');

function _migActPv_colExists(mysqli $db, string $table, string $col): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function _migActPv_tableExists(mysqli $db, string $table): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

if (!_migActPv_tableExists($mysqli, 'tblActivityLog')) {
    _migActPv_out('ERROR: tblActivityLog is missing — run install.php and the activity-log-expand migration first.');
    exit(1);
}

/* Columns on tblActivityLog. */
$cols = [
    ['col' => 'IpProxyChain', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN IpProxyChain VARCHAR(255) NULL
        COMMENT 'Comma-separated proxy chain from X-Forwarded-For; last hop = proxy that delivered to PHP'
        AFTER IpAddress"],
    ['col' => 'ProxyVpnIndicator', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN ProxyVpnIndicator VARCHAR(50) NULL
        COMMENT 'Heuristic/external classification: none | cloudflare | xff | datacentre | vpn | tor | proxy'
        AFTER IpProxyChain"],
    ['col' => 'ProxyVpnDetail', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN ProxyVpnDetail JSON NULL
        COMMENT 'Provider / score / source headers — populated by heuristic resolver + (future) external lookup'
        AFTER ProxyVpnIndicator"],
];

foreach ($cols as $s) {
    if (_migActPv_colExists($mysqli, 'tblActivityLog', $s['col'])) {
        _migActPv_out("[skip] tblActivityLog.{$s['col']} already present.");
        continue;
    }
    if (!$mysqli->query($s['sql'])) {
        _migActPv_out("ERROR: adding {$s['col']} failed: " . $mysqli->error);
        exit(1);
    }
    _migActPv_out("[add ] tblActivityLog.{$s['col']}.");
}

/* Index on ProxyVpnIndicator so curators can filter "all VPN-flagged
   requests in last 7 days" without a full scan. */
$idxSql = 'CREATE INDEX idx_ProxyVpnIndicator ON tblActivityLog (ProxyVpnIndicator)';
$idxName = 'idx_ProxyVpnIndicator';
$probe = $mysqli->prepare(
    'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
);
$tbl = 'tblActivityLog';
$probe->bind_param('ss', $tbl, $idxName);
$probe->execute();
$idxExists = (bool)$probe->get_result()->fetch_row();
$probe->close();
if ($idxExists) {
    _migActPv_out("[skip] tblActivityLog {$idxName} already present.");
} else {
    if (!$mysqli->query($idxSql)) {
        _migActPv_out("WARN: creating {$idxName} failed: " . $mysqli->error);
    } else {
        _migActPv_out("[add ] tblActivityLog {$idxName}.");
    }
}

/* tblIpReputation cache — keyed by IpAddress, holds the result of
   external proxy/VPN lookups so we don't pay the latency on every
   subsequent request from the same IP. The lookup helper writes
   through; the resolver reads through. */
if (_migActPv_tableExists($mysqli, 'tblIpReputation')) {
    _migActPv_out('[skip] tblIpReputation already present.');
} else {
    $sql = "CREATE TABLE tblIpReputation (
        IpAddress      VARCHAR(45)  NOT NULL PRIMARY KEY,
        Indicator      VARCHAR(50)  NULL
                       COMMENT 'cloudflare | xff | datacentre | vpn | tor | proxy | none',
        Provider       VARCHAR(100) NULL
                       COMMENT 'Provider name from external lookup (e.g. NordVPN, AWS, Cloudflare WARP)',
        Score          SMALLINT     NULL
                       COMMENT 'Confidence score 0-100 if the lookup source provides one',
        Detail         JSON         NULL
                       COMMENT 'Raw lookup response, kept for forensic + audit',
        Source         VARCHAR(50)  NOT NULL DEFAULT ''
                       COMMENT 'header | ipqs | maxmind | ipinfo | manual',
        LookedUpAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ExpiresAt      TIMESTAMP    NULL,

        INDEX idx_Indicator (Indicator),
        INDEX idx_Expires   (ExpiresAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        _migActPv_out('ERROR: creating tblIpReputation failed: ' . $mysqli->error);
        exit(1);
    }
    _migActPv_out('[add ] tblIpReputation.');
}

_migActPv_out('');
_migActPv_out('Migration complete.');
$mysqli->close();
