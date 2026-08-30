<?php

declare(strict_types=1);

/**
 * iHymns — ProPresenter 7+ `.pro` parser validation against REAL fixtures (#1968 PR-1)
 * ================================================================================================
 *
 * ELI5
 * ----
 * `_bulkImport_parsePro7()` (in `includes/song_importers.php`) turns a decoded `.pro` presentation
 * into the neutral "song" shape every other iHymns importer produces — title, CCLI credits,
 * ordered sections (verse/chorus/…), and the arrangement (running order). This file proves that,
 * end to end, for EVERY real third-party ProPresenter file committed under
 * `tests/fixtures/propresenter/` — decode -> RTF -> section palette -> arrangement, the FULL walk,
 * not just the decoder (that's `tests/php/test-pp7-decode.php`) or just the RTF extractor (that's
 * `tests/php/test-pp7-rtf-extract.php`).
 *
 * ANTI-FALSE-POSITIVE POSTURE (the owner's #1 rule for this epic — see
 * `.claude/propresenter-interop-1968-plan.md`'s header + §8): every `expected/<name>.song.json`
 * file was drafted by RUNNING the parser against the real fixture, then EYEBALLED line-by-line
 * against the file's actual real-world content before being committed as the frozen contract —
 * never generated from what the parser "should" produce in the abstract. Two of the nine fixtures
 * intentionally EXPECT A PARSE FAILURE (`bussnet-media-macro.pro` — every cue is a MEDIA/MACRO
 * action with zero lyric text; `v7-empty-single-slide.pro` — a single genuinely blank slide) —
 * their `expected/*.song.json` carries `{"expectFailure": true, "reasonContains": "…"}` instead of
 * a song shape, proving the parser fails CLEANLY (not a crash, not a bogus empty song) on
 * content-free input.
 *
 * A NOTEWORTHY REAL-WORLD DISCOVERY this harness surfaced, ORIGINALLY kept faithfully in the
 * expected output (a deliberate false-negative, flagged "for future consideration" rather than
 * silently patched), and since FIXED for real by the PR-1 correctness-defect fix (dominant-font
 * lyric selection — see `_bulkImport_rtfToText()`'s `$minFontHalfPts` param and
 * `_bulkImport_pro7RtfMaxFontHalfPts()`, both in `includes/song_importers.php`):
 * ChrisMBarr's two Mac-exported sample files (`v7-at-the-cross-mac.pro`,
 * `v7-come-thou-fount-mac.pro`) prefix EVERY text run — including their genuinely-blank "Blank"
 * cue group — with two literal characters, an apostrophe and a comma ("',"), written in a
 * SEPARATE, smaller-font (`\fs24`) RTF run immediately before the real, larger-font (`\fs120`)
 * lyric run, with no paragraph break between them (byte-verified: no `\par`/soft-return separates
 * "'," from the following word). This reads as a PP7-Mac RTF-writer artifact (it appears in EVERY
 * text box in both files, including ones with no real content), NOT malformed decoding —
 * `bussnet`'s independently-produced Mac fixtures carry no such prefix at all, so it is
 * fixture/export-specific, not a general Mac-dialect rule the extractor should special-case.
 * `_bulkImport_parsePro7()` now selects each text element's DOMINANT (largest) font run as the
 * lyric and drops smaller runs merged into the SAME `rtf_data` — the "',I know a place" pollution
 * is gone, and its committed `expected/*.song.json` was regenerated to the clean output. The
 * standalone "Blank" cue group (whose ENTIRE content, provably, is nothing but that one small-font
 * artifact — no larger-font run exists anywhere on that specific slide for per-slide dominant-font
 * comparison to find) needed a SEPARATE, complementary fix: "Blank" (case-insensitive) joined the
 * existing "Song Title"/"Lyrics Background" non-lyric-group name skip-list in
 * `_bulkImport_parsePro7()` — the SAME already-proven mechanism, not a new one — because font-size
 * filtering alone is mathematically incapable of reaching a slide with no larger sibling run to
 * compare against without widening its comparison scope beyond that one slide, which was tried and
 * rejected during implementation for breaking real content in `bussnet-test.pro` (a legitimate
 * `\fs80` translation-layer run next to an unrelated `\fs84` chosen run) and
 * `v7-feature-test-win.pro` (several single-font cues at different absolute sizes, each
 * individually dominant on its own slide). See `_bulkImport_parsePro7()`'s own doc-block point 3
 * for the full reasoning, kept there rather than duplicated here.
 *
 * MUTATION-PROVEN (rule #34), performed once by hand at authoring time, then reverted:
 *   - Broke the arrangement INDEX mapping in `_bulkImport_parsePro7()` (changed
 *     `$paletteGroupUuidToIndex[$group['groupUuid']] = count($components) - 1;` to
 *     `= count($components);`, an off-by-one) -> FIVE of the nine real fixtures went RED:
 *     `bussnet-test.pro` (the one real fixture with a genuinely non-trivial arrangement) changed
 *     from the expected `[2,0,2,1,2]` to `[3,1,3,2,3]` — the section running order silently
 *     wrong, exactly the failure mode this guard exists to catch — AND, more subtly,
 *     `bussnet-amazing-grace.pro` / `bussnet-doxology.pro` / `bussnet-stille-nacht.pro` (whose
 *     TRUE arrangement is a trivial identity `[0,1,…]`, correctly collapsed to `arrangement: null`
 *     by the "identity -> store nothing" rule) ALSO went red, because the shifted indices no
 *     longer FORM an identity sequence and so incorrectly stopped collapsing to null. Every
 *     fixture with no arrangement resolution to begin with (v7-at-the-cross-mac.pro,
 *     v7-come-thou-fount-mac.pro, v7-feature-test-win.pro's dangling case, the two
 *     expected-failure fixtures) stayed unaffected, confirming the guard's sensitivity is
 *     precisely scoped to arrangement-index correctness. Reverted -> all 23 green again.
 *   - PR-1 correctness-defect fix, TWO independent mutations, each performed once by hand then
 *     reverted (see `_bulkImport_rtfToText()` / `_bulkImport_parsePro7()` in
 *     `includes/song_importers.php` for exactly what was neutered):
 *     (1) neutered the font-suppression gate (`_isFontSuppressed()`'s condition forced to `false`)
 *         -> the "'," artifact reappeared, glued onto the front of the real lyric line, in BOTH
 *         `v7-at-the-cross-mac.pro` ("got '\',I know a place', expected 'I know a place'") and
 *         `v7-come-thou-fount-mac.pro` — exactly the pre-fix pollution this fix removes. Every
 *         other fixture stayed green (no other fixture has a two-font single-element run).
 *         Reverted -> all 23 green again.
 *     (2) neutered the "Blank" name-exclusion (forced its `strcasecmp(...) === 0` term to `false`)
 *         -> BOTH `v7-*-mac.pro` fixtures went red with an extra spurious `components[1]` entry
 *         (the standalone "'," component reappearing) — `[first diff at $.components (keys differ:
 *         [0,1] vs [0])]`. No other fixture has a "Blank" group, so no other fixture was affected.
 *         Reverted -> all 23 green again.
 *
 * Usage:
 *   php tests/php/test-pp7-parse.php
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/song_importers.php   _bulkImport_parsePro7() (under test) + _bulkImport_pro7GroupType()
 * @see .claude/propresenter-interop-1968-plan.md         §3.3 (the parse walk), §3.4 (element selection), §3.5 (label mapping)
 * @see tests/fixtures/propresenter/README.md              real-fixture provenance + licensing
 * @see tests/php/test-pp7-decode.php                      the decoder-level cross-validation this builds on
 * @see tests/php/test-pp7-rtf-extract.php                 the RTF-extractor-level truth table this builds on
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
 * Find the first point at which two (already-decoded) values disagree, as a human-readable
 * dotted/bracketed path — mirrors tests/php/test-pp7-decode.php's own helper so a failure prints
 * something actionable instead of two enormous JSON blobs.
 */
function pp7ParseFirstDiffPath($a, $b, string $path = '$'): ?string
{
    if (is_array($a) && is_array($b)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        if ($keysA !== $keysB) {
            return "{$path} (keys differ: [" . implode(',', $keysA) . '] vs [' . implode(',', $keysB) . '])';
        }
        foreach ($a as $k => $v) {
            $sub = is_int($k) ? "{$path}[{$k}]" : "{$path}.{$k}";
            $diff = pp7ParseFirstDiffPath($v, $b[$k], $sub);
            if ($diff !== null) {
                return $diff;
            }
        }
        return null;
    }
    if ($a === $b) {
        return null;
    }
    $av = is_string($a) && strlen($a) > 120 ? substr($a, 0, 120) . '…' : var_export($a, true);
    $bv = is_string($b) && strlen($b) > 120 ? substr($b, 0, 120) . '…' : var_export($b, true);
    return "{$path} (got {$av}, expected {$bv})";
}

echo "\n#1968 PR-1 — ProPresenter 7+ .pro parser (_bulkImport_parsePro7) vs. real fixtures\n\n";

/* ============================================================================================
 * (a) FULL PARSE — every real .pro fixture vs. its committed expected/*.song.json
 * ============================================================================================ */

echo "-- (a) real-fixture parse validation --\n";

$fixturesDir = dirname(__DIR__, 2) . '/tests/fixtures/propresenter';
$expectedDir = $fixturesDir . '/expected';

$proFixtures = glob($fixturesDir . '/*.pro') ?: [];
sort($proFixtures);

// Coverage floor (rule #34's under-report clause) — mirrors test-pp7-decode.php's identical guard.
ok('at least 11 committed .pro fixtures exist to parse-validate against (found ' . count($proFixtures) . ')',
    count($proFixtures) >= 11);

foreach ($proFixtures as $proPath) {
    $base = basename($proPath, '.pro');
    $expectedPath = $expectedDir . '/' . $base . '.song.json';

    // A fixture without a matching expected file is a coverage gap, not a skip.
    if (!is_file($expectedPath)) {
        ok("{$base}.pro has a matching expected/{$base}.song.json", false);
        continue;
    }

    $body = file_get_contents($proPath);
    if ($body === false) {
        ok("{$base}.pro is readable", false);
        continue;
    }

    $expectedRaw = file_get_contents($expectedPath);
    $expected = $expectedRaw !== false ? json_decode($expectedRaw, true) : null;
    if (!is_array($expected)) {
        ok("{$base}.song.json parses as JSON", false);
        continue;
    }

    [$parsed, $reason] = _bulkImport_parsePro7($body);

    if (!empty($expected['expectFailure'])) {
        ok("{$base}.pro: parse fails cleanly as expected (parsed === null)", $parsed === null);
        if ($parsed === null) {
            $needle = (string)($expected['reasonContains'] ?? '');
            ok("{$base}.pro: failure reason contains \"{$needle}\" (got: " . ($reason ?? 'null') . ')',
                $needle !== '' && str_contains((string)$reason, $needle));
        }
        continue;
    }

    if ($parsed === null) {
        ok("{$base}.pro: parses successfully (got failure: " . ($reason ?? 'null') . ')', false);
        continue;
    }

    $diff = pp7ParseFirstDiffPath($parsed, $expected);
    ok("{$base}.pro: _bulkImport_parsePro7() matches expected/{$base}.song.json"
        . ($diff !== null ? " [first diff at {$diff}]" : ''),
        $diff === null);
}

/* ============================================================================================
 * (b) SYNTHETIC EDGE CASES — code paths NO committed real fixture exercises
 * ============================================================================================
 * Exhaustively verified during implementation (every real .pro's cues[] vs. every group's
 * cueIdentifiers[] union, and every arrangement-resolution rung actually taken) that:
 *   - no real fixture has a cue absent from every group's cueIdentifiers ("unreferenced cues",
 *     plan §3.3 point 4's defensive net);
 *   - no real fixture reaches the literal `arrangements[0]` fallback rung (every fixture either
 *     resolves selected_arrangement directly, or has an entirely empty arrangements[] to begin
 *     with — the "dangling" case in v7-feature-test-win.pro never even reaches this rung).
 * Both are real, spec-required branches (plan §3.3), so they are exercised here via a MINIMAL,
 * independent, hand-rolled protobuf byte-builder (varint + length-delimited only — the same two
 * wire shapes `includes/propresenter7_decode.php`'s own doc-block names as everything this schema
 * ever needs) — NOT a reuse of this app's own `.pro` exporter (propresenter-export.js), so this is
 * NOT the circular self-round-trip the owner's #1 rule for this epic forbids. The synthetic bytes'
 * correctness is itself verified below by round-tripping through the ALREADY cross-validated
 * `pp7DecodePresentation()` (proven correct against protobufjs on 9 real files in
 * tests/php/test-pp7-decode.php) before being handed to the parser under test.
 */

echo "\n-- (b) synthetic: unreferenced cues + the arrangements[0] fallback rung --\n";

/** A minimal proto3 varint writer — https://protobuf.dev/programming-guides/encoding/#varints */
function pp7TestVarint(int $v): string
{
    $out = '';
    while (true) {
        $b = $v & 0x7F;
        $v >>= 7;
        if ($v !== 0) {
            $out .= chr($b | 0x80);
        } else {
            $out .= chr($b);
            break;
        }
    }
    return $out;
}
function pp7TestTag(int $field, int $wireType): string { return pp7TestVarint(($field << 3) | $wireType); }
function pp7TestLenDelim(string $bytes): string { return pp7TestVarint(strlen($bytes)) . $bytes; }
function pp7TestStrField(int $field, string $s): string { return pp7TestTag($field, 2) . pp7TestLenDelim($s); }
function pp7TestMsgField(int $field, string $sub): string { return pp7TestTag($field, 2) . pp7TestLenDelim($sub); }
function pp7TestUuid(string $s): string { return pp7TestStrField(1, $s); } // UUID{string=1}

/**
 * Build ONE synthetic `.pro`-shaped Presentation message covering both uncovered branches at
 * once: a "Verse 1" group with one real cue, a SECOND cue referenced by NO group at all
 * (unreferenced-cue coverage), and ONE arrangement named "MyOrder" (deliberately not matching the
 * ccli/standard/original name fallback) with NO `selected_arrangement` set at all — forcing
 * resolution all the way to the literal `arrangements[0]` rung.
 */
function pp7TestBuildSyntheticPro(): string
{
    $rtf1 = '{\rtf1\ansi Line one}';
    $rtf2 = '{\rtf1\ansi Unreferenced line}';

    // Slide.Element{element=1 -> Graphics.Element{text=13 -> Graphics.Text{rtf_data=5}}, info=4}
    $makeElement = static function (string $rtf, int $info): string {
        $graphicsText = pp7TestStrField(5, $rtf);
        $graphicsElement = pp7TestMsgField(13, $graphicsText);
        return pp7TestMsgField(1, $graphicsElement) . pp7TestTag(4, 0) . pp7TestVarint($info);
    };
    // Slide{elements=1} -> PresentationSlide{base_slide=1} -> Action.SlideType{presentation=2}
    //   -> Action{type=9=PRESENTATION_SLIDE(11), slide=23} -> Cue{uuid=1, actions=10}
    $makeCue = static function (string $uuid, string $rtf) use ($makeElement): string {
        $slide = pp7TestMsgField(1, $makeElement($rtf, 2)); // IS_TEXT_ELEMENT
        $presentationSlide = pp7TestMsgField(1, $slide);
        $actionSlideType = pp7TestMsgField(2, $presentationSlide);
        $action = pp7TestTag(9, 0) . pp7TestVarint(11) . pp7TestMsgField(23, $actionSlideType);
        return pp7TestMsgField(1, pp7TestUuid($uuid)) . pp7TestMsgField(10, $action);
    };

    $cue1 = $makeCue('11111111-1111-1111-1111-111111111111', $rtf1);
    $cue2 = $makeCue('22222222-2222-2222-2222-222222222222', $rtf2); // never referenced by any group

    // Group{uuid=1, name=2} -> Presentation.CueGroup{group=1, cue_identifiers=2}
    $group = pp7TestMsgField(1, pp7TestUuid('AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA')) . pp7TestStrField(2, 'Verse 1');
    $cueGroup = pp7TestMsgField(1, $group) . pp7TestMsgField(2, pp7TestUuid('11111111-1111-1111-1111-111111111111'));

    // Presentation.Arrangement{uuid=1, name=2, group_identifiers=3} — named so it will NOT match
    // the ccli/standard/original fallback rung, forcing resolution to the arrangements[0] literal.
    $arrangement = pp7TestMsgField(1, pp7TestUuid('99999999-9999-9999-9999-999999999999'))
        . pp7TestStrField(2, 'MyOrder')
        . pp7TestMsgField(3, pp7TestUuid('AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA'));

    // Presentation{name=3, arrangements=11, cue_groups=12, cues=13} — NO selected_arrangement (10).
    return pp7TestStrField(3, 'Synthetic Test')
        . pp7TestMsgField(11, $arrangement)
        . pp7TestMsgField(12, $cueGroup)
        . pp7TestMsgField(13, $cue1)
        . pp7TestMsgField(13, $cue2);
}

$syntheticBytes = pp7TestBuildSyntheticPro();

// Verify the hand-rolled encoding itself is correct BEFORE trusting it as a parser-test input, by
// round-tripping it through the already cross-validated decoder.
try {
    $syntheticDecoded = pp7DecodePresentation($syntheticBytes);
    ok('synthetic .pro bytes decode without throwing', true);
    ok('synthetic .pro: name decodes correctly', $syntheticDecoded['name'] === 'Synthetic Test');
    ok('synthetic .pro: 1 cue group ("Verse 1") decodes correctly',
        count($syntheticDecoded['cueGroups']) === 1 && $syntheticDecoded['cueGroups'][0]['groupName'] === 'Verse 1');
    ok('synthetic .pro: 2 cues decode correctly (one referenced, one not)',
        count($syntheticDecoded['cues']) === 2);
    ok('synthetic .pro: 1 arrangement ("MyOrder") decodes correctly, no selected_arrangement',
        count($syntheticDecoded['arrangements']) === 1
        && $syntheticDecoded['arrangements'][0]['name'] === 'MyOrder'
        && $syntheticDecoded['selectedArrangement'] === null);
} catch (\Throwable $e) {
    ok('synthetic .pro bytes decode without throwing (' . $e->getMessage() . ')', false);
}

[$syntheticParsed, $syntheticReason] = _bulkImport_parsePro7($syntheticBytes);
ok('synthetic .pro parses successfully' . ($syntheticParsed === null ? " (got failure: {$syntheticReason})" : ''),
    $syntheticParsed !== null);

if ($syntheticParsed !== null) {
    ok('unreferenced-cue coverage: 2 components exist (the real group + the defensively-appended cue)',
        count($syntheticParsed['components']) === 2);
    ok('unreferenced-cue coverage: component 0 is "Verse 1"\'s real content',
        ($syntheticParsed['components'][0]['type'] ?? null) === 'verse'
        && ($syntheticParsed['components'][0]['number'] ?? null) === 1
        && ($syntheticParsed['components'][0]['lines'] ?? null) === ['Line one']);
    ok('unreferenced-cue coverage: component 1 is the defensively-appended unreferenced cue',
        ($syntheticParsed['components'][1]['type'] ?? null) === 'verse'
        && ($syntheticParsed['components'][1]['lines'] ?? null) === ['Unreferenced line']);
    ok('unreferenced-cue coverage: a warning names the unreferenced cue',
        (bool)array_filter(
            $syntheticParsed['warnings'] ?? [],
            static fn($w) => str_contains((string)$w, '22222222-2222-2222-2222-222222222222')
                && str_contains((string)$w, 'not referenced by any group')
        ));
    ok('arrangements[0] fallback rung: no selected_arrangement + a non-ccli/standard/original name'
        . ' still resolves via the literal arrangements[0] entry (indices=[0], non-identity since 2 components exist now)',
        $syntheticParsed['arrangement'] === [0]);
}

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA real-fixture parse failure means _bulkImport_parsePro7() disagrees with a hand-verified\n";
    echo "real ProPresenter file's actual content — the owner's #1 rule for this epic is that this\n";
    echo "must never ship. A synthetic-edge-case failure means a defensive code path (unreferenced\n";
    echo "cues, the arrangements[0] fallback rung) that no real committed fixture happens to exercise\n";
    echo "has regressed.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ parser assertions passed.\n";
