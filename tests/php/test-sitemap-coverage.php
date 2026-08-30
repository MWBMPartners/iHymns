<?php

declare(strict_types=1);

/**
 * iHymns — Sitemap coverage + hardening guard (dynamic-sitemap hardening, 2026-08-30)
 * ============================================================================
 *
 * ELI5
 * ----
 * The sitemap is a promise to search engines: "here is every real page on
 * this site." This guard makes sure that promise stays true automatically —
 * it looks at the REAL list of public page fragments on disk (the same list
 * `includes/spa_routes.php` uses to decide what's a real route) and checks
 * that EVERY one of them is either advertised in `sitemap.xml.php` or
 * deliberately, explicitly left out with a reason written down here. A new
 * public page that forgets the sitemap fails THIS build, not a future SEO
 * audit six months from now (rule #33: a URL another page emits — or, here,
 * a page the app itself serves — is a contract).
 *
 * WHAT THIS FILE ASSERTS (tree-derived + mutation-proven, rule #34)
 * ----------------------------------------------------------------------------
 *  1. COVERAGE — every `includes/pages/*.php` basename (minus the four
 *     internal fragments `IHYMNS_SPA_INTERNAL_PAGES` already names — the
 *     REAL constant, never re-typed) is in EXACTLY ONE of this file's
 *     $INCLUDED / $EXCLUDED maps: $INCLUDED names the URL-path literal the
 *     sitemap source must contain; $EXCLUDED names a non-empty reason. A
 *     page in neither, or in both, or a stale map entry naming a page that
 *     no longer exists, is a failure.
 *  2. CAP SAFETY — `IHYMNS_SITEMAP_PAGE_SIZE` is defined, <= 45,000 (leaving
 *     headroom under the protocol's hard 50,000), the songs section's SQL
 *     carries a bound `LIMIT ?` (never an interpolated one), and a real
 *     truth table for the extracted pure `sitemapPageCount()` helper
 *     (0 -> 1 page, 10000 -> 1, 10001 -> 2, …).
 *  3. HOST NON-FORK — the file calls the ONE shared `appCanonicalHost()`
 *     (#526) and holds NO array literal re-forking the four-channel host
 *     allow-list.
 *  4. .HTACCESS LOCKSTEP — the child-sitemap rewrite rule exists alongside
 *     the original `^sitemap\.xml$` rule, and every section key in
 *     `IHYMNS_SITEMAP_SECTIONS` is lowercase-letters-only (the shape the
 *     `.htaccess` rewrite's `[a-z]+` capture group actually accepts — a key
 *     with a digit or hyphen would silently 404 forever).
 *  5. LASTMOD HONESTY — a truth table for the extracted pure
 *     `sitemapLastmod()` helper, PLUS the retired "one shared `$today` for
 *     every entity" pattern does not reappear anywhere in the file, PLUS
 *     the home page's deliberate `date('Y-m-d')` "today" appears EXACTLY
 *     ONCE (proving it stayed the one deliberate exception, not a habit
 *     that crept back into a second entity loop).
 *  6. CAPABILITY-URL BAN — the string `/setlist/shared` never appears in the
 *     sitemap source. A shared set-list link is a 256-bit CAPABILITY URL
 *     (#1791); advertising one to every search engine would leak a private
 *     link to the world — the worst possible regression for this file.
 *  7. THE VISIBILITY GATE (search-engine visibility feature, #2024/#2025) —
 *     the file calls the ONE shared `searchEngineVisibleHere()` (never a raw
 *     re-read of the setting), and does so BEFORE `getDbMysqli(` (no wasted
 *     DB fingerprint work on a hidden channel) AND before
 *     `HTTP_IF_NONE_MATCH` (a hidden channel can never answer a cached-copy
 *     304 — it must always get a real 404). The `getDbMysqli(` anchor itself
 *     is asserted to appear EXACTLY ONCE first, so the ordering check can't
 *     quietly become meaningless if a second call ever appears elsewhere.
 *
 * WHY THE TRUTH TABLES CALL REAL FUNCTIONS, NOT JUST REGEX THE SOURCE
 * ----------------------------------------------------------------------------
 * `sitemap.xml.php` is a normal request-handling page — every branch ends in
 * `exit;`, so `require`-ing it here would silently kill THIS test process at
 * whichever branch runs first. `sitemapPageCount()` and `sitemapLastmod()`
 * are pure enough to have no reason to share that fate, so they live in
 * `includes/sitemap_helpers.php` (function/const definitions only, no
 * top-level side effects — see that file's own doc-block) and THIS guard
 * requires that small file directly and calls them for real. Every other
 * assertion here is static source-text analysis (comment-stripped, matching
 * `tests/php/test-theme-index.php`'s established shape) — exactly the
 * "no DB needed" posture this whole guard is designed to have.
 *
 * MUTATION-PROOFING (run during development; the exact procedure + results
 * are recorded in the tracking issue this guard shipped with, and in this
 * PR's commit history):
 *   1. Delete the '/work/' emission                → PASS 1 (INCLUDED) goes RED
 *   2. `touch includes/pages/zzz-probe.php`         → PASS 1 (unclassified) goes RED
 *   3. Add a stale EXCLUDED entry `'zzz' => '...'`  → PASS 1 (stale entry) goes RED
 *   4. Set IHYMNS_SITEMAP_PAGE_SIZE to 60000        → PASS 2 (cap) goes RED
 *   5. Reintroduce the hardcoded host array literal → PASS 3 (host non-fork) goes RED
 *   6. Remove the `.htaccess` child rewrite rule    → PASS 4 (.htaccess lockstep) goes RED
 *   7. Rename a section key to include a digit      → PASS 4 (key shape) goes RED
 *   8. Reintroduce `'lastmod' => $today` in a loop  → PASS 5 (retired pattern) goes RED
 *   9. Add a second `date('Y-m-d')` entity lastmod  → PASS 5 (exactly-once) goes RED
 *  10. Add the literal '/setlist/shared' to the file → PASS 6 goes RED
 *  11. Delete the visibility-gate call               → PASS 7.1 goes RED
 *  12. Move the gate block to just before
 *      `sitemapEmitUrlset($built['urls']);`           → PASS 7.2b and 7.3 go RED
 * Every mutation was performed once against the real tree, confirmed RED
 * (naming the right thing), then reverted byte-identical; the guard was also
 * confirmed GREEN on the correct code both before and after (rule #34's
 * "under-reporting is worse than no scanner, and a guard that fails on
 * correct code gets deleted" — both directions checked).
 *
 *   php tests/php/test-sitemap-coverage.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch.
 *
 * @see appWeb/public_html/sitemap.xml.php
 * @see appWeb/public_html/includes/sitemap_helpers.php
 * @see appWeb/public_html/includes/spa_routes.php   IHYMNS_SPA_INTERNAL_PAGES (the REAL constant)
 * @see tests/php/test-theme-index.php               the comment-stripping precedent this mirrors
 * @see tests/php/test-manage-action-api-coverage.php the INCLUDED/EXCLUDED-map coverage-guard precedent (rule #48)
 * @link .claude/CLAUDE.md rule #33/#34/#35/#48
 */

$repo          = dirname(__DIR__, 2);
$webRoot       = $repo . '/appWeb/public_html';
$sitemapFile   = $webRoot . '/sitemap.xml.php';
$helpersFile   = $webRoot . '/includes/sitemap_helpers.php';
$htaccessFile  = $webRoot . '/.htaccess';
$spaRoutesFile = $webRoot . '/includes/spa_routes.php';
$pagesDir      = $webRoot . '/includes/pages';

$passed = 0;
$failed = 0;

function sc(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

/** Blank out comments, preserving offsets — the tests/php/test-theme-index.php
 *  precedent (`tiStrip()`), so a doc-comment mentioning a banned pattern for
 *  ILLUSTRATION can never false-positive a check that scans real code. */
function scStrip(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

foreach ([$sitemapFile, $helpersFile, $htaccessFile, $spaRoutesFile, $pagesDir] as $f) {
    if (!file_exists($f)) {
        fwrite(STDERR, "FATAL: missing {$f} — cannot derive the sitemap contract.\n");
        exit(1);
    }
}

$sitemapRaw     = (string)file_get_contents($sitemapFile);
$sitemapStripped = scStrip($sitemapRaw);
$htaccessRaw    = (string)file_get_contents($htaccessFile);

/* =========================================================================
 * PASS 1 — COVERAGE: every real page fragment is INCLUDED (with the URL
 * literal it must appear as in sitemap.xml.php's source) or EXCLUDED (with a
 * plain-English reason) — never neither, never both, never stale.
 * ========================================================================= */

require_once $spaRoutesFile; // IHYMNS_SPA_INTERNAL_PAGES — the REAL constant, never re-typed here

/** page-fragment basename => the URL-path literal that must appear,
 *  single-quoted, in sitemap.xml.php's (comment-stripped) source. */
$INCLUDED = [
    'help'           => '/help',
    'musician'       => '/musician/',
    'privacy'        => '/privacy',
    'publisher'      => '/publisher/',
    'request-a-song' => '/request',     // the app's own de-facto canonical spelling (song.php, help.php)
    'search'         => '/search',
    'song'           => '/song/',
    'songbook'       => '/songbook/',
    'songbooks'      => '/songbooks',
    'tag'            => '/tag/',
    'terms'          => '/terms',
    'themes'         => '/themes',
    'tune'           => '/tune/',
    'whats-new'      => '/whats-new',
    'work'           => '/work/',
];

/** page-fragment basename => plain-English reason it is deliberately absent
 *  (§1b of the plan this guard stands over). */
$EXCLUDED = [
    'favorites' => 'Per-user content (localStorage/account) — nothing indexable. Removed from the sitemap (owner decision D2).',
    'link'      => 'Device-pairing approval flow (RFC 8628) — auth-required, transactional, nothing public to index.',
    'setlist'   => 'Per-user, auth-shaped content; a shared set-list link is a 256-bit CAPABILITY URL (#1791) that must never be advertised to a crawler.',
    'settings'  => 'Per-device preferences page — thin, nothing indexable. Removed from the sitemap (owner decision D2).',
    'stats'     => 'Almost entirely per-device data computed client-side from localStorage — thin for search (owner decision D3).',
];

$realPages = [];
foreach (glob($pagesDir . '/*.php') ?: [] as $file) {
    $seg = basename($file, '.php');
    if (in_array($seg, IHYMNS_SPA_INTERNAL_PAGES, true)) {
        continue; // 'home' / 'not-found' / 'identifier' / 'setlist-shared' — not first-segment routes
    }
    $realPages[] = $seg;
}
sort($realPages);
sc('1.0 the pages/ glob found at least one real page (the derivation works at all)', count($realPages) > 0);

foreach ($realPages as $page) {
    $inIncluded = array_key_exists($page, $INCLUDED);
    $inExcluded = array_key_exists($page, $EXCLUDED);
    sc("1.1 '{$page}' is classified in exactly one of \$INCLUDED / \$EXCLUDED",
        $inIncluded xor $inExcluded);

    if ($inIncluded) {
        $needle = "'" . $INCLUDED[$page] . "'";
        sc("1.2 '{$page}': sitemap.xml.php's source contains the literal {$needle}",
            strpos($sitemapStripped, $needle) !== false);
    }
    if ($inExcluded) {
        sc("1.3 '{$page}': the EXCLUDED reason is a real, non-empty sentence",
            trim($EXCLUDED[$page]) !== '');
    }
}

/* Stale-entry direction: every map key must still be a real page. */
foreach (array_keys($INCLUDED) as $key) {
    sc("1.4 INCLUDED['{$key}'] names a page fragment that still exists", in_array($key, $realPages, true));
}
foreach (array_keys($EXCLUDED) as $key) {
    sc("1.5 EXCLUDED['{$key}'] names a page fragment that still exists", in_array($key, $realPages, true));
}

/* =========================================================================
 * PASS 2 — CAP SAFETY: the page-size constant, the bound LIMIT, and a real
 * truth table for sitemapPageCount().
 * ========================================================================= */

if (preg_match('/const\s+IHYMNS_SITEMAP_PAGE_SIZE\s*=\s*(\d+)\s*;/', $sitemapStripped, $m)) {
    $pageSize = (int)$m[1];
    sc('2.1 IHYMNS_SITEMAP_PAGE_SIZE is defined in sitemap.xml.php', true);
    sc('2.2 IHYMNS_SITEMAP_PAGE_SIZE <= 45000 (headroom under the protocol\'s 50,000 URL cap)', $pageSize > 0 && $pageSize <= 45000);
} else {
    sc('2.1 IHYMNS_SITEMAP_PAGE_SIZE is defined in sitemap.xml.php', false);
    sc('2.2 IHYMNS_SITEMAP_PAGE_SIZE <= 45000 (headroom under the protocol\'s 50,000 URL cap)', false);
}

/* The songs section's SELECT must bind its LIMIT — never interpolate it
   (rule #5). Comment-stripped so a doc-comment mentioning "LIMIT ?" as
   illustration can't false-positive; the negative check below guards the
   OTHER direction (an interpolated `LIMIT {$something}` naming the paginator
   would be the regression). */
sc('2.3 the songs section SQL contains a bound "LIMIT ?"', strpos($sitemapStripped, 'LIMIT ?') !== false);
sc('2.4 the LIMIT is never interpolated from a variable (no "LIMIT {$" / "LIMIT $")',
    !preg_match('/LIMIT\s*\{?\$/', $sitemapStripped));

require_once $helpersFile; // sitemapPageCount(), sitemapLastmod() — pure, safe to call directly (see doc-block)

$pageCountTable = [
    [0, 10000, 1],
    [1, 10000, 1],
    [9999, 10000, 1],
    [10000, 10000, 1],
    [10001, 10000, 2],
    [20000, 10000, 2],
    [20001, 10000, 3],
    [0, 0, 1],       // a non-positive page size can't paginate — one page holds it all
    [-5, 10000, 1],  // a stray negative total is floored to 0 -> still one page
];
foreach ($pageCountTable as [$total, $perPage, $expect]) {
    sc("2.5 sitemapPageCount({$total}, {$perPage}) === {$expect}", sitemapPageCount($total, $perPage) === $expect);
}

/* =========================================================================
 * PASS 3 — HOST NON-FORK: appCanonicalHost() is used; the four-channel host
 * allow-list is not re-typed as a second array literal (rule #22 — #526's
 * doc-block explicitly names the sitemap as sharing this one source).
 * ========================================================================= */

sc('3.1 sitemap.xml.php calls the shared appCanonicalHost()', strpos($sitemapStripped, 'appCanonicalHost(') !== false);
foreach (['ihymns.app', 'www.ihymns.app', 'dev.ihymns.app', 'beta.ihymns.app'] as $hostLiteral) {
    sc("3.2 sitemap.xml.php holds no quoted host literal '{$hostLiteral}' (would be a re-forked allow-list)",
        strpos($sitemapStripped, "'{$hostLiteral}'") === false);
}

/* =========================================================================
 * PASS 4 — .HTACCESS LOCKSTEP: the original + child rewrite rules both
 * exist, and every registered section key round-trips through the child
 * URL's `[a-z]+` capture group (rule #35 — two files that must agree).
 * ========================================================================= */

sc('4.1 .htaccess still has the original "^sitemap\\.xml$" rule', strpos($htaccessRaw, '^sitemap\\.xml$') !== false);
sc('4.2 .htaccess has the new child-sitemap rewrite rule', strpos($htaccessRaw, '^sitemap-([a-z]+)') !== false);

if (preg_match('/const\s+IHYMNS_SITEMAP_SECTIONS\s*=\s*\[(.*?)\n\];/s', $sitemapStripped, $m)) {
    /* Match only OUTER keys — each is shaped `'key' => [...]` (a nested
       array value). The one INNER key, `'paginated' => false/true`, has a
       scalar value (no `[` after `=>`) and is deliberately excluded so this
       list is exactly the real section names, not section-names-plus-one-
       spurious-'paginated'-per-entry. */
    preg_match_all("/'([a-z0-9-]+)'\s*=>\s*\[/", $m[1], $km);
    $sectionKeys = $km[1];
    sc('4.3 IHYMNS_SITEMAP_SECTIONS parsed at least one section key', count($sectionKeys) > 0);
    foreach ($sectionKeys as $key) {
        sc("4.4 section key '{$key}' is lowercase-letters-only (matches .htaccess's [a-z]+ capture group)",
            (bool)preg_match('/^[a-z]+$/', $key));
    }
} else {
    sc('4.3 IHYMNS_SITEMAP_SECTIONS parsed at least one section key', false);
}

/* =========================================================================
 * PASS 5 — LASTMOD HONESTY: a real truth table for sitemapLastmod(), the
 * retired "one shared $today for every entity" pattern stays gone, and the
 * home page's deliberate date('Y-m-d') stays the ONE exception.
 * ========================================================================= */

$lastmodTable = [
    // [dbDate, fallback, expect]
    ['2026-08-20 10:30:00', null, '2026-08-20'],
    ['2026-08-20', null, '2026-08-20'],
    [null, null, null],
    ['', null, null],
    [null, '2026-01-05T00:00:00Z', '2026-01-05'],
    ['', '', null],
    ['short', null, null],       // < 10 chars — not a usable date, falls through
];
foreach ($lastmodTable as [$db, $fallback, $expect]) {
    $got = sitemapLastmod($db, $fallback);
    $label = '5.1 sitemapLastmod(' . var_export($db, true) . ', ' . var_export($fallback, true) . ') === ' . var_export($expect, true);
    sc($label, $got === $expect);
}

/* The retired shape: a single shared $today variable fed into an entity
   loop's lastmod. Comment-stripped so this doc-block's own prose (which
   must describe the retired pattern to explain the check) can't trip it. */
sc('5.2 the retired "$today" per-entity lastmod variable does not reappear', strpos($sitemapStripped, '$today') === false);
/* The home page keeps EXACTLY one deliberate date('Y-m-d') — if a second
   entity loop grows its own "just say today" shortcut, this count moves to
   2 and the guard goes red naming exactly that regression. */
sc("5.3 date('Y-m-d') appears EXACTLY ONCE (the home page's one deliberate exception)",
    substr_count($sitemapStripped, "date('Y-m-d')") === 1);

/* =========================================================================
 * PASS 6 — CAPABILITY-URL BAN: never advertise a shared set-list's secret.
 * ========================================================================= */

sc('6.1 sitemap.xml.php never contains the string "/setlist/shared" (a shared set-list link is a secret capability URL)',
    strpos($sitemapRaw, '/setlist/shared') === false);

/* =========================================================================
 * PASS 7 — THE VISIBILITY GATE (search-engine visibility feature, #2024/
 * #2025): an admin-hidden channel's sitemap must 404, and it must do so
 * BEFORE the DB fingerprint work AND before conditional GET, so a crawler
 * holding a cached ETag can never get a 304 "still good" for a channel
 * we've just asked it not to list.
 * ========================================================================= */

sc('7.1 sitemap.xml.php calls the ONE shared searchEngineVisibleHere() (never a raw getAppSetting(\'search_visibility_channels\'…) re-read)',
    strpos($sitemapStripped, 'searchEngineVisibleHere(') !== false);

$posGate = strpos($sitemapStripped, 'searchEngineVisibleHere(');
$posDb   = strpos($sitemapStripped, 'getDbMysqli(');
$posIfNoneMatch = strpos($sitemapStripped, 'HTTP_IF_NONE_MATCH');

/* The anchor itself must not have silently multiplied or vanished — a
   second getDbMysqli( call elsewhere in the file would make the ordering
   check below meaningless without anyone noticing. */
sc('7.2a "getDbMysqli(" appears exactly once in sitemap.xml.php (the step-2 try block — the ordering anchor itself hasn\'t drifted)',
    substr_count($sitemapStripped, 'getDbMysqli(') === 1);
sc('7.2b the visibility gate runs BEFORE getDbMysqli( (no wasted DB fingerprint work on a hidden channel)',
    $posGate !== false && $posDb !== false && $posGate < $posDb);
sc('7.3 the visibility gate runs BEFORE HTTP_IF_NONE_MATCH (a hidden channel can never answer a cached-copy 304)',
    $posGate !== false && $posIfNoneMatch !== false && $posGate < $posIfNoneMatch);

/* ========================================================================= */

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
