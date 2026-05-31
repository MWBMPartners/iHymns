/**
 * iHymns — Reading Progress Indicator Module (#109, #751)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Displays a thin, scroll-linked progress bar at the top of any
 * scrollable page. Originally song-only (#109); generalised in #751
 * to fire on every page the router loads. The bar fills from left to
 * right as the user scrolls, hidden on short pages that don't need
 * scrolling. Songbook colour applies on song / songbook pages where
 * the relevant `data-songbook-color` is present; everywhere else the
 * bar uses the framework accent colour.
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
     * Generic entry point: attach the progress bar to whatever page is
     * currently rendered, regardless of which router page emitted it.
     * Called by router.afterPageLoad() on every navigation. Safe to
     * call when no scrollable content is present — short pages are
     * detected via the documentElement.scrollHeight heuristic and the
     * bar simply isn't created.
     *
     * Colour-source priority (set by createBar()):
     *   1. data-songbook-color attribute on a `.page-song` /
     *      `.page-songbook` ancestor — when the page has a clear
     *      songbook context, we inherit its accent.
     *   2. Legacy [data-songbook="…"] CSS rules for the original
     *      hardcoded songbooks (CP/JP/MP/SDAH/CH/Misc).
     *   3. CSS default (--bs-primary) — every other page.
     */
    initOnAnyPage() {
        this.cleanup();

        /* Wait one frame so the freshly-injected page HTML has had
           layout. scrollHeight is what we check; before layout it's
           often equal to clientHeight and we'd false-negative. */
        requestAnimationFrame(() => {
            if (document.documentElement.scrollHeight <= window.innerHeight + 50) {
                return; /* Page doesn't scroll meaningfully — no bar needed. */
            }

            /* Prefer a song / songbook context for colour inheritance,
               but the bar attaches even when neither is present. */
            const ctx = document.querySelector('.page-song, .page-songbook');
            this.createBar(ctx);

            this._scrollHandler = () => this.updateProgress();
            window.addEventListener('scroll', this._scrollHandler, { passive: true });
            this.updateProgress();
        });
    }

    /**
     * Backwards-compatible alias. The router still calls this on
     * song-page navigation; new call sites should prefer
     * initOnAnyPage(). (#751)
     */
    initSongPage() {
        this.initOnAnyPage();
    }

    /**
     * Create the progress bar element.
     * @param {HTMLElement|null} ctx Optional song/songbook context
     *   element used to inherit the accent colour. When null (e.g. on
     *   /home, /favorites, or any /manage/* page), the bar uses the
     *   default --bs-primary CSS rule.
     */
    createBar(ctx) {
        this.bar = document.createElement('div');
        this.bar.className = 'reading-progress-bar';
        this.bar.setAttribute('role', 'progressbar');
        this.bar.setAttribute('aria-label', 'Reading progress');
        this.bar.setAttribute('aria-valuemin', '0');
        this.bar.setAttribute('aria-valuemax', '100');
        this.bar.setAttribute('aria-valuenow', '0');

        /* Apply songbook accent colour when a context is present. Three
           sources, in priority order:
           1) data-songbook-color on the page-song / page-songbook
              article — the runtime colour fetched by song.php /
              songbook page from tblSongbooks.Colour. Works for every
              songbook, including custom ones created via
              /manage/songbooks whose abbreviation isn't in the
              hardcoded --songbook-{ABBR} CSS variable set.
           2) data-songbook abbreviation, exposed via the
              [data-songbook="…"] CSS rules in app.css for the legacy
              built-in songbooks (CP/JP/MP/SDAH/CH/Misc).
           3) The CSS default (--bs-primary) — applies on every other
              page (#751: home, songbooks list, favourites, /manage/*,
              etc.). */
        if (ctx) {
            const songbook       = ctx.dataset.songbook;
            const songbookColour = ctx.dataset.songbookColor;
            if (songbookColour) {
                this.bar.style.background = songbookColour;
            }
            if (songbook) {
                this.bar.dataset.songbook = songbook;
            }
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
