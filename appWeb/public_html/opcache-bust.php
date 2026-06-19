<?php
/**
 * opcache-bust.php — token-guarded OPcache reset for post-deploy cache busting.
 *
 * #1290 — on this shared DreamHost FPM host, OPcache can serve STALE compiled
 * bytecode across an SFTP deploy even with opcache.validate_timestamps=on: the
 * freshly-uploaded file's mtime doesn't always change in a way FPM revalidates,
 * so the deployed code does NOT run until the cache is reset. That single fault
 * produced the songs_json/sitemap "Unknown column s.SongbookName" flood (DB
 * column dropped + repo code correct, but stale bytecode still selected it) AND
 * the editor's "Invalid or missing CSRF token" (the stale API ran old CSRF code
 * that didn't match the fresh page's token). A manual reset on /manage/setup-
 * database stopped both — so the deploy workflow now curls this endpoint after
 * every SFTP mirror, making new code go live immediately.
 *
 * This file is intentionally STANDALONE — no includes, no DB, no session, no
 * app bootstrap — so nothing can interfere with (or be interfered by) the reset.
 *
 * SECURITY
 *   - Requires a shared secret, supplied via the `X-OPcache-Key` request header
 *     (preferred) or `?key=`, matched with hash_equals() against the expected
 *     key resolved from `IHYMNS_OPCACHE_KEY` (env) or `../.auth/opcache_bust_key.php`.
 *   - SAFE BY DEFAULT: if no key is configured server-side, it returns 503 and
 *     does nothing — the endpoint cannot be abused before it is set up.
 *   - RATE-LIMITED: at most one reset per 5 seconds (temp-dir sentinel), so even
 *     a leaked key cannot be used to hammer recompiles into a DoS.
 *   - The only side effect is opcache_reset() + clearstatcache(). No user input
 *     other than the key is read or echoed.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/* ---- Resolve the EXPECTED key: env first, then a .auth/ sibling file. ----
   .auth/ is deployed as a sibling of public_html/, so from this file the path
   is ../.auth/opcache_bust_key.php. That file must `return '<the-key>';`. */
$expected = (string) (getenv('IHYMNS_OPCACHE_KEY') ?: '');
if ($expected === '') {
    $keyFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'opcache_bust_key.php';
    if (is_file($keyFile)) {
        $v = require $keyFile;
        if (is_string($v)) {
            $expected = trim($v);
        }
    }
}
if ($expected === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'OPcache bust not configured.']);
    exit;
}

/* ---- Compare the PROVIDED key (header preferred, query fallback). ---- */
$provided = (string) ($_SERVER['HTTP_X_OPCACHE_KEY'] ?? ($_GET['key'] ?? ''));
if ($provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden.']);
    exit;
}

/* ---- Rate-limit: skip if a reset happened in the last 5 seconds. ---- */
$sentinel = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ihymns_opcache_bust.ts';
$now  = time();
$last = is_file($sentinel) ? (int) @file_get_contents($sentinel) : 0;
if ($now - $last < 5) {
    echo json_encode(['ok' => true, 'reset' => false, 'note' => 'skipped (a reset ran <5s ago)']);
    exit;
}
@file_put_contents($sentinel, (string) $now);

/* ---- The actual bust. ---- */
$available = function_exists('opcache_reset');
$reset     = $available ? @opcache_reset() : false;
clearstatcache(true);

/* ---- Best-effort Activity-Log entry (#1290). ----------------------------
   The bust above has ALREADY run; logging is observability only. We pull in
   the long-lived DB + logging includes (present in every live docroot —
   not the NEW-include #1250 hazard, which is specific to .sql/ migrations)
   using the same __DIR__-relative convention as sitemap.xml.php / api.php /
   index.php. The ENTIRE require + log is wrapped so a DB outage, a missing
   tblActivityLog, or any throwable can NEVER break or delay the reset
   response. We deliberately log NOTHING derived from the secret key or any
   raw request input — only the reset outcome + environment. */
$logged = false;
try {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
    logActivity(
        'ops.opcache_reset',
        'runtime',
        'opcache',
        [
            'trigger'           => 'auto-deploy',
            'reset'             => (bool) $reset,
            'opcache_available' => $available,
            'environment'       => function_exists('ihymns_environment') ? ihymns_environment() : null,
        ],
        $reset ? 'success' : 'error'
    );
    $logged = true;
} catch (\Throwable $e) {
    /* Swallow — logging must NEVER break or delay the bust, especially if the
       DB is down. logActivity() is itself best-effort, but the require_once of
       the DB layer (or getDbMysqli() inside it under MYSQLI_REPORT_STRICT) can
       still throw, so the whole block is guarded here. One error_log line lets
       an operator spot a sustained logging outage. */
    error_log('[opcache-bust] activity log failed: ' . $e->getMessage());
}

echo json_encode([
    'ok'                => true,
    'reset'             => (bool) $reset,
    'opcache_available' => $available,
    'logged'            => $logged,
]);
