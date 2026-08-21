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

    function fmtAction(a) {
        return String(a || 'edit').replace(/[_.]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
    }

    async function load() {
        container.innerHTML = '<p class="text-muted small mb-0">Loading history…</p>';
        try {
            const res = await api.listRevisions(songId);
            render(res.revisions || []);
        } catch (e) {
            container.innerHTML = '';
            const err = document.createElement('div');
            err.className = 'alert alert-danger py-2 small mb-0';
            err.textContent = 'Could not load revisions: ' + e.message;
            container.appendChild(err);
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
        container.innerHTML = '';

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
        container.appendChild(intro);

        if (!revs.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted';
            empty.textContent = 'No revisions yet — they appear as you edit.';
            container.appendChild(empty);
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
            changesBtn.innerHTML = '<i class="bi bi-file-diff me-1"></i>Changes';
            changesBtn.addEventListener('click', () => toggleDiff(rev, diffPanel));

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Restore';
            btn.addEventListener('click', () => restore(rev));

            actions.append(changesBtn, btn);
            item.append(left, actions);
            list.appendChild(item);
            list.appendChild(diffPanel);
        });
        container.appendChild(list);
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
