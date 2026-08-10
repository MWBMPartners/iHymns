<?php

declare(strict_types=1);

/**
 * test-musician-dup-scan.php — registry-duplicate scan honesty (#1785 C6, guard G4)
 * ====================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING PINNED
 * ---------------------
 * `musicianDuplicatesFindCandidates()` (includes/musician_duplicates.php) —
 * the PURE candidate-generator behind `/manage/musician-duplicates` (#1785
 * §7). "Pure" here means it has NO \mysqli anywhere in its call graph, so
 * this file can hand it plain synthetic name arrays and assert exact
 * groups/pairs/misses without a database — the split rule #1785's plan §6
 * calls out explicitly.
 *
 * Also pins `musicianDuplicatesSuggestSurvivor()` — the pure default-
 * survivor picker (plan D2) — and `musicianDuplicatesDateLabel()`, the
 * partial-date display helper the bulk payload builder uses.
 *
 * SEEDED FIXTURES (rule #34 — derived from the plan's OWN worked
 * experiment, not invented ad hoc): §3.1 of the plan ran a real MySQL
 * uk_Name-coexistence experiment and found exactly four variant classes
 * that can coexist as two DISTINCT live registry rows — interior double
 * space, straight-vs-curly apostrophe, period present/absent, hyphen-vs-
 * en-dash. Assertion group 1 below is that exact fixture list, so this
 * test is provably testing the SAME classes the plan's own research
 * identified, not a re-invented set that happens to also fold-equal.
 *
 * MUTATION-PROOF (rule #34 — recorded once landed alongside G1-G3/G5;
 * the required "broken -> red -> restored" pass is run and documented in
 * the #1785 C6 commit body, not claimed here in advance):
 *   - comment out the bucket-cap `> $bucketCap` check in Bucket A -> the
 *     over-cap assertion (group 4) goes red (no skippedBuckets entry).
 *   - delete the Disambiguation-exclusion `$pairAllowed` call in Bucket A
 *     -> the exclusion assertion (group 5) goes red (the excluded pair
 *     reappears in `groups`).
 *   - swap `$useA > $useB` to `<` in musicianDuplicatesSuggestSurvivor()
 *     -> the survivor-ladder assertions (group 6) go red.
 *
 * Usage:
 *   php tests/php/test-musician-dup-scan.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/musician_duplicates.php
 * @see .claude/musicians-dedup-1785-plan.md §3.3, §6, §10 (guard G4)
 */

$root = dirname(__DIR__, 2);
require_once $root . '/appWeb/public_html/includes/musician_duplicates.php';

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

/** Find a group in a scan result containing exactly the given id set. */
function findGroupWithMembers(array $groups, array $ids): ?array
{
    sort($ids);
    foreach ($groups as $g) {
        $m = $g['members'];
        sort($m);
        if ($m === $ids) { return $g; }
    }
    return null;
}
/** Does the pair list contain an (aId,bId) pair, either order? */
function pairsContain(array $pairs, int $a, int $b): ?array
{
    foreach ($pairs as $p) {
        if (($p['aId'] === $a && $p['bId'] === $b) || ($p['aId'] === $b && $p['bId'] === $a)) {
            return $p;
        }
    }
    return null;
}

/* ------------------------------------------------------------------------ *
 * 1 — Bucket A: the plan §3.1 coexistence fixtures, verbatim.              *
 * ------------------------------------------------------------------------ */
echo "1 — Bucket A: §3.1 coexisting-variant fixtures\n";

$rows = [
    ['id' => 1, 'name' => 'Eddie James'],
    ['id' => 2, 'name' => 'Eddie  James'],       // interior double space
    ['id' => 3, 'name' => "O'Brien"],
    ['id' => 4, 'name' => "O\xE2\x80\x99Brien"], // curly apostrophe
    ['id' => 5, 'name' => 'J. Newton'],
    ['id' => 6, 'name' => 'J Newton'],           // period absent
    ['id' => 7, 'name' => 'Anna-Marie'],
    ['id' => 8, 'name' => "Anna\xE2\x80\x93Marie"], // en-dash
];
$result = musicianDuplicatesFindCandidates($rows);

$g12 = findGroupWithMembers($result['groups'], [1, 2]);
assertTrue($g12 !== null, 'Eddie James / Eddie  James (double space) -> grouped in Bucket A');
if ($g12) {
    assertEq($g12['pairs'][0][2], 'whitespace', '…classified as "whitespace"');
}

$g34 = findGroupWithMembers($result['groups'], [3, 4]);
assertTrue($g34 !== null, "O'Brien / O\xE2\x80\x99Brien (apostrophe) -> grouped in Bucket A");
if ($g34) {
    assertEq($g34['pairs'][0][2], 'punctuation', '…classified as "punctuation"');
}

$g56 = findGroupWithMembers($result['groups'], [5, 6]);
assertTrue($g56 !== null, 'J. Newton / J Newton (period) -> grouped in Bucket A');
if ($g56) {
    assertEq($g56['pairs'][0][2], 'punctuation', '…classified as "punctuation"');
}

$g78 = findGroupWithMembers($result['groups'], [7, 8]);
assertTrue($g78 !== null, "Anna-Marie / Anna\xE2\x80\x93Marie (dash) -> grouped in Bucket A");
if ($g78) {
    assertEq($g78['pairs'][0][2], 'punctuation', '…classified as "punctuation"');
}

assertEq($result['stats']['registryRows'], 8, 'stats.registryRows counts every seeded row');
assertEq($result['stats']['foldGroups'], 4, 'stats.foldGroups counts exactly the four coexisting-variant groups');

/* ------------------------------------------------------------------------ *
 * 2 — Bucket B: fuzzy pair via metaphone blocking, scored with the shared  *
 *     scorer (rule #22) and gated on the threshold.                       *
 * ------------------------------------------------------------------------ */
echo "\n2 — Bucket B: fuzzy candidates\n";

$fuzzyRows = [
    ['id' => 10, 'name' => 'John Newton'],
    ['id' => 11, 'name' => 'Newton, John'],   // comma-reversed — shares last-token metaphone with 10; scores ~0.67 (G1)
    ['id' => 12, 'name' => 'Charles Wesley'], // unrelated — must NOT pair with either above
];
/* threshold is clamped to the documented 0.5-1.0 range (plan §6's opts
   doc-comment) — 0.6 is comfortably below the ~0.67 comma-reversal score
   and comfortably above the default 0.85, so it isolates "found at a
   lowered-but-valid threshold, not at the default" cleanly. */
$fz = musicianDuplicatesFindCandidates($fuzzyRows, [], ['threshold' => 0.6]);
$p1011 = pairsContain($fz['pairs'], 10, 11);
assertTrue($p1011 !== null, 'John Newton / Newton, John -> a scored fuzzy pair at a lowered threshold');
if ($p1011) {
    assertEq($p1011['signal'], 'fuzzy', '…signal is "fuzzy"');
    assertTrue($p1011['score'] > 0.0, '…score is positive', 'score=' . $p1011['score']);
}
assertTrue(pairsContain($fz['pairs'], 10, 12) === null, 'John Newton / Charles Wesley -> never a candidate pair (no shared block, unrelated words)');
assertTrue($fz['stats']['fuzzyPairsScored'] >= 1, 'stats.fuzzyPairsScored counts at least the one scored pair');

/* The default 0.85 threshold must exclude the SAME pair — proves the
   threshold gate is honoured, not bypassed. */
$fzStrict = musicianDuplicatesFindCandidates($fuzzyRows); // default threshold 0.85
assertTrue(pairsContain($fzStrict['pairs'], 10, 11) === null,
    'John Newton / Newton, John -> NOT a candidate at the default 0.85 threshold (comma-reversal scores well under it)');

/* ------------------------------------------------------------------------ *
 * 3 — Bucket C: alias signal (gated on the caller supplying alias rows).  *
 * ------------------------------------------------------------------------ */
echo "\n3 — Bucket C: alias signal\n";

$aliasRows = [
    ['id' => 20, 'name' => 'Frances van Alstyne'],
    ['id' => 21, 'name' => 'Fanny Crosby'], // a DIFFERENT registry spelling…
];
$aliases = [
    ['musicianId' => 21, 'name' => 'Frances van Alstyne'], // …but curated as an alias of id 21
];
$ac = musicianDuplicatesFindCandidates($aliasRows, $aliases);
$p2021 = pairsContain($ac['pairs'], 20, 21);
assertTrue($p2021 !== null, 'a registry Name matching ANOTHER row\'s curated alias -> a candidate pair');
if ($p2021) {
    assertEq($p2021['signal'], 'alias-match', '…signal is "alias-match"');
    assertEq($p2021['score'], 1.0, '…score is 1.0 (curated signal, not a fuzzy estimate)');
}

/* An alias that only restates the OWNER's own name is not a signal against
   anyone else. */
$selfAliasRows = [['id' => 30, 'name' => 'Eddie James']];
$selfAlias     = [['musicianId' => 30, 'name' => 'Eddie James']];
$sa = musicianDuplicatesFindCandidates($selfAliasRows, $selfAlias);
assertEq($sa['pairs'], [], 'an alias equal to its OWN owner\'s name -> no self-pair fabricated');

/* ------------------------------------------------------------------------ *
 * 4 — No silent caps (rule #35): an over-cap bucket is SKIPPED and         *
 *     reported, never quietly truncated or silently scored anyway.        *
 * ------------------------------------------------------------------------ */
echo "\n4 — over-cap buckets are reported, never silent\n";

$capRows = [];
for ($i = 1; $i <= 5; $i++) { $capRows[] = ['id' => $i, 'name' => 'Eddie James']; } // one fold-equal bucket of 5
$capped = musicianDuplicatesFindCandidates($capRows, [], ['bucketCap' => 2]);
assertEq($capped['groups'], [], 'an over-cap Bucket A group is SKIPPED -> not present in groups at all');
$skipKeys = array_column($capped['stats']['skippedBuckets'], 'key');
assertTrue(in_array('fold:eddie james', $skipKeys, true),
    'the skip IS surfaced in stats.skippedBuckets, keyed by fold', var_export($capped['stats']['skippedBuckets'], true));
$skipSize = 0;
foreach ($capped['stats']['skippedBuckets'] as $sb) { if ($sb['key'] === 'fold:eddie james') { $skipSize = $sb['size']; } }
assertEq($skipSize, 5, '…and the reported size is the TRUE bucket size (5), not the cap');

/* Same proof for Bucket B (metaphone blocking). */
$capFuzzyRows = [];
for ($i = 1; $i <= 5; $i++) { $capFuzzyRows[] = ['id' => 100 + $i, 'name' => 'John Newton the ' . $i . 'th']; }
$cappedFuzzy = musicianDuplicatesFindCandidates($capFuzzyRows, [], ['bucketCap' => 2, 'threshold' => 0.1]);
assertTrue(count($cappedFuzzy['stats']['skippedBuckets']) > 0, 'an over-cap Bucket B block is also reported in skippedBuckets');

/* ------------------------------------------------------------------------ *
 * 5 — Disambiguation exclusion (plan §3.1 — dead code today under         *
 *     uk_Name, load-bearing the day it's relaxed; costs one `if`).        *
 * ------------------------------------------------------------------------ */
echo "\n5 — Disambiguation exclusion\n";

$disambigRows = [
    ['id' => 40, 'name' => 'John Smith', 'disambiguation' => '(the elder)'],
    ['id' => 41, 'name' => 'John Smith', 'disambiguation' => '(the younger)'],
];
$dr = musicianDuplicatesFindCandidates($disambigRows);
assertEq($dr['groups'], [], 'two fold-equal rows with DIFFERING non-empty Disambiguation -> excluded entirely, not grouped');

/* A blank Disambiguation on either (or both) sides must NOT trigger the
   exclusion -- only a genuine, non-empty DISAGREEMENT does. */
$oneBlankRows = [
    ['id' => 42, 'name' => 'John Smith', 'disambiguation' => '(the elder)'],
    ['id' => 43, 'name' => 'John Smith', 'disambiguation' => ''],
];
$ob = musicianDuplicatesFindCandidates($oneBlankRows);
assertTrue(findGroupWithMembers($ob['groups'], [42, 43]) !== null,
    'one side blank Disambiguation -> NOT excluded (only a genuine disagreement is)');

/* ------------------------------------------------------------------------ *
 * 6 — musicianDuplicatesSuggestSurvivor() — pure default-survivor ladder. *
 * ------------------------------------------------------------------------ */
echo "\n6 — musicianDuplicatesSuggestSurvivor()\n";

$richA = ['id' => 1, 'useCount' => 5, 'born' => null, 'died' => null, 'disambiguation' => '', 'hasMbid' => false, 'links' => 0, 'identifiers' => 0, 'aliases' => 0];
$richB = ['id' => 2, 'useCount' => 1, 'born' => null, 'died' => null, 'disambiguation' => '', 'hasMbid' => false, 'links' => 0, 'identifiers' => 0, 'aliases' => 0];
assertEq(musicianDuplicatesSuggestSurvivor($richA, $richB), 'a', 'higher useCount wins outright');
assertEq(musicianDuplicatesSuggestSurvivor($richB, $richA), 'b', '…direction-agnostic (symmetric under swap)');

$tieUse1 = ['id' => 1, 'useCount' => 3, 'born' => '1823', 'died' => null, 'disambiguation' => '', 'hasMbid' => false, 'links' => 0, 'identifiers' => 0, 'aliases' => 0];
$tieUse2 = ['id' => 2, 'useCount' => 3, 'born' => null,   'died' => null, 'disambiguation' => '', 'hasMbid' => false, 'links' => 0, 'identifiers' => 0, 'aliases' => 0];
assertEq(musicianDuplicatesSuggestSurvivor($tieUse1, $tieUse2), 'a', 'tied useCount -> richer metadata (a birth year) wins');

$tieAll1 = ['id' => 5, 'useCount' => 0, 'born' => null, 'died' => null, 'disambiguation' => '', 'hasMbid' => false, 'links' => 0, 'identifiers' => 0, 'aliases' => 0];
$tieAll2 = ['id' => 9, 'useCount' => 0, 'born' => null, 'died' => null, 'disambiguation' => '', 'hasMbid' => false, 'links' => 0, 'identifiers' => 0, 'aliases' => 0];
assertEq(musicianDuplicatesSuggestSurvivor($tieAll1, $tieAll2), 'a', 'tied on everything -> lower id (the older row) wins, here side "a"');
/* Swap the ARGUMENT order (not just the ids): the lower-id row (id 5) is
   now passed as side "b" — the function must follow the LOWER ID, not a
   fixed argument position, so the winning LABEL flips to "b" while the
   winning identity (id 5) stays the same. */
assertEq(musicianDuplicatesSuggestSurvivor($tieAll2, $tieAll1), 'b', '…the lower id (5) still wins even when it is passed as side "b"');

/* ------------------------------------------------------------------------ *
 * 7 — musicianDuplicatesDateLabel() — partial-date display helper.       *
 * ------------------------------------------------------------------------ */
echo "\n7 — musicianDuplicatesDateLabel()\n";

assertEq(musicianDuplicatesDateLabel(null, null), null, 'no date -> null');
assertEq(musicianDuplicatesDateLabel('', null), null, 'empty-string date -> null');
assertEq(musicianDuplicatesDateLabel('1823-01-01', 'year'), '1823', 'year precision -> just the year');
assertEq(musicianDuplicatesDateLabel('1823-03-01', 'month'), '1823-03', 'month precision -> year-month');
assertEq(musicianDuplicatesDateLabel('1823-03-17', 'day'), '1823-03-17', 'day precision -> the full date');
assertEq(musicianDuplicatesDateLabel('1823-03-17', null), '1823', 'no precision recorded -> falls back to year-only (un-migrated-install shape)');

/* ------------------------------------------------------------------------ *
 * 9 — groupsOnly (the CTA-badge fast path, #1785 §7): Bucket A only, no    *
 *     fuzzy scoring performed at all.                                    *
 * ------------------------------------------------------------------------ */
echo "\n9 — groupsOnly fast path\n";

$goRows = [
    ['id' => 50, 'name' => 'Eddie James'],
    ['id' => 51, 'name' => 'Eddie  James'], // Bucket A fold-equal
    ['id' => 52, 'name' => 'John Newton'],
    ['id' => 53, 'name' => 'Newton, John'], // would be a Bucket B fuzzy pair, NOT fold-equal
];
$go = musicianDuplicatesFindCandidates($goRows, [], ['groupsOnly' => true]);
assertTrue(findGroupWithMembers($go['groups'], [50, 51]) !== null, 'Bucket A group still found with groupsOnly=true');
assertEq($go['pairs'], [], 'groupsOnly=true -> Bucket B/C never run, pairs is empty even though a fuzzy pair WOULD exist');
assertEq($go['stats']['fuzzyPairsScored'], 0, 'groupsOnly=true -> fuzzyPairsScored stays 0 (no scoring work done at all)');

/* ------------------------------------------------------------------------ *
 * 10 — musicianDuplicatesLifespanConflict() — the merge force-gate signal. *
 * ------------------------------------------------------------------------ */
echo "\n10 — musicianDuplicatesLifespanConflict()\n";

assertTrue(
    musicianDuplicatesLifespanConflict(['born' => '1823', 'died' => null], ['born' => '1825', 'died' => null]),
    'differing birth years, both present -> conflict'
);
assertTrue(
    musicianDuplicatesLifespanConflict(['born' => null, 'died' => '1901'], ['born' => null, 'died' => '1899']),
    'differing death years, both present -> conflict'
);
assertTrue(
    !musicianDuplicatesLifespanConflict(['born' => '1823', 'died' => null], ['born' => null, 'died' => null]),
    'one side blank birth year -> NOT a conflict (unknown, not disagreeing)'
);
assertTrue(
    !musicianDuplicatesLifespanConflict(['born' => '1823-03-17', 'died' => null], ['born' => '1823', 'died' => null]),
    'same YEAR at different precisions (day vs year-only) -> NOT a conflict (year-granularity comparison)'
);
assertTrue(
    !musicianDuplicatesLifespanConflict(['born' => null, 'died' => null], ['born' => null, 'died' => null]),
    'neither side has any date -> NOT a conflict'
);

/* ------------------------------------------------------------------------ *
 * 11 — Empty input is well-defined, not a crash.                         *
 * ------------------------------------------------------------------------ */
echo "\n11 — empty input\n";

$empty = musicianDuplicatesFindCandidates([]);
assertEq($empty['groups'], [], 'no rows -> no groups');
assertEq($empty['pairs'], [], 'no rows -> no pairs');
assertEq($empty['stats']['registryRows'], 0, 'no rows -> registryRows is 0');

/* ------------------------------------------------------------------------ */
echo "\n";
echo "musician-dup-scan: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
