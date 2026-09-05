<?php
/**
 * iHymns — line-first assembler unit test (#1235 P4 / C1)
 *
 * Exercises the PURE assembly core lyricLinesAssembleFromRows() in
 * appWeb/public_html/includes/lyric_lines_read.php — the function that rebuilds a
 * song's component-shaped array from authoritative tblLyricLines rows. The output
 * shape must match SongData::_getComponents() byte-for-byte (key order included), so
 * these assertions pin that contract. No DB / HTTP.
 *
 *   php tests/php/test-lyric-lines-read.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/appWeb/public_html/includes/lyric_lines_read.php';

$passed = 0;
$failed = 0;
function assertEq($actual, $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}

/* Row factory matching lyricLinesAssembleFromRows() input shape. */
function row(int $lineId, ?int $cid, string $text, array $o = []): array
{
    return [
        'line_id'       => $lineId,
        'cid'           => $cid,
        'text'          => $text,
        'line_lang'     => $o['line_lang']     ?? null,
        'line_chords'   => $o['line_chords']   ?? null,
        'comp_type'     => array_key_exists('comp_type', $o)   ? $o['comp_type']   : 'verse',
        'comp_number'   => array_key_exists('comp_number', $o) ? $o['comp_number'] : 1,
        'comp_lang'     => $o['comp_lang']     ?? null,
        'line_parttype' => $o['line_parttype'] ?? null,
        'line_partnum'  => $o['line_partnum']  ?? null,
        /* #1860 Phase 5 §2.5 — optional comp_label (sparse public/export 'label'
           key, SD3). Defaults to null so cases 1-8 above are untouched: with
           comp_label absent-or-null, lyricLinesAssembleFromRows() never adds a
           'label' key, so every existing fixture's exact expected array stays
           correct byte-for-byte — that this file needed NO edits to those
           fixtures is itself the sparse-emit proof SD3 promises. */
        'comp_label'    => array_key_exists('comp_label', $o) ? $o['comp_label'] : null,
    ];
}

/* ==================================================================== */
/* 1 — two verses: grouped by ComponentId, lineIds present, no chords/langs */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 10, 'a', ['comp_number' => 1]), row(2, 10, 'b', ['comp_number' => 1]),
        row(3, 11, 'c', ['comp_number' => 2]), row(4, 11, 'd', ['comp_number' => 2]),
    ]),
    [
        ['type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'], 'chords' => null, 'language' => null, 'lineIds' => [1, 2]],
        ['type' => 'verse', 'number' => 2, 'lines' => ['c', 'd'], 'chords' => null, 'language' => null, 'lineIds' => [3, 4]],
    ],
    'two verses grouped by ComponentId, byte-shape matches _getComponents'
);

/* ==================================================================== */
/* 2 — repeated identical refrain: distinct components, NOT merged by text */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 20, 'Glory', ['comp_type' => 'refrain', 'comp_number' => 0]),
        row(2, 21, 'Glory', ['comp_type' => 'refrain', 'comp_number' => 0]),
    ]),
    [
        ['type' => 'refrain', 'number' => 0, 'lines' => ['Glory'], 'chords' => null, 'language' => null, 'lineIds' => [1]],
        ['type' => 'refrain', 'number' => 0, 'lines' => ['Glory'], 'chords' => null, 'language' => null, 'lineIds' => [2]],
    ],
    'repeated refrain stays two components (grouped by cid, not text)'
);

/* ==================================================================== */
/* 3 — per-line language override emits sparse lineLanguages              */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 30, 'hello',   ['comp_lang' => 'en', 'line_lang' => 'en']),
        row(2, 30, 'bonjour', ['comp_lang' => 'en', 'line_lang' => 'fr']),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['hello', 'bonjour'],
        'chords' => null, 'language' => 'en', 'lineIds' => [1, 2], 'lineLanguages' => ['en', 'fr'],
    ]],
    'per-line override -> lineLanguages emitted'
);

/* ==================================================================== */
/* 4 — all lines inherit the component language: NO lineLanguages key     */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 40, 'x', ['comp_lang' => 'ko', 'line_lang' => 'ko']),
        row(2, 40, 'y', ['comp_lang' => 'ko', 'line_lang' => 'ko']),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['x', 'y'],
        'chords' => null, 'language' => 'ko', 'lineIds' => [1, 2],
    ]],
    'inherited language -> no lineLanguages key (sparse)'
);

/* a null line-language against a non-null component language IS a difference */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 41, 'a', ['comp_lang' => 'en', 'line_lang' => 'en']),
        row(2, 41, 'b', ['comp_lang' => 'en', 'line_lang' => null]),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'],
        'chords' => null, 'language' => 'en', 'lineIds' => [1, 2], 'lineLanguages' => ['en', null],
    ]],
    'null line-lang vs non-null comp-lang -> lineLanguages emitted'
);

/* ==================================================================== */
/* 5 — chords reconstructed as the parallel array (null-padded)           */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 50, 'a', ['line_chords' => json_encode(['C', 'Am'])]),
        row(2, 50, 'b', ['line_chords' => null]),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'],
        'chords' => [['C', 'Am'], null], 'language' => null, 'lineIds' => [1, 2],
    ]],
    'per-line chords recomposed into the parallel array'
);

/* ==================================================================== */
/* 6 — componentless (ComponentId NULL): group by PartType + PartNumber   */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, null, 'a', ['comp_type' => null, 'comp_number' => null, 'line_parttype' => 'verse', 'line_partnum' => 1]),
        row(2, null, 'b', ['comp_type' => null, 'comp_number' => null, 'line_parttype' => 'verse', 'line_partnum' => 1]),
        row(3, null, 'c', ['comp_type' => null, 'comp_number' => null, 'line_parttype' => 'chorus', 'line_partnum' => 0]),
    ]),
    [
        ['type' => 'verse',  'number' => 1, 'lines' => ['a', 'b'], 'chords' => null, 'language' => null, 'lineIds' => [1, 2]],
        ['type' => 'chorus', 'number' => 0, 'lines' => ['c'],      'chords' => null, 'language' => null, 'lineIds' => [3]],
    ],
    'componentless lines group by part identity'
);

/* ==================================================================== */
/* 7 — empty input -> empty list                                          */
/* ==================================================================== */
assertEq(lyricLinesAssembleFromRows([]), [], 'no rows -> []');

/* ==================================================================== */
/* 8 — adjacent same-type different-number verses are NOT merged          */
/* ==================================================================== */
assertEq(
    count(lyricLinesAssembleFromRows([
        row(1, 70, 'a', ['comp_number' => 1]),
        row(2, 71, 'b', ['comp_number' => 1]),   // a SECOND verse-1 (CP-0110 twin-verse case), distinct cid
    ])),
    2,
    'twin verse-1 components (distinct cid) stay separate'
);

/* ==================================================================== */
/* 9 — #1860 Phase 5 §2.5: comp_label present -> sparse 'label' emitted   */
/*     (placed right after 'language', before 'lineIds' — see the        */
/*     $flush ordering in lyric_lines_read.php)                          */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 80, 'Kyrie eleison', ['comp_type' => 'other', 'comp_number' => 0, 'comp_label' => 'Kyrie']),
    ]),
    [[
        'type' => 'other', 'number' => 0, 'lines' => ['Kyrie eleison'],
        'chords' => null, 'language' => null, 'label' => 'Kyrie', 'lineIds' => [1],
    ]],
    "#1860 Phase 5 - comp_label present -> assembled component carries 'label' => 'Kyrie'"
);

/* ==================================================================== */
/* 10 — #1860 Phase 5 §2.5: comp_label null/'' -> NO 'label' key          */
/*      (strict === on the FULL array is what proves sparseness — a      */
/*      loose == would not catch an extra null-valued key)               */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 81, 'a', ['comp_label' => null]),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a'],
        'chords' => null, 'language' => null, 'lineIds' => [1],
    ]],
    '#1860 Phase 5 - null comp_label -> no label key (sparse, byte-identical to pre-Phase-5 shape)'
);
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 82, 'a', ['comp_label' => '']),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a'],
        'chords' => null, 'language' => null, 'lineIds' => [1],
    ]],
    "#1860 Phase 5 - empty-string comp_label -> no label key (sparse)"
);

/* ==================================================================== */
/* 11 — the EDITOR shape carries lineIds too (#1627)                      */
/*                                                                        */
/* lyricLinesEditableComponents() takes a live \mysqli, so unlike          */
/* lyricLinesAssembleFromRows() above it cannot be exercised as a pure     */
/* function here. This is therefore a SOURCE assertion, and it is worth    */
/* having despite that weakness because of what the omission cost:         */
/*                                                                        */
/* The assembler shape has always emitted `lineIds` (which is why every    */
/* case above asserts them), and v1's editor reads that shape. The EDITOR  */
/* shape — the one api2 and the whole v2 editor speak — dropped the        */
/* `line_id` its own query already selected. So per-line translations and  */
/* annotations (#1088) had nothing to anchor to in v2: the tables, the     */
/* read path and api2's own endpoints all existed, with no way for the     */
/* v2 UI to put data into them. Silent, and invisible from either side.    */
/*                                                                        */
/* Rule #21: enrichment anchors on tblLyricLines.Id, never a LinesJson     */
/* index — an index shifts the moment a line is inserted and the           */
/* annotation drifts onto a different line with no error.                  */
/* ==================================================================== */
$readerSrc = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/includes/lyric_lines_read.php'
);
/* Strip block comments — the note above the code quotes `lineIds` while
   explaining it, and prose must not satisfy an assertion about code. */
$readerCode = preg_replace('#/\*[\s\S]*?\*/#', '', $readerSrc) ?? $readerSrc;

$editableFn = '';
$fnStart = strpos($readerCode, 'function lyricLinesEditableComponents');
if ($fnStart !== false) {
    $nextFn = strpos($readerCode, "\nfunction ", $fnStart + 10);
    $editableFn = substr($readerCode, $fnStart, $nextFn === false ? null : $nextFn - $fnStart);
}

assertEq($editableFn !== '', true, 'lyricLinesEditableComponents() found in the ONE read path');
assertEq(
    str_contains($editableFn, "'lineIds'"),
    true,
    "editor shape emits 'lineIds' — without it v2 has no anchor for #1088 enrichment"
);
assertEq(
    str_contains($editableFn, "'line_id'"),
    true,
    'editor shape reads the line_id its own query selects (ll.Id AS line_id)'
);
assertEq(
    str_contains($editableFn, "'label'"),
    true,
    "#1860 Phase 5 §2.3 — editor shape emits 'label' (REQ 3b, always-present)"
);
assertEq(
    str_contains($editableFn, "'sourceWorkId'"),
    true,
    "#1860 Phase 5 §2.3 — editor shape emits 'sourceWorkId' (REQ 2, always-present)"
);

/* ==================================================================== */
/* #2072 — the EDITOR shape now ALSO carries `notes` (always-present,     */
/* mirroring `chords`), so a per-line note that is WRITTEN (the OpenLyrics */
/* importer) can also be READ BACK — before this fix, tblLyricLines.Note   */
/* was write-only and the next whole-song save silently destroyed it.     */
/* Same "source assertion" caveat as the block above: lyricLinesEditable-  */
/* Components() takes a live \mysqli, so it can't be exercised as a pure   */
/* function here.                                                         */
/* ==================================================================== */
assertEq(
    str_contains($editableFn, "'notes'"),
    true,
    "#2072 — editor shape emits 'notes' (always-present, mirroring 'chords')"
);
assertEq(
    str_contains($editableFn, 'line_note'),
    true,
    '#2072 — editor shape reads line_note (ll.Note AS line_note from lyricLinesFetchPrimary())'
);

/* ==================================================================== */
/* #2072 — PROVE the PUBLIC/export shape is untouched. lyricLinesFetchPrimary() */
/* now selects `ll.Note AS line_note` (needed by the EDITOR shape above), but  */
/* the PUBLIC assembler lyricLinesAssembleFromRows() — the function every one   */
/* of the pure-function tests above exercises, and the one                     */
/* tools/export-fidelity-snapshot.php hashes per song — must NOT read that key: */
/* it is hashed byte-for-byte across ~16,083 songs and compared with strict     */
/* `===` by this very file's cases 1-10 above, so a new always-present key      */
/* there would silently change every one of those hashes. This is the          */
/* MUTATION-PROVABLE version of that promise: teach the assembler to read       */
/* `line_note` (e.g. by sparsely emitting a public `notes` key) and this        */
/* assertion goes red immediately, without needing a live DB or the fidelity    */
/* snapshot tool to catch it.                                                   */
/* ==================================================================== */
$assembleFn = '';
$assembleFnStart = strpos($readerCode, 'function lyricLinesAssembleFromRows');
if ($assembleFnStart !== false) {
    $nextFn2    = strpos($readerCode, "\nfunction ", $assembleFnStart + 10);
    $assembleFn = substr($readerCode, $assembleFnStart, $nextFn2 === false ? null : $nextFn2 - $assembleFnStart);
}
assertEq($assembleFn !== '', true, 'lyricLinesAssembleFromRows() found in the ONE read path');
assertEq(
    str_contains($assembleFn, 'line_note'),
    false,
    "#2072 — the PUBLIC shape (lyricLinesAssembleFromRows) must NOT read line_note; " .
    "'notes' is an EDITOR-shape-only addition (pass 7 C7) so the hashed public shape stays byte-identical"
);
assertEq(
    str_contains($assembleFn, "'notes'"),
    false,
    "#2072 — the PUBLIC shape must NOT emit a 'notes' key (would change ~16k stored fidelity hashes)"
);

/* ==================================================================== */
/* 12 — #2073 "who sings this line": lyricLinesFoldVoiceRuns() truth table */
/*      (the worked example from .claude/vocal-parts-2073-plan.md         */
/*      "Design pass 3" §1.1, implemented verbatim — six lines, a duet on */
/*      line 3, an echo on line 4, a gap on line 5, echo resumes on 6).   */
/*      W=female/10 "Women", M=male/11 "Men", E=backing/12 "Echo" (bg).   */
/* ==================================================================== */
$W = ['id' => 10, 'kind' => 'female',  'label' => 'Women', 'bg' => false];
$M = ['id' => 11, 'kind' => 'male',    'label' => 'Men',   'bg' => false];
$E = ['id' => 12, 'kind' => 'backing', 'label' => 'Echo',  'bg' => true];

/* Line ids are arbitrary — the fold only ever compares them for equality,
   never interprets them — so real-looking tblLyricLines ids (401..406)
   prove the function is genuinely id-agnostic, not accidentally relying on
   0-based array positions lining up with the ids themselves. */
$foldLineIds = [401, 402, 403, 404, 405, 406];
$foldVoices = [
    401 => [$W],
    402 => [$W],
    403 => [$W, $M],       // duet
    404 => [$W, $E],       // echo alongside the lead
    // 405 absent — a gap
    406 => [$E],
];
assertEq(
    lyricLinesFoldVoiceRuns($foldLineIds, $foldVoices),
    [
        ['from' => 0, 'to' => 1, 'parts' => [
            ['id' => 10, 'kind' => 'female', 'label' => 'Women', 'bg' => false, 'enters' => true],
        ]],
        ['from' => 2, 'to' => 2, 'parts' => [
            ['id' => 10, 'kind' => 'female',  'label' => 'Women', 'bg' => false, 'enters' => false],
            ['id' => 11, 'kind' => 'male',    'label' => 'Men',   'bg' => false, 'enters' => true],
        ]],
        ['from' => 3, 'to' => 3, 'parts' => [
            ['id' => 10, 'kind' => 'female',  'label' => 'Women', 'bg' => false, 'enters' => false],
            ['id' => 12, 'kind' => 'backing', 'label' => 'Echo',  'bg' => true,  'enters' => true],
        ]],
        ['from' => 5, 'to' => 5, 'parts' => [
            ['id' => 12, 'kind' => 'backing', 'label' => 'Echo', 'bg' => true, 'enters' => true],
        ]],
    ],
    'lyricLinesFoldVoiceRuns(): run A extends over the two identical Women lines, ' .
    'the duet on line 3 opens run B (Women continues, Men enters), the echo on line 4 ' .
    'opens run C (Women continues, Echo enters), the gap on line 5 closes it, and the ' .
    'echo alone on line 6 opens run D with Echo entering fresh (the gap reset adjacency)'
);
assertEq(lyricLinesFoldVoiceRuns([], []), [], 'lyricLinesFoldVoiceRuns(): no lines -> []');
assertEq(lyricLinesFoldVoiceRuns([401, 402], []), [], 'lyricLinesFoldVoiceRuns(): no voice data anywhere -> [] (never a leading empty run)');

/* MUTATION PROOF (recorded per rule #34 — run once, must go RED, then restore):
 *   change `$sig === $prevSig` to `$sig !== $prevSig` in lyricLinesFoldVoiceRuns()
 *   -> the two-line Women run (case above) splits into two separate runs instead
 *      of extending -> this assertion goes RED (run count 4 -> 5, `to` fields wrong).
 *   remove the `if ($runs !== [])` sparse guard around the `voices` emit in
 *   lyricLinesAssembleFromRows() (below, case 15) -> case 15 goes RED (an empty
 *   `voices => []` key appears where the test asserts the key is ABSENT).
 * Both were run against this file during #2073 commit 4's own verification pass
 * and confirmed RED before being restored to the code below.
 */

/* ==================================================================== */
/* 13 — #2073: lyricLinesAssembleFromRows($rows) with NO extra args is    */
/*      BYTE-IDENTICAL to before commit 4 — the whole point of defaulting */
/*      $voicesByLine/$spansByLine to [] is that every existing call site */
/*      (there are many) that never learned about voices keeps behaving   */
/*      exactly as it always did.                                        */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 10, 'a', ['comp_number' => 1]), row(2, 10, 'b', ['comp_number' => 1]),
        row(3, 11, 'c', ['comp_number' => 2]), row(4, 11, 'd', ['comp_number' => 2]),
    ]),
    [
        ['type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'], 'chords' => null, 'language' => null, 'lineIds' => [1, 2]],
        ['type' => 'verse', 'number' => 2, 'lines' => ['c', 'd'], 'chords' => null, 'language' => null, 'lineIds' => [3, 4]],
    ],
    '#2073 — calling with only $rows (both new params default to []) reproduces case 1 exactly'
);

/* ==================================================================== */
/* 14 — #2073: `voices` is emitted AFTER `lineLanguages`, on a fixture     */
/*      that ALSO carries a sparse `lineLanguages` key — strict === on    */
/*      the WHOLE array is what proves the ORDER, not just the presence.  */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows(
        [
            row(1, 30, 'hello',   ['comp_lang' => 'en', 'line_lang' => 'en']),
            row(2, 30, 'bonjour', ['comp_lang' => 'en', 'line_lang' => 'fr']),
        ],
        [1 => [$W]]   // only line 1 (component position 0) carries a part
    ),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['hello', 'bonjour'],
        'chords' => null, 'language' => 'en', 'lineIds' => [1, 2], 'lineLanguages' => ['en', 'fr'],
        'voices' => [
            ['from' => 0, 'to' => 0, 'parts' => [
                ['id' => 10, 'kind' => 'female', 'label' => 'Women', 'bg' => false, 'enters' => true],
            ]],
        ],
    ]],
    "#2073 — 'voices' key lands AFTER 'lineLanguages' in insertion order (strict === proves it, " .
    "since PHP's === on arrays requires the same keys in the same order)"
);

/* ==================================================================== */
/* 15 — #2073: $voicesByLine that names ONLY line ids OUTSIDE this        */
/*      component -> NO `voices` key at all (sparse — never `voices=>[]`) */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows(
        [
            row(1, 10, 'a', ['comp_number' => 1]), row(2, 10, 'b', ['comp_number' => 1]),
        ],
        [999 => [$W]]   // 999 belongs to no line in this component
    ),
    [
        ['type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'], 'chords' => null, 'language' => null, 'lineIds' => [1, 2]],
    ],
    "#2073 — a \$voicesByLine map with no entry for any of this component's lines " .
    "produces NO 'voices' key (sparse; not an empty array)"
);

/* ==================================================================== */
/* 16 — #2073: `voiceSpans` — `line` is the 0-based POSITION within the   */
/*      component (index 1 for the SECOND line here), not the raw line   */
/*      id (102); a span keyed to an id outside the component -> no key. */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows(
        [row(101, 90, 'You are holy,'), row(102, 90, 'You are worthy,')],
        [],
        [102 => [['id' => 5, 'partId' => 12, 'kind' => 'backing', 'label' => 'Echo', 'bg' => true, 'start' => 4, 'end' => 9]]]
    ),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['You are holy,', 'You are worthy,'],
        'chords' => null, 'language' => null, 'lineIds' => [101, 102],
        'voiceSpans' => [
            ['line' => 1, 'start' => 4, 'end' => 9, 'part' => ['id' => 12, 'kind' => 'backing', 'label' => 'Echo', 'bg' => true]],
        ],
    ]],
    "#2073 — voiceSpans 'line' is the component-relative POSITION (1, the second line), " .
    "carries code-point start/end (rule #21), and the span's OWN id is not part of the public shape"
);
assertEq(
    lyricLinesAssembleFromRows(
        [row(101, 91, 'a')],
        [],
        [999 => [['id' => 1, 'partId' => 1, 'kind' => 'lead', 'label' => 'Lead', 'bg' => false, 'start' => 0, 'end' => 1]]]
    ),
    [['type' => 'verse', 'number' => 1, 'lines' => ['a'], 'chords' => null, 'language' => null, 'lineIds' => [101]]],
    "#2073 — a span keyed to a line id outside the component produces NO 'voiceSpans' key (sparse)"
);

/* ==================================================================== */
/* #2073 — WIRING: the assembler wrappers must actually CALL the gated    */
/* voice fetcher, and that fetcher must actually GATE on readiness — a    */
/* source assertion (same caveat as the editor-shape checks above: these  */
/* wrappers take a live \mysqli, so they can't be exercised as pure       */
/* functions here) that a future edit can't silently drop the wiring      */
/* while every pure-function case above keeps passing (they never touch   */
/* the wrapper functions at all).                                        */
/* ==================================================================== */
function _extractFn(string $code, string $name): string
{
    $start = strpos($code, "function {$name}(");
    if ($start === false) {
        return '';
    }
    $next = strpos($code, "\nfunction ", $start + 10);
    return substr($code, $start, $next === false ? null : $next - $start);
}

$assembleOneFn = _extractFn($readerCode, 'lyricLinesAssembleComponents');
$assembleMapFn = _extractFn($readerCode, 'lyricLinesAssembleComponentsMap');
$fetchVoicesFn = _extractFn($readerCode, 'lyricLinesFetchVoices');

assertEq($assembleOneFn !== '', true, 'lyricLinesAssembleComponents() found');
assertEq($assembleMapFn !== '', true, 'lyricLinesAssembleComponentsMap() found');
assertEq($fetchVoicesFn !== '', true, 'lyricLinesFetchVoices() found');
assertEq(
    str_contains($assembleOneFn, 'lyricLinesFetchVoices('),
    true,
    '#2073 — lyricLinesAssembleComponents() calls the gated voice fetcher'
);
assertEq(
    str_contains($assembleMapFn, 'lyricLinesFetchVoices('),
    true,
    '#2073 — lyricLinesAssembleComponentsMap() calls the gated voice fetcher (once per batch, never per song)'
);
assertEq(
    str_contains($fetchVoicesFn, 'vocalPartsTablesReady('),
    true,
    '#2073 — lyricLinesFetchVoices() gates on vocalPartsTablesReady() so an un-migrated install ' .
    'issues no extra query and the assembler output stays byte-identical'
);

echo "\n  ----------------------------------------\n";
echo "  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
