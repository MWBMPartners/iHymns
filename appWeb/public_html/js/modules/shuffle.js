/**
 * iHymns — Shuffle Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides random song selection. Users can pick a random song from
 * the entire collection or from a specific songbook. Uses the PHP
 * API's random endpoint for server-side randomness.
 */

export class Shuffle {
    constructor(app) {
        this.app = app;
        /** @type {boolean} Prevents double-clicks during loading */
        this.isLoading = false;
    }

    /**
     * Initialise — bind the header shuffle button and populate songbook list.
     */
    init() {
        /* Header shuffle button opens the modal */
        const btn = document.getElementById('header-shuffle-btn');
        if (btn) {
            btn.addEventListener('click', () => this.openModal());
        }

        /* "All songbooks" shuffle button in modal */
        document.querySelector('[data-shuffle-book=""]')?.addEventListener('click', () => {
            this.shuffleFromBook(null);
        });
    }

    /**
     * Open the shuffle modal and populate songbook options.
     */
    async openModal() {
        const modal = document.getElementById('shuffle-modal');
        if (!modal) return;

        /* Populate songbook buttons if not yet done */
        const list = document.getElementById('shuffle-songbook-list');
        if (list && list.children.length === 0) {
            try {
                const url = new URL(this.app.config.apiUrl, window.location.origin);
                url.searchParams.set('action', 'songbooks');
                const response = await fetch(url);
                const data = await response.json();

                if (data.songbooks) {
                    list.innerHTML = data.songbooks
                        .filter(b => b.songCount > 0)
                        .map(b => `
                            <button type="button"
                                    class="btn btn-shuffle-option w-100"
                                    data-shuffle-book="${this.escapeAttr(b.id)}"
                                    aria-label="Random song from ${this.escapeAttr(b.name)}">
                                <i class="fa-solid fa-book me-2" aria-hidden="true"></i>
                                ${this.escapeHtml(b.name)}
                                <span class="badge bg-body-secondary ms-auto">${b.songCount}</span>
                            </button>
                        `).join('');

                    /* Bind click events */
                    list.querySelectorAll('[data-shuffle-book]').forEach(btn => {
                        btn.addEventListener('click', () => {
                            this.shuffleFromBook(btn.dataset.shuffleBook);
                        });
                    });
                }
            } catch (error) {
                console.error('[Shuffle] Failed to load songbooks:', error);
            }
        }

        /* Show the Bootstrap modal */
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }

    /**
     * Pick a random song from a specific songbook (or all).
     *
     * @param {string|null} songbookId Songbook abbreviation or null for all
     */
    async shuffleFromBook(songbookId) {
        if (this.isLoading) return;
        this.isLoading = true;

        try {
            /* Close the modal */
            const modal = document.getElementById('shuffle-modal');
            if (modal) {
                bootstrap.Modal.getInstance(modal)?.hide();
            }

            /* Fetch a random song from the API */
            const url = new URL(this.app.config.apiUrl, window.location.origin);
            url.searchParams.set('action', 'random');
            if (songbookId) {
                url.searchParams.set('songbook', songbookId);
            }

            const response = await fetch(url);
            const data = await response.json();

            if (data.song?.id) {
                /* Navigate to the random song */
                this.app.router.navigate('/song/' + data.song.id);
            } else {
                this.app.showToast('No songs available', 'warning');
            }
        } catch (error) {
            console.error('[Shuffle] Error:', error);
            this.app.showToast('Failed to pick a random song', 'danger');
        } finally {
            this.isLoading = false;
        }
    }

    escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    escapeAttr(str) {
        return (str || '').replace(/[&"'<>]/g, c =>
            ({ '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' })[c]);
    }
}
