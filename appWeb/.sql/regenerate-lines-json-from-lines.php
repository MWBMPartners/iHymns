<?php

declare(strict_types=1);

/**
 * iHymns — REBUILD the tblSongComponents JSON payload columns FROM tblLyricLines
 *          (#1235 P4 / C6 — reversibility layer 3)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * The recovery counterpart to migrate-retire-component-lines-json.php. Because the
 * authoritative tblLyricLines is a strict SUPERSET of what the retired JSON columns held
 * (every line's text, per-line chords, per-line note, per-line language), those columns can
 * be reconstructed losslessly at any time — so the C6 drop is reversible without a backup
 * restore. Run this to:
 *   - un-drop a regretted C6 (re-ADD the columns + backfill), or
 *   - re-sync a stale shadow before the drop (idempotent refresh).
 *
 * Re-adds each missing column (LinesJson added NULLable, then tightened to NOT NULL once
 * every row carries an array, matching the canonical schema), then rebuilds all four per
 * component from its tblLyricLines rows (grouped by ComponentId, ordered by SortOrder):
 *   LinesJson     = [LineText, …]
 *   ChordsJson    = [decode(ll.ChordsJson)|null, …]      (null when no line carries a chord)
 *   NotesJson     = [ll.Note|null, …]                    (null when no line carries a note)
 *   LanguagesJson = [override|null, …]  where override = ll.LanguageCode when it differs
 *                   from the component Language, else null (the inherit rule) — null when none.
 *
 * Strictly additive + idempotent; reads the AUTHORITATIVE store only. Chunked per songbook
 * so memory stays flat (#929). NOT a registered "Apply all" migration — a deliberate
 * recovery tool, run by hand.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/regenerate-lines-json-from-lines.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migRegen_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

function _migRegen_colExists(\mysqli $db, string $table, string $col): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migRegen_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migRegen_out('=== iHymns — REBUILD tblSongComponents JSON columns FROM tblLyricLines (#1235 P4 / C6) ===');

try {
    if (!_migRegen_colExists($db, 'tblLyricLines', 'ChordsJson')) {
        _migRegen_out('  [ABORT] tblLyricLines (the authoritative source) has no ChordsJson column — the mirror is not present. Nothing to rebuild from.');
        if ($isCli) { exit(1); }
        return;
    }

    /* --- Re-ADD the columns if missing (post-drop recovery). LinesJson goes back NULLable
       first so the ADD succeeds on a populated table; it is tightened to NOT NULL after the
       backfill fills every row. DDL/comments match the pre-C6 schema.sql. --- */
    $addCols = [
        'LinesJson'     => "LinesJson JSON NULL DEFAULT NULL COMMENT 'Array of lyric lines' AFTER SortOrder",
        'ChordsJson'    => "ChordsJson JSON NULL DEFAULT NULL COMMENT 'Per-line chord annotations parallel to LinesJson; null-padded array e.g. [null,[\"C\",\"Am\"],null]. Lossless chord interchange so importers stop regex-stripping chord rows (#1066 Theme E)' AFTER Language",
        'NotesJson'     => "NotesJson JSON NULL DEFAULT NULL COMMENT 'Per-line presenter/slide notes parallel to LinesJson; null-padded array of strings e.g. [null,\"Repeat 2x\",null]. ProPresenter speaker notes round-trip (#1066 Theme E)' AFTER ChordsJson",
        'LanguagesJson' => "LanguagesJson JSON NULL DEFAULT NULL COMMENT 'Per-line language overrides parallel to LinesJson; null-padded IETF BCP 47 tags. A null/absent entry inherits the component Language. Durable home for per-line language so it survives lyric-line reprojection (#1235 P3 / #1253)' AFTER NotesJson",
    ];
    foreach ($addCols as $col => $ddl) {
        if (_migRegen_colExists($db, 'tblSongComponents', $col)) {
            _migRegen_out("  [SKIP] tblSongComponents.{$col} already present.");
        } else {
            $db->query("ALTER TABLE tblSongComponents ADD COLUMN {$ddl}");
            _migRegen_out("  [OK] Re-added tblSongComponents.{$col}.");
        }
    }

    /* --- Backfill, chunked per songbook (#929). For each component, rebuild the four arrays
       from its tblLyricLines rows. --- */
    $upd = $db->prepare(
        "UPDATE tblSongComponents SET LinesJson = ?, ChordsJson = ?, NotesJson = ?, LanguagesJson = ? WHERE Id = ?"
    );

    $books = [];
    $br = $db->query("SELECT DISTINCT SongbookAbbr FROM tblSongs ORDER BY SongbookAbbr");
    while ($row = $br->fetch_row()) { $books[] = (string)$row[0]; }
    $br->close();

    $compsTouched = 0;
    foreach ($books as $abbr) {
        /* Component metadata for this songbook (Language drives the per-line override calc). */
        $cs = $db->prepare(
            "SELECT c.Id, c.Language
               FROM tblSongComponents c JOIN tblSongs s ON s.SongId = c.SongId
              WHERE s.SongbookAbbr = ?"
        );
        $cs->bind_param('s', $abbr);
        $cs->execute();
        $compLang = [];
        $cres = $cs->get_result();
        while ($row = $cres->fetch_assoc()) {
            $compLang[(int)$row['Id']] = ($row['Language'] !== null && $row['Language'] !== '') ? (string)$row['Language'] : null;
        }
        $cs->close();
        if (empty($compLang)) { continue; }

        /* All mirrored lines for this songbook's primary versions, grouped by component. */
        $ls = $db->prepare(
            "SELECT ll.ComponentId, ll.LineText, ll.ChordsJson, ll.Note, ll.LanguageCode
               FROM tblLyricLines ll
               JOIN tblLyrics ly ON ly.Id = ll.LyricsId
               JOIN tblSongs s   ON s.SongId = ly.SongId
              WHERE s.SongbookAbbr = ? AND ly.Source = 'ihymns' AND ll.ComponentId IS NOT NULL
              ORDER BY ll.ComponentId, ll.SortOrder, ll.Id"
        );
        $ls->bind_param('s', $abbr);
        $ls->execute();
        $lres = $ls->get_result();

        /* Group rows by ComponentId into the four parallel arrays. */
        $byComp = [];
        while ($row = $lres->fetch_assoc()) {
            $cid = (int)$row['ComponentId'];
            $byComp[$cid]['lines'][]  = (string)$row['LineText'];
            $byComp[$cid]['chords'][] = ($row['ChordsJson'] !== null && $row['ChordsJson'] !== '')
                ? (json_decode((string)$row['ChordsJson'], true) ?? null) : null;
            $byComp[$cid]['notes'][]  = ($row['Note'] !== null && $row['Note'] !== '') ? (string)$row['Note'] : null;
            $byComp[$cid]['langs'][]  = ($row['LanguageCode'] !== null && $row['LanguageCode'] !== '') ? (string)$row['LanguageCode'] : null;
        }
        $ls->close();

        /* Write every component of this songbook (even line-less ones → LinesJson '[]'). */
        foreach ($compLang as $cid => $cLang) {
            $g       = $byComp[$cid] ?? ['lines' => [], 'chords' => [], 'notes' => [], 'langs' => []];
            $lines   = $g['lines'];
            $linesJson = json_encode($lines, JSON_UNESCAPED_UNICODE);

            $anyChord = false;
            foreach ($g['chords'] as $v) { if ($v !== null) { $anyChord = true; break; } }
            $chordsJson = $anyChord ? json_encode($g['chords'], JSON_UNESCAPED_UNICODE) : null;

            $anyNote = false;
            foreach ($g['notes'] as $v) { if ($v !== null) { $anyNote = true; break; } }
            $notesJson = $anyNote ? json_encode($g['notes'], JSON_UNESCAPED_UNICODE) : null;

            /* Override = effective language that differs from the component default. */
            $override = []; $anyLang = false;
            foreach ($g['langs'] as $lv) {
                $ov = ($lv !== null && $lv !== $cLang) ? $lv : null;
                $override[] = $ov;
                if ($ov !== null) { $anyLang = true; }
            }
            $langsJson = $anyLang ? json_encode($override, JSON_UNESCAPED_UNICODE) : null;

            $upd->bind_param('ssssi', $linesJson, $chordsJson, $notesJson, $langsJson, $cid);
            $upd->execute();
            $compsTouched++;
        }
    }
    $upd->close();
    _migRegen_out("  [OK] Rebuilt JSON columns for {$compsTouched} component(s).");

    /* Tighten LinesJson back to NOT NULL (canonical) now every row carries an array. */
    if (_migRegen_colExists($db, 'tblSongComponents', 'LinesJson')) {
        $nulls = (int)$db->query("SELECT COUNT(*) FROM tblSongComponents WHERE LinesJson IS NULL")->fetch_row()[0];
        if ($nulls === 0) {
            $db->query(
                "ALTER TABLE tblSongComponents MODIFY COLUMN LinesJson JSON NOT NULL COMMENT 'Array of lyric lines'"
            );
            _migRegen_out('  [OK] LinesJson tightened back to NOT NULL (canonical).');
        } else {
            _migRegen_out("  [WARN] {$nulls} row(s) still have NULL LinesJson — leaving the column NULLable. Investigate before relying on it.");
        }
    }

    _migRegen_out('Done. tblSongComponents JSON payload columns rebuilt from tblLyricLines.');
} catch (\Throwable $e) {
    _migRegen_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
