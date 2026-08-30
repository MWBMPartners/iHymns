/**
 * iHymns — v2 Song Editor Arrangement UI guard (#1627 item 2, #1601, #1618)
 *
 * ELI5
 * ----
 * The v2 editor just grew the arrangement (running-order) control — the last
 * day-one blocker before the v2 default flip. This file checks that the
 * client agrees with the server it talks to, that it never re-introduces the
 * two named red flags (#1587's unpinned CDN script, #1644's dropped-focus
 * drag regression), and that it never trusts its own guess about what the
 * server actually stored.
 *
 * WHAT IT ASSERTS
 *   (1) api-client.js exposes `updateArrangement`, and its action string has
 *       a REAL `case '...':` in api2.php — not a typo that would 400/404 at
 *       click time with no build-time signal (same technique
 *       tests/test-v2-enrichment-ui.js uses for the line-enrichment methods).
 *   (2) arrangement-editor.js never references SortableJS and never injects
 *       a `<script>` tag — the #1587/#1647 red flag (unpinned, unhashed
 *       third-party code loaded into an authenticated admin session). v1's
 *       arrangement editor is the thing being replaced BECAUSE it does this;
 *       regressing to it here would reintroduce the exact problem the task
 *       exists to avoid.
 *   (3) move() re-queries the moved chip's controls at their NEW position
 *       and calls .focus() on one of them — the #1644 regression (rebuilding
 *       the strip's innerHTML on every change silently drops keyboard focus
 *       to <body> if nothing re-focuses the moved control).
 *   (4) The module imports the shared assistive-tech announcer
 *       (js/utils/announce.js, #1645) rather than re-implementing its own
 *       aria-live region or its own `announce()` — the modularity rule.
 *   (5) attemptSet() derives the value it writes into the store from the
 *       SERVER's response (`res.arrangement`), never from the local
 *       tentative array it sent — "do not optimistically trust the local
 *       array" from the task brief, verified structurally.
 *   (6) #1991 — the ONE shared `iconBtn()` guard. Tree-derived (glob every
 *       *.js directly under manage/editor/v2/, rule #34) rather than a
 *       hand-typed three-file list, so a FOURTH copy showing up anywhere in
 *       the directory in future is caught too, not just a regression of the
 *       three known ones: exactly one `function iconBtn(` definition may
 *       exist anywhere under manage/editor/v2/ (in ui-helpers.js), that one
 *       definition sets `aria-label` from `title` (the #1990 a11y sweep's
 *       behaviour the extraction must not lose), and media-tab.js /
 *       structure-tab.js / arrangement-editor.js each import it from
 *       './ui-helpers.js' rather than redefining it locally.
 *
 * Every assertion here was mutation-tested against a deliberately broken
 * copy of arrangement-editor.js (see the session report for the pass/fail
 * pairs) before being trusted — an assertion that cannot be proven to fail
 * is not a test. Assertion (6) was mutation-checked the same way when added
 * (#1991): temporarily re-adding a second `function iconBtn(...) { ... }`
 * definition into structure-tab.js sent the "exactly one definition" check
 * RED (reporting both files), and temporarily changing ui-helpers.js's
 * `setAttribute('aria-label', title)` to a hardcoded string sent the
 * aria-label check RED; both were reverted and reverified GREEN before this
 * guard was trusted.
 *
 *   node tests/test-v2-arrangement-ui.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const PUB = join(ROOT, 'appWeb', 'public_html');
const EDITOR_V2 = join(PUB, 'manage', 'editor', 'v2');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

/* Strip comments before matching, on BOTH sides. This suite's own doc-blocks
   (and the source files' doc-blocks) discuss the exact strings under test at
   length — matching raw source would let prose satisfy an assertion that is
   supposed to be about code. Mirrors tests/test-vendor-sri.js and
   tests/test-v2-enrichment-ui.js's identical stripping approach. */
const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const stripPhpBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

const clientSrc = readFileSync(join(EDITOR_V2, 'api-client.js'), 'utf8');
const arrSrc    = readFileSync(join(EDITOR_V2, 'arrangement-editor.js'), 'utf8');
const apiSrc    = readFileSync(join(PUB, 'manage', 'editor', 'api2.php'), 'utf8');

const client = stripJs(clientSrc);
const arr    = stripJs(arrSrc);
const api    = stripPhpBlock(apiSrc);

console.log('\n#1627 item 2 — v2 editor Arrangement UI\n');

/* ---- 1. api-client.js exposes updateArrangement; action string is real -- */

const serverActions = new Set(
    Array.from(api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/gi)).map((m) => m[1])
);
check('parsed a plausible number of api2.php server cases (>= 20)', serverActions.size >= 20);

const updateArrMatch = client.match(/updateArrangement\s*:\s*\([^)]*\)\s*=>\s*postJson\(\s*'([a-z0-9_]+)'/);
check("api-client.editorApi.updateArrangement exists and calls postJson('arrangement_update', …)",
    updateArrMatch !== null && updateArrMatch[1] === 'arrangement_update');
check("api2.php has a real `case 'arrangement_update':` (would 400 at click time otherwise)",
    serverActions.has('arrangement_update'));

/* ---- 2. no SortableJS, no injected <script> (the #1587/#1647 red flag) -- */

check('arrangement-editor.js does not reference SortableJS in any form',
    !/sortable/i.test(arr));
check('arrangement-editor.js does not dynamically create a <script> element',
    !/createElement\(\s*['"]script['"]\s*\)/i.test(arr));
check('arrangement-editor.js does not reference a CDN URL',
    !/https?:\/\/cdn\./i.test(arr));
check('arrangement-editor.js does not set the HTML5 `draggable` attribute anywhere',
    !/\bdraggable\b/i.test(arr));

/* ---- 3. move() refocuses the moved chip's controls at their NEW position  */

/* Extracts a top-level function body by matching up to the first line that
   closes at 4-space indent — every function in this file is a 4-space-
   indented method of mountArrangementEditor(), and every brace nested
   inside one is indented deeper than that, so this does not stop early on
   an inner `}` the way a naive non-greedy match to the first `}` would. */
function functionBody(src, signature) {
    const re = new RegExp(signature.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ' \\{([\\s\\S]*?)\\n {4}\\}\\n');
    const m = src.match(re);
    return m ? m[1] : '';
}

const moveBody = functionBody(arr, 'async function move(pos, dir)');
check('parsed a non-trivial move() function body', moveBody.length > 40);
check('move() calls .focus() (refocusing after the re-render — the #1644 fix)',
    /\.focus\(\)/.test(moveBody));
check("move() targets the chip's NEW position (newPos), not its old one, when refocusing",
    /data-pos="'\s*\+\s*newPos\s*\+\s*'"/.test(moveBody));
check('move() computes newPos before using it to refocus (guards against a hollow newPos reference)',
    /const\s+newPos\s*=\s*pos\s*\+\s*dir\s*;/.test(moveBody));

/* ---- 4. reuses the shared announcer, never reimplements one ------------ */

check("arrangement-editor.js imports { announce } from the shared js/utils/announce.js module",
    /import\s*\{\s*announce\s*\}\s*from\s*['"][^'"]*\/js\/utils\/announce\.js['"]/.test(arrSrc));
check('arrangement-editor.js does not define its own announce() function',
    !/function\s+announce\s*\(/.test(arr));
check('arrangement-editor.js does not build its own aria-live region (that belongs to announce.js alone)',
    !/aria-live/i.test(arr));

/* ---- 5. store write uses the SERVER's echoed value, never the local array */

const attemptSetBody = functionBody(arr, 'async function attemptSet(arr, announceMsg)');
check('parsed a non-trivial attemptSet() function body', attemptSetBody.length > 40);
check('attemptSet() derives `stored` from the SERVER response (res.arrangement), not from the request',
    /const\s+stored\s*=\s*Array\.isArray\(\s*res\.arrangement\s*\)/.test(attemptSetBody));
check("attemptSet() writes store.update('song', …) using `stored`",
    /store\.update\(\s*'song'[\s\S]{0,200}stored/.test(attemptSetBody));
check("attemptSet()'s store write never uses the local `arr` parameter as the ArrangementJson value (would be trusting the unconfirmed local array)",
    !/ArrangementJson:\s*arr\b/.test(attemptSetBody));
check('attemptSet() only writes to the store inside the try (success) branch, never inside catch (failure)',
    !/catch \(e\) \{[\s\S]*store\.update/.test(attemptSetBody));

/* ---- failure kinds come from the STATUS, not the server's prose ----------
 *
 * The 409 (migration not run) and 422 (empty sections) branches drive calm
 * explanatory UI instead of a red toast, so the client has to tell them apart.
 * It originally did that by matching the server's SENTENCES — with a comment
 * conceding "if that wording ever changes, update these two together".
 *
 * That is a comment where a mechanism belongs, and it fails in the quietest
 * possible way: reword a server error, and the explanatory UI silently
 * degrades to a generic toast. Nothing throws, no test goes red, the feature
 * still "works". Same family as #1581's dispatch/listen mismatch.
 *
 * So unwrap() now attaches `status` to the thrown Error, the client branches on
 * the status code (part of the endpoint's documented contract), and the numbers
 * are tied HERE to what api2.php's arrangement_update case actually responds
 * with. Both sides derived from source.
 */
check('api-client unwrap() attaches the HTTP status to the thrown Error '
    + '(without it, callers can only branch on prose)',
    /err\.status\s*=\s*res\.status/.test(client));

/* Pull the arrangement_update case body out of api2.php and collect the status
   codes it actually responds with. */
const caseStart = api.indexOf("case 'arrangement_update'");
const caseBody = caseStart === -1 ? '' : api.slice(caseStart, caseStart + 4000);
const serverCodes = new Set(
    [...caseBody.matchAll(/ed2_respond\([\s\S]*?,\s*(\d{3})\s*\)/g)].map((m) => m[1])
);

check('api2.php arrangement_update was located and responds with status codes',
    caseBody !== '' && serverCodes.size > 0);

for (const [code, what] of [['409', 'migration not run'], ['422', 'empty sections']]) {
    check(`api2.php arrangement_update still responds ${code} (${what})`,
        serverCodes.has(code));
    check(`arrangement-editor.js branches on status ${code}, not on the server's wording`,
        new RegExp(`status\\s*===\\s*${code}`).test(arr));
}

/* Deliberately narrow: it looks for the pattern-MATCHING construct
   (`/…prose…/.test(`), not for the phrases themselves. The explanatory UI
   legitimately SAYS "empty sections" to the curator — that copy is the whole
   point of the 422 branch. A blunter check confusing user-facing copy with a
   regex against server prose fails on correct code, which is how a guard gets
   weakened or deleted rather than fixed. */
check('arrangement-editor.js does not regex-match the server error prose to decide failure kind',
    !/\/[^/\n]*(?:migration has not been run|empty sections)[^/\n]*\/[a-z]*\s*\.test\s*\(/i.test(arr));

/* ---- 6. #1991 — iconBtn() is the ONE shared editor-v2 helper -------------
 *
 * Tree-derived (glob manage/editor/v2/*.js, not a typed three-file list) so
 * a NEW file that redefines iconBtn locally in future is caught the same
 * way a regression of one of the three known copies would be.
 */

console.log('\n#1991 — iconBtn() consolidated into ui-helpers.js\n');

const DEFN_RE = /\bfunction\s+iconBtn\s*\(/g;

const v2Files = readdirSync(EDITOR_V2)
    .filter((name) => name.endsWith('.js'))
    .sort();
check('scanned a plausible number of editor-v2 .js files (parser sanity, >= 10)', v2Files.length >= 10);

const definitionSites = [];
for (const name of v2Files) {
    const stripped = stripJs(readFileSync(join(EDITOR_V2, name), 'utf8'));
    const hits = stripped.match(DEFN_RE);
    if (hits) { definitionSites.push({ name, count: hits.length }); }
}
const totalDefinitions = definitionSites.reduce((n, d) => n + d.count, 0);

check('exactly ONE `function iconBtn(` definition exists under manage/editor/v2/'
        + (totalDefinitions === 1 ? '' : ` (found: ${definitionSites.map((d) => `${d.name} x${d.count}`).join(', ') || 'none'})`),
    totalDefinitions === 1);
check('the one surviving definition lives in ui-helpers.js (not a stray copy elsewhere)',
    definitionSites.length === 1 && definitionSites[0].name === 'ui-helpers.js');

const uiHelpersSrc = stripJs(readFileSync(join(EDITOR_V2, 'ui-helpers.js'), 'utf8'));
check("ui-helpers.js's iconBtn() sets aria-label FROM title (the #1990 a11y sweep behaviour)",
    /setAttribute\(\s*'aria-label'\s*,\s*title\s*\)/.test(uiHelpersSrc));
check('ui-helpers.js exports iconBtn (import { iconBtn } from \'./ui-helpers.js\' must resolve)',
    /export\s+function\s+iconBtn\s*\(/.test(uiHelpersSrc));

/* Each of the three known consumers imports the shared helper rather than
   redefining it — checked individually (not just via the tree-wide count
   above) so a failure here names the exact file that regressed. */
for (const consumer of ['media-tab.js', 'structure-tab.js', 'arrangement-editor.js']) {
    const src = readFileSync(join(EDITOR_V2, consumer), 'utf8');
    check(`${consumer} imports iconBtn from './ui-helpers.js'`,
        /import\s*\{\s*iconBtn\s*\}\s*from\s*['"]\.\/ui-helpers\.js['"]/.test(src));
}

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nThe v2 Arrangement editor must stay in lock-step with api2.php\'s action name,');
    console.error('must never reintroduce the #1587 unpinned-CDN pattern or the #1644 dropped-focus');
    console.error('regression, must reuse the shared announcer, and must only ever persist what the');
    console.error('server actually confirms it stored.');
    process.exit(1);
}
console.log('\nAll v2 Arrangement UI contract assertions passed.');
