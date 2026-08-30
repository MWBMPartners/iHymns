/**
 * iHymns — DOM-target integrity for the public app
 *
 * ELI5
 * ----
 * Our JavaScript reaches for bits of the page by name ("give me the thing
 * called `search-input`"). This checks every one of those names is a thing
 * the page actually builds — and that every keyboard shortcut we advertise
 * to the user leads somewhere real.
 *
 * WHY THIS EXISTS — the silent no-op, again
 * -----------------------------------------
 * `document.getElementById('x')` returns `null` when nothing emits `id="x"`.
 * Every call site in this codebase then guards on it —
 * `if (el) {...}`, `if (!bar) return;` — which is correct defensive code and
 * ALSO the perfect disguise: the handler binds nothing, the shortcut does
 * nothing, no exception is thrown, nothing appears in the console. The page
 * renders, Bootstrap works, the feature is simply absent. That is the class
 * that killed the public Export feature for ~7 weeks (#1565, rule #30) and
 * the Settings language filter (#1581, rule #35).
 *
 * The instance that motivated this file: #812 removed the header search bar
 * ("the footer-nav already exposes a Search entry; one affordance is enough")
 * and updated the CSS to match — but not `js/modules/search.js`, which kept
 * looking up `#header-search-bar` / `#search-input` / `#header-search-toggle`
 * / `#search-clear-btn`. Those four ids have not existed since. The visible
 * consequence was NOT dead markup, it was two DEAD KEYBOARD SHORTCUTS: `/`
 * and `Ctrl`+`K`, both advertised as "Open search" in the shortcuts overlay
 * (js/modules/shortcuts.js) AND on the public help page
 * (includes/pages/help.php) — and both calling `toggleHeaderSearch()`, whose
 * first act is `if (!bar) return;`. `e.preventDefault()` ran first, so `/`
 * did not even type a slash. A documented promise, silently unkept.
 *
 * DERIVED, NOT TYPED (rule #34)
 * -----------------------------
 * Both assertions below build their subject list by walking the tree:
 * assertion 1 from every `getElementById`/`querySelector('#…')` in the public
 * JS, assertion 2 from the `<kbd>` rows the overlay itself renders. Add a new
 * lookup or a new advertised shortcut anywhere and it is covered with no edit
 * here — that is the whole point. Neither has a hand-maintained allowlist,
 * because an allowlist is where this class of bug goes to hide.
 *
 * WIDENED SCOPE (silent-wiring sweep, 2026-08-30, epic #2008)
 * -------------------------------------------------------------
 * Assertion 1's needle corpus used to be `appWeb/public_html/js/**` only —
 * the PUBLIC app. That left the entire `manage/**` tree and every inline
 * `<script>` body in every `.php` page (public AND admin) unscanned, which is
 * exactly where the #2008 sweep found real dead wiring living: eight leftover
 * `btn-*-export` id lookups in `manage/editor/index.php` (dead "enable this
 * button" wiring from the #1166 unified Export dropdown) and a dead
 * `#song-count` update in `manage/editor/editor.js` (#1180 leftover, removed
 * from the markup but not from the JS). It is also the historical home of the
 * gwiz-rollback-inline bug (`manage/gating.php`, a11y audit A0, 2026-08-30):
 * a button rendered with `data-gwiz-rollback-inline` and NO `id`, looked up
 * with `getElementById('gwiz-rollback-inline')` — a named, focusable, silently
 * dead control. So the needle corpus is now every `*.js` under
 * `appWeb/public_html` (not just `js/`) PLUS every inline `<script>` body
 * extracted from every `*.php` under `public_html` (external `src=` scripts
 * and inert `application/(ld+)?json` islands are skipped — same allow-shape
 * as `tests/php/test-fragment-inline-scripts.php` / `tests/test-manage-php-urls.js`).
 * Comments are stripped from the needle side too (string-aware, char-by-char,
 * so a `//` inside a quoted URL string is never mistaken for a comment —
 * mirrors `stripJsComments()` in `tests/test-manage-php-urls.js`), with every
 * stripped span replaced by same-length whitespace/newlines so a reported
 * line number is always the real source line (the #1701 / rule #34 gotcha:
 * an earlier prototype of this widening deleted newlines outright and
 * mis-reported findings by ~200 lines).
 *
 * The haystack also gained a shape the widened needle side needs:
 * `'id' => 'x'` / `"id" => "x"` — a PHP array-config literal handed to a
 * field-rendering helper (e.g. `manage/songbook-series.php`'s
 * `ihymns_slug_advanced_field(['id' => 'create-slug', …])`). Without this
 * shape a perfectly live id renders through a helper instead of a literal
 * `id="…"` attribute and this scanner would false-flag it as dead.
 *
 *   node tests/test-dom-target-integrity.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const WEB = join(__dirname, '..', 'appWeb');
const PUB = join(WEB, 'public_html');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else {
        failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
        console.log(`  FAIL  ${label}`);
        if (detail) console.log(`        ${detail}`);
    }
}

function walk(dir, re, out = []) {
    let entries;
    try { entries = readdirSync(dir, { withFileTypes: true }); } catch { return out; }
    for (const e of entries) {
        if (e.name === 'vendor' || e.name === 'node_modules' || e.name.startsWith('.')) continue;
        const p = join(dir, e.name);
        if (e.isDirectory()) walk(p, re, out);
        else if (re.test(e.name)) out.push(p);
    }
    return out;
}

const rel = (p) => p.slice(PUB.length + 1);

/* Inline `<script>` opening tag + body. Attributes captured separately so the
   src=/JSON-island exemptions below can be checked without re-matching. */
const SCRIPT_TAG_RE = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;

/**
 * String/template-literal/regex-AWARE JS comment stripper — walks the source
 * one character at a time, tracking whether it is inside a string / template
 * literal / regex literal / line comment / block comment, so a `//` sitting
 * inside a quoted URL (`'https://…'`) is never mistaken for the start of a
 * real comment (the #1701 regex-literal trap). Blanks comments to
 * same-length whitespace, newlines kept, so a line number computed against
 * the output still lands on the real source line.
 *
 * Base algorithm copied from tests/test-manage-php-urls.js rather than
 * imported — each tests/test-*.js file is self-contained per house style (no
 * shared helper module exists on the JS test side; the PHP side shares via
 * tests/php/lib/ instead) — but WIDENED here with regex-literal recognition,
 * which that file's narrower use case never needed and this one does.
 *
 * REGEX-LITERAL TRAP (found scanning manage/gating.php during the
 * silent-wiring sweep, epic #2008 — recording it here so it is never
 * rediscovered): `.replace(/"/g, '&quot;')` — a real, common pattern in this
 * codebase's HTML-escaping helpers — contains a `/"/ ` regex literal whose
 * PATTERN is a bare double-quote. A quote-tracking walker with no concept of
 * regex literals sees the `/` as an ordinary character (it is neither `//`
 * nor `/*`) and then reads the `"` inside the pattern as the OPENING of a
 * real double-quoted string. Every following `"` in the file then toggles
 * that phantom string state on/off, which — over the following ~1600
 * characters of a large inline script — desynced the walker enough that it
 * failed to recognise a REAL `/* ... *\/` doc-comment describing the historic
 * gwiz-rollback-inline bug, and this guard reported the comment's own PROSE
 * (`getElementById('gwiz-rollback-inline')`, quoted for the reader) as a live
 * dead lookup. A false alarm citing a sentence, not code — worse than a
 * missed one, because rule #34 says a guard that cries wolf gets deleted.
 *
 * The fix is the classic regex-vs-division heuristic: `/` opens a regex
 * literal only when the last significant (non-whitespace) character emitted
 * so far is one this codebase's style only ever pairs with a regex argument
 * — `(` `,` `=` `:` `;` `!` `&` `|` `?` `{` `[` `+` `-` `*` `%` `<` `>` a
 * newline, or nothing (start of file). After an identifier, digit, `)` or
 * `]`, `/` is division and is left untouched — the walker is deliberately
 * biased toward UNDER-detecting regex literals in ambiguous cases (rule #34:
 * under-reporting a suppression only costs recall on some other file, never
 * turns a real bug invisible here — this file's job is finding dead id
 * lookups, not perfectly tokenising JavaScript).
 * https://developer.mozilla.org/docs/Web/JavaScript/Reference/Lexical_grammar#comments
 * https://developer.mozilla.org/docs/Web/JavaScript/Reference/Regular_expressions
 */
function stripJsComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    let mode = null; // null | 'sq' | 'dq' | 'tpl' | 'line' | 'block' | 'regex'
    let inCharClass = false; // regex mode only: inside a [...] class, where an unescaped '/' does not close the regex
    let lastSig = '';        // last non-whitespace character actually emitted, for the regex/division heuristic
    const REGEX_PRECEDERS = new Set(['(', ',', '=', ':', ';', '!', '&', '|', '?', '{', '[', '+', '-', '*', '%', '<', '>', '\n', '']);

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
            if (c === closer) { mode = null; lastSig = closer; }
            i++; continue;
        }
        if (mode === 'regex') {
            if (c === '\\') { out += c + c2; i += 2; continue; }
            if (c === '[') { inCharClass = true; out += c; i++; continue; }
            if (c === ']') { inCharClass = false; out += c; i++; continue; }
            if (c === '/' && !inCharClass) {
                out += c; i++;
                while (i < n && /[a-z]/i.test(src[i])) { out += src[i]; i++; } /* flags: g, i, m, … */
                mode = null; lastSig = '/';
                continue;
            }
            if (c === '\n') { mode = null; out += c; i++; continue; } /* unterminated — bail, don't eat the rest of the file */
            out += c; i++; continue;
        }

        if (c === '/' && c2 === '/') { mode = 'line';  out += '  '; i += 2; continue; }
        if (c === '/' && c2 === '*') { mode = 'block'; out += '  '; i += 2; continue; }
        if (c === '/' && REGEX_PRECEDERS.has(lastSig)) { mode = 'regex'; inCharClass = false; out += c; i++; continue; }
        if (c === '\'') { mode = 'sq';  out += c; i++; continue; }
        if (c === '"')  { mode = 'dq';  out += c; i++; continue; }
        if (c === '`')  { mode = 'tpl'; out += c; i++; continue; }
        out += c;
        if (!/\s/.test(c)) lastSig = c;
        i++;
    }
    return out;
}

/* ======================================================================
 * Assertion 1 — every id JS looks up (whole tree + inline scripts) is
 * emitted somewhere
 * ==================================================================== */
console.log('\nAssertion 1 — every id JS looks up (public + manage + inline <script>) is emitted somewhere in the tree:\n');

/* The HAYSTACK is deliberately the WHOLE web tree, not just the public
   fragments: a public module may legitimately be reused by an admin page that
   emits the id (js/modules/song-media-editor.js looks up `tab-media`, which
   manage/editor/index.php emits). Scoping the haystack to includes/ alone made
   this scanner report that as dead — a false positive, and a scanner that
   cries wolf gets deleted rather than fixed (rule #34). */
const haystackFiles = walk(WEB, /\.(php|js|html)$/);
/* Comments are stripped from the haystack, not just read past. A file that
   merely MENTIONS `id="foo"` in a doc-block would otherwise count as emitting
   it, and a genuinely dangling lookup for `#foo` would be waved through. That
   is not hypothetical: 23 ids in this tree exist only inside comments (mostly
   admin-nav route names quoted in prose). The sibling
   tests/test-song-nav-direction.js was caught by exactly this — its own
   explanatory comment satisfied the assertion the comment was describing. */
const haystack = haystackFiles
    .map((f) => readFileSync(f, 'utf8'))
    .join('\n')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/* Ids emitted literally: id="x" in markup, id="x" inside a JS template string
   (where the quotes may be escaped), and the three programmatic/PHP-config
   forms. */
const emitted = new Set();
for (const m of haystack.matchAll(/\bid\s*=\s*(?:\\?["'])([A-Za-z][\w:.-]*)(?:\\?["'])/g)) emitted.add(m[1]);
for (const m of haystack.matchAll(/\.id\s*=\s*['"`]([\w:.-]+)['"`]/g)) emitted.add(m[1]);
for (const m of haystack.matchAll(/setAttribute\(\s*['"]id['"]\s*,\s*['"`]([\w:.-]+)['"`]/g)) emitted.add(m[1]);
/* `'id' => 'x'` / `"id" => "x"` — a PHP array-config literal handed to a
   field-rendering helper (widened-scope addition, epic #2008). Real
   instance: manage/songbook-series.php passes `['id' => 'create-slug', …]`
   to ihymns_slug_advanced_field() — no literal `id="…"` attribute is ever
   written for that field, so without this shape the scanner false-flags a
   perfectly live id. */
for (const m of haystack.matchAll(/['"]id['"]\s*=>\s*['"]([\w:.-]+)['"]/g)) emitted.add(m[1]);

/* Ids built dynamically — `id="row-${i}"`, `id="card-<?= $x ?>"`. Only the
   static PREFIX is knowable, so record it and treat any lookup starting with
   one as satisfiable. Without this the scanner reports every templated id as
   dead, which is the under-reporting-in-reverse failure of rule #34. */
const dynPrefixes = [];
for (const m of haystack.matchAll(/\bid\s*=\s*\\?["'`]([\w-]*)(?:\$\{|<\?)/g)) if (m[1]) dynPrefixes.push(m[1]);

/* NEEDLE gathering (widened-scope addition, epic #2008): every `*.js` under
   the WHOLE `appWeb/public_html` tree (not just `js/`) PLUS every inline
   `<script>` body extracted from every `*.php` under `public_html`. See the
   file-header note on why (the #1166/#1180/gwiz-rollback-inline bugs all
   lived in this previously-unscanned scope). */
const jsFiles = walk(PUB, /\.js$/);
const jsSources = jsFiles.map((f) => ({ file: rel(f), text: readFileSync(f, 'utf8'), lineOffset: 0 }));

let scriptBlocksScanned = 0;
const phpFiles = walk(PUB, /\.php$/);
for (const f of phpFiles) {
    const raw = readFileSync(f, 'utf8');
    /* HTML `<!-- -->` and block `/* *\/` comments blanked (newline-preserving,
       gotcha #1/#5 — a `<script>` mentioned only in a doc-comment must not be
       extracted as real, and every offset computed against the blanked text
       stays valid against `raw` because the length never changes). */
    const cleanedForTags = raw
        .replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    SCRIPT_TAG_RE.lastIndex = 0;
    let sm;
    while ((sm = SCRIPT_TAG_RE.exec(cleanedForTags)) !== null) {
        const attrs = sm[1];
        /* External `src=` scripts are governed by the emitting file itself
           (already in the corpus); inert JSON data islands are never
           executable. Same allow-shape as test-fragment-inline-scripts.php /
           test-manage-php-urls.js. */
        if (/\bsrc\s*=/i.test(attrs)) continue;
        if (/type\s*=\s*["']application\/(ld\+)?json["']/i.test(attrs)) continue;
        scriptBlocksScanned++;
        const bodyStart = sm.index + 7 /* '<script'.length */ + attrs.length + 1 /* '>' */;
        const rawBody = raw.slice(bodyStart, bodyStart + sm[2].length);
        const lineOffset = cleanedForTags.slice(0, bodyStart).split('\n').length - 1;
        jsSources.push({ file: `${rel(f)} [inline]`, text: rawBody, lineOffset });
    }
}
check(`scanner extracted a plausible number of inline <script> blocks (${scriptBlocksScanned})`,
    scriptBlocksScanned >= 30, `only found ${scriptBlocksScanned} — an extraction regression, not a clean tree`);

const lookups = new Map();   /* id -> ["file:line", ...] */
for (const s of jsSources) {
    /* String-aware JS comment stripping (mirrors stripJsComments() in
       tests/test-manage-php-urls.js) so a `//` or `id` mentioned only inside
       a quoted string or a comment can never register as a real lookup, and
       so the #1701 regex-literal trap (a `//` inside a URL string mistaken
       for a line comment) cannot recur here either. Newline-preserving —
       gotcha #1 — so reported line numbers stay true. */
    const stripped = stripJsComments(s.text);
    stripped.split('\n').forEach((line, i) => {
        const loc = `${s.file}:${s.lineOffset + i + 1}`;
        for (const m of line.matchAll(/getElementById\(\s*['"]([\w:.-]+)['"]\s*\)/g)) {
            if (!lookups.has(m[1])) lookups.set(m[1], []);
            lookups.get(m[1]).push(loc);
        }
        for (const m of line.matchAll(/querySelector(?:All)?\(\s*['"]#([\w:.-]+)['"]\s*\)/g)) {
            if (!lookups.has(m[1])) lookups.set(m[1], []);
            lookups.get(m[1]).push(loc);
        }
    });
}

/* A zero here would mean the scanner matched nothing and every assertion below
   is vacuously true — the "confident green that verifies nothing" failure. */
check(`scanner found id lookups to check (${lookups.size} distinct, across ${jsFiles.length} JS files + ${scriptBlocksScanned} inline script blocks)`,
    lookups.size > 50, `only found ${lookups.size}`);
check(`scanner found ids emitted by the tree (${emitted.size} literal, ${dynPrefixes.length} templated)`,
    emitted.size > 100, `only found ${emitted.size}`);

const dangling = [];
for (const [id, where] of [...lookups].sort()) {
    if (emitted.has(id)) continue;
    if (dynPrefixes.some((p) => p && id.startsWith(p))) continue;
    dangling.push([id, where]);
}
check('every id looked up by public JS is emitted somewhere',
    dangling.length === 0,
    dangling.map(([id, w]) => `#${id} <- ${w.join(', ')}`).join('\n        '));

/* ======================================================================
 * Assertion 2 — every advertised keyboard shortcut reaches a live handler
 * ==================================================================== */
console.log('\nAssertion 2 — every shortcut the overlay advertises has a dispatch case:\n');

const shortcutsSrc = readFileSync(join(PUB, 'js/modules/shortcuts.js'), 'utf8');
const appSrc = readFileSync(join(PUB, 'js/app.js'), 'utf8');

/* Each advertised row is `<dt>…<kbd>K</kbd>…</dt>` + `<dd>description</dd>`. */
const advertised = [];
for (const m of shortcutsSrc.matchAll(/<dt>([\s\S]*?)<\/dt>\s*<dd>([\s\S]*?)<\/dd>/g)) {
    const keys = [...m[1].matchAll(/<kbd>(.*?)<\/kbd>/g)].map((k) => k[1].trim());
    advertised.push({ keys, desc: m[2].trim().replace(/\s+/g, ' ') });
}
check(`parsed the advertised shortcut rows out of shortcuts.js (${advertised.length} rows)`,
    advertised.length >= 10, `only parsed ${advertised.length}`);

/* HTML notation -> KeyboardEvent.key. This is a NOTATION table, not a list of
   things to check: the rows come from the tree, and an unrecognised token
   FAILS below rather than being skipped, so the table cannot silently shrink
   this test's coverage.
   https://developer.mozilla.org/docs/Web/API/KeyboardEvent/key/Key_Values */
const KEY_NOTATION = {
    '&larr;': 'ArrowLeft', '&rarr;': 'ArrowRight',
    'Space': ' ', 'Esc': 'Escape', 'Enter': 'Enter', 'Backspace': 'Backspace',
};
/* Modifier names never appear as a `case` on their own. */
const MODIFIERS = new Set(['Ctrl', 'Cmd', 'Shift', 'Alt', 'Option']);

/* The digit range `<kbd>0</kbd>&ndash;<kbd>9</kbd>` is handled by app.js's
   explicit `e.key >= '0' && e.key <= '9'` range test, not by a case label. */
const hasDigitRange = /e\.key\s*>=\s*'0'\s*&&\s*e\.key\s*<=\s*'9'/.test(appSrc);

function dispatched(key) {
    if (MODIFIERS.has(key)) return true;
    const k = KEY_NOTATION[key] ?? key;
    if (/^[0-9]$/.test(k)) return hasDigitRange;
    /* `case 'f':` / `case 'F':` — accept either casing for a letter. */
    const variants = k.length === 1 ? [k.toLowerCase(), k.toUpperCase()] : [k];
    const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return variants.some((v) =>
        new RegExp(`case\\s+'${esc(v)}'\\s*:`).test(appSrc)
        /* A modified shortcut (Ctrl+K) gets its own listener with an equality
           test rather than a case label in the plain-key switch. */
        || new RegExp(`e\\.key\\s*===\\s*'${esc(v)}'`).test(appSrc));
}

for (const row of advertised) {
    check(`"${row.desc}" — ${row.keys.join(' ')} is dispatched`,
        row.keys.every(dispatched),
        `unhandled: ${row.keys.filter((k) => !dispatched(k)).join(', ')}`);
}

/* ======================================================================
 * Assertion 3 — "Open search" actually reaches the search page
 * ==================================================================== */
console.log('\nAssertion 3 — the "Open search" shortcut leads to the search page:\n');

/* Assertion 2 only proves a `case` exists. `/` HAD a case for as long as it was
   broken — it called toggleHeaderSearch(), which returned immediately because
   the bar #812 deleted was gone. So follow the handler one step further: find
   the Search method the `/` case invokes, and require that method to reference
   the /search route. #812's own comment names that route as the surviving
   affordance, so this is the contract, not a guess. */
/* Strip block comments BEFORE matching. A fixed character window between the
   case label and the call is unreliable for exactly the reason it looks safe:
   the first draft of this file used 400/300 chars and went red the moment the
   fix landed, because the fix added an explanatory comment between the two.
   The same mistake is recorded against test-editor-api2-contract.php (rule
   #34) — a window widened once is a window that will be too narrow again.
   Removing the comments removes the variable instead of guessing at it.       */
const appCode = appSrc.replace(/\/\*[\s\S]*?\*\//g, '');
/* Guard the strip itself: if it ate the code as well as the prose, every
   assertion below would fail for the wrong reason and send the next reader
   hunting a bug that is in this file. */
check('comment-stripping left app.js dispatch intact', /switch\s*\(\s*e\.key\s*\)/.test(appCode));

const slashCase = /case '\/':[\s\S]{0,800}?this\.search\.([A-Za-z_$][\w$]*)\s*\(/.exec(appCode);
check("the `/` case calls a method on the search module", !!slashCase,
    'no `this.search.<method>(...)` found within the `/` case');

const ctrlK = /\(\s*e\.ctrlKey\s*\|\|\s*e\.metaKey\s*\)[\s\S]{0,800}?this\.search\.([A-Za-z_$][\w$]*)\s*\(/.exec(appCode);
check('the Ctrl/Cmd+K listener calls a method on the search module', !!ctrlK,
    'no `this.search.<method>(...)` found in the Ctrl/Cmd+K listener');

/* Same reasoning as appCode above — and here it matters twice over, since the
   assertions look for `/search` and `header-search-bar` as SUBSTRINGS, and both
   appear in this module's prose. Matching against the comments would let a
   doc-block describing the fix stand in for the fix. */
const searchSrc = readFileSync(join(PUB, 'js/modules/search.js'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');
check('comment-stripping left search.js class body intact', /class Search/.test(searchSrc));

for (const [label, hit] of [['`/`', slashCase], ['Ctrl/Cmd+K', ctrlK]]) {
    if (!hit) continue;
    const method = hit[1];
    /* Grab the method body: from its declaration to the next method at the same
       indent. Bounded generously — a 120-char window silently truncated a
       sibling guard in this repo before (rule #34). */
    const body = new RegExp(`\\n    (?:async\\s+)?${method}\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)\\n    \\}`).exec(searchSrc);
    check(`${label} -> Search.${method}() exists in search.js`, !!body);
    if (!body) continue;
    check(`${label} -> Search.${method}() navigates to the /search route (not a removed header bar)`,
        /['"`]\/search/.test(body[1]),
        `Search.${method}() never mentions /search — it is the #812 dead end again`);
    check(`${label} -> Search.${method}() does not depend on the #812-removed header bar`,
        !/header-search-bar/.test(body[1]),
        `Search.${method}() still looks up #header-search-bar, deleted in #812`);
}

/* ======================================================================
 * Assertion 4 — openSearch() BEHAVES, not just reads correctly
 * ==================================================================== */
/* Assertions 1-3 are structural: they prove the wiring names things that
   exist. They cannot prove the method does the right thing when run, and the
   bug this file exists for was precisely a method that read fine and did
   nothing. So run it, in a real DOM, both ways round.
   The now-removed search autocomplete (#307) showed why this matters: its
   ARIA test drove `_showAutocomplete()` directly against an
   `<input id="search-input">` it built itself, so it passed for the entire
   period during which no page anywhere emitted that id and no code ever called
   `_initAutocomplete` — the feature was unreachable the whole time. Exercising
   a function in isolation says nothing about whether the product can reach it;
   that dead cluster was deleted wholesale in #307. */
console.log('\nAssertion 4 — openSearch() navigates and focuses when actually run:\n');

const { JSDOM } = await import('jsdom');

async function runOpenSearch(startPath, { alreadyHasInput }) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>',
        { url: `https://example.test${startPath}` });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.URL = window.URL;
    global.fetch = async () => ({ ok: true, json: async () => ({}) });
    window.fetch = global.fetch;
    global.localStorage = window.localStorage;

    const addInput = () => {
        const el = window.document.createElement('input');
        el.id = 'page-search-input';
        window.document.body.appendChild(el);
        return el;
    };
    if (alreadyHasInput) addInput();

    const navigated = [];
    const app = {
        config: { apiUrl: '/api' },
        router: {
            navigate: async (p) => {
                navigated.push(p);
                /* Mirror the real router: the fragment (and therefore the
                   input) only exists AFTER navigate() resolves. */
                addInput();
            },
        },
    };

    const { Search } = await import(pathToFileURL(join(PUB, 'js/modules/search.js')).href);
    const search = new Search(app);
    await search.openSearch();
    return { navigated, active: window.document.activeElement };
}

const fromHome = await runOpenSearch('/', { alreadyHasInput: false });
check('from another page, openSearch() navigates to /search',
    fromHome.navigated.length === 1 && fromHome.navigated[0] === '/search',
    `navigated to: ${JSON.stringify(fromHome.navigated)}`);
check('from another page, openSearch() focuses the search input once it exists',
    fromHome.active && fromHome.active.id === 'page-search-input',
    `focus landed on: ${fromHome.active ? fromHome.active.id || fromHome.active.tagName : 'nothing'}`);

/* The router early-returns when the path is unchanged, so on /search itself a
   navigate-then-focus implementation would focus nothing. This is the case a
   user hits every time they press the shortcut twice. */
const onSearch = await runOpenSearch('/search', { alreadyHasInput: true });
check('already on /search, openSearch() does NOT re-navigate',
    onSearch.navigated.length === 0,
    `navigated to: ${JSON.stringify(onSearch.navigated)}`);
check('already on /search, openSearch() still focuses the input',
    onSearch.active && onSearch.active.id === 'page-search-input',
    `focus landed on: ${onSearch.active ? onSearch.active.id || onSearch.active.tagName : 'nothing'}`);

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nAn id nothing emits makes every guarded handler a silent no-op: the page');
    console.error('renders, nothing throws, and the feature is simply absent. Emit the id, or');
    console.error('stop looking it up — and if a shortcut is advertised to the user, make sure');
    console.error('the thing it calls still leads somewhere.');
    process.exit(1);
}
console.log('\nAll DOM-target integrity assertions passed.');
