/**
 * tests/test-v2-songbook-move-ui.js — the v2 editor's songbook control is a
 * confirmed, immediate, closed-list MOVE (#1679 hardening, finding H1).
 *
 * ELI5
 * ----
 * Changing a song's songbook is not a label edit any more — it gives the song a
 * brand new id, wipes its number and leaves a permanent forwarding note. This
 * file drives the real Metadata tab in a fake browser and checks that doing so
 * takes a deliberate act: pick from a list, and say yes to a warning.
 *
 * WHY THIS EXISTS
 * ---------------
 * `songbook` shipped as a plain text input whose every `input` event fed a
 * 500 ms debounce. Once #1679 made that field re-key the SongId, EVERY keystroke
 * pause that happened to spell a real songbook performed an irreversible move —
 * new id, `Number` cleared, ~41 child tables cascaded, a permanent
 * `tblSongRedirects` row. Clearing the box and typing `C`, `P` with a pause
 * after the `C` moved the song into songbook `C`; moving it back restored
 * neither the id nor the number, and left a redirect behind either way.
 *
 * The fix is behavioural, not cosmetic, so a source-text assertion ("the FIELDS
 * row says 'select'") would not have caught the version of this bug that
 * matters: a select that still routes through `debouncedSave`, or one whose
 * confirm is checked after the request is already in flight. So this MOUNTS the
 * module and asserts what actually happens on `change`.
 *
 * WHAT IT ASSERTS
 *   1. The control is a <select> populated from the injected songbook list, and
 *      the song's own book is present even when the list has not loaded it.
 *   2. Declining the confirm sends NOTHING and snaps the control back.
 *   3. Accepting sends IMMEDIATELY — before the debounce window could elapse.
 *      (Contrast-asserted against `title`, which must still be debounced, so a
 *      change that removed all debouncing everywhere cannot pass this silently.)
 *   4. The confirm text names all three consequences (new id, number cleared,
 *      old id becomes a redirect) — the dialog IS the fix; a bare "are you
 *      sure?" would leave the surprise intact.
 *   5. A server failure reverts the control, so it never sits there displaying a
 *      book the song is not in.
 *   6. A successful move hands (previousId, newId) to the shell's callback.
 *   7. A songbook list that arrives AFTER mount re-fills the control (a deep
 *      link mounts the tabs before the index lands).
 *   8. The `songbooks` payload key and its row fields agree across api2.php →
 *      sidebar.js → metadata-tab.js, both sides derived from source.
 *   9. Sections 1–7 inject `getSongbooks` as a STUB, so they pin what the tab
 *      does with a list and say nothing about what the real list can contain.
 *      Section 9 therefore mounts the REAL sidebar and asserts the two things a
 *      stub can never be wrong about: a songbook with ZERO songs is offerable
 *      (the list is the catalogue, not the set of books present in the loaded
 *      index), and the answer is not frozen at mount. Plus the contrast that
 *      keeps the fix honest — the sidebar's own FILTER dropdown must still omit
 *      that book, because filtering by a book with no songs shows nothing.
 *
 * Pass an alternate module path as argv[2] to point this at a mutated copy —
 * that is how each assertion above was proven ABLE to fail (rule #34).
 *
 *   node tests/test-v2-songbook-move-ui.js
 *   node tests/test-v2-songbook-move-ui.js /tmp/mutant/metadata-tab.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js
 * @see appWeb/public_html/includes/song_relocate.php
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_MODULE_PATH = path.join(
    __dirname, '..', 'appWeb', 'public_html', 'manage', 'editor', 'v2', 'metadata-tab.js'
);
const MODULE_PATH = process.argv[2] ? path.resolve(process.argv[2]) : DEFAULT_MODULE_PATH;

/* The tab's own debounce constant. Read from the source rather than typed here,
   so a change to it cannot quietly widen the window this test waits inside. */
const DEBOUNCE_MS = (() => {
    const m = fs.readFileSync(MODULE_PATH, 'utf8').match(/SAVE_DEBOUNCE_MS\s*=\s*(\d+)/);
    return m ? Number(m[1]) : 500;
})();

let failures = 0;
let checks = 0;
function assert(cond, label) {
    checks++;
    if (cond) { console.log('  PASS  ' + label); }
    else { failures++; console.log('  FAIL  ' + label); }
}

const flush = (ms) => new Promise((r) => setTimeout(r, ms == null ? 5 : ms));

/** Minimal store stand-in matching the real store's get/set/subscribe contract. */
function makeStore(initial) {
    let state = Object.assign({}, initial);
    const subs = [];
    return {
        get: (k) => state[k],
        set: (k, v) => { state[k] = v; subs.forEach((fn) => fn()); },
        subscribe: (_k, fn) => { subs.push(fn); return () => {}; },
    };
}

async function main() {
    const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',
        { url: 'https://example.test/manage/editor/editor2.php' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;

    const { mountMetadataTab } = await import(pathToFileURL(MODULE_PATH).href);

    /* ---- harness -------------------------------------------------------- */

    const SONGBOOKS = [
        { abbr: 'CP', name: 'Common Praise' },
        { abbr: 'MP', name: 'Mission Praise' },
    ];

    let confirmAnswer = true;
    let confirmMessage = '';
    window.confirm = (msg) => { confirmMessage = String(msg); return confirmAnswer; };

    const calls = [];                 // [{ field, value, atMs }]
    let nextResult = { ok: true };
    let nextReject = null;
    const t0 = () => Date.now();
    let mountedAt = 0;

    const api = {
        updateMetadata: (songId, field, value) => {
            calls.push({ songId, field, value, atMs: t0() - mountedAt });
            if (nextReject) { const e = nextReject; nextReject = null; return Promise.reject(e); }
            return Promise.resolve(nextResult);
        },
    };

    const idChanges = [];
    const store = makeStore({ song: { Title: 'Amazing Grace', SongbookAbbr: 'MP', Number: 41 } });
    const container = window.document.getElementById('root');

    mountedAt = t0();
    const teardown = mountMetadataTab(container, {
        store,
        api,
        songId: 'MP-0041',
        toast: () => {},
        onSongIdChange: (prev, next) => { idChanges.push([prev, next]); },
        getSongbooks: () => SONGBOOKS.slice(),
    });

    /* ---- 1. the control is a closed list -------------------------------- */

    console.log('\n--- 1. the songbook control is a <select> of real songbooks ---');
    const sel = container.querySelector('#meta-songbook');
    assert(!!sel, 'a #meta-songbook control mounted');
    assert(sel && sel.tagName === 'SELECT',
        'the songbook control is a <select>, not a free-text input (got ' + (sel && sel.tagName) + ')');
    /* Guarded: a regression back to <input> has no `.options`, and a TypeError
       here would abort the run before the behavioural assertions below — a red
       build either way, but one that reports "crashed" instead of naming what
       broke. A guard should say what is wrong, not merely that something is. */
    const values = (sel && sel.options) ? Array.from(sel.options).map((o) => o.value).sort() : [];
    assert(values.join(',') === 'CP,MP', 'options come from the injected songbook list (got ' + values.join(',') + ')');
    assert(sel && sel.value === 'MP', "the song's current book is the selected option");

    /* The song's own book must survive an index that has not listed it — a
       select that silently drops the current value would show the WRONG book and
       make the very next change a move the curator never asked for. */
    console.log('\n--- 1b. an unlisted current book is still shown ---');
    const dom2 = new JSDOM('<!doctype html><html><body><div id="root2"></div></body></html>');
    const c2 = dom2.window.document.getElementById('root2');
    global.window = dom2.window; global.document = dom2.window.document;
    dom2.window.confirm = () => false;
    const store2 = makeStore({ song: { SongbookAbbr: 'ZZ' } });
    const down2 = mountMetadataTab(c2, {
        store: store2, api, songId: 'ZZ-0001', toast: () => {},
        getSongbooks: () => SONGBOOKS.slice(),
    });
    const sel2 = c2.querySelector('#meta-songbook');
    assert(!!sel2 && sel2.value === 'ZZ',
        'a current book missing from the loaded list is prepended and stays selected');
    down2();
    global.window = window; global.document = window.document;

    /* ---- 2. declining the confirm sends nothing ------------------------- */

    console.log('\n--- 2. declining the confirmation performs no move ---');
    calls.length = 0;
    confirmAnswer = false;
    sel.value = 'CP';
    sel.dispatchEvent(new window.Event('change', { bubbles: true }));
    await flush(DEBOUNCE_MS + 60);
    assert(calls.length === 0, 'no request was sent when the curator declined (sent ' + calls.length + ')');
    assert(sel.value === 'MP', 'the select snapped back to the book the song is actually in');

    /* ---- 3. accepting sends IMMEDIATELY, not on a debounce -------------- */

    console.log('\n--- 3. an accepted move is sent immediately, never debounced ---');
    calls.length = 0;
    confirmAnswer = true;
    nextResult = { ok: true, songId: 'CP-0007', previousId: 'MP-0041' };
    sel.value = 'CP';
    const beforeChange = t0();
    sel.dispatchEvent(new window.Event('change', { bubbles: true }));
    const sentSynchronously = calls.length === 1;
    assert(sentSynchronously,
        'the request left on the change event itself, with no timer in between');
    const moveCall = calls[0];
    assert(!!moveCall && moveCall.field === 'songbook' && moveCall.value === 'CP',
        'it sent field=songbook with the chosen abbreviation');
    assert(!!moveCall && (t0() - beforeChange) < DEBOUNCE_MS,
        'and it did so well inside the ' + DEBOUNCE_MS + 'ms debounce window');

    /* CONTRAST: a text field must STILL be debounced. Without this, deleting the
       debounce from every field would make assertion 3 pass while making the rest
       of the tab worse — the "guard so blunt it cannot tell right from wrong"
       failure rule #34 names. */
    console.log('\n--- 3b. contrast: a text field is still debounced ---');
    calls.length = 0;
    const titleInput = container.querySelector('#meta-title');
    assert(!!titleInput, 'the title input mounted');
    titleInput.value = 'Amazing Grace!';
    titleInput.dispatchEvent(new window.Event('input', { bubbles: true }));
    assert(calls.length === 0, 'typing in Title sends nothing immediately');
    await flush(DEBOUNCE_MS + 60);
    assert(calls.some((c) => c.field === 'title'), 'Title is sent once the debounce elapses');

    /* ---- 4. the dialog states the consequences ------------------------- */

    console.log('\n--- 4. the confirmation names all three consequences ---');
    assert(/new id/i.test(confirmMessage), 'the dialog says the song gets a NEW id');
    assert(/number/i.test(confirmMessage) && /clear/i.test(confirmMessage),
        'the dialog says the song number is cleared');
    assert(/redirect/i.test(confirmMessage), 'the dialog says the old id becomes a redirect');
    assert(/CP/.test(confirmMessage), 'the dialog names the destination songbook');

    /* ---- 5. a server failure reverts the control ----------------------- */

    console.log('\n--- 5. a failed move does not leave the control lying ---');
    await flush(20);
    store.set('song', { Title: 'Amazing Grace', SongbookAbbr: 'MP', Number: 41 });   // re-render, back to MP
    const sel3 = container.querySelector('#meta-songbook');
    calls.length = 0;
    nextReject = Object.assign(new Error('Server error.'), { status: 500 });
    sel3.value = 'CP';
    sel3.dispatchEvent(new window.Event('change', { bubbles: true }));
    await flush(30);
    assert(calls.length === 1, 'the request was attempted');
    assert(sel3.value === 'MP', 'the select reverted to the song\'s real book after the failure');

    /* ---- 6. a successful move reaches the shell ------------------------ */

    console.log('\n--- 6. a successful move hands the new id to the shell ---');
    idChanges.length = 0;
    nextResult = { ok: true, songId: 'CP-0007', previousId: 'MP-0041' };
    sel3.value = 'CP';
    sel3.dispatchEvent(new window.Event('change', { bubbles: true }));
    await flush(30);
    assert(idChanges.length === 1 && idChanges[0][0] === 'MP-0041' && idChanges[0][1] === 'CP-0007',
        'onSongIdChange(previousId, newId) fired so the shell can re-open the song');

    /* ---- 7. a late songbook list re-fills the control ------------------- */

    /* #1679 A2. The tab reads getSongbooks() once per render, and render() only
       runs when the `song` store slice changes — which in production happens
       exactly once, immediately before the tabs mount. The sidebar's index is
       fetched in parallel, so a DEEP LINK (?song=…) routinely mounts the tabs
       first and the select froze holding one option: the song's own book. The
       move was then impossible on precisely the entry point a curator follows
       from /manage/revisions or Missing Numbers — and silently so, because a
       one-option select looks like a normal control. */
    console.log('\n--- 7. a songbook list that arrives after mount re-fills the select ---');
    const dom3 = new JSDOM('<!doctype html><html><body><div id="root3"></div></body></html>');
    const c3 = dom3.window.document.getElementById('root3');
    global.window = dom3.window; global.document = dom3.window.document;
    let lateBooks = [];
    let releaseIndex;
    const indexReady = new Promise((r) => { releaseIndex = r; });
    const store3 = makeStore({ song: { SongbookAbbr: 'MP' } });
    const down3 = mountMetadataTab(c3, {
        store: store3, api, songId: 'MP-0041', toast: () => {},
        getSongbooks: () => lateBooks.slice(),
        whenSongbooksReady: () => indexReady,
    });
    const sel4 = c3.querySelector('#meta-songbook');
    assert(!!sel4 && Array.from(sel4.options).map((o) => o.value).join(',') === 'MP',
        "before the index lands the control holds only the song's own book");
    lateBooks = SONGBOOKS.concat([{ abbr: 'NEW', name: 'Brand New Book' }]);
    releaseIndex();
    await flush(20);
    const lateValues = Array.from(sel4.options).map((o) => o.value).sort().join(',');
    assert(lateValues === 'CP,MP,NEW',
        'once the catalogue arrives the same control offers every book (got ' + lateValues + ')');
    assert(sel4.value === 'MP',
        "and re-filling did not change which book the control claims the song is in");
    down3();
    global.window = window; global.document = window.document;

    /* ---- 8. the payload key the server sends is the one the client reads -- */

    /* #1679 A2 + rule #35. The songbook catalogue crosses THREE files —
       api2.php's load_index emits it, sidebar.js stores it, metadata-tab.js reads
       fields off each row — with nothing but agreement holding them together.
       This repo has broken exactly that shape twice (the X-Requested-With header
       api2 required and its own client never sent; the two spellings of the
       language-filter event). Both sides are DERIVED from source here rather than
       typed as a list, so renaming a field on either side turns this red instead
       of producing an empty <select> with no error anywhere (rule #34). */
    console.log('\n--- 8. server payload key + row fields match what the client reads ---');
    const api2Src    = fs.readFileSync(
        path.join(__dirname, '..', 'appWeb', 'public_html', 'manage', 'editor', 'api2.php'), 'utf8');
    const sidebarSrc = fs.readFileSync(
        path.join(__dirname, '..', 'appWeb', 'public_html', 'manage', 'editor', 'v2', 'sidebar.js'), 'utf8');
    const metaSrc    = fs.readFileSync(MODULE_PATH, 'utf8');

    /* Bounding the case body on the next bare "case '" TRUNCATED it: the block's
       own comment cites `manage/editor/api.php case 'load_index'`, so the naive
       marker matched inside the very comment that documents the fix, and the
       assertion reported the payload key as missing while it sat six lines below
       the cut. Anchor on a switch-level case label instead — start of line, the
       dispatch's four-space indent. (Left recorded because it is the third time
       in this repo a guard has been wrong-but-confident about where a block ends
       — rule #34.) */
    const liStart = api2Src.indexOf("\n    case 'load_index'");
    const liNext  = liStart === -1 ? -1 : api2Src.slice(liStart + 1).search(/\n {4}case '/);
    const liBlock = liStart === -1 ? '' : api2Src.slice(liStart, liNext === -1 ? undefined : liStart + 1 + liNext);
    assert(/'songbooks'\s*=>/.test(liBlock),
        "api2.php's load_index response carries a `songbooks` key");
    assert(/res\.songbooks\b/.test(sidebarSrc),
        'sidebar.js reads res.songbooks off the load_index response');

    /* #1691 D7 — the row literal is read with a BALANCED-BRACKET scan, not
       /\[([^\]]*)\]/. That regex stops at the FIRST `]`, and the row's own
       `name` value contains one (`$b['name']`), so the capture was truncated
       mid-literal. Both keys happened to sit before the cut — correct BY LUCK,
       the A12 disease — and a key added after any nested `[...]` would be
       silently dropped from the server side of the comparison: an under-report
       whose tick reads as coverage when the client ignores the key, and a red
       on CORRECT code when the client reads it (rule #34's two failure modes,
       one truncation). The scan tracks PHP quoting too, so a bracket inside a
       string value cannot unbalance it — the same lesson jsRedactLiterals()
       in test-song-relocate-hardening.php learned the hard way. */
    const phpArrayLiteralAfter = (src, anchorRe) => {
        const m = src.match(anchorRe);
        if (!m) { return null; }
        const open = src.indexOf('[', m.index + m[0].length);
        if (open === -1) { return null; }
        let depth = 0;
        let quote = null;                      // "'" or '"' while inside a PHP string
        for (let i = open; i < src.length; i++) {
            const c = src[i];
            if (quote) {
                if (c === '\\') { i++; continue; }    // skip the escaped character
                if (c === quote) { quote = null; }
                continue;
            }
            if (c === "'" || c === '"') { quote = c; continue; }
            if (c === '[') { depth++; continue; }
            if (c === ']') {
                depth--;
                if (depth === 0) { return src.slice(open + 1, i); }
            }
        }
        return null;                           // unbalanced — report "not found", loudly
    };
    /* The anchor deliberately EXCLUDES the opening bracket of the literal:
       `$books[]` has its own (empty) bracket pair, which the scan must not
       mistake for the array's. */
    const rowLiteral = phpArrayLiteralAfter(liBlock, /\$books\[\]\s*=\s*/);
    const serverFields = rowLiteral !== null
        ? Array.from(rowLiteral.matchAll(/'(\w+)'\s*=>/g)).map((m) => m[1]).sort()
        : [];
    const clientFields = Array.from(new Set(
        Array.from(metaSrc.matchAll(/\bb\.(\w+)\b/g)).map((m) => m[1])
    )).sort();
    assert(serverFields.length > 0,
        'the songbook row literal was found in api2.php (parsed: ' + JSON.stringify(serverFields) + ')');
    assert(serverFields.join(',') === clientFields.join(','),
        'server row fields ' + JSON.stringify(serverFields)
        + ' are exactly the fields metadata-tab.js reads ' + JSON.stringify(clientFields));

    /* ---- 9. the catalogue itself: an EMPTY songbook is offerable ---------- */

    /* #1679 A2, second half. Sections 1 and 7 inject `getSongbooks` as a stub, so
     * they pin the tab's BEHAVIOUR and say nothing about where the list comes
     * from — and "where it comes from" is the whole finding. The v2 sidebar used
     * to derive the list from the loaded song index, which cannot contain a book
     * with ZERO songs, so a curator who had just created a songbook could not
     * move the first song into it. A stub answering [{CP},{MP}] is green either
     * way; only the real module can be wrong about this.
     *
     * Both properties are asserted against the real sidebar:
     *   (a) a book present in load_index's `songbooks` but in NO song reaches
     *       getSongbooks();
     *   (b) the answer is not frozen at mount — before the index resolves it is
     *       the index-derived fallback, after it the catalogue.
     * Plus the contrast that keeps the fix honest: the sidebar's own FILTER
     * dropdown must still NOT offer the empty book, because filtering the list on
     * screen by a book with no songs can only ever show nothing. Same data,
     * different question — a change that pointed both at the catalogue would pass
     * (a) and (b) while making the filter useless, and this catches it. */
    console.log('\n--- 9. an empty songbook is offerable, and the list is not frozen at mount ---');
    const { mountSidebar } = await import(pathToFileURL(path.join(
        __dirname, '..', 'appWeb', 'public_html', 'manage', 'editor', 'v2', 'sidebar.js')).href);

    const dom4 = new JSDOM('<!doctype html><html><body><div id="root4"></div></body></html>');
    const c4 = dom4.window.document.getElementById('root4');
    global.window = dom4.window; global.document = dom4.window.document;

    let releaseIndex4;
    const indexLanded = new Promise((r) => { releaseIndex4 = r; });
    const sidebar = mountSidebar(c4, {
        api: {
            loadIndex: () => indexLanded.then(() => ({
                songs: [
                    { id: 'MP-0041', number: 41, title: 'Amazing Grace', songbook: 'MP', songbookName: 'Mission Praise' },
                    { id: 'CP-0007', number: 7, title: 'Abide With Me', songbook: 'CP', songbookName: 'Common Praise' },
                ],
                /* EMPTY is in the catalogue and in no song — the whole point. */
                songbooks: [
                    { abbr: 'CP', name: 'Common Praise' },
                    { abbr: 'MP', name: 'Mission Praise' },
                    { abbr: 'EMPTY', name: 'Brand New Book' },
                ],
            })),
        },
        toast: () => {},
    });

    const beforeIndex = sidebar.getSongbooks().map((b) => b.abbr).sort().join(',');
    assert(beforeIndex === '', 'before the index resolves the sidebar offers nothing (no frozen stale list)');

    releaseIndex4();
    await sidebar.whenLoaded();
    await flush(10);

    const afterIndex = sidebar.getSongbooks().map((b) => b.abbr).sort().join(',');
    assert(afterIndex !== beforeIndex,
        'the songbook list is re-answered after the index lands, not frozen at mount'
        + ' (before "' + beforeIndex + '", after "' + afterIndex + '")');
    assert(afterIndex === 'CP,EMPTY,MP',
        'a songbook with NO songs is offered as a move target (got ' + afterIndex + ')');

    const filterOptions = Array.from(c4.querySelectorAll('select option'))
        .map((o) => o.value).filter((v) => v !== '' && v !== 'title' && v !== 'number' && v !== 'songbook');
    assert(!filterOptions.includes('EMPTY'),
        'but the sidebar FILTER still omits it — filtering by a book with no songs shows nothing'
        + ' (options: ' + JSON.stringify(filterOptions) + ')');

    /* End to end: that same handle, wired the way editor2.php wires it
       (`getSongbooks: () => sidebar.getSongbooks()`), puts the empty book in the
       Metadata tab's <select>. Sections 1 and 7 stop one step short of this —
       they prove the tab renders whatever it is HANDED, never that what the shell
       hands it can contain an empty book. Both halves have to hold for the move
       to be possible, and they live in different files (rule #35). */
    const host4 = dom4.window.document.createElement('div');
    dom4.window.document.body.appendChild(host4);
    const down4 = mountMetadataTab(host4, {
        store: makeStore({ song: { SongbookAbbr: 'MP', Number: 41 } }),
        api,
        songId: 'MP-0041',
        toast: () => {},
        getSongbooks: () => sidebar.getSongbooks(),
        whenSongbooksReady: () => sidebar.whenLoaded(),
    });
    await flush(10);
    const tabOptions = Array.from(host4.querySelectorAll('#meta-songbook option'))
        .map((o) => o.value).sort().join(',');
    assert(tabOptions === 'CP,EMPTY,MP',
        'and the Metadata tab, wired to the real sidebar, offers it too (got ' + tabOptions + ')');
    down4();
    global.window = window; global.document = window.document;

    teardown();

    console.log('\n' + checks + ' assertion(s), ' + failures + ' failure(s).');
    if (failures > 0) { process.exit(1); }
    console.log('All v2 songbook-move UI assertions passed.');
}

main().catch((e) => { console.error(e); process.exit(1); });
