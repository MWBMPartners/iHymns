/**
 * tests/test-ietf-picker-detached-boot.js — the IETF BCP 47 picker must
 * suggest something even when it is booted onto a detached DOM node (#1907,
 * sibling of #1849).
 *
 * ELI5
 * ----
 * The little "type a language" box has an autocomplete list hiding behind
 * it. Some screens build that box off-screen first and only slot it into
 * the page a moment later (when a curator clicks "Set language" on a lyric
 * line). This test builds the box the SAME off-screen way those screens do,
 * plugs it into the page afterwards — exactly like they do — and then
 * checks that typing into it actually shows suggestions. Before the fix it
 * never did, silently, forever.
 *
 * WHY THIS EXISTS
 * ----------------
 * The owner reported "no auto/live-search" on the per-line language picker
 * in the Structure tab's enrichment panel. `bootIetfLanguagePicker()`
 * (js/modules/ietf-language-picker.js) used to resolve its four
 * `<datalist>` elements via `document.getElementById(...)` exactly ONCE, at
 * boot, into `const`s the rest of the module closed over forever.
 * `document.getElementById()` only ever searches the LIVE document (MDN:
 * "must be part of the document tree") — a `<datalist>` that exists only
 * inside a still-detached `document.createElement('div')` subtree is
 * invisible to it, so those `const`s came back `null` and NOTHING later
 * reassigned them, no matter when the subtree was eventually attached.
 * `rebuildDatalist(null, …)` then no-ops forever and `resolveCode()` (which
 * reads `datalistEl?.options`) always falls through to raw typed text — the
 * picker LOOKS alive (the tag preview updates live, Save works) but no
 * suggestion EVER renders. This exact class was already diagnosed and fixed
 * for ONE call site (the v2 Metadata tab) in #1849 — see the comment this
 * fix's own doc-block links back to at
 * manage/editor/v2/metadata-tab.js:1563-1578 — but the two DYNAMIC builders
 * that boot the picker on a still-detached wrapper (v2's
 * `buildIetfPicker()` in manage/editor/v2/enrichment-panel.js, and v1's
 * `buildInlineIetfPicker()` in manage/editor/editor.js) were never touched,
 * so the owner's report was that same bug at its two unfixed sibling
 * sites. #1907 fixes the MODULE instead of patching each call site, so
 * every caller — present and future — is covered without a boot-order
 * contract to remember (CLAUDE.md rule #34).
 *
 * WHAT THIS TEST ACTUALLY EXERCISES (not a proxy for the bug — the bug
 * itself)
 * -------------------------------------------------------------------------
 * A source-text assertion ("the module no longer contains the string
 * `const langList`") would prove nothing about whether suggestions render —
 * it is exactly the kind of "confident, incomplete green" CLAUDE.md rule
 * #34 warns against. So instead this test:
 *
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
 *   5. Fires REAL `focus`/`input` events — the same events a curator
 *      clicking into the box and typing would fire — and asserts:
 *        a. the language datalist actually gained `<option>` children
 *           (the suggestion LIST rendered at all — this is what was
 *           permanently empty pre-fix);
 *        b. typing "English" and blurring resolves the hidden composed tag
 *           to the canonical code "en", not the raw typed text (the
 *           TYPEAHEAD actually resolved a pick, matching the owner's
 *           "auto/live-search" expectation, not just "a list exists");
 *        c. the same holds for the script and region subtag inputs, which
 *           use prefix-search rather than a preloaded list.
 *
 * MUTATION-TESTED (rule #34): pass an alternate module path as argv[1] to
 * point this at a different copy of ietf-language-picker.js — that is how
 * this was proven able to fail. Run it against the pre-#1907 version with:
 *
 *   git show HEAD:appWeb/public_html/js/modules/ietf-language-picker.js \
 *     > /tmp/ietf-picker-broken.js
 *   node tests/test-ietf-picker-detached-boot.js /tmp/ietf-picker-broken.js
 *
 * (HEAD is the pre-#1907 commit until this fix itself is committed — see
 * the session transcript for the actual RED/GREEN evidence recorded at
 * commit time.) That run must FAIL. Re-running with no argument (the real,
 * fixed file) must PASS.
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
 * @see appWeb/public_html/manage/editor/v2/enrichment-panel.js
 * @see appWeb/public_html/manage/editor/editor.js
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js (#1849 precedent)
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
 * a new call site is added. Not exhaustive of every file type on purpose —
 * appWeb/public_html is where every real caller lives today.
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
 * Minimal fetch mock — serves the same three endpoints the module's own
 * doc-block names (LANG_URL / SCRIPT_URL / REGION_URL), never touching the
 * network. Shaped like the real /api and /manage/songbooks JSON contracts.
 * --------------------------------------------------------------------------- */
const LANG_SUGGESTIONS = [
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

function installFetchMock() {
    global.fetch = async (input) => {
        const urlStr = String(input);
        const u = new URL(urlStr, 'https://example.test/');
        const action = u.searchParams.get('action');
        if (u.pathname === '/api' && action === 'languages') return jsonResponse({ languages: LANG_SUGGESTIONS });
        if (u.pathname === '/api' && action === 'variants') return jsonResponse({ variants: [] });
        if (u.pathname === '/manage/songbooks' && action === 'script_search') return jsonResponse({ suggestions: SCRIPT_SUGGESTIONS });
        if (u.pathname === '/manage/songbooks' && action === 'region_search') return jsonResponse({ suggestions: REGION_SUGGESTIONS });
        return { ok: false, status: 404, headers: { get: () => null }, json: async () => ({}) };
    };
}

/* Build the picker markup EXACTLY per the module's own documented markup
   contract (its doc-block, lines ~22-34) — the same shape both
   enrichment-panel.js's buildIetfPicker() and editor.js's
   buildInlineIetfPicker() build via string innerHTML. Returns a DETACHED
   <div> — the caller decides when (if ever) to attach it, same as real
   life. */
function buildDetachedPickerMarkup(doc, idSuffix) {
    const wrap = doc.createElement('div');
    wrap.className = 'ietf-picker';
    wrap.setAttribute('data-ietf-picker-id', idSuffix);
    wrap.innerHTML =
        '<div class="row g-1">'
      +   '<input type="text" class="ietf-picker-language" list="ietf-lang-list-' + idSuffix + '" autocomplete="off">'
      +   '<input type="text" class="ietf-picker-script" list="ietf-script-list-' + idSuffix + '" autocomplete="off">'
      +   '<input type="text" class="ietf-picker-region" list="ietf-region-list-' + idSuffix + '" autocomplete="off">'
      + '</div>'
      + '<code class="ietf-tag-preview">—</code>'
      + '<span class="ietf-tag-display"></span>'
      + '<input type="hidden" class="ietf-tag-output" name="language" value="">'
      + '<datalist id="ietf-lang-list-' + idSuffix + '"></datalist>'
      + '<datalist id="ietf-script-list-' + idSuffix + '"></datalist>'
      + '<datalist id="ietf-region-list-' + idSuffix + '"></datalist>';
    return wrap;
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
        { url: 'https://example.test/manage/editor/editor2.php' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.localStorage = window.localStorage;
    global.CustomEvent = window.CustomEvent;
    global.Event = window.Event;
    installFetchMock();

    const { bootIetfLanguagePicker } = await import(pathToFileURL(MODULE_PATH).href);

    /* ===== Scenario A — mirrors enrichment-panel.js's buildIetfPicker():
       boot on a detached node, prefill via setTag() WHILE STILL DETACHED,
       THEN attach, THEN let the user actually use it. ===================== */
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
    const langListIdA = langInputA.getAttribute('list');

    /* A curator clicking into the box — the ONE-TIME focus listener that
       loads the full language list. */
    langInputA.dispatchEvent(new window.Event('focus'));
    await flush(30);

    const langListElA = window.document.getElementById(langListIdA);
    assert(!!langListElA, 'the language <datalist> element is resolvable via getElementById once attached');
    const optionCountA = langListElA ? langListElA.querySelectorAll('option').length : 0;
    assert(optionCountA > 0,
        'THE REGRESSION: the language datalist gained option(s) after focus (was permanently 0 pre-#1907 — this is the "no suggestions ever" bug) — got ' + optionCountA);

    /* Now actually type "English" and blur — the real user gesture the
       owner described as "auto/live-search". Assert the picker RESOLVED
       the typed text to the canonical code via the (now-populated)
       datalist, not just left the raw string sitting there. */
    langInputA.value = 'English';
    langInputA.dispatchEvent(new window.Event('input', { bubbles: true }));
    langInputA.dispatchEvent(new window.Event('blur'));
    await flush(10);

    const tagOutputA = wrapA.querySelector('.ietf-tag-output');
    assert(tagOutputA.value === 'en',
        'typing "English" resolved via the datalist to the canonical code "en" (got "' + tagOutputA.value + '") — this is the live-search the owner reported as missing');

    /* ===== Scenario B — mirrors editor.js's buildInlineIetfPicker(): boot
       detached with NO prefill, attach later, exercise the SCRIPT and
       REGION prefix-search inputs (a different code path from the
       preloaded language list — both were equally broken pre-#1907). === */
    console.log('\n--- Scenario B: boot detached (no prefill), attach later, exercise script + region (editor.js shape) ---');
    const wrapB = buildDetachedPickerMarkup(window.document, 'scenario-b');
    const ctlB = bootIetfLanguagePicker(wrapB);
    assert(!!ctlB, 'bootIetfLanguagePicker() returns a controller for a fresh (no-prefill) detached boot too');

    window.document.body.appendChild(wrapB);

    const scriptInputB = wrapB.querySelector('.ietf-picker-script');
    const regionInputB = wrapB.querySelector('.ietf-picker-region');
    const scriptListIdB = scriptInputB.getAttribute('list');
    const regionListIdB = regionInputB.getAttribute('list');

    scriptInputB.value = 'Lat';
    scriptInputB.dispatchEvent(new window.Event('input', { bubbles: true }));
    regionInputB.value = 'Uni';
    regionInputB.dispatchEvent(new window.Event('input', { bubbles: true }));
    /* Script/region lookups are debounced 200ms (DEBOUNCE_MS in the
       module) rather than gated on focus — give both timers + their
       fetch microtasks room to land. */
    await flush(260);

    const scriptListElB = window.document.getElementById(scriptListIdB);
    const regionListElB = window.document.getElementById(regionListIdB);
    assert(!!scriptListElB && scriptListElB.querySelectorAll('option').length > 0,
        'the script datalist gained option(s) after typing, on a picker booted detached and attached later');
    assert(!!regionListElB && regionListElB.querySelectorAll('option').length > 0,
        'the region datalist gained option(s) after typing, on a picker booted detached and attached later');

    /* ===== Contrast case — a picker booted on an ALREADY-attached node
       (the songbooks.php / editor/index.php shape) must be completely
       unaffected by this fix: same behaviour, nothing regressed. ========= */
    console.log('\n--- Contrast: already-attached boot (songbooks.php / editor/index.php shape) still works ---');
    const wrapC = buildDetachedPickerMarkup(window.document, 'scenario-c');
    window.document.body.appendChild(wrapC); /* attach FIRST, matches document.querySelector(...) call sites */
    const ctlC = bootIetfLanguagePicker(wrapC);
    assert(!!ctlC, 'an already-attached boot still returns a controller');
    const langInputC = wrapC.querySelector('.ietf-picker-language');
    langInputC.dispatchEvent(new window.Event('focus'));
    await flush(30);
    const langListElC = window.document.getElementById(langInputC.getAttribute('list'));
    assert(!!langListElC && langListElC.querySelectorAll('option').length > 0,
        'an already-attached-at-boot picker still gets suggestions (no regression for the unaffected call sites)');

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
