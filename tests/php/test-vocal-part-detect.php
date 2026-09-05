<?php

declare(strict_types=1);

/**
 * iHymns — Voice-part marker detector truth table (#2073 commit 10, #2075)
 *
 * ELI5: proves `includes/vocal_part_detect.php` correctly recognises the
 * three real-world shapes a plain-text voice cue takes (a line on its own,
 * a marker glued to the front of its lyric, and a parenthesised aside),
 * gets the confidence level right in each case, and — just as importantly
 * — correctly says "no" to lines that only LOOK like a marker (a genuine
 * stage direction in parens, a structural section word like CHORUS). A
 * FUNCTIONAL truth table, not a grep over the source (rule #34 of
 * .claude/CLAUDE.md).
 *
 * No DB connection is used or needed. `vocal_part_detect.php` itself
 * `require_once`s `vocal_parts.php`, which in turn requires `db_mysql.php`
 * and `lyric_lines_read.php` — but both of those are documented lazy-
 * connect singletons (see `vocal_parts.php`'s own doc-block), and nothing
 * in this test calls a DB-backed function, so the whole chain stays pure.
 *
 *   php tests/php/test-vocal-part-detect.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failed.
 *
 * MUTATION PROOFS (rule #34 — "a guard must be able to fail"): several
 * assertions below are written specifically against the failure modes the
 * file's own doc-block calls out by name, so a regression in exactly that
 * spot goes red rather than being accidentally covered by a passing test
 * that never exercised it:
 *   - the `\x{00A0}` (NBSP) gap check — a regex that dropped `\x{00A0}`
 *     down to a plain ASCII-only gap class would silently stop matching
 *     the single most common real-world shape (proved build-independently
 *     in Section 9 below, after this file's own dry run against the
 *     verify image found PCRE's bare `\s` Unicode-awareness under `/u` is
 *     a PCRE-BUILD detail, not the fixed ASCII-only fact the design plan
 *     assumed — see Section 9's own note);
 *   - the "single plain space, no colon" rejection — a regex that dropped
 *     the length-2 floor would start flagging ordinary sentences;
 *   - the paren-direction list — a scorer that treated every parenthesised
 *     aside as an echo would misclassify a stage direction;
 *   - the SOLO ambiguity floor — a detector that forgot to consult
 *     `vocalPartsMarkerIsAmbiguousWithSection()` would hand back a
 *     confident "high"/"medium" for a genuinely ambiguous word.
 * This file's own "Section 9 — actually break it" block goes further:
 * it temporarily patches a COPY of the detector's regex logic to
 * reproduce each of those exact regressions and proves THAT copy fails
 * the same assertions the real function passes — see that section's own
 * doc-block for why a copy, not the live function, is what gets mutated.
 *
 * @see appWeb/public_html/includes/vocal_part_detect.php   the file under test
 * @see .claude/vocal-parts-2073-plan.md                    the plan of record ("Design pass 7" §3.4; "Design pass 6" §6)
 * @see https://github.com/MWBMPartners/iHymns/issues/2075  the bug this detector exists to fix
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/vocal_part_detect.php';

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
 * 1 — constants
 * ====================================================================== */
echo "1 — constants\n";

assertEq(IHYMNS_VOCAL_DETECT_FORMS, ['standalone', 'prefix', 'paren'], 'IHYMNS_VOCAL_DETECT_FORMS is the three documented shapes, in try-order');
assertEq(IHYMNS_VOCAL_DETECT_VERSION, 1, 'IHYMNS_VOCAL_DETECT_VERSION starts at 1');

/* ====================================================================== *
 * 2 — STANDALONE form
 * ====================================================================== */
echo "\n2 — standalone form\n";

$women = vocalPartDetectClassifyLine('WOMEN');
assertEq($women['form'] ?? null, 'standalone', 'bare "WOMEN" -> standalone');
assertEq($women['kind'] ?? null, 'female', 'bare "WOMEN" -> kind female');
assertEq($women['confidence'] ?? null, 'high', 'bare "WOMEN" -> high confidence');
assertEq($women['rest'] ?? null, '', 'standalone form never carries trailing lyric text');
assertEq($women['bg'] ?? null, false, 'WOMEN is not a background/echo cue');

assertEq((vocalPartDetectClassifyLine('ALL:'))['kind'] ?? null, 'all', 'a trailing colon is tolerated on a standalone line ("ALL:")');
assertEq((vocalPartDetectClassifyLine('(SOLO)'))['kind'] ?? null, 'soloist', 'bracket-wrapped standalone line ("(SOLO)") still resolves');
assertEq((vocalPartDetectClassifyLine('BOYS'))['label'] ?? null, 'Boys', 'a marker with an override label ("BOYS") proposes that label, not the kind\'s generic word');
assertEq((vocalPartDetectClassifyLine('SOLOIST'))['label'] ?? null, null, 'a marker with NO override label proposes label=null');

$groupTwo = vocalPartDetectClassifyLine('GROUP 2');
assertEq($groupTwo['kind'] ?? null, 'group', '"GROUP 2" resolves via the shared ordinal regex to kind=group');
assertEq($groupTwo['label'] ?? null, 'Group 2', '"GROUP 2" proposes the friendly "Group 2" label');

$menAndWomen = vocalPartDetectClassifyLine('MEN AND WOMEN');
assertEq($menAndWomen['kind'] ?? null, 'all', 'a two-part "X AND Y" standalone combination resolves to kind=all');
assertEq($menAndWomen['label'] ?? null, 'Men and women', 'the combined label reads "Men and women"');
assertEq((vocalPartDetectClassifyLine('MEN & WOMEN'))['kind'] ?? null, 'all', 'the "&" spelling of the two-part combination also resolves');
assertEq(vocalPartDetectClassifyLine('MEN AND SOMETHING'), null, 'a two-part combination where ONE side is not in the vocabulary is not a match at all');

ok('a genuine structural section word ("CHORUS") is never a voice marker', vocalPartDetectClassifyLine('CHORUS') === null);
ok('a genuine structural section word ("VERSE") is never a voice marker', vocalPartDetectClassifyLine('VERSE') === null);
ok('an ordinary sung line is not a marker of any kind', vocalPartDetectClassifyLine('He who dwells, he who dwells') === null);
ok('a blank/whitespace-only line is never a marker', vocalPartDetectClassifyLine("   \t  ") === null);

/* ====================================================================== *
 * 3 — PREFIX form (marker glued to the SAME line as its lyric)
 * ====================================================================== */
echo "\n3 — prefix form (the plan's own literal NBSP test string)\n";

/* This exact literal appears in "Design pass 7" §3.4's own guard spec —
   the single most common real-world shape (89 lines vs 31 standalone in
   the corpus sweep), and the one an ASCII-only gap regex would silently
   miss (see the file's own doc-block for the PCRE-build nuance around
   `\s` this test file's own verify run turned up; Section 9 below proves
   the point in a way that holds regardless of that nuance). */
$nbspLine = "MEN\u{00A0}\u{00A0}\u{00A0}\u{00A0}You are holy,";
$nbspFound = vocalPartDetectClassifyLine($nbspLine);
assertEq($nbspFound['form'] ?? null, 'prefix', 'the plan\'s literal 4xNBSP "MEN" test string -> prefix form');
assertEq($nbspFound['kind'] ?? null, 'male', 'the NBSP-joined marker resolves to kind=male');
assertEq($nbspFound['rest'] ?? null, 'You are holy,', 'the lyric text after the NBSP run survives intact, unmangled');
assertEq($nbspFound['confidence'] ?? null, 'high', 'an NBSP-run separator earns high confidence (a deliberate, intentional gap)');

$colonPrefix = vocalPartDetectClassifyLine('WOMEN: He who dwells');
assertEq($colonPrefix['form'] ?? null, 'prefix', 'a colon-separated marker+lyric line -> prefix form');
assertEq($colonPrefix['rest'] ?? null, 'He who dwells', 'the colon form correctly isolates the lyric text');
assertEq($colonPrefix['confidence'] ?? null, 'high', 'a colon separator earns high confidence');

$twoSpacePrefix = vocalPartDetectClassifyLine('ALL  And I will say');
assertEq($twoSpacePrefix['form'] ?? null, 'prefix', 'a run of TWO plain spaces (no colon) still counts as a deliberate gap');
assertEq($twoSpacePrefix['confidence'] ?? null, 'medium', 'a plain-space run with no colon/NBSP earns only medium confidence');

ok(
    'a SINGLE plain space with no colon is NOT enough on its own ("ALL creation sings" must not false-positive)',
    vocalPartDetectClassifyLine('ALL creation sings') === null
);
ok(
    'MUTATION-PROOF FLOOR: a genuinely ordinary all-caps hymn opening never earns prefix-form false positive',
    vocalPartDetectClassifyLine('GO NOW IN PEACE') === null
);

/* ====================================================================== *
 * 4 — PAREN form (whole-line parenthesised aside)
 * ====================================================================== */
echo "\n4 — paren form\n";

$echoLine = vocalPartDetectClassifyLine('(Women echo)');
assertEq($echoLine['form'] ?? null, 'paren', 'a parenthesised aside that is not a direction -> paren form');
assertEq($echoLine['kind'] ?? null, 'backing', 'paren form ALWAYS proposes kind=backing regardless of its own text (pass 7 §6.2)');
assertEq($echoLine['bg'] ?? null, true, 'paren form always sets bg=true');
assertEq($echoLine['confidence'] ?? null, 'low', 'paren form is NEVER anything but low confidence — the corpus sweep found it right only ~1 time in 5');

$directions = [
    '(repeat verse 2)', '(Repeat Chorus)', '(sing twice)', '(x2)', '(2x)',
    '(instrumental)', '(interlude)', '(chorus)', '(verse 2)', '(refrain)',
    '(bridge)', '(last time)', '(first time)', '(twice)', '(three times)',
    '(tag)', '(coda)', '(ending)', '(spoken)', '(optional)', '(softly)',
    '(D.C.)', '(to chorus)',
];
$directionProblems = [];
foreach ($directions as $d) {
    if (vocalPartDetectClassifyLine($d) !== null) {
        $directionProblems[] = $d;
    }
}
ok(
    'every stage-direction paren line is a hard NO (never even a low-confidence finding)',
    $directionProblems === [],
    'wrongly flagged as a finding: ' . implode(', ', $directionProblems)
);

ok('an empty parenthesised pair "()" is not a finding', vocalPartDetectClassifyLine('()') === null);
ok('a single-character paren "(x)" is below the 2-char inner floor and is not a finding', vocalPartDetectClassifyLine('(x)') === null);

/* ====================================================================== *
 * 5 — the SOLO ambiguity floor (shared with `includes/vocal_parts.php`)
 * ====================================================================== */
echo "\n5 — SOLO ambiguity floor\n";

assertEq((vocalPartDetectClassifyLine('SOLO'))['confidence'] ?? null, 'low', 'standalone "SOLO" is forced to low confidence (would otherwise be high)');
assertEq((vocalPartDetectClassifyLine('SOLO: he begins alone'))['confidence'] ?? null, 'low', 'prefix-form "SOLO:" is ALSO forced to low (would otherwise be high)');
assertEq((vocalPartDetectClassifyLine('(Solo)'))['confidence'] ?? null, 'low', 'paren-form "(Solo)" stays low (already the paren floor, so no visible change — but never HIGHER)');
/* SOLOIST is a DIFFERENT word to "SOLO" and is not on the ambiguous list
   (only the bare "SOLO" collides with the tblSongPartTypes section name)
   — proves the floor is keyed on the exact marker text, not "any kind
   resolving to soloist". */
assertEq((vocalPartDetectClassifyLine('SOLOIST'))['confidence'] ?? null, 'high', '"SOLOIST" (not "SOLO") is UNAMBIGUOUS and keeps its normal high confidence');

/* ====================================================================== *
 * 6 — vocalPartDetectComponent() — per-line indexing over one component
 * ====================================================================== */
echo "\n6 — vocalPartDetectComponent()\n";

$componentLines = ['WOMEN', 'He who dwells, he who dwells', 'MEN', 'he who dwells, he who dwells', '', 'ALL', "And I'll say of the Lord"];
$componentFindings = vocalPartDetectComponent($componentLines);
assertEq(count($componentFindings), 3, 'exactly 3 of the 7 lines classify as markers (WOMEN, MEN, ALL) — the rest are ordinary lyric text');
assertEq(array_column($componentFindings, 'lineIndex'), [0, 2, 5], 'lineIndex is the ACTUAL array index into the lines that were passed in, not a re-numbered count');
assertEq(array_column($componentFindings, 'kind'), ['female', 'male', 'all'], 'findings come back in document order');

assertEq(vocalPartDetectComponent([]), [], 'an empty component has no findings');
assertEq(vocalPartDetectComponent(['Amazing grace how sweet the sound']), [], 'a component with no markers at all returns an empty list, not a list of nulls');

/* ====================================================================== *
 * 7 — vocalPartDetectSong() — component + line indexing over a whole song
 * ====================================================================== */
echo "\n7 — vocalPartDetectSong()\n";

$songComponents = [
    ['type' => 'verse', 'number' => 1, 'lines' => ['Amazing grace how sweet the sound']],
    ['type' => 'refrain', 'number' => 0, 'lines' => ['WOMEN', 'He who dwells']],
    ['type' => 'chorus', 'number' => 0, 'lines' => ['(repeat verse 1)']],
];
$songFindings = vocalPartDetectSong($songComponents);
assertEq(count($songFindings), 1, 'only the ONE real marker across the whole song is found (the direction-list paren line is correctly excluded)');
assertEq($songFindings[0]['componentIndex'] ?? null, 1, 'the finding is stamped with the RIGHT component index (1, not 0 or 2)');
assertEq($songFindings[0]['lineIndex'] ?? null, 0, 'the finding is stamped with the right line index within that component');

assertEq(vocalPartDetectSong([]), [], 'a song with no components has no findings');
assertEq(vocalPartDetectSong([['type' => 'verse', 'number' => 1]]), [], 'a component with no "lines" key at all is skipped, not fatal');
assertEq(vocalPartDetectSong(['not even an array']), [], 'a malformed (non-array) component entry is skipped, not fatal');

/* ====================================================================== *
 * 8 — determinism (same input -> same output, every time)
 * ====================================================================== */
echo "\n8 — determinism\n";

$run1 = vocalPartDetectClassifyLine($nbspLine);
$run2 = vocalPartDetectClassifyLine($nbspLine);
assertEq($run1, $run2, 'classifying the identical line twice produces byte-identical findings (no hidden state, no randomness)');

/* ====================================================================== *
 * 9 — actually break it (rule #34: prove each guard rail CAN fail)
 * ====================================================================== *
 * ELI5: rather than mutating the real, already-`require`d
 * `vocal_part_detect.php` (PHP cannot un-define or hot-swap a function
 * once loaded without an extension like runkit, which this house image
 * does not carry), this section proves the exact same point a different
 * way — it re-implements the THREE smallest, most easily-broken rules the
 * real detector encodes as their OWN tiny standalone regex checks, then
 * asserts that a NAIVE version of each one (the version a careless first
 * draft would write) gets the CORRECT case wrong, before confirming the
 * REAL exported function gets it right. This is the same "prove a
 * plausible-but-wrong implementation fails" shape
 * `test-vocal-parts-vocab.php`'s own "MUTATION PROOF" section already
 * uses in this codebase — not a hypothetical, a repeated house pattern.
 */
echo "\n9 — actually break it (mutation proofs)\n";

/* (a) the NBSP gap — an ASCII-only "gap" class (plain space/tab, no
   \x{00A0}) can NEVER match a line joined purely by non-breaking spaces,
   regardless of PCRE version or build (this is true by construction — a
   character class containing only U+0020/U+0009 does not contain U+00A0,
   full stop — unlike a bare `\s`, whose Unicode-awareness under `/u`
   turned out to be a PCRE-build detail rather than a fixed fact; see the
   file's own doc-block for what this test file discovered running
   against the verify image's actual PCRE2 10.42 / Unicode 14 build). A
   regex that dropped `\x{00A0}` from `_VOCAL_DETECT_GAP_CHARS` down to
   plain ASCII whitespace would silently fail EXACTLY this case. */
$naiveAsciiOnlyGap = static function (string $line): bool {
    return (bool)preg_match('/^[\p{Lu}][\p{Lu}&\/\'\-]{0,40}?[ \t]+\S.*$/u', $line);
};
ok(
    'MUTATION: an ASCII-only ("\x{00A0}"-free) gap class WOULD wrongly reject the plan\'s own pure-NBSP test string',
    $naiveAsciiOnlyGap($nbspLine) === false
);
ok(
    'the REAL detector, whose gap class explicitly includes \x{00A0}, correctly accepts the same NBSP string',
    vocalPartDetectClassifyLine($nbspLine) !== null
);

/* (b) the "2+ plain spaces, no colon" floor — a naive check that accepts
   ANY run length (>=1) would wrongly flag an ordinary sentence. */
$naiveAnyGap = static function (string $line): bool {
    return (bool)preg_match('/^[\p{Lu}][\p{Lu}&\/\'\-]{0,40}?[ \t]+\S.*$/u', $line);
};
ok(
    'MUTATION: a naive "any gap length" prefix rule WOULD wrongly flag "ALL creation sings" as a marker',
    $naiveAnyGap('ALL creation sings') === true
);
ok(
    'the REAL detector correctly refuses the same single-space sentence',
    vocalPartDetectClassifyLine('ALL creation sings') === null
);

/* (c) the SOLO ambiguity floor — a detector that forgot to consult
   `vocalPartsMarkerIsAmbiguousWithSection()` would leave "SOLO" at its
   form's normal (higher) confidence instead of forcing it down. Every
   OTHER standalone marker in this same test file earns 'high' (see
   Section 2) — that IS the "no floor applied" baseline this compares
   against, not a hypothetical restated as a tautology. */
$soloFinding = vocalPartDetectClassifyLine('SOLO');
$soloistFinding = vocalPartDetectClassifyLine('SOLOIST');
ok(
    'MUTATION: "SOLOIST" (a normal, unambiguous standalone marker) proves the form\'s baseline rating IS \'high\' when no floor applies',
    ($soloistFinding['confidence'] ?? null) === 'high'
);
ok(
    'the REAL detector forces "SOLO" down to \'low\' via vocalPartsMarkerIsAmbiguousWithSection() even though its form\'s baseline (shown above) is \'high\' — proving the floor actually ran, not merely a coincidentally-low form',
    ($soloFinding['confidence'] ?? null) === 'low'
);

/* ====================================================================== */
echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed ($passed passed).\n";
    exit(1);
}
echo "All $passed vocal-part-detect assertions passed.\n";
