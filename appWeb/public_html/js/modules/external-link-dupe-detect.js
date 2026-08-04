/**
 * iHymns — External-Link Duplicate Detection
 *
 * Detects when a curator pastes / types a URL that already exists in
 * another row of the same external-links card-list, raises a toast,
 * and removes the just-edited duplicate row. Shared across every
 * surface that exposes an external-links editor — the canonical one
 * (songs / songbooks / works via includes/partials/external-links-section.php
 * + js/modules/external-links-editor.js) AND the musicians inline
 * editor (manage/musicians.php's bespoke `cp-link-row` rows).
 *
 * Comparison is host + path only (no query, no hash, www. stripped,
 * trailing slash trimmed, case-folded) so curators don't end up with
 *   https://www.imdb.com/name/nm123/
 *   https://IMDB.com/name/nm123
 *   http://imdb.com/name/nm123/?ref=footer
 * as three "different" rows pointing at the same resource.
 *
 * Exposed on `window.iHymnsExtLinkDupeDetect`:
 *   normalise(url)              → canonical comparison key, or ''
 *   attach(rowEl, opts)         → teardown fn
 *
 * `attach` listens for change / blur / paste on the row's URL input.
 * A match against a sibling row triggers
 *   1. window.iHymnsToast.show(...)
 *   2. rowEl.remove()
 * in that order — the toast then sticks even though the row is gone.
 *
 * Pre-fill on edit-modal load is safe: programmatic `.value = …`
 * assignments don't fire change/blur, so two stored duplicates from
 * before this feature shipped stay visible until the curator touches
 * one — at which point the listener kicks in and resolves the dupe.
 */

(function () {
    'use strict';

    /* Query params that NEVER change which resource a link points at — safe to
       strip before comparing (so "same video + a tracker" still dedupes). The
       identity-bearing params (e.g. YouTube `?v=`) are KEPT. (#1177) */
    var TRACKING_PARAM = /^(?:utm_[a-z]+|ref|referrer|fbclid|gclid|dclid|msclkid|igshid|mc_[a-z]+|spm|si|feature|app|source|_hsenc|_hsmi|yclid)$/i;

    /* Provider canonicalisers — collapse genuinely-equivalent forms of the SAME
       resource to one key, so they ARE caught as duplicates, while DIFFERENT
       resources on the same platform stay distinct. IDs are case-SENSITIVE
       (YouTube/Spotify), so extract from the original-case path. */
    function youtubeId(u, host, path) {
        if (host === 'youtu.be') {
            var m = path.match(/^\/([A-Za-z0-9_-]{6,})/);
            return m ? m[1] : null;
        }
        if (host === 'youtube.com' || host === 'm.youtube.com'
            || host === 'music.youtube.com' || host === 'youtube-nocookie.com') {
            if (path === '/watch') { return u.searchParams.get('v') || null; }
            var m2 = path.match(/^\/(?:shorts|embed|v|live)\/([A-Za-z0-9_-]{6,})/);
            if (m2) return m2[1];
        }
        return null;
    }
    function spotifyId(host, path) {
        if (host === 'open.spotify.com' || host === 'spotify.com') {
            var m = path.match(/^\/(?:intl-[a-z]+\/)?(track|album|playlist|episode|show|artist)\/([A-Za-z0-9]+)/);
            if (m) { return m[1] + '/' + m[2]; }
        }
        return null;
    }

    /**
     * Normalise a URL string to a comparison key (#1177). Strategy:
     *   - lowercase host, strip leading 'www.'; drop trailing '/'
     *   - KEEP identity-bearing query params (strip only the tracking allowlist),
     *     sorted for stability — so two DIFFERENT YouTube videos
     *     (watch?v=A vs watch?v=B) are NO LONGER treated as the same link
     *   - provider-aware canonicalisation so genuinely-equivalent forms still
     *     dedupe (youtu.be/ID == youtube.com/watch?v=ID == /shorts/ID; Spotify
     *     track/ID ignoring ?si)
     *   - net: exact-same URL blocked; same platform, different resource allowed
     *   - non-URL input (malformed, relative): lowercased + trimmed trailing
     *     slash so simple duplicate text is still caught
     */
    function normalise(url) {
        if (typeof url !== 'string') return '';
        var s = url.trim();
        if (!s) return '';
        try {
            var u = new URL(s);
            var host = (u.hostname || '').toLowerCase();
            if (host.indexOf('www.') === 0) host = host.slice(4);
            /* Original-case path for case-sensitive provider IDs. */
            var rawPath = (u.pathname || '/').replace(/\/+$/, '') || '/';

            var yt = youtubeId(u, host, rawPath);
            if (yt) { return 'youtube:' + yt; }
            var sp = spotifyId(host, rawPath);
            if (sp) { return 'spotify:' + sp; }

            /* Generic: host + path + identity query (tracking stripped, sorted).
               Keep param keys/values as-is apart from lowercasing the key — a
               value can be a case-sensitive id. */
            var params = [];
            u.searchParams.forEach(function (v, k) {
                if (TRACKING_PARAM.test(k)) { return; }
                params.push(k.toLowerCase() + '=' + v);
            });
            params.sort();
            var q = params.length ? '?' + params.join('&') : '';
            return host + rawPath.toLowerCase() + q;
        } catch (_e) {
            return s.toLowerCase().replace(/\/+$/, '');
        }
    }

    /**
     * Find a sibling row inside `container` whose URL field normalises
     * to `target` (and isn't `excludeRow` itself). Returns the matching
     * row element or null.
     */
    function findDuplicate(container, target, excludeRow, urlSelector, rowSelector) {
        if (!container || !target) return null;
        var rows = container.querySelectorAll(rowSelector);
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (row === excludeRow) continue;
            var input = row.querySelector(urlSelector);
            if (!input) continue;
            if (normalise(input.value) === target) return row;
        }
        return null;
    }

    /**
     * Wire duplicate detection on a single row.
     *
     * @param {HTMLElement} rowEl
     * @param {object} [opts]
     * @param {string}   [opts.urlSelector]
     *        CSS selector for the URL input inside the row + its
     *        siblings. Default matches both the canonical
     *        `name="ext_link_urls[]"` field used by the shared
     *        partial / editor module AND the musicians inline
     *        `name="links[i][url]"` field.
     * @param {string}   [opts.rowSelector]
     *        Selector for sibling rows in the same container. Default
     *        matches `.ihymns-ext-link-row` (shared) and `.cp-link-row`
     *        (musicians).
     * @param {HTMLElement} [opts.container]
     *        Override the container we search for duplicates within.
     *        Defaults to rowEl.parentElement.
     * @param {string}   [opts.toastMessage]
     *        Override the default toast text.
     * @returns {Function} teardown that detaches the listeners.
     */
    function attach(rowEl, opts) {
        if (!rowEl) return function () {};
        var settings = Object.assign({
            urlSelector: 'input[type="url"], input[name="ext_link_urls[]"], input[name$="[url]"]',
            rowSelector: '.ihymns-ext-link-row, .cp-link-row',
            container:   rowEl.parentElement,
            toastMessage: 'That URL is already in this list — duplicate removed.',
        }, opts || {});
        var urlInput = rowEl.querySelector(settings.urlSelector);
        if (!urlInput) return function () {};

        function check() {
            var key = normalise(urlInput.value);
            if (!key) return;
            /* parentElement may have changed since attach time (drag-
               reorder, container swap) — re-read each check. */
            var container = settings.container || rowEl.parentElement;
            var dupe = findDuplicate(container, key, rowEl, settings.urlSelector, settings.rowSelector);
            if (!dupe) return;
            if (window.iHymnsToast && typeof window.iHymnsToast.show === 'function') {
                window.iHymnsToast.show(settings.toastMessage, 'warning');
            }
            rowEl.remove();
        }

        urlInput.addEventListener('change', check);
        urlInput.addEventListener('blur',   check);
        urlInput.addEventListener('paste', function () {
            /* paste fires before the value updates */
            setTimeout(check, 0);
        });

        return function teardown() {
            urlInput.removeEventListener('change', check);
            urlInput.removeEventListener('blur',   check);
        };
    }

    window.iHymnsExtLinkDupeDetect = {
        normalise: normalise,
        attach:    attach,
    };
})();
