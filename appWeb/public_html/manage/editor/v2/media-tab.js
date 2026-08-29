/* ==========================================================================
 *  media-tab.js — the v2 Song Editor "Media" tab (#1200, Phase 3b)
 *
 *  Accompanying files per song — audio recordings, sheet-music PDFs, MIDI,
 *  MusicXML — grouped by kind. Upload (multipart → api2.php media_upload, where
 *  the bytes are MIME-sniffed + size-capped server-side), edit the annotation
 *  (debounced), reorder within a kind (up/down, like the Structure tab), and
 *  delete (with confirm). Every mutation is its own atomic, server-confirmed
 *  write; playback links go to the gated /song-media/<id> stream route (#853).
 *
 *  mountMediaTab(container, { store, api, songId, toast, registerFlush }) -> teardown fn
 *  Hydrates from the store's `media` slice: [{ id, kind, fileName, mimeType,
 *  sizeBytes, annotation, sortOrder, storageBackend, uploadedAt, streamUrl }, ...].
 *
 *  registerFlush(fn) — #1846: hands the shell a "flush my pending debounced
 *  annotation saves now" function for its manual Save button. See
 *  flushPending() below.
 * ========================================================================== */

const SAVE_DEBOUNCE_MS = 500;

/* Kinds + their UI hints. ACCEPT is only a picker convenience — the SERVER
   sniffs the real MIME (SongMediaStorage::validateUpload), never the suffix.
   sizeCap mirrors SongMediaStorage::SIZE_CAPS for a fail-fast client check. */
const KIND_ORDER = ['audio', 'video', 'image', 'sheet-music', 'midi', 'musicxml'];
const KIND_META = {
    'audio':       { label: 'Audio',            icon: 'bi-music-note-beamed', accept: 'audio/*',                                   sizeCap: 50 * 1024 * 1024 },
    /* #1968 P4 — ProPresenter background media. sizeCap mirrors
       SongMediaStorage::SIZE_CAPS (video 100 MiB, image 10 MiB) — the PHP↔JS
       lockstep is CI-guarded by tests/php/test-song-media-visibility.php (c). */
    'video':       { label: 'Video',            icon: 'bi-film',              accept: 'video/*',                                   sizeCap: 100 * 1024 * 1024 },
    'image':       { label: 'Image',            icon: 'bi-image',             accept: 'image/*',                                   sizeCap: 10 * 1024 * 1024 },
    'sheet-music': { label: 'Sheet music (PDF)', icon: 'bi-file-earmark-pdf',  accept: 'application/pdf,.pdf',                       sizeCap: 10 * 1024 * 1024 },
    'midi':        { label: 'MIDI',             icon: 'bi-file-earmark-music', accept: 'audio/midi,audio/x-midi,.mid,.midi',        sizeCap:  1 * 1024 * 1024 },
    'musicxml':    { label: 'MusicXML',         icon: 'bi-file-earmark-code',  accept: '.musicxml,.xml,application/xml,text/xml',   sizeCap:  1 * 1024 * 1024 },
};

function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) { return n + ' B'; }
    const u = ['KB', 'MB', 'GB'];
    let i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
    return n.toFixed(n < 10 ? 1 : 0) + ' ' + u[i];
}

export function mountMediaTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    /* #1846 — hand the shell a "flush me now" function once, at mount time
       (a plain injected callback, not a DOM event — rule #35/#1581). Defaults
       to a no-op so the tab still mounts standalone in a test harness that
       doesn't pass registerFlush. */
    const registerFlush = opts.registerFlush || function () {};
    const annoTimers = new Map();   // mediaId -> debounce timer for annotation saves
    /* #1846 — mediaId -> { item, value } not yet fired, the SAME key space as
       annoTimers above. Lets the shell's manual Save button fire a pending
       debounced annotation write early via flushPending() below. */
    const annoPending = new Map();

    function getMedia() {
        const m = store.get('media');
        return Array.isArray(m) ? m : [];
    }
    function mediaByKind(kind) {
        return getMedia().filter((m) => m.kind === kind)
            .sort((a, b) => (a.sortOrder - b.sortOrder) || (a.id - b.id));
    }

    /* ---- mutations ---- */

    async function refresh() {
        try {
            const res = await api.listMedia(songId);
            store.set('media', res.media || []);   // re-renders
        } catch (e) {
            toast('Could not refresh media: ' + e.message, 'danger');
        }
    }

    async function upload(kind, fileInput, annoInput, statusEl) {
        const file = fileInput.files && fileInput.files[0];
        if (!file) { toast('Choose a file first.', 'danger'); return; }
        const cap = KIND_META[kind].sizeCap;
        if (cap && file.size > cap) {
            toast(KIND_META[kind].label + ' files must be ≤ ' + fmtBytes(cap) + '.', 'danger');
            return;
        }
        statusEl.textContent = 'Uploading…';
        try {
            await api.uploadMedia(songId, kind, file, annoInput.value.trim());
            await refresh();   // full re-render shows the new file (clears the picker)
        } catch (e) {
            statusEl.textContent = '';
            toast('Upload failed: ' + e.message, 'danger');
        }
    }

    function saveAnnotation(item, value) {
        /* item.id is stable, so the SERVER write is always correct even if an
           intervening upload/delete/reorder re-rendered the tab and replaced the
           store rows. Update the LIVE store row by id (not the possibly-stale
           captured `item`) so the model stays consistent; no re-render (keeps
           input focus). NB: we deliberately do NOT clear the debounce timers in
           render() — that would drop an in-flight save (data loss).
           #1846 — returned so flushPending() (below) can await this specific
           save's completion; no existing caller used the return value before
           (debouncedAnnotation's setTimeout fires it and moves on), so this
           changes nothing for them.
           #1851 — resolves TRUE on success, FALSE on failure (never rejects);
           flushPending() sums the FALSEs into a failure count for the
           shell's Save-button outcome report. */
        return api.updateMedia(item.id, value)
            .then(() => {
                const live = getMedia().find((m) => m.id === item.id);
                if (live) { live.annotation = value; }
                return true;
            })
            .catch((e) => { toast('Could not save note: ' + e.message, 'danger'); return false; });
    }
    function debouncedAnnotation(item, value) {
        if (annoTimers.has(item.id)) { clearTimeout(annoTimers.get(item.id)); }
        annoPending.set(item.id, { item, value });   // #1846 — recorded so flushPending() can fire it early
        annoTimers.set(item.id, setTimeout(() => { annoPending.delete(item.id); saveAnnotation(item, value); }, SAVE_DEBOUNCE_MS));
    }

    /**
     * #1846 — the shell's manual Save button's hook into this tab: cancel
     * every pending per-file annotation debounce timer and fire each
     * recorded (item, value) save immediately instead of waiting out
     * SAVE_DEBOUNCE_MS.
     *
     * ELI5: if you just typed an annotation and paused for less than half a
     * second, clicking Save shouldn't make you wait for the timer — this
     * sends it right now.
     *
     * @returns {Promise<number>} Resolves once every flushed save has
     *   settled to the COUNT of saves that FAILED (0 = all ok) — the shell's
     *   Save button (#1846/#1851) sums this across every tab to decide
     *   between "All changes saved." and a real failure report.
     *   saveAnnotation() already catches its own rejection into a toast and
     *   resolves a boolean rather than rethrowing, so the
     *   `.catch(() => false)` here is belt-and-braces, not the normal path
     *   — this function itself must still never reject.
     */
    function flushPending() {
        annoTimers.forEach((t) => clearTimeout(t));
        annoTimers.clear();
        const proms = [];
        annoPending.forEach(({ item, value }) => {
            proms.push(Promise.resolve(saveAnnotation(item, value)).catch(() => false));
        });
        annoPending.clear();
        return Promise.all(proms).then((results) => results.reduce((n, ok) => n + (ok === false ? 1 : 0), 0));
    }
    registerFlush(flushPending);

    /**
     * #1851 — cancel this file's pending debounced annotation autosave
     * FIRST, mirroring structure-tab.js's removeComponent()/credits-tab.js's
     * removeCredit(). Milder here than the other two (a stale annotation
     * save after delete just 404s the toast, no resurrection — updateMedia
     * has nothing to re-append) but there is no reason to let a doomed
     * request fire at all.
     */
    async function remove(item) {
        if (!window.confirm('Remove "' + item.fileName + '"? This cannot be undone.')) { return; }
        if (annoTimers.has(item.id)) { clearTimeout(annoTimers.get(item.id)); annoTimers.delete(item.id); }
        annoPending.delete(item.id);
        const before = getMedia();
        store.set('media', before.filter((m) => m.id !== item.id));   // optimistic
        try {
            await api.deleteMedia(item.id);
        } catch (e) {
            store.set('media', before);   // revert — no optimistic lies (§6a)
            toast('Could not delete: ' + e.message, 'danger');
        }
    }

    async function move(kind, index, dir) {
        const items = mediaByKind(kind);
        const target = index + dir;
        if (target < 0 || target >= items.length) { return; }
        const tmp = items[index]; items[index] = items[target]; items[target] = tmp;
        items.forEach((m, i) => { m.sortOrder = i; });   // mutate the store objects
        store.set('media', getMedia().slice());          // re-render in the new order
        try {
            await api.reorderMedia(songId, kind, items.map((m) => m.id));
        } catch (e) {
            toast('Could not reorder: ' + e.message, 'danger');
            refresh();   // pull the server's truth back
        }
    }

    /* #1968 P4 — publish/unpublish an imported (admin-only) media row. Same
       optimistic-then-revert posture as remove()/move(): update the store first
       so the badge flips instantly, roll back on a server error (no optimistic
       lies). A row with no `visibility` field (pre-migration server) reads as
       'public'. */
    async function setVisibility(item, visibility) {
        const before = getMedia();
        store.set('media', before.map((m) => (m.id === item.id ? Object.assign({}, m, { visibility: visibility }) : m)));
        try {
            await api.setMediaVisibility(item.id, visibility);
        } catch (e) {
            store.set('media', before);
            toast('Could not change visibility: ' + e.message, 'danger');
        }
    }

    /* Publish every admin-only row of one kind (the per-kind convenience —
       D-P4-1, a client loop over the ONE endpoint, no second data model). */
    async function publishAll(kind) {
        const admin = mediaByKind(kind).filter((m) => (m.visibility || 'public') === 'admin');
        for (const it of admin) { await setVisibility(it, 'public'); }
    }

    /* ---- render ---- */

    function iconBtn(icon, title, disabled, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-secondary';
        b.title = title;
        b.setAttribute('aria-label', title);
        b.disabled = !!disabled;
        b.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i>';
        b.addEventListener('click', onClick);
        return b;
    }

    function buildFileRow(item, index, total) {
        const row = document.createElement('div');
        row.className = 'd-flex align-items-start gap-2 border rounded p-2 mb-2';

        const main = document.createElement('div');
        main.className = 'flex-grow-1 min-width-0';

        const top = document.createElement('div');
        top.className = 'd-flex align-items-center gap-2 flex-wrap';
        const link = document.createElement('a');
        link.href = item.streamUrl;                 // server-built '/song-media/<id>'
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'fw-semibold text-truncate';
        link.textContent = item.fileName;           // untrusted → textContent
        const meta = document.createElement('span');
        meta.className = 'text-muted small';
        meta.textContent = fmtBytes(item.sizeBytes) + ' · ' + (item.mimeType || '');
        top.append(link, meta);
        /* #1968 P4 — an admin-only (unpublished) row is BADGED, never hidden
           (the curator surface). warning-subtle tokens, theme-aware (#1223). */
        if ((item.visibility || 'public') === 'admin') {
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle';
            badge.textContent = 'Admin only';
            badge.title = 'Not shown on the public site until published';
            top.appendChild(badge);
        }
        main.appendChild(top);

        const anno = document.createElement('input');
        anno.type = 'text';
        anno.className = 'form-control form-control-sm mt-1';
        anno.maxLength = 255;
        anno.placeholder = 'Annotation (e.g. "1989 BBC recording")';
        anno.value = item.annotation || '';
        anno.addEventListener('input', () => debouncedAnnotation(item, anno.value));
        main.appendChild(anno);

        const btns = document.createElement('span');
        btns.className = 'btn-group btn-group-sm';
        btns.append(
            iconBtn('bi-arrow-up', 'Move up', index === 0, () => move(item.kind, index, -1)),
            iconBtn('bi-arrow-down', 'Move down', index === total - 1, () => move(item.kind, index, 1)),
        );
        /* #1968 P4 — publish (eye) an admin-only row / unpublish (eye-slash) a
           public one, per-row (owner decision D1). */
        if ((item.visibility || 'public') === 'admin') {
            const pub = iconBtn('bi-eye', 'Publish (show on the public site)', false, () => setVisibility(item, 'public'));
            pub.classList.add('text-success');
            btns.appendChild(pub);
        } else {
            btns.appendChild(iconBtn('bi-eye-slash', 'Unpublish (hide from the public site)', false, () => setVisibility(item, 'admin')));
        }
        const del = iconBtn('bi-trash', 'Remove file', false, () => remove(item));
        del.classList.add('text-danger');
        btns.appendChild(del);

        row.append(main, btns);
        return row;
    }

    function buildKindBlock(kind) {
        const m = KIND_META[kind];
        const block = document.createElement('div');
        block.className = 'mb-4';

        const h = document.createElement('h3');
        h.className = 'h6 d-flex align-items-center gap-2';
        h.innerHTML = '<i class="bi ' + m.icon + '" aria-hidden="true"></i>';
        const hLabel = document.createElement('span');
        hLabel.textContent = m.label;
        h.appendChild(hLabel);
        block.appendChild(h);

        const items = mediaByKind(kind);
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted small mb-2';
            empty.textContent = 'No ' + m.label.toLowerCase() + ' uploaded yet.';
            block.appendChild(empty);
        } else {
            items.forEach((it, i) => block.appendChild(buildFileRow(it, i, items.length)));
            /* #1968 P4 — a "Publish all N" convenience when a kind holds MORE
               than one admin-only row (a single row already has its own toggle). */
            const adminCount = items.filter((it) => (it.visibility || 'public') === 'admin').length;
            if (adminCount > 1) {
                const pubAll = document.createElement('button');
                pubAll.type = 'button';
                pubAll.className = 'btn btn-sm btn-outline-success mb-2';
                pubAll.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i> ';
                pubAll.appendChild(document.createTextNode('Publish all ' + adminCount));
                pubAll.addEventListener('click', () => publishAll(kind));
                block.appendChild(pubAll);
            }
        }

        /* upload row */
        const up = document.createElement('div');
        up.className = 'd-flex align-items-center gap-2 flex-wrap';
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = m.accept;
        fileInput.className = 'form-control form-control-sm';
        fileInput.style.maxWidth = '260px';
        const annoInput = document.createElement('input');
        annoInput.type = 'text';
        annoInput.maxLength = 255;
        annoInput.placeholder = 'Annotation (optional)';
        annoInput.className = 'form-control form-control-sm';
        annoInput.style.maxWidth = '240px';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-primary';
        btn.innerHTML = '<i class="bi bi-upload me-1" aria-hidden="true"></i>Upload';
        const statusEl = document.createElement('span');
        statusEl.className = 'text-muted small';
        btn.addEventListener('click', () => upload(kind, fileInput, annoInput, statusEl));
        up.append(fileInput, annoInput, btn, statusEl);
        block.appendChild(up);

        const hint = document.createElement('div');
        hint.className = 'form-text';
        hint.textContent = 'Max ' + fmtBytes(m.sizeCap) + '.';
        block.appendChild(hint);

        return block;
    }

    function render() {
        container.innerHTML = '';
        KIND_ORDER.forEach((kind) => container.appendChild(buildKindBlock(kind)));
    }

    const off = store.subscribe('media', render);
    render();

    return function teardown() {
        off();
        annoTimers.forEach((t) => clearTimeout(t));
        annoTimers.clear();
        annoPending.clear();   // #1846 — no lingering references once the tab is gone
        container.innerHTML = '';
    };
}
