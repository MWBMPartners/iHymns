<?php

declare(strict_types=1);

/**
 * iHymns — cross-subdomain auth-cookie helpers (#1377 / #1376)
 *
 * ELI5
 * ----
 * When someone signs in, the browser needs to remember "yes, this is a
 * signed-in user" on every later request — that's what a cookie is for.
 * iHymns is really several subdomains (the public app, `/manage/`, alpha/
 * beta/dev copies) that all need to recognise the SAME sign-in, so the
 * cookie has to be set up carefully: shared across `.ihymns.app` but
 * never leaked to JavaScript, never sent over plain HTTP, and dropped
 * cleanly on sign-out. This file is the ONE place that builds that cookie
 * — set it here, clear it here, and every caller (the public API, the
 * admin login) gets the exact same shape, so a sign-in can never work on
 * one surface and silently fail on another.
 *
 * The `ihymns_auth` cookie carries the opaque API bearer token that signs a
 * user in across EVERY iHymns subdomain (`.ihymns.app`) — the public app
 * (index.php / api.php) AND the path-scoped /manage/ admin area both resolve
 * it. These three helpers are the SINGLE source of truth for how that cookie
 * is shaped, set, and cleared.
 *
 * WHY this is its own include (modularity rule — .claude/CLAUDE.md):
 *   Originally these lived inside api.php. /manage/ now also needs to MINT the
 *   cookie on admin login (so a /manage signed-in admin is recognised on the
 *   public app — e.g. the maintenance bypass in includes/maintenance.php),
 *   but a /manage page must NOT include api.php wholesale. Extracting the
 *   cookie helpers here lets BOTH api.php and manage/includes/auth.php
 *   `require_once` the same logic instead of forking a second copy that could
 *   silently drift (different SameSite, different domain scope = a sign-in that
 *   works on one surface and not the other).
 *
 * The token STORAGE shape (sha256 hash in tblApiTokens.Token) and the token
 * GENERATION (bin2hex(random_bytes(32)) → 64-char hex) are intentionally NOT
 * in here — they live with each caller's INSERT — so this file is purely about
 * the browser-facing cookie attributes and has no DB dependency.
 *
 * Requires: nothing (reads $_SERVER superglobals, calls setcookie()).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* function_exists() guards: this file is loaded via require_once (idempotent),
   but the guards are belt-and-braces against a partial-deploy window where an
   older api.php still declares these inline — first definition wins, the
   bytes are identical, so no behaviour can diverge. */

if (!function_exists('_authCookieOpts')) {
    /**
     * Standard cookie options for the `ihymns_auth` cookie (#390).
     *
     * The `Domain` attribute is only set when the current host is under
     * `ihymns.app`, so in local/dev environments (e.g. `localhost`) the
     * cookie stays host-only. `Secure` is set whenever the request arrived
     * over HTTPS (including via a reverse proxy that set X-Forwarded-Proto).
     *
     * SameSite=Lax (NOT Strict) is deliberate: the cookie must accompany a
     * top-level cross-subdomain navigation (e.g. clicking from /manage to the
     * public app) so the user stays recognised — Strict would drop it on that
     * first cross-site GET. HttpOnly keeps JS from reading the bearer token.
     *
     * ELI5: works out the right set of cookie attributes for THIS request —
     * "should it be marked Secure?" (only if we're on HTTPS), "should it
     * span all of ihymns.app?" (only on a real ihymns.app host, never on
     * localhost) — so both setAuthTokenCookie() and clearAuthTokenCookie()
     * below always agree on exactly what the cookie looks like.
     *
     * @param int $expiresAtTimestamp Unix epoch seconds for the cookie expiry.
     * @return array<string,mixed> setcookie() options array.
     */
    function _authCookieOpts(int $expiresAtTimestamp): array
    {
        $host  = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
        $https = !empty($_SERVER['HTTPS'])
              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        $opts = [
            'expires'  => $expiresAtTimestamp,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ];

        /* Scope the cookie to the parent domain so every iHymns subdomain
           (alpha/beta/dev/production/sync) sees the same sign-in. */
        if (preg_match('/(?:^|\.)ihymns\.app$/i', $host)) {
            $opts['domain'] = '.ihymns.app';
        }

        return $opts;
    }
}

if (!function_exists('setAuthTokenCookie')) {
    /**
     * Set the `ihymns_auth` cookie used for cross-subdomain sign-in and
     * ITP-resilient persistence (#390). Safe to call before any output;
     * a no-op once headers are sent (so a late call can't warning-spam).
     *
     * @param string $token              The RAW bearer token (64-char hex) —
     *                                   NOT the sha256 hash stored in the DB.
     * @param int    $expiresAtTimestamp Unix epoch seconds for the expiry.
     */
    function setAuthTokenCookie(string $token, int $expiresAtTimestamp): void
    {
        if (headers_sent()) return;
        setcookie('ihymns_auth', $token, _authCookieOpts($expiresAtTimestamp));
    }
}

if (!function_exists('clearAuthTokenCookie')) {
    /**
     * Clear the `ihymns_auth` cookie (logout + 401 paths). Sets an already-
     * expired cookie with the SAME path/domain/secure attributes as
     * setAuthTokenCookie() so the browser actually drops it (a mismatched
     * attribute set leaves a stale cookie behind).
     */
    function clearAuthTokenCookie(): void
    {
        if (headers_sent()) return;
        setcookie('ihymns_auth', '', _authCookieOpts(time() - 3600));
    }
}
