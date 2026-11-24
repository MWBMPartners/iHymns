#!/usr/bin/env node
/*
 * Form rows on the admin pages must line up (#2202, #2203).
 *
 * PLAIN ENGLISH
 * -------------
 * A form "row" puts several boxes side by side. If the row lines its columns
 * up along the BOTTOM ("align-items-end") and one column has help text under
 * its box while the next does not, the two boxes end up at different heights
 * (the IntAppsAPI card was 64px out). The fix is to line them up along the
 * TOP with the shared class "admin-form-row", and to give any column that has
 * no label (a Save button, a tick box) an invisible label-sized line,
 * "admin-label-spacer", so its control still starts on the same line as the
 * boxes next to it. The slug field ("Edit slug (advanced)") sits in a column
 * too, so it must take the same label look through its 'rowLabelClass' option.
 *
 * THE RULES (all read from the PHP source, never from a typed file list)
 *   0. A bottom-aligned form that holds a <textarea> fails (the #2202 rule).
 *   a. A row with "align-items-end" that has a help-text column fails:
 *      use "admin-form-row" instead.
 *   b. In an "admin-form-row", a column that holds a control but has no
 *      label must start with an "admin-label-spacer" whose other classes are
 *      exactly the row's first label's classes (same classes = same height).
 *      A full-width column (col-12) sits on its own line and is exempt.
 *   c. A slug field in a row beside labelled columns must pass
 *      'rowLabelClass' equal to those labels' classes.
 *
 * HOW IT READS THE PAGES
 * PHP blocks and comments are blanked out first, the slug partial call is
 * turned into a stand-in <details> tag carrying its rowLabelClass, and a
 * small tag-stack parser builds the element tree. A floor on how many rows
 * were found stops a broken parser from "passing" by finding nothing (rule #34).
 *
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

/* ---- turning a PHP page into something the tag parser can read ---- */

/* Blank out PHP and comments but keep every newline, so line numbers stay true. */
function strip(src) {
    return src
        /* the slug partial call -> a stand-in <details> that carries its rowLabelClass */
        .replace(/<\?=\s*ihymns_slug_advanced_field\([\s\S]*?\?>/g, (m) => {
            const rl = (m.match(/'rowLabelClass'\s*=>\s*'([^']*)'/) || [])[1] || '';
            return '<details class="slug-advanced" data-rowlabel="' + rl + '"></details>' + m.replace(/[^\n]/g, '');
        })
        .replace(/<\?php[\s\S]*?(\?>|$)/g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/<\?=[\s\S]*?\?>/g, (m) => ' X' + m.replace(/[^\n]/g, ' ').slice(2))
        .replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
}

const VOID = new Set(['input', 'br', 'img', 'hr', 'meta', 'link', 'source', 'wbr', 'col', 'area']);

/* A tiny tag-stack parser: good enough for well-formed template markup. */
function parse(text) {
    const tagRe = /<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/g;
    const rootNode = { kids: [], tag: '#root' };
    const stack = [rootNode];
    let m;
    while ((m = tagRe.exec(text)) !== null) {
        const tag = m[1].toLowerCase();
        if (m[0][1] === '/') {
            for (let i = stack.length - 1; i > 0; i--) {
                if (stack[i].tag === tag) { stack.length = i; break; }
            }
            continue;
        }
        const node = { tag, open: m[0], start: m.index, kids: [] };
        stack[stack.length - 1].kids.push(node);
        if (!VOID.has(tag) && !m[0].endsWith('/>')) stack.push(node);
    }
    return rootNode;
}

const cls = (n) => ((n.open.match(/class="([^"]*)"/) || [])[1] || '');
const classSet = (s) => s.split(/\s+/).filter(Boolean);
const hasClass = (n, c) => classSet(cls(n)).includes(c);
function all(n, test, out = []) {
    for (const k of n.kids) { if (test(k)) out.push(k); all(k, test, out); }
    return out;
}
/* a box the person types in or presses: inputs (not hidden), selects, text areas, buttons */
const isControl = (k) =>
    (['input', 'select', 'textarea'].includes(k.tag) && !/type="hidden"/.test(k.open)) || hasClass(k, 'btn');
/* the same, but tick boxes and radio buttons do not count: their own caption is not "help text",
   and a bottom-aligned tick box is deliberately level with the bottom of its neighbour */
const isBox = (k) => isControl(k) && !/type="(checkbox|radio)"/.test(k.open);
/* a real label for a box (a tick box's own "form-check-label" does not count) */
const isLabel = (k) => (k.tag === 'label' && !hasClass(k, 'form-check-label')) || (hasClass(k, 'form-label') && !hasClass(k, 'admin-label-spacer'));
const isColumn = (k) => classSet(cls(k)).some((c) => c === 'col' || c.startsWith('col-'));
const isFullWidth = (k) => hasClass(k, 'col-12');

/* a column carries help text if it has a .form-text, or a small/muted note AFTER its first control */
function hasHelp(col) {
    const ctl = all(col, isBox)[0];
    return all(col, (x) =>
        hasClass(x, 'form-text') ||
        (ctl && x.start > ctl.start && x.tag !== 'label' && x.tag !== 'option' &&
         (hasClass(x, 'small') || hasClass(x, 'text-muted')) && !hasClass(x, 'btn'))
    ).length > 0;
}

/* Returns { problems, rowsWithControls } for one file's text. */
function analyse(text, label) {
    const problems = [];
    let rowsWithControls = 0;

    /* rule 0 — the older text-area rule, kept as it was */
    const formRe = /<form\b[^>]*class="[^"]*\brow\b[^"]*\balign-items-end\b[^"]*"[^>]*>([\s\S]*?)<\/form>/g;
    let fm;
    while ((fm = formRe.exec(text)) !== null) {
        if (/<textarea\b/.test(fm[1])) {
            const line = text.slice(0, fm.index).split('\n').length;
            problems.push(`${label}:${line} form uses align-items-end but holds a <textarea>`);
        }
    }

    const stripped = strip(text);
    const tree = parse(stripped);
    const lineOf = (n) => stripped.slice(0, n.start).split('\n').length;

    for (const row of all(tree, (k) => hasClass(k, 'row'))) {
        const cols = row.kids.filter(isColumn);
        const controlCols = cols.filter((c) => all(c, isControl).length > 0);
        if (controlCols.length >= 2) rowsWithControls++;
        const where = `${label}:${lineOf(row)}`;

        /* rule a — bottom-aligned row with a help-text column */
        const boxCols = cols.filter((c) => all(c, isBox).length > 0);
        if (hasClass(row, 'align-items-end') && boxCols.length >= 2 && boxCols.some(hasHelp)) {
            problems.push(`${where} row is bottom-aligned but a column has help text — use "admin-form-row" (+ "admin-label-spacer")`);
        }

        /* rule b — an admin-form-row's label-less control column needs the spacer */
        if (hasClass(row, 'admin-form-row')) {
            const firstLabel = all(row, isLabel)[0];
            const labelSet = firstLabel ? classSet(cls(firstLabel)).sort().join(' ') : '';
            for (const col of controlCols) {
                if (isFullWidth(col)) continue;
                if (all(col, isLabel).length > 0) continue; // it has its own label
                const first = col.kids[0];
                if (!first || !hasClass(first, 'admin-label-spacer')) {
                    problems.push(`${where} a column with a control but no label must start with an "admin-label-spacer"`);
                    continue;
                }
                const spacerSet = classSet(cls(first)).filter((c) => c !== 'admin-label-spacer').sort().join(' ');
                if (spacerSet !== labelSet) {
                    problems.push(`${where} spacer classes "${spacerSet}" must equal the row's label classes "${labelSet}"`);
                }
            }
        }

        /* rule c — a slug field in a row beside labelled columns must match their label look */
        for (const col of cols) {
            const slug = all(col, (x) => x.tag === 'details' && hasClass(x, 'slug-advanced'))[0];
            if (!slug) continue;
            const siblingLabel = cols.filter((c) => c !== col).flatMap((c) => all(c, isLabel))[0];
            if (!siblingLabel) continue; // nothing labelled beside it
            const want = classSet(cls(siblingLabel)).sort().join(' ');
            const got = classSet((slug.open.match(/data-rowlabel="([^"]*)"/) || [])[1] || '').sort().join(' ');
            if (got !== want) {
                problems.push(`${where} slug field must pass 'rowLabelClass' => '${want}' (got '${got}')`);
            }
        }
    }
    return { problems, rowsWithControls };
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
let rowTotal = 0;
for (const file of files) {
    const r = analyse(fs.readFileSync(file, 'utf8'), path.relative(root, file));
    found.push(...r.problems);
    rowTotal += r.rowsWithControls;
}
check('parser found a sensible number of form rows (floor guards a broken parser)', rowTotal > 30, `only ${rowTotal} rows`);
check('no crooked form rows (bottom-aligned with help text, missing/mismatched spacer, slug label look)', found.length === 0, found.join('\n     '));

/* ---- self-tests: each rule must be able to fail, and to pass ---- */
const N = (s) => analyse(s, 'x').problems.length;
const col = (inner, c = 'col-md-6') => `<div class="${c}">${inner}</div>`;
const lab = (c = 'form-label') => `<label class="${c}" for="a">A</label>`;
const inp = '<input type="text" class="form-control">';
const help = '<div class="form-text">help</div>';
const btn = '<button class="btn">Go</button>';
const sp = (c) => `<span class="${c} admin-label-spacer" aria-hidden="true"></span>`;

check('self-test 0: bottom-aligned form with a text area is caught',
    N('<form class="row g-3 align-items-end"><textarea></textarea></form>') === 1);
check('self-test 0: top-aligned form with a text area passes',
    N('<form class="row g-3"><textarea></textarea></form>') === 0);

check('self-test a: bottom-aligned row with a help-text column is caught',
    N(`<div class="row align-items-end">${col(lab() + inp + help)}${col(lab() + inp)}</div>`) === 1);
check('self-test a: bottom-aligned row without help text passes',
    N(`<div class="row align-items-end">${col(lab() + inp)}${col(lab() + inp)}</div>`) === 0);

check('self-test b: label-less button column without a spacer is caught',
    N(`<div class="row admin-form-row">${col(lab() + inp + help)}${col(btn)}</div>`) === 1);
check('self-test b: spacer with the wrong classes is caught',
    N(`<div class="row admin-form-row">${col(lab('form-label small') + inp + help)}${col(sp('form-label') + btn)}</div>`) === 1);
check('self-test b: spacer with the row\'s label classes passes',
    N(`<div class="row admin-form-row">${col(lab('form-label small') + inp + help)}${col(sp('form-label small') + btn)}</div>`) === 0);
check('self-test b: a full-width (col-12) button column is exempt',
    N(`<div class="row admin-form-row">${col(lab() + inp + help)}${col(btn, 'col-12')}</div>`) === 0);

const slugCall = (extra) => `<?= ihymns_slug_advanced_field([ ${extra} 'value' => '' ]) ?>`;
check('self-test c: slug field beside a label without rowLabelClass is caught',
    N(`<div class="row">${col(lab('form-label small') + inp)}${col(slugCall(''))}</div>`) === 1);
check('self-test c: slug field with the wrong rowLabelClass is caught',
    N(`<div class="row">${col(lab('form-label small') + inp)}${col(slugCall("'rowLabelClass' => 'form-label',"))}</div>`) === 1);
check('self-test c: slug field with the matching rowLabelClass passes',
    N(`<div class="row">${col(lab('form-label small') + inp)}${col(slugCall("'rowLabelClass' => 'form-label small',"))}</div>`) === 0);

console.log(failures ? `\n${failures} failed` : '\nall passed');
process.exit(failures ? 1 : 0);
