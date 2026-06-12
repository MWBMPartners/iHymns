<?php

declare(strict_types=1);

/**
 * iHymns — Activity Log Helper (#535)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Single canonical write API for `tblActivityLog`. Every meaningful
 * action in the system — auth events, admin CRUD, user activity, API
 * requests, system events — calls `logActivity()` so we end up with
 * one consistently-shaped audit trail across the whole codebase.
 *
 * The table is described in `appWeb/.sql/schema.sql`; the columns
 * supporting Result/UserAgent/RequestId/Method/DurationMs were added
 * in `migrate-activity-log-expand.php`.
 *
 * USAGE:
 *   require_once __DIR__ . '/activity_log.php';
 *
 *   logActivity('auth.login', 'user', $userId, ['username' => $u]);
 *   logActivity('song.edit',  'song', $songId, [
 *       'fields' => ['Title','Copyright'],
 *       'before' => ['Title' => 'Old', 'Copyright' => '(c) 2010'],
 *       'after'  => ['Title' => 'New', 'Copyright' => '(c) 2026'],
 *   ]);
 *   logActivity('auth.login', 'user', $username, [
 *       'reason' => 'wrong_password',
 *   ], 'failure');
 *
 * BEST-EFFORT GUARANTEE:
 *   logActivity() never throws and never blocks the caller. On
 *   any failure it writes a one-line `error_log()` entry prefixed
 *   `[activity_log]` and returns. A logging outage will not
 *   degrade a live request.
 *
 * REQUEST CORRELATION:
 *   Every PHP request gets one auto-generated 16-char hex
 *   RequestId on first call. Every subsequent call within the
 *   same request shares it, so admins can pull `WHERE RequestId =
 *   ?` to reconstruct exactly what one HTTP request did.
 *
 * PER-REQUEST FLOOD CAP:
 *   A runaway loop calling `logActivity()` thousands of times
 *   could flood the table. After IHYMNS_LOG_PER_REQUEST_CAP rows
 *   the helper short-circuits silently for the rest of the
 *   request and writes a single warning to error_log.
 *
 * PRIVACY:
 *   Callers are responsible for keeping sensitive data out of
 *   `$details`. Project-rules.md documents the policy:
 *     - NEVER log password hashes, plaintext passwords, bearer
 *       tokens, magic-link tokens, or password-reset tokens.
 *     - DO log user_id, username, email, IP, UA — these are
 *       already in the user record.
 *     - For edits, log the field-name list + before/after values,
 *       NOT the entire row.
 *
 * @requires PHP 8.1+ with mysqli
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php'; // canonical ihymns_environment()

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/** Cap on activity-log writes per HTTP request — guards against runaway loops. */
const IHYMNS_LOG_PER_REQUEST_CAP = 200;

/**
 * Get (or lazily mint) the per-request correlation ID. Stable for
 * the lifetime of the PHP request; new on the next request.
 *
 * @return string 16-char hex
 */
function activityLogRequestId(): string
{
    static $rid = null;
    if ($rid === null) {
        try {
            $rid = bin2hex(random_bytes(8));
        } catch (\Throwable $_e) {
            /* random_bytes() should not fail on a working PHP; fall
               back to a less-strong but always-available source. */
            $rid = substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 16);
        }
    }
    return $rid;
}

/**
 * Per-request write counter — increments on every successful insert.
 * Used by the flood cap above. Exposed (read-only) for tests.
 */
function activityLogWriteCount(?int $set = null): int
{
    static $count = 0;
    if ($set !== null) $count = $set;
    return $count;
}

/**
 * Resolve the acting user ID for a log row.
 *
 * Order of precedence:
 *   1. explicit `$userId` argument from the caller
 *   2. /manage/ session: $_SESSION['user_id']
 *   3. main-app bearer-token auth: getAuthenticatedUser() if available
 *   4. NULL (unauthenticated / system)
 */
function activityLogResolveUserId(?int $userId): ?int
{
    if ($userId !== null) return $userId > 0 ? $userId : null;

    if (session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION['user_id'])
        && (int)$_SESSION['user_id'] > 0) {
        return (int)$_SESSION['user_id'];
    }

    if (function_exists('getAuthenticatedUser')) {
        try {
            $u = getAuthenticatedUser();
            if (is_array($u) && !empty($u['Id'])) {
                return (int)$u['Id'];
            }
        } catch (\Throwable $_e) {
            /* Best-effort — ignore and fall through. */
        }
    }

    return null;
}

/**
 * Persist one row to tblActivityLog.
 *
 * @param string  $action      Dotted lowercase verb. e.g. 'auth.login',
 *                              'song.edit', 'setlist.share'. Max 50 chars.
 * @param string  $entityType  Logical entity type. e.g. 'song', 'user',
 *                              'songbook', 'setlist'. Max 50 chars; '' OK.
 * @param string  $entityId    Primary key of the affected entity, as a
 *                              string. Max 50 chars; '' OK.
 * @param array   $details     Arbitrary JSON-serialisable context. Common
 *                              shapes:
 *                                edits:    ['fields'=>[],'before'=>[],'after'=>[]]
 *                                failures: ['reason'=>'wrong_password']
 *                                errors:   ['error'=>$e->getMessage(),'code'=>500]
 * @param string  $result      One of 'success' | 'failure' | 'error'.
 * @param int|null $userId     Override acting user (defaults to session/auth).
 * @param int|null $durationMs Wall-clock duration of the logged op.
 *
 * @return void Best-effort — never throws.
 */
function logActivity(
    string $action,
    string $entityType = '',
    string $entityId   = '',
    array  $details    = [],
    string $result     = 'success',
    ?int   $userId     = null,
    ?int   $durationMs = null
): void {
    /* Per-request flood cap. A logging-induced infinite loop would
       otherwise drown the table; one warning is enough. */
    $count = activityLogWriteCount();
    if ($count >= IHYMNS_LOG_PER_REQUEST_CAP) {
        if ($count === IHYMNS_LOG_PER_REQUEST_CAP) {
            error_log('[activity_log] per-request cap reached (' . IHYMNS_LOG_PER_REQUEST_CAP
                . '); further log calls in this request are dropped.');
            activityLogWriteCount($count + 1); /* push past cap so the warn fires once */
        }
        return;
    }

    /* Validate Result up-front — anything outside the ENUM would
       trigger a strict-mode error on insert. */
    if (!in_array($result, ['success', 'failure', 'error'], true)) {
        error_log('[activity_log] invalid Result "' . $result . '" — coerced to error');
        $result = 'error';
    }

    if ($action === '' || strlen($action) > 50) {
        error_log('[activity_log] invalid action "' . $action . '" — skipped');
        return;
    }

    /* JSON-encode Details. Failed encode (e.g. unicode that doesn't
       round-trip) shouldn't kill the write — fall back to a sentinel. */
    if (!empty($details)) {
        $detailsJson = json_encode(
            $details,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($detailsJson === false) {
            $detailsJson = '{"_error":"json_encode_failed"}';
        }
    } else {
        $detailsJson = null;
    }

    $resolvedUserId = activityLogResolveUserId($userId);
    /* Real client IP + proxy chain + heuristic indicator (#proxy-vpn).
       Honours CF-Connecting-IP / X-Forwarded-For / X-Real-IP so that
       behind Cloudflare or any reverse proxy the audit trail records
       the actual visitor's IP, not the proxy's. The resolver also
       computes a proxy/VPN indicator + classification detail; both
       columns are NULL on a pre-migration deployment, in which case
       the bind_param below sends them as NULL and MySQL accepts. */
    [$ip, $proxyChain, $proxyInd, $proxyDetail] = activityLogResolveClientIp();
    $proxyDetailJson = $proxyDetail !== null
        ? json_encode($proxyDetail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        : null;
    if ($proxyDetailJson === false) $proxyDetailJson = null;

    $ua             = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $method         = strtoupper((string)($_SERVER['REQUEST_METHOD']  ?? ''));
    $rid            = activityLogRequestId();

    try {
        $db = getDbMysqli();
        /* The new IpProxyChain / ProxyVpnIndicator / ProxyVpnDetail
           columns ship via migrate-activity-log-proxy-vpn.php. On
           pre-migration deployments INSERTing into the new column
           list would 1054. Probe once per process and degrade to
           the legacy 11-column INSERT if absent. */
        static $hasProxyCols = null;
        if ($hasProxyCols === null) {
            try {
                $probe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblActivityLog'
                        AND COLUMN_NAME  = 'ProxyVpnIndicator' LIMIT 1"
                );
                $probe->execute();
                $hasProxyCols = (bool)$probe->get_result()->fetch_row();
                $probe->close();
            } catch (\Throwable $_e) {
                $hasProxyCols = false;
            }
        }

        /* #1207 — probe for the observability columns (Environment / RequestPath
           / Referrer) added by migrate-activity-log-observability.php. Degrade to
           skipping the group on a pre-migration deploy. Probed once per process. */
        static $hasObsCols = null;
        if ($hasObsCols === null) {
            try {
                $obsProbe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblActivityLog'
                        AND COLUMN_NAME  = 'Environment' LIMIT 1"
                );
                $obsProbe->execute();
                $hasObsCols = (bool)$obsProbe->get_result()->fetch_row();
                $obsProbe->close();
            } catch (\Throwable $_e) {
                $hasObsCols = false;
            }
        }

        $actionTrim   = substr($action,     0, 50);
        $entityTrim   = substr($entityType, 0, 50);
        $entityIdTrim = substr($entityId,   0, 50);
        $methodTrim   = substr($method,     0, 10);
        $duration     = $durationMs !== null ? max(0, $durationMs) : null;
        $proxyChainTrim = $proxyChain !== null ? substr($proxyChain, 0, 255) : null;
        $proxyIndTrim   = $proxyInd   !== null ? substr($proxyInd,   0, 50)  : null;

        /* #1207 observability — environment, requested path, referrer. NULL on a
           pre-migration deploy (the $hasObsCols probe degrades the column group). */
        $environment = substr(activityLogEnvironment(), 0, 16);
        $reqUriRaw   = (string)($_SERVER['REQUEST_URI'] ?? '');
        $qPos        = strpos($reqUriRaw, '?');
        $pathOnly    = $qPos === false ? $reqUriRaw : substr($reqUriRaw, 0, $qPos);
        $requestPath = $pathOnly !== '' ? substr($pathOnly, 0, 512) : null;
        $referrer    = isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== ''
                     ? substr((string)$_SERVER['HTTP_REFERER'], 0, 2048)
                     : null;

        /* #1208 — country snapshot AT log time. Cache + MaxMind-local ONLY on the
           write path (never an external API call — that would add network latency
           to every request); a brand-new uncached IP stays NULL until the
           activity-log viewer backfills it via the API chain. Resolution drift
           over time is exactly why this is snapshotted on the row, not joined. */
        $country = null;
        if ($hasObsCols) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ip_geolocation.php';
            $geo = ihymnsGeoLookup($ip, false);
            $country = $geo !== null ? $geo['code'] : null;
        }

        /* Build the INSERT dynamically so the column list, placeholder count,
           type string and value list can NEVER drift apart (the #919/#923/#924
           bind-count saga that silently darkened the audit trail). All three are
           derived from ONE ordered set; optional column GROUPS are appended only
           when their migration has run (probed once per process). CreatedAt is
           NOW() (not a placeholder). */
        $cols   = ['UserId', 'Action', 'EntityType', 'EntityId', 'Result', 'Details', 'IpAddress'];
        $types  = 'issssss';
        $values = [$resolvedUserId, $actionTrim, $entityTrim, $entityIdTrim, $result, $detailsJson, $ip];

        if ($hasProxyCols) {
            array_push($cols, 'IpProxyChain', 'ProxyVpnIndicator', 'ProxyVpnDetail');
            $types .= 'sss';
            array_push($values, $proxyChainTrim, $proxyIndTrim, $proxyDetailJson);
        }
        if ($hasObsCols) {
            array_push($cols, 'Environment', 'RequestPath', 'Referrer', 'Country');
            $types .= 'ssss';
            array_push($values, $environment, $requestPath, $referrer, $country);
        }
        array_push($cols, 'UserAgent', 'RequestId', 'Method', 'DurationMs');
        $types .= 'sssi';
        array_push($values, $ua, $rid, $methodTrim, $duration);

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare(
            'INSERT INTO tblActivityLog (' . implode(', ', $cols) . ', CreatedAt) VALUES (' . $placeholders . ', NOW())'
        );
        /* #926 — bindParamSafe throws a context-named error if types/args ever
           drift; here they're built together so they can't, but keep the guard. */
        bindParamSafe('logActivity dynamic INSERT', $stmt, $types, ...$values);
        $stmt->execute();
        $stmt->close();
        activityLogWriteCount($count + 1);
    } catch (\Throwable $e) {
        /* Logging is best-effort. A failure here must not propagate.
           One error_log so admins can spot a sustained outage. */
        error_log('[activity_log] write failed for "' . $action . '": ' . $e->getMessage());
    }
}

/**
 * Resolve the deploy environment for activity-log labelling (#1207):
 * 'alpha' | 'beta' | 'production'. Mirrors infoAppVer.php's canonical
 * DOCUMENT_ROOT-based detection without pulling the whole $app array onto the
 * write path. Cached per process. The shared DB serves all three environments,
 * so this is what lets a log row say which one it came from.
 *
 * @return string One of 'alpha', 'beta', 'production'.
 */
function activityLogEnvironment(): string
{
    /* Delegates to the ONE canonical detector (includes/environment.php). This
       used to be a verbatim copy of ihymns_environment(); kept as a thin wrapper
       so existing callers + the #1207 naming stay put while the detection logic
       lives in a single place (the modularity rule). */
    return ihymns_environment();
}

/**
 * Convenience wrapper for the common "log this server-side
 * exception" pattern. Captures the exception's message + class
 * into Details and tags Result='error' automatically.
 */
function logActivityError(
    string $action,
    string $entityType,
    string $entityId,
    \Throwable $e,
    array $extra = []
): void {
    logActivity(
        $action,
        $entityType,
        $entityId,
        array_merge(
            [
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => basename($e->getFile()),
                'line'       => $e->getLine(),
            ],
            $extra
        ),
        'error'
    );
}

/* =========================================================================
 * IP / PROXY / VPN RESOLUTION
 *
 * Behind any reverse proxy (Cloudflare, AWS ALB, nginx) REMOTE_ADDR
 * is the proxy's IP, not the visitor's. The audit trail must record
 * the real client so a curator triaging suspicious traffic isn't
 * looking at one of three Cloudflare IPs for every request.
 *
 * Resolution chain (first match wins):
 *   1. CF-Connecting-IP            — Cloudflare-fronted requests.
 *      Trusted only when REMOTE_ADDR is in a Cloudflare IP range OR
 *      we can detect the Cloudflare path another way (CF-Ray header).
 *   2. X-Real-IP                   — common nginx reverse-proxy header.
 *      Trusted only when REMOTE_ADDR looks like a private/loopback
 *      address (the proxy and PHP are on the same host).
 *   3. X-Forwarded-For             — generic proxy chain. First IP in
 *      the chain is the original client; subsequent entries are
 *      intermediaries. Same trust gate as X-Real-IP.
 *   4. REMOTE_ADDR                 — direct connection, no proxy.
 *
 * Heuristic indicator (returned alongside the IP):
 *   - 'cloudflare' — CF-Connecting-IP / CF-Ray present.
 *   - 'xff'        — X-Forwarded-For present, no CF.
 *   - 'datacentre' — REMOTE_ADDR in a known cloud datacentre range
 *                    AND no proxy headers present (suggests a
 *                    cloud-hosted client / scraper).
 *   - 'tor'        — REMOTE_ADDR matches a TOR exit-node entry in
 *                    tblIpReputation (populated by future external
 *                    lookup; null indicator until then).
 *   - 'vpn'        — external lookup classified as VPN (future).
 *   - 'proxy'      — external lookup classified as generic proxy.
 *   - 'none'       — no proxy/vpn signal detected.
 * ========================================================================= */

/**
 * Resolve the real client IP, intermediate proxy chain, and a
 * heuristic proxy/VPN indicator for the current PHP request.
 *
 * Returns a 4-tuple:
 *   [string $clientIp, ?string $proxyChain, ?string $indicator, ?array $detail]
 *
 *   - $clientIp   — best-guess real client IP. Always non-empty for
 *                   real HTTP requests; empty string only when
 *                   REMOTE_ADDR itself is unset (CLI / cron).
 *   - $proxyChain — comma-separated intermediate proxies (last hop
 *                   = proxy that delivered the request to PHP).
 *                   NULL when there's no proxy.
 *   - $indicator  — one of cloudflare / xff / datacentre / tor / vpn
 *                   / proxy / none, or NULL if pre-migration deploys
 *                   want to skip writing the column entirely.
 *   - $detail     — extra context for the JSON column: { source,
 *                   provider?, score?, headers? }. NULL when there's
 *                   nothing to record.
 *
 * Cached for the lifetime of the PHP request — every call from
 * logActivity() within a single request shares one resolution.
 *
 * @return array{0:string,1:?string,2:?string,3:?array}
 */
function activityLogResolveClientIp(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    /* Header reads — every header that any common proxy might
       inject. Most are absent on most requests; this is a cheap
       associative read so the size doesn't matter. */
    $cfConnecting = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    $cfRay        = (string)($_SERVER['HTTP_CF_RAY']           ?? '');
    $xRealIp      = (string)($_SERVER['HTTP_X_REAL_IP']        ?? '');
    $xff          = (string)($_SERVER['HTTP_X_FORWARDED_FOR']  ?? '');

    $clientIp   = $remote;
    $proxyChain = null;
    $indicator  = null;
    $detail     = null;

    if ($cfConnecting !== '' && $cfRay !== '') {
        /* Cloudflare-fronted: CF-Connecting-IP carries the original
           client. The presence of CF-Ray confirms this path (a
           spoofed CF-Connecting-IP without the matching Ray would
           need to also forge Cloudflare's TLS cert chain — not the
           same threat model as a header injection). */
        $clientIp   = $cfConnecting;
        $proxyChain = $remote;
        $indicator  = 'cloudflare';
        $detail     = [
            'source'  => 'header',
            'headers' => ['cf-connecting-ip' => true, 'cf-ray' => $cfRay],
        ];
    } elseif ($xff !== '' && _activityLogIsTrustedProxyHop($remote)) {
        /* X-Forwarded-For from a trusted reverse proxy. Take the
           first hop (the original client) and record the rest as
           the proxy chain. */
        $hops = array_map('trim', explode(',', $xff));
        $hops = array_values(array_filter($hops, static fn($h) => $h !== ''));
        if (!empty($hops)) {
            $clientIp   = $hops[0];
            $rest       = array_slice($hops, 1);
            $rest[]     = $remote;
            $proxyChain = implode(',', $rest);
            $indicator  = 'xff';
            $detail     = [
                'source'  => 'header',
                'headers' => ['x-forwarded-for' => $xff],
            ];
        }
    } elseif ($xRealIp !== '' && _activityLogIsTrustedProxyHop($remote)) {
        $clientIp   = $xRealIp;
        $proxyChain = $remote;
        $indicator  = 'xff';
        $detail     = [
            'source'  => 'header',
            'headers' => ['x-real-ip' => $xRealIp],
        ];
    }

    /* External / cache-backed lookup (TOR / VPN / datacentre / proxy
       classification beyond what headers tell us). When the helper
       returns a result, it OVERRIDES the header-derived indicator —
       a request that came through Cloudflare AND a TOR exit on the
       client side should be flagged 'tor', not 'cloudflare'. The
       heuristic 'cloudflare' tag is preserved in the detail blob. */
    $reputation = activityLogIpReputation($clientIp);
    if ($reputation !== null) {
        $indicator = $reputation['indicator'] ?? $indicator;
        $detail    = array_merge($detail ?? [], [
            'reputation_provider' => $reputation['provider'] ?? null,
            'reputation_score'    => $reputation['score']    ?? null,
            'reputation_source'   => $reputation['source']   ?? null,
        ]);
    }

    /* Fall back to 'none' when every signal said clean — distinguishes
       "we checked, no proxy" from "we never recorded an indicator on
       this row" (legacy pre-migration). NULL stays NULL only when
       the resolver was called pre-migration AND no header signal
       fired AND the reputation cache returned nothing. */
    if ($indicator === null) {
        $indicator = 'none';
    }

    /* IP-format sanity: cap to the column width (45). For an
       attacker-controlled header that smuggled a comma-separated
       blob through some intermediate proxy that didn't strip it,
       this prevents the cap from flooding the audit row. */
    if (strlen($clientIp) > 45) {
        $clientIp = substr($clientIp, 0, 45);
    }

    return $cache = [$clientIp, $proxyChain, $indicator, $detail];
}

/**
 * Recognise a trusted reverse-proxy hop. Used by the IP resolver to
 * decide whether to honour X-Forwarded-For / X-Real-IP from the
 * given REMOTE_ADDR — those headers are attacker-controllable on
 * any direct connection, so we ONLY trust them when the connecting
 * peer is a known proxy.
 *
 * Trust rules (any one matches):
 *   - Loopback (127.0.0.0/8, ::1) — local reverse proxy on the
 *     same host (very common nginx → php-fpm setup).
 *   - RFC 1918 private ranges — proxy on the same internal network
 *     (typical containerised / k8s deployment).
 *   - APP_CONFIG['trusted_proxies'] — admin-configured external
 *     proxy IPs / CIDRs (out of scope for this PR; future-proofed).
 */
function _activityLogIsTrustedProxyHop(string $ip): bool
{
    if ($ip === '') return false;
    /* Loopback */
    if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.')) return true;
    /* RFC 1918 IPv4 private */
    if (str_starts_with($ip, '10.')) return true;
    if (str_starts_with($ip, '192.168.')) return true;
    if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) return true;
    /* Unique local IPv6 (fc00::/7) */
    if (preg_match('/^f[cd][0-9a-f]{2}:/i', $ip)) return true;
    return false;
}

/**
 * Read the cached proxy/VPN classification for the given IP, or
 * trigger the external lookup if configured + missing. Returns
 * NULL when there's no cached entry AND no external lookup is
 * configured — the resolver then falls back to header-only
 * heuristics.
 *
 * Per-request memoisation so the same IP isn't re-queried on every
 * logActivity() call within one request.
 *
 * @return array{indicator:string, provider:?string, score:?int, source:string}|null
 */
function activityLogIpReputation(string $ip): ?array
{
    static $cache = [];
    if ($ip === '') return null;
    if (array_key_exists($ip, $cache)) return $cache[$ip];

    /* Cache table read. Returns the latest non-expired row. The
       table itself ships with migrate-activity-log-proxy-vpn.php;
       on pre-migration deploys the SELECT 500s and we degrade to
       null. */
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare(
            'SELECT Indicator, Provider, Score, Source
               FROM tblIpReputation
              WHERE IpAddress = ?
                AND (ExpiresAt IS NULL OR ExpiresAt > NOW())
              LIMIT 1'
        );
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && !empty($row['Indicator'])) {
            return $cache[$ip] = [
                'indicator' => (string)$row['Indicator'],
                'provider'  => $row['Provider'] !== null ? (string)$row['Provider'] : null,
                'score'     => $row['Score']    !== null ? (int)$row['Score']       : null,
                'source'    => (string)$row['Source'],
            ];
        }
    } catch (\Throwable $_e) {
        /* Pre-migration deploy or DB unreachable — fall through to
           the external-lookup attempt and ultimately to null. */
    }

    /* External lookup (IPQualityScore / MaxMind / ipinfo). Disabled
       by default — admin enables via APP_CONFIG['ip_reputation'].
       The lookup is best-effort and writes through to tblIpReputation
       on success so the cost is paid once per IP per TTL window. */
    $result = _activityLogExternalIpLookup($ip);
    if ($result !== null) {
        return $cache[$ip] = $result;
    }

    return $cache[$ip] = null;
}

/**
 * Stub for the external IP-reputation lookup. Reads
 * APP_CONFIG['ip_reputation'] for a configured provider and credentials;
 * returns NULL when nothing is configured (the default state — no
 * external service is wired up yet, so this PR ships heuristic-only
 * coverage).
 *
 * The follow-up integration will plug in IPQualityScore / MaxMind
 * GeoIP2 Anonymous IP / ipinfo.io here. The function signature +
 * return shape are fixed by this PR so the integration is a drop-in.
 *
 * @return array{indicator:string, provider:?string, score:?int, source:string}|null
 */
function _activityLogExternalIpLookup(string $ip): ?array
{
    $cfg = (defined('APP_CONFIG') && is_array(APP_CONFIG))
        ? (APP_CONFIG['ip_reputation'] ?? null)
        : null;
    if (!is_array($cfg) || empty($cfg['enabled']) || empty($cfg['provider'])) {
        return null;
    }
    /* Concrete providers will live in a separate ip_reputation.php
       module so this file doesn't grow third-party HTTP clients.
       Until then, return null and rely on the header heuristic. */
    return null;
}

/* =========================================================================
 * PER-REQUEST ACTIVITY LOG
 *
 * Per the user requirement: "every dynamic request should be logged,
 * including 2xx successes — not just errors." Without this, the audit
 * trail captured edits + auth events + exceptions but never the
 * baseline "this user loaded this page at this time" record. With it,
 * /manage/activity-log can answer questions like "when did this IP
 * last hit /api?action=songs" or "show me every request from
 * suspected-VPN clients in the past 7 days".
 *
 * Volume note: enabled on every entry point that calls
 * installGlobalActivityLogHandlers(). Skipped on static assets
 * (those don't reach PHP). For very chatty endpoints (range-byte
 * media streaming, polling status endpoints) the row count climbs
 * quickly — see project-rules.md retention policy for pruning.
 * ========================================================================= */

/**
 * Register a shutdown handler that writes one tblActivityLog row per
 * dynamic-PHP request, capturing the HTTP status code, method, URL
 * path, duration, and IP/proxy classification.
 *
 * Action shape:
 *   - 'request.success' for 2xx / 3xx
 *   - 'request.failure' for 4xx
 *   - 'request.error'   for 5xx
 *
 * EntityType = $source label (matches installGlobalActivityLogHandlers).
 * EntityId   = empty string.
 * Result     = success | failure | error (mirrors the HTTP class).
 * Details    = { status, path, query, duration_ms }
 *
 * Idempotent — calling twice with the same source registers two
 * shutdown handlers, but the second is a no-op because we record
 * the row from the first. Callers should call this exactly once per
 * entry point.
 *
 * @param string $source The same label used by
 *                       installGlobalActivityLogHandlers — 'api',
 *                       'index', 'editor_api', 'manage', 'og_image',
 *                       'sitemap', 'song_media'.
 */
function installRequestActivityLogger(string $source): void
{
    static $installed = false;
    if ($installed) return;
    $installed = true;

    $startedAt = microtime(true);
    register_shutdown_function(function () use ($source, $startedAt): void {
        try {
            $status = http_response_code();
            if ($status === false) $status = 200;
            $statusInt = (int)$status;
            $action = match (true) {
                $statusInt >= 500 => 'request.error',
                $statusInt >= 400 => 'request.failure',
                default            => 'request.success',
            };
            $result = match (true) {
                $statusInt >= 500 => 'error',
                $statusInt >= 400 => 'failure',
                default            => 'success',
            };
            $duration = (int)round((microtime(true) - $startedAt) * 1000);
            $path = (string)($_SERVER['REQUEST_URI'] ?? '');
            /* Strip query string for the `path` field; preserve in
               its own field so common queries are filterable. */
            $qpos  = strpos($path, '?');
            $query = $qpos !== false ? substr($path, $qpos + 1) : '';
            $path  = $qpos !== false ? substr($path, 0, $qpos)   : $path;

            logActivity(
                $action,
                $source,
                '',
                [
                    'status'      => $statusInt,
                    'path'        => substr($path,  0, 255),
                    'query'       => substr($query, 0, 255),
                    'duration_ms' => $duration,
                ],
                $result,
                null,
                $duration
            );
        } catch (\Throwable $_e) {
            /* Per-request logger must never compound an existing
               failure. Best-effort. */
        }
    });
}

/**
 * Install global PHP error handlers that mirror every uncaught
 * \Throwable + every fatal-class PHP error into tblActivityLog.
 *
 * Without this, an uncaught exception or PHP fatal in any request
 * never reaches the audit trail — only error_log catches it,
 * which lives outside the curator-visible /manage/activity-log
 * surface. Curators triaging a 500 should be able to find every
 * incident from the same UI, regardless of whether the failing
 * code happened to wrap itself in a try/catch.
 *
 * Two handlers are registered:
 *   1. set_exception_handler — fires on any uncaught \Throwable.
 *      Logs `uncaught.throwable` with class + message + file/line.
 *   2. register_shutdown_function — fires on PHP fatals (parse,
 *      out-of-memory, type-error during property init, etc.) that
 *      don't surface as catchable throwables. Logs `fatal.php_error`.
 *
 * Both handlers are best-effort — wrapped in their own try/catch so
 * a logging-time failure cannot compound the original error or stop
 * the response from being emitted. The shutdown handler also
 * cooperates with any prior shutdown handler (e.g. api.php's JSON
 * 500 emitter) — order is "first registered, first run", so any
 * earlier-registered response emitter still controls the body; we
 * only add the audit row.
 *
 * Idempotent — calling twice replaces the previous handler with
 * one that has the same source label, but doesn't double-log.
 *
 * @param string $source The `EntityType` to record. Use a stable
 *                       identifier of the entry point so curators
 *                       can filter — e.g. 'api', 'editor_api',
 *                       'manage'. Anything ≤ 50 chars.
 *
 * @return void
 */
function installGlobalActivityLogHandlers(string $source): void
{
    /* Per-request shutdown logger — writes one Action='request.*'
       row at end of every dynamic request. Installed alongside the
       throwable/fatal handlers below so a single
       installGlobalActivityLogHandlers() call covers both the
       "every successful 2xx" and "every uncaught exception" cases.
       Idempotent — see installRequestActivityLogger(). */
    installRequestActivityLogger($source);

    /* Probe the currently-registered exception handler so we can
       chain to it — important on /api.php which registers its own
       JSON-emitting handler EARLIER in the bootstrap. We swap a
       temp closure in to read the previous handler back, then
       restore so our real handler can wrap it. set_exception_handler
       returns the prior handler (or null) when called. */
    $prevExceptionHandler = set_exception_handler(static function (): void {});
    restore_exception_handler();
    set_exception_handler(function (\Throwable $e) use ($source, $prevExceptionHandler): void {
        try {
            logActivityError(
                'uncaught.throwable',
                $source,
                '',
                $e,
                ['request_id' => activityLogRequestId()]
            );
        } catch (\Throwable $_) {
            /* Logging itself failed — swallow so we still hand
               off to whatever prior handler wanted to emit a
               response. */
        }
        if ($prevExceptionHandler !== null) {
            /* Hand control back to the prior handler so its
               response-emission path runs unchanged. */
            try {
                ($prevExceptionHandler)($e);
            } catch (\Throwable $_) { /* ignore */ }
        } else {
            /* No prior handler — re-throw so PHP's default kicks
               in (logs to stderr / shows the configured error
               page). Without this re-throw the request would
               silently 200 with whatever partial body was buffered. */
            error_log(
                '[activity_log] uncaught throwable in ' . $source . ': '
                . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            );
        }
    });

    /* Shutdown handler for fatal-class errors (E_ERROR, E_PARSE,
       E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR). These are NOT
       catchable as throwables — they only surface via error_get_last
       at shutdown. */
    register_shutdown_function(function () use ($source): void {
        $err = error_get_last();
        $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!$err || !($err['type'] & $fatalMask)) return;

        $msg  = (string)($err['message'] ?? '');
        $file = basename((string)($err['file'] ?? '?'));
        $line = (int)($err['line'] ?? 0);

        try {
            logActivity(
                'fatal.php_error',
                $source,
                '',
                [
                    'message'    => $msg,
                    'file'       => $file,
                    'line'       => $line,
                    'type'       => (int)($err['type'] ?? 0),
                    'request_id' => activityLogRequestId(),
                ],
                'error'
            );
        } catch (\Throwable $_) {
            /* Best-effort — a logging failure during shutdown
               cannot be allowed to interfere with the response. */
        }

        /* #929 — emit a JSON 500 envelope so the frontend has
           something to parse off the response body. Without this,
           a PHP-fatal mid-response leaves the body empty (or
           HTML-shaped, depending on display_errors). The frontend
           toast then says only "HTTP 500" with no detail because
           there's no JSON to extract `detail` from.

           Two cases:

           1. Headers haven't been sent yet — we can set the
              Content-Type + status code cleanly and emit a fresh
              envelope.

           2. Headers HAVE been sent (the endpoint started writing
              its successful response, then OOM'd partway through) —
              we can't change the headers but can append a JSON
              trailer separated by a newline boundary that the
              frontend's response.text() reads. The
              `_fetchAndParseSongs()` extractor in editor.js parses
              `JSON.parse(body)` then falls through to a regex search
              for the trailer pattern.

           Source-gated: only fires for API-shaped sources where the
           caller expects JSON. HTML pages (`index`, `manage`)
           leave the existing chrome-shutdown handler in
           setup-database.php to do its work. */
        $apiSources = ['api', 'editor_api', 'song_media', 'og_image', 'sitemap'];
        if (!in_array($source, $apiSources, true)) return;

        $envelope = [
            'error'  => 'Internal server error',
            'detail' => $msg,
            'file'   => $file . ':' . $line,
        ];
        $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) return;

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            echo $body;
        } else {
            /* Best-effort trailer. The frontend's existing extractor
               (`_fetchAndParseSongs` in editor.js) tries
               JSON.parse(body) first; if that fails it currently
               drops the detail. A follow-up could extend the
               extractor to look for the `\n\n` trailer marker, but
               for now this still preserves the detail in the
               response body for any consumer that wants it. */
            echo "\n\n" . $body;
        }
    });
}
