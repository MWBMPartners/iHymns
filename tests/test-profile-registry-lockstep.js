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
 * 4. The Apple mapping (PR 3b, step I4). The Apple app binds five of its own saved preferences to
 *    registry ids (ProfileSyncMapping.swift). Section 4 reads that table from the Swift source
 *    (comments removed, files found from the tree) and checks, against the registry: every id is
 *    a real entry used by Apple, of a kind the binding can carry; every UserDefaults key is one the
 *    app really stores the setting under (IHSettingsStore or an @AppStorage); the app's own
 *    enum words equal the registry's allowed words; the app's default equals the registry default;
 *    every entry the registry says Apple uses and syncs has a binding (otherwise the account would
 *    accept a setting the app silently never applies); and the fields the Swift schema type decodes
 *    all exist in the snapshot.
 *
 * Mutation proofs are recorded in the C6 commit: a theme value added only in PHP,
 * and a hand edit to the snapshot, each turn this red. Section 4 (I4): a changed UserDefaults key,
 * a mistyped registry id, a platform removed from an entry, an enum word renamed in Swift, and the
 * snapshot's bundling removed from Package.swift each turn it red.
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
import { appSwiftFiles, read as readSwift, stripSwift, callArguments } from './lib/swift-source.js';

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
check('Package.swift bundles the snapshot as a resource (step I4), and no longer merely excludes it',
    pkg.includes('.copy("Resources/profile-schema.json")') && !/exclude:\s*\[\s*"Resources\/profile-schema\.json"/.test(pkg));

console.log('\n4 - the Apple mapping (step I4)');
{
    const files = appSwiftFiles();
    const find = (suffix) => files.find((f) => f.endsWith(suffix));
    const mappingFile = find('IHFeatures/ProfileSyncMapping.swift');
    const settingsFile = find('IHFeatures/IHSettingsStore.swift');
    const themeFile = find('IHDesign/IHAppearanceTheme.swift');
    const cvdFile = find('IHDesign/IHCVDMode.swift');
    const schemaFile = find('IHModels/ProfileSchema.swift');
    check('found the mapping, settings store, theme, colour-vision and schema Swift files', !!(mappingFile && settingsFile && themeFile && cvdFile && schemaFile));

    const code = Object.fromEntries(files.map((f) => [f, stripSwift(readSwift(f))]));
    const mapping = code[mappingFile];

    /* The binding table, from the source. */
    const bindings = callArguments(mapping, 'ProfileSettingBinding').map((c) => ({
        id: (/\bid\s*:\s*"([^"]+)"/.exec(c.args) || [])[1],
        key: (/\bdefaultsKey\s*:\s*"([^"]+)"/.exec(c.args) || [])[1],
        kind: (/\bkind\s*:\s*\.([A-Za-z]+)/.exec(c.args) || [])[1],
    })).filter((b) => b.id && b.key && b.kind);
    check('read the binding table (' + bindings.length + ' bindings)', bindings.length >= 5, JSON.stringify(bindings));

    /* The UserDefaults keys the app really stores things under: IHSettingsStore's Keys, and every @AppStorage. */
    const storedKeys = new Set();
    for (const m of code[settingsFile].matchAll(/static\s+let\s+\w+\s*=\s*"([^"]+)"/g)) storedKeys.add(m[1]);
    const appStorageDefaults = new Map();   /* key -> literal default found beside an @AppStorage */
    for (const f of files) {
        for (const m of code[f].matchAll(/@AppStorage\(\s*"([^"]+)"\s*\)[^\n]*?=\s*([^\s\n]+)/g)) { storedKeys.add(m[1]); appStorageDefaults.set(m[1], m[2]); }
    }
    check('collected the keys the app stores settings under (' + storedKeys.size + ')', storedKeys.size >= 8);

    const kindToTypes = { bool: ['bool'], double: ['float'], string: ['enum', 'string'], cvd: ['enum'] };
    const problems = [];
    for (const b of bindings) {
        const e = dump.entries.find((x) => x.id === b.id);
        if (!e) { problems.push(b.id + ' is not a registry entry'); continue; }
        if (!(e.platforms.includes('apple') || e.platforms.includes('all'))) problems.push(b.id + ' is not used by Apple (platforms ' + e.platforms.join(',') + ')');
        if (e.scope !== 'account' || e.sync !== 'profile_sync') problems.push(b.id + ' is not an account profile_sync entry');
        if (!(kindToTypes[b.kind] || []).includes(e.type)) problems.push(b.id + ': the binding kind "' + b.kind + '" cannot carry a registry "' + e.type + '"');
        if (!storedKeys.has(b.key)) problems.push(b.id + ': "' + b.key + '" is not a key the app stores a setting under');
    }
    check('every binding is a real Apple registry entry, of a kind it can carry, under a key the app really uses', problems.length === 0, problems.join(' | '));

    /* Every account profile_sync entry the registry names for Apple explicitly must be bound. */
    const appleNamed = dump.entries.filter((e) => e.platforms.includes('apple') && e.scope === 'account' && e.sync === 'profile_sync' && e.type !== 'map' && e.type !== 'list' && e.type !== 'json');
    const unbound = appleNamed.filter((e) => !bindings.some((b) => b.id === e.id));
    check('every scalar entry the registry names for Apple has a binding (' + appleNamed.map((e) => e.id).join(', ') + ')', appleNamed.length >= 5 && unbound.length === 0, unbound.map((e) => e.id).join(', '));

    /* The app's enum words equal the registry's allowed words. */
    const enumWords = (src, name) => {
        const m = new RegExp('enum\\s+' + name + '\\b[^{]*\\{([\\s\\S]*?)\\n\\}').exec(src);
        if (!m) return null;
        return [...m[1].matchAll(/^\s*case\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s*=\s*"([^"]+)")?\s*$/gm)].map((x) => x[2] ?? x[1]);
    };
    const themeWords = enumWords(code[themeFile], 'IHAppearanceTheme');
    const cvdWords = enumWords(code[cvdFile], 'IHCVDMode');
    check('the app theme words equal the registry theme words', themeWords !== null && same([...themeWords].sort(), [...entry('appearance.theme').allowed].sort()), JSON.stringify(themeWords));
    check('the app colour-vision words (without "none") equal the registry words', cvdWords !== null && same(cvdWords.filter((w) => w !== 'none').sort(), [...entry('appearance.cvd').allowed].sort()) && cvdWords.includes('none'), JSON.stringify(cvdWords));

    /* The app's default equals the registry default, where an @AppStorage states one. */
    const defaultProblems = [];
    let comparedDefaults = 0;
    for (const b of bindings) {
        const lit = appStorageDefaults.get(b.key);
        if (lit === undefined) continue;
        const e = entry(b.id);
        const want = e.default;
        const got = lit === 'true' ? true : lit === 'false' ? false : (Number.isNaN(Number(lit)) ? lit : Number(lit));
        comparedDefaults++;
        if (got !== want) defaultProblems.push(b.id + ': the app says ' + lit + ', the registry says ' + JSON.stringify(want));
    }
    check('the app defaults equal the registry defaults (' + comparedDefaults + ' compared)', comparedDefaults >= 2 && defaultProblems.length === 0, defaultProblems.join(' | '));

    /* The reason words the app acts on equal the server's constants (rule #35: status plus reason word, never prose). */
    const reasonFile = find('IHAPI/ProfileSyncEndpoints.swift');
    const reasonEnum = (/enum\s+ProfileSyncReason\b[\s\S]*?\n\}/.exec(code[reasonFile]) || [''])[0];
    const swiftReasons = [...reasonEnum.matchAll(/\bcase\s+[A-Za-z]+\s*=\s*"([^"]+)"/g)].map((m) => m[1]);
    const phpConst = (file, name) => (new RegExp("const\\s+" + name + "\\s*=\\s*'([^']+)'").exec(fs.readFileSync(path.join(PUB, 'includes', file), 'utf8')) || [])[1];
    const serverReasons = [phpConst('profile_sync_gate.php', 'PROFILE_SYNC_DISABLED_REASON'), phpConst('profile_sync_gate.php', 'PROFILE_SYNC_NOT_READY_REASON'), phpConst('account_write_gate.php', 'ACCOUNT_WRITES_OFF_REASON')];
    check('the reason words the app acts on equal the server constants (' + swiftReasons.join(', ') + ')', swiftReasons.length === 3 && same([...swiftReasons].sort(), [...serverReasons].sort()), JSON.stringify({ swiftReasons, serverReasons }));
    const engineFile = find('IHFeatures/ProfileSyncEngine.swift');
    check('the engine compares refusals against those cases, never typed-in text', !/message\s*==\s*"/.test(code[engineFile]) && /ProfileSyncReason\.paused\.rawValue/.test(code[engineFile]));

    /* The Swift schema type decodes only fields the snapshot really has. */
    const entryBody = (/struct\s+ProfileEntry\b[\s\S]*?\n\}/.exec(code[schemaFile]) || [''])[0];
    const swiftFields = [...entryBody.matchAll(/^\s*public\s+let\s+([A-Za-z_][A-Za-z0-9_]*)\s*:/gm)].map((m) => m[1]);
    const snapKeys = new Set(JSON.parse(snap).entries.flatMap((e) => Object.keys(e)));
    const missingFields = swiftFields.filter((f) => !snapKeys.has(f));
    check('every field the Swift entry type decodes exists in the snapshot (' + swiftFields.join(', ') + ')', swiftFields.length >= 10 && missingFields.length === 0, missingFields.join(', '));
}

console.log(failures === 0 ? '\nAll lockstep checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
