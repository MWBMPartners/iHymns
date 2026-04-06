/**
 * iHymns — Service Worker
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Provides offline support and caching for the iHymns PWA.
 *
 * STRATEGY:
 * "Network-first with offline fallback" — as per requirements:
 *   - Always try to load content from the host (network) first
 *   - Only fall back to cached content when the network is unreachable
 *   - Pre-caches essential app shell assets for instant offline access
 *   - CDN resources are NOT cached to avoid cache poisoning
 *
 * CACHE MANAGEMENT:
 *   - Cache version is bumped to force a full cache purge
 *   - Old caches are automatically cleaned up on activation
 */

/* =========================================================================
 * CACHE CONFIGURATION
 * ========================================================================= */

/** Cache version — increment to force a full cache purge on update */
const CACHE_VERSION = 'ihymns-v0.2.0';

/**
 * Assets to pre-cache during service worker installation.
 * These are the essential app shell files needed for offline access.
 * Only local files — CDN resources are never cached.
 */
const PRECACHE_ASSETS = [
    '/',
    '/css/app.css',
    '/css/print.css',
    '/js/app.js',
    '/js/modules/router.js',
    '/js/modules/transitions.js',
    '/js/modules/settings.js',
    '/js/modules/search.js',
    '/js/modules/favorites.js',
    '/js/modules/pwa.js',
    '/js/modules/shuffle.js',
    '/js/modules/numpad.js',
    '/js/modules/share.js',
    '/manifest.json',
    '/assets/favicon.svg',
];

/* =========================================================================
 * INSTALL EVENT — Pre-cache essential assets
 * ========================================================================= */

self.addEventListener('install', (event) => {
    console.log('[SW] Installing service worker:', CACHE_VERSION);

    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then(cache => {
                console.log('[SW] Pre-caching app shell assets');
                return cache.addAll(PRECACHE_ASSETS);
            })
            .then(() => {
                /* Skip waiting — activate immediately */
                return self.skipWaiting();
            })
    );
});

/* =========================================================================
 * ACTIVATE EVENT — Clean up old caches
 * ========================================================================= */

self.addEventListener('activate', (event) => {
    console.log('[SW] Activating service worker:', CACHE_VERSION);

    event.waitUntil(
        caches.keys()
            .then(cacheNames => {
                /* Delete any caches that don't match the current version */
                return Promise.all(
                    cacheNames
                        .filter(name => name !== CACHE_VERSION)
                        .map(name => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                /* Take control of all open tabs immediately */
                return self.clients.claim();
            })
    );
});

/* =========================================================================
 * FETCH EVENT — Network-first strategy with offline fallback
 *
 * Strategy per resource type:
 *   1. CDN resources → Network-only (never cache third-party)
 *   2. API requests  → Network-first, no caching (dynamic data)
 *   3. Local assets  → Network-first, cache on success, serve cache if offline
 *   4. Navigation    → Network-first, fall back to cached '/' for offline shell
 * ========================================================================= */

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    /* --- CDN / Third-party resources: network-only, never cache --- */
    if (url.origin !== self.location.origin) {
        /* Let the browser handle CDN requests normally */
        return;
    }

    /* --- API requests: network-only (always fresh data) --- */
    if (url.pathname.startsWith('/api')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                /* API unavailable offline — return error JSON */
                return new Response(
                    JSON.stringify({ error: 'You appear to be offline. Please check your connection.' }),
                    {
                        status: 503,
                        headers: { 'Content-Type': 'application/json' }
                    }
                );
            })
        );
        return;
    }

    /* --- Song data (songs.json): network-first, cache for offline --- */
    if (url.pathname.endsWith('/songs.json')) {
        event.respondWith(networkFirstWithCache(event.request));
        return;
    }

    /* --- Navigation requests: network-first, offline shell fallback --- */
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    /* Cache the navigation response for offline */
                    const clone = response.clone();
                    caches.open(CACHE_VERSION).then(cache => {
                        cache.put(event.request, clone);
                    });
                    return response;
                })
                .catch(() => {
                    /* Offline — serve cached version or the app shell */
                    return caches.match(event.request)
                        .then(cached => cached || caches.match('/'));
                })
        );
        return;
    }

    /* --- All other local assets: network-first with cache fallback --- */
    event.respondWith(networkFirstWithCache(event.request));
});

/* =========================================================================
 * HELPER FUNCTIONS
 * ========================================================================= */

/**
 * Network-first caching strategy.
 * Tries the network first; on success, caches the response.
 * On failure (offline), serves from cache if available.
 *
 * @param {Request} request The fetch request
 * @returns {Promise<Response>} The response
 */
async function networkFirstWithCache(request) {
    try {
        /* Try network first */
        const networkResponse = await fetch(request);

        /* Cache the successful response */
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_VERSION);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;

    } catch (error) {
        /* Network failed — try cache */
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        /* Nothing in cache either — return a generic offline response */
        return new Response('Offline — content not available', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain' }
        });
    }
}
