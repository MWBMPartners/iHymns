/**
 * iHymns — Song of the Day Module (#108)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Displays a featured "Song of the Day" card on the home page.
 *
 * ARCHITECTURE (#1015 — DB-direct rewrite):
 *   The deterministic date-based selection and the Christian-calendar
 *   theming now live SERVER-SIDE (includes/SongOfTheDay.php) and are
 *   served by the live `?action=song_of_the_day` endpoint. This module
 *   only fetches that endpoint and renders the card — it no longer holds
 *   the song corpus, so there is nothing to go stale.
 *
 *   The client still passes two things the server can't know on its own:
 *     - `lang`: the active "Show languages" filter (localStorage), so a
 *       non-English user doesn't get a daily song they can't read.
 *     - `date`: the browser's LOCAL date, so liturgical themes align to
 *       the user's own calendar (exactly as the old client did). The
 *       server falls back to its own date if it's missing/malformed.
 */
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { toTitleCase } from '../utils/text.js';

export class SongOfTheDay {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number} Monotonic token so a slow fetch can't overwrite
         *  the card after a newer language-filter change. */
        this._renderSeq = 0;
    }

    /**
     * Initialise — wire a single iHymns:language-filter-changed listener
     * (#855) so the SoTD card re-fetches when the user toggles the
     * "Show languages" filter. Bound on document (not the container) so it
     * survives the SPA's home-section re-renders without rebinding.
     */
    init() {
        if (this._langFilterBound) return;
        this._langFilterBound = true;
        document.addEventListener('iHymns:language-filter-changed', () => {
            this.renderHomeSection();
        });
    }

    /**
     * Read the active language-filter subtag set the same way the
     * songbook-language-filter module does — single source of truth is
     * localStorage['songbook-language-filter'] (a JSON array of lowercase
     * primary subtags). Returns [] when "All" is selected (no filter),
     * and likewise on parse errors.
     */
    getActiveSubtags() {
        try {
            const raw = localStorage.getItem('songbook-language-filter');
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed.filter(s => typeof s === 'string' && /^[a-z]{2,3}$/.test(s));
        } catch (_e) {
            return [];
        }
    }

    /**
     * Fetch today's Song of the Day from the live endpoint and render the
     * card. No-ops gracefully (empty card) when the home section isn't
     * present or the request fails.
     */
    async renderHomeSection() {
        const container = document.getElementById('song-of-the-day');
        if (!container) return;

        const seq = ++this._renderSeq;

        let data;
        try {
            const url = new URL(this.app.config.apiUrl, window.location.origin);
            url.searchParams.set('action', 'song_of_the_day');
            url.searchParams.set('date', this._localDateStr());
            const subtags = this.getActiveSubtags();
            if (subtags.length) url.searchParams.set('lang', subtags.join(','));

            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error(`SoTD API: HTTP ${response.status}`);
            data = await response.json();
        } catch (error) {
            /* Offline / server error — leave the card empty rather than
               showing a broken state on the home page. */
            if (seq === this._renderSeq) container.innerHTML = '';
            return;
        }

        /* A newer filter change superseded this fetch — discard. */
        if (seq !== this._renderSeq) return;

        const song = data && data.song;
        if (!song || !song.id) {
            container.innerHTML = '';
            return;
        }

        const themeLabel = data.themeLabel || 'Song of the Day';
        const firstLine = data.firstLine || '';

        container.innerHTML = `
            <div class="card card-song-of-the-day mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3">
                        <div class="sotd-icon">
                            <i class="fa-solid fa-sun fa-lg" aria-hidden="true"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1 fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">
                                ${escapeHtml(themeLabel)}
                            </h6>
                            <a href="/song/${escapeHtml(song.id)}"
                               class="text-decoration-none"
                               data-navigate="song"
                               data-song-id="${escapeHtml(song.id)}">
                                <h5 class="card-title mb-1">${escapeHtml(toTitleCase(song.title))}${verifiedBadge(song)}</h5>
                            </a>
                            <p class="text-muted small mb-1">
                                <span class="badge bg-body-secondary" data-songbook="${escapeHtml(song.songbook || '')}">${escapeHtml(song.songbook || '')}</span>
                                ${song.number ? '#' + escapeHtml(String(song.number)) + ' &middot; ' : ''}${escapeHtml(song.songbookName || '')}
                            </p>
                            ${firstLine ? `<p class="fst-italic text-muted small mb-0">&ldquo;${escapeHtml(firstLine)}&rdquo;</p>` : ''}
                        </div>
                    </div>
                </div>
            </div>`;
    }

    /**
     * The browser's LOCAL date as YYYY-MM-DD, so the server themes the day
     * against the user's own calendar (mirrors the old client behaviour).
     *
     * @returns {string}
     */
    _localDateStr() {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }
}
