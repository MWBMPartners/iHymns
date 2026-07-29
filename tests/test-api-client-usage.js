/**
 * iHymns — Shared API-Client Usage Guard (#1624)
 *
 * ELI5: this test walks every JS module under `js/modules/**` and
 * `js/utils/**` and makes sure nobody calls the bare browser `fetch()`
 * any more — everybody goes through `apiFetch()` in
 * `js/utils/api-client.js`, the one place that knows how to attach the
 * language-filter header, the same-origin CSRF marker, and the
 * offline/maintenance signals (see that file's own doc-comment for the
 * full design, and #1031 for the incident — a monkey-patched
 * `window.fetch` — this module replaces).
 *
 * #1624 is the tail of #1031: that work migrated only the
 * language-sensitive call sites, leaving ~80 bare `fetch()` calls that
 * still worked fine but missed what the shared client provides. This
 * guard is what stops the next ~80 from accumulating.
 *
 * Detail: two independent assertions, both provably capable of failing
 * (a test that can't fail is worthless — see the #1581 precedent in
 * test-event-names.js, which this file's structure mirrors):
 *
 *   1. LITERAL BAN — scans every `appWeb/public_html/js/modules/**\/*.js`
 *      and `appWeb/public_html/js/utils/**\/*.js` file for a call to the
 *      bare `fetch(` identifier — i.e. NOT `apiFetch(`, NOT
 *      `window.fetch(` on some OTHER object, and NOT a mention of
 *      `fetch(` inside a `/* *\/` or `//` comment or a quoted string.
 *      Comments and string/template literals are stripped with a small
 *      character-level scanner (see `stripCommentsAndStrings()`) before
 *      the regex runs, rather than a single regex trying to do both
 *      jobs at once — a doc-comment explaining "this used to call
 *      fetch()" must never itself trip the ban.
 *
 *   2. COUNT-EXACT SELF-CLEANING ALLOWLIST — a handful of files are
 *      DELIBERATE, documented exceptions (see ALLOWLIST below) and are
 *      allowed EXACTLY their current number of raw `fetch(` calls, no
 *      more, no fewer. Both directions matter: MORE than the allow-
 *      listed count is a new violation; FEWER means the exception has
 *      been fixed (or the file rewritten) and the stale entry must be
 *      deleted rather than quietly outliving the reason it was added —
 *      same discipline as `tests/php/test-fragment-inline-scripts.php`
 *      and `LITERAL_ALLOWLIST` in `tests/test-event-names.js`.
 *
 *      Two entries (api-client.js, place-search.js) were anticipated by
 *      #1624's own spec. Three more (analytics.js, error-monitor.js,
 *      offline-indicator.js) were found DURING the #1624 migration itself
 *      — each has a real, non-hypothetical behaviour change that
 *      `apiFetch()` would introduce, not mere inertia:
 *        - error-monitor.js's `_sendReport()` fallback depends on `fetch()`
 *          being capable of throwing SYNCHRONOUSLY when the transport is
 *          broken (`tests/test-error-monitor.js`'s L10 group proves this).
 *          `apiFetch()` is an `async function` — ANY throw inside it,
 *          synchronous or not, becomes a REJECTED PROMISE, never a
 *          synchronous throw to the caller. Migrating this call would
 *          silently break the "don't mark a fingerprint sent when nothing
 *          was actually attempted" guarantee the L10 test exists to pin.
 *        - offline-indicator.js's `_probeConnectivity()` deliberately
 *          IGNORES an `AbortError` from its own 2.5s timeout (a slow
 *          network isn't necessarily an offline one). `apiFetch()`
 *          dispatches `EVT_FETCH_FAILED` — which THIS MODULE ITSELF
 *          listens for — on every network-level failure, abort included,
 *          so migrating this one call would make a merely-slow probe
 *          incorrectly flip the banner to "offline".
 *        - analytics.js's `_sendToEndpoint()` fallback POSTs to an
 *          ADMIN-CONFIGURED, possibly CROSS-ORIGIN "custom analytics
 *          endpoint". An ad-blocker or CORS rejection there throws a
 *          `TypeError` exactly like a real outage — `apiFetch()` would
 *          turn that into `EVT_FETCH_FAILED` and show every visitor an
 *          incorrect "you're offline" banner because a third-party
 *          analytics collector was unreachable, not the site itself.
 *      All three are genuinely same-species with api-client.js /
 *      place-search.js: "migrating this exact call site changes real
 *      behaviour", not "nobody got round to it yet".
 *
 * USAGE:
 *   node tests/test-api-client-usage.js
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 *
 * @see appWeb/public_html/js/utils/api-client.js  the shared client this guards
 * @see tests/test-event-names.js                  the guard-structure precedent
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const JS_ROOT    = path.resolve(__dirname, '..', 'appWeb', 'public_html', 'js');
const SCAN_DIRS  = [
    path.join(JS_ROOT, 'modules'),
    path.join(JS_ROOT, 'utils'),
];

/**
 * Recursively collect every *.js file under `dir`. Plain manual walk
 * (rather than fs.readdirSync's `recursive` option) so this keeps
 * working regardless of exact Node minor version — matches the style
 * of the existing tests/test-*.js scripts.
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

const allFiles = SCAN_DIRS.flatMap(collectJsFiles);

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
 * Comment / string stripper
 *
 * A small character-level scanner rather than a single "clever" regex —
 * regex-only comment stripping is notoriously wrong on edge cases (a
 * `//` inside a `https://` string literal being the classic trap this
 * codebase's own URLs would hit). Walks the source once, tracking
 * whether it is inside a block comment, a line comment, or a single/
 * double/template-quoted string, and drops everything except real code
 * — newlines are preserved so line numbers in any future diagnostic
 * stay meaningful.
 *
 * Known, accepted limitation: content inside a template literal's
 * `${...}` interpolation is treated as opaque string content, same as
 * the rest of the template. Nothing in this codebase embeds a literal
 * `fetch(` call inside a template interpolation, so this cannot hide a
 * real violation in practice — see the file header's design note.
 * ==================================================================== */
function stripCommentsAndStrings(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    /** @type {'code'|'block'|'line'|'sq'|'dq'|'tpl'} */
    let state = 'code';

    while (i < n) {
        const c  = src[i];
        const c2 = src[i + 1];

        if (state === 'code') {
            if (c === '/' && c2 === '*') { state = 'block'; i += 2; continue; }
            if (c === '/' && c2 === '/') { state = 'line';  i += 2; continue; }
            if (c === "'")  { state = 'sq';  out += ' '; i += 1; continue; }
            if (c === '"')  { state = 'dq';  out += ' '; i += 1; continue; }
            if (c === '`')  { state = 'tpl'; out += ' '; i += 1; continue; }
            out += c;
            i += 1;
            continue;
        }

        if (state === 'block') {
            if (c === '*' && c2 === '/') { state = 'code'; out += '  '; i += 2; continue; }
            out += (c === '\n') ? '\n' : ' ';
            i += 1;
            continue;
        }

        if (state === 'line') {
            if (c === '\n') { state = 'code'; out += '\n'; i += 1; continue; }
            out += ' ';
            i += 1;
            continue;
        }

        /* sq / dq / tpl — quoted content. Escaped chars (\x) are
           consumed as a pair so an escaped quote can never look like
           the closing delimiter. */
        const closers = { sq: "'", dq: '"', tpl: '`' };
        const closer = closers[state];
        if (c === '\\') {
            out += '  ';
            i += 2; /* skip the escaped character too */
            continue;
        }
        if (c === closer) { state = 'code'; out += ' '; i += 1; continue; }
        out += (c === '\n') ? '\n' : ' ';
        i += 1;
    }

    return out;
}

/* ======================================================================
 * Assertion 1 — raw fetch( ban (comment/string-stripped source)
 * ==================================================================== */
console.log('Assertion 1 — no raw fetch( call in js/modules/** or js/utils/** (outside the allowlist):');

/* A bare `fetch(` — NOT preceded by an identifier char or `.`, so this
   never matches `apiFetch(`, `window.fetch(`, `this.fetch(`, etc. */
const RAW_FETCH_RE = /(?<![A-Za-z0-9_$.])fetch\(/g;

/* Count-exact, self-cleaning allowlist (see file header). Paths are
   relative to JS_ROOT. */
const ALLOWLIST = [
    {
        file: 'utils/api-client.js',
        count: 1,
        why: 'the ONE place allowed to call the real fetch() — apiFetch()\'s own implementation.',
    },
    {
        file: 'modules/place-search.js',
        count: 2,
        why: 'loaded as a classic <script src> on 7 admin pages, zero import/export — '
           + 'a static import breaks it outright and a dynamic import() broke '
           + 'tests/test-place-search-keyboard.js under jsdom (#1624). Never needed the '
           + 'language header either.',
    },
    {
        file: 'modules/error-monitor.js',
        count: 1,
        why: '_sendReport()\'s fetch fallback relies on a SYNCHRONOUS throw to detect a '
           + 'broken transport (tests/test-error-monitor.js L10) — apiFetch() is an async '
           + 'function, so any throw inside it becomes a rejected promise, never a '
           + 'synchronous one, which would silently break that guarantee.',
    },
    {
        file: 'modules/offline-indicator.js',
        count: 1,
        why: '_probeConnectivity() deliberately ignores its own AbortError (slow network '
           + '≠ offline); apiFetch() dispatches EVT_FETCH_FAILED on ANY network-level '
           + 'failure including abort, which this module itself listens for — migrating '
           + 'would make a merely-slow probe show a false "offline" banner.',
    },
    {
        file: 'modules/analytics.js',
        count: 1,
        why: '_sendToEndpoint()\'s fetch fallback posts to an admin-configured, possibly '
           + 'cross-origin custom analytics endpoint; an ad-blocker/CORS failure there '
           + 'would surface as apiFetch()\'s EVT_FETCH_FAILED and show a false "offline" '
           + 'banner for a third-party outage that has nothing to do with site reachability.',
    },
];

const violationsByFile = new Map();
for (const file of allFiles) {
    const relPath = path.relative(JS_ROOT, file).split(path.sep).join('/');
    const src = fs.readFileSync(file, 'utf8');
    const stripped = stripCommentsAndStrings(src);
    const count = (stripped.match(RAW_FETCH_RE) || []).length;
    if (count > 0) violationsByFile.set(relPath, count);
}

const unexpected = [];
for (const [relPath, count] of violationsByFile) {
    const entry = ALLOWLIST.find(a => a.file === relPath);
    if (!entry) {
        unexpected.push(`${relPath}: ${count} raw fetch( call(s), not allow-listed`);
    } else if (count !== entry.count) {
        unexpected.push(`${relPath}: expected exactly ${entry.count} allow-listed raw fetch( call(s), found ${count}`);
    }
}
/* A file present in ALLOWLIST but with ZERO violations found is also a
   mismatch — folded into the same self-cleaning check below rather than
   duplicated here. */

check(
    'no un-allow-listed raw fetch( call, and every allow-listed file matches its exact count',
    unexpected.length === 0,
    unexpected.length ? unexpected.join('\n        ') : ''
);

/* Self-cleaning: an allowlist entry whose file no longer has ANY raw
   fetch( is stale — the exception it was excusing has been migrated (or
   the file rewritten) — and must be deleted from ALLOWLIST above.
   Same discipline as tests/php/test-fragment-inline-scripts.php and
   test-event-names.js's LITERAL_ALLOWLIST. */
for (const entry of ALLOWLIST) {
    const found = violationsByFile.get(entry.file) || 0;
    check(
        `allowlist entry stays live: ${entry.file} (expects ${entry.count})`,
        found > 0,
        found === 0 ? `zero raw fetch( calls found — remove this stale allowlist entry (${entry.why})` : ''
    );
}

/* ======================================================================
 * Assertion 2 — regex sanity: proves the guard is genuinely capable of
 * catching a real violation and of NOT catching the things it must
 * ignore (comments, prose, apiFetch(), window.fetch on an unrelated
 * object). A guard that can't fail is worthless (#1581 precedent).
 * ==================================================================== */
console.log('');
console.log('Assertion 2 — regex/stripper self-test (proves the guard can both fire and stay silent):');

function countRawFetch(src) {
    return (stripCommentsAndStrings(src).match(RAW_FETCH_RE) || []).length;
}

const selfTestCases = [
    { label: 'a real call is caught',                         src: 'const r = await fetch(url);',                         expect: 1 },
    { label: 'apiFetch( is never matched',                     src: 'const r = await apiFetch(url);',                     expect: 0 },
    { label: 'apiFetchJson( is never matched',                 src: 'const j = await apiFetchJson(url);',                 expect: 0 },
    { label: 'a /* */ comment mention is ignored',              src: '/* calls fetch(url) internally */',                  expect: 0 },
    { label: 'a JSDoc-style comment mention is ignored',        src: '/**\n * fetch() accepts string | URL | Request\n */', expect: 0 },
    { label: 'a // line-comment mention is ignored',            src: '// fallback to fetch(url) here',                      expect: 0 },
    { label: 'a fetch(-shaped string literal is ignored',       src: "const s = 'call fetch(url) manually';",              expect: 0 },
    { label: 'a URL containing // is not mistaken for a comment (real call after it is still caught)',
      src: "const u = 'https://example.test/x'; fetch(u);",                                                                expect: 1 },
    { label: 'window.fetch( is not matched (dot-qualified)',    src: 'return window.fetch(url);',                          expect: 0 },
    { label: 'multiple real calls are all counted',             src: 'fetch(a); fetch(b);',                                expect: 2 },
];

for (const t of selfTestCases) {
    check(`self-test: ${t.label}`, countRawFetch(t.src) === t.expect, `expected ${t.expect}, got ${countRawFetch(t.src)}`);
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
