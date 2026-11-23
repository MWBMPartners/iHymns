/**
 * profile-sync.js - keep a person's small settings in step across their devices
 * ===========================================================================
 * Account/profile rewrite, PR 2b step 2b-5 (epic #2180). The browser's half of
 * `GET/POST /api?action=profile_sync` (the server's half is includes/profile_sync.php).
 *
 * ELI5
 * ----
 * When a signed-in person changes a setting (theme, text size, chord style, a key for one song ...),
 * this remembers "I changed that" in an OUTBOX, waits a moment (1.5 seconds, so a slider being dragged
 * is one change, not fifty), and sends only the things that changed. It also asks the account "what
 * changed elsewhere since I last looked?" when the person signs in and when they come back to the tab
 * after a while, and applies those changes here. Two rules keep it from ever hurting anyone:
 *  1. A change still waiting in the outbox is never overwritten by something read from the account.
 *  2. A value that arrived FROM the account is never sent back as if the person had just chosen it.
 *
 * Detailed
 * --------
 *  - OFF unless the server says so (`config.profileSync.enabled`, which needs the admin switch AND the
 *    database table). While it is off, or after the server answers 409 `profile_sync_disabled` /
 *    `profile_sync_not_ready`, `isActive()` is false and settings.js keeps its older whole-box sync.
 *  - Which settings exist, and what values they may take, comes from the downloaded `profile_schema`
 *    (cached with its tag so the app starts offline); nothing is typed here (rule #35).
 *  - OUTBOX (`ihymns_profile_outbox`): {uid, seq, items:{"section.key": {section, key, op, value?, seq}}}.
 *    The newest change to an item replaces the older one. After a send, an item is removed ONLY if its
 *    `seq` still equals the one that was sent, so a change made while the request was in flight survives.
 *  - CURSOR (`ihymns_profile_cursor`): {uid, cursor, claimed}. Both records name their person; a record for
 *    another person is discarded when read (A7: one person's pending changes are never sent as another's).
 *  - `app.syncStorage(key, {origin})`: origin 'local' (the default) queues the change; origin 'account'
 *    (what this module itself uses after applying a value) does NOT, so values from the account are never
 *    sent back.
 *  - READING: at sign-in and after 30 minutes away (utils/pull-gate.js), GET pages of up to 500 until
 *    `more` is false. Everything is applied except items still in the outbox. The first read of a person on
 *    a device also "claims": a value this device holds that the account has never heard of (not even a
 *    deletion) is queued, so settings chosen before signing in are not lost.
 *  - A reply is dropped if a different person is signed in by the time it arrives.
 *  - PAUSED SITE (409 `account_writes_off_here`): the outbox is kept and sending waits 5 minutes. Reading
 *    still works.
 *  - Status codes decide what to do, never the server's sentence (rule #35).
 *
 * @module modules/profile-sync
 * @see includes/profile_sync.php  the server's half
 * @see js/modules/profile-sync-adapters.js  where each setting lives in this browser
 */

import {
    STORAGE_PROFILE_OUTBOX, STORAGE_PROFILE_CURSOR, STORAGE_PROFILE_SCHEMA,
    EVT_AUTH_CHANGED, EVT_PROFILE_APPLIED, EVT_PROFILE_ACTIVE_CHANGED, ACCOUNT_WRITES_OFF_REASON,
    STORAGE_SEARCH_HISTORY, STORAGE_SYNC_SEARCH_HISTORY,
} from '../constants.js';
import { apiFetch } from '../utils/api-client.js';
import { createPullGate } from '../utils/pull-gate.js';
import { buildProviders } from './profile-sync-adapters.js';

/** How long a change sits in the outbox before it is sent (so a slider drag is one change). */
export const PUSH_DELAY_MS = 1500;
/** How long to wait after "saving is paused on this site" before trying again. */
export const PAUSED_RETRY_MS = 5 * 60 * 1000;
/** How long to wait after a network or server error before trying again. */
export const ERROR_RETRY_MS = 60 * 1000;
/** The most changes in one request (the server refuses more with 413). */
export const MAX_CHANGES_PER_REQUEST = 200;
/** A request body stays well under the server's 64 KiB limit. */
const MAX_BODY_CHARS = 48 * 1024;
/** The outbox never grows past this many items (the oldest are dropped first). A safety net, not a normal limit. */
const MAX_OUTBOX_ITEMS = 2500;
/** The most pages one read will follow. */
const MAX_PAGES = 40;
/** Reasons for which a refused change will never be accepted, so it must not stay in the outbox for ever. */
export const PERMANENT_REJECTIONS = ['unknown_key', 'device_only', 'not_profile_item', 'invalid_value', 'invalid_item_key', 'value_too_large', 'cap_reached', 'not_opted_in'];
/** The setting that lets a person opt out of syncing their settings (the same one settings.js has always used). */
const SYNC_TOGGLE_KEY = 'ihymns_sync_app_settings';

const idOf = (section, key) => section + '.' + key;

export class ProfileSync {
    /**
     * @param {object} app the app (needs `config.apiUrl`, `userAuth`, and optionally `syncStorage`)
     * @param {{storage?: Storage, setTimer?: Function, clearTimer?: Function, now?: () => number}} [opts] test hooks
     */
    constructor(app, opts = {}) {
        this.app = app;
        this.storage = opts.storage || localStorage;
        this._setTimer = opts.setTimer || ((fn, ms) => setTimeout(fn, ms));
        this._clearTimer = opts.clearTimer || ((t) => clearTimeout(t));
        this.schema = null;
        this.providers = null;
        /** Set when the server answers that this feature is not on here (or not ready): the older sync then carries on. */
        this._off = false;
        this._pushTimer = null;
        this._retryTimer = null;
        this._inFlight = false;
        this._again = false;
        this._pullGate = createPullGate();
        this._schemaPromise = null;
        /** The last answer of isActive() that listeners were told about (see refreshActive()). */
        this._lastActive = null;
        /** The most changes per request right now: halved when the server says a request was too big, so it can never get stuck. */
        this._batchLimit = MAX_CHANGES_PER_REQUEST;
    }

    /* ============================ switch ============================ */

    /** Is the new sync doing the work right now? settings.js stops its older whole-box sync while this is true. */
    isActive() {
        return !this._off && !!this.app.config?.profileSync?.enabled && this._signedIn() && this._optedIn();
    }

    /**
     * Tell the page when isActive() has changed, so anything that shows only while the sync is working (the Settings row
     * "Keep my recent searches in my account") appears or disappears at once, not only the next time the page is drawn.
     * Call it after anything that can change the answer. Fires only on a real change.
     *
     * @returns {boolean} the current answer
     */
    refreshActive() {
        const now = this.isActive();
        if (this._lastActive !== now) {
            this._lastActive = now;
            try { document.dispatchEvent(new CustomEvent(EVT_PROFILE_ACTIVE_CHANGED, { detail: { active: now } })); } catch (_e) { /* no DOM */ }
        }
        return now;
    }

    _uid() {
        const id = this.app.userAuth?.getUser?.()?.id;
        return (id === null || id === undefined || id === '') ? null : String(id);
    }
    _signedIn() { return !!this.app.userAuth?.isLoggedIn?.() && this._uid() !== null; }
    _optedIn() {
        try { return this.storage.getItem(SYNC_TOGGLE_KEY) !== 'false'; } catch (_e) { return true; }
    }

    /** Start listening. Safe to call once, after the app's sign-in object exists. */
    init() {
        if (!this.app.config?.profileSync?.enabled) return;
        document.addEventListener(EVT_AUTH_CHANGED, (e) => {
            const loggedIn = !!e?.detail?.loggedIn;
            if (!loggedIn) {
                this._pullGate.reset();
                this._cancelTimers();
                this.refreshActive();
                return;
            }
            this.refreshActive();
            const uid = e?.detail?.user?.id ?? this._uid();
            if (this._off || !this._optedIn() || !this._pullGate.begin(uid)) return;
            this.syncNow().then((ok) => { if (!ok) this._pullGate.fail(); }).catch(() => this._pullGate.fail());
        });
        this.refreshActive();
        /* Already signed in when the page loaded (the router's first announcement came before this existed). */
        if (this._signedIn() && this._optedIn() && this._pullGate.begin(this._uid())) {
            this.syncNow().then((ok) => { if (!ok) this._pullGate.fail(); }).catch(() => this._pullGate.fail());
        }
    }

    _cancelTimers() {
        if (this._pushTimer) { this._clearTimer(this._pushTimer); this._pushTimer = null; }
        if (this._retryTimer) { this._clearTimer(this._retryTimer); this._retryTimer = null; }
    }

    /* ============================ schema ============================ */

    /** Load (and cache by its tag) the server's list of personal settings, then build the providers. */
    async ensureSchema() {
        if (this.providers) return true;
        if (!this._schemaPromise) this._schemaPromise = this._loadSchema().finally(() => { this._schemaPromise = null; });
        return this._schemaPromise;
    }

    async _loadSchema() {
        let cached = null;
        try { cached = JSON.parse(this.storage.getItem(STORAGE_PROFILE_SCHEMA) || 'null'); } catch (_e) { cached = null; }
        let schema = null;
        try {
            const headers = {};
            if (cached && typeof cached.etag === 'string') headers['If-None-Match'] = cached.etag;
            const res = await apiFetch(`${this.app.config.apiUrl}?action=profile_schema`, { headers });
            if (res.status === 304 && cached && cached.data) {
                schema = cached.data;
            } else if (res.ok) {
                const data = await res.json();
                if (data && Array.isArray(data.entries)) {
                    schema = data;
                    try { this.storage.setItem(STORAGE_PROFILE_SCHEMA, JSON.stringify({ etag: res.headers.get('ETag') || null, data })); } catch (_e) { /* storage full: use it this time only */ }
                }
            }
        } catch (_e) { /* offline: fall back to the cached copy below */ }
        if (!schema && cached && cached.data && Array.isArray(cached.data.entries)) schema = cached.data;
        if (!schema) return false;
        this.schema = schema;
        this.providers = buildProviders(schema, this.storage);
        return true;
    }

    /* ============================ outbox ============================ */

    _readJson(key) {
        try { return JSON.parse(this.storage.getItem(key) || 'null'); } catch (_e) { return null; }
    }
    _writeJson(key, value) {
        try { this.storage.setItem(key, JSON.stringify(value)); return true; } catch (_e) { return false; }
    }

    /** The outbox for THIS person (a record for someone else is thrown away). */
    getOutbox() {
        const uid = this._uid();
        const ob = this._readJson(STORAGE_PROFILE_OUTBOX);
        if (ob && typeof ob === 'object' && ob.uid === uid && ob.items && typeof ob.items === 'object') return ob;
        return { uid, seq: 0, items: {} };
    }
    _saveOutbox(ob) {
        const ids = Object.keys(ob.items);
        if (ids.length > MAX_OUTBOX_ITEMS) {
            ids.sort((a, b) => ob.items[a].seq - ob.items[b].seq).slice(0, ids.length - MAX_OUTBOX_ITEMS).forEach((id) => delete ob.items[id]);
        }
        if (ids.length === 0) { try { this.storage.removeItem(STORAGE_PROFILE_OUTBOX); } catch (_e) { /* nothing */ } return; }
        this._writeJson(STORAGE_PROFILE_OUTBOX, ob);
    }
    /** How many changes are waiting to be sent. */
    pendingCount() { return Object.keys(this.getOutbox().items).length; }

    _getCursorRecord() {
        const uid = this._uid();
        const rec = this._readJson(STORAGE_PROFILE_CURSOR);
        if (rec && typeof rec === 'object' && rec.uid === uid && Number.isInteger(rec.cursor) && rec.cursor >= 0) return rec;
        return { uid, cursor: 0, claimed: false };
    }
    _saveCursorRecord(rec) { this._writeJson(STORAGE_PROFILE_CURSOR, rec); }

    _enqueue(section, key, op, value) {
        const ob = this.getOutbox();
        ob.seq += 1;
        const item = { section, key, op, seq: ob.seq };
        if (op === 'set') item.value = value;
        ob.items[idOf(section, key)] = item;
        this._saveOutbox(ob);
    }

    /* ============================ local changes ============================ */

    /**
     * A local setting changed. Called from `app.syncStorage(key, opts)`.
     * Origin 'account' means "this value came FROM the account": never queued, so it is never sent back.
     *
     * @param {string} storageKey the browser storage key that changed
     * @param {{origin?: 'local'|'account'}} [opts]
     */
    recordLocalChange(storageKey, opts = {}) {
        if (opts.origin === 'account') return;
        if (!this.isActive()) return;
        if (!this.providers) {
            /* The list of settings has not loaded yet (the first moments after sign-in): load it, then record this change. */
            this.ensureSchema().then((ok) => { if (ok) this.recordLocalChange(storageKey, opts); }).catch(() => {});
            return;
        }
        const hit = this.providers.forStorageKey(storageKey);
        if (!hit) return;
        const r = hit.provider.read(hit.item);
        if (r.state !== 'value' && r.state !== 'absent') return;   /* invalid: never sent and never a delete; off: private, never sent */
        const section = hit.provider.entry.section;
        const key = hit.item;
        if (r.state === 'absent') this._enqueue(section, key, 'delete');
        else this._enqueue(section, key, 'set', r.value);
        this.schedulePush();
    }

    /**
     * The person flipped "Keep my recent searches in my account". Switching ON also sends the current list; switching OFF
     * deletes the account's copy (and the option itself is recorded either way, so other devices learn it).
     *
     * @param {boolean} on
     */
    async setSearchHistorySync(on) {
        /* Nothing is changed on this device unless the change can also be queued for the account: a switch that only
           moved locally would leave the account (and so every other device) believing something else. */
        if (!this.isActive() || !(await this.ensureSchema()) || !this.isActive()) return false;
        try {
            if (on) this.storage.setItem(STORAGE_SYNC_SEARCH_HISTORY, 'true');
            else this.storage.removeItem(STORAGE_SYNC_SEARCH_HISTORY);
        } catch (_e) { return false; }
        this.recordLocalChange(STORAGE_SYNC_SEARCH_HISTORY);           /* the option itself: set true, or delete */
        if (on) this.recordLocalChange(STORAGE_SEARCH_HISTORY);        /* now the list reads as a value, so it is sent */
        else this._enqueue('history', 'searches', 'delete');           /* off: the account's copy goes */
        this.schedulePush();
        return true;
    }

    /**
     * The person switched settings sync back ON after it had been off. Nothing is queued from here: what this device
     * holds may be older than the account (the account kept changing, and per-song items may have been removed there),
     * so sending it all would overwrite newer values and bring back what was deleted. Instead the device starts again
     * the way a first sign-in does: read EVERYTHING the account holds (it wins), then claim only the values the account
     * has never heard of (not even a removal), then send what is waiting.
     *
     * @returns {Promise<boolean>} true when the read finished
     */
    async resumeAfterOptIn() {
        if (!this.isActive()) return false;
        const rec = this._getCursorRecord();
        rec.claimed = false;
        this._saveCursorRecord(rec);
        return this.syncNow();
    }

    schedulePush(delay = PUSH_DELAY_MS) {
        if (this._pushTimer) this._clearTimer(this._pushTimer);
        this._pushTimer = this._setTimer(() => { this._pushTimer = null; this.push().catch(() => { /* retried by the error path */ }); }, delay);
    }

    /* ============================ talking to the server ============================ */

    _url(extra = '') { return `${this.app.config.apiUrl}?action=profile_sync${extra}`; }

    /** Take the next batch out of the outbox (oldest first), small enough for one request. */
    _nextBatch(ob) {
        const items = Object.values(ob.items).sort((a, b) => a.seq - b.seq);
        const batch = []; let size = 0;
        for (const it of items) {
            const change = { section: it.section, key: it.key, op: it.op };
            if (it.op === 'set') change.value = it.value;
            const len = JSON.stringify(change).length + 1;
            if (batch.length > 0 && (size + len > MAX_BODY_CHARS || batch.length >= this._batchLimit)) break;
            batch.push({ change, seq: it.seq, id: idOf(it.section, it.key) });
            size += len;
        }
        return batch;
    }

    /** Send what is waiting, then read what changed elsewhere (the same reply carries both). Returns true when nothing is left to retry. */
    async push() {
        if (this._inFlight) { this._again = true; return false; }
        if (!this.isActive()) return false;
        if (!(await this.ensureSchema())) { this._scheduleRetry(ERROR_RETRY_MS); return false; }
        const uid = this._uid();
        const ob = this.getOutbox();
        const batch = this._nextBatch(ob);
        if (batch.length === 0) return true;
        this._inFlight = true;
        let clean = true;
        try {
            const rec = this._getCursorRecord();
            let res;
            try {
                res = await apiFetch(this._url(), {
                    method: 'POST', auth: true,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        schemaVersion: this.schema.schemaVersion,
                        client: { platform: 'web', appVersion: String(this.app.config?.version || '') },
                        cursor: rec.cursor,
                        changes: batch.map((b) => b.change),
                    }),
                });
            } catch (_e) { this._scheduleRetry(ERROR_RETRY_MS); return false; }
            if (this._uid() !== uid) return false;                  /* a different person is signed in now: this reply is not for them */
            if (res.status === 409) { await this._handleRefusal(res); return false; }
            if (res.status === 413) {                               /* too big for one request: send half as many next time */
                if (batch.length <= 1) {                            /* a single change the server will never take: drop it (the value stays on this device) */
                    const only = this.getOutbox();
                    if (only.items[batch[0].id]?.seq === batch[0].seq) { delete only.items[batch[0].id]; this._saveOutbox(only); }
                } else {
                    this._batchLimit = Math.max(1, Math.floor(batch.length / 2));
                }
                this.schedulePush(ERROR_RETRY_MS);
                return false;
            }
            if (!res.ok) {                                          /* 401, 429, 5xx ...: keep everything and try again later */
                if (res.status >= 500 || res.status === 429) this._scheduleRetry(ERROR_RETRY_MS);
                return false;
            }
            const j = await res.json();
            if (this._uid() !== uid) return false;
            /* The server has the changes: take out only those that have not changed since they were sent. */
            const after = this.getOutbox();
            for (const b of batch) {
                const cur = after.items[b.id];
                if (cur && cur.seq === b.seq) delete after.items[b.id];
            }
            /* A refused change will never be accepted as sent: do not keep retrying it (the value stays on this device). */
            for (const r of (Array.isArray(j.rejected) ? j.rejected : [])) {
                if (PERMANENT_REJECTIONS.includes(r.reason)) {
                    const sent = batch.find((b) => b.change.section === r.section && b.change.key === r.key);
                    const cur = sent ? after.items[sent.id] : null;
                    if (cur && cur.seq === sent.seq) delete after.items[sent.id];
                }
            }
            this._saveOutbox(after);
            this._applyReply(j);
            this._saveCursorFrom(j);
            if (j.more) await this.pull();
            const left = Object.keys(this.getOutbox().items).length;
            clean = left === 0;
            if (!clean) {
                /* More than one batch was waiting: go straight on. If this request made no progress at all (the server
                   kept a change we did not recognise as refused), wait before trying again instead of looping. */
                this.schedulePush(left < Object.keys(ob.items).length ? 0 : ERROR_RETRY_MS);
            }
            return clean;
        } finally {
            this._inFlight = false;
            if (this._again) { this._again = false; this.schedulePush(); }
        }
    }

    async _handleRefusal(res) {
        let reason = null;
        try { reason = (await res.clone().json()).reason || null; } catch (_e) { /* not JSON */ }
        if (reason === ACCOUNT_WRITES_OFF_REASON) { this._scheduleRetry(PAUSED_RETRY_MS); return; }   /* the outbox stays; try again in a while */
        /* profile_sync_disabled / profile_sync_not_ready: this site does not use it (yet), so the older sync carries on. */
        this._off = true;
        this.refreshActive();
    }

    _scheduleRetry(ms) {
        if (this._retryTimer) return;
        this._retryTimer = this._setTimer(() => { this._retryTimer = null; this.push().catch(() => {}); }, ms);
    }

    /** Read everything above the cursor, page by page. Returns false when it could not finish (try again later). */
    async pull() {
        if (!this.isActive()) return false;
        if (!(await this.ensureSchema())) return false;
        const uid = this._uid();
        const rec0 = this._getCursorRecord();
        /* The first read of this person on this device is a FULL read from 0 (whatever the saved cursor says), because the
           claim below needs to know everything the account has ever held. */
        const needFull = !rec0.claimed;
        let from = needFull ? 0 : rec0.cursor;
        const seen = new Set();
        for (let page = 0; page < MAX_PAGES; page++) {
            let res;
            try { res = await apiFetch(this._url('&cursor=' + from), { auth: true }); } catch (_e) { return false; }
            if (this._uid() !== uid) return false;
            if (res.status === 409) { await this._handleRefusal(res); return false; }
            if (!res.ok) return false;
            const j = await res.json();
            if (this._uid() !== uid) return false;
            for (const c of (j.changes || [])) seen.add(idOf(c.section, c.key));
            this._applyReply(j);
            this._saveCursorFrom(j);
            if (Number.isInteger(j.cursor)) from = j.cursor;
            if (!j.more) {
                if (needFull) this._claimMissing(seen);
                return true;
            }
        }
        return false;   /* more pages than we will follow in one go: the rest is read next time */
    }

    /** Pull, and push anything waiting. Used at sign-in and after a long absence. */
    async syncNow() {
        if (!this.isActive()) return false;
        if (!(await this.ensureSchema())) return false;
        const read = await this.pull();                             /* skips anything still waiting in the outbox */
        if (this.pendingCount() > 0) return (await this.push()) && read;
        return read;
    }

    _saveCursorFrom(j) {
        if (!Number.isInteger(j.cursor) || j.cursor < 0) return;
        const rec = this._getCursorRecord();
        /* The cursor only goes up, except when the server says it was too high (a restored backup). */
        rec.cursor = j.resetCursor ? j.cursor : Math.max(rec.cursor, j.cursor);
        this._saveCursorRecord(rec);
    }

    /**
     * Apply the changes of one reply, EXCEPT items still waiting in the outbox (the person's newer choice wins),
     * and without queueing anything (origin 'account').
     */
    _applyReply(j) {
        const pending = this.getOutbox().items;
        const changed = [];
        for (const c of (Array.isArray(j.changes) ? j.changes : [])) {
            if (!c || typeof c.section !== 'string' || typeof c.key !== 'string') continue;
            if (pending[idOf(c.section, c.key)]) continue;
            const provider = this.providers.forPair(c.section, c.key);
            if (!provider) continue;                                /* not a web setting, or one this build does not know: keep it on the account */
            const keys = c.deleted ? provider.remove(c.key) : provider.write(c.key, c.value);
            for (const k of keys) changed.push(k);
        }
        for (const k of changed) { try { this.app.syncStorage?.(k, { origin: 'account' }); } catch (_e) { /* the cookie sync is best effort */ } }
        if (changed.length > 0) {
            try { document.dispatchEvent(new CustomEvent(EVT_PROFILE_APPLIED, { detail: { keys: changed } })); } catch (_e) { /* no DOM */ }
        }
        return changed;
    }

    /**
     * First read of this person on this device: queue the values the account has never heard of (neither a value
     * nor a deletion) and that the entry says may be claimed. Done once.
     */
    _claimMissing(seen) {
        const rec = this._getCursorRecord();
        if (rec.claimed) return 0;
        let n = 0;
        for (const p of this.providers.all) {
            if (p.entry.claim !== true || p.isMap) continue;
            for (const { item, value } of p.localItems()) {
                if (seen.has(idOf(p.entry.section, item))) continue;
                this._enqueue(p.entry.section, item, 'set', value); n++;
            }
        }
        rec.claimed = true;
        this._saveCursorRecord(rec);
        if (n > 0) this.schedulePush(0);
        return n;
    }
}
