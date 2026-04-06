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
         * Calendar-themed keyword sets (#163).
         * Each entry: { name, keywords[], check(date) → boolean }
         * Order matters — first matching theme wins. More specific dates
         * (e.g. Good Friday) should come before broader ranges (e.g. Lent).
         */
        this.calendarThemes = [
            /* ---- CHRISTMAS SEASON ---- */
            {
                name: 'Advent',
                keywords: ['advent', 'come thou long-expected', 'o come o come emmanuel', 'prepare', 'watchman', 'waiting', 'prophecy', 'promised', 'messiah', 'herald', 'lo he comes'],
                check: (d) => this.isAdvent(d) && !(d.getMonth() === 11 && d.getDate() >= 24)
            },
            {
                name: 'Christmas',
                keywords: ['christmas', 'bethlehem', 'manger', 'born', 'nativity', 'holy night', 'silent night', 'noel', 'away in a', 'infant holy', 'unto us a child', 'hark the herald', 'joy to the world', 'o come all ye faithful', 'incarnate', 'god with us', 'emmanuel', 'swaddling'],
                check: (d) => (d.getMonth() === 11 && d.getDate() >= 24 && d.getDate() <= 31) ||
                              (d.getMonth() === 0 && d.getDate() <= 6) /* Christmas Eve–Epiphany */
            },
            {
                name: 'Epiphany',
                keywords: ['wise men', 'magi', 'star', 'gold', 'frankincense', 'myrrh', 'kings', 'manifest', 'epiphany', 'light of the world', 'nations'],
                check: (d) => d.getMonth() === 0 && d.getDate() >= 6 && d.getDate() <= 12
            },

            /* ---- EASTER SEASON (specific dates first) ---- */
            {
                name: 'Palm Sunday',
                keywords: ['hosanna', 'palm', 'ride on', 'triumphant', 'jerusalem', 'blessed is he', 'king is coming', 'open the gates', 'majesty'],
                check: (d) => this.isDaysFromEaster(d, -7)
            },
            {
                name: 'Holy Week',
                keywords: ['gethsemane', 'agony', 'betrayed', 'upper room', 'bread and wine', 'communion', 'last supper', 'wash', 'servant', 'cross', 'calvary', 'suffering', 'lamb', 'sacrifice'],
                check: (d) => this.isEasterRange(d, -6, -3) /* Monday–Thursday of Holy Week */
            },
            {
                name: 'Good Friday',
                keywords: ['cross', 'calvary', 'crucified', 'blood', 'sacrifice', 'suffering', 'lamb of god', 'were you there', 'old rugged cross', 'when i survey', 'atonement', 'wounded', 'pierced', 'forgive them', 'it is finished', 'nailed'],
                check: (d) => this.isDaysFromEaster(d, -2) || this.isDaysFromEaster(d, -1) /* Good Friday + Saturday */
            },
            {
                name: 'Easter',
                keywords: ['risen', 'resurrection', 'he is risen', 'easter', 'alive', 'tomb', 'hallelujah', 'victory', 'death where is', 'christ arose', 'up from the grave', 'morning', 'rolled away', 'conquered', 'lives'],
                check: (d) => this.isEasterRange(d, 0, 7) /* Easter Sunday + week */
            },
            {
                name: 'Ascension',
                keywords: ['ascend', 'ascension', 'throne', 'exalted', 'crowned', 'reign', 'majesty', 'right hand', 'lifted up', 'king of kings', 'lord of lords', 'crown him'],
                check: (d) => this.isEasterRange(d, 38, 40) /* Ascension Thursday ± 1 */
            },
            {
                name: 'Pentecost',
                keywords: ['spirit', 'holy spirit', 'pentecost', 'fire', 'wind', 'breath of god', 'come holy', 'fill me', 'power from on high', 'tongue', 'comforter', 'counselor', 'advocate'],
                check: (d) => this.isEasterRange(d, 49, 50) /* Pentecost Sunday + Monday */
            },

            /* ---- LENT (broad range, after specific Holy Week dates) ---- */
            {
                name: 'Lent',
                keywords: ['repent', 'repentance', 'forgive', 'mercy', 'humble', 'cleanse', 'search me', 'create in me', 'broken', 'contrite', 'ashes', 'fast', 'surrender', 'wilderness', 'temptation', 'refine'],
                check: (d) => this.isEasterRange(d, -46, -8) /* Ash Wednesday to week before Palm Sunday */
            },

            /* ---- OTHER SEASONS ---- */
            {
                name: 'New Year',
                keywords: ['new', 'beginning', 'great is thy faithfulness', 'morning', 'mercy', 'grace', 'hope', 'new year', 'fresh', 'renew', 'guide me'],
                check: (d) => d.getMonth() === 0 && d.getDate() >= 1 && d.getDate() <= 3
            },
            {
                name: 'Reformation',
                keywords: ['mighty fortress', 'reformation', 'faith alone', 'grace alone', 'scripture', 'word of god', 'stand', 'truth', 'anchor', 'foundation', 'rock'],
                check: (d) => d.getMonth() === 9 && d.getDate() >= 29 && d.getDate() <= 31 /* Oct 29–31 */
            },
            {
                name: 'Harvest',
                keywords: ['harvest', 'thanksgiving', 'thank', 'grateful', 'bountiful', 'sow', 'reap', 'fields', 'provision', 'all good gifts', 'come ye thankful', 'fruit'],
                check: (d) => d.getMonth() === 9 && d.getDate() >= 1 && d.getDate() <= 14 /* Early–mid October */
            },
            {
                name: 'Remembrance',
                keywords: ['peace', 'rest', 'eternal', 'remember', 'honour', 'fallen', 'comfort', 'shelter', 'refuge', 'everlasting arms', 'safe'],
                check: (d) => d.getMonth() === 10 && d.getDate() >= 9 && d.getDate() <= 11 /* Remembrance (Nov 9–11) */
            },
            {
                name: 'Trinity Sunday',
                keywords: ['trinity', 'three in one', 'triune', 'father son', 'holy holy holy', 'godhead', 'three persons'],
                check: (d) => this.isDaysFromEaster(d, 56) /* Trinity Sunday = Pentecost + 7 */
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
     * Find a themed song by keyword matching in titles AND lyrics (#163).
     * Title matches are weighted higher than lyrics-only matches.
     * Uses deterministic selection so all users see the same song.
     * @param {Array} songs
     * @param {string[]} keywords
     * @param {Date} date
     * @returns {object|null}
     */
    findThemedSong(songs, keywords, date) {
        const titleMatches = [];
        const lyricsMatches = [];

        for (const song of songs) {
            const title = (song.title || '').toLowerCase();
            const titleHit = keywords.some(kw => title.includes(kw));

            if (titleHit) {
                titleMatches.push(song);
                continue;
            }

            /* Search first-verse lyrics if title didn't match */
            const lyrics = this.getSongLyrics(song).toLowerCase();
            if (lyrics && keywords.some(kw => lyrics.includes(kw))) {
                lyricsMatches.push(song);
            }
        }

        /* Prefer title matches; fall back to lyrics matches */
        const pool = titleMatches.length > 0 ? titleMatches : lyricsMatches;
        if (pool.length === 0) return null;

        /* Deterministic pick from matches using date seed */
        const seed = this.dateSeed(date);
        return pool[seed % pool.length];
    }

    /**
     * Extract searchable lyrics text from a song's components.
     * Returns the first 2 components' lines joined as a single string.
     * @param {object} song
     * @returns {string}
     */
    getSongLyrics(song) {
        const components = song.components || [];
        const lines = [];
        for (let i = 0; i < Math.min(components.length, 2); i++) {
            const comp = components[i];
            if (comp.lines && comp.lines.length > 0) {
                lines.push(...comp.lines);
            } else if (comp.lyrics) {
                lines.push(comp.lyrics);
            }
        }
        return lines.join(' ');
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
     * Check if a date falls within a range of days from Easter (#163).
     * @param {Date} d
     * @param {number} startOffset Start of range (days from Easter, inclusive)
     * @param {number} endOffset   End of range (days from Easter, inclusive)
     * @returns {boolean}
     */
    isEasterRange(d, startOffset, endOffset) {
        const easter = this.easterDate(d.getFullYear());
        const easterMs = easter.getTime();
        const dayMs = 86400000;
        const dateMs = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
        return dateMs >= easterMs + startOffset * dayMs &&
               dateMs <= easterMs + endOffset * dayMs;
    }

    /**
     * Check if a date falls within the Advent season (#163).
     * Advent starts 4 Sundays before Christmas (Nov 27 – Dec 3)
     * and runs until December 23.
     * @param {Date} d
     * @returns {boolean}
     */
    isAdvent(d) {
        const year = d.getFullYear();
        /* Find the 4th Sunday before Christmas Day (Dec 25). */
        /* Christmas Day's day-of-week: 0=Sun..6=Sat */
        const xmas = new Date(year, 11, 25);
        const xmasDay = xmas.getDay();
        /* Days back to the previous Sunday (or Christmas itself if Sunday) */
        const daysBack = xmasDay === 0 ? 7 : xmasDay;
        /* 4th Sunday before Christmas */
        const adventStart = new Date(year, 11, 25 - daysBack - 21);
        const adventEnd = new Date(year, 11, 23);
        const dateOnly = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        return dateOnly >= adventStart && dateOnly <= adventEnd;
    }
}
