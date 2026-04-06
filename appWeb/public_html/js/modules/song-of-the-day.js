/**
 * iHymns — Song of the Day Module (#108)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Displays a featured "Song of the Day" card on the home page.
 * Uses a deterministic date-based algorithm so all users see the
 * same song on the same day. For key dates in the Christian calendar
 * (Christmas, Easter, Good Friday, etc.), a themed song is selected
 * by keyword matching instead.
 */
import { escapeHtml } from '../utils/html.js';

export class SongOfTheDay {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /**
         * Calendar-themed keyword sets.
         * Each entry: { keywords[], dateCheck(date) → boolean }
         */
        this.calendarThemes = [
            {
                name: 'Christmas',
                keywords: ['christmas', 'bethlehem', 'manger', 'born', 'nativity', 'holy night', 'silent night', 'noel', 'away in a', 'infant holy', 'unto us a child'],
                check: (d) => (d.getMonth() === 11 && d.getDate() >= 20 && d.getDate() <= 31) ||
                              (d.getMonth() === 0 && d.getDate() <= 6) /* Advent–Epiphany */
            },
            {
                name: 'Easter',
                keywords: ['risen', 'resurrection', 'he is risen', 'easter', 'alive', 'tomb', 'hallelujah', 'victory'],
                check: (d) => this.isEasterSunday(d) || this.isDaysFromEaster(d, 1) /* Easter Sunday + Monday */
            },
            {
                name: 'Good Friday',
                keywords: ['cross', 'calvary', 'crucified', 'blood', 'sacrifice', 'suffering', 'lamb of god', 'were you there'],
                check: (d) => this.isDaysFromEaster(d, -2) /* Good Friday */
            },
            {
                name: 'Palm Sunday',
                keywords: ['hosanna', 'palm', 'king', 'ride on', 'triumphant', 'jerusalem'],
                check: (d) => this.isDaysFromEaster(d, -7) /* Palm Sunday */
            },
            {
                name: 'Pentecost',
                keywords: ['spirit', 'holy spirit', 'pentecost', 'fire', 'wind', 'breath of god', 'come holy'],
                check: (d) => this.isDaysFromEaster(d, 49) /* Pentecost = Easter + 49 days */
            },
            {
                name: 'New Year',
                keywords: ['new', 'beginning', 'great is thy faithfulness', 'morning', 'mercy', 'grace', 'hope'],
                check: (d) => d.getMonth() === 0 && d.getDate() === 1
            },
            {
                name: 'Harvest',
                keywords: ['harvest', 'thanksgiving', 'thank', 'grateful', 'praise', 'bountiful', 'sow', 'reap', 'fields'],
                check: (d) => d.getMonth() === 9 && d.getDate() >= 1 && d.getDate() <= 7 /* Early October */
            },
        ];
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Render the Song of the Day card on the home page.
     * Requires songs data to be loaded in the search module.
     */
    renderHomeSection() {
        const container = document.getElementById('song-of-the-day');
        if (!container) return;

        const songs = this.app.search?.songsData;
        if (!songs || songs.length === 0) {
            container.innerHTML = '';
            return;
        }

        const today = new Date();
        const song = this.pickSong(songs, today);
        if (!song) return;

        const firstLine = this.getFirstLine(song);

        container.innerHTML = `
            <div class="card card-song-of-the-day mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3">
                        <div class="sotd-icon">
                            <i class="fa-solid fa-sun fa-lg" aria-hidden="true"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1 fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">
                                ${this.getThemeLabel(today)}
                            </h6>
                            <a href="/song/${escapeHtml(song.id)}"
                               class="text-decoration-none"
                               data-navigate="song"
                               data-song-id="${escapeHtml(song.id)}">
                                <h5 class="card-title mb-1">${escapeHtml(song.title)}</h5>
                            </a>
                            <p class="text-muted small mb-1">
                                <span class="badge bg-body-secondary" data-songbook="${escapeHtml(song.songbook || '')}">${escapeHtml(song.songbook || '')}</span>
                                #${song.number} &middot; ${escapeHtml(song.songbookName || '')}
                            </p>
                            ${firstLine ? `<p class="fst-italic text-muted small mb-0">&ldquo;${escapeHtml(firstLine)}&rdquo;</p>` : ''}
                        </div>
                    </div>
                </div>
            </div>`;
    }

    /**
     * Pick the song of the day.
     * First checks for a calendar-themed match, then falls back to deterministic pick.
     * @param {Array} songs All songs
     * @param {Date} date Today's date
     * @returns {object|null}
     */
    pickSong(songs, date) {
        /* Check calendar themes */
        for (const theme of this.calendarThemes) {
            if (theme.check(date)) {
                const match = this.findThemedSong(songs, theme.keywords, date);
                if (match) return match;
            }
        }

        /* Deterministic pseudo-random pick */
        return this.deterministicPick(songs, date);
    }

    /**
     * Find a themed song by keyword matching in titles.
     * Uses deterministic selection from matches so all users see the same one.
     * @param {Array} songs
     * @param {string[]} keywords
     * @param {Date} date
     * @returns {object|null}
     */
    findThemedSong(songs, keywords, date) {
        const matches = songs.filter(song => {
            const title = (song.title || '').toLowerCase();
            return keywords.some(kw => title.includes(kw));
        });

        if (matches.length === 0) return null;

        /* Deterministic pick from matches using date seed */
        const seed = this.dateSeed(date);
        return matches[seed % matches.length];
    }

    /**
     * Deterministic pseudo-random pick using date as seed.
     * @param {Array} songs
     * @param {Date} date
     * @returns {object}
     */
    deterministicPick(songs, date) {
        const seed = this.dateSeed(date);
        return songs[seed % songs.length];
    }

    /**
     * Generate a deterministic numeric seed from a date.
     * Same date always produces the same number.
     * @param {Date} date
     * @returns {number}
     */
    dateSeed(date) {
        const str = `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; /* Convert to 32-bit integer */
        }
        return Math.abs(hash);
    }

    /**
     * Get a label for the current theme or default.
     * @param {Date} date
     * @returns {string}
     */
    getThemeLabel(date) {
        for (const theme of this.calendarThemes) {
            if (theme.check(date)) return `${theme.name} Song of the Day`;
        }
        return 'Song of the Day';
    }

    /**
     * Get the first line of the first verse.
     * @param {object} song
     * @returns {string}
     */
    getFirstLine(song) {
        const components = song.components || [];
        for (const comp of components) {
            if (comp.lines && comp.lines.length > 0) {
                return comp.lines[0];
            }
        }
        return '';
    }

    /* =====================================================================
     * EASTER DATE CALCULATION (Anonymous Gregorian algorithm)
     * ===================================================================== */

    /**
     * Calculate Easter Sunday for a given year.
     * @param {number} year
     * @returns {Date}
     */
    easterDate(year) {
        const a = year % 19;
        const b = Math.floor(year / 100);
        const c = year % 100;
        const d = Math.floor(b / 4);
        const e = b % 4;
        const f = Math.floor((b + 8) / 25);
        const g = Math.floor((b - f + 1) / 3);
        const h = (19 * a + b - d - g + 15) % 30;
        const i = Math.floor(c / 4);
        const k = c % 4;
        const l = (32 + 2 * e + 2 * i - h - k) % 7;
        const m = Math.floor((a + 11 * h + 22 * l) / 451);
        const month = Math.floor((h + l - 7 * m + 114) / 31) - 1;
        const day = ((h + l - 7 * m + 114) % 31) + 1;
        return new Date(year, month, day);
    }

    /**
     * Check if a date is Easter Sunday.
     * @param {Date} d
     * @returns {boolean}
     */
    isEasterSunday(d) {
        const easter = this.easterDate(d.getFullYear());
        return d.getMonth() === easter.getMonth() && d.getDate() === easter.getDate();
    }

    /**
     * Check if a date is N days from Easter (negative = before, positive = after).
     * @param {Date} d
     * @param {number} offset Days from Easter
     * @returns {boolean}
     */
    isDaysFromEaster(d, offset) {
        const easter = this.easterDate(d.getFullYear());
        const target = new Date(easter);
        target.setDate(target.getDate() + offset);
        return d.getMonth() === target.getMonth() && d.getDate() === target.getDate();
    }

    /**
     * Escape HTML for safe insertion.
     * @param {string} str
     * @returns {string}
     */
}
