<?php

declare(strict_types=1);

/**
 * iHymns — Give songs their songbook's language (audit follow-up; manual
 * since the #2137 review).
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
 * side counterpart that fixes the underlying tags.
 *
 * WHAT IT CHANGES — for every songbook that declares ONE ordinary language:
 *   - a member song with NO language at all is given the songbook's
 *     language ("filled");
 *   - a member song in a DIFFERENT ordinary language (the HAC case: `en`
 *     inside a Croatian book) is given the songbook's language
 *     ("rewritten");
 *   - a member song whose language is already in the songbook's language
 *     group (`en-GB` inside an `en` book) is left alone.
 *
 * WHAT IT NEVER TOUCHES (#2137 review — it used to overwrite all of these):
 *   - `und` (nobody knows), `mul` (several languages), `zxx` (no language),
 *     `mis` (a language with no code), the local-use codes `qaa`–`qtz`,
 *     private-use and old "grandfathered" tags (`x-hymnal`, `i-default`),
 *     and anything malformed. Each of those is a deliberate statement, and
 *     replacing it with the songbook's language is a guess policy LANG-003
 *     forbids. `mediaLanguageIsOrdinaryLanguage()` decides, the same test the
 *     card's probe uses (migration-registry.php).
 *   - songbooks with no language, or whose language is itself one of the
 *     values above (a `mul` book is not a claim about any one song).
 *
 * WHY IT IS A MANUAL CARD NOW (#2137 review):
 *   It used to run from "Apply all pending migrations", which meant a
 *   routine upgrade silently overwrote song languages and nothing could undo
 *   it. Filling a song that has no language from its songbook is a GUESS
 *   (policy LANG-003: unknown stays unknown unless someone decides), so the
 *   decision now belongs to a curator: the card is `'manual' => true` in the
 *   registry (never run by "Apply all" or the setup wizard), and the script
 *   is a DRY RUN unless confirmed — a web run without `&confirm=1`, or a CLI
 *   run without `--confirm`, only reports what it would change.
 *
 * Idempotent — re-running is safe; only rows that still qualify change.
 *
 * @migration-updates tblSongs.Language
 *
 * USAGE:
 *   Web (report):  /manage/setup-database → this card's "Dry-run" link
 *   Web (apply):   the card's confirm button (adds &confirm=1)
 *   CLI (report):  php appWeb/.sql/migrate-backfill-song-language-from-songbook.php
 *   CLI (apply):   php appWeb/.sql/migrate-backfill-song-language-from-songbook.php --confirm
 */

/* Shared includes: resolved through the runner's real docroot, because the
   deployed docroot is renamed per channel (rule #41). The literal is the repo
   fallback for a CLI or test run only. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
if (!function_exists('getDbMysqli')) {
    require_once $_incDir . '/db_mysql.php';
}
require_once $_incDir . '/media_language.php';

/**
 * Do the work against one database connection.
 *
 * Kept as a function (called at the bottom unless
 * IHYMNS_MIGRATION_NO_AUTORUN is defined) so the test suite can run it against
 * a throwaway database: tests/php/test-song-language-backfill.php.
 *
 * @param \mysqli             $db
 * @param bool                $apply  false = report only (the default for a web or CLI run)
 * @param callable(string):void $out  one line of output
 * @return array{filled:int, rewritten:int, matched:int, skippedSpecial:int, skippedMalformed:int, booksSkipped:int}
 */
function migrateBackfillSongLanguageFromSongbook(\mysqli $db, bool $apply, callable $out): array
{
    $counts = ['filled' => 0, 'rewritten' => 0, 'matched' => 0,
               'skippedSpecial' => 0, 'skippedMalformed' => 0, 'booksSkipped' => 0];

    /* Without the shared rules nothing can tell `und` from a real language,
       so stop rather than guess. */
    mediaLanguageRequire();

    foreach ([['tblSongs', 'Language'], ['tblSongbooks', 'Language']] as [$table, $column]) {
        $stmt = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if (!$exists) {
            $out("[skip] {$table}.{$column} is missing — run the earlier language migrations first.");
            return $counts;
        }
    }

    $books = [];
    $res = $db->query("SELECT Abbreviation, Language FROM tblSongbooks WHERE Language IS NOT NULL AND Language <> ''");
    while ($r = $res->fetch_assoc()) {
        $books[(string)$r['Abbreviation']] = (string)$r['Language'];
    }
    $res->close();

    $pick   = $db->prepare('SELECT SongId, Language FROM tblSongs WHERE SongbookAbbr = ?');
    $update = $db->prepare('UPDATE tblSongs SET Language = ? WHERE SongId = ?');

    foreach ($books as $abbr => $bookRaw) {
        $bookTag = mediaLanguageTagForStorage($bookRaw);
        if (!is_string($bookTag) || !mediaLanguageIsOrdinaryLanguage($bookTag)) {
            $counts['booksSkipped']++;
            $out("[skip] {$abbr}: the songbook's language (\"{$bookRaw}\") is not one ordinary language — its songs are left alone.");
            continue;
        }
        $bookGroup = mediaLanguageGroup($bookTag);

        $pick->bind_param('s', $abbr);
        $pick->execute();
        $songs = $pick->get_result();
        $bookFilled = 0;
        $bookRewritten = 0;
        while ($row = $songs->fetch_assoc()) {
            $songRaw = trim((string)($row['Language'] ?? ''));
            if ($songRaw === '') {
                $action = 'fill';
            } else {
                $songTag = mediaLanguageTagForStorage($songRaw);
                if (!is_string($songTag)) {
                    $counts['skippedMalformed']++;
                    continue;
                }
                if (!mediaLanguageIsOrdinaryLanguage($songTag)) {
                    $counts['skippedSpecial']++;          // und / mul / zxx / mis / qaa–qtz / x-… — never touched
                    continue;
                }
                if (mediaLanguageGroup($songTag) === $bookGroup) {
                    $counts['matched']++;
                    continue;
                }
                $action = 'rewrite';
            }
            if ($apply) {
                $songId = (string)$row['SongId'];
                $update->bind_param('ss', $bookTag, $songId);
                $update->execute();
            }
            if ($action === 'fill') { $counts['filled']++; $bookFilled++; }
            else                    { $counts['rewritten']++; $bookRewritten++; }
        }
        $songs->close();
        if ($bookFilled + $bookRewritten > 0) {
            $out(sprintf('[%s] %s → %s: %d song%s with no language, %d in another language.',
                $apply ? 'fix ' : 'plan', $abbr, $bookTag,
                $bookFilled, $bookFilled === 1 ? '' : 's', $bookRewritten));
        }
    }
    $pick->close();
    $update->close();

    $out(sprintf(
        '%s: %d song%s given the songbook\'s language because they had none, %d changed from another language, '
        . '%d already matched; left alone: %d with a special code (und, mul, zxx, mis, qaa–qtz, private-use), '
        . '%d malformed, %d songbook%s without one ordinary language.',
        $apply ? 'Done' : 'Dry run — nothing was changed',
        $counts['filled'], $counts['filled'] === 1 ? '' : 's', $counts['rewritten'], $counts['matched'],
        $counts['skippedSpecial'], $counts['skippedMalformed'],
        $counts['booksSkipped'], $counts['booksSkipped'] === 1 ? '' : 's'
    ));
    return $counts;
}

if (!defined('IHYMNS_MIGRATION_NO_AUTORUN')) {
    $isCli = PHP_SAPI === 'cli';
    $out = static function (string $line) use ($isCli): void {
        echo $isCli ? $line . "\n" : htmlspecialchars($line, ENT_QUOTES) . "<br>\n";
    };
    /* A curator's decision: report only unless explicitly confirmed. */
    $apply = $isCli
        ? in_array('--confirm', $argv ?? [], true)
        : (($_GET['confirm'] ?? '') === '1');
    $out($apply
        ? 'Giving songs their songbook\'s language (confirmed).'
        : 'DRY RUN — reporting only. Add ' . ($isCli ? '--confirm' : '&confirm=1') . ' to apply.');
    $db = getDbMysqli();
    if (!$db) {
        $out('ERROR: could not connect to database.');
        if ($isCli) { exit(1); }
        return;
    }
    try {
        migrateBackfillSongLanguageFromSongbook($db, $apply, $out);
    } catch (\RuntimeException $e) {
        $out('ERROR: ' . $e->getMessage());
        if ($isCli) { exit(1); }
    }
}
