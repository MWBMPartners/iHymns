/* ==========================================================================
 *  tags-tab.js — the v2 Song Editor "Tags" tab (#1200, Phase 3)
 *
 *  A chip list of the song's tags + an "Add a tag" typeahead. Each attach /
 *  detach is its own atomic, server-confirmed write (tag_attach / tag_detach) —
 *  the tag registry row is auto-created on attach and the SERVER's canonical
 *  {id,name,slug} is adopted (so 'worship' / 'WORSHIP' / 'Worship' all collapse
 *  to one row). Tags persist immediately; there is no whole-song save and no
 *  false-success toast — a failed write surfaces a real error.
 *
 *  mountTagsTab(container, { store, api, songId, toast }) -> teardown fn
 *  Reads/maintains the store's `tags` slice: [{ id, name, slug }, ...].
 * ========================================================================== */

const SEARCH_DEBOUNCE_MS = 180;   /* matches the legacy editor's typeahead feel */

export function mountTagsTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};

    let searchTimer = null;
    let suggestions = [];   /* current dropdown items: [{id,name,slug,usage}] */
    let activeIndex = -1;   /* keyboard-highlighted suggestion (-1 = none) */

    function getTags() {
        const t = store.get('tags');
        return Array.isArray(t) ? t : [];
    }

    /* ---- mutations (these change the store → full re-render) ---- */

    async function attach(name) {
        const clean = (name || '').trim();
        if (clean === '') { return; }
        try {
            const res = await api.attachTag(songId, clean);
            const tag = res.tag;
            if (tag) {
                const tags = getTags().slice();
                if (!tags.some((x) => x.id === tag.id)) {
                    tags.push(tag);
                    tags.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
                    store.set('tags', tags);   // re-renders (also clears the input)
                } else {
                    render();   // already present — just reset the input + dropdown
                }
                /* re-focus the (recreated) input so curators can keep adding tags */
                const ni = container.querySelector('#v2-tag-input');
                if (ni) { ni.focus(); }
            }
        } catch (e) {
            toast('Could not add tag: ' + e.message, 'danger');
        }
    }

    async function detach(tag) {
        const before = getTags();
        store.set('tags', before.filter((x) => x.id !== tag.id));   // optimistic remove
        try {
            await api.detachTag(songId, tag.id);
        } catch (e) {
            store.set('tags', before);   // revert — never leave the chip gone when the server refused (§6a: no optimistic lies)
            toast('Could not remove tag: ' + e.message, 'danger');
        }
    }

    /* ---- typeahead ---- */

    function runSearch(input, dropdown) {
        const q = input.value.trim();
        api.searchTags(q, 10).then((res) => {
            suggestions = Array.isArray(res.suggestions) ? res.suggestions : [];
            activeIndex = -1;
            renderDropdown(input, dropdown);
        }).catch(() => { /* search is best-effort; a failure just shows no list */ });
    }

    function renderDropdown(input, dropdown) {
        dropdown.innerHTML = '';
        const q = input.value.trim();
        const existingNames = new Set(getTags().map((t) => String(t.name || '').toLowerCase()));
        const lowerSugg = suggestions.map((s) => String(s.name || '').toLowerCase());
        const exactMatch = q !== '' && lowerSugg.includes(q.toLowerCase());

        let rendered = 0;
        suggestions.forEach((s, i) => {
            if (existingNames.has(String(s.name || '').toLowerCase())) { return; }   // hide tags already on the song
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1';
            const label = document.createElement('span');
            label.textContent = s.name;
            const badge = document.createElement('span');
            badge.className = 'badge bg-secondary rounded-pill';
            badge.textContent = String(s.usage || 0);
            badge.title = (s.usage || 0) + ' song(s) tagged';
            item.append(label, badge);
            item.addEventListener('mousedown', (ev) => { ev.preventDefault(); attach(s.name); });
            dropdown.appendChild(item);
            rendered++;
        });

        /* Offer "Create new tag: <q>" when the typed value isn't an exact hit. */
        if (q !== '' && !exactMatch && !existingNames.has(q.toLowerCase())) {
            const create = document.createElement('button');
            create.type = 'button';
            create.className = 'list-group-item list-group-item-action py-1 fst-italic';
            create.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Create new tag: ';
            const strong = document.createElement('strong');
            strong.textContent = q;
            create.appendChild(strong);
            create.addEventListener('mousedown', (ev) => { ev.preventDefault(); attach(q); });
            dropdown.appendChild(create);
            rendered++;
        }

        dropdown.classList.toggle('d-none', rendered === 0);
    }

    /* ---- render ---- */

    function render() {
        suggestions = [];
        activeIndex = -1;
        /* Cancel any pending typeahead debounce — render() recreates the input +
           dropdown, so a timer scheduled against the old (now detached) elements
           would fire on orphans + leak them. */
        if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
        container.innerHTML = '';

        const tags = getTags();

        /* chip list */
        const chips = document.createElement('div');
        chips.className = 'd-flex flex-wrap gap-2 mb-3';
        if (!tags.length) {
            const empty = document.createElement('span');
            empty.className = 'text-muted small';
            empty.textContent = 'No tags yet.';
            chips.appendChild(empty);
        }
        tags.forEach((tag) => {
            const chip = document.createElement('span');
            chip.className = 'badge rounded-pill d-inline-flex align-items-center gap-1 text-bg-primary';
            const label = document.createElement('span');
            label.textContent = tag.name;
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'btn-close btn-close-white';
            x.style.fontSize = '0.6em';
            x.title = 'Remove tag';
            x.setAttribute('aria-label', 'Remove tag ' + tag.name);
            x.addEventListener('click', () => detach(tag));
            chip.append(label, x);
            chips.appendChild(chip);
        });
        container.appendChild(chips);

        /* add input + dropdown */
        const lab = document.createElement('label');
        lab.className = 'form-label small mb-1';
        lab.htmlFor = 'v2-tag-input';
        lab.textContent = 'Add a tag';
        const wrap = document.createElement('div');
        wrap.className = 'position-relative';
        wrap.style.maxWidth = '420px';
        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'v2-tag-input';
        input.className = 'form-control form-control-sm';
        input.autocomplete = 'off';
        input.placeholder = 'Type to search or create — e.g. Easter, Communion';
        const dropdown = document.createElement('div');
        dropdown.className = 'list-group position-absolute w-100 shadow-sm d-none';
        dropdown.style.zIndex = '1050';
        dropdown.style.maxHeight = '240px';
        dropdown.style.overflowY = 'auto';

        input.addEventListener('input', () => {
            if (searchTimer) { clearTimeout(searchTimer); }
            searchTimer = setTimeout(() => runSearch(input, dropdown), SEARCH_DEBOUNCE_MS);
        });
        input.addEventListener('focus', () => runSearch(input, dropdown));
        input.addEventListener('blur', () => { setTimeout(() => dropdown.classList.add('d-none'), 150); });
        input.addEventListener('keydown', (e) => {
            const items = Array.from(dropdown.querySelectorAll('.list-group-item'));
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                highlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, -1);
                highlight(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0 && items[activeIndex]) {
                    items[activeIndex].dispatchEvent(new MouseEvent('mousedown'));
                } else {
                    attach(input.value);
                }
            } else if (e.key === 'Escape') {
                dropdown.classList.add('d-none');
            }
        });

        wrap.append(input, dropdown);
        container.append(lab, wrap);
    }

    function highlight(items) {
        items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
    }

    const off = store.subscribe('tags', render);
    render();

    return function teardown() {
        off();
        if (searchTimer) { clearTimeout(searchTimer); }
        container.innerHTML = '';
    };
}
