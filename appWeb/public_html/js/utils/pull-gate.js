/**
 * pull-gate.js - decide WHEN to fetch a person's saved settings again
 * =====================================================================
 * Account/profile rewrite step A2 (epic #2180).
 *
 * ELI5
 * ----
 * The app announces "the sign-in state changed" after EVERY page change, not
 * only after a real sign-in. Settings used to answer each announcement by
 * downloading the account's copy and writing it over what is on screen. If you
 * had just changed the theme and moved page within a second and a half, the
 * old account copy arrived first and undid your change. This tiny helper is a
 * door with a memory: it only opens when the signed-in person is different from
 * last time, or when half an hour has gone by.
 *
 * Detailed
 * --------
 * Pure and framework-free (the clock is injectable) so a test can drive it
 * directly. `begin(uid)` both asks and records in one step, so two events
 * arriving back to back cannot both be told "yes". If the fetch then fails the
 * caller calls `fail()` so the next announcement may try again, rather than the
 * failure being remembered as a success. Shared by settings.js and
 * list-sort.js (rule #22: one copy of the rule, not two).
 *
 * @module utils/pull-gate
 */

/** How long a successful fetch stays "fresh" for the same person: 30 minutes. */
export const PULL_GATE_MAX_AGE_MS = 30 * 60 * 1000;

/**
 * @param {{ now?: () => number, maxAgeMs?: number }} [opts]
 * @returns {{ begin: (uid: unknown) => boolean, fail: () => void, reset: () => void }}
 */
export function createPullGate(opts = {}) {
    const now = typeof opts.now === 'function' ? opts.now : Date.now;
    const maxAgeMs = typeof opts.maxAgeMs === 'number' ? opts.maxAgeMs : PULL_GATE_MAX_AGE_MS;
    /** @type {?string} the person the last fetch was for */
    let lastUid = null;
    /** @type {number} when that fetch started */
    let lastAt = 0;

    return {
        /**
         * Should we fetch now? If yes, the answer is remembered immediately.
         * No person id (signed out, or not known yet) always means "no".
         */
        begin(uid) {
            if (uid === null || uid === undefined || uid === '') return false;
            const id = String(uid);
            const t = now();
            if (lastUid === id && (t - lastAt) < maxAgeMs) return false;
            lastUid = id;
            lastAt = t;
            return true;
        },
        /** The fetch we just allowed did not work: forget it so we may retry. */
        fail() { lastUid = null; lastAt = 0; },
        /** Sign-out (or a different session): the next sign-in always fetches. */
        reset() { lastUid = null; lastAt = 0; },
    };
}
