/**
 * iHymns — Offline Action Queue (#337, #338)
 *
 * IndexedDB-backed durable queue for POSTs that couldn't reach the
 * server because the browser was offline. Consumers call
 * `offlineQueue.enqueue(type, payload)` and the queue:
 *
 *   1. stores the item durably (survives page close, browser restart);
 *   2. registers a Background Sync tag with the service worker so the
 *      OS wakes us up when connectivity returns (where supported);
 *   3. falls back to a `window.online` listener + manual drain for
 *      browsers without Background Sync (Safari, Firefox private mode).
 *
 * A single store, keyed by an auto-increment id, with a `type` index so
 * #337 (song-request submissions) and #338 (favourites + setlists)
 * share storage without crossing wires. Consumers pass their own
 * `send(payload)` to drain — the queue doesn't know how to POST
 * anything itself, which keeps it content-agnostic.
 */

const DB_NAME    = 'ihymns-offline-queue';
const DB_VERSION = 1;
const STORE_NAME = 'queue';

/** Promisified `IDBRequest`. */
function idb(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
    });
}

/**
 * IndexedDB availability cache. Some contexts advertise the API
 * (`'indexedDB' in window` is true) but reject open() — Firefox
 * private browsing historically returned an "InvalidStateError",
 * Safari occasionally aborts inside cross-origin iframes, and a
 * handful of locked-down browser extensions block open() at the
 * permission layer (#354). The first openDb() call probes; cached
 * thereafter so we don't reissue a failing request on every drain.
 *
 *   null     — not yet probed
 *   false    — IDB confirmed unusable; helpers all fail-soft
 *   true     — confirmed usable on this page
 */
let _idbWorking = null;

/**
 * Open (and upgrade if needed) the offline-queue database. Safari
 * has historically mis-fired `onupgradeneeded` during the same tick
 * as `onsuccess`, so the upgrade branch is idempotent.
 *
 * Rejects with the original DOMException — callers should catch
 * and bail rather than crash so a private-mode visitor still gets
 * a usable (though non-persisting) UI. (#354)
 */
function openDb() {
    if (_idbWorking === false) {
        return Promise.reject(new Error('IndexedDB unavailable in this context'));
    }
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined' || indexedDB === null) {
            _idbWorking = false;
            reject(new Error('IndexedDB API not present'));
            return;
        }
        let req;
        try {
            req = indexedDB.open(DB_NAME, DB_VERSION);
        } catch (e) {
            _idbWorking = false;
            reject(e);
            return;
        }
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                const store = db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                store.createIndex('byType', 'type', { unique: false });
            }
        };
        req.onsuccess = () => { _idbWorking = true; resolve(req.result); };
        req.onerror   = () => { _idbWorking = false; reject(req.error); };
        req.onblocked = () => { _idbWorking = false; reject(new Error('IndexedDB open blocked')); };
    });
}

/**
 * Run `fn(store)` inside a transaction and wait for `tx.oncomplete`
 * before resolving. This guarantees the write is durable when the
 * promise resolves — important when we then register a Background
 * Sync tag that could fire immediately.
 */
async function withStore(mode, fn) {
    const db    = await openDb();
    const tx    = db.transaction(STORE_NAME, mode);
    const store = tx.objectStore(STORE_NAME);
    const result = await fn(store);
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve(result);
        tx.onerror    = () => reject(tx.error);
        tx.onabort    = () => reject(tx.error || new Error('tx aborted'));
    });
}

/** Is Background Sync actually registerable from this context? */
function syncSupported() {
    return 'serviceWorker' in navigator
        && typeof window.SyncManager !== 'undefined';
}

export const offlineQueue = {
    /**
     * Public-shaped feature test for IndexedDB so consumers can decide
     * whether to surface "saved offline, will send when reconnected"
     * messaging. Resolves true if a real open() succeeds, false in
     * private-mode / blocked-extension contexts (#354).
     */
    async isAvailable() {
        if (_idbWorking !== null) return _idbWorking;
        try { (await openDb()).close(); return true; }
        catch (_e) { return false; }
    },

    /**
     * Persist `{type, payload}` to IndexedDB. Returns the numeric id
     * of the stored row so UIs can show "Saved as request #42 — will
     * send when you're back online".
     *
     * In private-mode or blocked-IDB contexts, returns null and the
     * caller should fall back to a one-shot "could not save offline,
     * please try again when reconnected" hint rather than letting
     * the rejection bubble up. (#354)
     */
    async enqueue(type, payload) {
        let id;
        try {
            id = await withStore('readwrite', (store) => idb(
                store.add({ type, payload, createdAt: Date.now() })
            ));
        } catch (e) {
            console.warn('[offlineQueue] enqueue failed (IDB unavailable?):', e);
            return null;
        }

        if (syncSupported()) {
            try {
                const reg = await navigator.serviceWorker.ready;
                await reg.sync.register(`ihymns-queue-${type}`);
            } catch (_e) {
                /* Registration can fail (private mode, user declined SW
                   perms). The window.online fallback still fires. */
            }
        }
        return id;
    },

    /** Count pending items of a given type — for badge displays. */
    async count(type) {
        try {
            return await withStore('readonly', (store) => idb(
                store.index('byType').count(IDBKeyRange.only(type))
            ));
        } catch (_e) {
            return 0; /* IDB blocked/private-mode → no pending rows visible */
        }
    },

    /**
     * Drain queue for a given type. `send(payload)` is awaited per
     * item (oldest first). Its return value decides the outcome:
     *
     *   - resolves truthy → item deleted
     *   - resolves falsy  → item kept (retry later)
     *   - throws          → item kept (retry later)
     *
     * Returns `{ sent, failed, remaining }` so the caller can surface
     * progress in the UI.
     */
    async drain(type, send) {
        let items;
        try {
            items = await withStore('readonly', (store) => new Promise((resolve, reject) => {
                const acc = [];
                const cursorReq = store.index('byType').openCursor(IDBKeyRange.only(type));
                cursorReq.onsuccess = (ev) => {
                    const cur = ev.target.result;
                    if (cur) { acc.push({ id: cur.primaryKey, row: cur.value }); cur.continue(); }
                    else resolve(acc);
                };
                cursorReq.onerror = () => reject(cursorReq.error);
            }));
        } catch (_e) {
            return { sent: 0, failed: 0, remaining: 0 }; /* IDB unavailable */
        }

        let sent = 0, failed = 0;
        for (const { id, row } of items) {
            try {
                const ok = await send(row.payload);
                if (ok) {
                    await withStore('readwrite', (store) => idb(store.delete(id)));
                    sent++;
                } else {
                    failed++;
                }
            } catch (_e) {
                failed++;
            }
        }
        const remaining = await this.count(type);
        return { sent, failed, remaining };
    },

    /**
     * "Latest state wins" drain — for sync operations where replaying
     * every queued payload would waste the network. Favourites /
     * setlists / settings each POST the full local list, so multiple
     * queued edits collapse into a single sync with the most recent
     * state.
     *
     * Callers enqueue a marker (payload can be `{}` or a dedup key)
     * via `enqueue(type, {})`; on drain `send()` is invoked ONCE with
     * no argument — the caller reads fresh state from localStorage.
     * If `send()` resolves truthy, every queued row of that type is
     * deleted; otherwise they stay for the next trigger.
     *
     * Returns `{ ran, remaining }`.
     */
    async drainLatest(type, send) {
        const pending = await this.count(type);
        if (pending === 0) return { ran: false, remaining: 0 };
        let ok = false;
        try {
            ok = !!(await send());
        } catch (_e) {
            ok = false;
        }
        if (!ok) return { ran: true, remaining: pending };

        await withStore('readwrite', (store) => new Promise((resolve, reject) => {
            const cursorReq = store.index('byType').openCursor(IDBKeyRange.only(type));
            cursorReq.onsuccess = (ev) => {
                const cur = ev.target.result;
                if (cur) { cur.delete(); cur.continue(); }
                else resolve();
            };
            cursorReq.onerror = () => reject(cursorReq.error);
        }));
        return { ran: true, remaining: 0 };
    },

    /**
     * Like `bindAutoDrain` but for `drainLatest` semantics — wires the
     * same triggers (`online` + SW `QUEUE_DRAIN`) to a latest-state
     * sync rather than per-item replay.
     */
    bindAutoDrainLatest(type, send, onResult = () => {}) {
        const run = async () => {
            try {
                onResult(await this.drainLatest(type, send));
            } catch (_e) { /* leave queue alone */ }
        };
        window.addEventListener('online', run);
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'QUEUE_DRAIN'
                    && e.data.tag === `ihymns-queue-${type}`) {
                    run();
                }
            });
        }
        /* One-shot nudge: page may have loaded after the most recent
           offline→online transition, so we won't get an `online` event
           to trigger drain. Just try — the run() drain itself fail-
           softs on network errors (items stay queued until the next
           successful drain attempt), so this is safe to call
           unconditionally regardless of navigator.onLine state (which
           is unreliable per #524). (#354) */
        setTimeout(run, 500);
    },

    /**
     * Register the two triggers that should drain the queue:
     *   - the window coming back online
     *   - the service worker echoing a `QUEUE_DRAIN` message after a
     *     Background Sync tag fires (the SW posts this from its
     *     `sync` handler in service-worker.js).
     *
     * `onResult({ sent, failed, remaining })` is called after each
     * drain pass so the UI can refresh any "pending" badges.
     */
    bindAutoDrain(type, send, onResult = () => {}) {
        const run = async () => {
            try {
                onResult(await this.drain(type, send));
            } catch (_e) { /* queue stays put */ }
        };
        window.addEventListener('online', run);
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'QUEUE_DRAIN'
                    && e.data.tag === `ihymns-queue-${type}`) {
                    run();
                }
            });
        }
        /* Might have come online between page load and bindAutoDrain.
           A one-shot nudge covers that without waiting for the next
           offline→online transition. */
        /* One-shot nudge: page may have loaded after the most recent
           offline→online transition, so we won't get an `online` event
           to trigger drain. Just try — the run() drain itself fail-
           softs on network errors (items stay queued until the next
           successful drain attempt), so this is safe to call
           unconditionally regardless of navigator.onLine state (which
           is unreliable per #524). (#354) */
        setTimeout(run, 500);
    },
};
