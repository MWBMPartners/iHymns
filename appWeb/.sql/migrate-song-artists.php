<?php

declare(strict_types=1);

/**
 * iHymns — Songs Artist credit migration (#587)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds the new `tblSongArtists` table — a sixth credit role parallel
 * to writers / composers / arrangers / adaptors / translators. The
 * Artist credit captures the recording / release artist of a
 * contemporary worship song (e.g. "Hillsong Worship" for "What a
 * Beautiful Name", "Matt Redman" for "10,000 Reasons"). Traditional
 * hymns often leave it blank; the field is purely additive.
 *
 * Schema:
 *   tblSongArtists.Id        INT AUTO_INCREMENT PRIMARY KEY
 *   tblSongArtists.SongId    VARCHAR(20) NOT NULL  (FK → tblSongs.SongId)
 *   tblSongArtists.Name      VARCHAR(255) NOT NULL
 *   tblSongArtists.SortOrder SMALLINT NOT NULL DEFAULT 0
 *
 * @migration-adds tblSongArtists.Id
 * @migration-adds tblSongArtists.SongId
 * @migration-adds tblSongArtists.Name
 * @migration-adds tblSongArtists.SortOrder
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-song-artists.php
 *
 * Idempotent — re-running is safe; the table-exists check skips the
 * CREATE if the table is already present.
 */

if (PHP_SAPI === 'cli') {
    /* Guarded require — see #652. The dashboard has already loaded

       db_mysql.php via auth.php's bootstrap, so the function already

       exists at this point in dashboard mode; the guard skips the

       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        /* Guarded: dashboard mode pre-loads auth.php transitively. The

           guard also avoids re-opening the file from outside public_html/,

           which some hosts (open_basedir / php-fpm chroot) refuse even

           though the file is otherwise reachable (#652). */

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
    /* Guarded require — see #652. The dashboard has already loaded

       db_mysql.php via auth.php's bootstrap, so the function already

       exists at this point in dashboard mode; the guard skips the

       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = false;
}

function _migArtists_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migArtists_tableExists(mysqli $db, string $table): bool
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

_migArtists_out('Songs Artist credit migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migArtists_out('ERROR: could not connect to database.');
    exit(1);
}

if (_migArtists_tableExists($mysqli, 'tblSongArtists')) {
    _migArtists_out('[skip] tblSongArtists already present.');
} else {
    $sql = "CREATE TABLE tblSongArtists (
        Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        SongId      VARCHAR(20)     NOT NULL,
        Name        VARCHAR(255)    NOT NULL,
        SortOrder   SMALLINT        NOT NULL DEFAULT 0,
        CreatedAt   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_SongId (SongId),
        INDEX idx_Name   (Name),
        CONSTRAINT fk_Artists_Song
            FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migArtists_out('ERROR: creating tblSongArtists failed: ' . $mysqli->error);
        exit(1);
    }
    _migArtists_out('[add ] tblSongArtists.');
}

_migArtists_out('Songs Artist credit migration finished.');
/* Don't close $mysqli — it's the shared singleton from getDbMysqli().
   The bulk migration runner in /manage/setup-database.php iterates many
   migrations in one PHP request; closing here would invalidate the
   handle for every subsequent migration that calls getDbMysqli(). PHP
   closes the connection on script exit anyway. */
