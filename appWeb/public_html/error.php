<?php

declare(strict_types=1);

/**
 * iHymns — Standalone HTTP error page.
 *
 * Target of Apache ErrorDocument (403 / 405 / 500 / 503) — NOT directly
 * navigable as a plain URL: `.htaccess`'s "block direct access to .php
 * files" rule (~line 47) matches on `%{THE_REQUEST}`, the ORIGINAL request
 * line, so any request that literally contains `.php` — including
 * `/error.php?code=NNN` typed into a browser — is rewritten to a 404 before
 * this file ever runs. An Apache-internal ErrorDocument redirect does not
 * change the original request line, so THAT path reaches this file fine;
 * a human can only exercise the `?code=` branch by having Apache hand them
 * here already (or via a raw HTTP client that skips `THE_REQUEST` entirely,
 * which is what the whitelist below is defending against). Self-contained:
 * no DB and no app bootstrap, so it renders even when the app itself is what
 * failed.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'error_page.php';

/* Whitelist of statuses we render — so a forged ?code= can't set an arbitrary
   HTTP status on the response. Apache sets REDIRECT_STATUS on an ErrorDocument
   internal redirect; a direct visit may pass ?code=. Default 500.

   Derived from errorPageMap() (#1704, rule #35) rather than hand-typed, plus
   ONE explicit addition: 502. errorPageContent()'s `?? [...]` fallback still
   renders a coherent generic page for any unmapped status, so extending this
   whitelist doesn't need a matching map entry — it only needs to be a status
   worth letting the standalone page answer for.

   502 was carried over from the pre-#1704 list on the assumption that it's an
   Apache/reverse-proxy "Bad Gateway" condition PHP itself never raises.
   CORRECTED while building the #1704 coverage guard: api.php's admin-only
   snapshot-refresh action (setup-database.php's outbound-fetch step) DOES
   emit a genuine 502 via sendJson() when the upstream fetch fails — but it is
   JSON-only with no render surface, so it's tracked as a documented exemption
   in tests/php/test-error-page-coverage.php rather than promoted to a map
   entry. The Apache-level case is still real (a reverse proxy can answer 502
   before Apache/PHP ever sees the request), so 502 stays in this whitelist
   either way. */
$allowed = array_unique(array_merge(errorPageStatuses(), [502]));

$status = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);
if (!in_array($status, $allowed, true)) {
    $status = (int)($_GET['code'] ?? 0);
}
if (!in_array($status, $allowed, true)) {
    $status = 500;
}

$opts = ['actions' => [['label' => 'Go to home', 'href' => '/', 'primary' => true]]];
if ($status === 503 || $status === 429) {
    $opts['retryAfter'] = 30;
}

renderErrorPage($status, $opts);
