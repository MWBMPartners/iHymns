<?php

declare(strict_types=1);

/**
 * iHymns — JSON-backed extensible tier capabilities
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds tblAccessTiers.Capabilities (JSON NULL) — the additive, schema-free home for
 * NEW gated tier capabilities. The 7 original capabilities (CanViewLyrics …
 * RequiresCcli) stay as their own TINYINT(1) columns (the existing native-app API
 * contract reads them); future caps are stored as keys inside this ONE JSON column,
 * so adding a gated feature is a ONE-LINE registry change in
 * includes/access_tier_validation.php (TIER_CAPS, storage 'json') — NO further ALTER.
 *
 * Forward-looking one-pass design (rule #20): a growable capability vocabulary is the
 * textbook "don't dribble ALTERs" case — JSON (app-validated against the TIER_CAPS
 * map) is the correct shape, NOT an ENUM and NOT one-new-column-per-feature. NULL =
 * "no json caps set" (every json cap falls back to its TIER_CAPS default); the admin
 * UI + the API CRUD + the API emit + content gating all derive the cap list from
 * TIER_CAPS, so they pick up a new json cap with zero per-surface edits.
 *
 * Additive + IDEMPOTENT (column-existence guarded). The read path
 * (tierCapRead() in access_tier_validation.php) is column-existence-gated, so the app
 * works whether or not this migration has run yet — an un-migrated install just sees
 * every json cap at its default.
 *
 * @migration-adds tblAccessTiers.Capabilities
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-tier-capabilities-json.php
 *   Web:  /manage/setup-database → "JSON-backed tier capabilities" button
 *
 * @requires PHP 8.1+ with mysqli
 */

/* ELI5: are we being run from the command line, or clicked in the web dashboard?
   WHY: a web run needs plain-text headers; the dashboard sets a sentinel constant so
   the migration runner can capture our echo output instead of leaking raw HTTP headers. */
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* ELI5: print one line, in CLI or HTML form depending on where we run.
   WHY: a single output helper keeps the migration's progress legible in both the
   terminal and the dashboard's live <pre> capture (mirrors every other migrate-*.php). */
function _migTCJ_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

/* ELI5: does this column already exist on this table?
   WHY: makes the migration idempotent — re-running it is a safe no-op. INFORMATION_SCHEMA
   is queried with a bound param (rule #5), never string-interpolation.
   https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html */
function _migTCJ_columnExists(\mysqli $db, string $t, string $c): bool
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

/* ELI5: load the MySQL login details written by install.php.
   WHY: the migration runs standalone (CLI or web) so it needs its own connection; it
   cannot assume the app bootstrap has run. Bail clearly if the install step is missing. */
$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migTCJ_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migTCJ_output("");
_migTCJ_output("=== iHymns — JSON-backed tier capabilities (tblAccessTiers.Capabilities) ===");

/* ELI5: open the database connection.
   WHY: mysqli runs under MYSQLI_REPORT_ERROR|STRICT app-wide, so a bad statement THROWS
   — we catch the connect failure to print a friendly line instead of a stack trace. */
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migTCJ_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migTCJ_output("Connected to MySQL: " . DB_NAME);

try {
    /* ELI5: only add the column if it isn't already there.
       WHY: idempotency — the dashboard "Apply all pending" runner and curators may re-run
       this; a guarded ADD COLUMN keeps re-runs a clean no-op rather than a 1060 error. */
    if (_migTCJ_columnExists($mysql, 'tblAccessTiers', 'Capabilities')) {
        _migTCJ_output("  [SKIP] tblAccessTiers.Capabilities already present.");
    } else {
        /* ELI5: add one JSON column that holds every FUTURE gated capability as named keys.
           WHY: JSON (not ENUM, not a new column per feature) is the additive home for a
           growable vocabulary (rule #20). Byte-identical to the schema.sql mirror so a
           fresh install equals a migrated one. NULL = no json caps set → defaults apply. */
        $mysql->query(
            "ALTER TABLE tblAccessTiers
                ADD COLUMN Capabilities JSON NULL
                COMMENT 'json-backed extensible tier caps (rule 20); future gated capabilities live here as named keys, no per-feature ALTER'
                AFTER RequiresCcli"
        );
        _migTCJ_output("  [OK] Added tblAccessTiers.Capabilities.");
    }
    _migTCJ_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migTCJ_output("ERROR: " . $e->getMessage());
}
