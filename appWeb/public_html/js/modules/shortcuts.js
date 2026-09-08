/**
 * iHymns — Keyboard Shortcuts Help Overlay (#104)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shows a categorised keyboard shortcuts reference overlay when
 * the user presses '?' from any page (outside input fields).
 * Dismissible via Escape or the close button.
 *
 * a11y audit H2 (2026-08-30): this overlay declared `aria-modal="true"`
 * but never actually moved focus into it, trapped Tab, or restored focus
 * on close — the mispaired aria-modal was WORSE than no dialog semantics
 * at all, because it told a screen reader "you are now confined to this
 * dialog" while focus stayed on the page behind it. Fixed by adopting the
 * shared recipe in js/utils/dialog-a11y.js (openModalDialog()) — see
 * show()/hide() below.
 * @link https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/
 */
import { openModalDialog } from '../utils/dialog-a11y.js';

export class Shortcuts {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;
        this.visible = false;
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /** Toggle the shortcuts overlay on/off */
    toggle() {
        if (this.visible) {
            this.hide();
        } else {
            this.show();
        }
    }

    /** Show the shortcuts overlay */
    show() {
        if (this.visible) return;
        this.visible = true;

        document.getElementById('shortcuts-overlay')?.remove();

        const overlay = document.createElement('div');
        overlay.id = 'shortcuts-overlay';
        overlay.className = 'shortcuts-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-label', 'Keyboard shortcuts');
        overlay.setAttribute('aria-modal', 'true');

        overlay.innerHTML = `
            <div class="shortcuts-dialog">
                <div class="shortcuts-header">
                    <h2 class="h5 mb-0">
                        <i class="fa-regular fa-keyboard me-2" aria-hidden="true"></i>
                        Keyboard Shortcuts
                    </h2>
                    <button type="button" class="btn-close" id="shortcuts-close-btn" aria-label="Close"></button>
                </div>
                <div class="shortcuts-body">
                    <div class="shortcuts-section">
                        <h3 class="shortcuts-section-title">Navigation</h3>
                        <dl class="shortcuts-list">
                            <div class="shortcut-row">
                                <dt><kbd>/</kbd> or <kbd>Ctrl</kbd>+<kbd>K</kbd></dt>
                                <dd>Open search</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>#</kbd></dt>
                                <dd>Open number pad</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>&larr;</kbd></dt>
                                <dd>Previous song</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>&rarr;</kbd></dt>
                                <dd>Next song</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="shortcuts-section">
                        <h3 class="shortcuts-section-title">Actions</h3>
                        <dl class="shortcuts-list">
                            <div class="shortcut-row">
                                <dt><kbd>F</kbd></dt>
                                <dd>Toggle favourite</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>P</kbd></dt>
                                <dd>Presentation mode (one section at a time)</dd>
                            </div>
                            <!-- #1714 left-over. That fix made the B key work
                                 and updated /help's shortcut table, but this
                                 second, on-screen list still had no B row — so
                                 the app was describing its own shortcuts two
                                 different ways. app.js's keydown switch handles
                                 'b'/'B' -> display.toggleBlankScreen(). -->
                            <div class="shortcut-row">
                                <dt><kbd>B</kbd></dt>
                                <dd>Blank the screen while presenting</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>L</kbd></dt>
                                <dd>Open set lists</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>S</kbd></dt>
                                <dd>Auto-scroll</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>Space</kbd></dt>
                                <dd>Pause auto-scroll</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>PageDown</kbd></dt>
                                <dd>Next section (foot pedal / MIDI, #1267)</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>PageUp</kbd></dt>
                                <dd>Previous section (foot pedal / MIDI, #1267)</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>+</kbd> / <kbd>-</kbd></dt>
                                <dd>Font size</dd>
                            </div>
                            <!-- Corrected 2026-09-08. This row used to read
                                 "Close overlay / search". Escape has not closed
                                 anything to do with search since #812 removed the
                                 header search bar — app.js's Escape branch now only
                                 hides this overlay, and its own comment says so
                                 ("There is nothing left to close on this path").
                                 Search is a page of its own now, which you leave by
                                 navigating away. Escape does also leave Presentation
                                 mode (present-mode.js), which is worth saying here
                                 because the P row sits a few lines above. -->
                            <div class="shortcut-row">
                                <dt><kbd>Esc</kbd></dt>
                                <dd>Close this overlay or Presentation mode</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>?</kbd></dt>
                                <dd>Show this help</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="shortcuts-section">
                        <h3 class="shortcuts-section-title">Quick-Jump</h3>
                        <dl class="shortcuts-list">
                            <div class="shortcut-row">
                                <dt><kbd>0</kbd>&ndash;<kbd>9</kbd></dt>
                                <dd>Type song number</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>Enter</kbd></dt>
                                <dd>Go to song</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>Backspace</kbd></dt>
                                <dd>Delete last digit</dd>
                            </div>
                            <!-- Added 2026-09-08. app.js has always cleared a
                                 half-typed song number on Escape, right beside the
                                 Enter and Backspace handling this list already
                                 documents — but only those two were ever listed, so
                                 the way out of a mistyped number was undocumented.
                                 Note this happens BEFORE the general Escape branch,
                                 so while you are mid-number Escape cancels the
                                 number rather than closing this overlay. -->
                            <div class="shortcut-row">
                                <dt><kbd>Esc</kbd></dt>
                                <dd>Cancel the number you are typing</dd>
                            </div>
                        </dl>
                    </div>
                </div>
                <div class="shortcuts-footer">
                    <a href="/help" data-navigate="help" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-circle-question me-1" aria-hidden="true"></i>
                        More Help
                    </a>
                </div>
            </div>`;

        document.body.appendChild(overlay);

        /* Close button */
        overlay.querySelector('#shortcuts-close-btn')?.addEventListener('click', () => this.hide());

        /* Click on backdrop to close */
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.hide();
        });

        /* "More Help" link navigates and closes */
        overlay.querySelector('[data-navigate="help"]')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.hide();
            this.app.router.navigate('/help');
        });

        /* Animate in */
        requestAnimationFrame(() => overlay.classList.add('visible'));

        /* a11y audit H2 (WCAG 2.4.3 Focus Order, 2.1.1 Keyboard): wire the
           shared modal-dialog focus recipe on top of the visual open/close
           this module already had. `aria-modal` is already set above (the
           call below re-sets it — a harmless no-op); this ADDS the part
           that was missing: focus moved into the dialog, a Tab trap, the
           background hidden from assistive tech (`inert`), Escape now
           routes here directly (in addition to app.js's existing global
           handler, which stays — dialog-a11y's close() is idempotent), and
           focus restored to whatever had it before '?' was pressed.
           `onClose` is the dialog's ACTUAL teardown (the CSS transition +
           removal this module already did) — every dismiss path (Close
           button, backdrop click, "More Help", Escape) now funnels through
           this ONE close() instead of each one repeating its own copy. */
        this._close = openModalDialog(overlay, {
            onClose: () => {
                this.visible = false;
                overlay.classList.remove('visible');
                overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
                /* Fallback removal if no transition */
                setTimeout(() => overlay.remove(), 300);
            },
        });
    }

    /** Hide the shortcuts overlay */
    hide() {
        if (!this.visible) return;
        this._close?.();
    }
}
