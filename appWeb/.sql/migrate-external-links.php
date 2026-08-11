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
 * NOT DESTRUCTIVE, despite the word "Replaces" above. This migration only
 * ADDS: four tables and a seeded vocabulary. The columns and table it
 * supersedes are still there, still read as fallbacks, and are dropped later
 * by a separate migration once the new system has proved itself. There is
 * consequently nothing to recover from a run of this file — the failure mode
 * is "some tables exist that nobody reads yet".
 *
 * IDEMPOTENT — the DDL and the data use different mechanisms:
 *   - Each CREATE sits behind a tableExists() probe (Steps 1, 3, 4, 5), which
 *     is why a re-run prints a "[skip]" line per table instead of erroring.
 *     The probe rather than `CREATE TABLE IF NOT EXISTS` is deliberate: the
 *     operator can see from the transcript which tables this run created.
 *   - The registry seed (Step 2) is `INSERT … ON DUPLICATE KEY UPDATE` keyed
 *     on the UNIQUE `uq_slug`, so a re-run refreshes the label / category /
 *     icon / ordering of the ~70 seeded providers. `IsActive` is pointedly
 *     absent from the UPDATE clause — a provider a curator switched off must
 *     stay off — and so is `UrlPattern`, which is curator territory too.
 *
 * SCHEMA MIRROR (rule #19): all four CREATE TABLE blocks below are
 * byte-identical twins of their counterparts in appWeb/.sql/schema.sql, which
 * is what a FRESH install reads. The one intended difference is
 * tblExternalLinkTypes.AppliesTo — see the note on that column in Step 1.
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
 *
 * Two column-shape decisions below are worth knowing before editing:
 *
 * `Category ENUM(...)` — this is the one growable vocabulary in the family
 * that is NOT a VARCHAR. It predates rule #20 and is grandfathered, but the
 * cost is visible in the #1003 seed comment further down, which explains that
 * Genius and Muzikum were filed under 'information' rather than given a
 * category of their own precisely because a new category means an ALTER. A
 * new vocabulary column added today would be VARCHAR + an app-level
 * allow-list; do not copy this ENUM into a new table.
 *
 * `AppliesTo SET('song','songbook','person')` — NOT the same as its
 * schema.sql twin, which reads SET('song','songbook','person','work'). That
 * is the intended sequence, not drift: 'work' is added later by
 * migrate-works.php (#840) Step 3, which MODIFYs this column and then widens a
 * curated allow-list of slugs. A fresh install gets the wider SET from
 * schema.sql on day one. See the report note on the seed rows below, which
 * name 'work' before that widening has happened.
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
 *
 * ⚠️ Several rows below (oclc-worldcat, the thirteen media-database
 * providers, secondhandsongs) declare AppliesTo = 'song,songbook,person,work'.
 * On a FRESH install that is fine: schema.sql already defines the SET with
 * 'work' in it. On a LONG-RUNNING install taking the migrations in registry
 * order it is not — this migration runs BEFORE migrate-works.php (#840), so at
 * this moment the column created in Step 1 has no 'work' member and those rows
 * name a value the SET does not contain. What MySQL does then depends on the
 * server's sql_mode, not on anything in this file. Do not "fix" it by dropping
 * 'work' from the seed (fresh installs would then lose it) — the fix, if one
 * is wanted, is ordering or a widen-first step. Documented rather than changed
 * during the #1158 annotation pass; see that pass's report.
 * ---------------------------------------------------------------------- */
$seedTypes = [
    /* slug, name, category, applies_to, allow_multiple, icon, order */
    ['official-website',     'Official website',     'official',    'song,songbook,person', 0, 'bi-globe',           10],
    ['wikipedia',            'Wikipedia',            'information', 'song,songbook,person', 1, 'bi-wikipedia',       20],
    ['wikidata',             'Wikidata',             'information', 'song,songbook,person', 0, 'bi-database',        21],
    ['hymnary-org',          'Hymnary.org',          'information', 'song,songbook,person', 0, 'bi-music-note-list', 22],
    ['hymnal-plus',          'Hymnal Plus',          'information', 'song,songbook',        0, 'bi-music-note-list', 23],
    ['cyber-hymnal',         'The Cyber Hymnal',     'information', 'song,person',          0, 'bi-music-note-beamed', 24],
    ['internet-archive',     'Internet Archive',     'read',        'song,songbook',        1, 'bi-archive',         30],
    ['open-library',         'Open Library',         'read',        'songbook',             1, 'bi-book-half',       31],
    ['archive-misc',         'Other archive',        'read',        'song,songbook,person', 1, 'bi-archive',         39],
    ['oclc-worldcat',        'WorldCat / OCLC',      'authority',   'song,songbook,person,work', 1, 'bi-card-list',       40],
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
    ['tidal',                'Tidal',                'listen',      'song,person',          1, 'bi-music-note',      86],
    ['deezer',               'Deezer',               'listen',      'song,person',          1, 'bi-music-note',      87],
    ['amazon-music',         'Amazon Music',         'listen',      'song,person',          1, 'bi-amazon',          88],
    ['pandora',              'Pandora',              'listen',      'song,person',          1, 'bi-broadcast',       89],
    ['iheartradio',          'iHeartRadio',          'listen',      'song,person',          1, 'bi-broadcast-pin',   95],
    ['qobuz',                'Qobuz',                'listen',      'song,person',          1, 'bi-music-note',      96],
    ['napster',              'Napster',              'listen',      'song,person',          1, 'bi-music-note',      97],
    ['anghami',              'Anghami',              'listen',      'song,person',          1, 'bi-music-note',      105],
    ['jiosaavn',             'JioSaavn',             'listen',      'song,person',          1, 'bi-music-note',      106],
    ['yandex-music',         'Yandex Music',         'listen',      'song,person',          1, 'bi-music-note',      107],
    ['mixcloud',             'Mixcloud',             'listen',      'song,person',          1, 'bi-cloud',           110],
    ['audiomack',            'Audiomack',            'listen',      'song,person',          1, 'bi-music-note',      111],
    ['discogs',              'Discogs',              'information', 'song,person',          1, 'bi-vinyl',           90],
    ['musicbrainz-work',     'MusicBrainz work',     'information', 'song',                 0, 'bi-music-note',      91],
    ['musicbrainz-recording','MusicBrainz recording','information', 'song',                 1, 'bi-music-note',      92],
    ['musicbrainz-artist',   'MusicBrainz artist',   'information', 'person',               0, 'bi-person-vcard',    93],
    ['goodreads-author',     'Goodreads author',     'information', 'person',               0, 'bi-book',            94],

    /* Media databases — film / TV / streaming / anime / games. AppliesTo
       deliberately wide (song,songbook,person,work) since iHymns data
       is the seed for the upcoming iLyrics DB + MeedyaDB and these
       providers want to live on every entity type. */
    ['imdb',                 'IMDb',                 'information', 'song,songbook,person,work', 1, 'bi-film',           120],
    ['tmdb',                 'The Movie DB (TMDB)',  'information', 'song,songbook,person,work', 1, 'bi-film',           121],
    ['thetvdb',              'TheTVDB',              'information', 'song,songbook,person,work', 1, 'bi-tv',             122],
    ['letterboxd',           'Letterboxd',           'information', 'song,songbook,person,work', 1, 'bi-film',           123],
    ['rotten-tomatoes',      'Rotten Tomatoes',      'information', 'song,songbook,person,work', 1, 'bi-star',           124],
    ['metacritic',           'Metacritic',           'information', 'song,songbook,person,work', 1, 'bi-star-half',      125],
    ['allmovie',             'AllMovie',             'information', 'song,songbook,person,work', 1, 'bi-film',           126],
    ['tvmaze',               'TVmaze',               'information', 'song,songbook,person,work', 1, 'bi-tv',             127],
    ['trakt',                'Trakt',                'information', 'song,songbook,person,work', 1, 'bi-tv',             128],
    ['justwatch',            'JustWatch',            'information', 'song,songbook,person,work', 1, 'bi-search',         129],
    ['myanimelist',          'MyAnimeList',          'information', 'song,songbook,person,work', 1, 'bi-collection-play',130],
    ['anidb',                'AniDB',                'information', 'song,songbook,person,work', 1, 'bi-collection-play',131],
    ['igdb',                 'IGDB',                 'information', 'song,songbook,person,work', 1, 'bi-controller',     132],

    ['linkedin',             'LinkedIn',             'social',      'person',               0, 'bi-linkedin',        100],
    ['twitter-x',            'Twitter / X',          'social',      'person',               0, 'bi-twitter-x',       101],
    ['instagram',            'Instagram',            'social',      'person',               0, 'bi-instagram',       102],
    ['facebook',             'Facebook',             'social',      'person',               0, 'bi-facebook',        103],
    ['mastodon',             'Mastodon',             'social',      'person',               0, 'bi-mastodon',        104],
    ['myspace',              'Myspace',              'social',      'person',               0, 'bi-people',          105],

    /* MusicBrainz-style additions (#1003) — providers that surface on the
       reference MusicBrainz artist page but weren't in the iHymns seed
       yet. AllMusic + Discogs sit together under 'information'; Last.fm
       lives with the other 'listen' services; Bandsintown is touring /
       performance info ('information'); Genius + Muzikum are lyrics-with-
       extras pages (kept under 'information' instead of forcing a new
       category — the curator can still pick 'Lyrics page' for plain text
       lyrics sites that aren't on this list). */
    ['allmusic',             'AllMusic',             'information', 'song,person',          1, 'bi-music-note',      87],
    ['lastfm',               'Last.fm',              'listen',      'song,person',          1, 'bi-soundwave',       88],
    ['bandsintown',          'Bandsintown',          'information', 'person',               0, 'bi-ticket-perforated', 26],
    ['genius',               'Genius',               'information', 'song,person',          1, 'bi-file-text',       27],
    ['muzikum',              'Muzikum.eu',           'information', 'song,person',          1, 'bi-file-text',       28],
    ['secondhandsongs',      'SecondHandSongs',      'information', 'song,songbook,person,work', 1, 'bi-music-note-list', 93],
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
    /* The `@` is vestigial and does less than it looks like it does: the DB
       layer sets MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so a failing
       execute() THROWS mysqli_sql_exception, and error suppression does not
       stop an exception. Anything it still hides would be a PHP-level notice.
       Left in place here — removing it is a behaviour change, not a comment. */
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
 *
 * The three per-entity join tables (Steps 3-5) are deliberately identical in
 * shape, and their two foreign keys deliberately differ in policy:
 *
 *   ON DELETE CASCADE  to the OWNING entity — deleting a songbook / song /
 *     credit-person should take its links with it; an orphaned link row has no
 *     meaning and nothing would ever surface it again.
 *
 *   ON DELETE RESTRICT to tblExternalLinkTypes — deleting a link TYPE that is
 *     still in use must FAIL loudly rather than silently destroy curator data
 *     across every entity that used it. This is why /manage/external-link-types
 *     offers "deactivate" (IsActive = 0) as the everyday action: a retired
 *     provider stops appearing in the picker while its existing rows survive.
 *
 * A separate per-entity table per rule #15 (never a generic polymorphic
 * entity_type/entity_id pair) — that is what lets each FK above be a real,
 * enforced constraint rather than a convention the application has to police.
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
