/* ==========================================================================
 *  counterparts-panel.js — the v2 Song Editor "Cross-book counterparts" panel
 *  (#1608, ported from v1's inline #song-link-suggestions sidebar panel)
 *
 *  ELI5: this is the "these are the same hymn, just printed in a different
 *  songbook" feature. A curator can link e.g. MP-0031 (Amazing Grace) to
 *  CH-0376 (also Amazing Grace, different number) so the app knows they're
 *  counterparts, and can accept or dismiss the computer's own similar-title
 *  guesses about which OTHER songs might be the same hymn.
 *
 *  WHY THIS EXISTS (#1608): v1's inline "Suggested counterparts" panel
 *  (manage/editor/editor.js:3228-3551, HTML at index.php:1176-1211) was the
 *  ONLY place a curator could reach get_song_links / add_song_link /
 *  remove_song_link / suggest_song_links / dismiss_song_link_suggestion —
 *  but manage/editor/index.php has 302-redirected every editor visit to THIS
 *  v2 shell since #1601 landed, so the panel (and those five actions) has
 *  been live-but-unreachable for as long as v2 has been the default editor —
 *  the #1565/#1580 "looks alive, isn't" failure class, just one layer up
 *  (the whole PANEL, not one button inside it). #1220 explicitly kept the
 *  panel when the standalone suggestions PAGE was absorbed into
 *  /manage/duplicate-songs (#1215): duplicate-songs is a PULL review queue a
 *  curator visits deliberately; this panel is a PUSH surface that appears
 *  with the most context, the moment a curator has the song open. This file
 *  is the "port the panel" resolution recorded in
 *  .claude/wave4-prelaunch-plan.md §3/§6 Block C (option a).
 *
 *  RULE #22 (song_similarity.php is the ONE scorer) — this file computes NO
 *  similarity score. It only renders what api2.php's song_link_suggestions
 *  GET returns, which is itself a pure read of the pre-scored
 *  tblSongLinkSuggestions table the offline batch build-song-link-
 *  suggestions.php fills.
 *
 *  RULE #35 — failure KIND is read from `err.status` (409 = the two songs
 *  are already in different counterpart groups; 404 = target not found),
 *  never by matching the server's error sentence, which stays free to
 *  reword without silently degrading this panel's messaging.
 *
 *  mountCounterpartsPanel(container, { api, songId, toast, getSongs }) -> teardown fn
 *  `getSongs()` (optional) supplies the datalist source — editor2.php wires
 *  it to the sidebar's already-loaded slim index (sidebar.js's
 *  getAllSongs()), so this panel fetches nothing of its own for that list,
 *  mirroring how the Metadata tab's move-target `<select>` reuses
 *  getSongbooks() rather than re-querying the corpus (#1679 H1).
 *
 *  ⚠️ VERIFICATION NOTE (D5(ii) / D3 class — see the commit body): this file
 *  is exercised here only by static analysis (`node --check`, eslint) plus a
 *  re-run of the SERVER contract (api2.php) — there is no browser automation
 *  in this environment. Typing into the add-by-SongId field, the datalist
 *  picker, focus retention, and the Link/Dismiss button wiring have NOT been
 *  clicked by a human. Treat this commit as needing one manual browser pass
 *  before merge, exactly like credits-tab.js's #960 3-field rewrite did.
 * ========================================================================== */

export function mountCounterpartsPanel(container, opts) {
    const { api, songId } = opts;
    const toast = opts.toast || function () {};
    const getSongs = typeof opts.getSongs === 'function' ? opts.getSongs : function () { return []; };

    /* Guards every async callback below against acting on a torn-down panel
       — the same shape as sidebar.js's out-of-order-fetch guards, needed
       here because a song switch can tear this panel down while a
       song_links / song_link_suggestions fetch is still in flight. */
    let destroyed = false;

    container.innerHTML = '';

    const heading = document.createElement('h3');
    heading.className = 'h6 mb-2';
    heading.innerHTML = '<i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Cross-book counterparts';

    const intro = document.createElement('p');
    intro.className = 'text-muted small';
    intro.textContent = 'Link this song to its appearances in other songbooks (same hymn, ' +
        'different number). Distinct from external links above — this is a same-catalogue relationship.';

    const listEl = document.createElement('div');
    listEl.id = 'v2-counterparts-list';
    listEl.className = 'mb-2';

    const addRow = document.createElement('div');
    addRow.className = 'input-group input-group-sm mb-3';
    const addInput = document.createElement('input');
    addInput.type = 'text';
    addInput.className = 'form-control';
    addInput.placeholder = 'Target Song ID (e.g. CH-0376)';
    addInput.setAttribute('aria-label', 'Target Song ID');
    addInput.setAttribute('list', 'v2-counterparts-datalist');
    const datalistEl = document.createElement('datalist');
    datalistEl.id = 'v2-counterparts-datalist';
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-outline-primary';
    addBtn.innerHTML = '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Link';
    addRow.append(addInput, datalistEl, addBtn);

    const sugBox = document.createElement('div');
    sugBox.className = 'mt-2';
    sugBox.style.display = 'none';
    const sugIntro = document.createElement('div');
    sugIntro.className = 'form-text mb-2';
    sugIntro.innerHTML = '<i class="bi bi-lightbulb me-1" aria-hidden="true"></i>' +
        'Suggested counterparts — similar titles in other songbooks:';
    const sugList = document.createElement('div');
    sugBox.append(sugIntro, sugList);

    container.append(heading, intro, listEl, addRow, sugBox);

    /* Datalist source: the sidebar's already-loaded slim index (id/title/
       songbook), mirroring v1's `songData.songs` client-side array
       (editor.js:3269) without re-fetching anything — the sidebar already
       paid for this load. */
    function fillDatalist() {
        datalistEl.innerHTML = '';
        getSongs().forEach((s) => {
            if (!s || s.id === songId) { return; }
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = (s.title || '') + ' (' + (s.songbook || '?') + ')';
            datalistEl.appendChild(opt);
        });
    }
    fillDatalist();

    function renderLinks(links) {
        listEl.innerHTML = '';
        if (!links.length) {
            const empty = document.createElement('span');
            empty.className = 'text-muted small';
            empty.textContent = 'No cross-book counterparts linked yet.';
            listEl.appendChild(empty);
            return;
        }
        links.forEach((ln) => {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 mb-1';

            const badge = document.createElement('span');
            badge.className = 'badge bg-secondary';
            badge.textContent = ln.songbook || '?';

            const info = document.createElement('span');
            info.className = 'flex-grow-1 small';
            const strong = document.createElement('strong');
            strong.textContent = ln.songId;
            info.appendChild(strong);
            let suffix = ' — ' + (ln.title || '(unknown)');
            if (ln.number !== null && ln.number !== undefined) { suffix += ' (#' + ln.number + ')'; }
            info.appendChild(document.createTextNode(suffix));

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-outline-danger';
            removeBtn.title = 'Unlink this counterpart';
            removeBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            removeBtn.addEventListener('click', () => removeLink(ln.songId));

            row.append(badge, info, removeBtn);
            listEl.appendChild(row);
        });
    }

    function renderSuggestions(suggestions) {
        sugList.innerHTML = '';
        if (!suggestions.length) { sugBox.style.display = 'none'; return; }
        suggestions.forEach((sg) => {
            const other = sg.other || {};
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 mb-1 small';

            const scoreBadge = document.createElement('span');
            scoreBadge.className = 'badge bg-info-subtle text-info-emphasis';
            scoreBadge.textContent = Math.round((sg.score || 0) * 100) + '%';
            scoreBadge.title = 'Title score ' + Math.round((sg.titleScore || 0) * 100) +
                '% · lyrics score ' + Math.round((sg.lyricsScore || 0) * 100) + '%';

            const info = document.createElement('span');
            info.className = 'flex-grow-1';
            const idChip = document.createElement('strong');
            idChip.textContent = other.songId || '';
            info.appendChild(idChip);
            let label = ' — ' + (other.title || '(untitled)');
            if (other.number !== null && other.number !== undefined) {
                label += ' (' + (other.songbook || '?') + ' #' + other.number + ')';
            } else if (other.songbook) {
                label += ' (' + other.songbook + ')';
            }
            info.appendChild(document.createTextNode(label));

            const linkBtn = document.createElement('button');
            linkBtn.type = 'button';
            linkBtn.className = 'btn btn-sm btn-outline-success';
            linkBtn.title = 'Link as the same hymn';
            linkBtn.innerHTML = '<i class="bi bi-link-45deg" aria-hidden="true"></i>';
            linkBtn.addEventListener('click', () => linkFromSuggestion(other.songId));

            const dismissBtn = document.createElement('button');
            dismissBtn.type = 'button';
            dismissBtn.className = 'btn btn-sm btn-outline-secondary';
            dismissBtn.title = 'Dismiss — different hymns';
            dismissBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            dismissBtn.addEventListener('click', () => dismissSuggestion(other.songId));

            row.append(scoreBadge, info, linkBtn, dismissBtn);
            sugList.appendChild(row);
        });
        sugBox.style.display = '';
    }

    async function refreshLinks() {
        try {
            const res = await api.songLinks(songId);
            if (destroyed) { return; }
            renderLinks(res.links || []);
        } catch (e) {
            if (destroyed) { return; }
            listEl.innerHTML = '';
            const err = document.createElement('span');
            err.className = 'text-danger small';
            err.textContent = 'Could not load counterparts: ' + e.message;
            listEl.appendChild(err);
        }
    }

    /* Suggestions are best-effort UX sugar, not the core feature (the link
       list above is): a failure here (or an un-migrated tableMissing:true)
       hides the box rather than surfacing an error, matching v1's own
       catch-and-hide (editor.js:3525-3527). */
    async function refreshSuggestions() {
        try {
            const res = await api.songLinkSuggestions(songId);
            if (destroyed) { return; }
            if (res.tableMissing) { sugBox.style.display = 'none'; return; }
            renderSuggestions(res.suggestions || []);
        } catch (_e) {
            if (destroyed) { return; }
            sugBox.style.display = 'none';
        }
    }

    async function addLink() {
        const targetId = addInput.value.trim();
        if (!targetId) { toast('Enter a target Song ID.', 'warning'); return; }
        if (targetId === songId) { toast('A song cannot be a counterpart of itself.', 'warning'); return; }
        try {
            /* v1's UI never sent a `note` on this form either (editor.js:3363
               posts only sourceSongId/targetSongId) — the API accepts one,
               nothing in either editor's UI writes one from here. */
            await api.songLinkAdd(songId, targetId);
            addInput.value = '';
            toast('Cross-book counterpart linked.', 'success');
            await refreshLinks();
            await refreshSuggestions();
        } catch (e) {
            /* Rule #35 — branch on e.status, never on e.message's wording. */
            if (e.status === 409) {
                toast(e.message, 'danger');
            } else if (e.status === 404) {
                toast('Song "' + targetId + '" was not found.', 'danger');
            } else {
                toast('Could not link counterpart: ' + e.message, 'danger');
            }
        }
    }

    async function removeLink(targetSongId) {
        try {
            await api.songLinkRemove(targetSongId);
            toast('Cross-book counterpart unlinked.', 'success');
            await refreshLinks();
        } catch (e) {
            toast('Could not unlink counterpart: ' + e.message, 'danger');
        }
    }

    async function linkFromSuggestion(targetSongId) {
        if (!targetSongId) { return; }
        try {
            await api.songLinkAdd(songId, targetSongId);
            toast('Linked as same hymn.', 'success');
            await refreshLinks();
            await refreshSuggestions();
        } catch (e) {
            toast(e.status === 409 ? e.message : ('Could not link from suggestion: ' + e.message), 'danger');
        }
    }

    async function dismissSuggestion(targetSongId) {
        if (!targetSongId) { return; }
        try {
            await api.songLinkSuggestionDismiss(songId, targetSongId);
            toast('Suggestion dismissed.', 'info');
            await refreshSuggestions();
        } catch (e) {
            toast('Could not dismiss suggestion: ' + e.message, 'danger');
        }
    }

    addBtn.addEventListener('click', addLink);
    /* Enter-to-submit on the input, matching v1's interaction model
       (editor.js:3544-3549) and the Translations input beside it. */
    addInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); addLink(); }
    });

    refreshLinks();
    refreshSuggestions();

    return function teardown() {
        destroyed = true;
        container.innerHTML = '';
    };
}
