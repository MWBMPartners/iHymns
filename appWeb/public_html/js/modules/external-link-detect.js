/**
 * iHymns — External-Link Provider Auto-Detect (#841)
 *
 * Single source of truth for URL → tblExternalLinkTypes.Slug mapping
 * across every admin edit modal that exposes the external-links
 * card-list editor (songbooks, credit-people, songs, works — wired in
 * by each page as the rest of #838 / #839 land alongside #840).
 *
 * Mirrors MusicBrainz behaviour: paste a URL, the provider dropdown
 * flips to the matching registry entry. If the curator already
 * manually picked a non-default provider, their choice wins
 * (data-user-picked attribute prevents the auto-detector from
 * overwriting). Empty / unknown URL → no change.
 *
 * Exposed on `window.iHymnsLinkDetect`:
 *   detectFromUrl(url)            → slug | null
 *   slugToOptionValue(select, sl) → <option> value (numeric id) or ''
 *   attachAutoDetect(rowEl, opts) → teardown fn
 *
 * Adding a new provider: append an entry to the `RULES` array below.
 * That's the only place. Order matters — more-specific patterns
 * (path-prefix-discriminated) must come before less-specific ones.
 */

(function () {
    'use strict';

    /* Rules are evaluated top-to-bottom; first match wins. Each rule:
     *   { slug, hosts: string[], pathPrefix?: string, hostMatch?: 'eq'|'suffix' }
     * - hosts: lowercase host strings to compare
     * - hostMatch: 'eq' (exact) or 'suffix' (e.g. '*.bandcamp.com').
     *   Defaults to 'suffix' so 'youtube.com' matches both
     *   'youtube.com' and 'www.youtube.com'.
     * - pathPrefix: optional prefix the URL pathname must start with
     *   (case-insensitive). Used to discriminate musicbrainz/work,
     *   musicbrainz/recording, musicbrainz/artist on the same host.
     */
    var RULES = [
        /* MusicBrainz path-discriminated rules go FIRST so they win over
           any later "musicbrainz.org" generic match (we don't ship a
           generic one — but the precedence is still right). */
        { slug: 'musicbrainz-work',      hosts: ['musicbrainz.org'], pathPrefix: '/work/' },
        { slug: 'musicbrainz-recording', hosts: ['musicbrainz.org'], pathPrefix: '/recording/' },
        { slug: 'musicbrainz-artist',   hosts: ['musicbrainz.org'], pathPrefix: '/artist/' },

        /* music.youtube.com must beat youtube.com */
        { slug: 'youtube-music',         hosts: ['music.youtube.com'] },
        { slug: 'youtube',               hosts: ['youtube.com', 'youtu.be', 'm.youtube.com'] },

        { slug: 'wikipedia',             hosts: ['wikipedia.org'] }, /* suffix → matches en., de., …  */
        { slug: 'wikidata',              hosts: ['wikidata.org'] },
        { slug: 'hymnary-org',           hosts: ['hymnary.org'] },
        { slug: 'hymnal-plus',           hosts: ['hymnalplus.com'] },
        { slug: 'cyber-hymnal',          hosts: ['hymntime.com', 'cyberhymnal.org'] },
        { slug: 'internet-archive',      hosts: ['archive.org'] },
        { slug: 'open-library',          hosts: ['openlibrary.org'] },
        { slug: 'oclc-worldcat',         hosts: ['worldcat.org'] },
        { slug: 'viaf',                  hosts: ['viaf.org'] },
        { slug: 'loc-name-authority',    hosts: ['id.loc.gov'] },
        { slug: 'find-a-grave',          hosts: ['findagrave.com'] },
        { slug: 'ccli-songselect',       hosts: ['songselect.ccli.com'] },
        { slug: 'imslp',                 hosts: ['imslp.org'] },
        { slug: 'vimeo',                 hosts: ['vimeo.com'] },
        { slug: 'spotify',               hosts: ['open.spotify.com', 'spotify.com'] },
        { slug: 'apple-music',           hosts: ['music.apple.com'] },
        { slug: 'bandcamp',              hosts: ['bandcamp.com'] },
        { slug: 'soundcloud',            hosts: ['soundcloud.com'] },
        { slug: 'librivox',              hosts: ['librivox.org'] },
        { slug: 'discogs',               hosts: ['discogs.com'] },
        { slug: 'goodreads-author',     hosts: ['goodreads.com'], pathPrefix: '/author/' },
        { slug: 'linkedin',              hosts: ['linkedin.com'] },
        { slug: 'twitter-x',             hosts: ['twitter.com', 'x.com'] },
        { slug: 'instagram',             hosts: ['instagram.com'] },
        { slug: 'facebook',              hosts: ['facebook.com', 'm.facebook.com', 'fb.com'] },
        { slug: 'mastodon',              hosts: ['mastodon.social', 'mastodon.online', 'mas.to', 'fosstodon.org'] },
    ];

    function lowerHost(h) { return (h || '').toLowerCase(); }

    function matchHost(rule, host) {
        var mode = rule.hostMatch || 'suffix';
        for (var i = 0; i < rule.hosts.length; i++) {
            var h = rule.hosts[i].toLowerCase();
            if (mode === 'eq') {
                if (host === h) return true;
            } else {
                /* suffix: 'youtube.com' matches 'www.youtube.com' but
                   not 'notyoutube.com' — boundary check via leading '.'
                   or full-string equality. */
                if (host === h || host.endsWith('.' + h)) return true;
            }
        }
        return false;
    }

    /**
     * @param {string} rawUrl
     * @returns {string|null} matching slug, or null
     */
    function detectFromUrl(rawUrl) {
        if (typeof rawUrl !== 'string') return null;
        var s = rawUrl.trim();
        if (!s) return null;
        /* URL constructor throws on malformed input → return null. */
        var u;
        try { u = new URL(s); } catch (_e) { return null; }
        var host = lowerHost(u.hostname);
        if (!host) return null;
        var path = (u.pathname || '/').toLowerCase();
        for (var i = 0; i < RULES.length; i++) {
            var r = RULES[i];
            if (!matchHost(r, host)) continue;
            if (r.pathPrefix && !path.startsWith(r.pathPrefix.toLowerCase())) continue;
            return r.slug;
        }
        return null;
    }

    /**
     * Walk the <select>'s options and find the one whose label / data
     * matches the slug. Pages serialise the registry with both
     * `slug` and the option `value` being the numeric `tblExternalLinkTypes.Id`
     * — we don't have the slug on the option directly, so we cross-reference
     * via the JSON list exposed by each page on `window._iHymnsLinkTypes`.
     *
     * @param {HTMLSelectElement} selectEl
     * @param {string} slug
     * @returns {string} option value, or '' when no match
     */
    function slugToOptionValue(selectEl, slug) {
        if (!selectEl || !slug) return '';
        var types = (window && Array.isArray(window._iHymnsLinkTypes)) ? window._iHymnsLinkTypes : [];
        for (var i = 0; i < types.length; i++) {
            if ((types[i].slug || '').toLowerCase() === slug.toLowerCase()) {
                return String(types[i].id || '');
            }
        }
        return '';
    }

    /**
     * Wire a row card so pasting / typing in its url-input flips its
     * provider <select> to the detected provider — UNLESS the curator
     * already manually picked one (data-user-picked stamped on `change`).
     *
     * @param {HTMLElement} rowEl  The card containing both inputs.
     * @param {object} [opts]
     * @param {boolean} [opts.respectManualChoice=true]
     * @returns {Function} teardown that removes the listeners.
     */
    function attachAutoDetect(rowEl, opts) {
        if (!rowEl) return function () {};
        var settings = Object.assign({ respectManualChoice: true }, opts || {});
        var urlInput  = rowEl.querySelector('input[type="url"], input[name="ext_link_urls[]"]');
        var providerSelect = rowEl.querySelector('select[name="ext_link_type_ids[]"]');
        if (!urlInput || !providerSelect) return function () {};

        function onSelectChange() {
            /* Only stamp 'user picked' when the resulting value is a
               real provider; empty selection should remain auto-detect-eligible. */
            if (providerSelect.value) {
                providerSelect.dataset.userPicked = '1';
            } else {
                delete providerSelect.dataset.userPicked;
            }
        }
        function onUrlChange() {
            if (settings.respectManualChoice && providerSelect.dataset.userPicked === '1') return;
            var slug = detectFromUrl(urlInput.value);
            if (!slug) return;
            var nextValue = slugToOptionValue(providerSelect, slug);
            if (!nextValue) return;
            if (providerSelect.value === nextValue) return;
            providerSelect.value = nextValue;
            /* Synthetic event so any parent listeners (form-validators,
               framework wrappers) see the change. We DO NOT mark this as
               user-picked — programmatic selection stays auto-overrideable. */
            providerSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        providerSelect.addEventListener('change', onSelectChange);
        urlInput.addEventListener('input',  onUrlChange);
        urlInput.addEventListener('change', onUrlChange);
        urlInput.addEventListener('paste', function () {
            /* Paste fires before the value updates; queue a tick. */
            setTimeout(onUrlChange, 0);
        });

        /* If the row already has a URL when attached (edit-modal load),
           run detection once immediately — but only when no provider is
           selected yet, so we don't trample a curator's pre-existing
           explicit choice. */
        if (urlInput.value && !providerSelect.value) {
            onUrlChange();
        }

        return function teardown() {
            providerSelect.removeEventListener('change', onSelectChange);
            urlInput.removeEventListener('input',  onUrlChange);
            urlInput.removeEventListener('change', onUrlChange);
        };
    }

    /* Expose. */
    window.iHymnsLinkDetect = {
        detectFromUrl: detectFromUrl,
        slugToOptionValue: slugToOptionValue,
        attachAutoDetect: attachAutoDetect,
        /* Exposed for tests / debug — read-only conceptually. */
        _RULES: RULES,
    };
})();
