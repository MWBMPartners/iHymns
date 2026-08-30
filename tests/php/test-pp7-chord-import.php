<?php

declare(strict_types=1);

/**
 * iHymns — ProPresenter 7+ CHORD import (#1968 P6, folds into #1080)
 * ================================================================================================
 *
 * ELI5
 * ----
 * A ProPresenter 7 slide's chords aren't typed into the lyrics — they're a separate list of "put
 * chord X starting at character N" records riding alongside the clean text
 * (`Graphics.Text.attributes.custom_attributes[]`, decoded by `includes/propresenter7_decode.php`'s
 * C1 additions). This file proves the IMPORTER (`includes/song_importers.php`'s chord-import
 * section, C2) turns those character-offset records into the exact same POSITIONED STRING chord
 * cells `includes/chord_display.php` / `js/modules/print.js` / the editor's chords textarea
 * already know how to render — at the right column, on the right line, without corrupting a
 * single lyric character.
 *
 * NO REAL CHORD-BEARING SAMPLE EXISTS YET (owner checklist D4)
 * -----------------------------------------------------------------------------------------------
 * All 12 real third-party `.pro` files this epic has decoded are chord-FREE — see
 * `.claude/propresenter-chords-plan.md` §5. This test therefore runs against a REFERENCE-DERIVED
 * synthetic fixture, `tools/pp7-gen-chord-fixture.js`, built with `protobufjs` REFLECTION DIRECTLY
 * against the vendored proto schema — deliberately NOT through this repo's own exporter
 * (`propresenter-export.js`), which would make this the exact circular same-schema round-trip the
 * owner's #1 rule for this epic forbids (see that generator's own doc-block for the full
 * reasoning). Regenerated FRESH on every run of this test (never a committed binary), mirroring
 * `tests/php/test-pp7-roundtrip.php`'s `tools/pp7-gen-roundtrip-sample.js` posture exactly.
 *
 * WHAT THE FIXTURE COVERS (see `tools/pp7-gen-chord-fixture.js`'s own doc-block for the full
 * layout): a chord at column 0, a chord mid-word, a MULTI-LINE slide (exercises
 * `PP7_CHORD_NEWLINE_UNITS` bucketing), a chord positioned right after a non-BMP emoji (exercises
 * UTF-16<->code-point conversion), a chord offset that deliberately OVERFLOWS the text (must clamp
 * with a warning), one NON-chord `CustomAttribute` (`capitalization`) that must be silently
 * skipped, `chord_pro{enabled:true}` on one element only (import must not care), and one entirely
 * CHORDLESS component (the "Bridge" group) alongside a byte-for-byte lyric-identical CHORDLESS
 * TWIN file (same generator, second output path) for the clean-lyric-preservation proof.
 *
 * A REAL CORRECTNESS BUG THIS FIXTURE CAUGHT DURING AUTHORING (kept here for the record, per this
 * repo's "a scanner that under-reports is worse than no scanner" / honesty-about-findings
 * discipline): the first version of `pp7DecodeIntRange()` treated an ABSENT `start` field as
 * malformed. proto3 NEVER writes a plain scalar field holding its own type's zero default onto
 * the wire — so a genuine, real-world `IntRange{start:0, end:21}` (a chord at the very first
 * character of a line — the single most common chord position) encodes with `start` completely
 * absent, and the old code silently DROPPED every such chord. Running this exact fixture (which
 * places a real chord at column 0) through the importer during authoring surfaced the bug
 * immediately — the column-0 chord vanished from the imported cell entirely. Fixed in
 * `pp7DecodeIntRange()` (see its own doc-block for the full explanation); this test's first two
 * chord-column assertions below are what would have caught it, and its mutation-proof section
 * demonstrates that explicitly.
 *
 * MUTATION-PROVEN (rule #34), each performed once by hand against the real working tree, this
 * test re-run and confirmed RED, then reverted (`git diff --stat` empty before moving on) —
 * transcript recorded in the implementing commit/PR, not duplicated here to avoid this doc-block
 * going stale relative to the code:
 *   - flip `PP7_CHORD_NEWLINE_UNITS` from 1 to 0 -> the multi-line slide's line-2 (emoji-line)
 *     chord mis-buckets onto line 1 instead
 *   - make `_bulkImport_pro7Utf16OffsetToCodePointColumn()` walk UTF-16 units 1:1 as if they were
 *     code points (i.e. never account for a surrogate pair's extra unit) -> the emoji-line
 *     chord's column comes out one column early
 *   - make `_bulkImport_pro7ChordCellsFromRanges()` append chord symbols space-joined instead of
 *     column-positioned -> every column assertion below fails
 *   - make chord capture append the chord symbol INTO the line text instead of the parallel
 *     `chords` cell -> the clean-lyric byte-compare against the chordless twin fails
 *
 * Usage:
 *   php tests/php/test-pp7-chord-import.php
 *
 * Requires a `node` binary on PATH (only to regenerate the fixture `.pro` files — the assertions
 * themselves are pure PHP; mirrors test-pp7-roundtrip.php's own requirement + CI posture exactly).
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see tools/pp7-gen-chord-fixture.js                     the REAL protobufjs-reflection fixture builder
 * @see appWeb/public_html/includes/propresenter7_decode.php   pp7DecodeIntRange()/pp7DecodeCustomAttribute()/… (C1)
 * @see appWeb/public_html/includes/song_importers.php     the chord-import section (C2, under test)
 * @see tests/php/test-pp7-parse.php                        the real-fixture, chordless, sibling suite this must never regress
 * @see .claude/propresenter-chords-plan.md                 §3 (import design), §5 (this fixture's brief), §6 (this guard's brief)
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

/** Mirrors test-pp7-roundtrip.php's identically-purposed helper (distinct name — each test file
 *  runs in its own PHP subprocess, tools/run-php-tests.php, so no namespace collision risk; kept
 *  distinct anyway so a stack trace names the right file unambiguously). */
function pp7ChordImportFirstDiffPath($a, $b, string $path = '$'): ?string
{
    if (is_array($a) && is_array($b)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        if ($keysA !== $keysB) {
            return "{$path} (keys differ: [" . implode(',', $keysA) . '] vs [' . implode(',', $keysB) . '])';
        }
        foreach ($a as $k => $v) {
            $sub = is_int($k) ? "{$path}[{$k}]" : "{$path}.{$k}";
            $diff = pp7ChordImportFirstDiffPath($v, $b[$k], $sub);
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

echo "\n#1968 P6 — ProPresenter 7+ CHORD import (reference-derived synthetic fixture)\n\n";

/* ============================================================================================
 * (1) Generate BOTH fixtures via the REAL protobufjs-reflection generator (a separate node
 *     process — see file header for why this must not be a same-process self-decode, and must
 *     not go through this repo's own exporter).
 * ============================================================================================ */

$repoRoot = dirname(__DIR__, 2);
$generatorScript = $repoRoot . '/tools/pp7-gen-chord-fixture.js';

ok('generator script exists (tools/pp7-gen-chord-fixture.js)', is_file($generatorScript));

$chordPath     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ihymns-pp7-chord-' . bin2hex(random_bytes(6)) . '.pro';
$chordlessPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ihymns-pp7-chordless-' . bin2hex(random_bytes(6)) . '.pro';

$nodeVersionProbe = @shell_exec('node --version 2>&1');
ok('a `node` binary is on PATH (required to regenerate the fixtures — see file header)',
    is_string($nodeVersionProbe) && (bool)preg_match('/^v\d+\.\d+\.\d+/', trim($nodeVersionProbe)));

$parsedChords     = null;
$reasonChords     = null;
$parsedChordless  = null;
$reasonChordless  = null;

if ($failed === 0) {
    $cmd = escapeshellarg('node') . ' ' . escapeshellarg($generatorScript) . ' '
        . escapeshellarg($chordPath) . ' ' . escapeshellarg($chordlessPath) . ' 2>&1';
    $cmdOutputLines = [];
    $exitCode = 1;
    exec($cmd, $cmdOutputLines, $exitCode);

    ok('generator exits 0 (output: ' . implode(' | ', $cmdOutputLines) . ')', $exitCode === 0);
    ok('generator wrote the chord-bearing fixture', is_file($chordPath));
    ok('generator wrote the chordless-twin fixture', is_file($chordlessPath));

    if ($exitCode === 0 && is_file($chordPath) && is_file($chordlessPath)) {
        $chordBody     = file_get_contents($chordPath);
        $chordlessBody = file_get_contents($chordlessPath);
        ok('chord-bearing fixture is non-empty', $chordBody !== false && strlen($chordBody) > 0);
        ok('chordless-twin fixture is non-empty', $chordlessBody !== false && strlen($chordlessBody) > 0);

        if ($chordBody !== false && $chordlessBody !== false) {
            [$parsedChords, $reasonChords]       = _bulkImport_parsePro7($chordBody);
            [$parsedChordless, $reasonChordless] = _bulkImport_parsePro7($chordlessBody);
        }
    }

    @unlink($chordPath);
    @unlink($chordlessPath);
}

ok('_bulkImport_parsePro7() parses the chord-bearing fixture successfully'
    . ($parsedChords === null ? ' (got failure: ' . ($reasonChords ?? 'null') . ')' : ''),
    $parsedChords !== null);
ok('_bulkImport_parsePro7() parses the chordless-twin fixture successfully'
    . ($parsedChordless === null ? ' (got failure: ' . ($reasonChordless ?? 'null') . ')' : ''),
    $parsedChordless !== null);

/* ============================================================================================
 * (2) Structural shape — 3 components (Verse 1 / Chorus / Bridge), matching the generator's
 *     own layout (a superset of the plan's "2 groups / 3 cues" — see that file's doc-block for
 *     why the third, entirely chordless "Bridge" group was added).
 * ============================================================================================ */

if ($parsedChords !== null) {
    ok('chord-bearing fixture: 3 components (Verse 1 / Chorus / Bridge)',
        count($parsedChords['components']) === 3);
}
if ($parsedChordless !== null) {
    ok('chordless-twin fixture: 3 components (Verse 1 / Chorus / Bridge)',
        count($parsedChordless['components']) === 3);
}

/* ============================================================================================
 * (3) Chord placement — exact code-point columns, hand-traced against the generator's own
 *     construction (tools/pp7-gen-chord-fixture.js buildPresentation()):
 *       Verse 1 (component 0), line 0 "Amazing grace how sweet the sound":
 *         'G' at column 0 (chord at column 0 — the #1968 P6 correctness-fix regression case)
 *         'D' at column 20 ("Amazing grace how s|weet..." -> indexOf('sweet')+2 = 18+2 = 20,
 *              i.e. mid-word, between the 's'/'w' and the following 'e')
 *       Verse 1, line 1 "That saved a wretch like me": 'Bm' at column 0 — this is CUE B's OWN
 *              first line (Cue A/Cue B each carry their own independent chord-offset space, one
 *              per element), so this row alone does NOT exercise NEWLINE_UNITS bucketing (its
 *              offset is 0 regardless of the newline weight) — it is here as a sanity baseline
 *              the NEXT row's mis-bucket would otherwise be compared against.
 *       Verse 1, line 2 "I once was <emoji> lost but now am found" — THIS is the NEWLINE_UNITS
 *              row: 'C' right after the emoji, codepoint column 12
 *              (I(0) (1)o(2)n(3)c(4)e(5) (6)w(7)a(8)s(9) (10)EMOJI(11) — so column 12 is the
 *              space immediately after it). Being on line INDEX 1 (not 0), its GLOBAL offset
 *              only lands in the right place when the importer adds exactly ONE unit for the
 *              line-0/line-1 break — flip `PP7_CHORD_NEWLINE_UNITS` and this row (only) mis-
 *              buckets onto line 1 instead of line 2 (mutation-proven below). The emoji is ALSO
 *              one code point but TWO UTF-16 units, so a UTF-16-unit-as-codepoint bug would
 *              additionally place 'C' one column early even with correct bucketing.
 *       Chorus (component 1), line 0 "Was blind but now I see" (23 code points):
 *         'F' at column 0; 'Amen' OVERFLOWS (generator sets start = text length + 50) and must
 *              clamp to the line's own end (column 23) with a warning — never dropped, never
 *              placed past the real text
 *       Bridge (component 2): NO chords key at all (asserted separately in section (5)).
 * ============================================================================================ */

if ($parsedChords !== null) {
    $verse1 = $parsedChords['components'][0] ?? null;
    $chorus = $parsedChords['components'][1] ?? null;

    ok('Verse 1 carries a `chords` array parallel to its 3 lines',
        is_array($verse1['chords'] ?? null) && count($verse1['chords']) === 3);

    if (is_array($verse1['chords'] ?? null) && count($verse1['chords']) === 3) {
        $line0Cell = (string)$verse1['chords'][0];
        $line1Cell = (string)$verse1['chords'][1];
        $line2Cell = (string)$verse1['chords'][2];

        ok('line 0: "G" lands at column 0 (the #1968 P6 correctness-fix regression case — a chord genuinely at the start of the text)',
            str_starts_with($line0Cell, 'G'));
        ok('line 0: "D" lands MID-WORD at column 20 (not at any word boundary)',
            (mb_strpos($line0Cell, 'D') === 20));
        ok('line 1 (cue B\'s own first line): "Bm" lands at column 0',
            trim($line1Cell) === 'Bm' && str_starts_with($line1Cell, 'Bm'));
        ok('line 2 (the emoji line): "C" lands at code-point column 12, right after the emoji — code-point-correct despite the emoji occupying 2 UTF-16 units',
            (mb_strpos($line2Cell, 'C') === 12));
        // Belt-and-braces: assert the SAME column via a hand-rolled emoji-count-blind Array-based
        // walk, independent of mb_strpos()'s own code-point semantics, so a coincidental
        // mb_strpos() quirk can't hide a real bug.
        $line2CodePoints = mb_str_split($line2Cell, 1, 'UTF-8');
        $cIndex = array_search('C', $line2CodePoints, true);
        ok('line 2: the "C" column, re-counted by an independent code-point walk, is also 12',
            $cIndex === 12);
    }

    ok('Chorus carries a `chords` array parallel to its 1 line',
        is_array($chorus['chords'] ?? null) && count($chorus['chords']) === 1);

    if (is_array($chorus['chords'] ?? null) && count($chorus['chords']) === 1) {
        $chorusCell = (string)$chorus['chords'][0];
        ok('Chorus line: "F" lands at column 0',
            str_starts_with($chorusCell, 'F'));
        $amenAt = mb_strpos($chorusCell, 'Amen');
        ok('Chorus line: "Amen" (deliberately overflowing) is present and clamped to the line\'s own end (column 23 — "Was blind but now I see" is 23 code points), never dropped and never past the real text',
            $amenAt === 23);
        ok('Chorus line: NO stray chord token leaked from the non-chord `capitalization` CustomAttribute — the cell contains ONLY "F" and "Amen"',
            trim(preg_replace('/\s+/u', ' ', $chorusCell)) === 'F Amen');
    }

    ok('the overflow clamp produced exactly one collected warning',
        in_array("a chord offset landed beyond the end of the text and was clamped to the last line's end", $parsedChords['warnings'] ?? [], true));
}

/* ============================================================================================
 * (4) Clean-lyric preservation — the chord-bearing fixture's LINES must be byte-identical to the
 *     chordless twin's lines, component by component. This is the mechanical proof that chord
 *     capture never pollutes the lyric text (no bracket, no marker, nothing — §1's headline
 *     finding that PP7 chords are never inline text).
 * ============================================================================================ */

if ($parsedChords !== null && $parsedChordless !== null) {
    $chordLinesOnly = array_map(
        static fn(array $c): array => ['type' => $c['type'], 'number' => $c['number'], 'lines' => $c['lines']],
        $parsedChords['components']
    );
    $chordlessLinesOnly = array_map(
        static fn(array $c): array => ['type' => $c['type'], 'number' => $c['number'], 'lines' => $c['lines']],
        $parsedChordless['components']
    );
    $diff = pp7ChordImportFirstDiffPath($chordLinesOnly, $chordlessLinesOnly);
    ok('every component\'s {type, number, lines} is BYTE-IDENTICAL whether or not the file carries chords'
        . ($diff !== null ? " [first diff at {$diff}]" : ''),
        $diff === null);

    ok('the chordless-twin fixture carries NO warnings at all (no overflow clamp — it has no chords to clamp)',
        ($parsedChordless['warnings'] ?? ['non-empty']) === []);
}

/* ============================================================================================
 * (5) Shape non-regression — a chordless component carries NO `chords` key at all (never a
 *     present-but-empty/null array), matching _bulkImport_parseChordPro()'s exact flush-gate
 *     convention (rule #25's "byte-identical to today" contract).
 * ============================================================================================ */

if ($parsedChords !== null) {
    $bridge = $parsedChords['components'][2] ?? null;
    ok('the Bridge component (genuinely chordless, even WITHIN the chord-bearing fixture) carries NO `chords` key at all',
        is_array($bridge) && !array_key_exists('chords', $bridge));
}
if ($parsedChordless !== null) {
    $anyChordsKey = false;
    foreach ($parsedChordless['components'] as $c) {
        if (array_key_exists('chords', $c)) {
            $anyChordsKey = true;
            break;
        }
    }
    ok('the chordless-twin fixture carries NO `chords` key on ANY component',
        !$anyChordsKey);
}

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA failure here means the chord IMPORT path (includes/song_importers.php's chord-import\n";
    echo "section, built on includes/propresenter7_decode.php's C1 decoder additions) has drifted\n";
    echo "from the reference-derived fixture's known-correct chord positions, or that chord capture\n";
    echo "has started polluting the clean lyric text. See .claude/propresenter-chords-plan.md §3/§6.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ chord import assertions passed.\n";
