#!/usr/bin/env node
/*
 * Admin pages must not scroll sideways on a phone (#2203).
 *
 * PLAIN ENGLISH
 * -------------
 * On a 360px-wide screen, three kinds of markup pushed whole admin pages
 * wider than the screen: a card header that cannot wrap, a wide table with no
 * scroll box around it, and an accordion header with several pieces on one
 * line. Each has a one-class fix in css/admin.css. This guard finds every
 * such piece of markup under manage/ and fails if it lacks the fix, and it
 * checks the CSS still defines the classes.
 *
 * THE RULES (read from the PHP source, never from a typed list)
 *   a. every "card-header" that is also "d-flex" has "admin-card-header";
 *   b. every <table> with 4 or more header cells is inside a
 *      ".table-responsive" box, or has columns tagged data-col-priority
 *      (those columns hide themselves on small screens instead);
 *   c. every "accordion-button" with 3 or more child elements has
 *      "admin-accordion-summary";
 *   d. admin.css still defines the classes above, and ".container-admin"
 *      still has overflow-wrap (it breaks one very long word).
 *
 * What it does NOT see: markup built in JavaScript, and anything that only
 * goes wrong with real data. The live check (tools/layout-audit/ovf3.cjs)
 * covers pages it can load.
 *
 * Run:  node tests/test-admin-narrow-layout.js
 */
'use strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const publicRoot = path.join(here, '..', 'appWeb', 'public_html');
const root = path.join(publicRoot, 'manage');

function phpFiles(dir) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) out.push(...phpFiles(full));
        else if (entry.name.endsWith('.php')) out.push(full);
    }
    return out;
}

/* Blank PHP blocks and HTML comments but keep every newline (line numbers stay true).
   An echo <?= ... ?> becomes ' X' (a space first, so it never glues onto the class name before it). */
function strip(src) {
    return src
        .replace(/<\?php[\s\S]*?(\?>|$)/g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/<\?=[\s\S]*?\?>/g, (m) => ' X' + m.replace(/[^\n]/g, ' ').slice(2))
        .replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
}

const VOID = new Set(['input', 'br', 'img', 'hr', 'meta', 'link', 'source', 'wbr', 'col', 'area']);

/* Tag-stack parser. Each node knows its parent so "is it inside X?" is answerable. */
function parse(text) {
    const tagRe = /<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/g;
    const rootNode = { kids: [], tag: '#root', parent: null };
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
        const node = { tag, open: m[0], start: m.index, kids: [], parent: stack[stack.length - 1] };
        stack[stack.length - 1].kids.push(node);
        if (!VOID.has(tag) && !m[0].endsWith('/>')) stack.push(node);
    }
    return rootNode;
}

const classSet = (n) => (((n.open || '').match(/class="([^"]*)"/) || [])[1] || '').split(/\s+/).filter(Boolean);
const hasClass = (n, c) => classSet(n).includes(c);
function all(n, test, out = []) {
    for (const k of n.kids) { if (test(k)) out.push(k); all(k, test, out); }
    return out;
}
const insideClass = (n, c) => { for (let p = n.parent; p; p = p.parent) { if (p.open && hasClass(p, c)) return true; } return false; };

/* Returns the problems found in one file's text, plus how many of each thing were seen. */
function analyse(text, label) {
    const problems = [];
    const seen = { headers: 0, tables: 0, accordions: 0 };
    const stripped = strip(text);
    const tree = parse(stripped);
    const lineOf = (n) => stripped.slice(0, n.start).split('\n').length;

    for (const h of all(tree, (k) => hasClass(k, 'card-header') && hasClass(k, 'd-flex'))) {
        seen.headers++;
        if (!hasClass(h, 'admin-card-header')) problems.push(`${label}:${lineOf(h)} card-header with d-flex needs "admin-card-header"`);
    }
    for (const t of all(tree, (k) => k.tag === 'table')) {
        const head = t.kids.find((k) => k.tag === 'thead');
        const cells = head ? all(head, (k) => k.tag === 'th') : [];
        if (cells.length < 4) continue;
        seen.tables++;
        const tagged = cells.some((c) => /data-col-priority/.test(c.open));
        if (!tagged && !insideClass(t, 'table-responsive')) {
            problems.push(`${label}:${lineOf(t)} table with ${cells.length} columns needs a ".table-responsive" wrapper (or data-col-priority on its columns)`);
        }
    }
    for (const b of all(tree, (k) => hasClass(k, 'accordion-button'))) {
        const pieces = b.kids.length;
        if (pieces < 3) continue;
        seen.accordions++;
        if (!hasClass(b, 'admin-accordion-summary')) problems.push(`${label}:${lineOf(b)} accordion-button with ${pieces} pieces needs "admin-accordion-summary"`);
    }
    return { problems, seen };
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
const totals = { headers: 0, tables: 0, accordions: 0 };
for (const file of files) {
    const r = analyse(fs.readFileSync(file, 'utf8'), path.relative(root, file));
    found.push(...r.problems);
    for (const k of Object.keys(totals)) totals[k] += r.seen[k];
}
/* floors: a parser that finds nothing must not pass (rule #34) */
check('parser saw card headers (floor)', totals.headers >= 15, `only ${totals.headers}`);
check('parser saw wide tables (floor)', totals.tables >= 8, `only ${totals.tables}`);
check('parser saw multi-piece accordion headers (floor)', totals.accordions >= 1, `only ${totals.accordions}`);
check('no admin markup that would scroll the page sideways on a phone', found.length === 0, found.join('\n     '));

/* d — the CSS must still carry the fixes */
const css = fs.readFileSync(path.join(publicRoot, 'css', 'admin.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
const rule = (sel) => (css.match(new RegExp('(?:^|[\\s,}])' + sel.replace(/[.]/g, '\\.') + '\\s*(?:,[^{]*)?\\{([^}]*)\\}', 'm')) || [])[1] || '';
check('admin.css: .admin-card-header wraps', /flex-wrap:\s*wrap/.test(rule('.admin-card-header')));
check('admin.css: .admin-card-header > .d-flex wraps', /\.admin-card-header\s*>\s*\.d-flex\s*\{[^}]*flex-wrap:\s*wrap/.test(css));
check('admin.css: .admin-accordion-summary wraps', /\.admin-accordion-summary\s*\{[^}]*flex-wrap:\s*wrap/.test(css));
check('admin.css: .container-admin breaks long words', /overflow-wrap:\s*break-word/.test(rule('.container-admin')));

/* ---- self-tests: each rule must be able to fail, and to pass ---- */
const N = (s) => analyse(s, 'x').problems.length;
const th4 = '<thead><tr><th>a</th><th>b</th><th>c</th><th>d</th></tr></thead>';
check('self-test a: card-header d-flex without the class is caught',
    N('<div class="card-header d-flex justify-content-between"></div>') === 1);
check('self-test a: card-header d-flex with the class passes',
    N('<div class="card-header d-flex admin-card-header"></div>') === 0);
check('self-test a: a plain card-header passes', N('<div class="card-header"></div>') === 0);
check('self-test b: bare 4-column table is caught', N(`<table>${th4}</table>`) === 1);
check('self-test b: table inside .table-responsive passes',
    N(`<div class="table-responsive"><table>${th4}</table></div>`) === 0);
check('self-test b: table whose columns carry data-col-priority passes',
    N('<table><thead><tr><th data-col-priority="primary">a</th><th>b</th><th>c</th><th>d</th></tr></thead></table>') === 0);
check('self-test b: a 3-column table is not flagged',
    N('<table><thead><tr><th>a</th><th>b</th><th>c</th></tr></thead></table>') === 0);
const acc = '<span>a</span><span>b</span><span>c</span>';
check('self-test c: 3-piece accordion-button without the class is caught',
    N(`<button class="accordion-button">${acc}</button>`) === 1);
check('self-test c: with the class it passes',
    N(`<button class="accordion-button admin-accordion-summary">${acc}</button>`) === 0);
check('self-test c: a 2-piece accordion-button passes',
    N('<button class="accordion-button"><span>a</span><span>b</span></button>') === 0);

console.log(failures ? `\n${failures} failed` : '\nall passed');
process.exit(failures ? 1 : 0);
