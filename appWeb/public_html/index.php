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

/* On-demand debug mode (#TBD) — must come first so it catches errors
   anywhere downstream. Honoured only on Alpha/Beta when the page is
   loaded with both `?_debug=1` and `?_dev=1`; production ignores. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'debug_mode.php';
enableDebugModeIfRequested();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'infoAppVer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';

/* Channel gate (#407) — alpha / beta subdomains require the user to
   hold access_alpha / access_beta entitlements. Never gates production
   or /api / /manage / static assets (those paths never hit index.php
   thanks to the root .htaccess). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'channel_gate.php';
enforceChannelGate($app["Application"]["Version"]["Development"]["Status"] ?? null);

/* =========================================================================
 * APPLICATION METADATA — accessed directly via $app array
 * ========================================================================= */

/* Verify native app availability (cached, 24h TTL) */
$nativeApps = APP_CONFIG['native_apps'];
$iosApp     = verifyAppStoreApp('ios', $nativeApps['ios'] ?? null);
$androidApp = verifyAppStoreApp('android', $nativeApps['android'] ?? null);

/** Build a display version string (e.g., "0.1.5 Beta") */
$versionDisplay = $app["Application"]["Version"]["Number"];
if ($app["Application"]["Version"]["Development"]["Status"] !== null) {
    $versionDisplay .= ' ' . $app["Application"]["Version"]["Development"]["Status"];
}

/** On alpha: append build timestamp (yyyymmddhhmmss) for tracking deploys */
$commitDate = $app["Application"]["Version"]["Repo"]["Commit"]["Date"] ?? null;
if ($app["Application"]["Version"]["Development"]["Status"] === 'Alpha' && $commitDate !== null) {
    $buildStamp = preg_replace('/[^0-9]/', '', $commitDate);
    if (strlen($buildStamp) >= 12) {
        $versionDisplay .= ' · ' . substr($buildStamp, 0, 14);
    }
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

$cspMatomoUrl = '';
if (!empty(APP_CONFIG['analytics']['matomo_url'])) {
    $cspMatomoUrl = ' ' . rtrim(APP_CONFIG['analytics']['matomo_url'], '/');
}

$cspDirectives = [
    "default-src 'self'",
    "script-src 'self' 'nonce-{$cspNonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.googletagmanager.com https://plausible.io https://www.clarity.ms https://cdn.usefathom.com{$cspMatomoUrl}",
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
    "img-src 'self' data: https:",
    "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
    "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.google-analytics.com https://plausible.io https://www.clarity.ms https://*.usefathom.com{$cspMatomoUrl}",
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
$ogTitle       = $app["Application"]["Name"] . ' — Christian Hymns & Worship Songs';
$ogDescription = $app["Application"]["Description"]["Synopsis"];
$ogType        = 'website';
$ogImage       = getCanonicalUrl('/og-image');
$ogImageAlt    = $app["Application"]["Name"] . ' logo';

/* JSON-LD structured data — built during OG detection, rendered in <head> */
$jsonLdScripts   = [];
$breadcrumbItems = [];
$pageType        = 'home'; /* 'home', 'song', 'songbook', or 'other' */

/* Detect specific page routes for customised OG tags */
try {
    $songData = new SongData();

    /* Song page: /song/CP-0001 */
    if (preg_match('#^/song/([A-Za-z]+-\d+)$#', $requestPath, $matches)) {
        $ogSong = $songData->getSongById($matches[1]);
        if ($ogSong !== null) {
            $pageType = 'song';
            $ogTitle = htmlspecialchars($ogSong['title']) . ' — '
                     . htmlspecialchars($ogSong['songbookName'])
                     . ' #' . (int)$ogSong['number'];
            $ogDescription = 'View lyrics for "' . $ogSong['title']
                           . '" from ' . $ogSong['songbookName']
                           . ' on ' . $app["Application"]["Name"];
            if (!empty($ogSong['writers'])) {
                $ogDescription .= '. Written by ' . implode(', ', $ogSong['writers']);
            }
            /* Add first verse snippet for richer previews (#123) */
            if (!empty($ogSong['components'][0]['lines'])) {
                $firstLines = array_slice($ogSong['components'][0]['lines'], 0, 2);
                $ogDescription .= '. "' . implode(' / ', $firstLines) . '..."';
            }
            $ogType = 'article';
            $ogImage = getCanonicalUrl('/og-image?song=' . urlencode($matches[1]));
            $ogImageAlt = 'Preview of "' . $ogSong['title'] . '" from ' . $ogSong['songbookName'];

            /* JSON-LD: MusicComposition */
            $musicComposition = [
                '@context' => 'https://schema.org',
                '@type'    => 'MusicComposition',
                'name'     => $ogSong['title'],
                'inLanguage' => $locale,
            ];
            if (!empty($ogSong['composers'])) {
                $musicComposition['composer'] = array_map(
                    fn($name) => ['@type' => 'Person', 'name' => $name],
                    $ogSong['composers']
                );
            }
            if (!empty($ogSong['writers'])) {
                $musicComposition['lyricist'] = array_map(
                    fn($name) => ['@type' => 'Person', 'name' => $name],
                    $ogSong['writers']
                );
            }
            if (!empty($ogSong['songbookName'])) {
                $musicComposition['isPartOf'] = [
                    '@type' => 'MusicAlbum',
                    'name'  => $ogSong['songbookName'],
                ];
            }
            $jsonLdScripts[] = $musicComposition;

            /* Breadcrumb: Home > Songbooks > Songbook Name > #N */
            $songbookId = $ogSong['songbook'] ?? '';
            $breadcrumbItems = [
                ['name' => 'Home',      'url' => getCanonicalUrl('/')],
                ['name' => 'Songbooks', 'url' => getCanonicalUrl('/songbooks')],
                ['name' => $ogSong['songbookName'], 'url' => getCanonicalUrl('/songbook/' . $songbookId)],
                ['name' => '#' . (int)$ogSong['number'], 'url' => $canonicalUrl],
            ];
        }
    }
    /* Songbook page: /songbook/CP */
    elseif (preg_match('#^/songbook/([A-Za-z]+)$#', $requestPath, $matches)) {
        $ogBook = $songData->getSongbook($matches[1]);
        if ($ogBook !== null) {
            $pageType = 'songbook';
            $ogTitle = htmlspecialchars($ogBook['name']) . ' — ' . $app["Application"]["Name"];
            $ogDescription = 'Browse ' . number_format($ogBook['songCount'])
                           . ' songs from ' . $ogBook['name'] . ' on ' . $app["Application"]["Name"];
            $ogImage = getCanonicalUrl('/og-image?songbook=' . urlencode($matches[1]));
            $ogImageAlt = $ogBook['name'] . ' songbook on ' . $app["Application"]["Name"];

            /* Breadcrumb: Home > Songbooks > Songbook Name */
            $breadcrumbItems = [
                ['name' => 'Home',      'url' => getCanonicalUrl('/')],
                ['name' => 'Songbooks', 'url' => getCanonicalUrl('/songbooks')],
                ['name' => $ogBook['name'], 'url' => $canonicalUrl],
            ];
        }
    }
    /* Shared setlist page: /setlist/shared/abc123 */
    elseif (preg_match('#^/setlist/shared/([a-f0-9]+)$#', $requestPath, $matches)) {
        $pageType = 'other';
        $shareId = $matches[1];
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SharedSetlist.php';
        $shareData = sharedSetlistGet($shareId);
        if (is_array($shareData)) {
            $setlistName = $shareData['name'] ?? 'Shared Set List';
            $setlistSongCount = count($shareData['songs'] ?? []);
            $ogTitle = htmlspecialchars($setlistName) . ' — Shared Set List — ' . $app["Application"]["Name"];
            $ogDescription = 'A curated set list with ' . $setlistSongCount
                           . ' ' . ($setlistSongCount === 1 ? 'song' : 'songs')
                           . ' on ' . $app["Application"]["Name"];
            $ogImage = getCanonicalUrl('/og-image?setlist=' . urlencode($shareId));
            $ogImageAlt = 'Set list "' . $setlistName . '" on ' . $app["Application"]["Name"];
        }

        /* Breadcrumb: Home > Set Lists > Shared */
        $breadcrumbItems = [
            ['name' => 'Home',     'url' => getCanonicalUrl('/')],
            ['name' => 'Set List', 'url' => getCanonicalUrl('/setlist')],
            ['name' => 'Shared',   'url' => $canonicalUrl],
        ];
    }
    /* Songbooks listing page */
    elseif ($requestPath === '/songbooks') {
        $pageType = 'other';
        $breadcrumbItems = [
            ['name' => 'Home',      'url' => getCanonicalUrl('/')],
            ['name' => 'Songbooks', 'url' => $canonicalUrl],
        ];
    }
    /* Home page */
    elseif ($requestPath === '/' || $requestPath === '') {
        $pageType = 'home';
    }
    else {
        $pageType = 'other';
    }
} catch (\RuntimeException $e) {
    /* If song data isn't available, use defaults — no fatal error */
}

/* JSON-LD: WebSite schema with SearchAction (home page only) */
if ($pageType === 'home') {
    $siteUrl = getCanonicalUrl('/');
    $jsonLdScripts[] = [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => $app["Application"]["Name"],
        'url'             => $siteUrl,
        'potentialAction'  => [
            '@type'       => 'SearchAction',
            'target'      => $siteUrl . 'search?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/* JSON-LD: BreadcrumbList (all pages except home) */
if (!empty($breadcrumbItems)) {
    $breadcrumbListItems = [];
    foreach ($breadcrumbItems as $pos => $item) {
        $breadcrumbListItems[] = [
            '@type'    => 'ListItem',
            'position' => $pos + 1,
            'name'     => $item['name'],
            'item'     => $item['url'],
        ];
    }
    $jsonLdScripts[] = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $breadcrumbListItems,
    ];
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
    <meta name="keywords" content="<?= htmlspecialchars($app["Application"]["Description"]["Keywords"]) ?>">
    <meta name="author" content="<?= htmlspecialchars($app["Application"]["Vendor"]["Name"]) ?>">
    <meta name="application-name" content="<?= htmlspecialchars($app["Application"]["Name"]) ?>">
    <meta name="generator" content="<?= htmlspecialchars($app["Application"]["Name"]) ?> PWA">

    <!-- Canonical URL — prevents duplicate content for search engines -->
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">

    <!-- Open Graph Protocol (Facebook, LinkedIn, Slack, Discord, etc.) -->
    <meta property="og:type" content="<?= htmlspecialchars($ogType) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($app["Application"]["Name"]) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:image:alt" content="<?= htmlspecialchars($ogImageAlt) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="<?= htmlspecialchars($locale) ?>">

    <!-- Twitter Card (X / Twitter) -->
    <meta name="twitter:card" content="summary_large_image">
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
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($app["Application"]["Name"]) ?>">
    <!-- Smart App Banner — only shown when a verified native app exists (#99) -->
    <?php if ($iosApp['verified']): ?>
        <meta name="apple-itunes-app" content="app-id=<?= htmlspecialchars($iosApp['appId']) ?>, app-argument=<?= htmlspecialchars($requestPath) ?>">
    <?php endif; ?>
    <?php if ($androidApp['verified']): ?>
        <meta name="google-play-app" content="app-id=<?= htmlspecialchars($androidApp['appId']) ?>">
    <?php endif; ?>
    <meta name="msapplication-TileColor" content="#4f46e5">
    <meta name="msapplication-config" content="none">
    <meta name="format-detection" content="telephone=no">

    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json">

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

    <!-- Bootstrap CSS — CDN with local fallback for offline PWA -->
    <link rel="stylesheet"
          href="<?= $libs['bootstrap']['css_cdn'] ?>"
          integrity="<?= $libs['bootstrap']['css_sri'] ?>"
          crossorigin="anonymous"
          id="bootstrap-css"
          onerror="this.onerror=null;this.removeAttribute('integrity');this.removeAttribute('crossorigin');this.href='/<?= $libs['bootstrap']['css_local'] ?>';">

    <!-- Font Awesome CSS — CDN with local fallback for offline PWA -->
    <link rel="stylesheet"
          href="<?= $libs['fontawesome']['css_cdn'] ?>"
          integrity="<?= $libs['fontawesome']['css_sri'] ?>"
          crossorigin="anonymous"
          id="fontawesome-css"
          onerror="this.onerror=null;this.removeAttribute('integrity');this.removeAttribute('crossorigin');this.href='/<?= $libs['fontawesome']['css_local'] ?>';">

    <!-- Animate.css — CDN with local fallback for offline PWA -->
    <link rel="stylesheet"
          href="<?= $libs['animatecss']['css_cdn'] ?>"
          integrity="<?= $libs['animatecss']['css_sri'] ?>"
          crossorigin="anonymous"
          id="animatecss"
          onerror="this.onerror=null;this.removeAttribute('integrity');this.removeAttribute('crossorigin');this.href='/<?= $libs['animatecss']['css_local'] ?>';">

    <!-- iHymns Application Stylesheet -->
    <link rel="stylesheet" href="/css/app.css?v=<?= urlencode($app["Application"]["Version"]["Number"]) ?>">

    <!-- Accessibility Stylesheet (high contrast, colour blind modes, RTL) -->
    <link rel="stylesheet" href="/css/accessibility.css?v=<?= urlencode($app["Application"]["Version"]["Number"]) ?>">

    <!-- Print Stylesheet -->
    <link rel="stylesheet" href="/css/print.css?v=<?= urlencode($app["Application"]["Version"]["Number"]) ?>" media="print">

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
    <!-- Google Analytics 4 (GA4) — deferred until consent is granted -->
    <script nonce="<?= $cspNonce ?>">
        /**
         * GA4 loader — only fires when analytics consent is 'granted'.
         * When DNT is active the server never renders the consent banner
         * and this block still loads but with privacy-safe defaults.
         */
        (function() {
            var consent = localStorage.getItem('ihymns_analytics_consent');
            var dnt = <?= json_encode(USER_DNT) ?>;
            /* Load if: consent granted, OR DNT active (privacy mode), OR no consent banner needed (Plausible-only handled server-side) */
            if (consent === 'granted' || dnt) {
                var s = document.createElement('script');
                s.async = true;
                s.src = 'https://www.googletagmanager.com/gtag/js?id=' + <?= json_encode(APP_CONFIG['analytics']['google_analytics_id']) ?>;
                document.head.appendChild(s);

                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                window.gtag = gtag;
                gtag('js', new Date());
                gtag('config', <?= json_encode(APP_CONFIG['analytics']['google_analytics_id']) ?>, {
                    <?php if (USER_DNT): ?>
                    'anonymize_ip': true,
                    'storage': 'none',
                    'client_storage': 'none',
                    <?php endif; ?>
                    'send_page_view': true
                });
            }
        })();
    </script>
    <?php endif; ?>

    <?php if (!empty(APP_CONFIG['analytics']['plausible_domain'])): ?>
    <!-- Plausible Analytics (privacy-focused, no cookies — always loads) -->
    <script defer data-domain="<?= htmlspecialchars(APP_CONFIG['analytics']['plausible_domain']) ?>"
            src="https://plausible.io/js/script.js"></script>
    <?php endif; ?>

    <?php if (!empty(APP_CONFIG['analytics']['clarity_id'])): ?>
    <!-- Microsoft Clarity — deferred until consent is granted -->
    <script nonce="<?= $cspNonce ?>">
        (function() {
            var consent = localStorage.getItem('ihymns_analytics_consent');
            var dnt = <?= json_encode(USER_DNT) ?>;
            if (consent === 'granted' || dnt) {
                (function(c,l,a,r,i,t,y){
                    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                    t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
                })(window,document,"clarity","script",<?= json_encode(APP_CONFIG['analytics']['clarity_id']) ?>);
            }
        })();
    </script>
    <?php endif; ?>

    <?php if (!empty(APP_CONFIG['analytics']['matomo_url']) && !empty(APP_CONFIG['analytics']['matomo_site_id'])): ?>
    <!-- Matomo Analytics (self-hosted) -->
    <script nonce="<?= $cspNonce ?>">
        var _paq = window._paq = window._paq || [];
        _paq.push(['trackPageView']);
        _paq.push(['enableLinkTracking']);
        <?php if (USER_DNT): ?>
        _paq.push(['setDoNotTrack', true]);
        <?php endif; ?>
        (function() {
            var u=<?= json_encode(rtrim(APP_CONFIG['analytics']['matomo_url'], '/') . '/') ?>;
            _paq.push(['setTrackerUrl', u+'matomo.php']);
            _paq.push(['setSiteId', <?= json_encode(APP_CONFIG['analytics']['matomo_site_id']) ?>]);
            var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
            g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
        })();
    </script>
    <?php endif; ?>

    <?php if (!empty(APP_CONFIG['analytics']['fathom_site_id'])): ?>
    <!-- Fathom Analytics (privacy-focused) -->
    <script src="https://cdn.usefathom.com/script.js" data-site="<?= htmlspecialchars(APP_CONFIG['analytics']['fathom_site_id']) ?>" defer></script>
    <?php endif; ?>

    <!-- ================================================================
         JSON-LD STRUCTURED DATA — SEO (#151)
         ================================================================ -->
    <?php foreach ($jsonLdScripts as $jsonLd): ?>
    <script type="application/ld+json" nonce="<?= $cspNonce ?>"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?></script>
    <?php endforeach; ?>
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
                <i class="pwa-banner-icon" aria-hidden="true"></i>
                <span class="pwa-install-text"></span>
            </div>
            <button type="button"
                    class="btn btn-sm btn-install-app me-2 d-none"
                    id="pwa-install-btn"
                    aria-label="Install <?= htmlspecialchars($app["Application"]["Name"]) ?> app">
                <i class="me-1" aria-hidden="true"></i>
                <span></span>
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
                            aria-label="<?= htmlspecialchars($app["Application"]["Name"]) ?> navigation menu"
                            id="logo-nav-btn">
                        <i class="fa-solid fa-music fa-lg" aria-hidden="true"></i>
                        <span class="fw-bold"><?= htmlspecialchars($app["Application"]["Name"]) ?></span>
                        <?php if ($app["Application"]["Version"]["Development"]["Status"]): ?>
                            <span class="badge bg-warning text-dark ms-1 small"><?= htmlspecialchars($app["Application"]["Version"]["Development"]["Status"]) ?></span>
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
                        <li><a class="dropdown-item" href="/help" data-navigate="help">
                            <i class="fa-solid fa-circle-question me-2" aria-hidden="true"></i> Help
                        </a></li>

                        <!-- Single "Manage" entry — opens /manage/ which
                             shows per-entitlement cards for every
                             curator and administration surface. Visible
                             to any signed-in user with at least one
                             management entitlement (toggled by
                             user-auth.js). -->
                        <li id="nav-manage-divider" class="d-none"><hr class="dropdown-divider"></li>
                        <li id="nav-manage-li" class="d-none">
                            <a class="dropdown-item" href="/manage/">
                                <i class="fa-solid fa-gears me-2" aria-hidden="true"></i> Manage
                            </a>
                        </li>
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

                    <!-- In-app notifications bell (#289). Hidden until the
                         user signs in; the notifications module reveals it
                         and populates the unread-count badge + dropdown
                         body from /api?action=notifications_list. -->
                    <div class="dropdown d-none" id="header-notifications-dropdown">
                        <button type="button"
                                class="btn btn-header-icon position-relative"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                aria-label="Notifications"
                                id="header-notifications-btn"
                                title="Notifications">
                            <i class="fa-solid fa-bell" aria-hidden="true"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                                  id="header-notifications-badge"
                                  aria-live="polite">
                                0
                                <span class="visually-hidden">unread notifications</span>
                            </span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-0"
                             id="header-notifications-panel"
                             aria-labelledby="header-notifications-btn"
                             style="width: 320px; max-width: 90vw;">
                            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                                <strong class="small">Notifications</strong>
                                <button type="button"
                                        class="btn btn-sm btn-link text-decoration-none p-0 small"
                                        id="header-notifications-mark-all">
                                    Mark all read
                                </button>
                            </div>
                            <div id="header-notifications-list"
                                 style="max-height: 60vh; overflow-y: auto;">
                                <div class="text-center text-muted small py-4" id="header-notifications-empty">
                                    No notifications.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- User account button — shows sign-in or user menu -->
                    <div class="dropdown" id="header-user-dropdown">
                        <button type="button"
                                class="btn btn-header-icon dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                aria-label="Account"
                                id="header-user-btn"
                                title="Account">
                            <i class="fa-solid fa-user" aria-hidden="true" id="header-user-icon"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="header-user-btn">
                            <!-- Logged-out state (default) -->
                            <li id="header-user-guest">
                                <button class="dropdown-item" type="button" id="header-signin-btn">
                                    <i class="fa-solid fa-right-to-bracket me-2" aria-hidden="true"></i> Sign In
                                </button>
                            </li>
                            <li id="header-user-register-li">
                                <button class="dropdown-item" type="button" id="header-register-btn">
                                    <i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i> Create Account
                                </button>
                            </li>
                            <!-- ============================================
                                 Logged-in state (hidden by default; visibility
                                 toggled by user-auth.js). This menu now holds
                                 only items that relate to the signed-in
                                 person; app-wide Curator + Administration
                                 sections live in the iHymns (logo) dropdown.
                                 ============================================ -->
                            <!-- Display name + role are clickable shortcuts that
                                 deep-link to the Account & Profile tab on /settings. -->
                            <li id="header-user-name" class="d-none">
                                <a class="dropdown-item fw-semibold" href="/settings#tab-profile"
                                   data-navigate="settings" id="header-user-display-name"></a>
                            </li>
                            <li id="header-user-role-li" class="d-none">
                                <a class="dropdown-item small text-muted py-1" href="/settings#tab-profile"
                                   data-navigate="settings" id="header-user-role-text"></a>
                            </li>

                            <!-- ── Account ── -->
                            <li id="header-user-divider" class="d-none"><hr class="dropdown-divider"></li>
                            <li id="header-user-settings-li" class="d-none">
                                <a class="dropdown-item" href="/settings#tab-profile" data-navigate="settings">
                                    <i class="fa-solid fa-gear me-2" aria-hidden="true"></i> Settings
                                </a>
                            </li>
                            <li id="header-user-setlists-li" class="d-none">
                                <a class="dropdown-item" href="/setlist" data-navigate="setlist">
                                    <i class="fa-solid fa-list-ol me-2" aria-hidden="true"></i> My Set Lists
                                </a>
                            </li>
                            <li id="header-user-sync-li" class="d-none">
                                <button class="dropdown-item" type="button" id="header-sync-btn">
                                    <i class="fa-solid fa-arrows-rotate me-2" aria-hidden="true"></i> Sync Set Lists
                                </button>
                            </li>

                            <!-- ── Sign out ── -->
                            <li id="header-user-divider2" class="d-none"><hr class="dropdown-divider"></li>
                            <li id="header-user-signout-li" class="d-none">
                                <button class="dropdown-item" type="button" id="header-signout-btn">
                                    <i class="fa-solid fa-right-from-bracket me-2" aria-hidden="true"></i> Sign Out
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
                                class="btn btn-outline-secondary d-none"
                                id="search-clear-btn"
                                aria-label="Clear search">
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
                <p class="mt-3 text-muted">Loading <?= htmlspecialchars($app["Application"]["Name"]) ?>...</p>
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
                <?= $app["Application"]["Copyright"]["Full"] ?>
                &nbsp;|&nbsp;
                v<?= htmlspecialchars($versionDisplay) ?><?php
                    /* Subtle data source indicator — Alpha/Beta only */
                    if ($app["Application"]["Version"]["Development"]["Status"] !== null && isset($songData) && $songData->isJsonFallback()) {
                        echo ' <span title="Using JSON fallback (MySQL not configured)" style="opacity:0.4;cursor:help">&#9679; json</span>';
                    }
                ?>
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
                        Welcome to <?= htmlspecialchars($app["Application"]["Name"]) ?>
                    </h5>
                </div>
                <div class="modal-body">
                    <p class="lead">
                        <?= htmlspecialchars($app["Application"]["Name"]) ?> is designed to assist with worship wherever you are.
                    </p>
                    <p>
                        The lyrics provided in this application are intended for personal worship
                        and congregational use by those who already own the physical songbooks in
                        question, or who hold a valid
                        <strong>CCLI (Christian Copyright Licensing International)</strong> licence
                        covering the reproduction of song lyrics.
                    </p>
                    <p>
                        By continuing to use <?= htmlspecialchars($app["Application"]["Name"]) ?>, you confirm that:
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
         COOKIE / ANALYTICS CONSENT BANNER — GDPR compliance
         Shows only when GA4 or Clarity is configured and user hasn't
         already consented/declined. Plausible is cookieless so it
         doesn't require consent. Hidden when DNT is active.
         ================================================================ -->
    <?php
        $hasGa4     = !empty(APP_CONFIG['analytics']['google_analytics_id']);
        $hasClarity = !empty(APP_CONFIG['analytics']['clarity_id']);
        $hasPlausible = !empty(APP_CONFIG['analytics']['plausible_domain']);
        $needsConsent = ($hasGa4 || $hasClarity) && !USER_DNT;
    ?>
    <?php if ($needsConsent): ?>
    <div id="analytics-consent-banner" class="analytics-consent-banner d-none" role="dialog"
         aria-label="Analytics consent"
         data-has-ga4="<?= $hasGa4 ? '1' : '0' ?>"
         data-has-clarity="<?= $hasClarity ? '1' : '0' ?>"
         data-has-plausible="<?= $hasPlausible ? '1' : '0' ?>">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <p class="mb-0 small">
                <i class="fa-solid fa-chart-simple me-1" aria-hidden="true"></i>
                We use analytics to improve iHymns. No personal data is collected.
                <a href="/privacy" data-navigate="privacy" class="consent-link">Learn more</a>
            </p>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-sm btn-outline-light" id="consent-decline">Decline</button>
                <button type="button" class="btn btn-sm btn-light" id="consent-accept">Accept</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

         Hardened (#526) — previously every field was its own inline
         `<?= json_encode(...) ?>`, which meant any throw inside one
         corrupted the JS literal mid-output and killed SPA boot (the
         exact root cause of #509). Now the entire config is built
         server-side as a single PHP array, the only DB-touching call
         (getSongbooks) is wrapped in try/catch with a [] fallback,
         and the whole thing is encoded in one json_encode() call.
         A transient DB failure now leaves the SPA bootable in a
         degraded state (empty songbooks list) instead of dying.
         ================================================================ -->
    <?php
        try {
            $iHymnsConfigSongbooks = $songData->getSongbooks();
        } catch (\Throwable $e) {
            error_log('[index.php] iHymnsConfig.songbooks fallback to [] — '
                . $e->getMessage());
            $iHymnsConfigSongbooks = [];
        }

        $iHymnsConfig = [
            'appName'         => $app["Application"]["Name"],
            'version'         => $app["Application"]["Version"]["Number"],
            'versionDisplay'  => $versionDisplay,
            'devStatus'       => $app["Application"]["Version"]["Development"]["Status"],
            'appUrl'          => $app["Application"]["Website"]["URL"],
            'apiUrl'          => '/api',
            'dataUrl'         => '/api?action=songs_json',
            'nativeApps'      => [
                'ios'             => $iosApp['verified'] ? ($iosApp['storeUrl'] ?? $nativeApps['ios']) : null,
                'iosVerified'     => $iosApp['verified'],
                'android'         => $androidApp['verified'] ? ($androidApp['storeUrl'] ?? $nativeApps['android']) : null,
                'androidVerified' => $androidApp['verified'],
            ],
            'features'        => APP_CONFIG['features'],
            'fuseJsCdn'       => $libs['fusejs']['js_cdn'],
            'fuseJsLocal'     => $libs['fusejs']['js_local'],
            'toneJsCdn'       => $libs['tonejs']['js_cdn'],
            'toneJsLocal'     => $libs['tonejs']['js_local'],
            'pdfjsCdn'        => $libs['pdfjs']['js_cdn'],
            'pdfjsWorkerCdn'  => $libs['pdfjs']['worker_cdn'],
            'pdfjsLocal'      => $libs['pdfjs']['js_local'],
            'pdfjsWorkerLocal'=> $libs['pdfjs']['worker_local'],
            'audioBasePath'   => '/data/audio/',
            'musicBasePath'   => '/data/music/',
            'dnt'             => USER_DNT,
            'locale'          => $locale,
            'initialPath'     => $requestPath,
            'songbooks'       => $iHymnsConfigSongbooks,
            'storageBridgeUrl'=> 'https://sync.ihymns.app/bridge.html',
            'analytics'       => [
                'hasGa4'       => !empty(APP_CONFIG['analytics']['google_analytics_id']),
                'hasClarity'   => !empty(APP_CONFIG['analytics']['clarity_id']),
                'hasPlausible' => !empty(APP_CONFIG['analytics']['plausible_domain']),
            ],
        ];

        /* Encode once. If encoding itself fails (e.g. invalid UTF-8 in a
           songbook name), fall back to a minimal stub so the SPA still
           boots — better than emitting `window.iHymnsConfig = ;` which
           is invalid JS and would reproduce #509. */
        $iHymnsConfigJson = json_encode($iHymnsConfig, JSON_UNESCAPED_SLASHES);
        if ($iHymnsConfigJson === false) {
            error_log('[index.php] json_encode($iHymnsConfig) failed: '
                . json_last_error_msg());
            $iHymnsConfigJson = json_encode([
                'appName'    => 'iHymns',
                'apiUrl'     => '/api',
                'dataUrl'    => '/api?action=songs_json',
                'songbooks'  => [],
                'features'   => [],
                'analytics'  => ['hasGa4' => false, 'hasClarity' => false, 'hasPlausible' => false],
                'devStatus'  => null,
                'dnt'        => false,
                'locale'     => 'en',
                'initialPath'=> '/',
                /* Minimal stub — SPA boots in a degraded state. */
            ]);
        }
    ?>
    <script nonce="<?= $cspNonce ?>">
        /* Single throw-site server-side; single json_encode boundary.
           See the PHP block above for the hardening rationale. */
        window.iHymnsConfig = <?= $iHymnsConfigJson ?>;
    </script>

    <!-- iHymns Application Scripts (ES Modules)

         Cache-buster combines the semver with the deploy-time commit-date
         stamp (injected by the GH Actions pipeline into infoAppVer.php)
         so every deploy produces a new URL even when the semver hasn't
         bumped. Without the commit stamp, .htaccess' max-age=3600 holds
         onto user-auth.js and peers for up to an hour after a deploy. -->
    <?php
        $_appJsStamp = preg_replace('/[^0-9]/', '',
            (string)($app['Application']['Version']['Repo']['Commit']['Date'] ?? ''));
        $_appJsVersion = $app['Application']['Version']['Number']
            . ($_appJsStamp !== '' ? '-' . $_appJsStamp : '');
    ?>
    <script src="/js/app.js?v=<?= urlencode($_appJsVersion) ?>" type="module"></script>

    <!-- Colour Vision Deficiency (CVD) SVG correction filters (#319) -->
    <?php readfile(__DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'cvd-filters.svg'); ?>
</body>
</html>
