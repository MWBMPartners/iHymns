<?php

declare(strict_types=1);

/**
 * iHymns — Annotation votes (sort-only, never auto-publish) (#1090 P10)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblLyricAnnotationVotes records one vote per user per per-line annotation.
 * The tally (SUM(Vote)) drives SORT ORDER in the reviewer queue ONLY — it is
 * deliberately NOT wired to Status/IsVerified and NEVER auto-publishes to the
 * read path (the doctrinal/abuse hazard the iLyricsDB #25 auto-promote design
 * exhibited). Publishing stays moderator-driven.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-annotation-votes.php
 *   Web:  /manage/setup-database → "Annotation votes" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migVotes_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migVotes_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migVotes_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migVotes_output("");
_migVotes_output("=== iHymns — Annotation votes (#1090 P10) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migVotes_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migVotes_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migVotes_tableExists($mysql, 'tblLyricAnnotationVotes')) {
        _migVotes_output("  [SKIP] tblLyricAnnotationVotes already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricAnnotationVotes (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                AnnotationId BIGINT UNSIGNED  NOT NULL COMMENT 'FK to tblLyricLineAnnotations.Id',
                UserId       INT UNSIGNED     NOT NULL COMMENT 'FK to tblUsers.Id',
                Vote         TINYINT          NOT NULL COMMENT '+1 or -1 (app-validated to {-1,1})',
                CreatedAt    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_AnnotationUser (AnnotationId, UserId),
                INDEX      idx_Annotation    (AnnotationId),
                CONSTRAINT fk_AnnotVote_Annotation FOREIGN KEY (AnnotationId) REFERENCES tblLyricLineAnnotations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_AnnotVote_User       FOREIGN KEY (UserId)       REFERENCES tblUsers(Id)                ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Annotation votes — sort-only, never auto-publish (#1090 P10).'"
        );
        _migVotes_output("  [OK] Created tblLyricAnnotationVotes.");
    }
    _migVotes_output("Migration complete.");
} catch (\Throwable $e) { _migVotes_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
