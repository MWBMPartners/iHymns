/**
 * iHymns — duplicate-song contract guard (#1783)
 *
 * ELI5
 * ----
 * The editor's "Duplicate" button, the `?duplicate=` cold-load link and the
 * server `duplicate_song` endpoint are three files that must agree, plus one
 * rule the client must never break: it must NEVER hard-code the hidden staging
 * songbook's abbreviation ('PENDING') — that name arrives from the server so it
 * can be renamed in one place. This checks all four still hold.
 *
 * WHY THIS EXISTS
 * ---------------
 * Duplicate is a cross-file feature (rule #35): client method → server case →
 * shell deep-link. Any one of them silently drifting is the quiet-failure class
 * this repo keeps re-learning (#1623/#1628/#1680, #1677). And the staging-book
 * abbr is a five-consumer contract (rule #27/#35): the moment a client file
 * types 'PENDING' instead of reading `load_index.pendingSongbook`, a rename
 * breaks the client and nothing goes red. So we ban the literal here.
 *
 * DERIVED + MUTATION-PROVEN (rule #34): each leg was proven able to fail —
 * rename the case / drop the entitlement / type 'PENDING' in the client, watch
 * the matching assertion go red, restore.
 *
 *   node tests/test-editor-duplicate-contract.js
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

/* Strip HTML + C-style block comments AND // line comments, so a `'PENDING'`
   or a `case 'duplicate_song'` MENTIONED in a comment can never satisfy (or
   trip) an assertion about the live code. */
const strip = (s) => s
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1');

console.log('\n#1783 — duplicate-song contract\n');

/* ---- 1. client api method posts the right action ------------------------ */
const apiClient = strip(readFileSync(join(PUB, 'manage/editor/v2/api-client.js'), 'utf8'));
check("api-client.js exposes duplicateSong() posting the 'duplicate_song' action",
    /duplicateSong\s*:[^\n]*postJson\(\s*['"]duplicate_song['"]/.test(apiClient));

/* ---- 2. server handler exists and is entitlement-gated ------------------ */
const api2 = strip(readFileSync(join(PUB, 'manage/editor/api2.php'), 'utf8'));
check("api2.php has case 'duplicate_song'",
    /case\s+['"]duplicate_song['"]\s*:/.test(api2));
/* The FIRST statement of the case body must be the edit_songs gate — a
   content-writing duplicate is not an anonymous action (plan §5). Bound the
   match to the case opening so a distant edit_songs gate elsewhere can't
   satisfy it. */
check("case 'duplicate_song' is gated by ed2_requireEntitlement('edit_songs') as its first statement",
    /case\s+['"]duplicate_song['"]\s*:\s*\{\s*ed2_requireEntitlement\(\s*['"]edit_songs['"]\s*\)/.test(api2));

/* ---- 3. shell reads the ?duplicate= deep-link --------------------------- */
/* Asserted explicitly (not only via test-editor-deep-links.js's tree
   derivation) because no page emits ?duplicate= YET — the read must be guarded
   before the first emitter exists, exactly like the ?open= alias. */
const shell = strip(readFileSync(join(PUB, 'manage/editor/editor2.php'), 'utf8'));
check('editor2.php reads $_GET[\'duplicate\'] (the cold-load duplicate entry, #1783)',
    /\$_GET\[\s*['"]duplicate['"]\s*\]/.test(shell));
check('editor2.php calls duplicateSong() (the button + deep-link both route through it)',
    /duplicateSong\s*\(/.test(shell));

/* ---- 4. the client NEVER hard-codes the staging-book abbr --------------- */
/* 'PENDING' is a server-owned contract (ED2_PENDING_SONGBOOK in api2.php only;
   the client receives load_index.pendingSongbook / load_song.isPendingDuplicate).
   A client-side literal would silently break on a rename. Scan every v2 client
   file + the shell. */
const clientFiles = [
    'manage/editor/v2/api-client.js',
    'manage/editor/v2/metadata-tab.js',
    'manage/editor/v2/sidebar.js',
    'manage/editor/editor2.php',
];
for (const rel of clientFiles) {
    let src;
    try { src = strip(readFileSync(join(PUB, rel), 'utf8')); }
    catch { continue; }   // file may not exist in every checkout stage
    check(`${rel} does NOT hard-code the 'PENDING' staging-book abbr (must come from the server)`,
        !/['"]PENDING['"]/.test(src));
}

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nDuplicate is a cross-file feature (client method → server case → shell deep-link).');
    console.error('If one leg drifts, the button silently does nothing (rule #35). And a client that');
    console.error("types 'PENDING' breaks on a staging-book rename with nothing red (rule #27/#35).");
    process.exit(1);
}
console.log('\nAll duplicate-song contract assertions passed.');
