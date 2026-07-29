/**
 * tests/test-combobox-a11y.js — unit test for the shared combobox
 * keyboard + ARIA helper introduced in the #1594 part-2 consolidation.
 *
 * ELI5: builds a minimal, generic "list of rows under a text box" and
 * drives it with `window.iHymnsComboboxA11y` the same way every one of
 * the eight migrated call sites now does, proving the extracted helper
 * itself is correct BEFORE trusting any site-specific wiring on top of
 * it. Site-specific migrations (renderEntityPicker.js etc.) get their
 * own test files that load the real production file.
 *
 * This module is brand new (no pre-#1594-part-2 "before" state exists to
 * prove failing) — unlike the site-migration tests, there's nothing to
 * regress against. It is exercised here directly instead.
 *
 * @see appWeb/public_html/js/modules/combobox-a11y.js
 * @see tests/test-place-search-keyboard.js (the pattern this follows)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'combobox-a11y.js');

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

function main() {
    const html = '<!doctype html><html><body>' +
        '<div id="host">' +
        '  <input id="in" type="text">' +
        '  <div id="panel"></div>' +
        '</div>' +
        '</body></html>';
    const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://example.test/manage/some-page.php' });
    const { window } = dom;

    const src = fs.readFileSync(MODULE_PATH, 'utf8');
    const scriptEl = window.document.createElement('script');
    scriptEl.textContent = src;
    window.document.head.appendChild(scriptEl);

    assert(typeof window.iHymnsComboboxA11y === 'object'
        && typeof window.iHymnsComboboxA11y.handleComboboxKeydown === 'function'
        && typeof window.iHymnsComboboxA11y.applyComboboxAria === 'function',
        'window.iHymnsComboboxA11y exposes both functions (classic global export intact)');

    const input = window.document.getElementById('in');
    const panel = window.document.getElementById('panel');
    const host = window.document.getElementById('host');

    /* Minimal stand-in for a real call site: three rows, re-rendered on
       every highlight change exactly like place-search.js's renderPanel()
       and every migrated site's own render() do. */
    let rows = ['Alpha', 'Bravo', 'Charlie'];
    let activeIndex = 0;
    let committed = [];
    let closed = 0;
    let panelOpen = true;

    function render() {
        panel.innerHTML = '';
        rows.forEach((label) => {
            const item = window.document.createElement('div');
            item.className = 'list-group-item';
            item.textContent = label;
            panel.appendChild(item);
        });
        const items = Array.from(panel.querySelectorAll('.list-group-item'));
        window.iHymnsComboboxA11y.applyComboboxAria({
            input, panel, items, activeIndex, idPrefix: 'test-combo',
        });
    }
    render();

    function getItems() { return Array.from(panel.querySelectorAll('.list-group-item')); }

    const cfg = {
        isOpen: () => panelOpen,
        getItems,
        getActiveIndex: () => activeIndex,
        setActiveIndex: (i) => { activeIndex = i; },
        render,
        onCommit: (i, el) => { committed.push({ i, label: el.textContent }); panelOpen = false; },
        onClose: () => { closed++; panelOpen = false; },
    };

    console.log('\n--- Initial ARIA state ---');
    let items = getItems();
    assert(items.length === 3, 'panel rendered 3 rows');
    assert(items.every((el) => el.getAttribute('role') === 'option'), 'every row tagged role="option"');
    assert(panel.getAttribute('role') === 'listbox', 'panel tagged role="listbox"');
    assert(input.getAttribute('role') === 'combobox', 'input tagged role="combobox"');
    assert(input.getAttribute('aria-controls') === panel.id, 'aria-controls points at the panel id');
    assert(items[0].getAttribute('aria-selected') === 'true', 'row 0 starts aria-selected=true (activeIndex=0)');
    assert(items[1].getAttribute('aria-selected') === 'false' && items[2].getAttribute('aria-selected') === 'false',
        'inactive rows carry explicit aria-selected="false"');
    assert(input.getAttribute('aria-activedescendant') === items[0].id, 'aria-activedescendant tracks row 0');

    console.log('\n--- ArrowDown / ArrowUp move + clamp ---');
    let ev = keyEvent(window, 'ArrowDown');
    let handled = window.iHymnsComboboxA11y.handleComboboxKeydown(ev, cfg);
    assert(handled === true, 'ArrowDown reports handled=true');
    assert(ev.defaultPrevented, 'ArrowDown calls preventDefault()');
    assert(activeIndex === 1, 'ArrowDown moved activeIndex to 1');
    items = getItems();
    assert(items[1].getAttribute('aria-selected') === 'true', 're-render reflects the new highlight');

    window.iHymnsComboboxA11y.handleComboboxKeydown(keyEvent(window, 'ArrowDown'), cfg);
    window.iHymnsComboboxA11y.handleComboboxKeydown(keyEvent(window, 'ArrowDown'), cfg); // clamp attempt past the end
    assert(activeIndex === 2, 'ArrowDown clamps at the last index (2), never throws/overflows');

    window.iHymnsComboboxA11y.handleComboboxKeydown(keyEvent(window, 'ArrowUp'), cfg);
    assert(activeIndex === 1, 'ArrowUp moved activeIndex back to 1');
    window.iHymnsComboboxA11y.handleComboboxKeydown(keyEvent(window, 'ArrowUp'), cfg);
    window.iHymnsComboboxA11y.handleComboboxKeydown(keyEvent(window, 'ArrowUp'), cfg); // clamp attempt below 0
    assert(activeIndex === 0, 'ArrowUp clamps at index 0, never goes negative');

    console.log('\n--- Home / End ---');
    const homeEv = keyEvent(window, 'End');
    window.iHymnsComboboxA11y.handleComboboxKeydown(homeEv, cfg);
    assert(activeIndex === 2, 'End jumped straight to the last row');
    assert(homeEv.defaultPrevented, 'End calls preventDefault()');
    window.iHymnsComboboxA11y.handleComboboxKeydown(keyEvent(window, 'Home'), cfg);
    assert(activeIndex === 0, 'Home jumped straight to the first row');

    console.log('\n--- Enter commits the highlighted row ---');
    activeIndex = 1;
    render();
    const enterEv = keyEvent(window, 'Enter');
    handled = window.iHymnsComboboxA11y.handleComboboxKeydown(enterEv, cfg);
    assert(handled === true && enterEv.defaultPrevented, 'Enter (row highlighted) is handled + preventDefault()ed');
    assert(committed.length === 1 && committed[0].label === 'Bravo', 'Enter committed the HIGHLIGHTED row (index 1), not index 0');

    console.log('\n--- Tab commits but is never preventDefault()ed (no keyboard trap) ---');
    committed = [];
    panelOpen = true;
    activeIndex = 2;
    render();
    const tabEv = keyEvent(window, 'Tab');
    handled = window.iHymnsComboboxA11y.handleComboboxKeydown(tabEv, cfg);
    assert(handled === false, 'Tab always reports handled=false (it never "consumes" the key)');
    assert(!tabEv.defaultPrevented, 'Tab is NOT preventDefault()-ed — WCAG 2.1.2 no-keyboard-trap');
    assert(committed.length === 1 && committed[0].label === 'Charlie', 'Tab committed the highlighted row (Charlie) — not silently discarded');

    console.log('\n--- Escape closes + stopPropagation ---');
    panelOpen = true;
    let hostEscapeSeen = 0;
    host.addEventListener('keydown', (e) => { if (e.key === 'Escape') hostEscapeSeen++; });
    const escEv = keyEvent(window, 'Escape');
    input.dispatchEvent(escEv); // real dispatch (not direct call) so bubbling can be observed
    // dispatch alone won't run our handler (nothing wired it to 'keydown' — this is
    // a pure-function unit test) — call it explicitly on the same event object:
    handled = window.iHymnsComboboxA11y.handleComboboxKeydown(escEv, cfg);
    assert(handled === true, 'Escape (panel open) reports handled=true');
    assert(escEv.defaultPrevented, 'Escape calls preventDefault()');
    assert(closed === 1, 'Escape called onClose()');
    // stopPropagation() was called synchronously inside handleComboboxKeydown, on
    // an event that had already finished dispatching (dispatchEvent returned
    // before we called the handler) — so re-verify the propagation CONTRACT the
    // direct way: fire a fresh event through dispatchEvent with a REAL listener
    // that itself invokes handleComboboxKeydown, mirroring how every real call
    // site wires it (input.addEventListener('keydown', onKeydown)).
    input.addEventListener('keydown', function realOnKeydown(e) {
        window.iHymnsComboboxA11y.handleComboboxKeydown(e, cfg);
    });
    panelOpen = true;
    hostEscapeSeen = 0;
    input.dispatchEvent(keyEvent(window, 'Escape'));
    assert(hostEscapeSeen === 0, 'Escape did NOT bubble to the host listener while the panel was open (stopPropagation via a real listener chain)');

    console.log('\n--- isOpen()=false leaves keys untouched (no interference with normal text editing) ---');
    panelOpen = false;
    const arrowWhileClosed = keyEvent(window, 'ArrowDown');
    handled = window.iHymnsComboboxA11y.handleComboboxKeydown(arrowWhileClosed, cfg);
    assert(handled === false, 'ArrowDown while closed is left alone (handled=false)');
    assert(!arrowWhileClosed.defaultPrevented, 'ArrowDown while closed is NOT preventDefault()-ed');

    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main();
