<?php
/**
 * iHymns — JavaScript Dependencies Component
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Reusable script block containing all third-party libraries and the
 * main application entry point. Loaded at end of <body> for performance.
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
    <!-- Bootstrap 5.3 JavaScript Bundle (includes Popper.js) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>

    <!-- Fuse.js 7.x: fuzzy-search library -->
    <script src="https://cdn.jsdelivr.net/npm/fuse.js@7.0.0/dist/fuse.min.js"></script>

    <!-- PDF.js 4.9: PDF rendering for sheet music viewer -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.9.155/pdf.min.mjs" type="module"></script>

    <!-- iHymns application entry point (ES module) -->
    <script type="module" src="js/app.js"></script>
