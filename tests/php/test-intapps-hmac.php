<?php

declare(strict_types=1);

/**
 * iHymns — IntAppsAPI HMAC signer fixed-vector test (#1725/#1729)
 *
 * ELI5: locks down the EXACT recipe `intappsSign()` uses to fingerprint a
 * request, and proves it is NOT the recipe the gateway's own bundled
 * examples use (which are wrong).
 *
 * DETAIL:
 * `includes/intapps_client.php::intappsSign()` is a PURE function — no DB,
 * no network — so its output for fixed inputs is fully deterministic and
 * assertable here without any environment setup. Verified against the
 * gateway's OWN source, `web/src/Helpers/HmacValidator.php:50-51` in the
 * `mwbm-intappsapi` repo:
 *
 *     $payload = $requestBody . '.' . $timestamp;
 *     $expected = hash_hmac('sha256', $payload, $secret);
 *
 * The gateway's five bundled client examples instead sign
 * `METHOD|PATH|TIMESTAMP|BODY` — filed upstream as MWBM-intAppsAPI#120.
 * This suite asserts our signer produces the FIRST string's digest and
 * explicitly proves it differs from the second (the "silently copy the
 * wrong example" trap this file exists to catch).
 *
 * This is a UNIT test only — it proves `intappsSign()`'s own arithmetic.
 * The stronger, end-to-end proof (a verifier ported line-for-line from the
 * same `HmacValidator.php`, run over real HTTP against the actual
 * signature this codebase produces) is `tests/php/test-intapps-stub-e2e.php`
 * (a later commit in this batch) — see that file for why a unit test alone
 * cannot rule out "both sides misread the same source the same way".
 *
 *   php tests/php/test-intapps-hmac.php
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

echo "1 — canonical string is rawBody . '.' . unixTimestamp (HmacValidator.php:50)\n";

$secret = 'sekrit-shared-key';
$ts     = 1735689600; /* 2025-01-01T00:00:00Z, arbitrary but fixed */
$body   = '{"foo":"bar"}';

$expected = hash_hmac('sha256', $body . '.' . $ts, $secret);
check('non-empty body matches hand-computed hash_hmac(body.".".ts, secret)', intappsSign($body, $ts, $secret) === $expected);
check('signature is 64 lowercase hex characters (SHA-256 digest)', (bool)preg_match('/^[0-9a-f]{64}$/', intappsSign($body, $ts, $secret)));

echo "\n2 — the empty-body case (GET / empty POST), asserted explicitly\n";

$expectedEmpty = hash_hmac('sha256', '.' . $ts, $secret);
check("empty body signs as '.' . timestamp, not an empty payload", intappsSign('', $ts, $secret) === $expectedEmpty);
check('empty-body signature differs from the non-empty-body signature above', intappsSign('', $ts, $secret) !== intappsSign($body, $ts, $secret));

echo "\n3 — different secrets / timestamps / bodies never collide (sanity)\n";

check('changing the secret changes the signature', intappsSign($body, $ts, $secret) !== intappsSign($body, $ts, 'a-different-secret'));
check('changing the timestamp changes the signature', intappsSign($body, $ts, $secret) !== intappsSign($body, $ts + 1, $secret));
check('changing the body changes the signature', intappsSign($body, $ts, $secret) !== intappsSign($body . 'x', $ts, $secret));

echo "\n4 — MWBM-intAppsAPI#120: the gateway's OWN bundled examples sign the\n";
echo "    WRONG string (METHOD|PATH|TIMESTAMP|BODY) — prove we do not\n";
echo "    silently reproduce that mistake for a case where it would matter\n";

$method = 'POST';
$path   = '/v1/features/ihymns/batch';
$wrongCanonical = $method . '|' . $path . '|' . $ts . '|' . $body;
$wrongSignature = hash_hmac('sha256', $wrongCanonical, $secret);
check(
    "intappsSign() output differs from the buggy examples' METHOD|PATH|TIMESTAMP|BODY scheme",
    intappsSign($body, $ts, $secret) !== $wrongSignature
);

echo "\n5 — an ISO-8601 timestamp string (the OTHER documented #120 trap) would\n";
echo "    produce a different digest than the correct decimal-seconds one —\n";
echo "    intappsSign() only ever receives an int, so this class of bug is\n";
echo "    structurally excluded, but pin the divergence anyway\n";

$isoTs = '2025-01-01T00:00:00Z';
$isoCanonical = $body . '.' . $isoTs;
$isoSignature = hash_hmac('sha256', $isoCanonical, $secret);
check(
    'a decimal-seconds timestamp and an ISO-8601 timestamp never produce the same signature for the same body/secret',
    $expected !== $isoSignature
);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "All intapps-hmac assertions passed.\n";
exit(0);
