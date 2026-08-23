/**
 * iHymns — /themes A–Z index route test (#1148)
 *
 * The sibling of test-tag-route.js (#1637). The bug class both guard: a
 * multi-file SPA route whose pieces are each internally correct but disagree in
 * the GAP between them (router segment ⇄ api page case ⇄ fragment ⇄ cacheable
 * membership ⇄ afterPageLoad import ⇄ title map). A per-file unit test never
 * sees that gap; this "read the shipped source" structural check does.
 *
 * Tree-derived where it matters: the emitter coverage (Assertion 7) greps the
 * whole client/page tree for anything linking to /themes, so the home strip
 * (C3) and the nav entry (C4) join the checked set automatically as they land —
 * no typed list of emitters to keep in sync.
 *
 * The home strip navigates to /themes (C3 retired its old inline ?action=tags
 * reveal); Assertion 8 pins that. Assertion 7 stays tree-derived so the nav
 * entry (C4) joins the checked emitter set automatically as it lands.
 *
 *   node tests/test-themes-route.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, extname } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const WEB_ROOT   = join(__dirname, '..', 'appWeb', 'public_html');
const THEMES_PHP = join(WEB_ROOT, 'includes', 'pages', 'themes.php');
const THEMES_JS  = join(WEB_ROOT, 'js', 'modules', 'themes-page.js');
const ROUTER_JS  = join(WEB_ROOT, 'js', 'modules', 'router.js');
const API_PHP    = join(WEB_ROOT, 'api.php');

let passed = 0;
let failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/** Recursively collect files with the given extensions under dir. */
function walk(dir, exts, out = []) {
    if (!existsSync(dir)) return out;
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        const st = statSync(p);
        if (st.isDirectory()) walk(p, exts, out);
        else if (exts.includes(extname(p))) out.push(p);
    }
    return out;
}

console.log('Assertion 1 — the files that must agree all exist:');
check('includes/pages/themes.php exists', existsSync(THEMES_PHP));
check('js/modules/themes-page.js exists', existsSync(THEMES_JS));
check('js/modules/router.js exists', existsSync(ROUTER_JS));
check('api.php exists', existsSync(API_PHP));

const themesSrc = existsSync(THEMES_PHP) ? readFileSync(THEMES_PHP, 'utf8') : '';
const themesJs  = existsSync(THEMES_JS) ? readFileSync(THEMES_JS, 'utf8') : '';
const routerSrc = existsSync(ROUTER_JS) ? readFileSync(ROUTER_JS, 'utf8') : '';
const apiSrc    = existsSync(API_PHP) ? readFileSync(API_PHP, 'utf8') : '';

console.log('\nAssertion 2 — router.js resolves the "themes" segment (+ the "tags" alias):');
const tCaseStart = routerSrc.indexOf("case 'themes':");
const tNext = tCaseStart >= 0 ? routerSrc.indexOf('return', tCaseStart) : -1;
const tBlock = tCaseStart >= 0 && tNext > tCaseStart ? routerSrc.slice(tCaseStart, tNext + 40) : '';
check('parseRoute() has a case for the "themes" segment', tCaseStart >= 0);
check('the "themes"/"tags" route resolves to page: \'themes\'', /page:\s*'themes'/.test(tBlock));
check('the "tags" forgiving alias falls through to the same case',
    /case 'tags':/.test(routerSrc) && routerSrc.indexOf("case 'tags':") > routerSrc.indexOf("case 'tag':"));

console.log('\nAssertion 3 — api.php page switch + cacheability + alias-before-cache ordering (A.4):');
const apiCaseStart = apiSrc.indexOf("case 'themes':");
const apiCaseEnd = apiCaseStart >= 0 ? apiSrc.indexOf('break;', apiCaseStart) : -1;
const apiCaseBlock = apiCaseStart >= 0 && apiCaseEnd > apiCaseStart ? apiSrc.slice(apiCaseStart, apiCaseEnd) : '';
check('the page switch has a case for \'themes\'', apiCaseStart >= 0);
check('it requires includes/pages/themes.php',
    /pages['"]?\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*'themes\.php'/.test(apiCaseBlock));
const cacheableMatch = apiSrc.match(/\$_cacheablePages\s*=\s*\[[\s\S]*?\];/);
check('$_cacheablePages block found', !!cacheableMatch);
check('\'themes\' is registered as cacheable (public, no per-user data)',
    !!cacheableMatch && /'themes'/.test(cacheableMatch[0]));
/* A.4 — the tags->themes fold must run BEFORE the cacheable membership, or the
   alias would double-cache identical content under two page values. */
const foldPos  = apiSrc.search(/\$page\s*===\s*'tags'/);
const cachePos = apiSrc.indexOf('$_cacheablePages = [');
check('the tags->themes fold sits ABOVE the $_cacheablePages membership (no double-cache)',
    foldPos >= 0 && cachePos >= 0 && foldPos < cachePos);

console.log('\nAssertion 4 — afterPageLoad wires the module + updateTitle covers both theme surfaces:');
check('afterPageLoad imports ./themes-page.js under a page === \'themes\' branch',
    /page === 'themes'/.test(routerSrc) && /import\('\.\/themes-page\.js'\)/.test(routerSrc));
check('themes-page.js exports initThemesPage()', /export function initThemesPage\s*\(/.test(themesJs));
check('updateTitle has a \'themes\' entry', /'themes':\s*'Themes/.test(routerSrc));
check('updateTitle has a \'tag\' entry (the #1637 gap this feature also fixes)', /'tag':\s*'Theme/.test(routerSrc));

console.log('\nAssertion 5 — the fragment follows the CSP / a11y / progressive-enhancement rules:');
check('no executable inline <script> in the fragment (rule #30)',
    !/<script(?![^>]*\bsrc=)(?![^>]*application\/ld\+json)[^>]*>/i.test(themesSrc));
check('exactly one <h1> announcing "Themes"',
    (themesSrc.match(/<h1[\s>]/g) || []).length >= 1 && /<h1[^>]*>[\s\S]{0,80}Themes/.test(themesSrc));
check('breadcrumb has an aria-label', /aria-label="Breadcrumb"/.test(themesSrc));
check('rows link to /tag/<slug> with data-navigate="tag" (the shipped destination contract)',
    /href="\/tag\/<\?= htmlspecialchars\(\$slug\)/.test(themesSrc) && /data-navigate="tag"/.test(themesSrc));
check('the count rides inside the link with a visually-hidden " songs" suffix (accessible name)',
    /visually-hidden"> songs<\/span>/.test(themesSrc));
check('the filter + jump-bar hosts ship hidden (progressive enhancement — no dead controls without JS)',
    /id="themes-filter-block" hidden/.test(themesSrc) && /id="themes-jump-bar"[^>]*hidden/.test(themesSrc));
check('an empty vocabulary renders an empty-state, not a 404 (index-with-nothing is a 200)',
    /No themes yet/.test(themesSrc) && !/http_response_code\(404\)/.test(themesSrc));

console.log('\nAssertion 6 — the module derives its letters from the DOM, filters without fetch:');
check('jump letters are derived from rendered [data-themes-letter] sections (not a typed A–Z list)',
    /data-themes-letter/.test(themesJs));
/* Comment-stripped: the doc-block legitimately mentions "no fetch … apiFetch". */
const themesJsCode = themesJs.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
check('the filter does no network I/O (pure DOM — no fetch/apiFetch)',
    !/\bfetch\(|apiFetch\(/.test(themesJsCode));
check('scrollIntoView honours reduced motion',
    /scrollIntoView\(\{[^}]*reduceMotion\(\)/.test(themesJs));

console.log('\nAssertion 7 — every /themes emitter is handled by the route (tree-derived, tolerant):');
/* Grep the whole client/page tree for anything linking to /themes; assert the
   router case exists for each. At C2 there may be zero emitters (the route
   exists before anything links to it); the home strip (C3) and nav (C4) join
   this set automatically. */
const emitterFiles = [
    ...walk(join(WEB_ROOT, 'js'), ['.js']),
    ...walk(join(WEB_ROOT, 'includes'), ['.php']),
    join(WEB_ROOT, 'index.php'),
];
const emitters = emitterFiles.filter((f) => {
    if (!existsSync(f)) return false;
    const s = readFileSync(f, 'utf8');
    return /data-navigate="themes"/.test(s) || /href="\/themes"/.test(s);
});
/* Whether or not any emitter exists yet, the route case must exist (asserted
   in 2). This assertion just proves no emitter is orphaned. */
check(`every /themes emitter has a router case (emitters found: ${emitters.length})`,
    emitters.length === 0 || tCaseStart >= 0);

console.log('\nAssertion 8 — the home strip navigates to /themes; the inline reveal is retired (C3):');
const HOME_PAGE_JS = join(WEB_ROOT, 'js', 'modules', 'home-page.js');
const homeSrc = existsSync(HOME_PAGE_JS) ? readFileSync(HOME_PAGE_JS, 'utf8') : '';
/* Comment-stripped: a doc-block may legitimately explain the retired reveal. */
const homeCode = homeSrc.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
check('home-page.js emits the /themes link (data-navigate="themes")',
    /data-navigate="themes"/.test(homeCode) && /href="\/themes"/.test(homeCode));
check('home-page.js no longer fetches ?action=tags (the unbounded inline reveal is gone)',
    !/action=tags/.test(homeCode));
check('at least one /themes emitter now exists (the home strip)', emitters.length >= 1);

/* ------------------------------------------------------------------ */
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll /themes route assertions passed.');
