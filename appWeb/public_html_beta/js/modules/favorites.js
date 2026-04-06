/**
 * iHymns — Favourites Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
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
        /** @type {boolean} Whether select mode is active (#119) */
        this.selectMode = false;
        /** @type {Set<string>} Currently selected song IDs (#119) */
        this.selectedIds = new Set();
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
        this.app.syncStorage(this.storageKey);
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
        const selectToggle = document.getElementById('favorites-select-toggle');
        const batchToolbar = document.getElementById('favorites-batch-toolbar');

        /* Reset select mode on reload */
        this.selectMode = false;
        this.selectedIds.clear();

        const favorites = this.getAll();

        if (favorites.length === 0) {
            /* Show empty state */
            if (listEl) listEl.innerHTML = '';
            if (emptyEl) emptyEl.classList.remove('d-none');
            if (countBadge) countBadge.classList.add('d-none');
            if (clearAllBtn) clearAllBtn.classList.add('d-none');
            if (selectToggle) selectToggle.classList.add('d-none');
            if (batchToolbar) batchToolbar.classList.add('d-none');
            return;
        }

        /* Hide empty state, show list */
        if (emptyEl) emptyEl.classList.add('d-none');
        if (countBadge) countBadge.classList.remove('d-none');
        if (countEl) countEl.textContent = `${favorites.length} song${favorites.length !== 1 ? 's' : ''}`;

        /* Show select toggle button (#119) */
        if (selectToggle) {
            selectToggle.classList.remove('d-none');
            const freshToggle = selectToggle.cloneNode(true);
            selectToggle.replaceWith(freshToggle);
            freshToggle.addEventListener('click', () => this.toggleSelectMode());
        }

        if (clearAllBtn) {
            clearAllBtn.classList.remove('d-none');

            /*
             * FIX (#79): Use replaceWith(clone) to remove all prior event
             * listeners before binding a fresh one. This prevents listener
             * accumulation when the favourites page is visited multiple times.
             */
            const freshBtn = clearAllBtn.cloneNode(true);
            clearAllBtn.replaceWith(freshBtn);

            freshBtn.addEventListener('click', async () => {
                const ok = await this.app.showConfirm('Remove all favourites?', {
                    title: 'Clear Favourites',
                    okText: 'Remove All',
                    okClass: 'btn-danger',
                });
                if (ok) {
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
                    <input type="checkbox" class="form-check-input fav-select-check d-none me-2"
                           data-song-id="${this.escapeHtml(fav.id)}"
                           aria-label="Select ${this.escapeHtml(fav.title)}"
                           onclick="event.stopPropagation()">
                    <span class="song-number-badge" data-songbook="${this.escapeHtml(fav.songbook)}">${fav.number || '?'}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${this.escapeHtml(fav.title)}</span>
                        <small class="text-muted d-block">${this.escapeHtml(fav.songbook)}</small>
                    </div>
                    <i class="fa-solid fa-heart text-danger me-2 fav-heart-icon" aria-hidden="true"></i>
                    <i class="fa-solid fa-chevron-right text-muted fav-chevron-icon" aria-hidden="true"></i>
                </a>
            `).join('');
        }

        /* Bind batch toolbar actions (#119) */
        this.initBatchToolbar();
    }

    /* =====================================================================
     * BATCH SELECTION MODE (#119)
     * ===================================================================== */

    /**
     * Toggle select mode on/off.
     */
    toggleSelectMode() {
        this.selectMode = !this.selectMode;
        this.selectedIds.clear();

        const toggle = document.getElementById('favorites-select-toggle');
        const toolbar = document.getElementById('favorites-batch-toolbar');
        const checkboxes = document.querySelectorAll('.fav-select-check');
        const hearts = document.querySelectorAll('.fav-heart-icon');
        const chevrons = document.querySelectorAll('.fav-chevron-icon');
        const listItems = document.querySelectorAll('#favorites-list .song-list-item');

        if (toggle) {
            toggle.setAttribute('aria-pressed', String(this.selectMode));
            toggle.innerHTML = this.selectMode
                ? '<i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Cancel'
                : '<i class="fa-solid fa-check-double me-1" aria-hidden="true"></i> Select';
        }

        checkboxes.forEach(cb => {
            cb.classList.toggle('d-none', !this.selectMode);
            cb.checked = false;
        });
        hearts.forEach(el => el.classList.toggle('d-none', this.selectMode));
        chevrons.forEach(el => el.classList.toggle('d-none', this.selectMode));

        if (toolbar) toolbar.classList.toggle('d-none', !this.selectMode);

        /* In select mode, clicks toggle checkboxes instead of navigating */
        listItems.forEach(item => {
            if (this.selectMode) {
                item.setAttribute('data-original-href', item.getAttribute('href'));
                item.removeAttribute('href');
                item.addEventListener('click', this._handleSelectClick);
            } else {
                const original = item.getAttribute('data-original-href');
                if (original) item.setAttribute('href', original);
                item.removeEventListener('click', this._handleSelectClick);
            }
        });

        this.updateBatchCount();
    }

    /**
     * Handle click on a list item in select mode — toggle its checkbox.
     * @param {Event} e
     */
    _handleSelectClick = (e) => {
        e.preventDefault();
        const item = e.currentTarget;
        const cb = item.querySelector('.fav-select-check');
        if (cb) {
            cb.checked = !cb.checked;
            const songId = cb.dataset.songId;
            if (cb.checked) {
                this.selectedIds.add(songId);
            } else {
                this.selectedIds.delete(songId);
            }
            item.classList.toggle('active', cb.checked);
            this.updateBatchCount();
        }
    };

    /**
     * Update the selected count badge and enable/disable batch buttons.
     */
    updateBatchCount() {
        const countEl = document.getElementById('favorites-selected-count');
        const setlistBtn = document.getElementById('favorites-batch-setlist');
        const removeBtn = document.getElementById('favorites-batch-remove');
        const count = this.selectedIds.size;

        if (countEl) countEl.textContent = `${count} selected`;
        if (setlistBtn) setlistBtn.disabled = count === 0;
        if (removeBtn) removeBtn.disabled = count === 0;
    }

    /**
     * Initialise batch toolbar button handlers.
     */
    initBatchToolbar() {
        const selectAllBtn = document.getElementById('favorites-select-all');
        const setlistBtn = document.getElementById('favorites-batch-setlist');
        const removeBtn = document.getElementById('favorites-batch-remove');

        if (selectAllBtn) {
            const freshBtn = selectAllBtn.cloneNode(true);
            selectAllBtn.replaceWith(freshBtn);
            freshBtn.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('.fav-select-check');
                const allChecked = this.selectedIds.size === checkboxes.length;
                checkboxes.forEach(cb => {
                    cb.checked = !allChecked;
                    const item = cb.closest('.song-list-item');
                    if (!allChecked) {
                        this.selectedIds.add(cb.dataset.songId);
                        item?.classList.add('active');
                    } else {
                        this.selectedIds.delete(cb.dataset.songId);
                        item?.classList.remove('active');
                    }
                });
                freshBtn.textContent = allChecked ? 'Select All' : 'Deselect All';
                this.updateBatchCount();
            });
        }

        if (setlistBtn) {
            const freshBtn = setlistBtn.cloneNode(true);
            setlistBtn.replaceWith(freshBtn);
            freshBtn.addEventListener('click', () => this.batchAddToSetList());
        }

        if (removeBtn) {
            const freshBtn = removeBtn.cloneNode(true);
            removeBtn.replaceWith(freshBtn);
            freshBtn.addEventListener('click', () => this.batchRemove());
        }
    }

    /**
     * Batch add selected favourites to a set list.
     */
    async batchAddToSetList() {
        if (this.selectedIds.size === 0) return;

        let allSetlists = this.app.setList.getAll();

        /* If no set lists exist, prompt to create one */
        if (allSetlists.length === 0) {
            const name = await this.app.showPrompt('Create a new set list:', 'My Set List');
            if (!name || !name.trim()) return;
            this.app.setList.create(name.trim());
            allSetlists = this.app.setList.getAll();
        }

        /* Pick target set list */
        let targetList;
        if (allSetlists.length === 1) {
            targetList = allSetlists[0];
        } else {
            /* Let user choose from existing set lists */
            const choices = allSetlists.map(l => l.name).join(', ');
            const name = await this.app.showPrompt(
                `Which set list? (${choices})`,
                allSetlists[0].name,
            );
            if (!name) return;
            targetList = allSetlists.find(l => l.name.toLowerCase() === name.trim().toLowerCase());
            if (!targetList) {
                /* Create new set list with the entered name */
                targetList = this.app.setList.create(name.trim());
            }
        }

        /* Add all selected songs to the chosen set list */
        const favorites = this.getAll();
        let addedCount = 0;
        for (const fav of favorites) {
            if (this.selectedIds.has(fav.id)) {
                const added = this.app.setList.addSong(targetList.id, {
                    id: fav.id,
                    title: fav.title,
                    songbook: fav.songbook,
                    number: fav.number,
                });
                if (added) addedCount++;
            }
        }

        this.app.showToast(
            `Added ${addedCount} song${addedCount !== 1 ? 's' : ''} to "${targetList.name}"`,
            'success'
        );
        this.toggleSelectMode();
    }

    /**
     * Batch remove selected songs from favourites.
     */
    async batchRemove() {
        if (this.selectedIds.size === 0) return;

        const count = this.selectedIds.size;
        const ok = await this.app.showConfirm(
            `Remove ${count} song${count !== 1 ? 's' : ''} from favourites?`,
            {
                title: 'Remove Selected',
                okText: 'Remove',
                okClass: 'btn-danger',
            }
        );

        if (!ok) return;

        let favorites = this.getAll();
        favorites = favorites.filter(f => !this.selectedIds.has(f.id));
        this.saveAll(favorites);

        this.app.showToast(`Removed ${count} favourite${count !== 1 ? 's' : ''}`, 'info');
        this.loadFavoritesList();
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
