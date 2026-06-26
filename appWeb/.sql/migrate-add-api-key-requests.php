<?php

declare(strict_types=1);

/**
 * iHymns — Self-serve API-key requests (#1064 / API platform Phase D)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Backs the self-serve key-request flow: an admin (entitlement request_api_keys)
 * submits a request for a key (label + justification, scope from a safe self-serve
 * allow-list — catalogue:read); a global admin (manage_api_keys) reviews it on
 * /manage/api-keys and APPROVES (which mints the key, linking ApiKeyId) or REJECTS
 * (with a note). So org-admins get read keys without a global admin minting by hand,
 * while the sensitive scopes (lyrics:ingest, content:gated) stay approval-gated.
 *
 *   - Status   = pending | approved | rejected  (VARCHAR, app-validated — rule #20,
 *                never an ENUM so the workflow can grow states with no 2nd migration).
 *   - ApiKeyId = the tblApiKeys row created when a request is approved (NULL until then).
 *
 * Additive + IDEMPOTENT (table-existence guarded).
 *
 * @migration-adds tblApiKeyRequests.RequesterId
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-api-key-requests.php
 *   Web:  /manage/setup-database → "Self-serve API-key requests" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migAKR_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migAKR_tableExists(\mysqli $db, string $t): bool
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
if (!file_exists($credFile)) { _migAKR_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migAKR_output("");
_migAKR_output("=== iHymns — Self-serve API-key requests (Phase D) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migAKR_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migAKR_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migAKR_tableExists($mysql, 'tblApiKeyRequests')) {
        _migAKR_output("  [SKIP] tblApiKeyRequests already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblApiKeyRequests (
                Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                RequesterId   INT UNSIGNED  NOT NULL COMMENT 'tblUsers.Id of the admin who requested the key',
                Label         VARCHAR(120)  NOT NULL COMMENT 'Human label for the requested key',
                Scope         VARCHAR(255)  NOT NULL DEFAULT 'catalogue:read' COMMENT 'Requested scope (self-serve allow-list, app-validated)',
                Justification VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Why the requester needs the key',
                Status        VARCHAR(20)   NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | rejected (VARCHAR, rule #20)',
                ReviewedBy    INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id of the global admin who reviewed',
                ReviewedAt    DATETIME      NULL DEFAULT NULL COMMENT 'When it was reviewed (UTC)',
                ReviewNote    VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'Optional reviewer note (esp. on rejection)',
                ApiKeyId      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblApiKeys.Id minted on approval',
                CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_Requester (RequesterId),
                INDEX idx_Status    (Status),
                CONSTRAINT fk_ApiKeyReq_Requester FOREIGN KEY (RequesterId) REFERENCES tblUsers(Id)    ON DELETE CASCADE,
                CONSTRAINT fk_ApiKeyReq_Reviewer  FOREIGN KEY (ReviewedBy)  REFERENCES tblUsers(Id)    ON DELETE SET NULL,
                CONSTRAINT fk_ApiKeyReq_ApiKey    FOREIGN KEY (ApiKeyId)    REFERENCES tblApiKeys(Id)  ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Self-serve API-key requests + their review/approval state (Phase D).'"
        );
        _migAKR_output("  [OK] Created tblApiKeyRequests.");
    }
    _migAKR_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migAKR_output("ERROR: " . $e->getMessage());
}
