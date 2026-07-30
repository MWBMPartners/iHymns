/**
 * iHymns — setlist collaboration client wiring (#1638)
 * ============================================================================
 *
 * Setlist collaboration shipped WRITE-ONLY on every platform. The owner-facing
 * half worked; the collaborator-facing half did not exist. In particular
 * `setlist_collab_shared_with_me` was a working endpoint with ZERO callers
 * across JS, Swift and Kotlin — the classic silent no-op (#1565 / #1581 /
 * #1626 class): nothing errors, the feature simply is not there.
 *
 * This suite guards the client half against regressing back to that state:
 *
 *   1. the read endpoint is actually CALLED from setlist.js;
 *   2. shared setlists are rendered, and rendered as DISTINCT from your own;
 *   3. THE SYNC BOUNDARY — shared data never enters the local setlist store
 *      that `user_setlists_sync` posts back in 'replace' mode. This is the
 *      security-relevant assertion: a shared list written into
 *      localStorage['ihymns_setlists'] would be synced back as if the
 *      collaborator owned it;
 *   4. writes go to `setlist_collab_update` (whose permission the SERVER
 *      re-checks) rather than to the owner-scoped bulk sync;
 *   5. every request goes through `apiFetch`, never a bare `fetch()` (#1031).
 *
 * Source analysis, not execution: setlist.js pulls in the whole app graph
 * (DOM, localStorage, the sync layer), so importing it here is not viable.
 * Comments are stripped first — this file's own explanatory prose mentions
 * `localStorage` and `fetch(` while describing what must NOT happen, and a
 * naive substring check against raw source would trip on it. The stripper is
 * the same character-level state machine used by tests/test-arrangement-a11y.js.
 *
 *   node tests/test-setlist-collab-client.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
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
 * Comment stripper (shape shared with tests/test-arrangement-a11y.js).
 *
 * Strips // and /* *​/ comments while copying string / template content
 * through VERBATIM — the markup and endpoint names this suite inspects live
 * inside template literals, so blanking strings would blind every check.
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
    stripComments("apiFetch('/api?action=setlist_collab_update');").includes('setlist_collab_update'));

check('the SAME text inside a // comment is removed',
    !stripComments('// never call setlist_collab_update here\nconst x = 1;').includes('setlist_collab_update'));

check('the SAME text inside a block comment is removed',
    !stripComments('/* setlist_collab_update is described here */\nconst x = 1;').includes('setlist_collab_update'));

const raw = fs.readFileSync(SETLIST_JS, 'utf8');
const src = stripComments(raw);

/* ------------------------------------------------------------------------
 * 1. The read half finally has a caller
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 1 — the shared-with-me endpoint is actually called:');

check('setlist.js calls setlist_collab_shared_with_me',
    src.includes('action=setlist_collab_shared_with_me'),
    'the endpoint had zero callers on every platform before #1638');

check('it is called from the set-list overview render path',
    /_renderSharedWithMe\s*\(/.test(src)
    && src.split('_renderSharedWithMe').length >= 3,   /* definition + >=1 call */
    'a helper that is defined but never invoked is the same silent no-op');

check('anonymous users are not made to fire a request guaranteed to 401',
    /_renderSharedWithMe[\s\S]{0,900}(isLoggedIn|STORAGE_AUTH_TOKEN)/.test(src));

/* ------------------------------------------------------------------------
 * 2. Shared lists are visible AND distinguishable
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 2 — shared setlists are rendered, and marked as shared:');

check('there is a dedicated "Shared with me" section',
    /Shared with me/.test(src));

check('each row names the owner',
    /Shared by/.test(src));

check('each row shows the permission level',
    /Can edit/.test(src) && /View only/.test(src));

check('the permission and owner are in the row accessible name, not just a badge',
    /aria-label=[^\n]*shared by/i.test(src),
    'a screen-reader user must know whose list it is before opening it');

check('a view-only collaborator is told why they cannot edit',
    /view-only access/i.test(src));

check('a shared list has its own detail renderer, separate from the own-list one',
    /renderSharedSetListDetail\s*\(/.test(src) && /renderSetListDetail\s*\(/.test(src));

/* ------------------------------------------------------------------------
 * 3. THE SYNC BOUNDARY — the security-relevant assertion
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 3 — shared data never enters the synced local store:');

/* Isolate the collaboration block: from the shared-with-me section marker to
   the own-list detail renderer that follows it. Everything the collaboration
   code does lives in here. */
const collabStart = src.indexOf('_renderSharedWithMe(container');
const collabEnd   = src.indexOf('renderSetListDetail(listId)', collabStart);
const collabBlock = (collabStart >= 0 && collabEnd > collabStart)
    ? src.slice(collabStart, collabEnd)
    : '';

check('the collaboration block was located for inspection',
    collabBlock.length > 1000,
    `found ${collabBlock.length} chars`);

check('collaboration code never writes to localStorage',
    !/localStorage\.setItem/.test(collabBlock),
    'a shared list in ihymns_setlists is posted back by user_setlists_sync as if owned');

check('collaboration code never calls saveAll() (the store + sync entry point)',
    !/this\.saveAll\s*\(/.test(collabBlock));

check('collaboration code never schedules an owner-scoped sync',
    !/_scheduleSync\s*\(/.test(collabBlock));

check('collaboration code never calls the own-list mutators',
    !/this\.(moveSong|removeSong|rename|delete)\s*\(/.test(collabBlock),
    'those persist to localStorage — the store shared data must stay out of');

check('shared lists are held in an in-memory field instead',
    /this\._sharedLists\s*=/.test(collabBlock));

/* ------------------------------------------------------------------------
 * 4. Writes go through the permission-checked endpoint
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 4 — collaborative edits use the gated write endpoint:');

check('edits POST to setlist_collab_update',
    /action=setlist_collab_update/.test(collabBlock));

check('the write is a POST',
    /action=setlist_collab_update[\s\S]{0,300}method:\s*'POST'/.test(collabBlock));

check('the write sends X-Requested-With (the same-origin CSRF signal, #1590)',
    /action=setlist_collab_update[\s\S]{0,600}X-Requested-With/.test(collabBlock));

check('the write identifies the OWNER, not just the setlist id',
    /ownerId:\s*shared\.ownerId/.test(collabBlock),
    'setlistId is unique only per user, so the owner must be named');

check('edit controls are only rendered for the edit permission',
    /canEdit\s*=\s*shared\.permission === 'edit'/.test(collabBlock));

check('the renderer returns early for a view-only collaborator before binding edit handlers',
    /if \(!canEdit\) return;/.test(collabBlock),
    'an affordance, not the enforcement — the server re-checks on every write');

check('a refused save is surfaced rather than silently swallowed',
    /Save failed/.test(collabBlock));

/* ------------------------------------------------------------------------
 * 5. House rules
 * ---------------------------------------------------------------------- */
console.log('\nAssertion 5 — house rules (#1031, #1533):');

check('every collaboration request goes through apiFetch, never bare fetch()',
    !/[^.\w]fetch\s*\(/.test(collabBlock) && /apiFetch\s*\(/.test(collabBlock),
    'a bare fetch() skips the cross-cutting request concerns api-client.js adds');

check('playback reuses the existing source:\'shared\' vocabulary, not a third notion',
    /source:\s*'shared'/.test(collabBlock)
    && !/source:\s*'collab'/.test(collabBlock));

check('the notification deep link (?shared=ownerId:setlistId) is honoured',
    /_openSharedByKey\s*\(/.test(src)
    && src.split('_openSharedByKey').length >= 3
    && /URLSearchParams/.test(src),
    'an ActionUrl nothing reads is the same broken promise the issue is about');

check('the shared section removes any previous instance before appending',
    /setlist-shared-with-me'\)\?\.remove\(\)/.test(collabBlock),
    'renderSetListOverview can re-run while the fetch is in flight');

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll setlist-collaboration client assertions passed.');
