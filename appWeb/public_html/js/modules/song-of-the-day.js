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
import { EVT_LANGUAGE_FILTER_CHANGED } from '../constants.js';

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
     * Initialise — wire a single EVT_LANGUAGE_FILTER_CHANGED listener
     * (#855) so the SoTD card re-fetches when the user toggles the
     * "Show languages" filter. Bound on document (not the container) so it
     * survives the SPA's home-section re-renders without rebinding.
     *
     * #1581 — M4 correction: this listener and songbook-language-filter.js's
     * dispatch were NEVER the mismatched pair — both already used the same
     * capital-H 'iHymns:...' spelling and matched each other correctly (by
     * luck of matching typos, not by design). THE actual bug lived in
     * settings-language-filter.js, which dispatched a DIFFERENT, lowercase
     * 'ihymns:...' spelling that this listener (bound to the capital-H
     * name) could never match — DOM event types are case-sensitive, so
     * toggling the language filter from Settings silently never refreshed
     * this card. All three modules now import the same
     * EVT_LANGUAGE_FILTER_CHANGED constant (the lowercase spelling, since
     * that's what js/constants.js standardised on), so no dispatch/listen
     * site can drift from any other again. (See
     * tests/test-event-names.js, which bans a raw quoted event-name
     * literal outside constants.js — the '...' shorthand above is
     * deliberate, not the full literal.)
     */
    init() {
        if (this._langFilterBound) return;
        this._langFilterBound = true;
        document.addEventListener(EVT_LANGUAGE_FILTER_CHANGED, () => {
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
            url.searchParams.set('hemisphere', this._hemisphere());
            const country = this._country();
            if (country) url.searchParams.set('country', country);
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

    /**
     * The user's hemisphere ('n' default | 's'), derived from their IANA timezone so
     * the southern hemisphere gets Harvest in its own autumn (#1374, broadened #1376).
     * Privacy-friendly + cache-safe — no GeoIP, mirrors how the local date is already
     * client-supplied. Two passes: (1) an explicit southern allow-list (broadened to
     * the remaining populous southern zones); (2) a best-effort continent fallback for
     * the wholly-southern regions (Australia/Antarctica/Pacific island groups) so a
     * future IANA zone we didn't list still resolves. Anything unmatched (incl. errors,
     * and the equator-straddling Africa/America/Asia/Europe regions where a blanket
     * guess would mis-classify the northern majority) → 'n', the prior behaviour.
     *
     * @returns {'n'|'s'}
     */
    _hemisphere() {
        try {
            const tz = (Intl.DateTimeFormat().resolvedOptions().timeZone) || '';
            /* (1) Explicit southern-hemisphere zones with distinct autumn timing.
                   #1376 additions over #1374: more of Australia/NZ + Brazil + the
                   Southern Cone + southern Africa + more Pacific/Indian-Ocean groups. */
            const SOUTH = /^(Australia\/|Antarctica\/|Atlantic\/Stanley|Pacific\/(Auckland|Chatham|Fiji|Noumea|Port_Moresby|Bougainville|Guadalcanal|Tongatapu|Norfolk|Easter|Apia|Pago_Pago|Rarotonga|Tahiti|Marquesas|Gambier|Niue|Fakaofo|Tarawa|Efate|Kosrae|Pohnpei|Nauru)|Indian\/(Mauritius|Reunion|Antananarivo|Mahe|Cocos|Christmas|Kerguelen)|Africa\/(Johannesburg|Maseru|Mbabane|Windhoek|Gaborone|Harare|Lusaka|Blantyre|Maputo|Luanda|Antananarivo|Kinshasa|Lubumbashi|Brazzaville|Libreville|Bujumbura|Kigali)|America\/(Argentina\/|Sao_Paulo|Bahia|Fortaleza|Recife|Maceio|Araguaina|Cuiaba|Campo_Grande|Belem|Santarem|Manaus|Porto_Velho|Boa_Vista|Rio_Branco|Santiago|Punta_Arenas|Montevideo|Asuncion|La_Paz|Lima|Guayaquil))/;
            if (SOUTH.test(tz)) return 's';
            /* (2) Best-effort continent fallback for the wholly-southern regions —
                   Australia + Antarctica are entirely southern; the listed Pacific
                   prefixes are island groups that all sit below the equator. Anything
                   else stays 'n' (the safe default), since the other continent
                   prefixes straddle the equator. */
            if (/^(Australia\/|Antarctica\/)/.test(tz)) return 's';
            return 'n';
        } catch (_e) {
            return 'n';
        }
    }

    /**
     * The user's country as an ISO-3166-1 alpha-2 code (#1376), derived from the
     * region subtag of navigator.language / navigator.languages (e.g. 'en-US' → 'US',
     * 'en-GB' → 'GB', 'fr-CA' → 'CA'). Used server-side to theme NATIONAL civic days
     * (US/Canadian Thanksgiving, US Independence Day). Privacy-friendly + cache-safe —
     * no GeoIP, same shape as the existing client-supplied date/hemisphere signals.
     * Returns '' when no region subtag is present (e.g. a bare 'en'), which the server
     * treats as "no national theme" — an exact no-op vs today.
     *
     * @returns {string} Two upper-case letters, or '' when undeterminable.
     */
    _country() {
        try {
            /* Prefer the ordered preference list; fall back to the single value. */
            const tags = (Array.isArray(navigator.languages) && navigator.languages.length)
                ? navigator.languages
                : (navigator.language ? [navigator.language] : []);
            for (const tag of tags) {
                /* A BCP-47 tag's region subtag is the 2-letter group after the
                   primary language, e.g. en-US, fr-CA, es-419 (419 is a UN M.49
                   region, NOT alpha-2 — the {2} guard excludes it). */
                const m = /^[A-Za-z]{2,3}-(?:[A-Za-z]{4}-)?([A-Za-z]{2})\b/.exec(String(tag || ''));
                if (m && m[1]) return m[1].toUpperCase();
            }
            return '';
        } catch (_e) {
            return '';
        }
    }
}
