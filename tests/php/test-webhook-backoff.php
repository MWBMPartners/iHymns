<?php

declare(strict_types=1);

/**
 * iHymns — Webhook retry backoff schedule test (#1909)
 *
 * ELI5: proves the retry timing is exactly the ladder the design promises
 * (1m → 5m → 30m → 2h → 6h → 12h → 24h → 24h) and that once those retries are
 * used up the delivery is declared dead — not retried forever.
 *
 * DETAIL:
 * `includes/webhooks.php::webhookNextDelaySeconds()` is PURE (jitter is applied
 * separately). AttemptCount = attempts MADE; the delay after attempt N is
 * schedule[N-1]; past the schedule length ⇒ null ⇒ dead-letter. 8 schedule
 * entries ⇒ 8 retries + the initial attempt = 9 attempts total (design §6.3).
 * Mutation-proven: changing any schedule value or the dead-letter boundary flips
 * an assertion here.
 *
 *   php tests/php/test-webhook-backoff.php
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

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/webhooks.php';

echo "1 — the schedule is the exact 2.2-day ladder (design §6.3)\n";
$expected = [60, 300, 1800, 7200, 21600, 43200, 86400, 86400];
check('WEBHOOK_RETRY_SCHEDULE matches the documented ladder', WEBHOOK_RETRY_SCHEDULE === $expected);
check('the schedule has 8 entries (8 retries + 1 initial = 9 attempts)', count(WEBHOOK_RETRY_SCHEDULE) === 8);

echo "\n2 — each attempt maps to the right next delay\n";
foreach ($expected as $i => $secs) {
    $attemptNo = $i + 1;                 /* after attempt 1, delay = schedule[0] */
    check("attempt $attemptNo -> {$secs}s", webhookNextDelaySeconds($attemptNo) === $secs);
}

echo "\n3 — the dead-letter boundary is exact\n";
check('attempt 8 (last retry scheduled) -> 86400s', webhookNextDelaySeconds(8) === 86400);
check('attempt 9 (all retries exhausted) -> null (dead)', webhookNextDelaySeconds(9) === null);
check('attempt 20 -> null (dead)', webhookNextDelaySeconds(20) === null);

echo "\n4 — nonsensical attempt counts never crash and never schedule\n";
check('attempt 0 -> null', webhookNextDelaySeconds(0) === null);
check('attempt -5 -> null', webhookNextDelaySeconds(-5) === null);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} webhook-backoff assertion(s) failed.\n");
    exit(1);
}
echo "All webhook-backoff assertions passed.\n";
exit(0);
