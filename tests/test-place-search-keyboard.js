/**
 * tests/test-place-search-keyboard.js — keyboard + ARIA guard for #1594
 *
 * ELI5: proves you can drive the location picker with the keyboard alone.
 *
 * WHY THIS EXISTS
 * ---------------
 * `place-search.js` is the ONE shared place typeahead, used by credit-people,
 * works, venues, songbooks, organisations and both editors. The owner reported
 * it was mouse-only. The keys were in fact wired — but three real gaps were:
 *
 *   - Tab silently DISCARDED the highlighted option instead of committing it;
 *   - `aria-activedescendant` was never set, so a screen reader could not tell
 *     which option was active (the highlight was CSS-only, i.e. invisible to
 *     assistive tech — WCAG 2.1 4.1.2);
 *   - Escape did not stopPropagation, so inside the credit-people Bootstrap
 *     offcanvas it closed the WHOLE DRAWER, losing a part-filled form.
 *
 * Each assertion below fails against the pre-#1594 module. Re-prove that if you
 * ever change this file: a test never observed failing protects nothing (the
 * ProPresenter suite passed 54/54 while never exercising the real fetch path).
 *
 * This is the project's FIRST jsdom-backed test; jsdom is a devDependency and
 * never ships. Prior DOM-adjacent tests hand-rolled stubs, which does not scale
 * to a widget this DOM-coupled.
 *
 * @see appWeb/public_html/js/modules/place-search.js
 * @see https://www.w3.org/WAI/ARIA/apg/patterns/combobox/
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'place-search.js');

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
        '<div id="cpDrawer">' +
        '  <input id="place" type="text">' +
        '  <input id="placeId" type="hidden">' +
        '</div>' +
        '</body></html>';

    const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://example.test/manage/credit-people.php' });
    const { window } = dom;

    // --- fake fetch -------------------------------------------------
    // action=search  -> 3 canned candidates
    // action=upsert  -> echoes back a fake tblPlaces.Id
    const CANDIDATES = [
        { display_name: 'Sydney, NSW, Australia', type: 'city', address: { country: 'Australia' } },
        { display_name: 'Sydney, NS, Canada', type: 'city', address: { country: 'Canada' } },
        { display_name: 'Sydney Mines, NS, Canada', type: 'town', address: { country: 'Canada' } },
    ];
    let upsertCalls = 0;
    window.fetch = async (url, opts) => {
        const u = new window.URL(String(url), window.location.href);
        const action = u.searchParams.get('action');
        if (action === 'search') {
            return { ok: true, json: async () => ({ results: CANDIDATES }) };
        }
        if (action === 'upsert') {
            upsertCalls++;
            const body = JSON.parse(opts.body);
            return { ok: true, json: async () => ({ place: { id: 900 + upsertCalls, display_name: body.display_name } }) };
        }
        return { ok: false, status: 404, json: async () => ({}) };
    };

    // --- load the real module as a classic global script ------------
    const src = fs.readFileSync(MODULE_PATH, 'utf8');
    const scriptEl = window.document.createElement('script');
    scriptEl.textContent = src;
    window.document.head.appendChild(scriptEl);

    assert(typeof window.iHymnsPlaceSearch === 'object' && typeof window.iHymnsPlaceSearch.attach === 'function',
        'window.iHymnsPlaceSearch.attach exists (classic global export intact)');

    const input = window.document.getElementById('place');
    const hidden = window.document.getElementById('placeId');
    const drawer = window.document.getElementById('cpDrawer');

    // Bootstrap-offcanvas stand-in: a parent listener that would close
    // the whole drawer on an un-stopped Escape.
    let drawerEscapeSeen = 0;
    drawer.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') drawerEscapeSeen++;
    });

    window.iHymnsPlaceSearch.attach(input, { hiddenIdInput: hidden, minChars: 1, debounceMs: 0 });

    console.log('\n--- Setup ---');
    assert(input.getAttribute('autocomplete') === 'ihymns-place-search',
        'autocomplete set to unrecognised project token (not "off")');
    const panelId = input.getAttribute('aria-controls');
    assert(!!panelId, 'aria-controls set on input, pointing at the panel id');
    assert(!!window.document.getElementById(panelId), 'the aria-controls id resolves to a real panel element');

    // --- trigger a search --------------------------------------------
    console.log('\n--- Search resolves with 3 candidates ---');
    input.value = 'Syd';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(30); // debounce (0ms) + fetch microtasks

    const panel = window.document.getElementById(panelId);
    assert(panel.style.display === 'block', 'panel is open after search resolves');
    const options = Array.from(panel.querySelectorAll('[role="option"]'));
    assert(options.length === 3, 'panel rendered 3 option rows (got ' + options.length + ')');
    assert(options.every((o) => !!o.id), 'every option has a unique id');
    assert(new Set(options.map((o) => o.id)).size === 3, 'option ids are all distinct');
    assert(options[0].getAttribute('aria-selected') === 'true', 'first option starts aria-selected=true (highlight=0)');
    assert(options[1].getAttribute('aria-selected') === 'false' && options[2].getAttribute('aria-selected') === 'false',
        'inactive options carry explicit aria-selected="false"');
    assert(input.getAttribute('aria-activedescendant') === options[0].id,
        'aria-activedescendant tracks the initial highlight (index 0)');

    // --- ArrowDown / ArrowUp ------------------------------------------
    console.log('\n--- ArrowDown / ArrowUp move the highlight ---');
    input.dispatchEvent(keyEvent(window, 'ArrowDown'));
    let opts2 = Array.from(panel.querySelectorAll('[role="option"]'));
    assert(opts2[1].getAttribute('aria-selected') === 'true', 'ArrowDown moved highlight to index 1');
    assert(input.getAttribute('aria-activedescendant') === opts2[1].id,
        'aria-activedescendant updated to index 1 after ArrowDown');

    input.dispatchEvent(keyEvent(window, 'ArrowDown'));
    opts2 = Array.from(panel.querySelectorAll('[role="option"]'));
    assert(opts2[2].getAttribute('aria-selected') === 'true', 'ArrowDown moved highlight to index 2 (last)');

    const evClamp = keyEvent(window, 'ArrowDown');
    input.dispatchEvent(evClamp); // should clamp at last index, not throw / go out of range
    opts2 = Array.from(panel.querySelectorAll('[role="option"]'));
    assert(opts2[2].getAttribute('aria-selected') === 'true', 'ArrowDown at the last row clamps (stays at index 2)');

    input.dispatchEvent(keyEvent(window, 'ArrowUp'));
    opts2 = Array.from(panel.querySelectorAll('[role="option"]'));
    assert(opts2[1].getAttribute('aria-selected') === 'true', 'ArrowUp moved highlight back to index 1');
    assert(input.getAttribute('aria-activedescendant') === opts2[1].id,
        'aria-activedescendant updated to index 1 after ArrowUp');

    // --- Home / End -----------------------------------------------------
    console.log('\n--- Home / End jump to first / last ---');
    const homeEv = keyEvent(window, 'Home');
    input.dispatchEvent(homeEv);
    let opts3 = Array.from(panel.querySelectorAll('[role="option"]'));
    assert(opts3[0].getAttribute('aria-selected') === 'true', 'Home jumped highlight to index 0');
    assert(homeEv.defaultPrevented, 'Home calls preventDefault() while panel is open');

    const endEv = keyEvent(window, 'End');
    input.dispatchEvent(endEv);
    let opts4 = Array.from(panel.querySelectorAll('[role="option"]'));
    assert(opts4[2].getAttribute('aria-selected') === 'true', 'End jumped highlight to index 2 (last)');
    assert(endEv.defaultPrevented, 'End calls preventDefault() while panel is open');
    assert(input.getAttribute('aria-activedescendant') === opts4[2].id,
        'aria-activedescendant tracks highlight after End');

    // --- Enter commits ----------------------------------------------
    console.log('\n--- Enter commits the highlighted candidate ---');
    // highlight is currently 2 ("Sydney Mines, NS, Canada") from End above.
    const enterEv = keyEvent(window, 'Enter');
    input.dispatchEvent(enterEv);
    assert(enterEv.defaultPrevented, 'Enter calls preventDefault() when a valid highlight exists');
    assert(input.value === 'Sydney Mines, NS, Canada', 'Enter set the input value to the highlighted candidate');
    assert(panel.style.display === 'none', 'Enter closed the panel (closePanel() runs synchronously in pickCandidate())');
    await flush(20); // let the upsert fetch resolve
    assert(hidden.value !== '', 'Enter -> pickCandidate() eventually set the hidden Id from the upsert response');
    assert(input.getAttribute('aria-activedescendant') === null, 'aria-activedescendant cleared once the panel is closed');

    // --- Tab commits (the headline fix) ------------------------------
    console.log('\n--- Tab commits the highlighted candidate (not silently discarded) ---');
    hidden.value = ''; // reset so we can prove Tab is what sets it, not leftover state
    // NOTE: onInput() no-ops when the trimmed value equals the module's
    // internal `lastQuery` (that's deliberate de-dupe against repeated
    // 'input' events for an unchanged value) — use a fresh query string
    // per round so each section genuinely re-opens the panel instead of
    // silently reusing the previous round's already-closed state.
    input.value = 'Syd1';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(30);
    input.dispatchEvent(keyEvent(window, 'ArrowDown')); // highlight -> index 1
    const tabEv = keyEvent(window, 'Tab');
    input.dispatchEvent(tabEv);
    assert(!tabEv.defaultPrevented, 'Tab is NOT preventDefault()-ed (no keyboard trap)');
    assert(panel.style.display === 'none', 'Tab committed synchronously: pickCandidate() closed the panel immediately');
    assert(input.value === 'Sydney, NS, Canada', 'Tab committed the HIGHLIGHTED candidate (index 1), not index 0');
    await flush(20);
    assert(hidden.value !== '', 'Tab -> pickCandidate() eventually set the hidden Id from the upsert response');

    // --- Escape closes the panel AND stops propagation ----------------
    console.log('\n--- Escape closes panel + stopPropagation (does not also close the drawer) ---');
    input.value = 'Syd2';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(30);
    assert(panel.style.display === 'block', 'panel is open again ahead of the Escape test');
    drawerEscapeSeen = 0;
    const escOpenEv = keyEvent(window, 'Escape');
    input.dispatchEvent(escOpenEv);
    assert(escOpenEv.defaultPrevented, 'Escape (panel open) calls preventDefault()');
    assert(panel.style.display === 'none', 'Escape closed the panel');
    assert(drawerEscapeSeen === 0, 'Escape did NOT bubble to the #cpDrawer listener while the panel was open (stopPropagation worked)');

    drawerEscapeSeen = 0;
    const escClosedEv = keyEvent(window, 'Escape');
    input.dispatchEvent(escClosedEv);
    assert(drawerEscapeSeen === 1, 'Escape on an IDLE field (panel already closed) still bubbles normally to the drawer');

    // --- summary -------------------------------------------------------
    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
