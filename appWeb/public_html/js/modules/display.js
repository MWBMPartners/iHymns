/**
 * iHymns — Display Preferences & Presentation Mode Module (#95)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides per-song display controls: font size adjustment, line
 * spacing, verse number toggle, chorus highlighting, and auto-scroll.
 * Settings persist in localStorage.
 *
 * PRESENTATION MODE MOVED OUT (#1714 item 5, 2026-09): this file used to
 * build its OWN full-screen presentation overlay — a whole-song scrolling
 * clone with no arrow-key navigation — as a SEPARATE thing from
 * present-mode.js's overlay (the "Present" toolbar button's one-section-
 * at-a-time view with arrow keys, a focus trap, foot-pedal and swipe
 * support). Two overlays meant the "P" key opened the worse one, and the
 * global "B" key looked up an id (`#presentation-overlay`) that the good
 * overlay never set — so blanking the screen silently did nothing while
 * actually presenting. There is now exactly ONE presentation overlay
 * (present-mode.js), and this file's job is just to call into it — the
 * three thin wrappers below (`togglePresentationMode()`,
 * `toggleBlankScreen()`, the presentation-button click in
 * `bindToolbarEvents()`) and the presentation-aware half of `cleanup()`.
 * They all dynamically `import('./present-mode.js')` rather than a static
 * top-level import, so this file loading (eagerly, at app boot) does not
 * also force-load the presentation module on every page that never
 * presents anything — see `cleanup()`'s own comment for how it avoids
 * paying that import even once on a typical navigation.
 * @see js/modules/present-mode.js the ONE overlay, and its own header note
 *      on the merge.
 * @see tests/test-presentation-overlay-merge.js the tree-derived guard
 *      that keeps this from splitting back into two overlays.
 */
import { STORAGE_DISPLAY } from '../constants.js';
/* #1267 — jumpToSection()'s announce + reduce-motion handling deliberately
   reuses the SAME two helpers service-follow.js's _scrollToComponent()
   already uses for an almost-identical "scroll to a lyric section" job,
   rather than a third private copy of either check (modularity rule). */
import { announce } from '../utils/announce.js';
import { prefersReducedMotion } from '../utils/motion.js';

/**
 * Pure helper for jumpToSection() below — given the top offsets of every
 * lyric-component section (ascending, same coordinate space as scrollY)
 * and the current scroll position, returns which section index a "next"
 * (dir=1) or "previous" (dir=-1) command should land on. Exported
 * standalone (rather than folded into jumpToSection itself) so
 * tests/test-midi-input.js can exercise the maths without a DOM (#1267).
 *
 * ELI5: figures out which verse/chorus box you're currently looking at,
 * then picks the one right after (or before) it — clamped so pressing
 * "next" on the last section (or "previous" on the first) just stays put
 * instead of running off the end of the list.
 *
 * Detail: "current section" is the LAST index whose top is at or before
 * `scrollY` (within a small EPSILON tolerance for the sub-pixel rounding
 * a smooth scrollIntoView() landing can leave behind) — i.e. whichever
 * section's start you've most recently scrolled past. next/prev then
 * move exactly one section from there, clamped to the array bounds,
 * regardless of how far INTO that section the current scroll position
 * actually is — so "next" always advances a whole section even if
 * you're already most of the way down a long verse, and "previous"
 * always returns to the section before the current one rather than
 * back to the top of the current one.
 *
 * @param {number[]} tops Ascending top-offsets of each section, in the
 *   same coordinate space as scrollY (see jumpToSection()'s own
 *   coordinate note for how that space is built for each surface).
 * @param {number} scrollY Current scroll position in that same space.
 * @param {1|-1} dir +1 = next section, -1 = previous section.
 * @returns {number|null} Target index, or null when there is nothing to
 *   jump to (no sections) or dir is not exactly +1/-1.
 */
export function nextSectionIndex(tops, scrollY, dir) {
    if (!Array.isArray(tops) || tops.length === 0) return null;
    if (dir !== 1 && dir !== -1) return null;

    const EPSILON = 2; /* px tolerance — see doc-comment above */
    let current = 0;
    for (let i = 0; i < tops.length; i++) {
        if (tops[i] <= scrollY + EPSILON) current = i;
        else break;
    }

    return Math.max(0, Math.min(tops.length - 1, current + dir));
}

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

        /* The pre-service countdown timer moved to present-mode.js with the
           rest of presentation mode (#1714 item 5) — it lives there now,
           scoped to one open overlay, not on this always-alive instance. */

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
                <span class="btn btn-outline-secondary disabled" id="display-font-label" role="img"
                      aria-label="Current font size: ${Math.round(fontSize * 100)}%">${Math.round(fontSize * 100)}%</span>
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

        /* Presentation mode (#1714 item 5) — this button and the "Present"
           toolbar button (present-mode.js's own #btn-present) now open the
           exact same overlay; see this file's header note. Dynamically
           imported so this always-eager toolbar-binding code doesn't force
           the presentation module to load on every song page visit, only
           on the ones where someone actually asks to present. */
        document.getElementById('display-present-btn')?.addEventListener('click', () => {
            import('./present-mode.js').then((m) => m.openPresentMode());
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
     * Toggle presentation mode from the keyboard (#125) — the "P" key,
     * wired in app.js's keydown switch. Delegates entirely to
     * present-mode.js (#1714 item 5): that module decides whether to open
     * or close, since it's the one holding the "is anything open right
     * now?" state. Dynamically imported (not a static top-level import) so
     * loading this always-eager module doesn't also force-load the
     * presentation module for a page that never presents anything.
     */
    togglePresentationMode() {
        import('./present-mode.js').then((m) => m.togglePresentMode());
    }

    /** Apply font size to lyrics element */
    applyFontSize(lyricsEl) {
        const size = this.get('fontSize');
        lyricsEl.style.fontSize = `${size}em`;
    }

    /** Update the font size label in the toolbar */
    updateFontLabel() {
        const label = document.getElementById('display-font-label');
        if (!label) return;
        /* a11y audit M8 (2026-08-28): role="img" on this span means its
           aria-label is now the ONLY thing assistive tech reads — the
           visible textContent below is invisible to it. Both must be kept
           in sync on every change, or a screen-reader user would be told a
           value that stopped updating the moment the required role was
           added (the fix would have made this WORSE than the unlabelled-
           but-role-less span it replaced, which at least fell back to
           reading the live text). */
        const pct = Math.round(this.get('fontSize') * 100) + '%';
        label.textContent = pct;
        label.setAttribute('aria-label', `Current font size: ${pct}`);
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
     * SECTION NAVIGATION (#1267 — hands-free foot-pedal / Web MIDI turns)
     * ===================================================================== */

    /**
     * Jump to the next/previous lyric section (verse/chorus/bridge/...),
     * scrolling it into view. THE one action funnel both hands-free input
     * paths share (modularity rule): app.js's PageDown/PageUp keydown case
     * (a Bluetooth foot pedal that emits real keystrokes) and
     * midi-input.js's mapped 'next'/'prev' actions (raw Web MIDI hardware —
     * no keystrokes at all) both call this method directly rather than
     * each re-implementing the scroll/scan logic.
     *
     * Targets the in-page `.song-lyrics`. No-ops when it isn't there — this
     * is called from global key/MIDI handlers with no per-page guard of
     * their own beyond what they already check before calling it.
     *
     * Presentation mode is deliberately NOT one of the surfaces this
     * targets (#1714 item 5): present-mode.js's overlay shows one section
     * at a time and steps between them via its OWN PageDown/PageUp handling
     * (its `onKey`), and both app.js's keydown case and midi-input.js's
     * `_dispatch()` already check for that overlay FIRST and route to its
     * own Prev/Next before ever reaching this method — see either of their
     * own comments. This method's job is only the plain page.
     *
     * @param {1|-1} dir +1 = next section, -1 = previous section.
     */
    jumpToSection(dir) {
        const surface = document.querySelector('.song-lyrics');
        if (!surface) return;

        const comps = Array.from(surface.querySelectorAll('.lyric-component'));
        if (comps.length === 0) return;

        /* Coordinate space for nextSectionIndex(): the in-page lyrics scroll
           the document, the same element startAutoScroll() above already
           uses. */
        const scrollY = window.scrollY || document.documentElement.scrollTop || 0;
        const tops = comps.map((el) => el.getBoundingClientRect().top + scrollY);

        const targetIdx = nextSectionIndex(tops, scrollY, dir);
        if (targetIdx === null) return;

        const target = comps[targetIdx];
        /* Honour reduced motion (mirrors service-follow.js's
           _scrollToComponent() — see that method's own comment for why
           the OPTION, not just the CSS rule, has to be checked). */
        target.scrollIntoView({
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            block: 'start',
        });

        /* Announce the section change (WCAG 4.1.3) — a foot pedal or MIDI
           controller turn is exactly as silent to a screen reader as the
           scroll it produces; without this a blind user pressing the
           pedal gets no confirmation anything happened. */
        const label = (target.querySelector('.lyric-label')?.textContent || '').trim();
        announce(label ? `Now at ${label}` : `Section ${targetIdx + 1} of ${comps.length}`);
    }

    /* =====================================================================
     * PRESENTATION MODE — moved to present-mode.js (#1714 item 5)
     *
     * `enterPresentationMode()`, `exitPresentationMode()`,
     * `_closePresentation()`, `toggleBlankScreen()` (the old body),
     * `startCountdown()`, `stopCountdown()` and `_formatCountdown()` all
     * lived here, building this file's OWN full-screen overlay. They are
     * gone outright, not deprecated in place — this file's header explains
     * why, and present-mode.js is where the blank-screen control and the
     * countdown live now (`toggleBlank()`/`startCountdown()`/
     * `stopCountdown()`/`formatCountdown()` there). `togglePresentationMode()`
     * above and `toggleBlankScreen()` below are the two thin callers that
     * remain.
     * ===================================================================== */

    /**
     * Blank (or un-blank) the screen while presenting — the "B" key
     * (app.js's keydown switch calls this unconditionally, everywhere, so
     * it must stay a safe no-op off the song page). Delegates to
     * present-mode.js's `toggleBlankScreen()`, which reaches the ONE open
     * overlay directly (#1714 item 5) rather than this file's old approach
     * of looking up `#presentation-overlay` by id — an id the OTHER,
     * better overlay never set, which is exactly why "B" used to do
     * nothing while presenting from the "Present" button.
     */
    toggleBlankScreen() {
        import('./present-mode.js').then((m) => m.toggleBlankScreen());
    }

    /* =====================================================================
     * CLEANUP
     * ===================================================================== */

    /** Clean up when leaving a song page */
    cleanup() {
        this.stopAutoScroll();
        /* #1714 item 5 — the ONE presentation overlay now lives entirely in
           present-mode.js and is appended to <body>, OUTSIDE the swapped
           #page-content (rule #32 in .claude/CLAUDE.md: anything fixed to
           the screen outside the swapped page content must remove itself
           on EVERY navigation, as its first action, before any early
           return — a countdown or a round's auto-advance timer left
           running behind a closed overlay is a real bug, not a cosmetic
           one). router.js calls this cleanup() unconditionally on every
           navigation.
           The DOM check below, rather than an unconditional dynamic
           import(), means the overwhelming majority of navigations (no
           presentation overlay open) never pay for loading a module they
           don't need — the overlay can only exist at all if present-mode.js
           already ran once to build it, so on the rare navigation where it
           DOES exist, the import is always a cache hit, not a fresh
           network fetch. */
        if (document.querySelector('.presentation-overlay')) {
            import('./present-mode.js').then((m) => m.closePresentMode());
        }
    }
}
