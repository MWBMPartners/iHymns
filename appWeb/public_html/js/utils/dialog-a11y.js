/**
 * iHymns — Shared modal-overlay focus contract (a11y audit M3, 2026-08-28)
 * ==========================================================================
 *
 * ELI5: wraps up the boring-but-load-bearing parts of "I made a popup" —
 * remembering what to give focus back to, keeping Tab from escaping the
 * popup, and closing on Escape — into one small reusable helper, so a new
 * hand-rolled overlay gets them for free instead of the author having to
 * remember all four at once.
 *
 * WHY THIS MODULE EXISTS
 * -----------------------
 * `present-mode.js` (#1646) worked this recipe out first and got it right:
 * `aria-modal`, focus moved in on open, `inert` on the background (both AT
 * and Tab order), a Tab-key trap, Escape-to-close, and focus restored to
 * whatever opened the dialog. The #1770 Live-Follow overlays
 * (`live-follow.js`'s session-code overlay, `live-host-console.js`'s
 * "drive your session" sheet) shipped later, declared `role="dialog"`, and
 * stopped there — no `aria-modal`, no focus management, no Escape, no trap,
 * no restore. A keyboard user opening either had to Tab through the entire
 * obscured page behind the overlay to reach its Close button, and Escape
 * did nothing (the CLAUDE.md modularity rule: a THIRD hand-rolled copy of
 * this recipe is the wrong fix — extracting the one present-mode.js already
 * got right is).
 *
 * `present-mode.js` itself is left calling its own original, already-correct
 * inline implementation — it works, and a modal-behaviour extraction is not
 * a description of an actual song-page bug, so rewriting it purely to
 * consume this module would be behaviour-risk for no user-facing gain. This
 * module is for every OVERLAY THAT DIDN'T HAVE THE RECIPE YET.
 *
 * WCAG 2.1.1 Keyboard, 2.1.2 No Keyboard Trap (the trap must be escapable —
 * Escape is what makes it not a trap), 2.4.3 Focus Order.
 * https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/
 */

/**
 * Every element inside `container` that can currently take keyboard focus.
 * Mirrors present-mode.js's own `trapTab()` selector — visible (not
 * `display:none`, tested via `offsetParent`), not `disabled`, and either
 * naturally focusable or explicitly `tabindex` (excluding `-1`, which means
 * "focusable via script only", not "in the Tab order").
 *
 * @param {HTMLElement} container
 * @returns {HTMLElement[]}
 */
export function getFocusableElements(container) {
    return Array.from(
        container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
    ).filter(el => !el.disabled && el.offsetParent !== null);
}

/**
 * Keep Tab (and Shift+Tab) cycling inside `container` instead of escaping
 * into the page behind it. Call this from a `keydown` listener for every
 * `Tab` keypress while the dialog is open.
 *
 * @param {KeyboardEvent} e
 * @param {HTMLElement} container
 */
export function trapTabKey(e, container) {
    if (e.key !== 'Tab') { return; }

    const focusables = getFocusableElements(container);

    /* Every control can legitimately be disabled/hidden at once — keep focus
       on the dialog itself rather than letting Tab escape to the page. */
    if (focusables.length === 0) { e.preventDefault(); container.focus(); return; }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;

    if (e.shiftKey && (active === first || active === container)) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && active === last) {
        e.preventDefault();
        first.focus();
    }
}

/**
 * Hide (or restore) everything outside `overlay` from assistive tech and
 * from the Tab order, via the `inert` attribute on `overlay`'s siblings
 * under `<body>`. `aria-modal="true"` alone tells a screen reader to
 * confine itself to the dialog but does NOT affect the Tab order; `inert`
 * is the one attribute that does both.
 * https://developer.mozilla.org/en-US/docs/Web/API/HTMLElement/inert
 *
 * @param {HTMLElement} overlay a direct child of document.body
 * @param {boolean} on
 */
export function setSiblingsInert(overlay, on) {
    Array.from(document.body.children).forEach(el => {
        if (el === overlay) { return; }
        el.inert = !!on;
    });
}

/**
 * Turn an already-`role="dialog"` overlay (already appended to the DOM)
 * into a real modal: stamps `aria-modal`, remembers what to restore focus
 * to, inerts the background, moves focus in, and wires Escape-to-close +
 * a Tab trap — all four of the things M3 found missing, in one call.
 *
 * The caller still owns removing the overlay from the DOM (every overlay
 * here has its own teardown — broadcaster cleanup, sessionStorage, etc.);
 * pass that as `onClose` so it runs as part of the SAME close path Escape,
 * the backdrop click and the Close button all funnel through, instead of
 * each dismiss route re-implementing (or forgetting) a piece of teardown.
 *
 * @param {HTMLElement} overlay
 * @param {object} [opts]
 * @param {() => void} [opts.onClose] extra teardown (e.g. `overlay.remove()`)
 * @param {HTMLElement} [opts.initialFocus] focused on open; defaults to `overlay` itself
 *   (present-mode.js's own choice — the dialog's aria-label is then the first
 *   thing announced, e.g. "Live session code, dialog").
 * @returns {() => void} close() — call this from EVERY dismiss path (Close
 *   button, backdrop click); safe to call more than once.
 */
export function openModalDialog(overlay, opts = {}) {
    overlay.setAttribute('aria-modal', 'true');
    if (!overlay.hasAttribute('tabindex')) { overlay.tabIndex = -1; }

    const previouslyFocused = document.activeElement;
    let closed = false;

    function onKey(e) {
        if (e.key === 'Escape') { close(); }
        else { trapTabKey(e, overlay); }
    }

    function close() {
        if (closed) { return; }
        closed = true;
        document.removeEventListener('keydown', onKey);
        setSiblingsInert(overlay, false);
        if (typeof opts.onClose === 'function') { opts.onClose(); }
        /* Restore focus (WCAG 2.4.3) — without this, closing drops focus to
           <body> and the user's next Tab restarts from the top of the
           document, losing their place entirely. */
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus({ preventScroll: true });
        }
    }

    document.addEventListener('keydown', onKey);
    setSiblingsInert(overlay, true);
    (opts.initialFocus || overlay).focus({ preventScroll: true });

    return close;
}
