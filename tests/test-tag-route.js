/**
 * iHymns — /tag/<slug> theme page route test (#1637)
 *
 * Guards the specific failure mode from #1637: home.php's "Browse by
 * theme" chips (js/modules/home-page.js renderThemeChip()) always emitted
 * a well-formed `<a href="/tag/<slug>" data-navigate="tag">`, but nothing
 * on the other end of that link existed — router.js had no `tag` case
 * (silent fallthrough to `not-found`), api.php's page switch had no
 * `page=tag` case (silent 404), and includes/pages/tag.php didn't exist.
 * Every individual file was internally correct; the bug lived only in the
 * gap BETWEEN them, which is exactly what a structural cross-file check
 * catches and a per-file unit test would not.
 *
 * We don't spin up PHP or a browser here — this checks the four files
 * that make the chip's target resolve (home-page.js, router.js, api.php,
 * tag.php) still agree with each other on the route name, param name and
 * a11y/caching contract, the same "read the shipped source" style as
 * test-setlist-playback.js's structural section.
 *
 *   node tests/test-tag-route.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const WEB_ROOT     = join(__dirname, '..', 'appWeb', 'public_html');
const HOME_PAGE_JS  = join(WEB_ROOT, 'js', 'modules', 'home-page.js');
const ROUTER_JS     = join(WEB_ROOT, 'js', 'modules', 'router.js');
const API_PHP       = join(WEB_ROOT, 'api.php');
const TAG_PHP       = join(WEB_ROOT, 'includes', 'pages', 'tag.php');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

console.log('Assertion 1 — the four files that must agree all exist:');
check('js/modules/home-page.js exists', existsSync(HOME_PAGE_JS));
check('js/modules/router.js exists', existsSync(ROUTER_JS));
check('api.php exists', existsSync(API_PHP));
check('includes/pages/tag.php exists (#1637 — this file never existed before)', existsSync(TAG_PHP));

const homeSrc   = readFileSync(HOME_PAGE_JS, 'utf8');
const routerSrc = readFileSync(ROUTER_JS, 'utf8');
const apiSrc    = readFileSync(API_PHP, 'utf8');
const tagSrc    = readFileSync(TAG_PHP, 'utf8');

console.log('\nAssertion 2 — the chip still emits the exact contract the other three consume:');
check('theme chips link to /tag/<slug>',
    /href="\/tag\/\$\{slug\}"/.test(homeSrc));
check('theme chips carry data-navigate="tag"',
    /data-navigate="tag"/.test(homeSrc));

console.log('\nAssertion 3 — router.js resolves the "tag" segment (the #1637 gap):');
/* Slice from the case label to the NEXT case label (or the switch's
   closing default) rather than trying to balance nested { } with regex —
   the route's own return statement nests an object inside an object
   (`{ page: 'tag', params: { slug: ... } }`), which a non-greedy
   "stop at the first }" pattern cannot express. */
const tagCaseStart = routerSrc.indexOf("case 'tag':");
const nextCaseStart = tagCaseStart >= 0 ? routerSrc.indexOf("case '", tagCaseStart + 1) : -1;
const routeBlock = tagCaseStart >= 0 && nextCaseStart > tagCaseStart
    ? routerSrc.slice(tagCaseStart, nextCaseStart)
    : '';
check('parseRoute() has a case for the "tag" segment', tagCaseStart >= 0);
check('the "tag" route resolves to page: \'tag\'',
    /page:\s*'tag'/.test(routeBlock));
check('the "tag" route reads the slug from the URL segment (segments[1])',
    /params:\s*\{\s*slug:\s*segments\[1\]/.test(routeBlock));

console.log('\nAssertion 4 — api.php\'s page switch routes "tag" to tag.php (the #1637 gap):');
const apiCaseStart = apiSrc.indexOf("case 'tag':");
const apiCaseEnd = apiCaseStart >= 0 ? apiSrc.indexOf('break;', apiCaseStart) : -1;
const apiCaseBlock = apiCaseStart >= 0 && apiCaseEnd > apiCaseStart
    ? apiSrc.slice(apiCaseStart, apiCaseEnd)
    : '';
check('the page switch has a case for \'tag\'', apiCaseStart >= 0);
check('it requires includes/pages/tag.php',
    /pages['"]?\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*'tag\.php'/.test(apiCaseBlock));
check('it reads the slug from $_GET (matching buildApiUrl\'s params.slug -> ?slug=)',
    /\$_GET\['slug'\]/.test(apiCaseBlock));

console.log('\nAssertion 5 — cacheability decision is explicit and deliberate (rule #6):');
const cacheableMatch = apiSrc.match(/\$_cacheablePages\s*=\s*\[[\s\S]*?\];/);
check('$_cacheablePages block found', !!cacheableMatch);
check('\'tag\' is registered as cacheable (public, no per-user data — matches songbook/writer/person/work)',
    !!cacheableMatch && /'tag'/.test(cacheableMatch[0]));

console.log('\nAssertion 6 — tag.php follows the CSP / a11y / bounded-read rules the other slug pages do:');
check('no executable inline <script> in the fragment (rule #30 — CI\'s own guard is test-fragment-inline-scripts.php; this is a fast local echo of the same rule)',
    !/<script(?![^>]*\bsrc=)(?![^>]*application\/ld\+json)[^>]*>/i.test(tagSrc));
check('renders exactly one <h1> that announces the theme name (accessibility requirement)',
    (tagSrc.match(/<h1[\s>]/g) || []).length === 1
    && /<h1[^>]*>[\s\S]{0,120}\$tagInfo\['name'\]/.test(tagSrc));
check('song list items use role="listitem" inside a role="list" container (matches songbook.php/writer.php)',
    /role="list"/.test(tagSrc) && /role="listitem"/.test(tagSrc));
check('breadcrumb has an aria-label (matches songbook.php/writer.php)',
    /aria-label="Breadcrumb"/.test(tagSrc));
check('handles the empty-slug case without a bare PHP notice (explicit \'\' check, not just isset())',
    /\$tagSlug\s*!==\s*''/.test(tagSrc) || /\$tagSlug\s*===\s*''/.test(tagSrc));
check('unknown/empty slug renders the themed error card, not a blank page',
    /renderErrorFragment/.test(tagSrc));
check('the song query is scoped by TagId, never a whole-corpus scan (rule #17)',
    /WHERE tm\.TagId = \?/.test(tagSrc) && /bind_param\('i'/.test(tagSrc));

/* ------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll /tag route assertions passed.');
