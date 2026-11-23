/**
 * tests/test-profile-web-codec-lockstep.js - the browser and the server translate a setting the same way
 * (PR 2b step 2b-4, epic #2180; rule #35: two files that must agree get a mechanism, not a comment)
 *
 * ELI5
 * ----
 * The browser keeps settings as words ("true", "18", "{...}"); the account keeps real values. Both the
 * browser (js/modules/profile-web-codec.js) and the server (profileWebDecode / profileWebEncode in
 * includes/profile_sync.php) translate between them. If they ever disagree, a setting changes meaning on
 * its way across. This test feeds the SAME words to both and fails the moment their answers differ.
 *
 * Detailed (rule #34): nothing is typed that the registry could say. The entries come from the real
 * `php tools/dump-profile-schema.php`; the samples for each are built from the entry's own rules (every
 * allowed value, each edge just inside and just outside, a wrong type, the text forms "1" / "0" / "TRUE");
 * the server's side is the real PHP run through `php -r`. For every sample the two must agree on (1) valid or
 * not, (2) the decoded value, and (3) the text written back. The entry set is every account entry that
 * can travel through the old settings shelf or the web's own storage (a web storage key). The settings page
 * namespace is also compared across the two languages.
 * Mutation proof (recorded in the commit): make the JS read "1" as false -> red; make PHP write "yes" -> red.
 *
 * Usage: node tests/test-profile-web-codec-lockstep.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 600) : ''));
}

const codec = await import(path.join(PUB, 'js', 'modules', 'profile-web-codec.js'));
const dump = JSON.parse(execFileSync('php', [path.join(REPO, 'tools', 'dump-profile-schema.php')], { encoding: 'utf8' }));
const entries = dump.entries.filter((e) => e.sync === 'profile_sync' && e.scope === 'account' && typeof e.legacyWebKey === 'string' && e.legacyWebKey !== '');
check('the registry gave the entries that have a web storage key (' + entries.length + ')', entries.length >= 15);

function samplesFor(e) {
    const s = new Set(['', ' ', 'zz-not-a-value', 'null', '{}', '[]', 'true', 'false', '1', '0', 'TRUE', 'yes', ' true', '2.5', '-1', '1e3', '0x10']);
    switch (e.type) {
        case 'enum': e.allowed.forEach((a) => { s.add(a); s.add(a + 'x'); s.add(a.toUpperCase()); }); break;
        case 'int': {
            const step = e.step || 1;
            [e.min, e.max, e.min + step, e.min - step, e.max + step, e.min - 1, e.max + 1, e.min + 1, e.max - 1].forEach((n) => s.add(String(n)));
            s.add('007'); s.add('1.0'); s.add('-0'); s.add('99999999999');
            break;
        }
        case 'float':
            [e.min, e.max, (e.min + e.max) / 2, e.min - 1, e.max + 1].forEach((n) => { s.add(String(n)); s.add(Number(n).toFixed(1)); });
            break;
        case 'string':
            ['MP', 'a1', 'MP-1', 'toolongsongbookname', 'a b', 'MP\n'].forEach((x) => s.add(x));
            break;
        case 'list':
            ['["MP","SDAH"]', '["MP-1"]', '[1]', '[]', '{"a":1}', JSON.stringify(Array(25).fill('MP')), JSON.stringify(['x'.repeat(101)]), '["café"]'].forEach((x) => s.add(x));
            break;
        case 'json': {
            const keys = Object.keys(e.shape || {});
            ['{}', '{"mystery":1}', '[1]', '"x"'].forEach((x) => s.add(x));
            for (const k of keys) {
                const sp = e.shape[k];
                if (sp.type === 'bool') { s.add(JSON.stringify({ [k]: true })); s.add(JSON.stringify({ [k]: 'true' })); }
                if (sp.type === 'enum') { s.add(JSON.stringify({ [k]: sp.allowed[0] })); s.add(JSON.stringify({ [k]: 'zz' })); }
                if (sp.type === 'float' || sp.type === 'int') {
                    s.add(JSON.stringify({ [k]: sp.min })); s.add(JSON.stringify({ [k]: sp.max })); s.add(JSON.stringify({ [k]: sp.max + 1 })); s.add(JSON.stringify({ [k]: (sp.min + sp.max) / 2 }));
                }
            }
            break;
        }
    }
    return [...s];
}

const rows = [];
for (const e of entries) for (const t of samplesFor(e)) rows.push([e.id, t]);

const script = `
    $_SERVER['SCRIPT_FILENAME'] = 't';
    require ${JSON.stringify(path.join(PUB, 'includes', 'profile_sync.php'))};
    $in = json_decode(stream_get_contents(STDIN), true);
    $out = [];
    foreach ($in as [$id, $text]) {
        [$section, $key] = explode('.', $id, 2);
        $e = profileRegistryEntry($section, $key);
        $v = profileWebDecode($e, $text);
        $out[] = $v === null ? ['ok' => false] : ['ok' => true, 'v' => $v, 'enc' => profileWebEncode($e, $v)];
    }
    echo json_encode($out);
`;
const php = JSON.parse(execFileSync('php', ['-r', script], { input: JSON.stringify(rows), encoding: 'utf8', maxBuffer: 1 << 26 }));

/* Compare values in a way that treats 2 and 2.0 as the same (JSON has one number type in JavaScript). */
const canon = (v) => JSON.stringify(v, (_k, x) => (x && typeof x === 'object' && !Array.isArray(x)) ? Object.fromEntries(Object.entries(x).sort(([a], [b]) => (a < b ? -1 : 1))) : x);
/* PHP cannot tell an empty object from an empty list once decoded (both are []), so an empty PHP value for an object entry is compared as {}. */
const phpValue = (entry, v) => (entry.type === 'json' && Array.isArray(v) && v.length === 0) ? {} : v;
const byId = new Map(entries.map((e) => [e.id, e]));
const diffs = [];
let accepted = 0, refused = 0;
rows.forEach(([id, text], i) => {
    const entry = byId.get(id);
    const js = codec.decodeWebValue(entry, text);
    const jsOk = js !== undefined;
    const p = php[i];
    if (jsOk !== p.ok) { diffs.push(`${id} ${JSON.stringify(text)}: valid js=${jsOk} php=${p.ok}`); return; }
    if (!jsOk) { refused++; return; }
    accepted++;
    if (canon(js) !== canon(phpValue(entry, p.v))) { diffs.push(`${id} ${JSON.stringify(text)}: value js=${canon(js)} php=${canon(phpValue(entry, p.v))}`); return; }
    const jsEnc = codec.encodeWebValue(entry, js);
    if (jsEnc !== p.enc) diffs.push(`${id} ${JSON.stringify(text)}: text written back js=${jsEnc} php=${p.enc}`);
});
check('the browser and the server agree on every sample: valid or not, the value, and the text written back (' + rows.length + ' samples)', diffs.length === 0, diffs.slice(0, 6).join(' | '));
check('the samples include both accepted and refused values (the check is not vacuous): ' + accepted + ' accepted, ' + refused + ' refused', accepted > 40 && refused > 100);
check('every entry had at least one accepted sample (no entry was only ever refused)', entries.every((e) => rows.some(([id, t], i) => id === e.id && php[i].ok)));

/* A written value reads back to itself (a round trip) in the browser's own codec. */
const roundTrip = [];
rows.forEach(([id, text], i) => {
    if (!php[i].ok) return;
    const entry = byId.get(id);
    const v = codec.decodeWebValue(entry, text);
    const back = codec.decodeWebValue(entry, codec.encodeWebValue(entry, v));
    if (canon(back) !== canon(v)) roundTrip.push(id + ' ' + JSON.stringify(text));
});
check('write then read gives the same value back for every accepted sample', roundTrip.length === 0, roundTrip.join(' | '));

/* The two sides of the old settings shelf name the same shelf. */
const settingsJs = fs.readFileSync(path.join(PUB, 'js', 'modules', 'settings.js'), 'utf8');
const phpSrc = fs.readFileSync(path.join(PUB, 'includes', 'profile_sync.php'), 'utf8');
const jsNs = /const USER_SETTINGS_NAMESPACE = '([^']+)'/.exec(settingsJs)?.[1];
const phpNs = /const PROFILE_SYNC_WEB_NAMESPACE = '([^']+)'/.exec(phpSrc)?.[1];
check('the old settings shelf has the same name in settings.js and in profile_sync.php (' + jsNs + ')', !!jsNs && jsNs === phpNs);

console.log(failures === 0 ? '\nAll codec lockstep checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
