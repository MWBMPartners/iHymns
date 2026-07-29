/**
 * tests/test-render-entity-picker-keyboard.js — keyboard + ARIA guard for
 * manage/includes/renderEntityPicker.js (#1594 part 2, call site #2).
 *
 * ELI5: renderEntityPicker.js is the shared "song / user / organisation"
 * live-search combobox behind manage/restrictions.php. Before this
 * migration it was Escape-only — no arrows, no Home/End, no ARIA at all
 * (not even role="listbox"/"option"), so a keyboard-only or screen-reader
 * curator could not pick a suggestion without a mouse.
 *
 * Each assertion below is written to FAIL against the pre-migration file.
 * Run it against the pre-migration source with:
 *   git stash; node tests/test-render-entity-picker-keyboard.js; git stash pop
 * (or just check the run recorded in the #1594 part-2 report) — a test
 * never observed failing protects nothing (the exact lesson
 * test-place-search-keyboard.js's own doc-comment already draws out).
 *
 * @see appWeb/public_html/manage/includes/renderEntityPicker.js
 * @see appWeb/public_html/js/modules/combobox-a11y.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PICKER_MODULE_PATH   = path.join(__dirname, '..', 'appWeb', 'public_html', 'manage', 'includes', 'renderEntityPicker.js');
const COMBOBOX_MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'combobox-a11y.js');

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
    const html = '<!doctype html><html><body>' +
        '<form id="restriction-form">' +
        '  <select data-picker-canonical="#target-id">' +
        '    <option value="song">Song</option>' +
        '  </select>' +
        '  <input type="hidden" id="target-id" name="TargetId">' +
        '  <div data-picker-group-for="#target-id">' +
        '    <div class="rx-picker" data-picker-for="song">' +
        '      <input type="text" class="rx-picker-input" data-picker-source="song">' +
        '      <div class="rx-picker-popover d-none"></div>' +
        '    </div>' +
        '  </div>' +
        '</form>' +
        '</body></html>';

    const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://example.test/manage/restrictions.php' });
    const { window } = dom;

    const CANDIDATES = [
        { id: 101, label: 'Amazing Grace · MP', hint: 'MP-1' },
        { id: 102, label: 'Amazing Love · MP', hint: 'MP-2' },
        { id: 103, label: 'Amazing Day · MP', hint: 'MP-3' },
    ];
    window.fetch = async (url) => {
        return { json: async () => ({ results: CANDIDATES.map((c) => ({ id: c.id, title: c.label, songbook: '' })) }) };
    };

    /* Load the shared a11y helper FIRST — this is a plain classic global
       script in production too (manage/includes/head-libs.php loads it
       ahead of every page's own inline script), so loading it before
       renderEntityPicker.js here matches real page order. */
    const comboboxSrc = fs.readFileSync(COMBOBOX_MODULE_PATH, 'utf8');
    const comboboxScriptEl = window.document.createElement('script');
    comboboxScriptEl.textContent = comboboxSrc;
    window.document.head.appendChild(comboboxScriptEl);

    const pickerSrc = fs.readFileSync(PICKER_MODULE_PATH, 'utf8');
    const pickerScriptEl = window.document.createElement('script');
    pickerScriptEl.textContent = pickerSrc;
    window.document.head.appendChild(pickerScriptEl);

    assert(typeof window.initEntityPickers === 'function',
        'window.initEntityPickers exists (classic global export intact)');

    const form = window.document.getElementById('restriction-form');
    window.initEntityPickers(form);

    const input = form.querySelector('.rx-picker-input');
    const popover = form.querySelector('.rx-picker-popover');
    const hidden = window.document.getElementById('target-id');

    console.log('\n--- Search resolves with 3 candidates ---');
    input.value = 'Amazing';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(220); // 200ms debounce + fetch microtasks

    const rows = Array.from(popover.querySelectorAll('.list-group-item-action'));
    assert(rows.length === 3, 'popover rendered 3 suggestion rows (got ' + rows.length + ')');
    assert(!popover.classList.contains('d-none'), 'popover is visible after search resolves');

    console.log('\n--- ARIA combobox wiring (was entirely absent pre-migration) ---');
    assert(input.getAttribute('role') === 'combobox', 'input has role="combobox"');
    assert(popover.getAttribute('role') === 'listbox', 'popover has role="listbox"');
    assert(rows.every((r) => r.getAttribute('role') === 'option'), 'every row has role="option"');
    assert(rows.every((r) => !!r.id) && new Set(rows.map((r) => r.id)).size === rows.length,
        'every row has a unique id');
    assert(input.getAttribute('aria-activedescendant') === rows[0].id,
        'aria-activedescendant tracks the initial highlight (row 0)');
    assert(rows[0].getAttribute('aria-selected') === 'true', 'row 0 starts aria-selected=true');
    assert(rows[1].getAttribute('aria-selected') === 'false' && rows[2].getAttribute('aria-selected') === 'false',
        'inactive rows carry explicit aria-selected="false"');

    console.log('\n--- ArrowDown moves the highlight (previously did nothing) ---');
    const downEv = keyEvent(window, 'ArrowDown');
    input.dispatchEvent(downEv);
    let rows2 = Array.from(popover.querySelectorAll('.list-group-item-action'));
    assert(downEv.defaultPrevented, 'ArrowDown calls preventDefault() (pre-migration: untouched, no listener at all)');
    assert(rows2[1].getAttribute('aria-selected') === 'true', 'ArrowDown moved the highlight to row 1');

    console.log('\n--- Home / End (previously did nothing) ---');
    const endEv = keyEvent(window, 'End');
    input.dispatchEvent(endEv);
    let rows3 = Array.from(popover.querySelectorAll('.list-group-item-action'));
    assert(endEv.defaultPrevented, 'End calls preventDefault()');
    assert(rows3[2].getAttribute('aria-selected') === 'true', 'End jumped the highlight to the last row');
    const homeEv = keyEvent(window, 'Home');
    input.dispatchEvent(homeEv);
    let rows4 = Array.from(popover.querySelectorAll('.list-group-item-action'));
    assert(rows4[0].getAttribute('aria-selected') === 'true', 'Home jumped the highlight back to row 0');

    console.log('\n--- Enter commits the highlighted row (previously required a mouse click) ---');
    input.dispatchEvent(keyEvent(window, 'ArrowDown')); // highlight -> row 1 ("Amazing Love")
    const enterEv = keyEvent(window, 'Enter');
    input.dispatchEvent(enterEv);
    assert(enterEv.defaultPrevented, 'Enter calls preventDefault() when a row is highlighted');
    assert(hidden.value === '102', 'Enter committed the HIGHLIGHTED row (id 102), not the first row');
    assert(input.value === 'Amazing Love · MP', 'Enter filled the input from the committed row label');
    assert(popover.classList.contains('d-none'), 'Enter closed the popover');

    console.log('\n--- Tab commits the highlighted row (previously silently discarded it) ---');
    hidden.value = '';
    input.value = '';
    input.dataset.canonical = '';
    delete input.dataset.canonical;
    input.value = 'Amazing';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(220);
    input.dispatchEvent(keyEvent(window, 'ArrowDown'));
    input.dispatchEvent(keyEvent(window, 'ArrowDown')); // highlight -> row 2 ("Amazing Day")
    const tabEv = keyEvent(window, 'Tab');
    input.dispatchEvent(tabEv);
    assert(!tabEv.defaultPrevented, 'Tab is NOT preventDefault()-ed (no keyboard trap)');
    assert(hidden.value === '103', 'Tab committed the highlighted row (id 103) instead of discarding it on blur');

    console.log('\n--- Escape still closes the popover ---');
    input.value = 'Amazing';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(220);
    assert(!popover.classList.contains('d-none'), 'popover is open again ahead of the Escape check');
    input.dispatchEvent(keyEvent(window, 'Escape'));
    assert(popover.classList.contains('d-none'), 'Escape still closes the popover (pre-existing behaviour preserved)');

    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
