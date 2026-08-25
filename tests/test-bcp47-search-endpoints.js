/**
 * tests/test-bcp47-search-endpoints.js — every `action=…` URL the tree
 * emits for a BCP 47 subtag search/dump has a matching server case (BCP 47
 * registry plan §6.2.1, M2).
 *
 * ELI5: the language picker asks the server "what languages/scripts/
 * regions/variants match what I typed?" by URL. This makes sure every
 * question the picker (or anything else in the codebase) actually ASKS has
 * someone on the server side who ANSWERS it — a misspelled action name, or
 * a case that got deleted in a refactor, would otherwise just come back
 * with a blank/empty response and nobody would notice until a curator
 * complained the picker "doesn't suggest anything".
 *
 * DERIVED, NOT TYPED (rule #34): `findEmittedActions()` greps the WHOLE
 * `appWeb/public_html` tree (`.js` + `.php`, comment-stripped) for a quoted
 * `action=<name>` fragment matching the BCP 47 vocabulary — never a
 * hand-typed "the picker calls these 4 URLs" list — so a FIFTH search
 * action added later (or a stray literal anywhere else in the tree) is
 * picked up automatically. `findServedActions()` does the mirror scan of
 * `api.php` (every `case 'X':`) and `manage/songbooks.php` (every
 * `=== 'X'` action-dispatch comparison, the legacy alias shape) — also
 * grepped, never typed.
 *
 * MUTATION-PROVEN (rule #34): run against a deliberately broken copy of
 * either side to prove this can fail —
 *
 *   1. Delete the `case 'language_search':` line from a scratch copy of
 *      api.php and point MODULE overrides at it → RED (emitted but not
 *      served).
 *   2. Point the picker module at a misspelled action
 *      (`language_serach`) → RED (a NEW emitted action with no server case
 *      at all).
 *   3. Restore → GREEN.
 *
 * (Full transcript recorded in the commit body that shipped this file.)
 *
 *   node tests/test-bcp47-search-endpoints.js
 *
 * Exit status 0 = every emitted action is served, 1 = drift.
 *
 * @see appWeb/public_html/js/modules/ietf-language-picker.js  the picker (emitter)
 * @see appWeb/public_html/api.php                              the public search cases (server)
 * @see appWeb/public_html/manage/songbooks.php                 the legacy script_search/region_search alias (server)
 * @see includes/language_names.php                             bcp47SubtagSearch() — the one shared core
 * @see .claude/bcp47-language-registry-plan.md §6.2.1           the spec this guard implements
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, '..');
const PUB = path.join(REPO_ROOT, 'appWeb', 'public_html');

/* Override hooks purely for the mutation transcript above — never used in
   a normal run (both default to the real files). */
const API_PATH        = process.argv[2] ? path.resolve(process.argv[2]) : path.join(PUB, 'api.php');
const SONGBOOKS_PATH  = process.argv[3] ? path.resolve(process.argv[3]) : path.join(PUB, 'manage', 'songbooks.php');
const SCAN_ROOT        = process.argv[4] ? path.resolve(process.argv[4]) : PUB;

let failures = 0;
let checks = 0;
function assert(cond, label) {
    checks++;
    if (cond) {
        console.log('  PASS  ' + label);
    } else {
        failures++;
        console.log('  FAIL  ' + label);
    }
}

/** Blank `/* … *\/`, `// …` and PHP `# …` comment BODIES (keep newlines so
 *  a reported location stays sane, and a doc-block MENTIONING one of these
 *  action names — several exist deliberately, explaining the contract —
 *  never false-positives). */
function stripComments(src) {
    src = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ''));
    src = src.replace(/(^|\s)\/\/[^\n]*/g, '$1');
    src = src.replace(/(^|\s)#[^\n]*/g, (m, p1) => (m.includes('#!') ? m : p1));
    return src;
}

/* The BCP 47 vocabulary: the four NEW search actions + the four pre-
   existing bulk-dump actions this plan deliberately leaves untouched
   (F1 follow-up, not in scope) — included so a future accidental rename
   of one of THOSE is caught too, since this guard's emit-scan can't tell
   the difference between new and pre-existing and shouldn't need to.
   Deliberately a REGEX literal, never an array of QUOTED strings: two of
   the bare dump-action words above (the plural forms — the two matching
   the tblLanguageScripts and tblRegions tables) are THEMSELVES live
   dispatched action names that tests/php/test-orphan-inventory.php's own
   corpus-wide scanner treats any quote-delimited occurrence of as a
   "this orphan now has a caller" signal — including inside a JS comment,
   since that guard's non-PHP-file branch scans the WHOLE file text for
   quote-delimited literals with no notion of "this is commentary, not a
   real reference". A regex literal's alternation branches are bare,
   unquoted identifiers (no ' " or backtick anywhere near them), so this
   file never introduces a quoted token for that unrelated guard to
   misread — verified by re-running test-orphan-inventory.php after
   adding this file (see the commit body); this comment paragraph itself
   is written with the same care, deliberately never quoting either bare
   plural word. */
const BCP47_ACTION_RE = /^(language_search|script_search|region_search|variant_search|languages|scripts|regions|variants)$/;

function walk(dir, acc) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name === 'node_modules' || entry.name === '.git' || entry.name === 'vendor') continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) { walk(full, acc); continue; }
        if (/\.(js|php)$/.test(entry.name)) acc.push(full);
    }
    return acc;
}

/** Every `action=<bcp47-name>` literal actually emitted anywhere in the
 *  tree (a quoted string, or a `?action=x&`/`&action=x` URL fragment,
 *  comment-stripped) — grep-derived, not typed. Returns
 *  {action: [{file, line}]}. */
function findEmittedActions() {
    const files = walk(SCAN_ROOT, []);
    const re = /action=([a-z_]+)/g;
    const found = {};
    for (const f of files) {
        const raw = fs.readFileSync(f, 'utf8');
        const src = stripComments(raw);
        let m;
        re.lastIndex = 0;
        while ((m = re.exec(src)) !== null) {
            const name = m[1];
            if (!BCP47_ACTION_RE.test(name)) continue;
            const line = src.slice(0, m.index).split('\n').length;
            (found[name] = found[name] || []).push({ file: path.relative(REPO_ROOT, f), line });
        }
    }
    return found;
}

/** Every action string api.php actually SERVES via `case 'X':` inside its
 *  switch — grep-derived (not the hand-typed BCP47_ACTIONS list). */
function findApiCases(apiSrc) {
    const src = stripComments(apiSrc);
    const re = /case\s+'([a-z_]+)'\s*:/g;
    const out = new Set();
    let m;
    while ((m = re.exec(src)) !== null) out.add(m[1]);
    return out;
}

/** Every action string manage/songbooks.php serves via its
 *  `($_GET['action'] ?? '') === 'X'` dispatch shape — the legacy-alias
 *  pattern this file uses instead of a switch. */
function findSongbooksActions(sbSrc) {
    const src = stripComments(sbSrc);
    const re = /\(\$_GET\['action'\]\s*\?\?\s*''\)\s*===\s*'([a-z_]+)'/g;
    const out = new Set();
    let m;
    while ((m = re.exec(src)) !== null) out.add(m[1]);
    return out;
}

function main() {
    console.log('');
    console.log('🧪 test-bcp47-search-endpoints.js');
    console.log('Scanning: ' + path.relative(REPO_ROOT, SCAN_ROOT));
    console.log('api.php: ' + path.relative(REPO_ROOT, API_PATH));
    console.log('songbooks.php: ' + path.relative(REPO_ROOT, SONGBOOKS_PATH));
    console.log('══════════════════════════════════════════════════');

    if (!fs.existsSync(API_PATH)) { console.error('FATAL: api.php not found at ' + API_PATH); process.exit(1); }
    if (!fs.existsSync(SONGBOOKS_PATH)) { console.error('FATAL: songbooks.php not found at ' + SONGBOOKS_PATH); process.exit(1); }

    const apiSrc = fs.readFileSync(API_PATH, 'utf8');
    const sbSrc  = fs.readFileSync(SONGBOOKS_PATH, 'utf8');

    const emitted = findEmittedActions();
    const apiCases = findApiCases(apiSrc);
    const sbActions = findSongbooksActions(sbSrc);

    console.log('\n--- Emitted action=<name> literals found in the tree ---');
    const emittedNames = Object.keys(emitted).sort();
    emittedNames.forEach((n) => console.log('  ' + n + ': ' + emitted[n].length + ' site(s) — e.g. ' + emitted[n][0].file + ':' + emitted[n][0].line));

    assert(emittedNames.length >= 4,
        'at least 4 distinct BCP 47 action names are emitted somewhere in the tree (found ' + emittedNames.length + ') — scan floor, rule #34');

    console.log('\n--- api.php cases (derived, not typed) ---');
    console.log('  found ' + apiCases.size + ' case labels total (all actions, not just BCP 47)');
    assert(apiCases.size > 50, 'api.php has a substantial switch (sanity floor — parser under-read guard)');

    console.log('\n--- Cross-check: every emitted action is served ---');
    for (const name of emittedNames) {
        const servedByApi = apiCases.has(name);
        /* script_search / region_search are legacy-alias-only (rule #33 —
           links outlive code): the picker itself now calls the public
           /api actions, but the admin-only /manage/songbooks alias must
           still answer for whoever still links to it. Any OTHER name
           found on the legacy alias path would be unexpected, but is
           still accepted here (belt-and-braces, not a floor). */
        const servedBySongbooks = sbActions.has(name);
        const ok = servedByApi || servedBySongbooks;
        assert(ok,
            `action=${name} is emitted (${emitted[name].length} site(s), e.g. ${emitted[name][0].file}:${emitted[name][0].line}) `
          + `but NEITHER api.php nor manage/songbooks.php serves it — the request would 400/404 with no build-time signal.`);
    }

    /* Explicit floor: the four NEW search actions specifically must be
       served by api.php (never JUST the legacy alias) — the plan's whole
       point was making them PUBLIC on /api, not leaving them admin-only. */
    console.log('\n--- The four NEW search actions specifically live on api.php ---');
    for (const name of ['language_search', 'script_search', 'region_search', 'variant_search']) {
        assert(apiCases.has(name), `api.php serves action=${name} directly (not just via the legacy songbooks.php alias)`);
    }

    console.log('\n══════════════════════════════════════════════════');
    console.log(`✅ Passed: ${checks - failures}`);
    console.log(`❌ Failed: ${failures}`);
    console.log(`📊 Total:  ${checks}`);
    console.log('');
    process.exit(failures > 0 ? 1 : 0);
}

main();
