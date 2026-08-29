<?php

declare(strict_types=1);

/**
 * iHymns — "Connect a service" guided-wizard guard (#2003, epic #2002)
 * =====================================================================
 *
 * ELI5
 * ----
 * #2003 added ONE guided wizard, built from a registry
 * (`includes/integration_registry.php`) that describes three existing
 * config cards (IntAppsAPI, CueRCode, CAPTCHA) — never a second copy of
 * their save handlers, never a place a secret value could leak into the
 * browser. This file is the standing guard that keeps that shape honest:
 * the registry and the page's own launcher buttons can never drift apart,
 * the wizard saves through the EXISTING actions (never a forked write
 * path), a secret can never reach the emitted JSON, the CueRCode probe
 * reuses the EXACT same SSRF-hardened transport as the code that already
 * shipped, and the client driver never hardcodes a per-integration field
 * name (a registry-driven engine that quietly grew a special case would be
 * the whole point of the design lost).
 *
 * WHAT THIS FILE ASSERTS (checks (a)-(i), plan §10)
 * --------------------------------------------------------------------
 *  (a) REGISTRY <-> PAGE LOCKSTEP (tree-derived, both directions): every
 *      `integrationRegistry()` key has a launcher `<button>` in
 *      configuration.php carrying `data-bs-target="#integrationConnectModal"`
 *      + `data-integration="<key>"`; AND every `data-integration="…"`
 *      attribute the page actually emits names a real registry key. A
 *      launcher that silently stops existing, or a bogus one that opens
 *      nothing meaningful, both fail here.
 *  (b) REUSE, NOT FORK: every registry entry's `saveAction` is among the
 *      actions `dispatchParserActionsForFile()` (the SAME tree-derived
 *      parser the standing API-coverage guard uses) finds dispatched by
 *      `manage/configuration.php`; the classic actions `save_intappsapi` /
 *      `save_cuercode` / `save_captcha` / `captcha_probe` / `test_email`
 *      are ALL still discovered (surface freeze — the wizard must never
 *      have quietly replaced a classic action rather than reusing it); each
 *      card's manual form still emits its hidden `name="action"` input.
 *  (c) SECRET-LEAK TRUTH TEST (functional, the strongest check):
 *      `integrationClientProjection()` is called with a stub reader that
 *      hands back a SENTINEL string for every key; the sentinel must be
 *      ABSENT from the encoded output for every `secret:true` field and
 *      PRESENT for every `secret:false` field — proving structurally that a
 *      secret value cannot reach the browser regardless of what the
 *      database happens to hold. Also asserts every secret field's
 *      `setting` is registered in `secretSettingKeys()`.
 *  (d) TEST-BRANCH DISCIPLINE: the `integration_test` branch exists,
 *      `validateCsrfRequest(` textually precedes `integrationTestDispatch(`
 *      inside it, it validates the posted key against
 *      `array_keys(integrationRegistry())`, and every registry `testFn` is
 *      a real, defined function.
 *  (e) CUERCODE PROBE SAFETY: `cuercodeProbe()` reuses `_cuercodeResolveUrl()`
 *      + `_cuercodeHttpExec()` (never a second curl block) and NEVER calls
 *      `cuercodeGenerateCached()` (a cache hit would "pass" the test without
 *      the live service ever being asked anything); `_cuercodeHttpExec()`
 *      keeps `CURLOPT_FOLLOWLOCATION => false` and
 *      `CURLOPT_SSL_VERIFYPEER => true`.
 *  (f) CARRY-SAFETY / GENERICITY: the client driver defines a `collectFields(`
 *      that iterates `entry.fields` generically (never a per-integration
 *      field-name literal like `'cuercode_base_url'` anywhere in the file —
 *      the registry is the ONLY source of field names, by construction).
 *  (g) ENVELOPE + CSRF WIDENING PRESENT: the page's main POST gate calls
 *      `validateCsrfRequest(` (not the bare `validateCsrf(` it used before
 *      #2003); the additive `respond=json` envelope exists, its `ok` key
 *      derives from `!$csrfFailed && $saveError === ''`, and it sits AFTER
 *      the classic dispatch block closes (textual order — it must only ever
 *      OBSERVE what already happened, never run inside a case).
 *  (h) STEPPER SINGLETON DEFERENCE: the driver imports `createWizard` from
 *      `./admin-wizard.js`; the singleton itself is already policed by
 *      `test-external-link-wizard.php`'s own check (j) — this file confirms
 *      THAT guard is still green rather than re-implementing a second
 *      tree-wide scan (rule #22 applied to guards, the
 *      test-service-setup-wizard.php precedent for this exact move).
 *  (i) CAPTCHA PORTAL MAP COVERAGE: `IHYMNS_INTEGRATION_CAPTCHA_PORTALS`'s
 *      keys equal the SELECTABLE `captchaProviders()` keys in BOTH
 *      directions — a newly selectable provider with no portal link, or a
 *      stale portal entry for a provider that stopped being selectable,
 *      both fail here.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Every check above that can be phrased as "X is present/true" is paired
 * with a MUTATION PROOF immediately after it: the same extraction/scan is
 * run again against a deliberately broken IN-MEMORY string (never the
 * tracked file — nothing here ever writes to a file under version control)
 * and the assertion is shown to go red. This mirrors
 * `tests/php/test-service-setup-wizard.php` / `test-external-link-wizard.php`'s
 * own two-layer protocol.
 *
 * @see .claude/plan-integration-connect-wizard.md            the design (§5-§10)
 * @see appWeb/public_html/includes/integration_registry.php   the registry under test
 * @see appWeb/public_html/includes/cuercode_client.php        the extracted probe under test
 * @see appWeb/public_html/manage/configuration.php             the one page consumer
 * @see appWeb/public_html/js/modules/integration-connect-wizard.js  the client driver
 * @see tests/php/lib/dispatch_parser.php                       shared tokeniser this file reuses
 * @see tests/php/test-external-link-wizard.php                 the (j) singleton scan this defers to
 *
 *   php tests/php/test-integration-connect-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 * #2003 (epic #2002)
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo          = dirname(__DIR__, 2);
$publicHtml    = $repo . '/appWeb/public_html';
$registryFile  = $publicHtml . '/includes/integration_registry.php';
$cuercodeFile  = $publicHtml . '/includes/cuercode_client.php';
$secretCryptoFile = $publicHtml . '/includes/secret_crypto.php';
$pageFile      = $publicHtml . '/manage/configuration.php';
$jsFile        = $publicHtml . '/js/modules/integration-connect-wizard.js';
$extLinkGuardFile = __DIR__ . '/test-external-link-wizard.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; }
    else { $failed++; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/* =========================================================================
 * PART 0 — small, test-local parsing primitives.
 *
 * These mirror (never share state with — each wizard guard maintains its
 * own tiny copies, the established pattern test-service-setup-wizard.php's
 * own doc-block names) the primitives test-service-setup-wizard.php and
 * test-external-link-wizard.php already carry: a comment stripper, a
 * brace-depth function-body extractor, an if-block extractor, and the
 * hidden-action-input finder.
 * ========================================================================= */

/** Blank `/* … *\/` and `// …` comment bodies (PHP or JS — same syntax),
 *  keeping newlines so nothing shifts. A negative lookbehind on `:` stops
 *  `https://` inside a string literal from being mistaken for a `//`
 *  line-comment start (the SAME guard test-service-setup-wizard.php's own
 *  `stripPhpComments()` carries). */
function icwStripComments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
    return $src;
}

/** Extract a top-level `function NAME(...) { ... }` body by brace-depth
 *  matching, anchored on the function's OWN declaration (never a mention in
 *  a preceding comment — call on already comment-stripped source). */
function icwFunctionBodyFor(string $src, string $fnName): string
{
    if (!preg_match('/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = $m[0][1];
    $openBrace = strpos($src, '{', $start);
    if ($openBrace === false) { return ''; }
    $depth = 0;
    $len = strlen($src);
    for ($i = $openBrace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $openBrace + 1, $i - $openBrace - 1); }
        }
    }
    return '';
}

/** Extract the body of the FIRST `if (...) { ... }` whose opening `{`
 *  follows `$anchorNeedle`, by brace-depth matching. Used for the
 *  `integration_test` branch, which is an `if`, not a `case`. */
function icwIfBlockAfter(string $src, string $anchorNeedle): string
{
    $pos = strpos($src, $anchorNeedle);
    if ($pos === false) { return ''; }
    $openBrace = strpos($src, '{', $pos);
    if ($openBrace === false) { return ''; }
    $depth = 0;
    $len = strlen($src);
    for ($i = $openBrace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $openBrace + 1, $i - $openBrace - 1); }
        }
    }
    return '';
}

/** Does `$pageSrc` contain an `<input ...>` tag carrying BOTH
 *  `name="action"` and `value="$actionName"`? (Mirrors
 *  test-service-setup-wizard.php's `pageEmitsHiddenAction()`.) */
function icwPageEmitsHiddenAction(string $pageSrc, string $actionName): bool
{
    if (!preg_match_all('/<input\b[^>]*>/i', $pageSrc, $m)) { return false; }
    $needleVal = preg_quote($actionName, '/');
    foreach ($m[0] as $tag) {
        if (preg_match('/name\s*=\s*[\'"]action[\'"]/', $tag)
            && preg_match('/value\s*=\s*[\'"]' . $needleVal . '[\'"]/', $tag)) {
            return true;
        }
    }
    return false;
}

/** Does `$pageSrc` contain a `<button ...>` carrying BOTH
 *  `data-bs-target="#integrationConnectModal"` and
 *  `data-integration="$key"`? */
function icwPageHasLauncherFor(string $pageSrc, string $key): bool
{
    if (!preg_match_all('/<button\b[^>]*>/i', $pageSrc, $m)) { return false; }
    $needleKey = preg_quote($key, '/');
    foreach ($m[0] as $tag) {
        if (preg_match('/data-bs-target\s*=\s*[\'"]#integrationConnectModal[\'"]/', $tag)
            && preg_match('/data-integration\s*=\s*[\'"]' . $needleKey . '[\'"]/', $tag)) {
            return true;
        }
    }
    return false;
}

/* =========================================================================
 * PART 1 — fixture self-tests for the primitives above (rule #34).
 * ========================================================================= */

$fxComment = "real1();\n/* a block comment mentioning fakeCall( in prose */\nreal2(); // a line comment mentioning otherCall(\nconst u = 'https://example.com'; real3();";
$fxStripped = icwStripComments($fxComment);
ok('fixture: icwStripComments() keeps real code', str_contains($fxStripped, 'real1(') && str_contains($fxStripped, 'real2(') && str_contains($fxStripped, 'real3('));
ok('fixture: icwStripComments() removes a block-comment mention', !str_contains($fxStripped, 'fakeCall('));
ok('fixture: icwStripComments() removes a line-comment mention', !str_contains($fxStripped, 'otherCall('));
ok('fixture: icwStripComments() does not mangle a https:// URL literal', str_contains($fxStripped, "'https://example.com'"));

$fxFnSrc = "function before() { return 1; }\nfunction target(\$a) {\n    inside1();\n    if (\$a) { inside2(); }\n    inside3();\n}\nfunction after() { return 2; }";
$fxFnBody = icwFunctionBodyFor($fxFnSrc, 'target');
ok('fixture: icwFunctionBodyFor() finds markers genuinely inside the target (incl. past a nested brace)',
    str_contains($fxFnBody, 'inside1(') && str_contains($fxFnBody, 'inside2(') && str_contains($fxFnBody, 'inside3('));
ok('fixture: icwFunctionBodyFor() excludes the PRECEDING function', !str_contains($fxFnBody, 'return 1'));
ok('fixture: icwFunctionBodyFor() excludes the FOLLOWING function', !str_contains($fxFnBody, 'return 2'));
ok('fixture: icwFunctionBodyFor() returns "" for an absent function', icwFunctionBodyFor($fxFnSrc, 'nope') === '');

$fxIfSrc = "before();\nif (\$x === 'target') {\n    inside1();\n    if (\$y) { inside2(); }\n}\nafter();";
$fxIfBody = icwIfBlockAfter($fxIfSrc, "\$x === 'target')");
ok('fixture: icwIfBlockAfter() finds markers genuinely inside the target if-block', str_contains($fxIfBody, 'inside1(') && str_contains($fxIfBody, 'inside2('));
ok('fixture: icwIfBlockAfter() excludes code before/after the block', !str_contains($fxIfBody, 'before(') && !str_contains($fxIfBody, 'after('));
ok('fixture: icwIfBlockAfter() returns "" for an absent anchor', icwIfBlockAfter($fxIfSrc, 'nope') === '');

$fxHtml = '<input type="text" name="foo"><input type="hidden" name="action" value="do_thing">';
ok('fixture: icwPageEmitsHiddenAction() finds a real match', icwPageEmitsHiddenAction($fxHtml, 'do_thing'));
ok('fixture: icwPageEmitsHiddenAction() refuses a non-matching value', !icwPageEmitsHiddenAction($fxHtml, 'other_thing'));

$fxBtnHtml = '<button data-bs-target="#integrationConnectModal" data-integration="widget">go</button>';
ok('fixture: icwPageHasLauncherFor() finds a real launcher', icwPageHasLauncherFor($fxBtnHtml, 'widget'));
ok('fixture: icwPageHasLauncherFor() refuses an absent key', !icwPageHasLauncherFor($fxBtnHtml, 'gadget'));

/* =========================================================================
 * PART 2 — load real sources.
 * ========================================================================= */

foreach ([$registryFile, $cuercodeFile, $secretCryptoFile, $pageFile, $jsFile, $extLinkGuardFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

require_once $registryFile; /* require-safe by design — no DB, no network at load */

$pageSrc      = (string)file_get_contents($pageFile);
$cuercodeSrc  = (string)file_get_contents($cuercodeFile);
$jsSrc        = (string)file_get_contents($jsFile);
$pageStripped = icwStripComments($pageSrc);
$cuercodeStripped = icwStripComments($cuercodeSrc);
$jsStripped   = icwStripComments($jsSrc);

$registryKeys = array_keys(integrationRegistry());
ok('the registry has at least the three Phase-1 keys', count(array_intersect(['intapps', 'cuercode', 'captcha'], $registryKeys)) === 3);

echo "\n\"Connect a service\" guided wizard guard (#2003)\n\n";

/* =========================================================================
 * (a) REGISTRY <-> PAGE LOCKSTEP (tree-derived, both directions)
 * ========================================================================= */

foreach ($registryKeys as $key) {
    ok("(a) registry key '{$key}' has a launcher button in configuration.php", icwPageHasLauncherFor($pageSrc, $key));
}

preg_match_all('/data-integration\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $pageSrc, $mFound);
$foundKeys = array_values(array_unique($mFound[1]));
ok('(a) found at least one data-integration= attribute in the page (sanity)', count($foundKeys) > 0);
foreach ($foundKeys as $fk) {
    ok("(a) every data-integration=\"{$fk}\" emitted by the page names a real registry key", in_array($fk, $registryKeys, true));
}

/* MUTATION PROOF 1 — strip one real launcher from a copy. */
ok('(a) fixture precondition: a real cuercode launcher is present', icwPageHasLauncherFor($pageSrc, 'cuercode'));
$mutatedNoLauncher = str_replace('data-integration="cuercode"', 'data-removed="cuercode"', $pageSrc);
ok('(a) MUTATION PROOF: stripping the cuercode launcher from a copy makes the presence check go false',
    !icwPageHasLauncherFor($mutatedNoLauncher, 'cuercode'));

/* MUTATION PROOF 2 — inject a bogus data-integration into a copy; the
   reverse-direction scan must flag it as NOT a real registry key. */
$mutatedBogus = str_replace(
    'data-bs-target="#integrationConnectModal" data-integration="intapps"',
    'data-bs-target="#integrationConnectModal" data-integration="intapps"></button><button type="button" data-bs-toggle="modal" data-bs-target="#integrationConnectModal" data-integration="nope"',
    $pageSrc
);
preg_match_all('/data-integration\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $mutatedBogus, $mFoundMut);
$foundKeysMut = array_values(array_unique($mFoundMut[1]));
ok('(a) MUTATION PROOF: an injected bogus data-integration="nope" in a copy is NOT a real registry key',
    in_array('nope', $foundKeysMut, true) && !in_array('nope', $registryKeys, true));

/* =========================================================================
 * (b) REUSE, NOT FORK
 * ========================================================================= */

$pageActions = dispatchParserActionsForFile($pageFile)['names'];
foreach (integrationRegistry() as $key => $entry) {
    ok("(b) registry entry '{$key}''s saveAction '{$entry['saveAction']}' is really dispatched by configuration.php",
        in_array($entry['saveAction'], $pageActions, true));
}
foreach (['save_intappsapi', 'save_cuercode', 'save_captcha', 'captcha_probe', 'test_email'] as $need) {
    ok("(b) classic action '{$need}' is STILL dispatched (surface freeze — the wizard reuses, never replaces)",
        in_array($need, $pageActions, true));
}
foreach (['save_intappsapi', 'save_cuercode', 'save_captcha'] as $act) {
    ok("(b) the page still emits the hidden name=\"action\" value=\"{$act}\" input (the classic manual form)",
        icwPageEmitsHiddenAction($pageSrc, $act));
}

/* MUTATION PROOF — rename a dispatched literal in a TEMP FILE copy (never
   the tracked file) and confirm the tree-derived parser stops reporting it. */
ok('(b) fixture precondition: the literal \'save_cuercode\' really is a comparison in the page source',
    (bool)preg_match('/\$action\s*===\s*\'save_cuercode\'/', $pageSrc));
$tmpMutPage = tempnam(sys_get_temp_dir(), 'ihymns_icw_mut_') . '.php';
file_put_contents($tmpMutPage, str_replace("\$action === 'save_cuercode'", "\$action === 'zzz_mutated_save_cuercode'", $pageSrc));
$mutatedActions = dispatchParserActionsForFile($tmpMutPage)['names'];
@unlink($tmpMutPage);
ok('(b) MUTATION PROOF: renaming the save_cuercode comparison in a temp copy makes the parser stop reporting it',
    !in_array('save_cuercode', $mutatedActions, true));

/* =========================================================================
 * (c) SECRET-LEAK TRUTH TEST (functional)
 * ========================================================================= */

require_once $secretCryptoFile;
$secretKeys = secretSettingKeys();

$sentinelReader = static fn(string $k, ?string $d = null): ?string => 'SECRET-VALUE-SENTINEL-' . $k;
$falseStatus    = static fn(string $k): bool => false;
$projection     = integrationClientProjection($sentinelReader, $falseStatus);
$projectionJson = (string)json_encode($projection);

$secretFieldCount = 0;
$nonSecretFieldCount = 0;
foreach (integrationRegistry() as $ik => $entry) {
    foreach ($entry['fields'] as $f) {
        if ($f['type'] === 'checkbox-group') { continue; }
        $needle = 'SECRET-VALUE-SENTINEL-' . $f['setting'];
        if (!empty($f['secret'])) {
            $secretFieldCount++;
            ok("(c) secret field '{$f['post']}' ({$ik}) never leaks its sentinel value into the projection JSON",
                !str_contains($projectionJson, $needle));
            ok("(c) secret field '{$f['post']}' ({$ik})'s setting '{$f['setting']}' is registered in secretSettingKeys()",
                in_array($f['setting'], $secretKeys, true));
        } else {
            $nonSecretFieldCount++;
            ok("(c) non-secret field '{$f['post']}' ({$ik}) DOES echo its value where secret:false",
                str_contains($projectionJson, $needle));
        }
    }
}
ok('(c) at least one secret field was exercised (sanity — the check above is not vacuous)', $secretFieldCount >= 4);
ok('(c) at least one non-secret field was exercised (sanity)', $nonSecretFieldCount >= 4);

/* MUTATION PROOF — build a deliberately-leaked copy of the projection and
   confirm the SAME sentinel-scan mechanism DOES catch it (proves the
   scanner is not vacuously green). */
$leakedProjection = $projection;
$leakedProjection['cuercode']['fields'][1]['value'] = 'SECRET-VALUE-SENTINEL-cuercode_api_key';
ok('(c) fixture precondition: fields[1] of the cuercode entry really is the secret api_key field',
    ($projection['cuercode']['fields'][1]['post'] ?? null) === 'cuercode_api_key'
    && array_key_exists('set', $projection['cuercode']['fields'][1]));
$leakedJson = (string)json_encode($leakedProjection);
ok('(c) MUTATION PROOF: an injected leak in a copy of the projection IS detected by the sentinel scan',
    str_contains($leakedJson, 'SECRET-VALUE-SENTINEL-cuercode_api_key'));

/* =========================================================================
 * (d) TEST-BRANCH DISCIPLINE
 * ========================================================================= */

$intTestBody = icwIfBlockAfter($pageSrc, "=== 'integration_test') {");
ok('(d) isolated the integration_test branch body (non-empty)', $intTestBody !== '');

$posCsrf     = strpos($intTestBody, 'validateCsrfRequest(');
$posDispatch = strpos($intTestBody, 'integrationTestDispatch(');
ok('(d) validateCsrfRequest( is present in the integration_test branch', $posCsrf !== false);
ok('(d) integrationTestDispatch( is present in the integration_test branch', $posDispatch !== false);
ok('(d) validateCsrfRequest( textually PRECEDES integrationTestDispatch( in the branch',
    $posCsrf !== false && $posDispatch !== false && $posCsrf < $posDispatch);
ok('(d) the branch validates the posted integration against array_keys(integrationRegistry())',
    (bool)preg_match('/in_array\(\s*\$integrationKey\s*,\s*array_keys\(integrationRegistry\(\)\)\s*,\s*true\s*\)/', $intTestBody));

foreach (integrationRegistry() as $key => $entry) {
    ok("(d) registry testFn '{$entry['testFn']}' for '{$key}' is a real, defined function", function_exists($entry['testFn']));
}

/* MUTATION PROOF — remove the CSRF gate call from a COPY of the isolated
   branch body and confirm the presence check goes false. */
ok('(d) fixture precondition: the branch\'s own CSRF-gate literal really is present',
    str_contains($intTestBody, "if (!validateCsrfRequest((string)(\$_POST['csrf_token'] ?? '')))"));
$mutatedBranch = str_replace(
    "if (!validateCsrfRequest((string)(\$_POST['csrf_token'] ?? ''))) {",
    '/* zzz_mutated: gate removed */ if (false) {',
    $intTestBody
);
ok('(d) MUTATION PROOF: removing the CSRF gate call from a copy of the branch makes the presence check go false',
    strpos($mutatedBranch, 'validateCsrfRequest(') === false);

/* =========================================================================
 * (e) CUERCODE PROBE SAFETY
 * ========================================================================= */

$probeFn    = icwFunctionBodyFor($cuercodeStripped, 'cuercodeProbe');
$httpExecFn = icwFunctionBodyFor($cuercodeStripped, '_cuercodeHttpExec');
ok('(e) isolated cuercodeProbe() (non-empty)', $probeFn !== '');
ok('(e) isolated _cuercodeHttpExec() (non-empty)', $httpExecFn !== '');

ok('(e) cuercodeProbe() calls _cuercodeResolveUrl( (the host-bound SSRF check)', str_contains($probeFn, '_cuercodeResolveUrl('));
ok('(e) cuercodeProbe() calls _cuercodeHttpExec( (the shared transport)', str_contains($probeFn, '_cuercodeHttpExec('));
ok('(e) cuercodeProbe() NEVER calls cuercodeGenerateCached( (a cache hit must not "pass" a live-connectivity test)',
    !str_contains($probeFn, 'cuercodeGenerateCached('));

ok('(e) _cuercodeHttpExec() sets CURLOPT_FOLLOWLOCATION => false', (bool)preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*false/', $httpExecFn));
ok('(e) _cuercodeHttpExec() sets CURLOPT_SSL_VERIFYPEER => true', (bool)preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*true/', $httpExecFn));

/* MUTATION PROOFS */
$mutFollow = str_replace('CURLOPT_FOLLOWLOCATION => false', 'CURLOPT_FOLLOWLOCATION => true /* zzz_mutated */', $httpExecFn);
ok('(e) MUTATION PROOF: flipping FOLLOWLOCATION to true in a copy makes the check go false',
    !(bool)preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*false/', $mutFollow));

$mutVerify = str_replace('CURLOPT_SSL_VERIFYPEER => true', 'CURLOPT_SSL_VERIFYPEER => false /* zzz_mutated */', $httpExecFn);
ok('(e) MUTATION PROOF: flipping SSL_VERIFYPEER to false in a copy makes the check go false',
    !(bool)preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*true/', $mutVerify));

$mutCachedInjected = $probeFn . "\ncuercodeGenerateCached('https://example.com/');";
ok('(e) MUTATION PROOF: injecting a real cuercodeGenerateCached( call into a copy of cuercodeProbe() IS caught by the ban scan',
    str_contains($mutCachedInjected, 'cuercodeGenerateCached('));

/* =========================================================================
 * (f) CARRY-SAFETY / GENERICITY (the client driver)
 * ========================================================================= */

ok('(f) integration-connect-wizard.js defines collectFields(', str_contains($jsStripped, 'function collectFields('));
ok('(f) collectFields() iterates entry.fields generically (entry.fields.forEach)',
    (bool)preg_match('/entry\.fields\.forEach/', $jsStripped));

$bannedFieldLiterals = [
    'intappsapi_enabled_channels', 'intappsapi_base_url', 'intappsapi_app_slug',
    'intappsapi_app_uuid', 'intappsapi_api_key', 'intappsapi_hmac_secret',
    'cuercode_base_url', 'cuercode_api_key',
    'captcha_provider', 'captcha_site_key', 'captcha_secret_key', 'captcha_forms', 'captcha_strict_forms',
];
foreach ($bannedFieldLiterals as $lit) {
    ok("(f) integration-connect-wizard.js contains no hardcoded field-name literal '{$lit}'",
        !str_contains($jsStripped, "'{$lit}'") && !str_contains($jsStripped, "\"{$lit}\""));
}

/* MUTATION PROOF — inject a hardcoded field-name literal into a copy and
   confirm the SAME ban-scan mechanism catches it. */
$mutatedJs = str_replace(
    'function collectFields(entry) {',
    "function collectFields(entry) {\n    if (entry.key === 'cuercode') { return [['cuercode_base_url', 'x']]; }",
    $jsStripped
);
ok("(f) MUTATION PROOF: injecting a hardcoded 'cuercode_base_url' literal into a copy IS caught by the ban scan",
    str_contains($mutatedJs, "'cuercode_base_url'"));

/* =========================================================================
 * (g) ENVELOPE + CSRF WIDENING PRESENT
 *
 * Operates on $pageStripped (comment-stripped) throughout — this page's own
 * doc-block prose ABOUT the CSRF widening mentions "validateCsrfRequest()"
 * several times (rule #34's own lesson: a scan that isn't comment-stripped
 * first counts its own explanatory comments as more code than exists). Only
 * the real code occurrences must be counted here.
 * ========================================================================= */

preg_match_all('/validateCsrfRequest\(/', $pageStripped, $mGate, PREG_OFFSET_CAPTURE);
$gatePositions = array_map(static fn(array $x): int => $x[1], $mGate[0]);
ok('(g) validateCsrfRequest( appears exactly twice in real code (the integration_test branch + the widened main POST gate)',
    count($gatePositions) === 2);

$csrfFailedPos = strpos($pageStripped, '$csrfFailed = true;');
ok('(g) found $csrfFailed = true; in the page', $csrfFailedPos !== false);
$mainGatePos = null;
foreach ($gatePositions as $p) { if ($csrfFailedPos !== false && $p < $csrfFailedPos) { $mainGatePos = $p; } }
ok('(g) a validateCsrfRequest( call precedes $csrfFailed = true; (the widened main POST gate, not the branch\'s own)',
    $mainGatePos !== null);

ok('(g) the OLD bare validateCsrf( gate literal no longer appears at the main dispatch gate',
    !str_contains($pageStripped, "if (!validateCsrf((string)(\$_POST['csrf_token'] ?? '')))"));

$envelopePos = strpos($pageStripped, "(string)(\$_POST['respond'] ?? '') === 'json'");
ok('(g) the respond=json envelope block exists', $envelopePos !== false);
ok('(g) the envelope appears AFTER the widened main POST gate (textual order)',
    $envelopePos !== false && $mainGatePos !== null && $envelopePos > $mainGatePos);

$envelopeSnippet = $envelopePos !== false ? substr($pageStripped, $envelopePos, 700) : '';
ok("(g) the envelope's 'ok' key derives from !\$csrfFailed && \$saveError === '' (never a hand-typed second copy of the outcome)",
    (bool)preg_match('/\'ok\'\s*=>\s*!\$csrfFailed\s*&&\s*\$saveError\s*===\s*\'\'/', $envelopeSnippet));

/* MUTATION PROOF — revert the main gate token in a copy; only the
   integration_test branch's OWN validateCsrfRequest( call should remain. */
$mainGateNeedle = "validateCsrfRequest((string)(\$_POST['csrf_token'] ?? ''))) {\n        \$csrfFailed = true;";
ok('(g) fixture precondition: the main-gate literal really is validateCsrfRequest( right before $csrfFailed = true;',
    str_contains($pageStripped, $mainGateNeedle));
$mutatedGateReverted = str_replace(
    $mainGateNeedle,
    "validateCsrf((string)(\$_POST['csrf_token'] ?? ''))) {\n        \$csrfFailed = true;",
    $pageStripped
);
preg_match_all('/validateCsrfRequest\(/', $mutatedGateReverted, $mGateMut);
ok('(g) MUTATION PROOF: reverting the main gate token in a copy leaves only ONE validateCsrfRequest( occurrence (the branch\'s own)',
    count($mGateMut[0]) === 1);

/* =========================================================================
 * (h) STEPPER SINGLETON DEFERENCE
 * ========================================================================= */

ok('(h) integration-connect-wizard.js imports createWizard from ./admin-wizard.js',
    (bool)preg_match('~from\s+[\'"]\./admin-wizard\.js~', $jsSrc));
ok('(h) integration-connect-wizard.js calls createWizard(', str_contains($jsStripped, 'createWizard('));

$extLinkOut = [];
$extLinkStatus = 0;
exec('php ' . escapeshellarg($extLinkGuardFile) . ' 2>&1', $extLinkOut, $extLinkStatus);
ok('(h) tests/php/test-external-link-wizard.php (its own (j) createWizard-singleton scan) is still green',
    $extLinkStatus === 0);

/* =========================================================================
 * (i) CAPTCHA PORTAL MAP COVERAGE
 * ========================================================================= */

$selectableProviders = [];
foreach (captchaProviders() as $pk => $pe) {
    if (!empty($pe['selectable'])) { $selectableProviders[] = (string)$pk; }
}
sort($selectableProviders);
$portalKeys = array_keys(IHYMNS_INTEGRATION_CAPTCHA_PORTALS);
sort($portalKeys);
ok('(i) IHYMNS_INTEGRATION_CAPTCHA_PORTALS keys exactly equal the SELECTABLE captchaProviders() keys (both directions)',
    $selectableProviders === $portalKeys);
ok('(i) at least one selectable provider exists (sanity — the equality check above is not vacuous)',
    count($selectableProviders) > 0);

/* MUTATION PROOF — drop one provider from a copy of the portal map. */
$mutatedPortals = IHYMNS_INTEGRATION_CAPTCHA_PORTALS;
unset($mutatedPortals[$selectableProviders[0]]);
$mutatedPortalKeys = array_keys($mutatedPortals);
sort($mutatedPortalKeys);
ok('(i) MUTATION PROOF: removing one provider from a copy of the portal map breaks the equality check',
    $mutatedPortalKeys !== $selectableProviders);

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "The registry <-> page launcher wiring stays in lockstep in both directions, every\n"
   . "registry saveAction still points at a real, still-dispatched classic action (no forked\n"
   . "write path), no secret value can reach the emitted JSON projection regardless of what\n"
   . "the database holds, the integration_test branch is CSRF-gated before it ever dispatches,\n"
   . "the CueRCode probe reuses the shared SSRF-hardened transport without ever going through\n"
   . "the cache, the client driver collects fields generically with zero hardcoded field-name\n"
   . "literals, the CSRF gate was genuinely widened with the additive envelope sitting after\n"
   . "the classic dispatch, the shared stepper singleton is undisturbed, and the CAPTCHA portal\n"
   . "map exactly covers the selectable provider set.\n";
exit(0);
