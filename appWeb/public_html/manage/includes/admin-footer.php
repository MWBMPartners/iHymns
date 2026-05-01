<?php

declare(strict_types=1);

/**
 * iHymns — Shared Admin Footer
 *
 * Renders the same small copyright / version / Terms-Privacy strip
 * that lives at the bottom of the main app pages (index.php line
 * 911), adapted for the static layout of the /manage/ pages — no
 * fixed-position, no tab buttons, just the info line.
 *
 * USAGE:
 *   // Near the bottom of any /manage/*.php page (before </body>):
 *   require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php';
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'infoAppVer.php';

/* Mirror the $versionDisplay composition in public_html/index.php so
   the admin footer stays in lock-step with the main app footer. */
$_adminFooterVersion = $app['Application']['Version']['Number'] ?? '';
if (!empty($app['Application']['Version']['Development']['Status'])) {
    $_adminFooterVersion .= ' ' . $app['Application']['Version']['Development']['Status'];
}
$_adminFooterCommitDate = $app['Application']['Version']['Repo']['Commit']['Date'] ?? null;
if (($app['Application']['Version']['Development']['Status'] ?? null) === 'Alpha' && $_adminFooterCommitDate !== null) {
    $_adminFooterBuildStamp = preg_replace('/[^0-9]/', '', (string)$_adminFooterCommitDate);
    if (strlen($_adminFooterBuildStamp) >= 12) {
        $_adminFooterVersion .= ' · ' . substr($_adminFooterBuildStamp, 0, 14);
    }
}
?>
<?php
/* Close the admin-layout flex wrapper opened by admin-nav.php (#460).
   Guarded: login.php / setup.php / editor/index.php call this footer
   without going through admin-nav.php, so the flag prevents closing
   containers that were never opened. */
if (!empty($GLOBALS['_adminLayoutOpen'])):
?>
    </main>
</div> <!-- /.admin-layout -->
<?php endif; ?>
<footer class="footer-info admin-footer-static" role="contentinfo">
    <small>
        <?= $app['Application']['Copyright']['Full'] ?? '' ?>
        &nbsp;|&nbsp;
        v<?= htmlspecialchars($_adminFooterVersion) ?>
        &nbsp;|&nbsp;
        <a href="/terms" class="footer-link">Terms</a>
        &nbsp;|&nbsp;
        <a href="/privacy" class="footer-link">Privacy</a>
    </small>
</footer>

<!-- Bootstrap bundle — needed by the shared admin nav (hamburger
     offcanvas, brand dropdown, theme dropdown, user avatar dropdown)
     and any per-page modals. Loaded here so every /manage/* page
     picks it up regardless of whether the page template remembered.
     Sourced from APP_CONFIG['libraries']['bootstrap'] so the version
     stays in lock-step with head-libs.php (which loads the matching
     CSS). #527 closes the drift that previously had setup-database.php
     loading 5.3.6 CSS while this script loaded 5.3.3 JS. -->
<?php
    $_bsJs = APP_CONFIG['libraries']['bootstrap'] ?? null;
    if ($_bsJs && !empty($_bsJs['js_cdn'])):
?>
<script src="<?= htmlspecialchars((string)$_bsJs['js_cdn'], ENT_QUOTES) ?>"
        integrity="<?= htmlspecialchars((string)($_bsJs['js_sri'] ?? ''), ENT_QUOTES) ?>"
        crossorigin="anonymous"></script>
<?php endif; ?>

<?php
    /* Persistent bulk-import progress widget (#676). Boots on every
       /manage/* page so a curator who started an import on
       /manage/editor and navigated to /manage/songbooks (or any
       other admin page) still sees the live progress. The module
       is idempotent: if no job is tracked in localStorage, it
       does nothing. */
    $_bulkImportModulePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'bulk-import-progress.js';
    $_bulkImportVersion = is_file($_bulkImportModulePath) ? (string)filemtime($_bulkImportModulePath) : '1';
?>
<script type="module">
    import { bootBulkImportProgressWidget }
        from '/js/modules/bulk-import-progress.js?v=<?= htmlspecialchars($_bulkImportVersion, ENT_QUOTES) ?>';
    bootBulkImportProgressWidget();
</script>

<?php
    /* Reading-progress bar on every /manage/* page (#751). The public
       site wires this through its router on every navigation;
       /manage/* pages are full-page reloads, so we boot the module
       once on DOMContentLoaded. The module is shared with the public
       site (single source of truth) and the bar's mechanics are
       identical: position:fixed at the top of the viewport, fills as
       the user scrolls, hidden on short pages, defaults to
       --bs-primary on admin pages where there's no songbook context. */
    $_readingProgressPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'reading-progress.js';
    $_readingProgressVersion = is_file($_readingProgressPath) ? (string)filemtime($_readingProgressPath) : '1';
?>
<script type="module">
    import { ReadingProgress }
        from '/js/modules/reading-progress.js?v=<?= htmlspecialchars($_readingProgressVersion, ENT_QUOTES) ?>';
    /* Standalone instance — no parent app on /manage/* surfaces, so we
       pass null. The module only reads `this.app` for hooks the admin
       surface doesn't use. */
    const rp = new ReadingProgress(null);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => rp.initOnAnyPage());
    } else {
        rp.initOnAnyPage();
    }
</script>

