/**
 * iHymns — The merge rules for settings that follow a visitor between sites (#2143)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Two of iHymns's sites each hold a copy of your display settings, and each
 * copy may have changed since they last met. This file answers one question for
 * each setting: "which copy is newer?". The newer one wins, setting by setting,
 * so changing your theme on one site never undoes a text-size change you made
 * on another. A reset is remembered too (a "this was deleted" marker, called a
 * tombstone), so it is not undone by a site that still has the old value.
 *
 * DETAILED
 * --------
 * PURE on purpose: no page, no cookies, no localStorage. It takes plain values
 * and returns plain values, so it can be tested with a small truth table
 * (tests/test-settings-sync-core.js) and cannot be broken by a browser quirk.
 * The cookie and storage work is in settings-sync.js, which calls this file.
 *
 * An "entry" is a two-item array `[value, stamp]`:
 *   - value: the setting as text, or `null` for a tombstone ("deleted/reset");
 *   - stamp: a whole-number time, in milliseconds, of when it last changed.
 * A "map" is a `Map` of setting key -> entry, holding only keys in the registry
 * (settings-sync-registry.js). Anything else is thrown away on the way in.
 *
 * THE RULES
 *   - Newer stamp wins, setting by setting. A tie keeps the local value.
 *   - A newer remote value is only adopted if it passes that setting's check
 *     (a tombstone needs none). A value that fails is ignored, which is how a
 *     newer site inventing a new value cannot break an older one.
 *   - Stamps come from one browser on one machine, so they share one clock. If
 *     the clock is ever set backwards, `nextStamp()` still moves forward from the
 *     highest stamp already seen, so a newer change is never "older" than an
 *     earlier one.
 *
 * SAFE PARSING (the cookie is text that any sub-domain can write):
 *   - the text is read with JSON.parse inside try, and must be a plain object;
 *   - ONLY the registry's keys are looked up, with Object.hasOwn(). The parsed
 *     object is never looped over, spread or Object.assign-ed, so a key named
 *     `__proto__` or `constructor` has nowhere to go (this closes "prototype
 *     pollution", where data changes how every object in the page behaves);
 *   - results are collected in a Map, not a plain object;
 *   - stamps must be whole numbers from 0 to one day in the future, values must
 *     be text of at most 512 characters or null;
 *   - text over 4096 characters is ignored, and an encoded cookie over 3500 is
 *     never written (cookies are capped near 4096 bytes).
 *
 * @see js/modules/settings-sync-registry.js   the list and the per-setting checks
 * @see js/modules/settings-sync.js            the cookie transport that uses this
 * @see .claude/settings-sync-2143-plan.md     sections 2.3 and 3.1
 * @link https://developer.mozilla.org/docs/Web/JavaScript/Reference/Global_Objects/Object/hasOwn
 */

import { CROSS_SITE_KEYS, isShareableSetting } from './settings-sync-registry.js';

/** The envelope format version written into the cookie. */
export const ENVELOPE_VERSION = 2;

/** Longest encoded cookie value we will write (the cookie limit is about 4096 bytes). */
export const MAX_ENCODED_LENGTH = 3500;

/** Longest cookie text we will even try to read. */
export const MAX_DECODE_LENGTH = 4096;

/** A stamp may be at most this far ahead of now (one day), to ignore nonsense from a broken clock. */
const MAX_FUTURE_MS = 24 * 60 * 60 * 1000;

/**
 * The next stamp to use: now, but never less than one past the highest stamp
 * already seen. So a clock set backwards cannot make a new change look older.
 *
 * @param {number} highestSeen The highest stamp seen so far (0 if none).
 * @returns {number}
 */
export function nextStamp(highestSeen) {
    const seen = Number.isFinite(highestSeen) ? Math.floor(highestSeen) : 0;
    return Math.max(Date.now(), seen + 1);
}

/** The highest stamp in any of the given maps (0 if they are all empty). */
export function highestStamp(...maps) {
    let high = 0;
    for (const map of maps) {
        if (!(map instanceof Map)) continue;
        for (const entry of map.values()) {
            if (Array.isArray(entry) && Number.isFinite(entry[1]) && entry[1] > high) high = entry[1];
        }
    }
    return high;
}

/**
 * Turn the cookie's value (URL-encoded text) into a Map of registry key -> entry.
 * NEVER throws. Anything wrong with the text, or with one entry, is dropped.
 *
 * @param {string|null|undefined} text The raw cookie value.
 * @returns {Map<string, [string|null, number]>}
 */
export function decodeEnvelope(text) {
    const result = new Map();
    if (typeof text !== 'string' || text === '' || text.length > MAX_DECODE_LENGTH) return result;

    let parsed;
    try {
        parsed = JSON.parse(decodeURIComponent(text));
    } catch {
        return result;
    }
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return result;
    const k = Object.hasOwn(parsed, 'k') ? parsed.k : null;
    if (k === null || typeof k !== 'object' || Array.isArray(k)) return result;

    const latest = Date.now() + MAX_FUTURE_MS;
    /* Walk OUR keys and look each up. Never loop over what the cookie contains. */
    for (const key of CROSS_SITE_KEYS) {
        if (!Object.hasOwn(k, key)) continue;
        const entry = k[key];
        if (!Array.isArray(entry) || entry.length !== 2) continue;
        const [value, stamp] = entry;
        if (!Number.isInteger(stamp) || stamp < 0 || stamp > latest) continue;
        if (value === null) {
            result.set(key, [null, stamp]);
        } else if (isShareableSetting(key, value)) {
            result.set(key, [value, stamp]);
        }
    }
    return result;
}

/**
 * Turn a Map of entries into the cookie's value (URL-encoded text), or `null`
 * if it would be too long to store safely. Only registry keys are written.
 *
 * @param {Map<string, [string|null, number]>} map
 * @returns {string|null}
 */
export function encodeEnvelope(map) {
    const k = {};
    for (const key of CROSS_SITE_KEYS) {
        const entry = map instanceof Map ? map.get(key) : undefined;
        if (!Array.isArray(entry)) continue;
        const [value, stamp] = entry;
        if (!Number.isInteger(stamp) || stamp < 0) continue;
        if (value !== null && !isShareableSetting(key, value)) continue;
        k[key] = [value, stamp];
    }
    const text = encodeURIComponent(JSON.stringify({ v: ENVELOPE_VERSION, k }));
    return text.length > MAX_ENCODED_LENGTH ? null : text;
}

/**
 * Decide, setting by setting, which copy is newer.
 *
 * A key missing on one side counts as older than any entry on the other. For
 * each registry key:
 *   - remote newer (and a tombstone, or a value that passes its check): adopt it here;
 *   - local newer: it should be written back to the shared place;
 *   - equal stamps: keep the local value and do nothing.
 *
 * @param {Map<string, [string|null, number]>} local  What this site holds (with its own stamps).
 * @param {Map<string, [string|null, number]>} remote What the shared cookie holds.
 * @returns {{applyLocal: Map, writeRemote: Map}} `applyLocal`: entries this site should adopt
 *   (a `null` value means "remove the local setting"). `writeRemote`: entries the shared
 *   cookie should take.
 */
export function mergeEntries(local, remote) {
    const applyLocal = new Map();
    const writeRemote = new Map();
    for (const key of CROSS_SITE_KEYS) {
        const l = local instanceof Map ? local.get(key) : undefined;
        const r = remote instanceof Map ? remote.get(key) : undefined;
        const lStamp = Array.isArray(l) ? l[1] : -1;
        const rStamp = Array.isArray(r) ? r[1] : -1;

        if (rStamp > lStamp) {
            /* The remote copy is newer. Adopt it only if it is a tombstone or passes its check. */
            if (r[0] === null || isShareableSetting(key, r[0])) applyLocal.set(key, [r[0], r[1]]);
        } else if (lStamp > rStamp) {
            if (l[0] === null || isShareableSetting(key, l[0])) writeRemote.set(key, [l[0], l[1]]);
        }
        /* equal: keep local, nothing to do */
    }
    return { applyLocal, writeRemote };
}
