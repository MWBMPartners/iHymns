<?php

declare(strict_types=1);

/**
 * iHymns — MWBM-IntAppsAPI gateway sync cache (Epic #1725, this migration #1727)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates ONE dormant table, `tblIntAppsSync` — the local snapshot + refresh
 * bookkeeping for the MWBM-IntAppsAPI gateway integration (server-proxied
 * feature-flag kill switches; full design in
 * .claude/intappsapi-integration-plan.md §4, pre-launch delta in
 * .claude/wave4-prelaunch-plan.md §4/§6 Block D). Nothing reads or writes this
 * table until BOTH (a) `includes/intapps_client.php` lands (a later commit in
 * this same batch) AND (b) an admin lists the current channel in
 * `tblAppSettings.intappsapi_enabled_channels`. With the table merely CREATED
 * and that setting absent (the shipped default), every page on this branch is
 * a byte-identical no-op — proved in the batch's final commit.
 *
 * ONE-PASS SCHEMA (rule #20 — design the final shape once, ship it dormant):
 * the UNIQUE key is (Scope, Channel, AppSlug), all three NOT NULL VARCHAR so
 * none of them can dodge the key via NULL-permits-duplicates:
 *
 *   - Scope   — which gateway resource this row caches: 'features' is the
 *     only scope any code reads today; 'updates' / 'notifications' / 'status'
 *     are reserved so a later consumer is a DATA change, never a migration.
 *   - Channel — the three-docroot discriminator (serviceMode_channel() /
 *     ihymns_environment()). Default operation uses only the empty-string
 *     row (one gateway app slug, one MySQL, so a shared snapshot is correct
 *     and avoids tripling fetch traffic against the gateway's per-IP rate
 *     limit — the three docroots share one egress IP). Reserved so a future
 *     per-channel gateway app is a data change, not an ALTER (rule #26's
 *     cross-env lesson).
 *   - AppSlug — reserved multiplicity for a SECOND registered gateway app
 *     (per-platform, e.g. `ihymns-apple`, or per-env). Stress-test remedy 5:
 *     without this column a second slug would force the second ALTER rule
 *     #20 exists to forbid. Default operation uses only the empty-string
 *     value (the primary `ihymns` app).
 *
 * No ENUM anywhere (rule #20): Scope and LastErrorCode are both growable,
 * app-validated vocabularies, never MySQL ENUMs — an ENUM value-add is itself
 * an ALTER, exactly the "second migration" this one-pass batch avoids.
 * FetchedAt/AttemptedAt/RefreshLockedUntil are DATETIME, not TIMESTAMP (house
 * convention for idempotency/bookkeeping timestamps, #1066).
 *
 * FAIL-OPEN CONTRACT this table exists to serve (enforced entirely in
 * includes/intapps_client.php, not here): a failed or malformed fetch NEVER
 * overwrites PayloadJson/FetchedAt — the last-known-good snapshot (or, cold,
 * the caller's compiled-in default) is always what a consumer sees. Every
 * `tblIntAppsSync` read/write in the client is wrapped in try/catch against
 * `\mysqli_sql_exception` so an environment where this migration has NOT yet
 * run, but `intappsapi_enabled_channels` already lists the channel (three
 * docroots share one DB; migrations are web-run, not auto-applied — the two
 * can legitimately be out of step), degrades to the compiled default instead
 * of throwing under mysqli STRICT on the public home page (wave4-prelaunch
 * plan §5 defect D1 — the pre-launch BLOCKER).
 *
 * DORMANT + ADDITIVE: creating this table changes the behaviour of zero
 * existing reads — nothing references `tblIntAppsSync` before this batch's
 * client-module commit, and that module is itself dormant until the channel
 * allow-list names the current channel. Idempotent — safe to re-run.
 *
 * @migration-adds tblIntAppsSync
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-intapps-sync.php
 *   Web:  /manage/setup-database → "IntAppsAPI Gateway Sync Cache" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/intappsapi-integration-plan.md §4 (full design)
 * @see .claude/wave4-prelaunch-plan.md §4/§6 Block D (pre-launch delta + commit plan)
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
function _migIAS_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

/* ELI5: does this table already exist?
   WHY: makes the migration idempotent — re-running it is a safe no-op. INFORMATION_SCHEMA
   is queried with a bound param (rule #5), never string-interpolation.
   https://dev.mysql.com/doc/refman/8.0/en/information-schema-tables-table.html */
function _migIAS_tableExists(\mysqli $db, string $t): bool
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
if (!file_exists($credFile)) { _migIAS_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migIAS_output("");
_migIAS_output("=== iHymns — MWBM-IntAppsAPI Gateway Sync Cache (#1725/#1727) ===");

/* ELI5: open the database connection.
   WHY: mysqli runs under MYSQLI_REPORT_ERROR|STRICT app-wide, so a bad statement THROWS
   — we catch the connect failure to print a friendly line instead of a stack trace. */
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migIAS_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migIAS_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migIAS_tableExists($mysql, 'tblIntAppsSync')) {
        _migIAS_output("  [SKIP] tblIntAppsSync already present.");
    } else {
        /* DDL is byte-identical to appWeb/.sql/schema.sql (rule #19). */
        $mysql->query(
            "CREATE TABLE tblIntAppsSync (
                Id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate PK',
                Scope               VARCHAR(30)  NOT NULL COMMENT 'Gateway data family this row caches: features|updates|notifications|status. App-validated vocabulary (rule #20: VARCHAR, never ENUM); only \"features\" is read today',
                Channel             VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Docroot discriminator (serviceMode_channel/ihymns_environment value); empty string = shared across all three docroots. Reserved multiplicity per rule #20/#26; default operation uses only the empty-string row',
                AppSlug             VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'Gateway app slug this row caches; empty string = the primary ihymns app. Reserved multiplicity for a second registered gateway app, per-platform or per-env (rule #20); default operation uses only the empty-string value',
                PayloadJson         JSON         NULL COMMENT 'Last-known-GOOD decoded gateway envelope data. Never overwritten by a failed or malformed fetch',
                FetchedAt           DATETIME     NULL COMMENT 'UTC time of last SUCCESSFUL fetch. NULL = cold. DATETIME not TIMESTAMP (house rule, #1066)',
                AttemptedAt         DATETIME     NULL COMMENT 'UTC time of last attempt, success or failure -- drives exponential backoff',
                RefreshLockedUntil  DATETIME     NULL COMMENT 'Single-flight lock: a request that wins the conditional UPDATE owns the refresh until this UTC time; stale locks self-expire',
                LastHttpStatus      SMALLINT UNSIGNED NULL COMMENT 'HTTP status of the last attempt; NULL = transport-level failure (no answer at all)',
                LastErrorCode       VARCHAR(50)  NULL COMMENT 'Gateway envelope error.code of the last failure (ACCESS_DENIED, RATE_LIMITED, BAD_SHAPE, ...) for the admin status card; never the message prose',
                ConsecutiveFailures INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failure streak since the last success; backoff = LEAST(300*2^n, 3600) seconds',

                PRIMARY KEY (Id),
                UNIQUE KEY uq_IntAppsSync_ScopeChannelApp (Scope, Channel, AppSlug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='IntAppsAPI gateway local snapshot + refresh bookkeeping (#1725/#1727). Dormant until tblAppSettings.intappsapi_enabled_channels names the current channel; fail-open contract lives in includes/intapps_client.php.'"
        );
        _migIAS_output("  [OK] Created tblIntAppsSync.");
    }

    _migIAS_output("Migration complete. tblIntAppsSync is DORMANT -- nothing reads or writes");
    _migIAS_output("it until includes/intapps_client.php ships AND an admin enables a channel");
    _migIAS_output("on /manage/configuration.");
} catch (\mysqli_sql_exception $e) {
    _migIAS_output("ERROR: " . $e->getMessage());
}
