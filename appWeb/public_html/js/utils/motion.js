/**
 * iHymns — Shared reduced-motion check (a11y audit L7, 2026-08-30)
 * ==================================================================
 *
 * ELI5: some people ask their phone or computer to keep animations to a
 * minimum (motion sickness, vestibular disorders, or just a preference).
 * This one function answers "should THIS animation be skipped?" by
 * checking both places that preference can live — the app's own
 * Settings toggle, and the operating system's own setting — so a JS-
 * driven scroll can honour whichever one applies, not just the app's.
 *
 * WHY THIS EXISTS
 * ----------------
 * `settings.js`'s in-app "Reduce motion" toggle sets a `reduce-motion`
 * class on `<body>`, and CSS's `scroll-behavior: auto !important` rule
 * (accessibility.css) already makes CSS-driven smooth scrolling stop for
 * that class. But an explicit `{ behavior: 'smooth' }` passed to
 * `Element.scrollIntoView()` or `window.scrollTo()` is a JAVASCRIPT
 * option, not a CSS property — the `scroll-behavior` CSS rule does NOT
 * override it. Three call sites already re-implemented the SAME
 * `document.body.classList.contains('reduce-motion') ? 'auto' :
 * 'smooth'` ternary independently (router.js, songbook-index.js,
 * service-follow.js) — none of them also checked the OS-level
 * `prefers-reduced-motion` media query, so a user who has that OS
 * setting on but has never opened this app's Settings page still got
 * animated scrolls. A fourth site (live-follow.js) passed `'smooth'`
 * unconditionally, checking neither.
 *
 * This is strictly 2.3.3 Animation from Interactions (AAA, not AA), but
 * the app clearly already intends to honour reduced motion (the CSS rule,
 * the in-app toggle, and three of the four call sites already trying) —
 * this closes the gap between "trying" and actually covering both
 * sources of the preference, in one place instead of four.
 * @link https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion
 * @link https://www.w3.org/WAI/WCAG21/Understanding/animation-from-interactions.html
 */

/**
 * @returns {boolean} true when motion should be minimised — either the
 *   in-app "Reduce motion" toggle is on (`<body class="reduce-motion">`,
 *   set by settings.js's applyReduceMotion()) OR the OS/browser-level
 *   `prefers-reduced-motion: reduce` media query matches. Defensive
 *   try/catch around each check: this runs in module scope that could in
 *   principle be reached before `document.body` exists, or in an
 *   environment without `matchMedia` — either should degrade to "don't
 *   assume reduced motion", never throw.
 */
export function prefersReducedMotion() {
    try {
        if (document.body?.classList.contains('reduce-motion')) { return true; }
    } catch (_e) { /* fall through to the media-query check */ }

    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (_e) {
        return false;
    }
}
