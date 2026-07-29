<?php

declare(strict_types=1);

/**
 * iHymns — bulk_songs hydration count test (#1598)
 *
 * api.php's `bulk_songs` case (the offline "download songbook" endpoint) carried
 * a comment claiming its loop "already avoids getSongById() per song". That was
 * true of the loop in ISOLATION but false of what actually happened, because
 * includes/pages/song.php undid it on both counts:
 *   1. song.php unconditionally re-fetched $song via getSongById(), clobbering
 *      the $bulkSong the loop injected — one extra query per song.
 *   2. song.php's prev/next block called getSongs($songbook) — a FULL songbook
 *      re-hydration (every song's components + lyric assembly) — to find ONE
 *      song's neighbours. PER SONG, inside the loop.
 * For a book of N songs that was ~N extra whole-book hydrations — O(N²) — which
 * is why "download songbook" timed out / OOM'd on exactly the large books
 * (Mission Praise, ~3,517 songs) a user wants offline.
 *
 * This test proves the fix with a COUNTING FAKE standing in for SongData: it
 * renders the REAL includes/pages/song.php file (not a re-implementation) the
 * same way api.php's bulk_songs case does post-fix — $song / $prevSong /
 * $nextSong / $songNavInjected pre-set in the including scope, mirroring how
 * api.php's top-level `require` shares scope with the file it pulls in — and
 * asserts getSongs() is called EXACTLY ONCE for the whole book, never once per
 * song, and getSongById() is never called at all inside the loop.
 *
 * api.php's own switch-statement bootstrap (auth, rate limiting, a live DB
 * connection for $songData) isn't unit-testable in isolation, so the bulk-loop
 * SHAPE (the id→index map, the $songNavInjected sentinel) is mirrored here
 * rather than executed via api.php directly; the file actually required and
 * exercised is the real, unmodified includes/pages/song.php — the file the
 * O(N²) bug (and its fix) lives in.
 *
 * A second block proves the single-song (non-bulk, api.php `page=song`) path is
 * BYTE-BEHAVIOURALLY UNCHANGED: with no $song pre-set, song.php must still call
 * getSongById() once and getSongs() once for prev/next, exactly as before #1598.
 *
 *   php tests/php/test-bulk-songs-hydration.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failed.
 */

$root = dirname(__DIR__, 2) . '/appWeb/public_html';
$SONG_PAGE = $root . '/includes/pages/song.php';

/* toTitleCase() + the SongData class are required UNCONDITIONALLY by api.php
 * (api.php:170) before song.php ever runs — song.php calls toTitleCase() on its
 * very first line of output (line 84) without requiring the file itself (its own
 * "translations" (#281) block re-requires it later, defensively, but that's long
 * after the first call). Skipping this step would fatal on an undefined-function
 * error before the code under test ever ran, so mirror api.php's bootstrap order
 * exactly rather than have song.php pull it in lazily. SongData.php's class body
 * makes no DB connection at require time (only when instantiated), so this is
 * safe with no DB configured — the same premise test-gating-noop.php documents.
 */
require_once $root . '/includes/SongData.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    if ($cond) {
        $passed++;
    } else {
        $failed++;
    }
}

/**
 * Duck-typed stand-in for SongData — implements only the methods
 * includes/pages/song.php calls on its injected $songData, COUNTING
 * getSongs() / getSongById() invocations so the test can assert on the exact
 * call count directly instead of inferring it from timing or query logs.
 */
class CountingSongDataFake
{
    public int $getSongsCalls = 0;
    public int $getSongByIdCalls = 0;

    /** @var array<string, list<array<string,mixed>>> songbook abbr => ordered song rows */
    private array $byBook;
    /** @var array<string, array<string,mixed>> SongId => song row */
    private array $byId = [];

    public function __construct(array $byBook)
    {
        $this->byBook = $byBook;
        foreach ($byBook as $rows) {
            foreach ($rows as $row) {
                $this->byId[strtoupper((string)$row['id'])] = $row;
            }
        }
    }

    /** Mirrors SongData::getSongs() — the FULL-BOOK hydration under test (#1598). */
    public function getSongs(?string $songbookId = null): array
    {
        $this->getSongsCalls++;
        return $this->byBook[strtoupper((string)$songbookId)] ?? [];
    }

    public function getSongById(string $id): ?array
    {
        $this->getSongByIdCalls++;
        return $this->byId[strtoupper($id)] ?? null;
    }

    /* song.php tolerates a null songbook row (the colour / parent-link lookups
       both no-op on is_array($bookData) === false), so this never needs to
       return real data for these tests. */
    public function getSongbook(string $abbr): ?array
    {
        return null;
    }

    public function findSongIdByNumber(string $songbook, int $number): ?string
    {
        return null;
    }

    /* #1089/#1100 P1 — song.php's per-line translation reader. Empty is a
       legitimate "no translations for this song" answer for every test song
       here; this test is about hydration counts, not the translation UI. */
    public function getSongDetailExtras(string $songId, array $include): array
    {
        return [];
    }
}

/** One minimal-but-complete song row — same shape SongData::getSongs() returns. */
function makeSong(string $id, int $number, string $book = 'TB'): array
{
    return [
        'id'                 => $id,
        'number'             => $number,
        'title'              => 'Song ' . $number,
        'songbook'           => $book,
        'songbookName'       => 'Test Book',
        'language'           => 'en',
        'copyright'          => '',
        'ccli'               => '',
        'iswc'               => '',
        'tuneName'           => '',
        'verified'           => false,
        'lyricsPublicDomain' => true,
        'musicPublicDomain'  => true,
        'hasAudio'           => false,
        'hasSheetMusic'      => false,
        'writers'            => [],
        'composers'          => [],
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'artists'            => [],
        'components'         => [
            ['type' => 'verse', 'number' => 1, 'lines' => ['Line one', 'Line two'], 'language' => null],
        ],
    ];
}

/**
 * Render ONE song through the REAL song.php the way api.php's (fixed)
 * bulk_songs case does: $song / $songId / $prevSong / $nextSong /
 * $songNavInjected all pre-set in the SAME scope the require() runs in —
 * exactly how PHP's include-scope rule works whether that scope is a
 * function's locals (here) or api.php's top-level script scope (in prod).
 */
function renderBulkStyle(
    string $songPagePath,
    CountingSongDataFake $songData,
    array $bulkSongs,
    array $bulkIdIndex,
    array $bulkSong
): string {
    $songId = $bulkSong['id'];
    $song   = $bulkSong;

    $songNavInjected = true;
    $bulkIdx  = $bulkIdIndex[$songId] ?? null;
    $prevSong = ($bulkIdx !== null) ? ($bulkSongs[$bulkIdx - 1] ?? null) : null;
    $nextSong = ($bulkIdx !== null) ? ($bulkSongs[$bulkIdx + 1] ?? null) : null;

    ob_start();
    require $songPagePath;
    return (string)ob_get_clean();
}

/**
 * Render ONE song through the REAL song.php the way api.php's `page=song`
 * (non-bulk) route does: only $songData + $songId are set — $song is NOT
 * pre-injected, so song.php's isset($song) guard must fall back to fetching
 * it itself, exactly as before #1598.
 */
function renderSingleStyle(string $songPagePath, CountingSongDataFake $songData, string $songId): string
{
    ob_start();
    require $songPagePath;
    return (string)ob_get_clean();
}

/* ========================================================================= *
 * TEST 1 — bulk_songs path: N songs in one songbook, rendered the way
 * api.php's (fixed) bulk_songs case does. getSongs() must be called EXACTLY
 * ONCE for the whole request, never once per song; getSongById() must never
 * be called at all (the isset($song) guard uses the injected $bulkSong).
 * ========================================================================= */
echo "== bulk_songs: hydration call counts ==\n";

$N = 7;
$bookRows = [];
for ($i = 1; $i <= $N; $i++) {
    $bookRows[] = makeSong('TB-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), $i);
}
$byBook = ['TB' => $bookRows];

$fake = new CountingSongDataFake($byBook);

/* The ONE call api.php's bulk_songs case itself makes, to seed $bulkSongs —
   mirrors `$bulkSongs = $songData->getSongs($bulkSongbook);` in api.php. */
$bulkSongs = $fake->getSongs('TB');
$bulkIdIndex = array_flip(array_column($bulkSongs, 'id'));

$rendered = [];
foreach ($bulkSongs as $bulkSong) {
    $rendered[$bulkSong['id']] = renderBulkStyle($SONG_PAGE, $fake, $bulkSongs, $bulkIdIndex, $bulkSong);
}

check(
    "bulk_songs ({$N} songs): getSongs() called exactly once total, not once per song",
    $fake->getSongsCalls === 1,
    'actual getSongs() calls: ' . $fake->getSongsCalls . ' (a per-song re-hydration would show ' . ($N + 1) . ')'
);
check(
    "bulk_songs ({$N} songs): getSongById() never called inside the loop",
    $fake->getSongByIdCalls === 0,
    'actual getSongById() calls: ' . $fake->getSongByIdCalls . ' (the pre-fix bug called it once per song, ' . $N . ')'
);
check('bulk_songs: every song rendered non-empty HTML', count(array_filter($rendered)) === $N);

/* Spot-check prev/next actually resolved from the INJECTED data (not
   silently dropped): first song has no Previous, a middle song links both
   ways, the last song has no Next. Proves $songNavInjected really drives
   the render, not just that no exception was thrown. */
check(
    'bulk_songs: first song has no "Previous song" link',
    strpos($rendered['TB-0001'], 'Previous song') === false
);
check(
    'bulk_songs: first song links "Next song" to song #2',
    strpos($rendered['TB-0001'], 'Next song: Song 2') !== false
);
check(
    'bulk_songs: a middle song (#4) links to both neighbours (#3 and #5)',
    strpos($rendered['TB-0004'], 'Previous song: Song 3') !== false
        && strpos($rendered['TB-0004'], 'Next song: Song 5') !== false
);
check(
    'bulk_songs: last song has no "Next song" link',
    strpos($rendered['TB-000' . $N], 'Next song') === false
);

/* ========================================================================= *
 * TEST 2 — single-song (non-bulk) path is UNCHANGED. api.php's `page=song`
 * route never pre-sets $song, so song.php must still fetch it itself
 * (getSongById once) and still compute prev/next itself (getSongs once) —
 * exactly the pre-#1598 behaviour on this path. The fix is ADDITIVE: it must
 * be invisible to every caller that doesn't inject anything.
 * ========================================================================= */
echo "\n== single-song (non-bulk) path: unchanged behaviour ==\n";

$fake2 = new CountingSongDataFake($byBook);
$html2 = renderSingleStyle($SONG_PAGE, $fake2, 'TB-0004');

check(
    'single-song path: getSongById() called exactly once (song.php fetches it itself)',
    $fake2->getSongByIdCalls === 1,
    'actual: ' . $fake2->getSongByIdCalls
);
check(
    'single-song path: getSongs() called exactly once, for prev/next (unchanged)',
    $fake2->getSongsCalls === 1,
    'actual: ' . $fake2->getSongsCalls
);
check(
    'single-song path: prev/next still render correctly via the fallback',
    strpos($html2, 'Previous song: Song 3') !== false && strpos($html2, 'Next song: Song 5') !== false
);

/* ========================================================================= */
echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed === 0) {
    echo "All bulk_songs hydration assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$failed} assertion(s) failed.\n");
exit(1);
