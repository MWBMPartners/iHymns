<?php

declare(strict_types=1);

/**
 * iHymns — IntAppsAPI stub-gateway end-to-end suite (#1725/#1731)
 *
 * ELI5: starts the fake gateway from `tests/php/fixtures/intapps-stub-
 * gateway.php` on a real local port, points the REAL `intapps_client.php`
 * at it over REAL HTTP (loopback), and proves the whole loop — the
 * signer, the auth headers, the cold-cache populate, and every failure
 * mode — the strongest signature proof available in this container
 * (§2 of the pre-launch delta: this is CAN-be-proven-here territory;
 * signature acceptance by the REAL gateway is a separate, explicitly
 * un-provable-here claim — see the CANNOT list this suite's introducing
 * commit records).
 *
 * DETAIL:
 * -------
 * D12 (stress-test defect) — the FIRST assertion below is a POSITIVE
 * CONTROL: prove the stub is actually reachable and the cache actually
 * populates BEFORE trusting any "the fail-open path degraded correctly"
 * assertion. Without this ordering, pointing the client at a dead port by
 * accident would make every fail-open assertion pass vacuously (zero
 * traffic, zero populated cache, "looks like it degraded correctly") —
 * exactly the D12 trap. If the positive control fails, this suite exits
 * immediately rather than reporting a green fail-open matrix that proved
 * nothing.
 *
 * This suite writes real rows into the SAME `ihymns_live` database the
 * rest of the local verification uses (there is no isolated test DB in
 * this environment). Every row it touches is scoped to
 * (Scope='features', Channel=<current channel>, AppSlug='ihymns') plus
 * the seven `intappsapi_*` settings keys, and BOTH are deleted at the end
 * — matching the house DB-hygiene convention already used throughout this
 * batch's own commits.
 *
 * NO DB CONFIGURED (e.g. a from-scratch CI runner with no
 * appWeb/.auth/db_credentials.php — the established state several sibling
 * suites already handle, `test-gating-noop.php`/`test-gating-registry.php`
 * among them): this suite SKIPS its real assertions and exits 0, rather
 * than crashing on an unguarded `getDbMysqli()` throw. This is the ONE
 * suite in this batch that cannot degrade to a DB-free structural check —
 * its entire point is a live round trip — so "no DB" here means "not run",
 * not "proved". The local-instance run (MariaDB `ihymns_live`, this
 * batch's actual verification target) always has credentials and always
 * runs for real.
 *
 *   php tests/php/test-intapps-stub-e2e.php
 *
 * Exit status 0 = all pass OR skipped (no DB), 1 = at least one failure
 * (including "could not start the stub" / "positive control failed" when
 * a DB IS available — both treated as hard failures, never silently
 * skipped in that case).
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/db_mysql.php';
require_once $repoRoot . '/appWeb/public_html/includes/intapps_client.php';

/* Fail-CLOSED-to-SKIP, not fail-crash: getDbMysqli() throws
   RuntimeException when appWeb/.auth/db_credentials.php doesn't exist.
   This whole suite needs a REAL database (it writes/reads real rows
   across a real HTTP round trip) — there is no meaningful DB-free subset
   to run, unlike the pure-function suites in this same batch. */
try {
    $dbProbe = getDbMysqli();
    unset($dbProbe);
} catch (\Throwable $e) {
    echo "  SKIP  no appWeb/.auth/db_credentials.php configured — this suite needs a real database.\n";
    echo "        (" . $e->getMessage() . ")\n";
    echo "\nSkipped: no local DB available. Not a failure, not a pass — not run.\n";
    exit(0);
}

/* -------------------------------------------------------------------- *
 *  1. START THE STUB on an ephemeral port
 * -------------------------------------------------------------------- */

function findFreePort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new \RuntimeException("could not bind an ephemeral port: $errstr");
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $parts = explode(':', $name);
    return (int)end($parts);
}

$port = findFreePort();
$scratchDir = sys_get_temp_dir() . '/intapps-e2e-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($scratchDir, 0777, true) && !is_dir($scratchDir)) {
    fwrite(STDERR, "FATAL: could not create scratch dir $scratchDir\n");
    exit(1);
}
$logFile = $scratchDir . '/access.log';
$scenarioFile = $scratchDir . '/scenario.txt';
file_put_contents($scenarioFile, 'good');

$stubScript = $repoRoot . '/tests/php/fixtures/intapps-stub-gateway.php';
/* Only scalar values — $_SERVER/$_ENV under CLI carry array-valued entries
   (e.g. 'argv') that proc_open()'s env parameter cannot stringify. */
$env = array_filter($_ENV + $_SERVER, static fn($v): bool => is_scalar($v));
$env['INTAPPS_STUB_LOG_FILE'] = $logFile;
$env['INTAPPS_STUB_SCENARIO_FILE'] = $scenarioFile;

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $stubScript],
    $descriptors,
    $pipes,
    $repoRoot,
    $env
);
if (!is_resource($proc)) {
    fwrite(STDERR, "FATAL: could not spawn the stub gateway\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

/* Poll /v1/status until the server answers or we give up. */
$ready = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100000); /* 100ms */
    $ch = curl_init("http://127.0.0.1:{$port}/v1/status");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status === 200 && is_string($body) && str_contains($body, '"status":"ok"')) {
        $ready = true;
        break;
    }
}
check('the stub gateway came up and answered /v1/status', $ready);
if (!$ready) {
    fwrite(STDERR, "FATAL: stub gateway never became ready — aborting rather than running a vacuous suite.\n");
    proc_terminate($proc);
    proc_close($proc);
    exit(1);
}

/* -------------------------------------------------------------------- *
 *  2. WIRE THE REAL CLIENT AT THE STUB (real DB, scoped rows)
 * -------------------------------------------------------------------- */

$db = getDbMysqli();
$currentChannel = function_exists('ihymns_environment') ? ihymns_environment() : 'production';
$appSlug = 'ihymns';
$hmacSecret64a = str_repeat('a', 64); /* matches INTAPPS_STUB_HMAC_SECRET */

function e2eCleanup(\mysqli $db, string $channel, string $appSlug): void
{
    /* Scoped delete (never an unqualified sweep, per house DB-hygiene
       rule): only the seven intappsapi_* setting keys this suite itself
       writes, matched by an exact prefix. */
    $stmt = $db->prepare("DELETE FROM tblAppSettings WHERE SettingKey LIKE 'intappsapi_%'");
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('DELETE FROM tblIntAppsSync WHERE Scope = ? AND Channel = ? AND AppSlug = ?');
    $scope = 'features';
    $stmt->bind_param('sss', $scope, $channel, $appSlug);
    $stmt->execute();
    $stmt->close();
}

/* Scoped pre-clean in case a previous aborted run left rows behind. */
e2eCleanup($db, $currentChannel, $appSlug);

/* Values mirror the FIXED constants declared in
   tests/php/fixtures/intapps-stub-gateway.php (INTAPPS_STUB_APP_UUID /
   INTAPPS_STUB_API_KEY / INTAPPS_STUB_HMAC_SECRET) — duplicated here as
   literals rather than required-in, since the stub runs as a separate
   `php -S` process this suite only talks to over HTTP. */
$settingsRows = [
    'intappsapi_enabled_channels' => $currentChannel,
    'intappsapi_base_url'         => "http://127.0.0.1:{$port}",
    'intappsapi_allow_loopback'   => '1',
    'intappsapi_app_uuid'         => '11111111-1111-1111-1111-111111111111',
    'intappsapi_app_slug'         => $appSlug,
    'intappsapi_api_key'          => 'stub-api-key-0123456789abcdef',
    'intappsapi_hmac_secret'      => $hmacSecret64a,
];
foreach ($settingsRows as $k => $v) {
    $stmt = $db->prepare('INSERT INTO tblAppSettings (SettingKey, SettingValue) VALUES (?, ?)');
    $stmt->bind_param('ss', $k, $v);
    $stmt->execute();
    $stmt->close();
}

/* Ensure a clean tblIntAppsSync test cycle. */
register_shutdown_function(static function () use ($db, $currentChannel, $appSlug, $proc, $scratchDir): void {
    e2eCleanup($db, $currentChannel, $appSlug);
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    @array_map('unlink', glob($scratchDir . '/*') ?: []);
    @rmdir($scratchDir);
});

function setStubScenario(string $scenarioFile, string $scenario): void
{
    file_put_contents($scenarioFile, $scenario);
}

/* -------------------------------------------------------------------- *
 *  3. D12 POSITIVE CONTROL — must succeed before ANYTHING else is trusted
 * -------------------------------------------------------------------- */

echo "1 — positive control (must pass before the fail-open matrix means anything)\n";

setStubScenario($scenarioFile, 'good');
$goodResult = intappsForceRefresh($db, 'features');
check('positive control: intappsForceRefresh() against scenario=good returns ok=true', $goodResult['ok'] === true);
check('positive control: transport is answered, HTTP 200', $goodResult['transport'] === 'answered' && $goodResult['httpStatus'] === 200);

$logContents = (string)@file_get_contents($logFile);
check('positive control: the stub was actually contacted (access-logged)', str_contains($logContents, '/v1/features/ihymns'));

if ($goodResult['ok'] !== true || !str_contains($logContents, '/v1/features/ihymns')) {
    fwrite(STDERR, "FATAL: positive control failed — the fail-open matrix below would prove nothing. Aborting (D12).\n");
    echo "\nFAILED: positive control did not pass; suite aborted before the fail-open matrix.\n";
    exit(1);
}

$cached = intappsCachedScope($db, 'features');
check('cache holds the good snapshot', is_array($cached['payload']['features'] ?? null) && count($cached['payload']['features']) === 1);
check('intappsFlag() reads the real gateway value (web.sotd_card -> true)', intappsFlag($db, 'web.sotd_card', false) === true);
check('intappsFlag() falls to the compiled default for an unknown key', intappsFlag($db, 'nonexistent.key.xyz', true) === true);
check('intappsFlag() falls to the compiled default (false) for an unknown key too', intappsFlag($db, 'nonexistent.key.xyz', false) === false);

$meta = intappsFlagMeta($db, 'web.sotd_card');
check('intappsFlagMeta() returns the full object', $meta !== null && $meta['enabled'] === true && $meta['label'] === 'Song of the Day card');

/* -------------------------------------------------------------------- *
 *  4. THE SIGNER — real client signature accepted by a ported verifier
 * -------------------------------------------------------------------- */

echo "\n2 — the signer round-trips against a verifier ported line-for-line from HmacValidator.php\n";

$signedPost = intappsRequest('POST', "/v1/features/{$appSlug}/batch", '{"probe":true}');
check('a REAL client-signed POST is ACCEPTED by the stub (proves intappsSign() matches the ported verifier)', $signedPost['ok'] === true);

/* Raw protocol-level checks, deliberately bypassing intapps_client.php's
   own signer — these prove the STUB rejects exactly what the real
   gateway would reject, independent of whether our own signer is right
   (that's proven above). */
function rawStubCall(int $port, string $method, string $path, array $headers, string $body = ''): array
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $respBody = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, (string)$respBody];
}

$baseHeaders = [
    'X-App-ID: 11111111-1111-1111-1111-111111111111',
    'User-Agent: iHymns/1.0 (test)',
    'X-API-Key: stub-api-key-0123456789abcdef',
];

$ts = time();
$wrongCanonical = 'POST' . '|' . "/v1/features/{$appSlug}/batch" . '|' . $ts . '|';
$wrongSig = hash_hmac('sha256', $wrongCanonical, $hmacSecret64a);
[$status, ] = rawStubCall($port, 'POST', "/v1/features/{$appSlug}/batch", array_merge($baseHeaders, [
    "X-Timestamp: {$ts}", "X-Signature: {$wrongSig}",
]));
check(
    "MWBM-intAppsAPI#120: the gateway examples' METHOD|PATH|TIMESTAMP|BODY scheme is REJECTED (403) by the ported verifier",
    $status === 403
);

$isoTs = gmdate('Y-m-d\TH:i:s\Z');
$isoSig = hash_hmac('sha256', '.' . $isoTs, $hmacSecret64a);
[$status, ] = rawStubCall($port, 'POST', "/v1/features/{$appSlug}/batch", array_merge($baseHeaders, [
    "X-Timestamp: {$isoTs}", "X-Signature: {$isoSig}",
]));
check('an ISO-8601 timestamp (ctype_digit fails) is REJECTED (403)', $status === 403);

$oldTs = time() - 400;
$oldSig = hash_hmac('sha256', '.' . $oldTs, $hmacSecret64a);
[$status, ] = rawStubCall($port, 'POST', "/v1/features/{$appSlug}/batch", array_merge($baseHeaders, [
    "X-Timestamp: {$oldTs}", "X-Signature: {$oldSig}",
]));
check('a timestamp aged > 300s is REJECTED (403)', $status === 403);

[$status, ] = rawStubCall($port, 'GET', "/v1/features/{$appSlug}", [
    'X-App-ID: 11111111-1111-1111-1111-111111111111',
    'User-Agent: SomeOtherApp/1.0',
    'X-API-Key: stub-api-key-0123456789abcdef',
]);
check("a wrong User-Agent prefix (not 'iHymns/') is REJECTED (403)", $status === 403);

/* -------------------------------------------------------------------- *
 *  5. THE FAIL-OPEN MATRIX — every failure leaves PayloadJson untouched
 * -------------------------------------------------------------------- */

echo "\n3 — fail-open matrix: each scenario must leave the last-known-good snapshot untouched\n";

/**
 * @param bool $expectTransportOk Whether intappsRequest()'s OWN 'ok' flag
 *        (HTTP 2xx + envelope success:true — the TRANSPORT layer) should be
 *        true for this scenario. For 'data-null'/'missing-features' the
 *        transport genuinely succeeds (the gateway answered 200 with a
 *        well-formed envelope) — it's the SHAPE that's wrong, judged one
 *        layer up in _intappsCommitFetchResult(), which is exactly remedy
 *        4's point: a transport-successful, shape-invalid answer must
 *        still never overwrite the snapshot. For every other scenario here
 *        the transport itself failed (403 / oversized / garbage body).
 */
function assertNeverOverwrites(\mysqli $db, string $scenarioFile, string $scenario, string $expectedErrorCode, bool $expectTransportOk): void
{
    $before = intappsSyncRow($db, 'features');
    setStubScenario($scenarioFile, $scenario);
    $result = intappsForceRefresh($db, 'features');
    $after = intappsSyncRow($db, 'features');

    check("scenario '{$scenario}': transport-layer ok is " . ($expectTransportOk ? 'true (envelope succeeded; shape is the failure)' : 'false'), $result['ok'] === $expectTransportOk);
    check(
        "scenario '{$scenario}': PayloadJson is UNCHANGED (still the good snapshot)",
        $after !== null && $before !== null && $after['payload'] === $before['payload']
    );
    check(
        "scenario '{$scenario}': ConsecutiveFailures incremented",
        $after !== null && $before !== null && $after['consecutiveFailures'] === $before['consecutiveFailures'] + 1
    );
    check(
        "scenario '{$scenario}': LastErrorCode is '{$expectedErrorCode}'",
        $after !== null && $after['lastErrorCode'] === $expectedErrorCode
    );
}

assertNeverOverwrites($db, $scenarioFile, 'data-null', 'BAD_SHAPE', true);
assertNeverOverwrites($db, $scenarioFile, 'missing-features', 'BAD_SHAPE', true);
assertNeverOverwrites($db, $scenarioFile, 'oversized', 'OVERSIZED', false);
assertNeverOverwrites($db, $scenarioFile, 'http-403', 'ACCESS_DENIED', false);
assertNeverOverwrites($db, $scenarioFile, 'malformed', 'MALFORMED', false);

echo "\n   'hang' scenario — the client's 3s timeout must win the race against the stub's 10s sleep\n";
$rowBeforeHang = intappsSyncRow($db, 'features');
setStubScenario($scenarioFile, 'hang');
$hangStart = microtime(true);
$hangResult = intappsForceRefresh($db, 'features');
$hangElapsed = microtime(true) - $hangStart;
check('hang scenario: the call returns in well under the stub\'s 10s sleep (bounded by CURLOPT_TIMEOUT=3s)', $hangElapsed < 6.0);
check('hang scenario: fetch is NOT ok', $hangResult['ok'] === false);
$rowAfterHang = intappsSyncRow($db, 'features');
check(
    'hang scenario: PayloadJson is UNCHANGED',
    $rowAfterHang !== null && $rowBeforeHang !== null && $rowAfterHang['payload'] === $rowBeforeHang['payload']
);

/* php -S is single-threaded: the stub is still inside its sleep(10) for
   the remainder of that window even though OUR client already gave up at
   3s. Calling the stub again before that sleep(10) actually finishes
   would queue behind it and time out too — not a client bug, a fixture-
   server concurrency limit. Wait out the remainder (with margin) before
   trusting the stub to answer promptly again. */
$hangRemaining = 10.5 - $hangElapsed;
if ($hangRemaining > 0) {
    usleep((int)($hangRemaining * 1_000_000));
}

echo "\n   recovery — a subsequent GOOD fetch clears the failure streak\n";
setStubScenario($scenarioFile, 'good');
$recovered = intappsForceRefresh($db, 'features');
check('recovery: ok again after the matrix', $recovered['ok'] === true);
$rowRecovered = intappsSyncRow($db, 'features');
check('recovery: ConsecutiveFailures reset to 0', $rowRecovered !== null && $rowRecovered['consecutiveFailures'] === 0);

/* -------------------------------------------------------------------- *
 *  6. D1 — enabled but tblIntAppsSync absent degrades, never throws
 * -------------------------------------------------------------------- */

echo "\n4 — D1: the module stays enabled while tblIntAppsSync is temporarily absent\n";

/* `_intappsTableExists()` memoizes per PHP PROCESS (correct in production,
   where each web request IS a fresh process). This whole suite runs as
   ONE long-lived process, so by this point that memo is already latched
   to `true` from every earlier call above — renaming the table away here
   and calling the functions IN THIS PROCESS would prove nothing (the memo
   would keep insisting the table exists). A genuinely SEPARATE `php` CLI
   process is spawned instead, exactly mirroring how a real un-migrated
   docroot handling its OWN request has no memo to begin with. */
$d1ProbeScript = $scratchDir . '/d1-probe.php';
$d1ProbeBody = <<<'PHP'
<?php
declare(strict_types=1);
require_once REPO_ROOT_ABS . '/appWeb/public_html/includes/db_mysql.php';
require_once REPO_ROOT_ABS . '/appWeb/public_html/includes/intapps_client.php';
$db = getDbMysqli();
$out = ['threw' => false];
try {
    $cached = intappsCachedScope($db, 'features');
    $out['cachedPayload'] = $cached['payload'];
    intappsRefreshIfDue($db, 'features');
    $out['forceResult'] = intappsForceRefresh($db, 'features');
} catch (\Throwable $e) {
    $out['threw'] = true;
    $out['exceptionMessage'] = $e->getMessage();
}
echo json_encode($out);
PHP;
/* Nowdoc above so none of the probe's OWN `$db`/`$out`/`$cached` variable
   syntax gets interpolated by THIS (outer) script — only the literal
   placeholder token is substituted, once, here. */
$d1ProbeBody = str_replace('REPO_ROOT_ABS', var_export($repoRoot, true), $d1ProbeBody);
file_put_contents($d1ProbeScript, $d1ProbeBody);

$db->query('RENAME TABLE tblIntAppsSync TO tblIntAppsSync_e2e_hidden');
$probeOutput = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($d1ProbeScript) . ' 2>&1');
$db->query('RENAME TABLE tblIntAppsSync_e2e_hidden TO tblIntAppsSync');

$probeResult = json_decode((string)$probeOutput, true);
check(
    'D1: the fresh-process probe produced valid JSON (no fatal/uncaught output)',
    is_array($probeResult)
);
if (is_array($probeResult)) {
    check('D1: intappsCachedScope()/intappsRefreshIfDue()/intappsForceRefresh() never throw with the table absent', $probeResult['threw'] === false);
    /* NOT `?? 'unset'` — `??` treats an EXISTING key whose value is `null`
       the same as a MISSING key (it checks isset(), which is false for
       null), so that idiom would silently mask the exact `null` this
       assertion is trying to confirm. array_key_exists() is required. */
    check(
        'D1: intappsCachedScope() degrades to null payload',
        array_key_exists('cachedPayload', $probeResult) && $probeResult['cachedPayload'] === null
    );
    check(
        "D1: intappsForceRefresh() reports 'unreachable' with a table-absent message, not a crash",
        array_key_exists('forceResult', $probeResult) && ($probeResult['forceResult']['transport'] ?? '') === 'unreachable'
    );
} else {
    echo "       raw probe output: " . $probeOutput . "\n";
}

/* Confirm normal operation resumes once the table is back. */
setStubScenario($scenarioFile, 'good');
$postRestore = intappsForceRefresh($db, 'features');
check('D1: after restoring the table, a normal refresh works again', $postRestore['ok'] === true);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "All intapps-stub-e2e assertions passed.\n";
exit(0);
