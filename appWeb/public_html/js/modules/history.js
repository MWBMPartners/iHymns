/**
 * iHymns — Recently Viewed History Module (#92)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Tracks recently viewed songs in localStorage and displays them
 * on the home page for quick access. Stores the last 20 songs,
 * prevents duplicates (re-viewing moves a song to the top), and
 * provides a clear option.
 *
 * STORAGE FORMAT (localStorage key: 'ihymns_history'):
 *   [{ id: "CP-0001", title: "...", songbook: "CP", number: 1, viewedAt: "ISO" }, ...]
 *   Ordered by most recent first.
 */
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { toTitleCase } from '../utils/text.js';
import { STORAGE_HISTORY, STORAGE_HISTORY_BACKFILLED, songbookLabel } from '../constants.js';

export class History {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key */
        this.storageKey = STORAGE_HISTORY;

        /** @type {number} Maximum number of entries to store */
        this.maxEntries = 20;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Record a song view. Adds the song to the top of history,
     * removing any existing duplicate entry first.
     *
     * @param {string} songId   Song ID (e.g., 'CP-0001')
     * @param {string} title    Song title
     * @param {string} songbook Songbook abbreviation
     * @param {number} number   Song number
     */
    recordView(songId, title, songbook, number) {
        let history = this.getAll();

        /* Remove duplicate if already in history */
        history = history.filter(h => h.id !== songId);

        /* Add to the top */
        history.unshift({
            id: songId,
            title: title || '',
            songbook: songbook || '',
            number: number || 0,
            viewedAt: new Date().toISOString(),
        });

        /* Trim to max entries */
        if (history.length > this.maxEntries) {
            history = history.slice(0, this.maxEntries);
        }

        this.saveAll(history);
    }

    /**
     * Get all history entries, ordered by most recent first.
     *
     * `recordView()` dedupes on write, but localStorage may carry legacy
     * entries written before that logic existed (#549). Dedupe on read so
     * the returned list is always unique-by-id, keeping the first
     * occurrence (which is the most recent because the array is ordered
     * newest-first).
     *
     * @returns {Array} History entries — unique by id
     */
    getAll() {
        let raw;
        try {
            raw = JSON.parse(localStorage.getItem(this.storageKey)) || [];
        } catch {
            return [];
        }
        if (!Array.isArray(raw)) return [];

        const seen = new Set();
        const deduped = [];
        for (const h of raw) {
            if (!h?.id || seen.has(h.id)) continue;
            seen.add(h.id);
            deduped.push(h);
        }

        /* Legacy localStorage from before the on-write dedupe carries
           duplicates that recordView() only purges for the song currently
           being recorded. If we've just stripped any out, persist the
           clean version so subsequent reads stay cheap and other tabs
           pick up the cleanup via the storage sync. */
        if (deduped.length !== raw.length) {
            try { this.saveAll(deduped); } catch { /* best-effort */ }
        }

        return deduped;
    }

    /**
     * Save the history array to localStorage.
     *
     * @param {Array} history
     */
    saveAll(history) {
        localStorage.setItem(this.storageKey, JSON.stringify(history));
        this.app.syncStorage(this.storageKey);
    }

    /**
     * Clear all history — local cache and, for signed-in users, the
     * server's per-user history too (WS-G #1019) so the cleared state
     * propagates across devices instead of repopulating on the next pull.
     */
    clearAll() {
        localStorage.removeItem(this.storageKey);
        this.app.syncStorage?.(this.storageKey);
        if (this.app?.userAuth?.isLoggedIn?.()) {
            /* Suppress the next server pull for one render cycle so a SELECT
               can't beat the in-flight DELETE below and resurrect the cleared
               rows (review #6). Set ONLY when a server DELETE actually fires
               (logged in) so the flag can't leak across an auth boundary and
               suppress a legitimate cross-device pull on a later login. */
            this._clearedLocally = true;
            fetch(`${this.app.config.apiUrl}?action=song_history_clear`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.app.userAuth.authHeaders(),
                },
            }).catch(() => { /* offline — local clear stands; server retried never, acceptable */ });
        }
    }

    /* =====================================================================
     * DB-FIRST SYNC (WS-G #1019)
     *
     * The server (tblSongHistory) is the source of truth for a signed-in
     * user's recently-viewed list — it's already populated by the
     * fire-and-forget song_view POST the router sends on every song open.
     * localStorage is the offline cache + the anonymous-user store.
     * ===================================================================== */

    /**
     * Fetch the signed-in user's recently-viewed list from the server,
     * mapped into the local {id, title, songbook, number, viewedAt} shape.
     * @returns {Promise<Array|null>} null on auth/network/parse failure.
     */
    async _fetchServerHistory() {
        if (!this.app?.userAuth?.isLoggedIn?.()) return null;
        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=song_history`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.app.userAuth.authHeaders(),
                },
            });
            if (!res.ok) return null;
            const data = await res.json();
            if (!Array.isArray(data.history)) return null;
            return data.history.slice(0, this.maxEntries).map(h => ({
                id:       h.songId,
                title:    h.title || '',
                songbook: h.songbook || '',
                number:   h.number || 0,
                /* Prefer the server's UTC epoch → ISO-8601 UTC so it compares
                   correctly with the local cache's new Date().toISOString()
                   values. The raw MySQL TIMESTAMP string ('YYYY-MM-DD HH:MM:SS',
                   no 'T'/'Z') would sort wrong against ISO on same-day ties
                   (review #5). Falls back to the raw string if epoch absent. */
                viewedAt: h.viewedAtEpoch
                    ? new Date(h.viewedAtEpoch * 1000).toISOString()
                    : (h.viewedAt || ''),
            }));
        } catch {
            return null;
        }
    }

    /**
     * One-time push of the pre-existing LOCAL history into the server for
     * the signed-in account (e.g. songs viewed while anonymous, before this
     * device was signed in). Guarded by a per-device flag so it never
     * re-stacks rows. New views land server-side via the router's song_view
     * POST, so this only covers the backlog. Fire-and-forget on the login
     * path; safe to call repeatedly (no-op once the flag is set).
     */
    async backfillToServer() {
        if (!this.app?.userAuth?.isLoggedIn?.()) return;
        if (localStorage.getItem(STORAGE_HISTORY_BACKFILLED) === 'true') return;
        /* In-flight guard: renderHomeSection awaits this AND triggerUserDataSync
           fires it — without the guard the two could double-POST before the
           durable flag is set. (Harmless if they did — the read path dedupes —
           but cheap to avoid.) */
        if (this._backfilling) return this._backfilling;

        this._backfilling = (async () => {
            const local = this.getAll();
            if (local.length === 0) {
                /* Nothing to push YET — do NOT mark done. A backlog that
                   appears later (anonymous views accumulated before sign-in,
                   a Settings→Import) must still get pushed on a later render.
                   The flag is set only after a real successful POST below, so
                   the no-op case stays cheaply re-attemptable (review #5). */
                return;
            }
            try {
                const res = await fetch(`${this.app.config.apiUrl}?action=song_history_backfill`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...this.app.userAuth.authHeaders(),
                    },
                    body: JSON.stringify({
                        views: local.map(h => ({ song_id: h.id, viewed_at: h.viewedAt })),
                    }),
                });
                if (res.ok) localStorage.setItem(STORAGE_HISTORY_BACKFILLED, 'true');
            } catch {
                /* Offline — leave the flag unset so the backfill retries on a
                   later session. */
            }
        })();
        try { await this._backfilling; } finally { this._backfilling = null; }
    }

    /**
     * Render the recently viewed section on the home page.
     * Called by the router after the home page loads.
     *
     * DB-first: renders the local cache immediately (instant + works
     * offline / for anonymous users), then — when signed in — refreshes
     * from the server and mirrors the authoritative list into the cache so
     * recently-viewed is consistent across devices (WS-G #1019).
     */
    async renderHomeSection() {
        this._renderSection(this.getAll());

        if (this.app?.userAuth?.isLoggedIn?.()) {
            if (this._clearedLocally) {
                /* A clear just fired on this device; its server DELETE may not
                   have committed yet. Skip the pull for one cycle (review #6). */
                this._clearedLocally = false;
                return;
            }
            /* Push the local backlog FIRST so the server-pull below can't
               overwrite (and lose) un-synced anonymous history. Idempotent
               + flag-guarded, so this is a no-op once done. */
            await this.backfillToServer();

            const server = await this._fetchServerHistory();
            /* Mirror only a NON-EMPTY server list. An empty result (genuinely
               cleared, or a failed/partial fetch) must not wipe a populated
               local cache — the first render already showed it. Cross-device
               "clear" still propagates on the device that clicked it (it wipes
               local + POSTs the server clear); another device just keeps its
               cache until it clears or views something new. */
            if (server && server.length > 0) {
                /* Union with any LOCAL-ONLY entries (e.g. songs viewed while
                   offline, whose fire-and-forget song_view POST never reached
                   the server) so reconnecting doesn't silently drop them from
                   Recently Viewed (review #8). Server is still authoritative
                   for everything it knows; local-only views are additive. */
                const merged = this._unionByRecency(this.getAll(), server);
                this.saveAll(merged);       /* mirror to the local cache */
                this._renderSection(merged);
            }
        }
    }

    /**
     * Merge two recently-viewed lists, de-duped by song id keeping the most
     * recent view, newest-first, capped at maxEntries. ISO-8601 viewedAt
     * strings compare lexicographically == chronologically.
     * @param {Array} a
     * @param {Array} b
     * @returns {Array}
     */
    _unionByRecency(a, b) {
        const byId = new Map();
        for (const h of [...(b || []), ...(a || [])]) {
            if (!h || !h.id) continue;
            const existing = byId.get(h.id);
            if (!existing || (h.viewedAt || '') > (existing.viewedAt || '')) {
                byId.set(h.id, h);
            }
        }
        return [...byId.values()]
            .sort((x, y) => (y.viewedAt || '').localeCompare(x.viewedAt || ''))
            .slice(0, this.maxEntries);
    }

    /**
     * Build + insert the recently-viewed section for a given history list.
     * Removes any prior render first (idempotent re-render).
     * @param {Array} history
     */
    _renderSection(history) {
        if (!Array.isArray(history) || history.length === 0) {
            /* Empty — clear any stale section (e.g. server returned []). */
            document.getElementById('recent-songs-section')?.remove();
            return;
        }

        /* Find the insertion point on the home page */
        const homeSection = document.querySelector('.page-home');
        if (!homeSection) return;

        /* Remove existing recent section if present (prevents duplicates) */
        document.getElementById('recent-songs-section')?.remove();

        /* Build the section HTML.
           #1237 — the number badge shows a value ONLY when it's a real positive number;
           0 / null / '0' (Misc / Collection / non-official songbooks) render the badge
           EMPTY so the `.song-number-badge:empty::before` CSS shows the book glyph,
           matching the Popular list's renderPopularRow. The old `h.number ?? ''` was the
           bug: nullish-coalescing does NOT catch 0, so it printed a literal "0". */
        const section = document.createElement('div');
        section.id = 'recent-songs-section';
        section.className = 'mb-4';
        section.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-clock-rotate-left me-2" aria-hidden="true"></i>
                    Recently Viewed
                </h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="clear-history-btn"
                        aria-label="Clear recent history">
                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>
                    Clear
                </button>
            </div>
            <div class="list-group" id="recent-songs-list">
                ${history.slice(0, 10).map(h => `
                    <a href="/song/${escapeHtml(h.id)}"
                       class="list-group-item list-group-item-action song-list-item"
                       data-navigate="song"
                       data-song-id="${escapeHtml(h.id)}">
                        <span class="song-number-badge" data-songbook="${escapeHtml(h.songbook)}">${Number(h.number) > 0 ? escapeHtml(String(h.number)) : ''}</span>
                        <div class="song-info flex-grow-1">
                            <span class="song-title">${escapeHtml(toTitleCase(h.title))}${verifiedBadge(h)}</span>
                            <small class="text-muted d-block">${songbookLabel(h.songbook)}</small>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                `).join('')}
            </div>`;

        homeSection.appendChild(section);

        /* Bind clear button */
        document.getElementById('clear-history-btn')?.addEventListener('click', () => {
            this.clearAll();
            section.remove();
            this.app.showToast('History cleared', 'info', 2000);
        });
    }

    /**
     * Escape HTML to prevent XSS.
     *
     * @param {string} str
     * @returns {string}
     */
}
