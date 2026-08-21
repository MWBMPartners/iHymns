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
 *  `initSongbookExport()` / `initSongbookListExport()` (#1607) used to be
 *  called from a `<script>` embedded in the song.php / songbook.php AJAX
 *  fragments themselves. Two things broke that:
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
 *  real ES module (allowed under `script-src 'self'`) and calls these
 *  functions itself once the fragment is in the DOM, so no inline script is
 *  ever required.
 *
 *  #1607 — whole-songbook export additionally lives on the /songbooks LIST
 *  (one control per tile), per the owner's decision that the editor and the
 *  single-song view stay export-free/uncluttered and whole-book export is a
 *  /songbook and /songbooks affordance only. `initSongbookListExport()`
 *  wires N per-tile menus; it is NOT a second copy of the export logic —
 *  both it and `initSongbookExport()` (the existing single-menu /songbook/
 *  wiring) call the same `exportSongbookAs()` core below. Only the MENU
 *  DISCOVERY differs: one `.songbook-export-menu` closed over one `abbr`
 *  vs. N `.songbook-list-export-menu` elements, each resolving its own
 *  `abbr` from its tile's `data-songbook-id` (see includes/pages/songbooks.php).
 * ========================================================================== */

import { apiFetch } from '../utils/api-client.js';

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
   unlike the format-export.js formats. Single-song only (no exportSongbook).

   #1788 — load order matters: protobuf.min.js (the runtime) → pp7-proto-static.js
   (the CSP-safe precompiled schema, `pbjs -t static`, which reads the global
   `protobuf` and publishes window.iHymnsPP7Proto) → propresenter-export.js
   (which prefers that static tree over the old reflection descriptor). The
   static schema is what makes PP7 export work under the enforcing nonce CSP —
   the reflection path (`Root.fromJSON` + lazy codegen) is refused by #117. */
let _pp7Promise = null;
function loadPP7() {
    if (_pp7Promise) { return _pp7Promise; }
    _pp7Promise = loadScript('/manage/editor/vendor/protobuf.min.js')
        .then(() => loadScript('/manage/editor/protos/pp7-proto-static.js'))
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
    const r = await apiFetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!r.ok) { throw new Error('HTTP ' + r.status); }
    return r.json();
}

/**
 * Read the "Lines per slide" picker inside one export `<menu>` (#1918).
 * Returns 0 ("all on one slide", the unchanged default) when the control
 * is absent (an older cached fragment) or set to something non-numeric.
 * Read at CLICK time (not once at wire time) so the curator can change the
 * picker and immediately re-export without re-opening the menu.
 * @param {Element|null} menu
 * @returns {number}
 */
function readLinesPerSlide(menu) {
    /* Defensive: a menu stub without querySelector (an older cached
       fragment, or a minimal test double) yields the 0 default rather than
       throwing — the picker being absent already means "all on one slide". */
    const select = (menu && typeof menu.querySelector === 'function')
        ? menu.querySelector('.export-lines-per-slide')
        : null;
    const n = select ? parseInt(select.value, 10) : 0;
    return (Number.isFinite(n) && n > 0) ? n : 0;
}

/**
 * Build the `options` object to hand to ONE exporter, using whichever
 * option-key spelling that exporter actually reads (#1918). The two
 * exporter surfaces predate this control and were never reconciled onto one
 * shared key name: `window.iHymnsProPresenter.exportSong()` /
 * `exportAllAsBundle()` read `options.linesPerSlide`
 * (propresenter-export.js `normaliseOptions()`); every
 * `window.iHymnsFormatExport[...]` format reads `options.maxLinesPerSlide`
 * (format-export.js `maxLinesOf()`). Centralising the mapping HERE (rather
 * than at each of the two call sites below) means a future third exporter
 * only has to be added to this one function.
 * @param {string} fmtKey
 * @param {number} linesPerSlide
 * @returns {{linesPerSlide: number}|{maxLinesPerSlide: number}}
 */
function exportOptionsFor(fmtKey, linesPerSlide) {
    return fmtKey === 'proPresenter7'
        ? { linesPerSlide: linesPerSlide }
        : { maxLinesPerSlide: linesPerSlide };
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
            /* #1918: read the picker at click time (not wire time) so a
               change to the <select> takes effect on the very next click. */
            const options = exportOptionsFor(fmtKey, readLinesPerSlide(menu));
            try {
                toast('Preparing export…', 'info');
                const data = await fetchJson('/api?action=song_data&id=' + encodeURIComponent(songId));
                if (!data || !data.song) { throw new Error('song not found'); }
                if (fmtKey === 'proPresenter7') {
                    await loadPP7();
                    /* #1566 — exportSong() is async (it encodes the protobuf
                       then triggers the download); the songbook path below
                       (initSongbookExport) already awaits its bundle
                       equivalent. Missing this await let an encode failure
                       become an unhandled promise rejection instead of the
                       catch block's toast — the user saw nothing at all. */
                    await window.iHymnsProPresenter.exportSong(data.song, options);
                    return;
                }
                await loadExportLibs();
                const fmt = window.iHymnsFormatExport && window.iHymnsFormatExport[fmtKey];
                if (!fmt || typeof fmt.exportSong !== 'function') { throw new Error('format unavailable'); }
                fmt.exportSong(data.song, options);
            } catch (err) {
                toast('Export failed: ' + (err && err.message ? err.message : 'unknown error'), 'danger');
            }
        });
    });
}

/**
 * CORE: export songbook `abbr` as format `fmtKey`. This is the ONE place
 * that knows the `songbook_export` fetch, the songbook meta shape, and the
 * ProPresenter-7 `.probundle` special case (#887) — every caller (the
 * single-menu /songbook/ wiring below and the per-tile /songbooks list
 * wiring) goes through this, per the modularity rule (.claude/CLAUDE.md) —
 * a second copy of this logic is exactly the drift #1570 already had to
 * clean up once (the songbook menu missing ChordPro).
 * @param {string} abbr    Songbook abbreviation (e.g. 'MP')
 * @param {string} fmtKey  One of the `data-export-format` keys in export-menu.php
 * @param {number} [linesPerSlide] 0 (default) = all on one slide (#1918)
 * @returns {Promise<void>} Resolves once the download has been triggered;
 *   rejects with an Error whose message is fit to show the user.
 */
async function exportSongbookAs(abbr, fmtKey, linesPerSlide) {
    const data = await fetchJson('/api?action=songbook_export&abbr=' + encodeURIComponent(abbr));
    const songs = data && data.songs;
    if (!Array.isArray(songs) || !songs.length) { throw new Error('no songs to export'); }
    const meta = {
        name:         (data.songbook && (data.songbook.name || data.songbook.id)) || abbr,
        abbreviation: (data.songbook && (data.songbook.id || data.songbook.abbreviation)) || abbr,
    };
    const options = exportOptionsFor(fmtKey, linesPerSlide || 0);
    if (fmtKey === 'proPresenter7') {
        /* PP7 exports a whole songbook as a .probundle (#887). */
        await loadPP7();
        await window.iHymnsProPresenter.exportAllAsBundle(songs, {
            songbookAbbrev: meta.abbreviation,
            songbookName:   meta.name,
            linesPerSlide:  options.linesPerSlide,
        });
        return;
    }
    await loadExportLibs();
    const fmt = window.iHymnsFormatExport && window.iHymnsFormatExport[fmtKey];
    if (!fmt || typeof fmt.exportSongbook !== 'function') { throw new Error('format unavailable'); }
    fmt.exportSongbook(songs, Object.assign({}, meta, options));
}

/**
 * Bind click handlers on one songbook export `<ul>` (its `[data-export-format]`
 * items) to `exportSongbookAs(abbr, …)`. Shared by both the single-menu
 * /songbook/ wiring (`initSongbookExport`) and the per-tile /songbooks list
 * wiring (`initSongbookListExport`) below — the wiring boilerplate (toast,
 * idempotency guard, error handling) is identical either way; only WHICH
 * menu and WHICH abbr differ per caller.
 * @param {Element|null} menu
 * @param {string} abbr
 */
function wireSongbookExportMenu(menu, abbr) {
    if (!menu || menu.dataset.wired === '1' || !abbr) { return; }
    menu.dataset.wired = '1';

    menu.querySelectorAll('[data-export-format]').forEach((item) => {
        item.addEventListener('click', async () => {
            const fmtKey = item.dataset.exportFormat;
            /* #1918: read the picker at click time (not wire time). */
            const linesPerSlide = readLinesPerSlide(menu);
            try {
                toast('Preparing songbook export…', 'info');
                await exportSongbookAs(abbr, fmtKey, linesPerSlide);
            } catch (err) {
                toast('Songbook export failed: ' + (err && err.message ? err.message : 'unknown error'), 'danger');
            }
        });
    });
}

/**
 * Wire a songbook-view "Export songbook ▾" dropdown — /songbook/<abbr>,
 * ONE menu on the page. Signature unchanged (#1607): router.js still calls
 * `initSongbookExport(abbr)` with the single abbreviation read from
 * `.page-songbook`'s `data-songbook-abbr`.
 * @param {string} abbr  Songbook abbreviation (e.g. 'MP')
 */
export function initSongbookExport(abbr) {
    wireSongbookExportMenu(document.querySelector('.songbook-export-menu'), abbr);
}

/**
 * Wire EVERY per-tile "Export songbook ▾" dropdown on the /songbooks LIST
 * page (#1607). Unlike `initSongbookExport()` above (one menu, one abbr
 * closed over by the caller), this page renders N tiles — so each menu's
 * abbreviation is read from its OWN tile's `data-songbook-id`
 * (includes/pages/songbooks.php), not passed in. `wireSongbookExportMenu()`'s
 * per-menu `dataset.wired` guard makes this idempotent across repeated
 * /songbooks navigations, same as the single-menu case.
 */
export function initSongbookListExport() {
    document.querySelectorAll('.songbook-list-export-menu').forEach((menu) => {
        const tile = menu.closest('[data-songbook-id]');
        const abbr = tile ? tile.dataset.songbookId : '';
        wireSongbookExportMenu(menu, abbr);
    });
}
