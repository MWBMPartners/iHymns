/* ==========================================================================
 *  store.js — tiny reactive store for the v2 Song Editor (#1200)
 *
 *  The song is held as RELATIONAL SLICES that mirror MySQL (song scalars,
 *  components[], writers[]/composers[]/…, tags[], links[], arrangement[]) — NOT
 *  one denormalised JSON blob (the early-JSON model the rewrite replaces).
 *
 *  Each slice is independently observable: a subscriber to `components` is
 *  notified only when `components` changes, so editing a credit never re-renders
 *  the component list. This is what kills the "rebuild every card on every edit"
 *  slowness of the old editor. Hand-rolled, zero deps, no build step.
 *
 *  Usage:
 *    const store = createStore({ song: {}, components: [], writers: [] });
 *    const off = store.subscribe('components', (comps) => renderComponents(comps));
 *    store.update('components', (c) => [...c, newComp]);   // notifies only `components`
 *    store.set('song', loadedSong.song);                   // notifies only `song`
 *    store.subscribe('*', (state) => …);                   // notified on ANY slice change
 * ========================================================================== */

export function createStore(initial = {}) {
    /* The single source of truth — a flat map of slice-name -> value. */
    const state = { ...initial };

    /* slice-name -> Set<callback>. The '*' key gets every change. */
    const subs = new Map();

    function get(slice) {
        return slice === undefined ? state : state[slice];
    }

    /** Replace a slice wholesale + notify its subscribers. */
    function set(slice, value) {
        state[slice] = value;
        notify(slice);
        return value;
    }

    /** Mutate a slice via a pure-ish function (return the new value) + notify. */
    function update(slice, mutator) {
        state[slice] = mutator(state[slice], state);
        notify(slice);
        return state[slice];
    }

    /** Subscribe to a slice (or '*' for all). Returns an unsubscribe fn. */
    function subscribe(slice, fn) {
        if (!subs.has(slice)) { subs.set(slice, new Set()); }
        subs.get(slice).add(fn);
        return function off() {
            const set = subs.get(slice);
            if (set) { set.delete(fn); }
        };
    }

    function notify(slice) {
        const direct = subs.get(slice);
        if (direct) {
            direct.forEach((fn) => {
                try { fn(state[slice], state); }
                catch (e) { console.error('[editor-store] subscriber for "' + slice + '" threw:', e); }
            });
        }
        const wildcard = subs.get('*');
        if (wildcard) {
            wildcard.forEach((fn) => {
                try { fn(state, slice); }
                catch (e) { console.error('[editor-store] wildcard subscriber threw:', e); }
            });
        }
    }

    return { get, set, update, subscribe };
}
