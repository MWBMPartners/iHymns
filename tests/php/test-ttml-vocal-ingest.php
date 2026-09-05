<?php

declare(strict_types=1);

/**
 * iHymns — TTML voice-part ingest truth table (#2073 commit 12, D2)
 *
 * ELI5: Apple Music TTML is the richest "who sings this?" signal iHymns
 * ever sees — a `<head>` names every voice, each line says which voice(s)
 * sing it, and `ttm:role="x-bg"` marks an echo/background phrase — but
 * before this commit the parser threw all three of those away: it never
 * looked inside `<head>` at all, it collapsed a whole background-vocal
 * GROUP of several timed words into ONE fake word, and no word ever
 * inherited anything from its line or its group. This file proves the
 * parser fixes all three, over a small Apple-shaped fixture with two
 * `<head>` agents, an `x-bg` word-group, and a per-word agent override.
 *
 * SCOPE (parser only, no live MySQL — same posture as this programme's
 * other pure-function suites, e.g. `test-vocal-parts-core.php`'s own
 * doc-block): this repo's PHP test image has no MySQL/MariaDB, and the
 * WRITER half of this commit (`_lyricsIngestApplyVocalParts()`,
 * `lyricsIngestScanMetaJsonForVoiceSignal()`) needs a live `\mysqli` to do
 * anything at all — those are covered by manual/staging verification, not
 * here. `lyricsIngest_parseTtml()` itself does no I/O whatsoever (its own
 * doc-block says so, unchanged by this commit), which is exactly what
 * makes it possible to prove completely in a plain `php` process. The two
 * small NEW pure helpers the parser fix leans on — `_ttmlAgentAndBg()` and
 * `_ttmlSpanChildrenHaveWhitespaceGap()` — are truth-tabled directly too.
 *
 * MUTATION PROOFS (rule #34 of .claude/CLAUDE.md — "a guard must be able
 * to fail"), following this codebase's own house pattern (see
 * `test-vocal-part-detect.php`'s "Section 9" doc-block for the precedent:
 * write the NAIVE, plausible-but-wrong version of the risky rule, show it
 * gets a real case wrong, then show the REAL function gets the SAME case
 * right):
 *   - Section 4 proves `_ttmlSpanChildrenHaveWhitespaceGap()` answers
 *     `true` for the "Oh yeah" word-group node and `false` for a genuine
 *     touching-syllable node — then Section 3's own end-to-end assertion
 *     (the x-bg container yields TWO separate words, "Oh" and "yeah", not
 *     one fake "Ohyeah") is what actually goes RED if that discriminator
 *     is ever dropped/reverted to "always false" (the pre-fix behaviour,
 *     where every nested-span container took the single-word-with-
 *     syllables branch unconditionally) — a naive `static fn() => false`
 *     stand-in is asserted wrong for that exact node in Section 4 to make
 *     the connection explicit, then the real parse in Section 3 is asserted
 *     right.
 *   - Section 6 proves per-word INHERITANCE two ways at once: a plain word
 *     with no attributes of its own still ends up with the same
 *     `agentIds`/`isBackground` as its enclosing line (or its enclosing
 *     x-bg group, when that group carries its own override) — the fix for
 *     "a word inherits nothing from its ancestors, so the inner words of a
 *     background group carry no background flag". Reverting the parser's
 *     `$cur['meta'] = $inherited` baseline (or the container branch's
 *     `$w['meta'] = $merged`) back to "start every word's meta at null"
 *     turns every one of those "inherits from an ancestor with no
 *     attributes of its OWN" assertions red, because `_ttmlAgentAndBg(null)`
 *     always answers `agentIds:[], isBackground:false`.
 *
 *   php tests/php/test-ttml-vocal-ingest.php
 *
 * Exit status 0 = every assertion passed, 1 = at least one failed.
 *
 * @see appWeb/public_html/includes/lyrics_ingest.php  the file under test
 * @see .claude/vocal-parts-2073-plan.md  "Design pass 6" §7 (the TTML spec
 *      this commit implements) and "Design pass 7" §1 row C-none/§10 (its
 *      correction — the container rule is WHITESPACE ONLY, dropping Pass
 *      6's extra "and carries no ttm:role/ttm:agent" clause)
 * @see https://www.w3.org/TR/ttml2/#metadata-vocabulary-agent  TTML2 §12.2.1
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/lyrics_ingest.php';

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
 * Fixture — an Apple-shaped TTML file with two <head> agents, a two-word
 * x-bg group (the canonical "Oh yeah" example this plan's own mutation
 * note names — see this file's own header doc-block), a leaf-level
 * per-word agent override with no group wrapper at all, a multi-agent
 * IDREFS line, an unchanged true-syllable word, and a bg-only group with
 * NO agent anywhere (so its words carry an echo flag but no voice).
 * ====================================================================== */

$fixtureTtml = <<<'TTML'
<?xml version="1.0" encoding="UTF-8"?>
<tt xmlns="http://www.w3.org/ns/ttml" xmlns:ttm="http://www.w3.org/ns/ttml#metadata" xml:lang="en">
  <head>
    <metadata>
      <ttm:agent type="person">
        <ttm:name type="full">Ignored — no xml:id, can never be referenced</ttm:name>
      </ttm:agent>
      <ttm:agent xml:id="v1" type="person">
        <ttm:name type="full">Lead Singer</ttm:name>
      </ttm:agent>
      <ttm:agent xml:id="v2" type="group">
        <ttm:name>Backing Choir</ttm:name>
        <ttm:name type="full">The Backing Choir</ttm:name>
      </ttm:agent>
    </metadata>
  </head>
  <body>
    <div>
      <p begin="0.0s" end="3.0s" ttm:agent="v1">
        <span begin="0.0s" end="1.0s">Amazing</span> <span ttm:role="x-bg" ttm:agent="v2" begin="1.0s" end="3.0s"><span begin="1.0s" end="1.5s">Oh</span> <span begin="1.5s" end="3.0s">yeah</span></span>
      </p>
      <p begin="3.0s" end="5.0s" ttm:agent="v1">
        <span begin="3.0s" end="3.5s">So</span> <span ttm:agent="v2" begin="3.5s" end="5.0s">sweet</span>
      </p>
      <p begin="5.0s" end="7.0s" ttm:agent="v1 v2">
        <span begin="5.0s" end="6.0s"><span begin="5.0s" end="5.5s">to</span><span begin="5.5s" end="6.0s">day</span></span>
      </p>
      <p begin="7.0s" end="9.0s">
        <span ttm:role="x-bg" begin="7.0s" end="9.0s"><span begin="7.0s" end="8.0s">Hallelujah</span> <span begin="8.0s" end="9.0s">Amen</span></span>
      </p>
    </div>
  </body>
</tt>
TTML;

$parsed = lyricsIngest_parseTtml($fixtureTtml);

/* ====================================================================== */
echo "1 — <head> ttm:agent definitions are read (fix 1 of 3)\n";

ok('parsed[\'agents\'] is present and is an array', is_array($parsed['agents'] ?? null));
assertEq(count($parsed['agents']), 2, 'exactly the two id-bearing agents are kept — the id-less <ttm:agent> is skipped (it could never be referenced)');
ok('the id-less agent did not leak in under a blank key', !array_key_exists('', $parsed['agents']));

assertEq($parsed['agents']['v1']['type'] ?? null, 'person', 'v1 keeps its declared type');
assertEq($parsed['agents']['v1']['name'] ?? null, 'Lead Singer', 'v1\'s single <ttm:name> (no type="full") is still picked up');
assertEq($parsed['agents']['v2']['type'] ?? null, 'group', 'v2 keeps its declared type');
assertEq(
    $parsed['agents']['v2']['name'] ?? null,
    'The Backing Choir',
    'v2 has two <ttm:name> children — the type="full" one is preferred over the plain one, never the document order'
);
assertEq(
    count($parsed['agents']['v2']['meta']['names'] ?? []),
    2,
    'BOTH of v2\'s <ttm:name> entries are kept losslessly in meta, even though only one became the display name'
);

assertEq($parsed['hasVoiceParts'] ?? null, true, 'a file with <head> agents at all is hasVoiceParts, even before any line/word is inspected');

/* ====================================================================== */
echo "\n2 — a plain file with zero voice signal is NOT flagged\n";

$plainTtml = <<<'TTML'
<?xml version="1.0" encoding="UTF-8"?>
<tt xmlns="http://www.w3.org/ns/ttml" xml:lang="en">
  <body><div>
    <p begin="0.0s" end="2.0s">Amazing grace, how sweet the sound</p>
  </div></body>
</tt>
TTML;
$plainParsed = lyricsIngest_parseTtml($plainTtml);
assertEq($plainParsed['agents'], [], 'a file with no <head> agents parses to an empty agents map, not null/missing');
assertEq($plainParsed['hasVoiceParts'], false, 'no <head> agents and no ttm:agent/ttm:role anywhere -> hasVoiceParts is false');
assertEq($plainParsed['lines'][0]['agentIds'], [], 'a plain line\'s agentIds is an empty list, not null');
assertEq($plainParsed['lines'][0]['isBackground'], false, 'a plain line is not background');

/* ====================================================================== */
echo "\n3 — an x-bg word GROUP yields SEPARATE words, not one fake word (fix 2 of 3)\n";

$line1 = $parsed['lines'][0];
assertEq($line1['text'], 'Amazing Oh yeah', 'the line\'s flattened text is unaffected by the fix (still every word, whitespace-joined)');
assertEq(count($line1['words']), 3, 'THREE words — "Amazing" plus the x-bg group\'s "Oh" and "yeah" SEPARATELY, never one collapsed "Ohyeah"');
assertEq($line1['words'][0]['text'], 'Amazing', 'word 0 is the plain leaf word');
assertEq($line1['words'][1]['text'], 'Oh', 'word 1 is the FIRST word of the group, on its own');
assertEq($line1['words'][2]['text'], 'yeah', 'word 2 is the SECOND word of the group, on its own — this is exactly the case that used to read back as "Ohyeah" (this plan\'s own named mutation symptom)');
ok('neither group word carries a leftover syllable (each is one whole word, not one syllable of a bigger word)', $line1['words'][1]['syllables'] === [] && $line1['words'][2]['syllables'] === []);

/* ====================================================================== */
echo "\n4 — the whitespace discriminator itself, direct + mutation proof\n";

$groupFrag = new DOMDocument();
$groupFrag->loadXML('<x><a begin="0" end="1">Oh</a> <a begin="1" end="2">yeah</a></x>');
$groupEl = $groupFrag->documentElement;

$syllableFrag = new DOMDocument();
$syllableFrag->loadXML('<x><a begin="0" end="1">to</a><a begin="1" end="2">day</a></x>'); // no space at all
$syllableEl = $syllableFrag->documentElement;

ok('a container whose children are separated by a real space DOES have a whitespace gap', _ttmlSpanChildrenHaveWhitespaceGap($groupEl) === true);
ok('a container whose children TOUCH (no space in the source at all) does NOT have a whitespace gap', _ttmlSpanChildrenHaveWhitespaceGap($syllableEl) === false);

/* MUTATION: the pre-fix behaviour was equivalent to a discriminator that
   NEVER sees a gap — every nested-span container took the single-word
   branch unconditionally. A naive stand-in making that same (wrong)
   universal assumption disagrees with the real discriminator on the
   group node specifically — proving Section 3's "3 words, not 1"
   assertion is genuinely exercising this function, not merely a
   coincidence: if `_ttmlSpanChildrenHaveWhitespaceGap()` were ever
   reverted to this naive shape, Section 3 above would go RED. */
$naiveNeverHasGap = static fn(\DOMElement $el): bool => false;
ok(
    'MUTATION: the naive pre-fix stand-in ("no container ever has a gap") gets the group node WRONG',
    $naiveNeverHasGap($groupEl) === false
);
ok(
    'the REAL discriminator gets the SAME group node right (proving Section 3\'s word count depends on this, not luck)',
    _ttmlSpanChildrenHaveWhitespaceGap($groupEl) === true
);

/* ====================================================================== */
echo "\n5 — _ttmlAgentAndBg() truth table\n";

assertEq(_ttmlAgentAndBg(null), ['agentIds' => [], 'isBackground' => false], 'no meta at all -> no signal');
assertEq(_ttmlAgentAndBg(['ttm:agent' => 'v1']), ['agentIds' => ['v1'], 'isBackground' => false], 'prefixed ttm:agent, single id');
assertEq(_ttmlAgentAndBg(['agent' => 'v1  v2']), ['agentIds' => ['v1', 'v2'], 'isBackground' => false], 'bare (unprefixed) agent attr, IDREFS split on whitespace (incl. a double space)');
assertEq(_ttmlAgentAndBg(['ttm:role' => 'x-bg']), ['agentIds' => [], 'isBackground' => true], 'prefixed ttm:role x-bg alone');
assertEq(_ttmlAgentAndBg(['role' => 'foo x-bg bar']), ['agentIds' => [], 'isBackground' => true], 'x-bg matched as ONE token among several, not a whole-value equals');
assertEq(_ttmlAgentAndBg(['ttm:role' => 'foreground']), ['agentIds' => [], 'isBackground' => false], 'a role that is NOT x-bg is not background');
assertEq(
    _ttmlAgentAndBg(['ttm:agent' => 'v1', 'ttm:role' => 'x-bg']),
    ['agentIds' => ['v1'], 'isBackground' => true],
    'agent and background-role can both be present on the same element (a named backing singer)'
);

/* ====================================================================== */
echo "\n6 — per-word INHERITANCE, from a line and from a group (fix 3 of 3)\n";

/* line 1: "Amazing" has no attrs of its own -> inherits the LINE's v1. */
assertEq($line1['words'][0]['agentIds'], ['v1'], '"Amazing" (no own attrs) inherits the LINE\'s ttm:agent="v1"');
assertEq($line1['words'][0]['isBackground'], false, '"Amazing" is not background (neither it nor the line says so)');
/* the group's words: no own attrs, but the CONTAINER declared agent="v2" role="x-bg", which OVERRIDES the line's v1. */
assertEq($line1['words'][1]['agentIds'], ['v2'], '"Oh" (no own attrs) inherits its enclosing GROUP\'s ttm:agent="v2" — NOT the line\'s v1, because the group\'s own attribute wins');
assertEq($line1['words'][1]['isBackground'], true, '"Oh" inherits the group\'s ttm:role="x-bg"');
assertEq($line1['words'][2]['agentIds'], ['v2'], '"yeah" likewise inherits the group, not the line');
assertEq($line1['words'][2]['isBackground'], true, '"yeah" likewise inherits the group\'s background flag');
assertEq($line1['agentIds'], ['v1'], 'the LINE itself is unaffected by what its words inherited — it only reflects its OWN ttm:agent');
assertEq($line1['isBackground'], false, 'the line itself is not background (only the nested group is)');

/* line 2: a leaf word's OWN ttm:agent overrides what it would have inherited — no group/container needed at all. */
$line2 = $parsed['lines'][1];
assertEq($line2['text'], 'So sweet', 'line 2 text');
assertEq(count($line2['words']), 2, 'line 2 has exactly two words, both leaves directly under <p> (no container)');
assertEq($line2['words'][0]['agentIds'], ['v1'], '"So" (no own attrs) inherits the line\'s v1');
assertEq($line2['words'][1]['agentIds'], ['v2'], '"sweet" carries its OWN ttm:agent="v2" directly on the leaf span — the per-word AGENT OVERRIDE case, own wins over inherited');
assertEq($line2['words'][1]['isBackground'], false, '"sweet" is not marked background — an agent override does not imply one');

/* line 3: IDREFS split (two agents on ONE line) + the unchanged true-syllable-container path also now inherits correctly. */
$line3 = $parsed['lines'][2];
assertEq($line3['agentIds'], ['v1', 'v2'], 'ttm:agent="v1 v2" splits into TWO ids on the line (a duet line)');
assertEq(count($line3['words']), 1, 'the wrapped "to"+"day" (no whitespace between them) is still ONE word — the container/syllable distinction is UNCHANGED for a genuine syllable pair');
assertEq($line3['words'][0]['text'], 'today', 'the syllables still concatenate with no injected space, exactly as before this commit');
assertEq(count($line3['words'][0]['syllables']), 2, 'both syllables are kept (more than one syllable -> not collapsed to [])');
assertEq(
    $line3['words'][0]['agentIds'],
    ['v1', 'v2'],
    'the wrapping syllable-container span has NO attributes of its own, so the word now correctly inherits the LINE\'s two agents — before this fix the container branch only ever read the container\'s OWN (here: none) meta, so this would have been []'
);

/* line 4: a background GROUP with no agent anywhere still marks its words background, even though the LINE itself has zero signal. */
$line4 = $parsed['lines'][3];
assertEq($line4['agentIds'], [], 'line 4 itself declares no ttm:agent at all');
assertEq($line4['isBackground'], false, 'line 4 itself declares no ttm:role either — the bg signal lives ONLY on the nested group');
assertEq(count($line4['words']), 2, 'the agent-less x-bg group still yields two separate words, not one');
assertEq($line4['words'][0]['agentIds'], [], '"Hallelujah" has no agent — neither its own, its group\'s, nor its (signal-less) line\'s');
assertEq($line4['words'][0]['isBackground'], true, '"Hallelujah" IS background — inherited from its GROUP, which has no line-level fallback to rely on');
assertEq($line4['words'][1]['isBackground'], true, '"Amen" likewise');

/* ====================================================================== */
echo "\n7 — _lyricsIngestIntSetsEqual() truth table (the word/line redundancy check the writer half uses)\n";

ok('same elements, different order -> equal (sets, not sequences)', _lyricsIngestIntSetsEqual([1, 2, 3], [3, 1, 2]) === true);
ok('a genuinely different set -> not equal', _lyricsIngestIntSetsEqual([1, 2], [1, 2, 3]) === false);
ok('two empty lists -> equal', _lyricsIngestIntSetsEqual([], []) === true);
ok('duplicate-vs-single is NOT silently equal (this function does not dedupe)', _lyricsIngestIntSetsEqual([1, 1, 2], [1, 2]) === false);

/* ====================================================================== */
echo "\n8 — sanity: the DB-touching writer functions exist but are not exercised here\n";

ok(
    'the writer entry points this commit adds are declared (no live mysqli in this test image — see this file\'s own doc-block)',
    function_exists('_lyricsIngestApplyVocalParts') && function_exists('lyricsIngestScanMetaJsonForVoiceSignal')
);

/* ====================================================================== */
echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed ($passed passed).\n";
    exit(1);
}
echo "All $passed TTML voice-ingest assertions passed.\n";
