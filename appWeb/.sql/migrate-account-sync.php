<?php

declare(strict_types=1);

/**
 * iHymns — Account Sync + Shared Setlists Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Brings an existing iHymns deployment up to the schema required by
 * the per-user settings-sync feature and the shared-setlists move
 * from disk to MySQL. Specifically:
 *   1. tblUsers gets a `Settings` JSON column (synced UI prefs).
 *   2. tblSharedSetlists is created if missing.
 *   3. Any JSON files left in APP_SETLIST_SHARE_DIR (the legacy store)
 *      are imported into the new table, keyed by their filename so
 *      every existing share URL keeps resolving.
 *
 * Idempotent — re-running is safe; columns/tables that already exist
 * are skipped, and rows already imported are not duplicated.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-account-sync.php
 *   Web:  /manage/setup-database → "Account Sync Migration" button
 *         (entry point requires global_admin)
 *
 * PREREQUISITES:
 *   - install.php has been run (tables tblUsers + tblSharedSetlists exist
 *     in the canonical schema; this script handles existing deployments
 *     where they predate this migration).
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    /* Standalone web mode only — skip these headers when included by
     * the Setup dashboard so its HTML response isn't hijacked. */
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migAccSync_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* =========================================================================
 * LOAD MYSQL CREDENTIALS
 * ========================================================================= */

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migAccSync_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

/* =========================================================================
 * CONNECT TO MYSQL
 * ========================================================================= */

_migAccSync_output("");
_migAccSync_output("=== iHymns — Account Sync + Shared Setlists Migration ===");
_migAccSync_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migAccSync_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migAccSync_output("Connected to MySQL: " . DB_NAME);

/* =========================================================================
 * STEP 1: Add tblUsers.Settings column (idempotent)
 * ========================================================================= */

_migAccSync_output("");
_migAccSync_output("--- Step 1: tblUsers.Settings column ---");

try {
    $res = $mysql->query("SHOW COLUMNS FROM tblUsers LIKE 'Settings'");
    if ($res && $res->num_rows > 0) {
        _migAccSync_output("  [SKIP] Settings column already exists.");
    } else {
        $mysql->query(
            "ALTER TABLE tblUsers
             ADD COLUMN Settings JSON NULL DEFAULT NULL
                 COMMENT 'Synced per-user app preferences (theme, font, accessibility, etc.)'
             AFTER LoginCount"
        );
        _migAccSync_output("  [OK] Added Settings column to tblUsers.");
    }
} catch (\Throwable $e) {
    _migAccSync_output("  [ERROR] Could not add Settings column: " . $e->getMessage());
}

/* =========================================================================
 * STEP 2: Create tblSharedSetlists (idempotent)
 * ========================================================================= */

_migAccSync_output("");
_migAccSync_output("--- Step 2: tblSharedSetlists table ---");

try {
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblSharedSetlists (
            ShareId         VARCHAR(16)     NOT NULL PRIMARY KEY,
            Data            JSON            NOT NULL,
            CreatedBy       INT UNSIGNED    NULL DEFAULT NULL,
            CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ViewCount       INT UNSIGNED    NOT NULL DEFAULT 0,
            INDEX idx_CreatedBy (CreatedBy),
            INDEX idx_CreatedAt (CreatedAt),
            CONSTRAINT fk_SharedSetlists_User FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    _migAccSync_output("  [OK] tblSharedSetlists ready.");
} catch (\Throwable $e) {
    _migAccSync_output("  [ERROR] Could not create tblSharedSetlists: " . $e->getMessage());
    $mysql->close();
    return;
}

/* =========================================================================
 * STEP 3: Import existing JSON files from APP_SETLIST_SHARE_DIR
 * ========================================================================= */

_migAccSync_output("");
_migAccSync_output("--- Step 3: Import shared setlists from disk ---");

/* Resolve the legacy directory the same way includes/config.php does
 * (data_share/setlist_json relative to appWeb/). Hard-coded here so
 * the script works without bootstrapping the whole app. */
$shareDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'setlist_json';

$imported = 0;
$skipped  = 0;
$invalid  = 0;

if (!is_dir($shareDir)) {
    _migAccSync_output("  No legacy directory found at {$shareDir} — nothing to import.");
} else {
    $files = glob($shareDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    _migAccSync_output("  Found " . count($files) . " JSON file(s).");

    $checkStmt  = $mysql->prepare("SELECT 1 FROM tblSharedSetlists WHERE ShareId = ?");
    $insertStmt = $mysql->prepare(
        "INSERT INTO tblSharedSetlists (ShareId, Data) VALUES (?, ?)"
    );

    foreach ($files as $file) {
        $shareId = basename($file, '.json');
        if (!preg_match('/^[a-f0-9]{6,32}$/i', $shareId)) {
            $invalid++;
            continue; /* Skip non-share files (.gitkeep, etc.) */
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            _migAccSync_output("  [WARN] Could not read {$shareId}.json — skipped.");
            $invalid++;
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            _migAccSync_output("  [WARN] Invalid JSON in {$shareId}.json — skipped.");
            $invalid++;
            continue;
        }

        $checkStmt->bind_param('s', $shareId);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) {
            $checkStmt->free_result();
            $skipped++;
            continue;
        }
        $checkStmt->free_result();

        $jsonNormalised = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $insertStmt->bind_param('ss', $shareId, $jsonNormalised);
        $insertStmt->execute();
        $imported++;
    }

    $checkStmt->close();
    $insertStmt->close();
}

/* =========================================================================
 * SUMMARY
 * ========================================================================= */

_migAccSync_output("");
_migAccSync_output("--- Summary ---");
_migAccSync_output("  Shared setlists imported: {$imported}");
_migAccSync_output("  Shared setlists skipped:  {$skipped} (already in MySQL)");
_migAccSync_output("  Invalid / non-share files: {$invalid}");
_migAccSync_output("");
_migAccSync_output("Migration complete. Original JSON files were left in place; you can");
_migAccSync_output("delete them from {$shareDir} once you've verified the import.");

$mysql->close();
return;
