<?php

declare(strict_types=1);

/**
 * iHymns — Song-of-the-Day "complete thought" lyric-preview heuristic (#1841)
 *
 * ELI5
 * ----
 * A hymn's first sung line is very often just its title again (lyric lines
 * break where the song is sung), so the Song-of-the-Day snippet used to read
 * as the title twice — e.g. "Restore, O Lord,". `lyricLinesJoinPreview()` now
 * joins the opening lines into ONE flowing "complete thought" phrase, stopping
 * at the first real sentence end past a sensible minimum, or a character cap.
 * This is the mutation-proven truth table for that pure heuristic, plus a
 * source-scan proving SongOfTheDay + the reader actually use it.
 *
 * PART A (always) — the pure `lyricLinesJoinPreview()` over hand-built line
 *   arrays: real inputs, exact expected phrases. No DB.
 * PART B (always) — source-scan: `lyric_lines_read.php` defines both the pure
 *   joiner and the DB-reading `lyricLinesPreviewPhrase()`; `SongOfTheDay.php`'s
 *   snippet method delegates to them (mirror + LinesJson-fallback paths) and
 *   still emits the `firstLine` payload key (API contract, kept stable).
 *
 * MUTATION-PROVEN (rule #34) — each actually run, then reverted:
 *   - Add `,` to the sentence-end regex in `lyricLinesJoinPreview()` (so a
 *     comma counts as a stop) -> RED: the "long comma-ended first line is a
 *     continuation" case (1b). NOTE: it does NOT redden the shorter title-only
 *     case (1), whose first line is under the min-length floor — the floor
 *     masks the comma bug there, so case 1b (past the floor, comma-ended) was
 *     added specifically to prove the ".!? only" rule independently. Reverted
 *     -> green.
 *   - Drop the `$minChars` floor (stop at ANY sentence end) -> RED: the
 *     "short exclamation does not stop alone" case (it would return just
 *     "Rejoice!"). Reverted -> green.
 *   - Remove the over-budget whole-line break -> RED: the maxChars case (it
 *     would append the line that busts the budget). Reverted -> green.
 *
 *   php tests/php/test-sotd-preview-phrase.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/lyric_lines_read.php';

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) { $failures++; }
}

/* =========================================================================
 * PART A — the pure join heuristic
 * ========================================================================= */

/* 1. The headline case: first line == title (+ trailing comma) — must go PAST
 *    the title to a real thought, and stop at the sentence-ending period. */
check(
    'title-only first line keeps going past the title, stops at the sentence end',
    lyricLinesJoinPreview(['Restore, O Lord,', 'the honour of Your name.'])
        === 'Restore, O Lord, the honour of Your name.'
);

/* 1b. A comma is a CONTINUATION, never a stop — even a long first line that
 *     ends with a comma keeps going to the real sentence end. (Case 1's first
 *     line is under the min-length floor, which would mask a comma-as-boundary
 *     bug; this line is well past the floor AND ends with a comma, so it proves
 *     the "sentence-end is .!? only" rule independently — the mutation that adds
 *     `,` to the boundary set reddens THIS case, not case 1.) */
check(
    'a long comma-ended first line is a continuation, not a stop',
    lyricLinesJoinPreview(['When peace like a river attendeth my way,', 'when sorrows like sea billows roll.'])
        === 'When peace like a river attendeth my way, when sorrows like sea billows roll.'
);

/* 2. Punctuation is judged at the LINE END only — a "!" mid-line-1 does not
 *    stop the join ("Amazing grace!" is not the end of the thought). */
check(
    'mid-line punctuation does not stop the join (line-end only)',
    lyricLinesJoinPreview(['Amazing grace! how sweet the sound', 'that saved a wretch like me!'])
        === 'Amazing grace! how sweet the sound that saved a wretch like me!'
);

/* 3. The minimum-length floor: a very short exclamation is not a satisfying
 *    preview on its own, so the join continues to the next thought. */
check(
    'a short exclamation does not stop the join alone (min-length floor)',
    lyricLinesJoinPreview(['Rejoice!', 'the Lord is King.'])
        === 'Rejoice! the Lord is King.'
);

/* 4. The character budget stops at a WHOLE-LINE boundary (never a mid-word
 *    cut — the ellipsis is the client's job) before exceeding maxChars. */
check(
    'the character budget stops at a whole-line boundary before overflowing',
    lyricLinesJoinPreview(['one two three,', 'four five six,', 'seven eight nine,'], 30)
        === 'one two three, four five six,'
);

/* 5. A single line longer than the budget is still returned (a snippet beats
 *    none) — the phrase is never empty when there is any usable line. */
check(
    'a single over-budget line is returned rather than dropped',
    lyricLinesJoinPreview(['this is a single very long line with no ending punctuation here'], 30)
        === 'this is a single very long line with no ending punctuation here'
);

/* 6. Whitespace inside a line is collapsed to single spaces. */
check(
    'internal whitespace (multiple spaces / tabs) is collapsed',
    lyricLinesJoinPreview(["Come,   thou\tfount of every blessing."])
        === 'Come, thou fount of every blessing.'
);

/* 7. Blank lines between real lines are skipped, not joined as empty gaps. */
check(
    'blank lines are skipped mid-list',
    lyricLinesJoinPreview(['Bless the Lord,', '   ', 'O my soul.'])
        === 'Bless the Lord, O my soul.'
);

/* 8. No usable line -> null (so a caller can fall back / render nothing). */
check('empty array returns null', lyricLinesJoinPreview([]) === null);
check('all-blank lines return null', lyricLinesJoinPreview(['', '   ', "\t"]) === null);

/* 9. A first line that is itself a complete sentence (past the floor) stops
 *    there — it IS a complete thought already. */
check(
    'a complete first sentence stops at line one',
    lyricLinesJoinPreview(['Great is Thy faithfulness, O God my Father!', 'There is no shadow of turning with Thee.'])
        === 'Great is Thy faithfulness, O God my Father!'
);

/* =========================================================================
 * PART B — wiring source-scan
 * ========================================================================= */
$reader = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/lyric_lines_read.php');
$sotd   = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/SongOfTheDay.php');

check('lyric_lines_read.php defines lyricLinesJoinPreview()',
    strpos($reader, 'function lyricLinesJoinPreview(') !== false);
check('lyric_lines_read.php defines lyricLinesPreviewPhrase()',
    strpos($reader, 'function lyricLinesPreviewPhrase(') !== false);
check('SongOfTheDay uses the preview phrase on the migrated (mirror) path',
    strpos($sotd, 'lyricLinesPreviewPhrase(') !== false);
check('SongOfTheDay joins lines on the un-migrated LinesJson fallback path',
    strpos($sotd, 'lyricLinesJoinPreview(') !== false);
check('SongOfTheDay no longer reduces the snippet to the single first line',
    strpos($sotd, 'lyricLinesFirstLine(') === false);
check('SongOfTheDay still emits the firstLine payload key (API contract kept)',
    strpos($sotd, "'firstLine'") !== false);

/* =========================================================================
 * REPORT
 * ========================================================================= */
if ($failures) {
    echo "\nFAIL: {$failures} Song-of-the-Day preview-phrase check(s) failed.\n";
    exit(1);
}
echo "\nOK: the lyric preview joins opening lines into a complete-thought phrase (stops at a real sentence end past the floor, respects the budget at whole-line boundaries) and SongOfTheDay is wired to it.\n";
exit(0);
