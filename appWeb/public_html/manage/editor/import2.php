<?php

declare(strict_types=1);

/**
 * iHymns — Song Editor v2 IMPORT page (#1200, Phase 4b.2)
 *
 * A clean, standalone bulk-import surface for the v2 editor: upload one song
 * file, the server parses it with the SHARED importers (includes/song_importers.php
 * — the same parsers the legacy editor uses) and INSERT-saves new songs (existing
 * songs are skipped). Every write goes through the CSRF-guarded v2 API
 * (api2.php?action=import_file). The async ZIP (many songs) path lands next (4b.3).
 *
 * Open: /manage/editor/import2.php
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

$csrf = csrfToken();

/* Format key => [friendly label, accept hint]. The single source of the v2
   single-file import surface; mirrors the legacy single-file bulk_import_* set. */
$formats = [
    'auto'        => 'Auto-detect from file',
    'videopsalm'  => 'VideoPsalm (.json)',
    'openlp'      => 'OpenLP / OpenLyrics (.xml)',
    'pro6'        => 'ProPresenter 6 (.pro6)',
    'freeshow'    => 'FreeShow (.show)',
    'proclaim'    => 'Proclaim (.txt)',
    'pptx'        => 'PowerPoint (.pptx)',
    'easyworship' => 'EasyWorship (.db)',
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Song Editor v2 — import</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/css/admin.css">
    <?php
    $themeInit = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-theme-init.php';
    if (is_file($themeInit)) { include $themeInit; }
    ?>
</head>
<body class="p-3">
    <div class="container-fluid" style="max-width: 760px;">
        <div class="d-flex align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-upload me-2"></i>Import songs <span class="badge bg-info">v2</span></h1>
            <a href="/manage/editor/" class="btn btn-sm btn-outline-secondary ms-auto">Editor</a>
        </div>

        <p class="text-muted small">
            Upload one song file. Existing songs are <strong>skipped</strong> (import never overwrites).
            Bulk <code>.zip</code> import (many songs) lands in the next step.
        </p>

        <div class="card">
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small mb-1" for="imp-file">File</label>
                    <input type="file" id="imp-file" class="form-control" accept=".json,.xml,.pro6,.show,.txt,.pptx,.db">
                </div>
                <div class="row g-3">
                    <div class="col-12 col-sm-7">
                        <label class="form-label small mb-1" for="imp-format">Format</label>
                        <select id="imp-format" class="form-select form-select-sm">
                            <?php foreach ($formats as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-5 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="imp-dedupe">
                            <label class="form-check-label small" for="imp-dedupe">Skip title duplicates</label>
                        </div>
                    </div>
                </div>
                <button id="imp-go" type="button" class="btn btn-primary btn-sm mt-3"><i class="bi bi-upload me-1"></i>Import</button>
                <span id="imp-status" class="text-muted small ms-2"></span>
            </div>
        </div>

        <div id="imp-result" class="mt-3"></div>
    </div>

    <script>
    (function () {
        'use strict';
        var fileEl   = document.getElementById('imp-file');
        var fmtEl    = document.getElementById('imp-format');
        var dedupeEl = document.getElementById('imp-dedupe');
        var goBtn    = document.getElementById('imp-go');
        var statusEl = document.getElementById('imp-status');
        var resultEl = document.getElementById('imp-result');

        function csrf() {
            var m = document.querySelector('meta[name="csrf-token"]');
            return m ? (m.getAttribute('content') || '') : '';
        }

        /* Render the server summary as Bootstrap alerts. All server text goes via
           textContent — never innerHTML — so a malformed filename/error can't inject. */
        function renderSummary(data) {
            resultEl.innerHTML = '';
            var created = Number(data.songs_created || 0);
            var skipped = Number(data.songs_skipped_existing || 0);
            var failed  = Number(data.songs_failed || 0);

            var box = document.createElement('div');
            box.className = 'alert ' + (failed > 0 ? 'alert-warning' : 'alert-success');
            box.textContent = created + ' new · ' + skipped + ' already in DB (skipped) · ' + failed + ' failed';
            resultEl.appendChild(box);

            var books = (data.songbooks_created || []);
            if (books.length) {
                var bp = document.createElement('p');
                bp.className = 'small mb-1';
                bp.textContent = 'Songbooks created: ' + books.join(', ');
                resultEl.appendChild(bp);
            }
            var errs = (data.errors || []);
            if (errs.length) {
                var ul = document.createElement('ul');
                ul.className = 'small text-danger';
                errs.slice(0, 50).forEach(function (e) {
                    var li = document.createElement('li');
                    li.textContent = (typeof e === 'string') ? e : ((e.entry ? e.entry + ': ' : '') + (e.error || JSON.stringify(e)));
                    ul.appendChild(li);
                });
                resultEl.appendChild(ul);
            }
        }

        goBtn.addEventListener('click', function () {
            var file = fileEl.files && fileEl.files[0];
            if (!file) { statusEl.textContent = 'Choose a file first.'; return; }
            var fd = new FormData();
            fd.append('file', file);
            fd.append('format', fmtEl.value);
            fd.append('dedupeMode', dedupeEl.checked ? 'skip-title' : 'off');

            goBtn.disabled = true;
            statusEl.textContent = 'Importing…';
            resultEl.innerHTML = '';
            fetch('/manage/editor/api2.php?action=import_file', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
                body: fd,
            }).then(function (res) {
                return res.json().catch(function () { return { ok: false, error: 'HTTP ' + res.status }; });
            }).then(function (data) {
                goBtn.disabled = false;
                statusEl.textContent = '';
                if (!data || data.ok !== true) {
                    var err = document.createElement('div');
                    err.className = 'alert alert-danger';
                    err.textContent = 'Import failed: ' + ((data && (data.error_detail || data.error)) || 'unknown error');
                    resultEl.appendChild(err);
                    return;
                }
                renderSummary(data);
            }).catch(function (e) {
                goBtn.disabled = false;
                statusEl.textContent = '';
                var err = document.createElement('div');
                err.className = 'alert alert-danger';
                err.textContent = 'Import failed: ' + (e && e.message ? e.message : e);
                resultEl.appendChild(err);
            });
        });
    })();
    </script>
</body>
</html>
