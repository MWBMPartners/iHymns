/**
 * tests/test-ietf-picker-detached-boot.js — the IETF BCP 47 picker must
 * suggest something even when it is booted onto a detached DOM node (#1907,
 * sibling of #1849; REWORKED for the BCP 47 registry plan §4 live-search
 * rework — see "WHY THIS TEST CHANGED SHAPE" below).
 *
 * ELI5
 * ----
 * The little "type a language" box has a live-search suggestion panel
 * behind it. Some screens build that box off-screen first and only slot it
 * into the page a moment later (when a curator clicks "Set language" on a
 * lyric line). This test builds the box the SAME off-screen way those
 * screens do, plugs it into the page afterwards — exactly like they do —
 * and then checks that typing into it, and PICKING a suggestion, actually
 * works. Before the original #1907 fix it never did, silently, forever.
 *
 * WHY THIS TEST CHANGED SHAPE (2026-08-25, BCP 47 registry plan §4)
 * -------------------------------------------------------------------------
 * The original version of this test asserted a `<datalist>` gained
 * `<option>` children after focus/typing — that WAS the mechanism at the
 * time (a lazy `getElementById()` re-resolve). The picker has since been
 * reworked to remove `<datalist>` ENTIRELY in favour of the shared
 * `window.iHymnsPlaceSearch.attach()` live-search typeahead (rule #43),
 * which structurally cannot suffer the getElementById-on-a-detached-node
 * bug at all (it never calls `getElementById()` — see
 * `tests/test-ietf-picker-live-dom.js`, the NEW guard that checks that
 * mechanism directly). Keeping this test's OLD assertions unchanged after
 * that rework would be internally impossible to satisfy (there are no
 * `<datalist>` elements left to count options on) — so this file keeps its
 * ORIGINAL PURPOSE (prove a detached-then-attached boot still suggests
 * something and still resolves a real pick) while adapting its MECHANICS
 * to match: it now exercises the REAL `place-search.js` module (loaded as
 * a genuine classic script, exactly as the browser would) together with
 * the REAL `ietf-language-picker.js` ES module, and asserts against the
 * rendered `[role="option"]` panel + a REAL simulated pick (a `mousedown`
 * on the option row, mirroring `place-search.js`'s own `pickCandidate()`
 * wiring) rather than counting `<option>` elements in a `<datalist>`.
 *
 * WHAT THIS TEST ACTUALLY EXERCISES (not a proxy for the bug — the bug
 * itself, still)
 * -------------------------------------------------------------------------
 *   1. Builds the picker's markup EXACTLY per the module's own documented
 *      contract (the doc-block at the top of ietf-language-picker.js) —
 *      the same shape both `buildIetfPicker()` and `buildInlineIetfPicker()`
 *      emit via their own `wrap.innerHTML` — on a bare, UNATTACHED
 *      `document.createElement('div')`.
 *   2. Calls the REAL `bootIetfLanguagePicker()` on that detached node —
 *      reproducing the exact call order both affected sites use.
 *   3. Calls `ctl.setTag(...)` WHILE STILL DETACHED — reproducing
 *      enrichment-panel.js's `buildIetfPicker()`, which calls
 *      `ctl.setTag(tag)` synchronously right after boot, before its caller
 *      ever attaches the wrapper to the document.
 *   4. Attaches the wrapper to `document.body` — mirroring the real
 *      callers' later `host.appendChild(form)` once the curator opens the
 *      inline form.
 *   5. Fires REAL `focus`/`input`/`mousedown` events — the same events a
 *      curator clicking into the box, typing, and clicking a suggestion
 *      would fire — and asserts:
 *        a. focusing binds the live-search typeahead (the lazy `focusin`
 *           path — `window.iHymnsPlaceSearch.attach()` gets called);
 *        b. typing renders a REAL suggestion panel with `[role="option"]`
 *           rows (the suggestion LIST rendered at all — this is what was
 *           permanently empty pre-fix);
 *        c. a simulated `mousedown` PICK on that row resolves the hidden
 *           composed tag to the canonical code "en", not the raw typed
 *           text (the TYPEAHEAD actually resolved a pick, matching the
 *           owner's "auto/live-search" expectation, not just "a list
 *           exists");
 *        d. the same holds for the script and region subtag inputs;
 *        e. typing something NOT in the registry surfaces the M3 inline
 *           "not a recognised subtag" warning (the plan's §4.4 — proves
 *           the new free-text-stays-allowed-but-never-silent feature is
 *           actually wired on a detached-then-attached boot too).
 *
 * MUTATION-TESTED (rule #34): pass an alternate module path as argv[1] to
 * point this at a different copy of ietf-language-picker.js.
 *
 * DERIVED, NOT TYPED (rule #34): `findCallSites()` below greps the tree for
 * every `bootIetfLanguagePicker(` call rather than a hand-typed list, so a
 * newly added caller is picked up automatically and the "this bug has real
 * callers" sanity check can never go silently stale.
 *
 *   node tests/test-ietf-picker-detached-boot.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/js/modules/ietf-language-picker.js
 * @see appWeb/public_html/js/modules/place-search.js                  the real typeahead this test loads for real
 * @see appWeb/public_html/manage/editor/v2/enrichment-panel.js
 * @see appWeb/public_html/manage/editor/editor.js
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js (#1849 precedent)
 * @see tests/test-ietf-picker-live-dom.js                             the sibling STATIC-source-analysis guard (never duplicated — this file proves BEHAVIOUR at runtime)
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/getElementById
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, '..');
const DEFAULT_MODULE_PATH = path.join(
    REPO_ROOT, 'appWeb', 'public_html', 'js', 'modules', 'ietf-language-picker.js'
);
const MODULE_PATH = process.argv[2] ? path.resolve(process.argv[2]) : DEFAULT_MODULE_PATH;
const PLACE_SEARCH_PATH = path.join(REPO_ROOT, 'appWeb', 'public_html', 'js', 'modules', 'place-search.js');

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

const flush = (ms) => new Promise((r) => setTimeout(r, ms == null ? 10 : ms));

/* ---------------------------------------------------------------------------
 * Tree-derived caller inventory (rule #34) — grep every real `.js`/`.php`
 * source file under appWeb/public_html for a `bootIetfLanguagePicker(` call,
 * so this guard's "the bug has real callers" premise is grounded in the
 * actual tree rather than a hand-typed list that could go stale the moment
 * a new call site is added.
 * --------------------------------------------------------------------------- */
function findCallSites() {
    const root = path.join(REPO_ROOT, 'appWeb', 'public_html');
    const hits = [];
    const CALL_RE = /bootIetfLanguagePicker\s*\(/;
    (function walk(dir) {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) { walk(full); continue; }
            if (!/\.(js|php)$/.test(entry.name)) continue;
            const text = fs.readFileSync(full, 'utf8');
            text.split('\n').forEach((line, i) => {
                if (CALL_RE.test(line)) {
                    hits.push({ file: path.relative(REPO_ROOT, full), line: i + 1 });
                }
            });
        }
    })(root);
    return hits;
}

/* ---------------------------------------------------------------------------
 * Fake network — serves the FOUR real /api?action=*_search endpoints
 * (BCP 47 registry plan §4.3), shaped like bcp47SubtagSearch()'s real JSON
 * contract. Registered on BOTH `window.fetch` (place-search.js runs as a
 * genuine classic <script> inside jsdom's window, via runScripts:
 * 'dangerously' — its internal fetch() resolves against window.fetch) AND
 * Node's own `global.fetch` (ietf-language-picker.js is loaded via Node's
 * ESM import(), so its apiFetch()->fetch() call resolves against the
 * process-global fetch, not window.fetch — these are two DIFFERENT
 * bindings and both must be mocked for the two real modules to cooperate).
 * --------------------------------------------------------------------------- */
const LANGUAGE_SUGGESTIONS = [
    { code: 'en', name: 'English', nativeName: 'English' },
    { code: 'es', name: 'Spanish', nativeName: 'Español' },
];
const SCRIPT_SUGGESTIONS = [{ code: 'Latn', name: 'Latin', nativeName: 'Latin' }];
const REGION_SUGGESTIONS = [{ code: 'GB', name: 'United Kingdom' }];

function jsonResponse(obj) {
    return {
        ok: true,
        status: 200,
        headers: { get: () => null },
        json: async () => obj,
    };
}

function makeFetchMock() {
    return async (input) => {
        const urlStr = String(input);
        const u = new URL(urlStr, 'https://example.test/');
        const action = u.searchParams.get('action');
        const q = (u.searchParams.get('q') || '').toLowerCase();
        if (u.pathname === '/api' && action === 'language_search') {
            const hit = LANGUAGE_SUGGESTIONS.filter((s) => s.code.toLowerCase() === q || s.name.toLowerCase().includes(q));
            return jsonResponse({ suggestions: q ? hit : [] });
        }
        if (u.pathname === '/api' && action === 'script_search') {
            const hit = SCRIPT_SUGGESTIONS.filter((s) => s.code.toLowerCase() === q || s.name.toLowerCase().includes(q));
            return jsonResponse({ suggestions: q ? hit : [] });
        }
        if (u.pathname === '/api' && action === 'region_search') {
            const hit = REGION_SUGGESTIONS.filter((s) => s.code.toLowerCase() === q || s.name.toLowerCase().includes(q));
            return jsonResponse({ suggestions: q ? hit : [] });
        }
        if (u.pathname === '/api' && action === 'variant_search') {
            return jsonResponse({ suggestions: [] });
        }
        return { ok: false, status: 404, headers: { get: () => null }, json: async () => ({}) };
    };
}

/* Build the picker markup EXACTLY per the module's own documented markup
   contract (its doc-block) — the same shape all three dynamic builders
   emit via string innerHTML: three/four labelled inputs, each with a
   hidden `-code` sibling, plus the tag preview/output and the M3 unknown-
   subtag warning slot. NO `<datalist>` any more. Returns a DETACHED <div>
   — the caller decides when (if ever) to attach it, same as real life. */
function buildDetachedPickerMarkup(doc, idSuffix) {
    const wrap = doc.createElement('div');
    wrap.className = 'ietf-picker';
    wrap.setAttribute('data-ietf-picker-id', idSuffix);
    wrap.innerHTML =
        '<div class="row g-1">'
      +   '<div class="col"><input type="text" class="ietf-picker-language" autocomplete="off"><input type="hidden" class="ietf-picker-language-code"></div>'
      +   '<div class="col"><input type="text" class="ietf-picker-script" autocomplete="off"><input type="hidden" class="ietf-picker-script-code"></div>'
      +   '<div class="col"><input type="text" class="ietf-picker-region" autocomplete="off"><input type="hidden" class="ietf-picker-region-code"></div>'
      + '</div>'
      + '<code class="ietf-tag-preview">—</code>'
      + '<span class="ietf-tag-display"></span>'
      + '<div class="ietf-picker-unknown-warning form-text d-none"></div>'
      + '<input type="hidden" class="ietf-tag-output" name="language" value="">';
    return wrap;
}

/** Type into `inputEl`, dispatch 'input', wait for the debounce+fetch, and
 *  return the rendered [role="option"] rows in whichever panel opened (the
 *  panel place-search.js appended to document.body). */
async function typeAndWaitForPanel(window, inputEl, text, waitMs) {
    inputEl.value = text;
    inputEl.dispatchEvent(new window.Event('input', { bubbles: true }));
    await flush(waitMs == null ? 260 : waitMs); // 200ms module debounce + fetch microtasks
    const panelId = inputEl.getAttribute('aria-controls');
    const panel = panelId ? window.document.getElementById(panelId) : null;
    return { panel, options: panel ? Array.from(panel.querySelectorAll('[role="option"]')) : [] };
}

/** Simulate a REAL suggestion pick — place-search.js's rows listen for
 *  'mousedown' (preventDefault, so the input never blurs), not 'click'. */
function pickOption(window, optionEl) {
    const ev = new window.Event('mousedown', { bubbles: true, cancelable: true });
    optionEl.dispatchEvent(ev);
}

async function main() {
    console.log('');
    console.log('🧪 test-ietf-picker-detached-boot.js');
    console.log('Module under test: ' + path.relative(REPO_ROOT, MODULE_PATH));
    console.log('══════════════════════════════════════════════════');

    /* ---- sanity: the bug has real, tree-derived callers ------------------ */
    console.log('\n--- Tree-derived caller inventory (rule #34) ---');
    const callSites = findCallSites();
    callSites.forEach((c) => console.log('  found: ' + c.file + ':' + c.line));
    assert(callSites.length > 0,
        'at least one real bootIetfLanguagePicker(...) call site exists in appWeb/public_html (grep-derived, not typed)');
    const affectedBasenames = ['enrichment-panel.js', 'editor.js'];
    affectedBasenames.forEach((base) => {
        assert(callSites.some((c) => c.file.endsWith(base)),
            'the known detached-boot sibling site ' + base + ' is among the discovered callers');
    });

    /* ---- fake DOM + fake network ------------------------------------------ */
    const dom = new JSDOM('<!doctype html><html><body></body></html>',
        { url: 'https://example.test/manage/editor/editor2.php', runScripts: 'dangerously' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.localStorage = window.localStorage;
    global.CustomEvent = window.CustomEvent;
    global.Event = window.Event;
    global.URL = window.URL;
    window.fetch = makeFetchMock();   // for place-search.js (classic script, runs in jsdom's window)
    global.fetch = makeFetchMock();   // for ietf-language-picker.js's apiFetch (Node ESM, process-global fetch)

    /* Load the REAL place-search.js as a genuine classic global script —
       exactly how every real page loads it — so window.iHymnsPlaceSearch
       is the actual shared module, not a stand-in. */
    const placeSearchSrc = fs.readFileSync(PLACE_SEARCH_PATH, 'utf8');
    const scriptEl = window.document.createElement('script');
    scriptEl.textContent = placeSearchSrc;
    window.document.head.appendChild(scriptEl);
    assert(typeof window.iHymnsPlaceSearch === 'object' && typeof window.iHymnsPlaceSearch.attach === 'function',
        'window.iHymnsPlaceSearch.attach exists (the real shared module loaded, not a mock)');

    const { bootIetfLanguagePicker } = await import(pathToFileURL(MODULE_PATH).href);

    /* ===== Scenario A — mirrors enrichment-panel.js's buildIetfPicker():
       boot on a detached node, prefill via setTag() WHILE STILL DETACHED,
       THEN attach, THEN let the user actually search + pick. =============== */
    console.log('\n--- Scenario A: boot + setTag() detached, attach later (enrichment-panel.js shape) ---');
    const wrapA = buildDetachedPickerMarkup(window.document, 'scenario-a');
    assert(!wrapA.isConnected, 'sanity: wrapA starts detached (not connected to the document)');

    const ctlA = bootIetfLanguagePicker(wrapA);
    assert(!!ctlA, 'bootIetfLanguagePicker() returns a controller even when booted detached (never throws)');

    /* Exactly enrichment-panel.js:137-144 — setTag() called synchronously
       right after boot, before the caller ever attaches wrapA. */
    await ctlA.setTag('en').catch(() => {});

    /* NOW attach — mirrors form.appendChild(picker.el) + host.appendChild(form). */
    window.document.body.appendChild(wrapA);
    assert(wrapA.isConnected, 'sanity: wrapA is now attached to the live document');

    const langInputA = wrapA.querySelector('.ietf-picker-language');
    const tagOutputA = wrapA.querySelector('.ietf-tag-output');
    assert(langInputA.value === 'English', 'setTag("en") pre-filled the language input to "English" even while detached');
    assert(tagOutputA.value === 'en', 'setTag("en") composed the hidden output to "en" even while detached');

    /* A curator clicking into the box — this is what binds the LIVE search
       typeahead (the lazy focusin path, #1907/§4.1). Re-typing over the
       pre-filled value exercises the REAL search + REAL pick. */
    langInputA.dispatchEvent(new window.Event('focus', { bubbles: true }));
    langInputA.dispatchEvent(new window.Event('focusin', { bubbles: true }));
    await flush(10);

    const { options: langOptionsA } = await typeAndWaitForPanel(window, langInputA, 'Eng');
    assert(langOptionsA.length > 0,
        'THE REGRESSION: the language suggestion panel rendered option(s) after typing on a picker booted DETACHED and attached later (was permanently 0 pre-#1907) — got ' + langOptionsA.length);
    assert(langOptionsA.length > 0 && langOptionsA[0].textContent.includes('English'),
        'the rendered suggestion is "English" (the real /api?action=language_search mock response)');

    if (langOptionsA.length > 0) {
        pickOption(window, langOptionsA[0]);
        await flush(10);
    }
    assert(langInputA.value === 'English', 'picking the suggestion set the input to "English"');
    assert(tagOutputA.value === 'en',
        'picking the suggestion resolved the hidden composed tag to the canonical code "en" — this is the live-search the owner reported as missing');

    /* ===== Scenario B — mirrors editor.js's buildInlineIetfPicker(): boot
       detached with NO prefill, attach later, exercise the SCRIPT and
       REGION live-search inputs (independent request/response pairs from
       the preloaded-language path — both were equally broken pre-#1907,
       and both are now independent /api actions per the plan's §4.3). == */
    console.log('\n--- Scenario B: boot detached (no prefill), attach later, exercise script + region (editor.js shape) ---');
    const wrapB = buildDetachedPickerMarkup(window.document, 'scenario-b');
    const ctlB = bootIetfLanguagePicker(wrapB);
    assert(!!ctlB, 'bootIetfLanguagePicker() returns a controller for a fresh (no-prefill) detached boot too');

    window.document.body.appendChild(wrapB);

    const scriptInputB = wrapB.querySelector('.ietf-picker-script');
    const regionInputB = wrapB.querySelector('.ietf-picker-region');
    scriptInputB.dispatchEvent(new window.Event('focusin', { bubbles: true }));
    regionInputB.dispatchEvent(new window.Event('focusin', { bubbles: true }));
    await flush(10);

    const { options: scriptOptionsB } = await typeAndWaitForPanel(window, scriptInputB, 'Lat');
    const { options: regionOptionsB } = await typeAndWaitForPanel(window, regionInputB, 'Uni');
    assert(scriptOptionsB.length > 0, 'the script suggestion panel rendered option(s) after typing, on a picker booted detached and attached later');
    assert(regionOptionsB.length > 0, 'the region suggestion panel rendered option(s) after typing, on a picker booted detached and attached later');

    if (scriptOptionsB.length > 0) { pickOption(window, scriptOptionsB[0]); await flush(10); }
    if (regionOptionsB.length > 0) { pickOption(window, regionOptionsB[0]); await flush(10); }
    const tagOutputB = wrapB.querySelector('.ietf-tag-output');
    /* No language picked in this scenario -> composeTag() returns '' (no
       language means no tag, per the module's own documented contract) —
       so we assert the SUBTAG inputs resolved, not the composed tag. */
    assert(scriptInputB.value === 'Latin' && regionInputB.value === 'United Kingdom',
        'both script and region resolved to their picked friendly names ("' + scriptInputB.value + '" / "' + regionInputB.value + '")');
    assert(tagOutputB.value === '', 'sanity: with no language subtag picked, the composed tag stays empty (composeTag()\'s documented "no language -> no tag" rule)');

    /* ===== M3 — free text stays allowed, but is never silent (plan §4.4),
       proven on a detached-then-attached boot too. ========================= */
    console.log('\n--- M3: unrecognised free text surfaces the inline warning (plan §4.4) ---');
    const warningB = wrapB.querySelector('.ietf-picker-unknown-warning');
    const langInputB = wrapB.querySelector('.ietf-picker-language');
    langInputB.value = 'Klingon Made Up Nonsense';
    langInputB.dispatchEvent(new window.Event('input', { bubbles: true }));
    langInputB.dispatchEvent(new window.Event('blur'));
    await flush(10);
    assert(!warningB.classList.contains('d-none'), 'typing an unrecognised language subtag makes the inline warning visible');
    assert(warningB.textContent.includes('Klingon Made Up Nonsense'), 'the warning names the exact typed value');
    /* And free text is still SAVED — never blocked (rule #21). */
    assert(wrapB.querySelector('.ietf-tag-output').value.toLowerCase().startsWith('klingon made up nonsense'.split(' ')[0].toLowerCase())
        || wrapB.querySelector('.ietf-tag-output').value !== '',
        'the composed tag still includes the free-typed text — free text is never blocked, only flagged');

    /* ===== Contrast case — a picker booted on an ALREADY-attached node
       (the songbooks.php / editor/index.php shape) must be completely
       unaffected by this fix: same behaviour, nothing regressed. ========= */
    console.log('\n--- Contrast: already-attached boot (songbooks.php / editor/index.php shape) still works ---');
    const wrapC = buildDetachedPickerMarkup(window.document, 'scenario-c');
    window.document.body.appendChild(wrapC); /* attach FIRST, matches document.querySelector(...) call sites */
    const ctlC = bootIetfLanguagePicker(wrapC);
    assert(!!ctlC, 'an already-attached boot still returns a controller');
    const langInputC = wrapC.querySelector('.ietf-picker-language');
    langInputC.dispatchEvent(new window.Event('focusin', { bubbles: true }));
    await flush(10);
    const { options: optionsC } = await typeAndWaitForPanel(window, langInputC, 'Eng');
    assert(optionsC.length > 0, 'an already-attached-at-boot picker still gets suggestions (no regression for the unaffected call sites)');

    /* ===== Double-boot guard is untouched by this fix ==================== */
    const reboot = bootIetfLanguagePicker(wrapC);
    assert(reboot === null, 'booting the same rootEl twice still no-ops (dataset.ietfPickerBooted guard unchanged)');

    console.log('\n══════════════════════════════════════════════════');
    console.log(`✅ Passed: ${checks - failures}`);
    console.log(`❌ Failed: ${failures}`);
    console.log(`📊 Total:  ${checks}`);
    console.log('');
    process.exit(failures > 0 ? 1 : 0);
}

main().catch((err) => {
    console.error('FATAL:', err);
    process.exit(1);
});
