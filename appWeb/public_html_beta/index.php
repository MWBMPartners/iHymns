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
 * This file loads the Bootstrap framework, application CSS, and JavaScript
 * modules, then renders the main layout shell. All views (songbooks, songs,
 * search results, favourites) are rendered dynamically by JavaScript within
 * the #app-content container.
 *
 * ARCHITECTURE:
 * - PHP handles: version injection, copyright year, server-side includes
 * - JavaScript handles: SPA routing, data loading, search, UI rendering
 * - Bootstrap 5.3: responsive layout, components, dark mode
 *
 * @requires PHP 8.5+
 */

/* Load the application version/metadata configuration */
require_once __DIR__ . '/includes/infoAppVer.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <!-- ================================================================
         META TAGS
         ================================================================ -->

    <!-- Character encoding: UTF-8 supports all international characters in lyrics -->
    <meta charset="UTF-8">

    <!-- Viewport: ensures proper responsive behaviour on mobile devices -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Page title: shown in browser tab, bookmarks, and search results -->
    <title><?php echo htmlspecialchars($app["Application"]["Name"]); ?> — Christian Lyrics for Worship</title>

    <!-- Description: used by search engines and social media previews -->
    <meta name="description" content="<?php echo htmlspecialchars($app["Application"]["Description"]["Synopsis"]); ?>. Search thousands of hymns and worship songs from multiple songbooks.">

    <!-- Theme colour: warm amber matching iLyrics dB branding -->
    <meta name="theme-color" content="#d76600" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#3d2800" media="(prefers-color-scheme: dark)">

    <!-- Apple-specific meta tags for PWA/home screen behaviour -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($app["Application"]["Name"]); ?>">

    <!-- PWA Web App Manifest: defines app name, icons, theme, display mode -->
    <link rel="manifest" href="manifest.json">

    <!-- Favicon: shown in browser tabs and bookmarks -->
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">

    <!-- Apple touch icon: shown on iOS home screen when app is added -->
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">

    <!-- ================================================================
         STYLESHEETS
         ================================================================ -->

    <!-- Bootstrap 5.3 CSS: the responsive UI framework -->
    <!-- Loaded from CDN for performance; includes dark mode support via data-bs-theme -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <!-- Bootstrap Icons 1.11: icon font for UI elements (search, star, moon, etc.) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">

    <!-- Animate.css 4.1: CSS animation library for smooth transitions and effects -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"
          rel="stylesheet"
          integrity="sha384-Gu3KVV2H9d+yA4QDpVB7VcOyhJlAVrcXd0thEjr4KznfaFPLe0xQJyonVxONa4ZC"
          crossorigin="anonymous">

    <!-- iHymns custom stylesheet: app-specific styles, overrides, and theming -->
    <link href="css/styles.css" rel="stylesheet">

    <!-- Print stylesheet: optimised layout for printing song lyrics -->
    <link href="css/print.css" rel="stylesheet" media="print">
</head>

<body>
    <!-- Skip-to-content link for keyboard/screen reader users (WCAG 2.1 AA) -->
    <a href="#app-content" class="skip-to-content">Skip to main content</a>

    <!-- ================================================================
         NAVIGATION BAR
         Fixed at the top of the page. Contains:
         - App branding/logo
         - Search bar (always visible)
         - Navigation links (Songbooks, Favourites, Settings)
         - Dark mode toggle
         ================================================================ -->
    <nav class="navbar navbar-expand-lg sticky-top shadow-sm" id="main-navbar" role="banner">
        <div class="container-fluid">

            <!-- App brand/logo: clicking returns to the home/songbooks view -->
            <a class="navbar-brand d-flex align-items-center fw-bold" href="#" id="nav-brand"
               aria-label="iHymns home">
                <!-- Music note icon as the app logo -->
                <i class="bi bi-music-note-list me-2 fs-4" aria-hidden="true"></i>
                <!-- App name text -->
                <span><?php echo htmlspecialchars($app["Application"]["Name"]); ?></span>
            </a>

            <!-- Mobile hamburger toggle button: shown on small screens -->
            <button class="navbar-toggler border-0" type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#navbarContent"
                    aria-controls="navbarContent"
                    aria-expanded="false"
                    aria-label="Toggle navigation">
                <!-- Hamburger icon (three horizontal lines) -->
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Collapsible navbar content: expands on desktop, collapses on mobile -->
            <div class="collapse navbar-collapse" id="navbarContent">

                <!-- Search form: always visible in the navbar -->
                <form class="d-flex mx-lg-4 my-2 my-lg-0 flex-grow-1" role="search"
                      id="search-form" aria-label="Search songs">
                    <div class="input-group">
                        <!-- Search icon prepended to the input field -->
                        <span class="input-group-text bg-transparent border-end-0" id="search-icon">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <!-- Search input field: users type their query here -->
                        <input type="search"
                               class="form-control border-start-0"
                               id="search-input"
                               placeholder="Search songs, lyrics, songbooks..."
                               aria-label="Search songs, lyrics, songbooks"
                               aria-describedby="search-icon"
                               autocomplete="off">
                        <!-- Clear search button: shown when there is text in the input -->
                        <button class="btn btn-outline-secondary d-none" type="button"
                                id="search-clear" aria-label="Clear search">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                        <!-- Number search toggle: switches input to numeric keypad on mobile -->
                        <button class="btn btn-outline-secondary" type="button"
                                id="search-numpad-toggle"
                                aria-label="Toggle number search mode"
                                title="Search by song number (numpad)">
                            <i class="bi bi-123" aria-hidden="true"></i>
                        </button>
                    </div>
                </form>

                <!-- Navigation links: Songbooks, Favourites, Help -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                    <!-- Songbooks link: browse all songbooks -->
                    <li class="nav-item">
                        <a class="nav-link active" href="#" id="nav-songbooks"
                           aria-label="Browse songbooks" aria-current="page">
                            <i class="bi bi-book me-1" aria-hidden="true"></i>
                            <span>Songbooks</span>
                        </a>
                    </li>

                    <!-- Favourites link: view saved favourite songs -->
                    <li class="nav-item">
                        <a class="nav-link" href="#" id="nav-favorites"
                           aria-label="View favourites">
                            <i class="bi bi-star me-1" aria-hidden="true"></i>
                            <span>Favourites</span>
                        </a>
                    </li>

                    <!-- Help link: open in-app help documentation -->
                    <li class="nav-item">
                        <a class="nav-link" href="#" id="nav-help"
                           aria-label="Help and documentation">
                            <i class="bi bi-question-circle me-1" aria-hidden="true"></i>
                            <span>Help</span>
                        </a>
                    </li>
                </ul>

                <!-- Right-side controls: colourblind toggle + dark mode toggle -->
                <div class="d-flex align-items-center gap-1">
                    <!-- Colourblind-friendly mode toggle -->
                    <button class="btn btn-link nav-link px-2" type="button"
                            id="cb-toggle"
                            aria-label="Toggle colourblind-friendly mode"
                            aria-pressed="false"
                            title="Colourblind-friendly mode">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                    <!-- Dark mode toggle button -->
                    <button class="btn btn-link nav-link px-2" type="button"
                            id="theme-toggle"
                            aria-label="Toggle dark mode"
                            title="Toggle dark mode">
                        <!-- Sun icon (shown in dark mode, clicking switches to light) -->
                        <i class="bi bi-sun-fill d-none" id="theme-icon-light" aria-hidden="true"></i>
                        <!-- Moon icon (shown in light mode, clicking switches to dark) -->
                        <i class="bi bi-moon-fill" id="theme-icon-dark" aria-hidden="true"></i>
                    </button>
                </div>

            </div><!-- /.navbar-collapse -->
        </div><!-- /.container-fluid -->
    </nav>

    <!-- ================================================================
         PWA INSTALL BANNER
         Shown to users who haven't installed the app yet.
         On supported platforms, prompts PWA installation.
         When native apps are available, shows app store links instead.
         Hidden by default; shown/hidden by js/modules/settings.js
         ================================================================ -->
    <div class="alert alert-info alert-dismissible fade show m-3 d-none animate__animated animate__fadeInDown"
         id="install-banner" role="alert">
        <div class="d-flex align-items-center">
            <!-- Download/install icon -->
            <i class="bi bi-download fs-4 me-3" aria-hidden="true"></i>
            <div>
                <!-- Banner message text -->
                <strong>Install iHymns</strong> for quick access and offline use!
            </div>
            <!-- Install button: triggers the PWA install prompt -->
            <button class="btn btn-primary btn-sm ms-auto me-2" type="button"
                    id="install-btn">
                Install
            </button>
            <!-- Dismiss button: hides the banner -->
            <button type="button" class="btn-close" data-bs-dismiss="alert"
                    aria-label="Close install banner"></button>
        </div>
    </div>

    <!-- ================================================================
         MAIN CONTENT AREA
         This is the primary container where all views are rendered
         dynamically by JavaScript (SPA pattern). Views include:
         - Songbook grid (home)
         - Song list (within a songbook)
         - Song detail view (lyrics display)
         - Search results
         - Favourites list
         - Help content
         ================================================================ -->
    <main class="container-fluid py-3" id="app-content" role="main">

        <!-- Loading spinner: shown while song data is being fetched/parsed -->
        <div class="text-center py-5" id="loading-spinner">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading songs...</span>
            </div>
            <p class="mt-3 text-muted">Loading song library...</p>
        </div>

    </main>

    <!-- ================================================================
         FOOTER
         Contains copyright info, version, and useful links.
         ================================================================ -->
    <footer class="border-top py-3 mt-auto" id="app-footer" role="contentinfo">
        <div class="container-fluid">
            <div class="row align-items-center">
                <!-- Copyright and version info (left side) -->
                <div class="col-md-6 text-center text-md-start text-muted small">
                    <!-- Copyright with auto-computed year range -->
                    <span><?php echo htmlspecialchars($app["Application"]["Copyright"]["Full"]); ?>.</span>
                    <!-- Version number -->
                    <span class="ms-2">v<?php echo htmlspecialchars($app["Application"]["Version"]["Number"]); ?></span>
                </div>
                <!-- Links (right side) -->
                <div class="col-md-6 text-center text-md-end text-muted small mt-2 mt-md-0">
                    <a href="#" class="text-muted text-decoration-none me-3" id="footer-help">Help</a>
                    <a href="<?php echo htmlspecialchars($app["Application"]["Repo"]["URL"]); ?>"
                       class="text-muted text-decoration-none"
                       target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-github me-1" aria-hidden="true"></i>GitHub
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ================================================================
         JAVASCRIPT
         Loaded at the end of body for performance (non-blocking).
         Order matters: Bootstrap JS first, then libraries, then our modules.
         ================================================================ -->

    <!-- Bootstrap 5.3 JavaScript Bundle (includes Popper.js for dropdowns/tooltips) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>

    <!-- Fuse.js 7.x: lightweight fuzzy-search library for client-side song search -->
    <script src="https://cdn.jsdelivr.net/npm/fuse.js@7.0.0/dist/fuse.min.js"
            integrity="sha384-PCSoOZTpbkikBEtd/+uV3WNdc676i9KUf01KOA8CnJotvlx8rRrETbDuwdjqTYvt"
            crossorigin="anonymous"></script>

    <!-- PDF.js 4.9: Mozilla's PDF rendering library for sheet music viewer -->
    <!-- Loaded as an ES module; exposes window.pdfjsLib for use by sheet-music.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.9.155/pdf.min.mjs" type="module"
            integrity="sha384-Yw4pTrkymyfp/wv+Y5WT8asmcguuLMaa4rVm7i7SRthvUIAq83i8yVCk3nzzihT1"
            crossorigin="anonymous"></script>

    <!-- iHymns application JavaScript (ES module entry point) -->
    <!-- type="module" enables ES module imports and strict mode by default -->
    <script type="module" src="js/app.js"></script>
</body>
</html>
