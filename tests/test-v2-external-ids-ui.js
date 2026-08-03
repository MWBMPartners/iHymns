/**
 * iHymns — v2 Song Editor "Recording / external IDs" panel guard (#1741 P5b)
 *
 * ELI5
 * ----
 * The v2 editor's Metadata tab just grew a small card-list for a song's
 * external ids (Spotify, ISRC, MusicBrainz recording, …). This file checks
 * that the client-side pieces of that feature actually agree with the
 * server they talk to, and — the specific regression this feature is most
 * at risk of — that the "which providers can I add?" dropdown is built
 * ENTIRELY from the server's own registry, never a second, hand-typed list
 * that could silently drift from it (rule #35).
 *
 * WHAT IT ASSERTS
 *   (1) Every `song_external_id*` action literal api-client.js sends has a
 *       REAL `case '...':` in api2.php — the same property
 *       tests/php/test-editor-api2-contract.php already covers from the PHP
 *       side (that file re-derives it independently too); kept here as a
 *       second, SELF-CONTAINED assertion so this guard can be mutation-
 *       tested in isolation without depending on the PHP suite running.
 *   (2) NONE of `includes/media_identifiers.php`'s RECORDING_EXTERNAL_ID_TYPES
 *       slugs appear as a quoted string literal inside external-ids-panel.js
 *       — a hardcoded `'spotify'`/`'isrc'` fallback array in the panel is
 *       exactly the "second list" rule #35 exists to prevent: a dropdown
 *       offering a provider the server doesn't recognise (422 at Add time,
 *       no build-time signal) or missing one the server does.
 *   (3) external-ids-panel.js reads `window._iHymnsRecordingIdTypes`, and
 *       editor2.php actually emits it (`mediaIdentifierRecordingTypes(` +
 *       `_iHymnsRecordingIdTypes` both present) — the server-derivation
 *       chain the panel's whole "no second list" property depends on.
 *   (4) api2.php's `song_external_id_add` case references the THREE
 *       registry functions the plan requires (`mediaIdentifierIdTypeValid`,
 *       `mediaIdentifierValidateValue`, `mediaIdentifierScopeForType` — the
 *       last one is what makes IdScope server-derived, never a client
 *       param) and answers both 409 (table missing) and 422 (bad
 *       idType/value) within that case's own source window.
 *
 * Every assertion here is mutation-tested (see the session report) — each
 * one was confirmed to FAIL against a deliberately broken copy of the
 * source before being trusted, then re-confirmed to pass against the real
 * file (rule #34).
 *
 *   node tests/test-v2-external-ids-ui.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
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
   blocks (and the source files' doc-blocks) discuss the exact action names
   and provider slugs under test at length; matching raw source would let
   prose satisfy an assertion that is supposed to be about code. Mirrors
   tests/test-v2-enrichment-ui.js's stripping approach (the sibling guard
   this file's shape is modelled on). */
const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const stripPhpBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

const clientSrc  = readFileSync(join(EDITOR_V2, 'api-client.js'), 'utf8');
const panelSrc   = readFileSync(join(EDITOR_V2, 'external-ids-panel.js'), 'utf8');
const shellSrc   = readFileSync(join(EDITOR, 'editor2.php'), 'utf8');
const apiSrc     = readFileSync(join(EDITOR, 'api2.php'), 'utf8');
const mediaIdSrc = readFileSync(join(PUB, 'includes', 'media_identifiers.php'), 'utf8');

const client = stripJs(clientSrc);
const panel  = stripJs(panelSrc);
const shell  = stripPhpBlock(shellSrc);
const api    = stripPhpBlock(apiSrc);
const mediaId = stripPhpBlock(mediaIdSrc);

console.log('\n#1741 P5b — v2 editor "Recording / external IDs" panel\n');

/* ---- 1. api-client.js's song_external_id* actions are real api2.php cases */

const EXPECTED_METHODS = {
    listExternalIds:  'song_external_ids',
    addExternalId:    'song_external_id_add',
    deleteExternalId: 'song_external_id_delete',
};

const serverActions = new Set(
    Array.from(api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/gi)).map((m) => m[1])
);
check('parsed a plausible number of api2.php server cases (>= 20)', serverActions.size >= 20);

for (const [method, expectedAction] of Object.entries(EXPECTED_METHODS)) {
    /* Tolerant of the alignment whitespace api-client.js uses between the
       colon and the arrow function, and of either getJson/postJson (the
       read is a GET, the writes are POSTs). */
    const re = new RegExp(method + "\\s*:\\s*\\([^)]*\\)\\s*=>\\s*(?:getJson|postJson)\\(\\s*'([a-z0-9_]+)'", 'i');
    const m = client.match(re);
    check(`api-client.editorApi.${method} exists and calls the '${expectedAction}' action`,
        m !== null && m[1] === expectedAction);
    check(`api2.php has a real \`case '${expectedAction}':\` for ${method} (would 400/404 at click time otherwise)`,
        serverActions.has(expectedAction));
}

/* ---- 2. the dropdown vocab has NO second, hand-typed provider list ----- */

/* Extract every RECORDING_EXTERNAL_ID_TYPES key from media_identifiers.php's
   source — the array literal's `'slug' => [` lines — never hand-copied here
   (a hand-copied list could itself silently drift from the real one and
   pass this test for the wrong reason). */
const recordingIdTypeSlugs = Array.from(
    mediaId.matchAll(/^\s*'([a-z0-9-]+)'\s*=>\s*\[/gm)
).map((m) => m[1]);
check(`parsed RECORDING_EXTERNAL_ID_TYPES slugs from media_identifiers.php (${recordingIdTypeSlugs.length} found)`,
    recordingIdTypeSlugs.length >= 10);

/* Every quoted string literal that appears anywhere in the panel — single OR
   double quoted, so both `'spotify'` and `"spotify"` are caught. */
const panelStringLiterals = new Set(
    Array.from(panel.matchAll(/'([^'\\]*(?:\\.[^'\\]*)*)'|"([^"\\]*(?:\\.[^"\\]*)*)"/g))
        .map((m) => m[1] !== undefined ? m[1] : m[2])
);

const leakedSlugs = recordingIdTypeSlugs.filter((slug) => panelStringLiterals.has(slug));
check(
    'external-ids-panel.js contains NO RECORDING_EXTERNAL_ID_TYPES slug as a quoted string literal '
        + '(a hardcoded fallback list would silently diverge from the server registry, rule #35)',
    leakedSlugs.length === 0
);
if (leakedSlugs.length > 0) {
    console.log(`       leaked slugs: ${leakedSlugs.join(', ')}`);
}

/* ---- 3. the server-derivation chain: editor2.php -> window global -> panel */

check("external-ids-panel.js reads window._iHymnsRecordingIdTypes (the server-derived vocab, never a local list)",
    /window\._iHymnsRecordingIdTypes/.test(panel));
check("editor2.php calls mediaIdentifierRecordingTypes( (the ONE registry accessor, not a re-fork)",
    /mediaIdentifierRecordingTypes\s*\(/.test(shell));
check("editor2.php emits window._iHymnsRecordingIdTypes",
    /_iHymnsRecordingIdTypes/.test(shell));

/* ---- 4. song_external_id_add is server-derived + answers 409 AND 422 --- */

/* Isolate the case's own source window: from `case 'song_external_id_add':`
   to the next `case '...':` (or EOF). Generous, per rule #34's #1676
   lesson ("bounding an assertion window is itself a place to be subtly
   wrong — widen rather than guess tight"). */
function caseBody(src, name) {
    const start = src.indexOf(`case '${name}':`);
    if (start === -1) return '';
    const next = src.indexOf("case '", start + 10);
    return src.slice(start, next === -1 ? src.length : next);
}
const addCase = caseBody(api, 'song_external_id_add');
check("api2.php has a `case 'song_external_id_add':` body to inspect", addCase.length > 300);

check('song_external_id_add references mediaIdentifierIdTypeValid (rejects an unrecognised slug)',
    /mediaIdentifierIdTypeValid\s*\(/.test(addCase));
check('song_external_id_add references mediaIdentifierValidateValue (shape-checks the value)',
    /mediaIdentifierValidateValue\s*\(/.test(addCase));
check('song_external_id_add references mediaIdentifierScopeForType (IdScope is SERVER-derived, never a client param)',
    /mediaIdentifierScopeForType\s*\(/.test(addCase));
check("song_external_id_add answers 409 (table missing)",
    /ed2_respond\([^;]*?,\s*409\)/s.test(addCase));
check("song_external_id_add answers 422 (unknown idType or bad value)",
    /ed2_respond\([^;]*?,\s*422\)/s.test(addCase));

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nThe v2 editor\'s external-ids panel must stay in lock-step with api2.php\'s');
    console.error('action names and includes/media_identifiers.php\'s registry — a second,');
    console.error('hand-typed provider list in the panel is the regression class this file');
    console.error('exists to catch (rule #35 / #1741 P5b).');
    process.exit(1);
}
console.log('\nAll v2 external-ids-panel contract assertions passed.');
