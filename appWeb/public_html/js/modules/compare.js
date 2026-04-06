/**
 * iHymns — Song Comparison Module (#102)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Allows side-by-side comparison of two songs. Users pick a second
 * song via a search modal; both songs are displayed in a split view.
 * Responsive: side-by-side on desktop, tabbed on mobile.
 */
import { escapeHtml } from '../utils/html.js';

export class Compare {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Initialise the Compare button on a song page.
     * Called by router after song page loads.
     */
    initSongPage() {
        const btn = document.querySelector('.btn-compare');
        if (!btn) return;

        btn.addEventListener('click', () => {
            const article = btn.closest('.page-song');
            const songId = article?.dataset.songId || '';
            if (songId) this.showPickerModal(songId);
        });
    }

    /**
     * Show a modal to search for the second song to compare.
     * @param {string} firstSongId The currently viewed song ID
     */
    showPickerModal(firstSongId) {
        document.getElementById('compare-picker-modal')?.remove();

        const modal = document.createElement('div');
        modal.id = 'compare-picker-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-label', 'Pick a song to compare');

        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fa-solid fa-columns me-2" aria-hidden="true"></i>
                            Compare With...
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="search" class="form-control mb-3" id="compare-search-input"
                               placeholder="Search by title or number..." autocomplete="off" autofocus>
                        <div id="compare-search-results" class="list-group" style="max-height:300px;overflow-y:auto">
                            <p class="text-muted text-center py-3 small">Type to search for a song</p>
                        </div>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        let debounce = null;
        const input = modal.querySelector('#compare-search-input');
        const results = modal.querySelector('#compare-search-results');

        input?.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(async () => {
                const q = input.value.trim();
                if (q.length < 2) return;

                const url = new URL(this.app.config.apiUrl, window.location.origin);
                url.searchParams.set('action', 'search');
                url.searchParams.set('q', q);

                try {
                    const resp = await fetch(url);
                    const data = await resp.json();
                    const songs = (data.results || []).filter(s => s.id !== firstSongId).slice(0, 10);

                    if (songs.length === 0) {
                        results.innerHTML = '<p class="text-muted text-center py-3 small">No results</p>';
                        return;
                    }

                    results.innerHTML = songs.map(s => `
                        <button type="button" class="list-group-item list-group-item-action compare-pick"
                                data-song-id="${escapeHtml(s.id)}">
                            <span class="song-number-badge me-2" data-songbook="${escapeHtml(s.songbook || '')}">${s.number}</span>
                            <strong>${escapeHtml(s.title)}</strong>
                            <small class="text-muted ms-1">${escapeHtml(s.songbookName || '')}</small>
                        </button>`).join('');

                    results.querySelectorAll('.compare-pick').forEach(btn => {
                        btn.addEventListener('click', () => {
                            bsModal.hide();
                            this.loadComparison(firstSongId, btn.dataset.songId);
                        });
                    });
                } catch {
                    results.innerHTML = '<p class="text-danger text-center py-3 small">Search failed</p>';
                }
            }, 300);
        });

        modal.addEventListener('hidden.bs.modal', () => modal.remove());
    }

    /**
     * Load and display two songs side by side.
     * @param {string} songIdA First song ID
     * @param {string} songIdB Second song ID
     */
    async loadComparison(songIdA, songIdB) {
        /* Fetch both songs in parallel */
        const apiBase = this.app.config.apiUrl;
        const [respA, respB] = await Promise.all([
            fetch(`${apiBase}?page=song&id=${encodeURIComponent(songIdA)}`),
            fetch(`${apiBase}?page=song&id=${encodeURIComponent(songIdB)}`),
        ]);

        if (!respA.ok || !respB.ok) {
            this.app.showToast('Failed to load songs for comparison', 'danger', 3000);
            return;
        }

        const [htmlA, htmlB] = await Promise.all([respA.text(), respB.text()]);

        this.showComparisonView(htmlA, htmlB);
    }

    /**
     * Render the comparison overlay.
     * @param {string} htmlA First song HTML
     * @param {string} htmlB Second song HTML
     */
    showComparisonView(htmlA, htmlB) {
        document.getElementById('compare-overlay')?.remove();

        const overlay = document.createElement('div');
        overlay.id = 'compare-overlay';
        overlay.className = 'compare-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-label', 'Song comparison');

        overlay.innerHTML = `
            <div class="compare-header">
                <h2 class="h6 mb-0">
                    <i class="fa-solid fa-columns me-2" aria-hidden="true"></i>
                    Song Comparison
                </h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="compare-close-btn">
                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Close
                </button>
            </div>

            <!-- Desktop: side-by-side -->
            <div class="compare-panels d-none d-md-flex">
                <div class="compare-panel" id="compare-panel-a">${htmlA}</div>
                <div class="compare-divider"></div>
                <div class="compare-panel" id="compare-panel-b">${htmlB}</div>
            </div>

            <!-- Mobile: tabbed -->
            <div class="d-md-none">
                <ul class="nav nav-pills nav-fill mb-3 mx-2" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#compare-tab-a"
                                type="button" role="tab" aria-selected="true">Song A</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#compare-tab-b"
                                type="button" role="tab" aria-selected="false">Song B</button>
                    </li>
                </ul>
                <div class="tab-content px-2">
                    <div class="tab-pane fade show active" id="compare-tab-a" role="tabpanel">${htmlA}</div>
                    <div class="tab-pane fade" id="compare-tab-b" role="tabpanel">${htmlB}</div>
                </div>
            </div>`;

        document.body.appendChild(overlay);
        document.body.classList.add('compare-active');

        /* Close button */
        overlay.querySelector('#compare-close-btn')?.addEventListener('click', () => this.closeComparison());

        /* Escape to close */
        this._escHandler = (e) => {
            if (e.key === 'Escape') this.closeComparison();
        };
        document.addEventListener('keydown', this._escHandler);
    }

    /** Close the comparison overlay */
    closeComparison() {
        document.body.classList.remove('compare-active');
        document.getElementById('compare-overlay')?.remove();
        if (this._escHandler) {
            document.removeEventListener('keydown', this._escHandler);
            this._escHandler = null;
        }
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
}
