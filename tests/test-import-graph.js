/**
 * tests/test-import-graph.js — static ES-module import-graph guard
 *
 * ELI5: every JS file in the web app's `js/` tree is a Lego brick that
 * says "I need pieces from THIS other brick" (an `import`). This test
 * reads every brick's instructions WITHOUT running any of them — it just
 * checks two things a browser would only discover by actually loading the
 * page: (1) does the file the instructions point at really exist where
 * they say it does, and (2) does that file actually hand out the specific
 * piece being asked for (a named export)? Get either wrong and the page
 * doesn't crash loudly — it silently fails to boot the one feature that
 * import feeds, exactly the "console-only violation, no exception, no
 * toast" failure class CLAUDE.md rule #30 describes for a different
 * mechanism. `node --check` only parses ONE file in isolation and never
 * follows an import path, and ESLint's `no-unused-vars`/`import/no-*`
 * rules aren't enabled in this project's `.eslintrc` — so a typo'd
 * relative path or a renamed export currently has ZERO static coverage
 * and is caught only by a human clicking the broken feature in a browser.
 *
 * WHY THIS EXISTS
 * ----------------
 * Concretely, three real ways this codebase has broken before, all
 * invisible to `npm run lint` and `npm run test:js`:
 *   - A relative specifier with a typo'd path segment or missing `.js`.
 *   - A named import (`import { foo } from './x.js'`) where `x.js` was
 *     refactored and `foo` was renamed, removed, or never exported.
 *   - A file moved without updating every other file's `../` count.
 * None of these throw a SyntaxError — ES modules resolve imports at
 * evaluation time, in the browser, and only the FIRST page that happens
 * to exercise that particular `import` statement discovers the 404 or
 * the `undefined is not a function`. https://developer.mozilla.org/docs/Web/JavaScript/Guide/Modules#module_specifiers
 *
 * WHAT IS ACTUALLY CHECKED
 * -------------------------
 *   1. PATH RESOLUTION (fails the build): every relative specifier
 *      (`import`, `export ... from`, bare `import 'x'`, and a
 *      string-literal-argument dynamic `import('x')`) must resolve to a
 *      real file on disk, applying the same `.js`-then-`/index.js`
 *      fallback a bundler would.
 *   2. NAMED-EXPORT EXISTENCE (fails the build, conservatively): for a
 *      static `import Default, { a, b as c } from './x.js'`, if `x.js`
 *      resolves AND its own export list was parsed with full confidence,
 *      every named/default binding requested must actually be exported —
 *      following `export { a } from './y.js'` and `export * from './y.js'`
 *      re-export chains. A module whose export shape this parser cannot
 *      confidently classify (see OPAQUE below) is skipped entirely for
 *      this check rather than risk a false failure — rule #34: "a guard
 *      that fails on correct code gets weakened or deleted rather than
 *      fixed", and this spec explicitly prioritises zero false positives
 *      over completeness here.
 *   3. NON-VACUITY: asserts the scan actually walked a realistic number
 *      of files and import statements, so a broken glob/path fails loudly
 *      instead of silently "passing" over nothing (the same failure shape
 *      `tools/run-node-tests.js`'s own empty-directory guard exists for).
 *
 * SCOPE: `appWeb/public_html/js/**\/*.js` (recursive, DERIVED from the
 * tree via readdir per rule #34 — never a hand-typed file list) plus
 * `appWeb/public_html/service-worker*.js` if any such file exists (none
 * do today — the real file is `service-worker.js.php`, a `.php` file
 * that is not itself a JS module and is correctly excluded).
 *
 * COMMENT/STRING HANDLING: a hand-rolled character-walking stripper
 * (`stripComments`) removes `//` and `/* *\/` comments before any regex
 * runs, tracking `'…'`/`"…"`/`` `…` `` string state so a specifier or
 * keyword INSIDE a string or comment is never mistaken for a real
 * statement — this is what stops a doc-comment like
 * `/** import { x } from './nowhere.js' *\/` from producing a phantom
 * finding. Per this task's own spec: imports/exports in this codebase
 * sit above any regex-heavy code, so the stripper does not attempt to
 * distinguish `/` division from a `/regex/` literal — verified safe
 * against the two `\/\/`-bearing regex literals that exist in this tree
 * today (`modules/error-monitor.js` lines 114 and 409): one is followed
 * by a non-`/` character (no effect), the other causes a same-line-only
 * false "comment" on a local, non-exported `const` — bounded, harmless,
 * and does not touch either the import or export scan.
 *
 * OPAQUE MODULES: a module's export set is built by locating every
 * top-level `export` statement and classifying it against a closed set
 * of known shapes (`function`/`class`, single-name `const`/`let`/`var`,
 * `export default`, `export { a, b as c }` with or without `from`, and
 * `export * [as ns] from`). A `const` line ending in `=` is additionally
 * scanned forward (bracket/string-depth aware) for a top-level comma
 * before its terminating `;`, because `export const a = 1, b = 2;` would
 * otherwise silently capture only `a`. ANY export statement that doesn't
 * match a known shape (e.g. `export const { a, b } = f();`) marks the
 * WHOLE module OPAQUE, and every import that targets it skips the
 * named-export check — under-reporting a real mismatch is an acceptable
 * cost for never reporting a fake one (rule #34 again). As of this
 * writing every one of the 224 top-level `export` statements under
 * `js/` is a recognised shape, so OPAQUE_COUNT below is 0 on the clean
 * tree — see "OPAQUE MODULES FOUND" in the run output.
 *
 * MUTATION PROOF (rule #34 — a guard must be provably able to fail):
 * this suite was verified, by hand, against two live mutations of a real
 * file (`appWeb/public_html/js/modules/print.js`), each applied, run,
 * observed red, then reverted and observed green again:
 *   (a) inserted a new top-level line right after the existing import
 *       (line 21), reading
 *       `import { __definitelyNotExported } from './somewhere-that-does-not-exist.js';`
 *       -> ASSERTION 1 failed, naming
 *       `modules/print.js:22 → './somewhere-that-does-not-exist.js' → unresolved path …`
 *   (b) same insertion point, `import { __definitelyNotExported } from './list-sort.js';`
 *       (a real, resolvable, confidently-parsed module that does not
 *       export that name) -> ASSERTION 2 failed, naming
 *       `modules/print.js:22 → './list-sort.js' → missing named export '__definitelyNotExported' …`
 * In both cases reverting the single inserted line returned the suite to
 * byte-identical pass/fail counts and zero findings. Full transcript of
 * this run is recorded in the PR that introduces this file (matching the
 * precedent set by tests/test-event-names.js's own doc-block, "see the
 * #1581 fail-proof run recorded in the PR").
 *
 * USAGE:
 *   node tests/test-import-graph.js
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const REPO_ROOT     = path.resolve(__dirname, '..');
const PUBLIC_HTML   = path.join(REPO_ROOT, 'appWeb', 'public_html');
const JS_ROOT        = path.join(PUBLIC_HTML, 'js');

/* ------------------------------------------------------------------------
 * File discovery — DERIVED from the tree, never hardcoded (rule #34).
 * ------------------------------------------------------------------------ */
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

const files = collectJsFiles(JS_ROOT);

/* Plus appWeb/public_html/service-worker*.js, if any such FILE (not the
   .js.php the real service worker actually ships as today) exists. */
for (const entry of fs.readdirSync(PUBLIC_HTML, { withFileTypes: true })) {
    if (entry.isFile() && /^service-worker.*\.js$/.test(entry.name)) {
        files.push(path.join(PUBLIC_HTML, entry.name));
    }
}
files.sort();

let passed = 0;
let failed = 0;
const failureDetails = [];

function check(name, ok, detail) {
    if (ok) {
        passed++;
        console.log(`  PASS  ${name}`);
    } else {
        failed++;
        failureDetails.push({ name, detail });
        console.log(`  FAIL  ${name}`);
        if (detail) console.log(detail.split('\n').map(l => `        ${l}`).join('\n'));
    }
}

function relPath(p) {
    return path.relative(JS_ROOT, p).split(path.sep).join('/');
}

/* ==========================================================================
 * 1. Comment stripper — character-walking, string/template-aware.
 *
 * State machine over four states (code / line-comment / block-comment /
 * a string-ish state for ' " `). Every ORIGINAL newline is re-emitted
 * regardless of state, so line numbers computed against the stripped
 * output always match the original file — including for a multi-line
 * block comment, where every swallowed newline is still emitted. Escape
 * sequences (`\'`, `\"`, `` \` ``, `\\`) inside a string/template are
 * copied through as a pair so an escaped quote never ends the string
 * early. See the doc-block above for the two known, harmless edge cases
 * this simple approach has against this specific tree.
 * ========================================================================== */
function stripComments(src) {
    let out = '';
    let state = 'code'; // code | line | block | sq | dq | tpl
    const n = src.length;
    for (let i = 0; i < n; i++) {
        const c = src[i];
        const c2 = i + 1 < n ? src[i + 1] : '';

        if (state === 'code') {
            if (c === '/' && c2 === '/') { state = 'line'; i++; continue; }
            if (c === '/' && c2 === '*') { state = 'block'; i++; continue; }
            if (c === "'") { state = 'sq'; out += c; continue; }
            if (c === '"') { state = 'dq'; out += c; continue; }
            if (c === '`') { state = 'tpl'; out += c; continue; }
            out += c;
            continue;
        }
        if (state === 'line') {
            if (c === '\n') { out += '\n'; state = 'code'; }
            continue; // swallow everything else on the comment line
        }
        if (state === 'block') {
            if (c === '\n') { out += '\n'; continue; }
            if (c === '*' && c2 === '/') { state = 'code'; i++; continue; }
            continue; // swallow comment body
        }
        /* string-ish states: sq / dq / tpl */
        const closeChar = state === 'sq' ? "'" : state === 'dq' ? '"' : '`';
        if (c === '\\') { out += c + c2; i++; continue; } // copy escape pair verbatim
        if (c === closeChar) { state = 'code'; out += c; continue; }
        out += c; // string body — copied through untouched (incl. any // or /* )
    }
    return out;
}

/* Fast "byte index -> 1-based line number" via a precomputed newline-offset
   table + binary search, so per-match line lookups don't re-scan the file. */
function makeLineFinder(src) {
    const offsets = [0];
    for (let i = 0; i < src.length; i++) if (src[i] === '\n') offsets.push(i + 1);
    return (index) => {
        let lo = 0, hi = offsets.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (offsets[mid] <= index) lo = mid; else hi = mid - 1;
        }
        return lo + 1;
    };
}

/* ==========================================================================
 * 2. Path resolution — rule 1 of the spec.
 * ========================================================================== */
function resolveRelativeSpecifier(fromFile, spec) {
    if (!spec.startsWith('.')) return { skip: true }; // bare/absolute — never fail on these
    const raw = path.resolve(path.dirname(fromFile), spec);
    const tried = [];
    const tryFile = (p) => {
        tried.push(p);
        return fs.existsSync(p) && fs.statSync(p).isFile();
    };

    if (path.extname(raw) === '') {
        if (tryFile(raw + '.js')) return { ok: true, path: raw + '.js' };
        if (tryFile(path.join(raw, 'index.js'))) return { ok: true, path: path.join(raw, 'index.js') };
    } else {
        if (tryFile(raw)) return { ok: true, path: raw };
        // extension present but not a file — maybe it's actually a directory name
        if (fs.existsSync(raw) && fs.statSync(raw).isDirectory() && tryFile(path.join(raw, 'index.js'))) {
            return { ok: true, path: path.join(raw, 'index.js') };
        }
    }
    return { ok: false, tried };
}

/* ==========================================================================
 * 3. Export-set parsing per file — rule 2 of the spec.
 * ========================================================================== */
const EXPORT_SITE_RE   = /^[ \t]*export\b/gm;
const DEFAULT_RE       = /export\s+default\b/y;
const FUNC_CLASS_RE    = /export\s+(?:async\s+)?(?:function\s*\*?|class)\s+([A-Za-z_$][A-Za-z0-9_$]*)/y;
const CONST_RE         = /export\s+(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*([=;])/y;
const STAR_AS_RE       = /export\s*\*\s*as\s+([A-Za-z_$][A-Za-z0-9_$]*)\s+from\s*(['"])([^'"]+)\2/y;
const STAR_RE          = /export\s*\*\s*from\s*(['"])([^'"]+)\1/y;
const LIST_RE          = /export\s*\{([^}]*)\}\s*(?:from\s*(['"])([^'"]+)\2)?\s*;?/y;

const NAME_ITEM_RE = /^([A-Za-z_$][A-Za-z0-9_$]*)(?:\s+as\s+([A-Za-z_$][A-Za-z0-9_$]*))?$/;

/* Parses `a, b as c` (the inside of an `export {}` / `import {}` brace
   pair) into [{left, right}, …] where `left` is the name as known to the
   SOURCE module and `right` is the name as known to the CONSUMER — for
   `import { b as c }`, target must export `b` (left); for
   `export { a as b }`, THIS module now exports `b` (right). Returns null
   (opaque signal) if any item doesn't match the plain `name[ as name]`
   shape — no destructuring/defaults are legal here, so anything else is
   parser confusion, not valid JS. */
function parseNameList(text) {
    const items = text.split(',').map(s => s.trim()).filter(s => s.length > 0);
    const out = [];
    for (const item of items) {
        const m = NAME_ITEM_RE.exec(item);
        if (!m) return null;
        out.push({ left: m[1], right: m[2] || m[1] });
    }
    return out;
}

/* Guards against `export const a = 1, b = 2;` being silently read as only
   `{a}` (rule 2's "conservative — no false positives" requirement — under-
   capturing a real export name is exactly the false-FAIL shape this whole
   suite exists to avoid). Walks forward from just after the `=`,
   bracket/string-aware, and reports whether a top-level `,` appears before
   the statement's top-level `;`. Comments are already stripped by the time
   this runs. */
function hasTopLevelCommaBeforeSemicolon(src, fromIndex) {
    let depth = 0;
    let state = 'code'; // code | sq | dq | tpl
    for (let i = fromIndex; i < src.length; i++) {
        const c = src[i];
        if (state !== 'code') {
            const q = state === 'sq' ? "'" : state === 'dq' ? '"' : '`';
            if (c === '\\') { i++; continue; }
            if (c === q) state = 'code';
            continue;
        }
        if (c === "'") { state = 'sq'; continue; }
        if (c === '"') { state = 'dq'; continue; }
        if (c === '`') { state = 'tpl'; continue; }
        if (c === '{' || c === '[' || c === '(') { depth++; continue; }
        if (c === '}' || c === ']' || c === ')') { depth--; continue; }
        if (depth <= 0 && c === ';') return false;
        if (depth <= 0 && c === ',') return true;
    }
    return false; // ran off the end — malformed/unreachable for valid JS; be lenient
}

/* Attempts every known export-statement shape STICKY-anchored at `idx`
   (the index of the literal `export` keyword). Returns:
     null                        — unrecognised shape (caller marks OPAQUE)
     { names: [...], reexport? } — recognised; `names` are THIS module's
                                    newly-exported names, `reexport` (if
                                    present) describes a from-clause needing
                                    both a path check and (for non-wildcard)
                                    cross-module name resolution. */
function tryMatchExportSite(src, idx) {
    DEFAULT_RE.lastIndex = idx;
    let m = DEFAULT_RE.exec(src);
    if (m && m.index === idx) return { names: ['default'] };

    FUNC_CLASS_RE.lastIndex = idx;
    m = FUNC_CLASS_RE.exec(src);
    if (m && m.index === idx) return { names: [m[1]] };

    CONST_RE.lastIndex = idx;
    m = CONST_RE.exec(src);
    if (m && m.index === idx) {
        if (m[2] === '=' && hasTopLevelCommaBeforeSemicolon(src, idx + m[0].length)) {
            return null; // multi-declarator on one line — opaque rather than under-capture
        }
        return { names: [m[1]] };
    }

    STAR_AS_RE.lastIndex = idx;
    m = STAR_AS_RE.exec(src);
    if (m && m.index === idx) {
        return { names: [m[1]], reexport: { kind: 'namespace', spec: m[3] } };
    }

    STAR_RE.lastIndex = idx;
    m = STAR_RE.exec(src);
    if (m && m.index === idx) {
        return { names: [], reexport: { kind: 'wildcard', spec: m[2] } };
    }

    LIST_RE.lastIndex = idx;
    m = LIST_RE.exec(src);
    if (m && m.index === idx) {
        const parsed = parseNameList(m[1]);
        if (parsed === null) return null;
        const names = parsed.map(p => p.right);
        if (m[3] !== undefined) {
            return { names, reexport: { kind: 'named', spec: m[3], required: parsed.map(p => p.left) } };
        }
        return { names };
    }

    return null; // nothing recognised
}

/* ==========================================================================
 * 4. Per-file scan: collect specifiers (all kinds) + this file's own
 *    direct export declarations/reexport directives.
 * ========================================================================== */
const allSpecifiers = []; // { file, line, specifier, kind } — for path-resolution
const staticImports  = []; // { file, line, specifier, clauseText }
const fileRecords    = new Map(); // absPath -> { ownNames: Set, opaqueOwn: bool, reexports: [...] }

const IMPORT_CLAUSE_RE = /^(?:([A-Za-z_$][A-Za-z0-9_$]*)(?:\s*,\s*)?)?(?:\*\s+as\s+([A-Za-z_$][A-Za-z0-9_$]*))?(?:\{([^}]*)\})?$/;

const STATIC_IMPORT_RE = /\bimport\s+([^;]*?)\bfrom\s*(['"])([^'"]+)\2/g;
const BARE_IMPORT_RE   = /\bimport\s*(['"])([^'"]+)\1\s*;/g;
const DYNAMIC_IMPORT_RE = /\bimport\s*\(\s*(['"])([^'"]+)\1\s*\)/g;

for (const file of files) {
    const raw = fs.readFileSync(file, 'utf8');
    const src = stripComments(raw);
    const lineOf = makeLineFinder(src);

    /* --- static `import ... from '...'` --- */
    for (const m of src.matchAll(STATIC_IMPORT_RE)) {
        const specifier = m[3];
        const line = lineOf(m.index);
        allSpecifiers.push({ file, line, specifier, kind: 'import' });
        staticImports.push({ file, line, specifier, clauseText: m[1].trim() });
    }

    /* --- bare side-effect `import '...';` --- */
    for (const m of src.matchAll(BARE_IMPORT_RE)) {
        allSpecifiers.push({ file, line: lineOf(m.index), specifier: m[2], kind: 'bare-import' });
    }

    /* --- dynamic `import('...')`, string-literal arg only --- */
    for (const m of src.matchAll(DYNAMIC_IMPORT_RE)) {
        allSpecifiers.push({ file, line: lineOf(m.index), specifier: m[2], kind: 'dynamic-import' });
    }

    /* --- this file's own export declarations --- */
    const ownNames = new Set();
    const reexports = [];
    let opaqueOwn = false;

    EXPORT_SITE_RE.lastIndex = 0;
    let siteMatch;
    while ((siteMatch = EXPORT_SITE_RE.exec(src)) !== null) {
        const idx = siteMatch.index + (siteMatch[0].length - 'export'.length);
        const result = tryMatchExportSite(src, idx);
        if (result === null) {
            opaqueOwn = true;
            continue;
        }
        for (const n of result.names) ownNames.add(n);
        if (result.reexport) {
            const line = lineOf(idx);
            allSpecifiers.push({ file, line, specifier: result.reexport.spec, kind: 'export-from' });
            reexports.push({ ...result.reexport, line });
        }
    }

    fileRecords.set(file, { ownNames, opaqueOwn, reexports });
}

/* ==========================================================================
 * 5. Path-resolution pass — Assertion 1.
 * ========================================================================== */
console.log('Assertion 1 — every relative import/export specifier resolves to a real file:');

const pathFailures = [];
/* Cache: specifier resolution is deterministic per (file,specifier) pair;
   re-resolving identical pairs across import/bare/dynamic/export-from
   passes is cheap at this repo's size, so no memoisation needed beyond
   simple correctness. Store resolved absolute path alongside successes
   for the named-export pass below. */
const resolvedPathOf = new Map(); // `${file}::${specifier}` -> absPath|null

for (const s of allSpecifiers) {
    if (!s.specifier.startsWith('.')) continue; // bare/absolute — never fail (spec rule 1)
    const key = `${s.file}::${s.specifier}`;
    if (!resolvedPathOf.has(key)) {
        const r = resolveRelativeSpecifier(s.file, s.specifier);
        resolvedPathOf.set(key, r.ok ? r.path : null);
        if (!r.ok) {
            pathFailures.push(
                `${relPath(s.file)}:${s.line} → '${s.specifier}' → unresolved path ` +
                `(tried: ${r.tried.map(t => path.relative(REPO_ROOT, t)).join(', ')})`
            );
        }
    }
}

check(
    'no unresolved relative import/export path',
    pathFailures.length === 0,
    pathFailures.length ? `${pathFailures.length} finding(s):\n` + pathFailures.join('\n') : ''
);

/* ==========================================================================
 * 6. Resolve each file's FULL export set (own + transitive re-exports),
 *    memoised, cycle-safe. Runs after all files are parsed so re-export
 *    chains can look each other up regardless of scan order.
 * ========================================================================== */
const resolvedExportCache = new Map(); // absPath -> { opaque: bool, names: Set }

function resolveExports(absPath, visiting) {
    if (resolvedExportCache.has(absPath)) return resolvedExportCache.get(absPath);
    const rec = fileRecords.get(absPath);
    if (!rec) {
        const result = { opaque: true, names: new Set() }; // unknown file — stay conservative
        resolvedExportCache.set(absPath, result);
        return result;
    }
    if (visiting.has(absPath)) {
        const result = { opaque: true, names: new Set() }; // re-export cycle — bail out safely
        return result;
    }
    visiting.add(absPath);

    let opaque = rec.opaqueOwn;
    const names = new Set(rec.ownNames);

    for (const re of rec.reexports) {
        const key = `${absPath}::${re.spec}`;
        const targetPath = resolvedPathOf.get(key);
        if (!targetPath) continue; // already recorded as a path failure above; nothing more to add here

        if (re.kind === 'namespace') {
            continue; // `export * as ns from` already added `ns` itself to ownNames
        }
        const sub = resolveExports(targetPath, visiting);
        if (re.kind === 'wildcard') {
            if (sub.opaque) { opaque = true; continue; }
            for (const n of sub.names) if (n !== 'default') names.add(n);
        } else if (re.kind === 'named') {
            if (sub.opaque) { opaque = true; continue; }
            for (const req of re.required) {
                if (!sub.names.has(req)) { opaque = true; break; } // can't confidently name-check further
            }
        }
    }

    visiting.delete(absPath);
    const result = { opaque, names };
    resolvedExportCache.set(absPath, result);
    return result;
}

for (const file of files) resolveExports(file, new Set());

const opaqueFiles = files.filter(f => resolveExports(f, new Set()).opaque);
console.log('');
console.log(`OPAQUE MODULES FOUND: ${opaqueFiles.length} (named-export checks skipped against these)`);
if (opaqueFiles.length) {
    for (const f of opaqueFiles) console.log(`    · ${relPath(f)}`);
}

/* ==========================================================================
 * 7. Named-export existence pass — Assertion 2.
 * ========================================================================== */
console.log('');
console.log('Assertion 2 — every named/default static import is actually exported by its target:');

const namedFailures = [];

for (const imp of staticImports) {
    const key = `${imp.file}::${imp.specifier}`;
    const targetPath = resolvedPathOf.get(key);
    if (!targetPath) continue; // unresolved path already reported by Assertion 1

    const clause = IMPORT_CLAUSE_RE.exec(imp.clauseText);
    if (!clause) continue; // unparseable import-clause shape — skip conservatively

    const [, defaultName, , namedListText] = clause;
    const target = resolveExports(targetPath, new Set());
    if (target.opaque) continue; // can't confidently check against an opaque module

    const targetRel = relPath(targetPath);

    if (defaultName && !target.names.has('default')) {
        namedFailures.push(
            `${relPath(imp.file)}:${imp.line} → '${imp.specifier}' → ` +
            `missing default export in ${targetRel} (imported as '${defaultName}')`
        );
    }

    if (namedListText !== undefined) {
        const parsed = parseNameList(namedListText);
        if (parsed === null) continue; // unparseable named-list shape — skip conservatively
        for (const { left } of parsed) {
            if (!target.names.has(left)) {
                namedFailures.push(
                    `${relPath(imp.file)}:${imp.line} → '${imp.specifier}' → ` +
                    `missing named export '${left}' in ${targetRel}`
                );
            }
        }
    }
}

check(
    'no missing named/default export against a confidently-parsed target',
    namedFailures.length === 0,
    namedFailures.length ? `${namedFailures.length} finding(s):\n` + namedFailures.join('\n') : ''
);

/* ==========================================================================
 * 8. Non-vacuity guard — Assertion 3.
 * ========================================================================== */
console.log('');
console.log('Assertion 3 — the scan covered a realistic slice of the tree:');

const totalImportStatements = allSpecifiers.length;
console.log(`  (scanned ${files.length} files, ${totalImportStatements} import/export-from statement(s))`);

check(
    'scanned at least 60 files',
    files.length >= 60,
    `got ${files.length} files under ${path.relative(REPO_ROOT, JS_ROOT)} — the glob may be broken`
);
check(
    'scanned at least 100 import/export-from statements',
    totalImportStatements >= 100,
    `got ${totalImportStatements} — the extraction regexes may be broken`
);

/* ==========================================================================
 * Summary
 * ========================================================================== */
console.log('');
console.log(`${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('');
    console.log('Failures:');
    for (const f of failureDetails) {
        console.log(`  - ${f.name}`);
    }
}
process.exit(failed === 0 ? 0 : 1);
