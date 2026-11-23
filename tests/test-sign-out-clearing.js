/**
 * tests/test-sign-out-clearing.js - signing out clears this browser, asking first if anything has not reached the account
 * (PR 2c-3, epic #2180, owner decision O2)
 *
 * ELI5
 * ----
 * When someone signs out on purpose, this browser should forget their personal songs, set lists and so on, so the next
 * person on a shared computer cannot see them. But if something they changed a moment ago has not reached their account,
 * forgetting it would lose it. So sign-out first sends what is waiting, then (only if something is still left) asks:
 * try again, sign out anyway, or close the box and stay signed in. This test plays that with the REAL UserAuth, Favorites,
 * SetList and ProfileSync classes in a pretend browser (jsdom) and a pretend server that can be made slow, down, or paused.
 *
 * Detailed (rule #34)
 *  1. The clearing function alone, with a plain fake Storage: every personal key goes (derived from PERSONAL_DATA_KEYS),
 *     both bookmarks, the outbox, the cursor and the owner record go; display settings and somebody else's set-aside
 *     copy stay; the searches list goes only when it follows the account. The list of things the registry says are
 *     cleared at sign-out comes from the REAL `php tools/dump-profile-schema.php`, so a new entry that is not cleared fails.
 *  2. The counting: every "waiting" store in constants.js (a key ending in _ADDED, _DELETED or _OUTBOX) is seeded and must
 *     be counted; the offline-queue types are derived from user-auth.js and must equal the list the guard counts.
 *  3. The real flow (a pretend server): nothing unsent clears with no question; a change inside the 1.5 second wait is
 *     SENT before anything is cleared; a down server asks, and nothing is cleared until the person answers; closing the
 *     box keeps everything and the sign-in; "sign out anyway" clears; "try again" that works clears without a second question;
 *     three tries then a last question; a paused site's waiting removals count; a waiting profile change counts;
 *     with the switch off nothing is flushed, asked or cleared (the older behaviour).
 *  4. MUTATION PROOFS (recorded in the 2c-3 commit): the count skipped (the "asks" rows go red), the clearing skipped,
 *     the flush skipped (the "sent before clearing" row goes red).
 *
 * Usage: node tests/test-sign-out-clearing.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');
const JS = path.join(PUB, 'js');
const MODULES = path.join(JS, 'modules');
const url = (p) => pathToFileURL(p).href;
let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + String(detail).slice(0, 300)));
    if (!cond) failures++;
};
/* The offline queue warns (console.warn) that IndexedDB is missing in jsdom each time a pretend request fails; that is expected here. */
const realWarn = console.warn;
console.warn = (...a) => { if (!String(a[0]).startsWith('[offlineQueue]')) realWarn(...a); };
const flush = (ms = 30) => new Promise((r) => setTimeout(r, ms));

class FakeStorage {
    constructor() { this.m = new Map(); }
    get length() { return this.m.size; }
    key(i) { return [...this.m.keys()][i] ?? null; }
    getItem(k) { return this.m.has(k) ? this.m.get(k) : null; }
    setItem(k, v) { this.m.set(k, String(v)); }
    removeItem(k) { this.m.delete(k); }
}

const C = await import(url(path.join(JS, 'constants.js')));
const owner = await import(url(path.join(MODULES, 'account-data-owner.js')));
const guard = await import(url(path.join(MODULES, 'sign-out-guard.js')));
const adapters = await import(url(path.join(MODULES, 'profile-sync-adapters.js')));
const schema = JSON.parse(execFileSync('php', [path.join(REPO, 'tools', 'dump-profile-schema.php')], { encoding: 'utf8' }));

/* ================= 1. the clearing function ================= */
console.log('\n1 - the clearing function\n');
{
    const st = new FakeStorage();
    for (const k of owner.PERSONAL_DATA_KEYS) st.setItem(k, k === C.STORAGE_SYNC_SEARCH_HISTORY ? 'true' : '["x"]');
    st.setItem(C.STORAGE_SETLISTS_SYNCED_AT, '2026-01-01 00:00:00');
    st.setItem(C.STORAGE_FAVORITES_SYNCED_AT, '2026-01-01 00:00:00');
    st.setItem(C.STORAGE_PROFILE_OUTBOX, '{"uid":"1","seq":1,"items":{}}');
    st.setItem(C.STORAGE_PROFILE_CURSOR, '{"uid":"1","cursor":4,"claimed":true}');
    st.setItem(C.STORAGE_DATA_OWNER_UID, '1');
    st.setItem('ihymns_theme', 'dark');
    st.setItem('ihymns_font_size', '18');
    st.setItem(C.STORAGE_STASH_PREFIX + '9', '{"someone":"else"}');
    owner.clearPersonalDataAtSignOut(st);
    const left = [...st.m.keys()].sort();
    check('every personal key is gone (derived from PERSONAL_DATA_KEYS)', owner.PERSONAL_DATA_KEYS.every((k) => st.getItem(k) === null), left.join(','));
    check('both sync bookmarks, the outbox, the cursor and the owner record are gone',
        [C.STORAGE_SETLISTS_SYNCED_AT, C.STORAGE_FAVORITES_SYNCED_AT, C.STORAGE_PROFILE_OUTBOX, C.STORAGE_PROFILE_CURSOR, C.STORAGE_DATA_OWNER_UID].every((k) => st.getItem(k) === null));
    check('display settings stay', st.getItem('ihymns_theme') === 'dark' && st.getItem('ihymns_font_size') === '18');
    check("somebody else's set-aside copy is not touched", st.getItem(C.STORAGE_STASH_PREFIX + '9') !== null);
    const once = JSON.stringify([...st.m.entries()]);
    owner.clearPersonalDataAtSignOut(st);
    check('clearing twice leaves exactly the same', JSON.stringify([...st.m.entries()]) === once);

    const off = new FakeStorage();
    off.setItem(C.STORAGE_SEARCH_HISTORY, '["hymn"]');
    owner.clearPersonalDataAtSignOut(off);
    check('a searches list that does NOT follow the account stays (the older device-only list, as before)', off.getItem(C.STORAGE_SEARCH_HISTORY) !== null);
    const on = new FakeStorage();
    on.setItem(C.STORAGE_SEARCH_HISTORY, '["hymn"]');
    on.setItem(C.STORAGE_SYNC_SEARCH_HISTORY, 'true');
    owner.clearPersonalDataAtSignOut(on);
    check('a searches list that DOES follow the account goes, with its switch', on.getItem(C.STORAGE_SEARCH_HISTORY) === null && on.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === null);
    const blocked = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); }, key() { return null; }, length: 0 };
    check('blocked storage never throws out of a sign-out', (() => { try { owner.clearPersonalDataAtSignOut(blocked); return true; } catch (_e) { return false; } })());
}
{
    /* The registry says which settings are cleared at sign-out; every one must really be. */
    const entries = schema.entries.filter((e) => e.clearAtSignOut === true);
    const keys = new Set();
    for (const e of entries) {
        if (typeof e.legacyWebKey === 'string' && e.legacyWebKey !== '') keys.add(e.legacyWebKey);
        else if (adapters.ADAPTER_ENTRY_IDS.includes(e.id)) adapters.ADAPTER_STORAGE_KEYS.forEach((k) => keys.add(k));
        else keys.add('NO-WEB-KEY:' + e.id);
    }
    check('the registry marks several entries as cleared at sign-out (' + entries.map((e) => e.id).join(', ') + ')', entries.length >= 6);
    check('every one of them has a web key or a hand-written provider', ![...keys].some((k) => k.startsWith('NO-WEB-KEY:')), [...keys].filter((k) => k.startsWith('NO-WEB-KEY:')).join());
    const st = new FakeStorage();
    for (const k of keys) st.setItem(k, 'x');
    st.setItem(C.STORAGE_SYNC_SEARCH_HISTORY, 'true');   /* the searches list is only the account's while this choice is on */
    owner.clearPersonalDataAtSignOut(st);
    const stillThere = [...keys].filter((k) => st.getItem(k) !== null);
    check('every web key the registry marks "cleared at sign-out" is gone after the clearing', stillThere.length === 0, stillThere.join());
}

/* ================= 2. the counting ================= */
console.log('\n2 - the counting\n');
{
    /* the "waiting" stores are derived from constants.js, never typed here */
    const waitingKeys = Object.entries(C).filter(([n, v]) => /^STORAGE_.*_(ADDED|DELETED|OUTBOX)$/.test(n) && typeof v === 'string');
    check('constants.js names the waiting stores (' + waitingKeys.map(([n]) => n).join(', ') + ')', waitingKeys.length >= 4);
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
    Object.assign(globalThis, { window: dom.window, document: dom.window.document, localStorage: dom.window.localStorage, CustomEvent: dom.window.CustomEvent, Event: dom.window.Event });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
    const { Favorites } = await import(url(path.join(MODULES, 'favorites.js')) + '?c=' + Math.random());
    const { SetList } = await import(url(path.join(MODULES, 'setlist.js')) + '?c=' + Math.random());
    const { ProfileSync } = await import(url(path.join(MODULES, 'profile-sync.js')) + '?c=' + Math.random());
    for (const [name, key] of waitingKeys) {
        localStorage.clear();
        const app = { config: { apiUrl: '/api', profileSync: { enabled: true } }, syncStorage() {}, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: 1 }), authHeaders: () => ({}) } };
        app.favorites = new Favorites(app);
        app.setList = new SetList(app);
        app.profileSync = new ProfileSync(app, { storage: localStorage });
        if (key === C.STORAGE_PROFILE_OUTBOX) localStorage.setItem(key, JSON.stringify({ uid: '1', seq: 1, items: { 'appearance.theme': { section: 'appearance', key: 'theme', op: 'set', value: 'dark', seq: 1 } } }));
        else localStorage.setItem(key, JSON.stringify(['MP-1']));
        const c = await guard.countUnsentChanges(app, { queueCount: async () => 0 });
        check(`${name} is counted when it holds something (total ${c.total})`, c.total === 1, JSON.stringify(c));
        localStorage.clear();
        const none = await guard.countUnsentChanges(app, { queueCount: async () => 0 });
        check(`${name} counts nothing when empty`, none.total === 0);
    }
    /* the settings upload waiting and the queue markers */
    const app2 = { settings: { hasUnsentChanges: () => true }, favorites: { getPendingAdds: () => [], getPendingDeletes: () => [] }, setList: { getPendingDeletes: () => [] } };
    check('a settings upload still waiting is counted', (await guard.countUnsentChanges(app2, { queueCount: async () => 0 })).total === 1);
    const marked = await guard.countUnsentChanges({ favorites: { getPendingAdds: () => [], getPendingDeletes: () => [] }, setList: { getPendingDeletes: () => [] } }, { queueCount: async (t) => (t === 'favorites-sync' || t === 'custom-tags-sync' ? 2 : 0) });
    check('an offline-queue "a sync is wanted" marker is counted once per kind, not once per row', marked.total === 2, JSON.stringify(marked));
    const same = await guard.countUnsentChanges({ favorites: { getPendingAdds: () => ['MP-1'], getPendingDeletes: () => [] }, setList: { getPendingDeletes: () => [] } }, { queueCount: async (t) => (t === 'favorites-sync' ? 1 : 0) });
    check('a marker is not counted again when the same change is already named by a list', same.total === 1, JSON.stringify(same));
    check('a getter that throws never blocks (counted as nothing)', (await guard.countUnsentChanges({ favorites: { getPendingAdds: () => { throw new Error('x'); } } }, { queueCount: async () => { throw new Error('y'); } })).total === 0);
    /* the queue types the guard counts are exactly the ones user-auth.js drains (rule #35) */
    const ua = fs.readFileSync(path.join(MODULES, 'user-auth.js'), 'utf8');
    const drained = [...ua.matchAll(/bindAutoDrainLatest\('([a-z-]+)'/g)].map((m) => m[1]).sort();
    check('the queue types the guard counts equal the ones user-auth.js drains (' + drained.join(', ') + ')', JSON.stringify([...guard.UNSENT_QUEUE_TYPES].sort()) === JSON.stringify(drained));
    /* every store the guard flushes has a flushNow */
    for (const [file, cls] of [['favorites.js', 'async flushNow()'], ['setlist.js', 'async flushNow()'], ['settings.js', 'async flushNow()'], ['profile-sync.js', 'async flushNow()']]) {
        check(`${file} has a flushNow()`, fs.readFileSync(path.join(MODULES, file), 'utf8').includes(cls));
    }
    const { Settings } = await import(url(path.join(MODULES, 'settings.js')) + '?c=' + Math.random());
    check('Settings.hasUnsentChanges(): true only while an upload waits AND the older sync is the one doing the work',
        Settings.prototype.hasUnsentChanges.call({ _syncPushPending: true, _isSyncEnabled: () => true }) === true
        && Settings.prototype.hasUnsentChanges.call({ _syncPushPending: true, _isSyncEnabled: () => false }) === false
        && Settings.prototype.hasUnsentChanges.call({ _syncPushPending: false, _isSyncEnabled: () => true }) === false);
    let pushed = 0;
    const fakeSettings = { _syncPushPending: true, _isSyncEnabled: () => true, _syncPushTimer: null, hasUnsentChanges: Settings.prototype.hasUnsentChanges, async _pushSyncedSettings() { pushed++; this._syncPushPending = false; return true; } };
    check('Settings.flushNow() sends a waiting upload at once, and does nothing when nothing waits', (await Settings.prototype.flushNow.call(fakeSettings)) === true && pushed === 1 && (await Settings.prototype.flushNow.call(fakeSettings)) === true && pushed === 1);
}

/* ================= 2b. the decision on its own ================= */
console.log('\n2b - the decision\n');
{
    const mk = (counts, answers) => {
        const dialogs = []; let flushes = 0; const left = [...counts];
        const app = { showChoice: async (m, o) => { dialogs.push({ m, o }); return answers.shift() ?? null; } };
        const deps = { flush: async () => { flushes++; }, count: async () => ({ total: left.length > 1 ? left.shift() : left[0], parts: {} }) };
        return { app, dialogs, deps, flushes: () => flushes };
    };
    let t = mk([0], []);
    check('nothing unsent: go, with no question', (await guard.confirmSignOut(t.app, t.deps)) === 'go' && t.dialogs.length === 0 && t.flushes() === 1);
    t = mk([2], ['option2']);
    check('"Sign out anyway" goes', (await guard.confirmSignOut(t.app, t.deps)) === 'go' && t.dialogs.length === 1 && /2 changes have not reached your account yet/.test(t.dialogs[0].m));
    t = mk([1], [null]);
    check('closing the box stays signed in', (await guard.confirmSignOut(t.app, t.deps)) === 'stay');
    t = mk([1], []);
    check('an unanswered box (closed) stays signed in', (await guard.confirmSignOut(t.app, t.deps)) === 'stay' && /^1 change has not/.test(t.dialogs[0].m));
    t = mk([3, 0], ['option1']);
    check('"Try again" that sends everything goes without a second question', (await guard.confirmSignOut(t.app, t.deps)) === 'go' && t.dialogs.length === 1 && t.flushes() === 2);
    t = mk([3, 3, 3, 3], ['option1', 'option1', 'option1', 'option1']);
    const r4 = await guard.confirmSignOut(t.app, t.deps);
    check('"Try again" is offered three times, then a last question that offers "Sign out anyway" and "Stay signed in"', t.dialogs.length === 4 && t.flushes() === 4 && t.dialogs[0].o.option1Text === 'Try again' && t.dialogs[3].o.option1Text === 'Sign out anyway' && t.dialogs[3].o.option2Text === 'Stay signed in' && r4 === 'go', JSON.stringify(t.dialogs.map((d) => d.o.option1Text)));
    t = mk([3, 3, 3, 3], ['option1', 'option1', 'option1', 'option2']);
    check('...and "Stay signed in" on the last question stays', (await guard.confirmSignOut(t.app, t.deps)) === 'stay');
    check('with no way to ask, it stays signed in rather than lose anything', (await guard.confirmSignOut({}, { flush: async () => {}, count: async () => ({ total: 1, parts: {} }) })) === 'stay');
    /* a hung store cannot trap the person */
    let cleared = false; const hung = { favorites: { flushNow: () => new Promise(() => {}) } };
    const t0 = Date.now();
    await guard.flushEverything(hung, { timeoutMs: 40 });
    check('a store that never answers is given up on after the time limit', Date.now() - t0 < 1000 && cleared === false);
    const dropped = []; let seen = [];
    await guard.flushEverything({ favorites: { flushNow: async () => true }, setList: { flushNow: async () => false }, settings: {}, profileSync: { flushNow: async () => true } }, { dropMarkers: async (t) => { dropped.push(t); } });
    check('a store that flushed clears its own queue markers; one that did not leaves them', JSON.stringify(dropped.sort()) === JSON.stringify(['custom-tags-sync', 'favorites-sync']), dropped.join());
}

/* ================= 3. the real flow ================= */
console.log('\n3 - the real classes against a pretend server\n');
const guardSrc = fs.readFileSync(path.join(MODULES, 'sign-out-guard.js'), 'utf8');
const uaSrc = fs.readFileSync(path.join(MODULES, 'user-auth.js'), 'utf8');
async function world({ enabled = true, favs = 'ok', profile = 'ok', answers = [], userAuthPath = path.join(MODULES, 'user-auth.js') } = {}) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event, requestAnimationFrame: (f) => setTimeout(f, 0) });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true, serviceWorker: undefined }, configurable: true, writable: true });
    const w = { requests: [], mode: { favs, profile }, serverFavs: [], dialogs: [], answers: [...answers] };
    const api = await import(url(path.join(JS, 'utils', 'api-client.js')));
    api.setAuthHeaderProvider(() => ({ Authorization: 'Bearer tok' }));
    globalThis.fetch = async (input, init = {}) => {
        const u = new URL(String(input), 'https://example.test/');
        const action = u.searchParams.get('action');
        const body = init.body ? JSON.parse(init.body) : null;
        w.requests.push({ action, body });
        const json = (status, obj) => new Response(JSON.stringify(obj), { status, headers: { 'Content-Type': 'application/json' } });
        if (action === 'favorites_sync') {
            if (w.mode.favs === 'down') throw new TypeError('network down');
            if (w.mode.favs === 'readonly') return json(200, { favorites: body.favorites, readOnly: true, syncedAt: '2026-02-02 00:00:00' });
            w.serverFavs = (body.favorites || []).map((f) => f.id);
            return json(200, { favorites: body.favorites, syncedAt: '2026-02-02 00:00:00' });
        }
        if (action === 'user_setlists_sync') return json(200, { setlists: body.setlists || [], tombstones: [], syncedAt: '2026-02-02 00:00:00' });
        if (action === 'custom_tags_sync') return json(200, { tags: body.tags || [] });
        if (action === 'profile_schema') return json(200, schema);
        if (action === 'profile_sync') {
            if (w.mode.profile === 'down') return json(503, { reason: 'profile_sync_unavailable' });
            return json(200, { ok: true, cursor: 5, more: false, resetCursor: false, schemaVersion: schema.schemaVersion, changes: [], rejected: [], applied: (body?.changes || []).length });
        }
        return json(200, { ok: true });
    };
    const { UserAuth } = await import(url(userAuthPath) + '?w=' + Date.now() + Math.random());
    const { Favorites } = await import(url(path.join(MODULES, 'favorites.js')) + '?w=' + Math.random());
    const { SetList } = await import(url(path.join(MODULES, 'setlist.js')) + '?w=' + Math.random());
    const { ProfileSync } = await import(url(path.join(MODULES, 'profile-sync.js')) + '?w=' + Math.random());
    const app = { config: { apiUrl: '/api', profileSync: { enabled } }, syncStorage() {}, showToast() {} };
    app.showChoice = async (message, opts) => {
        w.dialogs.push({ message, opts, signedIn: app.userAuth.isLoggedIn(), favourites: localStorage.getItem(C.STORAGE_FAVORITES), outbox: localStorage.getItem(C.STORAGE_PROFILE_OUTBOX) });
        return w.answers.length ? w.answers.shift() : null;
    };
    app.userAuth = new UserAuth(app);
    app.favorites = new Favorites(app);
    app.setList = new SetList(app);
    app.profileSync = new ProfileSync(app, { storage: localStorage });
    app.userAuth.saveCredentials('tok', { id: 1, username: 'ann' });
    app.favorites._syncReady = true; app.setList._syncReady = true;   /* the first sync after sign-in has happened */
    localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1', tags: [] }]));
    localStorage.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ id: 'sl-1', name: 'Mine', songs: [] }]));
    localStorage.setItem(C.STORAGE_CUSTOM_TAGS, JSON.stringify(['Sunday']));
    localStorage.setItem(C.STORAGE_DATA_OWNER_UID, '1');
    localStorage.setItem(C.STORAGE_PROFILE_CURSOR, JSON.stringify({ uid: '1', cursor: 2, claimed: true }));
    localStorage.setItem('ihymns_theme', 'dark');
    w.app = app;
    w.personalLeft = () => owner.PERSONAL_DATA_KEYS.filter((k) => localStorage.getItem(k) !== null);
    w.serverLogouts = () => w.requests.filter((r) => r.action === 'auth_logout').length;
    return w;
}
const addFavouriteWhileOffline = (w) => { w.app.favorites._recordAdd(['MP-2']); localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1', tags: [] }, { id: 'MP-2', tags: [] }])); };

async function scenarios(userAuthPath, label) {
    const out = {};
    /* a. nothing unsent */
    let w = await world({ userAuthPath });
    out.a = await w.app.userAuth.logout();
    out.aState = { dialogs: w.dialogs.length, personal: w.personalLeft(), theme: localStorage.getItem('ihymns_theme'), owner: localStorage.getItem(C.STORAGE_DATA_OWNER_UID), cursor: localStorage.getItem(C.STORAGE_PROFILE_CURSOR), signedIn: w.app.userAuth.isLoggedIn(), logouts: w.serverLogouts() };
    /* b. a change inside the 1.5 second wait is SENT before anything is cleared */
    w = await world({ userAuthPath });
    w.app.favorites.saveAll([{ id: 'MP-1', tags: [] }, { id: 'MP-5', tags: [] }]);   /* arms the timer */
    out.b = await w.app.userAuth.logout();
    out.bState = { dialogs: w.dialogs.length, serverHas: [...w.serverFavs], personal: w.personalLeft() };
    /* c. server down: asks, nothing cleared until answered; close the box = stay */
    w = await world({ userAuthPath, favs: 'down', answers: [null] });
    addFavouriteWhileOffline(w);
    out.c = await w.app.userAuth.logout();
    out.cState = { dialogs: w.dialogs.length, atDialog: w.dialogs[0], msg: w.dialogs[0]?.message, signedIn: w.app.userAuth.isLoggedIn(), personal: w.personalLeft(), logouts: w.serverLogouts(), pending: w.app.favorites.getPendingAdds() };
    /* d. sign out anyway */
    w = await world({ userAuthPath, favs: 'down', answers: ['option2'] });
    addFavouriteWhileOffline(w);
    out.d = await w.app.userAuth.logout();
    out.dState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), personal: w.personalLeft(), logouts: w.serverLogouts(), pending: w.app.favorites.getPendingAdds() };
    /* e. try again, and the server is back */
    w = await world({ userAuthPath, favs: 'down', answers: ['option1'] });
    addFavouriteWhileOffline(w);
    w.app.showChoice = async (message, opts) => { w.dialogs.push({ message, opts }); w.mode.favs = 'ok'; return 'option1'; };
    out.e = await w.app.userAuth.logout();
    out.eState = { dialogs: w.dialogs.length, serverHas: [...w.serverFavs], signedIn: w.app.userAuth.isLoggedIn(), personal: w.personalLeft() };
    /* f. a paused site: the removal waiting is not reported as sent */
    w = await world({ userAuthPath, favs: 'readonly', answers: [null] });
    w.app.favorites._recordDelete(['MP-1']); localStorage.setItem(C.STORAGE_FAVORITES, '[]');
    out.f = await w.app.userAuth.logout();
    out.fState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), pending: w.app.favorites.getPendingDeletes() };
    /* g. a waiting profile change, and the server cannot take it */
    w = await world({ userAuthPath, profile: 'down', answers: [null] });
    w.app.profileSync._enqueue('appearance', 'theme', 'set', 'dark');
    out.g = await w.app.userAuth.logout();
    out.gState = { dialogs: w.dialogs.length, msg: w.dialogs[0]?.message, signedIn: w.app.userAuth.isLoggedIn(), outbox: localStorage.getItem(C.STORAGE_PROFILE_OUTBOX) !== null };
    /* h. a waiting profile change the server takes: sent, then cleared with no question */
    w = await world({ userAuthPath, profile: 'ok' });
    w.app.profileSync._enqueue('appearance', 'theme', 'set', 'dark');
    out.h = await w.app.userAuth.logout();
    out.hState = { dialogs: w.dialogs.length, sentProfile: w.requests.filter((r) => r.action === 'profile_sync' && r.body?.changes?.length).length, outbox: localStorage.getItem(C.STORAGE_PROFILE_OUTBOX), personal: w.personalLeft() };
    /* i. the switch off: the older behaviour, nothing flushed, asked or cleared */
    w = await world({ userAuthPath, enabled: false, favs: 'down', answers: ['option2'] });
    addFavouriteWhileOffline(w);
    const before = w.requests.length;
    out.i = await w.app.userAuth.logout();
    out.iState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), personal: w.personalLeft(), syncRequests: w.requests.slice(before).filter((r) => /_sync$/.test(r.action)).length, logouts: w.serverLogouts() };
    /* j. a second click while the question is open */
    w = await world({ userAuthPath, favs: 'down', answers: [] });
    addFavouriteWhileOffline(w);
    let release; const held = new Promise((r) => { release = r; });
    w.app.showChoice = async (message, opts) => { w.dialogs.push({ message, opts }); await held; return null; };
    const first = w.app.userAuth.logout();
    await flush(60);
    const secondPromise = w.app.userAuth.logout();   /* the second click, while the first question is still open */
    await flush(60);
    const dialogsWhileOpen = w.dialogs.length;
    release();
    const [firstResult, second] = await Promise.all([first, secondPromise]);
    out.jState = { dialogs: dialogsWhileOpen, second, firstResult, signedIn: w.app.userAuth.isLoggedIn() };
    return out;
}
const real = await scenarios(path.join(MODULES, 'user-auth.js'), 'real');
check('nothing unsent: signed out with no question', real.a.signedOut === true && real.aState.dialogs === 0 && real.aState.signedIn === false && real.aState.logouts === 1);
check('...and the personal data, the cursor and the owner record are cleared, but the theme stays', real.aState.personal.length === 0 && real.aState.cursor === null && real.aState.owner === null && real.aState.theme === 'dark', JSON.stringify(real.aState));
check('a change still inside the 1.5 second wait is SENT first, then cleared, with no question', real.b.signedOut === true && real.bState.dialogs === 0 && real.bState.serverHas.includes('MP-5') && real.bState.personal.length === 0, JSON.stringify(real.bState));
check('a server that is down: the person is asked, and says how many (1 change)', real.cState.dialogs === 1 && /^1 change has not reached your account yet/.test(real.cState.msg || ''), real.cState.msg);
check('...and at the moment of asking nothing had been cleared and the person was still signed in', real.cState.atDialog?.signedIn === true && (real.cState.atDialog?.favourites || '').includes('MP-2'));
check('closing the box: still signed in, every saved song and the waiting change kept, no sign-out sent to the server', real.c.signedOut === false && real.cState.signedIn === true && real.cState.personal.length >= 3 && real.cState.pending.includes('MP-2') && real.cState.logouts === 0, JSON.stringify(real.cState));
check('"Sign out anyway": signed out and cleared (the waiting change is lost from this browser, as warned)', real.d.signedOut === true && real.dState.signedIn === false && real.dState.personal.length === 0 && real.dState.pending.length === 0 && real.dState.logouts === 1 && real.dState.dialogs === 1, JSON.stringify(real.dState));
check('"Try again" after the server is back: the change is SENT, then cleared, with no second question', real.e.signedOut === true && real.eState.dialogs === 1 && real.eState.serverHas.includes('MP-2') && real.eState.personal.length === 0, JSON.stringify(real.eState));
check("a paused site's removal waiting to be sent is counted and asked about (never silently lost)", real.f.signedOut === false && real.fState.dialogs === 1 && real.fState.pending.includes('MP-1') && real.fState.signedIn === true, JSON.stringify(real.fState));
check('a profile change the server cannot take is counted and asked about, and stays in the outbox while signed in', real.g.signedOut === false && real.gState.dialogs === 1 && /^1 change has/.test(real.gState.msg || '') && real.gState.outbox === true, JSON.stringify(real.gState));
check('a profile change the server takes is sent first; the outbox is then cleared with the rest', real.h.signedOut === true && real.hState.dialogs === 0 && real.hState.sentProfile >= 1 && real.hState.outbox === null && real.hState.personal.length === 0, JSON.stringify(real.hState));
check('SWITCH OFF: sign-out is exactly the older one - no flush, no question, nothing cleared', real.i.signedOut === true && real.iState.dialogs === 0 && real.iState.syncRequests === 0 && real.iState.personal.length >= 3 && real.iState.signedIn === false && real.iState.logouts === 1, JSON.stringify(real.iState));

check('a second click while the question is open opens no second question and signs nobody out', real.jState.dialogs === 1 && real.jState.second.signedOut === false && real.jState.firstResult.signedOut === false && real.jState.signedIn === true, JSON.stringify(real.jState));

/* ================= 4. mutation proofs ================= */
console.log('\n4 - mutation proofs\n');
async function mutate(label, edit) {
    const stamp = Date.now() + '_' + Math.random().toString(16).slice(2);
    const g = path.join(MODULES, `zz_ihymns_test_signout_guard_${stamp}.js`);
    const u = path.join(MODULES, `zz_ihymns_test_signout_ua_${stamp}.js`);
    try {
        const { guardText, uaText } = edit(guardSrc, uaSrc);
        fs.writeFileSync(g, guardText);
        fs.writeFileSync(u, uaText.replace("'./sign-out-guard.js'", `'./${path.basename(g)}'`));
        return await scenarios(u, label);
    } finally {
        fs.rmSync(g, { force: true }); fs.rmSync(u, { force: true });
    }
}
{
    const m = await mutate('count skipped', (g, u) => {
        if (!g.includes('    let c = await count();')) throw new Error('mutation setup: line not found; update this test');
        return { guardText: g.replace('    let c = await count();\n    if (c.total === 0) return \'go\';', "    let c = { total: 0 }; /* MUTATED: count skipped */\n    if (c.total === 0) return 'go';"), uaText: u };
    });
    check('MUTATION (the count skipped): a down server no longer asks, so the unsent change is lost without a word (the harness can fail)', m.cState.dialogs === 0 && m.c.signedOut === true && m.cState.personal.length === 0);
}
{
    const m = await mutate('clearing skipped', (g, u) => {
        const from = 'try { clearPersonalDataAtSignOut(localStorage); }';
        if (!u.includes(from)) throw new Error('mutation setup: clearing line not found; update this test');
        return { guardText: g, uaText: u.replace(from, 'try { /* MUTATED: not cleared */ }') };
    });
    check('MUTATION (the clearing skipped): the next person on a shared browser would find the saved songs', m.aState.personal.length > 0);
}
{
    const m = await mutate('flush skipped', (g, u) => {
        const from = "const flush = deps.flush || (() => flushEverything(app));";
        if (!g.includes(from)) throw new Error('mutation setup: flush line not found; update this test');
        return { guardText: g.replace(from, 'const flush = deps.flush || (async () => {}); /* MUTATED: nothing is sent */'), uaText: u };
    });
    check('MUTATION (the flush skipped): a change inside the 1.5 second wait is cleared without ever being sent', !m.bState.serverHas.includes('MP-5'));
}

if (failures) { console.log(`\n${failures} failure(s)`); process.exit(1); }
console.log('\nall passed');
