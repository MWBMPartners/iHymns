/**
 * iHymns — Musical Key Transposition Utility
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides chord transposition functions for changing song keys.
 * Handles sharps (#), flats (b), and enharmonic equivalents.
 *
 * USAGE:
 *   import { transposeChord, transposeSong, getKeyName } from './utils/transpose.js';
 *   transposeChord('Am', 3);  // → 'Cm'
 *   transposeSong(chordsArray, 'C', 'G');  // transpose from C to G
 */

/* Chromatic scale using sharps */
const SHARP_NOTES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

/* Chromatic scale using flats */
const FLAT_NOTES = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

/* Keys that conventionally use flats */
const FLAT_KEYS = ['F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb', 'Dm', 'Gm', 'Cm', 'Fm', 'Bbm', 'Ebm'];

/* Map all note names to semitone index (0-11) */
const NOTE_INDEX = {};
SHARP_NOTES.forEach((note, i) => { NOTE_INDEX[note] = i; });
FLAT_NOTES.forEach((note, i) => { NOTE_INDEX[note] = i; });
/* Enharmonic aliases */
NOTE_INDEX['E#'] = 5;  /* = F */
NOTE_INDEX['B#'] = 0;  /* = C */
NOTE_INDEX['Cb'] = 11; /* = B */
NOTE_INDEX['Fb'] = 4;  /* = E */

/* Chord regex: captures root note (+ optional # or b), quality, and bass note */
const CHORD_REGEX = /^([A-G][#b]?)(.*?)(?:\/([A-G][#b]?))?$/;

/**
 * Transpose a single chord by a number of semitones.
 *
 * @param {string} chord     The chord to transpose (e.g., 'Am7', 'F#m', 'Bb/D')
 * @param {number} semitones Number of semitones to transpose (positive = up, negative = down)
 * @param {boolean} useFlats Whether to use flat notation (default: auto-detect from result)
 * @returns {string} The transposed chord
 */
export function transposeChord(chord, semitones, useFlats = null) {
    if (!chord || semitones === 0) return chord;

    const match = chord.match(CHORD_REGEX);
    if (!match) return chord; /* Not a recognized chord — return as-is */

    const [, root, quality, bass] = match;
    const rootIndex = NOTE_INDEX[root];
    if (rootIndex === undefined) return chord;

    /* Transpose root */
    const newRootIndex = ((rootIndex + semitones) % 12 + 12) % 12;
    const notes = (useFlats !== null ? useFlats : FLAT_KEYS.includes(chord))
        ? FLAT_NOTES : SHARP_NOTES;
    let result = notes[newRootIndex] + quality;

    /* Transpose bass note if present (e.g., C/G → D/A) */
    if (bass) {
        const bassIndex = NOTE_INDEX[bass];
        if (bassIndex !== undefined) {
            const newBassIndex = ((bassIndex + semitones) % 12 + 12) % 12;
            result += '/' + notes[newBassIndex];
        }
    }

    return result;
}

/**
 * Calculate the number of semitones between two keys.
 *
 * @param {string} fromKey Source key (e.g., 'C', 'G', 'Bbm')
 * @param {string} toKey   Target key
 * @returns {number} Semitones to transpose (0-11)
 */
export function getSemitonesBetween(fromKey, toKey) {
    const fromRoot = fromKey.replace(/m.*$/, '');
    const toRoot = toKey.replace(/m.*$/, '');
    const fromIndex = NOTE_INDEX[fromRoot];
    const toIndex = NOTE_INDEX[toRoot];
    if (fromIndex === undefined || toIndex === undefined) return 0;
    return ((toIndex - fromIndex) % 12 + 12) % 12;
}

/**
 * Transpose an array of chord objects by a number of semitones.
 *
 * @param {Array<{position: number, chord: string}>} chords Array of chord position objects
 * @param {number} semitones Number of semitones to transpose
 * @returns {Array<{position: number, chord: string}>} Transposed chords
 */
export function transposeChords(chords, semitones) {
    if (!Array.isArray(chords) || semitones === 0) return chords;
    return chords.map(c => ({
        ...c,
        chord: transposeChord(c.chord, semitones),
    }));
}

/**
 * Get the display name for a key after transposition.
 *
 * @param {string} originalKey The original key (e.g., 'C')
 * @param {number} semitones   Semitones transposed
 * @returns {string} The new key name
 */
export function getTransposedKey(originalKey, semitones) {
    return transposeChord(originalKey, semitones);
}

/**
 * Calculate the capo position for guitarists.
 * If the song is in key X and the guitarist wants to play in key Y,
 * the capo goes on fret = semitones between Y and X.
 *
 * @param {string} songKey    The song's key
 * @param {string} playKey    The key the guitarist wants to play shapes in
 * @returns {number} Capo fret position (0 = no capo)
 */
export function getCapoPosition(songKey, playKey) {
    return getSemitonesBetween(playKey, songKey);
}

/**
 * All standard musical keys for a key selector dropdown.
 */
export const ALL_KEYS = [
    'C', 'C#', 'Db', 'D', 'D#', 'Eb', 'E', 'F',
    'F#', 'Gb', 'G', 'G#', 'Ab', 'A', 'A#', 'Bb', 'B',
    'Cm', 'C#m', 'Dm', 'D#m', 'Ebm', 'Em', 'Fm',
    'F#m', 'Gm', 'G#m', 'Am', 'A#m', 'Bbm', 'Bm',
];
