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

import { escapeHtml } from '../utils/html.js';
import { userHasEntitlement } from './entitlements.js';
import { offlineQueue } from './offline-queue.js';
import { STORAGE_AUTH_TOKEN, STORAGE_AUTH_USER } from '../constants.js';

export class UserAuth {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;
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
     * Fire the global `ihymns:auth-changed` event so any UI that depends
     * on signed-in state can refresh itself (header menu, settings page
     * Account card, setlist sync bar, etc.). The detail payload is the
     * current user object, or null when signed out.
     */
    _broadcastAuthChanged() {
        try {
            document.dispatchEvent(new CustomEvent('ihymns:auth-changed', {
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
        this._broadcastAuthChanged();
    }

    /**
     * Clear auth credentials from localStorage.
     */
    clearCredentials() {
        localStorage.removeItem(STORAGE_AUTH_TOKEN);
        localStorage.removeItem(STORAGE_AUTH_USER);
        this._broadcastAuthChanged();
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
     * Register a new account.
     * @param {string} username
     * @param {string} password
     * @param {string} displayName
     * @returns {Promise<{ success: boolean, error?: string }>}
     */
    async register(username, password, displayName) {
        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_register`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ username, password, display_name: displayName }),
            });

            const data = await res.json();
            if (!res.ok) return { success: false, error: data.error || 'Registration failed.' };

            this.saveCredentials(data.token, data.user);
            return { success: true };
        } catch {
            return { success: false, error: 'Network error. Please try again.' };
        }
    }

    /**
     * Log in with existing credentials.
     * @param {string} username
     * @param {string} password
     * @returns {Promise<{ success: boolean, error?: string }>}
     */
    async login(username, password) {
        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_login`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ username, password }),
            });

            const data = await res.json();
            if (!res.ok) return { success: false, error: data.error || 'Login failed.' };

            this.saveCredentials(data.token, data.user);
            return { success: true };
        } catch {
            return { success: false, error: 'Network error. Please try again.' };
        }
    }

    /**
     * Log out (invalidate token on server and clear local credentials).
     */
    async logout() {
        try {
            await fetch(`${this.app.config.apiUrl}?action=auth_logout`, {
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
    }

    /**
     * Verify the current token is still valid.
     * @returns {Promise<boolean>}
     */
    async verify() {
        if (!this.isLoggedIn()) return false;

        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_me`, {
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
     * SETLIST SYNC
     * ===================================================================== */

    /**
     * Sync local setlists with the server.
     * Sends all local setlists, receives the merged result.
     *
     * @param {Array} localSetlists Array of local setlist objects
     * @returns {Promise<Array|null>} Merged setlists from server, or null on failure
     */
    async syncSetlists(localSetlists) {
        if (!this.isLoggedIn()) return null;

        /* Offline → mark a pending sync in the queue. bindOfflineDrains()
           replays with the LATEST local state when connectivity returns,
           so the merge reflects every edit made while offline (#338). */
        if (!navigator.onLine) {
            try { await offlineQueue.enqueue('setlists-sync', { ts: Date.now() }); } catch (_e) {}
            return null;
        }

        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=user_setlists_sync`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({ setlists: localSetlists }),
            });

            if (!res.ok) {
                if (res.status === 401) this.clearCredentials();
                return null;
            }

            const data = await res.json();
            return data.setlists || null;
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
     * @param {string[]} localFavoriteIds Array of "CP-0001"-style song ids
     * @returns {Promise<string[]|null>} Merged list from server, or null on failure/queued
     */
    async syncFavorites(localFavoriteIds) {
        if (!this.isLoggedIn()) return null;

        if (!navigator.onLine) {
            try { await offlineQueue.enqueue('favorites-sync', { ts: Date.now() }); } catch (_e) {}
            return null;
        }

        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=favorites_sync`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...this.authHeaders(),
                },
                body: JSON.stringify({ favorites: localFavoriteIds }),
            });

            if (!res.ok) {
                if (res.status === 401) this.clearCredentials();
                return null;
            }

            const data = await res.json();
            return data.favorites || null;
        } catch (err) {
            if (err instanceof TypeError) {
                try { await offlineQueue.enqueue('favorites-sync', { ts: Date.now() }); } catch (_e) {}
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
            const merged = await this.syncSetlists(this.app.setList.getAll());
            if (merged && Array.isArray(merged)) {
                this.app.setList.saveAll(merged);
                this.app.showToast?.(`Synced ${merged.length} setlist${merged.length === 1 ? '' : 's'}`, 'success', 2000);
                return true;
            }
            return false;
        });

        offlineQueue.bindAutoDrainLatest('favorites-sync', async () => {
            if (!this.isLoggedIn() || !this.app.favorites) return false;
            const localIds = (this.app.favorites.getAll() || []).map(f => f.id);
            const merged = await this.syncFavorites(localIds);
            if (merged && Array.isArray(merged)) {
                /* Favourites module stores objects {id, title, songbook, number, tags, addedAt};
                   the server sends only ids. Union IDs preserving local metadata where we have it. */
                const byId = new Map((this.app.favorites.getAll() || []).map(f => [f.id, f]));
                const rebuilt = merged.map(id => byId.get(id) || {
                    id, title: '', songbook: '', number: 0, tags: [], addedAt: new Date().toISOString(),
                });
                this.app.favorites.saveAll(rebuilt);
                return true;
            }
            return false;
        });
    }

    /**
     * Fetch all setlists from the server (read-only, no merge).
     * @returns {Promise<Array|null>}
     */
    async fetchSetlists() {
        if (!this.isLoggedIn()) return null;

        try {
            const res = await fetch(`${this.app.config.apiUrl}?action=user_setlists`, {
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
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_update_profile`, {
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
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_change_username`, {
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
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_change_password`, {
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
     * HEADER USER MENU — Toggle logged-in / logged-out state
     * ===================================================================== */

    /**
     * Initialise the header user dropdown menu.
     * Binds buttons and updates visibility based on auth state.
     * Call once on app init, and again after login/logout.
     */
    initUserMenu() {
        this._updateHeaderState();

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
                img.width = 32;
                img.height = 32;
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
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_forgot_password`, {
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
            const res = await fetch(`${this.app.config.apiUrl}?action=auth_reset_password`, {
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
                            <div class="mb-3">
                                <label for="auth-password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="auth-password"
                                       placeholder="Password (min 8 characters)" autocomplete="current-password" required>
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
                                           placeholder="Min 8 characters" minlength="8" required>
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
           instead" to fall back to the username/password form. */
        if (mode === 'login') {
            modal.querySelector('#auth-form').style.display = 'none';
            modal.querySelector('#auth-email-section')?.classList.remove('d-none');
            modal.querySelector('#auth-toggle').style.display = 'none';
            modal.querySelector('#auth-forgot-link-wrapper').style.display = 'none';
        }

        /* Toggle between login and register */
        modal.querySelector('#auth-toggle')?.addEventListener('click', () => {
            currentMode = currentMode === 'login' ? 'register' : 'login';
            const isReg = currentMode === 'register';
            modal.querySelector('#auth-modal-title').textContent = isReg ? 'Create Account' : 'Sign In';
            modal.querySelector('#auth-submit-text').textContent = isReg ? 'Create Account' : 'Sign In';
            modal.querySelector('#auth-display-name-group').style.display = isReg ? '' : 'none';
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
                const res = await fetch(`${this.app.config.apiUrl}?action=auth_email_login_request`, {
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
                const res = await fetch(`${this.app.config.apiUrl}?action=auth_email_login_verify`, {
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
                result = await this.register(username, password, displayName || username);
            } else {
                result = await this.login(username, password);
            }

            spinner?.classList.add('d-none');
            submitBtn.disabled = false;

            if (result.success) {
                bsModal.hide();
                this._updateHeaderState();
                this.app.setList?.renderSyncBar();
                this.app.showToast(
                    currentMode === 'register' ? 'Account created! Syncing setlists...' : 'Signed in! Syncing setlists...',
                    'success', 3000
                );
                this.triggerSetlistSync();
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
        this.app.showToast('Signed in! Syncing setlists...', 'success', 3000);
        this.triggerSetlistSync();
    }

    /**
     * Trigger a setlist sync after login/register.
     * Merges local setlists with server and updates localStorage.
     */
    async triggerSetlistSync() {
        if (!this.app.setList) return;

        const localLists = this.app.setList.getAll();
        const merged = await this.syncSetlists(localLists);

        if (merged && Array.isArray(merged)) {
            /* Save the merged result to localStorage */
            this.app.setList.saveAll(merged);
            this.app.showToast(`Synced ${merged.length} setlist${merged.length !== 1 ? 's' : ''}`, 'success', 2000);
        }
    }
}
