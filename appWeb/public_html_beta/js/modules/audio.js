/**
 * iHymns — MIDI Audio Playback Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Provides MIDI audio playback for songs that have associated audio files.
 * Uses an HTML5 <audio> element for browsers that natively support MIDI
 * playback. When native MIDI playback is not supported, falls back to
 * displaying a download link so the user can open the file in an external
 * application.
 *
 * MIDI FILE PATH CONVENTION:
 * Audio files are expected at: midi/<songbook>/<filename>_audio.mid
 * where <songbook> is the songbook code (e.g., 'CH') and <filename> is
 * derived from the song ID (e.g., 'CH-0003').
 *
 * ARCHITECTURE:
 * - initAudioPlayer()        : one-time setup (currently a no-op placeholder)
 * - renderAudioPlayer(song, containerEl) : renders playback UI into a container
 * - isAudioSupported()       : probes the browser for MIDI playback capability
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/**
 * Import DOM helpers from the shared helpers module.
 * - createElement : creates a DOM element with attributes and children
 */
import { createElement } from '../utils/helpers.js';

/* =========================================================================
 * MODULE-LEVEL STATE
 * ========================================================================= */

/**
 * Cached result of the MIDI support check.
 * Evaluated lazily on first call to isAudioSupported() and stored here
 * so subsequent calls do not need to re-probe the browser.
 * null means "not yet checked".
 */
let _midiSupportCached = null;

/**
 * Reference to the currently active <audio> element, if any.
 * Used to ensure only one audio player is active at a time (i.e., if the
 * user navigates to a different song we can stop the previous playback).
 */
let _currentAudioElement = null;

/* =========================================================================
 * PUBLIC API
 * ========================================================================= */

/**
 * initAudioPlayer()
 *
 * Performs one-time initialisation of the audio playback subsystem.
 * Currently this is a no-op placeholder. Future versions may pre-load
 * a MIDI SoundFont or initialise a Web Audio context here.
 *
 * Call this once during application bootstrap (e.g., from app.js).
 */
export function initAudioPlayer() {
    /* ---------------------------------------------------------------
     * PLACEHOLDER — no initialisation is required for the HTML5 Audio
     * approach. This function exists so that future enhancements (e.g.,
     * loading a Tone.js SoundFont) have a clear integration point.
     * --------------------------------------------------------------- */
}

/**
 * renderAudioPlayer(song, containerEl)
 *
 * Renders MIDI audio playback controls for a song into the given
 * container element. If the song does not have audio (song.hasAudio is
 * falsy), this function returns immediately without modifying the DOM.
 *
 * When the browser supports MIDI playback natively, the UI includes
 * Play/Pause and Stop buttons backed by an HTML5 <audio> element.
 * When MIDI is not natively supported, a download link is shown instead.
 *
 * @param {object}  song        - The song data object (must include id, songbook, hasAudio)
 * @param {Element} containerEl - The DOM element to render the player into
 */
export function renderAudioPlayer(song, containerEl) {
    /* ---------------------------------------------------------------
     * GUARD: exit early if the song has no associated audio file.
     * This avoids rendering an empty player section.
     * --------------------------------------------------------------- */
    if (!song.hasAudio) {
        return;
    }

    /* ---------------------------------------------------------------
     * DERIVE THE MIDI FILE URL
     * Convention: midi/<songbook>/<songId>_audio.mid
     * Example:    midi/CH/CH-0003_audio.mid
     * --------------------------------------------------------------- */
    const midiUrl = `midi/${encodeURIComponent(song.songbook)}/${encodeURIComponent(song.id)}_audio.mid`;

    /* ---------------------------------------------------------------
     * OUTER WRAPPER
     * Create a container div for the audio player section. Uses
     * Bootstrap utility classes for spacing and a custom class for
     * targeted styling.
     * --------------------------------------------------------------- */
    const playerWrapper = createElement('div', {
        className: 'audio-player-section mb-3',
        role: 'region',
        'aria-label': 'Audio player'
    });

    /* ---------------------------------------------------------------
     * SECTION HEADING
     * A small label so the user knows what this section is for.
     * --------------------------------------------------------------- */
    const heading = createElement('div', {
        className: 'audio-player-label text-muted small mb-1'
    }, 'Audio');

    /* Append the heading to the wrapper */
    playerWrapper.appendChild(heading);

    /* ---------------------------------------------------------------
     * BRANCH: native MIDI playback vs. download fallback
     * --------------------------------------------------------------- */
    if (isAudioSupported()) {
        /* ---------------------------------------------------------------
         * NATIVE MIDI PLAYBACK PATH
         * Build an HTML5 <audio> element and Play/Pause + Stop buttons.
         * --------------------------------------------------------------- */

        /* Create the hidden <audio> element that will handle actual playback */
        const audioEl = document.createElement('audio');

        /* Set the MIDI file as the audio source */
        audioEl.src = midiUrl;

        /* Preload metadata only — avoid downloading the whole file until play */
        audioEl.preload = 'none';

        /* ---------------------------------------------------------------
         * BUTTON GROUP
         * Uses Bootstrap .btn-group for a tidy row of controls.
         * --------------------------------------------------------------- */
        const btnGroup = createElement('div', {
            className: 'btn-group',
            role: 'group',
            'aria-label': 'Audio playback controls'
        });

        /* --- Play / Pause button --- */
        const playPauseBtn = createElement('button', {
            className: 'btn btn-outline-primary btn-sm',
            'aria-label': 'Play audio',
            onClick: () => {
                /* Toggle between play and pause states */
                if (audioEl.paused) {
                    /* Stop any previously playing audio from another song */
                    _stopCurrentAudio();

                    /* Start playback of this song's MIDI file */
                    audioEl.play().then(() => {
                        /* Update button label to indicate pause is now the action */
                        playPauseBtn.innerHTML = '<i class="bi bi-pause-fill me-1" aria-hidden="true"></i>Pause';
                        playPauseBtn.setAttribute('aria-label', 'Pause audio');
                    }).catch((err) => {
                        /* -------------------------------------------------------
                         * Playback failed — likely because the browser cannot
                         * decode MIDI. Fall back to offering a download link.
                         * ------------------------------------------------------- */
                        console.warn('MIDI playback failed:', err);
                        _replaceWithDownloadLink(playerWrapper, midiUrl);
                    });

                    /* Track this audio element as the currently active one */
                    _currentAudioElement = audioEl;
                } else {
                    /* Audio is currently playing — pause it */
                    audioEl.pause();

                    /* Update button label back to "Play" */
                    playPauseBtn.innerHTML = '<i class="bi bi-play-fill me-1" aria-hidden="true"></i>Play';
                    playPauseBtn.setAttribute('aria-label', 'Play audio');
                }
            }
        }, '');

        /* Set initial button content with a Bootstrap icon */
        playPauseBtn.innerHTML = '<i class="bi bi-play-fill me-1" aria-hidden="true"></i>Play';

        /* --- Stop button --- */
        const stopBtn = createElement('button', {
            className: 'btn btn-outline-secondary btn-sm',
            'aria-label': 'Stop audio',
            onClick: () => {
                /* Pause the audio and reset the playback position to the start */
                audioEl.pause();
                audioEl.currentTime = 0;

                /* Update the play/pause button back to "Play" state */
                playPauseBtn.innerHTML = '<i class="bi bi-play-fill me-1" aria-hidden="true"></i>Play';
                playPauseBtn.setAttribute('aria-label', 'Play audio');
            }
        }, '');

        /* Set stop button content with a Bootstrap icon */
        stopBtn.innerHTML = '<i class="bi bi-stop-fill me-1" aria-hidden="true"></i>Stop';

        /* --- Download button --- */
        const downloadBtn = createElement('a', {
            className: 'btn btn-outline-info btn-sm',
            href: midiUrl,
            download: '',
            'aria-label': 'Download MIDI file'
        }, '');

        /* Set download button content with a Bootstrap icon */
        downloadBtn.innerHTML = '<i class="bi bi-download me-1" aria-hidden="true"></i>Download';

        /* ---------------------------------------------------------------
         * EVENT: when audio ends naturally, reset the play button
         * --------------------------------------------------------------- */
        audioEl.addEventListener('ended', () => {
            playPauseBtn.innerHTML = '<i class="bi bi-play-fill me-1" aria-hidden="true"></i>Play';
            playPauseBtn.setAttribute('aria-label', 'Play audio');
        });

        /* ---------------------------------------------------------------
         * EVENT: if an error occurs during loading/decoding, fall back
         * to the download link automatically.
         * --------------------------------------------------------------- */
        audioEl.addEventListener('error', () => {
            console.warn('Audio element error — MIDI may not be supported natively.');
            _replaceWithDownloadLink(playerWrapper, midiUrl);
        });

        /* Assemble the button group */
        btnGroup.appendChild(playPauseBtn);
        btnGroup.appendChild(stopBtn);
        btnGroup.appendChild(downloadBtn);

        /* Append the button group to the player wrapper */
        playerWrapper.appendChild(btnGroup);

        /* Append the hidden <audio> element (not visible, but needed in the DOM) */
        playerWrapper.appendChild(audioEl);
    } else {
        /* ---------------------------------------------------------------
         * FALLBACK PATH — browser does not support MIDI
         * Show a download link so the user can still access the file.
         * --------------------------------------------------------------- */
        const fallback = _buildDownloadLink(midiUrl);

        /* Append the download fallback to the wrapper */
        playerWrapper.appendChild(fallback);
    }

    /* ---------------------------------------------------------------
     * MOUNT
     * Append the entire player section to the supplied container.
     * --------------------------------------------------------------- */
    containerEl.appendChild(playerWrapper);
}

/**
 * isAudioSupported()
 *
 * Checks whether the current browser can play MIDI files natively via
 * an HTML5 <audio> element. Tests for the 'audio/midi' MIME type using
 * the canPlayType() API on a temporary Audio object.
 *
 * Note: Most modern browsers do NOT natively support MIDI playback.
 * This function will return false in those cases, triggering the
 * download-link fallback in renderAudioPlayer().
 *
 * The result is cached after the first call for performance.
 *
 * @returns {boolean} true if the browser reports it can play audio/midi
 */
export function isAudioSupported() {
    /* ---------------------------------------------------------------
     * CACHED RESULT — if we have already checked, return immediately.
     * --------------------------------------------------------------- */
    if (_midiSupportCached !== null) {
        return _midiSupportCached;
    }

    /* ---------------------------------------------------------------
     * PROBE — create a temporary Audio element and ask the browser
     * whether it can play audio/midi. canPlayType() returns:
     *   '' (empty string)  — cannot play
     *   'maybe'            — might be able to play
     *   'probably'         — can almost certainly play
     * We treat both 'maybe' and 'probably' as supported.
     * --------------------------------------------------------------- */
    try {
        const testAudio = new Audio();
        const canPlay = testAudio.canPlayType('audio/midi');
        _midiSupportCached = (canPlay === 'probably' || canPlay === 'maybe');
    } catch (e) {
        /* If Audio constructor fails (unlikely), MIDI is definitely not supported */
        _midiSupportCached = false;
    }

    return _midiSupportCached;
}

/* =========================================================================
 * PRIVATE HELPER FUNCTIONS
 * ========================================================================= */

/**
 * _buildDownloadLink(midiUrl)
 *
 * Creates a styled download link element for the given MIDI file URL.
 * Used as the fallback UI when native playback is not available.
 *
 * @param {string} midiUrl - The URL to the MIDI file
 * @returns {Element} A Bootstrap-styled anchor element configured for download
 */
function _buildDownloadLink(midiUrl) {
    /* Create an anchor element styled as a Bootstrap outline button */
    const link = createElement('a', {
        className: 'btn btn-outline-primary btn-sm',
        href: midiUrl,
        /* The download attribute prompts the browser to download rather than navigate */
        download: '',
        'aria-label': 'Download MIDI audio file'
    }, '');

    /* Set the inner HTML with a download icon and label text */
    link.innerHTML = '<i class="bi bi-download me-1" aria-hidden="true"></i>Download MIDI';

    return link;
}

/**
 * _replaceWithDownloadLink(wrapperEl, midiUrl)
 *
 * Replaces all child content of the given wrapper element with a single
 * download link. Called when an attempted playback fails at runtime
 * (e.g., the browser could not decode the MIDI stream).
 *
 * @param {Element} wrapperEl - The player wrapper whose content will be replaced
 * @param {string}  midiUrl   - The URL to the MIDI file
 */
function _replaceWithDownloadLink(wrapperEl, midiUrl) {
    /* ---------------------------------------------------------------
     * PRESERVE the heading (first child) but remove everything else.
     * This ensures the "Audio" label remains visible.
     * --------------------------------------------------------------- */
    const heading = wrapperEl.firstElementChild;

    /* Clear all children */
    wrapperEl.innerHTML = '';

    /* Re-add the heading if it existed */
    if (heading) {
        wrapperEl.appendChild(heading);
    }

    /* Add an informational message explaining why playback is unavailable */
    const infoMsg = createElement('p', {
        className: 'text-muted small mb-1'
    }, 'Your browser cannot play MIDI files directly. Use the link below to download.');

    wrapperEl.appendChild(infoMsg);

    /* Build and append the download link */
    const link = _buildDownloadLink(midiUrl);
    wrapperEl.appendChild(link);
}

/**
 * _stopCurrentAudio()
 *
 * Stops any currently playing audio from a previous song. This prevents
 * two songs from playing simultaneously if the user navigates between
 * song detail views without explicitly stopping the first one.
 */
function _stopCurrentAudio() {
    if (_currentAudioElement) {
        /* Pause the audio */
        _currentAudioElement.pause();

        /* Reset the playback position to the beginning */
        _currentAudioElement.currentTime = 0;

        /* Clear the reference */
        _currentAudioElement = null;
    }
}
