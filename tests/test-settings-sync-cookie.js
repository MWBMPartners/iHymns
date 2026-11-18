/**
 * tests/test-settings-sync-cookie.js — the shared settings cookie, between two simulated sites
 *
 * PURPOSE
 * ELI5: this runs the real cookie transport (`js/modules/settings-sync.js`) in
 * two pretend browser tabs, "dev.ihymns.app" and "beta.ihymns.app", that share
 * one cookie jar the way two real iHymns sites would, and checks what each
 * one ends up with. It also checks that nothing private ever goes into the
 * cookie, that a hostile cookie changes nothing, and that nothing else in the
 * code quietly reads the cookie or copies a whole store over the local one.
 *
 * DETAIL
 *   - A change on one site shows on the other after it starts (and fires the
 *     "settings applied" event so the page re-applies them); each setting is
 *     decided on its own; a reset on one clears the other.
 *   - The cookie, and the stamps record, hold ONLY registry keys: favourites, set
 *     lists, history, tokens and the per-site stamps never appear in the cookie.
 *   - The cookie has the right attributes (domain .ihymns.app, Secure, SameSite
 *     Lax, about a year).
 *   - The one-time import of the old `ihymns_sync` cookie happens once and only
 *     takes registry keys.
 *   - It does nothing on other domains (localhost, ihymns.net).
 *   - A cookie planted by a hostile page (a `__proto__` key, an invalid value, a
 *     stamp from the far future) is ignored and changes nothing.
 *   - Tree checks: no PHP file reads `$_COOKIE['ihymns_prefs']` (the server must
 *     never trust it); `app.js` no longer copies a whole store over local storage.
 *
 * Mutation-proven (rule #34): drop the registry check in recordLocalChange (the
 * stamps row fails); make recordRemoval do nothing (the reset row fails); drop the
 * Secure flag (the attributes row fails); read the cookie in PHP (the server row
 * fails). The current tree is green.
 *
 *   node tests/test-settings-sync-cookie.js
 *
 * @see appWeb/public_html/js/modules/settings-sync.js
 * @see .claude/settings-sync-2143-plan.md  (section 5, guard G3)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import jsdom from 'jsdom';

const { JSDOM, CookieJar } = jsdom;
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');

const { CrossSiteSync, CROSS_SITE_COOKIE } = await import(path.join(PUB, 'js', 'modules', 'settings-sync.js'));
const { CROSS_SITE_KEYS } = await import(path.join(PUB, 'js', 'modules', 'settings-sync-registry.js'));
const { decodeEnvelope } = await import(path.join(PUB, 'js', 'modules', 'settings-sync-core.js'));
const C = await import(path.join(PUB, 'js', 'constants.js'));

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const THEME = C.STORAGE_THEME;
const FONT = C.STORAGE_FONT_SIZE;
const MOTION = C.STORAGE_REDUCE_MOTION;

/** A pretend browser tab at `url`, sharing `jar` with any other tab given the same jar. */
function tab(url, jar) {
    const dom = new JSDOM('<!doctype html><title>t</title>', { url, cookieJar: jar });
    const win = dom.window;
    const sync = new CrossSiteSync({ channel: 'alpha', win });
    const events = [];
    win.document.addEventListener(C.EVT_XSITE_SETTINGS_APPLIED, (e) => events.push(e.detail.keys));
    /* The transport uses the standard global `CustomEvent`; point it at THIS tab's class so the event is accepted. */
    const run = (fn) => { globalThis.CustomEvent = win.CustomEvent; return fn(); };
    return { win, sync, events, run, ls: win.localStorage, jar, url };
}
const rawCookie = (t) => (t.win.document.cookie.split(';').map((s) => s.trim()).find((s) => s.startsWith(CROSS_SITE_COOKIE + '=')) || '');
const cookieMap = (t) => decodeEnvelope(rawCookie(t).slice(CROSS_SITE_COOKIE.length + 1));

console.log('Two sites, one cookie:');
{
    const jar = new CookieJar();
    const A = tab('https://dev.ihymns.app/', jar);
    const B = tab('https://beta.ihymns.app/', jar);

    A.run(() => A.sync.init());
    A.ls.setItem(THEME, 'dark');
    A.run(() => A.sync.recordLocalChange(THEME));
    check('a setting chosen on A is written to the shared cookie', cookieMap(A).get(THEME)?.[0] === 'dark');

    const changed = B.run(() => B.sync.init());
    check('B adopts it when it starts', changed === true && B.ls.getItem(THEME) === 'dark');
    check('...and announces it so the page can re-apply (the event names the key)', B.events.length === 1 && B.events[0].includes(THEME));

    B.ls.setItem(FONT, '20');
    B.run(() => B.sync.recordLocalChange(FONT));
    A.run(() => A.sync._reconcile(true));
    check('a different setting changed on B reaches A', A.ls.getItem(FONT) === '20');
    check('...without undoing the theme A set (each setting is decided on its own)', A.ls.getItem(THEME) === 'dark' && B.ls.getItem(THEME) === 'dark');

    /* A later change to the SAME setting wins, even from the older tab. */
    B.ls.setItem(THEME, 'light');
    B.run(() => B.sync.recordLocalChange(THEME));
    A.run(() => A.sync._reconcile(true));
    check('the newer change to the same setting wins everywhere', A.ls.getItem(THEME) === 'light' && cookieMap(A).get(THEME)?.[0] === 'light');

    /* A reset travels. */
    for (const key of CROSS_SITE_KEYS) A.ls.removeItem(key);
    A.run(() => A.sync.recordRemoval());
    B.run(() => B.sync._reconcile(true));
    check('a reset on A clears the settings on B too (the old values are not handed back)',
        B.ls.getItem(THEME) === null && B.ls.getItem(FONT) === null);
    check('...and the cookie holds deletions, not the old values', cookieMap(A).get(THEME)?.[0] === null);
}

console.log('Only registry keys, only in the cookie that should hold them:');
{
    const jar = new CookieJar();
    const A = tab('https://dev.ihymns.app/', jar);
    A.run(() => A.sync.init());
    A.ls.setItem(C.STORAGE_FAVORITES, JSON.stringify(['MP-1', 'MP-2']));
    A.ls.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ name: 'Sunday' }]));
    A.ls.setItem(C.STORAGE_HISTORY, JSON.stringify(['MP-9']));
    A.ls.setItem(C.STORAGE_AUTH_TOKEN, 'SECRET-TOKEN-VALUE');
    A.ls.setItem(C.STORAGE_OWNER_ID, 'OWNER-ID-VALUE');
    A.ls.setItem(C.STORAGE_ANALYTICS_CONSENT, 'granted');
    for (const key of [C.STORAGE_FAVORITES, C.STORAGE_SETLISTS, C.STORAGE_HISTORY, C.STORAGE_AUTH_TOKEN, C.STORAGE_OWNER_ID, C.STORAGE_ANALYTICS_CONSENT]) {
        A.run(() => A.sync.recordLocalChange(key));
    }
    /* Check the stamps record NOW, straight after the content keys were recorded, before a later change
       rewrites it: a key outside the registry must never be stamped. */
    const stampsAfterContent = JSON.parse(A.ls.getItem(C.STORAGE_XSITE_STAMPS) || '{}');
    check('recording a favourite, set list, history, token, owner id or consent stamps nothing',
        Object.keys(stampsAfterContent).length === 0, JSON.stringify(stampsAfterContent));
    A.ls.setItem(THEME, 'dark');
    A.run(() => A.sync.recordLocalChange(THEME));

    const text = decodeURIComponent(rawCookie(A));
    check('the cookie holds the theme', text.includes('ihymns_theme'));
    check('...and none of the favourites, set lists, history, token, owner id or consent',
        !/MP-1|Sunday|MP-9|SECRET-TOKEN|OWNER-ID|granted|favorites|setlists|history|auth_token|owner_id|consent/i.test(text), text);
    check('...and not this site\'s own stamps record', !text.includes('xsite_stamps'));
    const stamps = JSON.parse(A.ls.getItem(C.STORAGE_XSITE_STAMPS) || '{}');
    check('the stamps record (device-local) holds registry keys only',
        Object.keys(stamps).length > 0 && Object.keys(stamps).every((k) => CROSS_SITE_KEYS.includes(k)), JSON.stringify(stamps));
    check('every key in the cookie is a registry key', [...cookieMap(A).keys()].every((k) => CROSS_SITE_KEYS.includes(k)));
}

console.log('Cookie attributes:');
{
    const jar = new CookieJar();
    const A = tab('https://dev.ihymns.app/', jar);
    A.run(() => A.sync.init());
    A.ls.setItem(THEME, 'dark');
    A.run(() => A.sync.recordLocalChange(THEME));
    const c = jar.getCookiesSync('https://beta.ihymns.app/').find((x) => x.key === CROSS_SITE_COOKIE);
    check('it is visible from a sibling site (domain .ihymns.app)', !!c && c.domain === 'ihymns.app' && c.hostOnly === false);
    check('it is Secure, SameSite=Lax, path /, about one year', !!c && c.secure === true && c.sameSite === 'lax' && c.path === '/' && c.maxAge >= 31000000 && c.maxAge <= 32000000,
        c ? JSON.stringify({ secure: c.secure, sameSite: c.sameSite, path: c.path, maxAge: c.maxAge }) : 'no cookie');
}

console.log('One-time import of the old cookie:');
{
    const jar = new CookieJar();
    const A = tab('https://dev.ihymns.app/', jar);
    A.win.document.cookie = 'ihymns_sync=' + encodeURIComponent(JSON.stringify({ ihymns_theme: 'light', ihymns_fontSize: '99', ihymns_auth_token: 'SECRET' })) + '; Domain=.ihymns.app; Path=/';
    A.run(() => A.sync.init());
    check('an old value is carried over', A.ls.getItem(THEME) === 'light');
    check('an invalid old value is not', A.ls.getItem(FONT) === null);
    check('a key that is not in the registry is not', A.ls.getItem(C.STORAGE_AUTH_TOKEN) === null);
    check('it is marked done, so it never repeats', A.ls.getItem(C.STORAGE_XSITE_LEGACY_IMPORTED) === '1');
    A.ls.removeItem(THEME);
    A.run(() => A.sync._importLegacyOnce());
    check('...a second import changes nothing', A.ls.getItem(THEME) === null);
}

console.log('Inactive where it should be:');
for (const url of ['http://localhost:8080/', 'https://ihymns.net/', 'https://example.com/']) {
    const T = tab(url, new CookieJar());
    T.ls.setItem(THEME, 'dark');
    const changed = T.run(() => T.sync.init());
    T.run(() => T.sync.recordLocalChange(THEME));
    check(url + ' — does nothing (no cookie written, nothing changed)', changed === false && rawCookie(T) === '' && T.sync.active === false);
}

console.log('A hostile cookie changes nothing:');
{
    const jar = new CookieJar();
    const A = tab('https://dev.ihymns.app/', jar);
    const hostile = encodeURIComponent('{"v":2,"k":{"__proto__":["x",1],"constructor":["y",2],"ihymns_theme":["sepia",9],"ihymns_fontSize":["18",99999999999999],"ihymns_auth_token":["abc",5],"ihymns_reduceMotion":[null,-4]}}');
    A.win.document.cookie = CROSS_SITE_COOKIE + '=' + hostile + '; Domain=.ihymns.app; Path=/';
    let threw = false; let changed = null;
    try { changed = A.run(() => A.sync.init()); } catch { threw = true; }
    check('starting with it does not throw and adopts nothing',
        !threw && changed === false && A.ls.getItem(THEME) === null && A.ls.getItem(FONT) === null && A.ls.getItem(C.STORAGE_AUTH_TOKEN) === null);
    check('...and nothing in the page was polluted', ({}).polluted === undefined && Object.keys({}).length === 0);
}

console.log('The server never trusts it, and the old overwrite is gone:');
{
    const files = execFileSync('git', ['ls-files', '-z'], { cwd: REPO, encoding: 'utf8' }).split('\0').filter(Boolean);
    const stripPhp = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/[^\n]*/g, '$1').replace(/(^|\s)#[^\n]*/g, '$1');
    const phpReaders = files.filter((f) => f.endsWith('.php') && !f.startsWith('appWeb/private_html/lib/'))
        .filter((f) => /\$_COOKIE\s*\[\s*['"]ihymns_prefs['"]\s*\]/.test(stripPhp(fs.readFileSync(path.join(REPO, f), 'utf8'))));
    check('no PHP file reads $_COOKIE[\'ihymns_prefs\']', phpReaders.length === 0, phpReaders.join(', '));
    const appJs = fs.readFileSync(path.join(PUB, 'js', 'app.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
    check('app.js no longer copies every stored item over the local ones', !/Object\.entries\(\s*data\s*\)[\s\S]{0,300}localStorage\.setItem/.test(appJs));
    check('app.js has no leftover bridge', !/storageBridge|StorageBridge/.test(appJs));
}

if (failures > 0) {
    console.error('\n' + failures + ' check(s) failed.');
    process.exit(1);
}
console.log('\nAll checks passed.');
