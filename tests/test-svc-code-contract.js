/**
 * iHymns — `svc_code` deep-link contract guard (#1770 G6, rule #33)
 * ============================================================================
 * ELI5: a URL parameter another page emits is a contract — if a page hands
 * out a link with `?svc_code=ABC123` on it and NOTHING ever reads that
 * param back, the tap/scan just silently does nothing. This checks that
 * every place in the tree that EMITS a `svc_code` query param has at least
 * one place that READS it.
 *
 * DETAILED — this is the exact standing violation #1770's own analysis
 * flagged (P5): `manage/service-projection.php`'s join QR has emitted
 * `/?svc_code=<CODE>` since #1339, and until #1770 C6 nothing in the tree
 * ever read it back — the projection QR silently did nothing useful beyond
 * "open the site" for the ~5 weeks it was live. #1770 C6 adds the SECOND
 * emitter (`live-follow.js`'s "show code" big-code+QR view, #1770 C6) AND
 * the ONE reader (`service-follow.js`'s `_readSvcCodeParam()`, wired from
 * `init()` — booted on every load).
 *
 * DERIVATION (tree-derived — rule #34, never a typed-in file list): every
 * `.php` and `.js` file under `appWeb/public_html/` is scanned for
 *   - an EMITTER: `svc_code=` immediately preceded by `?` or `&` inside a
 *     string literal — i.e. building a URL query string.
 *   - a READER: `.get('svc_code')` / `.get("svc_code")` — the
 *     `URLSearchParams#get()` shape a client-side reader must use.
 * The assertion is exactly rule #33's own wording: an emitter with ZERO
 * readers anywhere in the tree FAILS. (The reverse — a reader with no
 * emitter — is not a violation of anything; a reader tolerating a param
 * nobody currently emits is harmless future-proofing, not a contract gap.)
 *
 * THIS GUARD'S OWN MUTATION PROOF, PER THE PLAN'S OWN INSTRUCTION: "This
 * guard fails RED on today's tree until C6 lands — order it so its first
 * run proves it can fail." Since C6 (the reader) lands in the SAME commit
 * sequence as this guard, its first run here is already green — the
 * red-then-green proof instead comes from a MUTATION: temporarily deleting
 * the reader call site reproduces the pre-C6 tree and turns this guard red;
 * restoring it goes green again (see the commit body for the exact mutation
 * run).
 *
 * Usage: node tests/test-svc-code-contract.js
 *        IHYMNS_SCAN_ROOT=/path/to/tree node tests/test-svc-code-contract.js
 *
 * @see appWeb/public_html/manage/service-projection.php  emitter #1
 * @see appWeb/public_html/js/modules/live-follow.js      emitter #2 (#1770 C6)
 * @see appWeb/public_html/js/modules/service-follow.js   the ONE reader (#1770 C6)
 * @see .claude/live-follow-1770-plan.md §6.5, §9 G6
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');

const scanRoot = process.env.IHYMNS_SCAN_ROOT || path.join(PROJECT_ROOT, 'appWeb', 'public_html');

let failures = 0;
let passed = 0;
function check(cond, label) {
    if (cond) { passed++; console.log('PASS: ' + label); }
    else { failures++; console.error('FAIL: ' + label); }
}

/** Recursively list every .php/.js file under `dir`, skipping vendor/node_modules. */
function walk(dir, out) {
    let entries;
    try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
    for (const e of entries) {
        if (e.name === 'vendor' || e.name === 'node_modules' || e.name.startsWith('.')) { continue; }
        const full = path.join(dir, e.name);
        if (e.isDirectory()) { walk(full, out); }
        else if (e.isFile() && (e.name.endsWith('.php') || e.name.endsWith('.js'))) { out.push(full); }
    }
}

if (!fs.existsSync(scanRoot)) {
    console.error('FATAL: could not read ' + scanRoot);
    process.exit(1);
}

const files = [];
walk(scanRoot, files);
check(files.length > 0, `0.1 found ${files.length} .php/.js file(s) under ${scanRoot}`);

const emitters = [];
const readers = [];
const EMITTER_RE = /[?&]svc_code=/g;
const READER_RE = /\.get\(\s*['"]svc_code['"]\s*\)/g;

for (const file of files) {
    let src;
    try { src = fs.readFileSync(file, 'utf8'); } catch { continue; }
    if (src.indexOf('svc_code') === -1) { continue; }
    const rel = path.relative(scanRoot, file);

    let m;
    EMITTER_RE.lastIndex = 0;
    while ((m = EMITTER_RE.exec(src)) !== null) {
        const line = src.slice(0, m.index).split('\n').length;
        emitters.push(`${rel}:${line}`);
    }
    READER_RE.lastIndex = 0;
    while ((m = READER_RE.exec(src)) !== null) {
        const line = src.slice(0, m.index).split('\n').length;
        readers.push(`${rel}:${line}`);
    }
}

check(emitters.length > 0, `0.2 found ${emitters.length} svc_code emitter(s) (the scanner works) — ${emitters.join(', ')}`);

check(
    emitters.length === 0 || readers.length > 0,
    'G6 every svc_code emitter has at least one reader somewhere in the tree (rule #33) — '
        + `emitters: ${emitters.join(', ') || '(none)'} | readers: ${readers.join(', ') || '(none)'}`
);

console.log(`\n${passed} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
