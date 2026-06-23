<?php

declare(strict_types=1);

/**
 * iHymns — Per-action content-gating matcher test (#1120)
 *
 * Exercises the PURE contentAccessActionApplies() matcher (no DB) — the rule
 * that decides whether a restriction's AppliesToAction governs a requested
 * action (display / print / export / translate, with 'all' + 'reproduce' umbrellas).
 *
 *   php tests/php/test-content-access-action.php
 *
 * Exit status 0 = all pass, 1 = at least one assertion failed.
 */

declare(ticks=1); // no-op; keeps the file CLI-friendly under older harnesses

/* content_access.php's direct-access guard keys on SCRIPT_FILENAME (= this test
   under CLI, not the include), so it passes; the file only defines functions +
   requires db_mysql/licences (no DB connection at include time). */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/content_access.php';

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

/* 'all' (and the legacy empty) governs every action. */
foreach (['display', 'print', 'export', 'translate'] as $a) {
    check("'all' governs $a", contentAccessActionApplies('all', $a));
    check("'' (legacy) governs $a", contentAccessActionApplies('', $a));
}

/* Exact match only. */
check("'export' governs export", contentAccessActionApplies('export', 'export'));
check("'export' does NOT govern display", !contentAccessActionApplies('export', 'display'));
check("'export' does NOT govern print", !contentAccessActionApplies('export', 'print'));
check("'display' governs display", contentAccessActionApplies('display', 'display'));
check("'display' does NOT govern export", !contentAccessActionApplies('display', 'export'));

/* 'reproduce' is the umbrella for non-display reproduction actions. */
check("'reproduce' governs print", contentAccessActionApplies('reproduce', 'print'));
check("'reproduce' governs export", contentAccessActionApplies('reproduce', 'export'));
check("'reproduce' governs translate", contentAccessActionApplies('reproduce', 'translate'));
check("'reproduce' does NOT govern display", !contentAccessActionApplies('reproduce', 'display'));

/* Case-insensitive + empty action defaults to display. */
check("'EXPORT' governs export (case-insensitive)", contentAccessActionApplies('EXPORT', 'export'));
check("'all' governs empty action (→display)", contentAccessActionApplies('all', ''));
check("'reproduce' does NOT govern empty action (→display)", !contentAccessActionApplies('reproduce', ''));

if ($failures === 0) {
    echo "\nAll content-access action-matcher assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
exit(1);
