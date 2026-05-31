<?php

declare(strict_types=1);

/**
 * iHymns — Media Database Providers Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds IMDB, The Movie DB (TMDB), TheTVDB, Letterboxd, Rotten Tomatoes,
 * Metacritic, AllMovie, TVmaze, Trakt, JustWatch, MyAnimeList, AniDB
 * and IGDB to the `tblExternalLinkTypes` registry (#833) and seeds the
 * matching host patterns into `tblExternalLinkPatterns` (#845).
 *
 * Forward-looking: iHymns data + DB structure is the seed for the
 * upcoming iLyrics DB + MeedyaDB, so these providers want to be on
 * every entity type (song, songbook, person, work). AppliesTo is
 * deliberately wide.
 *
 * The seed lists in `migrate-external-links.php` + `migrate-external-link-patterns.php`
 * already cover these on a fresh install — this supplementary migration
 * exists to bring already-deployed installs in line without re-running
 * the bigger migrations (whose probes are satisfied as soon as the
 * tables exist, so the dashboard wouldn't prompt to re-run them).
 *
 * Idempotent: link types upsert by Slug, patterns guard on
 * (LinkTypeId, Host, PathPrefix) before inserting.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-media-database-providers.php
 *   Web: /manage/setup-database → "Media Database Providers"
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

function _migMediaDb_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migMediaDb_tableExists(\mysqli $db, string $table): bool
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

_migMediaDb_out('Media Database Providers migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblExternalLinkTypes', 'tblExternalLinkPatterns'] as $required) {
    if (!_migMediaDb_tableExists($mysqli, $required)) {
        _migMediaDb_out("ERROR: {$required} not found. Run migrate-external-links.php"
            . ' (#833) and migrate-external-link-patterns.php (#845) first.');
        return;
    }
}

/* ----------------------------------------------------------------------
 * Step 1 — Seed link types
 *
 * Mirrors the rows appended to migrate-external-links.php $seedTypes so
 * fresh-install seed data and this supplementary migration converge on
 * identical registry state. AppliesTo deliberately wide
 * ('song,songbook,person,work') so these providers surface on every
 * entity-editor surface.
 * ---------------------------------------------------------------------- */
$applies = 'song,songbook,person,work';
$seedTypes = [
    /* slug, name, category, applies_to, allow_multiple, icon, order */
    ['imdb',            'IMDb',                 'information', $applies, 1, 'bi-film',            120],
    ['tmdb',            'The Movie DB (TMDB)',  'information', $applies, 1, 'bi-film',            121],
    ['thetvdb',         'TheTVDB',              'information', $applies, 1, 'bi-tv',              122],
    ['letterboxd',      'Letterboxd',           'information', $applies, 1, 'bi-film',            123],
    ['rotten-tomatoes', 'Rotten Tomatoes',      'information', $applies, 1, 'bi-star',            124],
    ['metacritic',      'Metacritic',           'information', $applies, 1, 'bi-star-half',       125],
    ['allmovie',        'AllMovie',             'information', $applies, 1, 'bi-film',            126],
    ['tvmaze',          'TVmaze',               'information', $applies, 1, 'bi-tv',              127],
    ['trakt',           'Trakt',                'information', $applies, 1, 'bi-tv',              128],
    ['justwatch',       'JustWatch',            'information', $applies, 1, 'bi-search',          129],
    ['myanimelist',     'MyAnimeList',          'information', $applies, 1, 'bi-collection-play', 130],
    ['anidb',           'AniDB',                'information', $applies, 1, 'bi-collection-play', 131],
    ['igdb',            'IGDB',                 'information', $applies, 1, 'bi-controller',      132],
];

$upsert = $mysqli->prepare(
    'INSERT INTO tblExternalLinkTypes
         (Slug, Name, Category, AppliesTo, AllowMultiple, IconClass, DisplayOrder)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         Name          = VALUES(Name),
         Category      = VALUES(Category),
         AppliesTo     = VALUES(AppliesTo),
         AllowMultiple = VALUES(AllowMultiple),
         IconClass     = VALUES(IconClass),
         DisplayOrder  = VALUES(DisplayOrder)'
);
$typesAdded   = 0;
$typesUpdated = 0;
foreach ($seedTypes as $t) {
    [$slug, $name, $cat, $appliesTo, $multi, $icon, $order] = $t;
    $upsert->bind_param('ssssisi', $slug, $name, $cat, $appliesTo, $multi, $icon, $order);
    @$upsert->execute();
    $ar = $mysqli->affected_rows;
    if ($ar === 1)      $typesAdded++;
    elseif ($ar === 2)  $typesUpdated++;
}
$upsert->close();
_migMediaDb_out("[seed] {$typesAdded} link type" . ($typesAdded === 1 ? '' : 's')
    . " inserted, {$typesUpdated} updated.");

/* ----------------------------------------------------------------------
 * Step 2 — Seed URL patterns
 *
 * Single bare-domain rule per provider with MatchSubdomains = 1 so
 * m.imdb.com / www.themoviedb.org / per-country justwatch subdomains
 * all match a single seed row. NOT EXISTS guard keeps the step idempotent.
 * ---------------------------------------------------------------------- */
$seedRows = [
    /* [slug, host, path-prefix-or-null, match-subdomains, priority, note?] */
    ['imdb',            'imdb.com',           null, 1, 400, 'Suffix match covers m.imdb.com / www.imdb.com'],
    ['tmdb',            'themoviedb.org',     null, 1, 410, 'Suffix match covers www.themoviedb.org'],
    ['thetvdb',         'thetvdb.com',        null, 1, 420, 'Suffix match covers www.thetvdb.com'],
    ['letterboxd',      'letterboxd.com',     null, 1, 430],
    ['rotten-tomatoes', 'rottentomatoes.com', null, 1, 440],
    ['metacritic',      'metacritic.com',     null, 1, 450],
    ['allmovie',        'allmovie.com',       null, 1, 460],
    ['tvmaze',          'tvmaze.com',         null, 1, 470],
    ['trakt',           'trakt.tv',           null, 1, 480, 'Primary domain'],
    ['justwatch',       'justwatch.com',      null, 1, 490, 'Suffix match covers per-country www.justwatch.com regional subdomains'],
    ['myanimelist',     'myanimelist.net',    null, 1, 500],
    ['anidb',           'anidb.net',          null, 1, 510],
    ['igdb',            'igdb.com',           null, 1, 520, 'Internet Game Database'],
];

$slugToId = [];
$res = $mysqli->query('SELECT Id, Slug FROM tblExternalLinkTypes');
while ($row = $res->fetch_assoc()) {
    $slugToId[(string)$row['Slug']] = (int)$row['Id'];
}
$res->close();

$insert = $mysqli->prepare(
    'INSERT INTO tblExternalLinkPatterns
         (LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority, Note)
     SELECT ?, ?, ?, ?, ?, ?
       FROM DUAL
      WHERE NOT EXISTS (
            SELECT 1 FROM tblExternalLinkPatterns
             WHERE LinkTypeId = ?
               AND Host       = ?
               AND COALESCE(PathPrefix, "") = COALESCE(?, "")
      )'
);

$pInserted = 0;
$pSkipped  = 0;
$pMissing  = 0;
foreach ($seedRows as $r) {
    $slug    = (string)$r[0];
    $host    = (string)$r[1];
    $path    = $r[2] !== null ? (string)$r[2] : null;
    $matchSd = (int)$r[3];
    $prio    = (int)$r[4];
    $note    = isset($r[5]) ? (string)$r[5] : null;

    if (!isset($slugToId[$slug])) {
        $pMissing++;
        continue;
    }
    $typeId = $slugToId[$slug];

    $insert->bind_param(
        'issiisiss',
        $typeId, $host, $path, $matchSd, $prio, $note,
        $typeId, $host, $path
    );
    $insert->execute();
    if ($mysqli->affected_rows > 0) $pInserted++;
    else                            $pSkipped++;
}
$insert->close();

_migMediaDb_out("[seed] {$pInserted} pattern" . ($pInserted === 1 ? '' : 's')
    . " inserted, {$pSkipped} already present"
    . ($pMissing > 0 ? ", {$pMissing} skipped (link type missing)" : '')
    . '.');

_migMediaDb_out('Media Database Providers migration finished.');
