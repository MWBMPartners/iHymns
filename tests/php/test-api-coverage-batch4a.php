<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 4a" admin/curator registry CRUD (#1969)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.3/§9 asked for admin/curator
 * registry CRUD whose shared cores ALREADY EXIST — publishers (A1), works +
 * medley composition (A2), admin notification broadcast (A9), and extending
 * the EXISTING `admin_songbook_update` action with an `is_disabled` field
 * (A12, no new action). This guard checks, from the real dispatched source
 * (not a hand-typed belief), that the nine new `admin_publisher_*` /
 * `admin_work_*` / `admin_notification_send` actions genuinely exist as
 * `$action`-switch cases, are documented in `api-docs.yaml`, gate on the
 * SAME entitlement their sibling `manage/*.php` page gates on, and — the
 * part a mere "does the endpoint respond" smoke test cannot see — DELEGATE
 * to the shared core their sibling page already uses rather than forking a
 * second copy of the validation/write (rule #22): the publisher actions
 * call `includes/publisher_admin.php`; the work actions call
 * `workSlugify()`/`ihymns_canonical_iswc()`/`workExists()` (scalar fields)
 * and, for the medley action, the cycle-guarded `workMedleyReplace()` /
 * `workMedleyConstituents()` core in `includes/work_admin.php` (rule #45 —
 * NEVER a forked `INSERT`/`DELETE` against `tblWorkComponents`); the
 * notification action calls the ONE shared writer, `notifyUser()`
 * (`includes/notifications.php`, #1638). A12 is checked separately: the
 * EXISTING `admin_songbook_update` case now handles `is_disabled` via the
 * shared `songbookDisableReady()` gate (`includes/songbook_visibility.php`)
 * — and `admin_songbook_create` is asserted to still NOT mention it, since
 * A12 named only the update handler.
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * Same reasoning as `test-api-coverage-batch1.php` / `test-api-coverage-
 * batch3.php`: `api.php` has TWO switches (`$page` and `$action`), so a
 * bare `grep "case '...'"` cannot tell which one owns a label. Each case's
 * BODY is isolated by slicing the token stream between this case's label
 * and the next case's label in switch order (`tests/php/lib/
 * dispatch_parser.php`), so a delegation check against one action's body
 * cannot accidentally be satisfied by something that only appears in a
 * NEIGHBOURING action's body — this matters especially here, since
 * `admin_work_create`/`admin_work_update`/`admin_work_medley_replace` sit
 * next to each other and all reference `tblWorkComponents`-adjacent
 * identifiers.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyContains()`/
 * `caseBodyFor()` are proven, against tiny in-memory token-shaped fixtures
 * assembled with PHP's own `token_get_all()`, to both find a marker that
 * is there AND fail to find one that is not, before the real assertions
 * below are trusted. (Duplicated locally rather than imported — the
 * sibling `test-api-coverage-batch1.php` / `test-api-coverage-batch3.php`
 * define the SAME two tiny helpers locally rather than growing the shared
 * `dispatch_parser.php` library; this file follows that same precedent
 * rather than introducing a fourth shape.)
 *
 *   php tests/php/test-api-coverage-batch4a.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.3/§9   the plan this implements
 * @see appWeb/public_html/api.php                     the nine new cases + the extended admin_songbook_update
 * @see appWeb/public_html/includes/publisher_admin.php the publisher write cores (A1)
 * @see appWeb/public_html/includes/work_admin.php      workSlugify()/workExists()/workMedley*() (A2)
 * @see appWeb/public_html/includes/notifications.php   notifyUser() (A9)
 * @see appWeb/public_html/includes/songbook_visibility.php songbookDisableReady() (A12)
 * @see appWeb/public_html/manage/publishers.php        the page manage_publishers-gates against
 * @see appWeb/public_html/manage/works.php             the page manage_works-gates against
 * @see appWeb/public_html/manage/notifications.php     the page manage_notifications-gates against
 * @see tests/php/test-api-coverage-batch1.php           the sibling guard this mirrors
 * @see tests/php/test-api-coverage-batch3.php           the sibling guard this mirrors
 * @see #1969
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo        = dirname(__DIR__, 2);
$api         = $repo . '/appWeb/public_html/api.php';
$publisherAdminSrc = (string)file_get_contents($repo . '/appWeb/public_html/includes/publisher_admin.php');
$workAdminSrc       = (string)file_get_contents($repo . '/appWeb/public_html/includes/work_admin.php');
$notificationsSrc   = (string)file_get_contents($repo . '/appWeb/public_html/includes/notifications.php');
$publishersPageSrc  = (string)file_get_contents($repo . '/appWeb/public_html/manage/publishers.php');
$worksPageSrc       = (string)file_get_contents($repo . '/appWeb/public_html/manage/works.php');
$notificationsPageSrc = (string)file_get_contents($repo . '/appWeb/public_html/manage/notifications.php');

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

/**
 * Reconstruct the raw source text spanned by two token indices [start, end)
 * from an already-tokenised file (token_get_all() shape: array tokens carry
 * their text at [1]; single-char tokens are the text itself).
 */
function tokSpanText(array $toks, int $start, int $end): string
{
    $buf = '';
    $n = min($end, count($toks));
    for ($k = $start; $k < $n; $k++) {
        $t = $toks[$k];
        $buf .= is_array($t) ? $t[1] : $t;
    }
    return $buf;
}

/**
 * The source text of ONE `case '$name':` body inside the `$switchVar`
 * switch of `$file` — from this case's own label up to (not including) the
 * NEXT case label in switch order, or end-of-file for the last case.
 *
 * @return string|null null when no such case label exists in this switch.
 */
function caseBodyFor(string $file, string $switchVar, string $name): ?string
{
    $toks  = dispatchParserTokens($file);
    $cases = dispatchParserCaseTokens($file, $switchVar);
    foreach ($cases as $i => $c) {
        if ($c['name'] !== $name) { continue; }
        $start = $c['index'];
        $end   = isset($cases[$i + 1]) ? $cases[$i + 1]['index'] : count($toks);
        return tokSpanText($toks, $start, $end);
    }
    return null;
}

/** True when $needle is a literal substring of $body (or $body is null -> false). */
function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos($body, $needle) !== false;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — prove the two core functions above can
 * both find a marker that is there and fail to find one that is not,
 * against small real-tokeniser fixtures.
 * ========================================================================= */

$mutationFailures = [];

$fixtureSrc = <<<'PHP'
<?php
switch ($action) {
    case 'alpha':
        doAlphaThing();
        break;
    case 'beta':
        doBetaThing();
        alsoBetaHelper();
        break;
    case 'gamma':
        doGammaThing();
        break;
}
PHP;
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b4a_');
file_put_contents($fixtureFile, $fixtureSrc);

$betaBody = caseBodyFor($fixtureFile, '$action', 'beta');
if (!caseBodyContains($betaBody, 'doBetaThing(') || !caseBodyContains($betaBody, 'alsoBetaHelper(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-HIGH self-test: markers genuinely present in the beta case body were not found';
}
if (caseBodyContains($betaBody, 'doAlphaThing(') || caseBodyContains($betaBody, 'doGammaThing(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-LOW self-test: a NEIGHBOURING case\'s marker was wrongly found inside the beta case body — the slice is bleeding across case boundaries';
}
if (caseBodyFor($fixtureFile, '$action', 'does-not-exist') !== null) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: a non-existent case name returned a body instead of null';
}
unlink($fixtureFile);

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nAPI-coverage batch 4a (#1969): publishers (A1) / works+medley (A2) / notification send (A9) / songbook is_disabled (A12)\n\n";

$batch4a = [
    'admin_publisher_create',
    'admin_publisher_update',
    'admin_publisher_delete',
    'admin_publisher_merge',
    'admin_work_create',
    'admin_work_update',
    'admin_work_delete',
    'admin_work_medley_replace',
    'admin_notification_send',
];

/* ---- A. Dispatchable: the real $action switch carries all nine, exactly
   once each — tree-derived from the actual dispatcher, not a belief. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch4a as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}
/* admin_songbook_update is pre-existing (Batch 1/§719) — A12 must not have
   duplicated it into a second case label. */
ok("'admin_songbook_update' is STILL a real \$action case exactly once (A12 extended it in place, never duplicated it)",
    ($actionCounts['admin_songbook_update'] ?? 0) === 1);

/* ---- B. Documented: each has a top-level path item in api-docs.yaml
   (mirrors test-openapi-actions-exist.php's own extraction regex). ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch4a as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement key its sibling manage/*.php page gates on — checked from
   BOTH sides (the API case body AND the page source itself), so a future
   drift in either file is caught, not just a hand-typed belief about what
   the page currently does. ---- */
$entitlementByAction = [
    'admin_publisher_create' => 'manage_publishers',
    'admin_publisher_update' => 'manage_publishers',
    'admin_publisher_delete' => 'manage_publishers',
    'admin_publisher_merge'  => 'manage_publishers',
    'admin_work_create'         => 'manage_works',
    'admin_work_update'         => 'manage_works',
    'admin_work_delete'         => 'manage_works',
    'admin_work_medley_replace' => 'manage_works',
    'admin_notification_send'   => 'manage_notifications',
];
$pageSrcByEntitlement = [
    'manage_publishers'    => $publishersPageSrc,
    'manage_works'          => $worksPageSrc,
    'manage_notifications'  => $notificationsPageSrc,
];
foreach ($entitlementByAction as $name => $entKey) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' gates via userHasEntitlement('{$entKey}'",
        caseBodyContains($body, "userHasEntitlement('{$entKey}'"));
}
foreach ($pageSrcByEntitlement as $entKey => $src) {
    ok("the sibling manage/*.php page for '{$entKey}' really does gate on userHasEntitlement('{$entKey}' (API gate matches the page's OWN gate, not a belief about it)",
        strpos($src, "userHasEntitlement('{$entKey}'") !== false);
}

/* ---- D. A1 publisher actions: delegate to includes/publisher_admin.php —
   never a forked validation/write path (rule #22/#37). ---- */
$pubCreateBody = caseBodyFor($api, '$action', 'admin_publisher_create');
ok('admin_publisher_create delegates to publisherAdminValidateFields(',
    caseBodyContains($pubCreateBody, 'publisherAdminValidateFields('));
ok('admin_publisher_create delegates to publisherAdminCheckUniqueness(',
    caseBodyContains($pubCreateBody, 'publisherAdminCheckUniqueness('));
ok('admin_publisher_create delegates to publisherAdminCreate(',
    caseBodyContains($pubCreateBody, 'publisherAdminCreate('));
ok('admin_publisher_create delegates to publisherAdminReplaceAliases(',
    caseBodyContains($pubCreateBody, 'publisherAdminReplaceAliases('));
ok('admin_publisher_create does NOT re-embed a raw "INSERT INTO tblPublishers" (would be a forked write)',
    !caseBodyContains($pubCreateBody, 'INSERT INTO tblPublishers'));

$pubUpdateBody = caseBodyFor($api, '$action', 'admin_publisher_update');
ok('admin_publisher_update delegates to publisherAdminValidateFields(',
    caseBodyContains($pubUpdateBody, 'publisherAdminValidateFields('));
ok('admin_publisher_update delegates to publisherAdminCheckUniqueness(',
    caseBodyContains($pubUpdateBody, 'publisherAdminCheckUniqueness('));
ok('admin_publisher_update delegates to publisherAdminPersistFields(',
    caseBodyContains($pubUpdateBody, 'publisherAdminPersistFields('));
ok('admin_publisher_update delegates to publisherAdminRenameCascade( (the denorm tblSongbooks.Publisher cascade)',
    caseBodyContains($pubUpdateBody, 'publisherAdminRenameCascade('));
ok('admin_publisher_update delegates to publisherAdminReplaceAliases(',
    caseBodyContains($pubUpdateBody, 'publisherAdminReplaceAliases('));
ok('admin_publisher_update does NOT re-embed a raw "UPDATE tblPublishers" (would be a forked write)',
    !caseBodyContains($pubUpdateBody, 'UPDATE tblPublishers'));

$pubDeleteBody = caseBodyFor($api, '$action', 'admin_publisher_delete');
ok('admin_publisher_delete delegates to publisherAdminUsageCounts(',
    caseBodyContains($pubDeleteBody, 'publisherAdminUsageCounts('));
ok('admin_publisher_delete delegates to publisherAdminDelete(',
    caseBodyContains($pubDeleteBody, 'publisherAdminDelete('));
ok('admin_publisher_delete does NOT re-embed a raw "DELETE FROM tblPublishers" (would be a forked write)',
    !caseBodyContains($pubDeleteBody, 'DELETE FROM tblPublishers'));

$pubMergeBody = caseBodyFor($api, '$action', 'admin_publisher_merge');
ok('admin_publisher_merge delegates to publisherAdminMerge( (the ONE cascade — books/children/aliases/links repoint + source delete)',
    caseBodyContains($pubMergeBody, 'publisherAdminMerge('));

/* ---- E. A1 write cores actually EXIST in includes/publisher_admin.php
   (never assumed from the api.php call sites alone). ---- */
foreach ([
    'publisherAdminValidateFields', 'publisherAdminCheckUniqueness', 'publisherAdminCreate',
    'publisherAdminPersistFields', 'publisherAdminRenameCascade', 'publisherAdminReplaceAliases',
    'publisherAdminUsageCounts', 'publisherAdminDelete', 'publisherAdminMerge', 'publisherAdminProbeGates',
    'publisherTableExists',
] as $fn) {
    ok("includes/publisher_admin.php (or publisher_helpers.php) defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $publisherAdminSrc)
        || (bool)preg_match(
            '/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/',
            (string)file_get_contents($repo . '/appWeb/public_html/includes/publisher_helpers.php')
        ));
}

/* ---- F. A2 work scalar-field actions: delegate to includes/work_admin.php
   — never a forked slug/ISWC validator, and NEVER touch tblWorkComponents
   directly (medley composition is the SEPARATE admin_work_medley_replace
   action; rule #45 — scalar-field CRUD must not silently also rewrite the
   medley list). ---- */
$workCreateBody = caseBodyFor($api, '$action', 'admin_work_create');
ok('admin_work_create delegates to workSlugify( (includes/work_admin.php)',
    caseBodyContains($workCreateBody, 'workSlugify('));
ok('admin_work_create delegates to ihymns_canonical_iswc( (includes/identifier_normalize.php, via work_admin.php)',
    caseBodyContains($workCreateBody, 'ihymns_canonical_iswc('));
ok('admin_work_create delegates to workExists( (parent_id existence check)',
    caseBodyContains($workCreateBody, 'workExists('));
ok('admin_work_create delegates to ilidStampNewRow( (mints the ILW… id, mirrors manage/works.php)',
    caseBodyContains($workCreateBody, "ilidStampNewRow(\$db, 'work'"));
/* NOT a bare "tblWorkComponents" substring check here — the doc-comment
   immediately BEFORE the next case label (admin_work_update's) mentions
   that table name in prose, and caseBodyFor()'s slice legitimately
   includes a trailing comment right up to the next case label (documented
   in test-api-coverage-batch1.php's own caseBodyFor() doc-block). Checking
   the actual FUNCTION CALLS the medley write core exposes is both more
   precise and immune to that harmless bleed. */
ok('admin_work_create does NOT call workMedleyReplace( (medley composition is the separate admin_work_medley_replace action, rule #45)',
    !caseBodyContains($workCreateBody, 'workMedleyReplace('));
ok('admin_work_create does NOT call workMedleyAttach( (medley composition is the separate admin_work_medley_replace action, rule #45)',
    !caseBodyContains($workCreateBody, 'workMedleyAttach('));

$workUpdateBody = caseBodyFor($api, '$action', 'admin_work_update');
ok('admin_work_update delegates to workSlugify(',
    caseBodyContains($workUpdateBody, 'workSlugify('));
ok('admin_work_update delegates to ihymns_canonical_iswc(',
    caseBodyContains($workUpdateBody, 'ihymns_canonical_iswc('));
ok('admin_work_update delegates to workExists( (both the row-exists check and the parent_id existence check)',
    caseBodyContains($workUpdateBody, 'workExists('));
ok('admin_work_update does NOT touch tblWorkComponents (medley composition is the separate admin_work_medley_replace action, rule #45)',
    !caseBodyContains($workUpdateBody, 'tblWorkComponents'));
ok('admin_work_update does NOT re-embed a raw "INSERT INTO tblWorks" (create is a separate action)',
    !caseBodyContains($workUpdateBody, 'INSERT INTO tblWorks'));

$workDeleteBody = caseBodyFor($api, '$action', 'admin_work_delete');
ok('admin_work_delete issues the core "DELETE FROM tblWorks" (cascades handle child tables at the DB level, per schema.sql)',
    caseBodyContains($workDeleteBody, 'DELETE FROM tblWorks'));
ok('admin_work_delete does NOT separately touch tblWorkComponents (the FK cascade owns that, an app-level DELETE would be redundant/riskier)',
    !caseBodyContains($workDeleteBody, 'DELETE FROM tblWorkComponents'));

/* ---- G. A2 medley action: delegates ENTIRELY to the cycle-guarded
   workMedley*() core — never a forked INSERT/DELETE against
   tblWorkComponents (rule #45's central point). ---- */
$medleyBody = caseBodyFor($api, '$action', 'admin_work_medley_replace');
ok('admin_work_medley_replace delegates to workMedleyReplace( (includes/work_admin.php)',
    caseBodyContains($medleyBody, 'workMedleyReplace('));
ok('admin_work_medley_replace delegates to workMedleyConstituents( (reads back the STORED list, rule #35)',
    caseBodyContains($medleyBody, 'workMedleyConstituents('));
ok('admin_work_medley_replace delegates to workMedleyReady( (the un-migrated-install gate)',
    caseBodyContains($medleyBody, 'workMedleyReady('));
ok('admin_work_medley_replace delegates to workExists( (the medley Work itself must exist)',
    caseBodyContains($medleyBody, 'workExists('));
ok('admin_work_medley_replace does NOT re-embed a raw "INSERT INTO tblWorkComponents" (would be a forked write, rule #45)',
    !caseBodyContains($medleyBody, 'INSERT INTO tblWorkComponents'));
ok('admin_work_medley_replace does NOT re-embed a raw "DELETE FROM tblWorkComponents" (would be a forked write, rule #45)',
    !caseBodyContains($medleyBody, 'DELETE FROM tblWorkComponents'));

/* ---- H. A2 write cores actually EXIST in includes/work_admin.php. ---- */
foreach ([
    'workSlugify', 'workExists', 'workMedleyReady', 'workMedleyReplace',
    'workMedleyConstituents', 'workMedleyAttach', 'workMedleyWouldCycle', 'workAdminReady',
] as $fn) {
    ok("includes/work_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $workAdminSrc));
}

/* ---- I. A9 notification-send action: delegates to the ONE shared writer,
   notifyUser() — never a second copy of its Environment/ExpiresAt-aware
   INSERT shape (rule #22). ---- */
$notifBody = caseBodyFor($api, '$action', 'admin_notification_send');
ok('admin_notification_send delegates to notifyUser( for every resolved recipient (includes/notifications.php, #1638)',
    caseBodyContains($notifBody, 'notifyUser('));
ok('admin_notification_send does NOT re-embed a raw "INSERT INTO tblNotifications" (would be a forked write)',
    !caseBodyContains($notifBody, 'INSERT INTO tblNotifications'));
ok('includes/notifications.php defines function notifyUser(',
    (bool)preg_match('/\bfunction\s+notifyUser\s*\(/', $notificationsSrc));

/* ---- J. A12 — admin_songbook_update now handles is_disabled, via the
   SAME shared gate manage/songbooks.php's own $hasDisableCol probe uses
   (includes/songbook_visibility.php's songbookDisableReady()) — never a
   re-typed INFORMATION_SCHEMA probe. PRESENCE-based (array_key_exists),
   never unconditional (that would silently re-enable a book on every
   caller who doesn't yet know about the field). admin_songbook_create is
   asserted to remain UNTOUCHED — A12 named only the update handler. ---- */
$songbookUpdateBody = caseBodyFor($api, '$action', 'admin_songbook_update');
ok('admin_songbook_update delegates to songbookDisableReady( (includes/songbook_visibility.php — the SAME gate manage/songbooks.php uses)',
    caseBodyContains($songbookUpdateBody, 'songbookDisableReady('));
ok("admin_songbook_update reads is_disabled PRESENCE via array_key_exists('is_disabled'",
    caseBodyContains($songbookUpdateBody, "array_key_exists('is_disabled'"));
ok('admin_songbook_update writes IsDisabled via a plain "UPDATE tblSongbooks SET IsDisabled" (no core write function exists for this single-column flip, same posture as every other field on this pre-existing inline-SQL action)',
    caseBodyContains($songbookUpdateBody, 'UPDATE tblSongbooks SET IsDisabled'));
ok("admin_songbook_update's response echoes is_disabled back (rule #35 — the server's truth, never fabricated)",
    caseBodyContains($songbookUpdateBody, "'is_disabled'"));

$songbookCreateBody = caseBodyFor($api, '$action', 'admin_songbook_create');
ok('admin_songbook_create does NOT mention is_disabled (A12 named ONLY admin_songbook_update — create was not in scope)',
    !caseBodyContains($songbookCreateBody, 'is_disabled') && !caseBodyContains($songbookCreateBody, 'IsDisabled'));

ok('includes/songbook_visibility.php defines function songbookDisableReady(',
    (bool)preg_match(
        '/\bfunction\s+songbookDisableReady\s*\(/',
        (string)file_get_contents($repo . '/appWeb/public_html/includes/songbook_visibility.php')
    ));

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($mutationFailures) {
        fwrite(STDERR, "\nFAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    echo "\n{$passed} passed, {$failed} failed";
    if ($mutationFailures) { echo ' (+ ' . count($mutationFailures) . ' mutation self-test failure(s))'; }
    echo "\n";
    exit(1);
}

echo "\n{$passed} passed, 0 failed. All 9 API-coverage batch-4a endpoints are dispatchable, documented, gate on the same entitlement as their sibling page, and delegate to their shared cores with no forked validation/write — and admin_songbook_update's A12 is_disabled extension is presence-based and correctly scoped away from admin_songbook_create.\n";
exit(0);
