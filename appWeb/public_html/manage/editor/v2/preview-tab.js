/* ==========================================================================
 *  preview-tab.js — the v2 Song Editor "Preview" tab (#1200)
 *
 *  Read-only render of the song as it would present: the title + each component
 *  shown as a labelled block of lyric lines, in display (SortOrder) order. Pure
 *  view — no writes, no API. Subscribes to the `song` + `components` slices, so
 *  it stays live as the curator edits the other tabs.
 *
 *  mountPreviewTab(container, { store }) -> teardown fn
 * ========================================================================== */

export function mountPreviewTab(container, opts) {
    const { store } = opts;

    function render() {
        const song = store.get('song') || {};
        const comps = (store.get('components') || []).slice()
            .sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0));

        container.innerHTML = '';

        const head = document.createElement('div');
        head.className = 'mb-3';
        const h = document.createElement('h2');
        h.className = 'h4 mb-0';
        h.textContent = song.Title || '(untitled)';
        head.appendChild(h);
        if (song.Number || song.SongbookAbbr) {
            const sub = document.createElement('div');
            sub.className = 'text-muted small';
            sub.textContent = [song.SongbookAbbr, song.Number].filter(Boolean).join(' · ');
            head.appendChild(sub);
        }
        container.appendChild(head);

        if (!comps.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted';
            empty.textContent = 'No sections yet — add some on the Structure tab.';
            container.appendChild(empty);
            return;
        }

        comps.forEach((c) => {
            const block = document.createElement('div');
            block.className = 'mb-3';

            const label = document.createElement('div');
            label.className = 'fw-semibold text-uppercase small text-muted mb-1';
            /* #1907 Phase 5 — custom-first (rule #33): a curator-set component
               Label overrides the derived "Verse 1" heading here too, mirroring
               structure-tab.js's derivedComponentName()/headerText() and the six
               other display sites. The tree-derived guard test-component-label-sites.js
               caught this Preview tab as the site Commit 8's typed sweep missed. */
            label.textContent = (c.label && String(c.label).trim())
                ? String(c.label).trim()
                : (c.type || 'verse').replace(/^\w/, (ch) => ch.toUpperCase()) + (c.number ? ' ' + c.number : '');
            block.appendChild(label);

            const body = document.createElement('div');
            body.className = 'preview-lines';
            body.style.whiteSpace = 'pre-wrap';
            const lines = Array.isArray(c.lines) ? c.lines : [];
            /* textContent (never innerHTML) — curator lyrics are untrusted text. */
            body.textContent = lines.join('\n');
            block.appendChild(body);

            container.appendChild(block);
        });
    }

    const offSong = store.subscribe('song', render);
    const offComps = store.subscribe('components', render);
    render();

    return function teardown() {
        offSong();
        offComps();
        container.innerHTML = '';
    };
}
