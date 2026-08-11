<?php

declare(strict_types=1);

/**
 * iHymns — Live Follow: capable Quick sessions (#1770 C1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) for the #1770 Live-Follow /
 * Service-Mode UX rethink (Option A). Additive + dormant: after this card runs
 * NOTHING reads or writes any of the new columns/table yet (the server logic is
 * C2–C4, the client C5/C6), so applying it changes ZERO observable behaviour —
 * every existing Quick (`live_follow_*`) and Service (`service_*`) session
 * behaves byte-identically. Full plan: `.claude/live-follow-1770-plan.md`.
 *
 * Three objects, in ONE migration (the whole family up-front, not dribbled):
 *
 *   1. tblLiveFollowSessions — the leader-idle auto-close machinery (req 2/5):
 *      - LastLeaderSeenAt: last GENUINE leader interaction (NOT the automated
 *        keepalive — conflating the two is the abandoned-tab bug being fixed).
 *      - IdleTimeoutMins: the timeout RESOLVED at create from the
 *        app→org→user precedence chain and STAMPED, so the prune/gate predicate
 *        is pure SQL with no per-row lookups. NULL = no idle enforcement
 *        (service sessions, legacy rows) — NULL-safety IS the dormancy.
 *      - idx_Idle: (SessionKind, IsActive, LastLeaderSeenAt) for the prune scan.
 *
 *   2. tblOrganisations — the org layer of the idle-timeout precedence (req 5):
 *      - LiveIdleTimeoutMins: org override (NULL = app default applies).
 *      - EnforceIdleTimeout: 1 = org LOCKS its value (members' personal value
 *        ignored). The owner specified these two columns explicitly (analysis
 *        §480-494) — they are the contract, like the tier-cap columns (rule #28).
 *
 *   3. tblServiceDriverKeys — NEW dormant table: durable org-scoped credentials
 *      for external presentation-app drivers (ProPresenter/OpenLP shims) of
 *      Service-Mode sessions (req 4/7). Deliberately NO Channel column — the
 *      rule-#26 wall is enforced at session-resolution time (every resolve
 *      filters the serving docroot's channel), never on the durable credential.
 *      tblApiKeys (site-admin, unscoped) and tblSessionControlTokens (per-session,
 *      short-TTL) can't serve this — a church key must drive only its OWN org's
 *      sessions and be mintable by an org-admin from a static shim config.
 *
 * App default (tblAppSettings key `live_follow_idle_timeout_minutes`), the user
 * value (tblUsers.Settings JSON), host-CCLI (resolved LIVE, nothing stored) and
 * section granularity (`CurrentComponentIndex` already exists) need NO schema —
 * see the plan §3.4.
 *
 * @migration-adds tblLiveFollowSessions.LastLeaderSeenAt
 * @migration-adds tblLiveFollowSessions.IdleTimeoutMins
 * @migration-adds tblOrganisations.LiveIdleTimeoutMins
 * @migration-adds tblOrganisations.EnforceIdleTimeout
 *
 * SCHEMA MIRROR: the two ALTERs (+ idx_Idle) and the full tblServiceDriverKeys
 * CREATE are mirrored byte-identical in appWeb/.sql/schema.sql — rule #19.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every ADD COLUMN/INDEX is existence-guarded
 * and the table is CREATE TABLE IF NOT EXISTS, so a second run touches nothing.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-live-follow-quick-capable.php
 *   Web:  /manage/setup-database → "Live Follow: capable Quick sessions (#1770)"
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/live-follow-1770-plan.md §3
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migLfqc_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migLfqc_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migLfqc_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }
function _migLfqc_tblExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migLfqc_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migLfqc_out("");
_migLfqc_out("=== iHymns — Live Follow: capable Quick sessions (#1770 C1) ===");
_migLfqc_out("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migLfqc_out("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migLfqc_out("Connected to MySQL: " . DB_NAME);

try {
    /* ---- 1. tblLiveFollowSessions idle columns + index ---- */
    _migLfqc_out("--- tblLiveFollowSessions ---");
    $lfsCols = [
        'LastLeaderSeenAt' => "ADD COLUMN LastLeaderSeenAt DATETIME NULL DEFAULT NULL
            COMMENT 'UTC instant of the last GENUINE leader interaction (broadcast/create/console action, or a leaderActive heartbeat) — NOT bumped by the automated 30s keepalive alone. NULL = pre-#1770 row or not yet stamped; idle enforcement skips it (#1770)'",
        'IdleTimeoutMins' => "ADD COLUMN IdleTimeoutMins SMALLINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Resolved idle-timeout (minutes) stamped at session create from the app→org→user precedence chain (serviceMode_resolveIdleTimeoutMins). NULL = no idle enforcement (service sessions, legacy rows, un-migrated writers) (#1770)'",
    ];
    foreach ($lfsCols as $col => $clause) {
        if (_migLfqc_colExists($mysql, 'tblLiveFollowSessions', $col)) {
            _migLfqc_out("  [SKIP] tblLiveFollowSessions.{$col} already present.");
        } else {
            $mysql->query("ALTER TABLE tblLiveFollowSessions {$clause}");
            _migLfqc_out("  [OK] Added tblLiveFollowSessions.{$col}.");
        }
    }
    if (_migLfqc_idxExists($mysql, 'tblLiveFollowSessions', 'idx_Idle')) {
        _migLfqc_out("  [SKIP] idx_Idle already present.");
    } else {
        $mysql->query("ALTER TABLE tblLiveFollowSessions ADD INDEX idx_Idle (SessionKind, IsActive, LastLeaderSeenAt)");
        _migLfqc_out("  [OK] Added idx_Idle.");
    }

    /* ---- 2. tblOrganisations idle-timeout override columns ---- */
    _migLfqc_out("--- tblOrganisations ---");
    $orgCols = [
        'LiveIdleTimeoutMins' => "ADD COLUMN LiveIdleTimeoutMins SMALLINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Org override for the Quick-session leader-idle timeout (minutes). NULL = no override — the app default applies (#1770 req 5)'",
        'EnforceIdleTimeout' => "ADD COLUMN EnforceIdleTimeout TINYINT(1) NOT NULL DEFAULT 0
            COMMENT '1 = the org LOCKS LiveIdleTimeoutMins for its members (their personal value is ignored). Only meaningful when LiveIdleTimeoutMins is non-NULL (#1770 req 5)'",
    ];
    foreach ($orgCols as $col => $clause) {
        if (_migLfqc_colExists($mysql, 'tblOrganisations', $col)) {
            _migLfqc_out("  [SKIP] tblOrganisations.{$col} already present.");
        } else {
            $mysql->query("ALTER TABLE tblOrganisations {$clause}");
            _migLfqc_out("  [OK] Added tblOrganisations.{$col}.");
        }
    }

    /* ---- 3. tblServiceDriverKeys ---- */
    _migLfqc_out("--- tblServiceDriverKeys ---");
    if (_migLfqc_tblExists($mysql, 'tblServiceDriverKeys')) {
        _migLfqc_out("  [SKIP] tblServiceDriverKeys already present.");
    } else {
        $mysql->query(
            "CREATE TABLE IF NOT EXISTS tblServiceDriverKeys (
                Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                OrgId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrganisations — the org whose service sessions this key may drive. v1 mint path always sets it; app rule: exactly one of OrgId / OwnerUserId is non-NULL (#1770 req 4)',
                OwnerUserId INT UNSIGNED NULL DEFAULT NULL COMMENT 'RESERVED-DORMANT (rule #20): a future personal driver key for Quick sessions. No v1 code writes it (#1770 §10 S4)',
                VenueId     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrgVenues — optional narrowing to one venue; NULL = any venue of the org',
                KeyHash     CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the raw key — raw value never stored (tblSessionControlTokens / tblApiKeys discipline)',
                KeyPrefix   VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Non-secret leading chars for admin identification (mirrors tblApiKeys.KeyPrefix)',
                Label       VARCHAR(120) NOT NULL COMMENT 'Operator-facing name, e.g. \"Sanctuary ProPresenter\"',
                Scope       VARCHAR(40)  NOT NULL DEFAULT 'broadcast' COMMENT 'Granted capability — app-validated VARCHAR vocab, never ENUM (rule #20)',
                Protocol    VARCHAR(30)  NOT NULL DEFAULT 'generic' COMMENT 'Which shim family minted/uses it: generic | propresenter | openlp | … — display + diagnostics vocab, app-validated VARCHAR',
                IsActive    TINYINT(1)   NOT NULL DEFAULT 1,
                ExpiresAt   DATETIME     NULL DEFAULT NULL COMMENT 'Optional expiry (UTC), NULL = never. DATETIME not TIMESTAMP (rule #20 TTL convention)',
                RevokedAt   DATETIME     NULL DEFAULT NULL COMMENT 'Hard revocation instant (UTC); non-NULL = refused',
                LastUsedAt  DATETIME     NULL DEFAULT NULL,
                LastUsedIp  VARCHAR(45)  NULL DEFAULT NULL,
                CreatedBy   INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id of the minting org-admin',
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_KeyHash (KeyHash),
                INDEX idx_Org (OrgId, IsActive),
                CONSTRAINT fk_DriverKey_Org   FOREIGN KEY (OrgId)       REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_DriverKey_Venue FOREIGN KEY (VenueId)     REFERENCES tblOrgVenues(Id)     ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_DriverKey_User  FOREIGN KEY (OwnerUserId) REFERENCES tblUsers(Id)         ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_DriverKey_Creator FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)         ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Durable org-scoped credentials for external presentation-app drivers (ProPresenter/OpenLP shims) of Service-Mode sessions (#1770 req 4/7). Deliberately NO Channel column — the wall is enforced at session-resolution time (rule #26): every resolve filters the serving docroot''s channel.'"
        );
        _migLfqc_out("  [OK] Created tblServiceDriverKeys.");
    }

    _migLfqc_out("");
    _migLfqc_out("=== Done. Live-Follow capability schema is in place (dormant until C2+). ===");
} catch (\mysqli_sql_exception $e) {
    _migLfqc_out("ERROR: migration failed: " . $e->getMessage());
    return;
}
