/**
 * tests/test-ietf-picker-keeps-tags.js — the language picker never rewrites a
 * tag its boxes cannot hold (#2137 review)
 *
 * ELI5: the picker has four boxes — language, script, region, one variant. A
 * saved tag with anything else in it (`en-u-ca-gregory`, `en-x-hymnal`,
 * `x-hymnal`, `i-default`) used to be squeezed into those boxes and saved
 * back changed the moment a box lost focus: `en-CA-gregory`, `en-hymnal`, or
 * empty (which the song save stores as `und`, "not known"). These checks
 * prove such a tag is now kept exactly as saved, shown read-only with a plain
 * note, and only replaced when the curator types in a box.
 *
 * WHAT IT CHECKS
 *  - pickerCanHold() / pickerRebuildTag(), pure: the reviewer's four inputs
 *    cannot be held; ordinary tags (and letter-case differences) can; a
 *    second variant cannot (there is one variant box);
 *  - the REAL bootIetfLanguagePicker() in jsdom, once per input: the hidden
 *    output stays the saved tag after boot and after every box loses focus,
 *    the preview shows it, the note names it; typing a language replaces it
 *    and clears the note; an ordinary tag (`pt-BR`) still behaves as before.
 *
 * Mutation-proven: making pickerCanHold() always answer true turned the
 * four "kept" DOM checks red (the rewrites came back: en-CA-gregory,
 * en-hymnal, empty); removing the 'input' listener's reset turned the
 * "typing replaces it" check red.
 *
 *   node tests/test-ietf-picker-keeps-tags.js
 */

import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MODULE_PATH = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'ietf-language-picker.js');

let passed = 0;
let failed = 0;
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log('  PASS  ' + label); }
    else { failed++; console.log('  FAIL  ' + label + (detail ? ' — ' + detail : '')); }
}
const flush = (ms = 0) => new Promise((r) => setTimeout(r, ms));

console.log('tests/test-ietf-picker-keeps-tags.js — the language picker keeps tags it cannot show');

/* A fake page, and a fake registry answering the picker's name lookups. */
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/manage/editor/' });
const { window } = dom;
global.window = window;
global.document = window.document;
global.localStorage = window.localStorage;
global.Event = window.Event;
global.CustomEvent = window.CustomEvent;
const NAMES = { language: { en: 'English', pt: 'Portuguese' }, region: { BR: 'Brazil', GB: 'United Kingdom' } };
global.fetch = async (input) => {
    const u = new URL(String(input), 'https://example.test/');
    const kind = (u.searchParams.get('action') || '').replace('_search', '');
    const q = (u.searchParams.get('q') || '').toLowerCase();
    const table = NAMES[kind] || {};
    const hits = Object.entries(table).filter(([c]) => c.toLowerCase() === q).map(([code, name]) => ({ code, name }));
    return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ suggestions: hits }) };
};

const { pickerCanHold, pickerRebuildTag, bootIetfLanguagePicker } = await import(pathToFileURL(MODULE_PATH).href);

/* ---- pure ---- */
const REVIEWER_INPUTS = ['en-u-ca-gregory', 'en-x-hymnal', 'x-hymnal', 'i-default'];
for (const t of REVIEWER_INPUTS) {
    check(`the boxes cannot hold ${t} (they would save "${pickerRebuildTag(t)}")`, pickerCanHold(t) === false);
}
for (const t of ['', 'en', 'pt-BR', 'zh-Hant-TW', 'ca-ES-valencia', 'de-1996', 'EN-gb', 'es-419']) {
    check(`the boxes can hold "${t}"`, pickerCanHold(t) === true, pickerRebuildTag(t));
}
check('a second variant cannot be held (there is one variant box)', pickerCanHold('ca-ES-valencia-1901') === false);
check('with no variant box at all, a variant cannot be held', pickerCanHold('de-1996', 0) === false);

/* ---- the real picker in a page ---- */
function buildPicker(initialTag) {
    const wrap = document.createElement('div');
    wrap.className = 'ietf-picker';
    if (initialTag) wrap.setAttribute('data-initial-tag', initialTag);
    wrap.innerHTML =
        '<input type="text" class="ietf-picker-language"><input type="hidden" class="ietf-picker-language-code">'
      + '<input type="text" class="ietf-picker-script"><input type="hidden" class="ietf-picker-script-code">'
      + '<input type="text" class="ietf-picker-region"><input type="hidden" class="ietf-picker-region-code">'
      + '<input type="text" class="ietf-picker-variant"><input type="hidden" class="ietf-picker-variant-code">'
      + '<code class="ietf-tag-preview">—</code><span class="ietf-tag-display"></span>'
      + '<div class="ietf-picker-unknown-warning form-text d-none"></div>'
      + '<input type="hidden" class="ietf-tag-output" value="">';
    document.body.appendChild(wrap);
    return wrap;
}
const q = (wrap, cls) => wrap.querySelector('.' + cls);

for (const tag of REVIEWER_INPUTS) {
    const wrap = buildPicker(tag);
    bootIetfLanguagePicker(wrap);
    await flush(20);
    const out = q(wrap, 'ietf-tag-output');
    const note = q(wrap, 'ietf-picker-unknown-warning');
    check(`${tag}: kept exactly after the picker opens`, out.value === tag, out.value);
    ['ietf-picker-language', 'ietf-picker-script', 'ietf-picker-region', 'ietf-picker-variant'].forEach((cls) => {
        q(wrap, cls).dispatchEvent(new window.Event('blur'));
    });
    check(`${tag}: still kept after every box loses focus (the editors save on blur)`, out.value === tag, out.value);
    check(`${tag}: shown read-only in the preview`, q(wrap, 'ietf-tag-preview').textContent === tag);
    check(`${tag}: a plain note names it and says it is kept`,
        !note.classList.contains('d-none') && note.textContent.includes(tag) && note.textContent.includes('kept exactly'),
        note.textContent);
}

{
    const wrap = buildPicker('en-x-hymnal');
    bootIetfLanguagePicker(wrap);
    await flush(20);
    const lang = q(wrap, 'ietf-picker-language');
    lang.value = 'pt';
    lang.dispatchEvent(new window.Event('input', { bubbles: true }));
    check('typing a language in a box replaces the kept tag', q(wrap, 'ietf-tag-output').value === 'pt', q(wrap, 'ietf-tag-output').value);
    check('…and the note about the kept tag goes away', !q(wrap, 'ietf-picker-unknown-warning').textContent.includes('kept exactly'));
}

{
    const wrap = buildPicker('pt-BR');
    bootIetfLanguagePicker(wrap);
    await flush(20);
    check('an ordinary tag still fills the boxes as before (pt-BR → Portuguese, Brazil)',
        q(wrap, 'ietf-tag-output').value === 'pt-BR' && q(wrap, 'ietf-picker-language').value === 'Portuguese'
        && q(wrap, 'ietf-picker-region').value === 'Brazil',
        [q(wrap, 'ietf-tag-output').value, q(wrap, 'ietf-picker-language').value, q(wrap, 'ietf-picker-region').value].join(' / '));
    check('…with no note', q(wrap, 'ietf-picker-unknown-warning').classList.contains('d-none'));
}

console.log(`\n  ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
