/* ==========================================================================
 *  revisions-tab.js — the v2 Song Editor "Revisions" tab (#1200, Phase 4c;
 *  diff view #1628 item 4)
 *
 *  Read-only history of the song's saved snapshots (tblSongRevisions, written by
 *  every v2 mutation on a coalesced ~15s debounce), newest first, with a Restore
 *  action per entry. Each snapshot is the FULL record (scalars + sections +
 *  credits + tags + links), so a restore brings the whole song back to that
 *  state atomically (server-side). After a confirmed restore the page reloads so
 *  every tab re-hydrates from the restored state.
 *
 *  #1628 item 4 — each row also grows a "Changes" button that expands an
 *  inline field-level diff (fetched from api2's revision_get, computed by the
 *  pure diffSnapshots() below) BEFORE a curator commits to Restore. This is
 *  the diff view this file's own doc-comment used to say was still missing.
 *
 *  mountRevisionsTab(container, { api, songId, toast }) -> teardown fn
 *  diffSnapshots(before, after) -> pure field-level diff (no DOM; node-testable
 *  — see tests/test-revision-diff.js)
 * ========================================================================== */

export function mountRevisionsTab(container, opts) {
    const { api, songId } = opts;
    const toast = opts.toast || function () {};

    /* ---- C3/C4 (#1122) — persistent view switch (History / Field history),
     *  built ONCE at mount: a small header holding the switch, followed by a
     *  `contentEl` div the active view renders into. Every render/error path
     *  in this file now targets `contentEl` (never `container` directly) so
     *  the switch survives every re-render — only `teardown()` at the bottom
     *  still clears the whole `container`. */
    container.innerHTML = '';

    const header = document.createElement('div');
    header.className = 'mb-2';

    const switchGroup = document.createElement('div');
    switchGroup.className = 'btn-group btn-group-sm';
    switchGroup.setAttribute('role', 'group');
    switchGroup.setAttribute('aria-label', 'Revisions view');

    const historyBtn = document.createElement('button');
    historyBtn.type = 'button';
    historyBtn.className = 'btn btn-outline-secondary active';
    historyBtn.setAttribute('aria-pressed', 'true');
    historyBtn.textContent = 'History';

    const fieldHistoryBtn = document.createElement('button');
    fieldHistoryBtn.type = 'button';
    fieldHistoryBtn.className = 'btn btn-outline-secondary';
    fieldHistoryBtn.setAttribute('aria-pressed', 'false');
    fieldHistoryBtn.textContent = 'Field history';

    switchGroup.append(historyBtn, fieldHistoryBtn);
    header.appendChild(switchGroup);

    const contentEl = document.createElement('div');

    container.append(header, contentEl);

    /** Switches the active view. 'history' re-runs the existing load() path
     *  (unchanged behaviour, just now targeting contentEl); 'field' shows the
     *  fetch-once-and-cache Field history view (below). */
    function setMode(mode) {
        const isHistory = mode === 'history';
        historyBtn.classList.toggle('active', isHistory);
        historyBtn.setAttribute('aria-pressed', isHistory ? 'true' : 'false');
        fieldHistoryBtn.classList.toggle('active', !isHistory);
        fieldHistoryBtn.setAttribute('aria-pressed', !isHistory ? 'true' : 'false');
        if (isHistory) {
            load();
        } else {
            showFieldHistory();
        }
    }

    historyBtn.addEventListener('click', () => setMode('history'));
    fieldHistoryBtn.addEventListener('click', () => setMode('field'));

    function fmtAction(a) {
        return String(a || 'edit').replace(/[_.]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
    }

    async function load() {
        contentEl.innerHTML = '<p class="text-muted small mb-0">Loading history…</p>';
        try {
            const res = await api.listRevisions(songId);
            render(res.revisions || []);
        } catch (e) {
            contentEl.innerHTML = '';
            const err = document.createElement('div');
            err.className = 'alert alert-danger py-2 small mb-0';
            err.textContent = 'Could not load revisions: ' + e.message;
            contentEl.appendChild(err);
        }
    }

    /**
     * Restore — with the v1/v2 semantics difference spelled out (#1628 item 4).
     *
     * ELI5: restoring here gives you the song AS IT WAS AFTER that edit. The old
     * editor gave you the song as it was BEFORE it. Same button, one step apart.
     *
     * Detail: v1's `restore_revision` restores `PreviousData` — "undo the change
     * this row recorded" (api.php:1284-1290, and v1 only offers Restore when
     * `previousData` exists). v2's `revision_restore` restores `NewData` — "put
     * the song back to this snapshot" (api2.php:2326).
     *
     * Both models are internally coherent; v2's is the clearer one and matches
     * how the entries are labelled. The hazard is purely the handover: a curator
     * carrying v1 muscle memory into v2 lands one revision off. The confirm
     * dialog says which one it is, at the moment it matters, rather than
     * relying on anyone having read a note — and now each row's "Changes"
     * button (below) lets a curator SEE the before/after pair before they
     * click Restore at all, closing the gap this doc-block used to describe as
     * still open (#1628 item 4 — `revision_get` returns the snapshot pair;
     * `revision_list` above stays metadata-only by design, one concern per
     * endpoint).
     */
    async function restore(rev) {
        const when = rev.createdAt || 'this version';
        if (!window.confirm(
            'Restore the song to how it looked AFTER the ' + fmtAction(rev.action)
            + ' on ' + when + '?\n\n'
            + 'This replaces the current sections, metadata, credits, tags and links '
            + 'with that version. (Media files are not affected.)\n\n'
            + 'Note: this restores the state AFTER that edit. The legacy editor\'s '
            + 'Restore went back to the state BEFORE it — so if you are used to the '
            + 'old editor, you may want the entry one row below this one.'
        )) {
            return;
        }
        try {
            await api.restoreRevision(rev.id, songId);
            toast('Restored — reloading…', 'success');
            window.location.reload();   // re-hydrate every tab from the restored state
        } catch (e) {
            toast('Restore failed: ' + e.message, 'danger');
        }
    }

    /**
     * "Changes" — expand/collapse an inline diff panel below one revision row
     * (#1628 item 4). First click fetches api2's revision_get and renders the
     * diff; a second click just toggles visibility (no re-fetch — the
     * snapshot pair for a given revisionId never changes).
     *
     * ELI5: tapping "Changes" opens a little report card under that row
     * showing exactly what was different before vs after, without leaving
     * the list or touching Restore.
     *
     * Detail: `err.status` (not the error prose) decides how a failure
     * renders (rule #35) — a 409 means this particular revision genuinely has
     * no snapshot to diff (revision_get's own "no snapshot" refusal, the same
     * one revision_restore uses), which is a calm notice, not a red toast.
     */
    async function toggleDiff(rev, panel) {
        if (panel.dataset.loaded === '1') {
            panel.classList.toggle('d-none');
            return;
        }
        panel.classList.remove('d-none');
        panel.innerHTML = '<p class="text-muted small mb-0">Loading changes…</p>';
        try {
            const res = await api.getRevision(rev.id, songId);
            renderDiffPanel(panel, res);
            panel.dataset.loaded = '1';
        } catch (e) {
            panel.innerHTML = '';
            const msg = document.createElement('p');
            msg.className = (e && e.status === 409) ? 'text-muted mb-0' : 'alert alert-danger py-2 small mb-0';
            msg.textContent = (e && e.status === 409)
                ? 'No snapshot recorded for this revision — nothing to compare.'
                : 'Could not load changes: ' + e.message;
            panel.appendChild(msg);
            /* A failed fetch has nothing cached worth reusing — let a retry
               click fetch again rather than freezing on the error forever. */
        }
    }

    /** Render one revision_get response ({revision, after, before,
     *  beforeSource}) into its diff panel. `beforeSource === 'none'` is a
     *  DESIGNED rendering (the song's first-ever revision, or a legacy
     *  install with nothing earlier recorded) — never an error (plan §A.1). */
    function renderDiffPanel(panel, res) {
        panel.innerHTML = '';
        if (res.beforeSource === 'none') {
            const p = document.createElement('p');
            p.className = 'text-muted mb-0';
            p.textContent = 'No earlier state recorded for this revision — it looks like the first '
                + 'saved snapshot for this song.';
            panel.appendChild(p);
            return;
        }

        const diff = diffSnapshots(res.before, res.after);

        if (diff.legacy) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle mb-2';
            badge.textContent = 'legacy snapshot — limited diff';
            panel.appendChild(badge);
        }

        if (!diff.hasChanges) {
            const p = document.createElement('p');
            p.className = 'text-muted mb-0 mt-1';
            p.textContent = 'No differences detected between these two saved states.';
            panel.appendChild(p);
            return;
        }

        if (diff.scalars.length) {
            panel.appendChild(buildScalarTable(diff.scalars));
        }
        if (diff.components.added.length || diff.components.removed.length || diff.components.changed.length) {
            panel.appendChild(buildComponentList(diff.components));
        }
        const nameGroups = [];
        Object.keys(diff.credits).forEach((role) => {
            nameGroups.push({ label: fmtAction(role) + ' credits', group: diff.credits[role] });
        });
        if (diff.tags.added.length || diff.tags.removed.length) {
            nameGroups.push({ label: 'Tags', group: diff.tags });
        }
        if (diff.links.added.length || diff.links.removed.length) {
            nameGroups.push({ label: 'Links', group: diff.links });
        }
        nameGroups.forEach((g) => panel.appendChild(buildNameSetBlock(g.label, g.group)));
    }

    function fmtScalarValue(v) {
        if (v === null || v === undefined || v === '') { return '(empty)'; }
        if (typeof v === 'object') { try { return JSON.stringify(v); } catch (_e) { return String(v); } }
        return String(v);
    }

    function buildScalarTable(scalars) {
        const table = document.createElement('table');
        table.className = 'table table-sm table-borderless mb-2';
        const thead = document.createElement('thead');
        const hr = document.createElement('tr');
        ['Field', 'Before', 'After'].forEach((h) => {
            const th = document.createElement('th');
            th.textContent = h;
            th.setAttribute('scope', 'col');
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        scalars.forEach((row) => {
            const tr = document.createElement('tr');
            const tdKey = document.createElement('td'); tdKey.textContent = row.key;
            const tdBefore = document.createElement('td'); tdBefore.textContent = fmtScalarValue(row.before);
            const tdAfter = document.createElement('td'); tdAfter.textContent = fmtScalarValue(row.after);
            tr.append(tdKey, tdBefore, tdAfter);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
    }

    function componentLabel(c) {
        return (c.type || 'section') + (c.number ? ' ' + c.number : '') + (c.label ? ' ("' + c.label + '")' : '');
    }

    function buildComponentList(components) {
        const wrap = document.createElement('div');
        wrap.className = 'mb-2';
        const heading = document.createElement('div');
        heading.className = 'fw-semibold small mb-1';
        heading.textContent = 'Sections';
        wrap.appendChild(heading);

        const ul = document.createElement('ul');
        ul.className = 'mb-0 ps-3';
        components.added.forEach((c) => {
            const li = document.createElement('li');
            li.textContent = 'Added: ' + componentLabel(c) + ' (' + c.lineCount + ' line' + (c.lineCount === 1 ? '' : 's') + ')';
            ul.appendChild(li);
        });
        components.removed.forEach((c) => {
            const li = document.createElement('li');
            li.textContent = 'Removed: ' + componentLabel(c) + ' (' + c.lineCount + ' line' + (c.lineCount === 1 ? '' : 's') + ')';
            ul.appendChild(li);
        });
        components.changed.forEach((c) => {
            const li = document.createElement('li');
            let text = 'Changed: ' + componentLabel(c.after);
            if (c.linesChanged > 0) {
                text += ' — ' + c.linesChanged + ' line' + (c.linesChanged === 1 ? '' : 's') + ' changed';
            }
            li.textContent = text;
            if (c.firstDiff) {
                const quote = document.createElement('div');
                quote.className = 'text-muted small ps-2';
                quote.textContent = '“' + c.firstDiff.before + '” → “' + c.firstDiff.after + '”';
                li.appendChild(quote);
            }
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        return wrap;
    }

    function buildNameSetBlock(label, group) {
        const wrap = document.createElement('div');
        wrap.className = 'mb-2';
        const heading = document.createElement('div');
        heading.className = 'fw-semibold small mb-1';
        heading.textContent = label;
        wrap.appendChild(heading);
        const ul = document.createElement('ul');
        ul.className = 'mb-0 ps-3';
        (group.added || []).forEach((n) => {
            const li = document.createElement('li');
            li.textContent = 'Added: ' + n;
            ul.appendChild(li);
        });
        (group.removed || []).forEach((n) => {
            const li = document.createElement('li');
            li.textContent = 'Removed: ' + n;
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        return wrap;
    }

    function render(revs) {
        contentEl.innerHTML = '';

        /* #1628 item 4 — say WHICH state a restore lands on. The old editor's
           Restore went one step further back (PreviousData vs NewData); the
           "Changes" button on each row (below) is what closes that gap now —
           see the moment it matters. */
        const intro = document.createElement('p');
        intro.className = 'text-muted small';
        intro.textContent = 'Each entry is a snapshot of the whole song taken just after that edit. '
            + 'Restore brings the song back to that state — not to the state before the edit, '
            + 'which is what the legacy editor\'s Restore did. Use "Changes" to see exactly what '
            + 'this entry altered before you restore it.';
        contentEl.appendChild(intro);

        if (!revs.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted';
            empty.textContent = 'No revisions yet — they appear as you edit.';
            contentEl.appendChild(empty);
            return;
        }

        const list = document.createElement('div');
        list.className = 'list-group';
        revs.forEach((rev) => {
            const item = document.createElement('div');
            item.className = 'list-group-item d-flex justify-content-between align-items-center gap-2';

            const left = document.createElement('div');
            left.className = 'd-flex align-items-center gap-2 flex-wrap';
            const badge = document.createElement('span');
            badge.className = 'badge bg-secondary';
            badge.textContent = fmtAction(rev.action);
            const meta = document.createElement('span');
            meta.className = 'small';
            meta.textContent = (rev.createdAt || '') + (rev.username ? ' · ' + rev.username : '');
            left.append(badge, meta);

            const actions = document.createElement('div');
            actions.className = 'd-flex align-items-center gap-2';

            const diffPanel = document.createElement('div');
            diffPanel.className = 'list-group-item bg-body-tertiary py-2 small d-none';

            const changesBtn = document.createElement('button');
            changesBtn.type = 'button';
            changesBtn.className = 'btn btn-sm btn-outline-secondary';
            changesBtn.innerHTML = '<i class="bi bi-file-diff me-1" aria-hidden="true"></i>Changes';
            changesBtn.addEventListener('click', () => toggleDiff(rev, diffPanel));

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore';
            btn.addEventListener('click', () => restore(rev));

            actions.append(changesBtn, btn);
            item.append(left, actions);
            list.appendChild(item);
            list.appendChild(diffPanel);
        });
        contentEl.appendChild(list);
    }

    /* ---- C3 — "Field history" (per-field blame) view (#1122) --------------
     *
     * ELI5: instead of a list of edits in time order, this shows one row PER
     * FIELD — who last touched it, and when — so "who changed the copyright
     * line" doesn't mean scrolling through unrelated history entries.
     *
     * Detail: built on the already-tested pure blameFromSnapshots() above —
     * this view only renders its output, never recomputes the blame logic.
     */

    /** field key -> human label for the common metadata fields (ED2_META_FIELDS
     *  in api2.php). A field key not listed here (or a null `field`, meaning
     *  the column wasn't in the served fieldMap) falls back to
     *  titleCaseFromKey() below rather than silently omitting the row. */
    const FIELD_LABELS = {
        title: 'Title',
        number: 'Number',
        songbook: 'Songbook',
        language: 'Language',
        copyright: 'Copyright',
        ccli: 'CCLI',
        iswc: 'ISWC',
        isrc: 'ISRC',
        tuneName: 'Tune name',
        subtitle: 'Subtitle',
        disambiguation: 'Disambiguation',
        firstPublishedYear: 'First published year',
        copyrightYears: 'Copyright years',
        copyrightHolder: 'Copyright holder',
        originCity: 'Origin city',
        originCityId: 'Origin city (place)',
        verified: 'Verified',
        lyricsPublicDomain: 'Public domain (lyrics)',
        musicPublicDomain: 'Public domain (music)',
        hasAudio: 'Has audio',
        hasSheetMusic: 'Has sheet music',
        lyricsRightsLicenceKey: 'Lyrics rights licence',
        musicRightsLicenceKey: 'Music rights licence',
    };

    /** Generic fallback label for a key not in FIELD_LABELS: split camelCase
     *  ("firstPublishedYear" -> "first Published Year") and underscores, then
     *  title-case each word. Never throws on an odd key — worst case it just
     *  echoes the key back title-cased. */
    function titleCaseFromKey(k) {
        const spaced = String(k)
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .replace(/[_-]+/g, ' ')
            .trim();
        if (!spaced) { return String(k); }
        return spaced.replace(/\w\S*/g, (w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase());
    }

    /** The human label for one blame entry: FIELD_LABELS[entry.field] first,
     *  else a title-cased fallback of the field key — or, when `field` is
     *  null (column absent from the served fieldMap), of the raw column
     *  `key` instead. */
    function fieldLabel(entry) {
        if (entry.field && FIELD_LABELS[entry.field]) { return FIELD_LABELS[entry.field]; }
        return titleCaseFromKey(entry.field || entry.key);
    }

    let fieldHistoryData = null; // { res, blame } — cached after the first successful fetch

    /** Lazy, fetch-once (mirrors toggleDiff's posture above): the first
     *  activation of "Field history" fetches api.listRevisionSnapshots() and
     *  runs it through blameFromSnapshots(); every later activation just
     *  re-renders the cached result — the blame for a given song's saved
     *  history doesn't change without a new edit, and a new edit reloads the
     *  whole page anyway (restore()/revertField() below). */
    async function showFieldHistory() {
        if (fieldHistoryData) {
            renderFieldHistory(fieldHistoryData);
            return;
        }
        contentEl.innerHTML = '<p class="text-muted small mb-0">Loading field history…</p>';
        try {
            const res = await api.listRevisionSnapshots(songId);
            const blame = blameFromSnapshots(res.revisions || [], res.base, res.fieldMap || {}, res.noRollback || []);
            fieldHistoryData = { res: res, blame: blame };
            renderFieldHistory(fieldHistoryData);
        } catch (e) {
            contentEl.innerHTML = '';
            const err = document.createElement('div');
            err.className = 'alert alert-danger py-2 small mb-0';
            err.textContent = 'Could not load field history: ' + e.message;
            contentEl.appendChild(err);
        }
    }

    function renderFieldHistory(data) {
        contentEl.innerHTML = '';
        const res = data.res;
        const blame = data.blame;

        const intro = document.createElement('p');
        intro.className = 'text-muted small';
        intro.textContent = 'Who last changed each field, across this song\'s saved history.';
        contentEl.appendChild(intro);

        if (!blame.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted';
            empty.textContent = 'No field history yet — it builds as the song is edited.';
            contentEl.appendChild(empty);
            return;
        }

        const list = document.createElement('div');
        list.className = 'list-group';
        blame.forEach((entry) => { list.appendChild(buildFieldHistoryItem(entry)); });
        contentEl.appendChild(list);

        if (res.truncated) {
            const note = document.createElement('p');
            note.className = 'text-muted small mt-2 mb-0';
            note.textContent = 'Older history exists beyond the most recent snapshots shown.';
            contentEl.appendChild(note);
        }
    }

    /** One field's blame row: label + current value, a verdict line (who/when,
     *  or — for 'unchangedInWindow' — a muted notice with NO author claimed,
     *  since none can be honestly attributed), a "Show change" toggle for a
     *  'changed' entry, and (C4, when canRevert) a Revert button beside it. */
    function buildFieldHistoryItem(entry) {
        const item = document.createElement('div');
        item.className = 'list-group-item';

        const top = document.createElement('div');
        top.className = 'd-flex justify-content-between align-items-start gap-2 flex-wrap';

        const left = document.createElement('div');
        const label = document.createElement('div');
        label.className = 'fw-semibold';
        label.textContent = fieldLabel(entry);
        const value = document.createElement('div');
        value.className = 'small text-muted';
        value.textContent = 'Current: ' + fmtScalarValue(entry.currentValue);
        left.append(label, value);

        const actions = document.createElement('div');
        actions.className = 'd-flex align-items-center gap-2';

        const diffPanel = document.createElement('div');
        diffPanel.className = 'bg-body-tertiary py-2 small d-none mt-2';

        if (entry.verdict === 'changed') {
            const showBtn = document.createElement('button');
            showBtn.type = 'button';
            showBtn.className = 'btn btn-sm btn-outline-secondary';
            showBtn.innerHTML = '<i class="bi bi-file-diff me-1" aria-hidden="true"></i>Show change';
            showBtn.addEventListener('click', () => toggleFieldDiff(entry, diffPanel));
            actions.appendChild(showBtn);

            if (entry.canRevert) {
                const revertBtn = document.createElement('button');
                revertBtn.type = 'button';
                revertBtn.className = 'btn btn-sm btn-outline-secondary';
                revertBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Revert';
                revertBtn.addEventListener('click', () => revertField(entry));
                actions.appendChild(revertBtn);
            }
        }

        top.append(left, actions);
        item.appendChild(top);

        const verdictLine = document.createElement('div');
        verdictLine.className = 'small mt-1';
        verdictLine.appendChild(buildVerdictContent(entry));
        item.appendChild(verdictLine);

        item.appendChild(diffPanel);
        return item;
    }

    /** The verdict sentence for one blame entry, as a DOM fragment so the
     *  author/date sits in a badge — the same badge+meta shape the History
     *  list above uses for its own action badge. */
    function buildVerdictContent(entry) {
        const frag = document.createDocumentFragment();
        if (entry.verdict === 'changed' || entry.verdict === 'firstRecorded') {
            const info = (entry.verdict === 'changed') ? entry.last : entry.firstRecorded;
            const prefix = document.createElement('span');
            prefix.textContent = (entry.verdict === 'changed') ? 'Last changed by ' : 'First recorded by ';
            const badge = document.createElement('span');
            badge.className = 'badge bg-secondary mx-1';
            badge.textContent = info.username || 'unknown';
            const suffix = document.createElement('span');
            suffix.textContent = 'on ' + (info.createdAt || '');
            frag.append(prefix, badge, suffix);
        } else {
            const muted = document.createElement('span');
            muted.className = 'text-muted';
            muted.textContent = 'Not changed in the recent saved history';
            frag.appendChild(muted);
        }
        return frag;
    }

    /** Toggle one field's inline before/after panel. The pair is already in
     *  `entry.last` (blameFromSnapshots computed it up front), so — unlike
     *  toggleDiff() above — there is nothing to fetch; the first click just
     *  renders it and flips the "already built" flag for later toggles. */
    function toggleFieldDiff(entry, panel) {
        if (panel.dataset.loaded === '1') {
            panel.classList.toggle('d-none');
            return;
        }
        panel.classList.remove('d-none');
        panel.innerHTML = '';
        const before = document.createElement('div');
        before.textContent = 'Before: ' + fmtScalarValue(entry.last.before);
        const after = document.createElement('div');
        after.textContent = 'After: ' + fmtScalarValue(entry.last.after);
        panel.append(before, after);
        panel.dataset.loaded = '1';
    }

    /**
     * C4 (#1122) — per-field Revert. Writes a NEW edit via
     * api.updateMetadata(songId, field, previousValue); it does not delete or
     * alter any saved history row, so the revert itself shows up in both
     * views the next time they're fetched.
     *
     * ELI5: a per-field Undo — puts just this one field back the way it was,
     * without touching every other field the way a whole-revision Restore
     * (above) would.
     *
     * Detail: mirrors restore()'s reload pattern exactly — a metadata write
     * can ripple into derived/denormalised state elsewhere on the song, so a
     * full reload re-hydrates every tab from the server rather than patching
     * the DOM in place (same rationale as restore()'s doc-block above).
     */
    async function revertField(entry) {
        const label = fieldLabel(entry);
        const toValue = fmtScalarValue(entry.last.before);
        const fromValue = fmtScalarValue(entry.currentValue);
        const who = (entry.last.username || 'unknown') + ' on ' + (entry.last.createdAt || 'an earlier date');
        if (!window.confirm(
            'Revert "' + label + '" to "' + toValue + '"?\n\n'
            + 'This replaces the current value, "' + fromValue + '", and undoes the change made by '
            + who + '.\n\n'
            + 'This writes a NEW edit — it does not delete or change any saved history.'
        )) {
            return;
        }
        try {
            await api.updateMetadata(songId, entry.field, entry.last.before);
            toast('Reverted — reloading…', 'success');
            window.location.reload();   // re-hydrate every tab from the reverted state
        } catch (e) {
            toast('Revert failed: ' + e.message, 'danger');
        }
    }

    load();

    return function teardown() {
        container.innerHTML = '';
    };
}

/* ==========================================================================
 *  diffSnapshots(before, after) — pure field-level diff between two decoded
 *  revision snapshots (#1628 item 4). NO DOM access, so it runs identically
 *  in the browser (mountRevisionsTab above) and under Node
 *  (tests/test-revision-diff.js) — extracted specifically so it is testable
 *  without a DOM (rule #34 — a behavioural guard over the real function, not
 *  a source-text scan of markup this file happens to build).
 *
 *  ELI5: given "how the song looked before" and "how it looks after", this
 *  works out exactly what changed — which fields, which sections were
 *  added/removed/edited, and which credits/tags/links came or went — without
 *  touching the page.
 *
 *  DETAILED — shape tolerance. `tblSongRevisions.NewData`/`PreviousData` have
 *  stored THREE shapes over this app's history (api2.php's
 *  ed2_touchRevision() doc-block): the v2 full snapshot
 *  `{song:{...}, components:[...], credits:{role:[...]}, tags:[...], links:[...]}`;
 *  a bare tblSongs row (the pre-#1743 v1 shape — Uppercase keys, no
 *  `song`/`components` siblings); and an old editor-payload lowercase-keys
 *  shape. This function only trusts what a side ACTUALLY carries — it never
 *  invents `components`/`credits`/`tags`/`links` for a side that lacks the
 *  full v2 shape (a diff that hallucinates removed sections that were never
 *  really recorded would be worse than no diff — plan §A.1). When either
 *  side lacks the v2 shape, the returned `legacy` flag is true and ONLY the
 *  scalar comparison runs. That comparison still means something across a
 *  legacy/v2 pair: a v2 snapshot's `.song` object and a bare tblSongs row are
 *  BOTH the same Uppercase `SELECT * FROM tblSongs` column set, so the
 *  fields line up even though the shapes differ — which is also why this
 *  never throws on a mismatched pair, it just compares fewer things.
 *
 *  FUTURE REFINEMENT (recorded per the plan, not built here): component text
 *  changes are summarised as "N lines changed" + the first differing line
 *  quoted, not a full line-level LCS diff.
 *
 * @param {*} before a decoded revision snapshot (or null/undefined)
 * @param {*} after  a decoded revision snapshot (or null/undefined)
 * @returns {{legacy:boolean, hasChanges:boolean,
 *   scalars:Array<{key:string,before:*,after:*}>,
 *   components:{added:Array<object>,removed:Array<object>,changed:Array<object>},
 *   credits:Object<string,{added:string[],removed:string[]}>,
 *   tags:{added:string[],removed:string[]},
 *   links:{added:string[],removed:string[]}}}
 * ========================================================================== */
export function diffSnapshots(before, after) {
    const beforeV2 = isV2Shape(before);
    const afterV2  = isV2Shape(after);
    const legacy   = !(beforeV2 && afterV2);

    /* -------- (a) scalars — present in either side, value differs -------- */
    const beforeScalars = scalarsOf(before);
    const afterScalars  = scalarsOf(after);
    const scalarKeys = new Set([...Object.keys(beforeScalars), ...Object.keys(afterScalars)]);
    const scalars = [];
    scalarKeys.forEach((key) => {
        const b = beforeScalars[key];
        const a = afterScalars[key];
        if (!sameValue(b, a)) { scalars.push({ key: key, before: b, after: a }); }
    });
    scalars.sort((x, y) => (x.key < y.key ? -1 : (x.key > y.key ? 1 : 0)));

    /* -------- (b) components — compared BY POSITION -------- */
    const components = { added: [], removed: [], changed: [] };
    if (beforeV2 && afterV2) {
        const beforeComps = Array.isArray(before.components) ? before.components : [];
        const afterComps  = Array.isArray(after.components) ? after.components : [];
        const maxLen = Math.max(beforeComps.length, afterComps.length);
        for (let i = 0; i < maxLen; i++) {
            const b = beforeComps[i];
            const a = afterComps[i];
            if (b && !a) { components.removed.push(summariseComponent(i, b)); continue; }
            if (!b && a) { components.added.push(summariseComponent(i, a)); continue; }
            if (!b || !a) { continue; }

            const bLines = Array.isArray(b.lines) ? b.lines : [];
            const aLines = Array.isArray(a.lines) ? a.lines : [];
            const lineMax = Math.max(bLines.length, aLines.length);
            let linesChanged = 0;
            let firstDiff = null;
            for (let li = 0; li < lineMax; li++) {
                const bLine = lineText(bLines[li]);
                const aLine = lineText(aLines[li]);
                if (bLine !== aLine) {
                    linesChanged++;
                    if (firstDiff === null) { firstDiff = { lineIndex: li, before: bLine, after: aLine }; }
                }
            }
            const fieldsChanged = ['type', 'number', 'label', 'language'].filter((f) => !sameValue(b[f], a[f]));
            if (linesChanged > 0 || fieldsChanged.length > 0) {
                components.changed.push({
                    index: i,
                    before: { type: b.type, number: b.number, label: b.label || null },
                    after:  { type: a.type, number: a.number, label: a.label || null },
                    fieldsChanged: fieldsChanged,
                    linesChanged: linesChanged,
                    firstDiff: firstDiff,
                });
            }
        }
    }

    /* -------- (c) credits / tags / links — added/removed NAME SETS -------- */
    const credits = {};
    if (beforeV2 && afterV2) {
        const beforeCredits = (before.credits && typeof before.credits === 'object') ? before.credits : {};
        const afterCredits  = (after.credits && typeof after.credits === 'object') ? after.credits : {};
        const roles = new Set([...Object.keys(beforeCredits), ...Object.keys(afterCredits)]);
        roles.forEach((role) => {
            const group = addedRemoved(namesOf(beforeCredits[role]), namesOf(afterCredits[role]));
            if (group.added.length || group.removed.length) { credits[role] = group; }
        });
    }
    const tags  = (beforeV2 && afterV2) ? addedRemoved(namesOf(before.tags), namesOf(after.tags)) : { added: [], removed: [] };
    const links = (beforeV2 && afterV2) ? addedRemoved(urlsOf(before.links), urlsOf(after.links)) : { added: [], removed: [] };

    const hasChanges = scalars.length > 0
        || components.added.length > 0 || components.removed.length > 0 || components.changed.length > 0
        || Object.keys(credits).length > 0
        || tags.added.length > 0 || tags.removed.length > 0
        || links.added.length > 0 || links.removed.length > 0;

    return { legacy: legacy, hasChanges: hasChanges, scalars: scalars, components: components, credits: credits, tags: tags, links: links };
}

/** True when `snap` is the v2 full-snapshot shape (has both a `.song` object
 *  and a `.components` array) — the ONLY shape components/credits/tags/links
 *  are safely readable from. */
function isV2Shape(snap) {
    return !!(snap && typeof snap === 'object'
        && snap.song && typeof snap.song === 'object'
        && Array.isArray(snap.components));
}

/** The scalar (tblSongs-column) object for a snapshot, whichever of the three
 *  shapes it is: a v2 snapshot's `.song`, or the bare row itself. Never
 *  invents keys — an object with none of the expected shape returns `{}`. */
function scalarsOf(snap) {
    if (!snap || typeof snap !== 'object') { return {}; }
    return (snap.song && typeof snap.song === 'object') ? snap.song : snap;
}

/** undefined normalises to null; primitives compare by string; objects/arrays
 *  by JSON — good enough for a revision snapshot's own JSON-round-tripped
 *  values, and never throws (a stringify failure just means "different"). */
function sameValue(a, b) {
    const na = (a === undefined) ? null : a;
    const nb = (b === undefined) ? null : b;
    if (na === nb) { return true; }
    if (na === null || nb === null) { return false; }
    if (typeof na === 'object' || typeof nb === 'object') {
        try { return JSON.stringify(na) === JSON.stringify(nb); } catch (_e) { return false; }
    }
    return String(na) === String(nb);
}

/** A line entry is a plain string in every shape this app has ever written
 *  (lyricLinesEditableComponents()); this tolerates an unexpected object
 *  shape defensively rather than throwing. */
function lineText(line) {
    if (line === undefined || line === null) { return ''; }
    if (typeof line === 'object') {
        if (typeof line.text === 'string') { return line.text; }
        if (typeof line.Line === 'string') { return line.Line; }
        try { return JSON.stringify(line); } catch (_e) { return String(line); }
    }
    return String(line);
}

function summariseComponent(index, comp) {
    const lines = Array.isArray(comp && comp.lines) ? comp.lines : [];
    return {
        index: index,
        type: comp ? comp.type : null,
        number: comp ? comp.number : null,
        label: (comp && comp.label) || null,
        lineCount: lines.length,
    };
}

/** The distinct, non-empty `.name` values of a credits/tags-shaped list. */
function namesOf(list) {
    const set = new Set();
    if (Array.isArray(list)) {
        list.forEach((item) => {
            if (item && typeof item === 'object' && typeof item.name === 'string' && item.name !== '') {
                set.add(item.name);
            }
        });
    }
    return set;
}

/** The distinct, non-empty `.url` values of a links-shaped list — links have
 *  no `name`, so the URL is the identity used for added/removed. */
function urlsOf(list) {
    const set = new Set();
    if (Array.isArray(list)) {
        list.forEach((item) => {
            if (item && typeof item === 'object' && typeof item.url === 'string' && item.url !== '') {
                set.add(item.url);
            }
        });
    }
    return set;
}

function addedRemoved(beforeSet, afterSet) {
    return {
        added: [...afterSet].filter((n) => !beforeSet.has(n)),
        removed: [...beforeSet].filter((n) => !afterSet.has(n)),
    };
}

/* ==========================================================================
 *  canonicalScalarsOf(snap, fieldMap) — a snapshot's blame-tracked SCALAR
 *  fields, folded to canonical tblSongs COLUMN keys, whichever of the three
 *  shapes the snapshot is (#1122).
 *
 *  ELI5: three eras stored a song's fields under different key names — a v2
 *  snapshot and the old bare-row shape use Uppercase column names (`Title`),
 *  while the oldest editor-payload shape uses lowercase field keys (`title`).
 *  This flattens all three to ONE key set (the Uppercase column) so blame can
 *  compare a `Title` from 2024 against a `title` from 2022 without pretending
 *  they are different fields.
 *
 *  DETAILED: reuses the shipped scalarsOf() (v2 `.song` / bare row / payload
 *  root — the ONE shape picker, rule #22). For each fieldMap entry
 *  (fieldKey -> Column) it takes the Column value when present (v2/bare), else
 *  the lowercase fieldKey value when present (payload), else the field is
 *  ABSENT (not added to the map — a distinct state from a cleared value: a
 *  shape that never carried a field must never read as "cleared it", plan D2).
 *  Only the fieldMap columns are kept — derived/noise columns (UpdatedAt,
 *  NormalizedTitle, LyricsText, …) are NOT in the map and are dropped, so an
 *  auto-updating UpdatedAt never shows every pair as "changed".
 *
 * @param {*} snap a decoded revision snapshot (any of the 3 shapes, or null)
 * @param {Object<string,string>} fieldMap fieldKey -> Column (served, ED2_META_FIELDS-derived)
 * @returns {Object<string,{value:*,present:true}>} keyed by Column; absent keys omitted
 * ========================================================================== */
export function canonicalScalarsOf(snap, fieldMap) {
    const raw = scalarsOf(snap);
    const out = {};
    if (!raw || typeof raw !== 'object' || !fieldMap || typeof fieldMap !== 'object') { return out; }
    Object.keys(fieldMap).forEach((field) => {
        const col = fieldMap[field];
        if (Object.prototype.hasOwnProperty.call(raw, col)) {
            out[col] = { value: raw[col], present: true };
        } else if (Object.prototype.hasOwnProperty.call(raw, field)) {
            out[col] = { value: raw[field], present: true };
        }
        /* else: absent in this shape — deliberately omitted (absent != cleared). */
    });
    return out;
}

/* ==========================================================================
 *  blameFromSnapshots(rows, base, fieldMap, noRollback) — per-field BLAME over
 *  a song's whole revision window (#1122). PURE (no DOM), so it runs under Node
 *  (tests/test-revision-blame.js) exactly as in the browser — the same
 *  testable-without-a-DOM discipline as diffSnapshots (rule #34).
 *
 *  ELI5: "who last changed this field, and when" for every scalar field the
 *  song currently has — walked across the whole history, tolerant of the three
 *  snapshot shapes, honest when it genuinely cannot attribute a field.
 *
 *  DETAILED — the walk (plan §2/D2):
 *    1. Build an oldest->newest sequence of {snap, attrib}. `base` (the window
 *       pre-state, no attribution) leads when present; then each row's newData,
 *       oldest first. A row whose newData is null (undecodable) is BRIDGED
 *       (skipped) — never invents a change.
 *    2. For each canonical column CURRENTLY present, walk adjacent pairs:
 *       - present-on-both AND value differs => a CHANGE; the CUR revision is
 *         recorded (newest wins => last-writer attribution).
 *       - absent->present => the field's first APPEARANCE (introducing row).
 *    3. Verdict (rule #20 vocabulary): 'changed' (attributed to the last
 *       writer), 'firstRecorded' (introduced in-window / present since the
 *       first ROW and never changed — attributed to that row), or
 *       'unchangedInWindow' (present since `base`, i.e. predating any row we
 *       hold, never changed — NO author is claimed).
 *
 *  SCOPE (v1): SCALAR fields only — the issue's load-bearing case ("who changed
 *  this copyright line"). Structured-group blame (components / credits / tags /
 *  links, set-level) is a recorded fast-follow, alongside the same-scoped
 *  structured-field ROLLBACK (plan D3) — the shipped whole-revision Restore
 *  already covers "the lyrics are wrong, go back".
 *
 * @param {Array} rows newest-first [{id, action, createdAt, userId, username, newData}]
 * @param {*} base the window pre-state snapshot (oldest row's PreviousData) or null
 * @param {Object<string,string>} fieldMap fieldKey -> Column (served)
 * @param {Array<string>} noRollback field keys with no per-field Revert
 * @returns {Array<{key:string, field:?string, verdict:string,
 *   last:?object, firstRecorded:?object, canRevert:boolean, currentValue:*}>}
 *   one entry per currently-present scalar field, sorted by column key.
 * ========================================================================== */
export function blameFromSnapshots(rows, base, fieldMap, noRollback) {
    const map = (fieldMap && typeof fieldMap === 'object') ? fieldMap : {};
    const noRoll = new Set(Array.isArray(noRollback) ? noRollback : []);
    const colToField = {};
    Object.keys(map).forEach((f) => { colToField[map[f]] = f; });

    /* (1) oldest->newest sequence, bridging null-newData rows. */
    const seq = [];
    if (base && typeof base === 'object') { seq.push({ snap: base, attrib: null }); }
    const ordered = Array.isArray(rows) ? rows.slice().reverse() : [];
    ordered.forEach((r) => {
        if (r && r.newData && typeof r.newData === 'object') {
            seq.push({
                snap: r.newData,
                attrib: {
                    revisionId: r.id,
                    action:     r.action,
                    createdAt:  r.createdAt,
                    userId:     (r.userId === undefined ? null : r.userId),
                    username:   (r.username === undefined ? null : r.username),
                },
            });
        }
    });
    if (seq.length === 0) { return []; }
    const currentIdx = seq.length - 1;
    if (seq[currentIdx].attrib === null) { return []; } /* only `base`, no attributable row */

    /* Memoise each snapshot's canonical scalar map once. */
    const canon = seq.map((step) => canonicalScalarsOf(step.snap, map));
    const canonCur = canon[currentIdx];

    const out = [];
    Object.keys(map).forEach((field) => {
        const col = map[field];
        if (!Object.prototype.hasOwnProperty.call(canonCur, col)) { return; } /* not a current field */

        let last = null;
        let firstAppear = null;
        for (let i = 1; i < seq.length; i++) {
            const pvPresent = Object.prototype.hasOwnProperty.call(canon[i - 1], col);
            const cvPresent = Object.prototype.hasOwnProperty.call(canon[i], col);
            if (pvPresent && cvPresent && !sameValue(canon[i - 1][col].value, canon[i][col].value)) {
                last = { attrib: seq[i].attrib, before: canon[i - 1][col].value, after: canon[i][col].value };
            } else if (!pvPresent && cvPresent && firstAppear === null) {
                firstAppear = { attrib: seq[i].attrib };
            }
        }

        const presentAtStart = Object.prototype.hasOwnProperty.call(canon[0], col);
        const startIsRow = seq[0].attrib !== null;

        let verdict;
        let lastOut = null;
        let firstRecordedOut = null;
        if (last) {
            verdict = 'changed';
            lastOut = {
                revisionId: last.attrib.revisionId,
                action:     last.attrib.action,
                createdAt:  last.attrib.createdAt,
                userId:     last.attrib.userId,
                username:   last.attrib.username,
                before:     last.before,
                after:      last.after,
            };
        } else if (firstAppear) {
            verdict = 'firstRecorded';
            firstRecordedOut = {
                revisionId: firstAppear.attrib.revisionId,
                createdAt:  firstAppear.attrib.createdAt,
                userId:     firstAppear.attrib.userId,
                username:   firstAppear.attrib.username,
            };
        } else if (presentAtStart && startIsRow) {
            /* present since the very first ROW, never changed -> that row introduced it */
            verdict = 'firstRecorded';
            firstRecordedOut = {
                revisionId: seq[0].attrib.revisionId,
                createdAt:  seq[0].attrib.createdAt,
                userId:     seq[0].attrib.userId,
                username:   seq[0].attrib.username,
            };
        } else {
            /* present since `base` (predates any row we hold) and never changed
               in-window — we cannot honestly attribute an author. */
            verdict = 'unchangedInWindow';
        }

        out.push({
            key:           col,
            field:         Object.prototype.hasOwnProperty.call(colToField, col) ? colToField[col] : null,
            verdict:       verdict,
            last:          lastOut,
            firstRecorded: firstRecordedOut,
            canRevert:     !!colToField[col] && !noRoll.has(colToField[col]),
            currentValue:  canonCur[col].value,
        });
    });

    out.sort((x, y) => (x.key < y.key ? -1 : (x.key > y.key ? 1 : 0)));
    return out;
}
