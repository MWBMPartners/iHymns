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
 * #1039 Part A EXTENSION (2026-08-18): the column + ft_LyricsTextFolded shipped
 * dormant here; this now also adds the two FULLTEXT indexes the live search read
 * path's dual-arm MATCH() needs (ft_NormalizedTitle for title-only mode;
 * ft_NormTitleLyricsFolded for title+lyrics mode) and a batched PHP backfill of
 * BOTH folded columns (LyricsTextFolded + the long-unmaintained NormalizedTitle).
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
        /* COMMENT byte-identical to schema.sql:309 so a fresh install equals a
           migrated one (rule #19). An install that already ran the v1 comment
           ("(#1142)") keeps it — harmless drift the Schema Audit page notes. */
        $mysql->query("ALTER TABLE tblSongs ADD COLUMN LyricsTextFolded MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Diacritic-folded mirror of LyricsText for accent-insensitive FULLTEXT (Noël↔Noel) (#1090 audit / #1039); app-maintained on write' AFTER LyricsText");
        _migSyn_output("  [OK] Added tblSongs.LyricsTextFolded.");
    }
    if (_migSyn_idxExists($mysql, 'tblSongs', 'ft_LyricsTextFolded')) {
        _migSyn_output("  [SKIP] ft_LyricsTextFolded present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD FULLTEXT INDEX ft_LyricsTextFolded (LyricsTextFolded)");
        _migSyn_output("  [OK] Added FULLTEXT index ft_LyricsTextFolded.");
    }

    /* #1039 Part A — the two FULLTEXT indexes the dual-arm search read path
       MATCH()es the folded columns against. Title-only mode matches
       ft_NormalizedTitle; title+lyrics mode matches ft_NormTitleLyricsFolded
       (a MATCH() column set must EXACTLY mirror a FULLTEXT index). Idempotent
       per-object; byte-identical to their schema.sql mirrors (rule #19). */
    if (_migSyn_idxExists($mysql, 'tblSongs', 'ft_NormalizedTitle')) {
        _migSyn_output("  [SKIP] ft_NormalizedTitle present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD FULLTEXT INDEX ft_NormalizedTitle (NormalizedTitle)");
        _migSyn_output("  [OK] Added FULLTEXT index ft_NormalizedTitle.");
    }
    if (_migSyn_idxExists($mysql, 'tblSongs', 'ft_NormTitleLyricsFolded')) {
        _migSyn_output("  [SKIP] ft_NormTitleLyricsFolded present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD FULLTEXT INDEX ft_NormTitleLyricsFolded (NormalizedTitle, LyricsTextFolded)");
        _migSyn_output("  [OK] Added FULLTEXT index ft_NormTitleLyricsFolded.");
    }

    /* #1039 Part A — batched backfill of the folded columns. Requires the ONE
       fold point (ihymns_search_fold ⇒ ihymns_normalize_title). Resolve the
       docroot includes/ deploy-agnostically (rule #41): IHYMNS_INCLUDES_DIR is
       defined by the setup-database runner (= <real-docroot>/includes on any
       channel, whatever it is renamed to), with the literal public_html path as
       the standalone/CLI/test fallback ONLY — never a column-0 literal require
       (test-deploy-paths.php enforces). */
    $_incDir = defined('IHYMNS_INCLUDES_DIR')
        ? IHYMNS_INCLUDES_DIR
        : dirname(__DIR__) . '/public_html/includes';
    require_once $_incDir . '/title_normalize.php';

    /* Idempotent by construction: only rows whose LyricsTextFolded is still NULL
       are folded, and each folded row becomes non-NULL (ihymns_search_fold('')
       is '', never NULL) so it drops out of the next batch — the loop always
       terminates and a re-run is a cheap no-op once complete. Selecting on
       LyricsTextFolded (NOT "OR NormalizedTitle = ''") is deliberate: a title
       that legitimately folds to '' would otherwise be re-selected forever. The
       first run touches EVERY row (all dormant-NULL), which is what repairs the
       long-unmaintained NormalizedTitle across the whole corpus. */
    $backfilled = 0;
    $updBf = $mysql->prepare('UPDATE tblSongs SET NormalizedTitle = ?, LyricsTextFolded = ? WHERE SongId = ?');
    do {
        $sel = $mysql->query(
            "SELECT SongId, Title, LyricsText FROM tblSongs
              WHERE LyricsTextFolded IS NULL
              LIMIT 200"
        );
        $batch = [];
        if ($sel) { while ($r = $sel->fetch_assoc()) { $batch[] = $r; } $sel->free(); }
        foreach ($batch as $r) {
            /* #1908 D6 — cap the title fold to the NormalizedTitle column width
               (VARCHAR(500)); NFKD can EXPAND a title (Hangul decomposes to 2-3
               jamo per syllable) and this backfill now emits the NEW fold on
               any future/fresh run. $lf (LyricsTextFolded, MEDIUMTEXT) stays
               uncapped — it must not be truncated. */
            $nt  = mb_substr(ihymns_search_fold((string)$r['Title']), 0, 500);
            $lf  = ihymns_search_fold((string)$r['LyricsText']);
            $sid = (string)$r['SongId'];
            $updBf->bind_param('sss', $nt, $lf, $sid);
            $updBf->execute();
            $backfilled++;
            if (($backfilled % 2000) === 0) { _migSyn_output("  … backfilled {$backfilled} rows"); }
        }
    } while (count($batch) === 200);
    $updBf->close();
    _migSyn_output("  [OK] Backfilled folded columns for {$backfilled} row(s).");

    _migSyn_output("Migration complete.");
} catch (\Throwable $e) { _migSyn_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
