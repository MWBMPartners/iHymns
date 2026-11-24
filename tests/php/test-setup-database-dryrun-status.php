<?php

declare(strict_types=1);

/**
 * iHymns — Database Setup: a dry run must not be reported as an error
 * =====================================================================
 *
 * ELI5
 * ----
 * Some "run once" data cards (for example "Blank old personal links") start in
 * DRY-RUN mode: they only count what they WOULD change and change nothing. The
 * page then checks "is this card still pending?", sees "yes" (of course, nothing
 * was changed) and used to show a red "Error" badge. A correct dry run looked
 * broken. The single-run path must skip that check for a dry run of a card that
 * is marked `dryRunnable`, and only check runs that were meant to apply.
 *
 * Mutation protocol (rule #34): each assertion is also run against a broken copy
 * of the real source to prove it can fail.
 *
 *   php tests/php/test-setup-database-dryrun-status.php
 */

$root = dirname(__DIR__, 2);
$src = (string)file_get_contents($root . '/appWeb/public_html/manage/setup-database.php');

$passed = 0;
$failed = 0;
function check(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ✅ {$name}\n"; return; }
    $failed++;
    echo "  ❌ {$name}\n";
}

/* Returns the problems in one copy of the source (empty = fine). */
function dryRunProblems(string $text): array
{
    $problems = [];
    $flag = strpos($text, '$singleWasDryRun = ');
    $verify = strpos($text, '_migVerify_stillPending($singleConn, $singleProbe)');
    if ($flag === false) {
        $problems[] = 'the single-run path never works out whether the run was a dry run';
        return $problems;
    }
    $line = (string)substr($text, $flag, 200);
    if (!str_contains($line, '$migrationDryRunnable[$action]')) {
        $problems[] = 'the dry-run flag is not derived from the dryRunnable registry list';
    }
    if (!str_contains($line, "\$_GET['confirm']")) {
        $problems[] = 'the dry-run flag ignores confirm=1';
    }
    if ($verify === false || $verify < $flag) {
        $problems[] = 'the still-pending check runs before (or without) the dry-run flag';
    } else {
        $between = (string)substr($text, $flag, $verify - $flag);
        if (!preg_match('/if\s*\(\s*\$singleWasDryRun\s*\)/', $between)
            || !str_contains($between, 'elseif')) {
            $problems[] = 'a dry run is not kept out of the still-pending (error) branch';
        }
    }
    return $problems;
}

check('the real source treats a dry run of a dryRunnable card as not-an-error', dryRunProblems($src) === []);

$broken1 = str_replace('$singleWasDryRun = !empty($migrationDryRunnable[$action])', '$singleWasDryRun = !empty($somethingElse[$action])', $src);
check('self-test: dropping the dryRunnable list is caught', dryRunProblems($broken1) !== []);

$broken2 = str_replace("(\$_GET['confirm'] ?? '') !== '1'", 'true', $src);
check('self-test: ignoring confirm=1 is caught', dryRunProblems($broken2) !== []);

$broken3 = str_replace('} elseif (_migVerify_stillPending($singleConn, $singleProbe) === true) {', '} if (_migVerify_stillPending($singleConn, $singleProbe) === true) {', $src);
check('self-test: letting a dry run reach the error branch is caught', dryRunProblems($broken3) !== []);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
