/**
 * iHymns — Web MIDI foot-pedal / controller input (#1267)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE
 * -------
 * ELI5: some worship leaders drive the app with a MIDI foot pedal or
 * sustain-pedal controller instead of a keyboard or mouse — press the
 * pedal, the lyrics move to the next verse. This module listens for those
 * raw MIDI messages and turns them into the SAME "next section" / "previous
 * section" action a Bluetooth foot pedal's PageDown/PageUp keystrokes
 * already trigger (see app.js's keydown switch and present-mode.js's
 * onKey) — never a second, parallel implementation of the scroll/slide
 * logic (the modularity rule in .claude/CLAUDE.md).
 *
 * Two DIFFERENT hands-free input paths exist side by side for #1267, and
 * this file is only the SECOND of them:
 *   1. A Bluetooth foot pedal configured to emit real PageUp/PageDown
 *      KEYSTROKES — the browser sees an ordinary `keydown` event, so it is
 *      handled entirely by app.js's existing keydown switch (no MIDI
 *      involved at all).
 *   2. A MIDI foot pedal / controller wired over USB or Bluetooth MIDI —
 *      the browser sees raw MIDI byte triples via the Web MIDI API, with
 *      NO keystroke anywhere. THIS module decodes those.
 *
 * WHY PROGRESSIVE ENHANCEMENT, DEFAULT OFF
 * -----------------------------------------
 * `navigator.requestMIDIAccess()` is not universally supported (notably:
 * Safari, both desktop and iOS) and — critically — the FIRST call to it in
 * a browser that DOES support it pops a real permission prompt. Asking for
 * that permission unprompted, for a feature almost nobody has hardware
 * for, is exactly the kind of "why is this app asking for X" moment that
 * erodes trust. So: (a) `isSupported()` is checked before anything else —
 * an unsupported browser never even sees the Settings toggle (settings.js
 * hides the row entirely, mirroring audio.js's hideButtonsIfUnsupported()
 * posture, #602); (b) the setting itself defaults OFF (settings.js
 * `defaults.midiPedal = false`); (c) `start()` — the only thing that calls
 * requestMIDIAccess() — is called from app.js's init() ONLY when the
 * setting is already true, and from settings.js's change handler when the
 * user flips it on. Nothing in this module requests access on its own.
 *
 * DEFAULT MAP (v1, no learn-mode — see #1267 for a future per-device
 * mapping UI)
 * -------------------------------------------------------------------
 *   Note On  (status & 0xF0 === 0x90), velocity > 0   -> 'next'
 *   Note Off (status & 0xF0 === 0x80), or Note On
 *            with velocity 0 (running-status Note Off) -> null (ignored)
 *   Control Change (status & 0xF0 === 0xB0):
 *     CC64 (sustain pedal), value >= 64                -> 'next'
 *     CC67 (soft pedal)                                 -> 'prev'
 *     any other controller number                       -> null
 *   Anything else (Program Change, Pitch Bend, ...)      -> null
 *
 * The MIDI channel nibble (the low 4 bits of the status byte) is masked
 * off and ignored — a pedal on channel 2 behaves identically to one on
 * channel 1. See https://midi.org/summary-of-midi-1-0-messages for the
 * byte layout this switches on.
 *
 * @see js/modules/display.js jumpToSection() — the shared action funnel.
 * @see js/modules/present-mode.js onKey() — the slide-overlay counterpart.
 * @see js/modules/service-follow.js — an existing '.lyric-component' scroll
 *      consumer this module's dispatch logic was modelled on.
 */

/**
 * Pure MIDI-message decoder. Exported standalone (no `this`, no DOM, no
 * class state) so tests/test-midi-input.js can run the whole truth table
 * without a browser or a fake MIDIAccess object (#1267).
 *
 * "Rising edge" note: CC64 is documented above as "value >= 64 -> next",
 * not "only on the crossing from <64 to >=64". A genuinely stateless,
 * single-message function cannot see the PREVIOUS value to detect a true
 * edge — MidiInput's own 250 ms debounce (see DEBOUNCE_MS below) is what
 * keeps a HELD pedal (which many controllers report as a continuous
 * stream of CC64=127 messages) from firing 'next' dozens of times a
 * second; it does not perfectly replicate press/release edge detection,
 * but for a single momentary tap — the pedal's actual use case — the two
 * are indistinguishable in practice.
 *
 * @param {number} status First MIDI byte (status | channel nibble).
 * @param {number} data1  Second MIDI byte (note number, or CC number).
 * @param {number} data2  Third MIDI byte (velocity, or CC value).
 * @returns {'next'|'prev'|null}
 */
export function midiEventToAction(status, data1, data2) {
    const statusType = status & 0xf0; /* mask off the channel nibble */

    if (statusType === 0x90) {
        /* Note On. A velocity of 0 is the MIDI "running status" idiom for
           Note Off — many controllers send THIS instead of a real 0x80
           byte, so it must be treated identically to one. */
        return data2 > 0 ? 'next' : null;
    }

    if (statusType === 0x80) {
        return null; /* Note Off */
    }

    if (statusType === 0xb0) {
        if (data1 === 64) return data2 >= 64 ? 'next' : null; /* sustain */
        if (data1 === 67) return 'prev';                       /* soft */
        return null; /* some other controller number */
    }

    return null; /* Program Change, Pitch Bend, Aftertouch, System, ... */
}

/** Debounce window (ms) against pedal bounce / a held-down sustained CC stream. */
const DEBOUNCE_MS = 250;

export class MidiInput {
    /**
     * @param {object} app Reference to the main iHymnsApp instance.
     */
    constructor(app) {
        this.app = app;

        /** @type {MIDIAccess|null} Set only while actually listening. */
        this.access = null;

        /** @type {number} Date.now() of the last dispatched action. */
        this._lastActionAt = 0;

        /* Bound once so add/removeEventListener target the SAME function
           reference on every input port. */
        this._onMessage = this._onMessage.bind(this);
        this._onStateChange = this._onStateChange.bind(this);
    }

    /**
     * Feature detection. Notably false in Safari (desktop + iOS) and
     * older Firefox. Called both by app.js (whether to even construct
     * this class's Settings-visible affordance) and by settings.js
     * (whether to show the toggle row at all).
     * @returns {boolean}
     */
    static isSupported() {
        return typeof navigator !== 'undefined' && 'requestMIDIAccess' in navigator;
    }

    /**
     * Begin listening. The ONLY method in this file that calls
     * navigator.requestMIDIAccess() — and therefore the only one that can
     * trigger a browser permission prompt. Safe to call more than once
     * (a no-op while already running); safe to call in an unsupported
     * browser (a no-op).
     */
    async start() {
        if (!MidiInput.isSupported()) return;
        if (this.access) return; /* already listening */

        try {
            /* sysex: false — this app only ever reads Note On/Off and CC
               messages; requesting system-exclusive access would widen
               the permission prompt for no benefit here. */
            this.access = await navigator.requestMIDIAccess({ sysex: false });
        } catch (err) {
            /* Permission denied, or no MIDI hardware/driver available.
               Degrade silently — this is progressive enhancement, not a
               required feature. */
            console.warn('[iHymns] Web MIDI access unavailable:', err);
            this.access = null;
            return;
        }

        this._attachAllInputs();
        /* Hot-plug (#1267 spec): a pedal plugged in AFTER start() was
           already called (e.g. the browser was granted access once this
           session, then the user plugs the pedal in mid-service) still
           needs its 'midimessage' listener wired. Ports that DISCONNECT
           drop their own listeners with them — nothing to clean up there. */
        this.access.addEventListener('statechange', this._onStateChange);
    }

    /** Stop listening and release every port-level listener. */
    stop() {
        if (!this.access) return;
        this.access.removeEventListener('statechange', this._onStateChange);
        this._detachAllInputs();
        this.access = null;
    }

    /** Attach the message listener to every currently-known input port. */
    _attachAllInputs() {
        if (!this.access) return;
        this.access.inputs.forEach((input) => {
            input.addEventListener('midimessage', this._onMessage);
        });
    }

    /** Detach the message listener from every currently-known input port. */
    _detachAllInputs() {
        if (!this.access) return;
        this.access.inputs.forEach((input) => {
            input.removeEventListener('midimessage', this._onMessage);
        });
    }

    /**
     * MIDIAccess 'statechange' handler — wires a newly-connected input
     * port. @param {MIDIConnectionEvent} e
     */
    _onStateChange(e) {
        const port = e.port;
        if (port && port.type === 'input' && port.state === 'connected') {
            port.addEventListener('midimessage', this._onMessage);
        }
    }

    /**
     * Per-message handler: decode, debounce, dispatch.
     * @param {MIDIMessageEvent} e
     */
    _onMessage(e) {
        const data = e.data;
        if (!data || data.length < 3) return;

        const action = midiEventToAction(data[0], data[1], data[2]);
        if (!action) return;

        const now = Date.now();
        if (now - this._lastActionAt < DEBOUNCE_MS) return;
        this._lastActionAt = now;

        this._dispatch(action);
    }

    /**
     * Route a decoded action to whichever "next/previous section" surface
     * is actually active — mirrors, key for key, app.js's PageDown/PageUp
     * case and present-mode.js's onKey PageDown/PageUp case (added
     * alongside this module for #1267), so a MIDI pedal reaches the exact
     * same two consumers a keystroke-emitting pedal already does. Never a
     * third implementation of the scroll/slide logic (modularity rule).
     *
     * @param {'next'|'prev'} action
     */
    _dispatch(action) {
        /* present-mode.js's slide-by-slide overlay has its own Prev/Next
           buttons wired to its internal next()/prev() closures, which it
           does not export — clicking them is the one way to reach that
           SAME code from outside the module without forking it. `.present-
           nav` is unique to that overlay (display.js's OWN presentation
           overlay, #presentation-overlay, has no such element). */
        const presentNav = document.querySelector('.presentation-overlay .present-nav');
        if (presentNav) {
            const btn = presentNav.querySelector(action === 'next' ? '.present-next' : '.present-prev');
            btn?.click();
            return;
        }

        if (document.querySelector('.song-lyrics') && this.app.display) {
            this.app.display.jumpToSection(action === 'next' ? 1 : -1);
        }
    }
}
