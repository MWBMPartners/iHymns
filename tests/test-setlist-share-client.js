/**
 * iHymns — set-list share-link client wiring (#1791)
 * ============================================================================
 *
 * The SERVER half of #1791 (collab-by-link) landed ahead of the client:
 * `setlist_share` grew `scope`/`showSharerName`/`editAudience`, `setlist_get`
 * grew `canWrite`/`lockReason`/`sharedByName`, and three endpoints
 * (`setlist_token_update`, `setlist_share_list`, `setlist_share_revoke`) sat
 * allowlisted as orphans in `tests/php/fixtures/orphan-allowlist.php` — a
 * live, working feature nothing called (the #1565/#1626 silent-no-op class).
 *
 * This suite guards the CLIENT half against regressing back to that state,
 * and pins the specific contracts the server actually promises (rule #35 —
 * cross-file agreement needs a mechanism, not a comment):
 *
 *   1. `setlist_token_update`'s 401 `{error, reason:'signin_required'}` is
 *      branched on by STATUS + REASON, never by matching the error prose.
 *   2. The mint response's `editAudience`/`showSharerName` are READ back
 *      (not just sent) — an org may clamp the requested audience stricter,
 *      and the client must reflect what was actually STORED, not assume its
 *      own request was honoured verbatim.
 *   3. The three new share-management calls all go through `apiFetch()` /
 *      `apiFetchJson()`, never a bare `fetch()` (#1031, rule #31).
 *   4. The row-move/remove markup + wiring is ONE shared pair of helpers
 *      consumed by BOTH the email-collaborator detail view
 *      (`renderSharedSetListDetail()`) and the public token-edit surface
 *      (`initSharedSetListPage()`), not a second fork of the row template
 *      (the modularity rule; the #1216/#1638 precedent for what NOT to do).
 *
 * Source analysis, not execution — setlist.js pulls in the whole app graph
 * (DOM, localStorage, the sync layer), so importing it here is not viable.
 * Comments are stripped first (same character-level stripper as
 * tests/test-setlist-collab-client.js) so this file's own explanatory prose
 * — which necessarily mentions `fetch(`, `signin_required`, etc. while
 * describing what must/must not happen — can't false-positive a check
 * against the SOURCE it inspects.
 *
 *   node tests/test-setlist-share-client.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * MUTATION-PROVEN (rule #34): every assertion group below was deliberately
 * broken in the working tree, observed to go red, then restored via
 * `git checkout -- <path>` — see the session report for the exact mutation
 * + failure text per group. A guard that has never been watched to fail is
 * not yet trusted to catch anything.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const SETLIST_JS = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'setlist.js');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* ========================================================================
 * Comment stripper (shape shared with tests/test-setlist-collab-client.js
 * and tests/test-arrangement-a11y.js). Strips // and block comments while
 * copying string / template content through VERBATIM.
 * ====================================================================== */
function stripComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    /** @type {'code'|'block'|'line'|'sq'|'dq'|'tpl'} */
    let state = 'code';

    while (i < n) {
        const c  = src[i];
        const c2 = src[i + 1];

        if (state === 'code') {
            if (c === '/' && c2 === '*') { state = 'block'; i += 2; continue; }
            if (c === '/' && c2 === '/') { state = 'line';  i += 2; continue; }
            if (c === "'") { state = 'sq';  out += c; i += 1; continue; }
            if (c === '"') { state = 'dq';  out += c; i += 1; continue; }
            if (c === '`') { state = 'tpl'; out += c; i += 1; continue; }
            out += c;
            i += 1;
            continue;
        }
        if (state === 'block') {
            if (c === '*' && c2 === '/') { state = 'code'; i += 2; continue; }
            out += (c === '\n') ? '\n' : '';
            i += 1;
            continue;
        }
        if (state === 'line') {
            if (c === '\n') { state = 'code'; out += '\n'; i += 1; continue; }
            i += 1;
            continue;
        }
        const closers = { sq: "'", dq: '"', tpl: '`' };
        const closer = closers[state];
        if (c === '\\') { out += c + (c2 ?? ''); i += 2; continue; }
        if (c === closer) { state = 'code'; out += c; i += 1; continue; }
        out += c;
        i += 1;
    }
    return out;
}

/* ------------------------------------------------------------------------
 * Assertion 0 — the stripper self-test. A guard that cannot fail is
 * worthless (#1581 precedent).
 * ---------------------------------------------------------------------- */
console.log('Assertion 0 — comment stripper self-test:');

check('a match inside real code survives stripping',
    stripComments("apiFetch('/api?action=setlist_token_update');").includes('setlist_token_update'));

check('the SAME text inside a // comment is removed',
    !stripComments('// never call setlist_token_update here\nconst x = 1;').includes('setlist_token_update'));

check('the SAME text inside a block comment is removed',
    !stripComments('/* setlist_token_update is described here */\nconst x = 1;').includes('setlist_token_update'));

const raw = fs.readFileSync(SETLIST_JS, 'utf8');
const src = stripComments(raw);

/* ------------------------------------------------------------------------
 * 1. The three server-side endpoints finally have callers
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 1 — the three #1791 endpoints are actually called:');

check('setlist.js calls setlist_share_list',
    src.includes('action=setlist_share_list'),
    'this endpoint sat allowlisted as an orphan (tests/php/fixtures/orphan-allowlist.php) until the share dialog called it');

check('setlist.js calls setlist_share_revoke',
    src.includes('action=setlist_share_revoke'));

check('setlist.js calls setlist_token_update',
    src.includes('action=setlist_token_update'),
    'this is the anonymous/token EDIT write — the whole point of #1791');

check('the owner Share dialog exists and is wired to the share button',
    /renderShareDialog\s*\(/.test(src) && src.split('renderShareDialog').length >= 3,
    'a dialog that is defined but never invoked from the UI is the same silent no-op as an uncalled endpoint');

/* ------------------------------------------------------------------------
 * 2. The 401 signin_required contract — STATUS + REASON, never prose
 *    (#1677, rule #35)
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 2 — setlist_token_update branches on 401 + reason, not error text:');

/* Isolate the token-update call + its response handling for a scoped,
   less-accidentally-true check than scanning the whole file. */
const tokUpdateStart = src.indexOf('action=setlist_token_update');
const tokUpdateBlock = tokUpdateStart >= 0 ? src.slice(tokUpdateStart, tokUpdateStart + 2000) : '';

check('the token-update call site was located for inspection',
    tokUpdateBlock.length > 500,
    `found ${tokUpdateBlock.length} chars`);

check('the response is checked for HTTP status 401',
    /status\s*===\s*401/.test(tokUpdateBlock));

check("...specifically alongside reason === 'signin_required' (both conditions, not status alone)",
    /status\s*===\s*401[\s\S]{0,120}reason\s*===\s*'signin_required'/.test(tokUpdateBlock)
    || /reason\s*===\s*'signin_required'[\s\S]{0,120}status\s*===\s*401/.test(tokUpdateBlock));

check('a 401 signin_required downgrades to the sign-in prompt (not a generic error toast)',
    /signin_required[\s\S]{0,900}signinPromptEl/.test(tokUpdateBlock)
    || /signinPromptEl[\s\S]{0,900}signin_required/.test(tokUpdateBlock));

check('the shared-page init reads setlist_get\'s lockReason to pre-render the sign-in prompt too',
    /lockReason\s*===\s*'signin_required'/.test(src),
    'the FIRST load can already know it needs sign-in, before any write is attempted');

check('the existing app auth entry point is reused (showAuthModal), not a bespoke sign-in form',
    /userAuth\?\.showAuthModal\(\s*'login'\s*\)/.test(src));

/* ------------------------------------------------------------------------
 * 3. The mint response is READ, not just sent (rule #35's other half)
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 3 — mint response fields are read back, not assumed:');

const mintFnStart = src.indexOf('async mintShareLink');
const mintFnBlock = mintFnStart >= 0 ? src.slice(mintFnStart, mintFnStart + 3000) : '';

check('mintShareLink() exists', mintFnStart >= 0);

check('mintShareLink() sends editAudience/showSharerName on the request (scope=edit only for editAudience)',
    /opts\.showSharerName/.test(mintFnBlock) && /opts\.editAudience/.test(mintFnBlock));

check('mintShareLink() returns the RESPONSE\'s editAudience, not the request\'s',
    /result\.editAudience/.test(mintFnBlock),
    'must read what the server actually stored — it may have been clamped by an org mandate');

check('mintShareLink() returns the RESPONSE\'s showSharerName too',
    /result\.showSharerName/.test(mintFnBlock));

const dialogStart = src.indexOf('renderShareDialog(listId) {');
const dialogEnd   = src.indexOf('_shareLinkRowHtml(l) {', dialogStart);
const dialogBlock = (dialogStart >= 0 && dialogEnd > dialogStart) ? src.slice(dialogStart, dialogEnd) : '';

check('the Share dialog was located for inspection',
    dialogBlock.length > 2000,
    `found ${dialogBlock.length} chars`);

check('the dialog compares the STORED editAudience against what was requested (the org-clamp note)',
    /result\.editAudience\s*===\s*'authenticated'/.test(dialogBlock)
    && /requested\s*===\s*'anyone'/.test(dialogBlock),
    'never assume a mint request for "anyone" was honoured verbatim — an org may enforce sign-in');

check('...and explains the clamp to the owner rather than silently switching the radio',
    /organisation requires/i.test(dialogBlock));

/* ------------------------------------------------------------------------
 * 4. House rule #1031 — apiFetch/apiFetchJson, never bare fetch()
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 4 — the new share/token/revoke calls use apiFetch, never bare fetch():');

check('setlist_share_list is called via apiFetch',
    /apiFetch\([^)]*setlist_share_list/.test(src.replace(/\n/g, ' ')));

check('setlist_share_revoke is called via apiFetch',
    /apiFetch\(\s*'\/api\?action=setlist_share_revoke'/.test(src));

check('setlist_token_update is called via apiFetch',
    /apiFetch\(`[^`]*setlist_token_update/.test(src));

check('mintShareLink() (setlist_share) is called via apiFetch',
    /apiFetch\(`[^`]*action=setlist_share`/.test(mintFnBlock));

check('no bare fetch() was introduced anywhere in the Share-dialog / token-edit regions',
    !/[^.\w]fetch\s*\(/.test(dialogBlock),
    'a bare fetch() skips the cross-cutting request concerns api-client.js adds (#1031)');

/* ------------------------------------------------------------------------
 * 5. ONE shared row helper, consumed by BOTH edit surfaces
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 5 — the row template + move/remove wiring is shared, not forked:');

check('sharedSetlistRowsHtml() is defined exactly once',
    src.split('function sharedSetlistRowsHtml').length === 2);

check('bindSharedSetlistRowControls() is defined exactly once',
    src.split('function bindSharedSetlistRowControls').length === 2);

check('renderSharedSetListDetail() consumes sharedSetlistRowsHtml()',
    (() => {
        const start = src.indexOf('renderSharedSetListDetail(shared) {');
        const end   = src.indexOf('importSharedSetlist(sharedData)');
        const block = (start >= 0 && end > start) ? src.slice(start, end) : '';
        return block.includes('sharedSetlistRowsHtml(') && block.includes('bindSharedSetlistRowControls(');
    })());

check('the public token-edit surface (initSharedSetListPage) ALSO consumes both helpers',
    (() => {
        const start = src.indexOf('async initSharedSetListPage');
        const end   = src.indexOf('async enrichSharedSongItems');
        const block = (start >= 0 && end > start) ? src.slice(start, end) : '';
        return block.includes('sharedSetlistRowsHtml(') && block.includes('bindSharedSetlistRowControls(');
    })());

check('the editable row CSS class (.shared-detail-song) is emitted from exactly ONE markup literal',
    src.split('class="list-group-item d-flex align-items-center gap-2 shared-detail-song"').length === 2,
    'a second occurrence would be a second, forked row template');

check('the up/down/remove button classes are wired from the ONE shared binder, not re-implemented per surface',
    src.split("querySelectorAll('.btn-shared-up')").length === 2
    && src.split("querySelectorAll('.btn-shared-down')").length === 2
    && src.split("querySelectorAll('.btn-shared-remove')").length === 2,
    'each should appear exactly once — inside bindSharedSetlistRowControls() — not once per caller');

/* ------------------------------------------------------------------------
 * 6. fetchSharedSetlist() actually returns the capability trio
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 6 — fetchSharedSetlist() surfaces canWrite/scope/lockReason/songsDetailed:');

const fetchFnStart = src.indexOf('async fetchSharedSetlist');
const fetchFnBlock = fetchFnStart >= 0 ? src.slice(fetchFnStart, fetchFnStart + 2000) : '';

check('fetchSharedSetlist() was located for inspection', fetchFnBlock.length > 500);
check('...returns canWrite', /canWrite:\s*data\.canWrite\s*===\s*true/.test(fetchFnBlock));
check('...returns scope', /scope:\s*data\.scope\s*===\s*'edit'/.test(fetchFnBlock));
check('...returns lockReason', /lockReason:/.test(fetchFnBlock));
check('...returns sharedByName', /sharedByName:/.test(fetchFnBlock));
check('...returns songsDetailed (the object shape the editor needs)', /songsDetailed:/.test(fetchFnBlock));
check('...returns the server-confirmed shareId (closing the pre-#1791 sourceId gap)',
    /shareId:\s*typeof data\.shareId/.test(fetchFnBlock));

/* ------------------------------------------------------------------------
 * 7. #1802 — the add-a-song picker: ONE mount helper, both edit surfaces,
 *    the public search endpoint via apiFetch, no place-search.js, no
 *    hardcoded cap. All derived from the tree (counts/markers), never a
 *    typed list of call sites (rule #34).
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 7 — #1802 add-a-song picker: one helper, wired into both surfaces:');

check('mountSetlistAddSongPicker() is defined exactly once',
    src.split('function mountSetlistAddSongPicker').length === 2,
    'a second definition would be a second, forked picker — the same regression #1216/#1791 already ruled out for the row template');

/* Call-site count DERIVED by counting every mention of the identifier
   followed by `(` and subtracting the ONE declaration — never a typed
   "there are two call sites" assumption. `mountSetlistAddSongPicker(` also
   matches inside `function mountSetlistAddSongPicker(...)` itself, hence
   the subtraction. */
const addSongDefCount   = (src.match(/function mountSetlistAddSongPicker\(/g) || []).length;
const addSongMentions   = (src.match(/mountSetlistAddSongPicker\(/g) || []).length;
const addSongCallSites  = addSongMentions - addSongDefCount;

check('mountSetlistAddSongPicker() has >= 2 call sites (both edit surfaces), found by count not by name',
    addSongDefCount === 1 && addSongCallSites >= 2,
    `found ${addSongCallSites} call site(s) (${addSongMentions} total mentions, ${addSongDefCount} declaration(s))`);

const addSongFnStart = src.indexOf('function mountSetlistAddSongPicker');
const addSongFnEnd   = src.indexOf('\nexport class SetList', addSongFnStart);
const addSongFnBlock = (addSongFnStart >= 0 && addSongFnEnd > addSongFnStart)
    ? src.slice(addSongFnStart, addSongFnEnd)
    : '';

check('mountSetlistAddSongPicker() was located for inspection',
    addSongFnBlock.length > 500,
    `found ${addSongFnBlock.length} chars`);

check('...searches via the EXISTING public ?action=search endpoint (no new endpoint minted, rule #40)',
    /action=search/.test(addSongFnBlock));

check('...issues that search through apiFetch(), never a bare fetch() (rule #31)',
    /apiFetch\(/.test(addSongFnBlock) && !/(?<![\w.])fetch\(/.test(addSongFnBlock));

check('...never references place-search.js — that bundle is /manage-only and must not load on this public surface',
    !addSongFnBlock.includes('place-search'));

/* Scoped to the picker function block, NOT the whole file: setlist.js is
   large and a bare `200` (an HTTP status, a timeout, a char limit) can
   legitimately appear elsewhere, so a file-wide ban would fail on correct
   unrelated code and get weakened or deleted rather than fixed (rule #34's
   second edge). The plan's guard-4 scope is "the picker/push client code" —
   the add path is where a naive `if (list.length >= 200)` cap would land;
   the server's 413 body (res.data.maxSongs) is the ONLY source of the limit
   (rule #35, mirrors the #1662 client-side-cap ban). */
check('no hardcoded 200 cap literal in the add-a-song picker — the limit only ever arrives via the 413 body',
    !/(?<!\d)200(?!\d)/.test(addSongFnBlock));

check('the token-edit surface (initSharedSetListPage) calls mountSetlistAddSongPicker() with getSongs/onPick',
    (() => {
        const start = src.indexOf('async initSharedSetListPage');
        const end   = src.indexOf('async enrichSharedSongItems');
        const block = (start >= 0 && end > start) ? src.slice(start, end) : '';
        return block.includes('mountSetlistAddSongPicker(') && block.includes('getSongs:') && block.includes('onPick:');
    })());

check('the collab-detail surface (renderSharedSetListDetail) ALSO calls mountSetlistAddSongPicker() with getSongs/onPick',
    (() => {
        const start = src.indexOf('renderSharedSetListDetail(shared) {');
        const end   = src.indexOf('importSharedSetlist(sharedData)');
        const block = (start >= 0 && end > start) ? src.slice(start, end) : '';
        return block.includes('mountSetlistAddSongPicker(') && block.includes('getSongs:') && block.includes('onPick:');
    })());

/* Shell fragment (rule #30) — static markup only. The AUTHORITATIVE guard
   for "no executable inline <script>" is tests/php/test-fragment-inline-
   scripts.php, which already scans every includes/pages/*.php (comment-
   stripped first, so a doc-comment merely mentioning the tag can't false-
   positive it — see that file's own doc-block). Re-implementing that scan
   here would be the SAME regression rule #35 bans: two places computing
   one policy with nothing keeping them in agreement. This assertion's
   only job is the one the PHP guard does NOT do: confirm the #1802 host
   block actually exists in the fragment at all (an absent host is a
   silent no-op the PHP guard would never catch — it only bans scripts,
   it doesn't require any particular markup to be present). */
const SETLIST_SHARED_PHP = path.join(__dirname, '..', 'appWeb', 'public_html', 'includes', 'pages', 'setlist-shared.php');
const setlistSharedPhpRaw = fs.readFileSync(SETLIST_SHARED_PHP, 'utf8');

check('setlist-shared.php renders the #1802 add-a-song shell (host div + input)',
    setlistSharedPhpRaw.includes('id="shared-setlist-add-song"')
    && setlistSharedPhpRaw.includes('id="shared-setlist-add-input"'));

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll setlist share-link client assertions passed.');
