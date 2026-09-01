<?php

declare(strict_types=1);

/**
 * test-song-markup-line-id.php — song.php's lyric-line loop keeps emitting
 * `data-line-id` (#1266 Phase 2, rule #33)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING GUARDED
 * ----------------------
 * js/modules/song-markup.js is entirely DOM-first (rule #33): it discovers
 * every anchorable lyric line via `document.querySelectorAll('.lyric-line
 * [data-line-id]')` and never re-derives a line's identity by index/position.
 * That contract lives ENTIRELY in one place — the `<p class="lyric-line …>`
 * emission inside includes/pages/song.php's lyric-lines render loop — and
 * nothing in JS or in a schema migration would notice if a future refactor
 * of that loop (a reformat, a merge of the chords/translation logic, a
 * rename of `$lineId`) silently dropped the attribute: the page would still
 * render correctly, every OTHER feature on it would still work, and only the
 * markup layer would go quietly, permanently dark — the exact #1565/#1533
 * "silent no-op" failure class rule #30/#33 exist to name. This guard is
 * what turns that specific silent failure into a red CI run.
 *
 * WHY A STATIC SOURCE CHECK, NOT A RENDERED-PAGE CHECK
 * ------------------------------------------------------
 * Actually rendering includes/pages/song.php requires a live SongData/mysqli
 * stack this CI job does not have (and should not need, for a single
 * attribute contract). The render loop's SHAPE is what matters — the same
 * reasoning tests/php/test-song-visibility-guard.php gives for scanning
 * source rather than executing SQL — so this locates the loop by its own
 * structural marker (`foreach ($lines as $lineIdx => $line)`, rule #34:
 * derive from the tree, never assume a fixed line number) and asserts the
 * three things the JS module depends on, in the order the PHP actually
 * needs them: $lineId is computed from the #1089 `$lineIds` mirror, the
 * `<p class="lyric-line …>` tag carries the literal
 * `data-line-id="<?= (int)$lineId ?>"` attribute, and that attribute is
 * gated behind `if ($lineId > 0)` — never emitted as `data-line-id="0"` on
 * an un-migrated install or an unmatched line (rule #6: a shared-cache
 * fragment must never carry a value that isn't a genuine song-level fact).
 *
 * MUTATION RECORD (rule #34 — proven able to fail, not just written to pass)
 * ----------------------------------------------------------------------------
 * Applied to a SCRATCH COPY of song.php under this session's scratchpad
 * (never the tracked file — `git status --porcelain -- appWeb` was clean
 * before and after each mutant), pointed at by a one-off env override, run
 * against this same assertion list, and discarded:
 *
 *  M1  deleted the whole `data-line-id="<?= (int)$lineId ?>"` attribute,
 *      leaving `<p class="lyric-line mb-1">` unconditional again
 *      → RED: "the .lyric-line <p> emits the literal data-line-id=…" fails
 *        (the substring is simply gone from the window).
 *  M2  made the attribute unconditional (dropped the `if ($lineId > 0):
 *      … endif;` wrapper, always emitting `data-line-id="<?= (int)$lineId
 *      ?>"`)
 *      → RED: "data-line-id is gated on if ($lineId > 0)" fails — this is
 *        the mutation that matters most in practice, since an unconditional
 *        emission would leak `data-line-id="0"` into the shared-cache
 *        fragment for every un-migrated-install / unmatched line, which
 *        js/modules/song-markup.js would then try to treat as a real,
 *        anchorable line.
 *  M3  renamed the loop variable from `$lineIds[$lineIdx]` to a bare
 *      `$lineIdx` (i.e. reverted to the pre-#1089 "no stable id" shape)
 *      → RED: "render loop derives $lineId from $lineIds[$lineIdx]" fails.
 *  M4  broke the loop LOCATOR itself (renamed `$lineIdx` to `$idx` in a
 *      scratch copy, simulating "the loop moved/was rewritten")
 *      → RED via the locator assertion itself ("located the $lines render
 *        loop…"), not a false, confident green — the same floor discipline
 *        test-song-visibility-guard.php's M4 documents for its own walker.
 *  M5  GREEN survivor verified: the real, unmodified song.php as it stands
 *      in this commit.
 *
 * @see appWeb/public_html/includes/pages/song.php   the render loop itself
 * @see appWeb/public_html/js/modules/song-markup.js the DOM-first consumer
 * @see tests/php/test-song-visibility-guard.php     the sibling "derive by structural marker" precedent
 * @link https://github.com/MWBMPartners/iHymns/issues/1266
 */

$songPhp = dirname(__DIR__, 2) . '/appWeb/public_html/includes/pages/song.php';
$src = file_get_contents($songPhp);

$passed = 0;
$failed = 0;
function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  $label\n"; $passed++; }
    else { echo "  FAIL  $label\n"; $failed++; }
}

if ($src === false) {
    assertTrue(false, "could read $songPhp");
    echo "\n  $passed passed, $failed failed\n";
    exit(1);
}

/* TREE-DERIVED (rule #34): locate the render loop by its own structural
   marker rather than a hardcoded line number, so a reformat/reflow of
   song.php does not itself make this guard unable to find anything to
   check. `(?s)` lets `.` cross newlines; bounded lazily up to the loop's own
   closing `endforeach;` so the captured window is exactly one iteration's
   worth of markup, not the rest of the file. */
$found = preg_match(
    '/foreach\s*\(\s*\$lines\s+as\s+\$lineIdx\s*=>\s*\$line\s*\)\s*:(.*?)endforeach;/s',
    $src,
    $m
);
assertTrue((bool)$found, 'located the lyric-line render loop in song.php (foreach ($lines as $lineIdx => $line):)');

if (!$found) {
    /* Nothing further can be checked meaningfully — the floor discipline
       from test-song-visibility-guard.php's M4: a broken locator must fail
       loudly here, not report a confident, empty "everything passed". */
    echo "\n  $passed passed, $failed failed\n";
    exit(1);
}

$loopBody = $m[1];

assertTrue(
    str_contains($loopBody, '$lineId'),
    'render loop computes $lineId for each line'
);
assertTrue(
    (bool)preg_match('/\$lineId\s*=\s*\(int\)\s*\(\s*\$lineIds\[\s*\$lineIdx\s*\]\s*\?\?\s*0\s*\)/', $loopBody),
    'render loop derives $lineId from $lineIds[$lineIdx] — the #1089/#1235 stable tblLyricLines.Id mirror, never a re-sliced index'
);

/* Find the actual <p class="lyric-line …> emission and inspect a bounded
   window right after it, rather than one large fragile regex spanning the
   embedded PHP tags (which themselves contain a literal ">" inside
   "$lineId > 0" — a [^>]*-style regex would stop dead at that character). */
$pPos = strpos($loopBody, '<p class="lyric-line');
assertTrue($pPos !== false, 'found the <p class="lyric-line …> emission inside the loop');

$window = $pPos !== false ? substr($loopBody, $pPos, 400) : '';

assertTrue(
    str_contains($window, 'data-line-id="<?= (int)$lineId ?>"'),
    'the .lyric-line <p> emits the literal data-line-id="<?= (int)$lineId ?>" attribute'
);
assertTrue(
    (bool)preg_match('/if\s*\(\s*\$lineId\s*>\s*0\s*\)\s*:[\s\S]{0,60}data-line-id="<\?=\s*\(int\)\s*\$lineId\s*\?>"[\s\S]{0,40}<\?php\s+endif;\s*\?>/', $window),
    'data-line-id is gated behind if ($lineId > 0): … endif — omitted ENTIRELY otherwise, never emitted as data-line-id="0" (rule #6 cache-safety)'
);

echo "\n  ----------------------------------------\n";
echo "  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
