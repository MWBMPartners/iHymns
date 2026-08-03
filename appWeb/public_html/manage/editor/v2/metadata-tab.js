/* ==========================================================================
 *  metadata-tab.js — the v2 Song Editor "Metadata" tab (#1200, Phase 2)
 *
 *  The song's scalar fields. Each input saves ITS OWN field atomically via the
 *  granular `metadata_field_update` endpoint (debounced for text, immediate for
 *  selects/checkboxes) — no whole-song save. A failed save surfaces a real error
 *  and the field keeps the typed value for retry.
 *
 *  mountMetadataTab(container, { store, api, songId, toast, onSongIdChange,
 *                                getSongbooks, whenSongbooksReady }) -> teardown fn
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
    ['subtitle',           'Subtitle',          'Subtitle',           'text'],
    ['disambiguation',     'Disambiguation (short parenthetical)', 'Disambiguation', 'text'],
    ['number',             'Song Number',       'Number',             'number'],
    ['songbook',           'Songbook',          'SongbookAbbr',       'select'],
    ['language',           'Language (BCP 47)', 'Language',           'text'],
    ['ccli',               'CCLI Number',       'Ccli',               'text'],
    ['iswc',               'ISWC',              'Iswc',               'text'],
    ['isrc',               'ISRC',              'Isrc',               'text'],
    ['tuneName',           'Tune Name',         'TuneName',           'text'],
    ['copyright',          'Copyright',         'Copyright',          'text'],
    ['copyrightYears',     'Copyright year(s)', 'CopyrightYears',     'text'],
    ['copyrightHolder',    'Copyright holder',  'CopyrightHolder',    'text'],
    ['firstPublishedYear', 'First published (year)', 'FirstPublishedYear', 'number'],
    ['verified',           'Verified',          'Verified',           'check'],
    ['lyricsPublicDomain', 'Lyrics Public Domain', 'LyricsPublicDomain', 'check'],
    ['musicPublicDomain',  'Music Public Domain',  'MusicPublicDomain',  'check'],
    ['hasAudio',           'Has audio',         'HasAudio',           'check'],
    ['hasSheetMusic',      'Has sheet music',   'HasSheetMusic',      'check'],
];

/* #1741 P1 — these five tblSongs columns may not exist yet on an install that
 * hasn't run the "song-identity-fields" migration card (`ISRC` is #1064 —
 * pre-P1 — and is deliberately NOT in this set). load_song's `song` slice is
 * a raw `SELECT *` (api2.php ed2_buildSongSnapshot(), #1741 P5a §0.5), so an
 * absent column simply never appears as a key in `song` on an un-migrated
 * install — checking `column in song` is a zero-extra-request client gate
 * that gives a curator no dead control to click (the server's 409 remains
 * for a stale Service-Worker-cached client that tries anyway).
 */
const GATED_COLUMNS = new Set(['Subtitle', 'Disambiguation', 'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder']);

export function mountMetadataTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    /* #1679 — the shell's "this song now has a different id" handler. A plain
       injected callback rather than a DOM event, so there is no event-name
       literal to keep in sync with anything (rule #35 / #1581). Defaults to a
       no-op so the tab still mounts standalone in a test harness. */
    const onSongIdChange = opts.onSongIdChange || function () {};
    /* #1679 H1 — the songbook options, injected the same way. The shell passes
       sidebar.getSongbooks(), the SAME list the New-song modal offers, so there
       is one implementation of "which songbooks exist".
       #1679 A2 — that list is now the REAL catalogue (api2 load_index's
       `songbooks`), not the distinct books present in the loaded song index. The
       difference is not cosmetic: an index-derived list cannot contain a book
       with zero songs, so the first song could never be moved INTO a
       newly-created book — a move v1 has always allowed. Defaults to an empty
       list so the tab still mounts standalone. */
    const getSongbooks = opts.getSongbooks || function () { return []; };
    /* #1679 A2 — "tell me when that list is actually populated".
       ELI5: the song opens straight away, but the list of songbooks arrives a
       moment later, so we re-fill the dropdown when it does.
       Detail: getSongbooks() is read ONCE per render, and render() only runs when
       the `song` store slice changes — which in production happens exactly once,
       immediately before mountTabs(). The sidebar's index is fetched in parallel,
       so on a DEEP LINK (?song=…) the tabs routinely mount BEFORE it lands and
       the select froze holding a single option: the song's own book. The
       doc-block that claimed the arrow "resolves at render time" was true and
       irrelevant — nothing re-rendered. The shell passes sidebar.whenLoaded();
       the default resolves immediately so the tab still mounts standalone. */
    const whenSongbooksReady = opts.whenSongbooksReady || function () { return Promise.resolve(); };
    const timers = new Map();
    let disposed = false;     // set by teardown, so a late list can't touch a dead tab
    let placeDetach = null;   // teardown for the geocoder attached to the origin picker
    /* #1671 F3 — teardown for the "Musical key" fieldset. render() wipes the
       container on every `song`-slice change, so the panel is torn down and
       re-mounted with it; without this its in-flight fetch would resolve into a
       detached node and its listener would leak. */
    let keyPanelDetach = null;
    /* #1741 P5b — teardown for the "Recording / external IDs" fieldset. Same
       reason as keyPanelDetach immediately above: render() wipes the whole
       container on every `song`-slice change, so this panel is torn down and
       re-mounted with it. */
    let extIdsDetach = null;

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

        const sel = document.createElement('select');
        sel.className = 'form-select form-select-sm';
        sel.id = 'meta-' + field;

        /* Options are (re)built from whatever getSongbooks() answers NOW, with
           the song's own book guaranteed present — a book the index has not
           listed (empty, or still loading) must never make the control display
           the wrong songbook. Idempotent, so it can run again when the real
           catalogue arrives. */
        function fillOptions() {
            const books = (getSongbooks() || []).slice();
            if (current !== '' && !books.some((b) => b.abbr === current)) {
                books.unshift({ abbr: current, name: current });
            }
            sel.innerHTML = '';
            books.forEach((b) => {
                const o = document.createElement('option');
                o.value = b.abbr;
                o.textContent = (b.name && b.name !== b.abbr) ? (b.name + ' (' + b.abbr + ')') : b.abbr;
                sel.appendChild(o);
            });
            /* Re-assert the selection: replacing the options resets it, and the
               control must keep showing the book the song is in. */
            sel.value = current;
        }
        fillOptions();

        /* Re-fill once the catalogue lands. Without this a deep-linked song is
           stuck with the one option it mounted with, i.e. the move is impossible
           on exactly the entry point a curator follows from /manage/revisions or
           Missing Numbers. `.catch` swallows deliberately: a failed index already
           reports itself in the sidebar, and a rejected promise here must not
           surface as an unhandled rejection. */
        try {
            Promise.resolve(whenSongbooksReady())
                .then(() => { if (!disposed) { fillOptions(); } })
                .catch(() => {});
        } catch (_e) { /* a caller that returns a non-thenable: keep the mounted list */ }

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
        if (keyPanelDetach) { try { keyPanelDetach(); } catch (_e) {} keyPanelDetach = null; }
        if (extIdsDetach) { try { extIdsDetach(); } catch (_e) {} extIdsDetach = null; }
        container.innerHTML = '';
        const row = document.createElement('div');
        row.className = 'row g-3';

        FIELDS.forEach(([field, label, column, kind]) => {
            /* #1741 P1 gate — skip a field the server would 409 on rather
               than render a control that can never save. */
            if (GATED_COLUMNS.has(column) && !(column in song)) { return; }
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

        /* Musical key / tempo / time signature (#298 server, wired #1671 F3).
           A separate table (`tblSongKeys`) behind a separate endpoint pair with
           an all-or-nothing contract, so it is its own fieldset with its own
           Save rather than three more debounced `metadata_field_update` fields.
           Mounted LAST so the tab's own scalar grid keeps its position, and
           dynamically imported so a curator who never opens Metadata does not
           pay for it. Failure is logged, never fatal: the rest of the tab must
           still work if this one chunk cannot load. */
        import('./song-key-panel.js')
            .then((m) => {
                if (disposed || !container.isConnected) { return; }
                keyPanelDetach = m.mountSongKeyPanel(container, { songId: songId, toast: toast });
            })
            .catch((e) => { console.error('[metadata-tab] song-key panel failed to load:', e); });

        /* Recording / external IDs (#1741 P5b, tblSongExternalIds' first UI
           write path) — a card-list of {Spotify, ISRC, MusicBrainz, …} ids for
           this recording. Mounted BELOW the scalar grid via the SAME
           dynamically-imported-panel pattern as "Musical key" immediately
           above (own fieldset, own teardown var, curator who never opens
           Metadata never pays for the extra module). Lives here rather than
           as a new editor2.php tab because these ARE song metadata
           (identifiers about the song), not a Links-tab row — the Links tab's
           rows carry a `typeId` FK into a completely different registry
           (tblExternalLinkTypes), so reusing that editor here would mean
           faking typeIds for a store that doesn't have them. */
        import('./external-ids-panel.js')
            .then((m) => {
                if (disposed || !container.isConnected) { return; }
                extIdsDetach = m.mountExternalIdsPanel(container, { songId: songId, toast: toast });
            })
            .catch((e) => { console.error('[metadata-tab] external-ids panel failed to load:', e); });
    }

    const off = store.subscribe('song', render);
    render();

    return function teardown() {
        disposed = true;
        off();
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        if (placeDetach) { try { placeDetach(); } catch (_e) {} placeDetach = null; }
        if (keyPanelDetach) { try { keyPanelDetach(); } catch (_e) {} keyPanelDetach = null; }
        if (extIdsDetach) { try { extIdsDetach(); } catch (_e) {} extIdsDetach = null; }
        container.innerHTML = '';
    };
}
