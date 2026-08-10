/**
 * live-host-console.js — optional "drive sections" console for a Quick
 * "Go Live" host (#1770 C6, req #6).
 *
 * ELI5: a worship leader who has gone live from a song page can already
 * follow the app around and re-broadcast on every song they open — this
 * adds an OPTIONAL panel that lets them also pick a song and jump between
 * verses/chorus without leaving the current page, the same way the
 * Service-Projection laptop's "Drive songs" panel does for a church service.
 *
 * DETAILED — never a fork. `ServiceBroadcaster` (service-broadcast.js) is
 * the ONE driver-UI core Service Mode already ships on both its own
 * broadcaster front-ends (rule #26 I4 / CLAUDE.md modularity rule); this
 * module reuses it UNCHANGED, supplying an ADAPTER `apiCall` — the module's
 * OWN designed transport seam (`service-broadcast.js` constructor doc-block:
 * "Supplied by the host page") — that translates its generic
 * `apiCall(action, {method,query,body})` calls onto the ACTUAL Quick-session
 * endpoints:
 *   - `songs_index` / `song_detail` (unauthenticated reads) pass straight
 *     through to the SAME `apiFetch()` the rest of the client uses (rule
 *     #31), unchanged.
 *   - `service_broadcast` (the ONE write) is translated into
 *     `live_follow_update` — a Quick session is CODE + HostUserId scoped
 *     (bearer-authed), not a numeric `sessionId` like a Service Mode
 *     session, so the adapter is what bridges the two shapes; the actual
 *     write still lands on the SAME serviceMode_applyBroadcast() core
 *     server-side that the service_broadcast and service_drive actions
 *     both use (guard G4).
 *
 * If `ServiceBroadcaster` ever needs a change to serve a Quick host, that
 * change belongs in `service-broadcast.js` itself (both front-ends inherit
 * it) — never a second copy here (plan §11 landmine 8).
 *
 * @see js/modules/service-broadcast.js  the ONE broadcaster-UI core
 * @see js/modules/live-follow.js        the host bar's "Console" button opens this
 */
import { ServiceBroadcaster } from './service-broadcast.js';
import { apiFetch } from '../utils/api-client.js';

export class LiveHostConsole {
    /** @param {import('./live-follow.js').LiveFollow} liveFollow */
    constructor(liveFollow) {
        this.liveFollow = liveFollow;
        this.broadcaster = null;
        this._overlay = null;
    }

    /** Open (or re-open) the console overlay. No-op once already hosting has ended. */
    open() {
        if (!this.liveFollow.hostCode) { return; }
        this._render();
    }

    /** Tear down the broadcaster + remove the overlay. Idempotent. */
    close() {
        if (this.broadcaster) { this.broadcaster.destroy(); this.broadcaster = null; }
        if (this._overlay && this._overlay.parentNode) { this._overlay.parentNode.removeChild(this._overlay); }
        this._overlay = null;
    }

    _render() {
        this.close(); /* never stack two consoles on a re-open */

        const overlay = document.createElement('div');
        overlay.id = 'live-host-console-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-label', 'Drive your live session');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:1095;background:rgba(0,0,0,.5);'
            + 'display:flex;align-items:flex-end;justify-content:center;';

        const panel = document.createElement('div');
        panel.className = 'bg-body';
        panel.style.cssText = 'width:100%;max-width:640px;max-height:80vh;overflow-y:auto;'
            + 'border-radius:1rem 1rem 0 0;padding:1rem;box-shadow:0 -6px 24px rgba(0,0,0,.35);';

        const head = document.createElement('div');
        head.className = 'd-flex justify-content-between align-items-center mb-2';
        const title = document.createElement('strong');
        title.innerHTML = '<i class="bi bi-sliders me-1"></i>Drive your live session';
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn btn-sm btn-outline-secondary';
        closeBtn.textContent = 'Close';
        closeBtn.addEventListener('click', () => this.close());
        head.appendChild(title);
        head.appendChild(closeBtn);

        const body = document.createElement('div');

        panel.appendChild(head);
        panel.appendChild(body);
        overlay.appendChild(panel);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) { this.close(); } });
        document.body.appendChild(overlay);
        this._overlay = overlay;

        this.broadcaster = new ServiceBroadcaster({
            apiCall: this._adapterApiCall(),
            sessionId: 0, /* unused by the adapter — a Quick session is code-scoped, not id-scoped */
            mount: body,
            onSessionEnded: () => { this.close(); this.liveFollow.endHost(true); },
        });
        this.broadcaster.init();
    }

    /**
     * The transport adapter — ServiceBroadcaster's designed injection seam.
     * Reads pass straight through; the one write is translated.
     * @returns {(action: string, opts?: object) => Promise<object>}
     */
    _adapterApiCall() {
        const lf = this.liveFollow;
        return async (action, opts) => {
            opts = opts || {};
            if (action === 'service_broadcast') {
                const b = opts.body || {};
                /* live_follow_update is bearer-authed + code-scoped (lf._api()
                   already attaches auth when { auth: true }) — the SAME write
                   `_broadcast()` performs, so a console-driven change and an
                   auto-rebroadcast-on-navigate share one de-dupe/409 handling
                   path rather than a second copy of it. */
                const r = await lf._api('live_follow_update', {
                    method: 'POST',
                    auth: true,
                    body: { code: lf.hostCode, songId: b.songId, componentIndex: b.componentIndex },
                });
                if (r.status === 409 || (!r.httpOk && r.status !== 0)) {
                    /* Session ended/idle-closed mid-drive — let the console's
                       own onSessionEnded callback tear everything down through
                       the SAME path _broadcast()'s 409 branch uses. */
                    return { ok: false, error: 'Session ended.' };
                }
                if (r.data && r.data.ok) {
                    lf._lastBroadcast = String(b.songId) + '|' + String(b.componentIndex);
                }
                return r.data || {};
            }
            /* songs_index / song_detail — unauthenticated reads via the SAME
               shared client the rest of the app uses (rule #31: never a bare
               fetch()). */
            const headers = { 'X-Requested-With': 'XMLHttpRequest' };
            const init = { method: opts.method || 'GET', headers, credentials: 'same-origin', cache: 'no-store' };
            if (opts.body) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
            const res = await apiFetch('/api?action=' + action + (opts.query || ''), init);
            try { return await res.json(); } catch (_e) { return {}; }
        };
    }
}
