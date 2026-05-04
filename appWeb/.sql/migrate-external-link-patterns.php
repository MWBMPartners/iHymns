<?php

declare(strict_types=1);

/**
 * iHymns — External-Link URL Patterns Migration (#845)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Moves the URL → provider auto-detect rules from
 * js/modules/external-link-detect.js (#841) into a MySQL table so
 * curators can add / edit / remove rules without a code deploy.
 *
 * Each link type in tblExternalLinkTypes can carry multiple host /
 * path patterns. Sub-domains are supported (MatchSubdomains = 1
 * matches host suffixes; = 0 matches the exact host). Path prefixes
 * discriminate same-host providers (MusicBrainz /work/ vs /recording/
 * vs /artist/).
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-external-link-patterns.php
 *   Web: /manage/setup-database → "External-Link URL Patterns (#845)"
 *
 * @migration-adds tblExternalLinkPatterns
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

function _migExtPat_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migExtPat_tableExists(\mysqli $db, string $table): bool
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

_migExtPat_out('External-Link URL Patterns migration starting (#845)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migExtPat_tableExists($mysqli, 'tblExternalLinkTypes')) {
    _migExtPat_out('ERROR: tblExternalLinkTypes not found. Run migrate-external-links.php first (#833).');
    return;
}

/* ----------------------------------------------------------------------
 * Step 1 — tblExternalLinkPatterns
 * ---------------------------------------------------------------------- */
if (_migExtPat_tableExists($mysqli, 'tblExternalLinkPatterns')) {
    _migExtPat_out('[skip] tblExternalLinkPatterns already present.');
} else {
    $sql = "CREATE TABLE tblExternalLinkPatterns (
        Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        LinkTypeId      INT UNSIGNED NOT NULL,
        Host            VARCHAR(255) NOT NULL,
        PathPrefix      VARCHAR(255) NULL,
        MatchSubdomains TINYINT(1)   NOT NULL DEFAULT 1,
        Priority        INT UNSIGNED NOT NULL DEFAULT 100,
        IsActive        TINYINT(1)   NOT NULL DEFAULT 1,
        Note            VARCHAR(255) NULL,
        CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_type     (LinkTypeId),
        INDEX idx_host     (Host),
        INDEX idx_priority (Priority),

        CONSTRAINT fk_linkpat_type
            FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblExternalLinkPatterns failed: ' . $mysqli->error);
    }
    _migExtPat_out('[add ] tblExternalLinkPatterns.');
}

/* ----------------------------------------------------------------------
 * Step 2 — Seed
 *
 * Same provider list shipped in js/modules/external-link-detect.js
 * (#841). Lower Priority numbers win — keep MusicBrainz path-discriminated
 * rules + music.youtube.com ahead of broader hosts. Host-only rules
 * default to MatchSubdomains = 1 so a single 'wikipedia.org' covers
 * en.wikipedia.org, de.wikipedia.org and wikipedia.org itself.
 *
 * Each tuple: [slug, host, path-prefix-or-null, match-subdomains, priority, note?]
 * ---------------------------------------------------------------------- */
$seedRows = [
    /* MusicBrainz path-discriminated — must beat any later musicbrainz.org host */
    ['musicbrainz-work',      'musicbrainz.org', '/work/',      0, 10, 'MusicBrainz Work entity'],
    ['musicbrainz-recording', 'musicbrainz.org', '/recording/', 0, 11, 'MusicBrainz Recording entity'],
    ['musicbrainz-artist',    'musicbrainz.org', '/artist/',    0, 12, 'MusicBrainz Artist entity'],

    /* music.youtube.com must beat youtube.com */
    ['youtube-music',         'music.youtube.com', null,        0, 20, 'YouTube Music (must beat youtube.com)'],
    ['youtube',               'youtube.com',       null,        1, 30],
    ['youtube',               'youtu.be',          null,        1, 31],
    ['youtube',               'm.youtube.com',     null,        0, 32],

    ['wikipedia',             'wikipedia.org',     null,        1, 40, 'Suffix match covers en./de./fr./… wikipedia.org'],
    ['wikidata',              'wikidata.org',      null,        1, 41],
    ['hymnary-org',           'hymnary.org',       null,        1, 50],
    ['hymnal-plus',           'hymnalplus.com',    null,        1, 51],
    ['cyber-hymnal',          'hymntime.com',      null,        1, 52],
    ['cyber-hymnal',          'cyberhymnal.org',   null,        1, 53],
    ['internet-archive',      'archive.org',       null,        1, 60],
    ['open-library',          'openlibrary.org',   null,        1, 61],
    ['oclc-worldcat',         'worldcat.org',      null,        1, 70],
    ['viaf',                  'viaf.org',          null,        1, 71],
    ['loc-name-authority',    'id.loc.gov',        null,        0, 72],
    ['find-a-grave',          'findagrave.com',    null,        1, 73],
    ['ccli-songselect',       'songselect.ccli.com', null,      0, 80],
    ['imslp',                 'imslp.org',         null,        1, 90],
    ['vimeo',                 'vimeo.com',         null,        1, 100],
    ['spotify',               'open.spotify.com',  null,        0, 110],
    ['spotify',               'spotify.com',       null,        1, 111],
    ['apple-music',           'music.apple.com',   null,        0, 120],
    ['bandcamp',              'bandcamp.com',      null,        1, 130, 'Suffix match covers artist.bandcamp.com'],
    ['soundcloud',            'soundcloud.com',    null,        1, 140],
    ['librivox',              'librivox.org',      null,        1, 150],
    ['discogs',               'discogs.com',       null,        1, 160],
    ['goodreads-author',      'goodreads.com',     '/author/',  1, 170],
    ['linkedin',              'linkedin.com',      null,        1, 180],
    ['twitter-x',             'twitter.com',       null,        1, 190],
    ['twitter-x',             'x.com',             null,        1, 191],
    ['instagram',             'instagram.com',     null,        1, 200],
    ['facebook',              'facebook.com',      null,        1, 210],
    ['facebook',              'fb.com',            null,        1, 211],
    ['mastodon',              'mastodon.social',   null,        0, 220, 'Add other instances via /manage/external-link-types'],
    ['mastodon',              'mastodon.online',   null,        0, 221],
    ['mastodon',              'mas.to',            null,        0, 222],
    ['mastodon',              'fosstodon.org',     null,        0, 223],
];

/* Resolve slugs to LinkTypeIds in one query. */
$slugToId = [];
$res = $mysqli->query('SELECT Id, Slug FROM tblExternalLinkTypes');
while ($row = $res->fetch_assoc()) {
    $slugToId[(string)$row['Slug']] = (int)$row['Id'];
}
$res->close();

$inserted = 0;
$skipped  = 0;
$missing  = 0;

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

foreach ($seedRows as $r) {
    $slug    = (string)$r[0];
    $host    = (string)$r[1];
    $path    = $r[2] !== null ? (string)$r[2] : null;
    $matchSd = (int)$r[3];
    $prio    = (int)$r[4];
    $note    = isset($r[5]) ? (string)$r[5] : null;

    if (!isset($slugToId[$slug])) {
        $missing++;
        continue;
    }
    $typeId = $slugToId[$slug];

    /* Bind params — note the duplicate type/host/path for the
       NOT EXISTS guard and the INSERT itself. */
    $insert->bind_param(
        'issiisiss',
        $typeId, $host, $path, $matchSd, $prio, $note,
        $typeId, $host, $path
    );
    $insert->execute();
    if ($mysqli->affected_rows > 0) $inserted++;
    else                            $skipped++;
}
$insert->close();

_migExtPat_out("[seed] {$inserted} pattern" . ($inserted === 1 ? '' : 's')
    . " inserted, {$skipped} already present"
    . ($missing > 0 ? ", {$missing} skipped (link type missing — re-run migrate-external-links.php first)" : '')
    . '.');

_migExtPat_out('External-Link URL Patterns migration finished (#845).');
