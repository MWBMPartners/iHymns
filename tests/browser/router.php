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
 * headers, no PHP-direct-access blocking, no `/manage` special-casing beyond
 * "let the file server handle it since those are real files") — real
 * deploys never run through this file; only local `php -S` testing does.
 *
 * Usage: php -S 127.0.0.1:PORT tests/browser/router.php
 * (the docroot is derived below, not passed via -t, so the router's own
 * relative path resolution stays correct regardless of cwd).
 *
 * @see appWeb/public_html/.htaccess
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

/* SPA catch-all — everything else that isn't a real file falls to
 * index.php, mirroring .htaccess's final `RewriteRule ^ index.php`. */
chdir($docroot);
require $docroot . '/index.php';
return true;
