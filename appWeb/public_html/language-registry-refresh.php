<?php

declare(strict_types=1);

/**
 * iHymns — IANA + CLDR language registry keyed refresh endpoint (BCP 47
 * registry plan §3, M1)
 *
 * ELI5: a tiny URL a monthly robot (a GitHub Action, or any cron) pokes
 * to say "go re-check the official world-language list and teach the
 * database anything new" — without a human ever having to click the
 * button on `/manage/setup-database` themselves.
 *
 * DETAIL:
 * -------
 * A standalone docroot script — the `qr.php` / `og-image.php` /
 * `webhook-drain.php` shape, never a new hot-path branch in the 20k-line
 * `api.php`. It is Leg B of the plan's two-leg scheduled refresh (§3.2):
 * Leg A is a GitHub Action that refreshes the git-tracked snapshot files
 * and pushes them to `alpha`; Leg B (this endpoint) independently pokes
 * the LIVE shared DB to re-fetch upstream itself and re-run the
 * idempotent import — no ordering dependency between the two, and one
 * shared MySQL means one poke updates every channel (alpha/beta/main all
 * read the same DB).
 *
 * CONTRACT (status is the contract, rule #35):
 *   200  {ok:true, data:{fetched:[…], migrationLog:"…"}}  — a refresh ran.
 *   403  (no body)  — wrong / absent refresh key.
 *   503  (no body)  — dormant: no key configured on this channel, OR the
 *                      #738 schema has never been applied (see the
 *                      dormancy-gate note below — this is deliberate).
 *   502  {error, failed:[…]}  — an upstream IANA/CLDR fetch failed;
 *                      snapshots + DB are left completely untouched.
 *
 * AUTH: the `language_registry_refresh_key` app-setting (a secret —
 * encrypted at rest, registered in `secretSettingKeys()`), supplied as
 * `?key=…` or the `X-Refresh-Key` header, compared with `hash_equals()`.
 * The key grants only "spend our own refresh budget faster, and re-fetch
 * CANONICAL public IANA/CLDR data" — it accepts NO caller-supplied URL,
 * filename, or payload of any kind (verified by
 * `tests/php/test-language-registry-refresh.php`), so a leaked key cannot
 * inject data or reach any other host; the endpoint is also rate-limited
 * (fail-open) so a leaked key cannot hammer the DB or IANA/CLDR's servers.
 *
 * DORMANCY GATE (deliberate — the plan's §3.4 "never the first DDL run"
 * rule): `languageRegistrySchemaReady()` must be true — i.e. a human has
 * pressed the "Run IANA + CLDR Import" card on `/manage/setup-database`
 * at least ONCE, on this shared DB — before this endpoint will do
 * anything. An unattended cron endpoint must never be the thing that
 * first runs schema DDL (a table rename + new columns + a new table) on
 * the shared production DB; that stays a deliberate human action. Once
 * the card has been pressed once, the schema never needs touching again
 * (the #738 migration is purely additive), so every subsequent scheduled
 * run is a normal data-only refresh — see the plan's Activation Runbook
 * §7 for the full one-time setup sequence.
 *
 * WIRING: `.github/workflows/language-registry-refresh.yml` (monthly cron
 * + `workflow_dispatch`) →
 *   curl -fsS -X POST "https://…/language-registry-refresh" \
 *        -H "X-Refresh-Key: <key>"
 * A cPanel cron / uptime monitor works exactly as well (the
 * `webhook-drain.php` precedent this file mirrors documents the same
 * fallback) — the GitHub Action is simply the owner's preferred delivery
 * mechanism (BCP 47 plan §3, owner decision D2).
 *
 * ROUTING (rule #41/#33 — the /qr + /org-logo + /webhook-drain lesson,
 * commit 47bea481): this file's own disk name is
 * `language-registry-refresh.php`, but `.htaccess`'s "block direct .php
 * access" rule 404s ANY request whose raw request line contains ".php"
 * before this file's own code ever runs. The ONLY address a caller may
 * use is the extensionless `.htaccess` alias `/language-registry-refresh`
 * (see `.htaccess`) — `tests/php/test-endpoint-routing.php` verifies this
 * mechanically (it derives the check from the real `.htaccess` + the real
 * tree, never a typed URL list) so a literal `.php` URL anywhere in this
 * repo for this endpoint fails CI rather than shipping silently dead.
 *
 * @see includes/language_registry_refresh.php   the ONE refresh core (rule #35 — shared with api.php's admin button)
 * @see .claude/bcp47-language-registry-plan.md §3  the plan this implements
 * @see appWeb/public_html/webhook-drain.php        the pattern this file mirrors byte-for-byte
 * @link https://www.php.net/manual/en/function.hash-equals.php
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* maintenance.php is where getAppSetting() lives, and it is what pulls in
   secret_crypto.php's secretIsEncrypted()/secretDecrypt() (mirrors
   webhook-drain.php's identical require list) — needed so the
   language_registry_refresh_key comparison below reads the TRANSPARENTLY
   DECRYPTED value, not an enc:v1 ciphertext envelope. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'read_rate_limit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'language_registry_refresh.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/** End the request with a status and NO body (the caller only needs the code). */
function _languageRegistryRefreshNoBody(int $status): void
{
    http_response_code($status);
    exit;
}

/* Rate-limit the endpoint (fail-open, rule #28-C) — a leaked key cannot be
   used to hammer the DB or upstream IANA/CLDR. Keyed by IP; a monthly
   cron is nowhere near this budget — the ceiling exists purely to bound
   abuse of a leaked key, not to constrain legitimate use. */
try {
    enforceReadRateLimitKeyed('language_registry_refresh', 6);
} catch (\Throwable $e) {
    /* fail-open — never let the limiter itself break the endpoint */
}

try {
    $db = getDbMysqli();
} catch (\Throwable $e) {
    _languageRegistryRefreshNoBody(503); /* DB unreachable — dormant/degraded, no body */
}

/* Dormancy gate #1: no key configured on this channel at all ⇒ 503. Read
   BEFORE the schema gate so an operator who hasn't set up EITHER step
   sees the same "dormant" signal either way — this endpoint deliberately
   never distinguishes the two failure reasons in its response (a 503
   reveals nothing about which precondition is missing, matching
   webhook-drain.php's identical posture). */
$expected = (string)(getAppSetting('language_registry_refresh_key', '') ?? '');
if ($expected === '') {
    _languageRegistryRefreshNoBody(503);
}

/* Dormancy gate #2 (the plan's load-bearing rule, §3.4): the #738 schema
   must already be live — i.e. a human has pressed the setup-database card
   at least once. Checked BEFORE authenticating the key so a correctly-
   keyed-but-premature request degrades exactly like a not-yet-configured
   one, never runs the fetch, and never becomes the first thing to alter
   schema on the shared DB. */
if (!languageRegistrySchemaReady($db)) {
    _languageRegistryRefreshNoBody(503);
}

/* Auth: the refresh key (constant-time compare). This is the ONLY input
   this endpoint reads from the request — no other $_GET/$_POST/header
   value is consulted anywhere below (CI-guarded:
   tests/php/test-language-registry-refresh.php asserts this file reads no
   request input beyond the key), so a leaked key cannot be used to make
   this endpoint fetch or write anything other than the fixed, hardcoded
   IANA/CLDR URLs inside languageRegistryRefreshCore(). Wrong / absent key
   ⇒ 403, no body — never reveals whether a schema/config precondition
   was also missing. */
$provided = (string)($_GET['key'] ?? $_SERVER['HTTP_X_REFRESH_KEY'] ?? '');
if ($provided === '' || !hash_equals($expected, $provided)) {
    _languageRegistryRefreshNoBody(403);
}

/* Run the SAME core the admin "Refresh from IANA + CLDR (live)" button
   calls (rule #35 — one mechanism). */
$result = languageRegistryRefreshCore();

if (!$result['ok']) {
    /* Upstream fetch failure (or a local write failure) — snapshots + DB
       are left untouched by design (languageRegistryRefreshCore() never
       partially writes). Reported with detail (this response is only ever
       read by the operator's own cron/Action logs, never by an anonymous
       caller — the 403/503 paths above already gate who gets this far). */
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(502);
    echo json_encode([
        'error'  => $result['error'],
        'failed' => $result['failed'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok'   => true,
    'data' => [
        'fetched'      => $result['fetched'],
        'migrationLog' => $result['migrationLog'],
    ],
], JSON_UNESCAPED_SLASHES);
