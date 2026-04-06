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

export function toTitleCase(str) {
    if (!str) return str || '';
    return str
        .toLowerCase()
        .split(/\s+/)
        .map((word, i, arr) => {
            /* Always capitalise first and last word */
            if (i === 0 || i === arr.length - 1 || !MINOR_WORDS.has(word)) {
                word = word.charAt(0).toUpperCase() + word.slice(1);
            }
            /* Capitalise each part of hyphenated words */
            return word.replace(/-\w/g, m => m.toUpperCase());
        })
        .join(' ');
}
