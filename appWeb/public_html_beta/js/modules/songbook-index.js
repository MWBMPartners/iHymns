/**
 * iHymns — Songbook Table of Contents / Alphabetical Index (#111)
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Adds an alphabetical jump-to-letter index and a sort toggle (by number
 * or title) to songbook pages. Tapping a letter scrolls to the first song
 * starting with that letter. Letters with no matching songs are dimmed.
 */

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

        /* Insert sort toggle + alphabet strip */
        this.insertControls(page, songList, items, letterMap);
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
     * Insert the sort toggle and alphabet strip into the page.
     * @param {HTMLElement} page The songbook page section
     * @param {HTMLElement} songList The song list container
     * @param {HTMLElement[]} items Song list items
     * @param {Map<string, HTMLElement>} letterMap Letter-to-element map
     */
    insertControls(page, songList, items, letterMap) {
        const container = document.createElement('div');
        container.className = 'songbook-index-controls mb-3';

        /* Sort toggle */
        const sortGroup = document.createElement('div');
        sortGroup.className = 'btn-group btn-group-sm mb-2';
        sortGroup.setAttribute('role', 'group');
        sortGroup.setAttribute('aria-label', 'Sort songs by');
        sortGroup.innerHTML = `
            <button type="button" class="btn btn-outline-secondary active" data-sort="number">
                <i class="fa-solid fa-hashtag me-1" aria-hidden="true"></i># Number
            </button>
            <button type="button" class="btn btn-outline-secondary" data-sort="title">
                <i class="fa-solid fa-font me-1" aria-hidden="true"></i>A-Z Title
            </button>`;
        container.appendChild(sortGroup);

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

            if (letterMap.has(letter)) {
                btn.addEventListener('click', () => {
                    const target = letterMap.get(letter);
                    if (target) {
                        target.scrollIntoView({ behavior: document.body.classList.contains('reduce-motion') ? 'auto' : 'smooth', block: 'start' });
                        /* Brief highlight */
                        target.classList.add('alphabet-highlight');
                        setTimeout(() => target.classList.remove('alphabet-highlight'), 1500);
                    }
                });
            } else {
                btn.classList.add('disabled');
                btn.setAttribute('aria-disabled', 'true');
            }

            strip.appendChild(btn);
        }

        container.appendChild(strip);

        /* Insert before the song list */
        songList.before(container);

        /* Sort toggle handlers */
        let currentSort = 'number';
        sortGroup.querySelectorAll('[data-sort]').forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.dataset.sort;
                if (mode === currentSort) return;
                currentSort = mode;

                /* Update active state */
                sortGroup.querySelector('.active')?.classList.remove('active');
                btn.classList.add('active');

                /* Sort items */
                const sorted = [...items].sort((a, b) => {
                    if (mode === 'title') {
                        const titleA = a.querySelector('.song-title')?.textContent?.trim() || '';
                        const titleB = b.querySelector('.song-title')?.textContent?.trim() || '';
                        return titleA.localeCompare(titleB);
                    }
                    /* Sort by number (original DOM order, use aria-label or data-song-id) */
                    const numA = parseInt(a.querySelector('.song-number-badge')?.textContent?.trim(), 10) || 0;
                    const numB = parseInt(b.querySelector('.song-number-badge')?.textContent?.trim(), 10) || 0;
                    return numA - numB;
                });

                /* Re-append in new order */
                sorted.forEach(item => songList.appendChild(item));

                /* Rebuild letter map for new order */
                const newLetterMap = this.buildLetterMap(sorted);
                strip.querySelectorAll('.alphabet-letter').forEach(letterBtn => {
                    const letter = letterBtn.textContent;
                    /* Update click handler */
                    const newTarget = newLetterMap.get(letter);
                    const newBtn = letterBtn.cloneNode(true);
                    if (newTarget) {
                        newBtn.classList.remove('disabled');
                        newBtn.removeAttribute('aria-disabled');
                        newBtn.addEventListener('click', () => {
                            newTarget.scrollIntoView({ behavior: document.body.classList.contains('reduce-motion') ? 'auto' : 'smooth', block: 'start' });
                            newTarget.classList.add('alphabet-highlight');
                            setTimeout(() => newTarget.classList.remove('alphabet-highlight'), 1500);
                        });
                    } else {
                        newBtn.classList.add('disabled');
                        newBtn.setAttribute('aria-disabled', 'true');
                    }
                    letterBtn.replaceWith(newBtn);
                });
            });
        });
    }
}
