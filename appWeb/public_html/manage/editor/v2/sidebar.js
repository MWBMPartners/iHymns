/* ==========================================================================
 *  sidebar.js — the v2 Song Editor song-list sidebar (#1200, Phase 5)
 *
 *  Loads the lightweight song index (api2.php load_index → SongData slim index)
 *  once, renders a searchable list, and calls onSelect(id) when a song is
 *  clicked (the shell does the load-on-select). Client-side filter + a render
 *  cap keep a multi-thousand-song corpus responsive without server pagination.
 *
 *  mountSidebar(container, { api, onSelect, toast }) -> handle
 *    handle.setActive(id) · refresh() · addSong(stub) · removeSong(id)
 *         · getFirstId() · getSongbooks() · teardown()
 * ========================================================================== */

const RENDER_CAP = 300;   // most matches shown at once; search narrows further

export function mountSidebar(container, opts) {
    const { api } = opts;
    const onSelect = opts.onSelect || function () {};
    const toast = opts.toast || function () {};

    let songs = [];        // the slim index: [{ id, number, title, songbook, songbookName, ... }]
    let activeId = null;
    let filter = '';

    container.innerHTML = '';
    container.className = 'd-flex flex-column h-100';

    const head = document.createElement('div');
    head.className = 'p-2 border-bottom';
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control form-control-sm';
    search.placeholder = 'Search songs…';
    const count = document.createElement('div');
    count.className = 'text-muted small mt-1';
    head.append(search, count);

    const listEl = document.createElement('div');
    listEl.className = 'list-group list-group-flush flex-grow-1 overflow-auto';

    container.append(head, listEl);

    function matches(s, q) {
        if (!q) { return true; }
        const hay = ((s.songbook || '') + ' ' + (s.number != null ? s.number : '') + ' ' + (s.title || '')).toLowerCase();
        return hay.indexOf(q) !== -1;
    }

    function renderList() {
        listEl.innerHTML = '';
        const q = filter.trim().toLowerCase();
        const filtered = songs.filter((s) => matches(s, q));
        const shown = filtered.slice(0, RENDER_CAP);
        count.textContent = filtered.length + ' song' + (filtered.length === 1 ? '' : 's')
            + (filtered.length > shown.length ? ' (showing ' + shown.length + ')' : '');

        if (!shown.length) {
            const empty = document.createElement('div');
            empty.className = 'text-muted small p-3';
            empty.textContent = songs.length ? 'No matches.' : 'No songs.';
            listEl.appendChild(empty);
            return;
        }

        shown.forEach((s) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action py-1 px-2';
            if (s.id === activeId) { item.classList.add('active'); }
            item.dataset.id = s.id;

            const ref = document.createElement('div');
            ref.className = 'small text-muted';
            ref.textContent = [s.songbook, (s.number != null && s.number !== '' ? s.number : null)].filter((x) => x != null && x !== '').join(' ');
            const title = document.createElement('div');
            title.className = 'text-truncate';
            title.textContent = s.title || '(untitled)';
            item.append(ref, title);

            item.addEventListener('click', () => onSelect(s.id));
            listEl.appendChild(item);
        });
    }

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
        setActive(id) { activeId = id; renderList(); },
        refresh: load,
        addSong(stub) {
            if (stub && stub.id) { songs.unshift(stub); renderList(); }
        },
        removeSong(id) {
            songs = songs.filter((s) => s.id !== id);
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
        teardown() { container.innerHTML = ''; },
    };
}
