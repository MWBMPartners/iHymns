<?php

declare(strict_types=1);

/**
 * tools/ci/deprecation-report.php — turn the deprecation log into a pass/fail
 *
 * PLAIN-ENGLISH SUMMARY
 * Reads the log written by tools/ci/deprecation-trap.php, removes repeats, and
 * sorts each warning into one of two piles:
 *   - OUR code: printed as a GitHub `::error::` line and the script exits 1,
 *     so the check goes red.
 *   - THIRD-PARTY code (anything under appWeb/private_html/lib/, the vendored
 *     PDF library): printed as a `::warning::` and listed in the job summary,
 *     but it does NOT fail the check. We cannot fix someone else's code; we
 *     re-vendor it once its maker supports the new PHP.
 * A missing or empty log means "nothing was raised": exit 0.
 *
 *   php tools/ci/deprecation-report.php <log-file>
 *
 * @see tools/ci/deprecation-trap.php
 * @see https://docs.github.com/en/actions/using-workflows/workflow-commands-for-github-actions
 */

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "usage: php tools/ci/deprecation-report.php <log-file>\n");
    exit(2);
}

$ours = [];
$theirs = [];
if (is_file($path)) {
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $row = json_decode($raw, true);
        if (!is_array($row) || !isset($row['file'], $row['line'], $row['message'])) {
            continue;
        }
        $file = str_replace('\\', '/', (string)$row['file']);
        $key  = $file . '|' . $row['line'] . '|' . $row['message'];   /* repeats collapse to one */
        if (str_contains($file, 'appWeb/private_html/lib/')) {
            $theirs[$key] = [$file, $row['line'], $row['message']];
        } else {
            $ours[$key] = [$file, $row['line'], $row['message']];
        }
    }
}

$summary = [];
foreach ($ours as [$file, $line, $msg]) {
    echo "::error file={$file},line={$line}::PHP deprecation in our code ({$file}:{$line}): {$msg}\n";
    $summary[] = "- **our code** `{$file}:{$line}` — {$msg}";
}
foreach ($theirs as [$file, $line, $msg]) {
    echo "::warning file={$file},line={$line}::PHP deprecation in third-party code (not failing) ({$file}:{$line}): {$msg}\n";
    $summary[] = "- third-party `{$file}:{$line}` — {$msg}";
}
echo count($ours) . " deprecation(s) in our code, " . count($theirs) . " in third-party code.\n";

$sumFile = getenv('GITHUB_STEP_SUMMARY');
if (is_string($sumFile) && $sumFile !== '' && $summary !== []) {
    @file_put_contents($sumFile, "### PHP deprecations\n" . implode("\n", $summary) . "\n", FILE_APPEND);
}
exit($ours === [] ? 0 : 1);
