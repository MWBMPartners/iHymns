<?php

declare(strict_types=1);

/**
 * iHymns — Apple Phase-2 live schema batch (#1407 / #1408 / #1409 / #1410)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: this is the ONE database change for the whole "drive the TV /
 * remote-control / device pairing" family of upcoming Apple features. Rather
 * than adding one small table every time a piece of that family gets built
 * (four separate migrations touching the shared production database), every
 * table the family will need is created NOW, empty and unused (rule #20 —
 * one-pass forward-looking schema).
 *
 * DETAILED — five objects, in dependency order:
 *   1. tblServicePresence.Role       (#1406, ALTER — table already exists)
 *      Congregant vs projector poll-budget discriminator; already consumed
 *      by api.php's service_join/service_poll (columnExists-gated there —
 *      this migration is what flips those call sites live).
 *   2. tblApiTokens.DeviceName/Platform/AppVersion/LastSeenAt
 *      (#1409, ALTER — table already exists)
 *      Per-device metadata for the future "manage your devices" API/UI —
 *      the existing tblApiTokens row already IS one bearer token per
 *      signed-in device; this just lets it also carry a friendly name +
 *      platform + last-active timestamp instead of adding a parallel table.
 *   3. tblAuthDeviceCodes (#1407, CREATE — new)
 *      RFC 8628 OAuth Device Authorization Grant codes: a tvOS (or any
 *      limited-input) client requests a device_code + shows a short
 *      UserCode; the account owner enters that UserCode on a full-input
 *      device's browser at /link to approve. Channel-scoped (rule #26) so
 *      an alpha-issued code can't be approved against production.
 *   4. tblSessionControlTokens (#1408, CREATE — new)
 *      A scoped, revocable token that grants "control THIS ONE live/service
 *      session" without handing out the operator's own login — e.g. a
 *      leader hands their phone to a co-leader for the second half of a
 *      service without sharing credentials.
 *   5. tblApnsTokens (#1410, CREATE — new)
 *      APNs push tokens for ordinary device notifications AND (separately)
 *      Live Activities / Dynamic Island updates. `Kind` + nullable
 *      `SessionId` distinguish the two — a ordinary device token has no
 *      SessionId; a Live Activity token is scoped to the one live/service
 *      session it mirrors and churns independently of the device token.
 *
 * ALL FIVE ARE ENTIRELY DORMANT: no api.php endpoint reads/writes
 * tblAuthDeviceCodes, tblSessionControlTokens, or tblApnsTokens yet (those
 * ship in later Phase-2 PRs — #1407/#1408/#1410 respectively), and the
 * tblApiTokens/tblServicePresence columns are additive + nullable/defaulted
 * so every existing row and every existing read/write keeps working
 * unchanged. Running this migration on alpha/beta/production today changes
 * nothing observable.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (table/column existence guarded throughout).
 *
 * @migration-adds tblServicePresence.Role
 * @migration-adds tblApiTokens.DeviceName
 * @migration-adds tblApiTokens.Platform
 * @migration-adds tblApiTokens.AppVersion
 * @migration-adds tblApiTokens.LastSeenAt
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-apple-phase2-live-schema.php
 *   Web:  /manage/setup-database → "Apple Phase-2 live schema batch" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migPhase2Live_output(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { flush(); }
}

function _migPhase2Live_tableExists(\mysqli $db, string $table): bool
{
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

function _migPhase2Live_columnExists(\mysqli $db, string $table, string $col): bool
{
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    return (bool)($r && $r->num_rows > 0);
}

function _migPhase2Live_addCol(\mysqli $db, string $table, string $col, string $ddl): void
{
    if (_migPhase2Live_columnExists($db, $table, $col)) {
        _migPhase2Live_output("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migPhase2Live_output("  [OK] Added {$table}.{$col}.");
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migPhase2Live_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migPhase2Live_output("");
_migPhase2Live_output("=== iHymns — Apple Phase-2 live schema batch (#1407/#1408/#1409/#1410) ===");
_migPhase2Live_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)(defined('DB_PORT') ? DB_PORT : 3306));
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migPhase2Live_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migPhase2Live_output("Connected to MySQL: " . DB_NAME);

try {
    /* -------------------------------------------------------------------
     * 1. tblServicePresence.Role (#1406)
     * ------------------------------------------------------------------- */
    _migPhase2Live_output("");
    _migPhase2Live_output("--- tblServicePresence: Role ---");
    if (!_migPhase2Live_tableExists($mysql, 'tblServicePresence')) {
        _migPhase2Live_output("  [SKIP] tblServicePresence not present — run migrate-service-mode-sessions.php (#1335) first.");
    } else {
        _migPhase2Live_addCol(
            $mysql,
            'tblServicePresence',
            'Role',
            "Role VARCHAR(20) NOT NULL DEFAULT 'congregant' COMMENT 'congregant | projector — app-validated poll-budget role (#1406), VARCHAR not ENUM (rule #20)' AFTER PresenceToken"
        );
    }

    /* -------------------------------------------------------------------
     * 2. tblApiTokens.DeviceName/Platform/AppVersion/LastSeenAt (#1409)
     * ------------------------------------------------------------------- */
    _migPhase2Live_output("");
    _migPhase2Live_output("--- tblApiTokens: DeviceName/Platform/AppVersion/LastSeenAt ---");
    if (!_migPhase2Live_tableExists($mysql, 'tblApiTokens')) {
        _migPhase2Live_output("  [SKIP] tblApiTokens not present — this install predates the API token system.");
    } else {
        _migPhase2Live_addCol(
            $mysql,
            'tblApiTokens',
            'DeviceName',
            "DeviceName VARCHAR(120) NULL DEFAULT NULL COMMENT 'Client-reported device display name (#1409), e.g. an iPhone name — optional, never required for auth' AFTER UserId"
        );
        _migPhase2Live_addCol(
            $mysql,
            'tblApiTokens',
            'Platform',
            "Platform VARCHAR(20) NULL DEFAULT NULL COMMENT 'apple | android | web (#1409) — mirrors ANALYTICS_INGEST_ALLOWED_PLATFORMS vocabulary, VARCHAR not ENUM (rule #20)' AFTER DeviceName"
        );
        _migPhase2Live_addCol(
            $mysql,
            'tblApiTokens',
            'AppVersion',
            "AppVersion VARCHAR(20) NULL DEFAULT NULL COMMENT 'Client app version string at issue/last-seen time (#1409), e.g. 1.4.2' AFTER Platform"
        );
        _migPhase2Live_addCol(
            $mysql,
            'tblApiTokens',
            'LastSeenAt',
            "LastSeenAt DATETIME NULL DEFAULT NULL COMMENT 'Updated on each authenticated request bearing this token (#1409) — powers a future manage-devices last-active column' AFTER AppVersion"
        );
    }

    /* -------------------------------------------------------------------
     * 3. tblAuthDeviceCodes (#1407)
     * ------------------------------------------------------------------- */
    _migPhase2Live_output("");
    _migPhase2Live_output("--- tblAuthDeviceCodes ---");
    if (_migPhase2Live_tableExists($mysql, 'tblAuthDeviceCodes')) {
        _migPhase2Live_output("  [SKIP] tblAuthDeviceCodes already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblAuthDeviceCodes (
                Id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                DeviceCodeHash CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the device_code the tvOS/limited-input client polls with (RFC 8628 section 3.2) — raw value never stored',
                UserCode       VARCHAR(12)  NOT NULL COMMENT 'Short human code shown on-screen for the user to enter at /link (RFC 8628 section 3.1)',
                Status         VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | denied | expired — app-validated, VARCHAR not ENUM (rule #20)',
                UserId         INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers — set once the user approves at /link; NULL while pending',
                Channel        VARCHAR(16)  NULL DEFAULT NULL COMMENT '3-docroot env discriminator (rule #26) — filter in every poll/approve/prune query',
                PollCount      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Poll attempts so far — enforces RFC 8628 section 3.5 slow_down backoff',
                LastPolledAt   DATETIME     NULL DEFAULT NULL,
                ExpiresAt      DATETIME     NOT NULL COMMENT 'Device/user code TTL (short-lived, RFC 8628 section 3.2)',
                CreatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_DeviceCodeHash (DeviceCodeHash),
                UNIQUE KEY uq_UserCode (UserCode),
                INDEX      idx_Status_Expiry (Status, ExpiresAt),
                INDEX      idx_User (UserId),

                CONSTRAINT fk_AuthDeviceCodes_User
                    FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='RFC 8628 OAuth Device Authorization Grant codes for tvOS/limited-input pairing (#1407). Dormant until the tvOS device-code client + /link page ship.'"
        );
        _migPhase2Live_output("  [OK] Created tblAuthDeviceCodes.");
    }

    /* -------------------------------------------------------------------
     * 4. tblSessionControlTokens (#1408)
     * ------------------------------------------------------------------- */
    _migPhase2Live_output("");
    _migPhase2Live_output("--- tblSessionControlTokens ---");
    if (_migPhase2Live_tableExists($mysql, 'tblSessionControlTokens')) {
        _migPhase2Live_output("  [SKIP] tblSessionControlTokens already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSessionControlTokens (
                Id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                TokenHash  CHAR(64)        NOT NULL COMMENT 'SHA-256 hex of the scoped control token (raw value never stored)',
                SessionId  BIGINT UNSIGNED NOT NULL COMMENT 'FK tblLiveFollowSessions(Id) — BIGINT UNSIGNED to match the referenced PK; the live/service session this token may control',
                Channel    VARCHAR(16)     NULL DEFAULT NULL COMMENT '3-docroot env discriminator (rule #26) — filter in every issue/validate/revoke query',
                Scope      VARCHAR(40)      NOT NULL DEFAULT 'broadcast' COMMENT 'granted capability, e.g. broadcast | view — app-validated, VARCHAR not ENUM (rule #20)',
                IssuedAt   DATETIME     NOT NULL,
                ExpiresAt  DATETIME     NOT NULL COMMENT 'Revocable, short-lived control-token TTL',
                RevokedAt  DATETIME     NULL DEFAULT NULL COMMENT 'Explicit revoke timestamp; NULL = still live (subject to ExpiresAt)',
                CreatedAt  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_TokenHash (TokenHash),
                INDEX      idx_Session (SessionId),
                INDEX      idx_Expiry (ExpiresAt),

                CONSTRAINT fk_SessionControlTokens_Session
                    FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Scoped, revocable session control tokens for remote-control delegation (#1408). Dormant until session-control-token issuance/validation ships.'"
        );
        _migPhase2Live_output("  [OK] Created tblSessionControlTokens.");
    }

    /* -------------------------------------------------------------------
     * 5. tblApnsTokens (#1410)
     * ------------------------------------------------------------------- */
    _migPhase2Live_output("");
    _migPhase2Live_output("--- tblApnsTokens ---");
    if (_migPhase2Live_tableExists($mysql, 'tblApnsTokens')) {
        _migPhase2Live_output("  [SKIP] tblApnsTokens already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblApnsTokens (
                Id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Kind       VARCHAR(20)    NOT NULL DEFAULT 'device' COMMENT 'device | liveActivity — app-validated, VARCHAR not ENUM (rule #20); Live Activity tokens churn per-activity so Kind + nullable SessionId hedge without a 2nd migration',
                UserId     INT UNSIGNED   NULL DEFAULT NULL COMMENT 'FK tblUsers — owning user; NULL for an anonymous/presence-scoped token',
                SessionId  BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblLiveFollowSessions(Id) — BIGINT UNSIGNED to match the referenced PK; set only for Kind=liveActivity tokens tied to one live/service session',
                PushToken  VARBINARY(255) NOT NULL COMMENT 'Raw APNs device/activity push token bytes',
                ApnsEnv    VARCHAR(20)    NOT NULL DEFAULT 'production' COMMENT 'sandbox | production — which APNs gateway this token is valid against',
                ExpiresAt  DATETIME       NULL DEFAULT NULL COMMENT 'Optional TTL — NULL = no expiry (ordinary device tokens); Live Activity tokens set this to the activity end',
                CreatedAt  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_PushToken (PushToken),
                INDEX      idx_User (UserId),
                INDEX      idx_Session (SessionId),

                CONSTRAINT fk_ApnsTokens_User
                    FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_ApnsTokens_Session
                    FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='APNs push tokens for device notifications and Live Activities (#1410). Dormant until the APNs bridge ships.'"
        );
        _migPhase2Live_output("  [OK] Created tblApnsTokens.");
    }

    _migPhase2Live_output("");
    _migPhase2Live_output("--- Summary ---");
    _migPhase2Live_output("  All five Phase-2 live objects are now present (or already were).");
    _migPhase2Live_output("  Everything stays dormant until each consuming PR ships and starts");
    _migPhase2Live_output("  reading/writing these columns/tables.");
    _migPhase2Live_output("");
    _migPhase2Live_output("Migration complete.");
} catch (\Throwable $e) {
    _migPhase2Live_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
