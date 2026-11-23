/**
 * tests/test-recent-songbooks-sync.js - the songbooks a person opened lately follow their account (PR 2b step 2b-6, #2177)
 *
 * ELI5
 * ----
 * Opening a songbook adds it to the front of a short "recent songbooks" list kept in the browser. With the new sync that list is
 * one small item on the account: opening a songbook queues it, a list from another device replaces this one, and on a shared
 * computer the list belongs to one person and is put aside when a different person signs in. This runs the real Router method
 * that records the visit, the real sync, and the real "put one person's data aside" code.
 *
 * Detailed (rule #34): the entry comes from the real `php tools/dump-profile-schema.php` (list of up to 20 abbreviations, cleared
 * at sign-out, not claimed, never in the cookie). Mutation proof (in the commit): remove the syncStorage call from
 * trackRecentSongbook and the "queued" row goes red.
 *
 * Usage: node tests/test-recent-songbooks-sync.js
 */
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const JS = path.join(REPO, 'appWeb', 'public_html', 'js');
const url = (p) => pathToFileURL(p).href;
let failures = 0;
const check = (label, cond, detail = '') => { console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + detail)); if (!cond) failures++; };
const J = JSON.stringify;

const schema = JSON.parse(execFileSync('php', [path.join(REPO, 'tools', 'dump-profile-schema.php')], { encoding: 'utf8' }));
const e = schema.entries.find((x) => x.id === 'library.recentSongbooks');
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
const { window } = dom;
Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event, HTMLElement: window.HTMLElement });
Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
const C = await import(url(path.join(JS, 'constants.js')));
const { Router } = await import(url(path.join(JS, 'modules', 'router.js')));
const owner = await import(url(path.join(JS, 'modules', 'account-data-owner.js')));

console.log('\n1 - the registry entry\n');
check('up to 20 abbreviations, an account setting that syncs, cleared at sign-out, not claimed, never in the cookie',
    e && e.type === 'list' && e.maxItems === 20 && e.scope === 'account' && e.sync === 'profile_sync' && e.clearAtSignOut === true && e.claim === false && e.cookie === false && e.legacyWebKey === C.STORAGE_RECENT_SONGBOOKS);
check('it is one of the keys set aside when a different person signs in on this device', owner.PERSONAL_DATA_KEYS.includes(C.STORAGE_RECENT_SONGBOOKS));

console.log('\n2 - opening a songbook\n');
const posted = []; let serverRows = []; let serverCursor = 0;
globalThis.fetch = async (input, init = {}) => {
    const u = new URL(String(input), 'https://example.test/');
    const mk = (b) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => b, clone() { return { json: async () => b }; } });
    if (u.searchParams.get('action') === 'profile_schema') return mk(schema);
    if ((init.method || 'GET') === 'POST') posted.push(JSON.parse(init.body));
    return mk({ ok: true, cursor: serverCursor, more: false, resetCursor: false, schemaVersion: schema.schemaVersion, changes: serverRows, rejected: [], applied: 0 });
};
const { ProfileSync } = await import(url(path.join(JS, 'modules', 'profile-sync.js')) + '?t=' + Math.random());
let sync;
const app = { config: { apiUrl: '/api', profileSync: { enabled: true } }, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: 1 }) }, syncStorage(k, o) { sync.recordLocalChange(k, o || {}); } };
sync = new ProfileSync(app, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
await sync.ensureSchema();
const router = Object.create(Router.prototype); router.app = app;
router.trackRecentSongbook('MP'); router.trackRecentSongbook('CP'); router.trackRecentSongbook('MP');
check('the list keeps the newest first, no repeats', localStorage.getItem(C.STORAGE_RECENT_SONGBOOKS) === '["MP","CP"]');
check('opening a songbook queues the whole list as one item', J(Object.keys(sync.getOutbox().items)) === '["library.recentSongbooks"]' && J(sync.getOutbox().items['library.recentSongbooks'].value) === '["MP","CP"]');
await sync.push();
check('the push sends the real list', posted.length === 1 && J(posted[0].changes) === J([{ section: 'library', key: 'recentSongbooks', op: 'set', value: ['MP', 'CP'] }]) && sync.pendingCount() === 0);
localStorage.setItem(C.STORAGE_RECENT_SONGBOOKS, '["MP-1"]');
app.syncStorage(C.STORAGE_RECENT_SONGBOOKS);
check('a list that is not valid (a song id is not a songbook) is never sent', sync.pendingCount() === 0);

console.log('\n3 - a list from another device\n');
serverRows = [{ section: 'library', key: 'recentSongbooks', value: ['SDAH', 'MP'], deleted: false, version: 9 }]; serverCursor = 9;
await sync.pull();
check('it replaces this device\'s list, stored as the same text the page writes', localStorage.getItem(C.STORAGE_RECENT_SONGBOOKS) === '["SDAH","MP"]');
check('...and is not queued to be sent back', sync.pendingCount() === 0);

console.log('\n4 - a shared computer\n');
const store = window.localStorage;
store.setItem(C.STORAGE_RECENT_SONGBOOKS, '["SDAH","MP"]');
const r = owner.stashAndClear(store, 1);
check('when a different person signs in, the first person\'s recent songbooks are put aside and cleared from view', r.stashed === true && store.getItem(C.STORAGE_RECENT_SONGBOOKS) === null && (store.getItem(C.STORAGE_STASH_PREFIX + '1') || '').includes('SDAH'));

console.log(failures === 0 ? '\nAll recent-songbook checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
