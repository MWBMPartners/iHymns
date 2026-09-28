/**
 * tests/test-language-tags.js — the browser's share of the shared language
 * policy (#2137)
 *
 * ELI5: a person can tell iHymns which languages they read, in priority order
 * ("Brazilian Portuguese, then English"). These checks prove the browser keeps
 * that list whole and in order, matches it by language (a `pt-BR` preference
 * still finds `pt` songs), and puts those languages first when a list is first
 * shown — without ever moving an item because it was just picked.
 *
 * DETAIL: exercises js/utils/language-tags.js directly (pure functions, no
 * DOM), plus source checks that the three places which used to throw away a
 * regional preference with `/^[a-z]{2,3}$/` now use the shared helper
 * instead. Mutation-proven: restoring `/^[a-z]{2,3}$/` in api-client.js turned
 * the source check red; making mergePreferenceOrder() sort its output turned
 * the order checks red.
 *
 *   node tests/test-language-tags.js
 *
 * @see appWeb/public_html/js/utils/language-tags.js
 * @see docs/standards/media-language-bcp47-policy.md  UI-020, UI-045, UI-050
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    isPreferenceTag,
    languageGroupOf,
    mergePreferenceOrder,
    orderByPreference,
    preferenceMatchesTag,
    scriptOf,
} from '../appWeb/public_html/js/utils/language-tags.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WEB = path.join(__dirname, '..', 'appWeb', 'public_html');

let passed = 0;
let failed = 0;
function check(label, cond, detail = '') {
    if (cond) {
        passed++;
        console.log(`  PASS  ${label}`);
    } else {
        failed++;
        console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`);
    }
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

console.log('tests/test-language-tags.js — the browser keeps the reader\'s language choices whole and in order');

/* isPreferenceTag — keeps real tags (with regions and scripts), drops junk. */
check('a base code is kept', isPreferenceTag('en'));
check('a regional tag is kept (it used to be thrown away)', isPreferenceTag('pt-BR'));
check('a script + region tag is kept', isPreferenceTag('zh-Hant-TW'));
check('a private-use tag is kept', isPreferenceTag('x-hymnal'));
check('an empty string is dropped', !isPreferenceTag(''));
check('a comma or space (a header-injection attempt) is dropped', !isPreferenceTag('en, fr') && !isPreferenceTag('en\r\nX: y'));
check('an underscore form is dropped (not a tag)', !isPreferenceTag('pt_BR'));
check('a non-string is dropped', !isPreferenceTag(42) && !isPreferenceTag(null));

/* languageGroupOf — the group a tag belongs to, for matching. */
check('pt-BR is in the pt group', languageGroupOf('pt-BR') === 'pt');
check('ZH-hant is in the zh group (case-insensitive)', languageGroupOf('ZH-hant') === 'zh');
check('a private-use tag is its own group', languageGroupOf('x-hymnal') === 'x-hymnal');
check('an empty tag has no group', languageGroupOf('') === '');

/* mergePreferenceOrder — ticking and unticking keeps the priority. */
check('ticking a new language adds it LAST, keeping the earlier order',
    same(mergePreferenceOrder(['pt-BR', 'en'], ['pt', 'en', 'es']), ['pt-BR', 'en', 'es']));
check('a saved regional tag is NOT collapsed to its base code by its checkbox',
    same(mergePreferenceOrder(['pt-BR'], ['pt']), ['pt-BR']));
check('unticking a language removes every saved tag in its group, the rest keep their order',
    same(mergePreferenceOrder(['en', 'pt-BR', 'pt-PT', 'de'], ['en', 'de']), ['en', 'de']));
check('the result is never sorted A-Z (the old behaviour)',
    same(mergePreferenceOrder(['fr', 'de'], ['fr', 'de']), ['fr', 'de']));

/* orderByPreference — the reader's languages first, the server's order kept otherwise. */
const serverOrder = [
    { tag: 'ja', note: 'original' },
    { tag: 'zh' }, { tag: 'zh-Hant' }, { tag: 'en' }, { tag: 'en-GB' }, { tag: 'fr' }, { tag: 'de' },
];
const tags = (items) => items.map((i) => i.tag);
check('no preferences: the server\'s order is kept exactly',
    same(tags(orderByPreference(serverOrder, [], (i) => i.tag)), tags(serverOrder)));
check('the reader\'s groups come first in their priority order; within a group their exact tag first; the rest keep the server\'s order',
    same(tags(orderByPreference(serverOrder, ['fr', 'en-GB'], (i) => i.tag)), ['fr', 'en-GB', 'en', 'ja', 'zh', 'zh-Hant', 'de']),
    tags(orderByPreference(serverOrder, ['fr', 'en-GB'], (i) => i.tag)).join(','));
check('ordering does not change the list it was given (a new array is returned)',
    same(tags(serverOrder), ['ja', 'zh', 'zh-Hant', 'en', 'en-GB', 'fr', 'de']));

/* Source checks: the three places that used to drop regional preferences. */
const read = (rel) => fs.readFileSync(path.join(WEB, rel), 'utf8');
for (const rel of ['js/utils/api-client.js', 'js/modules/songbook-language-filter.js', 'js/modules/settings-language-filter.js']) {
    const src = read(rel);
    check(`${rel} no longer filters preferences with /^[a-z]{2,3}$/`, !src.includes('/^[a-z]{2,3}$/.test('));
    check(`${rel} uses the shared isPreferenceTag()`, src.includes('isPreferenceTag'));
}
const settingsSrc = read('js/modules/settings-language-filter.js');
check('the settings chips are labelled with language names, not upper-cased codes',
    !settingsSrc.includes('${sub.toUpperCase()}') && settingsSrc.includes('escapeHtml(nameOf(sub))'));
check('the song page picker is put in the reader\'s order once, from the router',
    read('js/modules/router.js').includes('m.orderTranslationPicker()')
    && read('js/modules/song-translations.js').includes('export function orderTranslationPicker()'));

/* The filter respects scripts (#2137 review, MATCH-040) — the same truth
   table as tests/php/test-language-filter-scripts.php, where the server's SQL
   and in-memory filters are checked row for row against a real database. */
check('scriptOf finds the script after the language (and any extlangs)',
    scriptOf('zh-Hant-TW') === 'hant' && scriptOf('sr-Latn') === 'latn' && scriptOf('zh-TW') === ''
    && scriptOf('de-1996') === '' && scriptOf('x-hymnal') === '' && scriptOf('') === '');
{
    const ROWS = ['zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW',
        'sr', 'sr-Latn', 'sr-Cyrl', 'sr-Cyrl-RS', 'en', 'en-GB', 'pt', 'pt-BR', 'pt-PT', 'x-hymnal', 'yue-Hant'];
    const CASES = {
        'zh-Hans': ['zh', 'zh-Hans', 'zh-Hans-CN', 'zh-TW'],
        'sr-Latn': ['sr', 'sr-Latn'],
        'zh-Hans,zh-Hant': ['zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW'],
        'zh-Hans,zh': ['zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW'],
        'zh-TW': ['zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW'],
        'pt-BR': ['pt', 'pt-BR', 'pt-PT'],
        'x-hymnal': ['x-hymnal'],
    };
    for (const [csv, expected] of Object.entries(CASES)) {
        const prefs = csv.split(',');
        const kept = ROWS.filter((t) => prefs.some((p) => preferenceMatchesTag(p, t)));
        check(`preferences "${csv}" keep exactly ${expected.join(', ')}`, same(kept, expected), kept.join(', '));
    }
}
check('a value not even shaped like a tag matches nothing, not even itself (core revision 4, MATCH-010)',
    !preferenceMatchesTag('en_GB', 'en_GB') && !preferenceMatchesTag('en', 'en_GB') && !preferenceMatchesTag('pt BR', 'pt'));
{
    const filterSrc = read('js/modules/songbook-language-filter.js');
    check('the songbook/song filter decides with preferenceMatchesTag() and reads the tiles\' whole tags',
        filterSrc.includes('preferenceMatchesTag(p, tag)') && filterSrc.includes('dataset.songbookLanguageTags'));
    for (const page of ['includes/pages/home.php', 'includes/pages/songbooks.php']) {
        check(`${page} gives each songbook tile its whole language tags`, read(page).includes('data-songbook-language-tags='));
    }
}

/* Song of the Day sends the same list as every other request (#2137 review).
   It builds its own `?lang=` (which wins over the header on the server), and
   used to keep only two- and three-letter codes, so `pt-BR, en` became `en`. */
{
    const { STORAGE_LANGUAGE_FILTER } = await import('../appWeb/public_html/js/constants.js');
    const store = { [STORAGE_LANGUAGE_FILTER]: JSON.stringify(['pt-BR', 'en', 'not a tag!', 'zh-Hant']) };
    globalThis.localStorage = { getItem: (k) => (k in store ? store[k] : null), setItem() {}, removeItem() {} };
    globalThis.window = globalThis.window || { location: { origin: 'https://example.test' } };
    globalThis.document = globalThis.document || { addEventListener() {}, getElementById() { return null; } };
    const { preferredLanguagesCsv } = await import('../appWeb/public_html/js/utils/api-client.js');
    const { SongOfTheDay } = await import('../appWeb/public_html/js/modules/song-of-the-day.js');
    const sotd = Object.create(SongOfTheDay.prototype);
    check('the header list keeps whole tags in the chosen order and drops junk',
        preferredLanguagesCsv() === 'pt-BR,en,zh-Hant', preferredLanguagesCsv());
    check('Song of the Day sends exactly the same list in ?lang= (pt-BR is no longer dropped)',
        sotd.getActiveSubtags().join(',') === preferredLanguagesCsv(), sotd.getActiveSubtags().join(','));
    store[STORAGE_LANGUAGE_FILTER] = '[]';
    check('with "All" chosen, Song of the Day sends no ?lang= at all', sotd.getActiveSubtags().length === 0);
    const sotdSrc = read('js/modules/song-of-the-day.js').replace(/\/\*[\s\S]*?\*\//g, '');
    check('js/modules/song-of-the-day.js has no filter of its own (no /^[a-z]{2,3}$/) and reads preferredLanguagesCsv()',
        !sotdSrc.includes('/^[a-z]{2,3}$/') && sotdSrc.includes('preferredLanguagesCsv()'));
}

console.log(`\n  ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
