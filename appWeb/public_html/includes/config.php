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
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
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

/** Path to the shared data directory (outside the web root for security) */
define('APP_DATA_SHARE', dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'data_share');

/** Path to the song data JSON file */
define('APP_DATA_FILE', APP_DATA_SHARE . DIRECTORY_SEPARATOR . 'song_data' . DIRECTORY_SEPARATOR . 'songs.json');

/** Path to the shared setlist JSON directory */
define('APP_SETLIST_SHARE_DIR', APP_DATA_SHARE . DIRECTORY_SEPARATOR . 'setlist_json');

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
 * APP STORE VERIFICATION (#1403)
 *
 * Checks whether a native app is actually published and available in the
 * relevant app store. Results are cached for 24 hours (file-based) so we
 * don't call the API on every page load.
 *
 * The store ID/ASIN/package can come from TWO sources — the admin-editable
 * tblAppSettings rows set on /manage/configuration (native_app_ios /
 * _android / _amazon, read via getAppSetting()) OR the legacy
 * APP_CONFIG['native_apps'] code-constant fallback — and either source may
 * hold a bare ID or a full store URL. ihymnsParseAppStoreId() below is the
 * ONE parser both this function AND the admin save handler
 * (manage/configuration.php's save_native_apps) call, so what the admin's
 * paste is validated against can never drift from what this function later
 * resolves.
 *
 * Usage:
 *   $result = verifyAppStoreApp('ios', 'https://apps.apple.com/app/ihymns/id1234567890');
 *   // Returns: ['verified' => true, 'appId' => '1234567890', 'name' => 'iHymns', ...]
 *   // Or:      ['verified' => false] if not found / not released
 * ========================================================================= */

/**
 * Parse a native-app-store identifier out of either a bare ID/ASIN/package
 * or a full store URL. Shared by verifyAppStoreApp() (below) and the
 * /manage/configuration save_native_apps handler — ONE place decides what
 * counts as a valid ID per platform, so the admin's saved value and the
 * public site's resolution of it can never disagree.
 *
 * @param string $platform 'ios' | 'android' | 'amazon'
 * @param string $input    Bare ID/ASIN/package, OR a full store URL
 * @return string|null Canonical ID (uppercased for the Amazon ASIN),
 *                      or null when $input doesn't match the platform's
 *                      expected shape at all (caller should reject/ignore).
 */
function ihymnsParseAppStoreId(string $platform, string $input): ?string {
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if ($platform === 'ios') {
        /* Apple App Store: .../id1234567890, or a bare numeric ID. The
           universal app (iOS/iPadOS/macOS/tvOS/watchOS/visionOS) shares
           this one App Store listing/ID. */
        if (preg_match('/id(\d{5,})/', $input, $m)) {
            return $m[1];
        }
        if (preg_match('/^\d{5,}$/', $input)) {
            return $input;
        }
        return null;
    }

    if ($platform === 'android') {
        /* Google Play: ...?id=com.example.app, or a bare package name. */
        if (preg_match('/[?&]id=([a-zA-Z0-9_.]+)/', $input, $m)) {
            return $m[1];
        }
        if (preg_match('/^[a-zA-Z0-9_.]+$/', $input)) {
            return $input;
        }
        return null;
    }

    if ($platform === 'amazon') {
        /* Amazon Appstore (Fire OS): .../dp/B0XXXXXXXX (10-char ASIN), or
           a bare ASIN. */
        if (preg_match('#/dp/([A-Za-z0-9]{10})(?:[/?]|$)#', $input, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^[A-Za-z0-9]{10}$/', $input)) {
            return strtoupper($input);
        }
        return null;
    }

    return null;
}

/**
 * Build the canonical, template-based store URL for a validated app ID.
 * NEVER built from the admin's raw pasted string — only from an ID that
 * has already passed ihymnsParseAppStoreId() — so a malformed or
 * attacker-controlled value can never reach the DOM (meta tags, the
 * window.iHymnsConfig JSON island, or pwa.js's native-app banner link).
 *
 * @param string $platform 'ios' | 'android' | 'amazon'
 * @param string $appId    Canonical ID, as returned by ihymnsParseAppStoreId()
 * @return string|null
 */
function ihymnsAppStoreCanonicalUrl(string $platform, string $appId): ?string {
    return match ($platform) {
        'ios'     => 'https://apps.apple.com/app/id' . $appId,
        'android' => 'https://play.google.com/store/apps/details?id=' . $appId,
        'amazon'  => 'https://www.amazon.com/dp/' . $appId,
        default   => null,
    };
}

/**
 * Resolve a native-app store value: the admin-set tblAppSettings row (read
 * by the caller via getAppSetting()) FIRST, falling back to the
 * APP_CONFIG['native_apps'] code constant only when the setting is unset/
 * empty (including "DB unavailable", since getAppSetting() itself already
 * degrades to '' on any DB error). A pure function — deliberately takes
 * the ALREADY-READ setting value rather than calling getAppSetting()
 * itself — so the admin-overrides-constant precedence is unit-testable
 * without a live DB (tests/php/test-native-app-stores.php). index.php is
 * the one caller today.
 *
 * @param string|null $settingValue getAppSetting('native_app_...', '') result
 * @param string|null $fallback     APP_CONFIG['native_apps'][...] value
 * @return string|null
 */
function ihymnsResolveNativeAppSetting(?string $settingValue, ?string $fallback): ?string {
    return ($settingValue !== null && $settingValue !== '') ? $settingValue : $fallback;
}

/**
 * Verify that a native app exists and is published in the app store.
 *
 * @param string      $platform  'ios' | 'android' | 'amazon'
 * @param string|null $storeUrl  Bare ID/ASIN/package OR a full store URL
 *                               (nullable — returns unverified if null/empty)
 * @return array{verified: bool, appId?: string, name?: string, storeUrl?: string}
 */
function verifyAppStoreApp(string $platform, ?string $storeUrl): array {
    if ($storeUrl === null || trim($storeUrl) === '') {
        return ['verified' => false];
    }

    $appId = ihymnsParseAppStoreId($platform, $storeUrl);
    if ($appId === null) {
        return ['verified' => false];
    }

    /* Always a template-built URL — see ihymnsAppStoreCanonicalUrl() above. */
    $canonicalUrl = ihymnsAppStoreCanonicalUrl($platform, $appId);

    /* Check the file-based cache (24-hour TTL) */
    $cacheDir = sys_get_temp_dir() . '/ihymns_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/appstore_' . $platform . '_' . md5($appId) . '.json';
    $cacheTTL  = 86400; /* 24 hours */

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $skipCache = false;

    if ($platform === 'ios') {
        /* Live iTunes lookup — a genuine "not found" (wrong ID / app pulled)
           still hides the banner, but a NETWORK failure fails OPEN: the
           admin explicitly set a well-formed ID, so a transient outage of
           itunes.apple.com must not hide a real, working store link. */
        $result = _verifyAppleAppStore($appId, $canonicalUrl);
        $skipCache = !empty($result['_transient']);
        unset($result['_transient']);
    } else {
        /* Google Play and Amazon have no free, ToS-compliant live-lookup
           API — a set + well-formed ID/ASIN is treated as showable (#1403
           fail-open; the owner explicitly configured it). */
        $result = ['verified' => true, 'appId' => $appId, 'storeUrl' => $canonicalUrl];
    }

    /* Cache the result (skipped for a transient network failure so the
       NEXT request retries the live check instead of being stuck on the
       fail-open result for a full 24h TTL). */
    if (!$skipCache) {
        @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
    }

    return $result;
}

/**
 * Check the Apple iTunes Lookup API for an app by ID.
 *
 * @param string $appId    Numeric Apple app ID
 * @param string $storeUrl Canonical App Store URL
 * @return array{verified: bool, appId: string, name?: string, storeUrl: string, _transient?: bool}
 */
function _verifyAppleAppStore(string $appId, string $storeUrl): array {
    $lookupUrl = 'https://itunes.apple.com/lookup?id=' . urlencode($appId);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method'  => 'GET',
            'header'  => "Accept: application/json\r\n",
        ],
    ]);

    $response = @file_get_contents($lookupUrl, false, $ctx);
    if ($response === false) {
        /* Network error — fail OPEN (#1403): the admin explicitly set this
           well-formed ID, so a transient itunes.apple.com outage must not
           hide a real, working App Store link. `_transient` tells the
           caller not to cache this result. */
        return ['verified' => true, 'appId' => $appId, 'storeUrl' => $storeUrl, '_transient' => true];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || ($data['resultCount'] ?? 0) < 1) {
        /* A real answer came back and the app genuinely isn't there — fail
           CLOSED (most likely a wrong/unpublished ID). */
        return ['verified' => false, 'appId' => $appId, 'storeUrl' => $storeUrl];
    }

    $app = $data['results'][0];
    return [
        'verified' => true,
        'appId'    => $appId,
        'name'     => $app['trackName'] ?? '',
        'storeUrl' => $app['trackViewUrl'] ?? $storeUrl,
    ];
}

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

        /* Bootstrap-Icons 1.11 — the bi-* icon font (#1676)
           Previously had no registry entry at all: head-libs.php and
           manage/editor/index.php each hardcoded the URL + hash, and
           editor2.php / import2.php hardcoded the URL with NO hash. Registered
           here so ihymns_bootstrap_css_links() is the single emitter, exactly
           as `bootstrap` above already was for the framework itself. */
        'bootstrap_icons' => [
            'version'    => '1.11.3',
            'css_cdn'    => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
            'css_sri'    => 'sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+',
            'css_local'  => 'vendor/bootstrap-icons/bootstrap-icons.min.css',
        ],

        /* Font Awesome 6.7 — Icon library */
        'fontawesome' => [
            'version'    => '6.7.2',
            'css_cdn'    => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css',
            'css_sri'    => 'sha384-nRgPTkuX86pH8yjPJUAFuASXQSSl2/bBUiNV47vSYpKFxHJhbcrGnmlYpYJMeD7a',
            'css_local'  => 'vendor/fontawesome/css/all.min.css',
        ],

        /* SortableJS 1.15 — drag-and-drop reorder for the card-layout editor (#1647).
           Loaded lazily by js/modules/card-layout.js, not from a <script> tag, so
           the module carries its own copy of these values — tests/test-vendor-sri.js
           asserts the two agree, since "two lists that must match with nothing
           enforcing it" is how this codebase has broken before. */
        'sortablejs' => [
            'version'    => '1.15.2',
            'js_cdn'     => 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js',
            'js_sri'     => 'sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM',
            'js_local'   => 'vendor/sortablejs/Sortable.min.js',
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

        /* Fuse.js removed in WS-J #1020 — there is no client-side corpus or
           Fuse.js index any more; search is live MySQL FULLTEXT server-side
           (see js/modules/search.js). */

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

        /* Swagger UI 5.32 — renders /api-docs.yaml on /manage/api-docs (#1587)
         *
         * ELI5: this is the library that turns our API description file into a
         * browsable page with a "Try it out" button.
         *
         * The page previously loaded the floating `swagger-ui-dist@5` major tag
         * with no SRI, so jsDelivr could re-point it to a different build on any
         * 5.x release — inside an authenticated admin session whose Try-it-out
         * runner is aimed at the live API. Pinned + SRI + local fallback, same
         * shape as Bootstrap/jQuery above (#354 / #526).
         *
         * The hashes below were computed from the published npm tarball
         * (`swagger-ui-dist-5.32.11.tgz`), which is byte-for-byte what jsDelivr
         * serves for these exact filenames. If a hash ever fails to match, the
         * browser refuses the CDN copy and api-docs.php falls through to the
         * vendored `vendor/swagger-ui/*` files — a degraded source, never a
         * broken page.
         * https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity */
        'swaggerui' => [
            'version'    => '5.32.11',
            'css_cdn'    => 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.11/swagger-ui.css',
            'css_sri'    => 'sha384-9Q2fpS+xeS4ffJy6CagnwoUl+4ldAYhOs9pgZuEKxypVModhmZFzeMlvVsAjf7uT',
            'js_cdn'     => 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.11/swagger-ui-bundle.js',
            'js_sri'     => 'sha384-vfl/klfTFrIz5urj0HnhcXLAbzPdRHezizfy+XgFB6GqcKkhlk0lS3bIbyB39NLA',
            'preset_cdn' => 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.11/swagger-ui-standalone-preset.js',
            'preset_sri' => 'sha384-m05NHMTwYzsIxuzXMYDard06UtAxQkr+gZ7tf01TGlpECbjtRVz8HSkSMCBiwMQQ',
            'css_local'  => 'vendor/swagger-ui/swagger-ui.css',
            'js_local'   => 'vendor/swagger-ui/swagger-ui-bundle.js',
            'preset_local' => 'vendor/swagger-ui/swagger-ui-standalone-preset.js',
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

        /* NOTE: the vendored `qrcode-generator` library entry was REMOVED here
         * (owner directive 2026-08-05 — QR via CueRCode across iHymns). QR codes
         * are now generated by our CueRCode service (github.com/MWBMPartners/
         * CueRCode) server-side and served by the same-origin /qr.php endpoint;
         * no client-side QR library ships any more. See includes/cuercode_client.php,
         * qr.php, and .claude/qr-cuercode-integration-plan.md. */
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

        /* Matomo (self-hosted) — URL and site ID */
        'matomo_url'          => null,  /* e.g., 'https://analytics.example.com' */
        'matomo_site_id'      => null,  /* e.g., '1' */

        /* Fathom Analytics — site ID (privacy-focused) */
        'fathom_site_id'      => null,  /* e.g., 'ABCDEFGH' */

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
     * Cross-subdomain storage bridge (#133, #529).
     *
     * The bridge is an iframe-hosted helper page on the canonical
     * sync-domain that uses postMessage to relay localStorage reads /
     * writes between any *.ihymns.app subdomain. Defining the URL
     * once here means a staging / white-label / multi-tenant deploy
     * can override it without editing CSP `frame-src` and the
     * iHymnsConfig bridge URL in two places that drift.
     *
     * `url`    — full URL of the bridge HTML page; loaded in an
     *            iframe by js/modules/storage-bridge.js
     * `origin` — bare scheme+host portion used by index.php's CSP
     *            `frame-src` directive.
     * ----------------------------------------------------------------- */
    'storage_bridge' => [
        'url'    => 'https://sync.ihymns.app/bridge.html',
        'origin' => 'https://sync.ihymns.app',
    ],

    /* -----------------------------------------------------------------
     * Native app store URLs for PWA install banner redirection.
     * Set to null if no native app exists for that platform yet.
     *
     * #1403 — this constant is now only the FALLBACK. The primary,
     * admin-editable source is tblAppSettings (native_app_ios /
     * native_app_android / native_app_amazon, set on
     * /manage/configuration → "Native app stores"), read via
     * getAppSetting() in index.php. This constant still works for a
     * fresh/un-migrated install, or if an admin prefers to pin it in
     * code — either a bare ID/package/ASIN or a full store URL is
     * accepted by verifyAppStoreApp().
     * ----------------------------------------------------------------- */
    'native_apps' => [
        'ios'     => null,  /* e.g., 'https://apps.apple.com/app/ihymns/id1234567890' */
        'android' => null,  /* e.g., 'https://play.google.com/store/apps/details?id=ltd.mwbmpartners.ihymns' */
        'amazon'  => null,  /* e.g., 'https://www.amazon.com/dp/B0XXXXXXXX' (Fire OS) */
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
     * IP reputation lookup — proxy/VPN/datacentre/TOR classification
     *
     * Off by default — the activity-log resolver falls back to header-
     * only heuristics (Cloudflare CF-Connecting-IP, X-Forwarded-For
     * trust gate, etc.) until an external service is wired in.
     *
     * Supported providers (drop-in once their integration ships):
     *   - 'ipqs'    — IPQualityScore (https://www.ipqualityscore.com)
     *   - 'maxmind' — MaxMind GeoIP2 Anonymous IP
     *   - 'ipinfo'  — ipinfo.io
     *
     * Cache TTL controls how long a row in tblIpReputation is treated
     * as fresh; after expiry the resolver re-queries the provider.
     * 24h default keeps the lookup cost low while still catching
     * reasonably-quick IP reassignments on consumer ISPs.
     * ----------------------------------------------------------------- */
    'ip_reputation' => [
        'enabled'        => false,        /* Set to true once provider + key are configured */
        'provider'       => null,         /* 'ipqs' | 'maxmind' | 'ipinfo' | null */
        'api_key'        => null,         /* Provider API key (do NOT commit a real key — read from env in deployment) */
        'cache_ttl_secs' => 86400,        /* Cache rows in tblIpReputation for 24h before re-querying */
        'min_score'      => 75,           /* Provider score threshold (0-100) above which we flag as proxy/vpn */
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
 * Resolve the request Host against the canonical-channel allow-list.
 *
 * SECURITY (CWE-640 / Host-header injection): $_SERVER['HTTP_HOST'] is sent by
 * the client and is therefore attacker-controllable. Interpolating it into an
 * emailed auth link (password-reset / magic-link / verify), a canonical <link>
 * URL, or a server-side outbound probe lets an attacker poison those values —
 * e.g. submit a password reset for a victim with a crafted `Host:` header so the
 * victim receives a *legitimate* email whose VALID-token link points at the
 * attacker's domain (token theft on click). We allow only the four canonical
 * channels and fall back to the production domain otherwise. This is the single
 * source of truth that the sitemap allow-list (#526) and the email/canonical
 * builders all share — never read HTTP_HOST directly for these purposes.
 *
 * @return string A trusted host that is never attacker-controlled.
 */
function appCanonicalHost(): string
{
    static $allowed = ['ihymns.app', 'www.ihymns.app', 'dev.ihymns.app', 'beta.ihymns.app'];
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    return in_array($requestHost, $allowed, true) ? $requestHost : 'ihymns.app';
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
    /* SECURITY: allow-listed host, not raw HTTP_HOST — prevents canonical-URL
       / SEO cache poisoning via a forged Host header (#526 / security audit). */
    $host   = appCanonicalHost();

    if ($path === '') {
        $path = getRequestPath();
    }

    return $scheme . '://' . $host . $path;
}
