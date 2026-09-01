/**
 * iHymns — Chord Notation Converters: Nashville Number System + Do-Re-Mi / Solfège (#1265)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Pure, DOM-free converters that turn a LETTER chord symbol (the only thing
 * ever stored, exported or printed — rule #45) into a SCREEN-DISPLAY-ONLY
 * alternative notation:
 *   - `chordToNashville()` — Nashville Number System scale-degree numbers
 *     ("1", "b3", "5/7", …), relative to a stated tonic.
 *   - `chordToSolfege()`   — Do-Re-Mi (solfège) syllables, either MOVABLE-do
 *     (tonic = "Do", relative like Nashville numbers) or FIXED-do (C is
 *     always "Do" regardless of key — no tonic needed at all).
 *
 * ⚠️ SCREEN-DISPLAY ONLY — NEVER a machine format (rule #45, mirroring the
 * #1907 `Label` precedent: a display convenience must never leak into a
 * round-trippable export). `format-export.js` (OpenLyrics/OpenSong/ChordPro/…),
 * `propresenter-export.js` and `includes/chord_display.php` all read and
 * write the STORED LETTER chord verbatim and MUST keep doing so — a Nashville
 * or solfège string written into an export would not round-trip through any
 * external tool that reads it back (rule #45's "a free-text label in an
 * exporter breaks re-import" lesson, applied to chord symbols instead of
 * section labels). `tests/test-chord-notation.js` source-scans
 * format-export.js and print.js to prove neither imports this module.
 *
 * GRAMMAR: reuses the SAME `[A-G][#b]?` root/slash-bass token grammar
 * `js/modules/transpose.js`'s `transposeChord()`/`transposeKey()` use — this
 * file does NOT fork a second parser, it only needs "root text -> semitone
 * distance from C", never the actual transpose maths (rule #6: reuse, don't
 * duplicate). The two SHARP_SCALE/FLAT_SCALE arrays below are copied rather
 * than imported because they are simple 12-entry literals with zero logic —
 * importing them would tie this DOM-free utils/ module to a modules/ class
 * file for no behavioural benefit; the values are pinned identical to
 * transpose.js's arrays and any future edit to either must mirror the other
 * (the two carry the same "sharps vs flats" comment intent).
 *
 * @see appWeb/public_html/js/modules/transpose.js — transposeChord()/transposeKey(),
 *   composeChordDisplay() (the ONE render seam this file's output is routed
 *   through via renderChordToken(), NOT a second [data-chord] walker).
 */

/** @type {string[]} Chromatic scale using sharps — mirrors transpose.js's SHARP_SCALE. */
const SHARP_SCALE = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

/** @type {string[]} Chromatic scale using flats — mirrors transpose.js's FLAT_SCALE. */
const FLAT_SCALE = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

/**
 * Nashville Number System degree, keyed by semitone distance of a chord's
 * ROOT from the TONIC's root (0-11). Spelled against the tonic's MAJOR
 * scale regardless of whether the tonic itself is major or minor (a minor
 * tonic's own root is still "1" — e.g. key Em: the Em chord is "1m", the
 * "m" surviving as ordinary quality-suffix passthrough, not from this table).
 * @type {Record<number, string>}
 */
const NASHVILLE_DEGREES = {
    0: '1', 1: 'b2', 2: '2', 3: 'b3', 4: '3', 5: '4',
    6: 'b5', 7: '5', 8: 'b6', 9: '6', 10: 'b7', 11: '7',
};

/**
 * Movable-do solfège syllable, keyed by the SAME semitone-distance-from-tonic
 * table as NASHVILLE_DEGREES above — flat-family syllables (Ra/Me/Se/Le/Te)
 * for the flattened degrees, per the owner's stated default (sub-decisions,
 * #1265).
 * @type {Record<number, string>}
 */
const SOLFEGE_DEGREES_MOVABLE = {
    0: 'Do', 1: 'Ra', 2: 'Re', 3: 'Me', 4: 'Mi', 5: 'Fa',
    6: 'Se', 7: 'Sol', 8: 'Le', 9: 'La', 10: 'Te', 11: 'Ti',
};

/**
 * Fixed-do letter -> syllable. Accidentals (# / b) are appended VERBATIM to
 * the syllable (e.g. "C#" -> "Do#", "Bb" -> "Sib") rather than resolved
 * against a scale — fixed-do is, by definition, keyless (rule per #1265's
 * spec: "tonic ignored").
 * @type {Record<string, string>}
 */
const FIXED_DO_LETTERS = { C: 'Do', D: 'Re', E: 'Mi', F: 'Fa', G: 'Sol', A: 'La', B: 'Si' };

/**
 * Root/slash-bass token grammar. IDENTICAL pattern to transpose.js's
 * transposeChord() — a bare letter A-G with an optional single # or b. Not
 * exported: every caller in this file needs its own fresh `RegExp` instance
 * per `.replace()` call (mirroring transpose.js's own inline-literal idiom),
 * since a *shared* global-flag RegExp object mutates `.lastIndex` across
 * calls in some engines/usages — cheapest correctness is a literal per call.
 * @returns {RegExp}
 */
function rootTokenPattern() {
    return /([A-G][#b]?)/g;
}

/**
 * Semitone index (0-11) of a bare root token (e.g. "C", "F#", "Bb"), tried
 * against the sharp scale first then the flat scale — mirrors transpose.js's
 * transposeChord() lookup order exactly. `-1` when the token isn't a
 * recognised root (should not happen for anything the root-token regex
 * itself matched, but kept defensive).
 * @param {string} rootText
 * @returns {number}
 */
function rootSemitone(rootText) {
    const sharpIdx = SHARP_SCALE.indexOf(rootText);
    if (sharpIdx !== -1) return sharpIdx;
    return FLAT_SCALE.indexOf(rootText);
}

/**
 * Semitone index of a TONIC's root (0-11), or `null` when `tonic` is empty
 * or unrecognised — the caller's signal that no relative conversion is
 * possible and the chord must be returned unchanged. Strips a trailing
 * minor "m" first, exactly like transpose.js's transposeKey() does, so a
 * minor tonic ("Em") resolves to its root's semitone ("E" -> 4) — the
 * minor-ness itself plays no further part in degree numbering (see
 * NASHVILLE_DEGREES's doc-block above).
 * @param {string} tonic
 * @returns {number|null}
 */
function tonicRootSemitone(tonic) {
    if (!tonic) return null;
    const isMinor = tonic.endsWith('m') && tonic.length > 1;
    const root = isMinor ? tonic.slice(0, -1) : tonic;
    const idx = rootSemitone(root);
    return idx === -1 ? null : idx;
}

/**
 * Convert a chord symbol's letter root(s) — including a slash-bass root — to
 * Nashville Number System degrees relative to `tonic`.
 *
 * Every `[A-G][#b]?` token in `chord` is converted independently, which is
 * what makes a slash chord "just work": `G/B` against tonic `C` becomes
 * `5/7` because the regex matches "G" and "B" as two separate tokens (the
 * "/" between them is untouched, exactly as transposeChord() already relies
 * on for slash chords). Any quality suffix (`m`, `7`, `maj7`, `sus4`, …)
 * is lowercase/non-A-G text the regex never touches, so it passes through
 * verbatim.
 *
 * @param {string} chord Letter chord symbol, e.g. "G/B", "Am7", "Csus4".
 * @param {string} tonic Song's stated key, e.g. "C", "Em", "F#".
 * @returns {string} Degree-number string, or `chord` unchanged when `chord`
 *   is empty/falsy, `tonic` doesn't resolve to a known root, or an
 *   individual token isn't a recognised root (matches transposeChord()'s own
 *   passthrough behaviour for both cases).
 */
export function chordToNashville(chord, tonic) {
    if (!chord) return chord;
    const tonicIdx = tonicRootSemitone(tonic);
    if (tonicIdx === null) return chord;

    return chord.replace(rootTokenPattern(), (match) => {
        const idx = rootSemitone(match);
        if (idx === -1) return match;
        const distance = ((idx - tonicIdx) % 12 + 12) % 12;
        return NASHVILLE_DEGREES[distance];
    });
}

/**
 * Convert a chord symbol's letter root(s) to Do-Re-Mi (solfège) syllables.
 *
 * @param {string} chord Letter chord symbol, e.g. "G/B", "Am7".
 * @param {string} tonic Song's stated key. IGNORED entirely when
 *   `opts.fixed` is true — fixed-do needs no key (a letter always maps to
 *   the same syllable).
 * @param {{fixed?: boolean}} [opts] `fixed: true` selects FIXED-do (C is
 *   always "Do"); omitted/false selects MOVABLE-do (tonic = "Do").
 * @returns {string} Syllable string, with the same passthrough behaviour as
 *   chordToNashville() above (empty chord, unresolved tonic in movable mode,
 *   or an individual unrecognised token all return unchanged).
 */
export function chordToSolfege(chord, tonic, opts) {
    if (!chord) return chord;
    const fixed = !!(opts && opts.fixed);

    if (fixed) {
        return chord.replace(rootTokenPattern(), (match) => {
            const letter = match.charAt(0);
            const accidental = match.slice(1);
            const syllable = FIXED_DO_LETTERS[letter];
            return syllable ? syllable + accidental : match;
        });
    }

    const tonicIdx = tonicRootSemitone(tonic);
    if (tonicIdx === null) return chord;

    return chord.replace(rootTokenPattern(), (match) => {
        const idx = rootSemitone(match);
        if (idx === -1) return match;
        const distance = ((idx - tonicIdx) % 12 + 12) % 12;
        return SOLFEGE_DEGREES_MOVABLE[distance];
    });
}
