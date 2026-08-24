<?php

declare(strict_types=1);

/**
 * iHymns — Webhook HMAC signer fixed-vector test (#1909)
 *
 * ELI5: locks the EXACT recipe `webhookSign()` uses so a partner's verifier can
 * be written against it, and proves it is the Stripe recipe (`t.body`) — NOT the
 * intapps recipe (`body.t`) — so a well-meaning "unify the two signers" refactor
 * that swapped the order would fail here instead of silently breaking every
 * partner verifier.
 *
 * DETAIL:
 * `includes/webhooks.php::webhookSign()` is PURE — deterministic, no DB/network.
 * Signed string is `"$ts.$rawBody"` (design §7.1, Stripe's scheme). The rotation
 * header (`webhookSignatureHeader()`) carries a SECOND `v1=` under the previous
 * secret only while the grace window is open.
 *
 *   php tests/php/test-webhook-sign.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 * @link https://stripe.com/docs/webhooks/signatures
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/webhooks.php';

$secret = 'whsec_deadbeefcafef00d';
$ts     = 1735689600;               /* fixed */
$body   = '{"id":"evt_abc","type":"song.updated"}';

echo "1 — canonical signed string is \"\$ts.\$rawBody\" (Stripe order, design §7.1)\n";
$expected = hash_hmac('sha256', $ts . '.' . $body, $secret);
check('matches hand-computed hash_hmac(ts.".".body, secret)', webhookSign($body, $ts, $secret) === $expected);
check('signature is 64 lowercase hex chars', (bool)preg_match('/^[0-9a-f]{64}$/', webhookSign($body, $ts, $secret)));

echo "\n2 — it DELIBERATELY differs from the intapps body.ts order (must not be unified)\n";
$wrongOrder = hash_hmac('sha256', $body . '.' . $ts, $secret);
check('webhookSign() != the body.ts (intapps) order for the same inputs', webhookSign($body, $ts, $secret) !== $wrongOrder);

echo "\n3 — sensitivity: secret / ts / body each change the signature\n";
check('changing the secret changes it', webhookSign($body, $ts, $secret) !== webhookSign($body, $ts, 'whsec_other'));
check('changing the timestamp changes it', webhookSign($body, $ts, $secret) !== webhookSign($body, $ts + 1, $secret));
check('changing the body changes it', webhookSign($body, $ts, $secret) !== webhookSign($body . 'x', $ts, $secret));

echo "\n4 — signature header shape: t=<ts>,v1=<hmac> (single, no rotation)\n";
$h1 = webhookSignatureHeader($body, $ts, $secret, null, false);
check('single-secret header is exactly t=..,v1=..', $h1 === 't=' . $ts . ',v1=' . $expected);
check('single-secret header has exactly one v1=', substr_count($h1, 'v1=') === 1);

echo "\n5 — rotation grace: a SECOND v1= appears under the previous secret only when active\n";
$prev = 'whsec_previoussecret';
$expectedPrev = hash_hmac('sha256', $ts . '.' . $body, $prev);
$h2 = webhookSignatureHeader($body, $ts, $secret, $prev, true);
check('active rotation header carries two v1= entries', substr_count($h2, 'v1=') === 2);
check('rotation header = current then previous', $h2 === 't=' . $ts . ',v1=' . $expected . ',v1=' . $expectedPrev);
$h3 = webhookSignatureHeader($body, $ts, $secret, $prev, false);
check('EXPIRED rotation drops the previous v1= (only one signature)', substr_count($h3, 'v1=') === 1);
$h4 = webhookSignatureHeader($body, $ts, $secret, null, true);
check('a null previous secret never adds a second v1= even if "active"', substr_count($h4, 'v1=') === 1);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} webhook-sign assertion(s) failed.\n");
    exit(1);
}
echo "All webhook-sign assertions passed.\n";
exit(0);
