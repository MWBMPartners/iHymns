/**
 * tests/test-router-title-coverage.js — every page key router.js can
 * return has a real document title (a11y audit 2026-08-30, guard G5)
 *
 * ELI5: every page in the app needs the little text in the browser tab
 * (and the window/history entry a screen-reader user reads out) to say
 * something real — "Amazing Grace — iHymns", not just "iHymns". This
 * file reads router.js itself, works out every page name the router can
 * ever produce, and fails the build if one of them has nowhere to get a
 * real title from.
 *
 * WHY THIS GUARD HAD TO BE WRITTEN (M1, WCAG 2.4.2 Page Titled)
 * ----------------------------------------------------------------
 * router.js's `updateTitle()` map had NO entry at all for the `publisher`,
 * `tune` and `work` pages (they silently fell back to the bare app name),
 * and — more importantly — the record pages that DID have an entry
 * (song, songbook, tag, musician, setlist-shared) used a single GENERIC
 * title for every record of that type forever: every one of ~14,000
 * different songs was titled "Song — iHymns". Fixed by adding the missing
 * static entries AND a `DYNAMIC_TITLE_PAGES` set (router.js) that
 * re-titles those record pages from their own rendered <h1> once the
 * fragment lands (owner decision D4). This guard is what keeps a THIRD
 * page type from silently missing both in the future.
 *
 * WHAT COUNTS AS "COVERED": a page key found in EITHER the static
 * `titles` object literal inside `updateTitle()`, OR the
 * `DYNAMIC_TITLE_PAGES` set. `login` is the one STRUCTURAL exception —
 * `handleCurrentRoute()` returns early for it (straight to the auth
 * modal) before `updateTitle()` is ever called, verified below rather
 * than assumed.
 *
 * DERIVATION (tree-derived, rule #34 — never a hand-typed page list): the
 * full set of page keys `parseRoute()` can return is read out of
 * router.js's OWN switch statement — every literal `page: '…'` value,
 * PLUS (for the one case that reuses the matched URL segment as the page
 * key itself, `page: segments[0]`, used by the iswc/ipi/isni/ccli/bowi/
 * isrc identifier-scheme group) every `case '…':` label feeding that
 * specific return statement.
 *
 * MUTATION PROOF (rule #34): (1) deleting the titles-map's `search` entry
 * (which has no dynamic-title fallback) proves the guard goes red on a
 * genuinely uncovered key; (2) deleting `song` from DYNAMIC_TITLE_PAGES
 * ONLY proves the guard correctly stays green (the static map's own
 * `song` entry still covers it as a fallback) rather than being
 * over-eager; (3) deleting `song` from BOTH places at once proves the
 * guard goes red — the exact "song has no title anywhere" regression
 * this guard exists to catch, per the audit's own recommended proof.
 *
 * Usage: node tests/test-router-title-coverage.js
 * Exit 0 = pass, 1 = fail.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');
const ROUTER_PATH = path.join(PUB, 'js', 'modules', 'router.js');

let passed = 0;
let failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* Bound a method's body from its 4-space-indented `name(args) {` DEFINITION
 * line to the next such definition (or end of source) — the SAME bounded-
 * window technique tests/php/test-link-emphasis-mode.php already uses for
 * settings.js's applyTheme(). */
function methodBody(src, methodNamePattern) {
    const startRe = new RegExp(`\\n\\s{4}${methodNamePattern}\\s*\\([^)]*\\)\\s*\\{`);
    const startMatch = startRe.exec(src);
    if (!startMatch) { return null; }
    const bodyStart = startMatch.index + startMatch[0].length;
    const nextRe = /\n\s{4}\w+\([^)]*\)\s*\{/;
    const rest = src.slice(bodyStart);
    const nextMatch = nextRe.exec(rest);
    return nextMatch ? rest.slice(0, nextMatch.index) : rest;
}

/** Every page key parseRoute() can hand back — see file header. */
function derivePageKeys(src) {
    const body = methodBody(src, 'parseRoute');
    if (body === null) { return null; }

    const keys = new Set();
    const literalRe = /page:\s*'([\w-]+)'/g;
    let m;
    while ((m = literalRe.exec(body)) !== null) { keys.add(m[1]); }

    const dynIdx = body.indexOf('page: segments[0]');
    if (dynIdx !== -1) {
        /* lastIndexOf's search STARTS at dynIdx and looks backward — but
           "return {" is the literal 9 characters immediately before
           "page: segments[0]" in THIS SAME return statement, so a naive
           `lastIndexOf('return {', dynIdx)` finds itself, not the
           PREVIOUS return. Back up past it first. */
        const priorReturnIdx = body.lastIndexOf('return {', dynIdx - 10);
        const windowStart = priorReturnIdx === -1 ? 0 : priorReturnIdx;
        const caseWindow = body.slice(windowStart, dynIdx);
        const caseRe = /case\s+'([\w-]+)':/g;
        let cm;
        while ((cm = caseRe.exec(caseWindow)) !== null) { keys.add(cm[1]); }
    }
    return keys;
}

function extractTitlesMapKeys(src) {
    const body = methodBody(src, 'updateTitle');
    if (body === null) { return null; }
    const openIdx = body.indexOf('const titles = {');
    if (openIdx === -1) { return null; }
    const closeIdx = body.indexOf('};', openIdx);
    if (closeIdx === -1) { return null; }
    const mapBody = body.slice(openIdx, closeIdx);
    const keys = new Set();
    const re = /'([\w-]+)':/g;
    let m;
    while ((m = re.exec(mapBody)) !== null) { keys.add(m[1]); }
    return keys;
}

function extractDynamicTitlePages(src) {
    const openIdx = src.indexOf('const DYNAMIC_TITLE_PAGES = new Set([');
    if (openIdx === -1) { return null; }
    const closeIdx = src.indexOf(']);', openIdx);
    if (closeIdx === -1) { return null; }
    const body = src.slice(openIdx, closeIdx);
    const keys = new Set();
    const re = /'([\w-]+)'/g;
    let m;
    while ((m = re.exec(body)) !== null) { keys.add(m[1]); }
    return keys;
}

/** The one structural exemption — see file header. */
const LOGIN_EXEMPT = 'login';

function findUncovered(pageKeys, titleKeys, dynamicKeys) {
    const uncovered = [];
    for (const key of pageKeys) {
        if (key === LOGIN_EXEMPT) { continue; }
        if (titleKeys.has(key) || dynamicKeys.has(key)) { continue; }
        uncovered.push(key);
    }
    return uncovered;
}

/* ------------------------------------------------------------------------
 * Read the real file.
 * ---------------------------------------------------------------------- */
const routerSrc = fs.readFileSync(ROUTER_PATH, 'utf8');

const pageKeys = derivePageKeys(routerSrc);
check('parseRoute() body was found and parsed', pageKeys !== null);
check(`a plausible number of page keys were derived (${pageKeys ? pageKeys.size : 0})`,
    pageKeys !== null && pageKeys.size >= 25,
    'expected >= 25 distinct page keys (home, song, songbook, …, iswc/ipi/isni/ccli/bowi/isrc, login, not-found) — '
    + 'the parser under-read parseRoute()\'s switch');

const titleKeys = extractTitlesMapKeys(routerSrc);
check('updateTitle()\'s titles map was found and parsed', titleKeys !== null);

const dynamicKeys = extractDynamicTitlePages(routerSrc);
check('DYNAMIC_TITLE_PAGES was found and parsed', dynamicKeys !== null);

/* Structural check for the one exemption (see file header). */
const loginEarlyReturnIdx = routerSrc.indexOf("page === 'login'");
const updateTitleCallIdx = routerSrc.indexOf('this.updateTitle(page, params)');
check("'login' is structurally exempt (handleCurrentRoute() returns early for it, before this.updateTitle() is "
    + 'ever reached, so it never needs a title-map entry)',
    loginEarlyReturnIdx !== -1 && updateTitleCallIdx !== -1 && loginEarlyReturnIdx < updateTitleCallIdx);

/* ------------------------------------------------------------------------
 * Assertion 1 — the real coverage check.
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 1 — every derived page key is covered:');
if (pageKeys !== null && titleKeys !== null && dynamicKeys !== null) {
    const uncovered = findUncovered(pageKeys, titleKeys, dynamicKeys);
    check(`all ${pageKeys.size} page key(s) are covered by updateTitle()'s map or DYNAMIC_TITLE_PAGES`,
        uncovered.length === 0,
        uncovered.length ? `uncovered: ${uncovered.join(', ')} — add a titles map entry or DYNAMIC_TITLE_PAGES membership` : '');

    /* Print the full derived list for a human skimming CI output. */
    console.log(`  (derived page keys: ${[...pageKeys].sort().join(', ')})`);
}

/* ------------------------------------------------------------------------
 * Assertion 2 — mutation proof (rule #34, in memory only).
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 2 — mutation proof:');
if (pageKeys !== null && titleKeys !== null && dynamicKeys !== null) {
    check("MUTATION: removing 'search' from the titles-map set (it has no dynamic-title fallback) makes it "
        + 'uncovered',
        findUncovered(pageKeys, new Set([...titleKeys].filter((k) => k !== 'search')), dynamicKeys)
            .includes('search'));

    check("MUTATION: removing 'song' from DYNAMIC_TITLE_PAGES ONLY still leaves it covered (the static map's "
        + "own 'song' entry is a valid fallback — the guard must not be over-eager)",
        !findUncovered(pageKeys, titleKeys, new Set([...dynamicKeys].filter((k) => k !== 'song')))
            .includes('song'));

    check("MUTATION: removing 'song' from BOTH the titles map AND DYNAMIC_TITLE_PAGES makes it uncovered — the "
        + 'exact "a page type has no title anywhere" regression this guard exists to catch',
        findUncovered(
            pageKeys,
            new Set([...titleKeys].filter((k) => k !== 'song')),
            new Set([...dynamicKeys].filter((k) => k !== 'song')),
        ).includes('song'));
}

/* ------------------------------------------------------------------------ */
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll router page keys are titled.');
