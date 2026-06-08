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
    ['originCity',         'Composition origin','OriginCity',         'text'],
    ['verified',           'Verified',          'Verified',           'check'],
    ['lyricsPublicDomain', 'Lyrics Public Domain', 'LyricsPublicDomain', 'check'],
    ['musicPublicDomain',  'Music Public Domain',  'MusicPublicDomain',  'check'],
    ['hasAudio',           'Has audio',         'HasAudio',           'check'],
    ['hasSheetMusic',      'Has sheet music',   'HasSheetMusic',      'check'],
];

export function mountMetadataTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    const timers = new Map();

    function save(field, value) {
        api.updateMetadata(songId, field, value).catch((e) => {
            toast('Could not save ' + field + ': ' + e.message, 'danger');
        });
    }
    function debouncedSave(field, value) {
        if (timers.has(field)) { clearTimeout(timers.get(field)); }
        timers.set(field, setTimeout(() => save(field, value), SAVE_DEBOUNCE_MS));
    }

    function render() {
        const song = store.get('song') || {};
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

        container.appendChild(row);
    }

    const off = store.subscribe('song', render);
    render();

    return function teardown() {
        off();
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        container.innerHTML = '';
    };
}
