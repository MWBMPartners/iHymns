<?php

declare(strict_types=1);

/**
 * iHymns — Shared Favicon / App-Icon Head Tags
 *
 * Single source of truth for the favicon + apple-touch-icon links
 * used on admin pages (/manage/*). Mirrors the four lines in
 * public_html/index.php so both surfaces stay in lock-step if the
 * asset names change.
 *
 * USAGE (inside each admin page's <head>):
 *   <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
 *
 * The manifest deliberately isn't included here — admin pages aren't
 * part of the PWA and shouldn't advertise the installable bundle.
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}
?>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/icon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
