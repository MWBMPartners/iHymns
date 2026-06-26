<?php

declare(strict_types=1);

/**
 * iHymns — Search synonyms + diacritic-folded FULLTEXT (#1142)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Close the recall gap on live FULLTEXT search (Saviour↔Savior, Noël↔Noel):
 *   - tblSearchSynonyms (PrimaryTerm, Synonym, Language) — query-expansion list.
 *   - tblSongs.LyricsTextFolded — diacritic-folded mirror of LyricsText (app
 *     maintains on write) + a FULLTEXT index for accent-insensitive search.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * @migration-adds tblSongs.LyricsTextFolded
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-search-synonyms.php
 *   Web:  /manage/setup-database → "Search synonyms + folding" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSyn_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSyn_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migSyn_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migSyn_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSyn_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSyn_output("");
_migSyn_output("=== iHymns — Search synonyms + folding (#1142) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSyn_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSyn_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migSyn_tableExists($mysql, 'tblSearchSynonyms')) {
        _migSyn_output("  [SKIP] tblSearchSynonyms already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSearchSynonyms (
                Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                PrimaryTerm VARCHAR(120) NOT NULL,
                Synonym     VARCHAR(120) NOT NULL,
                Language    VARCHAR(35)  NULL DEFAULT NULL,
                SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uq_Term_Syn (PrimaryTerm, Synonym, Language)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Search synonym expansion (#1142).'"
        );
        _migSyn_output("  [OK] Created tblSearchSynonyms.");
    }

    if (_migSyn_colExists($mysql, 'tblSongs', 'LyricsTextFolded')) {
        _migSyn_output("  [SKIP] tblSongs.LyricsTextFolded present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD COLUMN LyricsTextFolded MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Diacritic-folded mirror of LyricsText for accent-insensitive FULLTEXT (#1142)' AFTER LyricsText");
        _migSyn_output("  [OK] Added tblSongs.LyricsTextFolded.");
    }
    if (_migSyn_idxExists($mysql, 'tblSongs', 'ft_LyricsTextFolded')) {
        _migSyn_output("  [SKIP] ft_LyricsTextFolded present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD FULLTEXT INDEX ft_LyricsTextFolded (LyricsTextFolded)");
        _migSyn_output("  [OK] Added FULLTEXT index ft_LyricsTextFolded.");
    }

    _migSyn_output("Migration complete.");
} catch (\Throwable $e) { _migSyn_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
