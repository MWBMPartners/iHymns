/**
 * iHymns — PWA Installation Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages the PWA install banner and beforeinstallprompt event.
 * Shows a dismissible banner at the top offering to install the app.
 *
 * Platform handling:
 *   - Chrome/Edge/Samsung (Android & desktop): uses beforeinstallprompt API
 *   - Safari iOS:  "Tap Share below → Add to Home Screen" (iOS 15+ bottom bar)
 *   - Safari macOS: "File → Add to Dock" instructions
 *   - iOS non-Safari (Chrome, Edge, Firefox, Opera): banner hidden,
 *     since only Safari can install PWAs on iOS
 *   - Native app stores: redirects to app store if configured
 *
 * The banner HTML starts empty (no default content) — all text, icons, and
 * buttons are populated by JS based on platform detection. This prevents
 * the old generic banner content from flashing before JS loads. (#177)
 *
 * Banner dismissal is remembered in localStorage.
 */

import { STORAGE_PWA_BANNER_DISMISSED } from '../constants.js';

export class PWA {
    constructor(app) {
        this.app = app;

        /** @type {Event|null} Deferred beforeinstallprompt event */
        this.deferredPrompt = null;

        /** @type {string} localStorage key for banner dismissal */
        this.dismissKey = STORAGE_PWA_BANNER_DISMISSED;
    }

    /**
     * Initialise PWA module — listen for install prompt and set up banner.
     */
    init() {
        /* If running as installed PWA, don't show banner */
        if (window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true) {
            return;
        }

        /* Capture the beforeinstallprompt event (Chrome, Edge, Samsung on Android/desktop) */
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;
            this.showInstallBanner();
        });

        /* Detect successful install */
        window.addEventListener('appinstalled', () => {
            this.deferredPrompt = null;
            this.hideInstallBanner();
            this.app.showToast('App installed successfully!', 'success');
        });

        /* Banner dismiss button */
        const dismissBtn = document.getElementById('pwa-install-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                this.hideInstallBanner();
                localStorage.setItem(this.dismissKey, 'true');
            });
        }

        /* Install button — default handler for Chrome/Edge beforeinstallprompt */
        const installBtn = document.getElementById('pwa-install-btn');
        if (installBtn) {
            installBtn.addEventListener('click', () => this.handleInstallClick());
        }

        /* Platform-specific banners for browsers without beforeinstallprompt */
        const platform = this.detectPlatform();

        if (platform === 'ios-safari' || platform.startsWith('ios-') || platform === 'macos-safari') {
            /* Apple platforms: dynamically check if native app is on App Store (#221) */
            this.checkAppStoreAndShowBanner(platform);
        }
        /* Desktop Chrome/Edge/etc. — wait for beforeinstallprompt (handled above) */
    }

    /* =====================================================================
     * PLATFORM DETECTION
     * ===================================================================== */

    /**
     * Detect the current platform for install prompt purposes.
     *
     * @returns {string} Platform identifier:
     *   'ios-safari', 'ios-chrome', 'ios-edge', 'ios-firefox', 'ios-other',
     *   'macos-safari', 'android', 'desktop', or 'unknown'
     */
    detectPlatform() {
        const ua = navigator.userAgent || '';
        const isIOS = /iPad|iPhone|iPod/.test(ua) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        if (isIOS) {
            if (/CriOS/.test(ua))  return 'ios-chrome';
            if (/EdgiOS/.test(ua)) return 'ios-edge';
            if (/FxiOS/.test(ua))  return 'ios-firefox';
            if (/OPiOS/.test(ua))  return 'ios-other';   /* Opera */
            if (/Safari/.test(ua)) return 'ios-safari';
            return 'ios-other';
        }

        /* macOS Safari (not iOS iPad pretending to be Mac) */
        if (/Macintosh/.test(ua) && /Safari/.test(ua) &&
            !/Chrome|CriOS|FxiOS|EdgiOS/.test(ua)) {
            return 'macos-safari';
        }

        if (/Android/.test(ua)) return 'android';

        return 'desktop';
    }

    /* =====================================================================
     * BANNER DISPLAY
     * ===================================================================== */

    /**
     * Show a platform-specific install banner.
     * Populates the empty banner HTML with platform-appropriate content.
     *
     * @param {Object} opts Banner options
     * @param {string} opts.icon FontAwesome class for the leading icon
     * @param {string} opts.text HTML content for the banner text
     * @param {boolean} opts.showButton Whether to show the action button
     * @param {string} [opts.buttonIcon] FontAwesome class for button icon
     * @param {string} [opts.buttonText] Button label text
     * @param {Function} [opts.buttonAction] Click handler for the button
     */
    showPlatformBanner(opts) {
        if (localStorage.getItem(this.dismissKey)) return;

        const banner = document.getElementById('pwa-install-banner');
        if (!banner) return;

        /* Populate icon */
        const iconEl = banner.querySelector('.pwa-banner-icon');
        if (iconEl) {
            iconEl.className = 'pwa-banner-icon ' + opts.icon + ' fa-lg';
        }

        /* Populate text */
        const textEl = banner.querySelector('.pwa-install-text');
        if (textEl) {
            textEl.innerHTML = opts.text;
        }

        /* Populate button */
        const installBtn = document.getElementById('pwa-install-btn');
        if (installBtn) {
            if (opts.showButton && opts.buttonText) {
                installBtn.classList.remove('d-none');
                const icon = installBtn.querySelector('i');
                const span = installBtn.querySelector('span');
                if (icon) icon.className = (opts.buttonIcon || 'fa-solid fa-download') + ' me-1';
                if (span) span.textContent = opts.buttonText;

                if (opts.buttonAction) {
                    /* Replace click handler by cloning the button */
                    const newBtn = installBtn.cloneNode(true);
                    installBtn.parentNode.replaceChild(newBtn, installBtn);
                    newBtn.addEventListener('click', opts.buttonAction);
                }
            }
            /* If showButton is false, button stays hidden (d-none from HTML) */
        }

        banner.classList.remove('d-none');
        document.body.classList.add('banner-visible');
    }

    /**
     * Show the default PWA install banner (Chrome/Edge/Samsung beforeinstallprompt).
     * Populates the empty banner HTML with default install content.
     */
    showInstallBanner() {
        if (localStorage.getItem(this.dismissKey)) return;

        const banner = document.getElementById('pwa-install-banner');
        if (!banner) return;

        /* Populate default content for Chrome/Edge/Samsung */
        const iconEl = banner.querySelector('.pwa-banner-icon');
        if (iconEl) {
            iconEl.className = 'pwa-banner-icon fa-solid fa-mobile-screen-button fa-lg';
        }

        const textEl = banner.querySelector('.pwa-install-text');
        if (textEl) {
            textEl.innerHTML = 'Get the full <strong>iHymns</strong> experience!';
        }

        const installBtn = document.getElementById('pwa-install-btn');
        if (installBtn) {
            installBtn.classList.remove('d-none');
            const icon = installBtn.querySelector('i');
            const span = installBtn.querySelector('span');
            if (icon) icon.className = 'fa-solid fa-download me-1';
            if (span) span.textContent = 'Install';
        }

        banner.classList.remove('d-none');
        document.body.classList.add('banner-visible');
    }

    /**
     * Hide the PWA install banner.
     */
    hideInstallBanner() {
        const banner = document.getElementById('pwa-install-banner');
        if (banner) {
            banner.classList.add('d-none');
            document.body.classList.remove('banner-visible');
        }
    }

    /* =====================================================================
     * INSTALL ACTIONS
     * ===================================================================== */

    /**
     * Handle the install button click.
     * Either triggers the PWA install prompt or redirects to app store.
     */
    async handleInstallClick() {
        /* Check for native app redirect first */
        const nativeUrl = this.getNativeAppUrl();
        if (nativeUrl) {
            window.location.href = nativeUrl;
            return;
        }

        /* Trigger PWA install prompt if available */
        if (this.deferredPrompt) {
            this.deferredPrompt.prompt();
            const { outcome } = await this.deferredPrompt.userChoice;
            this.deferredPrompt = null;

            if (this.app.analytics) {
                this.app.analytics.trackPwaInstall(outcome);
            }

            if (outcome === 'accepted') {
                this.hideInstallBanner();
            }
        }
    }

    /* =====================================================================
     * NATIVE APP REDIRECT
     * ===================================================================== */

    /**
     * Check if the current platform has a native app available.
     * If so, populate the banner and redirect to the app store.
     */
    checkNativeAppRedirect() {
        const nativeUrl = this.getNativeAppUrl();
        if (!nativeUrl) return;

        this.showPlatformBanner({
            icon: 'fa-solid fa-mobile-screen-button',
            text: 'Get the full <strong>iHymns</strong> experience!',
            showButton: true,
            buttonIcon: 'fa-solid fa-arrow-up-right-from-square',
            buttonText: 'Open App',
        });
    }

    /**
     * Detect the user's platform and return the native app URL if available.
     *
     * @returns {string|null} Native app store URL or null
     */
    getNativeAppUrl() {
        const nativeApps = this.app.config.nativeApps || {};
        const ua = navigator.userAgent || '';

        if ((/iPad|iPhone|iPod/.test(ua) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) && nativeApps.ios) {
            return nativeApps.ios;
        }
        if (/Android/.test(ua) && nativeApps.android) {
            return nativeApps.android;
        }
        return null;
    }

    /* =====================================================================
     * DYNAMIC APP STORE CHECK (iTunes Search API) — #221
     *
     * Checks whether the iHymns app is published on the Apple App Store
     * before deciding which banner to show. Uses the public iTunes Search
     * API (no auth required). Result cached in localStorage for 24 hours.
     * ===================================================================== */

    /** localStorage key for caching the iTunes lookup result */
    static ITUNES_CACHE_KEY = 'ihymns_appstore_check';
    /** Cache duration: 24 hours in milliseconds */
    static ITUNES_CACHE_TTL = 24 * 60 * 60 * 1000;
    /** Bundle ID to look up on the App Store */
    static APPLE_BUNDLE_ID = 'ltd.mwbmpartners.ihymns';

    /**
     * Dynamically checks the Apple App Store for the native app, then
     * shows the appropriate banner based on availability.
     *
     * @param {string} platform - The detected platform ('ios-safari', 'macos-safari', etc.)
     */
    async checkAppStoreAndShowBanner(platform) {
        const result = await this.lookupAppStore();

        if (result && result.trackViewUrl) {
            /* App IS published on the App Store — show App Store banner on ALL Apple browsers */
            this.showPlatformBanner({
                icon: 'fa-brands fa-apple',
                text: platform === 'macos-safari'
                    ? 'Get <strong>iHymns</strong> from the App Store for the best Mac experience'
                    : 'Get the full <strong>iHymns</strong> app for the best experience',
                showButton: true,
                buttonIcon: 'fa-solid fa-arrow-up-right-from-square',
                buttonText: 'App Store',
                buttonAction: () => window.open(result.trackViewUrl, '_blank'),
            });

            /* Update Smart App Banner meta tag with real app-id */
            if (result.trackId) {
                this.updateSmartAppBanner(result.trackId);
            }
        } else {
            /* App NOT published — fall back to PWA install (Safari only) */
            if (platform === 'ios-safari') {
                this.showPlatformBanner({
                    icon: 'fa-solid fa-arrow-up-from-bracket',
                    text: 'Tap <strong><i class="fa-solid fa-arrow-up-from-bracket"></i> Share</strong> below, then <strong>Add to Home Screen</strong>',
                    showButton: false,
                });
            } else if (platform === 'macos-safari') {
                this.showPlatformBanner({
                    icon: 'fa-solid fa-display',
                    text: 'Install: <strong>File → Add to Dock</strong> for the best experience',
                    showButton: false,
                });
            }
            /* iOS non-Safari without native app: don't show banner */
        }
    }

    /**
     * Looks up the app on the iTunes Search API.
     * Returns cached result if available and fresh (< 24 hours old).
     *
     * @returns {Object|null} { trackId, trackViewUrl, ... } or null if not published
     */
    async lookupAppStore() {
        /* Check localStorage cache first */
        try {
            const cached = localStorage.getItem(PWA.ITUNES_CACHE_KEY);
            if (cached) {
                const { timestamp, data } = JSON.parse(cached);
                if (Date.now() - timestamp < PWA.ITUNES_CACHE_TTL) {
                    return data;
                }
            }
        } catch { /* ignore parse errors */ }

        /* Fetch from iTunes Search API */
        try {
            const response = await fetch(
                `https://itunes.apple.com/lookup?bundleId=${encodeURIComponent(PWA.APPLE_BUNDLE_ID)}&country=us&media=software`,
                { mode: 'cors', cache: 'default' }
            );

            if (!response.ok) return null;

            const json = await response.json();

            if (json.resultCount > 0 && json.results[0]) {
                const appData = {
                    trackId:      json.results[0].trackId,
                    trackViewUrl: json.results[0].trackViewUrl,
                    trackName:    json.results[0].trackName,
                    version:      json.results[0].version,
                };

                /* Cache the successful result */
                localStorage.setItem(PWA.ITUNES_CACHE_KEY, JSON.stringify({
                    timestamp: Date.now(),
                    data: appData,
                }));

                return appData;
            }

            /* App not found — cache the negative result too (avoids repeated API calls) */
            localStorage.setItem(PWA.ITUNES_CACHE_KEY, JSON.stringify({
                timestamp: Date.now(),
                data: null,
            }));

            return null;
        } catch {
            /* Network error — don't cache, will retry next page load */
            return null;
        }
    }

    /**
     * Updates the Smart App Banner <meta> tag with the real app-id
     * from the iTunes API response.
     *
     * @param {number} trackId - The App Store track ID
     */
    updateSmartAppBanner(trackId) {
        let meta = document.querySelector('meta[name="apple-itunes-app"]');
        if (meta) {
            const currentPath = window.location.pathname;
            meta.setAttribute('content', `app-id=${trackId}, app-argument=${currentPath}`);
        } else {
            /* Create the meta tag if it doesn't exist */
            meta = document.createElement('meta');
            meta.setAttribute('name', 'apple-itunes-app');
            meta.setAttribute('content', `app-id=${trackId}`);
            document.head.appendChild(meta);
        }
    }
}
