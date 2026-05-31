<?php

declare(strict_types=1);

/**
 * iHymns — Song Media Uploads Migration (#853)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds tblSongMedia — the unified per-song accompanying-files table so
 * curators can upload audio (MP3/M4A/OGG/WAV/FLAC/ALAC), sheet music
 * (PDF), notation (MusicXML) and MIDI for each song via the Song Editor.
 *
 * STORAGE STRATEGY (hybrid behind a per-kind routing constant — see
 * appWeb/public_html/includes/SongMediaStorage.php):
 *
 *   Database (MEDIUMBLOB) — sheet-music, midi, musicxml
 *     Small files (<10MB), no streaming/range concerns, atomic backups.
 *
 *   Filesystem (appWeb/uploads/songs/<2-char-prefix>/<sha256>.<ext>) — audio
 *     Large files; static-file performance + native HTTP range requests
 *     for audio scrubbing. Off the public docroot — served via the gated
 *     /song-media/<id> PHP route (phase E) so checkContentAccess()
 *     enforcement applies regardless of backend.
 *
 * GATING (phase E):
 * Each media row inherits its parent song's content-restriction rules
 * via the existing checkContentAccess('song', $songId) — no per-row
 * gating in this PR. (Per-row gating can layer on later via
 * tblContentRestrictions with EntityType='song-media'.)
 *
 * BACK-COMPAT:
 * tblSongs.HasAudio / HasSheetMusic boolean flags are preserved. Public
 * read paths (#853 phase D) prefer tblSongMedia row counts but fall
 * back to the legacy flag when the table is empty for a given song,
 * keeping pre-migration scrape data renderable.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-song-media.php
 *   Web: /manage/setup-database → "Song Media Uploads (#853)"
 *
 * @migration-adds tblSongMedia
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

function _migSongMedia_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migSongMedia_tableExists(\mysqli $db, string $table): bool
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

_migSongMedia_out('Song Media migration starting (#853)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migSongMedia_tableExists($mysqli, 'tblSongs')) {
    _migSongMedia_out('ERROR: tblSongs not found. Run prerequisite migrations first.');
    return;
}

/* ----------------------------------------------------------------------
 * Step 1 — tblSongMedia
 *
 * Storage routing is encoded in StorageBackend ('filesystem' | 'database')
 * — kept explicit on the row rather than inferred from Kind so a future
 * policy change (e.g. routing all kinds to FS once R2 is wired) doesn't
 * have to back-fill the column. The Content / StoragePath columns are
 * mutually exclusive at the row level (one of them is non-NULL based
 * on StorageBackend). Both are nullable so the same DDL covers both
 * backends without a separate per-backend table.
 *
 * Sha256 indexed for future de-dup (a curator uploading the same MP3
 * to two songs should reuse the file rather than store twice — TODO,
 * not in this PR's scope).
 * ---------------------------------------------------------------------- */
if (_migSongMedia_tableExists($mysqli, 'tblSongMedia')) {
    _migSongMedia_out('[skip] tblSongMedia already present.');
} else {
    $sql = "CREATE TABLE tblSongMedia (
        Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongId          VARCHAR(20)  NOT NULL,
        Kind            ENUM('audio','sheet-music','midi','musicxml') NOT NULL,
        StorageBackend  ENUM('filesystem','database') NOT NULL,
        FileName        VARCHAR(255) NOT NULL,
        MimeType        VARCHAR(127) NOT NULL,
        SizeBytes       BIGINT UNSIGNED NOT NULL,
        Sha256          CHAR(64)     NOT NULL,
        Content         MEDIUMBLOB   NULL,
        StoragePath     VARCHAR(255) NULL,
        Annotation      VARCHAR(255) NULL,
        SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
        UploadedBy      INT UNSIGNED NULL,
        UploadedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_song_kind (SongId, Kind, SortOrder),
        INDEX idx_kind      (Kind),
        INDEX idx_sha256    (Sha256),

        CONSTRAINT fk_media_song
            FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblSongMedia failed: ' . $mysqli->error);
    }
    _migSongMedia_out('[add ] tblSongMedia.');
}

_migSongMedia_out('Song Media migration finished (#853).');
_migSongMedia_out('Filesystem storage root (created on first upload):');
_migSongMedia_out('  ' . dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'songs');
