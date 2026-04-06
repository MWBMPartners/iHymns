/**
 * iHymns — Keyboard Shortcuts Help Overlay (#104)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shows a categorised keyboard shortcuts reference overlay when
 * the user presses '?' from any page (outside input fields).
 * Dismissible via Escape or the close button.
 */

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
                                <dd>Presentation mode</dd>
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
                                <dt><kbd>+</kbd> / <kbd>-</kbd></dt>
                                <dd>Font size</dd>
                            </div>
                            <div class="shortcut-row">
                                <dt><kbd>Esc</kbd></dt>
                                <dd>Close overlay / search</dd>
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
    }

    /** Hide the shortcuts overlay */
    hide() {
        if (!this.visible) return;
        this.visible = false;

        const overlay = document.getElementById('shortcuts-overlay');
        if (overlay) {
            overlay.classList.remove('visible');
            overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
            /* Fallback removal if no transition */
            setTimeout(() => overlay.remove(), 300);
        }
    }
}
