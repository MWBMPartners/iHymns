<?php

declare(strict_types=1);

/**
 * iHymns — Live-follow broadcast sessions (native present+follow) (#1090 P7)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblLiveFollowSessions holds ephemeral broadcast state for the native
 * "Live Follow" feature — a worship leader's current song/slide that
 * congregants mirror on their own devices via a short join code. A cleanup job
 * prunes rows past ExpiresAt. Additive + dormant until the native clients +
 * follow API land.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-live-follow.php
 *   Web:  /manage/setup-database → "Live-follow sessions" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migLF_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migLF_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migLF_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migLF_output("");
_migLF_output("=== iHymns — Live-follow sessions (#1090 P7) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migLF_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migLF_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migLF_tableExists($mysql, 'tblLiveFollowSessions')) {
        _migLF_output("  [SKIP] tblLiveFollowSessions already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLiveFollowSessions (
                Id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SessionCode          VARCHAR(12)  NOT NULL COMMENT 'Short human code / QR payload congregants enter to join',
                HostUserId           INT UNSIGNED NOT NULL COMMENT 'FK to tblUsers — the worship leader hosting',
                OrgId                INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblOrganisations',
                SetlistId            VARCHAR(100) NULL DEFAULT NULL COMMENT 'Soft link to the setlist being followed',
                CurrentSongId        VARCHAR(20)  NULL DEFAULT NULL COMMENT 'FK to tblSongs — the song currently displayed',
                CurrentComponentIndex INT        NULL DEFAULT NULL COMMENT 'Index into the arrangement/component order being shown',
                StateJson            JSON         NULL DEFAULT NULL COMMENT 'Extra broadcast state (blank, theme, font-size hints)',
                IsActive             TINYINT(1)   NOT NULL DEFAULT 1,
                StartedAt            DATETIME     NOT NULL,
                LastHeartbeatAt      DATETIME     NOT NULL,
                ExpiresAt            DATETIME     NULL DEFAULT NULL COMMENT 'Cleanup horizon for the prune job',
                CreatedAt            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Code    (SessionCode),
                INDEX      idx_Host   (HostUserId),
                INDEX      idx_Active (IsActive, LastHeartbeatAt),
                CONSTRAINT fk_LiveFollow_Host FOREIGN KEY (HostUserId)    REFERENCES tblUsers(Id)         ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_LiveFollow_Org  FOREIGN KEY (OrgId)         REFERENCES tblOrganisations(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_LiveFollow_Song FOREIGN KEY (CurrentSongId) REFERENCES tblSongs(SongId)     ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Live-follow broadcast sessions for native present+follow (#1090 P7).'"
        );
        _migLF_output("  [OK] Created tblLiveFollowSessions.");
    }
    _migLF_output("Migration complete.");
} catch (\Throwable $e) { _migLF_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
