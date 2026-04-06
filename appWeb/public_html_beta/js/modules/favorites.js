/**
 * iHymns — Favourites Module
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Manages the user's favourite songs. Stores IDs and metadata in
 * localStorage. Provides toggle, list, and clear functionality.
 */

export class Favorites {
    constructor(app) {
        this.app = app;
        /** @type {string} localStorage key for favourites */
        this.storageKey = 'ihymns_favorites';
    }

    /** Initialise — nothing to do on startup */
    init() {}

    /**
     * Get all favourite song IDs and metadata.
     * @returns {Array<{id: string, title: string, songbook: string, number: number, addedAt: string}>}
     */
    getAll() {
        try {
            return JSON.parse(localStorage.getItem(this.storageKey)) || [];
        } catch {
            return [];
        }
    }

    /**
     * Save the favourites array to localStorage.
     * @param {Array} favorites
     */
    saveAll(favorites) {
        localStorage.setItem(this.storageKey, JSON.stringify(favorites));
    }

    /**
     * Check if a song ID is in favourites.
     * @param {string} songId
     * @returns {boolean}
     */
    isFavorite(songId) {
        return this.getAll().some(f => f.id === songId);
    }

    /**
     * Toggle a song in/out of favourites.
     * @param {string} songId
     * @param {string} title
     * @param {string} songbook
     * @param {number} number
     * @returns {boolean} True if added, false if removed
     */
    toggle(songId, title, songbook, number) {
        let favorites = this.getAll();
        const index = favorites.findIndex(f => f.id === songId);

        if (index >= 0) {
            /* Remove from favourites */
            favorites.splice(index, 1);
            this.saveAll(favorites);
            return false;
        } else {
            /* Add to favourites */
            favorites.push({
                id: songId,
                title: title || '',
                songbook: songbook || '',
                number: number || 0,
                addedAt: new Date().toISOString(),
            });
            this.saveAll(favorites);
            return true;
        }
    }

    /** Clear all favourites */
    clearAll() {
        localStorage.removeItem(this.storageKey);
    }

    /**
     * Toggle favourite for the currently displayed song (keyboard shortcut).
     */
    toggleCurrentSong() {
        const btn = document.querySelector('.btn-favourite');
        if (btn) btn.click();
    }

    /**
     * Initialise the favourite button on a song page.
     * Called by router after song page loads.
     */
    initSongPage() {
        const btn = document.querySelector('.btn-favourite');
        if (!btn) return;

        const songId = btn.dataset.songId;
        const songTitle = btn.dataset.songTitle;

        /* Set initial state */
        this.updateFavoriteButton(btn, this.isFavorite(songId));

        /* Click handler */
        btn.addEventListener('click', () => {
            const article = btn.closest('.page-song');
            const songbook = article?.querySelector('.badge')?.textContent.trim() || '';
            const number = parseInt(article?.querySelector('.song-number-badge-lg')?.textContent.trim() || '0');

            const added = this.toggle(songId, songTitle, songbook, number);
            this.updateFavoriteButton(btn, added);

            this.app.showToast(
                added ? 'Added to favourites' : 'Removed from favourites',
                added ? 'success' : 'info',
                2000
            );
        });
    }

    /**
     * Update a favourite button's visual state.
     * @param {HTMLElement} btn The button element
     * @param {boolean} isFav True if currently favourited
     */
    updateFavoriteButton(btn, isFav) {
        btn.setAttribute('aria-pressed', String(isFav));
        btn.setAttribute('aria-label', isFav ? 'Remove from favourites' : 'Add to favourites');

        const icon = btn.querySelector('i');
        const label = btn.querySelector('span');

        if (icon) {
            icon.className = isFav ? 'fa-solid fa-heart me-1' : 'fa-regular fa-heart me-1';
        }
        if (label) {
            label.textContent = isFav ? 'Favourited' : 'Favourite';
        }
    }

    /**
     * Load and display the favourites list on the favourites page.
     * Called by router after favourites page loads.
     */
    async loadFavoritesList() {
        const listEl = document.getElementById('favorites-list');
        const emptyEl = document.getElementById('favorites-empty');
        const countBadge = document.getElementById('favorites-count-badge');
        const countEl = document.getElementById('favorites-count');
        const clearAllBtn = document.getElementById('clear-all-favorites');

        const favorites = this.getAll();

        if (favorites.length === 0) {
            /* Show empty state */
            if (listEl) listEl.innerHTML = '';
            if (emptyEl) emptyEl.style.display = '';
            if (countBadge) countBadge.classList.add('d-none');
            if (clearAllBtn) clearAllBtn.classList.add('d-none');
            return;
        }

        /* Hide empty state, show list */
        if (emptyEl) emptyEl.style.display = 'none';
        if (countBadge) countBadge.classList.remove('d-none');
        if (countEl) countEl.textContent = `${favorites.length} song${favorites.length !== 1 ? 's' : ''}`;
        if (clearAllBtn) {
            clearAllBtn.classList.remove('d-none');
            clearAllBtn.addEventListener('click', () => {
                if (confirm('Remove all favourites?')) {
                    this.clearAll();
                    this.loadFavoritesList();
                    this.app.showToast('All favourites removed', 'info');
                }
            });
        }

        /* Render favourite items */
        if (listEl) {
            listEl.innerHTML = favorites.map(fav => `
                <a href="/song/${this.escapeHtml(fav.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${this.escapeHtml(fav.id)}"
                   role="listitem">
                    <span class="song-number-badge">${fav.number || '?'}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${this.escapeHtml(fav.title)}</span>
                        <small class="text-muted d-block">${this.escapeHtml(fav.songbook)}</small>
                    </div>
                    <i class="fa-solid fa-heart text-danger me-2" aria-hidden="true"></i>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>
            `).join('');
        }
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
