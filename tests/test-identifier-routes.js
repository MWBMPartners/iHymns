/**
 * iHymns — external-identifier alias routes test (#1741 P3)
 *
 * Guards the same failure SHAPE as tests/test-tag-route.js (#1637): a route
 * segment can be internally correct in every one of several files and still
 * be dead on arrival if the files don't agree with each other on the route
 * name. Six segments are covered here — /iswc/ /ccli/ /bowi/ /isrc/ /ipi/
 * /isni/ — sharing ONE resolver (includes/identifier_resolve.php) and ONE
 * page (includes/pages/identifier.php), which absorbed and replaced the
 * old includes/pages/iswc.php (#940).
 *
 * TREE-DERIVED, NOT HARDCODED (rule #34 — "a guard must be … derived from
 * the tree rather than from a list you typed"): the six scheme names are
 * NOT retyped in this file. They are extracted from
 * includes/identifier_normalize.php's IHYMNS_ID_SCHEMES registry — the same
 * single source of truth router.js, api.php and identifier_resolve.php all
 * read from — so a SEVENTH scheme added to the registry without its router
 * case / api.php case is caught automatically, and a scheme retired from
 * the registry stops being asserted automatically, with no hand-edit to
 * this test either way.
 *
 * We don't spin up PHP or a browser here — same "read the shipped source"
 * structural style as test-tag-route.js and test-setlist-playback.js.
 *
 *   node tests/test-identifier-routes.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const WEB_ROOT       = join(__dirname, '..', 'appWeb', 'public_html');
const NORMALIZE_PHP  = join(WEB_ROOT, 'includes', 'identifier_normalize.php');
const RESOLVE_PHP    = join(WEB_ROOT, 'includes', 'identifier_resolve.php');
const ROUTER_JS       = join(WEB_ROOT, 'js', 'modules', 'router.js');
const API_PHP         = join(WEB_ROOT, 'api.php');
const IDENTIFIER_PHP  = join(WEB_ROOT, 'includes', 'pages', 'identifier.php');
const ISWC_PHP_GONE   = join(WEB_ROOT, 'includes', 'pages', 'iswc.php');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

console.log('Assertion 1 — the files that must exist:');
check('includes/identifier_normalize.php exists (the scheme registry this test derives its list from)', existsSync(NORMALIZE_PHP));
check('includes/identifier_resolve.php exists', existsSync(RESOLVE_PHP));
check('js/modules/router.js exists', existsSync(ROUTER_JS));
check('api.php exists', existsSync(API_PHP));
check('includes/pages/identifier.php exists (#1741 P3 — the unified page)', existsSync(IDENTIFIER_PHP));
check('includes/pages/iswc.php no longer exists (absorbed into identifier.php)', !existsSync(ISWC_PHP_GONE));

if (!existsSync(NORMALIZE_PHP)) {
    console.log(`\n${passed} passed, ${failed} failed`);
    console.error('\nCannot continue — identifier_normalize.php is missing, so the scheme list cannot be derived.');
    process.exit(1);
}

const normalizeSrc = readFileSync(NORMALIZE_PHP, 'utf8');

/* -----------------------------------------------------------------------
 * Derive the scheme list from IHYMNS_ID_SCHEMES itself — slice from the
 * `const IHYMNS_ID_SCHEMES = [` declaration to its closing `];`, then pull
 * every top-level `'<scheme>' => [` key out of that slice. This is the
 * SAME two-step "bounded slice, then regex the keys inside it" shape
 * test-tag-route.js uses for a PHP switch block, applied here to a PHP
 * array literal instead.
 * ---------------------------------------------------------------------- */
const registryStart = normalizeSrc.indexOf('const IHYMNS_ID_SCHEMES = [');
const registryEnd = registryStart >= 0 ? normalizeSrc.indexOf('];', registryStart) : -1;
const registryBlock = registryStart >= 0 && registryEnd > registryStart
    ? normalizeSrc.slice(registryStart, registryEnd)
    : '';

check('IHYMNS_ID_SCHEMES registry found in identifier_normalize.php', registryBlock !== '');

const SCHEMES = [];
{
    const keyPattern = /'([a-z0-9\-]+)'\s*=>\s*\[/g;
    let m;
    while ((m = keyPattern.exec(registryBlock)) !== null) {
        SCHEMES.push(m[1]);
    }
}
check('at least one scheme extracted from the registry (a truly empty list means the regex broke, not that the registry is empty)', SCHEMES.length > 0);

console.log(`\nDerived ${SCHEMES.length} scheme(s) from IHYMNS_ID_SCHEMES: ${SCHEMES.join(', ')}`);

/* -----------------------------------------------------------------------
 * MUTATION SELF-TEST (rule #34) — prove the extraction regex can actually
 * fail before trusting it to pass. Inject a bogus 'zz-mutation-probe' key
 * into a COPY of the registry block and confirm the SAME regex picks it
 * up — i.e. the derivation isn't silently matching nothing / everything.
 * ---------------------------------------------------------------------- */
{
    const mutated = registryBlock + `\n    'zz-mutation-probe' => ['label' => 'Probe', 'entity' => 'work', 'multiSong' => false],`;
    const probeKeys = [];
    const probePattern = /'([a-z0-9\-]+)'\s*=>\s*\[/g;
    let pm;
    while ((pm = probePattern.exec(mutated)) !== null) probeKeys.push(pm[1]);
    check('mutation self-test — injecting a bogus scheme into a COPY of the registry block IS detected by the same extraction regex',
        probeKeys.includes('zz-mutation-probe'),
        'the key-extraction regex did not pick up an injected key — it may be silently under-matching');
}

if (SCHEMES.length === 0) {
    console.log(`\n${passed} passed, ${failed} failed`);
    console.error('\nCannot continue — no schemes were derived from the registry.');
    process.exit(1);
}

const routerSrc     = readFileSync(ROUTER_JS, 'utf8');
const apiSrc        = readFileSync(API_PHP, 'utf8');
const identifierSrc = readFileSync(IDENTIFIER_PHP, 'utf8');

console.log('\nAssertion 2 — router.js resolves every derived scheme segment:');
for (const scheme of SCHEMES) {
    check(`parseRoute() has a case for the '${scheme}' segment`, routerSrc.includes(`case '${scheme}':`));
}

console.log('\nAssertion 3 — api.php\'s page switch routes every derived scheme to includes/pages/identifier.php:');
/* All six schemes fall through to ONE shared block in api.php (unlike
   tag.php's single dedicated case), so — rather than trying to slice a
   per-scheme block out of a shared fallthrough group — confirm each
   `case '<scheme>':` label exists, then confirm the ONE block those labels
   fall into requires identifier.php and reads $_GET['code']. This mirrors
   test-tag-route.js's "slice to the next case label" technique, applied to
   the fallthrough group as a whole rather than one case at a time. */
for (const scheme of SCHEMES) {
    check(`api.php's page switch has a case for '${scheme}'`, apiSrc.includes(`case '${scheme}':`));
}
const firstCaseIdx = SCHEMES.length ? apiSrc.indexOf(`case '${SCHEMES[0]}':`) : -1;
const groupBreakIdx = firstCaseIdx >= 0 ? apiSrc.indexOf('break;', firstCaseIdx) : -1;
const groupBlock = firstCaseIdx >= 0 && groupBreakIdx > firstCaseIdx
    ? apiSrc.slice(firstCaseIdx, groupBreakIdx)
    : '';
check('the identifier case group requires includes/pages/identifier.php',
    /pages['"]?\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*'identifier\.php'/.test(groupBlock));
check('the identifier case group reads the code from $_GET (matching buildApiUrl\'s params.code -> ?code=)',
    /\$_GET\['code'\]/.test(groupBlock));
check('every derived scheme\'s case label falls into the SAME identifier.php-requiring block (one shared block, not six copies)',
    SCHEMES.every((s) => groupBlock.includes(`case '${s}':`)),
    'a scheme\'s case label is outside the block that requires identifier.php — it would 404 or hit the wrong handler');

console.log('\nAssertion 4 — cacheability decision is explicit and deliberate (rule #6 — the routes are intentionally UNCACHED, matching the iswc/tune precedent):');
const cacheableMatch = apiSrc.match(/\$_cacheablePages\s*=\s*\[[\s\S]*?\];/);
check('$_cacheablePages block found', !!cacheableMatch);
for (const scheme of SCHEMES) {
    check(`'${scheme}' is NOT registered as cacheable (deliberate — matches the iswc/tune uncached precedent)`,
        !!cacheableMatch && !new RegExp(`'${scheme}'`).test(cacheableMatch[0]));
}

console.log('\nAssertion 5 — includes/pages/identifier.php follows the CSP / a11y / bounded-read rules the other slug pages do:');
check('no executable inline <script> in the fragment (rule #30 — CI\'s own guard is test-fragment-inline-scripts.php; this is a fast local echo of the same rule)',
    !/<script(?![^>]*\bsrc=)(?![^>]*application\/ld\+json)[^>]*>/i.test(identifierSrc));
check('renders exactly one <h1> (accessibility requirement — one heading per fragment)',
    (identifierSrc.match(/<h1[\s>]/g) || []).length === 1);
check('song/musician list items use role="listitem" inside a role="list" container',
    /role="list"/.test(identifierSrc) && /role="listitem"/.test(identifierSrc));
check('breadcrumb has an aria-label="Breadcrumb" (matches tag.php/songbook.php/writer.php)',
    /aria-label="Breadcrumb"/.test(identifierSrc));
check('handles the empty-code case without a bare PHP notice (explicit \'\' check, not just isset())',
    /\$idCanonical\s*===\s*''/.test(identifierSrc) || /\$idCanonical\s*!==\s*''/.test(identifierSrc));
check('a resolver throw renders the themed error card, not a blank page',
    /renderErrorFragment/.test(identifierSrc));
check('calls the shared resolver (ihymns_resolve_identifier) rather than re-forking a lookup inline',
    /ihymns_resolve_identifier\s*\(/.test(identifierSrc));
check('every dynamic value that reaches the breadcrumb/heading is escaped (no raw $idCanonical/$idLabel echo)',
    !/<\?=\s*\$idCanonical\s*\?>/.test(identifierSrc) && !/<\?=\s*\$idLabel\s*\?>/.test(identifierSrc));

/* ------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll external-identifier route assertions passed.');
