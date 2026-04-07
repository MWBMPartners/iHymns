/**
 * iHymns — PWA Installation Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages the PWA install banner and beforeinstallprompt event.
 * Shows a dismissible banner at the top offering to install the app.
 * On Safari (iOS/macOS), shows platform-specific install instructions
 * since Safari does not support the beforeinstallprompt API.
 * On platforms with native apps, redirects to the app store instead.
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

        /* Check for native app redirect (app store) */
        const nativeUrl = this.getNativeAppUrl();
        if (nativeUrl) {
            this.checkNativeAppRedirect();
            return;
        }

        /* Safari-specific: show manual install instructions */
        if (this.isSafari()) {
            this.showSafariBanner();
        }

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
     * Detect Safari browser (iOS and macOS).
     * Safari does not support beforeinstallprompt, so we need
     * platform-specific install instructions.
     *
     * @returns {boolean} True if running in Safari
     */
    isSafari() {
        const ua = navigator.userAgent || '';
        /* Safari: has "Safari" in UA but NOT "Chrome", "CriOS", "FxiOS", "EdgiOS" */
        const isSafariUA = /Safari/.test(ua) &&
            !/Chrome|CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
        return isSafariUA;
    }

    /**
     * Detect iOS (iPhone/iPad/iPod).
     *
     * @returns {boolean} True if running on iOS
     */
    isIOS() {
        const ua = navigator.userAgent || '';
        return /iPad|iPhone|iPod/.test(ua) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    /**
     * Show Safari-specific install banner with platform instructions.
     */
    showSafariBanner() {
        if (localStorage.getItem(this.dismissKey)) return;

        const banner = document.getElementById('pwa-install-banner');
        if (!banner) return;

        /* Update banner content for Safari */
        const textEl = banner.querySelector('.pwa-install-text');
        const installBtn = document.getElementById('pwa-install-btn');

        if (this.isIOS()) {
            /* iOS Safari: Share → Add to Home Screen */
            if (textEl) {
                textEl.innerHTML = 'Tap <strong><i class="fa-solid fa-arrow-up-from-bracket"></i> Share</strong>, then <strong>Add to Home Screen</strong>';
            }
            if (installBtn) {
                installBtn.style.display = 'none';
            }
        } else {
            /* macOS Safari: File → Add to Dock */
            if (textEl) {
                textEl.innerHTML = 'Install: <strong>File → Add to Dock</strong> for the best experience';
            }
            if (installBtn) {
                installBtn.style.display = 'none';
            }
        }

        /* Update the icon to match the platform */
        const iconEl = banner.querySelector('.pwa-install-banner i.fa-mobile-screen-button');
        if (iconEl) {
            if (this.isIOS()) {
                iconEl.className = 'fa-solid fa-arrow-up-from-bracket fa-lg';
            } else {
                iconEl.className = 'fa-solid fa-display fa-lg';
            }
        }

        banner.classList.remove('d-none');
        document.body.classList.add('banner-visible');
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

            /* Track PWA install prompt outcome */
            if (this.app.analytics) {
                this.app.analytics.trackPwaInstall(outcome);
            }

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

        if ((/iPad|iPhone|iPod/.test(ua) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) && nativeApps.ios) {
            return nativeApps.ios;
        }
        if (/Android/.test(ua) && nativeApps.android) {
            return nativeApps.android;
        }
        return null;
    }
}
