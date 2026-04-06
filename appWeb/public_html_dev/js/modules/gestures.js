/**
 * iHymns — Touch Gesture Navigation Module (#143)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Detects horizontal swipe gestures on song pages and navigates to
 * the previous or next song accordingly. Provides subtle visual edge
 * peek indicators during the swipe to give directional feedback.
 *
 * Swipe left  = next song
 * Swipe right = previous song
 *
 * Respects prefers-reduced-motion. Does not interfere with vertical
 * scrolling — the swipe must be predominantly horizontal and exceed
 * a minimum distance and velocity threshold.
 */

export class Gestures {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number|null} Touch start X coordinate */
        this.startX = null;

        /** @type {number|null} Touch start Y coordinate */
        this.startY = null;

        /** @type {number|null} Touch start timestamp */
        this.startTime = null;

        /** @type {boolean} Whether the current gesture has been locked as a scroll */
        this.isScrolling = null;

        /** @type {HTMLElement|null} Edge peek indicator element */
        this.peekIndicator = null;

        /** @type {number} Minimum swipe distance in pixels */
        this.MIN_DISTANCE = 50;

        /** @type {number} Minimum swipe velocity in px/ms */
        this.MIN_VELOCITY = 0.3;

        /* Bound handlers for cleanup */
        this._onTouchStart = this._onTouchStart.bind(this);
        this._onTouchMove = this._onTouchMove.bind(this);
        this._onTouchEnd = this._onTouchEnd.bind(this);
    }

    /** Initialise — attach touch listeners to the document */
    init() {
        /* Do nothing if touch is not supported */
        if (!('ontouchstart' in window)) return;

        document.addEventListener('touchstart', this._onTouchStart, { passive: true });
        document.addEventListener('touchmove', this._onTouchMove, { passive: true });
        document.addEventListener('touchend', this._onTouchEnd, { passive: true });
    }

    /**
     * Check whether gestures should be active right now.
     * Only enabled on song pages.
     *
     * @returns {boolean}
     */
    _isActive() {
        return !!document.querySelector('.page-song');
    }

    /**
     * Check if the user prefers reduced motion.
     *
     * @returns {boolean}
     */
    _prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /**
     * Handle touchstart — record starting position and time.
     *
     * @param {TouchEvent} e
     */
    _onTouchStart(e) {
        if (!this._isActive()) return;
        if (e.touches.length !== 1) return;

        const touch = e.touches[0];
        this.startX = touch.clientX;
        this.startY = touch.clientY;
        this.startTime = Date.now();
        this.isScrolling = null;
    }

    /**
     * Handle touchmove — determine gesture direction and show edge peek.
     *
     * @param {TouchEvent} e
     */
    _onTouchMove(e) {
        if (this.startX === null) return;
        if (e.touches.length !== 1) return;

        const touch = e.touches[0];
        const dx = touch.clientX - this.startX;
        const dy = touch.clientY - this.startY;

        /* On first significant move, decide if this is a scroll or a swipe */
        if (this.isScrolling === null) {
            this.isScrolling = Math.abs(dy) > Math.abs(dx);
        }

        /* If vertical scrolling, bail out */
        if (this.isScrolling) return;

        /* Show edge peek indicator */
        if (!this._prefersReducedMotion()) {
            this._showPeek(dx);
        }
    }

    /**
     * Handle touchend — evaluate the completed gesture and navigate if valid.
     *
     * @param {TouchEvent} e
     */
    _onTouchEnd(e) {
        this._hidePeek();

        if (this.startX === null || this.isScrolling) {
            this._reset();
            return;
        }

        const touch = e.changedTouches[0];
        const dx = touch.clientX - this.startX;
        const dy = touch.clientY - this.startY;
        const elapsed = Date.now() - this.startTime;

        this._reset();

        /* Must be more horizontal than vertical */
        if (Math.abs(dy) >= Math.abs(dx)) return;

        /* Must exceed minimum distance */
        if (Math.abs(dx) < this.MIN_DISTANCE) return;

        /* Must exceed minimum velocity */
        const velocity = Math.abs(dx) / elapsed;
        if (velocity < this.MIN_VELOCITY) return;

        /* Swipe left (negative dx) = next song, swipe right = previous */
        if (dx < 0) {
            this.app.navigateSongDirection('next');
        } else {
            this.app.navigateSongDirection('prev');
        }
    }

    /** Reset touch tracking state */
    _reset() {
        this.startX = null;
        this.startY = null;
        this.startTime = null;
        this.isScrolling = null;
    }

    /**
     * Show or update the edge peek indicator.
     * A subtle gradient appears on the edge the user is swiping towards.
     *
     * @param {number} dx Horizontal displacement from touch start
     */
    _showPeek(dx) {
        if (!this.peekIndicator) {
            this.peekIndicator = document.createElement('div');
            this.peekIndicator.className = 'gesture-peek';
            this.peekIndicator.setAttribute('aria-hidden', 'true');
            document.body.appendChild(this.peekIndicator);
        }

        /* Clamp the opacity based on distance (max at MIN_DISTANCE) */
        const progress = Math.min(Math.abs(dx) / this.MIN_DISTANCE, 1);
        const opacity = progress * 0.35;

        if (dx < 0) {
            /* Swiping left — indicator on right edge */
            this.peekIndicator.className = 'gesture-peek gesture-peek--right';
        } else {
            /* Swiping right — indicator on left edge */
            this.peekIndicator.className = 'gesture-peek gesture-peek--left';
        }

        this.peekIndicator.style.opacity = opacity;
    }

    /** Hide and remove the edge peek indicator */
    _hidePeek() {
        if (this.peekIndicator) {
            this.peekIndicator.remove();
            this.peekIndicator = null;
        }
    }
}
