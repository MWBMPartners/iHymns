/**
 * tests/test-search-autocomplete-aria.js — ARIA-only guard for
 * js/modules/search.js's header autocomplete (#1594 part 2, call site
 * #7 — the PUBLIC header search typeahead).
 *
 * ELI5: unlike the other seven #1594 part-2 call sites, this one's
 * Arrow/Enter/Escape keyboard handling and scrollIntoView already
 * worked — the only gap was ARIA (the "active" highlight was a CSS
 * class only, invisible to assistive tech). This test proves BOTH
 * halves: the pre-existing keyboard behaviour is UNCHANGED, and the new
 * ARIA attributes track it correctly.
 *
 * Pass an alternate module path as argv[2] to point this at a different
 * copy (used to prove the ARIA assertions fail against the
 * pre-migration file — that scratch copy needs sibling ../constants.js
 * and ../utils/{html,text}.js alongside it, since search.js imports
 * them by relative path).
 *
 * @see appWeb/public_html/js/modules/search.js
 * @see appWeb/public_html/js/modules/combobox-a11y.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'search.js');
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

async function main() {
    const html = '<!doctype html><html><body>' +
        '<div class="input-group"><input id="search-input" type="text"></div>' +
        '</body></html>';
    const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.URL = window.URL;

    const comboboxSrc = fs.readFileSync(COMBOBOX_MODULE_PATH, 'utf8');
    const scriptEl = window.document.createElement('script');
    scriptEl.textContent = comboboxSrc;
    window.document.head.appendChild(scriptEl);

    /* jsdom doesn't implement scrollIntoView at all (a known gap —
       place-search.js's own scrollActiveIntoView() wraps its call in a
       try/catch specifically because of this). search.js's
       _handleAutocompleteKeydown() calls it unguarded — pre-existing,
       untouched by this migration — so stub it here the same way a real
       browser's implementation would exist. */
    window.HTMLElement.prototype.scrollIntoView = () => {};

    const SONGS = [
        { id: 'MP-1', title: 'Amazing Grace', number: 1, songbook: 'MP' },
        { id: 'MP-2', title: 'Amazing Love', number: 2, songbook: 'MP' },
    ];
    window.fetch = async () => ({ ok: true, json: async () => ({ suggestions: SONGS }) });
    global.fetch = window.fetch;

    const { Search } = await import(pathToFileURL(MODULE_PATH).href);
    const app = { config: { apiUrl: '/api' }, router: null };
    const search = new Search(app);
    const input = window.document.getElementById('search-input');
    /* _showAutocomplete() guards against a stale/cleared query by
       comparing input.value to the query it was called with — set it
       first, matching what really happens after the 'input' event. */
    input.value = 'Amazing';

    await search._showAutocomplete(input, 'Amazing');
    const parent = input.closest('.input-group');
    const dropdown = parent.querySelector('.search-autocomplete');
    const items = () => Array.from(dropdown.querySelectorAll('.search-autocomplete-item'));

    console.log('\n--- Dropdown renders (pre-existing behaviour) ---');
    assert(!!dropdown, 'dropdown rendered');
    assert(items().length === 2, 'dropdown has 2 suggestion rows (got ' + items().length + ')');
    assert(items()[0].classList.contains('active'), 'row 0 has the pre-existing .active class (unchanged)');

    console.log('\n--- ARIA wiring (new) ---');
    assert(input.getAttribute('role') === 'combobox', 'input has role="combobox"');
    assert(dropdown.getAttribute('role') === 'listbox', 'dropdown has role="listbox" (was already true pre-migration)');
    assert(items().every((el) => el.getAttribute('role') === 'option'), 'every row has role="option" (was already true pre-migration)');
    assert(items()[0].getAttribute('aria-selected') === 'true', 'row 0 is aria-selected="true"');
    assert(items()[1].getAttribute('aria-selected') === 'false', 'row 1 carries explicit aria-selected="false"');
    assert(input.getAttribute('aria-activedescendant') === items()[0].id, 'aria-activedescendant tracks row 0');
    assert(input.getAttribute('aria-controls') === dropdown.id, 'aria-controls points at the dropdown id');

    console.log('\n--- ArrowDown still moves the highlight (pre-existing behaviour, unchanged) ---');
    const downEv = keyEvent(window, 'ArrowDown');
    const consumed = search._handleAutocompleteKeydown(downEv, input);
    assert(consumed === true, '_handleAutocompleteKeydown reports the key as consumed (unchanged)');
    assert(downEv.defaultPrevented, 'ArrowDown calls preventDefault() (unchanged)');
    assert(items()[1].classList.contains('active'), 'ArrowDown moved the pre-existing .active class to row 1 (unchanged)');
    assert(items()[1].getAttribute('aria-selected') === 'true', 'ArrowDown ALSO updated aria-selected on row 1 (new)');
    assert(input.getAttribute('aria-activedescendant') === items()[1].id, 'aria-activedescendant now tracks row 1 (new)');

    console.log('\n--- Escape still closes the dropdown (pre-existing behaviour, unchanged) ---');
    const escEv = keyEvent(window, 'Escape');
    const escConsumed = search._handleAutocompleteKeydown(escEv, input);
    assert(escConsumed === true, 'Escape reports consumed (unchanged)');
    assert(!parent.querySelector('.search-autocomplete'), 'Escape removed the dropdown (unchanged)');
    assert(!input.hasAttribute('aria-activedescendant'), 'aria-activedescendant cleared once the dropdown is gone (new)');

    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
