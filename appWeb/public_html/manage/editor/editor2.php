<?php

declare(strict_types=1);

/**
 * iHymns — Song Editor v2 PREVIEW shell (#1200, Phase 1)
 *
 * A minimal, self-contained host so the v2 Structure tab can be opened + verified
 * in a real browser while the rewrite is in flight (per the owner's verify loop).
 * Loads ONE song via the clean v2 API (api2.php), hydrates the reactive store,
 * and mounts the Structure tab. Every edit saves atomically + granularly — no
 * whole-song save, no race. This page lives ALONGSIDE the legacy editor; the
 * legacy editor stays the default until per-phase cutover.
 *
 * Open: /manage/editor/editor2.php?song=<SongId>
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

/* The CSRF token api2.php validates (X-CSRF-Token header) — emitted as a <meta>
   so the v2 api-client can read + send it. The legacy editor API had no CSRF;
   the v2 API requires it on every write. */
$csrf   = csrfToken();
$songId = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['song'] ?? ''));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Song Editor v2 — preview</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/css/admin.css">
    <?php
    /* Resolve theme (light/dark/contrast) before paint, like the rest of /manage. */
    $themeInit = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-theme-init.php';
    if (is_file($themeInit)) { include $themeInit; }
    ?>
</head>
<body class="p-3">
    <div class="container-fluid" style="max-width: 980px;">
        <div class="d-flex align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-music-note-list me-2"></i>Song Editor <span class="badge bg-info">v2 preview · Structure</span></h1>
            <a href="/manage/editor/" class="btn btn-sm btn-outline-secondary ms-auto">Legacy editor</a>
        </div>
        <div id="v2-status" class="alert alert-secondary py-2 small" role="status">Loading…</div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-structure" type="button"><i class="bi bi-list-ol me-1"></i>Structure</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-metadata" type="button"><i class="bi bi-info-circle me-1"></i>Metadata</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-credits" type="button"><i class="bi bi-people me-1"></i>Credits</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-preview" type="button"><i class="bi bi-eye me-1"></i>Preview</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="pane-structure"><div id="v2-structure"></div></div>
            <div class="tab-pane fade" id="pane-metadata"><div id="v2-metadata"></div></div>
            <div class="tab-pane fade" id="pane-credits"><div id="v2-credits"></div></div>
            <div class="tab-pane fade" id="pane-preview"><div id="v2-preview"></div></div>
        </div>

        <p class="text-muted small mt-3">
            Preview of the rewritten editor. Every edit saves <strong>instantly + atomically</strong>
            through the new granular API — no whole-song save, no save-race, no false-success toasts.
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script type="module">
        import { createStore }       from './v2/store.js';
        import { editorApi }         from './v2/api-client.js';
        import { mountStructureTab } from './v2/structure-tab.js';
        import { mountMetadataTab }  from './v2/metadata-tab.js';
        import { mountCreditsTab }   from './v2/credits-tab.js';
        import { mountPreviewTab }   from './v2/preview-tab.js';

        const songId   = <?= json_encode($songId) ?>;
        const statusEl = document.getElementById('v2-status');
        function status(msg, kind) {
            statusEl.textContent = msg;
            statusEl.className = 'alert py-2 small alert-' +
                (kind === 'danger' ? 'danger' : (kind === 'success' ? 'success' : 'secondary'));
        }
        const toast = (msg, type) => status(msg, type);

        (async () => {
            if (!songId) { status('Open as ?song=<SongId> to edit a song.', 'danger'); return; }
            try {
                const data  = await editorApi.loadSong(songId);
                const store = createStore({
                    song:       data.song || {},
                    components: (data.components || []).map((c, i) =>
                        Object.assign({ _key: 'c' + i + '_' + (c.id || 'x') }, c)),
                    credits:    data.credits || {},
                });
                const ctx = { store, api: editorApi, songId, toast };
                mountStructureTab(document.getElementById('v2-structure'), ctx);
                mountMetadataTab(document.getElementById('v2-metadata'), ctx);
                mountCreditsTab(document.getElementById('v2-credits'), ctx);
                mountPreviewTab(document.getElementById('v2-preview'), ctx);
                status('Loaded "' + ((data.song && data.song.Title) || songId) + '" — edits save instantly + atomically.', 'success');
            } catch (e) {
                status('Load failed: ' + e.message, 'danger');
            }
        })();
    </script>
</body>
</html>
