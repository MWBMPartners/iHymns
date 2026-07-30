/* ==========================================================================
 *  export.js — the v2 Song Editor EXPORT menu (#1200, Phase 4)
 *
 *  Single-song export to the worship formats, REUSING the existing pure
 *  serializers (window.iHymnsFormatExport for OpenSong/OpenLP/VideoPsalm/
 *  FreeShow/Proclaim/ProPresenter 6, window.iHymnsProPresenter for PP7) +
 *  the server-side EasyWorship endpoint — no serializer is re-implemented
 *  (CLAUDE.md modularity rule). The only glue is an adapter that maps the v2
 *  reactive store (PascalCase scalars + per-role credit objects) into the flat
 *  legacy song shape those serializers consume.
 *
 *  mountExportMenu(menuEl, { store, songId, toast }) -> teardown fn
 *    menuEl : the <ul class="dropdown-menu"> to populate.
 * ========================================================================== */

/* v2 store slices -> the flat song object the legacy serializers expect:
   {title, number, songbook, songbookName, language, copyright, ccli, tuneName,
    writers:[name], composers:[name], arrangers:[name], artists:[name],
    components:[{type, number, lines[], chords[]|null}]}. */
function buildExportSong(store) {
    const s = store.get('song') || {};
    const credits = store.get('credits') || {};
    const names = (role) => (Array.isArray(credits[role]) ? credits[role] : [])
        .map((c) => c.name).filter(Boolean);
    const components = (store.get('components') || []).slice()
        .sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
        /* `chords` is carried through because ChordPro's inline [chord] markers
           are built from it (#1080). Without it the v2 editor's own
           "Export ▸ ChordPro" silently produced a LYRICS-ONLY file while the
           public song-page export produced a correct chord sheet — the menu
           worked, a file downloaded, and the entire point of the format was
           missing. That is the same looks-alive-but-isn't class as #1565.

           The store holds `chords` as null or an array parallel to `lines`
           (structure-tab.js:44), which is exactly the shape
           format-export.js's buildChordPro() expects, so it passes straight
           through with the same defensive Array.isArray guard as `lines`. */
        .map((c) => ({
            type:   c.type,
            number: c.number,
            lines:  Array.isArray(c.lines)  ? c.lines  : [],
            chords: Array.isArray(c.chords) ? c.chords : null,
        }));
    return {
        id:           s.SongId || s.id || '',
        title:        s.Title || '',
        number:       (s.Number != null && s.Number !== '') ? s.Number : '',
        songbook:     s.SongbookAbbr || '',
        songbookName: s.SongbookName || s.SongbookAbbr || '',   // load_song omits SongbookName → fall back to abbr
        language:     s.Language || '',
        copyright:    s.Copyright || '',
        ccli:         s.Ccli || '',
        tuneName:     s.TuneName || '',
        writers:      names('writers'),
        composers:    names('composers'),
        arrangers:    names('arrangers'),
        artists:      names('artists'),
        components:   components,
    };
}

export function mountExportMenu(menuEl, opts) {
    const { store, songId } = opts;
    const toast = opts.toast || function () {};

    const fmt = (window.iHymnsFormatExport && typeof window.iHymnsFormatExport === 'object') ? window.iHymnsFormatExport : null;
    const pp7 = (window.iHymnsProPresenter && typeof window.iHymnsProPresenter === 'object') ? window.iHymnsProPresenter : null;

    /* [label, handler]. A handler that's null renders as a disabled item. */
    const ITEMS = [
        ['ProPresenter 7', pp7 ? () => exportPP7() : null],
        ['ProPresenter 6', fmt ? () => runFmt('proPresenter6') : null],
        ['OpenSong',       fmt ? () => runFmt('openSong') : null],
        ['OpenLP / OpenLyrics', fmt ? () => runFmt('openLyrics') : null],
        ['VideoPsalm',     fmt ? () => runFmt('videoPsalm') : null],
        ['FreeShow',       fmt ? () => runFmt('freeShow') : null],
        ['Proclaim',       fmt ? () => runFmt('proclaim') : null],
        ['EasyWorship (beta)', () => exportEasyWorship()],
    ];

    function runFmt(key) {
        try {
            const r = fmt[key].exportSong(buildExportSong(store), { maxLinesPerSlide: 0 });
            toast('Exported ' + r.filename, 'success');
        } catch (e) {
            toast('Export failed: ' + e.message, 'danger');
        }
    }

    async function exportPP7() {
        try {
            if (typeof pp7.init === 'function') { await pp7.init(); }
            const r = await pp7.exportSong(buildExportSong(store), {});
            toast('Exported ' + r.filename, 'success');
        } catch (e) {
            toast('ProPresenter 7 export failed: ' + e.message, 'danger');
        }
    }

    /* EasyWorship is generated server-side (SQLite) — trigger a download of the
       gated GET endpoint (same-origin cookie carries the editor session). Points
       at the v2 API (api2.php), not the legacy api.php: v1's `easyworship_export`
       action was one of the endpoints epic #1601's retirement would have broken,
       so #1678 gave api2.php its own case backed by the same shared helpers. */
    function exportEasyWorship() {
        if (!songId) { toast('Open a song first.', 'danger'); return; }
        const url = '/manage/editor/api2.php?action=easyworship_export&id=' + encodeURIComponent(songId) + '&maxLinesPerSlide=0';
        const a = document.createElement('a');
        a.href = url;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    /* ---- render the dropdown items ---- */
    menuEl.innerHTML = '';
    const cleanups = [];
    ITEMS.forEach(([label, handler]) => {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dropdown-item' + (handler ? '' : ' disabled');
        btn.textContent = label;
        if (handler) {
            btn.addEventListener('click', handler);
            cleanups.push(() => btn.removeEventListener('click', handler));
        } else {
            btn.title = 'Export module not loaded';
        }
        li.appendChild(btn);
        menuEl.appendChild(li);
    });

    return function teardown() {
        cleanups.forEach((fn) => fn());
        menuEl.innerHTML = '';
    };
}
