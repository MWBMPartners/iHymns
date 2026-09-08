/**
 * iHymns — "the test file this comment names must actually exist" guard (#2110)
 *
 * ELI5
 * ----
 * All over this codebase, a comment tells you which test keeps a piece of code
 * honest: "kept in sync by tests/php/test-something.php", "@see
 * tests/test-something.js". Three of those comments named test files that had
 * never existed — not in any commit, not on any branch. Nobody noticed for
 * months. This file walks the whole tree, collects every path under `tests/`
 * that any comment points at, and fails if one of them is not really there.
 *
 * WHY THIS MATTERS MORE THAN AN ORDINARY WRONG COMMENT
 * ---------------------------------------------------
 * A comment that is merely out of date wastes a minute of somebody's time. A
 * comment that says "a machine is already checking this" does something worse:
 * it stops the checking. A reviewer reads "the two halves can't drift, the
 * guard catches it", believes it, and moves on. The agreement then quietly
 * rots with nothing watching it at all — which is exactly the failure rule #35
 * in `.claude/CLAUDE.md` describes, one level up. Rule #35 says a comment is
 * not a mechanism. This is the case where the comment CLAIMS to be pointing at
 * a mechanism and is pointing at nothing.
 *
 * It happened three times before anyone looked (#2110). Running this scan for
 * the first time immediately turned up four more, so it was already six or
 * seven, not three. That is the whole argument for having this file: people
 * will keep writing these, and only something automatic will keep catching
 * them.
 *
 * WHAT IT LOOKS AT (rule #34 — derived from the tree, never a typed list)
 * ----------------------------------------------------------------------
 * Every `.js` and `.php` file under `appWeb/`, `tests/` and `tools/`, found by
 * walking the directories rather than by naming files here. Inside each one it
 * looks for anything shaped like a path to a test file:
 *
 *     tests/<anything>/<name>.php      or      tests/<name>.js
 *
 * and checks that the file is on disk. The whole file is scanned, not just its
 * comments — a `require` or an `import` of a test file that is not there is
 * just as broken as a comment about one, and scanning everything avoids having
 * to write a comment parser that could itself be subtly wrong and quietly skip
 * things (rule #34: a scanner that under-reports is worse than no scanner,
 * because its tick gets read as coverage).
 *
 * WHAT IT DELIBERATELY DOES NOT FLAG (rule #34 — a guard that goes red on
 * correct writing gets weakened or deleted rather than fixed)
 * ---------------------------------------------------------------------
 *  1. A mention of a FOLDER rather than a file. `tests/php/` and
 *     `tests/fixtures/` never match, because a match has to end in `.php` or
 *     `.js`.
 *  2. A wildcard. `tests/php/test-*.php` never matches, because `*` is not one
 *     of the characters a path is allowed to be made of here.
 *  3. A path that only LOOKS like it starts at `tests/` because it is the tail
 *     of a longer one. A nested `…/somewhere/tests/<name>.js` is left alone,
 *     because a match must begin at a natural boundary and not straight after
 *     a letter, dot, slash or hyphen.
 *  4. Prose that plainly says the file is gone. Both of these are correct
 *     writing and must stay green:
 *
 *         "This replaces the retired tests/… , which guarded the old library"
 *         "This file used to be tests/… and asserted on the frozen corpus"
 *
 *     So a citation is excused when the surrounding sentence contains one of a
 *     short list of plain-English phrases meaning "this no longer exists" —
 *     retired, removed, deleted, never existed, used to be, formerly, renamed,
 *     no longer. The list is of ENGLISH WORDS, not of files, so it is not the
 *     hardcoded list rule #34 warns about: it never needs updating when a test
 *     is added or renamed.
 *
 *     That excuse is a genuine hole, and worth naming plainly: someone could
 *     silence a real broken citation by writing the word "removed" near it.
 *     The trade is deliberate. Requiring an invented marker instead would
 *     force everybody to learn a piece of private punctuation, and the two
 *     honest, already-correct comments in this tree would have gone red on the
 *     day this guard was added — which is how guards get switched off.
 *
 *  5. Markdown and plan documents. `.claude/*.md`, the wiki and handoffs are
 *     historical records and design drafts by nature; they legitimately name
 *     tests that were only ever proposed. The failure this file exists to stop
 *     is a CODE comment misleading somebody reading the code, so code is what
 *     it reads. Stated as a known limit, not hidden as an oversight.
 *
 * PROVEN ABLE TO FAIL (rule #34), both ways round:
 *   - add a citation of a test file that is not there, with no explanation
 *     nearby → RED, naming the file, the line and the missing path;
 *   - take the word "retired" out of the one honest past-tense sentence in
 *     tests/test-qr-cuercode.js → RED, which proves the exemption in point 4
 *     is doing real work rather than the green being an accident.
 *   Both restored → GREEN. The runs are in the pull request that added this.
 *
 *   node tests/test-cited-files-exist.js
 *
 * Exit status 0 = every cited test file exists, 1 = at least one does not.
 *
 * @see .claude/CLAUDE.md rule #35  cross-file agreement needs a mechanism, not a comment
 * @see .claude/CLAUDE.md rule #34  tree-derived, mutation-proven guards
 * @see #2110
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..');

/* Where to look. Directories, not files — so a new file is covered the day it
   is written, with nothing to remember. */
const SCAN_DIRS  = ['appWeb', 'tests', 'tools'];
const SCAN_EXTS  = ['.js', '.php'];
/* Never worth walking: third-party code and version-control internals. */
const SKIP_DIRS  = new Set(['node_modules', 'vendor', '.git', 'dist', 'build']);

/**
 * Anything shaped like a path to a test file.
 *
 * The lookbehind is what stops a nested `some/other/tests/<name>.js` being read
 * as if it were the repository's own tests folder: the character just before
 * `tests/` must not be part of a path itself. The body allows letters, digits, dots, slashes,
 * hyphens and underscores — deliberately NOT `*`, so a wildcard such as
 * `tests/php/test-*.php` cannot match.
 */
const CITATION_RE = /(?<![\w./-])tests\/[A-Za-z0-9_./-]*\.(?:php|js)\b/g;

/**
 * Plain-English ways of saying "this file is not here any more". A citation
 * with one of these in the surrounding sentence is history, not a signpost,
 * and is left alone. Lower case; the surrounding text is lower-cased too.
 */
const GONE_PHRASES = [
    'never existed',
    'used to be',
    'no longer',
    'retired',
    'removed',
    'deleted',
    'formerly',
    'renamed',
    'replaced by',
    'superseded',
];

/* How much text either side of the citation counts as "the surrounding
   sentence". Comments here are hand-wrapped, so the explanation is often on
   the line before or the line after rather than the same one. */
const CONTEXT_CHARS = 160;

/** Walk a directory tree and return every file with one of `exts`. */
function collectFiles(dir, exts, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (SKIP_DIRS.has(entry.name)) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            collectFiles(full, exts, out);
        } else if (entry.isFile() && exts.some((e) => entry.name.endsWith(e))) {
            out.push(full);
        }
    }
    return out;
}

/** 1-based line number of `offset` within `src`. */
function lineAt(src, offset) {
    let n = 1;
    for (let i = 0; i < offset; i++) { if (src[i] === '\n') n++; }
    return n;
}

/**
 * True when the words around a citation say the file is gone. Newlines and
 * comment decoration collapse to single spaces first, so a phrase split across
 * two wrapped comment lines still reads as one sentence.
 */
function explainedAsGone(src, start, end) {
    const from = Math.max(0, start - CONTEXT_CHARS);
    const to   = Math.min(src.length, end + CONTEXT_CHARS);
    const window = src.slice(from, to)
        /* Drop the decoration that starts a wrapped comment line — the ` * `
           of a doc-block, a `//`, a `#`. Without this, a phrase split over two
           lines ("… had never / * existed …") would never be found, and a
           perfectly honest sentence would be reported as a broken citation. */
        .replace(/\n[ \t]*(?:\*+|\/\/+|#+)[ \t]?/g, ' ')
        .replace(/\s+/g, ' ')
        .toLowerCase();
    return GONE_PHRASES.some((phrase) => window.includes(phrase));
}

const files = SCAN_DIRS.flatMap((d) => {
    const full = path.join(ROOT, d);
    return fs.existsSync(full) ? collectFiles(full, SCAN_EXTS) : [];
});

/* Two kinds of broken reference, and only one of them is worth failing a build over.
   (Narrowed 2026-09-08 after the owner pushed back, rightly.)

   ELI5: there is a difference between a comment that says "look over there for an
   example" and one that says "don't worry, a machine is already checking this".
   Only the second kind is a promise, and only a broken promise should stop the build.

   The argument, in full, because it changed this file's design:

     The first version failed on ANY comment naming a test file that was not there.
     That treats a comment as a contract, and comments are not contracts — they are
     there to help somebody understand the code. Break the build over a stale
     cross-reference and the sensible response is to stop writing cross-references,
     which leaves the codebase worse than it started.

     Look at what this scan actually caught. THREE were claims of protection
     ("kept in sync by X", "@see … the guard over this page"). Those genuinely
     mislead: a reviewer reads one, believes something is watching, and stops
     checking — rule #35's failure, one level up. FOUR were ordinary pointers with
     the wrong filename. Worth tidying, not worth failing a build.

   So: a reference is a PROMISE when the words around it claim protection. Anything
   else is a pointer — still reported, so it gets fixed, but it does not fail. */
const PROMISE_WORDS = /\b(kept in sync|keeps? (?:them|these|it|the two)|@see|guard(?:ed|s)? (?:by|over)|asserted by|covered by|enforced by|CI guard|checked by|proven by|banned by|caught by|verified by|fails? (?:the )?build)\b/i;

/* Look at the sentence the reference sits in — 200 characters either side, which
   comfortably covers a doc-block line and its neighbours without wandering into an
   unrelated paragraph. */
const isPromise = (src, at) => PROMISE_WORDS.test(src.slice(Math.max(0, at - 200), at + 200));

const broken  = [];
let citations = 0;
let excused   = 0;
/* Cache: the same path is cited from many files, and this saves a few thousand
   pointless filesystem calls. */
const onDisk = new Map();
const exists = (rel) => {
    if (!onDisk.has(rel)) onDisk.set(rel, fs.existsSync(path.join(ROOT, rel)));
    return onDisk.get(rel);
};

for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');
    const rel = path.relative(ROOT, file).split(path.sep).join('/');
    CITATION_RE.lastIndex = 0;
    let m;
    while ((m = CITATION_RE.exec(src)) !== null) {
        citations++;
        const cited = m[0];
        if (exists(cited)) continue;
        if (explainedAsGone(src, m.index, m.index + cited.length)) { excused++; continue; }
        broken.push({ where: `${rel}:${lineAt(src, m.index)}`, cited, promise: isPromise(src, m.index) });
    }
}

console.log('\n#2110 — every test file a comment names must actually exist\n');
console.log(`Scanned ${files.length} .js/.php file(s) under ${SCAN_DIRS.join(', ')}.`);
console.log(`Found ${citations} reference(s) to a path under tests/ (${excused} explained as no longer existing).\n`);

/* Sanity floors (rule #34). If the walk or the pattern quietly broke, the run
   above would find nothing and this guard would report a confident, meaningless
   green. These numbers are far below what the tree really holds, so they only
   trip when something is genuinely wrong rather than when the tree grows or
   shrinks a little. */
const MIN_FILES     = 500;
const MIN_CITATIONS = 300;
if (files.length < MIN_FILES || citations < MIN_CITATIONS) {
    if (files.length < MIN_FILES) {
        console.log(`FAIL: the directory walk found only ${files.length} file(s), well under the ${MIN_FILES} this tree holds.`);
    }
    if (citations < MIN_CITATIONS) {
        console.log(`FAIL: the scan found only ${citations} reference(s) to a test file, well under the ${MIN_CITATIONS} this tree holds.`);
    }
    console.log('');
    console.log('That means this guard has stopped looking properly, not that the tree is clean —');
    console.log('and a checker that quietly checks nothing is worse than no checker, because its');
    console.log('tick gets read as coverage. Fix the walk or the pattern rather than lowering these');
    console.log('numbers.');
    process.exit(1);
}

const promises = broken.filter((b) => b.promise);
const pointers = broken.filter((b) => !b.promise);

if (pointers.length > 0) {
    console.log(`NOTE: ${pointers.length} comment(s) point at a test file that is not there.`);
    console.log('These are ordinary cross-references, not claims that something is being');
    console.log('checked — so they do NOT fail the build. They are still worth correcting,');
    console.log('because somebody will follow one and find nothing.\n');
    for (const b of pointers) {
        console.log(`  ${b.where}`);
        console.log(`      names: ${b.cited}   — not on disk`);
    }
    console.log('');
}

if (promises.length > 0) {
    console.log(`FAIL: ${promises.length} comment(s) claim a test is protecting something, and that test does not exist:\n`);
    for (const b of promises) {
        console.log(`  ${b.where}`);
        console.log(`      names: ${b.cited}   — not on disk`);
    }
    console.log('');
    console.log('This kind of sentence is read as a promise that something is already');
    console.log('checking, so a reviewer stops checking. Either write the file it names, or');
    console.log('change the sentence to say what is actually true. If the file genuinely used');
    console.log('to exist and was taken out, say so in the same sentence ("the retired …",');
    console.log('"this used to be …") and this guard will leave it alone.');
    process.exit(1);
}

console.log(`PASS: all ${citations - excused} live reference(s) to a test file resolve to a real file on disk.`);
process.exit(0);
