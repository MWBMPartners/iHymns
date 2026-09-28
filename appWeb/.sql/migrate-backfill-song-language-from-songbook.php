<?php

declare(strict_types=1);

/**
 * iHymns — Give songs that have NO language their songbook's language
 * (audit follow-up; manual and fill-only since the #2137 reviews).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Some songs were imported with no language at all, in songbooks that
 * declare one. This card lets a curator decide to give those songs their
 * songbook's language.
 *
 * WHAT IT CHANGES — only this:
 *   a song whose language is EMPTY — NULL, or nothing but spaces, tabs and
 *   line breaks (mediaLanguageIsBlank(), the one test for this; a no-break
 *   or zero-width space is a VALUE and is listed, not filled) — in a
 *   songbook that declares ONE ordinary language, is given that language.
 *
 * WHAT IT NEVER CHANGES (#2137 reviews):
 *   a song that already has ANY value — a real tag, a special code such as
 *   `und`, or a malformed value. The first version of this card rewrote
 *   every song whose language "differed" from its songbook's, so `yue` and
 *   `cmn-Hans` songs in a `zh` book became `zh`, and a `de` song in an `hr`
 *   book became `hr`, with no record — a declared language replaced by a
 *   guess (policy LANG-003, COMPAT-040). That branch is gone. Songs whose
 *   language differs from their songbook's are LISTED instead (song id,
 *   current language, songbook language), in the dry run and in the
 *   confirmed run's report, for a curator to review by hand.
 *   It also skips songbooks with no language, or whose language is not one
 *   ordinary language (`mul`, `und`, a malformed value).
 *
 * HOW A CURATOR RUNS IT:
 *   - It is a manual card ('manual' => true in the migration registry): never
 *     run by "Apply all", the bulk runner, the pending counter or the setup
 *     wizard.
 *   - It is a DRY RUN unless confirmed — a web run without `&confirm=1`, or
 *     a command-line run without `--confirm`, only reports. The decision is
 *     migrateBackfillSongLanguageConfirmed(), below, so it is tested.
 *   - The dry run lists every song it would fill (id, "none" → new language)
 *     and every song it would leave alone because its language differs.
 *   - The confirmed run writes one activity-log row per filled song (action
 *     `migration.song_language_backfill`; Details: from, to, songbook,
 *     "set from songbook by the backfill card", and who ran it), in the same
 *     transaction as the change, so every fill can be traced and undone.
 *     WHO ran it (#2137 review round 4): from the web, the row's UserId is
 *     the signed-in user and Details.ranBy is "web"; from the command line,
 *     UserId is empty and Details.ranBy is "command line". A confirmed web
 *     run that cannot tell who is signed in changes nothing.
 *   - If another person changes a song's language while a confirmed run is
 *     under way, their change is never overwritten. What happens next
 *     depends on the database, and both are safe: on MySQL (and MariaDB
 *     without snapshot isolation) that one song is skipped and the rest are
 *     filled; on MariaDB 11.8, whose `innodb_snapshot_isolation` is on by
 *     default, the whole run stops with "Record has changed since last
 *     read" and is rolled back — nothing filled, nothing logged — so it can
 *     simply be run again. Both observed in tests/php/test-song-language-backfill.php.
 *
 * Idempotent — a filled song has a language, so a second run fills nothing.
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

/** The activity-log action every fill is recorded under. */
const IHYMNS_BACKFILL_SONG_LANGUAGE_ACTION = 'migration.song_language_backfill';

/**
 * Has the person running this card confirmed it? (#2137 review)
 *
 * ELI5: "change things, or only say what would change?" Only an explicit
 * `--confirm` on the command line, or `confirm=1` in the web address (the
 * card's confirm button adds it), changes anything. Anything else is a dry
 * run. Kept as a function so the test can hold it to that.
 *
 * @param bool                 $isCli Is this a command-line run?
 * @param list<string>         $argv  The command-line arguments.
 * @param array<string, mixed> $get   The web request's query values ($_GET).
 */
function migrateBackfillSongLanguageConfirmed(bool $isCli, array $argv, array $get): bool
{
    return $isCli
        ? in_array('--confirm', $argv, true)
        : (($get['confirm'] ?? '') === '1');
}

/**
 * Who is running this card, for the activity log (#2137 review round 4)?
 *
 * ELI5: the trail must say who did it. From the web that is the signed-in
 * user; from the command line there is no signed-in user, so the trail says
 * "command line" instead of leaving it blank as if nobody did it.
 *
 * @param bool       $isCli       Is this a command-line run?
 * @param array|null $currentUser The signed-in user (getCurrentUser(): 'id'), or null.
 * @return array{userId: int|null, ranBy: string} ranBy is "command line" or "web".
 */
function migrateBackfillSongLanguageActor(bool $isCli, ?array $currentUser): array
{
    if ($isCli) {
        return ['userId' => null, 'ranBy' => 'command line'];
    }
    $id = (int)($currentUser['id'] ?? 0);
    return ['userId' => $id > 0 ? $id : null, 'ranBy' => 'web'];
}

/**
 * Do the work against one database connection.
 *
 * @param \mysqli               $db
 * @param bool                  $apply false = report only
 * @param callable(string):void $out   one line of output
 * @param array{userId: int|null, ranBy: string} $actor
 *        Who is running it (migrateBackfillSongLanguageActor()), written into
 *        every activity-log row. Required, so no caller can forget it.
 * @return array{filled: list<array{songId:string, to:string, songbook:string}>,
 *               differing: list<array{songId:string, language:string, songbook:string, songbookLanguage:string}>,
 *               matched:int, booksSkipped:int}
 * @throws \RuntimeException when the shared rules are missing, or (on a
 *         confirmed run) when there is nowhere to record the changes, or no
 *         way to say who made them.
 */
function migrateBackfillSongLanguageFromSongbook(\mysqli $db, bool $apply, callable $out, array $actor): array
{
    $result = ['filled' => [], 'differing' => [], 'matched' => 0, 'booksSkipped' => 0];

    /* Without the shared rules nothing can tell a real language from `mul`. */
    mediaLanguageRequire();

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    };
    foreach ([['tblSongs', 'Language'], ['tblSongbooks', 'Language']] as [$table, $column]) {
        if (!$columnExists($table, $column)) {
            $out("[skip] {$table}.{$column} is missing — run the earlier language migrations first.");
            return $result;
        }
    }
    /* No record, no change: a confirmed run must be able to write its trail. */
    if ($apply && !$columnExists('tblActivityLog', 'Details')) {
        throw new \RuntimeException('tblActivityLog is missing, so the changes could not be recorded; nothing was changed.');
    }
    /* …and the trail must say who: a web run with no signed-in user (which
       the setup page's own sign-in check should make impossible) or an
       actor this function does not recognise changes nothing. */
    $ranBy  = (string)($actor['ranBy'] ?? '');
    $userId = isset($actor['userId']) ? (int)$actor['userId'] : null;
    if ($apply && !($ranBy === 'command line' || ($ranBy === 'web' && $userId !== null && $userId > 0))) {
        throw new \RuntimeException('Could not tell who is running this card, so the changes could not be recorded; nothing was changed.');
    }

    $books = [];
    $res = $db->query("SELECT Abbreviation, Language FROM tblSongbooks WHERE Language IS NOT NULL AND Language <> '' ORDER BY Abbreviation");
    while ($r = $res->fetch_assoc()) {
        $books[(string)$r['Abbreviation']] = (string)$r['Language'];
    }
    $res->close();

    $pick   = $db->prepare('SELECT SongId, Language FROM tblSongs WHERE SongbookAbbr = ? ORDER BY SongId');
    /* `CAST(Language AS BINARY) <=> CAST(? AS BINARY)` (null-safe equals,
       byte for byte, bound to the value just read): the row is changed only
       if nobody else changed it in between. If someone did, the database
       decides what happens next, and both ways are safe (#2137 review round
       4 — this comment used to promise only the first): on MySQL, and
       MariaDB without snapshot isolation, the UPDATE matches no row and that
       one song is skipped; on MariaDB 11.8 with `innodb_snapshot_isolation`
       on (its default), the UPDATE itself fails with "Record has changed
       since last read", and the catch below rolls the WHOLE run back.
       #2137 review round 5 (L5) — BYTES, not the column's collation. Until
       this round the guard was `Language <=> ?`, compared by the collation
       (utf8mb4_unicode_ci), which counts an empty value, a space, a no-break
       space and a zero-width space as equal. So on MySQL 8.4 (and MariaDB
       with snapshot isolation off) somebody changing an empty language to a
       no-break or zero-width space, or to a space, or a space to empty,
       while the card ran had their change OVERWRITTEN with the songbook's
       language — the fourth review reproduced four such cases. Compared as
       bytes, any change at all is seen. (A binary COLLATION would not do:
       utf8mb4_bin still ignores trailing spaces.) The card only ever fills a
       value that was NULL or only ASCII spaces, tabs and line breaks, and
       those are the same bytes in every character set the connection could
       use; so a mismatch of character sets can only make a song fail to
       match — skipped, the safe way — never make a changed one match. */
    $update = $apply ? $db->prepare('UPDATE tblSongs SET Language = ? WHERE SongId = ? AND CAST(Language AS BINARY) <=> CAST(? AS BINARY)') : null;
    /* Straight into tblActivityLog rather than through logActivity(): that
       helper stops after 200 rows per request (a guard against runaway
       loops), and a trail that stopped part-way could not be used to undo
       the run. Every other column of the table has a default. */
    $record = $apply
        ? $db->prepare('INSERT INTO tblActivityLog (UserId, Action, EntityType, EntityId, Result, Details) VALUES (?, ?, \'song\', ?, \'success\', ?)')
        : null;

    /* One transaction for every fill AND its log row: a fill whose record
       could not be written must not survive (tested by making the log refuse
       a row part-way through). */
    if ($apply) {
        $db->begin_transaction();
    }
    try {
        foreach ($books as $abbr => $bookRaw) {
            $bookTag = mediaLanguageTagForStorage($bookRaw);
            if (!is_string($bookTag) || !mediaLanguageIsOrdinaryLanguage($bookTag)) {
                $result['booksSkipped']++;
                $out("[skip] {$abbr}: the songbook's language (\"{$bookRaw}\") is not one ordinary language — its songs are left alone.");
                continue;
            }
            $bookGroup = mediaLanguageGroup($bookTag);

            $pick->bind_param('s', $abbr);
            $pick->execute();
            $songs = $pick->get_result();
            while ($row = $songs->fetch_assoc()) {
                $songId  = (string)$row['SongId'];
                $songRaw = trim((string)($row['Language'] ?? ''), " \t\r\n");
                if (!mediaLanguageIsBlank($row['Language'] === null ? null : (string)$row['Language'])) {
                    /* ANY value is kept. One that says a different language
                       from the songbook is listed for a curator to review. */
                    $songTag = mediaLanguageTagForStorage($songRaw);
                    if (is_string($songTag) && mediaLanguageGroup($songTag) === $bookGroup) {
                        $result['matched']++;
                    } else {
                        $result['differing'][] = ['songId' => $songId, 'language' => $songRaw,
                                                  'songbook' => $abbr, 'songbookLanguage' => $bookTag];
                    }
                    continue;
                }
                /* No language at all: the one case this card fills. */
                if ($apply) {
                    $before = $row['Language'];
                    $update->bind_param('sss', $bookTag, $songId, $before);
                    $update->execute();
                    if ($update->affected_rows !== 1) {
                        continue;   // changed by someone else since it was read — leave it (see the UPDATE's comment)
                    }
                    $details = json_encode([
                        'field'    => 'Language',
                        'from'     => $row['Language'],
                        'to'       => $bookTag,
                        'songbook' => $abbr,
                        'note'     => 'set from songbook by the backfill card',
                        'ranBy'    => $ranBy,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $action = IHYMNS_BACKFILL_SONG_LANGUAGE_ACTION;
                    $logUser = $ranBy === 'web' ? $userId : null;
                    $record->bind_param('isss', $logUser, $action, $songId, $details);
                    $record->execute();
                }
                $result['filled'][] = ['songId' => $songId, 'to' => $bookTag, 'songbook' => $abbr];
            }
            $songs->close();
        }
        if ($apply) {
            $db->commit();
        }
    } catch (\Throwable $e) {
        if ($apply) {
            $db->rollback();
        }
        throw $e;
    } finally {
        $pick->close();
        if ($update) { $update->close(); }
        if ($record) { $record->close(); }
    }

    $verb = $apply ? 'filled' : 'would fill';
    foreach ($result['filled'] as $f) {
        $out("[{$verb}] {$f['songId']} ({$f['songbook']}): none → {$f['to']}");
    }
    foreach ($result['differing'] as $d) {
        $out("[review by hand, not changed] {$d['songId']}: \"{$d['language']}\" differs from songbook {$d['songbook']} ({$d['songbookLanguage']})");
    }
    $out(sprintf(
        '%s: %d song%s %s from the songbook (each recorded in the activity log as %s%s); '
        . '%d song%s already in the songbook\'s language; %d song%s in a different language, listed above for review and not changed; '
        . '%d songbook%s skipped.',
        $apply ? 'Done' : 'Dry run — nothing was changed',
        count($result['filled']), count($result['filled']) === 1 ? '' : 's',
        $apply ? 'given a language' : 'would be given a language',
        IHYMNS_BACKFILL_SONG_LANGUAGE_ACTION, $apply ? '' : ' on a confirmed run',
        $result['matched'], $result['matched'] === 1 ? '' : 's',
        count($result['differing']), count($result['differing']) === 1 ? '' : 's',
        $result['booksSkipped'], $result['booksSkipped'] === 1 ? '' : 's'
    ));
    return $result;
}

if (!defined('IHYMNS_MIGRATION_NO_AUTORUN')) {
    /* Run from /manage/setup-database (which defines IHYMNS_SETUP_DASHBOARD
       before it requires this file) is a WEB run, whatever the server's PHP
       type: confirmed by `confirm=1`, recorded against the signed-in user. */
    $isCli = PHP_SAPI === 'cli' && !defined('IHYMNS_SETUP_DASHBOARD');
    $out = static function (string $line) use ($isCli): void {
        echo $isCli ? $line . "\n" : htmlspecialchars($line, ENT_QUOTES) . "<br>\n";
    };
    /* A curator's decision: report only unless explicitly confirmed. */
    $apply = migrateBackfillSongLanguageConfirmed($isCli, $isCli ? ($argv ?? []) : [], $_GET);
    $out($apply
        ? 'Giving songs with no language their songbook\'s language (confirmed).'
        : 'DRY RUN — reporting only. Add ' . ($isCli ? '--confirm' : '&confirm=1') . ' to apply.');
    $db = getDbMysqli();
    if (!$db) {
        $out('ERROR: could not connect to database.');
        if ($isCli) { exit(1); }
        return;
    }
    $actor = migrateBackfillSongLanguageActor($isCli, (!$isCli && function_exists('getCurrentUser')) ? getCurrentUser() : null);
    try {
        migrateBackfillSongLanguageFromSongbook($db, $apply, $out, $actor);
    } catch (\RuntimeException $e) {
        $out('ERROR: ' . $e->getMessage());
        if ($isCli) { exit(1); }
    }
}
