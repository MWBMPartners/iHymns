/**
 * tests/test-favorites-custom-tag-dedup.js — custom-tag duplicate-pill guard
 * (F-3, #2021, 2026-08-30 correctness review).
 *
 * ELI5
 * ----
 * The "Edit Tags" modal lets a user type a brand-new tag name and click Add.
 * Before adding it for real, the code first checks "do I already have a pill
 * for this exact tag?" — if it re-uses an EXISTING pill, and if it doesn't it
 * mints a new one. This test proves that check actually recognises a tag it
 * has already added, for a tag containing a double-quote character, so
 * re-adding the SAME tag never creates a second, duplicate pill sitting next
 * to the first.
 *
 * THE BUG (F-3, js/modules/favorites.js:369, pre-existing — not introduced by
 * the branch this correctness review covers)
 * ---------------------------------------------------------------------------
 * The "already exists?" lookup used to be:
 *
 *     modal.querySelector(`.tag-checkbox[value="${escapeHtml(tag)}"]`)
 *
 * `escapeHtml()` HTML-entity-escapes its input (a `"` becomes the entity
 * `&quot;`) — the RIGHT escaping for putting text into markup, but this
 * string is spliced into a CSS ATTRIBUTE-SELECTOR, which wants CSS-string
 * escaping (a `"` needs a backslash: `\"`). So a tag containing a literal `"`
 * never matched the pill already rendered for it (with `escapeHtml()`'s
 * entity form baked into its `value` ATTRIBUTE at render time — but the
 * `.value` PROPERTY read back off a real `<input>` is always the decoded,
 * unescaped string, `Choir "A"`, never the entity form) — so re-adding it
 * silently minted a visible duplicate pill instead of just re-checking the
 * one that already existed.
 *
 * THE FIX
 * -------
 * Compare the raw `.value` DOM property directly over the live NodeList
 * (`[...modal.querySelectorAll('.tag-checkbox')].find(cb => cb.value ===
 * tag)`) instead of building a CSS selector string at all — a property read
 * is never re-parsed as markup or as selector syntax, so there is no
 * escaping rule left to get wrong.
 *
 * WHY A REAL jsdom RUN, NOT A STRING-LEVEL ASSERTION
 * ----------------------------------------------------
 * `escapeHtml('Choir "A"')` really does produce `Choir &quot;A&quot;`, and a
 * CSS attribute selector really does need `\"` — a purely textual assertion
 * about those two facts would prove the ESCAPING MISMATCH exists in the
 * abstract, but not that `editTags()` ACTUALLY produces a duplicate pill in
 * the DOM. This file drives the real, unmodified `Favorites` class (a real
 * ES import, not a copy) through jsdom exactly the way a browser would: open
 * the modal, type the tricky tag, click Add TWICE, and count the resulting
 * pills.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Proven able to fail by re-running the IDENTICAL drive function against a
 * MUTATED SIBLING COPY of the real file (`zz_ihymns_test_favorites_mutant_*
 * .js`, written into the SAME directory so its own relative imports still
 * resolve, and always cleaned up in a `finally` — the tracked source is never
 * touched) with the fix's one line reverted to the pre-fix selector-string
 * shape. Against that mutant, adding the SAME quote-containing tag twice
 * really does produce 2 pills, proving this test can tell fixed from broken.
 *
 * @see appWeb/public_html/js/modules/favorites.js   editTags() — the method under test
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MODULES_DIR = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules');
const MODULE_PATH = path.join(MODULES_DIR, 'favorites.js');

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

/** The tag deliberately contains a double quote — the exact character whose
 *  escaping differs between HTML-entity form (`escapeHtml`) and CSS-selector
 *  form (`CSS.escape` / manual `\"`). */
const TRICKY_TAG = 'Choir "A"';

/**
 * Set up a fresh jsdom document + global DOM shims, import the given
 * favorites.js-shaped module fresh (cache-busted via a query string so the
 * fixed file and a mutant sibling never collide in Node's ES module cache),
 * open the tag editor, and add TRICKY_TAG via the "Add custom tag" button
 * TWICE in a row — mirroring a user re-typing/re-adding a tag they already
 * added earlier in the session.
 *
 * @param {string} modulePath Absolute path to the favorites.js-shaped file to drive.
 * @returns {Promise<number>} How many `.tag-checkbox` pills carry TRICKY_TAG's value after both adds.
 */
async function driveDoubleAdd(modulePath) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>',
        { runScripts: 'dangerously', url: 'https://example.test/favorites' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.localStorage = window.localStorage;
    global.MouseEvent = window.MouseEvent;
    /* Minimal Bootstrap Modal stand-in — editTags() only calls the
       constructor + show()/hide(); this test never needs a real transition
       or backdrop, only the DOM the method builds before that call. */
    global.bootstrap = {
        Modal: class {
            constructor() {}
            show() {}
            hide() {}
        },
    };

    // Cache-bust so the SAME relative path (the mutant sibling reuses the
    // real directory so its own imports resolve) is never served from
    // Node's module cache across the two drives this file performs.
    const cacheBustUrl = pathToFileURL(modulePath).href + '?t=' + Date.now() + Math.random();
    const { Favorites } = await import(cacheBustUrl);

    const favorites = new Favorites({});             // empty app stub — editTags() only uses optional-chained app.* calls
    favorites.editTags('MP-1', 'Test Song');           // synchronous body up to `return new Promise(...)` — modal is already in the DOM when this returns

    const modal = window.document.getElementById('tag-editor-modal');
    if (!modal) {
        throw new Error('tag-editor-modal did not mount');
    }
    const input = modal.querySelector('#tag-custom-input');
    const addBtn = modal.querySelector('#tag-custom-add');

    // First add: mints the pill.
    input.value = TRICKY_TAG;
    addBtn.click();

    // Second add of the SAME tag text: must re-use the existing pill, not
    // mint a second one — this is the exact F-3 regression.
    input.value = TRICKY_TAG;
    addBtn.click();

    const matches = [...modal.querySelectorAll('.tag-checkbox')].filter((cb) => cb.value === TRICKY_TAG);
    return matches.length;
}

/**
 * Build a throwaway MUTATED SIBLING of favorites.js with the F-3 fix's one
 * line reverted to its pre-fix shape (the CSS-selector-with-escapeHtml
 * lookup) — written into the SAME directory as the real file so its
 * unmodified relative imports (`../utils/text.js`, `./list-sort.js`, …)
 * still resolve. The TRACKED file is never touched; the mutant is deleted in
 * the caller's `finally`.
 *
 * @returns {string} absolute path to the mutant file.
 */
function writeMutantFavorites() {
    const realSrc = fs.readFileSync(MODULE_PATH, 'utf8');
    const fixedLine = "const existing = [...modal.querySelectorAll('.tag-checkbox')].find(cb => cb.value === tag);";
    const preFixLine = 'const existing = modal.querySelector(`.tag-checkbox[value="${escapeHtml(tag)}"]`); // MUTATED: reverted to the pre-F-3 selector-string lookup';
    if (!realSrc.includes(fixedLine)) {
        throw new Error('MUTATION setup sanity failed: the fixed line was not found in the real source — the file changed shape, update this test.');
    }
    const mutatedSrc = realSrc.replace(fixedLine, preFixLine);
    const mutantPath = path.join(MODULES_DIR, `zz_ihymns_test_favorites_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
    fs.writeFileSync(mutantPath, mutatedSrc);
    return mutantPath;
}

async function main() {
    console.log('\nFavorites custom-tag duplicate-pill guard (F-3, #2021)\n');

    console.log('--- fixed favorites.js: re-adding a quote-containing tag must NOT duplicate its pill ---');
    const fixedCount = await driveDoubleAdd(MODULE_PATH);
    assert(fixedCount === 1,
        `adding "${TRICKY_TAG}" twice leaves exactly ONE pill for it (found ${fixedCount})`);

    console.log('\n--- MUTATION PROOF: the pre-fix selector-string lookup DOES duplicate the pill ---');
    let mutantPath = null;
    try {
        mutantPath = writeMutantFavorites();
        const mutantCount = await driveDoubleAdd(mutantPath);
        assert(mutantCount === 2,
            `against the REVERTED (pre-fix) lookup, adding "${TRICKY_TAG}" twice DOES create 2 duplicate pills (found ${mutantCount}) — proving this test can tell fixed from broken`);
    } finally {
        if (mutantPath) {
            fs.rmSync(mutantPath, { force: true });
        }
    }

    console.log(`\n=== ${checks} checks, ${failures} failed ===`);
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((err) => {
    console.error('HARNESS CRASHED:', err);
    process.exit(1);
});
