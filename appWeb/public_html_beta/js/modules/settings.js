/**
 * iHymns — Settings Module
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Manages user preferences: theme (light/dark/high-contrast/system),
 * reduce motion (disabled by default as per requirements), reduce
 * transparency, and lyrics font size. All settings are persisted
 * in localStorage.
 */

export class Settings {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key prefix */
        this.storagePrefix = 'ihymns_';

        /** Default settings — reduce motion is ON by default (animations disabled) */
        this.defaults = {
            theme: 'system',
            reduceMotion: true,       /* Animations disabled by default */
            reduceTransparency: false,
            fontSize: 18,
        };
    }

    /**
     * Initialise settings — load from localStorage and apply.
     */
    init() {
        /* Apply all saved settings on load */
        this.applyTheme(this.get('theme'));
        this.applyReduceMotion(this.get('reduceMotion'));
        this.applyReduceTransparency(this.get('reduceTransparency'));
        this.applyFontSize(this.get('fontSize'));

        /* Listen for system theme changes */
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (this.get('theme') === 'system') {
                this.applyTheme('system');
            }
        });

        /* Theme dropdown buttons in header */
        document.querySelectorAll('[data-theme]').forEach(btn => {
            btn.addEventListener('click', () => {
                const theme = btn.dataset.theme;
                this.set('theme', theme);
                this.applyTheme(theme);
                this.app.showToast(`Theme changed to ${this.getThemeLabel(theme)}`, 'success', 2000);
            });
        });
    }

    /**
     * Get a setting value from localStorage (with default fallback).
     *
     * @param {string} key Setting key
     * @returns {*} Setting value
     */
    get(key) {
        const stored = localStorage.getItem(this.storagePrefix + key);
        if (stored === null) return this.defaults[key];

        /* Parse booleans and numbers */
        if (stored === 'true') return true;
        if (stored === 'false') return false;
        if (!isNaN(stored) && stored !== '') return Number(stored);
        return stored;
    }

    /**
     * Save a setting to localStorage.
     *
     * @param {string} key Setting key
     * @param {*} value Setting value
     */
    set(key, value) {
        localStorage.setItem(this.storagePrefix + key, String(value));
    }

    /**
     * Apply a theme to the document.
     *
     * @param {string} theme Theme name: 'light', 'dark', 'high-contrast', or 'system'
     */
    applyTheme(theme) {
        const html = document.documentElement;
        let bsTheme = 'light';
        let ihymnsTheme = theme;

        if (theme === 'system') {
            /* Detect system preference */
            bsTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            ihymnsTheme = bsTheme;
        } else if (theme === 'dark') {
            bsTheme = 'dark';
        } else if (theme === 'high-contrast') {
            /* High contrast uses light BS theme with custom overrides */
            bsTheme = 'light';
        }

        html.setAttribute('data-bs-theme', bsTheme);
        html.setAttribute('data-ihymns-theme', ihymnsTheme);

        /* Update theme-color meta tags */
        const themeColor = bsTheme === 'dark' ? '#1e1b4b' : '#4f46e5';
        document.querySelectorAll('meta[name="theme-color"]').forEach(meta => {
            meta.setAttribute('content', themeColor);
        });
    }

    /**
     * Apply reduce-motion preference.
     *
     * @param {boolean} enabled True to reduce motion
     */
    applyReduceMotion(enabled) {
        document.body.classList.toggle('reduce-motion', enabled);
    }

    /**
     * Apply reduce-transparency preference.
     *
     * @param {boolean} enabled True to reduce transparency
     */
    applyReduceTransparency(enabled) {
        document.body.classList.toggle('reduce-transparency', enabled);
    }

    /**
     * Apply lyrics font size.
     *
     * @param {number} size Font size in pixels
     */
    applyFontSize(size) {
        document.documentElement.style.setProperty('--lyrics-font-size', size + 'px');
    }

    /**
     * Get a human-readable label for a theme name.
     *
     * @param {string} theme Theme key
     * @returns {string} Human-readable label
     */
    getThemeLabel(theme) {
        const labels = {
            'light': 'Light',
            'dark': 'Dark',
            'high-contrast': 'High Contrast',
            'system': 'System',
        };
        return labels[theme] || theme;
    }

    /**
     * Initialise the settings page controls (called after page loads).
     */
    initSettingsPage() {
        /* Theme buttons */
        document.querySelectorAll('[data-setting-theme]').forEach(btn => {
            const theme = btn.dataset.settingTheme;
            const isActive = this.get('theme') === theme;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-pressed', String(isActive));

            btn.addEventListener('click', () => {
                this.set('theme', theme);
                this.applyTheme(theme);

                /* Update all theme buttons */
                document.querySelectorAll('[data-setting-theme]').forEach(b => {
                    b.classList.toggle('active', b.dataset.settingTheme === theme);
                    b.setAttribute('aria-pressed', String(b.dataset.settingTheme === theme));
                });
            });
        });

        /* Reduce motion toggle */
        const motionToggle = document.getElementById('setting-reduce-motion');
        if (motionToggle) {
            motionToggle.checked = this.get('reduceMotion');
            motionToggle.addEventListener('change', () => {
                this.set('reduceMotion', motionToggle.checked);
                this.applyReduceMotion(motionToggle.checked);
            });
        }

        /* Reduce transparency toggle */
        const transparencyToggle = document.getElementById('setting-reduce-transparency');
        if (transparencyToggle) {
            transparencyToggle.checked = this.get('reduceTransparency');
            transparencyToggle.addEventListener('change', () => {
                this.set('reduceTransparency', transparencyToggle.checked);
                this.applyReduceTransparency(transparencyToggle.checked);
            });
        }

        /* Font size slider */
        const fontSlider = document.getElementById('setting-font-size');
        const fontValue = document.getElementById('font-size-value');
        if (fontSlider) {
            fontSlider.value = this.get('fontSize');
            if (fontValue) fontValue.textContent = this.get('fontSize') + 'px';

            fontSlider.addEventListener('input', () => {
                const size = Number(fontSlider.value);
                this.set('fontSize', size);
                this.applyFontSize(size);
                if (fontValue) fontValue.textContent = size + 'px';
            });
        }

        /* Default songbook dropdown (#96) */
        const defaultBookSelect = document.getElementById('setting-default-songbook');
        if (defaultBookSelect) {
            defaultBookSelect.value = localStorage.getItem('ihymns_default_songbook') || '';
            defaultBookSelect.addEventListener('change', () => {
                const val = defaultBookSelect.value;
                if (val) {
                    localStorage.setItem('ihymns_default_songbook', val);
                } else {
                    localStorage.removeItem('ihymns_default_songbook');
                }
            });
        }

        /* Clear cache button */
        const clearCacheBtn = document.getElementById('clear-cache-btn');
        if (clearCacheBtn) {
            clearCacheBtn.addEventListener('click', async () => {
                if ('caches' in window) {
                    const keys = await caches.keys();
                    await Promise.all(keys.map(key => caches.delete(key)));
                    this.app.showToast('Cache cleared successfully', 'success');
                }
            });
        }

        /* Reset settings button */
        const resetBtn = document.getElementById('reset-settings-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (confirm('Reset all settings to defaults? Your favourites will not be affected.')) {
                    Object.keys(this.defaults).forEach(key => {
                        localStorage.removeItem(this.storagePrefix + key);
                    });
                    localStorage.removeItem('ihymns_default_songbook');
                    /* Re-apply defaults */
                    this.applyTheme(this.defaults.theme);
                    this.applyReduceMotion(this.defaults.reduceMotion);
                    this.applyReduceTransparency(this.defaults.reduceTransparency);
                    this.applyFontSize(this.defaults.fontSize);
                    this.app.showToast('Settings reset to defaults', 'success');
                    /* Reload settings page to update controls */
                    this.app.router.navigate('/settings');
                }
            });
        }

        /* Cache status */
        this.updateCacheStatus();
    }

    /**
     * Update the cache status display on the settings page.
     */
    async updateCacheStatus() {
        const statusEl = document.getElementById('cache-status');
        if (!statusEl) return;

        if ('caches' in window) {
            const keys = await caches.keys();
            statusEl.textContent = keys.length > 0 ? `Active (${keys.length} cache(s))` : 'No caches';
            statusEl.className = keys.length > 0 ? 'badge bg-success' : 'badge bg-secondary';
        } else {
            statusEl.textContent = 'Not supported';
            statusEl.className = 'badge bg-warning';
        }
    }
}
