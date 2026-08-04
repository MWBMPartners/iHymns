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

    /**
     * Max lyric lines per slide — the one per-format export option v1 exposed
     * and v2 hardcoded to 0 (#1628 item 5, restoring #1065).
     *
     * ELI5: how many lines of lyrics go on one presentation slide. 0 means "keep
     * each verse whole", which is the right answer until a verse is too long for
     * the screen at the back of the hall.
     *
     * Detail: v2 passed a literal `0` to every serializer, so the setting simply
     * did not exist — a curator preparing for a venue with a small screen had no
     * way to split slides without leaving the editor. The value is read at CLICK
     * time, not at mount, so changing the number and then picking a format works
     * without any re-render or change plumbing.
     *
     * Shares v1's localStorage key deliberately: it is one operator preference
     * about one projector, not a per-editor setting, so a curator who set it in
     * v1 keeps it in v2 and vice-versa across the #1601 cutover.
     */
    const LINES_PER_SLIDE_KEY = 'ihymns_export_lines_per_slide';

    function readStoredLinesPerSlide() {
        try {
            const stored = parseInt(window.localStorage.getItem(LINES_PER_SLIDE_KEY), 10);
            return (!isNaN(stored) && stored > 0) ? Math.min(stored, 20) : 0;
        } catch (_e) {
            return 0;   // storage can throw in private mode / with cookies blocked
        }
    }

    /* Clamped to the same 0-20 as v1's input. A serializer handed a negative or
       absurd value produces a file nobody can use, and the failure shows up in
       ProPresenter rather than here. */
    function linesPerSlide() {
        const el = document.getElementById('v2-export-lines-per-slide');
        if (!el) { return readStoredLinesPerSlide(); }
        const v = parseInt(el.value, 10);
        return (!isNaN(v) && v > 0) ? Math.min(v, 20) : 0;
    }

    function runFmt(key) {
        try {
            const r = fmt[key].exportSong(buildExportSong(store), { maxLinesPerSlide: linesPerSlide() });
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
        /* Same setting as the client-side formats (#1628 item 5) — EasyWorship is
           generated server-side, so it travels as a query param instead of an
           options object, but it must honour the same number or the one
           server-rendered format would quietly ignore the operator's choice. */
        const url = '/manage/editor/api2.php?action=easyworship_export&id=' + encodeURIComponent(songId)
            + '&maxLinesPerSlide=' + encodeURIComponent(String(linesPerSlide()));
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

    /* ---- the lines-per-slide control, below a divider (v1's layout) ---- */
    const sepLi = document.createElement('li');
    const sep = document.createElement('hr');
    sep.className = 'dropdown-divider';
    sepLi.appendChild(sep);
    menuEl.appendChild(sepLi);

    const optLi = document.createElement('li');
    const optWrap = document.createElement('div');
    optWrap.className = 'px-3 py-1';
    const optLabel = document.createElement('label');
    optLabel.className = 'form-label small mb-1 d-block';
    optLabel.setAttribute('for', 'v2-export-lines-per-slide');
    optLabel.textContent = 'Max lines / slide';
    const optHint = document.createElement('span');
    optHint.className = 'text-muted';
    optHint.textContent = ' · presentation formats';
    optLabel.appendChild(optHint);
    const optInput = document.createElement('input');
    optInput.type = 'number';
    optInput.className = 'form-control form-control-sm';
    optInput.id = 'v2-export-lines-per-slide';
    optInput.min = '0';
    optInput.max = '20';
    optInput.step = '1';
    optInput.style.width = '5rem';
    optInput.value = String(readStoredLinesPerSlide());
    optInput.title = '0 = keep each verse on one slide. Remembered as your default.';
    /* The visible label already names this control, so aria-label would only
       ADD a second, competing accessible name (WCAG 2.5.3 Label in Name).
       https://www.w3.org/WAI/WCAG21/Understanding/label-in-name.html */

    /* Clicking inside the menu must not close it — Bootstrap's dropdown treats a
       click anywhere in .dropdown-menu as a dismiss trigger, so without this the
       menu shuts the instant the curator focuses the field and the setting is
       unreachable. Same reason v1 wraps its control in a plain <li>. */
    const stop = (e) => e.stopPropagation();
    optWrap.addEventListener('click', stop);
    cleanups.push(() => optWrap.removeEventListener('click', stop));

    /* Persist on change so the number is a remembered default, not a per-session
       one — a curator sets it once for their venue's screen. */
    const persist = () => {
        try {
            window.localStorage.setItem(LINES_PER_SLIDE_KEY, String(linesPerSlide()));
        } catch (_e) { /* private mode — the value still applies to this export */ }
    };
    optInput.addEventListener('change', persist);
    cleanups.push(() => optInput.removeEventListener('change', persist));

    optWrap.append(optLabel, optInput);
    optLi.appendChild(optWrap);
    menuEl.appendChild(optLi);

    return function teardown() {
        cleanups.forEach((fn) => fn());
        menuEl.innerHTML = '';
    };
}
