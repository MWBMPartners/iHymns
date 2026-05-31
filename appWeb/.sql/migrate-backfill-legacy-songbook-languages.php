<?php

declare(strict_types=1);

/**
 * iHymns — Backfill Language='en' on the 5 legacy English songbooks (#735)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The original 5 songbooks (CP, JP, MP, SDAH, CH) were created
 * before #673 added the Language column and currently carry
 * Language = NULL. With the multilingual scrapes from #699 about
 * to land (HA, NHA, HASD, HL, IA, plus 5 Slavic sites), it's time
 * to retro-tag the legacy 5 with their actual primary language.
 *
 * Each of the 5 is English, so this migration sets:
 *   tblSongbooks.Language = 'en'
 *   WHERE Abbreviation IN ('CP','JP','MP','SDAH','CH')
 *     AND (Language IS NULL OR Language = '')
 *
 * Why the second clause: a curator may have already set a more
 * specific tag like 'en-GB' or 'en-US' on one of these. The
 * migration MUST NOT overwrite that — re-running on a partly-
 * tagged catalogue should be a no-op for the rows already done.
 *
 * Required by:
 *   - #734 — language filter visibility scan
 *   - #736 — language filter v2 (multi-select / per-song / settings)
 *
 * The filter renders only when ≥2 distinct primary subtags exist
 * across songbooks UNION songs. Without this backfill, importing
 * one Spanish hymnal yields {es} = 1 distinct subtag = filter
 * suppressed. After this backfill, importing any non-English
 * songbook yields {en, es, …} ≥ 2 = filter renders automatically.
 *
 * Idempotent — re-running is safe; the SET-WHERE-NULL guard means
 * already-tagged rows are unaffected.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-backfill-legacy-songbook-languages.php
 *   Web: /manage/setup-database → "Backfill Legacy Songbook Languages"
 *        (entry point requires global_admin)
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migBackfillLang_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migBackfillLang_tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migBackfillLang_columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migBackfillLang_out('Legacy songbook Language backfill starting…');

$db = getDbMysqli();
if (!$db) {
    _migBackfillLang_out('ERROR: could not connect to database.');
    exit(1);
}

/* Pre-flight: tblSongbooks AND its Language column must exist.
   If Language doesn't exist, the songbook-language-column migration
   (#673) hasn't been run yet — direct the operator to run it first
   rather than auto-chaining. Keeps each migration one-liner-clear. */
if (!_migBackfillLang_tableExists($db, 'tblSongbooks')) {
    _migBackfillLang_out('ERROR: tblSongbooks not found. Run install.php first.');
    exit(1);
}
if (!_migBackfillLang_columnExists($db, 'tblSongbooks', 'Language')) {
    _migBackfillLang_out(
        'ERROR: tblSongbooks.Language column missing. ' .
        'Run migrate-songbook-language.php (#673) first, then re-run this.'
    );
    exit(1);
}

/* The 5 legacy songbooks. Hard-coded by Abbreviation because they
   are the historical baseline we know are English. Any newer
   songbook should be tagged at create time via the editor's
   Language picker (#681). */
$LEGACY_ABBRS = ['CP', 'JP', 'MP', 'SDAH', 'CH'];

/* First report what's there so the operator sees the before-state. */
$placeholders = implode(',', array_fill(0, count($LEGACY_ABBRS), '?'));
$types        = str_repeat('s', count($LEGACY_ABBRS));

$stmt = $db->prepare(
    "SELECT Abbreviation, Language
       FROM tblSongbooks
      WHERE Abbreviation IN ($placeholders)
      ORDER BY Abbreviation"
);
$stmt->bind_param($types, ...$LEGACY_ABBRS);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$rows) {
    _migBackfillLang_out(
        '[skip] None of CP/JP/MP/SDAH/CH found in tblSongbooks. ' .
        'Either this is a fresh install with no legacy data, or the ' .
        'Abbreviation values have been renamed since #673.'
    );
    _migBackfillLang_out('Legacy songbook Language backfill finished.');
    exit(0);
}

_migBackfillLang_out('Before:');
foreach ($rows as $r) {
    $label = $r['Language'] === null ? '(NULL)' : "'" . $r['Language'] . "'";
    _migBackfillLang_out("  {$r['Abbreviation']}  Language=$label");
}

/* Targeted UPDATE — only rows still NULL or empty get retagged.
   Already-set values (e.g. 'en-GB' set by a curator) are preserved. */
$stmt = $db->prepare(
    "UPDATE tblSongbooks
        SET Language = 'en'
      WHERE Abbreviation IN ($placeholders)
        AND (Language IS NULL OR Language = '')"
);
$stmt->bind_param($types, ...$LEGACY_ABBRS);
$stmt->execute();
$updated = $stmt->affected_rows;
$stmt->close();

if ($updated === 0) {
    _migBackfillLang_out("[skip] All 5 legacy songbooks already have a Language set. Nothing to backfill.");
} else {
    _migBackfillLang_out("[ok  ] Set Language='en' on {$updated} legacy songbook(s).");
}

/* Show the after-state so the operator can verify. */
$stmt = $db->prepare(
    "SELECT Abbreviation, Language
       FROM tblSongbooks
      WHERE Abbreviation IN ($placeholders)
      ORDER BY Abbreviation"
);
$stmt->bind_param($types, ...$LEGACY_ABBRS);
$stmt->execute();
$rowsAfter = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
_migBackfillLang_out('After:');
foreach ($rowsAfter as $r) {
    $label = $r['Language'] === null ? '(NULL)' : "'" . $r['Language'] . "'";
    _migBackfillLang_out("  {$r['Abbreviation']}  Language=$label");
}

_migBackfillLang_out('Legacy songbook Language backfill finished.');
