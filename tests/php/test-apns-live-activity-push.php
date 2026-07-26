<?php

/**
 * iHymns — Live Activities push-bridge helpers + hook wiring (#1429, Apple
 * Phase-2 PR-16)
 *
 * Three parts, mirroring tests/php/test-device-code.php's shape:
 *   1. Exercises the PURE functions in includes/live_activity_push.php
 *      directly (liveActivityPush_resolveDisplayState() /
 *      _contentState() / _apsPayload()) — no DB, no superglobals.
 *   2. Cross-checks liveActivityPush_contentState() against the SAME fixture
 *      file appApple/Packages/iHymnsKit/Tests/Fixtures/
 *      live_activity_content_state.json a Swift decode test reads, so the
 *      two sides of the contract can never silently drift apart.
 *   3. Dormancy: liveActivitySessionPush() never throws, even called with a
 *      bogus session id against an unconnected \mysqli handle (no DB
 *      reachable in this CLI/CI context) — the "table absent /
 *      apnsConfigured() false ⇒ verified no-op" contract from the #1429
 *      task brief.
 *   4. Structural source-scan of api.php: the hook is wired into all six
 *      broadcast/end call sites, live_follow_create's response now exposes
 *      'sessionId', and apns_register's Live-Activity branch is gated on
 *      serviceMode_userCanOperate() (#1429 C3's auth fix).
 *
 *   php tests/php/test-apns-live-activity-push.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/live_activity_push.php';

$failures = 0;
$passed = 0;

function _talapAssert(bool $cond, string $label): void
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
 * 1. liveActivityPush_resolveDisplayState() — PURE.
 * ---------------------------------------------------------------------- */

_talapAssert(liveActivityPush_resolveDisplayState('{"displayState":"blackout"}') === 'blackout', 'a recognised displayState wins outright');
_talapAssert(liveActivityPush_resolveDisplayState('{"displayState":"logo"}') === 'logo', 'displayState=logo recognised');
_talapAssert(liveActivityPush_resolveDisplayState('{"displayState":"live","blank":true}') === 'live', 'a recognised displayState wins even when blank disagrees');
_talapAssert(liveActivityPush_resolveDisplayState('{"displayState":"bogus","blank":true}') === 'blackout', 'an UNRECOGNISED displayState falls back to blank:true -> blackout');
_talapAssert(liveActivityPush_resolveDisplayState('{"displayState":"bogus","blank":false}') === 'live', 'an unrecognised displayState falls back to blank:false -> live');
_talapAssert(liveActivityPush_resolveDisplayState('{"blank":true}') === 'blackout', 'blank:true (no displayState) -> blackout');
_talapAssert(liveActivityPush_resolveDisplayState('{"blank":false}') === 'live', 'blank:false (no displayState) -> live');
_talapAssert(liveActivityPush_resolveDisplayState(null) === 'live', 'null StateJson defaults to live');
_talapAssert(liveActivityPush_resolveDisplayState('') === 'live', 'empty-string StateJson defaults to live');
_talapAssert(liveActivityPush_resolveDisplayState('not json at all') === 'live', 'garbage (undecodable) StateJson defaults to live');
_talapAssert(liveActivityPush_resolveDisplayState('{}') === 'live', 'empty object (neither key present) defaults to live');
_talapAssert(liveActivityPush_resolveDisplayState('"a json string, not an object"') === 'live', 'a valid-but-non-object JSON value defaults to live');

/* ---------------------------------------------------------------------- *
 * 2. liveActivityPush_contentState() — PURE.
 * ---------------------------------------------------------------------- */

$full = liveActivityPush_contentState('MP-1008', 'Shine Jesus Shine', 2, '{"displayState":"live"}', 7, true);
_talapAssert(
    array_keys($full) === ['songId', 'songTitle', 'componentIndex', 'displayState', 'revision', 'isLive'],
    'full contentState carries exactly the six expected keys, in order'
);
_talapAssert($full['songId'] === 'MP-1008' && is_string($full['songId']), 'songId round-trips as a string');
_talapAssert($full['componentIndex'] === 2 && is_int($full['componentIndex']), 'componentIndex round-trips as an int');
_talapAssert($full['revision'] === 7 && is_int($full['revision']), 'revision round-trips as an int');
_talapAssert($full['isLive'] === true && is_bool($full['isLive']), 'isLive round-trips as a bool');
_talapAssert($full['displayState'] === 'live', 'displayState resolved from the StateJson blob');

$sparse = liveActivityPush_contentState(null, null, null, null, 0, false);
_talapAssert(
    array_keys($sparse) === ['displayState', 'revision', 'isLive'],
    'null songId/songTitle/componentIndex are OMITTED entirely (never encoded as JSON null)'
);
_talapAssert(!array_key_exists('songId', $sparse) && !array_key_exists('songTitle', $sparse) && !array_key_exists('componentIndex', $sparse), 'no null-valued keys survive in the sparse contentState');
_talapAssert($sparse['displayState'] === 'live', 'sparse contentState defaults displayState to live with no StateJson');
_talapAssert($sparse['isLive'] === false, 'isLive=false passes through for a non-live (end) contentState');

/* componentIndex=0 is a legitimate value (the FIRST component), not "absent" —
   only a null componentIndex is omitted, never a falsy-but-present 0. */
$zeroIndex = liveActivityPush_contentState('MP-0001', 'Title', 0, null, 1, true);
_talapAssert(array_key_exists('componentIndex', $zeroIndex) && $zeroIndex['componentIndex'] === 0, 'componentIndex=0 is kept (falsy-but-not-null), not treated as absent');

/* ---------------------------------------------------------------------- *
 * 3. liveActivityPush_apsPayload() — PURE.
 * ---------------------------------------------------------------------- */

$NOW = time();
$cs = ['songId' => 'MP-1008', 'displayState' => 'live', 'revision' => 3, 'isLive' => true];

$updatePayload = liveActivityPush_apsPayload($cs, 'update', $NOW);
_talapAssert($updatePayload['aps']['timestamp'] === $NOW, 'aps.timestamp === the injected now');
_talapAssert($updatePayload['aps']['event'] === 'update', 'aps.event carries the requested event');
_talapAssert($updatePayload['aps']['content-state'] === $cs, 'aps.content-state passes the contentState array through unchanged');
_talapAssert($updatePayload['aps']['stale-date'] === $NOW + LIVE_SESSION_FRESHNESS_SECONDS, 'aps.stale-date === now + LIVE_SESSION_FRESHNESS_SECONDS (180)');
_talapAssert(!array_key_exists('dismissal-date', $updatePayload['aps']), "aps.dismissal-date is ABSENT for event='update'");

$endPayload = liveActivityPush_apsPayload($cs, 'end', $NOW);
_talapAssert($endPayload['aps']['event'] === 'end', 'end payload carries event=end');
_talapAssert(array_key_exists('dismissal-date', $endPayload['aps']), "aps.dismissal-date is PRESENT for event='end'");
_talapAssert($endPayload['aps']['dismissal-date'] === $NOW, 'dismissal-date defaults to now when $dismissAt is omitted');

$endPayloadExplicit = liveActivityPush_apsPayload($cs, 'end', $NOW, $NOW + 30);
_talapAssert($endPayloadExplicit['aps']['dismissal-date'] === $NOW + 30, 'an explicit $dismissAt overrides the now-default');

$threwOnInvalidEvent = false;
try {
    liveActivityPush_apsPayload($cs, 'bogus-event', $NOW);
} catch (\InvalidArgumentException $e) {
    $threwOnInvalidEvent = true;
}
_talapAssert($threwOnInvalidEvent, "liveActivityPush_apsPayload() throws InvalidArgumentException for an event outside {'update','end'}");

/* ---------------------------------------------------------------------- *
 * 4. Contract fixture — the SAME file a Swift decode test reads.
 * ---------------------------------------------------------------------- */

$fixturePath = dirname(__DIR__, 2) . '/appApple/Packages/iHymnsKit/Tests/Fixtures/live_activity_content_state.json';
_talapAssert(is_file($fixturePath), "contract fixture exists at $fixturePath");
if (is_file($fixturePath)) {
    $fixtureDecoded = json_decode((string)file_get_contents($fixturePath), true);
    $contractState = liveActivityPush_contentState('MP-1008', 'Shine Jesus Shine', 2, '{"displayState":"live"}', 7, true);
    _talapAssert(is_array($fixtureDecoded), 'fixture file decodes as valid JSON');
    /* #1429 Audit-B F12 — a loose `==` compare here type-juggles ("7"==7,
       true=="1"), which would silently pass even if the fixture stored its
       revision/isLive as JSON strings instead of a number/bool — exactly
       the class of drift this fixture exists to catch (this file's own
       header: "the two sides can never silently drift apart"). `ksort`
       both arrays (key ORDER must not matter for a semantic match) then
       compare with strict `===`, matching the per-key is_int()/is_bool()
       discipline the direct-output assertions above (~lines 75-78) already
       use. */
    $contractSorted = $contractState;
    $fixtureSorted = is_array($fixtureDecoded) ? $fixtureDecoded : [];
    ksort($contractSorted);
    ksort($fixtureSorted);
    _talapAssert($contractSorted === $fixtureSorted, 'liveActivityPush_contentState() output matches the fixture the Swift suite decodes, key-for-key (strict ===, no type juggling)');
}

/* ---------------------------------------------------------------------- *
 * 5. Dormancy — liveActivitySessionPush() NEVER throws, even with a bogus
 *    session id against an unconnected \mysqli handle (no DB reachable in
 *    this CLI context, mirroring test-gating-noop.php's "no DB" framing).
 *    mysqli_init() yields a REAL \mysqli-typed instance (satisfies the
 *    strict `\mysqli $db` parameter) that is simply never connected — every
 *    method call on it throws \Error, which apnsTokensTableExists()'s own
 *    try/catch(\Throwable) — and, as a last resort, this function's own
 *    outer try/catch — must absorb silently.
 * ---------------------------------------------------------------------- */

$bogusDb = mysqli_init();
_talapAssert($bogusDb instanceof \mysqli, 'mysqli_init() yields a real (unconnected) \mysqli instance for the dormancy probe');

$threwOnDormantPush = false;
try {
    liveActivitySessionPush($bogusDb, -999, 'update');
} catch (\Throwable $e) {
    $threwOnDormantPush = true;
}
_talapAssert($threwOnDormantPush === false, 'liveActivitySessionPush() never throws for a bogus sessionId against an unreachable DB (apnsTokensTableExists() gate absorbs it)');

$threwOnDormantEnd = false;
try {
    liveActivitySessionPush($bogusDb, -999, 'end');
} catch (\Throwable $e) {
    $threwOnDormantEnd = true;
}
_talapAssert($threwOnDormantEnd === false, "liveActivitySessionPush() never throws for event='end' either");

_talapAssert(apnsConfigured() === false, 'apnsConfigured() is false with no getAppSetting()/DB context in this CLI run — the OTHER half of the dormancy contract');

/* ---------------------------------------------------------------------- *
 * 6. Structural source-scan of api.php — the hook is actually wired in.
 * ---------------------------------------------------------------------- */

$apiFile = dirname(__DIR__, 2) . '/appWeb/public_html/api.php';
$apiSrc = (string)file_get_contents($apiFile);

_talapAssert(
    substr_count($apiSrc, 'liveActivitySessionPush(') >= 6,
    'api.php calls liveActivitySessionPush(...) at least 6 times (the 6 broadcast/end/supersede sites) — found ' . substr_count($apiSrc, 'liveActivitySessionPush(')
);
_talapAssert(
    str_contains($apiSrc, "'live_activity_push.php'"),
    "api.php require_once's includes/live_activity_push.php"
);

/**
 * Extract one `case '<label>':` body up to the next top-level `case`/
 * `default` at the same 8-space indent (mirrors
 * test-device-code.php's _tdcCaseBody() / test-auth-apple-platform.php's
 * _taapCaseBody()).
 */
function _talapCaseBody(string $source, string $caseLabel): ?string
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

$createBody = _talapCaseBody($apiSrc, 'live_follow_create');
_talapAssert($createBody !== null, "case 'live_follow_create' found in api.php");
_talapAssert($createBody !== null && str_contains($createBody, "'sessionId'"), "case 'live_follow_create's JSON response now exposes 'sessionId' (so the native app can register a Live Activity token against it)");
_talapAssert($createBody !== null && str_contains($createBody, 'liveActivitySessionPush('), "case 'live_follow_create' pushes an 'end' to any session it supersedes");

foreach (['live_follow_update', 'live_follow_leave', 'service_session_start', 'service_broadcast'] as $action) {
    $body = _talapCaseBody($apiSrc, $action);
    _talapAssert($body !== null, "case '{$action}' found in api.php");
    _talapAssert($body !== null && str_contains($body, 'liveActivitySessionPush('), "case '{$action}' calls liveActivitySessionPush(...)");
}

/* service_session_end is folded into the shared service_code_rotate/
   _current/_session_end case block (one combined `case 'a': case 'b':
   case 'c': { ... }` fallthrough chain, ALL sharing one body attached to
   the LAST label) — extracting from 'service_code_rotate' (the FIRST
   label) only captures the empty gap up to the next label, exactly the
   trap test-device-code.php's own docblock calls out for
   auth_device_code_approve/_deny. Extract from 'service_session_end' (the
   last label) instead, where the real shared body actually lives. */
$serviceEndChainBody = _talapCaseBody($apiSrc, 'service_session_end');
_talapAssert($serviceEndChainBody !== null, "the service_code_rotate/_current/_session_end case chain found in api.php");
_talapAssert($serviceEndChainBody !== null && str_contains($serviceEndChainBody, 'liveActivitySessionPush('), "the service_session_end branch calls liveActivitySessionPush(..., 'end')");

/* ---------------------------------------------------------------------- *
 * 7. apns_register — #1429 C3's auth fix (session-scoped operator gate).
 * ---------------------------------------------------------------------- */

$apnsRegisterBody = _talapCaseBody($apiSrc, 'apns_register');
_talapAssert($apnsRegisterBody !== null, "case 'apns_register' found in api.php");
_talapAssert($apnsRegisterBody !== null && str_contains($apnsRegisterBody, 'serviceMode_userCanOperate('), "case 'apns_register' gates the liveActivity branch on serviceMode_userCanOperate() (#1429 C3)");

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
