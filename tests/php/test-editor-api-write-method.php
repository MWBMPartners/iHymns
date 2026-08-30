<?php

declare(strict_types=1);

/**
 * iHymns — editor API method-gate guard (CONFIRMED-Low CSRF finding, security
 * review 2026-08-28)
 * ============================================================================
 *
 * ELI5
 * ----
 * Both editor API files (`manage/editor/api2.php`, the legacy
 * `manage/editor/api.php`) used to check "did this arrive by POST, with the
 * right header?" ONLY inside an `if ($method === 'POST')` block — but the
 * big switch that actually DOES things ran on every method regardless. So a
 * signed-in editor lured to a plain link (`<img src="…?action=create_song">`,
 * a cross-site top-level navigation) could trigger a write, because the
 * `ihymns_auth` cookie is SameSite=Lax and rides along on a GET. This file
 * is the mutation-proven proof that the fix — "POST is the default, and only
 * a named allow-list of pure reads may arrive by GET" — is actually in
 * place, in the right spot, with the right members, in BOTH files, and that
 * this test can actually fail (rule #34).
 *
 * WHAT IT ASSERTS (DB-free — this test never boots the app, connects to
 * MySQL, or sends an HTTP request; it works entirely from source text, the
 * same posture as tests/php/test-editor-api2-contract.php)
 *
 *   1. Each file's method-gate array constant parses as real PHP (self-
 *      tested extractor — proven able to fail both ways, rule #34).
 *   2. The gate sits BEFORE the `switch ($action)` dispatch and after the
 *      existing POST-only CSRF check — so it runs on every request, and the
 *      CSRF check inside the POST branch is untouched (regression guard).
 *   3. Simulating the EXACT gate expression the production code runs
 *      (`$method === 'POST' || in_array($action, ALLOW_LIST, true)`):
 *        - create_song (api2.php) — the finding's own exploit — is REFUSED
 *          on GET.
 *        - A representative destructive action in each file (bulk_delete /
 *          delete_song) is REFUSED on GET.
 *        - A representative read action (load_song) is ALLOWED on GET in
 *          BOTH files.
 *        - An unknown/typo'd action name is REFUSED on GET — proves the
 *          default is DENY, which is the actual point of the fix (closing
 *          the class, not patching one action).
 *        - Every action in every allow-list still names a real `case` in
 *          that file's switch (an allow-list entry for a renamed/deleted
 *          action would be a silent no-op, not a read hole — but it would
 *          mean this test is checking something that no longer exists).
 *
 * HOW TO RE-PROVE THIS FILE IS NOT VACUOUS (rule #34 — done once by hand
 * when this file was authored; not re-run automatically every CI pass,
 * exactly like test-editor-api2-contract.php's own header does not re-prove
 * itself on every run):
 *   1. Comment out the `if ($method !== 'POST' && ...)` block in api2.php
 *      (or api.php) -> `php tests/php/test-editor-api-write-method.php`
 *      must FAIL (the extractor can't find the gate text, or — if you
 *      instead widen the allow-list to include 'create_song' rather than
 *      deleting the gate — the create_song assertion goes RED).
 *   2. Restore the file -> the suite is GREEN again.
 *
 *   php tests/php/test-editor-api-write-method.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/manage/editor/api2.php   ED2_GET_SAFE_ACTIONS
 * @see appWeb/public_html/manage/editor/api.php    IHYMNS_EDITOR_API_GET_SAFE_ACTIONS
 */

$root   = dirname(__DIR__, 2);
$editor = $root . '/appWeb/public_html/manage/editor';

$passed   = 0;
$failed   = 0;
$failures = [];

function check(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  \xE2\x9C\x85 {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  \xE2\x9D\x8C {$label}\n";
    }
}

/** PHP-code-only projection (drop comments/doc-blocks) — the
 *  test-editor2-metadata-1862.php ed1862PhpCode() idiom, restated locally
 *  (tests in this tree are self-contained — no shared PHP test helper file
 *  exists, confirmed by grep) — so a pattern MENTIONED in a doc-comment
 *  (this file's own header included) can never satisfy a source assertion. */
function eawmPhpCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT || $tok[0] === T_INLINE_HTML) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/** Bracket-depth-matched array literal: from `const $constName = [` through
 *  its balanced closing `]`, eval()'d back into a real PHP array. null if
 *  not found or unbalanced — mirrors ed1862FunctionBody()'s brace-matching
 *  idiom (test-editor2-metadata-1862.php), applied to `[...]` instead of
 *  `{...}`. eval() is safe here: the input is this repo's own source on
 *  disk, never request data. */
function eawmExtractArrayConst(string $code, string $constName): ?array
{
    $needle = "const {$constName} = [";
    $start  = strpos($code, $needle);
    if ($start === false) { return null; }
    $bracketStart = strpos($code, '[', $start);
    if ($bracketStart === false) { return null; }
    $depth = 0;
    for ($i = $bracketStart, $len = strlen($code); $i < $len; $i++) {
        if ($code[$i] === '[') { $depth++; }
        elseif ($code[$i] === ']') {
            $depth--;
            if ($depth === 0) {
                $literal = substr($code, $bracketStart, $i - $bracketStart + 1);
                $arr = null;
                try {
                    eval('$arr = ' . $literal . ';');
                } catch (\Throwable $e) {
                    return null;
                }
                return is_array($arr) ? $arr : null;
            }
        }
    }
    return null;   // unbalanced — degrade to "not found" rather than a bogus tail
}

/** Every `case 'name':` label in a switch, comment-stripped source. */
function eawmExtractCaseNames(string $strippedCode): array
{
    preg_match_all("/case\s+'([A-Za-z0-9_]+)'\s*:/", $strippedCode, $m);
    return $m[1] ?? [];
}

/** Simulates the EXACT production expression:
 *    if ($method !== 'POST' && !in_array($action, ALLOW_LIST, true)) { 405 }
 *  Returns true when the request would be ALLOWED to reach the switch. */
function eawmGateAllows(string $method, string $action, array $allowList): bool
{
    return $method === 'POST' || in_array($action, $allowList, true);
}

echo "\nEditor API method-gate guard (CONFIRMED-Low CSRF finding)\n\n";

/* =============================================================================
 * SELF-TEST — prove the extractor itself can fail both ways before trusting
 * anything built on it (rule #34's own repeated lesson).
 * ============================================================================= */
echo "-- Self-test: array-literal extractor --\n";

$fixtureSrc = "<?php\n/* const DECOY = ['zzz']; a mention in a comment must not count */\nconst SAMPLE_LIST = [\n    'alpha', 'beta',\n    'gamma',\n];\n";
$fixtureStripped = eawmPhpCode($fixtureSrc);
check('comment-stripper drops a decoy const mentioned only in a comment', strpos($fixtureStripped, 'DECOY') === false);
check('comment-stripper keeps the real const', strpos($fixtureStripped, 'SAMPLE_LIST') !== false);

$extracted = eawmExtractArrayConst($fixtureStripped, 'SAMPLE_LIST');
check('extractor FAILS-HIGH check: correctly parses a real 3-element array', $extracted === ['alpha', 'beta', 'gamma']);
check('extractor FAILS-LOW check: returns null for a const that is not present', eawmExtractArrayConst($fixtureStripped, 'NOT_THERE') === null);

$gateSelfA = eawmGateAllows('GET', 'read_thing', ['read_thing']);
$gateSelfB = eawmGateAllows('GET', 'write_thing', ['read_thing']);
$gateSelfC = eawmGateAllows('POST', 'write_thing', ['read_thing']);
check('gate simulator FAILS-HIGH check: GET + on allow-list -> allowed', $gateSelfA === true);
check('gate simulator FAILS-LOW check: GET + NOT on allow-list -> refused', $gateSelfB === false);
check('gate simulator: POST always allowed regardless of allow-list', $gateSelfC === true);

/* =============================================================================
 * api2.php
 * ============================================================================= */
echo "\n-- api2.php --\n";

$api2File = $editor . '/api2.php';
check('manage/editor/api2.php exists', is_file($api2File));
$api2Src     = is_file($api2File) ? (string)file_get_contents($api2File) : '';
$api2Code    = $api2Src !== '' ? eawmPhpCode($api2Src) : '';
check('manage/editor/api2.php is readable', $api2Src !== '');

if ($api2Code !== '') {
    /* ---- position: CSRF check -> method gate -> switch, in that order ---- */
    $csrfPos   = strpos($api2Code, "!== 'XMLHttpRequest'");
    $gatePos   = strpos($api2Code, 'ED2_GET_SAFE_ACTIONS');
    $switchPos = strpos($api2Code, 'switch ($action)');
    check('api2.php still has the POST-block X-Requested-With CSRF check (regression guard — this fix must not remove it)', $csrfPos !== false);
    check('api2.php defines ED2_GET_SAFE_ACTIONS', $gatePos !== false);
    check('api2.php has a switch ($action) dispatch', $switchPos !== false);
    if ($csrfPos !== false && $gatePos !== false && $switchPos !== false) {
        check('the CSRF check runs BEFORE the method gate', $csrfPos < $gatePos);
        check('the method gate runs BEFORE the switch (so it gates EVERY case, not just create_song)', $gatePos < $switchPos);
    }
    /* The actual enforcement line — proves the gate is wired to a 405, not
       just declared and never consulted. */
    check(
        'api2.php actually branches on the gate (method !== POST && not in the allow-list -> 405)',
        (bool)preg_match('/\$method\s*!==\s*\'POST\'\s*&&\s*!in_array\(\$action,\s*ED2_GET_SAFE_ACTIONS,\s*true\)/', $api2Code)
        && strpos(substr($api2Code, (int)strpos($api2Code, '$method !== \'POST\' && !in_array($action, ED2_GET_SAFE_ACTIONS'), 200), '405') !== false
    );

    $api2AllowList = eawmExtractArrayConst($api2Code, 'ED2_GET_SAFE_ACTIONS');
    check('extracted ED2_GET_SAFE_ACTIONS as a real PHP array', is_array($api2AllowList));

    $api2Cases = eawmExtractCaseNames($api2Code);
    check('derived at least 40 case labels from api2.php (vacuity check — #66 actions expected)', count($api2Cases) >= 40);

    if (is_array($api2AllowList)) {
        /* ---- the finding itself: create_song must be refused on GET ---- */
        check(
            "SECURITY: 'create_song' via GET is REFUSED (the finding's own exploit)",
            eawmGateAllows('GET', 'create_song', $api2AllowList) === false
        );
        /* ---- a second, independently-worded destructive action ---- */
        check(
            "SECURITY: 'bulk_delete' via GET is REFUSED (representative destructive action)",
            eawmGateAllows('GET', 'bulk_delete', $api2AllowList) === false
        );
        check(
            "SECURITY: 'delete_song' via GET is REFUSED (representative destructive action)",
            eawmGateAllows('GET', 'delete_song', $api2AllowList) === false
        );
        /* ---- a representative read must still work on GET ---- */
        check(
            "'load_song' via GET is ALLOWED (a real read must not be broken by the fix)",
            eawmGateAllows('GET', 'load_song', $api2AllowList) === true
        );
        /* ---- default-deny: an unknown action name must be refused, not
               fall through — this is what "close the class" means, not just
               patching the one named action. ---- */
        check(
            "an unrecognised action ('__no_such_action__') via GET is REFUSED (default-deny, not default-allow)",
            eawmGateAllows('GET', '__no_such_action__', $api2AllowList) === false
        );
        /* ---- POST always works, for every action, allow-listed or not ---- */
        check("'create_song' via POST is still allowed (the fix must not break legitimate writes)", eawmGateAllows('POST', 'create_song', $api2AllowList) === true);

        /* ---- every allow-listed action names a real case (no stale/typo'd
               entries — those would make this test assert something that no
               longer exists in the switch). ---- */
        foreach ($api2AllowList as $a) {
            check("api2.php's allow-listed action '{$a}' has a matching case in the switch", in_array($a, $api2Cases, true));
        }
        check('api2.php allow-list has at least 15 entries (vacuity check)', count($api2AllowList) >= 15);
    }
}

/* =============================================================================
 * api.php (legacy)
 * ============================================================================= */
echo "\n-- api.php (legacy) --\n";

$apiFile = $editor . '/api.php';
check('manage/editor/api.php exists', is_file($apiFile));
$apiSrc  = is_file($apiFile) ? (string)file_get_contents($apiFile) : '';
$apiCode = $apiSrc !== '' ? eawmPhpCode($apiSrc) : '';
check('manage/editor/api.php is readable', $apiSrc !== '');

if ($apiCode !== '') {
    $csrfPos   = strpos($apiCode, 'validateCsrfRequest(');
    $gatePos   = strpos($apiCode, 'IHYMNS_EDITOR_API_GET_SAFE_ACTIONS');
    $switchPos = strpos($apiCode, 'switch ($action)');
    check('api.php still has the POST-block validateCsrfRequest() CSRF check (regression guard)', $csrfPos !== false);
    check('api.php defines IHYMNS_EDITOR_API_GET_SAFE_ACTIONS', $gatePos !== false);
    check('api.php has a switch ($action) dispatch', $switchPos !== false);
    if ($csrfPos !== false && $gatePos !== false && $switchPos !== false) {
        check('the CSRF check runs BEFORE the method gate', $csrfPos < $gatePos);
        check('the method gate runs BEFORE the switch (so it gates EVERY case)', $gatePos < $switchPos);
    }
    check(
        'api.php actually branches on the gate (REQUEST_METHOD !== POST && not in the allow-list -> 405)',
        (bool)preg_match('/!==\s*\'POST\'\s*\)?\s*\n?\s*&&\s*!in_array\(\$action,\s*IHYMNS_EDITOR_API_GET_SAFE_ACTIONS,\s*true\)/', $apiCode)
    );

    $apiAllowList = eawmExtractArrayConst($apiCode, 'IHYMNS_EDITOR_API_GET_SAFE_ACTIONS');
    check('extracted IHYMNS_EDITOR_API_GET_SAFE_ACTIONS as a real PHP array', is_array($apiAllowList));

    $apiCases = eawmExtractCaseNames($apiCode);
    check('derived at least 25 case labels from api.php (vacuity check)', count($apiCases) >= 25);

    if (is_array($apiAllowList)) {
        /* api.php has no create_song action (that landed only in api2.php —
           #1783 confirms the split); its own representative destructive
           action, present since v1, is delete_song. */
        check(
            "SECURITY: 'delete_song' via GET is REFUSED (representative destructive action — every write case in api.php has its OWN inline REQUEST_METHOD guard already, but the class-level default must ALSO deny)",
            eawmGateAllows('GET', 'delete_song', $apiAllowList) === false
        );
        check(
            "SECURITY: 'restore_revision' via GET is REFUSED",
            eawmGateAllows('GET', 'restore_revision', $apiAllowList) === false
        );
        check(
            "'load_song' via GET is ALLOWED (a real read must not be broken by the fix)",
            eawmGateAllows('GET', 'load_song', $apiAllowList) === true
        );
        check(
            "an unrecognised action ('__no_such_action__') via GET is REFUSED (default-deny)",
            eawmGateAllows('GET', '__no_such_action__', $apiAllowList) === false
        );
        check("'delete_song' via POST is still allowed", eawmGateAllows('POST', 'delete_song', $apiAllowList) === true);

        foreach ($apiAllowList as $a) {
            check("api.php's allow-listed action '{$a}' has a matching case in the switch", in_array($a, $apiCases, true));
        }
        check('api.php allow-list has at least 8 entries (vacuity check)', count($apiAllowList) >= 8);

        /* create_song does not exist in api.php at all — asserting it isn't
           allow-listed would be vacuous (rule #34); assert instead that it
           is simply absent as a case, so the api2.php-only finding is not
           silently assumed to apply here too. */
        check("api.php has no 'create_song' case (the finding's exploit is api2.php-only; #1783 split)", !in_array('create_song', $apiCases, true));
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    fwrite(STDERR, "\nFailures:\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "\nAll editor API method-gate assertions passed.\n";
exit(0);
