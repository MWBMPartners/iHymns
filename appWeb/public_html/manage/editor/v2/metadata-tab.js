/* ==========================================================================
 *  metadata-tab.js — the v2 Song Editor "Metadata" tab (#1200, Phase 2)
 *
 *  The song's scalar fields. Each input saves ITS OWN field atomically via the
 *  granular `metadata_field_update` endpoint (debounced for text, immediate for
 *  selects/checkboxes) — no whole-song save. A failed save surfaces a real error
 *  and the field keeps the typed value for retry.
 *
 *  mountMetadataTab(container, { store, api, songId, toast }) -> teardown fn
 *  Reads initial values from the store's `song` slice (the tblSongs row, as
 *  returned by api2.php load_song — note the columns are PascalCase).
 * ========================================================================== */

const SAVE_DEBOUNCE_MS = 500;

/* field key (api2 ED2_META_FIELDS) -> [label, tblSongs column, input kind] */
const FIELDS = [
    ['title',              'Song Title',        'Title',              'text'],
    ['number',             'Song Number',       'Number',             'number'],
    ['songbook',           'Songbook (abbr)',   'SongbookAbbr',       'text'],
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
    const timers = new Map();
    let placeDetach = null;   // teardown for the geocoder attached to the origin picker

    function save(field, value) {
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
        });
    }
    function debouncedSave(field, value) {
        if (timers.has(field)) { clearTimeout(timers.get(field)); }
        timers.set(field, setTimeout(() => save(field, value), SAVE_DEBOUNCE_MS));
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
