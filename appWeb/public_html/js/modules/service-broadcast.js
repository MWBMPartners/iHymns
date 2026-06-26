/**
 * service-broadcast.js — Service Mode BROADCASTER console (#1335 Phase 2b/2c).
 *
 * The shared driver UI an OPERATOR uses to push the congregation's current song
 * to every joined device. It is consumed by BOTH broadcaster front-ends — the
 * projection laptop (manage/service-projection.php) and a separate leader device
 * (manage/service-lead.php) — so the search / section-nav / broadcast logic lives
 * in ONE place (CLAUDE.md modularity rule #1 + rule #26 "never re-fork the helper
 * core"). The matching server endpoint is `service_broadcast` in api.php, which
 * the api.php comment notes "serves BOTH broadcaster mechanisms".
 *
 * Protocol (mirrors live-follow.js's host side): the operator picks a song +
 * advances sections; each change POSTs {sessionId, songId, componentIndex} to
 * `service_broadcast`, which bumps tblLiveFollowSessions.StateRevision. Joined
 * congregants (service-follow.js) short-poll `service_poll` and mirror the song,
 * scrolling to the broadcast section (`.lyric-component` nth-child, by index).
 *
 *   - songId       : a tblSongs.SongId ('MP-1008') from the slim index
 *                    (/api?action=songs_index — DB-direct, lightweight; rule #17).
 *   - componentIndex : 0-based index into the song's components in RENDER order
 *                    (arrangement-projected — see _projectSections), matching the
 *                    order song.php renders `.lyric-component` 1:1 so the
 *                    congregant's scrollIntoView lands on the right verse/chorus
 *                    even when the song has a custom arrangement (#160).
 *
 * Auth: operator pages carry the same-origin `ihymns_auth` cookie, which api.php
 * accepts; the injected `apiCall` only needs the X-Requested-With CSRF header
 * (the #293 pattern). The module never sees a credential.
 *
 * Docs: MDN fetch — https://developer.mozilla.org/docs/Web/API/Fetch_API
 *       Bootstrap 5 utilities — https://getbootstrap.com/docs/5.3/
 */

/* Max search rows rendered at once — keeps the picker snappy on a ~14k-row index
   without a virtual list (the operator types to narrow long before this bites). */
const SB_MAX_RESULTS = 40;

export class ServiceBroadcaster {
    /**
     * @param {Object}   cfg
     * @param {Function} cfg.apiCall  async (action, {method,query,body}) -> parsed JSON.
     *                                 Supplied by the host page (cookie-authed operator call).
     * @param {number}   cfg.sessionId  the live service session to drive.
     * @param {HTMLElement} cfg.mount   container the console renders into.
     * @param {Function} [cfg.onSessionEnded]  called when the server reports the
     *                                 session is gone (404/ended) so the page can tear down.
     */
    constructor(cfg) {
        this.apiCall   = cfg.apiCall;
        this.sessionId = cfg.sessionId;
        this.mount     = cfg.mount;
        this.onSessionEnded = typeof cfg.onSessionEnded === 'function' ? cfg.onSessionEnded : () => {};

        this.index        = [];     // slim song index [{id,number,title,songbook,songbookName}, …]
        this.currentSong  = null;   // {id, title, components:[…], arrangement:[…]} being broadcast
        this.sections     = [];     // components in RENDER order (arrangement-projected) — see _projectSections
        this.currentIndex = 0;      // index INTO this.sections that is broadcast
        this._lastKey     = '';     // "songId|idx" de-dupe — don't re-POST an unchanged state
        this._els         = {};     // cached DOM handles
        this._destroyed   = false;
    }

    /** Load the song index + paint the console. Safe to await; failures degrade gracefully. */
    async init() {
        this._render();
        try {
            const d = await this.apiCall('songs_index', { method: 'GET' });
            this.index = (d && Array.isArray(d.songs)) ? d.songs : [];
        } catch (_e) {
            this.index = [];
        }
        if (this._els.search) {
            this._els.search.placeholder = this.index.length
                ? ('Search ' + this.index.length + ' songs…')
                : 'Song list unavailable';
        }
    }

    /** Tear down timers/handlers + clear the DOM (host calls on end / unload). */
    destroy() {
        this._destroyed = true;
        if (this.mount) { this.mount.innerHTML = ''; }
    }

    /* ----------------------------------------------------------------- drive -- */

    /** Pick a song: fetch its components (for the section list), broadcast section 0. */
    async selectSong(songId) {
        if (!songId) { return; }
        let song = null;
        try {
            const d = await this.apiCall('song_detail', { method: 'GET', query: '&id=' + encodeURIComponent(songId) });
            song = d && d.song ? d.song : null;
        } catch (_e) { /* fall through to a title-only entry */ }

        if (!song) {
            /* Detail fetch failed — still broadcast by id with a single section so
               the operator isn't blocked (the congregant just won't section-scroll). */
            const meta = this.index.find((s) => String(s.id) === String(songId));
            song = { id: songId, title: meta ? meta.title : songId, components: [] };
        }
        this.currentSong  = song;
        this.sections     = this._projectSections(song);
        this.currentIndex = 0;
        this._renderNowPlaying();
        this._closePicker();
        await this.broadcast(song.id || songId, 0);
    }

    /**
     * Project a song's components into the SAME order song.php renders
     * `.lyric-component` — so a broadcast index lands on the section the
     * congregant scrolls to (service-follow._scrollToComponent, nth-child).
     * Mirrors song.php exactly (#160 arrangement-aware rendering): when an
     * `arrangement` (an array of indices into components, possibly reordered /
     * repeated / sparse) is present, render order = arrangement.map(i =>
     * components[i]).filter(Boolean); otherwise the raw component order. Without
     * this the index would be arrangement-blind and point at the wrong verse.
     */
    _projectSections(song) {
        const comps = (song && song.components) || [];
        const arr = song && Array.isArray(song.arrangement) ? song.arrangement : null;
        if (arr && arr.length) {
            return arr.map((i) => comps[i]).filter((c) => c !== undefined && c !== null);
        }
        return comps;
    }

    /** Advance / rewind the broadcast section within the current song. */
    nextSection() { this._goSection(this.currentIndex + 1); }
    prevSection() { this._goSection(this.currentIndex - 1); }

    _goSection(idx) {
        if (!this.currentSong) { return; }
        const count = this.sections.length;
        const max = count > 0 ? count - 1 : 0;
        const next = Math.max(0, Math.min(max, idx));
        if (next === this.currentIndex && next !== 0) { return; }
        this.currentIndex = next;
        this._highlightSection();
        this.broadcast(this.currentSong.id, next);
    }

    /**
     * POST one broadcast. De-dupes unchanged state so a re-render can't re-POST.
     * A 404 (session ended/superseded server-side) tears the console down via the
     * onSessionEnded callback — matching live-follow.js's host 409/404 handling.
     */
    async broadcast(songId, componentIndex) {
        if (this._destroyed || !songId) { return; }
        const key = songId + '|' + componentIndex;
        if (key === this._lastKey) { return; }
        try {
            const d = await this.apiCall('service_broadcast', {
                method: 'POST',
                body: { sessionId: this.sessionId, songId: songId, componentIndex: componentIndex },
            });
            if (d && d.ok) {
                this._lastKey = key;
            } else if (d && d.error) {
                /* "Unknown or ended session" → the session is gone; stop driving. */
                this.onSessionEnded();
            }
        } catch (_e) { /* transient network blip — the next change retries */ }
    }

    /* --------------------------------------------------------------- render -- */

    _render() {
        if (!this.mount) { return; }
        this.mount.innerHTML = '';

        /* Now-playing strip: current song title + a horizontal scroll of section
           chips. Built with Bootstrap utilities to match the codebase idiom
           (live-follow.js uses the same class vocabulary). */
        const now = document.createElement('div');
        now.className = 'sb-now d-flex flex-column gap-1';
        const title = document.createElement('div');
        title.className = 'sb-now-title fw-semibold text-truncate';
        title.textContent = 'No song selected';
        const sections = document.createElement('div');
        sections.className = 'sb-sections d-flex flex-wrap gap-1';
        now.appendChild(title);
        now.appendChild(sections);

        /* Controls: prev / next section + open the song picker. */
        const controls = document.createElement('div');
        controls.className = 'sb-controls d-flex flex-wrap gap-2 mt-2';
        const prev = this._btn('btn-outline-secondary', '<i class="bi bi-chevron-left"></i> Prev', () => this.prevSection());
        const next = this._btn('btn-outline-secondary', 'Next <i class="bi bi-chevron-right"></i>', () => this.nextSection());
        const pick = this._btn('btn-amber-solid', '<i class="bi bi-search me-1"></i>Choose song', () => this._togglePicker());
        controls.appendChild(prev);
        controls.appendChild(next);
        controls.appendChild(pick);

        /* Picker: search box + live results, hidden until opened. */
        const picker = document.createElement('div');
        picker.className = 'sb-picker mt-2';
        picker.hidden = true;
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control';
        search.placeholder = 'Loading songs…';
        search.setAttribute('aria-label', 'Search songs to broadcast');
        const results = document.createElement('ul');
        results.className = 'sb-results list-group mt-2';
        results.style.cssText = 'max-height: 46vh; overflow-y: auto;';
        picker.appendChild(search);
        picker.appendChild(results);

        let debounce = null;
        search.addEventListener('input', () => {
            if (debounce) { clearTimeout(debounce); }
            debounce = setTimeout(() => this._renderResults(search.value), 120);
        });
        search.addEventListener('keydown', (e) => {
            /* Enter on a single result broadcasts it — fast keyboard-only flow. */
            if (e.key === 'Enter') {
                const first = results.querySelector('[data-song-id]');
                if (first) { e.preventDefault(); this.selectSong(first.getAttribute('data-song-id')); }
            }
        });

        this.mount.appendChild(now);
        this.mount.appendChild(controls);
        this.mount.appendChild(picker);

        this._els = { title, sections, picker, search, results };
    }

    _btn(variant, html, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + variant;
        b.innerHTML = html;
        b.addEventListener('click', onClick);
        return b;
    }

    _togglePicker() {
        const p = this._els.picker;
        if (!p) { return; }
        p.hidden = !p.hidden;
        if (!p.hidden) {
            this._renderResults(this._els.search.value);
            this._els.search.focus();
        }
    }
    _closePicker() { if (this._els.picker) { this._els.picker.hidden = true; } }

    _renderResults(query) {
        const list = this._els.results;
        if (!list) { return; }
        list.innerHTML = '';
        const q = (query || '').trim().toLowerCase();
        const matches = [];
        for (let i = 0; i < this.index.length && matches.length < SB_MAX_RESULTS; i++) {
            const s = this.index[i];
            if (q === '') { matches.push(s); continue; }
            const hay = (String(s.title || '') + ' ' + String(s.number || '') + ' '
                + String(s.songbook || '') + ' ' + String(s.songbookName || '')).toLowerCase();
            if (hay.indexOf(q) !== -1) { matches.push(s); }
        }
        if (matches.length === 0) {
            const li = document.createElement('li');
            li.className = 'list-group-item text-secondary small';
            li.textContent = this.index.length ? 'No matching songs.' : 'Song list unavailable.';
            list.appendChild(li);
            return;
        }
        matches.forEach((s) => {
            const li = document.createElement('button');
            li.type = 'button';
            li.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            li.setAttribute('data-song-id', String(s.id));
            const left = document.createElement('span');
            left.className = 'text-truncate';
            left.textContent = s.title || s.id;
            const right = document.createElement('span');
            right.className = 'badge bg-secondary-subtle text-secondary-emphasis ms-2 flex-shrink-0';
            right.textContent = (s.songbook || '') + (s.number ? (' ' + s.number) : '');
            li.appendChild(left);
            li.appendChild(right);
            li.addEventListener('click', () => this.selectSong(String(s.id)));
            list.appendChild(li);
        });
    }

    _renderNowPlaying() {
        if (!this._els.title) { return; }
        this._els.title.textContent = this.currentSong
            ? (this.currentSong.title || this.currentSong.id)
            : 'No song selected';
        const wrap = this._els.sections;
        wrap.innerHTML = '';
        const comps = this.sections || [];
        if (comps.length === 0) {
            const span = document.createElement('span');
            span.className = 'text-secondary small';
            span.textContent = this.currentSong ? 'Broadcasting (whole song)' : '';
            wrap.appendChild(span);
            return;
        }
        comps.forEach((c, i) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'btn btn-sm sb-chip ' + (i === this.currentIndex ? 'btn-amber-solid' : 'btn-outline-secondary');
            chip.textContent = this._sectionLabel(c, i);
            chip.addEventListener('click', () => this._goSection(i));
            wrap.appendChild(chip);
        });
    }

    _highlightSection() {
        const wrap = this._els.sections;
        if (!wrap) { return; }
        Array.prototype.forEach.call(wrap.querySelectorAll('.sb-chip'), (chip, i) => {
            chip.className = 'btn btn-sm sb-chip ' + (i === this.currentIndex ? 'btn-amber-solid' : 'btn-outline-secondary');
        });
    }

    /* Human label for a component — mirrors song.php's label logic exactly
       (refrain→Chorus alias; number 0/absent = no number) so the operator's
       chips read the same as the rendered song page. */
    _sectionLabel(c, i) {
        let type = String((c && c.type) || 'verse').toLowerCase();
        if (type === 'refrain') { type = 'chorus'; }
        let label = type.charAt(0).toUpperCase() + type.slice(1);
        const n = c && c.number;
        if (n !== undefined && n !== null && Number(n) > 0) { label += ' ' + Number(n); }
        return label || ('Section ' + (i + 1));
    }
}
