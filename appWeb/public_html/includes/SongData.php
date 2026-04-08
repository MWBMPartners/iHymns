<?php

declare(strict_types=1);

/**
 * iHymns — Song Data Handler
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides server-side access to the song database (songs.json).
 * Handles loading, caching, searching, filtering, and retrieving
 * songs and songbook data for the iHymns web application.
 *
 * The class uses a singleton-like pattern via static caching to avoid
 * re-reading the JSON file on every request within the same PHP process.
 *
 * USAGE:
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
    /**
     * Static cache for the parsed song data.
     * Prevents re-reading the JSON file within the same request.
     */
    private static ?array $cache = null;

    /** Full parsed data array from songs.json */
    private array $data;

    /**
     * Constructor — loads and parses the song data.
     *
     * @throws RuntimeException If the data file cannot be read or parsed
     */
    public function __construct()
    {
        /* Use cached data if already loaded in this request */
        if (self::$cache !== null) {
            $this->data = self::$cache;
            return;
        }

        /* Verify the data file exists */
        if (!file_exists(APP_DATA_FILE)) {
            throw new \RuntimeException('Song data file not found: ' . APP_DATA_FILE);
        }

        /* Read and decode the JSON data */
        $json = file_get_contents(APP_DATA_FILE);
        if ($json === false) {
            throw new \RuntimeException('Failed to read song data file.');
        }

        $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($parsed)) {
            throw new \RuntimeException('Invalid song data format.');
        }

        /* Store in instance and static cache */
        $this->data = $parsed;
        self::$cache = $parsed;
    }

    /* =====================================================================
     * METADATA METHODS
     * ===================================================================== */

    /**
     * Get the metadata object from songs.json.
     *
     * @return array Metadata including generatedAt, totalSongs, etc.
     */
    public function getMeta(): array
    {
        return $this->data['meta'] ?? [];
    }

    /* =====================================================================
     * SONGBOOK METHODS
     * ===================================================================== */

    /**
     * Get all songbooks with their details.
     *
     * @return array List of songbook objects (id, name, songCount)
     */
    public function getSongbooks(): array
    {
        $books = $this->data['songbooks'] ?? [];
        usort($books, function (array $a, array $b): int {
            $normalize = function (string $s): string {
                return preg_replace('/^(the|a|an)\s+/i', '', strtolower(trim($s)));
            };
            return strcmp($normalize($a['name'] ?? ''), $normalize($b['name'] ?? ''));
        });
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
        foreach ($this->data['songbooks'] ?? [] as $book) {
            if (strtoupper($book['id']) === $id) {
                return $book;
            }
        }
        return null;
    }

    /* =====================================================================
     * SONG RETRIEVAL METHODS
     * ===================================================================== */

    /**
     * Get all songs, optionally filtered by songbook.
     *
     * When the hidden 'public_domain_only' feature flag is enabled,
     * only songs with empty copyright fields are returned. This allows
     * the service to be switched to show only copyright-free songs.
     *
     * @param string|null $songbookId Filter by songbook abbreviation (null = all)
     * @return array List of song objects
     */
    public function getSongs(?string $songbookId = null): array
    {
        $songs = $this->data['songs'] ?? [];

        /* Filter by songbook if specified */
        if ($songbookId !== null) {
            $songbookId = strtoupper(trim($songbookId));
            $songs = array_values(array_filter($songs, function (array $song) use ($songbookId): bool {
                return strtoupper($song['songbook']) === $songbookId;
            }));
        }

        /* HIDDEN FEATURE: Filter to public domain songs only.
         * Uses the lyricsPublicDomain boolean added by the parser (#225),
         * with a fallback to the copyright-string heuristic for any
         * songs.json files that haven't been regenerated yet.
         * Note: an empty copyright does NOT imply public domain — only
         * an explicit designation counts (case-insensitive):
         * "Public Domain", "PD", "PublicDomain", "PubDomain", "Pub Domain" */
        if (APP_CONFIG['features']['public_domain_only'] ?? false) {
            $songs = array_values(array_filter($songs, function (array $song): bool {
                /* Prefer the explicit boolean field if present */
                if (isset($song['lyricsPublicDomain'])) {
                    return (bool) $song['lyricsPublicDomain'];
                }
                /* Legacy fallback: check copyright string for explicit PD designation */
                $copyright = trim($song['copyright'] ?? '');
                return mb_stripos($copyright, 'public domain') !== false
                    || mb_stripos($copyright, 'publicdomain') !== false
                    || mb_stripos($copyright, 'pubdomain') !== false
                    || mb_stripos($copyright, 'pub domain') !== false
                    || strtoupper($copyright) === 'PD';
            }));
        }

        return $songs;
    }

    /**
     * Get a single song by its unique ID (e.g., 'CP-0001').
     *
     * Supports flexible ID formats: 'MP-1', 'MP-01', 'MP-001', and 'MP-0001'
     * all resolve to the same song. The lookup extracts the alphabetic prefix
     * and numeric suffix, strips leading zeros, then matches by songbook code
     * and number.
     *
     * @param string $id Song ID in the format 'BOOK-NUMBER' (zero-padding optional)
     * @return array|null Song object or null if not found
     */
    public function getSongById(string $id): ?array
    {
        $id = strtoupper(trim($id));

        /* Try exact match first (fast path for canonical IDs) */
        foreach ($this->data['songs'] ?? [] as $song) {
            if (strtoupper($song['id']) === $id) {
                return $song;
            }
        }

        /* No exact match — try normalized matching.
         * Extract the alphabetic prefix and numeric part, then compare
         * against each song's songbook code and number. */
        if (preg_match('/^([A-Z]+)-0*(\d+)$/', $id, $matches)) {
            $prefix = $matches[1];
            $number = (int) $matches[2];

            return $this->getSongByNumber($prefix, $number);
        }

        return null;
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
        foreach ($this->data['songs'] ?? [] as $song) {
            if (strtoupper($song['songbook']) === $songbook && (int)$song['number'] === $number) {
                return $song;
            }
        }
        return null;
    }

    /* =====================================================================
     * SEARCH METHODS
     * ===================================================================== */

    /**
     * Search songs by title, lyrics, or other fields.
     *
     * Performs a case-insensitive substring match across title,
     * songbook name, writers, and composers. For a more advanced
     * fuzzy search, the client-side Fuse.js is used instead.
     *
     * @param string      $query      Search query string
     * @param string|null $songbookId Limit search to a specific songbook
     * @param int         $limit      Maximum results to return (0 = no limit)
     * @return array Matching song objects
     */
    public function searchSongs(string $query, ?string $songbookId = null, int $limit = 50): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $songs = $this->getSongs($songbookId);
        $results = [];

        foreach ($songs as $song) {
            /* Check title */
            if (mb_stripos($song['title'] ?? '', $query) !== false) {
                $results[] = $song;
                continue;
            }

            /* Check songbook name */
            if (mb_stripos($song['songbookName'] ?? '', $query) !== false) {
                $results[] = $song;
                continue;
            }

            /* Check writers */
            foreach ($song['writers'] ?? [] as $writer) {
                if (mb_stripos($writer, $query) !== false) {
                    $results[] = $song;
                    continue 2;
                }
            }

            /* Check composers */
            foreach ($song['composers'] ?? [] as $composer) {
                if (mb_stripos($composer, $query) !== false) {
                    $results[] = $song;
                    continue 2;
                }
            }

            /* Check lyrics content */
            foreach ($song['components'] ?? [] as $component) {
                foreach ($component['lines'] ?? [] as $line) {
                    if (mb_stripos($line, $query) !== false) {
                        $results[] = $song;
                        continue 3;
                    }
                }
            }
        }

        /* Apply limit */
        if ($limit > 0 && count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
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

        $songs = $this->getSongs($songbookId);
        $results = [];

        foreach ($songs as $song) {
            $songNum = (string)$song['number'];
            /* Match if the song number starts with the query */
            if (str_starts_with($songNum, $number)) {
                $results[] = $song;
            }
        }

        return $results;
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
        $songs = $this->getSongs($songbookId);

        if (empty($songs)) {
            return null;
        }

        /* Use cryptographically secure random selection */
        $index = random_int(0, count($songs) - 1);
        return $songs[$index];
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
            'totalSongs'    => $totalSongs,
            'totalSongbooks' => count($songbooks),
            'songbooks'     => $bookStats,
        ];
    }
}
