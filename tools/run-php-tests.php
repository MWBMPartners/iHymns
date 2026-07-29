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
 * Exit status: 0 if every test file exits 0, 1 if any exits non-zero.
 */

$root    = dirname(__DIR__);
$testDir = $root . '/tests/php';

$files = glob($testDir . '/*.php') ?: [];
sort($files);

if (!$files) {
    fwrite(STDERR, "No PHP test files found in tests/php/ — that is almost certainly wrong.\n");
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
        /* Keep the output — a bare "it failed" sends the reader back to
           re-run it by hand, which is exactly the friction that makes people
           stop reading CI logs. */
        $failed[$name] = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
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
        fwrite(STDERR, "\n--- {$name} ---\n{$output}\n");
    }
    exit(1);
}

echo "\nAll PHP test suites passed.\n";
exit(0);
