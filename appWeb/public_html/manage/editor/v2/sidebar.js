/* ==========================================================================
 *  sidebar.js — the v2 Song Editor song-list sidebar (#1200, Phase 5)
 *
 *  Loads the lightweight song index (api2.php load_index → SongData slim index)
 *  once, renders a searchable list, and calls onSelect(id) when a song is
 *  clicked. A "Select" mode swaps the rows for checkboxes so the shell can run
 *  bulk ops on a selection (onSelectionChange reports the count). Client-side
 *  filter + a render cap keep a multi-thousand-song corpus responsive.
 *
 *  mountSidebar(container, { api, onSelect, onSelectionChange, toast }) -> handle
 *    handle.setActive(id) · refresh() · addSong(stub) · removeSong(id)
 *         · getFirstId() · getSongbooks() · getSelectedIds() · clearSelection()
 *         · teardown()
 * ========================================================================== */

const RENDER_CAP = 300;   // most matches shown at once; search narrows further

export function mountSidebar(container, opts) {
    const { api } = opts;
    const onSelect = opts.onSelect || function () {};
    const onSelectionChange = opts.onSelectionChange || function () {};
    const toast = opts.toast || function () {};

    let songs = [];          // slim index: [{ id, number, title, songbook, songbookName, ... }]
    let activeId = null;
    let filter = '';
    let bookFilter = '';     // '' = all songbooks (#1628 item 2)
    let sortMode = 'title';  // 'title' | 'number' | 'songbook'
    let selectMode = false;
    const selected = new Set();

    container.innerHTML = '';
    container.className = 'd-flex flex-column h-100';

    const head = document.createElement('div');
    head.className = 'p-2 border-bottom';
    /* Songbook filter + sort order (#1628 item 2, restoring v1's #251).
       ELI5: pick one songbook, and choose how the list is ordered.
       Detail: v2 shipped with text search and a 300-row render cap only. That is
       not a substitute for "browse songbook X by number" — the core curatorial
       motion of working through a book song by song. Searching "412" matches any
       song whose title or number contains 412, in any book, and the cap then
       hides the rest; filtering to one book and sorting by number gives the
       actual sequence. */
    const bookSel = document.createElement('select');
    bookSel.className = 'form-select form-select-sm mb-1';
    bookSel.setAttribute('aria-label', 'Filter by songbook');
    bookSel.title = 'Filter songs by songbook';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control form-control-sm';
    search.placeholder = 'Search songs…';

    const sortSel = document.createElement('select');
    sortSel.className = 'form-select form-select-sm mt-1';
    sortSel.setAttribute('aria-label', 'Sort songs by');
    sortSel.title = 'Sort order';
    [
        ['title',    'Sort by Title (A–Z)'],
        ['number',   'Sort by Number'],
        ['songbook', 'Sort by Songbook, then Number'],
    ].forEach(([value, label]) => {
        const o = document.createElement('option');
        o.value = value;
        o.textContent = label;
        sortSel.appendChild(o);
    });

    const controls = document.createElement('div');
    controls.className = 'd-flex align-items-center gap-1 mt-1';
    const count = document.createElement('div');
    count.className = 'text-muted small me-auto';
    const selBtn = document.createElement('button');
    selBtn.type = 'button';
    selBtn.className = 'btn btn-sm btn-outline-secondary py-0';
    selBtn.textContent = 'Select';
    const allBtn = document.createElement('button');
    allBtn.type = 'button';
    allBtn.className = 'btn btn-sm btn-outline-secondary py-0 d-none';
    allBtn.textContent = 'All';
    const noneBtn = document.createElement('button');
    noneBtn.type = 'button';
    noneBtn.className = 'btn btn-sm btn-outline-secondary py-0 d-none';
    noneBtn.textContent = 'None';
    controls.append(count, allBtn, noneBtn, selBtn);
    head.append(bookSel, search, sortSel, controls);

    const listEl = document.createElement('div');
    listEl.className = 'list-group list-group-flush flex-grow-1 overflow-auto';
    container.append(head, listEl);

    function matches(s, q) {
        if (!q) { return true; }
        const hay = ((s.songbook || '') + ' ' + (s.number != null ? s.number : '') + ' ' + (s.title || '')).toLowerCase();
        return hay.indexOf(q) !== -1;
    }
    /**
     * Comparators ported from v1 (editor.js:287-296) so the two editors order a
     * list identically — a curator switching editors mid-task should not see the
     * same book in a different sequence.
     *
     * `Number(x) || 0` is load-bearing, not laziness: song numbers arrive from
     * the slim index as strings, and a plain string compare puts "10" before
     * "2", which silently mis-orders every book. `|| 0` also folds null/''
     * (unnumbered songs, common in unofficial books) to the top rather than
     * NaN-ing the comparator into an unstable order.
     *
     * `localeCompare(..., { sensitivity: 'base' })` makes the title sort
     * case- and accent-insensitive, so "Ábide" files with "Abide".
     * https://developer.mozilla.org/docs/Web/JavaScript/Reference/Global_Objects/String/localeCompare
     */
    function compare(a, b) {
        if (sortMode === 'number') {
            return (Number(a.number) || 0) - (Number(b.number) || 0);
        }
        if (sortMode === 'songbook') {
            const sb = (a.songbook || '').localeCompare(b.songbook || '', undefined, { sensitivity: 'base' });
            if (sb !== 0) { return sb; }
            return (Number(a.number) || 0) - (Number(b.number) || 0);
        }
        return (a.title || '').localeCompare(b.title || '', undefined, { sensitivity: 'base' });
    }

    function filtered() {
        const q = filter.trim().toLowerCase();
        /* Filter BEFORE sort: the songbook filter is what makes the RENDER_CAP
           survivable on a multi-thousand-song corpus — sorting the whole index
           and then capping would still show the wrong 300 rows. */
        return songs
            .filter((s) => (!bookFilter || s.songbook === bookFilter) && matches(s, q))
            .sort(compare);
    }
    function notifySelection() { onSelectionChange(selected.size, Array.from(selected)); }
    function updateCount(list, shownLen) {
        count.textContent = (selectMode && selected.size ? selected.size + ' selected · ' : '')
            + list.length + (list.length > shownLen ? ' (showing ' + shownLen + ')' : '');
    }

    /* The distinct songbooks present in the loaded index. Hoisted to a local so
       the filter dropdown and the exported getSongbooks() (which the shell's
       New-song modal uses) derive from ONE implementation — the alternative was
       a second copy of this loop, which is the duplication the modularity rule
       exists to stop. */
    function songbookList() {
        const seen = Object.create(null);
        const out = [];
        songs.forEach((s) => {
            const abbr = s.songbook || '';
            if (abbr && !seen[abbr]) { seen[abbr] = 1; out.push({ abbr: abbr, name: s.songbookName || abbr }); }
        });
        out.sort((a, b) => a.abbr.localeCompare(b.abbr));
        return out;
    }

    /* Rebuild the filter options, preserving the current selection if that book
       still exists (a deleted song can remove the last member of a book). */
    function renderBookOptions() {
        const previous = bookFilter;
        bookSel.innerHTML = '';
        const all = document.createElement('option');
        all.value = '';
        all.textContent = 'All Songbooks';
        bookSel.appendChild(all);
        songbookList().forEach((b) => {
            const o = document.createElement('option');
            o.value = b.abbr;
            o.textContent = b.name === b.abbr ? b.abbr : (b.name + ' (' + b.abbr + ')');
            bookSel.appendChild(o);
        });
        bookFilter = Array.prototype.some.call(bookSel.options, (o) => o.value === previous) ? previous : '';
        bookSel.value = bookFilter;
    }

    function refLabel(s) {
        return [s.songbook, (s.number != null && s.number !== '' ? s.number : null)].filter((x) => x != null && x !== '').join(' ');
    }

    function renderList() {
        listEl.innerHTML = '';
        const list = filtered();
        const shown = list.slice(0, RENDER_CAP);
        updateCount(list, shown.length);

        if (!shown.length) {
            const empty = document.createElement('div');
            empty.className = 'text-muted small p-3';
            empty.textContent = songs.length ? 'No matches.' : 'No songs.';
            listEl.appendChild(empty);
            return;
        }

        shown.forEach((s) => {
            if (selectMode) {
                /* Select mode: a label + checkbox (valid interactive markup; no row-load). */
                const item = document.createElement('label');
                item.className = 'list-group-item d-flex align-items-center gap-2 py-1';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'form-check-input mt-0 flex-shrink-0';
                cb.checked = selected.has(s.id);
                cb.addEventListener('change', () => {
                    if (cb.checked) { selected.add(s.id); } else { selected.delete(s.id); }
                    notifySelection();
                    updateCount(list, shown.length);
                });
                const text = document.createElement('div');
                text.className = 'min-width-0';
                const ref = document.createElement('div'); ref.className = 'small text-muted'; ref.textContent = refLabel(s);
                const title = document.createElement('div'); title.className = 'text-truncate'; title.textContent = s.title || '(untitled)';
                text.append(ref, title);
                item.append(cb, text);
                listEl.appendChild(item);
            } else {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action py-1 px-2';
                if (s.id === activeId) { item.classList.add('active'); }
                item.dataset.id = s.id;
                const ref = document.createElement('div'); ref.className = 'small text-muted'; ref.textContent = refLabel(s);
                const title = document.createElement('div'); title.className = 'text-truncate'; title.textContent = s.title || '(untitled)';
                item.append(ref, title);
                item.addEventListener('click', () => onSelect(s.id));
                listEl.appendChild(item);
            }
        });
    }

    function setSelectMode(on) {
        selectMode = on;
        selBtn.classList.toggle('active', on);
        selBtn.textContent = on ? 'Done' : 'Select';
        allBtn.classList.toggle('d-none', !on);
        noneBtn.classList.toggle('d-none', !on);
        if (!on) { selected.clear(); }
        notifySelection();
        renderList();
    }

    selBtn.addEventListener('click', () => setSelectMode(!selectMode));
    /* "All" selects the currently-shown (capped) matches so the selection never
       exceeds what the curator can see checked — narrow the search to reach more. */
    allBtn.addEventListener('click', () => { filtered().slice(0, RENDER_CAP).forEach((s) => selected.add(s.id)); notifySelection(); renderList(); });
    noneBtn.addEventListener('click', () => { selected.clear(); notifySelection(); renderList(); });

    /* Resolves once the index has loaded (or failed). The shell's #1680
       Missing-Numbers prefill needs the song list before it can tell "song 412
       of CP is missing" from "it exists and should just be opened" — and
       load() is async, so without a handle to await, the prefill would race the
       fetch and mint a duplicate whenever the index was a moment slow. */
    let resolveLoaded;
    const loadedPromise = new Promise((r) => { resolveLoaded = r; });

    async function load() {
        listEl.innerHTML = '<div class="text-muted small p-3">Loading…</div>';
        try {
            const res = await api.loadIndex();
            songs = Array.isArray(res.songs) ? res.songs : [];
            renderBookOptions();
            renderList();
        } catch (e) {
            listEl.innerHTML = '';
            const err = document.createElement('div');
            err.className = 'alert alert-danger m-2 py-2 small';
            err.textContent = 'Could not load song list: ' + e.message;
            listEl.appendChild(err);
            toast('Could not load song list: ' + e.message, 'danger');
        } finally {
            /* `finally`, not the success branch: a waiter must be released even
               when the index FAILED, or the #1680 prefill hangs forever on a
               spinner with no explanation. It then finds no match and reports a
               real error instead — a wrong answer beats a silent stall. */
            resolveLoaded();
        }
    }

    search.addEventListener('input', () => { filter = search.value; renderList(); });
    bookSel.addEventListener('change', () => { bookFilter = bookSel.value; renderList(); });
    sortSel.addEventListener('change', () => { sortMode = sortSel.value; renderList(); });
    load();

    return {
        setActive(id) { activeId = id; if (!selectMode) { renderList(); } },
        refresh: load,
        /* Both mutators refresh the filter options: a new song can introduce a
           songbook the dropdown has never listed, and deleting the last song of
           a book must not strand a filter pinned to a book with no members —
           which would show an empty list with no obvious cause. */
        addSong(stub) { if (stub && stub.id) { songs.unshift(stub); renderBookOptions(); renderList(); } },
        removeSong(id) {
            songs = songs.filter((s) => s.id !== id);
            if (selected.delete(id)) { notifySelection(); }
            if (activeId === id) { activeId = null; }
            renderBookOptions();
            renderList();
        },
        /* getFirstId() answers "what should we open after a delete?", so it must
           follow what the curator is actually LOOKING at — the filtered, sorted
           list — not the raw index order. Before the filter existed those were
           the same thing; with a book filter applied, songs[0] can be a song
           from a different book that is not on screen. */
        getFirstId() { const l = filtered(); return l.length ? l[0].id : null; },
        getSongbooks: songbookList,
        /* Resolves once the index has loaded OR failed — see the note by
           loadedPromise. Callers that need the corpus (the #1680 prefill) await
           this instead of racing load(). */
        whenLoaded() { return loadedPromise; },
        /* Exact (songbook, number) lookup -> SongId, or null (#1680).
           Numbers arrive from the slim index as strings, so compare NUMERICALLY:
           `'412' === 412` is false, and a string compare here would report every
           existing song as missing and mint a duplicate on its number — the one
           outcome the Missing Numbers flow must never produce. */
        findByBookAndNumber(abbr, number) {
            const n = Number(number);
            if (!abbr || !Number.isFinite(n)) { return null; }
            const hit = songs.find((s) => s.songbook === abbr && Number(s.number) === n);
            return hit ? hit.id : null;
        },
        getSelectedIds() { return Array.from(selected); },
        clearSelection() { selected.clear(); notifySelection(); renderList(); },
        teardown() { container.innerHTML = ''; },
    };
}
