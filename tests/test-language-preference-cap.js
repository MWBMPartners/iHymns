/**
 * tests/test-language-preference-cap.js — a person can choose at most 32
 * languages, is told so plainly, and the page shows what the account kept
 * (#2137 review round 5)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The server uses only the first 32 preferred languages (every one adds to the
 * database query that filters songs; round 4 of the review capped it). Before
 * round 5 the settings page let a person tick 40, and the last 8 were quietly
 * ignored — the grids on the home and songbooks pages filtered by all 40 while
 * the server-filtered lists used 32. The lead's decision: the picker allows at
 * most 32; ticking a 33rd does not tick it and says "You can choose up to 32
 * languages. Untick one to add another."; the page uses the save's answer (what
 * the account kept) and shows it; the grids and the server-filtered lists then
 * agree.
 *
 * WHAT IT CHECKS
 *  - the browser's limit is the server's (read from includes/language_filter.php,
 *    never typed in here), and usablePreferenceList() keeps the first 32 in order;
 *  - the REAL settings picker (js/modules/settings-language-filter.js) in jsdom:
 *    32 ticks are saved; the 33rd is refused, un-ticked, not saved, and the exact
 *    message is shown; unticking one clears the message and lets another in;
 *  - the save's answer: when the account keeps something different (a tag
 *    tidied, a language dropped), the page stores and ticks exactly that and
 *    says how many were kept; with no answer (signed out) nothing changes; a
 *    slow answer to an OLDER save never undoes a newer choice;
 *  - a list saved before the limit (40 entries): the picker shows the first 32,
 *    the request header sends the first 32 (preferredLanguagesCsv()), and a 33rd
 *    tick is refused;
 *  - the home/songbooks grid (js/modules/songbook-language-filter.js): it filters
 *    by the same first 32 (a tile in the 33rd language is hidden, as the server
 *    would hide it), and its dropdown refuses a 33rd tick with the same words;
 *  - the song page's translation picker (js/modules/song-translations.js,
 *    round 6): with 33 saved languages, the 33rd (French) is not moved to the
 *    top — only the first 32 count, as everywhere else.
 *
 *   node tests/test-language-preference-cap.js
 *
 * Mutation-proven (see the commit body): removing the picker's check, ignoring
 * the save's answer, dropping the "newest save only" guard, the list not being
 * cut to 32, the header not using it, and removing the grid's check each turn
 * checks red; so does the translation picker reading the whole saved list
 * again (round 6).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUB = path.join(__dirname, '..', 'appWeb', 'public_html');
const mod = (rel) => pathToFileURL(path.join(PUB, rel)).href;

let passed = 0;
let failed = 0;
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log('  PASS  ' + label); }
    else { failed++; console.log('  FAIL  ' + label + (detail ? ' — ' + detail : '')); }
}
const flush = (ms = 0) => new Promise((r) => setTimeout(r, ms));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

console.log('tests/test-language-preference-cap.js — at most 32 languages, and the page shows what the account kept');

/* ---- a fake page ---- */
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/settings' });
const { window } = dom;
global.window = window;
global.document = window.document;
global.localStorage = window.localStorage;
global.Event = window.Event;
global.CustomEvent = window.CustomEvent;

/* 40 languages the catalogue "has", named by their code (so the picker's A-Z
   order is the code order). */
const CODES = ['af', 'am', 'ar', 'az', 'be', 'bg', 'bn', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'en', 'es',
    'et', 'eu', 'fa', 'fi', 'fr', 'ga', 'gl', 'gu', 'he', 'hi', 'hr', 'hu', 'hy', 'id', 'is', 'it', 'ja', 'ka',
    'kk', 'km', 'kn', 'ko', 'ky', 'pt'];

/* The fake server. `serverKeeps` decides what an account save keeps (by
   default: tidies `pt-br` to `pt-BR` and keeps the first 32, like
   parsePreferredLanguageSubtags()); `serverDelays` holds per-save delays. */
let saves = [];
let serverKeeps = (list) => list.map((t) => (t.toLowerCase() === 'pt-br' ? 'pt-BR' : t)).slice(0, 32);
let serverDelays = [];
global.fetch = async (input, init = {}) => {
    const u = new URL(String(input), 'https://example.test/');
    const action = u.searchParams.get('action');
    const json = (obj) => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => obj });
    if (action === 'catalogue_language_subtags') {
        return json({ subtags: CODES, names: Object.fromEntries(CODES.map((c) => [c, c])) });
    }
    if (action === 'user_preferred_languages_save') {
        const body = JSON.parse(String(init.body || '{}'));
        const n = saves.push(body.languages);
        const delay = serverDelays[n - 1] || 0;
        if (delay) await flush(delay);
        return json({ ok: true, languages: serverKeeps(body.languages), subtags: [] });
    }
    return json({});
};

const tags = await import(mod('js/utils/language-tags.js'));
const { preferredLanguagesCsv } = await import(mod('js/utils/api-client.js'));
const { bootSettingsLanguageFilter } = await import(mod('js/modules/settings-language-filter.js'));
const { bootSongbookLanguageFilter } = await import(mod('js/modules/songbook-language-filter.js'));

/* ---- 1. the number and the list, no page ---- */
const phpSrc = fs.readFileSync(path.join(PUB, 'includes', 'language_filter.php'), 'utf8');
const phpMax = Number((/const IHYMNS_LANGUAGE_FILTER_MAX_PREFERENCES = (\d+);/.exec(phpSrc) || [])[1]);
check(`the browser's limit (${tags.MAX_PREFERENCES}) is the server's (${phpMax}, read from includes/language_filter.php)`,
    tags.MAX_PREFERENCES === phpMax && phpMax === 32);
check('the message is the lead\'s wording, exactly',
    tags.TOO_MANY_LANGUAGES_MESSAGE === 'You can choose up to 32 languages. Untick one to add another.');
check('usablePreferenceList() keeps the first 32 tag-shaped entries, in order',
    same(tags.usablePreferenceList(['bad tag', ...CODES]), CODES.slice(0, 32)));
check('…and anything that is not a list is no list', same(tags.usablePreferenceList('en'), []) && same(tags.usablePreferenceList(null), []));

/* ---- helpers for the settings picker ---- */
const STORAGE = 'songbook-language-filter';
const stored = () => { try { return JSON.parse(localStorage.getItem(STORAGE) || '[]'); } catch (_e) { return null; } };
async function bootSettings({ saved = null, signedIn = false } = {}) {
    localStorage.clear();
    if (saved) localStorage.setItem(STORAGE, JSON.stringify(saved));
    if (signedIn) localStorage.setItem('ihymns_auth_token', 'test-token');
    document.body.innerHTML = '<div data-settings-language-filter></div>';
    const host = document.querySelector('[data-settings-language-filter]');
    bootSettingsLanguageFilter(document);
    await flush(10);
    const box = (code) => host.querySelector('#settings-lang-' + code);
    const tick = async (code, on = true) => {
        const cb = box(code);
        cb.checked = on;
        cb.dispatchEvent(new window.Event('change'));
        await flush(5);
        return cb.checked;
    };
    const message = () => host.querySelector('[data-settings-lang-message]').textContent;
    const ticked = () => CODES.filter((c) => box(c) && box(c).checked);
    return { host, box, tick, message, ticked };
}

/* ---- 2. the settings picker: at most 32 ---- */
{
    saves = [];
    const p = await bootSettings();
    check('the picker drew all 40 languages, with "All" ticked', CODES.every((c) => p.box(c)) && p.host.querySelector('#settings-lang-all').checked);
    for (const c of CODES.slice(0, 32)) { await p.tick(c); }
    check('32 languages ticked one by one are all kept, in the order chosen', same(stored(), CODES.slice(0, 32)), JSON.stringify(stored()));
    check('…with no message', p.message() === '');
    const before = stored();
    const stayed = await p.tick(CODES[32]);
    check('ticking a 33rd does NOT tick it', stayed === false && !p.box(CODES[32]).checked);
    check('…and says, in plain words: "You can choose up to 32 languages. Untick one to add another."',
        p.message() === 'You can choose up to 32 languages. Untick one to add another.', p.message());
    check('…and saves nothing (the stored list is unchanged, still 32)', same(stored(), before) && stored().length === 32);
    check('…and the request header still sends those 32', preferredLanguagesCsv().split(',').length === 32);
    await p.tick(CODES[0], false);
    check('unticking one clears the message', p.message() === '');
    const took = await p.tick(CODES[32]);
    check('…and then another language can be added (still 32)', took === true && stored().length === 32 && stored().includes(CODES[32]));
    check('signed out, nothing was sent to an account', saves.length === 0);
}

/* ---- 3. the save's answer is what the page shows ---- */
{
    saves = [];
    serverKeeps = (list) => list.filter((t) => t !== 'am').map((t) => (t.toLowerCase() === 'pt-br' ? 'pt-BR' : t));
    const p = await bootSettings({ saved: ['pt-br'], signedIn: true });
    check('a saved `pt-br` ticks Portuguese', same(p.ticked(), ['pt']));
    await p.tick('es');
    await flush(20);
    check('signed in, the list was sent to the account', saves.length === 1 && same(saves[0], ['pt-br', 'es']), JSON.stringify(saves));
    check('the account tidied `pt-br` to `pt-BR`: the page stores what it kept', same(stored(), ['pt-BR', 'es']), JSON.stringify(stored()));
    check('…with no message (nothing was left out)', p.message() === '');
    await p.tick('am');
    await flush(20);
    check('the account did not keep `am` (a language it does not recognise here): the page stores the kept list',
        same(stored(), ['pt-BR', 'es']), JSON.stringify(stored()));
    check('…un-ticks Amharic, so the boxes show what was kept', !p.box('am').checked && same(p.ticked(), ['es', 'pt']), JSON.stringify(p.ticked()));
    check('…and says how many were kept', p.message().startsWith('Saved 2 of the 3 languages you chose'), p.message());
    check('…and the request header sends the kept list', preferredLanguagesCsv() === 'pt-BR,es', preferredLanguagesCsv());
}

/* ---- 4. a slow answer to an older save never undoes a newer choice ---- */
{
    saves = [];
    serverKeeps = (list) => list.filter((t) => t !== 'am');
    serverDelays = [60, 0];
    const p = await bootSettings({ signedIn: true });
    await p.tick('am');   // save 1: ['am'] — the account keeps [], answered LATE
    await p.tick('ar');   // save 2: ['am', 'ar'] — the account keeps ['ar'], answered at once
    await flush(120);
    check('two saves, the first answered after the second', saves.length === 2);
    check('the newest answer decides: the account kept ["ar"], and that is what is stored and ticked',
        same(stored(), ['ar']) && same(p.ticked(), ['ar']), JSON.stringify([stored(), p.ticked()]));
    serverDelays = [];
}

/* ---- 5. a list saved before the limit ---- */
{
    saves = [];
    serverKeeps = (list) => list.slice(0, 32);
    const p = await bootSettings({ saved: CODES });
    check('a stored list of 40: the picker ticks only the first 32', same(p.ticked(), CODES.slice(0, 32)), String(p.ticked().length));
    check('…and the request header sends only the first 32, as the server would use them',
        preferredLanguagesCsv() === CODES.slice(0, 32).join(','));
    const stayed = await p.tick(CODES[35]);
    check('…and a 33rd tick is refused with the message', stayed === false && p.message().startsWith('You can choose up to 32'));
}

/* ---- 6. the home / songbooks grid ---- */
{
    localStorage.clear();
    localStorage.setItem(STORAGE, JSON.stringify(CODES));   // 40, saved before the limit
    const opts = CODES.map((c) => `<label class="lang-filter-row" data-search="${c}"><input type="checkbox" class="js-songbook-language-filter-option" value="${c}" data-lang-name="${c}"></label>`).join('');
    document.body.innerHTML =
        '<div data-songbook-language-filter>'
        + '<button><span class="js-lang-filter-trigger-label"></span><span class="js-lang-filter-count d-none"></span></button>'
        + '<div><label class="lang-filter-row lang-filter-row--all"><input type="checkbox" class="js-songbook-language-filter-all" checked></label>'
        + opts + '</div>'
        + '<p class="d-none js-lang-filter-limit"></p>'
        + '<span data-lang-filter-status></span></div>'
        + `<div class="col"><div class="card-songbook" data-songbook-id="A" data-songbook-language="${CODES[0]}"></div></div>`
        + `<div class="col"><div class="card-songbook" data-songbook-id="Z" data-songbook-language="${CODES[35]}"></div></div>`;
    bootSongbookLanguageFilter(document);
    await flush(5);
    const gridBox = (c) => document.querySelector(`.js-songbook-language-filter-option[value="${c}"]`);
    const gridTicked = CODES.filter((c) => gridBox(c).checked);
    check('the grid ticks only the first 32 of a stored list of 40', same(gridTicked, CODES.slice(0, 32)), String(gridTicked.length));
    const tile = (id) => document.querySelector(`[data-songbook-id="${id}"]`).closest('.col');
    check('a songbook in the 1st language is shown', tile('A').style.display !== 'none');
    check('a songbook in the 36th language is hidden — as the server-filtered lists hide it', tile('Z').style.display === 'none');
    const cb = gridBox(CODES[35]);
    cb.checked = true;
    cb.dispatchEvent(new window.Event('change'));
    await flush(5);
    const note = document.querySelector('.js-lang-filter-limit');
    check('the grid\'s dropdown refuses a 33rd tick', cb.checked === false);
    check('…and shows the same plain words', !note.classList.contains('d-none')
        && note.textContent === 'You can choose up to 32 languages. Untick one to add another.', note.textContent);
    check('…which a screen reader hears too', document.querySelector('[data-lang-filter-status]').textContent === note.textContent);
    const first = gridBox(CODES[0]);
    first.checked = false;
    first.dispatchEvent(new window.Event('change'));
    await flush(5);
    check('unticking one hides the note again', note.classList.contains('d-none'));
}

/* ---- 7. the song page's translation picker (#2137 review round 6, finding 5) ----
   It puts the reader's languages first. It used to read the WHOLE saved list,
   so a list saved before the limit moved its 33rd language to the top while
   every other reader ignored it. The fifth review's case: 33 saved languages,
   the 33rd is French. */
{
    const { orderTranslationPicker } = await import(mod('js/modules/song-translations.js'));
    const saved33 = ['af', 'am', 'ar', 'az', 'be', 'bg', 'bn', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'es', 'et', 'eu',
        'fa', 'fi', 'ga', 'gl', 'gu', 'he', 'hi', 'hr', 'hu', 'hy', 'id', 'is', 'it', 'ja', 'ka', 'fr'];
    /** The menu as song.php emits it (original first, then A to Z by name), ordered for the reader. */
    const pickerOrder = (tagsInMenu) => {
        localStorage.clear();
        localStorage.setItem(STORAGE, JSON.stringify(saved33));
        document.body.innerHTML = '<div class="page-song"><div class="song-translations"><ul class="dropdown-menu">'
            + tagsInMenu.map((t) => `<li data-language-tag="${t}">${t}</li>`).join('') + '</ul></div></div>';
        orderTranslationPicker();
        return Array.from(document.querySelectorAll('.dropdown-menu > li')).map((li) => li.dataset.languageTag);
    };
    check('the 33rd saved language (French) is one the server ignores', saved33.length === 33 && !tags.usablePreferenceList(saved33).includes('fr'));
    const reviewers = pickerOrder(['en', 'nl', 'fr']);
    check('the review\'s case (English original, Dutch, French): French, the 33rd, does NOT move to the top — the server\'s order stands',
        same(reviewers, ['en', 'nl', 'fr']), reviewers.join(', '));
    const withGerman = pickerOrder(['en', 'nl', 'fr', 'de']);
    check('…while German, among the first 32, does move to the top; the rest keep the server\'s order',
        same(withGerman, ['de', 'en', 'nl', 'fr']), withGerman.join(', '));
}

console.log(`\n  ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
