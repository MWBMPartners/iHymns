/**
 * iHymns — External-Link Provider Auto-Detect (#841)
 *
 * Single source of truth for URL → tblExternalLinkTypes.Slug mapping
 * across every admin edit modal that exposes the external-links
 * card-list editor (songbooks, musicians, songs, works — wired in
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

        /* Extra streaming platforms. Amazon Music + Yandex Music enumerate
           the per-country music.<host>.<tld> exact subdomains; the rest
           are bare domains with suffix matching so listen.tidal.com,
           www.deezer.com, play.anghami.com, etc. all match a single rule. */
        { slug: 'tidal',                 hosts: ['tidal.com'] },
        { slug: 'deezer',                hosts: ['deezer.com'] },
        { slug: 'amazon-music',          hosts: [
            'music.amazon.com', 'music.amazon.co.uk', 'music.amazon.de',
            'music.amazon.co.jp', 'music.amazon.fr', 'music.amazon.it',
            'music.amazon.es', 'music.amazon.com.au', 'music.amazon.ca',
            'music.amazon.com.br', 'music.amazon.com.mx', 'music.amazon.in',
        ], hostMatch: 'eq' },
        { slug: 'pandora',               hosts: ['pandora.com'] },
        { slug: 'iheartradio',           hosts: ['iheart.com', 'iheartradio.com', 'iheartradio.ca'] },
        { slug: 'qobuz',                 hosts: ['qobuz.com'] },
        { slug: 'napster',               hosts: ['napster.com'] },
        { slug: 'anghami',               hosts: ['anghami.com'] },
        { slug: 'jiosaavn',              hosts: ['jiosaavn.com', 'saavn.com'] },
        { slug: 'yandex-music',          hosts: [
            'music.yandex.ru', 'music.yandex.com', 'music.yandex.by',
            'music.yandex.kz', 'music.yandex.uz',
        ], hostMatch: 'eq' },
        { slug: 'mixcloud',              hosts: ['mixcloud.com'] },
        { slug: 'audiomack',             hosts: ['audiomack.com'] },

        { slug: 'discogs',               hosts: ['discogs.com'] },
        { slug: 'goodreads-author',     hosts: ['goodreads.com'], pathPrefix: '/author/' },

        /* Media databases — film / TV / streaming / anime / games.
           Forward-looking groundwork for iLyrics DB + MeedyaDB which
           will share the iHymns external-link registry. */
        { slug: 'imdb',                  hosts: ['imdb.com'] },
        { slug: 'tmdb',                  hosts: ['themoviedb.org'] },
        { slug: 'thetvdb',               hosts: ['thetvdb.com'] },
        { slug: 'letterboxd',            hosts: ['letterboxd.com'] },
        { slug: 'rotten-tomatoes',       hosts: ['rottentomatoes.com'] },
        { slug: 'metacritic',            hosts: ['metacritic.com'] },
        { slug: 'allmovie',              hosts: ['allmovie.com'] },
        { slug: 'tvmaze',                hosts: ['tvmaze.com'] },
        { slug: 'trakt',                 hosts: ['trakt.tv'] },
        { slug: 'justwatch',             hosts: ['justwatch.com'] },
        { slug: 'myanimelist',           hosts: ['myanimelist.net'] },
        { slug: 'anidb',                 hosts: ['anidb.net'] },
        { slug: 'igdb',                  hosts: ['igdb.com'] },

        { slug: 'linkedin',              hosts: ['linkedin.com'] },
        { slug: 'twitter-x',             hosts: ['twitter.com', 'x.com'] },
        { slug: 'instagram',             hosts: ['instagram.com'] },
        { slug: 'facebook',              hosts: ['facebook.com', 'm.facebook.com', 'fb.com'] },
        { slug: 'mastodon',              hosts: ['mastodon.social', 'mastodon.online', 'mas.to', 'fosstodon.org'] },

        /* MusicBrainz-parity additions — providers commonly surfaced on
           the MusicBrainz artist page that iHymns didn't yet detect. */
        { slug: 'myspace',               hosts: ['myspace.com'] },
        { slug: 'allmusic',              hosts: ['allmusic.com'] },
        { slug: 'lastfm',                hosts: ['last.fm'] },
        { slug: 'bandsintown',           hosts: ['bandsintown.com'] },
        { slug: 'genius',                hosts: ['genius.com'] },
        { slug: 'muzikum',               hosts: ['muzikum.eu'] },
        { slug: 'secondhandsongs',       hosts: ['secondhandsongs.com'] },
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
     * Build a normalised rule list from the DB-driven payload (#845).
     * `window._iHymnsLinkTypes` rows carry a `patterns` array — each
     * pattern row is { host, pathPrefix, matchSubdomains, priority }
     * straight from `tblExternalLinkPatterns`. We expand this into the
     * same shape the local `RULES` array uses, sort by priority, and
     * cache. Empty when no link type carries patterns — caller falls
     * back to RULES.
     */
    var _dbRulesCache = null;
    function loadDbRules() {
        if (_dbRulesCache !== null) return _dbRulesCache;
        var types = (window && Array.isArray(window._iHymnsLinkTypes)) ? window._iHymnsLinkTypes : [];
        var rules = [];
        for (var i = 0; i < types.length; i++) {
            var t = types[i];
            if (!t || !Array.isArray(t.patterns)) continue;
            for (var j = 0; j < t.patterns.length; j++) {
                var p = t.patterns[j] || {};
                if (!p.host) continue;
                rules.push({
                    slug:       String(t.slug || ''),
                    hosts:      [String(p.host)],
                    hostMatch:  p.matchSubdomains ? 'suffix' : 'eq',
                    pathPrefix: p.pathPrefix ? String(p.pathPrefix) : null,
                    priority:   Number(p.priority || 100),
                });
            }
        }
        rules.sort(function (a, b) { return a.priority - b.priority; });
        return (_dbRulesCache = rules);
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
        /* Prefer the DB-driven rule set (#845) when available. Falls back
           to the hard-coded RULES array on pre-migration deployments and
           on admin pages that don't expose `_iHymnsLinkTypes`. */
        var dbRules = loadDbRules();
        var ruleSet = (dbRules && dbRules.length > 0) ? dbRules : RULES;
        for (var i = 0; i < ruleSet.length; i++) {
            var r = ruleSet[i];
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
     * @param {boolean}  [opts.respectManualChoice=true]
     * @param {string}   [opts.urlSelector]    CSS selector for the URL input.
     *                                          Default matches both the
     *                                          canonical ext_link_urls[]
     *                                          field and any <input type="url">.
     * @param {string}   [opts.selectSelector] CSS selector for the provider
     *                                          <select>. Default targets
     *                                          ext_link_type_ids[].
     * @param {function} [opts.slugLookup]     (slug, selectEl) => optionValue.
     *                                          Override the default lookup
     *                                          (which cross-references
     *                                          window._iHymnsLinkTypes by id)
     *                                          when the consuming surface
     *                                          uses a different vocabulary.
     * @returns {Function} teardown that removes the listeners.
     */
    function attachAutoDetect(rowEl, opts) {
        if (!rowEl) return function () {};
        var settings = Object.assign({
            respectManualChoice: true,
            urlSelector:    'input[type="url"], input[name="ext_link_urls[]"]',
            selectSelector: 'select[name="ext_link_type_ids[]"]',
            slugLookup:     null,
        }, opts || {});
        var urlInput  = rowEl.querySelector(settings.urlSelector);
        var providerSelect = rowEl.querySelector(settings.selectSelector);
        if (!urlInput || !providerSelect) return function () {};
        var lookupFn = (typeof settings.slugLookup === 'function')
            ? settings.slugLookup
            : function (slug, sel) { return slugToOptionValue(sel, slug); };

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
            var nextValue = lookupFn(slug, providerSelect);
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
        /* Re-read window._iHymnsLinkTypes on next detect call. Useful
           after dynamically replacing the registry payload (admin
           preview, tests). */
        _resetDbRulesCache: function () { _dbRulesCache = null; },
    };
})();
