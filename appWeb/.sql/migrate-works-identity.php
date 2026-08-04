<?php

declare(strict_types=1);

/**
 * iHymns — Works identity schema batch (epic #1741 P1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) for the MusicBrainz-shaped Works
 * expansion, per .claude/catalogue-expansion-1741-plan.md §2.2. Additive +
 * dormant: nothing reads or writes any of these columns yet (that lands in
 * Phase 4b — the Work page + admin), so applying this card changes zero
 * observable behaviour.
 *
 *   - tblWorks.Ccli VARCHAR(50) NULL + UNIQUE uq_ccli — the future /ccli/
 *     resolver's Work-first lookup key. NULL (not '') so absent values
 *     coexist under the UNIQUE index — MySQL/MariaDB treat every NULL as
 *     distinct, the same pattern tblWorkSongs.uq_book_number and several
 *     other UNIQUE keys in this schema already rely on.
 *   - tblWorks.Subtitle / Disambiguation — same shape as the Song/Musician
 *     siblings landing in this same P1 batch.
 *   - tblWorks.TuneName + TuneId + idx_TuneId — mirrors the tblSongs
 *     TuneName/TuneId pair exactly (denorm display string + FK to the tune
 *     entity). The FK itself (fk_Works_Tune) is added via a TRAILING ALTER
 *     at the end of this script for the same reason tblSongs.fk_Songs_Tune
 *     is trailing in schema.sql: tblWorks was declared before tblTunes
 *     existed as a concept, so the referenced table may not exist yet on an
 *     install that hasn't run migrate-tunes-entity.php. That card is a
 *     dependency of this one (see the registry — this entry sits after
 *     'tunes-entity') but this script tolerates being run out of order: if
 *     tblTunes is absent it adds every column except the FK and prints a
 *     warning rather than fatally erroring, so re-running this card after
 *     tunes-entity has since landed picks up exactly the missing FK.
 *   - tblWorks.FirstPublishedYear SMALLINT UNSIGNED NULL — deliberately NOT
 *     MySQL's YEAR type, which only starts at 1901; hymn works predate that.
 *     The identical column is added to tblSongs in the SAME batch
 *     (migrate-song-identity-fields.php) because the plan's "Song AND Work
 *     editors" scope means adding it to only one side would force a second
 *     migration later (rule #20).
 *   - tblWorks.CopyrightYears / CopyrightHolder — split copyright fields;
 *     tblWorks has no legacy single `Copyright` column to keep alongside
 *     (unlike tblSongs), so there is nothing to reconcile.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every ADD COLUMN / ADD INDEX / ADD
 * CONSTRAINT is existence-guarded.
 *
 * @migration-adds tblWorks.Ccli
 * @migration-adds tblWorks.Subtitle
 * @migration-adds tblWorks.Disambiguation
 * @migration-adds tblWorks.TuneName
 * @migration-adds tblWorks.TuneId
 * @migration-adds tblWorks.FirstPublishedYear
 * @migration-adds tblWorks.CopyrightYears
 * @migration-adds tblWorks.CopyrightHolder
 *
 * SCHEMA MIRROR: every column/index/FK above is mirrored byte-identical in
 * appWeb/.sql/schema.sql — the 8 columns inline in the tblWorks CREATE TABLE
 * block, fk_Works_Tune as a trailing ALTER placed next to fk_Songs_Tune
 * (both need tblTunes to already exist) — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-works-identity.php
 *   Web:  /manage/setup-database → "Works identity schema (#1741 P1)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/catalogue-expansion-1741-plan.md §2.2
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migWorksId_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migWorksId_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migWorksId_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migWorksId_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }
function _migWorksId_fkExists(\mysqli $db, string $name): bool {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . $db->real_escape_string($name) . "' LIMIT 1"
    );
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migWorksId_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migWorksId_output("");
_migWorksId_output("=== iHymns — Works identity schema batch (#1741 P1) ===");
_migWorksId_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migWorksId_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migWorksId_output("Connected to MySQL: " . DB_NAME);

try {
    _migWorksId_output("--- tblWorks columns ---");

    $cols = [
        'Ccli' => [
            "ADD COLUMN Ccli VARCHAR(50) NULL DEFAULT NULL
                COMMENT 'CCLI Work Number (#1741 P1) — the future /ccli/ resolver''s Work-first lookup key. NULL rather than empty string so absent values coexist under uq_ccli (every NULL is distinct)'
                AFTER Slug",
        ],
        'Subtitle' => [
            "ADD COLUMN Subtitle VARCHAR(255) NULL DEFAULT NULL
                COMMENT 'Optional work subtitle (#1741 P1)'
                AFTER Ccli",
        ],
        'Disambiguation' => [
            "ADD COLUMN Disambiguation VARCHAR(255) NOT NULL DEFAULT ''
                COMMENT 'Short parenthetical to distinguish same-named works (#1741 P1)'
                AFTER Subtitle",
        ],
        'TuneName' => [
            "ADD COLUMN TuneName VARCHAR(120) NULL DEFAULT NULL
                COMMENT 'Traditional tune name mirror (#1741 P1); denorm display string, same pattern as tblSongs.TuneName. Canonical entity is tblTunes via TuneId'
                AFTER Disambiguation",
        ],
        'TuneId' => [
            "ADD COLUMN TuneId INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'FK to tblTunes.Id (#1741 P1); mirrors tblSongs.TuneId. FK added via trailing ALTER — tblTunes may not exist yet when this card runs (see fk_Works_Tune below)'
                AFTER TuneName",
        ],
        'FirstPublishedYear' => [
            "ADD COLUMN FirstPublishedYear SMALLINT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Year of first publication (#1741 P1); SMALLINT not MySQL YEAR — YEAR starts 1901 and hymn works predate it. Same column added to tblSongs in the same P1 batch (Song AND Work editors both need it, rule #20)'
                AFTER TuneId",
        ],
        'CopyrightYears' => [
            "ADD COLUMN CopyrightYears VARCHAR(100) NOT NULL DEFAULT ''
                COMMENT 'As-printed copyright year(s), free text e.g. \"1978, 1987, 2011\" (#1741 P1)'
                AFTER FirstPublishedYear",
        ],
        'CopyrightHolder' => [
            "ADD COLUMN CopyrightHolder VARCHAR(255) NOT NULL DEFAULT ''
                COMMENT 'Copyright holder name (#1741 P1)'
                AFTER CopyrightYears",
        ],
    ];
    foreach ($cols as $col => $clause) {
        if (_migWorksId_colExists($mysql, 'tblWorks', $col)) {
            _migWorksId_output("  [SKIP] tblWorks.{$col} already present.");
        } else {
            $mysql->query("ALTER TABLE tblWorks " . $clause[0]);
            _migWorksId_output("  [OK] Added tblWorks.{$col}.");
        }
    }

    if (!_migWorksId_idxExists($mysql, 'tblWorks', 'uq_ccli')) {
        $mysql->query("ALTER TABLE tblWorks ADD UNIQUE KEY uq_ccli (Ccli)");
        _migWorksId_output("  [OK] Added unique key uq_ccli.");
    } else {
        _migWorksId_output("  [SKIP] uq_ccli present.");
    }

    if (!_migWorksId_idxExists($mysql, 'tblWorks', 'idx_TuneId')) {
        $mysql->query("ALTER TABLE tblWorks ADD INDEX idx_TuneId (TuneId)");
        _migWorksId_output("  [OK] Added index idx_TuneId.");
    } else {
        _migWorksId_output("  [SKIP] idx_TuneId present.");
    }

    /* ---- Trailing FK: only if tblTunes exists ----
       Skip-and-warn rather than fatally erroring, per the plan: the
       tunes-entity card is this one's dependency and sits earlier in the
       migration registry, but an operator can still click cards out of
       order (or run this script standalone from the CLI). Everything
       else in this migration is unaffected by tblTunes' absence — only
       the FK constraint needs it. */
    _migWorksId_output("--- tblWorks.fk_Works_Tune ---");
    if (!_migWorksId_tableExists($mysql, 'tblTunes')) {
        _migWorksId_output("  [SKIP][WARN] tblTunes does not exist yet — run migrate-tunes-entity.php");
        _migWorksId_output("               ('Tune + meter entity' card) first, then re-run this migration");
        _migWorksId_output("               to add fk_Works_Tune. TuneId column was still added above.");
    } elseif (_migWorksId_fkExists($mysql, 'fk_Works_Tune')) {
        _migWorksId_output("  [SKIP] fk_Works_Tune present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblWorks
                ADD CONSTRAINT fk_Works_Tune
                    FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE SET NULL ON UPDATE CASCADE"
        );
        _migWorksId_output("  [OK] Added FK fk_Works_Tune.");
    }

    _migWorksId_output("");
    _migWorksId_output("Migration complete.");
} catch (\Throwable $e) {
    _migWorksId_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
