/**
 * iHymns — Audio Playback Module (#90)
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Provides MIDI audio playback for songs that have associated .mid files.
 * Uses Tone.js (loaded dynamically from CDN) as the Web Audio synthesis
 * engine. Falls back to a download link if audio playback is unavailable.
 *
 * ARCHITECTURE:
 *   1. When user clicks the audio button on a song page, a player UI
 *      is injected below the song header.
 *   2. Tone.js is loaded dynamically on first use (CDN with local fallback).
 *   3. The MIDI file is fetched and parsed into note events.
 *   4. Notes are scheduled on a Tone.js PolySynth for playback.
 *   5. Play/pause/stop controls and a progress bar are provided.
 *
 * FILE PATH:
 *   MIDI files are expected at: /data/audio/{SONG_ID}.mid
 *   e.g., /data/audio/CP-0001.mid
 */

export class Audio {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {object|null} Tone.js library reference */
        this.Tone = null;

        /** @type {boolean} Whether Tone.js has been loaded */
        this.toneLoaded = false;

        /** @type {boolean} Whether loading is in progress */
        this.toneLoading = false;

        /** @type {object|null} Active Tone.js PolySynth instance */
        this.synth = null;

        /** @type {object|null} Active Tone.js Part for scheduled notes */
        this.part = null;

        /** @type {string|null} Currently playing song ID */
        this.currentSongId = null;

        /** @type {boolean} Whether playback is active */
        this.isPlaying = false;

        /** @type {number} Total duration of current MIDI in seconds */
        this.duration = 0;

        /** @type {number|null} Progress update interval ID */
        this.progressInterval = null;
    }

    /** Initialise — nothing needed on startup; Tone.js loaded on demand */
    init() {}

    /**
     * Handle audio button click on a song page.
     * Creates/toggles the player UI and loads the MIDI file.
     *
     * @param {string} songId Song ID (e.g., 'CP-0001')
     */
    async handleAudioClick(songId) {
        const existingPlayer = document.getElementById('audio-player');

        /* If player is already showing for this song, toggle play/pause */
        if (existingPlayer && this.currentSongId === songId) {
            this.togglePlayPause();
            return;
        }

        /* If playing a different song, stop current playback first */
        if (this.isPlaying) {
            this.stop();
        }

        this.currentSongId = songId;

        /* Inject the player UI */
        this.injectPlayerUI(songId);

        /* Load Tone.js if not already loaded */
        if (!this.toneLoaded) {
            this.updatePlayerStatus('Loading audio engine...');
            const loaded = await this.loadToneJs();
            if (!loaded) {
                this.updatePlayerStatus('Audio playback unavailable in this browser.');
                this.showDownloadLink(songId);
                return;
            }
        }

        /* Fetch and parse the MIDI file */
        this.updatePlayerStatus('Loading MIDI file...');
        const midiData = await this.fetchMidi(songId);
        if (!midiData) {
            this.updatePlayerStatus('MIDI file not available for this song.');
            this.showDownloadLink(songId);
            return;
        }

        /* Parse MIDI and prepare for playback */
        try {
            await this.prepareMidi(midiData);
            this.updatePlayerStatus('Ready to play');
            this.enableControls(true);
        } catch (err) {
            console.error('[Audio] MIDI parse error:', err);
            this.updatePlayerStatus('Unable to parse MIDI file.');
            this.showDownloadLink(songId);
        }
    }

    /**
     * Inject the audio player UI below the song header card.
     *
     * @param {string} songId Song ID for download link
     */
    injectPlayerUI(songId) {
        /* Remove existing player if any */
        document.getElementById('audio-player')?.remove();

        const headerCard = document.querySelector('.card-song-header');
        if (!headerCard) return;

        const player = document.createElement('div');
        player.id = 'audio-player';
        player.className = 'card card-audio-player mb-4';
        player.setAttribute('role', 'region');
        player.setAttribute('aria-label', 'Audio player');
        player.innerHTML = `
            <div class="card-body py-2">
                <div class="d-flex align-items-center gap-3">
                    <!-- Play/Pause button -->
                    <button type="button" class="btn btn-primary btn-sm rounded-circle audio-play-btn"
                            id="audio-play-btn" disabled
                            aria-label="Play" title="Play">
                        <i class="fa-solid fa-play" aria-hidden="true"></i>
                    </button>
                    <!-- Stop button -->
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-circle audio-stop-btn"
                            id="audio-stop-btn" disabled
                            aria-label="Stop" title="Stop">
                        <i class="fa-solid fa-stop" aria-hidden="true"></i>
                    </button>
                    <!-- Progress bar -->
                    <div class="flex-grow-1">
                        <div class="progress audio-progress" style="height: 8px;"
                             role="progressbar" aria-label="Playback progress"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                            <div class="progress-bar bg-primary" id="audio-progress-bar" style="width: 0%"></div>
                        </div>
                        <small class="text-muted d-flex justify-content-between mt-1">
                            <span id="audio-time-current">0:00</span>
                            <span id="audio-status">Loading...</span>
                            <span id="audio-time-total">0:00</span>
                        </small>
                    </div>
                    <!-- Close button -->
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle"
                            id="audio-close-btn" aria-label="Close player" title="Close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
            </div>`;

        headerCard.after(player);

        /* Bind control events */
        document.getElementById('audio-play-btn')?.addEventListener('click', () => this.togglePlayPause());
        document.getElementById('audio-stop-btn')?.addEventListener('click', () => this.stop());
        document.getElementById('audio-close-btn')?.addEventListener('click', () => this.closePlayer());
    }

    /**
     * Dynamically load Tone.js from CDN with local fallback.
     *
     * @returns {Promise<boolean>} True if loaded successfully
     */
    async loadToneJs() {
        if (this.toneLoaded) return true;
        if (this.toneLoading) return false;
        this.toneLoading = true;

        try {
            /* Try CDN first */
            await this.loadScript(this.app.config.toneJsCdn);
            if (window.Tone) {
                this.Tone = window.Tone;
                this.toneLoaded = true;
                console.log('[Audio] Tone.js loaded from CDN');
                return true;
            }
        } catch {
            console.warn('[Audio] Tone.js CDN failed, trying local fallback');
        }

        try {
            /* Local fallback */
            await this.loadScript('/' + this.app.config.toneJsLocal);
            if (window.Tone) {
                this.Tone = window.Tone;
                this.toneLoaded = true;
                console.log('[Audio] Tone.js loaded from local');
                return true;
            }
        } catch {
            console.error('[Audio] Tone.js failed to load from both CDN and local');
        }

        this.toneLoading = false;
        return false;
    }

    /**
     * Load an external script dynamically.
     *
     * @param {string} src Script URL
     * @returns {Promise<void>}
     */
    loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.crossOrigin = 'anonymous';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    /**
     * Fetch a MIDI file for a given song.
     *
     * @param {string} songId Song ID (e.g., 'CP-0001')
     * @returns {Promise<ArrayBuffer|null>} MIDI data or null on failure
     */
    async fetchMidi(songId) {
        const url = this.app.config.audioBasePath + songId + '.mid';
        try {
            const response = await fetch(url);
            if (!response.ok) return null;
            return await response.arrayBuffer();
        } catch {
            return null;
        }
    }

    /**
     * Parse a MIDI ArrayBuffer and prepare Tone.js for playback.
     * Uses a simplified MIDI parser to extract note events.
     *
     * @param {ArrayBuffer} midiData Raw MIDI file data
     */
    async prepareMidi(midiData) {
        const Tone = this.Tone;

        /* Ensure the audio context is started (requires user gesture) */
        await Tone.start();

        /* Parse MIDI into note events using our lightweight parser */
        const notes = this.parseMidiToNotes(midiData);

        if (notes.length === 0) {
            throw new Error('No playable notes found in MIDI file');
        }

        /* Calculate total duration */
        this.duration = Math.max(...notes.map(n => n.time + n.duration));

        /* Create a polyphonic synth for playback */
        this.synth?.dispose();
        this.synth = new Tone.PolySynth(Tone.Synth, {
            maxPolyphony: 16,
            voice: Tone.Synth,
            options: {
                oscillator: { type: 'triangle' },
                envelope: { attack: 0.02, decay: 0.3, sustain: 0.4, release: 0.8 },
            }
        }).toDestination();

        /* Schedule all notes into a Tone.js Part */
        this.part?.dispose();
        this.part = new Tone.Part((time, note) => {
            this.synth.triggerAttackRelease(note.name, note.duration, time, note.velocity);
        }, notes.map(n => [n.time, { name: n.name, duration: n.duration, velocity: n.velocity }]));

        this.part.loop = false;

        /* Update time display */
        document.getElementById('audio-time-total').textContent = this.formatTime(this.duration);
    }

    /**
     * Lightweight MIDI parser — extracts note-on/note-off events.
     * Handles standard MIDI file format 0 and 1 (single & multi-track).
     *
     * @param {ArrayBuffer} buffer Raw MIDI data
     * @returns {Array<{time: number, name: string, duration: number, velocity: number}>}
     */
    parseMidiToNotes(buffer) {
        const data = new Uint8Array(buffer);
        const notes = [];
        let pos = 0;

        /* Helper: read a variable-length quantity (MIDI VLQ) */
        const readVLQ = () => {
            let value = 0;
            let byte;
            do {
                byte = data[pos++];
                value = (value << 7) | (byte & 0x7F);
            } while (byte & 0x80);
            return value;
        };

        /* Helper: read a fixed-length big-endian integer */
        const readInt = (len) => {
            let val = 0;
            for (let i = 0; i < len; i++) val = (val << 8) | data[pos++];
            return val;
        };

        /* MIDI note number to note name conversion */
        const noteNames = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        const midiToName = (num) => noteNames[num % 12] + (Math.floor(num / 12) - 1);

        /* Parse the header chunk */
        if (String.fromCharCode(...data.slice(0, 4)) !== 'MThd') return notes;
        pos = 4;
        readInt(4); /* header length */
        const format = readInt(2);
        const trackCount = readInt(2);
        let ticksPerBeat = readInt(2);

        /* Default tempo: 120 BPM = 500000 microseconds per beat */
        let microsecondsPerBeat = 500000;

        /* Parse each track */
        for (let t = 0; t < trackCount; t++) {
            if (pos >= data.length) break;

            /* Track header: MTrk + length */
            const marker = String.fromCharCode(...data.slice(pos, pos + 4));
            if (marker !== 'MTrk') break;
            pos += 4;
            const trackLength = readInt(4);
            const trackEnd = pos + trackLength;

            let tick = 0;
            let runningStatus = 0;
            const activeNotes = {}; /* {noteNum: {startTick, velocity}} */

            while (pos < trackEnd) {
                const deltaTick = readVLQ();
                tick += deltaTick;

                let statusByte = data[pos];

                /* Handle running status (re-use previous status byte) */
                if (statusByte < 0x80) {
                    statusByte = runningStatus;
                } else {
                    pos++;
                    if (statusByte < 0xF0) runningStatus = statusByte;
                }

                const channel = statusByte & 0x0F;
                const msgType = statusByte & 0xF0;

                if (msgType === 0x90) {
                    /* Note On */
                    const note = data[pos++];
                    const velocity = data[pos++];
                    if (velocity > 0) {
                        activeNotes[note] = { startTick: tick, velocity: velocity / 127 };
                    } else {
                        /* Velocity 0 = Note Off */
                        if (activeNotes[note]) {
                            const startTick = activeNotes[note].startTick;
                            const timeInSeconds = (startTick / ticksPerBeat) * (microsecondsPerBeat / 1000000);
                            const durationTicks = tick - startTick;
                            const durationSeconds = (durationTicks / ticksPerBeat) * (microsecondsPerBeat / 1000000);
                            notes.push({
                                time: timeInSeconds,
                                name: midiToName(note),
                                duration: Math.max(durationSeconds, 0.05),
                                velocity: activeNotes[note].velocity,
                            });
                            delete activeNotes[note];
                        }
                    }
                } else if (msgType === 0x80) {
                    /* Note Off */
                    const note = data[pos++];
                    pos++; /* velocity (ignored for note-off) */
                    if (activeNotes[note]) {
                        const startTick = activeNotes[note].startTick;
                        const timeInSeconds = (startTick / ticksPerBeat) * (microsecondsPerBeat / 1000000);
                        const durationTicks = tick - startTick;
                        const durationSeconds = (durationTicks / ticksPerBeat) * (microsecondsPerBeat / 1000000);
                        notes.push({
                            time: timeInSeconds,
                            name: midiToName(note),
                            duration: Math.max(durationSeconds, 0.05),
                            velocity: activeNotes[note].velocity,
                        });
                        delete activeNotes[note];
                    }
                } else if (msgType === 0xA0 || msgType === 0xB0 || msgType === 0xE0) {
                    /* Aftertouch, Control Change, Pitch Bend — 2 data bytes */
                    pos += 2;
                } else if (msgType === 0xC0 || msgType === 0xD0) {
                    /* Program Change, Channel Pressure — 1 data byte */
                    pos += 1;
                } else if (statusByte === 0xFF) {
                    /* Meta event */
                    const metaType = data[pos++];
                    const metaLength = readVLQ();
                    if (metaType === 0x51 && metaLength === 3) {
                        /* Tempo change */
                        microsecondsPerBeat = (data[pos] << 16) | (data[pos + 1] << 8) | data[pos + 2];
                    }
                    pos += metaLength;
                } else if (statusByte === 0xF0 || statusByte === 0xF7) {
                    /* SysEx event */
                    const sysexLength = readVLQ();
                    pos += sysexLength;
                }
            }

            pos = trackEnd;
        }

        /* Sort by time */
        notes.sort((a, b) => a.time - b.time);
        return notes;
    }

    /* =====================================================================
     * PLAYBACK CONTROLS
     * ===================================================================== */

    /** Toggle between play and pause states */
    togglePlayPause() {
        if (this.isPlaying) {
            this.pause();
        } else {
            this.play();
        }
    }

    /** Start or resume playback */
    play() {
        if (!this.Tone || !this.part) return;

        this.Tone.getTransport().start();
        this.part.start(0);
        this.isPlaying = true;

        this.updatePlayButton(true);
        this.updatePlayerStatus('Playing');
        this.startProgressUpdates();
    }

    /** Pause playback */
    pause() {
        if (!this.Tone) return;

        this.Tone.getTransport().pause();
        this.isPlaying = false;

        this.updatePlayButton(false);
        this.updatePlayerStatus('Paused');
        this.stopProgressUpdates();
    }

    /** Stop playback and reset to beginning */
    stop() {
        if (!this.Tone) return;

        this.Tone.getTransport().stop();
        this.Tone.getTransport().position = 0;
        this.part?.stop();
        this.isPlaying = false;

        this.updatePlayButton(false);
        this.updatePlayerStatus('Stopped');
        this.stopProgressUpdates();
        this.updateProgress(0);
    }

    /** Close the player UI and clean up */
    closePlayer() {
        this.stop();
        this.synth?.dispose();
        this.part?.dispose();
        this.synth = null;
        this.part = null;
        this.currentSongId = null;
        document.getElementById('audio-player')?.remove();
    }

    /* =====================================================================
     * UI HELPERS
     * ===================================================================== */

    /** Enable or disable player control buttons */
    enableControls(enabled) {
        const playBtn = document.getElementById('audio-play-btn');
        const stopBtn = document.getElementById('audio-stop-btn');
        if (playBtn) playBtn.disabled = !enabled;
        if (stopBtn) stopBtn.disabled = !enabled;
    }

    /** Update the play button icon between play/pause states */
    updatePlayButton(playing) {
        const btn = document.getElementById('audio-play-btn');
        if (!btn) return;
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = playing ? 'fa-solid fa-pause' : 'fa-solid fa-play';
        }
        btn.setAttribute('aria-label', playing ? 'Pause' : 'Play');
        btn.setAttribute('title', playing ? 'Pause' : 'Play');
    }

    /** Update the status text in the player */
    updatePlayerStatus(text) {
        const el = document.getElementById('audio-status');
        if (el) el.textContent = text;
    }

    /** Update the progress bar and current time display */
    updateProgress(fraction) {
        const bar = document.getElementById('audio-progress-bar');
        const timeEl = document.getElementById('audio-time-current');
        if (bar) bar.style.width = (fraction * 100) + '%';
        if (timeEl) timeEl.textContent = this.formatTime(fraction * this.duration);
    }

    /** Start periodic progress bar updates */
    startProgressUpdates() {
        this.stopProgressUpdates();
        this.progressInterval = setInterval(() => {
            if (!this.Tone || !this.isPlaying) return;
            const currentTime = this.Tone.getTransport().seconds;
            const fraction = this.duration > 0 ? currentTime / this.duration : 0;
            this.updateProgress(Math.min(fraction, 1));

            /* Auto-stop when playback reaches the end */
            if (currentTime >= this.duration) {
                this.stop();
            }
        }, 250);
    }

    /** Stop the progress update interval */
    stopProgressUpdates() {
        if (this.progressInterval) {
            clearInterval(this.progressInterval);
            this.progressInterval = null;
        }
    }

    /** Show a download link when playback is unavailable */
    showDownloadLink(songId) {
        const player = document.getElementById('audio-player');
        if (!player) return;
        const url = this.app.config.audioBasePath + songId + '.mid';
        const statusEl = player.querySelector('#audio-status');
        if (statusEl) {
            statusEl.innerHTML = `<a href="${url}" download class="text-decoration-underline">Download MIDI file</a>`;
        }
    }

    /**
     * Format seconds into mm:ss display.
     *
     * @param {number} seconds
     * @returns {string}
     */
    formatTime(seconds) {
        const m = Math.floor(seconds / 60);
        const s = Math.floor(seconds % 60);
        return m + ':' + String(s).padStart(2, '0');
    }
}
