<?php

declare(strict_types=1);

/**
 * iHymns — Service Mode sessions (Phase 2a schema, #1335 / epic #1323)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The forward-looking schema for "Service Mode" — congregants join a live
 * service via a venue-displayed ROTATING CODE and follow the songs in sync,
 * reusing the #1268 Live-Follow spine (tblLiveFollowSessions + the StateRevision
 * short-poll). Phase 3 (the temporary presence-scoped CCLI unlock) binds on top.
 *
 * DESIGN (locked via an adversarial design workflow — see
 * .claude/live-congregant-strategy.md). This ships the WHOLE Phase 2+3 schema in
 * ONE batch (rule #20), additive + dormant — no behaviour yet; the projection
 * page (2b), congregant client (2c) and gated unlock (3) consume it later.
 *
 *   (1) EXTEND tblLiveFollowSessions (reuse the spine, don't fork it):
 *       - RELAX HostUserId to NULL — a service session is anchored to a
 *         VENUE+SCHEDULE and may be host-less. The existing one-active-per-host
 *         supersede (`WHERE HostUserId = ?`, api.php:11618) is already NULL-safe
 *         (`NULL = <id>` never matches), so a host creating a session never
 *         touches a host-less service session — no api.php change needed here.
 *       - light up VenueId / ScheduleId / OccurrenceDate (the service-occurrence
 *         identity), SessionKind ('host' = #1268 leader follow, 'service' = this),
 *         and Channel — the 3-DOCROOT discriminator (HTTP_HOST-derived at create)
 *         so a session started on alpha/beta is NEVER joinable/gating on prod
 *         (same class as the prod-stale-SongbookName bug; must be in EVERY WHERE).
 *   (2) tblLiveFollowJoinCodes — DB-stored rotating nonce window (current +
 *       previous + grace) per session; SESSION-SCOPED uniqueness so two venues'
 *       identical codes never collide. The mint/rotate is transactional (2b).
 *   (3) tblServicePresence — an ANONYMOUS congregant's presence: client-minted
 *       PresenceDeviceId + an opaque PresenceToken (the gate key, hard-revocable),
 *       ExpiresAt = resolved-UTC min(service-occurrence end, hard ceiling). The
 *       Phase-3 gate reads this by token; revoked on leave/expiry/session-end.
 *   (4) tblServicePollCounters — a PER-SESSION poll budget so a NAT-shared
 *       congregation (many devices, one church-wifi IP) isn't throttled by the
 *       #1268 per-IP cap (the flagged NAT fix).
 *
 * VARCHAR-not-ENUM for SessionKind / Status (rule #20). DATETIME (not TIMESTAMP)
 * for the sync/expiry clocks. Additive + IDEMPOTENT (column/table guarded).
 * Runs AFTER migrate-org-venues.php (FKs to tblOrgVenues / tblOrgServiceSchedules).
 *
 * @migration-adds tblLiveFollowSessions.VenueId
 * @migration-adds tblLiveFollowSessions.ScheduleId
 * @migration-adds tblLiveFollowSessions.OccurrenceDate
 * @migration-adds tblLiveFollowSessions.SessionKind
 * @migration-adds tblLiveFollowSessions.Channel
 * @migration-adds tblLiveFollowJoinCodes
 * @migration-adds tblServicePresence
 * @migration-adds tblServicePollCounters
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-service-mode-sessions.php
 *   Web:  /manage/setup-database → "Service Mode sessions" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSMS_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSMS_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migSMS_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSMS_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSMS_output("");
_migSMS_output("=== iHymns — Service Mode sessions (#1335) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSMS_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSMS_output("Connected to MySQL: " . DB_NAME);

try {
    /* (1) Extend the #1268 spine. Gated on VenueId-absent so the whole ALTER
          (incl. the HostUserId relax) runs once + is idempotent. */
    if (_migSMS_columnExists($mysql, 'tblLiveFollowSessions', 'VenueId')) {
        _migSMS_output("  [SKIP] tblLiveFollowSessions already extended.");
    } else {
        $mysql->query(
            "ALTER TABLE tblLiveFollowSessions
                MODIFY HostUserId INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'FK to tblUsers — leader hosting; NULL for an org/venue-anchored service session (#1335)',
                ADD COLUMN VenueId        INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrgVenues — venue of this service session' AFTER OrgId,
                ADD COLUMN ScheduleId     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrgServiceSchedules — the recurring schedule' AFTER VenueId,
                ADD COLUMN OccurrenceDate DATE         NULL DEFAULT NULL COMMENT 'Date of this occurrence (scheduleId + date = occurrence identity)' AFTER ScheduleId,
                ADD COLUMN SessionKind    VARCHAR(20)  NOT NULL DEFAULT 'host' COMMENT 'host = #1268 leader follow | service = #1335 venue/org session — app-validated, VARCHAR not ENUM',
                ADD COLUMN Channel        VARCHAR(16)  NULL DEFAULT NULL COMMENT '3-docroot env discriminator (HTTP_HOST-derived at create); MUST be filtered in every join/poll/gate/prune query so a non-prod session is never joinable on prod',
                ADD INDEX idx_Service (VenueId, ScheduleId, OccurrenceDate, IsActive),
                ADD INDEX idx_OrgActive (OrgId, IsActive),
                ADD CONSTRAINT fk_LiveFollow_Venue    FOREIGN KEY (VenueId)    REFERENCES tblOrgVenues(Id)          ON DELETE SET NULL ON UPDATE CASCADE,
                ADD CONSTRAINT fk_LiveFollow_Schedule FOREIGN KEY (ScheduleId) REFERENCES tblOrgServiceSchedules(Id) ON DELETE SET NULL ON UPDATE CASCADE"
        );
        _migSMS_output("  [OK] Extended tblLiveFollowSessions (host NULLable + VenueId/ScheduleId/OccurrenceDate/SessionKind/Channel + indexes + FKs).");
    }

    /* (2) Rotating join codes. */
    if (_migSMS_tableExists($mysql, 'tblLiveFollowJoinCodes')) {
        _migSMS_output("  [SKIP] tblLiveFollowJoinCodes already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLiveFollowJoinCodes (
                Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SessionId   INT UNSIGNED  NOT NULL COMMENT 'FK tblLiveFollowSessions',
                Code        VARCHAR(12)   NOT NULL COMMENT 'Crockford base32 rotating join code (current/previous live)',
                Generation  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Monotonic per-session rotation counter',
                Status      VARCHAR(20)   NOT NULL DEFAULT 'current' COMMENT 'current | previous | superseded — app-validated, VARCHAR not ENUM (rule #20)',
                IssuedAt    DATETIME      NOT NULL,
                ExpiresAt   DATETIME      NOT NULL COMMENT 'Rotation horizon + grace (UTC); join rejects past this',
                CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Session_Code (SessionId, Code),
                INDEX idx_Session_Status (SessionId, Status),
                INDEX idx_Expiry (ExpiresAt),
                CONSTRAINT fk_JoinCode_Session FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Rotating venue join codes for Service Mode (#1335). Session-scoped uniqueness; current+previous window so a just-before-rotation scan still validates.'"
        );
        _migSMS_output("  [OK] Created tblLiveFollowJoinCodes.");
    }

    /* (3) Anonymous congregant presence (Phase 2 join + Phase 3 gate read). */
    if (_migSMS_tableExists($mysql, 'tblServicePresence')) {
        _migSMS_output("  [SKIP] tblServicePresence already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblServicePresence (
                Id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SessionId        INT UNSIGNED  NOT NULL COMMENT 'FK tblLiveFollowSessions',
                OrgId            INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Denorm of the session org for the Phase-3 gate read',
                VenueId          INT UNSIGNED  NULL DEFAULT NULL,
                ScheduleId       INT UNSIGNED  NULL DEFAULT NULL,
                OccurrenceDate   DATE          NULL DEFAULT NULL,
                Channel          VARCHAR(16)   NULL DEFAULT NULL COMMENT '3-docroot env discriminator (copied from the session); filter in every query',
                PresenceDeviceId VARCHAR(64)   NOT NULL COMMENT 'Client-minted anonymous device id (localStorage UUID) — advisory cooldown key, NOT a security control',
                PresenceToken    CHAR(43)      NOT NULL COMMENT 'Opaque base64url 32-byte nonce — the gate key; hard-revocable (not a signed token)',
                JoinedAt         DATETIME      NOT NULL,
                LastSeenAt       DATETIME      NOT NULL,
                ExpiresAt        DATETIME      NOT NULL COMMENT 'Resolved UTC = min(service-occurrence end via venue IANA tz, hard ceiling); gate + prune compare against this',
                IsActive         TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = left/revoked → immediate gate revocation',
                CreatedAt        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Token (PresenceToken),
                UNIQUE KEY uq_DeviceSession (SessionId, PresenceDeviceId),
                INDEX idx_Session (SessionId, IsActive),
                INDEX idx_Gate (PresenceToken, IsActive, ExpiresAt),
                CONSTRAINT fk_Presence_Session FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Anonymous congregant presence for Service Mode (#1335). PresenceToken = the Phase-3 gate key; ExpiresAt = resolved-UTC service end; one row per device per session (re-join reactivates).'"
        );
        _migSMS_output("  [OK] Created tblServicePresence.");
    }

    /* (4) Per-session NAT-safe poll budget. */
    if (_migSMS_tableExists($mysql, 'tblServicePollCounters')) {
        _migSMS_output("  [SKIP] tblServicePollCounters already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblServicePollCounters (
                Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SessionId   INT UNSIGNED NOT NULL COMMENT 'FK tblLiveFollowSessions',
                WindowStart DATETIME     NOT NULL COMMENT 'Start of the fixed counting window (UTC)',
                PollCount   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Polls served to the WHOLE session in this window — NAT-safe per-session budget (not per-IP, which would throttle a congregation behind one church-wifi IP)',
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Session_Window (SessionId, WindowStart),
                INDEX idx_Window (WindowStart),
                CONSTRAINT fk_PollCtr_Session FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-session poll budget for Service Mode (#1335) — the NAT-safe replacement for the #1268 per-IP poll cap.'"
        );
        _migSMS_output("  [OK] Created tblServicePollCounters.");
    }

    _migSMS_output("  Tables ship empty + dormant; the projection page (2b), congregant client (2c) + gated unlock (3) consume them. NOTHING joins/gates yet.");
    _migSMS_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSMS_output("ERROR: " . $e->getMessage());
}
