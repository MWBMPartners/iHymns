/**
 * tests/test-dialog-recipe.js — modal-dialog focus-recipe guard
 * (a11y audit 2026-08-30, guard G3)
 *
 * ELI5: some pop-ups in this app are built by hand in JavaScript (not
 * Bootstrap's own modal component). A pop-up like that has to do FOUR
 * things to be usable by a keyboard-only or screen-reader user: tell
 * assistive tech "you're now inside a dialog" (`aria-modal`), actually
 * MOVE the keyboard focus into it, keep the Tab key from wandering back
 * out into the page behind it, and give focus back to whatever the user
 * was doing once the pop-up closes. This file checks that EVERY
 * hand-rolled pop-up in the app either uses the one shared helper that
 * does all four (`openModalDialog()`) or does all four itself.
 *
 * WHY THIS GUARD HAD TO BE WRITTEN
 * ---------------------------------
 * `js/utils/dialog-a11y.js` was extracted once two Live-Follow overlays
 * were found with `role="dialog"` and none of the focus machinery that
 * role implies (a11y audit M3, 2026-08-28). But the extraction fixed the
 * two overlays someone happened to look at — nobody then swept the WHOLE
 * tree for every other overlay with the identical gap. The 2026-08-30
 * audit found FOUR more: js/modules/shortcuts.js (H2 — worse than
 * nothing, since it declared `aria-modal="true"` while doing none of the
 * rest, actively misleading a screen reader), js/modules/display.js's
 * presentation overlay (M2), js/modules/compare.js (M3), and
 * js/app.js's quick-jump picker (M4). All four are now fixed by adopting
 * `openModalDialog()`. This guard turns "someone eventually re-audits
 * the tree" into "the build fails the moment a new one ships broken".
 *
 * WHAT COUNTS AS "SETS role=dialog" (the trigger for this check): a JS
 * module that calls `.setAttribute('role', 'dialog')` (or the
 * double-quoted / `role="dialog"` literal-string form some future
 * overlay might use) on a JS-built overlay element — the SAME "this is
 * a hand-rolled dialog" fingerprint the a11y audit itself used.
 *
 * WHAT COUNTS AS "HAS THE RECIPE" — EITHER:
 *   (a) the file both IMPORTS dialog-a11y.js (static `import { … } from
 *       '…/dialog-a11y.js'` OR the dynamic `import('…/dialog-a11y.js')`
 *       form js/modules/admin-wizard.js uses to defer the cost for
 *       hosts that never need it) AND calls `openModalDialog(`; OR
 *   (b) all FOUR inline markers are present in the comment-stripped
 *       source: `aria-modal`, a `.focus(` call, an Escape branch
 *       (`e.key === 'Escape'`), and a Tab-trap fingerprint — either
 *       `e.key === 'Tab'` or `e.key !== 'Tab'` (both real patterns exist
 *       in this codebase: js/modules/print.js and
 *       js/modules/external-link-interstitial.js use the two different
 *       phrasings for the SAME correct trap), or the extracted
 *       `trapTabKey`/`trapTab` helper names.
 * This is a whole-FILE check (not per-dialog inside a file with more
 * than one) — deliberately coarse, matching how the audit itself
 * described the check; a file with two dialogs where only one has the
 * recipe still needs a human to notice which, but the guard at least
 * catches a file with NEITHER.
 *
 * SCOPE: appWeb/public_html/js/** only — every real `setAttribute('role',
 * 'dialog')` call in the whole tree (js/** AND manage/**, verified by a
 * full-tree grep while building this guard) lives under js/modules or
 * js/app.js, so this mirrors tests/test-icon-button-names.js's own
 * js/**-only scope rather than needlessly widening it.
 *
 * MUST START GREEN (per the audit's own instruction): written AFTER
 * fixing H2/M2/M3/M4, so every file it scans already has the recipe.
 *
 * MUTATION PROOF (rule #34): js/modules/present-mode.js — a currently-
 * correct, ALREADY-INLINE (not openModalDialog-based) implementation — is
 * read from disk, its Escape branch is stripped IN MEMORY ONLY, and this
 * guard is proven to go RED on the mutated copy while staying green on
 * the real file.
 *
 * Usage: node tests/test-dialog-recipe.js
 * Exit 0 = pass, 1 = fail.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');
const SCAN_ROOT = path.join(PUB, 'js'); // mirrors test-icon-button-names.js's scope — see file header

let passed = 0;
let failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* Comment stripper — identical shape to tests/test-icon-button-names.js's
 * stripComments() (state machine over //, block comments, and quoted /
 * template-literal spans, which are copied through verbatim). */
function stripComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    let state = 'code';
    while (i < n) {
        const c = src[i];
        const c2 = src[i + 1];
        if (state === 'code') {
            if (c === '/' && c2 === '*') { state = 'block'; i += 2; continue; }
            if (c === '/' && c2 === '/') { state = 'line'; i += 2; continue; }
            if (c === "'") { state = 'sq'; out += c; i += 1; continue; }
            if (c === '"') { state = 'dq'; out += c; i += 1; continue; }
            if (c === '`') { state = 'tpl'; out += c; i += 1; continue; }
            out += c; i += 1; continue;
        }
        if (state === 'block') {
            if (c === '*' && c2 === '/') { state = 'code'; i += 2; continue; }
            out += (c === '\n') ? '\n' : ''; i += 1; continue;
        }
        if (state === 'line') {
            if (c === '\n') { state = 'code'; out += '\n'; i += 1; continue; }
            i += 1; continue;
        }
        const closers = { sq: "'", dq: '"', tpl: '`' };
        const closer = closers[state];
        if (c === '\\') { out += c + (c2 ?? ''); i += 2; continue; }
        if (c === closer) { state = 'code'; out += c; i += 1; continue; }
        out += c; i += 1;
    }
    return out;
}

const SETS_ROLE_DIALOG = /setAttribute\(\s*['"]role['"]\s*,\s*['"]dialog['"]\s*\)|role\s*=\s*["']dialog["']/;
const HAS_OPEN_MODAL_DIALOG_CALL = /\bopenModalDialog\s*\(/;
const IMPORTS_DIALOG_A11Y = /dialog-a11y\.js/;
const HAS_ARIA_MODAL = /aria-modal/;
const HAS_FOCUS_CALL = /\.focus\(/;
const HAS_ESCAPE_BRANCH = /e\.key\s*===\s*['"]Escape['"]/;
const HAS_TAB_TRAP_FINGERPRINT = /e\.key\s*(?:===|!==)\s*['"]Tab['"]|trapTabKey|function\s+trapTab\b/;

/**
 * @param {string} strippedSrc comment-stripped module source.
 * @returns {{triggers:boolean, hasRecipe:boolean, via:string}} whether
 *   this file sets role="dialog" at all, and if so whether it has the
 *   recipe (and which path satisfied it, for a clearer failure message).
 */
function evaluateModule(strippedSrc) {
    const triggers = SETS_ROLE_DIALOG.test(strippedSrc);
    if (!triggers) { return { triggers: false, hasRecipe: true, via: 'n/a' }; }

    const viaHelper = IMPORTS_DIALOG_A11Y.test(strippedSrc) && HAS_OPEN_MODAL_DIALOG_CALL.test(strippedSrc);
    if (viaHelper) { return { triggers: true, hasRecipe: true, via: 'openModalDialog()' }; }

    const viaInline = HAS_ARIA_MODAL.test(strippedSrc)
        && HAS_FOCUS_CALL.test(strippedSrc)
        && HAS_ESCAPE_BRANCH.test(strippedSrc)
        && HAS_TAB_TRAP_FINGERPRINT.test(strippedSrc);
    if (viaInline) { return { triggers: true, hasRecipe: true, via: 'all four inline markers' }; }

    return { triggers: true, hasRecipe: false, via: 'neither' };
}

function walkJs(dir, acc = []) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
        if (ent.name === 'vendor' || ent.name === 'node_modules' || ent.name === '.git') { continue; }
        const full = path.join(dir, ent.name);
        if (ent.isDirectory()) { walkJs(full, acc); }
        else if (ent.name.endsWith('.js') && !ent.name.endsWith('.min.js')) { acc.push(full); }
    }
    return acc;
}

/* ------------------------------------------------------------------------
 * Assertion 0 — synthetic fixture self-test.
 * ---------------------------------------------------------------------- */
console.log('Assertion 0 — scanner self-test on synthetic fixtures:');

check('a file with no role="dialog" at all does not trigger the check',
    evaluateModule("const x = 1;").triggers === false);

check('a file that sets role="dialog" but has NEITHER path is flagged',
    (() => {
        const r = evaluateModule("overlay.setAttribute('role', 'dialog');");
        return r.triggers === true && r.hasRecipe === false;
    })());

check('openModalDialog() + a static import of dialog-a11y.js passes',
    (() => {
        const r = evaluateModule(
            "import { openModalDialog } from '../utils/dialog-a11y.js';\n"
            + "overlay.setAttribute('role', 'dialog');\n"
            + "openModalDialog(overlay, {});"
        );
        return r.triggers === true && r.hasRecipe === true && r.via === 'openModalDialog()';
    })());

check('openModalDialog() + a DYNAMIC import of dialog-a11y.js also passes (the admin-wizard.js shape)',
    (() => {
        const r = evaluateModule(
            "overlay.setAttribute('role', 'dialog');\n"
            + "import('../utils/dialog-a11y.js').then(({ openModalDialog }) => { openModalDialog(overlay, {}); });"
        );
        return r.triggers === true && r.hasRecipe === true;
    })());

check('all four inline markers (e.key === "Tab" phrasing) passes without openModalDialog',
    (() => {
        const r = evaluateModule(
            "overlay.setAttribute('role', 'dialog');\n"
            + "overlay.setAttribute('aria-modal', 'true');\n"
            + "overlay.focus();\n"
            + "function onKey(e) { if (e.key === 'Escape') { close(); } else if (e.key === 'Tab') { trap(); } }"
        );
        return r.triggers === true && r.hasRecipe === true && r.via === 'all four inline markers';
    })());

check('all four inline markers (e.key !== "Tab" phrasing — the external-link-interstitial.js shape) also passes',
    (() => {
        const r = evaluateModule(
            "overlay.setAttribute('role', 'dialog');\n"
            + "overlay.setAttribute('aria-modal', 'true');\n"
            + "goEl.focus();\n"
            + "overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { close(); return; } if (e.key !== 'Tab') { return; } trap(); });"
        );
        return r.triggers === true && r.hasRecipe === true;
    })());

check('missing JUST the Escape branch fails even with the other three markers present',
    (() => {
        const r = evaluateModule(
            "overlay.setAttribute('role', 'dialog');\n"
            + "overlay.setAttribute('aria-modal', 'true');\n"
            + "overlay.focus();\n"
            + "function onKey(e) { if (e.key === 'Tab') { trap(); } }"
        );
        return r.triggers === true && r.hasRecipe === false;
    })());

check('a doc-comment merely MENTIONING role="dialog" is not treated as a real dialog (comment stripper works)',
    evaluateModule(stripComments("// old markup used overlay.setAttribute('role', 'dialog') here\nconst x = 1;")).triggers === false);

/* ------------------------------------------------------------------------
 * Assertion 1 — LIVE mutation proof (rule #34) against
 * js/modules/present-mode.js — a currently-correct, hand-rolled (NOT
 * openModalDialog-based) implementation. Read from disk, its Escape
 * branch stripped IN MEMORY ONLY, never written back.
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 1 — live mutation proof (js/modules/present-mode.js):');

const presentModePath = path.join(PUB, 'js', 'modules', 'present-mode.js');
const presentModeRaw = fs.existsSync(presentModePath) ? fs.readFileSync(presentModePath, 'utf8') : '';
check('js/modules/present-mode.js exists', presentModeRaw !== '');

if (presentModeRaw !== '') {
    const stripped = stripComments(presentModeRaw);
    const escapeAnchor = "if (e.key === 'Escape') { close(); }";
    check('the expected Escape-branch anchor is present (fixture shape check — if this fails, the source '
        + 'moved and this proof needs a new anchor, not that the fix regressed)',
        stripped.includes(escapeAnchor));

    const evalAsIs = evaluateModule(stripped);
    check('the scanner does NOT flag present-mode.js AS-IS (already correctly implements the recipe inline)',
        evalAsIs.triggers === true && evalAsIs.hasRecipe === true);

    const mutated = stripped.replace(escapeAnchor, '/* escape branch removed */');
    check('the mutation anchor was actually found and replaced (sanity check on the replace() call itself)',
        mutated !== stripped);
    const evalMutated = evaluateModule(mutated);
    check('the scanner GOES RED when the Escape branch is stripped IN MEMORY (proves this guard would catch '
        + 'a future regression of the exact class H2 was)',
        evalMutated.triggers === true && evalMutated.hasRecipe === false);
}

/* ------------------------------------------------------------------------
 * Assertion 2 — the real scan across js/** (tree-derived, rule #34).
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 2 — real scan across appWeb/public_html/js/**:');

const allFiles = walkJs(SCAN_ROOT);
check(`scanned a plausible number of files (${allFiles.length} walked)`,
    allFiles.length >= 70, `only ${allFiles.length} .js files walked under appWeb/public_html/js — the tree walk under-read`);

let dialogModulesSeen = 0;
for (const file of allFiles) {
    const rel = path.relative(PUB, file);
    const src = stripComments(fs.readFileSync(file, 'utf8'));
    const result = evaluateModule(src);
    if (!result.triggers) { continue; }
    dialogModulesSeen++;
    check(`${rel} — has the modal-dialog focus recipe (via ${result.via})`, result.hasRecipe,
        result.hasRecipe ? '' : 'sets role="dialog" but has neither openModalDialog() (imported from dialog-a11y.js) '
            + 'nor all four inline markers (aria-modal, a .focus() call, an Escape branch, and a Tab-trap). '
            + 'WCAG 2.4.3/2.1.1 — see js/utils/dialog-a11y.js for the fix.');
}
check(`at least one real role="dialog" module was found in the tree (sanity — a scan that finds zero is `
    + 'suspiciously scoped, not a genuine all-clear)', dialogModulesSeen >= 5,
    `only found ${dialogModulesSeen} — expected shortcuts.js, display.js, compare.js, app.js, present-mode.js, `
    + 'live-follow.js, live-host-console.js, print.js (x2), external-link-interstitial.js at minimum');

/* ------------------------------------------------------------------------ */
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll dialog-recipe checks passed for appWeb/public_html/js/**.');
