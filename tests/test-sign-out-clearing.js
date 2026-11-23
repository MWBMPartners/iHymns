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
 *  3b. (PR 2c review) the real SetList / Favorites classes against a pretend server that is offline, answers 500, is
 *     paused (a 200 that says readOnly) or is reached from a second tab: an edit whose push already failed is still counted
 *     and asked about; a paused site never counts as "sent"; an edit made while a push is in the air keeps its mark.
 *     The old version of this file counted "waiting stores" found by key-name suffix and used a fake store that could only
 *     answer "sent", so it could not see this class of loss.
 *  4. MUTATION PROOFS (recorded in the 2c-3 commit): the count skipped (the "asks" rows go red), the clearing skipped,
 *     the flush skipped (the "sent before clearing" row goes red). PR 2c review added: the unsent mark never set, the
 *     read-only reply treated as sent, the marker dropped without a real push.
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
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
    Object.assign(globalThis, { window: dom.window, document: dom.window.document, localStorage: dom.window.localStorage, CustomEvent: dom.window.CustomEvent, Event: dom.window.Event });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
    const { Favorites } = await import(url(path.join(MODULES, 'favorites.js')) + '?c=' + Math.random());
    const { SetList } = await import(url(path.join(MODULES, 'setlist.js')) + '?c=' + Math.random());
    const { ProfileSync } = await import(url(path.join(MODULES, 'profile-sync.js')) + '?c=' + Math.random());
    /* PR 2c review: the old loop found "waiting stores" by the SUFFIX of a constant's name and then wrote a fake value
       straight into storage, so a store that never recorded its own edits still passed. Now each kind of change is made
       through the REAL class, the way the app makes it, and the count must notice it. */
    const mkApp = () => {
        const app = { config: { apiUrl: '/api', profileSync: { enabled: true } }, syncStorage() {}, showToast() {}, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: 1 }), authHeaders: () => ({}) } };
        app.favorites = new Favorites(app);
        app.setList = new SetList(app);
        app.profileSync = new ProfileSync(app, { storage: localStorage });
        return app;
    };
    const realEdits = {
        'a set list created': (a) => a.setList.create('Sunday'),
        'a set list renamed (not a deletion)': (a) => { localStorage.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ id: 'sl-1', name: 'Mine', songs: [] }])); a.setList.rename('sl-1', 'Renamed'); },
        'a set list deleted': (a) => { localStorage.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ id: 'sl-1', name: 'Mine', songs: [] }])); a.setList.delete('sl-1'); },
        'a favourite added': (a) => a.favorites.toggle('MP-1', 'T', 'MP', 1),
        'a favourite removed': (a) => { localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1', tags: [] }])); a.favorites.toggle('MP-1', 'T', 'MP', 1); },
        'a favourite given a tag (not an add or a removal)': (a) => { localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1', tags: [] }])); a.favorites.setTags('MP-1', ['Praise']); },
        'a custom tag invented': (a) => a.favorites.saveCustomTag('Sunday'),
        'a profile setting changed': (a) => a.profileSync._enqueue('appearance', 'theme', 'set', 'dark'),
    };
    for (const [what, edit] of Object.entries(realEdits)) {
        localStorage.clear();
        const app = mkApp();
        app.favorites._syncReady = true; app.setList._syncReady = true;   /* arm nothing; we only count, the timers are cancelled below */
        edit(app);
        clearTimeout(app.favorites._syncTimer); clearTimeout(app.favorites._tagSyncTimer); clearTimeout(app.setList._syncTimer);
        const c = await guard.countUnsentChanges(app, { queueCount: async () => 0 });
        check(`${what} is counted as 1 unsent change (total ${c.total})`, c.total === 1, JSON.stringify(c));
        localStorage.clear();
        const none = await guard.countUnsentChanges(app, { queueCount: async () => 0 });
        check(`...and counts nothing once the browser holds nothing`, none.total === 0);
    }
    {
        localStorage.clear();
        const anon = mkApp();
        anon.app = null;
        anon.favorites.app.userAuth = { isLoggedIn: () => false };
        anon.favorites.toggle('MP-9', 'T', 'MP', 9);
        clearTimeout(anon.favorites._syncTimer);
        check('a signed-out visitor\'s edit leaves no "unsent" mark (there is no account to tell)', localStorage.getItem(C.STORAGE_FAVORITES_UNSENT) === null);
    }
    {
        const lockstep = (await import(url(path.join(MODULES, 'account-data-owner.js')))).PERSONAL_DATA_KEYS;
        check('the three "unsent" marks are personal data (stashed and cleared with the lists they describe)',
            [C.STORAGE_SETLISTS_UNSENT, C.STORAGE_FAVORITES_UNSENT, C.STORAGE_CUSTOM_TAGS_UNSENT].every((k) => lockstep.includes(k)));
        const stores = [['setlist.js', 'markUnsent(localStorage, STORAGE_SETLISTS_UNSENT)'], ['favorites.js', 'markUnsent(localStorage, STORAGE_FAVORITES_UNSENT)'], ['favorites.js', 'markUnsent(localStorage, STORAGE_CUSTOM_TAGS_UNSENT)']];
        for (const [f, needle] of stores) check(`${f} sets ${needle.split(', ')[1].replace(')', '')}`, fs.readFileSync(path.join(MODULES, f), 'utf8').includes(needle));
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
    check('"Try again" is offered three times, then a last question that offers "Stay signed in" in its place; "Sign out anyway" never moves', t.dialogs.length === 4 && t.flushes() === 4 && t.dialogs[0].o.option1Text === 'Try again' && t.dialogs[3].o.option1Text === 'Stay signed in' && t.dialogs.every((d) => d.o.option2Text === 'Sign out anyway') && r4 === 'stay', JSON.stringify(t.dialogs.map((d) => [d.o.option1Text, d.o.option2Text])));
    t = mk([3, 3, 3, 3], ['option1', 'option1', 'option1', 'option2']);
    check('...and "Sign out anyway" on the last question signs out', (await guard.confirmSignOut(t.app, t.deps)) === 'go');
    t = mk([3, 3, 3, 3], ['option1', 'option1', 'option1', null]);
    check('...and closing the last question stays signed in', (await guard.confirmSignOut(t.app, t.deps)) === 'stay');
    /* The button layout, round by round, derived from the real dialog markup in app.js (the visual order is the markup order). */
    {
        const appSrc = fs.readFileSync(path.join(JS, 'app.js'), 'utf8');
        const choiceSrc = appSrc.slice(appSrc.indexOf('showChoice(message, opts = {})'));
        const footer = choiceSrc.slice(choiceSrc.indexOf('<div class="modal-footer">'));
        const order = [...footer.slice(0, footer.indexOf('</div>')).matchAll(/id="\$\{id\}-(opt[12])"/g)].map((m) => m[1]);   /* left to right */
        check('the dialog markup has both option buttons, so the slots can be derived (' + order.join(' then ') + ')', order.length === 2 && order.includes('opt1') && order.includes('opt2'));
        t = mk([3, 3, 3, 3], ['option1', 'option1', 'option1', 'option1']);
        await guard.confirmSignOut(t.app, t.deps);
        const labelsBySlot = t.dialogs.map((d) => order.map((o) => (o === 'opt1' ? d.o.option1Text : d.o.option2Text)));
        const slotOfSignOut = labelsBySlot.map((row) => row.indexOf('Sign out anyway'));
        check('every round puts "Sign out anyway" in the SAME place on the screen', slotOfSignOut.length === 4 && new Set(slotOfSignOut).size === 1 && slotOfSignOut[0] >= 0, JSON.stringify(labelsBySlot));
        check('...with the same look every round (it is never the only red button that was a different colour a moment ago)', new Set(t.dialogs.map((d) => d.o.option2Class)).size === 1 && t.dialogs.every((d) => /danger/.test(d.o.option2Class)));
        const slotOfSafe = labelsBySlot.map((row) => row.findIndex((l) => l === 'Try again' || l === 'Stay signed in'));
        check('...and the harmless choice (Try again, then Stay signed in) is always in the other place', new Set(slotOfSafe).size === 1 && slotOfSafe[0] !== slotOfSignOut[0]);
        check('the last question does not tell the person to "try again", which is no longer offered', !/again/.test(t.dialogs[3].m) && /again/.test(t.dialogs[0].m), t.dialogs[3].m);
    }
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
/** The mutation proofs point these at a broken copy of a store; normally empty. */
const OVERRIDE = {};
async function world({ enabled = true, favs = 'ok', profile = 'ok', sl = 'ok', tags = 'ok', answers = [], userAuthPath = path.join(MODULES, 'user-auth.js') } = {}) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event, requestAnimationFrame: (f) => setTimeout(f, 0) });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true, serviceWorker: undefined }, configurable: true, writable: true });
    const w = { requests: [], mode: { favs, profile, sl, tags }, serverFavs: [], serverLists: [], serverTags: [], dialogs: [], answers: [...answers] };
    /* An in-memory offline queue (jsdom has no IndexedDB), so a marker left by an offline attempt is REAL here. */
    const { offlineQueue } = await import(url(path.join(MODULES, 'offline-queue.js')));
    const markers = {};
    offlineQueue.enqueue = async (t) => { markers[t] = (markers[t] || 0) + 1; };
    offlineQueue.count = async (t) => markers[t] || 0;
    offlineQueue.drainLatest = async (t, send) => { if (markers[t] && await send()) markers[t] = 0; };
    offlineQueue.bindAutoDrainLatest = () => {};
    w.markers = markers;
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
            if (w.mode.favs === '500') return json(500, { error: 'boom' });
            if (w.mode.favs === 'readonly') return json(200, { favorites: body.favorites, readOnly: true, syncedAt: '2026-02-02 00:00:00' });
            w.serverFavs = (body.favorites || []).map((f) => f.id);
            return json(200, { favorites: body.favorites, syncedAt: '2026-02-02 00:00:00' });
        }
        if (action === 'user_setlists_sync') {
            if (w.mode.sl === 'down') throw new TypeError('network down');
            if (w.mode.sl === '500') return json(500, { error: 'boom' });
            if (w.mode.sl === 'readonly') return json(200, { setlists: [], tombstones: [], readOnly: true, syncedAt: '2026-02-02 00:00:00' });
            if (typeof w.mode.sl === 'function') await w.mode.sl(body);
            w.serverLists = (body.setlists || []).map((l) => l.name);
            return json(200, { setlists: body.setlists || [], tombstones: [], syncedAt: '2026-02-02 00:00:00' });
        }
        if (action === 'custom_tags_sync') {
            if (w.mode.tags === 'down') throw new TypeError('network down');
            if (w.mode.tags === '500') return json(500, { error: 'boom' });
            if (w.mode.tags === 'readonly') return json(200, { tags: [], readOnly: true });
            w.serverTags = body.tags || [];
            return json(200, { tags: body.tags || [] });
        }
        if (action === 'profile_schema') return json(200, schema);
        if (action === 'profile_sync') {
            if (w.mode.profile === 'down') return json(503, { reason: 'profile_sync_unavailable' });
            return json(200, { ok: true, cursor: 5, more: false, resetCursor: false, schemaVersion: schema.schemaVersion, changes: [], rejected: [], applied: (body?.changes || []).length });
        }
        return json(200, { ok: true });
    };
    const { UserAuth } = await import(url(userAuthPath) + '?w=' + Date.now() + Math.random());
    const { Favorites } = await import(url(OVERRIDE.favorites || path.join(MODULES, 'favorites.js')) + '?w=' + Math.random());
    const { SetList } = await import(url(OVERRIDE.setlist || path.join(MODULES, 'setlist.js')) + '?w=' + Math.random());
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

/* ================= 3b. edits whose push already failed (PR 2c review) ================= */
console.log('\n3b - edits whose push already failed, a paused site, a second tab\n');
async function lostEditScenarios(userAuthPath) {
    const out = {};
    /* The 1.5 second timer fires and the push runs (and fails): done by hand, exactly what the timer's callback does, so the test does not sleep. */
    const settle = async (store, push = '_pushNow') => {
        clearTimeout(store._syncTimer); clearTimeout(store._tagSyncTimer);
        store._syncTimerPending = false; store._tagSyncTimerPending = false;
        try { await store[push](); } catch (_e) { /* the timer's callback ignores it too */ }
    };
    const left = (w) => w.personalLeft().length;
    /* k. the push after a set-list edit failed with a server error; the timer is long gone when the person signs out */
    let w = await world({ userAuthPath, sl: '500', answers: [null] });
    w.app.setList.create('Sunday service'); await settle(w.app.setList);
    out.k = await w.app.userAuth.logout();
    out.kState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), lists: localStorage.getItem(C.STORAGE_SETLISTS) };
    /* l. no signal when the edit was made: the push queued a marker and stopped */
    w = await world({ userAuthPath, answers: [null] });
    navigator.onLine = false;
    w.app.setList.create('Offline service'); await settle(w.app.setList);
    out.lMarkerBefore = w.markers['setlists-sync'] || 0;
    out.l = await w.app.userAuth.logout();
    out.lState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), lists: localStorage.getItem(C.STORAGE_SETLISTS), marker: w.markers['setlists-sync'] || 0 };
    navigator.onLine = true;
    /* m. a paused site answers 200 with readOnly: nothing was saved, so it must not count as sent */
    w = await world({ userAuthPath, sl: 'readonly', answers: [null] });
    w.app.setList.create('Paused service'); await settle(w.app.setList);
    out.m = await w.app.userAuth.logout();
    out.mState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), lists: localStorage.getItem(C.STORAGE_SETLISTS) };
    /* m2. a set list deleted while the site is paused: the removal must stay queued */
    w = await world({ userAuthPath, sl: 'readonly', answers: [null] });
    w.app.setList.delete('sl-1'); await settle(w.app.setList);
    out.m2State = { pending: w.app.setList.getPendingDeletes() };
    out.m2 = await w.app.userAuth.logout();
    out.m2Dialogs = w.dialogs.length;
    /* n. the same edit when the server is fine: sent first, cleared, no question */
    w = await world({ userAuthPath });
    w.app.setList.create('Good service'); w.app.setList._syncTimerPending = false; clearTimeout(w.app.setList._syncTimer);
    out.n = await w.app.userAuth.logout();
    out.nState = { dialogs: w.dialogs.length, serverLists: [...w.serverLists], left: left(w) };
    /* o. a SECOND TAB made an edit whose push failed (it shares this browser's storage); this tab signs out */
    w = await world({ userAuthPath, sl: '500', answers: [null] });
    {
        const appB = { config: w.app.config, syncStorage() {}, showToast() {}, userAuth: w.app.userAuth };
        const { SetList } = await import(url(OVERRIDE.setlist || path.join(MODULES, 'setlist.js')) + '?tab=' + Math.random());
        const tabB = new SetList(appB); tabB._syncReady = true; appB.setList = tabB;
        tabB.create('Made in the other tab'); clearTimeout(tabB._syncTimer);   /* the other tab's timer is not in this tab */
    }
    out.o = await w.app.userAuth.logout();
    out.oState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), lists: localStorage.getItem(C.STORAGE_SETLISTS) };
    /* o2. ...and when the server is fine, THIS tab's sign-out sends the other tab's edit */
    w = await world({ userAuthPath });
    {
        const appB = { config: w.app.config, syncStorage() {}, showToast() {}, userAuth: w.app.userAuth };
        const { SetList } = await import(url(OVERRIDE.setlist || path.join(MODULES, 'setlist.js')) + '?tab=' + Math.random());
        const tabB = new SetList(appB); tabB._syncReady = true; appB.setList = tabB;
        tabB.create('Made in the other tab'); clearTimeout(tabB._syncTimer);
    }
    out.o2 = await w.app.userAuth.logout();
    out.o2State = { dialogs: w.dialogs.length, serverLists: [...w.serverLists], left: left(w) };
    /* p. a favourite given a tag (neither added nor removed), the push failed */
    w = await world({ userAuthPath, favs: '500', answers: [null] });
    w.app.favorites.setTags('MP-1', ['Praise']); await settle(w.app.favorites);
    out.p = await w.app.userAuth.logout();
    out.pState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn() };
    /* q. a custom tag invented, the push failed */
    w = await world({ userAuthPath, tags: '500', answers: [null] });
    w.app.favorites.saveCustomTag('Evening'); await settle(w.app.favorites, '_pushTagsNow');
    out.q = await w.app.userAuth.logout();
    out.qState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), tags: localStorage.getItem(C.STORAGE_CUSTOM_TAGS) };
    /* q2. a custom tag, paused site */
    w = await world({ userAuthPath, tags: 'readonly', answers: [null] });
    w.app.favorites.saveCustomTag('Evening'); await settle(w.app.favorites, '_pushTagsNow');
    out.q2 = await w.app.userAuth.logout();
    out.q2State = { dialogs: w.dialogs.length, tags: JSON.parse(localStorage.getItem(C.STORAGE_CUSTOM_TAGS) || '[]') };
    /* q3. a custom tag with a good server: sent on sign-out */
    w = await world({ userAuthPath });
    w.app.favorites.saveCustomTag('Evening'); clearTimeout(w.app.favorites._tagSyncTimer); w.app.favorites._tagSyncTimerPending = false;
    out.q3 = await w.app.userAuth.logout();
    out.q3State = { dialogs: w.dialogs.length, serverTags: [...w.serverTags], left: left(w) };
    /* r. an edit made while a push is in the air keeps its mark (nothing is marked sent that was not) */
    w = await world({ userAuthPath, sl: async () => { w.app.setList.saveAll([...w.app.setList.getAll(), { id: 'sl-late', name: 'Typed during the push', songs: [] }]); clearTimeout(w.app.setList._syncTimer); } });
    w.app.setList.create('First'); clearTimeout(w.app.setList._syncTimer);
    await w.app.setList._pushNow();
    out.rState = { mark: (w.app.setList.getUnsentStamp?.() ?? null) !== null };
    /* s. "Sign out anyway" after a failed set-list push: signed out, cleared */
    w = await world({ userAuthPath, sl: '500', answers: ['option2'] });
    w.app.setList.create('Lost on purpose'); await settle(w.app.setList);
    out.s = await w.app.userAuth.logout();
    out.sState = { dialogs: w.dialogs.length, signedIn: w.app.userAuth.isLoggedIn(), left: left(w) };
    /* t. the server was down at the first try and is back for "Try again": the edit is sent and the marker is gone */
    w = await world({ userAuthPath, sl: '500', answers: ['option1'] });
    w.app.setList.create('Retry me'); await settle(w.app.setList);
    w.app.showChoice = async (message, opts) => { w.dialogs.push({ message, opts }); w.mode.sl = 'ok'; return 'option1'; };
    out.t = await w.app.userAuth.logout();
    out.tState = { dialogs: w.dialogs.length, serverLists: [...w.serverLists], left: left(w) };
    /* u. only a "sync wanted" marker is left (no mark, nothing else), and the server is down: the marker must survive the failed attempt */
    w = await world({ userAuthPath, sl: '500', answers: [null] });
    w.markers['setlists-sync'] = 1;
    out.u = await w.app.userAuth.logout();
    out.uState = { dialogs: w.dialogs.length, marker: w.markers['setlists-sync'] || 0, signedIn: w.app.userAuth.isLoggedIn() };
    return out;
}
const lost = await lostEditScenarios(path.join(MODULES, 'user-auth.js'));
check('a set-list edit whose push FAILED (server error) is counted: the person is asked, nothing is cleared, still signed in', lost.k.signedOut === false && lost.kState.dialogs === 1 && lost.kState.signedIn === true && (lost.kState.lists || '').includes('Sunday service'), JSON.stringify(lost.kState));
check('a set-list edit made with no signal (an offline marker was queued) is asked about, the marker is still there and the list is kept', lost.lMarkerBefore >= 1 && lost.l.signedOut === false && lost.lState.dialogs === 1 && lost.lState.marker >= 1 && (lost.lState.lists || '').includes('Offline service'), JSON.stringify(lost));
check("a paused site's read-only 200 reply is NOT treated as sent: the person is asked and the set list is kept", lost.m.signedOut === false && lost.mState.dialogs === 1 && (lost.mState.lists || '').includes('Paused service'), JSON.stringify(lost.mState));
check("a set list deleted while the site is paused stays queued for the next sync (a read-only reply changed nothing)", lost.m2State.pending.includes('sl-1') && lost.m2.signedOut === false && lost.m2Dialogs === 1, JSON.stringify(lost.m2State));
check('a set-list edit with a healthy server is sent first and cleared with no question', lost.n.signedOut === true && lost.nState.dialogs === 0 && lost.nState.serverLists.includes('Good service') && lost.nState.left === 0, JSON.stringify(lost.nState));
check("an edit made in a SECOND TAB whose push failed is counted by this tab's sign-out and asked about", lost.o.signedOut === false && lost.oState.dialogs === 1 && (lost.oState.lists || '').includes('Made in the other tab'), JSON.stringify(lost.oState));
check("...and with a healthy server this tab's sign-out sends the other tab's edit before clearing", lost.o2.signedOut === true && lost.o2State.dialogs === 0 && lost.o2State.serverLists.includes('Made in the other tab') && lost.o2State.left === 0, JSON.stringify(lost.o2State));
check('a favourite given a tag (neither added nor removed) whose push failed is asked about', lost.p.signedOut === false && lost.pState.dialogs === 1, JSON.stringify(lost.pState));
check('a custom tag whose push failed is asked about and kept', lost.q.signedOut === false && lost.qState.dialogs === 1 && (lost.qState.tags || '').includes('Evening'), JSON.stringify(lost.qState));
check("a custom tag on a paused site is asked about, and the paused site's empty list does not wipe it", lost.q2.signedOut === false && lost.q2State.dialogs === 1 && lost.q2State.tags.includes('Evening'), JSON.stringify(lost.q2State));
check('a custom tag with a healthy server is sent on sign-out, then cleared with no question', lost.q3.signedOut === true && lost.q3State.dialogs === 0 && lost.q3State.serverTags.includes('Evening') && lost.q3State.left === 0, JSON.stringify(lost.q3State));
check('an edit made while a push was in the air keeps its "unsent" mark (nothing is marked sent that was not)', lost.rState.mark === true, JSON.stringify(lost.rState));
check('a lone offline "sync wanted" marker is not forgotten when its push fails: the person is asked and the marker is still there', lost.u.signedOut === false && lost.uState.dialogs === 1 && lost.uState.marker === 1, JSON.stringify(lost.uState));
check('"Sign out anyway" after a failed push signs out and clears', lost.s.signedOut === true && lost.sState.dialogs === 1 && lost.sState.left === 0, JSON.stringify(lost.sState));
check('"Try again" once the server is back sends the edit, and the person is not asked again', lost.t.signedOut === true && lost.tState.dialogs === 1 && lost.tState.serverLists.includes('Retry me') && lost.tState.left === 0, JSON.stringify(lost.tState));

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

/* PR 2c review mutations: break each new safeguard in a COPY and watch the matching row go red. */
async function mutateStores(label, edit) {
    const stamp = Date.now() + '_' + Math.random().toString(16).slice(2);
    const files = [];
    const write = (name, text) => { const f = path.join(MODULES, `zz_ihymns_test_${name}_${stamp}.js`); fs.writeFileSync(f, text); files.push(f); return f; };
    try {
        const sources = { guard: guardSrc, ua: uaSrc, setlist: fs.readFileSync(path.join(MODULES, 'setlist.js'), 'utf8'), favorites: fs.readFileSync(path.join(MODULES, 'favorites.js'), 'utf8') };
        const changed = edit(sources);
        const g = write('so_guard', changed.guard ?? sources.guard);
        const u = write('so_ua', (changed.ua ?? sources.ua).replace("'./sign-out-guard.js'", `'./${path.basename(g)}'`));
        if (changed.setlist) OVERRIDE.setlist = write('so_setlist', changed.setlist);
        if (changed.favorites) OVERRIDE.favorites = write('so_favorites', changed.favorites);
        return await lostEditScenarios(u);
    } finally {
        delete OVERRIDE.setlist; delete OVERRIDE.favorites;
        for (const f of files) fs.rmSync(f, { force: true });
    }
}
const replaceOnce = (text, from, to, what) => { if (!text.includes(from)) throw new Error('mutation setup: ' + what + ' not found; update this test'); return text.replace(from, to); };
{
    const m = await mutateStores('mark never set', (src) => ({ setlist: replaceOnce(src.setlist, 'markUnsent(localStorage, STORAGE_SETLISTS_UNSENT);', '/* MUTATED: never marked */', 'the set-list mark') }));
    check('MUTATION (set-list edits never marked unsent): a failed push is lost without a word', m.kState.dialogs === 0 && m.k.signedOut === true);
}
{
    const m = await mutateStores('read-only taken as sent', (src) => ({ ua: replaceOnce(src.ua, 'if (res.readOnly !== true) this.app.setList.clearUnsent?.(res.unsentStamp);', 'this.app.setList.clearUnsent?.(res.unsentStamp);', 'the read-only guard on the mark') }));
    check('MUTATION (a paused site\'s reply treated as sent): the edit is lost without a word', m.mState.dialogs === 0 && m.m.signedOut === true);
}
{
    const m = await mutateStores('paused deletions cleared', (src) => ({ ua: replaceOnce(src.ua, 'if (deleted.length > 0 && data.readOnly !== true)', 'if (deleted.length > 0)', 'the read-only guard on deletions') }));
    check('MUTATION (a paused site clears the waiting deletions): the removal is forgotten', m.m2State.pending.length === 0);
}
{
    const m = await mutateStores('markers dropped without a push', (src) => ({ guard: replaceOnce(src.guard, 'if (ok) { for (const t of types) await dropMarkers(t); }', 'for (const t of types) await dropMarkers(t); /* MUTATED */', 'the marker drop') }));
    check('MUTATION (a queue marker dropped even when the push failed): the lone marker is forgotten and the loss is silent', m.uState.marker === 0 && m.uState.dialogs === 0);
}
{
    const m = await mutateStores('flush ignores the mark', (src) => ({ setlist: replaceOnce(src.setlist, "if (!this._syncTimerPending && waiting() === 0 && this.getUnsentStamp() === null && !marker) return true;", "if (!this._syncTimerPending && waiting() === 0) return true; /* MUTATED */", 'the flushNow early return') }));
    check('MUTATION (flushNow only knows the timer and deletions, as before): the other tab\'s edit is no longer sent at sign-out (the healthy-server row goes red)', !(m.o2.signedOut === true && m.o2State.dialogs === 0 && m.o2State.serverLists.includes('Made in the other tab')));
}

if (failures) { console.log(`\n${failures} failure(s)`); process.exit(1); }
console.log('\nall passed');
