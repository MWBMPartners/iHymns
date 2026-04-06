<?php
/**
 * iHymns — PWA Install Banner Component
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Dismissible banner prompting users to install the PWA.
 * Hidden by default; shown by js/modules/settings.js when appropriate.
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
    <div class="alert alert-info alert-dismissible fade show m-3 d-none animate__animated animate__fadeInDown"
         id="install-banner" role="alert">
        <div class="d-flex align-items-center">
            <i class="bi bi-download fs-4 me-3" aria-hidden="true"></i>
            <div>
                <strong>Install iHymns</strong> for quick access and offline use!
            </div>
            <button class="btn btn-primary btn-sm ms-auto me-2" type="button"
                    id="install-btn">
                Install
            </button>
            <button type="button" class="btn-close" data-bs-dismiss="alert"
                    aria-label="Close install banner"></button>
        </div>
    </div>
