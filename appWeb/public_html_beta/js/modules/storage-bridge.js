/**
 * iHymns — Cross-Domain Storage Bridge (#133)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides shared localStorage access across all iHymns domains
 * (ihymns.app, ihymns.net, beta.ihymns.app, etc.) via a hidden
 * iframe hosted on a canonical sync domain.
 *
 * HOW IT WORKS:
 * 1. A hidden iframe loads bridge.html from the sync domain
 * 2. All storage operations are sent as postMessage requests
 * 3. The bridge iframe handles localStorage and replies
 * 4. Falls back to local localStorage if the bridge is unavailable
 *
 * USAGE:
 *   const bridge = new StorageBridge('https://sync.ihymns.app/bridge.html');
 *   await bridge.init();
 *   await bridge.set('ihymns_theme', 'dark');
 *   const theme = await bridge.get('ihymns_theme');
 */

export class StorageBridge {
    /**
     * @param {string} bridgeUrl Full URL to the bridge.html on the sync domain
     * @param {number} timeout Timeout in ms for bridge operations (default 2000)
     */
    constructor(bridgeUrl, timeout = 2000) {
        /** @type {string} URL of the bridge iframe */
        this.bridgeUrl = bridgeUrl;

        /** @type {number} Operation timeout */
        this.timeout = timeout;

        /** @type {HTMLIFrameElement|null} */
        this.iframe = null;

        /** @type {boolean} Whether the bridge is connected */
        this.connected = false;

        /** @type {Map<string, {resolve: Function, reject: Function}>} Pending requests */
        this._pending = new Map();

        /** @type {number} Auto-incrementing request ID */
        this._nextId = 1;

        /** @type {Function} Bound message handler for cleanup */
        this._messageHandler = this._onMessage.bind(this);
    }

    /**
     * Initialise the storage bridge.
     * Creates the hidden iframe and waits for it to load.
     * If the bridge fails to connect, falls back to local storage.
     *
     * @returns {Promise<boolean>} True if bridge connected, false if using fallback
     */
    async init() {
        /* If we're already on the sync domain, use local storage directly */
        if (this._isOnSyncDomain()) {
            this.connected = false; /* Use local fallback */
            return false;
        }

        return new Promise((resolve) => {
            try {
                /* Create hidden iframe */
                this.iframe = document.createElement('iframe');
                this.iframe.src = this.bridgeUrl;
                this.iframe.style.display = 'none';
                this.iframe.setAttribute('aria-hidden', 'true');
                this.iframe.id = 'ihymns-storage-bridge';
                document.body.appendChild(this.iframe);

                /* Listen for messages from the bridge */
                window.addEventListener('message', this._messageHandler);

                /* Wait for iframe to load, then ping */
                this.iframe.addEventListener('load', () => {
                    this._send('ping')
                        .then((result) => {
                            if (result === 'pong') {
                                this.connected = true;
                                console.log('[StorageBridge] Connected to sync domain');
                                /* Sync local data to bridge on first connect */
                                this._syncLocalToBridge();
                                resolve(true);
                            } else {
                                resolve(false);
                            }
                        })
                        .catch(() => {
                            console.warn('[StorageBridge] Bridge ping failed, using local storage');
                            resolve(false);
                        });
                });

                /* Iframe load error */
                this.iframe.addEventListener('error', () => {
                    console.warn('[StorageBridge] Bridge iframe failed to load, using local storage');
                    resolve(false);
                });

                /* Overall timeout */
                setTimeout(() => {
                    if (!this.connected) {
                        console.warn('[StorageBridge] Connection timed out, using local storage');
                        resolve(false);
                    }
                }, this.timeout + 500);

            } catch (error) {
                console.warn('[StorageBridge] Init failed:', error.message);
                resolve(false);
            }
        });
    }

    /**
     * Get a value from storage.
     * @param {string} key
     * @returns {Promise<string|null>}
     */
    async get(key) {
        if (this.connected) {
            try {
                const result = await this._send('get', { key });
                /* Also update local copy for offline access */
                if (result !== null) {
                    localStorage.setItem(key, result);
                }
                return result;
            } catch {
                /* Fall through to local */
            }
        }
        return localStorage.getItem(key);
    }

    /**
     * Set a value in storage.
     * @param {string} key
     * @param {string} value
     * @returns {Promise<void>}
     */
    async set(key, value) {
        /* Always write locally for offline access */
        localStorage.setItem(key, value);

        if (this.connected) {
            try {
                await this._send('set', { key, value });
            } catch {
                /* Local write already succeeded — bridge failure is non-fatal */
            }
        }
    }

    /**
     * Remove a value from storage.
     * @param {string} key
     * @returns {Promise<void>}
     */
    async remove(key) {
        localStorage.removeItem(key);

        if (this.connected) {
            try {
                await this._send('remove', { key });
            } catch {
                /* Non-fatal */
            }
        }
    }

    /**
     * Get all ihymns_ prefixed keys and values from the bridge.
     * @returns {Promise<Object>} Key-value pairs
     */
    async getAll() {
        if (this.connected) {
            try {
                return await this._send('getAll');
            } catch {
                /* Fall through */
            }
        }

        /* Fallback: local storage */
        const result = {};
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (k.startsWith('ihymns_')) {
                result[k] = localStorage.getItem(k);
            }
        }
        return result;
    }

    /**
     * On first connection, push any local ihymns_ data to the bridge
     * so existing users don't lose their settings when bridge is added.
     * Uses "keep newest" merge — bridge values take precedence if they exist.
     * @private
     */
    async _syncLocalToBridge() {
        try {
            const bridgeData = await this._send('getAll');

            /* Push local keys that don't exist on the bridge */
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key.startsWith('ihymns_') && !(key in bridgeData)) {
                    await this._send('set', { key, value: localStorage.getItem(key) });
                }
            }

            /* Pull bridge keys that don't exist locally */
            for (const [key, value] of Object.entries(bridgeData)) {
                if (localStorage.getItem(key) === null) {
                    localStorage.setItem(key, value);
                }
            }
        } catch {
            /* Non-fatal — sync will happen on next page load */
        }
    }

    /**
     * Check if the current page is on the sync domain.
     * @private
     * @returns {boolean}
     */
    _isOnSyncDomain() {
        try {
            const bridgeOrigin = new URL(this.bridgeUrl).origin;
            return window.location.origin === bridgeOrigin;
        } catch {
            return false;
        }
    }

    /**
     * Send a message to the bridge iframe and wait for a response.
     * @private
     * @param {string} action
     * @param {object} data
     * @returns {Promise<*>}
     */
    _send(action, data = {}) {
        return new Promise((resolve, reject) => {
            if (!this.iframe?.contentWindow) {
                reject(new Error('Bridge iframe not available'));
                return;
            }

            const id = this._nextId++;
            const timer = setTimeout(() => {
                this._pending.delete(id);
                reject(new Error(`Bridge timeout for ${action}`));
            }, this.timeout);

            this._pending.set(id, {
                resolve: (result) => {
                    clearTimeout(timer);
                    resolve(result);
                },
                reject: (err) => {
                    clearTimeout(timer);
                    reject(err);
                },
            });

            this.iframe.contentWindow.postMessage({
                ns: 'ihymns-bridge',
                action,
                id,
                ...data,
            }, new URL(this.bridgeUrl).origin);
        });
    }

    /**
     * Handle messages from the bridge iframe.
     * @private
     * @param {MessageEvent} event
     */
    _onMessage(event) {
        if (!event.data || event.data.ns !== 'ihymns-bridge') return;
        if (typeof event.data.id !== 'number') return;

        const pending = this._pending.get(event.data.id);
        if (pending) {
            this._pending.delete(event.data.id);
            pending.resolve(event.data.result);
        }
    }
}
