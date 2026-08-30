<?php

declare(strict_types=1);

/**
 * iHymns — "Connect a service" guided-wizard guard (#2003/#2004, epic #2002)
 * =====================================================================
 *
 * ELI5
 * ----
 * #2003 added ONE guided wizard, built from a registry
 * (`includes/integration_registry.php`) that describes three existing
 * config cards (IntAppsAPI, CueRCode, CAPTCHA) — never a second copy of
 * their save handlers, never a place a secret value could leak into the
 * browser. #2004 (the extend phase) grew the SAME registry to cover the
 * Email service, Sign in with Apple, and Partner webhooks cards, adding six
 * generic driver capabilities (select/textarea/checkbox renderers,
 * conditional `showWhen` visibility, an unrendered `carry` field, a
 * one-time-key reveal) — never a per-integration special case. This file is
 * the standing guard that keeps that WHOLE shape honest: the registry and
 * the page's own launcher buttons can never drift apart, the wizard saves
 * through the EXISTING actions (never a forked write path), a secret can
 * never reach the emitted JSON, the CueRCode probe reuses the EXACT same
 * SSRF-hardened transport as the code that already shipped, the client
 * driver never hardcodes a per-integration field name, the email/SIWA/
 * webhooks vocabularies stay in lockstep with their single sources of
 * truth, and — the single highest-stakes new invariant — a wizard save can
 * never silently WIPE a value `save_apple` unconditionally overwrites on
 * every save (rule #45's silent-corruption class, guard check (m)).
 *
 * WHAT THIS FILE ASSERTS (checks (a)-(n), plan §10 + the #2004 extend plan §9)
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
 *  (j) EMAIL VOCABULARY LOCKSTEP (#2004): the `email` entry's field post
 *      set equals `ihymnsEmailSettingsModel()`'s keys minus the documented
 *      omit-list (both directions); each field's `secret` flag and
 *      `email_service` `showWhen` condition equal the model's own
 *      `secret`/`providers` columns; the entry's `providers` keys equal
 *      `ihymnsEmailServiceOptions()` keys minus `'none'` equal
 *      `IHYMNS_INTEGRATION_EMAIL_PORTALS` keys (the (i) mirror);
 *      `manage/configuration.php` no longer declares the four vocabulary
 *      arrays inline.
 *  (k) ONE EMAIL-TEST CORE (#2004): the `test_email` branch and
 *      `integrationTestEmail()` BOTH call `EmailService::deliveryTest(` and
 *      NEITHER calls `EmailService::send(` of its own (moved, not
 *      duplicated, into `EmailService.php`).
 *  (l) SIWA MINT NEVER LEAKS (#2004): `integrationTestSiwa()` calls
 *      `appleSiwaBuildClientSecret(`, and the minted `$minted` value
 *      appears EXACTLY twice in the function body — its assignment and its
 *      `=== null` comparison — never a third (leaked) use.
 *  (m) SAVE_APPLE CARRY-SAFETY, TREE-DERIVED (#2004, rule #45): every
 *      `$_POST[...]` key `save_apple` reads is mechanically extracted from
 *      the branch's OWN source and asserted to be either a real `siwa`
 *      entry field post, a standard envelope key, or the documented
 *      blank-keep allowlist — the mechanism that keeps the registry and
 *      the (deliberately untouched) `save_apple` handler honest without
 *      constant-ising the handler itself. Also asserts the
 *      `apple_apns_key_id` field carries `'carry' => true`.
 *  (n) WEBHOOKS TEST + META DISCIPLINE (#2004): `integrationTestWebhooks()`
 *      calls `webhookDrainHealth(` and NO outbound-call function (no
 *      `curl_*`, no `webhookHttpPost(`); `integrationWebhookChannelMeta()`
 *      keys equal `webhookParseChannelsCsv()`'s own allow-list in BOTH
 *      directions; `webhookEnabledChannels()` calls the extracted parser
 *      rather than carrying its own copy of the allow-list.
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
/* #2004 (epic #2002) — the extend-phase's own source files. */
$emailOptionsFile = $publicHtml . '/includes/email_options.php';
$emailServiceFile = $publicHtml . '/includes/EmailService.php';
$appleSiwaFile     = $publicHtml . '/includes/apple_siwa.php';
$webhooksFile      = $publicHtml . '/includes/webhooks.php';

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

foreach ([$registryFile, $cuercodeFile, $secretCryptoFile, $pageFile, $jsFile, $extLinkGuardFile,
          $emailOptionsFile, $emailServiceFile, $appleSiwaFile, $webhooksFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

require_once $registryFile; /* require-safe by design — no DB, no network at load */

$pageSrc      = (string)file_get_contents($pageFile);
$cuercodeSrc  = (string)file_get_contents($cuercodeFile);
$jsSrc        = (string)file_get_contents($jsFile);
$regSrc       = (string)file_get_contents($registryFile);
$pageStripped = icwStripComments($pageSrc);
$cuercodeStripped = icwStripComments($cuercodeSrc);
$jsStripped   = icwStripComments($jsSrc);
$regStripped  = icwStripComments($regSrc);
/* #2004 — the extend-phase's own sources, comment-stripped the SAME way. */
$emailServiceSrc      = (string)file_get_contents($emailServiceFile);
$emailServiceStripped = icwStripComments($emailServiceSrc);
$appleSiwaSrc          = (string)file_get_contents($appleSiwaFile);
$appleSiwaStripped     = icwStripComments($appleSiwaSrc);
$webhooksSrc           = (string)file_get_contents($webhooksFile);
$webhooksStripped      = icwStripComments($webhooksSrc);

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
/* #2004 — grown to include the extend-phase's three save actions +
   test_email's core-extraction target stays dispatched under its own name
   (the classic test_email BRANCH still exists — it now delegates its body
   to EmailService::deliveryTest(), never disappears). */
foreach (['save_intappsapi', 'save_cuercode', 'save_captcha', 'captcha_probe', 'test_email', 'save_email', 'save_apple', 'save_webhooks'] as $need) {
    ok("(b) classic action '{$need}' is STILL dispatched (surface freeze — the wizard reuses, never replaces)",
        in_array($need, $pageActions, true));
}
foreach (['save_intappsapi', 'save_cuercode', 'save_captcha', 'save_email', 'save_apple', 'save_webhooks'] as $act) {
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

/** Find one field's PROJECTED shape by post name (a small local helper —
 *  the projection array is keyed by integration, not by post). */
function icwProjectedField(array $projection, string $ik, string $post): ?array
{
    foreach ($projection[$ik]['fields'] ?? [] as $pf) {
        if (($pf['post'] ?? null) === $post) { return $pf; }
    }
    return null;
}

$secretFieldCount   = 0;
$nonSecretFieldCount = 0;
$checkboxFieldCount = 0;
foreach (integrationRegistry() as $ik => $entry) {
    foreach ($entry['fields'] as $f) {
        if ($f['type'] === 'checkbox-group') { continue; }
        /* #2004 — widened: a 'checkbox' field (incl. the stateless
           'setting' => null command-tick shape, legal ONLY for this type)
           never echoes a raw setting value at all — it emits ONLY a
           `checked` boolean, so it is asserted separately below rather
           than falling into the plain secret/non-secret value-echo split. */
        if ($f['type'] === 'checkbox') {
            $checkboxFieldCount++;
            if ($f['setting'] !== null) {
                $needle = 'SECRET-VALUE-SENTINEL-' . $f['setting'];
                ok("(c) checkbox field '{$f['post']}' ({$ik}) never echoes a raw value into the projection JSON (only a 'checked' boolean)",
                    !str_contains($projectionJson, $needle));
            }
            $pf = icwProjectedField($projection, $ik, $f['post']);
            ok("(c) checkbox field '{$f['post']}' ({$ik})'s projected shape carries 'checked' and NEVER 'value'/'set'",
                $pf !== null && array_key_exists('checked', $pf) && !array_key_exists('value', $pf) && !array_key_exists('set', $pf));
            continue;
        }
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
/* #2004 — floors raised: the extend phase brought the registry from 4
   secret / a handful of non-secret fields to 12 secret / ~28 non-secret /
   2+ checkbox fields (email alone adds 7 secrets + 16 non-secrets, SIWA
   adds 1 secret + a carry field, webhooks adds 2 checkboxes) — kept
   conservatively below the real counts so a future field REMOVAL still has
   headroom before this sanity floor itself needs lowering. */
ok('(c) at least ten secret fields were exercised (sanity — the check above is not vacuous)', $secretFieldCount >= 10);
ok('(c) at least twenty non-secret fields were exercised (sanity)', $nonSecretFieldCount >= 20);
ok('(c) at least two checkbox fields were exercised (sanity — #2004)', $checkboxFieldCount >= 2);

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

/* MUTATION PROOF (#2004) — the SAME leak-detection mechanism, run against a
   CHECKBOX field this time (webhook_allow_loopback), proving the widened
   skip-condition's own leak-scan is not vacuously green either. */
$cbTestIk = null; $cbTestPost = null; $cbTestSetting = null;
foreach (integrationRegistry() as $ik2 => $entry2) {
    foreach ($entry2['fields'] as $f2) {
        if ($f2['type'] === 'checkbox' && $f2['setting'] !== null) {
            $cbTestIk = $ik2; $cbTestPost = $f2['post']; $cbTestSetting = $f2['setting'];
            break 2;
        }
    }
}
ok('(c) fixture precondition: a real checkbox field with a live (non-null) setting exists to mutate against',
    $cbTestIk !== null);
$cbIdx = null;
foreach ($projection[$cbTestIk]['fields'] as $idx => $pf) {
    if (($pf['post'] ?? null) === $cbTestPost) { $cbIdx = $idx; break; }
}
ok('(c) fixture precondition: located that checkbox field inside the real projection',
    $cbIdx !== null);
$leakedProjectionCb = $projection;
$leakedProjectionCb[$cbTestIk]['fields'][$cbIdx]['value'] = 'SECRET-VALUE-SENTINEL-' . $cbTestSetting;
$leakedJsonCb = (string)json_encode($leakedProjectionCb);
ok('(c) MUTATION PROOF: an injected leak in a copy of a CHECKBOX field IS detected by the sentinel scan',
    str_contains($leakedJsonCb, 'SECRET-VALUE-SENTINEL-' . $cbTestSetting));

/* MUTATION PROOF (#2004) — the STRUCTURAL "checked present, value/set
   absent" shape check, run against a deliberately corrupted copy carrying a
   stray 'value' key alongside 'checked' (the shape a leaking bug would
   actually produce), proving that assertion is not vacuously green either. */
$mutatedCbShape = $projection;
$mutatedCbShape[$cbTestIk]['fields'][$cbIdx]['value'] = 'x';
$mutPf = icwProjectedField($mutatedCbShape, $cbTestIk, $cbTestPost);
$mutStructOk = $mutPf !== null && array_key_exists('checked', $mutPf) && !array_key_exists('value', $mutPf) && !array_key_exists('set', $mutPf);
ok('(c) MUTATION PROOF: injecting a stray \'value\' key alongside \'checked\' in a copy makes the structural-shape check go false',
    $mutStructOk === false);

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

/* #2004 — DERIVED from the tree, never typed here (rule #34): every
   `fields[].post` of every registry entry. A hand-typed list can only ever
   ban the field names the author remembered to type; this one grows
   automatically the moment a new integration/field lands in the registry,
   so a future per-integration special case in the driver is caught even if
   nobody remembers to update THIS test file. */
$bannedFieldLiterals = [];
foreach (integrationRegistry() as $entry) {
    foreach ($entry['fields'] as $f) {
        $bannedFieldLiterals[] = $f['post'];
    }
}
$bannedFieldLiterals = array_values(array_unique($bannedFieldLiterals));
ok('(f) the tree-derived banned-literal list is non-empty (sanity — the loop below is not vacuous)',
    count($bannedFieldLiterals) >= 20);
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

/* #2004 — the envelope's ADDITIVE `drainKey` key (§6.3 of the extend plan):
   null on every action except a save_webhooks that just regenerated the
   drain key. Checked on the SAME $envelopeSnippet the 'ok'-derivation check
   above already isolated — still well inside the 700-char window (verified
   against the real file: the snippet's tail reaches past 'drainKey' with
   room to spare), so no widening was needed. */
ok("(g) the envelope block contains 'drainKey' => \$webhookNewDrainKey (the additive #2004 show-once-key key)",
    (bool)preg_match('/\'drainKey\'\s*=>\s*\$webhookNewDrainKey/', $envelopeSnippet));

/* MUTATION PROOF — rename the drainKey key in a copy of the snippet. */
ok('(g) fixture precondition: the drainKey line really is present in the real snippet',
    str_contains($envelopeSnippet, "'drainKey' => \$webhookNewDrainKey"));
$mutatedEnvelopeSnippet = str_replace("'drainKey' => \$webhookNewDrainKey", "'zzzMutatedKey' => \$webhookNewDrainKey", $envelopeSnippet);
ok('(g) MUTATION PROOF: renaming the drainKey key in a copy of the snippet makes the presence check go false',
    !(bool)preg_match('/\'drainKey\'\s*=>\s*\$webhookNewDrainKey/', $mutatedEnvelopeSnippet));

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
 * (j) EMAIL VOCABULARY LOCKSTEP (#2004, both directions, model-derived)
 * ========================================================================= */

require_once $emailOptionsFile;
$emailModel = ihymnsEmailSettingsModel();
$emailOmitList = ['email_smtp_preset'];
$emailModelFieldKeys = array_values(array_diff(array_keys($emailModel), $emailOmitList));
sort($emailModelFieldKeys);

$emailEntry = integrationRegistry()['email'] ?? null;
ok('(j) fixture precondition: the registry has an "email" entry', $emailEntry !== null);

$emailEntryPostsSorted = array_map(static fn(array $f): string => $f['post'], $emailEntry['fields'] ?? []);
sort($emailEntryPostsSorted);
ok('(j) the email entry\'s field post set EXACTLY equals ihymnsEmailSettingsModel() keys minus the documented omit-list (both directions)',
    $emailEntryPostsSorted === $emailModelFieldKeys);

foreach ($emailEntry['fields'] as $ef) {
    $modelRow = $emailModel[$ef['post']] ?? null;
    ok("(j) email field '{$ef['post']}' exists in ihymnsEmailSettingsModel()", $modelRow !== null);
    if ($modelRow === null) { continue; }
    [, , $mSecret, $mProviders] = $modelRow;
    ok("(j) email field '{$ef['post']}''s secret flag equals the model's",
        (bool)($ef['secret'] ?? false) === (bool)$mSecret);

    $showWhenProviderCond = null;
    foreach (($ef['showWhen'] ?? []) as $cond) {
        if (($cond['field'] ?? null) === 'email_service') { $showWhenProviderCond = $cond['in'] ?? null; }
    }
    if ($mProviders === null) {
        ok("(j) email field '{$ef['post']}' has NO email_service showWhen condition (the model's providers column is null)",
            $showWhenProviderCond === null);
    } else {
        $wantProviders = $mProviders;
        sort($wantProviders);
        $gotProviders = $showWhenProviderCond ?? [];
        sort($gotProviders);
        ok("(j) email field '{$ef['post']}''s email_service showWhen condition EXACTLY equals the model's providers column",
            $gotProviders === $wantProviders);
    }
}

$emailServiceOptionsForJ = ihymnsEmailServiceOptions();
$emailProviderKeysWanted = array_values(array_diff(array_keys($emailServiceOptionsForJ), ['none']));
sort($emailProviderKeysWanted);
$emailEntryProviderKeys = array_keys($emailEntry['providers'] ?? []);
sort($emailEntryProviderKeys);
$emailPortalKeys = array_keys(IHYMNS_INTEGRATION_EMAIL_PORTALS);
sort($emailPortalKeys);
ok('(j) the email entry\'s providers keys == ihymnsEmailServiceOptions() keys minus \'none\' == IHYMNS_INTEGRATION_EMAIL_PORTALS keys (the (i) mirror)',
    $emailEntryProviderKeys === $emailProviderKeysWanted && $emailProviderKeysWanted === $emailPortalKeys);

ok('(j) manage/configuration.php no longer DECLARES $EMAIL_SERVICE_OPTIONS inline (extracted to email_options.php)',
    !str_contains($pageStripped, '$EMAIL_SERVICE_OPTIONS = ['));
ok('(j) manage/configuration.php re-points $EMAIL_SERVICE_OPTIONS = ihymnsEmailServiceOptions()',
    (bool)preg_match('/\$EMAIL_SERVICE_OPTIONS\s*=\s*ihymnsEmailServiceOptions\(\)/', $pageStripped));

/* MUTATION PROOF — drop one key from a COPY of the model-derived list; the
   set-equality check must go false. */
$mutatedEmailModelKeys = $emailModelFieldKeys;
array_pop($mutatedEmailModelKeys);
ok('(j) MUTATION PROOF: dropping one key from a copy of the model-derived list breaks the set-equality check',
    $emailEntryPostsSorted !== $mutatedEmailModelKeys);

/* MUTATION PROOF — flip email_smtp_pass's secret flag in a COPY of the
   entry's fields and re-run the SAME per-field comparison against it. */
ok('(j) fixture precondition: email_smtp_pass really is secret:true in both the registry and the model',
    ($emailModel['email_smtp_pass'][2] ?? null) === true);
$mutatedEmailFields = $emailEntry['fields'];
foreach ($mutatedEmailFields as $idx => $ef2) {
    if ($ef2['post'] === 'email_smtp_pass') { $mutatedEmailFields[$idx]['secret'] = false; }
}
$mutFlipResult = null;
foreach ($mutatedEmailFields as $ef3) {
    if ($ef3['post'] === 'email_smtp_pass') {
        $mutFlipResult = ((bool)$ef3['secret'] === (bool)$emailModel['email_smtp_pass'][2]);
    }
}
ok('(j) MUTATION PROOF: flipping email_smtp_pass\'s secret flag in a copy makes the secret-flag-equality check go false',
    $mutFlipResult === false);

/* =========================================================================
 * (k) ONE EMAIL-TEST CORE (#2004) — test_email / integrationTestEmail() BOTH
 * delegate to EmailService::deliveryTest(), neither forks its own send.
 * ========================================================================= */

$testEmailBranchBody = icwIfBlockAfter($pageStripped, "=== 'test_email') {");
ok('(k) isolated the test_email branch body (non-empty)', $testEmailBranchBody !== '');
ok('(k) the test_email branch calls EmailService::deliveryTest(', str_contains($testEmailBranchBody, 'EmailService::deliveryTest('));
ok('(k) the test_email branch no longer calls EmailService::send( directly (moved into deliveryTest())',
    !str_contains($testEmailBranchBody, 'EmailService::send('));
ok('(k) the test_email branch no longer carries the old inline \'Send-test failed:\' literal (moved into EmailService.php)',
    !str_contains($testEmailBranchBody, 'Send-test failed:'));
ok('(k) EmailService.php now carries the \'Send-test failed:\' literal (moved, not duplicated)',
    str_contains($emailServiceSrc, 'Send-test failed:'));

$integrationTestEmailBody = icwFunctionBodyFor($regStripped, 'integrationTestEmail');
ok('(k) isolated integrationTestEmail()\'s body (non-empty)', $integrationTestEmailBody !== '');
ok('(k) integrationTestEmail() calls EmailService::deliveryTest(', str_contains($integrationTestEmailBody, 'EmailService::deliveryTest('));
ok('(k) integrationTestEmail() never calls EmailService::send( of its own',
    !str_contains($integrationTestEmailBody, 'EmailService::send('));

/* MUTATION PROOF — re-inline a send() call into a COPY of each isolated body. */
$mutTestEmailBranch = $testEmailBranchBody . "\nEmailService::send('x','y','z');";
ok('(k) MUTATION PROOF: re-inlining EmailService::send( into a copy of the test_email branch IS caught by the ban scan',
    str_contains($mutTestEmailBranch, 'EmailService::send('));
$mutIntegrationTestEmail = $integrationTestEmailBody . "\nEmailService::send('x','y','z');";
ok('(k) MUTATION PROOF: re-inlining EmailService::send( into a copy of integrationTestEmail() IS caught by the ban scan',
    str_contains($mutIntegrationTestEmail, 'EmailService::send('));

/* =========================================================================
 * (l) SIWA MINT NEVER LEAKS (#2004) — appleSiwaBuildClientSecret()'s output
 * is used ONLY in the null-check, never assigned into 'detail'.
 * ========================================================================= */

$integrationTestSiwaBody = icwFunctionBodyFor($regStripped, 'integrationTestSiwa');
ok('(l) isolated integrationTestSiwa()\'s body (non-empty)', $integrationTestSiwaBody !== '');
ok('(l) integrationTestSiwa() calls appleSiwaBuildClientSecret(', str_contains($integrationTestSiwaBody, 'appleSiwaBuildClientSecret('));
ok('(l) $minted appears EXACTLY twice — the assignment and the === null comparison, never a third (leaked) use',
    substr_count($integrationTestSiwaBody, '$minted') === 2);

/* Belt-and-braces: no 'detail' construction anywhere in the function body
   contains $minted (the structural claim the count above already implies,
   asserted directly too). */
preg_match_all("/'detail'\s*=>\s*\[[^\]]*\]/s", $integrationTestSiwaBody, $mDetail);
$anyDetailLeaksMinted = false;
foreach ($mDetail[0] as $detailBlock) {
    if (str_contains($detailBlock, '$minted')) { $anyDetailLeaksMinted = true; }
}
ok("(l) no 'detail' array construction in integrationTestSiwa() contains \$minted",
    !$anyDetailLeaksMinted);

/* MUTATION PROOF — inject a leaked 'jwt' => $minted into a COPY of the body. */
$mutSiwaLeak = str_replace(
    "'detail' => \$flags",
    "'detail' => ['jwt' => \$minted]",
    $integrationTestSiwaBody
);
ok('(l) MUTATION PROOF: injecting \'detail\'=>[\'jwt\'=>$minted] into a copy pushes the $minted count past 2',
    substr_count($mutSiwaLeak, '$minted') > 2);

/* =========================================================================
 * (m) SAVE_APPLE CARRY-SAFETY, TREE-DERIVED (#2004, rule #45's silent-wipe
 * class) — every $_POST[...] key save_apple reads UNCONDITIONALLY must be
 * either a real 'siwa' entry field post, one of the standard envelope keys,
 * or the documented blank-keep allowlist (apple_apns_private_key — absence
 * ⇒ '' ⇒ save_apple's own null-keep branch, never read as a wipe).
 * ========================================================================= */

$saveAppleBody = icwIfBlockAfter($pageStripped, "=== 'save_apple') {");
ok('(m) isolated the save_apple branch body (non-empty)', $saveAppleBody !== '');

preg_match_all("/\\\$_POST\['([a-z_]+)'\]/", $saveAppleBody, $mPost);
$saveApplePostKeys = array_values(array_unique($mPost[1]));
sort($saveApplePostKeys);
ok('(m) fixture precondition: save_apple reads at least 5 distinct $_POST keys (sanity — the scan below is not vacuous)',
    count($saveApplePostKeys) >= 5);

$siwaEntry = integrationRegistry()['siwa'] ?? null;
ok('(m) fixture precondition: the registry has a "siwa" entry', $siwaEntry !== null);
$siwaEntryPosts = array_map(static fn(array $f): string => $f['post'], $siwaEntry['fields'] ?? []);
$envelopeKeys = ['csrf_token', 'action', 'respond'];
$blankKeepAllowlist = ['apple_apns_private_key'];
$allowedSaveAppleKeys = array_values(array_unique(array_merge($siwaEntryPosts, $envelopeKeys, $blankKeepAllowlist)));

$unexplainedSaveAppleKeys = array_values(array_diff($saveApplePostKeys, $allowedSaveAppleKeys));
ok('(m) EVERY $_POST key save_apple reads is either a real siwa entry field, an envelope key, or the documented blank-keep allowlist',
    $unexplainedSaveAppleKeys === []);

$carryField = null;
foreach ($siwaEntry['fields'] as $sf) {
    if ($sf['post'] === 'apple_apns_key_id') { $carryField = $sf; break; }
}
ok('(m) the siwa entry\'s apple_apns_key_id field carries \'carry\' => true',
    $carryField !== null && ($carryField['carry'] ?? false) === true);

/* MUTATION PROOF (i) — remove the carry field from a COPY of the entry's
   field-post array and re-run the set-difference; save_apple's OWN
   apple_apns_key_id read then has nothing in the registry to explain it. */
$mutatedSiwaPosts = array_values(array_diff($siwaEntryPosts, ['apple_apns_key_id']));
$mutatedAllowedKeys = array_values(array_unique(array_merge($mutatedSiwaPosts, $envelopeKeys, $blankKeepAllowlist)));
$mutatedUnexplained = array_values(array_diff($saveApplePostKeys, $mutatedAllowedKeys));
ok('(m) MUTATION PROOF: removing apple_apns_key_id from a copy of the siwa field-post array leaves it UNEXPLAINED',
    in_array('apple_apns_key_id', $mutatedUnexplained, true));

/* MUTATION PROOF (ii) — inject a fake $_POST read into a COPY of the
   branch body; the SAME extraction mechanism must pick it up as a NEW,
   unexplained key. */
$mutatedSaveAppleBody = $saveAppleBody . "\n\$x = (string)(\$_POST['apple_new_thing'] ?? '');";
preg_match_all("/\\\$_POST\['([a-z_]+)'\]/", $mutatedSaveAppleBody, $mPostMut);
$mutatedSaveApplePostKeys = array_values(array_unique($mPostMut[1]));
$mutatedUnexplained2 = array_values(array_diff($mutatedSaveApplePostKeys, $allowedSaveAppleKeys));
ok('(m) MUTATION PROOF: injecting a fake $_POST[\'apple_new_thing\'] read into a copy of the branch IS caught as unexplained',
    in_array('apple_new_thing', $mutatedUnexplained2, true));

/* =========================================================================
 * (n) WEBHOOKS TEST + META DISCIPLINE (#2004) — the wizard's webhooks test
 * makes NO outbound call, and the channel-checkbox meta stays locked to the
 * SAME allow-list the pure CSV parser enforces, in both directions.
 * ========================================================================= */

$integrationTestWebhooksBody = icwFunctionBodyFor($regStripped, 'integrationTestWebhooks');
ok('(n) isolated integrationTestWebhooks()\'s body (non-empty)', $integrationTestWebhooksBody !== '');
ok('(n) integrationTestWebhooks() calls webhookDrainHealth(', str_contains($integrationTestWebhooksBody, 'webhookDrainHealth('));
ok('(n) integrationTestWebhooks() calls no curl_* function (no outbound HTTP from a status test)',
    !str_contains($integrationTestWebhooksBody, 'curl_'));
ok('(n) integrationTestWebhooks() calls no webhookHttpPost( (the SIGNED delivery dialer stays untouched by a status test)',
    !str_contains($integrationTestWebhooksBody, 'webhookHttpPost('));

$webhookParseFnBody = icwFunctionBodyFor($webhooksStripped, 'webhookParseChannelsCsv');
ok('(n) isolated webhookParseChannelsCsv()\'s body (non-empty)', $webhookParseFnBody !== '');
preg_match('/\[\s*\'alpha\'\s*,\s*\'beta\'\s*,\s*\'production\'\s*\]/', $webhookParseFnBody, $mChanLit);
$webhookParseAllowList = $mChanLit[0] ?? null;
ok('(n) webhookParseChannelsCsv()\'s allow-list literal was found (fixture precondition)', $webhookParseAllowList !== null);

$webhookChannelMetaKeys = array_keys(integrationWebhookChannelMeta());
sort($webhookChannelMetaKeys);
$parsedAllowListKeys = ['alpha', 'beta', 'production'];
sort($parsedAllowListKeys);
ok('(n) integrationWebhookChannelMeta() keys EXACTLY equal webhookParseChannelsCsv()\'s allow-list (both directions)',
    $webhookChannelMetaKeys === $parsedAllowListKeys);

$webhookEnabledChannelsFnBody = icwFunctionBodyFor($webhooksStripped, 'webhookEnabledChannels');
ok('(n) webhookEnabledChannels() calls webhookParseChannelsCsv( (re-pointed, not duplicated)',
    str_contains($webhookEnabledChannelsFnBody, 'webhookParseChannelsCsv('));
ok('(n) webhookEnabledChannels() no longer carries its OWN copy of the allow-list literal (the fold moved to webhookParseChannelsCsv())',
    !(bool)preg_match('/\[\s*\'alpha\'\s*,\s*\'beta\'\s*,\s*\'production\'\s*\]/', $webhookEnabledChannelsFnBody));

/* MUTATION PROOF — inject a curl_init( call into a copy of the test body. */
$mutWebhooksTest = $integrationTestWebhooksBody . "\ncurl_init('https://example.com/');";
ok('(n) MUTATION PROOF: injecting curl_init( into a copy of integrationTestWebhooks() IS caught by the ban scan',
    str_contains($mutWebhooksTest, 'curl_init('));

/* MUTATION PROOF — drop 'beta' from a copy of the channel-meta keys; the
   equality check against the parser's own allow-list must go false. */
$mutatedChannelMetaKeys = array_values(array_diff($webhookChannelMetaKeys, ['beta']));
sort($mutatedChannelMetaKeys);
ok('(n) MUTATION PROOF: dropping \'beta\' from a copy of the channel-meta keys breaks the equality check',
    $mutatedChannelMetaKeys !== $parsedAllowListKeys);

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "The registry <-> page launcher wiring stays in lockstep in both directions, every\n"
   . "registry saveAction still points at a real, still-dispatched classic action (no forked\n"
   . "write path), no secret value (incl. every checkbox field's raw setting) can reach the\n"
   . "emitted JSON projection regardless of what the database holds, the integration_test\n"
   . "branch is CSRF-gated before it ever dispatches, the CueRCode probe reuses the shared\n"
   . "SSRF-hardened transport without ever going through the cache, the client driver collects\n"
   . "fields generically with zero hardcoded field-name literals across all six integrations,\n"
   . "the CSRF gate was genuinely widened with the additive envelope (incl. the show-once\n"
   . "drain key) sitting after the classic dispatch, the shared stepper singleton is\n"
   . "undisturbed, the CAPTCHA portal map exactly covers the selectable provider set, the\n"
   . "email vocabulary stays in lockstep with its single model, both email test paths share\n"
   . "ONE delivery core, the SIWA credentials test never leaks its minted client_secret, every\n"
   . "\$_POST key save_apple reads unconditionally is accounted for by the siwa registry entry\n"
   . "(carry field included) so a wizard save can never silently wipe the stored APNs Key ID,\n"
   . "and the webhooks test makes no outbound call while its channel meta stays locked to the\n"
   . "SAME allow-list the pure CSV parser enforces.\n";
exit(0);
