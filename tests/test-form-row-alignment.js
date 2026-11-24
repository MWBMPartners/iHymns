#!/usr/bin/env node
/*
 * A form row whose columns are different heights must line up along the TOP.
 * "align-items-end" lines them up along the BOTTOM, so a short input sits
 * level with the end of a tall box (a big text area, or long help text) and
 * its label floats away from the label next to it. That is what made the
 * "Sign in with Apple" fields look crooked on the configuration page (#2202).
 *
 * This guard walks every manage page, finds each <form> that uses the
 * "row ... align-items-end" classes, and fails if the same form also holds a
 * <textarea> (the clearest sign the columns will differ in height).
 * Run:  node tests/test-form-row-alignment.js
 */
'use strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

const root = path.join(here, '..', 'appWeb', 'public_html', 'manage');

function phpFiles(dir) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) out.push(...phpFiles(full));
        else if (entry.name.endsWith('.php')) out.push(full);
    }
    return out;
}

/* Returns the problems found in one file's text. */
function problemsIn(text, label) {
    const problems = [];
    const formRe = /<form\b[^>]*class="[^"]*\brow\b[^"]*\balign-items-end\b[^"]*"[^>]*>([\s\S]*?)<\/form>/g;
    let match;
    while ((match = formRe.exec(text)) !== null) {
        if (/<textarea\b/.test(match[1])) {
            const line = text.slice(0, match.index).split('\n').length;
            problems.push(`${label}:${line} form uses align-items-end but holds a <textarea>`);
        }
    }
    return problems;
}

let failures = 0;
function check(name, ok, detail) {
    if (ok) { console.log('  ✅ ' + name); return; }
    failures++;
    console.log('  ❌ ' + name + (detail ? '\n     ' + detail : ''));
}

const files = phpFiles(root);
check('found manage pages to scan (list comes from the tree)', files.length > 20, `only ${files.length} files`);

const found = [];
for (const file of files) {
    found.push(...problemsIn(fs.readFileSync(file, 'utf8'), path.relative(root, file)));
}
check('no bottom-aligned form row holds a text area', found.length === 0, found.join('\n     '));

/* Self-test: prove the check can fail. */
const bad = '<form method="post" class="row g-3 align-items-end"><textarea></textarea></form>';
const good = '<form method="post" class="row g-3"><textarea></textarea></form>';
const inline = '<form method="post" class="row g-3 align-items-end"><input><button></button></form>';
check('self-test: a bottom-aligned form with a text area is caught', problemsIn(bad, 'x').length === 1);
check('self-test: a top-aligned form with a text area passes', problemsIn(good, 'x').length === 0);
check('self-test: a bottom-aligned inline form without a text area passes', problemsIn(inline, 'x').length === 0);

console.log(failures ? `\n${failures} failed` : '\nall passed');
process.exit(failures ? 1 : 0);
