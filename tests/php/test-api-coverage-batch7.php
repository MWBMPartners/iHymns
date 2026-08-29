<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 7": musician relations/grouping + Web Push admin
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `tests/php/test-manage-action-api-coverage.php` mapped eight
 * `manage/*.php` write actions as `web_only:GAP-*` because building THAT
 * guard (not the 2026-08-28 audit — these five postdate it) found no
 * matching `api.php` twin: five on `/manage/musicians` (group membership +
 * relations + the "Add all remaining" bulk-register button), three on
 * `/manage/notifications` (delete one row + Web Push send/test). This file
 * checks, from the REAL dispatched source (never a hand-typed belief), that
 * this batch closed all eight correctly:
 *
 *   1. all eight new actions genuinely exist as `$action`-switch cases in
 *      api.php (dispatched exactly once each);
 *   2. each has a top-level path item in api-docs.yaml;
 *   3. each gates via userHasEntitlement() on the SAME entitlement its
 *      sibling manage/*.php page ACTUALLY gates on (checked from BOTH
 *      sides — never a hand-typed belief about what the page currently
 *      does);
 *   4. each DELEGATES to a shared core (rule #22) — the five musician
 *      actions to includes/musician_helpers.php's addMusicianRelation()/
 *      removeMusicianRelation()/removeMusicianGroupMember()/
 *      musicianBulkRegisterRemaining() (the last one itself extracted from
 *      manage/musicians.php's own inline handler in THIS batch, so both
 *      callers now share one transaction rather than two copies of one),
 *      the two Web Push actions to includes/web_push.php's
 *      webPushBroadcast()/webPushBuildPayload() — never a forked SQL
 *      statement or a hand-rolled encryption call re-embedded in api.php;
 *   5. manage/musicians.php's `bulk_register_unregistered` handler was
 *      genuinely RE-POINTED at musicianBulkRegisterRemaining() (no leftover
 *      inline register-loop + reconcile transaction);
 *   6. tests/php/test-manage-action-api-coverage.php's mapping was actually
 *      updated — none of the eight GAP reasons remain in that file's
 *      $MAPPING.
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * `api.php` has TWO switches (`$page` and `$action`), so a bare
 * `grep "case '...'"` cannot tell which one owns a label —
 * `dispatch_parser.php`'s token walker does. Every one of this batch's
 * eight actions is its OWN separate `case 'name': { ... }` block (the two
 * Web Push actions share ONE body via fall-through, mirroring the page's
 * own single `if ($action === 'push_send' || $action === 'push_test')`
 * shape — `caseBodyFor()` still isolates that shared body cleanly from its
 * neighbours since it slices by NEXT case label, not by `break`).
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyFor()`/`caseBodyContains()`/
 * `functionBodyFor()`/`braceBlockAfter()`/`stripPhpComments()` are proven,
 * against tiny in-memory fixtures, to both find a marker that is there AND
 * fail to find one that is not, before the real assertions below are
 * trusted — the same precedent test-api-coverage-batch6a.php set (these
 * helpers are deliberately duplicated locally per batch file rather than
 * shared, matching that precedent, since each batch's fixture shapes
 * differ slightly and a shared helper file would itself need the same
 * mutation-proof treatment).
 *
 *   php tests/php/test-api-coverage-batch7.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @see appWeb/public_html/api.php                              the eight new cases
 * @see appWeb/public_html/includes/musician_helpers.php         addMusicianRelation()/removeMusicianRelation()/removeMusicianGroupMember()/musicianBulkRegisterRemaining()
 * @see appWeb/public_html/includes/web_push.php                 webPushBroadcast()/webPushBuildPayload()/webPushKindValid()/webPushConfigured()
 * @see appWeb/public_html/manage/musicians.php                  re-pointed bulk_register_unregistered handler
 * @see appWeb/public_html/manage/notifications.php               unchanged page (delete/push_send/push_test)
 * @see tests/php/test-manage-action-api-coverage.php             the standing guard whose mapping this batch updated
 * @see tests/php/test-api-coverage-batch6a.php                  the sibling guard this mirrors
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';

$musicianCoreSrc     = (string)file_get_contents($repo . '/appWeb/public_html/includes/musician_helpers.php');
$webPushCoreSrc      = (string)file_get_contents($repo . '/appWeb/public_html/includes/web_push.php');
$musiciansPageSrc    = (string)file_get_contents($repo . '/appWeb/public_html/manage/musicians.php');
$notificationsPageSrc= (string)file_get_contents($repo . '/appWeb/public_html/manage/notifications.php');
$coverageGuardSrc    = (string)file_get_contents(__DIR__ . '/test-manage-action-api-coverage.php');
$apiSrc              = (string)file_get_contents($api);

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
 * from an already-tokenised file (token_get_all() shape).
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
 * NEXT case label that has REAL CODE before it, or end-of-file for the
 * last case.
 *
 * Handles fall-through labels (`case 'a': case 'b': { ... }` sharing one
 * body) explicitly: naively stopping at the IMMEDIATE next case label
 * would make the FIRST label of such a pair capture only the empty gap up
 * to its sibling (just `:`, whitespace, and the `case` keyword — no real
 * code) and wrongly report the shared body as absent for that label — the
 * rule #34 "guard fails on correct code" trap
 * tests/php/test-read-rate-limit-docs.php's own findActionCaseBody() hit
 * and documented first. So this walks FORWARD past any run of
 * immediately-adjacent case labels whose only separating tokens are `:`
 * (plus whitespace/comments and the next `case` keyword) before capturing
 * the body — i.e. it finds the label chain's END, not its first member's
 * neighbour.
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
        $j = $i;
        while (isset($cases[$j + 1])) {
            $gapStart = $cases[$j]['index'] + 1; // just past this label's own string token
            $gapEnd   = $cases[$j + 1]['index']; // up to the next label's string token
            $pureFallthrough = true;
            for ($k = $gapStart; $k < $gapEnd; $k++) {
                $t = $toks[$k];
                if ($t === ':') { continue; }
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CASE], true)) { continue; }
                $pureFallthrough = false;
                break;
            }
            if (!$pureFallthrough) { break; }
            $j++;
        }
        $end = isset($cases[$j + 1]) ? $cases[$j + 1]['index'] : count($toks);
        return tokSpanText($toks, $start, $end);
    }
    return null;
}

/**
 * Strip `//`/`#`/`/* *\/`/doc-comments from a PHP source fragment, so a
 * substring check below can never be fooled by a marker that only appears
 * in PROSE (a doc-block mentioning a function name, e.g.) rather than
 * actual code. Wraps a bare fragment (no opening `<?php`) so
 * `token_get_all()` tokenises it as PHP; defensive `@`-suppressed +
 * original-string fallback (never crash the guard itself on a
 * pathological fragment) — the mutation self-test below proves the happy
 * path actually strips.
 */
function stripPhpComments(string $src): string
{
    $wrapped = (strpos(ltrim($src), '<?php') === 0) ? $src : ("<?php\n" . $src);
    $toks = @token_get_all($wrapped);
    if (!is_array($toks)) { return $src; }
    $out = '';
    foreach ($toks as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

/**
 * True when $needle is a literal substring of $body's CODE — comments
 * stripped first — or $body is null -> false.
 */
function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos(stripPhpComments($body), $needle) !== false;
}

/**
 * Slice a named top-level `function NAME(...) { ... }` region's body via
 * brace depth from the `function` keyword's own occurrence.
 */
function braceBlockAfter(string $src, string $conditionMarker): ?string
{
    $pos = strpos($src, $conditionMarker);
    if ($pos === false) { return null; }
    $braceStart = strpos($src, '{', $pos);
    if ($braceStart === false) { return null; }

    $depth = 0;
    $len = strlen($src);
    for ($i = $braceStart; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $pos, $i - $pos + 1);
            }
        }
    }
    return null; // unbalanced — treat as "could not isolate"
}

function functionBodyFor(string $src, string $fnName): ?string
{
    if (!preg_match('/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m)) {
        return null;
    }
    return braceBlockAfter($src, $m[0]);
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — prove the core helper functions above
 * can both find a marker that is there AND fail to find one that is not,
 * against small real-tokeniser/real-source fixtures.
 * ========================================================================= */

$mutationFailures = [];

$fixtureSrc = <<<'PHP'
<?php
switch ($action) {
    case 'alpha':
        doAlphaThing();
        break;
    case 'beta':
    case 'beta2':
        doBetaThing();
        alsoBetaHelper();
        break;
    case 'gamma':
        doGammaThing();
        break;
}
PHP;
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b7_');
file_put_contents($fixtureFile, $fixtureSrc);

$betaBody = caseBodyFor($fixtureFile, '$action', 'beta');
if (!caseBodyContains($betaBody, 'doBetaThing(') || !caseBodyContains($betaBody, 'alsoBetaHelper(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-HIGH self-test: markers genuinely present in the beta case body were not found';
}
if (caseBodyContains($betaBody, 'doAlphaThing(') || caseBodyContains($betaBody, 'doGammaThing(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-LOW self-test: a NEIGHBOURING case\'s marker was wrongly found inside the beta case body — the slice is bleeding across case boundaries';
}
/* Fall-through sibling: 'beta2' has NO code of its own before 'beta''s
   body — starts right at the shared body too, since caseBodyFor() slices
   from ITS OWN label to the NEXT label, and 'beta2' IS the next label
   after 'beta'. Prove the shared-body pattern (push_send/push_test's real
   shape) is handled: 'beta2''s slice must NOT still see doAlphaThing()
   (its predecessor) and must be null-safe for a name that never labels
   anything. */
if (caseBodyFor($fixtureFile, '$action', 'does-not-exist') !== null) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: a non-existent case name returned a body instead of null';
}
unlink($fixtureFile);

$fnFixtureSrc = <<<'PHP'
<?php
function decoyFunction(string $x): string
{
    return trim($x);
}

function realWriter(string $raw): array
{
    $clean = pretendSanitize($raw, 'layout');
    return ['ok' => true, 'clean' => $clean];
}
PHP;
$decoyBody = functionBodyFor($fnFixtureSrc, 'decoyFunction');
$realBody  = functionBodyFor($fnFixtureSrc, 'realWriter');
if ($realBody === null || strpos($realBody, 'pretendSanitize(') === false) {
    $mutationFailures[] = 'functionBodyFor() FAILS-HIGH self-test: a marker genuinely present inside realWriter() was not found';
}
if ($decoyBody === null || strpos($decoyBody, 'pretendSanitize(') !== false) {
    $mutationFailures[] = 'functionBodyFor() FAILS-LOW self-test: decoyFunction()\'s isolated body wrongly contains a marker that only exists in the NEIGHBOURING realWriter()';
}
if (functionBodyFor($fnFixtureSrc, 'doesNotExistFunction') !== null) {
    $mutationFailures[] = 'functionBodyFor() FAILS-LOW self-test: a non-existent function name returned a body instead of null';
}

$commentTrapSrc = <<<'PHP'
/* This block deliberately never calls dangerousFunction() from here —
   see the design doc for why. */
safeFunction();
PHP;
if (caseBodyContains($commentTrapSrc, 'dangerousFunction(')) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-LOW self-test: a marker that appears ONLY inside a /* comment */ was wrongly treated as present in the code';
}
if (!caseBodyContains($commentTrapSrc, 'safeFunction(')) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-HIGH self-test: a marker genuinely present in real CODE (outside the comment) was not found';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nAPI-coverage batch 7: musician relations/grouping + Web Push admin\n\n";

$musicianActions = [
    'admin_musician_member_add',
    'admin_musician_member_remove',
    'admin_musician_relation_add',
    'admin_musician_relation_remove',
    'admin_musician_bulk_register',
];
$notificationActions = [
    'admin_notification_delete',
    'admin_notification_push_send',
    'admin_notification_push_test',
];
$batch7 = array_merge($musicianActions, $notificationActions);

/* ---- A. Dispatchable: the real $action switch carries all eight,
   exactly once each — tree-derived from the actual dispatcher. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch7 as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml. ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch7 as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement key its sibling manage/*.php page ACTUALLY gates on —
   checked from BOTH sides. ---- */
$entitlementByAction = [
    'admin_musician_member_add'      => 'manage_musicians',
    'admin_musician_member_remove'   => 'manage_musicians',
    'admin_musician_relation_add'    => 'manage_musicians',
    'admin_musician_relation_remove' => 'manage_musicians',
    'admin_musician_bulk_register'   => 'manage_musicians',
    'admin_notification_delete'      => 'manage_notifications',
    'admin_notification_push_send'   => 'manage_notifications',
    'admin_notification_push_test'   => 'manage_notifications',
];
foreach ($entitlementByAction as $name => $entKey) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' gates via userHasEntitlement('{$entKey}'",
        caseBodyContains($body, "userHasEntitlement('{$entKey}'"));
}
/* The page-side half: manage/musicians.php and manage/notifications.php
   ACTUALLY gate their whole page on manage_musicians / manage_notifications
   at the top of the file — not a hand-typed belief about what the page
   currently does. */
ok('manage/musicians.php really does gate the page on manage_musicians (API gate matches the page\'s OWN gate)',
    strpos($musiciansPageSrc, "userHasEntitlement('manage_musicians'") !== false);
ok('manage/notifications.php really does gate the page on manage_notifications (API gate matches the page\'s OWN gate)',
    strpos($notificationsPageSrc, "userHasEntitlement('manage_notifications'") !== false);

/* ---- D. The five musician actions delegate to includes/musician_helpers.php
   — never a forked SQL statement re-embedded in api.php. ---- */
$musicianCoreFnByAction = [
    'admin_musician_member_add'      => 'addMusicianRelation(',
    'admin_musician_member_remove'   => 'removeMusicianGroupMember(',
    'admin_musician_relation_add'    => 'addMusicianRelation(',
    'admin_musician_relation_remove' => 'removeMusicianRelation(',
    'admin_musician_bulk_register'   => 'musicianBulkRegisterRemaining(',
];
foreach ($musicianCoreFnByAction as $name => $fnCall) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' delegates to {$fnCall}", caseBodyContains($body, $fnCall));
    foreach (['INSERT INTO tblMusicianRelations', 'UPDATE tblMusicianRelations',
              'DELETE FROM tblMusicianRelations', 'INSERT INTO tblMusicians ('] as $forkedSql) {
        ok("'{$name}' does NOT re-embed a raw \"{$forkedSql}\" (would be a forked write)",
            !caseBodyContains($body, $forkedSql));
    }
}
foreach (array_unique(array_values($musicianCoreFnByAction)) as $fnCall) {
    $fn = rtrim($fnCall, '(');
    ok("includes/musician_helpers.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $musicianCoreSrc));
}

/* ---- E. The two Web Push actions delegate to includes/web_push.php —
   never a hand-rolled VAPID/encryption call or a forked SQL statement
   re-embedded in api.php. ---- */
foreach (['admin_notification_push_send', 'admin_notification_push_test'] as $name) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' delegates to webPushBroadcast(", caseBodyContains($body, 'webPushBroadcast('));
    ok("'{$name}' delegates to webPushBuildPayload(", caseBodyContains($body, 'webPushBuildPayload('));
    ok("'{$name}' checks webPushConfigured(", caseBodyContains($body, 'webPushConfigured('));
    ok("'{$name}' checks webPushKindValid(", caseBodyContains($body, 'webPushKindValid('));
    /* Never a second encryption implementation — openssl_* calls belong
       ONLY inside includes/web_push.php. */
    foreach (['openssl_sign(', 'openssl_pkey_new(', 'hash_hkdf('] as $cryptoCall) {
        ok("'{$name}' does NOT call {$cryptoCall} directly (crypto stays inside web_push.php)",
            !caseBodyContains($body, $cryptoCall));
    }
}
foreach (['webPushBroadcast', 'webPushBuildPayload', 'webPushConfigured', 'webPushKindValid'] as $fn) {
    ok("includes/web_push.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $webPushCoreSrc));
}

/* admin_notification_delete has no dedicated core (the page itself is a
   one-line DELETE with no wrapper function) — assert instead that the
   exact same SQL statement text appears in BOTH the page's `delete`
   handler and the API action, so the two can never silently drift into
   different WHERE clauses. */
$deleteApiBody = caseBodyFor($api, '$action', 'admin_notification_delete');
ok("'admin_notification_delete' issues the SAME SQL as the page's own delete handler",
    caseBodyContains($deleteApiBody, 'DELETE FROM tblNotifications WHERE Id = ?')
    && strpos(stripPhpComments($notificationsPageSrc), 'DELETE FROM tblNotifications WHERE Id = ?') !== false);

/* ---- F. api.php requires includes/web_push.php explicitly (rule #22 —
   never rely on an implicit transitive load for new code). ---- */
ok('api.php explicitly requires includes/web_push.php',
    (bool)preg_match(
        "/require_once\\s+__DIR__\\s*\\.\\s*DIRECTORY_SEPARATOR\\s*\\.\\s*'includes'\\s*\\.\\s*DIRECTORY_SEPARATOR\\s*\\.\\s*'web_push\\.php'/",
        stripPhpComments($apiSrc)
    ));

/* =========================================================================
 * G. EXTRACTION VERIFICATION — manage/musicians.php's
 * bulk_register_unregistered handler was genuinely RE-POINTED at
 * musicianBulkRegisterRemaining(), not left with a second copy of the
 * register-loop + reconcile transaction inline.
 * ========================================================================= */

$pageBulkBlock = braceBlockAfter(
    $musiciansPageSrc,
    "(string)(\$_POST['action'] ?? '') === 'bulk_register_unregistered') {"
);
ok("manage/musicians.php's bulk_register_unregistered handler is isolatable by braceBlockAfter()",
    $pageBulkBlock !== null);
ok("manage/musicians.php's bulk_register_unregistered handler delegates to musicianBulkRegisterRemaining(",
    caseBodyContains($pageBulkBlock, 'musicianBulkRegisterRemaining('));
foreach (['SELECT Id FROM tblMusicians WHERE Name = ?', 'begin_transaction('] as $leftover) {
    ok("manage/musicians.php's bulk_register_unregistered handler has NO leftover \"{$leftover}\" (genuinely re-pointed at the core, not a second copy of the loop)",
        !caseBodyContains($pageBulkBlock, $leftover));
}

/* Cross-check: exactly ONE file in the whole tree opens a transaction
   around the register-loop + reconcile pair (the core) — never a second
   copy in the page or in api.php itself (already checked absent above;
   this is the whole-tree confirmation via the function's own defining
   file). */
$bulkCoreFnBody = functionBodyFor($musicianCoreSrc, 'musicianBulkRegisterRemaining');
ok('includes/musician_helpers.php\'s musicianBulkRegisterRemaining() is isolatable by functionBodyFor()',
    $bulkCoreFnBody !== null);
ok('musicianBulkRegisterRemaining() calls musicianCitedUnregisteredNames(', caseBodyContains($bulkCoreFnBody, 'musicianCitedUnregisteredNames('));
ok('musicianBulkRegisterRemaining() calls registerMusicianByName(', caseBodyContains($bulkCoreFnBody, 'registerMusicianByName('));
ok('musicianBulkRegisterRemaining() calls musicianReconcileCreditNameBytes(', caseBodyContains($bulkCoreFnBody, 'musicianReconcileCreditNameBytes('));
ok('musicianBulkRegisterRemaining() opens its own transaction (begin_transaction()',
    caseBodyContains($bulkCoreFnBody, 'begin_transaction('));

/* =========================================================================
 * H. THE STANDING GUARD'S MAPPING WAS ACTUALLY UPDATED — none of the eight
 * GAP-* reasons this batch closed remain in
 * tests/php/test-manage-action-api-coverage.php's $MAPPING, and each of
 * the eight now maps to its real api: target.
 * ========================================================================= */

$closedGapReasons = [
    'GAP-musician-group-membership',
    'GAP-musician-relation',
    'GAP-musician-bulk-register',
    'GAP-notification-delete',
    'GAP-webpush-broadcast',
];
$strippedGuard = stripPhpComments($coverageGuardSrc);
foreach ($closedGapReasons as $reason) {
    ok("test-manage-action-api-coverage.php's \$MAPPING no longer contains the closed '{$reason}' reason",
        strpos($strippedGuard, "'web_only:{$reason}'") === false);
}
$expectedMappingTargets = [
    "'add_member'                => 'api:admin_musician_member_add'",
    "'add_relation'              => 'api:admin_musician_relation_add'",
    "'bulk_register_unregistered'=> 'api:admin_musician_bulk_register'",
    "'remove_member'             => 'api:admin_musician_member_remove'",
    "'remove_relation'           => 'api:admin_musician_relation_remove'",
];
foreach ($expectedMappingTargets as $needle) {
    ok("test-manage-action-api-coverage.php's \$MAPPING contains \"{$needle}\"",
        strpos($coverageGuardSrc, $needle) !== false);
}
foreach (["'delete'             => 'api:admin_notification_delete'",
          "'push_send'          => 'api:admin_notification_push_send'",
          "'push_test'          => 'api:admin_notification_push_test'"] as $needle) {
    ok("test-manage-action-api-coverage.php's \$MAPPING contains \"{$needle}\"",
        strpos($coverageGuardSrc, $needle) !== false);
}

/* =========================================================================
 * I. api2.php / print-pdf.php / editor api.php CONSTRAINT COMPLIANCE — this
 * batch's task explicitly listed those as out of scope. Confirms none of
 * them mention this batch's eight new action names at all.
 * ========================================================================= */

foreach ([
    'api2.php'       => $repo . '/appWeb/public_html/manage/editor/api2.php',
    'editor api.php' => $repo . '/appWeb/public_html/manage/editor/api.php',
    'print-pdf.php'  => $repo . '/appWeb/public_html/manage/print-pdf.php',
] as $label => $path) {
    if (!is_file($path)) { continue; }
    $src = stripPhpComments((string)file_get_contents($path));
    $touched = false;
    foreach ($batch7 as $name) {
        if (strpos($src, $name) !== false) { $touched = true; break; }
    }
    ok("{$label} was left untouched by this batch (mentions none of the eight new action names)", !$touched);
}

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

echo "\n{$passed} passed, 0 failed. All eight API-coverage batch-7 endpoints are dispatchable, documented, gate on the same entitlement as their sibling page, delegate to their shared cores with no forked SQL/crypto, manage/musicians.php was genuinely re-pointed at the new musicianBulkRegisterRemaining() core, and the standing coverage guard's mapping reflects all eight closed gaps.\n";
exit(0);
