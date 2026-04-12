<?php

declare(strict_types=1);

/**
 * iHymns — Song Data Handler (MySQL)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides server-side access to the song database via MySQL.
 * All queries use MySQLi with prepared statements for security.
 * Handles loading, searching, filtering, and retrieving songs
 * and songbook data for the iHymns web application.
 *
 * USAGE:
 *   require_once __DIR__ . '/db_mysql.php';
 *   $songData = new SongData();
 *   $songbooks = $songData->getSongbooks();
 *   $song = $songData->getSongById('CP-0001');
 *   $results = $songData->searchSongs('amazing grace');
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * TITLE CASE HELPER (#148)
 * ========================================================================= */

/**
 * Convert a string to Title Case, following English title capitalisation rules.
 * Minor words (articles, conjunctions, short prepositions) are lowercased
 * unless they are the first or last word. Hyphenated parts are each capitalised.
 *
 * @param string $str The input string (may be ALL CAPS, lowercase, or mixed)
 * @return string The title-cased string
 */
function toTitleCase(string $str): string
{
    $minor = ['a','an','and','as','at','but','by','for','in','nor','of','on','or','so','the','to','up','yet'];
    $words = preg_split('/\s+/', mb_strtolower(trim($str)));
    $lastIndex = count($words) - 1;
    foreach ($words as $i => &$word) {
        /* Handle hyphenated words — capitalise each part */
        if (strpos($word, '-') !== false) {
            $word = implode('-', array_map(
                fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1),
                explode('-', $word)
            ));
            /* Still apply first/last rule to the whole hyphenated word */
            if ($i !== 0 && $i !== $lastIndex) {
                continue;
            }
        }
        /* Always capitalise first and last word; capitalise non-minor words */
        if ($i === 0 || $i === $lastIndex || !in_array($word, $minor)) {
            $word = mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
        }
    }
    unset($word);
    return implode(' ', $words);
}

class SongData
{
    /** MySQLi connection */
    private mysqli $db;

    /**
     * Constructor — establishes MySQL connection.
     *
     * @throws RuntimeException If the database connection fails
     */
    public function __construct()
    {
        $this->db = getDbMysqli();
    }

    /* =====================================================================
     * METADATA METHODS
     * ===================================================================== */

    /**
     * Get metadata about the song collection.
     *
     * @return array Metadata including totalSongs, totalSongbooks, etc.
     */
    public function getMeta(): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM songs");
        $stmt->execute();
        $result = $stmt->get_result();
        $totalSongs = (int)$result->fetch_assoc()['total'];
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM songbooks");
        $stmt->execute();
        $result = $stmt->get_result();
        $totalSongbooks = (int)$result->fetch_assoc()['total'];
        $stmt->close();

        return [
            'generatedAt'    => date('c'),
            'generatorVersion' => '1.0.0',
            'totalSongs'     => $totalSongs,
            'totalSongbooks' => $totalSongbooks,
        ];
    }

    /* =====================================================================
     * SONGBOOK METHODS
     * ===================================================================== */

    /**
     * Get all songbooks with their details, sorted alphabetically.
     *
     * @return array List of songbook objects (id, name, songCount)
     */
    public function getSongbooks(): array
    {
        $stmt = $this->db->prepare(
            "SELECT abbreviation AS id, name, song_count AS songCount
             FROM songbooks
             ORDER BY name ASC"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $books = [];
        while ($row = $result->fetch_assoc()) {
            $row['songCount'] = (int)$row['songCount'];
            $books[] = $row;
        }
        $stmt->close();
        return $books;
    }

    /**
     * Get a single songbook by its abbreviation ID.
     *
     * @param string $id Songbook abbreviation (e.g., 'CP', 'MP')
     * @return array|null Songbook object or null if not found
     */
    public function getSongbook(string $id): ?array
    {
        $id = strtoupper(trim($id));
        $stmt = $this->db->prepare(
            "SELECT abbreviation AS id, name, song_count AS songCount
             FROM songbooks
             WHERE abbreviation = ?"
        );
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }
        $row['songCount'] = (int)$row['songCount'];
        return $row;
    }

    /* =====================================================================
     * SONG RETRIEVAL METHODS
     * ===================================================================== */

    /**
     * Get all songs, optionally filtered by songbook.
     *
     * When the hidden 'public_domain_only' feature flag is enabled,
     * only songs with lyrics_public_domain = 1 are returned.
     *
     * @param string|null $songbookId Filter by songbook abbreviation (null = all)
     * @return array List of song objects
     */
    public function getSongs(?string $songbookId = null): array
    {
        $where = [];
        $params = [];
        $types = '';

        if ($songbookId !== null) {
            $songbookId = strtoupper(trim($songbookId));
            $where[] = "s.songbook_abbr = ?";
            $params[] = $songbookId;
            $types .= 's';
        }

        if (APP_CONFIG['features']['public_domain_only'] ?? false) {
            $where[] = "s.lyrics_public_domain = 1";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT s.song_id AS id, s.number, s.title, s.songbook_abbr AS songbook,
                       s.songbook_name AS songbookName, s.language, s.copyright, s.ccli,
                       s.verified, s.lyrics_public_domain AS lyricsPublicDomain,
                       s.music_public_domain AS musicPublicDomain,
                       s.has_audio AS hasAudio, s.has_sheet_music AS hasSheetMusic
                FROM songs s
                {$whereClause}
                ORDER BY s.songbook_abbr, s.number";

        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $songs = [];
        while ($row = $result->fetch_assoc()) {
            $row['number'] = (int)$row['number'];
            $row['verified'] = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
            $row['hasAudio'] = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $songs[] = $row;
        }
        $stmt->close();

        /* Attach writers, composers, and components for each song */
        foreach ($songs as &$song) {
            $song['writers']    = $this->_getWriters($song['id']);
            $song['composers'] = $this->_getComposers($song['id']);
            $song['components'] = $this->_getComponents($song['id']);
        }
        unset($song);

        return $songs;
    }

    /**
     * Get a single song by its unique ID (e.g., 'CP-0001').
     *
     * Supports flexible ID formats: 'MP-1', 'MP-01', 'MP-001', and 'MP-0001'
     * all resolve to the same song.
     *
     * @param string $id Song ID in the format 'BOOK-NUMBER' (zero-padding optional)
     * @return array|null Song object or null if not found
     */
    public function getSongById(string $id): ?array
    {
        $id = strtoupper(trim($id));

        /* Try exact match first (fast path) */
        $song = $this->_fetchSongRow($id);

        /* No exact match — try normalized matching */
        if ($song === null && preg_match('/^([A-Z]+)-0*(\d+)$/', $id, $matches)) {
            $prefix = $matches[1];
            $number = (int)$matches[2];
            return $this->getSongByNumber($prefix, $number);
        }

        return $song;
    }

    /**
     * Get a song by songbook abbreviation and song number.
     *
     * @param string $songbook Songbook abbreviation (e.g., 'CP')
     * @param int    $number   Song number within the songbook
     * @return array|null Song object or null if not found
     */
    public function getSongByNumber(string $songbook, int $number): ?array
    {
        $songbook = strtoupper(trim($songbook));

        $stmt = $this->db->prepare(
            "SELECT song_id FROM songs WHERE songbook_abbr = ? AND number = ? LIMIT 1"
        );
        $stmt->bind_param('si', $songbook, $number);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        return $this->_fetchSongRow($row['song_id']);
    }

    /* =====================================================================
     * SEARCH METHODS
     * ===================================================================== */

    /**
     * Search songs by title, lyrics, writers, or composers.
     *
     * Uses MySQL FULLTEXT search on title and lyrics_text for relevance-ranked
     * results, with a fallback to LIKE for short queries.
     *
     * @param string      $query      Search query string
     * @param string|null $songbookId Limit search to a specific songbook
     * @param int         $limit      Maximum results to return (0 = no limit)
     * @return array Matching song objects
     */
    public function searchSongs(string $query, ?string $songbookId = null, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $results = [];

        /* For short queries (< 3 chars), use LIKE — FULLTEXT has min word length */
        if (mb_strlen($query) < 3) {
            $likeQuery = '%' . $query . '%';

            $where = ["(s.title LIKE ? OR s.lyrics_text LIKE ?)"];
            $params = [$likeQuery, $likeQuery];
            $types = 'ss';

            if ($songbookId !== null) {
                $songbookId = strtoupper(trim($songbookId));
                $where[] = "s.songbook_abbr = ?";
                $params[] = $songbookId;
                $types .= 's';
            }

            $limitClause = $limit > 0 ? "LIMIT ?" : "";
            if ($limit > 0) {
                $params[] = $limit;
                $types .= 'i';
            }

            $whereClause = implode(' AND ', $where);

            $sql = "SELECT s.song_id AS id, s.number, s.title,
                           s.songbook_abbr AS songbook, s.songbook_name AS songbookName,
                           s.language, s.copyright, s.ccli,
                           s.verified, s.lyrics_public_domain AS lyricsPublicDomain,
                           s.music_public_domain AS musicPublicDomain,
                           s.has_audio AS hasAudio, s.has_sheet_music AS hasSheetMusic
                    FROM songs s
                    WHERE {$whereClause}
                    ORDER BY s.songbook_abbr, s.number
                    {$limitClause}";
        } else {
            /* FULLTEXT search for longer queries */
            $ftQuery = $query;

            $where = ["MATCH(s.title, s.lyrics_text) AGAINST(? IN BOOLEAN MODE)"];
            $params = [$ftQuery];
            $types = 's';

            if ($songbookId !== null) {
                $songbookId = strtoupper(trim($songbookId));
                $where[] = "s.songbook_abbr = ?";
                $params[] = $songbookId;
                $types .= 's';
            }

            $limitClause = $limit > 0 ? "LIMIT ?" : "";
            if ($limit > 0) {
                $params[] = $limit;
                $types .= 'i';
            }

            $whereClause = implode(' AND ', $where);

            $sql = "SELECT s.song_id AS id, s.number, s.title,
                           s.songbook_abbr AS songbook, s.songbook_name AS songbookName,
                           s.language, s.copyright, s.ccli,
                           s.verified, s.lyrics_public_domain AS lyricsPublicDomain,
                           s.music_public_domain AS musicPublicDomain,
                           s.has_audio AS hasAudio, s.has_sheet_music AS hasSheetMusic,
                           MATCH(s.title, s.lyrics_text) AGAINST(? IN BOOLEAN MODE) AS relevance
                    FROM songs s
                    WHERE {$whereClause}
                    ORDER BY relevance DESC, s.songbook_abbr, s.number
                    {$limitClause}";

            /* Add the MATCH param again for SELECT (relevance score) */
            $params = array_merge([$ftQuery], $params);
            $types = 's' . $types;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            unset($row['relevance']);
            $row['number'] = (int)$row['number'];
            $row['verified'] = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
            $row['hasAudio'] = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $row['writers']    = $this->_getWriters($row['id']);
            $row['composers'] = $this->_getComposers($row['id']);
            $row['components'] = $this->_getComponents($row['id']);
            $results[] = $row;
        }
        $stmt->close();

        /* If FULLTEXT returned no results, fall back to LIKE search on writers/composers */
        if (empty($results) && mb_strlen($query) >= 3) {
            $results = $this->_searchByWriterComposer($query, $songbookId, $limit);
        }

        return $results;
    }

    /**
     * Search songs by number within a songbook.
     *
     * @param string $songbookId Songbook abbreviation
     * @param string $number     Number to search for (can be partial, e.g. '12' matches 12, 120, 121...)
     * @return array Matching song objects
     */
    public function searchByNumber(string $songbookId, string $number): array
    {
        $songbookId = strtoupper(trim($songbookId));
        $number = trim($number);

        if ($number === '') {
            return [];
        }

        /* Use LIKE for prefix matching on the number cast to string */
        $likeNumber = $number . '%';
        $stmt = $this->db->prepare(
            "SELECT song_id AS id, number, title, songbook_abbr AS songbook,
                    songbook_name AS songbookName, language, copyright, ccli,
                    verified, lyrics_public_domain AS lyricsPublicDomain,
                    music_public_domain AS musicPublicDomain,
                    has_audio AS hasAudio, has_sheet_music AS hasSheetMusic
             FROM songs
             WHERE songbook_abbr = ? AND CAST(number AS CHAR) LIKE ?
             ORDER BY number"
        );
        $stmt->bind_param('ss', $songbookId, $likeNumber);
        $stmt->execute();
        $result = $stmt->get_result();

        $songs = [];
        while ($row = $result->fetch_assoc()) {
            $row['number'] = (int)$row['number'];
            $row['verified'] = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
            $row['hasAudio'] = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $row['writers']    = $this->_getWriters($row['id']);
            $row['composers'] = $this->_getComposers($row['id']);
            $row['components'] = $this->_getComponents($row['id']);
            $songs[] = $row;
        }
        $stmt->close();

        return $songs;
    }

    /* =====================================================================
     * RANDOM / SHUFFLE METHODS
     * ===================================================================== */

    /**
     * Get a random song, optionally from a specific songbook.
     *
     * @param string|null $songbookId Limit to a specific songbook (null = all)
     * @return array|null Random song object or null if no songs available
     */
    public function getRandomSong(?string $songbookId = null): ?array
    {
        if ($songbookId !== null) {
            $songbookId = strtoupper(trim($songbookId));
            $stmt = $this->db->prepare(
                "SELECT song_id FROM songs WHERE songbook_abbr = ? ORDER BY RAND() LIMIT 1"
            );
            $stmt->bind_param('s', $songbookId);
        } else {
            $stmt = $this->db->prepare(
                "SELECT song_id FROM songs ORDER BY RAND() LIMIT 1"
            );
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        return $this->_fetchSongRow($row['song_id']);
    }

    /* =====================================================================
     * STATISTICS METHODS
     * ===================================================================== */

    /**
     * Get summary statistics about the song collection.
     *
     * @return array Statistics array with totalSongs, songbookCounts, etc.
     */
    public function getStats(): array
    {
        $songbooks = $this->getSongbooks();
        $totalSongs = 0;
        $bookStats = [];

        foreach ($songbooks as $book) {
            $count = $book['songCount'] ?? 0;
            $totalSongs += $count;
            $bookStats[] = [
                'id'        => $book['id'],
                'name'      => $book['name'],
                'songCount' => $count,
            ];
        }

        return [
            'totalSongs'     => $totalSongs,
            'totalSongbooks' => count($songbooks),
            'songbooks'      => $bookStats,
        ];
    }

    /* =====================================================================
     * EXPORT METHOD — Generate full JSON for client-side caching / PWA
     * ===================================================================== */

    /**
     * Export the complete song database as a JSON-compatible array.
     *
     * Reproduces the same structure as the original songs.json for
     * backward compatibility with the PWA client-side cache and Fuse.js.
     *
     * @return array Full data array with meta, songbooks, and songs
     */
    public function exportAsJson(): array
    {
        return [
            'meta'      => $this->getMeta(),
            'songbooks' => $this->getSongbooks(),
            'songs'     => $this->getSongs(),
        ];
    }

    /* =====================================================================
     * PRIVATE HELPER METHODS
     * ===================================================================== */

    /**
     * Fetch a single song row with all related data by song_id.
     *
     * @param string $songId The canonical song ID (e.g., 'CP-0001')
     * @return array|null Complete song object or null
     */
    private function _fetchSongRow(string $songId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT song_id AS id, number, title, songbook_abbr AS songbook,
                    songbook_name AS songbookName, language, copyright, ccli,
                    verified, lyrics_public_domain AS lyricsPublicDomain,
                    music_public_domain AS musicPublicDomain,
                    has_audio AS hasAudio, has_sheet_music AS hasSheetMusic
             FROM songs
             WHERE song_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        $row['number'] = (int)$row['number'];
        $row['verified'] = (bool)$row['verified'];
        $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
        $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
        $row['hasAudio'] = (bool)$row['hasAudio'];
        $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
        $row['writers']    = $this->_getWriters($songId);
        $row['composers'] = $this->_getComposers($songId);
        $row['components'] = $this->_getComponents($songId);

        return $row;
    }

    /**
     * Get writer names for a song.
     *
     * @param string $songId Song ID
     * @return string[] Array of writer names
     */
    private function _getWriters(string $songId): array
    {
        $stmt = $this->db->prepare(
            "SELECT name FROM song_writers WHERE song_id = ? ORDER BY id"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $result = $stmt->get_result();
        $writers = [];
        while ($row = $result->fetch_assoc()) {
            $writers[] = $row['name'];
        }
        $stmt->close();
        return $writers;
    }

    /**
     * Get composer names for a song.
     *
     * @param string $songId Song ID
     * @return string[] Array of composer names
     */
    private function _getComposers(string $songId): array
    {
        $stmt = $this->db->prepare(
            "SELECT name FROM song_composers WHERE song_id = ? ORDER BY id"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $result = $stmt->get_result();
        $composers = [];
        while ($row = $result->fetch_assoc()) {
            $composers[] = $row['name'];
        }
        $stmt->close();
        return $composers;
    }

    /**
     * Get components (verses, choruses) for a song.
     *
     * @param string $songId Song ID
     * @return array Array of component objects with type, number, and lines
     */
    private function _getComponents(string $songId): array
    {
        $stmt = $this->db->prepare(
            "SELECT type, number, lines_json
             FROM song_components
             WHERE song_id = ?
             ORDER BY sort_order"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $result = $stmt->get_result();
        $components = [];
        while ($row = $result->fetch_assoc()) {
            $components[] = [
                'type'   => $row['type'],
                'number' => (int)$row['number'],
                'lines'  => json_decode($row['lines_json'], true) ?? [],
            ];
        }
        $stmt->close();
        return $components;
    }

    /**
     * Fallback search by writer/composer name using LIKE.
     *
     * @param string      $query      Search query
     * @param string|null $songbookId Optional songbook filter
     * @param int         $limit      Maximum results
     * @return array Matching songs
     */
    private function _searchByWriterComposer(string $query, ?string $songbookId, int $limit): array
    {
        $likeQuery = '%' . $query . '%';

        $where = [
            "(s.song_id IN (SELECT song_id FROM song_writers WHERE name LIKE ?)
              OR s.song_id IN (SELECT song_id FROM song_composers WHERE name LIKE ?))"
        ];
        $params = [$likeQuery, $likeQuery];
        $types = 'ss';

        if ($songbookId !== null) {
            $where[] = "s.songbook_abbr = ?";
            $params[] = $songbookId;
            $types .= 's';
        }

        $limitClause = $limit > 0 ? "LIMIT ?" : "";
        if ($limit > 0) {
            $params[] = $limit;
            $types .= 'i';
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT s.song_id AS id, s.number, s.title,
                       s.songbook_abbr AS songbook, s.songbook_name AS songbookName,
                       s.language, s.copyright, s.ccli,
                       s.verified, s.lyrics_public_domain AS lyricsPublicDomain,
                       s.music_public_domain AS musicPublicDomain,
                       s.has_audio AS hasAudio, s.has_sheet_music AS hasSheetMusic
                FROM songs s
                WHERE {$whereClause}
                ORDER BY s.songbook_abbr, s.number
                {$limitClause}";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $songs = [];
        while ($row = $result->fetch_assoc()) {
            $row['number'] = (int)$row['number'];
            $row['verified'] = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
            $row['hasAudio'] = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $row['writers']    = $this->_getWriters($row['id']);
            $row['composers'] = $this->_getComposers($row['id']);
            $row['components'] = $this->_getComponents($row['id']);
            $songs[] = $row;
        }
        $stmt->close();

        return $songs;
    }
}
