<?php

declare(strict_types=1);

/**
 * iHymns — lyric-body content-gating strip-list lockstep guard (#2073 commit 4, "G5")
 *
 * ELI5: this file makes sure two DIFFERENT files agree about "which
 * `song_detail` include blocks show what the lyrics say or who sings them" —
 * `includes/SongData.php` (which blocks EXIST) and
 * `includes/access_resolver.php` (which blocks get STRIPPED when a viewer
 * isn't allowed to see the lyric body). If a future commit adds a new
 * lyric-body-shaped block to one file and forgets the other, this is what
 * catches it — a denied viewer would otherwise see a block nobody ever told
 * `accessApplySong()` to remove, which is a silent content-gating LEAK, not
 * an error anyone would notice from a stack trace.
 *
 * DETAILED (#2073 "Design pass 7" §14, guard G5; rule #35 "cross-file
 * agreement needs a mechanism, not a comment"): before #2073, the two lists
 * happened to already agree (`vocalParts`/`translations`/`annotations` on
 * both sides) but NOTHING enforced that — this is exactly the shape of bug
 * rule #35 documents repeatedly (event names #1581, the CSRF header #1677):
 * a comment saying "keep these two lists in sync" is the failure, not the
 * fix. This test derives the "must be stripped" list from the SOURCE OF
 * TRUTH — `SongData::lyricBodyIncludeBlocks()`, a real PHP function called
 * directly, never a second hand-typed copy of the block names — and the
 * "actually stripped" list by parsing `access_resolver.php`'s own strip-list
 * array literal out of its source text (there is no public function to call
 * for that half; the array is local to `accessApplySong()`'s function body).
 *
 * TREE-DERIVED, NOT A TYPED LIST (rule #34): this test does not itself name
 * 'rounds'/'vocalWords'/'vocalParts' anywhere — it reads BOTH lists from the
 * files under test at run time, so a FUTURE block added to
 * `lyricBodyIncludeBlocks()` is covered automatically without this test file
 * ever being touched again.
 *
 * `includes/SongData.php` can be `require`d directly with no live DB: the
 * static method under test returns a plain array literal and touches no
 * `\mysqli` at all (confirmed against the pattern already used by
 * `tests/php/test-bulk-songs-hydration.php`, which requires the same file
 * the same way). `includes/access_resolver.php` is NOT required at all —
 * only its source TEXT is read — because requiring it pulls in
 * `content_gating.php` + `ccli_validator.php`, neither of which this test
 * needs merely to inspect one array literal.
 *
 *   php tests/php/test-lyric-body-strip-lockstep.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failed.
 *
 * MUTATION PROOF (rule #34 — run once during #2073 commit 4's own
 * verification pass, recorded here so a future reader does not have to take
 * the "this can fail" claim on faith):
 *   (1) removed `'rounds'` from `access_resolver.php`'s strip-list array
 *       literal, leaving `SongData::lyricBodyIncludeBlocks()` unchanged
 *       -> went RED: "'rounds' is in the strip list" (10 passed -> 8 passed,
 *       2 failed — "SongData::lyricBodyIncludeBlocks() member is in the
 *       strip list" ALSO failed, since 'rounds' is one of its members).
 *   (2) restored it, reran -> 10 passed, 0 failed.
 *   (3) removed `'rounds'` from `SongData::lyricBodyIncludeBlocks()` instead
 *       (leaving the strip list unchanged) -> went RED: "'rounds' is a
 *       lyric-body block" (10 passed -> 9 passed, 1 failed) — proving the
 *       guard catches drift from EITHER file, not just one, even though the
 *       TWO mutations tripped two DIFFERENT named assertions rather than the
 *       same one (removing a name from the "needed" list does not, on its
 *       own, break the needed-list-is-a-subset-of-strip-list check — it
 *       shrinks both sides of that particular comparison at once — so the
 *       guard also names 'rounds'/'vocalWords' directly, immediately below,
 *       specifically so a drift on the SongData side has its own assertion
 *       to fail rather than only ever showing up as a vacuous pass).
 *   (4) restored it, reran -> 10 passed, 0 failed.
 *
 * @see appWeb/public_html/includes/SongData.php          lyricBodyIncludeBlocks() / songDetailIncludeBlocks()
 * @see appWeb/public_html/includes/access_resolver.php   accessApplySong()'s strip-list foreach
 * @see .claude/vocal-parts-2073-plan.md                  the plan of record ("Design pass 7" §14 "G5")
 */

$root = dirname(__DIR__, 2);

require $root . '/appWeb/public_html/includes/SongData.php';

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

/* ====================================================================== *
 * 1 — derive the STRIP list from access_resolver.php's own source text.
 * ====================================================================== */
$resolverSrc = (string)file_get_contents($root . '/appWeb/public_html/includes/access_resolver.php');
ok('access_resolver.php read', $resolverSrc !== '', 'file_get_contents() returned empty/false');

/* Strip block AND line comments first — the doc-block above the foreach
   quotes several of the exact block names while explaining them, and prose
   must never satisfy an assertion meant to be about code (the same
   discipline test-lyric-lines-read.php already applies to lyric_lines_read.php). */
$resolverCode = (string)preg_replace('#/\*[\s\S]*?\*/#', '', $resolverSrc);
$resolverCode = (string)preg_replace('#//[^\n]*#', '', $resolverCode);

$stripList = [];
if (preg_match('/foreach\s*\(\s*\[(.*?)\]\s*as\s*\$bodyKey\s*\)/s', $resolverCode, $m)) {
    preg_match_all("/'([^']+)'/", $m[1], $names);
    $stripList = $names[1];
}
ok(
    'derived the strip-list array literal from accessApplySong()\'s $denyLyricBody foreach',
    $stripList !== [],
    'regex found no `foreach ([...] as $bodyKey)` in access_resolver.php — has the shape changed?'
);

/* ====================================================================== *
 * 2 — every lyric-body block SongData knows about must be in that list.
 * ====================================================================== */
$needed = SongData::lyricBodyIncludeBlocks();
ok('SongData::lyricBodyIncludeBlocks() is non-empty', $needed !== [], 'the method returned []');

$missing = array_values(array_diff($needed, $stripList));
ok(
    "every SongData::lyricBodyIncludeBlocks() member is in access_resolver.php's strip list",
    $missing === [],
    'missing from the strip list: ' . implode(', ', $missing)
);

/* 'components' itself must always be there too — voices/voiceSpans ride
   INSIDE a component (#2073), so stripping 'components' is what removes
   them; there is no separate 'voices' entry to check for (rule #35's own
   "the mechanism has to cover the WHOLE fact, not just the new blocks"). */
ok(
    "'components' is in the strip list (voices/voiceSpans ride inside it)",
    in_array('components', $stripList, true),
    'strip list was: ' . implode(', ', $stripList)
);

/* ====================================================================== *
 * 3 — cross-check: every "must strip" name is a REAL include block — a
 *     name added to lyricBodyIncludeBlocks() but never wired into
 *     songDetailIncludeBlocks() would pass assertion 2 vacuously (nothing
 *     ever emits it, so nothing ever needs stripping) while silently
 *     documenting a promise `getSongDetailExtras()` cannot keep.
 * ====================================================================== */
$allBlocks = SongData::songDetailIncludeBlocks();
$phantom = array_values(array_diff($needed, $allBlocks));
ok(
    'every lyricBodyIncludeBlocks() name is a real songDetailIncludeBlocks() entry',
    $phantom === [],
    'name(s) not in songDetailIncludeBlocks(): ' . implode(', ', $phantom)
);

/* ====================================================================== *
 * 4 — #2073 specifics named directly (not just "the lists agree in the
 *     abstract") so a reader can see at a glance that THIS commit's two new
 *     blocks are covered, mirroring the plan's own worked mutation example
 *     ("remove 'rounds' from either side -> red").
 * ====================================================================== */
ok("'rounds' is a lyric-body block", in_array('rounds', $needed, true));
ok("'vocalWords' is a lyric-body block", in_array('vocalWords', $needed, true));
ok("'rounds' is in the strip list", in_array('rounds', $stripList, true));
ok("'vocalWords' is in the strip list", in_array('vocalWords', $stripList, true));

echo "\n  ----------------------------------------\n";
echo "  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
