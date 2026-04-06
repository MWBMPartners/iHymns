/**
 * iHymns — Search History Module (#110)
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Stores recent search queries in localStorage and displays them as
 * quick-access chips below the search input. Clicking a chip re-runs
 * that search. Only stores queries that returned results.
 */

export class SearchHistory {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key */
        this.storageKey = 'ihymns_search_history';

        /** @type {number} Maximum stored queries */
        this.maxItems = 10;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Get stored search history.
     * @returns {string[]}
     */
    getHistory() {
        try {
            return JSON.parse(localStorage.getItem(this.storageKey)) || [];
        } catch {
            return [];
        }
    }

    /**
     * Record a successful search query.
     * @param {string} query The search query
     */
    record(query) {
        if (!query || query.length < 2) return;

        let history = this.getHistory();

        /* Remove duplicate if exists */
        history = history.filter(q => q.toLowerCase() !== query.toLowerCase());

        /* Add to front */
        history.unshift(query);

        /* Trim to max */
        if (history.length > this.maxItems) {
            history = history.slice(0, this.maxItems);
        }

        localStorage.setItem(this.storageKey, JSON.stringify(history));
    }

    /**
     * Clear all search history.
     */
    clear() {
        localStorage.removeItem(this.storageKey);
    }

    /**
     * Remove a single query from history.
     * @param {string} query
     */
    remove(query) {
        let history = this.getHistory();
        history = history.filter(q => q !== query);
        localStorage.setItem(this.storageKey, JSON.stringify(history));
    }

    /**
     * Render search history chips on the search page.
     * @param {HTMLElement} container The container to render into
     * @param {function} onSelect Callback when a chip is clicked (receives query)
     */
    renderChips(container, onSelect) {
        if (!container) return;

        const history = this.getHistory();
        if (history.length === 0) {
            container.innerHTML = '';
            return;
        }

        let html = '<div class="search-history-chips">';
        html += '<div class="d-flex align-items-center justify-content-between mb-2">';
        html += '<small class="text-muted fw-semibold">Recent searches</small>';
        html += '<button type="button" class="btn btn-link btn-sm text-muted p-0 search-history-clear">Clear all</button>';
        html += '</div>';
        html += '<div class="d-flex flex-wrap gap-1">';

        history.forEach(query => {
            html += `<span class="badge bg-body-secondary search-history-chip" role="button" tabindex="0" aria-label="Search for ${this.escapeHtml(query)}">
                <i class="fa-solid fa-clock-rotate-left me-1 opacity-50" aria-hidden="true"></i>
                ${this.escapeHtml(query)}
                <button type="button" class="btn-close btn-close-sm ms-1 search-history-remove" data-query="${this.escapeHtml(query)}" aria-label="Remove"></button>
            </span>`;
        });

        html += '</div></div>';
        container.innerHTML = html;

        /* Bind chip clicks */
        container.querySelectorAll('.search-history-chip').forEach(chip => {
            chip.addEventListener('click', (e) => {
                if (e.target.closest('.search-history-remove')) return;
                const text = chip.textContent.trim();
                /* Get the actual query from the chip (skip the icon text) */
                const query = chip.textContent.replace(/\s*×?\s*$/, '').trim();
                if (onSelect) onSelect(query);
            });

            chip.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    chip.click();
                }
            });
        });

        /* Bind remove buttons */
        container.querySelectorAll('.search-history-remove').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const query = btn.dataset.query;
                this.remove(query);
                this.renderChips(container, onSelect);
            });
        });

        /* Bind clear all */
        container.querySelector('.search-history-clear')?.addEventListener('click', () => {
            this.clear();
            this.renderChips(container, onSelect);
        });
    }

    /**
     * Escape HTML for safe insertion.
     * @param {string} str
     * @returns {string}
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
