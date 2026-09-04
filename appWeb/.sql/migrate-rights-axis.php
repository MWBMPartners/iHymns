<?php

declare(strict_types=1);

/**
 * iHymns — Rights axis: availability + royalty IDs + per-action restrictions (#1090 audit)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Three small rights-related gaps the cross-repo audit found:
 *   - tblSongs.Availability (available|paid_only|unavailable) — owner-driven
 *     withhold/paywall of a specific work, the hook both billing models gate on (#1139).
 *   - tblSongRoyaltyIds — per-song PRO/royalty-authority registration (#1140).
 *   - tblContentRestrictions.AppliesToAction — gate display vs print/export/
 *     translate independently (CCLI separates display from reproduction) (#1141).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * @migration-adds tblSongs.Availability
 * @migration-adds tblContentRestrictions.AppliesToAction
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-rights-axis.php
 *   Web:  /manage/setup-database → "Rights axis (availability/royalty/per-action)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migRA_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migRA_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migRA_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migRA_addCol(\mysqli $db, string $t, string $c, string $ddl): void {
    if (_migRA_colExists($db, $t, $c)) { _migRA_output("  [SKIP] {$t}.{$c} present."); return; }
    $db->query("ALTER TABLE {$t} ADD COLUMN {$ddl}");
    _migRA_output("  [OK] Added {$t}.{$c}.");
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migRA_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migRA_output("");
_migRA_output("=== iHymns — Rights axis (#1090 audit) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migRA_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migRA_output("Connected to MySQL: " . DB_NAME);

try {
    _migRA_addCol($mysql, 'tblSongs', 'Availability',
        "Availability VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'available | paid_only | unavailable (#1090 audit). Owner-driven rights gate read by content_access.php' AFTER MusicPublicDomain");

    _migRA_addCol($mysql, 'tblContentRestrictions', 'AppliesToAction',
        "AppliesToAction VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT 'all | display | print | export | translate | reproduce (#1090 audit)' AFTER RestrictionType");

    if (_migRA_tableExists($mysql, 'tblSongRoyaltyIds')) {
        _migRA_output("  [SKIP] tblSongRoyaltyIds already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongRoyaltyIds (
                Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId      VARCHAR(20)  NOT NULL,
                Authority   VARCHAR(40)  NOT NULL COMMENT 'ASCAP|BMI|PRS|SESAC|GEMA|… VARCHAR not ENUM (growable)',
                AuthorityId VARCHAR(100) NOT NULL COMMENT 'Society-assigned work/agreement id',
                Note        VARCHAR(255) NULL DEFAULT NULL,
                SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Song_Authority (SongId, Authority),
                INDEX idx_Song (SongId),
                CONSTRAINT fk_RoyaltyIds_Song FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-song PRO / royalty-authority IDs (#1140).'"
        );
        _migRA_output("  [OK] Created tblSongRoyaltyIds.");
    }

    _migRA_output("Migration complete.");
} catch (\Throwable $e) { _migRA_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
