/**
 * iHymns — Shared API-Client Usage Guard (#1624)
 *
 * ELI5: this test walks every JS file under `js/` and makes sure
 * nobody calls the bare browser `fetch()`
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
 *   1. LITERAL BAN — scans every `appWeb/public_html/js/**\/*.js` file
 *      (widened from a typed list of two subdirectories in #1700, which
 *      had never covered `js/app.js` or `js/constants.js`) for a call to
 *      the bare `fetch(` identifier — i.e. NOT `apiFetch(`, NOT
 *      `window.fetch(` on some OTHER object, and NOT a mention of
 *      `fetch(` inside a `/* *\/` or `//` comment or a quoted string.
 *      Comments and string/template literals are stripped with a small
 *      character-level scanner (see `stripCommentsAndStrings()`) before
 *      the regex runs, rather than a single regex trying to do both
 *      jobs at once — a doc-comment explaining "this used to call
 *      fetch()" must never itself trip the ban.
 *
 *      That scanner also understands REGEX LITERALS (#1701). It did not
 *      until then, and the consequence was severe: `s.replace(/"/g, …)`
 *      left the `/` in code, the `"` inside the pattern opened a string
 *      state that never closed, and EVERY `fetch(` in the rest of that
 *      file became invisible — reported as zero violations, not as an
 *      error. TEN scanned files were in that state, silently, for a year.
 *      Assertion 3 now fails the build if any file ends the walk outside
 *      the 'code' state, so a gap in the (necessarily heuristic) regex
 *      detection can produce a loud false failure but never another
 *      silent under-report.
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
/* ALL of js/, not a typed list of subdirectories (#1700).
 *
 * ELI5: look everywhere under js/, so a file that isn't in a folder we happened
 * to name still gets checked.
 *
 * This was `[JS_ROOT/modules, JS_ROOT/utils]`, which is one directory level
 * short of the tree it means to cover: `js/app.js` and `js/constants.js` sit at
 * the top level and were never scanned. `constants.js` did in fact contain a
 * bare `fetch(` for the whole life of the guard — allow-listed below now that it
 * is visible. Same derive-vs-hardcode lesson as rule #34: a check that NAMES
 * where to look misses whatever grows outside the names, and its green tick
 * reads as coverage of the whole tree.
 *
 * `collectJsFiles()` already recurses, so pointing it at the root covers
 * modules/ and utils/ exactly as before — no file previously scanned is dropped.
 */
const SCAN_DIRS  = [JS_ROOT];

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
/**
 * Could a `/` at this point begin a REGEX LITERAL rather than a division?
 *
 * ELI5: `/` means "divide" after a value and "start of a pattern" everywhere
 * else — this looks at what came just before to tell which.
 *
 * The standard JS lexer heuristic (#1701): a regex may begin only where an
 * OPERAND may begin. So if the previous significant character closes an operand
 * — an identifier or number character, or `)`, `]`, `}` — the `/` is division.
 * Anything else (`=`, `(`, `,`, `:`, `[`, `!`, `&`, `|`, `?`, `{`, `;`, `return`,
 * start of file) means a regex can start here.
 *
 * KNOWN GAP, stated rather than hidden: `}` is treated as closing an operand
 * (an object literal), which misreads `if (x) {} /re/.test(y)` — a block close
 * followed by a regex. Nothing in this codebase writes that, and the
 * derailment check below means the gap can only ever cause a LOUD failure, never
 * a silent under-report. That asymmetry is the entire point of pairing the two.
 *
 * @param {string} out Code emitted so far (comments/strings already removed).
 * @returns {boolean}
 */
function regexCanStartHere(out) {
    for (let k = out.length - 1; k >= 0; k--) {
        const ch = out[k];
        if (ch === ' ' || ch === '\n' || ch === '\t' || ch === '\r') { continue; }
        if (/[A-Za-z0-9_$)\]}]/.test(ch)) {
            /* `return`, `typeof`, `case` etc. end in identifier characters but are
               KEYWORDS, after which a regex certainly can start. Check the word. */
            const word = (out.slice(0, k + 1).match(/[A-Za-z_$][A-Za-z0-9_$]*$/) || [''])[0];
            return ['return', 'typeof', 'case', 'in', 'of', 'delete', 'void',
                    'instanceof', 'new', 'do', 'else', 'yield', 'await'].includes(word);
        }
        return true;   // an operator / punctuator — an operand may begin
    }
    return true;       // start of file
}

/**
 * @returns {{code: string, state: string}} `state` is the lexer state the walk
 *   ENDED in. Anything other than 'code' means the scan derailed and the
 *   stripped output cannot be trusted — see the derailment check at the call
 *   site. Returning it rather than swallowing it is the load-bearing half of
 *   the #1701 fix.
 */
function stripCommentsAndStrings(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    /** @type {'code'|'block'|'line'|'sq'|'dq'|'tpl'|'re'|'recls'} */
    let state = 'code';

    while (i < n) {
        const c  = src[i];
        const c2 = src[i + 1];

        if (state === 'code') {
            if (c === '/' && c2 === '*') { state = 'block'; i += 2; continue; }
            if (c === '/' && c2 === '/') { state = 'line';  i += 2; continue; }
            /* REGEX LITERAL (#1701). Before this existed, `s.replace(/"/g, 'x')`
               left the `/` in code, then the `"` inside the pattern opened a
               string state that never closed — and EVERY `fetch(` in the rest of
               the file became invisible, reported as zero violations rather than
               as an error. Ten scanned files derailed this way. */
            if (c === '/' && regexCanStartHere(out)) { state = 're'; out += ' '; i += 1; continue; }
            if (c === "'")  { state = 'sq';  out += ' '; i += 1; continue; }
            if (c === '"')  { state = 'dq';  out += ' '; i += 1; continue; }
            if (c === '`')  { state = 'tpl'; out += ' '; i += 1; continue; }
            out += c;
            i += 1;
            continue;
        }

        /* Inside a regex body. A `/` inside a CHARACTER CLASS does not close it
           (`/[/]/` is a valid regex matching a slash), so the class is tracked
           as its own state. */
        if (state === 're') {
            if (c === '\\') { out += '  '; i += 2; continue; }
            if (c === '[')  { state = 'recls'; out += ' '; i += 1; continue; }
            if (c === '/')  { state = 'code';  out += ' '; i += 1; continue; }
            if (c === '\n') { /* an unterminated regex — a newline cannot appear
                                 in one, so treat it as code again rather than
                                 running away to the end of the file. */
                state = 'code'; out += '\n'; i += 1; continue; }
            out += ' ';
            i += 1;
            continue;
        }

        if (state === 'recls') {
            if (c === '\\') { out += '  '; i += 2; continue; }
            if (c === ']')  { state = 're'; out += ' '; i += 1; continue; }
            if (c === '\n') { state = 'code'; out += '\n'; i += 1; continue; }
            out += ' ';
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

    return { code: out, state };
}

/* ======================================================================
 * Assertion 1 — raw fetch( ban (comment/string-stripped source)
 * ==================================================================== */
console.log('Assertion 1 — no raw fetch( call anywhere under js/ (outside the allowlist):');

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
        file: 'constants.js',
        count: 1,
        why: 'loadSongbookRegistry() must get EVERY songbook, and /api?action=songbooks '
           + 'language-FILTERS its response (makeLanguageFilterPredicate over '
           + 'resolvePreferredLanguagesForRequest). The registry is a NAME LOOKUP, not a '
           + 'browsable list: filtered, songbookFullName() returns null for any book '
           + 'outside the user\'s languages and every list view mentioning it silently '
           + 'falls back to the bare abbreviation. apiFetch() attaches '
           + 'X-Preferred-Languages, so migrating this call would cause exactly that '
           + '(#1700). Invisible until now — this file was outside the scan.',
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
/* Files whose scan DERAILED — see the check immediately below. */
const derailed = [];
for (const file of allFiles) {
    const relPath = path.relative(JS_ROOT, file).split(path.sep).join('/');
    const src = fs.readFileSync(file, 'utf8');
    const { code: stripped, state: endState } = stripCommentsAndStrings(src);
    if (endState !== 'code') { derailed.push(`${relPath}: ended in '${endState}'`); }
    const count = (stripped.match(RAW_FETCH_RE) || []).length;
    if (count > 0) violationsByFile.set(relPath, count);
}

/* -----------------------------------------------------------------------
 * DERAILMENT — the load-bearing half of the #1701 fix.
 *
 * ELI5: if we lost track of where we were in a file, say so loudly instead
 * of reporting "nothing wrong here".
 *
 * A well-formed JS file must leave the walk in the 'code' state. Ending
 * anywhere else means the scanner mistook something for the start of a string,
 * comment or regex and never found its end — from that point on the rest of the
 * file was blanked, and every `fetch(` in it silently became invisible.
 *
 * Before this check, that read as ZERO VIOLATIONS. Ten scanned files were in
 * exactly that state — a single regex literal containing a quote
 * (`s.replace(/"/g, …)` in modules/request.js) switched the whole guard off for
 * the remainder of each. The guard had reported green for a year.
 *
 * The regex-literal handling above is a heuristic and heuristics have gaps.
 * THIS check is what makes a gap survivable: it can produce a false FAILURE,
 * which someone must then look at, but it can never again let a scan that
 * covered half a file be read as coverage of all of it. Rule #34 — a scanner
 * that under-reports is worse than no scanner, because its tick is read as
 * coverage.
 * --------------------------------------------------------------------- */
check(
    'every scanned file lexed cleanly to the end (a derailed scan is not a clean one)',
    derailed.length === 0,
    derailed.length
        ? 'these files ended mid-string/comment/regex, so everything after that point was '
          + 'NOT scanned:\n        ' + derailed.join('\n        ')
        : ''
);

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
    return (stripCommentsAndStrings(src).code.match(RAW_FETCH_RE) || []).length;
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

    /* ---- REGEX LITERALS (#1701) -------------------------------------------
       The first two cases ARE the defect: before regex handling existed, the
       quote inside the pattern opened a string state that never closed, and the
       `fetch(` after it — along with the whole rest of the file — was silently
       invisible. Both are taken verbatim from the shape found live in
       modules/request.js:187. */
    { label: 'a regex containing a DOUBLE quote does not swallow the rest of the file',
      src: 'const t = s.replace(/"/g, "&quot;"); fetch(u);',                                                               expect: 1 },
    { label: 'a regex containing a SINGLE quote does not swallow the rest of the file',
      src: "const t = s.replace(/'/g, '&#39;'); fetch(u);",                                                                expect: 1 },
    { label: 'a regex containing a BACKTICK does not swallow the rest of the file',
      src: 'const t = s.replace(/`/g, ""); fetch(u);',                                                                     expect: 1 },
    { label: 'a slash inside a character class does not end the regex early',
      src: 'const t = s.split(/[/]/); fetch(u);',                                                                          expect: 1 },
    { label: 'a fetch( mention INSIDE a regex is not counted',
      src: 'const re = /fetch\\(/; const x = 1;',                                                                            expect: 0 },

    /* ---- DIVISION must NOT be mistaken for a regex --------------------------
       The inverse error, and the one that would make this guard fail on correct
       code: if `/` after an operand were read as a regex start, the rest of the
       expression would be blanked and a real call after it lost. */
    { label: 'division after an identifier is not read as a regex',
      src: 'const ratio = width / height; fetch(u);',                                                                      expect: 1 },
    { label: 'division after a closing paren is not read as a regex',
      src: 'const v = (a + b) / 2; fetch(u);',                                                                             expect: 1 },
    { label: 'division after a closing bracket is not read as a regex',
      src: 'const v = arr[0] / 2; fetch(u);',                                                                              expect: 1 },
    { label: 'a regex after `return` IS recognised (keyword, not operand)',
      src: 'function f() { return /x"y/.test(s); } fetch(u);',                                                             expect: 1 },
];

for (const t of selfTestCases) {
    check(`self-test: ${t.label}`, countRawFetch(t.src) === t.expect, `expected ${t.expect}, got ${countRawFetch(t.src)}`);
}

/* ======================================================================
 * Assertion 3 — the DERAILMENT detector itself can fire (#1701)
 *
 * The check that protects every other assertion in this file is worth no
 * more than its own ability to fail. Rule #34: derive it, then prove it.
 * ==================================================================== */
console.log('');
console.log('Assertion 3 — the derailment detector reports a broken lex rather than a clean one:');

const derailCases = [
    { label: 'an unterminated double-quoted string derails',  src: 'const a = "oops;\nfetch(u);',      clean: false },
    { label: 'an unterminated single-quoted string derails',  src: "const a = 'oops;\nfetch(u);",      clean: false },
    { label: 'an unterminated template literal derails',      src: 'const a = `oops;\nfetch(u);',      clean: false },
    { label: 'an unterminated block comment derails',         src: '/* oops\nfetch(u);',              clean: false },
    { label: 'ordinary well-formed source does NOT derail',   src: 'const a = "ok"; fetch(u);',        clean: true  },
    { label: 'a regex-heavy but well-formed file does NOT derail',
      src: 'const t = s.replace(/"/g, "x").split(/[/]/); fetch(u);',                                   clean: true  },
];

for (const t of derailCases) {
    const endState = stripCommentsAndStrings(t.src).state;
    check(
        `self-test: ${t.label}`,
        (endState === 'code') === t.clean,
        `end state was '${endState}', expected ${t.clean ? "'code'" : 'anything but code'}`
    );
}

/* The two halves must agree: a derailed file is exactly one whose tail was
   dropped. Proven by construction rather than asserted in prose — the same
   source, with and without the unterminated quote, must differ in what the
   scan can see. */
const derailedSrc = 'const a = "oops;\nfetch(u);';
const healthySrc  = 'const a = "ok"; fetch(u);';
check(
    'self-test: a derailed scan really does LOSE the call it should have found',
    countRawFetch(derailedSrc) === 0 && countRawFetch(healthySrc) === 1,
    `derailed saw ${countRawFetch(derailedSrc)} (want 0), healthy saw ${countRawFetch(healthySrc)} (want 1) — `
    + 'if the derailed case found the call, this file no longer demonstrates why the detector exists'
);

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
