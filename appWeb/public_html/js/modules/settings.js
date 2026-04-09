/**
 * iHymns — Settings Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages user preferences: theme (light/dark/high-contrast/system),
 * reduce motion (off by default, animations enabled), reduce
 * transparency, and lyrics font size. All settings are persisted
 * in localStorage.
 */

import {
    STORAGE_FAVORITES,
    STORAGE_SETLISTS,
    STORAGE_HISTORY,
    STORAGE_TRANSITION,
    STORAGE_DEFAULT_SONGBOOK,
    STORAGE_AUTO_UPDATE_SONGS,
    STORAGE_ANALYTICS_CONSENT,
    STORAGE_SEARCH_HISTORY,
} from '../constants.js';

export class Settings {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {string} localStorage key prefix */
        this.storagePrefix = 'ihymns_';

        /** Default settings — reduce motion is OFF by default (animations enabled) */
        this.defaults = {
            theme: 'system',
            reduceMotion: false,      /* Animations enabled by default */
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
                /* Track theme change analytics */
                if (this.app.analytics) {
                    this.app.analytics.trackThemeChange(theme);
                }
                this.app.showToast(`Theme changed to ${this.getThemeLabel(theme)}`, 'success', 2000);
            });
        });

        /* Analytics consent banner */
        this.initConsentBanner();
    }

    /* =====================================================================
     * ANALYTICS CONSENT — GDPR/privacy compliance
     *
     * localStorage key: ihymns_analytics_consent
     *   - 'granted'  : user accepted analytics
     *   - 'denied'   : user declined analytics
     *   - absent     : not yet decided (show banner)
     *
     * Rules:
     *   - DNT active           -> never show banner (server omits it)
     *   - Plausible-only       -> no banner needed (cookieless)
     *   - GA4 and/or Clarity   -> show banner if no stored consent
     *   - Decline              -> GA4 & Clarity blocked; Plausible continues
     *   - Accept               -> all analytics allowed
     * ===================================================================== */

    /** @type {string} localStorage key for analytics consent */
    static CONSENT_KEY = STORAGE_ANALYTICS_CONSENT;

    /**
     * Get the current analytics consent state.
     * @returns {'granted'|'denied'|null}
     */
    getAnalyticsConsent() {
        return localStorage.getItem(Settings.CONSENT_KEY);
    }

    /**
     * Set the analytics consent state and persist it.
     * @param {'granted'|'denied'} value
     */
    setAnalyticsConsent(value) {
        localStorage.setItem(Settings.CONSENT_KEY, value);
        this.app.syncStorage(Settings.CONSENT_KEY);
    }

    /**
     * Initialise the consent banner — show it if needed, bind buttons.
     */
    initConsentBanner() {
        const banner = document.getElementById('analytics-consent-banner');
        if (!banner) return; /* Banner not rendered (DNT active or Plausible-only) */

        const consent = this.getAnalyticsConsent();
        if (consent) return; /* Already decided */

        /* Show the banner with a slight delay for smooth entry */
        requestAnimationFrame(() => {
            banner.classList.remove('d-none');
            /* Force reflow before adding .show for CSS transition */
            banner.offsetHeight; // eslint-disable-line no-unused-expressions
            banner.classList.add('show');
        });

        /* Accept button */
        const acceptBtn = document.getElementById('consent-accept');
        if (acceptBtn) {
            acceptBtn.addEventListener('click', () => {
                this.setAnalyticsConsent('granted');
                this.hideConsentBanner();
                /* Reload to let PHP inline scripts load GA4/Clarity with consent set */
                this.app.showToast('Analytics enabled. Thank you!', 'success', 2000);
                setTimeout(() => window.location.reload(), 600);
            });
        }

        /* Decline button */
        const declineBtn = document.getElementById('consent-decline');
        if (declineBtn) {
            declineBtn.addEventListener('click', () => {
                this.setAnalyticsConsent('denied');
                this.hideConsentBanner();
                this.app.showToast('Analytics disabled', 'info', 2000);
            });
        }
    }

    /**
     * Hide the consent banner with animation.
     */
    hideConsentBanner() {
        const banner = document.getElementById('analytics-consent-banner');
        if (!banner) return;
        banner.classList.remove('show');
        banner.addEventListener('transitionend', () => {
            banner.classList.add('d-none');
        }, { once: true });
        /* Fallback if transition doesn't fire (reduce-motion) */
        setTimeout(() => banner.classList.add('d-none'), 500);
    }

    /**
     * Update the consent status label on the settings page.
     * @param {HTMLElement} el The status element
     * @param {string|null} consent Current consent value
     */
    updateConsentStatusLabel(el, consent) {
        if (consent === 'granted') {
            el.textContent = 'Enabled';
            el.className = 'privacy-consent-status text-success';
        } else if (consent === 'denied') {
            el.textContent = 'Disabled';
            el.className = 'privacy-consent-status text-danger';
        } else {
            el.textContent = 'Not set';
            el.className = 'privacy-consent-status text-muted';
        }
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
        const fullKey = this.storagePrefix + key;
        localStorage.setItem(fullKey, String(value));
        /* Sync to subdomain cookie + iframe bridge (#133) */
        this.app.syncStorage(fullKey);
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

        /* Re-evaluate badge text contrast after theme change (#152) */
        requestAnimationFrame(() => this.app.router?.fixBadgeContrast());
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
        /* Account section — user auth buttons */
        this._initAccountSection();

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

        /* Page transition style (#106) */
        const transitionSelect = document.getElementById('setting-transition');
        if (transitionSelect) {
            transitionSelect.value = localStorage.getItem(STORAGE_TRANSITION) || 'none';
            transitionSelect.addEventListener('change', () => {
                localStorage.setItem(STORAGE_TRANSITION, transitionSelect.value);
                this.app.syncStorage(STORAGE_TRANSITION);
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
            defaultBookSelect.value = localStorage.getItem(STORAGE_DEFAULT_SONGBOOK) || '';
            defaultBookSelect.addEventListener('change', () => {
                const val = defaultBookSelect.value;
                if (val) {
                    localStorage.setItem(STORAGE_DEFAULT_SONGBOOK, val);
                } else {
                    localStorage.removeItem(STORAGE_DEFAULT_SONGBOOK);
                }
                this.app.syncStorage(STORAGE_DEFAULT_SONGBOOK);
            });
        }

        /* Analytics consent toggle (Privacy section) */
        const consentToggle = document.getElementById('setting-analytics-consent');
        const consentStatus = document.getElementById('analytics-consent-status');
        if (consentToggle) {
            const current = this.getAnalyticsConsent();
            consentToggle.checked = current === 'granted';
            if (consentStatus) {
                this.updateConsentStatusLabel(consentStatus, current);
            }
            consentToggle.addEventListener('change', () => {
                const newValue = consentToggle.checked ? 'granted' : 'denied';
                this.setAnalyticsConsent(newValue);
                if (consentStatus) {
                    this.updateConsentStatusLabel(consentStatus, newValue);
                }
                if (newValue === 'granted') {
                    this.app.showToast('Analytics enabled. Reloading...', 'success', 1500);
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    this.app.showToast('Analytics disabled. Reloading...', 'info', 1500);
                    setTimeout(() => window.location.reload(), 600);
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

        /* Export data button (#103) */
        const exportBtn = document.getElementById('export-data-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => this.exportUserData());
        }

        /* Import data input (#103) */
        const importInput = document.getElementById('import-data-input');
        if (importInput) {
            importInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) this.importUserData(file);
                importInput.value = '';
            });
        }

        /* Download all songs for offline */
        const downloadBtn = document.getElementById('download-all-songs-btn');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => this.downloadAllSongs());
        }

        /* Per-songbook download buttons */
        document.querySelectorAll('.btn-download-songbook').forEach(btn => {
            btn.addEventListener('click', () => {
                const songbookId = btn.dataset.songbookId;
                if (songbookId) this.downloadSongbook(songbookId, btn);
            });
        });

        /* Auto-update offline songs toggle (#132) */
        const autoUpdateToggle = document.getElementById('setting-auto-update-songs');
        if (autoUpdateToggle) {
            autoUpdateToggle.checked = localStorage.getItem(STORAGE_AUTO_UPDATE_SONGS) === 'true';
            autoUpdateToggle.addEventListener('change', () => {
                const enabled = autoUpdateToggle.checked;
                localStorage.setItem(STORAGE_AUTO_UPDATE_SONGS, String(enabled));
                this.app.syncStorage(STORAGE_AUTO_UPDATE_SONGS);
                /* Inform the service worker of the new preference */
                if (navigator.serviceWorker?.controller) {
                    navigator.serviceWorker.controller.postMessage({
                        type: 'SET_AUTO_UPDATE',
                        enabled,
                    });
                }
            });
        }

        /* Listen for progress messages from service worker */
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data?.type === 'CACHE_ALL_SONGS_PROGRESS') {
                    this.updateDownloadProgress(event.data);
                }
            });
        }

        /* Check which songbooks are already cached */
        this.updateSongbookCacheStatus();

        /* Reset settings button */
        const resetBtn = document.getElementById('reset-settings-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', async () => {
                const ok = await this.app.showConfirm(
                    'Reset all settings to defaults? Your favourites will not be affected.',
                    { title: 'Reset Settings', okText: 'Reset', okClass: 'btn-danger' }
                );
                if (ok) {
                    Object.keys(this.defaults).forEach(key => {
                        localStorage.removeItem(this.storagePrefix + key);
                    });
                    localStorage.removeItem(STORAGE_DEFAULT_SONGBOOK);
                    localStorage.removeItem(STORAGE_TRANSITION);
                    localStorage.removeItem(STORAGE_AUTO_UPDATE_SONGS);
                    localStorage.removeItem(Settings.CONSENT_KEY);
                    /* Clear shared subdomain cookie (#133) */
                    this.app.subdomainSync?.clear();
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

    /* =====================================================================
     * IMPORT / EXPORT USER DATA (#103)
     * ===================================================================== */

    /**
     * Export favourites and set lists as a JSON file download.
     */
    exportUserData() {
        const safeParse = (key) => {
            try { return JSON.parse(localStorage.getItem(key) || '[]'); }
            catch { return []; }
        };

        const data = {
            version: 1,
            exportedAt: new Date().toISOString(),
            app: 'iHymns',
            favorites: safeParse(STORAGE_FAVORITES),
            setlists: safeParse(STORAGE_SETLISTS),
            history: safeParse(STORAGE_HISTORY),
        };

        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `ihymns-backup-${new Date().toISOString().slice(0, 10)}.json`;
        a.click();
        URL.revokeObjectURL(url);

        this.app.showToast('Data exported successfully', 'success', 2000);
    }

    /**
     * Import favourites and set lists from a JSON file.
     * @param {File} file The uploaded JSON file
     */
    async importUserData(file) {
        try {
            const text = await file.text();
            const data = JSON.parse(text);

            /* Validate structure */
            if (!data || data.app !== 'iHymns' || !data.version) {
                this.app.showToast('Invalid backup file', 'danger', 3000);
                return;
            }

            const favCount = (data.favorites || []).length;
            const setCount = (data.setlists || []).length;
            const histCount = (data.history || []).length;

            const choice = await this.app.showChoice(
                `Found: ${favCount} favourites, ${setCount} set lists, ${histCount} history entries.`,
                {
                    title: 'Import Data',
                    option1Text: 'Replace existing',
                    option2Text: 'Merge with existing',
                    option1Class: 'btn-warning',
                    option2Class: 'btn-primary',
                }
            );
            if (!choice) return; /* Dismissed */
            const mode = choice === 'option1' ? 'replace' : 'merge';

            if (mode === 'replace') {
                if (data.favorites) localStorage.setItem(STORAGE_FAVORITES, JSON.stringify(data.favorites));
                if (data.setlists) localStorage.setItem(STORAGE_SETLISTS, JSON.stringify(data.setlists));
                if (data.history) localStorage.setItem(STORAGE_HISTORY, JSON.stringify(data.history));
            } else {
                /* Merge: add non-duplicate entries */
                if (data.favorites) {
                    const existing = JSON.parse(localStorage.getItem(STORAGE_FAVORITES) || '[]');
                    const existingIds = new Set(existing.map(f => f.id));
                    const merged = [...existing, ...data.favorites.filter(f => !existingIds.has(f.id))];
                    localStorage.setItem(STORAGE_FAVORITES, JSON.stringify(merged));
                }
                if (data.setlists) {
                    const existing = JSON.parse(localStorage.getItem(STORAGE_SETLISTS) || '[]');
                    const existingIds = new Set(existing.map(l => l.id));
                    const merged = [...existing, ...data.setlists.filter(l => !existingIds.has(l.id))];
                    localStorage.setItem(STORAGE_SETLISTS, JSON.stringify(merged));
                }
                if (data.history) {
                    const existing = JSON.parse(localStorage.getItem(STORAGE_HISTORY) || '[]');
                    const existingIds = new Set(existing.map(h => h.id));
                    const merged = [...existing, ...data.history.filter(h => !existingIds.has(h.id))];
                    localStorage.setItem(STORAGE_HISTORY, JSON.stringify(merged.slice(0, 20)));
                }
            }

            /* Sync imported data to cross-domain bridge (#133) */
            this.app.syncStorage(STORAGE_FAVORITES);
            this.app.syncStorage(STORAGE_SETLISTS);
            this.app.syncStorage(STORAGE_HISTORY);

            this.app.showToast(`Data imported (${mode})`, 'success', 2000);
        } catch (error) {
            console.error('[Settings] Import error:', error);
            this.app.showToast('Failed to import data. Check the file format.', 'danger', 3000);
        }
    }

    /**
     * Ensure songs.json is fetched and cached locally for download operations.
     * @returns {Promise<Array>} Array of song objects
     */
    async getSongsData() {
        if (this._songsDataCache) return this._songsDataCache;
        const response = await fetch(this.app.config.dataUrl);
        if (!response.ok) throw new Error('Failed to fetch song data');
        const data = await response.json();
        this._songsDataCache = data.songs || [];
        return this._songsDataCache;
    }

    /**
     * Download all songs for offline use.
     * Fetches songs.json to get the full song list, then sends all IDs
     * to the service worker for background caching.
     */
    async downloadAllSongs() {
        const btn = document.getElementById('download-all-songs-btn');
        const statusEl = document.getElementById('download-songs-status');
        const progressWrap = document.getElementById('download-songs-progress');

        if (!btn || !('serviceWorker' in navigator) || !navigator.serviceWorker.controller) {
            this.app.showToast('Offline downloads require an active service worker. Please reload and try again.', 'warning');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1" aria-hidden="true"></i> Preparing...';
        if (statusEl) statusEl.textContent = '';
        if (progressWrap) progressWrap.classList.remove('d-none');

        try {
            const songs = await this.getSongsData();

            if (songs.length === 0) {
                this.app.showToast('No songs found to download', 'warning');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i> Download All Songbooks';
                return;
            }

            const songIds = songs.map(s => s.id);
            if (statusEl) statusEl.textContent = `Downloading 0 / ${songIds.length} songs...`;

            /* Disable all songbook buttons during bulk download */
            document.querySelectorAll('.btn-download-songbook').forEach(b => b.disabled = true);

            navigator.serviceWorker.controller.postMessage({
                type: 'CACHE_ALL_SONGS',
                songIds: songIds,
            });

        } catch (error) {
            console.error('[Settings] Download all songs error:', error);
            this.app.showToast('Failed to start download. Please try again.', 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i> Download All Songbooks';
            if (progressWrap) progressWrap.classList.add('d-none');
        }
    }

    /**
     * Download a single songbook for offline use.
     * @param {string} songbookId Songbook abbreviation (e.g. 'CP', 'MP')
     * @param {HTMLElement} btn The clicked button element
     */
    async downloadSongbook(songbookId, btn) {
        if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller) {
            this.app.showToast('Offline downloads require an active service worker.', 'warning');
            return;
        }

        const statusEl = document.getElementById('download-songs-status');
        const progressWrap = document.getElementById('download-songs-progress');
        const originalHtml = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>';
        if (progressWrap) progressWrap.classList.remove('d-none');

        try {
            const songs = await this.getSongsData();
            const songbookSongs = songs.filter(s => s.songbook === songbookId);

            if (songbookSongs.length === 0) {
                this.app.showToast(`No songs found in ${songbookId}`, 'warning');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                return;
            }

            const songIds = songbookSongs.map(s => s.id);
            if (statusEl) statusEl.textContent = `Downloading ${songbookId}: 0 / ${songIds.length}...`;

            navigator.serviceWorker.controller.postMessage({
                type: 'CACHE_ALL_SONGS',
                songIds: songIds,
            });

        } catch (error) {
            console.error(`[Settings] Download songbook ${songbookId} error:`, error);
            this.app.showToast(`Failed to download ${songbookId}. Please try again.`, 'danger');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (progressWrap) progressWrap.classList.add('d-none');
        }
    }

    /**
     * Update the download progress UI from service worker messages.
     * @param {object} data Progress data: { completed, failed, total }
     */
    updateDownloadProgress(data) {
        const btn = document.getElementById('download-all-songs-btn');
        const statusEl = document.getElementById('download-songs-status');
        const progressWrap = document.getElementById('download-songs-progress');
        const progressBar = document.getElementById('download-songs-bar');

        const { completed, failed, total } = data;
        const percent = Math.round(((completed + failed) / total) * 100);

        if (statusEl) statusEl.textContent = `Downloading ${completed + failed} / ${total} songs...`;
        if (progressBar) {
            progressBar.style.width = percent + '%';
            progressBar.setAttribute('aria-valuenow', String(percent));
        }

        /* Download complete */
        if (completed + failed >= total) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i> Download All Songbooks';
            }
            if (progressBar) {
                progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                progressBar.classList.add(failed > 0 ? 'bg-warning' : 'bg-success');
            }
            if (statusEl) {
                statusEl.textContent = failed > 0
                    ? `Done — ${completed} saved, ${failed} failed`
                    : `All ${completed} songs saved for offline use`;
            }
            /* Re-enable all songbook buttons */
            document.querySelectorAll('.btn-download-songbook').forEach(b => {
                b.disabled = false;
                b.innerHTML = '<i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>';
            });
            this.app.showToast(
                failed > 0
                    ? `Downloaded ${completed} songs (${failed} failed)`
                    : `All ${completed} songs downloaded for offline use`,
                failed > 0 ? 'warning' : 'success'
            );
            this.updateCacheStatus();
            this.updateSongbookCacheStatus();
        }
    }

    /**
     * Check how many songs from each songbook are cached and update the UI.
     */
    async updateSongbookCacheStatus() {
        if (!('caches' in window)) return;

        try {
            const cache = await caches.open('ihymns-recent-songs');
            const keys = await cache.keys();
            const cachedUrls = new Set(keys.map(k => new URL(k.url).searchParams.get('id')));
            const songs = await this.getSongsData();

            /* Group songs by songbook */
            const songbooks = {};
            songs.forEach(s => {
                if (!songbooks[s.songbook]) songbooks[s.songbook] = { total: 0, cached: 0 };
                songbooks[s.songbook].total++;
                if (cachedUrls.has(s.id)) songbooks[s.songbook].cached++;
            });

            /* Update status labels */
            document.querySelectorAll('.offline-songbook-status').forEach(el => {
                const id = el.dataset.songbook;
                const info = songbooks[id];
                if (!info) return;

                if (info.cached === 0) {
                    el.textContent = '';
                } else if (info.cached >= info.total) {
                    el.textContent = 'All saved';
                    el.classList.add('text-success');
                    el.classList.remove('text-muted');
                } else {
                    el.textContent = `${info.cached} / ${info.total}`;
                }
            });

            /* Update download buttons — show checkmark if fully cached */
            document.querySelectorAll('.btn-download-songbook').forEach(btn => {
                const id = btn.dataset.songbookId;
                const info = songbooks[id];
                if (info && info.cached >= info.total) {
                    btn.classList.remove('btn-outline-success');
                    btn.classList.add('btn-success');
                    btn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i>';
                    btn.title = `${id} — all songs saved offline`;
                }
            });
        } catch {
            /* Ignore — non-critical */
        }
    }

    /**
     * Save a single song for offline use (called from song page button).
     * @param {string} songId The song ID (e.g. 'CP-0001')
     * @param {HTMLElement} btn The clicked button
     */
    async saveSongOffline(songId, btn) {
        if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller) {
            this.app.showToast('Service worker not ready. Please reload.', 'warning');
            return;
        }

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1" aria-hidden="true"></i> Saving...';

        /* Use CACHE_SONG message — same protocol the router already uses */
        const apiUrl = `/api?page=song&id=${encodeURIComponent(songId)}`;
        navigator.serviceWorker.controller.postMessage({
            type: 'CACHE_SONG',
            url: apiUrl,
        });

        /* Brief delay then check if cached (SW caches asynchronously) */
        setTimeout(async () => {
            try {
                const cache = await caches.open('ihymns-recent-songs');
                const match = await cache.match(apiUrl);
                if (match) {
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.add('btn-success');
                    btn.innerHTML = '<i class="fa-solid fa-check me-1" aria-hidden="true"></i> <span>Saved Offline</span>';
                    btn.disabled = true;
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            } catch {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }, 1500);
    }

    /**
     * Check if a specific song is already cached and update the button state.
     * @param {string} songId Song ID
     * @param {HTMLElement} btn The save-offline button
     */
    async checkSongCacheStatus(songId, btn) {
        if (!('caches' in window)) return;
        try {
            const cache = await caches.open('ihymns-recent-songs');
            const apiUrl = `/api?page=song&id=${encodeURIComponent(songId)}`;
            const match = await cache.match(apiUrl);
            if (match) {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-success');
                btn.innerHTML = '<i class="fa-solid fa-check me-1" aria-hidden="true"></i> <span>Saved Offline</span>';
                btn.disabled = true;
            }
        } catch {
            /* Ignore */
        }
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

    /* =====================================================================
     * ACCOUNT SECTION — User auth integration
     * ===================================================================== */

    /**
     * Initialise the account section in settings.
     * Shows logged-in or logged-out state and binds button handlers.
     */
    _initAccountSection() {
        const auth = this.app.userAuth;
        if (!auth) return;

        const loggedOutEl = document.getElementById('auth-logged-out');
        const loggedInEl  = document.getElementById('auth-logged-in');

        if (auth.isLoggedIn()) {
            const user = auth.getUser();
            loggedOutEl?.classList.add('d-none');
            loggedInEl?.classList.remove('d-none');
            const nameEl = document.getElementById('auth-display-name-text');
            const userEl = document.getElementById('auth-username-text');
            if (nameEl) nameEl.textContent = user?.display_name || user?.username || '';
            if (userEl) userEl.textContent = '@' + (user?.username || '');
        } else {
            loggedOutEl?.classList.remove('d-none');
            loggedInEl?.classList.add('d-none');
        }

        /* Sign In button */
        document.getElementById('btn-auth-login')?.addEventListener('click', () => {
            auth.showAuthModal('login');
        });

        /* Create Account button */
        document.getElementById('btn-auth-register')?.addEventListener('click', () => {
            auth.showAuthModal('register');
        });

        /* Sync button */
        document.getElementById('btn-auth-sync')?.addEventListener('click', async () => {
            this.app.showToast('Syncing set lists...', 'info', 2000);
            await auth.triggerSetlistSync();
            /* Refresh account section display */
            this._initAccountSection();
        });

        /* Sign Out button */
        document.getElementById('btn-auth-logout')?.addEventListener('click', async () => {
            await auth.logout();
            this.app.showToast('Signed out', 'info', 2000);
            /* Refresh account section display */
            this._initAccountSection();
        });
    }
}
