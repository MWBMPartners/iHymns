/**
 * Songbook language filter (#679)
 *
 * Wires the <select class="js-songbook-language-filter"> rendered by
 * the /includes/partials/songbook-language-filter.php partial to a
 * pure client-side hide/show pass over the songbook tiles on the
 * surrounding page.
 *
 * Rules:
 *   - "All languages" (value="") → every tile visible.
 *   - Specific subtag picked     → tiles whose data-songbook-language
 *     starts with that subtag stay visible. Tiles WITHOUT a
 *     data-songbook-language attribute (i.e. songbooks with no
 *     Language set) ALWAYS stay visible — the absence is treated as
 *     "multi-lingual / not specified" so a curated grouping that
 *     mixes languages doesn't disappear when the user filters.
 *
 * Persistence:
 *   - The user's choice is stored in localStorage under
 *     'songbook-language-filter' so a return visit restores it.
 *
 * Accessibility:
 *   - Filtered-out tiles are hidden via display:none AND aria-hidden
 *     so a screen reader skips them.
 *   - The filter <select> is keyboard-reachable (rendered as a normal
 *     form control) and operates on `change`, so a curator using
 *     keyboard navigation gets the same UX as one with a mouse.
 *
 * The module is idempotent: bootSongbookLanguageFilter() can be
 * called multiple times (e.g. once on first page load + once after
 * an SPA navigation) without binding duplicate handlers.
 */

const STORAGE_KEY = 'songbook-language-filter';

/* Find every tile on the page that corresponds to a songbook. The
   home.php compact tiles use `.card-songbook` directly on the inner
   div; the /songbooks full-card tiles use `.card-songbook` on the
   outer <a>. Both surfaces carry data-songbook-language on whichever
   element renders the data attribute. We grab the closest tile
   container — `.col` for home, `.col-12.col-sm-6.col-md-4.col-lg-3`
   for /songbooks — by walking up to the nearest column. */
function findTileColumn(tile) {
    /* Bootstrap col classes start with `col` or `col-`. Walk up until
       we hit a parent that's a grid column, or fall through to the
       tile itself (degrades to hiding just the inner card). */
    let node = tile;
    while (node && node.parentElement) {
        node = node.parentElement;
        if (node.classList && (node.classList.contains('col') ||
            Array.from(node.classList).some(c => c.startsWith('col-')))) {
            return node;
        }
    }
    return tile;
}

function applyFilter(rootEl, subtag) {
    const tiles = rootEl.querySelectorAll('[data-songbook-id]');
    tiles.forEach(tile => {
        const tileLang = (tile.dataset.songbookLanguage || '').toLowerCase();
        const col = findTileColumn(tile);
        if (!col) return;

        const shouldShow = (() => {
            if (!subtag) return true;                      // "All languages"
            if (!tileLang) return true;                    // un-tagged → always shown
            return tileLang.startsWith(subtag.toLowerCase());
        })();

        if (shouldShow) {
            col.style.removeProperty('display');
            col.removeAttribute('aria-hidden');
        } else {
            col.style.display = 'none';
            col.setAttribute('aria-hidden', 'true');
        }
    });
}

/**
 * Boot the language filter on the page. Call once per
 * SPA-page-render. Idempotent — re-calling on the same DOM is safe.
 *
 * @param {ParentNode} [root=document] Where to scope the search.
 */
export function bootSongbookLanguageFilter(root) {
    const scope = root || document;
    const filter = scope.querySelector('.js-songbook-language-filter');
    if (!filter || filter.dataset.songbookFilterBooted === '1') return;
    filter.dataset.songbookFilterBooted = '1';

    /* The filter element is rendered above the tile grid. Both surfaces
       use document.body as the natural search scope for tiles — the
       grid sits on the same page as the filter. */
    const applyToPage = (subtag) => applyFilter(scope, subtag);

    /* Restore saved choice. localStorage may be unavailable in private
       browsing modes; treat read errors as "no saved state". */
    let saved = '';
    try { saved = localStorage.getItem(STORAGE_KEY) || ''; } catch (_e) {}

    /* If the saved value is no longer in the dropdown (the curator
       removed every songbook in that language), fall back to "All". */
    const optionExists = Array.from(filter.options).some(o => o.value === saved);
    if (!optionExists) saved = '';

    if (saved) {
        filter.value = saved;
        applyToPage(saved);
    }

    filter.addEventListener('change', () => {
        const v = filter.value;
        try {
            if (v) localStorage.setItem(STORAGE_KEY, v);
            else   localStorage.removeItem(STORAGE_KEY);
        } catch (_e) { /* private mode — best effort */ }
        applyToPage(v);
    });
}
