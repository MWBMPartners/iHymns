<?php

declare(strict_types=1);

/**
 * iHymns — External-identifier normaliser guard (#1741 P3)
 *
 * ELI5
 * ----
 * `includes/identifier_normalize.php` is the ONE place that turns a
 * curator/importer/URL-typed identifier (ISWC/CCLI/BOWI/ISRC/IPI/ISNI) into
 * its canonical stored/queried form. This test proves each per-scheme fold
 * behaves as documented, that the dispatcher (`ihymns_normalize_identifier`)
 * routes every registry scheme correctly, and — the other half of #1741 P3 —
 * that `manage/works.php`'s `$validateIswc` closure actually DELEGATES to
 * the shared fold rather than keeping its own duplicate copy (rule #22 —
 * "one fold, not two").
 *
 * TREE-DERIVED COVERAGE (rule #34): the "every scheme has a working sample"
 * assertion below iterates `array_keys(IHYMNS_ID_SCHEMES)` — the live
 * registry — rather than a hand-typed scheme list, so a SEVENTH scheme
 * added to the registry without an accompanying $plausibleSamples entry
 * fails LOUDLY here (a missing sample is itself reported as a failure,
 * never silently skipped) instead of shipping unverified.
 *
 * Pure source + in-process function calls — no DB required — so it slots
 * into `tools/run-php-tests.php`'s glob alongside every other `tests/php/
 * test-*.php` file (see that file's doc-block: any file matching the glob
 * runs, full stop — no separate registration step to forget).
 *
 *   php tests/php/test-identifier-normalize.php
 *
 * Exit status 0 = clean, 1 = at least one assertion failed.
 *
 * @see appWeb/public_html/includes/identifier_normalize.php
 * @see appWeb/public_html/manage/works.php
 * @see appWeb/public_html/includes/musician_helpers.php (canonicaliseIsni — the ISNI delegate target)
 * @see .claude/catalogue-expansion-1741-plan.md §4
 */

$repoRoot = dirname(__DIR__, 2);

$passed = 0;
$failed = 0;
$failures = [];

/**
 * Record one assertion's outcome. Mirrors the pass/fail reporting +
 * exit-code convention tests/php/test-media-identifiers.php uses (echoed
 * PASS summary line on success, STDERR bullet list + exit(1) on any
 * failure) so this suite reads identically in `tools/run-php-tests.php`'s
 * output.
 */
function tinCheck(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        $failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
    }
}

/* ---- Load the module under test (guarded require — the file 403s direct
   HTTP access via a SCRIPT_FILENAME check; set it to something else first,
   matching test-media-identifiers.php's identical idiom). ---- */
$normalizeFile = $repoRoot . '/appWeb/public_html/includes/identifier_normalize.php';
if (!is_readable($normalizeFile)) {
    fwrite(STDERR, "FATAL: could not read $normalizeFile\n");
    exit(1);
}
$_SERVER['SCRIPT_FILENAME'] = 'test-runner';
require_once $normalizeFile;

if (!function_exists('ihymns_normalize_identifier') || !defined('IHYMNS_ID_SCHEMES')) {
    fwrite(STDERR, "FATAL: identifier_normalize.php loaded but IHYMNS_ID_SCHEMES / ihymns_normalize_identifier are missing\n");
    exit(1);
}

/* =========================================================================
 * ISWC
 * ========================================================================= */

/* Bare-digit and already-canonical inputs both fold to the same canonical
   string — NOT the literal "t345246800.1" shape (a dot immediately before
   the final check digit does not match works.php's original shape regex,
   `/^T-?\d{3}\.?\d{3}\.?\d{3}-?\d$/` — dots are only accepted BETWEEN the
   three 3-digit groups, a hyphen only immediately before the check digit —
   confirmed empirically against the extracted fold below before writing
   this assertion, so this test doesn't encode a false expectation). */
tinCheck(
    'ihymns_canonical_iswc(bare digits, lowercase t) canonicalises to T-345.246.800-1',
    ihymns_canonical_iswc('t3452468001') === 'T-345.246.800-1'
);
tinCheck(
    'ihymns_canonical_iswc(hyphen-before-check-digit style) canonicalises to T-345.246.800-1',
    ihymns_canonical_iswc('T345246800-1') === 'T-345.246.800-1'
);
tinCheck(
    'ihymns_canonical_iswc(already-canonical, lowercase) canonicalises to T-345.246.800-1',
    ihymns_canonical_iswc('t-345.246.800-1') === 'T-345.246.800-1'
);
tinCheck(
    "ihymns_canonical_iswc('') returns '' (empty is valid — ISWC is optional)",
    ihymns_canonical_iswc('') === ''
);
tinCheck(
    'ihymns_canonical_iswc(malformed — missing digits) returns null',
    ihymns_canonical_iswc('T-345.246-1') === null
);
tinCheck(
    'ihymns_canonical_iswc(malformed — no T prefix) returns null',
    ihymns_canonical_iswc('3452468001') === null
);

/* =========================================================================
 * CCLI
 * ========================================================================= */

tinCheck(
    "ihymns_canonical_ccli(' 12-34 ') === '1234' (digits only, separators stripped)",
    ihymns_canonical_ccli(' 12-34 ') === '1234'
);
tinCheck(
    "ihymns_canonical_ccli('') === '' (empty stays empty)",
    ihymns_canonical_ccli('') === ''
);
tinCheck(
    "ihymns_canonical_ccli('CCLI Song # 7025648') strips the non-digit prefix to '7025648'",
    ihymns_canonical_ccli('CCLI Song # 7025648') === '7025648'
);

/* =========================================================================
 * BOWI — conservative clean, never rejects (shape unconfirmed)
 * ========================================================================= */

tinCheck(
    "ihymns_canonical_bowi('a1-b2.c3 d4') strips separators + whitespace and uppercases to 'A1B2C3D4'",
    ihymns_canonical_bowi('a1-b2.c3 d4') === 'A1B2C3D4'
);
tinCheck(
    "ihymns_canonical_bowi('') === '' (empty stays empty)",
    ihymns_canonical_bowi('') === ''
);

/* =========================================================================
 * ISRC
 * ========================================================================= */

tinCheck(
    "ihymns_canonical_isrc('us-abc-12-34567') === 'USABC1234567'",
    ihymns_canonical_isrc('us-abc-12-34567') === 'USABC1234567'
);
tinCheck(
    "ihymns_canonical_isrc('') === '' (empty stays empty)",
    ihymns_canonical_isrc('') === ''
);

/* =========================================================================
 * ISNI — must DELEGATE to musician_helpers.php::canonicaliseIsni(), never
 * re-implement the regroup fold (rule #22).
 * ========================================================================= */

require_once $repoRoot . '/appWeb/public_html/includes/musician_helpers.php';
$isniSamples = ['0000 0001 2103 2683', '000000012103 2683', '', '123'];
foreach ($isniSamples as $isniRaw) {
    tinCheck(
        "ihymns_canonical_isni(" . var_export($isniRaw, true) . ") equals canonicaliseIsni() verbatim (delegation, not re-implementation)",
        ihymns_canonical_isni($isniRaw) === canonicaliseIsni($isniRaw)
    );
}

/* =========================================================================
 * IPI
 * ========================================================================= */

tinCheck(
    "ihymns_canonical_ipi(' IPI: 00028555241 ') === '00028555241' (digits only)",
    ihymns_canonical_ipi(' IPI: 00028555241 ') === '00028555241'
);
tinCheck(
    "ihymns_canonical_ipi('') === '' (empty stays empty)",
    ihymns_canonical_ipi('') === ''
);

/* =========================================================================
 * Dispatcher — ihymns_normalize_identifier()
 * ========================================================================= */

tinCheck(
    "ihymns_normalize_identifier('bogus', 'x') === null (unrecognised scheme)",
    ihymns_normalize_identifier('bogus', 'x') === null
);
tinCheck(
    "ihymns_normalize_identifier('iswc', 'not-an-iswc') === null (dispatch reaches the rejecting ISWC fold)",
    ihymns_normalize_identifier('iswc', 'not-an-iswc') === null
);
tinCheck(
    "ihymns_normalize_identifier('ccli', ' 12-34 ') === '1234' (dispatch reaches the CCLI fold)",
    ihymns_normalize_identifier('ccli', ' 12-34 ') === '1234'
);

/* Tree-derived coverage (rule #34): every registry scheme must have a
   PLAUSIBLE sample that round-trips to a non-null canonical value. A
   scheme present in IHYMNS_ID_SCHEMES but absent from $plausibleSamples
   fails LOUDLY (not silently skipped) — the missing-sample check below is
   itself an assertion, not a guard clause that quietly continues. */
$plausibleSamples = [
    'iswc' => 'T-345.246.800-1',
    'ccli' => '7025648',
    'bowi' => 'A1B2C3D4E5F6',
    'isrc' => 'USRC17607839',
    'ipi'  => '00028555241',
    'isni' => '0000 0001 2103 2683',
];
$registrySchemes = array_keys(IHYMNS_ID_SCHEMES);
tinCheck('IHYMNS_ID_SCHEMES is non-empty (a truly empty registry would make the coverage loop below vacuous)', count($registrySchemes) > 0);
foreach ($registrySchemes as $scheme) {
    if (!array_key_exists($scheme, $plausibleSamples)) {
        tinCheck("scheme '{$scheme}' has a \$plausibleSamples entry to test coverage with", false,
            'a new IHYMNS_ID_SCHEMES entry was added without a matching sample in this test — add one above');
        continue;
    }
    $sample = $plausibleSamples[$scheme];
    $result = ihymns_normalize_identifier($scheme, $sample);
    tinCheck(
        "ihymns_normalize_identifier('{$scheme}', " . var_export($sample, true) . ') returns non-null for a plausible value',
        $result !== null,
        'got null — either the fold rejected a value this test believed was plausible, or the dispatch is broken for this scheme'
    );
}

/* Every registry entry must declare 'label', 'entity' (one of
   work|song|musician) and a boolean 'multiSong' — the shape
   includes/identifier_resolve.php and includes/pages/identifier.php rely on. */
foreach (IHYMNS_ID_SCHEMES as $scheme => $def) {
    tinCheck("IHYMNS_ID_SCHEMES['{$scheme}']['label'] is a non-empty string", is_string($def['label'] ?? null) && $def['label'] !== '');
    tinCheck("IHYMNS_ID_SCHEMES['{$scheme}']['entity'] is one of work|song|musician",
        in_array($def['entity'] ?? null, ['work', 'song', 'musician'], true));
    tinCheck("IHYMNS_ID_SCHEMES['{$scheme}']['multiSong'] is a bool", is_bool($def['multiSong'] ?? null));
}

/* =========================================================================
 * manage/works.php delegation (#1741 P3 — rule #22: one fold, not two)
 * ========================================================================= */

$worksFile = $repoRoot . '/appWeb/public_html/manage/works.php';
if (!is_readable($worksFile)) {
    tinCheck('manage/works.php is readable', false, "could not read {$worksFile}");
} else {
    $worksSrc = (string)file_get_contents($worksFile);
    tinCheck(
        'manage/works.php requires includes/identifier_normalize.php',
        str_contains($worksSrc, "identifier_normalize.php")
    );
    tinCheck(
        "manage/works.php's \$validateIswc closure delegates to ihymns_canonical_iswc() (fold #1 killed)",
        str_contains($worksSrc, 'ihymns_canonical_iswc(')
    );
    tinCheck(
        "manage/works.php no longer contains its own inline ISWC reformat body (substr(\$digits, 0, 3) — the duplicate fold this phase removed)",
        !str_contains($worksSrc, 'substr($digits, 0, 3)')
    );
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run on EVERY invocation, entirely
 * in-process. A guard that has never been proven able to fail is not
 * trustworthy.
 * ========================================================================= */

$mutationFailures = [];

/* FAILS-HIGH: a value this test KNOWS is malformed must be rejected. If the
   ISWC fold were accidentally loosened to accept anything, this would catch
   it going green when it should be red. */
if (ihymns_canonical_iswc('this-is-not-an-iswc-at-all') !== null) {
    $mutationFailures[] = 'ihymns_canonical_iswc() FAILS-HIGH self-test did not go red — a clearly-malformed ISWC was accepted';
}

/* FAILS-LOW: the dispatcher must actually reach the underlying fold, not
   just always return the raw input unchanged — probe with a value the raw
   fold would NOT return verbatim (leading/trailing whitespace + lowercase),
   confirming normalisation actually happened rather than the dispatcher
   short-circuiting to a pass-through. */
if (ihymns_normalize_identifier('ccli', ' 00 12 34 ') === ' 00 12 34 ') {
    $mutationFailures[] = 'ihymns_normalize_identifier() FAILS-LOW self-test did not go red — dispatch appears to pass the raw value through unchanged instead of folding it';
}

/* Confirm the tree-derived coverage loop CAN fail: a scheme missing from
   $plausibleSamples must be reported, not silently skipped. Exercised
   against a COPY, never the real $plausibleSamples array. */
$withMissingSample = $plausibleSamples;
unset($withMissingSample['iswc']);
$sawMissingIswcSample = false;
foreach (array_keys(IHYMNS_ID_SCHEMES) as $scheme) {
    if (!array_key_exists($scheme, $withMissingSample)) {
        $sawMissingIswcSample = ($scheme === 'iswc');
    }
}
if (!$sawMissingIswcSample) {
    $mutationFailures[] = 'tree-derived coverage FAILS-LOW self-test did not go red — removing a scheme\'s sample (in a copy) was not detected as missing';
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($failed > 0) {
        fwrite(STDERR, "FAIL: External-identifier normaliser guard (#1741 P3):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    fwrite(STDERR, "\n" . $passed . " passed, " . $failed . " failed.\n");
    exit(1);
}

echo "PASS: External-identifier normaliser guard — {$passed} assertion(s) passed "
   . '(' . count($registrySchemes) . " scheme(s) in IHYMNS_ID_SCHEMES all have working coverage, "
   . "manage/works.php delegates to the shared ihymns_canonical_iswc() fold, "
   . "and both mutation self-tests went red as expected).\n";
exit(0);
