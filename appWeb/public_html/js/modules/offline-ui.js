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

import {
    STORAGE_OFFLINE_INCLUDE_AUDIO,
    STORAGE_OFFLINE_INCLUDE_AUDIO_LEGACY,
    EVT_OFFLINE_SETTINGS_CHANGED,
} from '../constants.js';

/** Is this browser capable of offline caching? */
export function isOfflineSupported() {
    return (typeof navigator !== 'undefined')
        && ('serviceWorker' in navigator)
        && (typeof window !== 'undefined')
        && ('caches' in window)
        && ('indexedDB' in window);
}

/**
 * Quota-aware pre-flight check for bulk caching operations (#354).
 *
 * Reads navigator.storage.estimate() and returns a snapshot:
 *
 *   {
 *     supported: boolean,   // false on engines without StorageManager
 *     usage:     number,    // bytes already used (0 if unknown)
 *     quota:     number,    // bytes the origin is allowed (0 if unknown)
 *     freeBytes: number,    // quota - usage (0 if unknown)
 *     freeRatio: number,    // freeBytes / quota (0..1, NaN if unknown)
 *   }
 *
 * Callers (cacheAllSongs, cacheSongbook) should consult this BEFORE
 * kicking off a multi-megabyte fetch sequence — better to surface a
 * "not enough free storage to download" warning than to let the
 * service worker silently fail mid-write when the browser eventually
 * evicts the cache. The threshold for warning is up to the caller;
 * 50 MB free + 5 % free-ratio is a reasonable baseline for the
 * offline-songbook flows.
 *
 * Resolves with `supported: false` when navigator.storage isn't
 * available — caller should treat that as "go ahead, we don't
 * know" rather than blocking the operation outright.
 */
export async function getStorageEstimate() {
    const blank = { supported: false, usage: 0, quota: 0, freeBytes: 0, freeRatio: NaN };
    if (typeof navigator === 'undefined' || !navigator.storage || !navigator.storage.estimate) {
        return blank;
    }
    try {
        const e = await navigator.storage.estimate();
        const usage = Number(e.usage) || 0;
        const quota = Number(e.quota) || 0;
        const freeBytes = Math.max(quota - usage, 0);
        return {
            supported: true,
            usage,
            quota,
            freeBytes,
            freeRatio: quota > 0 ? freeBytes / quota : NaN,
        };
    } catch (_e) {
        return blank;
    }
}

/**
 * Ask the browser to make this origin's storage PERSISTENT (#1597 RC6).
 *
 * ELI5: tells the browser "the user actually asked for these songs, please
 * don't quietly bin them to free up space".
 *
 * WHY THIS EXISTS
 * ---------------
 * `navigator.storage.persist()` appeared NOWHERE in the tree. Without it every
 * Cache API entry this app writes is "best-effort" storage, which the browser
 * may evict whenever it feels pressure — and Safari's 7-day cap on script-
 * writable storage will drop the lot after a week of not opening the app. So a
 * user could download a songbook, be shown "All 3,517 songs saved for offline
 * use", not open the app for a fortnight, and find it empty. Persistent
 * storage is the one API that says otherwise.
 *
 * Call this at the START of a DELIBERATE download — that is the moment the
 * user has demonstrated intent, and Chrome's heuristics (installed PWA, high
 * engagement, notifications permission) are most likely to grant it silently.
 * Never call it speculatively on page load: on Firefox it raises a permission
 * prompt, and a prompt the user did not ask for gets dismissed.
 *
 * Best-effort by design — a `false` result is not an error, it just means the
 * download is evictable. We surface that to the caller rather than blocking.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/StorageManager/persist
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Storage_API/Storage_quotas_and_eviction_criteria
 * @returns {Promise<{supported: boolean, persisted: boolean}>}
 */
export async function requestPersistentStorage() {
    if (typeof navigator === 'undefined'
        || !navigator.storage
        || typeof navigator.storage.persist !== 'function') {
        return { supported: false, persisted: false };
    }
    try {
        /* Already granted? `persisted()` is a cheap read and avoids
           re-triggering Firefox's permission prompt on every download. */
        if (typeof navigator.storage.persisted === 'function'
            && await navigator.storage.persisted()) {
            return { supported: true, persisted: true };
        }
        return { supported: true, persisted: await navigator.storage.persist() };
    } catch (_e) {
        return { supported: true, persisted: false };
    }
}

/**
 * Human-readable byte size, shared so the Settings storage line and the
 * per-songbook size labels can never drift apart on rounding.
 * @param {number} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n >= 1073741824) return (n / 1073741824).toFixed(1) + ' GB';
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    return Math.round(n / 1024) + ' KB';
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

/**
 * Resolve the service worker we can post to.
 * `controller` is the worker already driving this page; `ready.active` covers
 * the first load after install, before a controller exists.
 * @returns {Promise<ServiceWorker>}
 */
async function activeWorker() {
    if (navigator.serviceWorker.controller) return navigator.serviceWorker.controller;
    const reg = await navigator.serviceWorker.ready;
    if (!reg.active) throw new Error('Service worker not active');
    return reg.active;
}

/** Post a CACHE_ALL_SONGS message targeting a single songbook. */
async function cacheSongbook(songbookId, totalSongs) {
    (await activeWorker()).postMessage({
        type: 'CACHE_ALL_SONGS',
        songbooks: [songbookId],
        totalSongs,
    });
}

/**
 * Post a CACHE_SONG message for a single song.
 *
 * `saved: true` is what routes this into the service worker's untrimmed
 * SAVED_CACHE (#1597 RC1). router.js posts the same message WITHOUT the flag
 * for every incidental song view, and those land in the capped recency bucket.
 * Before the split they shared one 2,000-entry budget, so viewing one song
 * deleted ~1,500 songs the user had deliberately downloaded.
 */
async function cacheSong(songId) {
    const url = `/api?page=song&id=${encodeURIComponent(songId)}`;
    (await activeWorker()).postMessage({ type: 'CACHE_SONG', url, saved: true });
}

/**
 * Cached mirror of the "include audio in offline downloads" preference,
 * refreshed by the EVT_OFFLINE_SETTINGS_CHANGED listener in bootOfflineUi().
 * `null` = not read yet.
 * @type {boolean|null}
 */
let _includeAudio = null;

/**
 * Read the user's "include audio" preference (#1597 RC7).
 *
 * TWO KEYS, ON PURPOSE. `Settings.set('includeAudioOffline', …)` derives its
 * localStorage key from a prefix, producing `ihymns_includeAudioOffline` with
 * a `'true'`/`'false'` value — a key that never existed in constants.js. This
 * module was reading the CANONICAL `ihymns_offline_include_audio` with a
 * `'1'` value, so the two halves of the feature have never once agreed and
 * this returned false no matter what the user chose. Settings now mirror-
 * writes the canonical key; the legacy key is still read so nobody's existing
 * choice is silently reset by the upgrade.
 */
function includeAudioPref() {
    if (_includeAudio !== null) return _includeAudio;
    try {
        const canonical = localStorage.getItem(STORAGE_OFFLINE_INCLUDE_AUDIO);
        if (canonical !== null) {
            _includeAudio = canonical === '1' || canonical === 'true';
            return _includeAudio;
        }
        _includeAudio = localStorage.getItem(STORAGE_OFFLINE_INCLUDE_AUDIO_LEGACY) === 'true';
    } catch {
        _includeAudio = false;
    }
    return _includeAudio;
}

/**
 * Fetch each songbook's audio manifest and ask the SW to cache it (#401).
 *
 * Lives here rather than on the Settings class because BOTH download surfaces
 * need it — the Settings per-songbook buttons and the home-page songbook tiles
 * (`[data-songbook-download]`). It used to be a private method on Settings, so
 * a tile download never fetched audio at all regardless of the preference.
 * CLAUDE.md rule #7: offline-download behaviour belongs in this module and is
 * reused, not forked per surface.
 *
 * Failures are logged and skipped — audio is an enhancement on top of the
 * lyric download, never a reason to fail it.
 *
 * @param {string[]} songbooks Songbook abbreviations
 * @returns {Promise<void>}
 */
export async function queueAudioCacheForSongbooks(songbooks) {
    if (typeof navigator === 'undefined' || !navigator.serviceWorker) return;
    let worker;
    try {
        worker = await activeWorker();
    } catch {
        return;
    }
    for (const songbook of songbooks) {
        try {
            const r = await fetch(`/api?action=bulk_audio&songbook=${encodeURIComponent(songbook)}`);
            if (!r.ok) continue;
            const data = await r.json();
            const urls = (data.audio || []).map(a => a.url).filter(Boolean);
            if (urls.length === 0) continue;
            worker.postMessage({ type: 'CACHE_AUDIO_URLS', urls, songbook });
        } catch (err) {
            console.warn(`[offline-ui] audio manifest fetch failed for ${songbook}:`, err.message);
        }
    }
}

function setButtonState(btn, state, labelOverride) {
    /* The idle label reflects the live "include audio" preference so the
       button tells the truth about what tapping it will fetch (#1597 RC7).
       Folding it into aria-label as well as title keeps the a11y name and the
       tooltip in step, the same pattern as the language badges (#680 / #856). */
    const label = {
        idle:        includeAudioPref()
            ? 'Download for offline use, including audio'
            : 'Download for offline use',
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

/**
 * One-shot guard for the WINDOW/SW-level listeners at the end of
 * bootOfflineUi(). The per-button wiring is idempotent via `dataset.wired`,
 * but `router.js:585` re-invokes bootOfflineUi() on every SPA navigation, and
 * a listener attached to `window` / `navigator.serviceWorker` has no element
 * to stamp — so without this they stacked up one copy per page view, and each
 * SW progress message ran the button sweep N times.
 */
let _globalListenersBound = false;

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
                /* Deliberate intent → argue for persistent storage BEFORE
                   writing megabytes the browser might otherwise evict
                   (#1597 RC6). Never blocks the download. */
                await requestPersistentStorage();
                await cacheSongbook(songbookId, totalSongs);
                /* #1597 RC7 — the tile path now honours the Settings audio
                   preference. It previously ignored it entirely, so "include
                   audio in offline downloads" did nothing outside Settings. */
                if (includeAudioPref()) {
                    queueAudioCacheForSongbooks([songbookId]).catch(() => { /* enhancement only */ });
                }
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
                await requestPersistentStorage();   /* #1597 RC6 */
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

    /* Window / service-worker listeners — bound once per page load, not once
       per SPA navigation. See `_globalListenersBound`. */
    if (_globalListenersBound) return;
    _globalListenersBound = true;

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

    /* Include-audio preference changed in Settings (#1597 RC7).
     *
     * This listener has existed since #453 as an empty placeholder, and the
     * matching dispatcher was never written — so the constant had a listener,
     * zero dispatchers, and the preference was inert for every tile download.
     * (`tests/test-event-names.js` did not catch it because the name is in
     * that suite's ONE_SIDED_ALLOWLIST, which explicitly excuses
     * "listener exists, dispatcher pending".)
     *
     * Settings now dispatches on the toggle, and the handler does real work:
     * it refreshes the cached preference so the very next download honours the
     * new choice without a page reload, and re-labels every idle download
     * button so its tooltip / accessible name stops lying about whether audio
     * is included. */
    window.addEventListener(EVT_OFFLINE_SETTINGS_CHANGED, (ev) => {
        const detail = ev && ev.detail;
        _includeAudio = (detail && typeof detail.includeAudio === 'boolean')
            ? detail.includeAudio
            : null;   /* null → re-read from storage on next use */
        for (const btn of document.querySelectorAll('[data-songbook-download], [data-song-download]')) {
            if (btn.dataset.state === 'idle') setButtonState(btn, 'idle');
        }
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
