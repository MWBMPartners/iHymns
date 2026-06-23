<?php
/**
 * api_keys.php — machine-to-machine API key auth (#1064)
 * ======================================================
 *
 * Per-client, SHA-256-hashed, scoped, revocable keys for external services
 * (e.g. MeedyaDL #907) calling the public API without a session. The raw key
 * is generated + shown once at creation; only its hash is stored in
 * tblApiKeys. Endpoints call apiKeyAuthorize() with the scope they require.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

declare(strict_types=1);

/**
 * Generate a fresh API key. Returns:
 *   [ 'raw' => 'ihk_live_<40 hex>', 'hash' => <sha256 hex>, 'prefix' => 'ihk_live_xxxx' ]
 * Only the hash + prefix are persisted; the raw value is shown once to the
 * admin and never stored.
 */
function apiKeyGenerate(): array
{
    $secret = bin2hex(random_bytes(20));        // 40 hex chars, 160 bits
    $raw    = 'ihk_live_' . $secret;
    return [
        'raw'    => $raw,
        'hash'   => hash('sha256', $raw),
        'prefix' => substr($raw, 0, 13),         // "ihk_live_" + 4 chars — non-secret
    ];
}

/**
 * Extract a presented raw key from the request: either
 *   Authorization: Bearer <key>     or     X-API-Key: <key>
 * Returns null if neither header is present.
 */
function apiKeyFromRequest(): ?string
{
    $auth = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = (string)$v; break; }
        }
    }
    if ($auth !== '' && preg_match('/^\s*Bearer\s+(.+)$/i', $auth, $m)) {
        return trim($m[1]);
    }
    if (isset($_SERVER['HTTP_X_API_KEY']) && $_SERVER['HTTP_X_API_KEY'] !== '') {
        return trim((string)$_SERVER['HTTP_X_API_KEY']);
    }
    return null;
}

/** True if a space-separated scope string grants $required (exact or "*"). */
function apiKeyHasScope(string $scopeField, string $required): bool
{
    $scopes = preg_split('/\s+/', trim($scopeField)) ?: [];
    return in_array('*', $scopes, true) || in_array($required, $scopes, true);
}

/**
 * Look up + validate a raw key against tblApiKeys. On a match that is Active
 * and grants $requiredScope, records LastUsedAt / LastUsedIp and returns the
 * key row. Returns null otherwise (unknown / inactive / wrong scope). The
 * lookup is by hash (indexed, constant work); no raw key is ever compared in
 * SQL or logged.
 */
function apiKeyVerify(\mysqli $db, string $rawKey, string $requiredScope): ?array
{
    $rawKey = trim($rawKey);
    if ($rawKey === '') { return null; }
    $hash = hash('sha256', $rawKey);

    $stmt = $db->prepare(
        'SELECT Id, Label, Scope, Active FROM tblApiKeys WHERE KeyHash = ? LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null || (int)$row['Active'] !== 1) {
        return null;
    }
    if (!apiKeyHasScope((string)$row['Scope'], $requiredScope)) {
        return null;
    }

    /* Best-effort usage stamp — never block the request on it. */
    try {
        $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $upd = $db->prepare('UPDATE tblApiKeys SET LastUsedAt = NOW(), LastUsedIp = ? WHERE Id = ?');
        $kid = (int)$row['Id'];
        $upd->bind_param('si', $ip, $kid);
        $upd->execute();
        $upd->close();
    } catch (\Throwable $_) { /* ignore */ }

    return $row;
}

/**
 * Endpoint guard: require a valid key with $requiredScope. On failure, emits a
 * 401 JSON body and returns null (caller should `return`/`break`). On success
 * returns the key row. Sets WWW-Authenticate so clients know the scheme.
 */
function apiKeyAuthorize(\mysqli $db, string $requiredScope): ?array
{
    $raw = apiKeyFromRequest();
    if ($raw === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('WWW-Authenticate: Bearer realm="iHymns API", scope="' . $requiredScope . '"');
        echo json_encode(['error' => 'Missing API key. Send "Authorization: Bearer <key>" or "X-API-Key: <key>".']);
        return null;
    }
    $row = apiKeyVerify($db, $raw, $requiredScope);
    if ($row === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('WWW-Authenticate: Bearer realm="iHymns API", scope="' . $requiredScope . '"');
        echo json_encode(['error' => 'Invalid or unauthorized API key.']);
        return null;
    }
    return $row;
}

/* ---------------------------------------------------------------------------
 * Rate limiting + idempotency middleware (#1066 Theme B enforcement, #1343-resume).
 * The per-key limits (tblApiKeys.RateLimitPerMin/PerDay), the rolling-window
 * counters (tblApiKeyUsage) and the idempotency cache (tblApiKeyIdempotency) were
 * shipped as dormant schema; these helpers are the enforcement layer. Everything is
 * GATED on the relevant table/column existing, so an un-migrated install (the 3
 * shared docroots run un-migrated under STRICT) is a clean no-op — never a 500.
 * ------------------------------------------------------------------------- */

/** Memoised INFORMATION_SCHEMA table-existence probe (STRICT-safe gate). */
function _apiKeyTableExists(\mysqli $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) { return $cache[$table]; }
    try {
        $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $st->bind_param('s', $table);
        $st->execute();
        $ok = $st->get_result()->fetch_row() !== null;
        $st->close();
    } catch (\Throwable $_) { $ok = false; }
    return $cache[$table] = $ok;
}

/** Memoised column-existence probe. */
function _apiKeyColumnExists(\mysqli $db, string $table, string $col): bool
{
    static $cache = [];
    $k = $table . '.' . $col;
    if (array_key_exists($k, $cache)) { return $cache[$k]; }
    try {
        $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $ok = $st->get_result()->fetch_row() !== null;
        $st->close();
    } catch (\Throwable $_) { $ok = false; }
    return $cache[$k] = $ok;
}

/**
 * Enforce per-key rate limits for the current request. Returns TRUE if the request
 * is allowed (and records it against the minute/day windows); returns FALSE after
 * EMITTING a 429 with Retry-After if a limit is exceeded (caller should `break`).
 * No-op (allowed) when the usage table / limit columns are absent or the key has no
 * limit set. Fixed-window counters via an atomic upsert (race-safe).
 */
function apiKeyEnforceRateLimit(\mysqli $db, array $key, string $scope = ''): bool
{
    $keyId = (int)($key['Id'] ?? 0);
    if ($keyId <= 0) { return true; }
    if (!_apiKeyTableExists($db, 'tblApiKeyUsage')) { return true; }
    if (!_apiKeyColumnExists($db, 'tblApiKeys', 'RateLimitPerMin')) { return true; }

    $sel = $db->prepare('SELECT RateLimitPerMin, RateLimitPerDay FROM tblApiKeys WHERE Id = ? LIMIT 1');
    $sel->bind_param('i', $keyId);
    $sel->execute();
    $lim = $sel->get_result()->fetch_assoc();
    $sel->close();
    $perMin = ($lim && $lim['RateLimitPerMin'] !== null) ? (int)$lim['RateLimitPerMin'] : null;
    $perDay = ($lim && $lim['RateLimitPerDay'] !== null) ? (int)$lim['RateLimitPerDay'] : null;

    $windows = [];
    if ($perMin !== null && $perMin > 0) { $windows[] = ['minute', gmdate('Y-m-d H:i:00'), $perMin]; }
    if ($perDay !== null && $perDay > 0) { $windows[] = ['day',    gmdate('Y-m-d 00:00:00'), $perDay]; }
    if ($windows === []) { return true; } /* no limit configured */

    foreach ($windows as [$wtype, $wstart, $limit]) {
        /* Atomic increment of the fixed window (race-safe via the UNIQUE key). */
        $up = $db->prepare(
            'INSERT INTO tblApiKeyUsage (ApiKeyId, Scope, WindowType, WindowStart, RequestCount)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE RequestCount = RequestCount + 1'
        );
        $up->bind_param('isss', $keyId, $scope, $wtype, $wstart);
        $up->execute();
        $up->close();

        $cs = $db->prepare('SELECT RequestCount FROM tblApiKeyUsage WHERE ApiKeyId = ? AND Scope = ? AND WindowType = ? AND WindowStart = ? LIMIT 1');
        $cs->bind_param('isss', $keyId, $scope, $wtype, $wstart);
        $cs->execute();
        $count = (int)($cs->get_result()->fetch_assoc()['RequestCount'] ?? 0);
        $cs->close();

        if ($count > $limit) {
            $retry = ($wtype === 'minute') ? 60 : 3600;
            http_response_code(429);
            header('Content-Type: application/json; charset=UTF-8');
            header('Retry-After: ' . $retry);
            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Window: ' . $wtype);
            echo json_encode(['error' => 'Rate limit exceeded (' . $limit . ' per ' . $wtype . '). Retry later.']);
            return false;
        }
    }
    return true;
}

/** The client's Idempotency-Key header (≤255 chars), or null if absent/oversized. */
function apiKeyIdempotencyKey(): ?string
{
    $k = isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? trim((string)$_SERVER['HTTP_IDEMPOTENCY_KEY']) : '';
    return ($k !== '' && strlen($k) <= 255) ? $k : null;
}

/**
 * If a non-expired cached response exists for this (key, Idempotency-Key, body),
 * return ['status'=>int, 'data'=>string(JSON)] for replay; else null. The body hash
 * is part of the match so the same key reused with a different body is NOT a replay
 * (a conflict the caller may choose to reject — here it simply misses → re-processes).
 */
function apiKeyIdempotencyReplay(\mysqli $db, int $keyId, string $idemKey, string $rawBody): ?array
{
    if ($keyId <= 0 || !_apiKeyTableExists($db, 'tblApiKeyIdempotency')) { return null; }
    $hash = hash('sha256', $rawBody);
    $st = $db->prepare(
        'SELECT ResponseData, HttpStatus FROM tblApiKeyIdempotency
          WHERE ApiKeyId = ? AND IdempotencyKey = ? AND RequestHash = ? AND ExpiresAt > UTC_TIMESTAMP() LIMIT 1'
    );
    $st->bind_param('iss', $keyId, $idemKey, $hash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row === null) { return null; }
    return ['status' => (int)$row['HttpStatus'], 'data' => (string)$row['ResponseData']];
}

/** Cache a response under the Idempotency-Key (24h TTL). Best-effort; never throws. */
function apiKeyIdempotencyStore(\mysqli $db, int $keyId, string $idemKey, string $rawBody, int $status, string $responseJson): void
{
    if ($keyId <= 0 || !_apiKeyTableExists($db, 'tblApiKeyIdempotency')) { return; }
    try {
        $hash    = hash('sha256', $rawBody);
        $expires = gmdate('Y-m-d H:i:s', time() + 86400); /* fixed 24h TTL (schema contract) */
        $st = $db->prepare(
            'INSERT INTO tblApiKeyIdempotency (ApiKeyId, IdempotencyKey, RequestHash, ResponseData, HttpStatus, ExpiresAt)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ResponseData = VALUES(ResponseData), HttpStatus = VALUES(HttpStatus), ExpiresAt = VALUES(ExpiresAt)'
        );
        $st->bind_param('isssis', $keyId, $idemKey, $hash, $responseJson, $status, $expires);
        $st->execute();
        $st->close();
    } catch (\Throwable $_) { /* best-effort cache write */ }
}
