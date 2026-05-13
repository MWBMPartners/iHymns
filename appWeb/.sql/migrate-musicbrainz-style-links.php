<?php

declare(strict_types=1);

/**
 * iHymns — MusicBrainz-Parity External Links Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds six more external-link providers commonly surfaced on a MusicBrainz
 * artist page that iHymns didn't yet detect:
 *
 *   - Myspace        (legacy social, still hosts older artist pages)
 *   - AllMusic       (sister site to AllMovie / Discogs-style metadata)
 *   - Last.fm        (scrobble-based listen + discovery)
 *   - Bandsintown    (touring / live performance dates)
 *   - Genius         (lyrics + annotations)
 *   - Muzikum.eu     (lyrics + translations)
 *
 * The seed lists in `migrate-external-links.php` +
 * `migrate-external-link-patterns.php` already cover these on a fresh
 * install; this supplementary migration brings already-deployed installs
 * in line via the dashboard.
 *
 * Idempotent: link types upsert by Slug, patterns guard on
 * (LinkTypeId, Host, PathPrefix) before inserting.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-musicbrainz-style-links.php
 *   Web: /manage/setup-database → "MusicBrainz-Parity External Links"
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

function _migMbLinks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migMbLinks_tableExists(\mysqli $db, string $table): bool
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

_migMbLinks_out('MusicBrainz-Parity External Links migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblExternalLinkTypes', 'tblExternalLinkPatterns'] as $required) {
    if (!_migMbLinks_tableExists($mysqli, $required)) {
        _migMbLinks_out("ERROR: {$required} not found. Run migrate-external-links.php"
            . ' (#833) and migrate-external-link-patterns.php (#845) first.');
        return;
    }
}

/* ----------------------------------------------------------------------
 * Step 1 — Seed link types
 * ---------------------------------------------------------------------- */
$seedTypes = [
    /* slug, name, category, applies_to, allow_multiple, icon, order */
    ['myspace',     'Myspace',     'social',      'person',        0, 'bi-people',            105],
    ['allmusic',    'AllMusic',    'information', 'song,person',   1, 'bi-music-note',        87],
    ['lastfm',      'Last.fm',     'listen',      'song,person',   1, 'bi-soundwave',         88],
    ['bandsintown', 'Bandsintown', 'information', 'person',        0, 'bi-ticket-perforated', 26],
    ['genius',      'Genius',      'information', 'song,person',   1, 'bi-file-text',         27],
    ['muzikum',     'Muzikum.eu',  'information', 'song,person',   1, 'bi-file-text',         28],
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
_migMbLinks_out("[seed] {$typesAdded} link type" . ($typesAdded === 1 ? '' : 's')
    . " inserted, {$typesUpdated} updated.");

/* ----------------------------------------------------------------------
 * Step 2 — Seed URL patterns
 * ---------------------------------------------------------------------- */
$seedRows = [
    /* [slug, host, path-prefix-or-null, match-subdomains, priority, note?] */
    ['myspace',     'myspace.com',      null, 1, 224, 'Legacy social network'],
    ['allmusic',    'allmusic.com',     null, 1, 165, 'Sister site to AllMovie'],
    ['lastfm',      'last.fm',          null, 1, 145, 'Suffix match covers www.last.fm'],
    ['bandsintown', 'bandsintown.com',  null, 1, 75,  'Suffix match covers www.bandsintown.com'],
    ['genius',      'genius.com',       null, 1, 36,  'Lyrics + annotations'],
    ['muzikum',     'muzikum.eu',       null, 1, 37,  'Lyrics + translations'],
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

_migMbLinks_out("[seed] {$pInserted} pattern" . ($pInserted === 1 ? '' : 's')
    . " inserted, {$pSkipped} already present"
    . ($pMissing > 0 ? ", {$pMissing} skipped (link type missing)" : '')
    . '.');

_migMbLinks_out('MusicBrainz-Parity External Links migration finished.');
