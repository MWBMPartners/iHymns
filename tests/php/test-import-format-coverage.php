<?php

declare(strict_types=1);

/**
 * iHymns — Single-file import format-coverage guard (#882)
 *
 * ELI5: the import page (import2.php) shows a dropdown of file formats you
 * can upload. This test makes sure that dropdown and the server (api2.php)
 * actually agree with each other — every format offered in the dropdown is
 * one the server will accept, every format the server accepts is reachable
 * from somewhere in the UI, and — the part that actually matters to a
 * curator — picking a format and uploading a matching file reaches a parser
 * that can read it, not a dead end.
 *
 * Detail — why this exists. #882 was exactly this shape of bug: OpenSong was
 * a format the SERVER'S underlying parser (_bulkImport_parseOpenSong()) could
 * already handle, but no upload path — dropdown or auto-detect — ever
 * reached it for a single file. A hand-typed list of "the formats" in a test
 * would have missed that the same way the UI missed it; this guard instead
 * DERIVES both sides from the live source (CLAUDE.md rule #34 — "derive from
 * the tree, not a list you typed") so a future format added to one side but
 * not the other fails loudly here instead of shipping unreachable.
 *
 * THREE extractions, all via token_get_all() (never a source-text regex
 * window per rule #34's test-editor-api2-contract.php lesson — regex misses
 * multi-line array literals and can't tell a string used as a dispatch
 * condition from one used as a result):
 *
 *   1. import2.php's `$formats` dropdown — array KEYS (the format value each
 *      <option> submits) — plus the file-picker's `accept` attribute.
 *   2. api2.php's `import_file` case, bounded to just that case's block
 *      (reusing tests/php/lib/dispatch_parser.php's case-locator so a
 *      `$format` string elsewhere in this ~5000-line file can never leak
 *      in — the same false-positive class dispatch_parser.php's own header
 *      documents for `case 'song'` living in the WRONG switch):
 *        - `$bodyFormats` array values,
 *        - the `match ($format) { ... }` arm CONDITIONS (never the RESULT
 *          expressions — a result like _bulkImport_processX($content, $x)
 *          must never be mistaken for a format name),
 *        - the two `elseif ($format === '…')` literals (pptx, easyworship).
 *   3. For every format the UI offers (except the synthetic 'auto'), the
 *      REAL pure parser function for that format, fed a minimal fixture,
 *      must return success. This is the assertion that actually would have
 *      caught #882: 'opensong' was never even in api2's accepted set, so a
 *      forward-assert alone (this file's step 4) already fails without ever
 *      touching a parser — but the fixture-parses-clean step (step 6) is
 *      what proves the format doesn't just LOOK routed, it WORKS.
 *
 * Fixtures live in tests/php/fixtures/single-file/ for formats with no
 * existing single-file fixture (openlp, pro6, proclaim, chordpro, ihymns).
 * videopsalm, freeshow and opensong already have dedicated fixture
 * directories from their own parser tests (test-videopsalm-parser.php,
 * fixtures/freeshow/, fixtures/opensong/) — reused here rather than
 * duplicated, per the modularity rule (CLAUDE.md: "if a shared module/asset
 * already exists, reuse it — do not duplicate").
 *
 * pptx and easyworship are NAMED, NARROW exemptions from the
 * fixture-parses-clean check: both are path/archive-based (a .pptx is a zip
 * of slide XML; EasyWorship is a SQLite Songs.db), so their "parser" is a
 * process function that opens a file path and needs real zip/sqlite
 * machinery, not a pure string-in/array-out function like every other
 * format. They still get a WIRING assertion (the process function exists
 * and is exactly the pair the elseif-literal extraction found) — this is a
 * completeness list, not a workaround: it exists so this guard cannot
 * silently start ignoring a NEW path-based format by widening a blanket
 * skip, since anything not on the list must produce a real fixture pass.
 *
 * MUTATION-TESTED before this commit (see the commit body for the full
 * transcript): (a) commenting out the 'opensong' match arm in api2.php's
 * import_file case → red; (b) adding a bogus format key to import2.php's
 * $formats dropdown → red; (c) truncating the opensong single-file
 * fixture → red; each restored → green.
 *
 *   php tests/php/test-import-format-coverage.php
 *
 * Exit status 0 = all pass, 1 = at least one assertion failed.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';
require_once __DIR__ . '/lib/dispatch_parser.php';

$passed = 0;
$failed = 0;
function aEq($actual, $expected, string $label): void
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
function aTrue($cond, string $label): void
{
    aEq((bool)$cond, true, $label);
}

/* ======================================================================
 * TOKEN-LEVEL EXTRACTORS
 * These are narrow and local to this guard on purpose: dispatch_parser.php
 * already solves the harder "which switch/if-chain owns this case label"
 * problem for the whole codebase (reused below via
 * dispatchParserCaseTokens()); the array/match-arm shape below is specific
 * to how import2.php and api2.php happen to declare their format lists, so
 * it stays here rather than growing the shared library for a one-consumer
 * shape (CLAUDE.md: "when a piece of logic exists in more than one place,
 * extract it" — this one doesn't, yet).
 * ====================================================================== */

/** Is this token an opener for bracket/paren/brace DEPTH tracking (not just
 *  the outer-most brace — see dispatchParserIsOpenBrace() for why the two
 *  string-interpolation open tokens must count too). */
function ifcIsOpen($t): bool
{
    if ($t === '(' || $t === '[') { return true; }
    return dispatchParserIsOpenBrace($t);
}
function ifcIsClose($t): bool
{
    return $t === ')' || $t === ']' || $t === '}';
}
/** Skip whitespace/comment tokens forward from $i (inclusive check at $i). */
function ifcSkipTrivia(array $toks, int $i, int $to): int
{
    while ($i <= $to && is_array($toks[$i]) && in_array($toks[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $i++;
    }
    return $i;
}

/**
 * Find the token index range [start,end] (inclusive, both braces) of a
 * `case '<label>': { ... }` block belonging to `switch ($switchVar)`.
 * Returns null if the case doesn't exist or isn't a brace-delimited block
 * (every case in api2.php's action switch is — confirmed by requiring this
 * to succeed for 'import_file' below; a change to that shape would fail
 * this guard loudly rather than silently extracting from the wrong place).
 */
function ifcCaseBlockRange(string $file, string $switchVar, string $label): ?array
{
    $toks = dispatchParserTokens($file);
    $n    = count($toks);
    foreach (dispatchParserCaseTokens($file, $switchVar) as $c) {
        if ($c['name'] !== $label) { continue; }
        $i = $c['index'];
        while ($i < $n && $toks[$i] !== ':') { $i++; }
        $i = ifcSkipTrivia($toks, $i + 1, $n - 1);
        if (($toks[$i] ?? null) !== '{') { return null; }
        $start = $i;
        $depth = 0;
        for (; $i < $n; $i++) {
            if (dispatchParserIsOpenBrace($toks[$i])) { $depth++; continue; }
            if ($toks[$i] === '}') {
                $depth--;
                if ($depth === 0) { return [$start, $i]; }
            }
        }
        return null;
    }
    return null;
}

/** Plain list array `$var = ['a', 'b', ...];` — every string VALUE at
 *  bracket-depth 1, within [$from,$to] inclusive. */
function ifcArrayValues(array $toks, int $from, int $to, string $varName): array
{
    for ($i = $from; $i <= $to; $i++) {
        if (!(is_array($toks[$i]) && $toks[$i][0] === T_VARIABLE && $toks[$i][1] === $varName)) { continue; }
        $j = ifcSkipTrivia($toks, $i + 1, $to);
        if (($toks[$j] ?? null) !== '=') { continue; }
        $j = ifcSkipTrivia($toks, $j + 1, $to);
        if (($toks[$j] ?? null) !== '[') { continue; }
        $depth = 0; $vals = [];
        for (; $j <= $to; $j++) {
            if (ifcIsOpen($toks[$j])) { $depth++; continue; }
            if (ifcIsClose($toks[$j])) { $depth--; if ($depth === 0) { return $vals; } continue; }
            if ($depth === 1 && is_array($toks[$j]) && $toks[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $vals[] = trim($toks[$j][1], "'\"");
            }
        }
        return $vals;
    }
    return [];
}

/** Keyed array `$var = ['a' => X, 'b' => Y];` — every string KEY (a literal
 *  immediately followed by `=>`, skipping trivia) at bracket-depth 1. */
function ifcArrayKeys(array $toks, int $from, int $to, string $varName): array
{
    for ($i = $from; $i <= $to; $i++) {
        if (!(is_array($toks[$i]) && $toks[$i][0] === T_VARIABLE && $toks[$i][1] === $varName)) { continue; }
        $j = ifcSkipTrivia($toks, $i + 1, $to);
        if (($toks[$j] ?? null) !== '=') { continue; }
        $j = ifcSkipTrivia($toks, $j + 1, $to);
        if (($toks[$j] ?? null) !== '[') { continue; }
        $depth = 0; $keys = [];
        for (; $j <= $to; $j++) {
            if (ifcIsOpen($toks[$j])) { $depth++; continue; }
            if (ifcIsClose($toks[$j])) { $depth--; if ($depth === 0) { return $keys; } continue; }
            if ($depth === 1 && is_array($toks[$j]) && $toks[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $k = ifcSkipTrivia($toks, $j + 1, $to);
                if (is_array($toks[$k] ?? null) && $toks[$k][0] === T_DOUBLE_ARROW) {
                    $keys[] = trim($toks[$j][1], "'\"");
                }
            }
        }
        return $keys;
    }
    return [];
}

/**
 * `match ($var) { 'a', 'b' => X, 'c' => Y }` — every string literal that is
 * a CONDITION (appears before an arm's `=>`), never a literal that appears
 * in a RESULT expression (after `=>`, before the arm-separating top-level
 * comma) — so a result like `_bulkImport_processX($content, $origName)`
 * (no literal there today, but a future one might) can never masquerade as
 * an accepted format name.
 */
function ifcMatchConditions(array $toks, int $from, int $to, string $varName): array
{
    for ($i = $from; $i <= $to; $i++) {
        if (!(is_array($toks[$i]) && $toks[$i][0] === T_MATCH)) { continue; }
        $j = ifcSkipTrivia($toks, $i + 1, $to);
        if (($toks[$j] ?? null) !== '(') { continue; }
        $j = ifcSkipTrivia($toks, $j + 1, $to);
        if (!(is_array($toks[$j] ?? null) && $toks[$j][0] === T_VARIABLE && $toks[$j][1] === $varName)) { continue; }
        while ($j <= $to && $toks[$j] !== ')') { $j++; }
        $j = ifcSkipTrivia($toks, $j + 1, $to);
        if (($toks[$j] ?? null) !== '{') { continue; }
        $j++;

        $depth = 0;             // depth of (), [], nested {} INSIDE the match body
        $state = 'condition';   // 'condition' before an arm's =>, 'result' after
        $conditions = [];
        for (; $j <= $to; $j++) {
            $t = $toks[$j];
            if ($depth === 0 && $t === '}') { return $conditions; }
            if (ifcIsOpen($t)) { $depth++; continue; }
            if (ifcIsClose($t)) { $depth--; continue; }
            if ($depth === 0 && is_array($t) && $t[0] === T_DOUBLE_ARROW) { $state = 'result'; continue; }
            if ($depth === 0 && $t === ',') { if ($state === 'result') { $state = 'condition'; } continue; }
            if ($depth === 0 && $state === 'condition' && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $conditions[] = trim($t[1], "'\"");
            }
        }
        return $conditions;
    }
    return [];
}

/** `elseif ($var === '<literal>')` string literals within [$from,$to]. */
function ifcElseifLiterals(array $toks, int $from, int $to, string $varName): array
{
    $out = [];
    for ($i = $from; $i <= $to; $i++) {
        if (!(is_array($toks[$i]) && $toks[$i][0] === T_ELSEIF)) { continue; }
        for ($k = $i + 1; $k <= min($to, $i + 20); $k++) {
            if ($toks[$k] === '{') { break; }
            if (is_array($toks[$k]) && $toks[$k][0] === T_VARIABLE && $toks[$k][1] === $varName) {
                for ($m = $k + 1; $m <= min($to, $k + 6); $m++) {
                    if (is_array($toks[$m]) && $toks[$m][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $out[] = trim($toks[$m][1], "'\"");
                        break;
                    }
                    if ($toks[$m] === ')' || $toks[$m] === '{') { break; }
                }
                break;
            }
        }
    }
    return $out;
}

/* ======================================================================
 * 1. Extract import2.php's UI surface.
 * ====================================================================== */
$import2File = dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/import2.php';
$import2Toks = dispatchParserTokens($import2File);
$uiFormats   = ifcArrayKeys($import2Toks, 0, count($import2Toks) - 1, '$formats');

/* VACUITY CHECK — if extraction silently returned nothing (a rewrite moved
   $formats, changed its shape, or a bug in the extractor itself), every
   assertion below would vacuously pass over an empty set and this guard
   would report green while checking NOTHING. Fail loud instead. The exact
   number is incidental; the point is "clearly more than zero, more than
   one, more than what a broken extractor might coincidentally return". */
aTrue(count($uiFormats) >= 5, 'vacuity check: import2.php $formats extraction found a non-trivial list (' . count($uiFormats) . ' entries)');

$import2Src = (string)file_get_contents($import2File);
aTrue((bool)preg_match('/id="imp-file"[^>]*accept="([^"]+)"/', $import2Src, $acceptMatch),
    'vacuity check: import2.php file-picker accept attribute found');
$uiAccept = array_map('trim', explode(',', $acceptMatch[1] ?? ''));

$uiFormatsToCheck = array_values(array_diff($uiFormats, ['auto']));

/* ======================================================================
 * 2. Extract api2.php's accepted single-file format set, bounded to the
 *    import_file case only.
 * ====================================================================== */
$api2File = dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/api2.php';
$range    = ifcCaseBlockRange($api2File, '$action', 'import_file');
aTrue($range !== null, "vacuity check: api2.php case 'import_file' located as a brace block");

$api2Toks     = dispatchParserTokens($api2File);
$bodyFormats  = ifcArrayValues($api2Toks, $range[0], $range[1], '$bodyFormats');
$matchConds   = ifcMatchConditions($api2Toks, $range[0], $range[1], '$format');
$elseifs      = ifcElseifLiterals($api2Toks, $range[0], $range[1], '$format');

aTrue(count($bodyFormats) >= 5, 'vacuity check: api2.php $bodyFormats extraction found a non-trivial list (' . count($bodyFormats) . ' entries)');
aTrue(count($matchConds) >= 5, 'vacuity check: api2.php match($format) condition extraction found a non-trivial list (' . count($matchConds) . ' entries)');
aEq($elseifs, ['pptx', 'easyworship'], 'api2.php elseif($format===...) literals are exactly pptx and easyworship');

/* $bodyFormats drives which formats even ENTER the match; the match arms
   are what actually dispatch them. If a format is added to one but not the
   other, that format is either dead code (in bodyFormats, no arm — a fatal
   UnhandledMatchError at runtime) or unreachable (an arm with no
   bodyFormats entry, so in_array() never lets execution reach it) — the
   exact "two files must agree with nothing enforcing it" shape rule #35
   names. Assert they are the SAME set, not just each individually
   non-trivial. */
$bodyFormatsSorted = $bodyFormats; sort($bodyFormatsSorted);
$matchCondsSorted  = $matchConds;  sort($matchCondsSorted);
aEq($bodyFormatsSorted, $matchCondsSorted, '$bodyFormats and the match($format) arms name exactly the same set (rule #35: no format is dead-listed or unreachable)');

$api2Accepted = array_values(array_unique(array_merge($bodyFormats, $elseifs)));

/* Internal-only targets: reachable via format=auto on certain extensions,
   never offered as a direct dropdown choice. Named here, not silently
   subtracted — anything ADDED to this list is a decision this test forces
   someone to make explicitly. */
$internalOnlyFormats = ['xmlauto'];

/* ======================================================================
 * 3. FORWARD assert: every UI-offered format (except 'auto') is one api2
 *    actually accepts. This is the exact shape of #882 — 'opensong' was
 *    offered nowhere in api2's accepted set before this fix.
 * ====================================================================== */
foreach ($uiFormatsToCheck as $fmt) {
    aTrue(in_array($fmt, $api2Accepted, true), "forward: UI format '$fmt' is accepted by api2.php's import_file handler");
}

/* ======================================================================
 * 4. REVERSE assert: every format api2 accepts is either offered in the UI
 *    or is a named internal-only target. Catches the opposite drift — a
 *    format the server silently accepts that no curator can ever reach
 *    from the page (the dead-code twin of the #882 bug).
 * ====================================================================== */
foreach ($api2Accepted as $fmt) {
    $reachable = in_array($fmt, $uiFormatsToCheck, true) || in_array($fmt, $internalOnlyFormats, true);
    aTrue($reachable, "reverse: api2-accepted format '$fmt' is offered in the UI or is a named internal-only target");
}

/* ======================================================================
 * 5. "Reaches a parser that can parse it" — per UI format, a fixture fed to
 *    that format's REAL pure parser must parse successfully. This is the
 *    assertion that actually proves the format WORKS, not just that its
 *    name appears wired.
 *
 *    Every entry returns bool: true = the fixture parsed cleanly.
 * ====================================================================== */
$fixtureDir = __DIR__ . '/fixtures';
$formatParsers = [
    'ihymns' => [
        'fixture' => $fixtureDir . '/single-file/ihymns.json',
        'check'   => static function (string $body): bool {
            [, $songs, $err] = _bulkImport_parseIHymnsJson($body, 'ihymns.json');
            return $err === null && is_array($songs) && count($songs) > 0;
        },
    ],
    'videopsalm' => [
        /* Reuses the existing videopsalm parser-test fixture directory
           rather than duplicating its content (modularity rule). */
        'fixture' => $fixtureDir . '/videopsalm/sample-hymnal [VPS].json',
        'check'   => static function (string $body): bool {
            [$meta, $songs] = _bulkImport_parseVideoPsalmSongbook($body, 'sample-hymnal [VPS].json');
            return $meta !== null && is_array($songs) && count($songs) > 0;
        },
    ],
    'openlp' => [
        'fixture' => $fixtureDir . '/single-file/openlp.xml',
        'check'   => static function (string $body): bool {
            [$parsed] = _bulkImport_parseOpenLyrics($body);
            return $parsed !== null;
        },
    ],
    'opensong' => [
        /* Reuses the repo's real #882 OpenSong fixture directory. */
        'fixture' => $fixtureDir . '/opensong/be-thou-my-vision.xml',
        'check'   => static function (string $body): bool {
            [$song] = _bulkImport_parseOpenSong($body, 'OS', 'OpenSong Import', 0, static fn (): int => 1);
            return $song !== null;
        },
    ],
    'pro6' => [
        'fixture' => $fixtureDir . '/single-file/pro6.pro6',
        'check'   => static function (string $body): bool {
            [$parsed] = _bulkImport_parsePro6($body);
            return $parsed !== null;
        },
    ],
    'freeshow' => [
        /* Reuses the existing FreeShow parser-test fixture directory. */
        'fixture' => $fixtureDir . '/freeshow/simple-song.show',
        'check'   => static function (string $body): bool {
            [$show] = _bulkImport_parseFreeShow($body);
            return $show !== null;
        },
    ],
    'proclaim' => [
        'fixture' => $fixtureDir . '/single-file/proclaim.txt',
        'check'   => static function (string $body): bool {
            [$parsed] = _bulkImport_parseProclaimText($body, 'proclaim.txt');
            return $parsed !== null;
        },
    ],
    'chordpro' => [
        'fixture' => $fixtureDir . '/single-file/chordpro.cho',
        'check'   => static function (string $body): bool {
            [$song] = _bulkImport_parseChordPro($body, 'CP', 'ChordPro Import', 1);
            return $song !== null;
        },
    ],
];

/* pptx / easyworship: path/archive-based, no pure string-in/array-out
   parser exists to fixture-test the way the eight above are. Named,
   narrow exemption — anything NOT on this list must have a real entry in
   $formatParsers above, so a future path-based format still has to be an
   explicit decision, not a silent gap. */
$pathBasedExemptions = [
    'pptx'        => '_bulkImport_processPptx',
    'easyworship' => '_bulkImport_processEasyWorship',
];

/* COMPLETENESS CHECK — every UI format must be covered by EITHER the
   parser map OR the named path-based exemption list. A new UI format with
   neither fails this loudly, rather than the fixture loop below silently
   skipping formats it doesn't recognise (rule #34: a scanner that
   under-reports is worse than no scanner). */
foreach ($uiFormatsToCheck as $fmt) {
    $covered = array_key_exists($fmt, $formatParsers) || array_key_exists($fmt, $pathBasedExemptions);
    aTrue($covered, "completeness: UI format '$fmt' has either a fixture-tested parser or a named path-based exemption");
}

foreach ($formatParsers as $fmt => $spec) {
    if (!in_array($fmt, $uiFormatsToCheck, true)) {
        /* Not currently offered in the UI (would already have failed the
           completeness/forward checks above) — skip running its fixture so
           a stale map entry for a retired format doesn't need its own
           fixture kept alive. */
        continue;
    }
    $fixturePath = $spec['fixture'];
    aTrue(is_file($fixturePath), "fixture exists for '$fmt': $fixturePath");
    if (!is_file($fixturePath)) { continue; }
    $body = (string)file_get_contents($fixturePath);
    aTrue(($spec['check'])($body), "'$fmt' fixture parses successfully via its real parser");
}

foreach ($pathBasedExemptions as $fmt => $fn) {
    if (!in_array($fmt, $uiFormatsToCheck, true)) { continue; }
    aTrue(function_exists($fn), "wiring-only: path-based format '$fmt' has its process function ($fn) defined");
    aTrue(in_array($fmt, $elseifs, true), "wiring-only: path-based format '$fmt' is one of api2's elseif branches");
}

/* ======================================================================
 * 6. accept attribute sanity: every UI format that maps to a real file
 *    extension should have that extension (or one of its variants)
 *    reachable through the file picker's accept list. Loose on purpose —
 *    this is a UX nicety check, not a routing contract, so it only asserts
 *    the two extensions #882 actually added (opensong's own dropdown entry
 *    is worthless if a curator's file manager filters it out of the
 *    picker).
 * ====================================================================== */
aTrue(in_array('.opensong', $uiAccept, true), 'accept: .opensong reachable through the file picker (#882)');
aTrue(in_array('.cho', $uiAccept, true), 'accept: .cho reachable through the file picker (chordpro)');

if ($failed === 0) {
    echo "\nAll import format-coverage assertions passed ($passed).\n";
    exit(0);
}
fwrite(STDERR, "\n$failed assertion(s) failed.\n");
exit(1);
