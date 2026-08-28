<?php

declare(strict_types=1);

/**
 * tests/php/test-api-token-device-meta.php — device metadata: auto-name +
 * rename guard (#1975)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A signed-in "device" is a row in `tblApiTokens`. This guard proves two things:
 *   1. a WEB sign-in no longer lands as "Unnamed device" — the server derives a
 *      friendly name ("Chrome on Windows") from the request User-Agent; and
 *   2. every place that mints a token either writes that device metadata OR
 *      carries a written reason for deliberately not doing so — so a NEW sign-in
 *      path can't quietly reintroduce the "Unnamed" bug.
 * It also proves the `device_rename` endpoint is CSRF-gated and can only ever
 * touch the CALLER's own token.
 *
 * WHAT THIS ASSERTS
 * -----------------
 *  (A) apiTokenBrowserLabelFromUA()  — real-UA truth table; native/bot/empty→null.
 *  (B) apiTokenWebDeviceFallback()   — fills the gap when platform is null AND
 *      the UA is a browser; respects an explicit platform; leaves an
 *      unrecognised UA untouched (never fakes "web").
 *  (C) apiTokenCleanDeviceName()     — trim, 120-cap, blank/non-string→null.
 *  (D) TOKEN-MINT COVERAGE (tree-derived, rule #34) — every
 *      `INSERT INTO tblApiTokens (Token, UserId, ExpiresAt)` across api.php +
 *      manage/includes/auth.php is, within a window, either followed by an
 *      `apiTokenDeviceMetaStore(` call OR annotated with a `device-meta:` /
 *      `no-device-meta:` reason marker. Floor ≥ 5 (vacuity guard).
 *  (E) device_rename ENDPOINT — POST-gated, validateCsrfRequest(), a
 *      per-user rate limit, an un-migrated 409, and an UPDATE whose `UserId = ?`
 *      is IN the WHERE (own-only).
 *
 * Every check is mutation-proven: (A)-(C) are behavioural truth tables over the
 * real functions; (D)/(E) carry self-tests proving the scan logic itself can go
 * red.
 *
 * USAGE:  php tests/php/test-api-token-device-meta.php
 *
 * @see appWeb/public_html/includes/api_tokens.php
 * @see appWeb/public_html/api.php  case 'device_rename'
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/appWeb/public_html/includes/api_tokens.php';

$passed = 0; $failed = 0; $failures = [];
function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  \xE2\x9C\x85 {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/* ========================================================================
 * (A) apiTokenBrowserLabelFromUA — real UA truth table
 * ===================================================================== */
echo "\n(A) apiTokenBrowserLabelFromUA():\n";
$UA = [
    'chrome-win'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    'safari-mac'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'safari-ios'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'chrome-and'  => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36',
    'edge-win'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0',
    'firefox-lin' => 'Mozilla/5.0 (X11; Linux x86_64; rv:126.0) Gecko/20100101 Firefox/126.0',
    'samsung'     => 'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
    'crios'       => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/125.0.0.0 Mobile/15E148 Safari/604.1',
    'ipad'        => 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/604.1',
];
ok('Chrome on Windows',          apiTokenBrowserLabelFromUA($UA['chrome-win'])  === 'Chrome on Windows');
ok('Safari on Mac',              apiTokenBrowserLabelFromUA($UA['safari-mac'])  === 'Safari on Mac');
ok('Safari on iPhone',           apiTokenBrowserLabelFromUA($UA['safari-ios'])  === 'Safari on iPhone');
ok('Chrome on Android',          apiTokenBrowserLabelFromUA($UA['chrome-and'])  === 'Chrome on Android');
ok('Edge beats Chrome/Safari',   apiTokenBrowserLabelFromUA($UA['edge-win'])    === 'Edge on Windows');
ok('Firefox on Linux',           apiTokenBrowserLabelFromUA($UA['firefox-lin']) === 'Firefox on Linux');
ok('Samsung Internet on Android',apiTokenBrowserLabelFromUA($UA['samsung'])     === 'Samsung Internet on Android');
ok('Chrome-on-iOS via CriOS',    apiTokenBrowserLabelFromUA($UA['crios'])       === 'Chrome on iPhone');
ok('Safari on iPad',             apiTokenBrowserLabelFromUA($UA['ipad'])        === 'Safari on iPad');
ok('native app UA → null',       apiTokenBrowserLabelFromUA('iHymns/1.0 CFNetwork/1494.0.7 Darwin/23.4.0') === null);
ok('curl bot → null',            apiTokenBrowserLabelFromUA('curl/8.4.0') === null);
ok('empty UA → null',            apiTokenBrowserLabelFromUA('') === null);

/* ========================================================================
 * (B) apiTokenWebDeviceFallback — gap-fill + precedence
 * ===================================================================== */
echo "\n(B) apiTokenWebDeviceFallback():\n";
$fb1 = apiTokenWebDeviceFallback(null, null, $UA['chrome-win']);
ok('no platform + browser UA → web + derived name',
    $fb1['platform'] === 'web' && $fb1['deviceName'] === 'Chrome on Windows');
$fb2 = apiTokenWebDeviceFallback(null, 'apple', $UA['chrome-win']);
ok('explicit platform respected (never overridden)',
    $fb2['platform'] === 'apple' && $fb2['deviceName'] === null);
$fb3 = apiTokenWebDeviceFallback(null, null, 'iHymns/1.0 CFNetwork');
ok('unrecognised UA + no platform → left untouched (not faked web)',
    $fb3['platform'] === null && $fb3['deviceName'] === null);
$fb4 = apiTokenWebDeviceFallback('My Laptop', null, $UA['chrome-win']);
ok('a client-sent name is kept, platform filled to web',
    $fb4['platform'] === 'web' && $fb4['deviceName'] === 'My Laptop');

/* ========================================================================
 * (C) apiTokenCleanDeviceName — trim / cap / blank
 * ===================================================================== */
echo "\n(C) apiTokenCleanDeviceName():\n";
ok('trims surrounding whitespace',  apiTokenCleanDeviceName('  Foyer TV  ') === 'Foyer TV');
ok('blank → null (clear)',          apiTokenCleanDeviceName('   ') === null);
ok('non-string → null',             apiTokenCleanDeviceName(null) === null && apiTokenCleanDeviceName(42) === null);
$long = str_repeat('x', 200);
ok('caps at 120 code points',       mb_strlen((string)apiTokenCleanDeviceName($long)) === 120);
ok('keeps a normal name intact',    apiTokenCleanDeviceName("Lance's iPhone") === "Lance's iPhone");

/* ========================================================================
 * (D) TOKEN-MINT COVERAGE — every mint writes metadata or states why not
 * ===================================================================== */
echo "\n(D) token-mint coverage (tree-derived):\n";

/** A mint's surrounding window is "covered" when it either stores device
 *  metadata OR carries a written reason marker. */
function mintCovered(string $window): bool
{
    return strpos($window, 'apiTokenDeviceMetaStore(') !== false
        || strpos($window, 'device-meta:') !== false;   /* matches device-meta: AND no-device-meta: */
}

/* Self-test the scan logic can fail (rule #34). */
ok('mintCovered self-test: bare window is NOT covered',
    mintCovered("\$stmt = INSERT INTO tblApiTokens ...;\n\$stmt->execute();") === false);
ok('mintCovered self-test: a store call IS covered',
    mintCovered("...\napiTokenDeviceMetaStore(\$db, \$h, ...);") === true);
ok('mintCovered self-test: a reason marker IS covered',
    mintCovered("/* no-device-meta: internal bridge token */") === true);

$mintFiles = [
    $ROOT . '/appWeb/public_html/api.php',
    $ROOT . '/appWeb/public_html/manage/includes/auth.php',
];
$MINT = 'INSERT INTO tblApiTokens (Token, UserId, ExpiresAt)';
$WIN_BEFORE = 35; $WIN_AFTER = 25;
$mintsFound = 0; $mintsUncovered = [];
foreach ($mintFiles as $f) {
    $lines = explode("\n", (string)file_get_contents($f));
    foreach ($lines as $i => $line) {
        if (strpos($line, $MINT) === false) { continue; }
        $mintsFound++;
        $from = max(0, $i - $WIN_BEFORE);
        $window = implode("\n", array_slice($lines, $from, $WIN_BEFORE + $WIN_AFTER + 1));
        if (!mintCovered($window)) {
            $mintsUncovered[] = basename($f) . ':' . ($i + 1);
        }
    }
}
ok('found the token-mint sites (floor ≥ 5, vacuity guard)', $mintsFound >= 5);
ok('EVERY token mint stores device metadata or carries a reason marker'
    . ($mintsUncovered ? ' — UNCOVERED: ' . implode(', ', $mintsUncovered) : ''),
    $mintsUncovered === []);

/* ========================================================================
 * (E) device_rename endpoint hardening (source scan of api.php)
 * ===================================================================== */
echo "\n(E) device_rename endpoint:\n";
$api = (string)file_get_contents($ROOT . '/appWeb/public_html/api.php');
$start = strpos($api, "case 'device_rename':");
ok('device_rename case exists', $start !== false);
$block = '';
if ($start !== false) {
    /* Bound the block at the next `case '` after a reasonable minimum, so we
       scan the endpoint body and not the whole file. */
    $next = strpos($api, "\n        case '", $start + 20);
    $block = substr($api, $start, ($next !== false ? $next - $start : 4000));
}
ok('POST-gated (405 otherwise)',
    strpos($block, "REQUEST_METHOD") !== false && strpos($block, '405') !== false);
ok('CSRF via validateCsrfRequest()',
    strpos($block, 'validateCsrfRequest(') !== false);
ok('per-user rate limit on device_rename',
    strpos($block, "checkRateLimit('device_rename'") !== false);
ok('un-migrated install → 409 (distinct from 404)',
    strpos($block, 'apiTokensDeviceMetaColumnsExist(') !== false && strpos($block, '409') !== false);
ok('OWN-ONLY: the UPDATE binds UserId = ? inside the WHERE',
    (bool)preg_match('/UPDATE\s+tblApiTokens\s+SET\s+DeviceName\s*=\s*\?\s+WHERE\s+UserId\s*=\s*\?/', $block));
ok('device id is regex-pinned to the hash-prefix length',
    strpos($block, 'API_TOKEN_DEVICE_ID_LENGTH') !== false);
/* Self-test (E) can fail: the own-only regex must NOT match an unscoped UPDATE. */
ok('own-only self-test: an unscoped UPDATE would fail the regex',
    !preg_match('/UPDATE\s+tblApiTokens\s+SET\s+DeviceName\s*=\s*\?\s+WHERE\s+UserId\s*=\s*\?/',
        'UPDATE tblApiTokens SET DeviceName = ? WHERE Token LIKE CONCAT(?, "%")'));

/* ===================================================================== */
echo "\n" . ($failed === 0
    ? "PASS: {$passed} assertion(s) — device auto-name + rename are wired and hardened.\n"
    : "FAIL: {$failed} of " . ($passed + $failed) . " assertion(s) failed:\n  - " . implode("\n  - ", $failures) . "\n");
exit($failed === 0 ? 0 : 1);
