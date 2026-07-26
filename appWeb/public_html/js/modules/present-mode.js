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

        /* Create overlay */
        const overlay = document.createElement('div');
        overlay.className = 'presentation-overlay';
        overlay.innerHTML = `
            <button class="present-close" aria-label="Close presentation">&times;</button>
            <div class="present-label"></div>
            <div class="present-lyrics"></div>
            <div class="present-nav">
                <button class="present-prev" aria-label="Previous"><i class="fa-solid fa-chevron-left me-1"></i>Prev</button>
                <button class="present-counter"></button>
                <button class="present-next" aria-label="Next">Next<i class="fa-solid fa-chevron-right ms-1"></i></button>
            </div>
        `;

        const labelEl = overlay.querySelector('.present-label');
        const lyricsEl = overlay.querySelector('.present-lyrics');
        const counterEl = overlay.querySelector('.present-counter');
        const prevBtn = overlay.querySelector('.present-prev');
        const nextBtn = overlay.querySelector('.present-next');

        function render() {
            const slide = slides[current];
            labelEl.textContent = slide.label;
            lyricsEl.textContent = slide.text;
            counterEl.textContent = (current + 1) + ' / ' + slides.length;
            prevBtn.disabled = current === 0;
            nextBtn.disabled = current === slides.length - 1;
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
            overlay.remove();
        }

        function next() { if (current < slides.length - 1) { current++; render(); } }
        function prev() { if (current > 0) { current--; render(); } }

        /* Navigation events */
        overlay.querySelector('.present-close').addEventListener('click', close);
        prevBtn.addEventListener('click', (e) => { e.stopPropagation(); prev(); });
        nextBtn.addEventListener('click', (e) => { e.stopPropagation(); next(); });
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
