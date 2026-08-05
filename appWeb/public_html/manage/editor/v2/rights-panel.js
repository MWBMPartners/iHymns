/* ==========================================================================
 *  rights-panel.js — the v2 Song Editor "Rights" panel (#1769 P4)
 *
 *  Two pickers on the Metadata tab: the licence a song's LYRICS are covered by
 *  (tblSongs.LyricsRightsLicenceKey) and the one its MUSIC is covered by
 *  (tblSongs.MusicRightsLicenceKey). Both are per-song RIGHTS FACTS — dormant
 *  until #1769 P6; nothing enforces on them yet. This panel is only the write
 *  path a curator uses to RECORD them.
 *
 *  mountRightsPanel(container, { songId, store, api, toast }) -> teardown fn
 *
 *  DESIGN NOTES
 *  ------------
 *   - DOM-first / data-driven, no hand-typed vocabulary: the picker options come
 *     from `window._iHymnsLicenceTypes` (editor2.php emits it from the SAME
 *     licence_registry the server validates against — rule #35, no second list).
 *   - Un-migrated detection is a ZERO-EXTRA-REQUEST client gate (the metadata-tab
 *     GATED_COLUMNS precedent): load_song's `song` slice is a `SELECT *`, so on an
 *     install that has not run the gating-facts card the two columns are simply
 *     absent as keys. When absent, the panel renders a calm disabled note rather
 *     than a control that would 409.
 *   - Failure KINDS are read off `err.status` (attached by api-client.js's
 *     unwrap()), never by regex-matching the server's sentence (rule #35): 409 =
 *     the install regressed to un-migrated between load and save; 422 = the key
 *     is not in the live registry.
 *   - Songbook default is a PREFILL HINT the curator may adopt with one click
 *     (P4 D4) — NEVER an automatic write. It shows only while the song's own
 *     value for that side is still empty.
 * ========================================================================== */

/** The two rights sides, in render order. `field` is the api2 ED2_META_FIELDS
 *  key; `column` is the tblSongs column (used only for the un-migrated probe +
 *  reading the current value off the `song` slice); `defaultKey` indexes the
 *  songbookRightsDefaults hint object. */
const SIDES = [
    { field: 'lyricsRightsLicenceKey', column: 'LyricsRightsLicenceKey', label: 'Lyrics rights',  defaultKey: 'lyrics' },
    { field: 'musicRightsLicenceKey',  column: 'MusicRightsLicenceKey',  label: 'Music rights',   defaultKey: 'music'  },
];

export function mountRightsPanel(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    let disposed = false;

    /* The licence vocabulary, as a classic global registry map emitted by
       editor2.php (key -> {label, description}). Empty object if, somehow, it
       did not load — the pickers then offer only "— none —", which is still a
       valid (clearing) choice rather than a broken control. */
    const vocab = (window._iHymnsLicenceTypes && typeof window._iHymnsLicenceTypes === 'object')
        ? window._iHymnsLicenceTypes : {};

    const wrap = document.createElement('div');
    wrap.className = 'border rounded p-3 mt-3';
    wrap.setAttribute('data-panel', 'rights');

    function render() {
        if (disposed) { return; }
        const song = store.get('song') || {};
        const defaults = store.get('songbookRightsDefaults') || null;
        wrap.innerHTML = '';

        const h = document.createElement('h6');
        h.className = 'mb-1';
        h.textContent = 'Rights';
        wrap.appendChild(h);

        const sub = document.createElement('p');
        sub.className = 'form-text small mt-0 mb-3';
        sub.textContent = 'The licence each part of this song is covered by. Recorded now; it does not gate anything yet.';
        wrap.appendChild(sub);

        /* Un-migrated: the columns are absent from the SELECT * `song` slice.
           Render a calm disabled note (never a 409-ing control). */
        const migrated = SIDES.some((s) => (s.column in song));
        if (!migrated) {
            const note = document.createElement('div');
            note.className = 'alert alert-secondary py-2 small mb-0';
            note.textContent = 'Rights fields are not available on this install yet — run the “gating facts” card on /manage/setup-database.';
            wrap.appendChild(note);
            return;
        }

        const row = document.createElement('div');
        row.className = 'row g-3';

        SIDES.forEach((side) => {
            const col = document.createElement('div');
            col.className = 'col-12 col-md-6';

            const lab = document.createElement('label');
            lab.className = 'form-label small mb-1';
            lab.htmlFor = 'meta-' + side.field;
            lab.textContent = side.label;

            const sel = document.createElement('select');
            sel.className = 'form-select form-select-sm';
            sel.id = 'meta-' + side.field;

            const current = song[side.column] != null ? String(song[side.column]) : '';

            const none = document.createElement('option');
            none.value = '';
            none.textContent = '— none —';
            sel.appendChild(none);
            Object.keys(vocab).forEach((key) => {
                const o = document.createElement('option');
                o.value = key;
                o.textContent = (vocab[key] && vocab[key].label) ? vocab[key].label : key;
                sel.appendChild(o);
            });
            /* If the stored value is a key no longer in the registry (a disabled
               or deleted licence), keep it selectable so the control shows the
               truth rather than silently snapping to "none". */
            if (current !== '' && !Object.prototype.hasOwnProperty.call(vocab, current)) {
                const o = document.createElement('option');
                o.value = current;
                o.textContent = current + ' (not in registry)';
                sel.appendChild(o);
            }
            sel.value = current;

            sel.addEventListener('change', () => {
                const val = sel.value;
                api.updateMetadata(songId, side.field, val).then(() => {
                    song[side.column] = val === '' ? null : val;
                    render();   // refresh the hint (adopting a default clears it)
                }).catch((e) => {
                    if (e && e.status === 409) {
                        toast(side.label + ': this install has not applied the gating-facts migration yet.', 'danger');
                    } else if (e && e.status === 422) {
                        toast(side.label + ': ' + e.message, 'danger');
                    } else {
                        toast('Could not save ' + side.label.toLowerCase() + ': ' + (e ? e.message : 'unknown error'), 'danger');
                    }
                    sel.value = current;   // never leave the control claiming a save that failed
                });
            });

            col.append(lab, sel);

            /* Songbook default HINT (D4): shown only while this side is still
               empty; a one-click adopt that writes THIS song only (never a bulk
               or automatic write). */
            const defKey = defaults ? defaults[side.defaultKey] : null;
            if (current === '' && defKey) {
                const hint = document.createElement('div');
                hint.className = 'form-text small mt-1';
                const defLabel = (vocab[defKey] && vocab[defKey].label) ? vocab[defKey].label : defKey;
                const txt = document.createElement('span');
                txt.textContent = 'Songbook default: ' + defLabel + ' ';
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-link btn-sm p-0 align-baseline';
                btn.textContent = 'Use';
                btn.addEventListener('click', () => {
                    sel.value = defKey;
                    sel.dispatchEvent(new Event('change'));
                });
                hint.append(txt, btn);
                col.appendChild(hint);
            }

            row.appendChild(col);
        });

        wrap.appendChild(row);
    }

    /* Re-render when the song slice changes (a song switch) or when the songbook
       default hint arrives/updates. Both are separate subscriptions so either
       trigger refreshes the panel. */
    const offSong = store.subscribe('song', render);
    const offDefaults = store.subscribe('songbookRightsDefaults', render);
    container.appendChild(wrap);
    render();

    return function teardown() {
        disposed = true;
        offSong();
        offDefaults();
        if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
    };
}
