<?php

declare(strict_types=1);

/**
 * iHymns — Backfill tblSongs.Language from tblSongbooks.Language for
 * single-language songbooks (audit follow-up).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Several bulk-imports landed every song in a non-English songbook
 * tagged `language: 'en'`. HAC is the documented example — the
 * songbook itself was correctly marked Croatian, but every member
 * song carries the English tag. The Song-of-the-Day language
 * filter (and any other consumer that trusts `tblSongs.Language`)
 * then surfaces those songs to users who've filtered for English.
 *
 * PR #975 patched the SoTD filter to PREFER the songbook's
 * `languages` set when unambiguous; this migration is the data-
 * side counterpart that fixes the underlying tags so every
 * consumer benefits without each having to add the same
 * defensive logic.
 *
 * Algorithm:
 *   1. SELECT every songbook whose `Language` column is non-empty.
 *   2. For each, find every member song whose `Language` differs
 *      from the songbook's Language (case-insensitive, primary-
 *      subtag comparison so "en-US" inside an "en" songbook
 *      stays — only blatant mismatches are touched).
 *   3. UPDATE tblSongs.Language = songbook.Language for those rows.
 *
 * Conservative on purpose:
 *   - Songs in MULTI-language songbooks (e.g. Misc / a hymnal that
 *     deliberately mixes languages — songbook.Language IS NULL or
 *     empty) are LEFT ALONE. The per-song tag is authoritative
 *     when the songbook itself doesn't declare a single language.
 *   - A song whose Language is empty / NULL is treated as
 *     "untagged" and gets the songbook's language (this is the
 *     "fix me" case the curator left to the catalogue).
 *   - A song whose Language already matches the songbook's
 *     primary subtag is untouched.
 *
 * Idempotent — re-running is safe; only rows whose Language still
 * disagrees with their songbook's get rewritten.
 *
 * @migration-updates tblSongs.Language
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-backfill-song-language-from-songbook.php
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('getDbMysqli')) {
            require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
        }
    }
    $isCli = false;
}

function _migSongLangBF_out(string $line): void
{
    if (PHP_SAPI === 'cli') {
        echo $line . "\n";
    } else {
        echo htmlspecialchars($line, ENT_QUOTES) . "<br>\n";
    }
}

function _migSongLangBF_columnExists(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
}

/* Primary-subtag comparison helper. "en-GB" and "en" compare equal;
   "en" and "hr" compare not-equal. Matches the comparison rule used
   on the client side in songbook-language-filter.js. */
function _migSongLangBF_primary(string $lang): string
{
    $lang = strtolower(trim($lang));
    if ($lang === '') return '';
    return preg_split('/[-_]/', $lang, 2)[0];
}

_migSongLangBF_out('Song language backfill from songbook starting…');

$db = getDbMysqli();
if (!$db) {
    _migSongLangBF_out('ERROR: could not connect to database.');
    if ($isCli) exit(1); else return;
}

/* Schema-tolerant — both columns must exist. */
if (!_migSongLangBF_columnExists($db, 'tblSongs', 'Language')) {
    _migSongLangBF_out('[skip] tblSongs.Language column missing — run migrate-ietf-bcp47-language.php first.');
    if ($isCli) exit(0); else return;
}
if (!_migSongLangBF_columnExists($db, 'tblSongbooks', 'Language')) {
    _migSongLangBF_out('[skip] tblSongbooks.Language column missing — run migrate-songbook-language.php first.');
    if ($isCli) exit(0); else return;
}

/* Step 1: gather the songbooks with a non-empty Language. */
$res = $db->query(
    "SELECT Abbreviation, Language
       FROM tblSongbooks
      WHERE Language IS NOT NULL
        AND Language <> ''"
);
$books = [];
while ($r = $res->fetch_assoc()) {
    $books[(string)$r['Abbreviation']] = (string)$r['Language'];
}
$res->close();

if (empty($books)) {
    _migSongLangBF_out('[ok  ] no songbook has a Language set — nothing to backfill.');
    return;
}

_migSongLangBF_out('[scan] ' . count($books) . ' songbook' . (count($books) === 1 ? '' : 's')
    . ' have a Language to propagate.');

/* Step 2: for each songbook, find songs whose Language differs and
   update them. Done one songbook at a time so the audit log line
   per book is readable and a single bad row doesn't roll back the
   whole pass. */
$totalUpdated   = 0;
$totalUntouched = 0;
$skipBookCount  = 0;

$stmtPickSongs = $db->prepare(
    "SELECT SongId, Language
       FROM tblSongs
      WHERE SongbookAbbr = ?"
);
$stmtUpdate    = $db->prepare(
    "UPDATE tblSongs SET Language = ? WHERE SongId = ?"
);

foreach ($books as $abbr => $bookLang) {
    $bookPrimary = _migSongLangBF_primary($bookLang);
    if ($bookPrimary === '') { $skipBookCount++; continue; }

    $stmtPickSongs->bind_param('s', $abbr);
    $stmtPickSongs->execute();
    $songsRes = $stmtPickSongs->get_result();

    $bookUpdated   = 0;
    $bookUntouched = 0;
    while ($row = $songsRes->fetch_assoc()) {
        $songLang    = (string)($row['Language'] ?? '');
        $songPrimary = _migSongLangBF_primary($songLang);
        if ($songPrimary === $bookPrimary) {
            $bookUntouched++;
            continue;
        }
        /* If the song already carries a more-specific BCP 47 region
           subtag whose primary matches (e.g. "en-GB" inside an "en"
           songbook) we'd have hit the equal-primary branch above —
           safe to overwrite the remaining cases. */
        $stmtUpdate->bind_param('ss', $bookLang, $row['SongId']);
        $stmtUpdate->execute();
        $bookUpdated++;
    }
    $songsRes->close();

    $totalUpdated   += $bookUpdated;
    $totalUntouched += $bookUntouched;
    if ($bookUpdated > 0) {
        _migSongLangBF_out(sprintf(
            '[fix ] %s (Language=%s): rewrote %d song%s; %d already matched.',
            $abbr, $bookLang, $bookUpdated, $bookUpdated === 1 ? '' : 's', $bookUntouched
        ));
    }
}
$stmtPickSongs->close();
$stmtUpdate->close();

_migSongLangBF_out(sprintf(
    'Backfill complete: %d song%s rewritten, %d already matched, %d songbook%s skipped (empty Language).',
    $totalUpdated, $totalUpdated === 1 ? '' : 's',
    $totalUntouched,
    $skipBookCount, $skipBookCount === 1 ? '' : 's'
));

if ($totalUpdated > 0) {
    _migSongLangBF_out('[note] Nothing to regenerate — song reads are DB-direct (WS-J #1020),');
    _migSongLangBF_out('       so the public PWA picks up the new tags on its next fetch.');
}
