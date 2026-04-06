/**
 * iHymns — Set List / Playlist Module (#94)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Allows users to create ordered set lists (playlists) of songs for
 * worship services or events. Supports multiple named lists, drag-to-
 * reorder, sequential navigation, and text export.
 *
 * STORAGE FORMAT (localStorage key: 'ihymns_setlists'):
 *   [{
 *     id: "uuid",
 *     name: "Sunday Morning 6 April",
 *     createdAt: "ISO",
 *     songs: [{ id, title, songbook, number }, ...]
 *   }, ...]
 */

export class SetList {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key */
        this.storageKey = 'ihymns_setlists';

        /** @type {string|null} Currently active set list ID (for navigation) */
        this.activeSetListId = null;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /* =====================================================================
     * CRUD OPERATIONS
     * ===================================================================== */

    /**
     * Get all set lists.
     * @returns {Array}
     */
    getAll() {
        try {
            return JSON.parse(localStorage.getItem(this.storageKey)) || [];
        } catch {
            return [];
        }
    }

    /**
     * Save all set lists to localStorage.
     * @param {Array} lists
     */
    saveAll(lists) {
        localStorage.setItem(this.storageKey, JSON.stringify(lists));
        this.app.syncStorage(this.storageKey);
    }

    /**
     * Get a set list by ID.
     * @param {string} listId
     * @returns {object|null}
     */
    getById(listId) {
        return this.getAll().find(l => l.id === listId) || null;
    }

    /**
     * Create a new set list.
     * @param {string} name Set list name
     * @returns {object} The new set list
     */
    create(name) {
        const lists = this.getAll();
        const newList = {
            id: this.generateId(),
            name: name || 'Untitled Set List',
            createdAt: new Date().toISOString(),
            songs: [],
        };
        lists.unshift(newList);
        this.saveAll(lists);
        return newList;
    }

    /**
     * Rename a set list.
     * @param {string} listId
     * @param {string} name New name
     */
    rename(listId, name) {
        const lists = this.getAll();
        const list = lists.find(l => l.id === listId);
        if (list) {
            list.name = name;
            this.saveAll(lists);
        }
    }

    /**
     * Delete a set list.
     * @param {string} listId
     */
    delete(listId) {
        const lists = this.getAll().filter(l => l.id !== listId);
        this.saveAll(lists);
        if (this.activeSetListId === listId) {
            this.activeSetListId = null;
        }
    }

    /**
     * Add a song to a set list (at the end).
     * @param {string} listId
     * @param {object} song { id, title, songbook, number }
     * @returns {boolean} True if added (false if duplicate)
     */
    addSong(listId, song) {
        const lists = this.getAll();
        const list = lists.find(l => l.id === listId);
        if (!list) return false;

        /* Prevent duplicates within the same set list */
        if (list.songs.some(s => s.id === song.id)) return false;

        list.songs.push({
            id: song.id,
            title: song.title || '',
            songbook: song.songbook || '',
            number: song.number || 0,
        });
        this.saveAll(lists);
        return true;
    }

    /**
     * Remove a song from a set list.
     * @param {string} listId
     * @param {string} songId
     */
    removeSong(listId, songId) {
        const lists = this.getAll();
        const list = lists.find(l => l.id === listId);
        if (!list) return;

        list.songs = list.songs.filter(s => s.id !== songId);
        this.saveAll(lists);
    }

    /**
     * Move a song within a set list (reorder).
     * @param {string} listId
     * @param {number} fromIndex
     * @param {number} toIndex
     */
    moveSong(listId, fromIndex, toIndex) {
        const lists = this.getAll();
        const list = lists.find(l => l.id === listId);
        if (!list) return;

        const [song] = list.songs.splice(fromIndex, 1);
        list.songs.splice(toIndex, 0, song);
        this.saveAll(lists);
    }

    /* =====================================================================
     * SET LIST PAGE — Render and manage
     * ===================================================================== */

    /**
     * Initialise the set list page (called by router after page loads).
     */
    initSetListPage() {
        this.renderSetListOverview();
    }

    /**
     * Render the set list overview (list of all set lists).
     */
    renderSetListOverview() {
        const container = document.getElementById('setlist-container');
        if (!container) return;

        const lists = this.getAll();

        if (lists.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5" id="setlist-empty">
                    <i class="fa-solid fa-list-ol fa-3x mb-3 opacity-25" aria-hidden="true"></i>
                    <p>No set lists yet</p>
                    <small>Create a set list to organise songs for worship services</small>
                </div>`;
        } else {
            container.innerHTML = `
                <div class="list-group" id="setlist-list">
                    ${lists.map(list => `
                        <div class="list-group-item list-group-item-action d-flex align-items-center gap-3 setlist-item"
                             data-setlist-id="${this.escapeHtml(list.id)}" role="button" tabindex="0">
                            <div class="flex-grow-1">
                                <strong>${this.escapeHtml(list.name)}</strong>
                                <small class="text-muted d-block">
                                    ${list.songs.length} song${list.songs.length !== 1 ? 's' : ''}
                                    &middot; Created ${this.formatDate(list.createdAt)}
                                </small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-setlist"
                                    data-setlist-id="${this.escapeHtml(list.id)}"
                                    aria-label="Delete set list ${this.escapeHtml(list.name)}">
                                <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                            </button>
                        </div>
                    `).join('')}
                </div>`;

            /* Bind click to open set list detail */
            container.querySelectorAll('.setlist-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    if (e.target.closest('.btn-delete-setlist')) return;
                    const id = item.dataset.setlistId;
                    this.renderSetListDetail(id);
                });
                item.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        if (e.target.closest('.btn-delete-setlist')) return;
                        this.renderSetListDetail(item.dataset.setlistId);
                    }
                });
            });

            /* Bind delete buttons */
            container.querySelectorAll('.btn-delete-setlist').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const id = btn.dataset.setlistId;
                    if (confirm('Delete this set list?')) {
                        this.delete(id);
                        this.renderSetListOverview();
                        this.app.showToast('Set list deleted', 'info', 2000);
                    }
                });
            });
        }

        /* Bind create button */
        const createBtn = document.getElementById('create-setlist-btn');
        if (createBtn) {
            const freshBtn = createBtn.cloneNode(true);
            createBtn.replaceWith(freshBtn);
            freshBtn.addEventListener('click', () => this.showCreateDialog());
        }
    }

    /**
     * Render a single set list detail view (songs in order).
     * @param {string} listId
     */
    renderSetListDetail(listId) {
        const container = document.getElementById('setlist-container');
        if (!container) return;

        const list = this.getById(listId);
        if (!list) return;

        let songsHtml;
        if (list.songs.length === 0) {
            songsHtml = `
                <div class="text-center text-muted py-4">
                    <p>No songs in this set list yet.</p>
                    <small>Open a song and use "Add to Set List" to add songs.</small>
                </div>`;
        } else {
            songsHtml = `
                <div class="list-group" id="setlist-songs">
                    ${list.songs.map((song, index) => `
                        <div class="list-group-item d-flex align-items-center gap-2 setlist-song-item"
                             data-song-id="${this.escapeHtml(song.id)}" data-index="${index}"
                             draggable="true">
                            <span class="text-muted fw-bold me-1" style="min-width:24px">${index + 1}.</span>
                            <span class="song-number-badge" data-songbook="${this.escapeHtml(song.songbook)}">${song.number || '?'}</span>
                            <div class="flex-grow-1">
                                <a href="/song/${this.escapeHtml(song.id)}" data-navigate="song"
                                   class="text-decoration-none">${this.escapeHtml(song.title)}</a>
                                <small class="text-muted d-block">${this.escapeHtml(song.songbook)}</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-move-up"
                                    data-index="${index}" ${index === 0 ? 'disabled' : ''}
                                    aria-label="Move up">
                                <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-move-down"
                                    data-index="${index}" ${index === list.songs.length - 1 ? 'disabled' : ''}
                                    aria-label="Move down">
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-song"
                                    data-song-id="${this.escapeHtml(song.id)}"
                                    aria-label="Remove from set list">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                    `).join('')}
                </div>`;
        }

        container.innerHTML = `
            <div class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="setlist-back-btn">
                    <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back
                </button>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">${this.escapeHtml(list.name)}</h2>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="setlist-rename-btn"
                            aria-label="Rename set list" title="Rename">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="setlist-export-btn"
                            aria-label="Export set list as text" title="Export">
                        <i class="fa-solid fa-file-export" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="setlist-copy-btn"
                            aria-label="Copy set list to clipboard" title="Copy">
                        <i class="fa-solid fa-copy" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="setlist-print-btn"
                            aria-label="Print set list" title="Print">
                        <i class="fa-solid fa-print" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="setlist-activate-btn"
                            aria-label="Activate set list for navigation" title="Use this set list">
                        <i class="fa-solid fa-play me-1" aria-hidden="true"></i> Use
                    </button>
                </div>
            </div>
            <p class="text-muted small">${list.songs.length} song${list.songs.length !== 1 ? 's' : ''}</p>
            ${songsHtml}`;

        /* Back button */
        container.querySelector('#setlist-back-btn')?.addEventListener('click', () => {
            this.renderSetListOverview();
        });

        /* Rename */
        container.querySelector('#setlist-rename-btn')?.addEventListener('click', () => {
            const name = prompt('Rename set list:', list.name);
            if (name && name.trim()) {
                this.rename(listId, name.trim());
                this.renderSetListDetail(listId);
            }
        });

        /* Export as text */
        container.querySelector('#setlist-export-btn')?.addEventListener('click', () => {
            this.exportAsText(list);
        });

        /* Copy to clipboard */
        container.querySelector('#setlist-copy-btn')?.addEventListener('click', () => {
            this.copyToClipboard(list);
        });

        /* Print set list (#113) */
        container.querySelector('#setlist-print-btn')?.addEventListener('click', () => {
            this.printSetList(list);
        });

        /* Activate for navigation */
        container.querySelector('#setlist-activate-btn')?.addEventListener('click', () => {
            this.activeSetListId = listId;
            this.app.showToast(`Set list "${list.name}" activated. Navigate songs with the set list controls.`, 'success', 3000);
        });

        /* Move up/down buttons */
        container.querySelectorAll('.btn-move-up').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.dataset.index, 10);
                this.moveSong(listId, idx, idx - 1);
                this.renderSetListDetail(listId);
            });
        });

        container.querySelectorAll('.btn-move-down').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.dataset.index, 10);
                this.moveSong(listId, idx, idx + 1);
                this.renderSetListDetail(listId);
            });
        });

        /* Remove song buttons */
        container.querySelectorAll('.btn-remove-song').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.removeSong(listId, btn.dataset.songId);
                this.renderSetListDetail(listId);
                this.app.showToast('Song removed from set list', 'info', 2000);
            });
        });

        /* Drag-to-reorder */
        this.initDragReorder(listId);
    }

    /**
     * Initialise drag-to-reorder on set list songs.
     * @param {string} listId
     */
    initDragReorder(listId) {
        const songItems = document.querySelectorAll('.setlist-song-item');
        let dragSrcIndex = null;

        songItems.forEach(item => {
            item.addEventListener('dragstart', (e) => {
                dragSrcIndex = parseInt(item.dataset.index, 10);
                item.classList.add('opacity-50');
                e.dataTransfer.effectAllowed = 'move';
            });

            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                item.classList.add('border-primary');
            });

            item.addEventListener('dragleave', () => {
                item.classList.remove('border-primary');
            });

            item.addEventListener('drop', (e) => {
                e.preventDefault();
                item.classList.remove('border-primary');
                const dropIndex = parseInt(item.dataset.index, 10);
                if (dragSrcIndex !== null && dragSrcIndex !== dropIndex) {
                    this.moveSong(listId, dragSrcIndex, dropIndex);
                    this.renderSetListDetail(listId);
                }
            });

            item.addEventListener('dragend', () => {
                item.classList.remove('opacity-50');
                dragSrcIndex = null;
            });
        });
    }

    /* =====================================================================
     * SONG PAGE INTEGRATION — "Add to Set List" button
     * ===================================================================== */

    /**
     * Initialise the "Add to Set List" button on a song page.
     * Called by router after song page loads.
     */
    initSongPage() {
        const btn = document.querySelector('.btn-add-to-setlist');
        if (!btn) return;

        btn.addEventListener('click', () => {
            const article = btn.closest('.page-song');
            const songId = article?.dataset.songId || '';
            const title = article?.querySelector('h1')?.textContent.trim() || '';
            const songbook = article?.dataset.songbook || '';
            const number = parseInt(article?.dataset.songNumber, 10) || 0;

            if (!songId) return;

            this.showAddToSetListDialog({ id: songId, title, songbook, number });
        });
    }

    /**
     * Show a dialog to choose which set list to add a song to.
     * @param {object} song { id, title, songbook, number }
     */
    showAddToSetListDialog(song) {
        const lists = this.getAll();

        /* Remove existing modal if any */
        document.getElementById('add-to-setlist-modal')?.remove();

        const modal = document.createElement('div');
        modal.id = 'add-to-setlist-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'add-to-setlist-modal-label');
        modal.setAttribute('aria-hidden', 'true');

        const listOptions = lists.length > 0
            ? lists.map(l => `
                <button type="button" class="list-group-item list-group-item-action setlist-option"
                        data-setlist-id="${this.escapeHtml(l.id)}">
                    <strong>${this.escapeHtml(l.name)}</strong>
                    <small class="text-muted d-block">${l.songs.length} song${l.songs.length !== 1 ? 's' : ''}</small>
                </button>`).join('')
            : '<p class="text-muted text-center py-3">No set lists yet</p>';

        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="add-to-setlist-modal-label">
                            <i class="fa-solid fa-list-ol me-2" aria-hidden="true"></i>
                            Add to Set List
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Adding: <strong>${this.escapeHtml(song.title)}</strong></p>
                        <div class="list-group mb-3" id="setlist-options">
                            ${listOptions}
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm w-100" id="create-new-setlist-btn">
                            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>
                            Create New Set List
                        </button>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        /* Bind set list option clicks */
        modal.querySelectorAll('.setlist-option').forEach(opt => {
            opt.addEventListener('click', () => {
                const added = this.addSong(opt.dataset.setlistId, song);
                bsModal.hide();
                if (added) {
                    this.app.showToast('Added to set list', 'success', 2000);
                } else {
                    this.app.showToast('Song already in this set list', 'warning', 2000);
                }
            });
        });

        /* Create new set list */
        modal.querySelector('#create-new-setlist-btn')?.addEventListener('click', () => {
            const name = prompt('Set list name:', '');
            if (name && name.trim()) {
                const newList = this.create(name.trim());
                this.addSong(newList.id, song);
                bsModal.hide();
                this.app.showToast(`Created "${name.trim()}" and added song`, 'success', 2000);
            }
        });

        /* Clean up on close */
        modal.addEventListener('hidden.bs.modal', () => modal.remove());
    }

    /**
     * Show the create set list dialog (from set list page).
     */
    showCreateDialog() {
        const name = prompt('Set list name:', '');
        if (name && name.trim()) {
            this.create(name.trim());
            this.renderSetListOverview();
            this.app.showToast(`Set list "${name.trim()}" created`, 'success', 2000);
        }
    }

    /* =====================================================================
     * SET LIST NAVIGATION — Next/previous within active set list
     * ===================================================================== */

    /**
     * Get navigation info for a song within the active set list.
     * @param {string} songId Current song ID
     * @returns {{ prev: object|null, next: object|null, position: number, total: number }|null}
     */
    getNavigation(songId) {
        if (!this.activeSetListId) return null;

        const list = this.getById(this.activeSetListId);
        if (!list) return null;

        const index = list.songs.findIndex(s => s.id === songId);
        if (index < 0) return null;

        return {
            prev: index > 0 ? list.songs[index - 1] : null,
            next: index < list.songs.length - 1 ? list.songs[index + 1] : null,
            position: index + 1,
            total: list.songs.length,
            listName: list.name,
        };
    }

    /**
     * Render set list navigation bar on a song page (if active).
     * Called by router after song page loads.
     */
    renderSongNavigation() {
        const songPage = document.querySelector('.page-song');
        if (!songPage) return;

        const songId = songPage.dataset.songId;
        if (!songId) return;

        const nav = this.getNavigation(songId);
        if (!nav) return;

        /* Remove existing set list nav if present */
        document.getElementById('setlist-song-nav')?.remove();

        const navEl = document.createElement('div');
        navEl.id = 'setlist-song-nav';
        navEl.className = 'alert alert-primary d-flex align-items-center justify-content-between mb-3';
        navEl.setAttribute('role', 'navigation');
        navEl.setAttribute('aria-label', 'Set list navigation');

        navEl.innerHTML = `
            <div>
                ${nav.prev
                    ? `<a href="/song/${this.escapeHtml(nav.prev.id)}" data-navigate="song"
                         class="btn btn-sm btn-outline-primary">
                         <i class="fa-solid fa-chevron-left me-1" aria-hidden="true"></i> Prev
                       </a>`
                    : `<button class="btn btn-sm btn-outline-secondary" disabled>
                         <i class="fa-solid fa-chevron-left me-1" aria-hidden="true"></i> Prev
                       </button>`
                }
            </div>
            <small class="text-center">
                <i class="fa-solid fa-list-ol me-1" aria-hidden="true"></i>
                ${this.escapeHtml(nav.listName)} — ${nav.position}/${nav.total}
            </small>
            <div>
                ${nav.next
                    ? `<a href="/song/${this.escapeHtml(nav.next.id)}" data-navigate="song"
                         class="btn btn-sm btn-outline-primary">
                         Next <i class="fa-solid fa-chevron-right ms-1" aria-hidden="true"></i>
                       </a>`
                    : `<button class="btn btn-sm btn-outline-secondary" disabled>
                         Next <i class="fa-solid fa-chevron-right ms-1" aria-hidden="true"></i>
                       </button>`
                }
            </div>`;

        /* Insert at the top of the song page (after breadcrumb) */
        const breadcrumb = songPage.querySelector('nav[aria-label="Breadcrumb"]');
        if (breadcrumb) {
            breadcrumb.after(navEl);
        } else {
            songPage.prepend(navEl);
        }
    }

    /* =====================================================================
     * EXPORT & SHARE
     * ===================================================================== */

    /**
     * Export a set list as formatted text.
     * @param {object} list Set list object
     */
    exportAsText(list) {
        const text = this.formatSetListText(list);
        const blob = new Blob([text], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${list.name.replace(/[^a-zA-Z0-9 ]/g, '')}.txt`;
        a.click();
        URL.revokeObjectURL(url);
        this.app.showToast('Set list exported', 'success', 2000);
    }

    /**
     * Copy set list to clipboard as text.
     * @param {object} list Set list object
     */
    async copyToClipboard(list) {
        const text = this.formatSetListText(list);
        try {
            await navigator.clipboard.writeText(text);
            this.app.showToast('Set list copied to clipboard', 'success', 2000);
        } catch {
            /* Fallback for older browsers */
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
            this.app.showToast('Set list copied to clipboard', 'success', 2000);
        }
    }

    /**
     * Format a set list as plain text for export/copy.
     * @param {object} list
     * @returns {string}
     */
    formatSetListText(list) {
        let text = `${list.name}\n${'='.repeat(list.name.length)}\n\n`;
        list.songs.forEach((song, i) => {
            text += `${i + 1}. ${song.title} (${song.songbook} #${song.number})\n`;
        });
        text += `\n— Generated by iHymns`;
        return text;
    }

    /* =====================================================================
     * UTILITY HELPERS
     * ===================================================================== */

    /**
     * Generate a simple unique ID.
     * @returns {string}
     */
    generateId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2, 5);
    }

    /**
     * Format an ISO date string for display.
     * @param {string} iso ISO date string
     * @returns {string}
     */
    formatDate(iso) {
        try {
            return new Date(iso).toLocaleDateString(undefined, {
                day: 'numeric', month: 'short', year: 'numeric'
            });
        } catch {
            return '';
        }
    }

    /* =====================================================================
     * PRINT SET LIST (#113)
     * ===================================================================== */

    /**
     * Print a set list with full lyrics for all songs.
     * Fetches each song's page content, builds a print document, and prints.
     * @param {object} list The set list { id, name, createdAt, songs }
     */
    async printSetList(list) {
        if (!list.songs || list.songs.length === 0) {
            this.app.showToast('No songs to print.', 'warning');
            return;
        }

        this.app.showToast('Preparing print layout...', 'info', 2000);

        /* Fetch all song pages in parallel */
        const songPages = await Promise.all(
            list.songs.map(async (song) => {
                try {
                    const url = `${this.app.config.apiUrl}?page=song&id=${encodeURIComponent(song.id)}`;
                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) return null;
                    return { song, html: await response.text() };
                } catch {
                    return null;
                }
            })
        );

        /* Build print document */
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        if (!printWindow) {
            this.app.showToast('Pop-up blocked. Please allow pop-ups for printing.', 'danger');
            return;
        }

        const dateStr = new Date().toLocaleDateString(undefined, {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });

        /* Build running order summary */
        const orderSummary = list.songs.map((song, i) =>
            `<tr><td class="pe-3 text-muted">${i + 1}.</td><td>${this.escapeHtml(song.title)}</td><td class="text-muted">${this.escapeHtml(song.songbook)} #${song.number || '?'}</td></tr>`
        ).join('');

        /* Build individual song pages */
        let songPagesHtml = '';
        songPages.forEach((page, index) => {
            if (!page) return;
            songPagesHtml += `
                <div class="print-song-page">
                    <div class="print-song-header">
                        <span class="print-song-order">${index + 1}</span>
                        <h2>${this.escapeHtml(page.song.title)}</h2>
                        <p class="print-song-meta">${this.escapeHtml(page.song.songbook)} #${page.song.number || '?'}</p>
                    </div>
                    <div class="print-song-content">${page.html}</div>
                </div>`;
        });

        printWindow.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>${this.escapeHtml(list.name)} — iHymns Set List</title>
    <style>
        @page { margin: 2cm; size: A4; }
        * { box-sizing: border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; font-size: 12pt; line-height: 1.6; color: #000; margin: 0; padding: 0; }

        /* Cover page */
        .print-cover { text-align: center; page-break-after: always; padding-top: 30vh; }
        .print-cover h1 { font-size: 24pt; margin-bottom: 0.5em; }
        .print-cover .print-date { font-size: 14pt; color: #666; margin-bottom: 2em; }
        .print-cover .print-song-count { font-size: 12pt; color: #999; }

        /* Running order */
        .print-order { page-break-after: always; }
        .print-order h2 { font-size: 16pt; margin-bottom: 1em; border-bottom: 2px solid #000; padding-bottom: 0.3em; }
        .print-order table { width: 100%; border-collapse: collapse; }
        .print-order td { padding: 0.3em 0.5em; border-bottom: 1px solid #eee; font-size: 11pt; }

        /* Individual songs */
        .print-song-page { page-break-before: always; }
        .print-song-header { margin-bottom: 1.5em; border-bottom: 2px solid #333; padding-bottom: 0.5em; }
        .print-song-order { display: inline-block; width: 2em; height: 2em; line-height: 2em; text-align: center; background: #333; color: #fff; border-radius: 50%; font-weight: bold; float: left; margin-right: 0.75em; margin-top: 0.2em; }
        .print-song-header h2 { font-size: 18pt; margin: 0 0 0.2em; }
        .print-song-meta { color: #666; font-size: 10pt; margin: 0; }

        /* Song content overrides */
        .print-song-content .breadcrumb,
        .print-song-content .song-navigation,
        .print-song-content .d-flex.flex-wrap.gap-2,
        .print-song-content .card-song-header { display: none !important; }
        .print-song-content .song-lyrics { margin: 0; }
        .print-song-content .lyric-component { margin-bottom: 1em; }
        .print-song-content .lyric-label { font-weight: bold; font-size: 10pt; color: #666; margin-bottom: 0.3em; }
        .print-song-content .lyric-line { margin: 0.1em 0; }
        .print-song-content .song-copyright { font-size: 9pt; color: #999; margin-top: 1em; }

        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <!-- Cover Page -->
    <div class="print-cover">
        <h1>${this.escapeHtml(list.name)}</h1>
        <p class="print-date">${dateStr}</p>
        <p class="print-song-count">${list.songs.length} song${list.songs.length !== 1 ? 's' : ''}</p>
    </div>

    <!-- Running Order -->
    <div class="print-order">
        <h2>Running Order</h2>
        <table>${orderSummary}</table>
    </div>

    <!-- Songs -->
    ${songPagesHtml}
</body>
</html>`);

        printWindow.document.close();

        /* Wait for content to load then trigger print */
        printWindow.onload = () => {
            printWindow.print();
        };
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
