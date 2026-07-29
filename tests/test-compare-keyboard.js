/**
 * tests/test-compare-keyboard.js — keyboard + ARIA guard for
 * js/modules/compare.js's "Compare With..." song-picker modal (#1594
 * part 2, call site #6).
 *
 * ELI5: before this migration the compare picker was mouse-only — no
 * arrow keys, no Tab-commit, no ARIA. It also lives directly inside a
 * Bootstrap modal (unlike every other #1594 part-2 call site), so this
 * test additionally proves Escape still bubbles up and closes the WHOLE
 * MODAL exactly as it always did — the one place in this consolidation
 * where stopPropagation() on Escape would have been a regression, not a
 * fix.
 *
 * Pass an alternate module path as argv[2] to point this at a different
 * copy (used to prove the "new" assertions fail against the
 * pre-migration file — the pre-migration copy used for that run needs
 * its sibling ../utils/html.js + text.js alongside it since compare.js
 * imports them by relative path; see the #1594 part-2 report for how
 * that scratch copy was laid out).
 *
 * @see appWeb/public_html/js/modules/compare.js
 * @see appWeb/public_html/js/modules/combobox-a11y.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'compare.js');
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
    const dom = new JSDOM('<!doctype html><html><body></body></html>',
        { runScripts: 'dangerously', url: 'https://example.test/song/MP-1' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.URL = window.URL;

    const comboboxSrc = fs.readFileSync(COMBOBOX_MODULE_PATH, 'utf8');
    const scriptEl = window.document.createElement('script');
    scriptEl.textContent = comboboxSrc;
    window.document.head.appendChild(scriptEl);

    // Bootstrap stand-in: a real modal-close listener on the MODAL element
    // (matching how Bootstrap's own Modal component listens for Escape),
    // so this test can prove our new Escape handling still lets it fire.
    let bsHideCalls = 0;
    window.bootstrap = {
        Modal: function (el) {
            this.show = () => {};
            this.hide = () => { bsHideCalls++; el.dispatchEvent(new window.Event('hidden.bs.modal')); };
        },
    };
    // compare.js references the bare (unqualified) `bootstrap` global, as
    // real browser code loading Bootstrap's UMD bundle via <script> does —
    // bridge it the same way `global.window`/`global.document` are bridged
    // above (Node's dynamic import() doesn't share jsdom's own globals).
    global.bootstrap = window.bootstrap;
    // Bootstrap's real modal ALSO listens for keydown 'Escape' on the modal
    // element itself and calls hide() — stand in for that specific mechanism
    // so a regression (our code calling stopPropagation on Escape) would be
    // caught by "modalEscapeSeen stays 0".
    let modalEscapeSeen = 0;

    const SONGS = [
        { id: 'MP-2', title: 'Amazing Love', number: 2, songbook: 'MP', songbookName: 'Mission Praise' },
        { id: 'MP-3', title: 'Amazing Day', number: 3, songbook: 'MP', songbookName: 'Mission Praise' },
    ];
    window.fetch = async (url) => {
        const href = String(url);
        if (href.includes('action=search')) { return { ok: true, json: async () => ({ results: SONGS }) }; }
        // loadComparison() fetches the two song pages as HTML text.
        return { ok: true, text: async () => '<article>stub song page</article>' };
    };
    // Same bare-global bridging concern as `bootstrap` above — compare.js
    // calls unqualified `fetch(url)`, and Node 22 ships its own native
    // global fetch that would otherwise shadow this stub and attempt a
    // REAL network request.
    global.fetch = window.fetch;

    const { Compare } = await import(pathToFileURL(MODULE_PATH).href);
    const app = { config: { apiUrl: '/api' }, showToast: () => {} };
    const compare = new Compare(app);

    compare.showPickerModal('MP-1');
    const modal = window.document.getElementById('compare-picker-modal');
    assert(!!modal, 'picker modal mounted');
    modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') { modalEscapeSeen++; } });

    const input = modal.querySelector('#compare-search-input');
    const results = modal.querySelector('#compare-search-results');

    console.log('\n--- Search resolves with 2 candidates ---');
    input.value = 'Amazing';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(320); // 300ms debounce + fetch microtasks

    let rows = () => Array.from(results.querySelectorAll('.compare-pick'));
    assert(rows().length === 2, 'results rendered 2 rows (got ' + rows().length + ')');

    console.log('\n--- ARIA combobox wiring (new) ---');
    assert(input.getAttribute('role') === 'combobox', 'input has role="combobox"');
    assert(results.getAttribute('role') === 'listbox', 'results has role="listbox"');
    assert(rows().every((el) => el.getAttribute('role') === 'option'), 'every row has role="option"');
    assert(rows().every((el) => el.getAttribute('aria-selected') === 'false'), 'no row aria-selected before any arrow key');

    console.log('\n--- ArrowDown navigates (previously did nothing) ---');
    const downEv = keyEvent(window, 'ArrowDown');
    input.dispatchEvent(downEv);
    assert(downEv.defaultPrevented, 'ArrowDown calls preventDefault()');
    assert(rows()[0].getAttribute('aria-selected') === 'true', 'ArrowDown highlighted row 0');
    assert(input.getAttribute('aria-activedescendant') === rows()[0].id, 'aria-activedescendant tracks row 0');

    console.log('\n--- End / Home (new) ---');
    input.dispatchEvent(keyEvent(window, 'End'));
    assert(rows()[1].getAttribute('aria-selected') === 'true', 'End jumped to the last row');
    input.dispatchEvent(keyEvent(window, 'Home'));
    assert(rows()[0].getAttribute('aria-selected') === 'true', 'Home jumped back to the first row');

    console.log('\n--- Tab commits the highlighted row (new — was mouse-only before) ---');
    const tabEv = keyEvent(window, 'Tab');
    input.dispatchEvent(tabEv);
    assert(!tabEv.defaultPrevented, 'Tab is not preventDefault()-ed (no keyboard trap)');
    assert(bsHideCalls === 1, 'Tab committed row 0 -> loadComparison flow called bsModal.hide()');
    await flush(30); // let the (stubbed) loadComparison() background fetch settle before continuing

    console.log('\n--- Escape still bubbles to close the WHOLE MODAL (the one site where stopPropagation would be a regression) ---');
    // Fresh modal — the one above hid itself via the Tab commit.
    modalEscapeSeen = 0;
    bsHideCalls = 0;
    compare.showPickerModal('MP-1');
    const modal2 = window.document.getElementById('compare-picker-modal');
    modal2.addEventListener('keydown', (e) => { if (e.key === 'Escape') { modalEscapeSeen++; } });
    const input2 = modal2.querySelector('#compare-search-input');
    input2.value = 'Amazing';
    input2.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(320);
    const escEv = keyEvent(window, 'Escape');
    input2.dispatchEvent(escEv);
    assert(modalEscapeSeen === 1, 'Escape STILL bubbles to the modal-level listener (stopEscapePropagation:false honoured)');

    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
