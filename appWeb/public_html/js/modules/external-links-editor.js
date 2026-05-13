/**
 * iHymns — Shared External-Links card-list editor
 *
 * Single source of truth for the per-row card editor that every admin
 * surface uses to attach external links to a parent row (songbook, work,
 * song, …). Before this module, songbooks.php and works.php carried
 * ~100 near-identical lines each — diverging in trivial ways and
 * skipping the auto-detect wiring on rows added post-load. Now both
 * pages (and the song editor app) call `iHymnsExtLinksEditor.mount()`
 * and the markup, behaviours, and auto-detect wiring are all defined
 * here.
 *
 * Contract:
 *   const editor = window.iHymnsExtLinksEditor.mount({
 *       rowsEl,                       // <div> container that holds rows
 *       addBtnEl,                     // <button> that adds a fresh row
 *       types = window._iHymnsLinkTypes ?? [],
 *       noteMaxLen = 255,
 *       urlMaxLen  = 2048,
 *       notePlaceholder = 'Optional note',
 *   });
 *
 *   editor.addRow({ typeId, url, note, verified })   → HTMLElement
 *   editor.setRows([{ typeId, url, … }, …])          → clears + repopulates
 *   editor.clear()
 *
 * Field naming stays the canonical `ext_link_type_ids[]`,
 * `ext_link_urls[]`, `ext_link_notes[]`, `ext_link_verified[]` so the
 * server-side `saveExternalLinksForRow()` helper handles every surface
 * the same way.
 */

(function () {
    'use strict';

    /* Category label/order map — same one previously duplicated across
       songbooks.php and works.php. Kept in this module so a new category
       in the DB needs adding here once, not in every consumer. */
    var CAT_LABELS = {
        'official':    'Official',
        'information': 'Information',
        'read':        'Read',
        'sheet-music': 'Sheet music',
        'listen':      'Listen',
        'watch':       'Watch',
        'purchase':    'Purchase',
        'authority':   'Authority',
        'social':      'Social',
        'other':       'Other',
    };
    var CAT_ORDER = [
        'official', 'information', 'read', 'sheet-music',
        'listen', 'watch', 'purchase', 'authority', 'social', 'other',
    ];

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function groupByCategory(types) {
        var byCat = {};
        for (var i = 0; i < types.length; i++) {
            var t   = types[i] || {};
            var cat = t.category || 'other';
            if (!byCat[cat]) byCat[cat] = [];
            byCat[cat].push(t);
        }
        return byCat;
    }

    function buildSelectHtml(types, selectedId) {
        var byCat = groupByCategory(types);
        var html  = '<select class="form-select form-select-sm" name="ext_link_type_ids[]" required>';
        html += '<option value="">— pick a link type —</option>';
        for (var ci = 0; ci < CAT_ORDER.length; ci++) {
            var cat = CAT_ORDER[ci];
            if (!byCat[cat] || byCat[cat].length === 0) continue;
            html += '<optgroup label="' + escapeHtml(CAT_LABELS[cat] || cat) + '">';
            for (var ti = 0; ti < byCat[cat].length; ti++) {
                var t = byCat[cat][ti];
                var sel = (Number(selectedId) === Number(t.id)) ? ' selected' : '';
                html += '<option value="' + Number(t.id) + '"' + sel + '>'
                     +    escapeHtml(t.name)
                     +  '</option>';
            }
            html += '</optgroup>';
        }
        /* If the DB has any category not in CAT_ORDER, dump it under a
           catch-all "Other (uncategorised)" group so a curator can still
           reach it without an explicit module update. */
        var leftoverCats = Object.keys(byCat).filter(function (c) { return CAT_ORDER.indexOf(c) === -1; });
        if (leftoverCats.length) {
            html += '<optgroup label="Other (uncategorised)">';
            for (var li = 0; li < leftoverCats.length; li++) {
                var items = byCat[leftoverCats[li]];
                for (var lj = 0; lj < items.length; lj++) {
                    var t2 = items[lj];
                    var sel2 = (Number(selectedId) === Number(t2.id)) ? ' selected' : '';
                    html += '<option value="' + Number(t2.id) + '"' + sel2 + '>'
                         +    escapeHtml(t2.name) + '</option>';
                }
            }
            html += '</optgroup>';
        }
        html += '</select>';
        return html;
    }

    function buildRowEl(types, data, opts) {
        var url  = String(data && data.url  || '');
        var note = String(data && data.note || '');
        var ver  = data && data.verified ? 'checked' : '';
        var notePh = opts.notePlaceholder || 'Optional note';
        var urlMax = Number(opts.urlMaxLen) || 2048;
        var noteMax = Number(opts.noteMaxLen) || 255;

        var card = document.createElement('div');
        card.className = 'card bg-dark border-secondary ihymns-ext-link-row';
        card.innerHTML =
            '<div class="card-body py-2">' +
              '<div class="d-flex align-items-start gap-2">' +
                '<i class="bi bi-grip-vertical text-muted mt-2" aria-hidden="true"></i>' +
                '<div class="flex-grow-1">' +
                  '<div class="row g-2 mb-1">' +
                    '<div class="col-md-5">' + buildSelectHtml(types, data && data.typeId || 0) + '</div>' +
                    '<div class="col-md-7">' +
                      '<input type="url" class="form-control form-control-sm" ' +
                              'name="ext_link_urls[]" required maxlength="' + urlMax + '" ' +
                              'placeholder="https://…" value="' + escapeHtml(url) + '">' +
                    '</div>' +
                  '</div>' +
                  '<div class="row g-2">' +
                    '<div class="col-md-9">' +
                      '<input type="text" class="form-control form-control-sm" ' +
                              'name="ext_link_notes[]" maxlength="' + noteMax + '" ' +
                              'placeholder="' + escapeHtml(notePh) + '" ' +
                              'value="' + escapeHtml(note) + '">' +
                    '</div>' +
                    '<div class="col-md-3 d-flex align-items-center">' +
                      '<div class="form-check small">' +
                        '<input class="form-check-input" type="checkbox" ' +
                                'name="ext_link_verified[]" value="1" ' + ver + '>' +
                        '<label class="form-check-label">Verified</label>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-danger" ' +
                        'data-action="remove-ext-link" title="Remove this link" aria-label="Remove this link">' +
                  '<i class="bi bi-x-lg" aria-hidden="true"></i>' +
                '</button>' +
              '</div>' +
            '</div>';

        card.querySelector('[data-action=remove-ext-link]').addEventListener('click', function () {
            card.remove();
        });

        /* Auto-detect provider from the pasted URL — the global
           iHymnsLinkDetect module (#841) wires the row's URL input +
           select together. Silently no-ops if the module didn't
           load for some reason. */
        if (window.iHymnsLinkDetect && typeof window.iHymnsLinkDetect.attachAutoDetect === 'function') {
            window.iHymnsLinkDetect.attachAutoDetect(card);
        }

        /* Duplicate-URL detection — if the curator pastes a URL that
           matches another row in the same container, the dupe-detect
           module fires a toast and auto-removes this just-added row.
           Loaded globally via admin-footer.php so a no-op fallback
           when the module isn't on the page keeps the editor working. */
        if (window.iHymnsExtLinkDupeDetect && typeof window.iHymnsExtLinkDupeDetect.attach === 'function') {
            window.iHymnsExtLinkDupeDetect.attach(card);
        }

        return card;
    }

    /**
     * Mount a card-list editor on the supplied container + add button.
     * Returns a small handle the caller can use to add / replace rows.
     */
    function mount(config) {
        var cfg = config || {};
        var rowsEl   = cfg.rowsEl;
        var addBtnEl = cfg.addBtnEl;
        if (!rowsEl) throw new Error('iHymnsExtLinksEditor.mount(): missing rowsEl');

        var types = Array.isArray(cfg.types) ? cfg.types
                   : (Array.isArray(window._iHymnsLinkTypes) ? window._iHymnsLinkTypes : []);

        var opts = {
            notePlaceholder: cfg.notePlaceholder || 'Optional note',
            urlMaxLen:       cfg.urlMaxLen,
            noteMaxLen:      cfg.noteMaxLen,
        };

        function addRow(data) {
            var card = buildRowEl(types, data || {}, opts);
            rowsEl.appendChild(card);
            return card;
        }
        function setRows(list) {
            rowsEl.innerHTML = '';
            (Array.isArray(list) ? list : []).forEach(function (r) { addRow(r); });
        }
        function clear() { rowsEl.innerHTML = ''; }

        if (addBtnEl && !addBtnEl.dataset.ihymnsExtLinksBound) {
            addBtnEl.addEventListener('click', function () {
                addRow({ typeId: 0, url: '', note: '', verified: false });
            });
            addBtnEl.dataset.ihymnsExtLinksBound = '1';
        }

        return { addRow: addRow, setRows: setRows, clear: clear };
    }

    window.iHymnsExtLinksEditor = { mount: mount };
})();
