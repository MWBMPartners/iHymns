/**
 * iHymns — third-party load integrity guard (#1647, rule #1587)
 *
 * Third-party code running inside this origin must be pinned to an exact
 * version, SRI-checked, and backed by a vendored local copy. #1587 established
 * that after an audit of the admin pages; #1647 is the one it missed, and it
 * was on the PUBLIC side — SortableJS, injected at runtime by
 * js/modules/card-layout.js rather than written as a <script> tag, which is
 * presumably why a sweep looking at markup never saw it.
 *
 * WHY A GUARD AND NOT JUST A FIX
 * ------------------------------
 * The reason SRI was missing is instructive and likely to recur. A PLACEHOLDER
 * hash was committed once. It did not match, so the browser refused the script,
 * so the card-layout reorder feature was dead — with no error a user would ever
 * see. The response was to delete the integrity attribute and leave a comment
 * saying SRI could be added "when we pin the CDN version", by which point the
 * URL was already pinned.
 *
 * So the failure mode is not "somebody forgot". It is "somebody tried, it broke
 * something invisibly, and removing the security control was the fastest way to
 * make the feature work again". A guard is the only thing that makes that
 * trade-off visible next time.
 *
 * WHAT IT ASSERTS
 *   (1) The runtime loader carries an integrity hash, a pinned version, and a
 *       same-origin fallback. The fallback is not a nicety: it is what makes
 *       the hash safe to keep, because a mismatch then degrades to a working
 *       local load instead of a dead feature.
 *   (2) The hash and URL in the JS module match APP_CONFIG['libraries'] in
 *       includes/config.php. The module cannot read PHP config, so the values
 *       are necessarily duplicated — and "two lists that must agree with
 *       nothing enforcing it" is a failure mode this codebase keeps hitting
 *       (event names, rate-limit pairs, entitlement maps, test lists). This
 *       assertion is the tie.
 *   (3) tools/download-vendor.sh actually fetches the file the fallback points
 *       at. A fallback URL that nothing populates is a 404 dressed as
 *       resilience — and since the SPA's .htaccess catch-all answers unmatched
 *       paths with HTTP 200 and the HTML shell (#1566), that 404 would arrive
 *       as HTML parsed as JavaScript rather than as an honest failure.
 *
 *   node tests/test-vendor-sri.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const PUB = join(ROOT, 'appWeb', 'public_html');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

/* Strip comments before matching. The module's doc-block explains the
   placeholder-hash history and necessarily quotes the surrounding vocabulary;
   matching raw source would let prose satisfy assertions that are supposed to
   be about code. */
const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const stripPhpBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

const cardSrc = stripJs(readFileSync(join(PUB, 'js/modules/card-layout.js'), 'utf8'));
const configSrc = stripPhpBlock(readFileSync(join(PUB, 'includes/config.php'), 'utf8'));
const vendorSh = readFileSync(join(ROOT, 'tools/download-vendor.sh'), 'utf8');

console.log('\n#1647 — third-party load integrity\n');

/* ---- 1. the loader is pinned, hashed and backed ----------------------- */

const cdnMatch = cardSrc.match(/https:\/\/cdn\.jsdelivr\.net\/npm\/sortablejs@([\d.]+)\/Sortable\.min\.js/);
check('card-layout.js pins an exact SortableJS version (no @latest / floating tag)',
    cdnMatch !== null && /^\d+\.\d+\.\d+$/.test(cdnMatch[1]));

const sriMatch = cardSrc.match(/sha384-[A-Za-z0-9+/=]{40,}/);
check('card-layout.js carries a real sha384 SRI hash',
    sriMatch !== null);

check('the SRI hash is not an obvious placeholder',
    sriMatch !== null && !/(placeholder|xxx|0000|TODO)/i.test(sriMatch[0]));

check('SRI is paired with crossOrigin (without it the browser cannot hash the body)',
    /integrity\s*=/.test(cardSrc) && /crossOrigin\s*=/.test(cardSrc));

check('a same-origin vendored fallback exists — this is what makes the hash safe to keep',
    /\/vendor\/sortablejs\/Sortable\.min\.js/.test(cardSrc));

check('the fallback path is root-absolute, not relative (#1566)',
    /['"]\/vendor\/sortablejs\//.test(cardSrc));

/* ---- 2. the JS module and the PHP registry agree ---------------------- */

const cfgBlock = (configSrc.match(/'sortablejs'\s*=>\s*\[[\s\S]*?\]/) || [''])[0];
check('includes/config.php registers sortablejs in APP_CONFIG[libraries]',
    cfgBlock !== '');

const cfgSri = (cfgBlock.match(/sha384-[A-Za-z0-9+/=]{40,}/) || [null])[0];
check('the SRI hash in config.php MATCHES the one in card-layout.js',
    cfgSri !== null && sriMatch !== null && cfgSri === sriMatch[0]);

const cfgCdn = (cfgBlock.match(/https:\/\/cdn\.jsdelivr\.net\/npm\/sortablejs@[\d.]+\/Sortable\.min\.js/) || [null])[0];
check('the CDN URL in config.php MATCHES the one in card-layout.js',
    cfgCdn !== null && cdnMatch !== null && cfgCdn === cdnMatch[0]);

check('config.php records the local vendored path',
    /vendor\/sortablejs\/Sortable\.min\.js/.test(cfgBlock));

/* ---- 3. something actually populates the fallback --------------------- */

check('download-vendor.sh creates the sortablejs vendor directory',
    /mkdir -p "\$VENDOR_DIR\/sortablejs"/.test(vendorSh));

check('download-vendor.sh downloads Sortable.min.js',
    /sortablejs@[\d.]+\/Sortable\.min\.js/.test(vendorSh)
    && /\$VENDOR_DIR\/sortablejs\/Sortable\.min\.js/.test(vendorSh));

const shVer = (vendorSh.match(/sortablejs@([\d.]+)\/Sortable\.min\.js/) || [null, null])[1];
check('download-vendor.sh fetches the SAME version the module loads',
    shVer !== null && cdnMatch !== null && shVer === cdnMatch[1]);

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nThird-party code in this origin must be pinned + SRI-checked + vendored');
    console.error('(rule #1587). If a hash mismatch is breaking a feature, fix the HASH —');
    console.error('do not remove the integrity attribute, which is how #1647 happened.');
    process.exit(1);
}
console.log('\nAll third-party integrity assertions passed.');
