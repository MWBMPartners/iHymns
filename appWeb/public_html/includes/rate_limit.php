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
 * Check whether a request is within the rate limit for a given action.
 *
 * Uses tblLoginAttempts as the counter table (the `Username` column is
 * repurposed to carry the action identifier for non-login checks). When
 * an authenticated user ID is supplied, the IP-based key is replaced
 * with `user:<id>` so a single user can't side-step the limit by
 * cycling addresses, and a per-user signed-in budget can be set
 * larger than the per-IP unauthenticated one.
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
       column. Cannot rate limit without either a user or an IP. */
    $key = $userId !== null && $userId > 0 ? 'user:' . $userId : $ip;
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
 * @param string $action  Action identifier (stored in Username column)
 * @param string $ip      Client IP address
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
