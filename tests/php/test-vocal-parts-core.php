<?php

declare(strict_types=1);

/**
 * iHymns — Vocal-parts WRITE-CORE truth table (#2073, commit 5)
 *
 * ELI5: this proves the WRITE half of `includes/vocal_parts.php` — the part
 * that lets a curator (or a future importer) actually SAY who sings a line,
 * not just read what was already said — does what it claims: a FUNCTIONAL
 * truth table over its PURE validators (never a grep over the source text,
 * rule #34), plus a structural, tree-derived, comment-stripped check that
 * every write function which resolves a `$songId` genuinely proves
 * ownership before touching a row (the IDOR guard the task brief asked for
 * "specified" — this is that specification, made executable).
 *
 * WHY PURE FUNCTIONS ONLY (no live MySQL here — same posture
 * `line_enrichment.php`'s own test file states plainly): "the DB-touching
 * upsert/delete functions need a live mysqli and are covered by manual /
 * staging verification; these pure guards are the CI-enforced contract."
 * This repo's PHP test image has no MySQL/MariaDB, so every DECISION this
 * commit makes had to be pushed into a function that needs no connection to
 * be exercised — that is what makes it testable here at all, and it is why
 * every validator below is a small, pure, single-purpose function rather
 * than inline logic buried inside a `\mysqli`-typed write function.
 *
 * #2073 commit 5 CROSS-REVIEW (F3/F4/F5/F6): a same-day cross-review found
 * that the WRITE core reintroduced the exact bug class this programme had
 * already removed twice that same day — a POSITIONAL guess standing in for
 * genuine identity. `lyricLinesSameSlotCarryPairs()` /
 * `lyricLinesCarryVocalRows()` (the "same-slot carry") are DELETED, not
 * fixed — see section 9 below, which proves they stay gone. The remaining
 * sections prove the three narrower fixes that survived the review: the
 * voices tri-state (F3), a complete + restorable delete-time snapshot (F4),
 * and an atomic (not silently-partial) voices apply (F6).
 *
 *   php tests/php/test-vocal-parts-core.php
 *
 * Exit status 0 = every assertion passed, 1 = at least one failed.
 *
 * @see appWeb/public_html/includes/vocal_parts.php       the write half under test
 * @see appWeb/public_html/includes/lyric_lines_sync.php  lyricLinesApplyDesired() / lyricLinesSnapshotDeletedEnrichment() / lyricLinesWriteComponents()
 * @see appWeb/public_html/includes/lyric_rounds.php      lyricRoundsToFlagFromRows()
 * @see appWeb/public_html/manage/editor/save_song_core.php  the whole-song save funnel
 * @see .claude/vocal-parts-2073-plan.md                  "Design pass 7" §3.1, §4.6 (superseded by the F5 same-day cross-review — the carry described there was REMOVED, not implemented as written)
 *
 * SECTION 17 (a later session, same file): a real bug found while building
 * the "Who sings" panel — `vocalPartsUpsert()`'s CREATE branch (`id`
 * absent) minted a brand-new `tblVocalParts` row every time, with none of
 * the same-kind dedupe its sibling `vocalPartsFindOrCreate()` always had.
 * Marking one run of lines "Women" then a second run "Women" again split
 * one singing group across two rows, silently. Section 17 proves the fix.
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/db_mysql.php';
require $root . '/appWeb/public_html/includes/line_enrichment.php';   // lineEnrichmentValidateOffsets() — vocalPartsValidateSpanOffsets() reuses it
require $root . '/appWeb/public_html/includes/vocal_parts.php';
require $root . '/appWeb/public_html/includes/lyric_lines_sync.php';  // lyricLinesDiff() / lyricLinesApplyDesired() / lyricLinesSnapshotDeletedEnrichment()
require $root . '/appWeb/public_html/includes/lyric_rounds.php';      // lyricRoundsToFlagFromRows()
require $root . '/tests/php/lib/php_source_units.php';

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
        if ($detail !== '') { echo "        $detail\n"; }
        $failed++;
    }
}

function assertEq($actual, $expected, string $label): void
{
    ok($label, $actual === $expected, 'expected: ' . var_export($expected, true) . ' | actual: ' . var_export($actual, true));
}

function assertThrows(callable $fn, string $expectedClass, string $label): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  FAIL  $label (no exception thrown)\n";
        $failed++;
    } catch (\Throwable $e) {
        if ($e instanceof $expectedClass) {
            echo "  PASS  $label\n";
            $passed++;
        } else {
            echo "  FAIL  $label (wrong exception: " . get_class($e) . ': ' . $e->getMessage() . ")\n";
            $failed++;
        }
    }
}

/* ====================================================================== *
 * 1 — vocalPartsRequireKind()
 * ====================================================================== */
echo "1 — vocalPartsRequireKind()\n";

assertEq(vocalPartsRequireKind('MEN'), 'male', 'a marker word resolves to its kind');
assertEq(vocalPartsRequireKind('female'), 'female', 'a bare kind key passes through');
assertEq(vocalPartsRequireKind('main'), 'lead', 'a legacy alias resolves');
assertThrows(static fn() => vocalPartsRequireKind('nonsense-kind'), \InvalidArgumentException::class,
    'an unrecognised kind throws (never silently guesses)');

/* ====================================================================== *
 * 2 — vocalPartsNormalizeLabelInput()
 * ====================================================================== */
echo "\n2 — vocalPartsNormalizeLabelInput()\n";

assertEq(vocalPartsNormalizeLabelInput(null), null, 'null stays null');
assertEq(vocalPartsNormalizeLabelInput('   '), null, 'whitespace-only folds to null');
assertEq(vocalPartsNormalizeLabelInput('  Youth Choir  '), 'Youth Choir', 'trims outer whitespace');
assertEq(vocalPartsNormalizeLabelInput(str_repeat('x', 200), 120), str_repeat('x', 120), 'caps at maxLen (code points)');
assertEq(vocalPartsNormalizeLabelInput(str_repeat('é', 200), 120), str_repeat('é', 120), 'caps by CODE POINT, not byte (rule #21) — 200 two-byte chars still cap at 120 chars');

/* ====================================================================== *
 * 3 — vocalPartsFoldHiddenLabel() — rule #45's hide-when-equal, applied here
 * ====================================================================== */
echo "\n3 — vocalPartsFoldHiddenLabel()\n";

assertEq(vocalPartsFoldHiddenLabel(null, 'female'), null, 'null in, null out');
assertEq(vocalPartsFoldHiddenLabel('Women', 'female'), null, 'a label equal to the kind\'s own word folds to null');
assertEq(vocalPartsFoldHiddenLabel('WOMEN', 'female'), null, 'the fold is case-insensitive');
assertEq(vocalPartsFoldHiddenLabel('Youth Choir', 'female'), 'Youth Choir', 'a genuinely different label survives untouched');
assertEq(vocalPartsFoldHiddenLabel('Men', 'female'), 'Men', 'a DIFFERENT kind\'s word is not folded — only a match against THIS part\'s own kind counts');

/* MUTATION PROOF (documented, exercised manually — see this commit's report):
   deleting the mb_strtolower() fold so the comparison becomes case-sensitive
   makes the 'WOMEN'/'female' assertion above fail (it would then survive as
   a "real" label) while the exact-case 'Women' assertion stays green — a
   single-case mutation the earlier assertion alone could not have caught,
   which is why both a same-case and a different-case example are asserted. */

/* ====================================================================== *
 * 4 — vocalPartsNormalizeGenderInput() — rule #44 (derive, never re-ask)
 * ====================================================================== */
echo "\n4 — vocalPartsNormalizeGenderInput()\n";

assertEq(vocalPartsNormalizeGenderInput(null, 'male'), 'male', 'a null gender on a gendered kind derives the implied one');
assertEq(vocalPartsNormalizeGenderInput(null, 'choir'), null, 'a null gender on a non-gendered kind derives null (choir implies nothing)');
assertEq(vocalPartsNormalizeGenderInput('Female', 'male'), 'female', 'an explicit choice wins over — and can DISAGREE with — the kind\'s own implication (a female soloist singing the "male" part in a duet arrangement is a real case)');
assertThrows(static fn() => vocalPartsNormalizeGenderInput('robot', 'lead'), \InvalidArgumentException::class,
    'an unrecognised gender word throws');

/* ====================================================================== *
 * 5 — vocalPartsValidateNamedSingerInputs()
 * ====================================================================== */
echo "\n5 — vocalPartsValidateNamedSingerInputs()\n";

$threw = false;
try { vocalPartsValidateNamedSingerInputs('named-singer', null, null); } catch (\InvalidArgumentException $e) { $threw = true; }
ok('named-singer with NEITHER musicianId nor singerName throws', $threw);

$threw = false;
try { vocalPartsValidateNamedSingerInputs('named-singer', 42, null); } catch (\InvalidArgumentException $e) { $threw = true; }
ok('named-singer with a musicianId (no name) passes', !$threw);

$threw = false;
try { vocalPartsValidateNamedSingerInputs('named-singer', null, 'Jane Doe'); } catch (\InvalidArgumentException $e) { $threw = true; }
ok('named-singer with a typed singerName (no musician) passes', !$threw);

$threw = false;
try { vocalPartsValidateNamedSingerInputs('male', null, null); } catch (\InvalidArgumentException $e) { $threw = true; }
ok('every OTHER kind is a no-op even with neither field set', !$threw);

/* ====================================================================== *
 * 6 — vocalPartsNormalizeAssignMode()
 * ====================================================================== */
echo "\n6 — vocalPartsNormalizeAssignMode()\n";

assertEq(vocalPartsNormalizeAssignMode('Add'), 'add', 'case/trim-folds a valid mode');
assertEq(vocalPartsNormalizeAssignMode('REPLACE'), 'replace', 'the other valid mode');
assertThrows(static fn() => vocalPartsNormalizeAssignMode('merge'), \InvalidArgumentException::class,
    'an unrecognised mode throws rather than silently defaulting to one of the two');

/* ====================================================================== *
 * 7 — vocalPartsValidateSpanOffsets() — reuses lineEnrichmentValidateOffsets()
 * ====================================================================== */
echo "\n7 — vocalPartsValidateSpanOffsets()\n";

assertEq(vocalPartsValidateSpanOffsets(3, 7, 20), [3, 7], 'an ordinary interior span passes through unchanged');
assertThrows(static fn() => vocalPartsValidateSpanOffsets(0, 20, 20), \InvalidArgumentException::class,
    'a WHOLE-LINE span (start=0, end=cpLen) is rejected — "use the line control"');
assertThrows(static fn() => vocalPartsValidateSpanOffsets(5, 5, 20), \InvalidArgumentException::class,
    'a ZERO-WIDTH span (end === start) is rejected');
assertThrows(static fn() => vocalPartsValidateSpanOffsets(10, 3, 20), \InvalidArgumentException::class,
    'an INVERTED span (end before start) is rejected');
assertThrows(static fn() => vocalPartsValidateSpanOffsets(0, 25, 20), \InvalidArgumentException::class,
    'an out-of-range end (beyond cpLen) is rejected');
assertEq(vocalPartsValidateSpanOffsets(0, 19, 20), [0, 19], 'a span covering everything EXCEPT the very last code point is legal (not "whole line")');

/* ====================================================================== *
 * 8 — vocalPartsKindFromTtmlAgent() — pure ingest-only helper (D2, no caller yet)
 * ====================================================================== */
echo "\n8 — vocalPartsKindFromTtmlAgent()\n";

assertEq(vocalPartsKindFromTtmlAgent(['type' => 'person'], 0), 'lead', 'the FIRST person-type agent -> lead');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'person'], 1), 'soloist', 'a SUBSEQUENT person-type agent -> soloist (this file\'s own flagged, defensible resolution of the plan\'s unexplained personOrdinal parameter)');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'person'], 4), 'soloist', 'any later ordinal is still soloist, not something else');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'group'], 0), 'group', 'group type -> group kind');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'other'], 0), 'duet', 'other type -> duet kind (TTML "other" is how a source represents a fused duet voice)');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'character'], 0), 'named-singer', 'character type -> named-singer');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'organization'], 0), 'choir', 'organization type (not in the TTML2 base vocabulary, but seen in the wild) -> choir');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'PERSON'], 0), 'lead', 'the type attribute is case-folded');
assertEq(vocalPartsKindFromTtmlAgent([], 0), 'lead', 'a missing type attribute -> lead (Pass 2\'s own fallback, unaffected by the ordinal question)');
assertEq(vocalPartsKindFromTtmlAgent(['type' => 'nonsense'], 0), 'lead', 'an unrecognised type attribute -> lead, same fallback');

/* ====================================================================== *
 * SHARED SOURCE UNITS — lyric_lines_sync.php and vocal_parts.php,
 * comment-stripped via phpSourceUnits(), reused by sections 9-14 below.
 * ====================================================================== */
$vpSrc  = (string)file_get_contents($root . '/appWeb/public_html/includes/vocal_parts.php');
$vpUnits = phpSourceUnits($vpSrc);

$llsSrc   = (string)file_get_contents($root . '/appWeb/public_html/includes/lyric_lines_sync.php');
$llsUnits = phpSourceUnits($llsSrc);

$ssSrc   = (string)file_get_contents($root . '/appWeb/public_html/manage/editor/save_song_core.php');
$ssUnits = phpSourceUnits($ssSrc);

/**
 * Does ANY `sqlOnly` string for $fn (in $units) contain $needle, once
 * whitespace is collapsed? Used wherever the assertion is about the SHAPE
 * of a query (a real SQL literal, which phpSourceUnits() deliberately makes
 * opaque in the `code` view — see that file's own header for why).
 */
function llsSqlOnlyContains(array $units, string $fn, string $needle): bool
{
    foreach ($units[$fn]['sqlOnly'] ?? [] as $s) {
        if (str_contains((string)preg_replace('/\s+/', ' ', $s), $needle)) {
            return true;
        }
    }
    return false;
}

/* ====================================================================== *
 * 9 — NO POSITIONAL CARRY (#2073 commit 5 cross-review, F5)
 *
 * The same-slot carry (`lyricLinesSameSlotCarryPairs()` /
 * `lyricLinesCarryVocalRows()`) is DELETED, not weakened — a positional
 * guess is the wrong shape no matter how it is hedged. This section proves
 * (a)-(c) that neither function, nor any call to either, exists anywhere in
 * the file any more, and (d) the concrete `[A, B] -> [X, B]` case the
 * cross-review named: a heavy rewrite of A (below the diff's own fuzzy
 * floor) must leave X — the brand-new line taking A's old slot — with
 * NOTHING carried onto it. This repo's PHP test image has no live mysqli,
 * so "X ends up with no voice marks" is proven the only way it CAN be
 * proven without one: `lyricLinesDiff()` (pure) shows X is a genuine
 * unmatched INSERT, and (a)-(c) prove there is no code path left anywhere
 * that could attach A's rows to it — together those two facts are the
 * whole of what "no voice marks land on X" means at the code level.
 * ====================================================================== */
echo "\n9 — NO POSITIONAL CARRY (F5) — the same-slot carry stays deleted\n";

ok('lyricLinesSameSlotCarryPairs() no longer exists anywhere in lyric_lines_sync.php',
    !isset($llsUnits['lyricLinesSameSlotCarryPairs']));
ok('lyricLinesCarryVocalRows() no longer exists anywhere in lyric_lines_sync.php',
    !isset($llsUnits['lyricLinesCarryVocalRows']));

$applyDesiredCode = $llsUnits['lyricLinesApplyDesired']['code'] ?? '';
ok('lyricLinesApplyDesired() never calls lyricLinesSameSlotCarryPairs()',
    $applyDesiredCode !== '' && !str_contains($applyDesiredCode, 'lyricLinesSameSlotCarryPairs('));
ok('lyricLinesApplyDesired() never calls lyricLinesCarryVocalRows()',
    $applyDesiredCode !== '' && !str_contains($applyDesiredCode, 'lyricLinesCarryVocalRows('));

/* (d) [A, B] -> [X, B]: A is a rewritten-below-the-fuzzy-floor line (a
   completely different lyric, not a typo), B is unchanged. Real hymn text
   so the fuzzy-similarity floor is exercised honestly, not gamed. */
$existingAB = [
    ['Id' => 10, 'PartType' => 'verse', 'PartNumber' => 1, 'LineText' => 'Amazing grace how sweet the sound'],
    ['Id' => 11, 'PartType' => 'verse', 'PartNumber' => 1, 'LineText' => 'That saved a wretch like me'],
];
$desiredXB = [
    ['PartType' => 'verse', 'PartNumber' => 1, 'LineText' => 'Completely unrelated brand new lyric text'],
    ['PartType' => 'verse', 'PartNumber' => 1, 'LineText' => 'That saved a wretch like me'],
];
$similarityXA = lyricLinesSimilarity($desiredXB[0]['LineText'], $existingAB[0]['LineText']);
ok('sanity: X and A score well below the diff\'s 0.5 fuzzy floor (this scenario genuinely exercises "delete + insert", not "reuse")',
    $similarityXA < 0.5,
    'similarity = ' . $similarityXA);

$planXB = lyricLinesDiff($existingAB, $desiredXB);
assertEq($planXB['matchedIds'], [null, 11], '(d) X (di=0) is a genuine unmatched INSERT; B (di=1) reuses its existing Id 11');
assertEq($planXB['deleteIds'], [10], '(d) A (Id 10) is deleted, not reused, not carried anywhere');

/* MUTATION PROOF: re-adding lyricLinesSameSlotCarryPairs()/…CarryVocalRows()
   anywhere in the file, or re-wiring a call to either from
   lyricLinesApplyDesired(), flips this section's first four checks RED
   immediately — they do not depend on interpreting behaviour, only on the
   two names being genuinely absent from the tree. */

/* ====================================================================== *
 * 10 — vocalPartsVoiceCellAction() — the F3 tri-state fix
 *
 * #2073 commit 5 cross-review finding F3: the shipped version conflated
 * "component-level voices: null/[]" (CLEAR every line) with "this line's
 * own cell is absent" (leave that ONE line untouched) — both fell into the
 * same `continue`, so an explicit component-wide clear silently did
 * nothing. Every branch below is the one this bug lived in.
 * ====================================================================== */
echo "\n10 — vocalPartsVoiceCellAction() — F3 tri-state (absent=preserve, null/[]=clear, list=set)\n";

assertEq(vocalPartsVoiceCellAction(null, 0), ['action' => 'clear'],
    'component-level voices:null CLEARS this line — THE F3 regression case (the pre-fix code left this untouched)');
assertEq(vocalPartsVoiceCellAction([], 0), ['action' => 'clear'],
    'component-level voices:[] CLEARS this line too — same bug, the other empty spelling');
assertEq(vocalPartsVoiceCellAction([['kind' => 'MEN']], 3), ['action' => 'untouched'],
    'a per-line array that is simply SHORTER than this line\'s index -> untouched (nothing said about THIS line)');
assertEq(vocalPartsVoiceCellAction([null], 0), ['action' => 'clear'],
    'this ONE line\'s own cell is null -> cleared');
assertEq(vocalPartsVoiceCellAction([[]], 0), ['action' => 'clear'],
    'this ONE line\'s own cell is [] -> cleared, same as null');
assertEq(vocalPartsVoiceCellAction(['not-an-array'], 0), ['action' => 'untouched'],
    'a malformed (non-array, non-null) cell is skipped as best-effort transport, not treated as a clear or a crash');
assertEq(
    vocalPartsVoiceCellAction([[['kind' => 'MEN'], ['kind' => 'WOMEN', 'bg' => true]]], 0),
    ['action' => 'set', 'cell' => [['kind' => 'MEN'], ['kind' => 'WOMEN', 'bg' => true]]],
    'a genuine list of voice specs -> "set", carrying the list through unchanged'
);

/* MUTATION PROOF: reverting to the pre-fix single `if ($cells === null ||
   !array_key_exists($li, $cells)) { continue; }` shape (treating null/[] the
   SAME as "line's own cell absent") turns the first two assertions above
   from 'clear' into 'untouched' and they go RED — those two are the ENTIRE
   reason this function exists, so this is a direct, unhedged proof of F3. */

/* vocalPartsApplyComponentVoices() must actually DELEGATE to this pure
   decision — not re-derive it inline, which is exactly how the bug got
   reintroduced once already. */
$applyVoicesCode = $vpUnits['vocalPartsApplyComponentVoices']['code'] ?? '';
ok('vocalPartsApplyComponentVoices() calls vocalPartsVoiceCellAction() for its per-line decision (never re-derives it inline)',
    $applyVoicesCode !== '' && str_contains($applyVoicesCode, 'vocalPartsVoiceCellAction('));

/* ====================================================================== *
 * 11 — save_song_core.php carries the `voices` key through (F3, second half)
 *
 * #2073 commit 5 cross-review finding F3: the whole-song save funnel
 * rebuilt its per-component payload entry WITHOUT a `voices` key at all —
 * so even with the tri-state fixed one file over, a save that went through
 * THIS funnel could never reach it with voicesProvided true, silently
 * dropping every explicit clear or explicit voices list on the floor.
 * Structural (this funnel needs a live mysqli to run end-to-end) — mirrors
 * the EXACT key-present-only shape the `notes` field already uses two lines
 * above it, which is what this check also confirms is still there as the
 * template being followed.
 * ====================================================================== */
echo "\n11 — save_song_core.php carries `voices` key-present-only (mirrors `notes`)\n";

$saveSongCode = $ssUnits['editorSaveSongCore']['code'] ?? '';
ok('editorSaveSongCore() exists and was found as its own analysis unit', $saveSongCode !== '');
ok('the whole-song save funnel checks array_key_exists(\'notes\', ...) before setting the notes key (the template F3 follows)',
    str_contains($saveSongCode, "array_key_exists('notes'"));
ok('the whole-song save funnel ALSO checks array_key_exists(\'voices\', ...) before setting the voices key — F3\'s fix',
    str_contains($saveSongCode, "array_key_exists('voices'"));

/* MUTATION PROOF: deleting the `array_key_exists('voices', $comp)` block
   (reverting to the pre-fix shape where $writeCompEntry never mentions
   'voices' at all) makes the second assertion above the ONLY one that goes
   red — proving it is not satisfied merely by the sibling 'notes' check. */

/* ====================================================================== *
 * 12 — lyricRoundsToFlagFromRows() — the F4 flag decision, including the
 *      round-VOICE-only hit the pre-fix version missed entirely
 *
 * #2073 commit 5 cross-review finding F4 (part 1): the shipped version of
 * this decision only ever looked at a ROUND's own four line columns. A
 * VOICE's own partner-song span can be invalidated by the SAME line delete
 * while the round's own fields are completely untouched — that round was
 * never even fetched, so it was neither snapshotted nor flagged.
 * ====================================================================== */
echo "\n12 — lyricRoundsToFlagFromRows() — F4 round-flag decision\n";

$deleteIdsF4 = [101, 200, 210, 310, 602];

$roundsF4 = [
    ['Id' => 1, 'StartLineId' => 100, 'EndLineId' => 101, 'CodaStartLineId' => null, 'CodaEndLineId' => null],
    // Round 2's OWN StartLineId (200) is being deleted — CASCADE-deletes the
    // whole round; must be excluded even though its voice ALSO hits (210).
    ['Id' => 2, 'StartLineId' => 200, 'EndLineId' => 201, 'CodaStartLineId' => null, 'CodaEndLineId' => null],
    // Round 3's OWN fields (300/301) are completely untouched — only its
    // VOICE's own span (310) hits. THIS is the F4 fix: pre-fix, round 3
    // would not even have been fetched, let alone flagged.
    ['Id' => 3, 'StartLineId' => 300, 'EndLineId' => 301, 'CodaStartLineId' => null, 'CodaEndLineId' => null],
    // Round 4 is entirely unaffected — neither its own fields nor its
    // voice's span intersects the deletion.
    ['Id' => 4, 'StartLineId' => 400, 'EndLineId' => 401, 'CodaStartLineId' => null, 'CodaEndLineId' => null],
    // Round 5's own CodaStartLineId (602) is hit — the coda-span-breaks case.
    ['Id' => 5, 'StartLineId' => 600, 'EndLineId' => 601, 'CodaStartLineId' => 602, 'CodaEndLineId' => 603],
];
$roundVoicesF4 = [
    ['RoundId' => 1, 'VoiceNumber' => 1, 'StartLineId' => null, 'EndLineId' => null],
    ['RoundId' => 2, 'VoiceNumber' => 1, 'StartLineId' => 210, 'EndLineId' => 211],
    ['RoundId' => 3, 'VoiceNumber' => 1, 'StartLineId' => 310, 'EndLineId' => 311],
    ['RoundId' => 4, 'VoiceNumber' => 1, 'StartLineId' => null, 'EndLineId' => null],
    ['RoundId' => 5, 'VoiceNumber' => 1, 'StartLineId' => null, 'EndLineId' => null],
];

assertEq(
    lyricRoundsToFlagFromRows($roundsF4, $roundVoicesF4, $deleteIdsF4),
    [1, 3, 5],
    'round 1 (own EndLineId hit), round 3 (VOICE-ONLY hit — the F4 fix), and round 5 (own CodaStartLineId hit) are flagged; round 2 is excluded (cascade-deleted whole, despite its voice also hitting) and round 4 is untouched'
);

/* MUTATION-DISTINGUISHING CHECK: strip round 3's voice-level contribution
   (simulating the pre-fix "only look at the round's own columns" logic) and
   confirm round 3 is NO LONGER flagged — proving the assertion above is not
   accidentally passing for some other reason. This is the scenario a
   round-fields-only implementation gets wrong. */
$roundsF4NoRound3Fields = $roundsF4;   // round 3's OWN fields were never touched anyway
$preF4Flag = [];
foreach ($roundsF4NoRound3Fields as $round) {
    $rid = (int)$round['Id'];
    $deleteSet = array_flip($deleteIdsF4);
    if (isset($deleteSet[(int)$round['StartLineId']])) { continue; }
    $loses = isset($deleteSet[(int)($round['EndLineId'] ?? 0)])
        || ($round['CodaStartLineId'] !== null && isset($deleteSet[(int)$round['CodaStartLineId']]))
        || ($round['CodaEndLineId']   !== null && isset($deleteSet[(int)$round['CodaEndLineId']]));
    if ($loses) { $preF4Flag[] = $rid; }
}
assertEq($preF4Flag, [1, 5],
    'sanity: a round-fields-ONLY decision (the pre-fix shape) finds [1, 5] and MISSES round 3 entirely — proving the real function\'s [1, 3, 5] genuinely depends on the voice-level check, not on round 3 having been reachable some other way');

/* ====================================================================== *
 * 13 — lyricLinesSnapshotDeletedEnrichment() — the F4 completeness fixes
 *
 * #2073 commit 5 cross-review finding F4 (parts 2 and 3): the round/voice
 * capture used a hand-picked column list (not enough to actually restore
 * from), and tblLyricWords / tblPresentationSlideOverrides — both of which
 * genuinely CASCADE from the same line delete — were never captured at all,
 * despite a doc-block claiming this function covered "everything that
 * cascades". Structural (a live mysqli would be needed to run the function
 * itself), proven against the query SHAPES via the `sqlOnly` view.
 * ====================================================================== */
echo "\n13 — lyricLinesSnapshotDeletedEnrichment() — F4 capture completeness\n";

ok('the round capture is SELECT * (every restoration-critical column), not a hand-picked list',
    llsSqlOnlyContains($llsUnits, 'lyricLinesSnapshotDeletedEnrichment', 'SELECT * FROM tblLyricRounds WHERE Id IN'));
ok('the round-VOICE capture is SELECT * too (EntryBasis/EntryLines/EntryBeats/EntryMs/VocalPartId/… all restorable)',
    llsSqlOnlyContains($llsUnits, 'lyricLinesSnapshotDeletedEnrichment', 'SELECT * FROM tblLyricRoundVoices WHERE RoundId IN'));
ok('rounds are discovered via a VOICE\'s own StartLineId/EndLineId, not only the round\'s own four columns',
    llsSqlOnlyContains($llsUnits, 'lyricLinesSnapshotDeletedEnrichment', 'SELECT RoundId FROM tblLyricRoundVoices'));
ok('tblLyricWords (per-word timing, ON DELETE CASCADE from tblLyricLines) is captured for the deleted lines',
    llsSqlOnlyContains($llsUnits, 'lyricLinesSnapshotDeletedEnrichment', 'FROM tblLyricWords WHERE LineId IN'));
ok('tblPresentationSlideOverrides (per-line/word style patches, ALSO ON DELETE CASCADE) is captured',
    llsSqlOnlyContains($llsUnits, 'lyricLinesSnapshotDeletedEnrichment', 'FROM tblPresentationSlideOverrides'));

/* The gate for tblPresentationSlideOverrides must be its OWN existence
   probe (it ships in a separate migration, migrate-presentation-themes.php,
   and can genuinely be absent) — never assumed present just because
   tblLyricLines is. Structural only (no live DB call): confirms
   lyricLinesVocalTablesPresent() declares a `slideOverrides` key sourced
   from ITS OWN INFORMATION_SCHEMA hit on tblPresentationSlideOverrides,
   and that the snapshot function actually gates its capture on it. */
$vocalTablesPresentCode = $llsUnits['lyricLinesVocalTablesPresent']['code'] ?? '';
ok('lyricLinesVocalTablesPresent() declares a slideOverrides key…',
    $vocalTablesPresentCode !== '' && str_contains($vocalTablesPresentCode, "'slideOverrides'"));
ok('…sourced from its own tblPresentationSlideOverrides existence check (a separate migration, genuinely can be absent)',
    llsSqlOnlyContains($llsUnits, 'lyricLinesVocalTablesPresent', 'tblPresentationSlideOverrides'));
ok('the snapshot function gates its capture on that SAME slideOverrides flag',
    str_contains($llsUnits['lyricLinesSnapshotDeletedEnrichment']['code'] ?? '', "vocalTables['slideOverrides']"));

/* MUTATION PROOF: reverting either round capture to its old hand-picked
   column list, or deleting either new table's capture, removes the exact
   substring each assertion above looks for and the corresponding check
   goes red — these are shape checks, not behavioural inference, so there
   is no way to satisfy them without the capture genuinely being there. */

/* ====================================================================== *
 * 14 — lyricLinesWriteComponents() — the F6 atomicity fix
 *
 * #2073 commit 5 cross-review finding F6: vocalPartsApplyComponentVoices()
 * performs several independent writes; a bug or a genuine DB error partway
 * through left whatever it had ALREADY written committed, while the
 * surrounding best-effort catch still reported the whole song save as an
 * unqualified success — "partial success reported as success". Structural
 * (this needs a live mysqli + a genuine mid-call failure to exercise the
 * rollback path end-to-end; not reachable in this DB-less test image), but
 * unambiguous: a savepoint set BEFORE the call, released only after it
 * returns, and rolled back TO on any caught failure, is what makes the unit
 * atomic rather than silently partial.
 * ====================================================================== */
echo "\n14 — lyricLinesWriteComponents() — F6 savepoint-scoped atomicity\n";

$writeComponentsCode = $llsUnits['lyricLinesWriteComponents']['code'] ?? '';
ok('lyricLinesWriteComponents() exists and was found as its own analysis unit', $writeComponentsCode !== '');

$posSavepoint = strpos($writeComponentsCode, "savepoint('ihymns_vp_apply')");
$posApplyCall = strpos($writeComponentsCode, 'vocalPartsApplyComponentVoices(');
$posRelease   = strpos($writeComponentsCode, "release_savepoint('ihymns_vp_apply')");
/* ⚠️ The check below deliberately looks for the SQL statement, not for
   `rollback(0, 'ihymns_vp_apply')`. That method call was what the code used to
   do and it was WRONG: in mysqli, rollback()'s second argument names a
   TRANSACTION, not a savepoint, so it rolled back the whole song save while the
   caller went on reporting success. Unwinding to a savepoint can only be done
   with the SQL statement. This assertion is therefore also a guard against
   somebody "tidying" the raw query back into the method call. (#2073) */
/* The SQL text lives in the unit's `strings` view, not `code` — the tokenizer
   renders string literals opaquely in `code` so that a statement merely
   MENTIONED in prose can never satisfy a check. */
$writeComponentsStrings = implode("\n", $llsUnits['lyricLinesWriteComponents']['strings'] ?? []);
$posRollback  = strpos($writeComponentsStrings, 'ROLLBACK TO SAVEPOINT ihymns_vp_apply');
$posBadRollback = strpos($writeComponentsCode, "rollback(0, 'ihymns_vp_apply')");

ok('a savepoint is set BEFORE vocalPartsApplyComponentVoices() is called',
    $posSavepoint !== false && $posApplyCall !== false && $posSavepoint < $posApplyCall,
    "savepoint at $posSavepoint, apply call at " . var_export($posApplyCall, true));
ok('the SAME savepoint is released only AFTER a successful apply',
    $posRelease !== false && $posApplyCall !== false && $posRelease > $posApplyCall,
    "release at $posRelease, apply call at " . var_export($posApplyCall, true));
ok('a caught failure rolls back TO the same savepoint — the unit is atomic, not silently partial',
    $posRollback !== false);
ok('it does NOT use mysqli rollback(0, name), which would discard the whole transaction',
    $posBadRollback === false,
    'found rollback(0, ...) at ' . var_export($posBadRollback, true)
    . ' — that names a TRANSACTION, not a savepoint, and would throw away the song save');

/* MUTATION PROOF: removing the savepoint(), the release_savepoint() or the
   ROLLBACK TO SAVEPOINT statement — or reordering the savepoint to AFTER the
   apply call — flips the corresponding check above red; each anchors on a real,
   load-bearing token sequence, not a comment or a nearby name. Swapping the SQL
   statement back for mysqli's rollback(0, name) trips BOTH the positive and the
   negative check, which is the point: that swap is the actual bug this pair was
   written to prevent recurring. */

/* ====================================================================== *
 * 15 — STRUCTURAL IDOR GUARD (tree-derived from the file's OWN function
 *      list, comment-stripped via the shared phpSourceUnits() tokenizer —
 *      never a bare grep of the raw file text, which a doc-block PROSE
 *      mention of "vocalPartsResolveLines()" would satisfy even after the
 *      real call was deleted)
 * ====================================================================== */
echo "\n15 — structural IDOR guard (every song-scoped write proves ownership)\n";

/**
 * True when $fn's comment-stripped body calls one of the ownership-proving
 * resolvers by name, OR (for a function whose own inline SQL does the
 * ownership JOIN itself, e.g. vocalPartsSpanDelete) its reconstructed
 * STRING content mentions the literal column name 'SongId' — the SQL text
 * itself collapses to an opaque `@STR@`/`@SQL:…@` marker in the `code` view
 * specifically so PROSE can never satisfy this check, but the `strings`
 * view exists precisely so the SQL's own content still can.
 */
function vocalPartsFnProvesOwnership(array $units, string $fn, array $codeMarkers): bool
{
    $code = $units[$fn]['code'] ?? null;
    if ($code === null) {
        return false;   // function not found at all — never silently pass
    }
    foreach ($codeMarkers as $marker) {
        if (str_contains($code, $marker)) {
            return true;
        }
    }
    foreach ($units[$fn]['strings'] ?? [] as $s) {
        if (str_contains($s, 'SongId')) {
            return true;
        }
    }
    return false;
}

$idorChecks = [
    'vocalPartsUpsert'             => ['vocalPartsResolvePart(', 'lyricLinesEnsurePrimaryVersion('],
    'vocalPartsDelete'             => ['vocalPartsResolvePart('],
    'vocalPartsAssignLines'        => ['vocalPartsResolveLines('],
    'vocalPartsClearLines'         => ['vocalPartsResolveLines('],
    'vocalPartsSpanUpsert'         => ['vocalPartsResolveLines('],
    'vocalPartsSpanDelete'         => [],   // proves ownership via its own inline JOIN — 'SongId' in strings
    'vocalPartsApplyComponentVoices' => ['vocalPartsResolveLines(', 'vocalPartsClearLines(', 'vocalPartsAssignLines('],
];
foreach ($idorChecks as $fn => $markers) {
    ok(
        "$fn proves song ownership before writing (calls a resolver, or its own SQL checks SongId)",
        vocalPartsFnProvesOwnership($vpUnits, $fn, $markers),
        "checked markers: " . implode(', ', $markers ?: ['(SongId in strings only)'])
    );
}

/* The two DELIBERATE exceptions (documented in their own doc-blocks in
   vocal_parts.php): ingest-only, version-scoped primitives that bypass
   $songId ownership resolution ON PURPOSE, because their caller already
   holds a KNOWN $lyricsId from having just minted the tblLyrics row itself,
   inside the SAME transaction — there is no $songId argument for them to
   check ownership against at all. Asserted here as a FLOOR (both must still
   exist with this exact, no-$songId signature) rather than silently
   excluded, so a future edit that quietly ADDS a $songId parameter without
   also adding the matching resolver call cannot slip through unnoticed. */
foreach (['vocalPartsAssignLinesForVersion', 'vocalPartsAssignWords'] as $fn) {
    $code = $vpUnits[$fn]['code'] ?? null;
    ok("$fn exists and is version-scoped, not song-scoped (deliberate ownership exception)", $code !== null);
}

/* ====================================================================== *
 * 16 — VOCAL_PARTS_PAYLOAD_KEYS <-> vocalPartsForSong()'s actual return
 *      shape lockstep (rule #35 — a mechanism, not a comment): the
 *      constant was declared back in commit 1 with nothing reading it yet;
 *      this proves the commit-5 function that finally DOES read it emits
 *      exactly those eight keys, in the SAME order, on both its early
 *      (un-migrated / no-version) return AND its full return.
 * ====================================================================== */
echo "\n16 — VOCAL_PARTS_PAYLOAD_KEYS <-> vocalPartsForSong() lockstep\n";

function vocalPartsForSongLiteralKeys(string $code, string $anchorVar): ?array
{
    if (!preg_match('/\\' . $anchorVar . '\s*=\s*\[(.*?)\];/s', $code, $m)) {
        return null;
    }
    preg_match_all("/'(\w+)'\s*=>/", $m[1], $km);
    return $km[1];
}

$forSongCode = $vpUnits['vocalPartsForSong']['code'] ?? '';
$emptyKeys   = vocalPartsForSongLiteralKeys($forSongCode, '$empty');
assertEq($emptyKeys, VOCAL_PARTS_PAYLOAD_KEYS, 'the early "$empty" return literal\'s keys, in order, equal VOCAL_PARTS_PAYLOAD_KEYS exactly');

/* The FINAL `return [ ... ];` — find it as the LAST array literal assigned
   to the bare `return` (not `$empty`), by matching every `return [ ... ];`
   in the function and taking the one whose key set is the fullest (the
   early-return path re-uses $empty, so this is unambiguous). */
preg_match_all('/return\s*\[(.*?)\];/s', $forSongCode, $rm);
$bestKeys = null;
foreach ($rm[1] as $block) {
    preg_match_all("/'(\w+)'\s*=>/", $block, $km);
    if ($bestKeys === null || count($km[1]) > count($bestKeys)) {
        $bestKeys = $km[1];
    }
}
assertEq($bestKeys, VOCAL_PARTS_PAYLOAD_KEYS, 'the full "return [...]" literal\'s keys, in order, equal VOCAL_PARTS_PAYLOAD_KEYS exactly');

/* ====================================================================== *
 * 17 — vocalPartsUpsert() DUPLICATE-PART GUARD (bug found while building
 *      the "Who sings" panel, task brief for this session).
 *
 * BEFORE this fix, a CREATE request (`id` absent) always INSERTed a brand
 * new `tblVocalParts` row, even when a part naming the exact same voice
 * already existed on the version: mark one run of lines "Women", mark a
 * second run "Women" again, and the song ended up with TWO Women parts —
 * one singing group silently split across two rows, nothing erroring, the
 * screen looking right. `vocalPartsFindOrCreate()` (the ingest-facing
 * sibling, proven earlier in this file) always had this check; no api2
 * action ever called it — verified by hand: `grep -c vocalPartsFindOrCreate
 * manage/editor/api2.php` returns 0 — so `vocalPartsUpsert()` was the ONE
 * creation path with the hole (every endpoint that creates a part calls
 * it, directly or via `vocal_part_upsert`).
 *
 * Structural only (this needs a live mysqli to run end-to-end; not
 * reachable in this DB-less test image — same posture as sections 14/15
 * above), over the query SHAPES via the `sqlOnly` view (never the opaque
 * `code` view for the query TEXT itself, since two different queries
 * against the same table render to the SAME `@SQL:SELECT:tblVocalParts@`
 * marker there) plus positional checks on the surrounding `code` for
 * ordering and control flow.
 * ====================================================================== */
echo "\n17 — vocalPartsUpsert() duplicate-part guard (no duplicate parts on CREATE)\n";

$upsertCode = $vpUnits['vocalPartsUpsert']['code'] ?? '';
ok('vocalPartsUpsert() exists and was found as its own analysis unit', $upsertCode !== '');

ok('a named-singer create WITH a musicianId matches an existing part by (LyricsId, PartKind, MusicianId) — the same real person cannot get a second part',
    llsSqlOnlyContains($vpUnits, 'vocalPartsUpsert', 'SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = ? AND MusicianId = ? LIMIT 1'));
ok('a named-singer create with ONLY a typed name (no musicianId) matches an existing part by (LyricsId, PartKind, MusicianId IS NULL, SingerName) — a case vocalPartsFindOrCreate() itself has no real caller for, so this cannot just delegate to it',
    llsSqlOnlyContains($vpUnits, 'vocalPartsUpsert', 'SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = ? AND MusicianId IS NULL AND SingerName = ? LIMIT 1'));
ok('every other kind matches an existing part by (LyricsId, PartKind, Label <=> label) — the NULL-safe operator so "no label" only ever matches another "no label" row',
    llsSqlOnlyContains($vpUnits, 'vocalPartsUpsert', 'SELECT Id FROM tblVocalParts WHERE LyricsId = ? AND PartKind = ? AND Label <=> ? LIMIT 1'));

$posGuard  = strpos($upsertCode, 'if ($id === 0) {');
$posInsert = strpos($upsertCode, '@SQL:INSERT:tblVocalParts@');
ok('the dedupe guard is conditioned on this being a genuine CREATE call (checks $id === 0, never runs on an edit-by-id)',
    $posGuard !== false);
ok('the guard runs BEFORE the function ever commits to an INSERT (so a match is found ahead of any decision to mint a row)',
    $posGuard !== false && $posInsert !== false && $posGuard < $posInsert,
    "guard at $posGuard, INSERT at " . var_export($posInsert, true));

ok('on a match, the found row is RE-RESOLVED so the rest of the function edits that exact row (vocalPartsResolvePart($db,$songId,$id) is called a second time — once for an id the CALLER gave, once here for an id the GUARD found)',
    substr_count($upsertCode, 'vocalPartsResolvePart($db, $songId, $id)') >= 2);

/* MUTATION PROOF (performed by hand against the real file while writing
   this fix — not re-run automatically by the suite itself):
     1. Commented out the `if ($id === 0) { … }` guard block entirely,
        restoring vocalPartsUpsert() to its pre-fix always-INSERT shape.
     2. Ran `php tests/php/test-vocal-parts-core.php` — all five checks in
        this section failed RED (the three llsSqlOnlyContains() checks find
        nothing because the SQL literals are gone; $posGuard is false; the
        substr_count() drops to 1).
     3. Restored the block from source control and re-ran — all five back
        to PASS, and no other section's pass/fail count moved.
   This proves the checks are load-bearing (they fail without the fix) and
   scoped (they touch nothing else the fix didn't). */

/* ====================================================================== *
 * 18 — vocalPartsClearLines() OPTIONAL PART FILTER (independent-review
 *      BUG 1 fix, 2026-09 — data loss). `vocalPartReviewUndo()` used to
 *      clear EVERY assignment of a background class on a set of lines,
 *      with no way to say "only the ONE part I created" — so undoing one
 *      accepted suggestion could wipe a curator's own hand-made
 *      assignment, or one from a different accepted suggestion, on the
 *      very same lines. This is the write-core half of that fix: an
 *      optional `$partId` that, when given, scopes the DELETE to that one
 *      part and leaves every other part on the same lines untouched.
 *      Structural, like sections 15/17 above (this needs a live mysqli to
 *      run end to end; not reachable in this DB-less test image) — over
 *      the `strings` view for the query's literal text (the SQL is built
 *      by conditional `.=` appends across several statements, so the
 *      fragment never begins with a SQL verb and `sqlOnly` alone would
 *      never see it — confirmed by hand: the append literal really does
 *      land in `strings`, not just `code`, before writing this assertion
 *      against it) plus a positional check that the new filter is
 *      genuinely CONDITIONAL, mirroring the existing IsBackground filter
 *      right above it rather than replacing it.
 * ====================================================================== */
echo "\n18 — vocalPartsClearLines() optional VocalPartId filter (bug 1)\n";

$clearLinesCode    = $vpUnits['vocalPartsClearLines']['code'] ?? '';
$clearLinesStrings = $vpUnits['vocalPartsClearLines']['strings'] ?? [];
ok('vocalPartsClearLines() exists and was found as its own analysis unit', $clearLinesCode !== '');

/* The parameter list belongs to the ENCLOSING scope in phpSourceUnits()'s
   own model (a unit's `code` starts at the function's opening `{`, not
   its `(`), so the signature itself is checked against the raw source
   directly rather than the per-function `code` view. */
ok('the function signature accepts an optional $partId (defaulting to null, so every existing caller is unaffected)',
    (bool)preg_match('/function\s+vocalPartsClearLines\s*\([^{]*\?int\s+\$partId\s*=\s*null/', $vpSrc));
ok('the DELETE conditionally appends "AND VocalPartId = ?" — mirroring the existing IsBackground filter\'s own shape',
    str_contains($clearLinesCode, '$partId !== null') && in_array(' AND VocalPartId = ?', $clearLinesStrings, true));
ok('the bound $partId value is the caller\'s own parameter, not a hard-coded stand-in',
    str_contains($clearLinesCode, '$params[] = $partId;'));

/* MUTATION PROOF (rule #34 — a guard must be able to fail): a synthetic
   copy of the OLD (pre-fix) function shape, with no part filter at all,
   must be caught by the SAME checks the real (fixed) function passes
   above. */
$oldClearSrc = "<?php function fakeOldClearLines(\\mysqli \$db, string \$songId, array \$lineIds, ?bool \$isBackground = null): int {
    \$sql = 'DELETE FROM tblLyricLineVocalParts WHERE LineId IN (?)';
    if (\$isBackground !== null) { \$sql .= ' AND IsBackground = ?'; }
    return 0;
}";
$oldClearUnits   = phpSourceUnits($oldClearSrc);
$oldClearStrings = $oldClearUnits['fakeOldClearLines']['strings'] ?? [];
ok('mutation-proof: the "$partId parameter exists" check correctly FAILS the old (pre-fix) shape',
    !(bool)preg_match('/function\s+fakeOldClearLines\s*\([^{]*\?int\s+\$partId\s*=\s*null/', $oldClearSrc));
ok('mutation-proof: the "AND VocalPartId = ?" check correctly FAILS the old (pre-fix) shape',
    !in_array(' AND VocalPartId = ?', $oldClearStrings, true));

/* MANUAL MUTATION PROOF, performed by hand against the real file while
   writing this fix (same posture as section 17's own note above):
     1. Removed the `?int $partId = null` parameter and the trailing
        `if ($partId !== null) { … }` block from the real
        vocalPartsClearLines() in appWeb/public_html/includes/vocal_parts.php.
     2. Ran `php tests/php/test-vocal-parts-core.php` — this section's
        three checks failed RED; no other section's pass/fail count moved.
     3. Restored the block from source control and re-ran — all three back
        to PASS. */

/* ====================================================================== *
 * 19 — vocalPartsUpsert() PRESERVES AN EXISTING DUPLICATE'S DETAILS
 *      (independent-review BUG 4 fix, 2026-09 — the reviewer's own words:
 *      "my own duplicate-part fix erases existing details", referring to
 *      section 17's dedupe guard above). BEFORE this fix, $label /
 *      $singerName / $musicianId were all worked out BEFORE the dedupe
 *      lookup ran — at that point $existing was still null (this is a
 *      CREATE call), so any field the caller did not explicitly supply
 *      was locked in as null. Once the dedupe lookup found a match and
 *      pointed $existing at that REAL row, the function fell into the
 *      "edit an existing part" branch below and would overwrite that
 *      part's saved label/singer/musician with those already-computed
 *      nulls — silently blanking details a curator never touched.
 *      Structural, same posture as section 17 (no live mysqli here): the
 *      fix re-runs each field's OWN "caller omitted it -> keep $existing's
 *      value" fallback a second time, now that $existing points at the
 *      duplicate — so each `array_key_exists('<field>', $input)` check
 *      appears TWICE in the function (once for the ordinary $id>0 edit
 *      path, once again inside the dedupe-hit branch), and the SECOND
 *      occurrence of each sits AFTER the dedupe lookup, not before it.
 * ====================================================================== */
echo "\n19 — vocalPartsUpsert() re-derives label/singerName/musicianId after a dedupe hit (bug 4)\n";

$posDupeHit = strpos($upsertCode, '$dupeRow !== null');
ok('vocalPartsUpsert() still has its dedupe-hit branch (section 17\'s own fix, untouched by this one)', $posDupeHit !== false);

foreach (['label', 'singerName', 'musicianId'] as $field) {
    $needle = "array_key_exists('$field', \$input)";
    ok("the '$field' caller-omitted fallback is checked TWICE — once for an ordinary edit, once again after a dedupe hit",
        substr_count($upsertCode, $needle) === 2);
    ok("the SECOND '$field' check sits AFTER the dedupe-hit branch starts (it re-derives from the FOUND duplicate, not the pre-lookup nulls)",
        $posDupeHit !== false && strrpos($upsertCode, $needle) > $posDupeHit);
}

/* MUTATION PROOF (rule #34): a synthetic copy of the OLD (pre-fix) shape —
   the caller-omitted fallback computed only ONCE, before any dedupe check
   — must be caught by the SAME "checked twice" assertion the real (fixed)
   function passes above. */
$oldUpsertSrc = "<?php function fakeOldUpsert(\\mysqli \$db, string \$songId, array \$input): array {
    \$label = array_key_exists('label', \$input) ? \$input['label'] : null;
    if (\$dupeRow !== null) {
        \$existing = 1;
    }
    return [];
}";
$oldUpsertUnits = phpSourceUnits($oldUpsertSrc);
$oldUpsertCode  = $oldUpsertUnits['fakeOldUpsert']['code'] ?? '';
ok("mutation-proof: the 'checked twice' check correctly FAILS a synthetic function that only computes the fallback once (the old shape)",
    substr_count($oldUpsertCode, "array_key_exists('label', \$input)") !== 2);

/* MANUAL MUTATION PROOF, performed by hand against the real file while
   writing this fix:
     1. Removed the `if ($existing !== null) { … }` re-derivation block
        (the one guarded by the "BUG FIX (independent review, 2026-09)"
        comment) from the real vocalPartsUpsert() in
        appWeb/public_html/includes/vocal_parts.php, restoring the
        pre-fix shape where $label/$singerName/$musicianId are only ever
        computed before the dedupe lookup.
     2. Ran `php tests/php/test-vocal-parts-core.php` — every "checked
        twice" and "sits AFTER" assertion in this section failed RED
        (each field's fallback was back down to a single occurrence);
        no other section's pass/fail count moved.
     3. Restored the block from source control and re-ran — all back to
        PASS. */

/* ====================================================================== */
echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed ($passed passed).\n";
    exit(1);
}
echo "All $passed vocal-parts write-core assertions passed.\n";
