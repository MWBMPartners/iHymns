<?php

declare(strict_types=1);

/**
 * iHymns — Voice-part vocabulary + normaliser truth table (#2073, commit 1)
 *
 * ELI5: this proves the fixed "who sings this" word list in
 * `includes/vocal_parts.php` is internally consistent, and that the small
 * functions which turn typed text ("MEN", "Group 2", "2nd") into one of its
 * 22 kinds actually do that correctly — a FUNCTIONAL truth table, not a
 * grep over the source text (rule #34 of .claude/CLAUDE.md: a guard must be
 * mutation-tested and prove it can fail, never just assert a file contains
 * some string).
 *
 * No DB connection is used or needed — everything under test here is a
 * pure PHP function. `includes/vocal_parts.php` itself `require_once`s
 * `db_mysql.php` and `lyric_lines_read.php`, but neither file opens a
 * connection merely by being loaded (both are lazy — `getDbMysqli()` /
 * a live `\mysqli` parameter are only touched when a DB-backed function in
 * `vocal_parts.php` is actually CALLED, and this test calls none of those).
 *
 *   php tests/php/test-vocal-parts-vocab.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failed.
 *
 * @see appWeb/public_html/includes/vocal_parts.php   the file under test
 * @see .claude/vocal-parts-2073-plan.md               the plan of record ("Design pass 7" §1, §3.1; "Design pass 2" §1)
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/vocal_parts.php';

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
        if ($detail !== '') {
            echo "        $detail\n";
        }
        $failed++;
    }
}

function assertEq($actual, $expected, string $label): void
{
    ok($label, $actual === $expected, 'expected: ' . var_export($expected, true) . ' | actual: ' . var_export($actual, true));
}

/* ====================================================================== *
 * 1 — IHYMNS_VOCAL_PART_KINDS structural invariants
 * ====================================================================== */
echo "1 — vocabulary shape\n";

$kinds = IHYMNS_VOCAL_PART_KINDS;
/* ⚠️ PLAN DISCREPANCY (flagged, not silently "corrected" away): both
   "Design pass 2" §1.2 and "Design pass 7" §3.1 of
   .claude/vocal-parts-2073-plan.md say this map has "22 keys" / "22
   canonical keys" in their PROSE, but the VERBATIM `const
   IHYMNS_VOCAL_PART_KINDS = [ … ]` array literal Pass 2 §1.2 actually
   writes out (which Pass 7 explicitly says to copy "exactly") lists only
   21 entries — lead, soloist, named-singer, male, female, children, all,
   unison, duet, group, choir, congregation, cantor, descant, soprano,
   alto, tenor, bass, backing, narrator, spoken. This file implements the
   VERBATIM literal (the thing both passes agree is authoritative), so
   this assertion pins the REAL count (21), not the prose's miscounted
   claim — reported loudly in this commit's summary rather than padding
   the map with an invented 22nd kind to make the prose right. */
ok('21 kinds — the VERBATIM count in the plan\'s own array literal (its prose says "22", which is the plan\'s own miscount)', count($kinds) === 21, 'got: ' . count($kinds));

$facetProblems = [];
foreach ($kinds as $key => $def) {
    foreach (['label', 'description', 'gender', 'markers', 'openlyrics', 'ttmlAgent'] as $facet) {
        if (!array_key_exists($facet, $def)) {
            $facetProblems[] = "$key: missing facet '$facet'";
        }
    }
    if (($def['label'] ?? '') === '') {
        $facetProblems[] = "$key: empty label";
    }
    if (($def['description'] ?? '') === '') {
        $facetProblems[] = "$key: empty description";
    }
    if ($def['gender'] !== null && !in_array($def['gender'], IHYMNS_VOCAL_GENDERS, true)) {
        $facetProblems[] = "$key: gender '{$def['gender']}' not in IHYMNS_VOCAL_GENDERS";
    }
    if (!is_array($def['markers'] ?? null)) {
        $facetProblems[] = "$key: markers is not an array";
    }
    if (!in_array($def['ttmlAgent'] ?? null, ['person', 'group', 'other'], true)) {
        $facetProblems[] = "$key: ttmlAgent '{$def['ttmlAgent']}' not one of person|group|other";
    }
    /* Every kind except named-singer carries a real OpenLyrics keyword — its
       own is the one deliberate null (rule #1.3 of the plan: it has no
       fixed word, only a name). */
    if ($key !== 'named-singer' && ($def['openlyrics'] ?? null) === null) {
        $facetProblems[] = "$key: openlyrics keyword is null (only named-singer may be)";
    }
}
ok('every kind carries all six facets with valid values', $facetProblems === [], implode('; ', $facetProblems));

/* Marker keys must be upper-case (the fold contract vocalPartsKindFromWord()
   relies on) and unique ACROSS kinds — the same word can never mean two
   different voices, or resolution would silently depend on map order. */
$markerOwner = [];
$caseProblems = [];
$dupProblems = [];
foreach ($kinds as $key => $def) {
    foreach (array_keys($def['markers']) as $marker) {
        if ($marker !== mb_strtoupper($marker, 'UTF-8')) {
            $caseProblems[] = "$key: marker '$marker' is not upper-case";
        }
        if (isset($markerOwner[$marker])) {
            $dupProblems[] = "'$marker' claimed by both '{$markerOwner[$marker]}' and '$key'";
        }
        $markerOwner[$marker] = $key;
    }
}
ok('every marker word is upper-case', $caseProblems === [], implode('; ', $caseProblems));
ok('no marker word is claimed by two different kinds', $dupProblems === [], implode('; ', $dupProblems));

/* Every alias must resolve to a REAL kind key — an alias pointing nowhere
   would make vocalPartsNormalizeKind() return a string nothing else
   recognises. */
$aliasProblems = [];
foreach (IHYMNS_VOCAL_PART_KIND_ALIASES as $alias => $target) {
    if (!isset($kinds[$target])) {
        $aliasProblems[] = "'$alias' => '$target' (no such kind)";
    }
}
ok('every alias target is a real kind key', $aliasProblems === [], implode('; ', $aliasProblems));

/* Every IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS entry must be a REAL marker
   word somewhere in the map above — it is a MODIFIER on an existing
   marker (forces Confidence='low'), never an independent vocabulary of
   its own. A typo here would silently mean the detector never actually
   matches the word it thinks it is being careful about. */
$allMarkerWords = [];
foreach ($kinds as $def) {
    foreach (array_keys($def['markers']) as $marker) {
        $allMarkerWords[$marker] = true;
    }
}
$ambiguousProblems = [];
foreach (IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS as $word) {
    if (!isset($allMarkerWords[$word])) {
        $ambiguousProblems[] = "'$word' is not a real marker word in IHYMNS_VOCAL_PART_KINDS";
    }
}
ok('every IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS entry is a real marker word', $ambiguousProblems === [], implode('; ', $ambiguousProblems));

/* ====================================================================== *
 * 2 — marker words vs the tblSongPartTypes section vocabulary (tree-
 * derived from the migration's own seed data, never hand-typed — rule #34)
 * ====================================================================== */
echo "\n2 — markers never collide with a SECTION word (tree-derived)\n";

$partTypesFile = $root . '/appWeb/.sql/migrate-song-part-types.php';
$partTypesSrc = (string) @file_get_contents($partTypesFile);
if (preg_match('/\$seed\s*=\s*\[(.*?)\];/s', $partTypesSrc, $seedMatch)) {
    preg_match_all("/'([^']+)',\\s*'([^']+)',\\s*\\d+\\s*\\]/", $seedMatch[1], $seedRows, PREG_SET_ORDER);
    $sectionWords = [];
    foreach ($seedRows as $row) {
        $sectionWords[] = mb_strtoupper($row[2], 'UTF-8'); // the human-readable 'Name', not the slug
    }
    ok(
        'parsed the tblSongPartTypes seed list from the migration (tree-derived)',
        count($sectionWords) >= 15,
        'parsed ' . count($sectionWords) . ' names: ' . implode(',', $sectionWords)
    );
} else {
    $sectionWords = [];
    ok('parsed the tblSongPartTypes seed list from the migration (tree-derived)', false, "could not find \$seed in $partTypesFile");
}

/**
 * The invariant-check itself, factored into a function so §2b below can run
 * it a SECOND time against a deliberately corrupted copy of the vocabulary
 * and prove it goes red (rule #34 — a guard must be shown able to fail,
 * not just shown passing once).
 *
 * @param array<string,array{markers:array<string,mixed>}> $kindsMap
 * @param list<string>                                     $sectionWordsUpper
 * @return list<string> every colliding marker word found
 */
function _vpvCollisions(array $kindsMap, array $sectionWordsUpper): array
{
    $found = [];
    foreach ($kindsMap as $def) {
        foreach (array_keys($def['markers']) as $marker) {
            if (in_array($marker, $sectionWordsUpper, true)) {
                $found[] = $marker;
            }
        }
    }
    return array_values(array_unique($found));
}

/* KNOWN, NAMED, count-exact allowance — see IHYMNS_VOCAL_PART_KINDS's own
   doc-block ("KNOWN, DELIBERATE OVERLAP") for the full reasoning: "Solo"
   the instrumental section and "SOLO" the one-singer voice marker are
   different real concepts sharing one English word. Any OTHER collision is
   new and must fail the build immediately — this allowance can only ever
   shrink, never silently grow (the same shape as
   tests/php/test-schema-ddl-parity.php's $allow list).

   SOURCED FROM PRODUCTION, not hand-typed here a second time (rule #35):
   IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS is the SAME list the (not-yet-
   written) detector will consult to force Confidence='low' on exactly
   these words — see that constant's own doc-block. Reusing it here means
   a marker added to one list without the other fails a DIFFERENT
   assertion below ("every ambiguous-section-marker is a real marker
   word"), not just this one — the two concerns stay mechanically tied
   together instead of drifting apart via separate prose. */
$allowedCollisions = IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS;

if ($sectionWords) {
    $realCollisions = _vpvCollisions($kinds, $sectionWords);
    $unexpected = array_values(array_diff($realCollisions, $allowedCollisions));
    $missingAllowance = array_values(array_diff($allowedCollisions, $realCollisions));
    ok(
        'no UNEXPECTED marker/section-word collision (only the documented SOLO overlap is allowed)',
        $unexpected === [],
        'unexpected collisions: ' . implode(',', $unexpected)
    );
    ok(
        'the documented SOLO allowance is still actually true (not stale)',
        $missingAllowance === [],
        'allowance claims a collision that no longer exists: ' . implode(',', $missingAllowance)
    );
}

/* 2b — MUTATION PROOF: the check function above must be ABLE to fail.
   Inject a deliberately corrupted copy of the map (an extra, wrong
   marker word that collides with a real section name) and confirm
   _vpvCollisions() reports it — proving this is a real, working check
   and not a tautology that always passes. Never mutates the real
   IHYMNS_VOCAL_PART_KINDS constant. */
$corrupted = $kinds;
$corrupted['choir']['markers']['CHORUS'] = null; // CHORUS is a real tblSongPartTypes name
$mutationCaught = $sectionWords
    ? in_array('CHORUS', _vpvCollisions($corrupted, $sectionWords), true)
    : false;
ok(
    'MUTATION PROOF: injecting a real section word (CHORUS) as a marker is caught by the checker',
    $sectionWords ? $mutationCaught : true,
    $sectionWords ? '' : '(skipped — section-word list did not parse, see 2 above)'
);

/* ====================================================================== *
 * 3 — vocalPartsNormalizeKind()
 * ====================================================================== */
echo "\n3 — vocalPartsNormalizeKind()\n";

assertEq(vocalPartsNormalizeKind('MAIN'), 'lead', 'MAIN (legacy schema alias) -> lead');
assertEq(vocalPartsNormalizeKind('  Lead  '), 'lead', 'whitespace-padded exact key -> lead');
assertEq(vocalPartsNormalizeKind('men'), 'male', 'men (lower-case marker word, not a key or alias) -> male');
assertEq(vocalPartsNormalizeKind('WOMEN'), 'female', 'WOMEN (upper-case marker word) -> female');
assertEq(vocalPartsNormalizeKind('x-bg'), 'backing', 'x-bg (TTML ttm:role shorthand alias) -> backing');
assertEq(vocalPartsNormalizeKind('kid'), 'children', 'kid (alias) -> children');
assertEq(vocalPartsNormalizeKind('sop'), 'soprano', 'sop (abbreviation alias) -> soprano');
assertEq(vocalPartsNormalizeKind('nope'), null, 'unrecognised word -> null');
assertEq(vocalPartsNormalizeKind(''), null, 'empty string -> null');
assertEq(vocalPartsNormalizeKind('chorus'), null, 'chorus (a SECTION word, never a voice) -> null');

/* ====================================================================== *
 * 4 — vocalPartsKindFromWord()
 * ====================================================================== */
echo "\n4 — vocalPartsKindFromWord()\n";

assertEq(vocalPartsKindFromWord('MEN    '), ['kind' => 'male', 'label' => null], 'MEN with trailing spaces');
assertEq(vocalPartsKindFromWord("MEN\u{00A0}\u{00A0}\u{00A0}\u{00A0}"), ['kind' => 'male', 'label' => null], 'MEN with trailing non-breaking spaces collapse to a plain match');
assertEq(vocalPartsKindFromWord('ladies:'), ['kind' => 'female', 'label' => null], 'ladies: (trailing colon stripped)');
assertEq(vocalPartsKindFromWord('BOYS'), ['kind' => 'male', 'label' => 'Boys'], 'BOYS -> male with the override label "Boys"');
assertEq(vocalPartsKindFromWord('ALL:'), ['kind' => 'all', 'label' => null], 'ALL: -> all');
assertEq(vocalPartsKindFromWord('HALLELUJAH'), null, 'an unrelated word -> null');
assertEq(vocalPartsKindFromWord('CHORUS'), null, 'CHORUS (a section word) -> null, never choir');

/* The merged ordinal pattern (see IHYMNS_VOCAL_GROUP_ORDINAL_RE's own
   doc-block for why this test exercises BOTH the prefixed and the bare
   forms — the two design passes each covered only one). */
assertEq(vocalPartsKindFromWord('Group 2'), ['kind' => 'group', 'label' => 'Group 2'], 'Group 2 (prefixed digit)');
assertEq(vocalPartsKindFromWord('VOICE II'), ['kind' => 'group', 'label' => 'Group 2'], 'VOICE II (prefixed Roman numeral)');
assertEq(vocalPartsKindFromWord('2nd'), ['kind' => 'group', 'label' => 'Group 2'], '2nd (BARE ordinal digit, no prefix — Pass 2\'s own test case)');
assertEq(vocalPartsKindFromWord('THIRD GROUP'), ['kind' => 'group', 'label' => 'Group 3'], 'THIRD GROUP (bare ordinal word + trailing GROUP)');
assertEq(vocalPartsKindFromWord('LEFT'), ['kind' => 'group', 'label' => 'Left side'], 'LEFT (bare side word)');
assertEq(vocalPartsKindFromWord('RIGHT SIDE'), ['kind' => 'group', 'label' => 'Right side'], 'RIGHT SIDE (bare side word + SIDE)');
assertEq(vocalPartsKindFromWord('SIDE LEFT'), ['kind' => 'group', 'label' => 'Left side'], 'SIDE LEFT (prefixed side word)');

assertEq(vocalPartsKindFromWord(''), null, 'empty string -> null');
assertEq(vocalPartsKindFromWord('   '), null, 'whitespace only -> null');

/* ====================================================================== *
 * 5 — vocalPartsImpliedGender()
 * ====================================================================== */
echo "\n5 — vocalPartsImpliedGender()\n";

assertEq(vocalPartsImpliedGender('male'), 'male', 'male kind implies male');
assertEq(vocalPartsImpliedGender('female'), 'female', 'female kind implies female');
assertEq(vocalPartsImpliedGender('lead'), null, 'lead kind implies no gender');
assertEq(vocalPartsImpliedGender('choir'), null, 'choir kind implies no gender');
assertEq(vocalPartsImpliedGender('nope'), null, 'unrecognised kind -> null, never throws');

/* ====================================================================== *
 * 5b — vocalPartsMarkerIsAmbiguousWithSection() — the SOLO-vs-Solo policy
 * ====================================================================== */
echo "\n5b — vocalPartsMarkerIsAmbiguousWithSection()\n";

assertEq(vocalPartsMarkerIsAmbiguousWithSection('SOLO'), true, 'SOLO is the known ambiguous marker');
assertEq(vocalPartsMarkerIsAmbiguousWithSection('solo'), true, 'lower-case folds the same as upper-case');
assertEq(vocalPartsMarkerIsAmbiguousWithSection('  Solo  '), true, 'whitespace-padded still folds to a match');
assertEq(vocalPartsMarkerIsAmbiguousWithSection('SOLOIST'), false, 'SOLOIST (unambiguous — no section shares this word) is NOT flagged');
assertEq(vocalPartsMarkerIsAmbiguousWithSection('MEN'), false, 'an unrelated, unambiguous marker is NOT flagged');
assertEq(vocalPartsMarkerIsAmbiguousWithSection(''), false, 'empty string -> false, never throws');

/* ====================================================================== *
 * 6 — vocalPartsDisplayLabel() / vocalPartsShape()
 * ====================================================================== */
echo "\n6 — vocalPartsDisplayLabel() + vocalPartsShape()\n";

assertEq(
    vocalPartsDisplayLabel(['PartKind' => 'male', 'Label' => null, 'SingerName' => null]),
    'Men',
    'no Label/SingerName -> the kind\'s own label'
);
assertEq(
    vocalPartsDisplayLabel(['PartKind' => 'male', 'Label' => 'Boys Choir', 'SingerName' => null]),
    'Boys Choir',
    'an explicit Label always wins'
);
assertEq(
    vocalPartsDisplayLabel(['PartKind' => 'named-singer', 'Label' => null, 'SingerName' => 'Fanny Crosby']),
    'Fanny Crosby',
    'SingerName used when there is no Label'
);
assertEq(
    vocalPartsDisplayLabel(['PartKind' => 'named-singer', 'Label' => null, 'SingerName' => null], 'Isaac Watts'),
    'Isaac Watts',
    'a joined musician name is the last resort before the generic kind label'
);

$shape = vocalPartsShape([
    'Id' => 7, 'PartKind' => 'female', 'Label' => null, 'MusicianId' => null,
    'SingerName' => null, 'Gender' => 'female', 'TtmlAgentId' => null,
    'Source' => 'ihymns', 'SortOrder' => 2,
]);
assertEq($shape, [
    'id' => 7, 'kind' => 'female', 'label' => null, 'displayLabel' => 'Women',
    'singerName' => null, 'gender' => 'female', 'musicianId' => null,
    'ttmlAgentId' => null, 'source' => 'ihymns', 'sortOrder' => 2,
], 'vocalPartsShape() produces the full always-present editor shape');

/* ====================================================================== *
 * 7 — vocalPartsKindsProjection()
 * ====================================================================== */
echo "\n7 — vocalPartsKindsProjection()\n";

$projection = vocalPartsKindsProjection();
ok('projection has one row per kind, in map order', count($projection) === count($kinds));
$firstKeys = array_column($projection, 'key');
assertEq(array_slice($firstKeys, 0, 3), ['lead', 'soloist', 'named-singer'], 'projection preserves map declaration order');
$namedSingerMatches = array_values(array_filter($projection, static fn($p) => $p['key'] === 'named-singer'));
ok('named-singer is present in the projection', count($namedSingerMatches) === 1);
/* array_key_exists(), not `?? 'MISSING'` — `??` treats a genuinely-present
   `null` value the same as an absent key, which would make this assertion
   pass even if 'marker' vanished from the row entirely. */
assertEq(
    array_key_exists('marker', $namedSingerMatches[0] ?? []) ? $namedSingerMatches[0]['marker'] : 'MISSING-KEY',
    null,
    'named-singer has no fixed marker word (null)'
);

/* ====================================================================== *
 * 8 — vocalPartsExportKeyword() + vocalPartsTtmlAgent()
 * ====================================================================== */
echo "\n8 — vocalPartsExportKeyword() + vocalPartsTtmlAgent()\n";

assertEq(vocalPartsExportKeyword(['kind' => 'male'], 'marker'), 'MEN', 'male -> marker MEN');
assertEq(vocalPartsExportKeyword(['kind' => 'male'], 'openlyrics'), 'men', 'male -> openlyrics men');
assertEq(vocalPartsExportKeyword(['kind' => 'backing'], 'marker'), 'ECHO', 'backing\'s FIRST marker (ECHO) is the canonical export word');
assertEq(vocalPartsExportKeyword(['kind' => 'named-singer', 'singerName' => 'Fanny Crosby'], 'openlyrics'), 'Fanny Crosby', 'named-singer openlyrics uses the singer name');
assertEq(vocalPartsExportKeyword(['kind' => 'named-singer', 'singerName' => null, 'displayLabel' => null], 'openlyrics'), 'solo', 'named-singer with no name falls back to "solo"');
assertEq(vocalPartsExportKeyword(['kind' => 'named-singer', 'singerName' => 'Fanny Crosby'], 'marker'), 'FANNY CROSBY', 'named-singer marker is the upper-cased name');

$versionParts = [
    ['id' => 1, 'kind' => 'group', 'sortOrder' => 0],
    ['id' => 2, 'kind' => 'group', 'sortOrder' => 1],
    ['id' => 3, 'kind' => 'male', 'sortOrder' => 2],
];
assertEq(vocalPartsExportKeyword(['id' => 2, 'kind' => 'group'], 'openlyrics', $versionParts), 'group2', 'the SECOND group part among its siblings exports as group2');
assertEq(vocalPartsExportKeyword(['id' => 1, 'kind' => 'group'], 'marker', $versionParts), 'GROUP 1', 'the FIRST group part exports as marker "GROUP 1"');

$threw = false;
try {
    vocalPartsExportKeyword(['kind' => 'male'], 'not-a-format');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
ok('an unknown export format throws InvalidArgumentException', $threw);

$agent = vocalPartsTtmlAgent(['kind' => 'male', 'ttmlAgentId' => null, 'displayLabel' => 'Men'], 2);
assertEq($agent, ['id' => 'v3', 'type' => 'group', 'name' => 'Men'], 'no stored TtmlAgentId falls back to v<index+1>');
$agent2 = vocalPartsTtmlAgent(['kind' => 'male', 'ttmlAgentId' => 'v9', 'displayLabel' => 'Men'], 2);
assertEq($agent2['id'], 'v9', 'a stored TtmlAgentId is used verbatim');

/* ====================================================================== *
 * 9 — vocalPartsDeriveRuns() — PURE run derivation
 * ====================================================================== */
echo "\n9 — vocalPartsDeriveRuns()\n";

/* A song with no vocal-part data at all must derive [] — the byte-identical
   contract for every existing render that has never heard of this feature. */
assertEq(vocalPartsDeriveRuns([100, 200, 300], []), [], 'no data anywhere -> [] (never a leading empty run)');

$linesMap = [
    20 => [['partId' => 1, 'bg' => false, 'sortOrder' => 0]],
    30 => [['partId' => 1, 'bg' => false, 'sortOrder' => 0]],
    40 => [['partId' => 2, 'bg' => false, 'sortOrder' => 0], ['partId' => 99, 'bg' => true, 'sortOrder' => 1]],
];
$runs = vocalPartsDeriveRuns([10, 20, 30, 40, 50], $linesMap);
assertEq($runs, [
    ['startIndex' => 1, 'endIndex' => 2, 'startLineId' => 20, 'endLineId' => 30, 'partIds' => [1]],
    ['startIndex' => 3, 'endIndex' => 3, 'startLineId' => 40, 'endLineId' => 40, 'partIds' => [2]],
    ['startIndex' => 4, 'endIndex' => 4, 'startLineId' => 50, 'endLineId' => 50, 'partIds' => []],
], 'leading empty line (10) omitted; adjacent identical rows (20,30) merge; a background-only row (40\'s 99) never widens the visible set; a TRAILING empty run (50) is KEPT (only index 0 is ever omitted)');

$scattered = vocalPartsDeriveRuns([1, 2, 3, 4], [
    1 => [['partId' => 5, 'bg' => false, 'sortOrder' => 0]],
    3 => [['partId' => 5, 'bg' => false, 'sortOrder' => 0]],
]);
assertEq($scattered, [
    ['startIndex' => 0, 'endIndex' => 0, 'startLineId' => 1, 'endLineId' => 1, 'partIds' => [5]],
    ['startIndex' => 1, 'endIndex' => 1, 'startLineId' => 2, 'endLineId' => 2, 'partIds' => []],
    ['startIndex' => 2, 'endIndex' => 2, 'startLineId' => 3, 'endLineId' => 3, 'partIds' => [5]],
    ['startIndex' => 3, 'endIndex' => 3, 'startLineId' => 4, 'endLineId' => 4, 'partIds' => []],
], 'a scattered (non-adjacent) same-part assignment derives as SEVERAL runs, never merged across the gap');

/* Mutation proof for the pure run deriver too: flipping the merge
   comparison from set-equality to "same first element" would wrongly
   merge two lines that share a lead singer but differ in background
   voices. Prove the REAL function tells them apart. */
$bgDiffers = vocalPartsDeriveRuns([1, 2], [
    1 => [['partId' => 1, 'bg' => false, 'sortOrder' => 0]],
    2 => [['partId' => 1, 'bg' => false, 'sortOrder' => 0], ['partId' => 2, 'bg' => false, 'sortOrder' => 1]],
]);
ok(
    'MUTATION PROOF: a second voice (duet) joining on line 2 starts a NEW run, not a merge',
    count($bgDiffers) === 2,
    'got ' . count($bgDiffers) . ' run(s): ' . var_export($bgDiffers, true)
);

/* ====================================================================== *
 * 10 — small satellite constants sanity
 * ====================================================================== */
echo "\n10 — satellite constants\n";

ok('IHYMNS_VOCAL_SOURCES_STRUCTURED includes import-marker (Pass 7 contradiction C6)', in_array('import-marker', IHYMNS_VOCAL_SOURCES_STRUCTURED, true));
ok('IHYMNS_VOCAL_GENDERS is exactly male/female/neutral', IHYMNS_VOCAL_GENDERS === ['male', 'female', 'neutral']);
ok(
    'VOCAL_PARTS_PAYLOAD_KEYS carries the eight documented bulk-payload keys',
    VOCAL_PARTS_PAYLOAD_KEYS === ['ready', 'spansReady', 'roundsReady', 'lyricsId', 'parts', 'lineAssignments', 'spans', 'rounds']
);

/* ====================================================================== */
echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed ($passed passed).\n";
    exit(1);
}
echo "All $passed vocal-parts vocabulary assertions passed.\n";
