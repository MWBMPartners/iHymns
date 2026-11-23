/**
 * tests/test-favorites-tombstones.js - a favourite removed on one device stays removed on the others
 * (account/profile rewrite PR 2a, step 2a-4, epic #2180)
 *
 * ELI5
 * ----
 * The website now keeps two small lists in the browser: "favourites I ADDED on purpose" and
 * "favourites I REMOVED on purpose". Every sync sends both to the server. The server answers with a
 * list of "removed elsewhere" notes (tombstones), and the website drops those favourites BEFORE it
 * merges the server's list into its own - otherwise its own old copy would win and put them back.
 * This test plays that with the REAL Favorites and UserAuth classes in jsdom against a small fake
 * server that follows the same rules as the real one (see userSyncItemUpsertPlan in
 * includes/user_sync.php; that side has its own tests).
 *
 * It checks: both lists are always sent (even empty); toggling, clearing and bulk removal record
 * the right ids, even when signed out; an id is only ever in one list; the list stops at 500 (newest
 * kept); a sync clears only the ids it sent (a change made while it was in flight survives); a paused
 * site's read-only reply clears nothing; tombstones are dropped before merging but never an id still
 * waiting in "added"; and two devices end up agreeing.
 *
 * MUTATION PROOF (rule #34): the same story is played against a throwaway sibling of user-auth.js
 * that merges BEFORE dropping the tombstoned ids. The removed favourite then comes back, proving the
 * test can fail. A second mutant skips clearing what was sent.
 *
 * Usage: node tests/test-favorites-tombstones.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const JS = path.join(__dirname, '..', 'appWeb', 'public_html', 'js');
const MODULES = path.join(JS, 'modules');
const USER_AUTH = path.join(MODULES, 'user-auth.js');
const FAVORITES = path.join(MODULES, 'favorites.js');

let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + detail));
    if (!cond) failures++;
};
const url = (p) => pathToFileURL(p).href;
const C = await import(url(path.join(JS, 'constants.js')));
const owner = await import(url(path.join(MODULES, 'account-data-owner.js')));

/* ---------- a fake server that follows the real rules ---------- */
function makeServer({ protocolOn = true } = {}) {
    const s = { favs: new Map(), tombs: new Map(), t: 0, requests: [], protocolOn, readOnly: false };
    const stamp = () => { s.t += 1; return '2026-11-20 10:' + String(Math.floor(s.t / 60)).padStart(2, '0') + ':' + String(s.t % 60).padStart(2, '0'); };
    s.stamp = stamp;
    s.handle = (who, body) => {
        s.requests.push({ who, body });
        const favs = s.favs.get(who) || new Map(); s.favs.set(who, favs);
        const tombs = s.tombs.get(who) || new Map(); s.tombs.set(who, tombs);
        const p2 = s.protocolOn && Object.prototype.hasOwnProperty.call(body, 'deleted');
        const since = body.since || null;
        if (s.readOnly) {
            return { favorites: [...favs.keys()].map((id) => ({ id, tags: [] })), syncedAt: null, truncated: false, cap: 500, readOnly: true,
                ...(s.protocolOn ? { tombstones: [...tombs].filter(([, at]) => !since || at >= since).map(([id, at]) => ({ id, deletedAt: at, reason: 'user' })), protocol: 1 } : {}) };
        }
        if (p2) for (const id of body.deleted) { favs.delete(id); tombs.set(id, stamp()); }
        const payload = (body.favorites || []).map((f) => f.id);
        if (p2) for (const id of (body.added || [])) if (payload.includes(id)) tombs.delete(id);
        for (const id of payload) {
            if (s.protocolOn && tombs.has(id)) {
                if (p2) continue;
                if (since && tombs.get(id) >= since) continue;
                tombs.delete(id);
            }
            favs.set(id, true);
        }
        const out = { favorites: [...favs.keys()].map((id) => ({ id, tags: [] })), syncedAt: stamp(), truncated: false, cap: 500 };
        if (s.protocolOn) { out.tombstones = [...tombs].filter(([, at]) => !since || at >= since).map(([id, at]) => ({ id, deletedAt: at, reason: 'user' })); out.protocol = p2 ? 2 : 1; }
        return out;
    };
    return s;
}

/* ---------- one browser (a jsdom window with the real classes) ---------- */
async function device(server, userAuthPath = USER_AUTH, favoritesPath = FAVORITES) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    const dev = { window, hold: null };
    dev.use = () => {
        Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event, requestAnimationFrame: (fn) => setTimeout(fn, 0) });
        Object.defineProperty(globalThis, 'navigator', { value: { onLine: true, serviceWorker: undefined }, configurable: true, writable: true });
        globalThis.fetch = async (input, init = {}) => {
            const u = new URL(String(input), 'https://example.test/');
            const action = u.searchParams.get('action');
            const body = init.body ? JSON.parse(init.body) : null;
            const ok = (obj) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => obj });
            if (dev.hold) await dev.hold;
            if (action === 'favorites_sync') return ok(server.handle(1, body));
            if (action === 'user_setlists_sync') return ok({ setlists: [], tombstones: [], syncedAt: '2026-02-02 00:00:00' });
            if (action === 'custom_tags_sync' || action === 'custom_tags') return ok({ tags: [] });
            return ok({});
        };
    };
    dev.use();
    const { UserAuth } = await import(url(userAuthPath) + '?t=' + Date.now() + Math.random());
    const { Favorites } = await import(url(favoritesPath) + '?t=' + Date.now() + Math.random());
    const app = { config: { apiUrl: '/api' }, syncStorage() {}, showToast() {}, showConfirm: async () => true };
    app.userAuth = new UserAuth(app);
    app.favorites = new Favorites(app);
    dev.app = app;
    dev.signIn = () => { dev.use(); app.userAuth.saveCredentials('tok', { id: 1, username: 'ben' }); };
    /* the per-edit push, exactly as Favorites._scheduleSync does it (minus the timer) */
    dev.push = async () => {
        dev.use();
        const favs = app.favorites.getAll().map((f) => ({ id: f.id, tags: f.tags || [] }));
        return app.userAuth._absorbFavoritesSync(await app.userAuth.syncFavorites(favs, 'replace'));
    };
    dev.reconcile = async () => { dev.use(); app.userAuth._userDataSynced = false; return app.userAuth.triggerFavoritesSync(); };
    dev.ids = () => { dev.use(); return app.favorites.getAll().map((f) => f.id).sort(); };
    dev.add = (id) => { dev.use(); return app.favorites.toggle(id, 'T ' + id, 'CP', 1); };
    return dev;
}
const J = (x) => JSON.stringify(x);

console.log('\n1 - what is recorded, even when signed out\n');
{
    const server = makeServer();
    const d = await device(server);
    d.use();
    check('signed out: a new device has nothing pending', J(d.app.favorites.getPendingAdds()) === '[]' && J(d.app.favorites.getPendingDeletes()) === '[]');
    d.add('CP-1'); d.add('CP-2');
    check('signed out: adding records the ids as added', J(d.app.favorites.getPendingAdds()) === '["CP-1","CP-2"]');
    d.add('CP-1');   /* toggles it off */
    check('removing records it as removed and takes it off the added list', J(d.app.favorites.getPendingDeletes()) === '["CP-1"]' && J(d.app.favorites.getPendingAdds()) === '["CP-2"]');
    d.add('CP-1');   /* back on */
    check('adding it again takes it off the removed list (an id is only ever in one list)', J(d.app.favorites.getPendingDeletes()) === '[]' && J(d.app.favorites.getPendingAdds()) === '["CP-2","CP-1"]');
    d.use(); d.app.favorites.clearAll();
    check('clearing all records every favourite as removed', J(d.app.favorites.getPendingDeletes().sort()) === '["CP-1","CP-2"]' && J(d.app.favorites.getPendingAdds()) === '[]');
    d.use(); localStorage.setItem(C.STORAGE_FAVORITES, J([{ id: 'MP-1' }, { id: 'MP-2' }, { id: 'MP-3' }]));
    d.app.favorites.selectedIds = new Set(['MP-1', 'MP-3']);
    d.app.favorites.loadFavoritesList = () => {};
    await d.app.favorites.batchRemove();
    check('removing several at once records each of them', d.app.favorites.getPendingDeletes().includes('MP-1') && d.app.favorites.getPendingDeletes().includes('MP-3') && !d.app.favorites.getPendingDeletes().includes('MP-2'));
    d.use(); for (let i = 0; i < 520; i++) d.app.favorites._recordDelete(['X-' + i]);
    const kept = d.app.favorites.getPendingDeletes();
    check('the list stops at 500 and keeps the newest', kept.length === 500 && kept.includes('X-519') && !kept.includes('X-0'), String(kept.length));
    localStorage.setItem(C.STORAGE_FAVORITES_DELETED, '{not json');
    check('a corrupt list reads as empty (never wedges a sync)', J(d.app.favorites.getPendingDeletes()) === '[]');
    check('both keys are PERSONAL data, stashed and cleared with the favourites', owner.PERSONAL_DATA_KEYS.includes(C.STORAGE_FAVORITES_ADDED) && owner.PERSONAL_DATA_KEYS.includes(C.STORAGE_FAVORITES_DELETED));
}

console.log('\n2 - what a sync sends and clears\n');
{
    const server = makeServer();
    const d = await device(server);
    d.signIn();
    await d.reconcile();
    const first = server.requests.at(-1).body;
    check('a sync with nothing pending still sends both lists, empty (that is what marks protocol 2)', Array.isArray(first.deleted) && first.deleted.length === 0 && Array.isArray(first.added) && first.added.length === 0);
    d.add('CP-1'); d.add('CP-2');
    await d.push();
    check('the request carried the added ids', J(server.requests.at(-1).body.added) === '["CP-1","CP-2"]');
    check('after a successful sync the sent ids are cleared', J(d.app.favorites.getPendingAdds()) === '[]');
    d.add('CP-1');   /* remove CP-1 */
    /* hold the reply, change something while it is in flight */
    let release; d.hold = new Promise((r) => { release = r; });
    const inFlight = d.push();
    await new Promise((r) => setTimeout(r, 20));
    d.add('CP-9');   /* added while the request is on its way */
    release(); d.hold = null;
    await inFlight;
    check('a change made while a sync was in flight is still pending afterwards', J(d.app.favorites.getPendingAdds()) === '["CP-9"]');
    check('...and the removal that WAS sent is cleared', J(d.app.favorites.getPendingDeletes()) === '[]');
    server.readOnly = true;
    d.add('CP-7');
    await d.push();
    check('a paused site\'s read-only reply clears nothing (the server changed nothing)', d.app.favorites.getPendingAdds().includes('CP-7'));
    server.readOnly = false;
}

console.log('\n2b - a removal made while a sync is in flight is not lost (review fix 2)\n');
async function inFlightRemoval(userAuthPath, favoritesPath) {
    const server = makeServer();
    const d = await device(server, userAuthPath, favoritesPath);
    d.signIn();
    await d.reconcile();
    d.add('CP-2');                                   /* favourite CP-2: pending "added" */
    let release; d.hold = new Promise((r) => { release = r; });
    const inFlight = d.push();                       /* sends added: ['CP-2'] */
    await new Promise((r) => setTimeout(r, 20));
    d.add('CP-2');                                   /* un-favourite it while the request is on its way */
    release(); d.hold = null;
    await inFlight;
    const sentAdded = J(server.requests.at(-1).body.added);
    const out = { sentAdded, ids: d.ids(), pendingDeletes: d.app.favorites.getPendingDeletes() };
    await d.push();                                  /* the next sync tells the server */
    out.serverHas = server.favs.get(1).has('CP-2');
    return out;
}
{
    const r = await inFlightRemoval(USER_AUTH, FAVORITES);
    check('setup: the in-flight request really carried CP-2 as added', r.sentAdded === '["CP-2"]', r.sentAdded);
    check('the favourite does not come back after the reply', !r.ids.includes('CP-2'), J(r.ids));
    check('the removal is still waiting to be sent', J(r.pendingDeletes) === '["CP-2"]', J(r.pendingDeletes));
    check('the next sync removes it on the server', r.serverHas === false);
}

console.log('\n3 - tombstones are dropped BEFORE merging, but never an id waiting in "added"\n');
async function tombstoneStory(userAuthPath) {
    const server = makeServer();
    const A = await device(server, userAuthPath), B = await device(server, userAuthPath);
    A.signIn(); B.signIn();
    A.add('CP-1'); A.add('CP-2'); await A.reconcile(); await A.push();
    await B.reconcile();                                   /* B now holds CP-1 and CP-2 */
    const bBefore = B.ids();
    A.add('CP-1');                                         /* A removes CP-1 on purpose */
    await A.push();
    const serverHasIt = server.favs.get(1).has('CP-1');
    await B.push();                                        /* B, still holding CP-1, syncs */
    const bAfter = B.ids();
    const aAfter = A.ids();
    await A.push();
    return { bBefore, bAfter, aAfter, serverHasIt, serverFinal: [...server.favs.get(1).keys()].sort(), server, A, B };
}
{
    const r = await tombstoneStory(USER_AUTH);
    check('setup: device B really held both songs', J(r.bBefore) === '["CP-1","CP-2"]', J(r.bBefore));
    check('device A removed CP-1 and the server dropped it', r.serverHasIt === false);
    check("device B syncs with its OLD list: CP-1 is not put back on the server", !r.serverFinal.includes('CP-1'), J(r.serverFinal));
    check('...and CP-1 disappears from device B', J(r.bAfter) === '["CP-2"]', J(r.bAfter));
    check('...and device A is unchanged', J(r.aAfter) === '["CP-2"]', J(r.aAfter));
    check('both devices and the server agree', J(r.bAfter) === J(r.serverFinal) && J(r.aAfter) === J(r.serverFinal));

    /* an id waiting in "added" is never dropped */
    const server = makeServer();
    const D = await device(server); D.signIn();
    D.add('CP-5'); await D.push();                          /* server now has CP-5 */
    D.add('CP-5'); await D.push();                          /* removed: marker on the server */
    D.add('CP-5');                                          /* added back, not yet synced: waiting in "added" */
    D.use();
    const kept = D.app.userAuth._absorbFavoritesSync({ favorites: [], tombstones: [{ id: 'CP-5', deletedAt: '2026-11-20 10:59:00', reason: 'user' }], syncedAt: '2026-11-20 11:00:00', uid: 1, sent: { added: [], deleted: [] } });
    check('a tombstone never removes a favourite that is waiting in "added"', kept === true && D.ids().includes('CP-5'), J(D.ids()));
    await D.push();
    check('...and the next sync, saying "added", brings it back for good', server.favs.get(1).has('CP-5') === true);

    /* the old-style server (switch off): nothing extra comes back, nothing breaks */
    const off = makeServer({ protocolOn: false });
    const E = await device(off); E.signIn();
    E.add('CP-8'); await E.push();
    const r2 = await E.push();
    check('against a server with the new behaviour off, syncs still work and favourites are kept', r2 === true && J(E.ids()) === '["CP-8"]');
}

console.log('\n4 - mutation proofs\n');
async function withMutant(from, to, fn) {
    let mutant = null;
    try {
        const src = fs.readFileSync(USER_AUTH, 'utf8');
        if (!src.includes(from)) throw new Error('mutation setup: line not found; update this test:\n' + from);
        mutant = path.join(MODULES, `zz_ihymns_test_userauth_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
        fs.writeFileSync(mutant, src.replace(from, to));
        return await fn(mutant);
    } finally {
        if (mutant) fs.rmSync(mutant, { force: true });
    }
}
{
    /* merge before dropping: the local list is NOT filtered by the tombstones */
    const r = await withMutant(
        "const localList = dead.size ? fav.getAll().filter((f) => f && !dead.has(f.id)) : fav.getAll();",
        'const localList = fav.getAll(); /* MUTATED: merge before dropping */',
        tombstoneStory
    );
    check('merging BEFORE dropping puts the removed favourite back on device B (the test can fail)', r.bAfter.includes('CP-1') || r.serverFinal.includes('CP-1'), J(r.bAfter));
}
{
    /* clearing skipped: what was sent stays pending for ever */
    const ok = await withMutant(
        'if (!res.readOnly) fav.clearPendingSent?.(res.sent);',
        '/* MUTATED: never clear what was sent */',
        async (mutant) => {
            const server = makeServer();
            const d = await device(server, mutant);
            d.signIn(); d.add('CP-1'); await d.push();
            return d.app.favorites.getPendingAdds().length === 0;
        }
    );
    check('with the clearing skipped, sent ids stay pending (the test can fail)', ok === false);
}
{
    /* both lists dropped from the request: the server never hears about removals */
    const r = await withMutant(
        'deleted: sentDeleted,\n                    added: sentAdded,',
        '/* MUTATED: lists not sent */',
        async (mutant) => {
            const server = makeServer();
            const d = await device(server, mutant);
            d.signIn(); await d.reconcile();
            return Object.prototype.hasOwnProperty.call(server.requests.at(-1).body, 'deleted');
        }
    );
    check('with the lists left out of the request, the "deleted" marker key is missing (the test can fail)', r === false);
}

{
    /* the OLD clearing rule: an id sent as "added" also leaves the removed list */
    let mutant = null;
    try {
        const src = fs.readFileSync(FAVORITES, 'utf8');
        const from = "if (sentDeleted.size) this._writeIdList(STORAGE_FAVORITES_DELETED, this.getPendingDeletes().filter(id => !sentDeleted.has(id)));";
        if (!src.includes(from)) throw new Error('mutation setup: line not found');
        const to = "if (sentAdded.size || sentDeleted.size) this._writeIdList(STORAGE_FAVORITES_DELETED, this.getPendingDeletes().filter(id => !sentDeleted.has(id) && !sentAdded.has(id)));";
        mutant = path.join(MODULES, `zz_ihymns_test_favorites_mutant_${Date.now()}.js`);
        fs.writeFileSync(mutant, src.replace(from, to).replace("from '../constants.js'", "from '../constants.js'"));
        const r = await inFlightRemoval(USER_AUTH, mutant);
        check('with the OLD clearing rule the in-flight removal is lost (the test can fail)', r.pendingDeletes.length === 0);
    } finally { if (mutant) fs.rmSync(mutant, { force: true }); }
}
{
    /* the reply's stale copy is NOT filtered by the waiting removals */
    const r = await withMutant(
        "if (waitingDeletes.size) serverList = serverList.filter((f) => f && !waitingDeletes.has(f.id));",
        '/* MUTATED: server copy not filtered */',
        (mutant) => inFlightRemoval(mutant, FAVORITES)
    );
    check('without dropping the server\'s stale copy the favourite comes back (the test can fail)', r.ids.includes('CP-2'), J(r.ids));
}

console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) FAILED.`);
process.exit(failures === 0 ? 0 : 1);
