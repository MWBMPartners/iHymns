/**
 * tests/test-search-history-sync.js - recent searches stay on the device unless the person says otherwise (PR 2b step 2b-6, #2177)
 *
 * ELI5
 * ----
 * What someone searched for is private. By default it never leaves the device. There is a switch, "Keep my recent searches in my
 * account", off until the person flips it. This runs the real search history, the real sync and a pretend server to check: with the
 * switch off NOTHING about searches is queued or sent (not even when the account has a list); with it on, the whole list is sent
 * and every change to it (a new search, removing one, clearing all) travels; flipping it off deletes the account's copy but leaves
 * this device's list; at sign-in the account's list replaces the device's (only while the switch is on); the first sign-in
 * never claims a searches list; over-long or odd entries are tidied so the list always fits the account's rules.
 *
 * Detailed (rule #34): the entries come from the real `php tools/dump-profile-schema.php`; the search words used are searched for in
 * the stored outbox text and in every request body, so a leak shows up wherever it is. Mutation proofs (in the commit): let the
 * provider read the list while the switch is off (the "nothing queued" row goes red); stop the OFF switch deleting the account's copy.
 *
 * Usage: node tests/test-search-history-sync.js
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
const searches = schema.entries.find((x) => x.id === 'history.searches');
const option = schema.entries.find((x) => x.id === 'privacy.syncSearchHistory');
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
const { window } = dom;
Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event });
Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
const C = await import(url(path.join(JS, 'constants.js')));
const { SearchHistory } = await import(url(path.join(JS, 'modules', 'search-history.js')));
const { ADAPTER_ENTRY_IDS } = await import(url(path.join(JS, 'modules', 'profile-sync-adapters.js')));

console.log('\n1 - the registry entries\n');
check('recent searches: up to 20 strings of up to 100 characters, account scope, cleared at sign-out, never claimed, never in the cookie',
    searches && searches.type === 'list' && searches.maxItems === 20 && searches.item.maxLength === 100 && searches.scope === 'account' && searches.clearAtSignOut === true && searches.claim === false && searches.cookie === false);
check('the switch: a true/false setting that follows the account, off by default', option && option.type === 'bool' && option.default === false && option.scope === 'account' && option.sync === 'profile_sync');
check('the web has hand-written providers for both', ADAPTER_ENTRY_IDS.includes('history.searches') && ADAPTER_ENTRY_IDS.includes('privacy.syncSearchHistory'));

/* ---------- a pretend server ---------- */
const bodies = []; const server = { rows: new Map(), max: 0 };
const seed = (section, key, value, deleted = false) => { server.max += 1; server.rows.set(section + '.' + key, { section, key, value, deleted, version: server.max }); };
globalThis.fetch = async (input, init = {}) => {
    const u = new URL(String(input), 'https://example.test/');
    const mk = (b) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => b, clone() { return { json: async () => b }; } });
    if (u.searchParams.get('action') === 'profile_schema') return mk(schema);
    const body = init.body ? JSON.parse(init.body) : null;
    if (body) {
        bodies.push(init.body);
        for (const c of body.changes) {
            if (c.op === 'set') seed(c.section, c.key, c.value); else seed(c.section, c.key, null, true);
        }
    }
    const from = Number(u.searchParams.get('cursor') ?? body?.cursor ?? 0);
    const rows = [...server.rows.values()].filter((r) => r.version > from).sort((a, b) => a.version - b.version);
    return mk({ ok: true, cursor: rows.length ? rows[rows.length - 1].version : from, more: false, resetCursor: false, schemaVersion: schema.schemaVersion, changes: rows, rejected: [], applied: 0 });
};
const { ProfileSync } = await import(url(path.join(JS, 'modules', 'profile-sync.js')) + '?t=' + Math.random());
let sync;
const app = { config: { apiUrl: '/api', profileSync: { enabled: true } }, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: 1 }) }, syncStorage(k, o) { sync.recordLocalChange(k, o || {}); } };
sync = new ProfileSync(app, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
await sync.ensureSchema();
const history = new SearchHistory(app);
const outboxText = () => localStorage.getItem(C.STORAGE_PROFILE_OUTBOX) || '';

console.log('\n2 - with the switch off (the default) nothing about searches leaves the device\n');
history.record('amazing grace'); history.record('how great thou art');
check('the searches are kept on the device', J(history.getHistory()) === '["how great thou art","amazing grace"]');
check('nothing was queued, and the words are nowhere in the stored outbox', sync.pendingCount() === 0 && !outboxText().includes('amazing'));
seed('history', 'searches', ['secret from another device']);
await sync.pull();
check('after a full sync no request carried the words', bodies.every((b) => !b.includes('amazing') && !b.includes('how great')));
check('...and the account\'s list was NOT written onto this device while the switch is off', J(history.getHistory()) === '["how great thou art","amazing grace"]');
check('the first sign-in did not claim the searches', !('history.searches' in sync.getOutbox().items));
server.rows.clear(); server.max = 0;

console.log('\n3 - switching it on sends the list, and every change travels\n');
await sync.setSearchHistorySync(true);
check('the switch is stored on the device', localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === 'true');
check('both the switch and the whole list are queued', J(Object.keys(sync.getOutbox().items).sort()) === '["history.searches","privacy.syncSearchHistory"]' && J(sync.getOutbox().items['history.searches'].value) === '["how great thou art","amazing grace"]');
await sync.push();
check('they reach the account', server.rows.get('history.searches').value.length === 2 && server.rows.get('privacy.syncSearchHistory').value === true && sync.pendingCount() === 0);
history.record('be thou my vision');
check('a new search queues the whole list again', sync.getOutbox().items['history.searches']?.value[0] === 'be thou my vision');
history.remove('amazing grace');
check('removing one search queues the shorter list', J(sync.getOutbox().items['history.searches'].value) === '["be thou my vision","how great thou art"]');
history.clear();
check('clearing all queues a delete', sync.getOutbox().items['history.searches']?.op === 'delete');
await sync.push();
check('and the account\'s copy is removed', server.rows.get('history.searches').deleted === true);

console.log('\n4 - the list is tidied so it always fits the account\'s rules\n');
localStorage.setItem(C.STORAGE_SEARCH_HISTORY, J(['x'.repeat(150), 'dup', 'dup', 7, null, '  ', ...Array.from({ length: 30 }, (_, i) => 'q' + i)]));
app.syncStorage(C.STORAGE_SEARCH_HISTORY);
const q = sync.getOutbox().items['history.searches'].value;
check('over-long entries are cut to 100, repeats and non-text entries are dropped, and at most 20 are sent', q.length === 20 && q[0].length === 100 && q.filter((x) => x === 'dup').length === 1 && q.every((x) => typeof x === 'string' && x.trim() !== ''));
localStorage.setItem(C.STORAGE_SEARCH_HISTORY, 'not json');
app.syncStorage(C.STORAGE_SEARCH_HISTORY);
check('a damaged list is never sent and never becomes a delete', sync.getOutbox().items['history.searches'].value.length === 20);
await sync.push();

console.log('\n5 - an account list replaces the device\'s, while the switch is on\n');
seed('history', 'searches', ['from my phone', 'another']);
await sync.pull();
check('the device\'s list became the account\'s', J(history.getHistory()) === '["from my phone","another"]');
check('...and was not sent back', !('history.searches' in sync.getOutbox().items));

console.log('\n6 - switching it off deletes the account\'s copy but not the device\'s\n');
await sync.setSearchHistorySync(false);
check('the switch is gone from the device', localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === null);
check('a delete of the account\'s list and of the switch are queued', sync.getOutbox().items['history.searches']?.op === 'delete' && sync.getOutbox().items['privacy.syncSearchHistory']?.op === 'delete');
await sync.push();
check('the account\'s copy is gone', server.rows.get('history.searches').deleted === true && server.rows.get('privacy.syncSearchHistory').deleted === true);
check('this device\'s own list is untouched', J(history.getHistory()) === '["from my phone","another"]');
history.record('after switching off');
check('and new searches are queued no more', sync.pendingCount() === 0);

console.log('\n7 - a shared browser: one person\'s switch and searches never reach the next person\'s account\n');
{
    const owner = await import(url(path.join(JS, 'modules', 'account-data-owner.js')));
    localStorage.clear();
    let who = 1;
    const accounts = new Map();                         // person -> Map(section.key -> row)
    const sentBy = new Map();                           // person -> every request body they sent
    const acct = (u) => { if (!accounts.has(u)) accounts.set(u, { max: 0, rows: new Map() }); return accounts.get(u); };
    globalThis.fetch = async (input, init = {}) => {
        const u = new URL(String(input), 'https://example.test/');
        const mk = (b) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => b, clone() { return { json: async () => b }; } });
        if (u.searchParams.get('action') === 'profile_schema') return mk(schema);
        const a = acct(who);
        const body = init.body ? JSON.parse(init.body) : null;
        if (body) {
            if (!sentBy.has(who)) sentBy.set(who, []);
            sentBy.get(who).push(init.body);
            for (const c of body.changes) { a.max += 1; a.rows.set(c.section + '.' + c.key, { section: c.section, key: c.key, value: c.op === 'set' ? c.value : null, deleted: c.op !== 'set', version: a.max }); }
        }
        const from = Number(u.searchParams.get('cursor') ?? body?.cursor ?? 0);
        const rows = [...a.rows.values()].filter((r) => r.version > from).sort((x, y) => x.version - y.version);
        return mk({ ok: true, cursor: rows.length ? rows[rows.length - 1].version : (from > a.max ? 0 : from), more: false, resetCursor: false, schemaVersion: schema.schemaVersion, changes: rows, rejected: [], applied: 0 });
    };
    let s2;
    const app2 = { config: { apiUrl: '/api', profileSync: { enabled: true } }, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: who }) }, syncStorage(k, o) { s2.recordLocalChange(k, o || {}); } };
    s2 = new ProfileSync(app2, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
    await s2.ensureSchema();
    const h2 = new SearchHistory(app2);
    const signIn = async (uid) => { who = uid; owner.reconcileDataOwner(localStorage, uid); await s2.syncNow(); };

    await signIn(1);
    await s2.setSearchHistorySync(true);
    h2.record('amazing grace'); h2.record('be thou my vision');
    await s2.push();
    check('person A opted in and her searches reached HER account', J(acct(1).rows.get('history.searches')?.value) === '["be thou my vision","amazing grace"]' && acct(1).rows.get('privacy.syncSearchHistory')?.value === true);

    await signIn(2);                                    // A leaves the shared browser; B signs in
    check('B does not inherit A\'s opt-in', localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === null);
    check('B does not see A\'s searches', localStorage.getItem(C.STORAGE_SEARCH_HISTORY) === null);
    h2.record('great is thy faithfulness');
    await s2.push(); await s2.syncNow();
    check('B\'s own search is kept on the device (the option is off for B)', J(h2.getHistory()) === '["great is thy faithfulness"]');
    check('nothing of A\'s, and nothing of B\'s searches, was ever sent for B', (sentBy.get(2) || []).every((b) => !b.includes('amazing') && !b.includes('vision') && !b.includes('faithfulness') && !b.includes('syncSearchHistory')));
    check('B\'s account holds no searches and no option', !acct(2).rows.has('history.searches') && !acct(2).rows.has('privacy.syncSearchHistory'));

    localStorage.removeItem(C.STORAGE_SEARCH_HISTORY);  // B clears their device-only list before leaving
    await signIn(1);                                    // A comes back
    check('A gets her own switch back', localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === 'true');
    check('...and her own searches back, untouched', J(h2.getHistory()) === '["be thou my vision","amazing grace"]');
    check('...and B\'s searches never entered her list', !(localStorage.getItem(C.STORAGE_SEARCH_HISTORY) || '').includes('faithfulness'));

    /* the device-only list of a person who never opted in is left exactly as it was (not stashed, not cleared) */
    localStorage.removeItem(C.STORAGE_SYNC_SEARCH_HISTORY);
    localStorage.setItem(C.STORAGE_SEARCH_HISTORY, J(['just on this device']));
    owner.reconcileDataOwner(localStorage, 3);
    check('with the option off, the older device-only list is not touched by a change of person (as before)', localStorage.getItem(C.STORAGE_SEARCH_HISTORY) === J(['just on this device']));
}

console.log('\n8 - the switch only changes this device when the account can be told, and the row follows whether the sync is working\n');
{
    localStorage.clear();
    const row = await import(url(path.join(JS, 'modules', 'search-history-sync-row.js')));
    document.body.innerHTML = '<div id="setting-sync-search-history-wrap" class="d-none"><input type="checkbox" id="setting-sync-search-history"></div>';
    const toggle = document.getElementById('setting-sync-search-history');
    const wrap = document.getElementById('setting-sync-search-history-wrap');
    let signedIn = true; const toasts = [];
    const app3 = { config: { apiUrl: '/api', profileSync: { enabled: true } }, userAuth: { isLoggedIn: () => signedIn, getUser: () => ({ id: 9 }) }, syncStorage(k, o) { s3.recordLocalChange(k, o || {}); }, showToast: (m) => toasts.push(m) };
    let s3 = new ProfileSync(app3, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
    app3.profileSync = s3;
    s3.init();
    row.bindSearchHistorySyncRow(document, app3, localStorage);
    const changed = () => new Promise((r) => setTimeout(r, 20));

    check('the row is shown while the sync is working', !wrap.classList.contains('d-none'));
    signedIn = false; s3.refreshActive(); await changed();
    check('...and hides as soon as the sync stops being active (here: signed out), without the page being redrawn', wrap.classList.contains('d-none'));
    signedIn = true; s3.refreshActive(); await changed();
    check('...and comes back when it is active again', !wrap.classList.contains('d-none'));
    localStorage.setItem('ihymns_sync_app_settings', 'false'); s3.refreshActive(); await changed();
    check('...and hides when the person switches their own settings sync off', wrap.classList.contains('d-none'));
    localStorage.removeItem('ihymns_sync_app_settings'); s3.refreshActive(); await changed();

    /* the change cannot be queued: the sync is not active */
    signedIn = false;
    const direct = await s3.setSearchHistorySync(true);
    check('with the sync not active, switching the option on changes NOTHING on this device and says so', direct === false && localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === null && s3.pendingCount() === 0);
    signedIn = true;
    /* the schema cannot be loaded (offline and nothing saved) */
    localStorage.removeItem(C.STORAGE_PROFILE_SCHEMA);   /* nothing saved from earlier */
    const offline = new ProfileSync(app3, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
    const savedFetch = globalThis.fetch; globalThis.fetch = async () => { throw new Error('offline'); };
    const r2 = await offline.setSearchHistorySync(true);
    globalThis.fetch = savedFetch;
    check('with the list of settings not loadable, the option is not changed either', r2 === false && localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === null);

    /* through the real row: the box is put back and the person told */
    app3.profileSync = offline; globalThis.fetch = async () => { throw new Error('offline'); };
    toggle.checked = true; toggle.dispatchEvent(new window.Event('change')); await changed();
    globalThis.fetch = savedFetch;
    check('flipping the box when nothing can be queued puts the box back and tells the person', toggle.checked === false && localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === null && toasts.length === 1);

    /* and when it can be queued it works and stays */
    app3.profileSync = s3; await s3.ensureSchema();
    toggle.checked = true; toggle.dispatchEvent(new window.Event('change')); await changed();
    check('when it can be queued the change is made, queued, and the box stays on', toggle.checked === true && localStorage.getItem(C.STORAGE_SYNC_SEARCH_HISTORY) === 'true' && s3.getOutbox().items['privacy.syncSearchHistory']?.value === true);
}

console.log('\n9 - the size limit: what the registry calls valid is storable, and the browser trims to fit\n');
{
    localStorage.clear();
    const codec = await import(url(path.join(JS, 'modules', 'profile-web-codec.js')));
    const serverLimit = Number(execFileSync('php', ['-r', '$_SERVER["SCRIPT_FILENAME"]="t"; require ' + J(path.join(REPO, 'appWeb', 'public_html', 'includes', 'profile_sync.php')) + '; echo PROFILE_SYNC_MAX_VALUE_BYTES;'], { encoding: 'utf8' }));
    check('the entry carries a byte limit no bigger than the server\'s per-item limit', Number.isInteger(searches.maxBytes) && searches.maxBytes <= serverLimit, J(searches.maxBytes));
    /* the same list judged by PHP's registry and by the browser's twin */
    const cjk = (n, len) => Array.from({ length: n }, (_, i) => String.fromCodePoint(0x8CDB + i).repeat(len));
    const lists = [cjk(20, 100), cjk(8, 100), cjk(13, 100), cjk(12, 100), cjk(20, 20), Array.from({ length: 20 }, () => ' '.repeat(100)), Array.from({ length: 12 }, () => ' '.repeat(100)), Array.from({ length: 20 }, () => '😀'.repeat(50)), ['a'], []];
    const php = JSON.parse(execFileSync('php', ['-r', 'require ' + J(path.join(REPO, 'appWeb', 'public_html', 'includes', 'profile_registry.php')) + '; $in = json_decode(stream_get_contents(STDIN), true); echo json_encode(array_map(fn($l) => profileRegistryValidate("history", "searches", $l) ? 1 : 0, $in));'], { input: J(lists), encoding: 'utf8' }));
    const web = lists.map((l) => (codec.validateSpec(searches, l) ? 1 : 0));
    check('PHP and the browser give the same verdict on every list, including the 4 KiB boundary and U+2028 (which PHP writes as six characters)', J(php) === J(web) && php.includes(0) && php.includes(1), J(php) + ' vs ' + J(web));
    /* the browser trims the oldest searches instead of sending something the account would refuse */
    localStorage.setItem(C.STORAGE_SYNC_SEARCH_HISTORY, 'true');
    let s4; const app4 = { config: { apiUrl: '/api', profileSync: { enabled: true } }, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: 4 }) }, syncStorage(k, o) { s4.recordLocalChange(k, o || {}); } };
    s4 = new ProfileSync(app4, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
    await s4.ensureSchema();
    localStorage.setItem(C.STORAGE_SEARCH_HISTORY, J(cjk(20, 100)));
    app4.syncStorage(C.STORAGE_SEARCH_HISTORY);
    const queued = s4.getOutbox().items['history.searches']?.value;
    check('20 long searches in a 3-byte script are trimmed (newest kept) to what fits, never cut inside a search',
        Array.isArray(queued) && queued.length > 0 && queued.length < 20 && codec.encodedBytes(queued) <= searches.maxBytes && J(queued) === J(cjk(20, 100).slice(0, queued.length)) && queued.every((q) => Array.from(q).length === 100), J((queued || []).length));
}

console.log(failures === 0 ? '\nAll search-history checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
