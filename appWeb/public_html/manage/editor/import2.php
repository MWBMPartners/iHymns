<?php

declare(strict_types=1);

/**
 * iHymns — Song Editor v2 IMPORT page (#1200, Phase 4b)
 *
 * A clean, standalone bulk-import surface for the v2 editor. Two paths, routed
 * by file type, both through the CSRF-guarded v2 API (api2.php) using the SHARED
 * importers (includes/song_importers.php — the same parsers the legacy editor
 * uses):
 *   - single song file (.json/.xml/.pro6/.pro/.probundle/.show/.txt/.pptx/.db) → import_file (sync)
 *   - .zip of many songs → import_zip (async: fastcgi worker + tblBulkImportJobs),
 *     tracked live by the shared bulk-import-progress.js widget.
 * Import is INSERT-only — existing songs are skipped, never overwritten.
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

/* Single-file format key => friendly label. ZIP is handled separately (async). */
$formats = [
    'auto'        => 'Auto-detect from file',
    /* #1633 — .json is shared by two formats. Auto-detect content-sniffs them
       apart (conservatively, favouring VideoPsalm), but both stay explicitly
       pickable so an operator can override a sniff that guessed wrong. */
    'ihymns'      => 'iHymns interchange (.json)',
    'videopsalm'  => 'VideoPsalm (.json)',
    'openlp'      => 'OpenLP / OpenLyrics (.xml)',
    /* #882 — auto-detect on a .xml/.opensong upload already tries BOTH
       OpenLyrics and OpenSong (_bulkImport_processXmlAuto()); this entry
       lets an operator pick OpenSong explicitly when they know the format
       and want to skip the sniff (or override a sniff that guessed wrong —
       same #1633 precedent as the iHymns/VideoPsalm .json pair above). */
    'opensong'    => 'OpenSong (.xml)',
    'pro6'        => 'ProPresenter 6 (.pro6)',
    /* epic #1968 / #885 — ProPresenter 7+ '.pro' protobuf import. '.pro' is
       genuinely ambiguous (ChordPro's own docs bless the extension too),
       which is why the ChordPro label below no longer claims it: `auto`
       content-sniffs a '.pro' upload (_bulkImport_sniffProDialect()) and
       routes it to whichever of pro7/pro6/chordpro the bytes actually are;
       an operator who KNOWS the file is ProPresenter 7+ can also pick this
       entry explicitly to bypass the sniff. */
    'pro7'        => 'ProPresenter 7+ (.pro)',
    /* epic #1968 P2 — `.probundle` is ProPresenter's own ZIP container (one
       or more `.pro` presentations + media at the root); unlike bare `.pro`
       it carries no ambiguity with another format, so `auto` maps it
       straight to this entry via the `.probundle` extension (no sniff
       needed). Media entries are reported, not imported (media ingest is a
       later phase, plan §6 / P4). */
    'probundle'   => 'ProPresenter 7+ Bundle (.probundle)',
    'freeshow'    => 'FreeShow (.show)',
    'proclaim'    => 'Proclaim (.txt)',
    /* #1264 — ChordPro was already accepted by api2.php's import_file
       (auto-mapped from .cho/.chopro/.crd/.chord) but had no dropdown
       entry and was excluded from the file picker's `accept` list, so an
       operator could never actually reach it from this page. '.pro' moved
       to the 'pro7' entry above (epic #1968) — a genuine ChordPro '.pro'
       still imports correctly via `auto`'s content sniff, or by picking
       ChordPro here explicitly. */
    'chordpro'    => 'ChordPro (.cho, .chopro, .crd, .chord)',
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
    <?php
    /* #1676 — Bootstrap CSS from the shared emitter. Same story as editor2.php:
       hardcoded 5.3.3 with no `integrity`, while the registry says 5.3.6. */
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap_assets.php';
    echo ihymns_bootstrap_css_links();
    $_pubRoot = dirname(__DIR__, 2);
    ?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime($_pubRoot . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime($_pubRoot . '/css/admin.css') ?>">
    <!-- Accessibility modes (#1643). This shell includes admin-theme-init.php
         below, so it DOES stamp data-ihymns-contrast / data-ihymns-cvd on <html>
         — without this link it stamps an intent it then ships no CSS to honour.
         Must stay after admin.css so the high-contrast !important rules win. -->
    <link rel="stylesheet" href="/css/accessibility.css?v=<?= filemtime($_pubRoot . '/css/accessibility.css') ?>">
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
            Upload a single song file, or a <strong>.zip</strong> of many songs (imported in the
            background with a live progress tracker). Existing songs are <strong>skipped</strong> —
            import never overwrites.
        </p>

        <div class="card">
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small mb-1" for="imp-file">File</label>
                    <input type="file" id="imp-file" class="form-control" accept=".json,.xml,.opensong,.pro6,.show,.txt,.cho,.chopro,.crd,.chord,.pro,.probundle,.pptx,.db,.zip">
                </div>
                <div class="row g-3">
                    <div class="col-12 col-sm-7">
                        <label class="form-label small mb-1" for="imp-format">Format <span class="text-muted">(single file only)</span></label>
                        <select id="imp-format" class="form-select form-select-sm">
                            <?php foreach ($formats as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-5 d-flex flex-column justify-content-end gap-1">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="imp-dedupe">
                            <label class="form-check-label small" for="imp-dedupe">Skip title duplicates</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="imp-dryrun">
                            <label class="form-check-label small" for="imp-dryrun">Dry run (preview only — nothing is written)</label>
                        </div>
                    </div>
                </div>
                <button id="imp-go" type="button" class="btn btn-primary btn-sm mt-3"><i class="bi bi-upload me-1"></i>Import</button>
                <span id="imp-status" class="text-muted small ms-2"></span>
            </div>
        </div>

        <div id="imp-result" class="mt-3"></div>
    </div>

    <script type="module">
        import {
            bootBulkImportProgressWidget,
            startUploadTracking,
            updateUploadProgress,
            startTracking,
            dismiss,
        } from '/js/modules/bulk-import-progress.js';

        bootBulkImportProgressWidget();   // mounts the floating tracker + resumes any stale job

        const fileEl   = document.getElementById('imp-file');
        const fmtEl    = document.getElementById('imp-format');
        const dedupeEl = document.getElementById('imp-dedupe');
        const dryRunEl = document.getElementById('imp-dryrun');
        const goBtn    = document.getElementById('imp-go');
        const statusEl = document.getElementById('imp-status');
        const resultEl = document.getElementById('imp-result');

        function csrf() {
            const m = document.querySelector('meta[name="csrf-token"]');
            return m ? (m.getAttribute('content') || '') : '';
        }

        /* Render a sync summary as Bootstrap alerts — server text via textContent only.
           #1674 — branches ONLY on the `dry_run` key the server echoes back
           (rule #35: a status/data key is the contract, never a re-derived
           guess client-side). */
        function renderSummary(data) {
            resultEl.innerHTML = '';
            const isDryRun = data.dry_run === true;
            if (isDryRun) {
                const note = document.createElement('div');
                note.className = 'alert alert-info';
                note.textContent = 'DRY RUN — nothing was written.';
                resultEl.appendChild(note);
            }
            const created = Number(data.songs_created || 0);
            const skipped = Number(data.songs_skipped_existing || 0);
            const failed  = Number(data.songs_failed || 0);
            const box = document.createElement('div');
            box.className = 'alert ' + (failed > 0 ? 'alert-warning' : 'alert-success');
            box.textContent = isDryRun
                ? (created + ' would be created · ' + skipped + ' already in DB (would skip) · ' + failed + ' failed to parse')
                : (created + ' new · ' + skipped + ' already in DB (skipped) · ' + failed + ' failed');
            resultEl.appendChild(box);
            const books = (data.songbooks_created || []);
            if (books.length) {
                const bp = document.createElement('p');
                bp.className = 'small mb-1';
                bp.textContent = (isDryRun ? 'Songbooks that would be created: ' : 'Songbooks created: ') + books.join(', ');
                resultEl.appendChild(bp);
            }
            const errs = (data.errors || []);
            if (errs.length) {
                const ul = document.createElement('ul');
                ul.className = 'small text-danger';
                errs.slice(0, 50).forEach((e) => {
                    const li = document.createElement('li');
                    li.textContent = (typeof e === 'string') ? e : ((e.entry ? e.entry + ': ' : '') + (e.error || JSON.stringify(e)));
                    ul.appendChild(li);
                });
                resultEl.appendChild(ul);
            }
        }

        function showError(msg) {
            resultEl.innerHTML = '';
            const err = document.createElement('div');
            err.className = 'alert alert-danger';
            err.textContent = 'Import failed: ' + msg;
            resultEl.appendChild(err);
        }

        /* ZIP → async: XHR (for upload progress) → import_zip → live widget polling. */
        function importZip(file) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('dedupeMode', dedupeEl.checked ? 'skip-title' : 'off');
            /* #1911 — round-trips through tblBulkImportJobs.DryRun; the
               poll widget renders the preview banner from the server's own
               `dry_run` echo (rule #35), never from this checkbox state. */
            fd.append('dryRun', dryRunEl.checked ? '1' : '0');
            startUploadTracking({ filename: file.name, sizeBytes: file.size });
            const xhr = new XMLHttpRequest();
            /* #1855: extensionless — the literal .php URL is 301'd by
               .htaccess, and a browser replays a 301'd POST as a body-less GET,
               which would silently drop this whole upload. */
            xhr.open('POST', '/manage/editor/api2?action=import_zip', true);
            xhr.withCredentials = true;
            /* api2.php gates EVERY POST on this header and has no X-CSRF-Token
               fallback (#1677), so without it the upload 403s before any import
               logic runs. XMLHttpRequest does NOT add it by itself — that is
               jQuery — so it must be set explicitly. */
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-CSRF-Token', csrf());
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) { updateUploadProgress({ loaded: e.loaded, total: e.total }); }
            };
            xhr.onload = () => {
                goBtn.disabled = false;
                statusEl.textContent = '';
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (_e) { /* non-JSON */ }
                if (!data || data.ok !== true) {
                    dismiss();
                    showError((data && (data.error_detail || data.error)) || ('HTTP ' + xhr.status));
                    return;
                }
                if (data.async && data.job_id) {
                    /* Hand off to the widget for live progress. */
                    startTracking({ jobId: data.job_id, filename: file.name, pollUrl: data.poll_url });
                } else {
                    /* Sync fallback (no FPM / EasyWorship-in-zip) — show the summary inline. */
                    dismiss();
                    renderSummary(data);
                }
            };
            xhr.onerror = () => {
                goBtn.disabled = false;
                statusEl.textContent = '';
                dismiss();
                showError('network error');
            };
            xhr.send(fd);
        }

        /* Single file → sync import_file. */
        function importSingle(file) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('format', fmtEl.value);
            fd.append('dedupeMode', dedupeEl.checked ? 'skip-title' : 'off');
            fd.append('dryRun', dryRunEl.checked ? '1' : '0');
            /* #1855: extensionless — see the XHR above; a literal .php URL
               here would 301 and silently strip this POST's body. */
            fetch('/manage/editor/api2?action=import_file', {
                method: 'POST',
                credentials: 'same-origin',
                /* X-Requested-With is what api2.php actually gates on (#1677);
                   X-CSRF-Token alone is not accepted. See the XHR above. */
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrf(),
                },
                body: fd,
            }).then((res) => res.json().catch(() => ({ ok: false, error: 'HTTP ' + res.status })))
              .then((data) => {
                  goBtn.disabled = false;
                  statusEl.textContent = '';
                  if (!data || data.ok !== true) { showError((data && (data.error_detail || data.error)) || 'unknown error'); return; }
                  renderSummary(data);
              }).catch((e) => {
                  goBtn.disabled = false;
                  statusEl.textContent = '';
                  showError(e && e.message ? e.message : String(e));
              });
        }

        goBtn.addEventListener('click', () => {
            const file = fileEl.files && fileEl.files[0];
            if (!file) { statusEl.textContent = 'Choose a file first.'; return; }
            const isZip = /\.zip$/i.test(file.name);
            /* #1911 — ZIP dry-run now round-trips through the async job row
               (tblBulkImportJobs.DryRun), so the client-side refusal that
               used to live here (#1674) is gone: import_zip carries the
               flag exactly like import_file already does. An un-migrated
               deployment still answers with its own 422, surfaced by
               importZip()'s xhr.onload → showError() below — this page
               doesn't pre-guess that outcome. */
            goBtn.disabled = true;
            statusEl.textContent = 'Importing…';
            resultEl.innerHTML = '';
            if (isZip) { importZip(file); }
            else { importSingle(file); }
        });
    </script>
</body>
</html>
