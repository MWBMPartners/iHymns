/**
 * iHymns — admin page window.iHymnsX load-closure wiring (silent-wiring sweep, epic #2008)
 *
 * ELI5
 * ----
 * A shared admin script defines something like `window.iHymnsToast` for
 * every page that loads `toast.js`. If a NEW admin page pastes the inline
 * snippet that calls `window.iHymnsToast.show(...)` but forgets to load
 * `toast.js` itself (or forgets to require the shared partial that loads
 * it), `window.iHymnsToast` is `undefined` on that page. The call throws —
 * or, if it's guarded (`if (window.iHymnsToast) {...}`), it silently does
 * nothing. This walks every `manage/**` page, follows everything it
 * actually loads (its own `<script src>` tags, every shared partial it
 * `require`s and THEIR `<script src>` tags, and the ES-import closure of
 * all of that), and checks that every `window.iHymnsX` the page's inline
 * scripts read is genuinely reachable.
 *
 * WHY THIS EXISTS — the admin-side twin of #1031
 * ------------------------------------------------------------------------
 * #1031 (rule #31) killed the deleted `window.fetch` override precisely
 * because "a patch only applies if something installed it" — the language
 * filter was wired into the router on some pages and not others, so a cold
 * load of an unwired page silently skipped it. This is the same failure
 * shape on the admin side: a `window.iHymnsX` global only exists on a page
 * that actually loaded the script defining it, and nothing before this
 * guard checked that the two stay matched as pages are added or edited.
 *
 * THE RESOLVER IS THE WORK
 * -------------------------
 * A first-pass prototype (`scan6-globals.mjs` in this sweep's scratch
 * analysis) found 17 candidate violations, and EVERY ONE was a false
 * positive caused by one of five resolver gaps — each is fixed here and
 * left documented so it is never reintroduced:
 *
 *  1. PHP requires built with `DIRECTORY_SEPARATOR` concatenation
 *     (`require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php';`,
 *     and `dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . …` for a partial
 *     reaching further up the tree) must resolve to a real file, not just
 *     a literal `require 'x.php'` — `admin-footer.php` is required exactly
 *     this way and it's what defines `window.iHymnsToast`.
 *  2. `head-libs.php` loads `external-link-detect.js`,
 *     `external-links-editor.js`, `combobox-a11y.js` as plain
 *     `<script src="/js/modules/…">` tags — the include chain must follow
 *     THROUGH a required partial to find these, not stop at the page's own
 *     `<script>` tags.
 *  3. Definer shapes beyond `window.X = api` — the IIFE pattern
 *     (`(function (global) { … global.iHymnsFormatExport = api; })(window)`,
 *     `format-export.js`) and the idempotent-init pattern
 *     (`window.X = window.X || …`) both define the global just as much as
 *     a bare assignment.
 *  4. REGEX WORD-BOUNDARY TRAP: `window\.(iHymns\w+)(?!\s*=[^=])` — with no
 *     `\b` — BACKTRACKS. On `window.iHymnsActivityLog = …` (an assignment,
 *     which the negative lookahead should exclude), the engine can match
 *     the SHORTER capture `iHymnsActivityLo` (one character short) at a
 *     position where the lookahead — checking for `g = …`, not `= …` — is
 *     satisfied, and report a phantom READ of a name that doesn't exist.
 *     Fixed by anchoring the capture group itself with `\b` immediately
 *     after it: `window\.(iHymns\w+)\b(?!\s*=[^=])`.
 *  5. Underscore data-islands — `window._iHymnsLinkTypes = <?= json_encode(…) ?>`
 *     — are a SEPARATE, page-local, PHP-to-JS data contract, not a shared
 *     module global. Scoping every regex to `window\.iHymns[A-Z]` (capital
 *     letter immediately after the namespace, no underscore) keeps these
 *     from drowning the real signal.
 *  6. PHP-INTERPOLATED `>` INSIDE A SCRIPT TAG'S OWN ATTRIBUTES (found
 *     during THIS guard's own build, past the plan's original five-item
 *     list — see `blankPhpBlocksForTagScan()`'s doc-comment for the full
 *     account). `<script src="/js/modules/toast.js?v=<?= filemtime(…) ?>">`
 *     — the standard cache-busting pattern EVERY script tag in this tree
 *     uses — contains a literal `>` inside its own `<?= … ?>`, which the
 *     universal `<script\b([^>]*)>` shape (used by every sibling guard in
 *     this sweep) cannot tell apart from the tag's real closing `>`. It hid
 *     `window.iHymnsToast`'s definer from every single admin page in this
 *     guard's first draft. Fixed by blanking `<?…?>` blocks to same-length
 *     whitespace before the tag-matching pass only (never the separate
 *     require-following pass, which needs real `<?php require … ?>` text
 *     intact).
 *
 *  7. PARTIAL-RESOLVES-TO-INCLUDER (mirrors the same-named suppression in
 *     the sibling `tests/php/test-manage-link-params.php`). A definer can
 *     live directly in a `.php` file's own inline `<script>`, not only in a
 *     `.js` file — `manage/includes/admin-theme-init.php` defines
 *     `window.iHymnsAdminApplyTheme` this way. `manage/includes/admin-nav.php`
 *     READS that global but never itself requires `admin-theme-init.php` (or
 *     `head-libs.php`, which does) — it only resolves because every PAGE
 *     that includes admin-nav.php ALSO independently requires head-libs.php
 *     as a SIBLING require. A partial's own isolated require-closure is
 *     therefore the wrong thing to check its reads against; the union of
 *     every page that includes it is right (`includersIndex()`).
 *
 * Measured baseline after all seven fixes: 0 violations. This guard is
 * entirely PREVENTIVE — it exists to catch the NEXT page that pastes a
 * snippet without its script tag, not to remediate an existing bug.
 *
 *   node tests/test-admin-global-wiring.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve, normalize } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUB = join(__dirname, '..', 'appWeb', 'public_html');

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

const SCRIPT_TAG_RE = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;

/** HTML `<!-- -->` + block `/* *\/` comments blanked, newline-preserving. */
function stripHtmlAndBlockComments(src) {
    src = src.replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
    src = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    return src;
}

/**
 * RESOLVER REQUIREMENT 6 (found and fixed during this guard's own build,
 * not in the original five-item list the plan handed off — recorded here
 * for the same reason the other five are recorded: so it is never
 * rediscovered blind).
 *
 * `<script\b([^>]*)>` — the standard "match a tag's own attributes" shape
 * used by every sibling guard in this sweep — silently breaks on this
 * codebase's own cache-busting convention: `<script
 * src="/js/modules/toast.js?v=<?= filemtime($_toastModulePath) ?>">`. The
 * `<?= … ?>` PHP echo tag contains a literal `>` (the one that CLOSES the
 * PHP tag), and `[^>]*` has no way to tell "a `>` that closes the PHP
 * interpolation" from "a `>` that closes the `<script>` tag itself" — it
 * stops at the FIRST one it meets, truncating the attrs capture mid-way
 * through the `src=` value, before its closing quote. The consequence
 * compounds: `/\bsrc\s*=\s*["']([^"']+)["']/` then finds no matching pair
 * of quotes in the truncated attrs and returns no match at all, so a
 * `<script src="…">` tag with this pattern is silently treated as though
 * it had no `src` — worse, the OUTER regex's own "tag" match ends at that
 * same premature `>`, so its "body" (`sm[2]`) then swallows everything
 * up to the NEXT literal `</script>` in the file, which in the worst case
 * can span past a later, genuinely separate `<script>` block and hide it
 * from the scan entirely.
 *
 * Every `<script src="…?v=<?= …?>…">` tag in this tree uses exactly this
 * pattern (`manage/includes/head-libs.php`, `admin-footer.php`,
 * `manage/editor/index.php`, …), so this is not a hypothetical — it hid
 * `window.iHymnsToast`'s definer (`toast.js`, loaded by `admin-footer.php`)
 * from every single admin page during this guard's own first draft.
 *
 * Fix: blank every `<?…?>` PHP block to same-length whitespace (newline-
 * preserving, so offsets/line numbers computed against the result stay
 * true) BEFORE running `SCRIPT_TAG_RE` against it. The static
 * `/js/modules/toast.js` prefix survives (it comes before the `<?=`); only
 * the dynamic cache-buster suffix is blanked, which `resolveScriptSrc()`
 * strips anyway (everything from the first `?` onward). Used ONLY for the
 * tag-matching pass — the separate `require`-following pass still scans
 * the UNBLANKED text, since a real `<?php require … ?>` block would
 * otherwise have its own require statement blanked away.
 */
function blankPhpBlocksForTagScan(src) {
    return src.replace(/<\?(?:php\b|=)?[\s\S]*?\?>/g, (m) => m.replace(/[^\n]/g, ' '));
}

/* ==========================================================================
 * PART 1 — DEFINERS: global name -> Set of files (rel to PUB) that define it
 * ======================================================================== */

/**
 * Every `window.iHymnsX = …` / `global.iHymnsX = …` (the IIFE pattern,
 * `global` bound to `window` by the closing `(window)`) — resolver
 * requirement 3. `window.X = window.X || …` is already covered by the
 * plain-assignment shape (it IS `window.X =` followed by something).
 * `\b` after the capture is resolver requirement 4's fix, applied on the
 * definer side too even though the negative-lookahead backtrack the plan
 * describes is a READ-side symptom — consistency avoids a second class of
 * off-by-one bug on this side.
 */
const DEFINER_RE = /\b(?:window|global)\.(iHymns[A-Z]\w*)\s*=(?!=)/g;

const definers = new Map(); /* global name -> Set<relPath> */
const jsFiles = walk(PUB, /\.js$/);
for (const f of jsFiles) {
    const src = readFileSync(f, 'utf8');
    DEFINER_RE.lastIndex = 0;
    let m;
    while ((m = DEFINER_RE.exec(src)) !== null) {
        const name = m[1];
        if (!definers.has(name)) definers.set(name, new Set());
        definers.get(name).add(rel(f));
    }
}
/* A definer can also live directly in a `.php` file's own inline <script>
   — `manage/includes/admin-theme-init.php` defines `window.iHymnsAdminApplyTheme`
   this way (a synchronous pre-CSS theme resolver, rule #16), never as a
   standalone .js file at all. Scanning the whole (comment-stripped) PHP
   text is sufficient — `window.X =` cannot appear as executable PHP, only
   inside a <script> block, so no attrs-parsing is needed here. */
const phpFilesForDefiners = walk(PUB, /\.php$/);
for (const f of phpFilesForDefiners) {
    const src = stripHtmlAndBlockComments(readFileSync(f, 'utf8'));
    DEFINER_RE.lastIndex = 0;
    let m;
    while ((m = DEFINER_RE.exec(src)) !== null) {
        const name = m[1];
        if (!definers.has(name)) definers.set(name, new Set());
        definers.get(name).add(rel(f));
    }
}
check(`scanner found window.iHymnsX definers (${definers.size} distinct globals across ${jsFiles.length} JS + ${phpFilesForDefiners.length} PHP files)`,
    definers.size >= 5, `only found ${definers.size}`);

/* ==========================================================================
 * PART 2 — IMPORT CLOSURE: the transitive ES-import graph of a JS file
 * ======================================================================== */

const importClosureCache = new Map();
function importClosure(jsAbs) {
    if (importClosureCache.has(jsAbs)) return importClosureCache.get(jsAbs);
    const seen = new Set();
    const stack = [jsAbs];
    while (stack.length) {
        const cur = stack.pop();
        if (seen.has(cur) || !existsSync(cur)) continue;
        seen.add(cur);
        const src = readFileSync(cur, 'utf8');
        for (const m of src.matchAll(/import\s*(?:[\w{},*\s]+from\s*)?['"]([^'"]+)['"]/g)) {
            if (!m[1].startsWith('.')) continue;
            stack.push(resolve(dirname(cur), m[1]));
        }
        for (const m of src.matchAll(/import\(\s*['"]([^'"]+)['"]\s*\)/g)) {
            if (!m[1].startsWith('.')) continue;
            stack.push(resolve(dirname(cur), m[1]));
        }
    }
    importClosureCache.set(jsAbs, seen);
    return seen;
}

/* ==========================================================================
 * PART 3 — PHP REQUIRE RESOLUTION (resolver requirement 1)
 * ======================================================================== */

/**
 * Resolve a `require`/`include`(_once) EXPRESSION (the raw text between the
 * keyword and the terminating `;`) to an absolute file path, handling this
 * codebase's two real shapes:
 *   - `__DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'x.php'`
 *   - `dirname(__DIR__, N) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'x.php'`
 * by walking N `dirname()` calls up from the requiring file's own directory
 * for the base, then joining every remaining quoted string-literal segment
 * (the `DIRECTORY_SEPARATOR .`/`.` concatenation operators are path joins).
 * Falls back to "same directory as the requiring file" for anything else
 * (a plain `require 'x.php'`), which covers every remaining real shape in
 * this tree.
 */
function resolveRequireExpr(exprText, requiringFileAbs) {
    let base = dirname(requiringFileAbs);
    const dirnameMatch = /dirname\(\s*__DIR__\s*(?:,\s*(\d+))?\s*\)/.exec(exprText);
    if (dirnameMatch) {
        const levels = dirnameMatch[1] ? parseInt(dirnameMatch[1], 10) : 1;
        for (let i = 0; i < levels; i++) base = dirname(base);
    }
    const literals = [...exprText.matchAll(/['"]([^'"]+)['"]/g)].map((m) => m[1]);
    if (literals.length === 0) return null;
    const relPath = literals.join('/');
    return normalize(join(base, relPath));
}

/* ==========================================================================
 * PART 4 — PAGE SCRIPT CLOSURE: every JS file a .php page actually loads
 * ======================================================================== */

/**
 * Absolute-path `<script src="/js/…">` -> the PUB-relative JS file, or null
 * (external CDN URLs, e.g. the Swagger UI `<?= $swagger['js_cdn'] ?>`
 * interpolation, resolve to null and are correctly ignored — they cannot
 * define an `iHymns*` global).
 */
function resolveScriptSrc(srcAttr, requestingFileAbs) {
    const clean = srcAttr.replace(/\?.*$/, ''); /* strip ?v=cache-buster */
    /* Root-absolute (`/js/modules/toast.js`) — the common case. */
    if (clean.startsWith('/')) {
        const abs = join(PUB, clean);
        return existsSync(abs) ? abs : null;
    }
    /* Relative (`format-export.js`, `propresenter-export.js`) — resolves
       against the REQUESTING PAGE's own directory, exactly like a browser
       resolves a relative src against the document's URL. Real instances:
       manage/editor/index.php and editor2.php both load their two exporter
       modules this way. External CDN URLs (`https://…`) also fall through
       here and correctly resolve to nothing. */
    if (!/^[a-z][a-z0-9+.-]*:/i.test(clean) && requestingFileAbs) {
        const abs = normalize(join(dirname(requestingFileAbs), clean));
        return existsSync(abs) ? abs : null;
    }
    return null;
}

const pageScriptsCache = new Map();
/**
 * Everything reachable from a `.php` file: `{ scripts, requiredPhp }` —
 * `scripts` is every JS file (absolute paths) via `<script src>` tags (own
 * markup + import closures) plus every `require`d partial's SAME
 * (recursively — resolver requirement 2, so a page that requires
 * head-libs.php reaches the `<script src>` tags head-libs.php itself
 * writes) plus inline `<script type="module">` import specifiers' closures;
 * `requiredPhp` is every `.php` file transitively `require`d — needed
 * because a shared PHP partial can define a `window.iHymnsX` global
 * directly in its OWN inline `<script>` (`admin-theme-init.php` and
 * `window.iHymnsAdminApplyTheme` is the real instance — a synchronous
 * pre-CSS theme-resolver script, rule #16), not only via a `.js` file.
 * `requiredPhp` is simply the shared `visitedPhp` Set every recursive call
 * mutates in place, so it already IS the full transitive closure by the
 * time the top-level call returns.
 */
function pageScripts(phpAbs, depth = 0, visitedPhp = new Set()) {
    const cacheKey = phpAbs;
    if (depth === 0 && pageScriptsCache.has(cacheKey)) return pageScriptsCache.get(cacheKey);
    if (!existsSync(phpAbs) || visitedPhp.has(phpAbs) || depth > 6) return { scripts: new Set(), requiredPhp: visitedPhp };
    visitedPhp.add(phpAbs);

    const raw = readFileSync(phpAbs, 'utf8');
    const cleaned = stripHtmlAndBlockComments(raw);
    /* Resolver requirement 6 — see blankPhpBlocksForTagScan()'s doc-comment.
       Used ONLY for the tag-matching pass below; the require-following pass
       further down still scans the unblanked `cleaned`. */
    const forTags = blankPhpBlocksForTagScan(cleaned);
    const scripts = new Set();

    /* <script src="…"> tags (own file's markup) + inline <script
       type="module"> import specifiers (their closure). */
    SCRIPT_TAG_RE.lastIndex = 0;
    let sm;
    while ((sm = SCRIPT_TAG_RE.exec(forTags)) !== null) {
        const attrs = sm[1];
        const srcMatch = /\bsrc\s*=\s*["']([^"']+)["']/.exec(attrs);
        if (srcMatch) {
            const abs = resolveScriptSrc(srcMatch[1], phpAbs);
            if (abs) { scripts.add(abs); for (const c of importClosure(abs)) scripts.add(c); }
            continue;
        }
        if (/type\s*=\s*["']application\/(ld\+)?json["']/.test(attrs)) continue;
        if (/type\s*=\s*["']module["']/.test(attrs)) {
            const body = sm[2];
            for (const m of body.matchAll(/import\s*(?:[\w{},*\s]+from\s*)?['"]([^'"]+)['"]/g)) {
                if (!m[1].startsWith('.')) continue;
                const abs = resolve(dirname(phpAbs), m[1]);
                if (existsSync(abs)) { scripts.add(abs); for (const c of importClosure(abs)) scripts.add(c); }
            }
        }
    }

    /* Follow require/require_once/include/include_once — resolver
       requirement 1+2: this is what reaches admin-footer.php (toast.js),
       head-libs.php (external-link-detect.js et al.), admin-nav.php,
       head-favicon.php, and any page-specific partial. */
    for (const m of cleaned.matchAll(/(?:require|include)(?:_once)?\s*\(?([^;]+?)\)?\s*;/g)) {
        const abs = resolveRequireExpr(m[1], phpAbs);
        if (abs && existsSync(abs) && abs.endsWith('.php')) {
            for (const s of pageScripts(abs, depth + 1, visitedPhp).scripts) scripts.add(s);
        }
    }

    const result = { scripts, requiredPhp: visitedPhp };
    if (depth === 0) pageScriptsCache.set(cacheKey, result);
    return result;
}

/* ==========================================================================
 * PART 5 — PER-PAGE READS + THE CHECK
 * ======================================================================== */

/**
 * `window.iHymnsX` READ, not a write. `\b` immediately after the capture
 * group is resolver requirement 4's fix — see the file header for the
 * backtracking bug this closes (a plain `(?!\s*=[^=])` with no `\b` can
 * match one character short of a real assignment and report a phantom
 * read). Scoped to `iHymns[A-Z]` (resolver requirement 5) so the
 * page-local `window._iHymnsLinkTypes` data-island contract never enters
 * this scan at all.
 */
const READ_RE = /\bwindow\.(iHymns[A-Z]\w*)\b(?!\s*=[^=])/g;
const DEFINE_RE_INLINE = /\bwindow\.(iHymns[A-Z]\w*)\s*=(?!=)/g;

const managePhpFiles = walk(join(PUB, 'manage'), /\.php$/);

/**
 * PARTIAL-RESOLVES-TO-INCLUDER (mirrors the same-named suppression in the
 * sibling `tests/php/test-manage-link-params.php`, epic #2008). A read
 * inside `manage/includes/admin-nav.php` for `window.iHymnsAdminApplyTheme`
 * only resolves because the PAGE that includes admin-nav.php ALSO
 * independently requires `head-libs.php` (which requires
 * `admin-theme-init.php`, the actual definer) — admin-nav.php itself never
 * requires either. A partial's own isolated closure is therefore the wrong
 * thing to check; the union of every page that includes it is right.
 *
 * @return array<string,array<int,string>> partial basename => [including page abs paths]
 */
function includersIndex() {
    const idx = new Map();
    const includesDir = join(PUB, 'manage', 'includes');
    for (const f of managePhpFiles) {
        if (f.startsWith(includesDir)) continue; /* partials including partials: not needed today */
        const text = stripHtmlAndBlockComments(readFileSync(f, 'utf8'));
        for (const m of text.matchAll(/['"]([A-Za-z0-9_.\-]+\.php)['"]/g)) {
            const partialPath = join(includesDir, m[1]);
            if (existsSync(partialPath)) {
                if (!idx.has(partialPath)) idx.set(partialPath, []);
                idx.get(partialPath).push(f);
            }
        }
    }
    return idx;
}
const includers = includersIndex();

let pagesWithReads = 0;
const globalsResolved = new Set();
const findings = [];

for (const f of managePhpFiles) {
    const raw = readFileSync(f, 'utf8');
    const cleaned = stripHtmlAndBlockComments(raw);
    /* Resolver requirement 6 (see blankPhpBlocksForTagScan()) — same-length
       so a line offset computed against either string agrees with the
       other. */
    const forTags = blankPhpBlocksForTagScan(cleaned);

    const inlineDefs = new Set();
    const reads = new Map(); /* name -> ["file:line", ...] */

    SCRIPT_TAG_RE.lastIndex = 0;
    let sm;
    while ((sm = SCRIPT_TAG_RE.exec(forTags)) !== null) {
        const attrs = sm[1];
        if (/\bsrc\s*=/.test(attrs)) continue;
        if (/type\s*=\s*["']application\/(ld\+)?json["']/.test(attrs)) continue;
        const body = sm[2];
        const lineOffset = forTags.slice(0, sm.index).split('\n').length - 1;

        DEFINE_RE_INLINE.lastIndex = 0;
        let dm;
        while ((dm = DEFINE_RE_INLINE.exec(body)) !== null) inlineDefs.add(dm[1]);

        body.split('\n').forEach((line, i) => {
            READ_RE.lastIndex = 0;
            let rm;
            while ((rm = READ_RE.exec(line)) !== null) {
                const loc = `${rel(f)}:${lineOffset + i + 1}`;
                if (!reads.has(rm[1])) reads.set(rm[1], []);
                reads.get(rm[1]).push(loc);
            }
        });
    }

    if (reads.size === 0) continue;
    pagesWithReads++;

    const own = pageScripts(f);
    /* Fresh Sets — never mutate the cached result pageScripts() returns for
       `f` or an includer, since that cache entry is shared with any OTHER
       lookup of the same key. */
    const loaded = new Set(own.scripts);
    const requiredPhp = new Set(own.requiredPhp);
    /* Partial-resolves-to-includer — see includersIndex()'s doc-comment.
       Union in every including page's OWN closure too. */
    if (f.startsWith(join(PUB, 'manage', 'includes') + '/')) {
        for (const includerAbs of (includers.get(f) || [])) {
            const inc = pageScripts(includerAbs);
            for (const s of inc.scripts) loaded.add(s);
            for (const p of inc.requiredPhp) requiredPhp.add(p);
        }
    }
    for (const [name, locs] of reads) {
        if (inlineDefs.has(name)) { globalsResolved.add(name); continue; }
        const defFiles = definers.get(name);
        if (!defFiles) {
            findings.push(`UNDEFINED ANYWHERE: window.${name} read at ${locs[0]} (${rel(f)})`);
            continue;
        }
        /* A definer file is reachable either as a loaded JS file (<script
           src> closure) or, for a PHP-file definer (e.g.
           admin-theme-init.php's inline <script>), as a require'd partial
           in the page's own require closure. */
        const ok = [...defFiles].some((d) => {
            const abs = join(PUB, d);
            return loaded.has(abs) || requiredPhp.has(abs);
        });
        if (!ok) {
            findings.push(`NOT LOADED ON PAGE: window.${name} read at ${locs[0]} — defined in `
                + `${[...defFiles].join(', ')}; page loads ${loaded.size} script(s) + `
                + `${requiredPhp.size} required PHP file(s) but none of those`);
        } else {
            globalsResolved.add(name);
        }
    }
}

check(`scanner found admin pages reading a window.iHymnsX global (${pagesWithReads} pages, >= 10)`,
    pagesWithReads >= 10, `only found ${pagesWithReads}`);
check(`scanner resolved distinct globals successfully (${globalsResolved.size} distinct, >= 5)`,
    globalsResolved.size >= 5, `only resolved ${globalsResolved.size}`);

check('every window.iHymnsX read by an admin page is defined by that page\'s own inline script '
    + 'or a script in its loaded closure',
    findings.length === 0,
    findings.join('\n        '));

/* ======================================================================
 * Summary
 * ==================================================================== */
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nA window.iHymnsX global nothing on this page defines (or nothing THIS PAGE');
    console.error('LOADS defines) is undefined at read time — the guarded call site silently');
    console.error('no-ops. Load the script that defines it (directly, or via a shared partial),');
    console.error('or define it inline on this page.');
    process.exit(1);
}
console.log('\nAll admin global-wiring assertions passed.');
