/**
 * iHymns — Display Preferences & Presentation Mode Module (#95)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides per-song display controls: font size adjustment, line
 * spacing, verse number toggle, chorus highlighting, presentation
 * mode (fullscreen), and auto-scroll. Settings persist in localStorage.
 */
import { escapeHtml } from '../utils/html.js';
import { STORAGE_DISPLAY } from '../constants.js';

export class Display {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key */
        this.storageKey = STORAGE_DISPLAY;

        /** @type {number|null} requestAnimationFrame ID for auto-scroll */
        this.autoScrollRAF = null;

        /** @type {boolean} Whether auto-scroll is active */
        this.autoScrollActive = false;

        /** @type {number|null} setInterval id for the pre-service countdown (#1273) */
        this.countdownTimer = null;

        /** Default display preferences */
        this.defaults = {
            fontSize: 1.0,          /* Multiplier: 0.5 – 5.0 */
            lineSpacing: 'normal',  /* compact, normal, spacious */
            showVerseNumbers: true,
            highlightChorus: true,
            autoScrollSpeed: 30,    /* Pixels per second */
        };
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Get a display preference value.
     * @param {string} key
     * @returns {*}
     */
    get(key) {
        try {
            const stored = JSON.parse(localStorage.getItem(this.storageKey)) || {};
            return key in stored ? stored[key] : this.defaults[key];
        } catch {
            return this.defaults[key];
        }
    }

    /**
     * Set a display preference value.
     * @param {string} key
     * @param {*} value
     */
    set(key, value) {
        try {
            const stored = JSON.parse(localStorage.getItem(this.storageKey)) || {};
            stored[key] = value;
            localStorage.setItem(this.storageKey, JSON.stringify(stored));
            this.app.syncStorage(this.storageKey);
        } catch {
            /* Ignore storage errors */
        }
    }

    /**
     * Initialise display controls on a song page.
     * Injects a toolbar above the lyrics and applies stored preferences.
     * Called by router after song page loads.
     */
    initSongPage() {
        const lyricsEl = document.querySelector('.song-lyrics');
        if (!lyricsEl) return;

        /* Inject the display toolbar */
        this.renderToolbar(lyricsEl);

        /* Apply stored preferences */
        this.applyFontSize(lyricsEl);
        this.applyLineSpacing(lyricsEl);
        this.applyVerseNumbers(lyricsEl);
        this.applyChorusHighlight(lyricsEl);

        /* Practice / memorisation mode (#402) */
        this.initPracticeMode(lyricsEl);

        /* Protect lyrics content — prevent copy/paste and right-click */
        this.protectLyrics(lyricsEl);
    }

    /**
     * Practice / memorisation mode (#402).
     * Cycles the song-lyrics data-practice-level attribute through
     * 0 (full) → 1 (dimmed) → 2 (hidden) → 0. Dimmed lets users read
     * while blurring context; hidden masks every line and reveals
     * individual lines on tap/hover — handy for memorisation.
     *
     * State is per-song (fresh on every navigation); users can bind
     * this to a keyboard shortcut later if desired.
     */
    initPracticeMode(lyricsEl) {
        const btn   = document.getElementById('btn-practice-mode');
        const label = document.getElementById('btn-practice-label');
        if (!btn) return;

        const labels = ['Practice', 'Dimmed', 'Hidden'];
        let level = 0;

        const apply = () => {
            lyricsEl.dataset.practiceLevel = String(level);
            btn.dataset.practiceLevel = String(level);
            btn.classList.toggle('active', level > 0);
            btn.setAttribute('aria-pressed', level > 0 ? 'true' : 'false');
            if (label) label.textContent = labels[level];
            /* Clear any stale reveal state when leaving a mode */
            lyricsEl.querySelectorAll('.lyric-line.revealed')
                .forEach(el => el.classList.remove('revealed'));
        };

        btn.addEventListener('click', () => {
            level = (level + 1) % labels.length;
            apply();
        });

        /* Tap a hidden line to reveal it as a hint (level 2 only). */
        lyricsEl.addEventListener('click', (e) => {
            if (lyricsEl.dataset.practiceLevel !== '2') return;
            const line = e.target.closest('.lyric-line');
            if (!line) return;
            line.classList.toggle('revealed');
        });

        apply();
    }

    /**
     * Prevent copy, cut, text selection, and right-click on lyrics content.
     * Scoped to the lyrics element only so the rest of the app remains usable.
     * @param {HTMLElement} lyricsEl
     */
    protectLyrics(lyricsEl) {
        /* Prevent text selection via CSS */
        lyricsEl.style.userSelect = 'none';
        lyricsEl.style.webkitUserSelect = 'none';

        /* Prevent copy and cut */
        lyricsEl.addEventListener('copy', (e) => e.preventDefault());
        lyricsEl.addEventListener('cut', (e) => e.preventDefault());

        /* Prevent right-click context menu */
        lyricsEl.addEventListener('contextmenu', (e) => e.preventDefault());

        /* Prevent drag (which can be used to extract text) */
        lyricsEl.addEventListener('dragstart', (e) => e.preventDefault());
    }

    /**
     * Render the display controls toolbar above the lyrics.
     * @param {HTMLElement} lyricsEl The .song-lyrics element
     */
    renderToolbar(lyricsEl) {
        /* Remove existing toolbar if present */
        document.getElementById('display-toolbar')?.remove();

        const toolbar = document.createElement('div');
        toolbar.id = 'display-toolbar';
        toolbar.className = 'display-toolbar d-flex flex-wrap gap-2 align-items-center mb-3 p-2 rounded border bg-body-tertiary';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Display controls');

        const fontSize = this.get('fontSize');
        const spacing = this.get('lineSpacing');
        const showNums = this.get('showVerseNumbers');
        const hlChorus = this.get('highlightChorus');

        toolbar.innerHTML = `
            <!-- Font size controls -->
            <div class="btn-group btn-group-sm" role="group" aria-label="Font size">
                <button type="button" class="btn btn-outline-secondary" id="display-font-down"
                        aria-label="Decrease font size" title="Smaller text">
                    <i class="fa-solid fa-minus" aria-hidden="true"></i>
                </button>
                <span class="btn btn-outline-secondary disabled" id="display-font-label"
                      aria-label="Current font size">${Math.round(fontSize * 100)}%</span>
                <button type="button" class="btn btn-outline-secondary" id="display-font-up"
                        aria-label="Increase font size" title="Larger text">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Line spacing -->
            <select class="form-select form-select-sm" id="display-spacing"
                    aria-label="Line spacing" style="width:auto">
                <option value="compact" ${spacing === 'compact' ? 'selected' : ''}>Compact</option>
                <option value="normal" ${spacing === 'normal' ? 'selected' : ''}>Normal</option>
                <option value="spacious" ${spacing === 'spacious' ? 'selected' : ''}>Spacious</option>
            </select>

            <!-- Toggles -->
            <div class="form-check form-switch mb-0 ms-1">
                <input class="form-check-input" type="checkbox" id="display-verse-numbers"
                       ${showNums ? 'checked' : ''}>
                <label class="form-check-label small" for="display-verse-numbers">Verses</label>
            </div>

            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="display-chorus-highlight"
                       ${hlChorus ? 'checked' : ''}>
                <label class="form-check-label small" for="display-chorus-highlight">Chorus</label>
            </div>

            <!-- Presentation & auto-scroll -->
            <div class="ms-auto d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="display-autoscroll-btn"
                        aria-label="Toggle auto-scroll" title="Auto-scroll">
                    <i class="fa-solid fa-arrows-down-to-line" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="display-present-btn"
                        aria-label="Presentation mode" title="Presentation mode">
                    <i class="fa-solid fa-expand" aria-hidden="true"></i>
                </button>
            </div>`;

        lyricsEl.before(toolbar);

        /* Bind events */
        this.bindToolbarEvents(lyricsEl);
    }

    /**
     * Bind event listeners for all toolbar controls.
     * @param {HTMLElement} lyricsEl
     */
    bindToolbarEvents(lyricsEl) {
        const fontSteps = [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 1.2, 1.4, 1.6, 1.8, 2.0, 2.5, 3.0, 3.5, 4.0, 5.0];

        /* Font size down */
        document.getElementById('display-font-down')?.addEventListener('click', () => {
            const current = this.get('fontSize');
            /* Find closest step at or below current, then go one lower */
            let idx = fontSteps.findIndex(s => s >= current);
            if (idx < 0) idx = fontSteps.length - 1;
            if (fontSteps[idx] === current) idx--;
            if (idx >= 0) {
                this.set('fontSize', fontSteps[idx]);
                this.applyFontSize(lyricsEl);
                this.updateFontLabel();
            }
        });

        /* Font size up */
        document.getElementById('display-font-up')?.addEventListener('click', () => {
            const current = this.get('fontSize');
            /* Find closest step above current */
            const idx = fontSteps.findIndex(s => s > current);
            if (idx >= 0) {
                this.set('fontSize', fontSteps[idx]);
                this.applyFontSize(lyricsEl);
                this.updateFontLabel();
            }
        });

        /* Line spacing */
        document.getElementById('display-spacing')?.addEventListener('change', (e) => {
            this.set('lineSpacing', e.target.value);
            this.applyLineSpacing(lyricsEl);
        });

        /* Verse numbers toggle */
        document.getElementById('display-verse-numbers')?.addEventListener('change', (e) => {
            this.set('showVerseNumbers', e.target.checked);
            this.applyVerseNumbers(lyricsEl);
        });

        /* Chorus highlight toggle */
        document.getElementById('display-chorus-highlight')?.addEventListener('change', (e) => {
            this.set('highlightChorus', e.target.checked);
            this.applyChorusHighlight(lyricsEl);
        });

        /* Auto-scroll */
        document.getElementById('display-autoscroll-btn')?.addEventListener('click', () => {
            this.toggleAutoScroll();
        });

        /* Presentation mode */
        document.getElementById('display-present-btn')?.addEventListener('click', () => {
            this.enterPresentationMode(lyricsEl);
        });
    }

    /* =====================================================================
     * APPLY PREFERENCES
     * ===================================================================== */

    /** Font size steps for adjustment */
    static FONT_STEPS = [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 1.2, 1.4, 1.6, 1.8, 2.0, 2.5, 3.0, 3.5, 4.0, 5.0];

    /**
     * Adjust font size by one step up or down (#125).
     * @param {number} direction 1 for increase, -1 for decrease
     */
    adjustFontSize(direction) {
        const lyricsEl = document.querySelector('.song-lyrics');
        if (!lyricsEl) return;

        const steps = Display.FONT_STEPS;
        const current = this.get('fontSize');

        if (direction > 0) {
            const idx = steps.findIndex(s => s > current);
            if (idx >= 0) {
                this.set('fontSize', steps[idx]);
                this.applyFontSize(lyricsEl);
                this.updateFontLabel();
            }
        } else {
            let idx = steps.findIndex(s => s >= current);
            if (idx < 0) idx = steps.length - 1;
            if (steps[idx] === current) idx--;
            if (idx >= 0) {
                this.set('fontSize', steps[idx]);
                this.applyFontSize(lyricsEl);
                this.updateFontLabel();
            }
        }
    }

    /**
     * Toggle presentation mode from keyboard (#125).
     */
    togglePresentationMode() {
        const overlay = document.getElementById('presentation-overlay');
        if (overlay) {
            this.exitPresentationMode();
        } else {
            const lyricsEl = document.querySelector('.song-lyrics');
            if (lyricsEl) this.enterPresentationMode(lyricsEl);
        }
    }

    /** Apply font size to lyrics element */
    applyFontSize(lyricsEl) {
        const size = this.get('fontSize');
        lyricsEl.style.fontSize = `${size}em`;
    }

    /** Update the font size label in the toolbar */
    updateFontLabel() {
        const label = document.getElementById('display-font-label');
        if (label) label.textContent = Math.round(this.get('fontSize') * 100) + '%';
    }

    /** Apply line spacing to lyrics element */
    applyLineSpacing(lyricsEl) {
        const spacing = this.get('lineSpacing');
        lyricsEl.classList.remove('spacing-compact', 'spacing-normal', 'spacing-spacious');
        lyricsEl.classList.add(`spacing-${spacing}`);
    }

    /** Show/hide verse number labels */
    applyVerseNumbers(lyricsEl) {
        const show = this.get('showVerseNumbers');
        lyricsEl.querySelectorAll('.lyric-label').forEach(el => {
            el.style.display = show ? '' : 'none';
        });
    }

    /** Apply chorus highlighting */
    applyChorusHighlight(lyricsEl) {
        const highlight = this.get('highlightChorus');
        lyricsEl.classList.toggle('chorus-highlight', highlight);
        lyricsEl.classList.toggle('chorus-plain', !highlight);
    }

    /* =====================================================================
     * AUTO-SCROLL
     * ===================================================================== */

    /** Toggle auto-scroll on/off */
    toggleAutoScroll() {
        if (this.autoScrollActive) {
            this.stopAutoScroll();
        } else {
            this.startAutoScroll();
        }
    }

    /**
     * Start auto-scrolling using requestAnimationFrame.
     * Uses rAF instead of setInterval for reliable scrolling on all
     * platforms including iOS Safari, which throttles setInterval.
     * Delta-time based so scroll speed is consistent regardless of
     * frame rate (e.g. 60fps, 120fps ProMotion, or throttled).
     */
    startAutoScroll() {
        this.autoScrollActive = true;
        const speed = this.get('autoScrollSpeed');

        const btn = document.getElementById('display-autoscroll-btn');
        if (btn) btn.classList.add('active', 'btn-primary');
        btn?.classList.remove('btn-outline-secondary');

        this._showFloatingStop();

        let lastTime = null;
        let remainder = 0;

        const tick = (timestamp) => {
            if (!this.autoScrollActive) return;

            if (lastTime !== null) {
                const delta = (timestamp - lastTime) / 1000; /* seconds */
                remainder += speed * delta;

                /*
                 * Accumulate fractional pixels and only scroll whole pixels.
                 * iOS Safari ignores sub-pixel scrollBy values (e.g. 0.5px
                 * at 60fps or 0.25px at 120fps ProMotion), causing auto-scroll
                 * to appear completely broken. By accumulating and flushing
                 * whole pixels we ensure visible movement on every platform.
                 *
                 * Use document.scrollingElement (W3C standard) to get the
                 * correct scrollable element — iOS Safari in standalone PWA
                 * mode may use document.body instead of documentElement (#353).
                 */
                const px = Math.floor(remainder);
                if (px >= 1) {
                    const scrollEl = document.scrollingElement || document.documentElement;
                    scrollEl.scrollTop += px;
                    remainder -= px;
                }
            }
            lastTime = timestamp;

            /* Stop at bottom of page */
            const scrollEl = document.scrollingElement || document.documentElement;
            const scrollTop = scrollEl.scrollTop;
            if ((window.innerHeight + scrollTop) >= document.body.scrollHeight - 2) {
                this.stopAutoScroll();
                return;
            }

            this.autoScrollRAF = requestAnimationFrame(tick);
        };

        this.autoScrollRAF = requestAnimationFrame(tick);
    }

    /** Stop auto-scrolling */
    stopAutoScroll() {
        this.autoScrollActive = false;
        if (this.autoScrollRAF) {
            cancelAnimationFrame(this.autoScrollRAF);
            this.autoScrollRAF = null;
        }

        const btn = document.getElementById('display-autoscroll-btn');
        if (btn) btn.classList.remove('active', 'btn-primary');
        btn?.classList.add('btn-outline-secondary');

        this._hideFloatingStop();
    }

    _showFloatingStop() {
        if (document.getElementById('autoscroll-fab')) return;
        const fab = document.createElement('button');
        fab.id = 'autoscroll-fab';
        fab.type = 'button';
        fab.className = 'btn btn-primary btn-autoscroll-fab';
        fab.setAttribute('aria-label', 'Stop auto-scroll');
        fab.title = 'Stop auto-scroll';
        fab.innerHTML = '<i class="fa-solid fa-stop me-1" aria-hidden="true"></i> Stop';
        fab.addEventListener('click', () => this.stopAutoScroll());
        document.body.appendChild(fab);
    }

    _hideFloatingStop() {
        document.getElementById('autoscroll-fab')?.remove();
    }

    /* =====================================================================
     * PRESENTATION MODE
     * ===================================================================== */

    /**
     * Enter presentation mode — fullscreen with large text.
     * @param {HTMLElement} lyricsEl
     */
    enterPresentationMode(lyricsEl) {
        const songPage = lyricsEl.closest('.page-song');
        if (!songPage) return;

        /* Create presentation overlay */
        document.getElementById('presentation-overlay')?.remove();

        const overlay = document.createElement('div');
        overlay.id = 'presentation-overlay';
        overlay.className = 'presentation-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-label', 'Presentation mode');

        /* Get song title */
        const title = songPage.querySelector('h1')?.textContent.trim() || '';
        const songNum = songPage.querySelector('.song-number-badge-lg')?.textContent.trim() || '';

        overlay.innerHTML = `
            <div class="presentation-header">
                <div class="presentation-title">
                    ${songNum ? `<span class="presentation-number">${escapeHtml(songNum)}</span>` : ''}
                    <span>${escapeHtml(title)}</span>
                </div>
                <div class="presentation-controls">
                    <label class="visually-hidden" for="presentation-countdown-select">Pre-service countdown</label>
                    <select class="form-select form-select-sm presentation-countdown-select"
                            id="presentation-countdown-select" aria-label="Pre-service countdown">
                        <option value="0">Countdown…</option>
                        <option value="5">5 min</option>
                        <option value="10">10 min</option>
                        <option value="15">15 min</option>
                        <option value="20">20 min</option>
                    </select>
                    <button type="button" class="btn btn-dark btn-sm" id="presentation-blank-btn"
                            aria-pressed="false" aria-label="Blank the screen (press B)" title="Blank screen (B)">
                        <i class="fa-solid fa-circle-half-stroke me-1" aria-hidden="true"></i> Blank
                    </button>
                    <button type="button" class="btn btn-light btn-sm" id="presentation-close-btn"
                            aria-label="Exit presentation mode">
                        <i class="fa-solid fa-compress me-1" aria-hidden="true"></i> Exit
                    </button>
                </div>
            </div>
            <div class="presentation-lyrics">
                ${lyricsEl.innerHTML}
            </div>
            <div class="presentation-countdown" id="presentation-countdown" hidden
                 title="Click to dismiss the countdown">
                <div class="presentation-countdown-label">Service begins in</div>
                <div class="presentation-countdown-time" id="presentation-countdown-time"
                     role="timer" aria-live="off">0:00</div>
            </div>`;

        document.body.appendChild(overlay);

        /* Protect lyrics in presentation mode too */
        const presLyrics = overlay.querySelector('.presentation-lyrics');
        if (presLyrics) this.protectLyrics(presLyrics);

        /* Enter fullscreen */
        if (overlay.requestFullscreen) {
            overlay.requestFullscreen().catch(() => {
                /* Fullscreen not available — still show overlay */
            });
        }

        /* Add presentation class to body */
        document.body.classList.add('presentation-active');

        /* Close button */
        overlay.querySelector('#presentation-close-btn')?.addEventListener('click', () => {
            this.exitPresentationMode();
        });

        /* Blank/black screen toggle (#1273) — operator control; the "B" key
           (wired in app.js) does the same hands-on-keyboard blackout. */
        overlay.querySelector('#presentation-blank-btn')?.addEventListener('click', () => {
            this.toggleBlankScreen();
        });

        /* Pre-service countdown (#1273) — picking a preset starts/replaces the
           timer; the "Countdown…" option (value 0) clears it. */
        overlay.querySelector('#presentation-countdown-select')?.addEventListener('change', (e) => {
            const mins = parseInt(e.target.value, 10) || 0;
            if (mins > 0) {
                this.startCountdown(mins);
            } else {
                this.stopCountdown();
            }
        });

        /* Tapping the countdown (e.g. on the projector/touch device) dismisses
           it — Escape exits and "B" blanks regardless, via the key handlers. */
        overlay.querySelector('#presentation-countdown')?.addEventListener('click', () => {
            this.stopCountdown();
        });

        /* Escape key to close */
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                this.exitPresentationMode();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);

        /* Fullscreen change — exit if user exits fullscreen */
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                this.exitPresentationMode();
            }
        }, { once: true });
    }

    /** Exit presentation mode */
    exitPresentationMode() {
        /* Clear any running pre-service countdown so its interval doesn't
           keep firing after the overlay is gone (#1273). */
        this.stopCountdown();

        document.body.classList.remove('presentation-active');
        document.getElementById('presentation-overlay')?.remove();

        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
    }

    /* =====================================================================
     * PRESENTATION UTILITY SLIDES (#1273)
     * Blank/black screen + a pre-service countdown — the live-operation
     * controls worship presenters expect (ProPresenter / WorshipTools ship
     * these). Pure client-side over the existing presentation overlay; no
     * song data, no schema, no network.
     * ===================================================================== */

    /**
     * Toggle the blank/black screen in presentation mode.
     *
     * ELI5: hides the words behind a black screen so the congregation sees
     * nothing between songs, then brings them back.
     *
     * Detailed: flips the `presentation-blank` class on the overlay (CSS
     * blacks it out and hides the header/lyrics/countdown) and mirrors the
     * state into the Blank button's `aria-pressed`. No-ops when not in
     * presentation mode, so the bound "B" key is harmless on other pages.
     * MDN: https://developer.mozilla.org/en-US/docs/Web/API/Element/classList
     */
    toggleBlankScreen() {
        const overlay = document.getElementById('presentation-overlay');
        if (!overlay) return;

        const blanked = overlay.classList.toggle('presentation-blank');

        const btn = document.getElementById('presentation-blank-btn');
        if (btn) btn.setAttribute('aria-pressed', blanked ? 'true' : 'false');
    }

    /**
     * Start (or restart) a pre-service countdown overlay.
     *
     * ELI5: shows a big "Service begins in 5:00" timer that counts down to
     * zero so people know when to be seated.
     *
     * Detailed: anchors on an absolute end timestamp (`Date.now() + minutes`)
     * and recomputes the remaining seconds on every tick, so the display
     * can't drift even when a tick is delayed or the tab is throttled. Ticks
     * every 250ms for a crisp final second; on reaching zero it stops
     * ticking but leaves "0:00" on screen. Replaces any prior run.
     *
     * @param {number} minutes Whole minutes to count down from (clamped 1–180).
     */
    startCountdown(minutes) {
        const layer = document.getElementById('presentation-countdown');
        const timeEl = document.getElementById('presentation-countdown-time');
        if (!layer || !timeEl) return;

        /* Clear any previous run first so presets replace cleanly. */
        this.stopCountdown();

        /* Clamp to a sane range so a stray value can't run away. */
        const mins = Math.min(180, Math.max(1, Math.floor(minutes) || 0));
        const endAt = Date.now() + mins * 60 * 1000;

        const render = () => {
            const remaining = Math.max(0, Math.round((endAt - Date.now()) / 1000));
            timeEl.textContent = this._formatCountdown(remaining);
            if (remaining <= 0 && this.countdownTimer) {
                /* Reached zero — stop ticking but leave 0:00 displayed. */
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        };

        layer.hidden = false;
        render();
        this.countdownTimer = setInterval(render, 250);
    }

    /**
     * Stop the pre-service countdown and hide its overlay.
     *
     * ELI5: turns the countdown timer off.
     *
     * Detailed: clears the interval, hides the countdown layer, and resets
     * the header `<select>` back to "Countdown…" so the control reflects the
     * off state. Safe to call when nothing is running.
     */
    stopCountdown() {
        if (this.countdownTimer) {
            clearInterval(this.countdownTimer);
            this.countdownTimer = null;
        }
        const layer = document.getElementById('presentation-countdown');
        if (layer) layer.hidden = true;
        const select = document.getElementById('presentation-countdown-select');
        if (select) select.value = '0';
    }

    /**
     * Format whole seconds as `M:SS` (or `H:MM:SS` past an hour).
     * @param {number} totalSeconds
     * @returns {string}
     */
    _formatCountdown(totalSeconds) {
        const s = Math.max(0, totalSeconds);
        const hours = Math.floor(s / 3600);
        const mins = Math.floor((s % 3600) / 60);
        const secs = s % 60;
        const pad = (n) => String(n).padStart(2, '0');
        return hours > 0
            ? `${hours}:${pad(mins)}:${pad(secs)}`
            : `${mins}:${pad(secs)}`;
    }

    /* =====================================================================
     * CLEANUP
     * ===================================================================== */

    /** Clean up when leaving a song page */
    cleanup() {
        this.stopAutoScroll();
        this.exitPresentationMode();
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
}
