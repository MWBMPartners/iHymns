<?php

declare(strict_types=1);

/**
 * iHymns — Outbound-webhooks drain endpoint (#1909)
 *
 * ELI5: a tiny URL a cron job (or an uptime monitor) pokes every minute to push
 * out any webhook retries that are due, and to sweep away expired rows. It hands
 * back a small JSON tally of what it did.
 *
 * DETAIL:
 * -------
 * A standalone docroot script (the qr.php / og-image.php shape — never a new
 * hot-path branch in the 20k-line api.php). It is the ACCELERATOR of the hybrid
 * outbox (design §6.4): the immediate first attempt already runs post-flush in
 * the emitting request (includes/webhooks.php::webhookScheduleFirstAttempt());
 * this endpoint drains the backlog / retries. A cron-less install still
 * progresses because the post-flush pass also drains a little extra whenever
 * anything emits — retry latency is then traffic-shaped, documented honestly.
 *
 * CONTRACT (status is the contract, rule #35):
 *   200  {ok:true, data:{claimed,sent,failed,dead,pruned}}  — a drain ran.
 *   403  (no body)  — wrong / absent drain key.
 *   503  (no body)  — dormant: webhooks off on this channel OR tables un-migrated.
 *
 * AUTH: the `webhook_drain_key` app-setting (a secret — encrypted at rest,
 * registered in secretSettingKeys()), supplied as ?key=… or the X-Drain-Key
 * header, compared with hash_equals(). The key grants only "spend our OWN drain
 * budget faster" — it can neither read nor forge a webhook — and the endpoint is
 * rate-limited (fail-open) so a leaked key cannot hammer the DB.
 *
 * WIRING: cPanel cron / UptimeRobot →
 *   curl -fsS "https://…/webhook-drain?key=<drain key>"   every minute.
 * (Documented beside .sql/cleanup.php's crontab line; cleanup.php ALSO prunes the
 * expired rows for installs that already run it nightly.)
 *
 * ROUTING (routing-bug fix, rules #33/#38/#42): the address above is
 * `/webhook-drain`, deliberately WITHOUT `.php` — `.htaccess`'s "block
 * direct PHP access" rule 404s ANY request whose raw text contains ".php"
 * before this file's own code ever runs, so a literal `/webhook-drain.php`
 * curl target (this doc-block's own wording, until this fix) has NEVER
 * actually reached this file. `.htaccess` now carries the matching
 * extensionless alias (the /qr, /org-logo, /og-image precedent). This file's
 * OWN disk filename is unchanged — only the address a caller must use is.
 *
 * @see .claude/webhooks-1909-design.md §6.4
 * @see includes/webhooks.php (webhookDrainPass / webhookPruneExpired)
 * @link https://www.php.net/manual/en/function.hash-equals.php
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'read_rate_limit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'webhooks.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/** End the request with a status and NO body (the caller only needs the code). */
function _webhookDrainNoBody(int $status): void
{
    http_response_code($status);
    exit;
}

/* Rate-limit the endpoint (fail-open, rule #28-C) — a leaked key cannot be used
   to hammer the DB. Keyed by IP; a per-minute cron is well under this. */
try {
    enforceReadRateLimitKeyed('webhook_drain', 120);
} catch (\Throwable $e) {
    /* fail-open — never let the limiter itself break the endpoint */
}

try {
    $db = getDbMysqli();
} catch (\Throwable $e) {
    _webhookDrainNoBody(503); /* DB unreachable — dormant/degraded, no body */
}

/* Dormancy gate: off on this channel OR un-migrated ⇒ 503 no body (the qr.php
   degrade shape). Nothing to drain, and we never reveal whether the key was
   right for a dormant install. */
if (!webhooksEnabled() || !webhookSchemaReady($db)) {
    _webhookDrainNoBody(503);
}

/* Auth: the drain key (constant-time compare). Absent config ⇒ 403 (can't
   authorise). Wrong key ⇒ 403. No body either way. */
$provided = (string)($_GET['key'] ?? $_SERVER['HTTP_X_DRAIN_KEY'] ?? '');
$expected = (string)(getAppSetting(WEBHOOK_SETTING_DRAIN_KEY, '') ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    _webhookDrainNoBody(403);
}

/* Drain due deliveries, then a bounded retention prune, then record the drain
   time so /manage/configuration can show "cron is (not) wired". */
$drain  = webhookDrainPass($db, ['max' => WEBHOOK_DRAIN_MAX_DEFAULT, 'budget_ms' => WEBHOOK_DRAIN_BUDGET_MS_DEFAULT]);
$pruned = webhookPruneExpired($db, WEBHOOK_PRUNE_MAX_DEFAULT);
try {
    setAppSetting($db, WEBHOOK_SETTING_LAST_DRAIN_AT, gmdate('Y-m-d H:i:s'));
} catch (\Throwable $e) {
    /* best-effort telemetry — never fail the drain over it */
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok'   => true,
    'data' => [
        'claimed' => (int)$drain['claimed'],
        'sent'    => (int)$drain['sent'],
        'failed'  => (int)$drain['failed'],
        'dead'    => (int)$drain['dead'],
        'pruned'  => (int)$pruned,
    ],
], JSON_UNESCAPED_SLASHES);
