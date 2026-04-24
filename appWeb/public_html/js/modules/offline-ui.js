/**
 * iHymns — Offline Download UI Bindings (#453, #454, #455)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Runtime wiring for every "download for offline" button that lives
 * inside templated HTML (songbook cards on the home page, per-song
 * button in the song-view toolbar). Feature-detects Cache API +
 * Service Worker + IndexedDB; only shows the controls on browsers
 * that can actually act on them.
 *
 * Does NOT own the actual caching — that runs inside the service
 * worker (public_html/service-worker.js.php). This module posts
 * messages to the SW and listens for progress events back.
 */

/** Is this browser capable of offline caching? */
export function isOfflineSupported() {
    return (typeof navigator !== 'undefined')
        && ('serviceWorker' in navigator)
        && (typeof window !== 'undefined')
        && ('caches' in window)
        && ('indexedDB' in window);
}

/** One-time feature-detection toggle. Controls are rendered visible
 *  server-side; we only ADD the body class so the shared CSS rule in
 *  app.css / admin.css can hide them when the browser can't act. */
export function markOfflineCapability() {
    if (!isOfflineSupported()) {
        document.body.classList.add('offline-unsupported');
        return false;
    }
    document.body.classList.add('offline-supported');
    /* Legacy cleanup — older builds rendered these with `d-none` and
       relied on the JS to reveal them. New builds render them visible,
       but leave this unhide step in place so mid-version caches don't
       hide buttons on users who just got the refresh. */
    for (const btn of document.querySelectorAll('[data-songbook-download], [data-song-download]')) {
        btn.classList.remove('d-none');
    }
    return true;
}

/** Post a CACHE_ALL_SONGS message targeting a single songbook. */
async function cacheSongbook(songbookId, totalSongs) {
    const reg = await navigator.serviceWorker.ready;
    if (!reg.active) throw new Error('Service worker not active');
    reg.active.postMessage({
        type: 'CACHE_ALL_SONGS',
        songbooks: [songbookId],
        totalSongs,
    });
}

/** Post a CACHE_SONG message for a single song. */
async function cacheSong(songId) {
    const reg = await navigator.serviceWorker.ready;
    if (!reg.active) throw new Error('Service worker not active');
    const url = `/api?page=song&id=${encodeURIComponent(songId)}`;
    reg.active.postMessage({ type: 'CACHE_SONG', url });
}

/** Read the user's "include audio" preference from Settings. */
function includeAudioPref() {
    try {
        return localStorage.getItem('ihymns_offline_include_audio') === '1';
    } catch { return false; }
}

function setButtonState(btn, state, labelOverride) {
    const label = {
        idle:        'Download for offline use',
        downloading: 'Downloading…',
        cached:      'Already saved offline',
        error:       'Download failed — tap to retry',
    }[state] || 'Download';
    btn.dataset.state = state;
    btn.title = labelOverride || label;
    btn.setAttribute('aria-label', labelOverride || label);
    btn.disabled = state === 'downloading' || state === 'cached';

    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = {
            idle:        'fa-solid fa-cloud-arrow-down',
            downloading: 'fa-solid fa-spinner fa-spin',
            cached:      'fa-solid fa-cloud-check',
            error:       'fa-solid fa-triangle-exclamation',
        }[state] || 'fa-solid fa-cloud';
    }
}

/** Has the given URL been cached? */
async function urlIsCached(url) {
    try {
        for (const cacheName of await caches.keys()) {
            const cache = await caches.open(cacheName);
            const match = await cache.match(url);
            if (match) return true;
        }
    } catch { /* ignore */ }
    return false;
}

export function bootOfflineUi() {
    if (!markOfflineCapability()) return;

    /* Songbook cards (#453) — skip buttons we've already wired so that
       calling bootOfflineUi on every SPA page change doesn't double-bind. */
    for (const btn of document.querySelectorAll('[data-songbook-download]')) {
        if (btn.dataset.wired === '1') continue;
        btn.dataset.wired = '1';
        const songbookId = btn.dataset.songbookDownload;
        const card = btn.closest('.card-songbook');
        const totalSongs = parseInt(card?.dataset?.songbookSongs || '0', 10);
        setButtonState(btn, 'idle');

        btn.addEventListener('click', async (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            setButtonState(btn, 'downloading');
            try {
                await cacheSongbook(songbookId, totalSongs);
            } catch (e) {
                console.error('[offline-ui] cache songbook failed', e);
                setButtonState(btn, 'error');
            }
        });
    }

    /* Per-song button (#454) — idempotent as above. */
    for (const btn of document.querySelectorAll('[data-song-download]')) {
        if (btn.dataset.wired === '1') continue;
        btn.dataset.wired = '1';
        const songId = btn.dataset.songDownload;
        (async () => {
            if (await urlIsCached(`/api?page=song&id=${encodeURIComponent(songId)}`)) {
                setButtonState(btn, 'cached');
            } else {
                setButtonState(btn, 'idle');
            }
        })();

        btn.addEventListener('click', async (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            setButtonState(btn, 'downloading');
            try {
                await cacheSong(songId);
                /* Give the SW a moment to land the cache entry, then
                   verify. `cache.match` is cheap so we don't worry
                   about the tight poll. */
                setTimeout(async () => {
                    const ok = await urlIsCached(`/api?page=song&id=${encodeURIComponent(songId)}`);
                    setButtonState(btn, ok ? 'cached' : 'error');
                }, 500);
            } catch (e) {
                console.error('[offline-ui] cache song failed', e);
                setButtonState(btn, 'error');
            }
        });
    }

    /* Listen to SW progress to flip songbook buttons to `cached` when
       their download finishes. */
    if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', (ev) => {
            const msg = ev.data || {};
            if (msg.type !== 'CACHE_ALL_SONGS_PROGRESS') return;
            if (!msg.done) return;
            /* Mark every songbook-download button that's in-flight as
               cached; we don't get back the exact songbook ID in the
               current SW message shape, so flip any that are currently
               `downloading`. */
            for (const btn of document.querySelectorAll('[data-songbook-download]')) {
                if (btn.dataset.state === 'downloading') {
                    setButtonState(btn, msg.failed > 0 ? 'error' : 'cached');
                }
            }
        });
    }

    /* Respect the Include Audio pref implicitly — future work once the
       SW actually consumes a flag. Exposed via global event for JS
       listeners on the Settings page. */
    window.addEventListener('ihymns:offline-settings-changed', () => {
        /* No-op placeholder so Settings can trigger re-evaluation; the
           decision lives on the server / SW when we hook audio caching
           through the bulk_songs response. */
    });
}

/** Attach on first DOMContentLoaded + also expose manually. */
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootOfflineUi, { once: true });
    } else {
        bootOfflineUi();
    }
}
