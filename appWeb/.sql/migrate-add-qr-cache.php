<?php

declare(strict_types=1);

/**
 * iHymns — QR image cache (#1920)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: a QR code for a fixed link/text never changes — so instead of asking
 * our CueRCode partner service to redraw the SAME picture over and over on
 * every server-cold hit, we keep a copy of the bytes it drew us the first
 * time, in our OWN database, and hand that copy back next time.
 *
 * DETAIL:
 * Additive, dormant, one-pass schema (rule #19/#20) backing the read-through
 * cache `cuercodeGenerateCached()` (includes/cuercode_client.php, #1920 C3).
 * `CacheKey` is a sha256 hex over the canonical payload+normalised-options
 * JSON (`cuercodeCacheKey()`) — the SAME normalised option map the outbound
 * HTTP request body is built from, so a cache hit can never serve the wrong
 * image (the key and the request are one fold, not two that could drift).
 * `ParamsJson` is forward-looking (rule #20): a future QR customisation
 * option lands inside the hash + this column with NO schema change; a new
 * output `Format` is a VARCHAR value, never an ENUM add.
 *
 * Zero readers/writers exist until #1920 C3 lands (this migration is schema
 * only) — provably inert. Additive + idempotent (table-existence guarded).
 *
 * @migration-adds tblQrCache.CacheKey
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-qr-cache.php
 *   Web:  /manage/setup-database → "QR image cache (#1920)" button
 *
 * @see includes/cuercode_client.php   cuercodeCacheKey() / cuercodeGenerateCached()
 * @see includes/qr_cache.php          the fail-soft read/write/prune module
 * @see .claude/qr-cuercode-integration-plan.md
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migQrCache_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migQrCache_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migQrCache_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migQrCache_output("");
_migQrCache_output("=== iHymns — QR image cache (#1920) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migQrCache_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migQrCache_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migQrCache_tableExists($mysql, 'tblQrCache')) {
        _migQrCache_output("  [SKIP] tblQrCache already present.");
    } else {
        $mysql->query(
            "CREATE TABLE IF NOT EXISTS tblQrCache (
                CacheKey     CHAR(64)      NOT NULL COMMENT 'sha256 hex over the canonical payload+normalised-options JSON minted by cuercodeCacheKey() — the ONE key derivation (#1920)',
                Payload      VARCHAR(1024) NOT NULL COMMENT 'the encoded text/URL (bounded by CUERCODE_MAX_PAYLOAD_LEN); informational/debug — CacheKey is authoritative',
                ParamsJson   JSON          NOT NULL COMMENT 'the canonical normalised option map the key was derived from (format/size/ecc/type + optional colours today); a future option lands in the hash + here with NO schema change (rule #20)',
                Mime         VARCHAR(100)  NOT NULL COMMENT 'Content-Type CueRCode answered with (image/svg+xml, image/png)',
                Format       VARCHAR(10)   NOT NULL COMMENT 'svg | png today; VARCHAR not ENUM (rule #20)',
                Bytes        MEDIUMBLOB    NOT NULL COMMENT 'the QR image bytes exactly as CueRCode returned them; served verbatim',
                ByteLength   INT UNSIGNED  NOT NULL COMMENT 'strlen(Bytes), denormed so size accounting never reads blobs',
                CreatedAt    DATETIME      NOT NULL COMMENT 'UTC mint instant; DATETIME not TIMESTAMP (rule #20) so TTL pruning never re-reads through a session zone',
                LastAccessAt DATETIME      NULL DEFAULT NULL COMMENT 'DORMANT (#1920 one-pass, rule #20): reserved for a future LRU policy; v1 writes nothing here — TTL-on-CreatedAt is the shipped eviction',

                PRIMARY KEY (CacheKey),
                INDEX idx_CreatedAt (CreatedAt)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Server-side cache of CueRCode-generated QR images, keyed by payload+options hash (#1920).'"
        );
        _migQrCache_output("  [OK] Created tblQrCache.");
    }
    _migQrCache_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migQrCache_output("ERROR: " . $e->getMessage());
}
