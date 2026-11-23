/**
 * unsent-edits.js - "this store has changes the account has not been told about" (PR 2c review, epic #2180)
 * ========================================================================================================
 *
 * ELI5
 * ----
 * Each list the browser keeps for you (set lists, favourites, your own tags) gets a small sticky note the moment you
 * change it: "the account has not heard about this yet". The note is only taken down when the account has really
 * accepted the change (and was not paused). Sign-out reads the notes, so a change that never got through can never be
 * thrown away without asking you first.
 *
 * Detailed
 * --------
 *  - The note is a STAMP, not a yes/no. Every local edit writes a new stamp. A push remembers the stamp it started
 *    with and clears the note only if the stamp is STILL that one, so an edit made while the push was in the air keeps
 *    the note (nothing is ever marked as sent that was not sent).
 *  - Pure: every function takes the storage to work on, never throws (blocked storage reads as "nothing unsent",
 *    which is the older behaviour), and knows nothing about which store it serves.
 *  - Why a mark and not just the existing lists of added/removed ids: those only name favourites added or removed and
 *    set lists deleted. An edit to a set list's songs or to a favourite's tags is not in them, and before this file
 *    sign-out could not see such an edit once its 1.5 second timer had fired and the push had failed.
 *
 * @module modules/unsent-edits
 */

let sequence = 0;

/** A new stamp, different from every earlier one in this tab and (by the time and the random part) in any other tab. */
function newStamp() {
    sequence += 1;
    return Date.now().toString(36) + '.' + sequence + '.' + Math.random().toString(36).slice(2, 8);
}

/** The current stamp for a store, or null when nothing is unsent. */
export function readUnsent(storage, key) {
    try { return storage.getItem(key) || null; } catch (_e) { return null; }
}

/** Note that the store has a local change the account has not heard about. Returns the new stamp (null if storage is blocked). */
export function markUnsent(storage, key) {
    const stamp = newStamp();
    try { storage.setItem(key, stamp); return stamp; } catch (_e) { return null; }
}

/**
 * Take the note down, but only if no edit has happened since `stamp` was read.
 *
 * @param {Storage} storage
 * @param {string} key
 * @param {?string} stamp the stamp read BEFORE the push began; null/undefined means "nothing was marked then": leave it
 * @returns {boolean} true when the note is gone afterwards
 */
export function clearUnsentIf(storage, key, stamp) {
    const now = readUnsent(storage, key);
    if (now === null) return true;
    if (!stamp || now !== stamp) return false;
    try { storage.removeItem(key); return true; } catch (_e) { return false; }
}
