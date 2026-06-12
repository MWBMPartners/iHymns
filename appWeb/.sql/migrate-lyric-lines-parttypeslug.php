<?php

declare(strict_types=1);

/**
 * iHymns — tblLyricLines.PartTypeSlug backfill (#1235 P4 / C2; closes the #1138 gap)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE: the typed-part column `tblLyricLines.PartTypeSlug` (#1138) was added but
 * NEVER populated — every line carries the free-text `PartType` (e.g. 'verse') while
 * `PartTypeSlug` is NULL on all 291k+ rows. P4's line-first assembler + future export
 * paths key part identity on the controlled-vocab slug, so backfill it from the
 * free-text type via the canonical `tblSongPartTypes` allow-list.
 *
 * DATA-ONLY — no DDL (the column already exists in schema.sql), so there is NO
 * schema.sql delta and no @migration-adds doctag. Strictly idempotent: a single
 * allow-list JOIN that touches only rows whose slug is still NULL and whose
 * lower-cased `PartType` matches a known slug. An unrecognised `PartType` is LEFT
 * NULL (never invented — rule #20); on the real corpus all seven live values map, so
 * the expected post-state is 0 NULL slugs for mappable rows.
 *
 * Paired in the SAME commit with slug-at-write in the projector
 * (lyric_lines_sync.php::lyricLinesPartTypeSlug + lyricLinesBuildDesired) so a
 * re-projection on the next save keeps writing the slug — a backfill alone would rot.
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Lyric-line PartTypeSlug backfill (#1138)"
 *   CLI:  php appWeb/.sql/migrate-lyric-lines-parttypeslug.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migPTSlug_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migPTSlug_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migPTSlug_out('PartTypeSlug backfill (#1235 P4 / C2)…');

/* Guard: both tables + the column must exist (un-migrated install → skip cleanly
   rather than throw on a missing object). */
try {
    $haveCol = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'tblLyricLines' AND COLUMN_NAME = 'PartTypeSlug' LIMIT 1"
    );
    $okCol = $haveCol && $haveCol->fetch_row() !== null;
    if ($haveCol) { $haveCol->close(); }
    $haveVocab = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongPartTypes' LIMIT 1"
    );
    $okVocab = $haveVocab && $haveVocab->fetch_row() !== null;
    if ($haveVocab) { $haveVocab->close(); }
} catch (\Throwable $e) {
    _migPTSlug_out('  [SKIP] schema probe failed: ' . $e->getMessage());
    if ($isCli) { exit(0); }
    return;
}
if (!$okCol || !$okVocab) {
    _migPTSlug_out('  [SKIP] tblLyricLines.PartTypeSlug or tblSongPartTypes absent (un-migrated install).');
    if ($isCli) { exit(0); }
    return;
}

/* Idempotent allow-list backfill. The JOIN restricts to PartType values that match a
   real slug; the WHERE restricts to rows not yet set — so a re-run touches nothing.
   No user input enters the SQL. */
$db->query(
    "UPDATE tblLyricLines ll
       JOIN tblSongPartTypes pt ON pt.Slug = LOWER(ll.PartType)
        SET ll.PartTypeSlug = pt.Slug
      WHERE ll.PartTypeSlug IS NULL"
);
$updated = $db->affected_rows;
_migPTSlug_out("  [OK] Backfilled PartTypeSlug on {$updated} line(s).");

/* Report any still-unmapped rows (an unknown PartType with no slug) — left NULL on
   purpose; surfaced so a curator can add the vocab term + re-run if needed. */
$res = $db->query(
    "SELECT COUNT(*) AS n, ll.PartType
       FROM tblLyricLines ll
      WHERE ll.PartTypeSlug IS NULL
        AND ll.PartType IS NOT NULL AND ll.PartType <> ''
      GROUP BY ll.PartType
      ORDER BY n DESC"
);
$unmapped = 0;
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $unmapped += (int)$row['n'];
        _migPTSlug_out('  [INFO] ' . (int)$row['n'] . " line(s) with unmapped PartType '"
            . htmlspecialchars((string)$row['PartType'], ENT_QUOTES, 'UTF-8') . "' left NULL.");
    }
    $res->close();
}
_migPTSlug_out($unmapped === 0
    ? '  [DONE] No unmapped PartType remains — every line is typed.'
    : "  [DONE] {$unmapped} line(s) left with an unmapped PartType (add the term to tblSongPartTypes + re-run).");

if ($isCli) { exit(0); }
