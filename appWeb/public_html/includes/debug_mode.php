<?php

declare(strict_types=1);

/**
 * iHymns — On-Demand Debug Mode
 *
 * Lets a maintainer flip on full PHP error display from the browser
 * without redeploying, by visiting a page with BOTH `?_debug=1` AND
 * `?_dev=1` query parameters. One alone does nothing; mistyping does
 * nothing; bots crawling for `?debug=true` find nothing.
 *
 * GUARDS:
 *   1. CHANNEL-LOCKED — only honoured on Alpha/Beta deployments. Production
 *      ignores both params unconditionally; there is no override and no env
 *      knob. If you need to debug production, log into the server and read
 *      the PHP error log there.
 *
 *   2. TWO-PARAM COMBO — both `_debug=1` and `_dev=1` must be present
 *      simultaneously. Reduces accidental discovery by crawlers, link
 *      previewers, and copy-pasted URLs.
 *
 *   3. AUDIT-LOGGED — every fresh enable writes a line to the PHP error
 *      log with the IP and User-Agent, so misuse leaves a paper trail.
 *
 * BEHAVIOUR:
 *   - On enable: sets `display_errors=on`, `error_reporting=E_ALL`, sends
 *     an `X-Debug-Mode: on` response header, and writes a 30-minute
 *     HttpOnly/Secure/SameSite=Strict cookie so SPA navigations and
 *     same-tab AJAX inherit the flag without re-passing the URL params.
 *   - Registers a shutdown handler that, if a fatal error killed the page
 *     mid-render, prints the error as a fenced HTML block at the bottom
 *     of the partial output. This is what makes "blank page after the
 *     head" failure modes debuggable.
 *   - `?_debug=off` (alone, no `_dev` needed) clears the cookie everywhere
 *     including production, so a stale cookie can't get stuck.
 *
 * USAGE:
 *   This file MUST be required first, before any other code that could
 *   throw, in both `index.php` and `api.php`:
 *
 *     require_once __DIR__ . '/includes/debug_mode.php';
 *     enableDebugModeIfRequested();
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* infoAppVer.php populates $app['Application']['Version']['Development']['Status']
 * which the channel guard depends on. It's side-effect-free apart from its own
 * direct-access guard, so it's safe to load this early. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'infoAppVer.php';

const IHYMNS_DEBUG_COOKIE = '_ihymns_debug';
const IHYMNS_DEBUG_TTL    = 1800; /* 30 minutes */

/**
 * Entry point. Decides whether debug mode applies to this request,
 * configures PHP, sets headers + cookie, and registers the shutdown
 * handler. Idempotent.
 */
function enableDebugModeIfRequested(): void
{
    global $app;

    /* ?_debug=off clears the cookie on any channel, so a leaked or stale
       cookie from a previous deploy phase can always be cleared. */
    if (($_GET['_debug'] ?? null) === 'off') {
        _ihymnsDebugClearCookie();
        return;
    }

    $devStatus     = $app['Application']['Version']['Development']['Status'] ?? null;
    $isAlphaOrBeta = ($devStatus === 'Alpha' || $devStatus === 'Beta');

    if (!$isAlphaOrBeta) {
        /* If a cookie somehow leaked from a previous channel, drop it. */
        if (!empty($_COOKIE[IHYMNS_DEBUG_COOKIE])) {
            _ihymnsDebugClearCookie();
        }
        return;
    }

    $urlComboPresent = (
        ($_GET['_debug'] ?? null) === '1'
     && ($_GET['_dev']   ?? null) === '1'
    );
    $cookiePresent = !empty($_COOKIE[IHYMNS_DEBUG_COOKIE]);

    if (!$urlComboPresent && !$cookiePresent) {
        return;
    }

    @ini_set('display_errors',         '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);

    if (!headers_sent()) {
        header('X-Debug-Mode: on');
    }

    /* Slide the 30-min TTL forward on every debug request so an active
       session doesn't expire mid-investigation. Cheap; one Set-Cookie
       per request. */
    _ihymnsDebugSetCookie();

    /* Audit log only on fresh enables (URL combo without prior cookie) —
       cookie refreshes happen on every navigation and would flood the log. */
    if ($urlComboPresent && !$cookiePresent) {
        $ip  = $_SERVER['REMOTE_ADDR']      ?? '?';
        $ua  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '?'), 0, 200);
        $url = (string)($_SERVER['REQUEST_URI'] ?? '?');
        error_log(sprintf(
            '[ihymns-debug] enabled channel=%s ip=%s url=%s ua=%s',
            $devStatus, $ip, $url, $ua
        ));
    }

    register_shutdown_function('_ihymnsDebugShutdownHandler');
}

function _ihymnsDebugSetCookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(IHYMNS_DEBUG_COOKIE, '1', [
        'expires'  => time() + IHYMNS_DEBUG_TTL,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    /* Reflect immediately so the rest of this request also sees it. */
    $_COOKIE[IHYMNS_DEBUG_COOKIE] = '1';
}

function _ihymnsDebugClearCookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(IHYMNS_DEBUG_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    unset($_COOKIE[IHYMNS_DEBUG_COOKIE]);
}

/**
 * Surface fatals that killed the page mid-render. Without this, a fatal
 * after some output has already been written just truncates the response
 * silently — the exact failure mode that prompted this feature.
 */
function _ihymnsDebugShutdownHandler(): void
{
    $err = error_get_last();
    if ($err === null) {
        return;
    }

    $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING
               | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;
    if (($err['type'] & $fatalMask) === 0) {
        /* Non-fatals were already printed inline by display_errors=on. */
        return;
    }

    $type = match ($err['type']) {
        E_ERROR           => 'E_ERROR',
        E_PARSE           => 'E_PARSE',
        E_CORE_ERROR      => 'E_CORE_ERROR',
        E_CORE_WARNING    => 'E_CORE_WARNING',
        E_COMPILE_ERROR   => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR      => 'E_USER_ERROR',
        default           => (string)$err['type'],
    };

    $msg     = htmlspecialchars((string)($err['message'] ?? '(no message)'), ENT_QUOTES, 'UTF-8');
    $file    = htmlspecialchars((string)($err['file']    ?? '(unknown)'),    ENT_QUOTES, 'UTF-8');
    $line    = (int)($err['line'] ?? 0);
    $channel = htmlspecialchars(
        (string)($GLOBALS['app']['Application']['Version']['Development']['Status'] ?? '?'),
        ENT_QUOTES, 'UTF-8'
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo "\n<!-- ihymns-debug: fatal error block -->\n";
    echo '<div style="position:relative;z-index:99999;margin:2rem auto;max-width:960px;'
       . 'padding:1.25rem 1.5rem;border:2px solid #b00020;border-radius:.5rem;'
       . 'background:#fff5f5;color:#1a1a1a;font:13px/1.5 ui-monospace,Menlo,Consolas,monospace;'
       . 'box-shadow:0 4px 16px rgba(176,0,32,.2);">'
       . '<div style="font:600 14px/1.4 system-ui,sans-serif;color:#b00020;margin-bottom:.5rem;">'
       . 'PHP fatal — debug mode'
       . '</div>'
       . '<div><strong>' . $type . ':</strong> ' . $msg . '</div>'
       . '<div style="margin-top:.4rem;color:#555;">at ' . $file . ':' . $line . '</div>'
       . '<div style="margin-top:.6rem;font:11px/1.3 system-ui,sans-serif;color:#777;">'
       . 'Visible only because <code>?_debug=1&amp;_dev=1</code> was set on this channel ('
       . $channel . '). Visit <code>?_debug=off</code> to dismiss.'
       . '</div>'
       . '</div>';
}
