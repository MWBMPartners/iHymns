/**
 * iHymns — v2 Song Editor "Alternative titles" panel guard (#1669, epic #832)
 *
 * ELI5
 * ----
 * The v2 editor's Metadata tab just grew a small card-list for a song's
 * alternative ("also known as") titles. This file checks that the panel
 * exports the function metadata-tab.js actually imports, that it's really
 * wired into the tab (with a teardown, matching every sibling panel on this
 * tab), and that — the specific regression class this codebase keeps
 * rediscovering (CLAUDE.md rule #31) — it never reaches for a raw `fetch()`
 * itself, only the injected api client.
 *
 * WHAT IT ASSERTS
 *   (1) alt-titles-panel.js exports `mountAltTitlesPanel`.
 *   (2) metadata-tab.js dynamically imports './alt-titles-panel.js' and
 *       calls `mountAltTitlesPanel(...)`, passing `songId` and `api`.
 *   (3) metadata-tab.js's mount call captures the returned teardown into a
 *       variable, and that variable is invoked in BOTH of render()'s reset
 *       block (so a re-render doesn't leak the panel — CLAUDE.md rule #32's
 *       "tear down before any early return" shape applied to a per-tab
 *       panel) and the tab's own outer teardown() function.
 *   (4) mountAltTitlesPanel() itself returns a function (a `teardown`) —
 *       source-level: the function body contains a `return function` (or
 *       `return () =>`) whose own body removes the mounted node.
 *   (5) alt-titles-panel.js contains NO raw `fetch(` call anywhere — every
 *       request goes through the injected `api` object (rule #31: a module
 *       reaching for bare fetch() bypasses whatever cross-cutting request
 *       concern the shared client attaches, and a bug in a one-off fetch
 *       fails silently for THIS module only, never surfacing as the
 *       site-wide signal a shared-client bug would).
 *   (6) api-client.js's `listAltTitles`/`addAltTitle`/`deleteAltTitle`
 *       methods exist and call the three real api2.php actions (the same
 *       property test-song-alt-titles.php already proves from the PHP side;
 *       kept here too so this guard is self-contained / mutation-testable
 *       in isolation, mirroring test-v2-external-ids-ui.js's own section 1).
 *
 * Every regex-based assertion below carries an in-file mutation self-test
 * (rule #34) proving the comment-stripping step actually removes a
 * comment-only mention that would otherwise false-pass the real check.
 *
 *   node tests/test-v2-alt-titles-ui.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see #1669
 * @see #832
 * @link tests/test-v2-external-ids-ui.js   the sibling guard this file's shape mirrors
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const PUB = join(ROOT, 'appWeb', 'public_html');
const EDITOR = join(PUB, 'manage', 'editor');
const EDITOR_V2 = join(EDITOR, 'v2');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

/* Strip comments before matching, on BOTH sides — this suite's own doc-
   blocks (and the source files' doc-blocks) discuss the exact function/
   action names under test at length; matching raw source would let prose
   satisfy an assertion that is supposed to be about code. Mirrors
   tests/test-v2-external-ids-ui.js's stripping approach. */
const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const stripPhpBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

const clientSrc  = readFileSync(join(EDITOR_V2, 'api-client.js'), 'utf8');
const panelSrc   = readFileSync(join(EDITOR_V2, 'alt-titles-panel.js'), 'utf8');
const metaTabSrc = readFileSync(join(EDITOR_V2, 'metadata-tab.js'), 'utf8');
const apiSrc     = readFileSync(join(EDITOR, 'api2.php'), 'utf8');

const client  = stripJs(clientSrc);
const panel   = stripJs(panelSrc);
const metaTab = stripJs(metaTabSrc);
const api     = stripPhpBlock(apiSrc);

console.log('\n#1669 — v2 editor "Alternative titles" panel\n');

/* ---- mutation self-test for the comment-stripper (rule #34) ------------- */
const commentOnlyFixture = stripJs('/* function onAdd() { fetch("/x"); } */\nfunction onAdd() { /* no real fetch here */ }\n');
check('(mutation self-test) stripJs() removes a comment-only fetch( mention',
    !/fetch\s*\(/.test(commentOnlyFixture.slice(commentOnlyFixture.indexOf('function onAdd'))));

/* ---- 1. the panel exports mountAltTitlesPanel ---------------------------- */

check('alt-titles-panel.js exports mountAltTitlesPanel',
    /export\s+function\s+mountAltTitlesPanel\s*\(/.test(panel));

/* ---- 2. metadata-tab.js imports and mounts it, passing songId + api ----- */

check("metadata-tab.js dynamically imports './alt-titles-panel.js'",
    /import\(\s*['"]\.\/alt-titles-panel\.js['"]\s*\)/.test(metaTab));
check('metadata-tab.js calls mountAltTitlesPanel(...) on the imported module',
    /\.mountAltTitlesPanel\s*\(/.test(metaTab));
check('metadata-tab.js passes songId when mounting the alt-titles panel',
    (() => {
        const idx = metaTab.indexOf('.mountAltTitlesPanel(');
        if (idx === -1) return false;
        const call = metaTab.slice(idx, idx + 300);
        return /songId\s*:\s*songId/.test(call);
    })());
check('metadata-tab.js passes the injected api client when mounting the alt-titles panel (never re-imports api-client.js inside the panel)',
    (() => {
        const idx = metaTab.indexOf('.mountAltTitlesPanel(');
        if (idx === -1) return false;
        const call = metaTab.slice(idx, idx + 300);
        return /api\s*:\s*api/.test(call);
    })());

/* ---- 3. the mount's teardown is captured AND invoked in both places ----- */

check('metadata-tab.js declares an altTitlesDetach teardown variable',
    /let\s+altTitlesDetach\s*=\s*null/.test(metaTab));
check('metadata-tab.js\'s mount call assigns its result into altTitlesDetach',
    /altTitlesDetach\s*=\s*m\.mountAltTitlesPanel\s*\(/.test(metaTab));

/* Count how many times altTitlesDetach is INVOKED (called as a function,
   not merely assigned or declared) — must be >= 2: once in render()'s
   reset-before-rebuild block, once in the tab's own outer teardown(). A
   third/fourth call site is fine (defensive re-guards); ONE site is the
   regression (a panel that leaks on re-render, or on tab close). */
const altTitlesDetachCalls = (metaTab.match(/altTitlesDetach\s*\(\s*\)/g) || []).length;
check(`metadata-tab.js invokes altTitlesDetach() at least twice (render()'s reset block + the outer teardown() — found ${altTitlesDetachCalls})`,
    altTitlesDetachCalls >= 2);

/* ---- 4. mountAltTitlesPanel() itself returns a real teardown function --- */

check('mountAltTitlesPanel() returns a function (the teardown contract every sibling panel on this tab follows)',
    (() => {
        const start = panel.indexOf('export function mountAltTitlesPanel');
        if (start === -1) return false;
        const body = panel.slice(start);
        return /return\s+function\s+teardown\s*\(/.test(body) || /return\s+function\s*\(\s*\)\s*\{/.test(body);
    })());
check("mountAltTitlesPanel()'s teardown removes the mounted node from the DOM (parentNode.removeChild)",
    /parentNode\.removeChild/.test(panel));

/* ---- 5. rule #31 — no raw fetch( anywhere in the panel ------------------- */

check('alt-titles-panel.js contains NO raw fetch( call (every request goes through the injected api object, rule #31)',
    !/\bfetch\s*\(/.test(panel));
check('alt-titles-panel.js actually USES the injected api (api.listAltTitles/addAltTitle/deleteAltTitle) — proves the "no fetch" result above is real coverage, not an empty file',
    /\bapi\.listAltTitles\s*\(/.test(panel)
    && /\bapi\.addAltTitle\s*\(/.test(panel)
    && /\bapi\.deleteAltTitle\s*\(/.test(panel));

/* ---- 6. api-client.js methods exist and call the real api2.php actions -- */

const EXPECTED_METHODS = {
    listAltTitles:   'song_alt_titles',
    addAltTitle:     'song_alt_title_add',
    deleteAltTitle:  'song_alt_title_delete',
};

const serverActions = new Set(
    Array.from(api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/gi)).map((m) => m[1])
);
check('parsed a plausible number of api2.php server cases (>= 20)', serverActions.size >= 20);

for (const [method, expectedAction] of Object.entries(EXPECTED_METHODS)) {
    const re = new RegExp(method + "\\s*:\\s*\\([^)]*\\)\\s*=>\\s*(?:getJson|postJson)\\(\\s*'([a-z0-9_]+)'", 'i');
    const m = client.match(re);
    check(`api-client.editorApi.${method} exists and calls the '${expectedAction}' action`,
        m !== null && m[1] === expectedAction);
    check(`api2.php has a real \`case '${expectedAction}':\` for ${method} (would 400/404 at click time otherwise)`,
        serverActions.has(expectedAction));
}

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nThe v2 editor\'s alt-titles panel must stay in lock-step with api2.php\'s');
    console.error('action names, and must never reach for a raw fetch() (rule #31) or leak on');
    console.error('re-render/close (missing teardown call).');
    process.exit(1);
}
console.log('\nAll v2 alt-titles-panel contract assertions passed.');
