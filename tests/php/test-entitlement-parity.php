<?php

declare(strict_types=1);

/**
 * iHymns — `userHasEntitlement()` ANSWERS what the two maps promise (#1590 E3)
 * ===========================================================================
 *
 * ELI5
 * ----
 * Permissions are written down twice: once for the server (PHP) and once for the
 * browser (JS). If the two copies ever disagree, a button vanishes for someone
 * who is allowed to press it, or appears for someone who is not. This file asks
 * the REAL server function "can a <role> do <thing>?" for every role and every
 * thing, and checks each answer against the browser's copy.
 *
 * WHY THIS FILE EXISTS ALONGSIDE tests/test-entitlement-parity.js
 * ---------------------------------------------------------------
 * The node guard (#1641 item 3) compares the two maps by READING BOTH FILES. It
 * is a good check and it stays. But it can only ever prove that two arrays of
 * text agree — and this branch has now shipped, four rounds running, a guard
 * that was green while the thing it guarded was broken. The write-up of that
 * regress names the cause exactly:
 *
 *     Source inspection was used as primary evidence for properties that have a
 *     runtime handle.
 *
 * `userHasEntitlement($key, $role)` is a PURE function with no I/O in its own
 * body. Replace its `in_array($role, $allowed, true)` with `return true;` and
 * every role gains every permission — and the node guard stays green, because
 * both map literals still read exactly as before. That is the same defeat
 * `test-transaction-fatal.php` (commit c74d4612) was written to end, applied to
 * the security-critical function this batch just wired into eleven new call
 * sites. So this file never asks what the PHP source SAYS. It CALLS the function
 * and asserts the booleans that come back.
 *
 * HERMETIC ON PURPOSE
 * -------------------
 * `userHasEntitlement()` reads `effectiveEntitlements()`, which merges any
 * operator overrides stored in `tblAppSettings`. A test that hit a real database
 * would pass or fail depending on what that install's operator had ticked —
 * i.e. it would fail on correct code, which per CLAUDE.md rule #34 is how a
 * guard gets weakened or deleted rather than fixed. So the memo global that
 * `effectiveEntitlements()` fills lazily is PRIMED with the shipped defaults
 * before the first call. The function is still genuinely called; only its data
 * source is pinned. That the priming works is not assumed — MUT-4 below proves
 * it by priming a deliberately wrong map and watching the answers change.
 *
 * WHAT IT ASSERTS
 *   1. The JS mirror parses to a plausible, COMPLETE map (every entry line the
 *      stripper leaves behind is accounted for — a parser that silently drops
 *      entries would make every comparison below pass vacuously).
 *   2. Key sets are equal in both directions.
 *   3. For every key x every role, the PHP FUNCTION'S ANSWER equals the JS
 *      mirror's answer. Differences are reported key-by-key, role-by-role.
 *   4. The function's own contract: unknown key fails closed, empty/null role
 *      fails closed, role matching is exact (not a hierarchy, not case-folded).
 *   5. The #1590 E1 equivalence baseline — for each entitlement wired in Batch 6,
 *      the default map admits EXACTLY the set of roles the raw role gate it
 *      replaced admitted. This is the security review made mechanical: change
 *      one of those defaults and the build says which live gate it moves.
 *   6. Mutation self-tests, run in memory on every invocation (rule #34: prove
 *      the detectors can still fire).
 *
 *   php tests/php/test-entitlement-parity.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/entitlements.php
 * @see appWeb/public_html/js/modules/entitlements.js
 * @see tests/test-entitlement-parity.js  (the source-comparison half)
 * @see tests/php/test-transaction-fatal.php  (the worked example of this shape)
 * @link https://www.php.net/manual/en/function.in-array.php
 */

$ROOT = dirname(__DIR__, 2);
$PUB  = $ROOT . '/appWeb/public_html';

require_once $PUB . '/includes/entitlements.php';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        " . str_replace("\n", "\n        ", $detail) . "\n"; }
    }
}

/* =========================================================================
 * The JS mirror, parsed as DATA
 * ========================================================================= */

/**
 * Strip JS comments.
 *
 * Both maps annotate entries with prose that NAMES keys and roles — this very
 * batch added a paragraph to the JS map explaining why `delete_songs` moved to
 * editor+. Parsing raw source would invent entries out of that commentary, so
 * the stripper runs first and everything downstream sees code only.
 */
function entParityStripComments(string $s): string
{
    $s = preg_replace('#/\*[\s\S]*?\*/#', '', $s) ?? $s;   /* block comments */
    $s = preg_replace('#(^|[^:])//.*$#m', '$1', $s) ?? $s; /* line comments  */
    return $s;
}

/**
 * Parse `export const ENTITLEMENTS = { … };` into key => sorted role list.
 *
 * Returns the parsed map AND the entry lines it saw, so the caller can prove the
 * parse was COMPLETE rather than merely non-empty. A scanner that under-reports
 * is worse than no scanner, because its tick is read as coverage (rule #34) —
 * and every comparison in this file is vacuous if a key silently goes missing.
 *
 * @return array{map: array<string, array<int,string>>, entryLines: int, parsed: int}
 */
function entParityParseJs(string $source): array
{
    $body = '';
    if (preg_match('/ENTITLEMENTS\s*=\s*\{([\s\S]*?)\n\};/', $source, $m) === 1) {
        $body = entParityStripComments($m[1]);
    }

    $map = [];
    if (preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\[([^\]]*)\]/m', $body, $rows, PREG_SET_ORDER)) {
        foreach ($rows as $r) {
            preg_match_all("/'([^']*)'/", $r[2], $roles);
            $list = $roles[1] ?? [];
            sort($list);
            $map[$r[1]] = $list;
        }
    }

    /* Completeness: every line that opens an entry must have produced a key.
       `[A-Za-z_]` at line start after comment-stripping is an entry opener in
       this file's style; anything else is blank, a closing brace, or a
       continuation of a role list. */
    $entryLines = 0;
    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^\s*[A-Za-z_][A-Za-z0-9_]*\s*:/', $line) === 1) { $entryLines++; }
    }

    return ['map' => $map, 'entryLines' => $entryLines, 'parsed' => count($map)];
}

$jsSource = (string)file_get_contents($PUB . '/js/modules/entitlements.js');
$js       = entParityParseJs($jsSource);
$jsMap    = $js['map'];

/* =========================================================================
 * Pin the PHP side to the SHIPPED DEFAULTS (see "hermetic on purpose")
 * ========================================================================= */

$GLOBALS['_ihymns_effective_entitlements'] = ENTITLEMENTS;

echo "\n#1590 E3 — entitlement behaviour parity (PHP function vs JS mirror)\n";
echo "  PHP map: " . count(ENTITLEMENTS) . " keys · JS map: " . count($jsMap) . " keys\n\n";

/* =========================================================================
 * 1 — the parse is plausible AND complete
 * ========================================================================= */

ok('the JS mirror parsed at all', $jsMap !== [],
   'ENTITLEMENTS = { … }; did not match — fix the extraction before reading anything below');
ok('the JS parse is COMPLETE (every entry line produced a key)',
   $js['entryLines'] === $js['parsed'],
   "entry lines seen: {$js['entryLines']}, keys parsed: {$js['parsed']} — the regex is dropping entries");
ok('both maps hold a plausible number of keys (> 20 each)',
   count(ENTITLEMENTS) > 20 && count($jsMap) > 20,
   'PHP ' . count(ENTITLEMENTS) . ', JS ' . count($jsMap));

/* Role universe, DERIVED from the data rather than typed: whatever roles the
   two maps actually mention. A role nobody grants anything cannot be compared
   and does not need to be. */
$roles = [];
foreach (ENTITLEMENTS as $list) { foreach ($list as $r) { $roles[$r] = true; } }
foreach ($jsMap as $list) { foreach ($list as $r) { $roles[$r] = true; } }
$roles = array_keys($roles);
sort($roles);
ok('a plausible role universe was derived from the maps (>= 3 roles)', count($roles) >= 3,
   implode(', ', $roles));
echo "  Roles under test: " . implode(', ', $roles) . "\n\n";

/* =========================================================================
 * 2 — key sets agree in both directions
 * ========================================================================= */

$onlyPhp = array_values(array_diff(array_keys(ENTITLEMENTS), array_keys($jsMap)));
$onlyJs  = array_values(array_diff(array_keys($jsMap), array_keys(ENTITLEMENTS)));

ok('no PHP-only keys (a missing JS key silently never renders its affordance)',
   $onlyPhp === [], 'missing from JS: ' . implode(', ', $onlyPhp));
ok('no JS-only keys (a JS-only key offers what the server will refuse)',
   $onlyJs === [], 'missing from PHP: ' . implode(', ', $onlyJs));

/* =========================================================================
 * 3 — THE POINT OF THIS FILE: the PHP function's ANSWERS vs the JS mirror
 * ========================================================================= */

/**
 * Compare `userHasEntitlement()`'s answers against a role map, cell by cell.
 *
 * Shared by the real check and by the mutation self-tests, so the mutations
 * exercise the SAME comparator that runs for real — two copies would be two
 * rules and the mutation would stop proving anything (rule #35).
 *
 * @param array<string, array<int,string>> $expected key => roles
 * @param array<int,string>                $roles    role universe
 * @return array<int,string>                         human-readable differences
 */
function entParityDiffAnswers(array $expected, array $roles): array
{
    $diffs = [];
    foreach ($expected as $key => $allowed) {
        foreach ($roles as $role) {
            $want = in_array($role, $allowed, true);
            $got  = userHasEntitlement($key, $role);
            if ($got !== $want) {
                $diffs[] = sprintf('%s / %s — expected %s, userHasEntitlement() said %s',
                    $key, $role, $want ? 'ALLOW' : 'DENY', $got ? 'ALLOW' : 'DENY');
            }
        }
    }
    return $diffs;
}

$sharedKeys = array_intersect_key($jsMap, ENTITLEMENTS);
$cellDiffs  = entParityDiffAnswers($sharedKeys, $roles);

ok(sprintf('every key x role cell agrees (%d keys x %d roles = %d answers, all from userHasEntitlement())',
           count($sharedKeys), count($roles), count($sharedKeys) * count($roles)),
   $cellDiffs === [],
   implode("\n", $cellDiffs)
   . "\n\nincludes/entitlements.php is the AUTHORITY; js/modules/entitlements.js mirrors it."
   . "\nCopy the role list verbatim — the mirror is not a second opinion.");

/* =========================================================================
 * 4 — the function's own contract
 *
 * These are the properties the node guard structurally cannot see, because they
 * are behaviour rather than text. Each one is a way the wiring added in E1 could
 * be defeated without either map changing a character.
 * ========================================================================= */

$anyKey = array_key_first(ENTITLEMENTS);

ok('an unknown entitlement fails CLOSED',
   userHasEntitlement('no_such_entitlement_at_all', 'global_admin') === false,
   'a typo in a call site must deny, never grant');
ok('a null role fails CLOSED', userHasEntitlement($anyKey, null) === false);
ok('an empty-string role fails CLOSED', userHasEntitlement($anyKey, '') === false);
ok('an unknown role fails CLOSED', userHasEntitlement($anyKey, 'superuser') === false);
ok('role matching is CASE-SENSITIVE (Admin is not admin)',
   userHasEntitlement('view_users', 'Admin') === false,
   'a case-insensitive match would let a mis-cased role string through');
ok('role matching is EXACT, not a hierarchy — global_admin does not inherit an admin-only key',
   userHasEntitlement('manage_own_organisation', 'global_admin') === true
   && userHasEntitlement('run_db_install', 'admin') === false,
   'userHasEntitlement() is in_array(), not hasRole()/roleLevel(). E1 relies on that: '
   . '`assign_global_admin` => [global_admin] replaced an exact `role !== global_admin` test');

/* =========================================================================
 * 5 — THE #1590 E1 EQUIVALENCE BASELINE
 *
 * Batch 6 replaced raw role tests with entitlement checks. The whole batch was
 * allowed only because each new default admits EXACTLY the roles its old raw
 * gate admitted — "zero live behaviour change until an operator deliberately
 * overrides". That claim was verified once, by hand, against the source. Here it
 * is as a running assertion, so it stays true.
 *
 * If one of these fails, the build is telling you a DEFAULT MOVED — which is a
 * live privilege change on every install that has not saved /manage/entitlements.
 * That may be exactly what you intend; update the expectation in the SAME commit
 * and say so in the message. What must not happen is it moving unnoticed.
 * ========================================================================= */

$editorPlus = ['editor', 'admin', 'global_admin'];
$adminPlus  = ['admin', 'global_admin'];
$gaOnly     = ['global_admin'];

$e1Baseline = [
    'delete_songs' => [$editorPlus,
        'replaced the editor APIs\' file-level hasRole($role, "editor") gate '
        . '(manage/editor/api.php:47, api2.php:135). NOT admin+ — the plan believed delete was '
        . 'escalated to a raw admin role; it never was.'],
    'bulk_edit_songs' => [$editorPlus,
        'same editor-level file gate; wired on api2 bulk_verify / bulk_tag_attach / '
        . 'bulk_tag_detach and on v1 bulk_tag for multi-song calls'],
    'edit_users' => [$adminPlus,
        'manage/users.php already required view_users (admin+) to load, and api.php\'s '
        . 'admin_user_* actions tested in_array(Role, [admin, global_admin])'],
    'change_user_roles' => [$adminPlus, 'same two surfaces as edit_users'],
    'delete_users'      => [$adminPlus, 'same two surfaces as edit_users'],
    'assign_global_admin' => [$gaOnly,
        'replaced the exact test $currentUser[role] !== "global_admin" in the create and '
        . 'change_role branches (and its api.php twin)'],
    'run_db_migrate' => [$gaOnly,
        'setup-database.php gates its whole page load on run_db_install (global_admin); the '
        . 'new action check ANDs with that'],
    'run_db_restore' => [$gaOnly, 'same page gate as run_db_migrate'],
    'run_db_backup'  => [$gaOnly,
        'ALIGNED DOWN from admin+ this pass: an admin never reached the backup button because '
        . 'the page gate rejected them first, so the admin tick advertised a capability that '
        . 'did not exist'],
    'view_users' => [$adminPlus,
        'not new — the page gate wired in #1648. Pinned here because every per-action check '
        . 'added in E1 sits behind it, so its default is load-bearing for all of them'],
];

foreach ($e1Baseline as $key => [$expectedRoles, $why]) {
    $actual = ENTITLEMENTS[$key] ?? null;
    $got    = [];
    foreach ($roles as $r) { if (userHasEntitlement($key, $r)) { $got[] = $r; } }
    sort($got);
    $want = $expectedRoles;
    sort($want);
    ok("E1 baseline: {$key} admits exactly [" . implode(', ', $want) . ']',
       $actual !== null && $got === $want,
       $actual === null
           ? "the key no longer exists in the map — if it was renamed, rename it here too"
           : 'userHasEntitlement() now admits [' . implode(', ', $got) . "]\n"
             . "The raw gate this replaced: {$why}\n"
             . 'Moving this default is a live privilege change. Intend it, or revert it.');
}

/* =========================================================================
 * 6 — MUTATION SELF-TESTS (rule #34)
 *
 * In memory, on every run, never touching the tree. A guard whose first green
 * run was never challenged has repeatedly been silently wrong in this repo —
 * including several written in the same pass that wrote the rule.
 * ========================================================================= */

echo "\n  Mutation self-tests (this guard proving it can still fail):\n";

/* ⚠️ EVERY MUTATION BELOW IS ANCHORED ON CODE STRUCTURE, NEVER ON PROSE OR
 * WHITESPACE. That is not fussiness — the first version of this block anchored
 * MUT-2 on the exact text `view_users:           ['admin', 'global_admin'],` and
 * MUT-5 on a sentence from the file's doc-block, and a trial mutation that did
 * nothing but REWORD TWO COMMENTS turned the whole suite red. A guard that fails
 * on correct code gets weakened or deleted rather than fixed (rule #34), and
 * "somebody improved a comment" is about as correct as code gets. So: regex
 * anchors on declarations, targets derived from the parsed data, and a negative
 * control that needs no anchor at all.
 */

/** Insert $insert immediately after the map's opening brace, whatever its spacing. */
function entParityInjectIntoMap(string $source, string $insert): string
{
    return (string)preg_replace('/(ENTITLEMENTS\s*=\s*\{)/', '$1' . "\n" . $insert, $source, 1);
}

/* MUT-1 — a key added to the JS mirror only must be seen as JS-only. */
$m1Src     = entParityInjectIntoMap($jsSource, "    invented_key_m1:      ['admin', 'global_admin'],");
$m1        = entParityParseJs($m1Src)['map'];
$m1OnlyJs  = array_values(array_diff(array_keys($m1), array_keys(ENTITLEMENTS)));
/* Membership, not exact equality: if the REAL tree also has a JS-only key this
   self-test must still report on its own mutation rather than being drowned by
   the genuine failure it sits beside. */
ok('MUT-1: a key added to ONE map only is detected as JS-only',
   $m1Src !== $jsSource
   && in_array('invented_key_m1', $m1OnlyJs, true)
   && !in_array('invented_key_m1', array_keys($jsMap), true),
   $m1Src === $jsSource
       ? 'the mutation did not apply — no `ENTITLEMENTS = {` declaration was found'
       : 'JS-only keys seen: [' . implode(', ', $m1OnlyJs) . ']');

/* MUT-2 — one role changed in the JS mirror must surface as a cell difference,
   NOT merely as "both files mention the key".
   Target DERIVED from the parsed map (rule #34: derive the list, do not type it):
   the first key that does not already grant the first role, so the injection is
   guaranteed to change an answer no matter how the maps are edited later. */
$m2Key = null; $m2Role = null;
foreach ($jsMap as $k => $granted) {
    foreach ($roles as $r) {
        if (!in_array($r, $granted, true)) { $m2Key = $k; $m2Role = $r; break 2; }
    }
}
$m2Src = $m2Key === null ? $jsSource : (string)preg_replace(
    '/^(\s*' . preg_quote($m2Key, '/') . '\s*:\s*\[)/m', '$1\'' . $m2Role . '\', ', $jsSource, 1);
$m2Map   = entParityParseJs($m2Src)['map'];
$m2Diffs = entParityDiffAnswers(array_intersect_key($m2Map, ENTITLEMENTS), $roles);
/* Measured as "this diff appeared, and was not there before" rather than
   "exactly one diff exists". A real divergence elsewhere in the tree must not
   stop the self-test reporting on its own mutation — otherwise the detector goes
   quiet in precisely the situation it exists for. */
$m2New = array_values(array_diff($m2Diffs, $cellDiffs));
ok('MUT-2: granting one extra role in ONE map is detected cell-by-cell',
   $m2Key !== null && $m2Src !== $jsSource
   && $m2New === ["{$m2Key} / {$m2Role} — expected ALLOW, userHasEntitlement() said DENY"],
   $m2Key === null
       ? 'no key/role pair to mutate — every key grants every role, which cannot be right'
       : ($m2Src === $jsSource
           ? "the injection into `{$m2Key}` did not apply"
           : 'new diffs introduced by the mutation: ' . implode(' | ', $m2New)));

/* MUT-3 — commentary must not become data. A block comment that looks exactly
   like an entry is the shape that would otherwise invent keys, and both maps
   really do discuss keys in prose. Prepended to the whole file, so there is no
   anchor to go stale. */
$m3Src = "/* prose mentioning fake_key_m3: ['user', 'editor', 'admin'] in a comment */\n" . $jsSource;
$m3 = entParityParseJs($m3Src)['map'];
ok('MUT-3: a key named only inside a comment is NOT parsed as an entry',
   !isset($m3['fake_key_m3']) && count($m3) === count($jsMap),
   'parsed ' . count($m3) . ' keys (expected ' . count($jsMap) . ')');

/* MUT-4 — the load-bearing one. Proves the PHP side of every comparison above is
   the FUNCTION'S ANSWER and not a re-read of the file: prime the memo global
   with a wrong map and userHasEntitlement() must change its mind. If this fails,
   the priming is not reaching the function, the suite has been silently reading
   whatever the environment provides, and the hermetic claim in the header is
   false. */
$m4Saved = $GLOBALS['_ihymns_effective_entitlements'];
$GLOBALS['_ihymns_effective_entitlements'] = ['edit_songs' => ['user']];
$m4UserNowAllowed  = userHasEntitlement('edit_songs', 'user');
$m4EditorNowDenied = userHasEntitlement('edit_songs', 'editor') === false;
$m4UnknownNowDenied = userHasEntitlement('view_users', 'global_admin') === false;
$GLOBALS['_ihymns_effective_entitlements'] = $m4Saved;
ok('MUT-4: userHasEntitlement() really reads the primed map (so every answer above is the FUNCTION talking)',
   $m4UserNowAllowed && $m4EditorNowDenied && $m4UnknownNowDenied,
   'priming had no effect — the memo global has been renamed, or the function stopped '
   . 'consulting effectiveEntitlements(). Either way this suite is no longer hermetic.');
ok('MUT-4b: …and the real map is restored afterwards',
   userHasEntitlement('edit_songs', 'editor') === true
   && userHasEntitlement('view_users', 'global_admin') === true);

/* MUT-5 — the negative control, and the one that caught this file's own bug.
   Rewording prose must NOT move any answer; a guard that goes red on a comment
   edit gets weakened or deleted rather than fixed.
   Anchorless BY CONSTRUCTION: it rewrites every line comment and wraps the file
   in a new block comment header, so there is no sentence for a future editor to
   move out from under it. */
$m5Src = "/* MUT-5 negative control: a rewritten header block. */\n"
       . preg_replace('#(^|[^:])//(.*)$#m', '$1//$2 (reworded by MUT-5)', $jsSource);
$m5Map   = entParityParseJs($m5Src)['map'];
$m5Diffs = entParityDiffAnswers(array_intersect_key($m5Map, ENTITLEMENTS), $roles);
/* Compared against the BASELINE run, not against "no diffs at all": the property
   under test is that prose has no effect, which stays meaningful even when the
   tree genuinely diverges. Asserting emptiness here would make the negative
   control fail for reasons that have nothing to do with comments. */
ok('MUT-5: rewording comments changes nothing (the guard is narrow enough to keep)',
   $m5Src !== $jsSource && $m5Diffs === $cellDiffs && array_keys($m5Map) === array_keys($jsMap),
   'a comment edit moved an answer, or changed the key set: '
   . implode(' | ', array_diff($m5Diffs, $cellDiffs)));

/* MUT-6 — the E1 baseline must itself be able to fail. */
$m6Saved = $GLOBALS['_ihymns_effective_entitlements'];
$m6Map = ENTITLEMENTS;
$m6Map['delete_songs'] = ['admin', 'global_admin'];   /* the pre-alignment value */
$GLOBALS['_ihymns_effective_entitlements'] = $m6Map;
$m6EditorDenied = userHasEntitlement('delete_songs', 'editor') === false;
$GLOBALS['_ihymns_effective_entitlements'] = $m6Saved;
ok('MUT-6: reverting delete_songs to the pre-#1590 admin-only default is visible here',
   $m6EditorDenied && userHasEntitlement('delete_songs', 'editor') === true,
   'the E1 baseline block cannot detect a default moving back, so it is not guarding anything');

if ($fail === 0) {
    echo "\nEntitlement maps agree, and userHasEntitlement() answers accordingly.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
