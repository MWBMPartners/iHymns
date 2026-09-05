<?php

declare(strict_types=1);

/**
 * iHymns — Song-part type vocabulary (#1138)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * A controlled song-section vocabulary so the API can render part headers +
 * reorder-by-part — today only itunes:song-part in MetaJson + free-text
 * tblSongComponents.Type:
 *   - tblSongPartTypes (seeded with the 16 standard sections).
 *   - tblLyricLines.PartTypeSlug — typed key into tblSongPartTypes.Slug
 *     (tblSongComponents.Type stays free-text/authoritative).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * @migration-adds tblLyricLines.PartTypeSlug
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-part-types.php
 *   Web:  /manage/setup-database → "Song-part types" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migPT_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migPT_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migPT_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migPT_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migPT_output("");
_migPT_output("=== iHymns — Song-part types (#1138) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migPT_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migPT_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migPT_tableExists($mysql, 'tblSongPartTypes')) {
        _migPT_output("  [SKIP] tblSongPartTypes already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongPartTypes (
                Id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Slug       VARCHAR(40)  NOT NULL COMMENT 'intro|verse|pre-chorus|chorus|post-chorus|bridge|refrain|interlude|instrumental|solo|ad-lib|outro|tag|vamp|coda',
                Name       VARCHAR(60)  NOT NULL,
                IsNumbered TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Verse 1 / Chorus 2 vs Intro/Outro',
                SortOrder  INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uq_Slug (Slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Controlled song-part type vocabulary (#1138).'"
        );
        _migPT_output("  [OK] Created tblSongPartTypes.");
    }

    /* Seed the 16 standard sections (idempotent via INSERT IGNORE on uq_Slug). */
    $seed = [
        ['intro', 'Intro', 0], ['verse', 'Verse', 1], ['pre-chorus', 'Pre-Chorus', 1],
        ['chorus', 'Chorus', 1], ['post-chorus', 'Post-Chorus', 1], ['bridge', 'Bridge', 1],
        ['refrain', 'Refrain', 0], ['interlude', 'Interlude', 1], ['instrumental', 'Instrumental', 1],
        ['solo', 'Solo', 1], ['ad-lib', 'Ad-Lib', 0], ['outro', 'Outro', 0],
        ['tag', 'Tag', 1], ['vamp', 'Vamp', 1], ['coda', 'Coda', 0], ['breakdown', 'Breakdown', 1],
    ];
    $ins = $mysql->prepare("INSERT IGNORE INTO tblSongPartTypes (Slug, Name, IsNumbered, SortOrder) VALUES (?, ?, ?, ?)");
    $n = 0;
    foreach ($seed as $i => $row) {
        $slug = $row[0]; $name = $row[1]; $numbered = (int)$row[2]; $order = $i * 10;
        $ins->bind_param('ssii', $slug, $name, $numbered, $order);
        $ins->execute();
        if ($ins->affected_rows > 0) $n++;
    }
    $ins->close();
    _migPT_output("  [OK] Seeded {$n} new part types (16 total).");

    if (_migPT_colExists($mysql, 'tblLyricLines', 'PartTypeSlug')) {
        _migPT_output("  [SKIP] tblLyricLines.PartTypeSlug already present.");
    } else {
        $mysql->query("ALTER TABLE tblLyricLines ADD COLUMN PartTypeSlug VARCHAR(40) NULL DEFAULT NULL COMMENT 'Typed key into tblSongPartTypes.Slug (#1138)' AFTER PartType");
        _migPT_output("  [OK] Added tblLyricLines.PartTypeSlug.");
    }

    _migPT_output("Migration complete.");
} catch (\Throwable $e) { _migPT_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
