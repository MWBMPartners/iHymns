/* ==========================================================================
 *  external-ids-panel.js — "Recording / external IDs" in the v2 editor's
 *                          Metadata tab (#1741 P5b)
 *
 *  ELI5: lets a curator record a song's Spotify id, ISRC, MusicBrainz
 *  recording id, and so on — a small growable list, not one form field per
 *  provider.
 *
 *  WHY THIS EXISTS. `tblSongExternalIds` (#1741 D5, `migrate-song-external-
 *  ids.php`) has held rows since the #1747 backfill mirrored grandfathered
 *  identifiers into it, and the #1749 ISRC dual-write mirror
 *  (`includes/song_external_ids.php`, P5d) keeps ONE of those rows in sync
 *  with `tblSongs.Isrc` — but until now NOTHING in either editor could ever
 *  SEE or manually ADD a row. This panel, plus its three api2.php actions
 *  (`song_external_ids` / `song_external_id_add` / `song_external_id_delete`),
 *  is that store's first UI write path.
 *
 *  WHY IT IS NOT THE LINKS TAB. `links-tab.js`'s rows carry a `typeId` FK
 *  into `tblExternalLinkTypes` — a completely different registry, keyed by
 *  URL PATTERN (#833/#845), not by identifier SHAPE. Reusing
 *  `iHymnsExtLinksEditor` here would mean fabricating `typeId`s for a store
 *  that has none of its own — a fork-by-misuse, not reuse (CLAUDE.md's
 *  modularity rule cuts both ways: share what genuinely is the same thing,
 *  don't force two different things to share one editor).
 *
 *  WHY THE DROPDOWN HAS NO HARDCODED PROVIDER LIST. It is built entirely
 *  from `window._iHymnsRecordingIdTypes` — the SAME registry
 *  (`includes/media_identifiers.php`'s `RECORDING_EXTERNAL_ID_TYPES`)
 *  `api2.php`'s `song_external_id_add` validates every write against,
 *  emitted once by `editor2.php` (rule #35's "server-derive the vocab, no
 *  second list", the SAME convention `window._iHymnsLinkTypes` already
 *  uses for the Links tab). A hardcoded fallback array in THIS file would
 *  be exactly the regression that rule bans — a dropdown offering a
 *  provider slug the server would then 422 on, or missing one the server
 *  would happily accept. `tests/test-v2-external-ids-ui.js` asserts NONE of
 *  the registry's slugs appear as a quoted string literal anywhere in this
 *  file.
 *
 *  mountExternalIdsPanel(container, { songId, toast, onIsrcDenorm }) -> teardown fn
 *
 *  @link .claude/catalogue-1741-P5-plan.md §2.4                                  the build spec this file implements
 *  @link appWeb/public_html/manage/editor/api2.php                               song_external_ids / song_external_id_add / song_external_id_delete
 *  @link appWeb/public_html/manage/editor/editor2.php                            window._iHymnsRecordingIdTypes emit
 *  @link appWeb/public_html/manage/editor/v2/song-key-panel.js                   the sibling dynamically-imported-panel this file's shape mirrors
 * ========================================================================== */

import { editorApi } from './api-client.js';

/**
 * Mount the panel.
 *
 * @param {HTMLElement} container Where to append. The Metadata tab wipes its
 *   own container on every `song`-slice change, so it calls the returned
 *   teardown and re-mounts rather than relying on this to survive.
 * @param {{songId: string, toast?: Function, onIsrcDenorm?: Function}} opts
 *   `onIsrcDenorm` — #1749 full unification: called with the server's
 *   projected primary-ISRC value (`?string`) whenever an add/remove response
 *   carries an `isrcDenorm` key (key-presence, rule #35 — never inferred
 *   from `idType` alone, since the SERVER is what decides whether a given
 *   add/delete touched an isrc row). Lets the Metadata tab's `#meta-isrc` box
 *   stay in sync with a change made from THIS panel instead of only on next
 *   reload. Optional — defaults to a no-op so this panel still mounts
 *   standalone in a test harness.
 * @returns {Function} teardown
 */
export function mountExternalIdsPanel(container, opts) {
    const songId = opts && opts.songId ? String(opts.songId) : '';
    const toast  = (opts && opts.toast) || function () {};
    const onIsrcDenorm = (opts && opts.onIsrcDenorm) || function () {};
    let disposed = false;

    /* The registry the server itself validates against (editor2.php's
       server-derived emit) — slug -> {label, scope}. An empty object (script
       load failure, or a page that somehow never ran the PHP emit) degrades
       to "no dropdown options, Add disabled", never a hand-typed fallback
       list — see the file header. */
    const registry = (window._iHymnsRecordingIdTypes && typeof window._iHymnsRecordingIdTypes === 'object')
        ? window._iHymnsRecordingIdTypes : {};

    const wrap = document.createElement('div');
    wrap.className = 'col-12';

    const fieldset = document.createElement('fieldset');
    fieldset.className = 'border rounded p-3 mt-3';
    /* A real <legend>, like song-key-panel.js's "Musical key" — it becomes
       the accessible group name for every control inside.
       https://developer.mozilla.org/docs/Web/HTML/Element/fieldset */
    const legend = document.createElement('legend');
    legend.className = 'float-none w-auto px-2 h6 mb-0';
    legend.textContent = 'Recording / external IDs';
    fieldset.appendChild(legend);

    /* role="list" on a styled (list-style:none) <ul> keeps it announced as a
       list by VoiceOver/Safari, which otherwise strips list semantics from
       an unstyled list. https://www.scottohara.me/blog/2019/01/12/lists-and-safari.html */
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

    const typeCol = document.createElement('div');
    typeCol.className = 'col-12 col-sm-4';
    const typeLab = document.createElement('label');
    typeLab.className = 'visually-hidden';
    typeLab.htmlFor = 'ed2-extid-type';
    typeLab.textContent = 'External-ID type';
    const typeSel = document.createElement('select');
    typeSel.className = 'form-select form-select-sm';
    typeSel.id = 'ed2-extid-type';
    typeCol.append(typeLab, typeSel);

    const valCol = document.createElement('div');
    valCol.className = 'col-12 col-sm-5';
    const valLab = document.createElement('label');
    valLab.className = 'visually-hidden';
    valLab.htmlFor = 'ed2-extid-value';
    valLab.textContent = 'External-ID value';
    const valInput = document.createElement('input');
    valInput.type = 'text';
    valInput.className = 'form-control form-control-sm';
    valInput.id = 'ed2-extid-value';
    valInput.placeholder = 'Value';
    valCol.append(valLab, valInput);

    const btnCol = document.createElement('div');
    btnCol.className = 'col-12 col-sm-3';
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary w-100';
    addBtn.textContent = 'Add';
    btnCol.appendChild(addBtn);

    addRow.append(typeCol, valCol, btnCol);

    fieldset.append(list, status, addRow);
    wrap.appendChild(fieldset);
    container.appendChild(wrap);

    /* Options sorted by LABEL (not slug) — a curator scans "Apple Music …
       Spotify … YouTube Music", not "apple-music … spotify … youtube-music". */
    function fillTypeOptions() {
        typeSel.innerHTML = '';
        Object.keys(registry)
            .map((slug) => ({ slug: slug, label: (registry[slug] && registry[slug].label) || slug }))
            .sort((a, b) => a.label.localeCompare(b.label))
            .forEach((e) => {
                const o = document.createElement('option');
                o.value = e.slug;
                o.textContent = e.label;
                typeSel.appendChild(o);
            });
    }
    fillTypeOptions();

    let rows = [];   // the currently-shown externalId rows, server shape (see api2.php ed2_songExternalIdRowShape)

    function setAddEnabled(on) {
        const hasVocab = Object.keys(registry).length > 0;
        addBtn.disabled = !on || !hasVocab;
        typeSel.disabled = !hasVocab;
        valInput.disabled = !hasVocab;
    }

    function renderRows() {
        list.innerHTML = '';
        if (!rows.length) {
            const li = document.createElement('li');
            li.className = 'text-muted fst-italic';
            li.textContent = 'No external IDs recorded yet.';
            list.appendChild(li);
            return;
        }
        rows.forEach((row) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'listitem');
            li.className = 'd-flex align-items-center gap-2 mb-1';

            const label = document.createElement('code');
            label.className = 'text-body-secondary';
            label.textContent = row.label;

            const valSpan = document.createElement('span');
            valSpan.className = 'text-truncate';
            if (row.url) {
                const a = document.createElement('a');
                a.href = row.url;
                /* external + nofollow: this leaves iHymns to a third-party
                   provider's own page, which we neither vouch for nor want
                   search engines to attribute rank to; noopener is the
                   standard target=_blank tab-nabbing defence.
                   https://developer.mozilla.org/docs/Web/HTML/Attributes/rel */
                a.rel = 'noopener nofollow external';
                a.target = '_blank';
                a.textContent = row.idValue;
                valSpan.appendChild(a);
            } else {
                valSpan.textContent = row.idValue;
            }

            const rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn btn-sm btn-outline-danger py-0 px-1 ms-auto';
            rm.textContent = '✕';
            rm.setAttribute('aria-label', 'Remove ' + row.label + ' ' + row.idValue);
            rm.addEventListener('click', () => onRemove(row.id));

            li.append(label, valSpan, rm);
            list.appendChild(li);
        });
    }

    function onRemove(id) {
        editorApi.deleteExternalId(songId, id).then((res) => {
            if (disposed) return;
            rows = rows.filter((r) => Number(r.id) !== Number(id));
            renderRows();
            /* #1749 — key-PRESENCE, never inferred from the removed row's own
               idType: the server is what decides whether this delete touched
               an isrc row (rule #35). */
            if (res && ('isrcDenorm' in res)) { onIsrcDenorm(res.isrcDenorm); }
        }).catch((e) => {
            if (disposed) return;
            toast('Could not remove external id: ' + e.message, 'danger');
        });
    }

    function onAdd() {
        const idType = typeSel.value;
        const idValue = valInput.value.trim();
        if (!idType || !idValue) {
            toast('Pick a type and enter a value.', 'warning');
            return;
        }
        addBtn.disabled = true;
        editorApi.addExternalId(songId, idType, idValue).then((res) => {
            if (disposed) return;
            addBtn.disabled = false;
            /* `created === false` -> the exact (idType, idValue) pair already
               existed (uq_Song_Type_Value collision, INSERT IGNORE server
               side) — an info toast, not an error: the curator's intent
               ("this song should have this id") is already satisfied. */
            if (res && res.created === false) {
                toast('Already recorded.', 'info');
                return;
            }
            /* Prepend the ECHOED row — never the typed input. The server
               canonicalises (e.g. an ISRC), so what it hands back is the
               value actually stored. */
            if (res && res.externalId) {
                rows = [res.externalId].concat(rows);
                renderRows();
                valInput.value = '';
            }
            /* #1749 — key-PRESENCE, never inferred from the typeSel value the
               curator picked: the server is what decides whether this add
               touched an isrc row (rule #35). */
            if (res && ('isrcDenorm' in res)) { onIsrcDenorm(res.isrcDenorm); }
            toast('External id added.', 'success');
        }).catch((e) => {
            if (disposed) return;
            addBtn.disabled = false;
            /* Rule #35 — the STATUS picks the toast kind, never a prose
               match; the server's own sentence is still what's shown,
               because for a 422 that sentence is precisely what tells the
               curator what shape was expected. */
            toast(e.message, e.status === 422 ? 'warning' : 'danger');
        });
    }
    addBtn.addEventListener('click', onAdd);

    /* ---- initial load ----------------------------------------------------
       Branches on the `tableMissing` FLAG (rule #35 — never on prose): an
       un-migrated install gets one muted line and nothing to interact with,
       matching the plan's "render one muted line and stop". */
    setAddEnabled(false);
    editorApi.listExternalIds(songId).then((res) => {
        if (disposed) return;
        if (res && res.tableMissing) {
            status.textContent = 'External-ID storage is not migrated on this install.';
            list.innerHTML = '';
            addRow.style.display = 'none';
            return;
        }
        rows = (res && Array.isArray(res.externalIds)) ? res.externalIds : [];
        status.textContent = '';
        renderRows();
        setAddEnabled(true);
    }).catch((e) => {
        if (disposed) return;
        status.textContent = 'Could not load external ids (' + (e.status || '?') + ').';
    });

    return function teardown() {
        disposed = true;
        addBtn.removeEventListener('click', onAdd);
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
    };
}
