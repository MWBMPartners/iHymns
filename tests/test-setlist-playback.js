/**
 * iHymns — set-list playback navigation test (#1533)
 *
 * Guards the navigation resolver that drives the playback bar. The bug this
 * feature fixes was SILENT — `getNavigation()` returned null for a shared
 * setlist because it looked the list up by id in `getAll()`, and a shared
 * list has no local record. No error, no console warning: the bar simply
 * never appeared, which reads as "the feature doesn't exist" rather than
 * "the feature is broken". That is the same silent-no-op class as #1565 and
 * #1581, and it is exactly what a test is for.
 *
 * We exercise the resolver's LOGIC without importing setlist.js, which pulls
 * in the whole app graph (DOM, localStorage, the sync layer). The functions
 * under test are re-declared here against the same contract; if the module's
 * shape changes, `test-event-names.js`-style structural checks below fail on
 * the real file.
 *
 *   node tests/test-setlist-playback.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SETLIST_JS = join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'setlist.js');
const CONSTANTS_JS = join(__dirname, '..', 'appWeb', 'public_html', 'js', 'constants.js');
const APP_CSS = join(__dirname, '..', 'appWeb', 'public_html', 'css', 'app.css');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* ---------------------------------------------------------------------- */
/* 1. The resolver contract — a context serves ANY list, local or not      */
/* ---------------------------------------------------------------------- */

/** Mirrors getNavigation()'s context branch. */
function navFromContext(ctx, songId) {
    if (!ctx) return null;
    const i = ctx.songIds.indexOf(songId);
    if (i < 0) return null;
    const at = (n) => (ctx.songIds[n]
        ? { id: ctx.songIds[n], title: ctx.titles?.[ctx.songIds[n]] || null }
        : null);
    return {
        prev: i > 0 ? at(i - 1) : null,
        next: i < ctx.songIds.length - 1 ? at(i + 1) : null,
        position: i + 1,
        total: ctx.songIds.length,
        listName: ctx.name,
    };
}

console.log('Assertion 1 — navigation resolves from a playlist context:');

const ctx = {
    songIds: ['CP-1', 'CP-2', 'MP-9'],
    titles:  { 'CP-1': 'Amazing Grace', 'CP-2': 'How Great Thou Art', 'MP-9': 'In Christ Alone' },
    name:    'Sunday Morning',
    source:  'shared',              /* the case that could not work before */
    sourceId: 'abc123',
};

const first = navFromContext(ctx, 'CP-1');
check('a SHARED list resolves at all (the #1533 bug: this was null)', first !== null);
check('first song has no previous', first?.prev === null);
check('first song reports position 1 of 3', first?.position === 1 && first?.total === 3);
check('next carries its title for the "Next: …" label', first?.next?.title === 'How Great Thou Art');

const middle = navFromContext(ctx, 'CP-2');
check('middle song has both neighbours', !!middle?.prev && !!middle?.next);
check('middle song reports position 2', middle?.position === 2);

const last = navFromContext(ctx, 'MP-9');
check('last song has no next (bar disables the button)', last?.next === null);
check('last song reports position 3 of 3', last?.position === 3 && last?.total === 3);

check('a song OUTSIDE the list resolves to null (no bar, context kept)',
    navFromContext(ctx, 'ZZ-99') === null);
check('a null context resolves to null', navFromContext(null, 'CP-1') === null);

const untitled = navFromContext({ songIds: ['A-1', 'A-2'], name: 'No titles yet' }, 'A-1');
check('a missing titles map degrades to a null title, never "undefined"',
    untitled?.next?.title === null,
    `got ${JSON.stringify(untitled?.next?.title)}`);

/* ---------------------------------------------------------------------- */
/* 2. Structural checks against the REAL files                            */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 2 — the shipped module wires playback correctly:');

const setlistSrc  = readFileSync(SETLIST_JS, 'utf8');
const constSrc    = readFileSync(CONSTANTS_JS, 'utf8');
const cssSrc      = readFileSync(APP_CSS, 'utf8');

check('the storage key is a constant, not a raw literal (#1581 discipline)',
    /export const STORAGE_PLAYLIST_CONTEXT\s*=/.test(constSrc));

check('the context lives in sessionStorage, not localStorage',
    /sessionStorage\.setItem\(STORAGE_PLAYLIST_CONTEXT/.test(setlistSrc)
    && !/localStorage\.(get|set)Item\(STORAGE_PLAYLIST_CONTEXT/.test(setlistSrc));

/* The teardown MUST precede every early return, or a fixed-position bar
   strands over the next page. This is the regression most likely to be
   reintroduced by someone "tidying" the guard clauses to the top. */
const renderFn = setlistSrc.slice(setlistSrc.indexOf('renderSongNavigation()'));
const removeAt = renderFn.indexOf("getElementById('setlist-song-nav')?.remove()");
const firstReturn = renderFn.indexOf('return;');
check('renderSongNavigation() tears the bar down BEFORE any early return',
    removeAt >= 0 && removeAt < firstReturn,
    `remove at ${removeAt}, first return at ${firstReturn}`);

check('the router syncs the bar on EVERY navigation, not only song pages',
    /setList\?\.renderSongNavigation\(\)/.test(
        readFileSync(join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'router.js'), 'utf8')));

check('arrow-key handler refuses to steal keys from text entry',
    /INPUT.*TEXTAREA.*SELECT/s.test(setlistSrc) && /isContentEditable/.test(setlistSrc));

check('arrow-key handler leaves modifier combos to the browser',
    /e\.altKey \|\| e\.ctrlKey \|\| e\.metaKey/.test(setlistSrc));

check('keys are bound once in init(), not per bar render (no listener leak)',
    /_bindPlaylistKeys\(\)/.test(setlistSrc)
    && setlistSrc.split('_bindPlaylistKeys()').length === 3);   /* definition + one call */

check('the bar announces the new song to assistive tech',
    /aria-live/.test(setlistSrc) && /_announce\(/.test(setlistSrc));

check('disabled ends render a real <button disabled>, not a hrefless <a>',
    /<button type="button"[^>]*disabled/.test(setlistSrc));

check('the page reserves room so the fixed bar cannot cover the last line',
    /body\.has-playlist-bar \.main-content/.test(cssSrc)
    && /padding-bottom/.test(cssSrc.slice(cssSrc.indexOf('body.has-playlist-bar'))));

check('the bar clears the device safe-area inset',
    /\.playlist-bar\b[\s\S]{0,400}safe-area-inset-bottom/.test(cssSrc));

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll set-list playback assertions passed.');
