/**
 * tests/test-profile-registry-lockstep.js - the server's list of personal settings, the web's list
 * and the Apple snapshot agree (C6, epic #2180)
 *
 * ELI5
 * ----
 * There are now three copies of "which settings follow a person and what values
 * are allowed": the server's registry (PHP), the web's list of settings that may
 * travel in the shared cookie (JavaScript), and a snapshot inside the Apple code.
 * Three lists that must agree, with nothing checking them, is the exact thing that
 * keeps breaking (rule #35). This test dumps the server's list, loads the web's,
 * and fails the moment they differ; and it checks the Apple snapshot is exactly
 * what the dump tool prints.
 *
 * Detailed (rule #34): nothing is typed here. Every comparison is derived from the
 * two files themselves:
 *   1. The cookie keys. The web's CROSS_SITE_KEYS must be exactly the server
 *      entries marked `cookie: true`, matched through their old web storage key.
 *   2. The allowed values. Enum values, number ranges and steps, and the
 *      songbook pattern are compared, and for every cookie entry a set of sample
 *      values (each allowed value, just inside and just outside every range, a
 *      wrong type) is judged by BOTH the web validator and the server's rules; they
 *      must give the same verdict on every sample.
 *   3. The snapshot. appApple/.../Resources/profile-schema.json must equal, byte
 *      for byte, what `php tools/dump-profile-schema.php` prints.
 *
 * Mutation proofs are recorded in the C6 commit: a theme value added only in PHP,
 * and a hand edit to the snapshot, each turn this red.
 *
 * Usage: node tests/test-profile-registry-lockstep.js
 *
 * @see appWeb/public_html/includes/profile_registry.php
 * @see appWeb/public_html/js/modules/settings-sync-registry.js
 * @see tools/dump-profile-schema.php
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');
const SNAPSHOT = path.join(REPO, 'appApple', 'Packages', 'iHymnsKit', 'Sources', 'IHModels', 'Resources', 'profile-schema.json');

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 400) : ''));
}

/* ---- the server's list, from the real tool ---- */
const dumpText = execFileSync('php', [path.join(REPO, 'tools', 'dump-profile-schema.php')], { encoding: 'utf8' });
const dump = JSON.parse(dumpText);
check('the dump tool printed the registry (' + dump.entries.length + ' entries)', dump.entries.length >= 30 && dump.schemaVersion >= 1);

/* ---- the web's list ---- */
const web = await import(path.join(PUB, 'js', 'modules', 'settings-sync-registry.js'));
const webKeys = [...web.CROSS_SITE_KEYS];

console.log('\n1 - the cookie keys');
const cookieEntries = dump.entries.filter((e) => e.cookie === true);
const cookieLegacy = cookieEntries.map((e) => e.legacyWebKey).sort();
check('every cookie entry has an old web key', cookieEntries.every((e) => typeof e.legacyWebKey === 'string' && e.legacyWebKey !== ''));
check('the web list has exactly the keys the server marks cookie:true',
    JSON.stringify([...webKeys].sort()) === JSON.stringify(cookieLegacy),
    'only in web: ' + webKeys.filter((k) => !cookieLegacy.includes(k)).join(',') + ' | only on server: ' + cookieLegacy.filter((k) => !webKeys.includes(k)).join(','));
check('no non-cookie web entry is in the web cookie list',
    dump.entries.filter((e) => e.cookie !== true && e.legacyWebKey).every((e) => !webKeys.includes(e.legacyWebKey)));
check('nothing of scope device, or in the content section, is a cookie entry',
    cookieEntries.every((e) => e.scope === 'account' && e.section !== 'content' && e.section !== 'device'));

console.log('\n2 - the allowed values');
const byLegacy = new Map(cookieEntries.map((e) => [e.legacyWebKey, e]));
const entry = (id) => dump.entries.find((e) => e.id === id);
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

check('theme values are equal', same(web.THEME_VALUES, entry('appearance.theme').allowed), JSON.stringify(web.THEME_VALUES) + ' vs ' + JSON.stringify(entry('appearance.theme').allowed));
check('transition values are equal', same(web.TRANSITION_VALUES, entry('appearance.transition').allowed));
check('colour-vision values are equal', same(web.CVD_VALUES, entry('appearance.cvd').allowed));
const fs_ = entry('appearance.fontSize');
check('text size range and step are equal', fs_.min === web.FONT_SIZE_RANGE.min && fs_.max === web.FONT_SIZE_RANGE.max && fs_.step === web.FONT_SIZE_RANGE.step && fs_.from === web.FONT_SIZE_RANGE.min);
const idle = entry('live.idleTimeoutMins');
check('idle timeout range is equal', idle.min === web.IDLE_TIMEOUT_RANGE.min && idle.max === web.IDLE_TIMEOUT_RANGE.max);
const disp = entry('reading.display');
check('display preferences: the same five keys', same(Object.keys(disp.shape).sort(), [...web.DISPLAY_KEYS].sort()));
check('display preferences: the same line-spacing values', same(disp.shape.lineSpacing.allowed, web.DISPLAY_LINE_SPACING_VALUES));
check('link emphasis allows only "on"', same(entry('appearance.linkEmphasis').allowed, ['on']));

/* Behavioural lockstep: build samples from each server entry and compare the verdicts. */
function samplesFor(e) {
    const good = [], bad = ['', 'zz-not-a-value', '{}', 'null'];
    switch (e.type) {
        case 'bool': good.push('true', 'false'); bad.push('yes', '1', 'TRUE'); break;
        case 'enum': good.push(...e.allowed); bad.push(e.allowed[0] + 'x', e.allowed[0].toUpperCase() === e.allowed[0] ? 'x' : e.allowed[0].toUpperCase()); break;
        case 'int': {
            good.push(String(e.min), String(e.max));
            if (e.step > 1) good.push(String(e.min + e.step));
            bad.push(String(e.min - 1), String(e.max + 1), String(e.max + (e.step || 1)), String(e.min - (e.step || 1)), 'abc', '1.5');
            if (e.step > 1) bad.push(String(e.min + 1));
            break;
        }
        case 'string': {
            if (e.pattern) {
                good.push('MP', 'a1');
                bad.push('MP-1', 'toolongsongbook', 'a b');
            }
            break;
        }
        case 'json': {
            good.push('{}', JSON.stringify({ lineSpacing: disp.shape.lineSpacing.allowed[0] }), JSON.stringify({ showVerseNumbers: true }), JSON.stringify({ fontSize: 1.5 }));
            bad.push(JSON.stringify({ mystery: 1 }), JSON.stringify({ lineSpacing: 'zz' }), JSON.stringify({ fontSize: 99 }), '[1]');
            break;
        }
    }
    return { good, bad };
}
/* The PHP side judges the SAME strings: text values are converted to the canonical JSON type first. */
function phpVerdicts(items) {
    const script = `
        require ${JSON.stringify(path.join(PUB, 'includes', 'profile_registry.php'))};
        $in = json_decode(stream_get_contents(STDIN), true);
        $out = [];
        foreach ($in as $row) {
            [$id, $text] = $row;
            [$section, $key] = explode('.', $id, 2);
            $e = profileRegistryEntry($section, $key);
            $v = $text;
            switch ($e['type']) {
                case 'bool': $v = $text === 'true' ? true : ($text === 'false' ? false : $text); break;
                case 'int': $v = preg_match('/^-?\\d+$/', $text) ? (int)$text : $text; break;
                case 'float': $v = is_numeric($text) ? (float)$text : $text; break;
                case 'json': $d = json_decode($text, true); $v = (json_last_error() === JSON_ERROR_NONE && is_array($d)) ? $d : $text; break;
            }
            $out[] = ($text !== '' && profileValidateSpec($e, $v)) ? 1 : 0;
        }
        echo json_encode($out);
    `;
    return JSON.parse(execFileSync('php', ['-r', script], { input: JSON.stringify(items), encoding: 'utf8' }));
}
const rows = [];
for (const e of cookieEntries) {
    const s = samplesFor(e);
    for (const v of s.good) rows.push([e.id, v, e.legacyWebKey]);
    for (const v of s.bad) rows.push([e.id, v, e.legacyWebKey]);
}
const verdictsPhp = phpVerdicts(rows.map((r) => [r[0], r[1]]));
const disagreements = [];
rows.forEach((r, i) => {
    const jsVerdict = web.isShareableSetting(r[2], r[1]) ? 1 : 0;
    if (jsVerdict !== verdictsPhp[i]) disagreements.push(`${r[0]} ${JSON.stringify(r[1])}: web=${jsVerdict} server=${verdictsPhp[i]}`);
});
check('the web and the server give the same verdict on every sample (' + rows.length + ' samples)', disagreements.length === 0, disagreements.join(' | '));
check('the samples include both accepted and refused values (the check is not vacuous)', verdictsPhp.includes(1) && verdictsPhp.includes(0) && rows.length > 60);

console.log('\n2b - sign-out clearing and the shelf kept per person');
/* Every entry the registry says is cleared at sign-out, and that has a web storage key, must be one of the
   keys the device sets aside when a different person signs in (PERSONAL_DATA_KEYS): otherwise the next person
   on a shared browser would see it. Entries with no web key (search history) are handled by their adapter. */
const ownerMod = await import(path.join(PUB, 'js', 'modules', 'account-data-owner.js'));
const clearedWeb = dump.entries.filter((e) => e.clearAtSignOut === true && e.legacyWebKey);
check('some entries are cleared at sign-out and have a web key (the check is not vacuous): ' + clearedWeb.map((e) => e.id).join(', '), clearedWeb.length >= 5);
const notStashed = clearedWeb.filter((e) => !ownerMod.PERSONAL_DATA_KEYS.includes(e.legacyWebKey));
check('every web key cleared at sign-out is in PERSONAL_DATA_KEYS', notStashed.length === 0, notStashed.map((e) => e.id + ' -> ' + e.legacyWebKey).join(', '));
/* Every setting that travels through profile_sync and applies on the web must be reachable in this browser: it names the
   old web storage key it lives under, OR a hand-written provider is registered for it (profile-sync-adapters.js). Otherwise
   a setting the server accepts would silently never reach the browser. And a provider must not name an entry that is gone. */
const adaptersMod = await import(path.join(PUB, 'js', 'modules', 'profile-sync-adapters.js'));
const webSyncing = dump.entries.filter((e) => e.sync === 'profile_sync' && e.scope === 'account' && (e.platforms.includes('all') || e.platforms.includes('web')));
const unreachable = webSyncing.filter((e) => !(typeof e.legacyWebKey === 'string' && e.legacyWebKey !== '') && !adaptersMod.ADAPTER_ENTRY_IDS.includes(e.id));
check('every web profile_sync entry has a web storage key or a hand-written provider (' + webSyncing.length + ' entries)', webSyncing.length >= 20 && unreachable.length === 0, unreachable.map((e) => e.id).join(', '));
/* A key only a hand-written provider knows about (the searches list, the opt-in) is just as personal as one with a web key, and
   nothing above would notice a missing one: it has no registry web key. So the keys the providers declare are checked here. */
const adapterKeysNotStashed = adaptersMod.ADAPTER_STORAGE_KEYS.filter((k) => !ownerMod.PERSONAL_DATA_KEYS.includes(k));
check('every storage key a hand-written provider owns is in PERSONAL_DATA_KEYS (' + adaptersMod.ADAPTER_STORAGE_KEYS.length + ' keys)', adaptersMod.ADAPTER_STORAGE_KEYS.length >= 2 && adapterKeysNotStashed.length === 0, adapterKeysNotStashed.join(', '));
const staleAdapters = adaptersMod.ADAPTER_ENTRY_IDS.filter((id) => !webSyncing.some((e) => e.id === id));
check('every hand-written provider names an entry that exists and syncs on the web', staleAdapters.length === 0 && adaptersMod.ADAPTER_ENTRY_IDS.length >= 3, staleAdapters.join(', '));
const mapOnly = dump.entries.filter((e) => e.type === 'map');
check('every map entry is keyed "*" and has an item rule, a key pattern and a cap (a native client decodes the same fields)',
    mapOnly.length >= 1 && mapOnly.every((e) => e.key === '*' && e.item && typeof e.itemKeyPattern === 'string' && Number.isInteger(e.maxItems)));

console.log('\n3 - the Apple snapshot');
check('the snapshot file exists', fs.existsSync(SNAPSHOT));
const snap = fs.existsSync(SNAPSHOT) ? fs.readFileSync(SNAPSHOT, 'utf8') : '';
check('the snapshot equals, byte for byte, what tools/dump-profile-schema.php prints (regenerate with --write)', snap === dumpText);
check('the snapshot decodes to the same schema version', snap !== '' && JSON.parse(snap).schemaVersion === dump.schemaVersion);
check('the snapshot holds no server-only field', !/"(storage|export|erase)"\s*:/.test(snap));
const pkg = fs.readFileSync(path.join(REPO, 'appApple', 'Packages', 'iHymnsKit', 'Package.swift'), 'utf8');
check('Package.swift excludes the snapshot, so SwiftPM does not warn about an unhandled file (until step I4 bundles it)', pkg.includes('Resources/profile-schema.json'));

console.log(failures === 0 ? '\nAll lockstep checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
