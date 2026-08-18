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
import { shortTag, fullLabel, typeColor, typeTextColor } from '../utils/components.js';
import { STORAGE_SETLISTS, STORAGE_SETLISTS_DELETED, STORAGE_OWNER_ID, STORAGE_AUTH_TOKEN, STORAGE_PLAYLIST_CONTEXT, SHARE_ID_RE, songbookLabel, songbookFullName, SONGBOOK_NAMES, EVT_AUTH_CHANGED } from '../constants.js';
import { apiFetch } from '../utils/api-client.js';
import { announce } from '../utils/announce.js';
import { loadTemplates, pickPrintTemplate, fetchSong, renderTemplateBodyHtml, printCss, applyCustomLayout, downloadPrintPdf, printUsageContextFor, promptForCopies, pdfFilenameFor } from './print.js';

/**
 * May the viewer WRITE to this shared set list? (#1638 / #1698)
 *
 * ELI5: ask the server's answer, and only work it out ourselves if the server
 * is an older one that did not send one.
 *
 * The server states `canWrite` explicitly because the decision now has TWO
 * inputs — the collaborator's own permission AND the owner's account state —
 * and a client that re-derived it from `permission` alone would draw edit
 * buttons for a list `setlist_collab_update` will refuse with a 403. That is
 * rule #35's failure shape: two places computing one policy with nothing making
 * them agree, and the copy that draws the buttons being the wrong one.
 *
 * The `permission` fallback is not a second opinion — it is what to do when the
 * server did not express one at all. A response cached by the service worker
 * before this shipped has no `canWrite` key, and treating a missing key as
 * `false` would silently strip edit access from every collaborator until the
 * cache turned over.
 *
 * @param {{permission?:string, canWrite?:boolean}} shared One `shared` entry.
 * @returns {boolean}
 */
function sharedCanWrite(shared) {
    if (!shared) return false;
    if (typeof shared.canWrite === 'boolean') return shared.canWrite;
    return shared.permission === 'edit';
}

/**
 * Row markup for a STAGED-COPY set-list editor (#1791).
 *
 * ELI5: draws the list of songs, with move-up/move-down/remove buttons when
 * the viewer is allowed to change the order.
 *
 * EXTRACTED so the email-collaborator detail view (`renderSharedSetListDetail()`)
 * and the public token-edit surface (`initSharedSetListPage()`'s edit branch)
 * share ONE row template instead of two forks (modularity rule — a third copy
 * of this markup, one per write-grant model, is exactly the drift #1216/#1638
 * already learned to extract away).
 *
 * @param {Array<{id:string,title?:string,songbook?:string,number?:number}>} songs
 * @param {boolean} canEdit Render the move/remove controls at all.
 * @returns {string} HTML for the list-group rows (caller supplies the
 *          surrounding `<div id="…">` / `list-group` wrapper).
 */
function sharedSetlistRowsHtml(songs, canEdit) {
    if (songs.length === 0) {
        return `<div class="text-center text-muted py-4"><p>This set list has no songs yet.</p></div>`;
    }
    return songs.map((song, index) => `
        <div class="list-group-item d-flex align-items-center gap-2 shared-detail-song"
             data-song-id="${escapeHtml(String(song.id))}" data-index="${index}">
            <span class="text-muted fw-bold me-1" style="min-width:24px">${index + 1}.</span>
            <span class="song-number-badge" data-songbook="${escapeHtml(String(song.songbook || ''))}">${song.number ?? ''}</span>
            <div class="flex-grow-1">
                <a href="/song/${escapeHtml(String(song.id))}" data-navigate="song"
                   class="text-decoration-none">${escapeHtml(toTitleCase(song.title || String(song.id)))}</a>
                <small class="text-muted d-block">${songbookLabel(song.songbook)}</small>
            </div>
            ${canEdit ? `
            <button type="button" class="btn btn-sm btn-outline-secondary btn-shared-up"
                    data-index="${index}" ${index === 0 ? 'disabled' : ''} aria-label="Move up">
                <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-shared-down"
                    data-index="${index}" ${index === songs.length - 1 ? 'disabled' : ''} aria-label="Move down">
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-shared-remove"
                    data-index="${index}" aria-label="Remove from set list">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>` : ''}
        </div>
    `).join('');
}

/**
 * Wire the move-up/move-down/remove buttons `sharedSetlistRowsHtml()` just
 * rendered INTO `container` — the other half of the extraction above.
 *
 * Mutates `songs` IN PLACE (splice), then calls `opts.onMutate()` so the
 * CALLER decides what "after a change" means: `renderSharedSetListDetail()`
 * re-renders the whole detail view and pushes to `setlist_collab_update`;
 * the token-edit surface re-renders just the rows and pushes to
 * `setlist_token_update`. Two different write endpoints, one row-interaction
 * contract (rule #35 — the SHAPE is shared, the endpoint is the caller's).
 *
 * @param {?HTMLElement} container Element `sharedSetlistRowsHtml()` was
 *        rendered into (its `.btn-shared-*` buttons are bound here).
 * @param {Array<object>} songs MUTABLE staged-copy array.
 * @param {{canEdit:boolean, onMutate:() => void}} opts
 */
function bindSharedSetlistRowControls(container, songs, opts) {
    if (!container || !opts || !opts.canEdit) return;
    const move = (from, to) => {
        if (to < 0 || to >= songs.length) return;
        const [moved] = songs.splice(from, 1);
        songs.splice(to, 0, moved);
        opts.onMutate();
    };
    container.querySelectorAll('.btn-shared-up').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const i = parseInt(btn.dataset.index, 10);
            move(i, i - 1);
        });
    });
    container.querySelectorAll('.btn-shared-down').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const i = parseInt(btn.dataset.index, 10);
            move(i, i + 1);
        });
    });
    container.querySelectorAll('.btn-shared-remove').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const i = parseInt(btn.dataset.index, 10);
            if (Number.isNaN(i)) return;
            songs.splice(i, 1);
            opts.onMutate();
        });
    });
}

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

        /** @type {boolean} Authoritative 'replace' pushes are armed only once
         *  the first-login MERGE reconcile has hydrated localStorage (set by
         *  UserAuth.triggerSetlistSync) — until then a per-edit replace could
         *  push an empty/partial list and DELETE other-device setlists
         *  (review #1). */
        this._syncReady = false;
        /** @type {boolean} Set when getAll() hit a JSON parse error — a
         *  corrupt cache must never drive an authoritative replace (review #2). */
        this._loadError = false;
    }

    /** Initialise — re-render the sync bar whenever auth state flips. */
    init() {
        document.addEventListener(EVT_AUTH_CHANGED, () => {
            /* Only re-render if the sync bar is currently in the DOM. */
            if (document.getElementById('setlist-sync-bar')) {
                this.renderSyncBar();
            }
        });

        /* #1533 — ← / → move through the active set list. Bound once here,
           not per bar render, so repeated song navigation cannot leak
           listeners. */
        this._bindPlaylistKeys();

        /* Restore activeSetListId from a persisted OWN-list context after a
           reload, so custom arrangements keep applying. getNavigation() reads
           the context directly and needs no restoration; this is only for
           applyCustomArrangement()'s benefit. */
        const ctx = this.getPlaylistContext();
        if (ctx && ctx.source === 'own' && ctx.sourceId) {
            this.activeSetListId = ctx.sourceId;
        }
    }

    /* =====================================================================
     * CRUD OPERATIONS
     * ===================================================================== */

    /**
     * Get all set lists.
     * @returns {Array}
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
     * Save all set lists to localStorage (the client cache) and, for
     * signed-in users, schedule a DB-first push.
     *
     * @param {Array}   lists
     * @param {object}  [opts]
     * @param {boolean} [opts.sync=true] When false, persist locally WITHOUT
     *   scheduling a server push. Used by the sync-result handlers
     *   (triggerSetlistSync / offline drain) that have just written the
     *   server's own merged copy back — re-pushing it would be redundant.
     */
    saveAll(lists, { sync = true } = {}) {
        localStorage.setItem(this.storageKey, JSON.stringify(lists));
        this.app.syncStorage(this.storageKey);
        if (sync) this._scheduleSync();
    }

    /**
     * Debounced authoritative push of the current setlists to the server
     * (WS-F #1018). Mirrors favourites' `_scheduleSync`: 1.5 s debounce so a
     * flurry of edits (drag-reorder, bulk add) collapses into one POST. Uses
     * 'replace' mode so a setlist deleted locally is deleted server-side too
     * — the offline path is handled inside syncSetlists (queue + replay).
     */
    _scheduleSync() {
        if (!this.app?.userAuth?.isLoggedIn?.()) return;
        /* Hold off destructive 'replace' pushes until the first-login merge
           reconcile has hydrated the cache (review #1) — the reconcile unions
           any edits made meanwhile and then flushes this. */
        if (!this._syncReady) return;
        clearTimeout(this._syncTimer);
        this._syncTimer = setTimeout(async () => {
            const all = this.getAll();
            if (this._loadError) return; /* corrupt cache — never replace (review #2) */
            /* #1649 — absorb the response instead of discarding it. The server
               may have kept rows this push didn't mention (another device's
               newer additions, or the whole tail if we exceeded the cap), and
               the absorb helper both merges them back in and advances the sync
               watermark. Dropping the result on the floor left the local cache
               permanently behind the server. */
            const res = await this.app.userAuth.syncSetlists(all, 'replace');
            this.app.userAuth._absorbSetlistSync(res);
        }, 1500);
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
     *
     * #1661 — the deletion is QUEUED for the server as an explicit
     * instruction before the local removal, not merely implied by the set
     * list vanishing from the next payload. Under sync protocol 2 the server
     * no longer reads absence as deletion at all, so without this queue entry
     * the delete would never propagate and the next reconcile would hand the
     * set list straight back.
     *
     * Queue first, remove second: saveAll() schedules the sync, so a queue
     * write after it could lose a race with the debounce timer on a slow
     * device and publish a payload that deletes nothing.
     *
     * @param {string} listId
     */
    delete(listId) {
        this._queuePendingDelete(listId);
        const lists = this.getAll().filter(l => l.id !== listId);
        this.saveAll(lists);
        if (this.activeSetListId === listId) {
            this.activeSetListId = null;
        }
    }

    /**
     * Set (or clear) a set list's optional expiry (#1661).
     *
     * ELI5: give a set list a use-by date, or take one away.
     *
     * `null` means "never expires" — the owner's stated default, and what
     * every set list has unless someone deliberately dates it. The value is
     * an absolute instant in UTC, because the server compares it against its
     * own UTC clock; sending a local wall-clock time would fire the expiry
     * early or late by the device's offset.
     *
     * The server is the sole authority on the actual conversion — this only
     * records the intent, and the set list keeps working normally until a
     * sync observes that the moment has passed.
     *
     * @param {string} listId
     * @param {?string} expiresAt 'YYYY-MM-DD HH:MM:SS' UTC, or null to clear.
     * @returns {boolean} True if a matching set list was updated.
     */
    setExpiry(listId, expiresAt) {
        const lists = this.getAll();
        const list = lists.find(l => l.id === listId);
        if (!list) return false;
        list.expiresAt = expiresAt || null;
        this.saveAll(lists);
        return true;
    }

    /* =====================================================================
     * EXPLICIT-DELETE QUEUE (#1661)
     *
     * The client half of sync protocol 2. The server distinguishes a
     * protocol-2 client by the mere PRESENCE of a `deleted` key, so
     * getPendingDeletes() is called on EVERY sync and its empty array is
     * just as meaningful as a populated one — see UserAuth.syncSetlists.
     * ===================================================================== */

    /**
     * The ids deleted on this device that the server has not yet acknowledged.
     * @returns {string[]}
     */
    getPendingDeletes() {
        try {
            const v = JSON.parse(localStorage.getItem(STORAGE_SETLISTS_DELETED)) || [];
            return Array.isArray(v) ? v.filter(id => typeof id === 'string' && id) : [];
        } catch {
            /* A corrupt queue must not wedge every future sync, and unlike the
               setlists cache there is nothing here to salvage — the worst case
               is one un-propagated deletion the user can repeat. */
            return [];
        }
    }

    /**
     * Add an id to the pending-delete queue (idempotent).
     * @param {string} listId
     */
    _queuePendingDelete(listId) {
        if (!listId) return;
        const pending = this.getPendingDeletes();
        if (pending.includes(listId)) return;
        pending.push(listId);
        try { localStorage.setItem(STORAGE_SETLISTS_DELETED, JSON.stringify(pending)); } catch { /* quota — the delete still applied locally */ }
    }

    /**
     * Drop the ids a sync has just had accepted.
     *
     * Removes ONLY the ids that were actually sent, never the whole queue:
     * a deletion made while the request was in flight is still pending and
     * must survive to be announced on the next sync.
     *
     * @param {string[]} acknowledged Ids included in the successful request.
     */
    clearPendingDeletes(acknowledged) {
        if (!Array.isArray(acknowledged) || acknowledged.length === 0) return;
        const done = new Set(acknowledged);
        const remaining = this.getPendingDeletes().filter(id => !done.has(id));
        try { localStorage.setItem(STORAGE_SETLISTS_DELETED, JSON.stringify(remaining)); } catch { /* quota */ }
    }

    /**
     * Remove local copies of set lists the server reports as tombstoned.
     *
     * ELI5: another device deleted these (or they expired) — take them off
     * this device too.
     *
     * Returns the pruned array instead of writing it, so the caller can fold
     * this into the single saveAll() the absorb path already performs — two
     * writes would render the overview twice and could interleave with an
     * in-flight edit.
     *
     * @param {Array}  lists      The local set lists.
     * @param {Array}  tombstones Server tombstones ([{id, deletedAt, reason}]).
     * @returns {Array} The lists with tombstoned ids removed.
     */
    applyTombstones(lists, tombstones) {
        if (!Array.isArray(tombstones) || tombstones.length === 0) return lists;
        const dead = new Set(
            tombstones.map(t => (t && typeof t.id === 'string') ? t.id : null).filter(Boolean)
        );
        if (dead.size === 0) return lists;
        /* Disarm the getAll()-backed navigation fallback, which would
           otherwise call getById() on a set list that is about to vanish.
           The sessionStorage PLAYLIST CONTEXT is deliberately left alone: it
           is a self-contained snapshot of song ids (which is why it can drive
           a SHARED set list that was never in getAll() at all), and yanking a
           leader out of mid-service navigation because a co-leader deleted
           the list on their phone would be worse than letting this tab's
           session finish. It is session-scoped, so it does not outlive the
           tab. */
        if (this.activeSetListId && dead.has(this.activeSetListId)) {
            this.activeSetListId = null;
        }
        return (lists || []).filter(l => l && !dead.has(l.id));
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

        const entry = {
            id: song.id,
            title: song.title || '',
            songbook: song.songbook || '',
            number: song.number || 0,
        };

        /* Preserve custom arrangement if provided */
        if (song.arrangement && Array.isArray(song.arrangement)) {
            entry.arrangement = song.arrangement;
        }

        list.songs.push(entry);
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
        this.renderSyncBar();
        this.renderSetListOverview();

        /* #1638 — honour a `?shared=<ownerId>:<setlistId>` deep link, which is
           the ActionUrl the collaboration-invite notification carries. Reading
           it from window.location.search mirrors the /link route's handling of
           ?user_code= in router.js: parseRoute() only ever looks at path
           SEGMENTS, so a query string has to be read here.

           Deliberately best-effort — if the id no longer resolves (invite
           revoked, setlist deleted) the user simply lands on the normal
           overview rather than seeing an error about a link they did not type. */
        try {
            const target = new URLSearchParams(window.location.search || '').get('shared');
            if (target) this._openSharedByKey(target);
        } catch { /* malformed query string — the overview is a fine landing place */ }
    }

    /**
     * Render the sync bar at the top of the setlist page.
     * Shows sign-in prompt (if not logged in) or sync status (if logged in).
     */
    renderSyncBar() {
        const bar = document.getElementById('setlist-sync-bar');
        if (!bar) return;

        const auth = this.app.userAuth;

        /* Check auth state: use UserAuth instance if available, otherwise
           fall back to checking localStorage directly (#262) */
        const hasToken = auth
            ? auth.isLoggedIn()
            : !!localStorage.getItem(STORAGE_AUTH_TOKEN);

        if (hasToken) {
            const user = auth ? auth.getUser() : null;
            bar.className = 'alert alert-success py-2 px-3 d-flex align-items-center justify-content-between mb-3';
            bar.innerHTML = `
                <small>
                    <i class="fa-solid fa-cloud-arrow-up me-1" aria-hidden="true"></i>
                    Signed in as <strong>${escapeHtml(user?.display_name || user?.username || '')}</strong>
                    — set lists sync across devices
                </small>
                <button type="button" class="btn btn-sm btn-outline-success" id="setlist-sync-now-btn">
                    <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i> Sync Now
                </button>`;

            bar.querySelector('#setlist-sync-now-btn')?.addEventListener('click', async () => {
                if (!auth) return;
                this.app.showToast('Syncing...', 'info', 1500);
                await auth.triggerSetlistSync();
                this.renderSetListOverview();
            });
        } else {
            bar.className = 'alert alert-info py-2 px-3 d-flex align-items-center justify-content-between mb-3';
            bar.innerHTML = `
                <small>
                    <i class="fa-solid fa-user me-1" aria-hidden="true"></i>
                    Sign in to sync set lists across devices
                </small>
                <button type="button" class="btn btn-sm btn-outline-primary" id="setlist-login-btn">
                    Sign In
                </button>`;

            bar.querySelector('#setlist-login-btn')?.addEventListener('click', () => {
                if (auth) auth.showAuthModal('login');
            });
        }
    }

    /**
     * Render the set list overview (list of all set lists).
     */
    renderSetListOverview() {
        const container = document.getElementById('setlist-container');
        if (!container) return;

        /* Hide the per-setlist schedule card on the overview — it's a
           detail-view concern. */
        const schedCard = document.getElementById('setlist-schedule-card');
        if (schedCard) schedCard.style.display = 'none';

        this._renderUpcomingScheduledSetlists();

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
                                <strong>${escapeHtml(list.name)}</strong>${this.expiryBadge(list)}
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

        /* #1638 — "Shared with me". Appended AFTER the own-lists markup above,
           because that markup is written with container.innerHTML and would
           blow away anything already inside. Async and non-blocking: the
           user's own lists render from localStorage instantly and the shared
           section fills in when the network answers. */
        this._renderSharedWithMe(container);
    }

    /* =====================================================================
     * COLLABORATION — SETLISTS SHARED WITH ME (#1638)
     *
     * ELI5: the lists other people have let you see or help edit.
     *
     * THE BUG THIS CLOSES. Collaboration shipped write-only: an owner could
     * invite you and pick "view" or "edit", and you would never find out. The
     * `setlist_collab_shared_with_me` endpoint existed and worked, and had
     * ZERO callers on web, Apple and Android alike. This section is the first
     * caller it has ever had.
     *
     * THE SYNC BOUNDARY — the most important thing in this block.
     *
     * Shared setlists are held in memory ONLY (`this._sharedLists`). They are
     * never written to localStorage['ihymns_setlists'], because that array is
     * what `_scheduleSync()` posts to `user_setlists_sync` in 'replace' mode.
     * A shared list that leaked into it would be pushed back as if this user
     * owned it, forking a second `tblUserSetlists` row under a different
     * UserId — and that fork would then pass the owner-check in
     * `setlist_collab_invite`, letting a collaborator re-share a list that is
     * not theirs. Keeping them out of the store makes that impossible by
     * construction rather than by remembering to filter.
     *
     * Consequence, accepted deliberately: shared setlists do not appear
     * offline. Your own lists do (localStorage); somebody else's need the
     * network. That is the correct trade — the alternative caches other
     * people's data in the exact array that gets synced back as your own.
     * ===================================================================== */

    /**
     * Fetch and render the "Shared with me" section.
     *
     * @param {HTMLElement} container The #setlist-container element.
     * @private
     */
    _renderSharedWithMe(container) {
        if (!container) return;

        /* Anonymous users have nothing shared with them and the endpoint would
           just 401 — skip the request entirely rather than firing one that is
           guaranteed to fail. */
        const auth = this.app.userAuth;
        const loggedIn = auth ? auth.isLoggedIn() : !!localStorage.getItem(STORAGE_AUTH_TOKEN);
        if (!loggedIn) return;

        apiFetch('/api?action=setlist_collab_shared_with_me', { credentials: 'same-origin' })
            .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
            .then(data => {
                const shared = Array.isArray(data.shared) ? data.shared : [];
                this._sharedLists = shared;   // in-memory only — see the note above
                if (shared.length === 0) return;

                /* Guard against a double-render: renderSetListOverview() can be
                   called again (Sync Now, auth change) while this request is in
                   flight, and two sections would otherwise stack up. */
                document.getElementById('setlist-shared-with-me')?.remove();

                const section = document.createElement('div');
                section.id = 'setlist-shared-with-me';
                section.className = 'mt-4';
                section.innerHTML = `
                    <h2 class="h6 text-muted mb-2">
                        <i class="fa-solid fa-users me-2" aria-hidden="true"></i>Shared with me
                    </h2>
                    <div class="list-group">
                        ${shared.map(s => this._sharedRowHtml(s)).join('')}
                    </div>`;
                container.appendChild(section);

                section.querySelectorAll('.shared-setlist-item').forEach(item => {
                    const open = () => {
                        const entry = this._findShared(item.dataset.ownerId, item.dataset.setlistId);
                        if (entry) this.renderSharedSetListDetail(entry);
                    };
                    item.addEventListener('click', open);
                    item.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
                });
            })
            .catch(() => {
                /* 401 / offline / feature unavailable — render nothing. An
                   empty section is the honest outcome; an error box about
                   somebody else's setlists would be noise on a page whose main
                   content loaded fine. */
            });
    }

    /**
     * One row of the "Shared with me" list.
     *
     * The owner's name and the permission are both in the row's ACCESSIBLE
     * NAME, not just in visual badges — a screen-reader user needs to know
     * whose list this is and whether they can change it before they open it.
     * Same pattern as the language / unofficial-songbook badges elsewhere.
     *
     * @param {{ownerId:number, ownerName:string, setlistId:string, permission:string, name:string, songs:Array, canWrite?:boolean, locked?:boolean}} s
     * @returns {string} HTML
     * @private
     */
    _sharedRowHtml(s) {
        const count = Array.isArray(s.songs) ? s.songs.length : 0;
        const canEdit = sharedCanWrite(s);
        const locked = s.locked === true;
        /* Three states now, not two (#1698): a locked owner's list is neither
           "can edit" nor plain "view only" — the difference matters, because a
           collaborator WAS granted edit and would otherwise assume the button
           had simply moved. */
        const permLabel = locked ? 'Read-only' : (canEdit ? 'Can edit' : 'View only');
        const badgeCls = locked ? 'text-bg-warning' : (canEdit ? 'text-bg-success' : 'text-bg-secondary');
        return `
            <div class="list-group-item list-group-item-action d-flex align-items-center gap-3 shared-setlist-item"
                 data-owner-id="${escapeHtml(String(s.ownerId))}"
                 data-setlist-id="${escapeHtml(s.setlistId)}"
                 role="button" tabindex="0"
                 aria-label="${escapeHtml(s.name)}, shared by ${escapeHtml(s.ownerName)}, ${permLabel}">
                <div class="flex-grow-1">
                    <strong>${escapeHtml(s.name)}</strong>
                    <span class="badge ${badgeCls} ms-2"
                          style="font-size:0.65rem">${permLabel}</span>
                    <small class="text-muted d-block">
                        ${count} song${count !== 1 ? 's' : ''}
                        &middot; Shared by ${escapeHtml(s.ownerName)}
                    </small>
                </div>
                <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
            </div>`;
    }

    /**
     * Look up a shared entry from the in-memory list.
     *
     * Keyed on BOTH ids: `setlistId` is client-generated and unique only per
     * user, so two different owners can legitimately share lists with the same
     * id string. Matching on the setlist id alone would open the wrong one.
     *
     * @param {string|number} ownerId
     * @param {string} setlistId
     * @returns {object|null}
     * @private
     */
    _findShared(ownerId, setlistId) {
        const list = Array.isArray(this._sharedLists) ? this._sharedLists : [];
        return list.find(s => String(s.ownerId) === String(ownerId)
            && String(s.setlistId) === String(setlistId)) || null;
    }

    /**
     * Open a shared setlist from an `ownerId:setlistId` key.
     *
     * Used by the notification deep link. Fetches the shared list if the page
     * has not already loaded it, so the link works on a cold page load.
     *
     * @param {string} key `<ownerId>:<setlistId>`
     * @private
     */
    _openSharedByKey(key) {
        const sep = key.indexOf(':');
        if (sep <= 0) return;
        const ownerId   = key.slice(0, sep);
        /* The server rawurlencode()s the setlist id into the link, so decode
           it back before comparing. decodeURIComponent throws on a malformed
           %-sequence, hence the try. */
        let setlistId = key.slice(sep + 1);
        try { setlistId = decodeURIComponent(setlistId); } catch { /* use as-is */ }

        const open = () => {
            const entry = this._findShared(ownerId, setlistId);
            if (entry) this.renderSharedSetListDetail(entry);
        };

        if (Array.isArray(this._sharedLists)) { open(); return; }
        apiFetch('/api?action=setlist_collab_shared_with_me', { credentials: 'same-origin' })
            .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
            .then(data => {
                this._sharedLists = Array.isArray(data.shared) ? data.shared : [];
                open();
            })
            .catch(() => { /* link no longer resolves — stay on the overview */ });
    }

    /**
     * Render the detail view of a setlist somebody else shared.
     *
     * Deliberately a SEPARATE method from renderSetListDetail() rather than a
     * mode flag on it. That method's every control writes straight to
     * localStorage via moveSong/removeSong/rename — the exact store that must
     * never hold shared data. Threading a "don't persist" flag through all of
     * them is the kind of change where one missed branch silently reintroduces
     * the sync hazard; a separate renderer cannot make that mistake.
     *
     * PERMISSION IS ENFORCED SERVER-SIDE. `setlist_collab_update` resolves the
     * caller's level from the database on every write and refuses a 'view'
     * collaborator with a 403. The read-only rendering below is an affordance,
     * not a security control — hiding a button is not a permission.
     *
     * @param {object} shared Entry from setlist_collab_shared_with_me.
     */
    renderSharedSetListDetail(shared) {
        const container = document.getElementById('setlist-container');
        if (!container || !shared) return;

        /* Work on a COPY. Edits are staged locally and pushed explicitly, so
           an abandoned reorder never half-writes to the owner's list. */
        const songs = Array.isArray(shared.songs) ? shared.songs.map(s => ({ ...s })) : [];
        const canEdit = sharedCanWrite(shared);
        const locked = shared.locked === true;

        /* #1791 — row markup EXTRACTED to sharedSetlistRowsHtml() (module-scope,
           near sharedCanWrite() above), shared with the public token-edit
           surface in initSharedSetListPage() (modularity rule — one row
           template, not two forks). The wrapper div/id are kept here since the
           arm-on-tap listener below still queries `#shared-setlist-songs`. */
        const rowsHtml = songs.length === 0
            ? sharedSetlistRowsHtml(songs, canEdit)
            : `<div class="list-group" id="shared-setlist-songs">${sharedSetlistRowsHtml(songs, canEdit)}</div>`;

        container.innerHTML = `
            <div class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="shared-back-btn">
                    <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back
                </button>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <h2 class="h5 mb-0">${escapeHtml(shared.name)}</h2>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="shared-copy-btn"
                            aria-label="Copy set list to clipboard" title="Copy">
                        <i class="fa-solid fa-copy" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="shared-print-btn"
                            aria-label="Print set list" title="Print">
                        <i class="fa-solid fa-print" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="shared-activate-btn"
                            aria-label="Use this set list for navigation" title="Use this set list">
                        <i class="fa-solid fa-play me-1" aria-hidden="true"></i> Use
                    </button>
                </div>
            </div>
            <p class="text-muted small mb-3">
                Shared by <strong>${escapeHtml(shared.ownerName)}</strong>
                &middot; <span class="badge ${locked ? 'text-bg-warning' : (canEdit ? 'text-bg-success' : 'text-bg-secondary')}"
                               style="font-size:0.65rem">${locked ? 'Read-only' : (canEdit ? 'Can edit' : 'View only')}</span>
                &middot; ${songs.length} song${songs.length !== 1 ? 's' : ''}
            </p>
            ${locked ? `
            <div class="alert alert-warning py-2 px-3 small" role="note">
                <i class="fa-solid fa-lock me-1" aria-hidden="true"></i>
                ${escapeHtml(shared.lockReason || 'The owner’s account is unavailable. This is read-only.')}
            </div>` : (!canEdit ? `
            <div class="alert alert-secondary py-2 px-3 small" role="note">
                <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>
                You have view-only access. Ask ${escapeHtml(shared.ownerName)} for edit access to make changes.
            </div>` : '')}
            ${rowsHtml}
            <div id="shared-save-status" class="small mt-2" aria-live="polite"></div>`;

        container.querySelector('#shared-back-btn')?.addEventListener('click', () => {
            this.renderSetListOverview();
        });

        /* Read-only affordances work at BOTH permission levels — a view-only
           collaborator can still print the running order and follow along. */
        const asList = { id: shared.setlistId, name: shared.name, createdAt: '', songs };
        container.querySelector('#shared-copy-btn')?.addEventListener('click', () => {
            this.copyToClipboard(asList);
        });
        container.querySelector('#shared-print-btn')?.addEventListener('click', () => {
            this.printSetList(asList);
        });

        /* Arm playback. Reuses the EXISTING `source: 'shared'` vocabulary from
           #1533 rather than inventing a third notion of shared: that field's
           only functional job is "this is not one of my local lists, so don't
           try to apply a local custom arrangement", which is exactly true
           here. `sourceId` is informational for a shared context — it is only
           ever read back when source === 'own'. */
        const armShared = () => {
            if (songs.length === 0) return;
            const titles = {};
            for (const s of songs) { if (s && s.id) titles[s.id] = s.title || ''; }
            this.setPlaylistContext({
                songIds:  songs.map(s => s.id).filter(Boolean),
                titles,
                name:     shared.name,
                source:   'shared',
                sourceId: String(shared.setlistId || ''),
            });
        };
        container.querySelector('#shared-activate-btn')?.addEventListener('click', () => {
            armShared();
            this.app.showToast(`Set list "${shared.name}" activated.`, 'success', 3000);
        });
        /* Arm on tap, matching the own-list behaviour from #1533.
           Delegated onto the SONGS LIST rather than onto `container`: this
           method re-renders itself after every edit, and `container` survives
           that (only its innerHTML is replaced), so a listener bound there
           would stack up one copy per edit. `#shared-setlist-songs` is
           re-created each render, taking its listener with it. */
        container.querySelector('#shared-setlist-songs')?.addEventListener('click', (e) => {
            const link = e.target instanceof HTMLElement
                ? e.target.closest('.shared-detail-song a[data-navigate="song"]')
                : null;
            if (link) armShared();
        });

        if (!canEdit) return;

        /* ---- Edit controls. Every one of these ends in a server round-trip
           through setlist_collab_update, which re-checks the permission. The
           local `songs` array is only ever the OPTIMISTIC view. ---- */
        const push = () => {
            const status = document.getElementById('shared-save-status');
            if (status) status.innerHTML = '<span class="text-muted">Saving…</span>';
            return apiFetch('/api?action=setlist_collab_update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    ownerId:   shared.ownerId,
                    setlistId: shared.setlistId,
                    songs,
                }),
            })
                .then(r => r.json().then(j => ({ ok: r.ok, data: j })))
                .then(res => {
                    if (!res.ok) {
                        /* Re-render from the SERVER's copy so the UI never keeps
                           showing a change the server refused (a view-only
                           collaborator whose row was downgraded mid-session is
                           the case that matters). */
                        if (status) {
                            status.innerHTML = `<span class="text-danger">${escapeHtml(res.data.error || 'Save failed.')}</span>`;
                        }
                        return false;
                    }
                    /* Keep the cached entry in step so Back → re-open shows the
                       saved order without another fetch. Still memory-only. */
                    shared.songs = Array.isArray(res.data.songs) ? res.data.songs : songs;
                    if (status) status.innerHTML = '<span class="text-success">Saved.</span>';
                    return true;
                })
                .catch(() => {
                    if (status) status.innerHTML = '<span class="text-danger">Save failed — check your connection.</span>';
                    return false;
                });
        };

        /* #1791 — move/remove wiring EXTRACTED to bindSharedSetlistRowControls()
           (module-scope, near sharedSetlistRowsHtml() above), the other half of
           the row-template extraction. onMutate re-renders the whole detail view
           (so the header's song count + badge stay in step) then pushes. */
        bindSharedSetlistRowControls(container.querySelector('#shared-setlist-songs'), songs, {
            canEdit,
            onMutate: () => {
                shared.songs = songs;
                this.renderSharedSetListDetail(shared);
                push();
            },
        });
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
                            <span class="song-number-badge" data-songbook="${escapeHtml(song.songbook)}">${song.number ?? ''}</span>
                            <div class="flex-grow-1">
                                <a href="/song/${escapeHtml(song.id)}" data-navigate="song"
                                   class="text-decoration-none">${escapeHtml(toTitleCase(song.title))}${verifiedBadge(song)}</a>
                                <small class="text-muted d-block">
                                    ${songbookLabel(song.songbook)}${song.arrangement ? ` <span class="badge bg-warning bg-opacity-25 text-warning-emphasis arrangement-badge" style="font-size:0.6rem" title="${song.arrangementLabel ? escapeHtml(song.arrangementLabel) : 'Custom arrangement: ' + song.arrangement.length + ' component' + (song.arrangement.length !== 1 ? 's' : '')}">Custom Arr.</span>` : ''}
                                </small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-warning btn-arrange-song"
                                    data-song-id="${escapeHtml(song.id)}" data-index="${index}"
                                    aria-label="Customise arrangement" title="Arrange">
                                <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                            </button>
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
                <h2 class="h5 mb-0">${escapeHtml(list.name)}${this.expiryBadge(list)}</h2>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="setlist-rename-btn"
                            aria-label="Rename set list" title="Rename">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="setlist-expiry-btn"
                            aria-label="Set or clear this set list's expiry date" title="Expiry">
                        <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
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
                    <button type="button" class="btn btn-outline-secondary" id="setlist-pdf-btn"
                            aria-label="Save set list as PDF" title="Save as PDF">
                        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="setlist-template-btn"
                            aria-label="Save this set list as a reusable template" title="Save as template">
                        <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="setlist-activate-btn"
                            aria-label="Activate set list for navigation" title="Use this set list">
                        <i class="fa-solid fa-play me-1" aria-hidden="true"></i> Use
                    </button>
                </div>
            </div>
            <p class="text-muted small">${list.songs.length} song${list.songs.length !== 1 ? 's' : ''}</p>
            ${songsHtml}`;

        /* Scheduling card + collaboration controls (#398). Both are
           no-ops for anonymous users — the API endpoints 401 and this
           call also silently gates on authUser. */
        this._renderSetlistSchedule(listId);
        this._renderSetlistCollaborators(container, listId);

        /* Service plan (#301 / #1671 F4). Dynamically imported rather than a
           static import at the top of this file, for the same reason router.js
           imports its page modules that way: this screen is one of many, and a
           set list with no plan (the overwhelming majority) should not pay for
           the module at all. */
        this._renderServicePlan(container, listId);

        /* Save as template (#301 / #1671 F4). */
        container.querySelector('#setlist-template-btn')?.addEventListener('click', () => {
            import('./setlist-templates.js')
                .then(m => m.saveSetlistAsTemplate(this.getById(listId)))
                .catch(err => console.error('[SetList] setlist-templates import failed:', err));
        });

        /* Back button */
        container.querySelector('#setlist-back-btn')?.addEventListener('click', () => {
            this.renderSetListOverview();
        });

        /* Share (#147, dialog #1791) */
        container.querySelector('#setlist-share-btn')?.addEventListener('click', () => {
            this.renderShareDialog(listId);
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

        /* Optional expiry (#1661) */
        container.querySelector('#setlist-expiry-btn')?.addEventListener('click', () => {
            this.showExpiryDialog(listId);
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

        /* #302 — "Save as PDF" is the same clean, well-titled document as Print
           (cover + running order + full lyrics); the browser's print dialog is
           where "Save as PDF" lives, and the doc <title> ("<name> — iHymns Set
           List") becomes the default PDF filename. This is the house-style,
           dependency-free PDF path (identical to the song print, print.js) —
           no server or client PDF library, shared-hosting friendly. A discrete,
           clearly-labelled entry point makes the capability discoverable (many
           users don't know Print → Save as PDF). */
        container.querySelector('#setlist-pdf-btn')?.addEventListener('click', () => {
            this.printSetList(list);
        });

        /* Activate for navigation */
        container.querySelector('#setlist-activate-btn')?.addEventListener('click', () => {
            this._armFromOwnList(list);
            this.app.showToast(`Set list "${list.name}" activated. Navigate songs with the set list controls.`, 'success', 3000);
        });

        /* #1533 — ARM ON TAP. Previously the only way into playback was the
           "Use" button, which most people never pressed: tapping a song from
           the list you are looking at is the obvious gesture, and it did
           nothing. Delegated so it survives the drag-reorder re-renders. */
        container.addEventListener('click', (e) => {
            const link = e.target instanceof HTMLElement
                ? e.target.closest('.setlist-song-item a[data-navigate="song"]')
                : null;
            if (!link) return;
            this._armFromOwnList(list);
            /* Deliberately NOT preventDefault — the router's own delegated
               handler still performs the navigation. We only arm the context
               first, so the bar is correct on arrival. */
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

        /* Arrange buttons — open per-song arrangement editor */
        container.querySelectorAll('.btn-arrange-song').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openArrangementEditor(listId, btn.dataset.songId);
            });
        });

        /* Drag-to-reorder */
        this.initDragReorder(listId);
    }

    /**
     * Render + wire the service plan card for one set list (#301 / #1671 F4).
     *
     * ELI5: shows the running order this set list was built from, and lets you
     * pick which of its songs goes in each labelled space.
     *
     * A no-op when the set list has no plan, which is every set list until the
     * user applies a template — so this costs nothing on the common path.
     *
     * THE WRITE GOES THROUGH saveAll(), WHICH SYNCS. That is the whole point of
     * the #1671 F4 server work: before `tblUserSetlists.SlotsJson` existed the
     * plan would have been dropped on the next round trip, silently, for
     * signed-in users only. Nothing here needs to know that — it just saves the
     * set list — which is exactly why the fix belonged in the sync path rather
     * than in a UI workaround.
     *
     * @param {Element} container
     * @param {string}  listId
     */
    _renderServicePlan(container, listId) {
        const list = this.getById(listId);
        if (!list || !list.plan || !Array.isArray(list.plan.slots) || list.plan.slots.length === 0) {
            return;
        }
        import('./setlist-templates.js').then((m) => {
            /* The set list may have changed while the module loaded (a user can
               delete it, or navigate away); re-read rather than closing over a
               stale object. */
            const current = this.getById(listId);
            if (!current?.plan) { return; }

            /* Slot-type labels come from the SERVER's registry so a picker is
               never a hardcoded copy (rule #35). A failed load degrades to raw
               type keys, which are still readable — never to a blank card. */
            m.loadTemplateCatalogue().catch(() => ({ slotTypes: {} })).then((cat) => {
                const host = document.createElement('div');
                host.innerHTML = m.renderPlanHtml(current.plan, current.songs, cat?.slotTypes || {});
                const card = host.firstElementChild;
                if (!card) { return; }

                /* Inserted after the header block rather than appended, so the
                   plan reads above the song list it organises. */
                const songs = container.querySelector('#setlist-songs') || container.lastElementChild;
                songs?.parentNode?.insertBefore(card, songs);

                card.addEventListener('change', (e) => {
                    const sel = e.target instanceof HTMLSelectElement ? e.target : null;
                    const slotId = sel?.getAttribute('data-plan-slot');
                    if (!sel || !slotId) { return; }
                    const lists = this.getAll();
                    const target = lists.find(l => l.id === listId);
                    if (!target) { return; }
                    target.plan = m.assignSongToSlot(target.plan, slotId, sel.value);
                    this.saveAll(lists);
                    this.renderSetListDetail(listId);
                });

                card.querySelector('[data-plan-clear]')?.addEventListener('click', () => {
                    if (!window.confirm('Remove the service plan from this set list? The songs stay.')) { return; }
                    const lists = this.getAll();
                    const target = lists.find(l => l.id === listId);
                    if (!target) { return; }
                    /* null, not delete: the sync path reads PRESENCE of the key
                       as "here is the truth about plans", so an explicit null is
                       what actually clears the stored plan. Deleting the key
                       would mean "I know nothing about plans" and the server
                       would keep the old one — the isset() clear-semantics trap,
                       from the client side. */
                    target.plan = null;
                    this.saveAll(lists);
                    this.renderSetListDetail(listId);
                });
            });
        }).catch(err => console.error('[SetList] service plan render failed:', err));
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
     * PER-SONG ARRANGEMENT EDITOR — Draggable tags + live preview
     *
     * Allows customising the component order for a song within this
     * setlist, without modifying the global song data. Inspired by
     * ProPresenter 7's arrangement feature.
     * ===================================================================== */

    /**
     * Fetch full song data (with components) from the API.
     * @param {string} songId Song ID (e.g. "MP-1342")
     * @returns {Promise<Object|null>} Full song object or null
     */
    async fetchSongData(songId) {
        try {
            const url = `${this.app.config.apiUrl}?action=song_data&id=${encodeURIComponent(songId)}`;
            const res = await apiFetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return null;
            const json = await res.json();
            return json.song || null;
        } catch {
            return null;
        }
    }

    /**
     * Open the arrangement editor modal for a song in a setlist.
     * Fetches the song's component data, then shows a modal with
     * draggable tag chips and a live lyrics preview.
     *
     * @param {string} listId  Setlist ID
     * @param {string} songId  Song ID
     */
    async openArrangementEditor(listId, songId) {
        this.app.showToast('Loading song data...', 'info', 1500);

        const songData = await this.fetchSongData(songId);
        if (!songData || !songData.components || songData.components.length === 0) {
            this.app.showToast('Could not load song components.', 'danger', 3000);
            return;
        }

        /* Get the current custom arrangement for this song in this setlist (if any) */
        const list = this.getById(listId);
        if (!list) return;
        const songEntry = list.songs.find(s => s.id === songId);
        if (!songEntry) return;

        /* Current arrangement: custom (from setlist) > song default > sequential */
        const currentArr = songEntry.arrangement
            || songData.arrangement
            || songData.components.map((_, i) => i);

        this._showArrangementModal(listId, songId, songData, currentArr);
    }

    /**
     * Build and display the arrangement editor modal.
     *
     * @param {string}   listId     Setlist ID
     * @param {string}   songId     Song ID
     * @param {Object}   songData   Full song object with components
     * @param {number[]} arrangement Current arrangement (array of component indices)
     */
    _showArrangementModal(listId, songId, songData, arrangement) {
        /* Remove any previous modal */
        document.getElementById('arrangement-editor-modal')?.remove();

        /* Working copy of the arrangement (mutated by drag/drop) */
        let workingArr = [...arrangement];
        const components = songData.components;

        /* Build pool chips (one per unique component).
         *
         * ELI5: each chip is a real <button> now, not a <span> pretending
         * to be one — so tapping Enter or Space on it "just works" the
         * same way clicking it does.
         *
         * WHY: a <span role="button" tabindex="0"> is focusable (tabindex)
         * and LABELLED as a button (role) but a <span> has no native
         * activation behaviour — the browser only synthesises a click
         * from Enter/Space for genuinely interactive elements (<button>,
         * <a href>, etc). The old markup made every chip focusable and
         * announced as "button" by a screen reader, then did nothing
         * when the user pressed the one key they'd expect to work —
         * completely silent, no console error, indistinguishable from a
         * frozen page (#1644). A real <button> gets Enter/Space "for
         * free" from the browser and needs no role/tabindex — both are
         * intrinsic to the element, so they're dropped here rather than
         * kept redundantly.
         * https://developer.mozilla.org/en-US/docs/Web/HTML/Element/button
         * https://www.w3.org/WAI/ARIA/apg/patterns/button/ ("prefer a
         * native <button> over role=button whenever possible") */
        const poolChipsHtml = components.map((comp, idx) => {
            const tag = shortTag(comp);
            const label = fullLabel(comp);
            const color = typeColor(comp.type);
            const textColor = typeTextColor(comp.type);
            return `<button type="button" class="badge rounded-pill arrangement-pool-chip border-0"
                          data-comp-idx="${idx}"
                          title="${escapeHtml(label)} — click to add"
                          style="background-color:${color};color:${textColor};cursor:pointer;user-select:none;font-size:0.8rem;padding:0.4em 0.7em"
                          aria-label="Add ${escapeHtml(tag)}, ${escapeHtml(label)}, to arrangement">${escapeHtml(tag)}</button>`;
        }).join(' ');

        const modal = document.createElement('div');
        modal.id = 'arrangement-editor-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'arrangement-editor-label');
        modal.setAttribute('aria-hidden', 'true');

        modal.innerHTML = `
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="arrangement-editor-label">
                            <i class="fa-solid fa-sliders me-2" aria-hidden="true"></i>
                            Arrangement — ${escapeHtml(toTitleCase(songData.title))}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-2">
                        <!-- Component pool -->
                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-uppercase mb-1">
                                <i class="fa-solid fa-puzzle-piece me-1" aria-hidden="true"></i>
                                Components — click to add
                            </label>
                            <div class="d-flex flex-wrap gap-1" id="arr-pool">${poolChipsHtml}</div>
                        </div>

                        <!-- Arrangement strip: move-left/move-right/remove buttons on every
                             chip, drag-and-drop kept as a bonus for pointer input (#1644) -->
                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-uppercase mb-1">
                                <i class="fa-solid fa-arrows-left-right me-1" aria-hidden="true"></i>
                                Arrangement
                            </label>
                            <div class="d-flex flex-wrap gap-2 p-2 rounded border arrangement-strip"
                                 id="arr-strip"
                                 style="min-height:40px;background:var(--bs-body-bg)"
                                 aria-label="Current arrangement order. Use each item's move and remove buttons, arrow keys, or drag to reorder.">
                                <!-- Populated by JS -->
                            </div>
                            <small class="text-muted d-block mt-1">Use the ◀ ▶ buttons (or drag) to reorder; × removes a component.</small>
                        </div>

                        <!-- Quick actions -->
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="arr-auto-btn"
                                    title="Insert chorus after each verse">
                                <i class="fa-solid fa-wand-magic-sparkles me-1" aria-hidden="true"></i>
                                Auto (Chorus after Verse)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="arr-sequential-btn"
                                    title="Reset to sequential component order">
                                <i class="fa-solid fa-arrow-down-1-9 me-1" aria-hidden="true"></i>
                                Sequential
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="arr-default-btn"
                                    title="Use the song's default arrangement">
                                <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>
                                Song Default
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="arr-clear-btn"
                                    title="Clear custom arrangement">
                                <i class="fa-solid fa-eraser me-1" aria-hidden="true"></i>
                                Clear Custom
                            </button>
                        </div>

                        <!-- Live lyrics preview -->
                        <div>
                            <label class="form-label fw-semibold small text-uppercase mb-1">
                                <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>
                                Preview
                            </label>
                            <div class="song-lyrics border rounded p-2" id="arr-preview"
                                 style="max-height:40vh;overflow-y:auto;font-size:0.85rem">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="arr-save-btn">
                            <i class="fa-solid fa-check me-1" aria-hidden="true"></i>
                            Save Arrangement
                        </button>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);

        /* === Render helpers === */

        const renderStrip = () => {
            const strip = modal.querySelector('#arr-strip');
            if (!strip) return;

            if (workingArr.length === 0) {
                strip.innerHTML = '<span class="text-muted small fst-italic">Add components from the pool above</span>';
                return;
            }

            /* Each arrangement entry renders as a wrapper holding FOUR
             * sibling controls — the chip itself, move-left, move-right,
             * remove — never one nested inside another.
             *
             * ELI5: picture each entry as a little row of four separate
             * buttons glued together, not one big button hiding three
             * smaller ones inside it.
             *
             * WHY siblings, not nesting: the previous markup put the "×"
             * remove control INSIDE the chip's own <span role="button">,
             * i.e. an interactive control nested inside another
             * interactive control — banned by WCAG 4.1.2 (Name, Role,
             * Value) because assistive tech has no reliable way to expose
             * or activate the inner control independently of the outer
             * one (#1644). Four flat siblings sidesteps that outright,
             * and each one gets its own unambiguous accessible name.
             * https://www.w3.org/WAI/WCAG21/Understanding/name-role-value.html
             *
             * Move-left/move-right are real <button>s (not drag) so
             * touch users — who cannot fire HTML5 drag-and-drop from a
             * touchscreen at all — can still reorder; drag stays wired
             * below as a pointer-only bonus, never the only path. */
            strip.innerHTML = workingArr.map((idx, pos) => {
                const comp = components[idx];
                if (!comp) return '';
                const tag = shortTag(comp);
                const label = fullLabel(comp);
                const color = typeColor(comp.type);
                const textColor = typeTextColor(comp.type);
                const isFirst = pos === 0;
                const isLast = pos === workingArr.length - 1;
                /* The accessible name LEADS with the visible tag ("V1"), then the
                   spoken-out label ("Verse 1"), then the position.
                   ELI5: what you see written on the chip has to be part of what a
                   screen reader calls it, or someone who talks to their computer
                   can't ask for it by name.
                   Detail: WCAG 2.5.3 Label in Name (Level A) requires the
                   accessible name to CONTAIN the visible text. shortTag() renders
                   "V1" while fullLabel() renders "Verse 1", so an aria-label of
                   "Verse 1, position 2 of 5" does not contain "V1" — a speech-input
                   user saying "click V1" would match nothing. Leading with the tag
                   satisfies 2.5.3 and still gives assistive tech the unabbreviated
                   label. The icon-only move/remove buttons alongside have no visible
                   text, so 2.5.3 does not apply to them.
                   https://www.w3.org/WAI/WCAG21/Understanding/label-in-name.html */
                return `<div class="d-inline-flex align-items-center gap-1 arrangement-strip-item" data-pos="${pos}">
                            <button type="button" class="badge rounded-pill arrangement-strip-chip border-0"
                                    data-pos="${pos}" data-comp-idx="${idx}"
                                    draggable="true"
                                    title="${escapeHtml(label)} — position ${pos + 1} of ${workingArr.length}"
                                    style="background-color:${color};color:${textColor};cursor:grab;user-select:none;font-size:0.8rem;padding:0.4em 0.7em"
                                    aria-label="${escapeHtml(tag)}, ${escapeHtml(label)}, position ${pos + 1} of ${workingArr.length}">${escapeHtml(tag)}</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 arrangement-chip-move-left"
                                    data-pos="${pos}" ${isFirst ? 'disabled' : ''}
                                    aria-label="Move ${escapeHtml(label)} earlier">
                                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 arrangement-chip-move-right"
                                    data-pos="${pos}" ${isLast ? 'disabled' : ''}
                                    aria-label="Move ${escapeHtml(label)} later">
                                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 arrangement-chip-remove"
                                    data-pos="${pos}"
                                    aria-label="Remove ${escapeHtml(label)}">×</button>
                        </div>`;
            }).join('');

            /* Drag-and-drop is re-bound on every render as a POINTER
             * ENHANCEMENT ONLY — it never fires from touch input on
             * mobile browsers (the other half of #1644), which is why
             * the move buttons above exist and are wired independently
             * of this. Never remove this call — see _initStripDragDrop(). */
            this._initStripDragDrop(strip, workingArr, components, renderStrip, renderPreview);

            /* Move-left / move-right buttons. Bound fresh every render
             * because renderStrip() just replaced every node above —
             * exactly like the pre-existing remove-button binding below,
             * which already had to do the same thing. */
            strip.querySelectorAll('.arrangement-chip-move-left').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    moveStripEntry(parseInt(btn.dataset.pos, 10), -1);
                });
            });
            strip.querySelectorAll('.arrangement-chip-move-right').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    moveStripEntry(parseInt(btn.dataset.pos, 10), 1);
                });
            });

            /* ArrowLeft/ArrowRight on a focused chip is an ADDITIVE
             * convenience for keyboard users — it does nothing for touch,
             * which is why it is NOT what satisfies WCAG 2.5.7 (Dragging
             * Movements); the move buttons above are the actual
             * pointer-free path. preventDefault() stops the arrow key
             * from also scrolling the modal body while the chip has
             * focus.
             * https://www.w3.org/WAI/WCAG21/Understanding/dragging-movements.html */
            strip.querySelectorAll('.arrangement-strip-chip').forEach(chip => {
                chip.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowLeft') {
                        e.preventDefault();
                        moveStripEntry(parseInt(chip.dataset.pos, 10), -1);
                    } else if (e.key === 'ArrowRight') {
                        e.preventDefault();
                        moveStripEntry(parseInt(chip.dataset.pos, 10), 1);
                    }
                });
            });

            /* Bind remove buttons */
            strip.querySelectorAll('.arrangement-chip-remove').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const pos = parseInt(btn.dataset.pos, 10);
                    const comp = components[workingArr[pos]];
                    const label = comp ? fullLabel(comp) : 'Component';
                    workingArr.splice(pos, 1);
                    renderStrip();
                    renderPreview();
                    this._announce(`${label} removed`);
                    /* Same focus-restore problem as moveStripEntry() below:
                       renderStrip() just destroyed the button that was
                       clicked/activated, so without this a keyboard user
                       is dropped to document.body after every removal
                       too (#1644). Land on whichever remove button now
                       occupies the vacated slot, or the previous one. */
                    const nextPos = Math.min(pos, workingArr.length - 1);
                    if (nextPos >= 0) {
                        modal.querySelector(`.arrangement-chip-remove[data-pos="${nextPos}"]`)?.focus();
                    }
                });
            });
        };

        const renderPreview = () => {
            const preview = modal.querySelector('#arr-preview');
            if (!preview) return;

            if (workingArr.length === 0) {
                preview.innerHTML = '<p class="text-muted fst-italic mb-0">No components in arrangement</p>';
                return;
            }

            preview.innerHTML = workingArr.map(idx => {
                const comp = components[idx];
                if (!comp) return '';
                const label = fullLabel(comp);
                const lines = Array.isArray(comp.lines) ? comp.lines : [];
                const typeClass = 'lyric-' + (comp.type || 'verse');
                return `<div class="lyric-component ${escapeHtml(typeClass)}" role="group" aria-label="${escapeHtml(label)}">
                    <div class="lyric-label" aria-hidden="true">${escapeHtml(label)}</div>
                    <div class="lyric-lines">${lines.map(l => `<p class="lyric-line mb-1">${escapeHtml(l)}</p>`).join('')}</div>
                </div>`;
            }).join('');
        };

        /**
         * Swap a strip entry with its earlier/later neighbour, re-render,
         * then put keyboard focus back on the SAME chip at its NEW
         * position and tell screen-reader users what happened.
         *
         * ELI5: slides two puzzle pieces past each other, then makes sure
         * your finger (focus) stays on the piece you were holding instead
         * of jumping back to the start of the puzzle.
         *
         * WHY the re-focus matters (the single most important detail of
         * #1644): renderStrip() rebuilds #arr-strip's entire innerHTML on
         * every change — simplest way to keep the strip, the disabled
         * state of each end button, and every position label in sync —
         * but that DESTROYS every button node inside it, including
         * whichever one currently has keyboard focus. Left alone, the
         * browser drops focus to <body>, so the NEXT Tab/Enter from a
         * keyboard-only user starts back at the top of the modal instead
         * of continuing to reorder — silently reintroducing the exact
         * "technically focusable, nothing usable" failure this issue was
         * filed to fix, just one step later. Re-querying the chip at its
         * NEW data-pos and calling .focus() on it is the fix.
         * https://www.w3.org/WAI/WCAG21/Understanding/focus-order.html
         *
         * @param {number} pos    Current position (0-based) of the chip to move
         * @param {number} delta  -1 = earlier/left, +1 = later/right
         */
        const moveStripEntry = (pos, delta) => {
            const newPos = pos + delta;
            if (newPos < 0 || newPos >= workingArr.length) return;
            const comp = components[workingArr[pos]];
            const label = comp ? fullLabel(comp) : 'Component';
            [workingArr[pos], workingArr[newPos]] = [workingArr[newPos], workingArr[pos]];
            renderStrip();
            renderPreview();
            modal.querySelector(`.arrangement-strip-chip[data-pos="${newPos}"]`)?.focus();
            this._announce(`${label} moved to position ${newPos + 1} of ${workingArr.length}`);
        };

        /* Initial render */
        renderStrip();
        renderPreview();

        /* === Pool chip clicks — add to arrangement === */
        modal.querySelectorAll('.arrangement-pool-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                const idx = parseInt(chip.dataset.compIdx, 10);
                workingArr.push(idx);
                renderStrip();
                renderPreview();
                const comp = components[idx];
                this._announce(`${comp ? fullLabel(comp) : 'Component'} added, position ${workingArr.length}`);
            });
        });

        /* === Quick action buttons === */

        /* Auto-generate: chorus/refrain after each verse */
        modal.querySelector('#arr-auto-btn')?.addEventListener('click', () => {
            const refrainIdx = components.findIndex(c => c.type === 'chorus' || c.type === 'refrain');
            if (refrainIdx === -1) {
                this.app.showToast('No chorus found.', 'warning', 2000);
                return;
            }
            const auto = [];
            components.forEach((comp, i) => {
                if (comp.type === 'verse') {
                    auto.push(i);
                    auto.push(refrainIdx);
                } else if (i !== refrainIdx) {
                    auto.push(i);
                }
            });
            workingArr = auto;
            renderStrip();
            renderPreview();
        });

        /* Sequential order */
        modal.querySelector('#arr-sequential-btn')?.addEventListener('click', () => {
            workingArr = components.map((_, i) => i);
            renderStrip();
            renderPreview();
        });

        /* Song default */
        modal.querySelector('#arr-default-btn')?.addEventListener('click', () => {
            workingArr = songData.arrangement
                ? [...songData.arrangement]
                : components.map((_, i) => i);
            renderStrip();
            renderPreview();
        });

        /* Clear custom */
        modal.querySelector('#arr-clear-btn')?.addEventListener('click', () => {
            workingArr = [];
            renderStrip();
            renderPreview();
        });

        /* === Save === */
        modal.querySelector('#arr-save-btn')?.addEventListener('click', () => {
            /* Build arrangement label string for tooltip display (e.g. "V1, C, V2, C, V3") */
            const arrLabels = workingArr.length > 0
                ? workingArr.map(idx => components[idx] ? shortTag(components[idx]) : '?').join(', ')
                : null;
            this.setSongArrangement(listId, songId, workingArr.length > 0 ? workingArr : null, arrLabels);
            bsModal.hide();
            this.app.showToast('Arrangement saved', 'success', 2000);
            this.renderSetListDetail(listId);
        });

        /* Cleanup on close */
        modal.addEventListener('hidden.bs.modal', () => modal.remove());

        bsModal.show();
    }

    /**
     * Initialise drag-and-drop reordering on arrangement strip chips.
     * Uses the HTML5 Drag and Drop API for natural drag behaviour.
     *
     * ELI5: lets a mouse user grab a chip and drop it somewhere else in
     * the row — a nice-to-have shortcut, not the only way to reorder.
     *
     * WHY this is an ENHANCEMENT, never the only path (#1644): the HTML5
     * Drag and Drop API this function uses is driven by mouse events
     * under the hood and simply does not fire from touch input on mobile
     * browsers — a worship leader arranging a song on their phone (the
     * exact scenario this feature exists for) could not reorder anything
     * through this code path alone. The move-left/move-right buttons
     * wired in renderStrip() are what make reordering possible for touch
     * AND keyboard users; this function stays as the bonus for a mouse.
     * https://developer.mozilla.org/en-US/docs/Web/API/HTML_Drag_and_Drop_API#drag_and_drop_events
     *
     * @param {HTMLElement} strip       The strip container
     * @param {number[]}    workingArr  Mutable arrangement array
     * @param {Array}       components  Song components (read-only)
     * @param {Function}    renderStrip Re-render callback
     * @param {Function}    renderPreview Re-render callback
     */
    _initStripDragDrop(strip, workingArr, components, renderStrip, renderPreview) {
        let dragSrcPos = null;

        strip.querySelectorAll('.arrangement-strip-chip').forEach(chip => {
            chip.addEventListener('dragstart', (e) => {
                dragSrcPos = parseInt(chip.dataset.pos, 10);
                chip.style.opacity = '0.4';
                e.dataTransfer.effectAllowed = 'move';
                /* Store data for cross-element identification */
                e.dataTransfer.setData('text/plain', String(dragSrcPos));
            });

            chip.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                chip.style.outline = '2px solid var(--bs-primary, #6366f1)';
                chip.style.outlineOffset = '2px';
            });

            chip.addEventListener('dragleave', () => {
                chip.style.outline = '';
                chip.style.outlineOffset = '';
            });

            chip.addEventListener('drop', (e) => {
                e.preventDefault();
                chip.style.outline = '';
                chip.style.outlineOffset = '';
                const dropPos = parseInt(chip.dataset.pos, 10);
                if (dragSrcPos !== null && dragSrcPos !== dropPos) {
                    /* Reorder: remove from old position, insert at new */
                    const [moved] = workingArr.splice(dragSrcPos, 1);
                    workingArr.splice(dropPos, 0, moved);
                    renderStrip();
                    renderPreview();
                }
            });

            chip.addEventListener('dragend', () => {
                chip.style.opacity = '';
                dragSrcPos = null;
            });
        });
    }

    /**
     * Save a custom arrangement for a song within a setlist.
     * @param {string}       listId      Setlist ID
     * @param {string}       songId      Song ID
     * @param {number[]|null} arrangement Custom arrangement array, or null to clear
     * @param {string|null}  arrLabel    Human-readable label (e.g. "V1, C, V2, C") for tooltip
     */
    setSongArrangement(listId, songId, arrangement, arrLabel = null) {
        const lists = this.getAll();
        const list = lists.find(l => l.id === listId);
        if (!list) return;

        const song = list.songs.find(s => s.id === songId);
        if (!song) return;

        if (arrangement && arrangement.length > 0) {
            song.arrangement = arrangement;
            song.arrangementLabel = arrLabel || null;
        } else {
            delete song.arrangement;
            delete song.arrangementLabel;
        }

        this.saveAll(lists);
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
        /* PLAYLIST CONTEXT FIRST (#1533). The context is the general case: it
           carries its own song order, so it serves a SHARED setlist too. That
           matters because a shared list is not in getAll() — before #1533,
           getById() returned null for one and shared lists could not be
           navigated at all, however you arrived at them. */
        const ctx = this.getPlaylistContext();
        if (ctx) {
            const i = ctx.songIds.indexOf(songId);
            if (i >= 0) {
                const at = (n) => (ctx.songIds[n]
                    ? { id: ctx.songIds[n], title: ctx.titles?.[ctx.songIds[n]] || null }
                    : null);
                return {
                    prev: i > 0 ? at(i - 1) : null,
                    next: i < ctx.songIds.length - 1 ? at(i + 1) : null,
                    position: i + 1,
                    total: ctx.songIds.length,
                    listName: ctx.name,
                };
            }
            /* In a playlist, but this song is not part of it — the user
               navigated away (a search, a related-song link). Fall through:
               no bar, and the context stays armed so Back resumes it. */
        }

        /* FALLBACK — the pre-#1533 "Use this set list" path. Kept so an
           already-activated list keeps working for anyone mid-session when
           this ships, and because activeSetListId also drives
           applyCustomArrangement(). */
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
     * Read the active playlist context, or null.
     *
     * ELI5: "which list am I working through, and in what order?"
     *
     * Read from sessionStorage on every call rather than cached in a field.
     * The SPA survives a reload as a fresh module instance, so an in-memory
     * field is exactly what used to lose the active list mid-service — that
     * is the #1533 complaint. sessionStorage is the source of truth; a bad
     * or hand-edited value resolves to null rather than throwing.
     *
     * @returns {{songIds: string[], titles: Object, name: string, source: string, sourceId: string}|null}
     */
    getPlaylistContext() {
        try {
            const raw = sessionStorage.getItem(STORAGE_PLAYLIST_CONTEXT);
            if (!raw) return null;
            const ctx = JSON.parse(raw);
            if (!ctx || !Array.isArray(ctx.songIds) || ctx.songIds.length === 0) return null;
            /* Defensive: ids must be strings — a malformed entry would make
               indexOf() silently never match, which reads as "playback just
               doesn't work" with no error. */
            ctx.songIds = ctx.songIds.filter(id => typeof id === 'string' && id !== '');
            return ctx.songIds.length ? ctx : null;
        } catch (_e) {
            return null;
        }
    }

    /**
     * Arm playlist playback for a list of songs.
     *
     * @param {Object} ctx
     * @param {string[]} ctx.songIds  Song ids IN ORDER
     * @param {Object}  [ctx.titles]  Optional id → title map, for the "next up" label
     * @param {string}  ctx.name      List name, shown in the bar
     * @param {string}  ctx.source    'own' | 'shared'
     * @param {string}  [ctx.sourceId] Setlist id (own) or share id (shared)
     */
    setPlaylistContext(ctx) {
        try {
            sessionStorage.setItem(STORAGE_PLAYLIST_CONTEXT, JSON.stringify(ctx));
        } catch (_e) {
            /* Private mode / quota — playback degrades to not persisting
               across a reload, which is the pre-#1533 behaviour. Not fatal. */
        }
        /* Keep activeSetListId in step for OWN lists so custom arrangements
           still apply (applyCustomArrangement reads it). A shared list has no
           local record, so it stays null — and applyCustomArrangement
           correctly does nothing. */
        if (ctx.source === 'own' && ctx.sourceId) {
            this.activeSetListId = ctx.sourceId;
        }
    }

    /**
     * Arm playback from one of the user's OWN setlists.
     * @param {Object} list A setlist record from getAll()/getById()
     * @private
     */
    _armFromOwnList(list) {
        if (!list || !Array.isArray(list.songs) || list.songs.length === 0) return;
        const titles = {};
        for (const s of list.songs) {
            if (s && s.id) titles[s.id] = s.title || '';
        }
        this.setPlaylistContext({
            songIds:  list.songs.map(s => s.id).filter(Boolean),
            titles,
            name:     list.name,
            source:   'own',
            sourceId: list.id,
        });
    }

    /** Leave playlist mode. */
    clearPlaylistContext() {
        try {
            sessionStorage.removeItem(STORAGE_PLAYLIST_CONTEXT);
        } catch (_e) { /* nothing to do */ }
        this.activeSetListId = null;
        document.getElementById('setlist-song-nav')?.remove();
        document.body.classList.remove('has-playlist-bar');
    }

    /**
     * Render set list navigation bar on a song page (if active).
     * Called by router after song page loads.
     */
    renderSongNavigation() {
        /* CLEAR FIRST, UNCONDITIONALLY. Since #1533 the bar is fixed-position
           on <body>, so it survives an SPA content swap that would have
           removed an in-flow element. Every early return below must therefore
           happen AFTER the teardown, or the bar strands over whatever page
           the user went to next. The router calls this on every navigation
           precisely so this teardown runs. */
        document.getElementById('setlist-song-nav')?.remove();
        document.body.classList.remove('has-playlist-bar');

        const songPage = document.querySelector('.page-song');
        if (!songPage) return;

        const songId = songPage.dataset.songId;
        if (!songId) return;

        const nav = this.getNavigation(songId);
        if (!nav) return;

        const navEl = document.createElement('div');
        navEl.id = 'setlist-song-nav';
        navEl.className = 'playlist-bar';
        navEl.setAttribute('role', 'navigation');
        navEl.setAttribute('aria-label', `Set list navigation — ${nav.listName}`);

        /* "Next up" title when we know it. The shared-list path fills titles in
           asynchronously, so absence is normal, not an error — fall back to the
           plain word rather than rendering "Next up: undefined". */
        const nextLabel = nav.next?.title
            ? `Next: ${toTitleCase(nav.next.title)}`
            : 'Next';

        const btn = (song, dir, label) => (song
            ? `<a href="/song/${escapeHtml(song.id)}" data-navigate="song"
                  class="btn btn-sm btn-primary playlist-bar-btn"
                  data-playlist-nav="${dir}"
                  aria-label="${dir === 'prev' ? 'Previous' : 'Next'} song in set list">
                 ${dir === 'prev' ? '<i class="fa-solid fa-chevron-left me-1" aria-hidden="true"></i>' : ''}
                 <span class="playlist-bar-btn-label">${escapeHtml(label)}</span>
                 ${dir === 'next' ? '<i class="fa-solid fa-chevron-right ms-1" aria-hidden="true"></i>' : ''}
               </a>`
            /* Disabled at the ends. A <button disabled> (not a styled <a>) so
               it is genuinely un-focusable and screen readers announce the
               state — an <a> without href is skipped by some ATs entirely. */
            : `<button type="button" class="btn btn-sm btn-outline-secondary playlist-bar-btn" disabled
                       aria-label="${dir === 'prev' ? 'No previous song' : 'No next song'} — ${dir === 'prev' ? 'start' : 'end'} of set list">
                 ${dir === 'prev' ? '<i class="fa-solid fa-chevron-left me-1" aria-hidden="true"></i>' : ''}
                 <span class="playlist-bar-btn-label">${escapeHtml(label)}</span>
                 ${dir === 'next' ? '<i class="fa-solid fa-chevron-right ms-1" aria-hidden="true"></i>' : ''}
               </button>`);

        navEl.innerHTML = `
            ${btn(nav.prev, 'prev', 'Prev')}
            <div class="playlist-bar-meta">
                <span class="playlist-bar-name">
                    <i class="fa-solid fa-list-ol me-1" aria-hidden="true"></i>${escapeHtml(nav.listName)}
                </span>
                <span class="playlist-bar-pos">${nav.position} of ${nav.total}</span>
            </div>
            ${btn(nav.next, 'next', nextLabel)}
            <button type="button" class="btn btn-sm btn-link playlist-bar-exit"
                    aria-label="Leave set list playback">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>`;

        document.body.appendChild(navEl);
        /* Lets the page reserve room so the bar never covers the last lyric
           line — a fixed bar with no matching padding is the classic way to
           make the final verse unreadable. */
        document.body.classList.add('has-playlist-bar');

        navEl.querySelector('.playlist-bar-exit')?.addEventListener('click', () => {
            this.clearPlaylistContext();
            this.app.showToast('Left set list playback.', 'info', 2000);
        });

        this._announce(`${toTitleCase(document.querySelector('.page-song h1')?.textContent?.trim() || 'Song')}. `
            + `${nav.position} of ${nav.total} in ${nav.listName}.`);

        /* Apply custom arrangement if this song has one in the active setlist */
        this.applyCustomArrangement(songId);
    }

    /**
     * Announce the current song to assistive technology.
     *
     * ELI5: tells a screen reader what just loaded, because tapping Next
     * changes the page under you without moving focus anywhere obvious.
     *
     * The SPA swaps content without a document load, so nothing announces the
     * new song by default — a sighted user sees it instantly and a screen
     * reader user gets silence. A polite live region says it without
     * interrupting whatever is being read.
     *
     * The region is created ONCE and reused: a live region added to the DOM
     * with its text already in place is frequently NOT announced, because the
     * AT only reports MUTATIONS to a region it was already observing. Hence
     * the deliberate empty-then-fill on the next frame.
     * https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Attributes/aria-live
     *
     * @param {string} message
     * @private
     */
    _announce(message) {
        /* Delegates to the shared announcer (#1645). This method WAS the
           implementation — it was extracted to js/utils/announce.js once the
           router needed the same thing, per the modularity rule. Kept as a
           one-line delegate rather than rewriting ~15 call sites: a smaller
           diff, and the private name still reads correctly at each of them. */
        announce(message);
    }

    /**
     * Bind ← / → to previous / next song while playlist mode is active.
     *
     * Bound ONCE, at construction, and it checks for the bar at keypress time
     * rather than being bound/unbound as the bar appears — a listener added
     * per render is a listener leaked per render.
     *
     * @private
     */
    _bindPlaylistKeys() {
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            /* Never steal arrows from text entry, or from a component that has
               its own arrow semantics (the comboboxes from #1594, sliders,
               anything with a roving tabindex). */
            const t = e.target;
            if (t instanceof HTMLElement && (
                t.isContentEditable
                || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName)
                || t.closest('[role="combobox"], [role="listbox"], [role="slider"], [role="tablist"]')
            )) return;
            /* Modifier combos belong to the browser (back/forward, word jump). */
            if (e.altKey || e.ctrlKey || e.metaKey) return;

            const bar = document.getElementById('setlist-song-nav');
            if (!bar) return;
            const link = bar.querySelector(
                `a[data-playlist-nav="${e.key === 'ArrowLeft' ? 'prev' : 'next'}"]`
            );
            if (!link) return;      /* disabled end of list — do nothing */
            e.preventDefault();
            link.click();
        });
    }

    /**
     * Re-render the song lyrics on the page using a custom arrangement
     * from the active setlist, if one exists. The server renders the song
     * with its default/global arrangement; this method re-orders the
     * already-rendered lyric components client-side.
     *
     * @param {string} songId Song ID
     */
    async applyCustomArrangement(songId) {
        if (!this.activeSetListId) return;

        const list = this.getById(this.activeSetListId);
        if (!list) return;

        const songEntry = list.songs.find(s => s.id === songId);
        if (!songEntry?.arrangement || songEntry.arrangement.length === 0) return;

        /* Fetch the full song data to get component content by index */
        const songData = await this.fetchSongData(songId);
        if (!songData?.components) return;

        const lyricsEl = document.querySelector('.song-lyrics');
        if (!lyricsEl) return;

        /* Show an indicator that a custom arrangement is active */
        const indicator = document.createElement('div');
        indicator.className = 'alert alert-warning py-1 px-2 small mb-2';
        indicator.innerHTML = '<i class="fa-solid fa-sliders me-1" aria-hidden="true"></i> Custom arrangement active';
        lyricsEl.parentNode.insertBefore(indicator, lyricsEl);

        /* Re-render the lyrics in the custom arrangement order */
        lyricsEl.innerHTML = songEntry.arrangement.map(idx => {
            const comp = songData.components[idx];
            if (!comp) return '';
            const label = fullLabel(comp);
            const lines = Array.isArray(comp.lines) ? comp.lines : [];
            const typeClass = 'lyric-' + (comp.type || 'verse');
            return `<div class="lyric-component ${escapeHtml(typeClass)}" role="group" aria-label="${escapeHtml(label)}">
                <div class="lyric-label" aria-hidden="true">${escapeHtml(label)}</div>
                <div class="lyric-lines">${lines.map(l => `<p class="lyric-line mb-1">${escapeHtml(l)}</p>`).join('')}</div>
            </div>`;
        }).join('');
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
     * Mint (or re-mint) a server-side share link, supporting the #1791
     * capability fields `generateShareLink()` predates: `scope` ('view' |
     * 'edit'), `showSharerName`, and — for an edit link — `editAudience`.
     *
     * Returns the RESPONSE's ACTUAL stored values (rule #35) — an org
     * mandate may clamp `editAudience` stricter than what was requested, so
     * the caller must read what was stored rather than assume its own
     * request was honoured verbatim.
     *
     * Deliberately a SEPARATE method from `generateShareLink()` rather than
     * an extra parameter on it: that method returns a bare URL STRING and
     * always reuses `list.shareId` for updates, a contract `shareSetlist()`
     * (whose exact body `tests/test-user-sync-client.js` pins) still depends
     * on. An edit link is a DIFFERENT identity under the #1791 multi-link
     * model — minting a second edit link must never silently overwrite a
     * first one (e.g. one team's link replacing another's) — so it always
     * mints fresh rather than reusing any stored id.
     *
     * @param {string} listId
     * @param {{scope?:'view'|'edit', showSharerName?:boolean, editAudience?:'anyone'|'authenticated', label?:string}} [opts]
     * @returns {Promise<{ok:true,id:string,url:string,scope:string,showSharerName:boolean,editAudience:?string}|{ok:false,error:string}>}
     */
    async mintShareLink(listId, opts = {}) {
        const list = this.getById(listId);
        if (!list) return { ok: false, error: 'Set list not found.' };

        const scope = opts.scope === 'edit' ? 'edit' : 'view';

        const arrangements = {};
        list.songs.forEach(s => {
            if (s.arrangement && Array.isArray(s.arrangement) && s.arrangement.length > 0) {
                arrangements[s.id] = s.arrangement;
            }
        });

        const payload = {
            name: list.name,
            songs: list.songs.map(s => s.id),
            owner: this.getOwnerId(),
            setlistId: listId,
            scope,
        };
        if (Object.keys(arrangements).length > 0) payload.arrangements = arrangements;
        if (opts.showSharerName) payload.showSharerName = true;
        if (scope === 'edit' && opts.editAudience) payload.editAudience = opts.editAudience;
        if (opts.label) payload.label = opts.label;
        /* View links reuse the stored shareId (update-in-place — matches
           generateShareLink()'s existing behaviour); edit links ALWAYS mint
           fresh (see the doc comment above — the multi-link model). */
        if (scope !== 'edit' && list.shareId) payload.id = list.shareId;

        try {
            const response = await apiFetch(`${this.app.config.apiUrl}?action=setlist_share`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(this.app?.userAuth?.authHeaders?.() || {}),
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                return { ok: false, error: result.error || response.statusText || 'Could not create the link.' };
            }
            if (scope !== 'edit' && result.id) {
                const allLists = this.getAll();
                const target = allLists.find(l => l.id === listId);
                if (target) { target.shareId = result.id; this.saveAll(allLists); }
            }
            return {
                ok: true,
                id: result.id,
                url: window.location.origin + result.url,
                scope: result.scope === 'edit' ? 'edit' : 'view',
                showSharerName: result.showSharerName === true,
                editAudience: typeof result.editAudience === 'string' ? result.editAudience : null,
            };
        } catch (_e) {
            return { ok: false, error: 'Network error — please try again.' };
        }
    }

    /**
     * Copy plain text to the clipboard, falling back to the classic
     * execCommand path for older browsers. Returns whether it worked so the
     * caller can choose its own success/failure copy.
     *
     * A small, deliberate duplicate of the fallback already inlined in
     * `shareSetlist()` (Web-Share/clipboard flow) rather than a shared
     * extraction of THAT method's tail — `shareSetlist()`'s body is pinned
     * verbatim by `tests/test-user-sync-client.js` (the `_syncReady` sync
     * gating it checks for), so reshaping any part of it risks that guard
     * for a few lines of copy-paste saved.
     *
     * @param {string} text
     * @returns {Promise<boolean>}
     */
    async _copyPlainText(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                textarea.remove();
                return true;
            } catch {
                return false;
            }
        }
    }

    /**
     * The owner's Share dialog (#1791) — replaces the old bare
     * `shareSetlist()` toast flow with a modal offering a VIEW link, an EDIT
     * link (with a "who can edit" choice + a "show my name" opt-in), and
     * this set list's Active links with per-link Revoke. Built the same way
     * as this file's other modals (`showAddToSetListDetail()`, the
     * arrangement editor further down) — `document.createElement` +
     * `bootstrap.Modal`, torn down on `hidden.bs.modal`.
     *
     * @param {string} listId
     */
    renderShareDialog(listId) {
        const list = this.getById(listId);
        if (!list) return;

        document.getElementById('setlist-share-modal')?.remove();

        const loggedIn = this.app?.userAuth?.isLoggedIn?.() === true;

        const modal = document.createElement('div');
        modal.id = 'setlist-share-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'setlist-share-modal-label');
        modal.setAttribute('aria-hidden', 'true');

        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="setlist-share-modal-label">
                            <i class="fa-solid fa-share-nodes me-2" aria-hidden="true"></i>
                            Share &ldquo;${escapeHtml(list.name)}&rdquo;
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="border rounded p-3 mb-3">
                            <h6 class="mb-1">Anyone with the link can view</h6>
                            <p class="text-muted small mb-2">
                                They can look through the songs and use Prev/Next to follow along. No account, no changes.
                            </p>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="share-view-showname">
                                <label class="form-check-label small" for="share-view-showname">Show my name on the shared page</label>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="share-view-copy-btn">
                                <i class="fa-solid fa-copy me-1" aria-hidden="true"></i>Copy link
                            </button>
                            <div class="small mt-2" id="share-view-status" aria-live="polite"></div>
                        </div>

                        <div class="border rounded p-3 mb-3">
                            <h6 class="mb-1">Anyone with the link can edit</h6>
                            <p class="text-muted small mb-2">
                                They can reorder and remove songs — no account needed, unless you require sign-in below.
                            </p>
                            ${loggedIn ? `
                            <div class="mb-2">
                                <span class="small fw-semibold d-block mb-1" id="share-edit-audience-label">Who can edit</span>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="share-edit-audience" id="share-edit-audience-anyone" value="anyone" checked>
                                    <label class="form-check-label small" for="share-edit-audience-anyone">Anyone with the link</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="share-edit-audience" id="share-edit-audience-auth" value="authenticated">
                                    <label class="form-check-label small" for="share-edit-audience-auth">People signed in to iHymns</label>
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="share-edit-showname">
                                <label class="form-check-label small" for="share-edit-showname">Show my name on the shared page</label>
                            </div>
                            <div class="small text-warning-emphasis d-none mb-2" id="share-edit-org-note"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="share-edit-create-btn">
                                <i class="fa-solid fa-user-pen me-1" aria-hidden="true"></i>Create &amp; copy
                            </button>
                            <div class="small mt-2" id="share-edit-status" aria-live="polite"></div>
                            ` : `
                            <p class="text-muted small mb-0">
                                <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                                Sign in to create an edit link — it always writes back to YOUR saved set list, so it needs an account to attach to.
                            </p>
                            `}
                        </div>

                        <div>
                            <h6 class="mb-2">Active links</h6>
                            <div id="share-links-list"><p class="text-muted small mb-0">Loading&hellip;</p></div>
                        </div>
                        <p class="text-muted small mb-0 mt-3">
                            Want to invite one specific person instead? Use the Collaborators card below — they will need an iHymns account.
                        </p>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        modal.addEventListener('hidden.bs.modal', () => modal.remove());
        bsModal.show();

        /* -------------------------------------------------------------
         * Active links — setlist_share_list (owner-auth'd, cookie session).
         * ----------------------------------------------------------- */
        const linksListEl = modal.querySelector('#share-links-list');
        const refreshLinks = () => {
            if (!linksListEl) return;
            if (!loggedIn) {
                linksListEl.innerHTML = '<p class="text-muted small mb-0">Sign in to see and manage links you’ve created for this set list.</p>';
                return;
            }
            apiFetch(`/api?action=setlist_share_list&setlistId=${encodeURIComponent(listId)}`, {
                credentials: 'same-origin',
            })
                .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
                .then(data => {
                    const links = Array.isArray(data.links) ? data.links : [];
                    if (links.length === 0) {
                        linksListEl.innerHTML = '<p class="text-muted small mb-0">No links yet.</p>';
                        return;
                    }
                    linksListEl.innerHTML = `<div class="list-group list-group-flush">
                        ${links.map(l => this._shareLinkRowHtml(l)).join('')}
                    </div>`;
                    linksListEl.querySelectorAll('.btn-share-revoke').forEach(btn => {
                        btn.addEventListener('click', () => {
                            btn.disabled = true;
                            apiFetch('/api?action=setlist_share_revoke', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                                body: JSON.stringify({ shareId: btn.dataset.shareId }),
                            })
                                .then(r => (r.ok ? r.json() : Promise.reject(r)))
                                .then(() => refreshLinks())
                                .catch(() => {
                                    btn.disabled = false;
                                    this.app.showToast('Could not revoke that link.', 'danger', 3000);
                                });
                        });
                    });
                })
                .catch(() => {
                    linksListEl.innerHTML = '<p class="text-danger small mb-0">Could not load active links.</p>';
                });
        };
        refreshLinks();

        /* -------------------------------------------------------------
         * View row — Copy link.
         * ----------------------------------------------------------- */
        modal.querySelector('#share-view-copy-btn')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const status = modal.querySelector('#share-view-status');
            const showName = modal.querySelector('#share-view-showname')?.checked === true;
            btn.disabled = true;
            if (status) status.innerHTML = '<span class="text-muted">Creating link…</span>';
            const result = await this.mintShareLink(listId, { scope: 'view', showSharerName: showName });
            btn.disabled = false;
            if (!result.ok) {
                if (status) status.innerHTML = `<span class="text-danger">${escapeHtml(result.error)}</span>`;
                return;
            }
            const copied = await this._copyPlainText(result.url);
            if (status) {
                status.innerHTML = copied
                    ? '<span class="text-success">Link copied to clipboard.</span>'
                    : `<span class="text-muted">Link: <code>${escapeHtml(result.url)}</code></span>`;
            }
            refreshLinks();
        });

        /* -------------------------------------------------------------
         * Edit row — "Who can edit" + "Create & copy".
         * ----------------------------------------------------------- */
        modal.querySelector('#share-edit-create-btn')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const status = modal.querySelector('#share-edit-status');
            const orgNote = modal.querySelector('#share-edit-org-note');
            const showName = modal.querySelector('#share-edit-showname')?.checked === true;
            const requested = modal.querySelector('input[name="share-edit-audience"]:checked')?.value === 'authenticated'
                ? 'authenticated' : 'anyone';
            btn.disabled = true;
            if (status) status.innerHTML = '<span class="text-muted">Creating link…</span>';
            orgNote?.classList.add('d-none');
            const result = await this.mintShareLink(listId, {
                scope: 'edit',
                showSharerName: showName,
                editAudience: requested,
            });
            btn.disabled = false;
            if (!result.ok) {
                if (status) status.innerHTML = `<span class="text-danger">${escapeHtml(result.error)}</span>`;
                return;
            }
            /* #1791 G4-org — read the RESPONSE, never assume the request was
               honoured verbatim (rule #35): the server may have CLAMPED
               editAudience stricter than requested because an org mandates
               sign-in. Reflect the actual stored value and explain why. */
            if (requested === 'anyone' && result.editAudience === 'authenticated') {
                const anyoneRadio = modal.querySelector('#share-edit-audience-anyone');
                if (anyoneRadio) anyoneRadio.disabled = true;
                const authRadio = modal.querySelector('#share-edit-audience-auth');
                if (authRadio) authRadio.checked = true;
                if (orgNote) {
                    orgNote.textContent = 'Your organisation requires people to sign in to edit shared set lists.';
                    orgNote.classList.remove('d-none');
                }
            }
            const copied = await this._copyPlainText(result.url);
            if (status) {
                status.innerHTML = copied
                    ? '<span class="text-success">Link copied to clipboard.</span>'
                    : `<span class="text-muted">Link: <code>${escapeHtml(result.url)}</code></span>`;
            }
            refreshLinks();
        });
    }

    /**
     * One row of the Share dialog's "Active links" list.
     *
     * @param {{shareId:string,scope:string,label:?string,created:?string,lastUsed:?string,viewCount:number,editCount:number,revoked:boolean}} l
     *        Entry from `setlist_share_list`.
     * @returns {string} HTML
     * @private
     */
    _shareLinkRowHtml(l) {
        const scopeLabel = l.scope === 'edit' ? 'Edit' : 'View';
        const badgeCls = l.scope === 'edit' ? 'text-bg-warning' : 'text-bg-secondary';
        const revoked = l.revoked === true;
        const usage = l.scope === 'edit'
            ? `${Number(l.editCount) || 0} edit${Number(l.editCount) === 1 ? '' : 's'}`
            : `${Number(l.viewCount) || 0} view${Number(l.viewCount) === 1 ? '' : 's'}`;
        return `
            <div class="list-group-item d-flex align-items-center justify-content-between gap-2 small">
                <div class="flex-grow-1">
                    <span class="badge ${badgeCls}" style="font-size:0.65rem">${escapeHtml(scopeLabel)}</span>
                    ${l.label ? ` <strong>${escapeHtml(l.label)}</strong>` : ''}
                    ${revoked ? ' <span class="badge text-bg-danger" style="font-size:0.65rem">Revoked</span>' : ''}
                    <span class="text-muted d-block">
                        ${escapeHtml(usage)}
                        ${l.created ? ` &middot; created ${escapeHtml(this.formatDate(l.created))}` : ''}
                        ${l.lastUsed ? ` &middot; last used ${escapeHtml(this.formatDate(l.lastUsed))}` : ''}
                    </span>
                </div>
                ${revoked ? '' : `
                <button type="button" class="btn btn-sm btn-outline-danger btn-share-revoke" data-share-id="${escapeHtml(String(l.shareId))}">
                    Revoke
                </button>`}
            </div>`;
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

        /* Collect per-song custom arrangements (only those that have one) */
        const arrangements = {};
        list.songs.forEach(s => {
            if (s.arrangement && Array.isArray(s.arrangement) && s.arrangement.length > 0) {
                arrangements[s.id] = s.arrangement;
            }
        });

        const payload = {
            name: list.name,
            songs: list.songs.map(s => s.id),
            owner: this.getOwnerId(),
            /* #1380 — the LOCAL setlist id doubles as the server-side
               tblUserSetlists.SetlistId (same id is used by the sync API). Sending
               it lets the server LIVE-LINK the share to the owner's stored setlist
               (gated server-side on the authenticated user owning that row), so the
               owner's later edits reach link-holders instead of freezing a snapshot. */
            setlistId: listId,
        };

        /* Include arrangements map only if any songs have custom arrangements */
        if (Object.keys(arrangements).length > 0) {
            payload.arrangements = arrangements;
        }

        /* If this list was shared before, include the server-side ID for update */
        if (list.shareId) {
            payload.id = list.shareId;
        }

        try {
            const response = await apiFetch(`${this.app.config.apiUrl}?action=setlist_share`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    /* #1380 — include the bearer token when signed in so the server
                       can identify the owner and establish the live link. Anonymous
                       users (no token) still share fine — just snapshot-only. */
                    ...(this.app?.userAuth?.authHeaders?.() || {}),
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

        /* #1380 — when signed in, push the current setlists to the server FIRST so
           the (UserId, SetlistId) row exists when the share API tries to live-link
           to it. The _scheduleSync debounce (1.5 s) might not have fired yet, so a
           freshly-created or freshly-edited setlist could otherwise be shared as a
           snapshot. Best-effort: a sync failure just falls back to snapshot-only. */
        if (this.app?.userAuth?.isLoggedIn?.()) {
            /* #1649 — this path used to send a HARDCODED 'replace', bypassing
               the _syncReady gate that every other caller respects. Sharing a
               setlist before the first-login reconcile had hydrated the cache
               therefore pushed a possibly-empty local list as authoritative
               truth and wiped the account's server-side setlists. Gate it: only
               claim authority once a reconcile has actually run; otherwise
               MERGE, which still creates the row the share link needs but can
               never delete anything. */
            try {
                const res = await this.app.userAuth.syncSetlists(
                    this.getAll(),
                    this._syncReady ? 'replace' : 'merge'
                );
                this.app.userAuth._absorbSetlistSync(res);
            } catch (_e) {}
        }

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
            const response = await apiFetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    /* #1535 — send the bearer token when signed in so the server
                       can tell whether the VIEWER is this share's OWNER. Anonymous
                       viewers send no token and are never treated as the owner
                       (they still see the share + Import normally). */
                    ...(this.app?.userAuth?.authHeaders?.() || {}),
                },
            });

            /* #1380 — 410 Gone: the share was a LIVE link to a setlist the owner
               has since deleted (revoked). Signal that distinctly so the page shows
               a friendly "no longer shared" state instead of the generic "broken
               link" error (which a null return triggers). */
            if (response.status === 410) {
                return { unavailable: true };
            }

            if (!response.ok) return null;

            const data = await response.json();
            if (!data || !data.name || !Array.isArray(data.songs)) return null;

            return {
                name: data.name,
                songIds: data.songs,
                arrangements: data.arrangements || {},
                /* #1380 — true when the server resolved the owner's CURRENT setlist
                   (a live link); the page can note "updates live" to the viewer. */
                live: data.live === true,
                /* #1535 — true when the signed-in viewer IS this share's owner, so
                   the page hides the "shared with you" banner + Import buttons. */
                isOwner: data.isOwner === true,
                /* #1791 — the server-stated capability trio (rule #35: the client
                   never re-derives write access from anything but this). shareId
                   is the server-CONFIRMED id — closes the pre-#1791 gap where the
                   playlist context's sourceId was read from a key this function
                   never actually returned. */
                shareId: typeof data.shareId === 'string' ? data.shareId : '',
                scope: data.scope === 'edit' ? 'edit' : 'view',
                canWrite: data.canWrite === true,
                /* 'signin_required' → render a sign-in prompt; any OTHER value
                   (the pre-existing #1698 owner-lock reasons) → an owner-lock
                   note. Present only when canWrite is false. */
                lockReason: typeof data.lockReason === 'string' ? data.lockReason : null,
                /* #1791 G3 — opt-in byline; '' when the owner didn't opt in. */
                sharedByName: typeof data.sharedByName === 'string' ? data.sharedByName : '',
                /* #1791 — the OBJECT shape (id/title/songbook/number/arrangement),
                   present only when canWrite — lets the edit surface render
                   without N per-song fetches. */
                songsDetailed: Array.isArray(data.songsDetailed) ? data.songsDetailed : null,
            };
        } catch {
            return null;
        }
    }

    /**
     * Import a shared set list into the user's local set lists.
     * Fetches song metadata from the API, then creates a new local set list.
     * Preserves per-song custom arrangements if present.
     *
     * @param {{ name: string, songIds: string[], arrangements?: Object }} sharedData Parsed shared data
     * @returns {object} The newly created set list
     */
    async importSharedSetlist(sharedData) {
        const newList = this.create(sharedData.name);

        /* Fetch song metadata for each song ID in parallel */
        const songResults = await Promise.all(
            sharedData.songIds.map(async (songId) => {
                try {
                    const url = `${this.app.config.apiUrl}?action=song_data&id=${encodeURIComponent(songId)}`;
                    const response = await apiFetch(url, {
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

        /* Add songs to the new list in order, preserving custom arrangements */
        for (const song of songResults) {
            if (song) {
                /* Attach custom arrangement if one exists for this song */
                if (sharedData.arrangements && sharedData.arrangements[song.id]) {
                    song.arrangement = sharedData.arrangements[song.id];
                }
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
        /* #1380 — distinct "no longer shared" state (a revoked LIVE link), so a
           deleted-by-owner share reads as intentional, not as a broken link. */
        const unavailableEl = document.getElementById('shared-setlist-unavailable');

        if (!loadingEl || !errorEl || !contentEl) return;

        /* Determine if this is a server-side share ID or legacy inline base64
         * data. A server id is the shared-fold grammar (#1791): legacy 8-hex OR
         * a base64url capability token, 6–64 chars, no base64 padding. A legacy
         * inline blob carries `+`/`/`/`=` and runs far longer, so it correctly
         * falls to parseLegacySharedSetlist(). SHARE_ID_RE is the client mirror
         * of PHP sharedSetlistSafeShareId(). */
        let sharedData = null;
        const isShortId = SHARE_ID_RE.test(shareData);

        if (isShortId) {
            /* Fetch from server-side storage (#155) */
            sharedData = await this.fetchSharedSetlist(shareData);
        } else {
            /* Legacy base64 fallback (pre-#155) */
            sharedData = this.parseLegacySharedSetlist(shareData);
        }

        /* #1380 — three outcomes:
           (a) unavailable → the live link's underlying setlist was deleted (410):
               show the friendly "no longer shared" block (if the page provides it),
               else fall back to the generic error.
           (b) null → genuinely broken / malformed link: the existing error block.
           (c) data → render. */
        if (sharedData && sharedData.unavailable) {
            loadingEl.classList.add('d-none');
            if (unavailableEl) {
                unavailableEl.classList.remove('d-none');
            } else {
                errorEl.classList.remove('d-none');
            }
            return;
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
        /* #1380 — optional "this set list updates live" note when the share is a
           live link (the owner's edits flow through). */
        const liveNoteEl = document.getElementById('shared-setlist-live-note');
        if (liveNoteEl) {
            liveNoteEl.classList.toggle('d-none', !sharedData.live);
        }

        /* #1535 — the OWNER viewing their OWN shared link shouldn't be told it was
           "shared with you" nor offered to Import it into itself. Hide the shared
           banner + both Import buttons and show the owner note instead; a non-owner
           / anonymous viewer keeps the normal shared+import UI. (The live-link note
           above stays useful — those are the owner's own edits flowing through.) */
        const viewerIsOwner = sharedData.isOwner === true;
        document.getElementById('shared-setlist-banner')?.classList.toggle('d-none', viewerIsOwner);
        document.getElementById('shared-setlist-owner-note')?.classList.toggle('d-none', !viewerIsOwner);
        /* #1790 — the top "Import" button became the "Start set list" button (shown
           to everyone, owner included); only the single bottom "Save a copy" button
           is hidden for the owner now (nothing to import into itself, #1535). */
        document.getElementById('shared-setlist-import-btn-bottom')?.classList.toggle('d-none', viewerIsOwner);

        if (titleEl) titleEl.textContent = sharedData.name;
        if (countEl) countEl.textContent = sharedData.songIds.length;
        if (pluralEl) pluralEl.textContent = sharedData.songIds.length !== 1 ? 's' : '';

        /* #1791 G3 — opt-in "Shared by <name>" byline. textContent, not
           innerHTML — the browser's own text escaping is the whole job here,
           so there is nothing an explicit escapeHtml() call would add. */
        const bylineEl = document.getElementById('shared-setlist-byline');
        if (bylineEl) {
            if (sharedData.sharedByName) {
                bylineEl.textContent = `Shared by ${sharedData.sharedByName}`;
                bylineEl.classList.remove('d-none');
            } else {
                bylineEl.textContent = '';
                bylineEl.classList.add('d-none');
            }
        }

        /* #1791 — server-stated write capability (rule #35: never re-derived
           here). `needsSignIn` / `otherLock` are mutually exclusive readings
           of the SAME `lockReason` the server sends only when canWrite is
           false: 'signin_required' is this link's EditAudience; any other
           value is a pre-existing #1698 owner-account-lock reason. Neither
           touches the READ-ONLY listing below — viewing + tap-to-play stay
           the unchanged #1790 behaviour; only the edit affordance is gated. */
        const isEditable  = sharedData.canWrite === true;
        const needsSignIn = sharedData.lockReason === 'signin_required';
        const otherLock   = !!sharedData.lockReason && !needsSignIn;

        const signinPromptEl = document.getElementById('shared-setlist-signin-prompt');
        const lockedNoteEl   = document.getElementById('shared-setlist-locked-note');
        signinPromptEl?.classList.toggle('d-none', !needsSignIn);
        lockedNoteEl?.classList.toggle('d-none', !otherLock);
        document.getElementById('shared-setlist-signin-btn')?.addEventListener('click', () => {
            this.app.userAuth?.showAuthModal('login');
        });
        if (needsSignIn) {
            /* Re-run this whole init once, after a successful sign-in, so a
               visitor who signs in mid-visit gets the edit surface without a
               manual reload. {once:true} — spends itself; a page that never
               signs in never accumulates a second listener on the next
               navigation because there is no next navigation for THIS
               instance to leak into (initSharedSetListPage runs fresh per
               page load, and the listener target is `document`, not this
               function's closure — but {once:true} still bounds it to
               exactly one firing, which is all a single page visit needs). */
            document.addEventListener(EVT_AUTH_CHANGED, () => {
                this.initSharedSetListPage(shareData);
            }, { once: true });
        }

        /* Render song list. `#shared-setlist-songs` renders ONE of two row
           shapes: the #1790 read-only playlist rows (progressively enriched
           below) for everyone else, or — when the server says canWrite —
           #1791's editable rows via `sharedSetlistRowsHtml()`, the SAME
           helper `renderSharedSetListDetail()` uses (modularity rule — one
           row template, not two forks). The editable branch is seeded from
           `songsDetailed`, which already carries full metadata, so it skips
           the per-song enrichment fetches entirely. */
        const songsContainer = document.getElementById('shared-setlist-songs');
        const editStatusEl   = document.getElementById('shared-setlist-edit-status');
        /** @type {?Array<object>} Staged copy — non-null only while the edit surface is mounted. */
        let editableSongs = null;

        if (songsContainer) {
            if (isEditable && Array.isArray(sharedData.songsDetailed)) {
                editableSongs = sharedData.songsDetailed.map(s => ({ ...s }));
            } else {
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
        }

        /* Show content, hide loading */
        loadingEl.classList.add('d-none');
        contentEl.classList.remove('d-none');

        /* Enrich song items with metadata from the API — skipped for the edit
           surface, which already has full metadata from `songsDetailed`. */
        if (!editableSongs) {
            this.enrichSharedSongItems(sharedData.songIds);
        }

        /* #1533 — ARM ON TAP for a SHARED list. This is the case that could
           not work at all before: a shared list has no local record, so
           getById() returns null and the old getNavigation() bailed. The
           context carries its own order, so it needs no local record.

           Titles start empty and are filled by enrichSharedSongItems() above,
           which resolves them asynchronously. We re-read them at click time
           rather than at render time so "Next: <title>" is populated if
           enrichment has landed, and degrades to a plain "Next" if not.

           #1791 — the selector is now generic (`[data-song-id]` /
           `a[data-navigate="song"]`, not the read-only `.shared-song-item` /
           `.shared-song-title` classes) so this ONE delegated listener, bound
           once to the stable `songsContainer` element, keeps working when the
           edit surface below swaps its innerHTML for the editable row markup —
           re-binding per render (the collab-detail pattern) is unnecessary
           here because the container itself is never replaced, only its
           contents. */
        /* #1790 — extracted so the explicit "Start set list" button arms the
           Prev/Next context the SAME way a tap does (no second copy of the
           title-harvest + setPlaylistContext call — modularity rule). Titles are
           re-read from the DOM at arm time so "Next: <title>" is populated once
           enrichSharedSongItems() has landed, and degrades to a plain "Next"
           before then. */
        const armSharedContext = () => {
            const titles = {};
            songsContainer?.querySelectorAll('[data-song-id]').forEach((item) => {
                const id = item.dataset.songId;
                const t  = item.querySelector('a[data-navigate="song"]')?.textContent?.trim();
                /* Pre-enrichment the anchor still shows the raw id — storing
                   that would render "Next: CP-1008", which is worse than the
                   plain word. */
                if (id && t && t !== id) titles[id] = t;
            });
            this.setPlaylistContext({
                songIds:  sharedData.songIds,
                titles,
                name:     sharedData.name,
                source:   'shared',
                sourceId: String(sharedData.shareId || shareData || ''),
            });
        };

        songsContainer?.addEventListener('click', (e) => {
            const link = e.target instanceof HTMLElement
                ? e.target.closest('a[data-navigate="song"]')
                : null;
            if (!link) return;
            /* The router's own delegated handler performs the navigation; we only
               arm the context first so the bar is correct on arrival. */
            armSharedContext();
        });

        /* #1790 — explicit "Start set list" button: arm the context, then jump to
           song 1 — for users who don't discover tap-to-play. Shown to everyone
           (owner included; you can play your own shared list). No-op on an empty
           list. */
        document.getElementById('shared-setlist-start-btn')?.addEventListener('click', () => {
            const firstId = sharedData.songIds && sharedData.songIds[0];
            if (!firstId) return;
            armSharedContext();
            this.app.router.navigate('/song/' + encodeURIComponent(firstId));
        });

        /* Bind import buttons — skipped entirely for the owner (#1535); their
           buttons are hidden above, so there's nothing to import into itself. */
        if (!viewerIsOwner) {
            const importHandler = async () => {
                const imported = await this.importSharedSetlist(sharedData);
                if (imported) {
                    this.app.showToast(`Set list "${sharedData.name}" imported with ${imported.songs.length} songs`, 'success', 3000);
                    this.app.router.navigate('/setlist');
                } else {
                    this.app.showToast('Failed to import set list', 'danger', 3000);
                }
            };

            /* #1790 — only the single bottom "Save a copy" button remains
               (the top button is now "Start set list"). */
            document.getElementById('shared-setlist-import-btn-bottom')?.addEventListener('click', importHandler);
        }

        /* #1791 — the ANONYMOUS/TOKEN edit surface: reorder/remove on the
           public shared page itself, the SAME staged-copy pattern as
           `renderSharedSetListDetail()` (optimistic local mutation, explicit
           push, re-render from the server's answer on refusal, aria-live
           status) — pushing to `setlist_token_update` instead of
           `setlist_collab_update`. */
        if (editableSongs && songsContainer) {
            editStatusEl?.classList.remove('d-none');

            /* Flips false the moment a write comes back 401 signin_required
               (the audience tightened mid-session, e.g. an org policy change
               or a token whose canWrite the client's earlier read had stale)
               — renderEditRows() then draws the SAME rows read-only instead
               of discarding sharedSetlistRowsHtml() for a second template. */
            let writeAllowed = true;
            let lastGoodSongs = editableSongs.map(s => ({ ...s }));

            /* Keeps the header count/plural AND the tap-to-play/Start order
               (sharedData.songIds) in step with every reorder/remove — those
               were fixed at page-load time and would otherwise go stale the
               moment the first edit landed. */
            const syncHeaderFromSongs = () => {
                sharedData.songIds = editableSongs.map(s => String(s.id));
                if (countEl) countEl.textContent = editableSongs.length;
                if (pluralEl) pluralEl.textContent = editableSongs.length !== 1 ? 's' : '';
            };

            const renderEditRows = () => {
                songsContainer.innerHTML = sharedSetlistRowsHtml(editableSongs, writeAllowed);
                if (writeAllowed) {
                    bindSharedSetlistRowControls(songsContainer, editableSongs, {
                        canEdit: true,
                        onMutate: () => {
                            syncHeaderFromSongs();
                            renderEditRows();
                            pushTokenEdit();
                        },
                    });
                }
            };

            const pushTokenEdit = () => {
                if (editStatusEl) editStatusEl.innerHTML = '<span class="text-muted">Saving…</span>';
                return apiFetch(`${this.app.config.apiUrl}?action=setlist_token_update`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ shareId: sharedData.shareId, songs: editableSongs }),
                })
                    .then(r => r.json().then(j => ({ status: r.status, ok: r.ok, data: j })))
                    .then(res => {
                        if (!res.ok) {
                            if (res.status === 401 && res.data && res.data.reason === 'signin_required') {
                                /* Distinct status/reason (rule #35, not prose) —
                                   downgrade to the sign-in prompt rather than
                                   keep offering controls the server will keep
                                   refusing. */
                                writeAllowed = false;
                                editableSongs.splice(0, editableSongs.length, ...lastGoodSongs.map(s => ({ ...s })));
                                syncHeaderFromSongs();
                                renderEditRows();
                                editStatusEl?.classList.add('d-none');
                                signinPromptEl?.classList.remove('d-none');
                                return false;
                            }
                            /* Any other refusal (revoked / expired / rate-limited /
                               owner-locked) — REVERT the optimistic edit to the
                               last server-confirmed state and re-render, so the
                               UI never keeps showing a change the server refused. */
                            editableSongs.splice(0, editableSongs.length, ...lastGoodSongs.map(s => ({ ...s })));
                            syncHeaderFromSongs();
                            renderEditRows();
                            if (editStatusEl) {
                                editStatusEl.innerHTML = `<span class="text-danger">${escapeHtml((res.data && res.data.error) || 'Save failed.')}</span>`;
                            }
                            return false;
                        }
                        lastGoodSongs = editableSongs.map(s => ({ ...s }));
                        if (editStatusEl) editStatusEl.innerHTML = '<span class="text-success">Saved.</span>';
                        return true;
                    })
                    .catch(() => {
                        if (editStatusEl) editStatusEl.innerHTML = '<span class="text-danger">Save failed — check your connection.</span>';
                        return false;
                    });
            };

            renderEditRows();
        }
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
                    const response = await apiFetch(url, {
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
                    /* #1531 — show the full Songbook NAME, not the abbreviation.
                       textContent (not innerHTML) so the plain name is used:
                       the song detail's own songbookName if present, else the
                       client registry, else the six fallbacks, else the abbr. */
                    if (metaEl) {
                        metaEl.textContent = json.song.songbookName
                            || songbookFullName(json.song.songbook)
                            || SONGBOOK_NAMES[json.song.songbook]
                            || json.song.songbook || '';
                    }
                    if (badge) {
                        badge.textContent = json.song.number ?? '';
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
     * Render the "Upcoming scheduled set lists" section at the top of
     * the setlist overview (#398). Silent no-op when the user isn't
     * authenticated — the endpoint 401s and we drop the node.
     * @private
     */
    _renderUpcomingScheduledSetlists() {
        const container = document.getElementById('setlist-container');
        if (!container) return;
        /* Remove any previous render so we don't stack. */
        document.getElementById('setlist-upcoming-card')?.remove();

        apiFetch('/api?action=setlist_schedule_upcoming', { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => {
                const upcoming = data.upcoming || [];
                if (upcoming.length === 0) return;
                const node = document.createElement('div');
                node.id = 'setlist-upcoming-card';
                node.className = 'card mb-3';
                node.innerHTML = `
                    <div class="card-body">
                        <h6 class="mb-2"><i class="fa-solid fa-calendar-days me-2"></i>Up next</h6>
                        <ul class="list-group list-group-flush">
                            ${upcoming.slice(0, 5).map(u => `
                                <li class="list-group-item d-flex justify-content-between align-items-center"
                                    data-setlist-id="${escapeHtml(u.SetlistId)}" role="button">
                                    <div>
                                        <strong>${escapeHtml(u.name || u.SetlistId)}</strong>
                                        ${u.Notes ? `<small class="text-muted d-block">${escapeHtml(u.Notes)}</small>` : ''}
                                    </div>
                                    <span class="badge bg-primary">${this.formatDate(u.ScheduledDate)}</span>
                                </li>
                            `).join('')}
                        </ul>
                    </div>`;
                container.parentNode.insertBefore(node, container);
                /* Clicking a row opens the detail view for that setlist. */
                node.querySelectorAll('[data-setlist-id]').forEach(row => {
                    row.addEventListener('click', () => {
                        this.renderSetListDetail(row.dataset.setlistId);
                    });
                });
            })
            .catch(() => {
                /* Unauthenticated or endpoint unreachable — fine, no card. */
            });
    }

    /**
     * Show and populate the setlist schedule card (#398). Fetches any
     * existing schedule for (user, setlist) and pre-fills the inputs,
     * then wires Save / Clear. Silent no-op if the card isn't in the
     * DOM (e.g. on the home page) or if the user isn't authenticated
     * (endpoints return 401; we just keep the card hidden).
     * @param {string} listId
     * @private
     */
    _renderSetlistSchedule(listId) {
        const card      = document.getElementById('setlist-schedule-card');
        const dateInput = document.getElementById('schedule-date');
        const notesIn   = document.getElementById('schedule-notes');
        const saveBtn   = document.getElementById('btn-schedule-save');
        const resultEl  = document.getElementById('schedule-result');
        if (!card || !dateInput || !saveBtn) return;

        /* Visibility tracks the authenticated-user check via the API's
           401 on setlist_schedule_current. If we get 401, hide card. */
        apiFetch(`/api?action=setlist_schedule_current&setlistId=${encodeURIComponent(listId)}`, {
            credentials: 'same-origin',
        })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => {
                card.style.display = '';
                const sched = data.schedule;
                if (sched) {
                    dateInput.value = sched.ScheduledDate || '';
                    if (notesIn) notesIn.value = sched.Notes || '';
                    if (resultEl) {
                        resultEl.innerHTML = `<span class="text-success"><i class="fa-solid fa-check-circle me-1"></i>Scheduled for ${this.formatDate(sched.ScheduledDate)}</span>`;
                    }
                } else {
                    dateInput.value = '';
                    if (notesIn) notesIn.value = '';
                    if (resultEl) resultEl.innerHTML = '';
                }
                this._bindScheduleButtons(listId);
            })
            .catch(() => {
                card.style.display = 'none';
            });
    }

    _bindScheduleButtons(listId) {
        const card     = document.getElementById('setlist-schedule-card');
        const dateIn   = document.getElementById('schedule-date');
        const notesIn  = document.getElementById('schedule-notes');
        const saveBtn  = document.getElementById('btn-schedule-save');
        const resultEl = document.getElementById('schedule-result');
        if (!card || !saveBtn || !dateIn) return;

        /* Replace to avoid stacking listeners across re-renders. */
        const newSave = saveBtn.cloneNode(true);
        saveBtn.parentNode.replaceChild(newSave, saveBtn);
        newSave.addEventListener('click', () => {
            const dateVal = (dateIn.value || '').trim();
            if (!dateVal) {
                if (resultEl) resultEl.innerHTML = '<span class="text-warning">Pick a date first.</span>';
                return;
            }
            apiFetch('/api?action=setlist_schedule_set', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    setlistId: listId,
                    date: dateVal,
                    notes: notesIn?.value || '',
                }),
            })
                .then(r => r.json().then(j => ({ ok: r.ok, data: j })))
                .then(res => {
                    if (!res.ok) {
                        if (resultEl) resultEl.innerHTML = `<span class="text-danger">${escapeHtml(res.data.error || 'Save failed.')}</span>`;
                        return;
                    }
                    if (resultEl) resultEl.innerHTML = `<span class="text-success"><i class="fa-solid fa-check-circle me-1"></i>Saved for ${this.formatDate(dateVal)}</span>`;
                });
        });

        /* Add a Clear button next to Save if it's not already there. */
        if (!document.getElementById('btn-schedule-clear')) {
            const clearBtn = document.createElement('button');
            clearBtn.id = 'btn-schedule-clear';
            clearBtn.type = 'button';
            clearBtn.className = 'btn btn-outline-secondary';
            clearBtn.textContent = 'Clear';
            newSave.parentNode.appendChild(clearBtn);
            clearBtn.addEventListener('click', () => {
                apiFetch('/api?action=setlist_schedule_clear', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ setlistId: listId }),
                })
                    .then(r => r.ok ? r.json() : Promise.reject(r))
                    .then(() => {
                        dateIn.value = '';
                        if (notesIn) notesIn.value = '';
                        if (resultEl) resultEl.innerHTML = '<span class="text-muted">Schedule cleared.</span>';
                    })
                    .catch(() => {
                        if (resultEl) resultEl.innerHTML = '<span class="text-danger">Clear failed.</span>';
                    });
            });
        }
    }

    /**
     * Append a "Collaborators" section to the setlist detail view (#398).
     * Owner can invite by email, change permission, or revoke. The list
     * is fetched from setlist_collab_list; endpoints silently 401 for
     * anonymous users so we hide the section on failure.
     * @param {HTMLElement} container
     * @param {string} listId
     * @private
     */
    _renderSetlistCollaborators(container, listId) {
        if (!container) return;
        const section = document.createElement('div');
        section.className = 'card mt-3';
        section.id = 'setlist-collab-card';
        section.innerHTML = `
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="mb-0"><i class="fa-solid fa-users me-2"></i>Collaborators</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-invite-collaborator">
                        <i class="fa-solid fa-user-plus me-1"></i>Invite
                    </button>
                </div>
                <ul class="list-group list-group-flush" id="collaborator-list">
                    <li class="list-group-item small text-muted">Loading…</li>
                </ul>
            </div>`;
        container.appendChild(section);

        const refresh = () => {
            apiFetch(`/api?action=setlist_collab_list&setlistId=${encodeURIComponent(listId)}`, {
                credentials: 'same-origin',
            })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    const list = document.getElementById('collaborator-list');
                    if (!list) return;
                    const rows = data.collaborators || [];
                    if (rows.length === 0) {
                        list.innerHTML = '<li class="list-group-item small text-muted">No collaborators yet — click Invite to share.</li>';
                        return;
                    }
                    list.innerHTML = rows.map(c => `
                        <li class="list-group-item d-flex align-items-center justify-content-between">
                            <div>
                                <strong>${escapeHtml(c.username)}</strong>
                                <small class="text-muted d-block">${escapeHtml(c.email)} &middot; ${escapeHtml(c.permission)}</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-collab"
                                    data-id="${c.id}" aria-label="Remove collaborator">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </li>
                    `).join('');
                    list.querySelectorAll('.btn-remove-collab').forEach(btn => {
                        btn.addEventListener('click', () => {
                            apiFetch('/api?action=setlist_collab_remove', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                                body: JSON.stringify({
                                    setlistId: listId,
                                    collaboratorId: Number(btn.dataset.id),
                                }),
                            })
                                .then(r => r.ok ? r.json() : Promise.reject(r))
                                .then(() => refresh())
                                .catch(() => alert('Remove failed.'));
                        });
                    });
                })
                .catch(() => {
                    /* Likely unauthenticated or feature-flag off — drop the section. */
                    section.remove();
                });
        };

        section.querySelector('#btn-invite-collaborator').addEventListener('click', () => {
            const email = prompt(
                'Invite a collaborator by email.\nThey must already have an iHymns account.'
            );
            if (!email) return;
            const permission = (prompt('Permission: "view" or "edit"?', 'edit') || 'edit').trim().toLowerCase();
            apiFetch('/api?action=setlist_collab_invite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    setlistId: listId,
                    collaboratorEmail: email.trim(),
                    permission,
                }),
            })
                .then(r => r.json().then(j => ({ ok: r.ok, data: j })))
                .then(res => {
                    if (!res.ok) { alert(res.data.error || 'Invite failed.'); return; }
                    refresh();
                });
        });

        refresh();
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
     * OPTIONAL EXPIRY (#1661)
     *
     * A set list normally lives until the user deletes it. An expiry is an
     * OPT-IN convenience for the throwaway ones ("Christmas Eve 2026"), and
     * the SERVER owns the actual conversion — everything here is display and
     * intent-capture only. Two devices with different clocks must never
     * disagree about whether a set list still exists mid-service, which is
     * exactly what per-device expiry would produce.
     * ===================================================================== */

    /**
     * Parse a stored expiry into a Date, or null.
     *
     * The stored form is the wire form, 'YYYY-MM-DD HH:MM:SS' in UTC. It is
     * parsed by replacing the space with 'T' and appending 'Z' rather than
     * handed to `new Date()` raw: the space-separated form is NOT part of the
     * ECMAScript Date Time String Format, so browsers parse it by
     * implementation-specific fallback — historically as LOCAL time in some
     * and as invalid in others. Being explicit is the difference between an
     * expiry that reads correctly everywhere and one that is silently off by
     * the viewer's UTC offset.
     *
     * @param {?string} expiresAt
     * @returns {?Date}
     */
    _parseExpiry(expiresAt) {
        if (typeof expiresAt !== 'string' || !expiresAt) return null;
        const d = new Date(expiresAt.replace(' ', 'T') + 'Z');
        return Number.isNaN(d.getTime()) ? null : d;
    }

    /**
     * A small "Expires …" chip for a set list, or '' when it never expires.
     *
     * Returns MARKUP, so every value it interpolates is escaped — the date is
     * machine-generated, but escaping unconditionally is cheaper than
     * re-deriving that fact at each call site.
     *
     * @param {object} list
     * @returns {string} HTML, or '' when there is no expiry.
     */
    expiryBadge(list) {
        const when = this._parseExpiry(list?.expiresAt);
        if (!when) return '';
        const label = when.toLocaleString(undefined, {
            day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });
        /* Past-dated means the server simply has not been asked yet (expiry is
           converted lazily, on the next sync) — say "Expired" rather than
           pretending it is still scheduled. */
        const past = when.getTime() <= Date.now();
        return ` <span class="badge ${past ? 'bg-danger-subtle text-danger-emphasis' : 'bg-warning-subtle text-warning-emphasis'} ms-1"
                       title="${escapeHtml(past ? 'Expired' : 'Expires')} ${escapeHtml(label)}">
                    <i class="fa-solid fa-hourglass-half me-1" aria-hidden="true"></i>${escapeHtml(past ? 'Expired' : label)}
                 </span>`;
    }

    /**
     * Prompt for an expiry date and store it (or clear it).
     *
     * Uses a plain date prompt rather than a bespoke modal: expiry is a
     * coarse, rarely-used convenience, and 'YYYY-MM-DD' is unambiguous. The
     * time is pinned to the END of the chosen day in the user's own time zone
     * — "expires on the 25th" plainly means "still works all of the 25th",
     * and `Date.UTC` on a locally-constructed date would instead cut it short
     * by the viewer's offset. The conversion to UTC happens here, once, so
     * the wire value is always an absolute instant.
     *
     * @param {string} listId
     */
    async showExpiryDialog(listId) {
        const list = this.getById(listId);
        if (!list) return;

        const current = this._parseExpiry(list.expiresAt);
        /* Pre-fill with the local calendar date of any existing expiry. */
        const prefill = current
            ? `${current.getFullYear()}-${String(current.getMonth() + 1).padStart(2, '0')}-${String(current.getDate()).padStart(2, '0')}`
            : '';

        const answer = await this.app.showPrompt(
            'Expire this set list on (YYYY-MM-DD) — leave blank to never expire:',
            prefill,
            { title: 'Set List Expiry', okText: 'Save' }
        );
        /* A cancelled prompt returns null and must change nothing; an empty
           STRING is a deliberate "clear the expiry" and must. */
        if (answer === null || answer === undefined) return;

        const trimmed = String(answer).trim();
        if (trimmed === '') {
            this.setExpiry(listId, null);
            this.app.showToast('Expiry removed — this set list will not expire.', 'success', 3000);
            this.renderSetListDetail(listId);
            return;
        }

        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(trimmed);
        if (!m) {
            this.app.showToast('Please use the format YYYY-MM-DD.', 'warning', 3000);
            return;
        }
        /* End of that local day. Constructing with the numeric constructor
           (not Date.parse of the string) is what keeps it local — the
           'YYYY-MM-DD' string form is defined to parse as UTC. */
        const localEnd = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 23, 59, 59);
        if (Number.isNaN(localEnd.getTime())) {
            this.app.showToast('That date is not valid.', 'warning', 3000);
            return;
        }
        const utc = localEnd.toISOString().slice(0, 19).replace('T', ' ');
        this.setExpiry(listId, utc);
        this.app.showToast(
            `This set list will expire after ${localEnd.toLocaleDateString()}.`,
            'success',
            3000
        );
        this.renderSetListDetail(listId);
    }

    /* =====================================================================
     * PRINT SET LIST (#113)
     * ===================================================================== */

    /**
     * Print a set list with full lyrics for all songs.
     * Offers the SAME template picker as the single-song Print (#1789 — rule
     * #22, consume the shared #1767 print-template engine rather than
     * re-forking it) and renders every song through it. Only the cover page
     * and running order stay set-list-specific.
     * @param {object} list The set list { id, name, createdAt, songs }
     */
    async printSetList(list) {
        if (!list.songs || list.songs.length === 0) {
            this.app.showToast('No songs to print.', 'warning');
            return;
        }

        /* Same picker as the single-song Print — gives the set list a
           template choice it never had before (#1789). Cancel (Esc / backdrop
           / Cancel button) resolves null and we simply don't print.
           #1767 remainder P4/P6 — `pickPrintTemplate()` now resolves
           `{ tpl, action }`; `offerPdf: true` shows the Download-PDF button
           whenever the SAME `?ping=1` auth/engine probe the single-song path
           uses says the server-PDF endpoint is reachable (print.js's own
           memoised check — never a second probe here). */
        const templates = await loadTemplates(this.app);
        const picked = await pickPrintTemplate(this.app, templates, { offerPdf: true });
        if (!picked) { return; }
        const { tpl, action } = picked;

        /* #1767 remainder P6 (plan §4.4) — batch server-PDF: render EVERY
           song via the ONE renderer and POST them as a single `mode:'batch'`
           request. Delegated to its own method (below) — the browser-Print
           path underneath is UNCHANGED. */
        if (action === 'pdf') {
            await this._downloadSetListPdf(list, tpl);
            return;
        }

        this.app.showToast('Preparing print layout...', 'info', 2000);

        /* Fetch each song's STRUCTURED data (?action=song_data via the shared
           fetchSong()) — not the screen-chromed `?page=song` fragment the old
           code injected raw. A failed fetch yields null and is skipped below;
           the running order (built from list.songs meta, not this array)
           still lists it. */
        const songs = await Promise.all(
            list.songs.map((s) => fetchSong(this.app, s.id))
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

        /* Build running order summary — from list.songs meta (NOT the fetched
           `songs` array), so a song whose structured-data fetch failed still
           appears in the order. */
        const orderSummary = list.songs.map((song, i) =>
            `<tr><td class="pe-3 text-muted">${i + 1}.</td><td>${escapeHtml(toTitleCase(song.title))}</td><td class="text-muted">${escapeHtml(SONGBOOK_NAMES[song.songbook] || song.songbook)}${song.number != null ? ' #' + song.number : ''}</td></tr>`
        ).join('');

        /* Build individual song pages — each rendered through the SAME
           renderTemplateBodyHtml() the single-song Print uses, in the
           template the user just picked. The template's own `title` block
           already prints the song title, so the wrapper here only adds a
           small numbered badge for the set-list position (skip nulls from a
           failed fetch; the badge index still reflects the ORIGINAL list
           position). */
        let songPagesHtml = '';
        songs.forEach((song, index) => {
            if (!song) { return; }
            songPagesHtml += `
                <div class="print-song-page">
                    <div class="print-song-order-badge">${index + 1}</div>
                    ${renderTemplateBodyHtml(song, tpl)}
                </div>`;
        });

        printWindow.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>${escapeHtml(list.name)} — iHymns Set List</title>
    <style>${printCss(tpl.pageOptions)}
        /* NOTE: no @page size override here — printCss() above already set
           @page { size: ... } from the chosen template's pageOptions (#1767 G);
           re-declaring size here would silently force it back to A4 and
           defeat the whole point of applying the template (rule #22). */
        @page { margin: 2cm; }

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

        /* Individual songs — each a fresh page rendered by the shared
           print-template engine (renderTemplateBodyHtml). The badge is a
           small circled position number that floats clear of the template's
           own title block rather than replacing it. */
        .print-song-page { page-break-before: always; position: relative; }
        .print-song-order-badge { display: inline-block; width: 1.6em; height: 1.6em; line-height: 1.6em; text-align: center; background: #333; color: #fff; border-radius: 50%; font-weight: bold; font-size: 10pt; float: left; margin-right: 0.6em; margin-top: 0.1em; }

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
     * #1767 remainder P6 (plan §4.4) — set-list "Download PDF": renders
     * EVERY song via the SAME renderTemplateBodyHtml()/applyCustomLayout()
     * the browser-print path above and the single-song downloadSongPdf()
     * (print.js) both use — the one-renderer invariant holds for the batch
     * path exactly as it does for the single-song one, because this calls
     * the identical client function once per song, never a second renderer.
     * Assembles `documents: [...]` for the WHOLE (possibly capped) list and
     * POSTs `mode: 'batch'` via the shared downloadPrintPdf() (print.js) —
     * never a second POST/status-branch/download implementation.
     *
     * CCLI copies prompt fires ONCE for the whole set (plan §6.3's
     * multi-song design), reusing the SAME printUsageContextFor()/
     * promptForCopies() the single-song path uses — checked against the
     * FIRST song in the (server-fetched) set that actually carries a CCLI
     * number, because the underlying question — "does THIS signed-in
     * account hold a qualifying CCLI licence" — is licence-scoped, not
     * song-scoped, so any CCLI-bearing song answers it for the whole batch.
     * A set with no CCLI-numbered song at all has nothing to report and
     * skips the prompt entirely. The SERVER re-resolves the licence AND
     * each song's own CCLI number again from scratch
     * (manage/print-pdf.php) — this client-side check only decides whether
     * to SHOW the prompt, never what gets logged.
     *
     * @param {object} list The set list { id, name, songs }.
     * @param {object} tpl  The picked print template.
     */
    async _downloadSetListPdf(list, tpl) {
        this.app.showToast('Preparing PDF…', 'info', 2000);

        /* v1 batch cap (plan §4.4) mirrors the server's PDF_MAX_DOCUMENTS —
           sliced HONESTLY (never silently) so a very large set list's PDF
           names the songs it actually included. Only THIS (PDF) path caps —
           the browser-Print path above has no such limit (window.print()
           paginates whatever HTML it's given). */
        const PDF_BATCH_SONG_CAP = 50;
        const overCap = list.songs.length > PDF_BATCH_SONG_CAP;
        const songsMeta = overCap ? list.songs.slice(0, PDF_BATCH_SONG_CAP) : list.songs;

        const songs = (await Promise.all(
            songsMeta.map((s) => fetchSong(this.app, s.id))
        )).filter(Boolean);
        if (!songs.length) {
            this.app.showToast('Could not load any songs to build the PDF.', 'danger', 3500);
            return;
        }
        if (overCap) {
            this.app.showToast(`PDF includes the first ${PDF_BATCH_SONG_CAP} of ${list.songs.length} songs.`, 'info', 5000);
        }

        const ccliSong = songs.find((s) => String((s && s.ccli) || '').trim() !== '');
        let copies = null;
        if (ccliSong) {
            const usageCtx = await printUsageContextFor(this.app, ccliSong.publicId || ccliSong.id);
            if (usageCtx.promptCopies) {
                copies = await promptForCopies(this.app);
                if (copies === null) { return; }
            }
        }

        const documents = songs.map((song) => {
            const contentHtml = renderTemplateBodyHtml(song, tpl);
            const bodyHtml = (tpl && tpl.layoutHtml)
                ? applyCustomLayout(tpl.layoutHtml, song, contentHtml)
                : contentHtml;
            return {
                bodyHtml,
                meta: {
                    songId: song.publicId || song.id || '',
                    title:  song.title || 'Untitled',
                    lang:   song.language || 'en',
                    dir:    'ltr',
                    book:   song.songbookName || song.songbook || '',
                },
            };
        });

        const payload = {
            mode: 'batch',
            documents,
            css: printCss(tpl.pageOptions),
            pageOptions: tpl.pageOptions || {},
            filename: pdfFilenameFor({ title: list.name }),
            // #1897 W3 — the set-list this whole batch was printed FOR. The
            // server sanitises it and rides it into each qualifying document's
            // printUsageLog() so the CCLI report can attribute the print to a
            // service's list (an absent/blank id is stored as NULL server-side).
            setlistId: list.id,
        };
        if (copies != null) { payload.copies = copies; }

        await downloadPrintPdf(this.app, payload);
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
}
