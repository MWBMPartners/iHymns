<?php

declare(strict_types=1);

/**
 * test-search-spaceless-script.php — #1908 Commit 5 space-less-script search arm
 * ================================================================================
 *
 * ELI5: MySQL's built-in FULLTEXT search finds words by looking for the gaps
 * between them. English/Cyrillic/Greek/Hangul/Arabic/Hebrew all put gaps
 * between words, so FULLTEXT works fine for them. Chinese, Japanese, Thai,
 * Lao, Khmer and Burmese are usually written with NO gaps at all, so
 * FULLTEXT can't tell where one word ends and the next begins and a 3+
 * character search in one of those scripts silently returns nothing. This
 * test proves two things: (a) the little "is this query in one of those
 * no-gap scripts?" detector (`ihymns_contains_spaceless_script()`) correctly
 * says yes/no for a representative sample of every script involved, and (b)
 * `SongData::searchSongs()`'s 3+-char branch actually asks that detector,
 * and asks it BEFORE it ever tries the FULLTEXT ladder — so a no-gap-script
 * query gets the substring fallback FIRST instead of silently falling
 * through to a search strategy that cannot find it.
 *
 * DETAIL — TWO PARTS (no DB required; both are pure source/string checks —
 * this mirrors the `test-print-pdf-batch.php` Part A static-scan shape and,
 * for the position assertion specifically, its A4 "the cap check appears
 * BEFORE the render call" idiom applied to a different pair of anchors):
 *
 *   PART (a) — FUNCTIONAL predicate truth table (no DB, no source scan):
 *     `ihymns_contains_spaceless_script()` returns TRUE for one query in
 *     each of the seven included scripts (Han, Katakana, Hiragana, Thai,
 *     Khmer, Myanmar, Lao), and FALSE for one query in each of the six
 *     DELIBERATELY EXCLUDED (space-delimited) scripts (Latin, Cyrillic,
 *     Greek, Hangul, Hebrew, Arabic) — see the function's own doc-block in
 *     title_normalize.php for why each side of that line sits where it does.
 *
 *   PART (b) — STATIC, comment-stripped source scan of SongData.php (via the
 *     real tokenizer, never a slash-star regex — same precedent
 *     `test-print-pdf-batch.php`/`test-print-one-renderer.php` use, since a
 *     naive regex can be fooled by a `//`/`/* ... *\/` INSIDE a string
 *     literal): `searchSongs()`'s body is extracted with BALANCED-BRACE
 *     matching (not a fixed-width window — rule #34, calibrated against the
 *     real source so it neither under- nor over-matches), and within it the
 *     3+-char `else` branch is extracted the same way. Two assertions on
 *     that else-branch text:
 *       b1. it calls `ihymns_contains_spaceless_script(`
 *       b2. that call's text position is BEFORE the first `_runFulltextSearch(`
 *           call's text position — i.e. the space-less-script LIKE arm is
 *           genuinely tried FIRST, not bolted on after the FULLTEXT ladder
 *           has already had its chance (which would defeat the whole point:
 *           FULLTEXT would already have returned an empty array for these
 *           queries, so trying it first and falling through to LIKE only
 *           on empty would still work by accident today, but is exactly the
 *           ordering a future edit could invert without any test noticing —
 *           this assertion is what makes that regression loud).
 *
 * MUTATION-PROVEN (rule #34) — both of the following were run this session,
 * watched RED, then reverted byte-identical (confirmed via `git diff` empty
 * afterwards) before this file was left in its final state:
 *   (a) temporarily removed `\p{Thai}` from the predicate's character class
 *       in title_normalize.php -> Part (a)'s Thai TRUE-case assertion went
 *       RED (the other five spaceless-script TRUE cases stayed green,
 *       proving the mutation was narrow and the test caught exactly it).
 *   (b) temporarily moved the `if (ihymns_contains_spaceless_script($query))`
 *       block in SongData.php's searchSongs() to AFTER the D2-hybrid
 *       FULLTEXT steps 1-2 (still inside the same `else` branch) -> Part
 *       (b)'s b2 position assertion went RED (b1 stayed green, since the
 *       call was still present — just relocated), proving the assertion
 *       tests ORDER and not merely PRESENCE.
 *
 *   php tests/php/test-search-spaceless-script.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/title_normalize.php  ihymns_contains_spaceless_script()
 * @see appWeb/public_html/includes/SongData.php          searchSongs() — the caller
 * @see tests/php/test-print-pdf-batch.php                 the comment-strip / balanced-brace / position-assert idioms this mirrors
 * @see .claude/unicode-nonlatin-1908-plan.md §5            the locked spec this test implements
 * @link https://www.php.net/manual/en/regexp.reference.unicode.php  PCRE \p{...} Unicode property classes
 */

$root           = dirname(__DIR__, 2);
$publicRoot     = $root . '/appWeb/public_html';
$titleNormPath  = $publicRoot . '/includes/title_normalize.php';
$songDataPath   = $publicRoot . '/includes/SongData.php';

$fail = 0;
function sss_ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') {
            echo "        $detail\n";
        }
    }
}

/** Strip comments via the real tokenizer (never a slash-star regex — see
 *  test-print-pdf-batch.php / test-print-one-renderer.php's doc-blocks for
 *  the false-positive class a naive regex hits on a comment-shaped string
 *  literal). Blanks comment bodies rather than deleting them so byte offsets
 *  used for the position assertion in Part (b) stay meaningful. */
function sss_stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]); // keep line numbers stable
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/**
 * Balanced-brace extraction of the block that opens at `$needleWithBrace`
 * (which MUST already include everything up to and including the opening
 * `{`). Returns null when the needle isn't found or the braces never close.
 * Identical idiom to `ppbExtractBracedBlock()` in test-print-pdf-batch.php.
 */
function sss_extractBracedBlock(string $src, string $needleWithBrace, int $searchFrom = 0): ?string
{
    $pos = strpos($src, $needleWithBrace, $searchFrom);
    if ($pos === false) {
        return null;
    }
    $bracePos = $pos + strlen($needleWithBrace) - 1; // the opening '{' itself
    $depth = 0;
    $len = strlen($src);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $bracePos + 1, $i - $bracePos - 1);
            }
        }
    }
    return null;
}

sss_ok('title_normalize.php exists', is_file($titleNormPath));
sss_ok('SongData.php exists', is_file($songDataPath));
if ($fail > 0) {
    fwrite(STDERR, "\nFATAL: one or more #1908 Commit 5 files are missing — cannot continue.\n");
    exit(1);
}

require_once $titleNormPath;

/* =============================================================================
 * PART (a) — ihymns_contains_spaceless_script() predicate truth table
 * ============================================================================= */

echo "Part (a) — ihymns_contains_spaceless_script() predicate truth table\n";

sss_ok('ihymns_contains_spaceless_script() is defined', function_exists('ihymns_contains_spaceless_script'));

/* TRUE cases — one query per INCLUDED (space-less) script. */
$trueCases = [
    'Han (Chinese/Japanese kanji)' => '耶稣爱我',   // "Jesus loves me"
    'Katakana (Japanese)'          => 'イエス',       // "Iesu" (Jesus)
    'Hiragana (Japanese)'          => 'ひらがな',     // "hiragana" itself
    'Thai'                          => 'พระเยซู',      // "Jesus"
    'Khmer (Cambodian)'            => 'ខ្មែរ',         // "Khmer"
    'Myanmar (Burmese)'            => 'မြန်မာ',        // "Myanmar"
    'Lao'                           => 'ລາວ',          // "Lao"
];
foreach ($trueCases as $label => $probe) {
    sss_ok(
        "TRUE:  {$label}  ({$probe})",
        ihymns_contains_spaceless_script($probe) === true,
        'ihymns_contains_spaceless_script(' . $probe . ') should be true'
    );
}

/* FALSE cases — one query per EXCLUDED (space-delimited) script. */
$falseCases = [
    'Latin (English)'   => 'Amazing',
    'Cyrillic (Russian)' => 'Иисус',       // "Jesus"
    'Greek'               => 'Αγάπη',       // "Love/Agape"
    'Hangul (Korean)'    => '예수',          // "Jesus"
    'Hebrew'              => 'שלום',         // "Shalom/Peace"
    'Arabic'              => 'العربية',      // "Arabic" (the language name)
];
foreach ($falseCases as $label => $probe) {
    sss_ok(
        "FALSE: {$label}  ({$probe})",
        ihymns_contains_spaceless_script($probe) === false,
        'ihymns_contains_spaceless_script(' . $probe . ') should be false'
    );
}

/* Sanity floor — the empty string and a plain ASCII sentence with spaces
   both stay false (no script leaks in from an unrelated code path). */
sss_ok('empty string is false', ihymns_contains_spaceless_script('') === false);
sss_ok('plain ASCII sentence with spaces is false', ihymns_contains_spaceless_script('Amazing Grace how sweet') === false);

/* Mixed Latin+CJK query — the predicate only needs ONE matching code point
   (the plan's §5 note: "Mixed Latin+CJK queries: LIKE first ... FULLTEXT
   loose-arm fallback can still catch the Latin tokens" — that behaviour
   depends on this predicate firing true for a mixed string). */
sss_ok('mixed Latin+Han string is true', ihymns_contains_spaceless_script('Amazing 耶稣') === true);

/* =============================================================================
 * PART (b) — static position assert: SongData::searchSongs()'s else branch
 * calls ihymns_contains_spaceless_script() BEFORE the FULLTEXT ladder.
 * ============================================================================= */

echo "\nPart (b) — SongData::searchSongs() else-branch static scan\n";

$songDataRaw = (string)file_get_contents($songDataPath);
$songDataSrc = sss_stripComments($songDataRaw);

/* Extract searchSongs()'s FULL body via balanced-brace matching. The
   parameter list uses `[]` array-literal defaults (never `{}`), so the
   FIRST `{` found after "function searchSongs(" is unambiguously the
   function's own opening brace — no risk of stopping early on a brace
   inside a default-value expression. */
$sigPos = strpos($songDataSrc, 'function searchSongs(');
sss_ok('located "function searchSongs(" in SongData.php', $sigPos !== false);

$funcBody = null;
if ($sigPos !== false) {
    $bracePos = strpos($songDataSrc, '{', $sigPos);
    sss_ok('found searchSongs()\'s opening brace', $bracePos !== false);
    if ($bracePos !== false) {
        // sss_extractBracedBlock wants the needle to END at the '{', so hand
        // it a 1-char needle anchored exactly at $bracePos.
        $funcBody = sss_extractBracedBlock($songDataSrc, '{', $bracePos);
    }
}
sss_ok('extracted searchSongs()\'s balanced-brace function body', $funcBody !== null);

/* Within the function body, extract the 3+-char `else` branch — the block
   that opens right after the `if (mb_strlen($query) < 3 || empty($tokens))`
   short-query gate. Searching for the literal "} else {" is safe here
   because it is bounded to the ALREADY-extracted searchSongs() body (rule
   #34 — a bounded window calibrated against the real source, not a blind
   whole-file scan), and the comment-stripped source preserves formatting
   outside of comments, so the "} else {" shape used by the current code
   round-trips through the stripper unchanged. */
$elseBody = null;
if ($funcBody !== null) {
    $elsePos = strpos($funcBody, '} else {');
    sss_ok('found the 3+-char "} else {" branch inside searchSongs()', $elsePos !== false);
    if ($elsePos !== false) {
        // The needle must end at the opening '{' of the else-block itself,
        // i.e. "} else {" (8 chars) — its last char IS that brace.
        $elseBody = sss_extractBracedBlock($funcBody, '} else {', $elsePos);
    }
}
sss_ok('extracted the else-branch\'s balanced-brace body', $elseBody !== null);

if ($elseBody !== null) {
    $predicatePos = strpos($elseBody, 'ihymns_contains_spaceless_script(');
    $fulltextPos  = strpos($elseBody, '_runFulltextSearch(');

    sss_ok(
        'b1. else branch calls ihymns_contains_spaceless_script(',
        $predicatePos !== false
    );
    sss_ok(
        'b1.1 else branch calls _runFulltextSearch( at least once (sanity floor for b2)',
        $fulltextPos !== false
    );
    sss_ok(
        'b2. ihymns_contains_spaceless_script( appears BEFORE the first _runFulltextSearch( call '
            . '— the space-less-script LIKE arm is tried FIRST, not bolted on after the FULLTEXT ladder',
        $predicatePos !== false && $fulltextPos !== false && $predicatePos < $fulltextPos,
        $predicatePos !== false && $fulltextPos !== false
            ? "predicate at offset {$predicatePos}, first _runFulltextSearch( at offset {$fulltextPos}"
            : '(one or both anchors missing — see b1/b1.1 above)'
    );

    /* Also confirm the LIKE-arm call itself is inside the guarded if — i.e.
       the predicate call and the _searchByLike( call it guards are close
       together and both precede the FULLTEXT ladder, not merely present
       somewhere in the branch. */
    $likeCallPos = strpos($elseBody, '_searchByLike(', $predicatePos !== false ? $predicatePos : 0);
    sss_ok(
        'the space-less-script arm actually calls _searchByLike( after the predicate check (rule #22 — reuses the existing helper, no forked query)',
        $predicatePos !== false && $likeCallPos !== false && $likeCallPos > $predicatePos
            && $fulltextPos !== false && $likeCallPos < $fulltextPos
    );
} else {
    sss_ok('b1. else branch calls ihymns_contains_spaceless_script(', false, 'else-branch body could not be extracted — see above');
    sss_ok('b2. ihymns_contains_spaceless_script( appears before _runFulltextSearch(', false, 'else-branch body could not be extracted — see above');
}

/* =============================================================================
 */
echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "FAILED: {$fail} assertion(s) did not pass.\n");
    exit(1);
}
echo "All assertions passed.\n";
exit(0);
