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

    /** #858 — schema-probe result for tblSongComponents.Language. */
    private bool $_componentLangColumn = false;
    private bool $_componentLangColumnChecked = false;
    private bool $_componentChordsColumn = false;
    private bool $_componentChordsColumnChecked = false;
    /** #1235 P2a — schema-probe result for the tblLyricLines mirror (read source). */
    private bool $_lyricLinesMirror = false;
    private bool $_lyricLinesMirrorChecked = false;

    /** #892 — schema-probe result for tblSongs.ArrangementJson. */
    private bool $_arrangementColumn = false;
    private bool $_arrangementColumnChecked = false;
    /* Places adoption — single-flight probe for tblSongs.OriginCityId.
       Mirrors the ArrangementJson pattern so a pre-adoption install
       keeps the legacy SELECT shape (no extra columns) without a
       repeat INFORMATION_SCHEMA round-trip per request. */
    private bool $_originPlaceColumn = false;
    private bool $_originPlaceColumnChecked = false;

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
     * Constructor — connects to live MySQL.
     *
     * WS-J #1020: DB-direct only. MySQL is the single source of truth. The
     * old JSON-file fallback — which served a STALE songs.json corpus when the
     * live DB was unreachable — was removed per the governing rule "server
     * DB-down = graceful error, never stale". getDbMysqli() throws when MySQL
     * is unavailable and we let that propagate; callers surface a clean error
     * and WS-K's maintenance mode provides the user-facing UX.
     *
     * The $jsonMode / $jsonData members and their guards remain in the read
     * methods as inert dead code (jsonMode can never become true now); a
     * follow-up can strip the branches purely for tidiness.
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
        if ($this->jsonMode) {
            return $this->jsonData['meta'] ?? [];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM tblSongs");
        $stmt->execute();
        $result = $stmt->get_result();
        $totalSongs = (int)$result->fetch_assoc()['total'];
        $stmt->close();

        /* #963 — only count songbooks that have at least one song.
           Empty placeholder rows in tblSongbooks would otherwise
           inflate the home-page "N Songbooks" badge and the PWA
           cache's meta.totalSongbooks. */
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total
               FROM tblSongbooks b
              WHERE EXISTS (
                  SELECT 1 FROM tblSongs s
                   WHERE s.SongbookAbbr = b.Abbreviation
              )"
        );
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
    /**
     * Other-language versions of a song (#281) — the translation cluster: songs
     * THIS one translates to (outward), the source it translates FROM (inward),
     * and that source's other translations (siblings). Used by the song page's
     * language picker AND the song page's hreflang alternates (#1206). Wrapped so
     * a missing table / DB hiccup yields an empty list, never a fatal.
     *
     * @return array<int, array<string, mixed>> rows: song_id, target_language,
     *         language_name, native_name, text_direction, translator, verified
     */
    public function getSongTranslations(string $songId): array
    {
        if ($songId === '' || $this->jsonMode || !($this->db instanceof \mysqli)) {
            return [];
        }
        try {
            $sql = '
                /* Outward — this song has translations to other languages */
                SELECT t.TranslatedSongId AS song_id, t.TargetLanguage AS target_language,
                       l.Name AS language_name, l.NativeName AS native_name,
                       l.TextDirection AS text_direction, t.Translator AS translator, t.Verified AS verified
                  FROM tblSongTranslations t
                  JOIN tblLanguages l ON l.Code = t.TargetLanguage
                 WHERE t.SourceSongId = ? AND l.IsActive = 1
                UNION
                /* Inward — this song IS a translation; surface the source. */
                SELECT src.SongId AS song_id, srcLang.Code AS target_language,
                       srcLang.Name AS language_name, srcLang.NativeName AS native_name,
                       srcLang.TextDirection AS text_direction, "" AS translator, 1 AS verified
                  FROM tblSongTranslations selfT
                  JOIN tblSongs src ON src.SongId = selfT.SourceSongId
                  JOIN tblLanguages srcLang ON srcLang.Code = src.Language
                 WHERE selfT.TranslatedSongId = ? AND srcLang.IsActive = 1
                UNION
                /* Siblings — the source\'s OTHER translations. */
                SELECT sibling.TranslatedSongId AS song_id, sibling.TargetLanguage AS target_language,
                       l2.Name AS language_name, l2.NativeName AS native_name,
                       l2.TextDirection AS text_direction, sibling.Translator AS translator, sibling.Verified AS verified
                  FROM tblSongTranslations selfT2
                  JOIN tblSongTranslations sibling
                       ON sibling.SourceSongId = selfT2.SourceSongId
                      AND sibling.TranslatedSongId <> selfT2.TranslatedSongId
                  JOIN tblLanguages l2 ON l2.Code = sibling.TargetLanguage
                 WHERE selfT2.TranslatedSongId = ? AND l2.IsActive = 1
            ';
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('sss', $songId, $songId, $songId);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
            return $rows;
        } catch (\Throwable $_e) {
            return [];   // missing table / DB hiccup → no alternates, never fatal
        }
    }

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

        /* Attach series memberships, compilers, alt names, external
           links and contained-language sets in batch queries (#782
           phase D, #831, #832, #833, #857) so the home / browse
           grids and songbook pages render without N+1 queries. */
        if ($books) {
            $seriesMap        = $this->_songbookSeriesMap(null);
            $compilersMap     = $this->_songbookCompilersMap(null);
            $altNamesMap      = $this->_songbookAltNamesMap(null);
            $linksMap         = $this->_externalLinksMap('songbook', null);
            $songLanguagesMap = $this->_songbookSongLanguagesMap();
            foreach ($books as &$_b) {
                $_b['series']           = $seriesMap[(string)$_b['id']]    ?? [];
                $_b['compilers']        = $compilersMap[(string)$_b['id']] ?? [];
                $_b['alternativeNames'] = $altNamesMap[(string)$_b['id']]  ?? [];
                $_b['links']            = $linksMap[(string)$_b['id']]     ?? [];

                /* #1181 — effective badge colour: own → first member series
                   that defines a colour → theme default. Lets a series share
                   ONE colour across all its songbooks (mirrors getSongbook()). */
                if (($_b['colour'] ?? '') === '') {
                    foreach ($_b['series'] as $_ser) {
                        if (!empty($_ser['colour'])) { $_b['colour'] = $_ser['colour']; break; }
                    }
                }

                /* #857 — union of: (a) the songbook's own primary
                   subtag, and (b) every distinct primary subtag
                   carried by songs within it. Drives the "Show
                   languages" filter visibility and the badge
                   tooltip. Returns an empty array on pre-#673
                   deploys; the existing single-language behaviour
                   then takes over via the legacy `language` field. */
                $contained = $songLanguagesMap[(string)$_b['id']] ?? [];
                $own       = '';
                if (!empty($_b['language']) && preg_match('/^([a-z]{2,3})/i', (string)$_b['language'], $m)) {
                    $own = strtolower($m[1]);
                }
                $merged = $own !== '' ? array_merge([$own], $contained) : $contained;
                $merged = array_values(array_unique($merged));
                sort($merged);
                $_b['languages'] = $merged;
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
        /* Every column is `b.`-qualified so the SELECT compiles when the
           parent-songbook self-join (LEFT JOIN tblSongbooks p ON …, see
           _songbookParentJoin) is also active. Without the alias prefix
           every name in this fragment is ambiguous because both `b` and
           `p` are aliases for tblSongbooks — produced "Column 'X' in
           field list is ambiguous" 500s on every getSongbooks() call once
           both #672 (bib) and #782 (parent) had been applied. */
        $this->_bibSelectCache = $hasBibCols
            ? ', b.WebsiteUrl AS websiteUrl, b.InternetArchiveUrl AS internetArchiveUrl,
               b.WikipediaUrl AS wikipediaUrl, b.WikidataId AS wikidataId,
               b.OclcNumber AS oclcNumber, b.OcnNumber AS ocnNumber,
               b.LcpNumber AS lcpNumber, b.Isbn AS isbn,
               b.ArkId AS arkId, b.IsniId AS isniId,
               b.ViafId AS viafId, b.Lccn AS lccn, b.LcClass AS lcClass'
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
        /* `b.`-qualified — same reason as _songbookBibSelect() above; the
           parent-songbook self-join makes any unqualified column
           ambiguous. */
        $this->_langSelectCache = $hasLangCol ? ', b.Language AS language' : '';
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
                               s.Colour       AS scolour,
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
                               s.Colour       AS scolour,
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
                    'id'     => (int)$row['sid'],
                    'name'   => (string)$row['sname'],
                    'slug'   => (string)$row['sslug'],
                    'colour' => (string)($row['scolour'] ?? ''),  // #1181 — series badge colour
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
     * Pull `[abbr => ['en','af',…]]` — for each songbook, the set of
     * distinct primary language subtags that appear on its songs (#857).
     *
     * Used to fix songbook-tile visibility under the "Show languages"
     * filter: a songbook tagged English (e.g. Advent Hymns) which
     * happens to contain Afrikaans-tagged songs should still surface
     * when the user filters for Afrikaans. The home page combines
     * this with `tblSongbooks.Language` to produce the union list,
     * stored on the tile as `data-songbook-languages`.
     *
     * Schema-probed: pre-#673 (no Language column on tblSongs)
     * returns an empty map and the legacy single-language filter
     * behaviour stays in effect.
     *
     * @return array<string, string[]>
     */
    private function _songbookSongLanguagesMap(): array
    {
        $hasSchema = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongs'
                    AND COLUMN_NAME  = 'Language' LIMIT 1"
            );
            $probe->execute();
            $hasSchema = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* fall through */ }
        if (!$hasSchema) return [];

        try {
            /* GROUP_CONCAT keeps everything in one round-trip; the
               primary-subtag extraction lives in SQL because the
               sub-string is cheap there and avoids a per-row
               PHP regex on a result set that can be tens of
               thousands of rows. */
            $sql = "SELECT SongbookAbbr,
                           GROUP_CONCAT(
                               DISTINCT LOWER(SUBSTRING_INDEX(Language, '-', 1))
                               ORDER BY Language SEPARATOR ','
                           ) AS langs
                      FROM tblSongs
                     WHERE Language IS NOT NULL AND Language <> ''
                     GROUP BY SongbookAbbr";
            $res = $this->db->query($sql);
            $out = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $abbr  = (string)$row['SongbookAbbr'];
                    $langs = array_values(array_filter(
                        explode(',', (string)($row['langs'] ?? '')),
                        static fn($s) => $s !== '' && preg_match('/^[a-z]{2,3}$/', $s)
                    ));
                    $out[$abbr] = $langs;
                }
                $res->close();
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::_songbookSongLanguagesMap] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pull `[abbr => [{id, name, slug, note}, ...]]` from
     * tblSongbookCompilers joined to tblCreditPeople. Same shape +
     * caching strategy as _songbookSeriesMap(): single query covers
     * the home grid + every songbook page on a single request.
     *
     * Schema-probed (#831). Pre-migration deployments get an empty
     * map so /songbook/<abbr> renders cleanly without the
     * "Compiled by …" line.
     *
     * @param string[]|null $abbrs Limit to these abbreviations; null = all
     * @return array<string, array<int, array{id:int,name:string,slug:string,note:string}>>
     */
    private function _songbookCompilersMap(?array $abbrs = null): array
    {
        $hasCompilersSchema = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbookCompilers'
                  LIMIT 1"
            );
            $probe->execute();
            $hasCompilersSchema = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* fall through */ }
        if (!$hasCompilersSchema) return [];

        try {
            if ($abbrs === null) {
                $sql = 'SELECT b.Abbreviation AS abbr,
                               p.Id           AS pid,
                               p.Name         AS pname,
                               p.Slug         AS pslug,
                               c.Note         AS note,
                               c.SortOrder    AS sortOrder
                          FROM tblSongbookCompilers c
                          JOIN tblCreditPeople p ON p.Id = c.CreditPersonId
                          JOIN tblSongbooks    b ON b.Id = c.SongbookId
                         ORDER BY b.Abbreviation, c.SortOrder ASC, p.Name ASC';
                $stmt = $this->db->prepare($sql);
            } else {
                $abbrs = array_values(array_filter(array_unique(array_map(
                    static fn($a) => strtoupper(trim((string)$a)),
                    $abbrs
                ))));
                if (!$abbrs) return [];
                $ph  = implode(',', array_fill(0, count($abbrs), '?'));
                $sql = "SELECT b.Abbreviation AS abbr,
                               p.Id           AS pid,
                               p.Name         AS pname,
                               p.Slug         AS pslug,
                               c.Note         AS note,
                               c.SortOrder    AS sortOrder
                          FROM tblSongbookCompilers c
                          JOIN tblCreditPeople p ON p.Id = c.CreditPersonId
                          JOIN tblSongbooks    b ON b.Id = c.SongbookId
                         WHERE b.Abbreviation IN ($ph)
                         ORDER BY b.Abbreviation, c.SortOrder ASC, p.Name ASC";
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
                    'id'   => (int)$row['pid'],
                    'name' => (string)$row['pname'],
                    'slug' => (string)($row['pslug'] ?? ''),
                    'note' => (string)($row['note'] ?? ''),
                ];
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::_songbookCompilersMap] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pull `[abbr => [{title, note}, ...]]` from
     * tblSongbookAlternativeTitles. Schema-probed (#832).
     * Pre-migration deployments get an empty map so the public
     * "Also known as …" line and JSON-LD alternateName both
     * gracefully no-op.
     *
     * @param string[]|null $abbrs Limit to these abbreviations; null = all
     * @return array<string, array<int, array{title:string,note:string}>>
     */
    private function _songbookAltNamesMap(?array $abbrs = null): array
    {
        $hasSchema = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbookAlternativeTitles'
                  LIMIT 1"
            );
            $probe->execute();
            $hasSchema = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* fall through */ }
        if (!$hasSchema) return [];

        try {
            if ($abbrs === null) {
                $sql = 'SELECT b.Abbreviation AS abbr,
                               a.Title         AS title,
                               a.Note          AS note,
                               a.SortOrder     AS sortOrder
                          FROM tblSongbookAlternativeTitles a
                          JOIN tblSongbooks b ON b.Id = a.SongbookId
                         ORDER BY b.Abbreviation, a.SortOrder ASC, a.Title ASC';
                $stmt = $this->db->prepare($sql);
            } else {
                $abbrs = array_values(array_filter(array_unique(array_map(
                    static fn($a) => strtoupper(trim((string)$a)),
                    $abbrs
                ))));
                if (!$abbrs) return [];
                $ph  = implode(',', array_fill(0, count($abbrs), '?'));
                $sql = "SELECT b.Abbreviation AS abbr,
                               a.Title         AS title,
                               a.Note          AS note,
                               a.SortOrder     AS sortOrder
                          FROM tblSongbookAlternativeTitles a
                          JOIN tblSongbooks b ON b.Id = a.SongbookId
                         WHERE b.Abbreviation IN ($ph)
                         ORDER BY b.Abbreviation, a.SortOrder ASC, a.Title ASC";
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
                    'title' => (string)$row['title'],
                    'note'  => (string)($row['note'] ?? ''),
                ];
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::_songbookAltNamesMap] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pull `[songId => [{title, note, language}, ...]]` from
     * tblSongAlternativeTitles. Schema-probed (#832).
     *
     * @param string[]|null $songIds Limit to these SongIds; null = all
     * @return array<string, array<int, array{title:string,note:string,language:string}>>
     */
    private function _songAltTitlesMap(?array $songIds = null): array
    {
        $hasSchema = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongAlternativeTitles'
                  LIMIT 1"
            );
            $probe->execute();
            $hasSchema = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* fall through */ }
        if (!$hasSchema) return [];

        try {
            if ($songIds === null) {
                $sql = 'SELECT SongId, Title, Note, Language, SortOrder
                          FROM tblSongAlternativeTitles
                         ORDER BY SongId, SortOrder ASC, Title ASC';
                $stmt = $this->db->prepare($sql);
            } else {
                $songIds = array_values(array_filter(array_unique(array_map(
                    static fn($s) => trim((string)$s),
                    $songIds
                ))));
                if (!$songIds) return [];
                $ph  = implode(',', array_fill(0, count($songIds), '?'));
                $sql = "SELECT SongId, Title, Note, Language, SortOrder
                          FROM tblSongAlternativeTitles
                         WHERE SongId IN ($ph)
                         ORDER BY SongId, SortOrder ASC, Title ASC";
                $stmt  = $this->db->prepare($sql);
                $types = str_repeat('s', count($songIds));
                $stmt->bind_param($types, ...$songIds);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $sid = (string)$row['SongId'];
                if (!isset($out[$sid])) $out[$sid] = [];
                $out[$sid][] = [
                    'title'    => (string)$row['Title'],
                    'note'     => (string)($row['Note'] ?? ''),
                    'language' => (string)($row['Language'] ?? ''),
                ];
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::_songAltTitlesMap] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pull `[songId => [{id, kind, fileName, mimeType, sizeBytes,
     *                    annotation, sortOrder, streamUrl}, …]]` from
     * tblSongMedia (#853). Schema-probed; pre-migration deployments
     * get an empty map so the public song page just doesn't render
     * the media block.
     *
     * Bytes are NEVER returned by this method — only metadata. The
     * public surface uses streamUrl (= /song-media/<id>) which is
     * served by the gated route (phase E) so checkContentAccess()
     * applies regardless of whether the underlying storage is the
     * filesystem or the database.
     *
     * @param string[]|null $songIds Limit to these SongIds; null = all
     * @return array<string, array<int, array{id:int,kind:string,fileName:string,mimeType:string,sizeBytes:int,annotation:string,sortOrder:int,streamUrl:string}>>
     */
    private function _songMediaMap(?array $songIds = null): array
    {
        $hasSchema = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongMedia'
                  LIMIT 1"
            );
            $probe->execute();
            $hasSchema = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* fall through */ }
        if (!$hasSchema) return [];

        try {
            $select = 'SELECT Id, SongId, Kind, FileName, MimeType, SizeBytes,
                              Annotation, SortOrder
                         FROM tblSongMedia';
            if ($songIds === null) {
                $sql  = $select . ' ORDER BY SongId, Kind ASC, SortOrder ASC, Id ASC';
                $stmt = $this->db->prepare($sql);
            } else {
                $songIds = array_values(array_filter(array_unique(array_map(
                    static fn($s) => trim((string)$s),
                    $songIds
                ))));
                if (!$songIds) return [];
                $ph   = implode(',', array_fill(0, count($songIds), '?'));
                $sql  = $select . " WHERE SongId IN ($ph)"
                      . ' ORDER BY SongId, Kind ASC, SortOrder ASC, Id ASC';
                $stmt = $this->db->prepare($sql);
                $types = str_repeat('s', count($songIds));
                $stmt->bind_param($types, ...$songIds);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $sid = (string)$row['SongId'];
                if (!isset($out[$sid])) $out[$sid] = [];
                $out[$sid][] = [
                    'id'         => (int)$row['Id'],
                    'kind'       => (string)$row['Kind'],
                    'fileName'   => (string)$row['FileName'],
                    'mimeType'   => (string)$row['MimeType'],
                    'sizeBytes'  => (int)$row['SizeBytes'],
                    'annotation' => (string)($row['Annotation'] ?? ''),
                    'sortOrder'  => (int)$row['SortOrder'],
                    'streamUrl'  => '/song-media/' . (int)$row['Id'],
                ];
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[SongData::_songMediaMap] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pull `[entityKey => [{slug, name, category, url, note, verified, iconClass}, ...]]`
     * from one of the three tblXxxExternalLinks join tables (#833).
     * Generic over the three entity types — `$entityType` selects the
     * table + key-column combination:
     *
     *   'songbook' → tblSongbookExternalLinks      keyed by Abbreviation
     *   'song'     → tblSongExternalLinks          keyed by SongId
     *   'person'   → tblCreditPersonExternalLinks  keyed by CreditPersonId (int)
     *
     * Schema-probed; pre-migration deployments get an empty map. The
     * registry join (tblExternalLinkTypes) drops link-type rows that
     * have been deactivated — IsActive = 0 acts as a soft delete.
     *
     * @param string         $entityType  'songbook' | 'song' | 'person'
     * @param array|null     $keys        Limit to these keys; null = all
     * @return array<string|int, array<int, array{slug:string,name:string,category:string,url:string,note:string,verified:bool,iconClass:string,sortOrder:int}>>
     */
    private function _externalLinksMap(string $entityType, ?array $keys = null): array
    {
        switch ($entityType) {
            case 'songbook':
                $table   = 'tblSongbookExternalLinks';
                $entCol  = 'SongbookId';
                $joinSql = ' JOIN tblSongbooks b ON b.Id = el.SongbookId ';
                $keyExpr = 'b.Abbreviation';
                $bindT   = 's';
                break;
            case 'song':
                $table   = 'tblSongExternalLinks';
                $entCol  = 'SongId';
                $joinSql = '';
                $keyExpr = 'el.SongId';
                $bindT   = 's';
                break;
            case 'person':
                $table   = 'tblCreditPersonExternalLinks';
                $entCol  = 'CreditPersonId';
                $joinSql = '';
                $keyExpr = 'el.CreditPersonId';
                $bindT   = 'i';
                break;
            default:
                return [];
        }

        $hasSchema = false;
        try {
            $probe = $this->db->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $probe->bind_param('s', $table);
            $probe->execute();
            $hasSchema = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* fall through */ }
        if (!$hasSchema) return [];

        try {
            $base = "SELECT {$keyExpr} AS k,
                            t.Slug      AS slug,
                            t.Name      AS name,
                            t.Category  AS category,
                            t.IconClass AS iconClass,
                            el.Url      AS url,
                            el.Note     AS note,
                            el.Verified AS verified,
                            el.SortOrder AS sortOrder
                       FROM {$table} el
                       JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                       {$joinSql}
                      WHERE COALESCE(t.IsActive, 1) = 1";
            $orderBy = " ORDER BY {$keyExpr}, t.Category, el.SortOrder ASC, t.DisplayOrder ASC, t.Name ASC";

            if ($keys === null) {
                $stmt = $this->db->prepare($base . $orderBy);
            } else {
                $clean = array_values(array_filter(array_unique(array_map(
                    static fn($k) => is_int($k) ? $k : trim((string)$k),
                    $keys
                ))));
                if ($entityType === 'songbook') {
                    $clean = array_map(static fn($s) => strtoupper((string)$s), $clean);
                }
                if (!$clean) return [];
                $ph    = implode(',', array_fill(0, count($clean), '?'));
                $sql   = $base . " AND {$keyExpr} IN ($ph)" . $orderBy;
                $stmt  = $this->db->prepare($sql);
                $types = str_repeat($bindT, count($clean));
                $stmt->bind_param($types, ...$clean);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $key = $entityType === 'person' ? (int)$row['k'] : (string)$row['k'];
                if (!isset($out[$key])) $out[$key] = [];
                $out[$key][] = [
                    'slug'      => (string)$row['slug'],
                    'name'      => (string)$row['name'],
                    'category'  => (string)$row['category'],
                    'iconClass' => (string)($row['iconClass'] ?? ''),
                    'url'       => (string)$row['url'],
                    'note'      => (string)($row['note'] ?? ''),
                    'verified'  => (bool)$row['verified'],
                    'sortOrder' => (int)$row['sortOrder'],
                ];
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            error_log("[SongData::_externalLinksMap({$entityType})] " . $e->getMessage());
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
           safe via the schema probe inside _songbookSeriesMap.
           #831 — compilers attached the same way.
           #832 — alt names attached the same way.
           #833 — external links attached the same way. */
        $seriesMap               = $this->_songbookSeriesMap([$id]);
        $compilersMap            = $this->_songbookCompilersMap([$id]);
        $altNamesMap             = $this->_songbookAltNamesMap([$id]);
        $linksMap                = $this->_externalLinksMap('songbook', [$id]);
        $row['series']           = $seriesMap[(string)$row['id']]    ?? [];
        $row['compilers']        = $compilersMap[(string)$row['id']] ?? [];
        $row['alternativeNames'] = $altNamesMap[(string)$row['id']]  ?? [];
        $row['links']            = $linksMap[(string)$row['id']]     ?? [];
        /* #1181 — effective badge colour resolves own → first member series
           that defines a colour → theme default (empty). So a series can give
           ONE shared colour to all its songbooks without each being set. */
        if (($row['colour'] ?? '') === '') {
            foreach ($row['series'] as $ser) {
                if (!empty($ser['colour'])) { $row['colour'] = $ser['colour']; break; }
            }
        }
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
     * Lightweight, paginated song index — DB-direct, NO relation loads.
     *
     * Returns ONLY the columns a browse / list / editor-sidebar needs
     * (id, number, title, songbook, songbookName, language, has-media
     * flags). Built for WS-A (#1012): list surfaces no longer materialise
     * the whole corpus, so read memory stays flat regardless of catalogue
     * size. Full song detail is fetched per-record via getSongById().
     *
     * songbookName comes from a JOIN to tblSongbooks (the LIVE name), not
     * the denormalised tblSongs.SongbookName — forward-compatible with the
     * de-normalisation in WS-E (#1013).
     *
     * The language filter is pushed into SQL (applyLanguageFilterSql) so
     * pagination stays correct (post-fetch filtering would drop rows from
     * the page). A missing DB is an error, never a stale-file fallback —
     * per the live/online-first governing rule.
     *
     * @param string|null  $bookId      Optional songbook abbreviation filter.
     * @param int          $limit       Page size (clamped 1..500).
     * @param int          $offset      Row offset (>= 0).
     * @param list<string> $langSubtags Preferred-language subtags ([] = no filter).
     * @return array{songs: list<array>, total: int, offset: int, limit: int}
     */
    public function getSongsIndex(?string $bookId = null, int $limit = 50, int $offset = 0, array $langSubtags = []): array
    {
        if ($this->jsonMode || !($this->db instanceof \mysqli)) {
            throw new \RuntimeException('getSongsIndex requires a live database connection.');
        }
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'language_filter.php';

        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $where  = [];
        $params = [];
        $types  = '';
        if ($bookId !== null && $bookId !== '') {
            $bookId   = strtoupper(trim($bookId));
            $where[]  = 's.SongbookAbbr = ?';
            $params[] = $bookId;
            $types   .= 's';
        }
        if (APP_CONFIG['features']['public_domain_only'] ?? false) {
            $where[] = 's.LyricsPublicDomain = 1';
        }

        [$langWhere, $langTypes, $langParams] = applyLanguageFilterSql('s.Language', $langSubtags);

        $whereClause  = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
        $whereClause .= $langWhere; /* ' AND (...)' or ' AND 1=1' */

        $allTypes  = $types . $langTypes;
        $allParams = array_merge($params, $langParams);

        /* total (for pagination) */
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM tblSongs s {$whereClause}");
        if ($allTypes !== '') {
            $cStmt->bind_param($allTypes, ...$allParams);
        }
        $cStmt->execute();
        $total = (int)($cStmt->get_result()->fetch_row()[0] ?? 0);
        $cStmt->close();

        /* page rows — lightweight projection only, canonical ordering
           (mirrors getSongs: songbook → numbered-first → number → title). */
        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, b.Name AS songbookName,
                       s.Language AS language,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                FROM tblSongs s
                LEFT JOIN tblSongbooks b ON b.Abbreviation = s.SongbookAbbr
                {$whereClause}
                ORDER BY s.SongbookAbbr ASC,
                         CASE WHEN b.IsOfficial = 1 AND s.Number IS NOT NULL THEN 0 ELSE 1 END ASC,
                         s.Number ASC,
                         LOWER(s.Title) ASC
                LIMIT ? OFFSET ?";
        $rowTypes  = $allTypes . 'ii';
        $rowParams = array_merge($allParams, [$limit, $offset]);
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($rowTypes, ...$rowParams);
        $stmt->execute();
        $res = $stmt->get_result();
        $songs = [];
        while ($row = $res->fetch_assoc()) {
            $row['number']        = normaliseSongNumber($row['number']);
            $row['hasAudio']      = (bool)$row['hasAudio'];
            $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
            $songs[] = $row;
        }
        $stmt->close();

        return ['songs' => $songs, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    /**
     * Slim index of EVERY song — lightweight fields only (no lyrics,
     * components, or credits). Powers the Song Editor sidebar (WS-D
     * #1016): one query returns id/number/title/songbook/songbookName per
     * song so the editor lists the whole catalogue without downloading
     * the ~140 MB corpus; the full editable record is fetched per song on
     * open via getSongById(). songbookName is the LIVE tblSongbooks.Name
     * (WS-E #1013). Canonical order mirrors getSongsIndex/getSongs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSongsSlimIndex(): array
    {
        if (!$this->db) {
            throw new \RuntimeException('getSongsSlimIndex requires a live database connection.');
        }

        /* No parameters — pure constant SQL, safe to run via query(). */
        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                       s.Language AS language,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                FROM tblSongs s
                LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                ORDER BY s.SongbookAbbr ASC,
                         CASE WHEN sb.IsOfficial = 1 AND s.Number IS NOT NULL THEN 0 ELSE 1 END ASC,
                         s.Number ASC,
                         LOWER(s.Title) ASC";

        $res  = $this->db->query($sql);
        $rows = [];
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $row['number']        = normaliseSongNumber($row['number']);
                $row['hasAudio']      = (bool)$row['hasAudio'];
                $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
                $rows[] = $row;
            }
            $res->free();
        }
        return $rows;
    }

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
        /* #892 — append ArrangementJson to the bulk-load SELECT only
           when the column exists, so the Song Editor's `?action=load`
           round-trip surfaces the persisted custom order. Pre-
           migration deploys keep the legacy column list. */
        $arrSelect = $this->_hasArrangementColumn() ? ', s.ArrangementJson AS arrangementJson' : '';
        /* WS-E (#1013): songbookName from the LIVE tblSongbooks JOIN (b),
           not the denormalised s.SongbookName, so songbook renames
           propagate immediately. (b is LEFT JOINed below.) */
        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title, s.SongbookAbbr AS songbook,
                       b.Name AS songbookName, s.Language AS language, s.Copyright AS copyright,
                       s.TuneName AS tuneName, s.Ccli AS ccli, s.Iswc AS iswc,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                       {$arrSelect}
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
            /* #892 — decode the JSON int-array column when present.
               Surfaces as `arrangement` to match the shape that the
               Song Editor (`editor.js`) and pages/song.php both read. */
            if (array_key_exists('arrangementJson', $row)) {
                $row['arrangement'] = $this->_decodeArrangement($row['arrangementJson']);
                unset($row['arrangementJson']);
            }
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
            /* #833 — song-level external links bulked in so the Song
               Editor (which reads from the corpus cache built off
               exportAsJson → getSongs) surfaces existing links on load.
               Pre-migration safe via the schema probe in the helper. */
            $songLinksMap = $this->_externalLinksMap('song', $songIds);
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
                $song['links']       = $songLinksMap[$sid]   ?? [];
            }
            unset($song);
        }

        return $songs;
    }

    /**
     * Get the full records of every song on which a person is credited as a
     * writer OR composer, matching any of the supplied name variants
     * (compared case-insensitively).
     *
     * Scoped replacement for the old whole-corpus `getSongs()` scan in
     * writer.php: the catalogue must NEVER materialise in full (CLAUDE.md
     * rule #17 / the #929 OOM). The match is pushed into SQL using the same
     * IN-subquery shape already used by searchSongs() and the credit→songs
     * JOIN in person.php; only the (small) matched set is then hydrated, one
     * record at a time, via the canonical getSongById().
     *
     * @param string[] $nameVariants Candidate names in any case; matched
     *                 case-insensitively against tblSongWriters /
     *                 tblSongComposers `.Name`.
     * @return array<int,array> Full song records (same shape as getSongs()).
     */
    public function getSongsByCreditName(array $nameVariants): array
    {
        /* Normalise to a unique, lower-cased, non-empty set. */
        $variants = [];
        foreach ($nameVariants as $n) {
            $n = mb_strtolower(trim((string)$n));
            if ($n !== '') {
                $variants[$n] = true;
            }
        }
        $variants = array_keys($variants);
        if ($variants === []) {
            return [];
        }

        /* Matched song IDs where the person is a writer OR composer. The
           placeholder string is built from a hardcoded count() (never from
           user input) and every value is bound (CLAUDE.md SQL rule). */
        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $sql = "SELECT DISTINCT s.SongId
                  FROM tblSongs s
                 WHERE s.SongId IN (SELECT SongId FROM tblSongWriters   WHERE LOWER(Name) IN ($placeholders))
                    OR s.SongId IN (SELECT SongId FROM tblSongComposers WHERE LOWER(Name) IN ($placeholders))";
        $stmt = $this->db->prepare($sql);
        /* Variants are bound twice — once for each subquery. */
        $types  = str_repeat('s', count($variants) * 2);
        $values = array_merge($variants, $variants);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['SongId'];
        }
        $stmt->close();
        if ($ids === []) {
            return [];
        }

        /* Hydrate only the matched set — bounded by the person's catalogue,
           never O(corpus) — reusing the canonical per-record path. The
           writer page is ETag-cached (api.php $_cacheablePages) so this cost
           amortises; a batch hydrator (cf. _getWritersMap) is a possible
           future optimisation for very prolific writers. */
        $songs = [];
        foreach ($ids as $songId) {
            $rec = $this->getSongById($songId);
            if ($rec !== null) {
                $songs[] = $rec;
            }
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

    /**
     * The block names a song_detail `include=` request may ask for (#1099).
     * Allow-list — anything outside this is ignored, never interpolated.
     *
     * @return string[]
     */
    public static function songDetailIncludeBlocks(): array
    {
        return [
            'tune', 'media', 'arrangements', 'royaltyIds', 'scriptureRefs',
            'vocalParts', 'translations', 'annotations',
        ];
    }

    /**
     * Optional, scoped enrichment blocks for the song_detail API (#1099 — the
     * native/casting "technical gate"). Each block is computed ONLY when asked
     * for via the include-list AND its table exists (un-migrated deployments get
     * a clean omission, never a 500), reads one song's rows (never the corpus),
     * and binds every value. Returns a map of present blocks; absent/empty
     * blocks are omitted so clients tolerate partial payloads.
     *
     * @param string   $songId  Canonical SongId, e.g. CP-0001
     * @param string[] $include Requested block names (already allow-list filtered)
     * @return array<string,mixed>
     */
    public function getSongDetailExtras(string $songId, array $include): array
    {
        if ($this->jsonMode || $this->db === null || $include === []) {
            return [];
        }
        $songId = strtoupper(trim($songId));
        $want = array_intersect($include, self::songDetailIncludeBlocks());
        if ($want === []) {
            return [];
        }

        /* Resolve the song's primary lyrics version once (for per-line blocks). */
        $needsLyrics = (bool)array_intersect($want, ['vocalParts', 'translations', 'annotations']);
        $lyricsId = $needsLyrics ? $this->_primaryLyricsId($songId) : 0;

        $out = [];
        foreach ($want as $block) {
            try {
                switch ($block) {
                    case 'tune':
                        $row = $this->_extrasRow(
                            'SELECT t.Id AS id, t.Name AS name, t.Slug AS slug, t.MeterCode AS meter, '
                          . 't.MusicBrainzWorkMBID AS musicBrainzWorkMbid '
                          . 'FROM tblTunes t JOIN tblSongs s ON s.TuneId = t.Id WHERE s.SongId = ? LIMIT 1',
                            's', [$songId]
                        );
                        if ($row !== null) { $out['tune'] = $row; }
                        break;

                    case 'media':
                        $rows = $this->_extrasRows(
                            'SELECT Id AS id, Kind AS kind, MimeType AS mimeType, FileName AS fileName, '
                          . 'SizeBytes AS sizeBytes FROM tblSongMedia WHERE SongId = ? ORDER BY Id',
                            's', [$songId]
                        );
                        if ($rows) { $out['media'] = $rows; }
                        break;

                    case 'arrangements':
                        $rows = $this->_extrasRows(
                            'SELECT Name AS name, IsDefault AS isDefault, KeySignature AS keySignature, '
                          . 'CapoFret AS capoFret, ComponentOrderJson AS componentOrder '
                          . 'FROM tblSongArrangements WHERE SongId = ? ORDER BY IsDefault DESC, Name',
                            's', [$songId]
                        );
                        foreach ($rows as &$r) {
                            $r['isDefault'] = (bool)$r['isDefault'];
                            $r['componentOrder'] = $r['componentOrder'] !== null
                                ? json_decode((string)$r['componentOrder'], true) : null;
                        }
                        unset($r);
                        if ($rows) { $out['arrangements'] = $rows; }
                        break;

                    case 'royaltyIds':
                        $rows = $this->_extrasRows(
                            'SELECT Authority AS authority, AuthorityId AS authorityId, Note AS note '
                          . 'FROM tblSongRoyaltyIds WHERE SongId = ? ORDER BY SortOrder, Authority',
                            's', [$songId]
                        );
                        if ($rows) { $out['royaltyIds'] = $rows; }
                        break;

                    case 'scriptureRefs':
                        $rows = $this->_extrasRows(
                            'SELECT Book AS book, Chapter AS chapter, VerseStart AS verseStart, '
                          . 'VerseEnd AS verseEnd, OsisRef AS osisRef FROM tblSongScriptureRefs '
                          . 'WHERE SongId = ? ORDER BY SortOrder, Id',
                            's', [$songId]
                        );
                        if ($rows) { $out['scriptureRefs'] = $rows; }
                        break;

                    case 'vocalParts':
                        if ($lyricsId > 0) {
                            $rows = $this->_extrasRows(
                                'SELECT Id AS id, PartKind AS partKind, Label AS label, '
                              . 'SingerName AS singerName, Gender AS gender, CreditPersonId AS creditPersonId '
                              . 'FROM tblVocalParts WHERE LyricsId = ? ORDER BY SortOrder, Id',
                                'i', [$lyricsId]
                            );
                            if ($rows) { $out['vocalParts'] = $rows; }
                        }
                        break;

                    case 'translations':
                        if ($lyricsId > 0) {
                            $rows = $this->_extrasRows(
                                'SELECT tr.LineId AS lineId, tr.Kind AS kind, tr.TargetLanguage AS targetLanguage, '
                              . 'tr.Text AS text, tr.IsPrimary AS isPrimary '
                              . 'FROM tblLyricLineTranslations tr WHERE tr.LyricsId = ? AND tr.Status = ? '
                              . 'ORDER BY tr.LineId, tr.SortOrder',
                                'is', [$lyricsId, 'approved']
                            );
                            foreach ($rows as &$r) { $r['isPrimary'] = (bool)$r['isPrimary']; }
                            unset($r);
                            if ($rows) { $out['translations'] = $rows; }
                        }
                        break;

                    case 'annotations':
                        if ($lyricsId > 0) {
                            $rows = $this->_extrasRows(
                                'SELECT a.StartLineId AS startLineId, a.EndLineId AS endLineId, '
                              . 'a.AnnotationType AS annotationType, a.Body AS body, a.BodyFormat AS bodyFormat, '
                              . 'a.IsVerified AS isVerified FROM tblLyricLineAnnotations a '
                              . 'WHERE a.LyricsId = ? AND a.Status = ? ORDER BY a.StartLineId, a.SortOrder',
                                'is', [$lyricsId, 'approved']
                            );
                            foreach ($rows as &$r) { $r['isVerified'] = (bool)$r['isVerified']; }
                            unset($r);
                            if ($rows) { $out['annotations'] = $rows; }
                        }
                        break;
                }
            } catch (\Throwable $e) {
                /* Table missing on an un-migrated deployment (or any read error):
                   omit the block rather than fail the whole song_detail. */
                continue;
            }
        }
        return $out;
    }

    /** Resolve a song's primary (or sole approved) lyrics version Id, or 0. */
    private function _primaryLyricsId(string $songId): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT Id FROM tblLyrics WHERE SongId = ? AND Status = 'approved' "
              . "ORDER BY IsPrimary DESC, Id ASC LIMIT 1"
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ? (int)$row['Id'] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Run a single-row enrichment query (bound) → assoc row or null. */
    private function _extrasRow(string $sql, string $types, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** Run a multi-row enrichment query (bound) → list of assoc rows. */
    private function _extrasRows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        return $rows;
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
     * @param string      $query         Search query string
     * @param string|null $songbookId    Limit search to a specific songbook
     * @param int         $limit         Maximum results to return (0 = no limit)
     * @param int         $offset        Pagination offset into the result set
     * @param bool        $includeLyrics Search (and snippet) song bodies too,
     *                                   not just titles — mirrors the public
     *                                   "search within lyrics" toggle
     * @return array Matching song objects
     */
    public function searchSongs(string $query, ?string $songbookId = null, int $limit = 50, int $offset = 0, bool $includeLyrics = true): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        if ($offset < 0) {
            $offset = 0;
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
                if ($includeLyrics) {
                    foreach ($song['components'] ?? [] as $comp) {
                        foreach ($comp['lines'] ?? [] as $line) {
                            if (mb_stripos($line, $q) !== false) { $results[] = $song; continue 3; }
                        }
                    }
                }
            }
            return $limit > 0 ? array_slice($results, $offset, $limit) : array_slice($results, $offset);
        }

        if ($songbookId !== null) {
            $songbookId = strtoupper(trim($songbookId));
            if ($songbookId === '') {
                $songbookId = null;
            }
        }

        /* Which columns participate in the FULLTEXT match — title-only
           when the caller hasn't opted into lyrics search (mirrors the
           public "search within lyrics" toggle, off by default), and
           title + lyrics when they have. Both column sets are backed by
           a dedicated FULLTEXT index (idx_TitleFt / idx_TitleLyricsFt). */
        $matchCols = $includeLyrics ? 's.Title, s.LyricsText' : 's.Title';

        $results = [];
        $tokens  = self::_tokenizeSearch($query);

        /* Very short queries (< 3 chars) sit below InnoDB's FULLTEXT
           minimum token length, so a LIKE scan is the only option. */
        if (mb_strlen($query) < 3 || empty($tokens)) {
            $results = $this->_searchByLike($query, $songbookId, $limit, $offset, $includeLyrics);
        } else {
            /* D2 hybrid — step 1: relevance-ranked FULLTEXT with each
               term prefix-matched AND required (+term*). Catches partial
               words ("amaz grac" → "Amazing Grace") the way the old Fuse
               index did, while staying a live, indexed MySQL query.
               A scripture reference ORs in its canonical expansion so
               "Ps 23" still reaches "Psalm 23" (#397). */
            $primary = self::_booleanPrefixExpr($tokens, true);
            if ($scriptureExpansion !== null && $scriptureExpansion !== $query) {
                $expExpr = self::_booleanPrefixExpr(self::_tokenizeSearch($scriptureExpansion), true);
                if ($expExpr !== '') {
                    $primary = '(' . $primary . ') (' . $expExpr . ')';
                }
            }
            $results = $this->_runFulltextSearch($primary, $matchCols, $songbookId, $limit, $offset);

            /* D2 hybrid — step 2: if requiring every term found nothing,
               broaden to ANY term (drop the +) so a single mistyped
               token doesn't sink the whole query. */
            if (empty($results)) {
                $loose   = self::_booleanPrefixExpr($tokens, false);
                $results = $this->_runFulltextSearch($loose, $matchCols, $songbookId, $limit, $offset);
            }
        }

        /* Bulk-attach writers / composers / components — was 3 queries
           per matched row, now one per side table (#533). */
        $this->_attachSearchResultCredits($results);

        /* Server-side lyrics snippet (replaces the old client-side Fuse
           snippet) — only when lyrics search is on and the hit is in the
           body rather than the title. */
        if ($includeLyrics) {
            $this->_attachLyricsSnippets($results, $tokens);
        }

        /* D2 hybrid — step 3: writer/composer LIKE fallback when the
           text passes came up empty. */
        if (empty($results) && mb_strlen($query) >= 3) {
            $results = $this->_searchByWriterComposer($query, $songbookId, $limit);
        }

        /* D2 hybrid — step 4: phonetic (SOUNDEX) last resort, so an
           "Halleluyah"/"Hallelujah"-style misspelling still lands
           something. Only runs when every other pass came up empty, so
           the unindexed SOUNDEX scan stays a rare cost. */
        if (empty($results) && mb_strlen($query) >= 3) {
            $results = $this->_searchBySoundex($query, $songbookId, $limit);
        }

        /* Curated merges (alt-title #832, scripture-tag #397) belong at
           the TOP of the FIRST page only — re-prepending them on every
           "load more" page would duplicate them. */
        if ($offset === 0) {
            $results = $this->_mergeCuratedHits($results, $query, $scriptureExpansion, $songbookId, $limit);
        }

        return $results;
    }

    /**
     * Split a user query into FULLTEXT-safe tokens. Strips the
     * characters that carry meaning in BOOLEAN mode (+ - > < ( ) ~ * "
     * @) so a raw user string can never inject operators or break the
     * parser; we re-add our own operators in _booleanPrefixExpr().
     *
     * @return string[]
     */
    private static function _tokenizeSearch(string $query): array
    {
        $clean = preg_replace('/[+\-><()~*"@]+/u', ' ', $query);
        $parts = preg_split('/\s+/u', trim((string)$clean), -1, PREG_SPLIT_NO_EMPTY);
        return $parts ?: [];
    }

    /**
     * Build a BOOLEAN-mode expression with every token prefix-matched.
     * $require = true  → "+amaz* +grac*" (all terms required),
     * $require = false → "amaz* grac*"   (any term, used to broaden).
     */
    private static function _booleanPrefixExpr(array $tokens, bool $require): string
    {
        $op = $require ? '+' : '';
        $terms = [];
        foreach ($tokens as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            $terms[] = $op . $t . '*';
        }
        return implode(' ', $terms);
    }

    /**
     * Run a single FULLTEXT BOOLEAN-mode pass and return lightweight,
     * relevance-ordered rows (credits are bulk-attached by the caller).
     * songbookName comes from the LIVE tblSongbooks JOIN, not the
     * denormalised tblSongs.SongbookName (WS-E #1013).
     */
    private function _runFulltextSearch(string $ftQuery, string $matchCols, ?string $songbookId, int $limit, int $offset): array
    {
        if (trim($ftQuery) === '') {
            return [];
        }

        $where  = ["MATCH({$matchCols}) AGAINST(? IN BOOLEAN MODE)"];
        $params = [$ftQuery];
        $types  = 's';

        if ($songbookId !== null) {
            $where[]  = 's.SongbookAbbr = ?';
            $params[] = $songbookId;
            $types   .= 's';
        }

        $limitClause = $limit > 0 ? 'LIMIT ? OFFSET ?' : '';
        $whereClause = implode(' AND ', $where);

        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                       s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic,
                       MATCH({$matchCols}) AGAINST(? IN BOOLEAN MODE) AS relevance
                FROM tblSongs s
                LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                WHERE {$whereClause}
                ORDER BY relevance DESC, s.SongbookAbbr, s.Number
                {$limitClause}";

        /* MATCH appears in SELECT (relevance) and WHERE — bind twice. */
        array_unshift($params, $ftQuery);
        $types = 's' . $types;
        if ($limit > 0) {
            $params[] = $limit;  $types .= 'i';
            $params[] = $offset; $types .= 'i';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $this->_hydrateSearchRows($stmt->get_result());
        $stmt->close();
        return $rows;
    }

    /**
     * Substring (LIKE) search — used for sub-3-char queries below the
     * FULLTEXT minimum token length. Returns lightweight rows; credits
     * are bulk-attached by the caller.
     */
    private function _searchByLike(string $query, ?string $songbookId, int $limit, int $offset, bool $includeLyrics): array
    {
        $like = '%' . $query . '%';

        if ($includeLyrics) {
            $where  = ['(s.Title LIKE ? OR s.LyricsText LIKE ?)'];
            $params = [$like, $like];
            $types  = 'ss';
        } else {
            $where  = ['s.Title LIKE ?'];
            $params = [$like];
            $types  = 's';
        }

        if ($songbookId !== null) {
            $where[]  = 's.SongbookAbbr = ?';
            $params[] = $songbookId;
            $types   .= 's';
        }

        $limitClause = $limit > 0 ? 'LIMIT ? OFFSET ?' : '';
        $whereClause = implode(' AND ', $where);

        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                       s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                FROM tblSongs s
                LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                WHERE {$whereClause}
                ORDER BY s.SongbookAbbr, s.Number
                {$limitClause}";

        if ($limit > 0) {
            $params[] = $limit;  $types .= 'i';
            $params[] = $offset; $types .= 'i';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $this->_hydrateSearchRows($stmt->get_result());
        $stmt->close();
        return $rows;
    }

    /**
     * Phonetic (SOUNDEX) last-resort search. Unindexed — kept cheap by
     * only ever running when every other search pass came up empty.
     * Attaches its own credits because it is a terminal fallback.
     */
    private function _searchBySoundex(string $query, ?string $songbookId, int $limit): array
    {
        try {
            $where  = ['SOUNDEX(s.Title) = SOUNDEX(?)'];
            $params = [$query];
            $types  = 's';

            if ($songbookId !== null) {
                $where[]  = 's.SongbookAbbr = ?';
                $params[] = $songbookId;
                $types   .= 's';
            }

            $limitClause = $limit > 0 ? 'LIMIT ?' : '';
            $whereClause = implode(' AND ', $where);

            $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                           s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                           s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                           s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                           s.MusicPublicDomain AS musicPublicDomain,
                           s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                    FROM tblSongs s
                    LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                    WHERE {$whereClause}
                    ORDER BY s.SongbookAbbr, s.Number
                    {$limitClause}";

            if ($limit > 0) {
                $params[] = $limit;
                $types   .= 'i';
            }

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $this->_hydrateSearchRows($stmt->get_result());
            $stmt->close();
            $this->_attachSearchResultCredits($rows);
            return $rows;
        } catch (\Throwable $e) {
            error_log('[SongData::_searchBySoundex] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Normalise a raw search result row into the lightweight summary
     * shape the API returns. Shared by every search SQL path.
     */
    private function _hydrateSearchRows(\mysqli_result $res): array
    {
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            unset($row['relevance']);
            $row['number']             = normaliseSongNumber($row['number']);
            $row['verified']           = (bool)$row['verified'];
            $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
            $row['musicPublicDomain']  = (bool)$row['musicPublicDomain'];
            $row['hasAudio']           = (bool)$row['hasAudio'];
            $row['hasSheetMusic']      = (bool)$row['hasSheetMusic'];
            /* Every search path LEFT JOINs tblSongbooks, so an orphan /
               FK-less song yields a NULL songbookName. Coerce to '' so the
               API contract never leaks a literal null (mirrors the
               tuneName/iswc coercion in getSongById). */
            $row['songbookName']       = $row['songbookName'] ?? '';
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Attach a `lyricsSnippet` (first body line containing a query
     * token) to each row whose match is in the body, not the title —
     * the server-side replacement for the old Fuse match-snippet.
     * Requires components to have been attached already.
     *
     * @param string[] $tokens
     */
    private function _attachLyricsSnippets(array &$rows, array $tokens): void
    {
        if (empty($rows) || empty($tokens)) {
            return;
        }
        $needles = [];
        foreach ($tokens as $t) {
            $t = mb_strtolower(trim((string)$t));
            if ($t !== '') $needles[] = $t;
        }
        if (empty($needles)) {
            return;
        }

        foreach ($rows as &$row) {
            /* Snippet is only useful when the hit is NOT already in the
               title — a title match is its own context. */
            $title = mb_strtolower($row['title'] ?? '');
            $inTitle = false;
            foreach ($needles as $n) {
                if (mb_stripos($title, $n) !== false) { $inTitle = true; break; }
            }
            if ($inTitle) continue;

            foreach (($row['components'] ?? []) as $comp) {
                foreach (($comp['lines'] ?? []) as $line) {
                    $ll = mb_strtolower($line);
                    foreach ($needles as $n) {
                        if (mb_stripos($ll, $n) !== false) {
                            $row['lyricsSnippet'] = $line;
                            break 3;
                        }
                    }
                }
            }
        }
        unset($row);
    }

    /**
     * Prepend curator-priority matches — alternative titles (#832) and
     * scripture-reference tags (#397) — to the result list, de-duped by
     * SongId. Applied to the first page only (offset 0). Extracted from
     * searchSongs() so the page-1 merge stays one self-contained step.
     */
    private function _mergeCuratedHits(array $results, string $query, ?string $scriptureExpansion, ?string $songbookId, int $limit): array
    {
        /* Alternative-title matches (#832) — surface songs whose
           tblSongAlternativeTitles row matches, AT THE TOP so a
           curator-flagged alt match outranks a body-text fuzzy hit
           (search "Faith's Review and Expectation" → Amazing Grace
           first, not buried below lyrics matches). */
        $altHits = $this->_searchByAlternativeTitle($query, $songbookId, $limit);
        if (!empty($altHits)) {
            $seenAlt = [];
            foreach ($results as $r) { $seenAlt[$r['id']] = true; }
            $merged = [];
            foreach ($altHits as $hit) {
                if (!isset($seenAlt[$hit['id']])) {
                    $merged[] = $hit;
                    $seenAlt[$hit['id']] = true;
                }
            }
            foreach ($results as $r) { $merged[] = $r; }
            $results = $merged;
            if ($limit > 0) $results = array_slice($results, 0, $limit);
        }

        /* Scripture-reference tag matches (#397 follow-up) — surface
           songs tagged with the canonical book or full reference, at
           the top, de-duped against the existing list. */
        if ($scriptureExpansion !== null) {
            $tagHits = $this->_searchByScriptureTag($scriptureExpansion, $songbookId);
            if (!empty($tagHits)) {
                $tagIds = [];
                foreach ($tagHits as $th) { $tagIds[$th['id']] = true; }
                $merged = $tagHits;
                foreach ($results as $r) {
                    if (!isset($tagIds[$r['id']])) $merged[] = $r;
                }
                $results = array_values($merged);
                if ($limit > 0) $results = array_slice($results, 0, $limit);
            }
        }

        return $results;
    }

    /**
     * Lightweight autocomplete/typeahead suggestions (#307). Returns at
     * most $limit minimal rows (id / title / songbook / number / language)
     * ordered by relevance, deliberately skipping the heavy searchSongs()
     * pipeline (credits, components, curated merges, snippets) so header
     * typeahead stays snappy as a live MySQL query.
     *
     * Same D2 prefix strategy as searchSongs: FULLTEXT BOOLEAN +term* on
     * Title, with a LIKE fallback for sub-3-char queries (below the
     * FULLTEXT minimum token length) or when FULLTEXT finds nothing.
     * `language` rides along so the caller can language-filter; the
     * client ignores it.
     */
    public function suggestSongs(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '' || $this->jsonMode || !$this->db) {
            return [];
        }
        if ($limit <= 0) {
            $limit = 8;
        }

        $tokens = self::_tokenizeSearch($query);
        $rows   = [];

        $collect = function (\mysqli_result $res) use (&$rows): void {
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    'id'       => $row['id'],
                    'title'    => $row['title'],
                    'songbook' => $row['songbook'],
                    'number'   => normaliseSongNumber($row['number']),
                    'language' => $row['language'] ?? '',
                ];
            }
        };

        if (mb_strlen($query) >= 3 && !empty($tokens)) {
            $expr = self::_booleanPrefixExpr($tokens, true);
            $sql  = "SELECT s.SongId AS id, s.Title AS title,
                            s.SongbookAbbr AS songbook, s.Number AS number,
                            s.Language AS language,
                            MATCH(s.Title) AGAINST(? IN BOOLEAN MODE) AS relevance
                     FROM tblSongs s
                     WHERE MATCH(s.Title) AGAINST(? IN BOOLEAN MODE)
                     ORDER BY relevance DESC, s.SongbookAbbr, s.Number
                     LIMIT ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('ssi', $expr, $expr, $limit);
            $stmt->execute();
            $collect($stmt->get_result());
            $stmt->close();
        }

        /* LIKE fallback — short queries, or FULLTEXT came up empty. */
        if (empty($rows)) {
            $like = '%' . $query . '%';
            $sql  = "SELECT s.SongId AS id, s.Title AS title,
                            s.SongbookAbbr AS songbook, s.Number AS number,
                            s.Language AS language
                     FROM tblSongs s
                     WHERE s.Title LIKE ?
                     ORDER BY s.SongbookAbbr, s.Number
                     LIMIT ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('si', $like, $limit);
            $stmt->execute();
            $collect($stmt->get_result());
            $stmt->close();
        }

        return $rows;
    }

    /**
     * Find songs tagged with the given scripture reference (#397).
     *
     * Matches `Name = <reference>` (e.g. "Psalm 23"), `Name = <book>`
     * (e.g. "Psalm"), or the kebab-case slug form. Curators tag songs
     * via /manage/editor/ (tags UI) and the hit merges into search
     * results for scripture-style queries.
     */

    /**
     * Search songs by alternative title (#832). Returns the same row
     * shape as searchSongs() so the caller can merge results
     * transparently. The match ranks "alt title contains the query"
     * at the same level as a canonical title hit — alt titles ARE
     * canonical-equivalents in MusicBrainz parlance, just less
     * prominent. Each returned row also carries `matchedVia` =
     * { alternativeTitle: "<the alt that matched>" } so the result
     * UI can render a "(known as: …)" hint.
     *
     * Schema-probed; pre-migration deployments return an empty list.
     */
    private function _searchByAlternativeTitle(string $query, ?string $songbookId, int $limit): array
    {
        if ($this->jsonMode || !$this->db) return [];
        $query = trim($query);
        if ($query === '') return [];

        /* Probe — bail cheaply when the schema isn't live. */
        try {
            $probe = $this->db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongAlternativeTitles' LIMIT 1"
            );
            $exists = $probe && $probe->fetch_row() !== null;
            if ($probe) $probe->close();
            if (!$exists) return [];
        } catch (\Throwable $_e) {
            return [];
        }

        try {
            $like = '%' . $query . '%';
            $sql  = 'SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                            s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                            s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                            s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                            s.MusicPublicDomain AS musicPublicDomain,
                            s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic,
                            a.Title AS matchedAltTitle
                       FROM tblSongAlternativeTitles a
                       JOIN tblSongs s ON s.SongId = a.SongId
                       LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                      WHERE a.Title LIKE ?';
            $params = [$like];
            $types  = 's';
            if ($songbookId !== null) {
                $songbookId = strtoupper(trim($songbookId));
                $sql .= ' AND s.SongbookAbbr = ?';
                $params[] = $songbookId;
                $types   .= 's';
            }
            $sql .= ' ORDER BY s.SongbookAbbr, s.Number';
            if ($limit > 0) {
                $sql .= ' LIMIT ?';
                $params[] = $limit;
                $types   .= 'i';
            }

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();

            /* De-dup on SongId — a song with three alt-titles all matching
               the query would otherwise appear three times in the merge. */
            $hits = [];
            while ($row = $res->fetch_assoc()) {
                $sid = (string)$row['id'];
                if (isset($hits[$sid])) continue;
                $matchedAlt = (string)($row['matchedAltTitle'] ?? '');
                unset($row['matchedAltTitle']);
                $row['number'] = normaliseSongNumber($row['number']);
                $row['verified'] = (bool)$row['verified'];
                $row['lyricsPublicDomain'] = (bool)$row['lyricsPublicDomain'];
                $row['musicPublicDomain'] = (bool)$row['musicPublicDomain'];
                $row['hasAudio'] = (bool)$row['hasAudio'];
                $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
                $row['matchedVia'] = ['alternativeTitle' => $matchedAlt];
                $hits[$sid] = $row;
            }
            $stmt->close();
            $hits = array_values($hits);
            $this->_attachSearchResultCredits($hits);
            return $hits;
        } catch (\Throwable $e) {
            error_log('[SongData::_searchByAlternativeTitle] ' . $e->getMessage());
            return [];
        }
    }

    private function _searchByScriptureTag(string $scriptureRef, ?string $songbookId): array
    {
        if ($this->jsonMode || !$this->db) return [];

        /* Derive the base book (strip trailing chapter/verse). */
        $book = preg_replace('/\s+\d+(?::\d+)?$/', '', $scriptureRef);

        $slugRef  = self::_tagSlug($scriptureRef);
        $slugBook = self::_tagSlug($book);

        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                       s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                  FROM tblSongs s
                  JOIN tblSongTagMap m ON m.SongId = s.SongId
                  JOIN tblSongTags   t ON t.Id = m.TagId
                  LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
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
            "SELECT s.SongId AS id, s.Number AS number, s.Title AS title, s.SongbookAbbr AS songbook,
                    sb.Name AS songbookName, s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                    s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                    s.MusicPublicDomain AS musicPublicDomain,
                    s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
             FROM tblSongs s
             LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
             WHERE s.SongbookAbbr = ? AND CAST(s.Number AS CHAR) LIKE ?
             ORDER BY s.Number"
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

        /* #963 — only count songbooks that have at least one song.
           Mirrors getMeta()'s SQL-side filter so every surface reads
           the same number whether it comes from the live DB
           (this method) or the precomputed static cache (getMeta()
           feeds exportAsJson()). */
        $populatedSongbooks = 0;
        foreach ($songbooks as $book) {
            if ((int)($book['songCount'] ?? 0) > 0) {
                $populatedSongbooks++;
            }
        }

        return [
            'totalSongs'     => $totalSongs,
            'totalSongbooks' => $populatedSongbooks,
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

    /* WS-J #1020: exportAsJson() — the whole-corpus materialiser that fed the
       songs.json file cache (~140 MB of PHP-array memory, #929 OOM) — was
       removed with the cache itself. Reads are live MySQL now: getSongsSlimIndex()
       for the lightweight index, getSongs($abbr) for a single songbook bundle,
       getSongById() for one full record. Nothing materialises the whole corpus. */

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
        /* #892 — append ArrangementJson to the SELECT only when the
           column exists. Pre-migration deploys keep the legacy
           15-column shape so single-song reads don't 1054 on a
           half-migrated install. */
        $arrSelect    = $this->_hasArrangementColumn() ? ', ArrangementJson AS arrangementJson' : '';
        $placeSelect  = $this->_hasOriginPlaceColumn()
            ? ', OriginCity AS originCity, OriginCityId AS originCityId'
            : '';
        $stmt = $this->db->prepare(
            "SELECT s.SongId AS id, s.Number AS number, s.Title AS title, s.SongbookAbbr AS songbook,
                    sb.Name AS songbookName, s.Language AS language, s.Copyright AS copyright,
                    s.TuneName AS tuneName, s.Ccli AS ccli, s.Iswc AS iswc,
                    s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                    s.MusicPublicDomain AS musicPublicDomain,
                    s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                    {$arrSelect}
                    {$placeSelect}
             FROM tblSongs s
             LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
             WHERE s.SongId = ?
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
        /* Places adoption — pass-through the FK Id (or null) to the
           editor so the place-search module can populate its hidden
           sidecar input. The display string lives in originCity. */
        if (array_key_exists('originCity', $row)) {
            $row['originCity']   = $row['originCity'] ?? '';
            $row['originCityId'] = isset($row['originCityId']) ? (int)$row['originCityId'] : null;
        }
        /* #892 — decode the stored JSON int-array; the public render in
           pages/song.php reads `$song['arrangement']` directly. The
           helper drops malformed payloads (defensive) and returns NULL
           when the column was unset, keeping the existing
           "fallback to plain SortOrder" path live. */
        if (array_key_exists('arrangementJson', $row)) {
            $row['arrangement'] = $this->_decodeArrangement($row['arrangementJson']);
            unset($row['arrangementJson']);
        }
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

        /* #832 — alt titles for this song. Empty array on a pre-
           migration deployment via the schema probe in the helper. */
        $altMap = $this->_songAltTitlesMap([$songId]);
        $row['alternativeTitles'] = $altMap[$songId] ?? [];

        /* #833 — external links for this song. Empty array on a
           pre-migration deployment via the schema probe. */
        $linksMap = $this->_externalLinksMap('song', [$songId]);
        $row['links'] = $linksMap[$songId] ?? [];

        /* #840 — Works this song belongs to (with sibling members
           and Work-level external links attached). Empty array on a
           pre-migration deployment via the schema probe. */
        $worksMap = $this->_worksMap([$songId]);
        $row['works'] = $worksMap[$songId] ?? [];

        /* #853 — accompanying media (audio + sheet PDF + MIDI +
           MusicXML). Empty array on pre-migration. The legacy
           HasAudio / HasSheetMusic flags from MissionPraise scrape
           data continue to populate the indicator badges, but if
           tblSongMedia carries any rows of that kind for this song,
           we override the flag to true so the public surface
           reflects curator uploads — keeps the indicator badges
           on the songbook list page in sync without a separate
           denormalised counter. */
        $mediaMap = $this->_songMediaMap([$songId]);
        $row['media'] = $mediaMap[$songId] ?? [];
        if (!empty($row['media'])) {
            foreach ($row['media'] as $m) {
                if (($m['kind'] ?? '') === 'audio')        $row['hasAudio']       = true;
                if (($m['kind'] ?? '') === 'sheet-music')  $row['hasSheetMusic']  = true;
            }
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
     * Schema-probe for tblSongComponents.Language (#858). Cached for
     * the lifetime of the SongData instance — every call to
     * _getComponents / _getComponentsMap shares the same answer.
     * Pre-migration deploys return false and the SELECT skips the
     * column to stay 1.x-compatible.
     */
    private function _hasComponentLanguageColumn(): bool
    {
        if ($this->_componentLangColumnChecked) {
            return $this->_componentLangColumn;
        }
        $this->_componentLangColumnChecked = true;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongComponents'
                    AND COLUMN_NAME  = 'Language' LIMIT 1"
            );
            $stmt->execute();
            $this->_componentLangColumn = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $this->_componentLangColumn = false;
        }
        return $this->_componentLangColumn;
    }

    /** Cached probe for the optional tblSongComponents.ChordsJson column (#1066/#1094). */
    private function _hasComponentChordsColumn(): bool
    {
        if ($this->_componentChordsColumnChecked) {
            return $this->_componentChordsColumn;
        }
        $this->_componentChordsColumnChecked = true;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongComponents'
                    AND COLUMN_NAME  = 'ChordsJson' LIMIT 1"
            );
            $stmt->execute();
            $this->_componentChordsColumn = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $this->_componentChordsColumn = false;
        }
        return $this->_componentChordsColumn;
    }

    /**
     * #892 — schema-probe for tblSongs.ArrangementJson. Same caching
     * pattern as the component-language probe so editor-load (which
     * reads ~3,600 rows) doesn't fire INFORMATION_SCHEMA per row.
     * Pre-migration deploys return false and the SELECT skips the
     * column, keeping the legacy 15-column shape intact.
     */
    private function _hasArrangementColumn(): bool
    {
        if ($this->_arrangementColumnChecked) {
            return $this->_arrangementColumn;
        }
        $this->_arrangementColumnChecked = true;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongs'
                    AND COLUMN_NAME  = 'ArrangementJson' LIMIT 1"
            );
            $stmt->execute();
            $this->_arrangementColumn = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $this->_arrangementColumn = false;
        }
        return $this->_arrangementColumn;
    }

    /**
     * Places adoption — single-flight probe for tblSongs.OriginCityId.
     * Mirrors _hasArrangementColumn() so the SELECT path can gate the
     * OriginCity / OriginCityId columns without a per-request
     * INFORMATION_SCHEMA round-trip.
     */
    private function _hasOriginPlaceColumn(): bool
    {
        if ($this->_originPlaceColumnChecked) {
            return $this->_originPlaceColumn;
        }
        $this->_originPlaceColumnChecked = true;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongs'
                    AND COLUMN_NAME  = 'OriginCityId' LIMIT 1"
            );
            $stmt->execute();
            $this->_originPlaceColumn = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $this->_originPlaceColumn = false;
        }
        return $this->_originPlaceColumn;
    }

    /**
     * #892 — Decode the JSON string read from tblSongs.ArrangementJson
     * back into a plain int[]. Returns null when the column was NULL
     * or when the stored payload is malformed (defensive — the JSON
     * type validates well-formedness on write so this should be
     * unreachable, but a hand-edited row shouldn't 500 the read).
     */
    private function _decodeArrangement($raw): ?array
    {
        if ($raw === null || $raw === '') return null;
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string)$raw, true);
            if (!is_array($decoded)) return null;
        }
        $out = [];
        foreach ($decoded as $i) {
            if (!is_int($i) && !(is_string($i) && ctype_digit($i))) return null;
            $out[] = (int)$i;
        }
        return empty($out) ? null : $out;
    }

    /**
     * Get components (verses, choruses) for a song.
     *
     * @param string $songId Song ID
     * @return array Array of component objects with type, number, lines, language
     */
    private function _getComponents(string $songId): array
    {
        $langSelect = $this->_hasComponentLanguageColumn()
            ? ', Language AS language'
            : ', NULL AS language';
        $chordsSelect = $this->_hasComponentChordsColumn()
            ? ', ChordsJson AS chords_json'
            : ', NULL AS chords_json';
        $stmt = $this->db->prepare(
            "SELECT Id AS component_id, Type AS type, Number AS number, LinesJson AS lines_json{$langSelect}{$chordsSelect}
             FROM tblSongComponents
             WHERE SongId = ?
             ORDER BY SortOrder"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        /* #1235 P2a — source the line TEXT from the normalised tblLyricLines
           mirror; fall back to the component's authoritative LinesJson when the
           mirror is absent OR its line count does not match LinesJson (a cheap
           guard against a partially-synced / stale mirror silently showing
           short or wrong lyrics). Component metadata (type/number/chords/language)
           stays on tblSongComponents during P2. Output is BYTE-IDENTICAL to the
           LinesJson path — no extra keys; the per-line Ids are exposed in P3 (with
           the data/songs.schema.json update) once a consumer needs them. */
        $mirror = $this->_mirrorLinesByComponent($songId);

        $components = [];
        foreach ($rows as $row) {
            $cid       = (int)$row['component_id'];
            $linesJson = json_decode($row['lines_json'], true) ?? [];
            $lines     = (!empty($mirror[$cid]) && count($mirror[$cid]) === count($linesJson))
                ? array_map(static fn($l) => $l['text'], $mirror[$cid])
                : $linesJson;
            $components[] = [
                'type'     => $row['type'],
                'number'   => (int)$row['number'],
                'lines'    => $lines,
                'chords'   => (isset($row['chords_json']) && $row['chords_json'] !== null) ? (json_decode($row['chords_json'], true) ?: null) : null,
                'language' => $row['language'] !== null ? (string)$row['language'] : null,
            ];
        }
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
        $langSelect = $this->_hasComponentLanguageColumn()
            ? ', Language AS language'
            : ', NULL AS language';
        $chordsSelect = $this->_hasComponentChordsColumn()
            ? ', ChordsJson AS chords_json'
            : ', NULL AS chords_json';
        $stmt = $this->db->prepare(
            "SELECT SongId, Id AS component_id, Type AS type, Number AS number, LinesJson AS lines_json{$langSelect}{$chordsSelect}
             FROM tblSongComponents
             WHERE SongId IN ($placeholders)
             ORDER BY SongId, SortOrder"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        /* #1235 P2a — same mirror-sourced lines + count-checked LinesJson fallback
           as _getComponents(), batched for getSongs(). Byte-identical output. */
        $mirror = $this->_mirrorLinesByComponentMap($songIds);

        $map = [];
        foreach ($rows as $row) {
            $sid       = $row['SongId'];
            $cid       = (int)$row['component_id'];
            $linesJson = json_decode($row['lines_json'], true) ?? [];
            $lines     = (!empty($mirror[$sid][$cid]) && count($mirror[$sid][$cid]) === count($linesJson))
                ? array_map(static fn($l) => $l['text'], $mirror[$sid][$cid])
                : $linesJson;
            $map[$sid][] = [
                'type'     => $row['type'],
                'number'   => (int)$row['number'],
                'lines'    => $lines,
                'chords'   => (isset($row['chords_json']) && $row['chords_json'] !== null) ? (json_decode($row['chords_json'], true) ?: null) : null,
                'language' => $row['language'] !== null ? (string)$row['language'] : null,
            ];
        }
        return $map;
    }

    /**
     * #1235 P2a — cached probe: is the normalised tblLyricLines mirror present?
     * When true, the component builders source line text from it (with a
     * per-component LinesJson fallback); when false (un-migrated install) every
     * component falls back to tblSongComponents.LinesJson, so reads never break.
     */
    private function _hasLyricLinesMirror(): bool
    {
        if ($this->_lyricLinesMirrorChecked) {
            return $this->_lyricLinesMirror;
        }
        $this->_lyricLinesMirrorChecked = true;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblLyricLines' LIMIT 1"
            );
            $stmt->execute();
            $this->_lyricLinesMirror = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $this->_lyricLinesMirror = false;
        }
        return $this->_lyricLinesMirror;
    }

    /**
     * #1235 P2a — a song's mirrored lyric lines (the primary 'ihymns' version)
     * from tblLyricLines, grouped by ComponentId, each component in SortOrder.
     * Returns [ componentId => [ ['id'=>int,'text'=>string], … ] ], or [] when the
     * mirror is absent or the song has no mirrored lines (callers then fall back
     * to LinesJson per component). One query.
     *
     * @return array<int,array<int,array{id:int,text:string}>>
     */
    private function _mirrorLinesByComponent(string $songId): array
    {
        if (!$this->_hasLyricLinesMirror()) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT ll.ComponentId AS cid, ll.Id AS line_id, ll.LineText AS line_text
               FROM tblLyricLines ll
               JOIN tblLyrics ly ON ly.Id = ll.LyricsId
              WHERE ly.SongId = ? AND ly.Source = 'ihymns' AND ll.ComponentId IS NOT NULL
              ORDER BY ll.SortOrder"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(int)$row['cid']][] = ['id' => (int)$row['line_id'], 'text' => (string)$row['line_text']];
        }
        $stmt->close();
        return $out;
    }

    /**
     * #1235 P2a — bulk variant of _mirrorLinesByComponent() for getSongs().
     * Returns [ songId => [ componentId => [ ['id','text'], … ] ] ]. One query.
     *
     * @param string[] $songIds
     * @return array<string,array<int,array<int,array{id:int,text:string}>>>
     */
    private function _mirrorLinesByComponentMap(array $songIds): array
    {
        if (empty($songIds) || !$this->_hasLyricLinesMirror()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));
        $types = str_repeat('s', count($songIds));
        $stmt = $this->db->prepare(
            "SELECT ly.SongId AS song_id, ll.ComponentId AS cid, ll.Id AS line_id, ll.LineText AS line_text
               FROM tblLyricLines ll
               JOIN tblLyrics ly ON ly.Id = ll.LyricsId
              WHERE ly.SongId IN ($placeholders) AND ly.Source = 'ihymns' AND ll.ComponentId IS NOT NULL
              ORDER BY ll.SortOrder"
        );
        $stmt->bind_param($types, ...$songIds);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(string)$row['song_id']][(int)$row['cid']][] = ['id' => (int)$row['line_id'], 'text' => (string)$row['line_text']];
        }
        $stmt->close();
        return $out;
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
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                       s.Language AS language, s.Copyright AS copyright, s.Ccli AS ccli,
                       s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                       s.MusicPublicDomain AS musicPublicDomain,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                FROM tblSongs s
                LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
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

    /* =====================================================================
     * WORKS — composition grouping (#840)
     *
     * Pull membership rows from tblWorkSongs and the Work header rows
     * they reference. Probe-gated on tblWorks existing; pre-migration
     * deployments get an empty map and every read path short-circuits
     * cleanly.
     * ===================================================================== */

    /**
     * Probe once — does tblWorks exist? Cached because the song page
     * and the api both ask within the same request.
     */
    private function _hasWorksSchema(): bool
    {
        if (isset($this->_hasWorksSchemaCache)) {
            return $this->_hasWorksSchemaCache;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $t = 'tblWorks';
            $stmt->bind_param('s', $t);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return $this->_hasWorksSchemaCache = $exists;
        } catch (\Throwable $_e) {
            return $this->_hasWorksSchemaCache = false;
        }
    }
    /** @var bool|null cached probe result */
    private ?bool $_hasWorksSchemaCache = null;

    /**
     * For each songId in $songIds, return a list of Works the song is
     * a member of. Empty array on a pre-migration deployment.
     *
     * Each Work entry: { id, parentId, title, slug, iswc, isCanonical,
     *                    members:[{songId, songbook, number, title, songbookName}],
     *                    links:[…] }
     *
     * Members + links are attached for the Works that appear, so
     * downstream code can render the "Other versions of this work"
     * sub-list without another round-trip.
     *
     * @param array<int,string> $songIds
     * @return array<string,array<int,array<string,mixed>>> keyed by songId
     */
    private function _worksMap(array $songIds): array
    {
        if (empty($songIds) || !$this->_hasWorksSchema()) return [];

        $clean = array_values(array_filter(array_unique(array_map('strval', $songIds))));
        if (empty($clean)) return [];

        try {
            /* Step 1 — membership rows + Work headers in one go. */
            $ph    = implode(',', array_fill(0, count($clean), '?'));
            $sql   = "SELECT ws.SongId    AS songId,
                             ws.IsCanonical AS isCanonical,
                             ws.SortOrder AS memberSort,
                             ws.Note      AS memberNote,
                             w.Id         AS workId,
                             w.ParentWorkId AS parentId,
                             w.Title      AS title,
                             w.Slug       AS slug,
                             w.Iswc       AS iswc
                        FROM tblWorkSongs ws
                        JOIN tblWorks w ON w.Id = ws.WorkId
                       WHERE ws.SongId IN ($ph)
                       ORDER BY w.Title ASC, ws.SortOrder ASC";
            $stmt  = $this->db->prepare($sql);
            $types = str_repeat('s', count($clean));
            $stmt->bind_param($types, ...$clean);
            $stmt->execute();
            $res = $stmt->get_result();

            $bySong = [];
            $workIds = [];
            while ($row = $res->fetch_assoc()) {
                $sid  = (string)$row['songId'];
                $wid  = (int)$row['workId'];
                $workIds[$wid] = true;
                $bySong[$sid][] = [
                    'id'          => $wid,
                    'parentId'    => $row['parentId'] !== null ? (int)$row['parentId'] : null,
                    'title'       => (string)$row['title'],
                    'slug'        => (string)$row['slug'],
                    'iswc'        => (string)($row['iswc'] ?? ''),
                    'isCanonical' => (bool)$row['isCanonical'],
                    'memberNote'  => (string)($row['memberNote'] ?? ''),
                    /* members + links attached in step 2/3 below */
                    'members'     => [],
                    'links'       => [],
                ];
            }
            $stmt->close();

            if (empty($bySong)) return [];

            /* Step 2 — sibling members of each work surfaced. */
            $widList = array_keys($workIds);
            $ph2     = implode(',', array_fill(0, count($widList), '?'));
            $sql2    = "SELECT ws.WorkId AS workId,
                               ws.SongId AS songId,
                               ws.IsCanonical AS isCanonical,
                               s.Title AS title,
                               s.Number AS number,
                               s.SongbookAbbr AS songbook,
                               sb.Name AS songbookName
                          FROM tblWorkSongs ws
                          JOIN tblSongs s ON s.SongId = ws.SongId
                          LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                         WHERE ws.WorkId IN ($ph2)
                         ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC";
            $stmt2 = $this->db->prepare($sql2);
            $types2 = str_repeat('i', count($widList));
            $stmt2->bind_param($types2, ...$widList);
            $stmt2->execute();
            $r2 = $stmt2->get_result();
            $membersByWork = [];
            while ($mrow = $r2->fetch_assoc()) {
                $wid = (int)$mrow['workId'];
                $membersByWork[$wid][] = [
                    'songId'       => (string)$mrow['songId'],
                    'title'        => (string)$mrow['title'],
                    'number'       => normaliseSongNumber($mrow['number']),
                    'songbook'     => (string)$mrow['songbook'],
                    'songbookName' => (string)($mrow['songbookName'] ?? ''),
                    'isCanonical'  => (bool)$mrow['isCanonical'],
                ];
            }
            $stmt2->close();

            /* Step 3 — work-level external links (only when the table
               exists; widely deferred-friendly since #833 might not yet
               be applied even when Works is). */
            $linksByWork = [];
            try {
                $probe = $this->db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorkExternalLinks' LIMIT 1"
                );
                $probe->execute();
                $hasWorkLinks = $probe->get_result()->fetch_row() !== null;
                $probe->close();
            } catch (\Throwable $_e) {
                $hasWorkLinks = false;
            }
            if ($hasWorkLinks) {
                $sql3 = "SELECT el.WorkId AS workId,
                                t.Slug      AS slug,
                                t.Name      AS name,
                                t.Category  AS category,
                                t.IconClass AS iconClass,
                                el.Url      AS url,
                                el.Note     AS note,
                                el.Verified AS verified,
                                el.SortOrder AS sortOrder
                           FROM tblWorkExternalLinks el
                           JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                          WHERE el.WorkId IN ($ph2)
                            AND COALESCE(t.IsActive, 1) = 1
                          ORDER BY el.WorkId, t.Category, el.SortOrder ASC,
                                   t.DisplayOrder ASC, t.Name ASC";
                $stmt3 = $this->db->prepare($sql3);
                $stmt3->bind_param($types2, ...$widList);
                $stmt3->execute();
                $r3 = $stmt3->get_result();
                while ($lrow = $r3->fetch_assoc()) {
                    $wid = (int)$lrow['workId'];
                    $linksByWork[$wid][] = [
                        'slug'      => (string)$lrow['slug'],
                        'name'      => (string)$lrow['name'],
                        'category'  => (string)$lrow['category'],
                        'iconClass' => (string)($lrow['iconClass'] ?? ''),
                        'url'       => (string)$lrow['url'],
                        'note'      => (string)($lrow['note'] ?? ''),
                        'verified'  => (bool)$lrow['verified'],
                        'sortOrder' => (int)$lrow['sortOrder'],
                    ];
                }
                $stmt3->close();
            }

            /* Stitch it together. */
            foreach ($bySong as $sid => &$worksList) {
                foreach ($worksList as &$w) {
                    $w['members'] = $membersByWork[$w['id']] ?? [];
                    $w['links']   = $linksByWork[$w['id']]   ?? [];
                }
                unset($w);
            }
            unset($worksList);

            return $bySong;
        } catch (\Throwable $e) {
            error_log('[SongData::_worksMap] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Public read: full Work row by slug or numeric id, including
     * members, parent / children references and external links.
     * Returns null when the schema isn't there or the work doesn't
     * exist. Used by the public /work/<slug> page and the api.
     */
    public function getWork(string|int $slugOrId): ?array
    {
        if (!$this->_hasWorksSchema()) return null;

        $isInt = is_int($slugOrId) || ctype_digit((string)$slugOrId);
        try {
            if ($isInt) {
                $stmt = $this->db->prepare(
                    'SELECT Id, ParentWorkId, Title, Slug, Iswc, Notes, CreatedAt, UpdatedAt
                       FROM tblWorks WHERE Id = ? LIMIT 1'
                );
                $id = (int)$slugOrId;
                $stmt->bind_param('i', $id);
            } else {
                $stmt = $this->db->prepare(
                    'SELECT Id, ParentWorkId, Title, Slug, Iswc, Notes, CreatedAt, UpdatedAt
                       FROM tblWorks WHERE Slug = ? LIMIT 1'
                );
                $slug = (string)$slugOrId;
                $stmt->bind_param('s', $slug);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return null;

            $work = [
                'id'        => (int)$row['Id'],
                'parentId'  => $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null,
                'title'     => (string)$row['Title'],
                'slug'      => (string)$row['Slug'],
                'iswc'      => (string)($row['Iswc'] ?? ''),
                'notes'     => (string)($row['Notes'] ?? ''),
                'createdAt' => (string)$row['CreatedAt'],
                'updatedAt' => (string)$row['UpdatedAt'],
                'parent'    => null,
                'children'  => [],
                'members'   => [],
                'links'     => [],
            ];

            /* Parent header (one row) */
            if ($work['parentId'] !== null) {
                $stmt = $this->db->prepare(
                    'SELECT Id, Title, Slug, Iswc FROM tblWorks WHERE Id = ? LIMIT 1'
                );
                $pid = (int)$work['parentId'];
                $stmt->bind_param('i', $pid);
                $stmt->execute();
                $prow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($prow) {
                    $work['parent'] = [
                        'id'    => (int)$prow['Id'],
                        'title' => (string)$prow['Title'],
                        'slug'  => (string)$prow['Slug'],
                        'iswc'  => (string)($prow['Iswc'] ?? ''),
                    ];
                }
            }

            /* Direct children */
            $stmt = $this->db->prepare(
                'SELECT Id, Title, Slug, Iswc FROM tblWorks
                  WHERE ParentWorkId = ? ORDER BY Title ASC'
            );
            $wid = (int)$work['id'];
            $stmt->bind_param('i', $wid);
            $stmt->execute();
            $cres = $stmt->get_result();
            while ($crow = $cres->fetch_assoc()) {
                $work['children'][] = [
                    'id'    => (int)$crow['Id'],
                    'title' => (string)$crow['Title'],
                    'slug'  => (string)$crow['Slug'],
                    'iswc'  => (string)($crow['Iswc'] ?? ''),
                ];
            }
            $stmt->close();

            /* Members */
            $stmt = $this->db->prepare(
                /* WS-E (#1013): live songbook name via JOIN, not the
                   denormalised s.SongbookName. */
                'SELECT ws.SongId, ws.IsCanonical, ws.SortOrder, ws.Note,
                        s.Title, s.Number, s.SongbookAbbr, sb.Name AS SongbookName
                   FROM tblWorkSongs ws
                   JOIN tblSongs s ON s.SongId = ws.SongId
                   LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                  WHERE ws.WorkId = ?
                  ORDER BY ws.IsCanonical DESC, s.SongbookAbbr ASC, s.Number ASC'
            );
            $stmt->bind_param('i', $wid);
            $stmt->execute();
            $mres = $stmt->get_result();
            while ($mrow = $mres->fetch_assoc()) {
                $work['members'][] = [
                    'songId'       => (string)$mrow['SongId'],
                    'title'        => (string)$mrow['Title'],
                    'number'       => normaliseSongNumber($mrow['Number']),
                    'songbook'     => (string)$mrow['SongbookAbbr'],
                    'songbookName' => (string)($mrow['SongbookName'] ?? ''),
                    'isCanonical'  => (bool)$mrow['IsCanonical'],
                    'memberNote'   => (string)($mrow['Note'] ?? ''),
                ];
            }
            $stmt->close();

            /* External links — probe-gated on the work-links table */
            try {
                $probe = $this->db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'tblWorkExternalLinks' LIMIT 1"
                );
                $probe->execute();
                $hasWorkLinks = $probe->get_result()->fetch_row() !== null;
                $probe->close();
            } catch (\Throwable $_e) {
                $hasWorkLinks = false;
            }
            if ($hasWorkLinks) {
                $stmt = $this->db->prepare(
                    "SELECT t.Slug, t.Name, t.Category, t.IconClass,
                            el.Url, el.Note, el.Verified, el.SortOrder
                       FROM tblWorkExternalLinks el
                       JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                      WHERE el.WorkId = ?
                        AND COALESCE(t.IsActive, 1) = 1
                      ORDER BY t.Category, el.SortOrder ASC,
                               t.DisplayOrder ASC, t.Name ASC"
                );
                $stmt->bind_param('i', $wid);
                $stmt->execute();
                $lres = $stmt->get_result();
                while ($lrow = $lres->fetch_assoc()) {
                    $work['links'][] = [
                        'slug'      => (string)$lrow['Slug'],
                        'name'      => (string)$lrow['Name'],
                        'category'  => (string)$lrow['Category'],
                        'iconClass' => (string)($lrow['IconClass'] ?? ''),
                        'url'       => (string)$lrow['Url'],
                        'note'      => (string)($lrow['Note'] ?? ''),
                        'verified'  => (bool)$lrow['Verified'],
                        'sortOrder' => (int)$lrow['SortOrder'],
                    ];
                }
                $stmt->close();
            }

            return $work;
        } catch (\Throwable $e) {
            error_log('[SongData::getWork] ' . $e->getMessage());
            return null;
        }
    }
}
