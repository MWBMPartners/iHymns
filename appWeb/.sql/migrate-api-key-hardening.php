<?php

declare(strict_types=1);

/**
 * iHymns — API-key hardening: rate limits, usage, idempotency (#1066 Theme B)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Today a bad or repeated push to the ingest endpoint can DOS it or duplicate
 * work — there is no per-key quota and no retry safety. This migration adds:
 *   - tblApiKeys.RateLimitPerMin / RateLimitPerDay — per-key quotas (NULL = none).
 *   - tblApiKeyUsage         — rolling rate-limit counters. The Scope column in
 *     the unique key reserves per-endpoint limiting without a future migration.
 *   - tblApiKeyIdempotency   — cached responses keyed by a client Idempotency-Key
 *     so retried POSTs are safe. ExpiresAt is DATETIME (not TIMESTAMP) to avoid
 *     MySQL 8 implicit-default magic.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column/table existence guarded).
 *
 * NOT DESTRUCTIVE. Two NULLable columns and two new tables; no existing key,
 * column or row is touched. The new quotas default to NULL = unlimited, so
 * applying this changes the behaviour of exactly nothing until an admin sets a
 * number. The "undo" is DROP the two tables and the two columns.
 *
 * HOW IDEMPOTENCY IS ACHIEVED — four independent guards, one per object:
 * _migApiHard_addCol() SHOW COLUMNS-probes before each ALTER, and
 * _migApiHard_tableExists() probes before each CREATE. A re-run prints four
 * [SKIP] lines. The guards must stay per-object rather than one guard around
 * the lot, because these four steps land independently: a run that added the
 * columns and then failed on a CREATE has to be resumable.
 *
 * ⚠ NOTE ON THE REGISTRY PROBE: the card's probe is single-object —
 * `!columnExists('tblApiKeys','RateLimitPerMin')` (migration-registry.php) —
 * and RateLimitPerMin is the FIRST thing this script creates. So a run that
 * added the columns and then threw creating tblApiKeyUsage would flip the card
 * to APPLIED with two of the four objects missing. Everything downstream is
 * dormant, so nothing breaks today, but per CLAUDE.md rule #19 a migration
 * creating several objects should carry the multi-object OR-probe form (compare
 * the 'line-enrichment' and 'song-identity-map' entries, which do). Widening it
 * is a registry change, not a change to this file.
 *
 * OPERATOR VIEW: /manage/setup-database → card "API-key hardening — rate limits
 * + idempotency (#1066)" → button "Run API-Key Hardening Migration". A first run
 * prints four [OK] lines; a repeat prints four [SKIP]s.
 *
 * SCHEMA MIRROR: the two columns and both CREATE TABLEs are mirrored in
 * appWeb/.sql/schema.sql (what a FRESH install reads instead of running this).
 * They must stay byte-identical, COMMENT text included, or fresh and migrated
 * installs diverge and the Schema Audit page (#518) flags it (rule #19). The
 * `\"\"` in the Scope COMMENT below is only PHP double-quote escaping — the SQL
 * that reaches MySQL carries plain `""`, matching schema.sql exactly.
 *
 * @migration-adds tblApiKeys.RateLimitPerMin
 * @migration-adds tblApiKeys.RateLimitPerDay
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-api-key-hardening.php
 *   Web:  /manage/setup-database → "API-key hardening (rate limits + idempotency)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migApiHard_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migApiHard_addCol(\mysqli $db, string $table, string $col, string $ddl): void {
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    if ($r && $r->num_rows > 0) {
        _migApiHard_output("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migApiHard_output("  [OK] Added {$table}.{$col}.");
}

function _migApiHard_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migApiHard_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migApiHard_output("");
_migApiHard_output("=== iHymns — API-key hardening (#1066 Theme B) ===");
_migApiHard_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migApiHard_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migApiHard_output("Connected to MySQL: " . DB_NAME);

try {
    _migApiHard_output("");
    _migApiHard_output("--- tblApiKeys: rate-limit columns ---");
    _migApiHard_addCol($mysql, 'tblApiKeys', 'RateLimitPerMin',
        "RateLimitPerMin INT UNSIGNED NULL DEFAULT NULL COMMENT 'Max requests/minute; NULL = no limit (#1066 Theme B)' AFTER LastUsedIp");
    _migApiHard_addCol($mysql, 'tblApiKeys', 'RateLimitPerDay',
        "RateLimitPerDay INT UNSIGNED NULL DEFAULT NULL COMMENT 'Max requests/calendar day (UTC); NULL = no limit (#1066 Theme B)' AFTER RateLimitPerMin");

    _migApiHard_output("");
    _migApiHard_output("--- tblApiKeyUsage ---");
    if (_migApiHard_tableExists($mysql, 'tblApiKeyUsage')) {
        _migApiHard_output("  [SKIP] tblApiKeyUsage already present.");
    } else {
        /* The forward-looking bit of this DDL is `Scope` (CLAUDE.md rule #20's
           "reserve a discriminator in the UNIQUE key when multiplicity is
           foreseeable"). Today every counter is global and Scope is ''. The
           moment someone wants "100 ingest writes/min but 5 000 reads/min",
           that is a new value in an existing column — no ALTER, no migration,
           no re-keying of live counter rows. Leaving Scope out and adding it
           later would mean altering a UNIQUE KEY on a hot table.

           It is NOT NULL DEFAULT '' rather than NULLable precisely because it
           is part of uq_KeyWindow: MySQL treats NULLs in a unique index as
           distinct, so a NULLable Scope would let the same global window be
           counted twice and quietly under-enforce the limit.

           WindowStart is DATETIME, not TIMESTAMP — TIMESTAMP is stored as UTC
           and re-rendered in the session time zone, so a server whose zone
           changes would shift every historical window
           (https://dev.mysql.com/doc/refman/8.0/en/datetime.html). idx_Window
           on WindowStart alone exists for the cleanup sweep that deletes
           expired windows across all keys, which cannot use uq_KeyWindow
           (wrong leading column). */
        $mysql->query(
            "CREATE TABLE tblApiKeyUsage (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ApiKeyId     INT UNSIGNED    NOT NULL COMMENT 'FK to tblApiKeys.Id',
                Scope        VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'Per-scope window key; \"\" = global. Reserves per-endpoint limits without a migration',
                WindowType   VARCHAR(10)     NOT NULL COMMENT 'minute | day — rolling-window granularity',
                WindowStart  DATETIME        NOT NULL COMMENT 'Window start (minute-truncated, or UTC day)',
                RequestCount INT UNSIGNED    NOT NULL DEFAULT 1,
                UpdatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_KeyWindow (ApiKeyId, Scope, WindowType, WindowStart),
                INDEX      idx_Window   (WindowStart),

                CONSTRAINT fk_Usage_ApiKey
                    FOREIGN KEY (ApiKeyId) REFERENCES tblApiKeys(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-key rolling rate-limit counters (#1066 Theme B).'"
        );
        _migApiHard_output("  [OK] Created tblApiKeyUsage.");
    }

    _migApiHard_output("");
    _migApiHard_output("--- tblApiKeyIdempotency ---");
    if (_migApiHard_tableExists($mysql, 'tblApiKeyIdempotency')) {
        _migApiHard_output("  [SKIP] tblApiKeyIdempotency already present.");
    } else {
        /* This table is what makes a retried POST safe: the client sends an
           Idempotency-Key header, the server caches the response against it,
           and a retry replays the cached response instead of doing the work
           twice (the Stripe/IETF pattern —
           https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/).

           RequestHash is in the UNIQUE KEY alongside the client's key, which is
           the security-relevant part: reusing an idempotency key with a
           DIFFERENT body must not silently return the old response. Including
           the SHA-256 makes the mismatch visible as a distinct row rather than
           a false cache hit. CHAR(64) is exactly the hex length of SHA-256 —
           fixed-width, so no length prefix and no padding surprises.

           ExpiresAt is DATETIME, not TIMESTAMP, for two reasons: TIMESTAMP tops
           out in 2038, and MySQL applies implicit-default/ON UPDATE magic to
           the first TIMESTAMP column in a table, which would silently rewrite a
           TTL on unrelated updates (rule #20 calls this out by name). It is a
           plain column, so nothing expires on its own — a cleanup sweep using
           idx_Expires has to delete the rows. */
        $mysql->query(
            "CREATE TABLE tblApiKeyIdempotency (
                Id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ApiKeyId       INT UNSIGNED    NOT NULL COMMENT 'FK to tblApiKeys.Id',
                IdempotencyKey VARCHAR(255)    NOT NULL COMMENT 'Client-provided idempotency key',
                RequestHash    CHAR(64)        NOT NULL COMMENT 'SHA-256 of the request body',
                ResponseData   MEDIUMTEXT      NOT NULL COMMENT 'Cached response payload (JSON)',
                HttpStatus     INT UNSIGNED    NOT NULL DEFAULT 200,
                CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ExpiresAt      DATETIME        NOT NULL COMMENT 'TTL expiry (fixed 24h from creation); rows past this are cleanup-eligible',

                UNIQUE KEY uq_KeyHashCombo   (ApiKeyId, IdempotencyKey, RequestHash),
                INDEX      idx_Expires       (ExpiresAt),
                INDEX      idx_ApiKeyCreated (ApiKeyId, CreatedAt),

                CONSTRAINT fk_Idempotency_ApiKey
                    FOREIGN KEY (ApiKeyId) REFERENCES tblApiKeys(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Idempotency-key response cache for safe ingest retries (#1066 Theme B).'"
        );
        _migApiHard_output("  [OK] Created tblApiKeyIdempotency.");
    }

    _migApiHard_output("");
    _migApiHard_output("--- Summary ---");
    _migApiHard_output("  API keys can now carry per-minute/day quotas; usage + idempotency tables");
    _migApiHard_output("  back the 429 enforcement + safe-retry contract (wired in a follow-up).");
    _migApiHard_output("");
    _migApiHard_output("Migration complete.");
} catch (\Throwable $e) {
    _migApiHard_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
