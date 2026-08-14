/**
 * tests/test-analytics-clarity-dnt.js — Clarity must never session-record a DNT user (#1852)
 *
 * ELI5: Microsoft Clarity is a "watch a recording of what the visitor did"
 * tool (mouse, clicks, scrolling), which is far more invasive than the other
 * analytics (which just count page views). If a visitor's browser says "Do Not
 * Track", Clarity must NOT run at all. The other tools (Google Analytics,
 * Matomo) are allowed to run under Do-Not-Track *in a privacy-safe mode* — they
 * anonymise/soften — but Clarity has no such mode, so for it, Do-Not-Track has
 * to mean OFF. This guard reads index.php and makes sure the Clarity loader
 * gates on "consent granted AND not Do-Not-Track", and can never regress back to
 * the old "load if consent OR do-not-track" that recorded the very users who
 * opted out.
 *
 * WHY THIS EXISTS
 * ----------------
 * index.php loads four analytics tools behind a shared `consent === 'granted' ||
 * dnt` gate — the design intent being "a DNT user is in privacy mode, so load
 * without a consent banner but in a privacy-safe configuration." GA4 honours
 * that with anonymize_ip + storage:'none'; Matomo with setDoNotTrack; Plausible
 * is cookieless. Clarity got the `|| dnt` load-gate copied but NOT a matching
 * mitigation, so a Do-Not-Track user was the one user getting full session
 * recording — a silent GDPR/consent exposure the moment a clarity_id is
 * configured (#1852). The failure is invisible (no error, dormant until
 * configured), exactly the class that needs a build-time guard, not a comment.
 * https://learn.microsoft.com/en-us/clarity/setup-and-installation/cookie-consent
 *
 * WHAT IS CHECKED (tree-derived from index.php, mutation-proven)
 * -------------------------------------------------------------
 *   1. The Clarity loader block exists and is non-trivial (loads clarity.ms).
 *   2. It does NOT contain a `|| dnt` gate (the #1852 bug — DNT triggering the load).
 *   3. It gates on `consent === 'granted' && !dnt` (consent AND not-DNT).
 *
 * SCOPE/LIMITS: static source analysis of the Clarity block only; it does not
 * execute the page. Runs under `node tools/run-node-tests.js`.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const INDEX_PHP = path.resolve(__dirname, '..', 'appWeb/public_html/index.php');

let failures = 0;
const fail = (m) => { console.log(`  ❌ ${m}`); failures++; };
const pass = (m) => { console.log(`  ✔ ${m}`); };

let src = '';
try {
    src = fs.readFileSync(INDEX_PHP, 'utf8');
} catch (e) {
    console.log(`FATAL: could not read index.php: ${e.message}`);
    process.exit(1);
}

/* Isolate the Clarity loader block: from its `clarity_id` PHP-if opener to the
   next `<?php endif; ?>`. Deriving the exact block (not scanning the whole file)
   keeps the guard from being fooled by the CSP host allow-list or the config
   bridge, which also mention Clarity. */
const startIdx = src.indexOf("APP_CONFIG['analytics']['clarity_id']");
const endifIdx = startIdx === -1 ? -1 : src.indexOf('<?php endif; ?>', startIdx);
const block = (startIdx !== -1 && endifIdx !== -1) ? src.slice(startIdx, endifIdx) : '';

console.log('Clarity DNT gating (#1852)');

/* 1 — non-vacuity: the block must exist and actually load Clarity, or the
   later assertions would vacuously "pass" against an empty string. */
if (!block || !/clarity\.ms\/tag/.test(block)) {
    fail('could not locate the Clarity loader block in index.php (or it no longer loads clarity.ms) — guard cannot run');
    console.log('');
    console.log('analytics-clarity-dnt: guard could not verify.');
    process.exit(1);
}
pass('located the Clarity loader block');

/* Strip comments before scanning for the GATE tokens — the explanatory
   doc-comment legitimately DESCRIBES the `|| dnt` it forbids, and a comment
   mentioning it must never be read as the code doing it (the rule #34
   under/over-report trap: an earlier revision of THIS guard matched its own
   comment). HTML <!--…-->, JS block /*…*​/, and PHP <?…?> are removed; the
   real `if (…) {` gate is code and survives. */
const blockCode = block
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/<\?[\s\S]*?\?>/g, ' ');

/* 2 — the #1852 bug must be absent: DNT must never be an OR-trigger for load. */
if (/\|\|\s*dnt\b/.test(blockCode)) {
    fail("Clarity loader gates on `|| dnt` — a Do-Not-Track user would be session-recorded (#1852 regression)");
} else {
    pass('Clarity loader does not load on `|| dnt`');
}

/* 3 — the fix must be present: consent AND not-DNT. */
if (/consent\s*===\s*'granted'\s*&&\s*!\s*dnt\b/.test(blockCode)) {
    pass("Clarity loader gates on `consent === 'granted' && !dnt`");
} else {
    fail("Clarity loader must gate on `consent === 'granted' && !dnt` (consent AND not Do-Not-Track)");
}

console.log('');
if (failures) {
    console.log(`analytics-clarity-dnt: ${failures} check(s) failed.`);
    process.exit(1);
}
console.log('analytics-clarity-dnt: all checks passed.');
process.exit(0);
