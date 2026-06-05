<?php

declare(strict_types=1);

/**
 * iHymns — NormalizedTitle dedup/match column (#1066 Theme D)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Duplicate detection and ingest song-resolution both normalise titles row by
 * row in PHP today — O(N) per lookup. This migration adds an app-maintained,
 * indexed fold of Title so those become an indexed pre-filter (the exact compare
 * still runs in PHP, since MySQL 8 cannot reproduce ihymns_normalize_title()).
 *
 *   - tblSongs.NormalizedTitle VARCHAR(500) NOT NULL DEFAULT '' + idx_NormalizedTitle.
 *
 * It is a PLAIN column (not GENERATED) deliberately. The migration BACKFILLS
 * every existing row using the canonical PHP normalizer; write paths keep it in
 * sync on create/edit (follow-up feature work).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column existence guarded; backfill re-runnable).
 *
 * @migration-adds tblSongs.NormalizedTitle
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-normalized-title.php
 *   Web:  /manage/setup-database → "NormalizedTitle dedup column" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migNormTitle_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migNormTitle_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

/* The canonical title normalizer the backfill (and write paths) use. */
$normHelper = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'title_normalize.php';
if (!file_exists($normHelper)) {
    _migNormTitle_output("ERROR: title_normalize.php not found at {$normHelper}.");
    return;
}
require_once $normHelper;
if (!function_exists('ihymns_normalize_title')) {
    _migNormTitle_output("ERROR: ihymns_normalize_title() unavailable after include.");
    return;
}

_migNormTitle_output("");
_migNormTitle_output("=== iHymns — NormalizedTitle dedup column (#1066 Theme D) ===");
_migNormTitle_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migNormTitle_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migNormTitle_output("Connected to MySQL: " . DB_NAME);

try {
    _migNormTitle_output("");
    _migNormTitle_output("--- tblSongs.NormalizedTitle + index ---");

    $hasCol = $mysql->query("SHOW COLUMNS FROM tblSongs LIKE 'NormalizedTitle'");
    if ($hasCol && $hasCol->num_rows > 0) {
        _migNormTitle_output("  [SKIP] tblSongs.NormalizedTitle already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN NormalizedTitle VARCHAR(500) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci
                    COMMENT 'App-maintained fold of Title via ihymns_normalize_title() for a fast indexed dedup/match pre-filter; exact compare still runs in PHP (#1066 Theme D)'
                    AFTER Title"
        );
        _migNormTitle_output("  [OK] Added tblSongs.NormalizedTitle.");
    }

    $hasIdx = $mysql->query("SHOW INDEX FROM tblSongs WHERE Key_name = 'idx_NormalizedTitle'");
    if ($hasIdx && $hasIdx->num_rows > 0) {
        _migNormTitle_output("  [SKIP] idx_NormalizedTitle already present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_NormalizedTitle (NormalizedTitle)");
        _migNormTitle_output("  [OK] Added index idx_NormalizedTitle.");
    }

    _migNormTitle_output("");
    _migNormTitle_output("--- Backfill (re-runnable; recomputes every row) ---");

    $sel = $mysql->query("SELECT Id, Title FROM tblSongs");
    $upd = $mysql->prepare("UPDATE tblSongs SET NormalizedTitle = ? WHERE Id = ?");
    $count = 0;
    $changed = 0;
    while ($row = $sel->fetch_assoc()) {
        $norm = ihymns_normalize_title((string)$row['Title']);
        if (mb_strlen($norm) > 500) {
            $norm = mb_substr($norm, 0, 500);
        }
        $id = (int)$row['Id'];
        $upd->bind_param('si', $norm, $id);
        $upd->execute();
        $count++;
        if ($upd->affected_rows > 0) {
            $changed++;
        }
        if ($count % 500 === 0) {
            _migNormTitle_output("  …{$count} rows processed");
        }
    }
    $upd->close();
    _migNormTitle_output("  [OK] Backfilled {$count} songs ({$changed} updated).");

    _migNormTitle_output("");
    _migNormTitle_output("--- Summary ---");
    _migNormTitle_output("  tblSongs.NormalizedTitle is populated + indexed. Duplicate detection and");
    _migNormTitle_output("  ingest resolution can now pre-filter on the index (PHP does the exact compare).");
    _migNormTitle_output("  Write paths must keep it in sync on create/edit (follow-up feature work).");
    _migNormTitle_output("");
    _migNormTitle_output("Migration complete.");
} catch (\Throwable $e) {
    _migNormTitle_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
