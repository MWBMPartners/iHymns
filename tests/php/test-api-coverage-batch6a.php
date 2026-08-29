<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 6a" API-key + webhook administration
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.3 A18/A19 asked for two admin
 * surfaces to get native-app-capable JSON twins: API-key management
 * (`/manage/api-keys`) and outbound-webhook management (`/manage/webhooks`).
 * The owner approved this ONLY under a strict SHOW-ONCE secret discipline
 * (Q5) — a plaintext secret (an API key's value, a webhook signing secret)
 * may appear in a response ONLY from the action that MINTS or ROTATES it,
 * and no "reveal an existing secret" action is ported at all. This guard
 * checks, from the REAL dispatched source (never a hand-typed belief), that:
 *
 *   1. all sixteen new actions genuinely exist as `$action`-switch cases in
 *      api.php (dispatched exactly once each);
 *   2. each has a top-level path item in api-docs.yaml;
 *   3. each gates on the SAME entitlement its sibling manage/*.php page
 *      ACTUALLY gates on (checked from BOTH sides);
 *   4. each DELEGATES to the shared core (rule #22) — includes/api_keys.php's
 *      new Admin CRUD section (A18, extracted from manage/api-keys.php's own
 *      inline logic in this batch) or the PRE-EXISTING includes/
 *      webhook_admin.php (A19, no extraction needed — the page already used
 *      it) — never a forked SQL statement re-embedded in api.php itself;
 *   5. manage/api-keys.php was genuinely RE-POINTED at the new core (no
 *      leftover raw SQL for the six mutations it used to inline);
 *   6. THE SHOW-ONCE RULE ITSELF: no `admin_api_key_reveal*` /
 *      `admin_webhook_reveal*` action exists anywhere in api.php, and a
 *      plaintext-secret field (`rawKey` / `secret`) appears in EXACTLY the
 *      four expected response bodies (admin_api_key_create,
 *      admin_api_key_approve_request, admin_webhook_create,
 *      admin_webhook_rotate_secret) and NOWHERE else among the sixteen —
 *      proven per-action by isolating each case's own body via the shared
 *      dispatch-token parser, not a whole-file grep that could conflate one
 *      action's response with a neighbour's.
 *
 *   - A18 API keys:  includes/api_keys.php's Admin CRUD section
 *                     (apiKeyAdminCreate/Toggle/Delete/SetLimits/
 *                      RequestCreate/RequestApprove/RequestReject)
 *                     <-> manage/api-keys.php (re-pointed, this batch)
 *   - A19 Webhooks:   includes/webhook_admin.php (pre-existing core)
 *                     <-> manage/webhooks.php (already delegated, untouched)
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * `api.php` has TWO switches (`$page` and `$action`), so a bare
 * `grep "case '...'"` cannot tell which one owns a label —
 * `dispatch_parser.php`'s token walker does. Every one of this batch's
 * sixteen actions is its OWN separate `case 'name': { ... }` block (never
 * grouped fall-through), so `caseBodyFor()` cleanly isolates each action's
 * full body from its neighbours — no `braceBlockAfter()` marker-slicing is
 * needed here, unlike test-api-coverage-batch5.php's `if ($action === 'x')`
 * sibling pages.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyFor()`/`caseBodyContains()`/
 * `functionBodyFor()`/`braceBlockAfter()` are proven, against tiny in-memory
 * fixtures, to both find a marker that is there AND fail to find one that is
 * not, before the real assertions below are trusted (duplicated locally, the
 * same precedent test-api-coverage-batch5.php set).
 *
 *   php tests/php/test-api-coverage-batch6a.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.3 A18/A19   the plan this implements
 * @see appWeb/public_html/api.php                         the sixteen new cases
 * @see appWeb/public_html/includes/api_keys.php            Admin CRUD core (A18)
 * @see appWeb/public_html/includes/webhook_admin.php       pre-existing core (A19)
 * @see appWeb/public_html/manage/api-keys.php               re-pointed page (A18)
 * @see appWeb/public_html/manage/webhooks.php                unchanged page (A19)
 * @see tests/php/test-api-coverage-batch5.php               the sibling guard this mirrors
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';

$apiKeysCoreSrc  = (string)file_get_contents($repo . '/appWeb/public_html/includes/api_keys.php');
$webhookCoreSrc  = (string)file_get_contents($repo . '/appWeb/public_html/includes/webhook_admin.php');
$apiKeysPageSrc  = (string)file_get_contents($repo . '/appWeb/public_html/manage/api-keys.php');
$webhooksPageSrc = (string)file_get_contents($repo . '/appWeb/public_html/manage/webhooks.php');
$apiSrc          = (string)file_get_contents($api);

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

/**
 * Strip `//`/`#`/`/* *\/`/doc-comments from a PHP source fragment, so a
 * substring check below can never be fooled by a marker that only appears
 * in PROSE (a doc-block mentioning a function name, e.g.) rather than
 * actual code. Wraps a bare fragment (no opening `<?php`) so
 * `token_get_all()` tokenises it as PHP rather than one giant
 * T_INLINE_HTML blob — every case/function body this file slices out is
 * pure PHP with no literal HTML, so this is always safe here. Defensive
 * `@`-suppressed + a non-array-result fallback to the ORIGINAL string
 * (never crash the guard itself on a pathological fragment); the
 * mutation self-test below proves the happy path actually strips.
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
 * stripped first (see stripPhpComments()) — or $body is null -> false.
 * Comment-stripping makes every check below strictly more correct in both
 * directions: a "DOES delegate to X(" assertion can no longer be satisfied
 * by a doc-block merely mentioning `X(`, and a "does NOT contain forked
 * SQL" assertion can no longer be defeated by a comment that happens to
 * quote the banned string in prose.
 */
function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos(stripPhpComments($body), $needle) !== false;
}

/**
 * Slice ONE brace-delimited block out of $src by BRACE DEPTH, starting from
 * the first `{` found AT OR AFTER the first occurrence of $conditionMarker.
 *
 * @return string|null null when the marker isn't found, or the block is
 *         unbalanced (no matching close brace before EOF).
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

/**
 * Slice a named top-level `function NAME(...) { ... }` region's body via
 * brace depth from the `function` keyword's own occurrence.
 */
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
        doBetaThing();
        alsoBetaHelper();
        break;
    case 'gamma':
        doGammaThing();
        break;
}
PHP;
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b6a_');
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

/* stripPhpComments() / caseBodyContains() self-test — this is the exact
   trap this test hit in development: a doc-comment mentioning a function
   NAME (with trailing parens, so it visually reads like a call) must NOT
   register as the function actually being CALLED. */
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

echo "\nAPI-coverage batch 6a: API-key admin (A18) / webhook admin (A19)\n\n";

$apiKeyActions = [
    'admin_api_key_create',
    'admin_api_key_toggle',
    'admin_api_key_delete',
    'admin_api_key_set_limits',
    'admin_api_key_approve_request',
    'admin_api_key_reject_request',
    'api_key_request',
];
$webhookActions = [
    'admin_webhook_create',
    'admin_webhook_update',
    'admin_webhook_verify',
    'admin_webhook_pause',
    'admin_webhook_resume',
    'admin_webhook_rotate_secret',
    'admin_webhook_send_test',
    'admin_webhook_delete',
    'admin_webhook_redrive',
];
$batch6a = array_merge($apiKeyActions, $webhookActions);

/* ---- A. Dispatchable: the real $action switch carries all sixteen,
   exactly once each — tree-derived from the actual dispatcher. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch6a as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml. ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch6a as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement key its sibling manage/*.php page ACTUALLY gates on —
   checked from BOTH sides. ---- */
$entitlementByAction = [
    'admin_api_key_create'          => 'manage_api_keys',
    'admin_api_key_toggle'          => 'manage_api_keys',
    'admin_api_key_delete'          => 'manage_api_keys',
    'admin_api_key_set_limits'      => 'manage_api_keys',
    'admin_api_key_approve_request' => 'manage_api_keys',
    'admin_api_key_reject_request'  => 'manage_api_keys',
    'api_key_request'               => 'request_api_keys',
    'admin_webhook_create'          => 'manage_webhooks',
    'admin_webhook_update'          => 'manage_webhooks',
    'admin_webhook_verify'          => 'manage_webhooks',
    'admin_webhook_pause'           => 'manage_webhooks',
    'admin_webhook_resume'          => 'manage_webhooks',
    'admin_webhook_rotate_secret'   => 'manage_webhooks',
    'admin_webhook_send_test'       => 'manage_webhooks',
    'admin_webhook_delete'          => 'manage_webhooks',
    'admin_webhook_redrive'         => 'manage_webhooks',
];
foreach ($entitlementByAction as $name => $entKey) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' gates via userHasEntitlement('{$entKey}'",
        caseBodyContains($body, "userHasEntitlement('{$entKey}'"));
}
/* The page-side half: manage/api-keys.php ACTUALLY gates its manage-only
   actions on manage_api_keys and its self-serve action on
   request_api_keys; manage/webhooks.php ACTUALLY gates on manage_webhooks —
   not a hand-typed belief about what the page currently does. */
ok('manage/api-keys.php really does gate manage-only actions on manage_api_keys (API gate matches the page\'s OWN gate)',
    strpos($apiKeysPageSrc, "userHasEntitlement('manage_api_keys'") !== false);
ok('manage/api-keys.php really does gate the self-serve action on request_api_keys (API gate matches the page\'s OWN gate)',
    strpos($apiKeysPageSrc, "userHasEntitlement('request_api_keys'") !== false);
ok('manage/webhooks.php really does gate on manage_webhooks (API gate matches the page\'s OWN gate)',
    strpos($webhooksPageSrc, "userHasEntitlement('manage_webhooks'") !== false);

/* ---- D. A18 API-key actions delegate to includes/api_keys.php's Admin
   CRUD core — never a forked SQL statement re-embedded in api.php. ---- */
$apiKeyCoreFnByAction = [
    'admin_api_key_create'          => 'apiKeyAdminCreate(',
    'admin_api_key_toggle'          => 'apiKeyAdminToggle(',
    'admin_api_key_delete'          => 'apiKeyAdminDelete(',
    'admin_api_key_set_limits'      => 'apiKeyAdminSetLimits(',
    'admin_api_key_approve_request' => 'apiKeyAdminRequestApprove(',
    'admin_api_key_reject_request'  => 'apiKeyAdminRequestReject(',
    'api_key_request'               => 'apiKeyAdminRequestCreate(',
];
foreach ($apiKeyCoreFnByAction as $name => $fnCall) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' delegates to {$fnCall}", caseBodyContains($body, $fnCall));
    foreach (['INSERT INTO tblApiKeys', 'UPDATE tblApiKeys', 'DELETE FROM tblApiKeys',
              'INSERT INTO tblApiKeyRequests', 'UPDATE tblApiKeyRequests'] as $forkedSql) {
        ok("'{$name}' does NOT re-embed a raw \"{$forkedSql}\" (would be a forked write)",
            !caseBodyContains($body, $forkedSql));
    }
}
foreach (array_values($apiKeyCoreFnByAction) as $fnCall) {
    $fn = rtrim($fnCall, '(');
    ok("includes/api_keys.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $apiKeysCoreSrc));
}

/* ---- E. A19 webhook actions delegate to the PRE-EXISTING
   includes/webhook_admin.php core — never a forked SQL statement
   re-embedded in api.php. ---- */
$webhookCoreFnByAction = [
    'admin_webhook_create'        => 'webhookSubscriptionCreate(',
    'admin_webhook_update'        => 'webhookSubscriptionUpdate(',
    'admin_webhook_verify'        => 'webhookSendVerification(',
    'admin_webhook_pause'         => 'webhookSubscriptionSetStatus(',
    'admin_webhook_resume'        => 'webhookSubscriptionSetStatus(',
    'admin_webhook_rotate_secret' => 'webhookSubscriptionRotateSecret(',
    'admin_webhook_send_test'     => 'webhookEnqueueForSubscription(',
    'admin_webhook_delete'        => 'webhookSubscriptionDelete(',
    'admin_webhook_redrive'       => 'webhookDeliveryRedrive(',
];
foreach ($webhookCoreFnByAction as $name => $fnCall) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' delegates to {$fnCall}", caseBodyContains($body, $fnCall));
    ok("'{$name}' gates on webhookSchemaReady( (STRICT-safe, mirrors the page's own pre-dispatch guard)",
        caseBodyContains($body, 'webhookSchemaReady('));
    foreach (['INSERT INTO tblWebhookSubscriptions', 'UPDATE tblWebhookSubscriptions',
              'DELETE FROM tblWebhookSubscriptions', 'INSERT INTO tblWebhookEvents',
              'INSERT INTO tblWebhookDeliveries', 'UPDATE tblWebhookDeliveries'] as $forkedSql) {
        ok("'{$name}' does NOT re-embed a raw \"{$forkedSql}\" (would be a forked write)",
            !caseBodyContains($body, $forkedSql));
    }
}
foreach (array_unique(array_values($webhookCoreFnByAction)) as $fnCall) {
    $fn = rtrim($fnCall, '(');
    ok("includes/webhook_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $webhookCoreSrc));
}

/* =========================================================================
 * F. SHOW-ONCE SECRET DISCIPLINE — the whole point of this batch (owner
 * condition Q5). Every assertion here is checked against the ISOLATED body
 * of each individual case, never a whole-file grep, so one action's
 * response can never be confused with a neighbour's.
 * ========================================================================= */

/* F1. No reveal action exists anywhere in api.php's real $action switch. */
foreach (['admin_api_key_reveal', 'admin_api_key_reveal_secret', 'admin_api_key_show_secret',
          'admin_webhook_reveal', 'admin_webhook_reveal_secret', 'admin_webhook_show_secret'] as $bannedName) {
    ok("no '{$bannedName}' action exists in api.php's \$action switch (show-once — no reveal action was ported)",
        !isset($actionCounts[$bannedName]));
}
/* Belt-and-braces: no case label anywhere in the real dispatch surface
   contains the substring 'reveal' at all (catches a differently-spelled
   reveal action the named list above didn't anticipate). */
$revealLikeCases = array_filter($actionCases, static fn(string $c): bool => stripos($c, 'reveal') !== false);
ok('no $action case label in api.php contains "reveal" anywhere (catches an unanticipated spelling of a reveal action)',
    $revealLikeCases === []);

/* F2. webhookSubscriptionRevealSecret() (the page-only reveal function) is
   NEVER called from api.php — proven against api.php's WHOLE source
   (comments stripped, so a doc-comment merely NAMING the function, as this
   very section's own header does, can never satisfy or defeat the check),
   not just this batch's sixteen bodies, so a reveal call slipped in
   elsewhere in the file would still be caught. */
ok('api.php never calls webhookSubscriptionRevealSecret( anywhere in the file (the page-only reveal path stays web-only)',
    strpos(stripPhpComments($apiSrc), 'webhookSubscriptionRevealSecret(') === false);

/* F3. Exactly the four expected actions' response bodies carry a
   plaintext-secret field (`rawKey` for API keys, `secret` for webhooks);
   every other action's body carries neither. */
$mintOrRotateActions = [
    'admin_api_key_create'          => 'rawKey',
    'admin_api_key_approve_request' => 'rawKey',
    'admin_webhook_create'          => 'secret',
    'admin_webhook_rotate_secret'   => 'secret',
];
foreach ($mintOrRotateActions as $name => $field) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' (a mint/rotate action) DOES emit '{$field}' in its sendJson() response — this is the ONE place it may appear",
        caseBodyContains($body, "'{$field}'"));
}
foreach ($batch6a as $name) {
    if (isset($mintOrRotateActions[$name])) { continue; }
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' (NOT a mint/rotate action) does NOT emit 'rawKey' anywhere in its own body",
        !caseBodyContains($body, "'rawKey'"));
    ok("'{$name}' (NOT a mint/rotate action) does NOT emit 'secret' anywhere in its own body",
        !caseBodyContains($body, "'secret'"));
}

/* F4. Inside includes/api_keys.php, apiKeyGenerate() (the ONLY function
   that can produce a raw key) is called from EXACTLY apiKeyAdminCreate()
   and apiKeyAdminRequestApprove() — proven by isolating each function's
   OWN body, not a whole-file grep. */
$apiKeysCoreFnNames = [
    'apiKeyAdminCreate', 'apiKeyAdminToggle', 'apiKeyAdminDelete', 'apiKeyAdminSetLimits',
    'apiKeyAdminRequestCreate', 'apiKeyAdminRequestApprove', 'apiKeyAdminRequestReject',
];
$mintingFns = ['apiKeyAdminCreate', 'apiKeyAdminRequestApprove'];
foreach ($apiKeysCoreFnNames as $fn) {
    $fnBody = functionBodyFor($apiKeysCoreSrc, $fn);
    ok("includes/api_keys.php's {$fn}() is isolatable by functionBodyFor()", $fnBody !== null);
    $callsGenerate = caseBodyContains($fnBody, 'apiKeyGenerate(');
    if (in_array($fn, $mintingFns, true)) {
        ok("{$fn}() (a minting function) DOES call apiKeyGenerate(", $callsGenerate);
        ok("{$fn}() returns 'rawKey' in its result array", caseBodyContains($fnBody, "'rawKey'"));
    } else {
        ok("{$fn}() (NOT a minting function) does NOT call apiKeyGenerate(", !$callsGenerate);
        ok("{$fn}() does NOT return 'rawKey' anywhere in its own body", !caseBodyContains($fnBody, "'rawKey'"));
    }
}

/* F5. Inside includes/webhook_admin.php, webhookMintSecret() (the ONLY
   function that can produce a raw signing secret) is called from EXACTLY
   webhookSubscriptionCreate() and webhookSubscriptionRotateSecret(). */
$webhookMintingFns = ['webhookSubscriptionCreate', 'webhookSubscriptionRotateSecret'];
$webhookNonMintingFns = [
    'webhookSubscriptionUpdate', 'webhookSubscriptionSetStatus', 'webhookSendVerification',
    'webhookEnqueueForSubscription', 'webhookSubscriptionDelete', 'webhookDeliveryRedrive',
    'webhookSubscriptionRevealSecret',
];
foreach (array_merge($webhookMintingFns, $webhookNonMintingFns) as $fn) {
    $fnBody = functionBodyFor($webhookCoreSrc, $fn);
    ok("includes/webhook_admin.php's {$fn}() is isolatable by functionBodyFor()", $fnBody !== null);
    $callsMint = caseBodyContains($fnBody, 'webhookMintSecret(');
    if (in_array($fn, $webhookMintingFns, true)) {
        ok("{$fn}() (a minting function) DOES call webhookMintSecret(", $callsMint);
    } else {
        ok("{$fn}() (NOT a minting function) does NOT call webhookMintSecret(", !$callsMint);
    }
}
/* webhookSubscriptionRevealSecret() specifically: still exists (page-only,
   untouched), does NOT mint (it reveals the EXISTING stored secret via
   webhookSecretReveal(), never webhookMintSecret()), and (per F2 above) is
   simply never called from api.php. */
$revealFnBody = functionBodyFor($webhookCoreSrc, 'webhookSubscriptionRevealSecret');
ok('webhookSubscriptionRevealSecret() still exists in includes/webhook_admin.php (untouched, page-only — this batch did not delete it, only declined to expose it over the API)',
    $revealFnBody !== null);
ok('webhookSubscriptionRevealSecret() calls webhookSecretReveal( (reveals the EXISTING secret, distinct from minting a new one)',
    caseBodyContains($revealFnBody, 'webhookSecretReveal('));

/* =========================================================================
 * G. EXTRACTION VERIFICATION (A18 only — A19's page needed no extraction,
 * it already delegated) — manage/api-keys.php was genuinely RE-POINTED at
 * the new core, not just the API. Sliced PER-CASE via braceBlockAfter() so
 * one block's leftover SQL can never leak a false pass into another.
 * ========================================================================= */

$pageCreateFn = [
    'create'          => 'apiKeyAdminCreate(',
    'toggle'          => 'apiKeyAdminToggle(',
    'delete'          => 'apiKeyAdminDelete(',
    'set_limits'      => 'apiKeyAdminSetLimits(',
    'request'         => 'apiKeyAdminRequestCreate(',
    'approve_request' => 'apiKeyAdminRequestApprove(',
    'reject_request'  => 'apiKeyAdminRequestReject(',
];
foreach ($pageCreateFn as $caseName => $fnCall) {
    $block = braceBlockAfter($apiKeysPageSrc, "case '{$caseName}':");
    ok("manage/api-keys.php's '{$caseName}' case delegates to {$fnCall}",
        caseBodyContains($block, $fnCall));
    foreach (['INSERT INTO tblApiKeys', 'UPDATE tblApiKeys', 'DELETE FROM tblApiKeys',
              'INSERT INTO tblApiKeyRequests', 'UPDATE tblApiKeyRequests'] as $forkedSql) {
        ok("manage/api-keys.php's '{$caseName}' case has NO leftover raw \"{$forkedSql}\" (genuinely re-pointed at the core)",
            !caseBodyContains($block, $forkedSql));
    }
}

/* Cross-check: exactly ONE file in the whole tree writes
   "INSERT INTO tblApiKeys (Label, KeyHash" — the core (which BOTH the page
   and api.php now call) — never a second copy in the page or in api.php
   itself (already checked absent in sections D and G above; this is the
   whole-tree confirmation). */
$insertApiKeySites = 0;
foreach ([
    $repo . '/appWeb/public_html/includes/api_keys.php',
    $repo . '/appWeb/public_html/manage/api-keys.php',
    $api,
] as $f) {
    $src = (string)file_get_contents($f);
    if (strpos($src, 'INSERT INTO tblApiKeys (Label, KeyHash') !== false) { $insertApiKeySites++; }
}
ok('exactly ONE file writes "INSERT INTO tblApiKeys (Label, KeyHash" (the core) — neither the page nor api.php carries a second copy',
    $insertApiKeySites === 1);

/* =========================================================================
 * H. api2.php / print-pdf.php / editor api.php CONSTRAINT COMPLIANCE — this
 * batch's task explicitly listed those as out of scope ("Do NOT touch
 * api2.php/editor api.php/print-pdf.php"). Confirms none of them mention
 * this batch's sixteen new action names at all (they have no business
 * knowing about API-key/webhook admin), proving the batch stayed inside
 * api.php + its two includes/ cores + manage/api-keys.php.
 * ========================================================================= */

foreach ([
    'api2.php'      => $repo . '/appWeb/public_html/manage/editor/api2.php',
    'editor api.php' => $repo . '/appWeb/public_html/manage/editor/api.php',
    'print-pdf.php'  => $repo . '/appWeb/public_html/manage/print-pdf.php',
] as $label => $path) {
    if (!is_file($path)) { continue; }
    $src = stripPhpComments((string)file_get_contents($path));
    $touched = false;
    foreach ($batch6a as $name) {
        if (strpos($src, $name) !== false) { $touched = true; break; }
    }
    ok("{$label} was left untouched by this batch (mentions none of the sixteen new action names)", !$touched);
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

echo "\n{$passed} passed, 0 failed. All sixteen API-coverage batch-6a endpoints are dispatchable, documented, gate on the same entitlement as their sibling page, delegate to their shared cores with no forked SQL, manage/api-keys.php was genuinely re-pointed at the new core, and the show-once secret discipline holds exactly: no reveal action was ported, and a plaintext secret appears in exactly the four mint/rotate responses and nowhere else.\n";
exit(0);
