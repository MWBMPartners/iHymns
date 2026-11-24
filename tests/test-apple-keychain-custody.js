/**
 * tests/test-apple-keychain-custody.js - who may keep, write and delete each Apple Keychain item
 * (account rewrite PR 3b, step I2a, epic #2180)
 *
 * ELI5
 * ----
 * The older Apple apps kept ONE shared sign-in in iCloud Keychain, and every Apple device the person owns
 * read it. Each device is now getting its own sign-in. Three mistakes would undo that quietly, and none of
 * them would show up in any test that can run here (real Keychain behaviour cannot be tested, even in CI):
 *   1. New code saving over, or deleting, the shared item. That would sign out every other device at once.
 *   2. A device's own sign-in being saved so a backup or a phone transfer copies it. Two devices would
 *      then share one sign-in again.
 *   3. A second thing ending up in iCloud Keychain that was never meant to travel.
 * This guard reads the Swift source and makes each of those a red build.
 *
 * Detailed (rule #34, #35)
 * ------------------------
 * The Swift files come from walking `appApple/` (non-test code only), comments removed first, so a comment
 * saying "app.ihymns.token" or "delete()" cannot satisfy or trip a check. Nothing is typed as a file list.
 * The checks are written as rules over every `KeychainTokenStore(...)` construction found in the tree, not
 * as a list of the ones that exist today, so a new store added later is judged by the same rules:
 *   1. The shared item's service name (`app.ihymns.token`) appears in exactly one app file: the one that
 *      declares `LegacySharedTokenReader`.
 *   2. That type, and the `LegacyTokenReading` protocol, declare a `load` and no `save`/`delete`/`remove`
 *      or other function; the type holds its Keychain store as `private`; and its file calls no `SecItem*`.
 *   3. Every `KeychainTokenStore(...)` construction names its service as a string literal, and:
 *        - a synced one (anything but `synchronizable: false`) must use `app.ihymns.enrol-key`, except the
 *          one inside the reader file, which must use the shared item's name;
 *        - a not-synced one must also say `thisDeviceOnly: true`;
 *        - the device-token store exists, and has `synchronizable: false` AND `thisDeviceOnly: true`.
 *   4. The store's `init` has no default service (a caller must name the item), refuses synced plus
 *      this-device-only together, and `save` uses `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`
 *      on its this-device-only branch (and the plain constant on the other).
 *   5. No other file makes a Keychain item synced: every `kSecAttrSynchronizable` outside the store is `false`.
 *   6. Self-tests: each rule is run on a deliberately broken in-memory copy of the real source and must
 *      report a problem.
 *
 * Mutations tried when this was written (each turned it red, then was restored): flipping the device-token
 * store to `synchronizable: true`; adding a `delete()` to the reader; removing `thisDeviceOnly: true` from
 * the device-token store; naming `app.ihymns.token` in a second file; removing the ThisDeviceOnly constant.
 *
 * Usage: node tests/test-apple-keychain-custody.js
 */
import { appSwiftFiles, read, stripSwift, callArguments, selfTest } from './lib/swift-source.js';

selfTest();

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 700) : ''));
}

const SHARED = 'app.ihymns.token';
const ENROL_KEY = 'app.ihymns.enrol-key';
const DEVICE_TOKEN = 'app.ihymns.device-token';

/** { path: code-with-strings } for every app file. */
function loadAll() {
    const map = new Map();
    for (const f of appSwiftFiles()) map.set(f, stripSwift(read(f)));
    return map;
}

/** The body of `struct|actor|class|protocol|enum NAME ... { ... }` in already-stripped code, or ''. */
function typeBody(code, name) {
    const m = new RegExp('\\b(struct|actor|class|protocol|enum)\\s+' + name + '\\b[^{]*\\{').exec(code);
    if (!m) return '';
    let depth = 1;
    let i = m.index + m[0].length;
    const start = i;
    while (i < code.length && depth > 0) {
        if (code[i] === '{') depth++;
        else if (code[i] === '}') depth--;
        i++;
    }
    return code.slice(start, i - 1);
}

/** Parse one KeychainTokenStore(...) argument string. */
function parseConstruction(args) {
    const service = (/\bservice\s*:\s*"([^"]*)"/.exec(args) || [])[1] ?? null;
    const syncMatch = /\bsynchronizable\s*:\s*(true|false)\b/.exec(args);
    const deviceOnly = /\bthisDeviceOnly\s*:\s*true\b/.test(args);
    return {
        service,
        // the init's default for `synchronizable` is true, so "not mentioned" means synced
        synced: syncMatch ? syncMatch[1] === 'true' : true,
        deviceOnly,
    };
}

/** Judge the whole tree. PURE (takes the map), so the self-tests can feed it mutated copies. */
function judge(map) {
    const problems = [];

    // ---- rule 1: the shared item's name appears in one file only ----
    const withShared = [...map].filter(([, code]) => code.includes('"' + SHARED + '"')).map(([f]) => f);
    const readerFile = [...map].find(([, code]) => /\bstruct\s+LegacySharedTokenReader\b/.test(code));
    if (!readerFile) problems.push('no LegacySharedTokenReader type found');
    else if (withShared.length !== 1 || withShared[0] !== readerFile[0]) {
        problems.push('the shared item name appears in: ' + (withShared.join(', ') || '(nowhere)') + ' (must be only ' + readerFile[0] + ')');
    }

    // ---- rule 2: the reader can only read ----
    if (readerFile) {
        const [rf, rcode] = readerFile;
        for (const typeName of ['LegacySharedTokenReader', 'LegacyTokenReading']) {
            const body = typeBody(rcode, typeName);
            if (!body) { problems.push(typeName + ' body not found in ' + rf); continue; }
            const funcs = [...body.matchAll(/\bfunc\s+([A-Za-z_][A-Za-z0-9_]*)/g)].map((m) => m[1]);
            const extra = funcs.filter((n) => n !== 'load');
            if (!funcs.includes('load')) problems.push(typeName + ' has no load()');
            if (extra.length) problems.push(typeName + ' declares more than load(): ' + extra.join(', '));
        }
        const readerBody = typeBody(rcode, 'LegacySharedTokenReader');
        if (!/\bprivate\s+let\s+sharedItem\b/.test(readerBody)) problems.push('the reader must hold its store as `private let sharedItem`');
        if (/\bSecItem(Add|Delete|Update)\b/.test(rcode)) problems.push(rf + ' calls SecItem* directly');
    }

    // ---- rule 3: every KeychainTokenStore(...) construction ----
    let deviceTokenSeen = false;
    let constructions = 0;
    for (const [f, code] of map) {
        for (const c of callArguments(code, 'KeychainTokenStore')) {
            constructions++;
            const p = parseConstruction(c.args);
            const where = f.split('/').pop();
            if (p.service === null) { problems.push(where + ': a KeychainTokenStore has no literal service name'); continue; }
            if (p.synced) {
                const isReader = readerFile && f === readerFile[0];
                const allowed = isReader ? SHARED : ENROL_KEY;
                if (p.service !== allowed) problems.push(where + ': a SYNCED store uses "' + p.service + '" (allowed here: "' + allowed + '")');
                if (p.deviceOnly) problems.push(where + ': a store is both synced and this-device-only');
            } else if (!p.deviceOnly) {
                problems.push(where + ': a not-synced store "' + p.service + '" is missing `thisDeviceOnly: true` (a backup would copy it)');
            }
            if (p.service === DEVICE_TOKEN) {
                deviceTokenSeen = true;
                if (p.synced || !p.deviceOnly) problems.push(where + ': the device-token store must be synchronizable: false AND thisDeviceOnly: true');
            }
        }
    }
    if (constructions === 0) problems.push('no KeychainTokenStore construction found to judge');
    if (!deviceTokenSeen) problems.push('no store for "' + DEVICE_TOKEN + '" was found');

    // ---- rule 4: the store itself ----
    const storeFile = [...map].find(([, code]) => /\bactor\s+KeychainTokenStore\b/.test(code));
    if (!storeFile) problems.push('KeychainTokenStore not found');
    else {
        const code = storeFile[1];
        const initSig = (/public\s+init\s*\(([^)]*)\)/.exec(code) || [])[1] ?? '';
        if (!/\bservice\s*:\s*String\b/.test(initSig) || /\bservice\s*:\s*String\s*=/.test(initSig)) problems.push('the store init must take `service: String` with NO default');
        if (!/precondition\s*\(\s*!\s*\(\s*synchronizable\s*&&\s*thisDeviceOnly\s*\)/.test(code)) problems.push('the store init must refuse synchronizable + thisDeviceOnly together');
        const saveBody = (() => {
            const m = /func\s+save\s*\([^)]*\)[^{]*\{/.exec(code);
            if (!m) return '';
            let d = 1; let i = m.index + m[0].length; const s = i;
            while (i < code.length && d > 0) { if (code[i] === '{') d++; else if (code[i] === '}') d--; i++; }
            return code.slice(s, i - 1);
        })();
        if (!/if\s+thisDeviceOnly\s*\{[^}]*kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly/.test(saveBody)) problems.push('save() must use kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly on its this-device-only branch');
        if (!/else\s*\{[^}]*kSecAttrAccessibleAfterFirstUnlock\b(?!ThisDeviceOnly)/.test(saveBody)) problems.push('save() must keep the plain accessibility on its other branch');
    }

    // ---- rule 5: nothing else makes an item synced ----
    for (const [f, code] of map) {
        if (storeFile && f === storeFile[0]) continue;
        for (const m of code.matchAll(/kSecAttrSynchronizable[^:\n]*:\s*([^,\n\]\)]+)/g)) {
            if (m[1].trim() !== 'false') problems.push(f.split('/').pop() + ': sets kSecAttrSynchronizable to `' + m[1].trim() + '` (only the store may)');
        }
    }
    return { problems, constructions };
}

const map = loadAll();
const real = judge(map);
check('judged ' + real.constructions + ' KeychainTokenStore construction(s) in the tree', real.constructions >= 3);
check('every custody rule holds on the real source', real.problems.length === 0, real.problems.join('\n        '));

/* ---- rule 6: self-tests, each on a broken in-memory copy ---- */
function mutate(rel, fn) {
    const m = new Map(map);
    const key = [...m.keys()].find((k) => k.endsWith(rel));
    if (!key) throw new Error('self-test target missing: ' + rel);
    const before = m.get(key);
    const after = fn(before);
    if (after === before) throw new Error('self-test mutation changed nothing in ' + rel);
    m.set(key, after);
    return judge(m).problems;
}
check('self-test: a device-token store flipped to synced is caught',
    mutate('IHAuth/AuthStorage.swift', (c) => c.replace(/(service:\s*"app\.ihymns\.device-token",\s*)synchronizable:\s*false,\s*thisDeviceOnly:\s*true/, '$1synchronizable: true')).length > 0);
check('self-test: a device-token store without thisDeviceOnly is caught',
    mutate('IHAuth/AuthStorage.swift', (c) => c.replace(/(service:\s*"app\.ihymns\.device-token",\s*synchronizable:\s*false),\s*thisDeviceOnly:\s*true/, '$1')).length > 0);
check('self-test: a delete() added to the reader is caught',
    mutate('IHAuth/LegacySharedTokenReader.swift', (c) => c.replace(/public func load\(\)/, 'public func delete() async throws {}\n    public func load()')).length > 0);
check('self-test: the shared name in a second file is caught',
    mutate('IHAuth/AuthStorage.swift', (c) => c + '\nlet oops = "app.ihymns.token"\n').length > 0);
check('self-test: removing the ThisDeviceOnly constant is caught',
    mutate('IHAuth/KeychainTokenStore.swift', (c) => c.replace('kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly', 'kSecAttrAccessibleAfterFirstUnlock')).length > 0);
check('self-test: a default service name on the store init is caught',
    mutate('IHAuth/KeychainTokenStore.swift', (c) => c.replace('init(service: String,', 'init(service: String = "x",')).length > 0);
check('self-test: another file making an item synced is caught',
    mutate('IHAuth/AuthStorage.swift', (c) => c + '\nlet q = [kSecAttrSynchronizable as String: true]\n').length > 0);
check('self-test: a comment mentioning the shared name does not trip rule 1', (() => {
    const m = new Map(map);
    const key = [...m.keys()].find((k) => k.endsWith('IHAuth/AuthStorage.swift'));
    m.set(key, stripSwift(read(key) + '\n// app.ihymns.token is only read by LegacySharedTokenReader\n'));
    return judge(m).problems.length === 0;
})());

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
