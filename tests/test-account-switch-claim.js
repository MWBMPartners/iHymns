/**
 * tests/test-account-switch-claim.js - one person's saved songs are never merged into another's account (A7, epic #2180)
 *
 * ELI5
 * ----
 * Ben signs out of the church laptop and Carol signs in. His favourites and set
 * lists are still in the browser, and the app used to upload them into HER
 * account. This test plays that story with the REAL UserAuth, Favorites and
 * SetList classes in jsdom and a fake server that records what each person's
 * requests contained.
 *
 * It checks: Carol's requests contain none of Ben's songs; Ben's data is put
 * aside, not destroyed; Ben signing back in gets it back; a session that ends
 * by itself (the server says 401) puts the data aside too; a visitor's own
 * anonymous songs are still claimed by whoever signs in first (as before).
 *
 * MUTATION PROOF (rule #34): the same story is played against a throwaway
 * sibling of user-auth.js with the owner check switched off. Carol's request
 * then DOES carry Ben's songs, proving this test can fail.
 *
 * Usage: node tests/test-account-switch-claim.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const JS = path.join(__dirname, '..', 'appWeb', 'public_html', 'js');
const MODULES = path.join(JS, 'modules');
const USER_AUTH = path.join(MODULES, 'user-auth.js');

let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + detail));
    if (!cond) failures++;
};
const flush = (ms = 20) => new Promise((r) => setTimeout(r, ms));
const url = (p) => pathToFileURL(p).href;

/* ---------- 1. the storage core on its own, with a plain fake Storage ---------- */
class FakeStorage {
    constructor() { this.m = new Map(); }
    get length() { return this.m.size; }
    key(i) { return [...this.m.keys()][i] ?? null; }
    getItem(k) { return this.m.has(k) ? this.m.get(k) : null; }
    setItem(k, v) { this.m.set(k, String(v)); }
    removeItem(k) { this.m.delete(k); }
}
const core = await import(url(path.join(MODULES, 'account-data-owner.js')));
const C = await import(url(path.join(JS, 'constants.js')));

console.log('\n1 - the storage core\n');
{
    const st = new FakeStorage();
    st.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1' }]));
    st.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ id: 'sl-ben', name: 'Ben list' }]));
    st.setItem(C.STORAGE_SETLISTS_SYNCED_AT, '2026-01-01 00:00:00');
    check('no owner + anonymous data: the first person to sign in claims it', core.reconcileDataOwner(st, 7).action === 'claim' && core.readDataOwner(st) === '7');
    check('the same person again: nothing moves', core.reconcileDataOwner(st, '7').action === 'same');
    const r = core.reconcileDataOwner(st, 8);
    check('a different person: the data is set aside', r.action === 'switched' && r.stashed === true);
    check('the live favourites are empty for the new person', st.getItem(C.STORAGE_FAVORITES) === null && st.getItem(C.STORAGE_SETLISTS) === null);
    check('the old watermark is gone (it must never carry across)', st.getItem(C.STORAGE_SETLISTS_SYNCED_AT) === null);
    check('the new person is now the recorded owner', core.readDataOwner(st) === '8');
    check('exactly one stash exists, under the old owner', [...st.m.keys()].filter((k) => k.startsWith(C.STORAGE_STASH_PREFIX)).join() === C.STORAGE_STASH_PREFIX + '7');
    const back = core.reconcileDataOwner(st, 7);
    check('the first person coming back gets their data restored', back.action === 'switched' && back.restored === true
        && JSON.parse(st.getItem(C.STORAGE_FAVORITES))[0].id === 'MP-1');
    check('...and the second person is the one now set aside (still only one stash)',
        [...st.m.keys()].filter((k) => k.startsWith(C.STORAGE_STASH_PREFIX)).join() === C.STORAGE_STASH_PREFIX + '8' || [...st.m.keys()].filter((k) => k.startsWith(C.STORAGE_STASH_PREFIX)).length <= 1);
    check('no signed-in id: nothing is done', core.reconcileDataOwner(st, null).action === 'none');
    /* identity, not position: a restored stash merges by id */
    const s2 = new FakeStorage();
    s2.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'A' }, { id: 'B' }]));
    core.recordDataOwner(s2, 1);
    core.protectOnUnplannedSignOut(s2, 1);
    s2.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'B' }, { id: 'C' }]));   // a visitor's songs made meanwhile
    core.reconcileDataOwner(s2, 1);
    const ids = JSON.parse(s2.getItem(C.STORAGE_FAVORITES)).map((x) => x.id).sort().join();
    check('a restored stash joins songs made meanwhile by id, with no duplicates', ids === 'A,B,C', ids);
    const s3 = new FakeStorage();
    s3.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'X' }]));
    check('an unplanned sign-out with no known owner leaves anonymous data alone', core.protectOnUnplannedSignOut(s3, null).cleared === false && s3.getItem(C.STORAGE_FAVORITES) !== null);
}

/* ---------- 2. the real classes ---------- */
async function story(userAuthPath) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    Object.assign(globalThis, {
        window, document: window.document, localStorage: window.localStorage,
        CustomEvent: window.CustomEvent, Event: window.Event,
        requestAnimationFrame: (fn) => setTimeout(fn, 0),
    });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true, serviceWorker: undefined }, configurable: true, writable: true });

    const requests = [];   // { who, action, body }
    const gates = {};      // action -> a promise Ben's (user 1) replies wait on, to hold a request "in flight"
    let currentToken = null;
    const tokenOwner = { 'tok-ben': 1, 'tok-carol': 2 };
    globalThis.fetch = async (input, init = {}) => {
        const u = new URL(String(input), 'https://example.test/');
        const action = u.searchParams.get('action');
        const h = init.headers;
        const auth = (h && typeof h.get === 'function') ? (h.get('Authorization') || '') : ((h && (h.Authorization || h.authorization)) || '');
        const token = auth.replace('Bearer ', '');
        const who = tokenOwner[token] ?? null;
        const body = init.body ? JSON.parse(init.body) : null;
        requests.push({ who, action, body });
        if (gates[action] && who === 1) await gates[action];
        const ok = (obj) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => obj });
        if (action === 'favorites_sync') {
            if (token === 'tok-expired') return { ok: false, status: 401, headers: { get: () => null }, json: async () => ({}) };
            return ok({ favorites: (body.favorites || []).map((f) => ({ id: f.id, tags: f.tags || [] })), syncedAt: '2026-02-02 00:00:00' });
        }
        if (action === 'user_setlists_sync') return ok({ setlists: body.setlists || [], tombstones: [], syncedAt: '2026-02-02 00:00:00' });
        if (action === 'custom_tags_sync' || action === 'custom_tags') return ok({ tags: gates.benTags && who === 1 ? ['BEN-TAG'] : [] });
        return ok({});
    };

    const { UserAuth } = await import(url(userAuthPath) + '?t=' + Date.now() + Math.random());
    const { Favorites } = await import(url(path.join(MODULES, 'favorites.js')) + '?t=' + Date.now() + Math.random());
    const { SetList } = await import(url(path.join(MODULES, 'setlist.js')) + '?t=' + Date.now() + Math.random());
    const app = { config: { apiUrl: '/api' }, syncStorage() {}, showToast() {}, showConfirm: async () => true };
    app.userAuth = new UserAuth(app);
    app.favorites = new Favorites(app);
    app.setList = new SetList(app);

    const settle = async () => { app.userAuth._userDataSynced = false; await app.userAuth.triggerUserDataSync(); await flush(30); };
    const mine = (who) => requests.filter((r) => r.who === who);
    return { app, requests, mine, settle, window, gates };
}

console.log('\n2 - the real UserAuth, Favorites and SetList\n');
async function playStory(userAuthPath) {
    const env = await story(userAuthPath);
    const { app, mine, settle } = env;
    /* Ben signs in and has songs. */
    app.userAuth.saveCredentials('tok-ben', { id: 1, username: 'ben' });
    localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-BEN-1', tags: [] }]));
    localStorage.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ id: 'sl-ben', name: 'Ben only', songs: [] }]));
    await settle();
    /* Ben signs out (planned) on the shared laptop - the data stays in the browser, as it does today. */
    await app.userAuth.logout();
    /* Carol signs in. */
    app.userAuth.saveCredentials('tok-carol', { id: 2, username: 'carol' });
    await settle();
    const carolSent = JSON.stringify(mine(2).map((r) => r.body));
    const carolCache = (localStorage.getItem(C.STORAGE_FAVORITES) || '') + (localStorage.getItem(C.STORAGE_SETLISTS) || '');
    const leaked = carolSent.includes('MP-BEN-1') || carolSent.includes('sl-ben');
    const cacheLeak = carolCache.includes('MP-BEN-1') || carolCache.includes('sl-ben');
    const stashed = localStorage.getItem(C.STORAGE_STASH_PREFIX + '1');
    /* Carol signs out, Ben comes back. */
    await app.userAuth.logout();
    app.userAuth.saveCredentials('tok-ben', { id: 1, username: 'ben' });
    await settle();
    const benBack = (localStorage.getItem(C.STORAGE_FAVORITES) || '').includes('MP-BEN-1');
    const benSent = JSON.stringify(mine(1).map((r) => r.body)).includes('MP-BEN-1');
    return { benSent, leaked, cacheLeak, stashed: stashed !== null && stashed.includes('MP-BEN-1'), benBack, env };
}
const good = await playStory(USER_AUTH);
check("the harness is not blind: Ben's own requests did carry his songs", good.benSent === true);
check("Carol's requests contain none of Ben's songs or set lists", good.leaked === false);
check("Carol's screen (the device cache) holds none of Ben's songs", good.cacheLeak === false);
check("Ben's songs were put aside, not destroyed", good.stashed === true);
check('Ben signing back in gets his songs back', good.benBack === true);

/* an unplanned end of session */
{
    const env = await story(USER_AUTH);
    env.app.userAuth.saveCredentials('tok-ben', { id: 1, username: 'ben' });
    localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-BEN-9', tags: [] }]));
    await env.settle();
    /* the server now rejects Ben's token */
    localStorage.setItem(C.STORAGE_AUTH_TOKEN, 'tok-expired');
    env.app.userAuth._userDataSynced = false;
    await env.app.userAuth.triggerUserDataSync();
    await flush(30);
    check('a session the server ends (401) is signed out', !env.app.userAuth.isLoggedIn());
    check("...and Ben's songs are no longer sitting in the browser for the next person", !(localStorage.getItem(C.STORAGE_FAVORITES) || '').includes('MP-BEN-9'));
    env.app.userAuth.saveCredentials('tok-ben', { id: 1, username: 'ben' });
    await env.settle();
    check('...and come back when Ben signs in again', (localStorage.getItem(C.STORAGE_FAVORITES) || '').includes('MP-BEN-9'));
}

/* a visitor's anonymous songs are still claimed (unchanged behaviour) */
{
    const env = await story(USER_AUTH);
    localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-VISITOR', tags: [] }]));
    env.app.userAuth.saveCredentials('tok-carol', { id: 2, username: 'carol' });
    await env.settle();
    check("a visitor's own songs are still claimed by the person who signs in first", JSON.stringify(env.mine(2).map((r) => r.body)).includes('MP-VISITOR'));
}

/* a FAST account switch: Ben's requests are still in flight when Carol signs in */
async function fastSwitch(userAuthPath) {
    const env = await story(userAuthPath);
    const { app, gates } = env;
    app.userAuth.saveCredentials('tok-ben', { id: 1, username: 'ben' });
    localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-BEN-1', tags: [] }]));
    localStorage.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ id: 'sl-ben', name: 'Ben only', songs: [] }]));
    let release;
    const gate = new Promise((r) => { release = r; });
    gates.favorites_sync = gate; gates.user_setlists_sync = gate; gates.custom_tags_sync = gate; gates.benTags = true;
    /* the three ways a reply is used: a per-edit push (absorbs), a reconcile, and the custom-tag reconcile */
    const push = (async () => app.userAuth._absorbFavoritesSync(await app.userAuth.syncFavorites([{ id: 'MP-BEN-1', tags: [] }], 'replace')))();
    const pushLists = (async () => app.userAuth._absorbSetlistSync(await app.userAuth.syncSetlists([{ id: 'sl-ben', name: 'Ben only', songs: [] }], 'replace')))();
    const tags = app.userAuth.triggerCustomTagsSync();
    await flush();                                              /* all three are now waiting on the server */
    app.userAuth.saveCredentials('tok-carol', { id: 2, username: 'carol' });   /* Carol signs in on the same device */
    release();
    const results = await Promise.all([push, pushLists, tags]);
    await flush(30);
    const live = (localStorage.getItem(C.STORAGE_FAVORITES) || '') + (localStorage.getItem(C.STORAGE_SETLISTS) || '') + (localStorage.getItem(C.STORAGE_CUSTOM_TAGS) || '');
    return { leaked: live.includes('MP-BEN-1') || live.includes('sl-ben') || live.includes('BEN-TAG'), owner: core.readDataOwner(localStorage), results };
}
console.log('\n2b - a fast account switch while a sync is in flight\n');
const fast = await fastSwitch(USER_AUTH);
check("Ben's late replies are dropped: none of his songs, set lists or tags reach Carol's cache", fast.leaked === false);
check('...and the device is still recorded as Carol\'s, not re-labelled by a stale reply', fast.owner === '2', String(fast.owner));
check('...each late reply reports "not applied" (false), not success', fast.results.every((r) => r === false), JSON.stringify(fast.results));

console.log('\n3 - mutation proof (owner check switched off)\n');
let mutant = null;
try {
    const src = fs.readFileSync(USER_AUTH, 'utf8');
    const from = 'const r = reconcileDataOwner(localStorage, uid);';
    if (!src.includes(from)) throw new Error('mutation setup: line not found; update this test');
    mutant = path.join(MODULES, `zz_ihymns_test_userauth_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
    fs.writeFileSync(mutant, src.replace(from, "const r = { action: 'none' }; /* MUTATED: owner check off */"));
    const bad = await playStory(mutant);
    check("with the owner check off, Carol's request DOES carry Ben's songs (the test can fail)", bad.leaked === true);
} finally {
    if (mutant) fs.rmSync(mutant, { force: true });
}

/* second mutation: an unplanned end of session no longer sets data aside */
{
    let m2 = null;
    try {
        const src = fs.readFileSync(USER_AUTH, 'utf8');
        const from = 'try { protectOnUnplannedSignOut(localStorage, this.getUser()?.id); }';
        if (!src.includes(from)) throw new Error('mutation setup: unplanned line not found; update this test');
        m2 = path.join(MODULES, `zz_ihymns_test_userauth_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
        fs.writeFileSync(m2, src.replace(from, 'try { /* MUTATED: no protection */ }'));
        const env = await story(m2);
        env.app.userAuth.saveCredentials('tok-ben', { id: 1, username: 'ben' });
        localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-BEN-9', tags: [] }]));
        await env.settle();
        localStorage.setItem(C.STORAGE_AUTH_TOKEN, 'tok-expired');
        env.app.userAuth._userDataSynced = false;
        await env.app.userAuth.triggerUserDataSync();
        await flush(30);
        check('with unplanned protection off, the songs stay in the browser (the test can fail)', (localStorage.getItem(C.STORAGE_FAVORITES) || '').includes('MP-BEN-9'));
    } finally {
        if (m2) fs.rmSync(m2, { force: true });
    }
}

/* third mutation: a reply is used no matter who is signed in now */
{
    let m3 = null;
    try {
        const src = fs.readFileSync(USER_AUTH, 'utf8');
        const from = '_replyIsStillForRequester(requesterUid) {';
        if (!src.includes(from)) throw new Error('mutation setup: helper not found; update this test');
        m3 = path.join(MODULES, `zz_ihymns_test_userauth_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
        fs.writeFileSync(m3, src.replace(from, from + ' return true; /* MUTATED: any reply accepted */'));
        const bad = await fastSwitch(m3);
        check("with the still-the-same-person check off, Ben's late reply DOES land in Carol's cache (the test can fail)", bad.leaked === true);
    } finally {
        if (m3) fs.rmSync(m3, { force: true });
    }
}

console.log(failures === 0 ? '\nAll account-switch checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
