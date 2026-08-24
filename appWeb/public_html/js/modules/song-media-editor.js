/*
 * iHymns — Song Media Editor (#853)
 *
 * ESM module that drives the Song Editor's "Media" tab.
 *
 * Responsibilities:
 *   - List existing media for the active song (one block per kind).
 *   - Per-row: annotation edit (debounced save), drag-handle reorder
 *     (within a kind only — kinds don't intermix), delete with confirm.
 *   - Per-kind: file picker + upload button. Mime + size pre-checks
 *     happen in the browser to fail fast; the server still validates
 *     authoritatively (this module never trusts its own checks).
 *
 * Lifecycle:
 *   bootSongMediaEditor(rootElement) is called once on page load. The
 *   module subscribes to document's 'iHymns:song-loaded' event so it
 *   refreshes whenever the curator picks a different song. It also
 *   refreshes when the Media tab is shown — covers the case where the
 *   tab was hidden during the load event.
 *
 *   #1581 — this literal is INTENTIONALLY not migrated to the shared
 *   EVT_* registry in constants.js. Its dispatch side, editor.js, is a
 *   classic (non-module) script with zero import/export — it cannot
 *   `import` constants.js. The pair still matches today (both spelled
 *   with the historical capital-H), so it's left as a documented,
 *   allow-listed exception (see tests/test-event-names.js) rather than
 *   forced into a registry only one side can reach.
 *
 * Talks to:
 *   - GET  /manage/editor/api?action=song_media_list&song_id=<id>
 *   - POST /manage/editor/api?action=song_media_upload   (multipart)
 *   - POST /manage/editor/api?action=song_media_update
 *   - POST /manage/editor/api?action=song_media_reorder
 *   - POST /manage/editor/api?action=song_media_delete
 */

import { apiFetch } from '../utils/api-client.js';

const API_BASE = '/manage/editor/api';

const KIND_META = {
    'audio': {
        label: 'Audio',
        icon: 'bi-music-note-beamed',
        helpText: 'Recordings — MP3, M4A, AAC, OGG, WAV, FLAC, ALAC.',
        accept: 'audio/mpeg,audio/mp4,audio/aac,audio/wav,audio/x-wav,audio/wave,audio/flac,audio/x-flac,audio/ogg,audio/aiff,audio/x-aiff,.mp3,.m4a,.aac,.wav,.flac,.ogg,.aiff,.alac',
        sizeCap: 50 * 1024 * 1024,
    },
    'sheet-music': {
        label: 'Sheet music (PDF)',
        icon: 'bi-file-earmark-pdf',
        helpText: 'Notation as PDF — full score, lead sheet, lyrics-only sheet, etc.',
        accept: 'application/pdf,.pdf',
        sizeCap: 10 * 1024 * 1024,
    },
    'midi': {
        label: 'MIDI',
        icon: 'bi-music-player',
        helpText: 'MIDI sequence — for accompaniment apps, projection software.',
        accept: 'audio/midi,audio/x-midi,audio/mid,.mid,.midi',
        sizeCap: 1 * 1024 * 1024,
    },
    'musicxml': {
        label: 'MusicXML',
        icon: 'bi-file-earmark-code',
        helpText: 'MusicXML notation — interchangeable score format.',
        accept: 'application/vnd.recordare.musicxml+xml,application/xml,text/xml,.musicxml,.xml',
        sizeCap: 1 * 1024 * 1024,
    },
};

const KIND_ORDER = ['audio', 'sheet-music', 'midi', 'musicxml'];

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatBytes(n) {
    if (!Number.isFinite(n) || n <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function formatDate(s) {
    if (!s) return '';
    /* "2026-05-04 14:23:11" → "2026-05-04 14:23" — the seconds rarely
       help and clutter the row. */
    return String(s).replace(/:\d{2}$/, '');
}

/**
 * Bootstrap the Song Media Editor on the given root element.
 * @param {HTMLElement} root  The panel container (#panel-media).
 */
export function bootSongMediaEditor(root) {
    if (!root) return null;

    let currentSongId = null;
    let inflight = null;       /* AbortController for the most recent list fetch */
    let mediaByKind = {};      /* {kind: [row, ...]} keyed for render */

    /* Top-level layout: per-kind block, each with its own upload form
       and row list. Rendered once on boot; rows render dynamically. */
    root.innerHTML = `
        <div class="song-media-empty text-muted small py-3" data-state="empty" hidden>
            Pick a song to manage its media.
        </div>
        <div class="song-media-loading text-muted small py-3" data-state="loading" hidden>
            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
            Loading media…
        </div>
        <div class="song-media-error alert alert-danger small py-2 px-3 mb-3" data-state="error" hidden></div>
        <div class="song-media-blocks" data-state="ready" hidden></div>
    `;

    const elEmpty   = root.querySelector('[data-state="empty"]');
    const elLoading = root.querySelector('[data-state="loading"]');
    const elError   = root.querySelector('[data-state="error"]');
    const elBlocks  = root.querySelector('[data-state="ready"]');

    function showState(name, message) {
        elEmpty.hidden = name !== 'empty';
        elLoading.hidden = name !== 'loading';
        elError.hidden = name !== 'error';
        elBlocks.hidden = name !== 'ready';
        if (name === 'error') {
            elError.textContent = message || 'Could not load media.';
        }
    }

    /* ----- Server calls -------------------------------------------- */

    async function apiList(songId) {
        if (inflight) inflight.abort();
        inflight = new AbortController();
        const url = `${API_BASE}?action=song_media_list&song_id=${encodeURIComponent(songId)}`;
        const res = await apiFetch(url, {
            credentials: 'same-origin',
            signal: inflight.signal,
        });
        if (!res.ok) throw new Error(`List failed: HTTP ${res.status}`);
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        return Array.isArray(data.media) ? data.media : [];
    }

    async function apiUpload(songId, kind, file, annotation) {
        const fd = new FormData();
        fd.append('song_id', songId);
        fd.append('kind', kind);
        fd.append('annotation', annotation || '');
        fd.append('file', file);
        const res = await apiFetch(`${API_BASE}?action=song_media_upload`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            throw new Error(data.error || `Upload failed: HTTP ${res.status}`);
        }
        return data.media;
    }

    async function apiUpdate(mediaId, annotation) {
        const fd = new FormData();
        fd.append('media_id', String(mediaId));
        fd.append('annotation', annotation || '');
        const res = await apiFetch(`${API_BASE}?action=song_media_update`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            throw new Error(data.error || `Update failed: HTTP ${res.status}`);
        }
        return data;
    }

    async function apiDelete(mediaId) {
        const fd = new FormData();
        fd.append('media_id', String(mediaId));
        const res = await apiFetch(`${API_BASE}?action=song_media_delete`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            throw new Error(data.error || `Delete failed: HTTP ${res.status}`);
        }
        return data;
    }

    async function apiReorder(songId, kind, ids) {
        const fd = new FormData();
        fd.append('song_id', songId);
        fd.append('kind', kind);
        ids.forEach(id => fd.append('ids[]', String(id)));
        const res = await apiFetch(`${API_BASE}?action=song_media_reorder`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            throw new Error(data.error || `Reorder failed: HTTP ${res.status}`);
        }
        return data;
    }

    /* ----- Render --------------------------------------------------- */

    function groupByKind(rows) {
        const out = {};
        KIND_ORDER.forEach(k => { out[k] = []; });
        rows.forEach(r => {
            if (!out[r.kind]) out[r.kind] = [];
            out[r.kind].push(r);
        });
        return out;
    }

    function rowMarkup(row) {
        const meta = KIND_META[row.kind] || { icon: 'bi-file-earmark', label: row.kind };
        return `
            <div class="card bg-body-tertiary border-secondary mb-2 song-media-row"
                 data-media-id="${row.id}"
                 draggable="true">
                <div class="card-body py-2">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-grip-vertical text-muted mt-1"
                           aria-hidden="true"
                           title="Drag to reorder"></i>
                        <i class="bi ${escapeHtml(meta.icon)} text-info mt-1" aria-hidden="true"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <a href="${escapeHtml(row.stream_url)}"
                                   target="_blank" rel="noopener"
                                   class="text-info text-decoration-none small fw-semibold text-truncate"
                                   title="Open in new tab">
                                    ${escapeHtml(row.file_name)}
                                </a>
                                <span class="text-muted small">
                                    ${escapeHtml(row.mime_type)} · ${formatBytes(row.size_bytes)}
                                </span>
                            </div>
                            <input type="text"
                                   class="form-control form-control-sm mt-1 song-media-annotation"
                                   placeholder="Optional annotation (e.g. '1989 BBC recording')"
                                   maxlength="255"
                                   value="${escapeHtml(row.annotation || '')}">
                            <div class="text-muted" style="font-size: 0.7rem; margin-top: 0.15rem;">
                                Uploaded ${escapeHtml(formatDate(row.uploaded_at))}
                                · Backend: ${escapeHtml(row.storage_backend)}
                            </div>
                        </div>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger song-media-delete"
                                title="Remove this file">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    function blockMarkup(kind) {
        const meta = KIND_META[kind];
        const rows = mediaByKind[kind] || [];
        const rowsHtml = rows.length
            ? rows.map(rowMarkup).join('')
            : `<div class="text-muted small fst-italic py-2">No ${meta.label.toLowerCase()} uploaded yet.</div>`;
        const capMb = (meta.sizeCap / (1024 * 1024)).toFixed(meta.sizeCap < 1024 * 1024 * 5 ? 1 : 0);
        return `
            <div class="form-section song-media-block" data-kind="${kind}">
                <h6 class="section-title d-flex justify-content-between align-items-center">
                    <span><i class="bi ${escapeHtml(meta.icon)} me-1"></i>${escapeHtml(meta.label)}</span>
                    <span class="text-muted small fw-normal">${rows.length} file${rows.length === 1 ? '' : 's'}</span>
                </h6>
                <div class="text-muted small mb-2">${escapeHtml(meta.helpText)} Max ${capMb} MB.</div>
                <div class="song-media-rows" data-rows="${kind}">${rowsHtml}</div>
                <div class="row g-2 align-items-end mt-1">
                    <div class="col-md-6">
                        <input type="file"
                               class="form-control form-control-sm song-media-file"
                               data-upload-kind="${kind}"
                               accept="${escapeHtml(meta.accept)}">
                    </div>
                    <div class="col-md-4">
                        <input type="text"
                               class="form-control form-control-sm song-media-pending-annotation"
                               data-upload-kind="${kind}"
                               placeholder="Optional annotation"
                               maxlength="255">
                    </div>
                    <div class="col-md-2">
                        <button type="button"
                                class="btn btn-sm btn-amber w-100 song-media-upload-btn"
                                data-upload-kind="${kind}"
                                disabled>
                            <i class="bi bi-cloud-upload me-1"></i>Upload
                        </button>
                    </div>
                </div>
                <div class="song-media-upload-status small text-muted mt-1" data-status-kind="${kind}"></div>
            </div>
        `;
    }

    function render() {
        if (!currentSongId) {
            showState('empty');
            return;
        }
        /* #938 — visually separate the four media kinds. The blockMarkup
           output had no inter-block spacing, so Audio / Sheet music /
           MIDI / MusicXML ran together and the curator had to read
           carefully to find the boundary. Wrap each non-first block in
           a top-margin + top-border separator. */
        elBlocks.innerHTML = KIND_ORDER.map((kind, i) => {
            const html = blockMarkup(kind);
            if (i === 0) return html;
            return `<div class="mt-4 pt-4 border-top border-secondary border-opacity-25">${html}</div>`;
        }).join('');
        showState('ready');
        bindBlockHandlers();
    }

    /* ----- Event wiring -------------------------------------------- */

    function bindBlockHandlers() {
        elBlocks.querySelectorAll('.song-media-file').forEach(input => {
            input.addEventListener('change', () => {
                const kind = input.dataset.uploadKind;
                const btn = elBlocks.querySelector(`.song-media-upload-btn[data-upload-kind="${kind}"]`);
                btn.disabled = !(input.files && input.files[0]);
            });
        });

        elBlocks.querySelectorAll('.song-media-upload-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const kind = btn.dataset.uploadKind;
                const fileInput = elBlocks.querySelector(`.song-media-file[data-upload-kind="${kind}"]`);
                const noteInput = elBlocks.querySelector(`.song-media-pending-annotation[data-upload-kind="${kind}"]`);
                const status    = elBlocks.querySelector(`[data-status-kind="${kind}"]`);
                if (!fileInput || !fileInput.files || !fileInput.files[0]) return;
                const file = fileInput.files[0];
                const meta = KIND_META[kind];
                if (file.size > meta.sizeCap) {
                    status.className = 'song-media-upload-status small text-danger mt-1';
                    status.textContent = `File too large (${formatBytes(file.size)} > ${formatBytes(meta.sizeCap)}).`;
                    return;
                }
                btn.disabled = true;
                status.className = 'song-media-upload-status small text-muted mt-1';
                status.textContent = `Uploading ${escapeHtml(file.name)}…`;
                try {
                    const newRow = await apiUpload(currentSongId, kind, file, noteInput.value);
                    if (!mediaByKind[kind]) mediaByKind[kind] = [];
                    mediaByKind[kind].push(newRow);
                    fileInput.value = '';
                    noteInput.value = '';
                    status.className = 'song-media-upload-status small text-success mt-1';
                    status.textContent = `Uploaded ${escapeHtml(file.name)}.`;
                    render();
                } catch (err) {
                    status.className = 'song-media-upload-status small text-danger mt-1';
                    status.textContent = err.message || 'Upload failed.';
                    btn.disabled = false;
                }
            });
        });

        elBlocks.querySelectorAll('.song-media-row').forEach(rowEl => {
            const id = Number(rowEl.dataset.mediaId);

            const noteInput = rowEl.querySelector('.song-media-annotation');
            if (noteInput) {
                let timer;
                noteInput.addEventListener('input', () => {
                    clearTimeout(timer);
                    timer = setTimeout(() => {
                        apiUpdate(id, noteInput.value).catch(() => {
                            noteInput.classList.add('is-invalid');
                            setTimeout(() => noteInput.classList.remove('is-invalid'), 1500);
                        });
                    }, 400);
                });
            }

            const delBtn = rowEl.querySelector('.song-media-delete');
            if (delBtn) {
                delBtn.addEventListener('click', async () => {
                    if (!confirm('Remove this file? Cannot be undone.')) return;
                    try {
                        await apiDelete(id);
                        const block = rowEl.closest('.song-media-block');
                        const kind  = block?.dataset.kind;
                        if (kind && mediaByKind[kind]) {
                            mediaByKind[kind] = mediaByKind[kind].filter(r => r.id !== id);
                        }
                        render();
                    } catch (err) {
                        alert(err.message || 'Delete failed.');
                    }
                });
            }
        });

        /* Drag-reorder within a kind. Cross-kind drops are blocked
           by checking the target's data-kind matches the source. */
        elBlocks.querySelectorAll('.song-media-rows').forEach(container => {
            const kind = container.dataset.rows;
            let dragSrc = null;

            container.addEventListener('dragstart', (e) => {
                const card = e.target.closest('.song-media-row');
                if (!card) return;
                dragSrc = card;
                card.style.opacity = '0.5';
                e.dataTransfer.effectAllowed = 'move';
                /* Required for Firefox to actually fire dragstart. */
                e.dataTransfer.setData('text/plain', card.dataset.mediaId);
            });

            container.addEventListener('dragend', () => {
                if (dragSrc) dragSrc.style.opacity = '';
                dragSrc = null;
            });

            container.addEventListener('dragover', (e) => {
                if (!dragSrc) return;
                e.preventDefault();
                const target = e.target.closest('.song-media-row');
                if (!target || target === dragSrc) return;
                const rect = target.getBoundingClientRect();
                const before = (e.clientY - rect.top) < (rect.height / 2);
                container.insertBefore(dragSrc, before ? target : target.nextSibling);
            });

            container.addEventListener('drop', async (e) => {
                e.preventDefault();
                if (!dragSrc) return;
                const ids = Array.from(container.querySelectorAll('.song-media-row'))
                    .map(el => Number(el.dataset.mediaId));
                /* Optimistic UI: keep the new order, only revert on
                   server failure. */
                try {
                    await apiReorder(currentSongId, kind, ids);
                    if (mediaByKind[kind]) {
                        const idMap = new Map(mediaByKind[kind].map(r => [r.id, r]));
                        mediaByKind[kind] = ids.map(id => idMap.get(id)).filter(Boolean);
                    }
                } catch (err) {
                    /* Re-fetch authoritative state on failure so UI
                       matches server reality. */
                    refresh();
                }
            });
        });
    }

    /* ----- Refresh ------------------------------------------------- */

    async function refresh() {
        if (!currentSongId) {
            showState('empty');
            return;
        }
        showState('loading');
        try {
            const rows = await apiList(currentSongId);
            mediaByKind = groupByKind(rows);
            render();
        } catch (err) {
            if (err.name === 'AbortError') return;
            showState('error', err.message || 'Could not load media.');
        }
    }

    /* ----- Public hooks -------------------------------------------- */

    /* Pre-existing song selection: read window.currentSongId on boot
       so a curator who already had a song open before the module
       loaded sees their media on first tab-show. */
    if (typeof window.currentSongId === 'string' || typeof window.currentSongId === 'number') {
        currentSongId = String(window.currentSongId || '');
        if (currentSongId) refresh();
    }

    /* #1581 — literal kept on purpose; see the file doc-block above. */
    document.addEventListener('iHymns:song-loaded', (e) => {
        const sid = String(e?.detail?.songId || '');
        if (!sid || sid === currentSongId) {
            /* Same song re-selected — no-op (avoids a redundant refetch). */
            if (!sid) currentSongId = null;
            if (!sid) showState('empty');
            return;
        }
        currentSongId = sid;
        refresh();
    });

    /* Tab-shown: refresh in case the tab was hidden during a song load
       or the user switched away while a fetch was inflight. */
    const tabBtn = document.getElementById('tab-media');
    if (tabBtn) {
        tabBtn.addEventListener('shown.bs.tab', () => {
            if (currentSongId) refresh();
        });
    }

    return { refresh };
}
