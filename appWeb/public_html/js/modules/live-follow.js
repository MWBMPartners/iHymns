/**
 * live-follow.js — real-time "Live Follow" leader→followers sync (#1268 P2).
 *
 * DB-relayed short-poll over the Phase-1 backend (the `live_follow_*` endpoints
 * + `tblLiveFollowSessions.StateRevision`). No WebSocket/SSE — the shared
 * DreamHost host can't hold long-lived sockets, so the host WRITES its current
 * context and followers POLL a monotonic revision cheaply (`?since=`).
 *
 *   HOST (a signed-in worship leader): "Go Live" creates a session with a
 *   6-char code; as the host navigates the app, the current song is broadcast;
 *   a heartbeat keeps the session fresh (90 s window); "End" closes it.
 *   FOLLOWER (anyone, by code): "Join Live" enters the code; the device mirrors
 *   the leader's current song (and scrolls to the broadcast section) and keeps
 *   following via a ~2.5 s poll until the host ends or the follower leaves.
 *
 * v1 is SONG-LEVEL follow — the headline "congregants mirror the leader's
 * current song". `componentIndex` is carried through the protocol and the
 * follower scrolls to it when present; host-driven SECTION advancing awaits a
 * per-section presentation cursor (the app renders a song as one scroll view
 * today) — tracked as a follow-up on #1268. The poll endpoint's NAT-shared
 * anti-enumeration hardening (a join-token-gated poll instead of the per-IP
 * cap) is likewise a backend follow-up the Phase-1 code already flagged.
 */

const LF_POLL_MS      = 2500;   // follower poll cadence
const LF_HEARTBEAT_MS = 30000;  // host keepalive — comfortably inside the 90 s freshness window
const LF_HOST_KEY     = 'ihymns_lf_host';     // sessionStorage: active host session code
const LF_FOLLOW_KEY   = 'ihymns_lf_follow';   // sessionStorage: joined follower session code
const LF_CODE_RE      = /^[A-Z0-9]{4,12}$/;

export class LiveFollow {
    constructor(app) {
        this.app = app;
        this.hostCode    = null;   // non-null ⇒ this device is hosting
        this.followCode  = null;   // non-null ⇒ this device is following
        this.followRev   = 0;      // last revision seen as a follower
        this.followHost  = '';     // host display name (follower banner)
        this._hbTimer    = null;
        this._pollTimer  = null;
        this._lastBroadcast = '';  // "songId|idx" de-dupe so re-mounts don't re-POST
        this._pendingScroll = null;// componentIndex to scroll to once the followed song renders
        this._polling    = false;  // re-entrancy guard for the async poll tick
    }

    init() {
        /* Resume across a FULL page reload (SPA navigations are handled by
           initSongPage). sessionStorage is per-tab, which matches a session's
           lifetime — a closed tab legitimately drops out. */
        try {
            this.hostCode   = sessionStorage.getItem(LF_HOST_KEY)   || null;
            this.followCode = sessionStorage.getItem(LF_FOLLOW_KEY) || null;
        } catch (_e) { /* storage blocked — feature still works in-memory */ }
        this.followRev = 0;

        /* A device is never both host AND follower; if storage somehow carries
           both (manual edit / earlier bug), host wins — drop the follow side so
           init() can't start both the heartbeat and the poll loop. */
        if (this.hostCode && this.followCode) {
            this.followCode = null;
            try { sessionStorage.removeItem(LF_FOLLOW_KEY); } catch (_e) {}
        }

        if (this.hostCode)   { this._startHeartbeat(); }
        if (this.followCode) { this._showFollowBanner(); this._startPolling(); }

        /* If the host signs out, their session can't be authenticated any more — end it. */
        document.addEventListener('ihymns:auth-changed', () => {
            if (this.hostCode && this.app.userAuth && !this.app.userAuth.isLoggedIn()) {
                this.endHost(true);
            }
        });
    }

    /* Called from router.afterPageLoad('song') on every song-page render. Mounts
       the per-song controls and, while hosting, broadcasts the current song. */
    initSongPage() {
        const page = document.querySelector('.page-song');
        if (!page) { return; }
        const songId = (page.dataset.songId || '').trim();

        this._mountControls(page, songId);

        if (this.hostCode && songId) {
            this._broadcast(songId, 0);
        }
        if (this.followCode) {
            this._showFollowBanner();
            /* A poll-driven navigation lands here once the new song has
               rendered — apply any pending section scroll. */
            if (this._pendingScroll !== null) {
                this._scrollToComponent(this._pendingScroll);
                this._pendingScroll = null;
            }
        }
    }

    /* ------------------------------------------------------------------ API -- */

    async _api(action, { method = 'GET', query = '', body = null, auth = false } = {}) {
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (body) { headers['Content-Type'] = 'application/json'; }
        if (auth && this.app.userAuth && typeof this.app.userAuth.authHeaders === 'function') {
            Object.assign(headers, this.app.userAuth.authHeaders());
        }
        const res = await fetch('/api?action=' + action + query, {
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
            body: body ? JSON.stringify(body) : undefined,
        });
        let data = null;
        try { data = await res.json(); } catch (_e) { data = null; }
        return { httpOk: res.ok, status: res.status, data: data || {} };
    }

    /* ----------------------------------------------------------------- HOST -- */

    async goLive(songId) {
        if (!this.app.userAuth || !this.app.userAuth.isLoggedIn()) {
            this.app.showToast('Sign in to host a Live Follow session.', 'warning');
            return;
        }
        if (this.followCode) {
            this.app.showToast('Leave the session you’re following before hosting your own.', 'warning');
            return;
        }
        try {
            const r = await this._api('live_follow_create', {
                method: 'POST', auth: true,
                body: { songId: songId || null, componentIndex: 0 },
            });
            if (!r.httpOk || !r.data.ok || !r.data.code) {
                this.app.showToast('Could not start the session: ' + (r.data.error || ('HTTP ' + r.status)), 'danger');
                return;
            }
            this.hostCode = r.data.code;
            this._lastBroadcast = (songId || '') + '|0';
            try { sessionStorage.setItem(LF_HOST_KEY, this.hostCode); } catch (_e) {}
            this._startHeartbeat();
            const page = document.querySelector('.page-song');
            if (page) { this._mountControls(page, (page.dataset.songId || '').trim()); }
            this.app.showToast('You’re live — share code ' + this.hostCode, 'success', 6000);
        } catch (_e) {
            this.app.showToast('Could not start the session (network error).', 'danger');
        }
    }

    async endHost(silent = false) {
        const code = this.hostCode;
        this.hostCode = null;
        this._lastBroadcast = '';
        this._stopHeartbeat();
        try { sessionStorage.removeItem(LF_HOST_KEY); } catch (_e) {}
        const page = document.querySelector('.page-song');
        if (page) { this._mountControls(page, (page.dataset.songId || '').trim()); }
        if (code) {
            try { await this._api('live_follow_leave', { method: 'POST', auth: true, body: { code } }); } catch (_e) {}
        }
        if (!silent) { this.app.showToast('Live session ended.', 'info'); }
    }

    async _broadcast(songId, componentIndex) {
        if (!this.hostCode || !songId) { return; }
        const key = songId + '|' + componentIndex;
        if (key === this._lastBroadcast) { return; }   /* no change since last write */
        try {
            const r = await this._api('live_follow_update', {
                method: 'POST', auth: true,
                body: { code: this.hostCode, songId, componentIndex },
            });
            if (r.status === 409 || (!r.httpOk && r.status !== 0)) {
                /* Session was superseded / ended / expired server-side. */
                this.app.showToast('Live session ended (it was closed or expired).', 'warning');
                this.endHost(true);
                return;
            }
            if (r.data && r.data.ok) { this._lastBroadcast = key; }
        } catch (_e) { /* transient — next navigation retries */ }
    }

    _startHeartbeat() {
        this._stopHeartbeat();
        this._hbTimer = setInterval(async () => {
            if (!this.hostCode) { this._stopHeartbeat(); return; }
            try {
                const r = await this._api('live_follow_heartbeat', { method: 'POST', auth: true, body: { code: this.hostCode } });
                if (r.data && r.data.ok === false) { this.endHost(true); }
            } catch (_e) { /* transient */ }
        }, LF_HEARTBEAT_MS);
    }
    _stopHeartbeat() { if (this._hbTimer) { clearInterval(this._hbTimer); this._hbTimer = null; } }

    /* ------------------------------------------------------------- FOLLOWER -- */

    async joinFollow() {
        if (this.hostCode) {
            this.app.showToast('End your own live session before following another.', 'warning');
            return;
        }
        const raw = await this.app.showPrompt('Enter the live session code:', '', {
            title: 'Join Live Follow', placeholder: 'e.g. ABC234',
        });
        if (raw === null) { return; }
        const code = raw.trim().toUpperCase();
        if (!LF_CODE_RE.test(code)) {
            this.app.showToast('That doesn’t look like a valid session code.', 'warning');
            return;
        }
        await this._doJoin(code);
    }

    async _doJoin(code) {
        try {
            const r = await this._api('live_follow_join', { method: 'GET', query: '&code=' + encodeURIComponent(code) });
            if (!r.httpOk || !r.data.ok) {
                this.app.showToast(r.data.error || 'Session not found or ended.', 'danger');
                return;
            }
            this.followCode = code;
            this.followRev  = (typeof r.data.revision === 'number') ? r.data.revision : 0;
            this.followHost = r.data.hostDisplayName || '';
            try { sessionStorage.setItem(LF_FOLLOW_KEY, code); } catch (_e) {}
            this._showFollowBanner();
            this._startPolling();
            this.app.showToast('Following ' + (this.followHost ? ('“' + this.followHost + '”') : 'the leader') + '.', 'success');
            this._applyFollowState(r.data.currentSongId, r.data.componentIndex);
        } catch (_e) {
            this.app.showToast('Could not join the session (network error).', 'danger');
        }
    }

    leaveFollow(silent = false) {
        this.followCode = null;
        this.followRev = 0;
        this.followHost = '';
        this._pendingScroll = null;
        this._stopPolling();
        try { sessionStorage.removeItem(LF_FOLLOW_KEY); } catch (_e) {}
        this._removeFollowBanner();
        const page = document.querySelector('.page-song');
        if (page) { this._mountControls(page, (page.dataset.songId || '').trim()); }
        if (!silent) { this.app.showToast('Stopped following.', 'info'); }
    }

    _startPolling() {
        this._stopPolling();
        this._pollTimer = setInterval(() => this._poll(), LF_POLL_MS);
    }
    _stopPolling() { if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; } }

    async _poll() {
        if (!this.followCode || this._polling) { return; }
        this._polling = true;
        try {
            const r = await this._api('live_follow_poll', {
                method: 'GET',
                query: '&code=' + encodeURIComponent(this.followCode) + '&since=' + this.followRev,
            });
            const d = r.data || {};
            if (d.active === false) {
                this.app.showToast('The live session has ended.', 'info');
                this.leaveFollow(true);
                return;
            }
            if (typeof d.revision === 'number') { this.followRev = d.revision; }
            if (d.changed === true) {
                this._applyFollowState(d.currentSongId, d.componentIndex);
            }
        } catch (_e) {
            /* Transient network blip — keep the session, retry next tick. */
        } finally {
            this._polling = false;
        }
    }

    /* Navigate to the leader's song (if different) + scroll to the section. */
    _applyFollowState(songId, componentIndex) {
        const idx = (typeof componentIndex === 'number' && componentIndex >= 0) ? componentIndex : null;
        if (!songId) { return; }
        const page = document.querySelector('.page-song');
        const current = page ? (page.dataset.songId || '').trim() : '';
        if (current && current.toUpperCase() === String(songId).toUpperCase()) {
            if (idx !== null) { this._scrollToComponent(idx); }
            return;
        }
        /* Different song — drive an SPA navigation; the section scroll is applied
           on the next song-page render (initSongPage reads _pendingScroll). */
        this._pendingScroll = idx;
        if (this.app.router && typeof this.app.router.navigate === 'function') {
            this.app.router.navigate('/song/' + encodeURIComponent(songId));
        } else {
            window.location.href = '/song/' + encodeURIComponent(songId);
        }
    }

    _scrollToComponent(index) {
        const comps = document.querySelectorAll('.page-song .lyric-component');
        const el = comps && comps.length > index ? comps[index] : null;
        if (el && typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    /* --------------------------------------------------------------- UI / DOM -- */

    /* Render the per-song control (Go Live / Join / live-badge) into a stable
       host container appended to the song-page action row. Idempotent — called
       on every song-page render and on every state change. */
    _mountControls(page, songId) {
        let host = page.querySelector('#live-follow-controls');
        if (!host) {
            host = document.createElement('span');
            host.id = 'live-follow-controls';
            host.className = 'd-inline-flex flex-wrap gap-2 align-middle';
            /* Mount next to the other song actions (favourite/share/setlist row);
               fall back to the lyrics region header if the row isn't found. */
            const row = page.querySelector('.song-actions') || page.querySelector('.d-flex.flex-wrap.gap-2') || page;
            row.appendChild(host);
        }
        host.innerHTML = '';

        if (this.hostCode) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-danger d-inline-flex align-items-center gap-1';
            badge.innerHTML = '<i class="bi bi-broadcast"></i> LIVE · <strong>' + this._esc(this.hostCode) + '</strong>';
            const end = document.createElement('button');
            end.type = 'button';
            end.className = 'btn btn-sm btn-outline-danger';
            end.innerHTML = '<i class="bi bi-stop-circle me-1"></i>End';
            end.addEventListener('click', () => this.endHost(false));
            host.appendChild(badge);
            host.appendChild(end);
            return;
        }

        if (this.followCode) { return; } /* following — controls live in the banner */

        if (this.app.userAuth && this.app.userAuth.isLoggedIn()) {
            const go = document.createElement('button');
            go.type = 'button';
            go.className = 'btn btn-sm btn-outline-primary';
            go.title = 'Broadcast this song to congregants’ devices in real time';
            go.innerHTML = '<i class="bi bi-broadcast me-1"></i>Go Live';
            go.addEventListener('click', () => this.goLive(songId));
            host.appendChild(go);
        }
        const join = document.createElement('button');
        join.type = 'button';
        join.className = 'btn btn-sm btn-outline-secondary';
        join.title = 'Follow a worship leader’s live session by code';
        join.innerHTML = '<i class="bi bi-people me-1"></i>Join Live';
        join.addEventListener('click', () => this.joinFollow());
        host.appendChild(join);
    }

    _showFollowBanner() {
        if (!this.followCode) { return; }
        let bar = document.getElementById('live-follow-banner');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'live-follow-banner';
            bar.setAttribute('role', 'status');
            bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:1080;'
                + 'background:#0d6efd;color:#fff;text-align:center;padding:0.4rem 1rem;'
                + 'font-size:0.85rem;line-height:1.3;box-shadow:0 -2px 8px rgba(0,0,0,.2);'
                + 'padding-bottom:calc(0.4rem + env(safe-area-inset-bottom, 0px));';
            document.body.appendChild(bar);
        }
        bar.innerHTML = '';
        const label = document.createElement('span');
        label.innerHTML = '<i class="bi bi-eye-fill me-1"></i>Following '
            + (this.followHost ? ('<strong>' + this._esc(this.followHost) + '</strong>') : 'the leader')
            + ' live ';
        const leave = document.createElement('button');
        leave.type = 'button';
        leave.className = 'btn btn-sm btn-light ms-2';
        leave.style.cssText = 'padding:0.05rem 0.5rem;font-size:0.78rem;';
        leave.textContent = 'Leave';
        leave.addEventListener('click', () => this.leaveFollow(false));
        bar.appendChild(label);
        bar.appendChild(leave);
    }

    _removeFollowBanner() {
        const bar = document.getElementById('live-follow-banner');
        if (bar && bar.parentNode) { bar.parentNode.removeChild(bar); }
    }

    _esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }
}
