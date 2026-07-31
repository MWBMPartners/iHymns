<?php

declare(strict_types=1);

/**
 * iHymns — Set-list service plan / template slots (#301, #1671 F4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ONE nullable JSON column, `tblUserSetlists.SlotsJson`, giving a set list
 * somewhere to keep the service-order plan it was built from.
 *
 * WHY IT DID NOT EXIST BEFORE. `tblSetlistTemplates` (#301) has always stored a
 * template's whole payload as `SlotsJson`, and `setlist_template_save` has
 * always accepted one — but `user_setlists_sync` persisted SetlistId / Name /
 * SongsJson / CreatedAt / UpdatedAt / ExpiresAt and nothing else. Applying a
 * template therefore produced slots the next sync silently discarded, for
 * signed-in users only. That is why #1671 Batch 8 (commit 2f0d9445) refused to
 * build the UI: the storage half was missing, and a screen on top of it would
 * have been a data-loss bug with a button.
 *
 * DESIGN NOTES — the decisions that stop a second migration (rule #20):
 *   - ONE column, holding an ENVELOPE
 *         { "templateId": 3, "templateName": "Sunday Service", "slots": [ … ] }
 *     rather than a `SlotsJson` column PLUS a `TemplateId` foreign key. The
 *     envelope's templateName is a SNAPSHOT, so provenance survives the
 *     template being deleted or renamed — where `ON DELETE SET NULL` would
 *     erase it. It also keeps the FK (and its per-row existence check, and its
 *     1452 failure mode under MYSQLI_REPORT_STRICT) out of the single most
 *     damage-prone write loop in this codebase.
 *   - Every foreseeable growth — per-slot duration, a "required" flag, a
 *     colour, a second provenance field — lands INSIDE the JSON. A
 *     column-per-concept design guarantees the incremental ALTER rule #20
 *     forbids.
 *   - JSON, not TEXT: MySQL validates the document on write, so a malformed
 *     plan is rejected at the storage layer rather than discovered by a
 *     renderer. `tblSetlistTemplates.SlotsJson` (#301) is already JSON, so the
 *     two ends of the same value now agree on type.
 *   - NULL DEFAULT NULL is load-bearing: every existing row reads as "no
 *     service plan", so applying this changes the behaviour of exactly zero
 *     existing set lists. (A MySQL JSON column cannot carry a non-NULL literal
 *     DEFAULT, so NULL-means-absent is also the only honest encoding.)
 *
 * ⚠️ APPLYING THIS IS NOT AUTOMATIC. Migrations are web-run from
 * /manage/setup-database and the three docroots share ONE MySQL, so every
 * reader is existence-gated behind setlistSlotsColumnReady()
 * (includes/setlist_templates.php). An un-migrated install degrades to "set
 * lists have no plans" — i.e. exactly today's behaviour — rather than throwing
 * under MYSQLI_REPORT_STRICT on an absent column (#1228).
 *
 * Additive + IDEMPOTENT (existence-guarded). Safe to re-run.
 *
 * @migration-adds tblUserSetlists.SlotsJson
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-setlist-slots.php
 *   Web:  /manage/setup-database → "Set-list service plans (#301)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @link https://dev.mysql.com/doc/refman/8.0/en/json.html
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSLS_output(string $m): void
{
    global $isCli;
    echo $m . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { flush(); }
}

function _migSLS_columnExists(\mysqli $db, string $t, string $c): bool
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
    _migSLS_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSLS_output("");
_migSLS_output("=== iHymns — Set-list service plans / template slots (#301, #1671 F4) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSLS_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSLS_output("Connected to MySQL: " . DB_NAME);

try {
    /* ------------------------------------------------------------------
     * The DDL below must stay BYTE-IDENTICAL to its mirror in schema.sql
     * (rule #19), COMMENT text included, so a fresh install and a migrated
     * install are structurally the same database and the Schema Audit page
     * (#518) has nothing to report.
     * ------------------------------------------------------------------ */
    if (_migSLS_columnExists($mysql, 'tblUserSetlists', 'SlotsJson')) {
        _migSLS_output("  [SKIP] tblUserSetlists.SlotsJson already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblUserSetlists
                ADD COLUMN SlotsJson JSON NULL DEFAULT NULL COMMENT 'Optional service plan (#301/#1671 F4): {templateId, templateName, slots[]}. NULL = no plan'"
        );
        _migSLS_output("  [OK] Added tblUserSetlists.SlotsJson.");
    }

    _migSLS_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSLS_output("ERROR: " . $e->getMessage());
}
