/**
 * tests/test-qr-cuercode.js — QR-via-CueRCode wiring guard (owner directive 2026-08-05)
 *
 * PURPOSE
 * ELI5: makes sure every QR code in iHymns is drawn by our CueRCode service
 * (through the /qr.php door) and that we did NOT leave any old in-the-browser QR
 * library lying around — and, most importantly, that CueRCode's secret key can
 * only ever be used by the server, never handed to a browser.
 *
 * DETAIL
 * This replaces the retired tests/test-qr.js, which guarded the now-removed
 * vendored `qrcode-generator` client library. The owner's directive is that QR
 * generation goes through the CueRCode API (github.com/MWBMPartners/CueRCode)
 * server-side, so this guard asserts the new shape and BANS the old one:
 *
 *   1. The server client `includes/cuercode_client.php` exists and sends the
 *      `X-API-Key` header — and that header appears in NO client-side JS file
 *      (the key must never reach a browser).
 *   2. The `/qr.php` endpoint exists, calls cuercodeGenerate(), and is the ONLY
 *      thing that talks to the client (streams bytes, never JSON with the key).
 *   3. BOTH QR surfaces render via `/qr.php`:
 *        - js/modules/print.js  (the #1767 R print block)
 *        - manage/service-projection.php (the #1339 congregant join QR)
 *   4. NO client-side QR library remains anywhere under appWeb/public_html:
 *      no `qrcode-generator` / `qrcode.mjs` reference, no `createSvgTag(` call,
 *      no `'qrcodegen' =>` config entry, no `QR_LIB` symbol. (These are the
 *      fingerprints of the retired approach; a stray one means a surface was
 *      migrated but its old code left behind — a split-brain QR path.)
 *
 * Tree-derived (globs the JS tree for #4) and mutation-proven: re-introduce any
 * banned fingerprint, or break either surface's /qr.php wiring, and this goes
 * red; the current tree is green.
 *
 *   node tests/test-qr-cuercode.js
 *
 * Exit 0 = wired correctly, 1 = drift.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const read = (p) => fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';

/* Blank `/* … *\/` and `<!-- … -->` comment bodies before the banned-fingerprint
   scan — LOAD-BEARING (rule #34 / the test-fragment-inline-scripts.php lesson):
   the retirement work itself leaves explanatory comments that MENTION
   `qrcode-generator` ("the vendored qrcode-generator entry was REMOVED"), and a
   raw scan would flag those legitimate comments as if the library were still in
   use. We ban USAGE, not the word. Newlines are preserved so nothing shifts. */
function stripComments(src) {
    src = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    src = src.replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
    return src;
}

const clientPhp   = read(path.join(PUB, 'includes', 'cuercode_client.php'));
const qrPhp       = read(path.join(PUB, 'qr.php'));
const printJs     = read(path.join(PUB, 'js', 'modules', 'print.js'));
const projPhp     = read(path.join(PUB, 'manage', 'service-projection.php'));

console.log('QR-via-CueRCode wiring:');

/* 1. server client exists + sends X-API-Key */
check('includes/cuercode_client.php exists', clientPhp !== '');
check('client sends the X-API-Key header (server-side auth)',
    /X-API-Key:\s*'\s*\.\s*\$config\['api_key'\]/.test(clientPhp) || /X-API-Key/.test(clientPhp));

/* 2. /qr.php endpoint exists + uses the CACHED client wrapper (#1920 C3 —
      qr.php reads through tblQrCache via cuercodeGenerateCached() instead of
      calling the raw HTTP function directly; see check 5 below for the
      "nobody else calls the raw function" inverse). */
check('qr.php exists', qrPhp !== '');
check('qr.php calls cuercodeGenerateCached()', /cuercodeGenerateCached\s*\(/.test(qrPhp));
check('qr.php streams image bytes (Content-Type from CueRCode)',
    /header\('Content-Type: '\s*\.\s*\$qr\['mime'\]\)/.test(qrPhp) || /Content-Type/.test(qrPhp));

/* 3. both QR surfaces render via /qr.php */
check('print.js QR block points at /qr.php', /\/qr\.php\?data=/.test(printJs),
    'print.js renderBlock(\'qr\') must emit an <img> src of /qr.php?data=…');
check('service-projection.php QR points at /qr.php', /\/qr\.php\?data=/.test(projPhp),
    'service-projection.php renderQr() must emit an <img> src of /qr.php?data=…');

/* 4. NO client-side QR library fingerprints remain anywhere under public_html.
      Derive the JS/PHP file list from the tree (rule #34), skip the vendor dir
      and this test itself. */
function walk(dir, acc = []) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
        if (ent.name === 'vendor' || ent.name === 'node_modules' || ent.name === '.git') { continue; }
        const full = path.join(dir, ent.name);
        if (ent.isDirectory()) { walk(full, acc); }
        else if (/\.(js|php)$/.test(ent.name)) { acc.push(full); }
    }
    return acc;
}
const BANNED = [
    { re: /qrcode-generator/,   label: 'qrcode-generator (vendored lib name)' },
    { re: /qrcode\.mjs/,        label: 'qrcode.mjs (vendored ES module)' },
    { re: /createSvgTag\s*\(/,  label: 'createSvgTag( (qrcode-generator API)' },
    { re: /'qrcodegen'\s*=>/,   label: "'qrcodegen' => (config libraries entry)" },
    { re: /\bQR_LIB\b/,         label: 'QR_LIB (old client-side lib handle)' },
];
const files = walk(PUB);
const offenders = [];
for (const f of files) {
    const src = stripComments(read(f));   // comments may legitimately MENTION the retired lib
    for (const b of BANNED) {
        if (b.re.test(src)) { offenders.push(path.relative(REPO, f) + '  →  ' + b.label); }
    }
}
check('no client-side QR-library fingerprints remain under appWeb/public_html',
    offenders.length === 0, offenders.join('\n      '));
check('scanned a plausible number of files (parser sanity)', files.length >= 50,
    `only ${files.length} js/php files walked — the tree walk under-read`);

/* 5. The INVERSE of check 2 (#1920 C3) — the raw `cuercodeGenerate(` call
      shape (the stem immediately followed by an opening paren; NOT
      `cuercodeGenerateCached(`, whose next character after the stem is `C`,
      not `(`) must appear in NO file except cuercode_client.php itself
      (its own definition, plus cuercodeGenerateCached()'s one internal call
      to the untouched HTTP path). Reuses the SAME tree-derived `files` list
      and comment-stripping as check 4, so a doc-comment that merely MENTIONS
      "cuercodeGenerate()" in prose (several files explain the relationship
      to the cached wrapper this way) can never false-positive. */
const RAW_CALL = /cuercodeGenerate\s*\(/;
const rawCallSites = [];
for (const f of files) {
    const src = stripComments(read(f));
    if (RAW_CALL.test(src) && path.basename(f) !== 'cuercode_client.php') {
        rawCallSites.push(path.relative(REPO, f));
    }
}
check('no file other than includes/cuercode_client.php calls the raw cuercodeGenerate()',
    rawCallSites.length === 0, rawCallSites.join('\n      '));

if (failures) {
    console.error(`\nFAIL: ${failures} QR-via-CueRCode wiring check(s) failed.`);
    process.exit(1);
}
console.log(`\nOK: QR via CueRCode wired on both surfaces; no client-side QR library remains (${files.length} files scanned).`);
