/**
 * iHymns — Page Transitions Module (#106)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages smooth page-to-page transitions for an app-like feel.
 * Supports configurable transition types: none, fade, slide, crossfade.
 * Respects the user's reduce-motion preference (disabled by default
 * as per requirements — animations off by default).
 */

export class Transitions {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number} Transition duration in milliseconds (max 300ms) */
        this.duration = 250;

        /** @type {string} Current transition type */
        this.type = 'none';
    }

    /**
     * Load the saved transition type from localStorage.
     */
    loadType() {
        this.type = localStorage.getItem('ihymns_transition') || 'none';
    }

    /**
     * Check if animations are currently enabled.
     * Returns false if reduce-motion is active or transition type is 'none'.
     *
     * @returns {boolean} True if animations should play
     */
    isAnimationEnabled() {
        if (document.body.classList.contains('reduce-motion')) return false;
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
            el.classList.remove('page-visible');
            return;
        }

        /* Remove any stale classes */
        el.classList.remove('page-visible', 'page-entering');

        /* Apply type-specific leaving class */
        el.dataset.transition = this.type;
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

        /* Apply type-specific entering class */
        el.dataset.transition = this.type;
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
