<?php

declare(strict_types=1);

/**
 * iHymns — Rate Limit Middleware Helper
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides a reusable rate-limiting function that checks tblLoginAttempts
 * (or any time-based counter) to throttle requests per IP and action.
 *
 * USAGE:
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'rate_limit.php';
 *
 *   // Returns true if allowed, false if rate limited
 *   $allowed = checkRateLimit('auth_login', $clientIp, 10, 900);
 *
 *   // Auto-respond with 429 if rate limited (exits on failure)
 *   checkRateLimit('auth_login', $clientIp, 10, 900, true);
 *
 * @requires PHP 8.1+ with mysqli
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Resolve the bucket key a rate-limit check/record pair must share.
 *
 * ELI5: "which counter does this request belong in? — the signed-in user's
 * own counter if we know who they are, otherwise their IP address."
 *
 * WHY THIS IS A SHARED FUNCTION AND NOT AN INLINE EXPRESSION (#1636).
 * checkRateLimit() derives this key internally from ($ip, $userId), but
 * recordRateLimitHit() takes an ALREADY-RESOLVED key. Every call site
 * therefore had to re-derive the same string by hand, e.g.
 *
 *     checkRateLimit('live_follow_create', $liveIp, 20, 3600, true, $userId);
 *     recordRateLimitHit('live_follow_create', 'user:' . $userId);
 *
 * Two hand-written copies of one rule is exactly the shape that rots: get the
 * derivation subtly wrong (say `'user:' . $userId` where $userId can be NULL,
 * as it can on service_broadcast's delegated control-token path) and the
 * WRITER files rows under `user:` while the READER counts rows under the IP —
 * a counter that silently never trips. That is the same class of failure as
 * #1636 itself, just one layer down, so the derivation now lives in ONE place
 * that both halves of the contract call.
 *
 * @param string   $ip     Client IP address (the anonymous fallback key).
 * @param int|null $userId Authenticated user ID, or null/0 when anonymous.
 * @return string The bucket key — 'user:<id>' when identified, else the IP.
 */
function rateLimitKey(string $ip, ?int $userId = null): string
{
    return $userId !== null && $userId > 0 ? 'user:' . $userId : $ip;
}

/**
 * Check whether a request is within the rate limit for a given action.
 *
 * Uses tblLoginAttempts as the counter table (the `Username` column is
 * repurposed to carry the action identifier for non-login checks). When
 * an authenticated user ID is supplied, the IP-based key is replaced
 * with `user:<id>` so a single user can't side-step the limit by
 * cycling addresses, and a per-user signed-in budget can be set
 * larger than the per-IP unauthenticated one.
 *
 * TWO-PART CONTRACT (#1636). This function only ever READS the counter.
 * Nothing is capped unless a matching recordRateLimitHit() WRITES to the same
 * (action, key) bucket — a check without its paired record is a cap that can
 * never be reached, which is precisely the bug #1636 found on thirteen
 * actions. tests/php/test-rate-limit-pairing.php now fails the build on an
 * unpaired action name. Derive the shared key with rateLimitKey() rather than
 * re-deriving 'user:' . $id by hand.
 *
 * Sliding-window — counts rows whose AttemptedAt is within the last
 * `windowSeconds` seconds, so the cap glides rather than reset on a
 * fixed boundary. Fails open on DB errors (logged) so a blip in the
 * counter table doesn't lock everyone out.
 *
 * @param string   $action        Identifier for the action being rate
 *                                 limited (e.g., 'auth_login',
 *                                 'song_request', 'og_image').
 * @param string   $ip            Client IP address (empty string is a
 *                                 no-op — cannot rate limit without a
 *                                 key).
 * @param int      $maxAttempts   Maximum allowed attempts within the
 *                                 window.
 * @param int      $windowSeconds Time window in seconds (e.g., 900
 *                                 for 15 minutes).
 * @param bool     $autoRespond   If true, sends a 429 JSON response
 *                                 and exits when the limit is hit.
 * @param int|null $userId        Optional authenticated user ID. If
 *                                 supplied, keys the bucket by user
 *                                 instead of IP — pass for endpoints
 *                                 where the per-user budget is the
 *                                 thing you actually want to cap.
 *
 * @return bool True if the request is allowed, false if rate limited.
 */
function checkRateLimit(
    string $action,
    string $ip,
    int $maxAttempts,
    int $windowSeconds,
    bool $autoRespond = false,
    ?int $userId = null
): bool {
    /* Per-user buckets are keyed off `user:<id>` in the IpAddress
       column. Cannot rate limit without either a user or an IP.
       Shared with every recordRateLimitHit() caller via rateLimitKey()
       so the reader and the writer can never disagree (#1636). */
    $key = rateLimitKey($ip, $userId);
    if ($key === '') {
        return true;
    }

    try {
        $db = getDbMysqli();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM tblLoginAttempts
             WHERE IpAddress = ? AND Username = ?
             AND AttemptedAt > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->bind_param('ssi', $key, $action, $windowSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $count = (int)($row[0] ?? 0);

        if ($count >= $maxAttempts) {
            if ($autoRespond) {
                http_response_code(429);
                header('Content-Type: application/json; charset=UTF-8');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-cache, must-revalidate');
                header('Retry-After: ' . $windowSeconds);
                echo json_encode([
                    'error' => 'Too many requests. Please try again later.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        /* Fail open on DB error — better to over-serve a few requests
           than to lock everyone out of an endpoint because the
           counter table briefly went away. Logged so a sustained
           outage is visible. \Throwable catches mysqli_sql_exception
           plus any other unexpected error from get_result()/fetch_row(). */
        error_log('[iHymns] Rate limit check failed: ' . $e->getMessage());
        return true;
    }
}

/**
 * Record a rate limit hit for tracking purposes.
 *
 * Inserts a row into tblLoginAttempts with the action as the Username field.
 * This allows checkRateLimit() to count the attempt in future checks.
 *
 * The $ip argument is the ALREADY-RESOLVED bucket key, not necessarily a raw
 * address — pass rateLimitKey($ip, $userId) so it matches whatever key the
 * paired checkRateLimit() will read (#1636). Note that checkRateLimit()
 * counts rows WITHOUT regard to $success, so a caller that wants to budget
 * only failures (auth_login_acct, service_join) simply records nothing on the
 * success path; $success is an audit signal, not a filter.
 *
 * @param string $action  Action identifier (stored in Username column)
 * @param string $ip      Resolved bucket key (IP address or 'user:<id>' etc.)
 * @param bool   $success Whether the action succeeded (default: true)
 */
function recordRateLimitHit(string $action, string $ip, bool $success = true): void
{
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare(
            'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, ?)'
        );
        $successInt = $success ? 1 : 0;
        $stmt->bind_param('ssi', $ip, $action, $successInt);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[iHymns] Rate limit recording failed: ' . $e->getMessage());
    }
}
