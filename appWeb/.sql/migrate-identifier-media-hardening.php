<?php

declare(strict_types=1);

/**
 * iHymns — Identifier + media hardening (#1090 P6 / N6-N7)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Future-proof two ENUM columns to VARCHAR (rule #20 — a new value should never
 * need an ALTER) and add a typed MusicBrainz artist id:
 *   - tblCreditPersonIdentifiers.IdentifierType ENUM('ipi','isni') -> VARCHAR(20)
 *     so CAE / IPI Base / PRO identifier types need no schema change (P6).
 *   - tblSongMedia.Kind ENUM(audio,sheet-music,midi,musicxml) -> VARCHAR(20)
 *     so 'notation-source' (Forte .fnf etc.) and 'pdf' need no schema change
 *     (N6/N7). App validates via SongMediaStorage::allKinds().
 *   - tblCreditPeople.MusicBrainzArtistMBID VARCHAR(50) UNIQUE — typed home for
 *     artist dedup/enrichment vs a parsed external-link URL (P6).
 *
 * Data-preserving (MODIFY keeps existing values) + IDEMPOTENT (skips when the
 * column is already VARCHAR / the column already exists).
 *
 * @migration-adds tblCreditPeople.MusicBrainzArtistMBID
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-identifier-media-hardening.php
 *   Web:  /manage/setup-database → "Identifier + media hardening" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migIdMedia_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migIdMedia_dataType(\mysqli $db, string $t, string $c): string {
    $r = $db->query(
        "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($t) . "'
            AND COLUMN_NAME = '" . $db->real_escape_string($c) . "' LIMIT 1"
    );
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? strtolower((string)$row['DATA_TYPE']) : '';
}
function _migIdMedia_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migIdMedia_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migIdMedia_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migIdMedia_output("");
_migIdMedia_output("=== iHymns — Identifier + media hardening (#1090 P6/N6-N7) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migIdMedia_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migIdMedia_output("Connected to MySQL: " . DB_NAME);

try {
    _migIdMedia_output("--- tblCreditPersonIdentifiers.IdentifierType ENUM->VARCHAR ---");
    if (_migIdMedia_dataType($mysql, 'tblCreditPersonIdentifiers', 'IdentifierType') === 'varchar') {
        _migIdMedia_output("  [SKIP] already VARCHAR.");
    } else {
        $mysql->query("ALTER TABLE tblCreditPersonIdentifiers MODIFY IdentifierType VARCHAR(20) NOT NULL COMMENT 'ipi | isni | cae | ipi-base | <pro-id> (app-validated; widened from ENUM #1090 P6)'");
        _migIdMedia_output("  [OK] Widened IdentifierType to VARCHAR(20).");
    }

    _migIdMedia_output("--- tblSongMedia.Kind ENUM->VARCHAR ---");
    if (_migIdMedia_dataType($mysql, 'tblSongMedia', 'Kind') === 'varchar') {
        _migIdMedia_output("  [SKIP] already VARCHAR.");
    } else {
        $mysql->query("ALTER TABLE tblSongMedia MODIFY Kind VARCHAR(20) NOT NULL COMMENT 'audio | sheet-music | midi | musicxml | notation-source | pdf (app-validated via SongMediaStorage::allKinds(); widened from ENUM #1090)'");
        _migIdMedia_output("  [OK] Widened Kind to VARCHAR(20).");
    }

    _migIdMedia_output("--- tblCreditPeople.MusicBrainzArtistMBID ---");
    if (_migIdMedia_colExists($mysql, 'tblCreditPeople', 'MusicBrainzArtistMBID')) {
        _migIdMedia_output("  [SKIP] column present.");
    } else {
        $mysql->query("ALTER TABLE tblCreditPeople ADD COLUMN MusicBrainzArtistMBID VARCHAR(50) NULL DEFAULT NULL COMMENT 'MusicBrainz Artist MBID (#1090 P6)' AFTER Slug");
        _migIdMedia_output("  [OK] Added tblCreditPeople.MusicBrainzArtistMBID.");
    }
    if (!_migIdMedia_idxExists($mysql, 'tblCreditPeople', 'uq_MbArtist')) {
        $mysql->query("ALTER TABLE tblCreditPeople ADD UNIQUE KEY uq_MbArtist (MusicBrainzArtistMBID)");
        _migIdMedia_output("  [OK] Added unique key uq_MbArtist.");
    } else { _migIdMedia_output("  [SKIP] uq_MbArtist present."); }

    _migIdMedia_output("");
    _migIdMedia_output("NOTE: app-side validation must back the now-unconstrained VARCHAR columns");
    _migIdMedia_output("      (SongMediaStorage::allKinds() for Kind; the identifier-type allow-list).");
    _migIdMedia_output("Migration complete.");
} catch (\Throwable $e) { _migIdMedia_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
