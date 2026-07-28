/**
 * iHymns — Event-Name Unification Guard (#1581)
 *
 * ELI5: this test walks every JS file the browser loads and makes sure
 * nobody spells a custom event's name out by hand any more — everybody
 * has to `import` it from js/constants.js, the one place the name is
 * written down. It also checks that every name IN constants.js is
 * actually used by at least one dispatcher and one listener, so a typo'd
 * or orphaned constant gets caught immediately instead of silently
 * doing nothing (exactly how `iHymns:language-filter-changed` vs
 * `ihymns:language-filter-changed` broke Song of the Day for however
 * long nobody noticed — DOM event types are case-sensitive strings, so
 * two differently-cased spellings are two entirely different events
 * with no error, no warning, nothing).
 * https://developer.mozilla.org/docs/Web/API/EventTarget/addEventListener
 *
 * Detail: two independent assertions, both of which must be provably
 * capable of failing (a test that can't fail is worthless — this is a
 * hard project rule; see the #1581 fail-proof run recorded in the PR).
 *
 *   1. LITERAL BAN — scans every appWeb/public_html/js/**\/*.js file
 *      (except constants.js itself, which is where the literals are
 *      SUPPOSED to live) for anything shaped like a quoted
 *      'ihymns:something' / 'iHymns:something' string. Two narrow,
 *      explicitly documented exceptions are allow-listed below — see
 *      LITERAL_ALLOWLIST — everything else is a regression.
 *
 *   2. CROSS-REFERENCE — for every EVT_* export in constants.js, walks
 *      the same tree looking for it used as the literal first argument
 *      to addEventListener(...) (a listener) and to
 *      dispatchEvent(new CustomEvent(...)) / dispatchEvent(new Event(...))
 *      (a dispatcher). Every name needs >=1 of each, UNLESS it's in
 *      ONE_SIDED_ALLOWLIST — and that allowlist is itself asserted
 *      to still match reality (see the self-cleaning check below),
 *      mirroring the precedent in tests/php/test-fragment-inline-scripts.php.
 *
 * USAGE:
 *   node tests/test-event-names.js
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const JS_ROOT    = path.resolve(__dirname, '..', 'appWeb', 'public_html', 'js');

/* The one file that's ALLOWED to spell the raw 'ihymns:x' strings — it's
   where every other file gets its EVT_* constant from. */
const CONSTANTS_FILE = path.join(JS_ROOT, 'constants.js');

/**
 * Recursively collect every *.js file under `dir`. Plain manual walk
 * (rather than fs.readdirSync's `recursive` option) so this keeps
 * working regardless of exact Node minor version — matches the style
 * of the existing tests/test-*.js scripts, which avoid relying on
 * very-new API surface.
 */
function collectJsFiles(dir) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            out.push(...collectJsFiles(full));
        } else if (entry.isFile() && entry.name.endsWith('.js')) {
            out.push(full);
        }
    }
    return out;
}

const allFiles = collectJsFiles(JS_ROOT);

let passed = 0;
let failed = 0;
const failures = [];

function check(name, ok, detail) {
    if (ok) {
        passed++;
        console.log(`  PASS  ${name}`);
    } else {
        failed++;
        failures.push({ name, detail });
        console.log(`  FAIL  ${name}`);
        if (detail) console.log(`        ${detail}`);
    }
}

/* ======================================================================
 * Assertion 1 — literal ban
 * ==================================================================== */
console.log('Assertion 1 — no raw ihymns:*/iHymns:* string literals outside constants.js:');

/* The regex from the #1581 spec: a quote char, 'i', h-or-H, 'ymns:',
   then an event-name tail, then a matching quote char. Deliberately
   case-flexible on the 'h' so it ALSO catches the historical capital-H
   typo this whole test exists to prevent from ever coming back.
   L7 (adversarial-review finding, OBS-VERIFY.md): the tail class was
   originally `[a-z][a-z-]*` — lowercase letters and hyphens only — which
   misses a camelCase or digit tail (e.g. 'ihymns:languageFilterChanged',
   'ihymns:refresh2'); every existing EVT_* name in constants.js happens
   to be all-lowercase-hyphenated today, so this was a live blind spot,
   not a hypothetical one — a future name in either shape would sail
   straight through this ban undetected. Widened to
   `[A-Za-z0-9][A-Za-z0-9-]*` (a leading alphanumeric, then any run of
   alphanumerics/hyphens) so a mixed-case or digit-bearing tail is caught
   the same way. https://developer.mozilla.org/docs/Web/JavaScript/Guide/Regular_expressions/Character_classes */
const LITERAL_RE = /(['"`])i[hH]ymns:([A-Za-z0-9][A-Za-z0-9-]*)\1/g;

/* Narrow, documented exceptions — NOT event names that got missed,
   but two genuinely different things a plain regex can't tell apart
   from an event-type string:
   - bulk-import-progress.js's STORAGE_KEY uses the same `ihymns:`
     colon-namespacing convention for a sessionStorage key, not a
     CustomEvent type. It never touches addEventListener/dispatchEvent.
   - song-media-editor.js's 'iHymns:song-loaded' pair is explicitly
     OUT OF SCOPE for #1581 (see the file's own doc-block): its
     dispatch side, manage/editor/editor.js, is a classic script with
     zero import/export and cannot import constants.js. Migrating only
     the listener would still leave two files free to disagree — worse
     than today, where at least the (undocumented) pair matches. */
const LITERAL_ALLOWLIST = [
    { file: 'modules/bulk-import-progress.js', literal: 'ihymns:bulk-import-active-job' },
    { file: 'modules/song-media-editor.js',     literal: 'iHymns:song-loaded' },
];
/* Hit counter per allowlist entry, used below for the self-cleaning check. */
const allowlistHitCount = LITERAL_ALLOWLIST.map(() => 0);

const literalViolations = [];
for (const file of allFiles) {
    if (file === CONSTANTS_FILE) continue; /* the one legitimate home for these literals */
    const relPath = path.relative(JS_ROOT, file).split(path.sep).join('/');
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    lines.forEach((line, idx) => {
        LITERAL_RE.lastIndex = 0;
        let m;
        while ((m = LITERAL_RE.exec(line)) !== null) {
            /* Strip the captured surrounding quote char so the matched
               text (with its ORIGINAL casing) compares byte-for-byte
               against the allowlist below. */
            const matchedText = m[0].slice(1, -1);
            const allowIdx = LITERAL_ALLOWLIST.findIndex(
                a => relPath.endsWith(a.file) && matchedText === a.literal
            );
            if (allowIdx !== -1) {
                allowlistHitCount[allowIdx]++;
                continue;
            }
            literalViolations.push(`${relPath}:${idx + 1}: ${JSON.stringify(matchedText)}`);
        }
    });
}

check(
    'no un-allow-listed raw ihymns:*/iHymns:* literal outside constants.js',
    literalViolations.length === 0,
    literalViolations.length ? `${literalViolations.length} hit(s):\n        ` + literalViolations.join('\n        ') : ''
);

/* Self-cleaning: an allowlist entry that no longer matches ANYTHING is
   stale (the literal it was excusing has been migrated/removed) and
   must be deleted from LITERAL_ALLOWLIST above — otherwise the
   allowlist would quietly grow to cover regressions that happen to
   reuse the same string in the same file. Same discipline as the
   count-exact allowlist in tests/php/test-fragment-inline-scripts.php. */
LITERAL_ALLOWLIST.forEach((entry, i) => {
    check(
        `literal allowlist entry stays live: ${entry.file} :: ${entry.literal}`,
        allowlistHitCount[i] > 0,
        allowlistHitCount[i] === 0 ? 'zero matches found — remove this stale allowlist entry' : ''
    );
});

/* ======================================================================
 * Assertion 2 — cross-reference every EVT_* export
 * ==================================================================== */
console.log('');
console.log('Assertion 2 — every EVT_* constant has >=1 dispatcher and >=1 listener:');

const constantsSrc = fs.readFileSync(CONSTANTS_FILE, 'utf8');
const evtNames = [...constantsSrc.matchAll(/export const (EVT_[A-Z_]+)\s*=/g)].map(m => m[1]);
check('constants.js exports at least one EVT_* name (sanity check on the scanner itself)', evtNames.length > 0);

/* One-sided-by-design names. Per #1581: a listener with no dispatcher
   yet is legitimate future work; a dispatcher with no listener never
   is (nobody would ever observe it, which is itself a bug smell), so
   this allowlist only ever means "listener exists, dispatcher pending". */
const ONE_SIDED_ALLOWLIST = new Set(['EVT_OFFLINE_SETTINGS_CHANGED']);

/* Concatenate every non-constants.js file into one haystack per name
   check — the dispatch/listen call and the EVT_* identifier always sit
   on the same source line in this codebase (see the #1581 migration),
   so a whitespace-only gap between the call and the identifier is
   sufficient; \s matches newlines too, so this remains correct even if
   a future edit wraps the call across lines. */
const haystack = allFiles
    .filter(f => f !== CONSTANTS_FILE)
    .map(f => fs.readFileSync(f, 'utf8'))
    .join('\n');

for (const name of evtNames) {
    const dispatchRe = new RegExp(`dispatchEvent\\(\\s*new\\s+(?:CustomEvent|Event)\\(\\s*${name}\\b`);
    const listenRe   = new RegExp(`addEventListener\\(\\s*${name}\\b`);
    const dispatchCount = (haystack.match(new RegExp(dispatchRe.source, 'g')) || []).length;
    const listenCount   = (haystack.match(new RegExp(listenRe.source, 'g')) || []).length;

    if (ONE_SIDED_ALLOWLIST.has(name)) {
        /* Allow-listed as listener-only. This must FAIL the moment
           reality changes shape, so the allowlist can never silently
           cover for a different, unrelated problem:
             - zero uses on BOTH sides -> the constant is dead, not one-sided
             - a dispatcher shows up -> no longer one-sided; remove from
               ONE_SIDED_ALLOWLIST and let the normal >=1/>=1 rule apply */
        check(
            `${name} — one-sided allowlist entry still shaped listener-only`,
            listenCount > 0 && dispatchCount === 0,
            `dispatchers=${dispatchCount} listeners=${listenCount} (expected 0 dispatchers, >=1 listener — ` +
            (dispatchCount > 0
                ? 'a dispatcher now exists; remove this name from ONE_SIDED_ALLOWLIST'
                : 'listener is missing; the constant looks dead')
            + ')'
        );
    } else {
        check(
            `${name} — has both a dispatcher and a listener`,
            dispatchCount >= 1 && listenCount >= 1,
            `dispatchers=${dispatchCount} listeners=${listenCount}`
        );
    }
}

/* ======================================================================
 * Summary
 * ==================================================================== */
console.log('');
console.log(`${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('');
    console.log('Failures:');
    for (const f of failures) {
        console.log(`  - ${f.name}`);
        if (f.detail) console.log(`    ${f.detail}`);
    }
}
process.exit(failed === 0 ? 0 : 1);
