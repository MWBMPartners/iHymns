/**
 * iHymns — Reading Progress Indicator Module (#109)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Displays a thin, scroll-linked progress bar at the top of song pages
 * that fills from left to right as the user scrolls through lyrics.
 * Uses the songbook accent colour. Hidden on short songs that don't scroll.
 */

export class ReadingProgress {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {HTMLElement|null} The progress bar element */
        this.bar = null;

        /** @type {function|null} Bound scroll handler for cleanup */
        this._scrollHandler = null;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Set up the progress indicator on a song page.
     * Called by router after song page loads.
     */
    initSongPage() {
        this.cleanup();

        const songPage = document.querySelector('.page-song');
        if (!songPage) return;

        /* Check if the page actually scrolls */
        requestAnimationFrame(() => {
            if (document.documentElement.scrollHeight <= window.innerHeight + 50) {
                return; /* Song is short enough to not need scrolling */
            }

            this.createBar(songPage);
            this._scrollHandler = () => this.updateProgress();
            window.addEventListener('scroll', this._scrollHandler, { passive: true });
            this.updateProgress();
        });
    }

    /**
     * Create the progress bar element.
     * @param {HTMLElement} songPage The song page article element
     */
    createBar(songPage) {
        this.bar = document.createElement('div');
        this.bar.className = 'reading-progress-bar';
        this.bar.setAttribute('role', 'progressbar');
        this.bar.setAttribute('aria-label', 'Reading progress');
        this.bar.setAttribute('aria-valuemin', '0');
        this.bar.setAttribute('aria-valuemax', '100');
        this.bar.setAttribute('aria-valuenow', '0');

        /* Apply songbook accent colour. Three sources, in priority order:
           1) data-songbook-color on the article — the runtime colour
              fetched by song.php from tblSongbooks.Colour. Works for
              every songbook, including custom ones created via
              /manage/songbooks whose abbreviation isn't in the
              hardcoded --songbook-{ABBR} CSS variable set.
           2) data-songbook abbreviation, exposed via the
              [data-songbook="…"] CSS rules in app.css for the legacy
              built-in songbooks (CP/JP/MP/SDAH/CH/Misc).
           3) The CSS default (--bs-primary). */
        const songbook       = songPage.dataset.songbook;
        const songbookColour = songPage.dataset.songbookColor;
        if (songbookColour) {
            this.bar.style.background = songbookColour;
        }
        if (songbook) {
            this.bar.dataset.songbook = songbook;
        }

        /* Insert directly under <body> rather than inside #page-content.
           #page-content has a transform on it for the page-transition
           animation (#149), and a transformed ancestor establishes a
           new containing block for position:sticky/fixed — sticking
           the bar inside the transitioning page instead of to the
           viewport. Body has no transform, so position:fixed pins
           reliably to the top of the viewport. */
        document.body.appendChild(this.bar);
    }

    /** Update the progress bar width based on scroll position */
    updateProgress() {
        if (!this.bar) return;

        const scrollTop = window.scrollY || document.documentElement.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;

        if (scrollHeight <= 0) {
            this.bar.style.width = '100%';
            this.bar.setAttribute('aria-valuenow', '100');
            return;
        }

        const progress = Math.min(100, Math.max(0, (scrollTop / scrollHeight) * 100));
        this.bar.style.width = progress + '%';
        this.bar.setAttribute('aria-valuenow', String(Math.round(progress)));
    }

    /** Remove the progress bar and unbind events */
    cleanup() {
        if (this._scrollHandler) {
            window.removeEventListener('scroll', this._scrollHandler);
            this._scrollHandler = null;
        }
        this.bar?.remove();
        this.bar = null;
    }
}
