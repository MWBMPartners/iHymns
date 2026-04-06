<?php

declare(strict_types=1);

/**
 * iHymns — Main Entry Point (index.php)
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * The main single-page application entry point for the iHymns web PWA.
 * Assembles the page from modular PHP components (head, navbar, footer, etc.)
 * and renders the SPA shell. All views are rendered dynamically by JavaScript.
 *
 * COMPONENTS:
 * - includes/infoAppVer.php           — Application metadata & version
 * - includes/components/head.php      — <head> meta tags & stylesheets
 * - includes/components/navbar.php    — Navigation bar with search
 * - includes/components/install-banner.php — PWA install prompt
 * - includes/components/footer.php    — Page footer with copyright
 * - includes/components/scripts.php   — JavaScript dependencies
 *
 * @requires PHP 8.5+
 */

/* Load the application version/metadata configuration */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'infoAppVer.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'head.php'; ?>
</head>

<body>
    <!-- Skip-to-content link for keyboard/screen reader users (WCAG 2.1 AA) -->
    <a href="#app-content" class="skip-to-content">Skip to main content</a>

    <!-- Navigation bar -->
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'navbar.php'; ?>

    <!-- PWA install banner -->
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'install-banner.php'; ?>

    <!-- Main content area: views rendered dynamically by JavaScript (SPA) -->
    <main class="container-fluid py-3" id="app-content" role="main">
        <!-- Loading spinner: shown while song data is being fetched -->
        <div class="text-center py-5" id="loading-spinner">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading songs...</span>
            </div>
            <p class="mt-3 text-muted">Loading song library...</p>
        </div>
    </main>

    <!-- Footer -->
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'footer.php'; ?>

    <!-- JavaScript dependencies -->
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'scripts.php'; ?>
</body>
</html>
