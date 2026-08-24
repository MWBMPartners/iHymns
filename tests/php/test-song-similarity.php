<?php
/**
 * iHymns — Song similarity scorer unit test (#1215 / #1216)
 *
 * Exercises the shared includes/song_similarity.php scorer that the duplicate
 * /counterpart review page + the suggestion builder both use. Pure functions,
 * no DB / HTTP.
 *
 *   php tests/php/test-song-similarity.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_similarity.php';

/* -------------------------------------------------------------------- */
/* Test runner — one-line PASS/FAIL per assertion.                       */
/* -------------------------------------------------------------------- */
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
function assertTrue($actual, string $label): void
{
    assertEq((bool)$actual, true, $label);
}

/* Build a feature row the scorer understands. */
function feat(string $title, string $firstLine = '', string $authors = '', array $ids = []): array
{
    return [
        'normTitle'     => ihymns_sim_normalise($title),
        'normFirstLine' => ihymns_sim_normalise($firstLine),
        'authors'       => $authors,
        'isrc'          => $ids['isrc'] ?? '',
        'iswc'          => $ids['iswc'] ?? '',
        'ccli'          => $ids['ccli'] ?? '',
    ];
}

/* -------------------------------------------------------------------- */
/* Normalisation                                                         */
/* -------------------------------------------------------------------- */
assertEq(ihymns_sim_normalise('The Church’s One Foundation'), 'church s one foundation',
    'normalise: strips leading article; apostrophe → space (fuzzy fold)');
assertEq(ihymns_sim_normalise('Niño Jesús'), 'nino jesus',
    'normalise: folds diacritics to ASCII');
assertEq(ihymns_sim_normalise('Angels, from the Realms of Glory'), 'angels from the realms of glory',
    'normalise: drops punctuation; only the LEADING article is stripped (interior "the" kept)');

/* #1908 — non-Latin scripts now SURVIVE the fuzzy fold (previously every
   non-Latin title folded to '' via iconv's "????"-then-strip mechanism). */
assertEq(ihymns_sim_normalise('耶稣爱我'), '耶稣爱我',
    'normalise: #1908 — CJK title survives the fold unchanged (was \'\' pre-#1908)');
assertEq(ihymns_sim_normalise('Χριστός Ανέστη'), 'χριστος ανεστη',
    'normalise: #1908 — Greek folds like any other script (lowercase, accents stripped)');

/* -------------------------------------------------------------------- */
/* Text similarity                                                       */
/* -------------------------------------------------------------------- */
assertEq(ihymns_sim_text('abcdef', 'abcdef'), 1.0, 'text: identical strings → 1.0');
assertTrue(ihymns_sim_text('immanuel', 'emmanuel') > 0.7, 'text: one-letter drift scores high');
assertTrue(ihymns_sim_text('be thou my vision', 'be still my soul') < 0.7,
    'text: different hymns sharing a frame score below 0.7');

/* #1908 — non-Latin pairs are now fuzzy-SCORABLE (previously both sides
   folded to '' upstream and ihymns_sim_text('', '') short-circuits 0.0). */
assertTrue(ihymns_sim_text(ihymns_sim_normalise('耶稣爱我'), ihymns_sim_normalise('耶稣爱他')) > 0.0,
    'text: #1908 — near-identical CJK pair (one character differs) scores > 0');
assertEq(ihymns_sim_text(ihymns_sim_normalise('耶稣爱我'), ihymns_sim_normalise('耶稣爱我')), 1.0,
    'text: #1908 — identical CJK pair scores exactly 1.0');

/* #1908 — the byte-cap fix: a >255-BYTE CJK pair (255 mb_substr CHARS would
   have been 500-765+ bytes, overflowing levenshtein's byte ceiling and, on
   a PHP build where levenshtein() still signals overflow with -1, minting a
   similarity > 1.0 via `1.0 - (-1/max)`) must stay within [0, 1]. Built at
   300 chars (900 bytes at 3 bytes/char) to comfortably clear 255 bytes. */
$longCjkA = str_repeat('耶', 300);
$longCjkB = str_repeat('穌', 300);
assertTrue(strlen($longCjkA) > 255, 'text: #1908 fixture sanity — the raw CJK string exceeds 255 bytes');
$longScore = ihymns_sim_text($longCjkA, $longCjkB);
assertTrue($longScore >= 0.0 && $longScore <= 1.0,
    'text: #1908 — a >255-byte CJK pair returns a value in [0, 1] (the levenshtein -1 guard)');

/* -------------------------------------------------------------------- */
/* Authors Jaccard                                                       */
/* -------------------------------------------------------------------- */
assertEq(ihymns_sim_authors_jaccard('Charles Wesley', 'Charles Wesley'), 1.0,
    'authors: identical → 1.0');
assertEq(ihymns_sim_authors_jaccard('Charles Wesley', 'Isaac Watts'), 0.0,
    'authors: disjoint → 0.0');
assertTrue(ihymns_sim_authors_jaccard('Charles Wesley|John Wesley', 'Charles Wesley') > 0.0,
    'authors: partial overlap → > 0');

/* -------------------------------------------------------------------- */
/* Composite scoring                                                     */
/* -------------------------------------------------------------------- */
$same = ihymns_sim_score(
    feat('Angels from the realms of glory', 'Angels from the realms of glory', 'James Montgomery'),
    feat('Angels, from the Realms of Glory', 'Angels from the realms of glory', 'James Montgomery')
);
assertTrue($same['score'] >= 0.90, 'score: same hymn, typography drift → ≥ 0.90');
assertEq($same['confidence'], 'high', 'score: same hymn → high confidence');
assertEq($same['signal'], 'fuzzy', 'score: no hard id → fuzzy signal');

$diff = ihymns_sim_score(
    feat('Immanuel', 'O come O come Immanuel', 'John Mason Neale'),
    feat('Immanuel', 'From the squalor of a borrowed stable', 'Stuart Townend')
);
assertTrue($diff['score'] < 0.80, 'score: same title, different hymn → < 0.80 (lyrics/authors disambiguate)');
assertTrue($diff['confidence'] !== 'high', 'score: same title, different hymn → not high');

/* Hard-identifier override: a shared CCLI pins the match to certain even when
   the titles diverge (a retitled arrangement). */
$hard = ihymns_sim_score(
    feat('How Great Is Our God', 'The splendor of a King', 'Tomlin', ['ccli' => '4348399']),
    feat('Cuán Grande Es Dios', 'Resplandece el Rey', 'Tomlin', ['ccli' => '4348399'])
);
assertEq($hard['score'], 1.0, 'score: shared CCLI → score 1.0');
assertEq($hard['signal'], 'shared-ccli', 'score: shared CCLI → shared-ccli signal');
assertEq($hard['confidence'], 'high', 'score: shared CCLI → high confidence');

/* ISWC takes priority over CCLI/ISRC in the signal label. */
$prio = ihymns_sim_score(
    feat('A', '', '', ['iswc' => 'T-034.524.680-C', 'ccli' => '111', 'isrc' => 'X']),
    feat('B', '', '', ['iswc' => 'T-034.524.680-C', 'ccli' => '111', 'isrc' => 'X'])
);
assertEq($prio['signal'], 'shared-iswc', 'score: ISWC wins the signal-label priority');

/* Confidence tiering of the raw blend. */
assertEq(ihymns_sim_confidence_tier(0.95, 'fuzzy'), 'high',   'tier: 0.95 fuzzy → high');
assertEq(ihymns_sim_confidence_tier(0.85, 'fuzzy'), 'medium', 'tier: 0.85 fuzzy → medium');
assertEq(ihymns_sim_confidence_tier(0.70, 'fuzzy'), 'low',    'tier: 0.70 fuzzy → low');
assertEq(ihymns_sim_confidence_tier(0.10, 'shared-isrc'), 'high', 'tier: any score + hard id → high');

/* -------------------------------------------------------------------- */
echo "\n";
echo "song-similarity: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
