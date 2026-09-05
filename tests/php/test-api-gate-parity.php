<?php

declare(strict_types=1);

/**
 * iHymns — F2 entitlement-gate cleanup: API/page gate parity guard
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A security audit (rule #1587's own worked regression, generalised) found
 * a bounded set of admin WRITE actions in api.php gating on a bare
 * `in_array($authUser['Role'], ['admin','global_admin'])` instead of the
 * finer entitlement their sibling `/manage/*.php` page ACTUALLY gates that
 * same write on. That matters because an operator who revokes a permission
 * at `/manage/entitlements` expects it revoked everywhere — a bare
 * role-check API twin is an open side door the entitlements page cannot
 * see or close (exactly the class of bug rule #1587 / the #1590
 * "entitlement truth-up" program already fixed once for `admin_songbook_*`
 * / `admin_musician_*`'s SIBLINGS and the `admin_user_*` family's other
 * six actions, API-coverage batch 7). This guard was extended a second
 * time (#1986, "F2 sweep") to cover the REMAINING pre-existing bare-role
 * admin WRITE actions batch 7 missed: the `admin_songbooks_reorder`,
 * `admin_group_*` and `admin_organisation_*` families.
 *
 * WHAT THIS GUARD PROVES, PER ACTION
 * -----------------------------------
 * 1. the action still exists as a real `$action` case in api.php;
 * 2. it calls `userHasEntitlement('<expected-key>', ...)` — not merely
 *    `in_array($Role, [...])` alone — proven by ISOLATING the case's own
 *    body (never a whole-file grep that could find an unrelated call);
 * 3. the entitlement key matches what the SIBLING manage/*.php page
 *    ACTUALLY gates the same operation on — checked from the page's own
 *    source, not a hand-typed belief;
 * 4. EQUIVALENCE holds today: `includes/entitlements.php`'s hardcoded
 *    default for that key is EXACTLY `['admin', 'global_admin']` — proven
 *    by parsing the real `ENTITLEMENTS` array, not assumed — so today's
 *    admitted set is provably unchanged by the swap (Part B's explicit
 *    "behaviour-neutral" requirement).
 *
 * WHAT THIS GUARD DOES **NOT** ASSERT (DELIBERATELY)
 * -----------------------------------------------------------------------
 * The task that produced this pass was explicit: swap ONLY actions with a
 * verified finer entitlement; LEAVE legitimately admin-only actions (schema
 * audit, migrations, diagnostics, gating switches, cleanup, entitlements
 * management) on the bare role check. This guard therefore does not assert
 * "every admin_* action uses userHasEntitlement" — that would be the
 * over-swap the task explicitly warned against, and a guard that bans the
 * bare check outright would be WRONG on correct code (rule #34's "a guard
 * that fails on correct code gets weakened or deleted" trap). It checks
 * only the NAMED set below, each with its own page-verified reason.
 *
 * The #1986 sweep also examined — and deliberately LEFT UNCHANGED, on
 * page-verified evidence — several bare-role actions this guard does NOT
 * assert on: `admin_songbook_delete_cascade` / `admin_songbooks_auto_
 * colour_fill` / `admin_songbooks_auto_colour_reassign` (manage/songbooks.
 * php ALSO bare-role-gates these specific destructive cases, beyond its
 * page-level manage_songbooks entitlement — genuine parity, not a miss);
 * `auth_register`'s admin-only-registration-mode check (no sibling
 * manage/*.php CRUD write to diff against); seven of the nine read-only
 * `admin_*` listing/report actions (`admin_users`, `admin_groups`,
 * `admin_activity_log`, `admin_organisations`, `admin_analytics_searches`,
 * `admin_data_health`, `admin_schema_audit`, `admin_migrations_status` —
 * out of scope: the task was WRITE actions only); and `admin_revision_
 * review`, flagged unsure because no manage/*.php page implements its
 * approve/reject-pending-revision workflow at all (manage/revisions.php is
 * a same-named-but-different, read-only audit log gated on
 * `verify_songs`).
 *
 * #2086 ADDITION — `song_revisions` / `admin_pending_revisions` (READ
 * actions, a SEPARATE section below, not folded into $GATED)
 * -----------------------------------------------------------------------
 * These two were bare-role-gated 500s (wrong SQL columns, fixed
 * separately) that this guard's own PREVIOUS revision listed as
 * out-of-scope precisely because `verify_songs` — the entitlement
 * `/manage/revisions` actually gates its own listing on — defaults WIDER
 * than admin+ (editor/admin/global_admin), so folding them into $GATED's
 * equivalence loop (assertion 5, which insists on EXACTLY
 * ['admin','global_admin']) would have been a false claim for one of them.
 * `song_revisions`'s old bare check WAS exactly
 * ['editor','admin','global_admin'] — genuinely behaviour-neutral, proven
 * below the same way. `admin_pending_revisions`'s old bare check was only
 * ['admin','global_admin'] — the swap to `verify_songs` is a DELIBERATE
 * WIDENING (an editor can already see this exact row inside
 * `/manage/revisions`'s unfiltered listing; refusing the SAME editor a
 * status-filtered view of the SAME rows was the actual bug), asserted
 * explicitly as a widening rather than silently assumed equivalent.
 *
 * WHY TREE-DERIVED WHERE IT MATTERS MOST (rule #34)
 * -----------------------------------------------------------------------
 * The set of TWENTY actions itself is a maintained list (matching this
 * codebase's other "maintained mapping with a rationale per entry"
 * guards — test-manage-action-api-coverage.php's own $MAPPING is the
 * house precedent) — a security-audit finding is inherently a named list,
 * not something re-derivable from the tree. But every CLAIM about the
 * list — does the action call userHasEntitlement, does the page gate on
 * that same key, is the default really ['admin','global_admin'] — is
 * checked against the LIVE source, never typed from memory.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyFor()`/`caseBodyContains()`
 * are proven, against a small in-memory fixture (including a fall-through
 * pair, mirroring admin_credit_person_add/admin_musician_add's real
 * shape), to both find a marker that is there AND fail to find one that is
 * not, before the real assertions below are trusted.
 *
 *   php tests/php/test-api-gate-parity.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @see appWeb/public_html/api.php                          the twenty swapped/added gates
 * @see appWeb/public_html/includes/entitlements.php          the ENTITLEMENTS default map
 * @see appWeb/public_html/manage/musicians.php                page-side manage_musicians gate
 * @see appWeb/public_html/manage/songbooks.php                page-side manage_songbooks gate
 * @see appWeb/public_html/manage/users.php                    page-side view_users gate + create's own doc-comment
 * @see appWeb/public_html/manage/groups.php                   page-side manage_user_groups gate (#1986)
 * @see appWeb/public_html/manage/organisations.php            page-side manage_organisations gate (#1986)
 * @see appWeb/public_html/manage/revisions.php                page-side verify_songs gate (#2086)
 * @see tests/php/test-api-coverage-batch7.php                the sibling guard this borrows its helpers from
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';

$apiSrc            = (string)file_get_contents($api);
$entitlementsSrc   = (string)file_get_contents($repo . '/appWeb/public_html/includes/entitlements.php');
$musiciansPageSrc  = (string)file_get_contents($repo . '/appWeb/public_html/manage/musicians.php');
$songbooksPageSrc  = (string)file_get_contents($repo . '/appWeb/public_html/manage/songbooks.php');
$usersPageSrc      = (string)file_get_contents($repo . '/appWeb/public_html/manage/users.php');
$groupsPageSrc        = (string)file_get_contents($repo . '/appWeb/public_html/manage/groups.php');
$organisationsPageSrc = (string)file_get_contents($repo . '/appWeb/public_html/manage/organisations.php');

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

/* ---- shared helpers (mirrors test-api-coverage-batch7.php's copies —
   duplicated per-file rather than shared, matching this repo's established
   precedent for these tiny tokeniser helpers). ---- */

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

function caseBodyFor(string $file, string $switchVar, string $name): ?string
{
    $toks  = dispatchParserTokens($file);
    $cases = dispatchParserCaseTokens($file, $switchVar);
    foreach ($cases as $i => $c) {
        if ($c['name'] !== $name) { continue; }
        $start = $c['index'];
        $j = $i;
        while (isset($cases[$j + 1])) {
            $gapStart = $cases[$j]['index'] + 1;
            $gapEnd   = $cases[$j + 1]['index'];
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

function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos(stripPhpComments($body), $needle) !== false;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34)
 * ========================================================================= */

$mutationFailures = [];

$fixtureSrc = <<<'PHP'
<?php
switch ($action) {
    case 'solo_action': {
        gateCheckOne();
        break;
    }
    case 'alias_a':
    case 'alias_b': {
        gateCheckTwo();
        sharedBodyMarker();
        break;
    }
    case 'trailing_action': {
        gateCheckThree();
        break;
    }
}
PHP;
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_gate_parity_fixture_');
file_put_contents($fixtureFile, $fixtureSrc);

$soloBody = caseBodyFor($fixtureFile, '$action', 'solo_action');
if (!caseBodyContains($soloBody, 'gateCheckOne(')) {
    $mutationFailures[] = 'caseBodyFor() FAILS-HIGH self-test: a marker genuinely present in solo_action\'s own body was not found';
}
if (caseBodyContains($soloBody, 'gateCheckTwo(')) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: solo_action\'s body wrongly bled into the NEXT case';
}

/* Fall-through pair: BOTH labels must see the shared body. */
$aliasABody = caseBodyFor($fixtureFile, '$action', 'alias_a');
$aliasBBody = caseBodyFor($fixtureFile, '$action', 'alias_b');
foreach (['gateCheckTwo(', 'sharedBodyMarker('] as $marker) {
    if (!caseBodyContains($aliasABody, $marker)) {
        $mutationFailures[] = "caseBodyFor() FAILS-HIGH self-test: fall-through label 'alias_a' did not see its own shared marker {$marker}";
    }
    if (!caseBodyContains($aliasBBody, $marker)) {
        $mutationFailures[] = "caseBodyFor() FAILS-HIGH self-test: fall-through label 'alias_b' did not see the shared marker {$marker}";
    }
}
if (caseBodyContains($aliasABody, 'gateCheckOne(') || caseBodyContains($aliasABody, 'gateCheckThree(')) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: alias_a\'s body wrongly bled into a NEIGHBOURING case outside the fall-through pair';
}
if (caseBodyFor($fixtureFile, '$action', 'does-not-exist') !== null) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: a non-existent case name returned a body instead of null';
}
unlink($fixtureFile);

$commentTrapSrc = <<<'PHP'
/* This never actually calls userHasEntitlement('manage_ghost', $r) — that
   string only appears in this comment. */
somethingElse();
PHP;
if (caseBodyContains($commentTrapSrc, "userHasEntitlement('manage_ghost'")) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-LOW self-test: a marker that appears ONLY inside a /* comment */ was wrongly treated as present in the code';
}
if (!caseBodyContains($commentTrapSrc, 'somethingElse(')) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-HIGH self-test: a marker genuinely present in real CODE was not found';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nF2 entitlement-gate cleanup: API/page gate parity\n\n";

/**
 * The twenty swapped/added actions, their expected entitlement key, and
 * which page's source proves that key is the page's OWN gate for the
 * equivalent operation. `admin_user_create` is the one ADDED-entitlement
 * case (kept alongside its pre-existing bare role check, matching its six
 * siblings' established #1590 E1 pattern — see the in-code comment on that
 * action); the other nineteen are SWAPS (bare check replaced outright,
 * matching the admin_tune_add / admin_musician_duplicate_dismiss
 * precedent already in api.php).
 *
 * The last eleven entries (`admin_songbooks_reorder` through
 * `admin_organisation_member_remove`) are the #1986 "F2 sweep" addition —
 * see the file doc-block above for which OTHER bare-role actions this same
 * sweep examined and deliberately left alone (page also bare-checks / no
 * sibling page / read-only / out of scope).
 */
$GATED = [
    'admin_credit_person_add'    => ['manage_musicians', 'musicians'],
    'admin_credit_person_update' => ['manage_musicians', 'musicians'],
    'admin_credit_person_rename' => ['manage_musicians', 'musicians'],
    'admin_credit_person_merge'  => ['manage_musicians', 'musicians'],
    'admin_credit_person_delete' => ['manage_musicians', 'musicians'],
    'admin_songbook_create'      => ['manage_songbooks', 'songbooks'],
    'admin_songbook_update'      => ['manage_songbooks', 'songbooks'],
    'admin_songbook_delete'      => ['manage_songbooks', 'songbooks'],
    'admin_user_create'          => ['view_users', 'users'],
    /* #1986 F2 sweep additions */
    'admin_songbooks_reorder'              => ['manage_songbooks', 'songbooks'],
    'admin_group_create'                   => ['manage_user_groups', 'groups'],
    'admin_group_update'                   => ['manage_user_groups', 'groups'],
    'admin_group_delete'                   => ['manage_user_groups', 'groups'],
    'admin_group_member_add'               => ['manage_user_groups', 'groups'],
    'admin_group_member_remove'            => ['manage_user_groups', 'groups'],
    /* #1996 — admin_organisation_create is a NEW action (not a swap of a
       pre-existing bare-role check), added alongside the other nine
       admin_organisation_* siblings already in this list, gated on the
       SAME manage_organisations key from day one. */
    'admin_organisation_create'            => ['manage_organisations', 'organisations'],
    'admin_organisation_update'            => ['manage_organisations', 'organisations'],
    'admin_organisation_delete'            => ['manage_organisations', 'organisations'],
    'admin_organisation_member_add'        => ['manage_organisations', 'organisations'],
    'admin_organisation_member_role_change' => ['manage_organisations', 'organisations'],
    'admin_organisation_member_remove'     => ['manage_organisations', 'organisations'],
];
$pageSrcByTag = [
    'musicians'     => $musiciansPageSrc,
    'songbooks'     => $songbooksPageSrc,
    'users'         => $usersPageSrc,
    'groups'        => $groupsPageSrc,
    'organisations' => $organisationsPageSrc,
];

/* ---- 1. Dispatchable — each is still a real, singly-defined $action
   case (a fall-through alias like admin_credit_person_add /
   admin_musician_add counts as one dispatch of the SHARED body, so this
   checks the label exists at all, not exclusivity of the body). ---- */
$actionCases = dispatchParserCasesForSwitch($api, '$action');
foreach (array_keys($GATED) as $name) {
    ok("'{$name}' is a real \$action case in api.php", in_array($name, $actionCases, true));
}

/* ---- 2. Each case body calls userHasEntitlement('<key>', ...) — not
   merely the bare role check alone. ---- */
foreach ($GATED as $name => [$entKey, $pageTag]) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' calls userHasEntitlement('{$entKey}'",
        caseBodyContains($body, "userHasEntitlement('{$entKey}'"));
}

/* ---- 3. The nineteen SWAP actions no longer gate on the bare role-check
   ALONE — i.e. userHasEntitlement is what actually decides admission, not
   just bolted on beside an unchanged bare check. For the nineteen swaps
   the literal `in_array($authUser['Role'], ['admin', 'global_admin'])`
   string must be ABSENT from the case body (it was replaced, not
   duplicated). admin_user_create is the deliberate exception — it KEEPS
   the bare check as well (established #1590 E1 pattern, matching its six
   siblings), so it is asserted separately below instead of here. ---- */
$swapOnly = $GATED;
unset($swapOnly['admin_user_create']);
foreach ($swapOnly as $name => [$entKey, $pageTag]) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' no longer gates on the bare in_array(\$authUser['Role'], ['admin', 'global_admin']) check (replaced, not just supplemented)",
        !caseBodyContains($body, "in_array(\$authUser['Role'], ['admin', 'global_admin'])"));
}
/* admin_user_create: the bare check is DELIBERATELY still present (E1
   pattern) — assert it stays, and that BOTH conditions are wired as an
   AND (the entitlement check is not dead code after an early return). */
$createBody = caseBodyFor($api, '$action', 'admin_user_create');
ok("'admin_user_create' DOES still carry the bare in_array(...) check too (E1 pattern: raw check kept, not replaced, because it also establishes \$authUser)",
    caseBodyContains($createBody, "in_array(\$authUser['Role'], ['admin', 'global_admin'])"));
ok("'admin_user_create' checks userHasEntitlement AFTER establishing \$authUser via the bare check (both conditions present, in the E1 AND-shape)",
    (function () use ($createBody): bool {
        if ($createBody === null) { return false; }
        $stripped = stripPhpComments($createBody);
        $bareAt = strpos($stripped, "in_array(\$authUser['Role']");
        $entAt  = strpos($stripped, "userHasEntitlement('view_users'");
        return $bareAt !== false && $entAt !== false && $entAt > $bareAt;
    })()
);

/* ---- 4. Page-side: the entitlement key really is that page's OWN gate
   for the equivalent write — not a hand-typed belief. musicians.php /
   songbooks.php / groups.php / organisations.php gate the WHOLE page on
   it (top-of-file, before the POST switch is even reached); users.php
   gates the WHOLE page on view_users and its `create` case has no finer
   per-action entry (verified via the page's own explicit doc-comment
   marker, since the ABSENCE of an entry cannot be grepped for directly). ---- */
ok('manage/musicians.php really does gate the page on manage_musicians',
    strpos($musiciansPageSrc, "userHasEntitlement('manage_musicians'") !== false);
ok('manage/songbooks.php really does gate the page on manage_songbooks',
    strpos($songbooksPageSrc, "userHasEntitlement('manage_songbooks'") !== false);
ok('manage/users.php really does gate the page on view_users',
    strpos($usersPageSrc, "userHasEntitlement('view_users'") !== false);
ok('manage/users.php\'s own doc-comment confirms `create` is deliberately absent from its per-action $ACTION_ENTITLEMENTS map (so view_users — the page-level gate — really is create\'s only gate, not a guess)',
    strpos($usersPageSrc, '`create` is deliberately absent') !== false
    && strpos($usersPageSrc, 'no `create_users` entitlement') !== false);
/* #1986 F2 sweep additions. */
ok('manage/groups.php really does gate the page on manage_user_groups',
    strpos($groupsPageSrc, "userHasEntitlement('manage_user_groups'") !== false);
ok('manage/organisations.php really does gate the page on manage_organisations',
    strpos($organisationsPageSrc, "userHasEntitlement('manage_organisations'") !== false);
/* manage/organisations.php's `update` case ALSO has a finer field-level
   gate (`manage_org_licences`) that PRESERVES (not rejects) just the
   licence sub-fields for a caller without it. #1986 CLOSED the matching
   API gap: admin_organisation_update now replicates the preserve exactly,
   so a caller with manage_organisations but NOT manage_org_licences can no
   longer change licences through the API that the page forbids them. These
   two assertions prove BOTH sides — the page reality and the API parity —
   so a future edit that drops either goes red. */
ok('manage/organisations.php\'s `update` case really does carry a finer manage_org_licences check alongside the page-level manage_organisations gate',
    strpos($organisationsPageSrc, "userHasEntitlement('manage_org_licences'") !== false
    && strpos($organisationsPageSrc, '$canEditOrgLicences') !== false);
ok('api.php admin_organisation_update now replicates the field-level manage_org_licences preserve (resolves $canEditOrgLicences and, when false, restores LicenceType/LicenceNumber from the stored row and skips orgLicenceSyncSet) — the #1986 parity fix',
    strpos($apiSrc, "userHasEntitlement('manage_org_licences'") !== false
    && strpos($apiSrc, '$canEditOrgLicences') !== false
    && preg_match('/if\s*\(\s*!\$canEditOrgLicences\s*\)\s*\{\s*\$licenceType\s*=/', $apiSrc) === 1
    && preg_match('/if\s*\(\s*\$canEditOrgLicences\s*\)\s*\{[^}]*orgLicenceSyncSet/s', $apiSrc) === 1);

/* ---- 5. EQUIVALENCE — the default map for each entitlement key really is
   EXACTLY ['admin', 'global_admin'] today, parsed from the live
   ENTITLEMENTS const (never assumed), proving the swap changes nothing
   live. Order-insensitive (a maintainer could list the two roles either
   way without changing behaviour) and exact-count (catches a THIRD role
   quietly added to the list, which would silently WIDEN who is admitted —
   the opposite failure this whole task exists to prevent). ---- */
foreach (array_unique(array_column($GATED, 0)) as $entKey) {
    if (!preg_match(
        "/'" . preg_quote($entKey, '/') . "'\\s*=>\\s*\\[([^\\]]*)\\]/",
        $entitlementsSrc,
        $m
    )) {
        ok("includes/entitlements.php defines a default for '{$entKey}' (could not even find the map entry)", false);
        continue;
    }
    $roles = array_map(
        static fn(string $r): string => trim($r, " \t\n\r\0\x0B'\""),
        array_filter(explode(',', $m[1]), static fn(string $r): bool => trim($r) !== '')
    );
    sort($roles);
    ok("includes/entitlements.php's default for '{$entKey}' is EXACTLY ['admin','global_admin'] (equivalence proof — the swap is behaviour-neutral today)",
        $roles === ['admin', 'global_admin']);
}

/* =========================================================================
 * #2086 — song_revisions / admin_pending_revisions (READ actions)
 *
 * See the file doc-block above for why these live in their OWN section
 * instead of $GATED: verify_songs's live default is not ['admin',
 * 'global_admin'], so assertion 5's equivalence check does not apply to
 * them the same way — one of the two is a deliberate widening, not a
 * behaviour-neutral swap, and that difference is asserted explicitly
 * below rather than glossed over.
 * ========================================================================= */

$revisionsPageSrc = (string)file_get_contents($repo . '/appWeb/public_html/manage/revisions.php');

foreach (['song_revisions', 'admin_pending_revisions'] as $name) {
    ok("'{$name}' is a real \$action case in api.php",
        in_array($name, $actionCases, true));
    $revBody = caseBodyFor($api, '$action', $name);
    ok("'{$name}' calls userHasEntitlement('verify_songs'",
        caseBodyContains($revBody, "userHasEntitlement('verify_songs'"));
}

/* Each one's OWN pre-#2086 bare-role literal, confirmed ABSENT — the two
   endpoints had DIFFERENT bare checks (three roles vs. two), so this is
   checked per-action against the exact string that action used to carry,
   never a shared literal that could miss one of them. */
$songRevisionsBody = caseBodyFor($api, '$action', 'song_revisions');
ok("'song_revisions' no longer gates on its old bare in_array(\$authUser['Role'], ['editor', 'admin', 'global_admin']) check (replaced, not just supplemented)",
    !caseBodyContains($songRevisionsBody, "in_array(\$authUser['Role'], ['editor', 'admin', 'global_admin'])"));

$adminPendingBody = caseBodyFor($api, '$action', 'admin_pending_revisions');
ok("'admin_pending_revisions' no longer gates on its old bare in_array(\$authUser['Role'], ['admin', 'global_admin']) check (replaced, not just supplemented)",
    !caseBodyContains($adminPendingBody, "in_array(\$authUser['Role'], ['admin', 'global_admin'])"));

/* Page-side: verify_songs really is manage/revisions.php's OWN gate for
   the equivalent (unfiltered) listing — not a hand-typed belief. */
ok('manage/revisions.php really does gate the page on verify_songs',
    strpos($revisionsPageSrc, "userHasEntitlement('verify_songs'") !== false);

/* verify_songs's live default, parsed from the real ENTITLEMENTS map —
   never assumed. This is the SAME parse assertion 5 above uses, just
   compared against three roles instead of two. */
$verifySongsRoles = null;
if (preg_match("/'verify_songs'\\s*=>\\s*\\[([^\\]]*)\\]/", $entitlementsSrc, $m)) {
    $verifySongsRoles = array_map(
        static fn(string $r): string => trim($r, " \t\n\r\0\x0B'\""),
        array_filter(explode(',', $m[1]), static fn(string $r): bool => trim($r) !== '')
    );
    sort($verifySongsRoles);
}
ok("includes/entitlements.php defines a default for 'verify_songs' (could not even find the map entry)",
    $verifySongsRoles !== null);

ok("'song_revisions' swap is BEHAVIOUR-NEUTRAL: verify_songs's live default is EXACTLY ['admin','editor','global_admin'] — the SAME three roles its old bare check already admitted, no more and no fewer",
    $verifySongsRoles === ['admin', 'editor', 'global_admin']);

ok("'admin_pending_revisions' swap is a DELIBERATE WIDENING, asserted explicitly rather than silently assumed: verify_songs's live default includes 'editor', which its old bare ['admin','global_admin'] check did not admit",
    $verifySongsRoles !== null && in_array('editor', $verifySongsRoles, true));

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

echo "\n{$passed} passed, 0 failed. All twenty F2 gate-parity actions call userHasEntitlement() on the SAME key their sibling manage/*.php page gates the equivalent write on, the nineteen swaps genuinely replaced (not merely supplemented) the bare role check, admin_user_create correctly KEEPS its bare check in the established E1 AND-shape, and every entitlement's live default is proven to be exactly ['admin','global_admin'] — today's admitted set is unchanged. The #2086 addition (song_revisions / admin_pending_revisions) is proven separately: both now call userHasEntitlement('verify_songs'), matching manage/revisions.php's own gate, with song_revisions confirmed behaviour-neutral and admin_pending_revisions confirmed as a deliberate, explicit widening rather than a silent one.\n";
exit(0);
