<?php

declare(strict_types=1);

/**
 * iHymns — Service Driver Keys (#1770 C4, req #4/#7)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE
 * -------
 * ELI5: a church's ProPresenter laptop (or a Stream Deck script, or a
 * Companion webhook) needs a way to tell iHymns "advance to this song / this
 * section" without a human clicking a browser button for every slide change.
 * This is the credential that lets it do that — a long-lived, per-organisation
 * key an org-admin mints once and pastes into the shim's config.
 *
 * DETAILED — `tblServiceDriverKeys` shipped dormant in #1770 C1
 * (`migrate-live-follow-quick-capable.php`). This module is the lifecycle
 * core: mint / verify / revoke / list, plus the request-header extraction the
 * new `service_drive` api.php action authenticates with. Every function is
 * TABLE-EXISTENCE-GATED (mysqli STRICT throws on a missing table; migrations
 * are web-run, never auto-applied, and the three docroots share one MySQL —
 * rule #19) so an un-migrated install degrades to "not available yet" (503),
 * never a fatal.
 *
 * WHY NOT REUSE `tblApiKeys` / `includes/api_keys.php`. tblApiKeys is
 * site-admin-minted with no org scoping (schema.sql) — a church driver key
 * must only ever be able to drive its OWN organisation's Service-Mode
 * sessions, and must be mintable by an ORG-admin, not a site admin. The
 * request-HEADER extraction is genuinely identical, though, so this module
 * reuses `apiKeyFromRequest()` from `api_keys.php` rather than re-parsing
 * `Authorization: Bearer` / `X-API-Key` a second time (CLAUDE.md modularity
 * rule — extract first, use second).
 *
 * WHY NOT REUSE `tblSessionControlTokens` / `session_control_token.php`.
 * Those are per-SESSION, short-TTL, minted by an already-authenticated
 * operator's browser for a co-leader's phone. A driver key is the opposite
 * shape: durable, org-scoped (not session-scoped — a shim names a VENUE, so
 * the same static config keeps working across every service that venue ever
 * runs), and held by unattended automation with no login of its own.
 *
 * SECURITY DISCIPLINE (mirrors `api_keys.php` / `session_control_token.php`):
 *   - The RAW key is generated, shown to the minting operator EXACTLY ONCE in
 *     the mint response, and never stored — only its SHA-256 hex
 *     (`KeyHash`) persists.
 *   - `KeyPrefix` (the raw key's first characters) is stored in the clear
 *     purely for admin identification ("which key is this?") — mirrors
 *     `tblApiKeys.KeyPrefix`. It is NOT secret and NEVER sufficient to
 *     authenticate.
 *   - Revocation is a flag (`RevokedAt` + `IsActive = 0`), never a DELETE —
 *     same "retirement is a flag" discipline as the join-code family
 *     (service_mode.php's header doc-block) and for the same reason: audit.
 *
 * @see includes/api_keys.php            apiKeyFromRequest() — reused here
 * @see includes/session_control_token.php the sibling short-TTL credential
 * @see .claude/live-follow-1770-plan.md §3.3, §4.5, §4.6
 * @link https://www.php.net/manual/en/function.hash.php
 * @link https://www.php.net/manual/en/function.random-bytes.php
 */

/* Direct-hit guard (the standing includes/ idiom — see content_access.php's
   header for the full rationale: a fetchable includes/ path must never
   answer with a blank 200 that tells a prober the file exists). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* apiKeyFromRequest() — the shared Authorization/X-API-Key header parser
   (rule: extract first, use second; never a second header-parsing copy). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api_keys.php';

/** Closed `Scope` vocabulary — VARCHAR, app-validated, never an ENUM (rule
 *  #20: a value-add would otherwise be a schema ALTER). Only 'broadcast' has
 *  a consumer today (`service_drive`); the column already carries room for a
 *  future read-only scope with zero migration. */
const SERVICE_DRIVER_KEY_SCOPES = ['broadcast'];

/** Closed `Protocol` vocabulary — display/diagnostics only (which shim
 *  family minted/uses the key); VARCHAR, app-validated, never an ENUM. The
 *  server CONTRACT is identical for every protocol (§7 of the plan — a
 *  generic contract ships now, protocol-specific shims are a follow-on). */
const SERVICE_DRIVER_KEY_PROTOCOLS = ['generic', 'propresenter', 'openlp'];

/** 10 mints / hour / user is plenty for an org-admin provisioning a handful
 *  of venues; matches the house rate for other credential-minting actions
 *  (service_control_token_mint is 30/hr, this is deliberately tighter since
 *  a driver key is durable rather than a 2h delegated token). */
const SERVICE_DRIVER_KEY_MINT_LIMIT_PER_HOUR = 10;

/**
 * Memoised INFORMATION_SCHEMA existence probe for `tblServiceDriverKeys`.
 * Mirrors `sessionControlTokensTableExists()` (session_control_token.php).
 */
function serviceDriverKeysTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblServiceDriverKeys' LIMIT 1"
        );
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $exists = false; /* un-migrated install → treat as absent → caller 503s */
    }
    return $exists;
}

/**
 * Generate a fresh raw driver key + its storage-ready hash/prefix. Mirrors
 * `apiKeyGenerate()`'s shape (api_keys.php) with a distinct prefix so a key
 * is visually identifiable as a driver key rather than a general API key.
 *
 * @return array{raw:string, hash:string, prefix:string}
 */
function serviceDriverKeyGenerate(): array
{
    $secret = bin2hex(random_bytes(24)); /* 48 hex chars, 192 bits */
    $raw    = 'ihdrv_live_' . $secret;
    return [
        'raw'    => $raw,
        'hash'   => hash('sha256', $raw),
        'prefix' => substr($raw, 0, 17), /* "ihdrv_live_" + 6 chars — non-secret */
    ];
}

/**
 * Mint + persist a fresh driver key for one organisation. Returns the RAW
 * key ONCE (the caller hands it to the org-admin — it is never stored, never
 * re-derivable, never logged) or null on a genuine failure. Table-existence
 * NOT checked here — the caller (api.php) already gates on
 * serviceDriverKeysTableExists() before reaching this, matching the
 * `sessionControlToken_mint()` convention.
 *
 * `Scope`/`Protocol` are validated against the closed vocabularies above and
 * fall back to their column defaults ('broadcast'/'generic') on anything
 * unrecognised — fail-open on a cosmetic field, never a 500.
 *
 * @param int         $orgId      The organisation this key may drive sessions for.
 * @param int|null    $venueId    Optional narrowing to one venue; null = any venue of the org.
 * @param string      $label      Operator-facing name, e.g. "Sanctuary ProPresenter".
 * @param string      $protocol   One of SERVICE_DRIVER_KEY_PROTOCOLS.
 * @param int|null    $createdBy  tblUsers.Id of the minting org-admin.
 * @return array{raw:string, id:int, prefix:string}|null
 */
function serviceDriverKeyMint(\mysqli $db, int $orgId, ?int $venueId, string $label, string $protocol, ?int $createdBy): ?array
{
    $label = trim($label);
    if ($orgId <= 0 || $label === '') {
        return null;
    }
    $label = mb_substr($label, 0, 120);
    $protocol = in_array($protocol, SERVICE_DRIVER_KEY_PROTOCOLS, true) ? $protocol : 'generic';

    $gen = serviceDriverKeyGenerate();
    try {
        $ins = $db->prepare(
            "INSERT INTO tblServiceDriverKeys
                (OrgId, VenueId, KeyHash, KeyPrefix, Label, Scope, Protocol, IsActive, CreatedBy)
             VALUES (?, ?, ?, ?, ?, 'broadcast', ?, 1, ?)"
        );
        $ins->bind_param('iissssi', $orgId, $venueId, $gen['hash'], $gen['prefix'], $label, $protocol, $createdBy);
        $ins->execute();
        $id = (int)$db->insert_id;
        $ins->close();
    } catch (\Throwable $e) {
        error_log('[service_driver_keys/mint] ' . $e->getMessage());
        return null;
    }
    return ['raw' => $gen['raw'], 'id' => $id, 'prefix' => $gen['prefix']];
}

/**
 * Look up + validate a raw driver key. On a match that is active, unrevoked
 * and unexpired, best-effort stamps `LastUsedAt`/`LastUsedIp` and returns the
 * key row (never the hash). Returns null otherwise (unknown / revoked /
 * inactive / expired). Lookup is by hash (indexed, constant work) — the raw
 * key is never compared in SQL or logged.
 *
 * @return array{Id:int, OrgId:?int, VenueId:?int, Label:string, Protocol:string, Scope:string}|null
 */
function serviceDriverKeyVerify(\mysqli $db, string $rawKey): ?array
{
    $rawKey = trim($rawKey);
    if ($rawKey === '') {
        return null;
    }
    $hash = hash('sha256', $rawKey);

    try {
        $stmt = $db->prepare(
            'SELECT Id, OrgId, VenueId, Label, Protocol, Scope, IsActive, RevokedAt, ExpiresAt
               FROM tblServiceDriverKeys WHERE KeyHash = ? LIMIT 1'
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[service_driver_keys/verify] ' . $e->getMessage());
        return null;
    }

    if ($row === null || (int)$row['IsActive'] !== 1 || $row['RevokedAt'] !== null) {
        return null;
    }
    if ($row['ExpiresAt'] !== null && strtotime((string)$row['ExpiresAt'] . ' UTC') <= time()) {
        return null;
    }

    /* Best-effort usage stamp — never block the request on it (mirrors
       apiKeyVerify()'s identical posture). */
    try {
        $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $kid = (int)$row['Id'];
        $upd = $db->prepare('UPDATE tblServiceDriverKeys SET LastUsedAt = UTC_TIMESTAMP(), LastUsedIp = ? WHERE Id = ?');
        $upd->bind_param('si', $ip, $kid);
        $upd->execute();
        $upd->close();
    } catch (\Throwable $_e) { /* ignore — never block the request */ }

    return [
        'Id'       => (int)$row['Id'],
        'OrgId'    => $row['OrgId'] !== null ? (int)$row['OrgId'] : null,
        'VenueId'  => $row['VenueId'] !== null ? (int)$row['VenueId'] : null,
        'Label'    => (string)$row['Label'],
        'Protocol' => (string)$row['Protocol'],
        'Scope'    => (string)$row['Scope'],
    ];
}

/**
 * Hard-revoke one driver key, scoped to the ORG that owns it (so an
 * org-admin cannot revoke a key belonging to a DIFFERENT organisation by
 * guessing/incrementing an id — the WHERE carries both `Id` and `OrgId`).
 * Retirement is a flag (`RevokedAt` + `IsActive = 0`), never a DELETE — the
 * row (and its usage history) survives for audit.
 *
 * @return bool true if a row was actually revoked (false = wrong org, unknown
 *              id, or already revoked — all indistinguishable to the caller
 *              on purpose, same anti-enumeration posture as the rest of this
 *              file's siblings).
 */
function serviceDriverKeyRevoke(\mysqli $db, int $keyId, int $orgId): bool
{
    if ($keyId <= 0 || $orgId <= 0) {
        return false;
    }
    try {
        $upd = $db->prepare(
            'UPDATE tblServiceDriverKeys
                SET RevokedAt = UTC_TIMESTAMP(), IsActive = 0
              WHERE Id = ? AND OrgId = ? AND RevokedAt IS NULL'
        );
        $upd->bind_param('ii', $keyId, $orgId);
        $upd->execute();
        $ok = $upd->affected_rows > 0;
        $upd->close();
        return $ok;
    } catch (\Throwable $e) {
        error_log('[service_driver_keys/revoke] ' . $e->getMessage());
        return false;
    }
}

/**
 * List an organisation's driver keys for the admin surface — Label, Prefix,
 * Protocol, VenueId, activity, expiry — NEVER the hash (the raw key cannot
 * be recovered once minted, by design).
 *
 * @return list<array<string,mixed>>
 */
function serviceDriverKeyList(\mysqli $db, int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    try {
        $stmt = $db->prepare(
            'SELECT Id, VenueId, KeyPrefix, Label, Scope, Protocol, IsActive, ExpiresAt,
                    RevokedAt, LastUsedAt, LastUsedIp, CreatedAt
               FROM tblServiceDriverKeys
              WHERE OrgId = ?
              ORDER BY CreatedAt DESC'
        );
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (\Throwable $e) {
        error_log('[service_driver_keys/list] ' . $e->getMessage());
        return [];
    }
}
