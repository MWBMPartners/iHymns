/**
 * iHymns — Shared Entity/Target Picker (#498)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * A reusable "name-first picker" for admin forms where the canonical
 * stored value is an internal id but the admin wants to see a human
 * label. Extracted from /manage/restrictions (#498) so Entitlements /
 * Organisations / any future surface can reuse the same UX without
 * copy-pasting 150 lines of inline JavaScript.
 *
 * Not an ES module — loaded via `<script src="…">` from admin pages
 * and exposes `window.initEntityPickers(formEl)` on the global. That
 * keeps the file usable from PHP-rendered forms that don't run
 * through the main SPA module graph.
 *
 * DOM CONTRACT:
 * Each "group" is a self-contained picker that has:
 *   <select data-picker-canonical="#my-hidden-id">        ← type select
 *     <option value="song">…</option>
 *     <option value="songbook">…</option>
 *   </select>
 *
 *   <input type="hidden" id="my-hidden-id" name="…">      ← canonical
 *
 *   <div data-picker-group-for="#my-hidden-id">           ← panel wrapper
 *     <div class="rx-picker" data-picker-for="song">…</div>
 *     <div class="rx-picker d-none" data-picker-for="songbook">…</div>
 *     …
 *   </div>
 *
 * Each `.rx-picker` panel either:
 *   * contains a `.rx-picker-select` whose `value` is the canonical
 *     id (for small-cardinality dropdowns), OR
 *   * contains a `.rx-picker-input` plus a sibling `.rx-picker-popover`
 *     (for live-search comboboxes). The input carries
 *     `data-picker-source="song|user|…"` selecting which backend
 *     endpoint to hit.
 *
 * The helper takes care of the rest: swapping panels when the type
 * select changes, syncing the visible picker's value into the hidden
 * canonical input, wiring debounced live-search, and a last-chance
 * sync on form submit so keyboard-only users can't POST an empty
 * canonical id.
 */

(function (global) {
    'use strict';

    /* Source name → endpoint URL. Keep this table in one place so new
       pickers just add an entry. The "user" source goes through the
       editor's admin-gated api.php because tblUsers isn't exposed on
       the public api.php; "song" uses the public search endpoint
       since songs aren't gated. */
    var PICKER_SOURCES = {
        song: {
            url: function (q) { return '/api?action=search&q=' + encodeURIComponent(q) + '&limit=15'; },
            extract: function (data) {
                return (data.results || []).map(function (s) {
                    return {
                        id:    s.id,
                        label: (s.title || s.id) + (s.songbook ? ' · ' + s.songbook : ''),
                        hint:  s.id,
                    };
                });
            },
        },
        user: {
            url: function (q) { return '/manage/editor/api?action=user_search&q=' + encodeURIComponent(q); },
            extract: function (data) { return data.suggestions || []; },
        },
        organisation: {
            url: function (q) { return '/manage/editor/api?action=org_search&q=' + encodeURIComponent(q); },
            extract: function (data) { return data.suggestions || []; },
        },
    };

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    /**
     * Auto-discover every picker group inside `form` and wire it up.
     *
     * @param {HTMLFormElement} form The form containing picker groups
     * @returns {function} Teardown (no-op today, reserved for SPA use)
     */
    function initEntityPickers(form) {
        if (!form) return function () {};

        var groups = [];

        form.querySelectorAll('select[data-picker-canonical]').forEach(function (typeSelect) {
            var canonicalSel = typeSelect.dataset.pickerCanonical;
            var hiddenInput  = form.querySelector(canonicalSel);
            if (!hiddenInput) return;
            var panel = form.querySelector('[data-picker-group-for="' + canonicalSel + '"]');
            if (!panel) return;
            groups.push({ typeSelect: typeSelect, hiddenInput: hiddenInput, panel: panel });
        });

        if (groups.length === 0) return function () {};

        groups.forEach(wireGroup);

        /* Click outside any popover dismisses them — one document
           listener covers every group in this form. */
        var onDocClick = function (e) {
            form.querySelectorAll('.rx-picker-popover:not(.d-none)').forEach(function (p) {
                if (!p.contains(e.target) && e.target !== p.previousElementSibling) {
                    p.classList.add('d-none');
                }
            });
        };
        document.addEventListener('click', onDocClick);

        /* Last-chance sync on submit. */
        var onSubmit = function () {
            groups.forEach(function (g) { syncHiddenFromPanel(g.panel, g.hiddenInput); });
        };
        form.addEventListener('submit', onSubmit);

        /* Teardown — useful if this ever gets called from an SPA
           router that reuses DOM fragments. Present-day admin pages
           are full-page loads and will GC the listeners naturally. */
        return function () {
            document.removeEventListener('click', onDocClick);
            form.removeEventListener('submit', onSubmit);
        };
    }

    /**
     * Show the panel matching `key`, hide siblings. The type select's
     * `value` drives this.
     */
    function swapPanel(panel, key) {
        panel.querySelectorAll('.rx-picker').forEach(function (p) {
            p.classList.toggle('d-none', p.dataset.pickerFor !== key);
        });
    }

    /**
     * Sync the visible panel's chosen value into `hiddenInput`.
     * Priority: dedicated canonical on the input > select.value >
     * input.value > empty.
     */
    function syncHiddenFromPanel(panel, hiddenInput) {
        var visible = panel.querySelector('.rx-picker:not(.d-none)');
        if (!visible) { hiddenInput.value = ''; return; }
        var sel = visible.querySelector('.rx-picker-select');
        var inp = visible.querySelector('.rx-picker-input');
        if (sel) { hiddenInput.value = sel.value || ''; return; }
        if (inp) {
            hiddenInput.value = inp.dataset.canonical || inp.value || '';
            return;
        }
        hiddenInput.value = '';
    }

    function wireGroup(group) {
        var typeSelect  = group.typeSelect;
        var hiddenInput = group.hiddenInput;
        var panel       = group.panel;

        typeSelect.addEventListener('change', function () {
            swapPanel(panel, typeSelect.value);
            syncHiddenFromPanel(panel, hiddenInput);
        });

        /* Select-based sub-pickers sync their value on change. */
        panel.querySelectorAll('.rx-picker-select').forEach(function (sel) {
            sel.addEventListener('change', function () {
                syncHiddenFromPanel(panel, hiddenInput);
            });
        });

        /* Live-search combobox sub-pickers. */
        panel.querySelectorAll('.rx-picker-input').forEach(function (input) {
            wireComboboxInput(input, panel, hiddenInput);
        });

        /* Initialise visible panel + hidden value on load. */
        swapPanel(panel, typeSelect.value);
        syncHiddenFromPanel(panel, hiddenInput);
    }

    function wireComboboxInput(input, panel, hiddenInput) {
        var popover = input.nextElementSibling;
        var source  = input.dataset.pickerSource;
        var debounce = null;

        if (!popover || !popover.classList.contains('rx-picker-popover')) return;

        function close() { popover.classList.add('d-none'); popover.innerHTML = ''; }

        function renderItems(items) {
            popover.innerHTML = '';
            if (!items.length) { close(); return; }
            items.forEach(function (it) {
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                row.innerHTML = '<span>' + escapeHtml(it.label) + '</span>' +
                    (it.hint ? '<small class="text-muted">' + escapeHtml(it.hint) + '</small>' : '');
                row.addEventListener('click', function () {
                    input.value = it.label;
                    input.dataset.canonical = String(it.id);
                    hiddenInput.value = String(it.id);
                    close();
                });
                popover.appendChild(row);
            });
            popover.classList.remove('d-none');
        }

        function fetchSuggestions(q) {
            var cfg = PICKER_SOURCES[source];
            if (!cfg) return;
            fetch(cfg.url(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) { renderItems(cfg.extract(data)); })
                .catch(close);
        }

        input.addEventListener('input', function () {
            clearTimeout(debounce);
            var q = input.value.trim();
            /* '*' is the canonical match-all sentinel — accepted in
               the song combobox (see restrictions.php). Skip the
               suggestion fetch and stage the id directly. */
            if (q === '*') {
                input.dataset.canonical = '*';
                hiddenInput.value = '*';
                close();
                return;
            }
            /* Editing the text drops any staged canonical id so the
               admin can't accidentally submit "Amazing Grace" mapped
               to a different canonical id. */
            delete input.dataset.canonical;
            hiddenInput.value = '';
            if (q.length < 1) { close(); return; }
            debounce = setTimeout(function () { fetchSuggestions(q); }, 200);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    }

    /* Expose on the global for PHP-rendered admin pages to call. */
    global.initEntityPickers = initEntityPickers;
})(window);
