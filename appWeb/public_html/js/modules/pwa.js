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
 *   - Amazon Fire OS (Silk browser): routed to the Amazon Appstore, never
 *     the PWA a2hs flow (#1403)
 *   - Native app stores: when the ADMIN has configured a store ID for the
 *     visitor's detected platform (Apple App Store / Google Play / Amazon
 *     Appstore — /manage/configuration → "Native app stores", #1403), a
 *     native-app download banner REPLACES the PWA install prompt for that
 *     platform entirely (getNativeAppUrl() / checkNativeAppRedirect()).
 *     The instructional a2hs banners above are only a FALLBACK for a
 *     platform with no native app configured.
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

        /**
         * @type {boolean} When the app is in maintenance mode we suppress the
         * install banner — a new visitor gets the full server-side maintenance
         * 503 page (which never loads this module), but a returning, NOT-yet-
         * installed user can still load the cached SPA shell during an outage;
         * prompting them to install mid-maintenance is poor UX and collides
         * with the maintenance banner app.js raises. Set true once
         * _ensureAppStatus() reports maintenance. (#1276 feature A)
         */
        this._maintenanceSuppressed = false;
    }

    /**
     * Initialise PWA module — listen for install prompt and set up banner.
     */
    async init() {
        /* If running as installed PWA, set the cross-subdomain cookie and exit */
        if (window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true) {
            this._setPwaInstalledCookie();
            return;
        }

        /* Check if PWA is already installed (cross-subdomain) before proceeding (#248) */
        const alreadyInstalled = await this._isAlreadyInstalled();
        if (alreadyInstalled) {
            return;
        }

        /* Suppress the install banner during maintenance (#1276 feature A).
           app_status is a cached, lightweight fetch shared with the auth modal
           and the maintenance banner; if maintenance is on we set the flag so
           the show* methods below bail. Best-effort — a failed/late status
           fetch leaves the banner enabled (the server-side 503 page is the
           real safety net for new visitors). Awaited here so the flag is set
           before beforeinstallprompt or the platform banners can fire. */
        try {
            const status = await this.app?.userAuth?._ensureAppStatus?.();
            if (status && status.maintenance) {
                this._maintenanceSuppressed = true;
            }
        } catch (_e) { /* status unavailable — leave banner enabled */ }
        if (this._maintenanceSuppressed) {
            return;   /* don't even wire the install listeners during maintenance */
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
            this._setPwaInstalledCookie();
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

        /* Check for native app redirect (app store) */
        const nativeUrl = this.getNativeAppUrl();
        if (nativeUrl) {
            this.checkNativeAppRedirect();
            return;
        }

        /* Platform-specific banners for browsers without beforeinstallprompt */
        const platform = this.detectPlatform();

        if (platform === 'ios-safari') {
            /* iOS 15+ moved the Share button to the bottom toolbar (#177) */
            this.showPlatformBanner({
                icon: 'fa-solid fa-arrow-up-from-bracket',
                text: 'Tap <strong><i class="fa-solid fa-arrow-up-from-bracket"></i> Share</strong> below, then <strong>Add to Home Screen</strong>',
                showButton: false,
            });
        } else if (platform.startsWith('ios-')) {
            /* iOS non-Safari: cannot install PWAs — don't show banner */
        } else if (platform === 'macos-safari') {
            this.showPlatformBanner({
                icon: 'fa-solid fa-display',
                text: 'Install: <strong>File → Add to Dock</strong> for the best experience',
                showButton: false,
            });
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
     *   'macos-safari', 'fireos', 'android', 'desktop', or 'unknown'
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

        /* Amazon Fire OS (Kindle Fire tablets, #1403). Fire OS is an
           Android fork, so its UA also contains "Android" — this check
           MUST run before the generic Android test below. The Silk
           browser UA carries "Silk"; device build IDs like "KFONWI"
           ("KF" + tablet code) appear on both Silk and any other browser
           installed on a Fire tablet. */
        if (/Silk/.test(ua) || /\bKF[A-Z0-9]+\b/i.test(ua)) {
            return 'fireos';
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
        if (this._maintenanceSuppressed) return;   /* #1276 — no install prompt during maintenance */
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
        if (this._maintenanceSuppressed) return;   /* #1276 — no install prompt during maintenance */
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
     * INSTALLED DETECTION (#248)
     *
     * Two strategies for cross-subdomain detection:
     * 1. navigator.getInstalledRelatedApps() — Chromium API, matches by
     *    manifest id. Requires "platform": "webapp" in related_applications.
     * 2. Shared parent-domain cookie — set when running in standalone mode,
     *    readable from any subdomain (e.g. dev., alpha., www.).
     * ===================================================================== */

    /**
     * Check if the PWA is already installed, using all available methods.
     *
     * @returns {Promise<boolean>} True if the PWA appears to be installed.
     */
    async _isAlreadyInstalled() {
        /* Strategy 1: getInstalledRelatedApps() — Chromium browsers */
        if ('getInstalledRelatedApps' in navigator) {
            try {
                const apps = await navigator.getInstalledRelatedApps();
                if (apps.length > 0) {
                    return true;
                }
            } catch (e) {
                /* API not available or failed — fall through to cookie check */
            }
        }

        /* Strategy 2: shared parent-domain cookie */
        if (this._getPwaInstalledCookie()) {
            return true;
        }

        return false;
    }

    /**
     * Set a cookie on the parent domain indicating the PWA is installed.
     * Called when running in standalone mode or after a successful install.
     * The cookie is set on the highest-level domain that isn't a public
     * suffix (e.g. .ihymns.app), so all subdomains can read it.
     */
    _setPwaInstalledCookie() {
        const domain = this._getParentDomain();
        const maxAge = 365 * 24 * 60 * 60; /* 1 year */
        let cookie = 'ihymns_pwa_installed=1; path=/; max-age=' + maxAge + '; SameSite=Lax';
        if (domain) {
            cookie += '; domain=' + domain;
        }
        document.cookie = cookie;
    }

    /**
     * Read the shared PWA-installed cookie.
     *
     * @returns {boolean} True if the cookie exists.
     */
    _getPwaInstalledCookie() {
        return document.cookie.split(';').some(c => c.trim().startsWith('ihymns_pwa_installed='));
    }

    /**
     * Determine the parent domain for cross-subdomain cookies.
     * e.g. "dev.ihymns.app" → ".ihymns.app"
     *       "ihymns.app"     → ".ihymns.app"
     *       "localhost"      → null (no parent domain)
     *
     * @returns {string|null} Parent domain with leading dot, or null.
     */
    _getParentDomain() {
        const host = window.location.hostname;

        /* Skip for localhost / IP addresses */
        if (host === 'localhost' || /^\d+\.\d+\.\d+\.\d+$/.test(host)) {
            return null;
        }

        const parts = host.split('.');
        /* Need at least 2 parts (e.g. ihymns.app) */
        if (parts.length < 2) return null;

        /* Use the last two parts as the parent domain */
        return '.' + parts.slice(-2).join('.');
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
                this._setPwaInstalledCookie();
            }
        }
    }

    /* =====================================================================
     * NATIVE APP REDIRECT
     * ===================================================================== */

    /**
     * Per-platform store branding for the native-app banner (#1403).
     * Keyed by the store the visitor's platform maps to (NOT by
     * detectPlatform()'s finer-grained ios-safari/ios-chrome/... values —
     * see the lookup in checkNativeAppRedirect()). Icon/name are fixed,
     * hardcoded strings — never derived from admin input — so they're
     * safe to drop straight into innerHTML via showPlatformBanner().
     */
    static NATIVE_STORE_META = {
        ios:     { icon: 'fa-brands fa-apple',       name: 'App Store' },
        android: { icon: 'fa-brands fa-google-play', name: 'Google Play' },
        amazon:  { icon: 'fa-brands fa-amazon',      name: 'Amazon Appstore' },
    };

    /**
     * Check if the current platform has a native app available.
     * If so, show the native-app download banner (REPLACES the PWA a2hs
     * instructional banner for this platform — see getNativeAppUrl()).
     */
    checkNativeAppRedirect() {
        const nativeUrl = this.getNativeAppUrl();
        if (!nativeUrl) return;

        const platform = this.detectPlatform();
        const storeKey = platform === 'fireos'
            ? 'amazon'
            : (platform.startsWith('ios-') ? 'ios' : 'android');
        const meta = PWA.NATIVE_STORE_META[storeKey];

        this.showPlatformBanner({
            icon: meta.icon,
            text: 'Get the <strong>iHymns</strong> app on the ' + meta.name,
            showButton: true,
            buttonIcon: 'fa-solid fa-arrow-up-right-from-square',
            buttonText: 'Get the App',
        });
    }

    /**
     * Detect the user's platform and return the native app URL if available.
     * Only returns a URL when the server has verified/accepted the app for
     * that store (iosVerified / androidVerified / amazonVerified flags
     * already gated this value server-side — see index.php's
     * iHymnsConfig.nativeApps). The URL itself is always a canonical,
     * template-built store link (ihymnsAppStoreCanonicalUrl() in
     * includes/config.php) — never a raw admin-entered string — so it's
     * safe to assign straight to window.location.href / an <a href>.
     *
     * Reuses detectPlatform() rather than re-sniffing the UA here, so
     * platform detection lives in exactly ONE place.
     *
     * @returns {string|null} Native app store URL or null
     */
    getNativeAppUrl() {
        const nativeApps = this.app.config.nativeApps || {};
        const platform = this.detectPlatform();

        if (platform.startsWith('ios-')) {
            return nativeApps.ios || null;
        }
        if (platform === 'fireos') {
            return nativeApps.amazon || null;
        }
        if (platform === 'android') {
            return nativeApps.android || null;
        }
        return null;
    }
}
