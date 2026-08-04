/**
 * iHymns — live-region guard (#1645)
 *
 * The bug: the whole `<main>` was `aria-live="polite"`, and the SPA injects
 * every page fragment INSIDE it. So on every navigation a screen-reader user
 * had the entire new page — breadcrumb, toolbar, every lyric line — queued for
 * announcement, while `router.js` simultaneously moved focus to that same
 * element. Two competing streams of speech, on every single navigation, with
 * nothing useful in either.
 *
 * That is a misapplied live region, not a missing one. `aria-live` is for
 * STATUS MESSAGES — short and specific. Marking a whole page region live means
 * announcing everything, which is functionally the same as announcing nothing,
 * except much louder.
 *
 * The tell that it never worked: `setlist.js` had already grown its own private
 * announcer, commented "nothing announces the new song by default". A working
 * live `<main>` would have made it unnecessary.
 *
 * This guard exists because the fix is a DELETION, and deletions get undone.
 * `aria-live` on a landmark reads like an accessibility feature to anyone who
 * hasn't heard what it does, so someone will eventually "restore" it in good
 * faith. Nothing would error and no user-visible symptom would appear to a
 * sighted developer — the same silence that let it survive this long.
 *
 *   node tests/test-live-region.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const PUB = join(ROOT, 'appWeb', 'public_html');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

/* Comment-stripping is LOAD-BEARING here, not tidiness.
 *
 * The fix deliberately leaves a long HTML comment above <main> explaining why
 * aria-live must not come back — a comment that necessarily CONTAINS the string
 * "aria-live" several times. Matching raw source would read that warning as the
 * violation it warns against, and the only way to make the suite pass would be
 * to delete the explanation. A guard that pressures you to remove the reasoning
 * is worse than no guard. (Four other suites in this repo learned the same
 * lesson; see test-fragment-inline-scripts.php's doc-block.) */
const stripHtmlComments = (s) => s.replace(/<!--[\s\S]*?-->/g, '');
const stripJsComments = (s) =>
    s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

const indexSrc = stripHtmlComments(readFileSync(join(PUB, 'index.php'), 'utf8'));
const routerSrc = stripJsComments(readFileSync(join(PUB, 'js/modules/router.js'), 'utf8'));
const setlistSrc = stripJsComments(readFileSync(join(PUB, 'js/modules/setlist.js'), 'utf8'));
const announceSrc = readFileSync(join(PUB, 'js/utils/announce.js'), 'utf8');
const announceCode = stripJsComments(announceSrc);

console.log('\n#1645 — live-region guard\n');

/* ---- 1. <main> must not be a live region ------------------------------- */

/* Isolate the opening tag only. Testing the whole file would match the
   announcer's own aria-live further down and pass for the wrong reason. */
const mainTag = (indexSrc.match(/<main\b[^>]*>/) || [''])[0];

check('index.php has a <main> element to inspect', mainTag !== '');
check('<main> does NOT carry aria-live (the #1645 regression)',
    mainTag !== '' && !/aria-live/i.test(mainTag));
check('<main> does NOT carry aria-atomic',
    mainTag !== '' && !/aria-atomic/i.test(mainTag));

/* The fix must not over-shoot: tabindex is what makes the focus move possible,
   and that focus move is the CORRECT SPA route-change mechanism. Removing it
   while removing aria-live would trade one a11y bug for another. */
check('<main> KEEPS tabindex="-1" (the focus target for route changes)',
    /tabindex\s*=\s*["']-1["']/.test(mainTag));
check('<main> KEEPS role="main"',
    /role\s*=\s*["']main["']/.test(mainTag));

/* ---- 2. the shared announcer exists and behaves ------------------------ */

check('js/utils/announce.js exports announce()',
    /export\s+function\s+announce\s*\(/.test(announceCode));

check('the announcer creates a polite, atomic region',
    /aria-live/.test(announceCode) && /polite/.test(announceCode)
    && /aria-atomic/.test(announceCode));

check('the region is visually-hidden, not display:none (which would silence it)',
    /visually-hidden/.test(announceCode));

/* The empty-then-refill-next-frame dance is the single easiest thing here to
   "simplify" into permanent silence — assistive tech reports MUTATIONS to a
   region it is already observing, so a plain assignment of unchanged or
   pre-placed text is frequently never announced. */
check('the announcer clears textContent before filling it',
    /textContent\s*=\s*['"]{2}/.test(announceCode));
check('the refill happens on the next frame (requestAnimationFrame)',
    /requestAnimationFrame/.test(announceCode));

/* ---- 3. one announcer, not one per feature ----------------------------- */

check('setlist.js no longer builds its own live region',
    !(/createElement\(['"]div['"]\)[\s\S]{0,300}aria-live/.test(setlistSrc)));
check('setlist.js delegates to the shared announcer',
    /from\s+['"]\.\.\/utils\/announce\.js['"]/.test(setlistSrc)
    && /announce\(/.test(setlistSrc));
check('the old playlist-specific region id is gone',
    !/playlist-live-region/.test(setlistSrc));

/* ---- 4. the router fills the gap the deletion left --------------------- */

check('router.js imports the shared announcer',
    /from\s+['"]\.\.\/utils\/announce\.js['"]/.test(routerSrc));
check('router.js announces after a navigation',
    /announce\(/.test(routerSrc));
check('router.js still moves focus to #main-content',
    /getElementById\(['"]main-content['"]\)\?\.focus/.test(routerSrc));

/* Ordering: focus first, THEN announce. A polite region queues behind whatever
   is already being spoken, so this order gives "you landed here" followed by
   "this is what it is". Reversed, the page name is buried under the focus
   announcement. */
const focusIdx = routerSrc.indexOf("getElementById('main-content')?.focus");
const announceIdx = routerSrc.indexOf('announce(', focusIdx === -1 ? 0 : focusIdx);
check('focus happens BEFORE the announcement',
    focusIdx !== -1 && announceIdx !== -1 && announceIdx > focusIdx);

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll live-region assertions passed.');
