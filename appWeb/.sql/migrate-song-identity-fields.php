<?php

declare(strict_types=1);

/**
 * iHymns — Song identity fields schema batch (epic #1741 P1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) for the Song side of the
 * MusicBrainz-shaped catalogue expansion, per
 * .claude/catalogue-expansion-1741-plan.md §2.4 and its §3 amendment.
 * Additive + dormant: nothing reads or writes any of the new columns yet
 * (editor fields land in Phase 5), so applying this card changes zero
 * observable behaviour EXCEPT for the two backfills below, which only ever
 * REFORMAT an already-present, already-app-authored identifier value into
 * its canonical shape — they never change what the identifier IDENTIFIES.
 *
 *   - tblSongs.Subtitle VARCHAR(500) NULL, .Disambiguation VARCHAR(255)
 *     DEFAULT '' — same shape as the Work/Tune/Musician siblings landing in
 *     this same P1 batch.
 *   - tblSongs.CopyrightYears / CopyrightHolder — split copyright fields.
 *     The legacy `Copyright` column is KEPT AS-IS (the as-printed denorm
 *     string every existing read path already uses) and is NOT auto-parsed
 *     into the two new columns — that would require guessing where a
 *     printed copyright notice's year(s) end and holder name begins, which
 *     is exactly the kind of silent-data-invention this schema-only batch
 *     must not do. The two columns start empty and are filled by a curator
 *     or a future importer.
 *   - tblSongs.FirstPublishedYear SMALLINT UNSIGNED NULL — deliberately NOT
 *     MySQL's YEAR type (starts at 1901; hymns predate it). The identical
 *     column is added to tblWorks in the SAME batch
 *     (migrate-works-identity.php) — the plan's "Song AND Work editors"
 *     scope means adding it to only one side would force a second
 *     migration later (rule #20).
 *   - idx_Iswc / idx_Ccli — neither tblSongs.Iswc nor .Ccli is indexed
 *     today even though both are about to become resolver lookup keys
 *     (/iswc/, /ccli/) hit on cacheable public routes against a ~14k-row
 *     table. Created LAST, after both backfills below, so — per rule #19 —
 *     the registry probe can treat "the index exists" as proof the
 *     backfill also ran; a connection dropping mid-backfill never leaves a
 *     green card sitting on top of half-canonicalised data.
 *
 * TWO BACKFILLS (idempotent; each pass is a plain reformat, never a value
 * change) using LOCAL canonicalise functions — NOT includes/identifier_
 * normalize.php, which does not exist yet (that shared module is Phase 3;
 * see the plan's explicit "do NOT create it here" instruction). A future
 * Phase-3 commit is expected to lift these two functions out verbatim.
 *
 *   1. ISWC -> T-NNN.NNN.NNN-C. Extracted from the SAME validate-and-
 *      reformat logic manage/works.php already applies to curator input
 *      (works.php's $validateIswc, ~line 68) so the backfill and the write
 *      path agree on one shape. A value that doesn't match the T + 10-digit
 *      + check-digit shape is left untouched and logged — never guessed at,
 *      never dropped.
 *   2. ISRC -> uppercase, hyphens/spaces stripped, bare 12 characters
 *      (§3 amendment: "one line now vs the forbidden second migration" —
 *      added alongside the ISWC backfill so idx_Iswc's sibling idx_Ccli — I
 *      mean idx_Isrc's future /isrc/ resolver also gets an indexed exact
 *      match from day one). A value that isn't exactly 12 alphanumeric
 *      characters after stripping is left untouched and logged.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every ADD COLUMN / ADD INDEX is
 * existence-guarded; both backfills only ever write a row whose current
 * value differs from its canonical form, so a second run touches 0 rows.
 *
 * @migration-adds tblSongs.Subtitle
 * @migration-adds tblSongs.Disambiguation
 * @migration-adds tblSongs.CopyrightYears
 * @migration-adds tblSongs.CopyrightHolder
 * @migration-adds tblSongs.FirstPublishedYear
 *
 * SCHEMA MIRROR: all 5 columns + idx_Iswc + idx_Ccli are mirrored
 * byte-identical in appWeb/.sql/schema.sql's tblSongs CREATE TABLE block —
 * rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-identity-fields.php
 *   Web:  /manage/setup-database → "Song identity fields (#1741 P1)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/catalogue-expansion-1741-plan.md §2.4, §3 amendment
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongId_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSongId_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migSongId_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }

/**
 * ISWC -> canonical T-NNN.NNN.NNN-C, or null if the shape can't be trusted.
 * Mirrors manage/works.php's $validateIswc (minus its "empty string is
 * valid" branch — the backfill loop only ever calls this on a non-empty
 * value, so a null return here always means "leave the row untouched").
 * https://en.wikipedia.org/wiki/International_Standard_Musical_Work_Code
 */
function _migSongId_canonicalIswc(string $raw): ?string {
    $raw = strtoupper(trim($raw));
    if ($raw === '') return null;
    if (preg_match('/^T-?\d{3}\.?\d{3}\.?\d{3}-?\d$/', $raw) !== 1) return null;
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === null || strlen($digits) !== 10) return null;
    return 'T-' . substr($digits, 0, 3) . '.' . substr($digits, 3, 3)
         . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 1);
}

/**
 * ISRC -> canonical bare 12-char uppercase form (hyphens/spaces stripped),
 * or null if the result isn't exactly 12 alphanumeric characters.
 * https://en.wikipedia.org/wiki/International_Standard_Recording_Code
 */
function _migSongId_canonicalIsrc(string $raw): ?string {
    $stripped = preg_replace('/[\s\-]+/', '', $raw) ?? '';
    $stripped = strtoupper($stripped);
    if ($stripped === '' || strlen($stripped) !== 12) return null;
    if (preg_match('/^[A-Z0-9]{12}$/', $stripped) !== 1) return null;
    return $stripped;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSongId_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSongId_output("");
_migSongId_output("=== iHymns — Song identity fields schema batch (#1741 P1) ===");
_migSongId_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongId_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migSongId_output("Connected to MySQL: " . DB_NAME);

try {
    _migSongId_output("--- tblSongs columns ---");
    $cols = [
        'Subtitle' => "ADD COLUMN Subtitle VARCHAR(500) NULL DEFAULT NULL
            COMMENT 'Optional song subtitle (#1741 P1)'
            AFTER NormalizedTitle",
        'Disambiguation' => "ADD COLUMN Disambiguation VARCHAR(255) NOT NULL DEFAULT ''
            COMMENT 'Short parenthetical to distinguish same-named songs (#1741 P1)'
            AFTER Subtitle",
        'CopyrightYears' => "ADD COLUMN CopyrightYears VARCHAR(100) NOT NULL DEFAULT ''
            COMMENT 'As-printed copyright year(s), free text e.g. \"1978, 1987, 2011\" (#1741 P1); Copyright stays as the legacy as-printed denorm string and is NOT auto-parsed into this + CopyrightHolder'
            AFTER Copyright",
        'CopyrightHolder' => "ADD COLUMN CopyrightHolder VARCHAR(255) NOT NULL DEFAULT ''
            COMMENT 'Copyright holder name (#1741 P1); see CopyrightYears comment re: the legacy Copyright column'
            AFTER CopyrightYears",
        'FirstPublishedYear' => "ADD COLUMN FirstPublishedYear SMALLINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Year of first publication (#1741 P1); SMALLINT not MySQL YEAR — YEAR starts 1901 and hymns predate it. Same column added to tblWorks in the same P1 batch (Song AND Work editors both need it, rule #20)'
            AFTER CopyrightHolder",
    ];
    foreach ($cols as $col => $clause) {
        if (_migSongId_colExists($mysql, 'tblSongs', $col)) {
            _migSongId_output("  [SKIP] tblSongs.{$col} already present.");
        } else {
            $mysql->query("ALTER TABLE tblSongs {$clause}");
            _migSongId_output("  [OK] Added tblSongs.{$col}.");
        }
    }

    /* ---- Backfill 1: ISWC -> canonical T-NNN.NNN.NNN-C ---- */
    _migSongId_output("--- Backfill tblSongs.Iswc to canonical form ---");
    $sel = $mysql->query("SELECT Id, Iswc FROM tblSongs WHERE Iswc IS NOT NULL AND Iswc <> ''");
    $upd = $mysql->prepare("UPDATE tblSongs SET Iswc = ? WHERE Id = ?");
    $iswcChanged = 0; $iswcUnparsed = 0; $iswcAlready = 0;
    while ($row = $sel->fetch_assoc()) {
        $current = (string)$row['Iswc'];
        $canon = _migSongId_canonicalIswc($current);
        if ($canon === null) {
            $iswcUnparsed++;
            _migSongId_output("  [WARN] tblSongs.Id={$row['Id']}: unparseable Iswc '{$current}' left untouched.");
            continue;
        }
        if ($canon === $current) { $iswcAlready++; continue; }
        $id = (int)$row['Id'];
        $upd->bind_param('si', $canon, $id);
        $upd->execute();
        $iswcChanged++;
    }
    $upd->close();
    _migSongId_output("  [OK] Iswc: {$iswcChanged} canonicalised, {$iswcAlready} already canonical, {$iswcUnparsed} left unparsed.");

    /* ---- Backfill 2: ISRC -> canonical bare 12-char (§3 amendment) ---- */
    _migSongId_output("--- Backfill tblSongs.Isrc to canonical form ---");
    $sel = $mysql->query("SELECT Id, Isrc FROM tblSongs WHERE Isrc IS NOT NULL AND Isrc <> ''");
    $upd = $mysql->prepare("UPDATE tblSongs SET Isrc = ? WHERE Id = ?");
    $isrcChanged = 0; $isrcUnparsed = 0; $isrcAlready = 0;
    while ($row = $sel->fetch_assoc()) {
        $current = (string)$row['Isrc'];
        $canon = _migSongId_canonicalIsrc($current);
        if ($canon === null) {
            $isrcUnparsed++;
            _migSongId_output("  [WARN] tblSongs.Id={$row['Id']}: unparseable Isrc '{$current}' left untouched.");
            continue;
        }
        if ($canon === $current) { $isrcAlready++; continue; }
        $id = (int)$row['Id'];
        $upd->bind_param('si', $canon, $id);
        $upd->execute();
        $isrcChanged++;
    }
    $upd->close();
    _migSongId_output("  [OK] Isrc: {$isrcChanged} canonicalised, {$isrcAlready} already canonical, {$isrcUnparsed} left unparsed.");

    /* ---- Indexes LAST — their presence is the registry probe's proof the
       backfills above completed (rule #19 step order). ---- */
    _migSongId_output("--- Indexes ---");
    if (!_migSongId_idxExists($mysql, 'tblSongs', 'idx_Iswc')) {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_Iswc (Iswc)");
        _migSongId_output("  [OK] Added index idx_Iswc.");
    } else {
        _migSongId_output("  [SKIP] idx_Iswc present.");
    }
    if (!_migSongId_idxExists($mysql, 'tblSongs', 'idx_Ccli')) {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_Ccli (Ccli)");
        _migSongId_output("  [OK] Added index idx_Ccli.");
    } else {
        _migSongId_output("  [SKIP] idx_Ccli present.");
    }

    _migSongId_output("");
    _migSongId_output("Migration complete.");
} catch (\Throwable $e) {
    _migSongId_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
