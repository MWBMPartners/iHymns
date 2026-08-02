<?php

declare(strict_types=1);

/**
 * iHymns — Tune enrichment schema batch (epic #1741 P1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) for enriching the Tune entity
 * (#1090 P4) with subtitle/disambiguation, composer/arranger-style credits,
 * and external links, per .claude/catalogue-expansion-1741-plan.md §2.3.
 * Additive + dormant: nothing reads or writes any of these objects yet
 * (that lands in Phase 4c — the Tune page on the registry), so applying
 * this card changes zero observable behaviour.
 *
 *   - tblTunes.Subtitle / Disambiguation — same shape as the Song/Work/
 *     Musician siblings landing in this same P1 batch.
 *   - tblTuneCredits (NEW) — ONE Role-discriminated table
 *     (composer|arranger|harmoniser|source), NOT six per-role clones like
 *     the legacy tblSongWriters/tblSongComposers/… family. Name is a plain
 *     name-string (matching every other credit table in this schema);
 *     CreditPersonId is a RESERVED, nullable, dormant FK to tblCreditPeople
 *     so the "credits: name-strings vs FK-ify" open question (plan owner
 *     decision 3) costs zero ALTER either way — it is unused until that
 *     decision lands.
 *   - tblTuneExternalLinks (NEW) — mirrors tblWorkExternalLinks exactly
 *     (WorkId -> TuneId), a real FK to tblExternalLinkTypes (rule #15: a
 *     new external-links entity gets its own tbl<Entity>ExternalLinks
 *     table, never a generic FK column).
 *   - tblExternalLinkTypes.Category ENUM -> VARCHAR(20) and .AppliesTo
 *     SET('song','songbook','person','work') -> VARCHAR(255) CSV (plan T3).
 *     A SET is exactly the same "value-add needs an ALTER" trap as an ENUM
 *     (rule #20) — adding 'tune' as a future AppliesTo value would otherwise
 *     force its own ALTER. MySQL/MariaDB cast an existing SET value to its
 *     CSV string representation for free on MODIFY, and every read of this
 *     column already goes through FIND_IN_SET() (rule #12's registry
 *     pattern), which reads a CSV VARCHAR identically to a SET. This
 *     migration does NOT retroactively add 'tune' to any row's AppliesTo
 *     value or change the column DEFAULT — that is a curator/admin-UI data
 *     decision for when the tune page's external-links editor ships.
 *
 * DEPENDS ON tblTunes (#1090 P4, migrate-tunes-entity.php) — every object in
 * this script either ALTERs tblTunes directly or FKs to it, so unlike
 * migrate-works-identity.php (where only ONE trailing FK needs the
 * dependency), this ENTIRE script is gated: if tblTunes is absent, it prints
 * a warning and exits without touching anything, rather than partially
 * applying. Re-running after migrate-tunes-entity.php has landed applies
 * everything in one pass.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every ADD COLUMN / CREATE TABLE / MODIFY
 * is existence- or DATA_TYPE-guarded.
 *
 * @migration-adds tblTunes.Subtitle
 * @migration-adds tblTunes.Disambiguation
 *
 * SCHEMA MIRROR: tblTunes.Subtitle/.Disambiguation, tblTuneCredits,
 * tblTuneExternalLinks, and the Category/AppliesTo MODIFYs are mirrored
 * byte-identical in appWeb/.sql/schema.sql — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-tune-enrichment.php
 *   Web:  /manage/setup-database → "Tune enrichment schema (#1741 P1)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/catalogue-expansion-1741-plan.md §2.3
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migTuneEnrich_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migTuneEnrich_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migTuneEnrich_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migTuneEnrich_dataType(\mysqli $db, string $t, string $c): string {
    $r = $db->query(
        "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($t) . "'
            AND COLUMN_NAME = '" . $db->real_escape_string($c) . "' LIMIT 1"
    );
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? strtolower((string)$row['DATA_TYPE']) : '';
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migTuneEnrich_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migTuneEnrich_output("");
_migTuneEnrich_output("=== iHymns — Tune enrichment schema batch (#1741 P1) ===");
_migTuneEnrich_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migTuneEnrich_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migTuneEnrich_output("Connected to MySQL: " . DB_NAME);

/* Whole-script gate: everything below either ALTERs tblTunes or FKs to it. */
if (!_migTuneEnrich_tableExists($mysql, 'tblTunes')) {
    _migTuneEnrich_output("");
    _migTuneEnrich_output("[SKIP][WARN] tblTunes does not exist yet — run migrate-tunes-entity.php");
    _migTuneEnrich_output("             ('Tune + meter entity' card) first, then re-run this migration.");
    _migTuneEnrich_output("             Nothing was changed.");
    $mysql->close();
    return;
}

try {
    _migTuneEnrich_output("--- tblTunes columns ---");
    if (_migTuneEnrich_colExists($mysql, 'tblTunes', 'Subtitle')) {
        _migTuneEnrich_output("  [SKIP] tblTunes.Subtitle already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblTunes
                ADD COLUMN Subtitle VARCHAR(255) NULL DEFAULT NULL
                    COMMENT 'Optional tune subtitle (#1741 P1)'
                AFTER Slug"
        );
        _migTuneEnrich_output("  [OK] Added tblTunes.Subtitle.");
    }
    if (_migTuneEnrich_colExists($mysql, 'tblTunes', 'Disambiguation')) {
        _migTuneEnrich_output("  [SKIP] tblTunes.Disambiguation already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblTunes
                ADD COLUMN Disambiguation VARCHAR(255) NOT NULL DEFAULT ''
                    COMMENT 'Short parenthetical to distinguish same-named tunes (#1741 P1)'
                AFTER Subtitle"
        );
        _migTuneEnrich_output("  [OK] Added tblTunes.Disambiguation.");
    }

    _migTuneEnrich_output("--- tblTuneCredits ---");
    if (_migTuneEnrich_tableExists($mysql, 'tblTuneCredits')) {
        _migTuneEnrich_output("  [SKIP] tblTuneCredits already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblTuneCredits (
                Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                TuneId          INT UNSIGNED NOT NULL,
                Role            VARCHAR(20)  NOT NULL COMMENT 'composer | arranger | harmoniser | source (app-validated; VARCHAR not ENUM, rule #20)',
                Name            VARCHAR(255) NOT NULL COMMENT 'Credited name-string, matching the tblSong*/tblWork credit-table pattern (no FK to tblCreditPeople by default)',
                CreditPersonId  INT UNSIGNED NULL DEFAULT NULL COMMENT 'Reserved FK to tblCreditPeople.Id (#1741 P1) — nullable/dormant so the credits name-string-vs-FK decision costs zero ALTER either way; unused until that decision lands',
                SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
                CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_tune           (TuneId),
                INDEX idx_role           (Role),
                INDEX idx_CreditPersonId (CreditPersonId),

                CONSTRAINT fk_TuneCredits_Tune
                    FOREIGN KEY (TuneId)         REFERENCES tblTunes(Id)       ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_TuneCredits_Person
                    FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Tune composer/arranger/harmoniser/source credits (#1741 P1), Role-discriminated per plan §2.3.'"
        );
        _migTuneEnrich_output("  [OK] Created tblTuneCredits.");
    }

    _migTuneEnrich_output("--- tblTuneExternalLinks ---");
    if (_migTuneEnrich_tableExists($mysql, 'tblTuneExternalLinks')) {
        _migTuneEnrich_output("  [SKIP] tblTuneExternalLinks already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblTuneExternalLinks (
                Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                TuneId      INT UNSIGNED NOT NULL,
                LinkTypeId  INT UNSIGNED NOT NULL,
                Url         VARCHAR(2048) NOT NULL,
                Note        VARCHAR(255) NULL,
                SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
                Verified    TINYINT(1)   NOT NULL DEFAULT 0,
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_tune (TuneId),
                INDEX idx_type (LinkTypeId),

                CONSTRAINT fk_link_tune
                    FOREIGN KEY (TuneId)     REFERENCES tblTunes(Id)             ON DELETE CASCADE,
                CONSTRAINT fk_link_type_tune
                    FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migTuneEnrich_output("  [OK] Created tblTuneExternalLinks.");
    }

    _migTuneEnrich_output("--- tblExternalLinkTypes.Category ENUM->VARCHAR ---");
    if (_migTuneEnrich_dataType($mysql, 'tblExternalLinkTypes', 'Category') === 'varchar') {
        _migTuneEnrich_output("  [SKIP] already VARCHAR.");
    } else {
        $mysql->query(
            "ALTER TABLE tblExternalLinkTypes
                MODIFY Category VARCHAR(20) NOT NULL DEFAULT 'other'
                    COMMENT 'information | listen | watch | read | sheet-music | purchase | authority | official | social | other (app-validated; widened from ENUM, rule #20, #1741 P1)'"
        );
        _migTuneEnrich_output("  [OK] Widened Category to VARCHAR(20).");
    }

    _migTuneEnrich_output("--- tblExternalLinkTypes.AppliesTo SET->VARCHAR ---");
    if (_migTuneEnrich_dataType($mysql, 'tblExternalLinkTypes', 'AppliesTo') === 'varchar') {
        _migTuneEnrich_output("  [SKIP] already VARCHAR.");
    } else {
        $mysql->query(
            "ALTER TABLE tblExternalLinkTypes
                MODIFY AppliesTo VARCHAR(255) NOT NULL DEFAULT 'song,songbook,person'
                    COMMENT 'CSV of applicable entity types: song | songbook | person | work | tune (app-validated via FIND_IN_SET; widened from SET so a new entity type never needs an ALTER, rule #20, #1741 P1)'"
        );
        _migTuneEnrich_output("  [OK] Widened AppliesTo to VARCHAR(255).");
    }

    _migTuneEnrich_output("");
    _migTuneEnrich_output("Migration complete.");
} catch (\Throwable $e) {
    _migTuneEnrich_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
