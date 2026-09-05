<?php

declare(strict_types=1);

/**
 * test-line-transliteration-display.php — the song page shows
 * transliterations, not just translations, and never lets the two look
 * like each other (#320)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING GUARDED
 * ----------------------
 * A transliteration is a song's words written out in a different alphabet
 * (e.g. Korean 사랑 spelled "sarang") so someone who cannot read the
 * original script can still sing along. It is NOT a translation — a
 * translation carries a different MEANING in another language.
 *
 * The database has stored transliterations since #1088. The editor can
 * write them since #1089. But includes/pages/song.php — the one place a
 * reader ever sees a lyric line — read the whole `translations` block back
 * from SongData::getSongDetailExtras() and then threw every
 * `kind === 'transliteration'` row away with a one-line `continue`, keeping
 * only `kind === 'translation'`. So entering a transliteration produced
 * nothing a reader could ever see: the feature looked finished (storage
 * done, editor done, API returning the rows) and quietly did nothing at
 * the one place that mattered. This guard is what turns "someone quietly
 * re-adds that filter" back into a red CI run.
 *
 * It also guards the SECOND half of the fix, which is easy to get subtly
 * wrong in the other direction: once transliteration rows are let through,
 * they must not be rendered exactly like a translation row (same italic
 * style, no distinguishing mark) — a reader would then see two unlabelled
 * italic lines under a lyric and have no way to tell "this is what it
 * means" from "this is how to say it". The fix keeps both kinds behind the
 * SAME toggle button and the SAME `.lyric-line-translation` class (see
 * "WHY ONE TOGGLE, NOT TWO" below) but gives a transliteration row its own
 * CSS look and a screen-reader-only lead-in translation rows don't get.
 *
 * WHY ONE TOGGLE, NOT TWO
 * ------------------------
 * js/modules/song-translations.js is out of scope for this change (owned
 * by another workstream) and it is entirely generic: it finds ONE button
 * (`[data-line-translations-toggle]`) and ONE set of rows
 * (`.lyric-line-translation`) by class name alone, with no idea what kind
 * of row it is toggling. Reusing that exact mechanism for transliterations
 * — rather than inventing a second button/class pair — needs no JS change
 * at all, which is also why this guard checks that BOTH kinds keep the
 * literal `lyric-line-translation` class and the `data-line-translation-for`
 * attribute unconditionally: dropping either from one branch would silently
 * break the shared toggle (or js/modules/song-markup.js's
 * insertNoteAfterLine(), which walks forward over exactly that class/
 * attribute pair to find where a per-line "my note" belongs) for that kind
 * only — a second silent, feature-scoped regression of the same shape as
 * the one this file exists to catch in the first place.
 *
 * WHY A STATIC SOURCE CHECK, NOT A RENDERED-PAGE CHECK
 * ------------------------------------------------------
 * Actually rendering includes/pages/song.php requires a live SongData/
 * mysqli stack this CI job does not have — the same reasoning
 * tests/php/test-song-markup-line-id.php gives for scanning source rather
 * than executing SQL. So this locates the two blocks that changed by their
 * own structural markers (rule #34: derive from the tree, never assume a
 * fixed line number) rather than a hardcoded line number, and inspects a
 * bounded window of each.
 *
 * MUTATION RECORD (rule #34 — proven able to fail, not just written to pass)
 * ----------------------------------------------------------------------------
 * Applied one mutation at a time to a SCRATCH COPY of song.php under this
 * session's scratchpad (never the tracked file — `git status --porcelain
 * -- appWeb/public_html/includes/pages/song.php` and
 * `-- appWeb/public_html/css/app.css` were both clean before and after
 * every mutant), pointed at by a one-off copy of this test with the two
 * `$songPhp`/`$appCss` paths swapped to the scratch copies, run, and
 * discarded:
 *
 *  M1  restored the ORIGINAL bug: changed the kind check back to
 *      `if (($tr['kind'] ?? '') !== 'translation' || empty($tr['isPrimary']))`
 *      (transliteration rows discarded again)
 *      → RED: "the kind filter accepts 'transliteration' as well as
 *        'translation'" fails — this is the exact regression #320 reports.
 *  M2  removed `'kind' => $kind,` from the stored per-line array (rows are
 *      let through but the render loop would have nothing to branch on)
 *      → RED: "each stored row keeps its 'kind' value for the render loop
 *        to key off" fails.
 *  M3  made `$isTranslit` always `false` (hardcoded), so a transliteration
 *      row would render with `fst-italic` — identical to a translation
 *      → RED: "$isTranslit is computed from $lt['kind'] …" fails.
 *  M4  changed the class-building ternary so `lyric-line-transliteration`
 *      and `fst-italic` are both added unconditionally (the "distinguish
 *      them" requirement silently dropped)
 *      → RED: "a transliteration row's classes exclude fst-italic" fails.
 *  M5  changed the base class string from `'lyric-line-translation …'` to
 *      `'lyric-transliteration-row …'` for the translit branch only
 *      (breaking the shared toggle/note-insertion contract for that one
 *      kind)
 *      → RED: "the literal lyric-line-translation class is present
 *        unconditionally (both kinds, required for the shared toggle)"
 *        fails.
 *  M6  deleted the `.lyric-line-transliteration` rule from app.css
 *      → RED: "app.css defines a .lyric-line-transliteration rule" fails.
 *  M7  changed that CSS rule to `font-style: italic` (the one thing it
 *      must never be, since that is the translation's own cue)
 *      → RED: "the CSS rule does not set font-style: italic" fails.
 *  M8  GREEN survivor verified: the real, unmodified song.php + app.css as
 *      they stand in this commit.
 *
 * @see appWeb/public_html/includes/pages/song.php   the fetch/filter block + render loop
 * @see appWeb/public_html/css/app.css               the .lyric-line-transliteration rule
 * @see appWeb/public_html/js/modules/song-translations.js  the shared, kind-agnostic toggle (untouched)
 * @see appWeb/public_html/js/modules/song-markup.js        insertNoteAfterLine() (untouched)
 * @see tests/php/test-song-markup-line-id.php       the sibling "derive by structural marker" precedent
 * @link https://github.com/MWBMPartners/iHymns/issues/320
 */

$songPhp = dirname(__DIR__, 2) . '/appWeb/public_html/includes/pages/song.php';
$appCss  = dirname(__DIR__, 2) . '/appWeb/public_html/css/app.css';

$passed = 0;
$failed = 0;
function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  $label\n"; $passed++; }
    else { echo "  FAIL  $label\n"; $failed++; }
}

$src = file_get_contents($songPhp);
$css = file_get_contents($appCss);

if ($src === false) {
    assertTrue(false, "could read $songPhp");
    echo "\n  $passed passed, $failed failed\n";
    exit(1);
}
if ($css === false) {
    assertTrue(false, "could read $appCss");
    echo "\n  $passed passed, $failed failed\n";
    exit(1);
}

/* ---------------------------------------------------------------------
 * PART 1 — the fetch/filter block (song.php): both kinds get through.
 * Located by the query call that starts it and the flag assignment that
 * ends it, so a reformat of the block's own internals doesn't break the
 * locator.
 * --------------------------------------------------------------------- */
$found1 = preg_match(
    '/\$lineExtras\s*=\s*\$songData->getSongDetailExtras\([^;]*\);(.*?)\$hasLineTranslations\s*=\s*!empty\(\$lineTranslationsByLineId\);/s',
    $src,
    $m1
);
assertTrue((bool)$found1, 'located the per-line translations fetch/filter block in song.php');

if (!$found1) {
    /* Floor discipline (test-song-markup-line-id.php's M4 precedent): a
       broken locator must fail loudly, not report a false, empty "all
       green". */
    echo "\n  $passed passed, $failed failed\n";
    exit(1);
}
$fetchBlock = $m1[1];

assertTrue(
    str_contains($fetchBlock, "'transliteration'") && str_contains($fetchBlock, "'translation'"),
    'the fetch/filter block mentions both the translation and transliteration kinds'
);
assertTrue(
    (bool)preg_match(
        '/if\s*\(\s*\(\s*\$kind\s*!==\s*\'translation\'\s*&&\s*\$kind\s*!==\s*\'transliteration\'\s*\)\s*\|\|\s*empty\(\s*\$tr\[\s*\'isPrimary\'\s*\]\s*\)\s*\)/',
        $fetchBlock
    ),
    "the kind filter accepts 'transliteration' as well as 'translation' (and still requires isPrimary) — the exact line #320 reports as broken"
);
assertTrue(
    str_contains($fetchBlock, "'kind'") && (bool)preg_match('/\'kind\'\s*=>\s*\$kind/', $fetchBlock),
    "each stored row keeps its 'kind' value for the render loop to key off"
);
/* The pre-existing empty-line-id / empty-text guards must survive
   untouched — this change is additive, not a rewrite of the whole block. */
assertTrue(
    str_contains($fetchBlock, '$lid <= 0') && str_contains($fetchBlock, "trim(\$txt) === ''"),
    'the existing lineId/text emptiness guards are still in place'
);

/* ---------------------------------------------------------------------
 * PART 2 — the render loop (song.php): the two kinds render differently.
 * Located by the loop that walks the per-line translation/transliteration
 * list, up to its own closing endforeach.
 * --------------------------------------------------------------------- */
$found2 = preg_match(
    '/foreach\s*\(\s*\$lineTr\s+as\s+\$lt\s*\)\s*:(.*?)endforeach;/s',
    $src,
    $m2
);
assertTrue((bool)$found2, 'located the per-line translation/transliteration render loop in song.php');

if (!$found2) {
    echo "\n  $passed passed, $failed failed\n";
    exit(1);
}
$loopBody = $m2[1];

assertTrue(
    (bool)preg_match('/\$isTranslit\s*=\s*\(\s*\$lt\[\s*\'kind\'\s*\]\s*\?\?\s*\'translation\'\s*\)\s*===\s*\'transliteration\'/', $loopBody),
    "\$isTranslit is computed from \$lt['kind'] rather than assumed"
);

/* The class-building expression: shared base class unconditional, then
   EXACTLY ONE of `.lyric-line-transliteration` (translit) or `.fst-italic`
   (translation) — never both, never neither. */
assertTrue(
    (bool)preg_match('/\'lyric-line-translation\s+small\s+text-muted\s+mb-1\s+d-none\'/', $loopBody),
    "the literal lyric-line-translation class is present unconditionally (both kinds, required for the shared toggle + song-markup.js's insertNoteAfterLine())"
);
assertTrue(
    (bool)preg_match(
        '/\$isTranslit\s*\?\s*\'\s*lyric-line-transliteration\'\s*:\s*\'\s*fst-italic\'/',
        $loopBody
    ),
    "a transliteration row gets .lyric-line-transliteration and a translation row gets .fst-italic — mutually exclusive, driven by \$isTranslit"
);
assertTrue(
    !(bool)preg_match('/lyric-line-transliteration[^"\']*fst-italic|fst-italic[^"\']*lyric-line-transliteration/', $loopBody),
    'a transliteration row never also carries fst-italic (the two must not look the same)'
);

/* data-line-translation-for must stay on the ONE <p> both kinds share — the
   shared toggle keys off it, and song-markup.js's insertNoteAfterLine()
   matches it to decide where a note belongs. There is only one such
   attribute per iteration (one <p>, not a kind-branch producing two), so
   "appears exactly once, with the right value" is the whole contract. */
assertTrue(
    (bool)preg_match('/data-line-translation-for="<\?=\s*\$lineId\s*\?>"/', $loopBody),
    'data-line-translation-for="<?= $lineId ?>" is present on the row both kinds share'
);
assertTrue(
    substr_count($loopBody, 'data-line-translation-for=') === 1,
    'data-line-translation-for appears exactly once in the loop body (one row, one anchor, not duplicated per branch)'
);

assertTrue(
    (bool)preg_match('/data-line-translation-kind="transliteration"/', $loopBody),
    'a transliteration row carries data-line-translation-kind="transliteration" for anyone who needs to key off kind explicitly'
);
assertTrue(
    (bool)preg_match(
        '/if\s*\(\s*\$isTranslit\s*\)\s*:\s*\?>\s*<span class="visually-hidden">Romanized:\s*<\/span>\s*<\?php\s+endif;/',
        $loopBody
    ),
    'a transliteration row carries a visually-hidden "Romanized:" lead-in, so a screen reader hears the same distinction a sighted reader sees'
);
/* The visually-hidden lead-in must be TIED to $isTranslit, not unconditional
   — a translation row must not suddenly also announce "Romanized:". Counts
   only the actual <span> markup (not the doc-comment above it, which also
   names "Romanized:" in prose). */
assertTrue(
    substr_count($loopBody, '<span class="visually-hidden">Romanized:') === 1,
    'the visually-hidden <span>Romanized:</span> markup appears exactly once in the loop body, inside the $isTranslit-gated branch only'
);

/* ---------------------------------------------------------------------
 * PART 3 — CSS (app.css): the transliteration look is genuinely different
 * from the translation look, in the one dimension that matters most —
 * italic vs upright, since italic is this app's established "this is a
 * translated MEANING" cue.
 * --------------------------------------------------------------------- */
$foundCss = preg_match(
    '/\.lyric-line-transliteration\s*\{([^}]*)\}/s',
    $css,
    $m3
);
assertTrue((bool)$foundCss, 'app.css defines a .lyric-line-transliteration rule');

if ($foundCss) {
    $rule = $m3[1];
    assertTrue(
        (bool)preg_match('/font-style\s*:\s*normal/', $rule),
        '.lyric-line-transliteration is explicitly upright (font-style: normal), never inheriting italic'
    );
    assertTrue(
        !(bool)preg_match('/font-style\s*:\s*italic/', $rule),
        'the CSS rule does not set font-style: italic — that is the translation row\'s own cue, not this one\'s'
    );
    assertTrue(
        (bool)preg_match('/letter-spacing|border-left/', $rule),
        '.lyric-line-transliteration carries at least one further distinguishing property (letter-spacing and/or a border accent), not just font-style alone'
    );
} else {
    echo "\n  $passed passed, $failed failed\n";
    exit(1);
}

echo "\n  ----------------------------------------\n";
echo "  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
