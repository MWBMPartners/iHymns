<?php

declare(strict_types=1);

/**
 * iHymns — Rounds/canon PURE timeline + voice-shape truth table (#2073, commit 5)
 *
 * ELI5: this proves `includes/lyric_rounds.php`'s two load-bearing PURE
 * functions — `lyricRoundsValidateVoicesShape()` (is this a legal set of
 * voices?) and, the star of the file, `lyricRoundTimeline()` (turn "3
 * voices, staggered, twice through" into an exact step-by-step schedule) —
 * do what "Design pass 7" §3.3 specifies, with NO database involved at all.
 * This is the ONE function a future Present-mode projector and its
 * JavaScript twin both depend on getting byte-identical, so every scenario
 * below is worked out BY HAND against the plan's own algorithm (never by
 * calling the function under test and asserting it agrees with itself).
 *
 *   php tests/php/test-lyric-rounds-timeline.php
 *
 * Exit status 0 = every assertion passed, 1 = at least one failed.
 *
 * @see appWeb/public_html/includes/lyric_rounds.php   the file under test
 * @see .claude/vocal-parts-2073-plan.md                "Design pass 7" §3.2-§3.3 (the algorithm this file transcribes, plus this file's own two flagged, documented corner-resolutions for coda-under-ms and the entryMs search bound)
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/db_mysql.php';
require $root . '/appWeb/public_html/includes/line_enrichment.php';   // lyricRoundUpsert() needs it loaded; harmless to load unconditionally here too
require $root . '/appWeb/public_html/includes/vocal_parts.php';
require $root . '/appWeb/public_html/includes/lyric_lines_sync.php';  // lyric_rounds.php's lyricRoundUpsert() requires this lazily; loaded up front so phpSourceUnits() sees a fully-parseable tree either way
require $root . '/appWeb/public_html/includes/lyric_rounds.php';
require $root . '/tests/php/lib/php_source_units.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        if ($detail !== '') { echo "        $detail\n"; }
        $failed++;
    }
}

function assertEq($actual, $expected, string $label): void
{
    ok($label, $actual === $expected, 'expected: ' . var_export($expected, true) . " \n        actual:   " . var_export($actual, true));
}

function assertThrows(callable $fn, string $expectedClass, string $label): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  FAIL  $label (no exception thrown)\n";
        $failed++;
    } catch (\Throwable $e) {
        if ($e instanceof $expectedClass) {
            echo "  PASS  $label\n";
            $passed++;
        } else {
            echo "  FAIL  $label (wrong exception: " . get_class($e) . ': ' . $e->getMessage() . ")\n";
            $failed++;
        }
    }
}

/** Pull just the {n, line} pairs of one step, in voice-number order — the
 *  part of a step's shape every scenario below actually checks. */
function stepLines(array $timeline, int $i): array
{
    $out = [];
    foreach (($timeline['steps'][$i]['voices'] ?? []) as $v) {
        $out[$v['n']] = $v['line'];
    }
    ksort($out);
    return $out;
}

/* ====================================================================== *
 * 1 — lyricRoundsValidateVoicesShape()
 * ====================================================================== */
echo "1 — lyricRoundsValidateVoicesShape()\n";

assertThrows(static fn() => lyricRoundsValidateVoicesShape([]), \InvalidArgumentException::class,
    'zero voices throws');
assertThrows(static fn() => lyricRoundsValidateVoicesShape(array_fill(0, 9, ['number' => 1])), \InvalidArgumentException::class,
    'more than 8 voices throws');
assertThrows(
    static fn() => lyricRoundsValidateVoicesShape([['number' => 1], ['number' => 1]]),
    \InvalidArgumentException::class,
    'a repeated voice number throws'
);
assertThrows(
    static fn() => lyricRoundsValidateVoicesShape([['number' => 1], ['number' => 3]]),
    \InvalidArgumentException::class,
    'a GAP in voice numbering (1, 3 — no 2) throws'
);
assertThrows(
    static fn() => lyricRoundsValidateVoicesShape([['number' => 2], ['number' => 3]]),
    \InvalidArgumentException::class,
    'voice numbering that does not START at 1 throws'
);
assertThrows(
    static fn() => lyricRoundsValidateVoicesShape([['number' => 1, 'entryLines' => 1], ['number' => 2]]),
    \InvalidArgumentException::class,
    'voice 1 with a non-zero entryLines throws — voice 1 must always enter at 0'
);
assertThrows(
    static fn() => lyricRoundsValidateVoicesShape([['number' => 1], ['number' => 2, 'entryBasis' => 'beats']]),
    \InvalidArgumentException::class,
    'a non-voice-1 voice claiming entryBasis "beats" with NO entryBeats throws'
);
assertThrows(
    static fn() => lyricRoundsValidateVoicesShape([['number' => 1], ['number' => 2, 'entryBasis' => 'ms']]),
    \InvalidArgumentException::class,
    'a non-voice-1 voice claiming entryBasis "ms" with NO entryMs throws'
);
assertThrows(
    static fn() => lyricRoundsValidateVoicesShape([['number' => 1], ['number' => 2, 'startLineId' => 500]]),
    \InvalidArgumentException::class,
    'a voice with startLineId but NO endLineId (partner-song "both or neither") throws'
);

$shaped = lyricRoundsValidateVoicesShape([
    ['number' => 2, 'entryLines' => 2],           // deliberately out of order in the input
    ['number' => 1],
    ['number' => 3, 'entryBasis' => 'beats', 'entryBeats' => 8.0, 'label' => '  Tenor  '],
]);
assertEq(array_column($shaped, 'number'), [1, 2, 3], 'voices come back sorted by number regardless of input order');
assertEq(array_column($shaped, 'sortOrder'), [0, 1, 2], 'sortOrder is assigned 0-based in number order');
assertEq($shaped[0]['entryBasis'], 'lines', 'voice 1 defaults to entryBasis "lines"');
assertEq($shaped[2]['label'], 'Tenor', 'a voice label is trimmed the same way a part label is');
assertEq($shaped[2]['entryBeats'], 8.0, 'a legitimately-populated entryBeats survives');

/* MUTATION PROOF (documented, exercised manually — see this commit's report):
   removing the `$number === 1` special case (so voice 1 is validated exactly
   like every other voice) makes the "voice 1 with a non-zero entryLines
   throws" assertion above go from PASS to FAIL — a real, load-bearing
   contiguity/floor rule this test alone (not a structural grep) proves. */

/* ====================================================================== *
 * 2 — lyricRoundSubjectLineIds() — PURE slice
 * ====================================================================== */
echo "\n2 — lyricRoundSubjectLineIds()\n";

$ordered = [10, 20, 30, 40, 50];
assertEq(lyricRoundSubjectLineIds($ordered, 20, 40), [20, 30, 40], 'an ordinary interior slice');
assertEq(lyricRoundSubjectLineIds($ordered, 30, null), [30], 'a null endLineId means "just the one line"');
assertEq(lyricRoundSubjectLineIds($ordered, 999, 40), [], 'a startLineId not present in the order -> []');
assertEq(lyricRoundSubjectLineIds($ordered, 20, 999), [], 'an endLineId not present in the order -> []');
assertEq(lyricRoundSubjectLineIds($ordered, 40, 20), [], 'endLineId sitting BEFORE startLineId -> [] (never a negative-length slice)');
assertEq(lyricRoundSubjectLineIds($ordered, 10, 50), $ordered, 'the whole list, start to end');

/* ====================================================================== *
 * 3 — lyricRoundTimeline() — the projector's ONE input, PURE
 * ====================================================================== */
echo "\n3 — lyricRoundTimeline()\n";

/* Shared 4-line subject used by every 'lines'/'beats'/'ms' scenario below,
   so only the round/voice shape differs between them. */
$subject = [10, 20, 30, 40];
$n = 4;

/* --- 3a. THREE-VOICE CANON, 'lines' basis, entryLines 0/2/4, complete --- */
$roundA = ['timesThrough' => 2, 'endingMode' => 'complete', 'bpm' => null, 'beatsPerLine' => null];
$voicesA = [
    ['number' => 1, 'entryBasis' => 'lines', 'entryLines' => 0, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 2, 'entryBasis' => 'lines', 'entryLines' => 2, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 3, 'entryBasis' => 'lines', 'entryLines' => 4, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
];
$tlA = lyricRoundTimeline($roundA, $voicesA, $subject, [], []);
assertEq($tlA['basis'], 'lines', '3a: basis resolves to "lines" (no bpm, no timings)');
assertEq($tlA['stepMs'], null, '3a: stepMs is null under the "lines" basis');
assertEq(count($tlA['steps']), 12, '3a: total steps S = max(0+8, 2+8, 4+8) = 12 ("complete" — the LATEST voice\'s own finish)');
/* Hand-worked expected {voice => line} per step (see this file's own
   worked derivation in the commit report — p<0 -> -1 waiting, p>=n*Tv -> -2
   finished, else p mod n). */
$expectedA = [
    0  => [1 => 0,  2 => -1, 3 => -1],
    1  => [1 => 1,  2 => -1, 3 => -1],
    2  => [1 => 2,  2 => 0,  3 => -1],
    3  => [1 => 3,  2 => 1,  3 => -1],
    4  => [1 => 0,  2 => 2,  3 => 0],
    5  => [1 => 1,  2 => 3,  3 => 1],
    6  => [1 => 2,  2 => 0,  3 => 2],
    7  => [1 => 3,  2 => 1,  3 => 3],
    8  => [1 => -2, 2 => 2,  3 => 0],
    9  => [1 => -2, 2 => 3,  3 => 1],
    10 => [1 => -2, 2 => -2, 3 => 2],
    11 => [1 => -2, 2 => -2, 3 => 3],
];
foreach ($expectedA as $i => $exp) {
    assertEq(stepLines($tlA, $i), $exp, "3a: step $i matches the hand-worked canon schedule");
}

/* --- 3b. UNEVEN entry offsets (0, 1, 3 — not evenly spaced), 'lines', complete --- */
$voicesB = [
    ['number' => 1, 'entryBasis' => 'lines', 'entryLines' => 0, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 2, 'entryBasis' => 'lines', 'entryLines' => 1, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 3, 'entryBasis' => 'lines', 'entryLines' => 3, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
];
$tlB = lyricRoundTimeline($roundA, $voicesB, $subject, [], []);
assertEq(count($tlB['steps']), 11, '3b: S = max(0+8, 1+8, 3+8) = 11 with uneven offsets');
assertEq(stepLines($tlB, 0),  [1 => 0,  2 => -1, 3 => -1], '3b: step 0');
assertEq(stepLines($tlB, 1),  [1 => 1,  2 => 0,  3 => -1], '3b: step 1 — voice 2 enters exactly one line after voice 1, not two');
assertEq(stepLines($tlB, 3),  [1 => 3,  2 => 2,  3 => 0],  '3b: step 3 — voice 3 enters exactly three lines after voice 1');
assertEq(stepLines($tlB, 8),  [1 => -2, 2 => 3,  3 => 1],  '3b: step 8 — voice 1 already finished (uneven offsets do not delay a finish)');
assertEq(stepLines($tlB, 10), [1 => -2, 2 => -2, 3 => 3],  '3b: step 10, the LAST step (S=11) — voice 3 is still mid-phrase, never shows -2 (matches its own e+n*T = 11 = S exactly)');

/* --- 3c. 'beats' basis: bpm=100, beatsPerLine=4 -> stepMs=2400 (the plan's own worked number) --- */
$roundC = ['timesThrough' => 2, 'endingMode' => 'complete', 'bpm' => 100.0, 'beatsPerLine' => 4.0];
$voicesC = [
    ['number' => 1, 'entryBasis' => 'lines', 'entryLines' => 0, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 2, 'entryBasis' => 'beats', 'entryLines' => 0, 'entryBeats' => 8.0,  'entryMs' => null, 'timesThrough' => null],
    ['number' => 3, 'entryBasis' => 'beats', 'entryLines' => 0, 'entryBeats' => 16.0, 'entryMs' => null, 'timesThrough' => null],
];
$tlC = lyricRoundTimeline($roundC, $voicesC, $subject, [], []);
assertEq($tlC['basis'], 'beats', '3c: basis resolves to "beats" (bpm + beatsPerLine both positive)');
assertEq($tlC['stepMs'], 2400, '3c: stepMs = round(60000/100*4) = 2400, the plan\'s own worked example');
assertEq(stepLines($tlC, 0), [1 => 0, 2 => -1, 3 => -1], '3c: entryBeats 8.0 / beatsPerLine 4.0 = 2 line-steps, same shape as scenario 3a\'s entryLines=2');
assertEq(stepLines($tlC, 8), [1 => -2, 2 => 2, 3 => 0],  '3c: identical STEP shape to 3a at i=8 (the beats->line-step conversion round-trips exactly for these numbers)');
assertEq($tlC['steps'][0]['atMs'], 0,     '3c: atMs(0) = 0 * stepMs');
assertEq($tlC['steps'][1]['atMs'], 2400,  '3c: atMs(1) = 1 * stepMs');
assertEq($tlC['steps'][11]['atMs'], 26400, '3c: atMs(11) = 11 * stepMs (the beats grid runs the whole way, never re-based per voice)');

/* --- 3d. 'ms' basis: four EQUAL-duration (1000ms) subject lines --- */
$msStart = [10 => 0, 20 => 1000, 30 => 2000, 40 => 3000];
$msEnd   = [10 => 1000, 20 => 2000, 30 => 3000, 40 => 4000];
$voicesD = [
    ['number' => 1, 'entryBasis' => 'lines', 'entryLines' => 0, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 2, 'entryBasis' => 'ms',    'entryLines' => 0, 'entryBeats' => null, 'entryMs' => 2000,  'timesThrough' => null],
    ['number' => 3, 'entryBasis' => 'ms',    'entryLines' => 0, 'entryBeats' => null, 'entryMs' => 4000,  'timesThrough' => null],
];
$tlD = lyricRoundTimeline($roundA, $voicesD, $subject, $msStart, $msEnd);
assertEq($tlD['basis'], 'ms', '3d: basis resolves to "ms" (every voice>1 has entryMs AND every subject line is fully timed)');
assertEq(stepLines($tlD, 8), [1 => -2, 2 => 2, 3 => 0], '3d: entryMs 2000/4000 over four 1000ms lines converts to entrySteps 2/4 — same schedule shape as 3a/3c');
assertEq($tlD['steps'][0]['atMs'], 0,     '3d: atMs(0) = 0');
assertEq($tlD['steps'][4]['atMs'], 4000,  '3d: atMs(4) = cumulative duration of 4 subject steps at 1000ms each');
assertEq($tlD['steps'][11]['atMs'], 11000, '3d: atMs(11) = 11000 (the cycle keeps accumulating past one full pass through the 4 lines)');

/* Documented rounding: an entryMs that falls BETWEEN two line boundaries
   rounds UP to the next whole line-step (never truncates down into a line
   that has not fully started yet). 1500ms sits between cumDur(1)=1000 and
   cumDur(2)=2000, so voice 2 must enter at step 2, not step 1. */
$voicesD2 = $voicesD;
$voicesD2[1]['entryMs'] = 1500;
$tlD2 = lyricRoundTimeline($roundA, $voicesD2, $subject, $msStart, $msEnd);
assertEq(stepLines($tlD2, 1), [1 => 1, 2 => -1, 3 => -1], '3d rounding: at the in-between step, voice 2 is STILL waiting');
assertEq(stepLines($tlD2, 2), [1 => 2, 2 => 0,  3 => -1], '3d rounding: voice 2 enters at the NEXT whole line-step, not a fraction of one');

/* --- 3e. 'together' ending — voice 3 given a LONGER per-voice timesThrough
     override (3, vs the round's own 2) and is deliberately CUT MID-PHRASE
     at voice 1's own finish line, per the plan's own risk note. --- */
$roundE = ['timesThrough' => 2, 'endingMode' => 'together', 'bpm' => null, 'beatsPerLine' => null];
$voicesE = [
    ['number' => 1, 'entryBasis' => 'lines', 'entryLines' => 0, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 2, 'entryBasis' => 'lines', 'entryLines' => 2, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 3, 'entryBasis' => 'lines', 'entryLines' => 4, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => 3],
];
$tlE = lyricRoundTimeline($roundE, $voicesE, $subject, [], []);
assertEq(count($tlE['steps']), 8, '3e: "together" truncates at n * voice-1\'s-own T = 4*2 = 8, ignoring every other voice\'s own count entirely');
$everCutV3Finishes = false;
foreach ($tlE['steps'] as $s) {
    foreach ($s['voices'] as $v) {
        if ($v['n'] === 3 && $v['line'] === -2) { $everCutV3Finishes = true; }
    }
}
ok('3e: voice 3 (the longer override) NEVER shows "-2" — it is cut off mid-phrase, not allowed to finish', !$everCutV3Finishes);
assertEq(stepLines($tlE, 7), [1 => 3, 2 => 1, 3 => 3], '3e: the LAST step before the together cutoff — voice 3 is mid-phrase (its 2nd pass, line index 3), not waiting and not finished');

/* --- 3f. 'coda' ending — 'lines' basis: 2 coda lines appended, EVERY voice
     shows the SAME coda position simultaneously (sung in unison). --- */
$roundF = ['timesThrough' => 2, 'endingMode' => 'coda', 'bpm' => null, 'beatsPerLine' => null, 'codaLineIds' => [50, 60]];
$tlF = lyricRoundTimeline($roundF, $voicesA, $subject, [], []);
assertEq(count($tlF['steps']), 10, '3f: 8 "together"-style subject steps + 2 coda steps = 10');
assertEq(stepLines($tlF, 8), [1 => 4, 2 => 4, 3 => 4], '3f: the FIRST coda step — every voice at line index n+0=4 (the first coda line), together');
assertEq(stepLines($tlF, 9), [1 => 5, 2 => 5, 3 => 5], '3f: the SECOND coda step — every voice at n+1=5, still together');

/* --- 3f-beats. 'coda' under the 'beats' basis: atMs "continues the grid"
     exactly as the plan's own words say, for the coda steps too. --- */
$roundFBeats = ['timesThrough' => 2, 'endingMode' => 'coda', 'bpm' => 100.0, 'beatsPerLine' => 4.0, 'codaLineIds' => [50, 60]];
$tlFBeats = lyricRoundTimeline($roundFBeats, $voicesA, $subject, [], []);
assertEq($tlFBeats['steps'][8]['atMs'], 19200, '3f-beats: coda step 0 -> atMs = 8 * stepMs(2400) = 19200, continuing the same grid');
assertEq($tlFBeats['steps'][9]['atMs'], 21600, '3f-beats: coda step 1 -> atMs = 9 * 2400 = 21600');

/* --- 3f-ms. 'coda' under the 'ms' basis: THIS FILE'S OWN FLAGGED, DOCUMENTED
     corner-resolution (lyricRoundTimeline()'s doc-block corner 1) — a coda
     step's atMs continues the SAME cumulative clock as the subject, using
     the coda lines' own ms if the caller supplied them, and degrades to
     null (never a guess) the moment one is missing. --- */
$msStartF = $msStart + [50 => 8000, 60 => 8500];
$msEndF   = $msEnd   + [50 => 8500, 60 => 9300];   // coda line durations: 500ms, 800ms
$voicesFms = [
    ['number' => 1, 'entryBasis' => 'lines', 'entryLines' => 0, 'entryBeats' => null, 'entryMs' => null, 'timesThrough' => null],
    ['number' => 2, 'entryBasis' => 'ms',    'entryLines' => 0, 'entryBeats' => null, 'entryMs' => 2000,  'timesThrough' => null],
    ['number' => 3, 'entryBasis' => 'ms',    'entryLines' => 0, 'entryBeats' => null, 'entryMs' => 4000,  'timesThrough' => null],
];
$roundFms = ['timesThrough' => 2, 'endingMode' => 'coda', 'bpm' => null, 'beatsPerLine' => null, 'codaLineIds' => [50, 60]];
$tlFms = lyricRoundTimeline($roundFms, $voicesFms, $subject, $msStartF, $msEndF);
assertEq($tlFms['basis'], 'ms', '3f-ms: basis is still "ms" (subject lines fully timed, coda timing is a bonus, not a requirement)');
assertEq($tlFms['steps'][8]['atMs'], 8000, '3f-ms: coda step 0 -> subject total (8 * 1000ms = 8000) + 0 coda ms so far');
assertEq($tlFms['steps'][9]['atMs'], 8500, '3f-ms: coda step 1 -> 8000 + the first coda line\'s own 500ms duration');

/* Missing coda timing degrades that step (and any AFTER it) to null atMs —
   NEVER a guess — while the subject portion is completely unaffected. */
$msEndFPartial = $msEndF;
unset($msEndFPartial[60]);   // the SECOND coda line's own timing is simply not supplied
$tlFmsPartial = lyricRoundTimeline($roundFms, $voicesFms, $subject, $msStartF, $msEndFPartial);
assertEq($tlFmsPartial['steps'][8]['atMs'], 8000, '3f-ms degrade: the FIRST coda step (fully timed) is unaffected');
assertEq($tlFmsPartial['steps'][9]['atMs'], null, '3f-ms degrade: the SECOND coda step (missing its own timing) degrades to null rather than a guessed number');

/* --- 3g. Degenerate input: zero subject lines -> the empty timeline, never a crash --- */
$tlEmpty = lyricRoundTimeline($roundA, $voicesA, [], [], []);
assertEq($tlEmpty, ['basis' => 'lines', 'stepMs' => null, 'steps' => []], '3g: an empty subject-line list returns a harmless empty timeline');

/* MUTATION PROOF (documented, exercised manually — see this commit's report):
   changing the per-step line formula from `$p % $n` to plain `$p` — the
   exact mutation the plan's own guard list (G3) names — makes EVERY
   assertion in §3a onward that checks a non-negative `line` value fail
   (the numbers stop cycling through 0..n-1 and just keep climbing), while
   the waiting (-1) / finished (-2) boundary assertions stay green, proving
   the mutation is caught by the RIGHT assertions rather than by accident. */

/* ====================================================================== *
 * 4 — STRUCTURAL IDOR GUARD for lyric_rounds.php's own write functions
 *     (comment-stripped via the shared phpSourceUnits() tokenizer — the
 *     same mechanism test-vocal-parts-core.php uses one file over, so a
 *     doc-block PROSE mention of "vocalPartsResolveLines()" cannot satisfy
 *     this the way a bare grep would)
 * ====================================================================== */
echo "\n4 — structural IDOR guard (lyric_rounds.php)\n";

$lrSrc = (string)file_get_contents($root . '/appWeb/public_html/includes/lyric_rounds.php');
$lrUnits = phpSourceUnits($lrSrc);

function lyricRoundsFnCallsAny(array $units, string $fn, array $markers): bool
{
    $code = $units[$fn]['code'] ?? null;
    if ($code === null) { return false; }
    foreach ($markers as $marker) {
        if (str_contains($code, $marker)) { return true; }
    }
    return false;
}

ok(
    'lyricRoundUpsert proves ownership of its start/end lines via vocalPartsResolveLines()',
    lyricRoundsFnCallsAny($lrUnits, 'lyricRoundUpsert', ['vocalPartsResolveLines('])
);
ok(
    'lyricRoundUpsert proves ownership of a per-voice partId via vocalPartsResolvePart()',
    lyricRoundsFnCallsAny($lrUnits, 'lyricRoundUpsert', ['vocalPartsResolvePart('])
);
ok(
    'lyricRoundDelete resolves ownership via lyricRoundResolve() before deleting',
    lyricRoundsFnCallsAny($lrUnits, 'lyricRoundDelete', ['lyricRoundResolve('])
);
$lrResolveHasSongId = false;
foreach ($lrUnits['lyricRoundResolve']['strings'] ?? [] as $s) {
    if (str_contains($s, 'SongId')) { $lrResolveHasSongId = true; break; }
}
ok('lyricRoundResolve() itself joins on SongId (the ownership check every other function above delegates to)', $lrResolveHasSongId);

/* ====================================================================== */
echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed ($passed passed).\n";
    exit(1);
}
echo "All $passed lyric-rounds timeline assertions passed.\n";
