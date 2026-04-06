/**
 * iHymns — Service Worker
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Provides offline functionality for the iHymns PWA by caching
 * essential local resources. CDN resources are NOT cached by the
 * service worker (they have their own CDN caching).
 *
 * STRATEGY:
 * - Local static assets: cache-first (fast offline access)
 * - Song data (songs.json): network-first (get latest, fallback to cache)
 * - CDN resources: network-only (never cache — avoids poisoned cache)
 * - Navigation requests: fallback to cached index.php when offline
 */

/* =========================================================================
 * CACHE CONFIGURATION
 * ========================================================================= */

/* Cache version: increment to force a full cache purge on next visit */
const CACHE_VERSION = 'ihymns-v0.2.0';

/* Local static assets to pre-cache during service worker installation */
const STATIC_ASSETS = [
    './',
    'index.php',
    'css/styles.css',
    'css/print.css',
    'js/app.js',
    'js/utils/helpers.js',
    'js/modules/songbook.js',
    'js/modules/song-view.js',
    'js/modules/search.js',
    'js/modules/favorites.js',
    'js/modules/settings.js',
    'js/modules/help.js',
    'js/modules/audio.js',
    'js/modules/sheet-music.js',
    'manifest.json',
    'assets/favicon.svg'
];

/* CDN origins — requests to these are NEVER cached by the service worker */
const CDN_ORIGINS = [
    'cdn.jsdelivr.net',
    'cdnjs.cloudflare.com'
];

/* =========================================================================
 * INSTALL — Pre-cache local static assets
 * ========================================================================= */
self.addEventListener('install', (event) => {
    console.log('[iHymns SW] Installing:', CACHE_VERSION);
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

/* =========================================================================
 * ACTIVATE — Clean up old caches
 * ========================================================================= */
self.addEventListener('activate', (event) => {
    console.log('[iHymns SW] Activating:', CACHE_VERSION);
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names
                    .filter((name) => name !== CACHE_VERSION)
                    .map((name) => {
                        console.log('[iHymns SW] Deleting old cache:', name);
                        return caches.delete(name);
                    })
            ))
            .then(() => self.clients.claim())
    );
});

/* =========================================================================
 * FETCH — Intercept network requests
 * ========================================================================= */
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    /* -----------------------------------------------------------------
     * CDN RESOURCES: Network-only — NEVER cache
     * CDN has its own caching. Caching CDN responses in the SW risks
     * poisoning the cache with error responses (503s) that then get
     * served forever via cache-first strategy.
     * ----------------------------------------------------------------- */
    if (CDN_ORIGINS.some((origin) => url.hostname.includes(origin))) {
        event.respondWith(fetch(event.request));
        return;
    }

    /* -----------------------------------------------------------------
     * SONG DATA: Network-first
     * Try network for fresh data, fall back to cache when offline.
     * ----------------------------------------------------------------- */
    if (url.pathname.includes('songs.json')) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then((c) => c.put(event.request, clone));
                    }
                    return response;
                })
                .catch(() => caches.match(event.request))
        );
        return;
    }

    /* -----------------------------------------------------------------
     * LOCAL ASSETS: Cache-first
     * Serve from cache for speed, fall back to network if not cached.
     * Only cache successful (200) GET responses.
     * ----------------------------------------------------------------- */
    event.respondWith(
        caches.match(event.request)
            .then((cached) => {
                if (cached) {
                    return cached;
                }
                return fetch(event.request)
                    .then((response) => {
                        /* Only cache successful local responses */
                        if (response && response.ok && event.request.method === 'GET') {
                            const clone = response.clone();
                            caches.open(CACHE_VERSION).then((c) => c.put(event.request, clone));
                        }
                        return response;
                    })
                    .catch(() => {
                        /* Offline fallback: serve index.php for navigation requests */
                        if (event.request.mode === 'navigate') {
                            return caches.match('index.php');
                        }
                        return new Response('Offline', { status: 503 });
                    });
            })
    );
});
