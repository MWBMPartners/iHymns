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
    let selectMode = false;
    const selected = new Set();

    container.innerHTML = '';
    container.className = 'd-flex flex-column h-100';

    const head = document.createElement('div');
    head.className = 'p-2 border-bottom';
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control form-control-sm';
    search.placeholder = 'Search songs…';

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
    head.append(search, controls);

    const listEl = document.createElement('div');
    listEl.className = 'list-group list-group-flush flex-grow-1 overflow-auto';
    container.append(head, listEl);

    function matches(s, q) {
        if (!q) { return true; }
        const hay = ((s.songbook || '') + ' ' + (s.number != null ? s.number : '') + ' ' + (s.title || '')).toLowerCase();
        return hay.indexOf(q) !== -1;
    }
    function filtered() {
        const q = filter.trim().toLowerCase();
        return songs.filter((s) => matches(s, q));
    }
    function notifySelection() { onSelectionChange(selected.size, Array.from(selected)); }
    function updateCount(list, shownLen) {
        count.textContent = (selectMode && selected.size ? selected.size + ' selected · ' : '')
            + list.length + (list.length > shownLen ? ' (showing ' + shownLen + ')' : '');
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

    async function load() {
        listEl.innerHTML = '<div class="text-muted small p-3">Loading…</div>';
        try {
            const res = await api.loadIndex();
            songs = Array.isArray(res.songs) ? res.songs : [];
            renderList();
        } catch (e) {
            listEl.innerHTML = '';
            const err = document.createElement('div');
            err.className = 'alert alert-danger m-2 py-2 small';
            err.textContent = 'Could not load song list: ' + e.message;
            listEl.appendChild(err);
            toast('Could not load song list: ' + e.message, 'danger');
        }
    }

    search.addEventListener('input', () => { filter = search.value; renderList(); });
    load();

    return {
        setActive(id) { activeId = id; if (!selectMode) { renderList(); } },
        refresh: load,
        addSong(stub) { if (stub && stub.id) { songs.unshift(stub); renderList(); } },
        removeSong(id) {
            songs = songs.filter((s) => s.id !== id);
            if (selected.delete(id)) { notifySelection(); }
            if (activeId === id) { activeId = null; }
            renderList();
        },
        getFirstId() { return songs.length ? songs[0].id : null; },
        getSongbooks() {
            const seen = Object.create(null);
            const out = [];
            songs.forEach((s) => {
                const abbr = s.songbook || '';
                if (abbr && !seen[abbr]) { seen[abbr] = 1; out.push({ abbr: abbr, name: s.songbookName || abbr }); }
            });
            out.sort((a, b) => a.abbr.localeCompare(b.abbr));
            return out;
        },
        getSelectedIds() { return Array.from(selected); },
        clearSelection() { selected.clear(); notifySelection(); renderList(); },
        teardown() { container.innerHTML = ''; },
    };
}
