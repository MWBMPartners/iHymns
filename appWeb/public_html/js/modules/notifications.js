/**
 * iHymns — In-app Notifications (#289)
 *
 * Shows unread + recent notifications under the bell icon in the site
 * header. Polls the server at a gentle cadence when the tab is
 * visible, refreshes immediately on auth changes, and exposes
 * `markAllRead` / `markOneRead` for the user actions.
 *
 * Storage is server-side only — no localStorage. The bell mirrors
 * whatever `/api?action=notifications_list` returns for the current
 * authenticated user. Signed-out visitors see nothing (the bell's
 * outer wrapper is hidden via `d-none` until auth broadcasts
 * logged-in).
 */

const POLL_INTERVAL_MS = 60_000;   /* 1 min — cheap enough, avoids bursty refresh */
const MAX_VISIBLE_ROWS = 50;

export class Notifications {
    constructor(app) {
        this.app = app;
        this._items = [];
        this._timer = null;
    }

    init() {
        const wrap = document.getElementById('header-notifications-dropdown');
        if (!wrap) return; /* bell markup missing — page template hasn't been updated */

        /* Reveal / hide + refresh on auth changes (login, logout, remote
           session invalidation). The user-auth module dispatches
           `ihymns:auth-changed` — see user-auth.js::_broadcastAuthChanged. */
        document.addEventListener('ihymns:auth-changed', () => this._applyAuthState());
        this._applyAuthState();

        /* Mark-all-read button in the panel header. */
        document.getElementById('header-notifications-mark-all')
            ?.addEventListener('click', (ev) => {
                ev.preventDefault();
                this.markAllRead();
            });

        /* Pause polling while the tab is hidden to avoid wasted work
           on an idle browser; resume + refresh when it's visible
           again. */
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.refresh();
        });
    }

    /* ---------------------------------------------------------------- */

    _applyAuthState() {
        const wrap = document.getElementById('header-notifications-dropdown');
        if (!wrap) return;
        if (this.app.userAuth?.isLoggedIn?.()) {
            wrap.classList.remove('d-none');
            this.refresh();
            this._startPolling();
        } else {
            wrap.classList.add('d-none');
            this._items = [];
            this._renderBadge();
            this._renderList();
            this._stopPolling();
        }
    }

    _startPolling() {
        this._stopPolling();
        this._timer = setInterval(() => this.refresh(), POLL_INTERVAL_MS);
    }

    _stopPolling() {
        if (this._timer) clearInterval(this._timer);
        this._timer = null;
    }

    /** Pull the latest server state and re-render. */
    async refresh() {
        if (!this.app.userAuth?.isLoggedIn?.()) return;
        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=notifications_list`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', ...this.app.userAuth.authHeaders() },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            this._items = Array.isArray(data.items) ? data.items.slice(0, MAX_VISIBLE_ROWS) : [];
            this._renderBadge();
            this._renderList();
        } catch (_e) {
            /* Network blip — keep the existing render; next poll will retry. */
        }
    }

    _renderBadge() {
        const badge = document.getElementById('header-notifications-badge');
        if (!badge) return;
        const unread = this._items.filter(n => !n.is_read).length;
        if (unread > 0) {
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    }

    _renderList() {
        const listEl  = document.getElementById('header-notifications-list');
        const emptyEl = document.getElementById('header-notifications-empty');
        if (!listEl) return;

        /* Re-render from scratch — the list is short and rebuilding is
           simpler than reconciling. Keep the empty-state node the same
           DOM element so its `d-none` state is the only toggle. */
        listEl.innerHTML = '';
        if (emptyEl) listEl.appendChild(emptyEl);

        if (this._items.length === 0) {
            emptyEl?.classList.remove('d-none');
            return;
        }
        emptyEl?.classList.add('d-none');

        for (const n of this._items) {
            const row = document.createElement('a');
            row.href = n.action_url || '#';
            row.className = `d-block px-3 py-2 text-decoration-none border-bottom notification-row ${n.is_read ? 'text-muted' : 'fw-semibold'}`;
            row.dataset.notificationId = String(n.id);
            if (n.action_url && n.action_url.startsWith('/')) row.setAttribute('data-navigate', 'true');
            row.innerHTML = `
                <div class="d-flex align-items-start gap-2">
                    <i class="fa-solid fa-circle mt-2 small ${n.is_read ? 'text-muted' : 'text-primary'}"
                       style="font-size: 0.5rem;" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <div class="small">${escapeHtml(n.title || '')}</div>
                        ${n.body ? `<div class="small text-muted">${escapeHtml(n.body)}</div>` : ''}
                        <div class="text-muted" style="font-size: 0.75rem;">${escapeHtml(formatRelative(n.created_at))}</div>
                    </div>
                </div>`;
            row.addEventListener('click', (ev) => {
                /* Mark as read on click, but don't block navigation —
                   await fires after the browser starts loading. */
                if (!n.is_read) this.markOneRead(n.id).catch(() => {});
                /* Main-app SPA router will take it from here for internal links. */
                if (!n.action_url) ev.preventDefault();
            });
            listEl.appendChild(row);
        }
    }

    async markOneRead(id) {
        const local = this._items.find(n => n.id === id);
        if (local) local.is_read = true;
        this._renderBadge();
        this._renderList();
        try {
            await fetch(`${this.app.config.apiUrl}?action=notifications_mark_read`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...this.app.userAuth.authHeaders() },
                credentials: 'same-origin',
                body:    JSON.stringify({ ids: [id] }),
            });
        } catch (_e) { /* next refresh will reconcile */ }
    }

    async markAllRead() {
        this._items.forEach(n => { n.is_read = true; });
        this._renderBadge();
        this._renderList();
        try {
            await fetch(`${this.app.config.apiUrl}?action=notifications_mark_read`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...this.app.userAuth.authHeaders() },
                credentials: 'same-origin',
                body:    JSON.stringify({ all: true }),
            });
        } catch (_e) { /* next refresh will reconcile */ }
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/** Small "2m ago" / "3h ago" / date formatter. */
function formatRelative(iso) {
    if (!iso) return '';
    const then = new Date(iso);
    const diff = (Date.now() - then.getTime()) / 1000;
    if (diff < 60)       return 'just now';
    if (diff < 3600)     return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400)    return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 86400*7)  return `${Math.floor(diff / 86400)}d ago`;
    return then.toLocaleDateString();
}
