/**
 * tests/test-card-layout-keyboard-alt.js — WCAG 2.2 2.5.7 (Dragging
 * Movements) + 2.1.1 (Keyboard) regression guard for the card-layout
 * reorder feature (#448), found during the #1150/#1151 accessibility
 * sweep.
 *
 * ELI5: the "Customise home" / "Customise layout" edit mode let you drag
 * a card's handle to reorder it, but had no way to do that with a
 * keyboard, a switch device, or any other non-pointer input — the ONLY
 * way to change the order was to physically drag. Set lists (setlist.js)
 * already solved this with "Move up" / "Move down" buttons; the card
 * grid's own doc-comment even CLAIMED to have the same fallback
 * ("Keyboard fallback: while in edit mode, each card shows up / down
 * buttons that move it by one slot") — but the code only ever grew a
 * Hide (×) button. The comment was aspirational, not descriptive.
 *
 * This is exactly the class of bug rule #34 warns about: a doc-comment
 * that reads as a completed feature is not evidence the feature exists.
 * This test drives the REAL module (not a re-implementation) through
 * jsdom, so it can only pass against actual "Move up"/"Move down"
 * buttons that actually move the DOM node.
 *
 * Pass an alternate module path as argv[2] to point this at a different
 * copy — used to prove this test is RED against the pre-fix module (see
 * the shell history in the PR this test shipped with: running this file
 * against `git show <pre-fix-sha>:…/card-layout.js` fails on every
 * "Move up/down button exists" assertion, and passes once the buttons
 * are added).
 *
 * @see appWeb/public_html/js/modules/card-layout.js
 * @see appWeb/public_html/js/modules/setlist.js (the pre-existing pattern this mirrors)
 */
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'card-layout.js');
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

/** Build a card grid matching the shape includes/pages/home.php and
 *  manage/index.php actually render: a `[data-layout-surface]` root,
 *  three `.card-layout-item`s each with a `.card-layout-handle` host
 *  (the home-page pattern — data-layout-handle=".card-layout-handle"),
 *  and the toolbar buttons initCardLayout() requires. */
function buildDom() {
    const html = `<!doctype html><html><body>
        <div class="d-flex align-items-center flex-wrap gap-2 mb-3" id="card-layout-toolbar">
            <button type="button" id="btn-card-layout-edit">Customise</button>
            <button type="button" class="d-none" id="btn-card-layout-done">Done</button>
            <button type="button" class="d-none" id="btn-card-layout-reset">Reset</button>
            <button type="button" class="d-none" id="btn-card-layout-save-default">Save as site default</button>
            <span class="d-none" id="card-layout-help"></span>
        </div>
        <div id="home-section-grid" data-layout-surface="home" data-layout-handle=".card-layout-handle"
             data-can-customise="1" data-can-set-default="0">
            <div class="card-layout-item" data-card-id="quick-actions">
                <div class="card-layout-handle"><i class="bi bi-grip-vertical" aria-hidden="true"></i><span>Drag to reorder</span></div>
                <section id="quick-actions">Quick actions</section>
            </div>
            <div class="card-layout-item" data-card-id="song-of-the-day">
                <div class="card-layout-handle"><i class="bi bi-grip-vertical" aria-hidden="true"></i><span>Drag to reorder</span></div>
                <div id="song-of-the-day">Song of the day</div>
            </div>
            <div class="card-layout-item" data-card-id="tags">
                <div class="card-layout-handle"><i class="bi bi-grip-vertical" aria-hidden="true"></i><span>Drag to reorder</span></div>
                <div id="tags-section">Tags</div>
            </div>
        </div>
    </body></html>`;
    return new JSDOM(html, { runScripts: 'dangerously', url: 'https://example.test/' });
}

function click(el) {
    el.dispatchEvent(new el.ownerDocument.defaultView.MouseEvent('click', { bubbles: true, cancelable: true }));
}

function itemIds(root) {
    return Array.from(root.querySelectorAll('.card-layout-item')).map((el) => el.dataset.cardId);
}

async function main() {
    const dom = buildDom();
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    /* card-layout.js's loadSortable() only runs on first edit-mode entry
       and appends a real <script src> to <head> — never awaited by this
       test (we only assert the synchronous button wiring), so no network
       stub is needed. */

    const { initCardLayout } = await import(pathToFileURL(MODULE_PATH).href);

    const root = window.document.getElementById('home-section-grid');
    initCardLayout(root);

    const btnEdit = window.document.getElementById('btn-card-layout-edit');
    const btnDone = window.document.getElementById('btn-card-layout-done');

    console.log('\n--- Entering edit mode grows a keyboard-operable alternative to dragging ---');
    click(btnEdit);

    const items = Array.from(root.querySelectorAll('.card-layout-item'));
    assert(items.length === 3, 'grid still has 3 items after entering edit mode');

    const upButtons = Array.from(root.querySelectorAll('.card-layout-move-up-btn'));
    const downButtons = Array.from(root.querySelectorAll('.card-layout-move-down-btn'));
    assert(upButtons.length === 3, 'every card grew a "Move up" button (2.5.7 non-drag alternative)');
    assert(downButtons.length === 3, 'every card grew a "Move down" button (2.5.7 non-drag alternative)');
    assert(upButtons.every((b) => b.tagName === 'BUTTON'), 'move-up controls are real <button> elements (focusable, Enter/Space-activatable)');
    assert(upButtons.every((b) => (b.getAttribute('aria-label') || '').toLowerCase().includes('up')),
        'every move-up button has an accessible name mentioning "up"');
    assert(downButtons.every((b) => (b.getAttribute('aria-label') || '').toLowerCase().includes('down')),
        'every move-down button has an accessible name mentioning "down"');

    console.log('\n--- Move controls live in the SAME handle that is visible (not aria-hidden) in edit mode ---');
    const handle = items[0].querySelector('.card-layout-handle');
    assert(handle.contains(upButtons[0]), 'the move-up button is inside .card-layout-handle, the strip edit mode reveals');
    assert(handle.getAttribute('aria-hidden') !== 'true', '.card-layout-handle itself is not aria-hidden (would remove its buttons from the accessibility tree — WCAG 4.1.2)');

    console.log('\n--- Boundary buttons start correctly disabled ---');
    assert(upButtons[0].disabled === true, 'first card\'s Move up is disabled (nothing above it)');
    assert(downButtons[2].disabled === true, 'last card\'s Move down is disabled (nothing below it)');
    assert(upButtons[1].disabled === false && downButtons[1].disabled === false,
        'the middle card\'s Move up/down are both enabled');
    assert(upButtons[2].disabled === false, 'last card\'s Move up is enabled (it CAN move up)');
    assert(downButtons[0].disabled === false, 'first card\'s Move down is enabled (it CAN move down)');

    console.log('\n--- Clicking "Move down" on the first card actually reorders the DOM ---');
    assert(itemIds(root).join(',') === 'quick-actions,song-of-the-day,tags', 'starting order is the server-rendered default');
    click(downButtons[0]); // move "quick-actions" down past "song-of-the-day"
    assert(itemIds(root).join(',') === 'song-of-the-day,quick-actions,tags',
        'Move down swapped the first two cards WITHOUT touching a pointer — this is the entire point of 2.5.7');

    console.log('\n--- Disabled state refreshes after a move ---');
    const upNow = Array.from(root.querySelectorAll('.card-layout-move-up-btn'));
    const downNow = Array.from(root.querySelectorAll('.card-layout-move-down-btn'));
    assert(upNow[0].disabled === true, 'the card now first is disabled from moving up further');
    assert(downNow[2].disabled === true, 'the card now last is still disabled from moving down further');
    assert(upNow[1].disabled === false, 'the card now in the middle can move up');

    console.log('\n--- "Move up" reverses the move ---');
    click(upNow[1]); // "quick-actions" is now index 1; move it back up
    assert(itemIds(root).join(',') === 'quick-actions,song-of-the-day,tags', 'Move up restored the original order');

    console.log('\n--- Exiting edit mode tears the controls down ---');
    click(btnDone);
    assert(root.querySelectorAll('.card-layout-move-up-btn').length === 0, 'Move up buttons are removed on Done');
    assert(root.querySelectorAll('.card-layout-move-down-btn').length === 0, 'Move down buttons are removed on Done');
    assert(root.querySelectorAll('.card-layout-hide-btn').length === 0, 'Hide buttons are removed on Done (pre-existing behaviour, still intact)');

    console.log('\n=== ' + checks + ' checks, ' + failures + ' failed ===');
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
