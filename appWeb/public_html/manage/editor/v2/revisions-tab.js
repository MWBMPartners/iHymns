/* ==========================================================================
 *  revisions-tab.js — the v2 Song Editor "Revisions" tab (#1200, Phase 4c)
 *
 *  Read-only history of the song's saved snapshots (tblSongRevisions, written by
 *  every v2 mutation on a coalesced ~15s debounce), newest first, with a Restore
 *  action per entry. Each snapshot is the FULL record (scalars + sections +
 *  credits + tags + links), so a restore brings the whole song back to that
 *  state atomically (server-side). After a confirmed restore the page reloads so
 *  every tab re-hydrates from the restored state.
 *
 *  mountRevisionsTab(container, { api, songId, toast }) -> teardown fn
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

    async function restore(rev) {
        const when = rev.createdAt || 'this version';
        if (!window.confirm('Restore the song to the ' + fmtAction(rev.action) + ' snapshot from ' + when + '?\n\nThis replaces the current sections, metadata, credits, tags and links with that version. (Media files are not affected.)')) {
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

    function render(revs) {
        container.innerHTML = '';

        const intro = document.createElement('p');
        intro.className = 'text-muted small';
        intro.textContent = 'Each entry is a saved snapshot of the whole song. Restore brings the song back to that version.';
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

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Restore';
            btn.addEventListener('click', () => restore(rev));

            item.append(left, btn);
            list.appendChild(item);
        });
        container.appendChild(list);
    }

    load();

    return function teardown() {
        container.innerHTML = '';
    };
}
