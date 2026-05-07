/**
 * iHymns — Transpose / Capo Indicator Module (#101)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Displays capo information and provides transpose controls on song pages.
 * When a song has capo metadata, a badge is shown in the header card.
 * When chord data is present, +/- controls allow transposing by semitone.
 * User's transpose offset per song is persisted in localStorage.
 *
 * NOTE: This is forward-looking. The current songs.json does not include
 * capo or chord data. The module activates automatically when these fields
 * are added to the data model:
 *   - song.capo (number): Capo fret position
 *   - song.key (string): Original key (e.g. "G", "Am")
 *   - data-chord attributes on lyric lines for inline chord display
 */
import { escapeHtml } from '../utils/html.js';
import { STORAGE_TRANSPOSE_PREFIX } from '../constants.js';

export class Transpose {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number} Current transpose offset in semitones */
        this.offset = 0;

        /** @type {string} Storage key prefix for per-song transpose offsets */
        this.storagePrefix = STORAGE_TRANSPOSE_PREFIX;

        /** @type {string[]} Chromatic scale using sharps */
        this.sharpScale = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

        /** @type {string[]} Chromatic scale using flats */
        this.flatScale = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

        /** @type {Set<string>} Keys that conventionally use flats */
        this.flatKeys = new Set(['F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb', 'Dm', 'Gm', 'Cm', 'Fm', 'Bbm', 'Ebm']);
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Initialise transpose/capo UI on a song page.
     * Called by router after song page loads.
     */
    initSongPage() {
        const songPage = document.querySelector('.page-song');
        if (!songPage) return;

        const songId = songPage.dataset.songId || '';
        const capo = parseInt(songPage.dataset.capo, 10) || 0;
        const originalKey = songPage.dataset.key || '';
        const hasChords = songPage.querySelectorAll('[data-chord]').length > 0;

        /* Nothing to show if no capo and no chords */
        if (!capo && !originalKey && !hasChords) return;

        /* Restore saved transpose offset for this song */
        this.offset = this.loadOffset(songId);

        /* Insert UI into the song meta area */
        const metaArea = songPage.querySelector('.song-meta') || songPage.querySelector('.card-song-header .card-body');
        if (!metaArea) return;

        const container = document.createElement('div');
        container.className = 'transpose-controls mb-3';
        container.setAttribute('role', 'group');
        container.setAttribute('aria-label', 'Transpose and capo controls');

        let html = '';

        /* Capo badge */
        if (capo) {
            html += `
                <span class="badge bg-warning text-dark me-2" aria-label="Capo on fret ${capo}">
                    <i class="fa-solid fa-guitar me-1" aria-hidden="true"></i>
                    Capo ${capo}
                </span>`;
        }

        /* Key display and transpose controls */
        if (originalKey) {
            const transposedKey = this.transposeKey(originalKey, this.offset);
            html += `
                <span class="transpose-key-display me-2">
                    Key: <strong id="transpose-current-key">${escapeHtml(transposedKey)}</strong>
                    ${this.offset !== 0 ? `<small class="text-muted">(original: ${escapeHtml(originalKey)})</small>` : ''}
                </span>`;
        }

        if (hasChords || originalKey) {
            html += `
                <div class="btn-group btn-group-sm" role="group" aria-label="Transpose">
                    <button type="button" class="btn btn-outline-secondary" id="transpose-down" aria-label="Transpose down one semitone">
                        <i class="fa-solid fa-minus" aria-hidden="true"></i>
                    </button>
                    <span class="btn btn-outline-secondary disabled" id="transpose-offset" aria-live="polite">
                        ${this.offset >= 0 ? '+' : ''}${this.offset}
                    </span>
                    <button type="button" class="btn btn-outline-secondary" id="transpose-up" aria-label="Transpose up one semitone">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="transpose-reset" aria-label="Reset transpose" title="Reset">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                    </button>
                </div>`;
        }

        container.innerHTML = html;

        /* Insert after song meta or after the title block */
        const songMeta = songPage.querySelector('.song-meta');
        if (songMeta) {
            songMeta.after(container);
        } else {
            const headerBody = songPage.querySelector('.card-song-header .card-body');
            const actionRow = headerBody?.querySelector('.d-flex.flex-wrap.gap-2');
            if (actionRow) {
                actionRow.before(container);
            }
        }

        /* Bind transpose controls */
        this.bindControls(songId, originalKey, hasChords);

        /* Apply initial transpose if offset is non-zero */
        if (this.offset !== 0 && hasChords) {
            this.applyTranspose();
        }
    }

    /**
     * Bind click handlers for transpose controls.
     * @param {string} songId Current song ID
     * @param {string} originalKey Original key
     * @param {boolean} hasChords Whether chord data exists
     */
    bindControls(songId, originalKey, hasChords) {
        const downBtn = document.getElementById('transpose-down');
        const upBtn = document.getElementById('transpose-up');
        const resetBtn = document.getElementById('transpose-reset');

        downBtn?.addEventListener('click', () => {
            this.offset = ((this.offset - 1) % 12 + 12) % 12;
            if (this.offset > 6) this.offset -= 12;
            this.updateDisplay(songId, originalKey, hasChords);
        });

        upBtn?.addEventListener('click', () => {
            this.offset = this.offset + 1;
            if (this.offset > 6) this.offset -= 12;
            this.updateDisplay(songId, originalKey, hasChords);
        });

        resetBtn?.addEventListener('click', () => {
            this.offset = 0;
            this.updateDisplay(songId, originalKey, hasChords);
        });
    }

    /**
     * Update the display after a transpose change.
     * @param {string} songId Current song ID
     * @param {string} originalKey Original key
     * @param {boolean} hasChords Whether chord data exists
     */
    updateDisplay(songId, originalKey, hasChords) {
        /* Update offset display */
        const offsetEl = document.getElementById('transpose-offset');
        if (offsetEl) {
            offsetEl.textContent = (this.offset >= 0 ? '+' : '') + this.offset;
        }

        /* Update key display */
        if (originalKey) {
            const keyEl = document.getElementById('transpose-current-key');
            if (keyEl) {
                keyEl.textContent = this.transposeKey(originalKey, this.offset);
            }
        }

        /* Transpose chord symbols inline */
        if (hasChords) {
            this.applyTranspose();
        }

        /* Save offset */
        this.saveOffset(songId, this.offset);
    }

    /**
     * Apply transpose to all chord elements in the song.
     * Expects elements with data-chord="originalChord" attribute.
     */
    applyTranspose() {
        document.querySelectorAll('[data-chord]').forEach(el => {
            const original = el.dataset.chord;
            if (original) {
                el.textContent = this.transposeChord(original, this.offset);
            }
        });
    }

    /**
     * Transpose a key name by a number of semitones.
     * @param {string} key Key name (e.g. "G", "Am", "Bb")
     * @param {number} semitones Number of semitones to transpose
     * @returns {string} Transposed key name
     */
    transposeKey(key, semitones) {
        if (!key || semitones === 0) return key;

        const isMinor = key.endsWith('m') && key.length > 1;
        const root = isMinor ? key.slice(0, -1) : key;
        const scale = this.flatKeys.has(key) ? this.flatScale : this.sharpScale;

        const index = scale.indexOf(root);
        if (index === -1) return key;

        const newIndex = ((index + semitones) % 12 + 12) % 12;
        return scale[newIndex] + (isMinor ? 'm' : '');
    }

    /**
     * Transpose a chord symbol by a number of semitones.
     * Handles compound chords like "Am7", "G/B", "Cmaj7".
     * @param {string} chord Chord symbol
     * @param {number} semitones Number of semitones
     * @returns {string} Transposed chord
     */
    transposeChord(chord, semitones) {
        if (!chord || semitones === 0) return chord;

        /* Match root note (with optional # or b) and the rest */
        return chord.replace(/([A-G][#b]?)/g, (match) => {
            const scale = this.sharpScale;
            const flatIdx = this.flatScale.indexOf(match);
            const sharpIdx = this.sharpScale.indexOf(match);
            const index = sharpIdx !== -1 ? sharpIdx : flatIdx;
            if (index === -1) return match;

            const newIndex = ((index + semitones) % 12 + 12) % 12;
            return scale[newIndex];
        });
    }

    /**
     * Load saved transpose offset for a song.
     * @param {string} songId
     * @returns {number}
     */
    loadOffset(songId) {
        const stored = localStorage.getItem(this.storagePrefix + songId);
        return stored ? parseInt(stored, 10) || 0 : 0;
    }

    /**
     * Save transpose offset for a song.
     * @param {string} songId
     * @param {number} offset
     */
    saveOffset(songId, offset) {
        if (offset === 0) {
            localStorage.removeItem(this.storagePrefix + songId);
        } else {
            localStorage.setItem(this.storagePrefix + songId, String(offset));
        }
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
}
