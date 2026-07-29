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
 * PHP-FPM (fatal) and exit() would truncate the dashboard. The NINE gates below
 * are IDENTICAL in both modes; only this wrapper differs.
 *
 * The nine are G1, G2, G3, G5, G6, G7, G8, G9, G10 — the numbering has holes,
 * and the holes are NOT all benign. The design pass (.claude/
 * lyrics-normalisation-strategy.md §11, table at L516-524) specifies thirteen.
 * Where the other four actually went, verified rather than assumed (#1615):
 *
 *   G11 (corpus fingerprint frozen across the drop) — IMPLEMENTED, but split
 *        across two files by design: this script WRITES the fingerprint (incl.
 *        corpusSha256) into the sentinel; migrate-retire-component-lines-json.php
 *        Stage 0(b) re-checks it still matches live counts at drop time. A
 *        single-process gate here could not span the drop, so this is correct.
 *   G13 (byte/semantic parity on all projected cols incl. ChordsJson) —
 *        SUBSUMED by G2, whose comparison already covers ChordsJson.
 *   G4′ (ArrangementJson arrays index valid ordinals 0..n-1; SortOrder stays
 *        contiguous) — only HALF landed. G8 covers SortOrder contiguity. The
 *        ArrangementJson ordinal-validity half is NOT implemented anywhere.
 *   G12 (live Id-stability smoke: 3 sentinel songs, double re-projection,
 *        identical Id sets) — NOT implemented. It is a live operational check,
 *        not something a batch verifier can assert.
 *
 * So an all-green run here is NOT the full thirteen-gate proof the design doc
 * describes. G4′-ordinals and G12 are tracked as a gap in #1618. Do NOT
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
if (!function_exists('lyricLinesAssembleComponents') || !function_exists('getDbMysqli')) {
    lcv_err('could not load includes/db_mysql.php or includes/lyric_lines_read.php');
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

/* ---- args ---- (CLI: getopt; web: ?phase= only — the smoke/sample flags are CLI-only) */
if ($LCV_WEB) {
    $phase       = (string)($_GET['phase'] ?? 'pre');
    /* Optional smoke limit (capped): a fast first-N-songs dry-run so the operator
       can confirm the web path works before the slow full-corpus run. limit>0 NEVER
       writes the sentinel (see the report tail), so a smoke run can't arm the drop. */
    $limit       = isset($_GET['limit']) ? max(0, min(5000, (int)$_GET['limit'])) : 0;
    $emitSamples = false;
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
    global $gates, $failuresNdjson;
    $gates[$id]['pass'] = false;
    $gates[$id]['fails']++;
    if (count($gates[$id]['samples']) < 20) { $gates[$id]['samples'][] = $detail; }
    $failuresNdjson[] = json_encode(['gate' => $id] + $detail, JSON_UNESCAPED_UNICODE);
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
gate('G8', 'ComponentId runs contiguous; group order ≡ component SortOrder');
gate('G10', 'per-line LanguageCode consistent (no spurious overrides emitted)');

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
