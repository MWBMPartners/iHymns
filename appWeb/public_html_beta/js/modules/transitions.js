/**
 * iHymns — Page Transitions Module
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Manages smooth page-to-page transitions for an app-like feel.
 * Respects the user's reduce-motion preference (disabled by default
 * as per requirements — animations off by default).
 */

export class Transitions {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number} Transition duration in milliseconds */
        this.duration = 250;
    }

    /**
     * Check if animations are currently enabled.
     * Returns false if reduce-motion is active (either OS or app setting).
     *
     * @returns {boolean} True if animations should play
     */
    isAnimationEnabled() {
        return !document.body.classList.contains('reduce-motion');
    }

    /**
     * Animate page content out (exit transition).
     *
     * @param {HTMLElement} el The page content container
     * @returns {Promise<void>} Resolves when transition completes
     */
    async pageOut(el) {
        if (!this.isAnimationEnabled()) {
            el.classList.remove('page-visible');
            return;
        }

        el.classList.remove('page-visible', 'page-entering');
        el.classList.add('page-leaving');

        return new Promise(resolve => {
            setTimeout(() => {
                el.classList.remove('page-leaving');
                resolve();
            }, this.duration);
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
            el.classList.add('page-visible');
            return;
        }

        el.classList.add('page-entering');

        /* Force a reflow so the browser sees the entering state */
        void el.offsetHeight;

        el.classList.remove('page-entering');
        el.classList.add('page-visible');

        return new Promise(resolve => {
            setTimeout(resolve, this.duration);
        });
    }
}
