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
 *
 * ---------------------------------------------------------------------------
 * WHERE THIS SITS (read this before changing anything below)
 * ---------------------------------------------------------------------------
 * ELI5: this is the ONE door between "the app wants songs" and "MySQL has
 * songs". Nearly every public page, every PWA request and both editors walk
 * through it, so a mistake here is a mistake everywhere at once.
 *
 * It is the READ side only — nothing in this file writes. Its consumers are
 * `api.php` (the whole public JSON surface + the SPA page fragments under
 * `includes/pages/*`), `manage/editor/api.php` + `api2.php`, the sitemap, the
 * exporters and the OG-image renderer. Writes live elsewhere:
 * `manage/editor/save_song_core.php`, `includes/song_importers.php`,
 * `includes/lyric_lines_sync.php`, `includes/song_soft_delete.php`.
 *
 * THE FIVE LOAD-BEARING RULES THIS FILE ENCODES
 *
 * 1. NOTHING MAY MATERIALISE THE WHOLE CORPUS (CLAUDE.md rule #17, epic #1010
 *    WS-J #1020). `exportAsJson()` and the `songs.json` file cache were
 *    DELETED — a full hydration was ~140 MB of PHP arrays and OOM-killed
 *    shared hosting (#929). Reads are scoped by construction:
 *      • `getSongsSlimIndex()`  — id/number/title/songbook only, whole
 *                                 catalogue or one book (#1037);
 *      • `getSongs($abbr)`      — ONE songbook, fully hydrated;
 *      • `getSongById()`        — ONE record, fully hydrated;
 *      • `getSongsIndex()`      — a paginated window.
 *    `getSongs(null)` still exists and still hydrates everything; it is the one
 *    loaded gun in the file. Callers are expected to pass a songbook (see the
 *    rule-#17 comment at api.php:1474). A DB outage is a graceful 503 from the
 *    caller, NEVER a stale-file fallback.
 *
 * 2. SOFT-DELETED SONGS ARE INVISIBLE (#1694, epic #1692). Every SELECT against
 *    `tblSongs` glues on `$this->_visible($alias)`, which delegates to the one
 *    shared `songVisibleSql()`. Two documented exceptions, each carrying a
 *    `@deleted-visible:` tag explaining itself: `getMissingSongNumbers()` (a
 *    hidden song still OCCUPIES its number slot) and `_songIdForPublicId()` (a
 *    pure id resolver — filtering it would decay a 410 into a 404).
 *
 * 3. THE SCHEMA IS NOT GUARANTEED TO MATCH `schema.sql`. Three docroots share
 *    ONE MySQL and migrations are web-run from `/manage/setup-database`, never
 *    auto-applied on deploy — so "it's in schema.sql" ≠ "it exists on alpha".
 *    mysqli runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`
 *    (`includes/db_mysql.php`), so naming an absent column does not return
 *    false, it THROWS and white-screens the page (the #1228 lesson). Hence the
 *    idiom repeated ~15 times below:
 *
 *        probe INFORMATION_SCHEMA once → cache the answer on the instance →
 *        return either a SELECT tail / JOIN fragment or an empty string.
 *
 *    Cached because `getSongbook()` + `getSongbooks()` (and a 14k-row editor
 *    load) commonly run in the same request and must not pay a round-trip
 *    each. The fragments are hardcoded PHP constants, never runtime data —
 *    CLAUDE.md rule #5 clause (a). Same reasoning for the `try/catch → empty
 *    map` wrappers on the optional side-table loaders.
 *
 * 4. LYRIC LINES HAVE EXACTLY ONE READ PATH (#1235 P4, CLAUDE.md rule #25).
 *    `tblLyricLines` is authoritative; this file NEVER parses
 *    `tblSongComponents.LinesJson` itself except in the explicitly-gated
 *    un-migrated fallback (`_getComponentsFromJson()` /
 *    `_getComponentsMapFromJson()`). Assembly is delegated to the shared
 *    `includes/lyric_lines_read.php`.
 *
 * 5. NO N+1. Anything attached to a LIST of songs/songbooks is loaded by a
 *    `*Map()` helper — one `IN (...)` query per side table, keyed by SongId or
 *    Abbreviation — not by calling the per-song helper in a loop (#533,
 *    #EditorLoad). The per-song helpers survive because for ONE song a single
 *    round-trip beats building a placeholder list.
 *
 * A NOTE ON `$jsonMode` / `$jsonData`: dead since WS-J #1020. `jsonMode` can
 * never become true — the constructor always connects. The `if ($this->jsonMode)`
 * branches are inert and kept only to keep that removal a separate, reviewable
 * change; do not add new ones, and do not trust them as documentation of any
 * live behaviour.
 *
 * @see appWeb/public_html/includes/song_soft_delete.php   the #1694 visibility unit
 * @see appWeb/public_html/includes/lyric_lines_read.php   the ONE lyric-line reader
 * @see appWeb/public_html/includes/song_redirects.php     consulted by getSongById()
 * @see appWeb/public_html/includes/db_mysql.php           STRICT mode + getDbMysqli()
 * @link https://www.php.net/manual/en/book.mysqli.php
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html
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
    /** #1343-B — single-flight probe for tblSongs.PublicId (permalink id). */
    private bool $_publicIdColumn = false;
    private bool $_publicIdColumnChecked = false;
    /* Places adoption — single-flight probe for tblSongs.OriginCityId.
       Mirrors the ArrangementJson pattern so a pre-adoption install
       keeps the legacy SELECT shape (no extra columns) without a
       repeat INFORMATION_SCHEMA round-trip per request. */
    private bool $_originPlaceColumn = false;
    private bool $_originPlaceColumnChecked = false;

    /**
     * #1765 Feature 1 — the audience-mode switch: true = PUBLIC (the site
     * everyone sees), false = ADMIN (`/manage/*`, sees everything).
     *
     * ELI5: one flag that decides whether "a disabled songbook" means
     * "gone" or "still fully there".
     *
     * DEFAULT TRUE IS THE FAIL-SAFE (rule for this whole feature): a caller
     * who forgets to ask for the admin view gets the SAFE outcome — a
     * disabled book's songs stay HIDDEN rather than LEAKING onto the public
     * site. The only way to see everything is the explicit opt-in
     * `SongData::forAdmin()` below; the plain constructor can never produce
     * an admin-mode instance. Consulted by `_visible()` (song grain) and
     * `_bookVisible()` (songbook grain) — nowhere else reads it directly.
     */
    private bool $publicVisibility = true;

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

    /**
     * The `/manage/*` instance — sees EVERY songbook and song, including
     * ones disabled from the public site (#1765 Feature 1).
     *
     * ELI5: exactly the same SongData, but with the "hide disabled books"
     * switch turned off, for admin pages that must keep editing (and
     * re-enabling) a disabled book.
     *
     * DETAILED: constructs identically to `new SongData()` — same
     * `getDbMysqli()` connection, same everything — then flips ONE flag.
     * This is deliberately the ONLY way to obtain an admin-mode instance:
     * the plain constructor always defaults to public/fail-safe (see
     * `$publicVisibility`'s doc-block), so a page that reaches for
     * `new SongData()` gets the SAFE outcome and must explicitly opt in to
     * seeing disabled books via this factory.
     *
     * CALL SITES (the #1765 read-path census — keep this list and the code
     * in lockstep, rule #35): `manage/missing-numbers.php`,
     * `manage/editor/api.php` (5 sites), `manage/editor/api2.php` (2 sites).
     * `manage/gating-noop-verify.php` deliberately stays `new SongData()` —
     * it verifies the PUBLIC payload, so it must see exactly what the
     * public sees, disabled books included in the exclusion.
     *
     * @return self An admin-mode instance.
     */
    public static function forAdmin(): self
    {
        $instance = new self();
        $instance->publicVisibility = false;
        return $instance;
    }

    /**
     * #1694 — the soft-delete visibility predicate, for every SELECT this
     * class builds against tblSongs. #1765 Feature 1 folds the
     * disabled-songbook predicate in here too — see below.
     *
     * ELI5: the little "and it isn't deleted, and its songbook isn't
     * disabled" clause each song query glues onto its WHERE — or a
     * harmless "1=1" for either half before its migration has run, or
     * (for the songbook half) on an admin-mode instance.
     *
     * ONE delegate to the ONE shared unit (songVisibleSql(), which gates on
     * songSoftDeleteReady() — the #1228 STRICT-mode lesson: an ungated
     * IsDeleted reference on an un-migrated docroot would THROW and
     * white-screen every song surface). Call sites embed it UNCONDITIONALLY
     * after WHERE/AND: migrated it reads `s.IsDeleted = 0`, un-migrated it
     * degrades to `1=1`, so the SQL stays valid either way with zero
     * per-site branching. Same require_once + $this->db delegate shape as
     * the songRedirectClaimsId() consult in getSongById().
     *
     * THE #1765 ADD-ON is AND-composed in PUBLIC MODE ONLY: `songServableSql()`
     * answers "does this song's songbook keep it servable?" at the SAME
     * alias `_visible()` was already asked about, so ONE edit here covers
     * every one of this class's ~30 `tblSongs` reads at once (the epic
     * plan's "chokepoint" architecture) — none of those ~30 call sites
     * needed to change. Admin mode (`SongData::forAdmin()`) skips this half
     * entirely (stays plain `songVisibleSql()`) — `/manage/*` must keep
     * seeing every song in a disabled book so it can still be edited or
     * re-enabled; only the PUBLIC audience loses them.
     *
     * @param string $alias Table alias to prefix ('' for un-aliased queries).
     * @return string SQL predicate fragment — a hardcoded constant from PHP
     *                source (rule #5 clause a), never runtime data.
     */
    private function _visible(string $alias = 's'): string
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $sql = songVisibleSql($this->db, $alias);
        if ($this->publicVisibility) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php';
            $sql .= ' AND ' . songServableSql($this->db, $alias);
        }
        return $sql;
    }

    /**
     * #1765 Feature 1 — the songbook-grain sibling of `_visible()`: "is
     * THIS songbook row itself visible?"
     *
     * ELI5: the little "and this songbook isn't disabled" clause for
     * queries that read `tblSongbooks` directly (as opposed to `_visible()`,
     * which is for queries reading `tblSongs`) — or a harmless "1=1" in
     * admin mode, or before the migration has run.
     *
     * ONE delegate to `songbookVisibleSql()` (`songbook_visibility.php`),
     * which itself gates on `songbookDisableReady()` — same #1228
     * STRICT-mode lesson as `_visible()`. ADMIN MODE SKIPS THE PREDICATE
     * ENTIRELY (returns `1=1` without even asking the DB whether the
     * column exists) so `/manage/*` always sees every songbook, migrated or
     * not — there is no admin-side reason to ever hide one.
     *
     * @param string $alias Table alias to prefix (default 'b' — the house
     *                      convention for `tblSongbooks` in this codebase's
     *                      joined queries).
     * @return string SQL predicate fragment — a hardcoded constant from PHP
     *                source (rule #5 clause a), never runtime data.
     */
    private function _bookVisible(string $alias = 'b'): string
    {
        if (!$this->publicVisibility) {
            return '1=1';
        }
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php';
        return songbookVisibleSql($this->db, $alias);
    }

    /* =====================================================================
     * METADATA METHODS
     * ===================================================================== */

    /**
     * Get metadata about the song collection.
     *
     * ELI5: the two headline numbers ("N songs across M songbooks") the home
     * page and the PWA's cached meta block show.
     *
     * These are LIVE `COUNT(*)`s, deliberately — unlike `getStats()`, which
     * sums the denormalised `tblSongbooks.SongCount` column. The two agree only
     * while every write path keeps `SongCount` current, so prefer this one
     * whenever the number is user-facing and correctness beats the two extra
     * aggregate queries.
     *
     * @return array Metadata including totalSongs, totalSongbooks, etc.
     */
    public function getMeta(): array
    {
        if ($this->jsonMode) {
            return $this->jsonData['meta'] ?? [];
        }

        /* #1694 D1 — totalSongs means VISIBLE songs (owner: "ideally
           accessible", deliberately NOT per-user), so the soft-delete
           predicate applies here exactly as it does on the tiles. */
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM tblSongs WHERE " . $this->_visible(''));
        $stmt->execute();
        $result = $stmt->get_result();
        $totalSongs = (int)$result->fetch_assoc()['total'];
        $stmt->close();

        /* #963 — only count songbooks that have at least one song.
           Empty placeholder rows in tblSongbooks would otherwise
           inflate the home-page "N Songbooks" badge and the PWA
           cache's meta.totalSongbooks. #1694 — "at least one song" now
           means at least one VISIBLE song, matching totalSongs above.
           #1765 Feature 1 — a disabled songbook itself must not be
           counted either (its songs are already excluded by _visible()'s
           songServableSql() half, but a disabled book with zero OTHER
           reason to be excluded still needs the outer _bookVisible()). */
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total
               FROM tblSongbooks b
              WHERE " . $this->_bookVisible() . "
                AND EXISTS (
                  SELECT 1 FROM tblSongs s
                   WHERE s.SongbookAbbr = b.Abbreviation
                     AND " . $this->_visible() . "
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
            /* #1694 — every branch hides soft-deleted songs. The inward branch
               already joins tblSongs (src) and just gains the predicate; the
               outward + sibling branches list TranslatedSongId WITHOUT touching
               tblSongs, so each gains a visibility JOIN — otherwise a deleted
               translation target would still be OFFERED in the language picker
               and the click would land on a 410 (the wrong-content class). The
               joins are semantically neutral pre-delete: tblSongTranslations
               rows always reference existing tblSongs rows (FK). */
            $sql = '
                /* Outward — this song has translations to other languages */
                SELECT t.TranslatedSongId AS song_id, t.TargetLanguage AS target_language,
                       l.Name AS language_name, l.NativeName AS native_name,
                       l.TextDirection AS text_direction, t.Translator AS translator, t.Verified AS verified
                  FROM tblSongTranslations t
                  JOIN tblSongs tgt ON tgt.SongId = t.TranslatedSongId AND ' . $this->_visible('tgt') . '
                  JOIN tblLanguages l ON l.Code = t.TargetLanguage
                 WHERE t.SourceSongId = ? AND l.IsActive = 1
                UNION
                /* Inward — this song IS a translation; surface the source. */
                SELECT src.SongId AS song_id, srcLang.Code AS target_language,
                       srcLang.Name AS language_name, srcLang.NativeName AS native_name,
                       srcLang.TextDirection AS text_direction, "" AS translator, 1 AS verified
                  FROM tblSongTranslations selfT
                  JOIN tblSongs src ON src.SongId = selfT.SourceSongId AND ' . $this->_visible('src') . '
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
                  JOIN tblSongs sib ON sib.SongId = sibling.TranslatedSongId AND ' . $this->_visible('sib') . '
                  JOIN tblLanguages l2 ON l2.Code = sibling.TargetLanguage
                 WHERE selfT2.TranslatedSongId = ? AND l2.IsActive = 1
            ';
            /* prepare() throws under MYSQLI_REPORT_STRICT (includes/db_mysql.php),
               so it never returns false — a real failure propagates to the caller's
               try/catch, not a silent empty result. No false-guard needed (#1317). */
            $stmt = $this->db->prepare($sql);
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

    /**
     * Every songbook with its display metadata, alphabetically by Name.
     *
     * ELI5: the one call behind the home-page tiles, `/songbooks`, the
     * songbook picker and the PWA's songbook list.
     *
     * FOUR THINGS A READER USUALLY GETS WRONG HERE:
     *
     * 1. `songCount` is the DENORMALISED `tblSongbooks.SongCount` column, not a
     *    live `COUNT(*)`. It is recomputed by the WRITERS (`save_song_core.php`,
     *    `song_importers.php`, `song_relocate.php`, and `song_soft_delete.php`'s
     *    D1 recompute, which counts VISIBLE songs only). That is why there is no
     *    `_visible()` predicate in the SQL below — this query touches no
     *    `tblSongs` row at all. If a count ever looks stale, the bug is in a
     *    write path, not here. Contrast `getMeta()`, which counts live.
     * 2. UNOFFICIAL BOOKS ARE NOT FILTERED OUT and must never be (CLAUDE.md
     *    rule #24 / #1223). `isOfficial` is returned so the caller can render
     *    the shared `.songbook-unofficial-badge`; hiding `IsOfficial = 0` rows
     *    here would erase every curated collection from the site.
     * 3. THE FOUR `{$…Select}` HOLES are probe-gated SELECT tails (see the
     *    file doc-block, rule 3). On an un-migrated docroot they are empty
     *    strings and the query simply returns fewer keys — never a 500.
     * 4. THE FIVE BATCH MAPS at the bottom are the N+1 defence: series,
     *    compilers, alt names, external links and contained-languages are each
     *    ONE query for ALL books (#782 phase D, #831, #832, #833, #857), not
     *    one query per tile. `getSongbook()` is the same shape scoped to one
     *    abbreviation — keep the two in step when adding an attachment.
     *
     * @return array List of songbook objects (id, name, songCount, isOfficial,
     *               colour, series, compilers, alternativeNames, links, languages…)
     */
    public function getSongbooks(): array
    {
        /* #150 — alphabetise ignoring a leading article ("The Australian Hymn
           Book" files under A, not T). Shared helper so the fuzzy dedup fold
           (ihymns_sim_normalise) is not re-forked for a sort key (rule #22). */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'sort_helpers.php';

        if ($this->jsonMode) {
            $books = $this->jsonData['songbooks'] ?? [];
            ihymns_sort_by_title_key($books, 'name');
            return $books;
        }

        $bibSelect    = $this->_songbookBibSelect();
        $langSelect   = $this->_songbookLanguageSelect();
        $displayAbbrSelect = $this->_songbookDisplayAbbrSelect();
        $parentSelect = $this->_songbookParentSelect();
        $parentJoin   = $this->_songbookParentJoin();
        $flagsSelect  = $this->_songbookFlagsSelect();
        $openLibrarySelect = $this->_songbookOpenLibrarySelect();
        $stmt = $this->db->prepare(
            "SELECT b.Abbreviation AS id, b.Name AS name, b.SongCount AS songCount,
                    b.Colour AS colour,
                    b.IsOfficial      AS isOfficial,
                    b.Publisher       AS publisher,
                    b.PublicationYear AS publicationYear,
                    b.Copyright       AS copyright,
                    b.Affiliation     AS affiliation
                    {$displayAbbrSelect}
                    {$langSelect}
                    {$bibSelect}
                    {$parentSelect}
                    {$flagsSelect}
                    {$openLibrarySelect}
             FROM tblSongbooks b{$parentJoin}
             WHERE " . $this->_bookVisible() . "
             ORDER BY b.Name ASC"   /* deterministic fetch base; #150 re-sorts article-aware in PHP below */
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $books = [];
        while ($row = $result->fetch_assoc()) {
            $row['songCount']  = (int)$row['songCount'];
            /* Cast to a strict bool so JSON consumers don't have to
               deal with 0/1 vs true/false ambiguity (#502). #1765 —
               isDisabled/isPublicDomain follow the same rule, only present
               when _songbookFlagsSelect() emitted them. */
            $row['isOfficial'] = (bool)$row['isOfficial'];
            if (array_key_exists('isDisabled', $row)) {
                $row['isDisabled'] = (bool)$row['isDisabled'];
            }
            if (array_key_exists('isPublicDomain', $row)) {
                $row['isPublicDomain'] = (bool)$row['isPublicDomain'];
            }
            $books[] = $this->_normaliseSongbookParent($row);
        }
        $stmt->close();

        /* #150 — final order ignores a leading article. The SQL ORDER BY above
           only guarantees a deterministic fetch; the human-facing alphabetical
           order (the home tiles + /songbooks list consume this) is set here. */
        ihymns_sort_by_title_key($books, 'name');

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
     * Same probe-once pattern for the optional DisplayAbbr column (#1332) —
     * a free-text label shown in place of the Abbreviation badge. The column
     * is added by migrate-songbook-display-abbr.php; until that runs (migrations
     * aren't auto-applied on deploy) the SELECT must not name it, or every
     * getSongbooks() call 500s. `b.`-qualified for the parent self-join.
     *
     * ELI5: some books want a prettier badge than their code — "Psalty" rather
     * than "PSALTY" — so there's an optional label column, and this quietly
     * leaves it out on installs that haven't grown it yet.
     *
     * WHY A SECOND COLUMN AT ALL, rather than just relaxing `Abbreviation`
     * (CLAUDE.md rule #27): `tblSongbooks.Abbreviation` **is** the SongId
     * prefix. `MP-1008` is parsed as `<letters>-<digits>` by the PWA router's
     * `normalizeSongId()`, by the OG-image renderer and by the
     * `related_songs` / `remove_favorite` / `song_view` API validators.
     * Widening its charset to allow `- _ :` would re-key all ~14k
     * `tblSongs.SongId` values and break every URL, bookmark and cached OG
     * image in existence. So `DisplayAbbr` is DISPLAY-ONLY: URLs, `data-*`
     * attributes and SongIds always use the real `Abbreviation`.
     *
     * Consumers render it through `ihymns_songbook_abbr_label()` plus the
     * `ihymns_songbook_show_abbr()` hide-when-it-equals-the-title check, both
     * in `includes/songbook_display.php` — never by reading the key directly,
     * so the "no DisplayAbbr on this install" case has one fallback and not
     * one per page. This method is that contract's read half: it is why a
     * consumer can treat `displayAbbr` as merely absent instead of guarding a
     * column-not-found exception.
     *
     * @return string '' or a leading-comma SELECT tail (a PHP constant, rule #5a)
     */
    private function _songbookDisplayAbbrSelect(): string
    {
        if (isset($this->_displayAbbrSelectCache)) {
            return $this->_displayAbbrSelectCache;
        }
        $has = false;
        try {
            $probe = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbooks'
                    AND COLUMN_NAME  = 'DisplayAbbr'
                  LIMIT 1"
            );
            $probe->execute();
            $has = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* probe failure → fall through to empty tail */ }
        $this->_displayAbbrSelectCache = $has ? ', b.DisplayAbbr AS displayAbbr' : '';
        return $this->_displayAbbrSelectCache;
    }
    private ?string $_displayAbbrSelectCache = null;

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

    /**
     * LEFT JOIN fragment paired with _songbookParentSelect(). Empty when
     * the schema isn't live so the FROM clause stays valid.
     *
     * #1765 Feature 1 — the join's ON clause also carries `_bookVisible('p')`
     * so a DISABLED parent's `parentAbbreviation`/`parentName` come back
     * NULL in public mode (the join simply finds no matching row), rather
     * than exposing a disabled book's identity through a child's `parent`
     * field. `b.ParentSongbookId` itself (the raw FK, selected from `b` not
     * `p`) is untouched by this — only the JOINED columns collapse. Admin
     * mode / un-migrated installs get `_bookVisible()`'s harmless `1=1`, so
     * the join behaves exactly as before there.
     */
    private function _songbookParentJoin(): string
    {
        return $this->_songbookParentSelect() === ''
            ? ''
            : ' LEFT JOIN tblSongbooks p ON p.Id = b.ParentSongbookId AND ' . $this->_bookVisible('p');
    }

    /**
     * Same probe-once pattern as `_songbookBibSelect()` but for the two
     * #1765 columns `IsDisabled` (Feature 1) / `IsPublicDomain` (Feature 2)
     * — checked IN LOCKSTEP (`COUNT = 2`, the `songSoftDeleteReady()`
     * shape) because the migration adds both in the same ALTER and a
     * half-applied state (one column landed, the other didn't) must not
     * emit a SELECT naming a column that isn't there.
     *
     * ELI5: the admin badge needs to know "is this book disabled?" and "is
     * it public domain?" — this quietly adds both to the row, or neither
     * on an un-migrated install.
     *
     * `isDisabled` IS a payload key on public reads too (deliberately not
     * public-mode-gated the way `_bookVisible()` is) — harmless, because
     * once `_bookVisible()` is embedded in the same query a disabled
     * book's row is never SELECTed at all, so the key can never actually
     * read `true` on the public site; it's `/manage/*` (via
     * `SongData::forAdmin()`) that needs to see `true` there to badge a
     * disabled row in the admin list. `isPublicDomain` is its Feature-2
     * lockstep pair — informational only, never a gate (plan "Current-state
     * facts": "ONE `IsPublicDomain` flag … never a gate").
     *
     * @return string '' or a leading-comma SELECT tail (rule #5a — a
     *                hardcoded PHP constant, never runtime data).
     */
    private function _songbookFlagsSelect(): string
    {
        if (isset($this->_flagsSelectCache)) {
            return $this->_flagsSelectCache;
        }
        $has = false;
        try {
            $probe = $this->db->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbooks'
                    AND COLUMN_NAME  IN ('IsDisabled', 'IsPublicDomain')"
            );
            $probe->execute();
            $row = $probe->get_result()->fetch_row();
            $probe->close();
            $has = ($row !== null && (int)$row[0] === 2);
        } catch (\Throwable $_e) { /* probe failure → fall through to empty tail */ }
        /* `b.`-qualified — same reason as _songbookBibSelect() above; the
           parent-songbook self-join makes any unqualified column ambiguous. */
        $this->_flagsSelectCache = $has
            ? ', b.IsDisabled AS isDisabled, b.IsPublicDomain AS isPublicDomain'
            : '';
        return $this->_flagsSelectCache;
    }
    private ?string $_flagsSelectCache = null;

    /**
     * Same probe-once pattern as `_songbookFlagsSelect()` above, but for
     * the Feature-3 (#1765) `OpenLibraryWorkId` / `OpenLibraryEditionId`
     * pair — checked IN LOCKSTEP (`COUNT = 2`) for the same reason: the
     * migration adds both in one Stage-1 loop, but a partial apply must
     * still degrade column-by-column rather than 500 the whole
     * getSongbooks()/getSongbook() call. Kept as a SEPARATE probe from
     * `_songbookFlagsSelect()` (rather than widening that one to COUNT=4)
     * because the two pairs are conceptually independent facts — a
     * install could plausibly land the disable/PD flags before the
     * OpenLibrary identifier columns (or vice versa) if a migration run
     * is interrupted mid-loop — and Commit 3's guard already asserts the
     * exact shape of `_songbookFlagsSelect()`'s own probe, so it's safer
     * not to perturb it here.
     *
     * ELI5: the admin edit modal (and any future public consumer) needs
     * to know "does this songbook have an OpenLibrary Work/Edition id?" —
     * this quietly adds both to the row, or neither on an install that
     * hasn't run the #1765 migration yet.
     *
     * @return string '' or a leading-comma SELECT tail (rule #5a — a
     *                hardcoded PHP constant, never runtime data).
     */
    private function _songbookOpenLibrarySelect(): string
    {
        if (isset($this->_openLibrarySelectCache)) {
            return $this->_openLibrarySelectCache;
        }
        $has = false;
        try {
            $probe = $this->db->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongbooks'
                    AND COLUMN_NAME  IN ('OpenLibraryWorkId', 'OpenLibraryEditionId')"
            );
            $probe->execute();
            $row = $probe->get_result()->fetch_row();
            $probe->close();
            $has = ($row !== null && (int)$row[0] === 2);
        } catch (\Throwable $_e) { /* probe failure → fall through to empty tail */ }
        /* `b.`-qualified — same reason as _songbookBibSelect() above; the
           parent-songbook self-join makes any unqualified column ambiguous. */
        $this->_openLibrarySelectCache = $has
            ? ', b.OpenLibraryWorkId AS openLibraryWorkId, b.OpenLibraryEditionId AS openLibraryEditionId'
            : '';
        return $this->_openLibrarySelectCache;
    }
    private ?string $_openLibrarySelectCache = null;

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
        /* @disabled-visible: batch-attachment helper (#1765) — reads
           tblSongbookSeries/Membership for ALL requested abbreviations
           (including a disabled one, when $abbrs is null or names it) with
           no predicate of its own. Safe by construction: the ONLY callers
           (getSongbooks()/getSongbook()) already filtered `$books`/`$row`
           through `_bookVisible()` BEFORE calling this, and only ever index
           the returned map by an id that survived that filter — a disabled
           book's entry is minted here but never read back out. */
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
               thousands of rows.

               GROUP_CONCAT's usual footgun — silent truncation at
               `group_concat_max_len` (1024 bytes by default, and it warns
               rather than errors) — cannot bite here, because DISTINCT is
               applied to the 2–3 character PRIMARY subtag, not the full tag.
               Even a wildly multilingual book yields a handful of values. If
               this is ever widened to concatenate whole tags or titles, that
               limit becomes a real ceiling and the query needs revisiting.
               https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html#function_group-concat */
            $sql = "SELECT SongbookAbbr,
                           GROUP_CONCAT(
                               DISTINCT LOWER(SUBSTRING_INDEX(Language, '-', 1))
                               ORDER BY Language SEPARATOR ','
                           ) AS langs
                      FROM tblSongs
                     WHERE Language IS NOT NULL AND Language <> ''
                       AND " . $this->_visible('') . "
                     GROUP BY SongbookAbbr";   /* #1694 — a hidden song's language must not mint a chip */
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
     * tblSongbookCompilers joined to tblMusicians. Same shape +
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
        /* @disabled-visible: batch-attachment helper (#1765) — same
           safe-by-construction reasoning as _songbookSeriesMap() above: only
           getSongbooks()/getSongbook() call this, always AFTER
           _bookVisible() has already filtered which abbreviations they will
           ever index back out of the returned map. */
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
                          JOIN tblMusicians p ON p.Id = c.MusicianId
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
                          JOIN tblMusicians p ON p.Id = c.MusicianId
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
        /* @disabled-visible: batch-attachment helper (#1765) — same
           safe-by-construction reasoning as _songbookSeriesMap() above. */
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
     *   'musician' → tblMusicianExternalLinks      keyed by MusicianId (int)
     *
     * Schema-probed; pre-migration deployments get an empty map. The
     * registry join (tblExternalLinkTypes) drops link-type rows that
     * have been deactivated — IsActive = 0 acts as a soft delete.
     *
     * @param string         $entityType  'songbook' | 'song' | 'musician'
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
            case 'musician':
                $table   = 'tblMusicianExternalLinks';
                $entCol  = 'MusicianId';
                $joinSql = '';
                $keyExpr = 'el.MusicianId';
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
            /* #1694 — filtered: this powers the public parent-songbook deep
               link (pages/song.php), and a hidden song must not be linked to. */
            $stmt = $this->db->prepare(
                'SELECT SongId FROM tblSongs WHERE SongbookAbbr = ? AND Number = ? AND ' . $this->_visible('') . ' LIMIT 1'
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
     * ELI5: the same thing `getSongbooks()` returns, for one book.
     *
     * The SELECT below is `getSongbooks()`'s with a `WHERE` bolted on, and the
     * attachments are FOUR of its five maps scoped to `[$id]` — series,
     * compilers, alt names and external links. That duplication is intentional
     * (the bulk path must not be forced through a single-row query, nor vice
     * versa) but it means the two DRIFT SILENTLY: add a column or an
     * attachment to one and the songbook page and the home tile start
     * disagreeing with nothing failing. Change both, always.
     *
     * ⚠️ THEY HAVE ALREADY DRIFTED, and this comment previously asserted the
     * parity it was warning you to protect — which is worse than saying
     * nothing, because it stops you checking at exactly the point checking
     * pays. `getSongbooks()` also attaches `_songbookSongLanguagesMap()` and
     * emits a `languages` key; this method does NOT. Verified harmless TODAY —
     * the only `languages` readers (`includes/pages/home.php`,
     * `includes/pages/songbooks.php`) both come through the plural path — but
     * any future single-book caller wanting language badges gets an empty
     * `?? []` and no error. (The `colour`
     * fallback chain below is a worked example: #1181 had to be written twice.)
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
        $displayAbbrSelect = $this->_songbookDisplayAbbrSelect();
        $parentSelect = $this->_songbookParentSelect();
        $parentJoin   = $this->_songbookParentJoin();
        $flagsSelect  = $this->_songbookFlagsSelect();
        $openLibrarySelect = $this->_songbookOpenLibrarySelect();
        $stmt = $this->db->prepare(
            "SELECT b.Abbreviation AS id, b.Name AS name, b.SongCount AS songCount,
                    b.Colour AS colour,
                    b.IsOfficial      AS isOfficial,
                    b.Publisher       AS publisher,
                    b.PublicationYear AS publicationYear,
                    b.Copyright       AS copyright,
                    b.Affiliation     AS affiliation
                    {$displayAbbrSelect}
                    {$langSelect}
                    {$bibSelect}
                    {$parentSelect}
                    {$flagsSelect}
                    {$openLibrarySelect}
             FROM tblSongbooks b{$parentJoin}
             WHERE b.Abbreviation = ? AND " . $this->_bookVisible() . "
            "
        );
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        /* #1765 Feature 1 — a disabled book's row simply never matches the
           WHERE above (public mode), so this null-return is the SAME path
           an unknown abbreviation already takes: no distinguishing 404 vs
           410 copy, matching the plan's "don't invite probing" note. */
        if ($row === null) {
            return null;
        }
        $row['songCount']  = (int)$row['songCount'];
        $row['isOfficial'] = (bool)$row['isOfficial'];
        if (array_key_exists('isDisabled', $row)) {
            $row['isDisabled'] = (bool)$row['isDisabled'];
        }
        if (array_key_exists('isPublicDomain', $row)) {
            $row['isPublicDomain'] = (bool)$row['isPublicDomain'];
        }
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
            /* 1) self + own parent (if any). #1765 Feature 1 — a disabled
               SELF collapses this whole call to $empty (same "no
               distinguishing 404 vs 410" posture as getSongbook()); a
               disabled PARENT keeps self visible but its parentAbbr/
               parentName come back NULL via the gated join, mirroring
               _songbookParentJoin()'s reasoning above. */
            $stmt = $this->db->prepare(
                'SELECT b.Id, b.Abbreviation, b.Name,
                        b.ParentSongbookId, b.ParentRelationship,
                        p.Abbreviation AS parentAbbr, p.Name AS parentName
                   FROM tblSongbooks b
                   LEFT JOIN tblSongbooks p ON p.Id = b.ParentSongbookId AND ' . $this->_bookVisible('p') . '
                  WHERE b.Abbreviation = ? AND ' . $this->_bookVisible('b')
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
                  rendering a list can show "Spanish" / "Tswana" inline.
                  #1765 Feature 1 — a disabled child must not appear in this
                  list either; it's a songbook-grain read like any other. */
            $langTail = $this->_songbookLanguageSelect() === '' ? '' : ', b.Language AS language';
            $stmt = $this->db->prepare(
                "SELECT b.Abbreviation AS id, b.Name AS name,
                        b.ParentRelationship AS relationship
                        {$langTail}
                   FROM tblSongbooks b
                  WHERE b.ParentSongbookId = ?
                    AND " . $this->_bookVisible() . "
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
                  self). Skipped when this row has no parent. #1765
                  Feature 1 — same songbook-grain filter as children. */
            if ($parentId > 0) {
                $stmt = $this->db->prepare(
                    "SELECT b.Abbreviation AS id, b.Name AS name,
                            b.ParentRelationship AS relationship
                            {$langTail}
                       FROM tblSongbooks b
                      WHERE b.ParentSongbookId = ?
                        AND b.Id <> ?
                        AND " . $this->_bookVisible() . "
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
        /* #1765 Feature 1 — a disabled songbook must not appear in its
           series listing either; songbook-grain like every other read here. */
        $bookVisible = $this->_bookVisible();
        try {
            if (is_int($seriesIdOrSlug)) {
                $sql = "SELECT b.Abbreviation AS id, b.Name AS name,
                               m.SortOrder    AS sortOrder,
                               m.Note         AS note
                               {$langTail}
                          FROM tblSongbookSeriesMembership m
                          JOIN tblSongbooks b ON b.Id = m.SongbookId
                         WHERE m.SeriesId = ? AND {$bookVisible}
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
                         WHERE s.Slug = ? AND {$bookVisible}
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
                /* #1694 — a hidden song has no counterparts panel to hang off. */
                'SELECT SongbookAbbr, Number FROM tblSongs WHERE SongId = ? AND ' . $this->_visible('') . ' LIMIT 1'
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
                      WHERE s.Number = ? AND s.SongbookAbbr IN ($ph)
                        AND " . $this->_visible();   /* #1694 — hidden counterparts stay hidden */
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
     * ⚠️ NOT THE SAME METHOD as `getSongsSlimIndex()` a few lines below, despite
     * the names. This one PAGINATES (limit/offset + a matching `total` so a
     * caller can say "showing 50 of 812") and language-filters; that one
     * returns EVERY row of a scope in one go with no filtering, for clients
     * that keep their own index in memory (the PWA, the editor sidebars).
     * Picking the wrong one gives you a working page with subtly wrong paging.
     * The COUNT and the row query share `$whereClause` deliberately — if they
     * ever diverge, `total` starts lying and "load more" breaks.
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

        /* #1694 — hidden songs never reach the paginated index (and the COUNT
           above the page rows shares this $where, so `total` agrees). */
        $where[] = $this->_visible();

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
     * OPTIONAL SONGBOOK SCOPE (#1037). Pass an abbreviation to return only
     * that book's rows.
     *
     * ELI5: ask for one songbook instead of all of them when that's all you need.
     *
     * The songbook listing page needs ONE book, and without this it pulled all
     * ~14,500 rows and filtered them in PHP — better than the full hydration it
     * replaced, but still four times the necessary rows and a whole-table scan
     * on the second-biggest public page, on shared hosting. The three other
     * callers (the PWA's songs_index, and both editor sidebars) genuinely want
     * the whole catalogue and pass nothing, so this is purely additive.
     *
     * Ordering is UNCHANGED — the ORDER BY below is identical either way, which
     * is what lets a scoped call keep the exact per-book order callers already
     * rely on rather than re-deriving it.
     *
     * @param string|null $songbookAbbr Restrict to one tblSongbooks.Abbreviation;
     *                                  null (default) returns every song.
     * @return array<int,array<string,mixed>>
     */
    public function getSongsSlimIndex(?string $songbookAbbr = null): array
    {
        if (!$this->db) {
            throw new \RuntimeException('getSongsSlimIndex requires a live database connection.');
        }

        /* $pubCol is a hardcoded constant (allow-listed; rule #5) added only when
           the column exists (#1343-B) so the PWA index carries the permalink id. */
        $pubCol = $this->_hasPublicIdColumn() ? ', s.PublicId AS publicId' : '';
        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                       s.Language AS language,
                       s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic{$pubCol}
                FROM tblSongs s
                LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                WHERE " . $this->_visible();   /* #1694 — the PWA index and the
                editor sidebar both go dark on a hidden song (restore-first
                workflow); '1=1' un-migrated keeps the clause always valid */

        /* Scoped variant (#1037). The abbreviation is BOUND, never interpolated
           (rule #5) — it reaches here from a URL segment. */
        $scoped = ($songbookAbbr !== null && $songbookAbbr !== '');
        if ($scoped) {
            $sql .= " AND s.SongbookAbbr = ?";
        }

        $sql .= " ORDER BY s.SongbookAbbr ASC,
                         CASE WHEN sb.IsOfficial = 1 AND s.Number IS NOT NULL THEN 0 ELSE 1 END ASC,
                         s.Number ASC,
                         LOWER(s.Title) ASC";

        /* Two execution shapes for one SQL string, and the asymmetry is
           deliberate rather than an oversight:

           ELI5: when there's a value to fill in we use a prepared statement;
           when there isn't, we just run the query.

           The scoped branch MUST prepare + bind — `$songbookAbbr` arrives from
           a URL segment, and rule #5 admits no interpolated value ever. The
           unscoped branch has NO bound values at all (every fragment in $sql is
           a PHP constant), so `query()` skips a prepare/execute round-trip on
           what is the single biggest read in the app: ~14k rows for the PWA
           index and both editor sidebars. Do NOT "tidy" this into one prepared
           path without measuring it. */
        $rows = [];
        if ($scoped) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $songbookAbbr);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $this->db->query($sql);
        }

        /* Not a false-check (STRICT mode throws instead of returning false —
           the #1228 red flag); it narrows the union type the two branches
           produce so static analysis and the loop below agree. */
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $row['number']        = normaliseSongNumber($row['number']);
                $row['hasAudio']      = (bool)$row['hasAudio'];
                $row['hasSheetMusic'] = (bool)$row['hasSheetMusic'];
                $rows[] = $row;
            }
            $res->free();
        }
        if ($scoped) {
            $stmt->close();
        }
        return $rows;
    }

    /**
     * Get all songs, optionally filtered by songbook.
     *
     * When the hidden 'public_domain_only' feature flag is enabled,
     * only songs with lyrics_public_domain = 1 are returned.
     *
     * ⚠️ PASS A SONGBOOK. ELI5: this returns WHOLE songs — every verse, every
     * credit — so asking for all of them at once is how the site runs out of
     * memory.
     *
     * This is the FULL hydration: NINE bulk side-table loads on top of the
     * main SELECT. Scoped to one book (`getSongs('MP')`) that is exactly right
     * and is what `songbook_export`, `bulk_songs`, `bulk_audio` and the
     * editor's per-book load all use. Called with `null` it materialises the
     * entire corpus — the ~140 MB PHP-array shape that OOM-killed shared
     * hosting in #929 and that CLAUDE.md rule #17 exists to forbid; the
     * whole-corpus `exportAsJson()` / `songs.json` cache it used to feed was
     * deleted in WS-J #1020 and must not come back in another guise.
     * `api.php:1474` carries the matching caller-side rule-#17 comment.
     *
     * If you want a list rather than the contents, the scoped replacements are
     * `getSongsSlimIndex($abbr)` (id/number/title only) and `getSongsIndex()`
     * (a paginated window) — `includes/pages/songbook.php` moved to the former
     * in #1037 for precisely this reason.
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

        /* #1694 — soft-deleted songs are invisible to every consumer of this
           bulk read (songbook page, songbook_export, bulk_songs, bulk_audio,
           sitemap). Unconditional: '1=1' un-migrated, so $where is now never
           empty and the clause below always renders a WHERE. */
        $where[] = $this->_visible();

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
        $idSelect  = $this->_songIdentitySelect();   /* #1750 — gated, may be '' pre-migration; same fold as _fetchSongRow() */
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
                       {$idSelect}
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
            /* #1750 — same always-present, shape-blind defaults as
               _fetchSongRow() (rule #22, ONE fold conceptually — the two
               call sites can't literally share a loop body since one is a
               single row and the other iterates $result, but the five
               lines are identical by design; see _fetchSongRow() for the
               full rationale). */
            $row['subtitle']           = (string)($row['subtitle'] ?? '');
            $row['disambiguation']     = (string)($row['disambiguation'] ?? '');
            $row['firstPublishedYear'] = isset($row['firstPublishedYear']) && $row['firstPublishedYear'] !== null
                ? (int)$row['firstPublishedYear'] : null;
            $row['copyrightYears']     = (string)($row['copyrightYears'] ?? '');
            $row['copyrightHolder']    = (string)($row['copyrightHolder'] ?? '');
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
           (≈3,600 songs) this cuts thousands of round-trips down to nine
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
     * JOIN in musician.php; only the (small) matched set is then hydrated, one
     * record at a time, via the canonical getSongById().
     *
     * ELI5: "show me everything this person wrote or composed", without
     * loading the whole catalogue to find it.
     *
     * WHY NAME VARIANTS RATHER THAN AN ID: there is no FK from
     * `tblSongWriters` / `tblSongComposers` to `tblMusicians` today — the
     * link is a name-string match. A caller assembles the spellings a
     * person is credited under and passes them all; see `getMusician()`'s
     * section header, which reads the same tables the same way and must be
     * changed in step if that FK is ever added.
     *
     * CALLER-LESS SINCE #1741 P4a-3 (#1754 doc-note): the historic caller,
     * `includes/pages/writer.php`, was retired when P4a-3 consolidated the
     * writer page into the musician profile — this method has had no caller
     * since. RETAINED DELIBERATELY (#1754) as the documented scoped-read
     * exemplar of rule #17 — credit-name → bounded id set → per-record
     * `getSongById()` hydration, never a corpus materialisation — and as the
     * ready-made read for a future credits API action. Do not resurrect a
     * whole-corpus scan instead of reusing this; do not delete without an
     * issue.
     *
     * @param string[] $nameVariants Candidate names in any case; matched
     *                 case-insensitively against tblSongWriters /
     *                 tblSongComposers `.Name`.
     * @return array<int,array> Full song records (same shape as getSongs()).
     * @see #1754
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
        /* #1694 — the OR pair is PARENTHESISED so the visibility predicate
           applies to BOTH branches (AND binds tighter than OR — appended bare,
           it would only filter the composer branch). */
        $sql = "SELECT DISTINCT s.SongId
                  FROM tblSongs s
                 WHERE (s.SongId IN (SELECT SongId FROM tblSongWriters   WHERE LOWER(Name) IN ($placeholders))
                    OR s.SongId IN (SELECT SongId FROM tblSongComposers WHERE LOWER(Name) IN ($placeholders)))
                   AND " . $this->_visible();
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
     * ELI5: someone typed or bookmarked an id; work out which song they meant,
     * and — this is the important half — refuse to guess when guessing could
     * hand back the wrong song.
     *
     * FOUR RESOLUTION STRATEGIES, IN THIS ORDER. The order is the design:
     *
     *   1. EXACT `SongId` (the PK) — first, so a legacy `/song/<SongId>` link
     *      resolves even if every later strategy would have misfired.
     *   2. OPAQUE `PublicId` permalink (#1343-B) — only for hyphen-less
     *      all-caps input, and only when the column exists.
     *   3. SUPPRESSION CHECKS — a redirect row (#1689) or a soft-deleted row
     *      (#1694) that CLAIMS this id stops resolution dead and returns null.
     *   4. NUMBER HEURISTIC (`getSongByNumber()`) — the forgiving fallback for
     *      drift between a song's `Number` and the digits in its SongId.
     *
     * Strategy 4 is the dangerous one and 3 is its leash. `(SongbookAbbr,
     * Number)` is NON-unique and `Number` is editable independently of the id,
     * so for a dead or moved id the heuristic can match whatever occupies that
     * slot NOW — HTTP 200, wrong song, nothing logged anywhere. Returning null
     * from step 3 rather than following the redirect here is also deliberate;
     * the reasoning is spelled out at the guard itself.
     *
     * Note what this method does NOT do: it does not gate content by tier. A
     * record returned here is stripped of gated fields later by
     * `contentGatingApply()` (CLAUDE.md rule #28) — visibility (#1694) and
     * entitlement are separate concerns and are enforced in separate places.
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

        /* Try exact match first (fast path) — SongId is the PK; this runs FIRST so
           legacy /song/<SongId> resolves even if every PublicId path below failed. */
        $song = $this->_fetchSongRow($id);

        /* #1343-B — opaque PublicId (IHUID) permalink: uppercase, hyphen-less, not a
           SongId. Gated on the column existing (un-migrated env skips it cleanly). */
        if ($song === null
            && strpos($id, '-') === false
            && preg_match('/^[0-9A-Z]{6,32}$/', $id) === 1
            && $this->_hasPublicIdColumn()) {
            $resolvedSongId = $this->_songIdForPublicId($id);
            if ($resolvedSongId !== null) {
                $song = $this->_fetchSongRow($resolvedSongId);
            }
        }

        /* No exact match — try normalized matching */
        if ($song === null && preg_match('/^([A-Z]+)-0*(\d+)$/', $id, $matches)) {
            $prefix = $matches[1];
            $number = (int)$matches[2];

            /* #1689 — A REDIRECT OUTRANKS THIS HEURISTIC.
             *
             * ELI5: if we have a forwarding note for this id, use it — don't
             * hand back whoever happens to have that number now.
             *
             * The fallback below exists to be forgiving about drift between a
             * song's `Number` and the digits in its SongId. But `tblSongs`'
             * `(SongbookAbbr, Number)` index is NON-UNIQUE and a Number can be
             * edited independently of the id (v2 `metadata_field_update
             * field=number`, v1 `#edit-number`), so for a DEAD or MOVED id it
             * can match whatever now occupies that slot in that book. The
             * result was HTTP 200 with a DIFFERENT SONG and no `redirectedFrom`
             * — silently wrong, which is the class this codebase keeps getting
             * caught by, and it got likelier when #1679 made re-keying a routine
             * curation action.
             *
             * A redirect row is a DEFINITE STATEMENT about where this id went; a
             * number match is a guess. The definite statement wins.
             *
             * Returning NULL rather than resolving the redirect here is
             * deliberate. Both readers — `api.php`'s song_detail/song_data and
             * `includes/pages/song.php` — already consult the redirect layer on
             * a null, and they need to KNOW they redirected so they can emit
             * `redirectedFrom` (so a client can rewrite what it stored) or the
             * SPA's [data-song-redirect] marker, and so a tombstone answers 410
             * rather than 404. Following it silently here would return the right
             * song while destroying that contract — a subtler regression than
             * the bug being fixed. So this only ever SUPPRESSES the guess and
             * lets the existing, correct path answer.
             *
             * Cost lands only on requests that already missed twice, and the
             * probe fails OPEN: an un-migrated install (where no redirect can
             * exist) and a transient probe failure both degrade to exactly the
             * pre-#1689 behaviour rather than turning a working page into a 404.
             */
            if ($this->db instanceof \mysqli) {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_redirects.php';
                if (songRedirectClaimsId($this->db, $id)) {
                    return null;
                }
                /* #1694 — a SOFT-DELETED id suppresses the guess too, for the
                 * same #1689 reason: the filtered exact read above just MISSED
                 * a row that still EXISTS, and falling through would serve
                 * whatever now holds that number — 200 OK, wrong song, no
                 * error anywhere.
                 *
                 * ELI5: if this exact song is merely hidden, say "nothing
                 * here" instead of guessing a lookalike.
                 *
                 * A SEPARATE `if`, deliberately NOT a `||` widening of the
                 * redirect-claim condition above: test-song-redirect-claim.php
                 * asserts that `return null` is gated on songRedirectClaimsId()
                 * ALONE (an added conjunct can only narrow when the
                 * suppression fires, so the guard rejects any widening). The
                 * probe fails OPEN — un-migrated installs and transient
                 * failures degrade to today's fallback, never to a 404. */
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
                if (songSoftDeletedHolds($this->db, $id)) {
                    return null;
                }
            }

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
            /* #1694 — the number heuristic must never pick a hidden song. */
            "SELECT SongId FROM tblSongs WHERE SongbookAbbr = ? AND Number = ? AND " . $this->_visible('') . " LIMIT 1"
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
            'externalIds',   /* #1750 / #1741 D5 — recording/release external-ID store, opt-in */
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
                          . 'FROM tblTunes t JOIN tblSongs s ON s.TuneId = t.Id WHERE s.SongId = ? '
                          . 'AND ' . $this->_visible() . ' LIMIT 1',   /* #1694 — belt-and-braces; callers already resolved a visible song */
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
                              . 'SingerName AS singerName, Gender AS gender, MusicianId AS musicianId '
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

                    case 'externalIds':   /* #1750 / #1741 D5 — recording/release external-ID store, opt-in */
                        /* ELI5: extra "this song is also known as ISRC/Spotify-ID/
                           MusicBrainz-ID X" rows, only sent when a client explicitly
                           asks for them.
                           DETAILED: SourceRef is deliberately NOT selected — it is
                           an internal idempotency/ownership reference (see
                           includes/song_external_ids.php's ownership model), never
                           meant to reach the wire. Same try/catch-per-block +
                           omit-when-empty pattern as every sibling case above, so an
                           un-migrated install (tblSongExternalIds absent) degrades to
                           a clean omission rather than a 500. */
                        $rows = $this->_extrasRows(
                            'SELECT IdScope AS idScope, IdType AS idType, IdValue AS idValue, Source AS source '
                          . 'FROM tblSongExternalIds WHERE SongId = ? ORDER BY IdScope, IdType, IdValue',
                            's', [$songId]
                        );
                        if ($rows) { $out['externalIds'] = $rows; }
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
     * @param string       $query         Search query string
     * @param string|null  $songbookId    Limit search to a specific songbook
     * @param int          $limit         Maximum results to return (0 = no limit)
     * @param int          $offset        Pagination offset into the result set
     * @param bool         $includeLyrics Search (and snippet) song bodies too,
     *                                    not just titles — mirrors the public
     *                                    "search within lyrics" toggle
     * @param list<string> $langSubtags   Preferred-language subtags ([] = no
     *                                    filter), pushed into the FULLTEXT/LIKE
     *                                    SQL via applyLanguageFilterSql() so
     *                                    LIMIT/OFFSET paginate over the already-
     *                                    filtered set (#1639 — filtering AFTER a
     *                                    fixed-size fetch under-reports how many
     *                                    matches remain, breaking `hasMore`).
     *                                    Only the two paginated passes below
     *                                    (FULLTEXT + LIKE) honour this; the
     *                                    zero-result last-resort fallbacks
     *                                    (writer/composer, SOUNDEX) and the
     *                                    page-1-only curated merge (alt-title,
     *                                    scripture-tag) don't carry an offset
     *                                    and stay unfiltered, same as before
     *                                    this fix — an intentionally narrow,
     *                                    documented trade-off (mirrors the
     *                                    api.php popular_songs/song_history
     *                                    comments), not an oversight.
     * @return array Matching song objects
     */
    public function searchSongs(string $query, ?string $songbookId = null, int $limit = 50, int $offset = 0, bool $includeLyrics = true, array $langSubtags = []): array
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
            $results = $this->_searchByLike($query, $songbookId, $limit, $offset, $includeLyrics, $langSubtags);
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
            $results = $this->_runFulltextSearch($primary, $matchCols, $songbookId, $limit, $offset, $langSubtags);

            /* D2 hybrid — step 2: if requiring every term found nothing,
               broaden to ANY term (drop the +) so a single mistyped
               token doesn't sink the whole query. */
            if (empty($results)) {
                $loose   = self::_booleanPrefixExpr($tokens, false);
                $results = $this->_runFulltextSearch($loose, $matchCols, $songbookId, $limit, $offset, $langSubtags);
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
    private function _runFulltextSearch(string $ftQuery, string $matchCols, ?string $songbookId, int $limit, int $offset, array $langSubtags = []): array
    {
        if (trim($ftQuery) === '') {
            return [];
        }

        $where  = ["MATCH({$matchCols}) AGAINST(? IN BOOLEAN MODE)"];
        $params = [$ftQuery];
        $types  = 's';

        $where[] = $this->_visible();   /* #1694 — hidden songs never match */

        if ($songbookId !== null) {
            $where[]  = 's.SongbookAbbr = ?';
            $params[] = $songbookId;
            $types   .= 's';
        }

        /* #1639 — push the preferred-language filter into the WHERE clause
           (same helper + binding shape as getSongsIndex(), the established
           pattern) rather than trimming rows out of the LIMIT/OFFSET window
           after the fact — that's what made `hasMore` lie. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'language_filter.php';
        [$langWhere, $langTypes, $langParams] = applyLanguageFilterSql('s.Language', $langSubtags);
        $params = array_merge($params, $langParams);
        $types .= $langTypes;

        $limitClause = $limit > 0 ? 'LIMIT ? OFFSET ?' : '';
        $whereClause = implode(' AND ', $where) . $langWhere;

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
    private function _searchByLike(string $query, ?string $songbookId, int $limit, int $offset, bool $includeLyrics, array $langSubtags = []): array
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

        $where[] = $this->_visible();   /* #1694 — hidden songs never match */

        if ($songbookId !== null) {
            $where[]  = 's.SongbookAbbr = ?';
            $params[] = $songbookId;
            $types   .= 's';
        }

        /* #1639 — same SQL-level language filter as _runFulltextSearch()
           above, so the sub-3-char / LIKE-fallback pass paginates over the
           already-filtered set too. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'language_filter.php';
        [$langWhere, $langTypes, $langParams] = applyLanguageFilterSql('s.Language', $langSubtags);
        $params = array_merge($params, $langParams);
        $types .= $langTypes;

        $limitClause = $limit > 0 ? 'LIMIT ? OFFSET ?' : '';
        $whereClause = implode(' AND ', $where) . $langWhere;

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

            $where[] = $this->_visible();   /* #1694 — hidden songs never match */

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
                      WHERE a.Title LIKE ?
                        AND ' . $this->_visible();   /* #1694 — hidden songs never match */
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

    /**
     * Find songs tagged with the given scripture reference (#397).
     *
     * Matches `Name = <reference>` (e.g. "Psalm 23"), `Name = <book>`
     * (e.g. "Psalm"), or the kebab-case slug form. Curators tag songs
     * via /manage/editor/ (tags UI) and the hit merges into search
     * results for scripture-style queries.
     *
     * (This doc-block was stranded ONE function above its own definition for
     * some time — it now sits where it belongs. Worth a glance whenever a
     * doc-block here seems to describe the wrong thing. The irony of this
     * sentence originally saying "two" is not lost on anyone.)
     *
     * ELI5: someone searched "Ps 23"; find the songs a curator has explicitly
     * tagged with that passage, so a deliberate tag beats a fuzzy text match.
     *
     * FOUR CANDIDATES, ONE `IN` PAIR: the caller passes the already-expanded
     * canonical reference ("Psalm 23"), from which the base book ("Psalm") is
     * derived by stripping a trailing chapter/verse; each is matched against
     * both `t.Name` and its slug form. That is why the bound list is four
     * values for two columns.
     *
     * NOT paginated and NOT language-filtered — it is a page-1-only curated
     * merge (see `_mergeCuratedHits()`), which is why the #1639 language
     * filter that the two paginated passes carry is absent here by design.
     */
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
                 WHERE (t.Name IN (?, ?) OR t.Slug IN (?, ?))
                   AND " . $this->_visible();
        /* #1694 — hidden songs never match. The Name/Slug OR pair is now
           PARENTHESISED: appended AND clauses used to bind only to the Slug
           branch (AND binds tighter than OR), which also means the pre-existing
           `AND s.SongbookAbbr = ?` scope below never applied to Name matches —
           a latent precedence bug this parenthesisation fixes as a side effect. */
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
                /* The only SONG-ROW hydrator in this file that open-codes the number
                   cast instead of calling normaliseSongNumber(). It agrees on
                   NULL but not on a stored 0 or '' — those reach the client as
                   0 here and as null everywhere else (#797). Harmless in
                   practice because 0 is not a legal hymn number, but if you are
                   touching this line, prefer the shared helper. */
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
               AND " . $this->_visible() . "
             ORDER BY s.Number"
        );   /* #1694 — number search must not surface hidden songs */
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
        /* #1694 — the shuffle pool excludes hidden songs (a random pick that
           lands on one would 404 downstream when _fetchSongRow filters it). */
        if ($songbookId !== null) {
            $songbookId = strtoupper(trim($songbookId));
            $stmt = $this->db->prepare(
                "SELECT SongId FROM tblSongs WHERE SongbookAbbr = ? AND " . $this->_visible('') . " ORDER BY RAND() LIMIT 1"
            );
            $stmt->bind_param('s', $songbookId);
        } else {
            $stmt = $this->db->prepare(
                "SELECT SongId FROM tblSongs WHERE " . $this->_visible('') . " ORDER BY RAND() LIMIT 1"
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
           (this method) or getMeta()'s own live COUNT(*).

           ⚠️ This comment used to end "(getMeta() feeds exportAsJson())".
           `exportAsJson()` was DELETED in WS-J #1020 — there is no static
           cache and no whole-corpus materialiser any more (CLAUDE.md rule
           #17). The sentence survived the deletion and described machinery
           that no longer exists. */
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
     * ELI5: "this hymnal goes up to 800 — which numbers haven't been typed in
     * yet?" Powers `/manage/missing-numbers` and the `missing_numbers` API.
     *
     * THE RANGE IS DERIVED, NOT DECLARED. There is no "this book has 800
     * hymns" field anywhere, so `maxNumber` is simply the highest number
     * PRESENT. Consequence worth knowing before trusting the output: gaps at
     * the END are invisible. A book whose last twenty hymns are all missing
     * reports no gap at all, and adding hymn 800 to a book that stops at 500
     * instantly "creates" 299 missing numbers. It is a completeness hint for a
     * curator, not an authoritative to-do list.
     *
     * @param string $songbookId Songbook abbreviation (e.g., 'CP')
     * @return array{missing: int[], maxNumber: int, totalExisting: int, songbook: string}
     */
    public function getMissingSongNumbers(string $songbookId): array
    {
        $songbookId = strtoupper(trim($songbookId));

        /* Get all existing song numbers for this songbook.

           @deleted-visible: a soft-deleted song's number slot is still
           OCCUPIED (#1694). The (SongbookAbbr, Number) index is NON-unique, so
           if this read filtered, a hidden song's number would be listed as
           "missing", a curator would fill the gap, and restoring the original
           would produce a duplicate number with nothing to stop it. Physical
           occupancy is the correct answer here.

           @disabled-visible: admin surface (#1765) — this powers
           `/manage/missing-numbers` (called via `SongData::forAdmin()`) and
           the editor+-role-gated `missing_songs` API action; number-slot
           occupancy is likewise PHYSICAL regardless of whether the book is
           disabled — the same reasoning as the soft-delete marker
           immediately above, one predicate over. */
        $stmt = $this->db->prepare(
            "SELECT Number FROM tblSongs WHERE SongbookAbbr = ? ORDER BY Number"
        );
        $stmt->bind_param('s', $songbookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $existing = [];
        while ($row = $result->fetch_assoc()) {
            /* Raw `(int)` here, NOT normaliseSongNumber() — the one place in
               this file that is right to differ.

               ELI5: we only care which numbered slots are taken, so a song
               with no number just becomes a harmless 0.

               `Number` is nullable and NULL casts to 0, which the gap scan
               below ignores because it starts at 1. Using the null-preserving
               helper would mean handling null inside max()/array_flip() for no
               gain. The visible side effect: `totalExisting` counts ROWS, so a
               book with unnumbered entries reports more "present" than it has
               occupied slots — and, because this query is deliberately
               unfiltered, soft-deleted songs count too. Both are consistent
               with what this method measures (physical occupancy), but neither
               is what "present" sounds like on the admin page. */
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
     * ELI5: the one place a single complete song is built. Everything that
     * hands you "a song" — the public page, the API, print, random, the
     * counterpart panel — ends up here.
     *
     * BEING THE FUNNEL IS THE POINT. `getSongById()`, `getSongByNumber()` and
     * `getRandomSong()` all delegate here, so the `_visible()` predicate on the
     * SELECT below is a SINGLE per-record visibility gate (#1694) rather than
     * three that can drift apart. Add a new single-song entry point and it
     * should call this, not re-issue its own SELECT.
     *
     * SHAPE CONTRACT: the returned array must match what `getSongs()` produces
     * per row, because clients (the editor, the exporters, `pages/song.php`)
     * consume both interchangeably. The two therefore attach the same keys by
     * different means — this one via the per-song helpers (one round-trip each
     * is cheaper than building placeholder lists for a single id), `getSongs()`
     * via the `*Map()` bulk loaders. Adding a key to either without the other
     * is the classic way this file drifts.
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
        $pubSelect    = $this->_hasPublicIdColumn() ? ', s.PublicId AS publicId' : '';   /* #1343-B (gated) */
        $idSelect     = $this->_songIdentitySelect();   /* #1750 — gated, may be '' pre-migration */
        $stmt = $this->db->prepare(
            "SELECT s.SongId AS id, s.Number AS number, s.Title AS title, s.SongbookAbbr AS songbook,
                    sb.Name AS songbookName, s.Language AS language, s.Copyright AS copyright,
                    s.TuneName AS tuneName, s.Ccli AS ccli, s.Iswc AS iswc,
                    s.Verified AS verified, s.LyricsPublicDomain AS lyricsPublicDomain,
                    s.MusicPublicDomain AS musicPublicDomain,
                    s.HasAudio AS hasAudio, s.HasSheetMusic AS hasSheetMusic
                    {$arrSelect}
                    {$placeSelect}
                    {$pubSelect}
                    {$idSelect}
             FROM tblSongs s
             LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
             WHERE s.SongId = ? AND " . $this->_visible() . "
             LIMIT 1"
        );   /* #1694 — THE per-record gate: every single-song read funnels here */
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
        /* #1750 — always-present, shape-blind defaults for the five #1741 P1
           identity columns (mirrors getWork()'s absent-column defaults,
           SongData.php ~5040-5052): keys exist on the returned array
           whether or not the backing column exists on this install yet, so
           #1752's strict native decoders get a stable contract on every
           install. */
        $row['subtitle']           = (string)($row['subtitle'] ?? '');
        $row['disambiguation']     = (string)($row['disambiguation'] ?? '');
        $row['firstPublishedYear'] = isset($row['firstPublishedYear']) && $row['firstPublishedYear'] !== null
            ? (int)$row['firstPublishedYear'] : null;
        $row['copyrightYears']     = (string)($row['copyrightYears'] ?? '');
        $row['copyrightHolder']    = (string)($row['copyrightHolder'] ?? '');
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
        /* #1355 — de-duplicate each people-credit list case-insensitively (order
           preserved, first occurrence wins). A person credited twice in the same
           role (a duplicate tblSong<Role> row) must surface ONCE — both in each row
           and in the song-view "Words & Music" combine (#603). Fixed at the source so
           the public song page, print (song_data) and export all dedupe alike. */
        foreach (['writers', 'composers', 'arrangers', 'adaptors', 'translators', 'artists'] as $_creditKey) {
            if (empty($row[$_creditKey]) || !is_array($row[$_creditKey])) { continue; }
            $_seenCredit = [];
            $row[$_creditKey] = array_values(array_filter(
                array_map(static fn($n) => trim((string)$n), $row[$_creditKey]),
                static function (string $n) use (&$_seenCredit): bool {
                    if ($n === '') { return false; }
                    $k = mb_strtolower($n);
                    if (isset($_seenCredit[$k])) { return false; }
                    $_seenCredit[$k] = true;
                    return true;
                }
            ));
        }
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
    /* =====================================================================
     * CREDIT ACCESSORS (#1158 annotation backfill) — the per-role name lists
     * ---------------------------------------------------------------------
     * ELI5: six little lists of who did what on a song — who wrote the words,
     * who wrote the tune, who arranged it, and so on — read straight off the
     * song's credit tables in the order the curator entered them.
     *
     * WHY the shape is what it is (the shared rationale, documented ONCE here
     * rather than repeated on each near-identical method):
     *
     *  - Each role has its OWN table (tblSongWriters / tblSongComposers /
     *    tblSongArrangers / tblSongAdaptors / tblSongTranslators, plus
     *    tblSongArtists for performers) — a per-role table, not one table with
     *    a Role column, because that is the legacy shape every writer/importer
     *    already targets. Each row is just a denormalised NAME STRING, not an
     *    FK to tblMusicians; linking those names to the musician registry is a
     *    SEPARATE concern (#1741 identity, #1784 byte-reconciliation) that must
     *    never be conflated with simply listing a song's credits.
     *  - `ORDER BY Id` is deliberate and load-bearing: Id is insertion order,
     *    so it preserves the CURATOR'S intended credit order (lead writer
     *    first, …). Sorting alphabetically here would silently reorder credits
     *    on every public render.
     *  - Each accessor has a SINGLE-song form (`_getX`) AND a bulk map form
     *    (`_getXMap`, keyed SongId → name[]). The map forms exist purely to
     *    kill the N+1 that `getSongs()` (a whole songbook, ~hundreds of rows)
     *    would otherwise hit — one `WHERE SongId IN (…)` query per role instead
     *    of one query per song per role. `getSongById()` uses the single form;
     *    `getSongs()` uses the maps. They MUST stay behaviourally identical
     *    (same table, same order) or a song's credits would differ between the
     *    list view and the detail view.
     * ===================================================================== */

    /**
     * Writer (lyricist) names for one song, in curator-entered order.
     *
     * @param  string   $songId
     * @return string[] Writer names (may be empty).
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

    /** #1343-B — single-flight probe for tblSongs.PublicId (gated reads/STRICT). */
    private function _hasPublicIdColumn(): bool
    {
        if ($this->_publicIdColumnChecked) {
            return $this->_publicIdColumn;
        }
        $this->_publicIdColumnChecked = true;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongs'
                    AND COLUMN_NAME  = 'PublicId' LIMIT 1"
            );
            $stmt->execute();
            $this->_publicIdColumn = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $this->_publicIdColumn = false;
        }
        return $this->_publicIdColumn;
    }

    /** #1343-B — resolve an opaque PublicId to its SongId (PK), or null. Bound;
     *  caller has already gated on _hasPublicIdColumn(). */
    private function _songIdForPublicId(string $publicId): ?string
    {
        /* @deleted-visible: pure id RESOLVER (#1694) — the follow-up
           _fetchSongRow() applies the visibility filter, and a deleted song's
           PublicId must still resolve here or songSoftDeletedHolds() could
           never recognise it (and the 410 answer would decay to a 404).
           @disabled-visible: same reasoning, one predicate over (#1765) — a
           PublicId belonging to a song in a disabled songbook must still
           resolve to a SongId here; _fetchSongRow()'s `_visible()` call is
           what actually hides it from the public response. */
        $stmt = $this->db->prepare('SELECT SongId FROM tblSongs WHERE PublicId = ? LIMIT 1');
        $stmt->bind_param('s', $publicId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? (string)$row['SongId'] : null;
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
        /* #1235 P4b (read switch) — the normalised tblLyricLines mirror is now
           AUTHORITATIVE for reads: assemble components from it via the shared
           line-first reader (no LinesJson read). Component metadata
           (type/number/language) still comes from the THIN tblSongComponents inside
           the assembler (C-variant); chords are recomposed from the per-line mirror.
           Output is byte-identical to the P2a path — proven corpus-wide by the G2
           cutover gate (appWeb/.sql/verify-lyrics-cutover.php). The LinesJson path survives
           ONLY as the un-migrated-install fallback (no mirror table yet). */
        if ($this->_hasLyricLinesMirror()) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
            return lyricLinesAssembleComponents($this->db, $songId);
        }
        return $this->_getComponentsFromJson($songId);
    }

    /**
     * Legacy fallback for an un-migrated install with no tblLyricLines mirror: build
     * components straight from tblSongComponents.LinesJson (no per-line Ids — there is
     * no stable line identity without the mirror). Once the P1 mirror exists this is
     * never reached; the column-existence guard means the same deployed code keeps
     * working before and after the P4d JSON-column drop.
     */
    private function _getComponentsFromJson(string $songId): array
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

        $components = [];
        foreach ($rows as $row) {
            $components[] = [
                'type'     => $row['type'],
                'number'   => (int)$row['number'],
                'lines'    => json_decode($row['lines_json'], true) ?? [],
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
    /**
     * Bulk-load writers keyed by SongId — the N+1-avoiding form of
     * `_getWriters()` for `getSongs()`. `ORDER BY SongId, Id` keeps each song's
     * writers in curator order within the grouped result. The exemplar the
     * other five `_get*Map()` accessors follow (see the section note above).
     *
     * @param  string[] $songIds
     * @return array<string,string[]> SongId → writer names.
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
        /* #1235 P4b (read switch) — assemble from the authoritative tblLyricLines
           mirror (batched + chunked) when present; LinesJson only for an un-migrated
           install. Kept in lockstep with _getComponents() so getSongs() and
           getSongById() agree. */
        if ($this->_hasLyricLinesMirror()) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
            return lyricLinesAssembleComponentsMap($this->db, $songIds);
        }
        return $this->_getComponentsMapFromJson($songIds);
    }

    /** Legacy no-mirror fallback for _getComponentsMap(); see _getComponentsFromJson(). */
    private function _getComponentsMapFromJson(array $songIds): array
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

        $map = [];
        foreach ($rows as $row) {
            $sid = $row['SongId'];
            $map[$sid][] = [
                'type'     => $row['type'],
                'number'   => (int)$row['number'],
                'lines'    => json_decode($row['lines_json'], true) ?? [],
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
        /* Delegate the actual probe to the shared assembler helper so the
           INFORMATION_SCHEMA query lives in ONE place (modularity rule); the
           instance flag above keeps it a thin per-request cache. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
        $this->_lyricLinesMirror = lyricLinesMirrorPresent($this->db);
        return $this->_lyricLinesMirror;
    }

    /* #1235 P4b — _mirrorLinesByComponent[Map]() were REMOVED here: the shared
       assembler in includes/lyric_lines_read.php (lyricLinesAssembleComponents / …Map)
       is now the ONE line reader, and the read switch above delegates to it. The
       _hasLyricLinesMirror() probe above stays — it gates that delegation. */

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

        $where[] = $this->_visible();   /* #1694 — hidden songs never match */

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
     * Cached per-column presence probe over the 9 #1741 P1/D5 tblWorks
     * "extra" columns (Ccli/Bowi/Subtitle/Disambiguation/TuneName/TuneId/
     * FirstPublishedYear/CopyrightYears/CopyrightHolder) — §0.3.2's cached
     * per-column idiom, generalised from `_hasWorksSchema()` above (which
     * only ever asked a table-level yes/no question).
     *
     * ELI5: "of these nine optional columns, which ones actually exist on
     * THIS database right now?" — asked once per request (migrations are
     * web-run, not guaranteed applied — a fresh `tblWorks` from #840 may
     * predate the #1741 P1/D5 batches that added these), remembered for
     * every subsequent `getWork()` call in the same request.
     *
     * DETAILED / WHY PER-COLUMN, NOT ALL-OR-NOTHING: `Bowi` shipped in a
     * DIFFERENT migration (`migrate-work-bowi.php`, #1741 D5) than the
     * other eight (`migrate-works-identity.php`, #1741 P1) — an install
     * that has applied one card but not the other is a real, supported
     * state, not a corrupt one. A single boolean "has the P1 batch landed?"
     * would either wrongly hide Bowi on a P1-only install or wrongly try to
     * SELECT a column that isn't there yet and throw. One
     * INFORMATION_SCHEMA.COLUMNS query naming all nine returns exactly the
     * present subset, so the caller (`getWork()`) can build its SELECT
     * fragment from whichever subset actually exists — mirrors the cached-
     * table-probe idiom immediately above, one level more granular.
     *
     * @return array<string,true> present column names as keys (a set, not
     *                             a list — `isset($cols['Ccli'])` reads
     *                             cleanly at every call site).
     * @link .claude/catalogue-1741-P4-plan.md §2.2 item 1
     * @link appWeb/.sql/migrate-works-identity.php  the P1 batch (8 of the 9 columns)
     * @link appWeb/.sql/migrate-work-bowi.php       the D5 batch (Bowi, gated independently)
     */
    private function _worksExtraCols(): array
    {
        if ($this->_worksExtraColsCache !== null) {
            return $this->_worksExtraColsCache;
        }
        /* Hardcoded constant column names (rule #5's carve-out) — never
           built from request input. */
        $cols = [
            'Ccli', 'Bowi', 'Subtitle', 'Disambiguation', 'TuneName', 'TuneId',
            'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder',
        ];
        $present = [];
        try {
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $this->db->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks'
                    AND COLUMN_NAME IN ($ph)"
            );
            $types = str_repeat('s', count($cols));
            $stmt->bind_param($types, ...$cols);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $present[(string)$row['COLUMN_NAME']] = true;
            }
            $stmt->close();
        } catch (\Throwable $_e) {
            $present = [];
        }
        return $this->_worksExtraColsCache = $present;
    }
    /** @var array<string,true>|null cached probe result */
    private ?array $_worksExtraColsCache = null;

    /**
     * #1750 — instance-cached existence probe for the five #1741 P1 "song
     * identity" columns on `tblSongs` (Subtitle, Disambiguation,
     * FirstPublishedYear, CopyrightYears, CopyrightHolder).
     *
     * ELI5: "of these five optional columns, which ones actually exist on
     * THIS database right now?" — asked once per request (migrations are
     * web-run, not guaranteed applied — see
     * `appWeb/.sql/migrate-song-identity-fields.php`), remembered for every
     * subsequent `_songIdentitySelect()` call in the same request.
     *
     * DETAILED / WHY A SEPARATE PROBE FROM `_worksExtraCols()`, NOT A SHARED
     * ONE: same clone-the-shape idea as that method (byte-for-byte pattern:
     * hardcoded IN-list, one INFORMATION_SCHEMA.COLUMNS query, try/catch →
     * []), but a DIFFERENT table (`tblSongs`, not `tblWorks`) and a
     * DIFFERENT column set (five, not nine) — so it is its own method, not a
     * parameterised reuse of `_worksExtraCols()`. Also deliberately NOT
     * shared with the editor's own probe, `ed2_songIdentityColsPresent()`
     * (`manage/editor/api2.php`) — that one runs in the `manage/editor`
     * runtime context (a free function, not a SongData method) and this one
     * runs in the public `includes/` context; unifying them into a
     * cross-context `require` would couple two independently-deployable
     * surfaces for no benefit (mirrors the existing `_worksExtraCols()`-vs-
     * editor-probe split — the same reasoning applies here, do not
     * "unify" the two).
     *
     * @return array<string,true> present column names as keys (a set, not a
     *                             list — `isset($cols['Subtitle'])` reads
     *                             cleanly at every call site).
     * @see #1750
     * @link appWeb/.sql/migrate-song-identity-fields.php the migration that adds these columns
     * @link SongData::_worksExtraCols() the pattern this clones
     */
    private function _songIdentityCols(): array
    {
        if ($this->_songIdentityColsCache !== null) {
            return $this->_songIdentityColsCache;
        }
        /* Hardcoded constant column names (rule #5's carve-out) — never
           built from request input. */
        $cols = ['Subtitle', 'Disambiguation', 'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder'];
        $present = [];
        try {
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $this->db->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
                    AND COLUMN_NAME IN ($ph)"
            );
            $types = str_repeat('s', count($cols));
            $stmt->bind_param($types, ...$cols);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $present[(string)$row['COLUMN_NAME']] = true;
            }
            $stmt->close();
        } catch (\Throwable $_e) {
            /* mysqli STRICT throws on a query naming a nonexistent
               column/table; migrations are web-run so an un-migrated
               install must degrade to "none present", never white-screen. */
            $present = [];
        }
        return $this->_songIdentityColsCache = $present;
    }
    /** @var array<string,true>|null cached probe result (#1750) */
    private ?array $_songIdentityColsCache = null;

    /**
     * #1750 — the SELECT-fragment half of the `_songIdentityCols()` probe:
     * folds "which of the five columns exist" into a ready-to-interpolate
     * `, s.Col AS camelAlias` string, so `_fetchSongRow()` and `getSongs()`
     * (the two SELECTs that must both carry these columns — rule #22, ONE
     * fold, two call sites) never duplicate the alias-mapping logic.
     *
     * ELI5: builds the extra bit of the SQL SELECT line for whichever of
     * the five identity columns this database actually has, already
     * aliased to the camelCase wire names the editor and public API agree
     * on.
     *
     * DETAILED: alias mapping is fixed and matches the editor's
     * `ED2_META_FIELDS` keys exactly (`manage/editor/api2.php`) — one
     * vocabulary across editor, public web, and native (rule #35: keeping
     * two files' key spellings in lockstep needs a mechanism, and here that
     * mechanism is "there is only one place the mapping is written").
     * Column names come from `_songIdentityCols()`'s own hardcoded probe
     * set only — never from caller input (rule #5's carve-out).
     *
     * @return string e.g. ', s.Subtitle AS subtitle, s.FirstPublishedYear AS firstPublishedYear'
     *                for whichever P1 columns exist; '' when none do.
     * @see #1750
     */
    private function _songIdentitySelect(): string
    {
        $present = $this->_songIdentityCols();
        $aliasMap = [
            'Subtitle'           => 'subtitle',
            'Disambiguation'     => 'disambiguation',
            'FirstPublishedYear' => 'firstPublishedYear',
            'CopyrightYears'     => 'copyrightYears',
            'CopyrightHolder'    => 'copyrightHolder',
        ];
        $parts = [];
        foreach ($aliasMap as $col => $alias) {
            if (isset($present[$col])) {
                $parts[] = "s.{$col} AS {$alias}";
            }
        }
        return $parts ? (', ' . implode(', ', $parts)) : '';
    }

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
                           AND " . $this->_visible() . "
                         ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC";
                         /* #1694 — membership rows survive a soft delete
                            (CASCADE-safe); the PANEL just hides them */
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

        /* One parameter, two lookup columns — an all-digit string is treated as
           an Id, not a Slug.

           ELI5: "/work/12" means work number 12, not a work whose name is "12".
           A work slug made only of digits is therefore unreachable by slug.

           ⚠️ Nothing PREVENTS one. This comment previously claimed "the slug
           generator has never produced one", which is not a property anything
           enforces: `manage/works.php` takes a curator-supplied slug verbatim
           (`$slug = $slugIn !== '' ? $slugIn : $slugFor($title);`) and nothing
           downstream rejects an all-digit value — so a work titled "1812", or
           a hand-typed slug "23", is reachable today and would be permanently
           unreachable by slug. That is a missing mechanism (rule #35), not a
           guarantee, and stating it as a guarantee is how it stays missing. */
        $isInt = is_int($slugOrId) || ctype_digit((string)$slugOrId);

        /* #1741 P4b — append whichever of the 9 "extra" columns actually
           exist on this install to the main SELECT. Hardcoded constant
           column names interpolated (rule #5's carve-out — never built
           from request input); the present-set itself comes from the
           cached INFORMATION_SCHEMA probe above, never from $slugOrId. */
        $extraColNames = [
            'Ccli', 'Bowi', 'Subtitle', 'Disambiguation', 'TuneName', 'TuneId',
            'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder',
        ];
        $extraPresent  = $this->_worksExtraCols();
        $extraSelected = array_values(array_filter(
            $extraColNames,
            static fn(string $c): bool => isset($extraPresent[$c])
        ));
        $extraSelect = $extraSelected ? (', ' . implode(', ', $extraSelected)) : '';

        try {
            if ($isInt) {
                $stmt = $this->db->prepare(
                    'SELECT Id, ParentWorkId, Title, Slug, Iswc, Notes, CreatedAt, UpdatedAt'
                    . $extraSelect . '
                       FROM tblWorks WHERE Id = ? LIMIT 1'
                );
                $id = (int)$slugOrId;
                $stmt->bind_param('i', $id);
            } else {
                $stmt = $this->db->prepare(
                    'SELECT Id, ParentWorkId, Title, Slug, Iswc, Notes, CreatedAt, UpdatedAt'
                    . $extraSelect . '
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
                /* #1741 P4b (§2.2.2) — absent-column defaults so work.php
                   can render shape-blind: every key below is always
                   present in the returned array, whether or not the
                   backing column exists on this install yet. */
                'ccli'               => isset($extraPresent['Ccli'])               ? (string)($row['Ccli'] ?? '')               : '',
                'bowi'               => isset($extraPresent['Bowi'])               ? (string)($row['Bowi'] ?? '')               : '',
                'subtitle'           => isset($extraPresent['Subtitle'])           ? (string)($row['Subtitle'] ?? '')           : '',
                'disambiguation'     => isset($extraPresent['Disambiguation'])     ? (string)($row['Disambiguation'] ?? '')     : '',
                'tuneName'           => isset($extraPresent['TuneName'])           ? (string)($row['TuneName'] ?? '')           : '',
                'tuneId'             => (isset($extraPresent['TuneId']) && $row['TuneId'] !== null) ? (int)$row['TuneId'] : null,
                'firstPublishedYear' => (isset($extraPresent['FirstPublishedYear']) && $row['FirstPublishedYear'] !== null) ? (int)$row['FirstPublishedYear'] : null,
                'copyrightYears'     => isset($extraPresent['CopyrightYears'])     ? (string)($row['CopyrightYears'] ?? '')     : '',
                'copyrightHolder'    => isset($extraPresent['CopyrightHolder'])    ? (string)($row['CopyrightHolder'] ?? '')    : '',
                'tune'               => null,
                'credits'            => ['writers' => [], 'composers' => [], 'arrangers' => []],
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
                  WHERE ws.WorkId = ? AND ' . $this->_visible() . '
                  ORDER BY ws.IsCanonical DESC, s.SongbookAbbr ASC, s.Number ASC'
                  /* #1694 — the public Work page hides deleted members */
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

            /* #1741 P4b (§2.2.3) — Tune link resolution. Only attempted
               when TuneId was actually resolved above (either the column
               was absent, or it was present but NULL — both leave
               $work['tuneId'] === null and this whole block is skipped).
               Belt-and-braces try/catch: tblTunes is a SEPARATE table
               (#1090) that can be absent even when tblWorks.TuneId exists
               (migrate-works-identity.php explicitly tolerates running
               before migrate-tunes-entity.php — see that script's
               doc-block), so a raw SELECT here would throw under STRICT
               mysqli on an install in that intermediate state. */
            if ($work['tuneId'] !== null) {
                try {
                    $tprobe = $this->db->prepare(
                        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblTunes' LIMIT 1"
                    );
                    $tprobe->execute();
                    $hasTunes = $tprobe->get_result()->fetch_row() !== null;
                    $tprobe->close();
                    if ($hasTunes) {
                        $tstmt = $this->db->prepare('SELECT Name, Slug FROM tblTunes WHERE Id = ? LIMIT 1');
                        $tid = (int)$work['tuneId'];
                        $tstmt->bind_param('i', $tid);
                        $tstmt->execute();
                        $trow = $tstmt->get_result()->fetch_assoc();
                        $tstmt->close();
                        if ($trow) {
                            $work['tune'] = [
                                'name' => (string)$trow['Name'],
                                'slug' => (string)$trow['Slug'],
                            ];
                        }
                    }
                } catch (\Throwable $_e) {
                    /* Tune lookup is a nice-to-have enrichment, not core
                       Work data — degrade to no tune link rather than
                       failing the whole page. */
                }
            }

            /* #1741 P4b (§2.2.4) — Aggregated writer/composer/arranger
               credits from member songs. There is NO tblWorkCredits table
               (§2.7's accepted escape hatch: the curator-credit table was
               scoped OUT of P4b) — credits are DERIVED by asking each of
               the three existing per-role song-credit tables which names
               appear across every member song's SongId. Skipped entirely
               when the work has no members (nothing to aggregate). Each
               table query gets its OWN try/catch — the three tables are
               old and near-certain to exist, but STRICT-safe costs
               nothing and one absent/renamed table shouldn't blank the
               other two roles. */
            if (!empty($work['members'])) {
                $creditSongIds = array_values(array_unique(array_map(
                    static fn(array $m): string => (string)$m['songId'],
                    $work['members']
                )));
                if ($creditSongIds) {
                    $creditPh    = implode(',', array_fill(0, count($creditSongIds), '?'));
                    $creditTypes = str_repeat('s', count($creditSongIds));
                    /* Hardcoded constant table names (rule #5's carve-out)
                       — never built from request input. */
                    $creditTables = [
                        'writers'   => 'tblSongWriters',
                        'composers' => 'tblSongComposers',
                        'arrangers' => 'tblSongArrangers',
                    ];
                    foreach ($creditTables as $creditKey => $creditTable) {
                        try {
                            $cstmt = $this->db->prepare(
                                "SELECT DISTINCT Name FROM {$creditTable} WHERE SongId IN ($creditPh)"
                            );
                            $cstmt->bind_param($creditTypes, ...$creditSongIds);
                            $cstmt->execute();
                            $cres  = $cstmt->get_result();
                            $names = [];
                            while ($crow = $cres->fetch_assoc()) {
                                $names[] = (string)$crow['Name'];
                            }
                            $cstmt->close();
                            sort($names, SORT_STRING | SORT_FLAG_CASE);
                            $work['credits'][$creditKey] = array_slice($names, 0, 50);
                        } catch (\Throwable $_e) {
                            /* Credit table absent/renamed — that role's
                               list simply stays empty (already defaulted
                               to [] in $work['credits'] above). */
                        }
                    }
                }
            }

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

    /* =====================================================================
     * MUSICIANS — writer/composer/etc. bio + discography (#1443/#1444,
     * renamed from "Credit People" by #1741 P2-B)
     *
     * JSON twin of includes/pages/musician.php's own query logic (the public
     * /musician/<slug> HTML page — see that file's header). Deliberately NOT
     * a copy-paste fork: both this method and musician.php independently
     * query tblMusicians + the six per-role credit tables because
     * tblMusicians has no direct FK from tblSongWriters/tblSongComposers/
     * etc. today (a name-string match, same as musician.php) — a future
     * schema change adding that FK should update BOTH read paths together.
     * ===================================================================== */

    /**
     * One credit person's registry row (bio/lifespan/links, when a
     * tblMusicians row exists) plus every song they are credited on,
     * grouped by role. Exactly ONE of $id/$slug/$name should be given by
     * the caller (api.php's `musician` action resolves which); when
     * none of the id/slug lookups finds a registry row, $name (or the
     * row's own Name once found) still drives the discography query — a
     * writer/composer with no curated tblMusicians row yet still has a
     * usable "songs by this name" result, mirroring musician.php's own
     * slug-has-no-row fallback.
     *
     * Returns null only when NEITHER a registry row NOR any credited song
     * could be found — nothing to show at all (same 404 condition
     * musician.php uses).
     *
     * @return array<string,mixed>|null
     */
    public function getMusician(?int $id = null, ?string $slug = null, ?string $name = null): ?array
    {
        $row = null;
        try {
            if ($id !== null) {
                $stmt = $this->db->prepare(
                    'SELECT Id, Name, Slug, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                            COALESCE(IsSpecialCase, 0) AS IsSpecialCase,
                            COALESCE(IsGroup, 0)       AS IsGroup
                       FROM tblMusicians WHERE Id = ? LIMIT 1'
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } elseif ($slug !== null && $slug !== '') {
                $stmt = $this->db->prepare(
                    'SELECT Id, Name, Slug, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                            COALESCE(IsSpecialCase, 0) AS IsSpecialCase,
                            COALESCE(IsGroup, 0)       AS IsGroup
                       FROM tblMusicians WHERE Slug = ? LIMIT 1'
                );
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            /* Slug/id lookup found nothing (or wasn't attempted) — try an
               exact Name match (tblMusicians.Name is UNIQUE). Mirrors
               musician.php's own "no registry row, fall back to a name" path. */
            if ($row === null && $name !== null && $name !== '') {
                $stmt = $this->db->prepare(
                    'SELECT Id, Name, Slug, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                            COALESCE(IsSpecialCase, 0) AS IsSpecialCase,
                            COALESCE(IsGroup, 0)       AS IsGroup
                       FROM tblMusicians WHERE Name = ? LIMIT 1'
                );
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('[SongData::getMusician] registry lookup failed: ' . $e->getMessage());
            $row = null;
        }

        /* The name the discography query below matches against — the
           registry row's own Name once one is found (authoritative),
           otherwise whatever the caller passed in (a plain writer/composer
           string tapped from a song, with no registry row yet). */
        $matchName = $row ? (string)$row['Name'] : (string)($name ?? '');
        if ($row === null && $matchName === '') {
            return null; /* nothing to look up at all */
        }

        $person = [
            'id'            => $row ? (int)$row['Id'] : null,
            'slug'          => $row ? (string)($row['Slug'] ?? '') : null,
            'name'          => $row ? (string)$row['Name'] : $matchName,
            'notes'         => ($row && $row['Notes'] !== null) ? (string)$row['Notes'] : null,
            'birthPlace'    => ($row && $row['BirthPlace'] !== null) ? (string)$row['BirthPlace'] : null,
            'birthDate'     => ($row && $row['BirthDate'] !== null) ? (string)$row['BirthDate'] : null,
            'deathPlace'    => ($row && $row['DeathPlace'] !== null) ? (string)$row['DeathPlace'] : null,
            'deathDate'     => ($row && $row['DeathDate'] !== null) ? (string)$row['DeathDate'] : null,
            'isSpecialCase' => $row ? (bool)$row['IsSpecialCase'] : false,
            'isGroup'       => $row ? (bool)$row['IsGroup'] : false,
            'discography'   => [],
            'links'         => [],
            'totalSongs'    => 0,
            /* #1752 Slice D — always-present default so the native Apple
               client (and any other consumer) can decode this key
               unconditionally, matching the P4b `getWork()` "shape-blind"
               convention (rule #9): an un-migrated docroot, a lookup that
               never resolved a registry row, or a query failure all leave
               this as the empty array rather than omitting the key. See
               `.claude/catalogue-1741-1750-plan.md` §4 for the frozen
               contract this app-side change is scoped OUTSIDE of (this key
               is new, #1741-adjacent but not part of that frozen five). */
            'identifiers'   => [],
        ];

        /* Discography by role — same six tables + labels musician.php uses,
           minus the #587 'artist' role when tblSongArtists hasn't been
           migrated yet (probe, exactly like musician.php's own guard). */
        $roleTables = [
            'writer'     => ['table' => 'tblSongWriters',     'label' => 'As Writer'],
            'composer'   => ['table' => 'tblSongComposers',   'label' => 'As Composer'],
            'arranger'   => ['table' => 'tblSongArrangers',   'label' => 'As Arranger'],
            'adaptor'    => ['table' => 'tblSongAdaptors',    'label' => 'As Adaptor'],
            'translator' => ['table' => 'tblSongTranslators', 'label' => 'As Translator'],
            'artist'     => ['table' => 'tblSongArtists',     'label' => 'As Artist'],
        ];
        try {
            $probe = $this->db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongArtists' LIMIT 1"
            );
            if (!($probe && $probe->fetch_row() !== null)) {
                unset($roleTables['artist']);
            }
            if ($probe) { $probe->close(); }
        } catch (\Throwable $_e) {
            unset($roleTables['artist']);
        }

        $matchedSongIds = [];
        foreach ($roleTables as $roleKey => $cfg) {
            try {
                /* `{$cfg['table']}` is interpolated into SQL, and that is
                   legitimate here — but only because of where it comes from.

                   ELI5: the table name is picked from a fixed list written
                   just above; nothing a visitor types can reach it.

                   CLAUDE.md rule #5 clause (a): a table/column name cannot be a
                   bound parameter in MySQL, so the only safe source is a
                   hardcoded PHP constant or an exact allow-list — `$roleTables`
                   is that constant, and `$roleKey` never leaves it. The VALUE
                   ($matchName) is bound, as it must be. If a role is ever made
                   configurable, this becomes an injection site: keep the
                   allow-list literal. */
                $stmt = $this->db->prepare(
                    "SELECT s.SongId AS songId, s.Title AS title, s.Number AS number,
                            s.SongbookAbbr AS songbook, sb.Name AS songbookName
                       FROM {$cfg['table']} c
                       JOIN tblSongs s ON s.SongId = c.SongId
                       LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                      WHERE c.Name = ? AND " . $this->_visible() . "
                      ORDER BY s.SongbookAbbr ASC, s.Number ASC"
                      /* #1694 — a discography lists visible songs only */
                );
                $stmt->bind_param('s', $matchName);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                if ($rows) {
                    $songs = [];
                    foreach ($rows as $r) {
                        $songs[] = [
                            'songId'       => (string)$r['songId'],
                            'title'        => (string)$r['title'],
                            'number'       => normaliseSongNumber($r['number']),
                            'songbook'     => (string)$r['songbook'],
                            'songbookName' => (string)($r['songbookName'] ?? ''),
                        ];
                        $matchedSongIds[(string)$r['songId']] = true;
                    }
                    $person['discography'][] = [
                        'role'  => $roleKey,
                        'label' => $cfg['label'],
                        'songs' => $songs,
                    ];
                }
            } catch (\Throwable $_e) {
                /* Table missing / query failed — skip this role, matching
                   musician.php's own per-role try/catch. */
            }
        }
        $person['totalSongs'] = count($matchedSongIds);

        if ($row === null && $person['totalSongs'] === 0) {
            return null; /* no registry row AND no credited songs — genuinely unknown */
        }

        /* External links — unified system only (#833). The legacy
           tblMusicianLinks fallback musician.php still renders is a
           display-only shim with no new rows since #833 shipped; not worth
           carrying into this newer JSON contract. */
        if ($row && (int)$row['Id'] > 0) {
            try {
                $probe = $this->db->query(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicianExternalLinks' LIMIT 1"
                );
                $hasLinks = $probe && $probe->fetch_row() !== null;
                if ($probe) { $probe->close(); }
            } catch (\Throwable $_e) {
                $hasLinks = false;
            }
            if ($hasLinks) {
                $pid = (int)$row['Id'];
                $stmt = $this->db->prepare(
                    'SELECT t.Slug AS slug, t.Name AS name, t.Category AS category, t.IconClass AS iconClass,
                            el.Url AS url, el.Note AS note, el.Verified AS verified, el.SortOrder AS sortOrder
                       FROM tblMusicianExternalLinks el
                       JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                      WHERE el.MusicianId = ?
                        AND COALESCE(t.IsActive, 1) = 1
                      ORDER BY t.Category, el.SortOrder ASC, t.DisplayOrder ASC, t.Name ASC'
                );
                $stmt->bind_param('i', $pid);
                $stmt->execute();
                $lres = $stmt->get_result();
                while ($lrow = $lres->fetch_assoc()) {
                    $person['links'][] = [
                        'slug'      => (string)$lrow['slug'],
                        'name'      => (string)$lrow['name'],
                        'category'  => (string)$lrow['category'],
                        'iconClass' => (string)($lrow['iconClass'] ?? ''),
                        'url'       => (string)$lrow['url'],
                        'note'      => $lrow['note'] !== null ? (string)$lrow['note'] : null,
                        'verified'  => (bool)$lrow['verified'],
                        'sortOrder' => (int)$lrow['sortOrder'],
                    ];
                }
                $stmt->close();
            }
        }

        /* #1752 Slice D — musician identifiers (IPI/ISNI and similar,
           `tblMusicianIdentifiers`, #1741 P2 rename target). `getMusician()`
           previously emitted NO P4a musician-enrichment identifiers at all
           (verified by reading this whole function — only id/slug/name/
           notes/lifespan/isSpecialCase/isGroup/discography/links/
           totalSongs) even though the web musician PAGE renders richer
           data; this closes that native/JSON gap (`.claude/
           catalogue-1741-1752-plan.md` §4.1). Byte-pattern of the
           `tblMusicianExternalLinks` existence-probe directly above —
           existence-gated (rule #9/#19: migrations are web-run, not
           auto-applied, so an un-migrated docroot must degrade to the
           already-set `'identifiers' => []` default, never throw) and
           wrapped in try/catch (rule #9 STRICT-safety: mysqli throws under
           MYSQLI_REPORT_STRICT, and a query failure here must not blank the
           whole musician page/JSON response). */
        if ($row && (int)$row['Id'] > 0) {
            try {
                $probe = $this->db->query(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicianIdentifiers' LIMIT 1"
                );
                $hasIdentifiers = $probe && $probe->fetch_row() !== null;
                if ($probe) { $probe->close(); }
            } catch (\Throwable $_e) {
                $hasIdentifiers = false;
            }
            if ($hasIdentifiers) {
                try {
                    $pid = (int)$row['Id'];
                    $stmt = $this->db->prepare(
                        'SELECT IdentifierType AS type, IdentifierValue AS value
                           FROM tblMusicianIdentifiers WHERE MusicianId = ? ORDER BY IdentifierType, IdentifierValue'
                    );
                    $stmt->bind_param('i', $pid);
                    $stmt->execute();
                    $ires = $stmt->get_result();
                    while ($irow = $ires->fetch_assoc()) {
                        $person['identifiers'][] = [
                            'type'  => (string)$irow['type'],
                            'value' => (string)$irow['value'],
                        ];
                    }
                    $stmt->close();
                } catch (\Throwable $_e) {
                    /* Query failed after the probe passed (e.g. a mid-flight
                       schema change) — degrade to the already-set empty
                       default rather than throwing (rule #9). */
                    $person['identifiers'] = [];
                }
            }
        }

        return $person;
    }
}
