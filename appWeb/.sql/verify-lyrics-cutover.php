<?php

declare(strict_types=1);

/**
 * iHymns — #1235 P4 lyrics-cutover verification gate (C3)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Proves — against the LIVE corpus — that making tblLyricLines authoritative and
 * retiring the tblSongComponents JSON payload columns loses NO data, and gates the
 * irreversible DROP behind that proof. Run at each phase of the cutover:
 *
 * Web (the owner's path — Global Admin → /manage/setup-database → "Verify cutover gate"):
 *   ?action=verify-cutover&phase=pre|soak|pre-drop|post-drop  (web-only DreamHost; no CLI)
 * CLI (CI / the local dress rehearsal):
 *   php appWeb/.sql/verify-lyrics-cutover.php --phase=pre        # before the read switch (Gate A)
 *   php appWeb/.sql/verify-lyrics-cutover.php --phase=soak       # during the soak
 *   php appWeb/.sql/verify-lyrics-cutover.php --phase=pre-drop   # inside the freeze, just before the drop (Gate C)
 *   php appWeb/.sql/verify-lyrics-cutover.php --phase=post-drop  # after the drop (Gate D)
 *
 * Options (CLI only):
 *   --limit=N        only the first N songs (smoke / dev)
 *   --emit-samples   print up to 20 example divergences per failing gate
 *   --json           machine-readable summary on stdout (NDJSON failures to stderr)
 *
 * The WEB path always emits samples (there is no flag to turn them off). It used to
 * hardcode $emitSamples = false, which made a RED run undiagnosable on the ONE
 * environment this script actually runs in: DreamHost is web-only, so the operator
 * got "[FAIL] G1 … (1 fail)" and no way to learn WHICH song — the samples were
 * collected and then discarded unprinted, and the NDJSON stream that carries the
 * same detail is fwrite(STDERR) which is undefined under PHP-FPM. Both diagnostic
 * channels were switched off precisely where they were needed (#1654).
 *
 * On an ALL-GREEN run it writes the sentinel row tblAppSettings['lyrics_cutover_gate']
 * = {phase, ranAt, result:'green', fingerprint:{songs,components,lines,corpusSha256}}.
 * The drop migration REFUSES to run unless that sentinel says phase=pre-drop/green and
 * the fingerprint still matches live counts (the gate is code, not process discipline).
 *
 * The losslessness centrepiece is G2: for every song, the components assembled from
 * tblLyricLines (lyric_lines_read.php — the post-cutover read) are compared field-by-
 * field, byte-for-byte (NFC-normalised, code-point aware) to the components built from
 * the authoritative tblSongComponents.LinesJson (the pre-cutover source). Equal across
 * the whole corpus ⇒ dropping the JSON loses nothing.
 *
 * Exit 0 = all gates for the phase passed; 1 = at least one failed; 2 = setup error.
 */

/* ── Execution mode ──────────────────────────────────────────────────────────
 * DUAL-MODE: a CLI tool (CI / the local dress rehearsal) AND a web runner
 * included by /manage/setup-database. The owner operates web-only on shared
 * DreamHost (no CLI), so the cutover gate MUST be runnable from the dashboard;
 * the dashboard defines IHYMNS_SETUP_DASHBOARD and passes the phase via ?phase=.
 * In web mode we echo (HTML-escaped) and `return` from the included script
 * instead of writing STDOUT/STDERR + exit() — STDOUT/STDERR are undefined under
 * PHP-FPM (fatal) and exit() would truncate the dashboard. The ELEVEN gates
 * below are IDENTICAL in both modes; only this wrapper differs.
 *
 * The eleven are G1, G2, G3, G4, G5, G6, G7, G8, G9, G10, G12 — the numbering
 * has holes, and the holes are NOT all benign. The design pass (.claude/
 * lyrics-normalisation-strategy.md §11, table at L516-524) specifies thirteen.
 * Where the other two actually went, verified rather than assumed (#1615):
 *
 *   G11 (corpus fingerprint frozen across the drop) — IMPLEMENTED, but split
 *        across two files by design: this script WRITES the fingerprint (incl.
 *        corpusSha256) into the sentinel; migrate-retire-component-lines-json.php
 *        Stage 0(b) re-checks it still matches live counts at drop time. A
 *        single-process gate here could not span the drop, so this is correct.
 *   G13 (byte/semantic parity on all projected cols incl. ChordsJson) —
 *        SUBSUMED by G2, whose comparison already covers ChordsJson.
 *
 * #1618 closed the remaining two gaps:
 *
 *   G4 (design doc's G4′: ArrangementJson arrays index valid ordinals 0..n-1;
 *       SortOrder stays contiguous) — NOW IMPLEMENTED. The SortOrder-contiguity
 *       half was always G8; this gate covers the other half — every value in
 *       tblSongs.ArrangementJson (#892) and every tblSongArrangements
 *       .ComponentOrderJson row (#1066 Theme E) must be a non-negative int
 *       < that song's component count. Runs every phase (both columns are
 *       untouched by the P4/C6 drop). Duplicates are legal by design (a
 *       repeated ordinal replays a component — schema.sql's own example is
 *       [0,1,1,2,3]) so this checks range only, never uniqueness or coverage.
 *   G12 (live Id-stability smoke: 3 sentinel songs, double re-projection,
 *        identical Id sets) — NOW IMPLEMENTED, but ONLY under --phase=soak
 *        (an operator step that must be remembered is exactly the failure
 *        class this closes — see #1618). It is READ-ONLY: two independent
 *        calls to the shared lyricLinesAssembleComponents() read path
 *        (lyric_lines_read.php, rule #25) for 3 deterministically-picked
 *        songs (most mirrored lines, SongId tiebreak — never random), diffed
 *        on the flattened tblLyricLines.Id set. This proves the READ
 *        projection is stable/repeatable; it does NOT prove the WRITE path's
 *        Id-preserving diff (lyricLinesWriteComponents →
 *        lyricLinesApplyDesired) survives a re-save of unchanged content —
 *        that would mean invoking the write path, which a read-only verifier
 *        must not do. A true write-path proof needs either a disposable
 *        staging DB this gate could safely mutate + roll back, or the write
 *        path exposing a pure dry-run (desired-vs-existing diff without
 *        executing it); neither exists today.
 *
 * So an all-green run — including a --phase=soak run for G12 — is now the
 * eleven-gate proof the design doc's thirteen rows collapse to once G11's
 * split and G13's subsumption are accounted for. A pre/pre-drop/post-drop run
 * skips G12 and says so; it is not part of those phases' green. Do NOT
 * renumber the survivors — design-doc citations must keep resolving. */
$LCV_WEB = (PHP_SAPI !== 'cli') && defined('IHYMNS_SETUP_DASHBOARD');

/* Direct web hits (no dashboard context) stay blocked. */
if (PHP_SAPI !== 'cli' && !$LCV_WEB) {
    http_response_code(403);
    exit("CLI only.\n");
}

/** Emit one report line: STDOUT in CLI, an HTML-escaped <br> line in the dashboard. */
function out(string $s): void {
    global $LCV_WEB;
    if ($LCV_WEB) { echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . "<br>\n"; }
    else { fwrite(STDOUT, $s . "\n"); }
}
/** Report a setup error (STDERR in CLI, escaped line in the dashboard). */
function lcv_err(string $m): void {
    global $LCV_WEB;
    if ($LCV_WEB) { echo '<strong>ERROR:</strong> ' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . "<br>\n"; }
    else { fwrite(STDERR, "ERROR: {$m}\n"); }
}

/* ext-intl is REQUIRED — Tier-2 NFC classification needs Normalizer (a byte mismatch
   that is only a Unicode normalisation difference is still a FAIL, but we must be able
   to label it so a curator knows it's NFD-vs-NFC, not corruption). */
if (!class_exists(\Normalizer::class)) {
    lcv_err('ext-intl (Normalizer) is required for the cutover gate.');
    if ($LCV_WEB) { return; }
    exit(2);
}

/* DB layer + the shared line-first assembler. DOCUMENT_ROOT-first (set in web
   mode) so the live web-root includes are used; the .sql-sibling path is the CLI
   fallback. db_mysql is already loaded when included by the dashboard. */
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if (!function_exists('getDbMysqli')) {
    foreach ([
        ($docRoot !== '' ? $docRoot . '/includes/db_mysql.php' : null),
        dirname(__DIR__) . '/public_html/includes/db_mysql.php',
    ] as $cand) {
        if ($cand !== null && is_file($cand)) { require_once $cand; break; }
    }
}
foreach ([
    ($docRoot !== '' ? $docRoot . '/includes/lyric_lines_read.php' : null),
    dirname(__DIR__) . '/public_html/includes/lyric_lines_read.php',
] as $cand) {
    if ($cand !== null && is_file($cand)) { require_once $cand; break; }
}
/* #1627 — the shared ArrangementJson rule that gate G4 below now applies, so the
   gate and the write side (includes/song_importers.php::_sanitiseArrangement)
   cannot disagree about which ordinals are valid. Same DOCUMENT_ROOT-first
   resolution as the assembler above, and REQUIRED rather than best-effort: G4
   calls arrangementViolations() unconditionally, so a missing file would fatal
   mid-run instead of failing the guard cleanly here. */
foreach ([
    ($docRoot !== '' ? $docRoot . '/includes/arrangement.php' : null),
    dirname(__DIR__) . '/public_html/includes/arrangement.php',
] as $cand) {
    if ($cand !== null && is_file($cand)) { require_once $cand; break; }
}
if (!function_exists('lyricLinesAssembleComponents') || !function_exists('getDbMysqli')
    || !function_exists('arrangementViolations')) {
    lcv_err('could not load includes/db_mysql.php, includes/lyric_lines_read.php or includes/arrangement.php');
    if ($LCV_WEB) { return; }
    exit(2);
}

/* Activity Log (#1282) — OPTIONAL/best-effort: every run (CLI cron + web) leaves a
   queryable row so a RED soak surfaces in /manage/activity-log without trawling cron
   email. Already loaded by the dashboard (via auth.php); load it for the CLI path. A
   missing activity_log.php must NOT abort the gate, so this is not in the guard above. */
if (!function_exists('logActivity')) {
    foreach ([
        ($docRoot !== '' ? $docRoot . '/includes/activity_log.php' : null),
        dirname(__DIR__) . '/public_html/includes/activity_log.php',
    ] as $cand) {
        if ($cand !== null && is_file($cand)) { require_once $cand; break; }
    }
}

/* ---- args ---- (CLI: getopt; web: ?phase= + optional ?limit= only) */
if ($LCV_WEB) {
    $phase       = (string)($_GET['phase'] ?? 'pre');
    /* Optional smoke limit (capped): a fast first-N-songs dry-run so the operator
       can confirm the web path works before the slow full-corpus run. limit>0 NEVER
       writes the sentinel (see the report tail), so a smoke run can't arm the drop. */
    $limit       = isset($_GET['limit']) ? max(0, min(5000, (int)$_GET['limit'])) : 0;
    /* ALWAYS ON, and deliberately not a flag (#1654).
       ELI5: if the check fails, it has to tell you which song — otherwise "it failed"
       is all you ever get, and you cannot fix it.
       Detail: this is the operator's ONLY path (DreamHost is web-only; the header's
       CLI invocations are for CI and the local rehearsal). gfail() already caps
       retained samples at 20 PER GATE, so the cost is bounded no matter how badly
       the corpus fails — it is the printing that was missing, not the collecting.
       Output is HTML-escaped by out() and the page is Global-Admin-only, so lyric
       excerpts in a G2 cpDiff are no wider an exposure than the song editor. */
    $emitSamples = true;
    $asJson      = false;
} else {
    $opt         = getopt('', ['phase:', 'limit:', 'emit-samples', 'json']);
    $phase       = (string)($opt['phase'] ?? 'pre');
    $limit       = isset($opt['limit']) ? max(0, (int)$opt['limit']) : 0;
    $emitSamples = array_key_exists('emit-samples', $opt);
    $asJson      = array_key_exists('json', $opt);
}
if (!in_array($phase, ['pre', 'soak', 'pre-drop', 'post-drop'], true)) {
    lcv_err('phase must be pre | soak | pre-drop | post-drop');
    if ($LCV_WEB) { return; }
    exit(2);
}

$db = getDbMysqli();
if (!($db instanceof mysqli)) {
    lcv_err('no DB.');
    if ($LCV_WEB) { return; }
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$vcStart = microtime(true);   /* for the Activity-Log durationMs (#1282) */

/* ---- output helpers ---- */
$failuresNdjson = [];   // collected NDJSON lines (CLI stderr only)
function nfc(string $s): string { $n = \Normalizer::normalize($s, \Normalizer::FORM_C); return $n === false ? $s : $n; }

/** First differing code-point index + a short context window, for a readable report. */
function cpDiff(string $a, string $b): array
{
    $ca = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $cb = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n  = min(count($ca), count($cb));
    for ($i = 0; $i < $n; $i++) {
        if ($ca[$i] !== $cb[$i]) {
            $w = static fn(array $c) => implode('', array_slice($c, max(0, $i - 8), 20));
            return ['at' => $i, 'a' => $w($ca), 'b' => $w($cb)];
        }
    }
    return ['at' => $n, 'a' => implode('', array_slice($ca, max(0, $n - 8), 20)), 'b' => implode('', array_slice($cb, max(0, $n - 8), 20))];
}

/**
 * Reconstruct a song's components from the AUTHORITATIVE tblSongComponents (the
 * pre-cutover source) — the comparable subset of the read shape (type/number/lines/
 * chords/language). Mirror-only fields (lineIds/lineLanguages) are excluded; this is
 * the LinesJson ground truth the assembler must reproduce.
 *
 * @return list<array{type:string,number:int,lines:list<string>,chords:?array,language:?string}>
 */
function sourceComponentsFromJson(\mysqli $db, string $songId): array
{
    /* Probe the optional columns so this also runs post-drop (where it returns [] and
       G2 is skipped — there is no JSON source left to compare against). */
    static $hasCols = null;
    if ($hasCols === null) {
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongComponents'
                AND COLUMN_NAME IN ('LinesJson','ChordsJson','Language')"
        );
        $row = $r ? $r->fetch_row() : null;
        $hasCols = ($row !== null && (int)$row[0] >= 1 && _vcColExists($db, 'tblSongComponents', 'LinesJson'));
        if ($r) { $r->close(); }
    }
    if (!$hasCols) { return []; }

    $chordsSel = _vcColExists($db, 'tblSongComponents', 'ChordsJson') ? 'ChordsJson' : 'NULL AS ChordsJson';
    $langSel   = _vcColExists($db, 'tblSongComponents', 'Language')   ? 'Language'   : 'NULL AS Language';
    $stmt = $db->prepare(
        "SELECT Type, Number, LinesJson, {$chordsSel}, {$langSel}
           FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder, Id"
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($rows as $r) {
        $lines  = json_decode((string)$r['LinesJson'], true);
        if (!is_array($lines)) { $lines = []; }
        $chords = ($r['ChordsJson'] !== null) ? (json_decode((string)$r['ChordsJson'], true) ?: null) : null;
        $out[] = [
            'type'     => (string)$r['Type'],
            'number'   => (int)$r['Number'],
            'lines'    => array_map('strval', $lines),
            'chords'   => $chords,
            'language' => ($r['Language'] !== null && $r['Language'] !== '') ? (string)$r['Language'] : null,
        ];
    }
    return $out;
}

/** Memoised column-existence probe. */
function _vcColExists(\mysqli $db, string $table, string $col): bool
{
    static $cache = [];
    $k = $table . '.' . $col;
    if (isset($cache[$k])) { return $cache[$k]; }
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($table) . "'
            AND COLUMN_NAME = '" . $db->real_escape_string($col) . "' LIMIT 1"
    );
    $cache[$k] = $r && $r->fetch_row() !== null;
    if ($r) { $r->close(); }
    return $cache[$k];
}

/* Strip the mirror-only additive keys so G2 compares the LinesJson-equivalent subset. */
function comparableComponents(array $assembled): array
{
    return array_map(static fn(array $c) => [
        'type'     => $c['type'],
        'number'   => $c['number'],
        'lines'    => $c['lines'],
        'chords'   => $c['chords'],
        'language' => $c['language'],
    ], $assembled);
}

/* ====================================================================== */
/* Gate accumulators                                                       */
/* ====================================================================== */
$gates = [];   // id => ['desc'=>, 'pass'=>bool, 'fails'=>int, 'samples'=>[]]
function gate(string $id, string $desc): void { global $gates; $gates[$id] = ['desc' => $desc, 'pass' => true, 'fails' => 0, 'samples' => []]; }
function gfail(string $id, array $detail): void
{
    global $gates, $failuresNdjson, $LCV_WEB;
    $gates[$id]['pass'] = false;
    $gates[$id]['fails']++;
    if (count($gates[$id]['samples']) < 20) { $gates[$id]['samples'][] = $detail; }
    /* NDJSON is the CLI's failure stream (fwrite(STDERR) at the report tail, guarded
       by !$LCV_WEB because STDERR is undefined under PHP-FPM). Accumulating it on the
       web therefore builds an array that is only ever discarded — and it is the ONE
       unbounded structure here (samples cap at 20, this does not). A corpus-wide G2
       failure would add ~292k json_encode'd entries for no reader, in a codebase that
       has already OOM'd once on whole-corpus memory (#929). (#1654) */
    if (!$LCV_WEB) {
        $failuresNdjson[] = json_encode(['gate' => $id] + $detail, JSON_UNESCAPED_UNICODE);
    }
}

out("== #1235 P4 lyrics-cutover gate — phase={$phase} ==");

/* ---------------------------------------------------------------------- */
/* Corpus-level gates (one query each)                                     */
/* ---------------------------------------------------------------------- */

/* G7 — point-in-time invariants the cutover assumes (never schema constraints). */
gate('G7', '1:1 song:lyrics for Source=ihymns; no song with >1 ihymns version');
$r = $db->query(
    "SELECT SongId, COUNT(*) c FROM tblLyrics WHERE Source='ihymns' GROUP BY SongId HAVING c > 1 LIMIT 50"
);
while ($row = $r->fetch_assoc()) { gfail('G7', ['songId' => $row['SongId'], 'versions' => (int)$row['c']]); }
$r->close();

/* G1/G8/G9 — every mirrored line has a ComponentId; runs are contiguous; group order
   ≡ component SortOrder. Done with one ordered pass per song below (cheap), but the
   global "0 NULL ComponentId" is a single query. */
gate('G9', '0 NULL ComponentId on mirrored lines (the post-drop grouping anchor)');
$r = $db->query(
    "SELECT COUNT(*) FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id=ll.LyricsId
      WHERE ly.Source='ihymns' AND ll.ComponentId IS NULL"
);
$nullCid = (int)$r->fetch_row()[0]; $r->close();
if ($nullCid > 0) { gfail('G9', ['nullComponentId' => $nullCid]); }

/* G5 — every line whose PartType maps to a known slug has the slug set. */
gate('G5', 'PartTypeSlug backfilled for all mappable lines (#1138)');
if (_vcColExists($db, 'tblLyricLines', 'PartTypeSlug')) {
    $r = $db->query(
        "SELECT COUNT(*) FROM tblLyricLines ll
           JOIN tblSongPartTypes pt ON pt.Slug = LOWER(ll.PartType)
          WHERE ll.PartTypeSlug IS NULL"
    );
    $nullSlug = (int)$r->fetch_row()[0]; $r->close();
    if ($nullSlug > 0) { gfail('G5', ['mappableButNull' => $nullSlug]); }
}

/* G6 — enrichment row counts of every line-FK table (snapshot for cross-phase delta;
   0 orphan line references). */
gate('G6', 'enrichment counts captured; 0 orphan line refs');
$enrichCounts = [];
foreach ([
    'tblLyricLineTranslations'  => 'LineId',
    'tblLyricLineAnnotations'   => 'StartLineId',
    'tblLyricLineVocalParts'    => 'LineId',
    'tblLyricWords'             => 'LineId',
] as $tbl => $fk) {
    if (!_vcTableExists($db, $tbl)) { $enrichCounts[$tbl] = null; continue; }
    $r = $db->query("SELECT COUNT(*) FROM `{$tbl}`"); $enrichCounts[$tbl] = (int)$r->fetch_row()[0]; $r->close();
    $r = $db->query("SELECT COUNT(*) FROM `{$tbl}` t LEFT JOIN tblLyricLines ll ON ll.Id = t.`{$fk}` WHERE ll.Id IS NULL");
    $orphan = (int)$r->fetch_row()[0]; $r->close();
    if ($orphan > 0) { gfail('G6', ['table' => $tbl, 'orphans' => $orphan]); }
}

function _vcTableExists(\mysqli $db, string $t): bool
{
    static $c = [];
    if (isset($c[$t])) { return $c[$t]; }
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($t) . "' LIMIT 1");
    $c[$t] = $r && $r->fetch_row() !== null; if ($r) { $r->close(); }
    return $c[$t];
}

/* ---------------------------------------------------------------------- */
/* Per-song gates (chunked per songbook so memory stays flat — #929)       */
/* ---------------------------------------------------------------------- */
gate('G1', 'per-component LinesJson length == mirror line count');
gate('G2', 'assembler output (from lines) byte-identical to LinesJson source');
gate('G3', 'blank lines map 1:1 to IsInstrumental; whitespace vs empty preserved');
gate('G4', "ArrangementJson/ComponentOrderJson ordinals are valid indices into that song's components[] (0..n-1); SortOrder contiguity is G8, not this gate");
gate('G8', 'ComponentId runs contiguous; group order ≡ component SortOrder');
gate('G10', 'per-line LanguageCode consistent (no spurious overrides emitted)');

/* G4 prep (#1618) — two homes hold a component-order array (design doc §11.3 D-4,
   still ⚖ under owner review transport-vs-storage): tblSongs.ArrangementJson (#892,
   optional single override) and tblSongArrangements.ComponentOrderJson (#1066 Theme
   E, named/multiple arrangements per song; an IsDefault=1 row there wins over
   ArrangementJson — soft-deprecation, no backfill, no drop). Both hold a JSON array
   of 0-based indices into that song's ordered components[]; a repeated index is BY
   DESIGN (it replays a component, e.g. a refrain between verses — schema.sql's own
   doc-comment example is [0,1,1,2,3]), so this gate checks range validity only,
   never uniqueness or full coverage.
   Prefetched WHOLE, not chunked per songbook like the per-song loop below: both
   sources are near-empty by design (13 songs total across both, per the #1618
   design-doc audit), so holding every row in memory is nothing like the #929
   whole-corpus-LYRICS risk that chunking guards against — chunking this instead
   would just trade a few KB of memory for an N+1 query against ~16k songs that
   almost never have a row here. Column/table existence is probed first (both are
   gated migrations — #892 / #1066 — so an un-migrated install has neither). */
$g4ArrangementsBySong = [];   // SongId => list of ['source' => label, 'raw' => JSON string]
if (_vcColExists($db, 'tblSongs', 'ArrangementJson')) {
    $r = $db->query("SELECT SongId, ArrangementJson FROM tblSongs WHERE ArrangementJson IS NOT NULL");
    while ($row = $r->fetch_assoc()) {
        $g4ArrangementsBySong[(string)$row['SongId']][] = ['source' => 'tblSongs.ArrangementJson', 'raw' => $row['ArrangementJson']];
    }
    $r->close();
}
if (_vcTableExists($db, 'tblSongArrangements')) {
    $r = $db->query("SELECT SongId, Id, Name, ComponentOrderJson FROM tblSongArrangements");
    while ($row = $r->fetch_assoc()) {
        $g4ArrangementsBySong[(string)$row['SongId']][] = [
            'source' => "tblSongArrangements#{$row['Id']} ({$row['Name']})",
            'raw'    => $row['ComponentOrderJson'],
        ];
    }
    $r->close();
}

$songSql = "SELECT SongId FROM tblSongs ORDER BY SongId" . ($limit > 0 ? " LIMIT {$limit}" : "");
$res = $db->query($songSql);
$songIds = [];
while ($row = $res->fetch_row()) { $songIds[] = (string)$row[0]; }
$res->close();

$nSongs = count($songIds);
$totalLines = 0; $totalComponents = 0; $corpusHash = hash_init('sha256');
$done = 0;

foreach ($songIds as $songId) {
    $assembled = lyricLinesAssembleComponents($db, $songId);
    $source    = sourceComponentsFromJson($db, $songId);

    $totalComponents += count($assembled);
    foreach ($assembled as $c) { $totalLines += count($c['lines']); }

    /* Feed a stable per-song serialisation into the corpus fingerprint. */
    hash_update($corpusHash, $songId . "\x1e" . json_encode($assembled, JSON_UNESCAPED_UNICODE));

    /* G4 — ordinal validity against THIS song's assembled component count (#1618).
       Runs every phase (both source columns are outside the P4/C6 drop's scope). */
    if (isset($g4ArrangementsBySong[$songId])) {
        $g4N = count($assembled);
        foreach ($g4ArrangementsBySong[$songId] as $g4Arr) {
            /* #1627 — the ordinal rule now comes from includes/arrangement.php,
               the SAME function the write side uses (via _sanitiseArrangement).
               ELI5: the gate and the editor now read from one rulebook.
               Detail: this loop and _sanitiseArrangement() were independent
               implementations of the same bounds. Had they drifted — the writer
               looser than the gate — the editor would have manufactured its own
               blocker: curators persisting arrangements this gate rejects, the
               #1618 verification going red on real curator data, and the
               destructive C6 drop that epic #1601 exists to unblock staying
               blocked, months later, with nothing pointing back to the cause.
               The per-ordinal gfail payloads below are UNCHANGED — that is why
               the shared helper returns violations rather than a bool. */
            $g4Ords = json_decode((string)$g4Arr['raw'], true);
            foreach (arrangementViolations($g4Ords, $g4N) as $g4V) {
                if ($g4V['reason'] === 'malformed') {
                    gfail('G4', ['songId' => $songId, 'source' => $g4Arr['source'], 'reason' => 'malformed JSON (not an array)']);
                    continue;
                }
                gfail('G4', ['songId' => $songId, 'source' => $g4Arr['source'], 'position' => $g4V['position'], 'value' => $g4V['value'], 'componentCount' => $g4N]);
            }
        }
    }

    /* G2 + G1 — only when a JSON source still exists (skipped post-drop). */
    if (!empty($source) || $phase !== 'post-drop') {
        $cmpNew = comparableComponents($assembled);
        if (!empty($source)) {
            if (count($cmpNew) !== count($source)) {
                gfail('G1', ['songId' => $songId, 'assembledComponents' => count($cmpNew), 'sourceComponents' => count($source)]);
            } else {
                foreach ($source as $i => $sc) {
                    $ac = $cmpNew[$i];
                    if (count($ac['lines']) !== count($sc['lines'])) {
                        gfail('G1', ['songId' => $songId, 'component' => $i, 'assembledLines' => count($ac['lines']), 'sourceLines' => count($sc['lines'])]);
                        continue;
                    }
                    /* G2 byte-identity, field by field. */
                    if ($ac['type'] !== $sc['type'] || $ac['number'] !== $sc['number'] || $ac['language'] !== $sc['language'] || $ac['chords'] !== $sc['chords']) {
                        gfail('G2', ['songId' => $songId, 'component' => $i, 'kind' => 'meta',
                            'assembled' => ['type' => $ac['type'], 'number' => $ac['number'], 'language' => $ac['language']],
                            'source'    => ['type' => $sc['type'], 'number' => $sc['number'], 'language' => $sc['language']]]);
                    }
                    foreach ($sc['lines'] as $li => $sLine) {
                        $aLine = $ac['lines'][$li];
                        if ($aLine !== $sLine) {
                            $tier = (nfc($aLine) === nfc($sLine)) ? 'NORMALISATION-DRIFT' : 'TEXT-MISMATCH';
                            $detail = ['songId' => $songId, 'component' => $i, 'line' => $li, 'tier' => $tier];
                            if ($GLOBALS['emitSamples']) { $detail['diff'] = cpDiff($sLine, $aLine); }
                            gfail('G2', $detail);
                        }
                    }
                }
            }
        }
    }

    /* G3 — blank/whitespace lines: a blank assembled line must be marked instrumental
       in the mirror (and vice-versa). Checked from the mirror directly. */
    /* (the per-line IsInstrumental check is a single grouped query below, not per song,
        to keep this loop lean — see G3q.) */

    if ((++$done % 2000) === 0 && !$asJson) { out("  …{$done}/{$nSongs} songs"); }
}

/* G3 (corpus) — blank LineText ⇔ IsInstrumental=1 bijection. */
$r = $db->query(
    "SELECT
        SUM(CASE WHEN TRIM(ll.LineText)='' AND ll.IsInstrumental=0 THEN 1 ELSE 0 END) AS blank_not_inst,
        SUM(CASE WHEN TRIM(ll.LineText)<>'' AND ll.IsInstrumental=1 THEN 1 ELSE 0 END) AS nonblank_inst
       FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id=ll.LyricsId
      WHERE ly.Source='ihymns'"
);
$b = $r->fetch_assoc(); $r->close();
if ((int)$b['blank_not_inst'] > 0 || (int)$b['nonblank_inst'] > 0) {
    gfail('G3', ['blankNotInstrumental' => (int)$b['blank_not_inst'], 'nonblankInstrumental' => (int)$b['nonblank_inst']]);
}

/* G8 (corpus) — ComponentId runs contiguous + group order matches SortOrder. A run is
   non-contiguous if a ComponentId reappears after a different one in SortOrder. */
$r = $db->query(
    "SELECT ly.SongId, ll.ComponentId, ll.SortOrder
       FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id=ll.LyricsId
      WHERE ly.Source='ihymns' AND ll.ComponentId IS NOT NULL
      ORDER BY ly.SongId, ll.SortOrder, ll.Id"
);
$seen = []; $prevSong = null; $prevCid = null;
while ($row = $r->fetch_assoc()) {
    $sid = (string)$row['SongId']; $cid = (int)$row['ComponentId'];
    if ($sid !== $prevSong) { $seen = []; $prevCid = null; $prevSong = $sid; }
    if ($cid !== $prevCid) {
        if (isset($seen[$cid])) { gfail('G8', ['songId' => $sid, 'componentId' => $cid, 'reason' => 'non-contiguous run']); }
        $seen[$cid] = true; $prevCid = $cid;
    }
}
$r->close();

/* ---------------------------------------------------------------------- */
/* G12 — live Id-stability smoke, --phase=soak ONLY (#1618).               */
/* ---------------------------------------------------------------------- */
if ($phase === 'soak') {
    gate('G12', 'Id-stability smoke: 3 sentinel songs, double re-projection, identical tblLyricLines.Id sets');

    /* Deterministic pick — NEVER random (design doc §11.5; an irreproducible failure
       is worse than no check at all). "Most mirrored lines" both avoids an edge-case
       empty/trivial song and stress-tests the ComponentId-grouping the assembler does
       most; the SongId tiebreak means the same 3 songs are picked on every run. */
    $g12Sentinels = [];
    $r = $db->query(
        "SELECT ly.SongId, COUNT(*) AS lineCount
           FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ly.Source = 'ihymns'
          GROUP BY ly.SongId
          ORDER BY lineCount DESC, ly.SongId ASC
          LIMIT 3"
    );
    while ($row = $r->fetch_assoc()) { $g12Sentinels[] = (string)$row['SongId']; }
    $r->close();

    if (empty($g12Sentinels)) {
        gfail('G12', ['reason' => 'no songs with mirrored lines to sample — un-migrated install?']);
    }

    /** Flatten an assembled components[] array to its sorted tblLyricLines.Id list. */
    $g12IdsOf = static function (array $components): array {
        $ids = [];
        foreach ($components as $c) {
            foreach (($c['lineIds'] ?? []) as $lid) { $ids[] = (int)$lid; }
        }
        sort($ids);
        return $ids;
    };

    foreach ($g12Sentinels as $g12SongId) {
        /* Read-only DOUBLE re-projection — two independent calls through the ONE
           shared read path (lyricLinesAssembleComponents(), rule #25), NO write of
           any kind. See the header block for what this does and does not prove. */
        $g12IdsFirst  = $g12IdsOf(lyricLinesAssembleComponents($db, $g12SongId));
        $g12IdsSecond = $g12IdsOf(lyricLinesAssembleComponents($db, $g12SongId));

        if (empty($g12IdsFirst)) {
            gfail('G12', ['songId' => $g12SongId, 'reason' => 'sentinel song re-projected to 0 lines']);
        } elseif ($g12IdsFirst !== $g12IdsSecond) {
            gfail('G12', [
                'songId'       => $g12SongId,
                'firstCount'   => count($g12IdsFirst),
                'secondCount'  => count($g12IdsSecond),
                'onlyInFirst'  => array_slice(array_values(array_diff($g12IdsFirst, $g12IdsSecond)), 0, 5),
                'onlyInSecond' => array_slice(array_values(array_diff($g12IdsSecond, $g12IdsFirst)), 0, 5),
            ]);
        }
    }
} else {
    out("  [SKIP] G12  Id-stability smoke only runs under --phase=soak (this run: phase={$phase}).");
}

$fingerprint = [
    'songs'       => $nSongs,
    'components'  => $totalComponents,
    'lines'       => $totalLines,
    'corpusSha256'=> hash_final($corpusHash),
];

/* ====================================================================== */
/* Report + sentinel                                                       */
/* ====================================================================== */
$allGreen = true;
foreach ($gates as $id => $g) {
    $status = $g['pass'] ? 'PASS' : 'FAIL';
    if (!$g['pass']) { $allGreen = false; }
    out(sprintf('  [%s] %-4s %s%s', $status, $id, $g['desc'], $g['pass'] ? '' : "  ({$g['fails']} fail)"));
    if (!$g['pass'] && $emitSamples) {
        foreach (array_slice($g['samples'], 0, 20) as $s) { out('        ' . json_encode($s, JSON_UNESCAPED_UNICODE)); }
    }
}
out('  fingerprint: ' . json_encode($fingerprint, JSON_UNESCAPED_UNICODE));

/* NDJSON failure stream → stderr (CI / log capture). CLI only — STDERR is undefined under PHP-FPM. */
if (!$LCV_WEB) {
    foreach ($failuresNdjson as $line) { fwrite(STDERR, $line . "\n"); }
}

/* Write the sentinel only on a full corpus all-green run (not when --limit truncated). */
if ($allGreen && $limit === 0) {
    $payload = json_encode(['phase' => $phase, 'result' => 'green', 'fingerprint' => $fingerprint], JSON_UNESCAPED_UNICODE);
    /* ranAt is stamped by the DB (NOW()) — the script has no wall clock it should trust. */
    $stmt = $db->prepare(
        "INSERT INTO tblAppSettings (SettingKey, SettingValue, UpdatedAt)
         VALUES ('lyrics_cutover_gate', ?, NOW())
         ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue), UpdatedAt = NOW()"
    );
    $stmt->bind_param('s', $payload);
    $stmt->execute();
    $stmt->close();
    out('  sentinel: lyrics_cutover_gate written (green).');
} elseif ($allGreen) {
    out('  sentinel: NOT written (--limit run is not a full-corpus proof).');
}

/* Activity Log (#1282) — record every run's outcome (CLI cron + web) so an
   unattended soak going RED is visible in /manage/activity-log without cron email.
   Best-effort: logActivity never throws + is absent only if activity_log didn't load. */
if (function_exists('logActivity')) {
    $vcFailedGates = [];
    foreach ($gates as $vcGid => $vcG) {
        if (!$vcG['pass']) { $vcFailedGates[$vcGid] = $vcG['fails']; }
    }
    logActivity(
        'setup.verify_cutover',
        'database',
        $phase,
        [
            'result'      => $allGreen ? 'green' : 'red',
            'mode'        => $LCV_WEB ? 'web' : 'cli',
            'limit'       => $limit,
            'failedGates' => $vcFailedGates,
            'fingerprint' => $fingerprint,
            'sentinel'    => ($allGreen && $limit === 0) ? 'written' : 'not-written',
        ],
        $allGreen ? 'success' : 'failure',
        null,
        (int) round((microtime(true) - $vcStart) * 1000)
    );
}

if ($asJson) {
    out(json_encode(['phase' => $phase, 'allGreen' => $allGreen, 'gates' => array_map(static fn($g) => ['pass' => $g['pass'], 'fails' => $g['fails']], $gates), 'fingerprint' => $fingerprint], JSON_UNESCAPED_UNICODE));
}

out($allGreen ? "== GREEN — phase {$phase} passed ==" : "== RED — phase {$phase} has failures ==");
/* Web: return so the dashboard reads $allGreen + finishes the page chrome; CLI: exit code for CI. */
if ($LCV_WEB) { return; }
exit($allGreen ? 0 : 1);
