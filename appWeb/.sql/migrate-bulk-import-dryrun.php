<?php

declare(strict_types=1);

/**
 * iHymns — ZIP bulk-import dry-run flag (#1911, Wave 4 Commit C6)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * One new, empty-by-default column: `tblBulkImportJobs.DryRun`. It remembers
 * whether a curator ticked "preview only" when they started a ZIP import, so
 * the background worker that actually processes the ZIP — which runs in a
 * separate step after the upload has already finished — knows not to write
 * anything for real. This migration only adds the column; nothing reads or
 * writes it until the matching api2.php / song_importers.php commit lands
 * alongside it.
 *
 * DETAILED / WHY (#1674, #1911, plan .claude/wave4-actionable-remainder-plan.md §C6)
 * ------------------------------------------------------------------------
 * #1674 already gave single-file import a working dry-run preview: the
 * request is fully synchronous, so a per-request static flag
 * (`_bulkImport_dryRun()` in includes/song_importers.php) is all that's
 * needed — it lives and dies inside the one PHP request that both parses
 * AND (would) save the file. ZIP import is different: `import_zip` persists
 * the upload to disk, creates a `tblBulkImportJobs` row, hands the browser a
 * `job_id` + `poll_url`, and only THEN does the actual per-song work — after
 * `fastcgi_finish_request()` has already released the HTTP connection. A
 * per-request static flag has nothing to survive on past that point for the
 * worker to read, and the separate `import_zip_status` poll requests that
 * follow are each a genuinely new PHP request with no memory of what the
 * original upload asked for. Because of that, #1674 shipped ZIP import with
 * a deliberate, honest 422 refusal instead of silently ignoring the flag
 * (rule #33) — this migration is what finally gives the flag somewhere
 * durable to live so that refusal can be lifted.
 *
 * TINYINT(1) NOT NULL DEFAULT 0, additive — mirrors the existing boolean-flag
 * columns on this table's siblings elsewhere in the schema. The table's own
 * `Status` column stays the grandfathered ENUM it already was; this
 * migration adds no new ENUM (rule #20 — DryRun is a genuine two-state
 * boolean, not a growable vocabulary, so TINYINT is the right shape here,
 * unlike Status/Kind/Signal-style columns).
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
 * @migration-adds tblBulkImportJobs.DryRun
 *
 * SCHEMA MIRROR: `DryRun` is mirrored byte-identical (incl. the full COMMENT
 * text) in `appWeb/.sql/schema.sql`, inline in the `tblBulkImportJobs`
 * CREATE TABLE block, immediately after the `Status` line — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-bulk-import-dryrun.php
 *   Web:  /manage/setup-database → "Run ZIP Dry-Run Migration" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/wave4-actionable-remainder-plan.md §C6  the spec this migration implements
 * @see appWeb/public_html/manage/includes/migration-registry.php  'bulk-import-dryrun' entry
 * @see appWeb/public_html/includes/song_importers.php  _bulkImport_dryRun() / _bulkImport_jobDryRunFlag()
 * @see appWeb/public_html/manage/editor/api2.php  import_zip / import_zip_status
 * @see #1674 #1911
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migBulkDryRun_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migBulkDryRun_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migBulkDryRun_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migBulkDryRun_output("");
_migBulkDryRun_output("=== iHymns — ZIP bulk-import dry-run flag (#1911) ===");
_migBulkDryRun_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migBulkDryRun_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migBulkDryRun_output("Connected to MySQL: " . DB_NAME);

try {
    _migBulkDryRun_output("--- tblBulkImportJobs.DryRun ---");
    if (_migBulkDryRun_colExists($mysql, 'tblBulkImportJobs', 'DryRun')) {
        _migBulkDryRun_output("  [SKIP] tblBulkImportJobs.DryRun already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblBulkImportJobs
    ADD COLUMN DryRun TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Preview-only flag (#1911) — set from the import_zip request at job-creation time and read back by the async worker (post-fastcgi_finish_request) and by import_zip_status polls, since neither can see the per-request _bulkImport_dryRun() static flag that gates single-file import (#1674). 1 = the worker must not write songs/songbooks or run songbook maintenance; SongsCreated/SongsSkippedExisting keep their normal meaning but describe what WOULD happen. Column-existence-gated everywhere it is read; an un-migrated install keeps the pre-#1911 422 refusal for dryRun=1 ZIP imports'"
        );
        _migBulkDryRun_output("  [OK] Added tblBulkImportJobs.DryRun.");
    }

    _migBulkDryRun_output("");
    _migBulkDryRun_output("=== Done. Bulk-import DryRun column is in place (dormant until a curator ticks it). ===");
} catch (\mysqli_sql_exception $e) {
    _migBulkDryRun_output("ERROR: migration failed: " . $e->getMessage());
    return;
}
