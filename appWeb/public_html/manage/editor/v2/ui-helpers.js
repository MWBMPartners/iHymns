/* ==========================================================================
 *  ui-helpers.js — shared small UI-construction helpers for the v2 Song
 *  Editor (#1991)
 *
 *  ELI5: a few of the editor's tabs each build the same kind of tiny
 *  "just an icon" button (move up, move down, remove, publish, …) — this
 *  file is the ONE place that knows how to build one, so every tab asks
 *  here instead of each writing its own copy.
 *
 *  WHY THIS FILE EXISTS
 *  `media-tab.js`, `structure-tab.js` and `arrangement-editor.js` each
 *  defined their own private `iconBtn(icon, title, disabled, onClick)`
 *  helper — three byte-for-byte identical copies bar one class list
 *  (arrangement-editor.js's chip toolbar wanted `btn-sm` + an extra
 *  `arr-btn` sizing hook). The #1990 a11y sweep added
 *  `aria-label = title` to all three copies IDENTICALLY, which is exactly
 *  the smell CLAUDE.md's modularity rule (#1) calls out: the next a11y or
 *  styling change has to be made three times by hand, or it silently
 *  drifts. #1991 extracts the one shared implementation below and
 *  re-points all three call sites at it.
 *
 *  No existing editor-v2 "UI kit" module existed to add this to (grepped
 *  every `export function`/`export const` under manage/editor/v2/ — every
 *  file there either mounts one tab/panel or is a single-purpose data
 *  helper: store.js, reflow.js, song-key-panel.js, api-client.js), so this
 *  is a new, narrowly-scoped module — matching this directory's existing
 *  pattern of small single-purpose ES modules (reflow.js, store.js) rather
 *  than one large shared "everything" file.
 * ========================================================================== */

/**
 * Build an icon-only `<button>` — a Bootstrap-Icons glyph with no visible
 * text, named for assistive tech via `title` + `aria-label` (WCAG 4.1.2 —
 * see tests/php/test-a11y-static-checks.php's a11yIconAccessibility()
 * M8 check, #1990).
 *
 * @param {string} icon - Bootstrap-Icons glyph suffix, e.g. 'bi-arrow-up'
 *   (paired with the literal 'bi' base class inside the rendered `<i>`).
 *   @see https://icons.getbootstrap.com/
 * @param {string} title - both the native `title` tooltip AND the
 *   `aria-label` the button is announced by. Kept as ONE string rather
 *   than two independent ones on purpose — a caller can't accidentally
 *   name the tooltip and the accessible name differently.
 *   @see https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Attributes/aria-label
 * @param {boolean} disabled - initial `disabled` state.
 * @param {(e: MouseEvent) => void} onClick - click handler, wired via
 *   `addEventListener` (not an inline `onclick=` attribute).
 * @param {{ className?: string }} [opts] - `className` overrides the
 *   default `'btn btn-outline-secondary'` class list wholesale.
 *   arrangement-editor.js's three call sites pass
 *   `'btn btn-sm btn-outline-secondary arr-btn'` here — the one real
 *   difference the three original copies had — so their rendered buttons
 *   stay byte-identical to the pre-extraction markup.
 * @returns {HTMLButtonElement}
 */
export function iconBtn(icon, title, disabled, onClick, opts = {}) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = opts.className || 'btn btn-outline-secondary';
    b.title = title;
    b.setAttribute('aria-label', title);
    b.disabled = !!disabled;
    b.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i>';
    b.addEventListener('click', onClick);
    return b;
}
