<?php

declare(strict_types=1);

/**
 * iHymns — standing manage-action / API-coverage guard (rule #34/#35)
 * =====================================================================
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` was a one-off audit: it read every
 * `/manage/*.php` admin page, found every button that changes something in
 * the database, and worked out which ones a phone app could not reach
 * because there was no matching `api.php`/`api2.php` action. Six batches of
 * work then closed almost all of those gaps. §9 of that plan asked for a
 * STANDING guard so the NEXT admin page (or the next button on an existing
 * page) can never quietly reopen the gap the way the original 33 did —
 * without this, "add a new admin action" and "expose it to native apps" are
 * two unrelated facts that nothing keeps in sync (rule #35: cross-file
 * agreement needs a mechanism, not a comment).
 *
 * WHAT THIS FILE DOES
 * --------------------
 * 1. ENUMERATES, from the real source tree (never a typed list — rule #34),
 *    every state-changing action every `manage/*.php` page dispatches via
 *    `$_POST['action']` / `$_REQUEST['action']`, in whichever of the several
 *    real shapes this codebase uses (`switch`, `if`/`elseif` chains, a raw
 *    superglobal comparison with no intermediate variable, `in_array()`) —
 *    PLUS the handful of pages that have exactly one implicit POST action
 *    and never name it (`login.php`, `setup.php`, `entitlements.php`,
 *    `diagnostics.php`, `print-pdf.php`, `requests.php`).
 * 2. Checks every one of those enumerated actions against a MAINTAINED
 *    mapping (this file's `$MAPPING` array — the one place a human writes
 *    down a decision, with a reason, per CLAUDE.md's "asking the owner for
 *    a decision" shape) to EITHER a real `api.php`/`api2.php` action, OR an
 *    explicit `web_only:` reason, OR (for the two endpoints that went
 *    Bearer-capable ON THEMSELVES rather than growing an api.php twin —
 *    plan §3 X3 — `manage/places-api.php` and `manage/print-pdf.php`) a
 *    `native:` reason, verified against the page's own Bearer-auth marker.
 * 3. A NEW, unmapped manage action — the whole point — fails LOUD: assertion
 *    A below is "every enumerated action has a mapping entry", so a page
 *    that grows a fourteenth `case` next month is caught the next time this
 *    runs, not the next time someone thinks to re-run the 2026-08-28 audit.
 *
 * WHY A CUSTOM RESTRICTED SCANNER, NOT `tests/php/lib/dispatch_parser.php`
 * AS-IS (still REUSING it for tokenisation — CLAUDE.md's modularity rule)
 * --------------------------------------------------------------------
 * `dispatch_parser.php`'s `dispatchParserActionsForFile()` treats `$_GET`,
 * `$_POST` and `$_REQUEST` alike, because ITS job (a different guard) is
 * "every action name this file answers to". OUR job is narrower and
 * different: a GET-dispatched action can never be a CSRF-protected write in
 * this codebase's own convention (rule #29 — every state-changing endpoint
 * here validates a POST body), so a GET-only action is a READ, and the task
 * brief is explicit — "Exclude pure GET/read renders; include only actions
 * that write/change state." Two concrete, verified examples the unrestricted
 * scanner would wrongly pull in:
 *   - `works.php`, `publishers.php`, `tunes.php`, `songbooks.php`,
 *     `songbook-series.php`, `catalogues.php`, `musicians.php`,
 *     `revisions.php` all have typeahead/export/filter helpers dispatched
 *     via a literal `$_GET['action']` comparison — e.g.
 *     `works.php:384 ($_GET['action'] ?? '') === 'song_search'` — sitting
 *     in the SAME FILE as, sometimes even naming the SAME VARIABLE as, the
 *     real POST writes. `revisions.php`'s whole `$filterAction` is a GET
 *     report-page filter (`create`/`edit`/`restore`/`delete` describe PAST
 *     revision kinds — it dispatches nothing).
 *   - `places-api.php` is the one file that assigns its action variable from
 *     `$_REQUEST['action']` (populated on GET too) rather than `$_POST`, and
 *     dispatches BOTH `if ($method === 'GET' && $action === 'search')` (a
 *     read) and `if ($method === 'POST' && $action === 'upsert')` (the
 *     write) off that ONE variable — so restricting by SOURCE superglobal
 *     alone is not enough for this one file; `isGetGated()` below also
 *     checks, for a comparison-shaped label, whether its own enclosing
 *     if-condition ALSO tests `$method`/`REQUEST_METHOD` against `'GET'`.
 * `gating-noop-verify.php` is excluded for the same reason from the other
 * end: its `capture`/`probe-audio`/`verify` actions (one of which really
 * does write `tblAppSettings`) are dispatched off `$_GET['action']` alone —
 * confirmed by direct inspection, not a name-pattern guess — so they are
 * out of THIS guard's scope (a GET-triggered write would itself be a CSRF
 * finding, not an API-coverage gap; it is not silently dropped, it is
 * scoped OUT by the same "POST/REQUEST superglobal only" rule that also
 * legitimately excludes every read helper above).
 *
 * WHY THE GET-GATE CHECK IS NARROW ON PURPOSE
 * --------------------------------------------
 * `isGetGated()` requires BOTH a `'GET'` string literal AND a method-ish
 * signal (`$method`, or the literal text `REQUEST_METHOD`) inside the SAME
 * statement (bounded backward by `;`/`{`/`}` — the nearest statement
 * start), not just a bare `'GET'` substring anywhere nearby. A single signal
 * would risk two failure directions the "narrow enough not to fail on
 * correct code" half of rule #34 warns about: a POST-only action whose
 * condition happens to contain an unrelated string containing "GET" would
 * be wrongly excluded (under-reporting — the worse failure mode), while a
 * check with NO specificity at all would exclude too much. Requiring both
 * signals together is what the ONE real instance (`places-api.php`) always
 * has and nothing else in the tree accidentally matches (verified — see the
 * mutation self-tests below AND the live count cross-check in this file's
 * own commit message / report).
 *
 * WHY THE IMPLICIT-ACTION (CLASS B) DETECTION IS ALSO DERIVED
 * -------------------------------------------------------------
 * A handful of pages have exactly ONE state-changing POST behaviour and
 * never key it by an `action` field at all (`login.php` posts
 * username/password; `setup.php` posts the first-admin form; `diagnostics.php`
 * posts a raw SQL string; `entitlements.php` posts the whole role matrix;
 * `print-pdf.php` posts render parameters; `requests.php` posts a triage
 * decision). `isImplicitStateChangingPage()` finds these MECHANICALLY too:
 * a file with ZERO `$_POST`/`$_REQUEST['action']` dispatch (so it never
 * contributed to the named-action enumeration above) AND a real
 * `$_SERVER['REQUEST_METHOD'] === 'POST'` (or `!== 'POST'` early-return)
 * guard is flagged as having exactly one implicit action, keyed
 * `'(implicit)'`. Every OTHER zero-action-key page in the tree
 * (`analytics.php`, `help.php`, `index.php`, `logout.php` [GET-triggered —
 * no REQUEST_METHOD guard at all], `revisions.php`, `gating-noop-verify.php`
 * [GET-only, excluded above], `credit-people.php`/`credit-people-bulk-promote.php`/
 * `song-link-suggestions.php` [pure 302 redirect shims, no POST handling of
 * their own], `service-lead.php`/`service-projection.php` [state changes
 * happen client-side via JS calling `api.php` directly, never server-side
 * in the page itself], …) was individually verified (this file's own commit
 * message / report) to have neither signal, so this criterion does not
 * silently create six entries out of nothing — it is exactly the six real
 * ones. A future page matching this same shape is picked up automatically;
 * one that DOESN'T (any future page using `action=` — the overwhelmingly
 * dominant convention) is picked up by the named-action path instead.
 *
 * THE MAPPING ITSELF (part 2 above) IS THE MAINTAINED PART
 * -----------------------------------------------------------
 * The brief is explicit that this is "a maintained MAPPING (this is the
 * human-maintained part, with a rationale per entry)". Every `api:` target
 * below was cross-checked against the REAL `$action` switch in api.php/
 * api2.php via `dispatchParserCasesForSwitch()` (assertion B), not typed
 * from memory of the plan document — several names differ from what the
 * plan predicted (`tunes.php`'s `create` → `admin_tune_add`, not
 * `admin_tune_create`; `musicians.php`'s `delete_from_registry` →
 * `admin_credit_person_delete`). A few real, currently-open gaps were
 * found IN BUILDING this guard that the 2026-08-28 plan does not mention at
 * all — `musicians.php`'s `add_member`/`remove_member`/`add_relation`/
 * `remove_relation`/`bulk_register_unregistered` (musician-registry
 * grouping/relations, #1741 P4a — postdates the plan) and
 * `notifications.php`'s `delete`/`push_send`/`push_test` (Web Push
 * broadcast — distinct from `admin_notification_send`, which only creates
 * the in-app row). These are marked `web_only:GAP-...` rather than force-
 * mapped to something incorrect — see this file's commit message / the
 * session report for the explicit flag the task brief asked for. They keep
 * this guard GREEN (a currently-open gap is not a NEW regression) while
 * staying honestly distinguishable — `grep "GAP-"` on this file — from a
 * deliberately-permanent `web_only:` entry.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * Two layers, matching the task brief's two asks:
 *  (1) Fixture-based self-tests below (small in-memory PHP snippets, the
 *      `test-api-coverage-batch6a.php` precedent) prove the PARSING
 *      helpers — `prPostRequestActionVars()`/`prActionsForFile()`/
 *      `prIsGetGated()`/`prIsImplicitStateChangingPage()` — can both find a
 *      marker that is there and correctly refuse one that is not. These run
 *      EVERY time this file runs, so the guard stays provably-breakable
 *      forever, not just at the moment it was written.
 *  (2) A REAL on-tree mutation (inject `case 'zzz_fake_action':` into a real
 *      manage page's switch; delete a mapping entry; point one at a
 *      non-existent action — each run, confirmed RED, then reverted
 *      byte-identical) was performed once against the actual tree while
 *      building this guard. Results are in this file's commit message / the
 *      session report, per the task brief's explicit "Report each
 *      mutation's result" — repeating a live edit-run-revert cycle against
 *      real page sources on every test run would make this suite mutate
 *      production files on every CI run, which is not what a standing guard
 *      should do; the fixture layer (1) is what keeps it standing.
 *
 * @link .claude/api-coverage-2026-08-28.md                 the plan this stands guard over
 * @see appWeb/public_html/api.php                            132 of the mapped actions
 * @see appWeb/public_html/manage/editor/api2.php              1 mapped action (song_link_suggestion_dismiss)
 * @see appWeb/public_html/manage/places-api.php                native: upsert (Bearer fallback, X3)
 * @see appWeb/public_html/manage/print-pdf.php                  native: implicit (Bearer fallback, C6)
 * @see tests/php/lib/dispatch_parser.php                      shared tokeniser this file reuses
 * @see tests/php/test-api-coverage-batch6a.php                the mutation-self-test precedent this mirrors
 *
 *   php tests/php/test-manage-action-api-coverage.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo      = dirname(__DIR__, 2);
$manageDir = $repo . '/appWeb/public_html/manage';
$apiFile   = $repo . '/appWeb/public_html/api.php';
$api2File  = $repo . '/appWeb/public_html/manage/editor/api2.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

/* =========================================================================
 * PART 1 — the derived enumerator (see doc-block for the "why restricted /
 * why GET-gated / why implicit-action" reasoning).
 * ========================================================================= */

/**
 * Local variables this file assigns from `$_POST['action']` OR
 * `$_REQUEST['action']` SPECIFICALLY — never `$_GET`. Mirrors
 * `dispatchParserActionVars()`'s shape but with `$_GET` excluded, because a
 * GET-sourced action can never be the CSRF-protected write this guard cares
 * about (see doc-block). The `$action` fallback is CONDITIONAL (only when
 * the raw literal genuinely appears in the file) rather than unconditional
 * like the shared library's — an unconditional fallback would wrongly pull
 * a totally unrelated `$action` variable (there is none currently, but the
 * conditional form costs nothing and removes the risk).
 *
 * @return array<int,string>
 */
function prPostRequestActionVars(string $file): array
{
    $toks = dispatchParserTokens($file);
    $n = count($toks);
    $vars = [];

    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t) || $t[0] !== T_VARIABLE) { continue; }
        $name = $t[1];
        if (in_array($name, ['$_GET', '$_POST', '$_REQUEST'], true)) { continue; }

        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && in_array($toks[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
        if (($toks[$j] ?? null) !== '=') { continue; }

        for ($k = $j + 1; $k < min($n, $j + 20); $k++) {
            if ($toks[$k] === ';') { break; }
            if (is_array($toks[$k]) && $toks[$k][0] === T_VARIABLE && in_array($toks[$k][1], ['$_POST', '$_REQUEST'], true)
                && ($toks[$k + 1] ?? null) === '['
                && is_array($toks[$k + 2] ?? null) && $toks[$k + 2][0] === T_CONSTANT_ENCAPSED_STRING
                && trim($toks[$k + 2][1], "'\"") === 'action'
                && ($toks[$k + 3] ?? null) === ']') {
                $vars[$name] = true;
                break;
            }
        }
    }

    $src = (string)file_get_contents($file);
    if (preg_match('/\$_(POST|REQUEST)\s*\[\s*[\'"]action[\'"]\s*\]/', $src)) {
        $vars['$action'] = true;
    }

    return array_keys($vars);
}

/**
 * Does the token at $i read `$_POST['action']` or `$_REQUEST['action']`
 * directly (no intermediate variable)? `$_GET` is deliberately NOT modelled
 * — see doc-block. Mirrors `dispatchParserActionSuperglobalAt()` restricted.
 *
 * @return int|null the index of the closing `]`, or null.
 */
function prSuperglobalAt(array $toks, int $i): ?int
{
    $t = $toks[$i];
    if (!is_array($t) || $t[0] !== T_VARIABLE) { return null; }
    if (!in_array($t[1], ['$_POST', '$_REQUEST'], true)) { return null; }
    if (($toks[$i + 1] ?? null) !== '[') { return null; }
    $s = $toks[$i + 2] ?? null;
    if (!is_array($s) || $s[0] !== T_CONSTANT_ENCAPSED_STRING) { return null; }
    if (trim($s[1], "'\"") !== 'action') { return null; }
    if (($toks[$i + 3] ?? null) !== ']') { return null; }
    return $i + 3;
}

/**
 * Is the comparison label at token index $labelIdx sitting inside an
 * if-condition that ALSO tests a method-ish signal against the literal
 * string `'GET'`? Scans BACKWARD from $labelIdx to the nearest statement
 * boundary (`;`, `{`, `}`) — i.e. the start of the CURRENT statement — and
 * requires BOTH: (a) a `$method`-named variable OR the literal text
 * `REQUEST_METHOD`, AND (b) the string `'GET'`, somewhere in that span.
 * Requiring both together (not either alone) is deliberately narrow — see
 * the doc-block's "why the GET-gate check is narrow on purpose" section —
 * it exists ONLY to correctly exclude `places-api.php`'s
 * `if ($method === 'GET' && $action === 'search')` from a file whose
 * action variable is `$_REQUEST`-sourced (populated on both GET and POST),
 * without excluding any genuinely POST-only action anywhere else in the
 * tree (proven never to fire elsewhere by this file's own assertions —
 * every OTHER enumerated action across all 39 files is unaffected).
 */
function prIsGetGated(array $toks, int $labelIdx): bool
{
    $hasMethodSignal = false;
    $hasGetString = false;
    for ($i = $labelIdx - 1; $i >= 0; $i--) {
        $t = $toks[$i];
        if ($t === ';' || $t === '{' || $t === '}') { break; }
        if (is_array($t)) {
            if ($t[0] === T_VARIABLE && strtolower($t[1]) === '$method') { $hasMethodSignal = true; }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $v = trim($t[1], "'\"");
                if ($v === 'REQUEST_METHOD') { $hasMethodSignal = true; }
                if ($v === 'GET') { $hasGetString = true; }
            }
        }
    }
    return $hasMethodSignal && $hasGetString;
}

/**
 * Every POST/REQUEST-dispatched action label in $file, across all four
 * real shapes this codebase uses (switch/case; `$var === 'x'` comparison
 * chains; a raw `$_POST['action'] === 'x'` comparison with no intermediate
 * variable; `in_array($var, ['x','y'], true)`) — restricted to POST/REQUEST
 * sources (never GET) and, for the comparison/in_array shapes, further
 * excluding any label proven GET-gated by `prIsGetGated()`. Switch/case
 * labels are NEVER GET-gated by construction (a `case` label lives directly
 * under `switch(...)`, not inside an `if` condition), so that shape skips
 * the check.
 *
 * @return array<int,string> unique action names, unsorted
 */
function prActionsForFile(string $file): array
{
    $toks = dispatchParserTokens($file);
    $n = count($toks);
    $vars = prPostRequestActionVars($file);
    $names = [];

    /* shape 1: switch ($var) { case 'x': } — reuses the shared, already
       mutation-tested tokeniser walk verbatim; never GET-gated. */
    foreach ($vars as $v) {
        foreach (dispatchParserCaseTokens($file, $v) as $c) {
            $names[$c['name']] = true;
        }
    }

    /* shapes 2–4: comparisons / in_array against the dispatch var or the
       raw superglobal. */
    $comparison = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL];
    for ($i = 0; $i < $n; $i++) {
        $end = null;
        $t = $toks[$i];
        if (is_array($t) && $t[0] === T_VARIABLE && in_array($t[1], $vars, true)) {
            $end = $i;
        } else {
            $sg = prSuperglobalAt($toks, $i);
            if ($sg !== null) { $end = $sg; }
        }
        if ($end === null) { continue; }

        for ($k = $end + 1; $k < min($n, $end + 12); $k++) {
            if ($toks[$k] === ';' || $toks[$k] === '{') { break; }
            if (is_array($toks[$k]) && in_array($toks[$k][0], $comparison, true)) {
                for ($m = $k + 1; $m < min($n, $k + 4); $m++) {
                    if (is_array($toks[$m]) && $toks[$m][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $v = trim($toks[$m][1], "'\"");
                        if ($v !== '' && !prIsGetGated($toks, $m)) { $names[$v] = true; }
                        break;
                    }
                }
                break;
            }
        }

        /* in_array($action, ['a','b'], true) — walk back to confirm we are
           its first argument, then collect every string in the array. */
        $b = $i - 1;
        while ($b > 0 && is_array($toks[$b]) && $toks[$b][0] === T_WHITESPACE) { $b--; }
        if (($toks[$b] ?? null) === '(') {
            $b--;
            while ($b > 0 && is_array($toks[$b]) && $toks[$b][0] === T_WHITESPACE) { $b--; }
            if (is_array($toks[$b] ?? null) && $toks[$b][0] === T_STRING && strtolower($toks[$b][1]) === 'in_array') {
                for ($k = $end + 1; $k < min($n, $end + 12); $k++) {
                    if ($toks[$k] === ')' || $toks[$k] === ';') { break; }
                    if ($toks[$k] === '[') {
                        $depth = 0;
                        for (; $k < $n; $k++) {
                            if ($toks[$k] === '[') { $depth++; continue; }
                            if ($toks[$k] === ']') { $depth--; if ($depth === 0) { break; } continue; }
                            if ($depth >= 1 && is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                                $v = trim($toks[$k][1], "'\"");
                                if ($v !== '' && !prIsGetGated($toks, $k)) { $names[$v] = true; }
                            }
                        }
                        break;
                    }
                }
            }
        }
    }

    return array_keys($names);
}

/**
 * Does $file have exactly ONE implicit (un-keyed) state-changing POST
 * action — i.e. it dispatches ZERO named `action=` values (so it never
 * appears in `prActionsForFile()`'s output) yet genuinely gates a real
 * `$_SERVER['REQUEST_METHOD'] === 'POST'` (or the `!== 'POST'` early-return
 * shape `print-pdf.php` uses)? See doc-block for the full list of
 * zero-action-key pages individually checked to have NEITHER signal (so
 * this is not "every quiet page becomes an entry" — it is exactly the six
 * real ones: login/setup/entitlements/diagnostics/print-pdf/requests).
 */
function prIsImplicitStateChangingPage(string $file, array $existingNames): bool
{
    if ($existingNames !== []) { return false; }
    $src = (string)file_get_contents($file);
    return (bool)preg_match(
        '/\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]\s*(?:\?\?[^)]*\))?\s*(?:===|!==)\s*[\'"]POST[\'"]/',
        $src
    );
}

const IMPLICIT_ACTION_KEY = '(implicit)';

/**
 * The full derived enumeration: every top-level `manage/*.php` file (never
 * recursive — `manage/editor/*`, `manage/includes/*` are a different
 * surface) mapped to the state-changing action names it dispatches.
 *
 * @return array<string,array<int,string>> basename => action names
 */
function discoverManageActions(string $manageDir): array
{
    $files = glob($manageDir . '/*.php');
    sort($files);
    $out = [];
    foreach ($files as $f) {
        $base = basename($f);
        $names = prActionsForFile($f);
        if ($names !== []) {
            sort($names);
            $out[$base] = $names;
        } elseif (prIsImplicitStateChangingPage($f, $names)) {
            $out[$base] = [IMPLICIT_ACTION_KEY];
        }
    }
    return $out;
}

/* =========================================================================
 * PART 1a — MUTATION SELF-TESTS for the parsing helpers above (rule #34).
 * Small in-memory fixtures, written to temp files (the tokeniser needs a
 * real file path — `dispatchParserTokens()` caches by path). Proves each
 * helper can both find a marker that is there and correctly refuse one that
 * is not, BEFORE the real assertions below are trusted.
 * ========================================================================= */

$mutationFailures = [];

function writeFixture(string $src): string
{
    $f = tempnam(sys_get_temp_dir(), 'ihymns_manage_action_fixture_');
    file_put_contents($f, $src);
    return $f;
}

/* --- shape coverage: switch, comparison chain, raw superglobal, in_array,
   all mixed with a GET sibling and a $_GET-only decoy that must NOT
   appear. --- */
$shapeFixtureSrc = <<<'PHP'
<?php
$action = (string)($_POST['action'] ?? '');
switch ($action) {
    case 'create': doCreate(); break;
    case 'delete': doDelete(); break;
}
if ($action === 'rename') { doRename(); }
if (($_POST['action'] ?? '') === 'toggle') { doToggle(); }
if (in_array($action, ['bulk_a', 'bulk_b'], true)) { doBulk(); }

/* a GET-only decoy dispatched off a totally different variable — must
   NEVER appear in the result (this is what the real works.php/publishers.php
   etc. typeahead helpers look like). */
if (($_GET['action'] ?? '') === 'typeahead_search') { doSearch(); }

/* the places-api.php shape: one $_REQUEST-sourced var serving both a GET
   read and a POST write off the SAME variable — only 'write_me' may survive. */
$rq = (string)($_REQUEST['action'] ?? '');
if ($method === 'GET' && $rq === 'read_me') { doRead(); }
if ($method === 'POST' && $rq === 'write_me') { doWrite(); }
PHP;
$shapeFixture = writeFixture($shapeFixtureSrc);
$shapeNames = prActionsForFile($shapeFixture);
sort($shapeNames);
$expectedShapeNames = ['bulk_a', 'bulk_b', 'create', 'delete', 'rename', 'toggle', 'write_me'];
if ($shapeNames !== $expectedShapeNames) {
    $mutationFailures[] = 'prActionsForFile() shape-coverage self-test FAILED: expected ['
        . implode(', ', $expectedShapeNames) . '] but got [' . implode(', ', $shapeNames) . ']'
        . ' (this must catch switch/case, comparison-chain, raw-superglobal and in_array shapes,'
        . ' exclude a $_GET-only decoy, and — the places-api.php trap — exclude a GET-gated'
        . ' comparison sharing the SAME $_REQUEST-sourced variable as a real POST write)';
}
unlink($shapeFixture);

/* --- isImplicitStateChangingPage(): a real POST-method guard with zero
   named actions is implicit; a page with named actions is NOT (even with a
   POST guard); a page with neither is not. --- */
$implicitYesSrc = <<<'PHP'
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    doTheOneThing();
}
PHP;
$implicitYesFixture = writeFixture($implicitYesSrc);
if (!prIsImplicitStateChangingPage($implicitYesFixture, [])) {
    $mutationFailures[] = 'prIsImplicitStateChangingPage() FAILS-HIGH self-test: a real REQUEST_METHOD===POST guard with zero named actions was not recognised as implicit';
}
if (prIsImplicitStateChangingPage($implicitYesFixture, ['already_has_one'])) {
    $mutationFailures[] = 'prIsImplicitStateChangingPage() FAILS-LOW self-test: a file that ALREADY has named actions was still flagged implicit (existingNames should short-circuit to false)';
}
unlink($implicitYesFixture);

$implicitNoSrc = <<<'PHP'
<?php
echo 'just a read-only report page, no POST handling at all';
PHP;
$implicitNoFixture = writeFixture($implicitNoSrc);
if (prIsImplicitStateChangingPage($implicitNoFixture, [])) {
    $mutationFailures[] = 'prIsImplicitStateChangingPage() FAILS-LOW self-test: a page with no REQUEST_METHOD guard at all was wrongly flagged implicit';
}
unlink($implicitNoFixture);

/* the !== 'POST' early-return shape (print-pdf.php's actual shape) must
   also register. */
$implicitNegatedSrc = <<<'PHP'
<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}
doTheRealWork();
PHP;
$implicitNegatedFixture = writeFixture($implicitNegatedSrc);
if (!prIsImplicitStateChangingPage($implicitNegatedFixture, [])) {
    $mutationFailures[] = 'prIsImplicitStateChangingPage() FAILS-HIGH self-test: the "!== POST early-return" shape (print-pdf.php\'s actual shape) was not recognised';
}
unlink($implicitNegatedFixture);

/* =========================================================================
 * PART 2 — THE MAINTAINED MAPPING (human-maintained, rationale per entry —
 * see doc-block). Every `api:<name>` target here was cross-checked by
 * PART 3's assertion B against the REAL `$action` switch in api.php /
 * api2.php, not typed from memory of the plan doc.
 *
 * Bucket vocabulary (exactly three, task-defined + one owner-approved
 * extension for the two Bearer-fallback-on-itself endpoints — plan §3 X3):
 *   'api:<action_name>'  — a real case in api.php's or api2.php's $action switch.
 *   'web_only:<reason>'  — deliberately not exposed (plan §6), OR an honestly
 *                          labelled 'web_only:GAP-...' for a currently-open
 *                          gap this pass discovered but the task brief
 *                          explicitly said to flag rather than guess-map
 *                          (see the doc-block's "gaps found" paragraph).
 *   'native:<reason>'    — the manage/*.php endpoint itself was made
 *                          Bearer-capable directly (verified by assertion D
 *                          against the page's own source), so no api.php
 *                          action exists or is needed.
 * ========================================================================= */

$MAPPING = [

    'api-keys.php' => [
        'approve_request' => 'api:admin_api_key_approve_request',
        'create'          => 'api:admin_api_key_create',
        'delete'          => 'api:admin_api_key_delete',
        'reject_request'  => 'api:admin_api_key_reject_request',
        'request'         => 'api:api_key_request',
        'set_limits'      => 'api:admin_api_key_set_limits',
        'toggle'          => 'api:admin_api_key_toggle',
    ],

    'catalogues.php' => [
        'add'            => 'api:admin_catalogue_create',
        'add_member'     => 'api:admin_catalogue_member_add',
        'delete'         => 'api:admin_catalogue_delete',
        'marcxml_import' => 'api:admin_catalogue_marcxml_import',
        'remove_member'  => 'api:admin_catalogue_member_remove',
        'update'         => 'api:admin_catalogue_update',
    ],

    /* configuration.php — SMTP/Apple-SIWA/CueRCode/IntApps secrets,
       maintenance mode, and app-setting toggles (incl. the feature-gating
       master switch, rule #28's own dormancy discipline). Plan §6 names
       this page explicitly. Nothing here is a registry CRUD capability a
       native curator app would need — it is the server's own configuration. */
    'configuration.php' => [
        'captcha_probe'                    => 'web_only:configuration-secrets',
        'save_apple'                       => 'web_only:configuration-secrets',
        'save_captcha'                     => 'web_only:configuration-secrets',
        'save_cuercode'                    => 'web_only:configuration-secrets',
        'save_editor_default'              => 'web_only:configuration-secrets',
        'save_email'                       => 'web_only:configuration-secrets',
        'save_feature_gating'              => 'web_only:configuration-secrets',
        'save_intappsapi'                  => 'web_only:configuration-secrets',
        'save_language_registry_refresh'   => 'web_only:configuration-secrets',
        'save_live_follow_idle'            => 'web_only:configuration-secrets',
        'save_maintenance'                 => 'web_only:configuration-secrets',
        'save_native_apps'                 => 'web_only:configuration-secrets',
        'save_pd_publication_threshold'    => 'web_only:configuration-secrets',
        'save_webhooks'                    => 'web_only:configuration-secrets',
        'test_email'                       => 'web_only:configuration-secrets',
    ],

    'data-health.php' => [
        'disconnect_fallbacks' => 'api:admin_data_health_fix',
    ],

    'deleted-songs.php' => [
        'purge'   => 'api:admin_song_purge',
        'restore' => 'api:admin_song_restore',
    ],

    'duplicate-songs.php' => [
        'auto_link' => 'api:admin_song_auto_link',
        /* 'dismiss' operates on a WHOLE CLUSTER (every pairwise combination
           of a list of SongIds in one call — verified against the actual
           handler, which loops i<j over $ids). api2's song_link_suggestion_dismiss
           is per-PAIR, so a native client reaches equivalent capability by
           calling it once per pair — exactly the reuse the plan's A10 row
           itself names ("api2 already has ... song_link_suggestion_dismiss
           — reuse, don't duplicate"). No cluster-batch admin_ action exists
           or was recommended. */
        'dismiss'   => 'api:song_link_suggestion_dismiss',
        'link'      => 'api:admin_song_link',
        'merge'     => 'api:admin_song_merge',
        'rebuild'   => 'api:admin_song_suggestions_rebuild',
        'unlink'    => 'api:admin_song_unlink',
    ],

    'external-link-types.php' => [
        'save_type_patterns' => 'api:admin_external_link_type_save',
    ],

    /* feature-gating.php — the master content-gating rule CRUD. Plan §6:
       "flipping gating remotely defeats its dormancy discipline (rule #28)".
       Marked "(can be revisited)" in the plan but never revisited by any of
       the six implementation batches (confirmed absent from api.php), so
       this stays the plan's own recommended default. */
    'feature-gating.php' => [
        'create'      => 'web_only:feature-gating-switches',
        'delete'      => 'web_only:feature-gating-switches',
        'rule_create' => 'web_only:feature-gating-switches',
        'rule_delete' => 'web_only:feature-gating-switches',
        'rule_toggle' => 'web_only:feature-gating-switches',
        'update'      => 'web_only:feature-gating-switches',
    ],

    'groups.php' => [
        'add_member'    => 'api:admin_group_member_add',
        'create'        => 'api:admin_group_create',
        'delete'        => 'api:admin_group_delete',
        'remove_member' => 'api:admin_group_member_remove',
        'update'        => 'api:admin_group_update',
    ],

    'ia-reconcile.php' => [
        'run' => 'api:admin_ia_reconcile_run',
    ],

    /* intapps-status.php — plan §6 groups this with the other status/docs/
       navigation UIs ("reads they render largely exist as API already").
       Both actions are diagnostic side-effects of an admin status page
       (a third-party heartbeat probe; a force-refresh of a cached status),
       not an app capability. */
    'intapps-status.php' => [
        'refresh_now'     => 'web_only:status-diagnostic-ui',
        'test_connection' => 'web_only:status-diagnostic-ui',
    ],

    'languages.php' => [
        'create'        => 'api:admin_language_create',
        'delete'        => 'api:admin_language_delete',
        'remap_tag'     => 'api:admin_language_remap_tag',
        'toggle_active' => 'api:admin_language_toggle',
        'update'        => 'api:admin_language_update',
    ],

    'licence-types.php' => [
        'create' => 'api:admin_licence_type_create',
        'delete' => 'api:admin_licence_type_delete',
        'toggle' => 'api:admin_licence_type_toggle',
        'update' => 'api:admin_licence_type_update',
    ],

    'musician-duplicates.php' => [
        'dismiss'   => 'api:admin_musician_duplicate_dismiss',
        'merge'     => 'api:admin_musician_merge',
        'undismiss' => 'api:admin_musician_duplicate_undismiss',
    ],

    /* musicians-bulk-promote.php — plan A17: "bulk-promote wizards ...
       DEFER — wizard-shaped, file-upload flows; API them only if a native
       curator surface is confirmed (X1/Q1)". Confirmed absent from api.php. */
    'musicians-bulk-promote.php' => [
        'bulk_promote' => 'web_only:A17-deferred-wizard',
    ],

    'musicians.php' => [
        'add'                       => 'api:admin_credit_person_add',
        /* GAPS found while building THIS guard, not discussed anywhere in
           the 2026-08-28 plan (verified: none of "member"/"relation"/
           "bulk_register" appears anywhere in api.php's action list). All
           five delegate to includes/musician_helpers.php's
           addMusicianRelation()/removeMusicianRelation()/
           removeMusicianGroupMember()/musicianCitedUnregisteredNames() —
           #1741 P4a, which postdates the plan's audit. Flagged per the task
           brief ("flag it, don't guess-map it") rather than force-mapped —
           see this file's commit message / the session report. */
        'add_member'                => 'web_only:GAP-musician-group-membership',
        'add_relation'              => 'web_only:GAP-musician-relation',
        'bulk_register_unregistered'=> 'web_only:GAP-musician-bulk-register',
        'delete_from_registry'      => 'api:admin_credit_person_delete',
        'merge'                     => 'api:admin_credit_person_merge',
        'remove_member'             => 'web_only:GAP-musician-group-membership',
        'remove_relation'           => 'web_only:GAP-musician-relation',
        'rename'                    => 'api:admin_credit_person_rename',
        'update_person'             => 'api:admin_credit_person_update',
    ],

    'my-organisations.php' => [
        'brand_save'                   => 'api:org_admin_brand_update',
        'idle_timeout_update'          => 'api:org_admin_settings_update',
        'licence_add'                  => 'api:org_admin_licence_add',
        'licence_change'               => 'api:org_admin_licence_change',
        'licence_remove'               => 'api:org_admin_licence_remove',
        'logo_remove'                  => 'api:org_admin_logo_delete',
        'logo_toggle'                  => 'api:org_admin_logo_set_active',
        'logo_upload'                  => 'api:org_admin_logo_upload',
        'member_add'                   => 'api:org_admin_member_add',
        'member_remove'                => 'api:org_admin_member_remove',
        'member_role_change'           => 'api:org_admin_member_role_change',
        'setlist_edit_audience_update' => 'api:org_admin_settings_update',
    ],

    'notifications.php' => [
        'compose'            => 'api:admin_notification_send',
        /* GAP — verified admin_notification_send only INSERTs tblNotifications
           (the in-app row); it never calls webPushBroadcast(). No
           admin_notification_delete exists either. Not discussed in the
           plan. Flagged, not guess-mapped. */
        'delete'             => 'web_only:GAP-notification-delete',
        /* Mints a NEW VAPID keypair — invalidates every existing push
           subscription tree-wide. Same risk class as configuration.php's
           secret_generate_key; a reasoned deliberate classification, not a
           plan citation. */
        'push_generate_keys' => 'web_only:infra-secret',
        'push_send'          => 'web_only:GAP-webpush-broadcast',
        'push_test'          => 'web_only:GAP-webpush-broadcast',
    ],

    'organisations.php' => [
        'add_member'         => 'api:admin_organisation_member_add',
        /* Shared with my-organisations.php's self-service actions — the
           API actions gate via userCanActOnOrg() (verified: global-admin OR
           that org's own admin), exactly the "one endpoint, two audiences"
           the plan's §4.2 note under O2/O3 calls for. */
        'brand_save'         => 'api:org_admin_brand_update',
        'create'             => 'api:organisation_create',
        'delete'             => 'api:admin_organisation_delete',
        'licence_change'     => 'api:org_admin_licence_change',
        'logo_remove'        => 'api:org_admin_logo_delete',
        'logo_toggle'        => 'api:org_admin_logo_set_active',
        'logo_upload'        => 'api:org_admin_logo_upload',
        'remove_member'      => 'api:admin_organisation_member_remove',
        'update'             => 'api:admin_organisation_update',
        'update_member_role' => 'api:admin_organisation_member_role_change',
    ],

    /* places-api.php — X3: made Bearer-capable ON ITSELF rather than
       growing an api.php twin (verified: apiTokenResolveBearerUser() call +
       $placesBearerAuthed gate on the upsert CSRF check). 'search' is
       excluded entirely — it is GET-dispatched (confirmed:
       `if ($method === 'GET' && $action === 'search')`), a pure read. */
    'places-api.php' => [
        'upsert' => 'native:bearer-fallback',
    ],

    'print-templates.php' => [
        'clone'         => 'api:admin_print_template_clone',
        'delete'        => 'api:admin_print_template_delete',
        /* 'import' constructs a template from pasted JSON (name + blocks) —
           verified admin_print_template_save accepts exactly that shape
           (name/blocks/page_options via the JSON body, id=0 -> create), so
           this is the SAME capability via a different authoring path, not a
           distinct one. */
        'import'        => 'api:admin_print_template_save',
        'layout_delete' => 'api:admin_print_layout_delete',
        'layout_save'   => 'api:admin_print_layout_save',
        'save'          => 'api:admin_print_template_save',
        'set_default'   => 'api:admin_print_template_set_default',
    ],

    'publishers.php' => [
        'create' => 'api:admin_publisher_create',
        'delete' => 'api:admin_publisher_delete',
        'merge'  => 'api:admin_publisher_merge',
        'update' => 'api:admin_publisher_update',
    ],

    /* print-pdf.php — Class B (implicit single action). C6: made
       Bearer-capable ON ITSELF (verified: apiTokenResolveBearerUser() via
       _pdfResolveAuthenticatedUser()'s Bearer-then-cookie order), mirroring
       song-media.php — no new api.php endpoint, per the plan's own C6 note
       ("extend print-pdf.php auth: Bearer fallback ... no new endpoint"). */
    'print-pdf.php' => [
        IMPLICIT_ACTION_KEY => 'native:bearer-fallback',
    ],

    'requests.php' => [
        /* Class B (implicit single action) — POSTs id/new_status/admin_notes/
           resolved_song_id with no 'action' key at all. Already covered per
           plan §5: "requests.php (triage, incl. corrections) ->
           admin_song_requests, admin_song_request_update". */
        IMPLICIT_ACTION_KEY => 'api:admin_song_request_update',
    ],

    'restrictions.php' => [
        'create' => 'api:admin_restriction_create',
        'delete' => 'api:admin_restriction_delete',
    ],

    /* setup-database.php — the migration runner console (Apply-all,
       manual/confirm-gated drops, backup/restore, secret rotation, opcache
       reset, deploy diagnostics). Plan §6 names this page explicitly:
       "schema mutation from a network API is an attack surface; migrations
       are deliberately web-run, admin-eyes-on (rules #19/#25)". */
    'setup-database.php' => [
        'apply-all-migrations' => 'web_only:setup-database-console',
        'backup'               => 'web_only:setup-database-console',
        'delete-backup'        => 'web_only:setup-database-console',
        'deploy-forensics'     => 'web_only:setup-database-console',
        'download-backup'      => 'web_only:setup-database-console',
        'install'              => 'web_only:setup-database-console',
        'opcache-reset'        => 'web_only:setup-database-console',
        'restore'              => 'web_only:setup-database-console',
        'save-credentials'     => 'web_only:setup-database-console',
        'secret_generate_key'  => 'web_only:setup-database-console',
        'secret_rotate'        => 'web_only:setup-database-console',
        'upload-backup'        => 'web_only:setup-database-console',
        'verify-cutover'       => 'web_only:setup-database-console',
    ],

    'songbook-series.php' => [
        'create'         => 'api:admin_songbook_series_create',
        'delete'         => 'api:admin_songbook_series_delete',
        'marcxml_import' => 'api:admin_songbook_series_marcxml_import',
        'update'         => 'api:admin_songbook_series_update',
    ],

    'songbooks.php' => [
        'auto_colour_fill'     => 'api:admin_songbooks_auto_colour_fill',
        'auto_colour_reassign' => 'api:admin_songbooks_auto_colour_reassign',
        'create'                => 'api:admin_songbook_create',
        'delete'                => 'api:admin_songbook_delete',
        'delete_cascade'        => 'api:admin_songbook_delete_cascade',
        /* Plan A17: "family_manifest ... DEFER — wizard-shaped ... API them
           only if a native curator surface is confirmed". Confirmed absent. */
        'family_manifest'       => 'web_only:A17-deferred-wizard',
        'marcxml_import'        => 'api:admin_songbook_marcxml_import',
        'reorder'               => 'api:admin_songbooks_reorder',
        /* A12: folded into admin_songbook_update's optional is_disabled key
           (verified: `array_key_exists('is_disabled', $body)` — absent key
           leaves IsDisabled untouched, present key sets it) rather than a
           separate action. */
        'toggle_disable'        => 'api:admin_songbook_update',
        'update'                => 'api:admin_songbook_update',
    ],

    'tags.php' => [
        'create' => 'api:admin_tag_create',
        'delete' => 'api:admin_tag_delete',
        'merge'  => 'api:admin_tag_merge',
        'update' => 'api:admin_tag_update',
    ],

    'tiers.php' => [
        'create' => 'api:admin_tier_create',
        'delete' => 'api:admin_tier_delete',
        'update' => 'api:admin_tier_update',
    ],

    'tunes.php' => [
        'create' => 'api:admin_tune_add',
        'delete' => 'api:admin_tune_delete',
        'merge'  => 'api:admin_tune_merge',
        'update' => 'api:admin_tune_update',
    ],

    'users.php' => [
        'change_role'    => 'api:admin_user_role_change',
        'change_tier'    => 'api:admin_set_user_tier',
        'create'         => 'api:admin_user_create',
        'delete'         => 'api:admin_user_delete',
        'rename_user'    => 'api:admin_user_rename',
        'reset_password' => 'api:admin_user_password_reset',
        'toggle_active'  => 'api:admin_user_toggle_active',
        'update_profile' => 'api:admin_user_update',
    ],

    'venues.php' => [
        'schedule_delete' => 'api:org_admin_schedule_delete',
        'schedule_save'   => 'api:org_admin_schedule_save',
        'venue_delete'    => 'api:org_admin_venue_delete',
        'venue_save'      => 'api:org_admin_venue_save',
    ],

    'webhooks.php' => [
        'create'        => 'api:admin_webhook_create',
        'delete'        => 'api:admin_webhook_delete',
        'pause'         => 'api:admin_webhook_pause',
        'redrive'       => 'api:admin_webhook_redrive',
        'resume'        => 'api:admin_webhook_resume',
        /* Show-once secret discipline (batch6a, owner condition Q5) — a
           reveal of an EXISTING secret is deliberately never ported to the
           API, only mint/rotate (which return the secret exactly once). */
        'reveal_secret' => 'web_only:show-once-secret',
        'rotate_secret' => 'api:admin_webhook_rotate_secret',
        'send_test'     => 'api:admin_webhook_send_test',
        'update'        => 'api:admin_webhook_update',
        'verify'        => 'api:admin_webhook_verify',
    ],

    'works.php' => [
        'create' => 'api:admin_work_create',
        'delete' => 'api:admin_work_delete',
        'update' => 'api:admin_work_update',
    ],

    /* Class B implicit-action pages — see doc-block. All three are
       deliberately web-only per plan §6, which names each of them
       explicitly. */
    'diagnostics.php' => [
        IMPLICIT_ACTION_KEY => 'web_only:diagnostics-raw-sql',
    ],
    'entitlements.php' => [
        IMPLICIT_ACTION_KEY => 'web_only:entitlements-matrix',
    ],
    'login.php' => [
        /* The browser PHP-session admin login — establishes the /manage
           session cookie. Native/API auth is a DIFFERENT mechanism already
           served by api.php's auth_login (Bearer token, tblApiTokens); this
           endpoint's job is specifically minting the session cookie the
           /manage/* browser console runs on, not a missing capability. */
        IMPLICIT_ACTION_KEY => 'web_only:admin-session-login',
    ],
    'setup.php' => [
        IMPLICIT_ACTION_KEY => 'web_only:pre-auth-bootstrap',
    ],
];

/* =========================================================================
 * PART 3 — THE ASSERTIONS
 * ========================================================================= */

echo "\nStanding manage-action / API-coverage guard\n\n";

$enumerated = discoverManageActions($manageDir);

$totalEnumerated = 0;
foreach ($enumerated as $names) { $totalEnumerated += count($names); }
echo "Enumerated {$totalEnumerated} state-changing manage/*.php action(s) across " . count($enumerated) . " page(s).\n\n";

/* ---- A. every enumerated action has a mapping entry — the whole point:
   a NEW unmapped manage action fails here, loudly, by name. ---- */
foreach ($enumerated as $base => $names) {
    foreach ($names as $name) {
        $label = $name === IMPLICIT_ACTION_KEY ? "{$base} (implicit single action)" : "{$base}::{$name}";
        ok("'{$label}' has a mapping entry", isset($MAPPING[$base][$name]));
    }
}

/* ---- B. every 'api:' target is a REAL case in api.php's or api2.php's
   $action switch — a typo'd or renamed mapping fails here. ---- */
$apiActionCases  = array_count_values(dispatchParserCasesForSwitch($apiFile, '$action'));
$api2ActionCases = array_count_values(dispatchParserCasesForSwitch($api2File, '$action'));

foreach ($MAPPING as $base => $names) {
    foreach ($names as $name => $mapping) {
        if (!str_starts_with($mapping, 'api:')) { continue; }
        $target = substr($mapping, 4);
        $inApi  = ($apiActionCases[$target] ?? 0) > 0;
        $inApi2 = ($api2ActionCases[$target] ?? 0) > 0;
        $label = $name === IMPLICIT_ACTION_KEY ? "{$base} (implicit)" : "{$base}::{$name}";
        ok("'{$label}' -> api:{$target} really exists as an \$action case in api.php or api2.php",
            $inApi || $inApi2);
    }
}

/* ---- C. no stale mapping entries — a mapped action that no longer exists
   in the live enumeration (renamed, removed) fails here rather than sitting
   as dead, misleading documentation. ---- */
foreach ($MAPPING as $base => $names) {
    foreach ($names as $name => $mapping) {
        $stillDispatched = isset($enumerated[$base]) && in_array($name, $enumerated[$base], true);
        $label = $name === IMPLICIT_ACTION_KEY ? "{$base} (implicit)" : "{$base}::{$name}";
        ok("mapping entry '{$label}' still corresponds to a real dispatched action (not stale)",
            $stillDispatched);
    }
}

/* ---- D. every 'native:' entry's page genuinely supports Bearer auth on
   itself — proven against the page's own source, not asserted. ---- */
$nativePages = [
    'places-api.php' => $repo . '/appWeb/public_html/manage/places-api.php',
    'print-pdf.php'  => $repo . '/appWeb/public_html/manage/print-pdf.php',
];
foreach ($MAPPING as $base => $names) {
    foreach ($names as $name => $mapping) {
        if (!str_starts_with($mapping, 'native:')) { continue; }
        $label = $name === IMPLICIT_ACTION_KEY ? "{$base} (implicit)" : "{$base}::{$name}";
        $path = $nativePages[$base] ?? null;
        if ($path === null) {
            ok("'{$label}' is native: but '{$base}' is not in the known native-page list (add it there)", false);
            continue;
        }
        $src = (string)file_get_contents($path);
        ok("'{$label}' native: claim is backed by a real Bearer-auth marker in {$base} (apiTokenResolveBearerUser()) ",
            strpos($src, 'apiTokenResolveBearerUser(') !== false);
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

$apiCount = 0; $webOnlyCount = 0; $nativeCount = 0; $gapCount = 0;
foreach ($MAPPING as $names) {
    foreach ($names as $mapping) {
        if (str_starts_with($mapping, 'api:')) { $apiCount++; }
        elseif (str_starts_with($mapping, 'native:')) { $nativeCount++; }
        elseif (str_starts_with($mapping, 'web_only:')) {
            $webOnlyCount++;
            if (str_contains($mapping, 'GAP')) { $gapCount++; }
        }
    }
}
echo "Mapping: {$apiCount} api:, {$webOnlyCount} web_only: (of which {$gapCount} flagged GAP-*), {$nativeCount} native:.\n";

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

echo "\n{$passed} passed, 0 failed. Every state-changing manage/*.php action is either API-covered, "
   . "explicitly web-only with a reason, or a verified native Bearer-fallback endpoint — "
   . "and the enumerator + mapping stay in lockstep with the live source tree.\n";
exit(0);
