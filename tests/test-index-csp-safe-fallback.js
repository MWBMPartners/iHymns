/**
 * tests/test-index-csp-safe-fallback.js — public-shell CDN fallback must be CSP-safe (#1832)
 *
 * ELI5: the public site loads Bootstrap / Font Awesome / Bootstrap-Icons /
 * Animate.css from a CDN, and if the CDN is down it's supposed to quietly load
 * a copy we host ourselves instead. That "load our copy" rescue used to be
 * written as an inline `onerror="…"` on the <link> tag. But index.php sends a
 * strict Content-Security-Policy (`script-src 'self' 'nonce-…'`, no
 * 'unsafe-inline') and a browser REFUSES inline event handlers under that
 * policy — so the rescue silently never ran and, on a CDN outage, the page
 * loaded unstyled. The fix moves the rescue into a nonce'd <script> that checks
 * each stylesheet's `.sheet` (null ⇒ it failed) and swaps to the local copy —
 * the same shape the jQuery/Bootstrap-JS fallbacks in the same file already use.
 * This guard makes sure the CSP-refused inline-handler pattern never comes back
 * to the public shell, and that the CSP-safe replacement stays wired.
 *
 * WHY THIS EXISTS
 * ----------------
 * The bug (#1832) was found by the browser smoke test (#1831) as a CSP console
 * violation, not by any behaviour break — because it only bites when a CDN is
 * unreachable, exactly the moment nobody is watching the console. Inline
 * event-handler attributes are governed by `script-src`; a nonce enables
 * <script> ELEMENTS, never inline `on*=` ATTRIBUTES, and 'unsafe-inline' /
 * 'unsafe-hashes' are deliberately absent (CLAUDE.md rule #30 — never add them).
 * So the only correct fix is to stop emitting the inline handler and do the
 * fallback from a nonce'd script.
 * https://developer.mozilla.org/docs/Web/HTTP/CSP#inline_event_handlers
 *
 * WHAT IS CHECKED (all tree-derived from the real files, mutation-proven)
 * ----------------------------------------------------------------------
 *   1. BUG ABSENT: index.php's literal HTML (with <script>/<?php?>/<!--…-->
 *      stripped) carries NO inline `on*=` event-handler attribute — the
 *      CSP-refused pattern.
 *   2. FIX PRESENT + NON-VACUOUS: index.php has a `<script nonce=…>` block that
 *      reads each CDN <link>'s `.sheet` and swaps `.href` to the local copy,
 *      covering all four CDN stylesheet ids.
 *   3. ICONS HELPER OPTED OUT ON THE SHELL: index.php calls
 *      `ihymns_bootstrap_icons_css_link(false)` so the shared helper does not
 *      emit its (CSP-dead) inline onerror on the public shell.
 *   4. ADMIN PATH PRESERVED: the shared helper still defaults
 *      `$inlineFallback = true`, so /manage/* (which sends NO CSP — see
 *      head-libs.php) keeps its working inline fallback and is not collateral.
 *
 * SCOPE/LIMITS: static source analysis. It proves the markup/wiring is present
 * and the refused pattern is gone; it does not execute a browser (that is the
 * #1831 smoke test's job). Runs under `node tools/run-node-tests.js`.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ROOT = path.resolve(__dirname, '..');
const INDEX_PHP = path.join(ROOT, 'appWeb/public_html/index.php');
const HELPER_PHP = path.join(ROOT, 'appWeb/public_html/includes/bootstrap_assets.php');

/* The four CDN stylesheets that carry a CDN→/vendor fallback in the shell. The
   first three ids are literal <link id="…"> in index.php; bootstrap-icons-css
   comes from the shared helper, but its id must still be addressed by the
   nonce'd fallback script, so all four are expected in that script. */
const CDN_CSS_IDS = ['bootstrap-css', 'fontawesome-css', 'bootstrap-icons-css', 'animatecss'];

let failures = 0;
const fail = (msg) => { console.log(`  ❌ ${msg}`); failures++; };
const pass = (msg) => { console.log(`  ✔ ${msg}`); };

/* ---- read the tree ------------------------------------------------------- */
let indexSrc = '';
let helperSrc = '';
try {
    indexSrc = fs.readFileSync(INDEX_PHP, 'utf8');
    helperSrc = fs.readFileSync(HELPER_PHP, 'utf8');
} catch (e) {
    console.log(`FATAL: could not read a source file: ${e.message}`);
    process.exit(1);
}

/* Non-vacuity: a truncated/empty read must not pass silently (rule #34). */
if (indexSrc.length < 5000) { fail(`index.php read looks truncated (${indexSrc.length} bytes)`); }
if (helperSrc.length < 1000) { fail(`bootstrap_assets.php read looks truncated (${helperSrc.length} bytes)`); }

/* Strip the three regions where an "on…=" or "onerror" token is legitimate:
   PHP tags, HTML comments (both describe the pattern), and real <script> bodies
   (JS `this.onerror`, `el.removeAttribute`, the fallback script itself). ORDER
   MATTERS: PHP must be stripped FIRST, because the header carries `<script`
   substrings inside PHP string literals (19 `<script` vs 16 `</script>` in the
   raw file); stripping <script> elements before those fake opens are gone
   mis-pairs a lazy match, letting ONE match span from a PHP-string `<script`
   to the first real `</script>` and swallow the CDN <link>s in between — which
   silently made this guard blind (it passed with a re-added onerror). */
const htmlOnly = indexSrc
    .replace(/<\?[\s\S]*?\?>/g, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/<script\b[\s\S]*?<\/script>/gi, ' ');

/* ---- 1. bug absent ------------------------------------------------------- */
console.log('1 — no CSP-refused inline event handler in the public shell markup');
{
    /* Match an inline event-handler attribute inside an element tag:
       `<tag … onSomething=`. Anchored to a tag opening so it can't match the
       word "on…" inside ordinary attribute-value text. */
    const inlineHandler = /<[a-zA-Z][^>]*?\son[a-z]+\s*=/;
    const m = htmlOnly.match(inlineHandler);
    if (m) {
        fail(`inline event-handler attribute found in index.php markup: "${m[0].slice(-40)}" — the enforcing nonce CSP refuses it (#1832)`);
    } else {
        pass('no inline on*= handler remains in the stripped HTML');
    }
    /* Belt-and-braces on the exact bug token. */
    if (/onerror\s*=/.test(htmlOnly)) {
        fail('literal onerror= present in index.php markup (outside <script>) — the #1832 pattern');
    } else {
        pass('no literal onerror= in the shell markup');
    }
}

/* ---- 2. fix present + non-vacuous --------------------------------------- */
console.log('2 — nonce\'d CSS fallback script present and covers every CDN stylesheet');
{
    /* Isolate nonce'd <script> blocks and find the one doing the sheet-null
       swap. Deriving from the real file (not asserting a fixed string) keeps
       the guard honest about what the code actually does. */
    const scripts = indexSrc.match(/<script\b[^>]*nonce=[\s\S]*?<\/script>/gi) || [];
    const fallbackScript = scripts.find(s => /\.sheet\b/.test(s) && /\.href\s*=/.test(s));
    if (!fallbackScript) {
        fail('no nonce\'d <script> that checks link.sheet and swaps .href to a local copy — the #1832 fix is missing');
    } else {
        pass('found the nonce\'d sheet-null fallback script');
        const missing = CDN_CSS_IDS.filter(id => !fallbackScript.includes(`'${id}'`) && !fallbackScript.includes(`"${id}"`));
        if (missing.length) {
            fail(`fallback script does not cover: ${missing.join(', ')}`);
        } else {
            pass(`fallback script covers all ${CDN_CSS_IDS.length} CDN stylesheet ids`);
        }
        /* It must strip integrity/crossorigin before re-pointing, or the
           local copy's own (different) hash would fail the same way. */
        if (/removeAttribute\(\s*['"]integrity['"]\s*\)/.test(fallbackScript)) {
            pass('fallback strips integrity before re-pointing to the local copy');
        } else {
            fail('fallback script does not strip integrity — the local copy would fail SRI');
        }
    }
}

/* ---- 3. icons helper opted out on the shell ----------------------------- */
console.log('3 — index.php suppresses the shared icons helper\'s inline onerror');
{
    if (/ihymns_bootstrap_icons_css_link\(\s*false\s*\)/.test(indexSrc)) {
        pass('index.php calls ihymns_bootstrap_icons_css_link(false)');
    } else {
        fail('index.php must call ihymns_bootstrap_icons_css_link(false) so the helper does not emit a CSP-dead onerror on the public shell');
    }
}

/* ---- 4. admin path preserved -------------------------------------------- */
console.log('4 — shared helper still defaults to the inline fallback for CSP-less /manage/*');
{
    /* Default MUST stay true so admin pages (which send no CSP — head-libs.php)
       keep the working inline fallback; the shell opts out explicitly (test 3). */
    if (/function\s+ihymns_bootstrap_icons_css_link\s*\(\s*bool\s+\$inlineFallback\s*=\s*true\s*\)/.test(helperSrc)) {
        pass('ihymns_bootstrap_icons_css_link(bool $inlineFallback = true) — admin default preserved');
    } else {
        fail('shared helper must keep signature ihymns_bootstrap_icons_css_link(bool $inlineFallback = true) so /manage/* is not collateral');
    }
}

/* ---- verdict ------------------------------------------------------------- */
console.log('');
if (failures) {
    console.log(`index-csp-safe-fallback: ${failures} check(s) failed.`);
    process.exit(1);
}
console.log('index-csp-safe-fallback: all checks passed.');
process.exit(0);
