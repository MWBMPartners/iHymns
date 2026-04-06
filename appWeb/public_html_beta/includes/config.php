<?php

declare(strict_types=1);

/**
 * iHymns — Application Configuration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Centralised configuration constants and settings for the iHymns
 * web application. Defines paths, CDN URLs, library versions,
 * analytics, feature flags, and internationalisation settings.
 *
 * USAGE:
 *   require_once __DIR__ . '/config.php';
 *   echo APP_CONFIG['libraries']['bootstrap']['version'];
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * PATH CONSTANTS
 * ========================================================================= */

/** Root directory of the web application */
define('APP_ROOT', dirname(__DIR__));

/** Path to the includes directory */
define('APP_INCLUDES', __DIR__);

/** Path to the song data JSON file */
define('APP_DATA_FILE', APP_ROOT . '/data/songs.json');

/* =========================================================================
 * DO NOT TRACK (DNT) DETECTION
 *
 * Respects the DNT HTTP header. When DNT is active:
 *   - Analytics still runs (for aggregate statistics)
 *   - IP addresses are anonymised in analytics platforms
 *   - No personally identifiable information is logged
 * ========================================================================= */

define('USER_DNT', (
    isset($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1'
));

/* =========================================================================
 * MAIN APPLICATION CONFIGURATION ARRAY
 *
 * All configuration is centralised here as a single constant.
 * ========================================================================= */

define('APP_CONFIG', [

    /* -----------------------------------------------------------------
     * Third-party library definitions
     *
     * Each library specifies:
     *   - version:    The exact version to load
     *   - css_cdn:    CDN URL for the CSS file (null if no CSS)
     *   - js_cdn:     CDN URL for the JS file (null if no JS)
     *   - css_local:  Local fallback path for CSS (null if no CSS)
     *   - js_local:   Local fallback path for JS (null if no JS)
     * ----------------------------------------------------------------- */
    'libraries' => [

        /* Bootstrap 5.3 — Responsive framework */
        'bootstrap' => [
            'version'    => '5.3.6',
            'css_cdn'    => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css',
            'css_sri'    => 'sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT',
            'js_cdn'     => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js',
            'js_sri'     => 'sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO',
            'css_local'  => 'vendor/bootstrap/bootstrap.min.css',
            'js_local'   => 'vendor/bootstrap/bootstrap.bundle.min.js',
        ],

        /* Font Awesome 6.7 — Icon library */
        'fontawesome' => [
            'version'    => '6.7.2',
            'css_cdn'    => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css',
            'css_sri'    => 'sha384-nRgPTkuX86pH8yjPJUAFuASXQSSl2/bBUiNV47vSYpKFxHJhbcrGnmlYpYJMeD7a',
            'css_local'  => 'vendor/fontawesome/css/all.min.css',
        ],

        /* jQuery 3.7 — DOM manipulation & AJAX */
        'jquery' => [
            'version'    => '3.7.1',
            'js_cdn'     => 'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js',
            'js_sri'     => 'sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs',
            'js_local'   => 'vendor/jquery/jquery.min.js',
        ],

        /* Animate.css 4.1 — CSS animation library */
        'animatecss' => [
            'version'    => '4.1.1',
            'css_cdn'    => 'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css',
            'css_sri'    => 'sha384-Gu3KVV2H9d+yA4QDpVB7VcOyhJlAVrcXd0thEjr4KznfaFPLe0xQJyonVxONa4ZC',
            'css_local'  => 'vendor/animate/animate.min.css',
        ],

        /* Fuse.js 7.1 — Client-side fuzzy search
         * NOTE: Loaded via dynamic import() — SRI not supported by browsers
         * for ES module imports. Hash stored for reference only. */
        'fusejs' => [
            'version'    => '7.1.0',
            'js_cdn'     => 'https://cdn.jsdelivr.net/npm/fuse.js@7.1.0/dist/fuse.min.mjs',
            'js_sri'     => 'sha384-811P6qTvNGdeioyBs0y+XTVXngjnK9rDj1bK7eeYjJcOVKGY3c5nDbCmE61nrHul',
            'js_local'   => 'vendor/fuse/fuse.min.mjs',
        ],

        /* Tone.js 15.1 — Web Audio framework for MIDI playback (#90)
         * NOTE: jsDelivr auto-minifies Tone.js (no Tone.min.js in npm package),
         * so the served content hash differs from the source. SRI hash stored
         * for the non-minified source; verify against CDN if switching URL. */
        'tonejs' => [
            'version'    => '15.1.22',
            'js_cdn'     => 'https://cdn.jsdelivr.net/npm/tone@15.1.22/build/Tone.js',
            'js_sri'     => 'sha384-NWoslxaf/3dYwQk+uGziDYJFdsjBpVlU1WpFRexuDFcIk/5PJpCbpVXia2Uikeix',
            'js_local'   => 'vendor/tone/Tone.min.js',
        ],

        /* PDF.js 4.9 — PDF rendering for sheet music viewer (#91)
         * NOTE: Loaded via dynamic import() — SRI not supported by browsers
         * for ES module imports. Hashes stored for reference only. */
        'pdfjs' => [
            'version'    => '4.9.124',
            'js_cdn'     => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.9.124/build/pdf.min.mjs',
            'js_sri'     => 'sha384-fxC9og0KaUwjhnUZmpdzl0G6njIZnnkS/8mWNomcEPWK3GzJzp8elGfMjbh5OmnQ',
            'worker_cdn' => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.9.124/build/pdf.worker.min.mjs',
            'worker_sri' => 'sha384-crCBoB141cuC+Cg34QibH3jxI+0kRpZIM+ZcpICrs0Cr1BvnkRPE3V++Ct0Dfuih',
            'js_local'   => 'vendor/pdfjs/pdf.min.mjs',
            'worker_local' => 'vendor/pdfjs/pdf.worker.min.mjs',
        ],
    ],

    /* -----------------------------------------------------------------
     * Analytics Configuration
     *
     * Supports multiple analytics platforms simultaneously.
     * Set a tracking ID to enable; set to null to disable.
     * DNT is respected: when active, IP anonymisation is enforced
     * and no PII is collected, but aggregate tracking continues.
     * ----------------------------------------------------------------- */
    'analytics' => [
        /* Google Analytics 4 (GA4) Measurement ID (e.g., 'G-XXXXXXXXXX') */
        'google_analytics_id' => null,

        /* Microsoft Clarity project ID (e.g., 'abcdefghij') */
        'clarity_id'          => null,

        /* Plausible Analytics domain (e.g., 'ihymns.app') — privacy-focused */
        'plausible_domain'    => null,

        /* Custom analytics endpoint for self-hosted solutions (e.g., Matomo) */
        'custom_endpoint'     => null,
        'custom_site_id'      => null,
    ],

    /* -----------------------------------------------------------------
     * Songbook colour map (#98)
     *
     * Each songbook gets a distinct accent colour used throughout the
     * app: songbook grid icons, song number badges, song page borders.
     * Colours are defined as [light, dark, solid, shadow_rgba].
     * ----------------------------------------------------------------- */
    'songbook_colours' => [
        'CP'   => ['#6366f1', '#818cf8', '#6366f1', '99, 102, 241'],  /* Indigo */
        'JP'   => ['#ec4899', '#f472b6', '#ec4899', '236, 72, 153'],  /* Pink */
        'MP'   => ['#14b8a6', '#2dd4bf', '#14b8a6', '20, 184, 166'],  /* Teal */
        'SDAH' => ['#f59e0b', '#fbbf24', '#f59e0b', '245, 158, 11'],  /* Amber */
        'CH'   => ['#ef4444', '#f87171', '#ef4444', '239, 68, 68'],   /* Red */
        'Misc' => ['#8b5cf6', '#a78bfa', '#8b5cf6', '139, 92, 246'], /* Violet */
    ],

    /* -----------------------------------------------------------------
     * Native app store URLs for PWA install banner redirection.
     * Set to null if no native app exists for that platform yet.
     * ----------------------------------------------------------------- */
    'native_apps' => [
        'ios'     => null,  /* e.g., 'https://apps.apple.com/app/ihymns/id1234567890' */
        'android' => null,  /* e.g., 'https://play.google.com/store/apps/details?id=ltd.mwbmpartners.ihymns' */
    ],

    /* -----------------------------------------------------------------
     * Feature flags — toggle features on/off
     * ----------------------------------------------------------------- */
    'features' => [
        'audio_playback'   => true,   /* Enable MIDI audio playback */
        'sheet_music'      => true,   /* Enable PDF sheet music viewer */
        'shuffle'          => true,   /* Enable shuffle/random song feature */
        'favorites'        => true,   /* Enable favorites functionality */
        'public_domain_only' => false, /* HIDDEN: restrict to copyright-free songs only */
    ],

    /* -----------------------------------------------------------------
     * Internationalisation (i18n) — Language support
     *
     * Currently English only, but structured for future expansion.
     * When additional languages are added, the UI strings and song
     * data will be loaded based on the active locale.
     * ----------------------------------------------------------------- */
    'i18n' => [
        'default_locale'    => 'en',        /* Default language locale */
        'supported_locales' => ['en'],       /* Currently supported locales */
        'fallback_locale'   => 'en',        /* Fallback if requested locale unavailable */
        'text_direction'    => 'ltr',       /* Default text direction (ltr or rtl) */
    ],
]);

/* =========================================================================
 * HELPER FUNCTION — Detect the current request path (clean URL)
 *
 * Strips query strings and normalises the URI for route matching.
 * Used by index.php to determine which OG meta tags to render
 * for social media share link previews.
 * ========================================================================= */

/**
 * Get the clean request path without query string or trailing slashes.
 *
 * @return string Normalised path (e.g., '/song/CP-0001')
 */
function getRequestPath(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    /* Remove query string */
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    /* Remove trailing slash (except for root) */
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return $path;
}

/**
 * Build the full canonical URL for the current page or a given path.
 *
 * @param string $path Optional path to append (default: current request path)
 * @return string Full URL (e.g., 'https://ihymns.app/song/CP-0001')
 */
function getCanonicalUrl(string $path = ''): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'ihymns.app';

    if ($path === '') {
        $path = getRequestPath();
    }

    return $scheme . '://' . $host . $path;
}
