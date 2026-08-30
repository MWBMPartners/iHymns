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

/* #1581 — import the shared event-name constant rather than spelling the
   auth-changed event's raw string here, so this listener can never drift
   out of sync with what user-auth.js actually dispatches. */
import { EVT_AUTH_CHANGED } from '../constants.js';
import { apiFetch } from '../utils/api-client.js';
/* #1770 C5 — shared anonymous-follower identity (device id + the same-origin
   presence cookie the Phase-3 gate reads). Extracted from service-follow.js
   so a Quick "Go Live" follower unlocks the host's CCLI licence the SAME way
   a Service-Mode congregant does (CLAUDE.md modularity rule — see
   presence-identity.js's doc-comment for the full "why"). */
import { deviceId, setPresenceCookie, clearPresenceCookie } from '../utils/presence-identity.js';
/* a11y audit M3 — the shared modal-dialog focus contract (aria-modal, focus
   in/out, Tab trap, Escape-to-close) extracted from present-mode.js's
   already-correct recipe. See dialog-a11y.js's doc-block for the "why". */
import { openModalDialog } from '../utils/dialog-a11y.js';
import { prefersReducedMotion } from '../utils/motion.js';

const LF_POLL_MS      = 2500;   // follower poll cadence
const LF_HEARTBEAT_MS = 30000;  // host keepalive — comfortably inside the 180 s join/poll freshness window (api.php)
const LF_HOST_KEY     = 'ihymns_lf_host';     // sessionStorage: active host session code
const LF_FOLLOW_KEY   = 'ihymns_lf_follow';   // sessionStorage: joined follow state {code, token} (#1377); legacy = bare code string
const LF_CODE_RE      = /^[A-Z0-9]{4,12}$/;

export class LiveFollow {
    constructor(app) {
        this.app = app;
        this.hostCode    = null;   // non-null ⇒ this device is hosting
        this.followCode  = null;   // non-null ⇒ this device is following
        this.followToken = null;   // per-follower poll-bucket token (#1377); NOT auth — the code is
        this.followRev   = 0;      // last revision seen as a follower
        this.followHost  = '';     // host display name (follower banner)
        /* #1770 C5 — the presence-minting join's CCLI-unlock token (req #3/#6).
           null on an older server (no presenceToken key in the join response)
           or when the follow was never a presence-minting join — back-compat
           by absence, the SAME posture followToken already established. */
        this.presenceToken = null;
        this._hbTimer    = null;
        this._pollTimer  = null;
        this._lastBroadcast = '';  // "songId|idx" de-dupe so re-mounts don't re-POST
        this._pendingScroll = null;// componentIndex to scroll to once the followed song renders
        this._polling    = false;  // re-entrancy guard for the async poll tick
        /* #1770 C5 — leader-activity tracking (req #2/#5). _lastInteractionAt is
           bumped ONLY by a genuine pointerdown/keydown while hosting; _lastBeatAt
           is the boundary of the last heartbeat window. The automated 30s beat
           alone must NEVER count as activity (plan §11 landmine 3 — that would
           recreate the immortal-abandoned-tab bug this feature exists to fix).
           _activityBound holds the bound listener so it can be removed on end. */
        this._lastInteractionAt = 0;
        this._lastBeatAt        = 0;
        this._activityBound     = null;
        this._hostConsole       = null; // lazily-created LiveHostConsole instance (#1770 C6)
        this._codeViewClose     = null; // close() returned by openModalDialog() while the code overlay is open (a11y audit M3)
        /* #1798 — the session length the host declared at "Go Live" (or the
           server-resolved default when they didn't pick one), and later
           whatever the "Extend" control most recently applied. Purely
           informational client-side (the server is the source of truth for
           enforcement) — used only to format the "Kept live for …" toast. */
        this.idleTimeoutMins    = null;
    }

    init() {
        /* Resume across a FULL page reload (SPA navigations are handled by
           initSongPage). sessionStorage is per-tab, which matches a session's
           lifetime — a closed tab legitimately drops out. */
        try {
            this.hostCode = sessionStorage.getItem(LF_HOST_KEY) || null;
            /* Follow state persists as {code, token} (#1377) so a reload keeps
               the per-follower poll-bucket token. Tolerate the LEGACY plain-string
               value written by an earlier client (token then absent → poll falls
               back to per-IP server-side; back-compat). */
            const fp = this._readFollowState();
            this.followCode     = fp.code          || null;
            this.followToken    = fp.token         || null;
            this.presenceToken  = fp.presenceToken || null;
        } catch (_e) { /* storage blocked — feature still works in-memory */ }
        this.followRev = 0;

        /* A device is never both host AND follower; if storage somehow carries
           both (manual edit / earlier bug), host wins — drop the follow side so
           init() can't start both the heartbeat and the poll loop. */
        if (this.hostCode && this.followCode) {
            this.followCode = null;
            this.followToken = null;
            this.presenceToken = null;
            try { sessionStorage.removeItem(LF_FOLLOW_KEY); } catch (_e) {}
        }

        if (this.hostCode)   { this._startHeartbeat(); this.renderHostBar(); }
        if (this.followCode) {
            this._showFollowBanner();
            this._startPolling();
            /* #1770 C5 — re-assert the presence cookie for the Phase-3 gate
               after a full reload (mirrors service-follow.js's own resume
               branch). Absent on a pre-#1770-C3 follow state — no-op. */
            if (this.presenceToken) { setPresenceCookie(this.presenceToken); }
        }

        /* If the host signs out, their session can't be authenticated any more — end it. */
        document.addEventListener(EVT_AUTH_CHANGED, () => {
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
        /* #1770 C5 (req #1) — the fixed host bar is a SEPARATE affordance from
           the inline song-page badge above: it is what makes hosting VISIBLE
           on every page, not just the one the leader happened to broadcast
           from. Idempotent (rule #32 shape) — safe to call on every render. */
        this.renderHostBar();

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
        const res = await apiFetch('/api?action=' + action + query, {
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
        /* #1798 — declare the session length up front ("the sermon gap"): a
           leader who knows they're covering a full service can pick a
           longer idle-close window than the site/org default at the moment
           they go live, rather than discovering mid-sermon that the default
           was too short. Cancelling the picker is NOT cancelling Go Live —
           it just means "use the server-resolved default", exactly as if
           this control didn't exist (the picker is a lightweight ADDITION,
           never a blocking step). */
        const chosenMins = await this._pickIdleDuration('Keep live for…');
        try {
            const body = { songId: songId || null, componentIndex: 0 };
            if (chosenMins !== null) { body.idleTimeoutMins = chosenMins; }
            const r = await this._api('live_follow_create', {
                method: 'POST', auth: true,
                body,
            });
            if (!r.httpOk || !r.data.ok || !r.data.code) {
                this.app.showToast('Could not start the session: ' + (r.data.error || ('HTTP ' + r.status)), 'danger');
                return;
            }
            this.hostCode = r.data.code;
            /* #1798 — the server may have capped the choice (an enforcing
               org's lock always wins for the host, see api.php's
               live_follow_create), so track what it actually applied, not
               what was requested. null on a pre-#1770 / un-migrated server
               (additive key, back-compat by absence). */
            this.idleTimeoutMins = (typeof r.data.idleTimeoutMins === 'number') ? r.data.idleTimeoutMins : null;
            this._lastBroadcast = (songId || '') + '|0';
            try { sessionStorage.setItem(LF_HOST_KEY, this.hostCode); } catch (_e) {}
            this._startHeartbeat();
            const page = document.querySelector('.page-song');
            if (page) { this._mountControls(page, (page.dataset.songId || '').trim()); }
            this.renderHostBar();
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
        /* #1770 C5/C6 — tear down every host-only surface. renderHostBar()
           removes the bar unconditionally then no-ops the re-add since
           hostCode is already null (rule #32 shape). */
        this.renderHostBar();
        this._removeCodeView();
        if (this._hostConsole) { this._hostConsole.close(); }
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
                /* Session was superseded / ended / expired server-side — OR,
                   since #1770 C2, idle-closed. ONE message covers all three
                   (I6 anti-probe opacity — a host cannot distinguish "someone
                   else closed it" from "it timed out" from this alone, and
                   isn't meant to). */
                this.app.showToast('Your live session ended (closed or timed out after inactivity).', 'warning');
                this.endHost(true);
                return;
            }
            if (r.data && r.data.ok) { this._lastBroadcast = key; }
        } catch (_e) { /* transient — next navigation retries */ }
    }

    _startHeartbeat() {
        this._stopHeartbeat();
        this._hbTimer = setInterval(() => this._beatNow(), LF_HEARTBEAT_MS);
        /* Mobile browsers throttle/pause setInterval in a backgrounded tab, so the
           30s beat can miss the freshness window while the leader is on another app
           or the screen sleeps — the session then goes "stale" and followers can't
           join. Fire an immediate beat whenever the page returns to the foreground so
           a briefly-backgrounded session recovers instead of dying. */
        if (!this._hbVisBound) {
            this._hbVisBound = () => { if (!document.hidden && this.hostCode) { this._beatNow(); } };
            document.addEventListener('visibilitychange', this._hbVisBound);
            window.addEventListener('focus', this._hbVisBound);
        }
        this._startActivityTracking();
    }
    async _beatNow() {
        if (!this.hostCode) { this._stopHeartbeat(); return; }
        try {
            const r = await this._api('live_follow_heartbeat', {
                method: 'POST', auth: true,
                body: { code: this.hostCode, leaderActive: this._consumeLeaderActive() },
            });
            if (r.data && r.data.ok === false) {
                /* #1770 C2 idle-close (or the session was ended/superseded
                   elsewhere) — same unified copy as the update-409 path above. */
                this.app.showToast('Your live session ended (closed or timed out after inactivity).', 'warning');
                this.endHost(true);
            }
        } catch (_e) { /* transient */ }
    }
    _stopHeartbeat() {
        if (this._hbTimer) { clearInterval(this._hbTimer); this._hbTimer = null; }
        if (this._hbVisBound) {
            document.removeEventListener('visibilitychange', this._hbVisBound);
            window.removeEventListener('focus', this._hbVisBound);
            this._hbVisBound = null;
        }
        this._stopActivityTracking();
    }

    /* #1770 C5 (req #2/#5) — passive leader-activity tracking. Installed only
       while hosting (bracketed by _startHeartbeat/_stopHeartbeat, which are
       themselves the "am I currently hosting" bracket), removed on end.
       `{ passive: true }` on pointerdown — this listener only ever READS the
       event, never calls preventDefault(), so the browser can keep scrolling
       off the main thread. keydown has no passive-eligible default to give up
       here, so it is added without the option (matches MDN's own guidance —
       passive is only meaningful for a handler that could otherwise block
       scrolling/zooming). https://developer.mozilla.org/docs/Web/API/EventTarget/addEventListener#using_passive_listeners */
    _startActivityTracking() {
        if (this._activityBound) { return; } /* already installed */
        this._lastBeatAt = Date.now();
        this._activityBound = () => { this._lastInteractionAt = Date.now(); };
        document.addEventListener('pointerdown', this._activityBound, { passive: true });
        document.addEventListener('keydown', this._activityBound);
    }
    _stopActivityTracking() {
        if (!this._activityBound) { return; }
        document.removeEventListener('pointerdown', this._activityBound);
        document.removeEventListener('keydown', this._activityBound);
        this._activityBound = null;
        this._lastInteractionAt = 0;
        this._lastBeatAt = 0;
    }
    /** Has there been a GENUINE interaction since the last heartbeat window began?
        Consumes (resets) the window boundary — call exactly once per beat. */
    _consumeLeaderActive() {
        const active = this._lastInteractionAt > this._lastBeatAt;
        this._lastBeatAt = Date.now();
        return active;
    }

    /* ------------------------------------------------------------- FOLLOWER -- */

    async joinFollow() {
        if (this.hostCode) {
            this.app.showToast('End your own live session before following another.', 'warning');
            return;
        }
        const raw = await this.app.showPrompt('Enter the live session code:', '', {
            title: 'Join Live Follow', placeholder: 'e.g. ABC234', codeEntry: true,
        });
        if (raw === null) { return; }
        /* Strip-then-validate. Codes are read off a screen and re-typed on a phone,
           so tolerate spaces, hyphens, NBSP and iOS smart-punctuation ("ABC 234",
           "abc-234") instead of rejecting them before we ever try. Case is already
           folded server-side + by the CI collation; we upper here for a clean match. */
        const code = raw.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!LF_CODE_RE.test(code)) {
            this.app.showToast('That doesn’t look like a valid session code.', 'warning');
            return;
        }
        await this._doJoin(code);
    }

    async _doJoin(code) {
        try {
            /* #1770 C3/C5 (req #3/#6) — POST with a presenceDeviceId opts this
               join into presence-minting server-side (live_follow_join's
               additive POST mode); the code itself still travels on the query
               string (the server reads it from $_GET regardless of method —
               see api.php's live_follow_join, which never changed that). A
               server without the #1770 C3 build simply ignores the body and
               answers exactly as the old GET join did — presenceToken is then
               absent from the response and the cookie below is never set
               (back-compat by absence, the followToken precedent below). */
            const r = await this._api('live_follow_join', {
                method: 'POST',
                query: '&code=' + encodeURIComponent(code),
                body: { presenceDeviceId: deviceId() },
            });
            /* An env in maintenance answers 503 ({maintenance:true} from the gate).
               The follower is anonymous and does NOT bypass maintenance, so without
               this branch they'd see the leader's perfectly-good code blamed as wrong.
               Distinct, non-blaming copy; keep them able to retry. */
            if (r.status === 503 || (r.data && r.data.maintenance)) {
                const why = (r.data && r.data.maintenance) ? 'down for maintenance' : 'briefly unavailable';
                this.app.showToast('iHymns is ' + why + ' — try the code again in a minute. The leader’s code is fine.', 'warning', 6000);
                return;
            }
            if (!r.httpOk || !r.data.ok) {
                this.app.showToast(r.data.error || 'Session not found. Check the code, and that the leader’s screen still shows “Live”.', 'danger');
                return;
            }
            this.followCode = code;
            /* Per-follower poll-bucket token (#1377). Stateless server-side — it
               only buckets the poll rate limiter PER follower so a NAT-shared
               congregation isn't throttled as one IP. May be absent if the server
               is an older build; the poll then falls back to per-IP (back-compat). */
            this.followToken = (typeof r.data.followToken === 'string' && r.data.followToken) ? r.data.followToken : null;
            /* #1770 C3/C5 — the CCLI-unlock presence token; absent unless the
               presence table exists AND the server actually minted one. */
            this.presenceToken = (typeof r.data.presenceToken === 'string' && r.data.presenceToken) ? r.data.presenceToken : null;
            this.followRev  = (typeof r.data.revision === 'number') ? r.data.revision : 0;
            this.followHost = r.data.hostDisplayName || '';
            this._writeFollowState();
            if (this.presenceToken) { setPresenceCookie(this.presenceToken); }
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
        this.followToken = null;
        this.presenceToken = null;
        this.followRev = 0;
        this.followHost = '';
        this._pendingScroll = null;
        this._stopPolling();
        try { sessionStorage.removeItem(LF_FOLLOW_KEY); } catch (_e) {}
        clearPresenceCookie();
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
            /* Append the per-follower token so the server buckets THIS device's
               poll rate (#1377); omit it when absent so an older server still
               accepts the request and falls back to per-IP (back-compat). */
            const tokenQs = this.followToken ? ('&token=' + encodeURIComponent(this.followToken)) : '';
            const r = await this._api('live_follow_poll', {
                method: 'GET',
                query: '&code=' + encodeURIComponent(this.followCode) + '&since=' + this.followRev + tokenQs,
            });
            /* Env went into maintenance mid-session — tell the follower ONCE (not every
               2.5s tick) and keep the session so updates resume when the site returns,
               instead of silently freezing. */
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
            /* a11y audit L7 (2026-08-30): this passed 'smooth' unconditionally
               — the one call site that checked NEITHER the in-app toggle nor
               the OS preference, deviating from its own sibling
               (service-follow.js's identical method, which already checked
               the in-app toggle). This is remotely-triggered scrolling (a
               HOST advances the slide), so a congregant with a vestibular
               disorder who set either preference got animated scrolling
               they did not initiate and had opted out of. */
            el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
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
            badge.innerHTML = '<i class="bi bi-broadcast" aria-hidden="true"></i> LIVE · <strong>' + this._esc(this.hostCode) + '</strong>';
            const end = document.createElement('button');
            end.type = 'button';
            end.className = 'btn btn-sm btn-outline-danger';
            end.innerHTML = '<i class="bi bi-stop-circle me-1" aria-hidden="true"></i>End';
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
            go.innerHTML = '<i class="bi bi-broadcast me-1" aria-hidden="true"></i>Go Live';
            go.addEventListener('click', () => this.goLive(songId));
            host.appendChild(go);
        }
        const join = document.createElement('button');
        join.type = 'button';
        join.className = 'btn btn-sm btn-outline-secondary';
        join.title = 'Follow a worship leader’s live session by code';
        join.innerHTML = '<i class="bi bi-people me-1" aria-hidden="true"></i>Join Live';
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
        label.innerHTML = '<i class="bi bi-eye-fill me-1" aria-hidden="true"></i>Following '
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

    /* ------------------------------------------------------------- host bar -- */

    /**
     * #1770 C5 (req #1) — the fixed HOST bar, visible on every page while
     * hosting (not just the song page the leader broadcast from — that is
     * the whole point of this delta: persistence was already real,
     * `_showFollowBanner`'s sibling on the follower side just never had a
     * host-side equivalent making it VISIBLE off the one song page).
     *
     * Rule #32 shape (CLAUDE.md — the setlist bar's own pattern,
     * `setlist.js`'s `renderSongNavigation()`): removes any existing
     * instance UNCONDITIONALLY, as the very FIRST statement, before any
     * early return — a `position:fixed` element on `<body>` survives an SPA
     * content swap on its own, so skipping the teardown-before-any-return
     * ordering strands the bar over whatever page the leader navigates to
     * next. Called from `init()`, every `initSongPage()`, `goLive()`,
     * `endHost()`, AND unconditionally from `router.js`'s `afterPageLoad()`
     * on EVERY navigation (mirroring `setList.renderSongNavigation()`'s own
     * wiring) — that last call site is what makes the "every page" half of
     * req #1 true for navigations that never touch a song page at all.
     */
    renderHostBar() {
        document.getElementById('live-host-bar')?.remove();
        if (!this.hostCode) { return; }

        const bar = document.createElement('div');
        bar.id = 'live-host-bar';
        bar.setAttribute('role', 'status');
        bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:1080;'
            + 'background:#dc3545;color:#fff;text-align:center;padding:0.4rem 1rem;'
            + 'font-size:0.85rem;line-height:1.3;box-shadow:0 -2px 8px rgba(0,0,0,.2);'
            + 'padding-bottom:calc(0.4rem + env(safe-area-inset-bottom, 0px));'
            + 'display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;justify-content:center;';

        const label = document.createElement('span');
        label.innerHTML = '<i class="bi bi-broadcast-pin me-1" aria-hidden="true"></i>LIVE · <strong>' + this._esc(this.hostCode) + '</strong>';

        const codeBtn = this._hostBarButton('Show code', () => this._toggleCodeView());
        const consoleBtn = this._hostBarButton('Console', () => this._openConsole());
        /* #1798 — keep a running session alive mid-service (the leader's
           phone died during the sermon, or they simply want more time than
           they originally picked at "Go Live"). */
        const extendBtn = this._hostBarButton('Extend', () => this._extendSession());
        const endBtn = this._hostBarButton('End', () => this.endHost(false));

        bar.appendChild(label);
        bar.appendChild(codeBtn);
        bar.appendChild(consoleBtn);
        bar.appendChild(extendBtn);
        bar.appendChild(endBtn);
        document.body.appendChild(bar);
    }

    _hostBarButton(label, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-light';
        btn.style.cssText = 'padding:0.05rem 0.5rem;font-size:0.78rem;';
        btn.textContent = label;
        btn.addEventListener('click', onClick);
        return btn;
    }

    /* ---------------------------------------------------- session length -- */

    /**
     * #1798 — keep a RUNNING session alive mid-service. Re-uses the same
     * duration picker as `goLive()` (30 min / 1 hour / 2 hours / until I end
     * it) then POSTs `live_follow_extend`, which both widens the window AND
     * resets the idle clock to now (server-side — see api.php) so the FULL
     * chosen window applies from this moment, not whatever fraction of the
     * old one remained.
     */
    async _extendSession() {
        if (!this.hostCode) { return; }
        const mins = await this._pickIdleDuration('Keep live for…');
        if (mins === null) { return; } /* dismissed — no-op, same posture as goLive()'s picker */
        try {
            const r = await this._api('live_follow_extend', {
                method: 'POST', auth: true,
                body: { code: this.hostCode, idleTimeoutMins: mins },
            });
            /* #1798/rule #35 — branch on HTTP status, never the error prose.
               409 means specifically "this install hasn't run the #1770
               idle-timeout migration yet", distinct from every other
               failure (400/403/404), which fall through to the generic
               server-message toast below. */
            if (r.status === 409) {
                this.app.showToast('This iHymns install hasn’t been updated for session lengths yet — ask your site administrator.', 'warning', 6000);
                return;
            }
            if (!r.httpOk || !r.data.ok) {
                this.app.showToast(r.data.error || 'Could not extend the session.', 'danger');
                return;
            }
            const applied = (typeof r.data.idleTimeoutMins === 'number') ? r.data.idleTimeoutMins : mins;
            this.idleTimeoutMins = applied;
            this.app.showToast('Kept live for ' + this._formatMins(applied) + '.', 'success');
        } catch (_e) {
            this.app.showToast('Could not extend the session (network error).', 'danger');
        }
    }

    /**
     * #1798 — a small lightweight choice dialog (mirrors the existing
     * `bootstrap.Modal` pattern already used directly by several other
     * modules — setlist.js, request.js, etc. — rather than app.js's
     * `showChoice()`, which only ever offers exactly two options). Shared by
     * `goLive()` (session length at start) and `_extendSession()` (mid-
     * service), so the "30 min / 1 hour / 2 hours / until I end it" set
     * lives in exactly one place.
     *
     * @param {string} title Modal heading.
     * @returns {Promise<number|null>} The chosen minutes, or null if the
     *          dialog was dismissed without a choice (caller decides what
     *          "no choice" means — goLive() treats it as "use the server
     *          default"; _extendSession() treats it as "do nothing").
     */
    _pickIdleDuration(title) {
        return new Promise((resolve) => {
            const options = [
                { label: '30 minutes', value: 30 },
                { label: '1 hour', value: 60 },
                { label: '2 hours', value: 120 },
                { label: 'Until I end it', value: 240 },
            ];
            const id = 'live-follow-duration-' + Date.now();
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = id;
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', id + '-label');
            modal.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-sm">'
                + '<div class="modal-content">'
                + '<div class="modal-header">'
                + '<h5 class="modal-title" id="' + id + '-label">' + this._esc(title) + '</h5>'
                + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
                + '</div>'
                + '<div class="modal-body d-grid gap-2" id="' + id + '-body"></div>'
                + '</div></div>';
            document.body.appendChild(modal);

            const body = modal.querySelector('#' + id + '-body');
            let chosen = null;
            options.forEach((opt) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline-primary';
                btn.textContent = opt.label;
                btn.addEventListener('click', () => { chosen = opt.value; bsModal.hide(); });
                body.appendChild(btn);
            });

            const bsModal = new bootstrap.Modal(modal);
            modal.addEventListener('hidden.bs.modal', () => { modal.remove(); resolve(chosen); });
            bsModal.show();
        });
    }

    /** #1798 — human-readable minutes for the "Kept live for …" toast. */
    _formatMins(mins) {
        if (mins >= 240) { return '4 hours (until you end it)'; }
        if (mins >= 60 && mins % 60 === 0) {
            const hrs = mins / 60;
            return hrs + (hrs === 1 ? ' hour' : ' hours');
        }
        return mins + ' minutes';
    }

    /* -------------------------------------------------------- big code + QR -- */

    /**
     * #1770 C6 (req #6) — a large code + QR overlay, toggled from the host
     * bar's "Show code" button. QR ONLY via the same-origin `/qr`
     * endpoint (rule #38 — never a client-side QR library); the join URL is
     * `/?svc_code=<CODE>`, the SAME param the Service-Projection QR already
     * emits (service-projection.php's `joinUrlFor()`) and that
     * `service-follow.js`'s `_readSvcCodeParam()` now reads (rule #33 —
     * closes the standing emitter-with-no-reader gap). A `/qr` 503
     * (CueRCode not configured) simply fails the `<img>` load and the big
     * typed code alongside it keeps working — never a blank box.
     *
     * NOTE the deliberate ABSENCE of `.php` in the src below: `/qr` is the
     * only reachable route (the extensionless `.htaccess` alias — rules
     * #33/#38, the /og-image precedent). A literal `/qr.php?...` src looks
     * identical in a code review but 404s every time, silently, because
     * `.htaccess`'s "block direct PHP access" rule matches the browser's
     * RAW request line before any rewrite ever runs.
     */
    _toggleCodeView() {
        if (document.getElementById('live-host-code-overlay')) { this._removeCodeView(); return; }
        this._showCodeView();
    }

    _showCodeView() {
        this._removeCodeView();
        if (!this.hostCode) { return; }

        const overlay = document.createElement('div');
        overlay.id = 'live-host-code-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-label', 'Live session code');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:1090;background:#0b1020;color:#fff;'
            + 'display:flex;flex-direction:column;align-items:center;justify-content:center;'
            + 'text-align:center;padding:4vmin;gap:3vmin;';

        const codeEl = document.createElement('div');
        codeEl.style.cssText = 'font-size:min(22vw,14vh,7rem);font-weight:800;letter-spacing:.08em;'
            + 'font-family:ui-monospace,"SFMono-Regular",Menlo,Consolas,monospace;';
        codeEl.textContent = this.hostCode;

        /* aria-hidden — a purely visual accelerator for a phone camera; the
           typed code above already conveys everything a screen reader needs
           (mirrors service-projection.php's own QR treatment, #1339). */
        const qrWrap = document.createElement('div');
        qrWrap.setAttribute('aria-hidden', 'true');
        qrWrap.style.cssText = 'width:min(50vw,40vh,320px);height:min(50vw,40vh,320px);background:#fff;'
            + 'border-radius:1rem;padding:1rem;display:flex;align-items:center;justify-content:center;';
        const img = document.createElement('img');
        img.alt = '';
        img.style.cssText = 'width:100%;height:100%;display:block;';
        img.addEventListener('error', () => { qrWrap.style.display = 'none'; });
        const joinUrl = location.origin + '/?svc_code=' + encodeURIComponent(this.hostCode);
        img.src = '/qr?data=' + encodeURIComponent(joinUrl) + '&format=svg&size=512';
        qrWrap.appendChild(img);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn btn-outline-light';
        closeBtn.textContent = 'Close';
        closeBtn.addEventListener('click', () => this._removeCodeView());

        overlay.appendChild(codeEl);
        overlay.appendChild(qrWrap);
        overlay.appendChild(closeBtn);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) { this._removeCodeView(); } });
        document.body.appendChild(overlay);

        /* a11y audit M3 — was role="dialog" with none of the rest of the
           contract: no aria-modal, focus never moved in, no Escape, no Tab
           trap, no focus restore on close. openModalDialog() supplies all
           four; onClose does the DOM removal this function used to do
           directly, so every dismiss path (Close button, backdrop click,
           Escape) now goes through the one close() below. */
        this._codeViewClose = openModalDialog(overlay, {
            onClose: () => overlay.remove(),
        });
    }

    _removeCodeView() {
        if (this._codeViewClose) {
            const close = this._codeViewClose;
            this._codeViewClose = null;
            close();
            return;
        }
        /* No modal was ever opened this call (e.g. re-entrant guard in
           _showCodeView()) — fall back to a plain removal so this stays
           safe to call unconditionally, as every existing call site does. */
        document.getElementById('live-host-code-overlay')?.remove();
    }

    /* -------------------------------------------------------------- console -- */

    /**
     * #1770 C6 (req #6, optional) — open the lazily-loaded drive-sections
     * console. Real ES module, dynamically imported (rule #30 — this file is
     * itself a real module in the app.js graph, never an injected fragment
     * script, so a dynamic `import()` here carries none of the nonce/CSP
     * problem that pattern exists to avoid).
     */
    _openConsole() {
        if (!this.hostCode) { return; }
        import('./live-host-console.js').then((m) => {
            if (!this._hostConsole) { this._hostConsole = new m.LiveHostConsole(this); }
            this._hostConsole.open();
        }).catch((err) => { console.error('[LiveFollow] console load failed:', err); });
    }

    _esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    /* ---------------------------------------------------- follow persistence -- */

    /* Read the persisted follow state (#1377, +presenceToken #1770 C5). New
       shape is JSON {code, token, presenceToken}; a LEGACY value is the bare
       code string (token/presenceToken absent → per-IP poll, no CCLI unlock
       re-assertion). Returns {code, token, presenceToken} with safe
       defaults, never throws. */
    _readFollowState() {
        const empty = { code: null, token: null, presenceToken: null };
        let raw = null;
        try { raw = sessionStorage.getItem(LF_FOLLOW_KEY); } catch (_e) { return empty; }
        if (!raw) { return empty; }
        /* JSON object form. */
        if (raw.charAt(0) === '{') {
            try {
                const o = JSON.parse(raw);
                if (o && typeof o === 'object') {
                    const code          = (typeof o.code === 'string')          ? o.code          : null;
                    const token         = (typeof o.token === 'string')         ? o.token         : null;
                    const presenceToken = (typeof o.presenceToken === 'string') ? o.presenceToken : null;
                    return { code: code, token: token, presenceToken: presenceToken };
                }
            } catch (_e) { /* fall through to legacy string handling */ }
        }
        /* Legacy bare-code string. */
        return { code: raw, token: null, presenceToken: null };
    }

    /* Persist the active follow state as {code, token, presenceToken} (#1377,
       +#1770 C5) so a full reload keeps the per-follower poll-bucket token AND
       re-asserts the CCLI-unlock presence cookie. No-op when not following. */
    _writeFollowState() {
        if (!this.followCode) { return; }
        try {
            sessionStorage.setItem(
                LF_FOLLOW_KEY,
                JSON.stringify({
                    code: this.followCode,
                    token: this.followToken || null,
                    presenceToken: this.presenceToken || null,
                })
            );
        } catch (_e) { /* storage blocked — in-memory follow still works */ }
    }
}
