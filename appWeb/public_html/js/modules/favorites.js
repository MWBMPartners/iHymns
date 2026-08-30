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
/* #1786 Option B — favourites is an ARRAY-mode list-sort surface: it is
   JS-rendered from an in-memory array (localStorage), not server-rendered
   DOM, so there is nothing for list-sort.js's DOM-reorder mode to grab hold
   of. wireListSortControl() + getListSort() are the array-mode contract;
   the actual comparator is the SAME shared core admin tables use (C1). */
import { wireListSortControl, getListSort } from './list-sort.js';
import { multiKeyCompareMissingLast, titleSortKey } from '../utils/sort-compare.js';

/** Allowed list-sort keys for the `favorites` surface — matches
 *  favorites.php's $listSortOptions exactly (types, not labels, matter
 *  here: sort_compare.js reads the TYPE below, not the server option). */
const FAVORITES_SORT_TYPES = { added: 'date', title: 'text', book: 'text' };

export class Favorites {
    constructor(app) {
        this.app = app;
        /** @type {string} localStorage key for favourites */
        this.storageKey = STORAGE_FAVORITES;
        /** @type {boolean} Whether select mode is active (#119) */
        this.selectMode = false;
        /** @type {Set<string>} Currently selected song IDs (#119) */
        this.selectedIds = new Set();
        /** @type {boolean} Authoritative 'replace' pushes are armed only once
         *  the first-login MERGE reconcile has hydrated localStorage with the
         *  server's union (set by UserAuth.triggerFavoritesSync). Until then a
         *  per-edit replace could push an empty/partial list and DELETE the
         *  user's other-device favourites (review #1). */
        this._syncReady = false;
        /** @type {boolean} Set when getAll() hit a JSON parse error — a
         *  corrupt cache must never drive an authoritative replace (review #2). */
        this._loadError = false;
    }

    /** Initialise — nothing to do on startup */
    init() {}

    /**
     * Get all favourite song IDs and metadata.
     * @returns {Array<{id: string, title: string, songbook: string, number: number, addedAt: string}>}
     */
    getAll() {
        try {
            const v = JSON.parse(localStorage.getItem(this.storageKey)) || [];
            this._loadError = false;
            return v;
        } catch {
            /* Corrupt cache — flag it so _scheduleSync won't turn the empty
               fallback into an authoritative replace that wipes the server. */
            this._loadError = true;
            return [];
        }
    }

    /**
     * Save the favourites array to localStorage (the client cache) and, for
     * signed-in users, schedule a DB-first push.
     *
     * @param {Array}   favorites
     * @param {object}  [opts]
     * @param {boolean} [opts.sync=true] When false, persist locally WITHOUT
     *   scheduling a server push — used by the sync-result handlers that
     *   have just written the server's own merged copy back.
     */
    saveAll(favorites, { sync = true } = {}) {
        localStorage.setItem(this.storageKey, JSON.stringify(favorites));
        this.app.syncStorage(this.storageKey);
        if (sync) this._scheduleSync();
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
        let added;

        if (index >= 0) {
            /* Remove from favourites */
            favorites.splice(index, 1);
            this.saveAll(favorites);
            added = false;
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
            added = true;
        }

        /* saveAll() schedules the debounced server push (see _scheduleSync). */
        return added;
    }

    /**
     * Debounced authoritative push of the current favourites to the server
     * (#338, WS-G #1019). 1.5 s debounce so a flurry of toggles (bulk-edit
     * dialog) collapses into one POST. Sends {id, tags} objects in 'replace'
     * mode so a removed favourite — and per-song tag edits — propagate
     * across devices. Offline path is handled inside syncFavorites (queue +
     * replay with the latest local state on reconnect).
     */
    _scheduleSync() {
        if (!this.app?.userAuth?.isLoggedIn?.()) return;
        /* Hold off destructive 'replace' pushes until the first-login merge
           reconcile has hydrated the cache (review #1) — the reconcile itself
           unions any edits made meanwhile and then flushes this. */
        if (!this._syncReady) return;
        clearTimeout(this._syncTimer);
        this._syncTimer = setTimeout(async () => {
            const all = this.getAll();
            if (this._loadError) return; /* corrupt cache — never replace (review #2) */
            const favs = all.map(f => ({ id: f.id, tags: f.tags || [] }));
            /* #1649 — absorb rather than discard: the server may have kept
               favourites this push didn't mention (another device's newer
               additions, or the tail beyond the cap). The absorb helper merges
               them back and advances the sync watermark. */
            const res = await this.app.userAuth.syncFavorites(favs, 'replace');
            this.app.userAuth._absorbFavoritesSync(res);
        }, 1500);
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
     * Get the raw per-user custom-tag pool (the names the user has invented,
     * independent of which favourites currently carry them). The DB-first
     * sync layer reads this; getAllTags() above is the display union.
     * @returns {string[]}
     */
    getCustomTags() {
        try {
            const custom = JSON.parse(localStorage.getItem(STORAGE_CUSTOM_TAGS)) || [];
            return Array.isArray(custom) ? custom : [];
        } catch {
            return [];
        }
    }

    /**
     * Replace the entire custom-tag pool (used by the sync layer when the
     * server's authoritative list comes back). Local-only write — does NOT
     * re-push to the server (the caller already has the server's copy).
     * @param {string[]} tags
     */
    saveCustomTags(tags) {
        const clean = [...new Set((tags || []).filter(t => typeof t === 'string' && t.trim() !== ''))].sort();
        localStorage.setItem(STORAGE_CUSTOM_TAGS, JSON.stringify(clean));
        this.app.syncStorage?.(STORAGE_CUSTOM_TAGS);
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
            this.app.syncStorage?.(STORAGE_CUSTOM_TAGS);
            this._scheduleTagSync();
        }
    }

    /**
     * Debounced DB-first push of the custom-tag pool (WS-G #1019). Uses MERGE
     * mode: a tag ADD never needs to delete anything, so union is correct and
     * closes the first-login race entirely (review #4) — no _syncReady gate
     * needed because merge can't wipe. A future tag-REMOVAL UI would instead
     * send 'replace' gated on _syncReady. Self-gates on auth + offline-queues
     * inside syncCustomTags.
     */
    _scheduleTagSync() {
        if (!this.app?.userAuth?.isLoggedIn?.()) return;
        clearTimeout(this._tagSyncTimer);
        this._tagSyncTimer = setTimeout(() => {
            this.app.userAuth.syncCustomTags(this.getCustomTags(), 'merge');
        }, 1500);
    }

    /**
     * Show a tag editor modal for a favourite song.
     * @param {string} songId
     * @param {string} songTitle
     */
    async editTags(songId, songTitle) {
        const currentTags = this.getTags(songId);
        const allTags = [...new Set([...Favorites.COMMON_TAGS, ...this.getAllTags()])].sort();

        /* A6 fix (a11y audit 2026-08-30, WCAG 2.1.1 Keyboard / 4.1.2
           Name-Role-Value): each pill used to be
           `<label><input type="checkbox" class="d-none">…</label>` —
           `d-none` is `display:none`, which pulls an element out of BOTH
           the tab order and the accessibility tree. Keyboard users could
           never reach these checkboxes at all, and a screen reader saw
           plain unlabelled text with no role or state. The fix is
           Bootstrap's own "checkbox toggle button" recipe (already used
           elsewhere in this codebase, e.g. settings-language-filter.js):
           a REAL sibling `<input class="btn-check">` (hidden only via
           `clip:rect(0,0,0,0)`, so it stays focusable/tabbable) plus a
           `<label for>` that native browser behaviour already wires up
           for both mouse and keyboard (Tab to it, Space/Enter toggles it,
           just like any other checkbox). See:
           https://getbootstrap.com/docs/5.3/forms/checks-radios/#checkbox-toggle-buttons
           `tagIdSeq` gives each pill — including ones added later via the
           custom-tag box below — a unique id to pair with its label,
           since tag TEXT isn't guaranteed unique-as-an-id (spaces, etc). */
        let tagIdSeq = 0;
        const tagHtml = allTags.map(tag => {
            const checked = currentTags.includes(tag) ? 'checked' : '';
            const escaped = escapeHtml(tag);
            const id = `tag-toggle-${tagIdSeq++}`;
            return `<input type="checkbox" class="btn-check tag-checkbox" id="${id}" value="${escaped}" autocomplete="off" ${checked}>
                    <label class="btn btn-sm ${checked ? 'btn-primary' : 'btn-outline-secondary'} rounded-pill tag-toggle-btn" for="${id}">${escaped}</label>`;
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
                                   placeholder="Add custom tag..." maxlength="30"
                                   aria-label="Add custom tag">
                            <button type="button" class="btn btn-outline-primary" id="tag-custom-add"
                                    aria-label="Add tag">
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
        /* ^ A13 fix: #tag-custom-add's only content is an aria-hidden icon
           (no visible text) — aria-label names it for screen readers.
           A19 fix: #tag-custom-input's only name was its placeholder,
           which most screen readers announce weakly (or not as a real
           accessible name at all) — aria-label gives it a proper one. */

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);

        /* A6 fix, continued — the checkbox is now a real sibling input, so
           the native `<label for>` association already forwards both
           clicks and keyboard activation (Space/Enter while focused) to
           it; we just need to keep the pill's visible colour (primary vs
           outline) in sync whenever the checkbox's state changes, from
           WHATEVER caused it (mouse OR keyboard). */
        modal.querySelectorAll('.tag-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                const lbl = modal.querySelector(`label[for="${cb.id}"]`);
                if (lbl) lbl.className = `btn btn-sm ${cb.checked ? 'btn-primary' : 'btn-outline-secondary'} rounded-pill tag-toggle-btn`;
            });
        });

        /* Add custom tag */
        const addCustom = () => {
            const input = modal.querySelector('#tag-custom-input');
            const tag = input.value.trim();
            if (!tag) return;
            /* Check if already exists.
               F-3 fix (2026-08-30 correctness review): this used to splice
               `escapeHtml(tag)` into a CSS attribute-selector string —
               `escapeHtml` turns a `"` into the HTML entity `&quot;`, which
               is the RIGHT escaping for putting text inside markup but the
               WRONG escaping for putting text inside a CSS selector (which
               instead wants a `"` backslash-escaped as `\"`). So a custom
               tag containing a literal `"` never matched the pill already
               rendered for it and silently got re-added as a visible
               duplicate. Comparing the raw `.value` DOM property directly
               (over the live NodeList) sidesteps the mismatch entirely —
               a property read is never re-parsed as markup OR as selector
               syntax, so there is no escaping rule to get wrong. Mirrors
               this same file's existing preference for DOM-API construction
               over selector string-building (see the comment a few lines
               below, at the custom-tag <input> creation). */
            const existing = [...modal.querySelectorAll('.tag-checkbox')].find(cb => cb.value === tag);
            if (existing) {
                existing.checked = true;
                const existingLbl = modal.querySelector(`label[for="${existing.id}"]`);
                if (existingLbl) existingLbl.className = 'btn btn-sm btn-primary rounded-pill tag-toggle-btn';
            } else {
                const container = modal.querySelector('.d-flex.flex-wrap');
                /* A6 fix — same btn-check pattern as the initial render
                   above: a real sibling input + a `label[for]`, not a
                   `d-none` checkbox wrapped by its label. */
                const id = `tag-toggle-${tagIdSeq++}`;
                /* DOM-API construction rather than innerHTML-with-
                   escape, so CodeQL has nothing to trace and a future
                   edit can't accidentally drop the escapeHtml call
                   (#504). */
                const tagCheckbox = document.createElement('input');
                tagCheckbox.type = 'checkbox';
                tagCheckbox.className = 'btn-check tag-checkbox';
                tagCheckbox.id = id;
                tagCheckbox.autocomplete = 'off';
                tagCheckbox.value = tag;
                tagCheckbox.checked = true;

                const newLabel = document.createElement('label');
                newLabel.className = 'btn btn-sm btn-primary rounded-pill tag-toggle-btn';
                newLabel.setAttribute('for', id);
                newLabel.appendChild(document.createTextNode(tag));

                tagCheckbox.addEventListener('change', () => {
                    newLabel.className = `btn btn-sm ${tagCheckbox.checked ? 'btn-primary' : 'btn-outline-secondary'} rounded-pill tag-toggle-btn`;
                });

                container.appendChild(tagCheckbox);
                container.appendChild(newLabel);
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

    /** Clear all favourites (and propagate the clear to the server). */
    clearAll() {
        localStorage.removeItem(this.storageKey);
        this.app.syncStorage?.(this.storageKey);
        /* Authoritative empty push so the cleared state reaches the other
           devices (WS-G #1019) — a local-only clear would just repopulate
           from the server on the next pull. getAll() now returns [] so
           syncFavorites([], 'replace') deletes the server rows. */
        this._scheduleSync();
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
        const sortControl = document.querySelector('[data-list-sort-surface="favorites"]');

        /* Reset select mode on reload */
        this.selectMode = false;
        this.selectedIds.clear();

        const favorites = this.getAll();

        if (favorites.length === 0) {
            /* Show empty state — nothing to sort either. */
            if (listEl) listEl.innerHTML = '';
            if (emptyEl) emptyEl.classList.remove('d-none');
            if (countBadge) countBadge.classList.add('d-none');
            if (clearAllBtn) clearAllBtn.classList.add('d-none');
            if (selectToggle) selectToggle.classList.add('d-none');
            if (batchToolbar) batchToolbar.classList.add('d-none');
            if (sortControl) sortControl.classList.add('d-none');
            return;
        }

        /* Hide empty state, show list */
        if (emptyEl) emptyEl.classList.add('d-none');
        if (countBadge) countBadge.classList.remove('d-none');
        if (sortControl) sortControl.classList.remove('d-none');
        if (countEl) countEl.textContent = `${favorites.length} song${favorites.length !== 1 ? 's' : ''}`;

        /* #1786 — apply the viewer's saved sort (array mode: sort a COPY,
           never the stored array itself — this is a display order only,
           never persisted back into the favourites list). Default (no
           saved spec) leaves insertion order — date-added ASC — exactly as
           it always rendered. */
        const sortSpec = getListSort('favorites', Object.keys(FAVORITES_SORT_TYPES));
        if (sortSpec.length) {
            const levels = sortSpec.map((s) => ({ key: s.key, type: FAVORITES_SORT_TYPES[s.key] || 'text', direction: s.dir }));
            const decorated = favorites.map((fav, i) => ({
                fav,
                i,
                vals: {
                    added: fav.addedAt ?? null,
                    title: titleSortKey(fav.title),
                    book: `${(fav.songbook || '').toLowerCase()} ${String(fav.number ?? 0).padStart(6, '0')}`,
                },
            }));
            decorated.sort((a, b) => {
                const cmp = multiKeyCompareMissingLast(levels, a.vals, b.vals);
                return cmp !== 0 ? cmp : a.i - b.i; /* stable */
            });
            favorites.length = 0;
            favorites.push(...decorated.map((d) => d.fav));
        }

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

        /* Render favourite items.
           A20 fix (a11y audit 2026-08-30): this row used to carry
           role="listitem", which demoted the <a>'s native "link" role for
           screen readers (an explicit ARIA role always wins over the
           element's own). The container in favorites.php was switched from
           role="list" to role="group" for the matching reason — see the
           long comment there for why a per-row wrapper element (the other
           textbook fix) isn't safe here (it would break this list's
           Bootstrap border/radius CSS and app.css's nth-child stagger
           animation, both of which need these <a> rows to stay DIRECT
           children of #favorites-list). */
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
                   data-tags="${tagsData}">
                    <input type="checkbox" class="form-check-input fav-select-check d-none me-2"
                           data-song-id="${escapeHtml(fav.id)}"
                           aria-label="Select ${escapeHtml(toTitleCase(fav.title))}"
                           onclick="event.stopPropagation()">
                    <span class="song-number-badge" data-songbook="${escapeHtml(fav.songbook)}">${escapeHtml(String(fav.number ?? ''))}</span>
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

        /* #1786 — wire the Sort ▾ control. Idempotent (wireListSortControl
           no-ops once the control's own data-list-sort-wired flag is set),
           so calling it on every reload — including the tag-edit and
           clear-all reloads above — is safe; onChange just re-runs this
           same method, which already resets select-mode at the top. */
        wireListSortControl('favorites', () => this.loadFavoritesList());
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
            /* Construct the button contents via DOM APIs rather than
               innerHTML so CodeQL's "DOM text reinterpreted as HTML"
               rule is happy, and we're immune to any future edit
               that accidentally introduces a dynamic string into the
               ternary (#504). */
            toggle.replaceChildren();
            const icon = document.createElement('i');
            icon.className = this.selectMode
                ? 'fa-solid fa-xmark me-1'
                : 'fa-solid fa-check-double me-1';
            icon.setAttribute('aria-hidden', 'true');
            toggle.appendChild(icon);
            toggle.appendChild(document.createTextNode(
                ' ' + (this.selectMode ? 'Cancel' : 'Select')
            ));
        }

        checkboxes.forEach(cb => {
            cb.classList.toggle('d-none', !this.selectMode);
            cb.checked = false;
        });
        hearts.forEach(el => el.classList.toggle('d-none', this.selectMode));
        chevrons.forEach(el => el.classList.toggle('d-none', this.selectMode));

        if (toolbar) toolbar.classList.toggle('d-none', !this.selectMode);

        /* In select mode, clicks toggle checkboxes instead of
           navigating. We strip `href` to disable native anchor
           navigation. To restore, we REBUILD from `data-song-id`
           (escapeHtml'd at row creation in loadFavoritesList) rather
           than round-tripping the previous href through a data
           attribute — the round-trip pattern trips CodeQL's "DOM text
           reinterpreted as HTML" rule even when the source is
           trusted (#958). */
        listItems.forEach(item => {
            if (this.selectMode) {
                item.removeAttribute('href');
                item.addEventListener('click', this._handleSelectClick);
            } else {
                const songId = item.dataset.songId;
                if (songId) {
                    /* dataset.songId is read back as a string, but
                       it was set via escapeHtml() in loadFavoritesList,
                       so any HTML-special chars are already entities.
                       Anchor-href reads strings as URL-text, not HTML,
                       so the `/song/<id>` shape is safe. */
                    item.setAttribute('href', '/song/' + encodeURIComponent(songId));
                }
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
