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
 * NON-DESTRUCTIVE: creates one table and seeds it. Nothing is dropped or
 * rewritten, and the JS rule list it supersedes stays in place as the
 * fallback (external-link-detect.js keeps its bundled RULES constant for
 * installs where this migration hasn't run — rule #11). Recovery from an
 * unwanted run is therefore just `DROP TABLE tblExternalLinkPatterns`: the
 * client falls back to RULES and detection carries on working.
 *
 * IDEMPOTENT — two guards, one per step:
 *   1. The CREATE is wrapped in a tableExists() probe, so a second run prints
 *      "[skip] … already present" rather than erroring. (Deliberately not
 *      `CREATE TABLE IF NOT EXISTS`: the probe is what lets the operator SEE
 *      which half of the migration actually did something.)
 *   2. The seed uses `INSERT … WHERE NOT EXISTS` on
 *      (LinkTypeId, Host, COALESCE(PathPrefix,'')) — see the note above the
 *      prepare() below for why the COALESCE is load-bearing, and why a guard
 *      rather than a UNIQUE key.
 *
 * SCHEMA MIRROR (rule #19): the CREATE TABLE below is a byte-identical twin of
 * the tblExternalLinkPatterns block in appWeb/.sql/schema.sql. A fresh install
 * reads schema.sql; a long-running install gets this. If the two drift — even
 * by a COMMENT — the two populations diverge structurally and the Schema Audit
 * page (#518) starts reporting differences nobody chose. Change one, change
 * the other, in the same commit.
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
 *
 * PRIORITY IS THE ONLY ORDERING — the order of this PHP array is not.
 * external_link_helpers.php reads the table `ORDER BY Priority ASC, Host ASC`
 * and external-link-detect.js re-sorts by priority client-side, so the fact
 * that e.g. `discogs` (160) sits below `audiomack` (350) in the source has no
 * effect. The numbers are hand-assigned in loose bands with gaps left for
 * insertions; they are NOT unique, and the relative order of two rules sharing
 * a priority is undefined. That only matters when two rules could match the
 * same URL — which is precisely why the genuinely conflicting pairs
 * (music.youtube.com vs youtube.com; the three MusicBrainz path rules vs any
 * future bare musicbrainz.org) are given distinct, deliberately low numbers.
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

    /* Extra streaming platforms (#streaming-extras). Bare-domain entries
       use MatchSubdomains = 1 so listen.tidal.com, www.deezer.com,
       open.qobuz.com, us.napster.com, play.anghami.com, etc. all match
       a single seed row. Amazon Music + Yandex Music enumerate per-TLD
       hosts because each is a distinct exact subdomain. */
    ['tidal',                 'tidal.com',         null,        1, 230, 'Suffix match covers listen.tidal.com / www.tidal.com / store.tidal.com'],
    ['deezer',                'deezer.com',        null,        1, 240, 'Suffix match covers www.deezer.com'],
    ['amazon-music',          'music.amazon.com',  null,        0, 250, 'Amazon Music — US (primary)'],
    ['amazon-music',          'music.amazon.co.uk',null,        0, 251, 'Amazon Music — UK'],
    ['amazon-music',          'music.amazon.de',   null,        0, 252, 'Amazon Music — Germany'],
    ['amazon-music',          'music.amazon.co.jp',null,        0, 253, 'Amazon Music — Japan'],
    ['amazon-music',          'music.amazon.fr',   null,        0, 254, 'Amazon Music — France'],
    ['amazon-music',          'music.amazon.it',   null,        0, 255, 'Amazon Music — Italy'],
    ['amazon-music',          'music.amazon.es',   null,        0, 256, 'Amazon Music — Spain'],
    ['amazon-music',          'music.amazon.com.au', null,      0, 257, 'Amazon Music — Australia'],
    ['amazon-music',          'music.amazon.ca',   null,        0, 258, 'Amazon Music — Canada'],
    ['amazon-music',          'music.amazon.com.br', null,      0, 259, 'Amazon Music — Brazil'],
    ['amazon-music',          'music.amazon.com.mx', null,      0, 260, 'Amazon Music — Mexico'],
    ['amazon-music',          'music.amazon.in',   null,        0, 261, 'Amazon Music — India'],
    ['pandora',               'pandora.com',       null,        1, 270],
    ['iheartradio',           'iheart.com',        null,        1, 280],
    ['iheartradio',           'iheartradio.com',   null,        1, 281, 'Alias domain'],
    ['iheartradio',           'iheartradio.ca',    null,        1, 282, 'Canada'],
    ['qobuz',                 'qobuz.com',         null,        1, 290, 'Suffix match covers open.qobuz.com / www.qobuz.com'],
    ['napster',               'napster.com',       null,        1, 300, 'Suffix match covers us.napster.com etc.'],
    ['anghami',               'anghami.com',       null,        1, 310, 'Suffix match covers play.anghami.com / www.anghami.com'],
    ['jiosaavn',              'jiosaavn.com',      null,        1, 320, 'Primary domain'],
    ['jiosaavn',              'saavn.com',         null,        1, 321, 'Legacy domain (pre-Jio rebrand)'],
    ['yandex-music',          'music.yandex.ru',   null,        0, 330, 'Yandex Music — Russia (primary)'],
    ['yandex-music',          'music.yandex.com',  null,        0, 331, 'Yandex Music — international'],
    ['yandex-music',          'music.yandex.by',   null,        0, 332, 'Yandex Music — Belarus'],
    ['yandex-music',          'music.yandex.kz',   null,        0, 333, 'Yandex Music — Kazakhstan'],
    ['yandex-music',          'music.yandex.uz',   null,        0, 334, 'Yandex Music — Uzbekistan'],
    ['mixcloud',              'mixcloud.com',      null,        1, 340],
    ['audiomack',             'audiomack.com',     null,        1, 350],

    ['discogs',               'discogs.com',       null,        1, 160],
    ['goodreads-author',      'goodreads.com',     '/author/',  1, 170],

    /* Media databases (film / TV / streaming / anime / games). Forward-
       looking groundwork for iLyrics DB + MeedyaDB which will share the
       iHymns external-link registry. Single bare-domain rule per
       provider with MatchSubdomains = 1 so m.imdb.com, www.themoviedb.org,
       etc. all match a single seed row. */
    ['imdb',                  'imdb.com',          null,        1, 400, 'Suffix match covers m.imdb.com / www.imdb.com'],
    ['tmdb',                  'themoviedb.org',    null,        1, 410, 'Suffix match covers www.themoviedb.org'],
    ['thetvdb',               'thetvdb.com',       null,        1, 420, 'Suffix match covers www.thetvdb.com'],
    ['letterboxd',            'letterboxd.com',    null,        1, 430],
    ['rotten-tomatoes',       'rottentomatoes.com',null,        1, 440],
    ['metacritic',            'metacritic.com',    null,        1, 450],
    ['allmovie',              'allmovie.com',      null,        1, 460],
    ['tvmaze',                'tvmaze.com',        null,        1, 470],
    ['trakt',                 'trakt.tv',          null,        1, 480, 'Primary domain'],
    ['justwatch',             'justwatch.com',     null,        1, 490, 'Suffix match covers per-country www.justwatch.com regional subdomains'],
    ['myanimelist',           'myanimelist.net',   null,        1, 500],
    ['anidb',                 'anidb.net',         null,        1, 510],
    ['igdb',                  'igdb.com',          null,        1, 520, 'Internet Game Database'],

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

    /* MusicBrainz-parity additions (#1003) */
    ['myspace',               'myspace.com',       null,        1, 224, 'Legacy social network — still hosts many older artist pages'],
    ['allmusic',              'allmusic.com',      null,        1, 165, 'Sister site to AllMovie'],
    ['lastfm',                'last.fm',           null,        1, 145, 'Suffix match covers www.last.fm'],
    ['bandsintown',           'bandsintown.com',   null,        1, 75,  'Suffix match covers www.bandsintown.com'],
    ['genius',                'genius.com',        null,        1, 36,  'Suffix match covers www.genius.com — lyrics + annotations'],
    ['muzikum',               'muzikum.eu',        null,        1, 37,  'Lyrics + translations site'],
    ['secondhandsongs',       'secondhandsongs.com', null,      1, 38,  'Database of cover versions, originals, releases'],
];

/* Resolve slugs to LinkTypeIds in one query.
   A slug with no row here is COUNTED and SKIPPED rather than fatal (see
   $missing below): this migration seeds patterns for providers whose types
   come from migrate-external-links.php (#833), and on an install where that
   ran before the newer providers were added to its seed list some slugs are
   legitimately absent. Reporting the count tells the operator to re-run #833
   first; throwing would strand the patterns that CAN be seeded. */
$slugToId = [];
$res = $mysqli->query('SELECT Id, Slug FROM tblExternalLinkTypes');
while ($row = $res->fetch_assoc()) {
    $slugToId[(string)$row['Slug']] = (int)$row['Id'];
}
$res->close();

$inserted = 0;
$skipped  = 0;
$missing  = 0;

/* The idempotency guard. tblExternalLinkPatterns carries no UNIQUE key
   (curators may legitimately want two rows differing only by Note or
   Priority), so INSERT IGNORE / ON DUPLICATE KEY have nothing to key on and
   this NOT EXISTS test is the only thing standing between a re-run and a
   duplicated seed. FROM DUAL is MySQL's no-table dummy source — a SELECT
   needs a FROM before it can carry a WHERE.

   COALESCE on both sides of the PathPrefix comparison is essential rather
   than tidy: SQL's `NULL = NULL` is NULL, not TRUE, so without it every
   host-only rule (the overwhelming majority below) would fail to recognise
   its own existing copy and every re-run would insert the whole seed again.
   https://dev.mysql.com/doc/refman/8.0/en/working-with-null.html */
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
