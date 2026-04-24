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
 * Open (and upgrade if needed) the offline-queue database. Safari
 * has historically mis-fired `onupgradeneeded` during the same tick
 * as `onsuccess`, so the upgrade branch is idempotent.
 */
function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                const store = db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                store.createIndex('byType', 'type', { unique: false });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
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
     * Persist `{type, payload}` to IndexedDB. Returns the numeric id
     * of the stored row so UIs can show "Saved as request #42 — will
     * send when you're back online".
     */
    async enqueue(type, payload) {
        const id = await withStore('readwrite', (store) => idb(
            store.add({ type, payload, createdAt: Date.now() })
        ));

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
        return withStore('readonly', (store) => idb(
            store.index('byType').count(IDBKeyRange.only(type))
        ));
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
        const items = await withStore('readonly', (store) => new Promise((resolve, reject) => {
            const acc = [];
            const cursorReq = store.index('byType').openCursor(IDBKeyRange.only(type));
            cursorReq.onsuccess = (ev) => {
                const cur = ev.target.result;
                if (cur) { acc.push({ id: cur.primaryKey, row: cur.value }); cur.continue(); }
                else resolve(acc);
            };
            cursorReq.onerror = () => reject(cursorReq.error);
        }));

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
        if (navigator.onLine) setTimeout(run, 500);
    },
};
