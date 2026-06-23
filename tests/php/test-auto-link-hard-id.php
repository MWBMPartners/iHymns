<?php

declare(strict_types=1);

/**
 * iHymns — Hard-key counterpart auto-promoter test (#1125)
 *
 * Exercises the PURE planning step autoLinkPlanGroups() (no DB) against the
 * eligibility rules that matter: cross-book requirement, same-official-songbook
 * collision drop, dismissal suppression, join-existing vs conflict-skip, and
 * idempotency (already-linked members produce no work).
 *
 *   php tests/php/test-auto-link-hard-id.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

require_once dirname(__DIR__, 2)
    . '/appWeb/public_html/includes/tools/auto-link-hard-id-counterparts.php';

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    if ($cond) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures++;
    }
}
/** Convenience: a candidate member. */
function m(string $sid, string $book, bool $official): array
{
    return ['sid' => $sid, 'book' => $book, 'official' => $official];
}

/* ---- 1. Two cross-book songs sharing an ISWC → one NEW group, both inserted. ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'iswc', 'members' => [m('MP-1', 'MP', true), m('CH-2', 'CH', true)]]],
    [], []
);
check('1 cross-book pair → 1 decision', count($p['decisions']) === 1);
check('1 target=new', ($p['decisions'][0]['target'] ?? null) === 'new');
check('1 inserts both', ($p['decisions'][0]['insert'] ?? []) == ['MP-1', 'CH-2']);

/* ---- 2. Idempotent: both already in the SAME group → no work (skippedLinked). ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'iswc', 'members' => [m('MP-1', 'MP', true), m('CH-2', 'CH', true)]]],
    ['MP-1' => 7, 'CH-2' => 7], []
);
check('2 already-linked → 0 decisions', count($p['decisions']) === 0);
check('2 counted skippedLinked', $p['counts']['skippedLinked'] === 1);

/* ---- 3. Same OFFICIAL songbook, two members → not a counterpart (both dropped). ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'ccli', 'members' => [m('MP-1', 'MP', true), m('MP-9', 'MP', true)]]],
    [], []
);
check('3 same-official → 0 decisions', count($p['decisions']) === 0);
check('3 counted skippedNotCounterpart', $p['counts']['skippedNotCounterpart'] === 1);

/* ---- 4. Same NON-official book (no cross-book span) → skippedNotCounterpart. ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'isrc', 'members' => [m('MISC-1', 'MISC', false), m('MISC-2', 'MISC', false)]]],
    [], []
);
check('4 same non-official book → 0 decisions', count($p['decisions']) === 0);
check('4 counted skippedNotCounterpart', $p['counts']['skippedNotCounterpart'] === 1);

/* ---- 5. Dismissed pair → whole group skipped. ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'iswc', 'members' => [m('CH-2', 'CH', true), m('MP-1', 'MP', true)]]],
    [], ['CH-2|MP-1' => true]   /* canonical A<B: 'CH-2' < 'MP-1' */
);
check('5 dismissed → 0 decisions', count($p['decisions']) === 0);
check('5 counted skippedDismissed', $p['counts']['skippedDismissed'] === 1);

/* ---- 6. One member already in a manual group, one free → free JOINS the group. ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'iswc', 'members' => [m('MP-1', 'MP', true), m('CH-2', 'CH', true)]]],
    ['MP-1' => 5], []
);
check('6 join → 1 decision', count($p['decisions']) === 1);
check('6 target = existing group 5', ($p['decisions'][0]['target'] ?? null) === 5);
check('6 inserts only the free one', ($p['decisions'][0]['insert'] ?? []) == ['CH-2']);

/* ---- 7. Members in TWO different groups → conflict, never auto-merge. ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'iswc', 'members' => [m('MP-1', 'MP', true), m('CH-2', 'CH', true)]]],
    ['MP-1' => 5, 'CH-2' => 9], []
);
check('7 conflict → 0 decisions', count($p['decisions']) === 0);
check('7 counted skippedConflict', $p['counts']['skippedConflict'] === 1);

/* ---- 8. Four-member group: MP has two official (collision → dropped); the two
          clean cross-book survivors link. ---- */
$p = autoLinkPlanGroups(
    [['idType' => 'ccli', 'members' => [
        m('MP-1', 'MP', true), m('MP-9', 'MP', true),   /* same official book → both dropped */
        m('CH-2', 'CH', true), m('SD-3', 'SDAH', true), /* clean cross-book survivors */
    ]]],
    [], []
);
check('8 collision-drop → 1 decision', count($p['decisions']) === 1);
check('8 links only the two clean survivors',
    (($p['decisions'][0]['insert'] ?? []) == ['CH-2', 'SD-3']));

/* ---------------------------------------------------------------------- */
if ($failures === 0) {
    echo "\nAll auto-link planner assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
exit(1);
