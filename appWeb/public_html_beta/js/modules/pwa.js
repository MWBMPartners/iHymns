/**
 * iHymns — PWA Installation Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages the PWA install banner and beforeinstallprompt event.
 * Shows a dismissible banner at the top offering to install the app.
 * On platforms with native apps, redirects to the app store instead.
 * Banner dismissal is remembered in localStorage.
 */

export class PWA {
    constructor(app) {
        this.app = app;

        /** @type {Event|null} Deferred beforeinstallprompt event */
        this.deferredPrompt = null;

        /** @type {string} localStorage key for banner dismissal */
        this.dismissKey = 'ihymns_pwa_banner_dismissed';
    }

    /**
     * Initialise PWA module — listen for install prompt and set up banner.
     */
    init() {
        /* Capture the beforeinstallprompt event (Chrome, Edge, Samsung) */
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;
            this.showInstallBanner();
        });

        /* Detect if already installed as PWA */
        window.addEventListener('appinstalled', () => {
            this.deferredPrompt = null;
            this.hideInstallBanner();
            this.app.showToast('App installed successfully!', 'success');
        });

        /* If running as installed PWA, don't show banner */
        if (window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true) {
            return;
        }

        /* Show banner if not dismissed and on a platform with native app */
        this.checkNativeAppRedirect();

        /* Banner dismiss button */
        const dismissBtn = document.getElementById('pwa-install-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                this.hideInstallBanner();
                localStorage.setItem(this.dismissKey, 'true');
            });
        }

        /* Install button */
        const installBtn = document.getElementById('pwa-install-btn');
        if (installBtn) {
            installBtn.addEventListener('click', () => this.handleInstallClick());
        }
    }

    /**
     * Show the PWA install banner if not previously dismissed.
     */
    showInstallBanner() {
        if (localStorage.getItem(this.dismissKey)) return;

        const banner = document.getElementById('pwa-install-banner');
        if (banner) {
            banner.classList.remove('d-none');
            document.body.classList.add('banner-visible');
        }
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

            if (outcome === 'accepted') {
                this.hideInstallBanner();
            }
        }
    }

    /**
     * Check if the current platform has a native app available.
     * If so, update the banner button to redirect to the app store.
     */
    checkNativeAppRedirect() {
        const nativeUrl = this.getNativeAppUrl();
        if (!nativeUrl) return;

        /* Update the install button text */
        const installBtn = document.getElementById('pwa-install-btn');
        if (installBtn) {
            const icon = installBtn.querySelector('i');
            const span = installBtn.querySelector('span');
            if (icon) icon.className = 'fa-solid fa-arrow-up-right-from-square me-1';
            if (span) span.textContent = 'Open App';
        }

        /* Show the banner if not dismissed */
        if (!localStorage.getItem(this.dismissKey)) {
            this.showInstallBanner();
        }
    }

    /**
     * Detect the user's platform and return the native app URL if available.
     *
     * @returns {string|null} Native app store URL or null
     */
    getNativeAppUrl() {
        const nativeApps = this.app.config.nativeApps || {};
        const ua = navigator.userAgent || '';

        if (/iPad|iPhone|iPod/.test(ua) && nativeApps.ios) {
            return nativeApps.ios;
        }
        if (/Android/.test(ua) && nativeApps.android) {
            return nativeApps.android;
        }
        return null;
    }
}
