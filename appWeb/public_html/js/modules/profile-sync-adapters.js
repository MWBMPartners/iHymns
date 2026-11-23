/**
 * profile-sync-adapters.js - how each personal setting is read from, and written to, this browser
 * ============================================================================================
 * Account/profile rewrite, PR 2b step 2b-5 (epic #2180). The sync engine (profile-sync.js) knows
 * nothing about where a setting lives; it asks a "provider" built here.
 *
 * ELI5
 * ----
 * The account keeps a list of personal settings (the `profile_schema` the server hands out). Each one
 * needs a small translator that knows where THIS browser keeps it and how to turn the stored words into
 * the real value and back. Most settings are simple: one browser storage key, one value. For those the
 * translator is built automatically from the downloaded list (every entry that names its old web storage
 * key), so no list of settings is typed in any web file. A few are not simple (a key per song, a list of
 * recent searches the person must switch on); those have their own hand-written translator, registered in
 * ADAPTERS below, and a test checks that every web setting has one or the other.
 *
 * Detailed
 * --------
 * A provider is:
 *   id            the registry id (`appearance.theme`, `songPrefs.*`)
 *   entry         the registry entry
 *   isMap         true for a section of many items (songPrefs)
 *   matchKey(k)   the item key if the browser storage key `k` belongs to this provider, else null
 *   read(item)    {state:'absent'} | {state:'invalid'} | {state:'value', value}
 *   write(item,v) store a real value; returns the storage keys it changed (empty = nothing to do)
 *   remove(item)  take the value away; returns the storage keys it changed
 *   localItems()  every item currently held here, for the one-time "claim" at first sign-in
 * An item key for an ordinary entry is the entry's own `key`; for a map it is the item's key (a song id).
 *
 * Values come back from `read` already checked against the registry. A value that is not valid is
 * reported as `invalid` and is never sent: a bad local value must not become a delete on the account.
 *
 * @module modules/profile-sync-adapters
 * @see js/modules/profile-web-codec.js  the text <-> value translator (twin of the server's)
 */

import { decodeWebValue, encodeWebValue, validateSpec } from './profile-web-codec.js';
import { STORAGE_TRANSPOSE_PREFIX, STORAGE_CAPO_PREFIX, STORAGE_OCTAVE_PREFIX, STORAGE_SEARCH_HISTORY, STORAGE_SYNC_SEARCH_HISTORY } from '../constants.js';

/**
 * The key, capo and octave chosen for each song are kept as THREE storage keys per song (a prefix plus the song id), each removed
 * when it returns to zero. On the account they are ONE item per song: {transpose, capo, octave}. (#2177)
 */
export const SONG_PREF_PREFIXES = Object.freeze({ transpose: STORAGE_TRANSPOSE_PREFIX, capo: STORAGE_CAPO_PREFIX, octave: STORAGE_OCTAVE_PREFIX });

/**
 * The provider for `songPrefs.*`: one account item per song, three storage keys per song here.
 *
 * ELI5: a song's key, capo and octave are saved as three separate small notes in the browser. For the account they travel
 * together. This puts the three notes together when reading, and splits them again when writing. All three at zero means
 * "no choice", which is a delete, not a set, and writing zero removes the note (as the page itself does).
 */
function songPrefsProvider(entry, storage) {
    const idRe = new RegExp('^(?:' + String(entry.itemKeyPattern).replace(/^\^/, '').replace(/\$$/, '') + ')$');
    const names = Object.keys(SONG_PREF_PREFIXES);
    const get = (k) => { try { return storage.getItem(k); } catch (_e) { return null; } };
    const whole = (txt) => {                         // the same reading the page uses: whole number, anything else is zero
        const n = parseInt(txt, 10);
        return Number.isNaN(n) ? 0 : n;
    };
    const songOf = (storageKey) => {
        for (const prefix of Object.values(SONG_PREF_PREFIXES)) {
            if (storageKey.startsWith(prefix)) {
                const id = storageKey.slice(prefix.length);
                return idRe.test(id) ? id : null;
            }
        }
        return null;
    };
    return {
        id: entry.id,
        entry,
        isMap: true,
        matchKey: (k) => (typeof k === 'string' ? songOf(k) : null),
        read(songId) {
            if (!idRe.test(songId)) return { state: 'invalid' };
            const value = {};
            for (const n of names) value[n] = whole(get(SONG_PREF_PREFIXES[n] + songId));
            if (names.every((n) => value[n] === 0)) return { state: 'absent' };
            return validateSpec(entry.item, value) ? { state: 'value', value } : { state: 'invalid' };
        },
        write(songId, value) {
            if (!idRe.test(songId) || !validateSpec(entry.item, value)) return [];
            const changed = [];
            for (const n of names) {
                const k = SONG_PREF_PREFIXES[n] + songId;
                const want = Number.isInteger(value[n]) ? value[n] : 0;
                const before = get(k);
                try { if (want === 0) storage.removeItem(k); else storage.setItem(k, String(want)); } catch (_e) { continue; }
                if (get(k) !== before) changed.push(k);
            }
            return changed;
        },
        remove(songId) {
            if (!idRe.test(songId)) return [];
            const changed = [];
            for (const n of names) {
                const k = SONG_PREF_PREFIXES[n] + songId;
                if (get(k) === null) continue;
                try { storage.removeItem(k); changed.push(k); } catch (_e) { /* nothing to do */ }
            }
            return changed;
        },
        localItems() {
            const ids = new Set();
            try {
                for (let i = 0; i < storage.length; i++) {
                    const id = songOf(storage.key(i) || '');
                    if (id !== null) ids.add(id);
                }
            } catch (_e) { /* nothing */ }
            const out = [];
            for (const id of ids) { const r = this.read(id); if (r.state === 'value') out.push({ item: id, value: r.value }); }
            return out;
        },
    };
}

/**
 * The provider for `history.searches`: the recent searches, which travel ONLY while the person has switched
 * "Keep my recent searches in my account" on. (#2177)
 *
 * ELI5: what someone searched for is private. By default it never leaves the device. Only when the person switches the
 * option on does this list read as a value (so it can be sent); with the option off it reads as "off", which the sync
 * treats as "send nothing, and do not touch the account's copy". Turning the option off deletes the account's copy (the
 * sync does that when the switch is flipped). A search longer than 100 characters is cut, and at most 20 are kept, so the
 * list always fits the account's rules.
 */
function searchHistoryProvider(entry, storage) {
    const get = (k) => { try { return storage.getItem(k); } catch (_e) { return null; } };
    const optionOn = () => get(STORAGE_SYNC_SEARCH_HISTORY) === 'true';
    const tidy = (raw) => {
        let list;
        try { list = JSON.parse(raw); } catch (_e) { return null; }
        if (!Array.isArray(list)) return null;
        const out = [];
        for (const q of list) {
            if (typeof q !== 'string') continue;
            const t = Array.from(q.trim()).slice(0, 100).join('');
            if (t !== '' && !out.includes(t)) out.push(t);
            if (out.length >= 20) break;
        }
        return out;
    };
    return {
        id: entry.id,
        entry,
        isMap: false,
        matchKey: (k) => (k === STORAGE_SEARCH_HISTORY ? entry.key : null),
        read() {
            if (!optionOn()) return { state: 'off' };
            const raw = get(STORAGE_SEARCH_HISTORY);
            if (raw === null) return { state: 'absent' };
            const list = tidy(raw);
            if (list === null) return { state: 'invalid' };
            if (list.length === 0) return { state: 'absent' };
            return validateSpec(entry, list) ? { state: 'value', value: list } : { state: 'invalid' };
        },
        write(_item, value) {
            if (!optionOn() || !validateSpec(entry, value)) return [];
            const text = JSON.stringify(value);
            if (get(STORAGE_SEARCH_HISTORY) === text) return [];
            try { storage.setItem(STORAGE_SEARCH_HISTORY, text); } catch (_e) { return []; }
            return [STORAGE_SEARCH_HISTORY];
        },
        remove() {
            if (!optionOn() || get(STORAGE_SEARCH_HISTORY) === null) return [];
            try { storage.removeItem(STORAGE_SEARCH_HISTORY); } catch (_e) { return []; }
            return [STORAGE_SEARCH_HISTORY];
        },
        localItems() { return []; },   /* never claimed: it is private, and only travels once the person has said so */
    };
}

/** The "keep my recent searches in my account" switch itself: an ordinary true/false setting kept under its own storage key. */
function syncSearchHistoryProvider(entry, storage) {
    return storageKeyProvider({ ...entry, legacyWebKey: STORAGE_SYNC_SEARCH_HISTORY }, storage);
}

/** Hand-written providers for settings that do not fit "one storage key, one value", keyed by registry entry id. @type {Record<string, (entry: object, storage: Storage) => object>} */
export const ADAPTERS = {
    'songPrefs.*': songPrefsProvider,
    'history.searches': searchHistoryProvider,
    'privacy.syncSearchHistory': syncSearchHistoryProvider,
};

/** The registry entry ids that have a hand-written provider (a test checks every web entry has a storage key OR is listed here). */
export const ADAPTER_ENTRY_IDS = Object.freeze(Object.keys(ADAPTERS));

/** The storage keys that only hand-written providers know about (a test checks every write of one of them tells the sync). */
export const ADAPTER_STORAGE_KEYS = Object.freeze([STORAGE_SEARCH_HISTORY, STORAGE_SYNC_SEARCH_HISTORY]);

/** Does this entry apply on the web at all? (An Apple-only setting is kept for the account but never applied here.) */
export function appliesOnWeb(entry) {
    const pl = Array.isArray(entry && entry.platforms) ? entry.platforms : [];
    return pl.includes('all') || pl.includes('web');
}

/** Does this entry travel through profile_sync for a signed-in person? */
export function isProfileItem(entry) {
    return !!entry && entry.sync === 'profile_sync' && entry.scope === 'account';
}

/** The provider for an ordinary setting: one storage key holding the value as text. */
export function storageKeyProvider(entry, storage) {
    const webKey = entry.legacyWebKey;
    const get = () => { try { return storage.getItem(webKey); } catch (_e) { return null; } };
    return {
        id: entry.id,
        entry,
        isMap: false,
        matchKey: (k) => (k === webKey ? entry.key : null),
        read() {
            const raw = get();
            if (raw === null) return { state: 'absent' };
            const v = decodeWebValue(entry, raw);
            return v === undefined ? { state: 'invalid' } : { state: 'value', value: v };
        },
        write(_item, value) {
            const text = encodeWebValue(entry, value);
            if (get() === text) return [];
            try { storage.setItem(webKey, text); } catch (_e) { return []; }
            return [webKey];
        },
        remove() {
            if (get() === null) return [];
            try { storage.removeItem(webKey); } catch (_e) { return []; }
            return [webKey];
        },
        localItems() {
            const r = this.read();
            return r.state === 'value' ? [{ item: entry.key, value: r.value }] : [];
        },
    };
}

/**
 * Build every provider the web needs from the downloaded `profile_schema`.
 *
 * @param {{entries: object[]}} schema the profile_schema answer
 * @param {Storage} storage the browser's storage
 * @returns {{ all: object[], forPair: (section: string, key: string) => (object|null), forStorageKey: (k: string) => ({provider: object, item: string}|null) }}
 */
export function buildProviders(schema, storage) {
    const byPair = new Map();       // `${section}.${key}` -> provider
    const mapBySection = new Map(); // section -> provider (a map entry)
    const all = [];
    for (const entry of (schema && Array.isArray(schema.entries) ? schema.entries : [])) {
        if (!isProfileItem(entry) || !appliesOnWeb(entry)) continue;
        let provider = null;
        if (Object.prototype.hasOwnProperty.call(ADAPTERS, entry.id)) provider = ADAPTERS[entry.id](entry, storage);
        else if (typeof entry.legacyWebKey === 'string' && entry.legacyWebKey !== '' && entry.type !== 'map') provider = storageKeyProvider(entry, storage);
        if (!provider) continue;
        all.push(provider);
        if (provider.isMap) mapBySection.set(entry.section, provider);
        else byPair.set(entry.section + '.' + entry.key, provider);
    }
    return {
        all,
        forPair(section, key) {
            return byPair.get(section + '.' + key) || mapBySection.get(section) || null;
        },
        forStorageKey(k) {
            for (const p of all) {
                const item = p.matchKey(k);
                if (item !== null && item !== undefined) return { provider: p, item };
            }
            return null;
        },
    };
}
