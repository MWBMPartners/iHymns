/**
 * iHymns — Songbook Table of Contents / Alphabetical Index (#111)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds an alphabetical jump-to-letter index to songbook pages. Tapping a
 * letter scrolls to the first song starting with that letter. Letters with
 * no matching songs are dimmed.
 *
 * #1786 ABSORB — the sort toggle this module used to own
 * ---------------------------------------------------------
 * Before #1786, this module ALSO drew its own "# Number / A-Z Title" sort
 * toggle and did the actual re-ordering itself — a second, competing sort
 * authority on the same page, with no article fold, no direction, no
 * persistence and no announcement (the modularity rule exists to stop
 * exactly this). Sorting on `/songbook/<abbr>` now belongs entirely to
 * `js/modules/list-sort.js`'s "Sort ▾" control (surface `songbook-songs`,
 * wired from `includes/pages/songbook.php`'s markup) — the SAME absorption
 * shape as `/manage/duplicate-songs` swallowing `/manage/song-link-
 * suggestions` (rule #22).
 *
 * This module keeps ONLY the alphabet strip, and stops being able to
 * re-order anything itself: when list-sort.js re-orders the song list out
 * from under it, this module has no idea unless told, so its letter map
 * (built once from the SERVER-rendered order) would silently point at the
 * wrong rows after a sort. It listens for `EVT_LIST_SORT_CHANGED`
 * (`js/constants.js`, dispatched by list-sort.js on `document` every time
 * `songbook-songs` changes — including the very first, async apply of an
 * already-saved preference, which can reorder the page before this
 * module's own synchronous initial build would otherwise know) and rebuilds
 * the letter map from whatever order the DOM is in at that moment.
 *
 * The listener is registered FRESH each time `initSongbookPage()` runs (once
 * per `/songbook/<abbr>` visit) and closes over THAT page's own `strip` /
 * `songList` elements. It never needs `removeEventListener`: once the SPA
 * router swaps the page away, `document.contains(strip)` goes false and the
 * listener is a one-line no-op forever after — cheap enough that letting old
 * listeners linger costs nothing worth a teardown mechanism (contrast rule
 * #32, which is about a *visible* fixed element stranding on screen; a dead
 * document-level listener has no such user-facing symptom).
 */

import { EVT_LIST_SORT_CHANGED } from '../constants.js';

export class SongbookIndex {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Set up the alphabetical index on a songbook page.
     * Called by router after songbook page loads.
     */
    initSongbookPage() {
        const page = document.querySelector('.page-songbook');
        if (!page) return;

        const songList = page.querySelector('.song-list');
        if (!songList) return;

        const items = Array.from(songList.querySelectorAll('.song-list-item'));
        if (items.length < 20) return; /* Don't show for very small songbooks */

        /* Build letter map */
        const letterMap = this.buildLetterMap(items);

        /* Insert alphabet strip */
        this.insertControls(page, songList, letterMap);
    }

    /**
     * Build a map of first letters to their first song element.
     * @param {HTMLElement[]} items Song list items
     * @returns {Map<string, HTMLElement>}
     */
    buildLetterMap(items) {
        const map = new Map();
        items.forEach(item => {
            const title = item.querySelector('.song-title')?.textContent?.trim() || '';
            const letter = title.charAt(0).toUpperCase();
            if (letter && /[A-Z]/.test(letter) && !map.has(letter)) {
                map.set(letter, item);
            }
        });
        return map;
    }

    /**
     * Insert the alphabet strip into the page and wire it to the CURRENT
     * letter map. Also registers this page's #1786 re-sort listener — see
     * the module doc-block for why it needs no teardown.
     *
     * @param {HTMLElement} page The songbook page section
     * @param {HTMLElement} songList The song list container
     * @param {Map<string, HTMLElement>} letterMap Letter-to-element map
     */
    insertControls(page, songList, letterMap) {
        const container = document.createElement('div');
        container.className = 'songbook-index-controls mb-3';

        /* Alphabet strip */
        const strip = document.createElement('div');
        strip.className = 'alphabet-strip';
        strip.setAttribute('role', 'navigation');
        strip.setAttribute('aria-label', 'Alphabetical index');

        for (let i = 65; i <= 90; i++) {
            const letter = String.fromCharCode(i);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'alphabet-letter';
            btn.textContent = letter;
            btn.setAttribute('aria-label', `Jump to letter ${letter}`);
            strip.appendChild(btn);
        }

        container.appendChild(strip);

        /* Insert before the song list */
        songList.before(container);

        /* Initial wiring — same code path a re-sort rebuild uses below. */
        this._rebuildStrip(strip, letterMap);

        /* #1786 absorb — rebuild whenever list-sort.js reorders THIS
           surface. See the module doc-block for the self-evicting shape. */
        document.addEventListener(EVT_LIST_SORT_CHANGED, (ev) => {
            if (!document.contains(strip)) return; /* this page has been navigated away from */
            if (ev?.detail?.surface !== 'songbook-songs') return;
            const freshItems = Array.from(songList.querySelectorAll('.song-list-item'));
            this._rebuildStrip(strip, this.buildLetterMap(freshItems));
        });
    }

    /**
     * (Re)wire every letter button in `strip` against `letterMap` — enabled
     * + a fresh click handler when a target exists, disabled otherwise.
     * Cloning + replacing each button (rather than tracking/removing the
     * previous listener by hand) is the simplest way to guarantee exactly
     * ONE click handler survives per button across repeated rebuilds.
     *
     * @param {HTMLElement} strip
     * @param {Map<string, HTMLElement>} letterMap
     */
    _rebuildStrip(strip, letterMap) {
        strip.querySelectorAll('.alphabet-letter').forEach(letterBtn => {
            const letter = letterBtn.textContent;
            const target = letterMap.get(letter);
            const freshBtn = letterBtn.cloneNode(true);
            if (target) {
                freshBtn.classList.remove('disabled');
                freshBtn.removeAttribute('aria-disabled');
                freshBtn.addEventListener('click', () => {
                    target.scrollIntoView({ behavior: document.body.classList.contains('reduce-motion') ? 'auto' : 'smooth', block: 'start' });
                    /* Brief highlight */
                    target.classList.add('alphabet-highlight');
                    setTimeout(() => target.classList.remove('alphabet-highlight'), 1500);
                });
            } else {
                freshBtn.classList.add('disabled');
                freshBtn.setAttribute('aria-disabled', 'true');
            }
            letterBtn.replaceWith(freshBtn);
        });
    }
}
