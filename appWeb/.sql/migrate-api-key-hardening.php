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
