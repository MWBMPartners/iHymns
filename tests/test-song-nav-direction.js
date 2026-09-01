/**
 * iHymns — previous/next song keyboard navigation resolves the right link
 *
 * ELI5
 * ----
 * On a song page the ← and → keys move you to the previous and next song.
 * This checks → really goes forwards — including on the last song of a book,
 * where it used to quietly go backwards instead.
 *
 * THE BUG
 * -------
 * `app.js`'s `navigateSongDirection()` picked its link POSITIONALLY:
 *
 *     prev -> '.song-navigation a:first-child'
 *     next -> '.song-navigation a:last-child'
 *
 * `includes/pages/song.php` renders the prev slot as either an `<a>` or an
 * empty `<span>` placeholder, but renders the next slot as an `<a>` or
 * NOTHING AT ALL — there is no `else` branch. So on the last song of a
 * songbook the container holds exactly one child, the PREVIOUS link, and that
 * single element satisfies `:first-child` and `:last-child` alike. Pressing →
 * ("Next song", advertised in the shortcuts overlay and twice on /help) walked
 * the user backwards; pressing → again walked them forwards, so they
 * oscillated between the last two songs of every book.
 *
 * Nothing errored, nothing logged. `:last-child` means "last child of its
 * parent", not "the last matching element" — a distinction that is invisible
 * until the count of children changes.
 * https://developer.mozilla.org/docs/Web/CSS/:last-child
 *
 * THE MECHANISM (rule #35)
 * ------------------------
 * A positional selector is an unwritten agreement between two files: the JS
 * assumes a child count the PHP is free to change. Nothing enforced it, so it
 * broke. The fix names the links explicitly — `data-song-nav="prev|next"` —
 * which is an agreement a reader can see, and this file is the thing that
 * keeps the two ends honest.
 *
 * DERIVED, NOT TYPED (rule #34)
 * -----------------------------
 * The selectors under test are extracted from app.js at run time, and the
 * markup shapes are asserted against song.php's real source, so this cannot
 * pass by testing a copy of the code that has drifted from the product.
 *
 *   node tests/test-song-nav-direction.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { JSDOM } from 'jsdom';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUB = join(__dirname, '..', 'appWeb', 'public_html');
const APP_JS  = join(PUB, 'js', 'app.js');
const SONG_PHP = join(PUB, 'includes', 'pages', 'song.php');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else {
        failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
        console.log(`  FAIL  ${label}`);
        if (detail) console.log(`        ${detail}`);
    }
}

const appSrc  = readFileSync(APP_JS, 'utf8');

/**
 * song.php with its PHP/HTML block comments removed.
 *
 * NOT optional. The doc-comment that now sits inside the `.song-navigation`
 * block explains the bug, and in doing so it contains the literal strings
 * `data-song-nav` and `<span></span>`. Against the raw source, every song.php
 * assertion below could be satisfied by that PROSE — delete the actual fix,
 * keep the comment describing it, and this file still reports green. It did
 * exactly that on its first mutation run: reverting the whole bug left the
 * placeholder count at 2 because one of them was in the comment.
 *
 * The same precaution is already taken by tests/php/test-fragment-inline-
 * scripts.php, for the same reason: a guard that reads comments is a guard
 * that can be satisfied by describing the work instead of doing it.
 */
const songSrc = readFileSync(SONG_PHP, 'utf8')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

console.log('\n1 — song.php marks each navigation link with its DIRECTION\n');

/* The load-bearing assertion. With named links the resolver no longer has to
   infer meaning from position, which is what made the last-song case wrong. */
check('song.php tags the previous-song link data-song-nav="prev"',
    /data-song-nav\s*=\s*"prev"/.test(songSrc));
check('song.php tags the next-song link data-song-nav="next"',
    /data-song-nav\s*=\s*"next"/.test(songSrc));

/* Belt and braces: keep BOTH slots occupied so the positional fallback below
   (which a stale service-worker-cached fragment still relies on) is correct
   too. The prev slot always had its `<span></span>`; the next slot never did,
   and that asymmetry is the whole bug. */
const navBlock = /<nav class="song-navigation[\s\S]*?<\/nav>/.exec(songSrc);
check('found the .song-navigation block in song.php', !!navBlock);
const placeholders = navBlock ? (navBlock[0].match(/<span><\/span>/g) || []).length : 0;
check('both nav slots emit a placeholder when empty, so the container always '
    + 'has two children (prev had one; next did not — that asymmetry WAS the bug)',
    placeholders >= 2, `found ${placeholders} <span></span> placeholder(s), expected 2`);

console.log('\n2 — the resolver picks the right link in all three real shapes\n');

/* Extract the selectors app.js ACTUALLY uses, rather than restating them here:
   a restated copy passes happily while the product drifts.

   The method branches `direction === 'prev' ? <prev> : <next>`, where each side
   is either one selector string or an ARRAY of them in preference order. Pull
   both sides out separately — an earlier draft flattened them into one list and
   split it by parity, which silently mis-assigned every selector the moment the
   fix turned two selectors into four, and reported three product failures that
   were really failures of this file. */
const selMatch = /navigateSongDirection\s*\(direction\)\s*\{[\s\S]*?\n    \}/.exec(appSrc);
check('found navigateSongDirection() in app.js', !!selMatch);

const body = selMatch ? selMatch[0] : '';

/**
 * Split the `cond ? A : B` after `direction === 'prev'` into its two arms.
 *
 * Done with a depth- and string-aware walk rather than a regex, because the
 * obvious regex is wrong in a way that looks right: `\?([\s\S]*?):` stops at
 * the FIRST colon after the `?`, and the first colon here lives INSIDE the
 * selector string `'.song-navigation a:first-child'`. That produced two
 * garbage arms, one of which jsdom then rejected as an invalid selector — a
 * loud failure this time, but the same regex against slightly different source
 * would have quietly returned a plausible-looking wrong answer. Rule #34: a
 * regex result checked against real source, not assumed.
 */
function splitTernary(src) {
    const start = src.indexOf("direction === 'prev'");
    if (start === -1) return null;
    let depth = 0, quote = null, qIdx = -1, cIdx = -1;
    for (let i = start; i < src.length; i++) {
        const ch = src[i];
        if (quote) {
            if (ch === '\\') { i++; continue; }
            if (ch === quote) quote = null;
            continue;
        }
        if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue; }
        if ('([{'.includes(ch)) { depth++; continue; }
        if (')]}'.includes(ch)) { if (depth === 0) break; depth--; continue; }
        if (depth !== 0) continue;
        if (ch === '?' && qIdx === -1) { qIdx = i; continue; }
        if (ch === ':' && qIdx !== -1 && cIdx === -1) { cIdx = i; continue; }
        if (ch === ';' && cIdx !== -1) return [src.slice(qIdx + 1, cIdx), src.slice(cIdx + 1, i)];
    }
    return null;
}

const ternary = splitTernary(body);
const quoted  = (s) => [...s.matchAll(/'([^']+)'/g)].map((m) => m[1]);
const GROUPS  = {
    prev: ternary ? quoted(ternary[0]) : [],
    next: ternary ? quoted(ternary[1]) : [],
};
check('parsed the prev/next selector groups out of the ternary '
    + `(prev: ${GROUPS.prev.join(' | ') || 'none'} — next: ${GROUPS.next.join(' | ') || 'none'})`,
    GROUPS.prev.length >= 1 && GROUPS.next.length >= 1,
    'could not read a selector for each direction — this file would then assert nothing');

/* Mirrors song.php's three renderings. Shape 3 is the one that was broken. */
const SHAPES = {
    'middle of a book (prev + next)': { prev: true,  next: true  },
    'FIRST song of a book (no prev)':  { prev: false, next: true  },
    'LAST song of a book (no next)':   { prev: true,  next: false },
};

/**
 * Build the container as song.php ACTUALLY renders it right now.
 *
 * This reads its two variables — whether each link carries a direction
 * attribute, and whether the empty next slot gets a placeholder — out of the
 * real source above rather than hard-coding the fixed shape. That distinction
 * matters: the first draft of this file rendered the POST-fix markup and
 * therefore reported section 2 green while the product was still broken. A
 * test that builds its own corrected input proves only that the correction
 * would work, which is the "wrong but green" failure of rule #34.
 */
const emitsPrevAttr = /data-song-nav\s*=\s*"prev"/.test(songSrc);
const emitsNextAttr = /data-song-nav\s*=\s*"next"/.test(songSrc);
const emitsNextPlaceholder = placeholders >= 2;

function render({ prev, next }) {
    const p = prev
        ? `<a href="/song/PREV"${emitsPrevAttr ? ' data-song-nav="prev"' : ''}>p</a>`
        : '<span></span>';
    const n = next
        ? `<a href="/song/NEXT"${emitsNextAttr ? ' data-song-nav="next"' : ''}>n</a>`
        : (emitsNextPlaceholder ? '<span></span>' : '');
    return `<nav class="song-navigation"><div class="d-flex justify-content-between">${p}${n}</div></nav>`;
}

/** Re-implement the resolver's LOOKUP using the selectors pulled from app.js —
 *  first match wins, exactly as navigateSongDirection() does. */
function resolve(doc, direction) {
    for (const sel of GROUPS[direction]) {
        const el = doc.querySelector(sel);
        if (el) return el.getAttribute('href');
    }
    return null;
}

for (const [label, shape] of Object.entries(SHAPES)) {
    const doc = new JSDOM(render(shape)).window.document;
    const gotPrev = resolve(doc, 'prev');
    const gotNext = resolve(doc, 'next');
    const wantPrev = shape.prev ? '/song/PREV' : null;
    const wantNext = shape.next ? '/song/NEXT' : null;
    check(`${label}: ← resolves to ${wantPrev ?? 'nothing'}`, gotPrev === wantPrev, `got ${gotPrev}`);
    check(`${label}: → resolves to ${wantNext ?? 'nothing'}`, gotNext === wantNext, `got ${gotNext}`);
}

console.log('\n3 — the named selector is tried FIRST, with a fallback for stale fragments\n');

/* Order is the whole point. A service-worker-cached fragment served from
   before the fix carries no data-song-nav attributes, so a positional fallback
   has to stay — but it must be the SECOND choice, or every fresh page keeps
   using the selector that was wrong. This is a structural assertion because
   "which one did it try first" is not observable from the result on markup
   where both happen to agree. */
const prevIdx = body.indexOf('data-song-nav="prev"');
const posIdx  = body.indexOf('a:first-child');
check('navigateSongDirection() prefers [data-song-nav] over the positional selector',
    prevIdx !== -1 && (posIdx === -1 || prevIdx < posIdx),
    `data-song-nav at ${prevIdx}, positional at ${posIdx}`);
check('a positional fallback survives, so a stale cached fragment still navigates',
    posIdx !== -1, 'no positional fallback found — pre-fix cached fragments would stop navigating');

/* And prove the preference actually bites: given a fragment where the two
   disagree, the named attribute must win. */
const conflicting = '<nav class="song-navigation"><div>'
    + '<a href="/song/NAMED-NEXT" data-song-nav="next">n</a>'
    + '<a href="/song/POSITIONAL-LAST">x</a>'
    + '</div></nav>';
const cdoc = new JSDOM(conflicting).window.document;
check('when the two selectors disagree, the named one wins',
    (cdoc.querySelector('[data-song-nav="next"]') || {}).getAttribute?.('href') === '/song/NAMED-NEXT');

console.log('\n4 — the ArrowLeft/ArrowRight case ignores the keys while a presentation overlay is open (#2065)\n');

/**
 * #2065: present-mode.js's own slide-by-slide overlay binds these same two
 * keys via its own `document.addEventListener('keydown', …)`, registered
 * AFTER this app.js listener. Its `preventDefault()` cannot stop an
 * earlier-registered sibling listener from also firing, so one press both
 * advanced the presentation slide AND walked the background song page —
 * the exact double-fire class the PageDown/PageUp case just below it
 * already guards against with its own `.presentation-overlay` check.
 *
 * This asserts the SOURCE SHAPE (the guard runs before the navigation
 * call), not the runtime behaviour — a jsdom simulation of two independent
 * `document.addEventListener('keydown', …)` listeners racing each other
 * would mostly be re-testing jsdom's event dispatch order, not this fix.
 *
 * Bounded up to (not including) the NEXT `case ` label at the switch's own
 * indentation — not the first `break;`, because the #2065 guard itself is
 * an early `if (…) break;`, so a first-`break;` bound would stop at that
 * guard and never see the real navigation call after it. Two drafts of
 * this bound were each wrong in a way that only showed up against the
 * real file (rule #34): bounding on the first `break;` sliced off the
 * navigation call itself (false FAIL); starting the search at `case
 * 'ArrowLeft':` then matched ZERO characters, because the very next line
 * is ALSO a `case` label at this indentation — `case 'ArrowRight':`,
 * the fallthrough sibling — so the lookahead fired immediately. Starting
 * at `case 'ArrowRight':` instead lands after BOTH labels, at the start
 * of their one shared body.
 */
const arrowCaseMatch = /case 'ArrowRight':[\s\S]*?(?=\n {16}case )/.exec(appSrc);
check('found the ArrowLeft/ArrowRight case in app.js\'s keydown handler', !!arrowCaseMatch);

const arrowCase = arrowCaseMatch ? arrowCaseMatch[0] : '';
const overlayIdx = arrowCase.indexOf('.presentation-overlay');
const navCallIdx = arrowCase.indexOf('navigateSongDirection(');
check('the case checks .presentation-overlay BEFORE calling navigateSongDirection()',
    overlayIdx !== -1 && navCallIdx !== -1 && overlayIdx < navCallIdx,
    `.presentation-overlay at ${overlayIdx}, navigateSongDirection( at ${navCallIdx} (both -1 means not found)`);

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\n":last-child" means "last child of its parent", NOT "the last match".');
    console.error('When song.php omits the next link entirely, the previous link becomes both');
    console.error('the first AND the last child, and → walks the user backwards with no error.');
    process.exit(1);
}
console.log('\nAll song navigation direction assertions passed.');
