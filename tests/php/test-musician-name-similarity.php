<?php

declare(strict_types=1);

/**
 * test-musician-name-similarity.php — shared NAME scorer + variant classifier (#1785 C2, rule #22)
 * ====================================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING PINNED
 * ---------------------
 * Two pure, DB-free additions the #1785 musician-registry-duplicate work
 * adds:
 *
 *   - `ihymns_sim_name_score()` (includes/song_similarity.php) — re-expresses
 *     the maths that used to live privately in manage/musicians-bulk-promote.
 *     php's now-deleted `_musBulkSimilarity()` through the SAME shared
 *     primitives (`ihymns_sim_fold()`, `ihymns_sim_text()`,
 *     `ihymns_sim_authors_jaccard()`) the song-duplicate scorer already
 *     uses (rule #22 — one scorer, extended, never re-forked).
 *   - `musicianNameVariantClass()` (includes/musician_helpers.php) — a pure
 *     6-rung ladder classifying HOW two names differ (identical /
 *     unicode-normalisation / whitespace / case / punctuation / null =
 *     genuinely different words), so a merge UI can say "these differ only
 *     by invisible spacing" instead of a bare "Eddie James → Eddie James"
 *     (problem 2 from the #1785 issue body).
 *
 * Score assertions use RANGES, not exact floats, for the non-trivial pairs
 * — the blend is an implementation detail (0.6·token-Jaccard +
 * 0.4·edit-distance) and pinning it to 4 decimal places would make this
 * test brittle to a legitimate future re-tune of those weights. The
 * boundary cases (fold-identical → 1.0, unrelated names → low) ARE pinned
 * exactly because those are structural guarantees the function's own
 * doc-block promises, not tuning outcomes.
 *
 *   php tests/php/test-musician-name-similarity.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/song_similarity.php     ihymns_sim_name_score()
 * @see appWeb/public_html/includes/musician_helpers.php    musicianNameVariantClass()
 * @see .claude/musicians-dedup-1785-plan.md §4.1, §4.2, §10 (guard G1)
 */

$root = dirname(__DIR__, 2);

/* Direct access to musician_helpers.php is blocked by a
   `basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)` guard —
   see the file's own doc-block. That guard compares basenames, and this
   test script's own basename is never `musician_helpers.php`, so a plain
   require_once here sails straight through it exactly as every OTHER
   consumer of this file does (the same reasoning
   test-musician-slug-resolve.php's doc-block spells out). */
require_once $root . '/appWeb/public_html/includes/musician_helpers.php';

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
function assertTrue($actual, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($actual) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label" . ($detail !== '' ? "\n        $detail" : '') . "\n";
        $failed++;
    }
}
function assertInRange(float $actual, float $lo, float $hi, string $label): void
{
    assertTrue(
        $actual >= $lo && $actual <= $hi,
        $label,
        "expected {$lo} <= x <= {$hi}, actual " . number_format($actual, 4)
    );
}

/* ------------------------------------------------------------------------ *
 * ihymns_sim_name_score() — the PERSON-NAME scorer                        *
 * ------------------------------------------------------------------------ */
echo "1 — ihymns_sim_name_score()\n";

assertEq(ihymns_sim_name_score('John Newton', 'John Newton'), 1.0,
    'score: identical names → exactly 1.0');
assertEq(ihymns_sim_name_score('José', 'Jose'), 1.0,
    'score: accent-fold-identical names → exactly 1.0 (diacritics fold via ihymns_sim_fold)');
assertEq(ihymns_sim_name_score('', 'John Newton'), 0.0,
    'score: an empty side → exactly 0.0 (nothing to compare)');

/* "J. Newton" vs "John Newton" — a real abbreviation, not fold-identical
   (fold("j. newton") = "j newton" =/= fold("john newton") = "john newton").
   Empirically ~0.49 with the shipped 0.6/0.4 blend — asserted as a band so a
   legitimate future re-tune of the blend weights doesn't make this test
   brittle, while still catching a gross regression (e.g. weights swapped,
   or the token/edit sub-scores wired to the wrong operand). */
assertInRange(
    ihymns_sim_name_score('J. Newton', 'John Newton'),
    0.35, 0.65,
    'score: "J. Newton" vs "John Newton" (abbreviation) → mid band'
);

/* "Newton, John" vs "John Newton" — same two word-tokens in reversed order
   plus a comma. Token-Jaccard is 1.0 (same token SET); edit-distance is
   moderate (comma + reordering cost characters). Empirically ~0.67. */
assertInRange(
    ihymns_sim_name_score('Newton, John', 'John Newton'),
    0.55, 0.85,
    'score: comma-reversed name → higher band (full token overlap)'
);
assertTrue(
    ihymns_sim_name_score('Newton, John', 'John Newton')
        > ihymns_sim_name_score('J. Newton', 'John Newton'),
    'score: comma-reversal (full token overlap) scores higher than a bare abbreviation (partial token overlap)'
);

assertTrue(ihymns_sim_name_score('John Newton', 'Charles Wesley') < 0.5,
    'score: two unrelated names → well below the 0.85 default merge-review threshold');

/* ------------------------------------------------------------------------ *
 * ihymns_sim_name_normalise() / ihymns_sim_name_tokens() — the person-name *
 * fold does NOT strip a leading article (unlike the title fold it shares   *
 * its primitives with) — a surname particle like "La Trobe" must survive. *
 * ------------------------------------------------------------------------ */
echo "\n2 — ihymns_sim_name_normalise() / ihymns_sim_name_tokens()\n";

assertEq(ihymns_sim_name_normalise('La Trobe'), 'la trobe',
    'name-normalise: does NOT strip a leading "La" — person names keep surname particles');
assertEq(ihymns_sim_name_tokens('Newton, John'), ['newton', 'john'],
    'name-tokens: comma becomes a token boundary, order preserved');
assertEq(ihymns_sim_name_tokens(''), [],
    'name-tokens: empty input → empty list');

/* ------------------------------------------------------------------------ *
 * musicianNameVariantClass() — the 6-rung ladder                          *
 * ------------------------------------------------------------------------ */
echo "\n3 — musicianNameVariantClass()\n";

assertEq(musicianNameVariantClass('Eddie James', 'Eddie James'), 'identical',
    'variant: byte-identical strings → identical');

assertEq(musicianNameVariantClass('Eddie James', 'Eddie James '), 'whitespace',
    'variant: trailing space → whitespace');
assertEq(musicianNameVariantClass('Eddie James', "Eddie\xC2\xA0James"), 'whitespace',
    'variant: interior NBSP → whitespace');
assertEq(musicianNameVariantClass('Eddie James', 'Eddie  James'), 'whitespace',
    'variant: interior DOUBLE space → whitespace (§3.1 — the class that CAN coexist as two live registry rows)');

assertEq(musicianNameVariantClass('Eddie James', 'eddie james'), 'case',
    'variant: case-only difference → case');

/* Precomposed "é" (U+00E9) vs "e" + COMBINING ACUTE ACCENT (U+0065 U+0301) —
   the SAME rendered glyph, two different Unicode encodings. Built from
   explicit byte sequences (not a source-literal é) so the test fixture
   itself is unambiguous about which of the two forms is which. */
$precomposedE = "Jos\xC3\xA9";      // "José", é = U+00E9
$combiningE   = "Jose\xCC\x81";     // "Jose" + U+0301 COMBINING ACUTE ACCENT
assertTrue($precomposedE !== $combiningE,
    'fixture sanity: the two byte sequences really are different (guards against a fixture typo silently passing)');
assertEq(musicianNameVariantClass($precomposedE, $combiningE), 'unicode-normalisation',
    'variant: combining vs precomposed accent (same glyph, different bytes) → unicode-normalisation');

assertEq(musicianNameVariantClass("O'Brien", "O\xE2\x80\x99Brien"), 'punctuation',
    'variant: straight vs curly apostrophe → punctuation');
assertEq(musicianNameVariantClass('J. Newton', 'J Newton'), 'punctuation',
    'variant: period present/absent → punctuation');
assertEq(musicianNameVariantClass('Anna-Marie', "Anna\xE2\x80\x93Marie"), 'punctuation',
    'variant: hyphen vs en-dash → punctuation');

assertEq(musicianNameVariantClass('John', 'Jane'), null,
    'variant: genuinely different words → null (the fuzzy-score case, not a byte-variant)');

/* ------------------------------------------------------------------------ *
 * musicianNameVariantDetail() / musicianCodePointLabel() — the tooltip    *
 * detail behind the badge.                                                 *
 * ------------------------------------------------------------------------ */
echo "\n4 — musicianNameVariantDetail() / musicianCodePointLabel()\n";

assertEq(musicianNameVariantDetail('Same', 'Same'), null,
    'detail: identical strings → null (nothing to report)');

$detail = musicianNameVariantDetail("O'Brien", "O\xE2\x80\x99Brien");
assertTrue(is_array($detail), 'detail: apostrophe pair → returns an array', var_export($detail, true));
if (is_array($detail)) {
    assertEq($detail['a'], "U+0027 (')", 'detail: side A labelled as straight apostrophe U+0027');
    assertEq($detail['b'], "U+2019 (\xE2\x80\x99)", 'detail: side B labelled as curly apostrophe U+2019');
}

assertEq(musicianCodePointLabel(null), "\xE2\x88\x85 (none)",
    'code-point label: null input → "∅ (none)"');
assertEq(musicianCodePointLabel('A'), 'U+0041 (A)',
    'code-point label: ASCII "A" → U+0041');

/* ------------------------------------------------------------------------ *
 * MUSICIAN_CREDIT_ROLE_TABLES — defined in this commit, not yet consumed  *
 * anywhere (C5 re-points every fork onto it); pin its shape now so a       *
 * later commit can't silently change the six table names out from under  *
 * every consumer it's about to grow.                                      *
 * ------------------------------------------------------------------------ */
echo "\n5 — MUSICIAN_CREDIT_ROLE_TABLES\n";

assertEq(MUSICIAN_CREDIT_ROLE_TABLES, [
    'writer'     => 'tblSongWriters',
    'composer'   => 'tblSongComposers',
    'arranger'   => 'tblSongArrangers',
    'adaptor'    => 'tblSongAdaptors',
    'translator' => 'tblSongTranslators',
    'artist'     => 'tblSongArtists',
], 'MUSICIAN_CREDIT_ROLE_TABLES: exactly the six credit-role tables, artist included');

/* ------------------------------------------------------------------------ */
echo "\n";
echo "musician-name-similarity: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
