/**
 * tests/test-icon-button-names.js — icon-only <button> naming guard for the
 * js/** tree (a11y audit 2026-08-30, finding A25.1)
 *
 * ELI5: some buttons in this app show only a little picture (an icon) with
 * no visible words — a "+" button, an "x" button, and so on. A sighted
 * mouse user can guess what they do from the picture. A screen-reader user
 * hears NOTHING unless the button also carries an `aria-label` or `title`
 * that says what it does in words. This test reads every JS module under
 * appWeb/public_html/js, finds every icon-only <button> those modules
 * BUILD (as HTML text inside a template literal — this is a JS app, so a
 * lot of markup is assembled in JS, not PHP), and fails if one has no name.
 *
 * WHY THIS GUARD HAD TO BE WRITTEN (not just re-run the existing one):
 * tests/php/test-a11y-static-checks.php already has an "icon-only control"
 * scanner (M8, a11yIconAccessibility()) — but it structurally cannot catch
 * this class of bug for two independent reasons, both of which let finding
 * A13 (js/modules/favorites.js's unnamed "Add tag" button) ship unnoticed:
 *   1. Its file list ($m2m8Targets) is built from `glob($dir.'/*.php')`
 *      only — .js files are never even opened.
 *   2. Its icon-glyph fingerprint (a11yIsBiIconClass()) matches ONLY
 *      Bootstrap-Icons' `bi`/`bi-*` classes. A13's icon was FontAwesome
 *      (`fa-solid fa-plus`) — a different icon library the PHP scanner
 *      was never taught to recognise.
 * Rather than bolt "also read .js" and "also match fa-" onto a scanner
 * whose whole regex pipeline (PHP-tag neutralisation, `<?=`/`<?php`
 * classification) is built around PHP source, this is a small JS-native
 * twin, following the tree-derived-glob + comment-stripped-source pattern
 * already established by tests/test-arrangement-a11y.js and
 * tests/test-component-label-sites.js (rule #34 — a hand-typed file list
 * only ever proves the files someone remembered to type).
 *
 * SCOPE (a11y audit G1, 2026-08-30 — WIDENED from the original js/**-only
 * carve-out): appWeb/public_html/js/** AND appWeb/public_html/manage/**
 * (the editor + admin JS tree). The original scope note here said the
 * admin JS tree was "a bigger surface this pass never audited" and left
 * widening to "a future pass, the same way #1990's M2/M8 PHP sweep grew a
 * second, wider target list instead of stretching the first one" — the
 * 2026-08-30 a11y audit WAS that future pass: it ran this exact scanner
 * over the 26 manage-tree JS files by hand and found zero unnamed
 * icon-only buttons, so widening SCAN_ROOTS below is free of scoping
 * games (this guard starts green over the wider tree, same as every
 * other guard rule #34 requires). Mirrors the PHP M2/M8 scanner's own
 * $m2m8Targets, which already covers manage/, manage/includes/,
 * manage/editor/, etc.
 *
 * WHAT COUNTS AS "ICON-ONLY, NAME REQUIRED" (mirrors a11yIconAccessibility()
 * rule (b) in test-a11y-static-checks.php, JS-flavoured):
 *   - a `<button …>…</button>` built inside the module's own template-
 *     literal / string source (found straight in the comment-stripped
 *     source text — no virtual DOM, no browser);
 *   - whose inner content, with every nested tag stripped, is blank
 *     (real static text — "Save", "Cancel" — already names the button,
 *     out of scope);
 *   - whose RAW inner content (before tags are stripped) contains no
 *     `${…}` JS interpolation either — a button whose label comes from a
 *     runtime expression (`${escapeHtml(title)}`) cannot be proven empty
 *     from source text alone, the exact same "decorative-beside-text"
 *     carve-out the PHP scanner gives a `<?= … ?>` echo;
 *   - that DOES contain at least one icon glyph — a Bootstrap-Icons
 *     `bi`/`bi-*` class OR a FontAwesome `fa-*` class (`fa-solid`,
 *     `fa-regular`, `fa-brands`, …) — inside an `<i …>` tag; a totally
 *     empty `<button id="…"></button>` a script populates later is a
 *     different, legitimate pattern and is skipped;
 *   - and carries NEITHER `aria-label=`/`aria-labelledby=`/`title=` on its
 *     own opening tag NOR a `.visually-hidden` span inside.
 *
 * MUTATION PROOF (rule #34 — a guard that was never shown able to fail is
 * not trusted): two independent proofs run BEFORE the real scan, every run:
 *   (1) a synthetic fixture proves the scanner both fires on a deliberately
 *       unnamed fa-icon button and stays silent on the same button once
 *       named, once it has visible text, once it's ${}-interpolated, and
 *       once it's a legitimately-empty script-populated placeholder;
 *   (2) a LIVE in-memory mutation against the real, currently-fixed
 *       js/modules/favorites.js "Add tag" button (A13's actual regression)
 *       — read from disk, aria-label stripped IN MEMORY ONLY (nothing is
 *       ever written back), reproving the scanner goes red on exactly the
 *       bug this guard exists to catch, then confirming the untouched file
 *       is clean. This is the same "read real file, mutate in memory,
 *       never write to disk" technique test-a11y-static-checks.php's own
 *       LIVE MUTATION PROOF section uses for languages.php / groups.php.
 *
 * Usage: node tests/test-icon-button-names.js
 * Exit 0 = pass, 1 = fail.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');
/* a11y audit G1 (2026-08-30) — widened from js/** only to ALSO include
 * manage/** (the editor + admin JS tree). See the SCOPE note above. */
const SCAN_ROOTS = [path.join(PUB, 'js'), path.join(PUB, 'manage')];

let passed = 0;
let failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* ========================================================================
 * Comment stripper — character-level state machine, identical in shape to
 * tests/test-arrangement-a11y.js's stripComments(). `//`/`/* *\/` comments
 * are blanked (newlines kept, so line numbers reported later stay exact);
 * single/double-quoted strings AND template literals are copied through
 * VERBATIM, because the actual HTML markup this guard needs to inspect
 * lives inside them. A naive "strip // to end of line" regex would wrongly
 * treat a `//` inside a `"https://…"` string as a comment start — the
 * state machine tracks which kind of span it's in so that can't happen.
 * ====================================================================== */
function stripComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    /** @type {'code'|'block'|'line'|'sq'|'dq'|'tpl'} */
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
            out += c;
            i += 1;
            continue;
        }

        if (state === 'block') {
            if (c === '*' && c2 === '/') { state = 'code'; i += 2; continue; }
            out += (c === '\n') ? '\n' : '';
            i += 1;
            continue;
        }

        if (state === 'line') {
            if (c === '\n') { state = 'code'; out += '\n'; i += 1; continue; }
            i += 1;
            continue;
        }

        const closers = { sq: "'", dq: '"', tpl: '`' };
        const closer = closers[state];
        if (c === '\\') { out += c + (c2 ?? ''); i += 2; continue; }
        if (c === closer) { state = 'code'; out += c; i += 1; continue; }
        out += c;
        i += 1;
    }

    return out;
}

/* ========================================================================
 * Icon-glyph fingerprint — the one difference from the PHP M8 scanner's
 * a11yIsBiIconClass(): this also matches FontAwesome's `fa-*` family
 * (the exact gap that let A13 through), not just Bootstrap-Icons' `bi`.
 * ====================================================================== */
function isIconClass(classAttrValue) {
    return /(?:^|\s)bi(?:$|[\s-])/.test(classAttrValue) || /(?:^|\s)fa-/.test(classAttrValue);
}

/* ========================================================================
 * The scanner — returns 1-based line numbers (against `src`, which must
 * already be comment-stripped so newline positions match the original
 * file) of every icon-only, unnamed <button>…</button>. See the file
 * header's "WHAT COUNTS AS…" section for the exact rule.
 *
 * The inner-content capture is bounded to ~800 chars (JS-built button
 * markup runs a little longer than the PHP equivalent — multi-line
 * template literals with indentation) and guarded with a negative
 * lookahead against a nested `<button`/`</button` so one regex pass can
 * never straddle two sibling buttons or run away across an unclosed tag.
 * ====================================================================== */
function findUnnamedIconButtons(src) {
    const lines = [];
    const pattern = /<button\b([^>]*)>((?:(?!<\/?button\b)[\s\S]){0,800}?)<\/button>/gi;
    let m;
    while ((m = pattern.exec(src)) !== null) {
        const attrs = m[1];
        const inner = m[2];
        const offset = m.index;

        /* A `${…}` JS interpolation anywhere in the RAW inner content means
           the button's content may come from a runtime value (an echoed
           label, an escapeHtml(title) call, …) — a static text scanner
           cannot know what that expression renders, so treat it the same
           way the PHP scanner treats a `<?= … ?>` echo: decorative-beside-
           text, not this check's concern. */
        if (inner.includes('${')) { continue; }

        /* Real static visible text (tags stripped) already names the
           button. */
        if (inner.replace(/<[^>]*>/g, '').trim() !== '') { continue; }

        /* Only a concern if an icon glyph is actually inside — a totally
           empty <button id="…"></button> a script populates later is a
           different, legitimate pattern. */
        let hasIcon = false;
        const iconTagPattern = /<i\b[^>]*>/gi;
        let im;
        while ((im = iconTagPattern.exec(inner)) !== null) {
            const classMatch = /class\s*=\s*"([^"]*)"/i.exec(im[0]);
            if (classMatch && isIconClass(classMatch[1])) { hasIcon = true; break; }
        }
        if (!hasIcon) { continue; }

        const named = /\b(?:aria-label|aria-labelledby|title)\s*=/i.test(attrs)
            || /class\s*=\s*"[^"]*\bvisually-hidden\b[^"]*"/i.test(inner);
        if (!named) {
            lines.push(src.slice(0, offset).split('\n').length);
        }
    }
    return lines;
}

/* ========================================================================
 * Tree walk — js/** only, skipping vendor/node_modules/.git and minified
 * files, mirroring tests/test-component-label-sites.js's walkJs().
 * ====================================================================== */
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
 * Assertion 0 — synthetic fixture self-test (rule #34: prove the scanner
 * CAN both fire and stay silent, on hand-typed text, before trusting it
 * against the real tree).
 * ---------------------------------------------------------------------- */
console.log('Assertion 0 — scanner self-test on synthetic fixtures:');

check('flags an unnamed fa-icon-only button',
    findUnnamedIconButtons('const h = `<button type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>`;').length === 1);

check('flags an unnamed bi-icon-only button (parity with the PHP M8 scanner)',
    findUnnamedIconButtons('const h = `<button type="button"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`;').length === 1);

check('does NOT flag the same button once it has aria-label',
    findUnnamedIconButtons('const h = `<button type="button" aria-label="Add tag"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>`;').length === 0);

check('does NOT flag the same button once it has title',
    findUnnamedIconButtons('const h = `<button type="button" title="Add tag"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>`;').length === 0);

check('does NOT flag a button with a .visually-hidden span instead of aria-label',
    findUnnamedIconButtons('const h = `<button type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i><span class="visually-hidden">Add tag</span></button>`;').length === 0);

check('does NOT flag a button that has real visible text alongside its icon',
    findUnnamedIconButtons('const h = `<button type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add tag</button>`;').length === 0);

check('does NOT flag a button whose label is a ${} runtime interpolation (decorative-beside-text)',
    findUnnamedIconButtons('const h = `<button type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i> ${escapeHtml(label)}</button>`;').length === 0);

check('does NOT flag a legitimately-empty script-populated placeholder button (no icon inside)',
    findUnnamedIconButtons('const h = `<button type="button" id="x-btn"></button>`;').length === 0);

check('does NOT flag a plain text button with no icon at all',
    findUnnamedIconButtons('const h = `<button type="button">Save</button>`;').length === 0);

check('the SAME unnamed-button text inside a // comment is NOT flagged (comment stripper works)',
    findUnnamedIconButtons(stripComments('// old markup: `<button type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>`\nconst x = 1;')).length === 0);

check('the SAME unnamed-button text inside a /* block */ comment is NOT flagged',
    findUnnamedIconButtons(stripComments('/* old markup: `<button type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>` */\nconst x = 1;')).length === 0);

check('a `//` inside a string is NOT treated as a comment start by the stripper',
    stripComments('const url = "https://example.com/x";').includes('https://example.com/x'));

/* ------------------------------------------------------------------------
 * Assertion 1 — LIVE in-memory mutation proof against the real, currently-
 * fixed js/modules/favorites.js "Add tag" button (A13's actual regression).
 * Read from disk, mutated ONLY in a local string, never written back —
 * same technique test-a11y-static-checks.php's LIVE MUTATION PROOF section
 * uses against manage/languages.php / manage/groups.php.
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 1 — live mutation proof (js/modules/favorites.js "Add tag" button):');

const favoritesPath = path.join(PUB, 'js', 'modules', 'favorites.js');
const favoritesRaw = fs.existsSync(favoritesPath) ? fs.readFileSync(favoritesPath, 'utf8') : '';
check('js/modules/favorites.js exists', favoritesRaw !== '');

if (favoritesRaw !== '') {
    const favoritesStripped = stripComments(favoritesRaw);
    const nameAnchor = 'id="tag-custom-add"\n                                    aria-label="Add tag">';
    check('the expected "Add tag" aria-label anchor is present (fixture shape check — if this fails, '
        + 'the source moved and this proof needs a new anchor, not that the fix regressed)',
        favoritesStripped.includes(nameAnchor));

    check('the scanner does NOT flag the button AS-IS (already correctly named — no false positive)',
        findUnnamedIconButtons(favoritesStripped).length === 0);

    const mutated = favoritesStripped.replace(nameAnchor, 'id="tag-custom-add">');
    check('the scanner GOES RED when aria-label is stripped from that SAME button IN MEMORY '
        + '(reproduces A13 exactly — proves this guard would have caught it)',
        findUnnamedIconButtons(mutated).length === 1);
}

/* ------------------------------------------------------------------------
 * Assertion 1b — G1 (2026-08-30) LIVE mutation proof that the WIDENED
 * scan roots actually reach manage/** file CONTENT. Unlike favorites.js's
 * A13 regression, no manage-tree file currently builds an icon-only
 * button as literal `<button>…</button>` markup this text-scanner can
 * see — the one manage-tree helper built for exactly that purpose
 * (js/modules... no — manage/editor/v2/ui-helpers.js's iconBtn()) builds
 * its button via DOM API calls (createElement/setAttribute/innerHTML on
 * the icon fragment alone), not one HTML literal a regex can match. So
 * this proof INJECTS the known-bad pattern into a REAL manage-tree
 * file's real content (nothing is ever written back) — proving the
 * widened walk genuinely reads and scans manage/** source, not just
 * that the scanner function itself works (Assertion 0 already proved
 * that on hand-typed fixtures).
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 1b — G1 live proof that manage/** is actually scanned:');

const reflowModalPath = path.join(PUB, 'manage', 'editor', 'v2', 'reflow-modal.js');
const reflowModalRaw = fs.existsSync(reflowModalPath) ? fs.readFileSync(reflowModalPath, 'utf8') : '';
check('manage/editor/v2/reflow-modal.js exists', reflowModalRaw !== '');

if (reflowModalRaw !== '') {
    const reflowStripped = stripComments(reflowModalRaw);
    check('the scanner does NOT flag manage/editor/v2/reflow-modal.js AS-IS (its real buttons all carry '
        + 'visible text)', findUnnamedIconButtons(reflowStripped).length === 0);

    const injected = reflowStripped
        + "\nconst _g1ProofFixture = '<button type=\"button\"><i class=\"bi bi-x-lg\" aria-hidden=\"true\"></i></button>';\n";
    check('the scanner GOES RED once an unnamed icon-only button is present in a REAL manage-tree file\'s '
        + 'content (proves SCAN_ROOTS genuinely reaches manage/**, not just js/**)',
        findUnnamedIconButtons(injected).length === 1);
}

/* ------------------------------------------------------------------------
 * Assertion 2 — the real scan across js/** AND manage/** (tree-derived,
 * rule #34; widened by G1 — see SCOPE above).
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 2 — real scan across appWeb/public_html/js/** and manage/**:');

const allFiles = SCAN_ROOTS.flatMap((root) => walkJs(root));

check(`scanned a plausible number of files (parser sanity, ${allFiles.length} walked)`,
    allFiles.length >= 90, `only ${allFiles.length} .js files walked under js/** + manage/** — the tree walk under-read`);

let totalUnnamed = 0;
for (const file of allFiles) {
    const rel = path.relative(PUB, file);
    const src = stripComments(fs.readFileSync(file, 'utf8'));
    const badLines = findUnnamedIconButtons(src);
    for (const line of badLines) {
        totalUnnamed++;
        failed++;
        const msg = `${rel}:${line} — icon-only <button> (fa-*/bi-* glyph, no visible/interpolated text) with `
            + 'no aria-label/aria-labelledby/title and no .visually-hidden span. A screen reader announces '
            + 'nothing for this control (WCAG 4.1.2). Add aria-label="…" (or title="…") on the <button>.';
        failures.push(msg);
        console.log(`  FAIL  ${msg}`);
    }
}
if (totalUnnamed === 0) {
    passed++;
    console.log(`  PASS  every icon-only <button> under js/** and manage/** (${allFiles.length} file(s)) carries an accessible name`);
}

/* ------------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll icon-only <button> naming checks passed for appWeb/public_html/js/** and manage/**.');
