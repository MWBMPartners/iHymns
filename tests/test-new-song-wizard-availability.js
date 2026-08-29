/**
 * iHymns — New-Song Wizard availability truth-table guard (#1997)
 *
 * ELI5
 * ----
 * The guided "New song" wizard (manage/editor/v2/new-song-wizard.js) has to
 * answer one small yes/no-ish question a lot: "is this song number free in
 * this songbook?" This file makes sure the REAL function that answers that
 * question keeps answering it correctly, for every one of its five possible
 * answers.
 *
 * WHY THIS RUNS UNDER NODE, NOT tests/php/test-new-song-wizard.php
 * ------------------------------------------------------------------
 * `numberAvailability()` is exported for exactly this reason (CLAUDE.md rule
 * #34 — "functional not text"), but new-song-wizard.js's TOP-LEVEL imports
 * (`/js/modules/admin-wizard.js`, a root-absolute browser URL) don't resolve
 * under a plain Node `import` — there is no DOM/browser resolver in this
 * runner. Rather than fake a DOM or stub two unrelated modules just to reach
 * one pure, side-effect-free function, this file extracts the REAL
 * `numberAvailability` function source from the tracked file (brace-depth
 * matched, the same technique tests/php/test-songbook-wizard.php's
 * `functionBodyFor()` uses on PHP) and compiles + runs it via `new
 * Function(...)`. That is still a FUNCTIONAL test — it executes the actual,
 * current, unmodified logic against real inputs and checks real outputs —
 * not a text/regex match against the source, and not a hand-copied
 * reimplementation that could quietly drift from the real thing.
 * `tests/php/test-new-song-wizard.php`'s own guard (g) `exec()`s this file
 * and requires it to exit 0, so a `php tools/run-php-tests.php` run also
 * covers this — and `tools/run-node-tests.js` globs every `tests/*.js` file
 * directly, so this needs no separate wiring there either.
 *
 * WHAT "FIVE OUTCOMES" MEANS (see the function's own doc-block for the full
 * reasoning) — 'blank' (no usable number typed — legal, Number is optional),
 * 'free' (a gap in the existing range), 'beyond-max' (available, but above
 * the book's current highest — also legal, but worth telling apart from a
 * gap for the UI's wording), 'hidden-held' (occupied ONLY by a soft-deleted
 * song — a warning, not a block), 'taken' (occupied by a live song — the
 * ONE outcome that blocks the wizard's Next).
 *
 * Usage:
 *   node tests/test-new-song-wizard-availability.js
 *
 * Exit status 0 = all pass, 1 = at least one assertion (or a mutation-proof
 * fixture self-test) failed.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(__dirname, '..');
const WIZARD_FILE = path.join(REPO, 'appWeb/public_html/manage/editor/v2/new-song-wizard.js');

let passed = 0;
let failed = 0;
function ok(label, cond) {
    if (cond) { passed++; } else { failed++; console.error('  ❌ ' + label); }
}

/**
 * Extract `export function <name>(...) { ... }`'s full source (including the
 * `export function …(` header, so the caller can strip just the `export `
 * keyword before compiling it standalone) by brace-depth matching from the
 * function's own opening brace — mirrors
 * tests/php/test-songbook-wizard.php's `functionBodyFor()` technique,
 * ported to a plain string/brace walk since this is JS being read by JS,
 * not tokenised PHP.
 *
 * @returns {string|null} the function's full source, or null if not found.
 */
function extractExportedFunction(src, name) {
    const headerRe = new RegExp('export\\s+function\\s+' + name + '\\s*\\(');
    const m = headerRe.exec(src);
    if (!m) { return null; }
    const start = m.index;
    const braceStart = src.indexOf('{', m.index + m[0].length - 1);
    if (braceStart === -1) { return null; }
    let depth = 0;
    for (let i = braceStart; i < src.length; i++) {
        const ch = src[i];
        if (ch === '{') { depth++; }
        else if (ch === '}') {
            depth--;
            if (depth === 0) { return src.slice(start, i + 1); }
        }
    }
    return null;   // unterminated — malformed source
}

/* =========================================================================
 * PART 1 — fixture self-test for extractExportedFunction() (rule #34: prove
 * the extraction primitive can both find a real marker and correctly refuse
 * an absent one, on a small synthetic snippet, before trusting it against
 * the real file).
 * ========================================================================= */
const fixtureSrc = [
    'function before() { return "nope"; }',
    'export function target(a, b) {',
    '    if (a) { return "inner " + b; }',
    '    return "outer";',
    '}',
    'function after() { return "nope2"; }',
].join('\n');
const fixtureExtracted = extractExportedFunction(fixtureSrc, 'target');
ok('extractExportedFunction() finds a real exported function and captures its body',
    fixtureExtracted !== null && fixtureExtracted.includes('inner') && fixtureExtracted.includes('outer'));
ok('MUTATION PROOF: extractExportedFunction() does not leak the PRECEDING function',
    fixtureExtracted !== null && !fixtureExtracted.includes('nope"'));
ok('MUTATION PROOF: extractExportedFunction() does not leak the FOLLOWING function',
    fixtureExtracted !== null && !fixtureExtracted.includes('nope2'));
ok('MUTATION PROOF: extractExportedFunction() returns null for an absent function name',
    extractExportedFunction(fixtureSrc, 'missingFn') === null);

/* Prove the compile-and-run step itself works on the fixture before trusting
   it against the real file — a `new Function` typo here would silently make
   every real assertion below throw the SAME way, masking a real regression
   behind a harness bug. */
// eslint-disable-next-line no-new-func
const fixtureFn = new Function(fixtureExtracted.replace(/^export\s+/, '') + '\nreturn target;')();
ok('the extracted fixture function actually RUNS and returns the real branch',
    fixtureFn(true, 'x') === 'inner x' && fixtureFn(false, 'x') === 'outer');

/* =========================================================================
 * PART 2 — load + compile the REAL numberAvailability() from the tracked
 * source file.
 * ========================================================================= */
ok('source file exists: ' + WIZARD_FILE, fs.existsSync(WIZARD_FILE));
const wizardSrc = fs.readFileSync(WIZARD_FILE, 'utf8');

const realExtracted = extractExportedFunction(wizardSrc, 'numberAvailability');
ok('new-song-wizard.js exports a numberAvailability(...) function', realExtracted !== null);

if (realExtracted === null) {
    console.log('\n' + passed + ' passed, ' + failed + ' failed.');
    console.log('Could not find numberAvailability() — every remaining check was skipped.');
    process.exit(1);
}

// eslint-disable-next-line no-new-func
const numberAvailability = new Function(realExtracted.replace(/^export\s+/, '') + '\nreturn numberAvailability;')();

/* =========================================================================
 * PART 3 — the functional truth table.
 * ========================================================================= */
console.log('\nNew-Song Wizard numberAvailability() truth table (#1997)\n');

const DATA = { missing: [3, 5], maxNumber: 10, hiddenHeld: [7] };

/* ---- blank — no usable number was typed; Number is OPTIONAL (rule #44) ---- */
ok("numberAvailability('', DATA) === 'blank' (empty string)", numberAvailability('', DATA) === 'blank');
ok('numberAvailability(null, DATA) === \'blank\'', numberAvailability(null, DATA) === 'blank');
ok('numberAvailability(undefined, DATA) === \'blank\'', numberAvailability(undefined, DATA) === 'blank');
ok("numberAvailability('   ', DATA) === 'blank' (whitespace only)", numberAvailability('   ', DATA) === 'blank');
ok("numberAvailability('abc', DATA) === 'blank' (non-numeric)", numberAvailability('abc', DATA) === 'blank');
ok('numberAvailability(0, DATA) === \'blank\' (not a positive integer)', numberAvailability(0, DATA) === 'blank');
ok('numberAvailability(-5, DATA) === \'blank\' (negative)', numberAvailability(-5, DATA) === 'blank');
ok('numberAvailability(3.5, DATA) === \'blank\' (non-integer)', numberAvailability(3.5, DATA) === 'blank');

/* ---- free — a listed gap inside the existing range ---- */
ok("numberAvailability(3, DATA) === 'free' (a listed gap)", numberAvailability(3, DATA) === 'free');
ok("numberAvailability('5', DATA) === 'free' (gap, as a STRING — DOM inputs are strings)", numberAvailability('5', DATA) === 'free');

/* ---- taken — occupied, not a gap, not beyond max, not hidden-held ---- */
ok("numberAvailability(2, DATA) === 'taken' (occupied, live)", numberAvailability(2, DATA) === 'taken');
ok("numberAvailability(10, DATA) === 'taken' (the max itself, occupied)", numberAvailability(10, DATA) === 'taken');

/* ---- beyond-max — free, but above the book's current ceiling ---- */
ok("numberAvailability(11, DATA) === 'beyond-max' (one past max)", numberAvailability(11, DATA) === 'beyond-max');
ok("numberAvailability(500, DATA) === 'beyond-max' (well past max)", numberAvailability(500, DATA) === 'beyond-max');
ok("numberAvailability(1, {missing: [], maxNumber: 0, hiddenHeld: []}) === 'beyond-max' (empty songbook — everything is beyond a max of 0)",
    numberAvailability(1, { missing: [], maxNumber: 0, hiddenHeld: [] }) === 'beyond-max');

/* ---- hidden-held — occupied ONLY by a soft-deleted song ---- */
ok("numberAvailability(7, DATA) === 'hidden-held'", numberAvailability(7, DATA) === 'hidden-held');
/* Precedence proof: 7 is <= maxNumber(10) and not in missing[], so WITHOUT
   the hiddenHeld check running first it would misreport as 'taken' — this
   is the exact ordering bug the function's own doc-block calls out. */
ok('MUTATION-SENSITIVE: a hidden-held number is never reported as plain \'taken\'',
    numberAvailability(7, DATA) !== 'taken');

/* ---- missing/undefined data — fails OPEN (never silently reports 'taken') ---- */
ok('numberAvailability(5, undefined) never returns \'taken\' when there is no data to check against',
    numberAvailability(5, undefined) !== 'taken');
ok('numberAvailability(5, {}) never returns \'taken\' against an empty data object',
    numberAvailability(5, {}) !== 'taken');

/* =========================================================================
 * PART 4 — MUTATION PROOFS against the real extracted source: confirm a
 * deliberately broken copy of the REAL function goes red on a case the
 * unbroken version passes (rule #34 — a check that was never seen to fail
 * is not trusted here).
 * ========================================================================= */

/* Break precedence: DELETE the hidden-held check (a mutated copy of the raw
   source text only — the tracked file is never touched), then recompile
   THAT and confirm case 7 (occupied only by a hidden song: <= maxNumber,
   not in missing[]) falls through to the 'taken' default instead of
   'hidden-held'. This is precisely the misreport the function's own
   doc-block warns the ordering exists to prevent. */
const mutatedSrc = realExtracted.replace(
    "if (hiddenHeld.indexOf(num) !== -1) { return 'hidden-held'; }\n    ",
    ''
);
ok('fixture precondition: the hidden-held-removal mutation actually changed the source',
    mutatedSrc !== realExtracted);
// eslint-disable-next-line no-new-func
const mutatedNumberAvailability = new Function(mutatedSrc.replace(/^export\s+/, '') + '\nreturn numberAvailability;')();
ok('MUTATION PROOF: removing the hidden-held precedence check misreports case 7 as \'taken\' (proves the truth table above is actually exercising this ordering)',
    mutatedNumberAvailability(7, DATA) === 'taken');

/* Break the blank guard: mutate `num < 1` to `num < 0` and confirm 0
   (which must be 'blank') flips to something else. */
const mutatedSrc2 = realExtracted.replace('num < 1', 'num < 0');
ok('fixture precondition: the blank-guard mutation actually changed the source', mutatedSrc2 !== realExtracted);
// eslint-disable-next-line no-new-func
const mutatedNumberAvailability2 = new Function(mutatedSrc2.replace(/^export\s+/, '') + '\nreturn numberAvailability;')();
ok('MUTATION PROOF: loosening the positive-integer guard makes numberAvailability(0, DATA) stop being \'blank\'',
    mutatedNumberAvailability2(0, DATA) !== 'blank');

console.log('\n' + passed + ' passed, ' + failed + ' failed.');
if (failed > 0) {
    process.exit(1);
}
console.log('numberAvailability() answers all five outcomes correctly, fails open on missing data,\n'
    + 'and the hidden-held/beyond-max/taken precedence is proven load-bearing by mutation.');
process.exit(0);
