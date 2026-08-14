/**
 * tests/test-manage-php-urls.js — .htaccess .php-hiding-redirect transport guard (#1855)
 *
 * ELI5: `manage/.htaccess` cosmetically hides `.php` from every `/manage/**`
 * URL by 301-redirecting the literal `.php` form to its extensionless
 * sibling. A browser is SPECIFIED to replay a 301'd POST/PUT/PATCH/DELETE
 * as a body-less GET (only 307/308 preserve method + body) — so a WRITE
 * aimed at a literal `/manage/foo.php` URL silently loses its whole
 * request body: no crash, no console error, just fields the server sees
 * as empty. This bit the v2 Song Editor for as long as its API client
 * requested `/manage/editor/api2.php` instead of `/manage/editor/api2`.
 *
 * The server-side fix (`manage/.htaccess`, `#1855`) adds a
 * `RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$` so the redirect can never
 * fire for a request carrying a body — that makes the bug class *survive*
 * a stray `.php` URL without silently eating data. This guard is the
 * belt-and-braces half: it stops a literal `/manage/**.php` browser
 * request URL from being written in the first place, so every write goes
 * straight to the endpoint with no redirect hop at all.
 *
 * ALGORITHM (mirrors tests/php/test-fragment-inline-scripts.php's shape —
 * comment-stripped source-tree scan, no server, no DB):
 *   1. Walk appWeb/public_html/**\/*.js and **\/*.php (tree-derived, never
 *      a hardcoded file list — rule #34 in .claude/CLAUDE.md).
 *   2. Strip comments BEFORE matching, preserving every newline so a
 *      reported line number still points at the real source line:
 *      - JS line comments and star-slash block comments are stripped by
 *        a small string/template-literal-AWARE scanner (stripJsComments
 *        below) — it walks the source char-by-char so a comment opener
 *        sitting inside a quoted string (e.g. `'https://…'`) is never
 *        mistaken for the start of a real comment.
 *      - `.php` files additionally get HTML `<!-- -->` comments and PHP/
 *        JS star-slash block comments blanked first (same technique as
 *        the fragment-inline-script guard) so a `<script>` or `<form>`
 *        mentioned only in a doc-comment is never extracted as real.
 *   3. For `.php` files, only the body of an inline `<script>…</script>`
 *      block is scanned as JS (external `src=` scripts and inert
 *      `application/(ld+)?json` islands are skipped, same allow-shape as
 *      the fragment-inline-script guard) — PHP `require`/`include` paths
 *      and `header('Location: …')` redirects are never JS and can never
 *      match. `<form … action="…">` is checked separately across the
 *      WHOLE file (a form submission is a browser request regardless of
 *      whether the file also happens to call fetch()).
 *   4. Within a scanned JS body, a `/manage/…\.php` string literal is
 *      flagged ONLY inside a file/script-block that also calls
 *      `fetch(`, `apiFetch(`, or `XMLHttpRequest#.open(` somewhere in the
 *      same script — i.e. a file that actually does browser networking.
 *      This is deliberately not "any fetch(...)-adjacent literal": tracing
 *      exact call-argument dataflow through a `const ENDPOINT = '…'`
 *      later used as `fetch(ENDPOINT + '?action=…')`, or a `searchUrl:
 *      (q) => '…' ` callback consumed by a shared typeahead module,
 *      would need a real JS parser. Scoping the literal ban to
 *      networking-capable files instead catches exactly those indirect
 *      shapes (verified against every real case in this tree: `const
 *      ENDPOINT`, `DEFAULTS.endpoint`, a `url:`/`searchUrl:` object
 *      property) without one, at the honestly-stated cost that a
 *      networking file with an UNRELATED string that happens to look like
 *      a `/manage/*.php` path would also be flagged — narrow enough that
 *      this has not happened anywhere in the tree as of this guard's
 *      introduction.
 *
 * OUT OF SCOPE ON PURPOSE (rule #34 — keep a guard narrow enough not to
 * fail on correct code):
 *   - Server-side `require`/`include` paths — never JS, so a comment- and
 *     script-scoped scan can't reach them by construction.
 *   - `header('Location: …')` redirects — same reason.
 *   - A `.php` mention inside a comment or JSDoc `@see`/`@link` tag — the
 *     comment strippers above blank these before matching runs.
 *   - A PHP variable that BUILDS a `poll_url`/`skipped_csv_url` value
 *     embedded in a JSON response body (e.g. `manage/editor/api2.php`'s
 *     `import_zip`/`import_zip_status` handlers) — that string lives in
 *     PHP source, not inside a `<script>` tag, so it is a real backend
 *     response value rather than a literal argument to a browser network
 *     call. Those two were found and fixed by hand during the #1855
 *     sweep; this guard does not (and structurally cannot, without also
 *     parsing arbitrary PHP array literals) catch a future regression
 *     there. Tracked as a known gap, not a silent one.
 *
 * PROVEN ABLE TO FAIL (rule #34): run with `.php` re-introduced into
 * `manage/editor/v2/api-client.js`'s `ENDPOINT` const (the indirect,
 * ENDPOINT-variable shape) AND into `js/modules/print.js`'s direct
 * `apiFetch('/manage/print-pdf?ping=1', …)` call (the direct-literal
 * shape) — RED on both, independently. Restored — GREEN. See the PR/
 * handoff for the exact commands and output.
 *
 *   node tests/test-manage-php-urls.js
 *
 * Exit status 0 = clean, 1 = at least one violation.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..', 'appWeb', 'public_html');

/**
 * Recursively collect every file under `dir` whose name ends with one of
 * `exts`. Plain manual walk (matches the style of tests/test-event-names.js)
 * so this never depends on a hardcoded file list — rule #34.
 */
function collectFiles(dir, exts, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            collectFiles(full, exts, out);
        } else if (entry.isFile() && exts.some((e) => entry.name.endsWith(e))) {
            out.push(full);
        }
    }
    return out;
}

/**
 * Blank every match of `re` down to whitespace, PRESERVING every newline
 * inside the match, so a reported line number computed against the
 * stripped text still lands on the real source line.
 */
function blankPreservingLines(src, re) {
    return src.replace(re, (m) => m.replace(/[^\n]/g, ' '));
}

/** HTML `<!-- -->` comments and PHP/JS star-slash block comments, newline-preserving. */
function stripHtmlAndBlockComments(src) {
    src = blankPreservingLines(src, /<!--[\s\S]*?-->/g);
    src = blankPreservingLines(src, /\/\*[\s\S]*?\*\//g);
    return src;
}

/**
 * String/template-literal-AWARE JS comment stripper. Blanks `//` line
 * comments and star-slash block comments to a same-length run of spaces
 * (newlines kept) while leaving every '…'/"…"/`…` literal's CONTENTS
 * untouched — that's exactly what needs to survive so the URL scan below
 * can still see it. A naive global strip of every "double slash to end of
 * line" span would also eat the `//` inside `'https://…'`; this walks the
 * source one character at a time, tracking whether it is inside a
 * string/template/line-comment/block-comment, so it never does that.
 * https://developer.mozilla.org/docs/Web/JavaScript/Reference/Lexical_grammar#comments
 */
function stripJsComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    let mode = null; // null | 'sq' | 'dq' | 'tpl' | 'line' | 'block'
    while (i < n) {
        const c  = src[i];
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
            if (c === closer) mode = null;
            i++; continue;
        }

        /* Not inside anything special right now. */
        if (c === '/' && c2 === '/') { mode = 'line';  out += '  '; i += 2; continue; }
        if (c === '/' && c2 === '*') { mode = 'block'; out += '  '; i += 2; continue; }
        if (c === '\'') { mode = 'sq';  out += c; i++; continue; }
        if (c === '"')  { mode = 'dq';  out += c; i++; continue; }
        if (c === '`')  { mode = 'tpl'; out += c; i++; continue; }
        out += c; i++;
    }
    return out;
}

/** 1-based line number of `offset` within `src`. */
function lineAt(src, offset) {
    let n = 1;
    for (let i = 0; i < offset; i++) { if (src[i] === '\n') n++; }
    return n;
}

/* A browser-request-shaped literal: /manage/<stuff>.php, optionally
   followed by a query string — up to (but excluding) the closing quote
   character it was written in. */
const URL_LITERAL_RE  = /(['"`])(\/manage\/[^'"`]*\.php[^'"`]*)\1/g;
/* "This script/file does browser networking at all" gate — see the
   file-level doc-block point 4 for why the literal ban is scoped to
   these files rather than tracing exact call-site dataflow. */
const NETWORK_CALL_RE = /\b(?:fetch|apiFetch)\s*\(|\.open\s*\(\s*['"`](?:GET|HEAD|POST|PUT|PATCH|DELETE)['"`]/;

const SCRIPT_TAG_RE  = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
const SRC_ATTR_RE    = /\bsrc\s*=/i;
const JSON_TYPE_RE   = /type\s*=\s*["']application\/(ld\+)?json["']/i;
const FORM_ACTION_RE = /<form\b[^>]*\baction\s*=\s*["']([^"']*\.php[^"']*)["']/gi;

const violations = [];
let jsScanned = 0;
let phpScanned = 0;
let scriptBlocksScanned = 0;

/* ---- .js files: whole-file scan ---- */
for (const file of collectFiles(ROOT, ['.js'])) {
    jsScanned++;
    const raw = fs.readFileSync(file, 'utf8');
    const stripped = stripJsComments(raw);
    if (!NETWORK_CALL_RE.test(stripped)) continue; /* never does browser networking — out of scope */
    const rel = path.relative(ROOT, file).split(path.sep).join('/');

    URL_LITERAL_RE.lastIndex = 0;
    let m;
    while ((m = URL_LITERAL_RE.exec(stripped)) !== null) {
        violations.push(`${rel}:${lineAt(stripped, m.index)}  ${m[0]}`);
    }
}

/* ---- .php files: inline <script> bodies + whole-file <form action=> ---- */
for (const file of collectFiles(ROOT, ['.php'])) {
    phpScanned++;
    const raw = fs.readFileSync(file, 'utf8');
    const rel = path.relative(ROOT, file).split(path.sep).join('/');
    /* Same length as `raw` (comments blanked, not removed) so every
       offset computed against it is still a valid offset into `raw`. */
    const cleanedForTags = stripHtmlAndBlockComments(raw);

    FORM_ACTION_RE.lastIndex = 0;
    let fm;
    while ((fm = FORM_ACTION_RE.exec(cleanedForTags)) !== null) {
        violations.push(`${rel}:${lineAt(cleanedForTags, fm.index)}  <form action="${fm[1]}">`);
    }

    SCRIPT_TAG_RE.lastIndex = 0;
    let sm;
    while ((sm = SCRIPT_TAG_RE.exec(cleanedForTags)) !== null) {
        const attrs = sm[1];
        if (SRC_ATTR_RE.test(attrs) || JSON_TYPE_RE.test(attrs)) continue; /* external or inert data island */
        scriptBlocksScanned++;

        /* `<script` (7) + attrs + `>` (1) precedes the captured body — same
           offset is valid in `raw` because cleanedForTags is the same length. */
        const bodyStart = sm.index + 7 + attrs.length + 1;
        const rawBody   = raw.slice(bodyStart, bodyStart + sm[2].length);
        const jsStripped = stripJsComments(rawBody);
        if (!NETWORK_CALL_RE.test(jsStripped)) continue;

        URL_LITERAL_RE.lastIndex = 0;
        let m;
        while ((m = URL_LITERAL_RE.exec(jsStripped)) !== null) {
            const lineNo = lineAt(raw, bodyStart) + lineAt(jsStripped, m.index) - 1;
            violations.push(`${rel}:${lineNo}  ${m[0]}`);
        }
    }
}

console.log('#1855 — manage/.htaccess .php-hiding-redirect transport guard\n');
console.log(`Scanned ${jsScanned} .js file(s), ${phpScanned} .php file(s) (${scriptBlocksScanned} inline <script> block(s)).\n`);

if (violations.length > 0) {
    console.log(`FAIL: ${violations.length} browser request URL(s) target a literal /manage/**.php path:\n`);
    for (const v of violations) { console.log(`  ${v}`); }
    console.log('');
    console.log('A literal .php URL under /manage/ is 301-redirected by manage/.htaccess to its');
    console.log('extensionless form. A browser replays a 301\'d POST/PUT/PATCH/DELETE as a body-less');
    console.log('GET, silently dropping the whole request body. Use the extensionless URL instead');
    console.log('(drop the ".php") — see #1855 in manage/.htaccess and the affected call sites.');
    process.exit(1);
}

console.log(`PASS: no browser request targets a literal /manage/**.php path (${jsScanned + phpScanned} files scanned).`);
process.exit(0);
