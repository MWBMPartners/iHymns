<?php

declare(strict_types=1);

/**
 * iHymns — Dynamic XML Sitemap Generator (index + hardened, 2026-08-30)
 * ============================================================================
 *
 * ELI5
 * ----
 * This file tells search engines "here is every real page on iHymns, and
 * roughly when it last changed" — so Google/Bing know what to (re)crawl
 * without guessing. It used to be one flat list that said "everything
 * changed today" for every single URL, which is not true and tells crawlers
 * nothing useful. Now it's a small INDEX page (`/sitemap.xml`) that points at
 * several smaller lists — one per kind of page (songbooks, songs, musicians,
 * …) — each carrying an honest "last changed" date pulled straight from the
 * database, so a crawler only re-fetches a list when something in it
 * actually moved.
 *
 * DETAILED / WHAT THIS FILE COVERS (rule #33 — the sitemap is a promise to
 * search engines; every public, canonical, same-for-everyone page is in it,
 * and everything else is deliberately left out for a stated reason)
 * ----------------------------------------------------------------------------
 * IN: `/`, `/songbooks`, `/songbook/<abbr>`, `/song/<id>`, `/musician/<slug>`,
 *     `/tag/<slug>`, `/themes`, `/search`, `/help`, `/privacy`, `/terms`,
 *     `/work/<slug>`, `/publisher/<slug>` (active only), `/tune/<slug>`,
 *     `/whats-new`, `/request`.
 * OUT (with the reason recorded in `tests/php/test-sitemap-coverage.php`'s
 *     EXCLUDED map): `/favorites` and `/settings` (per-user/per-device,
 *     nothing indexable), `/stats` (thin per-device data), `/setlist`
 *     (per-user/auth-shaped — and a shared setlist link is a 256-bit
 *     CAPABILITY URL that must never be advertised), `/link` (device-pairing
 *     approval flow).
 *
 * ACCESSED VIA (.htaccess "SITEMAP ROUTING" section):
 *   /sitemap.xml                  → this file, no query string → the INDEX
 *   /sitemap-<section>.xml        → this file, ?section=<section>
 *   /sitemap-songs-<page>.xml     → this file, ?section=songs&page=<page>
 * `?section=` is validated against IHYMNS_SITEMAP_SECTIONS below (an exact
 * allow-list, never interpolated into anything) — an unknown section or an
 * out-of-range page answers a plain 404, never a guess.
 *
 * WHAT'S KEPT FROM THE ORIGINAL FILE, BYTE-FOR-BYTE IN SPIRIT
 * ----------------------------------------------------------------------------
 *  - DB-outage contract: MySQL unreachable → 503 + `Retry-After: 300` with a
 *    still-valid, DB-free body (the static section in full; the index
 *    reduced to naming only that static child; every other section emits an
 *    honest, syntactically-valid EMPTY list rather than guessing). Crawlers
 *    keep their last-known-good copy and retry later. A 503 is NEVER turned
 *    into a 304 (conditional-GET is skipped entirely on an outage).
 *  - Schema tolerance: every entity block is its own try/catch — a
 *    pre-migration install emits fewer URLs for that one entity, never a
 *    fatal error for the whole file.
 *  - `X-Content-Type-Options: nosniff` on every response mode.
 *  - Uncaught-error mirroring into the activity log.
 *
 * WHAT'S NEW
 * ----------
 *  1. Honest per-entity `<lastmod>` sourced from each table's own
 *     `UpdatedAt` (or omitted when genuinely unknown) — replacing the old
 *     "say today, every time" placeholder.
 *  2. The previously-missing public entity types (work/publisher/tune/
 *     whats-new/request) — see IHYMNS_SITEMAP_SECTIONS + the section
 *     builder functions below. `/favorites` and `/settings` removed (owner
 *     decision D2 — per-user/per-device pages have nothing to index).
 *  3. This sitemap-index + paginated-children shape (10,000 songs per
 *     child — IHYMNS_SITEMAP_PAGE_SIZE), which makes the protocol's 50,000
 *     URL / 50 MB caps structurally unreachable for the foreseeable
 *     catalogue size.
 *  4. Conditional GET (ETag / Last-Modified / 304) from cheap COUNT/MAX
 *     aggregates, and a slim `SongId + UpdatedAt` query replacing the old
 *     per-songbook `SongData::getSongs()` call — that method's own doc-block
 *     names the sitemap as a consumer of its BULK (full lyric record) shape,
 *     which this file no longer needs (rule #17 — URL strings + dates only,
 *     never a corpus materialisation).
 *  5. Host resolution now calls the ONE shared `appCanonicalHost()`
 *     (`includes/config.php`) instead of re-forking its own copy of the
 *     four-channel allow-list (rule #22 — that function's own doc-block
 *     already names the sitemap as sharing this source of truth).
 *
 * WHY THE TWO PURE HELPERS (`sitemapPageCount()`, `sitemapLastmod()`) LIVE IN
 * A SEPARATE FILE, AND NOTHING ELSE DOES
 * ----------------------------------------------------------------------------
 * See `includes/sitemap_helpers.php`'s own doc-block. Short version: this
 * file ends in `exit;` on every branch, which a test can never safely
 * `require()`; those two functions are pure enough that a CI truth-table
 * test needs to CALL them for real, so they live in a small side file with no
 * top-level side effects. Everything else here (the section registry, every
 * entity query, the fingerprint/ETag machinery, the XML renderers) stays in
 * THIS file — moving it would break three existing guards
 * (`tests/test-themes-route.js`, `tests/test-writer-musician-route.js`,
 * `tests/php/test-musician-profile-fields.php`) that read specific literals
 * (`'/musician/'`, `'/tag/'`, `FROM tblMusicians`, `'/themes'`) straight out
 * of THIS file's source text.
 *
 * @see appWeb/public_html/includes/sitemap_helpers.php  sitemapPageCount(), sitemapLastmod()
 * @see appWeb/public_html/includes/config.php            appCanonicalHost() (#526)
 * @see appWeb/public_html/includes/theme_index.php        themeIndexCounts() (#1148, now with lastTouched)
 * @see appWeb/public_html/includes/song_soft_delete.php   songVisibleSql() (#1694)
 * @see appWeb/public_html/includes/songbook_visibility.php songbookVisibleSql()/songServableSql() (#1765)
 * @see appWeb/public_html/song-media.php:296-360           the RFC 7232 conditional-GET precedent this mirrors
 * @see .claude/CLAUDE.md rule #17                          "never materialise the whole corpus"
 * @link https://www.sitemaps.org/protocol.html              sitemap + sitemap-index protocol
 * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/ETag
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'sitemap_helpers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_visibility.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'theme_index.php';

/* Mirror every uncaught \Throwable + PHP fatal into tblActivityLog
   — a sitemap generator that 500s breaks search-engine indexing,
   so the failure needs to surface in /manage/activity-log. */
installGlobalActivityLogHandlers('sitemap');

/* =========================================================================
 * CONFIGURATION
 * ========================================================================= */

/** Songs-per-child cap (owner decision D7). ~2.5 MB uncompressed per page at
 *  today's catalogue size — comfortably under the protocol's 50 MB/50,000-URL
 *  caps on EVERY child, for the foreseeable catalogue size. Kept well under
 *  the protocol's hard 50,000 (tests/php/test-sitemap-coverage.php asserts
 *  <= 45,000, leaving headroom before the hard cap). */
const IHYMNS_SITEMAP_PAGE_SIZE = 10000;

/**
 * The section registry (§3 "Option A" — sitemap index now).
 *
 * ELI5: the list of child sitemaps that exist, and which ONE of them is cut
 * into numbered pages (only `songs`, at IHYMNS_SITEMAP_PAGE_SIZE per page —
 * every other section is small enough to ship as one file).
 *
 * `?section=` is checked against these keys with `array_key_exists()` — an
 * EXACT allow-list, never interpolated into SQL or a filename. Section keys
 * are lowercase-letters-only by construction (the .htaccess child rewrite's
 * capture group is `[a-z]+`; tests/php/test-sitemap-coverage.php asserts
 * every key here matches that shape, so a future section with, say, a digit
 * in its name would fail CI rather than silently 404 forever).
 *
 * @var array<string, array{paginated: bool}>
 */
const IHYMNS_SITEMAP_SECTIONS = [
    'static'     => ['paginated' => false],
    'songbooks'  => ['paginated' => false],
    'songs'      => ['paginated' => true],
    'musicians'  => ['paginated' => false],
    'themes'     => ['paginated' => false],
    'works'      => ['paginated' => false],
    'publishers' => ['paginated' => false],
    'tunes'      => ['paginated' => false],
];

/** Base URL — the resolved host comes from the ONE shared allow-list
 *  (`appCanonicalHost()`, includes/config.php ~line 613), never a second
 *  copy of the four-channel list. $_SERVER['HTTP_HOST'] is
 *  attacker-controllable (a poisoned Host header could otherwise be rendered
 *  into every <loc> URL — cache-poisoning / SEO-poisoning); that function's
 *  own doc-block names the sitemap as sharing this exact defence (#526). */
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = appCanonicalHost();
$baseUrl = $scheme . '://' . $host;

/* =========================================================================
 * FUNCTIONS — fingerprint / conditional GET
 * ========================================================================= */

/**
 * The catalogue "has anything changed?" fingerprint — one (COUNT, MAX) pair
 * per contributing table, EACH in its own try/catch.
 *
 * ELI5: instead of re-reading the whole catalogue to see if anything is
 * different, ask each table two cheap questions — "how many rows?" and
 * "what's the newest UpdatedAt?" — and remember the answers. If either
 * answer changed since last time, something changed; if not, nothing did.
 *
 * DETAILED / WHY PER-TABLE, NOT ONE STATEMENT: `includes/songs_index_etag.php`
 * folds several sub-selects into ONE statement because ALL of ITS tables are
 * guaranteed to exist (core song/songbook tables). Several of the tables
 * here (tblWorks, tblPublishers, tblTunes) are newer, optional families that
 * may not exist on an older/partial install — a single combined statement
 * would make the WHOLE fingerprint throw the moment any ONE of them is
 * missing. Querying each table separately, each wrapped, means a missing
 * table degrades to a constant (0 rows, no max) for THAT table only — the
 * same schema-tolerance posture as the musician/theme blocks this file
 * already had.
 *
 * `tblWorkSongs` deliberately reads `CreatedAt`, not `UpdatedAt` — that
 * table has no `UpdatedAt` column (its rows are simple add/remove links, not
 * ones with in-place edits worth tracking); `CreatedAt` still catches a song
 * being added to or removed from a Work, which is the change that matters
 * for `/work/<slug>`'s content.
 *
 * `tblSongTagMap` is COUNT-only: it has no date column at all, but a tag
 * being added to or removed from a song changes the count, which is enough
 * of a signal for the themes/tag fingerprint (the per-theme `<lastmod>`
 * inside the themes CHILD sitemap is more precise — see
 * sitemapSectionThemes() — this fingerprint is only the coarse
 * "should a crawler re-fetch at all?" signal). That one count is read via
 * `themeIndexMembershipCount()` (`includes/theme_index.php`) rather than a
 * second inline query here — `test-theme-index.php` keeps that file the ONE
 * place any tag-count query lives (rule #22).
 *
 * Every table/column name here is a hardcoded literal from PHP source, never
 * user input — the rule #5 "legitimate interpolation" case, identical in
 * shape to `songs_index_etag.php`'s own query.
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return array<string, array{count:int, max:?string}> Keyed by table name.
 */
function sitemapFingerprintAggregates(\mysqli $db): array
{
    $tables = [
        'tblSongs'      => 'UpdatedAt',
        'tblSongbooks'  => 'UpdatedAt',
        'tblMusicians'  => 'UpdatedAt',
        'tblWorks'      => 'UpdatedAt',
        'tblWorkSongs'  => 'CreatedAt',
        'tblPublishers' => 'UpdatedAt',
        'tblTunes'      => 'UpdatedAt',
    ];
    $out = [];
    foreach ($tables as $table => $col) {
        $out[$table] = ['count' => 0, 'max' => null];
        try {
            $res = $db->query("SELECT COUNT(*) AS c, MAX({$col}) AS m FROM {$table}");
            $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
            if ($row !== null) {
                $out[$table] = [
                    'count' => (int)$row['c'],
                    'max'   => $row['m'] !== null ? (string)$row['m'] : null,
                ];
            }
        } catch (\Throwable $e) {
            /* table absent on this install (or another transient hiccup) —
               leave the zero/null default; this ONE table simply never
               contributes a "something changed" signal until it exists. */
        }
    }
    /* themeIndexMembershipCount() is its own fail-safe-to-0 (schema-tolerant,
       never throws) — no try/catch needed around this ONE call. */
    $out['tblSongTagMap'] = ['count' => themeIndexMembershipCount($db), 'max' => null];
    return $out;
}

/**
 * The single freshest moment across every aggregate — feeds `Last-Modified`.
 *
 * ELI5: out of everything that could have changed, when was the MOST RECENT
 * change? That's the one date `Last-Modified` reports, for every response
 * (index or any child) — a client-side date check is coarser than the ETag
 * anyway, so one shared "freshest of everything" answer is honest enough.
 *
 * @param array<string, array{count:int, max:?string}> $aggregates sitemapFingerprintAggregates()'s result.
 * @return int|null Unix timestamp, or null when nothing has a usable date.
 */
function sitemapMaxTimestamp(array $aggregates): ?int
{
    $best = null;
    foreach ($aggregates as $agg) {
        $m = $agg['max'] ?? null;
        if ($m === null || $m === '') {
            continue;
        }
        $ts = strtotime($m . ' UTC');
        if ($ts !== false && ($best === null || $ts > $best)) {
            $best = $ts;
        }
    }
    return $best;
}

/**
 * The freshest of a short list of date-like strings — small helper used to
 * combine two aggregates into one child's index `<lastmod>` (e.g. songbooks
 * = the freshest of tblSongbooks.max and tblSongs.max, since a songbook page
 * lists its songs).
 *
 * @param list<string|null> $dates
 * @return string|null The lexicographically-greatest non-empty value (safe
 *                      for `YYYY-MM-DD HH:MM:SS` strings — string comparison
 *                      sorts them chronologically), or null when all absent.
 */
function sitemapMaxOf(array $dates): ?string
{
    $best = null;
    foreach ($dates as $d) {
        if ($d === null || $d === '') {
            continue;
        }
        if ($best === null || $d > $best) {
            $best = $d;
        }
    }
    return $best;
}

/**
 * Turn the fingerprint + everything else that can change the BYTES into one
 * opaque ETag a client round-trips via `If-None-Match`.
 *
 * ELI5: a short fingerprint string that changes if ANY of "the data",
 * "which section/page you asked for", "which channel you're on" or "which
 * build of the code is running" changes — so a client's cached copy of
 * `/sitemap-songs-2.xml` never gets confused with `/sitemap-musicians.xml`,
 * or with what alpha would have served yesterday.
 *
 * `$buildTag` is the deploy-injected build number (or this file's own
 * `filemtime()` on an un-injected local checkout) — so a code change to the
 * generator itself (a new priority, a fixed bug) busts every cached copy
 * even when the underlying data didn't move, matching
 * `songsIndexEtag()`'s `$deployRef` fold (rule #35 — same idea, same file's
 * worth of reasoning, not re-explained here).
 *
 * @param array<string, array{count:int, max:?string}> $aggregates
 * @param string $section  '' for the index, else the requested section key.
 * @param int    $page     1 for a non-paginated section/the index.
 * @param string $host     The resolved canonical host (channel-specific).
 * @param string $buildTag Build number or filemtime fallback.
 * @return string A quoted ETag value, e.g. `"sm1-1a2b3c4d5e6f7890"`.
 */
function sitemapEtag(array $aggregates, string $section, int $page, string $host, string $buildTag): string
{
    $parts = [];
    foreach ($aggregates as $table => $agg) {
        $parts[] = $table . '=' . (string)($agg['count'] ?? 0) . '|' . (string)($agg['max'] ?? '');
    }
    $signal = implode(';', $parts) . '||' . $section . '|' . $page . '|' . $host . '|' . $buildTag;
    return '"sm1-' . sha1($signal) . '"';
}

/**
 * Does the client's `If-None-Match` header already name THIS ETag?
 *
 * ELI5: RFC 7232 §3.2 in miniature — a client can send `*` (matches
 * anything), or a comma-list of validators, each optionally weak-prefixed
 * (`W/"…"`). Mirrors `songsIndexEtagMatches()` (includes/songs_index_etag.php)
 * and song-media.php's inline precedent — same rule, restated here because
 * this is a standalone response path with its own ETag shape.
 *
 * @param string $ifNoneMatch The raw `If-None-Match` request header value.
 * @param string $etag        This response's own current ETag (quoted).
 * @return bool True when the client already has this exact version.
 */
function sitemapIfNoneMatchHits(string $ifNoneMatch, string $etag): bool
{
    $ifNoneMatch = trim($ifNoneMatch);
    if ($ifNoneMatch === '') {
        return false;
    }
    if ($ifNoneMatch === '*') {
        return true;
    }
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        if (str_starts_with($candidate, 'W/')) {
            $candidate = trim(substr($candidate, 2));
        }
        if ($candidate === $etag) {
            return true;
        }
    }
    return false;
}

/* =========================================================================
 * FUNCTIONS — per-entity section builders
 *
 * Each returns ['urls' => list<array{loc,lastmod,changefreq,priority}>].
 * Every one of these is its own try/catch (schema tolerance, matching the
 * original file's musician/theme blocks) — a missing table degrades that
 * ONE section to an empty list, never a fatal for the whole file.
 * ========================================================================= */

/**
 * How many songs are actually advertised — feeds pagination for BOTH the
 * index (how many `/sitemap-songs-N.xml` entries to list) and the `songs`
 * section itself (the out-of-range-page 404 check). A cheap indexed COUNT.
 *
 * The visibility decision is made HERE, directly, by calling the shared
 * predicate helpers (`songVisibleSql` — soft-delete #1694, `songServableSql`
 * — disabled-songbook #1765) — deliberately NOT via a shared "build the
 * WHERE clause" indirection: `tests/php/test-song-visibility-guard.php` and
 * `test-songbook-visibility-guard.php` scan each function's OWN code for
 * these exact calls (no cross-function tracing), so the decision has to be
 * visible right where the query is built — the same shape every other
 * `tblSongs`-reading site in this codebase already uses. This is calling the
 * ONE shared predicate FUNCTION from two sites, not re-implementing its SQL
 * (rule #35 is about the latter); `sitemapSectionSongs()` below repeats the
 * identical three lines for the same reason, one call site each.
 *
 * Also applies the existing `public_domain_only` feature flag
 * (`SongData::getSongs()` already applies it today) — the sitemap must keep
 * honouring it, or a PD-only install would advertise pages it refuses to
 * serve. Nothing here is user input; every fragment is either a hardcoded
 * literal or a helper's own output (rule #5's legitimate interpolation).
 */
function sitemapVisibleSongsCount(\mysqli $db): int
{
    $visible  = songVisibleSql($db, 's');
    $servable = songServableSql($db, 's');
    $pdOnly   = (APP_CONFIG['features']['public_domain_only'] ?? false) ? ' AND s.LyricsPublicDomain = 1' : '';
    $res = $db->query("SELECT COUNT(*) AS c FROM tblSongs s WHERE {$visible} AND {$servable}{$pdOnly}");
    return (int)(($res instanceof \mysqli_result ? $res->fetch_assoc() : null)['c'] ?? 0);
}

/**
 * Static + browse-index pages (§1a "fixed" rows), plus `/whats-new` and
 * `/request` — the two previously-missing static pages (D-nothing, they were
 * simply absent). `/favorites` and `/settings` are gone (owner decision D2);
 * `/stats` was never in the original file and stays out (owner decision D3,
 * recorded in the CI guard's EXCLUDED map with its reason).
 *
 * `/` deliberately keeps `date('Y-m-d')` — genuinely "today", honestly so:
 * the home page really does change daily (Song of the Day). Every OTHER
 * static page falls back to the deploy commit date (the "the page template
 * last changed" honest answer) — `null` on an un-injected local checkout, in
 * which case `<lastmod>` is simply omitted for that page.
 *
 * `/songbooks` and `/themes` are NOT here — despite being fixed-URL pages,
 * their honest `<lastmod>` needs the songbooks/themes CONTENT (the browse
 * index changes when the things it lists change), so they are emitted as the
 * first row of sitemapSectionSongbooks()/sitemapSectionThemes() instead,
 * where that date is already being computed.
 */
function sitemapSectionStatic(string $baseUrl, ?string $commitDate): array
{
    $lastmod = sitemapLastmod(null, $commitDate);
    $pages = [
        '/search'    => ['priority' => '0.7', 'changefreq' => 'monthly'],
        '/help'      => ['priority' => '0.4', 'changefreq' => 'monthly'],
        '/privacy'   => ['priority' => '0.3', 'changefreq' => 'yearly'],
        '/terms'     => ['priority' => '0.3', 'changefreq' => 'yearly'],
        '/whats-new' => ['priority' => '0.4', 'changefreq' => 'weekly'],
        '/request'   => ['priority' => '0.3', 'changefreq' => 'monthly'],
    ];

    $urls = [
        [
            'loc'        => $baseUrl . '/',
            /* Home genuinely changes every day (Song of the Day) — this is
               the ONE deliberate "today" in the whole file; every other
               entity below sources a real date or omits <lastmod>. */
            'lastmod'    => date('Y-m-d'),
            'changefreq' => 'daily',
            'priority'   => '1.0',
        ],
    ];
    foreach ($pages as $path => $meta) {
        $urls[] = [
            'loc'        => $baseUrl . $path,
            'lastmod'    => $lastmod,
            'changefreq' => $meta['changefreq'],
            'priority'   => $meta['priority'],
        ];
    }
    return ['urls' => $urls];
}

/**
 * Songbook pages, plus `/songbooks` itself as the first row.
 *
 * `<lastmod>` per songbook is `GREATEST(book.UpdatedAt, MAX(its visible
 * songs' UpdatedAt))` (§2) — a songbook page lists its songs, so it changes
 * when they do, not only when the book row itself is edited. `/songbooks`
 * takes the freshest of every book's own computed date (it lists them all).
 */
function sitemapSectionSongbooks(\mysqli $db, string $baseUrl): array
{
    $urls = [];
    $freshest = null;
    try {
        $visibleB  = songbookVisibleSql($db, 'b');
        $visibleS  = songVisibleSql($db, 's');
        $sql = "SELECT b.Abbreviation AS abbr,
                       GREATEST(b.UpdatedAt, COALESCE(MAX(s.UpdatedAt), b.UpdatedAt)) AS lastTouched
                  FROM tblSongbooks b
                  LEFT JOIN tblSongs s ON s.SongbookAbbr = b.Abbreviation AND {$visibleS}
                 WHERE {$visibleB}
                 GROUP BY b.Abbreviation, b.UpdatedAt
                 ORDER BY b.Abbreviation";
        $res = $db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $abbr = (string)($row['abbr'] ?? '');
            if ($abbr === '') {
                continue;
            }
            $lm = sitemapLastmod((string)($row['lastTouched'] ?? ''));
            if ($lm !== null && ($freshest === null || $lm > $freshest)) {
                $freshest = $lm;
            }
            $urls[] = [
                'loc'        => $baseUrl . '/songbook/' . rawurlencode($abbr),
                'lastmod'    => $lm,
                'changefreq' => 'weekly',
                'priority'   => '0.8',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[sitemap] songbooks section unavailable: ' . $e->getMessage());
    }
    array_unshift($urls, [
        'loc'        => $baseUrl . '/songbooks',
        'lastmod'    => $freshest, // the freshest of every book's own row above — omitted (null) if none resolved
        'changefreq' => 'weekly',
        'priority'   => '0.9',
    ]);
    return ['urls' => $urls];
}

/**
 * Song pages, ONE page of IHYMNS_SITEMAP_PAGE_SIZE rows.
 *
 * A slim `SongId + UpdatedAt` query — NOT `SongData::getSongs($abbr)` (the
 * heavy songbook-export shape carrying full lyric records), which the
 * original file called once PER SONGBOOK, ~14k full records per hit
 * (rule #17). Ordered by SongId (not the old per-songbook grouping) so
 * pagination is a plain, stable `LIMIT`/`OFFSET` walk.
 *
 * The visibility decision (`songVisibleSql`/`songServableSql`/PD-flag) is
 * made directly in THIS function, not via a shared helper — see
 * `sitemapVisibleSongsCount()`'s doc-block for why (the visibility-guard
 * tests scan per-function, not across calls).
 */
function sitemapSectionSongs(\mysqli $db, string $baseUrl, int $page): array
{
    $urls = [];
    try {
        $visible  = songVisibleSql($db, 's');
        $servable = songServableSql($db, 's');
        $pdOnly   = (APP_CONFIG['features']['public_domain_only'] ?? false) ? ' AND s.LyricsPublicDomain = 1' : '';
        $limit    = IHYMNS_SITEMAP_PAGE_SIZE;
        $offset   = ($page - 1) * IHYMNS_SITEMAP_PAGE_SIZE;
        $stmt = $db->prepare(
            "SELECT s.SongId AS id, s.UpdatedAt AS updated
               FROM tblSongs s
              WHERE {$visible} AND {$servable}{$pdOnly}
              ORDER BY s.SongId
              LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $id = (string)($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $urls[] = [
                'loc'        => $baseUrl . '/song/' . rawurlencode($id),
                'lastmod'    => sitemapLastmod((string)($row['updated'] ?? '')),
                'changefreq' => 'monthly',
                'priority'   => '0.6',
            ];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[sitemap] songs section unavailable (page ' . $page . '): ' . $e->getMessage());
    }
    return ['urls' => $urls];
}

/**
 * Musician pages (#1741 P4a-3, registry-driven — one <loc> per tblMusicians
 * row with a slug, exactly as before this refactor; only the lastmod source
 * changed, from "today" to the row's own UpdatedAt).
 */
function sitemapSectionMusicians(\mysqli $db, string $baseUrl): array
{
    $urls = [];
    try {
        $res = $db->query(
            "SELECT Slug, UpdatedAt FROM tblMusicians WHERE Slug IS NOT NULL AND Slug <> '' ORDER BY Slug"
        );
        while ($row = $res->fetch_assoc()) {
            $slug = (string)($row['Slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'loc'        => $baseUrl . '/musician/' . rawurlencode($slug),
                'lastmod'    => sitemapLastmod((string)($row['UpdatedAt'] ?? '')),
                'changefreq' => 'monthly',
                'priority'   => '0.5',
            ];
        }
        if ($res instanceof \mysqli_result) {
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[sitemap] musicians section unavailable: ' . $e->getMessage());
    }
    return ['urls' => $urls];
}

/**
 * `/themes` plus `/tag/<slug>` per theme with at least one visible song —
 * sourced from the ONE visible-song count core (`themeIndexCounts()`, #1148)
 * so the sitemap can never advertise a theme the `/themes` index itself
 * hides. `/themes` takes the freshest `lastTouched` among the theme rows
 * fetched (the additive column this hardening pass adds to that core).
 */
function sitemapSectionThemes(\mysqli $db, string $baseUrl): array
{
    $urls = [];
    $freshest = null;
    try {
        foreach (themeIndexCounts($db) as $theme) {
            $slug = (string)($theme['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $lm = sitemapLastmod($theme['lastTouched'] ?? null);
            if ($lm !== null && ($freshest === null || $lm > $freshest)) {
                $freshest = $lm;
            }
            $urls[] = [
                'loc'        => $baseUrl . '/tag/' . rawurlencode($slug),
                'lastmod'    => $lm,
                'changefreq' => 'monthly',
                'priority'   => '0.5',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[sitemap] themes section unavailable: ' . $e->getMessage());
    }
    array_unshift($urls, [
        'loc'        => $baseUrl . '/themes',
        'lastmod'    => $freshest,
        'changefreq' => 'weekly',
        'priority'   => '0.6',
    ]);
    return ['urls' => $urls];
}

/**
 * `/work/<slug>` pages (rule #14 — `tblWorks`, one canonical page per
 * composition). `<lastmod>` is `GREATEST(work.UpdatedAt, MAX(membership
 * CreatedAt))` — `tblWorkSongs` has no `UpdatedAt` column (its rows are
 * simple add/remove links), so `CreatedAt` is used instead: it still catches
 * a song being added to or removed from the work, just not an in-place edit
 * of an existing membership row's own Note (a real but small gap, understood
 * and accepted — see sitemapFingerprintAggregates()'s doc-block for the same
 * point made once, not repeated per call site, rule #35).
 */
function sitemapSectionWorks(\mysqli $db, string $baseUrl): array
{
    $urls = [];
    try {
        $sql = "SELECT w.Slug AS slug,
                       GREATEST(w.UpdatedAt, COALESCE(MAX(ws.CreatedAt), w.UpdatedAt)) AS lastTouched
                  FROM tblWorks w
                  LEFT JOIN tblWorkSongs ws ON ws.WorkId = w.Id
                 GROUP BY w.Id, w.Slug, w.UpdatedAt
                 ORDER BY w.Slug";
        $res = $db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $slug = (string)($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'loc'        => $baseUrl . '/work/' . rawurlencode($slug),
                'lastmod'    => sitemapLastmod((string)($row['lastTouched'] ?? '')),
                'changefreq' => 'monthly',
                'priority'   => '0.5',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[sitemap] works section unavailable: ' . $e->getMessage());
    }
    return ['urls' => $urls];
}

/**
 * `/publisher/<slug>` pages — ACTIVE ONLY (`IsActive = 1`, owner decision
 * D6). A defunct publisher (`IsActive = 0`) is soft-hidden from pickers but
 * its public page still resolves if directly linked (rule #37) — the
 * sitemap simply doesn't go out of its way to advertise crawling it.
 */
function sitemapSectionPublishers(\mysqli $db, string $baseUrl): array
{
    $urls = [];
    try {
        $res = $db->query('SELECT Slug, UpdatedAt FROM tblPublishers WHERE IsActive = 1 ORDER BY Slug');
        while ($row = $res->fetch_assoc()) {
            $slug = (string)($row['Slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'loc'        => $baseUrl . '/publisher/' . rawurlencode($slug),
                'lastmod'    => sitemapLastmod((string)($row['UpdatedAt'] ?? '')),
                'changefreq' => 'monthly',
                'priority'   => '0.4',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[sitemap] publishers section unavailable: ' . $e->getMessage());
    }
    return ['urls' => $urls];
}

/**
 * `/tune/<slug>` pages — EVERY registry row (owner decision D5, matching the
 * musician precedent: the registry was backfilled from every distinct
 * `TuneName` already in use, so registry membership IS the curation signal —
 * no separate "is this tune actually linked to a song" filter needed).
 */
function sitemapSectionTunes(\mysqli $db, string $baseUrl): array
{
    $urls = [];
    try {
        $res = $db->query('SELECT Slug, UpdatedAt FROM tblTunes ORDER BY Slug');
        while ($row = $res->fetch_assoc()) {
            $slug = (string)($row['Slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'loc'        => $baseUrl . '/tune/' . rawurlencode($slug),
                'lastmod'    => sitemapLastmod((string)($row['UpdatedAt'] ?? '')),
                'changefreq' => 'monthly',
                'priority'   => '0.4',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[sitemap] tunes section unavailable: ' . $e->getMessage());
    }
    return ['urls' => $urls];
}

/** Dispatch one section name (already validated against
 *  IHYMNS_SITEMAP_SECTIONS by the caller) to its builder. */
function sitemapBuildSection(string $section, \mysqli $db, string $baseUrl, int $page, ?string $commitDate): array
{
    switch ($section) {
        case 'static':     return sitemapSectionStatic($baseUrl, $commitDate);
        case 'songbooks':  return sitemapSectionSongbooks($db, $baseUrl);
        case 'songs':      return sitemapSectionSongs($db, $baseUrl, $page);
        case 'musicians':  return sitemapSectionMusicians($db, $baseUrl);
        case 'themes':     return sitemapSectionThemes($db, $baseUrl);
        case 'works':      return sitemapSectionWorks($db, $baseUrl);
        case 'publishers': return sitemapSectionPublishers($db, $baseUrl);
        case 'tunes':      return sitemapSectionTunes($db, $baseUrl);
    }
    /* Unreachable in practice — the caller already checked $section against
       IHYMNS_SITEMAP_SECTIONS before calling this (a plain 404 for anything
       else). A defensive throw rather than a silent empty list, so a future
       registry entry that forgets its case here fails LOUD in dev/CI rather
       than quietly shipping an empty child sitemap forever. */
    throw new \InvalidArgumentException('sitemapBuildSection(): no builder wired for section ' . $section);
}

/**
 * Build the INDEX's child-sitemap list — cheap, from the fingerprint
 * aggregates ALREADY computed for the ETag (rule #35 — one mechanism, two
 * consumers), never by fully building every section just to summarise it.
 *
 * ELI5: for each child sitemap, guess a good "last changed" date from the
 * numbers we already have lying around, so a crawler can tell which children
 * are worth re-fetching without downloading all of them first.
 *
 * Each child's date is a deliberately COARSE, SAFE-DIRECTION approximation —
 * e.g. every `/sitemap-songs-N.xml` page shares the SAME global "newest song
 * edited anywhere" date, not a precise per-page date (which would need an
 * extra query per page). Over-estimating freshness costs one occasional
 * extra re-fetch; under-estimating would risk a crawler skipping a page that
 * really did change — the same safe-direction argument this whole plan
 * makes about `<lastmod>` throughout (§2).
 *
 * @param array<string, array{count:int, max:?string}> $aggregates
 * @return list<array{loc:string, lastmod:?string}>
 */
function sitemapRenderIndexEntries(\mysqli $db, string $baseUrl, ?string $commitDate, array $aggregates): array
{
    $songsMax = $aggregates['tblSongs']['max'] ?? null;

    $entries = [];
    $entries[] = ['loc' => $baseUrl . '/sitemap-static.xml', 'lastmod' => sitemapLastmod(null, $commitDate)];
    $entries[] = ['loc' => $baseUrl . '/sitemap-songbooks.xml', 'lastmod' => sitemapLastmod(
        sitemapMaxOf([$aggregates['tblSongbooks']['max'] ?? null, $songsMax])
    )];

    $totalSongs = 0;
    try {
        $totalSongs = sitemapVisibleSongsCount($db);
    } catch (\Throwable $e) {
        error_log('[sitemap] index: could not count songs for pagination: ' . $e->getMessage());
    }
    $songPages = sitemapPageCount($totalSongs, IHYMNS_SITEMAP_PAGE_SIZE);
    for ($p = 1; $p <= $songPages; $p++) {
        $entries[] = ['loc' => $baseUrl . '/sitemap-songs-' . $p . '.xml', 'lastmod' => sitemapLastmod($songsMax)];
    }

    $entries[] = ['loc' => $baseUrl . '/sitemap-musicians.xml', 'lastmod' => sitemapLastmod($aggregates['tblMusicians']['max'] ?? null)];
    /* themes has no direct date aggregate of its own (tblSongTags/
       tblSongTagMap carry no UpdatedAt) — the songs aggregate is used as a
       proxy, since a theme page's content is entirely the songs that carry
       it. The themes CHILD sitemap itself carries a precise per-theme date
       (sitemapSectionThemes()) — this is only the coarse index-level hint. */
    $entries[] = ['loc' => $baseUrl . '/sitemap-themes.xml', 'lastmod' => sitemapLastmod($songsMax)];
    $entries[] = ['loc' => $baseUrl . '/sitemap-works.xml', 'lastmod' => sitemapLastmod(
        sitemapMaxOf([$aggregates['tblWorks']['max'] ?? null, $aggregates['tblWorkSongs']['max'] ?? null])
    )];
    $entries[] = ['loc' => $baseUrl . '/sitemap-publishers.xml', 'lastmod' => sitemapLastmod($aggregates['tblPublishers']['max'] ?? null)];
    $entries[] = ['loc' => $baseUrl . '/sitemap-tunes.xml', 'lastmod' => sitemapLastmod($aggregates['tblTunes']['max'] ?? null)];

    return $entries;
}

/* =========================================================================
 * FUNCTIONS — XML renderers (shared by every response mode: healthy index,
 * healthy section, degraded-503 index, degraded-503 static, degraded-503
 * empty section — ONE emitter for a urlset, ONE for an index, rule #22).
 * ========================================================================= */

/** @param list<array{loc:string, lastmod:?string, changefreq:string, priority:string}> $urls */
function sitemapEmitUrlset(array $urls): void
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $url): ?>
    <url>
        <loc><?= htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') ?></loc>
<?php if (!empty($url['lastmod'])): ?>
        <lastmod><?= htmlspecialchars((string)$url['lastmod'], ENT_XML1, 'UTF-8') ?></lastmod>
<?php endif; ?>
        <changefreq><?= htmlspecialchars($url['changefreq'], ENT_XML1, 'UTF-8') ?></changefreq>
        <priority><?= htmlspecialchars($url['priority'], ENT_XML1, 'UTF-8') ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
<?php
}

/** @param list<array{loc:string, lastmod:?string}> $entries */
function sitemapEmitIndex(array $entries): void
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($entries as $entry): ?>
    <sitemap>
        <loc><?= htmlspecialchars($entry['loc'], ENT_XML1, 'UTF-8') ?></loc>
<?php if (!empty($entry['lastmod'])): ?>
        <lastmod><?= htmlspecialchars((string)$entry['lastmod'], ENT_XML1, 'UTF-8') ?></lastmod>
<?php endif; ?>
    </sitemap>
<?php endforeach; ?>
</sitemapindex>
<?php
}

/* =========================================================================
 * REQUEST HANDLING
 * ========================================================================= */

/* nosniff on EVERY response mode (index, section, 404, 503) — set once,
   here, before any branch can exit early. */
header('X-Content-Type-Options: nosniff');

/* ---- 1. Route parsing + STATIC validation (no DB touched yet) ---------- */

$section = isset($_GET['section']) ? strtolower(trim((string)$_GET['section'])) : null;
if ($section === '') {
    $section = null;
}
$pageParam = isset($_GET['page']) ? trim((string)$_GET['page']) : '';
$page = ($pageParam !== '' && ctype_digit($pageParam)) ? (int)$pageParam : 1;
if ($page < 1) {
    $page = 1;
}

/* An unknown section is a plain 404 — never a guess, never SQL built from
   the raw value (array_key_exists against the exact IHYMNS_SITEMAP_SECTIONS
   allow-list, not a pattern match). */
if ($section !== null && !array_key_exists($section, IHYMNS_SITEMAP_SECTIONS)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
$isPaginated = $section !== null && IHYMNS_SITEMAP_SECTIONS[$section]['paginated'];
/* A page number on a section that isn't paginated is also a 404 — e.g.
   /sitemap-musicians-2.xml names a page that structurally cannot exist. */
if ($section !== null && !$isPaginated && $pageParam !== '' && $pageParam !== '1') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}

/* ---- 1.5. Per-channel search-visibility gate (#2024/#2025) -------------
 * ELI5: an admin can switch a whole copy of the site (production/beta/
 * alpha) OFF from search engines. When THIS channel is off, the sitemap
 * itself is one of the three things that switches off — no point actively
 * inviting a crawler to a channel we've just asked it not to list.
 *
 * WHY HERE, EXACTLY: after the static shape checks above (an unknown
 * section/page is a 404 on every channel for the same reason, so nothing
 * leaks either way) and BEFORE both the DB fingerprint work (step 2 — no
 * wasted aggregate queries on a hidden channel) and the conditional-GET
 * block (step 4 — a crawler holding a cached copy + ETag must get a real
 * 404, never a 304 "still good" for content we've asked it to forget).
 * `searchEngineVisibleHere()` itself performs the request's first DB read
 * (via getAppSetting(), through the shared connection) — on a DB outage it
 * returns the safe default, so a hidden channel simply 404s (its steady
 * state anyway) while production falls through to the existing, fully
 * preserved 503 degraded path below. Every other contract in this file
 * (503 + Retry-After, the host whitelist, nosniff already set above, the
 * activity-log handlers) is untouched — this gate only ever adds an early
 * exit, never changes an existing branch. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'search_visibility.php';
if (!searchEngineVisibleHere()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}

/* ---- 2. DB connectivity + fingerprint ----------------------------------- */

/* DB-direct (WS-J #1020): getDbMysqli() THROWS when MySQL is unreachable —
   there is no stale-JSON fallback any more. A 500 sitemap breaks
   search-engine indexing, so on a DB outage we degrade to a still-valid,
   DB-free body per response mode (see the RENDER section below) and signal
   503 + Retry-After: crawlers keep their last-known-good copy and retry.
   The fingerprint aggregates are computed in the SAME try block — per §4a's
   "outage rule", a fingerprint failure must fall into this identical 503
   path, never a silently-stale 304 (a 503 is NEVER turned into a 304,
   enforced structurally below by only attempting conditional-GET when
   $dbAvailable is true). */
$db          = null;
$dbAvailable = false;
$aggregates  = [];
try {
    $db         = getDbMysqli();
    $aggregates = sitemapFingerprintAggregates($db);
    $dbAvailable = true;
} catch (\Throwable $e) {
    error_log('[sitemap] DB unavailable, emitting a degraded body: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
}

/* Deploy metadata — the commit date backs every static page's <lastmod>
   fallback; the build number folds into the ETag so a code deploy busts
   cached copies even when the underlying data didn't move. Needed on both
   the healthy and degraded paths (the degraded static/index bodies still
   want an honest commit-date fallback), so this is read unconditionally. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'infoAppVer.php';
$commitDate = $app['Application']['Version']['Repo']['Commit']['Date'] ?? null;

/* ---- 3. Out-of-range page (needs the DB; a 404 here carries no cache
 *        headers — it isn't the "this content is valid and unchanged"
 *        signal an ETag/304 makes) ------------------------------------- */

if ($dbAvailable && $isPaginated) {
    $totalItems = 0;
    try {
        $totalItems = sitemapVisibleSongsCount($db);
    } catch (\Throwable $e) {
        error_log('[sitemap] could not count songs for pagination: ' . $e->getMessage());
    }
    $totalPages = sitemapPageCount($totalItems, IHYMNS_SITEMAP_PAGE_SIZE);
    if ($page > $totalPages) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found.\n";
        exit;
    }
}

/* ---- 4. Conditional GET (only when the DB answered — §4a) -------------- */

if ($dbAvailable) {
    $buildNumber = $app['Application']['Version']['Build']['Number'] ?? null;
    $buildTag    = $buildNumber !== null ? (string)$buildNumber : (string)@filemtime(__FILE__);
    $etag        = sitemapEtag($aggregates, $section ?? '', $page, $host, $buildTag);
    $lastModTs   = sitemapMaxTimestamp($aggregates);

    header('ETag: ' . $etag);
    if ($lastModTs !== null) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModTs) . ' GMT');
    }
    /* Belt-and-braces: .htaccess also carves out a `public, max-age=3600`
       Cache-Control for this file's own FilesMatch (SITEMAP ROUTING
       section) — that later, more-specific directive is what actually
       reaches the client (it runs after PHP, in the same scope), but
       setting it here too keeps this file correct even served outside
       that .htaccess (a local PHP dev server, say). */
    header('Cache-Control: public, max-age=3600');

    $notModified = false;
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '') {
        $notModified = sitemapIfNoneMatchHits($ifNoneMatch, $etag);
    } elseif ($lastModTs !== null) {
        /* Per RFC 7232 §6, If-Modified-Since is consulted ONLY when the
           client sent no If-None-Match at all (song-media.php's precedent). */
        $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince !== '') {
            $imsTs = strtotime($ifModifiedSince);
            $notModified = ($imsTs !== false && $lastModTs <= $imsTs);
        }
    }
    if ($notModified) {
        http_response_code(304);
        exit;
    }
}

/* ---- 5. Render ----------------------------------------------------------- */

if (!$dbAvailable) {
    /* Degraded body per response mode — every mode still answers something
       syntactically valid, all under the 503 already set above:
         - the index degrades to naming only the DB-free static child;
         - ?section=static renders in FULL (it needs no DB at all);
         - every other section answers an honest, empty urlset (we cannot
           know what belongs in it without the database). */
    if ($section === null) {
        sitemapEmitIndex([['loc' => $baseUrl . '/sitemap-static.xml', 'lastmod' => sitemapLastmod(null, $commitDate)]]);
        exit;
    }
    if ($section === 'static') {
        sitemapEmitUrlset(sitemapSectionStatic($baseUrl, $commitDate)['urls']);
        exit;
    }
    sitemapEmitUrlset([]);
    exit;
}

if ($section === null) {
    sitemapEmitIndex(sitemapRenderIndexEntries($db, $baseUrl, $commitDate, $aggregates));
    exit;
}

$built = sitemapBuildSection($section, $db, $baseUrl, $page, $commitDate);

/* Belt-and-braces cap warning (§7 step 4) — songs is the only section
   expected to approach real scale (structurally bounded by pagination
   already); any OTHER section crossing 45,000 rows is a signal the data
   model changed in a way this file's design didn't anticipate, worth a
   curator's attention rather than a silent, ever-growing single file.
   logActivity() never throws and never blocks the response (its own
   doc-block's "best-effort guarantee"). */
if (!$isPaginated && count($built['urls']) > 45000) {
    logActivity('sitemap.cap_warning', 'sitemap', $section, ['count' => count($built['urls'])], 'error');
}

sitemapEmitUrlset($built['urls']);
exit;
