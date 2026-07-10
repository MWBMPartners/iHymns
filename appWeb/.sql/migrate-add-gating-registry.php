<?php

declare(strict_types=1);

/**
 * iHymns — Admin-configurable feature gating registry (#1481 P1 + P2 schema)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates BOTH tables the admin-configurable gating feature needs, in one pass
 * (CLAUDE.md rule #20 — design the final DDL up front so a feature family ships
 * as one additive batch, not dribbled ALTERs):
 *
 *   - tblGatingCapabilities (P1, BUILT + ENFORCED this release) — admin-defined
 *     capability DEFINITIONS. Each enabled row is unioned into the code
 *     TIER_CAPS registry by tierCapsEffective() in
 *     includes/access_tier_validation.php, so a Global Admin can define
 *     "CanRequestSongs" from the UI and have it appear on the /manage/tiers
 *     matrix + the access_tiers/tier_check API + checkTierAccess() enforcement
 *     with ZERO new code. Per-tier VALUES still ride the existing
 *     tblAccessTiers.Capabilities JSON column (#1352) — this migration does
 *     NOT touch tblAccessTiers.
 *
 *   - tblGatingRules (P2 schema-only THIS release — the enforcement loop that
 *     reads this table is a SEPARATE, not-yet-built change). Maps a
 *     DB-defined capability to a behaviour-kind (e.g. strip_payload_keys /
 *     drop_media_kinds) + kind-specific JSON params. Created now so P2 never
 *     needs a second migration (rule #20). Rows are born Enabled=0; the table
 *     is inert until the P2 enforcement loop ships AND both
 *     content_gating_enabled + feature_gating_rules_enabled are '1'.
 *
 * VARCHAR-not-ENUM (rule #20): CapKey / BehaviorKind / Scope / Source are all
 * growable vocabularies validated app-side, never MySQL ENUMs — a value-add to
 * an ENUM is itself an ALTER, exactly the "second migration" this one-pass
 * batch avoids.
 *
 * DORMANT + ADDITIVE: with tblGatingCapabilities empty (the state immediately
 * after this migration runs), tierCapsEffective() === TIER_CAPS byte-for-byte
 * — every surface (matrix, API emit, enforcement) behaves exactly as before
 * #1481. See tests/php/test-gating-registry.php for the no-op proof.
 *
 * @migration-adds tblGatingCapabilities
 * @migration-adds tblGatingRules
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-gating-registry.php
 *   Web:  /manage/setup-database → "Admin-configurable feature gating" button
 *
 * @requires PHP 8.1+ with mysqli
 */

/* ELI5: are we being run from the command line, or clicked in the web dashboard?
   WHY: a web run needs plain-text headers; the dashboard sets a sentinel constant so
   the migration runner can capture our echo output instead of leaking raw HTTP headers. */
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* ELI5: print one line, in CLI or HTML form depending on where we run.
   WHY: a single output helper keeps the migration's progress legible in both the
   terminal and the dashboard's live <pre> capture (mirrors every other migrate-*.php). */
function _migGR_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

/* ELI5: does this table already exist?
   WHY: makes the migration idempotent — re-running it is a safe no-op. INFORMATION_SCHEMA
   is queried with a bound param (rule #5), never string-interpolation.
   https://dev.mysql.com/doc/refman/8.0/en/information-schema-tables-table.html */
function _migGR_tableExists(\mysqli $db, string $t): bool
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

/* ELI5: load the MySQL login details written by install.php.
   WHY: the migration runs standalone (CLI or web) so it needs its own connection; it
   cannot assume the app bootstrap has run. Bail clearly if the install step is missing. */
$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migGR_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migGR_output("");
_migGR_output("=== iHymns — Admin-configurable feature gating registry (#1481) ===");

/* ELI5: open the database connection.
   WHY: mysqli runs under MYSQLI_REPORT_ERROR|STRICT app-wide, so a bad statement THROWS
   — we catch the connect failure to print a friendly line instead of a stack trace. */
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migGR_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migGR_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- tblGatingCapabilities (P1 — built + enforced this release) ---- */
    if (_migGR_tableExists($mysql, 'tblGatingCapabilities')) {
        _migGR_output("  [SKIP] tblGatingCapabilities already present.");
    } else {
        /* DDL is byte-identical to appWeb/.sql/schema.sql (rule #19). */
        $mysql->query(
            "CREATE TABLE tblGatingCapabilities (
                Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                CapKey        VARCHAR(40)  NOT NULL COMMENT 'PascalCase capability key, grammar ^Can[A-Z][A-Za-z0-9]{1,29}\$ (app-validated) -- unioned into TIER_CAPS by tierCapsEffective(); a code-key collision (case-insensitive, incl. camelCase) is ignored, code wins',
                Label         VARCHAR(30)  NOT NULL COMMENT 'Short column-header label shown on /manage/tiers and /manage/feature-gating',
                Description   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Checkbox tooltip / hint text',
                DefaultValue  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Assumed 0/1 value when a tier has not set this cap (mirrors the TIER_CAPS 4-tuple default slot)',
                EmitInApi     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = surfaced as a camelCase key on the public access_tiers API emit (rule #28 contract stays additive-only -- the 7 built-ins are unaffected)',
                Enabled       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = soft-disabled -- excluded from the tierCapsEffective() union but kept for audit/history; hard-delete is separate and blocked while tblGatingRules references the CapKey',
                SortOrder     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order on the /manage/tiers matrix and the /manage/feature-gating list',
                Source        VARCHAR(20)  NOT NULL DEFAULT 'admin' COMMENT 'provenance vocabulary -- VARCHAR not ENUM (rule #20); \"admin\" today, room to grow (e.g. a future bulk-import source) with no ALTER',
                CreatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who defined this capability',
                UpdatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who last edited it',
                CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_GatingCap_CapKey (CapKey),
                INDEX      idx_GatingCap_Enabled (Enabled, SortOrder),

                CONSTRAINT fk_GatingCap_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_GatingCap_UpdatedBy FOREIGN KEY (UpdatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Admin-defined gating capabilities (#1481 P1) -- unioned into TIER_CAPS by tierCapsEffective(); a code-key collision (case-insensitive, incl. camelCase) is ignored (code wins).'"
        );
        _migGR_output("  [OK] Created tblGatingCapabilities.");
    }

    /* ---- tblGatingRules (P2 schema — created now per rule #20, enforcement loop is a separate build) ---- */
    if (_migGR_tableExists($mysql, 'tblGatingRules')) {
        _migGR_output("  [SKIP] tblGatingRules already present.");
    } else {
        /* DDL is byte-identical to appWeb/.sql/schema.sql (rule #19). */
        $mysql->query(
            "CREATE TABLE tblGatingRules (
                Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                CapKey        VARCHAR(40)  NOT NULL COMMENT 'FK tblGatingCapabilities.CapKey -- P2 v1 rules key on DB-defined caps ONLY, never the 7 built-ins (preserves the Service-Mode presence grant + the CCLI gate)',
                BehaviorKind  VARCHAR(30)  NOT NULL COMMENT 'strip_payload_keys | drop_media_kinds -- VARCHAR not ENUM (rule #20); catalog lives in includes/gating_rules.php (P2, not yet built)',
                ParamsJson    JSON         NULL DEFAULT NULL COMMENT 'Kind-specific parameters, e.g. {\"keys\":[...]} or {\"kinds\":[...]} -- NULL is treated as empty by the P2 enforcement loop',
                Scope         VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'Reserved scoping discriminator (e.g. a future per-surface/per-songbook scope) -- NOT NULL so it can sit inside a UNIQUE key without NULL-permits-duplicates (rule #20 lesson from tblApiKeyUsage)',
                Enabled       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Rules are born DISABLED -- the P2 enforcement loop only applies Enabled=1 rows, and only when feature_gating_rules_enabled=1 AND content_gating_enabled=1 (two-flag nested topology)',
                SortOrder     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Apply order when multiple rules target the same capability',
                Source        VARCHAR(20)  NOT NULL DEFAULT 'admin' COMMENT 'provenance vocabulary -- VARCHAR not ENUM (rule #20)',
                CreatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who defined this rule',
                UpdatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who last edited it',
                CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_GatingRule (CapKey, BehaviorKind, Scope),
                INDEX      idx_GatingRule_Enabled (Enabled, SortOrder),

                CONSTRAINT fk_GatingRule_CapKey    FOREIGN KEY (CapKey)    REFERENCES tblGatingCapabilities(CapKey) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_GatingRule_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)              ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_GatingRule_UpdatedBy FOREIGN KEY (UpdatedBy) REFERENCES tblUsers(Id)              ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Admin-defined enforcement rules (#1481 P2, schema created in P1 one-pass per rule #20) -- cap-to-behaviour-kind mapping; DORMANT until P2 ships the enforcement loop and both feature flags are on.'"
        );
        _migGR_output("  [OK] Created tblGatingRules.");
    }

    _migGR_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migGR_output("ERROR: " . $e->getMessage());
}
