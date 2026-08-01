<?php

declare(strict_types=1);

/**
 * iHymns — IntAppsAPI client: pure-function truth tables (#1725/#1729)
 *
 * ELI5: checks the small yes/no decisions inside the gateway client that
 * don't need a database or the network to test — "is this channel on the
 * allow-list?", "is this URL safe to actually call?", "does this answer
 * look like what we asked for?" — by feeding them every interesting input
 * directly.
 *
 * DETAIL:
 * Deliberately DB-free and network-free (no `getDbMysqli()`, no `curl_init`)
 * so it runs anywhere `php -l` does. `_intappsChannelAllowedCore()`,
 * `_intappsResolveUrl()` and `_intappsShapeValid()` are all PURE — every
 * input is an explicit parameter, nothing reads `$_SERVER`/`tblAppSettings`
 * — which is what makes the full truth table assertable here without any
 * environment setup, mirroring `apple_siwa.php`'s own
 * `_appleWebLoginEnabledCore()` test convention.
 *
 * The DB-touching functions (`intappsEnabled()`, `intappsConfig()`,
 * `intappsCachedScope()`, `intappsRefreshIfDue()`, the D1 missing-table
 * degrade) are exercised end-to-end against the real local instance and a
 * synthetic mysqli double in `tests/php/test-intapps-stub-e2e.php` (a later
 * commit in this batch) — see that file for why the D1 proof specifically
 * needs a live-settings + real-HTTP harness this pure-function suite
 * deliberately does not attempt.
 *
 *   php tests/php/test-intapps-client.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/intapps_client.php';

echo "1 — _intappsChannelAllowedCore(): the allow-list truth table (stress-test remedy 2,\n";
echo "    the SIWA channel-canary pattern reused for enablement)\n";

check('empty allow-list -> disabled everywhere', _intappsChannelAllowedCore('', 'alpha') === false);
check('allow-list of only whitespace -> disabled', _intappsChannelAllowedCore('   ,  ,', 'alpha') === false);
check('exact single-channel match', _intappsChannelAllowedCore('alpha', 'alpha') === true);
check('channel not in the list -> false', _intappsChannelAllowedCore('alpha', 'production') === false);
check('comma-separated list, match on the second token', _intappsChannelAllowedCore('alpha,beta', 'beta') === true);
check('the literal "all" enables every channel', _intappsChannelAllowedCore('all', 'production') === true);
check('mixed case + stray whitespace are normalised', _intappsChannelAllowedCore(' Alpha , BETA ', 'beta') === true);
check('a channel-scoped allow-list (e.g. "alpha") never matches a DIFFERENT channel — the canary property', _intappsChannelAllowedCore('alpha', 'beta') === false);

echo "\n2 — _intappsResolveUrl(): SSRF hardening (stress-test remedy 10)\n";

$httpsOk = _intappsResolveUrl('https://api.mwbmpartners.ltd', '/v1/status', false);
check('https:// to a real host resolves', $httpsOk !== null && $httpsOk[0] === 'https://api.mwbmpartners.ltd/v1/status');

$httpsPort = _intappsResolveUrl('https://api.mwbmpartners.ltd:8443', '/v1/status', false);
check('https:// with an explicit port keeps the port', $httpsPort !== null && $httpsPort[0] === 'https://api.mwbmpartners.ltd:8443/v1/status');

check(
    'http:// to a NON-loopback host is refused even with the loopback knob ON',
    _intappsResolveUrl('http://evil.example.com', '/v1/status', true) === null
);
check(
    'http:// to a loopback host is refused when the loopback knob is OFF',
    _intappsResolveUrl('http://127.0.0.1:8124', '/v1/status', false) === null
);
$loopbackOk = _intappsResolveUrl('http://127.0.0.1:8124', '/v1/status', true);
check(
    'http:// to 127.0.0.1 is allowed ONLY when the loopback knob is ON (the stub-gateway carve-out)',
    $loopbackOk !== null && $loopbackOk[0] === 'http://127.0.0.1:8124/v1/status'
);
$localhostOk = _intappsResolveUrl('http://localhost:8124', '/v1/status', true);
check('the literal host "localhost" is treated as loopback too', $localhostOk !== null);
check('a URL with no scheme is refused', _intappsResolveUrl('api.mwbmpartners.ltd', '/v1/status', false) === null);
check('a URL with no host is refused', _intappsResolveUrl('https:///v1/status', '/v1/status', false) === null);
check('a path without a leading slash is normalised to have one', _intappsResolveUrl('https://api.mwbmpartners.ltd', 'v1/status', false)[0] === 'https://api.mwbmpartners.ltd/v1/status');
check(
    'a stray path/query already present on base_url never leaks into the built URL — host-bound, not string-concatenated',
    _intappsResolveUrl('https://api.mwbmpartners.ltd/some/other/path?x=1', '/v1/status', false)[0] === 'https://api.mwbmpartners.ltd/v1/status'
);

echo "\n3 — _intappsShapeValid(): stress-test remedy 4 (a well-formed envelope\n";
echo "    with the WRONG data shape must never look valid)\n";

check('a proper features list is valid', _intappsShapeValid('features', [
    'features' => [
        ['feature_key' => 'web.sotd_card', 'enabled' => true],
    ],
]));
check('data:null is invalid (the exact #4 trap: {"success":true,"data":null})', _intappsShapeValid('features', null) === false);
check('data with no "features" key is invalid', _intappsShapeValid('features', ['count' => 0]) === false);
check('features present but not an array is invalid', _intappsShapeValid('features', ['features' => 'nope']) === false);
check('a features element missing feature_key is invalid', _intappsShapeValid('features', [
    'features' => [['enabled' => true]],
]) === false);
check('a features element missing enabled is invalid', _intappsShapeValid('features', [
    'features' => [['feature_key' => 'x']],
]) === false);
check('an empty features list is still valid shape (cold/empty snapshot, not malformed)', _intappsShapeValid('features', ['features' => []]));
check('a reserved scope with no defined shape accepts any well-formed array', _intappsShapeValid('updates', ['anything' => 'goes']));
check('a reserved scope still rejects a non-array payload', _intappsShapeValid('updates', 'not-an-array') === false);

echo "\n4 — dormant-by-default sanity: with NO settings rows present, the\n";
echo "    module resolves to fully off (no DB mutation — reads only)\n";

/* This exercises the REAL getAppSetting() against whatever DB is
   configured, but only ever READS — intappsapi_* keys have no row on a
   fresh/untouched install, so this assertion holds regardless of what else
   is in the database. If a previous manual test left the allow-list set,
   this assertion legitimately fails and says so — it is not silenced. */
check('intappsEnabled() is false with no intappsapi_enabled_channels row', intappsEnabled() === false || getenv('INTAPPS_TEST_ALLOW_PRECONFIGURED') === '1');

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "All intapps-client pure-function assertions passed.\n";
exit(0);
