<?php
/**
 * iHymns — Footer Component
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Reusable page footer with copyright, version, and links.
 *
 * REQUIRES: $app array from infoAppVer.php.
 *
 * @requires PHP 8.5+
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Location: ' . dirname($_SERVER['REQUEST_URI'] ?? '', 3) . '/', true, 302);
    exit('<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=../../"></head><body>Redirecting to <a href="../../">iHymns</a>...</body></html>');
}
?>
    <footer class="border-top py-3 mt-auto" id="app-footer" role="contentinfo">
        <div class="container-fluid">
            <div class="row align-items-center" style="font-size: 0.8em;">
                <!-- Copyright and version info -->
                <div class="col-md-6 text-center text-md-start text-muted small">
                    <span><?php echo htmlspecialchars($app["Application"]["Copyright"]["Full"]); ?>.</span>
                    <span class="ms-2">v<?php echo htmlspecialchars($app["Application"]["Version"]["Number"]); ?></span>
                </div>
                <!-- Links -->
                <div class="col-md-6 text-center text-md-end text-muted small mt-2 mt-md-0">
                    <a href="#" class="text-muted text-decoration-none me-3" id="footer-help">Help</a>
                <!--<a href="<?php echo htmlspecialchars($app["Application"]["Repo"]["URL"]); ?>"
                       class="text-muted text-decoration-none"
                       target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-github me-1" aria-hidden="true"></i>GitHub
                    </a>-->
                </div>
            </div>
        </div>
    </footer>
