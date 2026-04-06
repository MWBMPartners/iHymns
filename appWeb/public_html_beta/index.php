<?php

declare(strict_types=1);

/**
 * iHymns — Main Application Entry Point
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * This is the single-page application (SPA) shell for iHymns.
 * It renders the complete HTML document including:
 *   - Dynamic Open Graph meta tags for social sharing previews
 *   - Analytics integration with DNT (Do Not Track) support
 *   - Fixed top header bar with branding and search
 *   - PWA install banner (dismissible)
 *   - First-launch disclaimer/terms acceptance modal
 *   - Main content area (loaded dynamically via AJAX)
 *   - Fixed bottom footer with navigation and copyright
 *   - Script tags with CDN + local fallback loading
 *
 * CLEAN URLs:
 * All URLs are served without file extensions. The .htaccess
 * rewrites all paths to this file. The JavaScript router uses
 * the History API (pushState) for client-side navigation.
 *
 * SOCIAL SHARE PREVIEWS:
 * When a shared link is fetched by a social media crawler (Facebook,
 * Twitter, Slack, etc.), this file detects the URL path and renders
 * appropriate Open Graph / Twitter Card meta tags for rich previews.
 *
 * INTERNATIONALISATION:
 * Currently English only. The HTML lang attribute, text direction,
 * and UI strings are prepared for future multi-language support.
 */

/* =========================================================================
 * BOOTSTRAP — Load configuration and application metadata
 * ========================================================================= */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/infoAppVer.php';
require_once __DIR__ . '/includes/SongData.php';

/* =========================================================================
 * APPLICATION METADATA SHORTCUTS
 * ========================================================================= */

$appName      = $app["Application"]["Name"];
$appVersion   = $app["Application"]["Version"]["Number"];
$appDevStatus = $app["Application"]["Version"]["Development"]["Status"];
$appCopyright = $app["Application"]["Copyright"]["Full"];
$appDesc      = $app["Application"]["Description"]["Synopsis"];
$appKeywords  = $app["Application"]["Description"]["Keywords"];
$appUrl       = $app["Application"]["Website"]["URL"];
$vendorName   = $app["Application"]["Vendor"]["Name"];

/** Build a display version string (e.g., "0.1.5 Beta") */
$versionDisplay = $appVersion;
if ($appDevStatus !== null) {
    $versionDisplay .= ' ' . $appDevStatus;
}

/** Library configuration shorthand */
$libs = APP_CONFIG['libraries'];

/** Internationalisation settings */
$locale = APP_CONFIG['i18n']['default_locale'];
$textDir = APP_CONFIG['i18n']['text_direction'];

/* =========================================================================
 * CONTENT SECURITY POLICY (CSP) — #117
 *
 * Generate a per-request nonce for inline scripts and send a strict CSP
 * header. This is the primary defence against XSS exploitation.
 * ========================================================================= */

$cspNonce = base64_encode(random_bytes(16));

$cspDirectives = [
    "default-src 'self'",
    "script-src 'self' 'nonce-{$cspNonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.googletagmanager.com https://plausible.io https://www.clarity.ms",
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
    "img-src 'self' data: https:",
    "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
    "connect-src 'self' https://www.google-analytics.com https://plausible.io https://www.clarity.ms",
    "frame-src 'self' https://sync.ihymns.app https://*.ihymns.app",
    "worker-src 'self' https://cdn.jsdelivr.net blob:",
    "manifest-src 'self'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'self'",
    "upgrade-insecure-requests",
];

header("Content-Security-Policy: " . implode('; ', $cspDirectives));

/* =========================================================================
 * OPEN GRAPH META TAGS — Dynamic per-page social sharing previews
 *
 * When a social media platform or messaging app fetches a shared URL,
 * it reads these meta tags to generate a rich preview (title, description,
 * image). We detect the current URL path and customise the tags accordingly.
 *
 * Supported share URL formats:
 *   /song/CP-0001    → Song-specific preview with title and songbook
 *   /songbook/CP     → Songbook-specific preview with name and count
 *   /                → Generic app preview
 *
 * Note: In future iterations (v2+), additional permalink formats may be
 * supported (e.g., /s/CP-0001 for short links).
 * ========================================================================= */

$requestPath = getRequestPath();
$canonicalUrl = getCanonicalUrl();

/* Default OG values (used for generic pages) */
$ogTitle       = $appName . ' — Christian Hymns & Worship Songs';
$ogDescription = $appDesc;
$ogType        = 'website';
$ogImage       = $appUrl . '/assets/icon-512.png';
$ogImageAlt    = $appName . ' logo';

/* Detect specific page routes for customised OG tags */
try {
    $songData = new SongData();

    /* Song page: /song/CP-0001 */
    if (preg_match('#^/song/([A-Za-z]+-\d+)$#', $requestPath, $matches)) {
        $ogSong = $songData->getSongById($matches[1]);
        if ($ogSong !== null) {
            $ogTitle = htmlspecialchars($ogSong['title']) . ' — '
                     . htmlspecialchars($ogSong['songbookName'])
                     . ' #' . (int)$ogSong['number'];
            $ogDescription = 'View lyrics for "' . $ogSong['title']
                           . '" from ' . $ogSong['songbookName']
                           . ' on ' . $appName;
            if (!empty($ogSong['writers'])) {
                $ogDescription .= '. Written by ' . implode(', ', $ogSong['writers']);
            }
            /* Add first verse snippet for richer previews (#123) */
            if (!empty($ogSong['components'][0]['lines'])) {
                $firstLines = array_slice($ogSong['components'][0]['lines'], 0, 2);
                $ogDescription .= '. "' . implode(' / ', $firstLines) . '..."';
            }
            $ogType = 'article';
        }
    }
    /* Songbook page: /songbook/CP */
    elseif (preg_match('#^/songbook/([A-Za-z]+)$#', $requestPath, $matches)) {
        $ogBook = $songData->getSongbook($matches[1]);
        if ($ogBook !== null) {
            $ogTitle = htmlspecialchars($ogBook['name']) . ' — ' . $appName;
            $ogDescription = 'Browse ' . number_format($ogBook['songCount'])
                           . ' songs from ' . $ogBook['name'] . ' on ' . $appName;
        }
    }
} catch (\RuntimeException $e) {
    /* If song data isn't available, use defaults — no fatal error */
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale) ?>" dir="<?= htmlspecialchars($textDir) ?>" data-bs-theme="light" data-ihymns-theme="light">
<head>
    <!-- ================================================================
         META TAGS — Character encoding, viewport, and compatibility
         ================================================================ -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <!-- ================================================================
         SEO & SOCIAL META TAGS — Dynamic per-page for share previews
         ================================================================ -->
    <title><?= $ogTitle ?></title>
    <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($appKeywords) ?>">
    <meta name="author" content="<?= htmlspecialchars($vendorName) ?>">
    <meta name="application-name" content="<?= htmlspecialchars($appName) ?>">
    <meta name="generator" content="<?= htmlspecialchars($appName) ?> PWA">

    <!-- Canonical URL — prevents duplicate content for search engines -->
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">

    <!-- Open Graph Protocol (Facebook, LinkedIn, Slack, Discord, etc.) -->
    <meta property="og:type" content="<?= htmlspecialchars($ogType) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($appName) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:image:alt" content="<?= htmlspecialchars($ogImageAlt) ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:locale" content="<?= htmlspecialchars($locale) ?>">

    <!-- Twitter Card (X / Twitter) -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta name="twitter:image:alt" content="<?= htmlspecialchars($ogImageAlt) ?>">

    <!-- ================================================================
         PWA & MOBILE META TAGS
         ================================================================ -->
    <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1e1b4b" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appName) ?>">
    <!-- Smart App Banner — prompts iOS Safari to install native app (#99) -->
    <meta name="apple-itunes-app" content="app-id=0000000000, app-argument=<?= htmlspecialchars($requestPath) ?>">
    <meta name="msapplication-TileColor" content="#4f46e5">
    <meta name="msapplication-config" content="none">
    <meta name="format-detection" content="telephone=no">

    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json" crossorigin="use-credentials">

    <!-- ================================================================
         FAVICON & APP ICONS
         ================================================================ -->
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/icon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">

    <!-- ================================================================
         STYLESHEETS — CDN with local fallback
         ================================================================ -->

    <!-- Bootstrap CSS -->
    <link rel="stylesheet"
          href="<?= $libs['bootstrap']['css_cdn'] ?>"
          integrity="<?= $libs['bootstrap']['css_sri'] ?>"
          crossorigin="anonymous"
          id="bootstrap-css">

    <!-- Font Awesome CSS -->
    <link rel="stylesheet"
          href="<?= $libs['fontawesome']['css_cdn'] ?>"
          integrity="<?= $libs['fontawesome']['css_sri'] ?>"
          crossorigin="anonymous"
          id="fontawesome-css">

    <!-- Animate.css — CSS animation library (respects prefers-reduced-motion) -->
    <link rel="stylesheet"
          href="<?= $libs['animatecss']['css_cdn'] ?>"
          integrity="<?= $libs['animatecss']['css_sri'] ?>"
          crossorigin="anonymous"
          id="animatecss">

    <!-- iHymns Application Stylesheet -->
    <link rel="stylesheet" href="/css/app.css?v=<?= urlencode($appVersion) ?>">

    <!-- Print Stylesheet -->
    <link rel="stylesheet" href="/css/print.css?v=<?= urlencode($appVersion) ?>" media="print">

    <!-- ================================================================
         PRECONNECT — Speed up CDN resource loading
         ================================================================ -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">

    <!-- ================================================================
         ANALYTICS — Loaded conditionally based on configuration.
         DNT is respected: IP anonymisation is enforced when DNT=1.
         ================================================================ -->
    <?php if (!empty(APP_CONFIG['analytics']['google_analytics_id'])): ?>
    <!-- Google Analytics 4 (GA4) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars(APP_CONFIG['analytics']['google_analytics_id']) ?>"></script>
    <script nonce="<?= $cspNonce ?>">
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', <?= json_encode(APP_CONFIG['analytics']['google_analytics_id']) ?>, {
            /* Anonymise IP when DNT is active */
            <?php if (USER_DNT): ?>
            'anonymize_ip': true,
            'storage': 'none',
            'client_storage': 'none',
            <?php endif; ?>
            'send_page_view': true
        });
    </script>
    <?php endif; ?>

    <?php if (!empty(APP_CONFIG['analytics']['plausible_domain'])): ?>
    <!-- Plausible Analytics (privacy-focused, no cookies) -->
    <script defer data-domain="<?= htmlspecialchars(APP_CONFIG['analytics']['plausible_domain']) ?>"
            src="https://plausible.io/js/script.js"></script>
    <?php endif; ?>

    <?php if (!empty(APP_CONFIG['analytics']['clarity_id'])): ?>
    <!-- Microsoft Clarity -->
    <script nonce="<?= $cspNonce ?>">
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window,document,"clarity","script",<?= json_encode(APP_CONFIG['analytics']['clarity_id']) ?>);
    </script>
    <?php endif; ?>
</head>

<body class="d-flex flex-column min-vh-100">
    <!-- ================================================================
         SKIP NAVIGATION LINK — Accessibility: allows keyboard users
         to skip directly to main content.
         ================================================================ -->
    <a href="#main-content"
       class="visually-hidden-focusable position-absolute top-0 start-0 p-3 bg-primary text-white z-3"
       id="skip-nav">
        Skip to main content
    </a>

    <!-- ================================================================
         PWA INSTALL BANNER — Shown for PWA-capable browsers.
         Offers to install the app or redirects to native app stores.
         Dismissible by user; remembers dismissal in localStorage.
         ================================================================ -->
    <div id="pwa-install-banner"
         class="pwa-install-banner d-none"
         role="banner"
         aria-label="Install application">
        <div class="container-fluid d-flex align-items-center justify-content-between py-2 px-3">
            <div class="d-flex align-items-center gap-2 flex-grow-1">
                <i class="fa-solid fa-mobile-screen-button fa-lg" aria-hidden="true"></i>
                <span class="pwa-install-text">
                    Get the full <strong><?= htmlspecialchars($appName) ?></strong> experience!
                </span>
            </div>
            <button type="button"
                    class="btn btn-sm btn-install-app me-2"
                    id="pwa-install-btn"
                    aria-label="Install <?= htmlspecialchars($appName) ?> app">
                <i class="fa-solid fa-download me-1" aria-hidden="true"></i>
                <span>Install</span>
            </button>
            <button type="button"
                    class="btn-close btn-close-white"
                    id="pwa-install-dismiss"
                    aria-label="Dismiss install banner"></button>
        </div>
    </div>

    <!-- ================================================================
         FIXED TOP HEADER BAR — Always visible, even when scrolling.
         Contains app branding, search toggle, and theme controls.
         ================================================================ -->
    <header id="app-header" class="app-header" role="banner">
        <nav class="navbar navbar-expand" aria-label="Main navigation">
            <div class="container-fluid px-3">
                <!-- App Logo & Name — Dropdown navigation -->
                <div class="dropdown">
                    <button type="button"
                            class="navbar-brand d-flex align-items-center gap-2 dropdown-toggle"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="<?= htmlspecialchars($appName) ?> navigation menu"
                            id="logo-nav-btn">
                        <i class="fa-solid fa-music fa-lg" aria-hidden="true"></i>
                        <span class="fw-bold"><?= htmlspecialchars($appName) ?></span>
                        <?php if ($appDevStatus): ?>
                            <span class="badge bg-warning text-dark ms-1 small"><?= htmlspecialchars($appDevStatus) ?></span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="logo-nav-btn">
                        <li><a class="dropdown-item" href="/" data-navigate="home">
                            <i class="fa-solid fa-house me-2" aria-hidden="true"></i> Home
                        </a></li>
                        <li><a class="dropdown-item" href="/songbooks" data-navigate="songbooks">
                            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i> Songbooks
                        </a></li>
                        <li><a class="dropdown-item" href="/favorites" data-navigate="favorites">
                            <i class="fa-solid fa-heart me-2" aria-hidden="true"></i> Favourites
                        </a></li>
                        <li><a class="dropdown-item" href="/setlist" data-navigate="setlist">
                            <i class="fa-solid fa-list-ol me-2" aria-hidden="true"></i> Set Lists
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/stats" data-navigate="stats">
                            <i class="fa-solid fa-chart-simple me-2" aria-hidden="true"></i> Statistics
                        </a></li>
                        <li><a class="dropdown-item" href="/settings" data-navigate="settings">
                            <i class="fa-solid fa-gear me-2" aria-hidden="true"></i> Settings
                        </a></li>
                        <li><a class="dropdown-item" href="/help" data-navigate="help">
                            <i class="fa-solid fa-circle-question me-2" aria-hidden="true"></i> Help
                        </a></li>
                    </ul>
                </div>

                <!-- Right-side header actions -->
                <div class="d-flex align-items-center gap-2">
                    <!-- Search toggle button -->
                    <button type="button"
                            class="btn btn-header-icon"
                            id="header-search-toggle"
                            aria-label="Toggle search"
                            aria-expanded="false"
                            aria-controls="header-search-bar">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </button>

                    <!-- Shuffle button — randomly pick a song -->
                    <button type="button"
                            class="btn btn-header-icon"
                            id="header-shuffle-btn"
                            aria-label="Random song"
                            title="Pick a random song">
                        <i class="fa-solid fa-shuffle" aria-hidden="true"></i>
                    </button>

                    <!-- Theme toggle dropdown -->
                    <div class="dropdown">
                        <button type="button"
                                class="btn btn-header-icon dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                aria-label="Theme settings"
                                id="theme-dropdown-btn">
                            <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="theme-dropdown-btn">
                            <li>
                                <button class="dropdown-item" data-theme="light" type="button">
                                    <i class="fa-solid fa-sun me-2" aria-hidden="true"></i> Light
                                </button>
                            </li>
                            <li>
                                <button class="dropdown-item" data-theme="dark" type="button">
                                    <i class="fa-solid fa-moon me-2" aria-hidden="true"></i> Dark
                                </button>
                            </li>
                            <li>
                                <button class="dropdown-item" data-theme="high-contrast" type="button">
                                    <i class="fa-solid fa-eye me-2" aria-hidden="true"></i> High Contrast
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button class="dropdown-item" data-theme="system" type="button">
                                    <i class="fa-solid fa-desktop me-2" aria-hidden="true"></i> System
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <!-- ============================================================
             COLLAPSIBLE SEARCH BAR — Slides down below the header
             when the search toggle is activated.
             ============================================================ -->
        <div id="header-search-bar" class="header-search-bar" aria-hidden="true">
            <div class="container-fluid px-3 py-2">
                <form id="search-form" role="search" aria-label="Search songs" autocomplete="off">
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input type="search"
                               class="form-control"
                               id="search-input"
                               name="q"
                               placeholder="Search songs, hymns, lyrics..."
                               aria-label="Search songs"
                               autocomplete="off"
                               spellcheck="false">
                        <button type="button"
                                class="btn btn-outline-secondary"
                                id="search-clear-btn"
                                aria-label="Clear search"
                                class="d-none">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </header>

    <!-- ================================================================
         MAIN CONTENT AREA — Dynamic content loaded via AJAX.
         Padding accounts for fixed header and footer.
         ================================================================ -->
    <main id="main-content"
          class="main-content flex-grow-1"
          role="main"
          aria-live="polite"
          aria-atomic="false"
          tabindex="-1">

        <!-- Loading spinner — shown during initial load and transitions -->
        <div id="page-loader" class="page-loader" role="status" aria-label="Loading content">
            <div class="spinner-container">
                <div class="spinner-border text-primary" role="presentation">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3 text-muted">Loading <?= htmlspecialchars($appName) ?>...</p>
            </div>
        </div>

        <!-- Page content container — AJAX content injected here -->
        <div id="page-content" class="page-content container-fluid px-3 py-3">
            <!-- Content loaded dynamically via JavaScript -->
        </div>
    </main>

    <!-- ================================================================
         FIXED BOTTOM FOOTER — Always visible at screen bottom.
         Contains navigation buttons and copyright/version info.
         ================================================================ -->
    <footer id="app-footer" class="app-footer" role="contentinfo">

        <!-- Navigation buttons — square app-style buttons -->
        <nav class="footer-nav" aria-label="Primary navigation">
            <div class="footer-nav-items">
                <a href="/"
                   class="footer-nav-item active"
                   data-navigate="home"
                   aria-label="Home"
                   aria-current="page">
                    <i class="fa-solid fa-house" aria-hidden="true"></i>
                    <span>Home</span>
                </a>
                <a href="/search"
                   class="footer-nav-item"
                   data-navigate="search"
                   aria-label="Search songs">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span>Search</span>
                </a>
                <button type="button"
                   class="footer-nav-item"
                   data-action="open-numpad"
                   aria-label="Search by song number">
                    <i class="fa-solid fa-hashtag" aria-hidden="true"></i>
                    <span>Number</span>
                </button>
                <a href="/songbooks"
                   class="footer-nav-item"
                   data-navigate="songbooks"
                   aria-label="Browse songbooks">
                    <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                    <span>Books</span>
                </a>
                <a href="/favorites"
                   class="footer-nav-item"
                   data-navigate="favorites"
                   aria-label="View favourites">
                    <i class="fa-solid fa-heart" aria-hidden="true"></i>
                    <span>Favourites</span>
                </a>
                <a href="/settings"
                   class="footer-nav-item"
                   data-navigate="settings"
                   aria-label="Settings">
                    <i class="fa-solid fa-gear" aria-hidden="true"></i>
                    <span>Settings</span>
                </a>
            </div>
        </nav>

        <!-- Copyright & Version info -->
        <div class="footer-info" aria-label="Application information">
            <small>
                <?= $appCopyright ?>
                &nbsp;|&nbsp;
                v<?= htmlspecialchars($versionDisplay) ?>
                &nbsp;|&nbsp;
                <a href="/terms" data-navigate="terms" class="footer-link">Terms</a>
                &nbsp;|&nbsp;
                <a href="/privacy" data-navigate="privacy" class="footer-link">Privacy</a>
            </small>
        </div>
    </footer>

    <!-- ================================================================
         NUMERIC KEYPAD MODAL — For searching by song number.
         Provides a touch-friendly number pad on all devices.
         ================================================================ -->
    <div class="modal fade" id="numpad-modal" tabindex="-1" aria-labelledby="numpad-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content numpad-modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="numpad-modal-label">
                        <i class="fa-solid fa-hashtag me-2" aria-hidden="true"></i>
                        Go to Song Number
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="numpad-songbook" class="form-label">Songbook</label>
                        <select class="form-select" id="numpad-songbook" aria-label="Select songbook">
                            <!-- Populated dynamically via JavaScript -->
                        </select>
                    </div>
                    <div class="numpad-display mb-3">
                        <input type="text"
                               class="form-control form-control-lg text-center numpad-input"
                               id="numpad-display"
                               readonly
                               aria-label="Song number"
                               placeholder="Enter number">
                    </div>
                    <div class="numpad-grid" role="group" aria-label="Numeric keypad">
                        <button type="button" class="btn btn-numpad" data-num="1" aria-label="1">1</button>
                        <button type="button" class="btn btn-numpad" data-num="2" aria-label="2">2</button>
                        <button type="button" class="btn btn-numpad" data-num="3" aria-label="3">3</button>
                        <button type="button" class="btn btn-numpad" data-num="4" aria-label="4">4</button>
                        <button type="button" class="btn btn-numpad" data-num="5" aria-label="5">5</button>
                        <button type="button" class="btn btn-numpad" data-num="6" aria-label="6">6</button>
                        <button type="button" class="btn btn-numpad" data-num="7" aria-label="7">7</button>
                        <button type="button" class="btn btn-numpad" data-num="8" aria-label="8">8</button>
                        <button type="button" class="btn btn-numpad" data-num="9" aria-label="9">9</button>
                        <button type="button" class="btn btn-numpad btn-numpad-action" data-num="clear" aria-label="Clear">
                            <i class="fa-solid fa-delete-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn btn-numpad" data-num="0" aria-label="0">0</button>
                        <button type="button" class="btn btn-numpad btn-numpad-go" data-num="go" aria-label="Go to song">
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div id="numpad-results" class="numpad-results mt-3" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         SHUFFLE MODAL — Options for random song selection.
         ================================================================ -->
    <div class="modal fade" id="shuffle-modal" tabindex="-1" aria-labelledby="shuffle-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="shuffle-modal-label">
                        <i class="fa-solid fa-shuffle me-2" aria-hidden="true"></i>
                        Random Song
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Pick a random song from:</p>
                    <div class="d-grid gap-2">
                        <button type="button"
                                class="btn btn-shuffle-option"
                                data-shuffle-book=""
                                aria-label="Random song from all songbooks">
                            <i class="fa-solid fa-globe me-2" aria-hidden="true"></i>
                            All Songbooks
                        </button>
                        <div id="shuffle-songbook-list"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         FIRST-LAUNCH DISCLAIMER MODAL
         Shown on first visit. User must accept to continue.
         Advises that iHymns is designed to assist with worship and
         its use is intended for those who own the songbooks or have
         a valid CCLI licence. Stored in localStorage after acceptance.
         ================================================================ -->
    <div class="modal fade" id="disclaimer-modal" tabindex="-1"
         aria-labelledby="disclaimer-modal-label"
         aria-hidden="true"
         data-bs-backdrop="static"
         data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="disclaimer-modal-label">
                        <i class="fa-solid fa-hand-holding-heart me-2" aria-hidden="true"></i>
                        Welcome to <?= htmlspecialchars($appName) ?>
                    </h5>
                </div>
                <div class="modal-body">
                    <p class="lead">
                        <?= htmlspecialchars($appName) ?> is designed to assist with worship wherever you are.
                    </p>
                    <p>
                        The lyrics provided in this application are intended for personal worship
                        and congregational use by those who already own the physical songbooks in
                        question, or who hold a valid
                        <strong>CCLI (Christian Copyright Licensing International)</strong> licence
                        covering the reproduction of song lyrics.
                    </p>
                    <p>
                        By continuing to use <?= htmlspecialchars($appName) ?>, you confirm that:
                    </p>
                    <ul>
                        <li>You own one or more of the songbooks featured, <strong>or</strong></li>
                        <li>You or your organisation holds a valid CCLI licence, <strong>or</strong></li>
                        <li>You are accessing only public domain songs</li>
                    </ul>
                    <p class="text-muted small">
                        For full details, please review our
                        <a href="/terms" data-navigate="terms" data-bs-dismiss="modal">Terms of Use</a>
                        and
                        <a href="/privacy" data-navigate="privacy" data-bs-dismiss="modal">Privacy Policy</a>.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-primary btn-lg w-100"
                            id="disclaimer-accept-btn">
                        <i class="fa-solid fa-check me-2" aria-hidden="true"></i>
                        I Understand &amp; Agree
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         SHARE MODAL — For sharing/copying song permalink
         ================================================================ -->
    <div class="modal fade" id="share-modal" tabindex="-1" aria-labelledby="share-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="share-modal-label">
                        <i class="fa-solid fa-share-nodes me-2" aria-hidden="true"></i>
                        Share Song
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Song being shared -->
                    <p class="fw-semibold mb-2" id="share-song-title"></p>

                    <!-- Permalink display and copy -->
                    <div class="input-group mb-3">
                        <input type="text"
                               class="form-control"
                               id="share-url-input"
                               readonly
                               aria-label="Song permalink">
                        <button type="button"
                                class="btn btn-outline-primary"
                                id="share-copy-btn"
                                aria-label="Copy link to clipboard">
                            <i class="fa-solid fa-copy" aria-hidden="true"></i>
                        </button>
                    </div>

                    <!-- Share action buttons -->
                    <div class="d-grid gap-2">
                        <button type="button"
                                class="btn btn-primary d-none"
                                id="share-native-btn">
                            <i class="fa-solid fa-share-from-square me-2" aria-hidden="true"></i>
                            Share via...
                        </button>
                        <button type="button"
                                class="btn btn-outline-secondary"
                                id="share-copy-text-btn">
                            <i class="fa-solid fa-quote-left me-2" aria-hidden="true"></i>
                            Copy Song Details
                        </button>
                    </div>

                    <!-- Copy confirmation -->
                    <div id="share-copy-confirm" class="text-success text-center mt-2 d-none">
                        <i class="fa-solid fa-check me-1" aria-hidden="true"></i>
                        Link copied to clipboard!
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         SCROLL-TO-TOP BUTTON (#97)
         ================================================================ -->
    <button type="button" id="scroll-to-top-btn" class="scroll-to-top-btn"
            aria-label="Scroll to top" aria-hidden="true" tabindex="-1">
        <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
    </button>

    <!-- ================================================================
         TOAST NOTIFICATIONS — For non-intrusive user feedback
         ================================================================ -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"
         aria-live="polite" aria-atomic="true">
    </div>

    <!-- ================================================================
         SCRIPTS — CDN with local fallback
         ================================================================ -->

    <!-- jQuery -->
    <script src="<?= $libs['jquery']['js_cdn'] ?>"
            integrity="<?= $libs['jquery']['js_sri'] ?>"
            crossorigin="anonymous"
            id="jquery-js"></script>
    <script nonce="<?= $cspNonce ?>">
        /* Fallback: load jQuery locally if CDN fails or SRI check fails (#117) */
        if (typeof jQuery === 'undefined') {
            var s = document.createElement('script');
            s.src = '/<?= $libs['jquery']['js_local'] ?>';
            s.async = false;
            document.head.appendChild(s);
        }
    </script>

    <!-- Bootstrap Bundle (includes Popper.js) -->
    <script src="<?= $libs['bootstrap']['js_cdn'] ?>"
            integrity="<?= $libs['bootstrap']['js_sri'] ?>"
            crossorigin="anonymous"
            id="bootstrap-js"></script>
    <script nonce="<?= $cspNonce ?>">
        /* Fallback: load Bootstrap JS locally if CDN fails or SRI check fails (#117) */
        if (typeof bootstrap === 'undefined') {
            var s = document.createElement('script');
            s.src = '/<?= $libs['bootstrap']['js_local'] ?>';
            s.async = false;
            document.head.appendChild(s);
        }
    </script>

    <!-- ================================================================
         iHymns Application Configuration — PHP to JavaScript bridge
         ================================================================ -->
    <script nonce="<?= $cspNonce ?>">
        /**
         * Global application configuration object.
         * Passes server-side PHP configuration to the client-side JavaScript.
         */
        window.iHymnsConfig = {
            appName:        <?= json_encode($appName) ?>,
            version:        <?= json_encode($appVersion) ?>,
            versionDisplay: <?= json_encode($versionDisplay) ?>,
            devStatus:      <?= json_encode($appDevStatus) ?>,
            appUrl:         <?= json_encode($appUrl) ?>,
            apiUrl:         '/api',
            dataUrl:        '/data/songs.json',
            nativeApps:     <?= json_encode(APP_CONFIG['native_apps']) ?>,
            features:       <?= json_encode(APP_CONFIG['features']) ?>,
            fuseJsCdn:      <?= json_encode($libs['fusejs']['js_cdn']) ?>,
            fuseJsLocal:    <?= json_encode($libs['fusejs']['js_local']) ?>,
            toneJsCdn:      <?= json_encode($libs['tonejs']['js_cdn']) ?>,
            toneJsLocal:    <?= json_encode($libs['tonejs']['js_local']) ?>,
            pdfjsCdn:       <?= json_encode($libs['pdfjs']['js_cdn']) ?>,
            pdfjsWorkerCdn: <?= json_encode($libs['pdfjs']['worker_cdn']) ?>,
            pdfjsLocal:     <?= json_encode($libs['pdfjs']['js_local']) ?>,
            pdfjsWorkerLocal: <?= json_encode($libs['pdfjs']['worker_local']) ?>,
            audioBasePath:  '/data/audio/',
            musicBasePath:  '/data/music/',
            dnt:            <?= json_encode(USER_DNT) ?>,
            locale:         <?= json_encode($locale) ?>,
            initialPath:    <?= json_encode($requestPath) ?>,
            songbooks:      <?= json_encode($songData->getSongbooks()) ?>,
            storageBridgeUrl: 'https://sync.ihymns.app/bridge.html',
        };
    </script>

    <!-- iHymns Application Scripts (ES Modules) -->
    <script src="/js/app.js?v=<?= urlencode($appVersion) ?>" type="module"></script>
</body>
</html>
