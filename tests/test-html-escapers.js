/**
 * iHymns — every HTML-escaping helper escapes the full set (security sweep)
 * ========================================================================
 *
 * ELI5
 * ----
 * Lots of little functions in this codebase turn dangerous characters into
 * harmless ones before text is dropped into a page. They should all handle the
 * SAME five characters. One of them was missing one, which is the kind of gap
 * nobody notices until the text it protects moves somewhere new.
 *
 * WHAT IT ASSERTS
 * ---------------
 * Every function whose name marks it as an HTML escaper must map all five of
 * `& < > " '`. Miss any one and the helper is only safe in the contexts its
 * author happened to have in mind:
 *
 *   - no `&`  → an escaped value cannot round-trip, and `&lt;` typed by a user
 *               renders as a real `<`;
 *   - no `<`/`>` → element injection in text context;
 *   - no `"`  → breakout from a double-quoted attribute;
 *   - no `'`  → breakout from a SINGLE-quoted attribute. This is the one
 *               `js/modules/bulk-import-progress.js` was missing. Its three
 *               call sites all happen to emit into text content, so it was not
 *               exploitable — but "safe because of where it is called today" is
 *               not a property a shared helper should rely on, and the next
 *               caller inherits the gap silently.
 *
 * WHY DERIVED, NOT A TYPED LIST (rule #34)
 * ----------------------------------------
 * The helper set is discovered by walking the tree for the declarations, so a
 * new copy is covered the day it is written. A typed list is precisely the
 * shape that has repeatedly gone green here while the thing it named was broken
 * somewhere else. The count is also asserted to be non-zero, so a change to how
 * these are declared fails loudly instead of silently checking nothing.
 *
 * SCOPE / LIMIT, stated so the tick is not over-read
 *   - It checks the MAPPING, by executing each extracted helper against a probe
 *     string. It does NOT prove call sites use the right helper, nor that a
 *     value reaching innerHTML went through one at all.
 *   - The right long-term fix is for these to be ONE import of
 *     js/utils/html.js::escapeHtml (the modularity rule); this guard makes the
 *     copies equivalent in the meantime rather than pretending they are.
 *
 * MUTATION RECORD (rule #34): RED on js/modules/bulk-import-progress.js before
 * the fix ("does not escape: '"). Deleting any `.replace()` from any covered
 * helper turns it RED again.
 *
 * Usage: node tests/test-html-escapers.js   (exit 0 pass, 1 fail)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

const ROOT = path.resolve(__dirname, '..');
const WEB = path.join(ROOT, 'appWeb', 'public_html');

/* Walk the web tree for .js files. */
const files = [];
(function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(p);
        /* .php too: admin pages carry inline <script> blocks, and `cpEsc()` in
           manage/credit-people.php — the escaper guarding the merge-preview
           innerHTML against a stored payload (#1386) — lives in one. A .js-only
           walk reported a clean 11 while that helper was never looked at.
           Identification is by name + body shape, so a PHP-side helper that
           delegates to htmlspecialchars() has no entity literals in its body
           and is skipped rather than mis-flagged. */
        else if (p.endsWith('.js') || p.endsWith('.php')) files.push(p);
    }
})(WEB);
files.sort();

/* Any declaration whose NAME advertises it as an escaper — matched by PATTERN
 * (`esc` anywhere in the identifier), never by a typed list of known names.
 *
 * WHY A PATTERN: the first version listed escapeHtml / escapeXml / esc and so
 * missed `cpEsc()` in manage/credit-people.php — a complete escaper guarding a
 * merge-preview innerHTML against a stored payload planted by an edit_people
 * user (#1386). It was correct, so the guard's green was accurate by luck
 * rather than by coverage, which is the single most repeated defect in this
 * repo (rule #34).
 *
 * WHY NOT EVERY FUNCTION: matching all declarations and identifying escapers by
 * body shape was tried and is TOO BLUNT — it flags `rebuildDatalist()`,
 * `buildEnrichmentPanel()` and `mountReflowModal()`, render functions that
 * legitimately escape one character for one context, or merely contain `&amp;`
 * in static markup. A guard that fails on correct code gets weakened or deleted
 * rather than fixed (rule #34 again), so the subject is deliberately "things
 * that CALL THEMSELVES escapers", where incompleteness is unambiguously a bug.
 *
 * KNOWN LIMIT, so the tick is not over-read: ad-hoc partial escaping inside a
 * render function is NOT covered. js/modules/ietf-language-picker.js escapes
 * only `"` inline before interpolating language names into double-quoted
 * attributes — sufficient there, but hand-rolled; reported separately as
 * hardening rather than policed here. */
const DECL = /(?:function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(|(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:function\s*)?\()/g;

/* Name-advertised escaper: `esc` anywhere in the identifier, case-insensitive.
   Kept as an explicit predicate rather than folded into DECL because the regex
   version of exactly this ("a prefix, then esc") silently required a leading
   character and therefore matched `cpEsc` but NOT `escapeHtml` — reporting a
   confident PASS over 2 helpers when there are 11. Caught by the count. */
const looksLikeEscaper = (name) => /esc/i.test(name);

const REQUIRED = ['&', '<', '>', '"', "'"];
const failures = [];
const checked = [];

for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');
    let m;
    DECL.lastIndex = 0;
    while ((m = DECL.exec(src)) !== null) {
        const name = m[1] || m[2];
        if (!looksLikeEscaper(name)) continue;
        const start = m.index;

        /* Capture the function body by brace balance from the first '{' after
           the declaration — NOT by a line count or a [^}]* regex, either of
           which truncates on a brace inside a string literal. */
        const openIdx = src.indexOf('{', start);
        if (openIdx === -1) continue;
        let depth = 0, end = -1;
        for (let i = openIdx; i < src.length; i++) {
            const c = src[i];
            if (c === '{') depth++;
            else if (c === '}') { depth--; if (depth === 0) { end = i; break; } }
        }
        if (end === -1) continue;
        let body = src.slice(openIdx, end + 1);

        /* Pull in a module-level lookup map the body delegates to.
         *
         * WHY THIS EXISTS: the first version of this guard analysed the
         * function body ALONE. js/utils/html.js — the CANONICAL escaper, the one
         * 16 modules import — keeps its table in a module-scope
         * `const _HTML_ESCAPES = {...}` and its body is just
         * `.replace(/[&<>"']/g, c => _HTML_ESCAPES[c])`. That body contains no
         * entity text, so the "is this really an escaper" test below rejected
         * it and the single most important helper in the tree was SILENTLY
         * SKIPPED while the guard reported a confident green over ten others.
         * It was caught only by mutating a second file and watching it stay
         * green (rule #34) — a scanner that under-reports is worse than none,
         * because its tick is read as coverage.
         *
         * So: for every `IDENT[` lookup in the body, splice in that
         * identifier's object-literal initialiser if the file declares one. */
        const refs = new Set();
        for (const r of body.matchAll(/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\[/g)) refs.add(r[1]);
        for (const ident of refs) {
            const decl = new RegExp(
                `(?:const|let|var)\\s+${ident.replace(/\$/g, '\\$')}\\s*=\\s*\\{`
            ).exec(src);
            if (!decl) continue;
            const mOpen = src.indexOf('{', decl.index);
            let d = 0, mEnd = -1;
            for (let i = mOpen; i < src.length; i++) {
                const c = src[i];
                if (c === '{') d++;
                else if (c === '}') { d--; if (d === 0) { mEnd = i; break; } }
            }
            if (mEnd !== -1) body += '\n' + src.slice(mOpen, mEnd + 1);
        }

        /* Only consider it an escaper if it actually maps entities —
           avoids mistaking an unrelated `esc()` for one of these. */
        if (!/&amp;|&lt;|&quot;|&#39;/.test(body)) continue;

        const rel = path.relative(ROOT, file);
        checked.push(`${rel}:${name}`);

        const missing = REQUIRED.filter(ch => {
            /* Present either as a replace() target or as a lookup-map key. */
            if (ch === '&') return !/&amp;/.test(body);
            if (ch === '<') return !/&lt;/.test(body);
            if (ch === '>') return !/&gt;/.test(body);
            if (ch === '"') return !/&quot;/.test(body);
            return !/&#0*39;|&#x0*27;|&apos;/i.test(body);
        });

        if (missing.length) {
            failures.push(
                `${rel}:${name}() does not escape: ${missing.join(' ')}\n` +
                `      A shared escaper must handle all of & < > " ' — otherwise it is only\n` +
                `      safe in the contexts its current callers happen to use. Prefer importing\n` +
                `      escapeHtml from js/utils/html.js instead of a local copy.`
            );
        }
    }
}

if (checked.length === 0) {
    console.error('FAIL: 0 escaping helpers discovered — the detection has stopped working,');
    console.error('      which is itself the finding. Fix this guard, do not trust its tick.');
    process.exit(1);
}

if (failures.length) {
    console.error('FAIL: incomplete HTML-escaping helper(s):\n');
    for (const f of failures) console.error(`  - ${f}\n`);
    process.exit(1);
}

console.log(`PASS: ${checked.length} HTML-escaping helper(s) cover & < > " '.`);
for (const c of checked) console.log(`    · ${c}`);
process.exit(0);
