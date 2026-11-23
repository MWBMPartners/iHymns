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

import { decodeWebValue, encodeWebValue } from './profile-web-codec.js';

/** Hand-written providers for settings that do not fit "one storage key, one value". Filled in by the 2b-6 steps. @type {Record<string, (entry: object, storage: Storage) => object>} */
export const ADAPTERS = {};

/** The registry entry ids that have a hand-written provider (a test checks every web entry has a storage key OR is listed here). */
export const ADAPTER_ENTRY_IDS = Object.freeze(Object.keys(ADAPTERS));

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
