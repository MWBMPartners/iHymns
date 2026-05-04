<?php

declare(strict_types=1);

/**
 * iHymns — MusicBrainz-Style External Links Migration (#833)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Replaces the three hard-coded songbook URL columns
 * (WebsiteUrl / InternetArchiveUrl / WikipediaUrl) AND the existing
 * tblCreditPersonLinks free-text-LinkType table with a single
 * MusicBrainz-style external-links system covering songs, songbooks
 * and credit-people.
 *
 * Schema (idempotent):
 *
 *   tblExternalLinkTypes — controlled vocabulary registry. ~37 seed
 *     types for hymnology (Hymnary.org, CCLI Songselect, IMSLP,
 *     YouTube, Spotify, Internet Archive, Wikipedia, Wikidata,
 *     Open Library, OCLC, Cyber Hymnal, LibriVox, MusicBrainz,
 *     VIAF, social, …).
 *
 *   tblSongbookExternalLinks — many-to-many tblSongbooks ↔ link type.
 *   tblSongExternalLinks     — many-to-many tblSongs ↔ link type.
 *   tblCreditPersonExternalLinks — many-to-many tblCreditPeople ↔ link type.
 *
 * Each per-entity link row carries Url / Note / SortOrder / Verified
 * so a curator can record multiple Internet Archive scans, multiple
 * YouTube performances, etc., with per-row context.
 *
 * Backfills are SEPARATE migrations:
 *   - migrate-backfill-songbook-links.php       (#833)
 *   - migrate-backfill-credit-person-links.php  (#833)
 *
 * Legacy reads (songbook URL columns + tblCreditPersonLinks) stay in
 * place for one release cycle as fallbacks; later migration drops
 * them once the public site has been on the new system.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-external-links.php
 *   Web: /manage/setup-database → "External Links System (#833)"
 *
 * @migration-adds tblExternalLinkTypes
 * @migration-adds tblSongbookExternalLinks
 * @migration-adds tblSongExternalLinks
 * @migration-adds tblCreditPersonExternalLinks
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

function _migExtLinks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migExtLinks_tableExists(\mysqli $db, string $table): bool
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

_migExtLinks_out('External Links migration starting (#833)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblSongs', 'tblSongbooks', 'tblCreditPeople'] as $required) {
    if (!_migExtLinks_tableExists($mysqli, $required)) {
        _migExtLinks_out("ERROR: {$required} not found. Run prerequisite migrations first.");
        return;
    }
}

/* ----------------------------------------------------------------------
 * Step 1 — tblExternalLinkTypes (registry)
 * ---------------------------------------------------------------------- */
if (_migExtLinks_tableExists($mysqli, 'tblExternalLinkTypes')) {
    _migExtLinks_out('[skip] tblExternalLinkTypes already present.');
} else {
    $sql = "CREATE TABLE tblExternalLinkTypes (
        Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        Slug          VARCHAR(60)  NOT NULL,
        Name          VARCHAR(120) NOT NULL,
        Category      ENUM(
                          'information', 'listen', 'watch', 'read',
                          'sheet-music', 'purchase', 'authority',
                          'official', 'social', 'other'
                      ) NOT NULL DEFAULT 'other',
        UrlPattern    VARCHAR(255) NULL,
        IconClass     VARCHAR(60)  NULL,
        AppliesTo     SET('song','songbook','person') NOT NULL DEFAULT 'song,songbook,person',
        AllowMultiple TINYINT(1)   NOT NULL DEFAULT 1,
        IsActive      TINYINT(1)   NOT NULL DEFAULT 1,
        DisplayOrder  INT UNSIGNED NOT NULL DEFAULT 0,
        CreatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

        UNIQUE KEY uq_slug   (Slug),
        INDEX     idx_active   (IsActive),
        INDEX     idx_category (Category, DisplayOrder)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblExternalLinkTypes failed: ' . $mysqli->error);
    }
    _migExtLinks_out('[add ] tblExternalLinkTypes.');
}

/* ----------------------------------------------------------------------
 * Step 2 — Seed link-type registry
 *
 * INSERT … ON DUPLICATE KEY UPDATE pattern keyed on Slug, so re-runs
 * upsert (refreshing Name / Category / IconClass / AppliesTo /
 * AllowMultiple / DisplayOrder if the seed list evolves) without
 * touching the IsActive column — that's curator-controlled. New
 * types added via SQL aren't disturbed by re-running.
 * ---------------------------------------------------------------------- */
$seedTypes = [
    /* slug, name, category, applies_to, allow_multiple, icon, order */
    ['official-website',     'Official website',     'official',    'song,songbook,person', 0, 'bi-globe',           10],
    ['wikipedia',            'Wikipedia',            'information', 'song,songbook,person', 1, 'bi-wikipedia',       20],
    ['wikidata',             'Wikidata',             'information', 'song,songbook,person', 0, 'bi-database',        21],
    ['hymnary-org',          'Hymnary.org',          'information', 'song,songbook,person', 0, 'bi-music-note-list', 22],
    ['hymnal-plus',          'Hymnal Plus',          'information', 'song,songbook',        0, 'bi-music-note-list', 23],
    ['cyber-hymnal',         'The Cyber Hymnal',     'information', 'song,person',          0, 'bi-music-note-beamed', 24],
    ['internet-archive',     'Internet Archive',     'read',        'songbook',             1, 'bi-archive',         30],
    ['open-library',         'Open Library',         'read',        'songbook',             1, 'bi-book-half',       31],
    ['archive-misc',         'Other archive',        'read',        'song,songbook,person', 1, 'bi-archive',         39],
    ['oclc-worldcat',        'WorldCat / OCLC',      'authority',   'songbook',             0, 'bi-card-list',       40],
    ['viaf',                 'VIAF authority',       'authority',   'songbook,person',      0, 'bi-card-list',       41],
    ['loc-name-authority',   'LoC name authority',   'authority',   'songbook,person',      0, 'bi-card-list',       42],
    ['find-a-grave',         'Find a Grave',         'authority',   'person',               0, 'bi-flower2',         43],
    ['ccli-songselect',      'CCLI SongSelect',      'purchase',    'song',                 0, 'bi-bag',             50],
    ['publisher-store',      'Publisher store',      'purchase',    'songbook',             0, 'bi-shop',            51],
    ['imslp',                'IMSLP / Petrucci',     'sheet-music', 'song,songbook,person', 1, 'bi-file-music',      60],
    ['sheet-music-pdf',      'Sheet music PDF',      'sheet-music', 'song',                 1, 'bi-file-music',      61],
    ['lyrics-page',          'Lyrics page',          'information', 'song',                 1, 'bi-file-text',       25],
    ['youtube',              'YouTube',              'watch',       'song,person',          1, 'bi-youtube',         70],
    ['vimeo',                'Vimeo',                'watch',       'song,person',          1, 'bi-camera-video',    71],
    ['spotify',              'Spotify',              'listen',      'song,person',          1, 'bi-spotify',         80],
    ['apple-music',          'Apple Music',          'listen',      'song,person',          1, 'bi-music-note',      81],
    ['youtube-music',        'YouTube Music',        'listen',      'song,person',          1, 'bi-music-note',      82],
    ['bandcamp',             'Bandcamp',             'listen',      'song,person',          1, 'bi-vinyl',           83],
    ['soundcloud',           'SoundCloud',           'listen',      'song,person',          1, 'bi-cloud',           84],
    ['librivox',             'LibriVox',             'listen',      'song,songbook',        1, 'bi-mic',             85],
    ['discogs',              'Discogs',              'information', 'song,person',          1, 'bi-vinyl',           90],
    ['musicbrainz-work',     'MusicBrainz work',     'information', 'song',                 0, 'bi-music-note',      91],
    ['musicbrainz-recording','MusicBrainz recording','information', 'song',                 1, 'bi-music-note',      92],
    ['musicbrainz-artist',   'MusicBrainz artist',   'information', 'person',               0, 'bi-person-vcard',    93],
    ['goodreads-author',     'Goodreads author',     'information', 'person',               0, 'bi-book',            94],
    ['linkedin',             'LinkedIn',             'social',      'person',               0, 'bi-linkedin',        100],
    ['twitter-x',            'Twitter / X',          'social',      'person',               0, 'bi-twitter-x',       101],
    ['instagram',            'Instagram',            'social',      'person',               0, 'bi-instagram',       102],
    ['facebook',             'Facebook',             'social',      'person',               0, 'bi-facebook',        103],
    ['mastodon',             'Mastodon',             'social',      'person',               0, 'bi-mastodon',        104],
    ['other',                'Other',                'other',       'song,songbook,person', 1, 'bi-link-45deg',      999],
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
$seedAdded   = 0;
$seedUpdated = 0;
foreach ($seedTypes as $t) {
    [$slug, $name, $cat, $applies, $multi, $icon, $order] = $t;
    $upsert->bind_param('ssssisi', $slug, $name, $cat, $applies, $multi, $icon, $order);
    @$upsert->execute();
    /* affected_rows is 1 for INSERT, 2 for UPDATE in MySQL's
       INSERT…ON DUPLICATE KEY semantics; treat 0 as a no-op
       (row already matches the seed exactly). */
    $ar = $mysqli->affected_rows;
    if ($ar === 1)      $seedAdded++;
    elseif ($ar === 2)  $seedUpdated++;
}
$upsert->close();
_migExtLinks_out("[seed] {$seedAdded} link type" . ($seedAdded === 1 ? '' : 's')
    . " inserted, {$seedUpdated} updated. Total registry: " . count($seedTypes) . '.');

/* ----------------------------------------------------------------------
 * Step 3 — tblSongbookExternalLinks
 * ---------------------------------------------------------------------- */
if (_migExtLinks_tableExists($mysqli, 'tblSongbookExternalLinks')) {
    _migExtLinks_out('[skip] tblSongbookExternalLinks already present.');
} else {
    $sql = "CREATE TABLE tblSongbookExternalLinks (
        Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongbookId  INT UNSIGNED NOT NULL,
        LinkTypeId  INT UNSIGNED NOT NULL,
        Url         VARCHAR(2048) NOT NULL,
        Note        VARCHAR(255) NULL,
        SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
        Verified    TINYINT(1)   NOT NULL DEFAULT 0,
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_book (SongbookId),
        INDEX idx_type (LinkTypeId),

        CONSTRAINT fk_link_book
            FOREIGN KEY (SongbookId) REFERENCES tblSongbooks(Id) ON DELETE CASCADE,
        CONSTRAINT fk_link_type_book
            FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblSongbookExternalLinks failed: ' . $mysqli->error);
    }
    _migExtLinks_out('[add ] tblSongbookExternalLinks.');
}

/* ----------------------------------------------------------------------
 * Step 4 — tblSongExternalLinks
 * ---------------------------------------------------------------------- */
if (_migExtLinks_tableExists($mysqli, 'tblSongExternalLinks')) {
    _migExtLinks_out('[skip] tblSongExternalLinks already present.');
} else {
    $sql = "CREATE TABLE tblSongExternalLinks (
        Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongId      VARCHAR(20)  NOT NULL,
        LinkTypeId  INT UNSIGNED NOT NULL,
        Url         VARCHAR(2048) NOT NULL,
        Note        VARCHAR(255) NULL,
        SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
        Verified    TINYINT(1)   NOT NULL DEFAULT 0,
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_song (SongId),
        INDEX idx_type (LinkTypeId),

        CONSTRAINT fk_link_song
            FOREIGN KEY (SongId)     REFERENCES tblSongs(SongId)         ON DELETE CASCADE,
        CONSTRAINT fk_link_type_song
            FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblSongExternalLinks failed: ' . $mysqli->error);
    }
    _migExtLinks_out('[add ] tblSongExternalLinks.');
}

/* ----------------------------------------------------------------------
 * Step 5 — tblCreditPersonExternalLinks
 * ---------------------------------------------------------------------- */
if (_migExtLinks_tableExists($mysqli, 'tblCreditPersonExternalLinks')) {
    _migExtLinks_out('[skip] tblCreditPersonExternalLinks already present.');
} else {
    $sql = "CREATE TABLE tblCreditPersonExternalLinks (
        Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        CreditPersonId  INT UNSIGNED NOT NULL,
        LinkTypeId      INT UNSIGNED NOT NULL,
        Url             VARCHAR(2048) NOT NULL,
        Note            VARCHAR(255) NULL,
        SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
        Verified        TINYINT(1)   NOT NULL DEFAULT 0,
        CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_person (CreditPersonId),
        INDEX idx_type   (LinkTypeId),

        CONSTRAINT fk_link_person
            FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)        ON DELETE CASCADE,
        CONSTRAINT fk_link_type_person
            FOREIGN KEY (LinkTypeId)     REFERENCES tblExternalLinkTypes(Id)   ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblCreditPersonExternalLinks failed: ' . $mysqli->error);
    }
    _migExtLinks_out('[add ] tblCreditPersonExternalLinks.');
}

_migExtLinks_out('External Links migration finished (#833).');
_migExtLinks_out('Backfills are separate migrations:');
_migExtLinks_out('  - migrate-backfill-songbook-links.php');
_migExtLinks_out('  - migrate-backfill-credit-person-links.php');
