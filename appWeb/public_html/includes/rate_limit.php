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
 * @requires PHP 8.1+ with PDO
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Check whether a request is within the rate limit for a given action and IP.
 *
 * Uses tblLoginAttempts to count recent requests. The `Username` column is
 * repurposed to store the action identifier for non-login rate checks.
 *
 * @param string $action        Identifier for the action being rate limited
 *                               (e.g., 'auth_login', 'song_request', 'register')
 * @param string $ip            Client IP address
 * @param int    $maxAttempts   Maximum allowed attempts within the window
 * @param int    $windowSeconds Time window in seconds (e.g., 900 for 15 minutes)
 * @param bool   $autoRespond   If true, sends a 429 JSON response and exits
 *                               when the rate limit is exceeded
 *
 * @return bool True if the request is allowed, false if rate limited
 */
function checkRateLimit(
    string $action,
    string $ip,
    int $maxAttempts,
    int $windowSeconds,
    bool $autoRespond = false
): bool {
    if ($ip === '') {
        return true; /* Cannot rate limit without an IP */
    }

    try {
        $db = getDb();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM tblLoginAttempts
             WHERE IpAddress = ? AND Username = ?
             AND AttemptedAt > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$ip, $action, $windowSeconds]);
        $count = (int)$stmt->fetchColumn();

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
    } catch (\PDOException $e) {
        /* If the database is unavailable, fail open to avoid blocking
         * legitimate requests. Log the error for monitoring. */
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
        $db = getDb();
        $stmt = $db->prepare(
            'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, ?)'
        );
        $stmt->execute([$ip, $action, $success ? 1 : 0]);
    } catch (\PDOException $e) {
        error_log('[iHymns] Rate limit recording failed: ' . $e->getMessage());
    }
}
