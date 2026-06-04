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
        header('WWW-Authenticate: Bearer realm="iHymns API", scope="' . $requiredScope . '"');
        echo json_encode(['error' => 'Missing API key. Send "Authorization: Bearer <key>" or "X-API-Key: <key>".']);
        return null;
    }
    $row = apiKeyVerify($db, $raw, $requiredScope);
    if ($row === null) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer realm="iHymns API", scope="' . $requiredScope . '"');
        echo json_encode(['error' => 'Invalid or unauthorized API key.']);
        return null;
    }
    return $row;
}
