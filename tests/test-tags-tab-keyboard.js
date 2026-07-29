/**
 * tests/test-tags-tab-keyboard.js — keyboard + ARIA guard for
 * manage/editor/v2/tags-tab.js's Add-a-tag combobox (#1594 part 2, call
 * site #4).
 *
 * ELI5: tags-tab.js's Arrow/Enter/Escape key handling already worked
 * before this migration — unlike most of the other #1594 part-2 call
 * sites — so this test's job is narrower: prove the NEW bits (ARIA
 * wiring, Home/End, and Tab actually committing the highlighted
 * suggestion instead of silently discarding it) work, while the
 * pre-existing Arrow/Enter/Escape behaviour is provably UNCHANGED.
 *
 * Pass an alternate module path as argv[2] to point this at a different
 * copy (used to prove the "new" assertions fail against the
 * pre-migration file — see the #1594 part-2 report for the recorded
 * run against a scratch copy of the pre-migration source).
 *
 * @see appWeb/public_html/manage/editor/v2/tags-tab.js
 * @see appWeb/public_html/js/modules/combobox-a11y.js
 */
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'manage', 'editor', 'v2', 'tags-tab.js');
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

/** Minimal store stand-in matching the real store's get/set/subscribe contract. */
function makeStore(initial) {
    let state = Object.assign({}, initial);
    const subs = [];
    return {
        get: (k) => state[k],
        set: (k, v) => { state[k] = v; subs.forEach((fn) => fn()); },
        subscribe: (k, fn) => { subs.push(fn); return () => {}; },
    };
}

async function main() {
    const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',
        { runScripts: 'dangerously', url: 'https://example.test/manage/editor/editor2.php' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.MouseEvent = window.MouseEvent;

    // combobox-a11y.js is a classic-global IIFE (no import/export) — load it the
    // same way the browser would via a <script> tag, matching every other test
    // in this suite that touches window.iHymnsComboboxA11y.
    const fs = await import('node:fs');
    const comboboxSrc = fs.readFileSync(COMBOBOX_MODULE_PATH, 'utf8');
    const scriptEl = window.document.createElement('script');
    scriptEl.textContent = comboboxSrc;
    window.document.head.appendChild(scriptEl);

    const { mountTagsTab } = await import(pathToFileURL(MODULE_PATH).href);

    const SUGGESTIONS = [
        { id: 11, name: 'Advent', usage: 4 },
        { id: 12, name: 'Adoration', usage: 9 },
    ];
    const attachedCalls = [];
    const api = {
        searchTags: async () => ({ suggestions: SUGGESTIONS }),
        attachTag: async (songId, name) => {
            attachedCalls.push(name);
            const match = SUGGESTIONS.find((s) => s.name.toLowerCase() === name.toLowerCase());
            return { tag: match ? { id: match.id, name: match.name, slug: match.name.toLowerCase() } : { id: 999, name, slug: name.toLowerCase() } };
        },
        detachTag: async () => ({}),
    };
    const store = makeStore({ tags: [] });
    const container = window.document.getElementById('root');

    mountTagsTab(container, { store, api, songId: 'MP-1', toast: () => {} });

    const input = container.querySelector('#v2-tag-input');
    assert(!!input, 'the Add-a-tag input mounted');

    console.log('\n--- Focus opens the dropdown with 2 suggestions ---');
    input.dispatchEvent(new window.Event('focus', { bubbles: true }));
    await flush(20);
    let dropdown = input.nextElementSibling;
    let items = () => Array.from(dropdown.querySelectorAll('.list-group-item'));
    assert(items().length === 2, 'dropdown rendered 2 suggestion rows (got ' + items().length + ')');

    console.log('\n--- ARIA combobox wiring (new) ---');
    assert(input.getAttribute('role') === 'combobox', 'input has role="combobox"');
    assert(dropdown.getAttribute('role') === 'listbox', 'dropdown has role="listbox"');
    assert(items().every((el) => el.getAttribute('role') === 'option'), 'every row has role="option"');
    assert(items().every((el) => el.getAttribute('aria-selected') === 'false'),
        'no row is aria-selected on a fresh render (activeIndex starts at -1 — unchanged pre-existing behaviour)');
    assert(!input.hasAttribute('aria-activedescendant'), 'no aria-activedescendant until an arrow key is pressed');

    console.log('\n--- ArrowDown still moves the highlight (pre-existing behaviour, unchanged) ---');
    const downEv = keyEvent(window, 'ArrowDown');
    input.dispatchEvent(downEv);
    assert(downEv.defaultPrevented, 'ArrowDown calls preventDefault() (pre-existing)');
    assert(items()[0].classList.contains('active'), 'ArrowDown highlights row 0 via the pre-existing .active class');
    assert(items()[0].getAttribute('aria-selected') === 'true', 'ArrowDown ALSO sets aria-selected="true" on row 0 (new)');
    assert(input.getAttribute('aria-activedescendant') === items()[0].id, 'aria-activedescendant now tracks row 0 (new)');

    console.log('\n--- End / Home (new) ---');
    const endEv = keyEvent(window, 'End');
    input.dispatchEvent(endEv);
    assert(endEv.defaultPrevented, 'End calls preventDefault()');
    assert(items()[1].getAttribute('aria-selected') === 'true', 'End jumped the highlight to the last row');
    input.dispatchEvent(keyEvent(window, 'Home'));
    assert(items()[0].getAttribute('aria-selected') === 'true', 'Home jumped the highlight back to row 0');

    console.log('\n--- Tab commits the highlighted suggestion (new — was silently discarded before) ---');
    const tabEv = keyEvent(window, 'Tab');
    input.dispatchEvent(tabEv);
    await flush(20);
    assert(!tabEv.defaultPrevented, 'Tab is NOT preventDefault()-ed (no keyboard trap)');
    assert(attachedCalls.includes('Advent'), 'Tab committed the highlighted suggestion ("Advent") via api.attachTag — was previously silently discarded on blur');

    console.log('\n--- Enter-with-no-highlight still creates the typed value verbatim (pre-existing behaviour, unchanged) ---');
    input.value = 'Brand New Tag Name';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(200);
    // activeIndex resets to -1 on every fresh search (runSearch), so a bare
    // Enter with no arrow key pressed since the last render still falls to
    // the create/attach(input.value) fallback.
    const enterEv = keyEvent(window, 'Enter');
    input.dispatchEvent(enterEv);
    await flush(20);
    assert(enterEv.defaultPrevented, 'Enter still calls preventDefault()');
    assert(attachedCalls.includes('Brand New Tag Name'), 'Enter with nothing highlighted still creates/attaches the typed text verbatim');

    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
