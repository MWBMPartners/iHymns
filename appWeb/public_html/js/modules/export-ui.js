/* ==========================================================================
 *  export-ui.js — End-user export of a song / songbook to worship-presentation
 *  formats (#1166). Surfaces the editor's existing export machinery
 *  (manage/editor/format-export.js → window.iHymnsFormatExport) to the PUBLIC
 *  song + songbook views, so a curator can hand a file to ProPresenter / OpenLP
 *  / VideoPsalm / FreeShow / OpenSong / Proclaim without opening the editor.
 *
 *  format-export.js is a plain global script (sets window.iHymnsFormatExport),
 *  NOT an ES module, and depends on propresenter-export.js for the ZIP writer.
 *  We load BOTH on demand (first export click) so pages that never export pay
 *  nothing. Song data comes from the public ?action=song_data endpoint; whole
 *  songbooks from ?action=songbook_export (both DB-direct, rule #17).
 *
 *  Wiring is ROUTER-driven (#1565), NOT fragment-inline. `initSongExport()` /
 *  `initSongbookExport()` used to be called from a `<script>` embedded in the
 *  song.php / songbook.php AJAX fragments themselves. Two things broke that:
 *   1. The document sends an enforcing nonce CSP (`script-src 'self'
 *      'nonce-…'`, no `unsafe-inline` — #117), which refuses any inline
 *      <script> that doesn't carry that exact per-request nonce.
 *      https://developer.mozilla.org/docs/Web/HTTP/Headers/Content-Security-Policy/script-src
 *   2. These fragments are shared-cache API responses (`/api?page=song` /
 *      `?page=songbook`, rule #6 in .claude/CLAUDE.md) — the SAME cached HTML
 *      is served to every visitor, so it can never be stamped with any one
 *      request's nonce. There is no fix on the fragment side.
 *  So the inline scripts silently no-oped for every visitor from the day the
 *  CSP shipped — clicking Export did nothing. The fix mirrors home-page.js:
 *  the SPA router (`router.js` `afterPageLoad()`) imports this module as a
 *  real ES module (allowed under `script-src 'self'`) and calls these two
 *  functions itself once the fragment is in the DOM, so no inline script is
 *  ever required.
 * ========================================================================== */

/* The format keys exactly match window.iHymnsFormatExport's registry. */
const FORMATS = [
    { key: 'openSong',      label: 'OpenSong (.xml)' },
    { key: 'openLyrics',    label: 'OpenLyrics / OpenLP (.xml)' },
    { key: 'proPresenter6', label: 'ProPresenter 6 (.pro6)' },
    { key: 'videoPsalm',    label: 'VideoPsalm (.json)' },
    { key: 'freeShow',      label: 'FreeShow (.show)' },
    { key: 'proclaim',      label: 'Proclaim (.txt)' },
    { key: 'chordPro',      label: 'ChordPro (.cho)' },
];

let _libsPromise = null;

/** Lazy-load the export libraries once (ProPresenter ZIP writer first). */
function loadExportLibs() {
    if (window.iHymnsFormatExport) { return Promise.resolve(); }
    if (_libsPromise) { return _libsPromise; }
    _libsPromise = loadScript('/manage/editor/propresenter-export.js')
        .then(() => loadScript('/manage/editor/format-export.js'))
        .catch((err) => { _libsPromise = null; throw err; });
    return _libsPromise;
}

/* ProPresenter 7+ (.pro, #887) is a separate exporter (window.iHymnsProPresenter)
   that encodes a binary protobuf, so it needs protobufjs loaded + init() first —
   unlike the format-export.js formats. Single-song only (no exportSongbook). */
let _pp7Promise = null;
function loadPP7() {
    if (_pp7Promise) { return _pp7Promise; }
    _pp7Promise = loadScript('/manage/editor/vendor/protobuf.min.js')
        .then(() => loadScript('/manage/editor/propresenter-export.js'))
        .then(() => {
            if (!window.iHymnsProPresenter || typeof window.iHymnsProPresenter.init !== 'function') {
                throw new Error('ProPresenter 7 exporter unavailable');
            }
            return window.iHymnsProPresenter.init({ protobuf: window.protobuf });
        })
        .catch((err) => { _pp7Promise = null; throw err; });
    return _pp7Promise;
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        if (document.querySelector('script[data-export-lib="' + src + '"]')) { resolve(); return; }
        const s = document.createElement('script');
        s.src = src;
        s.dataset.exportLib = src;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('Failed to load ' + src));
        document.head.appendChild(s);
    });
}

function toast(message, type) {
    if (window.iHymnsApp && typeof window.iHymnsApp.showToast === 'function') {
        window.iHymnsApp.showToast(message, type || 'info');
    }
}

async function fetchJson(url) {
    const r = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!r.ok) { throw new Error('HTTP ' + r.status); }
    return r.json();
}

/**
 * Wire a song-view "Export ▾" dropdown. The dropdown markup (button +
 * `.song-export-menu` with `[data-export-format]` items) lives in song.php;
 * this binds the clicks.
 * @param {string} songId
 */
export function initSongExport(songId) {
    const menu = document.querySelector('.song-export-menu');
    if (!menu || menu.dataset.wired === '1' || !songId) { return; }
    menu.dataset.wired = '1';

    menu.querySelectorAll('[data-export-format]').forEach((item) => {
        item.addEventListener('click', async () => {
            const fmtKey = item.dataset.exportFormat;
            try {
                toast('Preparing export…', 'info');
                const data = await fetchJson('/api?action=song_data&id=' + encodeURIComponent(songId));
                if (!data || !data.song) { throw new Error('song not found'); }
                if (fmtKey === 'proPresenter7') {
                    await loadPP7();
                    window.iHymnsProPresenter.exportSong(data.song, {});
                    return;
                }
                await loadExportLibs();
                const fmt = window.iHymnsFormatExport && window.iHymnsFormatExport[fmtKey];
                if (!fmt || typeof fmt.exportSong !== 'function') { throw new Error('format unavailable'); }
                fmt.exportSong(data.song, {});
            } catch (err) {
                toast('Export failed: ' + (err && err.message ? err.message : 'unknown error'), 'danger');
            }
        });
    });
}

/**
 * Wire a songbook-view "Export songbook ▾" dropdown.
 * @param {string} abbr  Songbook abbreviation (e.g. 'MP')
 */
export function initSongbookExport(abbr) {
    const menu = document.querySelector('.songbook-export-menu');
    if (!menu || menu.dataset.wired === '1' || !abbr) { return; }
    menu.dataset.wired = '1';

    menu.querySelectorAll('[data-export-format]').forEach((item) => {
        item.addEventListener('click', async () => {
            const fmtKey = item.dataset.exportFormat;
            try {
                toast('Preparing songbook export…', 'info');
                const data = await fetchJson('/api?action=songbook_export&abbr=' + encodeURIComponent(abbr));
                const songs = data && data.songs;
                if (!Array.isArray(songs) || !songs.length) { throw new Error('no songs to export'); }
                const meta = {
                    name:         (data.songbook && (data.songbook.name || data.songbook.id)) || abbr,
                    abbreviation: (data.songbook && (data.songbook.id || data.songbook.abbreviation)) || abbr,
                };
                if (fmtKey === 'proPresenter7') {
                    /* PP7 exports a whole songbook as a .probundle (#887). */
                    await loadPP7();
                    await window.iHymnsProPresenter.exportAllAsBundle(songs, {
                        songbookAbbrev: meta.abbreviation,
                        songbookName:   meta.name,
                    });
                    return;
                }
                await loadExportLibs();
                const fmt = window.iHymnsFormatExport && window.iHymnsFormatExport[fmtKey];
                if (!fmt || typeof fmt.exportSongbook !== 'function') { throw new Error('format unavailable'); }
                fmt.exportSongbook(songs, meta);
            } catch (err) {
                toast('Songbook export failed: ' + (err && err.message ? err.message : 'unknown error'), 'danger');
            }
        });
    });
}
