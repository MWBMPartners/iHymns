/**
 * iHymns — Favourites Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages the user's favourite songs. Stores IDs and metadata in
 * localStorage. Provides toggle, list, and clear functionality.
 */

import { toTitleCase } from '../utils/text.js';
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { STORAGE_FAVORITES, STORAGE_CUSTOM_TAGS, songbookLabel } from '../constants.js';

export class Favorites {
    constructor(app) {
        this.app = app;
        /** @type {string} localStorage key for favourites */
        this.storageKey = STORAGE_FAVORITES;
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
                tags: [],
                addedAt: new Date().toISOString(),
            });
            this.saveAll(favorites);
            return true;
        }
    }

    /* =====================================================================
     * TAGS (#122) — Custom categories for favourites
     * ===================================================================== */

    /** Common pre-defined tags for quick selection */
    static COMMON_TAGS = [
        'Praise', 'Worship', 'Communion', 'Christmas', 'Easter',
        'Weddings', 'Funerals', 'Baptism', 'Opening', 'Closing',
        'Fast', 'Slow', 'Choir', 'Children',
    ];

    /**
     * Get all unique tags used across favourites + user custom tags.
     * @returns {string[]}
     */
    getAllTags() {
        const favorites = this.getAll();
        const tagSet = new Set();
        for (const fav of favorites) {
            for (const tag of (fav.tags || [])) {
                tagSet.add(tag);
            }
        }
        /* Merge with any custom tags stored separately */
        try {
            const custom = JSON.parse(localStorage.getItem(STORAGE_CUSTOM_TAGS)) || [];
            custom.forEach(t => tagSet.add(t));
        } catch {}
        return [...tagSet].sort();
    }

    /**
     * Set tags on a favourite song.
     * @param {string} songId
     * @param {string[]} tags
     */
    setTags(songId, tags) {
        const favorites = this.getAll();
        const fav = favorites.find(f => f.id === songId);
        if (fav) {
            fav.tags = tags;
            this.saveAll(favorites);
        }
    }

    /**
     * Get tags for a specific favourite.
     * @param {string} songId
     * @returns {string[]}
     */
    getTags(songId) {
        const fav = this.getAll().find(f => f.id === songId);
        return fav?.tags || [];
    }

    /**
     * Save a custom tag to the user's tag list.
     * @param {string} tag
     */
    saveCustomTag(tag) {
        let custom = [];
        try { custom = JSON.parse(localStorage.getItem(STORAGE_CUSTOM_TAGS)) || []; } catch {}
        if (!custom.includes(tag)) {
            custom.push(tag);
            custom.sort();
            localStorage.setItem(STORAGE_CUSTOM_TAGS, JSON.stringify(custom));
        }
    }

    /**
     * Show a tag editor modal for a favourite song.
     * @param {string} songId
     * @param {string} songTitle
     */
    async editTags(songId, songTitle) {
        const currentTags = this.getTags(songId);
        const allTags = [...new Set([...Favorites.COMMON_TAGS, ...this.getAllTags()])].sort();

        /* Build tag picker content */
        const tagHtml = allTags.map(tag => {
            const checked = currentTags.includes(tag) ? 'checked' : '';
            const escaped = escapeHtml(tag);
            return `<label class="btn btn-sm ${checked ? 'btn-primary' : 'btn-outline-secondary'} rounded-pill tag-toggle-btn">
                        <input type="checkbox" class="d-none tag-checkbox" value="${escaped}" ${checked}> ${escaped}
                    </label>`;
        }).join('');

        /* Create modal */
        document.getElementById('tag-editor-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'tag-editor-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fa-solid fa-tags me-2" aria-hidden="true"></i>
                            Edit Tags
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-2">${escapeHtml(songTitle)}</p>
                        <div class="d-flex flex-wrap gap-2 mb-3">${tagHtml}</div>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" id="tag-custom-input"
                                   placeholder="Add custom tag..." maxlength="30">
                            <button type="button" class="btn btn-outline-primary" id="tag-custom-add">
                                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="tag-save-btn">Save Tags</button>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);

        /* Toggle button styling on checkbox change */
        modal.querySelectorAll('.tag-toggle-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const cb = btn.querySelector('.tag-checkbox');
                cb.checked = !cb.checked;
                btn.className = `btn btn-sm ${cb.checked ? 'btn-primary' : 'btn-outline-secondary'} rounded-pill tag-toggle-btn`;
            });
        });

        /* Add custom tag */
        const addCustom = () => {
            const input = modal.querySelector('#tag-custom-input');
            const tag = input.value.trim();
            if (!tag) return;
            /* Check if already exists */
            const existing = modal.querySelector(`.tag-checkbox[value="${escapeHtml(tag)}"]`);
            if (existing) {
                existing.checked = true;
                existing.closest('.tag-toggle-btn').className = 'btn btn-sm btn-primary rounded-pill tag-toggle-btn';
            } else {
                const container = modal.querySelector('.d-flex.flex-wrap');
                const newBtn = document.createElement('label');
                newBtn.className = 'btn btn-sm btn-primary rounded-pill tag-toggle-btn';
                newBtn.innerHTML = `<input type="checkbox" class="d-none tag-checkbox" value="${escapeHtml(tag)}" checked> ${escapeHtml(tag)}`;
                newBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const cb = newBtn.querySelector('.tag-checkbox');
                    cb.checked = !cb.checked;
                    newBtn.className = `btn btn-sm ${cb.checked ? 'btn-primary' : 'btn-outline-secondary'} rounded-pill tag-toggle-btn`;
                });
                container.appendChild(newBtn);
                this.saveCustomTag(tag);
            }
            input.value = '';
        };

        modal.querySelector('#tag-custom-add').addEventListener('click', addCustom);
        modal.querySelector('#tag-custom-input').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); addCustom(); }
        });

        /* Save handler */
        return new Promise((resolve) => {
            modal.querySelector('#tag-save-btn').addEventListener('click', () => {
                const selected = [...modal.querySelectorAll('.tag-checkbox:checked')].map(cb => cb.value);
                this.setTags(songId, selected);
                bsModal.hide();
                resolve(selected);
            });

            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
                resolve(null);
            });

            bsModal.show();
        });
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

            /* Track favourite toggle analytics */
            if (this.app.analytics) {
                this.app.analytics.trackFavoriteToggle(songId, added);
            }

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
            listEl.innerHTML = favorites.map(fav => {
                const tags = (fav.tags || []);
                const tagsHtml = tags.length > 0
                    ? `<span class="fav-tags ms-1">${tags.map(t => `<span class="badge bg-body-secondary text-body-secondary rounded-pill fav-tag-badge">${escapeHtml(t)}</span>`).join(' ')}</span>`
                    : '';
                const tagsData = tags.map(t => escapeHtml(t)).join(',');
                return `
                <a href="/song/${escapeHtml(fav.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${escapeHtml(fav.id)}"
                   data-tags="${tagsData}"
                   role="listitem">
                    <input type="checkbox" class="form-check-input fav-select-check d-none me-2"
                           data-song-id="${escapeHtml(fav.id)}"
                           aria-label="Select ${escapeHtml(toTitleCase(fav.title))}"
                           onclick="event.stopPropagation()">
                    <span class="song-number-badge" data-songbook="${escapeHtml(fav.songbook)}">${fav.number ?? ''}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${escapeHtml(toTitleCase(fav.title))}${verifiedBadge(fav)}</span>
                        <small class="text-muted d-block">${songbookLabel(fav.songbook)}${tagsHtml}</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-muted fav-edit-tags p-0 me-2"
                            data-song-id="${escapeHtml(fav.id)}"
                            data-song-title="${escapeHtml(fav.title)}"
                            aria-label="Edit tags"
                            onclick="event.preventDefault(); event.stopPropagation();">
                        <i class="fa-solid fa-tags" aria-hidden="true"></i>
                    </button>
                    <i class="fa-solid fa-heart text-danger me-2 fav-heart-icon" aria-hidden="true"></i>
                    <i class="fa-solid fa-chevron-right text-muted fav-chevron-icon" aria-hidden="true"></i>
                </a>`;
            }).join('');

            /* Bind tag edit buttons */
            listEl.querySelectorAll('.fav-edit-tags').forEach(btn => {
                btn.addEventListener('click', async () => {
                    await this.editTags(btn.dataset.songId, btn.dataset.songTitle);
                    this.loadFavoritesList();
                });
            });
        }

        /* Render tag filter (#122) */
        this.renderTagFilter(favorites);

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
     * Render the tag filter bar on the favourites page (#122).
     * @param {Array} favorites Current favourites list
     */
    renderTagFilter(favorites) {
        const filterEl = document.getElementById('favorites-tag-filter');
        const pillsEl = document.getElementById('favorites-tag-pills');
        if (!filterEl || !pillsEl) return;

        /* Collect all tags in use */
        const tagCounts = {};
        for (const fav of favorites) {
            for (const tag of (fav.tags || [])) {
                tagCounts[tag] = (tagCounts[tag] || 0) + 1;
            }
        }

        const tags = Object.entries(tagCounts).sort((a, b) => b[1] - a[1]);
        if (tags.length === 0) {
            filterEl.classList.add('d-none');
            return;
        }

        filterEl.classList.remove('d-none');
        pillsEl.innerHTML =
            `<button type="button" class="btn btn-sm btn-primary rounded-pill tag-filter-btn active" data-tag="">
                All <span class="badge bg-white text-primary ms-1">${favorites.length}</span>
            </button>` +
            tags.map(([tag, count]) =>
                `<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill tag-filter-btn" data-tag="${escapeHtml(tag)}">
                    ${escapeHtml(tag)} <span class="badge bg-secondary ms-1">${count}</span>
                </button>`
            ).join('');

        /* Bind filter clicks */
        pillsEl.querySelectorAll('.tag-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                /* Update active state */
                pillsEl.querySelectorAll('.tag-filter-btn').forEach(b => {
                    b.classList.remove('btn-primary', 'active');
                    b.classList.add('btn-outline-secondary');
                });
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-primary', 'active');

                /* Filter list items */
                const tag = btn.dataset.tag;
                const items = document.querySelectorAll('#favorites-list .song-list-item');
                items.forEach(item => {
                    if (!tag) {
                        item.classList.remove('d-none');
                    } else {
                        const itemTags = (item.dataset.tags || '').split(',');
                        item.classList.toggle('d-none', !itemTags.includes(tag));
                    }
                });
            });
        });
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
}
