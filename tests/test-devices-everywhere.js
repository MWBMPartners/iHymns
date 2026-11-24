/**
 * tests/test-devices-everywhere.js - the Devices card: "Sign out everywhere" and "Turn off" (PR 3 step H6, epic #2180)
 * ====================================================================================================================
 *
 * ELI5
 * ----
 * The Devices card on the settings page gets two new controls: a "Sign out everywhere" button (ends every sign-in on the
 * account, this browser too) and, when automatic sign-in for new Apple devices is on, a "Turn off" button. Both show only
 * where the site has device sign-in switched on. "Sign out everywhere" must behave like an ordinary sign-out in the one
 * way that matters most: if something the person changed has not reached their account, it ASKS FIRST, before anything is
 * sent. And if the server cannot be reached or refuses, the person stays signed in and is told the other devices were NOT
 * signed out; nothing is cleared. This test plays all of that with the REAL devices.js, UserAuth, Favorites and the REAL
 * card markup read out of settings.php, against a pretend server.
 *
 * Detailed (rule #34: markup derived from the tree; mutations proven in the H6 commit)
 *  1. The pure helpers: the three "how was this made" notes, the failure messages (by HTTP status only, rule #35).
 *  2. The card markup is cut out of includes/pages/settings.php (not retyped) and booted with the real module:
 *     hidden when the site has the switch off; shown when on; the keys row only when a key exists.
 *  3. The real flow: the question comes BEFORE the request; closing the question sends nothing and changes nothing;
 *     "sign out anyway" sends, then clears; 503, 403, 429 and a down network leave the person signed in with their data and
 *     the right sentence; 401 counts as signed out; a second click while the question is open asks once.
 *  4. "Turn off": posts the right action, hides the row on 200, keeps it on failure.
 *  5. The two button words in settings.php equal the constants devices.js exports.
 *  MUTATIONS (recorded in the H6 commit): the question skipped -> rows in 3 go red; clearing on a 503 -> red; the
 *  button shown when the switch is off -> red.
 *
 * Usage: node tests/test-devices-everywhere.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUB = path.join(__dirname, '..', 'appWeb', 'public_html');
const JS = path.join(PUB, 'js');
const MODULES = path.join(JS, 'modules');
const url = (p) => pathToFileURL(p).href;
let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + String(detail).slice(0, 300)));
    if (!cond) failures++;
};
const realWarn = console.warn;
console.warn = (...a) => { if (!String(a[0]).startsWith('[offlineQueue]')) realWarn(...a); };
const flush = (ms = 40) => new Promise((r) => setTimeout(r, ms));

const C = await import(url(path.join(JS, 'constants.js')));
const owner = await import(url(path.join(MODULES, 'account-data-owner.js')));
const dev = await import(url(path.join(MODULES, 'devices.js')));

/* ================= 1. the pure helpers ================= */
console.log('\n1 - the pure helpers\n');
check('a TV paired with a code is named, switch on or off', dev.deviceKindNote({ issuedVia: 'device_code' }, false) === 'Paired TV' && dev.deviceKindNote({ issuedVia: 'device_code' }, true) === 'Paired TV');
check('"Added automatically" and the older-app note appear only where the switch is on',
    dev.deviceKindNote({ issuedVia: 'enrol', boundToDevice: true }, true) === 'Added automatically'
    && dev.deviceKindNote({ issuedVia: 'enrol', boundToDevice: true }, false) === ''
    && /^Older app version/.test(dev.deviceKindNote({ issuedVia: 'password', boundToDevice: false, platform: 'apple' }, true))
    && dev.deviceKindNote({ issuedVia: 'password', boundToDevice: false, platform: 'apple' }, false) === '');
check('a browser, an admin-page sign-in and a device-bound sign-in are never called "older app"',
    dev.deviceKindNote({ issuedVia: 'password', boundToDevice: false, platform: 'web' }, true) === ''
    && dev.deviceKindNote({ issuedVia: 'cross_surface', boundToDevice: false, platform: null }, true) === ''
    && dev.deviceKindNote({ issuedVia: 'password', boundToDevice: true, platform: 'apple' }, true) === '');
check('the single-device sign-out question says the key is cancelled and the others stay signed in, only for a device\'s own sign-in on a site with the switch on',
    /cancels the automatic sign-in key/.test(dev.signOutConfirmText('iPhone', { boundToDevice: true }, true))
    && /other devices stay signed in/.test(dev.signOutConfirmText('iPhone', { boundToDevice: true }, true))
    && dev.signOutConfirmText('iPhone', { boundToDevice: true }, false) === 'Sign out "iPhone"? It will need to sign in again.'
    && dev.signOutConfirmText('Chrome', { boundToDevice: false }, true) === 'Sign out "Chrome"? It will need to sign in again.'
    && dev.signOutConfirmText('x', undefined, true) === 'Sign out "x"? It will need to sign in again.');
check('a browser sign-in with NO platform recorded is never called an older Apple app (the current row, or a browser-made name)',
    dev.deviceKindNote({ issuedVia: 'password', boundToDevice: false, platform: null, isCurrent: true }, true) === ''
    && dev.deviceKindNote({ issuedVia: 'password', boundToDevice: false, platform: null, deviceName: 'Chrome on Windows' }, true) === ''
    && dev.deviceKindNote({ issuedVia: 'password', boundToDevice: false, platform: null, deviceName: 'Safari on iPhone' }, true) === ''
    && /^Older app version/.test(dev.deviceKindNote({ issuedVia: 'password', boundToDevice: false, platform: null, deviceName: null }, true)));
check('every failure sentence of "Sign out everywhere" says the person is still signed in and the others were not signed out',
    [0, 403, 429, 500, 503].every((s) => /still signed in/.test(dev.signOutEverywhereFailureMessage(s)) && /other devices were not signed out/.test(dev.signOutEverywhereFailureMessage(s))));
check('the messages are chosen by status, not by any server sentence (the function takes only a number)', dev.signOutEverywhereFailureMessage.length === 1 && dev.keysOffFailureMessage.length === 1);
check('deviceSigninShown is true only for an explicit enabled:true', dev.deviceSigninShown({ deviceSignin: { enabled: true } }) === true && dev.deviceSigninShown({ deviceSignin: { enabled: 'yes' } }) === false && dev.deviceSigninShown({}) === false && dev.deviceSigninShown(null) === false);

/* ================= 2. the real markup ================= */
console.log('\n2 - the real card markup, from settings.php\n');
const settingsSrc = fs.readFileSync(path.join(PUB, 'includes', 'pages', 'settings.php'), 'utf8');
const cardStart = settingsSrc.indexOf('<div class="card card-settings mb-3 d-none" id="settings-devices-card"');
const cardEnd = settingsSrc.indexOf('<!-- ====', cardStart);
check('the devices card was found in settings.php', cardStart > 0 && cardEnd > cardStart);
const cardHtml = settingsSrc.slice(cardStart, cardEnd);
check('the card HTML holds no PHP (it is plain markup the test can use as it is)', !cardHtml.includes('<?'));

const apiMod = await import(url(path.join(JS, 'utils', 'api-client.js')));
apiMod.setAuthHeaderProvider(() => ({ Authorization: 'Bearer tok' }));
const { UserAuth } = await import(url(path.join(MODULES, 'user-auth.js')));
const { Favorites } = await import(url(path.join(MODULES, 'favorites.js')));
const { offlineQueue } = await import(url(path.join(MODULES, 'offline-queue.js')));
const markers = {};
offlineQueue.enqueue = async (t) => { markers[t] = (markers[t] || 0) + 1; };
offlineQueue.count = async (t) => markers[t] || 0;
offlineQueue.drainLatest = async (t, send) => { if (markers[t] && await send()) markers[t] = 0; };
offlineQueue.bindAutoDrainLatest = () => {};

/** One pretend browser + server. `everywhere` = what the server answers to auth_signout_everywhere (a status number, or 'down'). */
async function world({ switchOn = true, keys = 1, everywhere = 200, keysOff = 200, answers = [], unsent = false } = {}) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event, Element: window.Element, HTMLInputElement: window.HTMLInputElement, requestAnimationFrame: (f) => setTimeout(f, 0) });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true, serviceWorker: undefined }, configurable: true, writable: true });
    for (const k of Object.keys(markers)) delete markers[k];
    const w = { requests: [], dialogs: [], answers: [...answers], confirms: [], toasts: [], announced: [] };
    window.confirm = (m) => { w.confirms.push(m); return true; };
    document.body.innerHTML = cardHtml;
    globalThis.fetch = async (input, init = {}) => {
        const u = new URL(String(input), 'https://example.test/');
        const action = u.searchParams.get('action');
        w.requests.push({ action, order: w.requests.length, dialogsSoFar: w.dialogs.length });
        const json = (status, obj) => new Response(JSON.stringify(obj), { status, headers: { 'Content-Type': 'application/json' } });
        if (action === 'devices_list') {
            return json(200, {
                devices: [
                    { id: 'aaaaaaaaaaaaaaaa', deviceName: 'This browser', platform: 'web', isCurrent: true, issuedVia: 'password', boundToDevice: false, createdAt: '2026-11-01 10:00:00' },
                    { id: 'bbbbbbbbbbbbbbbb', deviceName: null, platform: 'apple', isCurrent: false, issuedVia: 'password', boundToDevice: false, createdAt: '2026-11-01 10:00:00' },
                ],
                enrolKeys: Array.from({ length: keys }, () => ({ platform: 'apple', createdAt: '2026-11-01 10:00:00', lastUsedAt: null, expiresAt: '2027-05-01 10:00:00' })),
            });
        }
        if (action === 'favorites_sync') throw new TypeError('network down');
        if (action === 'auth_signout_everywhere') {
            if (everywhere === 'down') throw new TypeError('network down');
            return json(everywhere, { tokensRevoked: 2, enrolKeysRevoked: 1, keptCurrent: false });
        }
        if (action === 'auth_enrol_keys_revoke') return json(keysOff, { revoked: 1 });
        return json(200, { ok: true });
    };
    const app = { config: { apiUrl: '/api', profileSync: { enabled: true }, deviceSignin: { enabled: switchOn } }, syncStorage() {},
        showToast(m) { w.toasts.push(m); }, settings: { refreshAccountSection() { w.accountRefreshed = true; } } };
    app.showChoice = async (message, opts) => {
        w.dialogs.push({ message, opts, signedIn: app.userAuth.isLoggedIn(), requestsSoFar: w.requests.length });
        return w.answers.length ? w.answers.shift() : null;
    };
    app.userAuth = new UserAuth(app);
    app.favorites = new Favorites(app);
    app.userAuth.saveCredentials('tok', { id: 1, username: 'ann' });
    app.favorites._syncReady = true;
    localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1', tags: [] }]));
    localStorage.setItem(C.STORAGE_DATA_OWNER_UID, '1');
    if (unsent) { app.favorites._recordAdd(['MP-2']); localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1', tags: [] }, { id: 'MP-2', tags: [] }])); }
    window.iHymnsApp = app;
    w.app = app;
    w.personalLeft = () => owner.PERSONAL_DATA_KEYS.filter((k) => localStorage.getItem(k) !== null);
    w.everywhereCalls = () => w.requests.filter((r) => r.action === 'auth_signout_everywhere');
    w.msg = () => document.getElementById('devices-msg')?.textContent || '';
    dev.bootDevicesCard();
    await flush(80);
    w.accountRefreshed = false;   /* signing in and booting the card also redraw it; only a redraw AFTER the click counts */
    return w;
}
const shown = (id) => !document.getElementById(id).classList.contains('d-none');
const click = async (id) => { document.getElementById(id).click(); await flush(120); };

{
    let w = await world({ switchOn: false });
    check('switch off: the extra area and the keys row are hidden (the card is what it was)', !shown('devices-everywhere') && !shown('devices-keys-row'));
    check('switch off: the older-app note is not drawn', !/Older app version/.test(document.getElementById('devices-list').textContent));
    w = await world({ switchOn: true, keys: 1 });
    check('switch on: the "Sign out everywhere" area shows, and so does the keys row when a key exists', shown('devices-everywhere') && shown('devices-keys-row'));
    check('switch on: the older-app note is drawn for the shared Apple sign-in', /Older app version, may be shared by several of your Apple devices/.test(document.getElementById('devices-list').textContent));
    w = await world({ switchOn: true, keys: 0 });
    check('switch on but no key: the area shows, the keys row does not', shown('devices-everywhere') && !shown('devices-keys-row'));
}

/* ================= 3. the real flow ================= */
console.log('\n3 - Sign out everywhere\n');
{
    /* nothing unsent: no question, the request goes, then everything is cleared */
    let w = await world();
    await click('devices-everywhere-btn');
    check('nothing unsent: one request to auth_signout_everywhere and no question', w.everywhereCalls().length === 1 && w.dialogs.length === 0);
    check('...the person is signed out here, the personal data is gone, and the Account card was redrawn', !w.app.userAuth.isLoggedIn() && w.personalLeft().length === 0 && w.accountRefreshed === true);
    check('...the person was asked to confirm first, in plain words', w.confirms.length === 1 && /^Sign out everywhere\?/.test(w.confirms[0]));
    check('...the ordinary sign-out action was NOT used instead', !w.requests.some((r) => r.action === 'auth_logout'));

    /* unsent change: the question comes BEFORE the request */
    w = await world({ unsent: true, answers: ['option2'] });
    await click('devices-everywhere-btn');
    check('something unsent: the question was asked, and BEFORE the request was sent', w.dialogs.length >= 1 && w.everywhereCalls().length === 1 && w.dialogs[0].requestsSoFar <= w.everywhereCalls()[0].order, JSON.stringify({ d: w.dialogs.length, r: w.everywhereCalls() }));
    check('...at the moment of asking the person was still signed in', w.dialogs[0]?.signedIn === true);
    check('...and "sign out anyway" then signs out and clears', !w.app.userAuth.isLoggedIn() && w.personalLeft().length === 0);

    /* the box closed: nothing sent, nothing changed */
    w = await world({ unsent: true, answers: [null] });
    await click('devices-everywhere-btn');
    check('closing the question: nothing is sent to the server', w.everywhereCalls().length === 0);
    check('...the person stays signed in with their saved data', w.app.userAuth.isLoggedIn() && w.personalLeft().length >= 1);

    /* failures: stay signed in, data kept, the right sentence */
    for (const [label, status] of [['a 503', 503], ['a 500', 500], ['a 403 (same-origin check)', 403], ['a 429 (too many)', 429], ['a network that is down', 'down']]) {
        w = await world({ everywhere: status });
        await click('devices-everywhere-btn');
        check(`${label}: the person stays signed in and nothing is cleared`, w.app.userAuth.isLoggedIn() && w.personalLeft().length >= 1 && w.accountRefreshed !== true, JSON.stringify([w.app.userAuth.isLoggedIn(), w.personalLeft(), w.accountRefreshed]));
        check(`${label}: the card says the other devices were NOT signed out`, /other devices were not signed out/.test(w.msg()), w.msg());
        check(`${label}: the button is usable again`, document.getElementById('devices-everywhere-btn').disabled === false);
    }
    /* 401: this sign-in was already dead: signed out anyway */
    w = await world({ everywhere: 401 });
    await click('devices-everywhere-btn');
    check('a 401 (this sign-in was already dead): signed out here as a normal sign-out', !w.app.userAuth.isLoggedIn());

    /* a second click while the question is open asks once */
    w = await world({ unsent: true });
    let release; const held = new Promise((r) => { release = r; });
    w.app.showChoice = async (message, opts) => { w.dialogs.push({ message, opts }); await held; return null; };
    document.getElementById('devices-everywhere-btn').click();
    await flush(120);
    const second = w.app.userAuth.logout({ everywhere: true });
    await flush(60);
    const whileOpen = w.dialogs.length;
    release();
    const secondResult = await second;
    await flush(60);
    check('a second request while the question is open does not open a second question or send anything', whileOpen === 1 && secondResult.signedOut === false && w.everywhereCalls().length === 0);
}

/* ================= 4. Turn off ================= */
console.log('\n4 - Turn off automatic sign-in\n');
{
    let w = await world({ keys: 2 });
    await click('devices-keys-off-btn');
    check('"Turn off" posts auth_enrol_keys_revoke once, asks first, and hides the row on success', w.requests.filter((r) => r.action === 'auth_enrol_keys_revoke').length === 1 && w.confirms.length === 1 && !shown('devices-keys-row'));
    check('...and does not sign the person out', w.app.userAuth.isLoggedIn());
    w = await world({ keys: 2, keysOff: 503 });
    await click('devices-keys-off-btn');
    check('a failed "Turn off" keeps the row and says so', shown('devices-keys-row') && /Could not turn off/.test(w.msg()), w.msg());
}

/* ================= 5. the button words ================= */
console.log('\n5 - the button words\n');
check('the "Sign out everywhere" button text in settings.php is the constant devices.js exports', new RegExp('id="devices-everywhere-btn"[^>]*>[\\s\\S]*?' + dev.SIGN_OUT_EVERYWHERE_LABEL + '\\s*</button>').test(cardHtml));
check('the "Turn off" button text in settings.php is the constant devices.js exports', new RegExp('id="devices-keys-off-btn"[^>]*>' + dev.TURN_OFF_AUTO_SIGN_IN_LABEL + '</button>').test(cardHtml));

console.log(failures === 0 ? '\nALL PASS' : `\n${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);
