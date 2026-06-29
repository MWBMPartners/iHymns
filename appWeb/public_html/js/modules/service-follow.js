/**
 * iHymns — Service Mode congregant client (#1335 Phase 2c)
 *
 * Lets a congregant JOIN a live service by typing the rotating code shown on
 * the venue screen, then FOLLOW the songs in sync (the worship leader /
 * projection laptop broadcasts via service_broadcast). Mirrors the worship-team
 * follower in live-follow.js, but over the anonymous Service-Mode endpoints with
 * a durable per-device id (localStorage) + an opaque presence token (the gate
 * key for the Phase-3 CCLI unlock).
 *
 * Anonymous by design — no login needed to follow already-entitled content; the
 * Phase-3 unlock reads the presence token server-side. Distinct from the
 * worship-team "Live Follow" (#1268): different endpoints, a green banner, and a
 * "Join service" entry rather than the per-song "Join Live".
 */

const SF_POLL_MS      = 2500;                       // follower poll cadence
const SF_PRESENCE_KEY = 'ihymns_sf_presence';       // sessionStorage: {token, rev}
const SF_DEVICE_KEY   = 'ihymns_sf_device';         // localStorage: durable anon device id
const SF_CODE_RE      = /^[A-Z0-9]{4,12}$/;

export class ServiceFollow {
    constructor(app) {
        this.app = app;
        this.token = null;
        this.rev = 0;
        this._pollTimer = null;
        this._polling = false;
        this._pendingScroll = null;
    }

    init() {
        /* Delegated entry point — any [data-action="join-service"] triggers join. */
        document.addEventListener('click', (e) => {
            const trigger = e.target && e.target.closest ? e.target.closest('[data-action="join-service"]') : null;
            if (trigger) { e.preventDefault(); this.joinService(); }
        });

        /* Resume a join across a reload (sessionStorage is per-tab). */
        try {
            const raw = sessionStorage.getItem(SF_PRESENCE_KEY);
            if (raw) {
                const saved = JSON.parse(raw);
                if (saved && saved.token) {
                    this.token = saved.token;
                    this.rev = (typeof saved.rev === 'number') ? saved.rev : 0;
                    this._setPresenceCookie(this.token);   /* re-assert for the gate after a reload */
                    this._showBanner();
                    this._startPolling();
                }
            }
        } catch (_e) { /* ignore */ }
    }

    /** Durable anonymous device id (NOT a login) — minted once, kept in localStorage. */
    _deviceId() {
        let id = null;
        try { id = localStorage.getItem(SF_DEVICE_KEY); } catch (_e) {}
        if (!id) {
            id = (window.crypto && crypto.randomUUID)
                ? crypto.randomUUID()
                : ('dev-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10));
            try { localStorage.setItem(SF_DEVICE_KEY, id); } catch (_e) {}
        }
        return id;
    }

    async joinService() {
        if (this.token) {
            this.app.showToast('You’re already following a service.', 'info');
            return;
        }
        const raw = await this.app.showPrompt('Enter the code shown on the screen:', '', {
            title: 'Join the service', placeholder: 'e.g. ABC234', codeEntry: true,
        });
        if (raw === null) { return; }
        /* Strip-then-validate (see live-follow.js): tolerate spaces, hyphens, NBSP
           and iOS smart-punctuation in a re-typed code rather than rejecting it. */
        const code = raw.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!SF_CODE_RE.test(code)) {
            this.app.showToast('That doesn’t look like a valid service code.', 'warning');
            return;
        }
        await this._doJoin(code);
    }

    async _doJoin(code) {
        try {
            const r = await this._api('service_join', { method: 'POST', body: { code: code, presenceDeviceId: this._deviceId() } });
            /* Maintenance/unavailable env (503) — the anonymous congregant doesn't
               bypass maintenance, so distinguish "site down" from "wrong code". */
            if (r.status === 503 || (r.data && r.data.maintenance)) {
                const why = (r.data && r.data.maintenance) ? 'down for maintenance' : 'briefly unavailable';
                this.app.showToast('iHymns is ' + why + ' — try the code again in a minute. The code is fine.', 'warning', 6000);
                return;
            }
            if (!r.httpOk || !r.data || !r.data.ok) {
                /* No Service-Mode (#1335) session matched this code. It may be a
                   worship-team "Go Live" (#1268) code instead: the two systems are
                   distinct, but a congregant only ever sees this one prominent
                   "Join a live service" button (the #1268 "Join Live" lives per-song
                   on the song page), so transparently fall back to the Live-Follow
                   join. live-follow.js#_doJoin starts following on success and shows
                   its own "session not found" toast on a genuinely bad code, so we
                   don't double-toast here. (#1386 — unify the discoverable join.) */
                if (this.app.liveFollow && typeof this.app.liveFollow._doJoin === 'function') {
                    await this.app.liveFollow._doJoin(code);
                    return;
                }
                this.app.showToast((r.data && r.data.error) || 'That code isn’t active right now.', 'danger');
                return;
            }
            this.token = r.data.presenceToken;
            this.rev = (typeof r.data.revision === 'number') ? r.data.revision : 0;
            try { sessionStorage.setItem(SF_PRESENCE_KEY, JSON.stringify({ token: this.token, rev: this.rev })); } catch (_e) {}
            this._setPresenceCookie(this.token);
            this._showBanner();
            this._startPolling();
            this.app.showToast('You’re following the service.', 'success');
            this._applyState(r.data.currentSongId, r.data.componentIndex);
        } catch (_e) {
            this.app.showToast('Could not join the service (network error).', 'danger');
        }
    }

    leaveService(silent = false) {
        const token = this.token;
        this.token = null;
        this.rev = 0;
        this._pendingScroll = null;
        this._stopPolling();
        try { sessionStorage.removeItem(SF_PRESENCE_KEY); } catch (_e) {}
        this._clearPresenceCookie();
        this._removeBanner();
        if (token) { this._api('service_leave', { method: 'POST', body: { presenceToken: token } }).catch(() => {}); }
        if (!silent) { this.app.showToast('Left the service.', 'info'); }
    }

    _startPolling() {
        this._stopPolling();
        this._pollTimer = setInterval(() => this._poll(), SF_POLL_MS);
    }
    _stopPolling() { if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; } }

    async _poll() {
        if (!this.token || this._polling) { return; }
        this._polling = true;
        try {
            const r = await this._api('service_poll', { method: 'GET', query: '&presenceToken=' + encodeURIComponent(this.token) + '&since=' + this.rev });
            /* Mid-service maintenance — notify once, keep following so updates resume. */
            if (r.status === 503 || (r.data && r.data.maintenance)) {
                if (!this._maintNotified) {
                    this._maintNotified = true;
                    this.app.showToast('Live updates paused — iHymns is briefly down for maintenance.', 'warning', 5000);
                }
                return;
            }
            this._maintNotified = false;
            const d = r.data || {};
            if (d.active === false) {
                this.app.showToast('The service has ended.', 'info');
                this.leaveService(true);
                return;
            }
            if (typeof d.revision === 'number') {
                this.rev = d.revision;
                try { sessionStorage.setItem(SF_PRESENCE_KEY, JSON.stringify({ token: this.token, rev: this.rev })); } catch (_e) {}
            }
            if (d.changed === true) { this._applyState(d.currentSongId, d.componentIndex); }
        } catch (_e) {
            /* transient — retry next tick */
        } finally {
            this._polling = false;
        }
    }

    /* Navigate to the service's current song (if different); section scroll is
       applied after the song-page render. */
    _applyState(songId, componentIndex) {
        const idx = (typeof componentIndex === 'number' && componentIndex >= 0) ? componentIndex : null;
        if (!songId) { return; }
        const page = document.querySelector('.page-song');
        const current = page ? (page.dataset.songId || '').trim() : '';
        if (current && current.toUpperCase() === String(songId).toUpperCase()) {
            if (idx !== null) { this._scrollToComponent(idx); }
            return;
        }
        this._pendingScroll = idx;
        if (this.app.router && typeof this.app.router.navigate === 'function') {
            this.app.router.navigate('/song/' + encodeURIComponent(songId));
            if (idx !== null) { setTimeout(() => { this._scrollToComponent(idx); this._pendingScroll = null; }, 600); }
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

    _showBanner() {
        if (!this.token) { return; }
        let bar = document.getElementById('service-follow-banner');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'service-follow-banner';
            bar.setAttribute('role', 'status');
            /* Green — visually distinct from the worship-team (blue) Live Follow banner. */
            bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:1080;'
                + 'background:#198754;color:#fff;text-align:center;padding:0.4rem 1rem;'
                + 'font-size:0.85rem;line-height:1.3;box-shadow:0 -2px 8px rgba(0,0,0,.2);'
                + 'padding-bottom:calc(0.4rem + env(safe-area-inset-bottom, 0px));';
            document.body.appendChild(bar);
        }
        bar.innerHTML = '';
        const label = document.createElement('span');
        label.innerHTML = '<i class="bi bi-broadcast-pin me-1"></i>Following the service live ';
        const leave = document.createElement('button');
        leave.type = 'button';
        leave.className = 'btn btn-sm btn-light ms-2';
        leave.style.cssText = 'padding:0.05rem 0.5rem;font-size:0.78rem;';
        leave.textContent = 'Leave';
        leave.addEventListener('click', () => this.leaveService(false));
        bar.appendChild(label);
        bar.appendChild(leave);
    }

    _removeBanner() {
        const bar = document.getElementById('service-follow-banner');
        if (bar && bar.parentNode) { bar.parentNode.removeChild(bar); }
    }

    /* The Phase-3 content gate (song.php) reads this same-origin cookie to grant
       a present congregant the org's CCLI unlock for gated lyrics. It's an opaque
       presence nonce (not a credential); set on join, cleared on leave/end. */
    _setPresenceCookie(token) {
        try {
            const secure = (location.protocol === 'https:') ? '; Secure' : '';
            document.cookie = 'ihymns_sf_presence_token=' + encodeURIComponent(token) + '; path=/; SameSite=Lax' + secure;
        } catch (_e) {}
    }
    _clearPresenceCookie() {
        try { document.cookie = 'ihymns_sf_presence_token=; path=/; Max-Age=0; SameSite=Lax'; } catch (_e) {}
    }

    /* Anonymous same-origin call. X-Requested-With satisfies the api.php CSRF
       guard; no auth header (congregant isn't logged in). */
    async _api(action, opts) {
        opts = opts || {};
        const url = '/api?action=' + action + (opts.query || '');
        const init = { method: opts.method || 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
        if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
        const res = await fetch(url, init);
        let data = {};
        try { data = await res.json(); } catch (_e) {}
        return { httpOk: res.ok, status: res.status, data: data };
    }
}
