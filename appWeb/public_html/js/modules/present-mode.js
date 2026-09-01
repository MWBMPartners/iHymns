/* ==========================================================================
 *  present-mode.js — Full-screen slide-by-slide Presentation mode (#297) for
 *  the public song page ("Present" button in the toolbar).
 *
 *  Builds a full-screen overlay from the lyric components already rendered
 *  on the page (`.lyric-component` / `.lyric-label` / `.lyric-line`) — no
 *  extra network fetch, it just re-reads the DOM the song page already
 *  produced — and lets the user step through verse/chorus "slides" with
 *  buttons, keyboard arrows, or a touch swipe.
 *
 *  CSP history (#1568): this used to be an inline <script> at the bottom of
 *  includes/pages/song.php. The document sends an enforcing nonce CSP
 *  (script-src 'self' 'nonce-…', no unsafe-inline — #117), which refuses any
 *  inline <script> lacking that exact per-request nonce, and the song
 *  fragment is a shared-cache API response (/api?page=song, rule #6 in
 *  .claude/CLAUDE.md) that can never be stamped with one request's nonce —
 *  so the inline version silently never ran for any visitor. This is the
 *  SAME class of bug as the Export dropdown (#1565): moved into a real ES
 *  module the router imports directly. See router.js afterPageLoad(),
 *  page === 'song' block, which calls initPresentMode() once the song
 *  fragment is in the DOM.
 * ========================================================================== */

/**
 * Wire the "Present" button on the song page. Idempotent — the SPA injects a
 * fresh song fragment (and a fresh #btn-present) on every navigation, so the
 * `dataset.wired` guard is per-DOM-node, not a global "only once ever" flag
 * (same pattern as export-ui.js's `menu.dataset.wired`).
 */

import { announce } from '../utils/announce.js';

export function initPresentMode() {
    const btnPresent = document.getElementById('btn-present');
    if (!btnPresent || btnPresent.dataset.wired === '1') return;
    btnPresent.dataset.wired = '1';

    btnPresent.addEventListener('click', () => {
        /* Collect all song components from the rendered page */
        const comps = document.querySelectorAll('.lyric-component');
        if (comps.length === 0) return;

        const slides = [];
        comps.forEach(comp => {
            const label = comp.querySelector('.lyric-label')?.textContent?.trim() || '';
            const lines = Array.from(comp.querySelectorAll('.lyric-line')).map(l => l.textContent);
            slides.push({ label, text: lines.join('\n') });
        });

        let current = 0;

        /* The dialog's accessible name. Read from the page heading — the same
           source router.js uses for its route announcement — so a screen reader
           says "Presenting Amazing Grace" rather than an anonymous "dialog". */
        const songTitle = (document.querySelector('#page-content h1')?.textContent || '').trim();

        /* Remember what to give focus back to on close. Restoring focus is not
           optional politeness: without it, closing the overlay drops focus to
           <body> and the user's next Tab starts from the top of the document,
           losing their place entirely (WCAG 2.4.3). */
        const previouslyFocused = document.activeElement;

        /* Create overlay.
         *
         * ELI5: tell the browser this is a proper pop-up window that covers the
         * page, not just a big coloured box drawn on top of it.
         *
         * Detail (#1646 item 1, WCAG 4.1.2 + 2.4.3): this was a plain <div>.
         * Visually it covered everything; to assistive tech and to the Tab key
         * it was simply more page content. A keyboard user opening Present and
         * pressing Tab landed on the toolbar and footer links BEHIND the
         * overlay — controls they could not see, while the audience saw only
         * lyrics. A screen-reader user got no signal that anything had opened.
         *
         * role="dialog" + aria-modal="true" declares it. tabindex="-1" makes it
         * programmatically focusable so focus can be moved INTO it on open (a
         * modal that never receives focus is still one Tab away from the page
         * behind it). The name comes from the song, so the announcement is
         * "Presenting Amazing Grace" rather than an anonymous "dialog".
         * https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/ */
        const overlay = document.createElement('div');
        overlay.className = 'presentation-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', `Presenting ${songTitle || 'song'}`);
        overlay.tabIndex = -1;
        overlay.innerHTML = `
            <button class="present-close" aria-label="Close presentation">&times;</button>
            <div class="present-label"></div>
            <div class="present-lyrics"></div>
            <div class="present-nav">
                <button class="present-prev" aria-label="Previous"><i class="fa-solid fa-chevron-left me-1"></i>Prev</button>
                <span class="present-counter"></span>
                <button class="present-next" aria-label="Next">Next<i class="fa-solid fa-chevron-right ms-1"></i></button>
            </div>
        `;

        const labelEl = overlay.querySelector('.present-label');
        const lyricsEl = overlay.querySelector('.present-lyrics');
        const counterEl = overlay.querySelector('.present-counter');
        const prevBtn = overlay.querySelector('.present-prev');
        const nextBtn = overlay.querySelector('.present-next');

        function render(announceSlide = false) {
            const slide = slides[current];
            labelEl.textContent = slide.label;
            lyricsEl.textContent = slide.text;
            counterEl.textContent = (current + 1) + ' / ' + slides.length;
            prevBtn.disabled = current === 0;
            nextBtn.disabled = current === slides.length - 1;

            /* Announce slide changes (#1646 item 1, WCAG 4.1.3).
               ELI5: say which verse we just moved to, because the screen
               doesn't say it out loud by itself.
               Detail: this swaps textContent on plain elements, which is
               invisible to assistive tech — a screen-reader user pressing
               Next got total silence and no way to know whether anything had
               happened. Announced only on CHANGES, not on the first render:
               the dialog's own accessible name already covers opening, and
               announcing both would say the song twice. The label is included
               because "2 of 7" alone is not orientation. */
            if (announceSlide) {
                announce(`${slide.label || 'Slide'}, ${current + 1} of ${slides.length}`);
            }
        }

        function close() {
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
            /* ELI5: stop listening for key presses once the overlay is gone,
               the same way you'd stop watching a door once it's shut.
               Detail (#1568 bug fix): this used to rely on
               `overlay.addEventListener('remove', …)` — 'remove' is not a
               real DOM event (Element has no such event; see the Element
               event reference below), so that handler never fired and every
               close via the × button left the document-level `keydown`
               listener attached forever, leaking one listener per
               presentation opened. Removing it explicitly here, on every
               close path, is the fix.
               https://developer.mozilla.org/docs/Web/API/Element#events */
            document.removeEventListener('keydown', onKey);
            /* Un-hide the page before removing the overlay, so the document is
               never left in a state where everything is inert. */
            setBackgroundInert(false);
            overlay.remove();
            /* Give focus back to whatever opened this (#1646 item 1, WCAG
               2.4.3). Without it focus falls to <body> and the user's next Tab
               restarts from the top of the document, losing their place. */
            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus({ preventScroll: true });
            }
        }

        /**
         * Hide (or restore) everything behind the overlay from assistive tech
         * and from the Tab order.
         *
         * ELI5: while the pop-up is open, everything underneath it is switched
         * off so you cannot accidentally tab into it.
         *
         * Detail: `aria-modal="true"` tells a screen reader to confine itself
         * to the dialog, but it does NOT affect the Tab order — a keyboard user
         * would still tab straight out into the page behind. `inert` is the
         * attribute that does both: it removes the subtree from the tab order
         * AND from the accessibility tree. Applied to the overlay's SIBLINGS
         * rather than to <body>, because inerting an ancestor of the overlay
         * would disable the overlay too.
         * https://developer.mozilla.org/en-US/docs/Web/API/HTMLElement/inert
         *
         * @param {boolean} on
         */
        function setBackgroundInert(on) {
            Array.from(document.body.children).forEach(el => {
                if (el === overlay) return;
                if (on) { el.inert = true; } else { el.inert = false; }
            });
        }

        function next() { if (current < slides.length - 1) { current++; render(true); } }
        function prev() { if (current > 0) { current--; render(true); } }

        /* Navigation events */
        overlay.querySelector('.present-close').addEventListener('click', close);
        prevBtn.addEventListener('click', (e) => { e.stopPropagation(); prev(); });
        nextBtn.addEventListener('click', (e) => { e.stopPropagation(); next(); });
        /* a11y audit m5 — .present-counter is a <span>, not a <button>: this
           listener only stops a tap on the "N / M" readout from ALSO
           triggering the lyrics-area advance behind it (line ~190). It never
           had a keyboard interaction of its own, so — unlike prev/next — it
           was a focusable control with nothing to operate; slide changes are
           already announced via render()'s announce() call above. */
        counterEl.addEventListener('click', (e) => e.stopPropagation());

        /* Click on lyrics area advances */
        lyricsEl.addEventListener('click', next);

        /* Keyboard navigation. Escape now routes through close() (rather
           than removing the listener inline) so there is exactly ONE place
           that tears down this handler — see the #1568 fix note in close(). */
        function onKey(e) {
            if (e.key === 'Escape') { close(); }
            else if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); next(); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
            /* #1267 — the same PageDown/PageUp a Bluetooth foot pedal
               emits as real keystrokes, so a pedal advances slides here
               exactly as it advances lyric sections on the plain song
               page (app.js's own PageDown/PageUp case). */
            else if (e.key === 'PageDown') { e.preventDefault(); next(); }
            else if (e.key === 'PageUp') { e.preventDefault(); prev(); }
            else if (e.key === 'Tab') { trapTab(e); }
        }

        /**
         * Keep Tab inside the dialog (#1646 item 1, WCAG 2.4.3 / 2.1.2).
         *
         * ELI5: pressing Tab at the last button jumps back to the first, so you
         * can never tab your way out of the pop-up by accident.
         *
         * Detail: `inert` on the siblings already removes the page behind from
         * the tab order, so this is the second layer — it handles the wrap at
         * each end, which inert alone does not do (without it, Tab past the
         * last control moves focus to the browser chrome and the user has to
         * find their way back in). Belt and braces is right here: `inert` is
         * the newer of the two mechanisms, and losing focus out of a fullscreen
         * presentation mid-service is not a graceful degradation.
         *
         * @param {KeyboardEvent} e
         */
        function trapTab(e) {
            const focusables = Array.from(
                overlay.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
            ).filter(el => !el.disabled && el.offsetParent !== null);

            /* Every control can legitimately be disabled at once — first slide
               of a one-slide song disables both Prev and Next. Keep focus on
               the dialog itself rather than letting Tab escape. */
            if (focusables.length === 0) { e.preventDefault(); overlay.focus(); return; }

            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            const active = document.activeElement;

            if (e.shiftKey && (active === first || active === overlay)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault();
                first.focus();
            }
        }
        document.addEventListener('keydown', onKey);

        /* Touch swipe support.
           ELI5: a plain "tap" also fires touchstart+touchend a few pixels
           apart from finger jitter — 50px is chosen as comfortably above
           that noise floor but well below a real intentional swipe, so taps
           don't accidentally page forward/back.
           Detail: no formal spec value here — 50 CSS px is a common rule-
           of-thumb swipe threshold (roughly a fingertip's width) balancing
           false-positive taps against requiring an uncomfortably large drag. */
        let touchStartX = 0;
        overlay.addEventListener('touchstart', (e) => { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
        overlay.addEventListener('touchend', (e) => {
            const diff = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(diff) > 50) {
                if (diff < 0) next(); else prev();
            }
        }, { passive: true });

        render();
        document.body.appendChild(overlay);

        /* Order matters: append, THEN inert the siblings (the overlay must
           already be a child so it can be excluded), THEN move focus in.
           Focusing before inerting would move focus into an element that is
           about to have its siblings disabled — harmless, but the reverse
           order (inert first) can blur the active element and leave focus on
           <body>, which is the state this is trying to avoid. */
        setBackgroundInert(true);
        overlay.focus({ preventScroll: true });

        /* Enter fullscreen if available.
           ELI5: ask the browser to hide everything else (address bar, tabs)
           so the lyrics fill the whole screen, like a real projector.
           Detail: requestFullscreen() is user-gesture-gated and can reject
           (e.g. unsupported browser, an iframe without the `allowfullscreen`
           attribute, or a user/OS policy denial) — caught here as a no-op
           since the overlay is still fully usable windowed, just without
           the fullscreen chrome-hiding.
           https://developer.mozilla.org/docs/Web/API/Element/requestFullscreen */
        if (overlay.requestFullscreen) {
            overlay.requestFullscreen().catch(() => {});
        }
    });
}
