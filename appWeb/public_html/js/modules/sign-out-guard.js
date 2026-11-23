/**
 * sign-out-guard.js - sign out without losing anything that has not reached the account (PR 2c-3, epic #2180)
 * ==========================================================================================================
 *
 * ELI5
 * ----
 * When a person signs out of the account on this browser, the browser should forget their personal data, so the
 * next person to use a shared computer does not see it. But if something the person changed a moment ago has NOT
 * reached their account yet (no signal, a slow connection, a paused site), forgetting it would lose it for good.
 * So sign-out first tries to send everything that is waiting. If something is still left, it ASKS:
 * "N changes have not reached your account yet", with three ways out:
 *   - Try again (up to three times; on the last question this button reads "Stay signed in" instead),
 *   - Sign out anyway (the waiting changes are lost from this browser; ALWAYS the same button in the same place), or
 *   - close the box (stay signed in; nothing happens).
 * Only then does it clear the browser's copy.
 *
 * Detailed
 * --------
 *  - Only runs when the new account sync is switched on for this site (`config.profileSync.enabled`, the admin switch
 *    plus the database objects). With it off, sign-out behaves exactly as it did before (plan, step 2c-3 "Switch off").
 *  - "Send what is waiting" = each store's own flushNow(): favourites (and custom tags), set lists, the older settings
 *    box upload and the per-item profile sync. All four run at once, with an overall time limit so a hung network
 *    can never trap a person signed in.
 *  - "Count what is still unsent" reads: the profile outbox, favourite adds and removals still waiting, set-list
 *    removals still waiting, a waiting settings upload, each store's own "has edits the account has not heard about" mark
 *    (unsent-edits.js: this is what catches an edit whose push already failed) and the offline queue's "a sync is wanted"
 *    markers. A mark or marker is only added to the count when the matching list shows nothing (it would count the same
 *    change twice).
 *  - A queue marker is dropped ONLY after its store really pushed and the account took it (flushNow() returns false for a
 *    failed push and for a paused site's read-only answer, so the marker, and so the count, survives).
 *    Which queue types exist is derived from user-auth.js by tests/test-sign-out-clearing.js (rule #35).
 *  - A failure to read any one of them is ignored (never blocks sign-out).
 *  - The dialog is the app's own app.showChoice(); closing it answers null, which means "stay signed in".
 *  - The clearing itself is clearPersonalDataAtSignOut() in account-data-owner.js, which also removes the outbox and
 *    cursor. This file does not touch storage.
 *
 * @module modules/sign-out-guard
 */

import { offlineQueue } from './offline-queue.js';
import { stashHoldsUnsent } from './account-data-owner.js';

/** The offline-queue types that mean "a sync of this kind is wanted". Must equal the types user-auth.js drains (the test derives them). */
export const UNSENT_QUEUE_TYPES = Object.freeze(['setlists-sync', 'favorites-sync', 'custom-tags-sync']);
/** How many times "Try again" is offered. */
export const MAX_RETRIES = 3;
/** The longest sign-out waits for everything to be sent. */
export const FLUSH_TIMEOUT_MS = 10000;

/** Is the guarded sign-out in force for this site? Off = the older behaviour exactly. */
export function signOutGuardActive(app) {
    return app?.config?.profileSync?.enabled === true;
}

/** PURE: the sentence the dialog shows. On the last round there is no "try again" left to offer, so the sentence does not mention it. */
export function unsentMessage(total, canRetry = true) {
    const n = Number(total) || 0;
    return (n === 1 ? '1 change has' : `${n} changes have`) + ' not reached your account yet. '
        + 'If you sign out now, ' + (n === 1 ? 'it' : 'they') + ' will be lost from this browser. '
        + (canRetry
            ? 'You can try sending ' + (n === 1 ? 'it' : 'them') + ' again, or sign out anyway.'
            : 'You can stay signed in, or sign out anyway.');
}

async function defaultDropMarkers(type) {
    try { await offlineQueue.drainLatest(type, async () => true); } catch (_e) { /* nothing queued, or storage blocked */ }
}

/**
 * Try to send everything that is waiting, in parallel, within a time limit.
 *
 * @param {object} app
 * @param {{timeoutMs?: number, setTimer?: Function, clearTimer?: Function, dropMarkers?: Function}} [deps] test hooks
 */
export async function flushEverything(app, deps = {}) {
    const wait = deps.timeoutMs ?? FLUSH_TIMEOUT_MS;
    const setTimer = deps.setTimer || ((fn, ms) => setTimeout(fn, ms));
    const clearTimer = deps.clearTimer || ((t) => clearTimeout(t));
    const dropMarkers = deps.dropMarkers || defaultDropMarkers;
    /* [store, the queue markers a successful flush of that store satisfies] */
    const jobs = [
        [app?.favorites, ['favorites-sync', 'custom-tags-sync']],
        [app?.setList, ['setlists-sync']],
        [app?.settings, []],
        [app?.profileSync, []],
    ];
    const run = Promise.allSettled(jobs.map(async ([store, types]) => {
        if (typeof store?.flushNow !== 'function') return true;
        const ok = await store.flushNow();
        if (ok) { for (const t of types) await dropMarkers(t); }
        return ok;
    }));
    let timer = null;
    await Promise.race([run, new Promise((resolve) => { timer = setTimer(resolve, wait); })]);
    if (timer !== null) clearTimer(timer);
}

/**
 * How many changes have not reached the account?
 *
 * @param {object} app
 * @param {{queueCount?: Function, uid?: *, storage?: Storage}} [deps] test hooks; `uid` is who is signing out (read BEFORE anything was sent)
 * @returns {Promise<{total: number, parts: object}>}
 */
export async function countUnsentChanges(app, deps = {}) {
    const queueCount = deps.queueCount || ((t) => offlineQueue.count(t));
    const parts = { profile: 0, favourites: 0, setlistDeletes: 0, settings: 0, queued: 0, edits: 0, setAside: 0 };
    const safely = (fn) => { try { return fn(); } catch (_e) { return 0; } };
    parts.profile = safely(() => (app?.profileSync?.isActive?.() ? app.profileSync.pendingCount() : 0)) || 0;
    parts.favourites = safely(() => (app?.favorites?.getPendingAdds?.().length || 0) + (app?.favorites?.getPendingDeletes?.().length || 0)) || 0;
    parts.setlistDeletes = safely(() => app?.setList?.getPendingDeletes?.().length || 0) || 0;
    parts.settings = safely(() => (app?.settings?.hasUnsentChanges?.() ? 1 : 0)) || 0;
    /* PR 2c review: the per-store "has edits the account has not heard about" marks. An edit to a set list's songs or a
       favourite's tags is in none of the lists above, and a push that already failed leaves no timer either, so without
       these a lost change was never counted. A mark is counted once per store, and only when the store's own lists above
       do not already name a change (it would count the same change twice). */
    const marked = (store, getter) => safely(() => (store?.[getter]?.() ? 1 : 0)) || 0;
    const setlistMark = marked(app?.setList, 'getUnsentStamp');
    const favouriteMark = marked(app?.favorites, 'getUnsentStamp');
    const tagMark = marked(app?.favorites, 'getTagsUnsentStamp');
    if (parts.setlistDeletes === 0) parts.edits += setlistMark;
    if (parts.favourites === 0) parts.edits += favouriteMark;
    parts.edits += tagMark;
    /* PR 2c review: a sign-in that ended while the changes were being sent has already moved the person's data into the set-aside
       copy (so the lists above read empty). What is still unsent inside that copy counts too, once. */
    parts.setAside = safely(() => (stashHoldsUnsent(deps.storage || localStorage, deps.uid) ? 1 : 0)) || 0;
    for (const type of UNSENT_QUEUE_TYPES) {
        let rows = 0;
        try { rows = Number(await queueCount(type)) || 0; } catch (_e) { rows = 0; }
        if (rows <= 0) continue;
        /* A marker only says "a sync is wanted". Count it when nothing else already names the change. */
        if (type === 'favorites-sync' && parts.favourites > 0) continue;
        if (type === 'setlists-sync' && (parts.setlistDeletes > 0 || setlistMark > 0)) continue;
        if (type === 'favorites-sync' && favouriteMark > 0) continue;
        if (type === 'custom-tags-sync' && tagMark > 0) continue;
        parts.queued += 1;
    }
    return { total: parts.profile + parts.favourites + parts.setlistDeletes + parts.settings + parts.queued + parts.edits + parts.setAside, parts };
}

/**
 * Decide whether to go ahead with the sign-out. Sends what is waiting, counts what is left, and asks if needed.
 *
 * @param {object} app needs showChoice() when something is left unsent
 * @param {{flush?: Function, count?: Function, uid?: *}} [deps] test hooks; `uid` = who is signing out, read before the flush
 * @returns {Promise<'go'|'stay'>}
 */
export async function confirmSignOut(app, deps = {}) {
    const flush = deps.flush || (() => flushEverything(app));
    const count = deps.count || (() => countUnsentChanges(app, { uid: deps.uid }));
    await flush();
    let c = await count();
    if (c.total === 0) return 'go';
    if (typeof app?.showChoice !== 'function') return 'stay';   /* cannot ask: do not lose anything */
    for (let tries = 0; ; tries++) {
        const lastChance = tries >= MAX_RETRIES;
        /* "Sign out anyway" is ALWAYS option2 (the same button, in the same place, with the same look) and the harmless choice is
           always option1, the button beside it: "Try again" on the first rounds, "Stay signed in" on the last. Before the review the
           last round swapped them, so the red button a finger had been tapping for "Try again" became "Sign out anyway". */
        const answer = await app.showChoice(unsentMessage(c.total, !lastChance), {
            title: 'Not everything was sent',
            option1Text: lastChance ? 'Stay signed in' : 'Try again',
            option2Text: 'Sign out anyway',
            option1Class: 'btn-primary',
            option2Class: 'btn-outline-danger',
        });
        if (answer === null) return 'stay';                         /* the box was closed: stay signed in */
        if (answer === 'option2') return 'go';                      /* sign out anyway */
        if (lastChance) return 'stay';                              /* option1 on the last round: stay signed in */
        await flush();                                              /* option1: try again */
        c = await count();
        if (c.total === 0) return 'go';
    }
}
