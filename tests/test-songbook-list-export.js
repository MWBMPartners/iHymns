/**
 * iHymns — Songbooks-list per-tile export coverage (#1607)
 *
 * ELI5
 * ----
 * #1607 asked for whole-songbook export to also live on the /songbooks LIST
 * (one "Export ▾" per tile), while the owner explicitly decided the single
 * song editor and the single-song public view stay export-free — that
 * dropdown must not get more cluttered. This file makes sure:
 *   1. the new per-tile wiring is a REAL ES module the router imports
 *      (not a fragment `<script>`, which the enforcing nonce CSP silently
 *      refuses — rule #30 / #1565), and
 *   2. it reuses the SAME export core as the existing single-menu
 *      /songbook/<abbr> wiring rather than forking a second copy of the
 *      fetch/format logic (the modularity rule), and
 *   3. the single-song view (song.php) still carries NO songbook-level
 *      export control — the owner's explicit anti-requirement, which this
 *      suite treats as a first-class regression to catch, not an aside.
 *
 * Detail
 * ------
 * Section A exercises `initSongbookListExport()` behaviourally, with the
 * same hand-built DOM/fetch stand-ins test-export-ui.js uses for the
 * existing `initSongExport()` / `initSongbookExport()` — proving the NEW
 * function actually resolves each menu's abbreviation from ITS OWN tile's
 * `data-songbook-id` (not the first tile's, not a closed-over global).
 *
 * Section B is source-derived, in the style of test-editor-deep-links.js /
 * test-vendor-sri.js: it greps the real .js/.php sources rather than
 * hand-typing a list, so a future regression (a second export-fetch copy,
 * a router wiring that quietly stops firing, a songbook-export control
 * that creeps back onto song.php) is caught structurally.
 *
 * `tests/php/test-fragment-inline-scripts.php` already scans EVERY file
 * under `includes/pages/*.php` and `includes/partials/*.php` (non-
 * recursive, comment-aware) for a disallowed inline `<script>` — songbooks.php
 * and export-menu.php both live directly in those directories, so that guard
 * already covers this change fully. B1 below is therefore deliberately a
 * cheap, redundant PIN (a raw substring check, stricter than necessary — it
 * would even trip on a commented-out tag) rather than a second copy of that
 * guard's comment-stripping algorithm, per the modularity rule.
 *
 * Every assertion in this file was mutation-tested by hand during
 * development (temporarily breaking the real source, confirming the
 * assertion goes red, then restoring it) — see the session report for the
 * per-assertion results; that loop is not re-encoded here because it would
 * just be this file testing itself.
 *
 *   node tests/test-songbook-list-export.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const PUB = path.join(PROJECT_ROOT, 'appWeb', 'public_html');

const EXPORT_UI_PATH = path.join(PUB, 'js', 'modules', 'export-ui.js');
const ROUTER_PATH    = path.join(PUB, 'js', 'modules', 'router.js');
const SONGBOOKS_PATH = path.join(PUB, 'includes', 'pages', 'songbooks.php');
const SONG_PATH      = path.join(PUB, 'includes', 'pages', 'song.php');
const MENU_PARTIAL_PATH = path.join(PUB, 'includes', 'partials', 'export-menu.php');

const exportUiSrc  = fs.readFileSync(EXPORT_UI_PATH, 'utf8');
const routerSrc    = fs.readFileSync(ROUTER_PATH, 'utf8');
const songbooksSrc = fs.readFileSync(SONGBOOKS_PATH, 'utf8');
const songSrc      = fs.readFileSync(SONG_PATH, 'utf8');
const menuSrc      = fs.readFileSync(MENU_PARTIAL_PATH, 'utf8');

/* Strip HTML `<!-- -->` and PHP `/* *\/` comments before matching prose that
   MENTIONS a class/function name from actually USING it — same reasoning as
   test-editor-deep-links.js's stripPhp() and test-fragment-inline-scripts.php's
   stripCommentsPreservingLines(). Several of the doc-comments this change
   itself added (songbooks.php, export-ui.js) say things like "the single-song
   view has no songbook export" in prose, which would otherwise self-defeat
   assertion B5 below. */
const stripComments = (s) => s
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

let pass = 0, fail = 0;
const failures = [];
function check(label, cond) {
    if (cond) { pass++; console.log(`  ✅ ${label}`); }
    else { fail++; failures.push(label); console.log(`  ❌ ${label}`); }
}

console.log('\n#1607 — /songbooks per-tile export coverage\n');

/* ===================================================================== *
 * SECTION A — behavioural: initSongbookListExport() actually wires N
 * independent menus to N independent abbreviations.
 * ===================================================================== */
console.log('Section A — initSongbookListExport() behaviour:');

/* Minimal DOM stand-ins, same shape as test-export-ui.js's makeItem/makeMenu,
   extended with `closest()` since the list wiring resolves each menu's
   abbreviation from its OWN tile via `menu.closest('[data-songbook-id]')`. */
function makeItem(exportFormat) {
    return {
        dataset: { exportFormat },
        _handlers: [],
        addEventListener(type, fn) { this._handlers.push(fn); },
        async click() { for (const fn of this._handlers) { await fn(); } }
    };
}
function makeTileMenu(items, songbookId) {
    const tile = songbookId === null ? null : { dataset: { songbookId } };
    return { dataset: {}, querySelectorAll: () => items, closest: () => tile };
}

let toasts, fetchLog, fetchFixture;
function resetRecorders() {
    toasts = [];
    fetchLog = [];
    fetchFixture = () => ({});
}
function installGlobals({ menus, formatExport }) {
    globalThis.document = {
        querySelectorAll: (sel) => (sel === '.songbook-list-export-menu' ? menus : [])
    };
    globalThis.window = {
        iHymnsFormatExport: formatExport,
        iHymnsApp: { showToast: (msg, type) => toasts.push({ msg, type }) }
    };
    globalThis.fetch = async (url) => {
        fetchLog.push(url);
        return { ok: true, json: async () => fetchFixture(url) };
    };
}

resetRecorders();
installGlobals({ menus: [], formatExport: {} });
const mod = await import(pathToFileURL(EXPORT_UI_PATH).href);
const { initSongExport, initSongbookExport, initSongbookListExport } = mod;
check('initSongbookListExport is an exported function', typeof initSongbookListExport === 'function');
check('initSongExport is still exported (unchanged surface)', typeof initSongExport === 'function');
check('initSongbookExport is still exported (unchanged surface)', typeof initSongbookExport === 'function');

await (async function testEachTileResolvesItsOwnAbbr() {
    resetRecorders();
    fetchFixture = (url) => {
        if (url.includes('abbr=MP')) return { songbook: { id: 'MP', name: 'Mission Praise' }, songs: [{ id: 'MP-0001' }] };
        if (url.includes('abbr=CP')) return { songbook: { id: 'CP', name: 'Carol Praise' }, songs: [{ id: 'CP-0001' }] };
        return {};
    };
    const calls = [];
    const formatExport = { openSong: { exportSongbook: (songs, meta) => calls.push(meta) } };

    const itemMP = makeItem('openSong');
    const itemCP = makeItem('openSong');
    const menuMP = makeTileMenu([itemMP], 'MP');
    const menuCP = makeTileMenu([itemCP], 'CP');
    installGlobals({ menus: [menuMP, menuCP], formatExport });

    initSongbookListExport();
    await itemMP.click();
    await itemCP.click();

    check('tile 1\'s menu fetches its OWN abbr (MP), not a shared/first-tile one',
        fetchLog.includes('/api?action=songbook_export&abbr=MP'));
    check('tile 2\'s menu fetches its OWN abbr (CP), independently of tile 1',
        fetchLog.includes('/api?action=songbook_export&abbr=CP'));
    check('both tiles reached exportSongbook with their own meta (2 distinct calls)',
        calls.length === 2 && calls.some(m => m.abbreviation === 'MP') && calls.some(m => m.abbreviation === 'CP'));
})();

await (async function testMissingTileNeverWires() {
    /* menu.closest('[data-songbook-id]') returning null (malformed/detached
       markup) must NOT wire a click handler with an empty-string abbr — that
       would silently hit `?action=songbook_export&abbr=` on click. This is
       exactly the falsy-abbr guard wireSongbookExportMenu() shares with
       initSongbookExport()'s existing `!abbr` check. */
    resetRecorders();
    const item = makeItem('openSong');
    const menu = makeTileMenu([item], null);
    installGlobals({ menus: [menu], formatExport: { openSong: { exportSongbook: () => {} } } });

    initSongbookListExport();

    check('a menu whose tile cannot be found is left unwired (no listener bound)',
        item._handlers.length === 0);
})();

await (async function testIdempotent() {
    resetRecorders();
    fetchFixture = () => ({ songbook: { id: 'MP' }, songs: [{ id: 'MP-0001' }] });
    const item = makeItem('openSong');
    const menu = makeTileMenu([item], 'MP');
    installGlobals({ menus: [menu], formatExport: { openSong: { exportSongbook: () => {} } } });

    initSongbookListExport();
    initSongbookListExport();

    check('calling initSongbookListExport twice binds each tile\'s listener once',
        item._handlers.length === 1);
})();

/* ===================================================================== *
 * SECTION B — source-derived structural guards.
 * ===================================================================== */
console.log('\nSection B — structural guards:');

/* --- B1: no executable inline <script> in the touched fragments -------- *
 * Comment-stripped first (same reasoning as B5's stripComments below) —
 * this change's own doc-comments legitimately DISCUSS why an inline
 * <script> would be refused, the same false-positive shape
 * test-fragment-inline-scripts.php's doc-block already calls out for
 * home.php / person.php. That PHP guard is the authoritative,
 * comment-aware, count-exact one; this is a cheap redundant pin on just
 * the two files this change touched. */
check('songbooks.php contains no executable inline "<script" tag',
    !/<script/i.test(stripComments(songbooksSrc)));
check('export-menu.php contains no executable inline "<script" tag either',
    !/<script/i.test(stripComments(menuSrc)));
check('songbooks.php sits directly under includes/pages/ (non-recursive), so '
    + "test-fragment-inline-scripts.php's glob already scans it",
    fs.existsSync(SONGBOOKS_PATH) && path.dirname(SONGBOOKS_PATH).endsWith(`includes${path.sep}pages`));

/* --- B2: the export core is NOT duplicated ------------------------------ */
const coreDefCount = (exportUiSrc.match(/\basync function exportSongbookAs\s*\(/g) || []).length;
check('exportSongbookAs (the core) is defined exactly once', coreDefCount === 1);

const fetchUrlCount = (exportUiSrc.match(/['"`]\/api\?action=songbook_export&abbr=/g) || []).length;
check('the songbook_export fetch URL literal appears exactly once (not re-forked per surface)',
    fetchUrlCount === 1);

const bundleCallCount = (exportUiSrc.match(/\.exportAllAsBundle\(/g) || []).length;
check('the PP7 .probundle special case (exportAllAsBundle) appears exactly once',
    bundleCallCount === 1);

const exportSongbookCallCount = (exportUiSrc.match(/fmt\.exportSongbook\(/g) || []).length;
check('the non-PP7 fmt.exportSongbook(...) call appears exactly once',
    exportSongbookCallCount === 1);

check('initSongbookExport delegates (does not itself fetch songbook_export)',
    !/export function initSongbookExport[\s\S]{0,400}?songbook_export/.test(exportUiSrc));
check('initSongbookListExport delegates (does not itself fetch songbook_export)',
    !/export function initSongbookListExport[\s\S]{0,400}?songbook_export/.test(exportUiSrc));

/* --- B3: the list wiring is imported from router.js, not fragment-inline */
/* Bound the search to the ISOLATED `if (page === 'songbooks')` branch —
   NOT a fixed-size lookback window from the call site. #1680's own guard
   (test-editor-deep-links.js) was burned TWICE by a window sized by eyeball
   against a short snippet and then too narrow for real, comment-heavy
   source (rule #34); deriving the window from the code's own structure
   (this `if` to the next top-level `if (page ===`) avoids re-guessing a
   constant. `indexOf` (not a compound-aware regex) is safe here because
   "if (page === 'songbooks')" — the single-condition form — is NOT a
   substring of the earlier combined "if (page === 'home' || page ===
   'songbooks')" guard a few lines above it; only this new isolated branch
   matches. */
const songbooksBranchIdx = routerSrc.indexOf("if (page === 'songbooks')");
check("found router.js's isolated if (page === 'songbooks') branch to scope this check to",
    songbooksBranchIdx !== -1);
if (songbooksBranchIdx !== -1) {
    const nextIf = routerSrc.indexOf('if (page ===', songbooksBranchIdx + 20);
    const branch = routerSrc.slice(songbooksBranchIdx, nextIf === -1 ? songbooksBranchIdx + 600 : nextIf);
    check('...calls initSongbookListExport() inside that branch', /initSongbookListExport\(\)/.test(branch));
    check("...reached via import('./export-ui.js') (a real ES module, not a fragment <script>)",
        /import\(\s*['"]\.\/export-ui\.js['"]\s*\)/.test(branch));
}

/* --- B4: each tile's control has a distinguishing accessible name ------ */
check("songbooks.php's per-tile export button's aria-label interpolates the "
    + 'book name ($book[\'name\']), so N tiles on the page get N distinct '
    + 'accessible names rather than N buttons all called "Export" (WCAG 2.5.3)',
    /aria-label="Export\s*<\?=\s*htmlspecialchars\(\$book\['name'\]\)\s*\?>\s*to a worship-presentation format"/
        .test(songbooksSrc));
check('...and it is wired to a dropdown-toggle, not a dead decorative button',
    /dropdown-toggle songbook-export-tile-btn/.test(songbooksSrc)
    && /data-bs-toggle="dropdown"/.test(songbooksSrc));

/* --- B5: the song VIEW still has NO songbook-level export (owner's ------ *
 *          explicit anti-requirement — must stay true).                    */
const songStripped = stripComments(songSrc);
check('song.php never references .songbook-export-menu',
    !/songbook-export-menu/.test(songStripped));
check('song.php never references .songbook-list-export-menu',
    !/songbook-list-export-menu/.test(songStripped));
check("song.php's own export menu sets \$exportMenuSurface = 'song' only "
    + "(never 'songbook' / 'songbook-list')",
    /\$exportMenuSurface\s*=\s*'song'\s*;/.test(songStripped)
    && !/\$exportMenuSurface\s*=\s*'songbook'/.test(songStripped)
    && !/\$exportMenuSurface\s*=\s*'songbook-list'/.test(songStripped));
check('song.php never calls initSongbookExport / initSongbookListExport '
    + '(no fragment-inline <script> could anyway — rule #30 — but the intent '
    + 'must not resurface even as a future ES-module wiring on this page)',
    !/initSongbookExport|initSongbookListExport/.test(songStripped));

/* The router's own `page === 'song'` branch is the other place that intent
   could resurface (song.php has nothing to say about what router.js does).
   Bound the branch at the next top-level `if (page === '...')` that follows
   it, mirroring B3's "derive the window, don't eyeball a fixed one" approach. */
const routerStripped = stripComments(routerSrc);
const songBranchStart = routerStripped.indexOf("if (page === 'song')");
check('found the router\'s page === \'song\' branch to scope this check to',
    songBranchStart !== -1);
if (songBranchStart !== -1) {
    const nextBranch = routerStripped.indexOf("if (page ===", songBranchStart + 20);
    const songBranch = routerStripped.slice(songBranchStart, nextBranch === -1 ? undefined : nextBranch);
    check("router.js's page === 'song' branch never calls the songbook export wiring",
        !/initSongbookExport|initSongbookListExport/.test(songBranch));
}

console.log(`\n${pass} passed, ${fail} failed`);
if (fail > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll songbook-list export assertions passed.');
