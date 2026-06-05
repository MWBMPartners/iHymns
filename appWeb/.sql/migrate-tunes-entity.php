<?php

declare(strict_types=1);

/**
 * iHymns — Tune + meter as a first-class entity (#1090 P4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Promote the hymn TUNE from free-text tblSongs.TuneName to an entity:
 *   - tblTunes (Name, Slug, MeterCode, MusicBrainzWorkMBID, HymnaryTuneId) —
 *     enables common-metre interchange ("sing this text to a tune they know")
 *     and de-dupes the same tune across 30+ hymnals.
 *   - tblTuneAliases — alternate names for a tune.
 *   - tblSongs.TuneId + fk_Songs_Tune (SET NULL) — link; TuneName kept as a
 *     JOIN-free denorm display mirror.
 * Backfills tblTunes from DISTINCT TuneName and sets TuneId (re-runnable).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * @migration-adds tblSongs.TuneId
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-tunes-entity.php
 *   Web:  /manage/setup-database → "Tune + meter entity" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migTunes_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}
function _migTunes_tableExists(\mysqli $db, string $t): bool {
    $r = $db->query("SHOW TABLES LIKE '{$t}'");
    return (bool)($r && $r->num_rows > 0);
}
function _migTunes_colExists(\mysqli $db, string $t, string $c): bool {
    $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'");
    return (bool)($r && $r->num_rows > 0);
}
function _migTunes_fkExists(\mysqli $db, string $name): bool {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . $db->real_escape_string($name) . "' LIMIT 1"
    );
    return (bool)($r && $r->num_rows > 0);
}
function _migTunes_slugify(string $name): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $s = $s === false ? $name : $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'tune' : substr($s, 0, 130);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migTunes_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migTunes_output("");
_migTunes_output("=== iHymns — Tune + meter entity (#1090 P4) ===");
_migTunes_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migTunes_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migTunes_output("Connected to MySQL: " . DB_NAME);

try {
    _migTunes_output("--- tblTunes ---");
    if (_migTunes_tableExists($mysql, 'tblTunes')) {
        _migTunes_output("  [SKIP] tblTunes already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblTunes (
                Id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Name                VARCHAR(120) NOT NULL COMMENT 'Canonical tune name, e.g. HYFRYDOL',
                Slug                VARCHAR(140) NOT NULL COMMENT 'URL-safe handle',
                MeterCode           VARCHAR(60)  NULL DEFAULT NULL COMMENT 'Hymn metre, e.g. 87.87 D | CM | LM | 86.86 (VARCHAR not ENUM)',
                MusicBrainzWorkMBID VARCHAR(50)  NULL DEFAULT NULL COMMENT 'MusicBrainz Work MBID — a tune is a composition (mirrors tblWorks)',
                HymnaryTuneId       VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Hymnary.org tune identifier for enrichment cross-link',
                Notes               TEXT         NULL DEFAULT NULL,
                CreatedAt           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Name   (Name),
                UNIQUE KEY uq_Slug   (Slug),
                UNIQUE KEY uq_MbWork (MusicBrainzWorkMBID),
                INDEX      idx_Meter (MeterCode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Hymn tunes as first-class entities (#1090 P4).'"
        );
        _migTunes_output("  [OK] Created tblTunes.");
    }

    _migTunes_output("--- tblTuneAliases ---");
    if (_migTunes_tableExists($mysql, 'tblTuneAliases')) {
        _migTunes_output("  [SKIP] tblTuneAliases already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblTuneAliases (
                Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                TuneId    INT UNSIGNED NOT NULL,
                Name      VARCHAR(120) NOT NULL,
                CreatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_TuneName (TuneId, Name),
                INDEX      idx_Tune    (TuneId),
                INDEX      idx_Name    (Name),
                CONSTRAINT fk_TuneAlias_Tune
                    FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migTunes_output("  [OK] Created tblTuneAliases.");
    }

    _migTunes_output("--- tblSongs.TuneId + FK ---");
    if (_migTunes_colExists($mysql, 'tblSongs', 'TuneId')) {
        _migTunes_output("  [SKIP] tblSongs.TuneId already present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD COLUMN TuneId INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblTunes.Id (#1090 P4)' AFTER TuneName");
        _migTunes_output("  [OK] Added tblSongs.TuneId.");
    }
    $idx = $mysql->query("SHOW INDEX FROM tblSongs WHERE Key_name = 'idx_TuneId'");
    if (!($idx && $idx->num_rows > 0)) {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_TuneId (TuneId)");
        _migTunes_output("  [OK] Added index idx_TuneId.");
    } else { _migTunes_output("  [SKIP] idx_TuneId present."); }
    if (!_migTunes_fkExists($mysql, 'fk_Songs_Tune')) {
        $mysql->query("ALTER TABLE tblSongs ADD CONSTRAINT fk_Songs_Tune FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE SET NULL ON UPDATE CASCADE");
        _migTunes_output("  [OK] Added FK fk_Songs_Tune.");
    } else { _migTunes_output("  [SKIP] fk_Songs_Tune present."); }

    _migTunes_output("--- Backfill tblTunes from DISTINCT TuneName (re-runnable) ---");
    $sel = $mysql->query("SELECT DISTINCT TuneName FROM tblSongs WHERE TuneName IS NOT NULL AND TuneName <> ''");
    $insTune = $mysql->prepare("INSERT IGNORE INTO tblTunes (Name, Slug) VALUES (?, ?)");
    $slugTaken = $mysql->prepare("SELECT 1 FROM tblTunes WHERE Slug = ? LIMIT 1");
    $created = 0;
    while ($row = $sel->fetch_assoc()) {
        $name = (string)$row['TuneName'];
        $base = _migTunes_slugify($name);
        $slug = $base; $n = 1;
        while (true) {
            $slugTaken->bind_param('s', $slug); $slugTaken->execute();
            $taken = $slugTaken->get_result();
            if (!($taken && $taken->num_rows > 0)) break;
            $n++; $slug = substr($base, 0, 134) . '-' . $n;
        }
        $insTune->bind_param('ss', $name, $slug);
        $insTune->execute();
        if ($insTune->affected_rows > 0) $created++;
    }
    $insTune->close(); $slugTaken->close();
    $mysql->query("UPDATE tblSongs s JOIN tblTunes t ON s.TuneName = t.Name SET s.TuneId = t.Id WHERE s.TuneId IS NULL AND s.TuneName IS NOT NULL AND s.TuneName <> ''");
    $linked = $mysql->affected_rows;
    _migTunes_output("  [OK] Created {$created} tunes; linked {$linked} songs to a TuneId.");

    _migTunes_output("");
    _migTunes_output("Migration complete.");
} catch (\Throwable $e) {
    _migTunes_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
