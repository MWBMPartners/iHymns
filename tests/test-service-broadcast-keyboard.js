/**
 * tests/test-service-broadcast-keyboard.js — keyboard + ARIA guard for
 * js/modules/service-broadcast.js's song picker (#1594 part 2, call site
 * #5 — the LIVE service broadcaster console).
 *
 * ELI5: before this migration, Enter in the song picker always broadcast
 * whatever song happened to render FIRST, no matter how far you'd
 * scrolled with the mouse — there were no arrow keys and no ARIA at all.
 * This is the most safety-sensitive of the eight migrated call sites (it
 * drives what a live congregation sees), so the migration is
 * deliberately conservative: arrows + ARIA + Escape are added, but NOT
 * Tab-commit (see the module's own doc-comment for the reasoning), and
 * the pre-existing "Enter broadcasts the first result" fallback is
 * proven UNCHANGED when no arrow key has been touched.
 *
 * Pass an alternate module path as argv[2] to point this at a different
 * copy (used to prove the "new" assertions fail against the
 * pre-migration file).
 *
 * @see appWeb/public_html/js/modules/service-broadcast.js
 * @see appWeb/public_html/js/modules/combobox-a11y.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'service-broadcast.js');
const COMBOBOX_MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'combobox-a11y.js');
const MODULE_PATH = process.argv[2] ? path.resolve(process.argv[2]) : DEFAULT_MODULE_PATH;

let failures = 0;
let checks = 0;
function assert(cond, label) {
    checks++;
    if (cond) {
        console.log('  PASS  ' + label);
    } else {
        failures++;
        console.log('  FAIL  ' + label);
    }
}

function keyEvent(win, key) {
    return new win.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
}

async function flush(ms) {
    await new Promise((resolve) => setTimeout(resolve, ms == null ? 10 : ms));
}

async function main() {
    const dom = new JSDOM('<!doctype html><html><body><div id="mount"></div></body></html>',
        { runScripts: 'dangerously', url: 'https://example.test/manage/service-projection.php' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;

    const comboboxSrc = fs.readFileSync(COMBOBOX_MODULE_PATH, 'utf8');
    const scriptEl = window.document.createElement('script');
    scriptEl.textContent = comboboxSrc;
    window.document.head.appendChild(scriptEl);

    const { ServiceBroadcaster } = await import(pathToFileURL(MODULE_PATH).href);

    const SONGS = [
        { id: 'MP-1', title: 'Amazing Grace', number: 1, songbook: 'MP', songbookName: 'Mission Praise' },
        { id: 'MP-2', title: 'Amazing Love', number: 2, songbook: 'MP', songbookName: 'Mission Praise' },
        { id: 'MP-3', title: 'Amazing Day', number: 3, songbook: 'MP', songbookName: 'Mission Praise' },
    ];
    const broadcastCalls = [];
    const apiCall = async (action, opts) => {
        if (action === 'songs_index') { return { songs: SONGS }; }
        if (action === 'song_detail') {
            const id = (opts.query || '').replace('&id=', '');
            const s = SONGS.find((x) => x.id === decodeURIComponent(id));
            return { song: { id: s.id, title: s.title, components: [] } };
        }
        if (action === 'service_broadcast') {
            broadcastCalls.push(opts.body.songId);
            return { ok: true };
        }
        return {};
    };

    const mount = window.document.getElementById('mount');
    const sb = new ServiceBroadcaster({ apiCall, sessionId: 1, mount });
    await sb.init();

    const search = mount.querySelector('.sb-picker input[type="search"]');
    const results = mount.querySelector('.sb-results');
    assert(!!search && !!results, 'picker search input + results list mounted');

    console.log('\n--- Opening the picker renders all 3 songs (empty query) ---');
    sb._togglePicker();
    let rows = () => Array.from(results.querySelectorAll('[data-song-id]'));
    assert(rows().length === 3, 'picker shows 3 songs (got ' + rows().length + ')');

    console.log('\n--- ARIA combobox wiring (new) ---');
    assert(search.getAttribute('role') === 'combobox', 'search input has role="combobox"');
    assert(results.getAttribute('role') === 'listbox', 'results list has role="listbox"');
    assert(rows().every((el) => el.getAttribute('role') === 'option'), 'every row has role="option"');
    assert(rows().every((el) => el.getAttribute('aria-selected') === 'false'),
        'no row is aria-selected before any arrow key (matches the "Enter picks first" fallback staying live)');

    console.log('\n--- Enter with NOTHING highlighted still broadcasts the FIRST result (pre-existing behaviour, unchanged) ---');
    const enterEv1 = keyEvent(window, 'Enter');
    search.dispatchEvent(enterEv1);
    await flush(20);
    assert(enterEv1.defaultPrevented, 'Enter calls preventDefault()');
    assert(broadcastCalls[broadcastCalls.length - 1] === 'MP-1', 'Enter with no highlight broadcasts the FIRST rendered song (MP-1) — unchanged');

    console.log('\n--- ArrowDown navigates (previously did nothing at all) ---');
    // Enter above committed via selectSong(), which closes the picker
    // (_closePicker()) — reopen once, explicitly, rather than assuming
    // prior state.
    sb._togglePicker();
    assert(!mount.querySelector('.sb-picker').hidden, 'picker re-opened ahead of the ArrowDown check');
    const downEv = keyEvent(window, 'ArrowDown');
    search.dispatchEvent(downEv);
    search.dispatchEvent(keyEvent(window, 'ArrowDown')); // now on row 1 ("Amazing Love")
    assert(downEv.defaultPrevented, 'ArrowDown calls preventDefault()');
    assert(rows()[1].getAttribute('aria-selected') === 'true', 'ArrowDown x2 highlighted row 1');
    assert(search.getAttribute('aria-activedescendant') === rows()[1].id, 'aria-activedescendant tracks row 1');

    console.log('\n--- Home / End (new) ---');
    search.dispatchEvent(keyEvent(window, 'End'));
    assert(rows()[2].getAttribute('aria-selected') === 'true', 'End jumped to the last row');
    search.dispatchEvent(keyEvent(window, 'Home'));
    assert(rows()[0].getAttribute('aria-selected') === 'true', 'Home jumped back to the first row');

    console.log('\n--- Enter with a DIFFERENT row highlighted broadcasts THAT row (new capability) ---');
    search.dispatchEvent(keyEvent(window, 'ArrowDown'));
    search.dispatchEvent(keyEvent(window, 'ArrowDown')); // highlight -> row 2 ("Amazing Day", MP-3)
    broadcastCalls.length = 0;
    const enterEv2 = keyEvent(window, 'Enter');
    search.dispatchEvent(enterEv2);
    await flush(20);
    assert(broadcastCalls[broadcastCalls.length - 1] === 'MP-3', 'Enter broadcast the HIGHLIGHTED song (MP-3), not the first one');

    console.log('\n--- Tab is deliberately NOT wired to commit (conservative — live-service safety) ---');
    // Enter above closed the picker again (selectSong -> _closePicker()).
    sb._togglePicker();
    assert(!mount.querySelector('.sb-picker').hidden, 'picker re-opened ahead of the Tab check');
    search.dispatchEvent(keyEvent(window, 'ArrowDown')); // highlight row 0
    broadcastCalls.length = 0;
    const tabEv = keyEvent(window, 'Tab');
    search.dispatchEvent(tabEv);
    await flush(20);
    assert(!tabEv.defaultPrevented, 'Tab is not preventDefault()-ed (never was — no keyboard trap either way)');
    assert(broadcastCalls.length === 0, 'Tab did NOT trigger a broadcast — deliberate conservative choice for this live-service console');

    console.log('\n--- Escape closes the picker (new) ---');
    assert(!mount.querySelector('.sb-picker').hidden, 'picker is open ahead of the Escape check');
    search.dispatchEvent(keyEvent(window, 'Escape'));
    assert(mount.querySelector('.sb-picker').hidden, 'Escape closed the picker');

    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
