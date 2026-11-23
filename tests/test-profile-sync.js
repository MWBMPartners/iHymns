/**
 * tests/test-profile-sync.js - the browser's half of profile_sync: nothing waiting is overwritten, nothing read is sent back
 * (PR 2b step 2b-5, epic #2180)
 *
 * ELI5
 * ----
 * This plays the real ProfileSync class in pretend browsers (jsdom) against a small pretend server that follows the same
 * rules as includes/profile_sync.php (numbering every accepted change, refusing what the registry does not allow, saying
 * what is new above a cursor). It checks the promises that keep a person's settings safe:
 *   - a change made on this device is queued, sent once after a short pause, and removed from the queue only when the
 *     server has it (a change made while the request was in flight stays queued);
 *   - a change that is still waiting is NEVER overwritten by something read from the account;
 *   - a value read from the account is written here WITHOUT being queued, so it is never sent back (no echo);
 *   - the first read on a device claims what the account has never heard of, but never what the account deliberately removed;
 *   - a reply that arrives after a DIFFERENT person signed in is dropped; another person's queue is thrown away;
 *   - a paused site keeps the queue and waits 5 minutes; a "not switched on here" answer hands the work back to the older sync;
 *   - a bad local value is never sent and never turns into a delete; a refused change does not stay queued for ever.
 *
 * Detailed (rule #34): the settings list is the REAL `php tools/dump-profile-schema.php`, so no setting is typed here. The
 * pretend server's rules are checked against the registry's own rules (profile-web-codec.js validateSpec). Mutation proofs
 * (recorded in the 2b-5 commit): apply a read over the queue (the "waiting change survives a read" row goes red); queue
 * a value that came from the account (the echo row goes red); clear the queue by id without checking its number (the
 * "change during a send survives" row goes red).
 *
 * Usage: node tests/test-profile-sync.js
 */
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const JS = path.join(REPO, 'appWeb', 'public_html', 'js');
const MODULES = path.join(JS, 'modules');
const url = (p) => pathToFileURL(p).href;

let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + String(detail).slice(0, 400)));
    if (!cond) failures++;
};
const J = (x) => JSON.stringify(x);

const schema = JSON.parse(execFileSync('php', [path.join(REPO, 'tools', 'dump-profile-schema.php')], { encoding: 'utf8' }));
const C = await import(url(path.join(JS, 'constants.js')));
const codec = await import(url(path.join(MODULES, 'profile-web-codec.js')));
const entryOf = (section, key) => schema.entries.find((e) => (e.section === section && e.key === key) || (e.section === section && e.type === 'map'));

/* ---------- a pretend server that follows the real rules ---------- */
function makeServer() {
    const s = { users: new Map(), requests: [], mode: null /* 'paused' | 'disabled' | '413' | null */, hold: null };
    const user = (u) => { if (!s.users.has(u)) s.users.set(u, { max: 0, rows: new Map() }); return s.users.get(u); };
    s.user = user;
    s.seed = (u, section, key, value) => { const d = user(u); d.max += 1; d.rows.set(section + '.' + key, { section, key, value, deleted: value === null, version: d.max }); };
    s.handle = async (u, method, query, body) => {
        s.requests.push({ u, method, query, body });
        if (s.hold) await s.hold;
        if (s.mode === 'disabled') return { status: 409, body: { reason: 'profile_sync_disabled' } };
        if (method === 'POST' && s.mode === 'paused') return { status: 409, body: { reason: 'account_writes_off_here' } };
        if (method === 'POST' && s.mode === '413') return { status: 413, body: { reason: 'too_many_changes' } };
        const d = user(u);
        const rejected = []; let applied = 0;
        if (method === 'POST') {
            for (const c of body.changes) {
                const e = entryOf(c.section, c.key);
                if (!e) { rejected.push({ section: c.section, key: c.key, reason: 'unknown_key' }); continue; }
                const id = c.section + '.' + c.key;
                if (c.op === 'set') {
                    const okVal = e.type === 'map' ? codec.validateSpec(e.item, c.value) : codec.validateSpec(e, c.value);
                    if (!okVal) { rejected.push({ section: c.section, key: c.key, reason: 'invalid_value' }); continue; }
                    const cur = d.rows.get(id);
                    if (cur && !cur.deleted && J(cur.value) === J(c.value)) continue;
                    d.max += 1; d.rows.set(id, { section: c.section, key: c.key, value: c.value, deleted: false, version: d.max }); applied++;
                } else {
                    const cur = d.rows.get(id);
                    if (!cur || cur.deleted) continue;
                    d.max += 1; d.rows.set(id, { section: c.section, key: c.key, value: null, deleted: true, version: d.max }); applied++;
                }
            }
        }
        const cursor = Number(query.get('cursor') ?? body?.cursor ?? 0);
        const reset = cursor > d.max;
        const from = reset ? 0 : cursor;
        const rows = [...d.rows.values()].filter((r) => r.version > from).sort((a, b) => a.version - b.version);
        const page = rows.slice(0, 500);
        return { status: 200, body: { ok: true, cursor: page.length ? page[page.length - 1].version : from, more: rows.length > 500, resetCursor: reset,
            schemaVersion: schema.schemaVersion, changes: page.map((r) => ({ section: r.section, key: r.key, value: r.value, deleted: r.deleted, version: r.version })), rejected, applied } };
    };
    return s;
}

/* ---------- one pretend browser ---------- */
async function device(server, { uid = 1, enabled = true, modulePath = path.join(MODULES, 'profile-sync.js') } = {}) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    const dev = { window, hold: null, timers: [], who: uid, syncCalls: [], applied: [] };
    dev.use = () => {
        Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event });
        Object.defineProperty(globalThis, 'navigator', { value: { onLine: true, serviceWorker: undefined }, configurable: true, writable: true });
        globalThis.fetch = async (input, init = {}) => {
            const u = new URL(String(input), 'https://example.test/');
            const action = u.searchParams.get('action');
            const method = (init.method || 'GET').toUpperCase();
            const make = (r) => ({ ok: r.status >= 200 && r.status < 300, status: r.status, headers: { get: (h) => (h.toLowerCase() === 'etag' ? '"t1"' : null) }, json: async () => r.body, clone() { return { json: async () => r.body }; } });
            if (action === 'profile_schema') return make({ status: 200, body: schema });
            if (action === 'profile_sync') {
                if (dev.hold) await dev.hold;
                const who = dev.who;
                return make(await server.handle(who, method, u.searchParams, init.body ? JSON.parse(init.body) : null));
            }
            return make({ status: 200, body: {} });
        };
    };
    dev.use();
    const { ProfileSync } = await import(url(modulePath) + '?t=' + Date.now() + Math.random());
    const app = {
        config: { apiUrl: '/api', profileSync: { enabled } },
        userAuth: { isLoggedIn: () => dev.who !== null, getUser: () => (dev.who === null ? null : { id: dev.who }), authHeaders: () => ({}) },
        /* exactly what app.js's syncStorage does for the profile sync: pass the key AND the options through */
        syncStorage(key, opts) { dev.syncCalls.push({ key, opts: opts || {} }); dev.sync.recordLocalChange(key, opts || {}); },
    };
    dev.app = app;
    dev.sync = new ProfileSync(app, { storage: window.localStorage, setTimer: (fn, ms) => { const t = { fn, ms }; dev.timers.push(t); return t; }, clearTimer: (t) => { dev.timers = dev.timers.filter((x) => x !== t); } });
    document.addEventListener(C.EVT_PROFILE_APPLIED, (e) => dev.applied.push(...e.detail.keys));
    dev.ls = window.localStorage;
    dev.set = (key, val) => { dev.use(); dev.ls.setItem(key, val); dev.sync.recordLocalChange(key); };
    dev.remove = (key) => { dev.use(); dev.ls.removeItem(key); dev.sync.recordLocalChange(key); };
    dev.timerMs = () => dev.timers.map((t) => t.ms);
    dev.runTimers = async () => { dev.use(); const t = dev.timers; dev.timers = []; for (const x of t) await x.fn(); };
    return dev;
}
const posts = (server) => server.requests.filter((r) => r.method === 'POST');

console.log('\n1 - a local change is queued, sent once, and removed from the queue only when the server has it\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    d.set(C.STORAGE_THEME, 'dark');
    check('the change is in the outbox', Object.keys(d.sync.getOutbox().items).join() === 'appearance.theme' && d.sync.getOutbox().items['appearance.theme'].value === 'dark');
    check('a send is scheduled after the 1.5 second pause', d.timerMs().includes(1500));
    d.set(C.STORAGE_THEME, 'light');
    check('a second change to the same setting replaces the first (one entry)', d.sync.pendingCount() === 1 && d.sync.getOutbox().items['appearance.theme'].value === 'light');
    check('only the last pause matters: one send is scheduled, not two', d.timers.length === 1);
    d.use(); await d.sync.push();
    check('exactly one request was sent, carrying only the changed setting', posts(server).length === 1 && J(posts(server)[0].body.changes) === J([{ section: 'appearance', key: 'theme', op: 'set', value: 'light' }]) && posts(server)[0].body.client.platform === 'web');
    check('the server has it, the outbox is empty', server.user(1).rows.get('appearance.theme').value === 'light' && d.sync.pendingCount() === 0 && d.ls.getItem(C.STORAGE_PROFILE_OUTBOX) === null);
    check('the value sent back to us (our own change) was not written again or announced', d.syncCalls.every((c) => c.opts.origin !== 'account') && d.applied.length === 0);
    d.set(C.STORAGE_FONT_SIZE, '22');
    d.use(); await d.sync.push();
    check('a different setting later sends only that one', J(posts(server)[1].body.changes.map((c) => c.key)) === '["fontSize"]' && server.user(1).rows.get('appearance.fontSize').value === 22);
}

console.log('\n2 - a change made while a send is in flight is not lost\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    d.set(C.STORAGE_THEME, 'dark');
    let release; d.hold = new Promise((r) => { release = r; });
    d.use(); const sending = d.sync.push();
    await new Promise((r) => setTimeout(r, 5));
    d.set(C.STORAGE_THEME, 'high-contrast');   /* changed again while the first send is on its way */
    release(); await sending;
    check('the newer change is still waiting (it was not the one that was sent)', d.sync.pendingCount() === 1 && d.sync.getOutbox().items['appearance.theme'].value === 'high-contrast');
    d.hold = null; d.use(); await d.sync.push();
    check('and it reaches the server on the next send', server.user(1).rows.get('appearance.theme').value === 'high-contrast' && d.sync.pendingCount() === 0);
}

console.log('\n3 - a change still waiting is never overwritten by a read; a read value is not sent back\n');
{
    const server = makeServer();
    server.seed(1, 'appearance', 'theme', 'dark');
    server.seed(1, 'appearance', 'fontSize', 22);
    const d = await device(server);
    await d.sync.ensureSchema();
    d.set(C.STORAGE_THEME, 'light');            /* waiting to be sent; the account says dark */
    d.use(); const ok = await d.sync.pull();
    check('the read worked', ok === true);
    check('the waiting theme was NOT overwritten by the account\'s dark', d.ls.getItem(C.STORAGE_THEME) === 'light' && d.sync.getOutbox().items['appearance.theme'].value === 'light');
    check('the account\'s other value (text size 22) WAS applied, as text', d.ls.getItem(C.STORAGE_FONT_SIZE) === '22');
    check('...and announced so the page re-applies it', d.applied.includes(C.STORAGE_FONT_SIZE) && !d.applied.includes(C.STORAGE_THEME));
    check('...and marked as coming from the account (so it goes to the cookie only)', d.syncCalls.some((c) => c.key === C.STORAGE_FONT_SIZE && c.opts.origin === 'account'));
    check('the value read from the account was NOT queued for sending back', !('appearance.fontSize' in d.sync.getOutbox().items) && d.sync.pendingCount() === 1);
    check('the read sent nothing (no POST)', posts(server).length === 0);
    d.set(C.STORAGE_FONT_SIZE, '22');           /* the same value, set again by hand: it still goes (it is a change the person made) */
    check('a value the person sets by hand IS queued even if it equals what the account holds', d.sync.pendingCount() === 2);
    d.use(); await d.sync.push();
    check('the waiting theme reaches the account afterwards', server.user(1).rows.get('appearance.theme').value === 'light');
    const cur = d.sync._getCursorRecord().cursor;
    d.use(); await d.sync.pull();
    check('the cursor only goes up', d.sync._getCursorRecord().cursor >= cur);
}

console.log('\n4 - a removal travels both ways\n');
{
    const server = makeServer();
    const a = await device(server); const b = await device(server);
    await a.sync.ensureSchema(); await b.sync.ensureSchema();
    a.set(C.STORAGE_THEME, 'dark'); a.use(); await a.sync.push();
    b.use(); await b.sync.pull();
    check('device B picked up the theme', b.ls.getItem(C.STORAGE_THEME) === 'dark');
    a.remove(C.STORAGE_THEME);
    check('removing it locally queues a delete', a.sync.getOutbox().items['appearance.theme'].op === 'delete');
    a.use(); await a.sync.push();
    b.use(); await b.sync.pull();
    check('device B removed it too', b.ls.getItem(C.STORAGE_THEME) === null && server.user(1).rows.get('appearance.theme').deleted === true);
    check('...without queueing anything (a removal read from the account is not sent back)', b.sync.pendingCount() === 0);
}

console.log('\n5 - the first read claims what the account has never heard of, never what it removed\n');
{
    const server = makeServer();
    server.seed(1, 'appearance', 'reduceMotion', true);
    server.user(1).rows.get('appearance.reduceMotion').deleted = true; server.user(1).rows.get('appearance.reduceMotion').value = null;   /* the person removed it on purpose */
    const d = await device(server);
    d.use();
    d.ls.setItem(C.STORAGE_THEME, 'dark');
    d.ls.setItem(C.STORAGE_REDUCE_MOTION, 'true');
    d.ls.setItem(C.STORAGE_FONT_SIZE, '17');          /* not a valid value (odd size) */
    d.ls.setItem(C.STORAGE_MOST_VIEWED_PERIOD, '90d');   /* a setting the registry does not let an anonymous visitor claim (claim: false) */
    await d.sync.ensureSchema();
    const ok = await d.sync.pull();
    const q = d.sync.getOutbox().items;
    check('the read succeeded and recorded that the first read is done', ok === true && d.sync._getCursorRecord().claimed === true);
    check('the theme the account has never heard of is queued to be claimed', q['appearance.theme']?.value === 'dark');
    check('a value the account deliberately removed is NOT claimed back', !('appearance.reduceMotion' in q));
    check('an invalid local value is not claimed', !('appearance.fontSize' in q));
    check('a setting the registry marks claim:false is not queued', entryOf('home', 'mostViewedPeriod').claim === false && !('home.mostViewedPeriod' in q));
    const before = d.sync.pendingCount();
    d.use(); await d.sync.pull();
    check('a later read does not claim again', d.sync.pendingCount() === before);
}

console.log('\n6 - a bad local value is never sent and never becomes a delete; a refused change does not stay queued\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    d.set(C.STORAGE_THEME, 'solarized');
    check('an invalid value is not queued (and not turned into a delete)', d.sync.pendingCount() === 0);
    d.set(C.STORAGE_REDUCE_MOTION, 'yes');
    check('...nor is a true/false value that is not true or false', d.sync.pendingCount() === 0);
    /* a value the registry allows but this fake server refuses */
    d.use(); d.ls.setItem(C.STORAGE_THEME, 'dark'); d.sync._enqueue('appearance', 'theme', 'set', 'not-allowed-by-server');
    await d.sync.push();
    check('a change the server refuses is dropped from the queue (it would never be accepted), not retried for ever', d.sync.pendingCount() === 0 && server.user(1).rows.size === 0);
}

console.log('\n7 - the wrong person\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    d.set(C.STORAGE_THEME, 'dark');
    check('the outbox names the person', d.sync.getOutbox().uid === '1');
    d.who = 2;
    check('another person signing in on this device sees an EMPTY outbox (the first person\'s changes are not theirs to send)', d.sync.pendingCount() === 0);
    d.who = 1;
    server.seed(1, 'appearance', 'fontSize', 20);
    let release; d.hold = new Promise((r) => { release = r; });
    d.use(); const pulling = d.sync.pull();
    await new Promise((r) => setTimeout(r, 5));
    d.who = 2;                                   /* the person changes while the reply is on its way */
    release(); const result = await pulling;
    check('a reply that arrives after a different person signed in is dropped (nothing applied)', result === false && d.ls.getItem(C.STORAGE_FONT_SIZE) === null);
    d.hold = null;
}

console.log('\n8 - paused site, and "not switched on here"\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    server.mode = 'paused';
    d.set(C.STORAGE_THEME, 'dark');
    d.use(); const ok = await d.sync.push();
    check('a paused site keeps the queue and says it is not done', ok === false && d.sync.pendingCount() === 1);
    check('...and waits 5 minutes before trying again', d.timerMs().includes(5 * 60 * 1000));
    check('...but the new sync is still the active one (the older one does not take over)', d.sync.isActive() === true);
    server.seed(1, 'appearance', 'fontSize', 24);
    d.use(); await d.sync.pull();
    check('a read still works on a paused site', d.ls.getItem(C.STORAGE_FONT_SIZE) === '24' && d.sync.pendingCount() === 1);
    server.mode = 'disabled';
    d.use(); await d.sync.pull();
    check('"not switched on for this site" hands the work back to the older sync (isActive() turns false)', d.sync.isActive() === false);
    check('...and nothing more is queued once it is off', (() => { const n = d.sync.pendingCount(); d.set(C.STORAGE_FONT_SIZE, '20'); return d.sync.pendingCount() === n; })());
    const off = await device(server, { enabled: false });
    check('when the page says the feature is off, it is never active', off.sync.isActive() === false);
}

console.log('\n9 - opting out of syncing, and a 413\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    d.use(); d.ls.setItem('ihymns_sync_app_settings', 'false');
    check('a person who switched settings sync off is not active (the older sync honours the same switch)', d.sync.isActive() === false);
    d.set(C.STORAGE_THEME, 'dark');
    check('...and nothing is queued', d.sync.pendingCount() === 0);
    d.ls.removeItem('ihymns_sync_app_settings');
    for (let i = 0; i < 3; i++) d.sync._enqueue('library', 'recentSongbooks', 'set', ['MP']);
    d.sync._enqueue('appearance', 'theme', 'set', 'dark'); d.sync._enqueue('appearance', 'fontSize', 'set', 20); d.sync._enqueue('appearance', 'transition', 'set', 'fade');
    server.mode = '413';
    d.use(); await d.sync.push();
    check('a 413 halves the batch for the next try and keeps everything queued', d.sync._batchLimit < 200 && d.sync.pendingCount() === 4);
    server.mode = null;
    for (let i = 0; i < 6 && d.sync.pendingCount() > 0; i++) { d.use(); await d.sync.push(); }
    check('and the smaller batches get through, every change reaching the server', d.sync.pendingCount() === 0 && server.user(1).rows.size === 4);
}

console.log('\n10 - two devices end up agreeing\n');
{
    const server = makeServer();
    const a = await device(server); const b = await device(server);
    await a.sync.ensureSchema(); await b.sync.ensureSchema();
    a.set(C.STORAGE_THEME, 'dark'); a.set(C.STORAGE_FONT_SIZE, '20');
    b.set(C.STORAGE_FONT_SIZE, '24'); b.set(C.STORAGE_REDUCE_MOTION, 'true');
    a.use(); await a.sync.push();
    b.use(); await b.sync.push();
    a.use(); await a.sync.pull();
    b.use(); await b.sync.pull();
    const view = (d) => J(['ihymns_theme', 'ihymns_fontSize', 'ihymns_reduceMotion'].map((k) => d.ls.getItem(k)));
    check('both devices hold the same values (the later arrival, B\'s 24, won the text size)', view(a) === view(b) && a.ls.getItem(C.STORAGE_FONT_SIZE) === '24' && a.ls.getItem(C.STORAGE_THEME) === 'dark' && a.ls.getItem(C.STORAGE_REDUCE_MOTION) === 'true', view(a) + ' / ' + view(b));
    check('and nothing is left waiting on either', a.sync.pendingCount() === 0 && b.sync.pendingCount() === 0);
}

console.log('\n11 - the cached list of settings lets the app start offline\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    check('the downloaded list is saved with its tag', J(Object.keys(JSON.parse(d.ls.getItem(C.STORAGE_PROFILE_SCHEMA)))) === '["etag","data"]' && JSON.parse(d.ls.getItem(C.STORAGE_PROFILE_SCHEMA)).etag === '"t1"');
    const { ProfileSync } = await import(url(path.join(MODULES, 'profile-sync.js')) + '?t=' + Math.random());
    d.use();
    const saved = globalThis.fetch;
    globalThis.fetch = async () => { throw new Error('offline'); };
    const offlineSync = new ProfileSync(d.app, { storage: d.ls });
    check('with no network the saved copy is used', (await offlineSync.ensureSchema()) === true && offlineSync.providers.all.length > 10);
    globalThis.fetch = saved;
}

console.log('\n12 - app.js hands the options through\n');
{
    const fs = await import('node:fs');
    const appJs = fs.readFileSync(path.join(JS, 'app.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
    check('app.js syncStorage(key, opts) passes opts to the profile sync (so origin "account" is honoured)', /syncStorage\(key, opts = \{\}\)/.test(appJs) && /this\.profileSync\?\.recordLocalChange\(key, opts\)/.test(appJs));
    check('app.js creates the profile sync only when the page says it is switched on', /if \(this\.config\.profileSync\?\.enabled\)[\s\S]{0,200}new ProfileSync\(this\)/.test(appJs));
    const settingsJs = fs.readFileSync(path.join(MODULES, 'settings.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
    check('settings.js stands its older whole-box sync down while the profile sync is active', /profileSync\?\.isActive\?\.\(\)\) return false/.test(settingsJs));
}

console.log('\n14 - switching settings sync back on never overwrites newer account values or brings back removed items\n');
{
    const server = makeServer();
    const d = await device(server);
    await d.sync.ensureSchema();
    /* the account moved on while this device had sync switched off */
    server.seed(1, 'appearance', 'theme', 'light');                 // changed on another device
    server.seed(1, 'songPrefs', 'MP-1', { transpose: 2, capo: 0, octave: 0 });
    server.seed(1, 'songPrefs', 'MP-1', null);                      // ... and then removed there (a delete marker)
    d.use();
    d.ls.setItem(C.STORAGE_THEME, 'dark');                          // stale on this device
    d.ls.setItem(C.STORAGE_TRANSPOSE_PREFIX + 'MP-1', '2');         // the removed per-song choice, still here
    d.ls.setItem(C.STORAGE_FONT_SIZE, '20');                        // something the account never heard of
    const ok = await d.sync.resumeAfterOptIn();
    await d.sync.push();
    check('the read finished', ok === true);
    check('the account\'s newer theme was NOT overwritten by this device\'s older one', server.user(1).rows.get('appearance.theme').value === 'light' && d.ls.getItem(C.STORAGE_THEME) === 'light');
    check('the per-song item removed on the account stays removed (not brought back)', server.user(1).rows.get('songPrefs.MP-1').deleted === true && d.ls.getItem(C.STORAGE_TRANSPOSE_PREFIX + 'MP-1') === null);
    check('a value the account has never heard of IS sent (nothing is lost)', server.user(1).rows.get('appearance.fontSize')?.value === 20);
    check('no request carried the stale theme or the removed song', posts(server).every((r) => !J(r.body.changes).includes('"dark"') && !J(r.body.changes).includes('MP-1')));
    const fs = await import('node:fs');
    const settingsJs = fs.readFileSync(path.join(MODULES, 'settings.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
    check('settings.js uses the resume route, and nothing queues "everything this device holds" any more', /profileSync\.resumeAfterOptIn\(\)/.test(settingsJs) && !/queueAllLocal/.test(settingsJs) && !/queueAllLocal/.test(fs.readFileSync(path.join(MODULES, 'profile-sync.js'), 'utf8')));
}

console.log('\n13 - the refusal reasons: the browser and the server agree (rule #35)\n');
{
    const { PERMANENT_REJECTIONS } = await import(url(path.join(MODULES, 'profile-sync.js')) + '?t=' + Math.random());
    const serverReasons = JSON.parse(execFileSync('php', ['-r', '$_SERVER["SCRIPT_FILENAME"]="t"; require ' + J(path.join(REPO, 'appWeb', 'public_html', 'includes', 'profile_sync.php')) + '; echo json_encode(PROFILE_SYNC_REJECT_REASONS);'], { encoding: 'utf8' }));
    /* Every reason the server can give for a single change is a refusal that will never be accepted as sent, so the browser must drop it
       from its outbox. A reason on one list and not the other would either retry a doomed change for ever or drop a retryable one. */
    check('every reason the server can give is in the browser\'s "never retry" list, and the browser lists none the server does not give',
        J([...serverReasons].sort()) === J([...PERMANENT_REJECTIONS].sort()), J(serverReasons) + ' vs ' + J(PERMANENT_REJECTIONS));
}

console.log(failures === 0 ? '\nAll profile-sync checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
