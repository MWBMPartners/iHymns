/**
 * iHymns — SPA Router Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages client-side routing using the History API (pushState).
 * Intercepts navigation clicks, loads page content via AJAX from
 * the PHP API, and manages page transitions.
 *
 * Clean URLs: All navigation uses paths like /song/CP-0001 instead
 * of hash-based routes. The server (.htaccess) rewrites all paths
 * to index.php, and this router handles the rest client-side.
 */

import { toTitleCase } from '../utils/text.js';
import { announce } from '../utils/announce.js';
import { escapeHtml } from '../utils/html.js';
import { userHasEntitlement } from './entitlements.js';
/* #1031 — shared client: attaches X-Preferred-Languages + X-Requested-With
   on every same-origin request, replacing the old global fetch monkey-patch.
   Page loads (loadPage) and the song/related/translation fetches below are
   exactly the requests that need the language filter applied. */
import { apiFetch } from '../utils/api-client.js';
import { shouldRenderErrorBody } from '../utils/error-response.js';
import { prefersReducedMotion } from '../utils/motion.js';
import {
    STORAGE_FAVORITES,
    STORAGE_SETLISTS,
    STORAGE_HISTORY,
    STORAGE_SEARCH_HISTORY,
    STORAGE_RECENT_SONGBOOKS,
    EVT_AUTH_CHANGED,
} from '../constants.js';

/**
 * a11y audit M1 / G5 (owner decision D4, 2026-08-30) — the "one specific
 * record" pages whose document title is re-derived from the rendered
 * fragment's own <h1> once it lands (applyDynamicRecordTitle() below),
 * rather than staying on updateTitle()'s generic per-page-TYPE title
 * forever. tests/test-router-title-coverage.js (G5) asserts every page
 * fragment under includes/pages/*.php is covered by EITHER a
 * updateTitle() map entry OR membership here — so a future page that is
 * neither (like publisher/tune/work were before this fix) fails the
 * build instead of shipping with a permanently generic tab title.
 * @type {Set<string>}
 */
const DYNAMIC_TITLE_PAGES = new Set([
    'song', 'songbook', 'tag', 'musician', 'publisher', 'tune', 'work', 'setlist-shared',
]);

export class Router {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;
        this.config = app.config;

        /** @type {string} The API base URL for AJAX requests */
        this.apiUrl = this.config.apiUrl || '/api';

        /** @type {string|null} Currently active route path */
        this.currentPath = null;

        /** @type {AbortController|null} For cancelling in-flight AJAX requests */
        this.abortController = null;

        /** @type {number} Monotonic counter stored in history.state so
         *  we can detect navigation direction on popstate. Each forward
         *  navigation pushes counter+1; back navigation lands on a
         *  smaller counter, forward (re-)navigation on a larger one.
         *  (#752) */
        this._navCounter = 0;

        /** @type {Map<string, number>} Per-path scroll positions.
         *  Saved on navigation, restored on popstate when the user
         *  goes back to a previously-seen page. (#752) */
        this._scrollByPath = new Map();
    }

    /**
     * Initialise the router — listen for popstate (back/forward) events.
     */
    init() {
        /* Handle magic link login before any routing (#magic-link) */
        this._handleMagicLink();

        /* Seed the initial history entry with a navigation counter so
           direction detection works from the first popstate. If we
           don't seed, the very first back-navigation lands on a state
           with no counter and the direction lookup falls through to
           the "forward" default. (#752) */
        if (!window.history.state || typeof window.history.state.counter !== 'number') {
            window.history.replaceState(
                { ...(window.history.state || {}), path: window.location.pathname, counter: this._navCounter },
                '',
                window.location.pathname + window.location.search
            );
        } else {
            this._navCounter = window.history.state.counter;
        }

        /* Handle browser back/forward navigation. The popstate event's
           target state carries the counter we set when navigate()
           pushed it; comparing against our local counter tells us
           which direction the user moved.

           We save the OUTGOING path's scroll position before
           handleCurrentRoute updates this.currentPath so a
           back-then-forward cycle can restore the user to where they
           were on each side. */
        window.addEventListener('popstate', (e) => {
            const newCounter = (e.state && typeof e.state.counter === 'number')
                ? e.state.counter
                : 0;
            const direction = newCounter < this._navCounter ? 'back' : 'forward';
            this._navCounter = newCounter;
            document.body.dataset.navDirection = direction;
            if (this.currentPath) {
                this._scrollByPath.set(this.currentPath, window.scrollY || 0);
            }
            this.handleCurrentRoute({ isPopstate: true });
        });
    }

    /**
     * Re-load the current route's content. Used by the pull-to-refresh
     * gesture (#822) — same as if the user navigated away and back,
     * but without changing the URL. Re-fetches the page HTML and
     * re-runs the after-load hooks so any cached page state (favourites
     * counts, song-of-the-day, etc.) refreshes.
     */
    async refresh() {
        await this.handleCurrentRoute({ isPopstate: false, isRefresh: true });
    }

    /**
     * Navigate to a new URL path.
     * Pushes the new state to the browser history and loads the page.
     *
     * @param {string} path URL path to navigate to (e.g., '/song/CP-0001')
     */
    async navigate(path, opts = {}) {
        /* Normalise path */
        path = path || '/';
        if (path !== '/' && path.endsWith('/')) {
            path = path.slice(0, -1);
        }

        /* Don't reload if already on this path */
        if (path === this.currentPath) return;

        /* Save current scroll position before leaving this page so
           popstate-back can restore it. (#752) */
        if (this.currentPath) {
            this._scrollByPath.set(this.currentPath, window.scrollY || 0);
        }

        /* Push (or REPLACE, for a permalink redirect — #1343 — so the dead
           /song/<old> URL doesn't linger in history and cause a back-button loop)
           new state with an incremented counter so popstate can detect direction. */
        this._navCounter += 1;
        const _state = { path, counter: this._navCounter };
        if (opts.replace) {
            window.history.replaceState(_state, '', path);
        } else {
            window.history.pushState(_state, '', path);
        }
        document.body.dataset.navDirection = 'forward';

        /* Load the page content */
        await this.handleCurrentRoute({ isPopstate: false });
    }

    /**
     * Handle the current URL route — determine which page to load
     * and fetch its content from the API.
     */
    async handleCurrentRoute(opts = {}) {
        const path = window.location.pathname || '/';
        this.currentPath = path;

        /* Parse the route into an API request */
        const { page, params } = this.parseRoute(path);

        /* For song pages, replace the URL with the canonical zero-padded form
         * so that /song/MP-1 silently becomes /song/MP-0001 in the address bar.
         * This ensures consistent URLs for bookmarking, sharing, and SEO. */
        if (page === 'song' && params.id) {
            const canonicalPath = `/song/${params.id}`;
            if (canonicalPath !== path) {
                window.history.replaceState({ path: canonicalPath }, '', canonicalPath);
                this.currentPath = canonicalPath;
            }
        }

        /* Login route: show auth modal instead of loading a page from API */
        if (page === 'login') {
            const token = new URLSearchParams(window.location.search).get('token');
            if (token) {
                /* Magic link with token — handled by _handleMagicLink() on init.
                 * If we reach here via navigate(), handle it now. */
                this._verifyMagicLinkToken(token);
            } else {
                /* No token — show the auth modal and go home */
                this.app.userAuth?.showAuthModal('login');
                window.history.replaceState({ path: '/' }, '', '/');
                this.currentPath = '/';
                await this.handleCurrentRoute();
            }
            return;
        }

        /* Update the active footer nav item */
        this.updateActiveNav(page);

        /* Update the document title */
        this.updateTitle(page, params);

        /* Track page view in analytics */
        this.app.trackPageView(path, document.title);

        /* Build the API URL for fetching the page content */
        const apiUrl = this.buildApiUrl(page, params);

        /* Load the page via AJAX with transitions */
        await this.loadPage(apiUrl);

        /* Clean up previous page state (#95) */
        this.app.display.cleanup();
        this.app.readingProgress.cleanup();

        /* Run post-load hooks (e.g., initialise favourites on song pages) */
        this.afterPageLoad(page, params);

        /* a11y (WCAG 2.4.3): move focus to the main region after a client-side
           navigation so keyboard + screen-reader users land on the new content
           rather than the stale nav link they activated. preventScroll so this
           doesn't fight the scroll-restore below; #main-content has tabindex="-1". */
        document.getElementById('main-content')?.focus({ preventScroll: true });

        /* a11y (WCAG 4.1.3): say WHAT the user has landed on (#1645).
         *
         * ELI5: tell the screen reader the name of the page we just moved to —
         * one short line, not the whole page.
         *
         * Detail: <main> used to be a live region, so every navigation read the
         * entire injected fragment aloud, including all the lyrics, while
         * competing with the focus announcement above. Removing that left a
         * real gap — an SPA route change is invisible to assistive tech — and
         * this fills it with the announcement that was actually wanted.
         *
         * Ordering is deliberate: focus FIRST, then announce. The focus move
         * triggers its own announcement of the newly-focused region, and a
         * polite live region queues behind whatever is already being spoken
         * rather than interrupting it — so the user hears the landing, then the
         * page name. Announcing first would put the two in the opposite,
         * less useful order. No extra deferral is needed: announce() already
         * defers its own write by a frame (that empty-then-fill is what makes
         * the mutation observable at all), which is comfortably after focus. */
        const heading = document.querySelector('#page-content h1');
        announce((heading?.textContent || '').trim() || toTitleCase(String(page || 'page')));

        /* a11y audit M1 (WCAG 2.4.2, owner decision D4): re-title the tab
           with the actual record's name now that its fragment (and heading)
           have landed — see applyDynamicRecordTitle()'s own doc-comment.
           Reuses the SAME heading element the announcement above just read,
           rather than querying the DOM a second time. */
        this.applyDynamicRecordTitle(page, heading);

        /* Scroll handling. On popstate-back / forward to a previously-
           seen path, restore the saved scroll position with a smooth
           scroll. On forward navigation (or when no saved position
           exists), jump to the top instantly. (#752) */
        const saved = opts.isPopstate ? this._scrollByPath.get(path) : undefined;
        document.getElementById('main-content')?.scrollTo(0, 0);
        if (typeof saved === 'number' && saved > 0) {
            /* Two rAFs so the page-entering transform has a chance to
               settle before we scroll — otherwise the browser scrolls
               into a transformed coordinate system. */
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    window.scrollTo({
                        top: saved,
                        left: 0,
                        /* a11y audit L7 (2026-08-30): was reduce-motion-class-only —
                           now also honours the OS-level prefers-reduced-motion
                           media query. @see js/utils/motion.js */
                        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
                    });
                });
            });
        } else {
            window.scrollTo(0, 0);
        }
    }

    /**
     * Parse a URL path into a page name and parameters.
     *
     * @param {string} path URL path (e.g., '/song/CP-0001')
     * @returns {{ page: string, params: object }}
     */
    parseRoute(path) {
        /* Remove leading slash and split into segments */
        const segments = path.replace(/^\//, '').split('/').filter(Boolean);

        if (segments.length === 0) {
            return { page: 'home', params: {} };
        }

        switch (segments[0]) {
            case 'songbook':
                return { page: 'songbook', params: { id: segments[1] || '' } };
            case 'songbooks':
                return { page: 'songbooks', params: {} };
            case 'song':
                return { page: 'song', params: { id: this.normalizeSongId(segments[1] || '') } };
            case 'search':
                return { page: 'search', params: {} };
            case 'favorites':
            case 'favourites':
                return { page: 'favorites', params: {} };
            case 'setlist':
            case 'setlists':
                if (segments[1] === 'shared' && segments[2]) {
                    return { page: 'setlist-shared', params: { data: segments[2] } };
                }
                return { page: 'setlist', params: {} };
            case 'settings':
                return { page: 'settings', params: {} };
            case 'link': {
                /* RFC 8628 device-code pairing "Link a device" page (#1407).
                   Forwards an optional ?user_code= from the verification_uri_
                   complete deep link (RFC 8628 §3.3, e.g. a QR code/on-screen
                   URL a TV shows) through to the server-rendered prefill. */
                const sp = new URLSearchParams(window.location.search || '');
                const userCode = (sp.get('user_code') || '').trim();
                return { page: 'link', params: userCode ? { user_code: userCode.slice(0, 20) } : {} };
            }
            case 'stats':
            case 'statistics':
                return { page: 'stats', params: {} };
            case 'writer':
                return { page: 'writer', params: { id: segments[1] || '' } };
            case 'musician':
            case 'people':
            case 'person':
                /* Musician public page (#588, renamed from Credit Person
                   by #1741 P2-B). /musician/<slug> is canonical;
                   /musician/<slug> and /person/<slug> resolve to the same
                   page as forgiving back-compat aliases — years of
                   external links + the shipped Apple client's
                   CanonicalURL.person(slug:) still emit the old
                   spellings (Wikipedia-style /wiki/Foo + linked-data
                   habits both work too). */
                return { page: 'musician', params: { slug: segments[1] || '' } };
            case 'work':
            case 'works':
                /* Work public page (#840). /work/<slug> is canonical;
                   /works/<slug> accepted as a forgiving alias matching
                   the people / person convention. */
                return { page: 'work', params: { slug: segments[1] || '' } };
            case 'tag':
                /* #1637 — /tag/<slug> lists every song carrying the named
                   theme (js/modules/home-page.js's "Browse by theme" chips,
                   renderThemeChip(), already emit this href/data-navigate
                   pair — this route was the missing piece). Empty / unknown
                   slug renders the page's own "no songs for this theme"
                   state (includes/pages/tag.php), same as work/tune/iswc. */
                return { page: 'tag', params: { slug: segments[1] || '' } };
            case 'themes':
            case 'tags':
                /* #1148 — the searchable /themes A–Z index (the follow-on to
                   the home Top-8 strip). `/tags` is a forgiving alias (the
                   people/person, work/works convention); api.php folds it to
                   `themes` BEFORE the cache key so the two never double-cache
                   identical content. */
                return { page: 'themes', params: {} };
            case 'tune':
                /* #940 — /tune/<slug> lists every song that uses the
                   named tune. Slugified upstream (lowercase + hyphen-
                   separated). Empty / unknown slug renders the page's
                   own friendly empty state. */
                return { page: 'tune', params: { slug: segments[1] || '' } };
            case 'publisher':
                /* #93 — /publisher/<slug>: who a publisher is + the
                   songbooks they published. Empty / unknown slug renders
                   the page's own friendly empty state. */
                return { page: 'publisher', params: { slug: segments[1] || '' } };
            case 'iswc':
                /* #940 — /iswc/<code> lists every song that shares the
                   ISWC. The code is the standard T-NNN.NNN.NNN-N format
                   url-encoded; the page handler decodes and strips
                   non-T/digit characters defensively. */
                return { page: 'iswc', params: { code: segments[1] || '' } };
            case 'ipi':
            case 'isni':
            case 'ccli':
            case 'bowi':
            case 'isrc':
                /* #1741 P3 — the five siblings of /iswc/ above, sharing the
                   same unified resolver + page (includes/pages/identifier.php,
                   api.php's identifier.php case group). Same shape as
                   'iswc': the raw URL segment is forwarded as `code` and the
                   page handler canonicalises + resolves it per scheme
                   (includes/identifier_normalize.php's IHYMNS_ID_SCHEMES). */
                return { page: segments[0], params: { code: segments[1] || '' } };
            case 'help':
                return { page: 'help', params: {} };
            case 'whats-new':
                /* #1583 — a deploy-time CHANGELOG.md excerpt, modelled
                   exactly on 'help' immediately above (no params, no
                   auth). See includes/pages/whats-new.php. */
                return { page: 'whats-new', params: {} };
            case 'terms':
                return { page: 'terms', params: {} };
            case 'privacy':
                return { page: 'privacy', params: {} };
            case 'request':
            case 'request-a-song': {
                /* /request is the canonical URL (#658). /request-a-song
                   stays as a back-compat alias so older bookmarks /
                   shared links / offline-queue submissions still resolve.
                   Forward `?songbook=` and `?number=` through to the API
                   so the partial can bake them into the form server-side
                   (#666) — relying on a client-side prefill alone proved
                   racy across SW caches + module-import timing. */
                const sp = new URLSearchParams(window.location.search || '');
                const params = {};
                const sb = (sp.get('songbook') || '').trim();
                const num = (sp.get('number')   || '').trim();
                if (sb)  params.songbook = sb.slice(0, 100);
                if (num) params.number   = num.slice(0, 500);
                return { page: 'request', params };
            }
            case 'login':
                return { page: 'login', params: {} };
            default:
                return { page: 'not-found', params: {} };
        }
    }

    /**
     * Normalise a song ID to its canonical zero-padded format.
     *
     * Accepts flexible formats like 'MP-1', 'MP-01', 'MP-001' and
     * normalises them to the canonical 4-digit padded form 'MP-0001'.
     * This ensures consistent URLs for SEO and caching.
     *
     * @param {string} id Song ID in any format (e.g., 'MP-1', 'mp-01')
     * @returns {string} Canonical ID (e.g., 'MP-0001') or original if not parseable
     */
    normalizeSongId(id) {
        if (!id) return id;

        /* Match pattern: letters, hyphen, digits */
        const match = id.match(/^([A-Za-z]+)-0*(\d+)$/);
        if (!match) return id;

        const prefix = match[1].toUpperCase();
        const number = match[2];

        /* Pad the number to 4 digits (the canonical format) */
        const padded = number.padStart(4, '0');
        return `${prefix}-${padded}`;
    }

    /**
     * Build the AJAX API URL for fetching page content.
     *
     * @param {string} page Page name
     * @param {object} params Route parameters
     * @returns {string} Full API URL
     */
    buildApiUrl(page, params) {
        const url = new URL(this.apiUrl, window.location.origin);
        url.searchParams.set('page', page);

        /* Add route-specific parameters */
        if (params.id) {
            url.searchParams.set('id', params.id);
        }
        /* Person page (#588) carries `slug`, not `id`. */
        if (params.slug) {
            url.searchParams.set('slug', params.slug);
        }
        /* #940 — ISWC pages key on the `code` parameter (the actual
           ISWC value, not a slug — the format T-NNN.NNN.NNN-N is
           already canonical). */
        if (params.code) {
            url.searchParams.set('code', params.code);
        }
        /* Request-a-song deep-link prefill (#666) — forwarded straight
           through to the partial so it can echo them into the input
           value attributes server-side. */
        if (params.songbook) {
            url.searchParams.set('songbook', params.songbook);
        }
        if (params.number) {
            url.searchParams.set('number', params.number);
        }
        /* Device-code pairing deep-link prefill (#1407) — forwarded straight
           through so link.php can echo it (normalised) into the code input's
           value attribute server-side, same pattern as songbook/number above. */
        if (params.user_code) {
            url.searchParams.set('user_code', params.user_code);
        }

        return url.toString();
    }

    /**
     * Load a page via AJAX and inject it into the content area.
     *
     * @param {string} url API URL to fetch
     */
    async loadPage(url) {
        const content = document.getElementById('page-content');
        if (!content) return;

        /* Cancel any in-flight request */
        if (this.abortController) {
            this.abortController.abort();
        }
        this.abortController = new AbortController();

        try {
            /* #864 — fetch FIRST, animate the swap second, so old and
               new content animate simultaneously via the View
               Transitions API. The previous implementation was
               sequential (out → fetch → in) which produced a "blank
               gap" between the old and new pages that looked staged
               rather than fluid. Loading bar still kicks off here so
               the user has a visible affordance during the fetch
               itself (especially on slow networks). */
            this.app.transitions.startLoading();

            /* Fetch the page content from the API */
            const response = await apiFetch(url, {
                signal: this.abortController.signal,
                headers: {
                    'Accept': 'text/html',
                }
            });

            /* #1705 — AN ERRORED RESPONSE MAY STILL CARRY A REAL EXPLANATION.
             *
             * ELI5: if the server said "this song was removed", show that —
             * don't replace it with "check your connection".
             *
             * This used to be `if (!response.ok) throw`, with the body never
             * read, so the catch block below rendered a generic connection
             * warning. Six surfaces send a themed, accessible card with a
             * helpful message and action buttons — song.php (410 for a removed
             * song, 404 otherwise), songbook.php, musician.php, work.php, tag.php
             * and maintenance.php's 503 — and NOT ONE had ever been seen by a
             * user. They render perfectly in curl.
             *
             * The old fallback was not just generic, it was WRONG: it blamed the
             * reader's network for a server that had answered clearly. Somebody
             * following an old link to a merged song was told to check their
             * wifi.
             *
             * The decision is a PURE function (status + content-type + body
             * length) so a test can call it — never a prose match on the body,
             * which is the rule #35 anti-pattern. An errored JSON response is
             * deliberately NOT injected: showing a reader `{"error":"…"}` would
             * be worse than the alert it replaced. */
            if (!response.ok) {
                const errorBody = await response.text();
                if (!shouldRenderErrorBody(
                    response.status,
                    response.headers.get('Content-Type'),
                    errorBody.length
                )) {
                    /* Nothing usable came back — fall through to the generic
                       alert, which for a genuine network or empty-body failure
                       is the honest answer. */
                    throw new Error(`HTTP ${response.status}`);
                }
                this.app.transitions.completeLoading();
                content.innerHTML = errorBody;
                this.app.transitions.pageIn(content);
                /* Deliberately no afterPageLoad(): an error card has no page
                   module to hydrate, and running one against a fragment whose
                   expected data-* attributes are absent is how a cleanup routine
                   throws on a page that is already failing. */
                return;
            }

            const html = await response.text();

            /* Hand the DOM swap to the transition runner. On modern
               browsers (View Transitions API), the browser snapshots
               the current page, runs the swap synchronously, and
               animates the cross-fade in CSS — all in one frame, no
               class-toggle dance. On older browsers it falls back
               to the legacy pageOut → swap → pageIn flow. */
            await this.app.transitions.runViewTransition(() => {
                content.innerHTML = html;
                /* NO script re-execution here, deliberately (#1619).
                 *
                 * ELI5: page fragments are not allowed to carry code, so
                 * there is nothing here to run.
                 *
                 * This used to call `_executeInlineScripts()`, which re-created
                 * each injected <script> so the browser would run it (innerHTML
                 * parses script tags but never executes them). That helper was
                 * removed because it could not do its job and was actively
                 * harmful:
                 *
                 *   - It copied attributes VERBATIM, so the re-created node had
                 *     no CSP nonce. index.php sends an ENFORCING nonce CSP
                 *     (#117), and fragments are separate, often shared-cache
                 *     HTTP responses that can never carry a per-request nonce
                 *     (rule #6). The browser refused every such script with a
                 *     console-only violation — no exception, no toast. That
                 *     silent half-execution killed the entire public Export
                 *     feature for ~7 weeks (#1565).
                 *   - It had nothing left to run: the only <script> in any
                 *     fragment is an inert `application/ld+json` block in
                 *     musician.php, which needs parsing, not executing.
                 *   - tests/php/test-fragment-inline-scripts.php now fails the
                 *     build on any executable inline script in a fragment, and
                 *     its allowlist is empty (#1572).
                 *
                 * Fragment behaviour is wired from afterPageLoad() as real ES
                 * modules instead — see the home-page.js pattern (rule #30).
                 * Do not reinstate this by injecting a nonce into fragments or
                 * relaxing the CSP; both trade a working CSP for convenience. */
            }, content);

            /* Complete loading bar after the transition is done. */
            this.app.transitions.completeLoading();

        } catch (error) {
            if (error.name === 'AbortError') {
                /* Request was cancelled — another navigation started */
                return;
            }

            console.error('[Router] Failed to load page:', error);
            this.app.transitions.completeLoading();
            content.innerHTML = `
                <div class="alert alert-danger mt-4" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                    Failed to load page. Please check your connection and try again.
                </div>`;
            this.app.transitions.pageIn(content);
        }
    }

    /**
     * Update the active state on footer navigation items.
     *
     * @param {string} page Current page name
     */
    updateActiveNav(page) {
        document.querySelectorAll('.footer-nav-item').forEach(item => {
            const navPage = item.dataset.navigate;
            const isActive = navPage === page || (navPage === 'home' && page === 'home');
            item.classList.toggle('active', isActive);
            item.setAttribute('aria-current', isActive ? 'page' : 'false');
        });
    }

    /**
     * Update the document title based on the current page.
     *
     * a11y audit M1 (WCAG 2.4.2 Page Titled, 2026-08-30): this map is the
     * FIRST title a page gets — called before the fragment has even been
     * fetched (handleCurrentRoute() calls it right after parseRoute(), well
     * before loadPage()) — so every entry here is a reasonable GENERIC
     * title for its page shape. For the "one specific record" pages
     * (song/songbook/tag/musician/publisher/tune/work/setlist-shared),
     * `applyDynamicRecordTitle()` below OVERWRITES this generic title with
     * the actual record's name once its fragment has landed — matching the
     * `"<record name> — iHymns"` shape index.php already computes
     * server-side pre-boot ($ogTitle) for the very first page load, so a
     * fresh visit and a client-side navigation end up with the SAME title
     * shape either way (owner decision D4).
     *
     * Before this fix `publisher`/`tune`/`work` had NO entry at all here
     * (falling through to the bare app name) and EVERY song/songbook/tag/
     * musician page kept this generic placeholder forever — a screen-reader
     * user tabbing through browser history heard "Song — iHymns" for every
     * one of ~14,000 different songs.
     * @link https://www.w3.org/WAI/WCAG21/Understanding/page-titled.html
     *
     * @param {string} page Current page name
     * @param {object} params Route parameters
     */
    updateTitle(page, params) {
        const appName = this.config.appName || 'iHymns';
        const titles = {
            'home': appName + ' — Christian Hymns & Worship Songs',
            'songbooks': 'Songbooks — ' + appName,
            'songbook': 'Songbook — ' + appName,
            'song': 'Song — ' + appName,
            'search': 'Search — ' + appName,
            'favorites': 'Favourites — ' + appName,
            'setlist': 'Set Lists — ' + appName,
            'setlist-shared': 'Shared Set List — ' + appName,
            'settings': 'Settings — ' + appName,
            'link': 'Link a Device — ' + appName,
            'stats': 'Usage Statistics — ' + appName,
            'writer': 'Writer — ' + appName,
            'musician': 'Musician — ' + appName,
            /* #1148 — the theme surfaces. `tag` had NO entry before this (its
               per-theme pages titled as the bare app name since #1637); added
               here alongside the new `themes` index it belongs to. */
            'themes': 'Themes — ' + appName,
            'tag': 'Theme — ' + appName,
            /* #1741 P3 — the six external-identifier alias pages (iswc had
               no title entry before this either; added here for
               consistency with its five new siblings). */
            'iswc': 'ISWC — ' + appName,
            'ipi': 'IPI — ' + appName,
            'isni': 'ISNI — ' + appName,
            'ccli': 'CCLI — ' + appName,
            'bowi': 'BOWI — ' + appName,
            'isrc': 'ISRC — ' + appName,
            'help': 'Help — ' + appName,
            'whats-new': "What's New — " + appName,
            'terms': 'Terms of Use — ' + appName,
            'privacy': 'Privacy Policy — ' + appName,
            'request': 'Request a Song — ' + appName,
            /* a11y audit M1 (2026-08-30) — the three missing entries: */
            'publisher': 'Publisher — ' + appName,
            'tune': 'Tune — ' + appName,
            'work': 'Work — ' + appName,
            'not-found': 'Page Not Found — ' + appName,
        };
        document.title = titles[page] || appName;
    }

    /**
     * a11y audit M1 (WCAG 2.4.2, owner decision D4, 2026-08-30) — the
     * "record name" pages, where the generic updateTitle() title above is
     * never good enough on its own: read the fragment's own <h1> (the SAME
     * element handleCurrentRoute() already reads for its SPA-navigation
     * announcement, just below where this is called from — DOM-first, per
     * the rule-#30 pattern) and re-title the tab as
     * `"<record name> — iHymns"`, matching what index.php computes
     * server-side pre-boot for a fresh (non-SPA) load of the same page.
     *
     * Deliberately does nothing when the heading is empty (e.g. the
     * setlist-shared fragment's title span is filled in async by its own
     * fetch, after this runs — the generic "Shared Set List — iHymns"
     * title from updateTitle() above stays until that later re-render, if
     * any, chooses to update it) rather than stamping a blank/dash title.
     *
     * @param {string} page Current page name
     * @param {Element|null} heading the `#page-content h1` element, if any.
     */
    applyDynamicRecordTitle(page, heading) {
        if (!DYNAMIC_TITLE_PAGES.has(page)) { return; }
        const name = (heading?.textContent || '').trim();
        if (!name) { return; }
        const appName = this.config.appName || 'iHymns';
        document.title = `${name} — ${appName}`;
    }

    /**
     * Post-load hooks — called after page content is injected.
     * Used to initialise page-specific functionality.
     *
     * @param {string} page Page name
     * @param {object} params Route parameters
     */
    afterPageLoad(page, params) {
        /* Re-bind any offline-download buttons in the freshly injected
           HTML (#453 / #454). The helper idempotently ignores nodes
           that already have handlers. */
        import('./offline-ui.js').then(m => m.bootOfflineUi()).catch(() => {});

        /* #1786 Option B — the public "Sort ▾" control. ONE unconditional
           boot for every page, never a per-page branch (a hand-typed page
           list rots — rule #34): initListSort() finds every
           [data-list-sort-surface] control in the fresh fragment and wires
           any that has a matching [data-list-sort-list] container. A
           surface with no container (favourites/search, array/server mode)
           is skipped by construction — those modules wire themselves via
           wireListSortControl(). */
        import('./list-sort.js').then(m => m.initListSort())
            .catch(err => console.error('[Router] list-sort init failed:', err));

        /* Reading-progress bar on every scrollable page (#751). Was
           song-only originally (#109); the module's short-page
           short-circuit handles non-scrollable pages cleanly so
           there's no need to gate per-page-type here. Songbook colour
           still inherits from .page-song / .page-songbook when
           present; everywhere else the CSS default (--bs-primary)
           applies. */
        this.app.readingProgress.initOnAnyPage();

        /* #1533 — the set-list playback bar is FIXED and lives on <body>, so
           unlike the old in-flow alert it does NOT disappear when the page
           content is swapped. Sync it on EVERY navigation, not just song ones:
           renderSongNavigation() removes the bar when the new page has no
           .page-song, which is what stops it stranding over the home screen.
           Must run before the early `return`s in the song branch below. */
        this.app.setList?.renderSongNavigation();

        /* #1770 C5 (rule #32) — the Quick "Go Live" HOST bar is likewise
           `position:fixed` on `<body>`, so it survives an SPA content swap on
           its own; call it unconditionally on EVERY navigation (not only
           song ones) so leaving a song page — or navigating between two
           non-song pages while hosting — never strands it, and so it stays
           visible across the WHOLE app while hosting (req #1), not just the
           one song page `initSongPage()` already re-renders it from. Same
           idempotent remove-then-conditionally-add shape as
           `renderSongNavigation()` immediately above. */
        this.app.liveFollow?.renderHostBar();

        /* #1266 Phase 2 — the per-line/song markup "add or edit" popover is
           `position:fixed` and appended straight to `<body>` (never nested
           inside `.page-song`), for the SAME reason reading-progress-bar and
           the #1533/#1770 fixed bars above live there: `#page-content` carries
           a CSS transform for the page-transition animation, and a transformed
           ancestor breaks `position:fixed` for any descendant (app.css, the
           `.reading-progress-bar` comment). A body-level fixed element does
           NOT get swept away by the router's `content.innerHTML = html` swap
           (rule #32), so — exactly like `renderSongNavigation()` /
           `renderHostBar()` immediately above — its teardown runs
           UNCONDITIONALLY on EVERY navigation, not only when leaving a song
           page, and as the very first thing this call does (before any early
           return inside the module). Dynamic-imported here (not statically,
           unlike the two `this.app.*` calls above) because song-markup.js is
           not part of the `iHymnsApp` singleton — same "import once, cache
           forever" cost as the unconditional `offline-ui.js` import at the
           top of this function; a module with nothing open just returns. */
        import('./song-markup.js')
            .then(m => m.teardownSongMarkup())
            .catch(() => {});

        /* #1741 P4a-3 — a legacy /writer/<name-slug> (or a name-slug /musician/
           credit link, or a /person|/people alias path) whose fragment resolved to
           a registry musician carries the canonical path on .page-musician.
           Soft-canonicalise the URL bar — the #1343-B data-song-canonical pattern:
           replaceState (no reload, no back-button trap), then retitle as Musician.

           ELI5: if you land on an old writer link (or a name-based musician link)
           and the page you get IS a real registry profile, quietly tidy the address
           bar to the profile's real /musician/<slug> URL — without reloading the
           page or breaking the Back button.

           DETAILED / WHY: `.page-musician[data-musician-canonical]` is only emitted
           by musician.php when a registry row actually rendered (musician.php's
           `$person !== null` guard) — a bare fallback-discography page has no
           canonical URL to point at, so this simply no-ops for it. `replaceState`
           (never `pushState` or `this.navigate()`) matches the #1343-B precedent:
           no second fetch, no new history entry, no reload — the fragment already
           on screen IS the right content, only the bar was stale.
           @link https://developer.mozilla.org/docs/Web/API/History/replaceState
           @link .claude/catalogue-1741-P4a3-plan.md §1.1 leg C */
        if (page === 'writer' || page === 'musician') {
            const _mCanon = document.querySelector('.page-musician[data-musician-canonical]');
            if (_mCanon) {
                const _mto = _mCanon.getAttribute('data-musician-canonical');
                if (_mto && _mto !== window.location.pathname) {
                    window.history.replaceState({ path: _mto }, '', _mto);
                    this.currentPath = _mto;
                    this.updateTitle('musician', params);
                }
            }
            /* #1753 — the #btn-edit-musician reveal was stranded inside the
               page === 'song' branch since #1348 and never ran on musician pages
               (the element only exists in the musician fragment, never the song
               one); it lives here now, where the element actually renders. */
            const editMusicianBtn = document.getElementById('btn-edit-musician');
            if (editMusicianBtn) {
                const role = this.app.userAuth?.getUser()?.role;
                if (userHasEntitlement('manage_musicians', role)) {
                    editMusicianBtn.classList.remove('d-none');
                }
            }
        }

        /* Initialise favourites state on song pages */
        if (page === 'song') {
            /* #1343 — a merged/deleted/renamed permalink renders a redirect marker
               instead of the song; navigate to the canonical song (history-replaced
               so the dead URL leaves no back-button trap) and skip the song inits. */
            const _redirect = document.querySelector('[data-song-redirect]');
            if (_redirect) {
                const _to = _redirect.getAttribute('data-song-redirect');
                if (_to) { this.navigate(_to, { replace: true }); return; }
            }
            /* #1343-B — the CORRECT song rendered, but via a non-canonical id (a
               legacy SongId / alias). Soft-canonicalise the URL bar to its PublicId
               WITHOUT reloading (content is already right); mirrors the zero-pad
               canonicalise at handleCurrentRoute. */
            const _canonical = document.querySelector('[data-song-canonical]');
            if (_canonical) {
                const _cto = _canonical.getAttribute('data-song-canonical');
                if (_cto && _cto !== window.location.pathname) {
                    window.history.replaceState({ path: _cto }, '', _cto);
                    this.currentPath = _cto;
                }
            }
            this.app.favorites.initSongPage();
            this.app.share.initSongPage();
            this.app.setList.initSongPage();
            this.app.setList.renderSongNavigation();
            this.app.display.initSongPage();
            this.app.compare.initSongPage();
            if (this.app.liveFollow) { this.app.liveFollow.initSongPage(); }  // #1268 Live Follow controls + host broadcast
            this.app.transpose.initSongPage();

            /* ELI5: hook up the Export ▾ menu and the Present button once the song
               fragment is on the page.
               Detail: these two lived as inline <script>s inside the fragment. The
               document sends an enforcing nonce CSP (#117) which refuses nonce-less
               inline scripts, and the fragment is a SHARED-CACHE response (rule #6)
               so it can never carry the per-request nonce — they never ran (#1565,
               #1568). Same fix as home-page.js: real modules, imported here.
               https://developer.mozilla.org/docs/Web/HTTP/Headers/Content-Security-Policy/script-src */
            const exportSongId = document.querySelector('.page-song')?.dataset.songId || params.id || '';
            import('./export-ui.js')
                .then(m => m.initSongExport(exportSongId))
                .catch(err => console.error('[Router] export-ui init failed:', err));
            import('./present-mode.js')
                .then(m => m.initPresentMode())
                .catch(err => console.error('[Router] present-mode init failed:', err));
            /* #1089/#1100 P1 — per-line translation "Show translation" toggle.
               Same CSP/shared-cache-fragment reasoning as Export/Present above:
               song.php only emits the button (and the hidden translation rows
               it controls) when the song actually has approved per-line
               translations, so this is a cheap no-op on every other song. */
            import('./song-translations.js')
                .then(m => m.initLineTranslations())
                .catch(err => console.error('[Router] song-translations init failed:', err));
            /* #1266 Phase 2 — per-user song highlights & notes. DOM-first
               (rule #33): the module reads SongId from `.page-song[data-song-id]`
               and lines from `[data-line-id]` itself rather than taking them as
               arguments, so no params are passed here (mirrors
               initLineTranslations() above, not the exportSongId-taking calls
               below). Signed-in-only: the module itself checks
               window.iHymnsApp.userAuth.getUser() and no-ops for a guest. */
            import('./song-markup.js')
                .then(m => m.initSongMarkup())
                .catch(err => console.error('[Router] song-markup init failed:', err));
            /* Musical key / tempo / time signature (#298, wired #1671 F3).
               Runs AFTER this.app.transpose.initSongPage() above deliberately:
               transpose.js reads `dataset.key` once at init, and `data-key` has
               never actually been emitted by song.php (SongData sets no `key`
               field at all), so its key-display branch has never executed. This
               module fetches the key, sets the attribute and re-runs
               initSongPage() so that branch finally has an input. Most songs
               have no key row and the endpoint answers 404, which the module
               treats as "nothing to show" rather than as an error. */
            import('./song-key.js')
                .then(m => m.initSongKey(exportSongId))
                .catch(err => console.error('[Router] song-key init failed:', err));
            /* readingProgress.initOnAnyPage() already ran at the top
               of afterPageLoad — covers every page including song.
               Removing the song-specific re-call avoids a redundant
               cleanup-then-recreate cycle. (#751) */

            /* Audio button — hide if the browser can't actually play
               our MIDI-via-Tone.js pipeline (#602). The audio module
               feature-detects Web Audio support; if absent, every
               .btn-audio on the page is hidden so curators don't see
               a button that wouldn't work. Idempotent — safe to call
               on every navigation. */
            this.app.audio?.hideButtonsIfUnsupported?.();

            /* Edit button — show only to users whose role carries the
               `edit_songs` entitlement (#407). The PHP editor API
               re-checks the same map server-side, so hiding the button
               is purely a UX affordance. */
            const editBtn = document.getElementById('btn-edit-song');
            if (editBtn) {
                const role = this.app.userAuth?.getUser()?.role;
                if (userHasEntitlement('edit_songs', role)) {
                    editBtn.classList.remove('d-none');
                }
            }

            /* Save Offline button — check cache state and bind click */
            const saveOfflineBtn = document.querySelector('.btn-save-offline');
            if (saveOfflineBtn) {
                const songId = saveOfflineBtn.dataset.songId;
                this.app.settings.checkSongCacheStatus(songId, saveOfflineBtn);
                saveOfflineBtn.addEventListener('click', () => {
                    this.app.settings.saveSongOffline(songId, saveOfflineBtn);
                });
            }

            /* Precache this song for offline access (#105) */
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                const songApiUrl = this.buildApiUrl('song', params);
                navigator.serviceWorker.controller.postMessage({
                    type: 'CACHE_SONG',
                    url: songApiUrl,
                });
            }

            /* Record song view in history (#92) */
            const songPage = document.querySelector('.page-song');
            if (songPage) {
                const songId = songPage.dataset.songId || params.id || '';
                const titleEl = songPage.querySelector('h1');
                const title = titleEl ? titleEl.textContent.trim() : '';
                const songbook = songPage.dataset.songbook || '';
                const number = parseInt(songPage.dataset.songNumber, 10) || 0;
                if (songId) {
                    this.app.history.recordView(songId, title, songbook, number);
                    if (songbook) this.trackRecentSongbook(songbook);

                    /* Record song view on server for history/popular tracking (#287) */
                    apiFetch(`${this.apiUrl}?action=song_view`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ song_id: songId })
                    }).catch(() => {}); // fire-and-forget
                }

                /* Load song translations (#352) — async, non-blocking */
                this.loadTranslations(songId);

                /* Load related songs (#118) — async, non-blocking */
                this.loadRelatedSongs(songId);
            }
        }

        /* Render recently viewed section on home page (#92) */
        if (page === 'home') {
            this.app.history.renderHomeSection();
            this.app.songOfTheDay.renderHomeSection();
            this.renderRecentSongbooks();

            /* Popular Songs / Recently Viewed / Browse by Theme (#303,
               #304, #305). Previously lived as an inline <script> at the
               bottom of home.php, which relied on a re-parse shim in
               loadPage() to execute after innerHTML injection. Pulling
               it into a proper module and invoking it here removes that
               transport dependency — the sections now run reliably
               whenever the home page is shown. */
            import('./home-page.js')
                .then(m => m.initHomePage())
                .catch(err => console.error('[Router] home-page init failed:', err));
        }

        /* #1148 — the /themes A–Z index: filter + letter jump bar, wired as a
           real ES module (the shared-cache fragment carries no inline script,
           rule #30). Reads its inputs DOM-first from the fragment's data-*. */
        if (page === 'themes') {
            import('./themes-page.js')
                .then(m => m.initThemesPage())
                .catch(err => console.error('[Router] themes-page init failed:', err));
        }

        /* Songbook language filter (#679). Booted on both /home and
           /songbooks since the filter sits above the tile grid on
           each. The module is idempotent so a quick navigation
           home → songbooks → home doesn't double-bind handlers. The
           partial that renders the <select> silently returns early
           when the catalogue spans only one language, in which case
           bootSongbookLanguageFilter is a no-op too (no selector
           found). */
        if (page === 'home' || page === 'songbooks') {
            import('./songbook-language-filter.js')
                .then(m => m.bootSongbookLanguageFilter())
                .catch(err => console.error('[Router] songbook-language-filter init failed:', err));
        }

        /* Per-tile "Export songbook ▾" dropdowns on the /songbooks LIST
           (#1607 — owner decision: whole-songbook export lives on
           /songbooks and /songbook/<abbr>, NOT inside the single-song
           view, to keep that dropdown uncluttered). Same CSP/shared-cache
           reasoning as the song/songbook Export wiring above: the fragment
           can never carry an inline <script>, so this is a real ES module
           imported here. `initSongbookListExport()` wires every tile's own
           menu to its own `data-songbook-id` — it does not take an abbr,
           unlike the single-menu `initSongbookExport()` used on
           /songbook/<abbr> below. */
        if (page === 'songbooks') {
            import('./export-ui.js')
                .then(m => m.initSongbookListExport())
                .catch(err => console.error('[Router] export-ui init failed:', err));
        }

        /* Settings page — language preferences picker (#736). The
           settings page hosts a duplicate of the language filter UI
           inside the Language Preferences section, so the user can
           adjust their preference from a non-grid context. The
           module is idempotent. */
        if (page === 'settings') {
            import('./settings-language-filter.js')
                .then(m => m.bootSettingsLanguageFilter())
                .catch(err => console.error('[Router] settings-language-filter init failed:', err));
            /* Also boot the songbook-filter module so the global
               fetch-header patch + saved subtag list propagation
               applies even when the home grid isn't on screen. */
            import('./songbook-language-filter.js')
                .then(m => m.bootSongbookLanguageFilter())
                .catch(() => { /* non-critical */ });
        }

        /* Persistent bulk-import progress widget (#676). Booted on
           every SPA navigation so a curator who started an import
           on /manage/editor and switched to the public app still
           sees the widget on the home / songbooks / song pages.
           The module reads the active job_id from localStorage and
           renders nothing if there's no in-flight import. */
        import('./bulk-import-progress.js')
            .then(m => m.bootBulkImportProgressWidget())
            .catch(err => console.error('[Router] bulk-import-progress init failed:', err));

        /* Initialise favourites list on favorites page */
        if (page === 'favorites') {
            this.app.favorites.loadFavoritesList();
        }

        /* Initialise settings controls on settings page */
        if (page === 'settings') {
            this.app.settings.initSettingsPage();

            /* Signed-in devices card (#1671 F1). Same rule-#30 wiring as
               home-page.js / export-ui.js: the settings fragment can never
               carry an executable inline <script> (enforcing nonce CSP #117 +
               a fragment that never sees the nonce), so the behaviour is a
               real ES module imported here. The module finds its own card via
               [data-devices-card] and no-ops when it is absent, so this costs
               nothing on any other route. */
            /* Push notifications card (#311 server / #1671 F6). Same rule-#30
               wiring, same DOM-first hook ([data-push-card]), and it reads its
               two inputs — the VAPID public key and the server's kind registry —
               from data-* the fragment already emits, so no extra API round trip
               and no hardcoded copy of the kind list. */
            import('./push-notifications.js')
                .then(m => m.bootPushCard())
                .catch(err => console.error('[Router] push-notifications init failed:', err));

            import('./devices.js')
                .then(m => m.bootDevicesCard())
                .catch(err => console.error('[Router] devices init failed:', err));
        }

        /* Device-code pairing "Link a device" page (#1407). */
        if (page === 'link') {
            import('./device-link.js')
                .then(m => m.bootDeviceLinkPage())
                .catch(err => console.error('[Router] device-link init failed:', err));
        }

        /* ELI5: hook up the Request-a-Song form (fetch submit, offline
           queueing, deep-link prefill) once its fragment is on the page.
           Detail: this logic lived as a `<script type="module">` inside
           includes/pages/request-a-song.php and never executed for anyone —
           the document's enforcing nonce CSP (#117) refuses nonce-less
           inline scripts, the fragment is a separate HTTP response that
           never sees the nonce, and `request` is in api.php's
           $_cacheablePages so it can't carry a per-request nonce at all
           (rule #6). The router's old `_executeInlineScripts()` re-created
           the node with its attributes verbatim, so the copy had no nonce
           either and was refused SILENTLY — every submit was quietly taken
           by the #711 no-JS `<form action>` fallback instead. That helper
           has since been removed entirely (#1619), so a fragment script now
           simply never runs rather than half-running. Same fix as
           home-page.js / export-ui.js: a real ES module imported here
           (#1572).
           `params` carries the forwarded `songbook` / `number` deep-link
           prefill; the module prefers the fragment's own data-prefill-*
           attributes and uses these only as the stale-cache fallback. */
        if (page === 'request') {
            import('./request-a-song.js')
                .then(m => m.initRequestASong(params))
                .catch(err => console.error('[Router] request-a-song init failed:', err));

            /* "Your requests" (#1671 F2) — the outcome side of the form above.
               Same rule-#30 wiring, and for the same reason: `request` is in
               api.php's $_cacheablePages, so this fragment can never carry the
               document's per-request CSP nonce and an inline <script> in it
               would be refused silently. The module finds its own section via
               [data-my-requests] and no-ops when it is absent. */
            import('./my-song-requests.js')
                .then(m => m.initMySongRequests())
                .catch(err => console.error('[Router] my-song-requests init failed:', err));
        }

        /* After the new page HTML is in the DOM, broadcast the current auth
           state so any just-injected markup (Account card, sync bars, etc.)
           lands in the correct logged-in/logged-out state. */
        try {
            document.dispatchEvent(new CustomEvent(EVT_AUTH_CHANGED, {
                detail: {
                    loggedIn: !!this.app.userAuth?.isLoggedIn(),
                    user: this.app.userAuth?.getUser() ?? null,
                },
            }));
        } catch { /* legacy browsers — ignore */ }

        /* Initialise set list page controls (#94) */
        if (page === 'setlist') {
            this.app.setList.initSetListPage();

            /* Set-list templates / service plans (#301, wired #1671 F4).
               Same rule-#30 wiring as home-page.js / export-ui.js: the set-list
               fragment can never carry an executable inline <script> (enforcing
               nonce CSP #117 + a fragment that never sees the nonce), so the
               behaviour is a real ES module imported here. The module finds its
               own hook (#template-dropdown) and no-ops when it is absent, so
               this costs nothing on any other route.

               The dropdown's markup has existed since #301 behind
               `display:none !important` with NO JS referencing it anywhere —
               the same orphan shape as #298's song-key container. It is
               revealed by the module, not by the fragment, so it can never
               again be visible without something behind it. */
            import('./setlist-templates.js')
                .then(m => m.bootSetlistTemplates(this.app.setList))
                .catch(err => console.error('[Router] setlist-templates init failed:', err));
        }

        /* Initialise shared set list page (#147) */
        if (page === 'setlist-shared') {
            this.app.setList.initSharedSetListPage(params.data);
        }

        /* Initialise songbook index (#111) and track visit (#121) */
        if (page === 'songbook') {
            this.app.songbookIndex.initSongbookPage();
            this.trackRecentSongbook(params.id);

            /* Export ▾ dropdown (#1565) — same router-driven wiring as the
               song page above: the fragment's own inline <script> was
               refused by the enforcing CSP (#117) and can't carry a nonce
               (shared-cache rule #6), so it never ran. `.page-songbook`'s
               `data-songbook-abbr` (songbook.php) is the id source the
               router already trusts elsewhere; `params.id` is only the
               fallback for a stale service-worker-cached fragment. */
            const sbAbbr = document.querySelector('.page-songbook')?.dataset.songbookAbbr || params.id || '';
            import('./export-ui.js')
                .then(m => m.initSongbookExport(sbAbbr))
                .catch(err => console.error('[Router] export-ui init failed:', err));
        }

        /* Initialise search page controls */
        if (page === 'search') {
            this.app.search.initSearchPage();
            this.app.numpad.initSearchPageNumpad();
        }

        /* Populate usage statistics (#120) */
        if (page === 'stats') {
            this.populateStats();
        }

        /* Auto-fix badge text contrast for all songbook badges (#152) */
        this.fixBadgeContrast();
    }

    /* =====================================================================
     * MAGIC LINK LOGIN
     * ===================================================================== */

    /**
     * Check for a magic link token in the URL on page load.
     * If `?token=` is present (typically on /login?token=...), verify
     * the token with the API, store credentials, and redirect home.
     * Called once during init() before any routing occurs.
     */
    _handleMagicLink() {
        const params = new URLSearchParams(window.location.search);
        const token = params.get('token');
        if (!token) return;

        /* Clear the token from the URL immediately to prevent re-triggering
         * on refresh or back/forward navigation */
        const cleanPath = window.location.pathname || '/';
        window.history.replaceState({ path: cleanPath }, '', cleanPath);

        /* Verify the token asynchronously */
        this._verifyMagicLinkToken(token);
    }

    /**
     * Verify a magic link token with the API and handle the result.
     *
     * On success: stores bearer token + user info, shows success toast,
     * updates header state, triggers setlist sync, and navigates home.
     *
     * On error: shows error toast and navigates home.
     *
     * @param {string} token The magic link token from the URL
     */
    async _verifyMagicLinkToken(token) {
        try {
            const res = await apiFetch(`${this.apiUrl}?action=auth_email_login_verify`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ token }),
            });

            const data = await res.json();

            if (res.ok && data.token && data.user) {
                /* Store credentials */
                this.app.userAuth?.saveCredentials(data.token, data.user);

                /* Update header to reflect logged-in state */
                this.app.userAuth?._updateHeaderState();

                /* Show success toast */
                this.app.showToast('Signed in successfully!', 'success', 3000);

                /* Trigger a full user-data backfill in the background
                   (setlists + favourites + tags + history) (WS-F/G). */
                this.app.userAuth?.triggerUserDataSync();
            } else {
                /* Token invalid or expired */
                const message = data.error || 'Login link expired. Please request a new one.';
                this.app.showToast(message, 'danger', 5000);
            }
        } catch {
            this.app.showToast('Login link expired. Please request a new one.', 'danger', 5000);
        }

        /* Navigate to home (clear /login from URL if still there) */
        if (window.location.pathname !== '/') {
            window.history.replaceState({ path: '/' }, '', '/');
            this.currentPath = null; /* Reset so handleCurrentRoute proceeds */
            this.handleCurrentRoute();
        }
    }

    /**
     * Automatically set badge text colour (dark/light) based on the
     * computed background luminance.  Uses WCAG relative-luminance
     * formula so any future songbook colour is handled automatically.
     */
    fixBadgeContrast() {
        const badges = document.querySelectorAll(
            '.song-number-badge, .song-number-badge-lg, .songbook-icon'
        );
        if (!badges.length) return;

        const toLinear = c => c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        const rootStyles = getComputedStyle(document.documentElement);

        badges.forEach(badge => {
            let rgb = null;

            /* Try 1: read the solid CSS variable from data-songbook attribute (#159) */
            const bookId = badge.dataset?.songbook
                || badge.className?.match(/songbook-icon-(\w+)/)?.[1];
            if (bookId) {
                const solidColor = rootStyles.getPropertyValue(`--songbook-${bookId}-solid`).trim();
                if (solidColor) {
                    /* Parse hex (#rrggbb) or rgb() */
                    const hex = solidColor.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
                    if (hex) {
                        rgb = [parseInt(hex[1], 16), parseInt(hex[2], 16), parseInt(hex[3], 16)];
                    } else {
                        const rgbM = solidColor.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                        if (rgbM) rgb = [parseInt(rgbM[1], 10), parseInt(rgbM[2], 10), parseInt(rgbM[3], 10)];
                    }
                }
            }

            /* Try 2: fall back to computed backgroundColor (for non-gradient badges) */
            if (!rgb) {
                const bg = getComputedStyle(badge).backgroundColor;
                if (!bg || bg === 'transparent' || bg === 'rgba(0, 0, 0, 0)') return;
                const m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                if (!m) return;
                rgb = [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
            }

            const r = rgb[0] / 255;
            const g = rgb[1] / 255;
            const b = rgb[2] / 255;
            const L = 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);

            /* Pick black or white text by ACTUAL WCAG contrast ratio against this
               background — whichever is higher — so ANY songbook/collection colour
               stays readable in every theme. The old flat luminance threshold
               (L > 0.4) mis-picked WHITE on mid-tone colours: e.g. the CH red
               (#ef4444) gave white ~3.5:1, where black is ~5.6:1. (#152) */
            const contrastBlack = (L + 0.05) / 0.05;
            const contrastWhite = 1.05 / (L + 0.05);
            badge.style.color = contrastBlack >= contrastWhite ? '#1a1a1a' : '#ffffff';
        });
    }

    /**
     * Populate the statistics page with client-side data (#120).
     * Reads from localStorage: history, favourites, setlists, search history.
     */
    populateStats() {
        /* History data */
        let history = [];
        try { history = JSON.parse(localStorage.getItem(STORAGE_HISTORY)) || []; } catch {}

        /* Favourites data */
        let favorites = [];
        try { favorites = JSON.parse(localStorage.getItem(STORAGE_FAVORITES)) || []; } catch {}

        /* Set lists data */
        let setlists = [];
        try { setlists = JSON.parse(localStorage.getItem(STORAGE_SETLISTS)) || []; } catch {}

        /* Search history data */
        let searches = [];
        try { searches = JSON.parse(localStorage.getItem(STORAGE_SEARCH_HISTORY)) || []; } catch {}

        /* Summary counts */
        const el = (id) => document.getElementById(id);
        if (el('stats-total-views')) el('stats-total-views').textContent = history.length;
        if (el('stats-total-favorites')) el('stats-total-favorites').textContent = favorites.length;
        if (el('stats-total-setlists')) el('stats-total-setlists').textContent = setlists.length;
        if (el('stats-total-searches')) el('stats-total-searches').textContent = searches.length;

        /* Most viewed songs — count occurrences in history */
        if (history.length > 0) {
            const viewCounts = {};
            for (const entry of history) {
                if (!viewCounts[entry.id]) {
                    viewCounts[entry.id] = { ...entry, count: 0 };
                }
                viewCounts[entry.id].count++;
            }
            const sorted = Object.values(viewCounts).sort((a, b) => b.count - a.count).slice(0, 10);
            const maxCount = sorted[0]?.count || 1;

            const container = el('stats-most-viewed');
            if (container) {
                container.innerHTML = sorted.map(s => `
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <a href="/song/${escapeHtml(s.id)}" class="text-decoration-none flex-grow-1 text-truncate"
                           data-navigate="song" data-song-id="${escapeHtml(s.id)}">
                            <span class="song-number-badge song-number-badge-sm" data-songbook="${escapeHtml(s.songbook)}">${s.number ?? ''}</span>
                            <span class="ms-1">${escapeHtml(toTitleCase(s.title))}</span>
                        </a>
                        <div class="stats-bar-wrap">
                            <div class="stats-bar" style="width: ${(s.count / maxCount * 100).toFixed(0)}%"></div>
                        </div>
                        <span class="badge bg-secondary">${s.count}</span>
                    </div>
                `).join('');
            }
        }

        /* Favourites by songbook */
        if (favorites.length > 0) {
            const bySongbook = {};
            for (const fav of favorites) {
                const sb = fav.songbook || 'Unknown';
                bySongbook[sb] = (bySongbook[sb] || 0) + 1;
            }
            const sorted = Object.entries(bySongbook).sort((a, b) => b[1] - a[1]);
            const maxCount = sorted[0]?.[1] || 1;

            const container = el('stats-favorites-by-songbook');
            if (container) {
                container.innerHTML = sorted.map(([sb, count]) => `
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="song-number-badge song-number-badge-sm" data-songbook="${escapeHtml(sb)}">${escapeHtml(sb)}</span>
                        <div class="stats-bar-wrap">
                            <div class="stats-bar bg-danger" style="width: ${(count / maxCount * 100).toFixed(0)}%"></div>
                        </div>
                        <span class="badge bg-secondary">${count}</span>
                    </div>
                `).join('');
            }
        }

        /* Search trends — frequency list */
        if (searches.length > 0) {
            const termCounts = {};
            for (const s of searches) {
                const term = (s.query || s).toString().toLowerCase().trim();
                if (term) termCounts[term] = (termCounts[term] || 0) + 1;
            }
            const sorted = Object.entries(termCounts).sort((a, b) => b[1] - a[1]).slice(0, 15);

            const container = el('stats-search-trends');
            if (container) {
                container.innerHTML = '<div class="d-flex flex-wrap gap-2">' +
                    sorted.map(([term, count]) =>
                        `<span class="badge bg-body-secondary text-body">${escapeHtml(term)} <span class="text-muted">(${count})</span></span>`
                    ).join('') + '</div>';
            }
        }

        /* Time-based activity */
        const now = new Date();
        const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const weekStart = new Date(todayStart); weekStart.setDate(weekStart.getDate() - weekStart.getDay());
        const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);

        let today = 0, week = 0, month = 0;
        for (const entry of history) {
            const d = new Date(entry.viewedAt);
            if (d >= todayStart) today++;
            if (d >= weekStart) week++;
            if (d >= monthStart) month++;
        }

        if (el('stats-views-today')) el('stats-views-today').textContent = today;
        if (el('stats-views-week')) el('stats-views-week').textContent = week;
        if (el('stats-views-month')) el('stats-views-month').textContent = month;
    }

    /**
     * Fetch and render translation links for the current song (#352).
     * Queries the API for songs linked as translations in other languages.
     * Also checks the reverse direction (if this song is itself a translation).
     *
     * @param {string} songId The current song's ID
     */
    async loadTranslations(songId) {
        const container = document.getElementById('song-translations');
        const itemsEl = document.getElementById('song-translations-items');
        if (!container || !itemsEl) return;

        try {
            const resp = await apiFetch(`${this.apiUrl}?action=song_translations&id=${encodeURIComponent(songId)}`);
            if (!resp.ok) return;
            const data = await resp.json();

            const translations = data.translations || [];
            if (translations.length === 0) return;

            itemsEl.innerHTML = translations.map(tr => `
                <a href="/song/${escapeHtml(tr.songId)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${escapeHtml(tr.songId)}"
                   role="listitem">
                    <span class="song-number-badge">${tr.number || '?'}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${escapeHtml(toTitleCase(tr.title))}${tr.verified ? ' <i class="fa-solid fa-circle-check text-success small" aria-hidden="true" title="Verified"></i>' : ''}</span>
                        <!-- a11y audit m3 (2026-08-28): lang on the endonym so a screen
                             reader pronounces it with the right language's rules rather
                             than the page's own (mirrors songbook-language-filter.php's
                             matching fix). -->
                        <small class="text-muted d-block">
                            <i class="fa-solid fa-language me-1" aria-hidden="true"></i><span${tr.language ? ` lang="${escapeHtml(tr.language)}"` : ''}>${escapeHtml(tr.languageNativeName || tr.languageName || tr.language)}</span>${tr.translator ? ` — ${escapeHtml(tr.translator)}` : ''}
                        </small>
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>
            `).join('');

            container.classList.remove('d-none');
            this.fixBadgeContrast();
        } catch (err) {
            console.warn('[Router] Failed to load translations:', err.message);
        }
    }

    /**
     * Find and render related songs for the current song page (#118).
     * Uses the LIVE ?action=related_songs endpoint (shared writer / composer
     * / tag / same-songbook, scored server-side), so it works DB-direct with
     * no client corpus (WS-I #1017 — was a whole-corpus TF-IDF scan). Runs
     * asynchronously; silently skips when offline or on error.
     *
     * @param {string} currentSongId The current song's ID
     */
    async loadRelatedSongs(currentSongId) {
        const container = document.getElementById('related-songs');
        const itemsEl = document.getElementById('related-songs-items');
        if (!container || !itemsEl) return;

        try {
            const url = new URL(this.app.config.apiUrl, window.location.origin);
            url.searchParams.set('action', 'related_songs');
            url.searchParams.set('id', currentSongId);
            url.searchParams.set('limit', '5');
            const response = await apiFetch(url);
            if (!response.ok) return; /* offline / error — non-critical, skip */
            const data = await response.json();
            const related = (data.related || []).slice(0, 5);
            if (related.length === 0) return;

            itemsEl.innerHTML = related.map(song => `
                <a href="/song/${escapeHtml(song.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${escapeHtml(song.id)}"
                   role="listitem">
                    <span class="song-number-badge" data-songbook="${escapeHtml(song.songbook || '')}">${song.number ?? ''}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${escapeHtml(toTitleCase(song.title || ''))}</span>
                        ${song.reason ? `<small class="text-muted d-block">${escapeHtml(song.reason)}</small>` : ''}
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>
            `).join('');

            container.classList.remove('d-none');
            this.fixBadgeContrast();

        } catch (err) {
            /* Non-critical — silently skip if related-songs is unavailable */
            console.warn('[Router] Failed to load related songs:', err.message);
        }
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */

    /* =====================================================================
     * RECENT SONGBOOKS (#121)
     * ===================================================================== */

    /**
     * Track a songbook visit in localStorage.
     * Keeps the 5 most recent unique songbooks.
     * @param {string} songbookId
     */
    trackRecentSongbook(songbookId) {
        if (!songbookId) return;
        const key = STORAGE_RECENT_SONGBOOKS;
        let recent = [];
        try { recent = JSON.parse(localStorage.getItem(key)) || []; } catch {}

        /* Move to front if already tracked */
        recent = recent.filter(id => id !== songbookId);
        recent.unshift(songbookId);
        recent = recent.slice(0, 5);

        localStorage.setItem(key, JSON.stringify(recent));
    }

    /**
     * Render recent songbook quick-access badges on the home page (#121, #162).
     * Shows coloured squares with the songbook abbreviation inside them.
     */
    renderRecentSongbooks() {
        const container = document.getElementById('recent-songbooks');
        if (!container) return;

        let recent = [];
        try { recent = JSON.parse(localStorage.getItem(STORAGE_RECENT_SONGBOOKS)) || []; } catch {}

        /* Only show if user has visited 2+ songbooks */
        if (recent.length < 2) return;

        const songbooks = this.config.songbooks || [];
        /* Drop any recently-viewed songbooks that no longer exist (deleted) so a
           badge never renders a 404-on-click link (e.g. a removed HAOLD). */
        recent = recent.filter(id => songbooks.some(b => b.id === id));
        if (recent.length === 0) return;

        const badges = recent.map(id => {
            const sb = songbooks.find(b => b.id === id);
            const name = sb?.name || id;
            return `<a href="/songbook/${escapeHtml(id)}"
                       class="text-decoration-none text-center"
                       data-navigate="songbook"
                       data-songbook-id="${escapeHtml(id)}"
                       title="${escapeHtml(name)}"
                       aria-label="${escapeHtml(name)}">
                        <span class="song-number-badge d-flex align-items-center justify-content-center"
                              data-songbook="${escapeHtml(id)}"
                              style="width: 48px; height: 48px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.02em;">
                            ${escapeHtml(id)}
                        </span>
                    </a>`;
        }).join('');

        container.innerHTML = `
            <div class="d-flex align-items-center gap-2 mb-1">
                <small class="text-muted fw-semibold">
                    <i class="fa-solid fa-clock-rotate-left me-1" aria-hidden="true"></i>
                    Recently viewed songbooks
                </small>
            </div>
            <div class="d-flex flex-wrap gap-2">${badges}</div>
        `;
        container.classList.remove('d-none');

        /* Fix badge text contrast for the songbook-coloured badges */
        this.fixBadgeContrast();
    }
}
