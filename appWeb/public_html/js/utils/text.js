/**
 * iHymns — Text Utility Functions
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shared text-formatting helpers used across multiple modules.
 */

/**
 * Convert a string to Title Case following English capitalisation rules.
 * Minor words (articles, conjunctions, short prepositions) are lowercased
 * unless they are the first or last word. Hyphenated parts are each capitalised.
 *
 * @param {string} str The input string (may be ALL CAPS, lowercase, or mixed)
 * @returns {string} The title-cased string
 */
const MINOR_WORDS = new Set([
    'a','an','and','as','at','but','by','for','in','nor',
    'of','on','or','so','the','to','up','yet',
]);

/* Words following these punctuation marks start a new clause and are capitalised
   regardless of the minor-word rule (e.g. "Alas! And Did My Saviour Bleed?"). */
const CLAUSE_BREAK = /[.!?:—–]$/;

function capFirstLetter(word) {
    /* Skip leading quotes/punctuation (e.g. "come → "Come) and uppercase the first letter. */
    const m = word.match(/^([^\p{L}]*)(\p{L})(.*)$/u);
    return m ? m[1] + m[2].toUpperCase() + m[3] : word;
}

function stripPunct(word) {
    return word.replace(/[^\p{L}\p{N}']/gu, '');
}

export function toTitleCase(str) {
    if (!str) return str || '';
    const words = str.toLowerCase().split(/\s+/);
    const last = words.length - 1;
    return words
        .map((word, i) => {
            const newClause = i > 0 && CLAUSE_BREAK.test(words[i - 1]);
            const isMinor = MINOR_WORDS.has(stripPunct(word));
            if (i === 0 || i === last || newClause || !isMinor) {
                word = capFirstLetter(word);
            }
            /* Capitalise each part of hyphenated words */
            return word.replace(/-\w/g, m => m.toUpperCase());
        })
        .join(' ');
}

/* Special letters that iconv ASCII//TRANSLIT folds but Unicode NFD does NOT
   decompose (they are distinct letters, not base+combining-mark) — the server
   fold reaches them via iconv, so the client mirror needs them explicitly to
   fold the headline "Miłość → milosc" class offline. */
const FOLD_SPECIAL = {
    'ł': 'l', 'ø': 'o', 'đ': 'd', 'æ': 'ae', 'œ': 'oe',
    'ħ': 'h', 'ß': 'ss', 'ð': 'd', 'þ': 'th', 'ı': 'i',
};

/**
 * foldSearchText — the CLIENT mirror of the server ihymns_search_fold() (#1039
 * Part A), for the OFFLINE slim-index search fallback. Diacritic- and
 * apostrophe-insensitive: "Miłość" / "Noël" / "aren’t" all fold to "milosc" /
 * "noel" / "arent", so a reader typing plain ASCII still matches offline.
 *
 * It need NOT be byte-identical to the PHP fold — the offline path folds BOTH
 * the query and the candidate at compare time, so there is no stored-value
 * contract to honour; it only has to fold the two sides the SAME way. Steps
 * mirror the server's (#1908 — 'NFD'→'NFKD' so the two are step-identical,
 * incl. full-width Latin/ideographic-space folding): lowercase, strip
 * combining marks (NFKD), map the non-decomposing special letters, then drop
 * everything that is not a letter, number or space (which also removes
 * apostrophes / smart quotes / dashes — any script, non-Latin included), and
 * collapse whitespace — the same shape as PHP's `[^\p{L}\p{N}\s]` strip.
 *
 * @param {string} s
 * @returns {string} the folded, lowercased, punctuation-stripped text
 * @see includes/title_normalize.php  ihymns_search_fold() — the server fold
 * @link https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/normalize
 */
export function foldSearchText(s) {
    if (!s) return '';
    let out = String(s).toLowerCase().normalize('NFKD').replace(/\p{M}/gu, '');
    out = out.replace(/[łøđæœħßðþı]/g, ch => FOLD_SPECIAL[ch] || ch);
    out = out.replace(/[^\p{L}\p{N}\s]+/gu, '');
    out = out.replace(/\s+/g, ' ').trim();
    return out;
}
