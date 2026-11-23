/**
 * tests/test-account-delete-ui.js - "Delete my account" on the web asks twice and explains every refusal (PR 2c-2, epic #2180)
 *
 * ELI5
 * ----
 * This plays the real "Delete my account" panel in a pretend browser (jsdom) with a pretend server. It checks that nothing is
 * sent until the person has typed DELETE, that the password (or email code) goes with it, that every kind of refusal is
 * explained in plain words chosen by the HTTP status and reason code (never the server's wording), that a refusal keeps the
 * person signed in with their saved songs, and that a successful delete clears this browser and signs out.
 *
 * Detailed (rule #34): the markup comes from the REAL settings.php, the clearing is done by the REAL UserAuth, and the
 * statuses the page explains are the statuses the REAL account_delete case in api.php can answer (read from the source, so a
 * new status there that this page does not explain fails here). Mutation proofs (recorded in the 2c-2 commit): the typed
 * word not required (the "nothing is sent" row goes red), the 409 branch ignoring the reason (the paused-site row goes red),
 * the browser not cleared after success (the clearing row goes red).
 *
 * Usage: node tests/test-account-delete-ui.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUB = path.join(__dirname, '..', 'appWeb', 'public_html');
const MODULES = path.join(PUB, 'js', 'modules');
const url = (p) => pathToFileURL(p).href;
let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + String(detail).slice(0, 300)));
    if (!cond) failures++;
};
const flush = (ms = 30) => new Promise((r) => setTimeout(r, ms));

const settingsPhp = fs.readFileSync(path.join(PUB, 'includes', 'pages', 'settings.php'), 'utf8');
const apiSrc = fs.readFileSync(path.join(PUB, 'api.php'), 'utf8');
const cardHtml = (/<div class="card card-settings[^"]*" id="settings-account-data-card" data-account-data-card>[\s\S]*?id="account-delete-cancel">Cancel<\/button>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/.exec(settingsPhp) || [''])[0];

const M = await import(url(path.join(MODULES, 'account-data.js')));
const C = await import(url(path.join(PUB, 'js', 'constants.js')));
const api = await import(url(path.join(PUB, 'js', 'utils', 'api-client.js')));
api.setAuthHeaderProvider(() => ({ Authorization: 'Bearer t' }));

console.log('\nthe markup\n');
check('the delete panel is in the card, closed until asked, with a password box, an email-code route, the typed word and a disabled button',
    cardHtml.includes('id="account-delete-panel" class="d-none') && cardHtml.includes('id="account-delete-password"') && cardHtml.includes('id="account-delete-code"')
    && cardHtml.includes('id="account-delete-typed"') && /id="account-delete-go" disabled/.test(cardHtml));
check('there is no inline script (rule #30)', !/<script/i.test(cardHtml));
check('the password box tells the browser it is a current password, and the code box that it is a one-time code', cardHtml.includes('autocomplete="current-password"') && cardHtml.includes('autocomplete="one-time-code"'));

console.log('\nthe pure rules\n');
check('only the exact word counts (spaces around it are fine)', ['DELETE', ' DELETE '].every(M.deleteConfirmationOk) && ['delete', 'Delete', 'DELET', '', null, undefined, 'DELETE ME'].every((x) => !M.deleteConfirmationOk(x)));
check('the body carries the password and the typed word', JSON.stringify(M.buildDeleteBody({ password: 'pw', typed: 'DELETE' })) === JSON.stringify({ password: 'pw', confirm: 'DELETE' }));
check('with no password the email code is used instead', JSON.stringify(M.buildDeleteBody({ emailCode: ' 123456 ', typed: 'DELETE' })) === JSON.stringify({ email_code: '123456', confirm: 'DELETE' }));
check('the typed word is never claimed when it was not typed', !('confirm' in M.buildDeleteBody({ password: 'pw', typed: 'nope' })));
const statuses = [400, 401, 403, 409, 429, 503, 500];
check('every refusal has its own sentence, chosen by status', new Set(statuses.map((s) => M.deleteMessageForResponse(s))).size === statuses.length);
check('a paused site (409 with its reason) is explained differently from "you are the only administrator" (409 without)',
    M.deleteMessageForResponse(409, C.ACCOUNT_WRITES_OFF_REASON) !== M.deleteMessageForResponse(409, null) && /paused/i.test(M.deleteMessageForResponse(409, C.ACCOUNT_WRITES_OFF_REASON)) && /administrator/i.test(M.deleteMessageForResponse(409, null)));
/* The server's own statuses: read from the real case, so one added there must be explained here. */
const a = apiSrc.indexOf("case 'account_delete'");
const b = apiSrc.indexOf("\n        case '", a + 10);
const caseText = apiSrc.slice(a, b);
const served = [...new Set([...caseText.matchAll(/sendJson\([^;]*?,\s*(\d{3})\)/g)].map((m) => Number(m[1])))].filter((n) => n >= 400);
const explained = new Set([400, 401, 403, 409, 429, 503]);
check('every error status account_delete can answer is one this page explains (' + served.join(', ') + ')', served.length >= 4 && served.every((n) => explained.has(n) || n === 405));

console.log('\nsending\n');
const respond = (status, body = {}) => ({ ok: status >= 200 && status < 300, status, clone() { return this; }, json: async () => body, headers: { get: () => null } });
{
    const calls = [];
    const r = await M.deleteAccount({ password: 'pw', typed: 'nope' }, { request: async (...x) => { calls.push(x); return respond(200); } });
    check('nothing is sent unless the word was typed', calls.length === 0 && r.ok === false && /DELETE/.test(r.message));
}
{
    const calls = [];
    const r = await M.deleteAccount({ password: 'pw', typed: 'DELETE' }, { request: async (u, init) => { calls.push({ u, init }); return respond(200, { ok: true, deleted: true }); } });
    check('a confirmed request is a POST to account_delete with the sign-in and the body', r.ok === true && calls.length === 1 && calls[0].u === '/api?action=account_delete'
        && calls[0].init.method === 'POST' && calls[0].init.auth === true && JSON.parse(calls[0].init.body).password === 'pw' && JSON.parse(calls[0].init.body).confirm === 'DELETE');
}
for (const [status, body, word] of [[401, {}, /did not match/i], [409, { reason: C.ACCOUNT_WRITES_OFF_REASON }, /paused/i], [409, { error: 'Transfer Global Admin to another account first.' }, /administrator/i], [429, {}, /wait/i], [503, {}, /nothing has been changed/i]]) {
    const r = await M.deleteAccount({ password: 'pw', typed: 'DELETE' }, { request: async () => respond(status, body) });
    check(`a ${status}${body.reason ? ' (' + body.reason + ')' : ''} is explained in plain words`, r.ok === false && r.status === status && word.test(r.message), r.message);
}
{
    const r = await M.deleteAccount({ password: 'pw', typed: 'DELETE' }, { request: async () => { throw new TypeError('offline'); } });
    check('offline: nothing changed, and it says so', r.ok === false && r.status === 0 && /nothing has been changed/i.test(r.message));
}
{
    const sent = [];
    const ok = await M.requestEmailCode('me@example.test', { request: async (u, init) => { sent.push({ u, body: JSON.parse(init.body) }); return respond(200, { ok: true }); } });
    check('the email code is asked for with the address, and the answer does not reveal whether the address exists', ok.ok === true && sent[0].u === '/api?action=auth_email_login_request' && sent[0].body.email === 'me@example.test' && /if that address/i.test(ok.message));
    check('an empty address asks nothing', (await M.requestEmailCode('', { request: async () => { throw new Error('should not be called'); } })).ok === false);
}

console.log('\nthe panel in a browser\n');
async function page({ status = 200, body = {} } = {}) {
    const dom = new JSDOM(`<!doctype html><html><body>${cardHtml}</body></html>`, { url: 'https://example.test/settings' });
    const { window } = dom;
    Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
    globalThis.requestAnimationFrame = (f) => setTimeout(f, 0);
    const requests = [];
    globalThis.fetch = async (input, init = {}) => {
        const u = new URL(String(input), 'https://example.test/');
        requests.push({ action: u.searchParams.get('action'), method: init.method, body: init.body ? JSON.parse(init.body) : null, headers: init.headers });
        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    };
    /* the REAL UserAuth, so the clearing is the real clearing */
    const { UserAuth } = await import(url(path.join(MODULES, 'user-auth.js')) + '?t=' + Math.random());
    const app = { config: { apiUrl: '/api' }, syncStorage() {}, toasts: [], showToast(m) { this.toasts.push(m); }, router: { navigated: [], navigate(p) { this.navigated.push(p); } } };
    app.userAuth = new UserAuth(app);
    app.userAuth.saveCredentials('tok', { id: 7, username: 'ann', email: 'ann@example.test' });
    window.localStorage.setItem(C.STORAGE_FAVORITES, JSON.stringify([{ id: 'MP-1', tags: [] }]));
    window.localStorage.setItem(C.STORAGE_SETLISTS, JSON.stringify([{ id: 'sl-1', name: 'Mine', songs: [] }]));
    window.localStorage.setItem(C.STORAGE_CUSTOM_TAGS, JSON.stringify(['Sunday']));
    window.localStorage.setItem('ihymns_theme', 'dark');   /* a display setting, not personal data */
    window.iHymnsApp = app;
    M.bootAccountDataCard();
    const $ = (id) => window.document.getElementById(id);
    return { window, app, requests, $ };
}
{
    const { window, requests, $ } = await page();
    check('the panel starts closed and the delete button starts disabled', $('account-delete-panel').classList.contains('d-none') && $('account-delete-go').disabled === true);
    $('account-delete-open').click();
    check('opening the panel shows it and says so for assistive technology, and puts the email in the code route', !$('account-delete-panel').classList.contains('d-none') && $('account-delete-open').getAttribute('aria-expanded') === 'true' && $('account-delete-email').value === 'ann@example.test');
    $('account-delete-password').value = 'pw';
    $('account-delete-typed').value = 'delet';
    $('account-delete-typed').dispatchEvent(new window.Event('input'));
    check('the button stays disabled until the word is typed in full', $('account-delete-go').disabled === true);
    $('account-delete-go').click();
    await flush();
    check('a click on the disabled button sends nothing', requests.length === 0);
    $('account-delete-typed').value = 'DELETE';
    $('account-delete-typed').dispatchEvent(new window.Event('input'));
    check('typing the word enables the button', $('account-delete-go').disabled === false);
    $('account-delete-cancel').click();
    check('cancel closes the panel and wipes what was typed', $('account-delete-panel').classList.contains('d-none') && $('account-delete-password').value === '' && $('account-delete-typed').value === '' && $('account-delete-go').disabled === true);
    check('cancelling sent nothing and kept the person signed in', requests.length === 0 && window.iHymnsApp.userAuth.isLoggedIn());
}
{
    const { window, app, requests, $ } = await page({ status: 409, body: { reason: C.ACCOUNT_WRITES_OFF_REASON } });
    $('account-delete-open').click();
    $('account-delete-password').value = 'pw'; $('account-delete-typed').value = 'DELETE';
    $('account-delete-typed').dispatchEvent(new window.Event('input'));
    $('account-delete-go').click();
    await flush(60);
    check('one request, to account_delete, with the same-origin header, the password and the typed word', requests.length === 1 && requests[0].action === 'account_delete' && requests[0].method === 'POST'
        && requests[0].headers.get('X-Requested-With') === 'XMLHttpRequest' && requests[0].body.password === 'pw' && requests[0].body.confirm === 'DELETE', JSON.stringify(requests));
    check('a refusal (paused site) is explained on the page', /paused/i.test($('account-delete-msg').textContent) && !$('account-delete-msg').classList.contains('d-none'));
    check('a refusal keeps the person signed in with all their saved songs', app.userAuth.isLoggedIn() && JSON.parse(window.localStorage.getItem(C.STORAGE_FAVORITES)).length === 1 && app.router.navigated.length === 0);
    check('the button works again after a refusal', $('account-delete-go').disabled === false);
}
{
    const { window, app, requests, $ } = await page({ status: 200, body: { ok: true, deleted: true } });
    $('account-delete-open').click();
    $('account-delete-password').value = 'pw'; $('account-delete-typed').value = 'DELETE';
    $('account-delete-typed').dispatchEvent(new window.Event('input'));
    $('account-delete-go').click();
    await flush(60);
    check('a successful delete signs this browser out', requests.length === 1 && !app.userAuth.isLoggedIn());
    const ls = window.localStorage;
    check("...and clears the person's saved songs, set lists and tags from this browser (and puts no copy aside)",
        ls.getItem(C.STORAGE_FAVORITES) === null && ls.getItem(C.STORAGE_SETLISTS) === null && ls.getItem(C.STORAGE_CUSTOM_TAGS) === null
        && !Array.from({ length: ls.length }, (_, i) => ls.key(i)).some((k) => k.startsWith(C.STORAGE_STASH_PREFIX)));
    check('...but leaves display settings alone', ls.getItem('ihymns_theme') === 'dark');
    check('...and takes the person to the home page with a toast', app.router.navigated.join() === '/' && app.toasts.length === 1);
}

if (failures) { console.log(`\n${failures} failure(s)`); process.exit(1); }
console.log('\nall passed');
