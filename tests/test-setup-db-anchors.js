/**
 * tests/test-setup-db-anchors.js — anchorable migration-card ids +
 * `setup-database#<frag>` link/target agreement guard (#1714 residual 4)
 *
 * ELI5: `manage/help.php` links out to specific migration cards on
 * `/manage/setup-database#<something>`, but the migration cards never
 * emitted an `id` at all — so EVERY such link was dead (the browser just
 * lands at the top of the page). This guard makes sure that never quietly
 * regresses: every `setup-database#<frag>` href anywhere under
 * `appWeb/public_html/` must point at a fragment the target page can
 * actually produce, AND the target page's card renderer must still be the
 * thing producing it.
 *
 * ALGORITHM (tree-derived both sides — rule #34 in .claude/CLAUDE.md; the
 * comment-stripping + scan shape mirrors tests/test-manage-php-urls.js):
 *   1. Parse `manage/includes/migration-registry.php`'s TOP-LEVEL array
 *      keys (the migration slugs) with a regex anchored on the file's
 *      real 4-space indent — never a hand-typed slug list. Any file that
 *      matches `^    '([a-z0-9-]+)' => \[$` at that indent is a migration
 *      slug; nested `card`/`script`/`probe`/`title`/... keys sit one level
 *      deeper (8 spaces) and never match.
 *   2. Walk every `.php`/`.js` file under `appWeb/public_html/`
 *      (comment-stripped first: HTML `<!-- -->` + PHP/JS `/* *\/` block
 *      comments, so a fragment only MENTIONED in a doc-comment is never
 *      picked up), collecting every `setup-database#<frag>` href.
 *   3. Assert every collected `<frag>` is exactly `mig-<slug>` for some
 *      slug found in step 1 — a stray `#bcp47`-style alias, or a fragment
 *      naming a slug the registry doesn't have, both fail loudly instead
 *      of silently landing at the top of the page.
 *   4. Assert `manage/setup-database.php`'s `$_renderCard` closure source
 *      still emits `id="mig-` on the card wrapper — so a future refactor
 *      that quietly drops the id (the exact regression this guard exists
 *      to catch) is caught even if nothing currently links to that one
 *      card yet.
 *
 * MUTATION DRILLS (rule #34 — proven able to fail, not just written once
 * and trusted):
 *   - Typo'd help.php fragment (`#mig-iana-language-subtag-registryX`):
 *     RED — "targets fragment not derivable from the registry".
 *   - Reverted `$_renderCard`'s `id="mig-<?= ... ?>"` back to a bare
 *     `<div class="col-md-6">`: RED — "$_renderCard no longer emits
 *     id=\"mig-\"".
 *   - Both restored: GREEN.
 *   See the #1714 commit/handoff for the exact before/after output.
 *
 * OUT OF SCOPE ON PURPOSE: this does not render the page or check that
 * the id is reachable at RUNTIME past a closed `<details>` — that's what
 * setup-database.php's own small deep-link-reveal `<script>` (added in
 * the same change) covers, and it isn't mechanically checkable from a
 * static source scan.
 *
 * Usage:
 *   node tests/test-setup-db-anchors.js
 *
 * Exit status 0 = clean, 1 = at least one violation.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..', 'appWeb', 'public_html');
const REGISTRY   = path.join(ROOT, 'manage', 'includes', 'migration-registry.php');
const SETUP_DB   = path.join(ROOT, 'manage', 'setup-database.php');

/** Recursively collect every file under `dir` whose name ends with one of `exts`. */
function collectFiles(dir, exts, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            collectFiles(full, exts, out);
        } else if (entry.isFile() && exts.some((e) => entry.name.endsWith(e))) {
            out.push(full);
        }
    }
    return out;
}

/** Blank every match of `re` down to whitespace, preserving newlines (line numbers stay valid). */
function blankPreservingLines(src, re) {
    return src.replace(re, (m) => m.replace(/[^\n]/g, ' '));
}

/** HTML `<!-- -->` comments and PHP/JS star-slash block comments, newline-preserving. */
function stripComments(src) {
    src = blankPreservingLines(src, /<!--[\s\S]*?-->/g);
    src = blankPreservingLines(src, /\/\*[\s\S]*?\*\//g);
    return src;
}

/** 1-based line number of `offset` within `src`. */
function lineAt(src, offset) {
    let n = 1;
    for (let i = 0; i < offset; i++) { if (src[i] === '\n') n++; }
    return n;
}

/* ---------------------------------------------------------------------
 * Step 1 — the registry's real slugs, parsed from its own source (never
 * typed by hand). Top-level entries sit at exactly 4 spaces of indent;
 * every nested key (card/script/probe/title/body/button/...) sits at 8+
 * and is excluded by the anchored `^ {4}` + end-of-line `\[$` shape.
 * ------------------------------------------------------------------- */
const registrySrc = fs.readFileSync(REGISTRY, 'utf8');
const registryStripped = stripComments(registrySrc);
const slugs = new Set();
{
    const re = /^ {4}'([a-z0-9-]+)' => \[$/gm;
    let m;
    while ((m = re.exec(registryStripped)) !== null) {
        slugs.add(m[1]);
    }
}

if (slugs.size === 0) {
    console.error(`FAIL: parsed zero top-level slugs out of ${REGISTRY} — the indent-anchored regex is almost certainly stale against the real file shape (a bug in this guard, not an empty registry).`);
    process.exit(1);
}

/* ---------------------------------------------------------------------
 * Step 2 — every `setup-database#<frag>` href anywhere under
 * appWeb/public_html/ (.php and .js — links could in principle come
 * from either), comment-stripped first.
 * ------------------------------------------------------------------- */
const HREF_FRAG_RE = /setup-database#([A-Za-z0-9_-]+)/g;
const hrefHits = []; // { file, frag, line }

for (const file of collectFiles(ROOT, ['.php', '.js'])) {
    const raw = fs.readFileSync(file, 'utf8');
    const stripped = stripComments(raw);
    const rel = path.relative(ROOT, file).split(path.sep).join('/');

    HREF_FRAG_RE.lastIndex = 0;
    let m;
    while ((m = HREF_FRAG_RE.exec(stripped)) !== null) {
        hrefHits.push({ file: rel, frag: m[1], line: lineAt(stripped, m.index) });
    }
}

/* ---------------------------------------------------------------------
 * Step 3 — every collected fragment must be `mig-<slug>` for a slug the
 * registry actually has.
 * ------------------------------------------------------------------- */
const badLinks = [];
for (const hit of hrefHits) {
    const slug = hit.frag.startsWith('mig-') ? hit.frag.slice(4) : null;
    if (slug === null || !slugs.has(slug)) {
        badLinks.push(hit);
    }
}

/* ---------------------------------------------------------------------
 * Step 4 — setup-database.php's $_renderCard closure must still emit
 * id="mig-" on the card wrapper. Scoped to the closure body (from its
 * `$_renderCard = static function` declaration to its closing `};`) so
 * this doesn't accidentally match an unrelated `id="mig-` elsewhere.
 * ------------------------------------------------------------------- */
const setupSrc = fs.readFileSync(SETUP_DB, 'utf8');
const setupStripped = stripComments(setupSrc);
const closureStart = setupStripped.indexOf('$_renderCard = static function');
if (closureStart === -1) {
    console.error(`FAIL: could not find the $_renderCard closure in ${SETUP_DB} — this guard's anchor is stale against the real file shape.`);
    process.exit(1);
}
/* First `};` at column 0-ish after the closure start marks its end — the
   real source closes it with `                };` before the following
   blank line + `?>`; matching just `};` after the start is enough since
   nothing between the closure's own start and its own end legitimately
   contains that exact two-char sequence outside of it. */
const closureEndRel = setupStripped.indexOf('};', closureStart);
const closureBody = closureEndRel === -1
    ? setupStripped.slice(closureStart)
    : setupStripped.slice(closureStart, closureEndRel);

const rendererEmitsId = /id="mig-/.test(closureBody);

/* ---------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------- */
console.log('#1714 residual 4 — setup-database migration-card anchor guard\n');
console.log(`Parsed ${slugs.size} migration slug(s) from ${path.relative(path.resolve(__dirname, '..'), REGISTRY)}.`);
console.log(`Scanned ${hrefHits.length} setup-database#<frag> href(s) under appWeb/public_html/.\n`);

let failed = false;

if (badLinks.length > 0) {
    failed = true;
    console.log(`FAIL: ${badLinks.length} link(s) target a fragment not derivable from the migration registry:\n`);
    for (const b of badLinks) {
        console.log(`  ${b.file}:${b.line}  #${b.frag}`);
    }
    console.log('');
    console.log('Every setup-database#<frag> link must be exactly "mig-<slug>" for a real');
    console.log('top-level slug in manage/includes/migration-registry.php — that is the id');
    console.log('$_renderCard() emits on the matching card. Fix the link (or add the migration');
    console.log('to the registry if it genuinely does not exist yet).\n');
}

if (!rendererEmitsId) {
    failed = true;
    console.log(`FAIL: ${path.relative(path.resolve(__dirname, '..'), SETUP_DB)}'s $_renderCard no longer emits id="mig-" on the card wrapper.`);
    console.log('Every migration card must stay independently linkable by fragment (#1714) —');
    console.log('restore the id="mig-<?= htmlspecialchars($migAction, ENT_QUOTES) ?>" attribute');
    console.log('on the card\'s outer <div class="col-md-6"> element.\n');
} else {
    console.log('PASS: $_renderCard emits id="mig-<slug>" on every card.');
}

if (failed) {
    process.exit(1);
}

console.log(`PASS: all ${hrefHits.length} setup-database#<frag> link(s) resolve to a real migration-card id.`);
process.exit(0);
