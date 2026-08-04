/**
 * iHymns — Tune live-search typeahead UI guard (#1741 P5c)
 *
 * ELI5
 * ----
 * The v2 editor's Metadata tab used to save a song's tune through a plain
 * text box, which stranded the tune's registry link on every edit. This
 * file checks that the fix actually landed on the CLIENT side: the old
 * text-box row is really gone, the new bespoke control saves through the
 * dedicated `song_tune_set` endpoint (never the generic per-keystroke
 * path), and — the specific regression this generalisation is most at risk
 * of — that `place-search.js`'s existing defaults (the 2 OTHER consumers:
 * Credit People / Works / Venues / Organisations place pickers, and this
 * tab's own origin-city picker) are UNCHANGED by the additive options this
 * phase introduced.
 *
 * WHAT IT ASSERTS
 *   (1) place-search.js's `DEFAULTS` object still has EXACTLY today's four
 *       keys with today's four VALUES — the byte-equivalence contract the
 *       2 pre-existing consumers depend on (rule #34: a generalisation that
 *       silently shifts a shared default is the same class of regression as
 *       an under-reporting scanner — quiet until a caller far away breaks).
 *   (2) place-search.js references all four new option names
 *       (`searchUrl`, `parseResults`, `pickMode`, `noun`) — the
 *       generalisation actually shipped, not just documented.
 *   (3) metadata-tab.js no longer has a `['tuneName', …]` FIELDS row (the
 *       exact regression this phase retires — that row saved TuneName alone
 *       through the debounced generic path, stranding TuneId).
 *   (4) metadata-tab.js DOES call `api.setSongTune(` and passes
 *       `pickMode: 'value'` to its typeahead attach() — the new control
 *       actually wired to the new endpoint + the non-network pick mode
 *       (a tblTunes find-or-create must not double-fire behind a caller's
 *       own save, see place-search.js's own pickMode doc-comment).
 *   (5) metadata-tab.js's tune input is wired on `'change'` (blur/pick),
 *       NOT a debounced `'input'` listener like the sibling text fields —
 *       the #1679-precedent "a side-effectful write must not fire on a
 *       typing pause" rule, restated for tunes (a debounced find-or-create
 *       would mint a junk tblTunes row per keystroke pause).
 *   (6) the `tune_search` / `song_tune_set` action literals api-client.js
 *       sends each have a REAL `case '...':` in api2.php — the same
 *       property tests/php/test-editor-api2-contract.php covers from the
 *       PHP side, kept here too so this guard is self-contained and can be
 *       mutation-tested in isolation (the test-v2-external-ids-ui.js
 *       precedent, #1741 P5b).
 *
 * Every assertion here is mutation-tested (see the session report) — each
 * one was confirmed to FAIL against a deliberately broken copy of the
 * source before being trusted, then re-confirmed to pass against the real
 * file (rule #34).
 *
 * ALSO RUN SEPARATELY (not duplicated here — a different harness): `node
 * tests/test-place-search-keyboard.js` must stay green, UNCHANGED, before
 * and after this phase — that is the no-behaviour-change proof for the
 * module's #1594 keyboard/ARIA machinery, which this file's static-source
 * assertions cannot exercise.
 *
 *   node tests/test-tune-typeahead-ui.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/js/modules/place-search.js DEFAULTS, defaultSearchUrl(), defaultParseResults()
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js the tune control (saveTune(), the tinput 'change' listener)
 * @see appWeb/public_html/manage/editor/v2/api-client.js searchTunes(), setSongTune()
 * @see appWeb/public_html/manage/editor/api2.php case 'tune_search', case 'song_tune_set'
 * @see .claude/catalogue-1741-P5-plan.md §3.8
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
   blocks (and the source files' doc-blocks) discuss the exact strings under
   test at length (e.g. metadata-tab.js's own comment names the retired
   `['tuneName', …]` row while explaining why it's gone), and prose that
   MENTIONS a pattern is not the pattern being live. Matching raw source
   would let the explanation satisfy the assertion it is explaining — the
   exact trap tests/test-v2-external-ids-ui.js's stripping approach exists
   to avoid (that file's own comment on this point, restated here). */
const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const stripPhpBlock = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

const placeSearchSrc = readFileSync(join(PUB, 'js', 'modules', 'place-search.js'), 'utf8');
const metadataTabSrc = readFileSync(join(EDITOR_V2, 'metadata-tab.js'), 'utf8');
const clientSrc      = readFileSync(join(EDITOR_V2, 'api-client.js'), 'utf8');
const apiSrc         = readFileSync(join(EDITOR, 'api2.php'), 'utf8');

const placeSearch = stripJs(placeSearchSrc);
const metadataTab = stripJs(metadataTabSrc);
const client       = stripJs(clientSrc);
const api          = stripPhpBlock(apiSrc);

console.log('\n#1741 P5c — Tune live-search typeahead + song_tune_set\n');

/* ---- 1. place-search.js: DEFAULTS unchanged + the four new options exist */

const defaultsMatch = placeSearch.match(/const\s+DEFAULTS\s*=\s*\{([\s\S]*?)\};/);
check('place-search.js still declares a `const DEFAULTS = {...}` object', defaultsMatch !== null);

if (defaultsMatch) {
    const body = defaultsMatch[1];
    /* Exactly today's four keys, exactly today's four values — a caller
       (Credit People, Works, Venues, Organisations, this tab's own
       origin-city picker) that passes NO options must get IDENTICAL
       behaviour to before this phase. */
    check("DEFAULTS.endpoint is unchanged ('/manage/places-api.php')",
        /endpoint\s*:\s*'\/manage\/places-api\.php'/.test(body));
    check('DEFAULTS.minChars is unchanged (3)', /minChars\s*:\s*3\s*,/.test(body));
    check('DEFAULTS.debounceMs is unchanged (250)', /debounceMs\s*:\s*250\s*,/.test(body));
    check('DEFAULTS.maxResults is unchanged (8)', /maxResults\s*:\s*8\s*,/.test(body));
    /* The four keys and NOTHING else — a fifth key silently added to this
       object is itself a behaviour change for the 2 pre-existing consumers,
       who spread `opts` on TOP of DEFAULTS (Object.assign({}, DEFAULTS,
       opts)) and would suddenly inherit a value they never asked for. */
    const declaredKeys = Array.from(body.matchAll(/(\w+)\s*:/g)).map((m) => m[1]);
    check('DEFAULTS declares exactly 4 keys (no fifth key silently added)',
        declaredKeys.length === 4
        && declaredKeys.includes('endpoint') && declaredKeys.includes('minChars')
        && declaredKeys.includes('debounceMs') && declaredKeys.includes('maxResults'));
}

for (const optionName of ['searchUrl', 'parseResults', 'pickMode', 'noun']) {
    check(`place-search.js references the additive '${optionName}' option`,
        new RegExp('\\b' + optionName + '\\b').test(placeSearch));
}
check("place-search.js's default pickMode is 'upsert' (today's POST-upsert flow, untouched)",
    /pickMode\s*===\s*'value'\s*\?\s*'value'\s*:\s*'upsert'/.test(placeSearch)
    || /pickMode\s*=\s*settings\.pickMode\s*===\s*'value'/.test(placeSearch));

/* ---- 2. metadata-tab.js: the old FIELDS row is GONE ------------------- */

check(
    "metadata-tab.js has NO `['tuneName', …]` FIELDS row (the regression this phase retires — "
        + 'that row saved TuneName alone, stranding TuneId)',
    !/\[\s*'tuneName'\s*,/.test(metadataTab)
);

/* ---- 3. metadata-tab.js: the NEW bespoke control is wired correctly --- */

check("metadata-tab.js calls api.setSongTune( (the new endpoint, via the shared client)",
    /api\.setSongTune\s*\(/.test(metadataTab));
check("metadata-tab.js calls api.searchTunes( (the mount-time meter hydration read)",
    /api\.searchTunes\s*\(/.test(metadataTab));
check("metadata-tab.js's typeahead attach() passes pickMode: 'value' (no implicit upsert)",
    /pickMode\s*:\s*'value'/.test(metadataTab));
check("metadata-tab.js's typeahead attach() passes a tune-flavoured noun",
    /noun\s*:\s*\{[^}]*singular\s*:\s*'tune'/.test(metadataTab));

/* Isolate the tune control's own source window (from the tune-name label
   id to the end of the meter-hydration block) so the 'change'-not-debounced
   assertion below can't accidentally match an unrelated field elsewhere on
   the tab. Bounded generously (rule #34's #1676 lesson: widen, don't guess
   tight) rather than "no closing brace". */
const tuneBlockStart = metadataTab.indexOf("meta-tuneName");
const tuneBlock = tuneBlockStart === -1 ? '' : metadataTab.slice(tuneBlockStart, tuneBlockStart + 6000);
check("found the tune control's own source window (>= 500 chars from the first 'meta-tuneName' reference)",
    tuneBlock.length >= 500);

check("the tune input is wired on 'change' (fires on blur/pick, not a typing pause)",
    /addEventListener\(\s*'change'/.test(tuneBlock));
check(
    "the tune input's save is NOT a debounced per-keystroke write "
        + '(a debounced find-or-create would mint a junk tblTunes row per pause, #1679-precedent)',
    !/debouncedSave\s*\(\s*['"]tuneName['"]/.test(tuneBlock)
);

/* ---- 4. api-client.js's tune_search/song_tune_set map to real api2 cases */

const serverActions = new Set(
    Array.from(api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/gi)).map((m) => m[1])
);
check('parsed a plausible number of api2.php server cases (>= 20)', serverActions.size >= 20);

const EXPECTED_METHODS = {
    searchTunes: 'tune_search',
    setSongTune: 'song_tune_set',
};
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
    console.error('\nAlso run `node tests/test-place-search-keyboard.js` separately — it must stay');
    console.error('green, UNCHANGED, as the no-behaviour-change proof for the 2 pre-existing');
    console.error('place-search.js consumers this generalisation must not disturb (#1741 P5c).');
    process.exit(1);
}
console.log('\nAll tune-typeahead contract assertions passed.');
console.log('Remember: also run `node tests/test-place-search-keyboard.js` — it must stay green, unchanged.');
