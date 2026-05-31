<?php

declare(strict_types=1);

/**
 * iHymns — Works (composition grouping) Migration (#840)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Introduces a top-level "Work" entity that groups together multiple
 * tblSongs rows that represent the same underlying composition across
 * different sources (different songbooks, different arrangements,
 * different translations, etc.).
 *
 * Models MusicBrainz Works ↔ Recordings:
 *   - one Work, many Recordings   (in MusicBrainz)
 *   - one Work, many tblSongs     (here)
 *
 * Pairwise counterpart linking from #807 / #808 stays — Works is the
 * registry-level grouping that lets us hang one ISWC, one set of
 * external links and one canonical title off the family.
 *
 * Schema (idempotent):
 *
 *   tblWorks                 — one row per composition. Self-referential
 *                              ParentWorkId supports unlimited nesting
 *                              (parent → child → grandchild …) for
 *                              arrangement / translation / medley
 *                              hierarchies, exactly as MusicBrainz does.
 *   tblWorkSongs             — membership: Work ↔ tblSongs.
 *   tblWorkExternalLinks     — many-to-many tblWorks ↔ tblExternalLinkTypes.
 *
 * Plus an ALTER on tblExternalLinkTypes.AppliesTo to add 'work' to the
 * SET so the registry can flag types that apply to Works.
 *
 * No backfill — Works is brand new. The pairwise counterparts table
 * (tblSongCounterparts from #807) stays alongside; future bulk
 * "group counterparts as a Work" tooling reads it.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-works.php
 *   Web: /manage/setup-database → "Works (#840)"
 *
 * @migration-adds   tblWorks
 * @migration-adds   tblWorkSongs
 * @migration-adds   tblWorkExternalLinks
 * @migration-alters tblExternalLinkTypes.AppliesTo
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

function _migWorks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migWorks_tableExists(\mysqli $db, string $table): bool
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

function _migWorks_columnDefinition(\mysqli $db, string $table, string $column): ?string
{
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row[0] ?? null;
}

_migWorks_out('Works migration starting (#840)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblSongs'] as $required) {
    if (!_migWorks_tableExists($mysqli, $required)) {
        _migWorks_out("ERROR: {$required} not found. Run install.php / migrate-json.php first.");
        return;
    }
}

/* The tblExternalLinkTypes table is created by migrate-external-links.php
   (#833). We don't hard-fail when it's missing — we just skip the
   AppliesTo SET widening + the tblWorkExternalLinks creation, so admins
   who run the migrations out of order get a friendly hint. */
$hasExtLinkRegistry = _migWorks_tableExists($mysqli, 'tblExternalLinkTypes');

/* ----------------------------------------------------------------------
 * Step 1 — tblWorks
 * ---------------------------------------------------------------------- */
if (_migWorks_tableExists($mysqli, 'tblWorks')) {
    /* Already present — make sure ParentWorkId is there (lets us land
       the nesting feature on top of an existing single-level Works
       schema without dropping data). */
    if (_migWorks_columnDefinition($mysqli, 'tblWorks', 'ParentWorkId') === null) {
        $sql = "ALTER TABLE tblWorks
                  ADD COLUMN ParentWorkId INT UNSIGNED NULL AFTER Id,
                  ADD INDEX idx_parent (ParentWorkId),
                  ADD CONSTRAINT fk_work_parent
                      FOREIGN KEY (ParentWorkId) REFERENCES tblWorks(Id) ON DELETE SET NULL";
        if (!$mysqli->query($sql)) {
            throw new \RuntimeException('ALTER tblWorks ADD ParentWorkId failed: ' . $mysqli->error);
        }
        _migWorks_out('[mod ] tblWorks.ParentWorkId added (nesting support).');
    } else {
        _migWorks_out('[skip] tblWorks already present.');
    }
} else {
    /* ParentWorkId is a nullable self-FK with ON DELETE SET NULL — when
       a parent work is deleted, child works "orphan" cleanly rather
       than cascading away. The depth of the nesting is unconstrained
       (mirrors MusicBrainz, which allows arbitrary parent/child trees:
       original work → arrangement → translation → choral arrangement
       → …). The admin UI surfaces a tree view; cycle detection is
       enforced application-side at update time (DB-level cycle
       prevention would need stored procedures we don't otherwise rely
       on). */
    $sql = "CREATE TABLE tblWorks (
        Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ParentWorkId  INT UNSIGNED NULL,
        Iswc          CHAR(15)     NULL,
        Title         VARCHAR(255) NOT NULL,
        Slug          VARCHAR(80)  NOT NULL,
        Notes         TEXT         NULL,
        CreatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uq_slug   (Slug),
        UNIQUE KEY uq_iswc   (Iswc),
        INDEX      idx_title (Title),
        INDEX      idx_parent (ParentWorkId),

        CONSTRAINT fk_work_parent
            FOREIGN KEY (ParentWorkId) REFERENCES tblWorks(Id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblWorks failed: ' . $mysqli->error);
    }
    _migWorks_out('[add ] tblWorks.');
}

/* ----------------------------------------------------------------------
 * Step 2 — tblWorkSongs (membership)
 *
 * Composite PK (WorkId, SongId) — a song can belong only once to a
 * given work. The same song MAY belong to multiple works (rare, but
 * occasionally legitimate for medley arrangements that quote multiple
 * compositions); we don't constrain that here.
 *
 * IsCanonical flags the "preferred" song-row for the work — typically
 * zero or one per work, but we don't enforce uniqueness in the schema
 * (the admin UI prevents accidental multi-canonical via UI). The
 * curator's freedom to mark two as canonical (e.g. when there are two
 * equally-canonical English-language sources) outweighs the stricter
 * constraint.
 * ---------------------------------------------------------------------- */
if (_migWorks_tableExists($mysqli, 'tblWorkSongs')) {
    _migWorks_out('[skip] tblWorkSongs already present.');
} else {
    $sql = "CREATE TABLE tblWorkSongs (
        WorkId       INT UNSIGNED NOT NULL,
        SongId       VARCHAR(20)  NOT NULL,
        IsCanonical  TINYINT(1)   NOT NULL DEFAULT 0,
        SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
        Note         VARCHAR(255) NULL,
        CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (WorkId, SongId),
        INDEX idx_song (SongId),
        INDEX idx_work_canonical (WorkId, IsCanonical),

        CONSTRAINT fk_work_song_work
            FOREIGN KEY (WorkId) REFERENCES tblWorks(Id)        ON DELETE CASCADE,
        CONSTRAINT fk_work_song_song
            FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)    ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblWorkSongs failed: ' . $mysqli->error);
    }
    _migWorks_out('[add ] tblWorkSongs.');
}

/* ----------------------------------------------------------------------
 * Step 3 — Widen tblExternalLinkTypes.AppliesTo to include 'work'
 *
 * Idempotent: only ALTER if the SET column lacks 'work'. The seeded
 * registry rows themselves don't need to be updated — the existing
 * AppliesTo values like 'song,songbook,person' just won't include
 * 'work'. The Works admin filter joins with FIND_IN_SET so types
 * without 'work' are silently excluded.
 * ---------------------------------------------------------------------- */
if (!$hasExtLinkRegistry) {
    _migWorks_out('[warn] tblExternalLinkTypes not present — run migrate-external-links.php (#833) first to enable Works external links.');
} else {
    $colDef = _migWorks_columnDefinition($mysqli, 'tblExternalLinkTypes', 'AppliesTo') ?? '';
    if (strpos($colDef, "'work'") !== false) {
        _migWorks_out('[skip] tblExternalLinkTypes.AppliesTo already includes \'work\'.');
    } else {
        $sql = "ALTER TABLE tblExternalLinkTypes
                MODIFY COLUMN AppliesTo
                  SET('song','songbook','person','work')
                  NOT NULL DEFAULT 'song,songbook,person'";
        if (!$mysqli->query($sql)) {
            throw new \RuntimeException('ALTER tblExternalLinkTypes.AppliesTo failed: ' . $mysqli->error);
        }
        _migWorks_out('[mod ] tblExternalLinkTypes.AppliesTo widened to include \'work\'.');
    }

    /* Seed: most "general / information / read / sheet-music / authority"
       types apply to works too — widen the set on rows where it makes
       sense. We bump AppliesTo on a curated allow-list of slugs, leaving
       social / find-a-grave alone. */
    $worksSlugs = [
        'official-website', 'wikipedia', 'wikidata', 'hymnary-org',
        'hymnal-plus', 'cyber-hymnal', 'lyrics-page',
        'imslp', 'sheet-music-pdf',
        'ccli-songselect',
        'youtube', 'vimeo',
        'spotify', 'apple-music', 'youtube-music', 'bandcamp',
        'soundcloud', 'librivox',
        'discogs', 'musicbrainz-work', 'musicbrainz-recording',
        'archive-misc',
        'other',
    ];
    $widened = 0;
    foreach ($worksSlugs as $slug) {
        $stmt = $mysqli->prepare(
            "UPDATE tblExternalLinkTypes
                SET AppliesTo = CONCAT_WS(',', AppliesTo, 'work')
              WHERE Slug = ?
                AND NOT FIND_IN_SET('work', AppliesTo)"
        );
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        if ($mysqli->affected_rows > 0) {
            $widened++;
        }
        $stmt->close();
    }
    if ($widened > 0) {
        _migWorks_out("[seed] Widened AppliesTo of {$widened} link type" . ($widened === 1 ? '' : 's') . ' to include \'work\'.');
    } else {
        _migWorks_out('[skip] All applicable link-type AppliesTo sets already include \'work\'.');
    }
}

/* ----------------------------------------------------------------------
 * Step 4 — tblWorkExternalLinks
 * ---------------------------------------------------------------------- */
if (!$hasExtLinkRegistry) {
    _migWorks_out('[skip] tblWorkExternalLinks deferred — run migrate-external-links.php (#833) then re-run this migration.');
} elseif (_migWorks_tableExists($mysqli, 'tblWorkExternalLinks')) {
    _migWorks_out('[skip] tblWorkExternalLinks already present.');
} else {
    $sql = "CREATE TABLE tblWorkExternalLinks (
        Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        WorkId      INT UNSIGNED NOT NULL,
        LinkTypeId  INT UNSIGNED NOT NULL,
        Url         VARCHAR(2048) NOT NULL,
        Note        VARCHAR(255) NULL,
        SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
        Verified    TINYINT(1)   NOT NULL DEFAULT 0,
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_work (WorkId),
        INDEX idx_type (LinkTypeId),

        CONSTRAINT fk_link_work
            FOREIGN KEY (WorkId)     REFERENCES tblWorks(Id)             ON DELETE CASCADE,
        CONSTRAINT fk_link_type_work
            FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblWorkExternalLinks failed: ' . $mysqli->error);
    }
    _migWorks_out('[add ] tblWorkExternalLinks.');
}

_migWorks_out('Works migration finished (#840).');
