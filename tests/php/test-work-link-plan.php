<?php

declare(strict_types=1);

/**
 * iHymns — Work identity model: workLinkPlan() decision-table test (#1860 Phase 3)
 * ================================================================================
 *
 * ELI5
 * ----
 * `workLinkPlan()` (includes/work_admin.php) is the pure brain that decides
 * what database writes should happen when a song gets a CCLI and/or an ISWC
 * — mint a work, link to an existing one, re-home a work under its ISWC
 * parent, or flag a conflict. This file feeds it every combination the
 * design doc's decision table (§3.3) names and checks the exact op list it
 * hands back — no database involved, because the function itself never
 * touches one (`song_relocate.php`'s pure-decision-core discipline,
 * CLAUDE.md rule #34: the decision is CALLED in a test, not read out of the
 * source).
 *
 * MODEL: `tests/php/test-auto-link-hard-id.php`'s `check()` harness — exit
 * 1 on any failure, no DB, plain assertions.
 *
 * SECTIONS T1-T12 call `workLinkPlan()` directly against hand-built
 * `tblWorks` row fixtures. T13/T14 exercise ORDER-INDEPENDENCE and
 * IDEMPOTENCY by replaying `workLinkPlan()` through a small, PURE, in-test
 * "apply the ops to a graph" oracle (`applyPlan()`) — deliberately a
 * SEPARATE implementation from the production executor
 * (`_workAdminExecutePlan()` in work_admin.php), so this test is an
 * independent check on the plan's meaning, not a mirror of the code that
 * consumes it. T15 pins the ISWC-fold contract this file depends on. T16
 * is a tree-derived wiring scan (the `test-qr-cuercode.js` discipline)
 * banning a second inline write path or a second ISWC-shape regex.
 *
 * MUTATION-TESTED (rule #34) — every section's "mutation -> RED" was
 * actually performed during this build (edit source, re-run this file,
 * confirm the named assertion(s) failed, revert), transcript in the commit
 * body that lands alongside this file.
 *
 *   php tests/php/test-work-link-plan.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see .claude/ilyrics-internal-ids-work-model-plan.md §3.3/§3.4
 * @see appWeb/public_html/includes/work_admin.php
 * @see #1860
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/work_admin.php';
require_once __DIR__ . '/lib/dispatch_parser.php';

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

/** A tblWorks row fixture (the shape workLinkPlan() reads: Id, ParentWorkId, Iswc, Ccli, Title, Slug). */
function w(int $id, ?int $parentWorkId, ?string $iswc, ?string $ccli): array
{
    return [
        'Id'           => $id,
        'ParentWorkId' => $parentWorkId,
        'Iswc'         => $iswc,
        'Ccli'         => $ccli,
        'Title'        => "Work {$id}",
        'Slug'         => "work-{$id}",
    ];
}

/** True if any op in $ops matches every key/value in $want. Uses
 *  array_key_exists() rather than `??` — an explicit `null` value (e.g.
 *  parentId => null for a standalone create) must compare equal to a
 *  wanted `null`, but `??` treats a present-and-null key as "missing" and
 *  would wrongly report no match. */
function hasOp(array $ops, array $want): bool
{
    foreach ($ops as $op) {
        $match = true;
        foreach ($want as $k => $v) {
            if (!array_key_exists($k, $op) || $op[$k] !== $v) { $match = false; break; }
        }
        if ($match) { return true; }
    }
    return false;
}

/** Count of ops whose 'op' key equals $kind. */
function opCount(array $ops, string $kind): int
{
    return count(array_filter($ops, static fn(array $o): bool => $o['op'] === $kind));
}

$ISWC = 'T-000.000.001-1';
$CCLI = '1111111';

/* ============================================================================
 * T1 — Row 1: both empty.
 * Mutation performed: made row 1 emit a link op (early-returned a link_song
 * op before the both-empty check) -> both T1 assertions went RED -> reverted.
 * ========================================================================== */
$p = workLinkPlan(null, null, '', '', []);
check('T1: both empty -> ops === []', $p['ops'] === []);
check('T1: both empty -> conflict === null', $p['conflict'] === null);

/* ============================================================================
 * T2 — Row 2: ISWC only, none found.
 * Mutation performed: dropped the create_work op (return only the link op)
 * -> the create-op assertion went RED -> reverted.
 * ========================================================================== */
$p = workLinkPlan(null, null, '', $ISWC, []);
check('T2: creates the parent (ref=parent, correct iswc)', hasOp($p['ops'], ['op' => 'create_work', 'ref' => 'parent', 'iswc' => $ISWC]));
check('T2: links to the parent ref', hasOp($p['ops'], ['op' => 'link_song', 'targetRef' => 'parent']));
check('T2: exactly two ops', count($p['ops']) === 2);
check('T2: conflict === null', $p['conflict'] === null);

/* ============================================================================
 * T3 — Row 3: parent exists, not covered by a more-specific membership.
 * Mutation performed: emitted a create_work anyway alongside the link ->
 * the "zero create_work ops" assertion went RED -> reverted.
 * ========================================================================== */
$P = w(1, null, $ISWC, null);
$p = workLinkPlan($P, null, '', $ISWC, []);
check('T3: link only', hasOp($p['ops'], ['op' => 'link_song', 'targetId' => 1]));
check('T3: zero create_work ops', opCount($p['ops'], 'create_work') === 0);
check('T3: exactly one op', count($p['ops']) === 1);

/* T3b — Row 3, covered via a more-specific child membership. */
$membershipsCovered = [['WorkId' => 5, 'ParentWorkId' => 1]];
$p = workLinkPlan($P, null, '', $ISWC, $membershipsCovered);
check('T3b: covered by a child membership -> ops === []', $p['ops'] === []);

/* ============================================================================
 * T4 — Row 4: one hand-curated work carries BOTH ids.
 * Mutation performed: emitted a set_parent op for this row -> the "zero
 * set_parent" assertion went RED -> reverted.
 * ========================================================================== */
$PC = w(1, null, $ISWC, $CCLI);
$p = workLinkPlan($PC, $PC, $CCLI, $ISWC, []);
check('T4: links to the single work', hasOp($p['ops'], ['op' => 'link_song', 'targetId' => 1]));
check('T4: zero create_work', opCount($p['ops'], 'create_work') === 0);
check('T4: zero set_parent', opCount($p['ops'], 'set_parent') === 0);
check('T4: exactly one op', count($p['ops']) === 1);
/* Idempotent replay: already a direct member -> zero ops. */
$p2 = workLinkPlan($PC, $PC, $CCLI, $ISWC, [['WorkId' => 1, 'ParentWorkId' => null]]);
check('T4: already-member replay -> ops === []', $p2['ops'] === []);

/* ============================================================================
 * T5 — Row 5: C exists standalone, P exists -> re-home C under P.
 * Mutation performed: skipped the set_parent op entirely -> the set_parent
 * assertion went RED -> reverted.
 * ========================================================================== */
$P = w(1, null, $ISWC, null);
$C = w(2, null, null, $CCLI);
$p = workLinkPlan($P, $C, $CCLI, $ISWC, []);
check('T5: set_parent(C=2 -> P id=1)', hasOp($p['ops'], ['op' => 'set_parent', 'workId' => 2, 'parentId' => 1]));
check('T5: links to C', hasOp($p['ops'], ['op' => 'link_song', 'targetId' => 2]));
check('T5: zero create_work (P already existed)', opCount($p['ops'], 'create_work') === 0);

/* T5b — Row 5 with P === null: mint the parent first, parent by REF. */
$p = workLinkPlan(null, $C, $CCLI, $ISWC, []);
check('T5b: creates the parent (ref=parent)', hasOp($p['ops'], ['op' => 'create_work', 'ref' => 'parent', 'iswc' => $ISWC]));
check('T5b: set_parent by parentRef (not a bare parentId)', hasOp($p['ops'], ['op' => 'set_parent', 'workId' => 2, 'parentRef' => 'parent']));
check('T5b: set_parent parentId is null (parented by ref, not id)', hasOp($p['ops'], ['op' => 'set_parent', 'workId' => 2, 'parentRef' => 'parent', 'parentId' => null]));
check('T5b: links to C', hasOp($p['ops'], ['op' => 'link_song', 'targetId' => 2]));

/* ============================================================================
 * T6 — Row 6: correctly parented + already a member + no stale parent
 * membership -> the fully idempotent zero-write replay.
 * Mutation performed: emitted the link_song op unconditionally (ignored
 * $member()) -> the "ops === []" assertion went RED -> reverted.
 * ========================================================================== */
$P = w(1, null, $ISWC, null);
$C = w(2, 1, null, $CCLI);
$memberships = [['WorkId' => 2, 'ParentWorkId' => 1]];
$p = workLinkPlan($P, $C, $CCLI, $ISWC, $memberships);
check('T6: zero-write idempotent replay -> ops === []', $p['ops'] === []);
check('T6: conflict === null', $p['conflict'] === null);

/* Row 6, NOT yet a member: link + refinement (a stale direct-parent
   membership exists alongside the correctly-parented child). */
$membershipsStale = [['WorkId' => 1, 'ParentWorkId' => null], ['WorkId' => 2, 'ParentWorkId' => 1]];
$p = workLinkPlan($P, $C, $CCLI, $ISWC, [['WorkId' => 1, 'ParentWorkId' => null]]);
check('T6: refines away the stale direct-parent membership', hasOp($p['ops'], ['op' => 'unlink_song', 'workId' => 1]));
check('T6: links to the child', hasOp($p['ops'], ['op' => 'link_song', 'targetId' => 2]));

/* ============================================================================
 * T7 — Row 7: C parented under a DIFFERENT work (conservative conflict).
 * Mutation performed (a): re-homed C onto P anyway -> the "zero set_parent"
 * assertion went RED. Mutation performed (b): returned conflict:null ->
 * the "conflict !== null" assertion went RED. Both reverted.
 * ========================================================================== */
$P = w(1, null, $ISWC, null);
$otherParent = w(9, null, null, null);   // a hand-curated suite, no ISWC of its own
$C = w(2, 9, null, $CCLI);
$p = workLinkPlan($P, $C, $CCLI, $ISWC, []);
check('T7: conflict !== null', $p['conflict'] !== null);
check('T7: zero set_parent (no re-home)', opCount($p['ops'], 'set_parent') === 0);
check('T7: zero create_work (no orphan ISWC parent)', opCount($p['ops'], 'create_work') === 0);
check('T7: still links to C', hasOp($p['ops'], ['op' => 'link_song', 'targetId' => 2]));

/* ============================================================================
 * T8 — Row 8: CCLI missing, ISWC parent exists -> create the child under it.
 * Mutation performed: dropped 'parentId' from the create_work op (standalone
 * instead of a child) -> the parentId assertion went RED -> reverted.
 * ========================================================================== */
$P = w(1, null, $ISWC, null);
$p = workLinkPlan($P, null, $CCLI, $ISWC, []);
check('T8: creates the child parented under P', hasOp($p['ops'], ['op' => 'create_work', 'ref' => 'child', 'ccli' => $CCLI, 'parentId' => 1]));
check('T8: links to the child ref', hasOp($p['ops'], ['op' => 'link_song', 'targetRef' => 'child']));

/* T12 — refinement D over row 8's inputs: the song is already a DIRECT
   member of P; the child arrives and should take over. */
$p = workLinkPlan($P, null, $CCLI, $ISWC, [['WorkId' => 1, 'ParentWorkId' => null]]);
check('T12: plan contains unlink_song(parent=1)', hasOp($p['ops'], ['op' => 'unlink_song', 'workId' => 1]));
check('T12: plan contains link_song(child)', hasOp($p['ops'], ['op' => 'link_song', 'targetRef' => 'child']));

/* ============================================================================
 * T9 — Row 9: neither exists -> mint both, link to the CHILD only.
 * Mutation performed: linked to the parent ref instead of the child ->
 * the targetRef assertion went RED -> reverted.
 * ========================================================================== */
$p = workLinkPlan(null, null, $CCLI, $ISWC, []);
check('T9: creates the parent', hasOp($p['ops'], ['op' => 'create_work', 'ref' => 'parent', 'iswc' => $ISWC]));
check('T9: creates the child, parented BY REF', hasOp($p['ops'], ['op' => 'create_work', 'ref' => 'child', 'ccli' => $CCLI, 'parentRef' => 'parent']));
check('T9: links to the CHILD', hasOp($p['ops'], ['op' => 'link_song', 'targetRef' => 'child']));
check('T9: exactly three ops', count($p['ops']) === 3);

/* ============================================================================
 * T10 — Row 10: CCLI only, none found -> standalone create.
 * Mutation performed: attached a parentRef to the create op (no longer
 * standalone) -> the standalone assertion went RED -> reverted.
 * ========================================================================== */
$p = workLinkPlan(null, null, $CCLI, '', []);
check('T10: standalone create (both parentRef and parentId null)', hasOp($p['ops'], ['op' => 'create_work', 'ref' => 'child', 'ccli' => $CCLI, 'parentRef' => null, 'parentId' => null]));
check('T10: links to the child ref', hasOp($p['ops'], ['op' => 'link_song', 'targetRef' => 'child']));

/* ============================================================================
 * T11 — Row 11: CCLI only, exists -> link only.
 * Mutation performed: emitted a create_work anyway -> the "link only"
 * assertion went RED -> reverted.
 * ========================================================================== */
$C = w(2, null, null, $CCLI);
$p = workLinkPlan(null, $C, $CCLI, '', []);
check('T11: link only', count($p['ops']) === 1 && hasOp($p['ops'], ['op' => 'link_song', 'targetId' => 2]));

/* ============================================================================
 * T13 — ORDER-INDEPENDENCE. Three entry sequences via the in-test applyPlan()
 * oracle converge to the identical graph (works, parent edges, memberships).
 * Mutation performed: re-introduced "adopt first CCLI onto the ISWC parent"
 * for the both-at-once sequence (B) by writing the Ccli straight onto the
 * matched P row instead of minting a child -> sequences A and B diverged
 * (T13's works/edges/members assertions went RED) -> reverted.
 * ========================================================================== */

/**
 * PURE, test-local executor over an associative graph:
 *   ['works' => [id => row], 'memberships' => [songId => [workId, ...]], 'nextId' => int]
 * Deliberately a SEPARATE implementation from work_admin.php's
 * `_workAdminExecutePlan()` — an oracle independent of the production code.
 */
function applyPlan(array $graph, array $plan, string $songId): array
{
    $works       = $graph['works'];
    $memberships = $graph['memberships'];
    $nextId      = $graph['nextId'];
    $refIds      = ['parent' => null, 'child' => null];

    $resolve = static function (?int $id, ?string $ref) use (&$refIds): ?int {
        if ($id !== null) { return $id; }
        return $ref !== null ? ($refIds[$ref] ?? null) : null;
    };

    foreach ($plan['ops'] as $op) {
        switch ($op['op']) {
            case 'create_work': {
                $ref   = $op['ref'];
                $newId = $nextId++;
                if ($ref === 'parent') {
                    $works[$newId] = ['Id' => $newId, 'ParentWorkId' => null, 'Iswc' => $op['iswc'], 'Ccli' => null];
                } else {
                    $parentId = $resolve($op['parentId'] ?? null, $op['parentRef'] ?? null);
                    $works[$newId] = ['Id' => $newId, 'ParentWorkId' => $parentId, 'Iswc' => null, 'Ccli' => $op['ccli']];
                }
                $refIds[$ref] = $newId;
                break;
            }
            case 'set_parent': {
                $workId   = (int)$op['workId'];
                $parentId = $resolve($op['parentId'] ?? null, $op['parentRef'] ?? null);
                $works[$workId]['ParentWorkId'] = $parentId;
                break;
            }
            case 'link_song': {
                $targetId = $resolve($op['targetId'] ?? null, $op['targetRef'] ?? null);
                if ($targetId !== null) {
                    if (!isset($memberships[$songId])) { $memberships[$songId] = []; }
                    if (!in_array($targetId, $memberships[$songId], true)) {
                        $memberships[$songId][] = $targetId;
                    }
                }
                break;
            }
            case 'unlink_song': {
                $workId = (int)$op['workId'];
                if (isset($memberships[$songId])) {
                    $memberships[$songId] = array_values(array_filter(
                        $memberships[$songId],
                        static fn(int $id): bool => $id !== $workId
                    ));
                }
                break;
            }
        }
    }

    return ['works' => $works, 'memberships' => $memberships, 'nextId' => $nextId];
}

function findByIswcInGraph(array $graph, string $iswc): ?array
{
    if ($iswc === '') { return null; }
    foreach ($graph['works'] as $w) {
        if (($w['Iswc'] ?? null) === $iswc) { return $w; }
    }
    return null;
}

function findByCcliInGraph(array $graph, string $ccli): ?array
{
    if ($ccli === '') { return null; }
    foreach ($graph['works'] as $w) {
        if (($w['Ccli'] ?? null) === $ccli) { return $w; }
    }
    return null;
}

function membershipRowsInGraph(array $graph, string $songId): array
{
    $rows = [];
    foreach (($graph['memberships'][$songId] ?? []) as $workId) {
        $rows[] = ['WorkId' => $workId, 'ParentWorkId' => $graph['works'][$workId]['ParentWorkId'] ?? null];
    }
    return $rows;
}

/** One "save" — plan + apply — mirroring what workFindOrLinkByIdentifier() does per call. */
function runSave(array $graph, string $songId, string $ccli, string $iswc): array
{
    $byIswc = findByIswcInGraph($graph, $iswc);
    $byCcli = findByCcliInGraph($graph, $ccli);
    $memberships = membershipRowsInGraph($graph, $songId);
    $plan = workLinkPlan($byIswc, $byCcli, $ccli, $iswc, $memberships);
    return applyPlan($graph, $plan, $songId);
}

/** Normalise a graph by IDENTIFIER, not by auto-incremented Id — so graphs
 *  built from independent sequences (different mint order) compare equal. */
function normalizeGraph(array $graph, string $songId): array
{
    $keyOf = static function (int $id) use ($graph): string {
        $w = $graph['works'][$id];
        if (!empty($w['Iswc'])) { return 'iswc:' . $w['Iswc']; }
        if (!empty($w['Ccli'])) { return 'ccli:' . $w['Ccli']; }
        return 'id:' . $id;
    };
    $works = [];
    $edges = [];
    foreach (array_keys($graph['works']) as $id) {
        $k = $keyOf($id);
        $works[] = $k;
        $parentId = $graph['works'][$id]['ParentWorkId'] ?? null;
        $edges[$k] = $parentId !== null ? $keyOf($parentId) : null;
    }
    sort($works);
    ksort($edges);
    $members = array_map($keyOf, $graph['memberships'][$songId] ?? []);
    sort($members);
    return ['works' => $works, 'edges' => $edges, 'members' => $members];
}

$songId = 'TEST-1';
$emptyGraph = ['works' => [], 'memberships' => [], 'nextId' => 1];

/* Sequence A: CCLI-only save, then the ISWC+CCLI save. */
$gA = runSave($emptyGraph, $songId, $CCLI, '');
$gA = runSave($gA, $songId, $CCLI, $ISWC);

/* Sequence B: ISWC+CCLI in one save. */
$gB = runSave($emptyGraph, $songId, $CCLI, $ISWC);

/* Sequence C: ISWC-only save, then the ISWC+CCLI save. */
$gC = runSave($emptyGraph, $songId, '', $ISWC);
$gC = runSave($gC, $songId, $CCLI, $ISWC);

$nA = normalizeGraph($gA, $songId);
$nB = normalizeGraph($gB, $songId);
$nC = normalizeGraph($gC, $songId);

check('T13: sequence A (CCLI, then both) == sequence B (both at once) — works', $nA['works'] === $nB['works']);
check('T13: sequence A == sequence B — parent edges', $nA['edges'] === $nB['edges']);
check('T13: sequence A == sequence B — memberships', $nA['members'] === $nB['members']);
check('T13: sequence B (both at once) == sequence C (ISWC, then both) — works', $nB['works'] === $nC['works']);
check('T13: sequence B == sequence C — parent edges', $nB['edges'] === $nC['edges']);
check('T13: sequence B == sequence C — memberships', $nB['members'] === $nC['members']);
check('T13: exactly one membership row (child only, no double-link)', count($nB['members']) === 1 && $nB['members'][0] === 'ccli:' . $CCLI);

/* ============================================================================
 * T14 — IDEMPOTENCY: re-run the FINAL (both-ids) save's plan against each of
 * T13's already-converged graphs — every one must produce ops === [].
 * Mutation performed: made row 6 always emit its link op regardless of
 * membership -> all three T14 assertions went RED -> reverted.
 * ========================================================================== */
foreach (['A' => $gA, 'B' => $gB, 'C' => $gC] as $label => $g) {
    $byIswc = findByIswcInGraph($g, $ISWC);
    $byCcli = findByCcliInGraph($g, $CCLI);
    $memberships = membershipRowsInGraph($g, $songId);
    $replay = workLinkPlan($byIswc, $byCcli, $CCLI, $ISWC, $memberships);
    check("T14: idempotent replay from converged sequence {$label} -> ops === []", $replay['ops'] === []);
}

/* ============================================================================
 * T15 — Malformed-ISWC contract pin: this file's normalisation depends on
 * ihymns_canonical_iswc() behaving exactly this way.
 * Behavioural pin — no mutation to perform (n/a per the build spec: this
 * section pins a CONTRACT this file relies on, not workLinkPlan() itself).
 * ========================================================================== */
check("T15: ihymns_canonical_iswc('garbage') === null", ihymns_canonical_iswc('garbage') === null);
check("T15: ihymns_canonical_iswc('T-345.246.800-1') === 'T-345.246.800-1'", ihymns_canonical_iswc('T-345.246.800-1') === 'T-345.246.800-1');

/* ============================================================================
 * T16 — TREE-DERIVED WIRING SCAN (the test-qr-cuercode.js discipline):
 * comment-stripped source, derived case lists, no typed file list.
 * ========================================================================== */

/** Strip PHP comments via the tokenizer (robust against `/*`/`//` inside
 *  strings, unlike a regex strip) — the same intent as test-deploy-paths.php's
 *  $loadCodeLines, implemented with token_get_all() instead. */
function stripPhpComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$repoRoot   = dirname(__DIR__, 2);
$api2File   = $repoRoot . '/appWeb/public_html/manage/editor/api2.php';
$workAdmin  = $repoRoot . '/appWeb/public_html/includes/work_admin.php';
$worksPage  = $repoRoot . '/appWeb/public_html/manage/works.php';

/* --- T16a: every song_work_* case in api2.php's $action switch references
       a work_admin.php helper — no second, inline write path. --- */

/* Vacuity/derivation self-test FIRST (rule #34's "wrong-but-green on first
   run" trap) — prove the pattern this scan relies on can actually match,
   independent of whatever api2.php currently contains. */
check('T16a: the song_work_* case-name pattern can match a fixture string', (bool)preg_match('/^song_work_[a-z_]+$/', 'song_work_autolink'));

$allActionCases = dispatchParserCaseTokens($api2File, '$action');
check('T16a: derived at least one case from api2.php\'s $action switch (vacuity check)', count($allActionCases) > 0);

$apiToks  = dispatchParserTokens($api2File);
$apiLines = explode("\n", (string)file_get_contents($api2File));

$workCases = [];
foreach ($allActionCases as $i => $c) {
    if (!preg_match('/^song_work_[a-z_]+$/', $c['name'])) { continue; }
    $startLine = $apiToks[$c['index']][2] ?? null;
    if ($startLine === null) { continue; }
    $endLine = isset($allActionCases[$i + 1]) ? ($apiToks[$allActionCases[$i + 1]['index']][2] ?? count($apiLines)) : count($apiLines);
    $block = implode("\n", array_slice($apiLines, $startLine - 1, max(1, $endLine - $startLine)));
    $workCases[$c['name']] = stripPhpComments("<?php\n" . $block);
}

check('T16a: at least two song_work_* cases were derived (song_work_autolink + song_work_set)', count($workCases) >= 2);

$workAdminHelperPattern = '/\b(workFindOrLinkByIdentifier|workLinkSongRow|workUnlinkSongRow|workExists|workSnapshot|workFindOrCreateByTitle|workAdminReady)\s*\(/';
foreach ($workCases as $name => $block) {
    check("T16a: case '{$name}' references a work_admin.php helper (no inline INSERT/DELETE fork)", (bool)preg_match($workAdminHelperPattern, $block));
}

/* --- T16b: work_admin.php reuses the ONE ISWC/CCLI fold, never re-forks it. --- */
$workAdminSrc = stripPhpComments((string)file_get_contents($workAdmin));
check('T16b: work_admin.php references ihymns_canonical_iswc(', str_contains($workAdminSrc, 'ihymns_canonical_iswc('));
check('T16b: work_admin.php references ihymns_canonical_ccli(', str_contains($workAdminSrc, 'ihymns_canonical_ccli('));
check(
    'T16b: work_admin.php contains NO second ISWC shape-regex fingerprint (T-?\\d{3})',
    !preg_match('/T-\?\\\\d\{3\}/', $workAdminSrc)
);

/* --- T16c: manage/works.php's $slugFor delegates to the shared fold. --- */
$worksPageSrc = stripPhpComments((string)file_get_contents($worksPage));
check('T16c: manage/works.php references workSlugify(', str_contains($worksPageSrc, 'workSlugify('));

/* ---------------------------------------------------------------------- */
if ($failures === 0) {
    echo "\nAll workLinkPlan() assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
exit(1);
