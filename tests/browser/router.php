<?php

declare(strict_types=1);

/**
 * tests/browser/router.php — Minimal router for PHP's built-in server (`php -S`).
 *
 * ELI5: `php -S` (used to serve the app for the browser smoke test) doesn't
 * understand Apache's `.htaccess`, so clean URLs like `/api` or `/song/CP-1`
 * would 404 or fall through to the wrong file without this. This tiny script
 * teaches the built-in server just enough of `.htaccess`'s routing to boot
 * the SPA the same way production does.
 *
 * DETAILED: `appWeb/public_html/.htaccess` rewrites a handful of clean URLs
 * to their PHP files (`/api` -> `api.php`, `/og-image` -> `og-image.php`, …)
 * and sends every other non-file request to `index.php` (the SPA catch-all,
 * client-side routed via the History API). PHP's built-in server has no
 * rewrite engine at all — passed a docroot with no router script, it serves
 * `index.php` for `/` (its own directory-index behaviour) but a request to
 * `/api?action=songs_index` finds no file literally named `api` and falls
 * through to `index.php` too, so the client receives the SPA's HTML shell
 * where it expected JSON. That silent shape-mismatch is exactly the kind of
 * console noise (`SyntaxError: Unexpected token '<'`) this smoke test exists
 * to catch — so the harness must not manufacture it itself by routing
 * `/api` wrong.
 *
 * This mirrors ONLY the subset of `.htaccess` the home-route smoke actually
 * exercises. It is not a full re-implementation (no gzip/cache/security
 * headers, no PHP-direct-access blocking) — real deploys never run through
 * this file; only local `php -S` testing does.
 *
 * #1855 (manage/.htaccess) — the one piece of `/manage` special-casing
 * this file DOES carry: manage/.htaccess hides `.php` from every
 * `/manage/**` URL with a THE_REQUEST-based 301, and a browser replays a
 * 301'd POST/PUT/PATCH/DELETE as a body-less GET (only 307/308 preserve
 * method+body) — so a write aimed at a literal `/manage/foo.php` URL
 * silently loses its whole body. `php -S`'s default behaviour (serve any
 * real file directly, ignore method entirely) would mask that class of bug
 * rather than reproduce it, so a browser-smoke run against the raw
 * built-in server could pass locally against a redirect production would
 * apply and lose the request body. The two blocks below teach this dev
 * router manage/.htaccess's actual method-scoped semantics — see their own
 * doc comments for the mechanism.
 *
 * Usage: php -S 127.0.0.1:PORT tests/browser/router.php
 * (the docroot is derived below, not passed via -t, so the router's own
 * relative path resolution stays correct regardless of cwd).
 *
 * @see appWeb/public_html/.htaccess
 * @see appWeb/public_html/manage/.htaccess
 * @link https://www.php.net/manual/en/features.commandline.webserver.php
 */

$docroot = realpath(__DIR__ . '/../../appWeb/public_html');
if ($docroot === false) {
    http_response_code(500);
    echo 'router.php: could not resolve appWeb/public_html';
    return true;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($uri) || $uri === '') {
    $uri = '/';
}

/* ==========================================================================
 * #1855 — /manage/**.php .php-hiding redirect, GET/HEAD ONLY.
 * Mirrors manage/.htaccess:69-72:
 *   RewriteCond %{THE_REQUEST} \.php[\s?/] [NC]
 *   RewriteCond %{REQUEST_URI} !^/manage/includes/ [NC]
 *   RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
 *   RewriteRule ^(.+)\.php$ /manage/$1 [R=301,L]
 *
 * ELI5: production hides ".php" from every /manage/ URL by redirecting a
 * literal .php request to its clean form — but ONLY for GET/HEAD, because a
 * browser replays a 301'd write as a body-less GET, silently dropping the
 * whole request body. This block reproduces exactly that, and MUST run
 * BEFORE the static-file passthrough below: on live Apache, mod_rewrite
 * sees THE_REQUEST before the file is served statically, so the redirect
 * fires even though the .php file genuinely exists on disk — the passthrough
 * would otherwise short-circuit this block on every real .php file (which
 * is all of them) and the redirect would never be reachable here at all.
 *
 * A non-GET/HEAD request intentionally does NOTHING in this block — it
 * falls through unredirected to the static-file passthrough below, which
 * serves the literal .php file directly with its original method and body
 * intact, exactly as production does once manage/.htaccess's method-scoped
 * RewriteCond keeps the redirect rule from firing for a write.
 */
if (str_starts_with($uri, '/manage/')
    && !str_starts_with($uri, '/manage/includes/')
    && str_ends_with($uri, '.php')
) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' || $method === 'HEAD') {
        $extensionless = substr($uri, 0, -strlen('.php'));
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . $extensionless . ($qs !== '' ? '?' . $qs : ''), true, 301);
        return true;
    }
    /* Not GET/HEAD — deliberately no return here; fall through. */
}

/* ==========================================================================
 * /manage/<name> -> /manage/<name>.php clean-URL resolution, ALL methods.
 * Mirrors manage/.htaccess:43-46:
 *   RewriteCond %{REQUEST_FILENAME} !-f
 *   RewriteCond %{REQUEST_FILENAME} !-d
 *   RewriteCond %{REQUEST_FILENAME}.php -f
 *   RewriteRule ^(.+?)/?$ $1.php [QSA,L]
 *
 * This is an INTERNAL rewrite (no redirect, no [R=…] flag on the mirrored
 * rule), so unlike the block above it applies to every HTTP method and
 * preserves the request body untouched — it is what makes an already-
 * extensionless request (every v2 editor write after #1855, and v1's
 * long-standing /manage/editor/api convention) actually reach real PHP
 * here, instead of falling through to the SPA catch-all further down and
 * getting index.php's HTML shell back where an admin page or a JSON API
 * response was expected — the exact silent shape-mismatch this whole file
 * exists to prevent (see the file-level doc-block). Placed after the block
 * above but the relative order does not matter: the two target disjoint
 * URI shapes (this one only ever fires for a URI that does NOT already end
 * in `.php`).
 */
if (str_starts_with($uri, '/manage/')) {
    $already = realpath($docroot . $uri);
    if ($already === false || !(is_file($already) || is_dir($already))) {
        $phpCandidate = realpath($docroot . rtrim($uri, '/') . '.php');
        if ($phpCandidate !== false
            && str_starts_with($phpCandidate, $docroot . DIRECTORY_SEPARATOR)
            && is_file($phpCandidate)
        ) {
            chdir(dirname($phpCandidate));
            require $phpCandidate;
            return true;
        }
    }
}

/* ==========================================================================
 * DIRECTORY-LISTING KILL (#1906) — mirrors .htaccess's / manage/.htaccess's
 *   RewriteCond %{REQUEST_FILENAME} -d
 *   RewriteCond %{REQUEST_FILENAME}/index.php !-f
 *   RewriteRule ^.+ - [R=404,L]
 * ELI5: a directory with no index.php of its own (e.g. /manage/includes/,
 * /css/) has no legitimate response. Production kills it via mod_rewrite
 * BEFORE the -d passthrough would hand it to mod_autoindex; this dev router
 * mirrors that by running the same is_dir()+!is_file(index.php) check
 * BEFORE the static-asset passthrough below, which would otherwise hand a
 * bare directory straight to the built-in server (PHP's `php -S` serves an
 * index.php/index.html it finds inside a directory, or a raw listing if it
 * finds neither — masking exactly the exposure this rule closes).
 * `/` itself is excluded (mirrors `^.+`, not `^`, in the .htaccess rule) so
 * it always falls through to the SPA catch-all below.
 * @see appWeb/public_html/.htaccess (DIRECTORY-LISTING KILL block)
 * @see appWeb/public_html/manage/.htaccess (DIRECTORY-LISTING KILL block)
 * ========================================================================== */
if ($uri !== '/') {
    $dirCandidate = realpath($docroot . $uri);
    if ($dirCandidate !== false
        && str_starts_with($dirCandidate, $docroot . DIRECTORY_SEPARATOR)
        && is_dir($dirCandidate)
        && !is_file($dirCandidate . '/index.php')
    ) {
        http_response_code(404);
        return true;
    }
}

/* Static-asset / real-file passthrough — mirrors .htaccess's
 *   RewriteCond %{REQUEST_FILENAME} -f [OR]
 *   RewriteCond %{REQUEST_FILENAME} -d
 *   RewriteRule ^ - [L]
 * `/` itself is excluded so it always falls through to the SPA catch-all
 * below rather than matching "the docroot is a directory". */
if ($uri !== '/') {
    $candidate = realpath($docroot . $uri);
    if ($candidate !== false
        && str_starts_with($candidate, $docroot . DIRECTORY_SEPARATOR)
        && (is_file($candidate) || is_dir($candidate))
    ) {
        return false; /* let the built-in server serve it directly */
    }
}

/* Clean-URL -> PHP-file rewrites, same mapping as .htaccess. Only the
 * handful the home-page smoke can plausibly reach are listed — extend this
 * list (and the equivalent .htaccess rule) together if a later smoke test
 * needs another one. */
$rewrites = [
    '/api'               => '/api.php',
    '/og-image'          => '/og-image.php',
    '/service-worker.js' => '/service-worker.js.php',
    '/sitemap.xml'       => '/sitemap.xml.php',
    '/robots.txt'        => '/robots.txt.php', // #2024/#2025 — robots.txt is now dynamic; .htaccess must agree (rule #35)
];
if (isset($rewrites[$uri])) {
    chdir($docroot);
    require $docroot . $rewrites[$uri];
    return true;
}

/* Static-asset-directory 404 (#1832 / rule #1566 class, issue #9 of this
 * pass) — mirrors .htaccess's
 *   RewriteRule ^(vendor|css|js|fonts)/ - [R=404,L]
 * ELI5: a CSS/JS fallback that points at a /vendor/... file which was never
 * actually deployed here must 404, not silently get index.php's HTML back
 * with a 200 — a stylesheet/script tag doesn't care that the body isn't
 * CSS/JS, it just fails to apply with no console error (rule #1566's
 * silent-failure shape). This block already ran the real-file/-dir
 * passthrough above, so anything that genuinely exists under these prefixes
 * already returned false (served directly) before reaching here; only a
 * MISSING asset under one of these prefixes falls through to this check.
 * DETAILED: matches by path PREFIX (not extension) so it can never
 * intercept an SPA client route — no route (home/song/songbook/search/
 * settings/favorites/...) starts with vendor/, css/, js/, or fonts/, same
 * as the .htaccess rule it mirrors. Keep both in lockstep (rule #35 —
 * cross-file agreement needs a mechanism, not a comment): this dev-server
 * router only fires for local `php -S` test runs, so it must reproduce the
 * production 404 or the browser smoke test would pass locally against a
 * quietly-broken production behaviour.
 * TODO(smoke test): a future browser smoke assertion belongs here too —
 * fetch /vendor/nonexistent.css against this router and assert a 404, so a
 * regression in either this file or the .htaccess rule it mirrors is
 * caught automatically instead of relying on manual review. */
if (preg_match('#^/(vendor|css|js|fonts)/#', $uri) === 1) {
    http_response_code(404);
    return true;
}

/* Bad-bot / scanner probe deny-list (#1905) — mirrors .htaccess's
 *   RewriteRule ^(wp-|wordpress|xmlrpc|phpmyadmin|adminer|dbadmin|mysqladmin|administrator|autodiscover|autoconfig|cgi-bin) - [R=404,L,NC]
 * Extensionless directory-style probes that no earlier rule catches must 404
 * here too, not fall to the SPA catch-all and get the shell. Keep the family
 * alternation BYTE-IDENTICAL to the .htaccess one (rule #35; the CI guard
 * tests/php/test-route-allowlist-coverage.php asserts the two match). `#i`
 * mirrors [NC]. */
if (preg_match('#^/(wp-|wordpress|xmlrpc|phpmyadmin|adminer|dbadmin|mysqladmin|administrator|autodiscover|autoconfig|cgi-bin)#i', $uri) === 1) {
    http_response_code(404);
    return true;
}

/* SPA catch-all — everything else that isn't a real file falls to
 * index.php, mirroring .htaccess's final `RewriteRule ^ index.php`.
 * NOTE (#1905): index.php now owns the UNKNOWN-ROUTE status — its derived
 * allow-list (includes/spa_routes.php) calls http_response_code(404) for an
 * unknown first segment before any output, so this dev router inherits the
 * hard-404 for unknown routes automatically, exactly as production does. */
chdir($docroot);
require $docroot . '/index.php';
return true;
