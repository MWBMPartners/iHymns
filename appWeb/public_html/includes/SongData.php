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
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
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

    /* Capitalise the first Unicode letter in a word, skipping any leading
       quotes or punctuation (e.g. "come → "Come). */
    $capFirstLetter = function (string $word): string {
        if (preg_match('/^([^\p{L}]*)(\p{L})(.*)$/u', $word, $m)) {
            return $m[1] . mb_strtoupper($m[2]) . $m[3];
        }
        return $word;
    };

    /* Strip non-letter/digit chars (except apostrophes) so that "and,"
       compares equal to "and" for the minor-words check. */
    $stripPunct = fn(string $w) => preg_replace('/[^\p{L}\p{N}\']/u', '', $w);

    foreach ($words as $i => &$word) {
        /* Handle hyphenated words — capitalise each part */
        if (strpos($word, '-') !== false) {
            $word = implode('-', array_map(
                fn($p) => $capFirstLetter($p),
                explode('-', $word)
            ));
            continue;
        }
        $prev = $i > 0 ? $words[$i - 1] : '';
        /* Word following ., !, ?, :, em/en dash starts a new clause and is
           always capitalised regardless of the minor-word rule. */
        $newClause = $i > 0 && preg_match('/[.!?:—–]$/u', $prev);
        $isMinor = in_array($stripPunct($word), $minor, true);
        if ($i === 0 || $i === $lastIndex || $newClause || !$isMinor) {
            $word = $capFirstLetter($word);
        }
    }
    unset($word);
    return implode(' ', $words);
}

class SongData
{
    /** MySQLi connection (null when using JSON fallback) */
    private ?mysqli $db = null;

    /** JSON fallback data (used when MySQL is not configured) */
    private ?array $jsonData = null;

    /** Whether we're using JSON fallback mode */
    private bool $jsonMode = false;

    /** Check if running in JSON fallback mode (no MySQL) */
    public function isJsonFallback(): bool { return $this->jsonMode; }

    /**
     * Expand a scripture reference so a search for an abbreviated book
     * name ("Ps 23", "1 Cor 13", "Rev 21") also matches the full form
     * in lyrics / titles (#397). Returns the canonical form (e.g.
     * "Psalm 23") to be concatenated onto the FULLTEXT query, or NULL
     * if the input doesn't look like a scripture reference.
     *
     * The list is intentionally small — just the 66 canonical books and
     * their most common abbreviations. It's not a full parser.
     */
    public static function expandScriptureReference(string $query): ?string
    {
        static $books = [
            'gen'    => 'Genesis',        'ex'    => 'Exodus',      'exod'  => 'Exodus',
            'lev'    => 'Leviticus',      'num'   => 'Numbers',     'deut'  => 'Deuteronomy', 'dt' => 'Deuteronomy',
            'josh'   => 'Joshua',         'judg'  => 'Judges',      'ruth'  => 'Ruth',
            '1 sam'  => '1 Samuel',       '1sam'  => '1 Samuel',    '2 sam' => '2 Samuel',    '2sam' => '2 Samuel',
            '1 kgs'  => '1 Kings',        '1kgs'  => '1 Kings',     '2 kgs' => '2 Kings',     '2kgs' => '2 Kings',
            '1 chr'  => '1 Chronicles',   '2 chr' => '2 Chronicles',
            'ezra'   => 'Ezra',           'neh'   => 'Nehemiah',    'esth'  => 'Esther',      'est' => 'Esther',
            'job'    => 'Job',            'ps'    => 'Psalm',       'psa'   => 'Psalm',       'psalms' => 'Psalm',
            'prov'   => 'Proverbs',       'pr'    => 'Proverbs',    'eccl'  => 'Ecclesiastes',
            'song'   => 'Song of Solomon','isa'   => 'Isaiah',      'jer'   => 'Jeremiah',
            'lam'    => 'Lamentations',   'ezek'  => 'Ezekiel',     'dan'   => 'Daniel',
            'hos'    => 'Hosea',          'joel'  => 'Joel',        'amos'  => 'Amos',        'obad' => 'Obadiah',
            'jon'    => 'Jonah',          'mic'   => 'Micah',       'nah'   => 'Nahum',       'hab' => 'Habakkuk',
            'zeph'   => 'Zephaniah',      'hag'   => 'Haggai',      'zech'  => 'Zechariah',   'mal' => 'Malachi',
            'matt'   => 'Matthew',        'mt'    => 'Matthew',     'mk'    => 'Mark',        'lk' => 'Luke',
            'jn'     => 'John',           'acts'  => 'Acts',        'rom'   => 'Romans',
            '1 cor'  => '1 Corinthians',  '1cor'  => '1 Corinthians','2 cor' => '2 Corinthians','2cor' => '2 Corinthians',
            'gal'    => 'Galatians',      'eph'   => 'Ephesians',   'phil'  => 'Philippians', 'phm' => 'Philemon',
            'col'    => 'Colossians',     '1 thes'=> '1 Thessalonians', '2 thes' => '2 Thessalonians',
            '1 tim'  => '1 Timothy',      '2 tim' => '2 Timothy',   'tit'   => 'Titus',       'heb' => 'Hebrews',
            'jas'    => 'James',          '1 pet' => '1 Peter',     '2 pet' => '2 Peter',
            '1 jn'   => '1 John',         '2 jn'  => '2 John',      '3 jn'  => '3 John',
            'jude'   => 'Jude',           'rev'   => 'Revelation',
        ];

        /* Match patterns like: "ps 23", "1 cor 13:4", "John 3:16", "Rev 21" */
        if (!preg_match('/^((?:[123]\s*)?[A-Za-z.]+)\s+(\d+(?:\s*:\s*\d+)?)/i', trim($query), $m)) {
            return null;
        }

        $bookKey = mb_strtolower(preg_replace('/\./', '', trim($m[1])));
        /* Collapse any whitespace to a single space so "1  Cor" matches "1 cor" */
        $bookKey = preg_replace('/\s+/', ' ', $bookKey);

        if (!isset($books[$bookKey])) return null;

        $chapter = preg_replace('/\s*:\s*/', ':', trim($m[2]));
        return $books[$bookKey] . ' ' . $chapter;
    }

    /**
     * Constructor — connects to MySQL, or falls back to JSON file.
     *
     * When MySQL credentials are not configured (e.g., fresh deployment
     * before database setup), the class falls back to reading songs.json
     * so the app remains functional.
     */
    public function __construct()
    {
        try {
            $this->db = getDbMysqli();
        } catch (\Throwable $e) {
            /* MySQL not available — fall back to JSON file. Logged
               (#534) so admins notice when the live DB is unreachable;
               otherwise the app degrades to read-only JSON without
               any signal. Fresh-install case is handled by the broader
               install-detection logic in includes/db_mysql.php. */
            error_log('[SongData] MySQL unavailable, using JSON fallback: ' . $e->getMessage());
            $this->jsonMode = true;
            $this->_loadJsonFallback();
        }
    }

    /**
     * Load song data from the JSON file as a fallback.
     */
    private function _loadJsonFallback(): void
    {
        $candidates = [
            defined('APP_DATA_FILE') ? APP_DATA_FILE : '',
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'song_data' . DIRECTORY_SEPARATOR . 'songs.json',
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'songs.json',
        ];

        foreach ($candidates as $path) {
            if ($path !== '' && file_exists($path)) {
                $json = file_get_contents($path);
                if ($json !== false) {
                    $this->jsonData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    return;
                }
            }
        }

        throw new \RuntimeException('Song data not available: MySQL not configured and songs.json not found.');
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
        if ($this->jsonMode) {
            return $this->jsonData['meta'] ?? [];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM tblSongs");
        $stmt->execute();
        $result = $stmt->get_result();
        $totalSongs = (int)$result->fetch_assoc()['total'];
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM tblSongbooks");
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
        if ($this->jsonMode) {
            $books = $this->jsonData['songbooks'] ?? [];
            usort($books, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
            return $books;
        }

        $stmt = $this->db->prepare(
            "SELECT Abbreviation AS id, Name AS name, SongCount AS songCount,
                    Colour AS colour,
                    IsOfficial      AS isOfficial,
                    Publisher       AS publisher,
                    PublicationYear AS publicationYear,
                    Copyright       AS copyright,
                    Affiliation     AS affiliation
             FROM tblSongbooks
             ORDER BY Name ASC"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $books = [];
        while ($row = $result->fetch_assoc()) {
            $row['songCount']  = (int)$row['songCount'];
            /* Cast to a strict bool so JSON consumers don't have to
               deal with 0/1 vs true/false ambiguity (#502). */
            $row['isOfficial'] = (bool)$row['isOfficial'];
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
        if ($this->jsonMode) {
            foreach ($this->jsonData['songbooks'] ?? [] as $book) {
                if (strtoupper($book['id']) === $id) return $book;
            }
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT Abbreviation AS id, Name AS name, SongCount AS songCount,
                    Colour AS colour,
                    IsOfficial      AS isOfficial,
                    Publisher       AS publisher,
                    PublicationYear AS publicationYear,
                    Copyright       AS copyright,
                    Affiliation     AS affiliation
             FROM tblSongbooks
             WHERE Abbreviation = ?"
        );
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }
        $row['songCount']  = (int)$row['songCount'];
        $row['isOfficial'] = (bool)$row['isOfficial'];
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
        if ($this->jsonMode) {
            $songs = $this->jsonData['songs'] ?? [];
            if ($songbookId !== null) {
                $songbookId = strtoupper(trim($songbookId));
                $songs = array_values(array_filter($songs, fn($s) => strtoupper($s['songbook']) === $songbookId));
            }
            return $songs;
        }

        $where = [];
        $params = [];
        $types = '';

        if ($songbookId !== null) {
            $songbookId = strtoupper(trim($songbookId));
            $where[] = "s.SongbookAbbr = ?";
            $params[] = $songbookId;
            $types .= 's';
        }

        if (APP_CONFIG['features']['public_domain_only'] ?? false) {
            $where[] = "s.LyricsPublicDomain = 1";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title, s.SongbookAbbr AS songbook,
                       s.SongbookName AS songbookName, s.Language AS language, s.Copyright AS copyright,
                       s.TuneName AS tuneName, s.Ccli AS ccli, s.Iswc AS iswc,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                FROM tblSongs s
                {$whereClause}
                ORDER BY s.SongbookAbbr, s.Number";

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
            /* tuneName / iswc are nullable; normalise to empty string
               in the public JSON so the editor can treat them as plain
               text inputs without null-checking every reader. */
            $row['tuneName'] = $row['tuneName'] ?? '';
            $row['iswc']     = $row['iswc']     ?? '';
            $songs[] = $row;
        }
        $stmt->close();

        /* Bulk-load every many-to-one collection in one query per table
           instead of N per song (#EditorLoad). For the full catalogue
           (≈3,600 songs) this cuts thousands of round-trips down to six
           and is the single biggest win for `exportAsJson()` (Song
           Editor load) and any page that calls `getSongs()` without a
           songbook filter. The per-song private helpers are still used
           by `_fetchSongRow()` for single-song fetches where one
           round-trip beats three table scans.

           Credit collections attached here: writers, composers,
           arrangers (#497), adaptors (#497), translators (#497),
           components. */
        $songIds = array_column($songs, 'id');
        if (!empty($songIds)) {
            $writersMap     = $this->_getWritersMap($songIds);
            $composersMap   = $this->_getComposersMap($songIds);
            $arrangersMap   = $this->_getArrangersMap($songIds);
            $adaptorsMap    = $this->_getAdaptorsMap($songIds);
            $translatorsMap = $this->_getTranslatorsMap($songIds);
            $componentsMap  = $this->_getComponentsMap($songIds);
            /* Tags included in the bulk load (#496 follow-up) so the
               Song Editor's full-catalogue load + any client that
               calls getSongs() has tag assignments available without
               a second per-song round-trip. Same bulk-loader pattern
               as writers / composers / etc. */
            $tagsMap = $this->_getTagsMap($songIds);
            foreach ($songs as &$song) {
                $sid = $song['id'];
                $song['writers']     = $writersMap[$sid]     ?? [];
                $song['composers']   = $composersMap[$sid]   ?? [];
                $song['arrangers']   = $arrangersMap[$sid]   ?? [];
                $song['adaptors']    = $adaptorsMap[$sid]    ?? [];
                $song['translators'] = $translatorsMap[$sid] ?? [];
                $song['components']  = $componentsMap[$sid]  ?? [];
                $song['tags']        = $tagsMap[$sid]        ?? [];
            }
            unset($song);
        }

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

        if ($this->jsonMode) {
            foreach ($this->jsonData['songs'] ?? [] as $song) {
                if (strtoupper($song['id']) === $id) return $song;
            }
            if (preg_match('/^([A-Z]+)-0*(\d+)$/', $id, $m)) {
                return $this->getSongByNumber($m[1], (int)$m[2]);
            }
            return null;
        }

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
        if ($this->jsonMode) {
            foreach ($this->jsonData['songs'] ?? [] as $song) {
                if (strtoupper($song['songbook']) === $songbook && (int)$song['number'] === $number) return $song;
            }
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT SongId FROM tblSongs WHERE SongbookAbbr = ? AND Number = ? LIMIT 1"
        );
        $stmt->bind_param('si', $songbook, $number);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        return $this->_fetchSongRow($row['SongId']);
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

        /* Scripture-reference awareness (#397): if the query looks like a
           Bible reference (e.g. "Ps 23", "1 Cor 13:4"), remember the
           canonical expansion so we can OR it into the FULLTEXT query
           below. We don't mutate $query here because the JSON-fallback
           path below relies on substring matching. */
        $scriptureExpansion = self::expandScriptureReference($query);

        /* JSON fallback: simple substring search */
        if ($this->jsonMode) {
            $q = mb_strtolower($query);
            $songs = $this->getSongs($songbookId);
            $results = [];
            foreach ($songs as $song) {
                if (mb_stripos($song['title'] ?? '', $q) !== false) { $results[] = $song; continue; }
                foreach ($song['writers'] ?? [] as $w) { if (mb_stripos($w, $q) !== false) { $results[] = $song; continue 2; } }
                foreach ($song['composers'] ?? [] as $c) { if (mb_stripos($c, $q) !== false) { $results[] = $song; continue 2; } }
                foreach ($song['components'] ?? [] as $comp) {
                    foreach ($comp['lines'] ?? [] as $line) {
                        if (mb_stripos($line, $q) !== false) { $results[] = $song; continue 3; }
                    }
                }
            }
            return $limit > 0 ? array_slice($results, 0, $limit) : $results;
        }

        $results = [];

        /* For short queries (< 3 chars), use LIKE — FULLTEXT has min word length */
        if (mb_strlen($query) < 3) {
            $likeQuery = '%' . $query . '%';

            $where = ["(s.Title LIKE ? OR s.LyricsText LIKE ?)"];
            $params = [$likeQuery, $likeQuery];
            $types = 'ss';

            if ($songbookId !== null) {
                $songbookId = strtoupper(trim($songbookId));
                $where[] = "s.SongbookAbbr = ?";
                $params[] = $songbookId;
                $types .= 's';
            }

            $limitClause = $limit > 0 ? "LIMIT ?" : "";
            if ($limit > 0) {
                $params[] = $limit;
                $types .= 'i';
            }

            $whereClause = implode(' AND ', $where);

            $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                           s.SongbookAbbr AS songbook, s.SongbookName AS songbookName,
                           s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                           s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                           s.MusicPublicDomain AS musicPublicDomain,
                           s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                    FROM tblSongs s
                    WHERE {$whereClause}
                    ORDER BY s.SongbookAbbr, s.Number
                    {$limitClause}";
        } else {
            /* FULLTEXT search for longer queries.
               If the query looked like a scripture reference, OR in the
               canonical expansion via BOOLEAN MODE so "Ps 23" also
               matches "Psalm 23" and vice versa (#397). */
            $ftQuery = $query;
            if ($scriptureExpansion !== null && $scriptureExpansion !== $query) {
                $ftQuery = '(' . $query . ') (' . $scriptureExpansion . ')';
            }

            $where = ["MATCH(s.Title, s.LyricsText) AGAINST(? IN BOOLEAN MODE)"];
            $params = [$ftQuery];
            $types = 's';

            if ($songbookId !== null) {
                $songbookId = strtoupper(trim($songbookId));
                $where[] = "s.SongbookAbbr = ?";
                $params[] = $songbookId;
                $types .= 's';
            }

            $limitClause = $limit > 0 ? "LIMIT ?" : "";
            if ($limit > 0) {
                $params[] = $limit;
                $types .= 'i';
            }

            $whereClause = implode(' AND ', $where);

            $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                           s.SongbookAbbr AS songbook, s.SongbookName AS songbookName,
                           s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                           s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                           s.MusicPublicDomain AS musicPublicDomain,
                           s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic,
                           MATCH(s.Title, s.LyricsText) AGAINST(? IN BOOLEAN MODE) AS relevance
                    FROM tblSongs s
                    WHERE {$whereClause}
                    ORDER BY relevance DESC, s.SongbookAbbr, s.Number
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

        /* Scripture-reference tag matches (#397 follow-up) — if the query
           looked like a scripture reference, ALSO surface songs that have
           been tagged with the canonical book or full reference. Merges
           de-duplicated into the existing result list at the top so
           curated matches outrank text matches. */
        if ($scriptureExpansion !== null) {
            $tagHits = $this->_searchByScriptureTag($scriptureExpansion, $songbookId);
            if (!empty($tagHits)) {
                $seen = [];
                foreach ($results as $r) { $seen[$r['id']] = true; }
                $merged = $tagHits;
                foreach ($results as $r) {
                    if (!isset($seen[$r['id']])) $merged[] = $r; /* already seen via tag hit */
                    elseif (!isset($tagHits[$r['id'] ?? ''])) $merged[] = $r;
                }
                $results = array_values($merged);
                if ($limit > 0) $results = array_slice($results, 0, $limit);
            }
        }

        return $results;
    }

    /**
     * Find songs tagged with the given scripture reference (#397).
     *
     * Matches `Name = <reference>` (e.g. "Psalm 23"), `Name = <book>`
     * (e.g. "Psalm"), or the kebab-case slug form. Curators tag songs
     * via /manage/editor/ (tags UI) and the hit merges into search
     * results for scripture-style queries.
     */
    private function _searchByScriptureTag(string $scriptureRef, ?string $songbookId): array
    {
        if ($this->jsonMode || !$this->db) return [];

        /* Derive the base book (strip trailing chapter/verse). */
        $book = preg_replace('/\s+\d+(?::\d+)?$/', '', $scriptureRef);

        $slugRef  = self::_tagSlug($scriptureRef);
        $slugBook = self::_tagSlug($book);

        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, s.SongbookName AS songbookName,
                       s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                  FROM tblSongs s
                  JOIN tblSongTagMap m ON m.SongId = s.SongId
                  JOIN tblSongTags   t ON t.Id = m.TagId
                 WHERE t.Name IN (?, ?) OR t.Slug IN (?, ?)";
        $types  = 'ssss';
        $params = [$scriptureRef, $book, $slugRef, $slugBook];

        if ($songbookId !== null) {
            $sql .= ' AND s.SongbookAbbr = ?';
            $types .= 's';
            $params[] = strtoupper(trim($songbookId));
        }

        $sql .= ' ORDER BY s.SongbookAbbr, s.Number';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $out = [];
            while ($row = $result->fetch_assoc()) {
                $row['number']             = $row['number'] !== null ? (int)$row['number'] : null;
                $row['verified']           = (bool)$row['verified'];
                $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
                $row['musicPublicDomain']  = (bool)$row['musicPublicDomain'];
                $row['hasAudio']           = (bool)$row['hasAudio'];
                $row['hasSheetMusic']      = (bool)$row['hasSheetMusic'];
                $out[] = $row;
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $_e) {
            /* Search continues with regular text matches even if the
               scripture-tag JOIN fails; logged so admins notice DDL
               drift on tblSongTags / tblSongTagMap. */
            error_log('[SongData::_searchByScriptureTag] ' . $_e->getMessage());
            return [];
        }
    }

    private static function _tagSlug(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string)$s, '-');
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

        if ($this->jsonMode) {
            $songs = $this->getSongs($songbookId);
            return array_values(array_filter($songs, fn($s) => str_starts_with((string)$s['number'], $number)));
        }

        /* Use LIKE for prefix matching on the number cast to string */
        $likeNumber = $number . '%';
        $stmt = $this->db->prepare(
            "SELECT SongId AS id, Number AS number, Title AS title, SongbookAbbr AS songbook,
                    SongbookName AS songbookName, Language AS language, Copyright AS copyright, Ccli AS ccli,
                    Verified AS verified, LyricsPublicDomain AS lyricsPublicDomain,
                    MusicPublicDomain AS musicPublicDomain,
                    HasAudio AS hasAudio, HasSheetMusic AS hasSheetMusic
             FROM tblSongs
             WHERE SongbookAbbr = ? AND CAST(Number AS CHAR) LIKE ?
             ORDER BY Number"
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
        if ($this->jsonMode) {
            $songs = $this->getSongs($songbookId);
            return empty($songs) ? null : $songs[random_int(0, count($songs) - 1)];
        }
        if ($songbookId !== null) {
            $songbookId = strtoupper(trim($songbookId));
            $stmt = $this->db->prepare(
                "SELECT SongId FROM tblSongs WHERE SongbookAbbr = ? ORDER BY RAND() LIMIT 1"
            );
            $stmt->bind_param('s', $songbookId);
        } else {
            $stmt = $this->db->prepare(
                "SELECT SongId FROM tblSongs ORDER BY RAND() LIMIT 1"
            );
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        return $this->_fetchSongRow($row['SongId']);
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
     * MISSING SONG DETECTION (#285)
     * ===================================================================== */

    /**
     * Find missing song numbers within a songbook.
     *
     * Compares the sequential range (1 to max song number) against
     * existing songs to identify gaps. Useful for editors to spot
     * songs that haven't been added yet.
     *
     * @param string $songbookId Songbook abbreviation (e.g., 'CP')
     * @return array{missing: int[], maxNumber: int, totalExisting: int, songbook: string}
     */
    public function getMissingSongNumbers(string $songbookId): array
    {
        $songbookId = strtoupper(trim($songbookId));

        /* Get all existing song numbers for this songbook */
        $stmt = $this->db->prepare(
            "SELECT Number FROM tblSongs WHERE SongbookAbbr = ? ORDER BY Number"
        );
        $stmt->bind_param('s', $songbookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $existing = [];
        while ($row = $result->fetch_assoc()) {
            $existing[] = (int)$row['Number'];
        }
        $stmt->close();

        if (empty($existing)) {
            return [
                'missing'       => [],
                'maxNumber'     => 0,
                'totalExisting' => 0,
                'songbook'      => $songbookId,
            ];
        }

        $maxNumber = max($existing);
        $existingSet = array_flip($existing);
        $missing = [];

        for ($i = 1; $i <= $maxNumber; $i++) {
            if (!isset($existingSet[$i])) {
                $missing[] = $i;
            }
        }

        return [
            'missing'       => $missing,
            'maxNumber'     => $maxNumber,
            'totalExisting' => count($existing),
            'songbook'      => $songbookId,
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
            "SELECT SongId AS id, Number AS number, Title AS title, SongbookAbbr AS songbook,
                    SongbookName AS songbookName, Language AS language, Copyright AS copyright,
                    TuneName AS tuneName, Ccli AS ccli, Iswc AS iswc,
                    Verified AS verified, LyricsPublicDomain AS lyricsPublicDomain,
                    MusicPublicDomain AS musicPublicDomain,
                    HasAudio AS hasAudio, HasSheetMusic AS hasSheetMusic
             FROM tblSongs
             WHERE SongId = ?
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
        $row['tuneName'] = $row['tuneName'] ?? '';
        $row['iswc']     = $row['iswc']     ?? '';
        $row['writers']      = $this->_getWriters($songId);
        $row['composers']    = $this->_getComposers($songId);
        $row['arrangers']    = $this->_getArrangers($songId);
        $row['adaptors']     = $this->_getAdaptors($songId);
        $row['translators']  = $this->_getTranslators($songId);
        $row['components']   = $this->_getComponents($songId);
        /* Tags attached here too so the single-song read path matches
           the bulk getSongs() shape (#496 follow-up). Uses the same
           SongId-keyed helper — collapsed to the one-song slice. */
        $tagsMap = $this->_getTagsMap([$songId]);
        $row['tags'] = $tagsMap[$songId] ?? [];
        $translations = $this->_getTranslations($songId);
        if (!empty($translations)) {
            $row['translations'] = $translations;
        }

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
            "SELECT Name AS name FROM tblSongWriters WHERE SongId = ? ORDER BY Id"
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
            "SELECT Name AS name FROM tblSongComposers WHERE SongId = ? ORDER BY Id"
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
            "SELECT Type AS type, Number AS number, LinesJson AS lines_json
             FROM tblSongComponents
             WHERE SongId = ?
             ORDER BY SortOrder"
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
     * Bulk-load writers for every song in $songIds and return them as a
     * map keyed by SongId. One query instead of N. Preserves per-song
     * ordering by the `Id` surrogate so the listing order matches what
     * `_getWriters()` would have returned. Used by `getSongs()`.
     *
     * @param string[] $songIds List of song IDs to fetch writers for
     * @return array<string,string[]> SongId → array of writer names
     */
    private function _getWritersMap(array $songIds): array
    {
        if (empty($songIds)) return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT SongId, Name FROM tblSongWriters
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, Id"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['SongId']][] = $row['Name'];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Bulk-load composers keyed by SongId. See `_getWritersMap()`.
     *
     * @param string[] $songIds
     * @return array<string,string[]>
     */
    private function _getComposersMap(array $songIds): array
    {
        if (empty($songIds)) return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT SongId, Name FROM tblSongComposers
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, Id"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['SongId']][] = $row['Name'];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Bulk-load components (verses, choruses) keyed by SongId. Same
     * structure as `_getComponents()` but amortised across every
     * requested song in a single query.
     *
     * @param string[] $songIds
     * @return array<string,array<int,array{type:string,number:int,lines:array}>>
     */
    private function _getComponentsMap(array $songIds): array
    {
        if (empty($songIds)) return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT SongId, Type AS type, Number AS number, LinesJson AS lines_json
             FROM tblSongComponents
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, SortOrder"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['SongId']][] = [
                'type'   => $row['type'],
                'number' => (int)$row['number'],
                'lines'  => json_decode($row['lines_json'], true) ?? [],
            ];
        }
        $stmt->close();
        return $map;
    }

    /* --------------------------------------------------------------
     * Arrangers / Adaptors / Translators (#497)
     *
     * Three sibling credit collections to writers/composers, each
     * backed by a dedicated many-to-one table (tblSongArrangers,
     * tblSongAdaptors, tblSongTranslators). Same idioms as the
     * writers/composers helpers above: a per-song variant for single
     * song lookups (`_fetchSongRow`) and a bulk `*Map` variant for
     * `getSongs()` full-catalogue loads.
     *
     * Note the naming gotcha: `_getTranslators` credits the *people*
     * who produced translations for this specific song, while
     * `_getTranslations` (below) lists the cross-song link records in
     * tblSongTranslations (#352) that map this song to its equivalent
     * in another language. Different tables, different concepts.
     * -------------------------------------------------------------- */

    /** @return string[] */
    private function _getArrangers(string $songId): array
    {
        $stmt = $this->db->prepare(
            "SELECT Name FROM tblSongArrangers WHERE SongId = ? ORDER BY Id"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row['Name']; }
        $stmt->close();
        return $out;
    }

    /** @return string[] */
    private function _getAdaptors(string $songId): array
    {
        $stmt = $this->db->prepare(
            "SELECT Name FROM tblSongAdaptors WHERE SongId = ? ORDER BY Id"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row['Name']; }
        $stmt->close();
        return $out;
    }

    /** @return string[] */
    private function _getTranslators(string $songId): array
    {
        $stmt = $this->db->prepare(
            "SELECT Name FROM tblSongTranslators WHERE SongId = ? ORDER BY Id"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row['Name']; }
        $stmt->close();
        return $out;
    }

    /**
     * Bulk-load arrangers keyed by SongId. See `_getWritersMap()`.
     *
     * @param string[] $songIds
     * @return array<string,string[]>
     */
    private function _getArrangersMap(array $songIds): array
    {
        if (empty($songIds)) return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT SongId, Name FROM tblSongArrangers
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, Id"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['SongId']][] = $row['Name'];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Bulk-load adaptors keyed by SongId. See `_getWritersMap()`.
     *
     * @param string[] $songIds
     * @return array<string,string[]>
     */
    private function _getAdaptorsMap(array $songIds): array
    {
        if (empty($songIds)) return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT SongId, Name FROM tblSongAdaptors
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, Id"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['SongId']][] = $row['Name'];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Bulk-load translators keyed by SongId. See `_getWritersMap()`.
     *
     * @param string[] $songIds
     * @return array<string,string[]>
     */
    private function _getTranslatorsMap(array $songIds): array
    {
        if (empty($songIds)) return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT SongId, Name FROM tblSongTranslators
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, Id"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['SongId']][] = $row['Name'];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Bulk-load tag assignments keyed by SongId (#496 follow-up).
     * Joins tblSongTagMap → tblSongTags so the returned rows carry
     * both the tag name and slug — callers that render chips can use
     * the name, callers that build /tag/<slug> links can use the slug.
     *
     * @param string[] $songIds
     * @return array<string,array<int,array{id:int,name:string,slug:string}>>
     */
    private function _getTagsMap(array $songIds): array
    {
        if (empty($songIds)) return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT m.SongId, t.Id AS id, t.Name AS name, t.Slug AS slug
             FROM tblSongTagMap m
             JOIN tblSongTags t ON t.Id = m.TagId
             WHERE m.SongId IN ($placeholders)
             ORDER BY m.SongId, t.Name ASC"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['SongId']][] = [
                'id'   => (int)$row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
            ];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Get translation links for a song (#352).
     *
     * @param string $songId Song ID
     * @return array Array of {songId, language} objects
     */
    private function _getTranslations(string $songId): array
    {
        $stmt = $this->db->prepare(
            "SELECT TranslatedSongId AS songId, TargetLanguage AS language
             FROM tblSongTranslations
             WHERE SourceSongId = ?
             ORDER BY TargetLanguage"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $result = $stmt->get_result();
        $translations = [];
        while ($row = $result->fetch_assoc()) {
            $translations[] = $row;
        }
        $stmt->close();
        return $translations;
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
            "(s.SongId IN (SELECT SongId FROM tblSongWriters WHERE Name LIKE ?)
              OR s.SongId IN (SELECT SongId FROM tblSongComposers WHERE Name LIKE ?))"
        ];
        $params = [$likeQuery, $likeQuery];
        $types = 'ss';

        if ($songbookId !== null) {
            $where[] = "s.SongbookAbbr = ?";
            $params[] = $songbookId;
            $types .= 's';
        }

        $limitClause = $limit > 0 ? "LIMIT ?" : "";
        if ($limit > 0) {
            $params[] = $limit;
            $types .= 'i';
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, s.SongbookName AS songbookName,
                       s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                FROM tblSongs s
                WHERE {$whereClause}
                ORDER BY s.SongbookAbbr, s.Number
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
