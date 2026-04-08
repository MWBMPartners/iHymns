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

import { toTitleCase } from '../utils/text.js';
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { STORAGE_SETLISTS, STORAGE_OWNER_ID } from '../constants.js';

export class SetList {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key */
        this.storageKey = STORAGE_SETLISTS;

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
                             data-setlist-id="${escapeHtml(list.id)}" role="button" tabindex="0">
                            <div class="flex-grow-1">
                                <strong>${escapeHtml(list.name)}</strong>
                                <small class="text-muted d-block">
                                    ${list.songs.length} song${list.songs.length !== 1 ? 's' : ''}
                                    &middot; Created ${this.formatDate(list.createdAt)}
                                </small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-setlist"
                                    data-setlist-id="${escapeHtml(list.id)}"
                                    aria-label="Delete set list ${escapeHtml(list.name)}">
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
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const id = btn.dataset.setlistId;
                    const ok = await this.app.showConfirm('Delete this set list?', {
                        title: 'Delete Set List',
                        okText: 'Delete',
                        okClass: 'btn-danger',
                    });
                    if (ok) {
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
                             data-song-id="${escapeHtml(song.id)}" data-index="${index}"
                             draggable="true">
                            <span class="text-muted fw-bold me-1" style="min-width:24px">${index + 1}.</span>
                            <span class="song-number-badge" data-songbook="${escapeHtml(song.songbook)}">${song.number || '?'}</span>
                            <div class="flex-grow-1">
                                <a href="/song/${escapeHtml(song.id)}" data-navigate="song"
                                   class="text-decoration-none">${escapeHtml(toTitleCase(song.title))}${verifiedBadge(song)}</a>
                                <small class="text-muted d-block">${escapeHtml(song.songbook)}</small>
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
                                    data-song-id="${escapeHtml(song.id)}"
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
                <h2 class="h5 mb-0">${escapeHtml(list.name)}</h2>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="setlist-rename-btn"
                            aria-label="Rename set list" title="Rename">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="setlist-share-btn"
                            aria-label="Share set list" title="Share">
                        <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
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

        /* Share (#147) */
        container.querySelector('#setlist-share-btn')?.addEventListener('click', () => {
            this.shareSetlist(listId);
        });

        /* Rename */
        container.querySelector('#setlist-rename-btn')?.addEventListener('click', async () => {
            const name = await this.app.showPrompt('Rename set list:', list.name, {
                title: 'Rename Set List',
                okText: 'Rename',
            });
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
                        data-setlist-id="${escapeHtml(l.id)}">
                    <strong>${escapeHtml(l.name)}</strong>
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
                        <p class="text-muted small">Adding: <strong>${escapeHtml(toTitleCase(song.title))}</strong></p>
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
        modal.querySelector('#create-new-setlist-btn')?.addEventListener('click', async () => {
            bsModal.hide();
            const name = await this.app.showPrompt('Set list name:', '', {
                title: 'New Set List',
                okText: 'Create',
                placeholder: 'e.g. Sunday Morning Worship',
            });
            if (name && name.trim()) {
                const newList = this.create(name.trim());
                this.addSong(newList.id, song);
                this.app.showToast(`Created "${name.trim()}" and added song`, 'success', 2000);
            }
        });

        /* Clean up on close */
        modal.addEventListener('hidden.bs.modal', () => modal.remove());
    }

    /**
     * Show the create set list dialog (from set list page).
     */
    async showCreateDialog() {
        const name = await this.app.showPrompt('Set list name:', '', {
            title: 'New Set List',
            okText: 'Create',
            placeholder: 'e.g. Sunday Morning Worship',
        });
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
                    ? `<a href="/song/${escapeHtml(nav.prev.id)}" data-navigate="song"
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
                ${escapeHtml(nav.listName)} — ${nav.position}/${nav.total}
            </small>
            <div>
                ${nav.next
                    ? `<a href="/song/${escapeHtml(nav.next.id)}" data-navigate="song"
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
     * SHAREABLE SET LISTS (#147, #155 server-side persistent storage)
     *
     * Shared setlists are stored server-side as private JSON files in
     * appWeb/data_share/setlist_json/. Each gets a short hex ID (8 chars).
     * The owner is identified by a random UUID stored in localStorage.
     * Legacy base64-encoded URLs (pre-#155) are still supported as fallback.
     * ===================================================================== */

    /**
     * Get or create a persistent owner UUID for this browser.
     * Used to identify who created a shared setlist so only they can update it.
     *
     * @returns {string} UUID string
     */
    getOwnerId() {
        let id = localStorage.getItem(STORAGE_OWNER_ID);
        if (!id) {
            id = crypto.randomUUID?.() || (
                /* Fallback for older browsers: generate UUID v4 */
                'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                    const r = (Math.random() * 16) | 0;
                    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
                })
            );
            localStorage.setItem(STORAGE_OWNER_ID, id);
        }
        return id;
    }

    /**
     * Generate a shareable URL by saving the setlist to the server.
     * Returns a short, clean URL like /setlist/shared/a1b2c3d4.
     *
     * If the setlist already has a shareId (from a previous share), it
     * sends an update request so the same link reflects the latest content.
     *
     * @param {string} listId Local setlist ID
     * @returns {Promise<string|null>} Shareable URL or null on failure
     */
    async generateShareLink(listId) {
        const list = this.getById(listId);
        if (!list) return null;

        const payload = {
            name: list.name,
            songs: list.songs.map(s => s.id),
            owner: this.getOwnerId(),
        };

        /* If this list was shared before, include the server-side ID for update */
        if (list.shareId) {
            payload.id = list.shareId;
        }

        try {
            const response = await fetch(`${this.app.config.apiUrl}?action=setlist_share`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                const err = await response.json().catch(() => ({}));
                console.error('Share failed:', err.error || response.statusText);
                return null;
            }

            const result = await response.json();

            /* Store the server-side share ID on the local setlist for future updates.
             * Re-fetch all lists, find this one, update it, and save back. */
            if (result.id) {
                const allLists = this.getAll();
                const target = allLists.find(l => l.id === listId);
                if (target) {
                    target.shareId = result.id;
                    this.saveAll(allLists);
                }
            }

            return window.location.origin + result.url;
        } catch (error) {
            console.error('Share request failed:', error);
            return null;
        }
    }

    /**
     * Share a set list via the Web Share API or copy-to-clipboard fallback.
     * Posts the setlist to the server first to get a persistent short URL.
     *
     * @param {string} listId Set list ID
     */
    async shareSetlist(listId) {
        const list = this.getById(listId);
        if (!list) return;

        /* Show a brief loading indicator */
        this.app.showToast('Creating share link...', 'info', 1500);

        const shareUrl = await this.generateShareLink(listId);
        if (!shareUrl) {
            this.app.showToast('Failed to create share link. Please try again.', 'danger', 3000);
            return;
        }

        /* Try native Web Share API first.
         * IMPORTANT: Only pass title + url (no text). Some platforms (macOS, iOS)
         * concatenate text and url when the user chooses "Copy", resulting in
         * a broken link. Omitting text ensures the clipboard only contains
         * the clean URL. */
        if (navigator.share) {
            try {
                await navigator.share({
                    title: list.name + ' — iHymns Set List',
                    url: shareUrl,
                });
                return;
            } catch (error) {
                if (error.name === 'AbortError') return;
                /* Fall through to clipboard copy */
            }
        }

        /* Fallback: copy bare URL to clipboard */
        try {
            await navigator.clipboard.writeText(shareUrl);
            this.app.showToast('Share link copied to clipboard', 'success', 3000);
        } catch {
            /* Last resort fallback for older browsers */
            const textarea = document.createElement('textarea');
            textarea.value = shareUrl;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
            this.app.showToast('Share link copied to clipboard', 'success', 3000);
        }
    }

    /**
     * Parse legacy base64-encoded shared set list data from a URL.
     * Kept for backwards compatibility with links created before #155.
     *
     * @param {string} encodedData Base64-encoded JSON string
     * @returns {{ name: string, songIds: string[], version: number }|null}
     */
    parseLegacySharedSetlist(encodedData) {
        try {
            const json = decodeURIComponent(escape(atob(encodedData)));
            const data = JSON.parse(json);

            if (!data || !data.n || !Array.isArray(data.s)) {
                return null;
            }

            return {
                name: data.n,
                songIds: data.s,
                version: data.v || 1,
            };
        } catch {
            return null;
        }
    }

    /**
     * Fetch shared setlist data from the server by short ID.
     *
     * @param {string} shareId 8-character hex ID
     * @returns {Promise<{ name: string, songIds: string[] }|null>}
     */
    async fetchSharedSetlist(shareId) {
        try {
            const url = `${this.app.config.apiUrl}?action=setlist_get&id=${encodeURIComponent(shareId)}`;
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) return null;

            const data = await response.json();
            if (!data || !data.name || !Array.isArray(data.songs)) return null;

            return {
                name: data.name,
                songIds: data.songs,
            };
        } catch {
            return null;
        }
    }

    /**
     * Import a shared set list into the user's local set lists.
     * Fetches song metadata from the API, then creates a new local set list.
     *
     * @param {{ name: string, songIds: string[] }} sharedData Parsed shared data
     * @returns {object} The newly created set list
     */
    async importSharedSetlist(sharedData) {
        const newList = this.create(sharedData.name);

        /* Fetch song metadata for each song ID in parallel */
        const songResults = await Promise.all(
            sharedData.songIds.map(async (songId) => {
                try {
                    const url = `${this.app.config.apiUrl}?action=song_data&id=${encodeURIComponent(songId)}`;
                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) return null;
                    const json = await response.json();
                    if (json.song) {
                        return {
                            id: json.song.id || songId,
                            title: json.song.title || '',
                            songbook: json.song.songbook || '',
                            number: json.song.number || 0,
                        };
                    }
                    return null;
                } catch {
                    /* If fetch fails, add with just the ID */
                    return { id: songId, title: songId, songbook: '', number: 0 };
                }
            })
        );

        /* Add songs to the new list in order */
        for (const song of songResults) {
            if (song) {
                this.addSong(newList.id, song);
            }
        }

        return this.getById(newList.id);
    }

    /**
     * Initialise the shared set list page.
     * Detects whether the URL contains a short server-side ID (8 hex chars)
     * or a legacy base64 string, then loads data accordingly.
     *
     * @param {string} shareData Short ID or legacy base64 string from the URL
     */
    async initSharedSetListPage(shareData) {
        const loadingEl = document.getElementById('shared-setlist-loading');
        const errorEl = document.getElementById('shared-setlist-error');
        const contentEl = document.getElementById('shared-setlist-content');

        if (!loadingEl || !errorEl || !contentEl) return;

        /* Determine if this is a server-side short ID or legacy base64 data.
         * Short IDs are 8 hex characters; legacy base64 strings are longer
         * and contain characters outside the hex range. */
        let sharedData = null;
        const isShortId = /^[a-f0-9]{6,16}$/.test(shareData);

        if (isShortId) {
            /* Fetch from server-side storage (#155) */
            sharedData = await this.fetchSharedSetlist(shareData);
        } else {
            /* Legacy base64 fallback (pre-#155) */
            sharedData = this.parseLegacySharedSetlist(shareData);
        }

        if (!sharedData) {
            loadingEl.classList.add('d-none');
            errorEl.classList.remove('d-none');
            return;
        }

        /* Populate the header */
        const titleEl = document.getElementById('shared-setlist-title');
        const countEl = document.getElementById('shared-setlist-count');
        const pluralEl = document.getElementById('shared-setlist-plural');

        if (titleEl) titleEl.textContent = sharedData.name;
        if (countEl) countEl.textContent = sharedData.songIds.length;
        if (pluralEl) pluralEl.textContent = sharedData.songIds.length !== 1 ? 's' : '';

        /* Render song list (initially with just IDs, then enrich with metadata) */
        const songsContainer = document.getElementById('shared-setlist-songs');
        if (songsContainer) {
            songsContainer.innerHTML = sharedData.songIds.map((songId, index) => `
                <div class="list-group-item d-flex align-items-center gap-2 shared-song-item"
                     data-song-id="${escapeHtml(songId)}">
                    <span class="text-muted fw-bold me-1" style="min-width:24px">${index + 1}.</span>
                    <span class="song-number-badge" data-songbook="">...</span>
                    <div class="flex-grow-1">
                        <a href="/song/${escapeHtml(songId)}" data-navigate="song"
                           class="text-decoration-none shared-song-title">${escapeHtml(songId)}</a>
                        <small class="text-muted d-block shared-song-meta">Loading...</small>
                    </div>
                </div>
            `).join('');
        }

        /* Show content, hide loading */
        loadingEl.classList.add('d-none');
        contentEl.classList.remove('d-none');

        /* Enrich song items with metadata from the API */
        this.enrichSharedSongItems(sharedData.songIds);

        /* Bind import buttons */
        const importHandler = async () => {
            const imported = await this.importSharedSetlist(sharedData);
            if (imported) {
                this.app.showToast(`Set list "${sharedData.name}" imported with ${imported.songs.length} songs`, 'success', 3000);
                this.app.router.navigate('/setlist');
            } else {
                this.app.showToast('Failed to import set list', 'danger', 3000);
            }
        };

        document.getElementById('shared-setlist-import-btn')?.addEventListener('click', importHandler);
        document.getElementById('shared-setlist-import-btn-bottom')?.addEventListener('click', importHandler);
    }

    /**
     * Enrich shared song list items with metadata fetched from the API.
     * Updates song titles, numbers, and songbook badges in-place.
     *
     * @param {string[]} songIds Array of song IDs
     */
    async enrichSharedSongItems(songIds) {
        await Promise.all(
            songIds.map(async (songId) => {
                try {
                    const url = `${this.app.config.apiUrl}?action=song_data&id=${encodeURIComponent(songId)}`;
                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) return;
                    const json = await response.json();
                    if (!json.song) return;

                    const item = document.querySelector(`.shared-song-item[data-song-id="${CSS.escape(songId)}"]`);
                    if (!item) return;

                    const titleEl = item.querySelector('.shared-song-title');
                    const metaEl = item.querySelector('.shared-song-meta');
                    const badge = item.querySelector('.song-number-badge');

                    if (titleEl) titleEl.textContent = toTitleCase(json.song.title) || songId;
                    if (metaEl) metaEl.textContent = json.song.songbook || '';
                    if (badge) {
                        badge.textContent = json.song.number || '?';
                        badge.dataset.songbook = json.song.songbook || '';
                    }
                } catch {
                    /* Non-critical — leave placeholder text */
                }
            })
        );
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
            text += `${i + 1}. ${toTitleCase(song.title)} (${song.songbook} #${song.number})\n`;
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
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
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
            `<tr><td class="pe-3 text-muted">${i + 1}.</td><td>${escapeHtml(toTitleCase(song.title))}</td><td class="text-muted">${escapeHtml(song.songbook)} #${song.number || '?'}</td></tr>`
        ).join('');

        /* Build individual song pages */
        let songPagesHtml = '';
        songPages.forEach((page, index) => {
            if (!page) return;
            songPagesHtml += `
                <div class="print-song-page">
                    <div class="print-song-header">
                        <span class="print-song-order">${index + 1}</span>
                        <h2>${escapeHtml(toTitleCase(page.song.title))}</h2>
                        <p class="print-song-meta">${escapeHtml(page.song.songbook)} #${page.song.number || '?'}</p>
                    </div>
                    <div class="print-song-content">${page.html}</div>
                </div>`;
        });

        printWindow.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>${escapeHtml(list.name)} — iHymns Set List</title>
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
        <h1>${escapeHtml(list.name)}</h1>
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
}
