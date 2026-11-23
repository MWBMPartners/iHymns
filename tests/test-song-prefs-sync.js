/**
 * tests/test-song-prefs-sync.js - the key, capo and octave chosen for each song follow the account (PR 2b step 2b-6, #2177)
 *
 * ELI5
 * ----
 * A song's key, capo and octave are saved in the browser as three little notes per song. On the account they are ONE item per
 * song, so two devices changing two different songs never fight. This checks the translator between the two shapes, that the
 * stepper buttons tell the sync (and only when something really changed), that an item from the account is written back as the
 * three notes (zero meaning "no note"), that all-zero means "delete", that a bad value or a bad song id is never sent, and that
 * every write of any of the three notes in the web code tells the sync.
 *
 * Detailed (rule #34): the entry (key pattern, ranges, the 2,000 cap) comes from the real `php tools/dump-profile-schema.php`.
 * The "every write tells the sync" check is DERIVED from the tree: every `localStorage.setItem/removeItem` whose key is built
 * from one of the three prefix constants (directly or through a property or local name), in any file under js/, must be followed
 * within a few lines by syncStorage - the same shape of guard as tests/test-settings-sync-cookie.js. Mutation proofs (in the
 * commit): remove the syncStorage call from _saveSongPref; make the provider treat all-zero as a set instead of absent.
 *
 * Usage: node tests/test-song-prefs-sync.js
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
const J = JSON.stringify;

const schema = JSON.parse(execFileSync('php', [path.join(REPO, 'tools', 'dump-profile-schema.php')], { encoding: 'utf8' }));
const e = schema.entries.find((x) => x.id === 'songPrefs.*');
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
const { window } = dom;
Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event });
Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
const C = await import(url(path.join(JS, 'constants.js')));
const { buildProviders, ADAPTERS, SONG_PREF_PREFIXES } = await import(url(path.join(JS, 'modules', 'profile-sync-adapters.js')));
const { Transpose } = await import(url(path.join(JS, 'modules', 'transpose.js')));

console.log('\n1 - the registry entry\n');
check('a map, 2,000 songs at most, one item per song, an account setting that syncs, not claimed, kept at sign-out, not in the cookie',
    e && e.type === 'map' && e.maxItems === 2000 && e.scope === 'account' && e.sync === 'profile_sync' && e.claim === false && e.clearAtSignOut === false && e.cookie === false && !e.legacyWebKey);
check('the web has a hand-written provider for it', Object.prototype.hasOwnProperty.call(ADAPTERS, 'songPrefs.*'));
check('the three prefixes are the page\'s own constants', J(Object.values(SONG_PREF_PREFIXES)) === J([C.STORAGE_TRANSPOSE_PREFIX, C.STORAGE_CAPO_PREFIX, C.STORAGE_OCTAVE_PREFIX]));

console.log('\n2 - the translator\n');
const p = buildProviders(schema, localStorage).forPair('songPrefs', 'MP-1008');
check('a song id is found under all three prefixes, and an unrelated or malformed key is not',
    p && p.isMap && p.matchKey('ihymns_transpose_MP-1008') === 'MP-1008' && p.matchKey('ihymns_capo_MP-1008') === 'MP-1008' && p.matchKey('ihymns_octave_MP-1008') === 'MP-1008'
    && p.matchKey('ihymns_transpose_not a song') === null && p.matchKey('ihymns_theme') === null && p.matchKey('ihymns_transpose_') === null);
localStorage.clear();
check('with no notes the song has no choice (absent: a delete, not a set)', p.read('MP-1008').state === 'absent');
localStorage.setItem('ihymns_transpose_MP-1008', '-3'); localStorage.setItem('ihymns_capo_MP-1008', '2');
check('the three notes are read together as one item (a missing one is zero)', J(p.read('MP-1008')) === J({ state: 'value', value: { transpose: -3, capo: 2, octave: 0 } }));
localStorage.setItem('ihymns_capo_MP-1008', '15');
check('a note outside the allowed range is invalid, never sent, never a delete', p.read('MP-1008').state === 'invalid');
localStorage.setItem('ihymns_capo_MP-1008', 'junk');
check('text that is not a number reads as zero, as the page does', J(p.read('MP-1008').value) === J({ transpose: -3, capo: 0, octave: 0 }));
const changed = p.write('MP-1008', { transpose: 0, capo: 4, octave: -1 });
check('writing an item from the account stores each non-zero note and removes the zero one', localStorage.getItem('ihymns_transpose_MP-1008') === null && localStorage.getItem('ihymns_capo_MP-1008') === '4' && localStorage.getItem('ihymns_octave_MP-1008') === '-1');
check('...and reports exactly the storage keys it changed', changed.sort().join() === ['ihymns_capo_MP-1008', 'ihymns_octave_MP-1008', 'ihymns_transpose_MP-1008'].sort().join());
check('writing the same item again changes nothing', p.write('MP-1008', { transpose: 0, capo: 4, octave: -1 }).length === 0);
check('a value outside the rules is refused by the writer too', p.write('MP-1008', { transpose: 99 }).length === 0 && localStorage.getItem('ihymns_capo_MP-1008') === '4');
check('removing takes all three notes away', p.remove('MP-1008').length === 2 && p.read('MP-1008').state === 'absent');
localStorage.setItem('ihymns_capo_CP-1', '1'); localStorage.setItem('ihymns_octave_MP-2', '1'); localStorage.setItem('ihymns_capo_bad id', '1');
check('every song that has a note is listed (once), and a malformed id is not', J(p.localItems().map((x) => x.item).sort()) === J(['CP-1', 'MP-2']));
localStorage.clear();

console.log('\n3 - the stepper buttons and the sync\n');
const posted = []; let rows = []; let cursor = 0;
globalThis.fetch = async (input, init = {}) => {
    const u = new URL(String(input), 'https://example.test/');
    const mk = (b) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => b, clone() { return { json: async () => b }; } });
    if (u.searchParams.get('action') === 'profile_schema') return mk(schema);
    if ((init.method || 'GET') === 'POST') posted.push(JSON.parse(init.body));
    return mk({ ok: true, cursor, more: false, resetCursor: false, schemaVersion: schema.schemaVersion, changes: rows, rejected: [], applied: 0 });
};
const { ProfileSync } = await import(url(path.join(JS, 'modules', 'profile-sync.js')) + '?t=' + Math.random());
let sync;
const app = { config: { apiUrl: '/api', profileSync: { enabled: true } }, userAuth: { isLoggedIn: () => true, getUser: () => ({ id: 1 }) }, syncStorage(k, o) { sync.recordLocalChange(k, o || {}); } };
sync = new ProfileSync(app, { storage: window.localStorage, setTimer: () => 1, clearTimer: () => {} });
await sync.ensureSchema();
const page = Object.create(Transpose.prototype);
page.app = app; page.storagePrefix = C.STORAGE_TRANSPOSE_PREFIX; page.capoStoragePrefix = C.STORAGE_CAPO_PREFIX; page.octaveStoragePrefix = C.STORAGE_OCTAVE_PREFIX;
/* a stepper click saves all three, as updateDisplay() does */
page.saveOffset('MP-5', 2); page.saveCapo('MP-5', 0); page.saveOctave('MP-5', 0);
check('a click that changed the key queues ONE item for the song, holding all three values', J(Object.keys(sync.getOutbox().items)) === '["songPrefs.MP-5"]' && J(sync.getOutbox().items['songPrefs.MP-5'].value) === J({ transpose: 2, capo: 0, octave: 0 }));
await sync.push();
check('it is sent as a real item and the queue empties', J(posted[0]?.changes) === J([{ section: 'songPrefs', key: 'MP-5', op: 'set', value: { transpose: 2, capo: 0, octave: 0 } }]) && sync.pendingCount() === 0);
page.saveOffset('MP-5', 2); page.saveCapo('MP-5', 0); page.saveOctave('MP-5', 0);
check('saving the same values again queues nothing (the notes did not change)', sync.pendingCount() === 0);
page.saveCapo('MP-5', 3);
check('changing only the capo queues the whole item with the key it already had', J(sync.getOutbox().items['songPrefs.MP-5'].value) === J({ transpose: 2, capo: 3, octave: 0 }));
page.saveOffset('MP-6', 1);
check('two songs are two separate items', J(Object.keys(sync.getOutbox().items).sort()) === '["songPrefs.MP-5","songPrefs.MP-6"]');
await sync.push();
page.saveOffset('MP-5', 0); page.saveCapo('MP-5', 0); page.saveOctave('MP-5', 0);
check('putting all three back to zero queues a DELETE for the song', sync.getOutbox().items['songPrefs.MP-5']?.op === 'delete');
await sync.push();

console.log('\n4 - an item arriving from the account\n');
rows = [{ section: 'songPrefs', key: 'CP-9', value: { transpose: 5, capo: 0, octave: 1 }, deleted: false, version: 7 }, { section: 'songPrefs', key: 'MP-6', value: null, deleted: true, version: 8 }]; cursor = 8;
await sync.pull();
check('an item from the account becomes the three notes, with the zero one absent', localStorage.getItem('ihymns_transpose_CP-9') === '5' && localStorage.getItem('ihymns_octave_CP-9') === '1' && localStorage.getItem('ihymns_capo_CP-9') === null);
check('a deletion from the account removes the song\'s notes', localStorage.getItem('ihymns_transpose_MP-6') === null);
check('neither is sent back', sync.pendingCount() === 0);

console.log('\n4b - one letter case for a song id, so mp-1 and MP-1 can never be two items\n');
{
    localStorage.clear();
    const pp = buildProviders(schema, localStorage).forPair('songPrefs', 'MP-9');
    check('a storage key in lower case still names the song, in the canonical (upper) case', pp.matchKey('ihymns_capo_mp-9') === 'MP-9' && pp.matchKey('ihymns_transpose_Mp-9') === 'MP-9');
    localStorage.setItem('ihymns_capo_mp-9', '3');
    check('a note kept under a lower-case key is read as the song\'s note (not as "no choice", which would send a delete)', J(pp.read('MP-9')) === J({ state: 'value', value: { transpose: 0, capo: 3, octave: 0 } }));
    check('the song is listed once, under the canonical case', J(pp.localItems().map((x) => x.item)) === '["MP-9"]');
    localStorage.setItem('ihymns_transpose_MP-9', '2');
    sync.recordLocalChange('ihymns_capo_mp-9'); sync.recordLocalChange('ihymns_transpose_MP-9');
    check('changes under either case queue ONE item, keyed in upper case', J(Object.keys(sync.getOutbox().items).filter((k) => /9$/i.test(k))) === '["songPrefs.MP-9"]' && J(sync.getOutbox().items['songPrefs.MP-9'].value) === J({ transpose: 2, capo: 3, octave: 0 }));
    pp.write('MP-9', { transpose: 2, capo: 5, octave: 0 });
    check('writing from the account leaves ONE copy of each note (the lower-case twin is removed)', localStorage.getItem('ihymns_capo_MP-9') === '5' && localStorage.getItem('ihymns_capo_mp-9') === null);
    localStorage.setItem('ihymns_octave_mp-9', '1');
    pp.remove('MP-9');
    check('removing takes the notes under every case away', ['ihymns_transpose_MP-9', 'ihymns_capo_MP-9', 'ihymns_octave_mp-9', 'ihymns_octave_MP-9'].every((k) => localStorage.getItem(k) === null));
    localStorage.clear();
}

console.log('\n5 - every write of a per-song note tells the sync (derived from the tree)\n');
{
    const stripJs = (s) => s.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' ')).replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1');
    const prefixNames = new Set(['STORAGE_TRANSPOSE_PREFIX', 'STORAGE_CAPO_PREFIX', 'STORAGE_OCTAVE_PREFIX']);
    const files = execFileSync('git', ['ls-files', '-z', '--', 'appWeb/public_html/js'], { cwd: REPO, encoding: 'utf8' }).split('\0').filter((f) => f.endsWith('.js') && !/profile-sync(-adapters)?\.js$/.test(f));
    const offenders = []; let writes = 0;
    for (const f of files) {
        const text = stripJs(fs.readFileSync(path.join(REPO, f), 'utf8'));
        if (![...prefixNames].some((n) => text.includes(n))) continue;
        /* Any file that knows one of the three prefixes: EVERY direct storage write in it must tell the sync (the key is often built
           in a local name, `const key = prefix + songId`, so tracing the name is not reliable; being strict about the whole file is). */
        const lines = text.split('\n');
        lines.forEach((line, i) => {
            if (!/localStorage\.(?:setItem|removeItem)\(/.test(line)) return;
            writes++;
            if (!/syncStorage\??\.?\(/.test(lines.slice(i, i + 12).join('\n'))) offenders.push(f.replace('appWeb/public_html/', '') + ':' + (i + 1));
        });
    }
    check('the scan found the real writes (' + writes + ')', writes >= 5, 'the pattern no longer matches the code, so this check would pass while checking nothing');
    check('every one is followed, within a few lines, by syncStorage', offenders.length === 0, offenders.join(', '));
}

console.log(failures === 0 ? '\nAll per-song checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
