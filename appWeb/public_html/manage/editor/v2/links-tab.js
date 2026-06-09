/* ==========================================================================
 *  links-tab.js — the v2 Song Editor "Links" tab (#1200, Phase 3)
 *
 *  External links (Hymnary, YouTube, Spotify, Wikipedia, …). This tab is a thin
 *  wrapper over the SHARED card-list editor the whole codebase uses
 *  (window.iHymnsExtLinksEditor, #833) + its URL→provider auto-detect
 *  (window.iHymnsLinkDetect, #845) — per CLAUDE.md #15 we reuse those modules,
 *  never fork a per-entity links editor or re-inline a provider regex list.
 *
 *  Persistence is a debounced RECONCILE (DELETE-then-INSERT) through
 *  api2.php link_save_all → the same saveExternalLinksForRow() helper the
 *  songbook/work surfaces use. Links are a bounded sub-form with no dual-save
 *  path, so a reconcile is safe here (no #1178-style race) and lets us keep the
 *  shared module's DOM + field naming verbatim. A failed save surfaces a real
 *  error; the typed rows stay in the DOM for retry.
 *
 *  mountLinksTab(container, { store, api, songId, toast }) -> teardown fn
 *  Hydrates from the store's `links` slice: [{ typeId, url, note, verified, sortOrder }, ...].
 *  Link TYPES come from window._iHymnsLinkTypes (emitted by editor2.php, like index.php).
 * ========================================================================== */

const SAVE_DEBOUNCE_MS = 600;

export function mountLinksTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};

    container.innerHTML = '';

    const intro = document.createElement('p');
    intro.className = 'text-muted small';
    intro.textContent = 'Paste a URL — the provider auto-detects. Changes save automatically.';

    const rowsEl = document.createElement('div');
    rowsEl.id = 'v2-link-rows';
    rowsEl.className = 'd-flex flex-column gap-2 mb-2';

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary';
    addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add link';

    container.append(intro, rowsEl, addBtn);

    /* The shared module is a global IIFE (loaded as a classic <script> before
       this module). If it didn't load, fail visibly rather than silently. */
    if (!window.iHymnsExtLinksEditor || typeof window.iHymnsExtLinksEditor.mount !== 'function') {
        rowsEl.innerHTML = '<div class="alert alert-warning py-2 small mb-0">The external-links editor module did not load.</div>';
        return function () { container.innerHTML = ''; };
    }

    const editor = window.iHymnsExtLinksEditor.mount({ rowsEl: rowsEl, addBtnEl: addBtn });
    editor.setRows((store.get('links') || []).map((l) => ({
        typeId: l.typeId, url: l.url, note: l.note, verified: !!l.verified,
    })));

    /* Read the live DOM (not the stale slice) so an in-flight edit is never
       clobbered — querySelectorAll returns rows in document (display) order. */
    function captureRows() {
        const sels  = rowsEl.querySelectorAll('select[name="ext_link_type_ids[]"]');
        const urls  = rowsEl.querySelectorAll('input[name="ext_link_urls[]"]');
        const notes = rowsEl.querySelectorAll('input[name="ext_link_notes[]"]');
        const vers  = rowsEl.querySelectorAll('input[name="ext_link_verified[]"]');
        const rows = [];
        for (let i = 0; i < sels.length; i++) {
            rows.push({
                typeId:   parseInt(sels[i].value, 10) || 0,
                url:      ((urls[i] && urls[i].value) || '').trim(),
                note:     ((notes[i] && notes[i].value) || '').trim(),
                verified: (vers[i] && vers[i].checked) ? 1 : 0,
            });
        }
        return rows;
    }

    let saveTimer = null;
    async function save() {
        try {
            const res = await api.saveLinks(songId, captureRows());
            /* Adopt the canonical persisted rows (no subscriber re-renders this
               tab — the DOM is authoritative while mounted — so this is safe
               bookkeeping that keeps the slice fresh for re-mount/preview). */
            store.set('links', res.links || []);
        } catch (e) {
            toast('Could not save links: ' + e.message, 'danger');
        }
    }
    function debouncedSave() {
        if (saveTimer) { clearTimeout(saveTimer); }
        saveTimer = setTimeout(save, SAVE_DEBOUNCE_MS);
    }

    /* input/change bubble up from every field (incl. the auto-detect's
       programmatic select change). A remove click bubbles AFTER the shared
       module's own handler has removed the card, so the capture sees the
       remaining rows. */
    function onEdit() { debouncedSave(); }
    function onClick(e) {
        if (e.target.closest('[data-action=remove-ext-link]')) { debouncedSave(); }
    }
    rowsEl.addEventListener('input', onEdit);
    rowsEl.addEventListener('change', onEdit);
    rowsEl.addEventListener('click', onClick);

    return function teardown() {
        if (saveTimer) { clearTimeout(saveTimer); }
        rowsEl.removeEventListener('input', onEdit);
        rowsEl.removeEventListener('change', onEdit);
        rowsEl.removeEventListener('click', onClick);
        container.innerHTML = '';
    };
}
