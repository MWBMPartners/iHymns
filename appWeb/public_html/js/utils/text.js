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
