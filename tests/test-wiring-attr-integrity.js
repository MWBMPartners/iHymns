/**
 * iHymns — attribute/class/dataset wiring integrity (silent-wiring sweep, epic #2008)
 *
 * ELI5
 * ----
 * `tests/test-dom-target-integrity.js` already checks that every `id` our
 * JavaScript looks up by `#id` actually exists somewhere. This is the same
 * check for the other three ways JS reaches for a piece of the page: a
 * `[data-x]` attribute selector, a `.dataset.someKey` read, and a plain
 * `.class-name` selector. Each one fails exactly the same silent way: the
 * lookup returns nothing, the caller's `if (el) {...}` guard quietly no-ops,
 * and nothing anywhere says so.
 *
 * WHY THIS EXISTS — the `.song-actions` finding (silent-wiring sweep)
 * ----------------------------------------------------------------------
 * `js/modules/live-follow.js`'s `_mountControls()` looked for its PRIMARY
 * mount point with `page.querySelector('.song-actions')` — a class
 * `includes/pages/song.php` never emitted. The feature survived for however
 * long on nothing but a brittle structural fallback
 * (`.d-flex.flex-wrap.gap-2`, a bare Bootstrap-utility-class combination one
 * markup reshuffle away from silently breaking). No error, no visible
 * symptom — until it was. Fixed in the same commit as this guard by emitting
 * the class; this file is what keeps that class of bug from recurring on any
 * of the three axes below.
 *
 * SCOPE
 * -----
 * Same corpus discipline as the widened `test-dom-target-integrity.js`:
 * every `*.js` under `appWeb/public_html` PLUS every inline `<script>` body
 * extracted from every `*.php` under `public_html` (external `src=` scripts
 * and inert `application/(ld+)?json` islands skipped). The comment stripper
 * is the same string/template-literal/regex-AWARE char-walker (copied, not
 * imported — each `tests/test-*.js` file is self-contained per house style)
 * — see that file's header for the regex-literal trap this walker was
 * widened to handle (`.replace(/"/g, …)` desyncing a naive quote tracker).
 *
 * THREE AXES
 * ----------
 *  1. `[data-x…]` attribute selectors — every `data-` token found inside a
 *     `querySelector[All]/closest/matches('…')` selector string, plus
 *     `getAttribute('data-x')` — checked against every `data-x=` / bare
 *     `data-x` / `setAttribute('data-x', …)` / `.dataset.someKey = …` write
 *     emitted anywhere in the tree.
 *  2. `.dataset.someKey` READS (kebab-folded to `data-some-key`) — same
 *     emission haystack as axis 1.
 *  3. Single-class selectors — `querySelector[All]/closest('.x')`
 *     (whole-selector only; compound selectors like `.a.b` or `.a[data-x]`
 *     are deliberately skipped — under-reporting here is safe and keeps
 *     precision high, same trade the id guard makes) — checked against
 *     `class="a b c"` / `classList.add/toggle('x')` / `className = '…'`
 *     token-split emissions.
 *
 * FALSE-POSITIVE HANDLING (every one measured against this tree, not
 * hypothetical — see the file-level comments at each site below):
 *  - Dynamic class/data-attr PREFIXES (`className = '… credit-name-' +
 *    field + '-input'`, `data-foo-${i}`) — only the static prefix is
 *    knowable, so it is harvested and any lookup starting with it passes.
 *    Mirrors the id guard's `dynPrefixes` handling.
 *  - A small, COUNT-EXACT, self-cleaning Bootstrap-runtime-created-class
 *    allowlist (`BOOTSTRAP_RUNTIME_CLASSES` below) — Bootstrap itself
 *    creates a handful of classes (`.modal-backdrop`, …) that this codebase
 *    legitimately looks up but never has to emit.
 *  - The CLASS-ONLY "last-resort tier": before flagging a class dead, check
 *    whether its exact name appears as a quoted string literal ANYWHERE in
 *    the tree OUTSIDE a lookup call's own argument (e.g.
 *    `includes/partials/export-menu.php`'s `'song' => 'song-export-menu'`
 *    PHP array value, later interpolated into a `class="…<?= $x ?>"`
 *    attribute the naive class-emission scan can't see through). This tier
 *    is NEVER applied to ids (see the sibling guard) and is proven not to
 *    swallow a real break by mutation test 5 below.
 *  - A one-sided, self-cleaning allowlist (mirrors `test-event-names.js`'s
 *    `ONE_SIDED_ALLOWLIST`) for a dataset key that is a deliberate opt-out
 *    escape hatch nothing emits YET — `dataset.noInterstitial`
 *    (`external-link-interstitial.js`) reads `data-no-interstitial` only to
 *    check its ABSENCE; the correct default is that nothing sets it.
 *
 *   node tests/test-wiring-attr-integrity.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
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

/* Inline `<script>` opening tag + body — same shape as the id guard. */
const SCRIPT_TAG_RE = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;

/**
 * String/template-literal/regex-AWARE JS comment stripper — identical to the
 * one in tests/test-dom-target-integrity.js (see that file's header for the
 * full rationale, including the `.replace(/"/g, …)` regex-literal trap this
 * walker was widened to handle). Copied rather than imported — each
 * tests/test-*.js file is self-contained per house style.
 */
function stripJsComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    let mode = null; // null | 'sq' | 'dq' | 'tpl' | 'line' | 'block' | 'regex'
    let inCharClass = false;
    let lastSig = '';
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
                while (i < n && /[a-z]/i.test(src[i])) { out += src[i]; i++; }
                mode = null; lastSig = '/';
                continue;
            }
            if (c === '\n') { mode = null; out += c; i++; continue; }
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

/* ==========================================================================
 * HAYSTACK — every emission shape, gathered once, shared across all 3 axes
 * ======================================================================== */

/* Deliberately the WHOLE web tree (not just public_html), same reasoning as
   the id guard: a public module may be reused by an admin page that emits
   the class/attr, or vice-versa. */
const haystackFiles = walk(WEB, /\.(php|js|html)$/);
const haystackRaw = haystackFiles.map((f) => readFileSync(f, 'utf8')).join('\n');
/* Comments stripped (not just skipped) so a class/attr mentioned only in a
   doc-comment cannot vouch for a genuinely dead lookup — same reasoning, and
   the same 23-ids-in-comments precedent, as the id guard. Non-newline-
   preserving here is fine: this haystack is used only for presence checks
   (Set membership / substring search), never for reporting a line number. */
const haystack = haystackRaw
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/* ---- class emissions ---------------------------------------------------- */
const emittedClasses = new Set();
for (const m of haystack.matchAll(/\bclass\s*=\s*(?:\\?["'])([^"'<>]*)(?:\\?["'])/g)) {
    for (const c of m[1].split(/\s+/)) { if (c && !/[${<]/.test(c)) emittedClasses.add(c); }
}
for (const m of haystack.matchAll(/classList\.(?:add|toggle|remove|contains)\(\s*['"`]([\w-]+)['"`]/g)) emittedClasses.add(m[1]);
for (const m of haystack.matchAll(/\bclassName\s*=\s*['"`]([^'"`]*)['"`]/g)) {
    for (const c of m[1].split(/\s+/)) { if (c) emittedClasses.add(c); }
}

/* Dynamic class prefixes: two independent shapes measured on this tree.
   (a) markup interpolation — `class="row-<?= $i ?>"` / `class="row-${i}"`;
   (b) JS string concatenation — `className = '… credit-name-' + field + '-input';`
       (manage/editor/editor.js's createCreditNameRow()). Only the static
       PREFIX before the concatenation is knowable; take the LAST
       whitespace-separated token of that prefix, since class attributes are
       space-separated and the variable only ever replaces the final token in
       this codebase's usage (`'form-control credit-name-' + field` → the
       dynamic part is the last token, `credit-name-`, not `form-control`). */
const dynClassPrefixes = [];
for (const m of haystack.matchAll(/\bclass\s*=\s*\\?["'`]([\w -]*)(?:\$\{|<\?)/g)) {
    const tok = (m[1] || '').trim().split(/\s+/).pop();
    if (tok) dynClassPrefixes.push(tok);
}
for (const m of haystack.matchAll(/\bclassName\s*=\s*['"`]([^'"`]*)['"`]\s*\+/g)) {
    const tok = (m[1] || '').trim().split(/\s+/).pop();
    if (tok) dynClassPrefixes.push(tok);
}

/* ---- data-* attribute emissions (kebab-case) ---------------------------- */
const emittedData = new Set();
for (const m of haystack.matchAll(/\bdata-([a-z0-9-]+)(?=\s*=|[\s>\]"'])/gi)) emittedData.add(m[1].toLowerCase());
for (const m of haystack.matchAll(/setAttribute\(\s*['"`]data-([a-z0-9-]+)['"`]/gi)) emittedData.add(m[1].toLowerCase());
for (const m of haystack.matchAll(/\.dataset\.([A-Za-z_$][\w$]*)\s*=(?!=)/g)) {
    emittedData.add(m[1].replace(/[A-Z]/g, (c) => '-' + c.toLowerCase()));
}
/* Dynamic data-attr prefixes — `data-foo-${i}` / `data-foo-<?= $i ?>`. */
const dynDataPrefixes = [];
for (const m of haystack.matchAll(/\bdata-([a-z0-9-]*)(?:\$\{|<\?)/gi)) if (m[1]) dynDataPrefixes.push(m[1].toLowerCase());

/* ---- CLASS-ONLY last-resort tier ----------------------------------------
   A copy of the haystack with every lookup CALL's own selector-string
   argument blanked out (same length, so this stays a pure text search — no
   line numbers are computed from it). A class name that still appears as a
   quoted literal in what's LEFT was written down somewhere other than its
   own lookup — a PHP array value later interpolated into a class attribute
   the plain class="..." scan can't see through, most commonly. This tier is
   ONLY consulted for the class axis (see the file header — applying it to
   ids would have hidden the gwiz-rollback-inline bug the sibling guard was
   built to catch, since that id's only appearance anywhere was inside its
   own dead lookup). */
const lookupCallBlanked = haystack.replace(
    /(?:querySelector(?:All)?|closest|matches)\(\s*['"`][^'"`]*['"`]\s*\)/g,
    (m) => m.replace(/[^\n]/g, ' ')
);
function classHasNonLookupLiteral(name) {
    return lookupCallBlanked.includes(`'${name}'`) || lookupCallBlanked.includes(`"${name}"`);
}

/* ==========================================================================
 * NEEDLE CORPUS — same widened tree as test-dom-target-integrity.js
 * ======================================================================== */

const jsFiles = walk(PUB, /\.js$/);
const jsSources = jsFiles.map((f) => ({ file: rel(f), text: readFileSync(f, 'utf8'), lineOffset: 0 }));

let scriptBlocksScanned = 0;
const phpFiles = walk(PUB, /\.php$/);
for (const f of phpFiles) {
    const raw = readFileSync(f, 'utf8');
    const cleanedForTags = raw
        .replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    SCRIPT_TAG_RE.lastIndex = 0;
    let sm;
    while ((sm = SCRIPT_TAG_RE.exec(cleanedForTags)) !== null) {
        const attrs = sm[1];
        if (/\bsrc\s*=/i.test(attrs)) continue;
        if (/type\s*=\s*["']application\/(ld\+)?json["']/i.test(attrs)) continue;
        scriptBlocksScanned++;
        const bodyStart = sm.index + 7 + attrs.length + 1;
        const rawBody = raw.slice(bodyStart, bodyStart + sm[2].length);
        const lineOffset = cleanedForTags.slice(0, bodyStart).split('\n').length - 1;
        jsSources.push({ file: `${rel(f)} [inline]`, text: rawBody, lineOffset });
    }
}
check(`scanner extracted a plausible number of inline <script> blocks (${scriptBlocksScanned})`,
    scriptBlocksScanned >= 30, `only found ${scriptBlocksScanned}`);

/* ==========================================================================
 * Axis 1 — [data-x] attribute selectors + getAttribute('data-x')
 * ======================================================================== */
console.log('\nAxis 1 — [data-x] attribute selectors are emitted somewhere:\n');

const dataSelLookups = new Map(); /* kebab-key -> ["file:line", ...] */
for (const s of jsSources) {
    const stripped = stripJsComments(s.text);
    stripped.split('\n').forEach((line, i) => {
        const loc = `${s.file}:${s.lineOffset + i + 1}`;
        for (const m of line.matchAll(/(?:querySelector(?:All)?|closest|matches)\(\s*['"`]([^'"`]+)['"`]/g)) {
            for (const am of m[1].matchAll(/\[data-([a-z0-9-]+)/gi)) {
                const k = am[1].toLowerCase();
                if (!dataSelLookups.has(k)) dataSelLookups.set(k, []);
                dataSelLookups.get(k).push(loc);
            }
        }
        for (const m of line.matchAll(/getAttribute\(\s*['"`]data-([a-z0-9-]+)['"`]\s*\)/gi)) {
            const k = m[1].toLowerCase();
            if (!dataSelLookups.has(k)) dataSelLookups.set(k, []);
            dataSelLookups.get(k).push(loc);
        }
    });
}

check(`scanner found [data-x] selector lookups to check (${dataSelLookups.size} distinct)`,
    dataSelLookups.size > 30, `only found ${dataSelLookups.size}`);
check(`scanner found data-* attributes emitted by the tree (${emittedData.size} literal, ${dynDataPrefixes.length} templated)`,
    emittedData.size > 30, `only found ${emittedData.size}`);

const deadDataSel = [];
for (const [k, where] of [...dataSelLookups].sort()) {
    if (emittedData.has(k)) continue;
    if (dynDataPrefixes.some((p) => p && k.startsWith(p))) continue;
    deadDataSel.push([k, where]);
}
check('every [data-x] attribute selector looked up is emitted somewhere',
    deadDataSel.length === 0,
    deadDataSel.map(([k, w]) => `[data-${k}] <- ${w.join(', ')}`).join('\n        '));

/* ==========================================================================
 * Axis 2 — .dataset.someKey reads
 * ======================================================================== */
console.log('\nAxis 2 — .dataset.someKey reads are emitted somewhere (kebab-folded):\n');

/* One-sided, self-cleaning allowlist for a dataset READ with a deliberate
   ABSENT default — mirrors ONE_SIDED_ALLOWLIST in test-event-names.js.
   `noInterstitial` (external-link-interstitial.js) is an opt-out escape
   hatch: `anchor.dataset.noInterstitial !== undefined` is checking that the
   attribute is ABSENT by default, and nothing in this tree needs to emit
   `data-no-interstitial` for the feature to work correctly today. If a
   future page starts emitting it, this entry MUST be deleted (the
   self-cleaning check below enforces that — an allowlist nobody is forced
   to prune silently becomes a list of permanently-broken things). */
const DATASET_ONE_SIDED_ALLOWLIST = [
    { kebab: 'no-interstitial', file: 'modules/external-link-interstitial.js' },
];
const datasetAllowHit = DATASET_ONE_SIDED_ALLOWLIST.map(() => 0);

const datasetReads = new Map(); /* camelKey -> ["file:line", ...] */
for (const s of jsSources) {
    const stripped = stripJsComments(s.text);
    stripped.split('\n').forEach((line, i) => {
        const loc = `${s.file}:${s.lineOffset + i + 1}`;
        for (const m of line.matchAll(/\.dataset\.([A-Za-z_$][\w$]*)/g)) {
            /* Exclude a WRITE — `.dataset.foo = x` (but not `==`/`===`). */
            const rest = line.slice(m.index + m[0].length).trimStart();
            if (/^=(?!=)/.test(rest)) continue;
            if (!datasetReads.has(m[1])) datasetReads.set(m[1], []);
            datasetReads.get(m[1]).push(loc);
        }
    });
}

check(`scanner found .dataset reads to check (${datasetReads.size} distinct)`,
    datasetReads.size > 30, `only found ${datasetReads.size}`);

const deadDataset = [];
for (const [key, where] of [...datasetReads].sort()) {
    const kebab = key.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());
    if (emittedData.has(kebab)) continue;
    if (dynDataPrefixes.some((p) => p && kebab.startsWith(p))) continue;

    const allowIdx = DATASET_ONE_SIDED_ALLOWLIST.findIndex(
        (a) => a.kebab === kebab && where.some((w) => w.startsWith(a.file) || w.includes(`/${a.file}`))
    );
    if (allowIdx !== -1) { datasetAllowHit[allowIdx]++; continue; }

    deadDataset.push([key, kebab, where]);
}
check('every .dataset read (outside the one-sided allowlist) has a matching data-* emission',
    deadDataset.length === 0,
    deadDataset.map(([key, kebab, w]) => `dataset.${key} (data-${kebab}) <- ${w.join(', ')}`).join('\n        '));

DATASET_ONE_SIDED_ALLOWLIST.forEach((entry, i) => {
    check(`dataset one-sided allowlist entry stays live: ${entry.file} :: dataset.${entry.kebab.replace(/-([a-z])/g, (_, c) => c.toUpperCase())}`,
        datasetAllowHit[i] > 0,
        datasetAllowHit[i] === 0 ? 'zero matching reads found — remove this stale allowlist entry' : '');
});

/* ==========================================================================
 * Axis 3 — single-class selectors (.x, whole-selector only)
 * ======================================================================== */
console.log('\nAxis 3 — single-class selector lookups are emitted somewhere:\n');

/* Bootstrap creates a handful of classes at RUNTIME that this codebase
   legitimately looks up but never has to emit itself — count-exact and
   self-cleaning: an entry that stops being looked up (or starts being
   emitted, making the allowance redundant) must be removed, same discipline
   as every other allowlist in this suite. Measured against this tree:
   `.offcanvas-backdrop`/`.tooltip`/`.popover` are never actually reached via
   a single-class `querySelector`/`closest` call today (Bootstrap's own JS
   manages those, not ours), so they are NOT listed — only entries this
   guard's scan actually needs belong here (plan §1(c): "only the ones
   actually looked up"). If a future change adds a lookup for one, add it
   back then. */
const BOOTSTRAP_RUNTIME_CLASSES = new Set(['modal-backdrop']);
const bootstrapAllowHit = new Map([...BOOTSTRAP_RUNTIME_CLASSES].map((c) => [c, 0]));

const classLookups = new Map(); /* class -> ["file:line", ...] */
for (const s of jsSources) {
    const stripped = stripJsComments(s.text);
    stripped.split('\n').forEach((line, i) => {
        const loc = `${s.file}:${s.lineOffset + i + 1}`;
        for (const m of line.matchAll(/(?:querySelector(?:All)?|closest)\(\s*['"`]\.([A-Za-z][\w-]*)['"`]\s*\)/g)) {
            if (!classLookups.has(m[1])) classLookups.set(m[1], []);
            classLookups.get(m[1]).push(loc);
        }
    });
}

check(`scanner found single-class selector lookups to check (${classLookups.size} distinct)`,
    classLookups.size > 100, `only found ${classLookups.size}`);
check(`scanner found classes emitted by the tree (${emittedClasses.size} literal, ${dynClassPrefixes.length} templated)`,
    emittedClasses.size > 100, `only found ${emittedClasses.size}`);

const deadClasses = [];
for (const [k, where] of [...classLookups].sort()) {
    if (emittedClasses.has(k)) continue;
    if (dynClassPrefixes.some((p) => p && k.startsWith(p))) continue;
    if (BOOTSTRAP_RUNTIME_CLASSES.has(k)) { bootstrapAllowHit.set(k, bootstrapAllowHit.get(k) + 1); continue; }
    if (classHasNonLookupLiteral(k)) continue; /* last-resort tier — see file header */
    deadClasses.push([k, where]);
}
check('every single-class selector looked up is emitted somewhere (directly, via a dynamic prefix, the Bootstrap-runtime allowlist, or the last-resort literal tier)',
    deadClasses.length === 0,
    deadClasses.map(([k, w]) => `.${k} <- ${w.join(', ')}`).join('\n        '));

for (const [cls, hits] of bootstrapAllowHit) {
    check(`Bootstrap-runtime class allowlist entry stays live: .${cls}`,
        hits > 0, hits === 0 ? 'zero matching lookups found — remove this stale allowlist entry' : '');
}

/* ==========================================================================
 * Summary
 * ======================================================================== */
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nA [data-x] selector, dataset key, or class nothing emits makes every guarded');
    console.error('handler a silent no-op — same failure shape as a dead #id (see the sibling');
    console.error('test-dom-target-integrity.js). Emit it, stop looking it up, or — if it is a');
    console.error('deliberate opt-out attribute with no emitter yet — add a one-sided allowlist');
    console.error('entry with a comment explaining why.');
    process.exit(1);
}
console.log('\nAll attribute/class/dataset wiring integrity assertions passed.');
