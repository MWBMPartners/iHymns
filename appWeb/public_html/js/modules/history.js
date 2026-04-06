/**
 * iHymns — Recently Viewed History Module (#92)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Tracks recently viewed songs in localStorage and displays them
 * on the home page for quick access. Stores the last 20 songs,
 * prevents duplicates (re-viewing moves a song to the top), and
 * provides a clear option.
 *
 * STORAGE FORMAT (localStorage key: 'ihymns_history'):
 *   [{ id: "CP-0001", title: "...", songbook: "CP", number: 1, viewedAt: "ISO" }, ...]
 *   Ordered by most recent first.
 */
import { escapeHtml } from '../utils/html.js';
import { STORAGE_HISTORY } from '../constants.js';

export class History {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key */
        this.storageKey = STORAGE_HISTORY;

        /** @type {number} Maximum number of entries to store */
        this.maxEntries = 20;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Record a song view. Adds the song to the top of history,
     * removing any existing duplicate entry first.
     *
     * @param {string} songId   Song ID (e.g., 'CP-0001')
     * @param {string} title    Song title
     * @param {string} songbook Songbook abbreviation
     * @param {number} number   Song number
     */
    recordView(songId, title, songbook, number) {
        let history = this.getAll();

        /* Remove duplicate if already in history */
        history = history.filter(h => h.id !== songId);

        /* Add to the top */
        history.unshift({
            id: songId,
            title: title || '',
            songbook: songbook || '',
            number: number || 0,
            viewedAt: new Date().toISOString(),
        });

        /* Trim to max entries */
        if (history.length > this.maxEntries) {
            history = history.slice(0, this.maxEntries);
        }

        this.saveAll(history);
    }

    /**
     * Get all history entries, ordered by most recent first.
     *
     * @returns {Array} History entries
     */
    getAll() {
        try {
            return JSON.parse(localStorage.getItem(this.storageKey)) || [];
        } catch {
            return [];
        }
    }

    /**
     * Save the history array to localStorage.
     *
     * @param {Array} history
     */
    saveAll(history) {
        localStorage.setItem(this.storageKey, JSON.stringify(history));
        this.app.syncStorage(this.storageKey);
    }

    /** Clear all history */
    clearAll() {
        localStorage.removeItem(this.storageKey);
    }

    /**
     * Render the recently viewed section on the home page.
     * Called by the router after the home page loads.
     * Injects HTML into the home page below the songbook cards.
     */
    renderHomeSection() {
        const history = this.getAll();
        if (history.length === 0) return;

        /* Find the insertion point on the home page */
        const homeSection = document.querySelector('.page-home');
        if (!homeSection) return;

        /* Remove existing recent section if present (prevents duplicates) */
        document.getElementById('recent-songs-section')?.remove();

        /* Build the section HTML */
        const section = document.createElement('div');
        section.id = 'recent-songs-section';
        section.className = 'mb-4';
        section.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-clock-rotate-left me-2" aria-hidden="true"></i>
                    Recently Viewed
                </h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="clear-history-btn"
                        aria-label="Clear recent history">
                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>
                    Clear
                </button>
            </div>
            <div class="list-group" id="recent-songs-list">
                ${history.slice(0, 10).map(h => `
                    <a href="/song/${escapeHtml(h.id)}"
                       class="list-group-item list-group-item-action song-list-item"
                       data-navigate="song"
                       data-song-id="${escapeHtml(h.id)}">
                        <span class="song-number-badge" data-songbook="${escapeHtml(h.songbook)}">${h.number || '?'}</span>
                        <div class="song-info flex-grow-1">
                            <span class="song-title">${escapeHtml(h.title)}</span>
                            <small class="text-muted d-block">${escapeHtml(h.songbook)}</small>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                `).join('')}
            </div>`;

        homeSection.appendChild(section);

        /* Bind clear button */
        document.getElementById('clear-history-btn')?.addEventListener('click', () => {
            this.clearAll();
            section.remove();
            this.app.showToast('History cleared', 'info', 2000);
        });
    }

    /**
     * Escape HTML to prevent XSS.
     *
     * @param {string} str
     * @returns {string}
     */
}
