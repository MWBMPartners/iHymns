<?php

declare(strict_types=1);

/**
 * iHymns — Song Editor v2 SHELL (#1200, Phase 5)
 *
 * The real v2 editor: a song-list sidebar (api2.php load_index) + a tabbed
 * editor that loads songs on click WITHOUT a full page reload — each switch
 * tears down the current tab instances, re-hydrates the reactive store from
 * load_song, and remounts the tabs for the new song. New / Delete / Import /
 * Paste & Reflow / Export live in the toolbar. Every edit still saves instantly,
 * atomically + granularly through the clean v2 API (api2.php). This lives
 * ALONGSIDE the legacy editor until per-phase cutover.
 *
 * Open: /manage/editor/editor2.php   (optionally ?song=<SongId> to deep-link)
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

if (!isAuthenticated()) {
    header('Location: /manage/login.php');
    exit;
}
$u = getCurrentUser();
if (!$u || !hasRole((string)($u['role'] ?? ''), 'editor')) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><body><h1>403 — editor access required</h1></body></html>';
    exit;
}

/* CSRF token api2.php validates (X-CSRF-Token header), emitted as a <meta> for
   the v2 api-client. */
$csrf   = csrfToken();
$songId = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['song'] ?? ''));   // optional deep-link

/* External-link type registry for the Links tab, shipped via window._iHymnsLinkTypes. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
$linkTypesForSong = loadExternalLinkTypesFor(getDbMysqli(), 'song');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Song Editor v2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/css/admin.css">
    <?php
    $themeInit = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-theme-init.php';
    if (is_file($themeInit)) { include $themeInit; }
    ?>
</head>
<body class="p-0">
    <div class="d-flex" style="height: 100vh;">
        <aside id="v2-sidebar" class="bg-body-tertiary" style="flex: 0 0 300px; min-width: 200px;"></aside>
        <div id="v2-grip" class="border-end border-start" title="Drag to resize" style="flex: 0 0 5px; cursor: col-resize;"></div>

        <main class="flex-grow-1 overflow-auto p-3" style="min-width: 0;">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <h1 class="h5 mb-0"><i class="bi bi-music-note-list me-2"></i>Song Editor <span class="badge bg-info">v2</span></h1>
                <div class="ms-auto d-flex gap-2 flex-wrap">
                    <button id="v2-new-btn" type="button" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New</button>
                    <a href="/manage/editor/import2.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-upload me-1"></i>Import</a>
                    <button id="v2-reflow-btn" type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-text-paragraph me-1"></i>Reflow</button>
                    <div class="dropdown">
                        <button id="v2-export-btn" type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download me-1"></i>Export</button>
                        <ul class="dropdown-menu dropdown-menu-end" id="v2-export-menu"></ul>
                    </div>
                    <button id="v2-delete-btn" type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                    <a href="/manage/editor/" class="btn btn-sm btn-outline-secondary">Legacy</a>
                </div>
            </div>
            <div id="v2-status" class="alert alert-secondary py-2 small" role="status">Loading…</div>

            <!-- Bulk-actions bar (shown when songs are selected in the sidebar's Select mode) -->
            <div id="v2-bulk-bar" class="alert alert-info py-2 px-3 d-none d-flex align-items-center gap-2 flex-wrap">
                <span id="v2-bulk-count" class="small fw-semibold"></span>
                <button id="v2-bulk-verify" type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-circle me-1"></i>Mark verified</button>
                <button id="v2-bulk-tag" type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tag me-1"></i>Add tag…</button>
                <button id="v2-bulk-clear" type="button" class="btn btn-sm btn-outline-secondary ms-auto">Clear</button>
            </div>

            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-structure" type="button"><i class="bi bi-list-ol me-1"></i>Structure</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-metadata" type="button"><i class="bi bi-info-circle me-1"></i>Metadata</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-credits" type="button"><i class="bi bi-people me-1"></i>Credits</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-links" type="button"><i class="bi bi-link-45deg me-1"></i>Links</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-tags" type="button"><i class="bi bi-tags me-1"></i>Tags</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-media" type="button"><i class="bi bi-collection-play me-1"></i>Media</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-preview" type="button"><i class="bi bi-eye me-1"></i>Preview</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-revisions" type="button"><i class="bi bi-clock-history me-1"></i>Revisions</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="pane-structure"><div id="v2-structure"></div></div>
                <div class="tab-pane fade" id="pane-metadata"><div id="v2-metadata"></div></div>
                <div class="tab-pane fade" id="pane-credits"><div id="v2-credits"></div></div>
                <div class="tab-pane fade" id="pane-links"><div id="v2-links"></div></div>
                <div class="tab-pane fade" id="pane-tags"><div id="v2-tags"></div></div>
                <div class="tab-pane fade" id="pane-media"><div id="v2-media"></div></div>
                <div class="tab-pane fade" id="pane-preview"><div id="v2-preview"></div></div>
                <div class="tab-pane fade" id="pane-revisions"><div id="v2-revisions"></div></div>
            </div>
        </main>
    </div>

    <!-- New song modal -->
    <div class="modal fade" id="v2-new-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6"><i class="bi bi-plus-lg me-1"></i>New song</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="v2-new-songbook">Songbook</label>
                        <select class="form-select form-select-sm" id="v2-new-songbook"></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="v2-new-title">Title</label>
                        <input type="text" class="form-control form-control-sm" id="v2-new-title" placeholder="Song title" maxlength="500">
                    </div>
                    <div id="v2-new-err" class="text-danger small"></div>
                    <p class="text-muted small mb-0">The server assigns the canonical id (<code>&lt;ABBR&gt;-NNNNNN</code>).</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" id="v2-new-create">Create</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Shared external-links modules (#833/#845) — classic globals the Links tab reuses. -->
    <script>window._iHymnsLinkTypes = <?= json_encode($linkTypesForSong, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/js/modules/external-link-detect.js"></script>
    <script src="/js/modules/external-links-editor.js"></script>

    <!-- Place-search (geocoder) for the Composition-origin picker — window.iHymnsPlaceSearch. -->
    <script src="/js/modules/place-search.js"></script>

    <!-- Export serializers (reused by export.js). propresenter-export.js first (format-export reuses its ZIP writer).
         #1567 — protobuf.min.js loaded FIRST: propresenter-export.js's ProPresenter
         7+ (.pro) encoder reads the `window.protobuf` global via its getProtobuf()
         helper (mirrors the v1 editor's index.php load order, #887); v2's export.js
         (ITEMS registry, mounted below via mountExportMenu) calls into the same
         window.iHymnsProPresenter this pair exposes, so without it PP7 export
         throws "protobufjs runtime not found" the first time it's invoked. -->
    <script src="vendor/protobuf.min.js"></script>
    <script src="propresenter-export.js"></script>
    <script src="format-export.js"></script>

    <script type="module">
        import { createStore }       from './v2/store.js';
        import { editorApi }         from './v2/api-client.js';
        import { mountSidebar }      from './v2/sidebar.js';
        import { mountStructureTab } from './v2/structure-tab.js';
        import { mountMetadataTab }  from './v2/metadata-tab.js';
        import { mountCreditsTab }   from './v2/credits-tab.js';
        import { mountLinksTab }     from './v2/links-tab.js';
        import { mountTagsTab }      from './v2/tags-tab.js';
        import { mountMediaTab }     from './v2/media-tab.js';
        import { mountPreviewTab }   from './v2/preview-tab.js';
        import { mountReflowModal }  from './v2/reflow-modal.js';
        import { mountExportMenu }   from './v2/export.js';
        import { mountRevisionsTab } from './v2/revisions-tab.js';

        const byId = (id) => document.getElementById(id);
        const initialSongId = <?= json_encode($songId) ?>;

        const statusEl = byId('v2-status');
        function status(msg, kind) {
            statusEl.textContent = msg;
            statusEl.className = 'alert py-2 small alert-' +
                (kind === 'danger' ? 'danger' : (kind === 'success' ? 'success' : 'secondary'));
        }
        const toast = (msg, type) => status(msg, type);

        /* One store for the whole shell; slices are replaced on each song switch. */
        const store = createStore({ song: {}, components: [], credits: {}, tags: [], links: [], media: [] });
        let teardowns = [];
        let currentSongId = null;
        let loadSeq = 0;   // monotonic token: only the latest load/delete applies (drops out-of-order results)

        function teardownTabs() {
            teardowns.forEach((fn) => { try { if (typeof fn === 'function') { fn(); } } catch (_e) {} });
            teardowns = [];
        }
        function mountTabs(songId) {
            const ctx = { store, api: editorApi, songId, toast };
            teardowns = [
                mountStructureTab(byId('v2-structure'), ctx),
                mountMetadataTab(byId('v2-metadata'), ctx),
                mountCreditsTab(byId('v2-credits'), ctx),
                mountLinksTab(byId('v2-links'), ctx),
                mountTagsTab(byId('v2-tags'), ctx),
                mountMediaTab(byId('v2-media'), ctx),
                mountPreviewTab(byId('v2-preview'), ctx),
                mountRevisionsTab(byId('v2-revisions'), ctx),
                mountReflowModal(byId('v2-reflow-btn'), ctx),
                mountExportMenu(byId('v2-export-menu'), ctx),
            ];
        }

        /* Load-on-select: tear down the current song's tabs, re-hydrate the store
           from load_song, remount for the new song. No full page reload. */
        async function loadSong(id) {
            if (!id) { return; }
            const seq = ++loadSeq;
            status('Loading…');
            try {
                const data = await editorApi.loadSong(id);
                if (seq !== loadSeq) { return; }   // a newer load/delete superseded this — drop the stale result
                teardownTabs();
                store.set('song', data.song || {});
                store.set('components', (data.components || []).map((c, i) => Object.assign({ _key: 'c' + i + '_' + (c.id || 'x') }, c)));
                store.set('credits', data.credits || {});
                store.set('tags', data.tags || []);
                store.set('links', data.links || []);
                store.set('media', data.media || []);
                currentSongId = id;
                mountTabs(id);
                try { history.replaceState(null, '', '?song=' + encodeURIComponent(id)); } catch (_e) {}
                sidebar.setActive(id);
                status('Editing "' + ((data.song && data.song.Title) || id) + '" — edits save instantly + atomically.', 'success');
            } catch (e) {
                if (seq !== loadSeq) { return; }   // don't surface a stale error after a newer switch
                status('Load failed: ' + e.message, 'danger');
            }
        }

        const bulkBar = byId('v2-bulk-bar');
        const bulkCount = byId('v2-bulk-count');
        function onSelChange(n) {
            bulkBar.classList.toggle('d-none', n === 0);
            bulkCount.textContent = n + ' song' + (n === 1 ? '' : 's') + ' selected';
        }
        const sidebar = mountSidebar(byId('v2-sidebar'), { api: editorApi, toast, onSelect: loadSong, onSelectionChange: onSelChange });

        /* ---- bulk actions (multi-select) ---- */
        byId('v2-bulk-clear').addEventListener('click', () => sidebar.clearSelection());
        byId('v2-bulk-verify').addEventListener('click', async () => {
            const ids = sidebar.getSelectedIds();
            if (!ids.length) { return; }
            try {
                await editorApi.bulkVerify(ids, true);
                toast('Marked ' + ids.length + ' song(s) verified.', 'success');
                sidebar.clearSelection();
            } catch (e) { status('Bulk verify failed: ' + e.message, 'danger'); }
        });
        byId('v2-bulk-tag').addEventListener('click', async () => {
            const ids = sidebar.getSelectedIds();
            if (!ids.length) { return; }
            const name = window.prompt('Tag to add to ' + ids.length + ' selected song(s):', '');
            if (name === null || name.trim() === '') { return; }
            try {
                const r = await editorApi.bulkTagAttach(ids, name.trim());
                toast('Tagged ' + (r.attached || 0) + ' of ' + ids.length + ' song(s) with "' + (r.tag ? r.tag.name : name.trim()) + '".', 'success');
                sidebar.clearSelection();
            } catch (e) { status('Bulk tag failed: ' + e.message, 'danger'); }
        });

        /* ---- resizable sidebar (#1193) — drag the grip; width persists ---- */
        (function () {
            const aside = byId('v2-sidebar');
            const grip = byId('v2-grip');
            const KEY = 'ihymns_editor_sidebar_w';
            const clampW = (w) => Math.max(200, Math.min(w, Math.round(window.innerWidth * 0.6)));
            const saved = parseInt(window.localStorage.getItem(KEY), 10);
            if (!isNaN(saved) && saved > 0) { aside.style.flexBasis = clampW(saved) + 'px'; }
            let dragging = false;
            grip.addEventListener('mousedown', (e) => { dragging = true; e.preventDefault(); document.body.style.userSelect = 'none'; });
            window.addEventListener('mousemove', (e) => {
                if (!dragging) { return; }
                aside.style.flexBasis = clampW(e.clientX - aside.getBoundingClientRect().left) + 'px';
            });
            window.addEventListener('mouseup', () => {
                if (!dragging) { return; }
                dragging = false;
                document.body.style.userSelect = '';
                const w = parseInt(aside.style.flexBasis, 10);
                if (!isNaN(w)) { try { window.localStorage.setItem(KEY, String(w)); } catch (_e) {} }
            });
        })();

        /* ---- New song ---- */
        const newModal = (window.bootstrap && window.bootstrap.Modal) ? new window.bootstrap.Modal(byId('v2-new-modal')) : null;
        byId('v2-new-btn').addEventListener('click', () => {
            const sel = byId('v2-new-songbook');
            sel.innerHTML = '';
            const books = sidebar.getSongbooks();
            if (!books.length) {
                const o = document.createElement('option');
                o.value = ''; o.textContent = '(song list still loading…)';
                sel.appendChild(o);
            } else {
                books.forEach((b) => {
                    const o = document.createElement('option');
                    o.value = b.abbr;
                    o.textContent = b.name + ' (' + b.abbr + ')';
                    sel.appendChild(o);
                });
            }
            byId('v2-new-title').value = '';
            byId('v2-new-err').textContent = '';
            if (newModal) { newModal.show(); }
        });
        byId('v2-new-create').addEventListener('click', async () => {
            const sb = byId('v2-new-songbook').value;
            const title = byId('v2-new-title').value.trim();
            const errEl = byId('v2-new-err');
            errEl.textContent = '';
            if (!sb) { errEl.textContent = 'Pick a songbook.'; return; }
            try {
                const res = await editorApi.createSong(sb, title || 'New Song');
                const book = sidebar.getSongbooks().find((b) => b.abbr === res.songbook);
                sidebar.addSong({ id: res.songId, number: null, title: res.title, songbook: res.songbook, songbookName: book ? book.name : res.songbook });
                if (newModal) { newModal.hide(); }
                loadSong(res.songId);
            } catch (e) {
                errEl.textContent = e.message;
            }
        });

        /* ---- Delete current song ---- */
        byId('v2-delete-btn').addEventListener('click', async () => {
            if (!currentSongId) { status('No song open to delete.', 'danger'); return; }
            const name = (store.get('song') && store.get('song').Title) || currentSongId;
            if (!window.confirm('Delete "' + name + '"?\n\nThis removes the song and ALL its data (sections, credits, tags, links, media, revisions). This cannot be undone.')) { return; }
            const gone = currentSongId;
            loadSeq++;   // invalidate any in-flight loadSong so it can't repaint the deleted song
            try {
                await editorApi.deleteSong(gone);
                sidebar.removeSong(gone);
                currentSongId = null;
                const next = sidebar.getFirstId();
                if (next) { loadSong(next); }
                else { teardownTabs(); status('Song deleted. Create a New song or pick one.', 'success'); }
            } catch (e) {
                status('Delete failed: ' + e.message, 'danger');
            }
        });

        /* ---- initial ---- */
        if (initialSongId) { loadSong(initialSongId); }
        else { status('Pick a song from the list, or create a New one.'); }
    </script>
</body>
</html>
