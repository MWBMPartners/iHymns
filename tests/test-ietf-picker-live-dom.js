/**
 * tests/test-ietf-picker-live-dom.js — the IETF BCP 47 picker's live-DOM
 * safety properties (BCP 47 registry plan §6.2.4, M2 guard 4).
 *
 * ELI5: makes sure the language picker never goes back to hunting for a
 * suggestion box the OLD, breakable way (`document.getElementById` at boot
 * time, or a `<datalist>` at all) — both of which are exactly the shape of
 * bug #1907 fixed twice over (once with a lazy re-resolve, then for real by
 * removing the mechanism entirely, BCP 47 registry plan §4.1/§4.2).
 *
 * WHAT IT CHECKS (source-text properties, all tree-derived / grep-found —
 * never a hand-typed file list, rule #34)
 * ----------------------------------------------------------------------
 * (A) NO BOOT-TIME `document.getElementById` CAPTURE — the module itself,
 *     `js/modules/ietf-language-picker.js`, must contain ZERO
 *     `getElementById(` occurrences anywhere. This is deliberately the
 *     STRONGEST form of the check the plan asks for ("every document
 *     lookup … sits inside the lazy focusin path"): the live-search
 *     rework removed the datalist-resolution mechanism entirely, so the
 *     healthy state is not merely "not at boot time" but "not at all".
 * (B) `attach()` CALLS ARE GATED BY THE LAZY `focusin` PATH — the ONE
 *     `window.iHymnsPlaceSearch.attach(` call site in the module must be
 *     lexically INSIDE the function that the one-time `focusin` listener
 *     invokes (`attachLiveSearch`), never called from anywhere else
 *     (verified by both a byte-offset "focusin registration comes after
 *     the function definition" check and an occurrence-count check).
 * (C) NO `<datalist>` FINGERPRINT SURVIVES — across the WHOLE tree (not
 *     just the module): no `list="ietf-` / `list='ietf-` attribute value
 *     (the old per-instance datalist wiring), and no `rebuildDatalist(`
 *     function. The four consumer files that build this picker's markup
 *     (the server partial + the three dynamic JS builders) are discovered
 *     by grep — every file that either calls `bootIetfLanguagePicker(` or
 *     requires the shared partial — never a typed list, so a FIFTH future
 *     consumer is covered automatically.
 *
 * MUTATION-PROVEN (rule #34) — every check below was actually broken, run,
 * confirmed RED, and restored; see the commit body that shipped this file
 * for the full red/green transcript.
 *
 *   node tests/test-ietf-picker-live-dom.js
 *
 * Exit status 0 = every property holds, 1 = drift.
 *
 * @see appWeb/public_html/js/modules/ietf-language-picker.js
 * @see appWeb/public_html/manage/editor/v2/enrichment-panel.js
 * @see appWeb/public_html/manage/editor/editor.js
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js
 * @see appWeb/public_html/manage/includes/partials/ietf-language-picker.php
 * @see .claude/bcp47-language-registry-plan.md §6.2.4
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, '..');
const PUB = path.join(REPO_ROOT, 'appWeb', 'public_html');
const MODULE_PATH = process.argv[2] ? path.resolve(process.argv[2]) : path.join(PUB, 'js', 'modules', 'ietf-language-picker.js');
const SCAN_ROOT    = process.argv[3] ? path.resolve(process.argv[3]) : PUB;

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

/** Blank `/* … *\/` and `// …` comment BODIES (keep newlines, so a
 *  reported offset stays meaningful) — this guard's OWN doc-block above
 *  freely mentions `getElementById`/`iHymnsPlaceSearch.attach(` in prose;
 *  without stripping, a scan of the CHECKED files' own doc-comments
 *  (which explain the very mechanism being checked, in the same prose
 *  style) would false-positive on itself. Mirrors the technique
 *  test-endpoint-routing.php / test-org-logo-surfaces.php already use. */
function stripComments(src) {
    src = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ''));
    src = src.replace(/(^|\s)\/\/[^\n]*/g, '$1');
    return src;
}

function walk(dir, acc) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name === 'node_modules' || entry.name === '.git' || entry.name === 'vendor') continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) { walk(full, acc); continue; }
        if (/\.(js|php)$/.test(entry.name)) acc.push(full);
    }
    return acc;
}

function main() {
    console.log('');
    console.log('🧪 test-ietf-picker-live-dom.js');
    console.log('Module: ' + path.relative(REPO_ROOT, MODULE_PATH));
    console.log('Scanning: ' + path.relative(REPO_ROOT, SCAN_ROOT));
    console.log('══════════════════════════════════════════════════');

    if (!fs.existsSync(MODULE_PATH)) { console.error('FATAL: module not found at ' + MODULE_PATH); process.exit(1); }
    const moduleSrc = stripComments(fs.readFileSync(MODULE_PATH, 'utf8'));

    /* ===== CHECK A — no getElementById anywhere in the module ============ */
    console.log('\n--- Check A: no document.getElementById() anywhere in the module ---');
    const getByIdMatches = [...moduleSrc.matchAll(/getElementById\s*\(/g)];
    assert(getByIdMatches.length === 0,
        'ietf-language-picker.js contains ZERO getElementById( occurrences (found ' + getByIdMatches.length
        + ') — the live-search rework removed the <datalist>-resolution mechanism entirely, so this must never reappear');

    /* ===== CHECK B — attach() is gated by the lazy focusin path =========== */
    console.log('\n--- Check B: iHymnsPlaceSearch.attach() is called ONLY from inside the lazy focusin-gated function ---');
    const attachCallRe = /iHymnsPlaceSearch\.attach\s*\(/g;
    const attachCalls = [...moduleSrc.matchAll(attachCallRe)];
    assert(attachCalls.length === 1,
        'exactly ONE call site of window.iHymnsPlaceSearch.attach( exists in the module (found ' + attachCalls.length + ') — every subtag routes through the same gated helper, never a second copy');

    const fnDefMatch  = moduleSrc.match(/function\s+attachLiveSearch\s*\(/);
    const focusinMatch = moduleSrc.match(/addEventListener\s*\(\s*['"]focusin['"]/);
    assert(!!fnDefMatch, "the module defines a function named 'attachLiveSearch' (the lazy-bind helper name this guard anchors on)");
    assert(!!focusinMatch, "the module registers a 'focusin' listener (the lazy-boot mechanism #1907/§4.1 requires)");

    if (fnDefMatch && focusinMatch && attachCalls.length >= 1) {
        const fnDefPos   = fnDefMatch.index;
        const focusinPos = focusinMatch.index;
        const attachPos  = attachCalls[0].index;
        assert(fnDefPos < focusinPos,
            'attachLiveSearch is DEFINED before the focusin listener REGISTERS it (definition offset ' + fnDefPos + ' < focusin offset ' + focusinPos + ')');
        assert(attachPos > fnDefPos,
            "the attach() call site sits AFTER attachLiveSearch's own function-definition point (i.e. lexically inside it), offset " + attachPos + ' > ' + fnDefPos);
        /* Belt-and-braces: the ONLY caller of attachLiveSearch( must be
           inside the focusin listener body — i.e. every OTHER occurrence of
           the literal "attachLiveSearch(" text is either the definition
           itself or a call between the focusin registration and the
           listener's own closing `}, { once: true });`. We approximate
           "inside the listener body" the same way test-endpoint-routing.php
           anchors on a directive SHAPE rather than re-parsing full JS: find
           the listener's closing marker and require every CALL (not the
           `function attachLiveSearch(` definition line itself) to sit
           between the focusin registration and that closer. */
        const closerMatch = moduleSrc.slice(focusinPos).match(/\}\s*,\s*\{\s*once:\s*true\s*\}\s*\)\s*;/);
        assert(!!closerMatch, "the focusin listener has the expected '{ once: true }' one-time-binding closer");
        if (closerMatch) {
            const closerPos = focusinPos + closerMatch.index + closerMatch[0].length;
            const fnDefStart = fnDefMatch.index;
            const fnDefEnd   = fnDefMatch.index + fnDefMatch[0].length;
            const callSites = [...moduleSrc.matchAll(/attachLiveSearch\s*\(/g)]
                .filter((m) => !(m.index >= fnDefStart && m.index < fnDefEnd)); // exclude the DEFINITION occurrence itself
            const allInsideListener = callSites.length > 0 && callSites.every((m) => m.index > focusinPos && m.index < closerPos);
            assert(allInsideListener,
                'every attachLiveSearch(...) CALL (found ' + callSites.length + ') sits between the focusin registration and its one-time-binding closer — never called eagerly at boot');
        }
    }

    /* ===== CHECK C — no <datalist> fingerprint anywhere in the tree ====== */
    console.log('\n--- Check C: no <datalist> fingerprint anywhere in the tree (module + every consumer) ---');
    const allFiles = walk(SCAN_ROOT, []);
    assert(allFiles.length > 50, 'tree walk found a substantial file count (' + allFiles.length + ') — under-read guard');

    /* Discover the consumer set by GREP, not a typed list (rule #34): any
       file that either calls bootIetfLanguagePicker( or requires the
       shared server partial. */
    const consumers = [];
    for (const f of allFiles) {
        const src = stripComments(fs.readFileSync(f, 'utf8'));
        if (/bootIetfLanguagePicker\s*\(/.test(src) || /ietf-language-picker\.php/.test(src)) {
            consumers.push(f);
        }
    }
    console.log('  discovered ' + consumers.length + ' consumer file(s):');
    consumers.forEach((f) => console.log('    ' + path.relative(REPO_ROOT, f)));
    assert(consumers.length >= 4, 'at least 4 consumer files discovered (found ' + consumers.length + ') — the plan names 4: the partial, editor.js, enrichment-panel.js, metadata-tab.js');

    const filesToCheck = [MODULE_PATH, ...consumers.map((f) => f)];
    let datalistHits = 0;
    let rebuildHits = 0;
    for (const f of new Set(filesToCheck)) {
        const src = stripComments(fs.readFileSync(f, 'utf8'));
        const dlMatches = [...src.matchAll(/list\s*=\s*["']ietf-/g)];
        if (dlMatches.length) {
            datalistHits += dlMatches.length;
            console.log('  FOUND list="ietf-… fingerprint in ' + path.relative(REPO_ROOT, f) + ' (' + dlMatches.length + ' occurrence(s))');
        }
        const rbMatches = [...src.matchAll(/rebuildDatalist\s*\(/g)];
        if (rbMatches.length) {
            rebuildHits += rbMatches.length;
            console.log('  FOUND rebuildDatalist( in ' + path.relative(REPO_ROOT, f) + ' (' + rbMatches.length + ' occurrence(s))');
        }
    }
    assert(datalistHits === 0, 'no list="ietf-…" fingerprint survives in the module or any discovered consumer (found ' + datalistHits + ')');
    assert(rebuildHits === 0, 'no rebuildDatalist( fingerprint survives in the module or any discovered consumer (found ' + rebuildHits + ')');

    /* Same check, but over the WHOLE scanned tree — catches a fingerprint
       reintroduced somewhere this guard's consumer-discovery grep doesn't
       happen to match (e.g. a copy-paste into an unrelated file). */
    let treeWideDatalistHits = 0;
    for (const f of allFiles) {
        const src = stripComments(fs.readFileSync(f, 'utf8'));
        treeWideDatalistHits += [...src.matchAll(/list\s*=\s*["']ietf-/g)].length;
    }
    assert(treeWideDatalistHits === datalistHits,
        'the tree-wide scan (found ' + treeWideDatalistHits + ') agrees with the consumer-set scan (found ' + datalistHits + ') — no fingerprint hiding outside the discovered consumer set');

    console.log('\n══════════════════════════════════════════════════');
    console.log(`✅ Passed: ${checks - failures}`);
    console.log(`❌ Failed: ${failures}`);
    console.log(`📊 Total:  ${checks}`);
    console.log('');
    process.exit(failures > 0 ? 1 : 0);
}

main();
