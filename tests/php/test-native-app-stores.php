<?php

declare(strict_types=1);

/**
 * iHymns — Native app store config test (#1403 / #1462)
 *
 * Pure-function coverage (no DB) for the admin-configurable native-app
 * store pipeline in includes/config.php:
 *   - ihymnsParseAppStoreId()        — bare ID/URL parsing + rejection,
 *                                       all 3 platforms (ios/android/amazon)
 *   - ihymnsAppStoreCanonicalUrl()   — canonical, template-built store URLs
 *   - verifyAppStoreApp()            — android/amazon fail-open-when-
 *                                       well-formed + malformed/empty
 *                                       rejection (no network calls made
 *                                       for these two platforms)
 *   - ihymnsResolveNativeAppSetting()— the tblAppSettings-overrides-
 *                                       APP_CONFIG-constant precedence
 *
 * The iOS live iTunes-lookup branch of verifyAppStoreApp() (network-error
 * fail-open vs genuine-not-found fail-closed) needs a real/simulated
 * network condition and is exercised manually (mirrors the DB-dependent
 * exclusions noted in test-song-public-id.php) — not asserted here.
 *
 *   php tests/php/test-native-app-stores.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

$_SERVER['SCRIPT_FILENAME'] = 'test-native-app-stores.php'; /* dodge config.php's direct-access guard */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/config.php';

$fail = 0;
function ok(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $fail++; }
}

/* -----------------------------------------------------------------------
 * ihymnsParseAppStoreId() — Apple App Store
 * ---------------------------------------------------------------------- */
ok('ios: bare numeric ID accepted',
    ihymnsParseAppStoreId('ios', '1234567890') === '1234567890');
ok('ios: full store URL → ID extracted',
    ihymnsParseAppStoreId('ios', 'https://apps.apple.com/us/app/ihymns/id1234567890') === '1234567890');
ok('ios: too-short numeric ID rejected',
    ihymnsParseAppStoreId('ios', '123') === null);
ok('ios: non-numeric garbage rejected',
    ihymnsParseAppStoreId('ios', 'not-an-id') === null);
ok('ios: empty string rejected',
    ihymnsParseAppStoreId('ios', '') === null);
ok('ios: a Google Play URL is rejected (wrong platform shape)',
    ihymnsParseAppStoreId('ios', 'https://play.google.com/store/apps/details?id=ltd.mwbmpartners.ihymns') === null);

/* -----------------------------------------------------------------------
 * ihymnsParseAppStoreId() — Google Play
 * ---------------------------------------------------------------------- */
ok('android: bare package name accepted',
    ihymnsParseAppStoreId('android', 'ltd.mwbmpartners.ihymns') === 'ltd.mwbmpartners.ihymns');
ok('android: full store URL → package extracted',
    ihymnsParseAppStoreId('android', 'https://play.google.com/store/apps/details?id=ltd.mwbmpartners.ihymns&hl=en')
        === 'ltd.mwbmpartners.ihymns');
ok('android: a package with disallowed characters is rejected',
    ihymnsParseAppStoreId('android', 'ltd.mwbm partners!.ihymns') === null);
ok('android: empty string rejected',
    ihymnsParseAppStoreId('android', '') === null);
ok('android: a bare non-URL, non-package string with a slash is rejected',
    ihymnsParseAppStoreId('android', 'not/a/package') === null);

/* -----------------------------------------------------------------------
 * ihymnsParseAppStoreId() — Amazon Appstore
 * ---------------------------------------------------------------------- */
ok('amazon: bare 10-char ASIN accepted + uppercased',
    ihymnsParseAppStoreId('amazon', 'b0abcdefgh') === 'B0ABCDEFGH');
ok('amazon: full store URL → ASIN extracted + uppercased',
    ihymnsParseAppStoreId('amazon', 'https://www.amazon.com/dp/b0abcdefgh/ref=foo') === 'B0ABCDEFGH');
ok('amazon: 9-char (too short) ASIN rejected',
    ihymnsParseAppStoreId('amazon', 'B0ABCDEFG') === null);
ok('amazon: 11-char (too long) ASIN rejected',
    ihymnsParseAppStoreId('amazon', 'B0ABCDEFGHI') === null);
ok('amazon: empty string rejected',
    ihymnsParseAppStoreId('amazon', '') === null);

/* Cross-check: an unknown platform never parses. */
ok('unknown platform: always rejected',
    ihymnsParseAppStoreId('windows', '1234567890') === null);

/* -----------------------------------------------------------------------
 * ihymnsAppStoreCanonicalUrl() — templated, never the raw input
 * ---------------------------------------------------------------------- */
ok('canonical URL: ios',
    ihymnsAppStoreCanonicalUrl('ios', '1234567890') === 'https://apps.apple.com/app/id1234567890');
ok('canonical URL: android',
    ihymnsAppStoreCanonicalUrl('android', 'ltd.mwbmpartners.ihymns')
        === 'https://play.google.com/store/apps/details?id=ltd.mwbmpartners.ihymns');
ok('canonical URL: amazon',
    ihymnsAppStoreCanonicalUrl('amazon', 'B0ABCDEFGH') === 'https://www.amazon.com/dp/B0ABCDEFGH');
ok('canonical URL: unknown platform → null',
    ihymnsAppStoreCanonicalUrl('windows', 'X') === null);

/* -----------------------------------------------------------------------
 * verifyAppStoreApp() — android/amazon: no live-lookup API exists, so a
 * set + well-formed ID/ASIN is fail-open "verified" with a canonical URL;
 * malformed/empty is fail-closed. Neither branch makes a network call.
 * ---------------------------------------------------------------------- */
$androidResult = verifyAppStoreApp('android', 'ltd.mwbmpartners.ihymns');
ok('android: well-formed package → verified true', $androidResult['verified'] === true);
ok('android: verified result carries the canonical store URL',
    ($androidResult['storeUrl'] ?? null) === 'https://play.google.com/store/apps/details?id=ltd.mwbmpartners.ihymns');

ok('android: malformed package → verified false',
    verifyAppStoreApp('android', 'not a package!!')['verified'] === false);
ok('android: null → verified false',
    verifyAppStoreApp('android', null)['verified'] === false);
ok('android: empty string → verified false',
    verifyAppStoreApp('android', '')['verified'] === false);

$amazonResult = verifyAppStoreApp('amazon', 'https://www.amazon.com/dp/b0abcdefgh');
ok('amazon: well-formed URL → verified true', $amazonResult['verified'] === true);
ok('amazon: verified result carries the canonical (uppercased) store URL',
    ($amazonResult['storeUrl'] ?? null) === 'https://www.amazon.com/dp/B0ABCDEFGH');
ok('amazon: malformed ASIN → verified false',
    verifyAppStoreApp('amazon', 'nope')['verified'] === false);
ok('amazon: empty string → verified false',
    verifyAppStoreApp('amazon', '')['verified'] === false);

/* -----------------------------------------------------------------------
 * ihymnsResolveNativeAppSetting() — admin setting overrides the
 * APP_CONFIG constant; empty/unset setting (incl. a DB-unavailable
 * getAppSetting() default of '') falls back to the constant.
 * ---------------------------------------------------------------------- */
ok('resolve: a set tblAppSettings value wins over the constant fallback',
    ihymnsResolveNativeAppSetting('999999999', '111111111') === '999999999');
ok('resolve: empty-string setting (unset row / DB-down default) falls back to the constant',
    ihymnsResolveNativeAppSetting('', '111111111') === '111111111');
ok('resolve: null setting falls back to the constant',
    ihymnsResolveNativeAppSetting(null, '111111111') === '111111111');
ok('resolve: both unset → null',
    ihymnsResolveNativeAppSetting('', null) === null);
ok('resolve: setting set, constant null → the setting',
    ihymnsResolveNativeAppSetting('999999999', null) === '999999999');

if ($fail === 0) { echo "\nAll native-app-store assertions passed.\n"; exit(0); }
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
