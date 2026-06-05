<?php

declare(strict_types=1);

/**
 * iHymns — Corpus-quality linter findings worklist (#1090 P8)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongQualityFindings is the linter worklist — one row per (song, rule)
 * defect, UPSERTed on each lint run, triaged like the review queue. Turns
 * "somewhere there are bad records" into a finite, assignable queue across the
 * 12k+ song corpus. Additive + dormant until the linter job + UI land.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-quality-findings.php
 *   Web:  /manage/setup-database → "Corpus-quality linter" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migQF_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migQF_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migQF_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migQF_output("");
_migQF_output("=== iHymns — Corpus-quality findings (#1090 P8) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migQF_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migQF_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migQF_tableExists($mysql, 'tblSongQualityFindings')) {
        _migQF_output("  [SKIP] tblSongQualityFindings already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongQualityFindings (
                Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId      VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId',
                RuleKey     VARCHAR(60)  NOT NULL COMMENT 'Rule that fired, e.g. missing_ccli | orphan_tune | unbalanced_chords (app-validated vs a central rule map)',
                Severity    VARCHAR(10)  NOT NULL DEFAULT 'warning' COMMENT 'info | warning | error',
                Status      VARCHAR(20)  NOT NULL DEFAULT 'open' COMMENT 'open | acknowledged | fixed | wontfix | false_positive',
                AssignedTo  INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblUsers — claimed by',
                DetailText  TEXT         NULL DEFAULT NULL,
                ContextJson JSON         NULL DEFAULT NULL COMMENT 'Structured evidence for the finding',
                FirstSeenAt DATETIME     NOT NULL COMMENT 'When the rule first fired for this (song,rule)',
                LastSeenAt  DATETIME     NOT NULL COMMENT 'Most recent lint run that still saw it',
                ResolvedBy  INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblUsers',
                ResolvedAt  DATETIME     NULL DEFAULT NULL,
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_SongRule  (SongId, RuleKey),
                INDEX      idx_Status   (Status),
                INDEX      idx_Severity (Severity),
                INDEX      idx_Rule     (RuleKey),
                INDEX      idx_Assignee (AssignedTo),
                CONSTRAINT fk_QualityFinding_Song     FOREIGN KEY (SongId)     REFERENCES tblSongs(SongId) ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_QualityFinding_Assignee FOREIGN KEY (AssignedTo) REFERENCES tblUsers(Id)     ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_QualityFinding_Resolver FOREIGN KEY (ResolvedBy) REFERENCES tblUsers(Id)     ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Corpus-quality linter findings worklist (#1090 P8).'"
        );
        _migQF_output("  [OK] Created tblSongQualityFindings.");
    }
    _migQF_output("Migration complete.");
} catch (\Throwable $e) { _migQF_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
