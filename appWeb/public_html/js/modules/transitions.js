/**
 * iHymns — Page Transitions Module (#149)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages smooth page-to-page transitions for an app-like feel.
 * Uses CSS classes on #page-content to drive opacity/transform
 * animations, with a thin loading progress bar during AJAX fetches.
 * Respects the user's reduce-motion preference.
 */

import { STORAGE_TRANSITION } from '../constants.js';

export class Transitions {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number} Transition duration in milliseconds */
        this.duration = 250;

        /** @type {string} Current transition type */
        this.type = 'none';

        /** @type {HTMLElement|null} Loading progress bar element */
        this.loadingBar = null;

        this._ensureLoadingBar();
    }

    /**
     * Load the saved transition type from localStorage.
     */
    loadType() {
        this.type = localStorage.getItem(STORAGE_TRANSITION) || 'none';
    }

    /**
     * Check if animations are currently enabled.
     * Returns false if reduce-motion is active or transition type is 'none'.
     *
     * @returns {boolean} True if animations should play
     */
    isAnimationEnabled() {
        if (document.body.classList.contains('reduce-motion')) return false;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
        if (this.type === 'none') return false;
        return true;
    }

    /**
     * Animate page content out (exit transition).
     *
     * @param {HTMLElement} el The page content container
     * @returns {Promise<void>} Resolves when transition completes
     */
    async pageOut(el) {
        this.loadType();

        if (!this.isAnimationEnabled()) {
            el.classList.remove('page-visible', 'page-entering');
            return;
        }

        /* Remove any stale classes */
        el.classList.remove('page-visible', 'page-entering');

        /* Apply leaving class to trigger exit animation */
        el.classList.add('page-leaving');

        return new Promise(resolve => {
            let resolved = false;
            const done = () => {
                if (resolved) return;
                resolved = true;
                el.removeEventListener('transitionend', done);
                el.classList.remove('page-leaving');
                resolve();
            };
            el.addEventListener('transitionend', done, { once: true });

            /* Safety timeout in case transitionend doesn't fire */
            setTimeout(done, this.duration + 50);
        });
    }

    /**
     * Animate page content in (enter transition).
     *
     * @param {HTMLElement} el The page content container
     * @returns {Promise<void>} Resolves when transition completes
     */
    async pageIn(el) {
        if (!this.isAnimationEnabled()) {
            el.classList.remove('page-leaving', 'page-entering');
            el.classList.add('page-visible');
            return;
        }

        /* Start in the entering state (invisible, offset down) */
        el.classList.remove('page-leaving');
        el.classList.add('page-entering');

        /* Force a reflow so the browser registers the entering state */
        void el.offsetHeight;

        /* Transition to visible */
        el.classList.remove('page-entering');
        el.classList.add('page-visible');

        return new Promise(resolve => {
            let resolved = false;
            const done = () => {
                if (resolved) return;
                resolved = true;
                el.removeEventListener('transitionend', done);
                resolve();
            };
            el.addEventListener('transitionend', done, { once: true });

            /* Safety timeout in case transitionend doesn't fire */
            setTimeout(done, this.duration + 50);
        });
    }

    /* ==================================================================
     * Loading Progress Bar (#149)
     * ================================================================== */

    /**
     * Ensure the loading bar element exists in the DOM.
     * @private
     */
    _ensureLoadingBar() {
        if (this.loadingBar) return;
        this.loadingBar = document.querySelector('.page-loading-bar');
        if (!this.loadingBar) {
            this.loadingBar = document.createElement('div');
            this.loadingBar.className = 'page-loading-bar';
            this.loadingBar.setAttribute('role', 'progressbar');
            this.loadingBar.setAttribute('aria-hidden', 'true');
            document.body.prepend(this.loadingBar);
        }
    }

    /**
     * Start the loading bar animation (grows to ~80%).
     * Called at the beginning of a page fetch.
     */
    startLoading() {
        this._ensureLoadingBar();
        const bar = this.loadingBar;

        /* Reset state */
        bar.classList.remove('complete', 'fade-out');
        bar.style.width = '';

        /* Force reflow so reset takes effect */
        void bar.offsetHeight;

        bar.classList.add('loading');
    }

    /**
     * Complete the loading bar animation (snap to 100%, then fade out).
     * Called after page content has been injected.
     */
    completeLoading() {
        this._ensureLoadingBar();
        const bar = this.loadingBar;

        bar.classList.remove('loading');
        bar.classList.add('complete');

        /* Fade out after the bar reaches 100% */
        setTimeout(() => {
            bar.classList.add('fade-out');
        }, 200);

        /* Clean up classes after fade-out completes */
        setTimeout(() => {
            bar.classList.remove('complete', 'fade-out', 'loading');
        }, 700);
    }
}
