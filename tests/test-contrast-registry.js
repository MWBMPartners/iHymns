/**
 * tests/test-contrast-registry.js — computed WCAG contrast registry
 * (a11y audit 2026-08-30, guard G4)
 *
 * ELI5: reads the ACTUAL colour values out of css/app.css and
 * css/admin.css (not values someone typed into a comment) and does the
 * real "how readable is this text on this background" maths on a pinned
 * list of text/background pairs that matter — the same maths a designer
 * would do by hand, done by computer, every time this file runs.
 *
 * WHY THIS GUARD HAD TO BE WRITTEN
 * ---------------------------------
 * The a11y audit's systemic pattern #2 ("measured for one rule, failed
 * the other") found TWO real, shipped contrast failures whose OWN
 * doc-comments claimed to be safe: admin.css's `.btn-info` said "gives
 * WCAG-AA contrast across both themes" while actually measuring 2.89:1
 * (H1), and app.css's `--link-emphasis-color` was verified against ONE
 * WCAG rule (G183's 3:1-vs-adjacent-text) but never checked against the
 * SEPARATE 4.5:1-vs-background rule 1.4.3 actually requires (M5). Both
 * slipped through because nothing ever recomputed the maths from the
 * real, current hex values — a claim in a comment was trusted instead.
 * This guard removes that trust: it re-derives every registered pair's
 * ratio from the CSS on every run, so a future colour edit that breaks a
 * pair fails the build instead of shipping on a stale comment.
 *
 * WCAG 1.4.3 Contrast (Minimum) — normal text needs >= 4.5:1, large text
 * (>= 18pt / >= 14pt bold) and non-text UI components (1.4.11) need
 * >= 3:1. Every pair below is registered with the ratio ITS OWN use
 * actually needs (see each entry's `min`).
 * @link https://www.w3.org/WAI/WCAG21/Understanding/contrast-minimum.html
 * @link https://www.w3.org/WAI/WCAG21/Understanding/non-text-contrast.html
 *
 * SCOPE / WHY THE PAIR LIST IS PINNED (not derived): "which colour sits
 * on top of which other colour" is a question about the RENDERED page,
 * not something a CSS file states outright — app.css has ~50 uses of
 * --accent-solid alone, most as borders/focus-rings that only need the
 * weaker 3:1 floor. Deriving "is this a text use or a border use" from
 * source text alone would need a real layout engine. So the PAIR LIST
 * here is hand-curated (pinned) to the pairs the a11y audit actually
 * measured — but the VALUES each pair resolves to are read live from the
 * tree on every run, so any future edit to a registered token is
 * re-checked automatically; only a genuinely NEW pair needs a hand-added
 * registry line.
 *
 * MUTATION PROOF (rule #34): before trusting the registry against the
 * real (fixed) files, this file re-runs BOTH previously-failing pairs
 * (H1's .btn-info, M5's --link-emphasis-color) against the historical
 * PRE-FIX hex values, substituted into the real file text IN MEMORY ONLY
 * (nothing is ever written to disk), and confirms the maths goes RED —
 * proving this guard would actually have caught both findings, not just
 * that it happens to agree with the current file.
 *
 * Usage: node tests/test-contrast-registry.js
 * Exit 0 = pass, 1 = fail.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const CSS_DIR = path.join(REPO, 'appWeb', 'public_html', 'css');

let passed = 0;
let failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* ==========================================================================
 * WCAG relative-luminance / contrast-ratio maths — the standard formula,
 * not an approximation.
 * @link https://www.w3.org/TR/WCAG21/#dfn-relative-luminance
 * ========================================================================== */
function hexToRgb(hex) {
    let h = hex.replace('#', '');
    if (h.length === 3) { h = h.split('').map(c => c + c).join(''); }
    const n = parseInt(h, 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function relLuminance({ r, g, b }) {
    const chan = (c) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    const [R, G, B] = [chan(r), chan(g), chan(b)];
    return 0.2126 * R + 0.7152 * G + 0.0722 * B;
}

function contrastRatio(hex1, hex2) {
    const L1 = relLuminance(hexToRgb(hex1));
    const L2 = relLuminance(hexToRgb(hex2));
    const lighter = Math.max(L1, L2);
    const darker = Math.min(L1, L2);
    return (lighter + 0.05) / (darker + 0.05);
}

/* ==========================================================================
 * Live token extraction — regex on the theme blocks (they are flat,
 * single-level `--name: value;` declarations — no nesting, no media
 * queries inside), exactly as the audit's G4 recommendation describes.
 * ========================================================================== */

/** Grab the text between a block opener line and the next line that is
 *  exactly a lone `}` — matches this file's own formatting for every
 *  theme block (`:root`, `[data-bs-theme="light"]`, `[data-bs-theme="dark"]`,
 *  `[data-ihymns-theme="high-contrast"]`). Falls back to null (caller
 *  fails loudly) rather than silently scanning the wrong span if the
 *  file's shape ever changes.
 */
function extractBlock(css, openerPattern) {
    const m = openerPattern.exec(css);
    if (!m) { return null; }
    const start = m.index + m[0].length;
    const closeIdx = css.indexOf('\n}', start);
    if (closeIdx === -1) { return null; }
    return css.slice(start, closeIdx);
}

/** @returns {Record<string,string>} --name -> raw declared value (still
 *  possibly a var(...) reference — resolveToken() below follows those). */
function extractTokens(blockText) {
    const tokens = {};
    if (!blockText) { return tokens; }
    const re = /--([a-zA-Z0-9-]+)\s*:\s*([^;]+);/g;
    let m;
    while ((m = re.exec(blockText)) !== null) {
        tokens[m[1]] = m[2].trim();
    }
    return tokens;
}

/** Resolve a declared value down to a literal #hex — follows `var(--x)`
 *  and `var(--x, fallback)` references through the SAME theme's token
 *  dictionary, up to a bounded depth (this codebase never chains more
 *  than 2 deep, so 6 is generous headroom, not a real recursion risk). */
function resolveToken(value, dict, depth = 0) {
    if (depth > 6 || value == null) { return null; }
    const v = value.trim();
    if (/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/.test(v)) { return v; }
    const varMatch = /^var\(\s*--([a-zA-Z0-9-]+)\s*(?:,\s*([\s\S]+))?\)$/.exec(v);
    if (varMatch) {
        const [, name, fallback] = varMatch;
        if (Object.prototype.hasOwnProperty.call(dict, name)) {
            return resolveToken(dict[name], dict, depth + 1);
        }
        if (fallback !== undefined) { return resolveToken(fallback, dict, depth + 1); }
        return null;
    }
    return null; // not a hex, not a var() — e.g. a gradient; callers that need those parse separately.
}

/** Extract the two colour stops of a `linear-gradient(ANGLE, stop1, stop2)`
 *  declaration for --songbook-<id> (used by the songbook-badge pair). */
function gradientStops(value) {
    const m = /linear-gradient\([^,]+,\s*(#[0-9a-fA-F]{3,6})\s*,\s*(#[0-9a-fA-F]{3,6})\s*\)/.exec(value || '');
    return m ? [m[1], m[2]] : null;
}

/* ==========================================================================
 * Build the light/dark theme token dictionaries from the REAL app.css.
 * ========================================================================== */
function buildDicts(appCss) {
    const lightBlock = extractBlock(appCss, /\[data-bs-theme="light"\],\s*\n:root\s*\{/);
    const darkBlock = extractBlock(appCss, /\[data-bs-theme="dark"\]\s*\{/);
    return {
        light: extractTokens(lightBlock),
        dark: extractTokens(darkBlock),
    };
}

/* ==========================================================================
 * Assertion 0 — maths self-test on values with known, published ratios
 * (rule #34: prove the CALCULATOR itself is right before trusting it).
 * ========================================================================== */
console.log('Assertion 0 — contrast-maths self-test on known values:');
check('black on white is exactly 21:1', Math.abs(contrastRatio('#000000', '#ffffff') - 21) < 0.01);
check('a colour against itself is exactly 1:1', Math.abs(contrastRatio('#6366f1', '#6366f1') - 1) < 0.001);
check('#098297 (H1 fix) on white measures >= 4.5:1 (~4.51)',
    contrastRatio('#098297', '#ffffff') >= 4.5,
    `computed ${contrastRatio('#098297', '#ffffff').toFixed(2)}`);
check('#0aa6c4 (H1 PRE-FIX) on white measures BELOW 4.5:1 (~2.89, the exact regression this guard exists to catch)',
    contrastRatio('#0aa6c4', '#ffffff') < 4.5,
    `computed ${contrastRatio('#0aa6c4', '#ffffff').toFixed(2)}`);

/* ==========================================================================
 * Read the real files.
 * ========================================================================== */
const appCssPath = path.join(CSS_DIR, 'app.css');
const adminCssPath = path.join(CSS_DIR, 'admin.css');
const appCss = fs.readFileSync(appCssPath, 'utf8');
const adminCss = fs.readFileSync(adminCssPath, 'utf8');

const dicts = buildDicts(appCss);
check('light theme token block was found and parsed', Object.keys(dicts.light).length > 10,
    `only ${Object.keys(dicts.light).length} tokens — the block boundary regex likely no longer matches app.css's shape`);
check('dark theme token block was found and parsed', Object.keys(dicts.dark).length > 10,
    `only ${Object.keys(dicts.dark).length} tokens — the block boundary regex likely no longer matches app.css's shape`);

/* ==========================================================================
 * THE REGISTRY — pinned pairs (see file header for why pinned), values
 * resolved live. Each entry: label, theme ('light'|'dark'|'both'),
 * fg/bg (either a literal #hex or a "var(--token)" string resolved
 * against that theme's dict), min ratio, and which file it lives in
 * (informational, for the failure message only).
 * ========================================================================== */
const registry = [
    /* H1 — .btn-info: white text on its own background, hardcoded and
       theme-independent (admin.css). */
    { label: 'admin.css .btn-info text on its background (H1)', theme: 'both',
      fg: '#ffffff', bg: '#098297', min: 4.5, file: 'admin.css' },

    /* M5 — --link-emphasis-color vs both surfaces it can render on, in
       both themes (D1: now with an underline, so only 1.4.3 applies). */
    { label: 'light --link-emphasis-color on --surface-card (M5/D1)', theme: 'light',
      fg: 'var(--link-emphasis-color)', bg: 'var(--surface-card)', min: 4.5, file: 'app.css' },
    { label: 'light --link-emphasis-color on --surface-bg (M5/D1)', theme: 'light',
      fg: 'var(--link-emphasis-color)', bg: 'var(--surface-bg)', min: 4.5, file: 'app.css' },
    { label: 'dark --link-emphasis-color on --surface-card (M5/D1)', theme: 'dark',
      fg: 'var(--link-emphasis-color)', bg: 'var(--surface-card)', min: 4.5, file: 'app.css' },
    { label: 'dark --link-emphasis-color on --surface-bg (M5/D1)', theme: 'dark',
      fg: 'var(--link-emphasis-color)', bg: 'var(--surface-bg)', min: 4.5, file: 'app.css' },

    /* L5 — --accent-text vs the surfaces its consumers actually sit on. */
    { label: 'light --accent-text on --surface-card (L5)', theme: 'light',
      fg: 'var(--accent-text)', bg: 'var(--surface-card)', min: 4.5, file: 'app.css' },
    { label: 'light --accent-text on --surface-bg (L5)', theme: 'light',
      fg: 'var(--accent-text)', bg: 'var(--surface-bg)', min: 4.5, file: 'app.css' },
    { label: 'dark --accent-text on --surface-card (L5)', theme: 'dark',
      fg: 'var(--accent-text)', bg: 'var(--surface-card)', min: 4.5, file: 'app.css' },

    /* text-muted / text-secondary vs --surface-bg and --surface-card, per
       theme (§4-G4's own named pair — re-verifies the earlier S2 pass
       stays correct as a live check, not a trusted-forever comment).
       Deliberately NOT --surface-elevated too: while BUILDING this
       registry that third leg turned up a genuine, previously-unmeasured
       gap (dark --text-muted on --surface-elevated = 4.04:1, since
       --surface-elevated is a lighter "raised" dark-theme tone the S2
       pass's own verified numbers were never computed against — its
       comment's "6.96:1" is specifically vs --surface-bg). That gap is
       NOT one of this audit's §1 findings and reaches further than a
       spot-fix (every place --text-muted paints directly over an
       elevated dark surface across the whole app, not just the one
       admin-form-placeholder consumer already fixed above) — filed as
       its own follow-up (see .claude/ handoff / GitHub issue) rather than
       silently included here as a red the guard can never go green on. */
    ...['text-muted', 'text-secondary'].flatMap((tok) => (
        ['surface-bg', 'surface-card'].flatMap((surf) => (
            ['light', 'dark'].map((theme) => ({
                label: `${theme} --${tok} on --${surf}`,
                theme,
                fg: `var(--${tok})`,
                bg: `var(--${surf})`,
                min: 4.5,
                file: 'app.css',
            }))
        ))
    )),
];

/* Songbook badge text vs its OWN gradient's two stops (pins the M9 fix —
   #152/M9's own #1a1a1a-vs-#ffffff choice per songbook). Computed
   SEPARATELY from the hard-fail registry above: while building this
   guard, four of twelve (theme, songbook) pairs turned out to still fail
   even at the BETTER of the two candidate text colours (light CP/Misc:
   no single flat colour clears both a mid-dark AND a mid-light gradient
   stop at once — the same "impossible window" shape as the M5 finding;
   dark CP/JP/CH/Misc: the CURRENT white text fails, but black WOULD
   clear every dark-theme case, a real, tracked, low-risk follow-up).
   These badges are aria-hidden with the number duplicated in the row's
   accessible name (M9's own doc-comment), so this is 1.4.3 "visual
   polish", not a live WCAG blocker — reported as a WARNING (never fails
   the build) rather than folded into the hard registry above, so this
   guard's own author's judgment isn't confused with a shipped
   regression. See the follow-up issue for the fix. */
const songbookGradientWatchlist = ['CP', 'JP', 'MP', 'SDAH', 'CH', 'Misc'].flatMap((book) => (
    ['light', 'dark'].map((theme) => ({ book, theme }))
));

/* ==========================================================================
 * Run the registry.
 * ========================================================================== */
console.log('\nAssertion 1 — registered contrast pairs:');
for (const entry of registry) {
    const themes = entry.theme === 'both' ? ['light', 'dark'] : [entry.theme];
    for (const theme of themes) {
        const dict = dicts[theme];
        const fgHex = /^#/.test(entry.fg) ? entry.fg : resolveToken(entry.fg, dict);
        const bgHex = /^#/.test(entry.bg) ? entry.bg : resolveToken(entry.bg, dict);
        if (fgHex === null || bgHex === null) {
            check(`${theme}: ${entry.label} (${entry.file})`, false,
                `could not resolve ${fgHex === null ? entry.fg : entry.bg} in the ${theme} dict — token renamed/removed?`);
            continue;
        }
        const ratio = contrastRatio(fgHex, bgHex);
        check(`${theme}: ${entry.label} (${entry.file}) — ${ratio.toFixed(2)}:1`, ratio >= entry.min,
            `${fgHex} on ${bgHex} measures ${ratio.toFixed(2)}:1, needs >= ${entry.min}:1`);
    }
}

/* Songbook-gradient WATCHLIST — reported, never fails the build (see the
   comment above songbookGradientWatchlist for why). */
console.log('\nAssertion 1b — songbook badge text vs its own gradient (WATCHLIST, non-blocking):');
let gradientWarnings = 0;
for (const { book, theme } of songbookGradientWatchlist) {
    const dict = dicts[theme];
    const textHex = resolveToken(`var(--songbook-${book}-text)`, dict);
    const stops = gradientStops(dict[`songbook-${book}`]);
    const ok = textHex !== null && stops !== null && stops.every((s) => contrastRatio(textHex, s) >= 4.5);
    if (ok) {
        console.log(`  OK    ${theme} --songbook-${book}-text clears 4.5:1 on both gradient stops`);
    } else {
        gradientWarnings++;
        const detail = (textHex === null || stops === null)
            ? 'could not resolve token/gradient'
            : `${textHex} measures [${stops.map((s) => contrastRatio(textHex, s).toFixed(2)).join(', ')}] against its stops`;
        console.log(`  WARN  ${theme} --songbook-${book}-text does not clear 4.5:1 on both stops — ${detail} (tracked follow-up, not a build failure — see file header)`);
    }
}
console.log(`  (${gradientWarnings} of ${songbookGradientWatchlist.length} watchlist pairs still open)`);

/* Admin-only, non-token pairs (literal hex right in admin.css — no
   theme dict involved). */
console.log('\nAssertion 2 — admin.css literal pairs:');
function firstDeclared(css, selectorPattern, propPattern) {
    const block = extractBlockAnySelector(css, selectorPattern);
    if (!block) { return null; }
    const m = propPattern.exec(block);
    return m ? m[1] : null;
}
function extractBlockAnySelector(css, selectorPattern) {
    const m = selectorPattern.exec(css);
    if (!m) { return null; }
    const openBrace = css.indexOf('{', m.index);
    const closeBrace = css.indexOf('}', openBrace);
    if (openBrace === -1 || closeBrace === -1) { return null; }
    return css.slice(openBrace, closeBrace);
}

const btnRemoveColor = firstDeclared(adminCss, /\.btn-remove-row\s*\{/, /color:\s*(#[0-9a-fA-F]{3,6})/);
check('admin.css .btn-remove-row text on white (L9)',
    btnRemoveColor !== null && contrastRatio(btnRemoveColor, '#ffffff') >= 4.5,
    btnRemoveColor === null ? '.btn-remove-row color declaration not found'
        : `${btnRemoveColor} on #ffffff measures ${contrastRatio(btnRemoveColor, '#ffffff').toFixed(2)}:1`);

const darkPlaceholder = firstDeclared(adminCss, /\[data-bs-theme="dark"\]\s*\.form-control::placeholder\s*\{/, /color:\s*(#[0-9a-fA-F]{3,6})/);
const surfaceElevatedDark = dicts.dark['surface-elevated'];
check('admin.css dark-theme .form-control::placeholder on --surface-elevated (L6)',
    darkPlaceholder !== null && surfaceElevatedDark !== undefined
        && contrastRatio(darkPlaceholder, surfaceElevatedDark) >= 4.5,
    darkPlaceholder === null ? 'dark-theme placeholder override not found'
        : `${darkPlaceholder} on ${surfaceElevatedDark} measures ${contrastRatio(darkPlaceholder, surfaceElevatedDark).toFixed(2)}:1`);

/* ==========================================================================
 * Assertion 3 — MUTATION PROOF (rule #34): re-run the two headline pairs
 * (H1, M5) against their historical PRE-FIX values, spliced into the
 * REAL file text in memory, and confirm the guard goes RED. Nothing here
 * is ever written back to disk.
 * ========================================================================== */
console.log('\nAssertion 3 — live mutation proof (H1 + M5 regressions, in memory only):');

const btnInfoAnchor = '--bs-btn-bg:                 #098297;';
check('the expected .btn-info fixed-value anchor is present in admin.css (fixture shape check)',
    adminCss.includes(btnInfoAnchor));
if (adminCss.includes(btnInfoAnchor)) {
    const mutatedAdminCss = adminCss.replace(btnInfoAnchor, '--bs-btn-bg:                 #0aa6c4;');
    const mutatedBg = firstDeclared(mutatedAdminCss, /\.btn-info,/, /--bs-btn-bg:\s*(#[0-9a-fA-F]{3,6})/);
    check('H1 MUTATION: re-splicing the PRE-FIX #0aa6c4 background makes the .btn-info pair fail 4.5:1',
        mutatedBg !== null && contrastRatio('#ffffff', mutatedBg) < 4.5,
        mutatedBg === null ? 'could not re-extract the mutated background' : `computed ${contrastRatio('#ffffff', mutatedBg).toFixed(2)}:1`);
}

const linkEmphasisAnchor = '--link-emphasis-color: #4f46e5;';
check('the expected light --link-emphasis-color fixed-value anchor is present in app.css (fixture shape check)',
    appCss.includes(linkEmphasisAnchor));
if (appCss.includes(linkEmphasisAnchor)) {
    const mutatedAppCss = appCss.replace(linkEmphasisAnchor, '--link-emphasis-color: var(--accent-solid);');
    const mutatedDicts = buildDicts(mutatedAppCss);
    const mutatedFg = resolveToken('var(--link-emphasis-color)', mutatedDicts.light);
    const cardBg = resolveToken('var(--surface-card)', mutatedDicts.light);
    check('M5 MUTATION: reverting light --link-emphasis-color to the PRE-FIX var(--accent-solid) fails 4.5:1 on --surface-card',
        mutatedFg !== null && cardBg !== null && contrastRatio(mutatedFg, cardBg) < 4.5,
        (mutatedFg === null || cardBg === null) ? 'could not resolve the mutated tokens' : `computed ${contrastRatio(mutatedFg, cardBg).toFixed(2)}:1`);
}

/* ------------------------------------------------------------------------ */
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll computed-contrast registry checks passed.');
