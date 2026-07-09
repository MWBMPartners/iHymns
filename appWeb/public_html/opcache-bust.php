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
 *   - #1466 P4 — this endpoint only ever VERIFIES the key, it never needs to hand
 *     the plaintext back to anyone (the one sender, deploy.yml, keeps its own copy
 *     from the GitHub secret). That makes it safe to store the expected value as a
 *     one-way `sha256:<hex>` hash instead of plaintext-at-rest; see the resolution
 *     comment below and `.claude/secret-encryption-strategy.md` §3 for the full
 *     rationale (this is called out there as "the ONLY verify-by-comparison secret").
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
   is ../.auth/opcache_bust_key.php. That file must `return '<the-key>';` OR,
   since #1466 P4, `return 'sha256:<hex>';` (a one-way hash of the plaintext
   key — see the comparison block below for how the two forms are told apart). */
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

/* ---- Compare the PROVIDED key (header preferred, query fallback). ----
 * ELI5: this endpoint is a bouncer checking an ID card, not a bank vault
 * handing out cash — it only ever needs to say "yes/no", never read the
 * secret back out loud. So the stored copy is allowed to be a one-way hash:
 * even someone who reads `.auth/opcache_bust_key.php` off disk can't recover
 * the real key from a hash, whereas today's plaintext-at-rest can be copied
 * and reused directly.
 *
 * WHY the `sha256:` prefix (not a length check): a raw key and a sha256 hex
 * digest are both commonly 64 hex characters, so length alone can't tell
 * them apart. A self-describing prefix removes the ambiguity and lets each
 * docroot's `.auth/opcache_bust_key.php` be migrated to the hashed form
 * independently and at any time — this branch accepts BOTH forms so a
 * mixed-fleet rollout (some docroots migrated, some not yet) never breaks.
 * The single sender, `.github/workflows/deploy.yml`'s OPcache-bust step, is
 * unaffected either way: it always sends the raw plaintext key (sourced from
 * the `OPCACHE_BUST_KEY` GitHub secret) in the `X-OPcache-Key` header — it
 * never sees or needs to know which form the server has stored.
 * Ref: #1466 P4, `.claude/secret-encryption-strategy.md` §3. */
$provided = (string) ($_SERVER['HTTP_X_OPCACHE_KEY'] ?? ($_GET['key'] ?? ''));
$ok = false;
if ($provided !== '' && $expected !== '') {
    if (strncmp($expected, 'sha256:', 7) === 0) {
        // Stored as a one-way hash: hash the provided key and compare hash-to-hash
        // (constant-time via hash_equals) — the plaintext is never reconstructed.
        $ok = hash_equals(substr($expected, 7), hash('sha256', $provided));
    } else {
        // Legacy plaintext-at-rest: compare directly (still constant-time).
        $ok = hash_equals($expected, $provided);
    }
}
if (!$ok) {
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

echo json_encode([
    'ok'                => true,
    'reset'             => (bool) $reset,
    'opcache_available' => $available,
]);
