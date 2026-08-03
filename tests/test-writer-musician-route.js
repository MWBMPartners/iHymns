/**
 * iHymns — /writer/<slug> → /musician/<slug> route consolidation test (#1741 P4a-3)
 *
 * Guards the exact regression rule #33 exists to prevent: a route this app
 * has advertised for YEARS (sitemap emissions, external links,
 * `api.php?page=writer&id=…`) is retired in favour of `/musician/<slug>`,
 * and every leg of that retirement has to keep working at once — the
 * fragment endpoint (`api.php`), the top-level crawler 301 (`index.php`),
 * the client-side address-bar canonicalisation (`router.js`), and the
 * sitemap no longer advertising the retired shape — while the OLD route
 * segment itself (`case 'writer'`, the `id` param, `$_cacheablePages`)
 * must never actually disappear, because links outlive code.
 *
 * TREE-DERIVED, NOT HARDCODED (rule #34): assertion group 2 below does not
 * type out a list of "files that used to emit /writer/ links" — it WALKS
 * the whole appWeb/public_html tree and fails on ANY file matching an
 * emitter shape, so a SEVENTH file that starts building a /writer/ href
 * tomorrow is caught automatically, with no edit to this test. Likewise
 * assertion group 7's second half derives "does another file re-fork the
 * name/slug variant fold" from the tree rather than a typed filename list.
 *
 * We don't spin up PHP or a browser here — the same "read the shipped
 * source, slice named regions, assert on the slice" structural style as
 * test-tag-route.js and test-identifier-routes.js.
 *
 *   node tests/test-writer-musician-route.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/musician_helpers.php  the shared resolver ladder
 * @see appWeb/public_html/includes/pages/musician.php     the fragment writer.php folded into
 * @see appWeb/public_html/api.php                         case 'writer' (fragment leg)
 * @see appWeb/public_html/index.php                       the top-level 301 (crawler leg)
 * @see appWeb/public_html/js/modules/router.js             the address-bar canonicalisation leg
 * @see appWeb/public_html/sitemap.xml.php                  the registry-driven musician emission
 * @see tests/test-tag-route.js                             the case-label-slice technique this reuses
 * @see .claude/catalogue-1741-P4a3-plan.md §4.1             this file's spec
 */

import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative, sep } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT     = join(__dirname, '..');
const WEB_ROOT       = join(REPO_ROOT, 'appWeb', 'public_html');
const MUSICIAN_PHP   = join(WEB_ROOT, 'includes', 'pages', 'musician.php');
const WRITER_PHP_GONE = join(WEB_ROOT, 'includes', 'pages', 'writer.php');
const HELPERS_PHP    = join(WEB_ROOT, 'includes', 'musician_helpers.php');
const ROUTER_JS       = join(WEB_ROOT, 'js', 'modules', 'router.js');
const API_PHP         = join(WEB_ROOT, 'api.php');
const INDEX_PHP       = join(WEB_ROOT, 'index.php');
const SITEMAP_PHP     = join(WEB_ROOT, 'sitemap.xml.php');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* -----------------------------------------------------------------------
 * PHP/JS comment stripper — a small character-level state machine, NOT a
 * regex (the same shape as tests/test-arrangement-a11y.js's stripComments(),
 * extended with PHP's `#` line-comment form). Quoted string content is
 * copied through VERBATIM (unlike test-api-client-usage.js's variant,
 * which blanks strings too) because the code under test here needs its
 * STRING LITERALS ('/musician/', 'musician.php', …) intact — only the
 * comments need to disappear.
 *
 * WHY THIS MATTERS FOR THIS FILE SPECIFICALLY: mutation row 2 in the build
 * spec's §4.3 protocol is "comment out the index.php /writer/ elseif
 * branch" — wrapping the whole branch in a `/* … *\/` block comment. A
 * PLAIN `indexSrc.indexOf("preg_match('#^/writer/")` substring search does
 * not care whether that text is live code or dead text inside a comment,
 * so group 4's checks below run against the COMMENT-STRIPPED source, not
 * the raw file — otherwise this guard would report a false "still there"
 * for a branch that has actually been disabled, the exact
 * "wrong-but-green" failure mode rule #34 warns about.
 * ---------------------------------------------------------------------- */
function stripComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    /** @type {'code'|'block'|'line'|'sq'|'dq'} */
    let state = 'code';

    while (i < n) {
        const c  = src[i];
        const c2 = src[i + 1];

        if (state === 'code') {
            if (c === '/' && c2 === '*') { state = 'block'; i += 2; continue; }
            if (c === '/' && c2 === '/') { state = 'line';  i += 2; continue; }
            if (c === '#') { state = 'line'; i += 1; continue; } /* PHP # line comment */
            if (c === "'") { state = 'sq';  out += c; i += 1; continue; }
            if (c === '"') { state = 'dq';  out += c; i += 1; continue; }
            out += c;
            i += 1;
            continue;
        }

        if (state === 'block') {
            if (c === '*' && c2 === '/') { state = 'code'; i += 2; continue; }
            out += (c === '\n') ? '\n' : '';
            i += 1;
            continue;
        }

        if (state === 'line') {
            if (c === '\n') { state = 'code'; out += '\n'; i += 1; continue; }
            i += 1;
            continue;
        }

        /* sq / dq — quoted content copied through verbatim; an escaped
           quote is consumed as a pair so it can never look like the
           closing delimiter. */
        const closer = state === 'sq' ? "'" : '"';
        if (c === '\\') { out += c + (c2 ?? ''); i += 2; continue; }
        if (c === closer) { state = 'code'; out += c; i += 1; continue; }
        out += c;
        i += 1;
    }

    return out;
}

console.log('Assertion group 0 — comment-stripper self-test (proves the guard below can actually go blind to a comment, not just to real code):');
check('a match inside real code SURVIVES stripping',
    stripComments("preg_match('#^/writer/([^/]+)$#', $x);").includes("preg_match('#^/writer/"));
check('the SAME text wrapped in a /* block */ comment is REMOVED by stripping (the exact row-2 mutation shape)',
    !stripComments("/* elseif (preg_match('#^/writer/([^/]+)$#', $x)) { } */").includes("preg_match('#^/writer/"));
check('a string literal survives stripping untouched (comments strip, strings do not)',
    stripComments("$x = '/musician/';").includes("'/musician/'"));

/* -----------------------------------------------------------------------
 * Recursive tree walk — appWeb/public_html/**\/*.{php,js}, skipping
 * vendor/, data/, node_modules/ (matching the build spec's exclusion
 * list). This is the "list" assertion groups 2 and 7 derive from: no
 * filename is typed anywhere below, so a new file added to the tree is
 * covered automatically, both to CATCH a future regression and to prove
 * (mutation row below) that a re-added emitter is actually found.
 * ---------------------------------------------------------------------- */
function walkSourceFiles(dir, out = []) {
    let entries;
    try { entries = readdirSync(dir); } catch { return out; }
    for (const entry of entries) {
        if (entry === 'vendor' || entry === 'data' || entry === 'node_modules') continue;
        const full = join(dir, entry);
        let st;
        try { st = statSync(full); } catch { continue; }
        if (st.isDirectory()) {
            walkSourceFiles(full, out);
        } else if (/\.(php|js)$/.test(entry)) {
            out.push(full);
        }
    }
    return out;
}

/* ========================================================================
 * Assertion group 1 — the files that must (and must not) exist.
 * ======================================================================== */
console.log('Assertion group 1 — files:');
check('includes/pages/musician.php exists', existsSync(MUSICIAN_PHP));
check('includes/pages/writer.php no longer exists (folded into musician.php — the iswc.php-gone precedent, #1741 P3)',
    !existsSync(WRITER_PHP_GONE));
check('includes/musician_helpers.php exists (the shared legacy-slug ladder)', existsSync(HELPERS_PHP));

if (!existsSync(MUSICIAN_PHP) || !existsSync(HELPERS_PHP)) {
    console.log(`\n${passed} passed, ${failed} failed`);
    console.error('\nCannot continue — a required file is missing.');
    process.exit(1);
}

const musicianSrc = readFileSync(MUSICIAN_PHP, 'utf8');
const helpersSrc  = readFileSync(HELPERS_PHP, 'utf8');
const routerSrc   = readFileSync(ROUTER_JS, 'utf8');
const apiSrc      = readFileSync(API_PHP, 'utf8');
const indexSrc    = readFileSync(INDEX_PHP, 'utf8');
const sitemapSrc  = existsSync(SITEMAP_PHP) ? readFileSync(SITEMAP_PHP, 'utf8') : '';
check('sitemap.xml.php exists', existsSync(SITEMAP_PHP));

/* ========================================================================
 * Assertion group 2 — TREE-DERIVED: no internal /writer/ EMITTER remains
 * anywhere under appWeb/public_html. This is the exact shape that caught
 * sitemap.xml.php:155 pre-fix — a string-built href/loc, not a route
 * HANDLER (case 'writer:', the index.php preg_match branch, and this very
 * test file's own doc-comments/strings all legitimately contain the
 * SUBSTRING "/writer/" without emitting a link from it, so the patterns
 * below are deliberately shaped to match only a CONCATENATION or a literal
 * href/src attribute value, not a bare mention).
 * ======================================================================== */
console.log('\nAssertion group 2 — tree-derived: no /writer/ link EMITTER remains:');
const EMITTER_PATTERNS = [
    { label: 'href="/writer/',      re: /href="\/writer\// },
    { label: "href='/writer/",      re: /href='\/writer\// },
    { label: "'/writer/' .",        re: /'\/writer\/'\s*\./ },
    { label: '"/writer/" .',        re: /"\/writer\/"\s*\./ },
];
const allSourceFiles = walkSourceFiles(WEB_ROOT);
check('the tree walk actually found source files (a truly empty list means the walker itself is broken, not that the tree is empty)',
    allSourceFiles.length > 50,
    `found ${allSourceFiles.length} .php/.js files under appWeb/public_html`);

const emitterHits = [];
for (const file of allSourceFiles) {
    const src = readFileSync(file, 'utf8');
    for (const { label, re } of EMITTER_PATTERNS) {
        if (re.test(src)) {
            emitterHits.push(`${relative(REPO_ROOT, file).split(sep).join('/')} (matches ${label})`);
        }
    }
}
check('no file under appWeb/public_html emits a /writer/ href or string-built /writer/ URL',
    emitterHits.length === 0,
    emitterHits.length ? 'found: ' + emitterHits.join('; ') : '');

/* Mutation self-test for the walk itself (rule #34's "prove the check can
   fail" requirement, done in-memory here rather than only by hand against
   the real tree): the SAME patterns must flag an obviously-bad fixture
   string, so a false "0 hits" above isn't just the regexes being inert. */
{
    const fixtureBad = 'const url = "/writer/" . $slug; return \'<a href="/writer/john">\';';
    const fixtureGood = "case 'writer': /* preg_match('#^/writer/([^/]+)$#', ...) handler, not an emitter */";
    const badHits = EMITTER_PATTERNS.filter(({ re }) => re.test(fixtureBad)).length;
    const goodHits = EMITTER_PATTERNS.filter(({ re }) => re.test(fixtureGood)).length;
    check('mutation self-test — the emitter patterns DO flag an obviously-bad fixture string',
        badHits > 0, `patterns matched ${badHits} time(s) against a fixture that mixes both emitter shapes`);
    check('mutation self-test — the SAME patterns do NOT flag ordinary handler code that merely names the route',
        goodHits === 0, `patterns matched ${goodHits} time(s) against handler-shaped code — the walk would false-positive on every route handler`);
}

/* ========================================================================
 * Assertion group 3 — sitemap.xml.php: registry-driven, no /writer/ left.
 * ======================================================================== */
console.log('\nAssertion group 3 — sitemap.xml.php:');
check("sitemap.xml.php queries FROM tblMusicians (registry-driven, not the old per-credited-name heuristic)",
    /FROM\s+tblMusicians/i.test(sitemapSrc));
check("sitemap.xml.php emits '/musician/' locs", sitemapSrc.includes("'/musician/'"));
check("sitemap.xml.php contains NO '/writer/' substring at all (not even in a comment — the file should read as fully migrated)",
    !sitemapSrc.includes('/writer/'));

/* ========================================================================
 * Assertion group 4 — index.php: the top-level crawler 301 leg.
 * ======================================================================== */
console.log('\nAssertion group 4 — index.php:');
/* Comment-stripped: mutation row 2 (§4.3) disables this whole branch by
   wrapping it in a /* block *\/ comment, which a raw substring search
   would not notice — see the group-0 self-test + this file's header note
   on stripComments() for why the mutated tree must go red here. */
const indexCodeOnly = stripComments(indexSrc);
const writerElseifStart = indexCodeOnly.indexOf("preg_match('#^/writer/");
check("index.php has a LIVE (non-commented-out) elseif branch matching '#^/writer/…' (the top-level crawler-hit leg)",
    writerElseifStart >= 0);
/* Slice from the preg_match to the NEXT elseif/if's own preg_match (or a
   generous bound) — same "slice to next landmark" shape as
   test-tag-route.js's case-label slicing, applied to an if/elseif chain
   instead of a switch. */
const nextBranchStart = writerElseifStart >= 0
    ? indexCodeOnly.indexOf('elseif (preg_match', writerElseifStart + 1)
    : -1;
const writerBranchEnd = nextBranchStart > writerElseifStart ? nextBranchStart : (writerElseifStart >= 0 ? writerElseifStart + 2000 : -1);
const writerBranch = writerElseifStart >= 0
    ? indexCodeOnly.slice(writerElseifStart, writerBranchEnd)
    : '';
check('…and that branch calls musicianResolveLegacySlugDb() (the shared ladder, not a re-forked lookup)',
    /musicianResolveLegacySlugDb\s*\(/.test(writerBranch));
check("…and on a resolve it emits a REAL 301 Location: …/musician/… (', true, 301)",
    /'\/musician\/'/.test(writerBranch) && /,\s*true,\s*301\s*\)/.test(writerBranch));
check('…and the pre-existing /people|/person 301 (the precedent this mirrors) is STILL present, unmodified',
    /preg_match\('#\^\/\(\?:people\|person\)\//.test(indexCodeOnly));

/* ========================================================================
 * Assertion group 5 — api.php: the SPA fragment leg. Slice from
 * `case 'writer':` to its `break;` (the test-tag-route.js technique).
 * ======================================================================== */
console.log("\nAssertion group 5 — api.php's case 'writer':");
/* Slice to the NEXT case label, not the first "break;" — case 'writer'
   has an EARLY break inside its "id is required" 400 guard (before the
   require line), so "first break;" would truncate the slice before ever
   reaching the require() this assertion needs to see. Same case-label-to-
   next-case-label technique test-tag-route.js uses for router.js. */
const apiCaseStart = apiSrc.indexOf("case 'writer':");
const apiCaseNext = apiCaseStart >= 0 ? apiSrc.indexOf("\n        case '", apiCaseStart + 1) : -1;
const apiCaseBlock = apiCaseStart >= 0 && apiCaseNext > apiCaseStart
    ? apiSrc.slice(apiCaseStart, apiCaseNext)
    : '';
check("the page switch still has a case for 'writer' (rule #33 — the route is a shipped contract, never removed)", apiCaseStart >= 0);
check("…and it now requires includes/pages/musician.php (the consolidated fragment)",
    /pages['"]?\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*'musician\.php'/.test(apiCaseBlock));
check("…and it no longer references writer.php anywhere in its own block",
    !/writer\.php/.test(apiCaseBlock));
check("…and it still reads the id from \$_GET (matching buildApiUrl's params.id -> ?id=, unchanged contract)",
    /\$_GET\['id'\]/.test(apiCaseBlock));
const cacheableMatch = apiSrc.match(/\$_cacheablePages\s*=\s*\[[\s\S]*?\];/);
check('$_cacheablePages block found', !!cacheableMatch);
check("'writer' is STILL registered as cacheable", !!cacheableMatch && /'writer'/.test(cacheableMatch[0]));
check("'musician' is STILL registered as cacheable", !!cacheableMatch && /'musician'/.test(cacheableMatch[0]));
check("the page=person/page=people -> musician normalisation is still present and untouched",
    /\$page\s*===\s*'person'\s*\|\|\s*\$page\s*===\s*'people'/.test(apiSrc));

/* ========================================================================
 * Assertion group 6 — router.js: parseRoute() contract lock + the
 * afterPageLoad() canonicalisation/edit-button-relocation leg.
 * ======================================================================== */
console.log("\nAssertion group 6 — router.js:");
const routerCaseStart = routerSrc.indexOf("case 'writer':");
const routerNextCase = routerCaseStart >= 0 ? routerSrc.indexOf("case '", routerCaseStart + 1) : -1;
const routerCaseBlock = routerCaseStart >= 0 && routerNextCase > routerCaseStart
    ? routerSrc.slice(routerCaseStart, routerNextCase)
    : '';
check("parseRoute() STILL has a case for the 'writer' segment (rule #33 lock — a URL param another page emits stays honoured)",
    routerCaseStart >= 0);
check("…and it STILL resolves to { page: 'writer', params: { id: … } } (unchanged shape)",
    /page:\s*'writer'/.test(routerCaseBlock) && /params:\s*\{\s*id:\s*segments\[1\]/.test(routerCaseBlock));

const afterLoadStart = routerSrc.indexOf('afterPageLoad(page, params)');
const songBranchStart = afterLoadStart >= 0 ? routerSrc.indexOf("if (page === 'song')", afterLoadStart) : -1;
check("afterPageLoad() exists and has a page === 'song' branch to slice around", afterLoadStart >= 0 && songBranchStart > afterLoadStart);

/* The writer/musician canonicalisation block sits BETWEEN afterPageLoad()'s
   start and the page === 'song' branch (spec S5 — "BEFORE the
   if (page === 'song') branch"). Slice exactly that region. */
const preSongBlock = (afterLoadStart >= 0 && songBranchStart > afterLoadStart)
    ? routerSrc.slice(afterLoadStart, songBranchStart)
    : '';
check("the writer/musician canonicalisation guard (\"page === 'writer' || page === 'musician'\") lives BEFORE the song branch",
    /page\s*===\s*'writer'\s*\|\|\s*page\s*===\s*'musician'/.test(preSongBlock));
check('…and that same region carries data-musician-canonical',
    /data-musician-canonical/.test(preSongBlock));
check('…and it canonicalises via history.replaceState (never pushState/navigate — no reload, no back-button trap, #1343-B pattern)',
    /window\.history\.replaceState\s*\(/.test(preSongBlock));
check("…and the #btn-edit-musician reveal was RELOCATED here (out of the song branch)",
    /getElementById\('btn-edit-musician'\)/.test(preSongBlock));

/* And the negative half of the same assertion: btn-edit-musician must NOT
   still be inside the sliced page === 'song' block (the #1753 bug this
   consolidation fixes — it must not be re-duplicated back into song's
   branch by a future edit). Slice the song branch the same way
   test-tag-route.js slices a switch case: from its own start to the next
   sibling `if (` at the SAME nesting depth. We approximate that with the
   next top-level "if (page ===" or the end of afterPageLoad — bounded
   generously since the song branch is the file's largest. */
const nextTopLevelIf = songBranchStart >= 0
    ? routerSrc.indexOf("\n        if (page ===", songBranchStart + 10)
    : -1;
const songBranchEnd = nextTopLevelIf > songBranchStart ? nextTopLevelIf : (songBranchStart >= 0 ? songBranchStart + 6000 : -1);
const songBranchBlock = songBranchStart >= 0 && songBranchEnd > songBranchStart
    ? routerSrc.slice(songBranchStart, songBranchEnd)
    : '';
check("the song === 'song' branch itself no longer contains the btn-edit-musician reveal (it was moved, not duplicated)",
    songBranchBlock !== '' && !/getElementById\('btn-edit-musician'\)/.test(songBranchBlock),
    songBranchBlock === '' ? 'could not slice the song branch to check' : undefined);
/* The song page's OWN edit button (btn-edit-song) must still be there —
   proves the slice bound is sane and we didn't accidentally cut the song
   branch down to nothing. */
check("…while the song page's OWN btn-edit-song reveal is still inside its branch (sanity check on the slice bound)",
    /getElementById\('btn-edit-song'\)/.test(songBranchBlock));

/* ========================================================================
 * Assertion group 7 — ONE resolver, THREE consumers (rule #22): the
 * name/slug variant fold exists in exactly one place.
 * ======================================================================== */
console.log('\nAssertion group 7 — one resolver, three consumers (rule #22):');
check('musician_helpers.php defines musicianLegacySlugPlan()', /function\s+musicianLegacySlugPlan\s*\(/.test(helpersSrc));
check('musician_helpers.php defines musicianResolveLegacySlug()', /function\s+musicianResolveLegacySlug\s*\(/.test(helpersSrc));
check('musician_helpers.php defines musicianResolveLegacySlugDb()', /function\s+musicianResolveLegacySlugDb\s*\(/.test(helpersSrc));
check('index.php calls musicianResolveLegacySlugDb()', /musicianResolveLegacySlugDb\s*\(/.test(indexSrc));
check('musician.php calls musicianResolveLegacySlugDb()', /musicianResolveLegacySlugDb\s*\(/.test(musicianSrc));

/* Walk-derived: no OTHER includes/pages/*.php file re-forks the
   name-variant fold (the str_replace('-', ' ' ...) + MB_CASE_TITLE
   co-occurrence that IS the fold's signature — musicianLegacySlugPlan()
   in musician_helpers.php is the only place this pair should appear
   under includes/pages/). */
const pagesDir = join(WEB_ROOT, 'includes', 'pages');
const pageFiles = walkSourceFiles(pagesDir).filter((f) => f.endsWith('.php'));
check('the pages-dir walk actually found files', pageFiles.length > 5, `found ${pageFiles.length}`);
const reforkHits = [];
for (const file of pageFiles) {
    if (file === MUSICIAN_PHP) continue; /* musician.php legitimately calls the shared fold's OUTPUT, checked separately below */
    const src = readFileSync(file, 'utf8');
    if (/str_replace\(\s*'-'\s*,\s*'\s*'/.test(src) && /MB_CASE_TITLE/.test(src)) {
        reforkHits.push(relative(REPO_ROOT, file).split(sep).join('/'));
    }
}
check('no includes/pages/*.php file OTHER than musician.php re-forks the name/slug variant fold (str_replace + MB_CASE_TITLE co-occurring)',
    reforkHits.length === 0,
    reforkHits.length ? 'found in: ' + reforkHits.join(', ') : '');

/* ========================================================================
 * Assertion group 8 — musician.php: the canonical marker + widened
 * fallback, both actually wired.
 * ======================================================================== */
console.log('\nAssertion group 8 — musician.php:');
check('musician.php emits data-musician-canonical="/musician/', musicianSrc.includes('data-musician-canonical="/musician/'));
check('…and the no-registry-row fallback binds musicianLegacySlugPlan(...)[\'names\'] (the D4 no-silent-404 closure)',
    /musicianLegacySlugPlan\(\s*\$personSlug\s*\)\s*\[\s*'names'\s*\]/.test(musicianSrc));

/* ------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll /writer/ -> /musician/ route consolidation assertions passed.');
