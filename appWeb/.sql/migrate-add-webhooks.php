<?php

declare(strict_types=1);

/**
 * iHymns — Partner-event outbound webhooks platform (#1909)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates the three dormant tables that back the outbound partner-webhooks
 * platform (design: .claude/webhooks-1909-design.md §4). External systems
 * subscribe to iHymns events (a song changed, a set-list was shared, a
 * service went live) and receive signed HTTP POST callbacks with retry,
 * dead-lettering and an admin triage surface.
 *
 *   - tblWebhookSubscriptions — the partner endpoints + their signing secret,
 *       event selectors (space-separated, mirrors tblApiKeys.Scope), rotation
 *       grace, health counters and lifecycle Status (VARCHAR vocab, rule #20).
 *   - tblWebhookEvents        — the durable event ledger / OUTBOX: ONE frozen
 *       envelope-payload row per event, fanned out to N delivery rows.
 *   - tblWebhookDeliveries     — per-(event × subscription) delivery state,
 *       retry schedule, claim lease and the dead-letter surface.
 *
 * Every table carries a Channel column (alpha | beta | production) filtered in
 * EVERY enqueue/claim/drain/list query — the 3 docroots share ONE MySQL, so an
 * un-filtered query is the prod-stale cross-env leak class (rule #26).
 *
 * ENTIRELY DORMANT: nothing reads or writes these tables until
 * tblAppSettings.webhooks_enabled_channels names a channel (design §9). All
 * retry/TTL instants are DATETIME (UTC), never TIMESTAMP; all vocab columns are
 * VARCHAR app-validated against a central map, never ENUM (rule #20). Additive,
 * IDEMPOTENT (per-table existence guard) — safe to re-run. Pure DDL, so no
 * shared include is required (rule #41: were one ever needed it would resolve
 * via IHYMNS_INCLUDES_DIR, never a hardcoded /public_html/ literal).
 *
 * @migration-adds tblWebhookSubscriptions.TargetUrl
 * @migration-adds tblWebhookEvents.PayloadJson
 * @migration-adds tblWebhookDeliveries.NextAttemptAt
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-webhooks.php
 *   Web:  /manage/setup-database → "Partner webhooks platform (#1909)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migWH_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migWH_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migWH_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migWH_output("");
_migWH_output("=== iHymns — Partner-event outbound webhooks (#1909) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migWH_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migWH_output("Connected to MySQL: " . DB_NAME);

try {
    /* --- 1. Subscriptions --- */
    if (_migWH_tableExists($mysql, 'tblWebhookSubscriptions')) {
        _migWH_output("  [SKIP] tblWebhookSubscriptions already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblWebhookSubscriptions (
                Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                Channel       VARCHAR(20)   NOT NULL COMMENT 'alpha | beta | production — the docroot env this subscription belongs to (rule #26). EVERY query filters it; a subscription never receives another channel''s events',
                OrgId         INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Reserved (dormant in v1): org-scoped subscription — receives only events whose payload org matches. NULL = global (admin-created partner feed)',
                SystemId      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Optional link to the tblExternalSystems registry (#1327) so a subscription is associated with a registered external system; informational, never an auth path',
                Label         VARCHAR(120)  NOT NULL COMMENT 'Human label, e.g. \"iLyricsDB sync\"',
                TargetUrl     VARCHAR(500)  NOT NULL COMMENT 'https:// delivery endpoint (app-validated: https, default port, public host — includes/webhooks.php SSRF gate)',
                TargetHost    VARCHAR(190)  NOT NULL COMMENT 'Lowercased host derived from TargetUrl at save (never client-supplied) — per-host caps + abuse queries without URL parsing in SQL',
                Secret        VARCHAR(500)  NOT NULL COMMENT 'HMAC signing secret (whsec_…): enc:v1 envelope when secret encryption is active, else plaintext bridge (secret_crypto.php). Needed in clear to sign — never a hash',
                SecretPrevious VARCHAR(500) NULL DEFAULT NULL COMMENT 'Previous secret retained during rotation grace — deliveries carry a second v1= signature under it until SecretPreviousExpiresAt',
                SecretPreviousExpiresAt DATETIME NULL DEFAULT NULL COMMENT 'UTC end of the dual-signing rotation grace window; NULL = no rotation in flight',
                Events        VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Space-separated event selectors: exact type, family.* wildcard, or * — app-validated against IHYMNS_WEBHOOK_EVENTS (VARCHAR vocabulary, rule #20). Mirrors tblApiKeys.Scope',
                ApiVersion    VARCHAR(10)   NOT NULL DEFAULT '1' COMMENT 'Payload contract version this subscriber receives — a future breaking payload reshape mints \"2\" with no migration',
                HeadersJson   JSON          NULL DEFAULT NULL COMMENT 'Reserved (dormant in v1): extra request headers some receivers require, {name:value} app-validated against a header-name allow-list (never Authorization/Host/Content-*)',
                FilterJson    JSON          NULL DEFAULT NULL COMMENT 'Reserved (dormant in v1): finer-than-type delivery filters (e.g. {\"songbooks\":[\"MP\"]}) — growable vocabulary as JSON, never new columns (rule #28 Capabilities precedent)',
                Status        VARCHAR(30)   NOT NULL DEFAULT 'pending_verification' COMMENT 'pending_verification | active | paused | disabled_failing | revoked — app-validated, VARCHAR not ENUM (rule #20)',
                VerifyToken   CHAR(64)      NULL DEFAULT NULL COMMENT 'Reserved: outstanding async-verification challenge hash (v1 verification is synchronous and stores nothing)',
                VerifiedAt    DATETIME      NULL DEFAULT NULL COMMENT 'UTC instant the endpoint last passed the challenge-echo handshake',
                ConsecutiveFailures INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dead deliveries in a row since the last success — drives the auto-disable ladder',
                FailingSince  DATETIME      NULL DEFAULT NULL COMMENT 'UTC start of the current consecutive-failure run; NULL when healthy',
                LastAttemptAt DATETIME      NULL DEFAULT NULL COMMENT 'Denorm for the admin list (rule #44: earns its read)',
                LastSuccessAt DATETIME      NULL DEFAULT NULL COMMENT 'Denorm for the admin list',
                CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id of the admin who created it',
                CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_ChannelStatus (Channel, Status),
                INDEX idx_TargetHost    (TargetHost),
                INDEX idx_Org           (OrgId),

                CONSTRAINT fk_WebhookSub_Org       FOREIGN KEY (OrgId)     REFERENCES tblOrganisations(Id)   ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_WebhookSub_System    FOREIGN KEY (SystemId)  REFERENCES tblExternalSystems(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_WebhookSub_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)           ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Outbound partner-webhook subscriptions (#1909). Secret is enc:v1-enveloped at rest; Events mirrors tblApiKeys.Scope; Channel walls the 3 docroots (rule #26).'"
        );
        _migWH_output("  [OK] Created tblWebhookSubscriptions.");
    }

    /* --- 2. Event ledger (outbox) --- */
    if (_migWH_tableExists($mysql, 'tblWebhookEvents')) {
        _migWH_output("  [SKIP] tblWebhookEvents already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblWebhookEvents (
                Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                EventUid    VARCHAR(40)   NOT NULL COMMENT 'evt_<32 hex> — the id in the envelope; the receiver''s dedupe key',
                Channel     VARCHAR(20)   NOT NULL COMMENT 'ihymns_environment() at emit (rule #26)',
                EventType   VARCHAR(60)   NOT NULL COMMENT 'Dotted type app-validated against IHYMNS_WEBHOOK_EVENTS (VARCHAR vocabulary, rule #20)',
                EntityType  VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'song | songbook | setlist | service | webhook — mirrors the activity-log entity vocabulary for admin filtering',
                EntityId    VARCHAR(190)  NOT NULL DEFAULT '' COMMENT 'The subject''s id as text (SongId, abbr, numeric id) — display/filtering, never a FK (subjects outlive/predate events)',
                PayloadJson MEDIUMTEXT    NOT NULL COMMENT 'The FROZEN full envelope-data JSON built at emit — redelivery is byte-identical (only the signature timestamp moves)',
                OccurredAt  DATETIME      NOT NULL COMMENT 'UTC emit instant (DATETIME not TIMESTAMP, rule #20)',
                ActorUserId INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Who performed the action — internal audit only, NEVER emitted in partner payloads',
                Source      VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Emitting funnel (editor_save | lyrics_ingest | bulk_import | api | admin) — provenance + the idempotency pair below',
                SourceRef   VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional natural idempotency ref from the funnel (e.g. ingest job id) — with Source, re-runs re-emit nothing; NULL rows coexist freely (rule #20)',
                ExpiresAt   DATETIME      NOT NULL COMMENT 'Retention TTL (emit + 30 days) — rows past this are prune-eligible',
                CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_EventUid  (EventUid),
                UNIQUE KEY uq_SourceRef (Source, SourceRef),
                INDEX idx_ChannelOccurred (Channel, OccurredAt),
                INDEX idx_Type            (EventType),
                INDEX idx_Entity          (EntityType, EntityId),
                INDEX idx_Expires         (ExpiresAt)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Durable webhook event ledger / outbox (#1909): one frozen payload per event, fanned out to N tblWebhookDeliveries rows. (Source,SourceRef) UNIQUE = idempotent re-emission (rule #20).'"
        );
        _migWH_output("  [OK] Created tblWebhookEvents.");
    }

    /* --- 3. Deliveries (per-subscription queue + dead-letter) --- */
    if (_migWH_tableExists($mysql, 'tblWebhookDeliveries')) {
        _migWH_output("  [SKIP] tblWebhookDeliveries already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblWebhookDeliveries (
                Id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                EventId        BIGINT UNSIGNED NOT NULL COMMENT 'FK tblWebhookEvents.Id — the frozen payload',
                SubscriptionId INT UNSIGNED    NOT NULL COMMENT 'FK tblWebhookSubscriptions.Id',
                Channel        VARCHAR(20)     NOT NULL COMMENT 'Denorm of the subscription channel (app-derived) so the claim query is index-only with no join (rule #26: filtered in EVERY claim/drain)',
                Status         VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | delivering | succeeded | failed | dead | cancelled — app-validated, VARCHAR not ENUM (rule #20). failed = scheduled for retry; dead = attempts exhausted (dead-letter); cancelled = subscription revoked/paused mid-queue',
                AttemptCount   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Attempts made since enqueue (reset to 0 by an admin re-drive; history stays in AttemptLogJson)',
                NextAttemptAt  DATETIME        NOT NULL COMMENT 'UTC due time — the claim predicate (DATETIME not TIMESTAMP, rule #20)',
                ClaimToken     CHAR(32)        NULL DEFAULT NULL COMMENT 'Lease held by the drain pass that claimed this row — two concurrent drains can never double-send',
                ClaimedAt      DATETIME        NULL DEFAULT NULL COMMENT 'UTC lease start; a delivering row older than the lease timeout is reclaimable (crashed worker recovery)',
                LastHttpStatus SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Last response status; 0 = transport error (DNS/TLS/timeout/refused)',
                LastError      VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Sanitised last failure detail for triage — never response bodies, never secrets',
                LastAttemptAt  DATETIME        NULL DEFAULT NULL,
                DeliveredAt    DATETIME        NULL DEFAULT NULL COMMENT 'UTC instant of the 2xx',
                AttemptLogJson JSON            NULL DEFAULT NULL COMMENT 'Bounded per-attempt history [{at,status,ms,err}] — capped at the attempt ceiling, display-only',
                ExpiresAt      DATETIME        NOT NULL COMMENT 'Retention TTL (enqueue + 30 days) — prune-eligible past this',
                CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_Event_Subscription (EventId, SubscriptionId),
                INDEX idx_Due          (Channel, Status, NextAttemptAt),
                INDEX idx_Subscription (SubscriptionId, CreatedAt),
                INDEX idx_Expires      (ExpiresAt),

                CONSTRAINT fk_WebhookDel_Event FOREIGN KEY (EventId)        REFERENCES tblWebhookEvents(Id)        ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_WebhookDel_Sub   FOREIGN KEY (SubscriptionId) REFERENCES tblWebhookSubscriptions(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-subscription webhook delivery queue, retry state and dead-letter surface (#1909). uq_Event_Subscription = fan-out idempotency; idx_Due = the drain claim predicate.'"
        );
        _migWH_output("  [OK] Created tblWebhookDeliveries.");
    }

    _migWH_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migWH_output("ERROR: " . $e->getMessage());
}
