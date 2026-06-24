<?php

declare(strict_types=1);

/**
 * read_rate_limit.php — lightweight per-requester rate limiting for the
 * heaviest PUBLIC read endpoints in api.php (#1354)
 * ============================================================================
 *
 * WHY:
 *   The public reads (song_detail / search / bulk_songs / songs_index /
 *   related_songs …) are sessionless and uncapped, so a scraper can hammer
 *   them. This adds a CHEAP, fixed-window counter — keyed PER TOKEN where a
 *   native-app session token is present (so a NAT-shared set of devices each
 *   get their own budget), else PER IP for anonymous callers — that emits a
 *   429 + Retry-After once a GENEROUS limit is exceeded. Real clients never
 *   trip it; abusive volume does.
 *
 * MIRRORS:
 *   includes/api_keys.php :: apiKeyEnforceRateLimit() (#1066 Theme B), which is
 *   the API-KEY-auth variant against tblApiKeyUsage. This is the token/IP
 *   variant against tblReadRateLimit (migrate-add-read-rate-limit.php, #1354).
 *
 * SAFETY CONTRACT (all four are load-bearing):
 *   - FAIL-OPEN: every DB touch is wrapped in try/catch and the table is
 *     existence-gated (request-cached). If the table is absent or ANY error
 *     occurs, the request is ALLOWED. A rate limiter must never take the site
 *     down (the #1228 white-screen lesson) — blunting abuse is strictly less
 *     important than serving real traffic.
 *   - STRICT-safe: mysqli runs under MYSQLI_REPORT_ERROR | _STRICT, so a query
 *     against a missing table THROWS; the existence-gate + try/catch turn that
 *     into a clean no-op rather than a 500.
 *   - LOW-OVERHEAD: at most two prepared statements per active window (an
 *     atomic upsert + a count read), and one cached INFORMATION_SCHEMA probe
 *     for the whole request. The common case ($perDay = 0) is a single window.
 *   - TIME IS SQL-SIDE: window boundaries come from DATE_FORMAT(UTC_TIMESTAMP())
 *     inside the query, NOT PHP date()/time(), so there's no per-node clock
 *     drift between the truncation and the comparison.
 *
 * @see appWeb/.sql/migrate-add-read-rate-limit.php
 * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/**
 * Memoised INFORMATION_SCHEMA table-existence probe for this module.
 *
 * ELI5: "does this table exist? remember the answer so we only ask once."
 * Why: the existence gate is what makes the whole limiter fail-open on an
 * un-migrated install; caching it keeps the limiter to ~one extra cheap query
 * per request (and zero on repeat calls within the same request). Returns
 * false on ANY error so a probe failure also degrades to "allow".
 */
function _readRateLimitTableExists(\mysqli $db): bool
{
    static $exists = null;            // request-scoped memo (one probe per request)
    if ($exists !== null) { return $exists; }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblReadRateLimit' LIMIT 1"
        );
        $st->execute();
        $exists = $st->get_result()->fetch_row() !== null;
        $st->close();
    } catch (\Throwable $_) {
        $exists = false;             // any error → treat as "absent" → fail open
    }
    return $exists;
}

/**
 * Resolve the rate-limit key for the current request.
 *
 * ELI5: "who is this? — if they sent a real-looking login token, bucket them by
 * THAT (so each device counts separately even behind one router); otherwise
 * bucket them by their IP address."
 *
 * Why per-token-first: a congregation of native apps behind one NAT egresses as
 * ONE IP — bucketing those by IP would throttle the whole room as if it were a
 * single scraper (the same NAT lesson as Service Mode rule #26's per-presence
 * limiting). A per-device session token gives each its own budget. The token is
 * accepted only in the SAME strict 64-hex shape getAuthBearerToken() (api.php)
 * uses, so a junk "Bearer foo" can't mint an arbitrary bucket label; an
 * unrecognised/absent token falls through to the IP bucket.
 *
 * NOTE: the token is NOT validated against the sessions table here — that would
 * cost a query per request and defeat the low-overhead goal. A forged 64-hex
 * token simply gets its OWN generous bucket; that's acceptable for blunting
 * casual scraping (the goal), and the per-IP backstop still covers the
 * no-/junk-token scraper. The hash means the raw token is never stored.
 *
 * @return string The bucket key: 'tok:<sha256hex>' (≤68 chars) or 'ip:<addr>'.
 */
function _readRateLimitResolveKey(): string
{
    /* Same source + shape as api.php::getAuthBearerToken(): the Authorization
       header (incl. the Apache REDIRECT_ variant), then the ihymns_auth cookie
       fallback. Strict 64-hex only — anything else is treated as "no token". */
    $header = (string)($_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '');
    $token = '';
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        $token = $m[1];
    } elseif (!empty($_COOKIE['ihymns_auth']) && preg_match('/^[a-f0-9]{64}$/i', (string)$_COOKIE['ihymns_auth'])) {
        $token = (string)$_COOKIE['ihymns_auth'];
    }

    if ($token !== '') {
        return 'tok:' . hash('sha256', $token);   // 4 + 64 = 68 chars; never stores the raw token
    }

    /* Anonymous → IP bucket. REMOTE_ADDR only (NOT X-Forwarded-For, which a
       client can forge to mint unlimited buckets). IPv6 max 39 chars → 'ip:'
       + 39 = 42 ≤ the RateKey VARCHAR(72). */
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return 'ip:' . $ip;
}

/**
 * Enforce a fixed-window rate limit on the current PUBLIC read request.
 *
 * ELI5: "count this request in a per-minute (and optionally per-day) bucket for
 * this requester+endpoint; if the bucket is over its limit, reply 429 'slow
 * down' and stop. If anything about the counting goes wrong, just let the
 * request through."
 *
 * Why a separate $scope per endpoint group: it lets song_detail (240/min) and
 * search (120/min) share ONE table with INDEPENDENT budgets — and reserves room
 * for new endpoint limits with NO further migration (rule #20).
 *
 * On a tripped limit this EMITS a 429 (Content-Type JSON, Retry-After header,
 * X-RateLimit-* hints) and `exit`s — the caller does not continue. On the allow
 * path it returns void and the caller proceeds. On ANY internal error (incl. a
 * missing table on an un-migrated install) it RETURNS — request allowed.
 *
 * @param string $scope   Endpoint group label (≤40 chars), e.g. 'song_detail'.
 * @param int    $perMin  Per-minute ceiling (>0). Requests beyond it in the same
 *                        UTC minute get 429. Choose GENEROUSLY — real clients
 *                        must never trip it.
 * @param int    $perDay  Optional per-day ceiling (0 = no daily cap; default).
 */
function enforceReadRateLimit(string $scope, int $perMin, int $perDay = 0): void
{
    /* Nothing to enforce if no positive limit was given. */
    if ($perMin <= 0 && $perDay <= 0) { return; }

    try {
        /* getDbMysqli() is the ONLY DB entry point (CLAUDE.md rule #5). If the
           DB itself is down this throws and we fail open below. */
        if (!function_exists('getDbMysqli')) { return; }
        $db = getDbMysqli();

        /* Existence-gate: un-migrated install → clean no-op (fail open). */
        if (!_readRateLimitTableExists($db)) { return; }

        $rateKey = _readRateLimitResolveKey();
        $scope   = substr($scope, 0, 40);          // honour the column width

        /* Build the active windows. WindowStart is computed SQL-side per query
           (DATE_FORMAT(UTC_TIMESTAMP(), …)) so there is no PHP-vs-SQL clock
           skew; the format string is a hardcoded constant (NOT user input), so
           interpolating it into the SQL is rule-#5-legitimate. Each entry:
           [WindowType, sqlTruncExpr, limit, retryAfterSeconds]. */
        $windows = [];
        if ($perMin > 0) {
            $windows[] = ['minute', "DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:00')", $perMin, 60];
        }
        if ($perDay > 0) {
            $windows[] = ['day', "DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d 00:00:00')", $perDay, 86400];
        }

        foreach ($windows as [$wtype, $startExpr, $limit, $retry]) {
            /* ONE atomic upsert: create the bucket at count 1, or bump an
               existing one. Race-safe via the UNIQUE (RateKey,Scope,WindowType,
               WindowStart) key — concurrent requests serialise on the row. The
               $startExpr is the hardcoded truncation constant above; every VALUE
               (RateKey/Scope/WindowType) is bound. */
            $up = $db->prepare(
                "INSERT INTO tblReadRateLimit (RateKey, Scope, WindowType, WindowStart, RequestCount)
                 VALUES (?, ?, ?, $startExpr, 1)
                 ON DUPLICATE KEY UPDATE RequestCount = RequestCount + 1"
            );
            $up->bind_param('sss', $rateKey, $scope, $wtype);
            $up->execute();
            $up->close();

            /* Read back this bucket's count (the upsert doesn't return it). */
            $cs = $db->prepare(
                "SELECT RequestCount FROM tblReadRateLimit
                  WHERE RateKey = ? AND Scope = ? AND WindowType = ? AND WindowStart = $startExpr LIMIT 1"
            );
            $cs->bind_param('sss', $rateKey, $scope, $wtype);
            $cs->execute();
            $count = (int)($cs->get_result()->fetch_assoc()['RequestCount'] ?? 0);
            $cs->close();

            if ($count > $limit) {
                /* Tripped: tell the client to back off and STOP processing.
                   Retry-After is the seconds to the next fixed window (a
                   conservative upper bound — the window may roll sooner). */
                if (!headers_sent()) {
                    http_response_code(429);
                    header('Content-Type: application/json; charset=UTF-8');
                    header('Retry-After: ' . $retry);
                    header('X-RateLimit-Limit: ' . $limit);
                    header('X-RateLimit-Window: ' . $wtype);
                    header('Cache-Control: no-store');
                }
                echo json_encode([
                    'error'       => 'Rate limit exceeded (' . $limit . ' per ' . $wtype . '). Please slow down and retry shortly.',
                    'retryAfter'  => $retry,
                ]);
                exit;
            }
        }
    } catch (\Throwable $_) {
        /* FAIL-OPEN: counting must never break the read. Swallow + allow. */
        return;
    }
}
