<?php

declare(strict_types=1);

/**
 * iHymns — SPA clean-URL route registry (#1905)
 * ============================================================================
 *
 * ELI5: this is the one place that answers "is /<something> a real page of the
 * app?" — so the front controller can return a proper 404 for a made-up path
 * (like a /wp-admin/ scanner probe) instead of pretending it exists with a 200.
 * Crucially the list is DERIVED, not hand-typed: add a normal new page and it is
 * recognised automatically, with NOTHING to edit here or in index.php.
 *
 * WHY THIS EXISTS (#1905 — the soft-404)
 * --------------------------------------
 * The .htaccess SPA catch-all rewrites every non-file path to index.php, which
 * renders the app shell with HTTP 200 regardless of whether the path is a real
 * route. So /wp-admin/, /wp-includes/…, and any garbage path returned 200 —
 * telling a scanner "valid", logging as request.success, and looking to a search
 * engine like a real page (a soft-404). index.php now asks spaIsKnownRoute()
 * and sends a real 404 for an unknown first segment (the SPA still renders the
 * themed "not found" card — only the STATUS becomes honest).
 *
 * WHY DERIVED, NOT A HARDCODED LIST (the whole point)
 * ---------------------------------------------------
 * A hardcoded allow-list in index.php would be a THIRD copy of the route table
 * (after js/modules/router.js and api.php's page-switch). The day someone adds a
 * route to the client router but forgets index.php, that new route silently
 * 404s to crawlers while working in-app — the dangerous drift direction, and the
 * exact rule #34/#35 trap. Instead the valid first-segment set is composed from:
 *
 *   1. FILESYSTEM — the basename of every includes/pages/*.php fragment. A NEW
 *      normal page (a new fragment file, which you add anyway alongside its
 *      router.js + api.php cases) becomes a valid route here automatically.
 *   2. IHYMNS_ID_SCHEMES — the six external-identifier schemes (iswc/ipi/isni/
 *      ccli/bowi/isrc), read from their own registry so a new scheme is picked
 *      up too (includes/identifier_normalize.php).
 *   3. A small IHYMNS_SPA_ROUTE_ALIASES list — the router-only back-compat
 *      spellings that have NO page fragment, plus the admin-portal prefixes.
 *
 * CI (tests/php/test-route-allowlist-coverage.php) derives router.js's parseRoute
 * segment switch and FAILS the build if any client route is not covered here — so
 * the alias list can never silently rot into 404-ing a real route, and a new
 * page that forgot its fragment file is caught. Completeness is safety-critical:
 * the set must COVER router.js (a superset is safe — it only over-permits a dead
 * URL to 200; a subset would 404 a live route).
 *
 * @see appWeb/public_html/js/modules/router.js  parseRoute() — the client route table
 * @see appWeb/public_html/index.php              the consumer (the #1905 404 gate)
 * @see tests/php/test-route-allowlist-coverage.php  the drift guard
 * @link https://www.php.net/manual/en/function.glob.php
 */

/* IHYMNS_ID_SCHEMES — the identifier route segments (iswc/ipi/isni/ccli/bowi/isrc).
   Guarded so a caller that already loaded it doesn't re-require. */
if (!defined('IHYMNS_ID_SCHEMES')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
}

/**
 * Router-only route SEGMENTS with NO includes/pages/*.php fragment of their own.
 *
 * These are the fall-through spelling aliases js/modules/router.js parseRoute()
 * accepts (e.g. /favourites → favorites, /people → musician), the one client-only
 * route (login — handled entirely in-app, no server fragment), and the admin
 * portal prefixes (.htaccess 301s /admin and passes /manage through BEFORE
 * index.php ever runs, but they are listed so an .htaccess change can't make them
 * 404 either). A NEW normal page needs NOTHING here — it is auto-derived from its
 * fragment file. This short, stable list is CI-guarded against router.js.
 *
 * @var list<string>
 */
const IHYMNS_SPA_ROUTE_ALIASES = [
    /* parseRoute() fall-through spelling aliases (canonical form has the page file) */
    'favourites',   // → favorites
    'setlists',     // → setlist
    'statistics',   // → stats
    'works',        // → work
    'people',       // → musician (legacy)
    'person',       // → musician (legacy)
    'writer',       // → musician (legacy; index.php 301s a resolvable writer)
    'request',      // → request-a-song (canonical fragment is request-a-song.php)
    'tags',         // → themes (#1148 forgiving alias; api.php folds page=tags→themes)
    /* client-only route — no server fragment */
    'login',
    /* admin portal — .htaccess handles these before index.php; listed for drift-resistance */
    'admin',
    'manage',
];

/**
 * includes/pages/*.php basenames that are NOT valid top-level FIRST segments, so
 * they are excluded from the derived set:
 *   - home           the home page is the EMPTY path '/', never '/home'
 *                    (router.js parseRoute has no 'home' case → /home is not-found)
 *   - not-found      the internal themed 404 fragment (never a URL a user routes to)
 *   - identifier     the shared handler for /iswc /ipi … (the SCHEMES are the segments)
 *   - setlist-shared reached via the 'setlist' first segment (/setlist/shared/<token>)
 *
 * @var list<string>
 */
const IHYMNS_SPA_INTERNAL_PAGES = ['home', 'not-found', 'identifier', 'setlist-shared'];

/**
 * The authoritative allow-list of valid clean-URL FIRST path segments (#1905).
 *
 * Derived (see the file header) so a new page never needs an edit. Statically
 * cached for the request — one directory glob, once.
 *
 * @return list<string> lowercase first-segment names, e.g. song, songbook, search, iswc, login.
 */
function spaKnownRouteSegments(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $segments = [];

    /* 1. Every page fragment file is a route segment — a NEW page auto-appears. */
    foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
        $seg = basename($file, '.php');
        if (!in_array($seg, IHYMNS_SPA_INTERNAL_PAGES, true)) {
            $segments[] = $seg;
        }
    }

    /* 2. The external-identifier schemes (iswc/ipi/isni/ccli/bowi/isrc). */
    if (defined('IHYMNS_ID_SCHEMES')) {
        foreach (array_keys(IHYMNS_ID_SCHEMES) as $scheme) {
            $segments[] = (string) $scheme;
        }
    }

    /* 3. Router-only aliases + admin prefixes (no page fragment). */
    foreach (IHYMNS_SPA_ROUTE_ALIASES as $alias) {
        $segments[] = $alias;
    }

    $cache = array_values(array_unique($segments));
    return $cache;
}

/**
 * Is $path — a getRequestPath()-normalised clean URL (query stripped, trailing
 * slash trimmed) — a KNOWN top-level route?
 *
 * The empty path / '/' is the home page (always known). Otherwise the FIRST path
 * segment must be in spaKnownRouteSegments(). CASE-SENSITIVE by design, mirroring
 * router.js's literal lowercase switch: /WP-ADMIN and /Song already resolve to
 * the client 'not-found' page, so the server agrees (returns 404) rather than
 * soft-200-ing a mixed-case variant. Real navigations always use a lowercase
 * first segment — any id/abbrev/slug is the SECOND segment (e.g. /song/MP-0001).
 *
 * @param string $path getRequestPath() output.
 * @return bool true when the path is a real route (serve 200); false → caller 404s.
 */
function spaIsKnownRoute(string $path): bool
{
    if ($path === '' || $path === '/') {
        return true;                                   // home
    }
    $first = explode('/', ltrim($path, '/'))[0] ?? '';
    if ($first === '') {
        return true;                                   // '//' and the like → home-ish, never a probe
    }
    return in_array($first, spaKnownRouteSegments(), true);
}
