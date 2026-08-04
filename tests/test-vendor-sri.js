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

import { readFileSync, readdirSync } from 'node:fs';
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

/* ---- 4. NO page hardcodes an un-hashed third-party load (#1676) -------
 *
 * Sections 1-3 check ONE library, named in this file. That is the shape of
 * check this codebase has repeatedly found insufficient: a hardcoded list only
 * ever covers what its author already knew about, so it can confirm the known
 * case and stay silent on every unknown one.
 *
 * And it did. While sections 1-3 passed, four pages were loading Bootstrap from
 * a CDN with no `integrity` at all and across THREE different pinned versions
 * (5.3.3 in both editors, 5.3.6 in the public channel gate, 5.3.6 in the
 * registry). The audit that produced rule #1587 had swept the admin pages that
 * use head-libs.php; these four build a bespoke <head> and were never in scope.
 *
 * So this section derives its file list from the TREE — every .php/.html under
 * public_html — and asserts the property directly: a subresource load naming an
 * external host must carry an integrity hash. It cannot go stale when a page is
 * added, which is the entire point.
 *
 * Note the tag regex is multi-line (`[\s\S]`). Several of these tags are written
 * across four or five lines with the attributes stacked, and a line-oriented
 * scan misses every one of them — which is why a `grep` for un-hashed loads
 * comes back almost clean while the real count is nine.
 */

const EXTERNAL_LOAD_EXEMPT = [
    {
        match: /cdn\.usefathom\.com/,
        why: 'Fathom analytics ships a rolling script.js with no versioned URL; '
           + 'an SRI hash would break the page on the vendor\'s next deploy. '
           + 'Scope is limited by the CSP script-src allow-list in index.php.',
    },
];

/* rel values that are hints to the network stack, not subresource loads —
   nothing is executed or applied, so there is no body to hash.
   https://developer.mozilla.org/docs/Web/HTML/Attributes/rel */
const NON_LOAD_REL = /rel\s*=\s*["'](preconnect|dns-prefetch|prefetch|preload|canonical|alternate|manifest|icon|apple-touch-icon|mask-icon|search|me|profile)["']/i;

function walk(dir, out = []) {
    for (const e of readdirSync(dir, { withFileTypes: true })) {
        if (e.name === 'vendor' || e.name === 'node_modules' || e.name.startsWith('.')) continue;
        const p = join(dir, e.name);
        if (e.isDirectory()) walk(p, out);
        else if (/\.(php|html)$/.test(e.name)) out.push(p);
    }
    return out;
}

const offenders = [];
let scanned = 0;

for (const file of walk(PUB)) {
    /* Strip HTML and PHP block comments first. Several of these files DISCUSS
       CDN URLs at length in their doc-blocks (this is a heavily annotated
       codebase), and a comment quoting a URL is not a load. */
    const src = readFileSync(file, 'utf8')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '');

    for (const tag of src.match(/<(?:script|link)\b[\s\S]*?>/gi) || []) {
        const url = (tag.match(/(?:src|href)\s*=\s*["'](https?:\/\/[^"']+)["']/i) || [])[1];
        if (!url) continue;                       // relative, or built from PHP config — not a hardcoded literal
        if (NON_LOAD_REL.test(tag)) continue;     // a hint, not a load
        if (EXTERNAL_LOAD_EXEMPT.some((e) => e.match.test(url))) continue;

        scanned++;
        if (!/integrity\s*=\s*["']\s*sha(256|384|512)-/i.test(tag)) {
            offenders.push(`${file.slice(PUB.length + 1)} -> ${url}`);
        }
    }
}

/* `scanned` counts LITERAL external loads only — a tag whose URL comes from
   APP_CONFIG has no https:// in the source and is checked by sections 1-3
   instead. So 0 is the healthy state, not a broken scan: it means nothing in
   the tree hardcodes a CDN URL any more. The count is printed so a future
   reader can tell "nothing to check" from "the regex stopped matching", and
   both mutation cases (un-hashed literal / hashed literal bypassing the shared
   emitter) are verified to fail this file. */
check(`every hardcoded external <script>/<link> carries an SRI hash (${scanned} literal load${scanned === 1 ? '' : 's'} found; 0 is the goal)`,
    offenders.length === 0);
if (offenders.length) {
    for (const o of offenders) console.log(`       ${o}`);
}

/* The registry-driven emitter is the intended way to load Bootstrap; assert the
   four historically-drifting pages actually route through it rather than having
   been "fixed" by pasting the current hash inline, which would drift again on
   the next version bump. */
for (const page of [
    'manage/editor/index.php',
    'manage/editor/editor2.php',
    'manage/editor/import2.php',
    'includes/channel_gate.php',
]) {
    const s = readFileSync(join(PUB, page), 'utf8');
    check(`${page} loads Bootstrap via ihymns_bootstrap_css_links(), not a hardcoded URL`,
        /ihymns_bootstrap_css_links\s*\(/.test(s)
        && !/["']https:\/\/cdn\.jsdelivr\.net\/npm\/bootstrap[@-]/.test(
            s.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '')));
}

check('download-vendor.sh fetches bootstrap-icons (its CSS url()s the font files relatively, '
    + 'so a CSS-only fallback renders empty boxes)',
    /bootstrap-icons@[\d.]+\/font\/bootstrap-icons\.min\.css/.test(vendorSh)
    && /bootstrap-icons\.woff2/.test(vendorSh));

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
