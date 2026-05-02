<?php

declare(strict_types=1);

/**
 * iHymns — Recompute tblSongbooks.SongCount from live tblSongs (#791)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongbooks.SongCount is a cached aggregate that the home + the
 * /songbooks tile-grid use to gate render — `if songCount > 0`.
 * Several write paths (bulk_import_zip, the legacy bulk `save`
 * action, /manage/songbooks edit / drag-drop) keep it in step, but
 * the per-song save_song handler hadn't until #791 / PR closing
 * this issue. Catalogues with editor-saved songs in newly-created
 * songbooks (notably Misc) ended up with SongCount stuck at 0 even
 * after rows landed in tblSongs — and the tile never appeared.
 *
 * This one-shot migration walks every tblSongbooks row and rewrites
 * SongCount from a live `SELECT COUNT(*) FROM tblSongs WHERE
 * SongbookAbbr = ?`. Idempotent: a row whose cached value already
 * matches the live count is a no-op. Reports before/after for any
 * row that drifted.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-recompute-songbook-songcount.php
 *   Web: /manage/setup-database → "Recompute Songbook SongCount (#791)"
 *        (entry point requires global_admin)
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migRecountSb_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

_migRecountSb_out('Songbook SongCount recompute starting (#791)…');

$db = getDbMysqli();
if (!$db) {
    _migRecountSb_out('ERROR: could not connect to database.');
    exit(1);
}

/* Pull every (Abbreviation, cached SongCount) pair, plus the live
   COUNT(*) from tblSongs, in one query. The LEFT JOIN keeps rows
   with zero matching songs (so an empty songbook with cached
   SongCount=N gets corrected to 0). */
$res = $db->query(
    "SELECT b.Id, b.Abbreviation, b.SongCount AS cached,
            COALESCE(s.live_count, 0) AS live
       FROM tblSongbooks b
       LEFT JOIN (
            SELECT SongbookAbbr, COUNT(*) AS live_count
              FROM tblSongs
             GROUP BY SongbookAbbr
       ) s ON s.SongbookAbbr = b.Abbreviation
      ORDER BY b.Abbreviation"
);
if (!$res) {
    _migRecountSb_out('ERROR: SELECT failed: ' . $db->error);
    exit(1);
}

$rows = $res->fetch_all(MYSQLI_ASSOC);
$res->close();

$updateStmt = $db->prepare('UPDATE tblSongbooks SET SongCount = ? WHERE Id = ?');
$drifted = 0;
$correct = 0;
$totalLive = 0;

foreach ($rows as $r) {
    $cached = (int)$r['cached'];
    $live   = (int)$r['live'];
    $totalLive += $live;
    if ($cached === $live) {
        $correct++;
        continue;
    }
    $id = (int)$r['Id'];
    $updateStmt->bind_param('ii', $live, $id);
    $updateStmt->execute();
    $delta = $live - $cached;
    $sign  = $delta > 0 ? '+' : '';
    _migRecountSb_out(sprintf(
        '  [fix] %-12s cached=%d  live=%d  (%s%d)',
        $r['Abbreviation'], $cached, $live, $sign, $delta
    ));
    $drifted++;
}
$updateStmt->close();

_migRecountSb_out("");
_migRecountSb_out("Summary: {$correct} songbook(s) already correct, {$drifted} re-counted; {$totalLive} song row(s) total across the catalogue.");
_migRecountSb_out('Songbook SongCount recompute finished (#791).');
