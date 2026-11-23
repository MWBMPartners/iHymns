/**
 * tests/test-account-export-ui.js - the "Download everything we hold about you" button (PR 2c-1, epic #2180)
 *
 * ELI5
 * ----
 * This plays the real "Your data" card module in a pretend browser (jsdom) with a pretend server. It checks that the
 * button asks for the file with a POST (and the sign-in), saves what comes back under a safe name, explains each kind
 * of failure in plain words chosen by the HTTP status (never by the server's wording), and that the card only shows
 * when someone is signed in.
 *
 * Detailed (rule #34): the markup is read from the REAL settings.php fragment (so a renamed id fails here), the router
 * wiring is checked from router.js, and the pure helpers are called with awkward input (a hostile file name in the
 * header). Mutation proofs (recorded in the 2c-1 commit): the status switch removed (the 429 row goes red), the file
 * name taken unchecked from the header (the hostile name row goes red), the card shown to a signed-out visitor.
 *
 * Usage: node tests/test-account-export-ui.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUB = path.join(__dirname, '..', 'appWeb', 'public_html');
const url = (p) => pathToFileURL(p).href;
let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + String(detail).slice(0, 300)));
    if (!cond) failures++;
};
const flush = (ms = 20) => new Promise((r) => setTimeout(r, ms));

const settingsPhp = fs.readFileSync(path.join(PUB, 'includes', 'pages', 'settings.php'), 'utf8');
const router = fs.readFileSync(path.join(PUB, 'js', 'modules', 'router.js'), 'utf8');
const cardHtml = (/<div class="card card-settings[^"]*" id="settings-account-data-card" data-account-data-card>[\s\S]*?<\/button>\s*<\/div>\s*<\/div>/.exec(settingsPhp) || [''])[0];

console.log('\nthe page and the wiring\n');
check('the settings page carries the card, hidden until signed in, with its button and message area',
    cardHtml.includes('d-none') && cardHtml.includes('id="account-export-btn"') && cardHtml.includes('id="account-data-msg"'));
check('the card has no inline script (rule #30)', !/<script/i.test(cardHtml));
check('the router imports the module after the settings page loads', /import\('\.\/account-data\.js'\)[\s\S]{0,80}bootAccountDataCard/.test(router));

const M = await import(url(path.join(PUB, 'js', 'modules', 'account-data.js')));
const api = await import(url(path.join(PUB, 'js', 'utils', 'api-client.js')));
api.setAuthHeaderProvider(() => ({ Authorization: 'Bearer t' }));   /* what app.js does at start-up */

console.log('\nthe pure helpers\n');
check('each failure kind has its own plain sentence, chosen by status',
    new Set([401, 403, 429, 503, 500].map((s) => M.exportMessageForStatus(s))).size === 5);
check('a 429 tells the person to wait', /wait/i.test(M.exportMessageForStatus(429)));
check('the server\'s own wording is never needed', M.exportMessageForStatus(401, 'ignored') === M.exportMessageForStatus(401));
const day = new Date(Date.UTC(2026, 10, 23));
check('the suggested file name is used when it is plain', M.exportFilenameFrom('attachment; filename="ihymns-account-export-20261123.json"', day) === 'ihymns-account-export-20261123.json');
check('a hostile file name is not used (path tricks, wrong extension, quotes)',
    ['attachment; filename="../../etc/passwd"', 'attachment; filename="evil.exe"', 'attachment; filename="a b.json"', null, ''].every((h) => M.exportFilenameFrom(h, day) === 'ihymns-account-export-20261123.json'));

/* ---------- the pretend browser ---------- */
function browser({ loggedIn = true } = {}) {
    const dom = new JSDOM(`<!doctype html><html><body>${cardHtml}</body></html>`, { url: 'https://example.test/settings' });
    const { window } = dom;
    Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, CustomEvent: window.CustomEvent, Event: window.Event });
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
    globalThis.requestAnimationFrame = (f) => setTimeout(f, 0);
    const saved = [];
    window.URL.createObjectURL = (b) => { saved.push({ size: b.size }); return 'blob:test/1'; };
    window.URL.revokeObjectURL = () => {};
    window.HTMLAnchorElement.prototype.click = function () { saved[saved.length - 1].download = this.download; saved[saved.length - 1].href = this.href; };
    window.iHymnsApp = { userAuth: { isLoggedIn: () => loggedIn, authHeaders: () => ({ Authorization: 'Bearer t' }) } };
    return { window, saved };
}

console.log('\nthe download\n');
{
    const { window, saved } = browser();
    const calls = [];
    const request = async (u, init) => {
        calls.push({ u, init });
        return { ok: true, status: 200, blob: async () => new window.Blob(['{"a":1}']), headers: { get: (h) => (h === 'Content-Disposition' ? 'attachment; filename="ihymns-account-export-20261123.json"' : null) } };
    };
    const r = await M.downloadAccountExport({ doc: window.document, win: window, request });
    check('it asks with a POST and sends the sign-in', calls.length === 1 && calls[0].u === '/api?action=account_export' && calls[0].init.method === 'POST' && calls[0].init.auth === true);
    check('it saves the file under the suggested name', r.ok === true && saved.length === 1 && saved[0].download === 'ihymns-account-export-20261123.json' && saved[0].size === 7);
    check('it leaves no stray link in the page', window.document.querySelectorAll('a[download]').length === 0);
}
for (const [status, word] of [[401, /signed in/i], [403, /reload/i], [429, /wait/i], [503, /few minutes/i], [500, /try again/i]]) {
    const { window, saved } = browser();
    const r = await M.downloadAccountExport({ doc: window.document, win: window, request: async () => ({ ok: false, status, blob: async () => new window.Blob([]), headers: { get: () => null } }) });
    check(`a ${status} saves nothing and explains in plain words`, r.ok === false && r.status === status && word.test(r.message) && saved.length === 0);
}
{
    const { window, saved } = browser();
    const r = await M.downloadAccountExport({ doc: window.document, win: window, request: async () => { throw new TypeError('network'); } });
    check('being offline saves nothing and says so', r.ok === false && r.status === 0 && /offline/i.test(r.message) && saved.length === 0);
}

console.log('\nthe card\n');
{
    const { window } = browser({ loggedIn: false });
    M.bootAccountDataCard();
    const card = window.document.querySelector('[data-account-data-card]');
    check('a signed-out visitor does not see the card', card.classList.contains('d-none'));
    window.iHymnsApp.userAuth.isLoggedIn = () => true;
    window.document.dispatchEvent(new window.CustomEvent((await import(url(path.join(PUB, 'js', 'constants.js')))).EVT_AUTH_CHANGED));
    check('it appears when someone signs in', !card.classList.contains('d-none'));
}
{
    const { window, saved } = browser({ loggedIn: true });
    const seen = [];
    globalThis.fetch = async (input, init = {}) => {
        seen.push({ url: String(input), method: init.method, headers: init.headers });
        return new Response('{"a":1}', { status: 200, headers: { 'Content-Disposition': 'attachment; filename="ihymns-account-export-20261123.json"' } });
    };
    M.bootAccountDataCard();
    M.bootAccountDataCard();   /* a second visit to the page boots the same card again */
    check('a signed-in person sees the card', !window.document.querySelector('[data-account-data-card]').classList.contains('d-none'));
    window.document.getElementById('account-export-btn').click();
    await flush(60);
    check('one click makes exactly one POST (booting twice did not double the handler), carrying the same-origin header and the sign-in',
        seen.length === 1 && seen[0].method === 'POST' && seen[0].url.includes('action=account_export')
        && seen[0].headers.get('X-Requested-With') === 'XMLHttpRequest' && seen[0].headers.get('Authorization') === 'Bearer t', JSON.stringify(seen.map((x) => x.url)));
    check('the person is told it worked and the button is usable again', /downloaded/i.test(window.document.getElementById('account-data-msg').textContent) && window.document.getElementById('account-export-btn').disabled === false && saved.length === 1);
    window.document.body.innerHTML = '';
    check('on a page without the card nothing is bound and nothing throws', (() => { try { M.bootAccountDataCard(); return true; } catch (_e) { return false; } })());
}

if (failures) { console.log(`\n${failures} failure(s)`); process.exit(1); }
console.log('\nall passed');
