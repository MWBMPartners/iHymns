<?php

declare(strict_types=1);

/**
 * iHymns — Work BOWI identifier schema batch (epic #1741 D5)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) adding the Best Open Work
 * Identifier — Luminate Data's open alternative to ISWC (media-identifiers-
 * spec.md §4b, "BOWI — Best Open Work Identifier, Luminate's open work-ID
 * alternative to ISWC — NEW to this spec — add to iHymns work vocabulary +
 * tblWorks") — to `tblWorks`. Deliberately its own tiny migration rather than
 * folded into the already-committed `migrate-works-identity.php` (#1741 P1),
 * per the D5 task brief: that script has already shipped, so a NEW column
 * gets a NEW migration, not a retroactive edit to a landed one.
 *
 * Additive + dormant: nothing reads or writes `tblWorks.Bowi` yet — no editor
 * field, no /bowi/ resolver route (that is explicitly P3, out of scope for
 * this storage-layer commit) — so applying this card changes zero observable
 * behaviour.
 *
 *   - tblWorks.Bowi VARCHAR(30) NULL — mirrors the existing Iswc/Ccli/
 *     MusicBrainzWorkMBID identity columns exactly: a first-class work
 *     identifier gets its own column (not a tblSongExternalIds row — that
 *     key/value table is for RECORDING/release/product grain per decision
 *     Q5/A=c, media-identifiers-spec.md §4c; a WORK identifier that already
 *     has a column-shaped home stays a column, per WORK_IDENTIFIER_TYPES in
 *     includes/media_identifiers.php). VARCHAR(30) is deliberately generous
 *     — unlike ISWC's fixed T-NNN.NNN.NNN-C shape, BOWI's exact format is not
 *     independently confirmed by this repo's grounding sources (the Luminate
 *     KB article WebFetch-403s; spec §4b names the identifier's EXISTENCE and
 *     purpose but not its character shape), so no `validate` regex is
 *     registered for it in WORK_IDENTIFIER_TYPES either — "do NOT invent".
 *   - uq_bowi UNIQUE KEY (Bowi) — NULL (not '') so absent values coexist
 *     under the UNIQUE index, mirroring uq_iswc / uq_ccli / uq_mbwork exactly
 *     (every NULL is distinct under InnoDB/MariaDB UNIQUE semantics).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. The ADD COLUMN / ADD UNIQUE KEY are both
 * existence-guarded.
 *
 * @migration-adds tblWorks.Bowi
 *
 * SCHEMA MIRROR: the column + uq_bowi are mirrored byte-identical in
 * appWeb/.sql/schema.sql's tblWorks CREATE TABLE block — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-work-bowi.php
 *   Web:  /manage/setup-database → "Work BOWI identifier (#1741 D5)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/media-identifiers-spec.md §4b, §4c
 * @see .claude/catalogue-expansion-1741-plan.md §2 (schema-convention precedent)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migWorkBowi_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migWorkBowi_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migWorkBowi_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migWorkBowi_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migWorkBowi_output("");
_migWorkBowi_output("=== iHymns — Work BOWI identifier schema batch (#1741 D5) ===");
_migWorkBowi_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migWorkBowi_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migWorkBowi_output("Connected to MySQL: " . DB_NAME);

try {
    _migWorkBowi_output("--- tblWorks.Bowi ---");
    if (_migWorkBowi_colExists($mysql, 'tblWorks', 'Bowi')) {
        _migWorkBowi_output("  [SKIP] tblWorks.Bowi already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblWorks
                ADD COLUMN Bowi VARCHAR(30) NULL DEFAULT NULL
                    COMMENT 'Best Open Work Identifier — Luminate Data''s open ISWC alternative (media-identifiers-spec.md §4b/§4c, #1741 D5); the future /bowi/ resolver''s lookup key. NULL rather than empty string so absent values coexist under uq_bowi (every NULL is distinct), mirrors uq_iswc/uq_ccli.'
                AFTER Ccli"
        );
        _migWorkBowi_output("  [OK] Added tblWorks.Bowi.");
    }

    if (_migWorkBowi_idxExists($mysql, 'tblWorks', 'uq_bowi')) {
        _migWorkBowi_output("  [SKIP] uq_bowi present.");
    } else {
        $mysql->query("ALTER TABLE tblWorks ADD UNIQUE KEY uq_bowi (Bowi)");
        _migWorkBowi_output("  [OK] Added unique key uq_bowi.");
    }

    _migWorkBowi_output("");
    _migWorkBowi_output("Migration complete.");
} catch (\Throwable $e) {
    _migWorkBowi_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
