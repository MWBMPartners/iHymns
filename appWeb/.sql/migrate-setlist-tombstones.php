<?php

declare(strict_types=1);

/**
 * iHymns — Setlist tombstones + optional expiry (#1661)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The storage half of sync protocol 2 for user setlists. Two objects, ONE
 * migration, designed forward-looking in a single pass (CLAUDE.md rule #20)
 * so the feature never has to come back for a second ALTER:
 *
 *   1. tblUserSetlistTombstones — an explicit, permanent record that a
 *      setlist was deleted. Until now the server INFERRED deletion from a
 *      setlist being absent from a sync payload, which is the confusion that
 *      let a truncated payload read as "the user deleted 10 set lists"
 *      (#1649). With a tombstone, deletion is something a client SAYS, and
 *      truncation and deletion can never be conflated again.
 *
 *   2. tblUserSetlists.ExpiresAt — an OPTIONAL per-setlist expiry.
 *      NULL (the default, and what every existing row gets) means "never
 *      expires", so applying this migration changes the behaviour of exactly
 *      zero existing set lists. An expiry that passes is converted by the
 *      server into a tombstone with Reason='expired', so expiry and deletion
 *      converge on ONE propagation mechanism rather than two.
 *
 * DESIGN NOTES — the decisions that stop a second migration:
 *   - Reason is VARCHAR(20), NOT ENUM. A growable vocabulary in an ENUM makes
 *     "add a value" mean "ship an ALTER" — rule #20. The allow-list lives in
 *     userSyncTombstoneReasons() (includes/user_sync.php) and already declares
 *     'admin' alongside 'user'/'expired' so a curator-initiated removal needs
 *     no schema change.
 *   - DeletedAt and ExpiresAt are DATETIME, not TIMESTAMP — rule #20's TTL
 *     convention. TIMESTAMP is range-limited to 2038 and is silently converted
 *     against the session time zone, both of which are wrong for a value that
 *     is compared against an explicit clock reading.
 *   - The PK is (UserId, SetlistId): a setlist id is client-generated and only
 *     unique WITHIN a user, and this is also the exact lookup the sync path
 *     performs, so no secondary index is needed.
 *   - ON DELETE CASCADE: when an account is deleted its tombstones are
 *     meaningless. Mirrors fk_Setlists_User on the table this shadows.
 *
 * ⚠️ APPLYING THIS IS NOT AUTOMATIC. Migrations are web-run from
 * /manage/setup-database and the three docroots share ONE MySQL, so every
 * reader of these objects in includes/user_sync.php is existence-gated
 * (userSyncTombstonesReady() / userSyncExpiryReady()). An un-migrated install
 * degrades to "no tombstones, no expiry" — i.e. exactly today's behaviour —
 * rather than throwing under MYSQLI_REPORT_STRICT (#1228).
 *
 * Additive + IDEMPOTENT (existence-guarded on both objects). Safe to re-run.
 *
 * @migration-adds tblUserSetlistTombstones.SetlistId
 * @migration-adds tblUserSetlists.ExpiresAt
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-setlist-tombstones.php
 *   Web:  /manage/setup-database → "Setlist tombstones + optional expiry" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSLT_output(string $m): void
{
    global $isCli;
    echo $m . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { flush(); }
}

function _migSLT_tableExists(\mysqli $db, string $t): bool
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

function _migSLT_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migSLT_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSLT_output("");
_migSLT_output("=== iHymns — Setlist tombstones + optional expiry (#1661) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSLT_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSLT_output("Connected to MySQL: " . DB_NAME);

try {
    /* ------------------------------------------------------------------
     * 1/2 — the tombstone table.
     *
     * The DDL below must stay BYTE-IDENTICAL to its mirror in schema.sql
     * (rule #19), COMMENT text included, so a fresh install and a migrated
     * install are structurally the same database and the Schema Audit page
     * has nothing to report.
     * ------------------------------------------------------------------ */
    if (_migSLT_tableExists($mysql, 'tblUserSetlistTombstones')) {
        _migSLT_output("  [SKIP] tblUserSetlistTombstones already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblUserSetlistTombstones (
                UserId     INT UNSIGNED  NOT NULL,
                SetlistId  VARCHAR(100)  NOT NULL COMMENT 'The client-generated id of the deleted setlist',
                DeletedAt  DATETIME      NOT NULL COMMENT 'DB clock (userSyncNow frame) at deletion',
                Reason     VARCHAR(20)   NOT NULL DEFAULT 'user' COMMENT 'user | expired | admin — VARCHAR not ENUM (rule #20)',

                PRIMARY KEY (UserId, SetlistId),

                CONSTRAINT fk_SetlistTomb_User
                    FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Explicitly deleted user setlists — anti-resurrection + cross-device prune (#1661).'"
        );
        _migSLT_output("  [OK] Created tblUserSetlistTombstones.");
    }

    /* ------------------------------------------------------------------
     * 2/2 — the optional expiry column.
     *
     * NULL DEFAULT NULL is load-bearing: every existing row therefore reads
     * as "never expires", so this ALTER cannot make anybody's set list
     * disappear. The owner's decision (#1661) is explicitly that an unset
     * expiry means the setlist lives until the user deletes it.
     * ------------------------------------------------------------------ */
    if (_migSLT_columnExists($mysql, 'tblUserSetlists', 'ExpiresAt')) {
        _migSLT_output("  [SKIP] tblUserSetlists.ExpiresAt already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblUserSetlists
                ADD COLUMN ExpiresAt DATETIME NULL DEFAULT NULL COMMENT 'Optional expiry (#1661), UTC; NULL = never expires. DATETIME not TIMESTAMP (rule #20 TTL convention)'"
        );
        _migSLT_output("  [OK] Added tblUserSetlists.ExpiresAt.");
    }

    _migSLT_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSLT_output("ERROR: " . $e->getMessage());
}
