/**
 * tests/test-settings-pull-gate.js - settings are not re-fetched on every page change (A2, epic #2180)
 *
 * ELI5
 * ----
 * The app says "sign-in state changed" after every page change. Settings used
 * to react by downloading the account copy and writing it over what is on
 * screen, which could undo a theme change made a moment earlier. This test
 * drives the REAL Settings class (and the real list-sort module's gate) in
 * jsdom and checks: two announcements for one person cause ONE download; a
 * change made after that survives the next announcement; a different person
 * does download; and a change still waiting to be sent is sent BEFORE the
 * download, not after.
 *
 * MUTATION PROOF (rule #34): the same drive is run against a throwaway sibling
 * copy of settings.js with the gate check removed. It must then FAIL the
 * "one download" check, proving this test can tell fixed from broken. The
 * mutant is written next to the real file (so its imports resolve) and always
 * deleted in `finally`.
 *
 * Usage: node tests/test-settings-pull-gate.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';
import { createPullGate, PULL_GATE_MAX_AGE_MS } from '../appWeb/public_html/js/utils/pull-gate.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const JS = path.join(__dirname, '..', 'appWeb', 'public_html', 'js');
const MODULES_DIR = path.join(JS, 'modules');
const SETTINGS = path.join(MODULES_DIR, 'settings.js');

let failures = 0;
function assert(cond, label) {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label);
    if (!cond) failures++;
}

/* ---- the gate on its own (pure) ---- */
console.log('\n1 - the pull gate\n');
{
    let t = 1000;
    const g = createPullGate({ now: () => t });
    assert(g.begin(7) === true, 'first sight of a person: pull');
    assert(g.begin(7) === false, 'same person again straight away: no pull');
    assert(g.begin(8) === true, 'a different person: pull');
    t += PULL_GATE_MAX_AGE_MS - 1;
    assert(g.begin(8) === false, 'just under 30 minutes: no pull');
    t += 2;
    assert(g.begin(8) === true, '30 minutes later: pull again');
    g.fail();
    assert(g.begin(8) === true, 'after a failed pull the next announcement retries');
    g.reset();
    assert(g.begin(8) === true, 'after sign-out the next sign-in pulls');
    assert(g.begin(null) === false && g.begin('') === false && g.begin(undefined) === false, 'no person id: never pull');
}

/* ---- the real Settings class ---- */
async function drive(modulePath, opts = {}) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>',
        { runScripts: 'dangerously', url: 'https://example.test/' });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.localStorage = window.localStorage;
    global.CustomEvent = window.CustomEvent;
    global.requestAnimationFrame = (fn) => setTimeout(fn, 0);
    window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });

    /* A tiny fake server: remembers what was POSTed, answers GET with it. */
    let server = { ihymns_theme: 'light' };
    const calls = [];
    global.fetch = async (url, init = {}) => {
        const method = (init.method || 'GET').toUpperCase();
        calls.push(method + ' ' + String(url));
        if (method === 'POST' && opts.postStatus) {
            return new Response(JSON.stringify({ error: 'refused' }), { status: opts.postStatus });
        }
        if (method === 'POST' && opts.postNetworkError) { throw new TypeError('network down'); }
        if (method === 'POST') {
            server = JSON.parse(init.body).settings;
            return new Response(JSON.stringify({ ok: true }), { status: 200 });
        }
        return new Response(JSON.stringify({ ok: true, settings: server }), { status: 200 });
    };

    let user = { id: 1 };
    const app = {
        config: { apiUrl: '/api' },
        syncStorage() {},
        showToast() {},
        userAuth: {
            isLoggedIn: () => true,
            getUser: () => user,
            authHeaders: () => ({}),
        },
    };
    const mod = await import(pathToFileURL(modulePath).href + '?t=' + Date.now() + Math.random());
    const settings = new mod.Settings(app);
    settings.init();
    return { settings, calls, window, setUser: (u) => { user = u; } };
}

const { EVT_AUTH_CHANGED } = await import(pathToFileURL(path.join(JS, 'constants.js')).href);
console.log('\n2 - the real Settings class\n');
async function scenario(modulePath) {
    const env = await drive(modulePath);
    /* The event name must be the real one, not my guess above. */
    const realFire = async (u) => {
        env.setUser(u);
        env.window.document.dispatchEvent(new env.window.CustomEvent(EVT_AUTH_CHANGED, { detail: { loggedIn: true, user: u } }));
        await new Promise((r) => setTimeout(r, 40));
    };
    const gets = () => env.calls.filter((c) => c.startsWith('GET') && c.includes('namespace=ihymns.web')).length;

    await realFire({ id: 1 });
    await realFire({ id: 1 });
    const afterTwo = gets();

    /* Change the theme, then announce again for the same person. */
    env.settings.set('theme', 'dark');
    await realFire({ id: 1 });
    const themeAfterSameUser = env.window.localStorage.getItem('ihymns_theme');

    /* A different person, with a change still waiting to be sent. */
    env.settings.set('theme', 'high-contrast');
    env.calls.length = 0;
    await realFire({ id: 2 });
    const firstWrite = env.calls.findIndex((c) => c.startsWith('POST'));
    const firstRead = env.calls.findIndex((c) => c.startsWith('GET') && c.includes('namespace=ihymns.web'));
    return { afterTwo, themeAfterSameUser, firstWrite, firstRead, theme: env.window.localStorage.getItem('ihymns_theme') };
}

const good = await scenario(SETTINGS);
assert(good.afterTwo === 1, `two announcements for one person -> one download (got ${good.afterTwo})`);
assert(good.themeAfterSameUser === 'dark', 'a theme change survives the next announcement for the same person');
assert(good.firstWrite !== -1 && good.firstRead !== -1 && good.firstWrite < good.firstRead,
    'a waiting change is sent BEFORE the download for a new person');
assert(good.theme === 'high-contrast', 'and it is the new value that survives the pull');

console.log('\n2a - a refused upload must not shut the pull gate for ever\n');
/* Change a setting while the server refuses the upload, then announce a different person
   (who must be pulled). Does the download still happen? */
async function refusedUpload(modulePath, opts) {
    const env = await drive(modulePath, opts);
    const fire = async (u) => {
        env.setUser(u);
        env.window.document.dispatchEvent(new env.window.CustomEvent(EVT_AUTH_CHANGED, { detail: { loggedIn: true, user: u } }));
        await new Promise((r) => setTimeout(r, 40));
    };
    await fire({ id: 1 });
    env.settings.set('theme', 'dark');
    await env.settings._pushSyncedSettings();          /* the refused (or failed) upload */
    env.calls.length = 0;
    await fire({ id: 2 });
    return env.calls.filter((c) => c.startsWith('GET') && c.includes('namespace=ihymns.web')).length;
}
assert(await refusedUpload(SETTINGS, { postStatus: 413 }) >= 1, 'a 413 (too big) upload is dropped, so the next pull still happens');
assert(await refusedUpload(SETTINGS, { postStatus: 409 }) >= 1, 'a 409 (paused site) upload is dropped, so the next pull still happens');
assert(await refusedUpload(SETTINGS, { postStatus: 500 }) === 0, 'a 500 keeps the change waiting: the pull does NOT run ahead of it');
assert(await refusedUpload(SETTINGS, { postNetworkError: true }) === 0, 'a network error keeps the change waiting: the pull does NOT run ahead of it');

console.log('\n2b - list-sort uses the same gate\n');
{
    const ls = fs.readFileSync(path.join(MODULES_DIR, 'list-sort.js'), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '');
    assert(/createPullGate\(\)/.test(ls) && /_sortPullGate\.begin\(/.test(ls),
        'list-sort.js re-primes only through the shared pull gate');
    const noGateLs = ls.replace(/_sortPullGate\.begin\([^)]*\)/, 'true');
    assert(!/_sortPullGate\.begin\(/.test(noGateLs), 'mutation: removing the gate call is detected by this check');
}

console.log('\n3 - mutation proofs\n');
/** Write a throwaway mutated sibling of settings.js, run the scenario on it, always clean up. */
async function withMutant(from, to) {
    let mutant = null;
    try {
        const src = fs.readFileSync(SETTINGS, 'utf8');
        if (!src.includes(from)) throw new Error('mutation setup: line not found, update this test: ' + from);
        mutant = path.join(MODULES_DIR, `zz_ihymns_test_settings_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
        fs.writeFileSync(mutant, src.replace(from, to));
        return await scenario(mutant);
    } finally {
        if (mutant) fs.rmSync(mutant, { force: true });
    }
}
const noGate = await withMutant('if (!this._pullGate.begin(uid)) return;', '/* MUTATED: gate removed */');
assert(noGate.afterTwo === 2, `gate removed: two announcements download twice (got ${noGate.afterTwo})`);
const noFlush = await withMutant('if (this._syncPushPending) {', 'if (false) {');
assert(noFlush.theme !== 'high-contrast', `send-first removed: the waiting change is overwritten by the old account value (got ${noFlush.theme})`);

/* mutation: any failed response keeps the change waiting (the old behaviour) */
{
    let mutant = null;
    try {
        const src = fs.readFileSync(SETTINGS, 'utf8');
        const from = 'if (res.status === undefined || res.status >= 500) {';
        if (!src.includes(from)) throw new Error('mutation setup: line not found, update this test');
        mutant = path.join(MODULES_DIR, `zz_ihymns_test_settings_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
        fs.writeFileSync(mutant, src.replace(from, 'if (true) { /* MUTATED: every failure keeps waiting */'));
        assert(await refusedUpload(mutant, { postStatus: 413 }) === 0, 'old behaviour (any failure keeps waiting): a 413 shuts the pull gate (the test can fail)');
    } finally {
        if (mutant) fs.rmSync(mutant, { force: true });
    }
}

console.log(failures === 0 ? '\nAll pull-gate checks pass.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
