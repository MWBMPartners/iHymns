<?php

declare(strict_types=1);

/**
 * iHymns — PHP Test Suite Runner
 * ==============================
 *
 * Runs every `tests/php/*.php` file under `php` and fails if any of them do.
 * This is the ONE list of "which PHP tests exist" — CI calls this script, so
 * a new test file cannot be added without being run.
 *
 * WHY THIS EXISTS (#1631 item 5, second instance)
 * ------------------------------------------------
 * `tools/run-node-tests.js` was written because `npm test` and CI's node step
 * hardcoded two DIFFERENT file lists, so 15 of 22 node suites ran in neither.
 * A glob fixed that.
 *
 * The PHP side had the identical disease and it went unnoticed at the time,
 * because only the node lists were being compared. `.github/workflows/test.yml`
 * enumerated PHP tests one `- name:` block at a time, and EIGHT files on disk
 * were never named in it:
 *
 *     test-auth-rate-limit.php          (48 assertions — login brute-force)
 *     test-service-mode-occurrence.php  (79 assertions — venue-local -> UTC)
 *     test-song-similarity.php
 *     test-songbook-render-parity.php
 *     test-bulk-songs-hydration.php
 *     test-musicxml-parser.php
 *     test-native-app-stores.php
 *     test-rate-limit-pairing.php       (new with #1636)
 *
 * All eight pass. They were not failing and not skipped — simply never
 * invoked, which is the worst state a test can be in: a failing test is a
 * signal, an unrun test is a false sense of coverage. The auth rate-limit
 * suite in particular is 48 assertions about brute-force protection that
 * nobody had run in CI.
 *
 * A hand-maintained list has a "forgot to add the new file" failure mode.
 * A glob does not.
 *
 * SCOPE: `tests/php/*.php` only (non-recursive). Fixtures under
 * `tests/php/fixtures/` are data, not tests, and the non-recursive glob
 * excludes them by construction. Node tests run separately via
 * `tools/run-node-tests.js`.
 *
 * Usage:
 *   php tools/run-php-tests.php
 *
 * No `php` on your machine? `tools/test-env/` holds a ready-made container
 * and a one-line `php` stand-in that runs everything inside it. See
 * `tools/test-env/README.md`.
 *
 * Exit status: 0 if every test file exits 0, 1 if any exits non-zero.
 *
 * READING THE RESULT
 * ------------------
 * The very last line this prints is always one of:
 *
 *     TEST RESULT: PASS (279 suites)
 *     TEST RESULT: FAIL (2 of 279 suites failed)
 *
 * (The numbers there are only to show the shape — the real ones come from
 * the run.)
 *
 * That line exists because the older ✅/❌/📊 summary block was repeatedly
 * misread — someone (human or automated) would scroll a long log, see a lot
 * of ticks, and report "tests pass" while suites were in fact failing. One
 * unmistakable sentence at the very bottom, in a fixed shape you can search
 * for with `grep 'TEST RESULT:'`, removes the judgement call. The older
 * summary lines are all still printed above it; this is purely an addition.
 */

$root    = dirname(__DIR__);
$testDir = $root . '/tests/php';

/**
 * Turn a failed suite's two output streams into ONE clearly labelled block.
 *
 * ELI5: a program can print in two places at once — the normal place
 * ("standard output") and the place reserved for complaints ("standard
 * error"). This keeps BOTH, each under its own heading, so nothing a
 * failing test said can go missing.
 *
 * WHY THIS FUNCTION EXISTS — the bug it fixes
 * -------------------------------------------
 * This runner used to keep only ONE of the two streams:
 *
 *     $failed[$name] = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
 *
 * i.e. "show the complaints if there are any, otherwise show the normal
 * output". That looks reasonable and is badly wrong here, because the test
 * suites in this project do not split their output that way. Many print
 * their per-assertion detail — the `FAIL  <which assertion, expected, got>`
 * lines, the only part anyone actually needs — to normal output, while
 * printing just a one-line tally to the complaints stream.
 *
 * A real, current example. `tests/php/test-ia-reconcile-guards.php` writes
 * 31 bytes to the complaints stream:
 *
 *     FAILED: 1 assertion(s) failed.
 *
 * and 6,353 bytes to normal output, one line of which is:
 *
 *     FAIL  #1908: a Greek-script candidate is now SCORABLE (...)
 *
 * Under the old line, the complaints stream was non-empty, so ALL 6,353
 * bytes were thrown away and the log showed only "FAILED: 1 assertion(s)
 * failed." — a failure with no clue as to which assertion, which sends the
 * reader off to re-run the suite by hand. That friction is exactly what
 * makes people stop reading test logs, and it is how this project ended up
 * with failing suites being reported as passing.
 *
 * Ordering inside each stream is untouched — each one is kept whole and in
 * the order the test printed it. Only leading and trailing blank lines are
 * removed, so the first line keeps its indentation. An empty stream is left
 * out entirely rather than printing an empty heading.
 */
function formatFailureOutput(string $stdout, string $stderr): string
{
    $blocks = [];

    /* trim(..., "\n") strips only newlines, not spaces — so a line that
       starts "  FAIL  ..." keeps its two-space indent and stays readable. */
    if (trim($stdout) !== '') {
        $blocks[] = "--- stdout ---\n" . trim($stdout, "\n");
    }
    if (trim($stderr) !== '') {
        $blocks[] = "--- stderr ---\n" . trim($stderr, "\n");
    }

    if (!$blocks) {
        return '(this suite printed nothing at all — it failed on its exit status alone. '
             . 'A silent non-zero exit usually means the PHP process died before it could '
             . 'say anything: a fatal error with display_errors off, or a kill signal.)';
    }

    return implode("\n\n", $blocks);
}

$files = glob($testDir . '/*.php') ?: [];
sort($files);

if (!$files) {
    fwrite(STDERR, "No PHP test files found in tests/php/ — that is almost certainly wrong.\n");
    /* Print the verdict here too (found by a cross-model review, 2026-09-08).
       See the matching comment in tools/run-node-tests.js: the path where
       nothing ran is the one a reader most needs told about, and a missing
       verdict line reads as "nothing to report". */
    echo "TEST RESULT: FAIL (no test files found)\n";
    exit(1);
}

$php     = PHP_BINARY ?: 'php';
$passed  = [];
$failed  = [];

foreach ($files as $file) {
    $name = basename($file);

    /* Run each test in its own process. Isolation is load-bearing: several
       suites declare helper functions with the same names (check(), test())
       and stub globals, so a single shared process would fatal on redeclare
       and mask every suite after the first. */
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$php, $file], $descriptors, $pipes, $root);

    if (!is_resource($proc)) {
        $failed[$name] = "could not spawn a process for {$name}";
        continue;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code === 0) {
        $passed[] = $name;
        echo "  ✅ {$name}\n";
    } else {
        /* Keep BOTH streams — a bare "it failed" sends the reader back to
           re-run it by hand, which is exactly the friction that makes people
           stop reading CI logs. See formatFailureOutput()'s comment above for
           why keeping only one of the two silently hid the useful half. */
        $failed[$name] = formatFailureOutput($stdout, $stderr);
        echo "  ❌ {$name} (exit {$code})\n";
    }
}

echo "\n";
echo '✅ Passed: ' . count($passed) . "\n";
echo '❌ Failed: ' . count($failed) . "\n";
echo '📊 Total:  ' . count($files) . "\n";

if ($failed) {
    fwrite(STDERR, "\n=== Failure output ===\n");
    foreach ($failed as $name => $output) {
        fwrite(STDERR, "\n=== {$name} ===\n{$output}\n");
    }
    /* Make sure the complaints above have actually reached the terminal
       before the verdict below is printed. Without this, a log that merges
       the two streams can show the verdict FIRST, in the middle of nowhere,
       which is the opposite of "unmistakable". */
    fflush(STDERR);

    /* THE VERDICT — always the last line, always this exact shape, always on
       normal output so `... | tail -1` and `grep 'TEST RESULT:'` both find
       it. See the "READING THE RESULT" note at the top of this file. */
    echo 'TEST RESULT: FAIL (' . count($failed) . ' of ' . count($files) . " suites failed)\n";
    exit(1);
}

echo "\nAll PHP test suites passed.\n";
echo 'TEST RESULT: PASS (' . count($files) . " suites)\n";
exit(0);
