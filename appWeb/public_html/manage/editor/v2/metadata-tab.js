/* ==========================================================================
 *  metadata-tab.js — the v2 Song Editor "Metadata" tab (#1200, Phase 2)
 *
 *  The song's scalar fields. Each input saves ITS OWN field atomically via the
 *  granular `metadata_field_update` endpoint (debounced for text, immediate for
 *  selects/checkboxes) — no whole-song save. A failed save surfaces a real error
 *  and the field keeps the typed value for retry.
 *
 *  mountMetadataTab(container, { store, api, songId, toast, onSongIdChange,
 *                                getSongbooks }) -> teardown fn
 *  Reads initial values from the store's `song` slice (the tblSongs row, as
 *  returned by api2.php load_song — note the columns are PascalCase).
 * ========================================================================== */

const SAVE_DEBOUNCE_MS = 500;

/* field key (api2 ED2_META_FIELDS) -> [label, tblSongs column, input kind]
 *
 * Input kinds: 'text' | 'number' (debounced on `input`), 'check' (immediate on
 * `change`), 'select' (immediate on `change`, options supplied by the caller).
 *
 * #1679 H1 — `songbook` is a SELECT, and deliberately not a text box. Since the
 * move became a re-key, writing this field mints a NEW SongId, clears Number,
 * cascades ~41 child tables and writes a permanent tblSongRedirects row. As a
 * debounced text input that irreversible action fired on a KEYSTROKE PAUSE: a
 * curator clearing the field and typing `C`, `P` who paused half a second after
 * the `C` moved the song into songbook `C`, and moving it back restored neither
 * the id nor the number. A closed list removes the typo entirely, `change`
 * removes the timer, and the confirm below removes the surprise. v1 has always
 * validated its move target against the loaded songbook list (editor.js's bulk
 * move) — this is the same rule, expressed as a control the user cannot get
 * wrong rather than as a check after the fact. */
const FIELDS = [
    ['title',              'Song Title',        'Title',              'text'],
    ['number',             'Song Number',       'Number',             'number'],
    ['songbook',           'Songbook',          'SongbookAbbr',       'select'],
    ['language',           'Language (BCP 47)', 'Language',           'text'],
    ['ccli',               'CCLI Number',       'Ccli',               'text'],
    ['iswc',               'ISWC',              'Iswc',               'text'],
    ['tuneName',           'Tune Name',         'TuneName',           'text'],
    ['copyright',          'Copyright',         'Copyright',          'text'],
    ['verified',           'Verified',          'Verified',           'check'],
    ['lyricsPublicDomain', 'Lyrics Public Domain', 'LyricsPublicDomain', 'check'],
    ['musicPublicDomain',  'Music Public Domain',  'MusicPublicDomain',  'check'],
    ['hasAudio',           'Has audio',         'HasAudio',           'check'],
    ['hasSheetMusic',      'Has sheet music',   'HasSheetMusic',      'check'],
];

export function mountMetadataTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    /* #1679 — the shell's "this song now has a different id" handler. A plain
       injected callback rather than a DOM event, so there is no event-name
       literal to keep in sync with anything (rule #35 / #1581). Defaults to a
       no-op so the tab still mounts standalone in a test harness. */
    const onSongIdChange = opts.onSongIdChange || function () {};
    /* #1679 H1 — the songbook options, injected the same way. The shell passes
       sidebar.getSongbooks(), which derives the distinct books from the loaded
       slim index — the SAME list the New-song modal offers, so there is one
       implementation of "which songbooks exist" and no new endpoint (its known
       limit is that a book with zero songs is not listed; that is inherited from
       the index, not introduced here). Defaults to an empty list so the tab
       still mounts standalone. */
    const getSongbooks = opts.getSongbooks || function () { return []; };
    const timers = new Map();
    let placeDetach = null;   // teardown for the geocoder attached to the origin picker

    /**
     * @param {string}   field
     * @param {*}        value
     * @param {Function} [onError]  called after the failure toast, so a control
     *                              that cannot be left showing a value the server
     *                              rejected (the songbook select) can revert.
     */
    function save(field, value, onError) {
        api.updateMetadata(songId, field, value).then((res) => {
            /* #1679 — changing the songbook RE-KEYS the SongId server-side
               (`tblSongbooks.Abbreviation` IS the id prefix, rule #27). Every id
               this tab captured at mount — and the shell's ?song= URL, the
               sidebar row, the other tabs — now points at a dead id, so hand the
               new one back to the shell, which re-opens the song under it.
               Compared by VALUE, not by field name: the server tells us whether
               a rename actually happened, so a no-op move (same book) doesn't
               churn the UI. */
            if (res && res.previousId && res.songId && res.songId !== res.previousId) {
                /* Guarded so a fault in the shell's re-open cannot fall through to
                   the .catch() below and toast "could not save" about a save that
                   actually succeeded — a misleading error is worse than none. */
                try { onSongIdChange(res.previousId, res.songId); } catch (_e) { /* shell owns its own errors */ }
            }
        }).catch((e) => {
            toast('Could not save ' + field + ': ' + e.message, 'danger');
            if (typeof onError === 'function') { try { onError(e); } catch (_e) {} }
        });
    }
    function debouncedSave(field, value) {
        if (timers.has(field)) { clearTimeout(timers.get(field)); }
        timers.set(field, setTimeout(() => save(field, value), SAVE_DEBOUNCE_MS));
    }

    /**
     * The songbook control (#1679 H1) — a closed list, saved immediately on
     * `change`, behind a confirm that spells out what a move actually does.
     *
     * ELI5: pick the book the song belongs in. It is a proper move, not a label
     * change, so we ask first — the song gets a new id and loses its number.
     *
     * Detail, in the order it matters:
     *  - OPTIONS come from the loaded index (see getSongbooks above). The song's
     *    CURRENT book is prepended when the list does not contain it, so the
     *    control always shows the truth even if the index is still loading or
     *    the book has only this one song.
     *  - `change`, never `input` + debouncedSave. A debounce timer turns an
     *    irreversible re-key into something that happens while you are still
     *    deciding; on a <select> there is no partial state to debounce anyway.
     *  - CONFIRM before the request, not a toast after it. The three
     *    consequences are stated plainly because none of them is guessable from
     *    the words "songbook": a new SongId, a cleared Number, and the old id
     *    demoted to a redirect. Moving back does not undo any of them.
     *    window.confirm matches what the shell already uses for Delete — the one
     *    other irreversible action in this editor — rather than introducing a
     *    second confirmation idiom.
     *  - On CANCEL or on a server error the select snaps back to the book the
     *    song is actually in, so the control never sits there claiming a move
     *    that did not happen.
     */
    function renderSongbookSelect(song, field, label, column) {
        const wrap = document.createElement('div');
        const lab = document.createElement('label');
        lab.className = 'form-label small mb-1';
        lab.htmlFor = 'meta-' + field;
        lab.textContent = label;

        const current = song[column] != null ? String(song[column]) : '';
        const books = (getSongbooks() || []).slice();
        if (current !== '' && !books.some((b) => b.abbr === current)) {
            books.unshift({ abbr: current, name: current });
        }

        const sel = document.createElement('select');
        sel.className = 'form-select form-select-sm';
        sel.id = 'meta-' + field;
        books.forEach((b) => {
            const o = document.createElement('option');
            o.value = b.abbr;
            o.textContent = (b.name && b.name !== b.abbr) ? (b.name + ' (' + b.abbr + ')') : b.abbr;
            sel.appendChild(o);
        });
        sel.value = current;

        const help = document.createElement('div');
        help.className = 'form-text small';
        help.textContent = 'Moving a song re-keys its id and clears its number.';

        sel.addEventListener('change', () => {
            const target = sel.value;
            if (target === current) { return; }
            const confirmed = window.confirm(
                'Move this song to songbook "' + target + '"?\n\n'
                + 'This is a move, not a label change:\n'
                + '  • the song gets a NEW id (' + (current || '?') + '-… becomes ' + target + '-…)\n'
                + '  • its song number is cleared\n'
                + '  • the old id becomes a permanent redirect to the new one\n\n'
                + 'Moving it back later will NOT restore the old id or number.'
            );
            if (!confirmed) { sel.value = current; return; }
            /* Immediate — no debouncedSave. The store is NOT updated optimistically:
               a successful move makes the shell re-open the song under its new id,
               which re-hydrates the whole slice from the server. */
            save(field, target, () => { sel.value = current; });
        });

        wrap.append(lab, sel, help);
        return wrap;
    }

    function render() {
        const song = store.get('song') || {};
        if (placeDetach) { try { placeDetach(); } catch (_e) {} placeDetach = null; }
        container.innerHTML = '';
        const row = document.createElement('div');
        row.className = 'row g-3';

        FIELDS.forEach(([field, label, column, kind]) => {
            const col = document.createElement('div');
            col.className = kind === 'check' ? 'col-12 col-sm-6 col-md-4' : 'col-12 col-md-6';

            if (kind === 'check') {
                const wrap = document.createElement('div');
                wrap.className = 'form-check';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.id = 'meta-' + field;
                input.checked = !!Number(song[column]);
                input.addEventListener('change', () => {
                    song[column] = input.checked ? 1 : 0;
                    save(field, input.checked ? 1 : 0);   // immediate
                });
                const lab = document.createElement('label');
                lab.className = 'form-check-label';
                lab.htmlFor = input.id;
                lab.textContent = label;
                wrap.append(input, lab);
                col.appendChild(wrap);
            } else if (kind === 'select') {
                col.appendChild(renderSongbookSelect(song, field, label, column));
            } else {
                const lab = document.createElement('label');
                lab.className = 'form-label small mb-1';
                lab.textContent = label;
                lab.htmlFor = 'meta-' + field;
                const input = document.createElement('input');
                input.type = kind === 'number' ? 'number' : 'text';
                input.className = 'form-control form-control-sm';
                input.id = 'meta-' + field;
                input.value = song[column] != null ? String(song[column]) : '';
                input.addEventListener('input', () => {
                    const val = kind === 'number' ? (parseInt(input.value, 10) || '') : input.value;
                    song[column] = val;
                    debouncedSave(field, val);
                });
                col.append(lab, input);
            }
            row.appendChild(col);
        });

        /* Composition origin — a geocoded place picker (visible text + hidden id),
           reusing the shared window.iHymnsPlaceSearch (places-api.php). Free-typing
           saves OriginCity; picking a place also saves the OriginCityId FK. */
        const pcol = document.createElement('div');
        pcol.className = 'col-12 col-md-6';
        const plab = document.createElement('label');
        plab.className = 'form-label small mb-1';
        plab.htmlFor = 'meta-originCity';
        plab.textContent = 'Composition origin';
        const pinput = document.createElement('input');
        pinput.type = 'text';
        pinput.className = 'form-control form-control-sm';
        pinput.id = 'meta-originCity';
        pinput.autocomplete = 'off';
        pinput.placeholder = 'City / place…';
        pinput.value = song.OriginCity != null ? String(song.OriginCity) : '';
        const phidden = document.createElement('input');
        phidden.type = 'hidden';
        phidden.value = song.OriginCityId != null ? String(song.OriginCityId) : '';
        pinput.addEventListener('input', () => {
            song.OriginCity = pinput.value;
            debouncedSave('originCity', pinput.value);   // free typing
        });
        phidden.addEventListener('change', () => {
            const n = parseInt(phidden.value, 10);
            const id = (phidden.value !== '' && !isNaN(n)) ? n : null;   // int or null, never ''
            song.OriginCityId = id;
            save('originCityId', id);            // a pick set the FK (or a clear set it null)
            song.OriginCity = pinput.value;
            save('originCity', pinput.value);    // a pick also set the visible display name
        });
        pcol.append(plab, pinput, phidden);
        row.appendChild(pcol);

        container.appendChild(row);

        /* Attach the geocoder typeahead (best-effort — no-op if the module didn't load). */
        if (window.iHymnsPlaceSearch && typeof window.iHymnsPlaceSearch.attach === 'function') {
            placeDetach = window.iHymnsPlaceSearch.attach(pinput, { hiddenIdInput: phidden }) || null;
        }
    }

    const off = store.subscribe('song', render);
    render();

    return function teardown() {
        off();
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        if (placeDetach) { try { placeDetach(); } catch (_e) {} placeDetach = null; }
        container.innerHTML = '';
    };
}
