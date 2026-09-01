/**
 * iHymns — Transpose / Capo Indicator Module (#101, capo+octave overlay #1271)
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
 *
 * #1271 — the dormant `data-capo` field above is now joined by a LIVE,
 * user-driven capo + octave DISPLAY overlay (independent of whether that
 * curator field is ever emitted): a capo stepper (0-11) and an octave
 * stepper (-2..+2), both persisted per-song in localStorage exactly like
 * the transpose offset. See composeChordDisplay() below for the render
 * seam that composes transpose offset + capo into what each `[data-chord]`
 * span shows, and its doc-block for why octave structurally CANNOT reach
 * that composition (it is display-only, by construction — rule #44: no
 * field/control gets wired into a computation it has no business in).
 */
import { escapeHtml } from '../utils/html.js';
import {
    STORAGE_TRANSPOSE_PREFIX,
    STORAGE_CHORD_COLUMNS,
    STORAGE_CAPO_PREFIX,
    STORAGE_OCTAVE_PREFIX,
} from '../constants.js';

/* ============================================================================
 * MODULE-LEVEL PURE HELPERS (#1271)
 * ============================================================================
 * Moved out of the class (they used to be `this.sharpScale` etc. + methods)
 * so composeChordDisplay() — the ONE seam every [data-chord] span is routed
 * through — can be a plain, DOM-free, Node-testable function with no `this`.
 * Nothing outside this file ever read the old `this.sharpScale` / `.flatScale`
 * / `.flatKeys` instance properties or called `.transposeChord()` /
 * `.transposeKey()` as methods (verified by a repo-wide grep before this
 * refactor), so this is a pure relocation, not a back-compat break.
 * ========================================================================== */

/** @type {string[]} Chromatic scale using sharps */
const SHARP_SCALE = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

/** @type {string[]} Chromatic scale using flats */
const FLAT_SCALE = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

/** @type {Set<string>} Keys that conventionally use flats */
const FLAT_KEYS = new Set(['F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb', 'Dm', 'Gm', 'Cm', 'Fm', 'Bbm', 'Ebm']);

/**
 * Transpose a key name by a number of semitones.
 * @param {string} key Key name (e.g. "G", "Am", "Bb")
 * @param {number} semitones Number of semitones to transpose
 * @returns {string} Transposed key name
 */
export function transposeKey(key, semitones) {
    if (!key || semitones === 0) return key;

    const isMinor = key.endsWith('m') && key.length > 1;
    const root = isMinor ? key.slice(0, -1) : key;
    const scale = FLAT_KEYS.has(key) ? FLAT_SCALE : SHARP_SCALE;

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
export function transposeChord(chord, semitones) {
    if (!chord || semitones === 0) return chord;

    /* Match root note (with optional # or b) and the rest */
    return chord.replace(/([A-G][#b]?)/g, (match) => {
        const flatIdx = FLAT_SCALE.indexOf(match);
        const sharpIdx = SHARP_SCALE.indexOf(match);
        const index = sharpIdx !== -1 ? sharpIdx : flatIdx;
        if (index === -1) return match;

        const newIndex = ((index + semitones) % 12 + 12) % 12;
        return SHARP_SCALE[newIndex];
    });
}

/**
 * Compose a chord's IMMUTABLE original text into what one `[data-chord]`
 * span should actually display, given the pending transpose offset and a
 * capo overlay (#1271).
 *
 * The SOUNDING chord (what the room hears) is `transposeChord(original,
 * offset)` — the offset alone, exactly what this module rendered before
 * #1271. Fretting a capo at fret N lets a guitarist finger a SHAPE N
 * semitones LOWER than the sounding pitch and still sound the same note (a
 * capo raises the open strings, so the shape underneath it must sit that
 * much lower): `shape = transposeChord(original, offset - capo)`.
 * `transposeChord()` already wraps any integer semitone count through its
 * own mod-12 arithmetic (see above), so no separate clamping is needed here
 * for extreme offset/capo combinations — e.g. offset=6, capo=11 composes to
 * -5 semitones and resolves correctly, same as calling transposeChord
 * directly with -5.
 *
 * PURE and DOM-free — this is the ONE seam renderChords() below routes
 * every `[data-chord]` element through (rule #6: never a second walker).
 * It is also the render seam #1265 is expected to extend; grow THIS
 * function's signature/logic rather than adding a parallel helper or a
 * second DOM walk.
 *
 * Octave (#1271's other new control) is deliberately NOT a parameter here:
 * it is a DISPLAY-ONLY badge beside the key (see the octave stepper in
 * initSongPage()) and must never influence a chord's sounding pitch or
 * shape — a capo/offset composition is the only thing that belongs in this
 * function's signature.
 *
 * @param {string} original Immutable original chord text (el.dataset.chord)
 * @param {number} offset   Pending transpose offset in semitones
 * @param {number} capo     Capo fret, 0-11
 * @returns {string} The chord shape to display.
 */
export function composeChordDisplay(original, offset, capo) {
    return transposeChord(original, offset - capo);
}

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

        /** @type {number} Current capo fret overlay, 0-11 (#1271) */
        this.capo = 0;

        /** @type {string} Storage key prefix for per-song capo overlay (#1271) */
        this.capoStoragePrefix = STORAGE_CAPO_PREFIX;

        /** @type {number} Current octave DISPLAY overlay, -2..+2 (#1271) */
        this.octave = 0;

        /** @type {string} Storage key prefix for per-song octave overlay (#1271) */
        this.octaveStoragePrefix = STORAGE_OCTAVE_PREFIX;
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
        /* Dormant curator field (song-key.js's doc-block explains why
           SongData never actually sets it today). #1271 keeps it ONLY as
           the stepper's initial-default SEED for a song this device has
           never touched — loadCapo() below prefers a stored user value the
           instant one exists, so "the user overlay wins" per this module's
           doc-block. */
        const capoAttr = parseInt(songPage.dataset.capo, 10) || 0;
        const originalKey = songPage.dataset.key || '';
        const hasChords = songPage.querySelectorAll('[data-chord]').length > 0;

        /* Nothing to show if no capo, no key and no chords */
        if (!capoAttr && !originalKey && !hasChords) return;

        /* Restore saved transpose offset + capo/octave overlay for this song */
        this.offset = this.loadOffset(songId);
        this.capo = this.loadCapo(songId, capoAttr);
        this.octave = this.loadOctave(songId);

        /* Insert UI into the song meta area */
        const metaArea = songPage.querySelector('.song-meta') || songPage.querySelector('.card-song-header .card-body');
        if (!metaArea) return;

        const container = document.createElement('div');
        container.className = 'transpose-controls mb-3';
        container.setAttribute('role', 'group');
        container.setAttribute('aria-label', 'Transpose, capo and octave controls');

        let html = '';

        /* Capo badge — glanceable summary, shown only once capo>0 (rule #44:
           no "Capo 0" clutter). Driven by the RESOLVED this.capo (user
           overlay, falling back to the dormant curator seed), not the raw
           curator attribute — this is the piece of the original #101 design
           #1271 keeps, now live instead of permanently dormant. Kept as its
           own element (rather than folded into the stepper's readout below)
           so the always-visible stepper can show 0 without this badge
           cluttering the row at the neutral value. */
        html += `
            <span class="badge bg-warning text-dark me-2${this.capo ? '' : ' d-none'}" id="capo-badge" role="img" aria-label="Capo on fret ${this.capo}">
                <i class="fa-solid fa-guitar me-1" aria-hidden="true"></i>Capo <span id="capo-badge-value">${this.capo}</span>
            </span>`;

        /* Key display and transpose controls. The key badge ALWAYS shows the
           SOUNDING key (transposeKey(originalKey, offset)) — capo never
           factors in here; a capo changes the SHAPE a guitarist plays, not
           the pitch the room actually hears (#1271). */
        if (originalKey) {
            const transposedKey = transposeKey(originalKey, this.offset);
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
                </div>

                <!-- #1271 — capo stepper, 0-11. Always visible under this same
                     gate (unlike the badge above) so 0 -> 1 is discoverable;
                     see the composeChordDisplay helper for how this composes
                     with the transpose offset into what each chord shape shows. -->
                <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Capo">
                    <span class="btn btn-outline-secondary disabled" aria-hidden="true"><i class="fa-solid fa-guitar" aria-hidden="true"></i></span>
                    <button type="button" class="btn btn-outline-secondary" id="capo-down" aria-label="Move capo down one fret">
                        <i class="fa-solid fa-minus" aria-hidden="true"></i>
                    </button>
                    <span class="btn btn-outline-secondary disabled" id="capo-value" aria-live="polite">${this.capo}</span>
                    <button type="button" class="btn btn-outline-secondary" id="capo-up" aria-label="Move capo up one fret">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    </button>
                </div>

                <!-- #1271 — octave DISPLAY stepper, -2..+2. Cosmetic only: it
                     NEVER reaches the composeChordDisplay/transposeChord
                     helpers — see composeChordDisplay's doc-block for why. -->
                <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Octave display">
                    <span class="btn btn-outline-secondary disabled" aria-hidden="true"><i class="fa-solid fa-arrows-up-down" aria-hidden="true"></i></span>
                    <button type="button" class="btn btn-outline-secondary" id="octave-down" aria-label="Lower octave display">
                        <i class="fa-solid fa-minus" aria-hidden="true"></i>
                    </button>
                    <span class="btn btn-outline-secondary disabled" id="octave-value" aria-live="polite">${escapeHtml(this.octaveLabel(this.octave))}</span>
                    <button type="button" class="btn btn-outline-secondary" id="octave-up" aria-label="Raise octave display">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
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

        /* #299 — wire the "Chords" show/hide toggle. The button + the
           `.lyric-chords` rows are server-rendered only when the song has
           chords (song.php), and the rows are CSS-hidden until `.chords-visible`
           is present on the song page. Chords start HIDDEN (lyrics-first); the
           button reveals them. aria-pressed tracks the state for assistive tech. */
        this.bindChordToggle(songPage);

        /* #1270 — two-column chord-chart layout. GLOBAL (not per-song)
           preference, so restore it on every song page, then wire the
           toggle. Guarded on the button's own presence — same
           `$songHasChords` gate the button itself renders under (song.php),
           so this is a no-op on a chordless song without needing its own
           `hasChords` check here. */
        if (localStorage.getItem(STORAGE_CHORD_COLUMNS) === '1') {
            songPage.classList.add('chord-columns');
            const columnsBtn = document.getElementById('btn-chord-columns');
            if (columnsBtn) {
                columnsBtn.setAttribute('aria-pressed', 'true');
                columnsBtn.classList.add('active');
            }
        }
        this.bindChordColumnsToggle(songPage);

        /* Render chord shapes if the offset and/or capo overlay is non-zero
           (#1271 — capo alone, with offset at 0, still changes what shows). */
        if ((this.offset !== 0 || this.capo !== 0) && hasChords) {
            this.renderChords();
        }
    }

    /**
     * Wire the #btn-toggle-chords button to reveal/hide the inline chord rows
     * (#299). No-op when the button is absent (chordless song).
     * @param {HTMLElement} songPage The `.page-song` root.
     */
    bindChordToggle(songPage) {
        const btn = document.getElementById('btn-toggle-chords');
        if (!btn || !songPage) return;
        btn.addEventListener('click', () => {
            const showing = songPage.classList.toggle('chords-visible');
            btn.setAttribute('aria-pressed', showing ? 'true' : 'false');
            btn.classList.toggle('active', showing);
        });
    }

    /**
     * Wire the #btn-chord-columns button to toggle a two-column chord-chart
     * layout on `.song-lyrics` (#1270, css/app.css). Unlike the show/hide
     * chords toggle above, this preference is persisted GLOBALLY across
     * every song (STORAGE_CHORD_COLUMNS, not a per-song key) — a reader who
     * prefers two columns wants it on every song, not re-toggled each time.
     * No-op when the button is absent (chordless song — same gate as
     * bindChordToggle above).
     * @param {HTMLElement} songPage The `.page-song` root.
     */
    bindChordColumnsToggle(songPage) {
        const btn = document.getElementById('btn-chord-columns');
        if (!btn || !songPage) return;
        btn.addEventListener('click', () => {
            const on = songPage.classList.toggle('chord-columns');
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.classList.toggle('active', on);
            if (on) {
                localStorage.setItem(STORAGE_CHORD_COLUMNS, '1');
            } else {
                localStorage.removeItem(STORAGE_CHORD_COLUMNS);
            }
        });
    }

    /**
     * Bind click handlers for transpose, capo and octave controls (#1271
     * folded the two new steppers in here rather than a separate bind
     * method — all three need the same songId/originalKey/hasChords
     * context to call updateDisplay()).
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

        /* #1271 — capo stepper, 0-11. A true stepper (hard-clamped, not the
           transpose offset's wrap-around) — a capo can't go below the nut
           or usefully past the point frets run out, so there is nothing to
           wrap TO. */
        const capoDownBtn = document.getElementById('capo-down');
        const capoUpBtn = document.getElementById('capo-up');

        capoDownBtn?.addEventListener('click', () => {
            this.capo = Math.max(0, this.capo - 1);
            this.updateDisplay(songId, originalKey, hasChords);
        });

        capoUpBtn?.addEventListener('click', () => {
            this.capo = Math.min(11, this.capo + 1);
            this.updateDisplay(songId, originalKey, hasChords);
        });

        /* #1271 — octave DISPLAY stepper, -2..+2. Also hard-clamped; see
           composeChordDisplay()'s doc-block for why this value never
           reaches the chord-transposition maths. */
        const octaveDownBtn = document.getElementById('octave-down');
        const octaveUpBtn = document.getElementById('octave-up');

        octaveDownBtn?.addEventListener('click', () => {
            this.octave = Math.max(-2, this.octave - 1);
            this.updateDisplay(songId, originalKey, hasChords);
        });

        octaveUpBtn?.addEventListener('click', () => {
            this.octave = Math.min(2, this.octave + 1);
            this.updateDisplay(songId, originalKey, hasChords);
        });
    }

    /**
     * Update the display after a transpose, capo or octave change — all
     * three steppers funnel into this one refresh (#1271).
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

        /* Update key display — SOUNDING key, capo never factors in here
           (see composeChordDisplay()'s doc-block). */
        if (originalKey) {
            const keyEl = document.getElementById('transpose-current-key');
            if (keyEl) {
                keyEl.textContent = transposeKey(originalKey, this.offset);
            }
        }

        /* #1271 — update the capo stepper readout + the glanceable badge
           (shown only once capo>0, matching the original #101 design). */
        const capoValueEl = document.getElementById('capo-value');
        if (capoValueEl) {
            capoValueEl.textContent = String(this.capo);
        }
        const capoBadge = document.getElementById('capo-badge');
        if (capoBadge) {
            capoBadge.classList.toggle('d-none', this.capo === 0);
            capoBadge.setAttribute('aria-label', `Capo on fret ${this.capo}`);
            const capoBadgeValueEl = document.getElementById('capo-badge-value');
            if (capoBadgeValueEl) capoBadgeValueEl.textContent = String(this.capo);
        }

        /* #1271 — update the octave display-only readout. */
        const octaveValueEl = document.getElementById('octave-value');
        if (octaveValueEl) {
            octaveValueEl.textContent = this.octaveLabel(this.octave);
        }

        /* Render chord shapes inline */
        if (hasChords) {
            this.renderChords();
        }

        /* Save offset + capo + octave — each of the three save*() calls is
           independently idempotent at its own neutral value (mirrors
           saveOffset exactly), so persisting all three on every stepper
           click is safe even though only one of them actually changed. */
        this.saveOffset(songId, this.offset);
        this.saveCapo(songId, this.capo);
        this.saveOctave(songId, this.octave);
    }

    /**
     * Render every `[data-chord]` element's displayed shape from its
     * IMMUTABLE `data-chord` original, via the ONE composed pure helper
     * composeChordDisplay() (#1271 rename from applyTranspose() — this now
     * composes the transpose offset AND the capo overlay, rather than the
     * offset alone).
     */
    renderChords() {
        document.querySelectorAll('[data-chord]').forEach(el => {
            const original = el.dataset.chord;
            if (original) {
                el.textContent = composeChordDisplay(original, this.offset, this.capo);
            }
        });
    }

    /**
     * Format the octave DISPLAY overlay as a badge string. Purely cosmetic
     * text formatting — never touches semitone maths (#1271).
     * @param {number} octave -2..+2
     * @returns {string} e.g. "8va", "8vb", "+2 oct", "0 oct"
     */
    octaveLabel(octave) {
        if (octave === 1) return '8va';
        if (octave === -1) return '8vb';
        return (octave > 0 ? '+' : '') + octave + ' oct';
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
     * Load saved capo overlay for a song (#1271). Mirrors loadOffset()
     * exactly, except the fallback when nothing is stored yet is the
     * caller-supplied dormant curator seed rather than a hardcoded 0 — see
     * the module doc-block ("the user overlay wins").
     * @param {string} songId
     * @param {number} [fallback=0] Initial default when no stored value exists.
     * @returns {number}
     */
    loadCapo(songId, fallback = 0) {
        const stored = localStorage.getItem(this.capoStoragePrefix + songId);
        return stored ? (parseInt(stored, 10) || 0) : fallback;
    }

    /**
     * Save capo overlay for a song (#1271). Mirrors saveOffset() exactly:
     * removed at the neutral value (0) rather than stored as "0".
     * @param {string} songId
     * @param {number} capo
     */
    saveCapo(songId, capo) {
        if (capo === 0) {
            localStorage.removeItem(this.capoStoragePrefix + songId);
        } else {
            localStorage.setItem(this.capoStoragePrefix + songId, String(capo));
        }
    }

    /**
     * Load saved octave DISPLAY overlay for a song (#1271). Mirrors
     * loadOffset() exactly — no curator field seeds this one, so the
     * fallback is always 0.
     * @param {string} songId
     * @returns {number}
     */
    loadOctave(songId) {
        const stored = localStorage.getItem(this.octaveStoragePrefix + songId);
        return stored ? (parseInt(stored, 10) || 0) : 0;
    }

    /**
     * Save octave DISPLAY overlay for a song (#1271). Mirrors saveOffset()
     * exactly: removed at the neutral value (0) rather than stored as "0".
     * @param {string} songId
     * @param {number} octave
     */
    saveOctave(songId, octave) {
        if (octave === 0) {
            localStorage.removeItem(this.octaveStoragePrefix + songId);
        } else {
            localStorage.setItem(this.octaveStoragePrefix + songId, String(octave));
        }
    }
}
