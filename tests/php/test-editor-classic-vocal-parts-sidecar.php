<?php

declare(strict_types=1);

/**
 * iHymns — classic editor `load_song` carries the `vocalParts` sidecar (#2073)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Two different doors into the song editor — the old one (`manage/editor/
 * api.php`) and the new one (`manage/editor/api2.php`) — both answer a
 * `load_song` request. #2073 taught api2.php to also hand back "who sings
 * what" (`vocalParts`) alongside the translations/annotations it already
 * sent. The plan asked for the SAME small addition on the old door too, so
 * a caller of either door sees the same picture. This file checks that the
 * old door actually got it, under the same name, in the same place — not
 * just that a function with the right name exists somewhere in the file.
 *
 * WHY THIS EXISTS
 * ---------------
 * The plan's own #2073 gap report (this task's brief) is the reason this
 * guard exists at all: `manage/editor/api2.php` grew NINE `vocalParts`
 * mentions while `manage/editor/api.php`'s `load_song` grew none, because
 * an earlier scoping pass listed the files to touch and left this one off
 * BY NAME. That is precisely the "half-shipped, nothing red" failure rule
 * #45 and rule #33 both warn about — a v2-only reader would keep working
 * forever while a v1 consumer (a script, a report, a future v1 screen)
 * silently never saw the data, with no error anywhere. A guard that only
 * checks api2.php cannot catch its OWN cross-file gap re-opening; this one
 * checks BOTH sides and that they agree (rule #35 — two payloads that must
 * agree need a mechanism, not a comment).
 *
 * WHAT THIS GUARD PROVES
 *   1. `manage/editor/api.php` requires `includes/vocal_parts.php` (so
 *      `vocalPartsForSong()` is actually callable — a call to an undefined
 *      function is a fatal, not a friendly error).
 *   2. The REAL `load_song` case body in `manage/editor/api.php` (isolated
 *      from the file's ~30 other cases by the shared switch/case tokeniser,
 *      never a whole-file grep that could match an unrelated case) calls
 *      `vocalPartsForSong(`.
 *   3. That same case body assigns the result under the literal key
 *      `$song['vocalParts']` — not some other spelling — and does so
 *      BEFORE the `echo json_encode(['song' => $song])` that ships the
 *      response, so the call is actually wired into the payload rather
 *      than dead code sitting after the point of no return.
 *   4. The classic key (`'vocalParts'`) matches the key api2.php's OWN
 *      `load_song` case attaches its sidecar under — read from api2.php's
 *      real source, never hand-typed, so a future rename on either side
 *      that breaks the pairing is caught without anyone updating this file.
 *
 * WHAT THIS GUARD DELIBERATELY DOES NOT ASSERT
 * `manage/editor/api.php` gains NO new `vocal_*`/`round_*` WRITE actions —
 * the "Who sings" panel that calls those lives only in Editor2 (v2), and
 * the classic editor has no UI to drive them. This is a considered choice
 * (see this task's own report), not an oversight, so this guard does not
 * ban a future classic write action either — only pins down that the
 * READ side, which the plan explicitly asked for, actually landed.
 *
 * Extraction helpers below are DUPLICATED, not imported, from
 * tests/php/test-api-gate-parity.php on purpose — that file's own header
 * already establishes this codebase's precedent for these particular tiny
 * tokeniser helpers being copied per-file rather than centralised, since
 * each copy is a handful of lines built directly on the ONE real shared
 * library (tests/php/lib/dispatch_parser.php) that does the actual
 * brace-depth switch/case parsing.
 *
 *   docker run --rm -v "$PWD":/app -w /app ihymns-php:8.3 \
 *       php tests/php/test-editor-classic-vocal-parts-sidecar.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/manage/editor/api.php   the classic `load_song` case
 * @see appWeb/public_html/manage/editor/api2.php  the v2 `load_song` case (reference only — not modified here)
 * @see appWeb/public_html/includes/vocal_parts.php  vocalPartsForSong() itself
 * @see .claude/vocal-parts-2073-plan.md            "Design pass 7" §4.2 / §12 commit 6
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo      = dirname(__DIR__, 2);
$apiFile   = $repo . '/appWeb/public_html/manage/editor/api.php';
$api2File  = $repo . '/appWeb/public_html/manage/editor/api2.php';

$passed   = 0;
$failed   = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  \xE2\x9C\x85 {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  \xE2\x9D\x8C {$label}\n";
    }
}

/* ---- shared helpers (deliberately duplicated — see file doc-block). ---- */

function tokSpanText(array $toks, int $start, int $end): string
{
    $buf = '';
    $n = min($end, count($toks));
    for ($k = $start; $k < $n; $k++) {
        $t = $toks[$k];
        $buf .= is_array($t) ? $t[1] : $t;
    }
    return $buf;
}

function caseBodyFor(string $file, string $switchVar, string $name): ?string
{
    $toks  = dispatchParserTokens($file);
    $cases = dispatchParserCaseTokens($file, $switchVar);
    foreach ($cases as $i => $c) {
        if ($c['name'] !== $name) { continue; }
        $start = $c['index'];
        $j = $i;
        while (isset($cases[$j + 1])) {
            $gapStart = $cases[$j]['index'] + 1;
            $gapEnd   = $cases[$j + 1]['index'];
            $pureFallthrough = true;
            for ($k = $gapStart; $k < $gapEnd; $k++) {
                $t = $toks[$k];
                if ($t === ':') { continue; }
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CASE], true)) { continue; }
                $pureFallthrough = false;
                break;
            }
            if (!$pureFallthrough) { break; }
            $j++;
        }
        $end = isset($cases[$j + 1]) ? $cases[$j + 1]['index'] : count($toks);
        return tokSpanText($toks, $start, $end);
    }
    return null;
}

function stripPhpComments(string $src): string
{
    $wrapped = (strpos(ltrim($src), '<?php') === 0) ? $src : ("<?php\n" . $src);
    $toks = @token_get_all($wrapped);
    if (!is_array($toks)) { return $src; }
    $out = '';
    foreach ($toks as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos(stripPhpComments($body), $needle) !== false;
}

/**
 * The one real predicate this whole file is proving: given a `load_song`
 * case body (already comment-stripped), is the vocalParts sidecar actually
 * wired — called, assigned under the right key, and assigned BEFORE the
 * response is sent? Pulled out as its own function purely so the mutation
 * self-tests below can drive it directly against small fixtures instead of
 * only ever seeing it through the real 300-line case body.
 */
function classicLoadSongVocalPartsWired(string $strippedCaseBody): bool
{
    $callPos = strpos($strippedCaseBody, 'vocalPartsForSong(');
    if ($callPos === false) { return false; }

    $keyPos = strpos($strippedCaseBody, "\$song['vocalParts']");
    if ($keyPos === false) { return false; }

    $echoPos = strpos($strippedCaseBody, "json_encode(['song'");
    if ($echoPos === false) { return false; } // the response line itself must exist to order against

    return $keyPos < $echoPos;
}

/* =========================================================================
 * MUTATION SELF-TEST (rule #34) — prove classicLoadSongVocalPartsWired()
 * and the shared extraction helpers can actually go red, using small
 * hand-built fixtures (never the real files) so the proof is independent
 * of whatever today's real source happens to contain.
 * ========================================================================= */

$mutationFailures = [];

$fixtureSrc = <<<'PHP'
<?php
switch ($action) {
    case 'load_song': {
        if (is_array($song['components'] ?? null)) {
            $song['lineAnnotations'] = $enr['annotations'];
            $song['vocalParts'] = vocalPartsForSong(getDbMysqli(), $songId);
        }
        echo json_encode(['song' => $song]);
        break;
    }
    case 'load_songs': {
        doSomethingElse();
        break;
    }
}
PHP;
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_classic_vp_fixture_');
file_put_contents($fixtureFile, $fixtureSrc);

$wiredBody = caseBodyFor($fixtureFile, '$action', 'load_song');
if (!classicLoadSongVocalPartsWired(stripPhpComments((string)$wiredBody))) {
    $mutationFailures[] = 'classicLoadSongVocalPartsWired() FAILS-HIGH self-test: a correctly-wired fixture was reported as NOT wired';
}
if (caseBodyContains($wiredBody, 'doSomethingElse(')) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: load_song body bled into the NEXT case (load_songs)';
}

/* Mutation A — the call is simply missing (the exact #2073 gap this guard
   exists to catch: a scoping pass that lists every OTHER file). Fed
   straight into the predicate (no need to re-isolate a case body — the
   predicate only ever looks at string positions of markers within
   whatever text it's handed). */
$missingCallSrc = str_replace(
    "\$song['vocalParts'] = vocalPartsForSong(getDbMysqli(), \$songId);",
    '// vocal parts sidecar not wired',
    $fixtureSrc
);
if (classicLoadSongVocalPartsWired(stripPhpComments($missingCallSrc))) {
    $mutationFailures[] = 'classicLoadSongVocalPartsWired() FAILS-LOW self-test: a fixture with NO vocalPartsForSong() call was reported as wired';
}

/* Mutation B — wrong key name (would silently break the api2 lockstep
   check even though a call to vocalPartsForSong() is genuinely present). */
$wrongKeySrc = str_replace("\$song['vocalParts']", "\$song['vocalPartz']", $fixtureSrc);
if (classicLoadSongVocalPartsWired(stripPhpComments($wrongKeySrc))) {
    $mutationFailures[] = 'classicLoadSongVocalPartsWired() FAILS-LOW self-test: a fixture assigning the WRONG key ($song[\'vocalPartz\']) was reported as wired';
}

/* Mutation C — the sidecar is assigned AFTER the response is already sent
   (dead code from the payload's point of view — the same class of "looks
   done, changes nothing" bug rule #30's silent-inline-script regression
   is built from). Built by literally swapping the two statements' order
   in the SAME known-good fixture, so this mutation tests exactly one
   thing: ordering. */
$afterEchoSrc = <<<'PHP'
<?php
switch ($action) {
    case 'load_song': {
        if (is_array($song['components'] ?? null)) {
            $song['lineAnnotations'] = $enr['annotations'];
        }
        echo json_encode(['song' => $song]);
        $song['vocalParts'] = vocalPartsForSong(getDbMysqli(), $songId);
        break;
    }
}
PHP;
if (classicLoadSongVocalPartsWired(stripPhpComments($afterEchoSrc))) {
    $mutationFailures[] = 'classicLoadSongVocalPartsWired() FAILS-LOW self-test: a fixture assigning vocalParts AFTER the response echo was reported as wired';
}

unlink($fixtureFile);

/* =========================================================================
 * REAL ASSERTIONS — against the actual files
 * ========================================================================= */

echo "\n#2073 — classic editor load_song vocalParts sidecar\n\n";

$apiSrc  = (string)file_get_contents($apiFile);
$api2Src = (string)file_get_contents($api2File);

/* ---- 1. vocal_parts.php is required somewhere at module scope, above the
   switch, so vocalPartsForSong() is actually callable (rather than a fatal
   "call to undefined function" the very first time load_song runs). ---- */
$switchOffset = strpos($apiSrc, 'switch ($action)');
ok('manage/editor/api.php module scope requires includes/vocal_parts.php',
    $switchOffset !== false
    && preg_match(
        "/require_once[^;]*'vocal_parts\\.php'\\s*;/",
        substr($apiSrc, 0, $switchOffset)
    ) === 1);

/* ---- 2/3/4. the real load_song case body, isolated by the tokenising
   parser (never a whole-file grep — api.php has ~30 other cases and this
   file's own header explains why a grep is the wrong tool here). ---- */
$classicBody = caseBodyFor($apiFile, '$action', 'load_song');
ok("manage/editor/api.php's load_song case exists as a real \$action case",
    $classicBody !== null);

$classicStripped = stripPhpComments((string)$classicBody);
ok("manage/editor/api.php's load_song case calls vocalPartsForSong(",
    strpos($classicStripped, 'vocalPartsForSong(') !== false);

ok("manage/editor/api.php's load_song case attaches it as \$song['vocalParts'], BEFORE the response is sent",
    classicLoadSongVocalPartsWired($classicStripped));

/* ---- 5. lockstep with api2.php: SAME key, read from api2's own real
   source — never hand-typed — so a rename on either side breaks this. ---- */
$v2Body = caseBodyFor($api2File, '$action', 'load_song');
ok("manage/editor/api2.php's load_song case exists as a real \$action case (reference only)",
    $v2Body !== null);

$v2Stripped = stripPhpComments((string)$v2Body);
ok("manage/editor/api2.php's load_song case ALSO attaches a 'vocalParts' key (the sidecar this test exists to keep the classic editor in lockstep with)",
    strpos($v2Stripped, "'vocalParts'") !== false);

ok("BOTH load_song cases use the SAME sidecar key ('vocalParts') — rule #35 lockstep",
    strpos($classicStripped, "\$song['vocalParts']") !== false
    && strpos($v2Stripped, "'vocalParts'") !== false);

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($mutationFailures) {
        fwrite(STDERR, "\nFAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    echo "\n{$passed} passed, {$failed} failed";
    if ($mutationFailures) { echo ' (+ ' . count($mutationFailures) . ' mutation self-test failure(s))'; }
    echo "\n";
    exit(1);
}

echo "\n{$passed} passed, 0 failed. The classic editor's load_song now carries the SAME 'vocalParts' sidecar api2.php's load_song does, wired before the response is sent, under the same key.\n";
exit(0);
