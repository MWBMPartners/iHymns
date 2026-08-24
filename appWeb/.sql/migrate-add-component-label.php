<?php

declare(strict_types=1);

/**
 * iHymns — Custom component labels (#1860 Phase 5, Commit 1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * One new, empty, dormant column: `tblSongComponents.Label`. It lets a
 * curator type an optional custom heading for one verse/chorus/bridge —
 * "isiZulu" instead of "Verse 3", or "Kyrie" instead of "Chorus 1" — while
 * everything that actually DOES something with the section (its CSS class,
 * arrangement, chorus-repeat, machine-export keywords) keeps using `Type`
 * exactly as before. This migration only adds the column; nothing reads or
 * writes it yet.
 *
 * DETAILED / WHY (design doc §3.7, this epic's plan §1)
 * ------------------------------------------------------------------------
 * `tblSongComponents` already carries `Language` (#858) and `SourceWorkId`
 * (#1860 Phase 3) as optional per-section overrides. `Label` is the third
 * sibling in that family — DISPLAY-ONLY, `Type` stays authoritative for CSS
 * highlighting, arrangement resolution, chorus-repeat and every machine
 * export section keyword (rule #20's DDL-forecast pass found nothing in
 * label-language, per-format captions or searchability that would force a
 * second migration — those stay deferred additive refinements, not reasons
 * to widen this column now).
 *
 * NULL means "derive the heading from Type+Number" (the existing behaviour).
 * The Phase-5 write seam (a later commit in this same epic, NOT this one)
 * stores NULL when a typed label equals the derived name (rule #27
 * hide-when-equal) — this migration does not touch that logic; it only
 * makes the column exist.
 *
 * VARCHAR(100), not ENUM (rule #20 — this is free text, not a bounded
 * vocabulary).
 *
 * No shared include is required — this is a single, self-contained ALTER
 * that mints nothing and calls no shared helper, so rule #41's
 * `IHYMNS_INCLUDES_DIR` resolution is N/A here: there is no
 * `require`/`include` of any file under a docroot's `includes/` directory
 * for a future editor to "fix" by threading it through that constant.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. The single ADD COLUMN is existence-guarded
 * (`colExists()`), so re-running this script after a successful apply is a
 * no-op [SKIP], and re-running after a failed apply resumes cleanly.
 *
 * @migration-adds tblSongComponents.Label
 *
 * SCHEMA MIRROR: `Label` is mirrored byte-identical (incl. the full COMMENT
 * text) in `appWeb/.sql/schema.sql`, inline in the `tblSongComponents`
 * CREATE TABLE block, immediately after the `SourceWorkId` line — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-component-label.php
 *   Web:  /manage/setup-database → "Run Component Label Migration" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/medley-component-work-1860-phase5-plan.md §1  the spec this migration implements
 * @see appWeb/public_html/manage/includes/migration-registry.php  'component-label' entry
 * @see #1860
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migCompLabel_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migCompLabel_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migCompLabel_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migCompLabel_output("");
_migCompLabel_output("=== iHymns — Custom component labels (#1860 Phase 5) ===");
_migCompLabel_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migCompLabel_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migCompLabel_output("Connected to MySQL: " . DB_NAME);

try {
    _migCompLabel_output("--- tblSongComponents.Label ---");
    if (_migCompLabel_colExists($mysql, 'tblSongComponents', 'Label')) {
        _migCompLabel_output("  [SKIP] tblSongComponents.Label already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongComponents
    ADD COLUMN Label VARCHAR(100) NULL DEFAULT NULL COMMENT 'Optional custom DISPLAY name for this section, overriding the derived \"Type Number\" heading (e.g. a Zulu verse shown as \"isiZulu\", a \"Kyrie\"). DISPLAY-ONLY: Type stays authoritative for CSS highlighting, arrangement resolution, chorus-repeat and machine-export section keywords. NULL = derive from Type+Number (stored NULL when a typed label equals the derived name, rule #27). Rendered inside the section''s own lang/dir context (#858). Per-section override sibling of Language (#858) and SourceWorkId (#1860 §3.6b). DORMANT until the #1860 Phase-5 wiring reads it'"
        );
        _migCompLabel_output("  [OK] Added tblSongComponents.Label.");
    }

    _migCompLabel_output("");
    _migCompLabel_output("=== Done. Component Label column is in place (dormant). ===");
} catch (\mysqli_sql_exception $e) {
    _migCompLabel_output("ERROR: migration failed: " . $e->getMessage());
    return;
}
