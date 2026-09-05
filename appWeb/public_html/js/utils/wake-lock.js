/**
 * iHymns — keep the screen awake
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Phones, tablets and laptops dim and then lock themselves after a minute or two
 * of nobody touching them. That is exactly the wrong behaviour when the device
 * is showing the words of a song to a room full of people. This asks the browser
 * to keep the screen on, and lets go again the moment we stop needing it.
 *
 * WHY THIS EXISTS (#2079)
 * -----------------------
 * A search of the whole codebase for "wakeLock" returned nothing at all. So a
 * tablet propped up showing a verse, or a phone driving a set list, would go
 * dark partway through a long prayer — and a volunteer running the service has
 * no reason to know they were supposed to change their device's power settings
 * first. It is the sort of fault that only shows up in front of a congregation.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It cannot force anything. The Screen Wake Lock API is a request, and the
 * browser is free to refuse it or take it away — most commonly when the page is
 * hidden, when the battery is low, or when the operating system decides
 * otherwise. Every one of those cases must leave the app working normally, just
 * without the screen staying on. So every call here is wrapped and every failure
 * is silent.
 *
 * THE RE-REQUEST IS THE LOAD-BEARING PART
 * ---------------------------------------
 * A wake lock is dropped automatically whenever the page becomes hidden — which
 * includes switching apps, or the operator locking the device by hand. It is NOT
 * restored when the page comes back. Without the visibilitychange listener
 * below, the very first time an operator checked a message mid-service the
 * screen would start sleeping again for the rest of the service, with nothing to
 * indicate why. That listener is the difference between this working and merely
 * appearing to.
 *
 * @see https://developer.mozilla.org/docs/Web/API/Screen_Wake_Lock_API
 */

/** @type {WakeLockSentinel|null} the live lock, when we hold one */
let sentinel = null;

/** @type {number} how many callers currently want the screen kept awake */
let holders = 0;

/** Ask the browser for a lock. Silent on refusal — never throws to the caller. */
async function request() {
    /* Feature-detect rather than assume: Safari only gained this in 16.4, and a
       page served over plain http never gets it at all. */
    if (!('wakeLock' in navigator)) return;

    /* If we think we already hold a lock, check it is actually still alive
       before believing ourselves. The browser releases the lock whenever the
       page is hidden and fires a 'release' event, which the handler below uses
       to clear this reference — but relying on that event alone is fragile,
       and a stale reference here would make every later re-request do nothing,
       silently, for the rest of the service. `released` is a real property on
       the sentinel, so this is a cheap second line of defence rather than a
       guess. */
    if (sentinel && sentinel.released !== true) return;
    sentinel = null;
    try {
        sentinel = await navigator.wakeLock.request('screen');
        /* The browser can revoke it without telling us anything else. Clearing
           our own reference here means a later re-request is not blocked by a
           stale sentinel that is no longer holding anything. */
        sentinel.addEventListener('release', () => { sentinel = null; });
    } catch {
        sentinel = null;
    }
}

/* Re-ask when the page becomes visible again — see the note above about why
   this is the part that actually matters. Registered once, at module load. */
if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && holders > 0) request();
    });
}

/**
 * Say that something on screen now needs the display kept awake.
 *
 * Counts callers, so two things asking at once (say, presentation mode opened
 * from inside a set list) do not release each other's lock when the first one
 * finishes. Always pair a call to this with a call to `releaseWakeLock()`.
 *
 * @returns {Promise<void>} resolves once the request has been made or refused
 */
export async function acquireWakeLock() {
    holders += 1;
    await request();
}

/**
 * Say that this caller no longer needs the screen kept awake.
 *
 * Only actually lets go when the last caller has finished. Safe to call more
 * times than acquire was called — the count never drops below zero.
 *
 * @returns {Promise<void>}
 */
export async function releaseWakeLock() {
    holders = Math.max(0, holders - 1);
    if (holders > 0 || !sentinel) return;
    try {
        await sentinel.release();
    } catch {
        /* Already gone, which is the outcome we wanted anyway. */
    }
    sentinel = null;
}

/** For tests: is a lock currently held? Not part of the app's own behaviour. */
export function _wakeLockHeldForTests() {
    return sentinel !== null;
}
