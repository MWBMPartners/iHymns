/**
 * iHymns — Settings / Theme Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Manages user-facing settings including dark/light theme toggling,
 * the PWA install prompt banner, and periodic update checking.
 * Theme preference is persisted in localStorage so it survives
 * page reloads and browser restarts.
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/**
 * Import the $ helper from our shared utilities module.
 * $ is a shorthand for document.querySelector — it returns the first DOM
 * element matching a CSS selector, or null if nothing matches.
 */
import { $ } from '../utils/helpers.js';

/* =========================================================================
 * CONSTANTS
 * ========================================================================= */

/**
 * THEME_STORAGE_KEY
 *
 * The localStorage key under which the user's chosen theme ('light' or
 * 'dark') is persisted. Using a namespaced key ('ihymns_theme') avoids
 * collisions with other apps on the same origin.
 */
const THEME_STORAGE_KEY = 'ihymns_theme';

/**
 * CB_STORAGE_KEY
 *
 * The localStorage key for the colourblind-friendly mode toggle.
 * Stores 'true' or 'false' (as strings).
 */
const CB_STORAGE_KEY = 'ihymns_colourblind';

/**
 * VERSION_STORAGE_KEY
 *
 * The localStorage key under which we store the last-known app version
 * string. We compare against the server response to detect updates.
 */
const VERSION_STORAGE_KEY = 'ihymns_app_version';

/**
 * VERSION_ENDPOINT
 *
 * The server-side endpoint that returns the current application version.
 * The response is expected to be JSON with at least a "version" field,
 * e.g. { "version": "1.2.3" }.
 */
const VERSION_ENDPOINT = 'includes/infoAppVer.php?check=version';

/**
 * DEFAULT_CHECK_INTERVAL_MINUTES
 *
 * How often (in minutes) the update checker runs by default, if the
 * caller does not supply a custom interval to initUpdateChecker().
 */
const DEFAULT_CHECK_INTERVAL_MINUTES = 30;

/* =========================================================================
 * MODULE-LEVEL STATE
 * ========================================================================= */

/**
 * deferredInstallPrompt
 *
 * Holds the browser's BeforeInstallPromptEvent so we can trigger the
 * native PWA install dialog later when the user clicks our custom
 * install button. The browser fires 'beforeinstallprompt' only once
 * per page load, so we must capture and store it.
 */
let deferredInstallPrompt = null;

/**
 * updateCheckIntervalId
 *
 * Holds the ID returned by setInterval() for the periodic update
 * checker. Stored so we could clear it later if needed (e.g. when
 * the module is torn down or the user navigates away).
 */
let updateCheckIntervalId = null;

/* =========================================================================
 * THEME — INTERNAL HELPERS
 * ========================================================================= */

/**
 * applyTheme(theme)
 *
 * Applies the given theme to the document and updates the toggle icons.
 * This is the single source of truth for visual theme state — both
 * initTheme() and toggleTheme() funnel through this function to avoid
 * duplicating DOM-manipulation logic.
 *
 * @param {string} theme - Either 'light' or 'dark'
 */
function applyTheme(theme) {
    /* Set the Bootstrap 5.3+ theme attribute on the root <html> element.
       Bootstrap reads data-bs-theme to switch between its light and dark
       colour palettes automatically. */
    document.documentElement.setAttribute('data-bs-theme', theme);

    /* Grab references to the two icon elements inside the toggle button.
       #theme-icon-light shows a sun icon (visible when theme is dark,
       indicating "click to switch to light").
       #theme-icon-dark shows a moon icon (visible when theme is light,
       indicating "click to switch to dark"). */
    const iconLight = $('#theme-icon-light'); /* Sun icon element */
    const iconDark  = $('#theme-icon-dark');  /* Moon icon element */

    /* Only manipulate icons if both elements exist in the DOM.
       They may be absent on pages that don't include the theme toggle. */
    if (iconLight && iconDark) {
        if (theme === 'dark') {
            /* Dark mode is active:
               - Show the sun icon (offers the user a way to go back to light)
               - Hide the moon icon (dark is already active) */
            iconLight.classList.remove('d-none'); /* Make sun visible */
            iconDark.classList.add('d-none');     /* Hide moon */
        } else {
            /* Light mode is active:
               - Show the moon icon (offers the user a way to switch to dark)
               - Hide the sun icon (light is already active) */
            iconLight.classList.add('d-none');    /* Hide sun */
            iconDark.classList.remove('d-none');  /* Make moon visible */
        }
    }
}

/**
 * getSystemThemePreference()
 *
 * Queries the operating system / browser-level colour scheme preference
 * using the prefers-color-scheme media query. Returns 'dark' if the
 * user's OS is set to dark mode, otherwise returns 'light'.
 *
 * @returns {string} 'dark' or 'light'
 */
function getSystemThemePreference() {
    /* window.matchMedia evaluates a CSS media query and returns a
       MediaQueryList whose .matches property is true/false. */
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

    /* Return 'dark' if the system prefers dark, otherwise 'light' */
    return prefersDark.matches ? 'dark' : 'light';
}

/* =========================================================================
 * THEME — EXPORTED FUNCTIONS
 * ========================================================================= */

/**
 * initTheme()
 *
 * Initialises the application theme on page load. The precedence order is:
 *   1. User's explicit choice stored in localStorage (highest priority)
 *   2. Operating system / browser preference via prefers-color-scheme
 *   3. Falls back to 'light' if neither is available
 *
 * Call this once during app bootstrap (e.g. in main.js or DOMContentLoaded).
 */
export function initTheme() {
    /* Attempt to read the user's previously saved theme from localStorage.
       This will be null if the user has never toggled the theme. */
    const savedTheme = localStorage.getItem(THEME_STORAGE_KEY);

    /* Determine which theme to apply: saved preference takes priority,
       otherwise fall back to the system-level preference. */
    const theme = savedTheme || getSystemThemePreference();

    /* Apply the resolved theme to the DOM (sets data-bs-theme and icons) */
    applyTheme(theme);
}

/**
 * toggleTheme()
 *
 * Toggles the theme between 'light' and 'dark'. Reads the current
 * theme from the <html> element's data-bs-theme attribute, flips it,
 * persists the new choice to localStorage, and applies it to the DOM.
 *
 * Designed to be called from a click handler on the theme toggle button.
 */
export function toggleTheme() {
    /* Read the currently active theme from the data-bs-theme attribute.
       Default to 'light' if the attribute is somehow missing. */
    const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';

    /* Flip the theme: if currently light, switch to dark, and vice versa */
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

    /* Persist the user's explicit choice so it survives page reloads.
       This also means initTheme() will pick it up next time. */
    localStorage.setItem(THEME_STORAGE_KEY, newTheme);

    /* Apply the new theme to the DOM immediately */
    applyTheme(newTheme);
}

/**
 * bindThemeToggle()
 *
 * Binds a click event listener to the #theme-toggle button element.
 * When clicked, it calls toggleTheme() to flip between light and dark.
 *
 * Call this once after the DOM is ready (e.g. in DOMContentLoaded or
 * after your page template has been rendered).
 */
export function bindThemeToggle() {
    /* Find the theme toggle button in the DOM */
    const toggleBtn = $('#theme-toggle');

    /* Only attach the listener if the button exists on this page.
       Some pages (e.g. a minimal error page) might not have it. */
    if (toggleBtn) {
        /* Add a click event listener that triggers the theme toggle */
        toggleBtn.addEventListener('click', toggleTheme);
    }
}

/* =========================================================================
 * COLOURBLIND-FRIENDLY MODE
 * ========================================================================= */

/**
 * initColourblindMode()
 *
 * Initialises the colourblind-friendly mode from localStorage.
 * When enabled, sets data-theme-cb="true" on <html> which activates
 * the CVD-safe colour palette defined in styles.css.
 */
export function initColourblindMode() {
    /* Read the stored preference (default: 'false') */
    const cbEnabled = localStorage.getItem(CB_STORAGE_KEY) === 'true';

    /* Apply the attribute to <html> */
    if (cbEnabled) {
        document.documentElement.setAttribute('data-theme-cb', 'true');
    } else {
        document.documentElement.removeAttribute('data-theme-cb');
    }
}

/**
 * toggleColourblindMode()
 *
 * Toggles the colourblind-friendly mode on or off.
 * Persists to localStorage and updates the DOM attribute.
 *
 * @returns {boolean} The new state (true = enabled)
 */
export function toggleColourblindMode() {
    /* Check current state */
    const isCurrentlyEnabled = document.documentElement.getAttribute('data-theme-cb') === 'true';

    /* Flip the state */
    const newState = !isCurrentlyEnabled;

    /* Persist to localStorage */
    localStorage.setItem(CB_STORAGE_KEY, String(newState));

    /* Apply to DOM */
    if (newState) {
        document.documentElement.setAttribute('data-theme-cb', 'true');
    } else {
        document.documentElement.removeAttribute('data-theme-cb');
    }

    return newState;
}

/**
 * isColourblindMode()
 *
 * Returns whether colourblind-friendly mode is currently active.
 *
 * @returns {boolean}
 */
export function isColourblindMode() {
    return document.documentElement.getAttribute('data-theme-cb') === 'true';
}

/* =========================================================================
 * PWA INSTALL BANNER
 * ========================================================================= */

/**
 * isAppInstalledStandalone()
 *
 * Checks whether the app is already running as an installed PWA
 * (i.e. in standalone display mode rather than in a browser tab).
 * Used to suppress the install banner when the app is already installed.
 *
 * @returns {boolean} true if running as a standalone PWA
 */
function isAppInstalledStandalone() {
    /* The display-mode: standalone media query matches when the PWA
       has been added to the home screen / installed and is running
       outside of the browser's normal UI. */
    return window.matchMedia('(display-mode: standalone)').matches;
}

/**
 * initInstallBanner()
 *
 * Sets up the PWA install banner flow:
 *   1. Listens for the browser's 'beforeinstallprompt' event, which
 *      fires when the app meets PWA installability criteria.
 *   2. Captures the event so we can trigger it on demand.
 *   3. Shows the #install-banner element to invite the user to install.
 *   4. Binds the #install-btn to trigger the native install prompt.
 *   5. Hides the banner if the app is already installed (standalone mode).
 *
 * Call this once during app initialisation.
 */
export function initInstallBanner() {
    /* If the app is already installed as a standalone PWA, there is no
       reason to show the install banner — exit early. */
    if (isAppInstalledStandalone()) {
        return; /* App is installed; nothing to do */
    }

    /* Listen for the 'beforeinstallprompt' event on the window.
       This event is fired by Chromium-based browsers when the PWA
       meets the installability criteria (has a manifest, service worker,
       served over HTTPS, etc.). */
    window.addEventListener('beforeinstallprompt', (event) => {
        /* Prevent the browser's default mini-infobar from appearing.
           We want to show our own custom install banner instead. */
        event.preventDefault();

        /* Store the event so we can call .prompt() on it later when
           the user clicks our custom install button. */
        deferredInstallPrompt = event;

        /* Find the install banner element in the DOM */
        const banner = $('#install-banner');

        /* Show the banner by removing the Bootstrap 'd-none' utility class */
        if (banner) {
            banner.classList.remove('d-none'); /* Make the banner visible */
        }
    });

    /* Bind the install button's click handler. When the user clicks
       #install-btn, we trigger the deferred native install prompt. */
    const installBtn = $('#install-btn');

    if (installBtn) {
        installBtn.addEventListener('click', async () => {
            /* If we don't have a deferred prompt (e.g. the event never
               fired, or the user already responded), do nothing. */
            if (!deferredInstallPrompt) {
                return; /* No prompt available to show */
            }

            /* Show the native browser install dialog */
            deferredInstallPrompt.prompt();

            /* Wait for the user to respond to the prompt (accept or dismiss) */
            const choiceResult = await deferredInstallPrompt.userChoice;

            /* Log the outcome for debugging purposes */
            if (choiceResult.outcome === 'accepted') {
                /* The user accepted the install prompt */
                console.log('[iHymns] User accepted the PWA install prompt.');
            } else {
                /* The user dismissed the install prompt */
                console.log('[iHymns] User dismissed the PWA install prompt.');
            }

            /* The prompt can only be used once. Clear the reference so we
               don't try to re-trigger it. */
            deferredInstallPrompt = null;

            /* Hide the install banner since the prompt has been shown
               (regardless of whether the user accepted or dismissed it). */
            const banner = $('#install-banner');
            if (banner) {
                banner.classList.add('d-none'); /* Hide the banner */
            }
        });
    }

    /* Listen for the 'appinstalled' event, which fires when the PWA
       is successfully installed. Hide the banner in case it's still visible. */
    window.addEventListener('appinstalled', () => {
        /* Log the successful installation */
        console.log('[iHymns] PWA was installed successfully.');

        /* Hide the install banner */
        const banner = $('#install-banner');
        if (banner) {
            banner.classList.add('d-none'); /* Hide the banner */
        }

        /* Clear the deferred prompt reference as it's no longer needed */
        deferredInstallPrompt = null;
    });
}

/* =========================================================================
 * UPDATE CHECKER
 * ========================================================================= */

/**
 * showUpdateToast(newVersion)
 *
 * Displays a Bootstrap toast notification informing the user that a
 * new version of the app is available. The toast encourages the user
 * to refresh the page to get the latest version.
 *
 * @param {string} newVersion - The version string of the available update
 */
function showUpdateToast(newVersion) {
    /* Find the update toast element in the DOM.
       We expect an element with id="update-toast" to exist in the HTML,
       styled as a Bootstrap toast component. */
    const toastEl = $('#update-toast');

    /* If the toast element doesn't exist in the DOM, fall back to a
       simple console warning so the update isn't silently lost. */
    if (!toastEl) {
        console.warn(`[iHymns] Update available (v${newVersion}), but #update-toast element not found.`);
        return; /* Cannot show a toast without the DOM element */
    }

    /* Optionally update the toast body text to include the new version.
       Look for a .toast-body child to set the message. */
    const toastBody = toastEl.querySelector('.toast-body');
    if (toastBody) {
        /* Set a user-friendly update message with the new version number */
        toastBody.textContent = `A new version (v${newVersion}) is available. Please refresh to update.`;
    }

    /* Use Bootstrap's Toast API to show the notification.
       bootstrap.Toast.getOrCreateInstance avoids creating duplicate
       instances if the toast was already initialised. */
    if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
        /* Get or create a Bootstrap Toast instance for this element */
        const toast = bootstrap.Toast.getOrCreateInstance(toastEl);

        /* Show the toast notification to the user */
        toast.show();
    } else {
        /* Bootstrap JS is not loaded — fall back to manual visibility.
           Add the 'show' class to make the toast visible via CSS. */
        toastEl.classList.add('show');
        console.warn('[iHymns] Bootstrap Toast API not available; using fallback.');
    }
}

/**
 * checkForUpdate()
 *
 * Performs a single update check by fetching the version endpoint on the
 * server. Compares the server version against the locally stored version.
 * If they differ, shows a toast notification and updates the stored version.
 *
 * This function is called both on initial setup and on each periodic
 * interval tick from initUpdateChecker().
 *
 * @returns {Promise<void>}
 */
async function checkForUpdate() {
    try {
        /* Fetch the current version from the server.
           We append a cache-busting timestamp parameter to prevent the
           browser from serving a stale cached response. */
        const response = await fetch(`${VERSION_ENDPOINT}&t=${Date.now()}`);

        /* If the server responds with a non-OK status, bail out.
           We don't want to show false update notifications based on
           error responses. */
        if (!response.ok) {
            console.warn(`[iHymns] Update check failed: HTTP ${response.status}`);
            return; /* Non-OK response; skip this check cycle */
        }

        /* Parse the JSON response body.
           Expected format: { "version": "1.2.3", ... } */
        const data = await response.json();

        /* Extract the version string from the response */
        const serverVersion = data.version;

        /* If the server didn't return a version field, we can't compare.
           This guards against unexpected response formats. */
        if (!serverVersion) {
            console.warn('[iHymns] Update check: no "version" field in response.');
            return; /* Missing version data; skip this check cycle */
        }

        /* Retrieve the locally stored version for comparison.
           This is the version from the last successful check (or null
           on the very first run). */
        const localVersion = localStorage.getItem(VERSION_STORAGE_KEY);

        /* If we have a local version and it differs from the server
           version, an update is available. */
        if (localVersion && localVersion !== serverVersion) {
            /* Show the user a toast notification about the update */
            showUpdateToast(serverVersion);

            /* Also attempt to trigger a service worker update check.
               If a service worker is registered, calling .update() will
               fetch the SW script from the server and install a new
               version if the script has changed. */
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.ready.then((registration) => {
                    /* Ask the service worker to check for an updated script */
                    registration.update();
                });
            }
        }

        /* Persist the server version as the new local version.
           On first run (localVersion is null), this seeds the stored
           version without triggering an "update available" toast. */
        localStorage.setItem(VERSION_STORAGE_KEY, serverVersion);

    } catch (error) {
        /* Network errors, JSON parse errors, etc. are caught here.
           We log a warning but don't crash — the next interval tick
           will try again automatically. */
        console.warn('[iHymns] Update check encountered an error:', error);
    }
}

/**
 * initUpdateChecker(intervalMinutes)
 *
 * Starts a periodic update checker that runs at a fixed interval.
 * On each tick, it fetches the version endpoint and compares versions.
 * If a new version is detected, a toast notification is shown.
 *
 * The first check runs immediately (so the user learns about updates
 * as soon as possible), and subsequent checks run on the interval.
 *
 * @param {number} [intervalMinutes=30] - How often to check, in minutes.
 *                                         Defaults to 30 minutes.
 */
export function initUpdateChecker(intervalMinutes = DEFAULT_CHECK_INTERVAL_MINUTES) {
    /* Clear any previously running update checker interval to prevent
       duplicate timers if initUpdateChecker is called more than once. */
    if (updateCheckIntervalId !== null) {
        clearInterval(updateCheckIntervalId); /* Stop the old timer */
        updateCheckIntervalId = null;         /* Reset the reference */
    }

    /* Convert the interval from minutes to milliseconds.
       setInterval expects milliseconds, so we multiply by 60,000. */
    const intervalMs = intervalMinutes * 60 * 1000;

    /* Run the first update check immediately rather than waiting for
       the first interval tick. This ensures the user is notified of
       updates as soon as the app loads. */
    checkForUpdate();

    /* Start the periodic interval timer. Each tick calls checkForUpdate()
       which fetches the version endpoint and compares versions. */
    updateCheckIntervalId = setInterval(checkForUpdate, intervalMs);

    /* Log the checker configuration for debugging */
    console.log(`[iHymns] Update checker started (interval: ${intervalMinutes} min).`);
}
