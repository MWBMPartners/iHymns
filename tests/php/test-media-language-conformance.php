<?php

declare(strict_types=1);

/**
 * iHymns — the shared language policy's own test cases, run here (#2137)
 *
 * ELI5: MWBM's apps share one written rule book for language codes (the
 * MWBM-MEDIA-LANG policy) and one PHP implementation of it. The rule book
 * comes with 268 worked examples ("given this, the answer must be that").
 * This test runs every one of those examples against iHymns' own copy of
 * the PHP code and its own copy of the reference data, and fails the build
 * if any example gives the wrong answer — so iHymns cannot quietly drift
 * away from the rules the other apps follow.
 *
 * DETAIL
 * ------
 * The examples, the reference data, the PHP implementation and its runner
 * are EXACT COPIES of the master files in MWBMPartners/MeedyaSuite-core,
 * kept identical by tools/media-lang/check_copies.py in CI:
 *
 *   tests/fixtures/bcp47-language-policy-v1.json            (the cases)
 *   appWeb/public_html/includes/vendor/media-language/bcp47-language-data-v1.json
 *   appWeb/public_html/includes/vendor/media-language/MediaLanguagePolicy.php
 *   appWeb/public_html/includes/vendor/media-language/tests/run-conformance.php
 *
 * So this file does not re-implement the checks. It runs the shared runner
 * as a separate PHP process (it ends with exit(), which would end this
 * process too), pointed at iHymns' copies, and then checks two things the
 * runner's exit status alone would not prove:
 *
 *   1. The runner said it ran EVERY case the fixture file holds, counted
 *      independently here from the JSON (rule #34: a runner that ran
 *      nothing and printed "0 failures" must not read as a pass).
 *   2. It reported zero failures and exited 0.
 *
 * Every section runs, not just the ones iHymns' profiles (text, canonical,
 * presentation) strictly need: the runner refuses to skip sections, and a
 * section passing costs nothing.
 *
 * NO DATABASE and NO PHP EXTENSION are needed — the shared code is plain
 * PHP 8.1+, so this runs the same on a laptop, in the test container and
 * in CI.
 *
 * MUTATION-PROVEN (rule #34): changing one expected answer in the fixture
 * copy turned this red; restoring it turned it green (see the commit body).
 *
 *   php tests/php/test-media-language-conformance.php
 *
 * Exit status 0 = every case passed, 1 = anything else.
 *
 * @see docs/standards/media-language-bcp47-policy.md  §8.1 (what a harness must do)
 * @see appWeb/public_html/includes/vendor/media-language/README.md
 */

$repoRoot  = dirname(__DIR__, 2);
$libDir    = $repoRoot . '/appWeb/public_html/includes/vendor/media-language';
$runner    = $libDir . '/tests/run-conformance.php';
$dataFile  = $libDir . '/bcp47-language-data-v1.json';
$fixtures  = $repoRoot . '/tests/fixtures/bcp47-language-policy-v1.json';

foreach ([$runner, $dataFile, $fixtures, $libDir . '/MediaLanguagePolicy.php'] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "FATAL: expected file missing: $f\n");
        exit(1);
    }
}

/* Count the cases ourselves, straight from the fixture JSON. Every top-level
   value that is a list is a section of cases; the others are metadata
   (policy name, versions, $schema). */
$decoded = json_decode((string)file_get_contents($fixtures), true);
if (!is_array($decoded)) {
    fwrite(STDERR, "FATAL: the fixture file is not valid JSON: $fixtures\n");
    exit(1);
}
$expectedCases = 0;
$sections      = 0;
foreach ($decoded as $key => $value) {
    if (is_array($value) && array_is_list($value)) {
        $sections++;
        $expectedCases += count($value);
    }
}
if ($expectedCases === 0 || $sections === 0) {
    fwrite(STDERR, "FATAL: found no cases in the fixture file — refusing to report a pass.\n");
    exit(1);
}

/* Run the shared runner in its own process. proc_open with an argument
   ARRAY (not a shell string) so no path is ever re-interpreted by a shell.
   https://www.php.net/manual/en/function.proc-open.php */
$cmd = [
    PHP_BINARY ?: 'php',
    $runner,
    '--fixtures', $fixtures,
    '--data', $dataFile,
];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
if (!is_resource($proc)) {
    fwrite(STDERR, "FATAL: could not start the conformance runner.\n");
    exit(1);
}
$stdout = (string)stream_get_contents($pipes[1]);
$stderr = (string)stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

echo $stdout;
if (trim($stderr) !== '') {
    fwrite(STDERR, $stderr);
}

$failures = [];

/* The runner's own summary line, e.g.
   "MWBM-MEDIA-LANG PHP conformance: 268/268 cases run (…), 0 failure(s)." */
if (!preg_match('/conformance: (\d+)\/(\d+) cases run .*?, (\d+) failure\(s\)\./s', $stdout, $m)) {
    $failures[] = 'the runner printed no summary line, so it cannot be shown to have run anything';
} else {
    [$_all, $ran, $declared, $failed] = [$m[0], (int)$m[1], (int)$m[2], (int)$m[3]];
    if ($ran !== $expectedCases) {
        $failures[] = "the runner ran {$ran} cases; the fixture file holds {$expectedCases}";
    }
    if ($declared !== $expectedCases) {
        $failures[] = "the runner counted {$declared} cases in the file; counted here: {$expectedCases}";
    }
    if ($failed !== 0) {
        $failures[] = "{$failed} case(s) gave the wrong answer (details above)";
    }
}
if ($exit !== 0) {
    $failures[] = "the runner exited with status {$exit}";
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: shared language-policy conformance (#2137):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "OK: all {$expectedCases} cases in {$sections} sections of the shared language policy pass against iHymns' copies.\n";
exit(0);
