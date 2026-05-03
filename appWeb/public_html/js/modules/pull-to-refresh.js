/**
 * iHymns — Pull-to-Refresh Gesture (#822)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Native-app-style pull-down-to-refresh gesture for the installed
 * PWA. Standalone PWAs hide the browser chrome that would otherwise
 * provide pull-to-refresh natively, leaving users with no equivalent
 * on a phone-installed iHymns. This module restores the gesture.
 *
 * Behaviour:
 *   - Engages only at scrollTop === 0 — mid-page scrolls are
 *     untouched.
 *   - Pull distance > REFRESH_THRESHOLD with a release fires the
 *     refresh; below threshold snaps back.
 *   - Indicator grows + spinner appears during pull, animates back
 *     to home on release / completion.
 *   - Dispatches `ihymns:refresh-requested`. Each page's controller
 *     listens, re-fetches its data, then dispatches
 *     `ihymns:refresh-complete` (the module clears its UI). A
 *     SAFETY_TIMEOUT_MS clears the spinner if no completion event
 *     arrives — never leaves the user stuck.
 *   - Disabled at /manage/* surfaces (full-page reload PHP).
 *   - Honours `prefers-reduced-motion` by skipping the animation
 *     transitions but keeping the gesture functional.
 *
 * Pointer Events (not touch / mouse separately) so the same code
 * path covers touch + mouse-drag in a desktop browser tab — useful
 * for development without needing a phone.
 */

const REFRESH_THRESHOLD     = 70;     /* px — pull distance to commit */
const MAX_PULL              = 130;    /* px — visual cap on indicator translation */
const RESISTANCE            = 0.55;   /* fractional drag-translation ratio */
const SAFETY_TIMEOUT_MS     = 5000;   /* dismiss spinner even if no completion event */
const SCROLL_TOP_TOLERANCE  = 1;      /* px — allow tiny scroll for soft-keyboard cases */

export class PullToRefresh {
    /** @param {object} app Reference to the main iHymnsApp instance */
    constructor(app) {
        this.app = app;

        /** Active pull state */
        this._startY    = null;
        this._lastY     = null;
        this._pulling   = false;     /* user is past the engagement check */
        this._refreshing = false;    /* refresh fired, waiting for completion */
        this._safetyTimer = null;

        /** Indicator DOM (lazy-created) */
        this._indicator = null;

        this._onPointerDown = this._onPointerDown.bind(this);
        this._onPointerMove = this._onPointerMove.bind(this);
        this._onPointerUp   = this._onPointerUp.bind(this);
        this._onRefreshComplete = this._onRefreshComplete.bind(this);
    }

    init() {
        /* Skip on admin / editor surfaces. /manage/* is full-page-reload
           PHP so the browser refresh button works fine; no point adding
           a gesture that competes with it. */
        if (this._isAdminSurface()) return;

        /* Pointer Events cover both touch + mouse. Wide-fallback to touch
           events if the runtime lacks pointer support (very old iOS, but
           we set passive: false so we can preventDefault selectively). */
        if (window.PointerEvent) {
            document.addEventListener('pointerdown', this._onPointerDown, { passive: true });
            document.addEventListener('pointermove', this._onPointerMove, { passive: false });
            document.addEventListener('pointerup',   this._onPointerUp,   { passive: true });
            document.addEventListener('pointercancel', this._onPointerUp, { passive: true });
        } else {
            /* Older browsers — use touch events directly. */
            document.addEventListener('touchstart', (e) => this._onPointerDown(e.touches[0]), { passive: true });
            document.addEventListener('touchmove',  (e) => this._onPointerMove(this._wrapTouch(e)), { passive: false });
            document.addEventListener('touchend',   () => this._onPointerUp(),   { passive: true });
            document.addEventListener('touchcancel',() => this._onPointerUp(),   { passive: true });
        }

        /* Page handlers signal completion via this event so we know
           when to dismiss the spinner. The 5s safety timeout fires
           if a handler forgets / errors / hangs. */
        document.addEventListener('ihymns:refresh-complete', this._onRefreshComplete);
    }

    _isAdminSurface() {
        const p = window.location.pathname || '';
        return p.startsWith('/manage/') || p.startsWith('/manage');
    }

    /* Touch event → pointer-event-shaped facade so the move handler
       can call preventDefault on the underlying touch event. */
    _wrapTouch(e) {
        const t = e.touches[0];
        return {
            clientY: t.clientY,
            preventDefault: () => e.preventDefault(),
        };
    }

    _onPointerDown(p) {
        if (this._refreshing) return;
        const y = p?.clientY;
        if (typeof y !== 'number') return;
        const top = this._scrollContainerTop();
        /* Only engage at top of page. If we're mid-scroll, every native
           scroll gesture must work normally. */
        if (top > SCROLL_TOP_TOLERANCE) return;
        this._startY = y;
        this._lastY  = y;
        this._pulling = false;
    }

    _onPointerMove(p) {
        if (this._refreshing) return;
        if (this._startY === null) return;
        const y = p?.clientY;
        if (typeof y !== 'number') return;

        const delta = y - this._startY;
        /* Negative delta = pulling up; not our gesture. Clear and bail. */
        if (delta <= 0) {
            this._reset();
            return;
        }

        /* If the page has scrolled (e.g. user touched while not at top
           and the OS started scrolling), stop the gesture — let scroll
           win. */
        if (this._scrollContainerTop() > SCROLL_TOP_TOLERANCE) {
            this._reset();
            return;
        }

        /* Engage. Apply resistance so the visual movement decelerates
           past the threshold (matches native iOS feel). */
        this._pulling = true;
        const translate = Math.min(delta * RESISTANCE, MAX_PULL);
        this._showIndicator(translate, translate >= REFRESH_THRESHOLD);

        /* Once engaged, suppress the underlying scroll/drag so the
           browser doesn't simultaneously fire its own pull-to-refresh
           (Chrome on Android still tries even in standalone). */
        if (typeof p.preventDefault === 'function') {
            try { p.preventDefault(); } catch (_e) { /* ignore */ }
        }

        this._lastY = y;
    }

    _onPointerUp() {
        if (this._refreshing) return;
        if (!this._pulling) {
            this._reset();
            return;
        }
        const delta = (this._lastY ?? this._startY) - this._startY;
        const translate = Math.min(delta * RESISTANCE, MAX_PULL);
        if (translate >= REFRESH_THRESHOLD) {
            this._fireRefresh();
        } else {
            this._dismissIndicator();
            this._reset();
        }
    }

    _scrollContainerTop() {
        return (document.scrollingElement || document.documentElement).scrollTop || 0;
    }

    /* ---------- Refresh lifecycle ---------- */

    _fireRefresh() {
        this._refreshing = true;
        this._showIndicator(REFRESH_THRESHOLD, true, /* spinning */ true);

        document.dispatchEvent(new CustomEvent('ihymns:refresh-requested'));

        /* Safety timeout — if no handler completes (or all handlers
           silently fail), un-stuck the UI. */
        this._safetyTimer = setTimeout(() => {
            this._onRefreshComplete();
        }, SAFETY_TIMEOUT_MS);

        this._reset(/* keepIndicator */ true);
    }

    _onRefreshComplete() {
        if (!this._refreshing) return;
        this._refreshing = false;
        if (this._safetyTimer) {
            clearTimeout(this._safetyTimer);
            this._safetyTimer = null;
        }
        this._dismissIndicator();
    }

    _reset(keepIndicator = false) {
        this._startY  = null;
        this._lastY   = null;
        this._pulling = false;
        if (!keepIndicator) {
            this._dismissIndicator();
        }
    }

    /* ---------- Indicator UI ---------- */

    _ensureIndicator() {
        if (this._indicator) return this._indicator;
        const wrap = document.createElement('div');
        wrap.id = 'pull-to-refresh-indicator';
        wrap.className = 'pull-to-refresh-indicator';
        wrap.setAttribute('role', 'status');
        wrap.setAttribute('aria-live', 'polite');
        wrap.setAttribute('aria-hidden', 'true');
        wrap.innerHTML =
            '<div class="ptr-circle">' +
              '<i class="fa-solid fa-arrow-down ptr-arrow" aria-hidden="true"></i>' +
              '<span class="ptr-spinner spinner-border spinner-border-sm" aria-hidden="true"></span>' +
            '</div>';
        document.body.appendChild(wrap);
        this._indicator = wrap;
        return wrap;
    }

    _showIndicator(translatePx, atOrPastThreshold, spinning = false) {
        const el = this._ensureIndicator();
        el.style.transform = `translateY(${translatePx}px)`;
        el.classList.toggle('is-armed',     atOrPastThreshold && !spinning);
        el.classList.toggle('is-spinning',  spinning);
        el.setAttribute('aria-hidden', 'false');
        if (spinning) {
            el.querySelector('.ptr-arrow')?.classList.add('d-none');
            el.querySelector('.ptr-spinner')?.classList.remove('d-none');
            el.setAttribute('aria-label', 'Refreshing');
        } else {
            el.querySelector('.ptr-arrow')?.classList.remove('d-none');
            el.querySelector('.ptr-spinner')?.classList.add('d-none');
            el.setAttribute('aria-label', atOrPastThreshold ? 'Release to refresh' : 'Pull to refresh');
        }
    }

    _dismissIndicator() {
        const el = this._indicator;
        if (!el) return;
        /* Animate back to home; CSS transition handles the easing. */
        el.style.transform = '';
        el.classList.remove('is-armed', 'is-spinning');
        el.setAttribute('aria-hidden', 'true');
    }
}
