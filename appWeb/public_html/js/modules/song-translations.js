/* ==========================================================================
 *  song-translations.js — Per-line lyric translation toggle (#1089 / #1100 P1)
 *
 *  ELI5: curators have been able to attach a translation to individual lyric
 *  lines for a while, but nothing on the site ever showed it. This module
 *  wires up the "Show translation" button on the song page: click it and a
 *  translated line appears right under each original line that has one;
 *  click again to hide them.
 *
 *  Detail: includes/pages/song.php now renders BOTH the source line and its
 *  translation(s) — the translation <p class="lyric-line-translation"> is
 *  already in the DOM, just hidden (`d-none`). That data is public and the
 *  SAME for every visitor of this song, so baking it into the (shared-cache,
 *  rule #6 in .claude/CLAUDE.md) fragment is fine; what's per-user is only
 *  whether it's currently SHOWN, which is exactly what this module toggles,
 *  client-side, with no re-fetch. song.php only renders the toggle button at
 *  all when the song actually has at least one translated line (no dead
 *  control on songs with none), so this module doesn't need to hide/remove
 *  anything when there's nothing to show — its own elements are simply
 *  absent from the DOM in that case, and it no-ops.
 *
 *  Wiring is ROUTER-driven (#1565/#1568 pattern — see present-mode.js /
 *  export-ui.js / home-page.js), NOT a fragment-inline <script>. The
 *  document's enforcing nonce CSP (script-src 'self' 'nonce-…', #117) would
 *  silently refuse a nonce-less inline <script>, and page=song is a
 *  shared-cache fragment (rule #6) that can never carry a per-request nonce
 *  anyway — so this is wired as a real ES module, imported by router.js's
 *  afterPageLoad() and invoked once the song fragment is in the DOM.
 *  https://developer.mozilla.org/docs/Web/HTTP/Headers/Content-Security-Policy/script-src
 * ========================================================================== */

import { STORAGE_LANGUAGE_FILTER } from '../constants.js';
import { orderByPreference, usablePreferenceList } from '../utils/language-tags.js';

/**
 * Wire the per-line translation toggle on the song page. Idempotent — the
 * SPA injects a fresh song fragment (and a fresh button, if the new song has
 * translations) on every navigation, so the `dataset.wired` guard is per-DOM-
 * node, not a global "only once ever" flag (same pattern as export-ui.js's
 * `menu.dataset.wired` / present-mode.js's `btnPresent.dataset.wired`).
 *
 * DOM-first (rule #30): reads everything it needs from the fragment's own
 * markup — `.page-song[data-has-line-translations]` gates whether there's
 * anything to wire at all, `[data-line-translations-toggle]` is the button,
 * `.lyric-line-translation` rows are the (currently hidden) translation
 * lines song.php already interleaved into `.lyric-lines`. No fetch, no
 * argument needed from the router.
 */
export function initLineTranslations() {
    const page = document.querySelector('.page-song[data-has-line-translations]');
    const btn  = document.querySelector('[data-line-translations-toggle]');
    if (!page || !btn || btn.dataset.wired === '1') { return; }

    const rows = document.querySelectorAll('.lyric-line-translation');
    /* Defensive only — song.php renders the button iff it also rendered at
       least one row, so this should never fire. If it somehow did (e.g. a
       future edit decouples the two), there's nothing to toggle: leave the
       button inert rather than wire a click handler with no effect. */
    if (!rows.length) { return; }

    btn.dataset.wired = '1';

    const labelEl = btn.querySelector('[data-line-translations-label]');

    btn.addEventListener('click', () => {
        const showing = btn.getAttribute('aria-pressed') === 'true';
        const next = !showing;
        rows.forEach((row) => row.classList.toggle('d-none', !next));
        btn.setAttribute('aria-pressed', String(next));
        if (labelEl) {
            labelEl.textContent = next ? 'Hide translation' : 'Show translation';
        }
    });
}

/**
 * Put the reader's own languages first in the song page's "Also in …"
 * translation picker (#2137 — the shared language policy's UI-020).
 *
 * ELI5: the server lists the other-language versions with the original first
 * and the rest A to Z by name. If you told iHymns which languages you read,
 * this moves those to the top, in your order — once, when the page opens.
 *
 * DETAIL: the song page is a shared, cached page piece (rule #6 in
 * .claude/CLAUDE.md), so the server cannot know who is reading it; only the
 * browser can. Everything else about the order stays the server's (original
 * first, then by name). Nothing moves later, when an item is picked (UI-050).
 * DOM-first (rule #30): each `<li>` carries `data-language-tag`, emitted by
 * includes/pages/song.php. The preference list is the same saved list the
 * language filter uses (STORAGE_LANGUAGE_FILTER).
 *
 * #2137 review round 6 (the fifth review's finding 5) — only the FIRST 32 of
 * that list are used (usablePreferenceList()), exactly as every other reader
 * in the browser and the server use them. It used to read the whole list, so
 * a list saved before the 32-language limit put its 33rd language (French, in
 * the review's case) at the top of this menu while the language filter, the
 * request header and the server all ignored it.
 */
export function orderTranslationPicker() {
    const menu = document.querySelector('.page-song .song-translations .dropdown-menu');
    if (!menu || menu.dataset.readerOrdered === '1') { return; }
    menu.dataset.readerOrdered = '1';
    let preferences = [];
    try {
        const raw = localStorage.getItem(STORAGE_LANGUAGE_FILTER);
        const parsed = raw ? JSON.parse(raw) : [];
        preferences = usablePreferenceList(parsed);   /* the first 32 tag-shaped entries, in order */
    } catch (_e) {
        preferences = [];   /* private mode / corrupt value: keep the server's order */
    }
    if (preferences.length === 0) { return; }
    const items = Array.from(menu.querySelectorAll(':scope > li[data-language-tag]'));
    const ordered = orderByPreference(items, preferences, (li) => li.dataset.languageTag || '');
    ordered.forEach((li) => menu.appendChild(li));
}
