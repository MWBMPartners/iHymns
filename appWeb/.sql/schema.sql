-- ============================================================================
-- iHymns — MySQL Database Schema
-- Copyright (c) 2026 iHymns. All rights reserved.
--
-- PURPOSE:
-- Defines the complete database structure for song data storage.
-- Replaces the flat-file songs.json approach with a normalised
-- relational schema for better performance, searchability, and
-- concurrent write safety.
--
-- USAGE:
--   Run via the installer:  php appWeb/.sql/install.php
--   Or manually:            mysql -u user -p ihymns < appWeb/.sql/schema.sql
--
-- ENGINE:  InnoDB (transactional, foreign key support)
-- CHARSET: utf8mb4 (full Unicode — emoji, curly quotes, em dashes)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Songbooks
-- Stores the songbook/collection definitions (CP, JP, MP, SDAH, CH, Misc).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS songbooks (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    abbreviation    VARCHAR(10)     NOT NULL UNIQUE,
    name            VARCHAR(255)    NOT NULL,
    song_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Songs
-- Core song metadata. Each song belongs to one songbook.
-- The `song_id` column holds the canonical string ID (e.g., "CP-0001").
-- The `lyrics_text` column holds concatenated plaintext lyrics for
-- full-text searching — populated during migration/save.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS songs (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    song_id             VARCHAR(20)     NOT NULL UNIQUE COMMENT 'Canonical ID, e.g. CP-0001',
    number              INT UNSIGNED    NOT NULL COMMENT 'Song number within its songbook',
    title               VARCHAR(500)    NOT NULL,
    songbook_abbr       VARCHAR(10)     NOT NULL COMMENT 'FK to songbooks.abbreviation',
    songbook_name       VARCHAR(255)    NOT NULL COMMENT 'Denormalised songbook name for convenience',
    language            VARCHAR(10)     NOT NULL DEFAULT 'en',
    copyright           VARCHAR(500)    NOT NULL DEFAULT '',
    ccli                VARCHAR(50)     NOT NULL DEFAULT '',
    verified            TINYINT(1)      NOT NULL DEFAULT 0,
    lyrics_public_domain TINYINT(1)     NOT NULL DEFAULT 0,
    music_public_domain TINYINT(1)      NOT NULL DEFAULT 0,
    has_audio           TINYINT(1)      NOT NULL DEFAULT 0,
    has_sheet_music     TINYINT(1)      NOT NULL DEFAULT 0,
    lyrics_text         MEDIUMTEXT      NOT NULL DEFAULT '' COMMENT 'Concatenated lyrics for full-text search',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes for common query patterns
    INDEX idx_songbook          (songbook_abbr),
    INDEX idx_songbook_number   (songbook_abbr, number),
    FULLTEXT idx_title_ft       (title),
    FULLTEXT idx_lyrics_ft      (lyrics_text),
    FULLTEXT idx_title_lyrics_ft (title, lyrics_text),

    CONSTRAINT fk_songs_songbook
        FOREIGN KEY (songbook_abbr) REFERENCES songbooks(abbreviation)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Song Writers (Lyricists)
-- Many-to-one: a song can have multiple writers.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS song_writers (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    song_id     VARCHAR(20)     NOT NULL,
    name        VARCHAR(255)    NOT NULL,

    INDEX idx_song_id   (song_id),
    INDEX idx_name      (name),

    CONSTRAINT fk_writers_song
        FOREIGN KEY (song_id) REFERENCES songs(song_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Song Composers
-- Many-to-one: a song can have multiple composers.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS song_composers (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    song_id     VARCHAR(20)     NOT NULL,
    name        VARCHAR(255)    NOT NULL,

    INDEX idx_song_id   (song_id),
    INDEX idx_name      (name),

    CONSTRAINT fk_composers_song
        FOREIGN KEY (song_id) REFERENCES songs(song_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Song Components (Verses, Choruses, Refrains)
-- Each component stores its lyrics lines as a JSON array.
-- `sort_order` preserves the display sequence from the original data.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS song_components (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    song_id     VARCHAR(20)     NOT NULL,
    type        VARCHAR(20)     NOT NULL COMMENT 'verse, chorus, refrain, bridge, etc.',
    number      INT UNSIGNED    NOT NULL COMMENT 'Component number (e.g., verse 1, verse 2)',
    sort_order  INT UNSIGNED    NOT NULL COMMENT 'Display order within the song',
    lines_json  JSON            NOT NULL COMMENT 'Array of lyric lines',

    INDEX idx_song_id       (song_id),
    INDEX idx_song_order    (song_id, sort_order),

    CONSTRAINT fk_components_song
        FOREIGN KEY (song_id) REFERENCES songs(song_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
