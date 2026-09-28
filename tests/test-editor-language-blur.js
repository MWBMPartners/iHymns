/**
 * tests/test-editor-language-blur.js — the old song editor saves a language
 * only when the curator changed it (#2137 reviews)
 *
 * ELI5: in the old editor (manage/editor/editor.js), the language picker's
 * boxes report every focus change. A focus change on its own used to mark
 * the song as changed, so a later save wrote the language again — and before
 * the picker kept tags it cannot show (`en-u-ca-gregory`, `x-hymnal`), that
 * rewrote them. The guard in commitLanguage() stops that: nothing is marked
 * changed unless the value really differs (and `und` counts the same as the
 * empty boxes it is shown as).
 *
 * HOW: this runs the REAL commitLanguage() from editor.js — cut out of the
 * source between its own first and last lines, not retyped — in a sandbox
 * with a fake song store and a fake hidden field, and checks when it marks
 * the song changed. Mutation-proven: deleting the guard line turns the
 * "focus change only" checks red.
 *
 *   node tests/test-editor-language-blur.js
 *
 * @see appWeb/public_html/manage/editor/editor.js  commitLanguage()
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const EDITOR = path.join(__dirname, '..', 'appWeb', 'public_html', 'manage', 'editor', 'editor.js');

let passed = 0;
let failed = 0;
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

console.log('tests/test-editor-language-blur.js — the old editor saves a language only when it changed');

const src = fs.readFileSync(EDITOR, 'utf8');
const start = src.indexOf('var commitLanguage = function () {');
const end = start > -1 ? src.indexOf('\n        };', start) : -1;
check('commitLanguage() was found in editor.js', start > -1 && end > start);
const fnSource = start > -1 && end > start ? src.slice(start, end + '\n        };'.length) : '';

/** Run the real function once against a song and a hidden-field value. */
function commitWith(songLanguage, fieldValue) {
    const song = { id: 'S1', language: songLanguage };
    const marked = [];
    const sandbox = {
        currentSongId: 'S1',
        findSongById: (id) => (id === 'S1' ? song : null),
        markModified: (id) => marked.push(id),
        document: { getElementById: (id) => (id === 'edit-language' ? { value: fieldValue } : null) },
    };
    vm.createContext(sandbox);
    vm.runInContext(`${fnSource}\ncommitLanguage();`, sandbox);
    return { marked: marked.length > 0, language: song.language };
}

if (fnSource !== '') {
    let r = commitWith('en-u-ca-gregory', 'en-u-ca-gregory');
    check('focus change only, a tag the picker keeps as it was: the song is NOT marked changed', !r.marked && r.language === 'en-u-ca-gregory');
    r = commitWith('pt-BR', 'pt-BR');
    check('focus change only, an ordinary tag: not marked changed', !r.marked);
    r = commitWith('und', '');
    check('focus change only, a song marked und (shown as empty boxes): not marked changed, still und', !r.marked && r.language === 'und');
    r = commitWith('pt-BR', 'pt-PT');
    check('a real change is recorded and marks the song changed', r.marked && r.language === 'pt-PT');
    r = commitWith('pt-BR', '');
    check('clearing the language is a real change (the server then stores und)', r.marked && r.language === '');
}

console.log(`\n  ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
