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

    teardown();

    console.log('\n' + checks + ' assertion(s), ' + failures + ' failure(s).');
    if (failures > 0) { process.exit(1); }
    console.log('All v2 songbook-move UI assertions passed.');
}

main().catch((e) => { console.error(e); process.exit(1); });
