/**
 * tests/test-import-through-stores.js - importing a backup goes through the favourites and set-list stores
 * (account/profile rewrite PR 2a, step 2a-5, epic #2180)
 *
 * ELI5
 * ----
 * Settings has an "Import data" button that reads a backup file. It used to write the browser's
 * storage directly, which skipped the account sync completely: choose "Replace existing" and the
 * favourites and set lists that the import removed were never reported as removed, so they came
 * back from the account the next time the person signed in. Now the import hands the new lists to
 * the real Favorites and SetList stores, which record every item the import removed as a deliberate
 * removal (matched by id) and every new item as a deliberate add, and which save and schedule the
 * sync themselves.
 *
 * This test drives the REAL Settings, Favorites and SetList classes in jsdom with a fake backup file.
 *
 * MUTATION PROOF (rule #34): the same story against a throwaway sibling of settings.js in which the
 * import writes straight to storage again. The removals are then NOT queued, proving the test can fail.
 *
 * Usage: node tests/test-import-through-stores.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const JS = path.join(__dirname, '..', 'appWeb', 'public_html', 'js');
const MODULES = path.join(JS, 'modules');
const SETTINGS = path.join(MODULES, 'settings.js');
const url = (p) => pathToFileURL(p).href;
const C = await import(url(path.join(JS, 'constants.js')));

let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + detail));
    if (!cond) failures++;
};
const J = (x) => JSON.stringify(x);

async function play(settingsPath, choice) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event, requestAnimationFrame: (fn) => setTimeout(fn, 0) });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true, serviceWorker: undefined }, configurable: true, writable: true });
    const { Settings } = await import(url(settingsPath) + '?t=' + Date.now() + Math.random());
    const { Favorites } = await import(url(path.join(MODULES, 'favorites.js')) + '?t=' + Date.now() + Math.random());
    const { SetList } = await import(url(path.join(MODULES, 'setlist.js')) + '?t=' + Date.now() + Math.random());
    const synced = [];
    const app = { config: { apiUrl: '/api' }, syncStorage: (k) => synced.push(k), showToast() {}, showChoice: async () => choice, showConfirm: async () => true };
    app.favorites = new Favorites(app);
    app.setList = new SetList(app);
    const settings = new Settings(app);

    /* This device already holds F1, F2 and the set lists L1, L2. */
    localStorage.setItem(C.STORAGE_FAVORITES, J([{ id: 'CP-1', tags: [] }, { id: 'CP-2', tags: [] }]));
    localStorage.setItem(C.STORAGE_SETLISTS, J([{ id: 'L1', name: 'One', songs: [] }, { id: 'L2', name: 'Two', songs: [] }]));
    const backup = { app: 'iHymns', version: 1, favorites: [{ id: 'CP-2', tags: [] }, { id: 'CP-3', tags: [] }], setlists: [{ id: 'L2', name: 'Two', songs: [] }, { id: 'L3', name: 'Three', songs: [] }], history: [] };
    await settings.importUserData({ text: async () => J(backup) });
    return {
        favs: JSON.parse(localStorage.getItem(C.STORAGE_FAVORITES)).map((f) => f.id).sort(),
        lists: JSON.parse(localStorage.getItem(C.STORAGE_SETLISTS)).map((l) => l.id).sort(),
        pendingFavDeletes: app.favorites.getPendingDeletes(),
        pendingFavAdds: app.favorites.getPendingAdds(),
        pendingListDeletes: app.setList.getPendingDeletes(),
        synced,
    };
}

console.log('\n"Replace existing"\n');
const r = await play(SETTINGS, 'option1');
check('the favourites become exactly the backup\'s', J(r.favs) === '["CP-2","CP-3"]', J(r.favs));
check('the set lists become exactly the backup\'s', J(r.lists) === '["L2","L3"]', J(r.lists));
check('the favourite the import removed (CP-1) is queued as a deliberate removal', J(r.pendingFavDeletes) === '["CP-1"]', J(r.pendingFavDeletes));
check('the favourite the import added (CP-3) is queued as a deliberate add', J(r.pendingFavAdds) === '["CP-3"]', J(r.pendingFavAdds));
check('a favourite in both (CP-2) is left alone', !r.pendingFavDeletes.includes('CP-2') && !r.pendingFavAdds.includes('CP-2'));
check('the set list the import removed (L1) is queued as a deletion, matched by id', J(r.pendingListDeletes) === '["L1"]', J(r.pendingListDeletes));
check('the stores told the cookie sync about both keys (saveAll did it)', r.synced.includes(C.STORAGE_FAVORITES) && r.synced.includes(C.STORAGE_SETLISTS));

console.log('\n"Merge with existing"\n');
const m = await play(SETTINGS, 'option2');
check('merge keeps what was there and adds what is new', J(m.favs) === '["CP-1","CP-2","CP-3"]' && J(m.lists) === '["L1","L2","L3"]', J([m.favs, m.lists]));
check('merge removes nothing, so nothing is queued as a removal', m.pendingFavDeletes.length === 0 && m.pendingListDeletes.length === 0);
check('merge queues only the genuinely new favourite as an add', J(m.pendingFavAdds) === '["CP-3"]', J(m.pendingFavAdds));

console.log('\nmutation proof\n');
let mutant = null;
try {
    const src = fs.readFileSync(SETTINGS, 'utf8');
    const from = "favStore?._recordDelete(before.map(idOf).filter((id) => id && !keep.has(id)));";
    if (!src.includes(from)) throw new Error('mutation setup: line not found; update this test');
    mutant = path.join(MODULES, `zz_ihymns_test_settings_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
    fs.writeFileSync(mutant, src.replace(from, '/* MUTATED: removals not queued */'));
    const bad = await play(mutant, 'option1');
    check('with the removal queueing taken out, CP-1 is NOT queued (the test can fail)', bad.pendingFavDeletes.length === 0);
} finally {
    if (mutant) fs.rmSync(mutant, { force: true });
}
let mutant2 = null;
try {
    const src = fs.readFileSync(SETTINGS, 'utf8');
    const from = "if (id && !keep.has(id)) listStore?._queuePendingDelete(id);";
    if (!src.includes(from)) throw new Error('mutation setup: line not found; update this test');
    mutant2 = path.join(MODULES, `zz_ihymns_test_settings_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
    fs.writeFileSync(mutant2, src.replace(from, '/* MUTATED: set-list removals not queued */'));
    const bad = await play(mutant2, 'option1');
    check('with the set-list removal queueing taken out, L1 is NOT queued (the test can fail)', bad.pendingListDeletes.length === 0);
} finally {
    if (mutant2) fs.rmSync(mutant2, { force: true });
}

console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) FAILED.`);
process.exit(failures === 0 ? 0 : 1);
