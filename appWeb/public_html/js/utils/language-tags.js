/**
 * js/utils/language-tags.js — the browser's small share of the shared
 * language policy (MWBM-MEDIA-LANG, #2137)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A person can tell iHymns which languages they read ("English, then Brazilian
 * Portuguese"). This file holds the few rules the BROWSER needs to respect
 * that list:
 *   - which saved values are plausible language tags at all;
 *   - which language GROUP a tag belongs to (`pt-BR` is Portuguese), because
 *     the language filter matches by group — a `pt-BR` preference still shows
 *     `pt` and `pt-PT` songs;
 *   - keeping the ORDER the person chose when they tick and untick boxes, and
 *     putting their languages first when a list is first drawn.
 *
 * Everything else — tidying tags, the stored order, names — is done on the
 * server by the shared PHP code (includes/media_language.php). The policy's
 * section 9 says browser code should leave ordering and matching to the server
 * or a port of it; this file is deliberately NOT a port. It never decides what
 * is stored; it only orders what the server already sent, for one person, and
 * it never moves an item because it was just selected (policy UI-050).
 *
 * @see docs/standards/media-language-bcp47-policy.md  UI-020, UI-045, UI-050
 * @see includes/language_filter.php                   the server side of the same filter
 */

/**
 * The shape of a language tag worth sending or storing: letters and digits in
 * hyphen-separated parts of 1–8 characters (`en`, `pt-BR`, `zh-Hant-TW`,
 * `x-hymnal`). Deliberately loose — the server tidies and checks properly; this
 * only keeps junk (an empty string, a stray comma, a header-injection attempt)
 * out of the saved list and the request header.
 *
 * #2137 — this replaced `/^[a-z]{2,3}$/` in three places, which silently threw
 * away any saved preference with a region or script (`pt-BR` vanished).
 */
const PREFERENCE_TAG_RE = /^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$/;

/**
 * Is this a plausible language tag to keep in a preference list?
 *
 * @param {unknown} value
 * @returns {boolean}
 */
export function isPreferenceTag(value) {
    return typeof value === 'string' && value.length <= 35 && PREFERENCE_TAG_RE.test(value);
}

/**
 * The language GROUP a tag belongs to, lower-cased: its first part for an
 * ordinary tag (`pt-BR` → `pt`), or the whole tag for a private-use or old
 * "grandfathered" one (`x-hymnal`, `i-default`), matching the server's
 * mediaLanguageGroup() and the policy's UI-020.
 *
 * @param {string} tag
 * @returns {string}
 */
export function languageGroupOf(tag) {
    const t = String(tag || '').trim().toLowerCase();
    if (t === '') return '';
    const first = t.split('-', 1)[0];
    return (first === 'x' || first === 'i') ? t : first;
}

/**
 * The script a tag names, lower-case (`zh-Hant-TW` → `hant`), or '' when it
 * names none. The script comes after the language and up to three extlangs,
 * and is the only four-letter subtag there (BCP 47 section 2.1).
 *
 * @param {string} tag
 * @returns {string}
 */
export function scriptOf(tag) {
    const m = /^[a-z]{2,8}(?:-[a-z]{3}){0,3}-([a-z]{4})(?:-|$)/i.exec(String(tag || '').trim());
    return m ? m[1].toLowerCase() : '';
}

/**
 * Does content in language `tag` suit a reader who chose `pref`?
 * (#2137 review — the shared policy's MATCH-040.)
 *
 * ELI5: the same language is enough (`pt-BR` suits `pt` and `pt-PT` content),
 * unless both name a script and the scripts differ: a reader who chose
 * Chinese in Simplified characters (`zh-Hans`) is not shown content written
 * in Traditional characters (`zh-Hant`). A preference with no script
 * (`zh`, `zh-TW`) suits every form. Mirrors includes/language_filter.php
 * (the server's SQL and in-memory filters), which the server tests row for
 * row; `und`, `mul` and `zxx` are the caller's business (always shown).
 *
 * @param {string} pref A saved preference tag.
 * @param {string} tag  The content's language tag.
 * @returns {boolean}
 */
export function preferenceMatchesTag(pref, tag) {
    const group = languageGroupOf(pref);
    if (group === '' || group !== languageGroupOf(tag)) return false;
    const want = scriptOf(pref);
    const have = scriptOf(tag);
    return !(want && have && want !== have);
}

/**
 * Keep the person's saved priority order when boxes are ticked or unticked.
 *
 * ELI5: the saved list is ["pt-BR", "en"]. The person unticks nothing and
 * ticks "Spanish": the new list is ["pt-BR", "en", "es"] — their first two
 * choices stay first, the new one goes last. They untick "Portuguese": every
 * saved tag in the Portuguese group goes, and the rest keep their order. A
 * saved `pt-BR` is never collapsed to `pt` just because the checkbox says `pt`.
 *
 * @param {string[]} saved          The saved preference list, highest priority first.
 * @param {string[]} checkedGroups  The language groups whose boxes are ticked now.
 * @returns {string[]} The new preference list.
 */
export function mergePreferenceOrder(saved, checkedGroups) {
    const checked = new Set(checkedGroups.map((g) => String(g).toLowerCase()));
    const out = [];
    const coveredGroups = new Set();
    for (const tag of saved) {
        const group = languageGroupOf(tag);
        if (checked.has(group) && !out.includes(tag)) {
            out.push(tag);
            coveredGroups.add(group);
        }
    }
    for (const group of checkedGroups) {
        const g = String(group).toLowerCase();
        if (!coveredGroups.has(g)) {
            out.push(g);
            coveredGroups.add(g);
        }
    }
    return out;
}

/**
 * Order a list for one person: items in their language groups first, in
 * their priority order; within a group, an exact match for a tag they listed
 * first; everything else after, in the order the server sent it (the server
 * already sorted the rest by name and put the original first — UI-030/UI-040).
 *
 * Stable: items that tie keep the server's order (policy LANG-027 / UI-045).
 *
 * @template T
 * @param {T[]} items
 * @param {string[]} preferences  Highest priority first.
 * @param {(item: T) => string} tagOf
 * @returns {T[]} A new, reordered array.
 */
export function orderByPreference(items, preferences, tagOf) {
    const prefs = preferences.filter(isPreferenceTag);
    if (prefs.length === 0) return items.slice();
    const groupRank = new Map();
    const exactRank = new Map();
    prefs.forEach((tag, i) => {
        const g = languageGroupOf(tag);
        if (!groupRank.has(g)) groupRank.set(g, groupRank.size);
        const exact = tag.toLowerCase();
        if (!exactRank.has(exact)) exactRank.set(exact, i);
    });
    const keyed = items.map((item, index) => {
        const tag = String(tagOf(item) || '');
        const g = groupRank.has(languageGroupOf(tag)) ? groupRank.get(languageGroupOf(tag)) : Infinity;
        const e = exactRank.has(tag.toLowerCase()) ? exactRank.get(tag.toLowerCase()) : Infinity;
        return { item, index, g, e };
    });
    keyed.sort((a, b) => (a.g - b.g) || (a.g === Infinity ? 0 : (a.e - b.e)) || (a.index - b.index));
    return keyed.map((k) => k.item);
}
