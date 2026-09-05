/**
 * iHymns — v2 Song Editor "Who sings" voice-parts UI guard (#2073 commit 7)
 *
 * ELI5
 * ----
 * The v2 editor's Structure tab just grew a "Who sings" drawer (voices-panel.js)
 * under each section — tick lines, pick a voice, echo, or set up a round. This
 * file checks that its client-side pieces actually agree with the server core
 * they talk to (`includes/vocal_parts.php`, the eight `api2.php` actions
 * #2073 commit 6 already shipped), that the vocabulary picker never grows a
 * second, hand-typed copy of the 21-kind list (rule #43), and that the
 * auto-save ("D3") ordering the task brief requires — resolve/flush a
 * section's real line ids BEFORE ever writing a voice assignment — is
 * actually there in the code, not just in a comment.
 *
 * WHAT IT ASSERTS
 *   (1) The whole-song `vocalParts` payload's key set — `includes/vocal_parts.php`'s
 *       `VOCAL_PARTS_PAYLOAD_KEYS` constant — is SET-EQUAL to voices-panel.js's
 *       `EMPTY_VOCAL_PARTS` fallback shape. Both parsed OUT OF SOURCE, never
 *       hand-copied here — a renamed/added/removed key on either side that the
 *       other doesn't also carry is exactly the #1581 shape (every part looks
 *       fine in isolation; the feature silently shows nothing).
 *   (2) `api-client.js` exposes this feature's whole action family (derived
 *       from the `postJson('vocal_...'/'round_...', ...)` call SHAPE, never a
 *       hardcoded method-name list), and every one of those action strings has
 *       a REAL `case '...':` in api2.php — the same property
 *       tests/php/test-editor-api2-contract.php proves from the PHP side,
 *       parsed fresh here from THIS feature's own methods. A floor-AND-ceiling
 *       count of exactly eight, so a deleted method is caught as surely as a
 *       renamed one.
 *   (3) `editor2.php` serves the vocal-part-kind vocabulary from the ONE PHP
 *       core (`vocalPartsKindsProjection()`), gated on `vocalPartsTablesReady()`
 *       — never real vocabulary handed to a picker that would just 409 on
 *       every write on an un-migrated install (the same posture the
 *       pre-existing `$songPartTypesForJs` emit right above it already uses).
 *   (4) voices-panel.js contains NO second, hand-typed copy of the 21-kind
 *       vocabulary (rule #43) — every real kind key is parsed fresh from
 *       `IHYMNS_VOCAL_PART_KINDS` and its occurrence count outside the tiny,
 *       explicitly-allowed `FALLBACK_KINDS` fallback array and the two
 *       `kind === 'named-singer'` / `kind === 'group'` comparisons those two
 *       kinds' own extra input needs is asserted to be exactly the allowance,
 *       no more. (A WAI-ARIA `role="group"` attribute this file legitimately
 *       sets three times is explicitly normalised out first — see the code
 *       comment at that step for why counting it as a "group" vocabulary hit
 *       would be a false positive a blunter check would wrongly flag, the
 *       "fails on correct code" trap CLAUDE.md rule #34 itself warns against.)
 *   (5) THE PART PICKER IS A REAL `<select>`, never a free-text box a curator
 *       could type an arbitrary kind into (rule #43's other half).
 *   (6) D3 auto-save ordering, checked INDEPENDENTLY per branch (word-span
 *       and whole-line assign each have their OWN "resolve ids first" call
 *       ahead of their OWN write — a single whole-function "does X appear
 *       anywhere before Y" check would pass even if ONE branch's order were
 *       reversed, because the OTHER branch's call still sits earlier in the
 *       file; splitting on the real source's own branch boundary avoids that
 *       false-negative).
 *   (7) structure-tab.js's wiring: the import, the panel mounted into every
 *       card, its destroy fn tracked (and run) the same way the existing
 *       "Source work" picker's is, the lyrics textarea keeping the panel's
 *       line rows in step, the D3 plumbing (`ensureSaved`/`hasPendingSave`/
 *       `onSaved`) actually handed to the panel, the in-flight save de-dupe
 *       that closes the "Add section create hasn't resolved yet" race, and
 *       the post-save notification the queued-retry mechanism depends on.
 *
 * Every assertion here was exercised against a deliberately broken copy of
 * the relevant source and confirmed to go RED before being trusted, then
 * re-confirmed GREEN against the real files — the mutation protocol
 * documented at the bottom of this file (rule #34: a guard that was never
 * proven able to fail is not a guard).
 *
 *   node tests/test-v2-voices-ui.js
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

/* Strip comments before matching, on BOTH sides — this suite's own doc-blocks
   (and the source files' own) discuss the exact action names, vocabulary
   keys, and code shapes under test at length; matching raw source would let
   prose satisfy an assertion that is supposed to be about code. Mirrors
   tests/test-v2-enrichment-ui.js's identical stripping approach. */
const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const stripPhpBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

const panelSrc  = readFileSync(join(EDITOR_V2, 'voices-panel.js'), 'utf8');
const structSrc = readFileSync(join(EDITOR_V2, 'structure-tab.js'), 'utf8');
const clientSrc = readFileSync(join(EDITOR_V2, 'api-client.js'), 'utf8');
const apiSrc    = readFileSync(join(PUB, 'manage', 'editor', 'api2.php'), 'utf8');
const coreSrc   = readFileSync(join(PUB, 'includes', 'vocal_parts.php'), 'utf8');
const shellSrc  = readFileSync(join(PUB, 'manage', 'editor', 'editor2.php'), 'utf8');

const panel  = stripJs(panelSrc);
const struct = stripJs(structSrc);
const client = stripJs(clientSrc);
const api    = stripPhpBlock(apiSrc);
const core   = stripPhpBlock(coreSrc);
const shell  = stripJs(shellSrc);

console.log('\n#2073 commit 7 — v2 editor "Who sings" panel\n');

/* ---- 1. vocalParts payload-key lockstep: PHP <-> JS ------------------- */

const serverKeys = (core.match(/const VOCAL_PARTS_PAYLOAD_KEYS\s*=\s*\[([^\]]*)\]/) || [null, ''])[1]
    .split(',').map((s) => s.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);
check(`parsed includes/vocal_parts.php VOCAL_PARTS_PAYLOAD_KEYS (${serverKeys.length} keys)`, serverKeys.length >= 5);

const clientKeysBlock = (panel.match(/const EMPTY_VOCAL_PARTS\s*=\s*\{([\s\S]*?)\};/) || [null, ''])[1];
const clientKeys = Array.from(clientKeysBlock.matchAll(/([A-Za-z]+)\s*:/g)).map((m) => m[1]);
check(`parsed voices-panel.js EMPTY_VOCAL_PARTS keys (${clientKeys.length} keys)`, clientKeys.length >= 5);

const setEq = (a, b) => a.length === b.length && new Set(a).size === a.length
    && a.every((v) => b.includes(v)) && b.every((v) => a.includes(v));
check(`vocalParts payload keys are set-equal PHP<->JS: [${serverKeys.join(', ')}]`, setEq(serverKeys, clientKeys));

/* ---- 2. api-client.js exposes the whole action family; every action ---
        string has a real case in api2.php ------------------------------- */

const serverActions = new Set(
    Array.from(api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/gi)).map((m) => m[1])
);
check('parsed a plausible number of api2.php server cases (>= 20)', serverActions.size >= 20);

/* Derived by the CALL SHAPE (postJson('vocal_...'/'round_...', ...)), never a
   hardcoded method-name list — a renamed or newly-added method in this
   family is picked up automatically. */
const clientMethods = Array.from(
    client.matchAll(/(\w+)\s*:\s*\([^)]*\)\s*=>\s*postJson\(\s*'((?:vocal_|round_)[a-z0-9_]+)'/gi)
);
check(`api-client.js exposes exactly eight vocal/round methods (found ${clientMethods.length})`, clientMethods.length === 8);
for (const [, method, action] of clientMethods) {
    check(`api2.php has a real \`case '${action}':\` for api-client.editorApi.${method} (a mismatch here 404s/400s at click time with no build-time signal)`,
        serverActions.has(action));
}

/* ---- 3. editor2.php serves the vocabulary from the ONE core, gated ----- */

check('editor2.php emits window._iHymnsVocalPartKinds from a PHP-side value',
    /window\._iHymnsVocalPartKinds\s*=\s*<\?=/.test(shellSrc));
check('editor2.php derives that value from vocalPartsTablesReady(...) ? vocalPartsKindsProjection() : [] (never real vocab on an un-migrated install)',
    /\$vocalPartKindsForJs\s*=\s*vocalPartsTablesReady\([^;]*\)\s*\?\s*vocalPartsKindsProjection\(\)\s*:\s*\[\]/.test(shell));
check('voices-panel.js reads window._iHymnsVocalPartKinds (the served vocabulary, not a second global name)',
    /window\._iHymnsVocalPartKinds/.test(panel));

/* ---- 4. no second, hand-typed copy of the 21-kind vocabulary (#43) ---- */

const realKeys = Array.from(core.matchAll(/^\s{4}'([a-z-]+)'\s*=>\s*\[/gm)).map((m) => m[1]);
check(`parsed includes/vocal_parts.php IHYMNS_VOCAL_PART_KINDS keys (${realKeys.length} keys)`, realKeys.length >= 15);

let panelForVocabCheck = panel.replace(/const\s+FALLBACK_KINDS\s*=\s*\[[\s\S]*?\];/, '');
/* `role="group"` is a WAI-ARIA attribute VALUE this file legitimately sets
   three times (the line list, a per-line chip group, the word picker) —
   nothing to do with the 'group' VOICE-PART kind. Normalised out here so it
   can never mask, or be mistaken for, a hardcoded vocabulary reference; see
   this suite's own header note (4) for why a blunter check would wrongly
   flag it. */
panelForVocabCheck = panelForVocabCheck.replace(/'role'\s*,\s*'group'/g, '');

const ALLOWED_EXTRA = { 'named-singer': 2, group: 1 };
for (const key of realKeys) {
    const re = new RegExp("'" + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + "'", 'g');
    const count = (panelForVocabCheck.match(re) || []).length;
    const allowed = ALLOWED_EXTRA[key] || 0;
    check(`voices-panel.js: '${key}' appears outside FALLBACK_KINDS no more than its allowance (${count} <= ${allowed})`, count <= allowed);
}
check("voices-panel.js contains the ONE allowed comparison kind === 'named-singer'", /kind\s*===\s*'named-singer'/.test(panel));
check("voices-panel.js contains the ONE allowed comparison kind === 'group'", /kind\s*===\s*'group'/.test(panel));

/* ---- 5. the part picker is a REAL <select>, never free text (#43) ------ */

check("voices-panel.js builds the part picker as document.createElement('select') (never a text input a curator could type an arbitrary kind into)",
    /const partSelect = document\.createElement\('select'\)/.test(panel));

/* ---- 6. client-side part dedupe runs BEFORE minting a new part -------- */

check('resolvePartSelection() checks findExistingPartForKind( before ever calling api.upsertVocalPart( (never mints a duplicate "Women" part on a second Assign)',
    (() => {
        const body = (panel.match(/async function resolvePartSelection\([\s\S]*?\n    \}\n/) || [''])[0];
        const iFind = body.indexOf('findExistingPartForKind(');
        const iMint = body.indexOf('api.upsertVocalPart(');
        return iFind !== -1 && iMint !== -1 && iFind < iMint;
    })());

/* ---- 7. D3 auto-save ordering, checked PER WRITE CALL ------------------
 *
 * A single whole-function "does ensureAndResolveIds( appear anywhere before
 * the write call" check is too weak: `onAssign()` calls
 * `ensureAndResolveIds(` TWICE (once per branch), and the WORD branch's own
 * call sits textually first — so it would satisfy "something called
 * ensureAndResolveIds before this write" for BOTH branches even if the
 * LINE branch's own call were moved to AFTER its own write, because the
 * OTHER branch's earlier call is still "before" it in the raw file. That
 * is a guard that cannot fail — rule #34's own warning.
 *
 * Instead, each write call's own IMMEDIATELY PRECEDING few hundred
 * characters must contain BOTH `ensureAndResolveIds(` and the
 * `if (!resolved.ok)` check on its result — i.e. THIS write is reached only
 * after THIS branch's own resolve-and-check, not merely somewhere earlier
 * in the function. A reordering mutation that moves a write call to before
 * its own resolve step empties that write's own preceding window of both
 * markers. */
const onAssignBody = (panel.match(/async function onAssign\([\s\S]*?\n    \}\n/) || [''])[0];
check('parsed a non-trivial onAssign() body', onAssignBody.length > 200);

function writeIsGuardedByOwnResolve(text, writeNeedle, windowSize) {
    const i = text.indexOf(writeNeedle);
    if (i === -1) { return false; }
    const win = text.slice(Math.max(0, i - windowSize), i);
    return win.includes('ensureAndResolveIds(') && win.includes('if (!resolved.ok)');
}
check("onAssign()'s api.upsertVocalSpan( write is immediately preceded by ITS OWN ensureAndResolveIds( + a resolved.ok check (D3, word-span branch)",
    writeIsGuardedByOwnResolve(onAssignBody, 'api.upsertVocalSpan(', 700));
check("onAssign()'s api.assignVocalLines( write is immediately preceded by ITS OWN ensureAndResolveIds( + a resolved.ok check (D3, line-assign branch)",
    writeIsGuardedByOwnResolve(onAssignBody, 'api.assignVocalLines(', 700));

/* ---- 8. structure-tab.js wiring ---------------------------------------- */

check("structure-tab.js imports buildVoicesPanel from './voices-panel.js'",
    /import\s*\{\s*buildVoicesPanel\s*\}\s*from\s*'\.\/voices-panel\.js'/.test(struct));
check("buildCard() appends the panel (body.appendChild(voices.el))",
    /body\.appendChild\(voices\.el\)/.test(struct));
check('buildCard() pushes voices.destroy into voicesPanelDestroyFns (torn down like every other card-scoped subscriber)',
    /voicesPanelDestroyFns\.push\(voices\.destroy\)/.test(struct));
check('voicesPanelDestroyFns is run + reset in AT LEAST two places (render() and teardown())',
    (struct.match(/voicesPanelDestroyFns\.forEach/g) || []).length >= 2);
check('the lyrics textarea input handler calls voices.refresh() (keeps the panel in step with live, unsaved edits)',
    /voices\.refresh\(\)/.test(struct));
check('structure-tab.js hands the panel ensureSaved, hasPendingSave, and an onSaved bound to THIS component (the D3 plumbing)',
    /ensureSaved\s*,\s*hasPendingSave\s*,\s*onSaved:\s*\(fn\)\s*=>\s*onComponentSaved\(comp,\s*fn\)/.test(struct));
check('saveComponent() de-dupes concurrent calls for the same component onto ONE in-flight promise (closes the "Add section create not yet resolved" race)',
    /inFlightSaves/.test(struct) && /const p = _saveComponentNow\(comp\)/.test(struct));
check('a successful save notifies onComponentSaved() listeners (the mechanism the D3 queued-retry banner relies on)',
    /saveListeners\.get\(comp\._key\)/.test(struct));
check('ensureSaved() exists and reuses saveComponent() (never a second, independent save path — task brief: "Never persist it by another path")',
    /async function ensureSaved\(comp\)\s*\{[\s\S]*?return saveComponent\(comp\);/.test(struct));

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nThe v2 editor\'s "Who sings" panel must stay in lock-step with the');
    console.error('vocal_parts.php/api2.php contract, never grow a second copy of the 21-kind');
    console.error('vocabulary, and never write a voice assignment against an unresolved line id.');
    process.exit(1);
}
console.log('\nAll v2 "Who sings" panel contract assertions passed.');

/*
 * MUTATION PROTOCOL (rule #34 — each performed against the real files,
 * observed RED, then reverted; re-run confirmed GREEN, `git diff` empty):
 *
 *   m1  Rename one entry in includes/vocal_parts.php's VOCAL_PARTS_PAYLOAD_KEYS
 *       (e.g. 'rounds' -> 'roundz') -> assertion (1)'s set-equal check fails.
 *   m2  Change one action-string literal in api-client.js's vocal/round
 *       methods (e.g. 'vocal_part_upsert' -> 'vocal_part_upsertx') -> the
 *       matching "api2.php has a real case" assertion in (2) fails.
 *   m3  Delete the `window._iHymnsVocalPartKinds` <script> line from
 *       editor2.php -> assertion (3)'s first check fails.
 *   m4  Change editor2.php's gate to unconditionally serve the vocab
 *       (drop the `vocalPartsTablesReady(...) ? ... : []` ternary) ->
 *       assertion (3)'s second check fails.
 *   m5  Add a hardcoded second vocabulary reference to voices-panel.js
 *       (e.g. `if (kind === 'choir') { ... }`) -> the 'choir' row in
 *       assertion (4)'s per-key loop fails (count 1 > allowance 0).
 *   m6  Change the part picker from `document.createElement('select')` to
 *       a `document.createElement('input')` -> assertion (5) fails.
 *   m7  In voices-panel.js, move the `api.upsertVocalPart(` call in
 *       resolvePartSelection() to BEFORE the `findExistingPartForKind(`
 *       check -> assertion (6) fails.
 *   m8  In the line-assign branch only, move the `api.assignVocalLines(`
 *       call to BEFORE its own `const resolved = await ensureAndResolveIds(
 *       indexes);`/`if (!resolved.ok)` pair -> assertion (7)'s SECOND
 *       `writeIsGuardedByOwnResolve` check fails, while the word-span
 *       branch's OWN check (correctly) still passes — proving the
 *       per-write-call proximity window, not a whole-function "appears
 *       anywhere before" check, is what catches it (a whole-function check
 *       cannot fail here: the WORD branch's own, earlier
 *       `ensureAndResolveIds(` call would still satisfy it even with the
 *       line branch's order reversed).
 *   m9  Delete `voices.refresh()` from the lyrics textarea's input handler
 *       in structure-tab.js -> the matching check in (8) fails.
 *   m10 Delete the `saveListeners.get(comp._key)` notification block from
 *       `_saveComponentNow()` -> the matching check in (8) fails, and (in
 *       the real editor) a queued D3 retry would then wait forever for a
 *       notification that never comes.
 */
