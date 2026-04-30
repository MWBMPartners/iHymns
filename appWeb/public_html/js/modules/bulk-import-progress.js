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

const STORAGE_KEY     = 'ihymns:bulk-import-active-job';
const POLL_INTERVAL_MS = 1500;
const POLL_BACKOFF_MS  = 5000; // after an error, slow down rather than spam

/* In-module state. We never expose these directly — bootstrap
   path mounts the widget and stashes the polling timer here. */
let widgetEl       = null;
let pollTimer      = null;
let activeJob      = null; // { jobId, filename, pollUrl }
let isPollingNow   = false;

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

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
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
       fallback otherwise). */
    widgetEl.style.cssText = `
        position: fixed;
        bottom: 1rem;
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
       the first poll is in flight. */
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
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 d-none"
                    data-bulk-action="dismiss"
                    aria-label="Dismiss"
                    title="Dismiss"
                    style="color: var(--text-secondary,#9ca3af);">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="ihymns-bulk-import-summary text-muted" style="font-size:0.75rem;">Connecting…</div>
        <div class="progress mt-2" style="height: 0.5rem;">
            <div class="progress-bar" role="progressbar"
                 style="width: 0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
        </div>
    `;

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
            stopAndRemove();
            clearActiveJob();
        }
    });

    document.body.appendChild(widgetEl);
    return widgetEl;
}

function render(job) {
    if (!widgetEl) return;
    const summary  = widgetEl.querySelector('.ihymns-bulk-import-summary');
    const bar      = widgetEl.querySelector('.progress-bar');
    const dismiss  = widgetEl.querySelector('[data-bulk-action="dismiss"]');
    const titleEl  = widgetEl.querySelector('strong');

    if (!job) {
        if (summary) summary.textContent = 'No active import.';
        return;
    }

    const filename = activeJob?.filename || job.filename || 'import.zip';
    if (titleEl) titleEl.textContent = filename;

    const status   = job.status || 'queued';
    const total    = Number(job.total_entries || 0);
    const done     = Number(job.processed_entries || 0);
    const percent  = Number(job.percent || 0);
    const created  = Number(job.songs_created || 0);
    const skipped  = Number(job.songs_skipped_existing || 0);
    const failed   = Number(job.songs_failed || 0);

    if (bar) {
        bar.style.width = Math.min(100, Math.max(0, percent)) + '%';
        bar.setAttribute('aria-valuenow', String(percent));
        if (status === 'completed') {
            bar.style.background = '#16a34a';
        } else if (status === 'failed') {
            bar.style.background = '#dc2626';
        }
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
            summary.innerHTML =
                `<i class="fa-solid fa-check-circle" style="color:#16a34a;"></i> ` +
                `Imported <strong>${created.toLocaleString()}</strong> new ` +
                `(${skipped.toLocaleString()} skipped` +
                (failed > 0 ? `, ${failed.toLocaleString()} failed` : '') +
                `).`;
        } else if (status === 'failed') {
            const errs = Array.isArray(job.errors) ? job.errors : [];
            const first = errs.length ? escapeHtml(errs[0].error || 'see logs') : 'see server logs';
            summary.innerHTML =
                `<i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> ` +
                `Import failed: ${first}`;
        }
    }

    /* Show the dismiss button only when the job is in a final
       state — otherwise the user might dismiss a still-running
       import and lose the tracking handle. */
    if (dismiss) {
        if (status === 'completed' || status === 'failed') {
            dismiss.classList.remove('d-none');
        } else {
            dismiss.classList.add('d-none');
        }
    }
}

/* ------------------------------------------------------------------
 * Polling
 * ------------------------------------------------------------------ */

function pollOnce() {
    if (!activeJob || isPollingNow) return;
    isPollingNow = true;
    const url = activeJob.pollUrl
        || ('/manage/editor/api?action=bulk_import_status&job_id=' + activeJob.jobId);
    fetch(url, { credentials: 'same-origin' })
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
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    if (widgetEl && widgetEl.parentNode) widgetEl.parentNode.removeChild(widgetEl);
    widgetEl  = null;
    activeJob = null;
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
 * Persists the handle to localStorage and starts the widget.
 */
export function startTracking({ jobId, filename, pollUrl }) {
    activeJob = { jobId, filename, pollUrl };
    writeActiveJob(activeJob);
    /* Re-mount even if a stale widget existed (e.g. from a
       previous import that's already complete). */
    stopAndRemove();
    activeJob = { jobId, filename, pollUrl };
    ensureWidget();
    render({ status: 'queued', filename });
    pollOnce();
}

/* Convenience: surface a global so the legacy classic-script
   editor.js can call startTracking without an ES-module import.
   Set on first boot — harmless if it overwrites a prior reference
   to itself. */
if (typeof window !== 'undefined') {
    window.bulkImportProgress = {
        boot: bootBulkImportProgressWidget,
        startTracking,
    };
}
