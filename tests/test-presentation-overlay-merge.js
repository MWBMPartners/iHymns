/**
 * tests/test-presentation-overlay-merge.js — only ONE module may build a
 * presentation overlay (#1714 item 5).
 *
 * ELI5
 * ----
 * There used to be TWO different full-screen "presentation mode" pop-ups.
 * The "Present" toolbar button opened a good one (present-mode.js — one
 * section at a time, arrow keys, a focus trap, foot-pedal and swipe
 * support). The "P" keyboard shortcut opened a worse one, built completely
 * separately in display.js (the whole song scrolling past at once, no
 * arrow keys) — which happened to be the only place with a blank-screen
 * button and a pre-service countdown. Because they were two different
 * things, the global "B" key (which blanks the screen) looked up an id
 * (`#presentation-overlay`) that the GOOD overlay never set, so pressing B
 * while presenting from the button people actually use did nothing at
 * all — no error, just silence.
 *
 * The fix deletes display.js's overlay outright and moves its two features
 * into present-mode.js, so there is exactly one place that builds this
 * kind of overlay. This file is the guard that keeps it that way: if
 * anyone ever adds a SECOND file that creates a `.presentation-overlay`
 * element, this test fails the build.
 *
 * WHY A FINGERPRINT, NOT A FILE LIST (rule #34)
 * ----------------------------------------------
 * A hardcoded "these two files are the presentation overlays" list would
 * pass forever even after a third one appeared, because nothing derives it
 * from the tree. Instead this scans EVERY `.js` file under
 * `appWeb/public_html/js/` (recursively — the whole tree, not a hand-typed
 * subset) for the actual DOM-creation act: assigning the literal class
 * name `presentation-overlay` to an element via `.className =`,
 * `.classList.add(...)`, or `.id =` (the id fingerprint catches
 * display.js's old shape too, which set BOTH an id and the class on the
 * same element). Comments are stripped first (a doc-comment merely
 * MENTIONING `.presentation-overlay` — and several files do, including
 * this one's own header — must not count as "creating" it); a
 * string/template/regex-aware stripper is required because a naive one
 * would also blank out the very string literal this test is searching
 * for.
 *
 * A QUERY (`document.querySelector('.presentation-overlay')`, which app.js
 * and midi-input.js both do, correctly) never matches any of the three
 * fingerprints — a CSS selector string always carries a leading `.`, while
 * a `className`/`id` assignment or `classList.add()` call never does. That
 * asymmetry is what lets "reads/queries the overlay" and "creates the
 * overlay" be told apart without understanding the surrounding code.
 *
 * MUTATION PROOF (rule #34)
 * --------------------------
 * Section 2 proves the check can actually fail: it takes an in-memory copy
 * of the real file map, adds ONE fake creation line to a file that isn't
 * present-mode.js (simulating exactly the regression this guard exists to
 * catch — a second overlay reappearing), and asserts the SAME scanning
 * function now reports two creators instead of one. No file on disk is
 * touched.
 *
 * Section 3 checks the two teardown-discipline details specific to this
 * merge: `close()` in present-mode.js clears BOTH of this overlay's timers
 * (a playing round's clock, and now the pre-service countdown) before
 * anything else, per rule #32's sibling in .claude/CLAUDE.md ("any timer
 * fixed to the overlay MUST be cleared the instant it closes, as the very
 * first action, before any early return") — a countdown left running
 * behind a closed overlay is a real, if invisible, bug. Section 4 is a
 * small correctness check on the countdown's pure formatting helper.
 *
 * @see appWeb/public_html/js/modules/present-mode.js  the ONE overlay now
 * @see appWeb/public_html/js/modules/display.js        the two thin callers
 * @see tests/test-present-round-projector.js            the sibling guard
 *      that proves close() clears the round timer FIRST; this file adds
 *      the countdown to that same discipline rather than duplicating it.
 *
 *   node tests/test-presentation-overlay-merge.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..');
const JS_ROOT = path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'js');
const PRESENT_MODE_REL = path.join('appWeb', 'public_html', 'js', 'modules', 'present-mode.js');
const DISPLAY_REL = path.join('appWeb', 'public_html', 'js', 'modules', 'display.js');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail) {
    if (cond) {
        passed++;
        console.log(`  ✓ ${label}`);
    } else {
        failed++;
        failures.push(detail ? `${label} — ${detail}` : label);
        console.log(`  ✗ ${label}${detail ? ' — ' + detail : ''}`);
    }
}

/* String/template-literal/regex-AWARE JS comment stripper — identical in
   shape to the one in tests/test-present-round-projector.js and
   tests/test-event-names.js (see either file's header for the full
   rationale). Copied rather than imported — each tests/test-*.js file is
   self-contained per house style. */
function stripJsComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    let mode = null; // null | 'sq' | 'dq' | 'tpl' | 'line' | 'block' | 'regex'
    let inCharClass = false;
    let lastSig = '';
    const REGEX_PRECEDERS = new Set(['(', ',', '=', ':', ';', '!', '&', '|', '?', '{', '[', '+', '-', '*', '%', '<', '>', '\n', '']);

    while (i < n) {
        const c = src[i];
        const c2 = i + 1 < n ? src[i + 1] : '';

        if (mode === 'line') {
            if (c === '\n') { out += '\n'; mode = null; } else { out += ' '; }
            i++; continue;
        }
        if (mode === 'block') {
            if (c === '*' && c2 === '/') { out += '  '; i += 2; mode = null; continue; }
            out += (c === '\n' ? '\n' : ' ');
            i++; continue;
        }
        if (mode === 'sq' || mode === 'dq' || mode === 'tpl') {
            if (c === '\\') { out += c + c2; i += 2; continue; }
            const closer = mode === 'sq' ? '\'' : mode === 'dq' ? '"' : '`';
            out += c;
            if (c === closer) { mode = null; lastSig = closer; }
            i++; continue;
        }
        if (mode === 'regex') {
            if (c === '\\') { out += c + c2; i += 2; continue; }
            if (c === '[') { inCharClass = true; out += c; i++; continue; }
            if (c === ']') { inCharClass = false; out += c; i++; continue; }
            if (c === '/' && !inCharClass) {
                out += c; i++;
                while (i < n && /[a-z]/i.test(src[i])) { out += src[i]; i++; }
                mode = null; lastSig = '/';
                continue;
            }
            if (c === '\n') { mode = null; out += c; i++; continue; }
            out += c; i++; continue;
        }

        if (c === '/' && c2 === '/') { mode = 'line'; out += '  '; i += 2; continue; }
        if (c === '/' && c2 === '*') { mode = 'block'; out += '  '; i += 2; continue; }
        if (c === '/' && REGEX_PRECEDERS.has(lastSig)) { mode = 'regex'; inCharClass = false; out += c; i++; continue; }
        if (c === '\'') { mode = 'sq'; out += c; i++; continue; }
        if (c === '"') { mode = 'dq'; out += c; i++; continue; }
        if (c === '`') { mode = 'tpl'; out += c; i++; continue; }
        out += c;
        if (!/\s/.test(c)) { lastSig = c; }
        i++;
    }
    return out;
}

/* The three ways JS in this codebase could create an element carrying the
   `presentation-overlay` class/id. A CSS-selector STRING (used to QUERY an
   existing overlay, e.g. `document.querySelector('.presentation-overlay')`)
   always carries a leading '.', so it never matches any of these — that is
   the load-bearing distinction between "reads it" and "creates it". */
const CREATION_FINGERPRINTS = [
    /\.className\s*=\s*(['"])presentation-overlay\1/,
    /\.classList\.add\(\s*(['"])presentation-overlay\1\s*\)/,
    /\.id\s*=\s*(['"])presentation-overlay\1/,
];

/**
 * Recursively list every `.js` file under `dir`, relative to
 * PROJECT_ROOT, sorted for a stable report. Tree-derived (rule #34) — no
 * hand-typed file list anywhere in this test.
 */
function listJsFilesRecursive(dir) {
    let out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            out = out.concat(listJsFilesRecursive(full));
        } else if (entry.isFile() && entry.name.endsWith('.js')) {
            out.push(path.relative(PROJECT_ROOT, full));
        }
    }
    return out.sort();
}

/**
 * Given a Map<relPath, source>, return the sorted list of relPaths whose
 * (comment-stripped) source contains at least one creation fingerprint.
 * Pure — no filesystem access — so section 2 can run it against a MUTATED
 * in-memory copy without touching any real file.
 */
function findOverlayCreators(fileMap) {
    const hits = [];
    for (const [rel, src] of fileMap) {
        const stripped = stripJsComments(src);
        if (CREATION_FINGERPRINTS.some((re) => re.test(stripped))) {
            hits.push(rel);
        }
    }
    return hits.sort();
}

console.log('\n1 — exactly one file under js/ creates a presentation overlay\n');

const relFiles = listJsFilesRecursive(JS_ROOT);
check(`found a non-trivial number of .js files under js/ (tree-derived, not a typed list)`, relFiles.length > 50,
    `found ${relFiles.length} — suspiciously few, check JS_ROOT`);

const realFileMap = new Map(relFiles.map((rel) => [rel, fs.readFileSync(path.join(PROJECT_ROOT, rel), 'utf8')]));
const realCreators = findOverlayCreators(realFileMap);

check('exactly one file creates a `.presentation-overlay` element',
    realCreators.length === 1,
    `found ${realCreators.length}: ${JSON.stringify(realCreators)}`);
check('that one file is present-mode.js',
    realCreators.length === 1 && realCreators[0] === PRESENT_MODE_REL,
    `found: ${JSON.stringify(realCreators)}`);
check('display.js is NOT one of them (its old, separate overlay was deleted, not just hidden)',
    !realCreators.includes(DISPLAY_REL));

console.log('\n2 — MUTATION PROOF: reintroducing a second overlay creator makes the check fail\n');
{
    const mutatedMap = new Map(realFileMap);
    const displaySrc = mutatedMap.get(DISPLAY_REL);
    check('display.js was found on disk to mutate', typeof displaySrc === 'string');
    if (typeof displaySrc === 'string') {
        const reintroduced = displaySrc + '\n// simulated regression:\noverlay.className = \'presentation-overlay\';\n';
        mutatedMap.set(DISPLAY_REL, reintroduced);
        const mutatedCreators = findOverlayCreators(mutatedMap);
        check('MUTATION PROOF: an in-memory reintroduction is caught (creator count goes from 1 to 2)',
            mutatedCreators.length === 2 && mutatedCreators.includes(DISPLAY_REL) && mutatedCreators.includes(PRESENT_MODE_REL),
            `found ${mutatedCreators.length}: ${JSON.stringify(mutatedCreators)}`);
    }
}

console.log('\n3 — close() clears BOTH of this overlay\'s timers before anything else\n');
{
    /** Extract a top-level `function <name>(...) { ... }` BODY (the text
     *  between its outermost braces) by brace-counting from the first
     *  match — robust to nested braces, which a non-greedy regex alone
     *  cannot handle correctly. Same technique as
     *  tests/test-present-round-projector.js's own helper of the same
     *  name (copied, not imported — house style). */
    function extractFunctionBody(strippedSrc, fnName) {
        const marker = 'function ' + fnName + '(';
        const at = strippedSrc.indexOf(marker);
        if (at === -1) { return null; }
        const openBrace = strippedSrc.indexOf('{', at);
        if (openBrace === -1) { return null; }
        let depth = 1;
        let i = openBrace + 1;
        while (i < strippedSrc.length && depth > 0) {
            if (strippedSrc[i] === '{') { depth++; }
            else if (strippedSrc[i] === '}') { depth--; }
            i++;
        }
        return strippedSrc.slice(openBrace + 1, i - 1);
    }

    const presentModeSrc = fs.readFileSync(path.join(PROJECT_ROOT, PRESENT_MODE_REL), 'utf8');
    const stripped = stripJsComments(presentModeSrc);

    const closeBody = extractFunctionBody(stripped, 'close');
    check('close() is found in present-mode.js', closeBody !== null);
    if (closeBody !== null) {
        const trimmed = closeBody.trim();
        check('close() calls stopRoundPlayback() as its very FIRST statement (round timer)',
            trimmed.startsWith('stopRoundPlayback();'),
            'close() body (comments blanked) starts with: ' + JSON.stringify(trimmed.slice(0, 60)));

        /* The countdown is the SECOND thing cleared — right after the round
           timer, still before anything else (activePresentation reset,
           releaseWakeLock(), fullscreen exit, overlay.remove(), ...).
           Whitespace between the two statements (a real newline + indent in
           the actual source) is collapsed out first so this checks ORDER,
           not incidental formatting. */
        const noWhitespace = trimmed.replace(/\s+/g, '');
        check('close() calls stopCountdown() as its SECOND statement (pre-service countdown timer)',
            noWhitespace.startsWith('stopRoundPlayback();stopCountdown();'),
            'close() body (comments+whitespace blanked) starts with: ' + JSON.stringify(noWhitespace.slice(0, 60)));
        check('close() clears the module-level "is anything open" slot (activePresentation = null)',
            noWhitespace.includes('activePresentation=null;'));

        console.log('  --- MUTATION PROOF: this structural check goes RED if the line is removed (in-memory only) ---');
        const mutatedNoCountdown = noWhitespace.replace('stopCountdown();', '');
        check('MUTATION PROOF: removing stopCountdown() from an in-memory copy fails the same assertion',
            !mutatedNoCountdown.startsWith('stopRoundPlayback();stopCountdown();'));
    }

    /* toggleBlank() must exist and must be reachable via the module-level
       activePresentation slot — checked structurally (function exists,
       and is referenced in the object literal that becomes
       activePresentation) rather than by driving a real DOM, since this
       repo has no jsdom-backed browser environment for this file (see
       tests/test-present-round-projector.js's own note on the same
       point). */
    check('toggleBlank() is defined in present-mode.js', stripped.includes('function toggleBlank()'));
    check('openPresentMode() records { close, toggleBlank } as the active presentation',
        /activePresentation\s*=\s*\{\s*close\s*,\s*toggleBlank\s*\}/.test(stripped));
}

console.log('\n4 — formatCountdown() truth table (pure function, no DOM required)\n');
{
    const mod = await import(path.join(PROJECT_ROOT, PRESENT_MODE_REL));
    check('present-mode.js exports formatCountdown', typeof mod.formatCountdown === 'function');
    if (typeof mod.formatCountdown === 'function') {
        const cases = [
            [0, '0:00'],
            [5, '0:05'],
            [65, '1:05'],
            [599, '9:59'],
            [600, '10:00'],
            [3599, '59:59'],
            [3600, '1:00:00'],
            [3661, '1:01:01'],
            [-5, '0:00'], // clamped
        ];
        for (const [input, expected] of cases) {
            const got = mod.formatCountdown(input);
            check(`formatCountdown(${input}) === ${JSON.stringify(expected)}`, got === expected, `got ${JSON.stringify(got)}`);
        }
    }

    check('present-mode.js also exports the presentation-control functions the "P"/"B" keys need',
        typeof mod.togglePresentMode === 'function'
        && typeof mod.toggleBlankScreen === 'function'
        && typeof mod.closePresentMode === 'function'
        && typeof mod.openPresentMode === 'function');
}

console.log('\n5 — display.js only ever reaches present-mode.js through a dynamic import\n');
{
    const displaySrc = fs.readFileSync(path.join(PROJECT_ROOT, DISPLAY_REL), 'utf8');
    const stripped = stripJsComments(displaySrc);
    check('display.js has no static top-level import of present-mode.js (would defeat the lazy-load)',
        !/^\s*import\s+.*from\s+(['"])\.\/present-mode\.js\1/m.test(stripped));
    check('display.js calls into present-mode.js via dynamic import() at least 3 times '
        + '(togglePresentationMode, toggleBlankScreen, the toolbar button, cleanup())',
        (stripped.match(/import\(\s*(['"])\.\/present-mode\.js\1\s*\)/g) || []).length >= 3);
}

console.log(`\n=== ${passed + failed} checks, ${failed} failed ===`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
}
process.exit(failed === 0 ? 0 : 1);
