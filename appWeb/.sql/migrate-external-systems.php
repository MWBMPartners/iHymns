<?php

declare(strict_types=1);

/**
 * iHymns — External-system integration hook (Service Mode follow-on, #1325 / #1327)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * A forward-looking, DORMANT schema hook so iHymns entities (organisations,
 * venues, service schedules — and later others) can be linked to / synced with
 * an EXTERNAL system in future. The first target is "WebMS-Intra"; the design
 * is system-agnostic so a second system never needs an ALTER (rule #20). Built
 * now because the Service-Mode venue/schedule schema is landing in the same
 * batch — adding the integration hook up front avoids a second migration.
 *
 * DESIGN (validated by an adversarial design workflow — see
 * .claude/live-congregant-strategy.md §"External-system integration"):
 *   - tblExternalSystems — a controlled-vocabulary REGISTRY of systems we hold
 *     an identity in (sibling of tblExternalLinkTypes which classifies public
 *     LINKS). System keys live in DATA, never hard-coded in PHP (rule #15).
 *   - tblOrganisationExternalRefs / tblOrgVenueExternalRefs /
 *     tblOrgServiceScheduleExternalRefs — per-entity DEDICATED mapping tables
 *     (rule #15 forbids a generic polymorphic EntityType+EntityId FK table;
 *     the generic "candidate A" was rejected because its numeric EntityId can't
 *     address SongId VARCHAR keys and a no-FK polymorphism orphan-rots on
 *     delete). Each FKs the registry + CASCADEs from its entity.
 *
 * Forward-looking columns reserved now (dormant) so the sync engine never
 * forces a second migration: SyncStatus / SyncDirection (VARCHAR, app-validated,
 * NOT ENUM); (Source, SourceRef) UNIQUE for idempotent external re-import;
 * (SystemId, ExternalId) UNIQUE = one external record ↔ one iHymns row per
 * system; LocalHash + ExternalEtag for optimistic-concurrency conflict
 * detection; LastError + LastErrorAt for operator triage; DeletedAt for
 * soft-unlink (distinct from FK-CASCADE hard delete); MetaJson for loss-free
 * round-trip. LastSyncedAt / LastErrorAt / DeletedAt are DATETIME not TIMESTAMP.
 *
 * SECURITY / PRIVACY (do NOT skip when the sync engine is built — dormant now):
 *   - tblExternalSystems.AuthScope is a secret-NAME hint only, NEVER a secret.
 *   - Org/venue address + service times = identifiable presence patterns for a
 *     faith community (GDPR Art. 9-adjacent). Outbound sync needs a lawful
 *     basis + DPA; default SyncDirection is 'inbound' until one exists.
 *   - The 3 docroots SHARE ONE MySQL — the future sync engine MUST gate live
 *     runs to production (or a sandbox BaseUrl) so alpha/beta can't exfiltrate
 *     real congregation data. Route sync history to tblActivityLog WITHOUT raw
 *     PII payloads.
 *
 * Additive + DORMANT + IDEMPOTENT (table-existence guarded; the seed uses
 * INSERT … ON DUPLICATE KEY UPDATE). Runs AFTER migrate-org-venues.php (FKs).
 *
 * @migration-adds tblExternalSystems
 * @migration-adds tblOrganisationExternalRefs
 * @migration-adds tblOrgVenueExternalRefs
 * @migration-adds tblOrgServiceScheduleExternalRefs
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-external-systems.php
 *   Web:  /manage/setup-database → "External-system integration hook" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migXS_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migXS_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migXS_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migXS_output("");
_migXS_output("=== iHymns — External-system integration hook (#1327) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migXS_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migXS_output("Connected to MySQL: " . DB_NAME);

try {
    /* 1) Registry of external systems. */
    if (_migXS_tableExists($mysql, 'tblExternalSystems')) {
        _migXS_output("  [SKIP] tblExternalSystems already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblExternalSystems (
                Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                SystemKey     VARCHAR(60)   NOT NULL COMMENT 'Stable machine key (e.g. webms-intra). App resolves systems by this — NEVER hard-code the key list in PHP (rule #15)',
                Name          VARCHAR(120)  NOT NULL COMMENT 'Curator-facing display name (e.g. WebMS Intranet)',
                Description   VARCHAR(255)  NULL DEFAULT NULL COMMENT 'What this system is / what the mapping means',
                BaseUrl       VARCHAR(255)  NULL DEFAULT NULL COMMENT 'Base URL to build a deep link from a stored ExternalId; {id} placeholder substituted app-side',
                Kind          VARCHAR(30)   NOT NULL DEFAULT 'sync' COMMENT 'sync | directory | finance | rota | identity | other — app-validated, VARCHAR not ENUM (rule #20)',
                AuthScope     VARCHAR(40)   NULL DEFAULT NULL COMMENT 'Credential/realm hint the sync layer maps to a secret NAME — never the secret itself',
                IsActive      TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = registered but paused; mappings persist, sync skips',
                DisplayOrder  INT UNSIGNED  NOT NULL DEFAULT 0,
                CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_SystemKey (SystemKey),
                INDEX      idx_Active   (IsActive),
                INDEX      idx_Kind     (Kind, DisplayOrder)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Registry of external SYSTEMS an iHymns entity can be mapped/synced into (WebMS-Intra, …). SystemKey UNIQUE so keys are never hard-coded (rule #15); sibling of tblExternalLinkTypes (#833).'"
        );
        _migXS_output("  [OK] Created tblExternalSystems.");
    }

    /* Seed the first system row so the per-entity ref FKs (RESTRICT) have a
       target — without this the first INSERT throws under STRICT. Idempotent. */
    $mysql->query(
        "INSERT INTO tblExternalSystems (SystemKey, Name, Description, Kind, IsActive, DisplayOrder)
         VALUES ('webms-intra', 'WebMS Intranet',
                 'Reserved integration target (#1327) — dormant until a sync engine + DPA exist.',
                 'sync', 0, 0)
         ON DUPLICATE KEY UPDATE SystemKey = SystemKey"
    );
    _migXS_output("  [OK] Seeded the 'webms-intra' system row (paused/dormant).");

    /* 2) Per-organisation external refs. */
    if (_migXS_tableExists($mysql, 'tblOrganisationExternalRefs')) {
        _migXS_output("  [SKIP] tblOrganisationExternalRefs already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblOrganisationExternalRefs (
                Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                OrgId         INT UNSIGNED  NOT NULL COMMENT 'FK to tblOrganisations.Id — the iHymns org',
                SystemId      INT UNSIGNED  NOT NULL COMMENT 'FK to tblExternalSystems.Id — which external system this id lives in',
                ExternalId    VARCHAR(190)  NOT NULL COMMENT 'Primary identifier of the org WITHIN the external system. 190 = utf8mb4 index-safe',
                ExternalSlug  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional human/slug handle in the external system',
                SyncStatus    VARCHAR(20)   NOT NULL DEFAULT 'linked' COMMENT 'linked | pending | synced | conflict | error | deprecated — app-validated, VARCHAR not ENUM (rule #20)',
                SyncDirection VARCHAR(20)   NOT NULL DEFAULT 'inbound' COMMENT 'none | inbound | outbound | bidirectional — VARCHAR not ENUM; inbound-first until a DPA exists',
                Source        VARCHAR(100)  NOT NULL DEFAULT 'webms-intra' COMMENT 'Provenance of the mapping row; part of the idempotency key',
                SourceRef     VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External primary id from the Source push for idempotent re-import; NULL for a manual link (multiple NULLs coexist, rule #20)',
                LocalHash     VARCHAR(64)   NULL DEFAULT NULL COMMENT 'Fingerprint of the iHymns row at last sync — optimistic-concurrency conflict detection',
                ExternalEtag  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External side version/etag at last sync — conflict detection',
                LastSyncedAt  DATETIME      NULL DEFAULT NULL COMMENT 'Last successful sync (DATETIME not TIMESTAMP — rule #20)',
                LastError     VARCHAR(500)  NULL DEFAULT NULL COMMENT 'Last sync failure detail for operator triage (no secrets/PII)',
                LastErrorAt   DATETIME      NULL DEFAULT NULL,
                DeletedAt     DATETIME      NULL DEFAULT NULL COMMENT 'Soft-unlink (operator removed the link) — distinct from FK-CASCADE hard delete',
                MetaJson      JSON          NULL DEFAULT NULL COMMENT 'Lossless extra attrs from the external system for round-trip re-export',
                CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who created the link (NULL for system import)',
                CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Org_System_Ext (OrgId, SystemId, ExternalId),
                UNIQUE KEY uq_System_Ext     (SystemId, ExternalId),
                UNIQUE KEY uq_SourceRef      (Source, SourceRef),
                INDEX      idx_Org           (OrgId),
                INDEX      idx_System        (SystemId),
                INDEX      idx_Status        (SyncStatus),
                CONSTRAINT fk_OrgExtRef_Org       FOREIGN KEY (OrgId)     REFERENCES tblOrganisations(Id)  ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_OrgExtRef_System    FOREIGN KEY (SystemId)  REFERENCES tblExternalSystems(Id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_OrgExtRef_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)          ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-org external-system identity map (rule #15 dedicated ref table). (Source,SourceRef) UNIQUE = idempotent re-import (rule #20); SystemId in the keys = multi-system without an ALTER.'"
        );
        _migXS_output("  [OK] Created tblOrganisationExternalRefs.");
    }

    /* 3) Per-venue external refs (OrgId denorm mirrors tblOrgServiceSchedules). */
    if (_migXS_tableExists($mysql, 'tblOrgVenueExternalRefs')) {
        _migXS_output("  [SKIP] tblOrgVenueExternalRefs already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblOrgVenueExternalRefs (
                Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                VenueId       INT UNSIGNED  NOT NULL COMMENT 'FK to tblOrgVenues.Id — the iHymns venue',
                OrgId         INT UNSIGNED  NOT NULL COMMENT 'Denorm of the venue org (app-derived, never client-trusted) for cheap org-scoped queries',
                SystemId      INT UNSIGNED  NOT NULL COMMENT 'FK to tblExternalSystems.Id',
                ExternalId    VARCHAR(190)  NOT NULL COMMENT 'Primary identifier of the venue WITHIN the external system',
                ExternalSlug  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional human/slug handle in the external system',
                SyncStatus    VARCHAR(20)   NOT NULL DEFAULT 'linked' COMMENT 'linked | pending | synced | conflict | error | deprecated — app-validated, VARCHAR not ENUM (rule #20)',
                SyncDirection VARCHAR(20)   NOT NULL DEFAULT 'inbound' COMMENT 'none | inbound | outbound | bidirectional — VARCHAR not ENUM',
                Source        VARCHAR(100)  NOT NULL DEFAULT 'webms-intra' COMMENT 'Provenance of the mapping row; part of the idempotency key',
                SourceRef     VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External primary id from the Source push for idempotent re-import; NULL for a manual link',
                LocalHash     VARCHAR(64)   NULL DEFAULT NULL COMMENT 'Fingerprint of the iHymns row at last sync — conflict detection',
                ExternalEtag  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External side version/etag at last sync — conflict detection',
                LastSyncedAt  DATETIME      NULL DEFAULT NULL COMMENT 'Last successful sync (DATETIME not TIMESTAMP — rule #20)',
                LastError     VARCHAR(500)  NULL DEFAULT NULL COMMENT 'Last sync failure detail for operator triage (no secrets/PII)',
                LastErrorAt   DATETIME      NULL DEFAULT NULL,
                DeletedAt     DATETIME      NULL DEFAULT NULL COMMENT 'Soft-unlink — distinct from FK-CASCADE hard delete',
                MetaJson      JSON          NULL DEFAULT NULL COMMENT 'Lossless extra attrs for round-trip re-export',
                CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who created the link (NULL for system import)',
                CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Venue_System_Ext (VenueId, SystemId, ExternalId),
                UNIQUE KEY uq_System_Ext       (SystemId, ExternalId),
                UNIQUE KEY uq_SourceRef        (Source, SourceRef),
                INDEX      idx_Venue           (VenueId),
                INDEX      idx_Org             (OrgId),
                INDEX      idx_System          (SystemId),
                INDEX      idx_Status          (SyncStatus),
                CONSTRAINT fk_VenueExtRef_Venue     FOREIGN KEY (VenueId)   REFERENCES tblOrgVenues(Id)      ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_VenueExtRef_Org       FOREIGN KEY (OrgId)     REFERENCES tblOrganisations(Id)  ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_VenueExtRef_System    FOREIGN KEY (SystemId)  REFERENCES tblExternalSystems(Id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_VenueExtRef_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)          ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-venue external-system identity map (rule #15 dedicated ref table). (Source,SourceRef) UNIQUE = idempotent re-import (rule #20); OrgId denorm mirrors tblOrgServiceSchedules.'"
        );
        _migXS_output("  [OK] Created tblOrgVenueExternalRefs.");
    }

    /* 4) Per-service-schedule external refs (full org/venue/schedule scope in
          one pass so a schedule sync never forces a third migration). */
    if (_migXS_tableExists($mysql, 'tblOrgServiceScheduleExternalRefs')) {
        _migXS_output("  [SKIP] tblOrgServiceScheduleExternalRefs already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblOrgServiceScheduleExternalRefs (
                Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                ScheduleId    INT UNSIGNED  NOT NULL COMMENT 'FK to tblOrgServiceSchedules.Id — the iHymns service time',
                OrgId         INT UNSIGNED  NOT NULL COMMENT 'Denorm of the schedule org (app-derived, never client-trusted) for cheap org-scoped queries',
                SystemId      INT UNSIGNED  NOT NULL COMMENT 'FK to tblExternalSystems.Id',
                ExternalId    VARCHAR(190)  NOT NULL COMMENT 'Primary identifier of the service/event WITHIN the external system',
                ExternalSlug  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional human/slug handle in the external system',
                SyncStatus    VARCHAR(20)   NOT NULL DEFAULT 'linked' COMMENT 'linked | pending | synced | conflict | error | deprecated — app-validated, VARCHAR not ENUM (rule #20)',
                SyncDirection VARCHAR(20)   NOT NULL DEFAULT 'inbound' COMMENT 'none | inbound | outbound | bidirectional — VARCHAR not ENUM',
                Source        VARCHAR(100)  NOT NULL DEFAULT 'webms-intra' COMMENT 'Provenance of the mapping row; part of the idempotency key',
                SourceRef     VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External primary id from the Source push for idempotent re-import; NULL for a manual link',
                LocalHash     VARCHAR(64)   NULL DEFAULT NULL COMMENT 'Fingerprint of the iHymns row at last sync — conflict detection',
                ExternalEtag  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External side version/etag at last sync — conflict detection',
                LastSyncedAt  DATETIME      NULL DEFAULT NULL COMMENT 'Last successful sync (DATETIME not TIMESTAMP — rule #20)',
                LastError     VARCHAR(500)  NULL DEFAULT NULL COMMENT 'Last sync failure detail for operator triage (no secrets/PII)',
                LastErrorAt   DATETIME      NULL DEFAULT NULL,
                DeletedAt     DATETIME      NULL DEFAULT NULL COMMENT 'Soft-unlink — distinct from FK-CASCADE hard delete',
                MetaJson      JSON          NULL DEFAULT NULL COMMENT 'Lossless extra attrs for round-trip re-export',
                CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who created the link (NULL for system import)',
                CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Sched_System_Ext (ScheduleId, SystemId, ExternalId),
                UNIQUE KEY uq_System_Ext       (SystemId, ExternalId),
                UNIQUE KEY uq_SourceRef        (Source, SourceRef),
                INDEX      idx_Sched           (ScheduleId),
                INDEX      idx_Org             (OrgId),
                INDEX      idx_System          (SystemId),
                INDEX      idx_Status          (SyncStatus),
                CONSTRAINT fk_SchedExtRef_Sched     FOREIGN KEY (ScheduleId) REFERENCES tblOrgServiceSchedules(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_SchedExtRef_Org       FOREIGN KEY (OrgId)      REFERENCES tblOrganisations(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_SchedExtRef_System    FOREIGN KEY (SystemId)   REFERENCES tblExternalSystems(Id)   ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_SchedExtRef_CreatedBy FOREIGN KEY (CreatedBy)  REFERENCES tblUsers(Id)             ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-service-schedule external-system identity map (rule #15 dedicated ref table). (Source,SourceRef) UNIQUE = idempotent re-import (rule #20); OrgId denorm mirrors tblOrgServiceSchedules.'"
        );
        _migXS_output("  [OK] Created tblOrgServiceScheduleExternalRefs.");
    }

    _migXS_output("  Tables ship empty + dormant; no read/write code consumes them yet. The WebMS-Intra sync engine is future, gated work (#1327) requiring a DPA + production-only run.");
    _migXS_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migXS_output("ERROR: " . $e->getMessage());
}
