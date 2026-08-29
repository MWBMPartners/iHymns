<?php

declare(strict_types=1);

/**
 * iHymns — Android/FireOS push scaffold guard (API-coverage plan 2026-08-28,
 * C1/X2)
 *
 * ELI5
 * ----
 * Makes sure the "remember an Android/FireOS phone wants a push" feature is
 * wired up safely and stays inert: the registration endpoints exist, are
 * documented, are gated exactly like the Apple ones, and the sender module
 * genuinely does nothing until an owner pastes real credentials.
 *
 * WHAT IS ASSERTED
 *   1. FUNCTIONAL (requires the real, side-effect-free-to-require
 *      includes/fcm.php — no DB, no network at require time, mirrors
 *      includes/apns.php / includes/cuercode_client.php): the provider
 *      vocabulary; fcmConfig()/admConfig() resolve null with no
 *      tblAppSettings row reachable (this CLI context has no DB
 *      credentials, so getAppSetting() catches the connection failure and
 *      returns its default — the SAME mechanism
 *      test-apns-live-activity-push.php's "apnsConfigured() === false"
 *      assertion relies on); fcmSend() NEVER throws and returns the
 *      documented no-op shapes for an invalid provider, an empty token, and
 *      the (today, unconditional) dormant/not-configured/not-implemented
 *      case; pushTokensTableExists() never throws against an unreachable
 *      \mysqli handle.
 *   2. STRUCTURAL on api.php (token-parsed via the shared dispatch parser —
 *      tests/php/lib/dispatch_parser.php — never a plain grep, so a
 *      same-named case in the OTHER switch can't false-positive, mirroring
 *      test-openapi-actions-exist.php's own "two switches" lesson):
 *      'fcm_register'/'fcm_unregister' are real $action cases, and each
 *      case body is gated EXACTLY like its apns_register/apns_unregister
 *      sibling — same POST-method guard, same auth.php + own-module
 *      require, same validateCsrfRequest()/getAuthenticatedUser() calls,
 *      same table-existence 503 (register) / idempotent-ok (unregister)
 *      shape, same per-user checkRateLimit(..., 30, 3600, true, $userId)
 *      budget, same never-log-the-token breadcrumb discipline.
 *   3. DOCUMENTED — both actions are real OpenAPI path items in
 *      api-docs.yaml (the full phantom/orphan cross-check already lives in
 *      test-openapi-actions-exist.php; this only pins the two new ones so a
 *      revert of the docs half alone still fails HERE with a precise
 *      message rather than only in that file's broader sweep).
 *   4. SECRET-KEY REGISTRATION — fcm_server_key, adm_client_id,
 *      adm_client_secret are ALL registered in secretSettingKeys()
 *      (includes/secret_crypto.php), so a future admin-UI card encrypts
 *      them at rest from the very first save.
 *   5. REGISTRY — the 'push-tokens' slug exists in migration-registry.php
 *      and names migrate-add-push-tokens.php (the registry's OWN shape —
 *      script/card/probe present, probe not always-true — is covered by
 *      the existing tests/php/test-migration-registry.php, not re-asserted
 *      here; the schema.sql mirror is covered by
 *      tests/php/test-schema-coverage.php).
 *
 * HOW EACH CHECK WOULD GO RED (rule #34 — a guard must be provably
 * mutation-testable, not just written and trusted):
 *   - Delete/rename a case label in api.php  -> assertion 2's case-body
 *     lookup returns null -> red.
 *   - Drop the pushTokensTableExists() 503/idempotent-ok branch -> the
 *     `str_contains($body, 'pushTokensTableExists(')` check -> red.
 *   - Remove either api-docs.yaml path item -> assertion 3 -> red.
 *   - Forget to add a key to secretSettingKeys() -> assertion 4 -> red.
 *   - Make fcmSend() perform a real network call when configured -> would
 *     require stubbing a keyed config to observe, which this CLI context
 *     cannot do without a DB; instead assertion 1 pins the UNKEYED contract
 *     (rule #38's "dormancy is a property of the install") and the file's
 *     own doc-block is the second line of defence for the keyed case.
 *
 *   php tests/php/test-fcm-scaffold.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/fcm.php
 * @see appWeb/public_html/api.php                        (fcm_register / fcm_unregister)
 * @see appWeb/public_html/api-docs.yaml
 * @see appWeb/public_html/includes/secret_crypto.php      (secretSettingKeys())
 * @see appWeb/public_html/manage/includes/migration-registry.php
 * @see appWeb/.sql/migrate-add-push-tokens.php
 * @see tests/php/test-apns-live-activity-push.php  (the sibling this guard's shape mirrors)
 * @see tests/php/lib/dispatch_parser.php           (the shared switch-walker)
 */

$repo = dirname(__DIR__, 2);

$failures = 0;
$passed = 0;
function _tfs_assert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/* ---------------------------------------------------------------------- *
 * 1 — FUNCTIONAL: includes/fcm.php, no DB reachable in this CLI context.
 * ---------------------------------------------------------------------- */

require_once $repo . '/appWeb/public_html/includes/fcm.php';

echo "Push scaffold — functional (includes/fcm.php):\n";

_tfs_assert(PUSH_TOKEN_PROVIDERS === ['fcm', 'adm'], "PUSH_TOKEN_PROVIDERS is exactly ['fcm', 'adm']");

_tfs_assert(fcmConfig() === null, 'fcmConfig() resolves null with no DB/settings reachable (dormant)');
_tfs_assert(fcmConfigured() === false, 'fcmConfigured() is false in the same dormant state');
_tfs_assert(admConfig() === null, 'admConfig() resolves null with no DB/settings reachable (dormant)');
_tfs_assert(admConfigured() === false, 'admConfigured() is false in the same dormant state');
_tfs_assert(pushProviderConfigured('fcm') === false, "pushProviderConfigured('fcm') is false when dormant");
_tfs_assert(pushProviderConfigured('adm') === false, "pushProviderConfigured('adm') is false when dormant");
_tfs_assert(pushProviderConfigured('bogus') === false, "pushProviderConfigured() is false for an unrecognised provider");

$threwOnSend = false;
$sendResult = null;
try {
    $sendResult = fcmSend('fcm', 'sometoken12345');
} catch (\Throwable $e) {
    $threwOnSend = true;
}
_tfs_assert($threwOnSend === false, 'fcmSend() never throws for a well-formed call in the dormant state');
_tfs_assert(is_array($sendResult) && ($sendResult['ok'] ?? null) === false, "fcmSend() returns ok:false when dormant");
_tfs_assert(is_array($sendResult) && ($sendResult['status'] ?? null) === 'not_configured', "fcmSend() returns status:'not_configured' — the SAME dormancy vocabulary cuercodeGenerate()/apnsSend() use — when the provider is unkeyed");

$invalidProviderResult = fcmSend('bogus-provider', 'sometoken');
_tfs_assert(($invalidProviderResult['status'] ?? null) === 'invalid_provider', "fcmSend() rejects an unrecognised provider with status:'invalid_provider' rather than silently defaulting");

$emptyTokenResult = fcmSend('fcm', '');
_tfs_assert(($emptyTokenResult['status'] ?? null) === 'invalid_token', "fcmSend() rejects an empty token with status:'invalid_token'");

/* pushTokensTableExists() mirrors apnsTokensTableExists()'s own dormancy
   test (test-apns-live-activity-push.php): a REAL but UNCONNECTED \mysqli
   handle must never throw — every method call on it throws \Error, which
   the function's own try/catch(\Throwable) must absorb. */
$bogusDb = mysqli_init();
_tfs_assert($bogusDb instanceof \mysqli, 'mysqli_init() yields a real (unconnected) \mysqli instance for the dormancy probe');
$threwOnTableProbe = false;
$tableProbeResult = null;
try {
    $tableProbeResult = pushTokensTableExists($bogusDb);
} catch (\Throwable $e) {
    $threwOnTableProbe = true;
}
_tfs_assert($threwOnTableProbe === false, 'pushTokensTableExists() never throws against an unreachable \mysqli handle');
_tfs_assert($tableProbeResult === false, 'pushTokensTableExists() reports false (treated as absent) when the probe cannot run');

/* ---------------------------------------------------------------------- *
 * 2 — STRUCTURAL: api.php dispatches + gates fcm_register/fcm_unregister
 * exactly like apns_register/apns_unregister.
 * ---------------------------------------------------------------------- */

require_once $repo . '/tests/php/lib/dispatch_parser.php';

echo "\nPush scaffold — structural (api.php dispatch + gating parity):\n";

$apiFile = $repo . '/appWeb/public_html/api.php';
$apiSrc = (string)file_get_contents($apiFile);

$actionCases = dispatchParserCasesForSwitch($apiFile, '$action');
_tfs_assert(in_array('fcm_register', $actionCases, true), "'fcm_register' is a real \$action case in api.php (not merely a same-named \$page case — token-parsed via the shared switch-walker)");
_tfs_assert(in_array('fcm_unregister', $actionCases, true), "'fcm_unregister' is a real \$action case in api.php");

/**
 * Extract one `case '<label>':` body up to the next top-level `case`/
 * `default` at the same 8-space indent — the SAME technique
 * test-apns-live-activity-push.php's _talapCaseBody() / test-device-code.php's
 * _tdcCaseBody() use, kept as its own small copy here (not extracted to the
 * shared parser lib) because it operates on already-tokenised-elsewhere
 * source text, not tokens, and every existing sibling test keeps its own
 * copy of this exact shape rather than adding a fifth dependency to the
 * shared lib for a four-line textual scan.
 */
function _tfsCaseBody(string $source, string $caseLabel): ?string
{
    $needle = "case '{$caseLabel}':";
    $start = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    $bodyStart = $start + strlen($needle);
    $nextCase = strpos($source, "\n        case '", $bodyStart);
    $nextDefault = strpos($source, "\n        default:", $bodyStart);
    $ends = array_filter([$nextCase, $nextDefault], static fn($v) => $v !== false);
    $end = $ends ? min($ends) : strlen($source);
    return substr($source, $bodyStart, $end - $bodyStart);
}

$apnsRegisterBody = _tfsCaseBody($apiSrc, 'apns_register');
$apnsUnregisterBody = _tfsCaseBody($apiSrc, 'apns_unregister');
$fcmRegisterBody = _tfsCaseBody($apiSrc, 'fcm_register');
$fcmUnregisterBody = _tfsCaseBody($apiSrc, 'fcm_unregister');

_tfs_assert($apnsRegisterBody !== null, "case 'apns_register' found in api.php (positive control — if THIS fails, the extractor itself is broken, not the new code)");
_tfs_assert($fcmRegisterBody !== null, "case 'fcm_register' found in api.php");
_tfs_assert($fcmUnregisterBody !== null, "case 'fcm_unregister' found in api.php");

/* Gating parity — every mechanism apns_register's gate uses, fcm_register's
   gate must ALSO use (the task's own instruction: "mirror apns_register's
   gate exactly"). Checked as a set so adding a NEW gate to one side without
   the other is caught in either direction. */
$gateMarkers = [
    "REQUEST_METHOD'] !== 'POST'"        => 'POST-only guard',
    "'auth.php'"                          => 'requires manage/includes/auth.php',
    "validateCsrfRequest("                => 'same-origin CSRF check (rule #29)',
    "getAuthenticatedUser("               => 'authenticated-user gate',
    "checkRateLimit("                     => 'rate limiting',
    "recordRateLimitHit("                 => 'rate limit hit recording',
];
foreach ($gateMarkers as $marker => $desc) {
    $inApns = $apnsRegisterBody !== null && str_contains($apnsRegisterBody, $marker);
    $inFcm  = $fcmRegisterBody !== null && str_contains($fcmRegisterBody, $marker);
    _tfs_assert($inApns, "sanity: apns_register's body contains its own {$desc} marker (positive control)");
    _tfs_assert($inFcm, "fcm_register's gate includes {$desc}, mirroring apns_register");
}

_tfs_assert($fcmRegisterBody !== null && str_contains($fcmRegisterBody, "'fcm.php'"), "case 'fcm_register' require_once's includes/fcm.php");
_tfs_assert($fcmRegisterBody !== null && str_contains($fcmRegisterBody, 'pushTokensTableExists('), "case 'fcm_register' gates on pushTokensTableExists() (mirrors apnsTokensTableExists())");
_tfs_assert($fcmRegisterBody !== null && str_contains($fcmRegisterBody, 'PUSH_TOKEN_PROVIDERS'), "case 'fcm_register' validates the provider against PUSH_TOKEN_PROVIDERS (rule #20 — never a hardcoded ['fcm','adm'] literal here)");
_tfs_assert($fcmRegisterBody !== null && str_contains($fcmRegisterBody, 'INSERT INTO tblPushTokens'), "case 'fcm_register' writes to tblPushTokens");
_tfs_assert($fcmRegisterBody !== null && str_contains($fcmRegisterBody, 'ON DUPLICATE KEY UPDATE'), "case 'fcm_register' upserts (ON DUPLICATE KEY UPDATE) rather than erroring on re-registration");
_tfs_assert($fcmRegisterBody !== null && !str_contains($fcmRegisterBody, "'token' =>") , "case 'fcm_register' never logs the raw request body's token key into an activity-log details array (breadcrumb discipline, mirrors apns.php's file-header SECURITY note)");

_tfs_assert($fcmUnregisterBody !== null && str_contains($fcmUnregisterBody, 'pushTokensTableExists('), "case 'fcm_unregister' gates on pushTokensTableExists()");
_tfs_assert($fcmUnregisterBody !== null && str_contains($fcmUnregisterBody, "sendJson(['ok' => true]); /* nothing to unregister"), "case 'fcm_unregister' answers {ok:true} (not 503) on an un-migrated install — mirrors apns_unregister's idempotent-cleanup contract");
_tfs_assert($fcmUnregisterBody !== null && str_contains($fcmUnregisterBody, 'DELETE FROM tblPushTokens'), "case 'fcm_unregister' deletes from tblPushTokens");
_tfs_assert($fcmUnregisterBody !== null && str_contains($fcmUnregisterBody, 'WHERE UserId = ? AND Token = ?'), "case 'fcm_unregister' scopes its delete to the caller's OWN tokens (own-only, mirrors apns_unregister)");

_tfs_assert(
    $apnsUnregisterBody !== null && $fcmUnregisterBody !== null
    && str_contains($apnsUnregisterBody, 'validateCsrfRequest(') === str_contains($fcmUnregisterBody, 'validateCsrfRequest(')
    && str_contains($apnsUnregisterBody, 'getAuthenticatedUser(') === str_contains($fcmUnregisterBody, 'getAuthenticatedUser('),
    'fcm_unregister carries the same CSRF + auth gate presence as apns_unregister'
);

/* Never calls fcmSend() from a live trigger — this change ships registration
   only. A future PR wiring a real send-on-event caller should NOT trip this
   guard (it only checks api.php, not every future call site), but api.php
   itself dispatching zero calls to fcmSend() is the concrete, checkable
   half of "inert groundwork" this test can pin today.

   TOKEN-PARSED, not substr_count() on raw source: this file's OWN doc
   comments (immediately above the case blocks) legitimately MENTION
   "fcmSend()" in prose while explaining the dormancy contract — a plain
   substring count would false-positive on that prose, exactly the
   comment-vs-code trap test-qr-cache.php's structural checks are built to
   dodge (its header: "so a docblock that MENTIONS ... in prose can't
   false-positive"). A doc-comment token's VALUE containing the substring
   "fcmSend(" is not a T_STRING 'fcmSend' token followed by '(', so the
   tokenizer-based count below is unaffected by it. */
function _tfsCountRealCalls(array $toks, string $funcName): int
{
    $n = count($toks);
    $count = 0;
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== $funcName) { continue; }
        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
        if (($toks[$j] ?? null) === '(') { $count++; }
    }
    return $count;
}
$apiToks = dispatchParserTokens($apiFile);
_tfs_assert(_tfsCountRealCalls($apiToks, 'fcmSend') === 0, 'api.php calls fcmSend() ZERO times (token-parsed, so a doc-comment MENTIONING it in prose cannot false-positive) — registration only, no live trigger sends a push yet');

/* ---------------------------------------------------------------------- *
 * 3 — DOCUMENTED: both actions are real OpenAPI path items.
 * ---------------------------------------------------------------------- */

echo "\nPush scaffold — documented (api-docs.yaml):\n";

$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
_tfs_assert(
    (bool)preg_match('#^  /api\.php\?action=fcm_register:#m', $yaml),
    '/api.php?action=fcm_register is a top-level OpenAPI path item'
);
_tfs_assert(
    (bool)preg_match('#^  /api\.php\?action=fcm_unregister:#m', $yaml),
    '/api.php?action=fcm_unregister is a top-level OpenAPI path item'
);

/* ---------------------------------------------------------------------- *
 * 4 — SECRET-KEY REGISTRATION.
 * ---------------------------------------------------------------------- */

echo "\nPush scaffold — secret key registration (includes/secret_crypto.php):\n";

$secretKeys = secretSettingKeys();
foreach (['fcm_server_key', 'adm_client_id', 'adm_client_secret'] as $key) {
    _tfs_assert(in_array($key, $secretKeys, true), "secretSettingKeys() registers '{$key}' (encrypted at rest from its first save)");
}

/* ---------------------------------------------------------------------- *
 * 5 — REGISTRY entry present and names the real migration script.
 * ---------------------------------------------------------------------- */

echo "\nPush scaffold — migration registry:\n";

/* Stub the probe-helper functions the registry file's closures reference,
   the SAME technique test-migration-registry.php uses, so requiring it
   evaluates without a fatal — the closures are never invoked here. */
if (!function_exists('_migProbe_tableExists')) {
    function _migProbe_tableExists(\mysqli $db, string $t): bool { return false; }
}
if (!function_exists('_migProbe_columnExists')) {
    function _migProbe_columnExists(\mysqli $db, string $t, string $c): bool { return false; }
}
if (!function_exists('_migProbe_columnIsNullable')) {
    function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; }
}
if (!function_exists('_migProbe_triggerExists')) {
    function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; }
}
$hasCredentials = true;
$_SERVER['SCRIPT_FILENAME'] = '/different.php'; /* bypass the registry file's direct-access guard */

$MIGRATIONS = require $repo . '/appWeb/public_html/manage/includes/migration-registry.php';
_tfs_assert(isset($MIGRATIONS['push-tokens']), "migration-registry.php has a 'push-tokens' entry");
_tfs_assert(
    ($MIGRATIONS['push-tokens']['script'] ?? null) === 'migrate-add-push-tokens.php',
    "the 'push-tokens' registry entry names migrate-add-push-tokens.php"
);
_tfs_assert(
    is_file($repo . '/appWeb/.sql/migrate-add-push-tokens.php'),
    'migrate-add-push-tokens.php actually exists in appWeb/.sql/'
);
_tfs_assert(
    empty($MIGRATIONS['push-tokens']['manual']),
    "the 'push-tokens' migration is a NORMAL (non-manual) card — creating an empty dormant table carries none of the destructive-drop risk 'manual' => true guards against"
);

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
