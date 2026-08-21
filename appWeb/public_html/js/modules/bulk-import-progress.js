/**
 * Persistent bulk-import progress widget (#676)
 *
 * Floats in the bottom-right corner of any iHymns page (admin OR
 * the public PWA). Survives navigation by reading the active
 * `job_id` from localStorage — so a curator can kick off an import
 * on /manage/editor, switch to /manage/songbooks (or even the main
 * app's home page), and still see the import progress + final
 * summary land in the same widget.
 *
 * Boot:
 *   import { bootBulkImportProgressWidget } from '/js/modules/bulk-import-progress.js';
 *   bootBulkImportProgressWidget();
 *
 * On import start (in editor.js), call:
 *   bulkImportProgress.startTracking({ jobId, filename, pollUrl });
 *   …which sets localStorage and immediately renders the widget.
 *
 * Widget responsibilities:
 *   1. Read the active job from localStorage on boot. Return early
 *      if none.
 *   2. Render a fixed-position widget into <body>. Idempotent.
 *   3. Poll the status endpoint every POLL_INTERVAL_MS ms.
 *   4. Update the progress bar + counters as new state arrives.
 *   5. On completion (status = 'completed' | 'failed'):
 *        - Stop polling.
 *        - Show a green / red final banner with the summary.
 *        - Reload the editor's catalogue if we're on /manage/editor.
 *        - Wait for the user to dismiss before clearing localStorage
 *          (so a quick page-navigation doesn't lose the result).
 *
 * The module is idempotent: bootBulkImportProgressWidget() is safe
 * to call multiple times (e.g. once per SPA navigation) — it
 * skips re-rendering if a widget is already mounted, and reuses
 * any in-flight polling timer.
 */

import { apiFetch } from '../utils/api-client.js';

const STORAGE_KEY     = 'ihymns:bulk-import-active-job';
const POLL_INTERVAL_MS = 1500;
const POLL_BACKOFF_MS  = 5000; // after an error, slow down rather than spam

/* #907 — auto-dismiss windows for completion / failure states. The
   curator gets enough time to read the result; hover-to-pause
   suspends the timer for as long as the cursor is over the widget,
   so a curator copying numbers from the summary doesn't have it
   yanked away mid-read. */
const AUTO_DISMISS_SUCCESS_MS = 15000; // 15s on a clean import
const AUTO_DISMISS_FAILURE_MS = 45000; // 45s when something failed (more to read)

/* In-module state. We never expose these directly — bootstrap
   path mounts the widget and stashes the polling timer here. */
let widgetEl         = null;
let pollTimer        = null;
let activeJob        = null; // { jobId, filename, pollUrl }
let isPollingNow     = false;
let autoDismissTimer = null;
let autoDismissPaused = false;
/* #907 — upload-phase tracking. The widget mounts BEFORE the upload
   starts so the curator sees byte-level feedback during the upload.
   `uploadState` is null once the job_id arrives and polling takes
   over. */
let uploadState      = null; // { filename, sizeBytes, loaded, total }

/* ------------------------------------------------------------------
 * localStorage helpers — best-effort; private-browsing failures
 * downgrade to "no persistence" rather than throwing.
 * ------------------------------------------------------------------ */
function readActiveJob() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed.jobId !== 'number') return null;
        return parsed;
    } catch (_e) {
        return null;
    }
}
function writeActiveJob(job) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(job)); } catch (_e) {}
}
function clearActiveJob() {
    try { localStorage.removeItem(STORAGE_KEY); } catch (_e) {}
}

/* ------------------------------------------------------------------
 * DOM construction
 * ------------------------------------------------------------------ */

/**
 * Escape text for HTML. (security sweep)
 *
 * ELI5: turns characters that would be read as markup into ones that just
 * show up as themselves.
 *
 * Detail: the `'` mapping was missing. Every one of this codebase's other nine
 * escapers has it, including the canonical js/utils/html.js::escapeHtml. It was
 * not exploitable here — all three call sites below emit into TEXT content,
 * where a bare `'` is inert — but a shared helper that is only safe because of
 * where it happens to be called today hands the gap to its next caller in
 * silence. The character matters the moment an escaped value lands in a
 * SINGLE-quoted attribute (`title='…'`), which nothing stops a later edit doing.
 *
 * Guarded by tests/test-html-escapers.js, which derives the helper list from the
 * tree so a new copy is covered on the day it is written.
 *
 * @param {*} s Any value; null/undefined become ''.
 * @returns {string} HTML-safe text.
 * @see appWeb/public_html/js/utils/html.js  the canonical implementation
 */
function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function ensureWidget() {
    if (widgetEl && document.body.contains(widgetEl)) return widgetEl;

    widgetEl = document.createElement('div');
    widgetEl.className = 'ihymns-bulk-import-widget';
    widgetEl.setAttribute('role', 'status');
    widgetEl.setAttribute('aria-live', 'polite');
    widgetEl.setAttribute('aria-label', 'Bulk import progress');

    /* Inline styles so the widget works on every iHymns surface
       without a per-page CSS dependency. Conservative palette
       (theme-aware via CSS variables when present, opaque dark
       fallback otherwise).
       #907 — TOP-right (was bottom-right). The bulk-import widget
       was getting overlapped by Bootstrap's app-toast queue, which
       is also bottom-right by default. Moving to top-right gives
       each surface its own corner with no z-index gymnastics. */
    widgetEl.style.cssText = `
        position: fixed;
        top: 1rem;
        right: 1rem;
        z-index: 9999;
        min-width: 18rem;
        max-width: 22rem;
        padding: 0.75rem 0.9rem;
        border-radius: 0.5rem;
        background: var(--surface-elevated, #1f2937);
        color: var(--text-primary, #f3f4f6);
        border: 1px solid var(--card-border, #374151);
        box-shadow: 0 6px 16px rgba(0,0,0,0.4);
        font-size: 0.875rem;
        line-height: 1.4;
    `.replace(/\s+/g, ' ').trim();

    /* Initial markup — gets replaced wholesale by render() each
       poll. The skeleton just gives the user something to see while
       the first poll is in flight.
       #907 — dismiss button is ALWAYS visible (was only shown after
       completion). Mid-run dismiss closes the visible widget but
       leaves the import running on the server; the curator can
       check job state later via /manage/activity-log. */
    widgetEl.innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <strong style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Importing…</strong>
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0"
                    data-bulk-action="minimise"
                    aria-label="Minimise progress widget"
                    title="Minimise"
                    style="color: var(--text-secondary,#9ca3af);">
                <i class="fa-solid fa-window-minimize"></i>
            </button>
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0"
                    data-bulk-action="dismiss"
                    aria-label="Dismiss"
                    title="Dismiss"
                    style="color: var(--text-secondary,#9ca3af);">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="ihymns-bulk-import-dryrun-banner" data-dryrun-banner
             style="font-size:0.7rem; font-weight:600; color:#38bdf8; margin-bottom:0.15rem; display:none;">
            <i class="fa-solid fa-flask"></i> DRY RUN — nothing will be written
        </div>
        <div class="ihymns-bulk-import-phase text-muted small" data-phase
             style="font-size:0.7rem; margin-bottom:0.15rem; display:none;"></div>
        <div class="ihymns-bulk-import-summary text-muted" style="font-size:0.75rem;">Connecting…</div>
        <div class="progress mt-2" style="height: 0.5rem;">
            <div class="progress-bar" role="progressbar"
                 style="width: 0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
        </div>
        <!-- Per-songbook breakdown + download skipped CSV button.
             Rendered only after status == 'completed' and only when
             the deploy carries the #906 PerSongbookJson column /
             the SkippedSongIdsJson column. Pre-migration deploys
             skip this section entirely so no UX regression. -->
        <div class="ihymns-bulk-import-detail mt-2" data-detail style="display:none;">
            <div data-perbook-table style="font-size:0.7rem; max-height:9rem; overflow:auto;
                 border:1px solid var(--card-border,#374151); border-radius:0.25rem;
                 padding:0.25rem 0.4rem; margin-bottom:0.35rem;"></div>
            <a class="btn btn-sm btn-outline-secondary w-100"
               data-skipped-csv-link
               style="font-size:0.7rem; display:none;"
               download>
                <i class="fa-solid fa-download me-1"></i>
                Download skipped SongIds (CSV)
            </a>
        </div>
    `;

    /* #907 — hover pauses the auto-dismiss timer; mouse-leave resumes
       it from where it was. The user can read the result without
       it disappearing mid-glance. */
    widgetEl.addEventListener('mouseenter', () => {
        autoDismissPaused = true;
        if (autoDismissTimer) { clearTimeout(autoDismissTimer); autoDismissTimer = null; }
    });
    widgetEl.addEventListener('mouseleave', () => {
        autoDismissPaused = false;
        /* If we were already in a final state, restart the dismiss
           timer at its full duration on mouse-leave. Tiny extra
           dismiss budget on hover-pause is acceptable for the UX win. */
        const status = activeJob?._lastStatus;
        if (status === 'completed') scheduleAutoDismiss(AUTO_DISMISS_SUCCESS_MS);
        if (status === 'failed')    scheduleAutoDismiss(AUTO_DISMISS_FAILURE_MS);
    });

    /* Click handlers via delegation so we don't have to re-bind
       after each render. */
    widgetEl.addEventListener('click', (e) => {
        const action = e.target.closest('[data-bulk-action]')?.dataset?.bulkAction;
        if (action === 'minimise') {
            widgetEl.classList.toggle('ihymns-bulk-import-widget--mini');
            const isMini = widgetEl.classList.contains('ihymns-bulk-import-widget--mini');
            /* Compact mode: shrink to a small floating chip. */
            widgetEl.style.minWidth = isMini ? 'unset' : '18rem';
            widgetEl.style.maxWidth = isMini ? 'unset' : '22rem';
            widgetEl.style.padding  = isMini ? '0.4rem 0.6rem' : '0.75rem 0.9rem';
        } else if (action === 'dismiss') {
            /* #907 — dismiss is now always available. Mid-run
               dismiss only closes the widget; the import keeps
               running on the server. localStorage is cleared so the
               next page-load doesn't auto-resume tracking — the
               curator can verify completion via /manage/activity-log
               or via the next page-load's own widget if they navigate
               away and come back. */
            stopAndRemove();
            clearActiveJob();
        }
    });

    document.body.appendChild(widgetEl);
    return widgetEl;
}

/* #907 — helper: schedule an auto-dismiss timer. Idempotent — clears
   any existing timer before starting a new one. Suspended while
   `autoDismissPaused` is true (cursor over widget). */
function scheduleAutoDismiss(ms) {
    if (autoDismissTimer) { clearTimeout(autoDismissTimer); autoDismissTimer = null; }
    if (autoDismissPaused) return; // hover suspends; mouseleave will reschedule
    autoDismissTimer = setTimeout(() => {
        autoDismissTimer = null;
        stopAndRemove();
        clearActiveJob();
    }, ms);
}

/* #907 — translate worker phase labels into human-readable status text. */
const PHASE_LABEL_TEXT = {
    'walking-zip':         'Walking ZIP archive…',
    'parsing-songs':       'Parsing songs…',
    'flushing-songbooks':  'Finalising…',
    'completed':           '',                        // hide on completion
};

function render(job) {
    if (!widgetEl) return;
    const summary   = widgetEl.querySelector('.ihymns-bulk-import-summary');
    const phaseEl   = widgetEl.querySelector('[data-phase]');
    const bar       = widgetEl.querySelector('.progress-bar');
    const titleEl   = widgetEl.querySelector('strong');
    const dryRunEl  = widgetEl.querySelector('[data-dryrun-banner]'); // #1911

    if (!job) {
        if (summary) summary.textContent = 'No active import.';
        return;
    }

    const filename = activeJob?.filename || job.filename || 'import.zip';
    if (titleEl) titleEl.textContent = filename;

    const status   = job.status || 'queued';
    const phase    = job.phase_label || null; // #907
    const total    = Number(job.total_entries || 0);
    const done     = Number(job.processed_entries || 0);
    const percent  = Number(job.percent || 0);
    const created  = Number(job.songs_created || 0);
    const skipped  = Number(job.songs_skipped_existing || 0);
    const failed   = Number(job.songs_failed || 0);
    /* #1911 — branches ONLY on the server-echoed `dry_run` key (rule #35),
       exactly like import2.php's renderSummary() does for the sync paths;
       never on the checkbox state the client happened to send. */
    const isDryRun = job.dry_run === true;
    if (dryRunEl) dryRunEl.style.display = isDryRun ? 'block' : 'none';

    /* Stash status on activeJob so the hover handlers can decide
       whether to reschedule auto-dismiss on mouse-leave. */
    if (activeJob) activeJob._lastStatus = status;

    if (bar) {
        bar.style.width = Math.min(100, Math.max(0, percent)) + '%';
        bar.setAttribute('aria-valuenow', String(percent));
        if (status === 'completed') {
            bar.style.background = '#16a34a';
        } else if (status === 'failed') {
            bar.style.background = '#dc2626';
        }
    }

    /* #907 — phase label above the summary. Hidden on pre-migration
       deploys (phase_label = null) and on completion (no useful
       phase to surface). */
    if (phaseEl) {
        const text = phase && phase !== 'completed' ? PHASE_LABEL_TEXT[phase] || phase : '';
        phaseEl.textContent = text;
        phaseEl.style.display = text ? 'block' : 'none';
    }

    if (summary) {
        if (status === 'queued') {
            summary.textContent = 'Queued — waiting for worker.';
        } else if (status === 'running') {
            summary.textContent =
                `${done.toLocaleString()} / ${total.toLocaleString()} entries · ` +
                `${created.toLocaleString()} new, ${skipped.toLocaleString()} skipped` +
                (failed > 0 ? `, ${failed.toLocaleString()} failed` : '');
        } else if (status === 'completed') {
            /* Spell out the skip reason inline so the curator never
               has to guess what "X skipped" means. Today every skip
               is "already in the database" (INSERT-only contract).
               #1911 — under dry-run the same counters describe what
               WOULD happen (the seam in _bulkImport_saveSong() /
               _bulkImport_upsertSongbook() already reports them that
               way), so only the WORDING changes here, mirroring
               import2.php's renderSummary(). */
            const skippedClause = skipped > 0
                ? ` (<strong>${skipped.toLocaleString()}</strong> already in the database`
                + (isDryRun ? ' (would skip)' : '')
                + (failed > 0 ? `, ${failed.toLocaleString()} failed` : '')
                + ')'
                : (failed > 0 ? ` (${failed.toLocaleString()} failed)` : '');
            summary.innerHTML = isDryRun
                ? `<i class="fa-solid fa-flask" style="color:#38bdf8;"></i> ` +
                  `Would import <strong>${created.toLocaleString()}</strong> new` +
                  skippedClause + '.'
                : `<i class="fa-solid fa-check-circle" style="color:#16a34a;"></i> ` +
                  `Imported <strong>${created.toLocaleString()}</strong> new` +
                  skippedClause + '.';
        } else if (status === 'failed') {
            const errs = Array.isArray(job.errors) ? job.errors : [];
            const first = errs.length ? escapeHtml(errs[0].error || 'see logs') : 'see server logs';
            summary.innerHTML =
                `<i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> ` +
                `Import failed: ${first}`;
        }
    }

    /* Per-songbook breakdown + skipped-CSV download. Only on completion;
       only when the backend supplied the data (pre-migration deploys
       return null for per_songbook and empty string for skipped_csv_url
       so this block remains hidden). */
    const detail   = widgetEl.querySelector('[data-detail]');
    const perBook  = widgetEl.querySelector('[data-perbook-table]');
    const csvLink  = widgetEl.querySelector('[data-skipped-csv-link]');
    if (detail && perBook && csvLink) {
        const perBookData = Array.isArray(job.per_songbook) ? job.per_songbook : [];
        const csvUrl      = typeof job.skipped_csv_url === 'string' ? job.skipped_csv_url : '';
        if (status === 'completed' && (perBookData.length > 0 || csvUrl !== '')) {
            if (perBookData.length > 0) {
                /* One row per songbook: "<abbr> (<name>) — N new / M already in DB / F failed".
                   Books with zero activity are dropped to keep the panel readable. */
                const rows = perBookData
                    .filter(b => (b.created|0) + (b.skipped|0) + (b.failed|0) > 0)
                    .map(b => {
                        const abbr = escapeHtml(b.abbr || '');
                        const name = b.name ? ` <span class="text-muted">(${escapeHtml(b.name)})</span>` : '';
                        const c = (b.created|0).toLocaleString();
                        const s = (b.skipped|0).toLocaleString();
                        const f = (b.failed|0).toLocaleString();
                        const failPart = (b.failed|0) > 0 ? ` · <span style="color:#dc2626;">${f} failed</span>` : '';
                        return `<div><code>${abbr}</code>${name} — `
                             + `<strong>${c}</strong> new · `
                             + `${s} already in DB${failPart}</div>`;
                    });
                perBook.innerHTML = rows.length ? rows.join('') :
                    '<div class="text-muted">(no per-songbook detail recorded)</div>';
            } else {
                perBook.innerHTML = '<div class="text-muted">(no per-songbook detail recorded)</div>';
            }
            if (csvUrl !== '') {
                csvLink.href = csvUrl;
                csvLink.style.display = 'inline-block';
            } else {
                csvLink.style.display = 'none';
            }
            detail.style.display = 'block';
        } else {
            detail.style.display = 'none';
        }
    }

    /* #907 — auto-dismiss on completion / failure. Pre-#907 the
       widget stuck around indefinitely until the user found the ×
       button (which was only visible after completion anyway).
       Auto-dismiss runs after a generous delay and pauses on hover. */
    if (status === 'completed' && !autoDismissTimer && !autoDismissPaused) {
        scheduleAutoDismiss(failed > 0 ? AUTO_DISMISS_FAILURE_MS : AUTO_DISMISS_SUCCESS_MS);
    } else if (status === 'failed' && !autoDismissTimer && !autoDismissPaused) {
        scheduleAutoDismiss(AUTO_DISMISS_FAILURE_MS);
    }
}

/* #907 — render the upload-phase state. Uses the same widget
   skeleton as the polling render() but reads from `uploadState`
   (loaded / total bytes) rather than the server's job row. */
function renderUploadPhase() {
    if (!widgetEl || !uploadState) return;
    const titleEl  = widgetEl.querySelector('strong');
    const phaseEl  = widgetEl.querySelector('[data-phase]');
    const summary  = widgetEl.querySelector('.ihymns-bulk-import-summary');
    const bar      = widgetEl.querySelector('.progress-bar');

    const filename = uploadState.filename || 'import.zip';
    const total    = Number(uploadState.total || uploadState.sizeBytes || 0);
    const loaded   = Number(uploadState.loaded || 0);
    const percent  = total > 0 ? Math.round((loaded / total) * 100) : 0;

    if (titleEl) titleEl.textContent = filename;
    if (phaseEl) {
        phaseEl.textContent = 'Uploading…';
        phaseEl.style.display = 'block';
    }
    if (bar) {
        bar.style.width = Math.min(100, Math.max(0, percent)) + '%';
        bar.setAttribute('aria-valuenow', String(percent));
        bar.style.background = ''; // default theme accent
    }
    if (summary) {
        const fmt = (b) => {
            if (!b) return '0 B';
            if (b < 1024) return b + ' B';
            if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
            return (b / 1024 / 1024).toFixed(1) + ' MB';
        };
        summary.textContent = total > 0
            ? `${fmt(loaded)} / ${fmt(total)} (${percent}%)`
            : `${fmt(loaded)} uploaded…`;
    }
}

/* ------------------------------------------------------------------
 * Polling
 * ------------------------------------------------------------------ */

function pollOnce() {
    if (!activeJob || isPollingNow) return;
    isPollingNow = true;
    /* Fallback URL for a stale localStorage job that never carried its own
       pollUrl (an old import started before pollUrl existed, or a job
       object that lost it across a schema change). ELI5: if we don't
       already know where to ask, ask the v2 editor API instead of the
       retired v1 one.

       WHY api2 / import_zip_status, NOT v1's bulk_import_status (#1629):
       this module is loaded globally by every admin page
       (manage-includes/admin-footer.php), so once v1's api.php is retired
       (the v2 editor cutover, #1601) a stale job in ANY admin's
       localStorage would poll a dead endpoint forever without this
       repoint — silently, since the failure just falls into the
       transient-error backoff branch below rather than surfacing an
       error. Confirmed a pure URL swap, not a shape change: `out` below
       is built as `{ ok: r.ok, data: <parsed body> }`, so `out.data.job`
       already reads api2's top-level `job` either way, and api2's 404 +
       migration_needed response lines up with the branch immediately
       below that already handles it. Every field this module reads off
       `out.data.job` (status/percent/phase_label/per_songbook/
       skipped_csv_url/filename/errors/total_entries/processed_entries/
       songs_created/songs_failed/songs_skipped_existing) exists in both
       handlers' output. */
    // #1855: extensionless — matches api-client.js's ENDPOINT; this GET
    // would survive the .htaccess 301, but skips the wasted redirect hop on
    // every poll tick.
    const url = activeJob.pollUrl
        || ('/manage/editor/api2?action=import_zip_status&job_id=' + activeJob.jobId);
    apiFetch(url, { credentials: 'same-origin' })
        .then(r => r.json().then(data => ({ ok: r.ok, data })))
        .then(out => {
            isPollingNow = false;
            if (!out.ok || !out.data || !out.data.job) {
                /* 404 with migration_needed: the table doesn't exist
                   on this deployment. Stop polling and let the user
                   know — most likely a stale localStorage entry from
                   before the migration ran. */
                if (out.data && out.data.migration_needed) {
                    stopAndRemove();
                    clearActiveJob();
                    return;
                }
                /* Transient error → back off + retry. */
                pollTimer = setTimeout(pollOnce, POLL_BACKOFF_MS);
                return;
            }
            render(out.data.job);

            const status = out.data.job.status;
            if (status === 'completed' || status === 'failed') {
                /* Final state — stop polling. The widget stays
                   visible until the user dismisses (or this page
                   gets unloaded). */
                if (status === 'completed') {
                    /* Refresh the editor's catalogue if we're on
                       /manage/editor so the new rows appear. The
                       editor exposes the loader as a global. */
                    try {
                        if (typeof window.loadSongsFromURL === 'function'
                            && Array.isArray(window.SONGS_URL_CANDIDATES)) {
                            window.loadSongsFromURL(window.SONGS_URL_CANDIDATES[0]);
                        }
                    } catch (_e) {}
                }
                return;
            }

            /* Schedule the next poll. */
            pollTimer = setTimeout(pollOnce, POLL_INTERVAL_MS);
        })
        .catch(() => {
            isPollingNow = false;
            pollTimer = setTimeout(pollOnce, POLL_BACKOFF_MS);
        });
}

function stopAndRemove() {
    if (pollTimer)        { clearTimeout(pollTimer);        pollTimer = null; }
    if (autoDismissTimer) { clearTimeout(autoDismissTimer); autoDismissTimer = null; }
    if (widgetEl && widgetEl.parentNode) widgetEl.parentNode.removeChild(widgetEl);
    widgetEl     = null;
    activeJob    = null;
    uploadState  = null;
    autoDismissPaused = false;
}

/* ------------------------------------------------------------------
 * Public API
 * ------------------------------------------------------------------ */

/**
 * Boot the widget. Idempotent — safe to call from every SPA
 * navigation. Returns immediately if no job is active in
 * localStorage.
 */
export function bootBulkImportProgressWidget() {
    /* Already running on this page? Don't double-mount. */
    if (widgetEl && document.body.contains(widgetEl) && activeJob) return;

    const job = readActiveJob();
    if (!job) return;

    activeJob = job;
    ensureWidget();
    /* First paint: show the queued state. The first poll fires
       immediately rather than waiting POLL_INTERVAL_MS so the
       widget feels responsive. */
    render({ status: 'queued', filename: job.filename });
    pollOnce();
}

/**
 * Called by editor.js right after the upload returns its job_id.
 * Persists the handle to localStorage and starts polling — fires
 * the first poll immediately rather than waiting POLL_INTERVAL_MS so
 * the curator sees real worker state within ~100ms of upload return
 * (#907).
 */
export function startTracking({ jobId, filename, pollUrl }) {
    /* Don't tear down the widget — if it's already mounted from
       startUploadTracking() (#907), keep the same DOM node so the
       transition from upload → polling is visually seamless. */
    activeJob   = { jobId, filename, pollUrl };
    uploadState = null;        // upload phase done; polling takes over
    writeActiveJob(activeJob);
    ensureWidget();
    render({ status: 'queued', filename });
    /* Immediate first poll so the worker's current PhaseLabel /
       progress lands within ~100ms of the upload return. */
    pollOnce();
}

/**
 * #907 — called by editor.js BEFORE the upload starts. Mounts the
 * widget in upload-tracking mode so the curator sees byte-level
 * progress during the upload phase (which can be 60+s on a 38 MB
 * ZIP under a constrained connection). Pre-#907 the widget mounted
 * only after the server returned the job_id, leaving the upload
 * phase entirely silent.
 */
export function startUploadTracking({ filename, sizeBytes }) {
    uploadState = {
        filename:  filename || 'import.zip',
        sizeBytes: Number(sizeBytes || 0),
        loaded:    0,
        total:     Number(sizeBytes || 0),
    };
    /* Don't restore activeJob from localStorage — this is a fresh
       upload, not a resumption. */
    activeJob = null;
    ensureWidget();
    renderUploadPhase();
}

/**
 * #907 — called by editor.js's `xhr.upload.onprogress` handler
 * during the upload phase. Updates the loaded/total counters and
 * re-renders. No-op if the widget isn't in upload mode (e.g. the
 * upload completed and we transitioned to polling).
 */
export function updateUploadProgress({ loaded, total }) {
    if (!uploadState) return;
    uploadState.loaded = Number(loaded || 0);
    if (total) uploadState.total = Number(total);
    renderUploadPhase();
}

/**
 * #907 — programmatic dismiss for editor.js's error path (server
 * rejected the upload, no job to track). Tears down the widget +
 * any auto-dismiss timer cleanly.
 */
export function dismiss() {
    stopAndRemove();
    clearActiveJob();
}

/* Convenience: surface a global so the legacy classic-script
   editor.js can call startTracking without an ES-module import.
   Set on first boot — harmless if it overwrites a prior reference
   to itself. */
if (typeof window !== 'undefined') {
    window.bulkImportProgress = {
        boot: bootBulkImportProgressWidget,
        startTracking,
        startUploadTracking,
        updateUploadProgress,
        dismiss,
    };
}
