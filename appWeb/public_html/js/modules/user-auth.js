/**
 * iHymns — User Authentication Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Public-facing user authentication for the iHymns PWA. Allows users
 * to register and log in with bearer tokens, enabling cross-device
 * setlist sync. Separate from the admin/editor auth in /manage/.
 *
 * STORAGE:
 *   localStorage['ihymns_auth_token']  — Bearer token (64 hex chars)
 *   localStorage['ihymns_auth_user']   — Cached user info (JSON)
 */

import { userHasEntitlement } from './entitlements.js';
import { offlineQueue } from './offline-queue.js';
import {
    STORAGE_AUTH_TOKEN, STORAGE_AUTH_USER, EVT_AUTH_CHANGED,
    STORAGE_SETLISTS_SYNCED_AT, STORAGE_FAVORITES_SYNCED_AT,
} from '../constants.js';
import { performAppleSignIn } from './apple-signin.js';
import { apiFetch } from '../utils/api-client.js';

export class UserAuth {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;
        /* Public app capabilities (#766). Populated lazily by
           _ensureAppStatus(); the auth modal reads this to decide
           whether to promote the email-login path. Defaults to
           emailLoginEnabled=true so a transient /api?action=app_status
           failure doesn't lock email-only deployments out — the
           server-side endpoint still 503s if the request actually
           hits with no email service. */
        this._appStatus = null;
        this._appStatusPromise = null;
    }

    /**
     * Lazily fetch and cache /api?action=app_status. Used by
     * showAuthModal to decide whether to promote the email login
     * path (#766). Returns the cached object on subsequent calls.
     * Best-effort — failures resolve to a permissive default so
     * a transient outage doesn't change the modal's shape.
     *
     * @returns {Promise<object>}
     */
    async _ensureAppStatus() {
        if (this._appStatus) return this._appStatus;
        if (this._appStatusPromise) return this._appStatusPromise;
        this._appStatusPromise = (async () => {
            try {
                const r = await apiFetch('/api?action=app_status', {
                    credentials: 'same-origin',
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                this._appStatus = await r.json();
            } catch (_e) {
                /* Permissive default for email — keep current behaviour (the
                   server-side 503 is still the safety net). Sign in with
                   Apple (#1479) is the OPPOSITE default: appleWebLoginEnabled
                   stays falsy on a failed fetch, so a transient app_status
                   outage never shows a button that would just 503 anyway —
                   dormancy-by-default matches the server's own fail-closed
                   posture for this brand-new, admin-opt-in feature. */
                this._appStatus = { emailLoginEnabled: true, appleWebLoginEnabled: false, appleSiwaServicesId: null };
            }
            return this._appStatus;
        })();
        return this._appStatusPromise;
    }

    /* =====================================================================
     * TOKEN & USER MANAGEMENT
     * ===================================================================== */

    /**
     * Get the stored auth token.
     * @returns {string|null}
     */
    getToken() {
        return localStorage.getItem(STORAGE_AUTH_TOKEN) || null;
    }

    /**
     * Get the cached user info.
     * @returns {{ id: number, username: string, display_name: string }|null}
     */
    getUser() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_AUTH_USER)) || null;
        } catch {
            return null;
        }
    }

    /**
     * Check if the user is logged in (has a token).
     * @returns {boolean}
     */
    isLoggedIn() {
        return !!this.getToken();
    }

    /**
     * Fire the global `EVT_AUTH_CHANGED` event so any UI that depends
     * on signed-in state can refresh itself (header menu, settings page
     * Account card, setlist sync bar, etc.). The detail payload is the
     * current user object, or null when signed out.
     */
    _broadcastAuthChanged() {
        try {
            document.dispatchEvent(new CustomEvent(EVT_AUTH_CHANGED, {
                detail: {
                    loggedIn: this.isLoggedIn(),
                    user: this.getUser(),
                },
            }));
        } catch { /* IE/legacy — ignore */ }
        /* Also refresh the header and settings account card immediately so
           callers don't have to wait for event handlers to bind. */
        this._updateHeaderState();
        this.app.settings?.refreshAccountSection?.();
    }

    /**
     * Save auth credentials to localStorage.
     * @param {string} token Bearer token
     * @param {object} user  User info { id, username, display_name }
     */
    saveCredentials(token, user) {
        localStorage.setItem(STORAGE_AUTH_TOKEN, token);
        localStorage.setItem(STORAGE_AUTH_USER, JSON.stringify(user));
        /* A fresh sign-in must re-run the one-time user-data backfill for the
           new session AND re-gate destructive 'replace' pushes until that new
           merge reconcile has hydrated the cache (a different account's data
           must not be authoritatively replaced by the prior session's). */
        this._resetUserDataSyncState();
        this._broadcastAuthChanged();
    }

    /**
     * Clear auth credentials from localStorage.
     */
    clearCredentials() {
        localStorage.removeItem(STORAGE_AUTH_TOKEN);
        localStorage.removeItem(STORAGE_AUTH_USER);
        this._resetUserDataSyncState();
        this._broadcastAuthChanged();
    }

    /**
     * Reset the per-session user-data sync state on any auth transition:
     * clear the once-per-session guard + in-progress latch, and DISARM each
     * store's _syncReady so no per-edit 'replace' can fire until the next
     * merge reconcile re-hydrates the cache for the (possibly different)
     * account. (review #1 / #6)
     */
    _resetUserDataSyncState() {
        this._userDataSynced = false;
        this._userDataSyncing = false;
        this._userDataSyncedAt = 0;
        if (this.app.setList) this.app.setList._syncReady = false;
        if (this.app.favorites) this.app.favorites._syncReady = false;
        /* #1649 — drop both sync watermarks on ANY auth transition. A watermark
           means "account X has seen everything up to time T"; carrying it into
           a different account would tell the server that account Y's rows are
           already-seen and therefore deletable, so signing in as someone else
           on a shared device could delete THEIR data. Clearing is always safe:
           an absent watermark just means the next sync omits `since` and gets
           the conservative legacy path. */
        try {
            localStorage.removeItem(STORAGE_SETLISTS_SYNCED_AT);
            localStorage.removeItem(STORAGE_FAVORITES_SYNCED_AT);
        } catch (_e) {}
        /* Don't let a clear's one-cycle pull-suppression leak across an auth
           transition into a fresh login's legitimate cross-device pull. */
        if (this.app.history) this.app.history._clearedLocally = false;
    }

    /**
     * Build Authorization header for API requests.
     * @returns {Object} Headers object with Authorization if logged in
     */
    authHeaders() {
        const token = this.getToken();
        if (!token) return {};
        return { 'Authorization': `Bearer ${token}` };
    }

    /* =====================================================================
     * API METHODS
     * ===================================================================== */

    /**
     * Run a JSON POST against the auth API and resolve the response into
     * either { ok: true, data } or { ok: false, error } where `error` is
     * the most diagnostic string we can produce.
     *
     * Three failure shapes that previously all collapsed to the same
     * misleading "Network error. Please try again." (#803):
     *   1. fetch() rejects                  → real connectivity / CORS
     *   2. fetch() succeeds but res.json()  → server returned non-JSON
     *      throws (HTML error page, empty body) — surface status + a
     *      snippet of the body so support has something actionable
     *   3. res.ok === false                 → server returned JSON 4xx;
     *      use data.error (existing behaviour) and fall back to status
     *      text if data.error is missing
     *
     * `defaultMsg` is the friendly verb fallback for case 3 — caller
     * passes "Registration failed." / "Login failed." etc.
     *
     * @param {string} url
     * @param {object} body
     * @param {string} defaultMsg
     * @returns {Promise<{ ok: true, data: any } | { ok: false, error: string }>}
     */
    async _postJson(url, body, defaultMsg) {
        let res;
        try {
            res = await apiFetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });
        } catch (err) {
            /* Case 1 — fetch itself rejected. This is the only true
               "couldn't reach the server" path. */
            return { ok: false, error: 'Network error. Please check your connection and try again.' };
        }

        /* Try to parse the body as JSON. On parse failure (case 2) we
           reach for the raw text so we can show the curator something
           actionable — typically the start of a PHP error page or an
           empty 502. */
        let data = null;
        let parseFailed = false;
        try {
            data = await res.json();
        } catch {
            parseFailed = true;
        }

        if (parseFailed) {
            let snippet = '';
            try { snippet = (await res.text()).slice(0, 200).trim(); } catch { /* ignore */ }
            const detail = snippet ? ` Server said: "${snippet.replace(/\s+/g, ' ')}"` : '';
            return {
                ok:    false,
                error: `Server returned an unreadable response (HTTP ${res.status} ${res.statusText}).${detail} `
                     + 'This is a server bug — please report it.',
            };
        }

        if (!res.ok) {
            /* Case 3 — server replied with a JSON body but a non-2xx
               status. data.error is the friendly message; data.request_id
               (added by the global handler in api.php for 500s, #803)
               is included verbatim so support can correlate the log. */
            const friendly = (data && data.error) ? data.error : (defaultMsg + ` (HTTP ${res.status})`);
            const rid      = data && data.request_id ? ` [ref ${data.request_id}]` : '';
            return { ok: false, error: friendly + rid };
        }

        return { ok: true, data };
    }

    /**
     * Register a new account.
     * @param {string} username
     * @param {string} password
     * @param {string} displayName
     * @param {string} [email] Optional email — when present and an email
     *        provider is configured server-side, the new account
     *        receives a verification email (#898).
     * @returns {Promise<{ success: boolean, error?: string,
     *                     verificationEmailSent?: boolean,
     *                     verificationEmailProvider?: string }>}
     */
    async register(username, password, displayName, email = '') {
        const payload = { username, password, display_name: displayName };
        if (email) payload.email = email;
        const r = await this._postJson(
            `${this.app.config.apiUrl}?action=auth_register`,
            payload,
            'Registration failed.'
        );
        if (!r.ok) return { success: false, error: r.error };
        this.saveCredentials(r.data.token, r.data.user);
        return {
            success: true,
            verificationEmailSent:     !!r.data.verification_email_sent,
            verificationEmailProvider: r.data.verification_email_provider || 'none',
        };
    }

    /**
     * Log in with existing credentials.
     * @param {string} username
     * @param {string} password
     * @returns {Promise<{ success: boolean, error?: string }>}
     */
    async login(username, password) {
        const r = await this._postJson(
            `${this.app.config.apiUrl}?action=auth_login`,
            { username, password },
            'Login failed.'
        );
        if (!r.ok) return { success: false, error: r.error };
        this.saveCredentials(r.data.token, r.data.user);
        return { success: true };
    }

    /**
     * Log out (invalidate token on server and clear local credentials).
     */
    async logout() {
        try {
            await apiFetch(`${this.app.config.apiUrl}?action=auth_logout`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
            });
        } catch {
            /* Non-critical — clear local creds regardless */
        }

        this.clearCredentials();

        /* #1388 — ask the service worker to drop the per-user caches.
           clearCredentials() only touches localStorage; the Cache Storage
           buckets belong to the SW and are keyed by URL alone, so without this
           the next user of a shared device can still be served fragments
           fetched under the previous session. Best-effort and non-blocking:
           no controller (first load, SW unsupported, hard-refresh) simply
           means there is no cache to clear. */
        try {
            navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_USER_CACHES' });
        } catch {
            /* Non-critical — never let cache cleanup break sign-out. */
        }
    }

    /**
     * Verify the current token is still valid.
     * @returns {Promise<boolean>}
     */
    async verify() {
        if (!this.isLoggedIn()) return false;

        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_me`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                /* Include cookies so the server can renew the auth cookie
                   lifetime (sliding expiry, #390). */
                credentials: 'same-origin',
            });

            /* Only clear credentials on an explicit "your token is bad" —
               401 Unauthorized or 403 Forbidden. Network failures, 5xx,
               CORS errors etc. are transient and must NOT log the user
               out, otherwise a momentary blip kicks everyone to sign-in. */
            if (res.status === 401 || res.status === 403) {
                this.clearCredentials();
                return false;
            }
            if (!res.ok) {
                /* Keep the token; just couldn't refresh right now. */
                return false;
            }

            const data = await res.json();
            if (data.user) {
                /* Update cached user info + notify any listeners so UI
                   matches the latest server-reported role / display name. */
                localStorage.setItem(STORAGE_AUTH_USER, JSON.stringify(data.user));
                this._broadcastAuthChanged();
            }

            return true;
        } catch {
            /* Offline / DNS / TLS failure — keep the token. */
            return false;
        }
    }

    /* =====================================================================
     * SIGN IN WITH APPLE (Web) — #1470 W1 backend / #1479 W2-W3 front end
     * ===================================================================== */

    /**
     * Run the web Sign in with Apple popup flow (js/modules/apple-signin.js)
     * and, on success, persist the returned session via the SAME
     * saveCredentials() path password/email login use — so header state,
     * the setlist sync bar, and every downstream `EVT_AUTH_CHANGED`
     * listener behave identically regardless of which method signed the
     * user in.
     *
     * @param {object} [opts]
     * @param {boolean} [opts.link=false] true = attach Apple to the CURRENT
     *        bearer (Settings "Link" button) instead of signing in fresh.
     * @returns {Promise<{ success: boolean, error?: string, flow?: string }>}
     *          `flow` is 'matched' | 'linked' | 'created' — see
     *          apple-signin.js's docblock. Callers show a one-time
     *          "already had an account? sign in and link from Settings"
     *          hint only on 'created'.
     */
    async signInWithApple({ link = false } = {}) {
        const status = await this._ensureAppStatus();
        if (status?.appleWebLoginEnabled !== true || !status?.appleSiwaServicesId) {
            return { success: false, error: 'Sign in with Apple is not available.' };
        }

        const result = await performAppleSignIn({
            apiUrl: this.app.config.apiUrl,
            clientId: status.appleSiwaServicesId,
            link,
            /* link mode attaches Apple to the CALLER's account — auth_apple's
               server-side link=1 path requires an already-authenticated
               bearer (api.php ~3204) precisely so a stolen identityToken
               can never hijack an arbitrary victim account. */
            authHeaders: link ? this.authHeaders() : {},
        });

        if (!result.ok) {
            return { success: false, error: result.error };
        }

        this.saveCredentials(result.token, result.user);
        return { success: true, flow: result.flow };
    }

    /**
     * Fetch the caller's linked external-identity providers (Settings
     * "Connected accounts" card). Never includes the Apple `sub` or a
     * refresh token — see `?action=auth_providers_list`'s server-side
     * masking (api.php `_authProviderListForUser()`).
     *
     * @returns {Promise<Array<{provider:string, email_masked:string, is_private_relay:boolean, linked_at:?string}>>}
     */
    async listLinkedProviders() {
        if (!this.isLoggedIn()) return [];
        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_providers_list`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
            });
            if (!res.ok) return [];
            const data = await res.json();
            return Array.isArray(data.providers) ? data.providers : [];
        } catch {
            return [];
        }
    }

    /**
     * Unlink a provider from the current account (Settings "Unlink"
     * button). The server enforces a lockout guard (409) that this method
     * simply surfaces — it never attempts to pre-validate that guard
     * client-side (the server holds the authoritative, race-safe view via
     * `SELECT ... FOR UPDATE`, see api.php `case 'auth_provider_unlink'`).
     *
     * @param {string} provider e.g. 'apple'
     * @returns {Promise<{ success: boolean, error?: string, providers?: Array }>}
     */
    async unlinkProvider(provider) {
        let res;
        try {
            res = await apiFetch(`${this.app.config.apiUrl}?action=auth_provider_unlink`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({ provider }),
            });
        } catch {
            return { success: false, error: 'Network error. Please check your connection and try again.' };
        }

        let data = null;
        try {
            data = await res.json();
        } catch {
            return { success: false, error: `Server returned an unreadable response (HTTP ${res.status}).` };
        }

        if (!res.ok) {
            return { success: false, error: data?.error || 'Could not unlink account.' };
        }

        return { success: true, providers: Array.isArray(data.providers) ? data.providers : [] };
    }

    /* =====================================================================
     * SYNC WATERMARKS + ABSORB HELPERS (#1649)
     *
     * Background: the server used to cap an incoming sync payload and then,
     * in 'replace' mode, delete every row absent from the CAPPED list — so a
     * user with more than 50 set lists silently lost the tail. The server-side
     * fix refuses to delete on a truncated payload, and additionally refuses
     * to delete rows written after a `since` watermark the client supplies.
     * These helpers are the client half of that watermark.
     * ===================================================================== */

    /**
     * Read a stored sync watermark, or null if we've never absorbed a sync.
     * @param {string} key STORAGE_SETLISTS_SYNCED_AT | STORAGE_FAVORITES_SYNCED_AT
     * @returns {string|null}
     */
    _syncedAt(key) {
        try { return localStorage.getItem(key) || null; } catch (_e) { return null; }
    }

    /**
     * Persist a sync watermark.
     *
     * ⚠️ LOAD-BEARING INVARIANT — call this ONLY from _absorbSetlistSync() /
     * _absorbFavoritesSync(), immediately AFTER the returned list has been
     * merged into local state, and nowhere else.
     *
     * ELI5: the watermark means "I have seen everything the server had up to
     * this moment". If we advanced it without actually keeping the list the
     * server sent, we would be lying — and the server takes the lie seriously:
     * on the NEXT sync it would treat those unseen rows as old enough to be
     * deletable, and our payload (which never absorbed them) would not mention
     * them. They'd be deleted one cycle later, which is the exact data-loss
     * class #1649 exists to close, just delayed by one round-trip.
     *
     * @param {string} key
     * @param {?string} value Server-minted DB clock reading; ignored if falsy.
     */
    _setSyncedAt(key, value) {
        if (!value) return;
        try { localStorage.setItem(key, value); } catch (_e) {}
    }

    /**
     * Tell the user once per session that their collection exceeds the server
     * cap, so the overflow lives on this device only.
     *
     * Fired inside syncSetlists()/syncFavorites() rather than at each call
     * site: there are four callers between them (reconcile, per-edit push,
     * offline drain, share) and a warning wired into only some of them is a
     * warning the user may never see. Throttled by an instance flag so a
     * flurry of edits doesn't produce a flurry of toasts.
     *
     * @param {object} data      Decoded server response ({truncated, cap}).
     * @param {string} flagName  Instance property used as the once-per-session latch.
     * @param {string} noun      Plural user-facing noun for the copy.
     */
    _warnTruncated(data, flagName, noun) {
        if (data?.truncated !== true || this[flagName]) return;
        this[flagName] = true;
        const cap = Number(data.cap) || 0;
        this.app.showToast?.(
            `You have more than ${cap} ${noun} — only ${cap} sync to your account; `
            + `the rest stay on this device.`,
            'warning',
            6000
        );
    }

    /**
     * Absorb a syncSetlists() result: merge the server's list into local state,
     * THEN advance the watermark. Order matters — see _setSyncedAt().
     *
     * The union puts the CURRENT local cache (re-read here, after the network
     * round-trip) on top, so an edit made during the request window survives
     * instead of being clobbered by the write-back.
     *
     * @param {?object} res Result from syncSetlists(), or null.
     * @returns {boolean} true if a result was absorbed.
     */
    _absorbSetlistSync(res) {
        if (!res || !Array.isArray(res.setlists) || !this.app.setList) return false;
        /* #1661 — PRUNE BEFORE UNION, and the order is load-bearing. The union
           lets the CURRENT local copy win for a shared id (it may carry an
           in-flight edit), so a set list another device deleted would survive
           its own tombstone if it were still in the local array at union time.
           Pruning first removes it from BOTH sides of the merge. */
        const pruned = this.app.setList.applyTombstones(
            this.app.setList.getAll(),
            res.tombstones
        );
        /* #1675 — SERVER WINS FOR CONFLICTED IDS. The union below puts the local
           copy on top (correct for an in-flight edit) — but a row the server
           REFUSED to overwrite (edited on another device since our watermark)
           would then be resurrected locally from our stale copy and re-pushed
           into a permanent conflict loop. So drop those ids from the local side
           first; the union then takes the authoritative server row. BELT (§A.7):
           only drop a local row when the server response actually carries a
           replacement for that id — a conflict entry with no matching row would
           otherwise delete the local copy leaving nothing. */
        const serverIds = new Set(res.setlists.map((s) => s && s.id).filter(Boolean));
        const conflictIds = new Set(
            (Array.isArray(res.conflicts) ? res.conflicts : [])
                .map((c) => c && c.id)
                .filter((id) => id && serverIds.has(id))
        );
        const localSide = conflictIds.size
            ? pruned.filter((l) => l && !conflictIds.has(l.id))
            : pruned;
        const final = this._unionSetlists(localSide, res.setlists);
        this.app.setList.saveAll(final, { sync: false });
        this._setSyncedAt(STORAGE_SETLISTS_SYNCED_AT, res.syncedAt);

        /* One toast per absorb (not per row). The refused edit is replaced by
           the newer server copy; the user re-applies it and the next push finds
           the row content-identical (watermark advanced past the conflict) and
           settles via the no-op skip — self-healing, no auto-retry (which would
           be last-writer-wins with extra steps) and no auto-merge (no base). */
        if (conflictIds.size) {
            const names = res.setlists
                .filter((s) => s && conflictIds.has(s.id) && s.name)
                .map((s) => s.name);
            const one = names.length === 1;
            const label = one ? `“${names[0]}”` : `${conflictIds.size} set lists`;
            this.app.showToast?.(
                `${label} ${one ? 'was' : 'were'} updated on another device — showing the newer version. `
                + `Your last change ${one ? 'to it' : 'to them'} was not applied.`,
                'warning',
                8000
            );
        }
        return true;
    }

    /**
     * Absorb a syncFavorites() result: merge into local state, THEN advance the
     * watermark. Same ordering invariant as _absorbSetlistSync().
     *
     * @param {?object} res Result from syncFavorites(), or null.
     * @returns {boolean} true if a result was absorbed.
     */
    _absorbFavoritesSync(res) {
        if (!res || !Array.isArray(res.favorites) || !this.app.favorites) return false;
        this.app.favorites.saveAll(
            this._mergeFavorites(this.app.favorites.getAll(), res.favorites),
            { sync: false }
        );
        this._setSyncedAt(STORAGE_FAVORITES_SYNCED_AT, res.syncedAt);
        return true;
    }

    /* =====================================================================
     * SETLIST SYNC
     * ===================================================================== */

    /**
     * Sync local setlists with the server.
     * Sends all local setlists, receives the merged result.
     *
     * @param {Array}  localSetlists Array of local setlist objects
     * @param {string} [mode='replace'] 'replace' (authoritative — per-edit
     *   auto-sync; deletions propagate) or 'merge' (union — first-login
     *   backfill; never deletes). See the server contract in api.php.
     * @returns {Promise<{setlists: Array, syncedAt: ?string, truncated: boolean}|null>}
     *   The server's merged result plus the #1649 sync metadata, or null on
     *   failure/queued. NOTE: this used to resolve to a BARE ARRAY — callers
     *   must now read `.setlists` and hand the whole object to
     *   _absorbSetlistSync() so the watermark advances with the data.
     */
    async syncSetlists(localSetlists, mode = 'replace') {
        if (!this.isLoggedIn()) return null;

        /* Offline → mark a pending sync in the queue. bindOfflineDrains()
           replays with the LATEST local state when connectivity returns,
           so the merge reflects every edit made while offline (#338). */
        if (!navigator.onLine) {
            try { await offlineQueue.enqueue('setlists-sync', { ts: Date.now() }); } catch (_e) {}
            return null;
        }

        /* #1661 — the explicit deletions this device is announcing. Captured
           BEFORE the request so the exact list can be cleared afterwards: a
           deletion made while the request is in flight must stay queued. */
        const deleted = this.app.setList?.getPendingDeletes?.() ?? [];

        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=user_setlists_sync`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({
                    setlists: localSetlists,
                    mode,
                    /* #1649 — the watermark from our last ABSORBED sync. The
                       server will not delete rows written after it, so a stale
                       cache here can no longer wipe another device's additions.
                       Omitted entirely when we've never synced, which the
                       server reads as "legacy client, absence-only deletion". */
                    ...(this._syncedAt(STORAGE_SETLISTS_SYNCED_AT)
                        ? { since: this._syncedAt(STORAGE_SETLISTS_SYNCED_AT) }
                        : {}),
                    /* #1661 — sent UNCONDITIONALLY, empty array included. Its
                       PRESENCE is what tells the server this client states its
                       deletions explicitly, so absence-based deletion is
                       retired for us. Sending it only when non-empty would
                       silently flip us back to the legacy inference on every
                       sync that happened not to delete anything — which is the
                       bug, not a saving. */
                    deleted,
                }),
            });

            if (!res.ok) {
                if (res.status === 401) this.clearCredentials();
                /* #1661/#1662 — 413: the payload was refused whole; nothing on
                   the server changed, so the local cache is intact and the user
                   must be told (unlike the silent truncation this replaced,
                   there is no partial success to mistake for one). Branch on the
                   machine-readable `reason`, NEVER the prose (rule #35): a
                   too_many_songs refusal names the offending list and its cap;
                   anything else (body_too_large / too_many_slots / a non-JSON
                   body) keeps the generic "too large" message. Latched once per
                   session either way, so a wedged auto-sync can't storm toasts. */
                if (res.status === 413 && !this._setlistTooLargeWarned) {
                    this._setlistTooLargeWarned = true;
                    let body = null;
                    try { body = await res.json(); } catch (_e) { /* non-JSON 413 → generic below */ }
                    if (body && body.reason === 'too_many_songs') {
                        const max = Number(body.maxSongs) || null;
                        /* Resolve the list's name from the local cache by the
                           id the server echoed — the response carries no name
                           (it changed nothing), and naming the list is what
                           makes the message actionable. */
                        const local = this.app.setList?.getAll?.() || [];
                        const match = Array.isArray(local)
                            ? local.find((l) => l && l.id === body.setlistId)
                            : null;
                        const named = match && match.name;
                        this.app.showToast?.(
                            (named
                                ? `The set list “${named}” has too many songs`
                                : 'One of your set lists has too many songs')
                            + (max ? ` (limit ${max}).` : '.')
                            + ' Nothing was changed — remove some songs and sync again.',
                            'danger',
                            8000
                        );
                    } else {
                        this.app.showToast?.(
                            'Your set lists are too large to sync in one request. Nothing was changed — '
                            + 'try removing a very large set list.',
                            'danger',
                            8000
                        );
                    }
                }
                return null;
            }

            const data = await res.json();
            if (!Array.isArray(data.setlists)) return null;

            /* #1661 — the server accepted these deletions (they are now
               permanent tombstones), so stop announcing them. Only the ids
               actually sent are cleared. */
            if (deleted.length > 0) this.app.setList?.clearPendingDeletes?.(deleted);

            /* Warn ONCE per session that the tail of the collection is
               device-only. Fired here rather than at each call site so every
               caller — reconcile, per-edit push, offline drain, share —
               is covered by one piece of code (#1649). Retained although the
               setlists cap is gone (#1661, `truncated` is now always false)
               because favourites and custom tags still cap. */
            this._warnTruncated(data, '_setlistCapWarned', 'set lists');

            return {
                setlists: data.setlists,
                syncedAt: typeof data.syncedAt === 'string' ? data.syncedAt : null,
                truncated: data.truncated === true,
                /* #1661 — deletions from other devices (and lazily-converted
                   expiries) for _absorbSetlistSync() to prune locally. */
                tombstones: Array.isArray(data.tombstones) ? data.tombstones : [],
                /* #1675 — per-row overwrite refusals for _absorbSetlistSync() to
                   take the server copy of (and toast). [] when none. */
                conflicts: Array.isArray(data.conflicts) ? data.conflicts : [],
            };
        } catch (err) {
            /* Network error mid-fetch — queue a sync marker so it runs
               again once we're online. TypeError is the usual fetch
               offline signal. */
            if (err instanceof TypeError) {
                try { await offlineQueue.enqueue('setlists-sync', { ts: Date.now() }); } catch (_e) {}
            }
            return null;
        }
    }

    /**
     * Sync favourites with the server. Same offline semantics as
     * syncSetlists — marks a pending sync via offlineQueue and
     * bindOfflineDrains replays with the latest localStorage state
     * when connectivity returns (#338).
     *
     * @param {Array<{id:string,tags:string[]}>|string[]} localFavorites
     *   Favourite objects (preferred, carries per-song tags) or legacy bare
     *   "CP-0001"-style ids — the server accepts both.
     * @param {string} [mode='replace'] 'replace' (authoritative — per-edit;
     *   removals + tag edits propagate) or 'merge' (first-login backfill).
     * @returns {Promise<{favorites: Array<{id:string,tags:string[]}>, syncedAt: ?string, truncated: boolean}|null>}
     *   The server's merged list plus the #1649 sync metadata, or null on
     *   failure/queued. NOTE: this used to resolve to a BARE ARRAY — callers
     *   must now read `.favorites` and hand the whole object to
     *   _absorbFavoritesSync() so the watermark advances with the data.
     */
    async syncFavorites(localFavorites, mode = 'replace') {
        if (!this.isLoggedIn()) return null;

        if (!navigator.onLine) {
            try { await offlineQueue.enqueue('favorites-sync', { ts: Date.now() }); } catch (_e) {}
            return null;
        }

        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=favorites_sync`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({
                    favorites: localFavorites,
                    mode,
                    /* #1649 — see syncSetlists() for why. */
                    ...(this._syncedAt(STORAGE_FAVORITES_SYNCED_AT)
                        ? { since: this._syncedAt(STORAGE_FAVORITES_SYNCED_AT) }
                        : {}),
                }),
            });

            if (!res.ok) {
                if (res.status === 401) this.clearCredentials();
                return null;
            }

            const data = await res.json();
            if (!Array.isArray(data.favorites)) return null;

            this._warnTruncated(data, '_favCapWarned', 'favourites');

            return {
                favorites: data.favorites,
                syncedAt: typeof data.syncedAt === 'string' ? data.syncedAt : null,
                truncated: data.truncated === true,
            };
        } catch (err) {
            if (err instanceof TypeError) {
                try { await offlineQueue.enqueue('favorites-sync', { ts: Date.now() }); } catch (_e) {}
            }
            return null;
        }
    }

    /**
     * Sync the per-user custom-tag pool (WS-G #1019). Same offline /
     * mode semantics as syncFavorites; queues under its own 'custom-tags-sync'
     * type so it drains independently on reconnect.
     *
     * @param {string[]} localTags
     * @param {string}   [mode='replace']
     * @returns {Promise<string[]|null>} Merged tag list, or null on failure/queued.
     */
    async syncCustomTags(localTags, mode = 'replace') {
        if (!this.isLoggedIn()) return null;

        if (!navigator.onLine) {
            try { await offlineQueue.enqueue('custom-tags-sync', { ts: Date.now() }); } catch (_e) {}
            return null;
        }

        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=custom_tags_sync`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({ tags: localTags, mode }),
            });

            if (!res.ok) {
                if (res.status === 401) this.clearCredentials();
                return null;
            }

            const data = await res.json();
            return Array.isArray(data.tags) ? data.tags : null;
        } catch (err) {
            if (err instanceof TypeError) {
                try { await offlineQueue.enqueue('custom-tags-sync', { ts: Date.now() }); } catch (_e) {}
            }
            return null;
        }
    }

    /**
     * Wire the offline queue to auto-replay setlist + favourite syncs
     * when connectivity returns. The page registered a Background Sync
     * tag on enqueue; the service worker echoes a QUEUE_DRAIN message
     * here, and `drainLatest` pushes the CURRENT local state rather
     * than the stale payload that was queued at failure time (#338).
     *
     * Called once from the module init; safe to call again on login
     * since the queue triggers are idempotent.
     */
    bindOfflineDrains() {
        if (this._offlineDrainsBound) return;
        this._offlineDrainsBound = true;

        offlineQueue.bindAutoDrainLatest('setlists-sync', async () => {
            if (!this.isLoggedIn() || !this.app.setList) return false;
            /* Don't replay a (possibly cross-session-persisted) replace until
               this session's merge reconcile has hydrated the cache — else the
               drain could push a pre-reconcile list and wipe the server
               (review #1). Keep the marker queued; the post-reconcile flush
               already pushes the latest state. */
            if (!this.app.setList._syncReady) return false;
            /* Replay offline edits authoritatively (deletions included). */
            const res = await this.syncSetlists(this.app.setList.getAll(), 'replace');
            /* #1649 — was `saveAll(merged, {sync:false})`, which OVERWROTE the
               local cache with the server's list. When the server had silently
               truncated that list, the drain then destroyed the local copy too
               and the loss became total. _absorbSetlistSync() unions instead,
               so local-only entries survive, and it advances the watermark
               only because it really did keep the data. */
            if (this._absorbSetlistSync(res)) {
                const n = this.app.setList.getAll().length;
                this.app.showToast?.(`Synced ${n} setlist${n === 1 ? '' : 's'}`, 'success', 2000);
                return true;
            }
            return false;
        });

        offlineQueue.bindAutoDrainLatest('favorites-sync', async () => {
            if (!this.isLoggedIn() || !this.app.favorites) return false;
            if (!this.app.favorites._syncReady) return false; /* see setlists drain (review #1) */
            const local = this.app.favorites.getAll() || [];
            const payload = local.map(f => ({ id: f.id, tags: f.tags || [] }));
            const res = await this.syncFavorites(payload, 'replace');
            /* #1649 — see the setlists drain above; the absorb helper merges
               rather than overwrites and owns the watermark advance. */
            return this._absorbFavoritesSync(res);
        });

        offlineQueue.bindAutoDrainLatest('custom-tags-sync', async () => {
            if (!this.isLoggedIn() || !this.app.favorites) return false;
            /* MERGE, not replace: custom tags are add-only today (no removal
               UI), so union is correct and needs no _syncReady gate — it can
               never wipe another device's tags (review #4). */
            const merged = await this.syncCustomTags(this.app.favorites.getCustomTags(), 'merge');
            if (merged && Array.isArray(merged)) {
                this.app.favorites.saveCustomTags(merged);
                return true;
            }
            return false;
        });

        /* First sign-in of the session (email login, magic link, OR a
           cross-subdomain bridged token that never went through
           _onLoginSuccess) → one-time DB-first backfill of every user-data
           store. The router re-dispatches EVT_AUTH_CHANGED on each page
           load with the current state, so the first loggedIn one fires this;
           the per-session guard inside triggerUserDataSync makes repeats a
           no-op. */
        document.addEventListener(EVT_AUTH_CHANGED, (e) => {
            if (e?.detail?.loggedIn) this.triggerUserDataSync();
        });

        /* On reconnect, re-run the reconcile so a session that began OFFLINE
           (the merges returned null → _syncReady never armed) un-blocks
           promptly instead of waiting for the next SPA navigation. Idempotent
           + guarded by _userDataSynced/_userDataSyncing (review: offline
           first-login deferral). */
        window.addEventListener('online', () => this.triggerUserDataSync());

        /* #1649 — re-reconcile after a long background sleep.
         *
         * ELI5: if the app has been sitting in a background tab for hours,
         * what it thinks you own may be badly out of date — so when you come
         * back, check with the server before pushing anything.
         *
         * The once-per-session latch (_userDataSynced) assumes a "session" is
         * short. On a PWA it isn't: the tab is backgrounded, not closed, and
         * can be resumed days later. Meanwhile the user may have added set
         * lists on their phone. The stale tab's first edit fires an
         * authoritative 'replace' built from a days-old cache — and while the
         * server-side `since` watermark now stops that deleting the newer
         * rows, the RIGHT behaviour is to pull them in rather than merely
         * avoid destroying them.
         *
         * So: on becoming visible more than 30 minutes after the last
         * successful reconcile, clear the latch and reconcile again (MERGE —
         * additive, never deletes). _syncReady is deliberately NOT disarmed:
         * that flag exists to block 'replace' until a reconcile has EVER run,
         * and re-arming it here would silently drop a legitimate edit made in
         * the window before the new reconcile lands. */
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') return;
            if (!this._userDataSynced) return;   /* not latched — nothing to refresh */
            const RESYNC_AFTER_MS = 30 * 60 * 1000;
            if (Date.now() - (this._userDataSyncedAt || 0) <= RESYNC_AFTER_MS) return;
            this._userDataSynced = false;
            this.triggerUserDataSync();
        });
    }

    /**
     * Union a local favourites array (rich objects with title/songbook/
     * number/addedAt) with the server's {id, tags} list for a first-login
     * MERGE reconcile. Both sides are kept: server-listed favourites take
     * local metadata where available, and any LOCAL-ONLY favourite (e.g.
     * added during the reconcile window, not yet on the server) is appended
     * so it isn't lost (review #3). Tags are the UNION of both sides so
     * neither device's tags are dropped (review #3 / #7).
     *
     * @param {Array} local   Current local favourite objects
     * @param {Array<{id:string,tags:string[]}>} server  Server list
     * @returns {Array} Rebuilt favourite objects
     */
    _mergeFavorites(local, server) {
        const byIdLocal = new Map((local || []).map(f => [f.id, f]));
        const seen = new Set();
        const result = (server || []).map(s => {
            seen.add(s.id);
            const l = byIdLocal.get(s.id);
            const tags = [...new Set([
                ...(Array.isArray(s.tags) ? s.tags : []),
                ...((l && Array.isArray(l.tags)) ? l.tags : []),
            ])];
            return {
                id:       s.id,
                title:    l?.title || '',
                songbook: l?.songbook || '',
                number:   l?.number || 0,
                tags,
                addedAt:  l?.addedAt || new Date().toISOString(),
            };
        });
        /* Local-only favourites the server doesn't know yet (in-flight adds). */
        for (const l of (local || [])) {
            if (!l || !l.id || seen.has(l.id)) continue;
            result.push({
                id:       l.id,
                title:    l.title || '',
                songbook: l.songbook || '',
                number:   l.number || 0,
                tags:     Array.isArray(l.tags) ? l.tags : [],
                addedAt:  l.addedAt || new Date().toISOString(),
            });
        }
        return result;
    }

    /**
     * Fetch all setlists from the server (read-only, no merge).
     * @returns {Promise<Array|null>}
     */
    async fetchSetlists() {
        if (!this.isLoggedIn()) return null;

        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=user_setlists`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
            });

            if (!res.ok) return null;
            const data = await res.json();
            return data.setlists || null;
        } catch {
            return null;
        }
    }

    /**
     * Update the signed-in user's display name and email.
     * @param {{ displayName: string, email: string }} fields
     * @returns {Promise<{ success: boolean, user?: object, error?: string }>}
     */
    async updateProfile({ displayName, email }) {
        if (!this.isLoggedIn()) return { success: false, error: 'Not signed in.' };
        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_update_profile`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({ display_name: displayName, email }),
            });
            const data = await res.json();
            if (!res.ok) return { success: false, error: data.error || 'Could not save profile.' };

            /* Update the cached user so the header + account card re-render
               immediately with the new display name. */
            if (data.user) {
                localStorage.setItem(STORAGE_AUTH_USER, JSON.stringify(data.user));
                this._broadcastAuthChanged();
            }
            return { success: true, user: data.user };
        } catch {
            return { success: false, error: 'Network error. Please try again.' };
        }
    }

    /**
     * Change the signed-in user's username. Requires the current
     * password as a confirmation step. Updates the cached user on
     * success so the header re-renders the new handle.
     *
     * @param {{ newUsername: string, currentPassword: string }} fields
     * @returns {Promise<{ success: boolean, user?: object, error?: string }>}
     */
    async changeUsername({ newUsername, currentPassword }) {
        if (!this.isLoggedIn()) return { success: false, error: 'Not signed in.' };
        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_change_username`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({
                    new_username: newUsername,
                    current_password: currentPassword,
                }),
            });
            const data = await res.json();
            if (!res.ok) return { success: false, error: data.error || 'Could not change username.' };

            if (data.user) {
                localStorage.setItem(STORAGE_AUTH_USER, JSON.stringify(data.user));
                this._broadcastAuthChanged();
            }
            return { success: true, user: data.user };
        } catch {
            return { success: false, error: 'Network error. Please try again.' };
        }
    }

    /**
     * Change the signed-in user's password.
     * @param {{ currentPassword: string, newPassword: string }} fields
     * @returns {Promise<{ success: boolean, error?: string }>}
     */
    async changePassword({ currentPassword, newPassword }) {
        if (!this.isLoggedIn()) return { success: false, error: 'Not signed in.' };
        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_change_password`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({
                    current_password: currentPassword,
                    new_password: newPassword,
                }),
            });
            const data = await res.json();
            if (!res.ok) return { success: false, error: data.error || 'Could not change password.' };
            return { success: true };
        } catch {
            return { success: false, error: 'Network error. Please try again.' };
        }
    }

    /* =====================================================================
     * EMAIL-LINK URL PARAMS (#898 follow-up)
     * The transactional emails carry click-throughs of the form
     *   /login?token=<48hex>          (magic-link sign-in)
     *   /login?reset_token=<48hex>    (forgot-password reset)
     *   /login?verify_token=<48hex>   (post-signup verification)
     * Without a client-side handler these links just land on the home
     * page and the user has to copy/paste manually. This consumer
     * detects each variant on boot, dispatches the matching API call,
     * surfaces a result toast, and strips the param from the URL via
     * history.replaceState so a refresh won't re-fire it.
     * ===================================================================== */

    /**
     * Inspect the current URL, act on any auth-link query param, and
     * scrub it from the address bar. Best-effort — silent on no match,
     * never throws so a malformed URL can't take the page down.
     */
    async _consumeAuthUrlParams() {
        let params;
        try {
            params = new URL(window.location.href).searchParams;
        } catch (_e) {
            return;
        }
        const magicToken  = (params.get('token')         || '').trim();
        const resetToken  = (params.get('reset_token')   || '').trim();
        const verifyToken = (params.get('verify_token')  || '').trim();
        if (!magicToken && !resetToken && !verifyToken) return;

        /* Drop the consumed param(s) from the address bar before any
           network call so a back-button or refresh doesn't replay it
           (single-use tokens would already be Used by then; keeping
           the param visible would only cause a confusing
           "invalid token" toast on the second hit). */
        const stripped = new URL(window.location.href);
        ['token', 'reset_token', 'verify_token'].forEach(p => stripped.searchParams.delete(p));
        try {
            window.history.replaceState({}, '', stripped.toString());
        } catch (_e) { /* ignore — replaceState is fine on https origins */ }

        if (verifyToken) {
            await this._handleVerifyEmailToken(verifyToken);
            return;
        }
        if (resetToken) {
            this._handleResetTokenLink(resetToken);
            return;
        }
        if (magicToken) {
            await this._handleMagicLinkToken(magicToken);
            return;
        }
    }

    async _handleVerifyEmailToken(token) {
        try {
            const r = await apiFetch(
                `${this.app.config.apiUrl}?action=auth_verify_email&token=${encodeURIComponent(token)}`,
                { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );
            const data = await r.json().catch(() => ({}));
            if (r.ok && data.ok) {
                this.app.showToast?.('Email address verified. Thanks!', 'success', 4000);
            } else {
                this.app.showToast?.(
                    data.error || 'Verification link is invalid or has expired.',
                    'warning', 5000
                );
            }
        } catch {
            this.app.showToast?.('Could not verify email — network error.', 'warning', 4000);
        }
    }

    async _handleMagicLinkToken(token) {
        try {
            const r = await apiFetch(`${this.app.config.apiUrl}?action=auth_email_login_verify`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ token }),
            });
            const data = await r.json().catch(() => ({}));
            if (r.ok && data.token && data.user) {
                this.saveCredentials(data.token, data.user);
                this._updateHeaderState();
                this.app.setList?.renderSyncBar();
                this.app.showToast?.('Signed in! Syncing setlists...', 'success', 3000);
                this.triggerSetlistSync();
            } else {
                this.app.showToast?.(
                    data.error || 'Sign-in link is invalid or has expired. Please request a new one.',
                    'warning', 5000
                );
            }
        } catch {
            this.app.showToast?.('Could not complete sign-in — network error.', 'warning', 4000);
        }
    }

    /**
     * Reset-password links open the auth modal in forgot-password
     * mode with the token pre-filled, so the user only has to type
     * their new password. Mirrors the flow that runs after a
     * successful "Send Reset Token" submission.
     */
    _handleResetTokenLink(token) {
        this.showAuthModal('login');
        /* showAuthModal builds the DOM synchronously; the form
           elements are present immediately. Defer one tick so the
           Bootstrap modal's show transition doesn't fight the
           focus call. */
        setTimeout(() => {
            const modal = document.getElementById('user-auth-modal');
            if (!modal) return;
            modal.querySelector('#auth-form')?.style.setProperty('display', 'none');
            modal.querySelector('#auth-toggle')?.style.setProperty('display', 'none');
            modal.querySelector('#auth-forgot-link-wrapper')?.style.setProperty('display', 'none');
            modal.querySelector('#auth-email-toggle-wrapper')?.style.setProperty('display', 'none');
            modal.querySelector('#auth-forgot-section')?.classList.remove('d-none');
            modal.querySelector('#auth-forgot-form')?.classList.add('d-none');
            const resetForm = modal.querySelector('#auth-reset-form');
            if (resetForm) {
                resetForm.classList.remove('d-none');
                const tokenInput = modal.querySelector('#auth-reset-token');
                if (tokenInput) tokenInput.value = token;
                modal.querySelector('#auth-reset-password')?.focus();
            }
        }, 50);
    }

    /* =====================================================================
     * HEADER USER MENU — Toggle logged-in / logged-out state
     * ===================================================================== */

    /**
     * Initialise the header user dropdown menu.
     * Binds buttons and updates visibility based on auth state.
     * Call once on app init, and again after login/logout.
     */
    initUserMenu() {
        this._updateHeaderState();

        /* #898 follow-up — auto-consume any /login?{token,reset_token,
           verify_token}=... params landed via a transactional email.
           Fire-and-forget so the rest of the menu wires up regardless
           of whether the click-through has already been used. */
        this._consumeAuthUrlParams().catch(() => { /* non-fatal */ });

        /* Refresh cached user info + bump the server-side sliding expiry
           once per boot (#390). Fire-and-forget: never blocks the UI, and
           verify() itself keeps the token on network errors so an offline
           launch doesn't sign the user out. */
        if (this.isLoggedIn()) {
            this.verify().catch(() => { /* non-fatal */ });
        }

        /* Sign In button */
        document.getElementById('header-signin-btn')?.addEventListener('click', () => {
            this.showAuthModal('login');
        });

        /* Create Account button */
        document.getElementById('header-register-btn')?.addEventListener('click', () => {
            this.showAuthModal('register');
        });

        /* Sync Set Lists button */
        document.getElementById('header-sync-btn')?.addEventListener('click', async () => {
            this.app.showToast('Syncing set lists...', 'info', 2000);
            await this.triggerSetlistSync();
        });

        /* Sign Out button */
        document.getElementById('header-signout-btn')?.addEventListener('click', async () => {
            await this.logout();
            this._updateHeaderState();
            /* Re-render setlist sync bar if on that page */
            this.app.setList?.renderSyncBar();
            this.app.showToast('Signed out', 'info', 2000);
        });
    }

    /**
     * Update the header dropdowns to reflect current auth state.
     *
     * The avatar menu holds only account items (always on when signed in).
     * Curator and Administration live on the iHymns (logo) dropdown and
     * are toggled per-entitlement; each section's label + divider collapse
     * with its items so users without the relevant rights see nothing.
     */
    _updateHeaderState() {
        const loggedIn = this.isLoggedIn();
        const user = this.getUser();
        const role = user?.role || null;

        /* Guest items (sign in / register) */
        ['header-user-guest', 'header-user-register-li'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('d-none', loggedIn);
        });

        /* Always-on items for signed-in users (Account section) */
        [
            'header-user-name', 'header-user-role-li', 'header-user-divider',
            'header-user-settings-li', 'header-user-setlists-li', 'header-user-sync-li',
            'header-user-divider2', 'header-user-signout-li',
        ].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('d-none', !loggedIn);
        });

        /* Update user info text */
        if (loggedIn && user) {
            const nameEl = document.getElementById('header-user-display-name');
            const roleEl = document.getElementById('header-user-role-text');
            if (nameEl) nameEl.textContent = user.display_name || user.username || '';
            if (roleEl) roleEl.textContent = this._roleLabel(user.role || 'user');
        }

        /* Single "Manage" entry in the iHymns dropdown. Visible to any
           signed-in user holding at least one curator or administration
           entitlement; the landing page (/manage/) then reveals
           per-card links based on the same entitlements. */
        const manageEntitlements = [
            'edit_songs', 'review_song_requests', 'verify_songs',
            'view_admin_dashboard', 'view_users', 'manage_user_groups',
            'manage_organisations', 'manage_songbooks',
            'manage_entitlements', 'view_analytics',
            'run_db_install', 'drop_legacy_tables',
        ];
        const canManage = loggedIn && manageEntitlements.some(
            ent => userHasEntitlement(ent, role)
        );
        /* Manage now sits in the Operations group with Statistics
           (no longer needs its own divider) per the dropdown reorder
           in #582. Only the <li> itself toggles. */
        const manageLi = document.getElementById('nav-manage-li');
        if (manageLi) manageLi.classList.toggle('d-none', !canManage);

        /* Update header user button: signed-in users get a Gravatar
           (or configured-resolver) avatar; signed-out users keep the
           generic Font Awesome icon (#581). The button slot is always
           the same `#header-user-icon` element — we just swap the
           tag (<i> ↔ <img>) so styling carried by `.btn-header-icon`
           stays applied. Avatar URL computation is async (SHA-256 via
           SubtleCrypto), so we fire-and-forget; if it resolves while
           the user is still signed in, swap; otherwise no-op. */
        const slot = document.getElementById('header-user-icon');
        if (!slot) return;
        if (loggedIn && user) {
            /* #616 — honour the per-user avatar-service preference. NULL
               or unrecognised string falls through to the default
               (Gravatar) inside _avatarUrl. */
            this._avatarUrl(user.email || user.username, 64, user.avatar_service).then(url => {
                /* User may have signed out by the time the hash resolves. */
                if (!this.isLoggedIn()) return;
                const current = document.getElementById('header-user-icon');
                if (!current) return;
                if (current.tagName === 'IMG' && current.getAttribute('src') === url) return;
                const img = document.createElement('img');
                img.id = 'header-user-icon';
                img.src = url;
                img.alt = '';
                /* 24px to match the optical weight of the FA / BI glyphs
                   in the adjacent header buttons (#646). */
                img.width = 24;
                img.height = 24;
                img.className = 'rounded-circle header-user-avatar';
                img.loading = 'lazy';
                img.referrerPolicy = 'no-referrer';
                img.onerror = () => {
                    img.onerror = null;
                    img.src = '/assets/avatar-fallback.svg';
                };
                current.replaceWith(img);
            });
        } else if (slot.tagName !== 'I' || slot.classList.contains('header-user-avatar')) {
            /* Restore the generic icon for signed-out users. */
            const i = document.createElement('i');
            i.id = 'header-user-icon';
            i.className = 'fa-solid fa-user';
            i.setAttribute('aria-hidden', 'true');
            slot.replaceWith(i);
        }
    }

    /**
     * Build an avatar URL. Mirrors PHP `userAvatarUrl()` so signed-in
     * users see the same avatar in both surfaces. Uses SHA-256 via
     * SubtleCrypto (Gravatar accepts both MD5 (legacy) and SHA-256
     * (since 2022) — SHA-256 lets us avoid shipping a hand-rolled
     * hash).
     *
     * @param {string} email
     * @param {number} size
     * @param {string|null} userOverride Per-user resolver preference
     *        (#616). NULL = use Gravatar (the default for the JS path).
     *        One of 'gravatar' | 'libravatar' | 'dicebear' | 'none'.
     * @returns {Promise<string>}
     */
    async _avatarUrl(email, size = 64, userOverride = null) {
        const e = (email || '').trim().toLowerCase();
        if (!e || !crypto?.subtle) return '/assets/avatar-fallback.svg';
        const service = (typeof userOverride === 'string' ? userOverride.trim().toLowerCase() : '') || 'gravatar';
        if (service === 'none') return '/assets/avatar-fallback.svg';

        const buf = new TextEncoder().encode(e);
        const hash = await crypto.subtle.digest('SHA-256', buf);
        const hex = Array.from(new Uint8Array(hash))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');

        if (service === 'libravatar') {
            return `https://seccdn.libravatar.org/avatar/${hex}?s=${size}&d=identicon&r=g`;
        }
        if (service === 'dicebear') {
            return `https://api.dicebear.com/7.x/identicon/svg?seed=${encodeURIComponent(hex)}&size=${size}`;
        }
        /* gravatar (default) — and any unknown string */
        return `https://www.gravatar.com/avatar/${hex}?s=${size}&d=identicon&r=g`;
    }

    /* Backwards-compatible alias for the old name (#616). Kept so any
       external caller still using `_gravatarUrl(email, size)` keeps
       working — it just calls through to the new resolver with no
       per-user override. */
    _gravatarUrl(email, size = 64) {
        return this._avatarUrl(email, size, null);
    }

    /**
     * Human-readable label for a role.
     * @param {string} role
     * @returns {string}
     */
    _roleLabel(role) {
        const labels = {
            'global_admin': 'Global Admin',
            'admin': 'Admin',
            'editor': 'Curator / Editor',
            'user': 'User',
        };
        return labels[role] || role;
    }

    /* =====================================================================
     * PASSWORD RESET — Forgot password flow
     * ===================================================================== */

    /**
     * Request a password reset token.
     * @param {string} usernameOrEmail
     * @returns {Promise<{ success: boolean, message?: string, token?: string, error?: string }>}
     */
    async forgotPassword(usernameOrEmail) {
        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_forgot_password`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ username: usernameOrEmail }),
            });

            const data = await res.json();
            if (!res.ok) return { success: false, error: data.error || 'Request failed.' };

            return { success: true, message: data.message, token: data._dev_token };
        } catch {
            return { success: false, error: 'Network error. Please try again.' };
        }
    }

    /**
     * Reset password using a token.
     * @param {string} token
     * @param {string} newPassword
     * @returns {Promise<{ success: boolean, message?: string, error?: string }>}
     */
    async resetPasswordWithToken(token, newPassword) {
        try {
            const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_reset_password`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ token, password: newPassword }),
            });

            const data = await res.json();
            if (!res.ok) return { success: false, error: data.error || 'Reset failed.' };

            return { success: true, message: data.message };
        } catch {
            return { success: false, error: 'Network error. Please try again.' };
        }
    }

    /* =====================================================================
     * UI — Login/Register Modal
     * ===================================================================== */

    /**
     * Show the login/register modal.
     * @param {string} mode Initial mode: 'login' or 'register'
     */
    showAuthModal(mode = 'login') {
        document.getElementById('user-auth-modal')?.remove();

        const modal = document.createElement('div');
        modal.id = 'user-auth-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'user-auth-modal-label');
        modal.setAttribute('aria-hidden', 'true');

        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="user-auth-modal-label">
                            <i class="fa-solid fa-user me-2" aria-hidden="true"></i>
                            <span id="auth-modal-title">${mode === 'register' ? 'Create Account' : 'Sign In'}</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="auth-error" class="alert alert-danger d-none" role="alert"></div>

                        <!-- Sign in with Apple (#1470 W1 backend / #1479 W2 front end).
                             DORMANT by default: hidden until _ensureAppStatus()
                             confirms app_status.appleWebLoginEnabled === true AND a
                             Services ID is configured (see the .then() below). Uses
                             the bundled FontAwesome Apple glyph in lieu of Apple's
                             official logo asset (not vendored in this repo) — an
                             HIG-adjacent, not pixel-exact, treatment. -->
                        <div id="auth-apple-section" class="d-none mb-3">
                            <button type="button" class="btn btn-dark w-100" id="auth-apple-signin-btn">
                                <i class="fa-brands fa-apple me-2" aria-hidden="true"></i>
                                <span id="auth-apple-signin-text">Sign in with Apple</span>
                                <span id="auth-apple-signin-spinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                            </button>
                            <div class="text-center text-muted small mt-2">or</div>
                        </div>

                        <form id="auth-form" novalidate>
                            <div class="mb-3" id="auth-display-name-group" style="display:${mode === 'register' ? '' : 'none'}">
                                <label for="auth-display-name" class="form-label">Display Name</label>
                                <input type="text" class="form-control" id="auth-display-name"
                                       placeholder="Your name" autocomplete="name">
                            </div>
                            <div class="mb-3">
                                <label for="auth-username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="auth-username"
                                       placeholder="Username" autocomplete="username" required>
                            </div>
                            <!-- Email is captured on signup so the new account can
                                 receive a verification email when an email provider
                                 is configured (#898). Optional today — accounts
                                 created without one stay EmailVerified=0 and can
                                 add an address later via account settings. -->
                            <div class="mb-3" id="auth-email-group" style="display:${mode === 'register' ? '' : 'none'}">
                                <label for="auth-email-register" class="form-label">
                                    Email <small class="text-secondary">(optional, for password resets)</small>
                                </label>
                                <input type="email" class="form-control" id="auth-email-register"
                                       placeholder="you@example.com" autocomplete="email">
                            </div>
                            <div class="mb-3">
                                <label for="auth-password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="auth-password"
                                       placeholder="Password (min 8 characters)"
                                       autocomplete="${mode === 'register' ? 'new-password' : 'current-password'}" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="auth-submit-btn">
                                <span id="auth-submit-text">${mode === 'register' ? 'Create Account' : 'Sign In'}</span>
                                <span id="auth-submit-spinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                            </button>
                        </form>

                        <div class="text-center mt-3">
                            <small id="auth-toggle" role="button" class="text-primary" style="cursor:pointer">
                                ${mode === 'register'
                                    ? 'Already have an account? <strong>Sign in</strong>'
                                    : 'No account? <strong>Create one</strong>'}
                            </small>
                        </div>
                        <div class="text-center mt-2" id="auth-forgot-link-wrapper" style="display:${mode === 'register' ? 'none' : ''}">
                            <small id="auth-forgot-link" role="button" class="text-muted" style="cursor:pointer">
                                Forgot password?
                            </small>
                        </div>
                        <!-- Legacy "Sign in with email instead" link kept for the
                             register-flow fallback path; hidden for login because
                             magic-link email is now the primary sign-in path (#395). -->
                        <div class="text-center mt-2" id="auth-email-toggle-wrapper" style="display:none">
                            <small id="auth-email-toggle" role="button" class="text-primary" style="cursor:pointer">
                                <i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Sign in with email instead
                            </small>
                        </div>

                        <!-- Email Login Section (hidden by default) -->
                        <div id="auth-email-section" class="d-none mt-3">
                            <hr>
                            <h6 class="mb-2">Sign in with Email</h6>
                            <div id="auth-email-error" class="alert alert-danger d-none py-2" role="alert"></div>
                            <div id="auth-email-success" class="alert alert-success d-none py-2" role="alert"></div>
                            <form id="auth-email-form" novalidate>
                                <div class="mb-2" id="auth-email-step1">
                                    <label for="auth-email-input" class="form-label small">Email Address</label>
                                    <input type="email" class="form-control form-control-sm" id="auth-email-input"
                                           placeholder="Enter your email address" autocomplete="email" required>
                                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-2" id="auth-email-submit">
                                        <span id="auth-email-submit-text">Send Login Code</span>
                                        <span id="auth-email-submit-spinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                                    </button>
                                </div>
                            </form>
                            <div id="auth-email-code-section" class="d-none">
                                <form id="auth-email-code-form" novalidate>
                                    <div class="mb-2">
                                        <label for="auth-email-code-input" class="form-label small">Enter the 6-digit code sent to your email</label>
                                        <input type="text" class="form-control form-control-sm text-center" id="auth-email-code-input"
                                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                                               autocomplete="one-time-code" required>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary w-100" id="auth-email-code-submit">
                                        <span id="auth-email-code-submit-text">Verify Code</span>
                                        <span id="auth-email-code-spinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                                    </button>
                                </form>
                            </div>
                            <div class="text-center mt-2">
                                <small id="auth-back-to-password" role="button" class="text-primary" style="cursor:pointer">
                                    <i class="fa-solid fa-key me-1" aria-hidden="true"></i>Sign in with a password instead
                                </small>
                            </div>
                        </div>

                        <!-- Forgot Password Form (hidden by default) -->
                        <div id="auth-forgot-section" class="d-none mt-3">
                            <hr>
                            <h6 class="mb-2">Reset Password</h6>
                            <div id="auth-forgot-error" class="alert alert-danger d-none py-2" role="alert"></div>
                            <div id="auth-forgot-success" class="alert alert-success d-none py-2" role="alert"></div>
                            <form id="auth-forgot-form" novalidate>
                                <div class="mb-2" id="auth-forgot-step1">
                                    <label for="auth-forgot-username" class="form-label small">Username or Email</label>
                                    <input type="text" class="form-control form-control-sm" id="auth-forgot-username"
                                           placeholder="Enter your username or email" required>
                                    <button type="submit" class="btn btn-sm btn-outline-primary w-100 mt-2" id="auth-forgot-submit">
                                        Send Reset Token
                                    </button>
                                </div>
                            </form>
                            <form id="auth-reset-form" class="d-none" novalidate>
                                <div class="mb-2">
                                    <label for="auth-reset-token" class="form-label small">Reset Token</label>
                                    <input type="text" class="form-control form-control-sm" id="auth-reset-token"
                                           placeholder="Paste the reset token" required>
                                </div>
                                <div class="mb-2">
                                    <label for="auth-reset-password" class="form-label small">New Password</label>
                                    <input type="password" class="form-control form-control-sm" id="auth-reset-password"
                                           placeholder="Min 8 characters" minlength="8" autocomplete="new-password" required>
                                </div>
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    Reset Password
                                </button>
                            </form>
                            <div class="text-center mt-2">
                                <small id="auth-back-to-login" role="button" class="text-primary" style="cursor:pointer">
                                    Back to Sign In
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);

        let currentMode = mode;

        /* Promote magic-link email as the primary sign-in path (#395).
           For the login flow we hide the password form and reveal the
           email section on open. Users can click "Sign in with a password
           instead" to fall back to the username/password form.

           BUT only when the deployment actually has an email service
           configured (#766). On a fresh install with no SMTP set up,
           emailLoginEnabled comes back false from /api?action=app_status
           and we leave the password form visible. The "Sign in with
           email instead" toggle is removed entirely so the user isn't
           offered a path that can't work. We resolve the flag
           asynchronously and apply the layout once it lands. The
           modal opens in the password-form default until then; the
           snap from "no email path" to "email path promoted" only
           happens on the very first open and only if the flag flips
           — subsequent opens hit the cached value with no flicker. */
        if (mode === 'login') {
            this._ensureAppStatus().then((status) => {
                const emailEnabled = status?.emailLoginEnabled !== false;
                if (emailEnabled) {
                    modal.querySelector('#auth-form').style.display = 'none';
                    modal.querySelector('#auth-email-section')?.classList.remove('d-none');
                    modal.querySelector('#auth-toggle').style.display = 'none';
                    modal.querySelector('#auth-forgot-link-wrapper').style.display = 'none';
                } else {
                    /* Email service unconfigured — strip every entry
                       point that would lead the user there. */
                    const emailSection = modal.querySelector('#auth-email-section');
                    if (emailSection) emailSection.remove();
                    const emailToggle = modal.querySelector('#auth-email-toggle-wrapper');
                    if (emailToggle) emailToggle.remove();
                    /* Keep the password form, the registration toggle
                       and the forgot-password link visible — those all
                       work without email (the forgot flow uses an
                       admin-issued reset token, not email). */
                }
            });
        }

        /* Sign in with Apple (#1479 W2) — reveal the button in BOTH login
           and register modes (auth_apple resolves to a sign-in OR a fresh
           account with no separate "register with Apple" flow needed).
           _ensureAppStatus() is memoized so this never triggers a second
           network fetch alongside the email-login check above. */
        this._ensureAppStatus().then((status) => {
            const appleEnabled = status?.appleWebLoginEnabled === true && !!status?.appleSiwaServicesId;
            modal.querySelector('#auth-apple-section')?.classList.toggle('d-none', !appleEnabled);
        });

        modal.querySelector('#auth-apple-signin-btn')?.addEventListener('click', async () => {
            const btn = modal.querySelector('#auth-apple-signin-btn');
            const spinner = modal.querySelector('#auth-apple-signin-spinner');
            const errorEl = modal.querySelector('#auth-error');

            errorEl?.classList.add('d-none');
            btn.disabled = true;
            spinner?.classList.remove('d-none');

            const result = await this.signInWithApple({ link: false });

            spinner?.classList.add('d-none');
            btn.disabled = false;

            if (result.success) {
                bsModal.hide();
                this._updateHeaderState();
                this.app.setList?.renderSyncBar();
                /* One-time guidance on a brand-new account (§5 of the SIWA-web
                   strategy) — never an interactive "an account with this email
                   already exists" prompt (that would be an enumeration
                   oracle). The user can link a pre-existing password/email
                   account to Apple afterwards from Settings. */
                if (result.flow === 'created') {
                    this.app.showToast(
                        'Account created with Apple! Already had an iHymns account? Sign in and link Apple from Settings.',
                        'info',
                        6000
                    );
                } else {
                    this.app.showToast('Signed in with Apple! Syncing your data...', 'success', 3000);
                }
                this.triggerUserDataSync();
            } else {
                errorEl.textContent = result.error || 'Sign in with Apple failed.';
                errorEl.classList.remove('d-none');
            }
        });

        /* Toggle between login and register */
        modal.querySelector('#auth-toggle')?.addEventListener('click', () => {
            currentMode = currentMode === 'login' ? 'register' : 'login';
            const isReg = currentMode === 'register';
            modal.querySelector('#auth-modal-title').textContent = isReg ? 'Create Account' : 'Sign In';
            modal.querySelector('#auth-submit-text').textContent = isReg ? 'Create Account' : 'Sign In';
            modal.querySelector('#auth-display-name-group').style.display = isReg ? '' : 'none';
            const emailGroup = modal.querySelector('#auth-email-group');
            if (emailGroup) emailGroup.style.display = isReg ? '' : 'none';
            /* #1150/#1151 — keep the shared password field's autocomplete
               purpose (WHATWG "new-password" vs "current-password") in
               step with the mode it's actually being used for, so a
               password manager offers to GENERATE a strong password when
               creating an account instead of offering a saved login. */
            const pwField = modal.querySelector('#auth-password');
            if (pwField) pwField.setAttribute('autocomplete', isReg ? 'new-password' : 'current-password');
            modal.querySelector('#auth-forgot-link-wrapper').style.display = isReg ? 'none' : '';
            modal.querySelector('#auth-toggle').innerHTML = isReg
                ? 'Already have an account? <strong>Sign in</strong>'
                : 'No account? <strong>Create one</strong>';
            modal.querySelector('#auth-email-toggle-wrapper').style.display = isReg ? 'none' : '';
            modal.querySelector('#auth-error')?.classList.add('d-none');
            modal.querySelector('#auth-forgot-section')?.classList.add('d-none');
            modal.querySelector('#auth-email-section')?.classList.add('d-none');
        });

        /* Forgot password link */
        modal.querySelector('#auth-forgot-link')?.addEventListener('click', () => {
            modal.querySelector('#auth-form').style.display = 'none';
            modal.querySelector('#auth-toggle').style.display = 'none';
            modal.querySelector('#auth-forgot-link-wrapper').style.display = 'none';
            modal.querySelector('#auth-email-toggle-wrapper').style.display = 'none';
            modal.querySelector('#auth-forgot-section')?.classList.remove('d-none');
            modal.querySelector('#auth-modal-title').textContent = 'Reset Password';
        });

        /* Back to Sign In from forgot password */
        modal.querySelector('#auth-back-to-login')?.addEventListener('click', () => {
            modal.querySelector('#auth-form').style.display = '';
            modal.querySelector('#auth-toggle').style.display = '';
            modal.querySelector('#auth-forgot-link-wrapper').style.display = '';
            modal.querySelector('#auth-email-toggle-wrapper').style.display = '';
            modal.querySelector('#auth-forgot-section')?.classList.add('d-none');
            modal.querySelector('#auth-modal-title').textContent = 'Sign In';
            /* Reset forgot password state */
            modal.querySelector('#auth-forgot-step1')?.classList.remove('d-none');
            modal.querySelector('#auth-reset-form')?.classList.add('d-none');
            modal.querySelector('#auth-forgot-error')?.classList.add('d-none');
            modal.querySelector('#auth-forgot-success')?.classList.add('d-none');
        });

        /* Email login toggle */
        modal.querySelector('#auth-email-toggle')?.addEventListener('click', () => {
            modal.querySelector('#auth-form').style.display = 'none';
            modal.querySelector('#auth-toggle').style.display = 'none';
            modal.querySelector('#auth-forgot-link-wrapper').style.display = 'none';
            modal.querySelector('#auth-email-toggle-wrapper').style.display = 'none';
            modal.querySelector('#auth-email-section')?.classList.remove('d-none');
            modal.querySelector('#auth-modal-title').textContent = 'Sign in with Email';
        });

        /* Back to password sign-in from email login */
        modal.querySelector('#auth-back-to-password')?.addEventListener('click', () => {
            modal.querySelector('#auth-form').style.display = '';
            modal.querySelector('#auth-toggle').style.display = '';
            modal.querySelector('#auth-forgot-link-wrapper').style.display = '';
            modal.querySelector('#auth-email-toggle-wrapper').style.display = '';
            modal.querySelector('#auth-email-section')?.classList.add('d-none');
            modal.querySelector('#auth-modal-title').textContent = 'Sign In';
            /* Reset email login state */
            modal.querySelector('#auth-email-step1')?.classList.remove('d-none');
            modal.querySelector('#auth-email-code-section')?.classList.add('d-none');
            modal.querySelector('#auth-email-error')?.classList.add('d-none');
            modal.querySelector('#auth-email-success')?.classList.add('d-none');
            modal.querySelector('#auth-email-input').value = '';
            modal.querySelector('#auth-email-code-input').value = '';
        });

        /* Email login — request code */
        let emailLoginAddress = '';
        modal.querySelector('#auth-email-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = modal.querySelector('#auth-email-input')?.value.trim();
            const errorEl = modal.querySelector('#auth-email-error');
            const successEl = modal.querySelector('#auth-email-success');
            const spinner = modal.querySelector('#auth-email-submit-spinner');
            const submitBtn = modal.querySelector('#auth-email-submit');

            if (!email) {
                errorEl.textContent = 'Please enter your email address.';
                errorEl.classList.remove('d-none');
                return;
            }

            errorEl?.classList.add('d-none');
            successEl?.classList.add('d-none');
            spinner?.classList.remove('d-none');
            submitBtn.disabled = true;

            try {
                const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_email_login_request`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ email }),
                });

                const data = await res.json();

                if (!res.ok) {
                    errorEl.textContent = data.error || 'Failed to send login code.';
                    errorEl.classList.remove('d-none');
                } else {
                    emailLoginAddress = email;
                    successEl.textContent = data.message || 'A 6-digit code has been sent to your email.';
                    successEl.classList.remove('d-none');
                    modal.querySelector('#auth-email-step1')?.classList.add('d-none');
                    modal.querySelector('#auth-email-code-section')?.classList.remove('d-none');
                }
            } catch {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('d-none');
            }

            spinner?.classList.add('d-none');
            submitBtn.disabled = false;
        });

        /* Email login — verify code */
        modal.querySelector('#auth-email-code-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const code = modal.querySelector('#auth-email-code-input')?.value.trim();
            const errorEl = modal.querySelector('#auth-email-error');
            const successEl = modal.querySelector('#auth-email-success');
            const spinner = modal.querySelector('#auth-email-code-spinner');
            const submitBtn = modal.querySelector('#auth-email-code-submit');

            if (!code || code.length !== 6) {
                errorEl.textContent = 'Please enter the 6-digit code.';
                errorEl.classList.remove('d-none');
                return;
            }

            errorEl?.classList.add('d-none');
            successEl?.classList.add('d-none');
            spinner?.classList.remove('d-none');
            submitBtn.disabled = true;

            try {
                const res = await apiFetch(`${this.app.config.apiUrl}?action=auth_email_login_verify`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ email: emailLoginAddress, code }),
                });

                const data = await res.json();

                if (!res.ok) {
                    errorEl.textContent = data.error || 'Verification failed.';
                    errorEl.classList.remove('d-none');
                } else {
                    this._onLoginSuccess(data, bsModal);
                }
            } catch {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('d-none');
            }

            spinner?.classList.add('d-none');
            submitBtn.disabled = false;
        });

        /* Forgot password form submission */
        modal.querySelector('#auth-forgot-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = modal.querySelector('#auth-forgot-username')?.value.trim();
            const errorEl = modal.querySelector('#auth-forgot-error');
            const successEl = modal.querySelector('#auth-forgot-success');

            if (!input) {
                errorEl.textContent = 'Please enter your username or email.';
                errorEl.classList.remove('d-none');
                return;
            }

            errorEl?.classList.add('d-none');
            successEl?.classList.add('d-none');

            const result = await this.forgotPassword(input);

            if (result.success) {
                successEl.textContent = result.message || 'Reset token generated.';
                successEl.classList.remove('d-none');

                /* If dev token returned, pre-fill the reset form */
                if (result.token) {
                    modal.querySelector('#auth-reset-token').value = result.token;
                }

                /* Show the reset form */
                modal.querySelector('#auth-forgot-step1')?.classList.add('d-none');
                modal.querySelector('#auth-reset-form')?.classList.remove('d-none');
            } else {
                errorEl.textContent = result.error;
                errorEl.classList.remove('d-none');
            }
        });

        /* Reset password form submission */
        modal.querySelector('#auth-reset-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const token = modal.querySelector('#auth-reset-token')?.value.trim();
            const newPass = modal.querySelector('#auth-reset-password')?.value;
            const errorEl = modal.querySelector('#auth-forgot-error');
            const successEl = modal.querySelector('#auth-forgot-success');

            if (!token || !newPass || newPass.length < 8) {
                errorEl.textContent = 'Token and password (min 8 characters) required.';
                errorEl.classList.remove('d-none');
                return;
            }

            errorEl?.classList.add('d-none');
            successEl?.classList.add('d-none');

            const result = await this.resetPasswordWithToken(token, newPass);

            if (result.success) {
                successEl.textContent = result.message || 'Password reset! Please sign in.';
                successEl.classList.remove('d-none');
                /* After a brief delay, switch back to login */
                setTimeout(() => {
                    modal.querySelector('#auth-back-to-login')?.click();
                }, 2000);
            } else {
                errorEl.textContent = result.error;
                errorEl.classList.remove('d-none');
            }
        });

        /* Form submission */
        modal.querySelector('#auth-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();

            const username = modal.querySelector('#auth-username')?.value.trim();
            const password = modal.querySelector('#auth-password')?.value;
            const displayName = modal.querySelector('#auth-display-name')?.value.trim();
            const email = modal.querySelector('#auth-email-register')?.value.trim() || '';
            const errorEl = modal.querySelector('#auth-error');
            const spinner = modal.querySelector('#auth-submit-spinner');
            const submitBtn = modal.querySelector('#auth-submit-btn');

            if (!username || !password) {
                errorEl.textContent = 'Username and password are required.';
                errorEl.classList.remove('d-none');
                return;
            }

            /* Show loading state */
            spinner?.classList.remove('d-none');
            submitBtn.disabled = true;
            errorEl?.classList.add('d-none');

            let result;
            if (currentMode === 'register') {
                result = await this.register(username, password, displayName || username, email);
            } else {
                result = await this.login(username, password);
            }

            spinner?.classList.add('d-none');
            submitBtn.disabled = false;

            if (result.success) {
                bsModal.hide();
                this._updateHeaderState();
                this.app.setList?.renderSyncBar();
                /* Honest toast (#898). When the user supplied an email
                   AND the verification email actually went out, tell
                   them to check it. Otherwise stay quiet about email
                   so we never claim delivery that didn't happen. */
                let toastMsg;
                if (currentMode === 'register' && result.verificationEmailSent) {
                    toastMsg = 'Account created! Check your email to verify your address. Syncing setlists...';
                } else if (currentMode === 'register') {
                    toastMsg = 'Account created! Syncing setlists...';
                } else {
                    toastMsg = 'Signed in! Syncing setlists...';
                }
                this.app.showToast(toastMsg, 'success', 4000);
                this.triggerUserDataSync();
            } else {
                errorEl.textContent = result.error;
                errorEl.classList.remove('d-none');
            }
        });

        modal.addEventListener('hidden.bs.modal', () => modal.remove());
        bsModal.show();
    }

    /**
     * Handle successful email-based login.
     * Stores credentials, closes modal, updates UI, and triggers sync.
     * @param {object} data  API response with { token, user }
     * @param {object} bsModal  Bootstrap Modal instance to close
     */
    _onLoginSuccess(data, bsModal) {
        this.saveCredentials(data.token, data.user);
        bsModal.hide();
        this._updateHeaderState();
        this.app.setList?.renderSyncBar();
        this.app.showToast('Signed in! Syncing your data...', 'success', 3000);
        this.triggerUserDataSync();
    }

    /**
     * Trigger a setlist sync (login / register / manual "Sync Now").
     * MERGE mode — unions local + server so neither side loses a setlist on
     * a first-login reconcile. (Per-edit auto-sync uses 'replace'.)
     *
     * The server result is unioned with the CURRENT cache (re-read AFTER the
     * network round-trip) rather than the pre-await snapshot, so an edit made
     * during the reconcile window survives instead of being clobbered by the
     * write-back (review #3). The post-await same-user re-check stops a
     * logout/account-switch mid-flight from repopulating the cache with the
     * old account's data (review #4).
     */
    async triggerSetlistSync(silent = false) {
        if (!this.app.setList) return false;

        const uid = this.getUser()?.id;
        const res = await this.syncSetlists(this.app.setList.getAll(), 'merge');
        if (!res) return false;
        if (!this.isLoggedIn() || this.getUser()?.id !== uid) return false;

        /* Union with the CURRENT cache (current local wins for shared ids —
           it carries any in-flight edit), then persist + arm replace. The
           post-reconcile flush pushes this union as an authoritative replace,
           so the in-flight edit reaches the server too. The union + persist +
           watermark advance now live together in _absorbSetlistSync() (#1649),
           so no caller can advance the watermark without keeping the data. */
        if (!this._absorbSetlistSync(res)) return false;
        const final = this.app.setList.getAll();
        this.app.setList._syncReady = true;
        /* Only announce when the user did something deliberate (login, the
           "Sync Now" button, a settings action). The automatic per-boot
           reconcile passes silent=true so a routine reload doesn't pop a
           "Synced N setlists" toast every time — favourites/tags already
           reconcile silently on that path. */
        if (!silent) {
            this.app.showToast(`Synced ${final.length} setlist${final.length !== 1 ? 's' : ''}`, 'success', 2000);
        }
        return true;
    }

    /** Union setlists by id; `current` (local, with in-flight edits) wins. */
    _unionSetlists(current, server) {
        const byId = new Map();
        for (const s of (server || [])) if (s && s.id) byId.set(s.id, s);
        for (const c of (current || [])) if (c && c.id) byId.set(c.id, c);
        return [...byId.values()];
    }

    /**
     * Trigger a favourites reconcile (first login). MERGE mode: pull the
     * server's {id, tags} list, union it with the CURRENT local favourites
     * (preserving local title/songbook/number metadata + UNIONING tags from
     * both sides so neither device's tags are lost), write the union back to
     * the cache, and arm replace so the post-reconcile flush pushes any
     * local-only favourites + in-flight edits to the server. Fixes the prior
     * gap where favourites were push-only and never appeared on a second
     * device (WS-G #1019), the in-flight-edit clobber (review #3), and the
     * cross-device tag loss (review #3 / #7).
     */
    async triggerFavoritesSync() {
        if (!this.app.favorites) return false;

        const uid = this.getUser()?.id;
        const payload = (this.app.favorites.getAll() || []).map(f => ({ id: f.id, tags: f.tags || [] }));
        const res = await this.syncFavorites(payload, 'merge');
        if (!res) return false;
        if (!this.isLoggedIn() || this.getUser()?.id !== uid) return false;

        /* Merge + watermark advance, in that order (#1649). */
        if (!this._absorbFavoritesSync(res)) return false;
        this.app.favorites._syncReady = true;
        return true;
    }

    /**
     * Trigger a custom-tag-pool reconcile (first login). MERGE mode: union
     * the server pool with the local pool and write the union back locally.
     */
    async triggerCustomTagsSync() {
        if (!this.app.favorites?.getCustomTags) return false;

        const local = this.app.favorites.getCustomTags();
        const merged = await this.syncCustomTags(local, 'merge');

        if (merged && Array.isArray(merged)) {
            this.app.favorites.saveCustomTags(merged);
            return true;
        }
        return false;
    }

    /**
     * One-time, once-per-session DB-first backfill of every user-data store
     * (WS-F/G #1018 / #1019). Reconciles setlists, favourites and the
     * custom-tag pool (all MERGE so nothing is lost on the first device
     * hand-off), and pushes the local recently-viewed backlog into
     * tblSongHistory.
     *
     * AWAITS the three merges before returning, and only latches the
     * once-per-session guard when ALL succeed — a transient failure (offline
     * at login, 500) therefore RETRIES on the next ihymns:auth-changed
     * navigation instead of being permanently suppressed (review #6). The
     * stores' _syncReady flags are set by the individual merges on success,
     * which is what unblocks the destructive 'replace' auto-sync; after the
     * merges land we flush each store's _scheduleSync so an edit made DURING
     * the reconcile window still propagates (review #1 / #9).
     */
    async triggerUserDataSync() {
        if (this._userDataSynced || this._userDataSyncing) return;
        if (!this.isLoggedIn()) return;
        this._userDataSyncing = true;

        let allOk = false;
        try {
            const results = await Promise.all([
                this.triggerSetlistSync(true), /* silent — routine per-boot reconcile */
                this.triggerFavoritesSync(),
                this.triggerCustomTagsSync(),
            ]);
            allOk = results.every(Boolean);
        } catch {
            allOk = false;
        } finally {
            this._userDataSyncing = false;
        }

        /* History backlog — independent + self-guarded (durable flag). */
        this.app.history?.backfillToServer?.();

        /* Flush edits made while the reconcile was in flight; _scheduleSync
           is now armed (for any store whose merge succeeded) and a no-op for
           the rest. */
        this.app.setList?._scheduleSync?.();
        this.app.favorites?._scheduleSync?.();

        /* Latch only on full success so failures retry next navigation. */
        if (allOk) {
            this._userDataSynced = true;
            /* #1649 — stamp WHEN the latch was set. The latch is once-per-
               session, but a PWA "session" can be days long (the tab is never
               closed, it is just backgrounded), and the whole point of the
               watermark is that a client with a stale cache must not push an
               authoritative 'replace'. The visibilitychange handler in
               bindOfflineDrains() uses this stamp to force a fresh reconcile
               after a long sleep. */
            this._userDataSyncedAt = Date.now();
        }
    }
}
