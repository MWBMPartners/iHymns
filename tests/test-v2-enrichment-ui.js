/**
 * iHymns — v2 Song Editor per-line enrichment UI guard (#1088, #1601, #1627)
 *
 * ELI5
 * ----
 * The v2 editor's Structure tab just grew a chords box and a per-line
 * "language / translation / annotation" drawer. This file checks that the
 * client-side pieces of that feature actually agree with the server they
 * talk to, and that the two "clear this field" special cases are wired the
 * right way round — a place this exact codebase has a documented habit of
 * getting backwards (rule #25's C4/C5 clear-semantics history).
 *
 * WHAT IT ASSERTS
 *   (1) api-client.js exposes all four line-enrichment methods, and each
 *       one's action-string literal has a REAL `case '...':` in api2.php —
 *       not a typo that would 400/404 at click time with no build-time
 *       signal (the exact failure class tests/php/test-editor-api2-contract.php
 *       exists for on the PHP side; this is the same property, parsed fresh
 *       from THIS feature's four methods).
 *   (2) enrichment-panel.js's LINE_TRANSLATION_KINDS / LINE_ANNOTATION_TYPES
 *       are SET-EQUAL to includes/line_enrichment.php's — not a superset, not
 *       a subset. A client kind/type the server doesn't recognise 400s
 *       silently (the user only finds out on Save); a server value the
 *       client never offers is a feature nobody can reach. Both lists are
 *       parsed OUT OF SOURCE (never hand-copied here), so this cannot pass by
 *       accident the way a hardcoded expectation could.
 *   (3) The chords clear-path sends an empty ARRAY, never `null`, for an
 *       all-blank chords textarea (structure-tab.js) — and the per-line
 *       language clear-path never collapses `comp.languages` back to `null`
 *       once it has become an array (enrichment-panel.js). Both guard the
 *       SAME api2.php `isset()` gate (component_upsert PRESERVES a field
 *       whose key is absent/null, so `null` cannot mean "clear"); getting
 *       either backwards makes the field permanently un-clearable with the
 *       save still reporting success — no error, just silent data loss.
 *
 * Every assertion here is mutation-tested (see the session report) — each
 * one was confirmed to FAIL against a deliberately broken copy of the source
 * before being trusted, then re-confirmed to pass against the real file.
 *
 *   node tests/test-v2-enrichment-ui.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
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
   (and the source files' doc-blocks) discuss the exact action names,
   vocabulary values, and `null`/`[]` literals under test at length — matching
   raw source would let prose satisfy an assertion that is supposed to be
   about code. Mirrors tests/test-vendor-sri.js's stripping approach. */
const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const stripPhpBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

const clientSrc  = readFileSync(join(EDITOR_V2, 'api-client.js'), 'utf8');
const panelSrc   = readFileSync(join(EDITOR_V2, 'enrichment-panel.js'), 'utf8');
const structSrc  = readFileSync(join(EDITOR_V2, 'structure-tab.js'), 'utf8');
const apiSrc     = readFileSync(join(PUB, 'manage', 'editor', 'api2.php'), 'utf8');
const enrichSrc  = readFileSync(join(PUB, 'includes', 'line_enrichment.php'), 'utf8');

const client = stripJs(clientSrc);
const panel  = stripJs(panelSrc);
const struct = stripJs(structSrc);
const api    = stripPhpBlock(apiSrc);
const enrich = stripPhpBlock(enrichSrc);

console.log('\n#1627 items 1+3 — v2 editor chords UI + per-line enrichment panel\n');

/* ---- 1. api-client.js exposes the four methods, action strings real ---- */

const EXPECTED_METHODS = {
    upsertLineTranslation: 'line_translation_upsert',
    deleteLineTranslation: 'line_translation_delete',
    upsertLineAnnotation:  'line_annotation_upsert',
    deleteLineAnnotation:  'line_annotation_delete',
};

/* Server case labels, parsed fresh (not hardcoded) so a renamed/removed
   server action is caught the same way test-editor-api2-contract.php catches
   it for the rest of the client surface. */
const serverActions = new Set(
    Array.from(api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/gi)).map((m) => m[1])
);
check('parsed a plausible number of api2.php server cases (>= 20)', serverActions.size >= 20);

for (const [method, expectedAction] of Object.entries(EXPECTED_METHODS)) {
    /* Match `methodName: (...) => postJson('action_name', ...)` — tolerant of
       the alignment whitespace api-client.js uses between the colon and the
       arrow function. */
    const re = new RegExp(method + '\\s*:\\s*\\([^)]*\\)\\s*=>\\s*postJson\\(\\s*\'([a-z0-9_]+)\'', 'i');
    const m = client.match(re);
    check(`api-client.editorApi.${method} exists and calls postJson('${expectedAction}', …)`,
        m !== null && m[1] === expectedAction);
    check(`api2.php has a real \`case '${expectedAction}':\` for ${method} (would 400 at click time otherwise)`,
        serverActions.has(expectedAction));
}

/* ---- 2. kind / annotationType vocab is SET-EQUAL, parsed from both sides - */

/* Client: enrichment-panel.js's exported arrays. */
const clientKinds = (panel.match(/LINE_TRANSLATION_KINDS\s*=\s*\[([^\]]*)\]/) || [null, ''])[1]
    .split(',').map((s) => s.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);
const clientTypes = (panel.match(/LINE_ANNOTATION_TYPES\s*=\s*\[([^\]]*)\]/) || [null, ''])[1]
    .split(',').map((s) => s.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);

/* Server: includes/line_enrichment.php's central vocab constants (rule #20). */
const serverKinds = (enrich.match(/LINE_TRANSLATION_KINDS\s*=\s*\[([^\]]*)\]/) || [null, ''])[1]
    .split(',').map((s) => s.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);
const serverTypes = (enrich.match(/LINE_ANNOTATION_TYPES\s*=\s*\[([^\]]*)\]/) || [null, ''])[1]
    .split(',').map((s) => s.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);

const setEq = (a, b) => a.length === b.length && new Set(a).size === a.length
    && a.every((v) => b.includes(v)) && b.every((v) => a.includes(v));

check(`parsed enrichment-panel.js LINE_TRANSLATION_KINDS (${clientKinds.length} values)`, clientKinds.length > 0);
check(`parsed enrichment-panel.js LINE_ANNOTATION_TYPES (${clientTypes.length} values)`, clientTypes.length > 0);
check(`parsed includes/line_enrichment.php LINE_TRANSLATION_KINDS (${serverKinds.length} values)`, serverKinds.length > 0);
check(`parsed includes/line_enrichment.php LINE_ANNOTATION_TYPES (${serverTypes.length} values)`, serverTypes.length > 0);

check(`translation "kind" vocab is set-equal client<->server: [${clientKinds.join(', ')}]`,
    setEq(clientKinds, serverKinds));
check(`annotation "type" vocab is set-equal client<->server: [${clientTypes.join(', ')}]`,
    setEq(clientTypes, serverTypes));

/* ---- 3a. chords clear-path: empty textarea -> [] , never null ---------- */

/* The exact ternary structure-tab.js's chords <textarea> input handler uses:
   assign comp.chords to the split rows when ANY row has content, else to a
   literal empty array. Written loosely enough to survive reformatting but
   tight enough that swapping the `[]` for `null` (the regression this guards)
   fails it — see the mutation-test note in the file header. */
const chordsClearRe = /comp\.chords\s*=\s*rows\.some\([\s\S]*?\)\s*\?\s*rows\s*:\s*\[\]\s*;/;
check('structure-tab.js: an all-blank chords textarea sets comp.chords = [] (clears server-side), not null (which would preserve the old chords)',
    chordsClearRe.test(struct));

/* Negative check alongside the positive one: the SAME assignment must not
   ALSO have a literal `: null` branch anywhere nearby (i.e. we didn't just
   add a second, wrong assignment next to a correct one). Scoped to the
   `comp.chords =` statement itself, not the whole file (comp.chords is
   legitimately read elsewhere, and `null` appears all over the file for
   unrelated fields like comp.language). */
const chordsAssignStmt = (struct.match(/comp\.chords\s*=\s*[^;]*;/) || [''])[0];
check('the comp.chords clear assignment does not fall back to null',
    chordsAssignStmt !== '' && !/:\s*null\s*;?\s*$/.test(chordsAssignStmt));

/* ---- 3b. per-line language clear-path: never re-nulls the whole array -- */

/* The regression this guards: collapsing `comp.languages` back to `null`
   once it has been an array. api2.php's component_upsert uses isset(), which
   is FALSE for a JSON null, so `languages: null` PRESERVES the stored value
   instead of clearing it — see enrichment-panel.js's file header. */
const languagesWholeNullAssign = /comp\.languages\s*=\s*null\s*;/;
check('enrichment-panel.js never reassigns comp.languages = null (that would make the LAST override permanently un-clearable via api2\'s isset() preserve-on-null gate)',
    !languagesWholeNullAssign.test(panel));

/* The correct shape: setLineLang() nulls a single CELL inside the array,
   never the field itself, and pads the array with push() before writing to
   it — i.e. comp.languages stays an array once created. */
check('enrichment-panel.js clears ONE cell (comp.languages[i] = …), not the whole field',
    /comp\.languages\[i\]\s*=\s*tag\s*\|\|\s*null\s*;/.test(panel));
check('enrichment-panel.js pads comp.languages with an Array before indexing into it (so setLineLang never writes onto a bare null)',
    /if\s*\(\s*!Array\.isArray\(comp\.languages\)\s*\)\s*\{\s*comp\.languages\s*=\s*\[\]\s*;?\s*\}/.test(panel));

/* structure-tab.js's save payload must forward whatever comp.languages holds
   (as an array once touched) rather than only ever sending null — this is
   the other half of the trap: enrichment-panel.js can get the array-cell
   discipline right and it still does nothing if the payload builder discards
   it. `comp.languages || null` is correct because a non-empty OR fully-null
   array is still a truthy JS array (only `null`/`undefined` fall through to
   the `null` on the right, and only when no override was EVER set). */
check("structure-tab.js's saveComponent() forwards comp.languages (falls back to null only when comp.languages itself is falsy, i.e. never touched)",
    /languages:\s*comp\.languages\s*\|\|\s*null\s*,/.test(struct));

/* ---- the payload keys agree across THREE files ------------------------
 *
 * api2.php EMITS `lineTranslations` / `lineAnnotations` from load_song;
 * editor2.php HYDRATES store slices of those names; enrichment-panel.js READS
 * those slices. Three files, one pair of names, nothing tying them together.
 *
 * That is the #1581 shape exactly — the Settings language filter dispatched one
 * event spelling while Song of the Day listened for another, and the result was
 * a silent no-op with no error anywhere. Here a rename on any one side gives an
 * enrichment panel that renders, accepts input, saves happily to the right
 * endpoints, and shows no existing translations or annotations at all: every
 * part works, the feature does not. Nothing in the suite above catches it,
 * because each file is individually correct.
 *
 * Derived from source on all three sides rather than asserting a hardcoded pair,
 * so renaming the keys DELIBERATELY (in all three) still passes.
 */
/* `panel` and the api2 source are already read above; only the shell is new. */
const shell = stripJs(readFileSync(join(PUB, 'manage/editor/editor2.php'), 'utf8'));

for (const key of ['lineTranslations', 'lineAnnotations']) {
    /* Server side: emitted as a load_song payload key. */
    const emitted = new RegExp(`['"]${key}['"]\\s*=>`).test(api);
    /* Shell: both declared as a store slice AND hydrated from the response. */
    const declared = new RegExp(`${key}\\s*:\\s*\\[`).test(shell);
    const hydrated = new RegExp(`store\\.set\\(\\s*['"]${key}['"]`).test(shell)
        && new RegExp(`data\\.${key}`).test(shell);
    /* Panel: read back out of the store. */
    const consumed = new RegExp(`store\\.get\\(\\s*['"]${key}['"]`).test(panel);

    check(`'${key}' agrees across api2.php -> editor2.php -> enrichment-panel.js`
        + (emitted && declared && hydrated && consumed ? '' :
            ` [emit:${emitted} declare:${declared} hydrate:${hydrated} consume:${consumed}]`),
        emitted && declared && hydrated && consumed);
}

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nThe v2 editor\'s chords + per-line enrichment UI must stay in lock-step with');
    console.error('api2.php\'s action names, includes/line_enrichment.php\'s vocab, and the');
    console.error('component_upsert isset()-preserve clear-semantics (rule #25 / #1627). A');
    console.error('"successful" save that silently preserved instead of clearing a field is the');
    console.error('regression class this file exists to catch.');
    process.exit(1);
}
console.log('\nAll v2 enrichment-UI contract assertions passed.');
