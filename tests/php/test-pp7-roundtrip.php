<?php

declare(strict_types=1);

/**
 * iHymns — ProPresenter 7+ export→import CLOSURE test (#1968 PR-1, plan §8.3c)
 * ================================================================================================
 *
 * ELI5
 * ----
 * Every other PP7 test in this repo proves ONE half of the story: the decoder/parser reads REAL
 * third-party `.pro` files correctly (`test-pp7-decode.php`, `test-pp7-parse.php`), and the exporter
 * PRODUCES structurally valid `.pro` bytes (`tests/test-propresenter-export.js`,
 * `tests/test-pp7-export-shape.js`). Neither proves the two halves of THIS APP agree with each
 * other — that a song exported by our own JS and re-imported by our own PHP comes back unchanged.
 * This file is that missing link: it builds a small song, hands it to the REAL exporter
 * (`propresenter-export.js`'s `buildPresentation()`, via `tools/pp7-gen-roundtrip-sample.js`), feeds
 * the resulting bytes into the REAL importer (`_bulkImport_parsePro7()`), and asserts the song that
 * comes back matches the one that went in.
 *
 * WHY A SEPARATE PROCESS, NOT A SELF-DECODE (the owner's #1 rule for this epic — see the plan's
 * header + §8): a same-process "encode this object, immediately decode the bytes I just wrote"
 * check proves nothing about correctness — it would happily stay green if BOTH sides shared the
 * same wrong idea of what a field means. #1918 and the first #1950 attempt shipped exactly that
 * kind of green and then failed in real ProPresenter. This test is not that: `buildPresentation()`
 * is a completely independent implementation (browser JS, protobufjs encode) from
 * `_bulkImport_parsePro7()` (hand-rolled PHP wire-walker, `includes/propresenter7_decode.php`) —
 * two different pieces of code, in two different languages, that have never seen each other's
 * source, agreeing on the same bytes. The REAL third-party fixtures (`tests/fixtures/propresenter/`,
 * validated in `test-pp7-decode.php`/`test-pp7-parse.php`) remain the ground truth for "is this
 * decoder RIGHT"; this test answers a narrower, complementary question — "do OUR OWN two halves
 * still agree with EACH OTHER" — and per the task brief's own fallback clause, if a clean
 * cross-language closure ever proved unreliable the fallback would be checking only that the
 * exporter's bytes decode through `propresenter7_decode.php` to the right STRUCTURE; that fallback
 * was not needed here — the full closure (through the actual importer, not just the decoder) works
 * cleanly and is what ships below.
 *
 * THE FIXTURE (`tools/pp7-gen-roundtrip-sample.js`'s `SAMPLE_SONG` — read that file's doc-block for
 * the full "why this shape" reasoning): a title, two writers, a year-free copyright string, a CCLI
 * number, and three components (verse 1 / chorus / verse 2, a couple of lines each). Every expected
 * value below was hand-traced through BOTH halves of the pipeline, then CONFIRMED by actually
 * running the generator + parser once at authoring time (not typed from what "should" happen in the
 * abstract — the owner's #1 rule again):
 *   - title: `buildCCLIPayload()` sets `ccli.song_title = song.title` verbatim; the importer reads
 *     `ccli.songTitle` first (its title ladder's FIRST rung) → round-trips exactly.
 *   - writers: `song.writers = ['Jane Doe', 'John Smith']`, NO composers on purpose. CCLI's `author`
 *     field is a SINGLE free-text string — `buildCCLIPayload()` joins writers with ', ' when there
 *     are no composers to combine with (`writers || composers`), and the importer splits `author` on
 *     any of the characters forward-slash, ampersand, comma or semicolon (Pro6's exact regex,
 *     Unicode mode — NOT quoted verbatim here: its literal form contains a `*` immediately before a
 *     `/`, which would close THIS block comment early) — comma IS one of the split characters, so
 *     `'Jane Doe, John Smith'` splits back into the original two-element array. Had a `composers[]`
 *     been set too, `buildCCLIPayload()` would fold it into the SAME `author` string
 *     (`writers + ' / ' + composers`) and the importer would import the composer's name as an
 *     extra "writer" — a real, documented quirk of the CCLI block's single-author-string shape, not
 *     a bug, and deliberately routed around here (not a metadata-edge-case test — that's
 *     `test-pp7-parse.php`'s job against real fixtures).
 *   - copyright: `song.copyright = '© Test Publisher'` carries NO digit run, so
 *     `buildCCLIPayload()`'s `/\b(19|20)\d{2}\b/` year-sniff never matches and `copyright_year` is
 *     omitted entirely; the importer's `$copyright` is then just `trim($publisher)` with no year
 *     suffix appended → exact round-trip. (A copyright string WITH an embedded year would still
 *     round-trip, just not byte-for-byte identical — `buildCCLIPayload()` extracts the year into its
 *     OWN field, and the importer re-appends it after the publisher string — a second real, harmless
 *     quirk this fixture also sidesteps to keep the "does the closure hold" assertion unambiguous.)
 *   - ccli: `'7654321'` → `buildCCLIPayload()` strips non-digits and stores an int; the importer
 *     casts back to string → exact round-trip.
 *   - components: three cue_groups, one cue each (no `linesPerSlide` chunking option is passed, so
 *     `buildPresentationPayload()`'s default — one cue per component — applies). The chorus carries
 *     NO explicit `number`, so `componentLabel()` emits the group name `'Chorus'` (no trailing
 *     digit); `_bulkImport_pro7GroupType('Chorus')` folds that back to `{type:'chorus', number:0}`
 *     — `number: 0` (not `null`) is this codebase's own "no number" convention, confirmed against
 *     `tests/fixtures/propresenter/expected/bussnet-test.song.json`'s numberless chorus entry. Every
 *     group's raw PP name equals its OWN derived "Type Number" display form
 *     (`_bulkImport_pro7TypeDisplayWord()`), so `label` is hidden on all three (rule #45's
 *     hide-when-equal) — none of the three expected components below carries a `label` key.
 *   - arrangement: `buildPresentationPayload()` always builds exactly ONE arrangement named
 *     "Default" listing `cue_groups` in the SAME order they were built (component order) — this
 *     exporter has no reordering support yet ("Future work" per its own comment) — so the resolved
 *     indices are always `[0,1,2]`, the identity sequence, which `_bulkImport_parsePro7()`'s §3.3
 *     point 5 rule collapses to `null` (never store a no-op arrangement). A genuinely REORDERED
 *     arrangement round-trip has no code path to reach through this exporter today and is therefore
 *     out of THIS test's scope (would need a hand-rolled synthetic `.pro`, which is exactly what
 *     `test-pp7-parse.php` §(b) already does for that class of coverage).
 *   - warnings: empty — nothing in this fixture trips a skip/unresolved-group/translation-layer/
 *     artist-credits warning.
 *
 * MUTATION-PROVEN (rule #34), each performed once by hand against the real working tree, this test
 * re-run and confirmed RED, then reverted (`git diff --stat` empty before moving on):
 *   m1 — `includes/song_importers.php`'s `_bulkImport_pro7GroupType()`: temporarily forced the
 *        'chorus' word-map entry to fold to `'refrain'` instead of `'chorus'` (simulating an
 *        exporter/importer lockstep drift, rule #35) → RED: `$.components[1] (keys differ:
 *        [type,number,lines,label] vs [type,number,lines])` — not just the type flipping to
 *        'refrain', but a SECOND, more subtle symptom: because the raw PP group name "Chorus" no
 *        longer equals `_bulkImport_pro7TypeDisplayWord('refrain')` ("Refrain"), rule #45's
 *        hide-when-equal check now finds them UNEQUAL and starts emitting a `label` key that
 *        shouldn't exist — a lockstep drift corrupts two fields at once, not one.
 *   m2 — `appWeb/public_html/manage/editor/propresenter-export.js`'s `buildCCLIPayload()`: changed
 *        the writers join separator from `', '` to `' '` (a bare space) → RED:
 *        `$.writers (keys differ: [0] vs [0,1])` — `_bulkImport_parsePro7()`'s author-split regex
 *        requires one of `/ & , ;` as a delimiter, so "Jane Doe John Smith" no longer splits and
 *        comes back as ONE writer instead of two. (An EARLIER attempt — swapping `buildRTF()`'s line
 *        separator from `\par` to `\line` — did NOT go red: `_bulkImport_rtfToText()`'s `case
 *        'line':` branch already treats `\line` as a break exactly like `\par`, so that specific
 *        swap is a genuine no-op on both sides, not a gap in this test. Recorded here rather than
 *        silently discarded, per this repo's "a scanner that under-reports is worse than no
 *        scanner" discipline — the eventual mutation chosen (m2 above) is a different, real
 *        regression class: a delimiter change breaking the CCLI author round-trip.)
 *   m3 — `includes/song_importers.php`'s arrangement-resolution `$identity` check: changed
 *        `$indices !== $identity` to `$indices === $identity` (inverting the collapse condition) →
 *        RED: `$.arrangement (got array(0,1,2), expected NULL)`.
 * Every mutation was reverted immediately after confirming red; the tree this test ships against is
 * unmodified.
 *
 * Usage:
 *   php tests/php/test-pp7-roundtrip.php
 *
 * Requires a `node` binary on PATH (only to regenerate the fixture `.pro` — the assertions
 * themselves are pure PHP). The CI job that runs the full `tests/php/*.php` glob
 * (`tools/run-php-tests.php`, via `.github/workflows/test.yml`'s "Lint & Validate" job) already sets
 * up Node earlier in the SAME job for the JS test suite, so `node` is on PATH there. The separate
 * `php-compat` matrix job runs an explicit, curated step list rather than the full glob (predates
 * this file, unrelated to it) and does not invoke this test.
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see tools/pp7-gen-roundtrip-sample.js                 the REAL exporter run against SAMPLE_SONG
 * @see appWeb/public_html/manage/editor/propresenter-export.js   buildPresentation() (exporter under test)
 * @see appWeb/public_html/includes/song_importers.php     _bulkImport_parsePro7() (importer under test)
 * @see tests/php/test-pp7-parse.php                       the real-fixture-vs-expected sibling this complements
 * @see .claude/propresenter-interop-1968-plan.md          §8.3(c) — this test's brief
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_decode.php';
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  ❌ {$label}\n";
    }
}

/** Mirrors tests/php/test-pp7-parse.php's identically-purposed helper — finds the first point at
 *  which two already-decoded values disagree, as a human-readable dotted/bracketed path, so a
 *  failure prints something actionable instead of two enormous JSON blobs. Named distinctly
 *  (pp7Roundtrip… vs pp7Parse…) because tools/run-php-tests.php runs each suite in its own PHP
 *  subprocess (no shared global namespace across files — verified in that runner's own doc-block),
 *  so a name COULD collide safely, but a distinct name keeps a stack trace unambiguous about which
 *  test file it came from. */
function pp7RoundtripFirstDiffPath($a, $b, string $path = '$'): ?string
{
    if (is_array($a) && is_array($b)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        if ($keysA !== $keysB) {
            return "{$path} (keys differ: [" . implode(',', $keysA) . '] vs [' . implode(',', $keysB) . '])';
        }
        foreach ($a as $k => $v) {
            $sub = is_int($k) ? "{$path}[{$k}]" : "{$path}.{$k}";
            $diff = pp7RoundtripFirstDiffPath($v, $b[$k], $sub);
            if ($diff !== null) {
                return $diff;
            }
        }
        return null;
    }
    if ($a === $b) {
        return null;
    }
    $av = is_string($a) && strlen($a) > 160 ? substr($a, 0, 160) . '…' : var_export($a, true);
    $bv = is_string($b) && strlen($b) > 160 ? substr($b, 0, 160) . '…' : var_export($b, true);
    return "{$path} (got {$av}, expected {$bv})";
}

echo "\n#1968 PR-1 — ProPresenter 7+ export -> import CLOSURE (our exporter -> our importer)\n\n";

/* ============================================================================================
 * (1) Generate the fixture .pro via the REAL exporter (a separate node process — see file header
 *     for why this must not be a same-process self-decode).
 * ============================================================================================ */

$repoRoot = dirname(__DIR__, 2);
$generatorScript = $repoRoot . '/tools/pp7-gen-roundtrip-sample.js';

ok('generator script exists (tools/pp7-gen-roundtrip-sample.js)', is_file($generatorScript));

$outPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'ihymns-pp7-roundtrip-' . bin2hex(random_bytes(6)) . '.pro';

$nodeVersionProbe = @shell_exec('node --version 2>&1');
ok('a `node` binary is on PATH (required to regenerate the closure fixture — see file header)',
    is_string($nodeVersionProbe) && (bool)preg_match('/^v\d+\.\d+\.\d+/', trim($nodeVersionProbe)));

$parsed = null;
$reason = null;

if ($failed === 0) {
    $cmd = escapeshellarg('node') . ' ' . escapeshellarg($generatorScript) . ' ' . escapeshellarg($outPath) . ' 2>&1';
    $cmdOutputLines = [];
    $exitCode = 1;
    exec($cmd, $cmdOutputLines, $exitCode);

    ok('generator exits 0 (output: ' . implode(' | ', $cmdOutputLines) . ')', $exitCode === 0);
    ok('generator wrote the fixture .pro file', is_file($outPath));

    if ($exitCode === 0 && is_file($outPath)) {
        $body = file_get_contents($outPath);
        ok('generated .pro is non-empty', $body !== false && strlen($body) > 0);

        if ($body !== false && strlen($body) > 0) {
            /* ========================================================================================
             * (2) Parse it back with the REAL importer.
             * ======================================================================================== */
            [$parsed, $reason] = _bulkImport_parsePro7($body);
        }
    }

    @unlink($outPath);
}

ok('_bulkImport_parsePro7() parses the generated .pro successfully'
    . ($parsed === null ? ' (got failure: ' . ($reason ?? 'null') . ')' : ''),
    $parsed !== null);

/* ============================================================================================
 * (3) Assert the round-tripped song equals the known synthetic source — see the file header's
 *     field-by-field trace for why each expected value is exactly this.
 * ============================================================================================ */

if ($parsed !== null) {
    $expected = [
        'title'        => 'Test Roundtrip Song',
        'songbookName' => '',
        'entry'        => 0,
        'language'     => '',
        'ccli'         => '7654321',
        'copyright'    => '© Test Publisher',
        'writers'      => ['Jane Doe', 'John Smith'],
        'components'   => [
            [
                'type'   => 'verse',
                'number' => 1,
                'lines'  => ['Amazing grace how sweet the sound', 'That saved a wretch like me'],
            ],
            [
                'type'   => 'chorus',
                'number' => 0,
                'lines'  => ['This is the chorus first line', 'This is the chorus second line'],
            ],
            [
                'type'   => 'verse',
                'number' => 2,
                'lines'  => ['I once was lost but now am found', 'Was blind but now I see'],
            ],
        ],
        'arrangement'  => null,
        'warnings'     => [],
    ];

    $diff = pp7RoundtripFirstDiffPath($parsed, $expected);
    ok('round-tripped song matches the known synthetic source'
        . ($diff !== null ? " [first diff at {$diff}]" : ''),
        $diff === null);
} else {
    ok('round-tripped song matches the known synthetic source (skipped — parse failed above)', false);
}

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA failure here means our OWN exporter and our OWN importer have drifted apart from each\n";
    echo "other — a song this app exports no longer comes back the way it went in. This is\n";
    echo "independent of whether either half is individually correct against real ProPresenter\n";
    echo "files (test-pp7-decode.php / test-pp7-parse.php / test-pp7-export-shape.js cover that).\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ export->import closure assertions passed.\n";
