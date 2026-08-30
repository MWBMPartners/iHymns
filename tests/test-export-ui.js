/**
 * iHymns — export-ui.js Wiring Smoke Tests (#1565 / #1569)
 *
 * PURPOSE:
 * ELI5: makes sure clicking the "Export ▾" menu on a public song or
 * songbook page still fetches the right data and hands it to the right
 * format exporter — using small hand-built stand-ins for the browser
 * instead of a real one.
 *
 * Detail: js/modules/export-ui.js used to be wired by a `<script>` embedded
 * directly in the song.php / songbook.php AJAX fragments. That never ran
 * (see tests/php/test-fragment-inline-scripts.php's doc-block for why —
 * enforcing nonce CSP #117 + the fragments being shared-cache responses,
 * rule #6). The #1565 fix moved the wiring into the SPA router instead:
 * `router.js` `afterPageLoad()` now `import()`s this file as a real ES
 * module and calls `initSongExport()` / `initSongbookExport()` once the
 * fragment is in the DOM. Before that fix landed, this file also carried a
 * top-level `window.iHymnsExportUi = { … }` assignment that ran at
 * IMPORT time — which is why this suite can now `import()` the module
 * under plain Node with nothing more than stub `window` / `document` /
 * `fetch` globals set up first: with the top-level assignment gone,
 * importing the module touches no browser global at all; only actually
 * CALLING `initSongExport()` / `initSongbookExport()` reaches into
 * `document`/`window`/`fetch` — so a `vm` sandbox (needed by
 * test-propresenter-export.js, which loads a plain global-scope IIFE) is
 * unnecessary here; a real ESM `import()` plus swapping `globalThis.*`
 * stand-ins between assertions is enough.
 *
 * USAGE:
 *   node tests/test-export-ui.js
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const MODULE_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'js', 'modules', 'export-ui.js'
);
const moduleSource = fs.readFileSync(MODULE_PATH, 'utf8');

/* ------------------------------------------------------------------ *
 *  Minimal DOM stand-ins.
 *
 *  A "menu" stub is just the two things export-ui.js actually calls on
 *  the element it finds via `document.querySelector('.song-export-menu')`
 *  / `'.songbook-export-menu'`: a `dataset` object (for the
 *  `dataset.wired` re-entrancy guard) and `querySelectorAll()` returning
 *  the list of `[data-export-format]` dropdown-item stubs.
 *
 *  Each "item" stub records every listener `addEventListener('click', fn)`
 *  registers in `_handlers`, and exposes `click()` to invoke them — so a
 *  test can trigger the exact same async click handler export-ui.js bound,
 *  without needing a real DOM Event object or a dispatch mechanism.
 * ------------------------------------------------------------------ */
function makeItem(exportFormat) {
    return {
        dataset: { exportFormat },
        _handlers: [],
        addEventListener(type, fn) { this._handlers.push(fn); },
        async click() {
            for (const fn of this._handlers) { await fn(); }
        }
    };
}
function makeMenu(items) {
    return { dataset: {}, querySelectorAll: () => items };
}

/* Recorders, reset at the top of every test so assertions never leak
   state from a previous one (each test also installs its own globals). */
let toasts;
let fetchLog;
let fetchFixture; // (url) => response body object

function resetRecorders() {
    toasts = [];
    fetchLog = [];
    fetchFixture = () => ({});
}

/**
 * (Re-)point the plain globals export-ui.js reads at call time —
 * `document`, `window`, `fetch` are bare identifiers in that ES module
 * (never imported), so Node resolves them against `globalThis` on every
 * access. Swapping them between tests re-targets the NEXT call without
 * needing to re-import the module.
 *
 * @param {object}   opts
 * @param {object}   opts.document
 * @param {object}   [opts.formatExport]
 * @param {Function} [opts.confirmImpl] #1571 — stand-in for `window.confirm`.
 *   Defaults to a function that THROWS if ever called, so a test that
 *   doesn't expect a confirm dialog fails loudly instead of silently
 *   passing a real browser's `confirm()` (which doesn't exist under Node).
 */
function installGlobals({ document, formatExport, confirmImpl }) {
    globalThis.document = document;
    globalThis.window = {
        iHymnsFormatExport: formatExport,
        iHymnsApp: { showToast: (msg, type) => toasts.push({ msg, type }) },
        confirm: confirmImpl || (() => { throw new Error('window.confirm() was not expected to be called in this test'); })
    };
    globalThis.fetch = async (url) => {
        fetchLog.push(url);
        return { ok: true, json: async () => fetchFixture(url) };
    };
}

/* Stub globals BEFORE the dynamic import (matches the spec / house style
   for this suite) — belt-and-braces, since the current module touches no
   global at import time, but a future regression that re-adds a top-level
   `window.iHymnsExportUi = …` assignment should fail loudly here with a
   ReferenceError instead of this file needing its own fix first. */
resetRecorders();
installGlobals({ document: { querySelector: () => null }, formatExport: {} });

const mod = await import(pathToFileURL(MODULE_PATH).href);
const { initSongExport, initSongbookExport } = mod;
assert.ok(typeof initSongExport === 'function', 'initSongExport must be an exported function');
assert.ok(typeof initSongbookExport === 'function', 'initSongbookExport must be an exported function');

let pass = 0;
let fail = 0;
async function test(name, fn) {
    try {
        await fn();
        console.log(`  ✓ ${name}`);
        pass++;
    } catch (err) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${err.message}`);
        if (process.env.DEBUG) console.error(err.stack);
        fail++;
    }
}

(async function run() {
    /* ---------------------------------------------------------------- */
    console.log('Song export wiring (initSongExport):');

    await test('wires the openSong item, fetches song_data, calls the format spy', async () => {
        resetRecorders();
        const SONG_FIXTURE = { song: { id: 'MP-1008', title: 'A Test Song' } };
        fetchFixture = () => SONG_FIXTURE;

        const exportSongCalls = [];
        const formatExport = {
            openSong: { exportSong: (song, opts) => exportSongCalls.push({ song, opts }) }
        };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        installGlobals({
            document: { querySelector: (sel) => (sel === '.song-export-menu' ? menu : null) },
            formatExport
        });

        initSongExport('MP-1008');
        await item.click();

        assert.deepEqual(fetchLog, ['/api?action=song_data&id=MP-1008']);
        assert.equal(exportSongCalls.length, 1, 'expected exportSong to be called exactly once');
        assert.deepEqual(exportSongCalls[0].song, SONG_FIXTURE.song);
    });

    await test('encodeURIComponent()s the song id in the song_data URL', async () => {
        resetRecorders();
        fetchFixture = () => ({ song: { id: 'MP 1008/x' } });
        const formatExport = { openSong: { exportSong: () => {} } };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        installGlobals({
            document: { querySelector: (sel) => (sel === '.song-export-menu' ? menu : null) },
            formatExport
        });

        initSongExport('MP 1008/x');
        await item.click();

        assert.deepEqual(fetchLog, ['/api?action=song_data&id=MP%201008%2Fx']);
    });

    await test('calling initSongExport twice binds each item\'s listener once (dataset.wired guard)', async () => {
        resetRecorders();
        fetchFixture = () => ({ song: { id: 'MP-1008' } });
        const formatExport = { openSong: { exportSong: () => {} } };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        let queryCount = 0;
        installGlobals({
            document: {
                querySelector: (sel) => {
                    if (sel === '.song-export-menu') { queryCount++; return menu; }
                    return null;
                }
            },
            formatExport
        });

        initSongExport('MP-1008');
        initSongExport('MP-1008');   // second call must no-op past the dataset.wired check

        assert.equal(queryCount, 2, 'sanity: the guard itself must run both times');
        assert.equal(item._handlers.length, 1, 'a second init() call must not bind a second listener');
    });

    /* ---------------------------------------------------------------- */
    console.log('\nSongbook export wiring (initSongbookExport):');

    await test('wires the openSong item, fetches songbook_export, calls exportSongbook with meta', async () => {
        resetRecorders();
        const SONGBOOK_FIXTURE = {
            songbook: { id: 'CP', name: 'Carol Praise' },
            songs: [{ id: 'CP-0001', title: 'A song' }, { id: 'CP-0002', title: 'Another song' }]
        };
        fetchFixture = () => SONGBOOK_FIXTURE;

        const exportSongbookCalls = [];
        const formatExport = {
            openSong: { exportSongbook: (songs, meta) => exportSongbookCalls.push({ songs, meta }) }
        };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        installGlobals({
            document: { querySelector: (sel) => (sel === '.songbook-export-menu' ? menu : null) },
            formatExport
        });

        initSongbookExport('CP');
        await item.click();

        assert.deepEqual(fetchLog, ['/api?action=songbook_export&abbr=CP']);
        assert.equal(exportSongbookCalls.length, 1, 'expected exportSongbook to be called exactly once');
        assert.deepEqual(exportSongbookCalls[0].songs, SONGBOOK_FIXTURE.songs);
        /* #1918: the meta now also carries the exporter's lines-per-slide
           option (0 = all-on-one-slide default) — format-export reads it as
           `maxLinesPerSlide`. */
        assert.deepEqual(exportSongbookCalls[0].meta, { name: 'Carol Praise', abbreviation: 'CP', maxLinesPerSlide: 0 });
    });

    await test('rejects (toasts, does not call the format spy) when songbook_export returns no songs', async () => {
        resetRecorders();
        fetchFixture = () => ({ songbook: { id: 'CP', name: 'Carol Praise' }, songs: [] });
        const exportSongbookCalls = [];
        const formatExport = {
            openSong: { exportSongbook: (songs, meta) => exportSongbookCalls.push({ songs, meta }) }
        };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        installGlobals({
            document: { querySelector: (sel) => (sel === '.songbook-export-menu' ? menu : null) },
            formatExport
        });

        initSongbookExport('CP');
        await item.click();

        assert.equal(exportSongbookCalls.length, 0, 'must not call exportSongbook with an empty song list');
        assert.ok(
            toasts.some((t) => t.type === 'danger'),
            'must surface a failure toast instead of silently doing nothing'
        );
    });

    await test('calling initSongbookExport twice binds each item\'s listener once', async () => {
        resetRecorders();
        fetchFixture = () => ({ songbook: { id: 'CP' }, songs: [{ id: 'CP-0001' }] });
        const formatExport = { openSong: { exportSongbook: () => {} } };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        installGlobals({
            document: { querySelector: (sel) => (sel === '.songbook-export-menu' ? menu : null) },
            formatExport
        });

        initSongbookExport('CP');
        initSongbookExport('CP');

        assert.equal(item._handlers.length, 1, 'a second init() call must not bind a second listener');
    });

    /* ---------------------------------------------------------------- */
    console.log('\nLarge-export confirm + progress (#1571):');

    await test('a large DOM count (>= threshold) shows a confirm; declining is a QUIET no-op', async () => {
        resetRecorders();
        let confirmCalls = 0;
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        const page = { dataset: { songbookSongCount: '3517' } };
        installGlobals({
            document: {
                querySelector: (sel) => {
                    if (sel === '.songbook-export-menu') return menu;
                    if (sel === '.page-songbook') return page;
                    return null;
                }
            },
            formatExport: { openSong: { exportSongbook: () => {} } },
            confirmImpl: () => { confirmCalls++; return false; }
        });

        initSongbookExport('MP');
        await item.click();

        assert.equal(confirmCalls, 1, 'expected exactly one confirm() call');
        assert.deepEqual(fetchLog, [], 'a declined confirm must never fetch — the server work must not even start');
        assert.equal(toasts.length, 0, 'a declined confirm must be QUIET — no error toast, same as a normal export');
    });

    await test('a large DOM count, confirming proceeds — and the post-fetch belt does NOT double-prompt', async () => {
        resetRecorders();
        let confirmCalls = 0;
        fetchFixture = () => ({
            songbook: { id: 'MP', name: 'Mission Praise' },
            songs: Array.from({ length: 3517 }, (_v, i) => ({ id: 'MP-' + i }))
        });
        const exportSongbookCalls = [];
        const formatExport = { openSong: { exportSongbook: (songs, meta) => exportSongbookCalls.push({ songs, meta }) } };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        const page = { dataset: { songbookSongCount: '3517' } };
        installGlobals({
            document: {
                querySelector: (sel) => {
                    if (sel === '.songbook-export-menu') return menu;
                    if (sel === '.page-songbook') return page;
                    return null;
                }
            },
            formatExport,
            confirmImpl: () => { confirmCalls++; return true; }
        });

        initSongbookExport('MP');
        await item.click();

        assert.equal(confirmCalls, 1,
            'the post-fetch belt must NOT re-confirm when the caller\'s DOM-first count was already known — that would double-prompt the common case');
        assert.equal(exportSongbookCalls.length, 1);
    });

    await test('no DOM count available (older cached fragment): the pre-fetch check is silent, the post-fetch belt uses the REAL count', async () => {
        resetRecorders();
        let confirmCalls = 0;
        fetchFixture = () => ({
            songbook: { id: 'MP' },
            songs: Array.from({ length: 600 }, (_v, i) => ({ id: 'MP-' + i }))
        });
        const exportSongbookCalls = [];
        const formatExport = { openSong: { exportSongbook: (songs, meta) => exportSongbookCalls.push({ songs, meta }) } };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        installGlobals({
            /* '.page-songbook' -> null: simulates an older cached fragment
               that predates the data-songbook-song-count attribute. */
            document: { querySelector: (sel) => (sel === '.songbook-export-menu' ? menu : null) },
            formatExport,
            confirmImpl: () => { confirmCalls++; return false; }
        });

        initSongbookExport('MP');
        await item.click();

        assert.equal(confirmCalls, 1, 'the belt must confirm exactly once, using the count from the fetched response');
        assert.equal(exportSongbookCalls.length, 0, 'a declined belt confirm must never reach the exporter');
        /* Unlike the PRE-fetch decline (test above), the "Preparing songbook
           export…" info toast has ALREADY fired by the time the belt runs
           (it fires before the fetch, and the belt only runs after) — so a
           declined belt confirm is quiet in the sense that matters: no
           FAILURE toast, nothing implying an error occurred. */
        assert.ok(toasts.every((t) => t.type !== 'danger'),
            'a declined belt confirm must never surface an error/failure toast');
    });

    await test('a small DOM count never triggers a confirm dialog at all', async () => {
        resetRecorders();
        fetchFixture = () => ({ songbook: { id: 'CP' }, songs: [{ id: 'CP-0001' }] });
        const formatExport = { openSong: { exportSongbook: () => {} } };
        const item = makeItem('openSong');
        const menu = makeMenu([item]);
        const page = { dataset: { songbookSongCount: '3' } };
        installGlobals({
            document: {
                querySelector: (sel) => {
                    if (sel === '.songbook-export-menu') return menu;
                    if (sel === '.page-songbook') return page;
                    return null;
                }
            },
            formatExport
            /* no confirmImpl — installGlobals()'s default THROWS if ever
               called, so this test fails loudly if a small book ever nags. */
        });

        initSongbookExport('CP');
        await item.click(); // must not throw
    });

    /* --- structural: the shared confirm core + the DOM-attribute pairing -- */
    await test('confirmLargeExport is defined exactly once', () => {
        const defs = (moduleSource.match(/function confirmLargeExport\s*\(/g) || []).length;
        assert.equal(defs, 1);
    });

    await test('confirmLargeExport is CALLED from both the pre-fetch (wireSongbookExportMenu) and post-fetch belt (exportSongbookAs) sites', () => {
        /* Comment-stripped first (several doc-comments in this file legitimately
           MENTION `confirmLargeExport()` in prose to explain the two call
           sites — the exact false-positive shape test-qr-cuercode.js's own
           stripComments() guards against) — THEN exclude the definition
           itself (`function confirmLargeExport(`) via a negative lookbehind,
           so this counts REAL call sites only. */
        const stripped = moduleSource.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
        const callSites = (stripped.match(/(?<!function\s)confirmLargeExport\(/g) || []).length;
        assert.equal(callSites, 2,
            'expected exactly 2 call sites: the pre-fetch check in wireSongbookExportMenu() and the post-fetch belt in exportSongbookAs()');
    });

    await test('the DOM-first count is read via BOTH .dataset.songbookSongCount and .dataset.songbookSongs', () => {
        assert.match(moduleSource, /\.dataset\.songbookSongCount\b/,
            'expected export-ui.js to read .dataset.songbookSongCount (data-songbook-song-count, the /songbook page)');
        assert.match(moduleSource, /\.dataset\.songbookSongs\b/,
            'expected export-ui.js to read .dataset.songbookSongs (data-songbook-songs, the pre-existing #1607 /songbooks list attribute)');
    });

    await test('includes/pages/songbook.php emits the data-songbook-song-count attribute export-ui.js reads (rule #33 pairing)', () => {
        const SONGBOOK_PAGE_PATH = path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'includes', 'pages', 'songbook.php');
        const src = fs.readFileSync(SONGBOOK_PAGE_PATH, 'utf8');
        assert.match(src, /data-songbook-song-count="/);
    });

    await test('includes/pages/songbooks.php still emits the pre-existing data-songbook-songs attribute (rule #33 pairing)', () => {
        const SONGBOOKS_LIST_PATH = path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'includes', 'pages', 'songbooks.php');
        const src = fs.readFileSync(SONGBOOKS_LIST_PATH, 'utf8');
        assert.match(src, /data-songbook-songs="/);
    });

    await test('the two LARGE_EXPORT_SONG_THRESHOLD literals (export-ui.js + the editor\'s separate inline script world) agree', () => {
        /* #1571 §3.3b — the editor's PP7 export wiring is a plain inline
           <script>, not an ES module, so it cannot import this constant; the
           one-number duplication is deliberate (a cross-reference comment
           beats loading a module into that script world for one constant).
           This is the lockstep mechanism that keeps the two from silently
           drifting apart (rule #35). */
        const EDITOR_INDEX_PATH = path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'index.php');
        const editorSrc = fs.readFileSync(EDITOR_INDEX_PATH, 'utf8');
        const modMatch = moduleSource.match(/LARGE_EXPORT_SONG_THRESHOLD\s*=\s*(\d+)/);
        const editorMatch = editorSrc.match(/LARGE_EXPORT_SONG_THRESHOLD\s*=\s*(\d+)/);
        assert.ok(modMatch, 'export-ui.js must define LARGE_EXPORT_SONG_THRESHOLD');
        assert.ok(editorMatch, "manage/editor/index.php must define its own LARGE_EXPORT_SONG_THRESHOLD (the deliberate #1571 duplication)");
        assert.equal(modMatch[1], editorMatch[1], 'the two thresholds have drifted apart — keep them in lockstep');
    });

    /* ---------------------------------------------------------------- */
    console.log('\nStatic source guard:');

    await test('the single-song ProPresenter 7 export is awaited (#1566 missing-await pin)', () => {
        /* Deliberately a source-text pin rather than an exercised path: driving
           the real proPresenter7 branch would mean stubbing protobufjs, the
           vendored proto bundle AND the ZIP writer here too, just to re-prove
           what test-propresenter-export.js already proves in full. What THIS
           file adds is the one line #1566 actually fixed — that export-ui.js
           awaits the call — so a future edit that drops the `await` and turns
           an encode failure back into a silent unhandled rejection (instead of
           the catch block's toast) fails this test immediately.
           #1979 — the single-song call became exportSongAsBundle() (embeds a
           public background video/image into a .probundle, else degrades to a
           bare .pro); the await requirement is unchanged. */
        assert.match(
            moduleSource,
            /await\s+window\.iHymnsProPresenter\.exportSongAsBundle\(/,
            'exportSongAsBundle(...) must be awaited so an encode/fetch failure reaches the catch block, not an unhandled rejection'
        );
    });

    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
