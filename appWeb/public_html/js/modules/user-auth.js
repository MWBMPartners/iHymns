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

/** @type {string} localStorage key for the auth token */
const STORAGE_AUTH_TOKEN = 'ihymns_auth_token';

/** @type {string} localStorage key for cached user info */
const STORAGE_AUTH_USER = 'ihymns_auth_user';

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
     * Save auth credentials to localStorage.
     * @param {string} token Bearer token
     * @param {object} user  User info { id, username, display_name }
     */
    saveCredentials(token, user) {
        localStorage.setItem(STORAGE_AUTH_TOKEN, token);
        localStorage.setItem(STORAGE_AUTH_USER, JSON.stringify(user));
    }

    /**
     * Clear auth credentials from localStorage.
     */
    clearCredentials() {
        localStorage.removeItem(STORAGE_AUTH_TOKEN);
        localStorage.removeItem(STORAGE_AUTH_USER);
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
            });

            if (!res.ok) {
                this.clearCredentials();
                return false;
            }

            const data = await res.json();
            if (data.user) {
                /* Update cached user info */
                localStorage.setItem(STORAGE_AUTH_USER, JSON.stringify(data.user));
            }

            return true;
        } catch {
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
        } catch {
            return null;
        }
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
     * Update the header dropdown to reflect current auth state.
     * Shows/hides appropriate menu items.
     */
    _updateHeaderState() {
        const loggedIn = this.isLoggedIn();
        const user = this.getUser();

        /* Guest items (sign in / register) */
        const guestIds = ['header-user-guest', 'header-user-register-li'];
        /* Logged-in items */
        const authIds = [
            'header-user-name', 'header-user-role-li', 'header-user-divider',
            'header-user-setlists-li', 'header-user-sync-li',
            'header-user-settings-li', 'header-user-divider2', 'header-user-signout-li',
        ];

        guestIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('d-none', loggedIn);
        });

        authIds.forEach(id => {
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

        /* Admin/Editor links — show based on role privilege */
        const role = user?.role || 'user';
        const roleLevel = { 'user': 1, 'editor': 2, 'admin': 3, 'global_admin': 4 }[role] || 0;
        const isEditor = loggedIn && roleLevel >= 2;
        const isAdmin  = loggedIn && roleLevel >= 3;

        const editorEl = document.getElementById('header-user-editor-li');
        const dashEl   = document.getElementById('header-user-dashboard-li');
        const divEl    = document.getElementById('header-user-admin-divider');
        if (editorEl) editorEl.classList.toggle('d-none', !isEditor);
        if (dashEl)   dashEl.classList.toggle('d-none', !isAdmin);
        if (divEl)    divEl.classList.toggle('d-none', !isEditor);

        /* Update icon style */
        const icon = document.getElementById('header-user-icon');
        if (icon) {
            icon.className = loggedIn
                ? 'fa-solid fa-circle-user'
                : 'fa-solid fa-user';
        }
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
            modal.querySelector('#auth-error')?.classList.add('d-none');
            modal.querySelector('#auth-forgot-section')?.classList.add('d-none');
        });

        /* Forgot password link */
        modal.querySelector('#auth-forgot-link')?.addEventListener('click', () => {
            modal.querySelector('#auth-form').style.display = 'none';
            modal.querySelector('#auth-toggle').style.display = 'none';
            modal.querySelector('#auth-forgot-link-wrapper').style.display = 'none';
            modal.querySelector('#auth-forgot-section')?.classList.remove('d-none');
            modal.querySelector('#auth-modal-title').textContent = 'Reset Password';
        });

        /* Back to Sign In from forgot password */
        modal.querySelector('#auth-back-to-login')?.addEventListener('click', () => {
            modal.querySelector('#auth-form').style.display = '';
            modal.querySelector('#auth-toggle').style.display = '';
            modal.querySelector('#auth-forgot-link-wrapper').style.display = '';
            modal.querySelector('#auth-forgot-section')?.classList.add('d-none');
            modal.querySelector('#auth-modal-title').textContent = 'Sign In';
            /* Reset forgot password state */
            modal.querySelector('#auth-forgot-step1')?.classList.remove('d-none');
            modal.querySelector('#auth-reset-form')?.classList.add('d-none');
            modal.querySelector('#auth-forgot-error')?.classList.add('d-none');
            modal.querySelector('#auth-forgot-success')?.classList.add('d-none');
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
                /* Update header menu to reflect logged-in state */
                this._updateHeaderState();
                /* Re-render setlist sync bar if on that page */
                this.app.setList?.renderSyncBar();
                this.app.showToast(
                    currentMode === 'register' ? 'Account created! Syncing setlists...' : 'Signed in! Syncing setlists...',
                    'success', 3000
                );
                /* Trigger setlist sync */
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
