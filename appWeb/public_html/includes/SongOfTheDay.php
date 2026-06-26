<?php

declare(strict_types=1);

/**
 * iHymns — Song of the Day (server-side, live MySQL) — WS-C #1015
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Picks the deterministic "Song of the Day" with LIVE MySQL queries — the
 * server-side replacement for the old client module that scanned the whole
 * songs.json corpus in the browser. Same contract as before: every user
 * sees the same song on the same calendar day; on key dates in the
 * Christian calendar a themed song is chosen by keyword matching.
 *
 * This is a faithful port of js/modules/song-of-the-day.js — the calendar
 * theme banks, the Anonymous Gregorian Easter algorithm, the Advent
 * calculation, and the deterministic date-seed pick all move here. The one
 * structural change is that selection is a bounded DB query (COUNT +
 * LIMIT 1 OFFSET seed%count, or a keyword LIKE pool) rather than an
 * in-memory scan — nothing materialises the corpus.
 *
 * Language filtering reuses applyLanguageFilterSql() (language_filter.php),
 * the same predicate search/browse use: untagged songs always pass; an
 * empty subtag set means no filter.
 */
final class SongOfTheDay
{
    private \mysqli $db;

    /** @var array<int,array{name:string,keywords:string[],check:callable}> */
    private array $calendarThemes;

    /**
     * Hemisphere of the requesting user ('n' default | 's'). Set per-call by
     * pickForDate() and read by the theme check closures (they capture $this), so
     * the ONLY hemisphere-sensitive theme — Harvest — fires in each hemisphere's
     * own autumn. Liturgical themes (Advent/Easter/...) are globally synchronous
     * and ignore it. (#1374)
     */
    private string $hemisphere = 'n';

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->calendarThemes = $this->buildThemes();
    }

    /* =====================================================================
     * PUBLIC API
     * ===================================================================== */

    /**
     * Pick the Song of the Day for a date, honouring the active language
     * filter. Mirrors the client pickSong() fallback ladder:
     *   1. filtered pool → themed pick
     *   2. filtered pool → deterministic pick
     *   3. English subset → themed pick      (skipped when filter is en/All)
     *   4. English subset → deterministic pick
     *   5. unfiltered    → deterministic pick
     *
     * @param string[] $langSubtags Lowercase primary subtags ([] = no filter)
     * @param string   $hemisphere  'n' (default) | 's' — shifts the Harvest theme
     *                               into the southern autumn; 's' only when explicitly
     *                               passed, so existing callers are unchanged.
     * @return array{song:array<string,mixed>,themeLabel:string,firstLine:string}|null
     */
    public function pickForDate(\DateTimeImmutable $date, array $langSubtags, string $hemisphere = 'n'): ?array
    {
        $this->hemisphere = ($hemisphere === 's') ? 's' : 'n';
        $seed  = $this->dateSeed($date);
        $theme = $this->activeTheme($date);

        $id = null;
        if ($theme !== null) {
            $id = $this->themedPick($theme, $langSubtags, $seed);
        }
        if ($id === null) {
            $id = $this->deterministicPick($langSubtags, $seed);
        }

        /* English fallback — skip when the active filter already IS English
           (or "All"), since it would just re-run over the same pool. */
        $isEnglishOrAll = empty($langSubtags) || $langSubtags === ['en'];
        if ($id === null && !$isEnglishOrAll) {
            if ($theme !== null) {
                $id = $this->themedPick($theme, ['en'], $seed);
            }
            if ($id === null) {
                $id = $this->deterministicPick(['en'], $seed);
            }
        }

        /* Last resort — unfiltered deterministic pick. */
        if ($id === null) {
            $id = $this->deterministicPick([], $seed);
        }
        if ($id === null) {
            return null;
        }

        return $this->buildResult($id, $this->themeLabel($date));
    }

    /** Friendly label for the active theme, or the generic default. */
    public function themeLabel(\DateTimeImmutable $date): string
    {
        $theme = $this->activeTheme($date);
        return $theme !== null ? $theme['name'] . ' Song of the Day' : 'Song of the Day';
    }

    /* =====================================================================
     * SELECTION (bounded live queries — nothing materialises the corpus)
     * ===================================================================== */

    /**
     * Themed pick: title keyword pool first (a title match is its own
     * context), falling back to a lyrics keyword pool. Returns a SongId
     * or null. Mirrors findThemedSong (title matches preferred).
     *
     * @param array{name:string,keywords:string[],check:callable} $theme
     * @param string[] $langSubtags
     */
    private function themedPick(array $theme, array $langSubtags, int $seed): ?string
    {
        $keywords = $theme['keywords'];
        if (empty($keywords)) {
            return null;
        }

        [$tw, $tt, $tp] = $this->keywordWhere('Title', $keywords);
        $id = $this->pickId($tw, $tt, $tp, $langSubtags, $seed);
        if ($id !== null) {
            return $id;
        }

        /* Lyrics fallback. Matches the full LyricsText (all components),
           whereas the old client only scanned the first two — a deliberate
           simplification: this pass only fires when NO title matched the
           theme at all (rare), and a broader body match still yields an
           on-theme song without a JOIN/decode over per-component JSON. */
        [$lw, $lt, $lp] = $this->keywordWhere('LyricsText', $keywords);
        return $this->pickId($lw, $lt, $lp, $langSubtags, $seed);
    }

    /** Deterministic pick from the (language-filtered) full catalogue. */
    private function deterministicPick(array $langSubtags, int $seed): ?string
    {
        return $this->pickId('', '', [], $langSubtags, $seed);
    }

    /**
     * Core deterministic pick: COUNT the candidate pool, then select the
     * single row at offset (seed % count) under a stable ORDER BY SongId.
     * Bounded — never pulls the whole pool into PHP.
     *
     * @param string   $extraWhere  e.g. " AND (Title LIKE ? OR ...)" (constants only)
     * @param string   $extraTypes  bind types matching $extraParams
     * @param mixed[]  $extraParams bind values for $extraWhere
     * @param string[] $langSubtags
     */
    private function pickId(string $extraWhere, string $extraTypes, array $extraParams, array $langSubtags, int $seed): ?string
    {
        [$langWhere, $langTypes, $langParams] = applyLanguageFilterSql('Language', $langSubtags);

        $where  = '1=1' . $extraWhere . $langWhere;
        $types  = $extraTypes . $langTypes;
        $params = array_merge($extraParams, $langParams);

        /* count */
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM tblSongs WHERE {$where}");
        if ($types !== '') {
            $cStmt->bind_param($types, ...$params);
        }
        $cStmt->execute();
        $count = (int)($cStmt->get_result()->fetch_row()[0] ?? 0);
        $cStmt->close();
        if ($count === 0) {
            return null;
        }

        $offset = $seed % $count;

        $pStmt = $this->db->prepare(
            "SELECT SongId FROM tblSongs WHERE {$where} ORDER BY SongId LIMIT 1 OFFSET ?"
        );
        $pStmt->bind_param($types . 'i', ...array_merge($params, [$offset]));
        $pStmt->execute();
        $row = $pStmt->get_result()->fetch_row();
        $pStmt->close();

        return ($row[0] ?? null) !== null ? (string)$row[0] : null;
    }

    /**
     * Build an " AND (<col> LIKE ? OR ...)" fragment from theme keywords.
     * $col is a hardcoded constant ('Title' / 'LyricsText'); keywords are
     * bound, never interpolated. Substring LIKE mirrors the client's
     * title.includes(kw) semantics.
     *
     * @param string[] $keywords
     * @return array{0:string,1:string,2:string[]}
     */
    private function keywordWhere(string $col, array $keywords): array
    {
        $clauses = array_fill(0, count($keywords), "{$col} LIKE ?");
        $where   = ' AND (' . implode(' OR ', $clauses) . ')';
        $types   = str_repeat('s', count($keywords));
        $params  = array_map(static fn(string $k): string => '%' . $k . '%', $keywords);
        return [$where, $types, $params];
    }

    /**
     * Fetch the lightweight card summary for the chosen song (live
     * songbook name via JOIN per WS-E #1013) plus its first lyric line.
     *
     * @return array{song:array<string,mixed>,themeLabel:string,firstLine:string}|null
     */
    private function buildResult(string $songId, string $themeLabel): ?array
    {
        /* Exactly the fields the home card renders (verified drives the
           verified-badge); kept minimal per the lightweight-reads rule. */
        $sql = "SELECT s.SongId AS id, s.Number AS number, s.Title AS title,
                       s.SongbookAbbr AS songbook, sb.Name AS songbookName,
                       s.Verified AS verified
                FROM tblSongs s
                LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                WHERE s.SongId = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        $song = [
            'id'           => (string)$row['id'],
            'number'       => normaliseSongNumber($row['number']),
            'title'        => (string)$row['title'],
            'songbook'     => (string)$row['songbook'],
            'songbookName' => (string)($row['songbookName'] ?? ''),
            'verified'     => (bool)$row['verified'],
        ];

        return [
            'song'       => $song,
            'themeLabel' => $themeLabel,
            'firstLine'  => $this->firstLine($songId),
        ];
    }

    /**
     * First non-empty lyric line of the song (its preview line).
     *
     * #1235 P4 (read switch) — the normalised tblLyricLines mirror is the authoritative
     * line source, so the preview line comes from the shared assembler
     * (lyricLinesFirstLine), never from tblSongComponents.LinesJson. The LinesJson read
     * survives ONLY as the un-migrated-install fallback below (no mirror table yet), so
     * the home card never breaks on a fresh install (the #1228/#1229 lesson).
     */
    private function firstLine(string $songId): string
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
        if (lyricLinesMirrorPresent($this->db)) {
            return (string)(lyricLinesFirstLine($this->db, $songId) ?? '');
        }

        /* lines-json-fallback (#1235 P4): un-migrated install with no tblLyricLines
           mirror — read the first component's LinesJson directly. */
        $stmt = $this->db->prepare(
            "SELECT LinesJson FROM tblSongComponents
              WHERE SongId = ? AND LinesJson IS NOT NULL
                AND LinesJson <> '' AND LinesJson <> '[]'
              ORDER BY SortOrder LIMIT 1"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row === null) {
            return '';
        }

        $lines = json_decode((string)$row[0], true);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    return $line;
                }
            }
        }
        return '';
    }

    /* =====================================================================
     * CALENDAR THEMES — ported verbatim from js/modules/song-of-the-day.js
     * Order matters: first matching theme wins (specific dates before broad
     * ranges). JS getMonth() is 0-based; PHP format('n') is 1-based.
     * ===================================================================== */

    /** @return array<int,array{name:string,keywords:string[],check:callable}> */
    private function buildThemes(): array
    {
        return [
            /* ---- CHRISTMAS SEASON ---- */
            [
                'name' => 'Advent',
                'keywords' => ['advent', 'come thou long-expected', 'o come o come emmanuel', 'prepare', 'watchman', 'waiting', 'prophecy', 'promised', 'messiah', 'herald', 'lo he comes'],
                'check' => fn(\DateTimeImmutable $d): bool =>
                    $this->isAdvent($d) && !((int)$d->format('n') === 12 && (int)$d->format('j') >= 24),
            ],
            [
                'name' => 'Christmas',
                'keywords' => ['christmas', 'bethlehem', 'manger', 'born', 'nativity', 'holy night', 'silent night', 'noel', 'away in a', 'infant holy', 'unto us a child', 'hark the herald', 'joy to the world', 'o come all ye faithful', 'incarnate', 'god with us', 'emmanuel', 'swaddling'],
                'check' => fn(\DateTimeImmutable $d): bool =>
                    ((int)$d->format('n') === 12 && (int)$d->format('j') >= 24 && (int)$d->format('j') <= 31)
                    || ((int)$d->format('n') === 1 && (int)$d->format('j') <= 6),
            ],
            [
                'name' => 'Epiphany',
                'keywords' => ['wise men', 'magi', 'star', 'gold', 'frankincense', 'myrrh', 'kings', 'manifest', 'epiphany', 'light of the world', 'nations'],
                'check' => fn(\DateTimeImmutable $d): bool =>
                    (int)$d->format('n') === 1 && (int)$d->format('j') >= 6 && (int)$d->format('j') <= 12,
            ],

            /* ---- EASTER SEASON (specific dates first) ---- */
            [
                'name' => 'Palm Sunday',
                'keywords' => ['hosanna', 'palm', 'ride on', 'triumphant', 'jerusalem', 'blessed is he', 'king is coming', 'open the gates', 'majesty'],
                'check' => fn(\DateTimeImmutable $d): bool => $this->isDaysFromEaster($d, -7),
            ],
            [
                'name' => 'Holy Week',
                'keywords' => ['gethsemane', 'agony', 'betrayed', 'upper room', 'bread and wine', 'communion', 'last supper', 'wash', 'servant', 'cross', 'calvary', 'suffering', 'lamb', 'sacrifice'],
                'check' => fn(\DateTimeImmutable $d): bool => $this->isEasterRange($d, -6, -3),
            ],
            [
                'name' => 'Good Friday',
                'keywords' => ['cross', 'calvary', 'crucified', 'blood', 'sacrifice', 'suffering', 'lamb of god', 'were you there', 'old rugged cross', 'when i survey', 'atonement', 'wounded', 'pierced', 'forgive them', 'it is finished', 'nailed'],
                'check' => fn(\DateTimeImmutable $d): bool =>
                    $this->isDaysFromEaster($d, -2) || $this->isDaysFromEaster($d, -1),
            ],
            [
                'name' => 'Easter',
                'keywords' => ['risen', 'resurrection', 'he is risen', 'easter', 'alive', 'tomb', 'hallelujah', 'victory', 'death where is', 'christ arose', 'up from the grave', 'morning', 'rolled away', 'conquered', 'lives'],
                'check' => fn(\DateTimeImmutable $d): bool => $this->isEasterRange($d, 0, 7),
            ],
            [
                'name' => 'Ascension',
                'keywords' => ['ascend', 'ascension', 'throne', 'exalted', 'crowned', 'reign', 'majesty', 'right hand', 'lifted up', 'king of kings', 'lord of lords', 'crown him'],
                'check' => fn(\DateTimeImmutable $d): bool => $this->isEasterRange($d, 38, 40),
            ],
            [
                'name' => 'Pentecost',
                'keywords' => ['spirit', 'holy spirit', 'pentecost', 'fire', 'wind', 'breath of god', 'come holy', 'fill me', 'power from on high', 'tongue', 'comforter', 'counselor', 'advocate'],
                'check' => fn(\DateTimeImmutable $d): bool => $this->isEasterRange($d, 49, 50),
            ],

            /* ---- LENT (broad range, after specific Holy Week dates) ---- */
            [
                'name' => 'Lent',
                'keywords' => ['repent', 'repentance', 'forgive', 'mercy', 'humble', 'cleanse', 'search me', 'create in me', 'broken', 'contrite', 'ashes', 'fast', 'surrender', 'wilderness', 'temptation', 'refine'],
                'check' => fn(\DateTimeImmutable $d): bool => $this->isEasterRange($d, -46, -8),
            ],

            /* ---- OTHER SEASONS ---- */
            [
                'name' => 'New Year',
                'keywords' => ['new', 'beginning', 'great is thy faithfulness', 'morning', 'mercy', 'grace', 'hope', 'new year', 'fresh', 'renew', 'guide me'],
                'check' => fn(\DateTimeImmutable $d): bool =>
                    (int)$d->format('n') === 1 && (int)$d->format('j') >= 1 && (int)$d->format('j') <= 3,
            ],
            [
                'name' => 'Reformation',
                'keywords' => ['mighty fortress', 'reformation', 'faith alone', 'grace alone', 'scripture', 'word of god', 'stand', 'truth', 'anchor', 'foundation', 'rock'],
                'check' => fn(\DateTimeImmutable $d): bool =>
                    (int)$d->format('n') === 10 && (int)$d->format('j') >= 29 && (int)$d->format('j') <= 31,
            ],
            [
                'name' => 'Harvest',
                'keywords' => ['harvest', 'thanksgiving', 'thank', 'grateful', 'bountiful', 'sow', 'reap', 'fields', 'provision', 'all good gifts', 'come ye thankful', 'fruit'],
                /* The ONLY hemisphere-sensitive theme (#1374). Harvest is a natural-
                   season festival, so the southern hemisphere celebrates it in THEIR
                   autumn (≈ March), not the northern October. Northern / unknown =
                   Oct 1–14 (unchanged); southern = Mar 1–14. The closure reads the
                   live $this->hemisphere set by pickForDate(). */
                'check' => fn(\DateTimeImmutable $d): bool =>
                    $this->hemisphere === 's'
                        ? ((int)$d->format('n') === 3  && (int)$d->format('j') >= 1 && (int)$d->format('j') <= 14)
                        : ((int)$d->format('n') === 10 && (int)$d->format('j') >= 1 && (int)$d->format('j') <= 14),
            ],
            [
                'name' => 'Remembrance',
                'keywords' => ['peace', 'rest', 'eternal', 'remember', 'honour', 'fallen', 'comfort', 'shelter', 'refuge', 'everlasting arms', 'safe'],
                'check' => fn(\DateTimeImmutable $d): bool =>
                    (int)$d->format('n') === 11 && (int)$d->format('j') >= 9 && (int)$d->format('j') <= 11,
            ],
            [
                'name' => 'Trinity Sunday',
                'keywords' => ['trinity', 'three in one', 'triune', 'father son', 'holy holy holy', 'godhead', 'three persons'],
                'check' => fn(\DateTimeImmutable $d): bool => $this->isDaysFromEaster($d, 56),
            ],
        ];
    }

    /** @return array{name:string,keywords:string[],check:callable}|null */
    private function activeTheme(\DateTimeImmutable $date): ?array
    {
        foreach ($this->calendarThemes as $theme) {
            if (($theme['check'])($date)) {
                return $theme;
            }
        }
        return null;
    }

    /* =====================================================================
     * DATE MATH — Anonymous Gregorian Easter + Advent (ported from JS)
     * ===================================================================== */

    /**
     * Deterministic, well-distributed seed from a calendar date.
     *
     * The contract is "same song for every user on the same calendar day",
     * which holds because all users now hit this one endpoint with the
     * same algorithm. It is deliberately NOT byte-compatible with the old
     * client's hash — exact parity is unachievable anyway (the client
     * picked from a corpus-ordered pool; we order by SongId), and a
     * rotating daily feature has no persistence expectation, so the
     * one-time change in which song shows is harmless. Don't "fix" this to
     * chase the old pick.
     */
    private function dateSeed(\DateTimeImmutable $date): int
    {
        return abs(crc32($date->format('Y-m-d')));
    }

    /** Easter Sunday (Anonymous Gregorian algorithm) for a year. */
    private function easterDate(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        /* JS computes a 0-based month (floor(...) - 1); the 1-based
           calendar month PHP needs is floor(...) directly. */
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** Date-only (drops time) for safe day-granularity comparisons. */
    private function dateOnly(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return new \DateTimeImmutable($d->format('Y-m-d'));
    }

    /** True if $d is exactly $offset days from Easter (sign = before/after). */
    private function isDaysFromEaster(\DateTimeImmutable $d, int $offset): bool
    {
        $easter = $this->easterDate((int)$d->format('Y'));
        $target = $easter->modify(($offset >= 0 ? '+' : '-') . abs($offset) . ' days');
        /* Compare month + day only, matching the JS getMonth/getDate check. */
        return $d->format('n-j') === $target->format('n-j');
    }

    /** True if $d falls within [Easter+start, Easter+end] (inclusive). */
    private function isEasterRange(\DateTimeImmutable $d, int $startOffset, int $endOffset): bool
    {
        $easter = $this->easterDate((int)$d->format('Y'));
        $lo = $easter->modify(($startOffset >= 0 ? '+' : '-') . abs($startOffset) . ' days');
        $hi = $easter->modify(($endOffset   >= 0 ? '+' : '-') . abs($endOffset)   . ' days');
        $day = $this->dateOnly($d);
        return $day >= $lo && $day <= $hi;
    }

    /**
     * True within Advent: the 4th Sunday before Christmas (Nov 27–Dec 3)
     * through December 23.
     */
    private function isAdvent(\DateTimeImmutable $d): bool
    {
        $year = (int)$d->format('Y');
        $xmas = new \DateTimeImmutable(sprintf('%04d-12-25', $year));
        $xmasDow = (int)$xmas->format('w'); /* 0=Sun..6=Sat, matches JS getDay() */
        $daysBack = $xmasDow === 0 ? 7 : $xmasDow;
        $adventStart = $xmas->modify('-' . ($daysBack + 21) . ' days');
        $adventEnd   = new \DateTimeImmutable(sprintf('%04d-12-23', $year));
        $day = $this->dateOnly($d);
        return $day >= $adventStart && $day <= $adventEnd;
    }
}
