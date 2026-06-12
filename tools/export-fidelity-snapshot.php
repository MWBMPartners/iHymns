<?php

declare(strict_types=1);

/**
 * iHymns — #1235 P4 export-fidelity snapshot (C3 companion)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Captures a per-song manifest of the ASSEMBLED component output — the single shape
 * every export surface (ProPresenter PP7 / OpenLyrics / LRC / TTML / songbook_export /
 * the song page / PDF) derives from via lyricLinesAssembleComponents(). If that shape
 * is byte-frozen across the JSON-column drop, every downstream export is too — so a
 * pre-drop manifest compared byte-for-byte to a post-drop manifest is the fidelity gate
 * (the verify tool proves assembler==source; this proves assembler==assembler across
 * the cutover).
 *
 *   php tools/export-fidelity-snapshot.php --out=/tmp/lyrics-pre.json     # capture baseline (Gate A/C)
 *   php tools/export-fidelity-snapshot.php --compare=/tmp/lyrics-pre.json # diff live vs baseline (Gate D)
 *
 * Options:
 *   --out=PATH       write the manifest to PATH
 *   --compare=PATH   load PATH and report any song whose assembled hash changed
 *   --limit=N        first N songs (smoke / dev)
 *   --sample=PATH    restrict to the song ids in a JSON array file (the committed
 *                    ~380-song coverage sample); default = whole corpus
 *
 * Exit 0 = captured, or compared with NO drift; 1 = drift found; 2 = setup error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/appWeb/public_html/includes/db_mysql.php';
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
foreach ([
    ($docRoot !== '' ? $docRoot . '/includes/lyric_lines_read.php' : null),
    dirname(__DIR__) . '/appWeb/public_html/includes/lyric_lines_read.php',
] as $cand) {
    if ($cand !== null && is_file($cand)) { require_once $cand; break; }
}
if (!function_exists('lyricLinesAssembleComponents')) {
    fwrite(STDERR, "ERROR: could not load includes/lyric_lines_read.php\n");
    exit(2);
}

$opt     = getopt('', ['out:', 'compare:', 'limit:', 'sample:']);
$outPath = isset($opt['out']) ? (string)$opt['out'] : null;
$cmpPath = isset($opt['compare']) ? (string)$opt['compare'] : null;
$limit   = isset($opt['limit']) ? max(0, (int)$opt['limit']) : 0;
$sample  = isset($opt['sample']) ? (string)$opt['sample'] : null;
if ($outPath === null && $cmpPath === null) {
    fwrite(STDERR, "ERROR: pass --out=PATH or --compare=PATH\n");
    exit(2);
}

$db = getDbMysqli();
if (!($db instanceof mysqli)) { fwrite(STDERR, "ERROR: no DB.\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Resolve the song id list. */
$songIds = [];
if ($sample !== null) {
    $raw = is_file($sample) ? json_decode((string)file_get_contents($sample), true) : null;
    if (!is_array($raw)) { fwrite(STDERR, "ERROR: --sample file is not a JSON array of song ids\n"); exit(2); }
    $songIds = array_values(array_map('strval', $raw));
} else {
    $sql = "SELECT SongId FROM tblSongs ORDER BY SongId" . ($limit > 0 ? " LIMIT {$limit}" : "");
    $res = $db->query($sql);
    while ($row = $res->fetch_row()) { $songIds[] = (string)$row[0]; }
    $res->close();
}

/* Hash each song's assembled output. JSON_UNESCAPED_UNICODE so the bytes are the real
   UTF-8 (a code-point-stable serialisation), and the key order is the assembler's. */
$manifest = [];
foreach ($songIds as $songId) {
    $assembled = lyricLinesAssembleComponents($db, $songId);
    $manifest[$songId] = hash('sha256', json_encode($assembled, JSON_UNESCAPED_UNICODE));
}
$corpusSha = hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE));

if ($outPath !== null) {
    /* No wall-clock stamp baked in (kept reproducible); the caller dates the filename. */
    $doc = ['songCount' => count($manifest), 'corpusSha256' => $corpusSha, 'songs' => $manifest];
    if (file_put_contents($outPath, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        fwrite(STDERR, "ERROR: could not write {$outPath}\n");
        exit(2);
    }
    fwrite(STDOUT, "Wrote manifest: {$outPath}  (" . count($manifest) . " songs, corpus " . substr($corpusSha, 0, 12) . "…)\n");
    exit(0);
}

/* Compare mode. */
$prior = is_file($cmpPath) ? json_decode((string)file_get_contents($cmpPath), true) : null;
if (!is_array($prior) || !isset($prior['songs']) || !is_array($prior['songs'])) {
    fwrite(STDERR, "ERROR: --compare file is not a valid manifest\n");
    exit(2);
}
$priorSongs = $prior['songs'];
$changed = []; $missing = []; $added = [];
foreach ($priorSongs as $sid => $hash) {
    if (!array_key_exists($sid, $manifest)) { $missing[] = $sid; continue; }
    if ($manifest[$sid] !== $hash) { $changed[] = $sid; }
}
foreach ($manifest as $sid => $hash) {
    if (!array_key_exists($sid, $priorSongs)) { $added[] = $sid; }
}

$drift = (!empty($changed) || !empty($missing));   // added songs are not a fidelity failure
fwrite(STDOUT, sprintf(
    "Compared %d songs vs baseline %s: %d changed, %d missing, %d added.\n",
    count($manifest), basename($cmpPath), count($changed), count($missing), count($added)
));
foreach (array_slice($changed, 0, 30) as $sid) { fwrite(STDOUT, "  CHANGED  {$sid}\n"); }
foreach (array_slice($missing, 0, 30) as $sid) { fwrite(STDOUT, "  MISSING  {$sid}\n"); }
if ($corpusSha === ($prior['corpusSha256'] ?? null)) {
    fwrite(STDOUT, "  corpus SHA matches baseline — export inputs are byte-frozen.\n");
}

exit($drift ? 1 : 0);
