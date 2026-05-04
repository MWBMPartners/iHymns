<?php

declare(strict_types=1);

/**
 * iHymns — Shared <head> library loads for /manage/* (#527)
 *
 * Centralised <link> stylesheet loads for every admin page. Pulls the
 * Bootstrap version + URL + SRI from APP_CONFIG['libraries']['bootstrap']
 * so a version bump propagates to every admin page in one place. The
 * JS counterpart is loaded by admin-footer.php from the same config
 * entry, so CSS and JS versions can never drift again — the cause of
 * the setup-database.php 5.3.6 vs admin-footer.php 5.3.3 mismatch
 * the audit flagged.
 *
 * USAGE
 *   require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php';
 * Place inside <head> after <title> (so per-page CSS overrides can
 * still take effect by appending after this require).
 *
 * EXPECTED CALLER STATE
 *   - APP_CONFIG['libraries']['bootstrap'] defined (loaded by
 *     auth.php's bootstrap, available globally on every admin page).
 *
 * Bootstrap-Icons is currently pinned here (no APP_CONFIG entry yet).
 * If a future PR centralises icons into APP_CONFIG['libraries']
 * ['bootstrap_icons'], swap the inline literal for the config lookup
 * the same way bootstrap is treated below.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

$_bs = APP_CONFIG['libraries']['bootstrap'] ?? null;
if ($_bs && !empty($_bs['css_cdn'])):
    ?>
    <link rel="stylesheet"
          href="<?= htmlspecialchars((string)$_bs['css_cdn'], ENT_QUOTES) ?>"
          integrity="<?= htmlspecialchars((string)($_bs['css_sri'] ?? ''), ENT_QUOTES) ?>"
          crossorigin="anonymous">
    <?php
endif;
?>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
<?php
/* Shared app + admin CSS — paths relative to public_html root.
   `dirname(__DIR__, 2)` from manage/includes/head-libs.php = public_html/. */
$_publicRoot = dirname(__DIR__, 2);
?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime($_publicRoot . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime($_publicRoot . '/css/admin.css') ?>">
<?php
/* External-link provider auto-detect (#841) — single source of truth
   for URL → tblExternalLinkTypes.Slug mapping, exposed on
   window.iHymnsLinkDetect. Loaded on every admin page so each
   page's external-links row builder can call attachAutoDetect()
   without an extra <script> tag of its own. Eager (no `defer`)
   so the global is ready before any inline page script that
   constructs rows on initial page load. */
$_extLinkDetectPath = $_publicRoot . '/js/modules/external-link-detect.js';
$_extLinkDetectVer  = is_file($_extLinkDetectPath) ? (string)filemtime($_extLinkDetectPath) : '1';
?>
    <script src="/js/modules/external-link-detect.js?v=<?= htmlspecialchars($_extLinkDetectVer, ENT_QUOTES) ?>"></script>
