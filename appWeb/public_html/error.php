<?php

declare(strict_types=1);

/**
 * iHymns — Standalone HTTP error page.
 *
 * Target of Apache ErrorDocument (403 / 500 / 503) and directly navigable as
 * /error.php?code=NNN, so server-level and direct-file errors get the SAME
 * themed, theme-aware page as the rest of the app. Self-contained: no DB and
 * no app bootstrap, so it renders even when the app itself is what failed.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'error_page.php';

/* Whitelist of statuses we render — so a forged ?code= can't set an arbitrary
   HTTP status on the response. Apache sets REDIRECT_STATUS on an ErrorDocument
   internal redirect; a direct visit may pass ?code=. Default 500. */
$allowed = [400, 401, 403, 404, 429, 500, 502, 503];

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
