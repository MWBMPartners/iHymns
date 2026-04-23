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
