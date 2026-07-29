<?php

declare(strict_types=1);

/**
 * iHymns — Songbook listing render-parity test (#1037)
 *
 * PURPOSE:
 * `includes/pages/songbook.php` used to call `SongData::getSongs($bookId)`
 * purely to render a list of titles — a call that, per-songbook, hydrates
 * every song's full lyric assembly (via the tblLyricLines mirror,
 * includes/lyric_lines_read.php) AND six credit collections AND tags AND
 * external links (CLAUDE.md rule #17 / the #929 OOM class). For Mission
 * Praise (~3,517 songs) that meant assembling the whole hymnal's lyrics on
 * every uncached view of a page that never shows a single line of lyric
 * text. The fix (#1037) switches the listing to `getSongsSlimIndex()` +
 * one small scoped supplementary query for the two fields the slim index
 * doesn't carry (`Verified`, `tblSongWriters`) — see the doc-block in
 * includes/pages/songbook.php for the full rationale.
 *
 * This test stubs the data layer entirely (a fake `$songData` object +
 * a fake `getDbMysqli()`), renders the REAL page template file, and
 * asserts every element/attribute/class the OLD getSongs()-based render
 * produced is still present — plus that the expensive call is gone and
 * the cheap ones are each made exactly ONCE for the whole page (never
 * per row).
 *
 *   php tests/php/test-songbook-render-parity.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failed.
 *
 * PROVE-FAIL RECORD (a test that cannot fail is worthless — project
 * testing philosophy, see test-whats-new-render.php): this file's
 * assertions were first run, unmodified, against a saved copy of
 * includes/pages/songbook.php AS IT STOOD BEFORE #1037 (the version that
 * called `getSongs($bookId)` and read `$song['verified']` / `$song['writers']`
 * directly off each song). Result: 39 of 45 assertions PASSED — every
 * single content/structure/order/leak-guard assertion — proving the
 * fixture + assertions faithfully model real old behaviour ("the baseline
 * captures reality"). The other 6 FAILED exactly as expected, all of them
 * the efficiency assertions this test exists to enforce: `getSongs()` WAS
 * called (old code calls it once per page), `getSongsSlimIndex()` was
 * NEVER called (old code doesn't know it exists) — both in the main
 * fixture and in the zero-song edge case, plus the supplementary
 * Verified/writers query was never prepared (old code has no such query,
 * it got verified/writers straight off getSongs()'s own hydration). After
 * the #1037 change, all 45 assertions pass. To repeat the check yourself,
 * point this script at a copy of the pre-#1037 file (with its sibling
 * includes/songbook_display.php and includes/partials/export-menu.php
 * alongside it, matching relative layout — the page's own requires are
 * path-relative to wherever the file you point at actually lives):
 *
 *   php tests/php/test-songbook-render-parity.php /path/to/old-songbook.php
 *
 * @see appWeb/public_html/includes/pages/songbook.php
 * @see appWeb/public_html/includes/SongData.php (getSongsSlimIndex, getSongs)
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/SongData.php';

$passed = 0;
$failed = 0;
function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        $failed++;
    }
}

/* =========================================================================
 * Fake mysqli layer — backs the new code's ONE supplementary query for
 * `Verified` + `tblSongWriters` (fields genuinely absent from the slim
 * index). Deliberately minimal: just enough surface (prepare/bind_param/
 * execute/get_result/fetch_assoc/close) for the page's usage.
 * ========================================================================= */

final class FakeMysqliResult
{
    private int $i = 0;
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->i++] ?? null;
    }
}

final class FakeMysqliStmt
{
    private ?string $boundValue = null;
    public function __construct(private array $resultRows) {}
    public function bind_param(string $types, ...$vars): bool
    {
        $this->boundValue = isset($vars[0]) ? (string)$vars[0] : null;
        return true;
    }
    public function execute(): bool { return true; }
    public function get_result(): FakeMysqliResult { return new FakeMysqliResult($this->resultRows); }
    public function close(): bool { return true; }
    public function boundValue(): ?string { return $this->boundValue; }
}

final class FakeMysqli
{
    public int $prepareCalls = 0;
    /** @var FakeMysqliStmt[] */
    public array $stmts = [];
    public function __construct(private array $creditsRows) {}
    public function prepare(string $sql): FakeMysqliStmt
    {
        $this->prepareCalls++;
        $stmt = new FakeMysqliStmt($this->creditsRows);
        $this->stmts[] = $stmt;
        return $stmt;
    }
}

/* getDbMysqli() is a plain global function (includes/db_mysql.php); the
   page calls it directly without requiring that file itself (mirrors the
   established pattern in includes/pages/tune.php / iswc.php), so a test
   stub can shadow it cleanly without ever loading the real connection
   factory. */
$GLOBALS['__ihymnsTestDb'] = null;
if (!function_exists('getDbMysqli')) {
    function getDbMysqli(): FakeMysqli
    {
        if ($GLOBALS['__ihymnsTestDb'] === null) {
            throw new \RuntimeException('FakeMysqli not configured for this render.');
        }
        return $GLOBALS['__ihymnsTestDb'];
    }
}

/* =========================================================================
 * Fake SongData — tracks call counts on the two reads the issue is about
 * (getSongs = expensive/forbidden going forward, getSongsSlimIndex = the
 * sanctioned replacement) so the test can assert not just WHAT rendered
 * but HOW it was fetched.
 * ========================================================================= */

final class FakeSongData
{
    public int $getSongsCalls = 0;
    public int $getSongsSlimIndexCalls = 0;

    public function __construct(
        private array $book,
        private array $legacySongs,   // shape the OLD getSongs($bookId) returned
        private array $slimIndex      // shape the NEW getSongsSlimIndex() returns (WHOLE corpus)
    ) {}

    public function getSongbook(string $id): ?array
    {
        return strtoupper(trim($id)) === strtoupper((string)$this->book['id']) ? $this->book : null;
    }

    /** The call the #1037 fix must never make again for a listing page. */
    public function getSongs(?string $songbookId = null): array
    {
        $this->getSongsCalls++;
        return $this->legacySongs;
    }

    /** The sanctioned scoped replacement (CLAUDE.md rule #17). */
    public function getSongsSlimIndex(): array
    {
        $this->getSongsSlimIndexCalls++;
        return $this->slimIndex;
    }
}

/* =========================================================================
 * Render helper — executes the real page template with a fake $songData +
 * $bookId in an isolated function scope (so nothing leaks to globals) and
 * captures its output.
 * ========================================================================= */

function renderSongbookPage(string $pagePath, $songData, string $bookId): string
{
    ob_start();
    require $pagePath;
    return (string)ob_get_clean();
}

/**
 * Pull the single `<a data-song-id="...">...</a>` block out of the
 * rendered song list so assertions can be scoped to one row instead of
 * matching anywhere on the page (list-group markup repeats the same
 * classes on every row, so an unscoped substring check could pass by
 * matching a different song's row).
 */
function extractSongBlock(string $html, string $songId): ?string
{
    $pattern = '#<a\b(?:(?!</a>).)*?data-song-id="' . preg_quote($songId, '#') . '"(?:(?!</a>).)*?</a>#s';
    return preg_match($pattern, $html, $m) === 1 ? $m[0] : null;
}

/* =========================================================================
 * Fixture — one songbook ("TB") with 4 songs covering every per-row
 * variable the old template branched on (numbered / unnumbered, verified /
 * unverified, one writer / several writers / no writers, audio-only /
 * sheet-music-only / both / neither), plus a DISTRACTOR song from a
 * different book ("OB") that must never leak into the TB render — that's
 * the regression a naive "just use the slim index" swap could introduce
 * (the slim index is corpus-wide, not book-scoped; filtering must happen
 * correctly in the page). A free-text DisplayAbbr (rule #27) and an
 * unofficial book (rule #24) exercise the header logic this change must
 * leave untouched.
 * ========================================================================= */

$bookTB = [
    'id'          => 'TB',
    'name'        => 'Test Book',
    'songCount'   => 4,
    'isOfficial'  => false,             // rule #24 — .songbook-unofficial-badge must render
    'displayAbbr' => 'TB Special',      // rule #27 — free-text display label, not the code
    'compilers'   => [],
    'alternativeNames' => [],
    'links'       => [],
];

$legacySongsTB = [
    ['id' => 'TB-0001', 'number' => 1,    'title' => 'amazing grace',                 'verified' => true,  'writers' => ['John Newton'],                          'hasAudio' => true,  'hasSheetMusic' => false],
    ['id' => 'TB-0002', 'number' => 2,    'title' => 'be thou my vision',             'verified' => false, 'writers' => [],                                        'hasAudio' => false, 'hasSheetMusic' => true],
    ['id' => 'TB-0003', 'number' => null, 'title' => 'christ the lord is risen today', 'verified' => true,  'writers' => ['Charles Wesley', 'Michael Praetorius'], 'hasAudio' => false, 'hasSheetMusic' => false],
    ['id' => 'TB-0004', 'number' => 4,    'title' => 'days of elijah',                'verified' => false, 'writers' => ['Robin Mark'],                            'hasAudio' => true,  'hasSheetMusic' => true],
];

$slimIndexWholeCorpus = [
    ['id' => 'TB-0001', 'number' => 1,    'title' => 'amazing grace',                  'songbook' => 'TB', 'songbookName' => 'Test Book',  'language' => null, 'hasAudio' => true,  'hasSheetMusic' => false],
    ['id' => 'TB-0002', 'number' => 2,    'title' => 'be thou my vision',              'songbook' => 'TB', 'songbookName' => 'Test Book',  'language' => null, 'hasAudio' => false, 'hasSheetMusic' => true],
    /* Distractor — a different songbook's song, interleaved in corpus order,
       must be filtered OUT of the TB render entirely. */
    ['id' => 'OB-0001', 'number' => 1,    'title' => 'a distractor from another book', 'songbook' => 'OB', 'songbookName' => 'Other Book', 'language' => null, 'hasAudio' => true,  'hasSheetMusic' => true],
    ['id' => 'TB-0003', 'number' => null, 'title' => 'christ the lord is risen today', 'songbook' => 'TB', 'songbookName' => 'Test Book',  'language' => null, 'hasAudio' => false, 'hasSheetMusic' => false],
    ['id' => 'TB-0004', 'number' => 4,    'title' => 'days of elijah',                 'songbook' => 'TB', 'songbookName' => 'Test Book',  'language' => null, 'hasAudio' => true,  'hasSheetMusic' => true],
];

/* Rows the new supplementary query would fetch: one row per (song, writer)
   pair via the LEFT JOIN, plus a single NULL-writer row for a song with
   none — real mysqli LEFT JOIN behaviour for tblSongWriters. */
$creditsRowsTB = [
    ['songId' => 'TB-0001', 'verified' => 1, 'writerName' => 'John Newton'],
    ['songId' => 'TB-0002', 'verified' => 0, 'writerName' => null],
    ['songId' => 'TB-0003', 'verified' => 1, 'writerName' => 'Charles Wesley'],
    ['songId' => 'TB-0003', 'verified' => 1, 'writerName' => 'Michael Praetorius'],
    ['songId' => 'TB-0004', 'verified' => 0, 'writerName' => 'Robin Mark'],
];

$pagePath = $argv[1] ?? dirname(__DIR__, 2) . '/appWeb/public_html/includes/pages/songbook.php';

$fakeSongData = new FakeSongData($bookTB, $legacySongsTB, $slimIndexWholeCorpus);
$GLOBALS['__ihymnsTestDb'] = new FakeMysqli($creditsRowsTB);

$html = renderSongbookPage($pagePath, $fakeSongData, 'TB');

echo "Rendering: $pagePath\n\n";

/* =========================================================================
 * Book-level header — untouched by #1037, asserted anyway as a smoke test
 * that the refactor didn't disturb anything above the song list.
 * ========================================================================= */

ok('section carries data-songbook-abbr for the export module (router.js)', str_contains($html, 'data-songbook-abbr="TB"'));
ok('song count line reflects songCount', str_contains($html, '4 songs'));
ok('unofficial badge renders for an IsOfficial=0 book (rule #24)', str_contains($html, 'songbook-unofficial-badge') && str_contains($html, 'Unofficial'));
ok('the DisplayAbbr free-text label renders on the abbreviation badge (rule #27), not the raw code alone', str_contains($html, 'TB Special'));

/* =========================================================================
 * Per-row parity — every element/attribute the OLD getSongs()-based render
 * produced, scoped to each row via extractSongBlock() so one row's markup
 * can't accidentally satisfy another row's assertion.
 * ========================================================================= */

$blockTB1 = extractSongBlock($html, 'TB-0001');
$blockTB2 = extractSongBlock($html, 'TB-0002');
$blockTB3 = extractSongBlock($html, 'TB-0003');
$blockTB4 = extractSongBlock($html, 'TB-0004');

ok('TB-0001 row present', $blockTB1 !== null);
ok('TB-0002 row present', $blockTB2 !== null);
ok('TB-0003 row present', $blockTB3 !== null);
ok('TB-0004 row present', $blockTB4 !== null);

if ($blockTB1 !== null) {
    ok('TB-0001 href points at /song/TB-0001', str_contains($blockTB1, 'href="/song/TB-0001"'));
    ok('TB-0001 data-navigate="song"', str_contains($blockTB1, 'data-navigate="song"'));
    ok('TB-0001 title-cased', str_contains($blockTB1, 'Amazing Grace'));
    ok('TB-0001 aria-label includes the song number', str_contains($blockTB1, 'aria-label="Song 1: Amazing Grace"'));
    ok('TB-0001 number badge shows 1', str_contains($blockTB1, '<span class="song-number-badge" data-songbook="TB" aria-hidden="true">1</span>'));
    ok('TB-0001 verified badge renders (Verified=1)', str_contains($blockTB1, 'verified-badge'));
    ok('TB-0001 writer byline renders "John Newton"', str_contains($blockTB1, 'song-writers') && str_contains($blockTB1, 'John Newton'));
    ok('TB-0001 has-audio icon renders', str_contains($blockTB1, 'fa-headphones'));
    ok('TB-0001 has-sheet-music icon absent', !str_contains($blockTB1, 'fa-file-pdf'));
}

if ($blockTB2 !== null) {
    ok('TB-0002 title-cased', str_contains($blockTB2, 'Be Thou My Vision'));
    ok('TB-0002 verified badge absent (Verified=0)', !str_contains($blockTB2, 'verified-badge'));
    ok('TB-0002 writer byline absent (no writers)', !str_contains($blockTB2, 'song-writers'));
    ok('TB-0002 has-audio icon absent', !str_contains($blockTB2, 'fa-headphones'));
    ok('TB-0002 has-sheet-music icon renders', str_contains($blockTB2, 'fa-file-pdf'));
}

if ($blockTB3 !== null) {
    ok('TB-0003 title-cased with minor-word lowercasing', str_contains($blockTB3, 'Christ the Lord Is Risen Today'));
    ok('TB-0003 aria-label has NO "Song N:" prefix (Number IS NULL)', str_contains($blockTB3, 'aria-label="Christ the Lord Is Risen Today"'));
    ok('TB-0003 number badge is empty (no songbook position)', str_contains($blockTB3, '<span class="song-number-badge" data-songbook="TB" aria-hidden="true"></span>'));
    ok('TB-0003 verified badge renders', str_contains($blockTB3, 'verified-badge'));
    ok('TB-0003 writer byline joins multiple writers with ", "', str_contains($blockTB3, 'Charles Wesley, Michael Praetorius'));
    ok('TB-0003 no audio/sheet-music icons', !str_contains($blockTB3, 'fa-headphones') && !str_contains($blockTB3, 'fa-file-pdf'));
}

if ($blockTB4 !== null) {
    ok('TB-0004 title-cased with minor-word lowercasing', str_contains($blockTB4, 'Days of Elijah'));
    ok('TB-0004 verified badge absent', !str_contains($blockTB4, 'verified-badge'));
    ok('TB-0004 writer byline renders "Robin Mark"', str_contains($blockTB4, 'Robin Mark'));
    ok('TB-0004 both audio and sheet-music icons render', str_contains($blockTB4, 'fa-headphones') && str_contains($blockTB4, 'fa-file-pdf'));
}

/* Row order preserved (SongbookAbbr → numbered-official-first → Number →
   Title — the slim index's own global sort, relied on rather than
   re-implemented in the page). */
$posTB1 = strpos($html, 'data-song-id="TB-0001"');
$posTB2 = strpos($html, 'data-song-id="TB-0002"');
$posTB3 = strpos($html, 'data-song-id="TB-0003"');
$posTB4 = strpos($html, 'data-song-id="TB-0004"');
ok(
    'song rows render in the original (already-sorted) order: 1, 2, 3, 4',
    $posTB1 !== false && $posTB2 !== false && $posTB3 !== false && $posTB4 !== false
    && $posTB1 < $posTB2 && $posTB2 < $posTB3 && $posTB3 < $posTB4
);

/* =========================================================================
 * Cross-book leak guard — the slim index is corpus-wide; a naive swap
 * could forget to filter by book and leak every songbook's titles onto
 * every songbook's page.
 * ========================================================================= */

ok('a different songbook\'s song (OB-0001) never appears in the TB render', !str_contains($html, 'OB-0001'));
ok('the distractor\'s title never appears in the TB render', stripos($html, 'distractor') === false);

/* =========================================================================
 * The actual point of #1037: the expensive call is gone, and the cheap
 * ones are each made exactly ONCE for the whole page — never per row.
 * ========================================================================= */

ok('SongData::getSongs() — the full lyric + credit hydration call — is NEVER made for the listing', $fakeSongData->getSongsCalls === 0);
ok('SongData::getSongsSlimIndex() is called exactly once (not once per row)', $fakeSongData->getSongsSlimIndexCalls === 1);
ok(
    'the supplementary Verified/writers query is prepared exactly once for the whole page (not once per row, not N+1)',
    $GLOBALS['__ihymnsTestDb']->prepareCalls === 1
);
ok(
    'the supplementary query is scoped to this songbook only (bound param = "TB", not an unscoped/whole-corpus query)',
    ($GLOBALS['__ihymnsTestDb']->stmts[0] ?? null)?->boundValue() === 'TB'
);

/* =========================================================================
 * Edge case — a songbook with zero matching songs must skip the
 * supplementary query entirely (nothing to look up) and must not render
 * the unofficial badge / abbreviation badge when the book is official and
 * its abbreviation equals its title (rule #1328 / #24 negative cases).
 * ========================================================================= */

$bookPS = [
    'id'          => 'PS',
    'name'        => 'PS',
    'songCount'   => 0,
    'isOfficial'  => true,
    'displayAbbr' => null,
    'compilers'   => [],
    'alternativeNames' => [],
    'links'       => [],
];
$fakeSongDataEmpty = new FakeSongData($bookPS, [], $slimIndexWholeCorpus);
$GLOBALS['__ihymnsTestDb'] = new FakeMysqli([]);
$htmlEmpty = renderSongbookPage($pagePath, $fakeSongDataEmpty, 'PS');

ok('an empty songbook renders "0 songs"', str_contains($htmlEmpty, '0 songs'));
ok('an official book with abbreviation === title hides the abbreviation badge (rule #1328)', !str_contains($htmlEmpty, 'badge bg-body-secondary'));
ok('an official book never renders the unofficial badge', !str_contains($htmlEmpty, 'songbook-unofficial-badge'));
ok(
    'zero matching songs means the supplementary Verified/writers query is skipped entirely (not run with an empty result)',
    $GLOBALS['__ihymnsTestDb']->prepareCalls === 0
);
ok('an empty songbook still calls the slim index exactly once', $fakeSongDataEmpty->getSongsSlimIndexCalls === 1);
ok('an empty songbook never calls getSongs()', $fakeSongDataEmpty->getSongsCalls === 0);

/* =========================================================================
 */

if ($failed === 0) {
    echo "\nAll $passed songbook render-parity assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n$failed of " . ($passed + $failed) . " assertion(s) failed.\n");
exit(1);
