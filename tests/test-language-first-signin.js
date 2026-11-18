/**
 * tests/test-language-first-signin.js - a visitor's language choice survives first sign-in (A3, epic #2180)
 *
 * ELI5
 * ----
 * A visitor picks "French" while browsing. They then sign in for the first time.
 * Their new account has no language choice yet, and the server used to answer
 * "no languages" for both "never chose" and "chose all". The page took that as
 * the truth and threw French away. Now the server also says whether a choice
 * was EVER saved (`isSet`), and when it was not, the page sends French up to
 * the account instead.
 *
 * WHAT IT DRIVES: the real bootSongbookLanguageFilter() in jsdom with a fake
 * server, plus the pure decision function, plus a check that api.php really
 * sends `isSet`.
 *
 * MUTATION PROOF (rule #34): a throwaway sibling copy of the module with the
 * old "always adopt the account's list" line is driven the same way; it must
 * lose the visitor's choice, which proves the test can fail.
 *
 *   node tests/test-language-first-signin.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUB = path.join(__dirname, '..', 'appWeb', 'public_html');
const MODULES = path.join(PUB, 'js', 'modules');
const REAL = path.join(MODULES, 'songbook-language-filter.js');

let failed = 0;
const check = (label, cond) => { console.log((cond ? '  PASS  ' : '  FAIL  ') + label); if (!cond) failed++; };
const flush = (ms = 0) => new Promise((r) => setTimeout(r, ms));

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
const { window } = dom;
global.window = window;
global.document = window.document;
global.localStorage = window.localStorage;
global.Event = window.Event;
global.CustomEvent = window.CustomEvent;

/* ---- the pure decision ---- */
const { decideAccountLanguages } = await import(pathToFileURL(path.join(PUB, 'js', 'utils', 'language-tags.js')).href);
console.log('\n1 - the decision\n');
check('account never set + device has a choice -> push the device choice up',
    JSON.stringify(decideAccountLanguages({ languages: [], isSet: false }, ['fr'])) === JSON.stringify({ action: 'push', list: ['fr'] }));
check('account never set + device has none -> adopt (nothing to lose)',
    decideAccountLanguages({ languages: [], isSet: false }, []).action === 'adopt');
check('account has a saved list -> adopt it',
    JSON.stringify(decideAccountLanguages({ languages: ['de'], isSet: true }, ['fr'])) === JSON.stringify({ action: 'adopt', list: ['de'] }));
check('older server (no isSet) -> exactly the old behaviour: adopt',
    decideAccountLanguages({ languages: [], subtags: [] }, ['fr']).action === 'adopt');
check('no usable answer -> keep what the device has', decideAccountLanguages(null, ['fr']).action === 'keep');

/* ---- the server really sends isSet ---- */
console.log('\n2 - the server sends isSet\n');
{
    const src = fs.readFileSync(path.join(PUB, 'api.php'), 'utf8');
    const a = src.indexOf("case 'user_preferred_languages':");
    const b = src.indexOf("case 'user_preferred_languages_save':");
    const body = src.slice(a, b);
    check("api.php's user_preferred_languages answer carries 'isSet'", /'isSet'\s*=>/.test(body));
    check('isSet is true only for a saved (non-NULL, non-empty) value', /'isSet'\s*=>\s*\$raw !== null && \$raw !== ''/.test(body));
}

/* ---- the real module ---- */
let account = { languages: [], isSet: false };
let posts = [];
global.fetch = async (input, init = {}) => {
    const u = new URL(String(input), 'https://example.test/');
    const action = u.searchParams.get('action');
    const json = (obj) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => obj });
    if (action === 'user_preferred_languages') return json(account);
    if (action === 'user_preferred_languages_save') { posts.push(JSON.parse(String(init.body)).languages); return json({ ok: true }); }
    return json({});
};

async function run(modulePath, serverAnswer, local) {
    account = serverAnswer; posts = [];
    localStorage.clear();
    localStorage.setItem('ihymns_auth_token', 't');
    if (local) localStorage.setItem('songbook-language-filter', JSON.stringify(local));
    document.body.innerHTML =
        '<div data-songbook-language-filter><button><span class="js-lang-filter-trigger-label"></span><span class="js-lang-filter-count d-none"></span></button>'
        + '<div><label class="lang-filter-row lang-filter-row--all"><input type="checkbox" class="js-songbook-language-filter-all" checked></label>'
        + '<label class="lang-filter-row" data-search="fr"><input type="checkbox" class="js-songbook-language-filter-option" value="fr" data-lang-name="fr"></label></div>'
        + '<p class="d-none js-lang-filter-limit"></p><span data-lang-filter-status></span></div>';
    const m = await import(pathToFileURL(modulePath).href + '?t=' + Date.now() + Math.random());
    m.bootSongbookLanguageFilter(document);
    await flush(30);
    return { stored: JSON.parse(localStorage.getItem('songbook-language-filter') || '[]'), posts: [...posts] };
}

console.log('\n3 - the real picker, first sign-in\n');
const first = await run(REAL, { languages: [], isSet: false }, ['fr']);
check('the visitor keeps French on the device', JSON.stringify(first.stored) === '["fr"]');
check('and French is sent up to the account', JSON.stringify(first.posts) === '[["fr"]]');
const chosenAll = await run(REAL, { languages: ['de'], isSet: true }, ['fr']);
check('an account that already has a list still wins', JSON.stringify(chosenAll.stored) === '["de"]' && chosenAll.posts.length === 0);

console.log('\n4 - mutation proof (old behaviour restored)\n');
let mutant = null;
try {
    const src = fs.readFileSync(REAL, 'utf8');
    const line = 'const decision = decideAccountLanguages(j, loadSavedSubtags());';
    if (!src.includes(line)) throw new Error('mutation setup: line not found, update this test');
    mutant = path.join(MODULES, `zz_ihymns_test_lang_mutant_${Date.now()}_${Math.random().toString(16).slice(2)}.js`);
    fs.writeFileSync(mutant, src.replace(line, "const decision = { action: 'adopt', list: usablePreferenceList(j.languages) }; /* MUTATED */"));
    const bad = await run(mutant, { languages: [], isSet: false }, ['fr']);
    check('with the old "always adopt" line the visitor loses French (test can fail)', JSON.stringify(bad.stored) === '[]');
} finally {
    if (mutant) fs.rmSync(mutant, { force: true });
}

console.log(failed === 0 ? '\nAll first-sign-in language checks pass.' : `\n${failed} check(s) failed.`);
process.exit(failed === 0 ? 0 : 1);
