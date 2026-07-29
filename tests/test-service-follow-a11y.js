/**
 * iHymns — Present mode + Service Mode accessibility guards (#1646 items 1 & 3, #1665)
 *
 * Three fixes, one shared failure signature: a purely VISUAL event with no
 * non-visual equivalent, in features whose users are least able to supply the
 * missing half themselves.
 *
 *   - Present mode was a plain <div>. It covered the screen, but to the Tab key
 *     and to assistive tech it was just more page content — so a keyboard user
 *     tabbed straight into the toolbar and footer BEHIND the overlay, hitting
 *     controls they could not see while the audience saw only lyrics.
 *
 *   - Service Mode's section changes were silent. A blind congregant following
 *     a live service heard nothing when the leader moved to the bridge; they
 *     fell out of sync mid-service with no way to know it had happened.
 *
 *   - The section scroll itself was fired on a fixed 600 ms timer racing an
 *     async render (#1665). When the fragment landed late, the scroll queried
 *     nothing, matched nothing, and did nothing — silently. Comfortable on a
 *     warm desktop, which is exactly why it survived: unreproducible for the
 *     developer, intermittent for everyone else.
 *
 * Each of those is invisible in code review and invisible in manual testing by
 * anyone not using the affected input method, so they get a source guard.
 *
 *   node tests/test-service-follow-a11y.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const MODULES = join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

/* Strip comments first. Both files now carry long explanations that quote the
   very identifiers under test ("aria-modal", "setTimeout", "_pendingScroll"),
   and matching raw source would let prose satisfy — or violate — assertions
   meant to be about code. */
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

const present = strip(readFileSync(join(MODULES, 'present-mode.js'), 'utf8'));
const follow = strip(readFileSync(join(MODULES, 'service-follow.js'), 'utf8'));

console.log('\n#1646 items 1 & 3 + #1665\n');

/* ---- Present mode is a real modal dialog ------------------------------ */

check('overlay declares role="dialog"', /setAttribute\(\s*['"]role['"]\s*,\s*['"]dialog['"]/.test(present));
check('overlay declares aria-modal="true"', /aria-modal['"]\s*,\s*['"]true['"]/.test(present));
check('overlay has an accessible name', /aria-label['"]\s*,/.test(present));
check('overlay is programmatically focusable (tabindex -1)', /tabIndex\s*=\s*-1/.test(present));

check('focus is moved INTO the overlay on open', /overlay\.focus\(/.test(present));
check('the previously-focused element is captured', /previouslyFocused/.test(present));
check('focus is restored on close', /previouslyFocused[\s\S]{0,200}\.focus\(/.test(present));

/* aria-modal tells a screen reader to stay inside the dialog but does NOT
   affect the Tab order — inert is what does both. */
check('the background is inerted while open', /\.inert\s*=\s*true/.test(present));
check('the background is un-inerted on close', /\.inert\s*=\s*false/.test(present));
check('Tab is trapped inside the dialog', /trapTab/.test(present) && /Tab['"]/.test(present));

check('slide changes are announced', /announce\(/.test(present));
check('present-mode imports the shared announcer',
    /from\s+['"]\.\.\/utils\/announce\.js['"]/.test(present));

/* ---- Service Mode: section changes are audible and non-animated ------- */

check('service-follow imports the shared announcer',
    /from\s+['"]\.\.\/utils\/announce\.js['"]/.test(follow));
check('a section change is announced', /announce\(/.test(follow));

check('scroll honours the reduced-motion preference',
    /reduce-motion/.test(follow) && /behavior:\s*document\.body\.classList\.contains/.test(follow));

/* ---- #1665: the render race ------------------------------------------- */

check('the fixed 600 ms scroll timer is gone',
    !/setTimeout\([\s\S]{0,120}?,\s*600\s*\)/.test(follow));
check('the scroll waits for the fragment to exist',
    /_scrollWhenReady/.test(follow) && /requestAnimationFrame/.test(follow));
check('the wait is bounded (cannot spin forever)',
    /SF_SCROLL_TIMEOUT_MS/.test(follow));
check('a superseding broadcast cancels the pending scroll',
    /_pendingScroll\s*!==\s*index/.test(follow));

/* The original bug was a field with a writer and no reader. Writing a
   sessionStorage key nothing consumes would be the identical bug. */
const writes = (follow.match(/sessionStorage\.setItem\(\s*SF_PENDING_SCROLL/g) || []).length;
const reads = (follow.match(/sessionStorage\.getItem\(\s*SF_PENDING_SCROLL/g) || []).length;
check('the full-page-load scroll handoff has a WRITER', writes >= 1);
check('…and a READER (the half the original _pendingScroll never had)', reads >= 1);
check('the pending key is cleared so it cannot re-fire',
    /removeItem\(\s*SF_PENDING_SCROLL/.test(follow));

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll Present/Service Mode accessibility assertions passed.');
