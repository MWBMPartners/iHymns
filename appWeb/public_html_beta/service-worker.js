/**
 * iHymns — Service Worker
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Provides offline functionality for the iHymns PWA by caching
 * essential resources (HTML, CSS, JS, song data, fonts, icons).
 * Uses a cache-first strategy for static assets and network-first
 * for the song data (to allow updates).
 */

/* =========================================================================
 * CACHE CONFIGURATION
 * ========================================================================= */

/* Cache version: increment this when deploying new versions to bust the cache */
const CACHE_VERSION = 'ihymns-v0.1.1';

/* Static assets to pre-cache during service worker installation */
/* These files are cached immediately so the app works offline on first install */
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
    'manifest.json',
    'assets/favicon.svg'
];

/* External CDN resources to cache on first use (not pre-cached) */
const CDN_ASSETS = [
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/fuse.js@7.0.0/dist/fuse.min.js'
];

/* =========================================================================
 * INSTALL EVENT
 * Triggered when the service worker is first installed.
 * Pre-caches all static assets so the app works offline immediately.
 * ========================================================================= */
self.addEventListener('install', (event) => {
    /* Log installation for debugging */
    console.log('[iHymns SW] Installing service worker:', CACHE_VERSION);

    /* Wait until all static assets are cached before marking install as complete */
    event.waitUntil(
        /* Open (or create) the cache with our versioned name */
        caches.open(CACHE_VERSION)
            .then((cache) => {
                /* Pre-cache all static assets */
                console.log('[iHymns SW] Pre-caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                /* Skip waiting: activate the new service worker immediately */
                /* (instead of waiting for all tabs to close) */
                return self.skipWaiting();
            })
    );
});

/* =========================================================================
 * ACTIVATE EVENT
 * Triggered when the service worker becomes active.
 * Cleans up old caches from previous versions.
 * ========================================================================= */
self.addEventListener('activate', (event) => {
    /* Log activation for debugging */
    console.log('[iHymns SW] Activating service worker:', CACHE_VERSION);

    /* Wait until old caches are cleaned up */
    event.waitUntil(
        /* Get all existing cache names */
        caches.keys()
            .then((cacheNames) => {
                /* Delete any caches that don't match the current version */
                return Promise.all(
                    cacheNames
                        .filter((name) => name !== CACHE_VERSION)
                        .map((name) => {
                            console.log('[iHymns SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                /* Claim all open clients (tabs) immediately */
                /* This ensures the new service worker controls existing tabs */
                return self.clients.claim();
            })
    );
});

/* =========================================================================
 * FETCH EVENT
 * Intercepts all network requests and serves from cache when available.
 *
 * Strategy:
 * - Song data (songs.json): Network-first (try network, fall back to cache)
 *   This ensures users get the latest songs when online.
 * - Static assets & CDN: Cache-first (try cache, fall back to network)
 *   This provides fast offline loading for CSS, JS, fonts, etc.
 * ========================================================================= */
self.addEventListener('fetch', (event) => {
    /* Get the request URL for strategy decisions */
    const requestUrl = new URL(event.request.url);

    /* --- Strategy: Network-first for song data --- */
    if (requestUrl.pathname.includes('songs.json')) {
        event.respondWith(
            /* Try to fetch from the network first */
            fetch(event.request)
                .then((networkResponse) => {
                    /* If network succeeds, cache the fresh response and return it */
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_VERSION).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                    return networkResponse;
                })
                .catch(() => {
                    /* If network fails, try to serve from cache */
                    return caches.match(event.request);
                })
        );
        return;
    }

    /* --- Strategy: Cache-first for everything else --- */
    event.respondWith(
        /* Check if the request is in the cache */
        caches.match(event.request)
            .then((cachedResponse) => {
                /* If found in cache, return the cached response (fast!) */
                if (cachedResponse) {
                    return cachedResponse;
                }

                /* If not in cache, fetch from the network */
                return fetch(event.request)
                    .then((networkResponse) => {
                        /* Only cache successful GET requests */
                        if (networkResponse && networkResponse.status === 200 &&
                            event.request.method === 'GET') {
                            /* Clone the response (it can only be consumed once) */
                            const responseClone = networkResponse.clone();

                            /* Store the response in the cache for future use */
                            caches.open(CACHE_VERSION).then((cache) => {
                                cache.put(event.request, responseClone);
                            });
                        }

                        /* Return the network response to the page */
                        return networkResponse;
                    })
                    .catch(() => {
                        /* Both cache and network failed: return an offline fallback */
                        /* For navigation requests, return the cached index page */
                        if (event.request.mode === 'navigate') {
                            return caches.match('index.php');
                        }

                        /* For other requests (images, etc.), return nothing */
                        return new Response('Offline', {
                            status: 503,
                            statusText: 'Service Unavailable'
                        });
                    });
            })
    );
});
