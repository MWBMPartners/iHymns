/**
 * iHymns — song key / tempo / time signature on the song page (#298, wired #1671 F3)
 *
 * ELI5: asks the server what musical key this song is in and shows it, then
 * tells the transpose controls what the starting key was so their +/- buttons
 * can name the key you have transposed INTO.
 *
 * ============================================================================
 * WHY THIS FILE EXISTS — a feature that was inert end to end
 * ============================================================================
 *
 * #298 shipped `tblSongKeys`, the `song_key` / `song_key_save` endpoints, and
 * a hidden `#song-key-container` block in `includes/pages/song.php`. It then
 * shipped nothing that read or wrote any of it:
 *
 *   - no caller for either endpoint, on web, Apple or Android;
 *   - no JS referencing the markup's ids;
 *   - and `transpose.js` reads `songPage.dataset.key`, which `song.php` only
 *     emits `if (!empty($song['key']))` — a field `SongData` NEVER SETS. (Its
 *     SELECT aliases `CapoFret AS capoFret`, and there is no key column on
 *     `tblSongs` at all.) So `data-key` has never once been rendered, and
 *     `transpose.js`'s entire key-display branch has never executed in
 *     production.
 *
 * This module closes the read half: one GET, one badge, and — the part that
 * matters — handing the key to `transpose.js` so its display branch finally
 * has an input. The write half is the "Musical key" panel in the v2 editor
 * (manage/editor/v2/song-key-panel.js).
 *
 * `data-capo` has the SAME never-set-by-SongData problem and is NOT fixed
 * here; see the report for #1671 F3 — it is a separate field on a separate
 * table and guessing at it would be scope creep.
 *
 * ============================================================================
 * WHY A FETCH RATHER THAN SERVER-RENDERING IT INTO THE FRAGMENT
 * ============================================================================
 *
 * Because rendering it server-side would leave `?action=song_key` with no
 * caller — the exact orphan this batch exists to remove — and because the
 * router ALREADY makes two per-song follow-up requests on the same hook
 * (`loadTranslations`, `loadRelatedSongs`). This is a third of the same shape,
 * not a new pattern. The song fragment stays shared-cacheable either way.
 *
 * STANDING CONSTRAINTS: rule #30 (a real ES module imported from
 * `afterPageLoad()`, inputs read from `data-*` on the fragment — the fragment
 * cannot carry a script at all); rule #31 (`apiFetch`, never bare `fetch()`);
 * rule #35 (a 404 is recognised by its STATUS, never by the sentence
 * "No key data found for this song.").
 *
 * @see appWeb/public_html/api.php — case 'song_key'
 * @see appWeb/public_html/js/modules/transpose.js — the consumer of `dataset.key`
 */

/* No `escapeHtml` import: every value this module writes goes through
   `textContent`, which cannot interpret markup. Importing an escaper it does
   not use would suggest to a reader that some path here builds HTML. */
import { apiFetch } from '../utils/api-client.js';

/* =========================================================================
 * PURE HELPERS — asserted by tests/test-song-key-ui.js, which CALLS them.
 * ========================================================================= */

/**
 * The muted detail line beside the key badge.
 *
 * `tempo` is null when unspecified; `timeSignature` is '' when unspecified
 * (the column is NOT NULL, so the server stores the empty string — see
 * includes/song_key.php's header). Both absent → '' so the caller can leave
 * the element empty rather than render a lone separator.
 *
 * @param {{tempo?: number|null, timeSignature?: string|null}} key
 * @returns {string} Plain text.
 */
export function keyDetailLine(key) {
    const parts = [];
    const tempo = key?.tempo;
    if (typeof tempo === 'number' && Number.isFinite(tempo) && tempo > 0) {
        parts.push(tempo + ' BPM');
    }
    const ts = typeof key?.timeSignature === 'string' ? key.timeSignature.trim() : '';
    if (ts !== '') parts.push(ts);
    return parts.join(' · ');
}

/**
 * Is this payload worth showing?
 *
 * A row can exist with an empty `OriginalKey` (the column is
 * `NOT NULL DEFAULT ''`), in which case there is nothing to badge — and
 * showing an empty badge reads as a rendering bug rather than as missing data.
 *
 * @param {*} key
 * @returns {boolean}
 */
export function hasDisplayableKey(key) {
    return !!key && typeof key.originalKey === 'string' && key.originalKey.trim() !== '';
}

/* =========================================================================
 * DOM WIRING
 * ========================================================================= */

/**
 * Entry point — called by `router.js` after a song fragment is injected.
 *
 * @param {string} [songIdFallback] The route param, used ONLY when the
 *   fragment does not carry `data-song-id` (a stale service-worker copy). The
 *   fragment is the primary source, matching export-ui.js / present-mode.js.
 */
export function initSongKey(songIdFallback = '') {
    const panel = document.querySelector('[data-song-key-panel]');
    const songPage = document.querySelector('.page-song');
    /* Both must be present. The panel alone would mean a song fragment
       without a song; the song page alone means an older cached fragment that
       predates this markup, in which case there is nothing to fill in. */
    if (!panel || !songPage) return;

    const songId = songPage.dataset.songId || songIdFallback || '';
    if (songId === '') return;

    load(songId, panel, songPage);
}

/**
 * @param {string} songId
 * @param {Element} panel
 * @param {HTMLElement} songPage
 */
async function load(songId, panel, songPage) {
    let key = null;
    try {
        const res = await apiFetch('/api?action=song_key&id=' + encodeURIComponent(songId));
        /* 404 is the NORMAL answer: `tblSongKeys` is sparse and most songs
           have no row. Recognised by status, never by the server's sentence
           (rule #35) — and deliberately NOT surfaced to the user, because
           "this song has no recorded key" is not a failure. */
        if (res.status === 404) return;
        if (!res.ok) return;
        const data = await res.json();
        key = data?.key || null;
    } catch (_e) {
        /* Offline or a network blip. The badge simply does not appear; the
           song itself is already fully rendered and readable. */
        return;
    }

    if (!hasDisplayableKey(key)) return;

    /* Guard against a navigation that happened while the request was in
       flight — the panel we captured may have been swapped out from under us,
       in which case writing to it paints an invisible detached node and, worse,
       would hand a stale key to the NEW song's transpose controls. */
    if (!panel.isConnected || !songPage.isConnected) return;
    if ((songPage.dataset.songId || '') !== songId) return;

    render(panel, key);

    /* Hand the key to transpose.js.
     *
     * `dataset.key` is the input its display branch reads, and it reads it
     * ONCE at init. So the order here is load-bearing: set the attribute, THEN
     * re-run initSongPage(). The module is idempotent in the sense that it
     * builds a fresh `.transpose-controls` node each time, so the old one is
     * removed first to avoid stacking two copies on a slow connection where
     * the router's own call has already run. */
    songPage.dataset.key = key.originalKey;
    const existing = songPage.querySelector('.transpose-controls');
    if (existing) existing.remove();
    try {
        window.iHymnsApp?.transpose?.initSongPage?.();
    } catch (_e) {
        /* transpose.js owns its own failures; a broken transpose widget must
           not also remove the key badge we just rendered. */
    }
}

/**
 * @param {Element} panel
 * @param {{originalKey: string, tempo?: number|null, timeSignature?: string|null}} key
 */
function render(panel, key) {
    const badge = panel.querySelector('#song-key-badge');
    const info  = panel.querySelector('#song-key-info');
    const detail = keyDetailLine(key);

    if (badge) {
        badge.textContent = 'Key: ' + key.originalKey;
        /* An accessible name that reads as a sentence. "Key: G" spoken from a
           bare badge is ambiguous; "Musical key: G" is not. */
        badge.setAttribute('aria-label', 'Musical key: ' + key.originalKey);
    }
    if (info) {
        info.textContent = detail;
    }

    /* `d-flex` is added rather than only removing `d-none`, because the markup
       needs a flex context for its `gap-2` / `align-items-center` to mean
       anything — and the original block achieved that with a `d-inline-flex`
       it then overrode with `display:none !important`, so it could never be
       shown by a class toggle at all. */
    panel.classList.remove('d-none');
    panel.classList.add('d-inline-flex');
}
