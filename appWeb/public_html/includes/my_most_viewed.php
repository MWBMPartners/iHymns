<?php

declare(strict_types=1);

/**
 * my_most_viewed.php - "Your most viewed": a person's own most-opened songs
 * ============================================================================
 * Account/profile rewrite, PR 2a step 2a-7 (epic #2180, issue #2175).
 *
 * ELI5
 * ----
 * The home page can show the songs a signed-in person opens most often, over a
 * period they choose (30 days, 90 days, 12 months or all time). This file holds
 * the rules for that list so the web, the phone apps and the API all get the same
 * answer: which periods exist, which one to use when none was asked for, and the
 * database query.
 *
 * Detailed
 * --------
 *  - It counts ALL of the person's own views: every source (a real view or an
 *    uploaded history) and every copy of the site. It is "one account, everywhere"
 *    (owner decision 4), unlike the public "most popular" list, which only counts
 *    real views from the live site (includes/song_history_provenance.php).
 *  - It never counts anybody else's views: every row is filtered by the person's
 *    id (the guard tests/php/test-my-most-viewed.php removes that filter and goes
 *    red).
 *  - Hidden songs and songs in a disabled songbook are left out, like the person's
 *    recently-viewed list (they would not open).
 *  - The default period is the person's saved choice (profile item home.mostViewedPeriod),
 *    otherwise 12 months.
 *  - The endpoint is dormant behind the per-site switch (profile_sync_gate.php).
 *
 * @see includes/profile_registry.php   home.mostViewedPeriod
 * @see api.php                          case 'my_most_viewed'
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'profile_registry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'profile_sync.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';     /* songVisibleSql() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php';  /* songServableSql() */

/** Period name => how many days back it reaches (null = no limit). The ONE list of periods. */
const MY_MOST_VIEWED_PERIODS = ['30d' => 30, '90d' => 90, '12m' => 365, 'all' => null];

/** The period used when the person has not chosen one. */
const MY_MOST_VIEWED_DEFAULT_PERIOD = '12m';

/** How many rows the query takes before the language filter, and how many are kept after it. */
const MY_MOST_VIEWED_QUERY_LIMIT = 60;
const MY_MOST_VIEWED_KEEP = 20;

/** How many reads a minute per person (one place to change it). */
const MY_MOST_VIEWED_RATE_PER_MINUTE = 60;

/**
 * PURE: is this a period we know?
 *
 * @param mixed $raw the `period` the caller sent
 */
function myMostViewedPeriodValid(mixed $raw): bool
{
    return is_string($raw) && array_key_exists($raw, MY_MOST_VIEWED_PERIODS);
}

/**
 * The period to use when the caller did not name one: the person's saved choice, otherwise 12 months.
 * A saved value that is not a known period is ignored. Never throws.
 */
function myMostViewedDefaultPeriod(\mysqli $db, int $userId): string
{
    try {
        $saved = profileItemRead($db, $userId, 'home', 'mostViewedPeriod');
        if (myMostViewedPeriodValid($saved) && profileRegistryValidate('home', 'mostViewedPeriod', $saved)) {
            return (string)$saved;
        }
    } catch (\Throwable $e) {
        if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($e)) { throw $e; }
    }
    return MY_MOST_VIEWED_DEFAULT_PERIOD;
}

/**
 * The person's most-viewed songs, most viewed first (before the language filter).
 *
 * @param \mysqli $db     live connection
 * @param int     $userId the person: EVERY row is filtered by this
 * @param string  $period one of MY_MOST_VIEWED_PERIODS
 * @return array<int,array<string,mixed>> rows: songId, title, number, songbook, songbookName, language, views (int)
 */
function myMostViewedRows(\mysqli $db, int $userId, string $period): array
{
    $days = MY_MOST_VIEWED_PERIODS[$period] ?? null;
    /* The only text added to the SQL is a fixed fragment chosen by the checked period; the day
       count is a bound value (rule #5). */
    $window = $days === null ? '' : 'AND h.ViewedAt > DATE_SUB(NOW(), INTERVAL ? DAY)';
    $limit  = MY_MOST_VIEWED_QUERY_LIMIT;
    $stmt = $db->prepare(
        'SELECT h.SongId        AS songId,
                s.Title         AS title,
                s.Number        AS number,
                s.SongbookAbbr  AS songbook,
                sb.Name         AS songbookName,
                s.Language      AS language,
                COUNT(*)        AS views
         FROM tblSongHistory h
         JOIN tblSongs s ON s.SongId = h.SongId
         LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
         WHERE h.UserId = ?
           ' . $window . '
           AND ' . songVisibleSql($db, 's') . '
           AND ' . songServableSql($db, 's') . '
         GROUP BY h.SongId, s.Title, s.Number, s.SongbookAbbr, sb.Name, s.Language
         ORDER BY views DESC, MAX(h.ViewedAt) DESC, h.SongId ASC
         LIMIT ' . (int)$limit
    );
    if ($days === null) {
        $stmt->bind_param('i', $userId);
    } else {
        $stmt->bind_param('ii', $userId, $days);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) {
        $r['views'] = (int)$r['views'];
    }
    unset($r);
    return $rows;
}
