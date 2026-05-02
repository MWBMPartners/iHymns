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

/**
 * Normalise a tblSongs.Number value coming back from the database to the
 * canonical "unnumbered" representation (#797).
 *
 * The column is nullable and NULL is the canonical sentinel for "this
 * song has no songbook position" (#392). However:
 *   - mysqli + assoc fetch hands NULL back as PHP null, which a naive
 *     `(int)$row['number']` round-trips to 0 — masking the NULL and
 *     causing the rest of the app to render "0" everywhere;
 *   - some legacy rows / payloads carry an empty string or '0'.
 *
 * Treat null, '', '0' and any non-positive integer as null. Any positive
 * integer is preserved as int.
 *
 * @param mixed $value
 * @return int|null
 */
function normaliseSongNumber($value): ?int
{
    if ($value === null || $value === '') return null;
    $n = (int)$value;
    return $n > 0 ? $n : null;
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

        $bibSelect    = $this->_songbookBibSelect();
        $langSelect   = $this->_songbookLanguageSelect();
        $parentSelect = $this->_songbookParentSelect();
        $parentJoin   = $this->_songbookParentJoin();
        $stmt = $this->db->prepare(
            "SELECT b.Abbreviation AS id, b.Name AS name, b.SongCount AS songCount,
                    b.Colour AS colour,
                    b.IsOfficial      AS isOfficial,
                    b.Publisher       AS publisher,
                    b.PublicationYear AS publicationYear,
                    b.Copyright       AS copyright,
                    b.Affiliation     AS affiliation
                    {$langSelect}
                    {$bibSelect}
                    {$parentSelect}
             FROM tblSongbooks b{$parentJoin}
             ORDER BY b.Name ASC"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $books = [];
        while ($row = $result->fetch_assoc()) {
            $row['songCount']  = (int)$row['songCount'];
            /* Cast to a strict bool so JSON consumers don't have to
               deal with 0/1 vs true/false ambiguity (#502). */
            $row['isOfficial'] = (bool)$row['isOfficial'];
            $books[] = $this->_normaliseSongbookParent($row);
        }
        $stmt->close();

        /* Attach series memberships in one batch query (#782 phase D)
           so the home/browse grids can render a "Part of: <Series>" line
           without N+1 queries. */
        if ($books) {
            $seriesMap = $this->_songbookSeriesMap(null);
            foreach ($books as &$_b) {
                $_b['series'] = $seriesMap[(string)$_b['id']] ?? [];
            }
            unset($_b);
        }
        return $books;
    }

    /**
     * Normalise the parent-songbook fields on a fetched row into a
     * single nested `parent` key (or null) — keeps consumers from
     * having to know about the underlying column names. Called once
     * per row in getSongbook / getSongbooks. Safe to call when the
     * schema isn't live: the parent fields are simply absent.
     *
     * @param array<string,mixed> $row Fetched row (mutated)
     * @return array<string,mixed>     The same row with `parent` added
     */
    private function _normaliseSongbookParent(array $row): array
    {
        $pid = $row['parentSongbookId'] ?? null;
        if ($pid !== null && (int)$pid > 0) {
            $row['parent'] = [
                'id'           => (int)$pid,
                'abbreviation' => (string)($row['parentAbbreviation'] ?? ''),
                'name'         => (string)($row['parentName']         ?? ''),
                'relationship' => (string)($row['parentRelationship'] ?? ''),
            ];
        } else {
            $row['parent'] = null;
        }
        /* Strip the flat columns now that we've nested them — keeps
           the public shape clean. */
        unset(
            $row['parentSongbookId'],
            $row['parentRelationship'],
            $row['parentAbbreviation'],
            $row['parentName']
        );
        return $row;
    }

    /**
     * Build the trailing fragment of the SELECT for songbook bibliographic
     * + authority-control identifier columns (#672). On a deployment that
     * hasn't run migrate-songbook-bibliographic.php yet the columns
     * aren't there and a SELECT that names them would 500 the songbooks
     * API. Probe INFORMATION_SCHEMA once per object instance, then return
     * either the full ", b.WebsiteUrl, b.OcnNumber, …" tail or an empty
     * string. Cached on the instance because getSongbooks() and
     * getSongbook() are commonly called in pairs on the same request.
     */
    private function _songbookBibSelect(): string
    {
        if (isset($this->_bibSelectCache)) {
            return $this->_bibSelectCache;
        }
        $hasBibCols = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbooks'
                    AND COLUMN_NAME  = 'WikidataId'
                  LIMIT 1"
            );
            $probe->execute();
            $hasBibCols = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* probe failure → fall through to empty tail */ }
        $this->_bibSelectCache = $hasBibCols
            ? ', WebsiteUrl AS websiteUrl, InternetArchiveUrl AS internetArchiveUrl,
               WikipediaUrl AS wikipediaUrl, WikidataId AS wikidataId,
               OclcNumber AS oclcNumber, OcnNumber AS ocnNumber,
               LcpNumber AS lcpNumber, Isbn AS isbn,
               ArkId AS arkId, IsniId AS isniId,
               ViafId AS viafId, Lccn AS lccn, LcClass AS lcClass'
            : '';
        return $this->_bibSelectCache;
    }
    private ?string $_bibSelectCache = null;

    /**
     * Same shape as _songbookBibSelect() but for the optional Language
     * column added in #673. Probe-once cache so getSongbooks() and
     * getSongbook() called in the same request only pay one
     * INFORMATION_SCHEMA round-trip between them.
     */
    private function _songbookLanguageSelect(): string
    {
        if (isset($this->_langSelectCache)) {
            return $this->_langSelectCache;
        }
        $hasLangCol = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbooks'
                    AND COLUMN_NAME  = 'Language'
                  LIMIT 1"
            );
            $probe->execute();
            $hasLangCol = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* probe failure → no Language tail */ }
        $this->_langSelectCache = $hasLangCol ? ', Language AS language' : '';
        return $this->_langSelectCache;
    }
    private ?string $_langSelectCache = null;

    /**
     * Same shape as _songbookBibSelect() / _songbookLanguageSelect()
     * but for the optional parent-songbook FK columns added in #782
     * phase A. When the schema is live, returns a SELECT tail with
     * `b.ParentSongbookId AS parentSongbookId,
     *  b.ParentRelationship AS parentRelationship,
     *  p.Abbreviation AS parentAbbreviation,
     *  p.Name AS parentName`
     * — assumes the caller's main table is aliased `b` and joins
     * `LEFT JOIN tblSongbooks p ON p.Id = b.ParentSongbookId`. The
     * join fragment is exposed as a separate accessor so callers can
     * inject it into the FROM clause.
     *
     * Probe-once cache (one INFORMATION_SCHEMA round-trip per request)
     * keeps getSongbook + getSongbooks cheap when both are called.
     */
    private function _songbookParentSelect(): string
    {
        if ($this->_parentSelectCache !== null) {
            return $this->_parentSelectCache;
        }
        $hasParentCol = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbooks'
                    AND COLUMN_NAME  = 'ParentSongbookId'
                  LIMIT 1"
            );
            $probe->execute();
            $hasParentCol = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* probe failure → no parent tail */ }
        $this->_parentSelectCache = $hasParentCol
            ? ', b.ParentSongbookId   AS parentSongbookId,
                 b.ParentRelationship AS parentRelationship,
                 p.Abbreviation       AS parentAbbreviation,
                 p.Name               AS parentName'
            : '';
        return $this->_parentSelectCache;
    }
    private ?string $_parentSelectCache = null;

    /** LEFT JOIN fragment paired with _songbookParentSelect(). Empty
        when the schema isn't live so the FROM clause stays valid. */
    private function _songbookParentJoin(): string
    {
        return $this->_songbookParentSelect() === ''
            ? ''
            : ' LEFT JOIN tblSongbooks p ON p.Id = b.ParentSongbookId';
    }

    /**
     * Pull `[abbr => [{id, name, slug}, ...]]` from the
     * tblSongbookSeries / tblSongbookSeriesMembership tables for a
     * subset (or all) of songbooks. Series counts in real catalogues
     * stay small — issuing one query per page-load (vs N queries per
     * songbook) keeps both /songbook/<abbr> and the home grid cheap.
     *
     * Schema-probed; pre-migration deployments get an empty map so
     * the caller's tile / page renders cleanly without the row.
     *
     * @param string[]|null $abbrs Limit to these abbreviations; null = all
     * @return array<string, array<int, array{id:int,name:string,slug:string}>>
     */
    private function _songbookSeriesMap(?array $abbrs = null): array
    {
        $hasSeriesSchema = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbookSeries'
                  LIMIT 1"
            );
            $probe->execute();
            $hasSeriesSchema = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* fall through */ }
        if (!$hasSeriesSchema) return [];

        try {
            if ($abbrs === null) {
                $sql = 'SELECT b.Abbreviation AS abbr,
                               s.Id           AS sid,
                               s.Name         AS sname,
                               s.Slug         AS sslug,
                               m.SortOrder    AS sortOrder
                          FROM tblSongbookSeriesMembership m
                          JOIN tblSongbookSeries s ON s.Id = m.SeriesId
                          JOIN tblSongbooks      b ON b.Id = m.SongbookId
                         ORDER BY b.Abbreviation, m.SortOrder ASC, s.Name ASC';
                $stmt = $this->db->prepare($sql);
            } else {
                $abbrs = array_values(array_filter(array_unique(array_map(
                    static fn($a) => strtoupper(trim((string)$a)),
                    $abbrs
                ))));
                if (!$abbrs) return [];
                $ph  = implode(',', array_fill(0, count($abbrs), '?'));
                $sql = "SELECT b.Abbreviation AS abbr,
                               s.Id           AS sid,
                               s.Name         AS sname,
                               s.Slug         AS sslug,
                               m.SortOrder    AS sortOrder
                          FROM tblSongbookSeriesMembership m
                          JOIN tblSongbookSeries s ON s.Id = m.SeriesId
                          JOIN tblSongbooks      b ON b.Id = m.SongbookId
                         WHERE b.Abbreviation IN ($ph)
                         ORDER BY b.Abbreviation, m.SortOrder ASC, s.Name ASC";
                $stmt  = $this->db->prepare($sql);
                $types = str_repeat('s', count($abbrs));
                $stmt->bind_param($types, ...$abbrs);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $abbr = (string)$row['abbr'];
                if (!isset($out[$abbr])) $out[$abbr] = [];
                $out[$abbr][] = [
                    'id'   => (int)$row['sid'],
                    'name' => (string)$row['sname'],
                    'slug' => (string)$row['sslug'],
                ];
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::_songbookSeriesMap] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Find a SongId by (songbook abbreviation, number) without
     * re-fetching the full song row. Used by the song page to
     * decide whether the parent songbook has a same-numbered
     * counterpart worth deep-linking to (#782 phase D). Returns
     * null when the songbook has no song at that number.
     *
     * Cheaper than getSongByNumber() — only a single SELECT against
     * the indexed (SongbookAbbr, Number) pair.
     */
    public function findSongIdByNumber(string $abbr, int $number): ?string
    {
        if ($number <= 0) return null;
        $abbr = strtoupper(trim($abbr));
        if ($abbr === '') return null;
        if ($this->jsonMode) {
            foreach ($this->jsonData['songs'] ?? [] as $song) {
                if (strtoupper((string)($song['songbook'] ?? '')) === $abbr
                    && (int)($song['number'] ?? 0) === $number
                ) {
                    return (string)$song['id'];
                }
            }
            return null;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT SongId FROM tblSongs WHERE SongbookAbbr = ? AND Number = ? LIMIT 1'
            );
            $stmt->bind_param('si', $abbr, $number);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ? (string)$row['SongId'] : null;
        } catch (\Throwable $e) {
            error_log('[SongData::findSongIdByNumber] ' . $e->getMessage());
            return null;
        }
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
        $bibSelect    = $this->_songbookBibSelect();
        $langSelect   = $this->_songbookLanguageSelect();
        $parentSelect = $this->_songbookParentSelect();
        $parentJoin   = $this->_songbookParentJoin();
        $stmt = $this->db->prepare(
            "SELECT b.Abbreviation AS id, b.Name AS name, b.SongCount AS songCount,
                    b.Colour AS colour,
                    b.IsOfficial      AS isOfficial,
                    b.Publisher       AS publisher,
                    b.PublicationYear AS publicationYear,
                    b.Copyright       AS copyright,
                    b.Affiliation     AS affiliation
                    {$langSelect}
                    {$bibSelect}
                    {$parentSelect}
             FROM tblSongbooks b{$parentJoin}
             WHERE b.Abbreviation = ?"
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
        $row = $this->_normaliseSongbookParent($row);
        /* #782 phase D — also attach series memberships. Single-songbook
           variant of the bulk fetch on getSongbooks(); pre-migration
           safe via the schema probe inside _songbookSeriesMap. */
        $seriesMap     = $this->_songbookSeriesMap([$id]);
        $row['series'] = $seriesMap[(string)$row['id']] ?? [];
        return $row;
    }

    /* =====================================================================
     * PARENT/SERIES PROGRAMMATIC HELPERS (#782 phase E)
     *
     * Public surface so other parts of the codebase (custom report
     * generators, future projection-software exporters, the analytics
     * module, etc.) can ask the questions the public song page + tile
     * already render answers to, without re-implementing the joins.
     * ===================================================================== */

    /**
     * Return the full hierarchical family of a songbook — its single
     * parent (or null), every direct child, and every sibling (other
     * children of the same parent, excluding the row itself). Hops are
     * bounded at 64 in each direction so a pathological cycle in the
     * data — already prevented by phase B's _wouldCreateParentCycle
     * guard — couldn't blow up the walk anyway.
     *
     * Empty `parent` + `children` + `siblings` arrays for songbooks that
     * have no relations declared. Pre-migration deployments (no
     * ParentSongbookId column) get the same empty shape.
     *
     * Result shape:
     *   [
     *     'self'     => ['id' => 'CIS', 'name' => 'Christ in Song'],
     *     'parent'   => null | ['id' => 'CIS', 'name' => '…',
     *                            'relationship' => 'translation'|'edition'|'abridgement'],
     *     'children' => [
     *        ['id' => 'HA', 'name' => 'Himnario Adventista',
     *         'relationship' => 'translation', 'language' => 'es'],
     *        …
     *     ],
     *     'siblings' => [ ...same shape as children... ],
     *   ]
     *
     * @param string $abbr Songbook abbreviation
     * @return array Family shape (always returns a populated array; missing rows ⇒ self => null)
     */
    public function getSongbookFamily(string $abbr): array
    {
        $abbr = strtoupper(trim($abbr));
        $empty = [
            'self'     => null,
            'parent'   => null,
            'children' => [],
            'siblings' => [],
        ];
        if ($abbr === '') return $empty;

        if ($this->jsonMode) {
            /* JSON-mode catalogues don't ship parent/series metadata
               (the JSON shape predates phase A) — return the trivial
               family. */
            $book = $this->getSongbook($abbr);
            return $book ? array_merge($empty, ['self' => ['id' => $book['id'], 'name' => $book['name']]]) : $empty;
        }

        if ($this->_songbookParentSelect() === '') return $empty;

        try {
            /* 1) self + own parent (if any). */
            $stmt = $this->db->prepare(
                'SELECT b.Id, b.Abbreviation, b.Name,
                        b.ParentSongbookId, b.ParentRelationship,
                        p.Abbreviation AS parentAbbr, p.Name AS parentName
                   FROM tblSongbooks b
                   LEFT JOIN tblSongbooks p ON p.Id = b.ParentSongbookId
                  WHERE b.Abbreviation = ?'
            );
            $stmt->bind_param('s', $abbr);
            $stmt->execute();
            $self = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$self) return $empty;

            $selfId = (int)$self['Id'];
            $out = [
                'self' => [
                    'id'   => (string)$self['Abbreviation'],
                    'name' => (string)$self['Name'],
                ],
                'parent'   => null,
                'children' => [],
                'siblings' => [],
            ];
            $parentId = isset($self['ParentSongbookId']) ? (int)$self['ParentSongbookId'] : 0;
            if ($parentId > 0) {
                $out['parent'] = [
                    'id'           => (string)($self['parentAbbr'] ?? ''),
                    'name'         => (string)($self['parentName'] ?? ''),
                    'relationship' => (string)($self['ParentRelationship'] ?? ''),
                ];
            }

            /* 2) Direct children (rows whose ParentSongbookId === selfId).
                  Pulled with the optional Language column so callers
                  rendering a list can show "Spanish" / "Tswana" inline. */
            $langTail = $this->_songbookLanguageSelect() === '' ? '' : ', b.Language AS language';
            $stmt = $this->db->prepare(
                "SELECT b.Abbreviation AS id, b.Name AS name,
                        b.ParentRelationship AS relationship
                        {$langTail}
                   FROM tblSongbooks b
                  WHERE b.ParentSongbookId = ?
                  ORDER BY b.Name ASC"
            );
            $stmt->bind_param('i', $selfId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $out['children'][] = [
                    'id'           => (string)$r['id'],
                    'name'         => (string)$r['name'],
                    'relationship' => (string)($r['relationship'] ?? ''),
                    'language'     => (string)($r['language']     ?? ''),
                ];
            }
            $stmt->close();

            /* 3) Siblings (other children of the same parent, excluding
                  self). Skipped when this row has no parent. */
            if ($parentId > 0) {
                $stmt = $this->db->prepare(
                    "SELECT b.Abbreviation AS id, b.Name AS name,
                            b.ParentRelationship AS relationship
                            {$langTail}
                       FROM tblSongbooks b
                      WHERE b.ParentSongbookId = ?
                        AND b.Id <> ?
                      ORDER BY b.Name ASC"
                );
                $stmt->bind_param('ii', $parentId, $selfId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $out['siblings'][] = [
                        'id'           => (string)$r['id'],
                        'name'         => (string)$r['name'],
                        'relationship' => (string)($r['relationship'] ?? ''),
                        'language'     => (string)($r['language']     ?? ''),
                    ];
                }
                $stmt->close();
            }

            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::getSongbookFamily] ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Return every songbook in a series, ordered by membership SortOrder
     * then Name. Looked up by either the series id (int) or its slug
     * (string). Empty list when the series doesn't exist or the schema
     * isn't live yet.
     *
     * Result shape per row:
     *   ['id' => 'SoF1', 'name' => 'Songs of Fellowship vol 1',
     *    'sortOrder' => 10, 'note' => 'first volume',
     *    'language' => 'en']  // language only when the column is live
     *
     * @param int|string $seriesIdOrSlug
     * @return array<int, array<string, int|string>>
     */
    public function getSongbooksInSeries($seriesIdOrSlug): array
    {
        if ($this->jsonMode) return []; /* JSON catalogues don't carry series */

        /* Schema probe — same gate as _songbookSeriesMap(). */
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbookSeries'
                  LIMIT 1"
            );
            $probe->execute();
            $present = $probe->get_result()->fetch_row() !== null;
            $probe->close();
            if (!$present) return [];
        } catch (\Throwable $_e) {
            return [];
        }

        $langTail = $this->_songbookLanguageSelect() === '' ? '' : ', b.Language AS language';
        try {
            if (is_int($seriesIdOrSlug)) {
                $sql = "SELECT b.Abbreviation AS id, b.Name AS name,
                               m.SortOrder    AS sortOrder,
                               m.Note         AS note
                               {$langTail}
                          FROM tblSongbookSeriesMembership m
                          JOIN tblSongbooks b ON b.Id = m.SongbookId
                         WHERE m.SeriesId = ?
                         ORDER BY m.SortOrder ASC, b.Name ASC";
                $stmt = $this->db->prepare($sql);
                $sid  = (int)$seriesIdOrSlug;
                $stmt->bind_param('i', $sid);
            } else {
                $sql = "SELECT b.Abbreviation AS id, b.Name AS name,
                               m.SortOrder    AS sortOrder,
                               m.Note         AS note
                               {$langTail}
                          FROM tblSongbookSeriesMembership m
                          JOIN tblSongbooks       b ON b.Id = m.SongbookId
                          JOIN tblSongbookSeries  s ON s.Id = m.SeriesId
                         WHERE s.Slug = ?
                         ORDER BY m.SortOrder ASC, b.Name ASC";
                $stmt = $this->db->prepare($sql);
                $slug = (string)$seriesIdOrSlug;
                $stmt->bind_param('s', $slug);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while ($r = $res->fetch_assoc()) {
                $row = [
                    'id'        => (string)$r['id'],
                    'name'      => (string)$r['name'],
                    'sortOrder' => (int)$r['sortOrder'],
                    'note'      => (string)($r['note'] ?? ''),
                ];
                if (array_key_exists('language', $r)) {
                    $row['language'] = (string)($r['language'] ?? '');
                }
                $out[] = $row;
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::getSongbooksInSeries] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Given a SongId (e.g. 'HA-0042'), return the same hymn-number's
     * row in every related songbook — parent + every child of the
     * parent (i.e. every sibling translation/edition/abridgement) —
     * keyed by relationship for easy rendering.
     *
     * Result shape:
     *   [
     *     'parent'   => ['id' => 'CIS-0042', 'songbook' => 'CIS',
     *                    'name' => 'Christ in Song', 'language' => 'en',
     *                    'relationship' => 'translation'],
     *     'siblings' => [
     *        ['id' => 'KMK-0042', 'songbook' => 'KMK', 'name' => 'Keresete Mo Kopelong',
     *         'language' => 'tn', 'relationship' => 'translation'],
     *        …
     *     ],
     *   ]
     *
     * Empty when:
     *   - the song's number is null (Misc / unnumbered),
     *   - the songbook has no parent,
     *   - no related songbook carries the same number.
     *
     * Cheap: one INFORMATION_SCHEMA probe (cached), one row fetch,
     * one family walk, one IN(…) query for the same-number row in
     * each related songbook. ~3 queries total.
     */
    public function getSongCounterparts(string $songId): array
    {
        $empty = ['parent' => null, 'siblings' => []];
        $songId = trim($songId);
        if ($songId === '') return $empty;
        if ($this->jsonMode) return $empty;
        if ($this->_songbookParentSelect() === '') return $empty;

        try {
            /* Step 1 — pull the source song's (SongbookAbbr, Number).
               Cheap, no joins. */
            $stmt = $this->db->prepare(
                'SELECT SongbookAbbr, Number FROM tblSongs WHERE SongId = ? LIMIT 1'
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return $empty;

            $abbr   = (string)$row['SongbookAbbr'];
            $number = $row['Number'] !== null ? (int)$row['Number'] : 0;
            if ($number <= 0) return $empty;

            /* Step 2 — walk the family. Re-uses the helper above so the
               relationship-aware language tail comes for free. */
            $family = $this->getSongbookFamily($abbr);

            /* Step 3 — assemble the candidate-row list of related songbook
               abbreviations (parent + siblings). Children of the current
               songbook aren't included — counterpart semantics here are
               "this hymn elsewhere in the same family", and a child of
               the current row would be a translation OF this row, which
               is the inward-translations relationship already covered
               by tblSongTranslations elsewhere on the song page (#281). */
            $candidates = [];
            $relationshipByAbbr = [];
            if ($family['parent']) {
                $candidates[] = $family['parent']['id'];
                $relationshipByAbbr[$family['parent']['id']] = $family['parent']['relationship'];
            }
            foreach ($family['siblings'] as $s) {
                $candidates[] = $s['id'];
                $relationshipByAbbr[$s['id']] = $s['relationship'];
            }
            if (!$candidates) return $empty;

            $ph   = implode(',', array_fill(0, count($candidates), '?'));
            $sql  = "SELECT s.SongId, s.SongbookAbbr, b.Name AS bookName,
                            b.Language AS bookLanguage
                       FROM tblSongs s
                       JOIN tblSongbooks b ON b.Abbreviation = s.SongbookAbbr
                      WHERE s.Number = ? AND s.SongbookAbbr IN ($ph)";
            $stmt = $this->db->prepare($sql);
            $types = 'i' . str_repeat('s', count($candidates));
            $args  = array_merge([$number], $candidates);
            $stmt->bind_param($types, ...$args);
            $stmt->execute();
            $res = $stmt->get_result();
            $out = $empty;
            while ($r = $res->fetch_assoc()) {
                $entry = [
                    'id'           => (string)$r['SongId'],
                    'songbook'     => (string)$r['SongbookAbbr'],
                    'name'         => (string)$r['bookName'],
                    'language'     => (string)($r['bookLanguage'] ?? ''),
                    'relationship' => (string)($relationshipByAbbr[$r['SongbookAbbr']] ?? ''),
                ];
                if ($family['parent'] && $r['SongbookAbbr'] === $family['parent']['id']) {
                    $out['parent'] = $entry;
                } else {
                    $out['siblings'][] = $entry;
                }
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::getSongCounterparts] ' . $e->getMessage());
            return $empty;
        }
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

        /* #718 — Non-official songbooks (and any songbook where every
           song's Number is NULL) sort their songs alphabetically by
           Title. Officially-published hymnals with numbered hymns
           keep the by-Number sort.

           SQL evaluates the branch per-row via a JOIN to
           tblSongbooks.IsOfficial:
             - IsOfficial = 1 AND Number IS NOT NULL → numbered (rank 0)
             - otherwise                             → alphabetical (rank 1)

           Within each songbook (clustered by SongbookAbbr), numbered
           rows come first (Number ASC), then any un-numbered entries
           in alphabetical order. Non-official songbooks therefore
           render as a flat alphabetical list because every row gets
           rank 1 + uses the title key.

           LOWER(s.Title) suffices as the alphabetical key — the
           leading-article strip from #717 / #674 is desktop-only
           (JS) for the songbook list; doing it in SQL would require
           REGEXP_REPLACE which is MySQL 8.0+ only and the project
           supports 5.7+. Acceptable degradation: "The Solid Rock"
           sorts under T in the un-numbered tail. Future enhancement:
           add a generated column TitleSortKey on tblSongs that
           strips the article at write-time. */
        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title, s.SongbookAbbr AS songbook,
                       s.SongbookName AS songbookName, s.Language AS language, s.Copyright AS copyright,
                       s.TuneName AS tuneName, s.Ccli AS ccli, s.Iswc AS iswc,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                FROM tblSongs s
                LEFT JOIN tblSongbooks b ON b.Abbreviation = s.SongbookAbbr
                {$whereClause}
                ORDER BY s.SongbookAbbr ASC,
                         CASE
                            WHEN b.IsOfficial = 1 AND s.Number IS NOT NULL THEN 0
                            ELSE 1
                         END ASC,
                         s.Number ASC,
                         LOWER(s.Title) ASC";

        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $songs = [];
        while ($row = $result->fetch_assoc()) {
            $row['number'] = normaliseSongNumber($row['number']);
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
            $artistsMap     = $this->_getArtistsMap($songIds);     /* #587 */
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
                $song['artists']     = $artistsMap[$sid]     ?? [];   /* #587 */
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
            $row['number'] = normaliseSongNumber($row['number']);
            $row['verified'] = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
            $row['hasAudio'] = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $results[] = $row;
        }
        $stmt->close();

        /* Bulk-attach writers / composers / components — was 3 queries
           per matched row, now one per side table (#533). */
        $this->_attachSearchResultCredits($results);

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
            $row['number'] = normaliseSongNumber($row['number']);
            $row['verified'] = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
            $row['hasAudio'] = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $songs[] = $row;
        }
        $stmt->close();

        $this->_attachSearchResultCredits($songs); /* #533 */

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

        $row['number'] = normaliseSongNumber($row['number']);
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
        $row['artists']      = $this->_getArtists($songId);    /* #587 */
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
     * Attach writers/composers/components in bulk to a list of song
     * rows, replacing the per-row N+1 calls that searchSongs(),
     * searchByNumber() and _searchByWriterComposer() previously made
     * (#533). Mirrors the bulk-loader pattern already used by
     * getSongs() — one query per side table instead of three per
     * matched row.
     *
     * Stays minimal (writers / composers / components only) to
     * preserve the current shape returned by these search methods.
     * Single-song reads via _fetchSongRow() still attach the full
     * credit shape (arrangers / adaptors / translators / tags).
     *
     * @param array<int,array> $songs Reference — each row gains
     *                                writers / composers / components keys.
     */
    private function _attachSearchResultCredits(array &$songs): void
    {
        if (empty($songs)) return;
        $songIds = array_column($songs, 'id');
        if (empty($songIds)) return;

        $writersMap    = $this->_getWritersMap($songIds);
        $composersMap  = $this->_getComposersMap($songIds);
        $componentsMap = $this->_getComponentsMap($songIds);

        foreach ($songs as &$song) {
            $sid = $song['id'];
            $song['writers']    = $writersMap[$sid]    ?? [];
            $song['composers']  = $composersMap[$sid]  ?? [];
            $song['components'] = $componentsMap[$sid] ?? [];
        }
        unset($song);
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
     * @return string[]
     * Artists (#587). Returns an empty array on installs where the
     * tblSongArtists table hasn't been created yet, so the load path
     * stays usable on a partly-migrated DB.
     */
    private function _getArtists(string $songId): array
    {
        if (!$this->_songArtistsTableExists()) return [];
        $stmt = $this->db->prepare(
            "SELECT Name FROM tblSongArtists WHERE SongId = ? ORDER BY SortOrder, Id"
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
     * Bulk-load artists keyed by SongId (#587). See `_getWritersMap()`.
     *
     * @param string[] $songIds
     * @return array<string,string[]>
     */
    private function _getArtistsMap(array $songIds): array
    {
        if (empty($songIds))                     return [];
        if (!$this->_songArtistsTableExists())   return [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT SongId, Name FROM tblSongArtists
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, SortOrder, Id"
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
     * Cached check for the tblSongArtists table (#587). The table
     * arrives via migrate-song-artists.php — until that's been
     * applied, every credit-load helper that touches it must no-op.
     * INFORMATION_SCHEMA is queried once per request.
     */
    private ?bool $_songArtistsTableExistsCached = null;
    private function _songArtistsTableExists(): bool
    {
        if ($this->_songArtistsTableExistsCached !== null) {
            return $this->_songArtistsTableExistsCached;
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongArtists' LIMIT 1"
        );
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        $this->_songArtistsTableExistsCached = $exists;
        return $exists;
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
            $row['number'] = normaliseSongNumber($row['number']);
            $row['verified'] = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
            $row['hasAudio'] = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $songs[] = $row;
        }
        $stmt->close();

        $this->_attachSearchResultCredits($songs); /* #533 */

        return $songs;
    }
}
