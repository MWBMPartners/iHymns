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

console.log(`\n  ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
