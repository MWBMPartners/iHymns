/**
 * tests/test-chord-settings-sync.js - the chord style and two-column setting follow the account (PR 2b step 2b-6, #2176)
 *
 * ELI5
 * ----
 * The chord page saves "two columns" as the text "1" and removes it when off. When the account carries that setting, the
 * browser stores what the account says as the text "true". So the page must read BOTH as "on", or a person who turned columns
 * on on their phone would open the laptop and find it off. This checks the reader, the two chord settings' registry entries
 * (they follow the account but NEVER travel in the shared cookie), and that the real profile sync turns an account value into
 * the right stored text and back.
 *
 * Detailed (rule #34): the entries come from the real `php tools/dump-profile-schema.php`. The page's reader is called with
 * every stored shape; transpose.js is read to prove it uses that reader and does not compare against "1" itself any more. The
 * sync part runs the real ProfileSync in jsdom: an account value arrives, is stored, and a local toggle becomes a queued change.
 * Mutation proof (in the commit): put the strict `=== '1'` back in transpose.js and this goes red.
 *
 * Usage: node tests/test-chord-settings-sync.js
 */
import fs from 'node:fs';
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

const { chordColumnsStoredOn } = await import(url(path.join(JS, 'utils', 'chord-notation.js')));
const C = await import(url(path.join(JS, 'constants.js')));
const schema = JSON.parse(execFileSync('php', [path.join(REPO, 'tools', 'dump-profile-schema.php')], { encoding: 'utf8' }));
const entry = (id) => schema.entries.find((e) => e.id === id);

console.log('\n1 - the reader\n');
check('"1" (saved on this device) is on', chordColumnsStoredOn('1') === true);
check('"true" (arrived from the account) is on', chordColumnsStoredOn('true') === true);
check('nothing, "false", "0" and anything else are off', [null, undefined, '', 'false', '0', 'yes', 'TRUE', ' 1'].every((v) => chordColumnsStoredOn(v) === false));
const transposeSrc = fs.readFileSync(path.join(JS, 'modules', 'transpose.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
check('transpose.js reads the setting through that reader and no longer compares against "1" itself', /chordColumnsStoredOn\(\s*localStorage\.getItem\(STORAGE_CHORD_COLUMNS\)\s*\)/.test(transposeSrc) && !/getItem\(STORAGE_CHORD_COLUMNS\)\s*===\s*'1'/.test(transposeSrc));

console.log('\n2 - the registry entries\n');
const notation = entry('chords.notation'); const columns = entry('chords.columns');
check('chord notation: an account setting that syncs, with its old web key, never in the shared cookie', notation && notation.scope === 'account' && notation.sync === 'profile_sync' && notation.legacyWebKey === C.STORAGE_CHORD_NOTATION && notation.cookie === false);
check('chord columns: the same, a true/false value', columns && columns.type === 'bool' && columns.sync === 'profile_sync' && columns.legacyWebKey === C.STORAGE_CHORD_COLUMNS && columns.cookie === false);
check('neither is in the list of settings the cookie may carry', !(await import(url(path.join(JS, 'modules', 'settings-sync-registry.js')))).CROSS_SITE_KEYS.some((k) => k === C.STORAGE_CHORD_NOTATION || k === C.STORAGE_CHORD_COLUMNS));

console.log('\n3 - through the real sync\n');
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
const { window } = dom;
Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent });
Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
const rows = [{ section: 'chords', key: 'columns', value: true, deleted: false, version: 1 }, { section: 'chords', key: 'notation', value: 'nashville', deleted: false, version: 2 }];
const posted = [];
globalThis.fetch = async (input, init = {}) => {
    const u = new URL(String(input), 'https://example.test/');
    const mk = (b) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => b, clone() { return { json: async () => b }; } });
    if (u.searchParams.get('action') === 'profile_schema') return mk(schema);
    if ((init.method || 'GET') === 'POST') posted.push(JSON.parse(init.body));
    return mk({ ok: true, cursor: 2, more: false, resetCursor: false, schemaVersion: schema.schemaVersion, changes: rows, rejected: [], applied: 0 });
};
const { ProfileSync } = await import(url(path.join(JS, 'modules', 'profile-sync.js')) + '?t=' + Math.random());
const app = { config: { apiUrl: '/api', profileSync: { enabled: true } }, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: 1 }) }, syncStorage(k, o) { sync.recordLocalChange(k, o || {}); } };
const sync = new ProfileSync(app, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
await sync.ensureSchema();
await sync.pull();
check('an account value of true is stored as the text "true", and the page reads it as on', localStorage.getItem(C.STORAGE_CHORD_COLUMNS) === 'true' && chordColumnsStoredOn(localStorage.getItem(C.STORAGE_CHORD_COLUMNS)));
check('the chord style from the account is stored as it is', localStorage.getItem(C.STORAGE_CHORD_NOTATION) === 'nashville');
check('neither was queued for sending back', sync.pendingCount() === 0);
localStorage.removeItem(C.STORAGE_CHORD_COLUMNS);          /* the person turns columns off here (the page removes the key) */
app.syncStorage(C.STORAGE_CHORD_COLUMNS);
check('turning columns off here queues a delete', sync.getOutbox().items['chords.columns']?.op === 'delete');
localStorage.setItem(C.STORAGE_CHORD_COLUMNS, '1');        /* turning it on here, as the page saves it */
app.syncStorage(C.STORAGE_CHORD_COLUMNS);
check('turning it on here (saved as "1") queues the real value true', sync.getOutbox().items['chords.columns']?.op === 'set' && sync.getOutbox().items['chords.columns'].value === true);
localStorage.setItem(C.STORAGE_CHORD_NOTATION, 'solfege');
app.syncStorage(C.STORAGE_CHORD_NOTATION);
await sync.push();
check('a push sends them as real values', posted.length === 1 && posted[0].changes.some((c) => c.section === 'chords' && c.key === 'columns' && c.value === true) && posted[0].changes.some((c) => c.key === 'notation' && c.value === 'solfege'));

console.log(failures === 0 ? '\nAll chord-setting checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
