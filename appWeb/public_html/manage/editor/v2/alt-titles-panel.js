/* ==========================================================================
 *  alt-titles-panel.js — "Alternative titles" in the v2 editor's Metadata
 *                        tab (#1669, epic #832)
 *
 *  ELI5: lets a curator record other names a song is known by — "Amazing
 *  Grace" is also catalogued as "Faith's Review and Expectation" — a small
 *  growable list, not a second Title field.
 *
 *  WHY THIS EXISTS. `tblSongAlternativeTitles` (#832,
 *  `migrate-alternative-titles.php`) has had a complete READ half since it
 *  landed — the public song page's "Also known as" line, the OG image, and
 *  the #832 search boost (a query matching an alt title ranks the song top)
 *  — but until now NOTHING in either editor could ADD a row: the only
 *  `INSERT` anywhere in the tree was api2.php's `duplicate_song` action's
 *  copy of an EXISTING song's rows, which can never create a first one.
 *  This panel, plus its three api2.php actions (`song_alt_titles` /
 *  `song_alt_title_add` / `song_alt_title_delete`), is that write path's
 *  first UI, modelled line-for-line on external-ids-panel.js (the sibling
 *  #1741 P5b panel this tab already carries).
 *
 *  WHY NOT RULE #43's FIND-OR-CREATE PICKER. An alt title is per-song FREE
 *  TEXT — a title STRING, not a reference to a registry entity (tblTunes,
 *  tblPublishers, tblMusicians, …) that could be shared or looked up across
 *  songs. There is nothing to search or dedupe against beyond THIS song's
 *  own existing alts, which the server's `uq_song_title` UNIQUE key already
 *  enforces — see includes/song_alt_titles.php's doc-block for the full
 *  reasoning.
 *
 *  WHY `api` IS INJECTED RATHER THAN IMPORTED. Unlike external-ids-panel.js
 *  (which imports `editorApi` directly from api-client.js), this panel
 *  takes its api client as an injected `opts.api` — the SAME shape
 *  metadata-tab.js itself receives from its own caller. Either shape
 *  satisfies rule #31 (no raw `fetch`, always go through the shared
 *  client); this file's specific shape is what its build spec calls for.
 *
 *  mountAltTitlesPanel(container, { api, songId, toast }) -> teardown fn
 *
 *  @link .claude/wave2-importer-editor-fidelity-plan.md §2                   the #1669 build spec this file implements
 *  @link appWeb/public_html/manage/editor/api2.php                          song_alt_titles / song_alt_title_add / song_alt_title_delete
 *  @link appWeb/public_html/includes/song_alt_titles.php                    the shared write core this panel's requests ultimately reach
 *  @link appWeb/public_html/manage/editor/v2/external-ids-panel.js          the sibling panel this file's shape mirrors
 * ========================================================================== */

/**
 * Mount the panel.
 *
 * @param {HTMLElement} container Where to append. The Metadata tab wipes its
 *   own container on every `song`-slice change, so it calls the returned
 *   teardown and re-mounts rather than relying on this to survive.
 * @param {{api: object, songId: string, toast?: Function}} opts
 *   `api` — the injected editorApi client (see WHY note above; rule #31 —
 *   this panel never calls raw `fetch` itself).
 * @returns {Function} teardown
 */
export function mountAltTitlesPanel(container, opts) {
    const api    = opts && opts.api;
    const songId = opts && opts.songId ? String(opts.songId) : '';
    const toast  = (opts && opts.toast) || function () {};
    let disposed = false;

    const wrap = document.createElement('div');
    wrap.className = 'col-12';

    const fieldset = document.createElement('fieldset');
    fieldset.className = 'border rounded p-3 mt-3';
    /* A real <legend>, like song-key-panel.js/external-ids-panel.js's own —
       becomes the accessible group name for every control inside.
       https://developer.mozilla.org/docs/Web/HTML/Element/fieldset */
    const legend = document.createElement('legend');
    legend.className = 'float-none w-auto px-2 h6 mb-0';
    legend.textContent = 'Alternative titles';
    fieldset.appendChild(legend);

    /* role="list" keeps an unstyled (list-style:none) <ul> announced as a
       list by VoiceOver/Safari. https://www.scottohara.me/blog/2019/01/12/lists-and-safari.html */
    const list = document.createElement('ul');
    list.setAttribute('role', 'list');
    list.className = 'list-unstyled mb-2 small';

    const status = document.createElement('div');
    status.className = 'form-text small';
    status.setAttribute('role', 'status');
    status.textContent = 'Loading…';

    /* ---- add row --------------------------------------------------------- */
    const addRow = document.createElement('div');
    addRow.className = 'row g-2 align-items-end mt-1';

    const titleCol = document.createElement('div');
    titleCol.className = 'col-12 col-sm-5';
    const titleLab = document.createElement('label');
    titleLab.className = 'visually-hidden';
    titleLab.htmlFor = 'ed2-alttitle-title';
    titleLab.textContent = 'Alternative title';
    const titleInput = document.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'form-control form-control-sm';
    titleInput.id = 'ed2-alttitle-title';
    titleInput.placeholder = 'Also known as…';
    titleInput.maxLength = 255;
    titleCol.append(titleLab, titleInput);

    const langCol = document.createElement('div');
    langCol.className = 'col-12 col-sm-3';
    const langLab = document.createElement('label');
    langLab.className = 'visually-hidden';
    langLab.htmlFor = 'ed2-alttitle-language';
    langLab.textContent = 'Language (optional)';
    const langInput = document.createElement('input');
    langInput.type = 'text';
    langInput.className = 'form-control form-control-sm';
    langInput.id = 'ed2-alttitle-language';
    langInput.placeholder = 'Language (e.g. es)';
    langInput.maxLength = 35;
    langCol.append(langLab, langInput);

    const noteCol = document.createElement('div');
    noteCol.className = 'col-12 col-sm-2';
    const noteLab = document.createElement('label');
    noteLab.className = 'visually-hidden';
    noteLab.htmlFor = 'ed2-alttitle-note';
    noteLab.textContent = 'Note (optional)';
    const noteInput = document.createElement('input');
    noteInput.type = 'text';
    noteInput.className = 'form-control form-control-sm';
    noteInput.id = 'ed2-alttitle-note';
    noteInput.placeholder = 'Note';
    noteInput.maxLength = 255;
    noteCol.append(noteLab, noteInput);

    const btnCol = document.createElement('div');
    btnCol.className = 'col-12 col-sm-2';
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary w-100';
    addBtn.textContent = 'Add';
    btnCol.appendChild(addBtn);

    addRow.append(titleCol, langCol, noteCol, btnCol);

    fieldset.append(list, status, addRow);
    wrap.appendChild(fieldset);
    container.appendChild(wrap);

    let rows = [];   // the currently-shown altTitle rows, server shape (see api2.php song_alt_title* cases)

    function setAddEnabled(on) {
        addBtn.disabled = !on;
        titleInput.disabled = !on;
        langInput.disabled = !on;
        noteInput.disabled = !on;
    }

    function renderRows() {
        list.innerHTML = '';
        if (!rows.length) {
            const li = document.createElement('li');
            li.className = 'text-muted fst-italic';
            li.textContent = 'No alternative titles recorded yet.';
            list.appendChild(li);
            return;
        }
        rows.forEach((row) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'listitem');
            li.className = 'd-flex align-items-center gap-2 mb-1';

            const titleSpan = document.createElement('span');
            titleSpan.className = 'text-truncate';
            titleSpan.textContent = row.title;

            const metaBits = [];
            if (row.language) { metaBits.push(row.language); }
            if (row.note) { metaBits.push(row.note); }
            const metaSpan = document.createElement('span');
            metaSpan.className = 'text-body-secondary text-truncate';
            metaSpan.textContent = metaBits.length ? ('(' + metaBits.join(' — ') + ')') : '';

            const rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn btn-sm btn-outline-danger py-0 px-1 ms-auto';
            rm.textContent = '✕';
            rm.setAttribute('aria-label', 'Remove alternative title ' + row.title);
            rm.addEventListener('click', () => onRemove(row.id));

            li.append(titleSpan, metaSpan, rm);
            list.appendChild(li);
        });
    }

    function onRemove(id) {
        api.deleteAltTitle(songId, id).then(() => {
            if (disposed) return;
            rows = rows.filter((r) => Number(r.id) !== Number(id));
            renderRows();
        }).catch((e) => {
            if (disposed) return;
            toast('Could not remove alternative title: ' + e.message, 'danger');
        });
    }

    function onAdd() {
        const title = titleInput.value.trim();
        if (!title) {
            toast('Enter an alternative title.', 'warning');
            return;
        }
        addBtn.disabled = true;
        api.addAltTitle(songId, title, langInput.value.trim(), noteInput.value.trim()).then((res) => {
            if (disposed) return;
            addBtn.disabled = false;
            /* `created === false` -> this exact title already existed for
               this song (uq_song_title collision, INSERT IGNORE server
               side) — an info toast, not an error: the curator's intent
               ("this song should have this alt title") is already
               satisfied. */
            if (res && res.created === false) {
                toast('Already recorded.', 'info');
                return;
            }
            /* Prepend the ECHOED row — never the typed input. The server
               is what decides the final stored shape. */
            if (res && res.altTitle) {
                rows = [res.altTitle].concat(rows);
                renderRows();
                titleInput.value = '';
                langInput.value = '';
                noteInput.value = '';
            }
            toast('Alternative title added.', 'success');
        }).catch((e) => {
            if (disposed) return;
            addBtn.disabled = false;
            /* Rule #35 — the STATUS picks the toast kind, never a prose
               match; the server's own sentence is still what's shown,
               because for a 422 that sentence is precisely what tells the
               curator what was wrong (e.g. "That is already the song's
               main title."). */
            toast(e.message, e.status === 422 ? 'warning' : 'danger');
        });
    }
    addBtn.addEventListener('click', onAdd);

    /* ---- initial load ----------------------------------------------------
       Branches on the `tableMissing` FLAG (rule #35 — never on prose): an
       un-migrated install gets one muted line and nothing to interact
       with, matching external-ids-panel.js's own degrade. */
    setAddEnabled(false);
    api.listAltTitles(songId).then((res) => {
        if (disposed) return;
        if (res && res.tableMissing) {
            status.textContent = 'Alternative-title storage is not migrated on this install.';
            list.innerHTML = '';
            addRow.style.display = 'none';
            return;
        }
        rows = (res && Array.isArray(res.altTitles)) ? res.altTitles : [];
        status.textContent = '';
        renderRows();
        setAddEnabled(true);
    }).catch((e) => {
        if (disposed) return;
        status.textContent = 'Could not load alternative titles (' + (e.status || '?') + ').';
    });

    return function teardown() {
        disposed = true;
        addBtn.removeEventListener('click', onAdd);
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
    };
}
