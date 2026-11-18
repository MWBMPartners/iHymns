/**
 * iHymns — The one list of settings that may follow a visitor between sites (#2143)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A visitor can change the theme or the text size on one iHymns site (live,
 * beta or dev) and have the others pick it up. This file is the ONLY place
 * that says WHICH settings are allowed to do that, and what a sensible value
 * for each one looks like. Anything not listed here can never travel:
 * favourites, set lists, history, sign-in tokens, consent choices, and the
 * bookkeeping each site keeps for itself.
 *
 * DETAILED
 * --------
 * Rule #35: two files that must agree need a mechanism, not a comment. Before
 * this file there were two lists that already disagreed, with nothing checking
 * them: the nine keys in `subdomain-sync.js` and the fourteen in `settings.js`
 * (`SYNC_PREF_KEYS`, which keeps a signed-in user's settings in step across
 * devices). Now there is ONE list, and both import it.
 *
 * Every key has a VALIDATOR: a function that says whether a value is one the
 * settings page could really have produced. A value that fails is dropped, so
 * a newer site that invents a new theme cannot make an older site break, and a
 * hostile page on another sub-domain cannot plant anything odd. The allowed
 * values are exported as plain arrays so a test (tests/test-settings-sync-
 * registry.js) can compare them with the real `<option>`s on the settings page
 * and fail the moment the two drift apart.
 *
 * Validators are called with a NON-EMPTY string. "No value" (a reset, or a
 * setting that was never chosen) is not a string at all: it travels as `null`
 * (a "tombstone", see settings-sync-core.js), which needs no validator.
 *
 * The keys come from `constants.js`, never typed out here, so a renamed key
 * cannot leave this list pointing at nothing.
 *
 * @see js/modules/settings-sync-core.js   the merge rules that use this list
 * @see js/modules/settings.js             SYNC_PREF_KEYS now imports CROSS_SITE_KEYS
 * @see includes/pages/settings.php        the real <option>s the lists below mirror
 * @see .claude/settings-sync-2143-plan.md the full plan (section 1.5 classifies every saved key)
 */

import {
    STORAGE_THEME,
    STORAGE_FONT_SIZE,
    STORAGE_REDUCE_MOTION,
    STORAGE_REDUCE_TRANSPARENCY,
    STORAGE_TRANSITION,
    STORAGE_DEFAULT_SONGBOOK,
    STORAGE_AUTO_UPDATE_SONGS,
    STORAGE_NUMPAD_LIVE_SEARCH,
    STORAGE_SEARCH_LYRICS,
    STORAGE_DISPLAY,
    STORAGE_CVD_MODE,
    STORAGE_LINK_EMPHASIS,
    STORAGE_KEYBOARD_SHORTCUTS,
    STORAGE_LIVE_IDLE_TIMEOUT_MINS,
} from '../constants.js';

/* ── Allowed values (exported so the guard can compare them with settings.php) ── */

/** The theme choices on the settings page. */
export const THEME_VALUES = Object.freeze(['system', 'light', 'dark', 'high-contrast']);

/** `#setting-transition`'s <option value>s. */
export const TRANSITION_VALUES = Object.freeze([
    'none', 'fade', 'crossfade', 'slide', 'scale', 'lift', 'depth', 'blur',
]);

/** `#setting-cvd-mode`'s <option value>s, apart from the empty "None" one (which is "no value"). */
export const CVD_VALUES = Object.freeze(['protanopia', 'deuteranopia', 'tritanopia', 'achromatopsia']);

/** `#setting-font-size`'s range input: min, max and step. */
export const FONT_SIZE_RANGE = Object.freeze({ min: 14, max: 28, step: 2 });

/** The live-session idle timeout, in minutes (settings.js clamps to this). */
export const IDLE_TIMEOUT_RANGE = Object.freeze({ min: 5, max: 240 });

/** The lyric-display preferences object (`ihymns_display`): its five keys and their limits. */
export const DISPLAY_LINE_SPACING_VALUES = Object.freeze(['compact', 'normal', 'spacious']);
export const DISPLAY_KEYS = Object.freeze([
    'fontSize', 'lineSpacing', 'showVerseNumbers', 'highlightChorus', 'autoScrollSpeed',
]);

/** Longest value we will ever accept or write, in characters. */
export const MAX_VALUE_LENGTH = 512;

/* ── Small helpers ── */

const isBoolText = (v) => v === 'true' || v === 'false';

/** A whole number written in plain digits, inside [min, max] and (optionally) on a step. */
function isWholeNumberText(v, min, max, step = 1, from = 0) {
    if (!/^\d{1,4}$/.test(v)) return false;
    const n = Number(v);
    return n >= min && n <= max && (n - from) % step === 0;
}

/** `ihymns_display`: JSON for a plain object with only the five known keys, each in range. */
function isDisplayPrefs(v) {
    let obj;
    try { obj = JSON.parse(v); } catch { return false; }
    if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) return false;
    for (const key of Object.keys(obj)) {
        if (!DISPLAY_KEYS.includes(key)) return false;
        const val = obj[key];
        switch (key) {
            case 'fontSize':
                if (typeof val !== 'number' || !Number.isFinite(val) || val < 0.5 || val > 5) return false;
                break;
            case 'lineSpacing':
                if (!DISPLAY_LINE_SPACING_VALUES.includes(val)) return false;
                break;
            case 'showVerseNumbers':
            case 'highlightChorus':
                if (typeof val !== 'boolean') return false;
                break;
            case 'autoScrollSpeed':
                if (typeof val !== 'number' || !Number.isFinite(val) || val < 0 || val > 1000) return false;
                break;
        }
    }
    return true;
}

/* ── The registry ── */

/**
 * key -> { validate(value) }. The ONLY list of settings allowed to travel
 * between sites. A key that is not here is never read from, or written to, the
 * shared cookie. (`Object.freeze` stops the Map being swapped for another; it
 * does not stop `.set()`, so tests also check the contents against the tree.)
 *
 * @type {ReadonlyMap<string, {validate: (value: string) => boolean}>}
 */
export const CROSS_SITE_SETTINGS = Object.freeze(new Map([
    [STORAGE_THEME,                { validate: (v) => THEME_VALUES.includes(v) }],
    [STORAGE_FONT_SIZE,            { validate: (v) => isWholeNumberText(v, FONT_SIZE_RANGE.min, FONT_SIZE_RANGE.max, FONT_SIZE_RANGE.step, FONT_SIZE_RANGE.min) }],
    [STORAGE_REDUCE_MOTION,        { validate: isBoolText }],
    [STORAGE_REDUCE_TRANSPARENCY,  { validate: isBoolText }],
    [STORAGE_TRANSITION,           { validate: (v) => TRANSITION_VALUES.includes(v) }],
    /* A songbook abbreviation is letters and digits only, 1 to 10 (rule #27). */
    [STORAGE_DEFAULT_SONGBOOK,     { validate: (v) => /^[A-Za-z0-9]{1,10}$/.test(v) }],
    [STORAGE_AUTO_UPDATE_SONGS,    { validate: isBoolText }],
    [STORAGE_NUMPAD_LIVE_SEARCH,   { validate: isBoolText }],
    [STORAGE_SEARCH_LYRICS,        { validate: isBoolText }],
    [STORAGE_DISPLAY,              { validate: isDisplayPrefs }],
    [STORAGE_CVD_MODE,             { validate: (v) => CVD_VALUES.includes(v) }],
    [STORAGE_LINK_EMPHASIS,        { validate: (v) => v === 'on' }],
    [STORAGE_KEYBOARD_SHORTCUTS,   { validate: isBoolText }],
    /* #1770 §4.7 — unprefixed on purpose (see the constant's own note in constants.js). */
    [STORAGE_LIVE_IDLE_TIMEOUT_MINS, { validate: (v) => isWholeNumberText(v, IDLE_TIMEOUT_RANGE.min, IDLE_TIMEOUT_RANGE.max) }],
]));

/** Just the keys, in a frozen array: what `settings.js` calls SYNC_PREF_KEYS. */
export const CROSS_SITE_KEYS = Object.freeze([...CROSS_SITE_SETTINGS.keys()]);

/**
 * Is this key allowed to travel, and is this value one it could really have?
 *
 * @param {string} key   A localStorage key.
 * @param {*}      value The value to check. Only a non-empty string of at most
 *                       MAX_VALUE_LENGTH characters can pass.
 * @returns {boolean}
 */
export function isShareableSetting(key, value) {
    const entry = CROSS_SITE_SETTINGS.get(key);
    if (!entry) return false;
    if (typeof value !== 'string' || value === '' || value.length > MAX_VALUE_LENGTH) return false;
    try {
        return entry.validate(value) === true;
    } catch {
        return false;
    }
}
