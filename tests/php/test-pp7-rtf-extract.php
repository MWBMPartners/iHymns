<?php

declare(strict_types=1);

/**
 * iHymns — dual-dialect RTF extractor truth table (#1968 PR-1, plan §3.6)
 * ================================================================================================
 *
 * ELI5
 * ----
 * `_bulkImport_rtfToText()` (in `includes/song_importers.php`) turns ProPresenter/Pro6/
 * EasyWorship/Proclaim's RTF lyric text into plain UTF-8 lines. Real ProPresenter 7+ files come
 * in TWO dialects (Mac `\cocoartf…` with a backslash+newline "soft return" as its line break,
 * Windows `\rtf0…` with `\par`) that the pre-#1968 code did not handle correctly for three
 * specific escapes, PLUS a fourth, later, PR-1 correctness-defect fix: two real Mac-exported
 * fixtures merge a small-font attribution/artifact run onto the front of the real lyric run in the
 * SAME `rtf_data`, which this function now filters out via dominant-(largest-)font selection. This
 * file is the truth table for all FOUR targeted fixes (plan §3.6 for the first three; the epic
 * #1968 PR-1 correctness-defect fix for the fourth) — every "real" row's RTF bytes are lifted
 * directly from a committed fixture at test-run time (via the already-cross-validated
 * `pp7DecodePresentation()`, never retyped from memory — see each row's comment for exactly which
 * fixture/cue/element it slices), plus a handful of clearly labelled SYNTHETIC rows for mechanisms
 * no committed real fixture happens to exercise (cp1252's 0x80-0x9F block, a supplementary-plane
 * surrogate pair, the Cocoa LINE SEPARATOR idiom) — mixing real-lifted and synthetic rows is the
 * same posture the plan's own §3.6 truth table takes.
 *
 * NON-REGRESSION (the second job this file does): all four changes are to a function SHARED by
 * Pro6 / EasyWorship / Proclaim, not just the new ProPresenter-7 code (CLAUDE.md modularity rule
 * — extend, don't fork). Section (b) below proves ordinary plain-RTF behaviour (no Cocoa dialect,
 * no cp1252 high bytes, no surrogates, no `\fsN`) is byte-IDENTICAL before and after the four
 * changes, using a genuine byte-slice from the pre-existing `tests/php/fixtures/single-file/
 * pro6.pro6` fixture (used by `test-import-format-coverage.php`) plus one hand-authored plain-RTF
 * row representative of EasyWorship's dialect — **no EasyWorship `.db` binary fixture is committed
 * anywhere in this repo to lift bytes from** (verified via `find tests -iname '*.db'` returning
 * nothing during implementation), so that row is explicitly NOT a "lifted from a real fixture" row
 * and is labelled as such below; its only job is to prove the ordinary `\par`-only, ASCII-only RTF
 * path (the shape EasyWorship's `word.words` column actually carries) is untouched by the four
 * changes. Change 4 specifically is ALSO non-regressing by construction, not just by test coverage:
 * its default `$minFontHalfPts = 0` (what every pre-existing caller passes) makes the suppression
 * check always false — see row (a)(11)'s second assertion, which proves this on the EXACT SAME
 * two-font input the filtered assertion right above it uses, rather than a different one.
 *
 * MUTATION-PROVEN (rule #34), performed once by hand at authoring time, then reverted:
 *   - Neutered change 1 in `_bulkImport_rtfToText()` (`includes/song_importers.php`) so a
 *     `\` + CR/LF is consumed but emits NOTHING (restoring the pre-#1968 "silently dropped"
 *     behaviour) -> TWO rows went RED: (a)(1) ("Trans Original 1Trans Original 2" — the two
 *     lines silently joined into one run-on line, exactly the bug this change fixes) AND (a)(3)
 *     ("Stille Nacht, heilige NachtAlles schläft, einsam wacht" — bussnet-stille-nacht.pro's two
 *     lines are ALSO Cocoa-soft-return-separated, an independent real fixture catching the same
 *     mutation). Every other row — including both non-regression rows, which contain no soft
 *     returns at all — stayed GREEN, confirming the guard is sensitive to exactly this one change
 *     and nothing else, across two independent real fixtures. Reverted -> all 20 green again.
 *   - PR-1 correctness-defect fix: neutered change 4's suppression gate in `_bulkImport_rtfToText()`
 *     (its `$isFontSuppressed` closure forced to always return `false`) -> row (a)(11)'s FILTERED
 *     assertion (`$minFontHalfPts=120`) went RED — the "'," artifact reappeared glued onto the real
 *     lyric text, exactly the pollution this change removes — while every other row, including
 *     (a)(11)'s own paired default-0 assertion (which is SUPPOSED to still show "','") and every
 *     non-regression row, stayed GREEN. The SAME mutation also turns `tests/php/test-pp7-parse.php`
 *     red on both `v7-*-mac.pro` fixtures (see that file's own MUTATION-PROVEN note). Reverted ->
 *     all 24 green again.
 *
 * Usage:
 *   php tests/php/test-pp7-rtf-extract.php
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/song_importers.php   _bulkImport_rtfToText() (the function under test) + _bulkImport_rtfCp1252ByteToUtf8()
 * @see .claude/propresenter-interop-1968-plan.md         §3.6 (this test's brief + truth table)
 * @see tests/fixtures/propresenter/README.md              real-fixture provenance + licensing
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

/**
 * Locate one cue's element rtf_data by decoding a REAL committed fixture at test-run time and
 * matching the cue uuid by (case-insensitive) prefix — the same lookup idiom the scratch
 * verification scripts used while this test was authored. Fails loudly (returns null) rather than
 * silently if the fixture or cue ever goes missing, so a moved/renamed fixture shows up as a RED
 * assertion instead of a quietly-empty row.
 */
function pp7RtfTestFixtureElement(string $fixtureBasename, string $cueUuidPrefix, int $elementIndex): ?string
{
    $path = dirname(__DIR__, 2) . '/tests/fixtures/propresenter/' . $fixtureBasename;
    if (!is_file($path)) {
        return null;
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        return null;
    }
    $decoded = pp7DecodePresentation($bytes);
    foreach ($decoded['cues'] as $cue) {
        if (stripos((string)$cue['uuid'], $cueUuidPrefix) === 0) {
            return $cue['slideRtf'][$elementIndex] ?? null;
        }
    }
    return null;
}

echo "\n#1968 PR-1 — dual-dialect RTF extractor truth table\n\n";

/* ============================================================================================
 * (a) TRUTH TABLE — the three targeted changes (plan §3.6)
 * ============================================================================================ */

echo "-- (a) truth table --\n";

/* (a)(1) Cocoa 2-line body, real: tests/fixtures/propresenter/bussnet-test.pro, the "Ending"
   cue group's cue (uuid starts 562C027E), element 0 — a genuine original/translated pair (element
   1 is the translation layer, tested separately by test-pp7-parse.php's translation-layer
   coverage). The bytes contain a literal backslash immediately followed by a raw LF between "1"
   and "Trans" (hex `...31 5C 0A 54 72 61 6E 73...`, verified during implementation) — the Cocoa
   soft return change 1 exists to handle. */
$row1 = pp7RtfTestFixtureElement('bussnet-test.pro', '562c027e', 0);
ok('bussnet-test.pro Ending cue element 0 was found', $row1 !== null);
if ($row1 !== null) {
    ok('change 1 (Cocoa soft return -> newline): splits into exactly 2 real lines',
        _bulkImport_rtfToText($row1) === "Trans Original 1\nTrans Original 2");
}

/* (a)(2) Windows dialect + \par, real: tests/fixtures/propresenter/v7-feature-test-win.pro
   (the ONLY genuine Windows-authored PP 7.13.2 fixture in this corpus — PLATFORM_WINDOWS,
   \rtf0\ansi\ansicpg1252, \csgenericrgb colours, \strokewidth/\strokec/\highlight/\cb —
   see the file's own doc-block in propresenter7_decode.php), the "" (unnamed) cue group's second
   cue (uuid starts 89ed8dad), element 1: "Other text in lower 3rd" followed by a trailing \par
   with no further text. Proves \csgenericrgb / \strokewidth / \highlight / \cb (Windows-dialect
   colour/stroke control words absent from the Mac dialect) contribute NOTHING to the extracted
   text, and that \par still splits correctly alongside the new soft-return handling. */
$row2 = pp7RtfTestFixtureElement('v7-feature-test-win.pro', '89ed8dad', 1);
ok('v7-feature-test-win.pro cue element 1 was found', $row2 !== null);
if ($row2 !== null) {
    ok('Windows \\rtf0 dialect: \\par splits correctly, \\csgenericrgb/\\strokewidth/\\highlight/\\cb contribute nothing',
        _bulkImport_rtfToText($row2) === "Other text in lower 3rd\n");
}

/* (a)(3) \'XX cp1252 hex escape, real: tests/fixtures/propresenter/bussnet-stille-nacht.pro's
   only cue (uuid starts a9642a69) — "Alles schl\'e4ft" (0xE4 = "ä" — real German lyric text,
   the ONLY \'XX >= 0x80 escape found anywhere in this fixture corpus, verified by scanning every
   committed .pro during implementation). */
$row3 = pp7RtfTestFixtureElement('bussnet-stille-nacht.pro', 'a9642a69', 0);
ok('bussnet-stille-nacht.pro cue element 0 was found', $row3 !== null);
if ($row3 !== null) {
    ok('change 2 (\\\'XX cp1252-aware): "Alles schl\\\'e4ft" decodes to "Alles schläft"',
        _bulkImport_rtfToText($row3) === "Stille Nacht, heilige Nacht\nAlles schläft, einsam wacht");
}

/* (a)(4) \'93…\'94 cp1252 SMART QUOTES — the 0x80-0x9F block specifically (0xE4 above is in the
   0xA0-0xFF range, which is byte-identical to Latin-1 and needs no lookup table at all; nothing in
   the committed corpus has a 0x80-0x9F \'XX escape — verified by scanning every fixture's hex
   escapes during implementation, so this row is SYNTHETIC, exercising the one 32-entry lookup
   table `_bulkImport_rtfCp1252ByteToUtf8()` adds). 0x93/0x94 are cp1252's curly double quotes. */
ok('synthetic: \\\'93…\\\'94 (cp1252 0x80-0x9F block) -> U+201C…U+201D curly double quotes',
    _bulkImport_rtfToText("{\\rtf1\\ansi\\ansicpg1252 \\'93Hello\\'94}") === "\u{201C}Hello\u{201D}");

/* (a)(5) Basic \uN (non-surrogate), synthetic: \u8217 = U+2019 RIGHT SINGLE QUOTATION MARK, with
   its \uc1-default one-character ASCII fallback ("?") correctly swallowed rather than leaking
   into the output. */
ok('synthetic: \\u8217? -> U+2019 (fallback "?" swallowed, not emitted)',
    _bulkImport_rtfToText('{\rtf1\ansi \u8217?}') === "\u{2019}");

/* (a)(6) Surrogate pair, synthetic (plan §3.6's own worked example): \u-10179?\u-8704? is the
   UTF-16 surrogate pair for U+1F600 GRINNING FACE, encoded RTF-style as two SIGNED 16-bit \uN
   escapes (-10179 + 65536 = 55357 = 0xD83D high surrogate; -8704 + 65536 = 56832 = 0xDE00 low
   surrogate; 0x10000 + (0xD83D-0xD800)*0x400 + (0xDE00-0xDC00) = 0x1F600). Before change 3 both
   halves were silently dropped (mb_chr() on a lone surrogate returns false).  */
ok('change 3 (surrogate-pair combining): \\u-10179?\\u-8704? -> the single U+1F600 GRINNING FACE',
    _bulkImport_rtfToText('{\rtf1\ansi \u-10179?\u-8704?}') === "\u{1F600}");

/* (a)(7) A LONE (unpaired) high surrogate is dropped, never combined with whatever text follows
   it — the same "never throws, degrades gracefully" contract the pre-#1968 code already had for
   a lone surrogate, now proven for the buffered-and-orphaned case specifically. */
ok('a lone/orphaned high surrogate (\\u-10179? with no following low surrogate) is dropped, not glued to following text',
    _bulkImport_rtfToText('{\rtf1\ansi \u-10179?ABC}') === 'ABC');

/* (a)(8) Cocoa \uc0\u8232  LINE SEPARATOR idiom, synthetic (real fixtures in this corpus never
   use it, only \par / the soft return): folds to "\n" exactly like \par, rather than emitting the
   invisible U+2028 character (which would silently re-join the two "lines" downstream). \u8233
   (PARAGRAPH SEPARATOR) is checked too. */
ok('Cocoa \\uc0\\u8232 (LINE SEPARATOR) idiom folds to a newline',
    _bulkImport_rtfToText('{\rtf1\ansi one\uc0\u8232 two}') === "one\ntwo");
ok('U+2029 PARAGRAPH SEPARATOR also folds to a newline',
    _bulkImport_rtfToText('{\rtf1\ansi one\uc0\u8233 two}') === "one\ntwo");

/* (a)(9) Header/table destinations contribute nothing, real: the ENTIRE content of
   tests/fixtures/propresenter/v7-empty-single-slide.pro's only cue/element (uuid starts
   9fe98fd2) — a real "empty slide" whose rtf_data is nothing but \fonttbl / \colortbl /
   \*\expandedcolortbl boilerplate. Proves the destination-skipping machinery is untouched by the
   three changes (a fixture-derived non-regression check on the EXISTING behaviour, not a new one). */
$row9 = pp7RtfTestFixtureElement('v7-empty-single-slide.pro', '9fe98fd2', 0);
ok('v7-empty-single-slide.pro cue element 0 was found', $row9 !== null);
if ($row9 !== null) {
    ok('fonttbl/colortbl/expandedcolortbl-only RTF (a real "empty slide") extracts to the empty string',
        _bulkImport_rtfToText($row9) === '');
}

/* (a)(10) Escaped literal brace/backslash — untouched by the three changes; a plain sanity check. */
ok('escaped \\{ \\} \\\\ decode to literal characters',
    _bulkImport_rtfToText('{\rtf1\ansi \{brace\} and \\\\backslash}') === '{brace} and \\backslash');

/* (a)(11) Change 4 — dominant-font suppression, the #1968 PR-1 correctness-defect fix. REAL
   two-font byte-slice lifted from tests/fixtures/propresenter/v7-at-the-cross-mac.pro's "Song"
   cue group, first cue (uuid starts 95898949), element 0 — the ACTUAL rtf_data that motivated
   this fix: a small `\f0\fs24 \cf0 ','` RTF-writer-artifact run immediately followed, in the
   SAME rtf_data with no \par between them, by the real `\f1\fs120 …` lyric ("I know a place…").
   _bulkImport_pro7RtfMaxFontHalfPts() on this exact slice returns 120 (verified below), which is
   what _bulkImport_pro7SelectCueText() would pass as $minFontHalfPts in production.
   TWO rows, deliberately paired to prove the GATE (not a coincidence) does the work:
     - with $minFontHalfPts = 120: "'," is GONE, only the \fs120 lyric survives.
     - with the DEFAULT $minFontHalfPts = 0 (no argument, exactly how every pre-#1968 caller —
       Pro6/EasyWorship/Proclaim — invokes this function): "'," is STILL there, byte-identical to
       pre-fix behaviour — this is the "DEFAULT-0 NON-REGRESSION CONTRACT" the function doc-block
       promises, proven on the SAME input as the filtered row rather than a different one. */
$row11 = pp7RtfTestFixtureElement('v7-at-the-cross-mac.pro', '95898949', 0);
ok('v7-at-the-cross-mac.pro "Song" cue element 0 was found', $row11 !== null);
if ($row11 !== null) {
    ok('change 4: _bulkImport_pro7RtfMaxFontHalfPts() finds the real max (\\fs120) on this slice',
        _bulkImport_pro7RtfMaxFontHalfPts($row11) === 120);
    ok('change 4 (dominant-font suppression, $minFontHalfPts=120): the small \\fs24 "\',"" run is'
        . ' dropped, only the \\fs120 lyric survives',
        _bulkImport_rtfToText($row11, 120) === "I know a place\nA wonderful place\n"
            . "Where accused and condemned\nFind mercy and grace\nWhere the wrongs we have done\n"
            . "And the wrongs done to us\nWere nailed there with him \nThere on the cross");
    ok('change 4 gate proof: the SAME slice with the DEFAULT $minFontHalfPts=0 still carries the'
        . ' "\'," prefix — the filtering above is the gate doing the work, not a coincidence of the input',
        _bulkImport_rtfToText($row11) === "',I know a place\nA wonderful place\n"
            . "Where accused and condemned\nFind mercy and grace\nWhere the wrongs we have done\n"
            . "And the wrongs done to us\nWere nailed there with him \nThere on the cross");
}

/* ============================================================================================
 * (b) NON-REGRESSION — the SAME shared function, exercised by Pro6 / EasyWorship, must be
 *     byte-IDENTICAL after the three ProPresenter-7-motivated changes above.
 * ============================================================================================ */

echo "\n-- (b) non-regression: existing Pro6/EasyWorship RTF stays byte-identical --\n";

/* (b)(1) REAL — lifted from the pre-existing tests/php/fixtures/single-file/pro6.pro6 fixture
   (used by test-import-format-coverage.php; NOT authored for this test), whose single
   <RVTextElement RTFData="…"> base64-decodes to plain ASCII \par-separated text with none of the
   three changed escapes (no soft return, no \'XX >= 0x80, no \uN at all). */
$pro6FixturePath = dirname(__DIR__, 2) . '/tests/php/fixtures/single-file/pro6.pro6';
ok('tests/php/fixtures/single-file/pro6.pro6 exists', is_file($pro6FixturePath));
if (is_file($pro6FixturePath)) {
    $pro6Xml = file_get_contents($pro6FixturePath);
    ok('the pro6.pro6 fixture readable', $pro6Xml !== false);
    if ($pro6Xml !== false && preg_match('/RTFData="([^"]+)"/', $pro6Xml, $m)) {
        $pro6Rtf = base64_decode($m[1], true);
        ok('pro6.pro6\'s embedded RTFData base64-decodes', $pro6Rtf !== false);
        if ($pro6Rtf !== false) {
            ok('non-regression: pro6.pro6\'s RTF still extracts byte-identically ("Probe pro6 line one\\nProbe pro6 line two")',
                _bulkImport_rtfToText($pro6Rtf) === "Probe pro6 line one\nProbe pro6 line two");
        }
    } else {
        ok('pro6.pro6 contains an RTFData="…" attribute to lift', false);
    }
}

/* (b)(2) NOT a real fixture — no EasyWorship .db binary is committed anywhere in this repo
   (verified: `find tests -iname '*.db'` returns nothing). Hand-authored, plain ASCII, \par-only
   RTF representative of EasyWorship's `word.words` column dialect (no Cocoa soft return, no
   cp1252 high bytes, no \uN) — proves the ordinary plain-RTF path both EasyWorship and Proclaim
   share with Pro6 stays untouched. */
ok('non-regression: ordinary plain-RTF (EasyWorship/Proclaim-representative, no fixture exists to lift from) stays byte-identical',
    _bulkImport_rtfToText('{\rtf1\ansi\ansicpg1252 Amazing Grace\par How sweet the sound}')
        === "Amazing Grace\nHow sweet the sound");

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA truth-table failure means _bulkImport_rtfToText() disagrees with a byte-verified real\n";
    echo "fixture (or a documented RTF-spec mechanism). A non-regression failure means one of the\n";
    echo "three #1968 changes broke the SAME shared function's pre-existing Pro6/EasyWorship/\n";
    echo "Proclaim behaviour — never acceptable per the CLAUDE.md modularity rule.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ RTF extractor assertions passed.\n";
