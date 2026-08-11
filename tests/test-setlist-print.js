/**
 * tests/test-setlist-print.js — Set-list Print uses the shared print-template
 * engine (#1789)
 *
 * PURPOSE
 * ELI5: makes sure the "Print set list" button draws every song using the
 * SAME print-layout machine as the single-song Print button, instead of
 * secretly grabbing the whole web page for each song and stuffing it into the
 * print window (which used to drag in buttons, forms and other things that
 * should never end up on paper).
 *
 * DETAIL
 * Before #1789, `SetList.printSetList()` in
 * appWeb/public_html/js/modules/setlist.js fetched the full `?page=song` HTML
 * fragment per song and injected it raw into a hand-built print document —
 * bypassing the #1767 print-template engine entirely (js/modules/print.js),
 * so the set-list Print offered no template choice and looked inconsistent
 * with the single-song Print. The fix (rule #22 — CONSUME the shared engine,
 * never re-fork it) makes `printSetList()`:
 *   1. offer the same template picker (`pickPrintTemplate()`) the single-song
 *      Print uses,
 *   2. fetch each song's STRUCTURED data via the shared `fetchSong()` (not
 *      the screen-chromed page fragment), and
 *   3. render each song's body via the shared `renderTemplateBodyHtml()`.
 *
 * This guard asserts:
 *   1. setlist.js imports `renderTemplateBodyHtml`, `fetchSong`, AND
 *      `pickPrintTemplate` from './print.js'.
 *   2. The `printSetList` method body no longer contains `page=song` — the
 *      whole point of the fix (no more raw page-fragment fetch/injection).
 *   3. print.js `export`s `pickPrintTemplate`, `loadTemplates`, `fetchSong`,
 *      and `printCss` (the reusable pieces the callers above need).
 *
 * Tree-derived (reads the real source, not a hand-typed expectation of what
 * it says) and mutation-proven — see the commit history / handoff for the
 * red-then-green mutation run performed against this file.
 *
 *   node tests/test-setlist-print.js
 *
 * Exit 0 = wired correctly, 1 = drift.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const MODULES = path.join(REPO, 'appWeb', 'public_html', 'js', 'modules');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const read = (p) => fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';

/* Blank `/* … *\/` comment bodies before the banned-fingerprint scan — LOAD-
   BEARING (rule #34 / the test-qr-cuercode.js precedent): the fix itself
   leaves an explanatory comment that legitimately MENTIONS the old
   `?page=song` fetch ("not the screen-chromed page fragment the old code
   injected raw…") to explain what changed. A raw scan would flag that
   correct, explanatory comment as if the bug were still present. We ban
   USAGE (real code), not the word. Newlines are preserved so nothing shifts. */
function stripComments(src) {
    return src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
}

const setlistPath = path.join(MODULES, 'setlist.js');
const printPath = path.join(MODULES, 'print.js');
const setlistJs = read(setlistPath);
const printJs = read(printPath);

console.log('Set-list Print → shared print-template engine (#1789):');

check('setlist.js exists', setlistJs !== '', setlistPath);
check('print.js exists', printJs !== '', printPath);

/* 1. setlist.js imports the reusable engine pieces from ./print.js. Regex the
      import line itself (not just "the name appears somewhere"), so a stray
      mention in a comment can't false-pass this. */
const printImportLine = (setlistJs.match(/^import\s*\{[^}]*\}\s*from\s*['"]\.\/print\.js['"];?\s*$/m) || [''])[0];
check('setlist.js has an import line from ./print.js', printImportLine !== '',
    'expected a line like: import { … } from \'./print.js\';');
['renderTemplateBodyHtml', 'fetchSong', 'pickPrintTemplate'].forEach((name) => {
    const re = new RegExp('\\b' + name + '\\b');
    check(`setlist.js imports ${name} from ./print.js`, re.test(printImportLine),
        `import line was: ${printImportLine || '(none found)'}`);
});

/* 2. Extract the printSetList() method body by string-slicing from its
      declaration to the next class-method declaration (a line starting, after
      indentation, with an identifier + '(' at the same 4-space class-member
      indent — the same shape every other method in this class uses) — and
      assert it no longer fetches the `?page=song` fragment. This is the crux
      of the fix: the old code built `...?page=song&id=...` and fetched the
      full page HTML; the new code must not. */
const startMarker = 'async printSetList(';
const startIdx = setlistJs.indexOf(startMarker);
check('setlist.js defines async printSetList(', startIdx !== -1);

let methodBody = '';
if (startIdx !== -1) {
    /* Find the next method/property declaration after this one, at the same
       4-space class-member indentation, to bound the slice. Falls back to the
       end of the file if printSetList is the last member (it is, today). */
    const afterStart = setlistJs.slice(startIdx + startMarker.length);
    const nextMemberRe = /\n {4}(?:async\s+)?[A-Za-z_$][\w$]*\s*\(/;
    const nextMatch = nextMemberRe.exec(afterStart);
    const endIdx = nextMatch ? startIdx + startMarker.length + nextMatch.index : setlistJs.length;
    methodBody = setlistJs.slice(startIdx, endIdx);
}
check('printSetList() body was extracted (non-empty)', methodBody.length > 200,
    `extracted ${methodBody.length} chars`);
const methodBodyCode = stripComments(methodBody);
check('printSetList() no longer fetches the ?page=song fragment', !methodBodyCode.includes('page=song'),
    'found "page=song" in printSetList() CODE (outside comments) — the raw-fragment fetch this fix removes');
check('printSetList() calls the shared pickPrintTemplate()', /\bpickPrintTemplate\s*\(/.test(methodBody));
check('printSetList() calls the shared fetchSong()', /\bfetchSong\s*\(/.test(methodBody));
check('printSetList() calls the shared renderTemplateBodyHtml()', /\brenderTemplateBodyHtml\s*\(/.test(methodBody));

/* 3. print.js exports the reusable pieces (regex each individual `export`
      declaration, not just presence of the identifier anywhere). */
[
    /* #1767 remainder P4 — pickPrintTemplate() became `async` (it awaits the
       memoised ?ping=1 check before deciding whether to offer Download PDF),
       matching the SAME `export\s+async\s+function` shape loadTemplates()/
       fetchSong() already use below — `async` is now OPTIONAL here rather
       than disallowed, so this stays green on the (still entirely valid)
       non-async form too if a future refactor drops the await. */
    ['pickPrintTemplate', /export\s+(?:async\s+)?function\s+pickPrintTemplate\s*\(/],
    ['loadTemplates', /export\s+async\s+function\s+loadTemplates\s*\(/],
    ['fetchSong', /export\s+async\s+function\s+fetchSong\s*\(/],
    ['printCss', /export\s+function\s+printCss\s*\(/],
].forEach(([name, re]) => {
    check(`print.js exports ${name}`, re.test(printJs));
});

if (failures) {
    console.error(`\nFAIL: ${failures} set-list-print wiring check(s) failed.`);
    process.exit(1);
}
console.log('\nOK: set-list Print consumes the shared #1767 print-template engine.');
