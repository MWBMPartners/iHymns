<?php

declare(strict_types=1);

/**
 * iHymns — Song usage-event spine for licence reporting (#1090 P5)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongUsageEvents records "song X used on date Y in context Z by org O" —
 * the substrate a compliant CCLI / CCS / OneLicense usage report reads (GROUP BY
 * tblSongs.Ccli over an org+date window). Additive + dormant until the
 * projection/print/schedule write-hooks land.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-usage-events.php
 *   Web:  /manage/setup-database → "Song usage-event log" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migUsage_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migUsage_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migUsage_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migUsage_output("");
_migUsage_output("=== iHymns — Song usage-event log (#1090 P5) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migUsage_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migUsage_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migUsage_tableExists($mysql, 'tblSongUsageEvents')) {
        _migUsage_output("  [SKIP] tblSongUsageEvents already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongUsageEvents (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId       VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId',
                OrgId        INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblOrganisations — the reporting church/org',
                UserId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblUsers — who logged/triggered it',
                SetlistId    VARCHAR(100) NULL DEFAULT NULL COMMENT 'Soft link to a setlist (no hard FK — setlists are client-synced)',
                ScheduleId   INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblSetlistSchedule (a scheduled service), if any',
                UsedAt       DATETIME     NOT NULL COMMENT 'When the song was used',
                UsageContext VARCHAR(20)  NOT NULL DEFAULT 'projected' COMMENT 'projected | printed | streamed | recorded | rehearsed (app-validated)',
                LicenceId    INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblOrganisationLicences — the licence the use is reported under',
                Quantity     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Copies/prints/attendance count where the licensor needs it',
                Source       VARCHAR(40)  NOT NULL DEFAULT 'app' COMMENT 'app | import | api | manual',
                MetaJson     JSON         NULL DEFAULT NULL,
                CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_Song     (SongId),
                INDEX idx_OrgDate  (OrgId, UsedAt),
                INDEX idx_Context  (UsageContext),
                INDEX idx_Date     (UsedAt),
                INDEX idx_Schedule (ScheduleId),
                INDEX idx_Licence  (LicenceId),
                CONSTRAINT fk_Usage_Song     FOREIGN KEY (SongId)     REFERENCES tblSongs(SongId)              ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_Usage_Org      FOREIGN KEY (OrgId)      REFERENCES tblOrganisations(Id)         ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Usage_User     FOREIGN KEY (UserId)     REFERENCES tblUsers(Id)                 ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Usage_Schedule FOREIGN KEY (ScheduleId) REFERENCES tblSetlistSchedule(Id)       ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Usage_Licence  FOREIGN KEY (LicenceId)  REFERENCES tblOrganisationLicences(Id)  ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Reportable song-usage events for CCLI/CCS/OneLicense reporting (#1090 P5).'"
        );
        _migUsage_output("  [OK] Created tblSongUsageEvents.");
    }
    _migUsage_output("Migration complete.");
} catch (\Throwable $e) { _migUsage_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
