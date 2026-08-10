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

/* Themed error-page renderer — loaded BEFORE the bootstrap safety net below so
   it's available even if a later require fails. Self-contained, no deps. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'error_page.php';

/* =========================================================================
 * BOOTSTRAP SAFETY NET (#811)
 *
 * Catch any uncaught Throwable during request bootstrap and render a
 * friendly fallback page instead of a blank screen. Triggers when the
 * deployed code references schema (table / column) that the matching
 * migrate-*.php hasn't been applied for yet — typically the brief
 * window between a deploy landing and an admin running the migrations.
 *
 * Production: shows a generic "service starting" message.
 * Alpha / Beta: includes the exception text + the exact admin path
 *               to apply pending migrations.
 *
 * Register before any DB-touching require (config.php pulls APP_CONFIG
 * but doesn't connect; SongData / channel_gate do).
 * ========================================================================= */
set_exception_handler(function (\Throwable $e): void {
    error_log('[index.php bootstrap] uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' at ' . basename($e->getFile()) . ':' . $e->getLine());

    /* Pre-prod surfaces the exception + a setup link; production stays
       generic so a casual visitor isn't shown internals. */
    $serverPath = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $isPreProd  = str_contains($serverPath, 'public_html_dev')
               || str_contains($serverPath, 'public_html_beta');

    $opts = [
        'title'      => 'iHymns is starting up',
        'message'    => 'The service is initialising. Please retry in a moment.',
        'code'       => '',
        'retryAfter' => 30,
        'actions'    => [['label' => 'Try again', 'href' => '/', 'primary' => true]],
    ];
    if ($isPreProd) {
        $opts['detail']    = get_class($e) . ': ' . $e->getMessage();
        $opts['message']   = 'The service is initialising. Admins: open Setup database and apply any pending migrations.';
        $opts['actions'][] = ['label' => 'Setup database', 'href' => '/manage/setup-database'];
    }

    if (function_exists('renderErrorPage')) {
        renderErrorPage(503, $opts);          /* themed, theme-aware (no dark flash) */
    } else {
        /* error_page.php somehow unavailable — last-resort minimal fallback. */
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            header('Retry-After: 30');
            header('Cache-Control: no-store');
        }
        echo '<!DOCTYPE html><title>iHymns</title><p style="font-family:system-ui;padding:2rem">'
           . 'iHymns is starting up. Please retry in a moment.</p>';
    }
});

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
/* #1676 — ihymns_bootstrap_icons_css_link(), the shared emitter for the bi-* font. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap_assets.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'infoAppVer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
/* Mirror every uncaught \Throwable + PHP fatal into tblActivityLog so
   curators reading /manage/activity-log see every public-site error
   alongside admin events. Chains to the 503-emitting bootstrap
   safety net registered above. */
installGlobalActivityLogHandlers('index');

/* Channel gate (#407) — alpha / beta subdomains require the user to
   hold access_alpha / access_beta entitlements. Never gates production
   or /api / /manage / static assets (those paths never hit index.php
   thanks to the root .htaccess). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'channel_gate.php';
enforceChannelGate($app["Application"]["Version"]["Development"]["Status"] ?? null);

/* System maintenance mode (WS-K #1021) — admin-toggled in
   /manage/configuration. When on, the public SPA serves a branded
   maintenance landing page (503 + Retry-After) to everyone. /manage/* is a
   separate entry point (not routed through index.php), so admins keep access
   to turn it off. A returning PWA user's service worker treats the 503 like a
   network error and serves the cached shell, preserving their offline-capable
   experience; the app shows a maintenance banner from ?action=app_status. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
enforceMaintenanceForPublicSite();

/* =========================================================================
 * APPLICATION METADATA — accessed directly via $app array
 * ========================================================================= */

/* Native app store IDs (#1403) — admin-editable via /manage/configuration
   ("Native app stores" card → tblAppSettings: native_app_ios /
   native_app_android / native_app_amazon), falling back to the
   APP_CONFIG['native_apps'] code-constant when the admin hasn't set a
   value (keeps a fresh/un-migrated install working). getAppSetting() is
   DB-safe (returns the default on any error), so this can never throw;
   ihymnsResolveNativeAppSetting() is the pure precedence function both
   this and tests/php/test-native-app-stores.php exercise. */
$nativeAppsFallback  = APP_CONFIG['native_apps'];
$nativeAppIosVal     = ihymnsResolveNativeAppSetting(getAppSetting('native_app_ios', ''), $nativeAppsFallback['ios'] ?? null);
$nativeAppAndroidVal = ihymnsResolveNativeAppSetting(getAppSetting('native_app_android', ''), $nativeAppsFallback['android'] ?? null);
$nativeAppAmazonVal  = ihymnsResolveNativeAppSetting(getAppSetting('native_app_amazon', ''), $nativeAppsFallback['amazon'] ?? null);

/* Verify native app availability (cached, 24h TTL) */
$iosApp     = verifyAppStoreApp('ios', $nativeAppIosVal);
$androidApp = verifyAppStoreApp('android', $nativeAppAndroidVal);
$amazonApp  = verifyAppStoreApp('amazon', $nativeAppAmazonVal);

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

/**
 * Environment-badge colour (#1583).
 * ELI5: Alpha's badge stays yellow; Beta's badge is now blue, so the two
 * pre-production channels are told apart at a glance instead of both
 * wearing the same "caution" colour.
 * DETAIL: `Development.Status` (set in includes/infoAppVer.php from the
 * deploy-time SFTP path / `.env-channel` fallback) is one of exactly
 * `'Alpha' | 'Beta' | null` — see that file's `match(true)` arms — so a
 * simple two-way branch is exhaustive; `null` never reaches this line
 * because the badge markup below is itself wrapped in `if (Status)`.
 * Bootstrap 5.3 theme-aware utility classes only (no bespoke CSS), same
 * convention as the existing `bg-warning text-dark`.
 * https://getbootstrap.com/docs/5.3/utilities/colors/
 */
$envBadgeClass = ($app["Application"]["Version"]["Development"]["Status"] === 'Beta')
    ? 'bg-info text-dark'
    : 'bg-warning text-dark';

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
    /* appleid.cdn-apple.com serves the "Sign in with Apple JS" SDK for web SIWA (#1470 W2,
       #1484). The popup is a window.open to appleid.apple.com (not an iframe → no frame-src),
       and the token exchange is server-side (no connect-src needed). Harmless while web SIWA
       is dormant; required the moment an admin enables it. */
    "script-src 'self' 'nonce-{$cspNonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.googletagmanager.com https://plausible.io https://www.clarity.ms https://cdn.usefathom.com https://appleid.cdn-apple.com{$cspMatomoUrl}",
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
    "img-src 'self' data: https:",
    "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
    "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.google-analytics.com https://plausible.io https://www.clarity.ms https://*.usefathom.com{$cspMatomoUrl}",
    "frame-src 'self' " . APP_CONFIG['storage_bridge']['origin'] . " https://*.ihymns.app",
    "worker-src 'self' https://cdn.jsdelivr.net blob:",
    "manifest-src 'self'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'self'",
    "upgrade-insecure-requests",
];

header("Content-Security-Policy: " . implode('; ', $cspDirectives));

/* #1206 — declare the page's primary (UI) language. The actual content languages
   on a song page are carried per-component via lang/dir tags (#858/#1205); this
   header + <html lang> stay the UI language by design. */
header('Content-Language: ' . $locale);

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

/* #1206 — hreflang alternates for a song's translation cluster. Filled in the
   song route below (empty for every other page); emitted in <head>. */
$hreflangLinks    = [];
$hreflangSongLang = '';

/* Detect specific page routes for customised OG tags */
try {
    $songData = new SongData();

    /* Song page: /song/CP-0001 OR /song/<PublicId> (#1343-B — widen-only; getSongById resolves either). */
    if (preg_match('#^/song/([A-Za-z0-9_-]{1,32})$#', $requestPath, $matches)) {
        $ogSong = $songData->getSongById($matches[1]);
        if ($ogSong !== null) {
            $pageType = 'song';

            /* #1343-B — canonical/og:url is the opaque PublicId permalink (the
               stable form), even when a crawler hit a legacy /song/<SongId>. */
            if (!empty($ogSong['publicId'])) {
                $canonicalUrl = getCanonicalUrl('/song/' . rawurlencode((string)$ogSong['publicId']));
            }

            /* #1206 — translation-cluster hreflang alternates (SEO: marks these
               as language variants of one work). Only when the song has
               other-language versions. Self + each alternate (+ x-default in the
               head). Shares SongData::getSongTranslations() with the picker. */
            $hreflangSongLang = trim((string)($ogSong['language'] ?? ''));
            $_cluster = $songData->getSongTranslations($matches[1]);
            if (!empty($_cluster)) {
                $_seen = [];
                if ($hreflangSongLang !== '') {
                    $hreflangLinks[$hreflangSongLang] = $canonicalUrl;   // self
                    $_seen[strtolower($hreflangSongLang)] = true;
                }
                foreach ($_cluster as $_c) {
                    $_lang = trim((string)($_c['target_language'] ?? ''));
                    $_sid  = (string)($_c['song_id'] ?? '');
                    if ($_lang === '' || $_sid === '' || isset($_seen[strtolower($_lang)])) { continue; }
                    $hreflangLinks[$_lang] = getCanonicalUrl('/song/' . rawurlencode($_sid));
                    $_seen[strtolower($_lang)] = true;
                }
            }
            /* #85 — a song with no number must NOT read "#0" in the share title:
               (int)null === 0, so only append "#N" when there is a real number. */
            $_ogNum  = (int)($ogSong['number'] ?? 0);
            $ogTitle = htmlspecialchars($ogSong['title']) . ' — '
                     . htmlspecialchars($ogSong['songbookName'])
                     . ($_ogNum > 0 ? ' #' . $_ogNum : '');
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

            /* JSON-LD: MusicComposition. #858 — inLanguage is the
               union of the song's primary language and every per-
               component override, so a Spanish-chorus / English-verse
               medley is correctly indexed as multilingual. Falls back
               to a single-string $locale when no per-component data
               is attached (pre-migration / no overrides). */
            $jsonLdLanguages = [];
            if (!empty($ogSong['language'])) {
                $jsonLdLanguages[] = (string)$ogSong['language'];
            } elseif ($locale !== '') {
                $jsonLdLanguages[] = $locale;
            }
            foreach (($ogSong['components'] ?? []) as $cmp) {
                $cl = trim((string)($cmp['language'] ?? ''));
                if ($cl !== '' && !in_array($cl, $jsonLdLanguages, true)) {
                    $jsonLdLanguages[] = $cl;
                }
            }
            $musicComposition = [
                '@context' => 'https://schema.org',
                '@type'    => 'MusicComposition',
                'name'     => $ogSong['title'],
                /* Single-value when only one language; array when the
                   song carries per-component overrides. Both shapes
                   are valid schema.org per the Web Schemas
                   recommendation. */
                'inLanguage' => count($jsonLdLanguages) > 1
                    ? $jsonLdLanguages
                    : ($jsonLdLanguages[0] ?? $locale),
            ];
            /* #832 — alternateName for SEO. Search engines pick this up
               so a query for the original title / common misspelling /
               vernacular name still surfaces this song. Empty array on
               pre-migration deployments — key omitted in that case so
               the JSON-LD stays clean. */
            if (!empty($ogSong['alternativeTitles'])) {
                $musicComposition['alternateName'] = array_values(array_map(
                    static fn(array $a): string => (string)($a['title'] ?? ''),
                    array_filter(
                        $ogSong['alternativeTitles'],
                        static fn($a) => is_array($a) && !empty($a['title'])
                    )
                ));
            }
            /* #833 — sameAs for SEO. Search engines pick up the
               external-link URLs as authority signals; e.g. a
               linked Hymnary.org page reinforces the song identity. */
            if (!empty($ogSong['links']) && is_array($ogSong['links'])) {
                $sameAs = array_values(array_filter(array_map(
                    static fn($l) => is_array($l) ? (string)($l['url'] ?? '') : '',
                    $ogSong['links']
                )));
                if (!empty($sameAs)) {
                    $musicComposition['sameAs'] = $sameAs;
                }
            }
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
            /* #1750 — the five #1741 P1 song-identity fields, each gated on
               non-empty. ELI5: teaches search engines the subtitle,
               disambiguation, first-published year and copyright holder of
               a hymn, the same way the header/footer now show them to a
               human reader. DETAILED: $ogSong comes straight from
               getSongById() (SongData::_fetchSongRow()), so §1's
               always-present shape-blind keys land here automatically —
               no separate query needed. This array is emitted later by the
               shell's existing nonce'd <script type="application/ld+json">
               loop (no new <script>, no CSP concern — rule #30 n/a, this
               is the shell, not a shared-cache fragment). */
            if (!empty($ogSong['subtitle'])) {
                $musicComposition['alternativeHeadline'] = (string)$ogSong['subtitle'];
            }
            if (!empty($ogSong['disambiguation'])) {
                $musicComposition['disambiguatingDescription'] = (string)$ogSong['disambiguation'];
            }
            if (!empty($ogSong['firstPublishedYear'])) {
                /* Year-precision ISO-8601 is valid schema.org Date. */
                $musicComposition['datePublished'] = (string)(int)$ogSong['firstPublishedYear'];
            }
            if (!empty($ogSong['copyrightHolder'])) {
                $musicComposition['copyrightHolder'] = ['@type' => 'Organization', 'name' => (string)$ogSong['copyrightHolder']];
            }
            if (!empty($ogSong['copyrightYears']) && preg_match('/^\d{4}$/', trim((string)$ogSong['copyrightYears']))) {
                /* schema.org copyrightYear is a Number — emit ONLY when the
                   free-text field is a single plain year; multi-year strings
                   ("1978, 1987") fold into copyrightNotice below instead. */
                $musicComposition['copyrightYear'] = (int)trim((string)$ogSong['copyrightYears']);
            }
            $ldCopyright = trim(trim((string)($ogSong['copyrightYears'] ?? '')) . ' ' . trim((string)($ogSong['copyrightHolder'] ?? '')));
            if ($ldCopyright === '') { $ldCopyright = trim((string)($ogSong['copyright'] ?? '')); }
            if ($ldCopyright !== '') {
                $musicComposition['copyrightNotice'] = '© ' . $ldCopyright;
            }
            $jsonLdScripts[] = $musicComposition;

            /* Breadcrumb: Home > Songbooks > Songbook Name > #N */
            $songbookId = $ogSong['songbook'] ?? '';
            $breadcrumbItems = [
                ['name' => 'Home',      'url' => getCanonicalUrl('/')],
                ['name' => 'Songbooks', 'url' => getCanonicalUrl('/songbooks')],
                ['name' => $ogSong['songbookName'], 'url' => getCanonicalUrl('/songbook/' . $songbookId)],
                /* #85 — leaf crumb: the song number, or its title when it has none (never "#0"). */
                ['name' => ((int)($ogSong['number'] ?? 0) > 0 ? '#' . (int)$ogSong['number'] : (string)($ogSong['title'] ?? '')), 'url' => $canonicalUrl],
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
            /* #832 — append "Also known as: …" to social previews when
               alt names are present, so a Twitter/Facebook share card
               surfaces vernacular names too. Capped at 3 alts to keep
               the OG description from blowing past the ~200 char
               threshold platforms truncate at. */
            if (!empty($ogBook['alternativeNames'])) {
                $altSlice = array_slice($ogBook['alternativeNames'], 0, 3);
                $altText  = implode(', ', array_map(
                    static fn(array $a): string => (string)($a['title'] ?? ''),
                    $altSlice
                ));
                if ($altText !== '') {
                    $ogDescription .= '. Also known as: ' . $altText;
                }
            }
            $ogImage = getCanonicalUrl('/og-image?songbook=' . urlencode($matches[1]));
            $ogImageAlt = $ogBook['name'] . ' songbook on ' . $app["Application"]["Name"];

            /* Breadcrumb: Home > Songbooks > Songbook Name */
            $breadcrumbItems = [
                ['name' => 'Home',      'url' => getCanonicalUrl('/')],
                ['name' => 'Songbooks', 'url' => getCanonicalUrl('/songbooks')],
                ['name' => $ogBook['name'], 'url' => $canonicalUrl],
            ];

            /* JSON-LD: MusicAlbum (#832 — alternateName, #833 — sameAs for SEO). */
            $musicAlbum = [
                '@context'  => 'https://schema.org',
                '@type'     => 'MusicAlbum',
                'name'      => $ogBook['name'],
                'numTracks' => (int)($ogBook['songCount'] ?? 0),
            ];
            if (!empty($ogBook['alternativeNames'])) {
                $musicAlbum['alternateName'] = array_values(array_map(
                    static fn(array $a): string => (string)($a['title'] ?? ''),
                    array_filter(
                        $ogBook['alternativeNames'],
                        static fn($a) => is_array($a) && !empty($a['title'])
                    )
                ));
            }
            if (!empty($ogBook['links']) && is_array($ogBook['links'])) {
                $albumSameAs = array_values(array_filter(array_map(
                    static fn($l) => is_array($l) ? (string)($l['url'] ?? '') : '',
                    $ogBook['links']
                )));
                if (!empty($albumSameAs)) {
                    $musicAlbum['sameAs'] = $albumSameAs;
                }
            }
            $jsonLdScripts[] = $musicAlbum;
        }
    }
    /* #1741 P4a-3 (owner decision D4) — /writer/<name-slug> is retired in favour
       of /musician/<registry-slug>. A resolvable slug gets a REAL 301 (mirrors
       the /people|/person 301 below) so crawlers + old external links converge
       on one canonical URL. An unresolvable slug falls through to the SPA shell
       — the fragment renders the name-based fallback, so no /writer/ URL that
       used to answer ever dies (rule #33). NOTE the pattern: ([^/]+) +
       rawurldecode, NOT the person branch's [a-z0-9\-]+ — historic writer slugs
       carry dots and percent-encoded bytes (sitemap fold, pre-D4).
       @link .claude/catalogue-1741-P4a3-plan.md §1.1 leg A / §2 A1-A2/A11-A12 */
    elseif (preg_match('#^/writer/([^/]+)$#', $requestPath, $matches)) {
        $pageType = 'other';
        try {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
            $writerResolved = musicianResolveLegacySlugDb(getDbMysqli(), rawurldecode($matches[1]));
        } catch (\Throwable $_e) {
            $writerResolved = null;   /* fail-open: serve the shell, never a 500 */
        }
        if ($writerResolved !== null) {
            header('Location: ' . getCanonicalUrl('/musician/' . rawurlencode($writerResolved)), true, 301);
            exit;
        }
    }
    /* #1741 P2-B — /people/<slug> and /person/<slug> are LEGACY path
       prefixes; the canonical route is now /musician/<slug>. Issue a real
       301 for any non-fragment (i.e. a genuine top-level HTTP request —
       this file is never reached by the SPA's internal `/api?page=...`
       fragment fetches, only by a fresh navigation / crawler / shared
       link) load of either old prefix, so search engines converge on ONE
       indexed URL and a stale bookmark/shared-link still lands on real
       content. This upgrades the #1453 soft "advertise one spelling via
       <link rel=canonical>" approach (which served both spellings 200
       with the plural form merely PREFERRED) to an actual redirect — the
       client-side SPA router (`js/modules/router.js`) still accepts all
       three prefixes for same-document navigation, which never re-hits
       this file. Kept as its own preg_match (not folded into the
       /musician/ match below) so the redirect is unconditional and
       doesn't need a DB round-trip just to bounce the request onward. */
    elseif (preg_match('#^/(?:people|person)/([a-z0-9\-]+)$#', $requestPath, $matches)) {
        header('Location: ' . getCanonicalUrl('/musician/' . rawurlencode($matches[1])), true, 301);
        exit;
    }
    /* Musician page: /musician/<slug> (#833 — sameAs JSON-LD; renamed
       from the Credit-Person "Person page" by #1741 P2-B). The Apple
       app's AASA + Universal Links claim BOTH the legacy `/person/*`
       component (kept indefinitely — shipped client contract,
       `.well-known/apple-app-site-association.php`) and the new
       canonical `/musician/*`. */
    elseif (preg_match('#^/musician/([a-z0-9\-]+)$#', $requestPath, $matches)) {
        $pageType = 'other';
        $personSlug = $matches[1];
        /* #1754 — ELI5: before we look this slug up, first ask "is there a
           MORE canonical spelling of this slug?" — so a crawler hitting a
           name-slug or alias URL (e.g. /musician/fanny-crosby when the
           registry slug is frances-jane-van-alstyne, or any song.php-emitted
           credit-name slug) still gets real JSON-LD instead of none.
           DETAILED / WHY: resolve name-slug / legacy-slug input through the
           ONE ladder (rule #22) BEFORE the exact lookup below. Fail-open: a
           null answer leaves the slug as arrived and the branch behaves
           exactly as pre-#1754. No rawurldecode needed — this route's
           charset is [a-z0-9-] (unlike /writer/'s, :484). NOTE $canonicalUrl
           is recomputed AFTER resolution so <link rel=canonical> / og:url
           agree with the fragment's data-musician-canonical marker — both
           now derive from the same resolver (rule #35). No 301 here
           (deliberate — leg C of the P4a-3 design: /musician/ soft-
           canonicalises via the fragment's data-musician-canonical +
           history.replaceState; only /writer/ hard-301s, :484). The
           driver itself never throws (musician_helpers.php:1103-1105); the
           try/catch below guards getDbMysqli() only.
           @see #1754 */
        try {
            if (!function_exists('musicianResolveLegacySlugDb')) {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
            }
            $musicianResolved = musicianResolveLegacySlugDb(getDbMysqli(), $personSlug);
            if ($musicianResolved !== null) { $personSlug = $musicianResolved; }
        } catch (\Throwable $_e) { /* getDbMysqli() outage — serve the shell, never a 500 */ }
        $canonicalUrl = getCanonicalUrl('/musician/' . rawurlencode($personSlug));
        try {
            $personDb = getDbMysqli();
            $stmt = $personDb->prepare(
                'SELECT Id, Name, Slug FROM tblMusicians WHERE Slug = ? LIMIT 1'
            );
            $stmt->bind_param('s', $personSlug);
            $stmt->execute();
            $personRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($personRow) {
                $personJsonLd = [
                    '@context' => 'https://schema.org',
                    '@type'    => 'Person',
                    'name'     => (string)$personRow['Name'],
                ];
                /* Check both new and legacy link tables — same fallback
                   as the public page. */
                $personLinks = [];
                $r = $personDb->query(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicianExternalLinks' LIMIT 1"
                );
                $hasNewPersonLinks = $r && $r->fetch_row() !== null;
                if ($r) $r->close();
                if ($hasNewPersonLinks) {
                    $stmt = $personDb->prepare(
                        'SELECT Url FROM tblMusicianExternalLinks WHERE MusicianId = ?'
                    );
                    $pid = (int)$personRow['Id'];
                    $stmt->bind_param('i', $pid);
                    $stmt->execute();
                    $personLinks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                }
                if (empty($personLinks)) {
                    try {
                        $stmt = $personDb->prepare(
                            'SELECT Url FROM tblMusicianLinks WHERE MusicianId = ?'
                        );
                        $pid = (int)$personRow['Id'];
                        $stmt->bind_param('i', $pid);
                        $stmt->execute();
                        $personLinks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                    } catch (\Throwable $_e) { /* legacy table missing */ }
                }
                if (!empty($personLinks)) {
                    $personSameAs = array_values(array_filter(array_map(
                        static fn($l) => (string)($l['Url'] ?? $l['url'] ?? ''),
                        $personLinks
                    )));
                    if (!empty($personSameAs)) {
                        $personJsonLd['sameAs'] = $personSameAs;
                    }
                }
                $jsonLdScripts[] = $personJsonLd;
            }
        } catch (\Throwable $_e) { /* table missing — no JSON-LD */ }
    }
    /* Work page: /work/<slug> (#840 — sameAs JSON-LD + breadcrumb) */
    elseif (preg_match('#^/work/([a-z0-9\-]+)$#', $requestPath, $matches)) {
        $pageType = 'other';
        $workSlug = $matches[1];
        try {
            $workDb = getDbMysqli();
            /* Probe tblWorks; pre-migration deployments skip silently. */
            $r = $workDb->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks' LIMIT 1"
            );
            $hasWorksTable = $r && $r->fetch_row() !== null;
            if ($r) $r->close();
            if ($hasWorksTable) {
                $stmt = $workDb->prepare(
                    'SELECT Id, Title, Slug, Iswc FROM tblWorks WHERE Slug = ? LIMIT 1'
                );
                $stmt->bind_param('s', $workSlug);
                $stmt->execute();
                $workRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($workRow) {
                    $ogTitle       = htmlspecialchars((string)$workRow['Title']) . ' — Work — ' . $app["Application"]["Name"];
                    $ogDescription = 'Work record on ' . $app["Application"]["Name"]
                                   . (!empty($workRow['Iswc']) ? ' — ISWC ' . htmlspecialchars((string)$workRow['Iswc']) : '');
                    $breadcrumbItems = [
                        ['name' => 'Home',  'url' => getCanonicalUrl('/')],
                        ['name' => 'Works', 'url' => getCanonicalUrl('/works')],
                        ['name' => (string)$workRow['Title'], 'url' => $canonicalUrl],
                    ];
                    $workJsonLd = [
                        '@context' => 'https://schema.org',
                        '@type'    => 'MusicComposition',
                        'name'     => (string)$workRow['Title'],
                    ];
                    if (!empty($workRow['Iswc'])) {
                        $workJsonLd['iswcCode'] = (string)$workRow['Iswc'];
                    }
                    $hasWorkLinks = false;
                    $r2 = $workDb->query(
                        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorkExternalLinks' LIMIT 1"
                    );
                    $hasWorkLinks = $r2 && $r2->fetch_row() !== null;
                    if ($r2) $r2->close();
                    if ($hasWorkLinks) {
                        $stmt = $workDb->prepare(
                            'SELECT Url FROM tblWorkExternalLinks WHERE WorkId = ?'
                        );
                        $wid = (int)$workRow['Id'];
                        $stmt->bind_param('i', $wid);
                        $stmt->execute();
                        $linkRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        $sameAs = array_values(array_filter(array_map(
                            static fn($l) => (string)($l['Url'] ?? ''),
                            $linkRows
                        )));
                        if (!empty($sameAs)) {
                            $workJsonLd['sameAs'] = $sameAs;
                        }
                    }
                    $jsonLdScripts[] = $workJsonLd;
                }
            }
        } catch (\Throwable $_e) { /* schema missing — no JSON-LD */ }
    }
    /* Shared setlist page: /setlist/shared/abc123 — #1791 widened the id class
       from `[a-f0-9]+` to the shared-fold grammar `[A-Za-z0-9_-]{6,64}` (legacy
       8-hex AND base64url capability tokens; the authoritative fold is
       sharedSetlistSafeShareId(), which resolveWire re-validates below). */
    elseif (preg_match('#^/setlist/shared/([A-Za-z0-9_-]{6,64})$#', $requestPath, $matches)) {
        $pageType = 'other';
        $shareId = $matches[1];
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SharedSetlist.php';
        /* #1380 / FIX 5 — resolve through the SHARED live-vs-snapshot resolver so the
           OG/meta tags reflect the owner's CURRENT setlist (name + count) on a live
           share, not the frozen snapshot. `unavailable` (a live link the owner has
           since deleted) and null (no such share) both leave the static defaults in
           place — there's no live preview to advertise for a no-longer-shared link. */
        $shareData = sharedSetlistResolveWire(getDbMysqli(), $shareId);
        if (is_array($shareData) && empty($shareData['unavailable'])) {
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
} catch (\Throwable $e) {
    /* If song data isn't available, use defaults — no fatal error.
       Widened from \RuntimeException → \Throwable (#811) so a fresh
       deploy that lands new-schema code before its migrations have
       run still renders the page; the metadata block falls back to
       the static defaults rather than blanking the site. */
    error_log('[index.php] OG/route detection failed: ' . $e->getMessage());
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
<?php /* #1206 — hreflang alternates: language variants of this song (only when a
         translation cluster exists). x-default points at the current page. */ ?>
<?php foreach ($hreflangLinks as $_hl => $_hurl): ?>
    <link rel="alternate" hreflang="<?= htmlspecialchars($_hl, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($_hurl, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
<?php if (!empty($hreflangLinks)): ?>
    <link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>

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
    <!-- Amazon Appstore (Fire OS, #1403) has no standard smart-banner meta tag —
         it's covered by the native-app banner in pwa.js + the manifest, not here. -->
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

    <!-- Bootstrap Icons (#1347) — the external-link registry (tblExternalLinkTypes.IconClass)
         seeds bi-* classes (bi-spotify / bi-youtube / bi-instagram / …). The public app
         otherwise only loads Font Awesome, so those provider icons rendered blank on the
         song / person / work external-link buttons. CDN (jsdelivr is in the CSP style-src
         + font-src); decorative, so it degrades to text-only labels if the CDN is offline.
         #1676 — was the ONE external load in this shell not sourced from APP_CONFIG,
         and the only one with no `integrity`. Its neighbours (Font Awesome above,
         Animate.css below) were already pinned + hashed + fallback-backed, which is
         exactly why nobody spotted this line: it sits in a block that looks handled.
         Now emitted by the shared helper, same registry, same onerror fallback. -->
    <?= ihymns_bootstrap_icons_css_link() ?>

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
    <?php /* SECURITY: JSON_HEX_TAG|_AMP|_APOS|_QUOT so a DB song/songbook/person name
             containing a literal </script> (or &, ", ') cannot break out of this
             <script> element and inject HTML to every visitor (public stored XSS).
             JSON_HEX_TAG escapes < and > to </>. See security audit. */ ?>
    <script type="application/ld+json" nonce="<?= $cspNonce ?>"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endforeach; ?>
</head>

<body class="d-flex flex-column min-vh-100">
<?php if (!empty($GLOBALS['_ihymnsMaintenanceBypass'])): /* #1233 — admin is bypassing maintenance; make that obvious. Self-contained styles (no icon/CSS deps). */ ?>
    <?php /* #1308 — was position:sticky;z-index:1080, which painted OVER the
       position:fixed .app-header (z-1030) and hid the nav brand / profile
       controls. Now position:fixed at the very top, and the nonce'd script
       below pushes BOTH the fixed header (.app-header top) and the scrollable
       content (.main-content padding-top) down by the banner's MEASURED height
       so nothing is occluded. Height is measured (not hard-coded) because the
       message wraps to 2–3 lines on narrow viewports; re-measured on resize.
       The PWA install banner is suppressed during maintenance (pwa.js #1301),
       so the single-banner layout is the only case to handle here. */ ?>
    <div id="ihymns-maint-bypass" role="status" style="position:fixed;top:0;left:0;right:0;z-index:1040;background:#ffc107;color:#000;text-align:center;padding:calc(0.5rem + env(safe-area-inset-top, 0px)) max(1rem, env(safe-area-inset-right, 0px)) 0.5rem max(1rem, env(safe-area-inset-left, 0px));font-size:0.85rem;line-height:1.3;"><!-- #1279: pad past the iOS safe area (Dynamic Island / clock) so the text isn't occluded; applyMaintBypassOffset() measures offsetHeight, so the header/content offset follows automatically -->
        🔧 <strong>Maintenance mode is ON</strong> for this environment — visitors see a maintenance page; you have admin access.
        <a href="/manage/configuration" style="color:#000;text-decoration:underline;font-weight:600;">Turn it off</a>.
    </div>
    <script nonce="<?= $cspNonce ?>">
    (function () {
        function applyMaintBypassOffset() {
            var bar = document.getElementById('ihymns-maint-bypass');
            if (!bar) return;
            var h = bar.offsetHeight;                 /* live height incl. wrapped lines */
            var hdr = document.querySelector('.app-header');
            if (hdr) {
                hdr.style.top = h + 'px';             /* push fixed header below the banner */
                /* #1279 review — h already includes the iOS safe-area inset (the banner's
                   own padding-top), and in a standalone PWA .app-header ALSO adds
                   padding-top:env(safe-area-inset-top). Zero it here so the inset isn't
                   counted twice (a visible gap above the navbar during maintenance bypass). */
                hdr.style.paddingTop = '0px';
            }
            var main = document.querySelector('.main-content');
            if (main) { main.style.paddingTop = 'calc(var(--header-height) + 8px + ' + h + 'px)'; }
        }
        /* .app-header / .main-content are emitted AFTER this script, so wait for
           the DOM before measuring; also re-run on resize (width → wrap → height). */
        if (document.readyState !== 'loading') { applyMaintBypassOffset(); }
        else { document.addEventListener('DOMContentLoaded', applyMaintBypassOffset); }
        window.addEventListener('resize', applyMaintBypassOffset);
    })();
    </script>
<?php endif; ?>
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
                            <!-- Environment badge (#812) — must remain visible at every
                                 viewport so functional differences between Alpha/Beta and
                                 production stay obvious. Uses the dedicated `env-badge`
                                 class (admin.css / app.css) for stable padding instead of
                                 Bootstrap's `small` modifier which collapsed awkwardly.
                                 Colour now split Alpha=warning/Beta=info ($envBadgeClass,
                                 #1583) so the two pre-production channels read apart at a
                                 glance; production still renders no badge at all (the `if`
                                 above). This <span> deliberately stays inside the
                                 `dropdown-toggle` <button> rather than becoming its own
                                 <a> — nesting an interactive element inside another is an
                                 accessibility violation (WCAG 4.1.2), so the "What's new"
                                 link lives as its own dropdown item below instead. -->
                            <span class="badge env-badge <?= $envBadgeClass ?> ms-1"><?= htmlspecialchars($app["Application"]["Version"]["Development"]["Status"]) ?></span>
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

                        <!-- Single "Manage" entry — opens /manage/ which
                             shows per-entitlement cards for every
                             curator and administration surface. Visible
                             to any signed-in user with at least one
                             management entitlement (toggled by
                             user-auth.js). Sits in the same operations
                             group as Statistics (#582). -->
                        <li id="nav-manage-li" class="d-none">
                            <a class="dropdown-item" href="/manage/">
                                <i class="fa-solid fa-gears me-2" aria-hidden="true"></i> Manage
                            </a>
                        </li>

                        <!-- Help moved to the bottom (#582) — support
                             content is the natural last section in the
                             menu, separated from operations by its own
                             divider. -->
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/help" data-navigate="help">
                            <i class="fa-solid fa-circle-question me-2" aria-hidden="true"></i> Help
                        </a></li>
                        <!-- What's New (#1583) — a deploy-time CHANGELOG.md
                             excerpt (includes/pages/whats-new.php). Sits
                             beside Help in the same support group rather
                             than as a tap target on the env badge above,
                             because a <span> nested inside the
                             dropdown-toggle <button> can't also be an <a>
                             without nesting two interactive elements
                             (WCAG 4.1.2) — this dropdown item is the
                             tap-through instead. -->
                        <li><a class="dropdown-item" href="/whats-new" data-navigate="whats-new">
                            <i class="fa-solid fa-bullhorn me-2" aria-hidden="true"></i> What's New
                        </a></li>
                    </ul>
                </div>

                <!-- Right-side header actions -->
                <div class="d-flex align-items-center gap-2">
                    <!-- Search toggle button removed (#812) — the bottom
                         footer-nav already exposes a "Search" entry that
                         navigates to /search; one affordance is enough,
                         and the saved real-estate matters most on mobile
                         portrait. -->

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
                             role="group"
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

        <!-- Collapsible header search bar removed (#812) alongside its
             toggle button. The dedicated /search page reached via the
             footer-nav handles the use case. The autocomplete + Fuse
             index logic in js/modules/search.js degrades gracefully
             when neither element is present. -->
    </header>

    <!-- ================================================================
         MAIN CONTENT AREA — Dynamic content loaded via AJAX.
         Padding accounts for fixed header and footer.
         ================================================================ -->
    <!-- ⚠️ DO NOT re-add aria-live / aria-atomic here (#1645).
         It looks like an accessibility feature and is the opposite of one.

         Every page fragment is injected INSIDE this element, so marking it a
         live region queued the entire new page — breadcrumb, toolbar and every
         lyric line — for announcement on every navigation. aria-live is for
         status messages: short, specific, "your changes were saved". Announcing
         a whole page announces nothing useful, loudly.

         It also collided with the focus move router.js performs onto this same
         element, giving screen-reader users two competing streams of speech to
         silence on every single navigation.

         The replacement is js/utils/announce.js — one small dedicated region
         that says WHAT CHANGED ("Amazing Grace") in one line. router.js calls
         it after the focus move. tabindex="-1" stays: it is what makes that
         focus move possible, and it is the correct SPA route-change mechanism.
         Guarded by tests/test-live-region.js. -->
    <main id="main-content"
          class="main-content flex-grow-1"
          role="main"
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
        <div class="footer-info" role="group" aria-label="Application information">
            <small>
                <?= $app["Application"]["Copyright"]["Full"] ?>
                &nbsp;|&nbsp;
                <?php
                    /* WS-J #1020: the "● json" data-source indicator is gone —
                       there is no JSON fallback any more (DB-direct only), so
                       it could never light up. #1583: the version text is now
                       a tap-through to /whats-new (same `data-navigate`
                       convention as Terms/Privacy below) — it's the one
                       version indicator every visitor already looks at, so
                       it doubles as the What's New affordance instead of
                       adding a separate footer link. */
                ?>
                <a href="/whats-new" data-navigate="whats-new" class="footer-link" aria-label="What's new — v<?= htmlspecialchars($versionDisplay) ?>">v<?= htmlspecialchars($versionDisplay) ?></a>
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
    <div class="modal fade" id="numpad-modal" tabindex="-1" role="dialog" aria-labelledby="numpad-modal-label" aria-hidden="true">
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
    <div class="modal fade" id="shuffle-modal" tabindex="-1" role="dialog" aria-labelledby="shuffle-modal-label" aria-hidden="true">
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
         role="dialog"
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
    <div class="modal fade" id="share-modal" tabindex="-1" role="dialog" aria-labelledby="share-modal-label" aria-hidden="true">
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
         echo json_encode() call, which meant any throw inside one
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
            /* Slim catalogue index for offline browse/search + the offline-
               download enumerator (WS-I #1017) — replaces the ~5.7 MB
               whole-song corpus. Online reads are always the live API. */
            'dataUrl'         => '/api?action=songs_index',
            'nativeApps'      => [
                /* Always a canonical, template-built store URL (see
                   ihymnsAppStoreCanonicalUrl() in includes/config.php) —
                   never the admin's raw pasted string — so pwa.js can put
                   this straight into a link href with no further parsing. */
                'ios'             => $iosApp['verified'] ? ($iosApp['storeUrl'] ?? null) : null,
                'iosVerified'     => $iosApp['verified'],
                'android'         => $androidApp['verified'] ? ($androidApp['storeUrl'] ?? null) : null,
                'androidVerified' => $androidApp['verified'],
                'amazon'          => $amazonApp['verified'] ? ($amazonApp['storeUrl'] ?? null) : null,
                'amazonVerified'  => $amazonApp['verified'],
            ],
            'features'        => APP_CONFIG['features'],
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
            'storageBridgeUrl'=> APP_CONFIG['storage_bridge']['url'],
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
        /* SECURITY: JSON_HEX_TAG|_AMP|_APOS|_QUOT — $iHymnsConfig carries DB
           songbook names; a name containing </script> must not break out of the
           inline <script> below (public stored XSS). JSON_HEX_TAG escapes </>. */
        $iHymnsConfigJson = json_encode($iHymnsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($iHymnsConfigJson === false) {
            error_log('[index.php] json_encode($iHymnsConfig) failed: '
                . json_last_error_msg());
            $iHymnsConfigJson = json_encode([
                'appName'    => 'iHymns',
                'apiUrl'     => '/api',
                'dataUrl'    => '/api?action=songs_index',
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
