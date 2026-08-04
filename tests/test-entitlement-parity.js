/**
 * iHymns — PHP ⇄ JS entitlement map parity guard (#1641 item 3)
 *
 * `includes/entitlements.php` is the authority. `js/modules/entitlements.js` is
 * a MIRROR of it — the PHP file says so in its own header — because the client
 * needs to decide whether to render an affordance without a round trip.
 *
 * They had drifted by **thirteen keys**, all present in PHP and missing from
 * JS.
 *
 * WHY THAT MATTERS EVEN THOUGH NOTHING BROKE
 * ------------------------------------------
 * The drift was latent: JS `userHasEntitlement()` returns `false` for an
 * unknown key, and no JS caller used any of the thirteen. So there was no
 * symptom.
 *
 * But the failure it sets up is silent and one-directional. The next
 * client-side affordance gated on one of those keys — a button, a menu entry, a
 * panel — simply never renders. No error, no console warning, no 403 to
 * investigate. It reads as "that feature was never built", which is the single
 * hardest bug class in this codebase to notice (#1565, #1581, #1626, #1643).
 *
 * And the direction is the dangerous one: a MISSING key fails CLOSED and
 * invisibly, so an admin who has been granted a permission finds the UI simply
 * does not offer it, while the server would have allowed the action.
 *
 * THE PATTERN THIS CLOSES
 * -----------------------
 * "Two lists that must agree, with nothing enforcing it" — the fourth instance
 * found in this codebase, after event names (tests/test-event-names.js),
 * rate-limit check/record pairs (#1636), and the npm-vs-CI test lists that let
 * 15 of 22 node suites run nowhere. Every one of them was a comment saying
 * "keep these in sync" and no mechanism. A comment is not a mechanism.
 *
 * WHAT IT ASSERTS
 *   1. Every PHP key exists in JS (the drift that happened).
 *   2. Every JS key exists in PHP (the reverse — a JS-only key would grant a
 *      client-side affordance the server then refuses, i.e. fails OPEN in the
 *      UI, which is worse).
 *   3. Shared keys list the SAME roles. A key present in both but granted to
 *      different roles is the subtlest form of this bug and the one a
 *      key-presence check alone would miss.
 *
 *   node tests/test-entitlement-parity.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUB = join(__dirname, '..', 'appWeb', 'public_html');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

/* Strip comments before parsing. Both files annotate individual entries with
   trailing `//` notes and block comments that contain quoted role names — and
   this guard's own additions to the JS map describe the drift in prose that
   names several keys. Parsing raw source would invent entries from commentary. */
const stripBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');
const stripLine = (s) => s.replace(/(^|[^:])\/\/.*$/gm, '$1');

const phpRaw = readFileSync(join(PUB, 'includes/entitlements.php'), 'utf8');
const jsRaw = readFileSync(join(PUB, 'js/modules/entitlements.js'), 'utf8');

/* Isolate the map bodies. Matching the whole file would pull in unrelated
   arrays (effectiveEntitlements()'s locals, the overrides parser). */
const phpBodyMatch = phpRaw.match(/const ENTITLEMENTS = \[([\s\S]*?)\n\];/);
const jsBodyMatch = jsRaw.match(/ENTITLEMENTS\s*=\s*\{([\s\S]*?)\n\};/);

check('includes/entitlements.php exposes a parseable ENTITLEMENTS map', phpBodyMatch !== null);
check('js/modules/entitlements.js exposes a parseable ENTITLEMENTS map', jsBodyMatch !== null);

if (!phpBodyMatch || !jsBodyMatch) {
    console.error('\nCannot compare — one of the maps did not parse. Fix the extraction above;');
    console.error('a guard that silently stops comparing is worse than no guard.');
    process.exit(1);
}

const phpBody = stripLine(stripBlock(phpBodyMatch[1]));
const jsBody = stripLine(stripBlock(jsBodyMatch[1]));

const roles = (s) => [...s.matchAll(/'([a-z_]+)'/g)].map(m => m[1]).sort().join(',');

const php = new Map(
    [...phpBody.matchAll(/'([a-z0-9_]+)'\s*=>\s*\[([^\]]*)\]/g)].map(m => [m[1], roles(m[2])])
);
const js = new Map(
    [...jsBody.matchAll(/^\s*([a-z0-9_]+)\s*:\s*\[([^\]]*)\]/gm)].map(m => [m[1], roles(m[2])])
);

console.log(`\n#1641 item 3 — entitlement map parity (PHP ${php.size} keys, JS ${js.size} keys)\n`);

/* A sanity floor: if either map parses to a handful of entries the regex has
   drifted and every comparison below would pass vacuously. */
check('both maps parsed a plausible number of entries (>20 each)',
    php.size > 20 && js.size > 20);

const onlyPhp = [...php.keys()].filter(k => !js.has(k)).sort();
const onlyJs = [...js.keys()].filter(k => !php.has(k)).sort();

check(`no PHP-only keys (missing from JS → client affordance silently never renders)${onlyPhp.length ? ' — ' + onlyPhp.join(', ') : ''}`,
    onlyPhp.length === 0);

check(`no JS-only keys (missing from PHP → UI offers what the server refuses)${onlyJs.length ? ' — ' + onlyJs.join(', ') : ''}`,
    onlyJs.length === 0);

const roleDiffs = [...php.keys()]
    .filter(k => js.has(k) && php.get(k) !== js.get(k))
    .map(k => `${k} (PHP: ${php.get(k)} | JS: ${js.get(k)})`);

check(`shared keys grant the SAME roles${roleDiffs.length ? ' — ' + roleDiffs.join('; ') : ''}`,
    roleDiffs.length === 0);

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nincludes/entitlements.php is the AUTHORITY; js/modules/entitlements.js mirrors it.');
    console.error('Copy the role list verbatim — the JS map is a mirror, not a second opinion.');
    process.exit(1);
}
console.log('\nEntitlement maps are in sync.');
