/**
 * account-data-owner.js - never merge one person's saved songs into another account
 * =================================================================================
 * Account/profile rewrite step A7 (epic #2180). Pure: every function takes the
 * storage to work on, so a test can drive it with a fake or a jsdom one.
 *
 * ELI5
 * ----
 * On a shared computer (a church laptop) Ben signs out. His favourites, set
 * lists, tags and history stay in the browser. Carol then signs in, and the app
 * used to treat everything in the browser as "hers" and upload it into HER
 * account, so Ben's songs appeared in Carol's account (and her "recently viewed"
 * showed his). Now the browser remembers WHOSE data it holds. When a different
 * person signs in, the previous person's data is put into one safe-keeping box
 * (a "stash") and cleared from view. If Ben comes back, his data is put back.
 *
 * Detailed
 * --------
 *  - `ihymns_data_owner_id` is recorded when an account's data is absorbed
 *    (the account now holds, and the device mirrors, that person's data).
 *  - No recorded owner = the data was made anonymously (before anyone signed in)
 *    and is claimed by whoever signs in, exactly as before.
 *  - A different owner = stash the old data under `ihymns_stash_<owner>`, clear
 *    the live keys, and restore a stash for the NEW person if one exists.
 *  - At most ONE stash exists. Creating a new one replaces the old. This bounds
 *    how much of other people's data a shared device keeps. The cost: a third
 *    person signing in discards the first person's stash. Anything that had
 *    reached the first person's account is safe there; only changes that never
 *    synced are lost, and that is the price of the bound.
 *  - If the stash cannot be written (storage full), the live data is STILL
 *    cleared: a privacy leak into someone else's account is the worse outcome.
 *  - The sync watermarks are cleared with the data but are never restored: a
 *    watermark means "this account has seen everything up to T" and must not
 *    be carried across.
 *
 * Identity, never position (rule #51): when a restored stash meets data already
 * present, items are matched by their `id`, never by array index.
 *
 * @module modules/account-data-owner
 */

import {
    STORAGE_FAVORITES, STORAGE_SETLISTS, STORAGE_CUSTOM_TAGS, STORAGE_HISTORY,
    STORAGE_SETLISTS_DELETED, STORAGE_SETLISTS_SYNCED_AT, STORAGE_FAVORITES_SYNCED_AT,
    STORAGE_FAVORITES_ADDED, STORAGE_FAVORITES_DELETED, STORAGE_RECENT_SONGBOOKS,
    STORAGE_DATA_OWNER_UID, STORAGE_STASH_PREFIX, STORAGE_SEARCH_HISTORY, STORAGE_SYNC_SEARCH_HISTORY,
    STORAGE_PROFILE_OUTBOX, STORAGE_PROFILE_CURSOR,
    STORAGE_SETLISTS_UNSENT, STORAGE_FAVORITES_UNSENT, STORAGE_CUSTOM_TAGS_UNSENT,
} from '../constants.js';

/** The personal-content keys that belong to ONE person. Stashed, cleared, restored. */
export const PERSONAL_DATA_KEYS = Object.freeze([
    STORAGE_FAVORITES, STORAGE_SETLISTS, STORAGE_CUSTOM_TAGS, STORAGE_HISTORY, STORAGE_SETLISTS_DELETED,
    /* PR 2a (2a-4): what this person added / removed on purpose and has not yet told the server. */
    STORAGE_FAVORITES_ADDED, STORAGE_FAVORITES_DELETED,
    /* PR 2b (2b-1): the songbooks this person opened lately. A personal list the account now keeps (#2177),
       so on a shared device it belongs to one person and is stashed with the rest. */
    STORAGE_RECENT_SONGBOOKS,
    /* PR 2b review (privacy): the "keep my recent searches in my account" choice, and the list it unlocks. Both belong to ONE
       person. Without them on this list, person A's choice and searches stayed behind when A signed out, and person B's browser
       then uploaded them into B's account. The LIST is only set aside while the choice is on (see searchListTravels()): while it
       is off the list is the older, device-only kind and stays where it is, as before. */
    STORAGE_SYNC_SEARCH_HISTORY, STORAGE_SEARCH_HISTORY,
    /* PR 2c review: the "has edits the account has not seen" marks. They describe the lists above, so they travel and clear with them. */
    STORAGE_SETLISTS_UNSENT, STORAGE_FAVORITES_UNSENT, STORAGE_CUSTOM_TAGS_UNSENT,
]);

/** Cleared along with the data, never restored (see the file header). */
const WATERMARK_KEYS = Object.freeze([STORAGE_SETLISTS_SYNCED_AT, STORAGE_FAVORITES_SYNCED_AT]);

/** Account ids are compared as text: the server sends a number, storage holds a string. */
const norm = (uid) => (uid === null || uid === undefined || uid === '') ? null : String(uid);

/** Safe read: private mode / blocked storage must never throw out of sign-in. */
function get(storage, key) {
    try { return storage.getItem(key); } catch (_e) { return null; }
}
function set(storage, key, value) {
    try { storage.setItem(key, value); return true; } catch (_e) { return false; }
}
function remove(storage, key) {
    try { storage.removeItem(key); } catch (_e) { /* nothing to do */ }
}

/** Is a stored JSON value empty (absent, `[]`, `{}`)? */
function isEmptyValue(raw) {
    if (raw === null || raw === '' || raw === '[]' || raw === '{}') return true;
    return false;
}

/** Whose data is on this device? null = nobody's (anonymous). */
export function readDataOwner(storage) {
    return norm(get(storage, STORAGE_DATA_OWNER_UID));
}

/** Record that the data on this device belongs to this account. */
export function recordDataOwner(storage, uid) {
    const id = norm(uid);
    if (id === null) return;
    set(storage, STORAGE_DATA_OWNER_UID, id);
}

/**
 * Is this person's recent-searches list one that belongs to their ACCOUNT (and so to them alone)? Only while they have
 * switched "Keep my recent searches in my account" on. With it off the list is the older device-only list, which is not
 * stashed, not cleared and not restored (exactly as before this option existed).
 */
function searchListTravels(storage) {
    return get(storage, STORAGE_SYNC_SEARCH_HISTORY) === 'true';
}

/** The personal keys that count right now (the searches list only while it follows the account). */
function activeKeys(storage) {
    const travels = searchListTravels(storage);
    return PERSONAL_DATA_KEYS.filter((k) => k !== STORAGE_SEARCH_HISTORY || travels);
}

/** Does the device hold any personal data at all? */
function holdsPersonalData(storage) {
    return activeKeys(storage).some((k) => !isEmptyValue(get(storage, k)));
}

/** Every stash key currently present (there should be at most one). */
function stashKeys(storage) {
    const out = [];
    try {
        for (let i = 0; i < storage.length; i++) {
            const k = storage.key(i);
            if (typeof k === 'string' && k.startsWith(STORAGE_STASH_PREFIX)) out.push(k);
        }
    } catch (_e) { /* ignore */ }
    return out;
}

/** Take a copy of the personal keys (only those that hold something). */
function snapshot(storage) {
    const data = {};
    for (const k of activeKeys(storage)) {
        const v = get(storage, k);
        if (!isEmptyValue(v)) data[k] = v;
    }
    return data;
}

/** Remove the live personal data and the watermarks. */
function clearLive(storage) {
    for (const k of activeKeys(storage)) remove(storage, k);
    for (const k of WATERMARK_KEYS) remove(storage, k);
}

/**
 * Put one stored list back, matching items by `id` (identity, never position).
 * Items already on the device win; stash items not already present are appended.
 */
function mergeRestored(currentRaw, stashedRaw) {
    if (isEmptyValue(currentRaw)) return stashedRaw;
    try {
        const cur = JSON.parse(currentRaw);
        const old = JSON.parse(stashedRaw);
        if (!Array.isArray(cur) || !Array.isArray(old)) return currentRaw;
        const keyOf = (x) => (x && typeof x === 'object' && x.id !== undefined) ? 'id:' + x.id : 'v:' + JSON.stringify(x);
        const seen = new Set(cur.map(keyOf));
        const extra = old.filter((x) => !seen.has(keyOf(x)));
        return JSON.stringify(cur.concat(extra));
    } catch (_e) {
        return currentRaw;
    }
}

/**
 * Move the given person's personal data into the single stash and clear it from
 * the live keys. Used both when a DIFFERENT person signs in and when a session
 * ends without the person choosing to sign out (token rejected or expired).
 *
 * @returns {{stashed: boolean, cleared: boolean}}
 */
export function stashAndClear(storage, ownerUid) {
    const owner = norm(ownerUid);
    const data = snapshot(storage);
    let stashed = false;
    /* At most one stash: drop every old one first. */
    for (const k of stashKeys(storage)) remove(storage, k);
    if (owner !== null && Object.keys(data).length > 0) {
        stashed = set(storage, STORAGE_STASH_PREFIX + owner, JSON.stringify(data));
    }
    clearLive(storage);
    remove(storage, STORAGE_DATA_OWNER_UID);
    return { stashed, cleared: true };
}

/**
 * A person SIGNS OUT on purpose (PR 2c-3, owner decision O2): clear this browser's copy of their personal data.
 * No set-aside copy is made: the account holds it, and a copy left behind is exactly what the next person on a
 * shared computer must not find. (The question "has everything reached your account?" is asked BEFORE this, by
 * sign-out-guard.js.)
 *
 * Removes: every personal key (favourites, set lists, custom tags, history, recent songbooks, the pending removals
 * and additions, the search-history choice and, only when that choice was on, the list itself), both sync
 * bookmarks, the profile sync's outbox and cursor, and the record of whose data this is. Display settings (theme,
 * text size, language filter, per-song keys) are NOT personal data and stay.
 *
 * Idempotent, never throws (blocked storage must not stop a sign-out).
 */
export function clearPersonalDataAtSignOut(storage) {
    clearLive(storage);
    remove(storage, STORAGE_PROFILE_OUTBOX);
    remove(storage, STORAGE_PROFILE_CURSOR);
    remove(storage, STORAGE_DATA_OWNER_UID);
}

/** Put a person's stash (if any) back into the live keys, then delete it. */
function restoreStash(storage, uid) {
    const key = STORAGE_STASH_PREFIX + uid;
    const raw = get(storage, key);
    if (raw === null) return false;
    remove(storage, key);
    let data;
    try { data = JSON.parse(raw); } catch (_e) { return false; }
    if (!data || typeof data !== 'object') return false;
    for (const k of PERSONAL_DATA_KEYS) {
        if (typeof data[k] !== 'string') continue;
        if (k === STORAGE_SEARCH_HISTORY) continue;   /* handled below: it is put back whole, never merged with whoever used the browser meanwhile */
        const merged = mergeRestored(get(storage, k), data[k]);
        set(storage, k, merged);
    }
    if (data[STORAGE_SYNC_SEARCH_HISTORY] === 'true') {
        /* This person's choice is on, so their list is THEIR list: put it back exactly (or, if they had none, leave none),
           never mixed with a device-only list somebody else typed while they were away. */
        if (typeof data[STORAGE_SEARCH_HISTORY] === 'string') set(storage, STORAGE_SEARCH_HISTORY, data[STORAGE_SEARCH_HISTORY]);
        else remove(storage, STORAGE_SEARCH_HISTORY);
    }
    return true;
}

/**
 * Run when a person is signed in and about to have the device's data reconciled
 * with their account. Idempotent: safe to call again and again.
 *
 * @param {Storage} storage
 * @param {unknown} currentUid  the signed-in account id
 * @returns {{action: 'none'|'claim'|'same'|'switched', stashed?: boolean, restored?: boolean}}
 *   none    - no signed-in id, nothing done.
 *   claim   - no recorded owner: anonymous data, claimed by this person (as before) and now
 *             recorded as theirs, after restoring their own stash if they have one.
 *   same    - the recorded owner is this person.
 *   switched- the recorded owner was someone else: their data was stashed and cleared.
 */
export function reconcileDataOwner(storage, currentUid) {
    const uid = norm(currentUid);
    if (uid === null) return { action: 'none' };
    const owner = readDataOwner(storage);

    if (owner === uid) return { action: 'same' };

    if (owner === null) {
        /* Nobody's data: anonymous. The removals noted while nobody was signed in were never
           about THIS person's account (the device had no account then), so whoever signs in first
           must not have them applied to their account: drop the lists of pending removals and
           keep the favourites and set lists themselves. Done BEFORE the stash is given back, so
           a returning person's own earlier removals are not touched. */
        remove(storage, STORAGE_FAVORITES_DELETED);
        remove(storage, STORAGE_SETLISTS_DELETED);
        /* The person may be coming back after a session ended on its own; give them their own
           stash back. */
        const restored = restoreStash(storage, uid);
        /* Claim it now, not only when a sync later succeeds: if that sync fails
           (offline) and somebody else signs in next, this data must not be
           treated as theirs. */
        recordDataOwner(storage, uid);
        return { action: 'claim', restored };
    }

    /* Someone else's data is here. Keep this person's own stash aside while we
       swap: read it first, because stashAndClear() replaces every stash. */
    const ownStash = get(storage, STORAGE_STASH_PREFIX + uid);
    const result = stashAndClear(storage, owner);
    /* The device now holds nothing personal; from here it is this person's. */
    recordDataOwner(storage, uid);
    if (ownStash !== null) {
        set(storage, STORAGE_STASH_PREFIX + uid, ownStash);
        /* stashAndClear() just wrote the previous person's stash as THE single
           stash; restoring this person's removes theirs, leaving only the
           previous person's. */
        const restored = restoreStash(storage, uid);
        return { action: 'switched', stashed: result.stashed, restored };
    }
    return { action: 'switched', stashed: result.stashed, restored: false };
}

/**
 * A session ended without the person asking to sign out (the server rejected the
 * token, or it expired). Put their data aside so the next person on this device
 * never sees it; if the same person signs back in, reconcileDataOwner() restores it.
 * The owner is the recorded data owner, falling back to the account that was
 * signed in. With no known owner there is nothing personal to protect.
 */
export function protectOnUnplannedSignOut(storage, signedInUid) {
    const owner = readDataOwner(storage) ?? norm(signedInUid);
    /* No known owner (or nothing to protect): leave the data alone. Clearing
       data we cannot attribute to anyone would only destroy a visitor's own
       anonymous work. */
    if (owner === null || !holdsPersonalData(storage)) return { stashed: false, cleared: false };
    return stashAndClear(storage, owner);
}
