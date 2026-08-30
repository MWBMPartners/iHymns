/* ==========================================================================
 *  reflow-modal.js — the v2 Paste & Reflow MODAL (#1200, Phase 4)
 *
 *  Curators paste raw lyrics, click Parse, tweak the auto-detected sections, and
 *  Apply them to the song in one atomic write (api2.php components_replace).
 *  Uses the pure parser in reflow.js. The modal is built in JS + driven by the
 *  Bootstrap bundle already loaded by editor2.php. Apply offers Replace (wipe +
 *  set, the common "paste a whole song" case) or Append (add to existing).
 *
 *  mountReflowModal(triggerBtn, { store, api, songId, toast }) -> teardown fn
 * ========================================================================== */

import { reflowParseText, REFLOW_TYPES } from './reflow.js';

export function mountReflowModal(triggerBtn, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};

    let blocks = [];   /* [{ type, number, lines:string[] }] — live, editable */

    /* ---- modal scaffold (built once) ---- */
    const modalEl = document.createElement('div');
    modalEl.className = 'modal fade';
    modalEl.tabIndex = -1;
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.innerHTML =
        '<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
          '<div class="modal-content">' +
            '<div class="modal-header">' +
              '<h2 class="modal-title h5"><i class="bi bi-text-paragraph me-2" aria-hidden="true"></i>Paste &amp; Reflow</h2>' +
              '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body">' +
              '<div class="alert alert-info py-2 small">' +
                '<strong>Separate each section with a blank line.</strong> A leading ' +
                '"Verse 2" / "Chorus" line is detected as a label; repeated blocks become the chorus; ' +
                'sections are auto-numbered. You can fix anything below before applying.' +
              '</div>' +
              '<textarea class="form-control mb-2" rows="8" data-reflow="input" ' +
                'placeholder="Paste lyrics here, one blank line between sections…"></textarea>' +
              '<div class="d-flex align-items-center gap-2 mb-3">' +
                '<button type="button" class="btn btn-sm btn-primary" data-reflow="parse"><i class="bi bi-magic me-1" aria-hidden="true"></i>Parse into sections</button>' +
                '<span class="text-muted small" data-reflow="count"></span>' +
              '</div>' +
              '<div data-reflow="blocks"></div>' +
            '</div>' +
            '<div class="modal-footer justify-content-between flex-wrap gap-2">' +
              '<div class="btn-group btn-group-sm" role="group" aria-label="Apply mode">' +
                '<input type="radio" class="btn-check" name="reflow-mode" id="reflow-mode-replace" value="replace" checked>' +
                '<label class="btn btn-outline-secondary" for="reflow-mode-replace">Replace all sections</label>' +
                '<input type="radio" class="btn-check" name="reflow-mode" id="reflow-mode-append" value="append">' +
                '<label class="btn btn-outline-secondary" for="reflow-mode-append">Append</label>' +
              '</div>' +
              '<div class="d-flex gap-2">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' +
                '<button type="button" class="btn btn-sm btn-success" data-reflow="apply" disabled><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Apply</button>' +
              '</div>' +
            '</div>' +
          '</div>' +
        '</div>';
    document.body.appendChild(modalEl);

    const inputEl  = modalEl.querySelector('[data-reflow="input"]');
    const parseBtn = modalEl.querySelector('[data-reflow="parse"]');
    const countEl  = modalEl.querySelector('[data-reflow="count"]');
    const blocksEl = modalEl.querySelector('[data-reflow="blocks"]');
    const applyBtn = modalEl.querySelector('[data-reflow="apply"]');

    const bsModal = (window.bootstrap && window.bootstrap.Modal)
        ? new window.bootstrap.Modal(modalEl)
        : null;

    /* ---- render the editable preview cards ---- */
    function renderBlocks() {
        blocksEl.innerHTML = '';
        countEl.textContent = blocks.length
            ? (blocks.length + ' section' + (blocks.length === 1 ? '' : 's') + ' detected')
            : '';
        applyBtn.disabled = blocks.length === 0;

        if (!blocks.length) {
            blocksEl.innerHTML = '<p class="text-muted small mb-0">Paste lyrics above and click <strong>Parse into sections</strong>.</p>';
            return;
        }

        blocks.forEach((block, index) => {
            const card = document.createElement('div');
            card.className = 'card mb-2';

            const header = document.createElement('div');
            header.className = 'card-header d-flex align-items-center gap-2 py-1';

            const typeSel = document.createElement('select');
            typeSel.className = 'form-select form-select-sm';
            typeSel.style.width = '150px';
            REFLOW_TYPES.forEach((t) => {
                const o = document.createElement('option');
                o.value = t;
                o.textContent = t.replace(/^\w/, (c) => c.toUpperCase());
                if (t === block.type) { o.selected = true; }
                typeSel.appendChild(o);
            });
            typeSel.addEventListener('change', () => { block.type = typeSel.value; });

            const numInput = document.createElement('input');
            numInput.type = 'number';
            numInput.min = '1';
            numInput.className = 'form-control form-control-sm';
            numInput.style.width = '80px';
            numInput.placeholder = '#';
            numInput.value = block.number != null ? String(block.number) : '';
            numInput.addEventListener('change', () => { block.number = numInput.value ? parseInt(numInput.value, 10) : null; });

            const spacer = document.createElement('span');
            spacer.className = 'me-auto';

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-sm btn-outline-danger';
            del.title = 'Remove section';
            del.setAttribute('aria-label', 'Remove section');
            del.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            del.addEventListener('click', () => { blocks.splice(index, 1); renderBlocks(); });

            header.append(typeSel, numInput, spacer, del);

            const ta = document.createElement('textarea');
            ta.className = 'form-control border-0';
            ta.rows = Math.max(2, block.lines.length);
            ta.value = block.lines.join('\n');
            ta.addEventListener('input', () => { block.lines = ta.value.split('\n'); });

            card.append(header, ta);
            blocksEl.appendChild(card);
        });
    }

    function parse() {
        blocks = reflowParseText(inputEl.value);
        renderBlocks();
    }

    async function apply() {
        /* Drop any section whose lines are entirely blank (matches legacy).
           Omit sortOrder so the server assigns it (base + index): base 0 in
           replace mode, MAX(SortOrder)+1 in append mode — otherwise a fixed
           0,1,2… would collide with existing rows when appending. */
        const payload = blocks
            .filter((b) => b.lines.join('').trim() !== '')
            .map((b) => ({ type: b.type || 'verse', number: b.number || 0, lines: b.lines }));
        if (!payload.length) { toast('Nothing to apply — parse some lyrics first.', 'danger'); return; }

        const mode = (modalEl.querySelector('input[name="reflow-mode"]:checked') || {}).value || 'replace';
        applyBtn.disabled = true;
        try {
            const res = await api.replaceComponents(songId, payload, mode);
            const comps = (res.components || []).map((c, i) =>
                Object.assign({ _key: 'c' + i + '_' + (c.id || 'x') }, c));
            store.set('components', comps);   // re-renders Structure + Preview
            if (bsModal) { bsModal.hide(); }
            inputEl.value = '';
            blocks = [];
            renderBlocks();
            toast('Applied ' + comps.length + ' section' + (comps.length === 1 ? '' : 's') + '.', 'success');
        } catch (e) {
            applyBtn.disabled = false;
            toast('Could not apply reflow: ' + e.message, 'danger');
        }
    }

    parseBtn.addEventListener('click', parse);
    applyBtn.addEventListener('click', apply);
    function openModal() { if (bsModal) { bsModal.show(); } }
    triggerBtn.addEventListener('click', openModal);
    renderBlocks();

    return function teardown() {
        triggerBtn.removeEventListener('click', openModal);
        if (bsModal) { bsModal.dispose(); }
        modalEl.remove();
    };
}
