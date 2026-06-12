<?php
/**
 * iHymns — per-line enrichment validators unit test (#1235 P3 / #1088)
 *
 * Exercises the PURE, DB-free validators in
 * appWeb/public_html/includes/line_enrichment.php (the shared translation /
 * annotation write layer the editor + native API both call). The DB-touching
 * upsert/delete functions need a live mysqli and are covered by manual / staging
 * verification; these pure guards are the CI-enforced contract.
 *
 *   php tests/php/test-line-enrichment.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

/* db_mysql.php is required by line_enrichment.php for bindParamSafe()/getDbMysqli;
   it defines functions only (no connection on include). */
require dirname(__DIR__, 2) . '/appWeb/public_html/includes/db_mysql.php';
require dirname(__DIR__, 2) . '/appWeb/public_html/includes/line_enrichment.php';

$passed = 0;
$failed = 0;
function assertEq($actual, $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) { echo "  PASS  $label\n"; $passed++; }
    else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}
function assertThrows(callable $fn, string $label): void
{
    global $passed, $failed;
    try { $fn(); echo "  FAIL  $label (no exception)\n"; $failed++; }
    catch (\Throwable $e) { echo "  PASS  $label\n"; $passed++; }
}

/* ==================================================================== */
/* Vocabulary allow-lists (rule #20 — VARCHAR validated, never ENUM)     */
/* ==================================================================== */
assertEq(lineEnrichmentNormalizeVocab('translation', LINE_TRANSLATION_KINDS), 'translation',
    'vocab: exact kind passes');
assertEq(lineEnrichmentNormalizeVocab('  Transliteration ', LINE_TRANSLATION_KINDS), 'transliteration',
    'vocab: trims + lower-cases');
assertEq(lineEnrichmentNormalizeVocab('furigana', LINE_TRANSLATION_KINDS), null,
    'vocab: unknown kind rejected (null)');
assertEq(lineEnrichmentNormalizeVocab('SCRIPTURE', LINE_ANNOTATION_TYPES), 'scripture',
    'vocab: annotation type case-folds');
assertEq(lineEnrichmentNormalizeVocab('markdown', LINE_ANNOTATION_BODY_FORMATS), 'markdown',
    'vocab: body format passes');
assertEq(lineEnrichmentNormalizeVocab('pending_review', LINE_ENRICHMENT_STATUSES), 'pending_review',
    'vocab: status passes');
assertEq(lineEnrichmentNormalizeVocab('published', LINE_ENRICHMENT_STATUSES), null,
    'vocab: unknown status rejected');

/* ==================================================================== */
/* Code-point offset validation (rule #21 — code points, end-exclusive)  */
/* ==================================================================== */
assertEq(lineEnrichmentValidateOffsets(null, null, 10, 10, true), [null, null],
    'offsets: both null = whole line(s)');
assertEq(lineEnrichmentValidateOffsets(0, 10, 10, 10, true), [0, 10],
    'offsets: full single-line span at the boundary is valid (end-exclusive == len)');
assertEq(lineEnrichmentValidateOffsets(3, 7, 10, 10, true), [3, 7],
    'offsets: a mid-line phrase span is valid');
assertThrows(static fn() => lineEnrichmentValidateOffsets(-1, 5, 10, 10, true),
    'offsets: negative start rejected');
assertThrows(static fn() => lineEnrichmentValidateOffsets(0, 11, 10, 10, true),
    'offsets: end beyond line length rejected');
assertThrows(static fn() => lineEnrichmentValidateOffsets(7, 3, 10, 10, true),
    'offsets: end before start on one line rejected');
/* On a MULTI-line span, end can precede start numerically (different lines). */
assertEq(lineEnrichmentValidateOffsets(8, 2, 10, 10, false), [8, 2],
    'offsets: multi-line span allows end<start (different lines)');

/* ==================================================================== */
/* BCP 47 language validation (fallback grammar — canonical validator     */
/* not loaded in this unit context; production uses _ietfBcp47Validate)   */
/* ==================================================================== */
assertEq(lineEnrichmentValidateLanguage('en'), 'en', 'lang: bare language');
assertEq(lineEnrichmentValidateLanguage('ja-Latn'), 'ja-Latn', 'lang: language+script');
assertEq(lineEnrichmentValidateLanguage('zh-Hans-CN'), 'zh-Hans-CN', 'lang: language+script+region');
assertEq(lineEnrichmentValidateLanguage('  fr  '), 'fr', 'lang: trims');
assertEq(lineEnrichmentValidateLanguage(''), null, 'lang: empty = null');
assertEq(lineEnrichmentValidateLanguage('not a tag!'), null, 'lang: malformed rejected');
/* A grammar-VALID but very long tag (each subtag <=8 chars) must be truncated to
   the 35-char column width — a malformed over-long subtag would be rejected, not
   capped, so build the input from legal 8-char subtags. */
assertEq(mb_strlen((string)lineEnrichmentValidateLanguage('en' . str_repeat('-aaaaaaaa', 6))), 35,
    'lang: a valid over-long tag is capped at the 35-char column width');

/* ==================================================================== */
/* Per-line LanguagesJson builder (#1235 P3 / #1253) — the shared builder  */
/* both editor save paths (api.php save_song, api2.php component_upsert) use */
/* ==================================================================== */
assertEq(lineEnrichmentBuildLanguagesJson(['', 'es', 'fr'], 3), '[null,"es","fr"]',
    'languagesJson: null-pads inheriting lines, keeps overrides');
assertEq(lineEnrichmentBuildLanguagesJson(['en'], 1), '["en"]',
    'languagesJson: single override');
assertEq(lineEnrichmentBuildLanguagesJson(['en-GB', 'zh-Hans-CN'], 2), '["en-GB","zh-Hans-CN"]',
    'languagesJson: full BCP47 tags pass through');
assertEq(lineEnrichmentBuildLanguagesJson(['', '', ''], 3), null,
    'languagesJson: all-inherit returns null (column stays NULL)');
assertEq(lineEnrichmentBuildLanguagesJson(['not a tag!', 'es'], 2), '[null,"es"]',
    'languagesJson: invalid tag becomes null (inherit), valid kept');
assertEq(lineEnrichmentBuildLanguagesJson(['es'], 0), null,
    'languagesJson: zero lineCount returns null');
assertEq(lineEnrichmentBuildLanguagesJson('es', 2), null,
    'languagesJson: non-array input returns null');
assertEq(lineEnrichmentBuildLanguagesJson(['es'], 3), '["es",null,null]',
    'languagesJson: pads to lineCount when the array is short');

echo "\n  ----------------------------------------\n";
echo "  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
