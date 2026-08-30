/*
 * iHymns — Setup Bulk Migration Runner (#869)
 *
 * Replaces the legacy "Apply All Pending Migrations" full-page
 * redirect with a per-migration AJAX runner that stays on the
 * dashboard. Each migration is its own short HTTP request so
 * the bulk run never sits in a single long PHP request that
 * could hit a server-level timeout (PHP-FPM
 * `request_terminate_timeout`, nginx `proxy_read_timeout`,
 * hosting CDN limits — all of which `set_time_limit(0)` cannot
 * override). #862 / #863 / #868 papered over the timeout
 * symptom; this module removes the timeout exposure entirely.
 *
 * UX:
 *   - User clicks "Apply All Pending Migrations".
 *   - JS intercepts the click; opens an inline progress panel
 *     above the migration cards.
 *   - For each pending migration:
 *       1. Mark its row as "running" (spinner).
 *       2. fetch(`?action=<name>&format=text`).
 *       3. Server returns a plain-text envelope:
 *            STATUS: ok
 *            ACTION: <name>
 *            SCRIPT: migrate-<name>.php
 *            ELAPSED_MS: <ms>
 *            ---
 *            <captured migration output>
 *       4. Append the output to the live log + flip the row
 *          icon to ✓ (or ✗ on error).
 *   - On any error, stop the run; surface a banner pointing the
 *     curator at the failed migration's output.
 *   - On success, reload the dashboard so pending-probe state
 *     refreshes (some migrations no-op when re-run, some flip
 *     "pending" → "applied" in the cards-grid partition).
 *
 * Fallback: if the user has JS disabled, the Apply All <a>'s
 * native href ("?action=apply-all-migrations") still works as
 * before, with the chrome / footer / badge fixes from
 * #862 / #863 / #868 / #870.
 */

import { apiFetch } from '../utils/api-client.js';

// #1855: extensionless — a literal .php URL is 301'd by .htaccess; both
// uses below are GET (a page navigation and a read-only migration-run poll)
// so they would have survived, but the redirect hop was pure waste.
const ENDPOINT = '/manage/setup-database';

/**
 * Boot the bulk runner. Idempotent — re-calling on the same DOM is
 * safe (the wrapper carries a data flag).
 *
 * @param {ParentNode} [root=document]
 */
export function bootSetupBulkRunner(root) {
    const scope  = root || document;
    const button = scope.querySelector('[data-bulk-runner-trigger]');
    if (!button || button.dataset.bulkRunnerBooted === '1') return;
    button.dataset.bulkRunnerBooted = '1';

    button.addEventListener('click', (e) => {
        /* Non-JS path falls through to the <a href="?action=apply-all-migrations">
           navigation. With JS we own the click. */
        e.preventDefault();
        const csv = button.dataset.pendingMigrations || '';
        const pending = csv.split(',').map(s => s.trim()).filter(Boolean);
        if (pending.length === 0) {
            window.alert('No pending migrations — every migration\'s probe reports its work as already applied.');
            return;
        }
        if (!window.confirm(
            `Run ${pending.length} pending migration${pending.length === 1 ? '' : 's'} `
            + 'in dependency order?\n\n'
            + 'Each runs as its own short request so server-level timeouts can\'t '
            + 'truncate the bulk run. Safe to re-run — applied migrations no-op.'
        )) return;
        runSequence(scope, button, pending).catch(err => {
            console.error('[setup-bulk-runner] run failed:', err);
        });
    });
}

/**
 * Drive the sequence: render the progress panel, then loop.
 */
async function runSequence(scope, triggerBtn, pending) {
    triggerBtn.classList.add('disabled');
    triggerBtn.setAttribute('aria-disabled', 'true');

    const panel = ensurePanel(scope);
    panel.dataset.state = 'running';
    panel.querySelector('[data-bulk-status]').textContent = `Running 0 / ${pending.length}…`;
    const list = panel.querySelector('[data-bulk-list]');
    list.innerHTML = '';

    /* Pre-render rows so the curator sees the full plan upfront. */
    const rows = new Map();
    pending.forEach(action => {
        const row = document.createElement('div');
        row.className = 'd-flex align-items-start gap-2 py-1 border-bottom border-secondary';
        row.dataset.bulkRow = action;
        row.innerHTML = `
            <span class="bulk-row-icon flex-shrink-0" aria-hidden="true"
                  style="display:inline-block;width:1.25rem;text-align:center;">○</span>
            <code class="flex-shrink-0 text-info">${escapeHtml(action)}</code>
            <span class="bulk-row-meta text-muted small ms-auto"></span>
        `;
        list.appendChild(row);
        rows.set(action, row);
    });

    let completed = 0;
    let failed = false;
    for (const action of pending) {
        const row = rows.get(action);
        const icon = row.querySelector('.bulk-row-icon');
        const meta = row.querySelector('.bulk-row-meta');
        icon.textContent = '⟳';
        icon.classList.add('text-warning');
        meta.textContent = 'Running…';
        panel.querySelector('[data-bulk-status]').textContent =
            `Running ${completed + 1} / ${pending.length}: ${action}…`;

        try {
            const result = await runOne(action);
            /* a11y audit A14 (2026-08-30) — the ○/⟳/✓/✗ icon above is
               aria-hidden, so this meta text is the ONLY textual state a
               screen-reader user gets — and it used to go BLANK on success
               whenever elapsedMs was 0 (a near-instant migration), leaving a
               successful row indistinguishable from an untouched one except
               by colour. Always say "Done", timing as an optional extra. */
            const ms = result.elapsedMs > 0 ? `Done · ${result.elapsedMs} ms` : 'Done';
            if (result.ok) {
                icon.textContent = '✓';
                icon.classList.remove('text-warning');
                icon.classList.add('text-success');
                meta.textContent = ms;
                appendLog(panel, action, result.output, true);
                completed++;
            } else {
                icon.textContent = '✗';
                icon.classList.remove('text-warning');
                icon.classList.add('text-danger');
                meta.textContent = 'Failed — ' + (result.error || 'unknown error');
                appendLog(panel, action, result.output, false, result.error);
                failed = true;
                break;
            }
        } catch (err) {
            icon.textContent = '✗';
            icon.classList.remove('text-warning');
            icon.classList.add('text-danger');
            meta.textContent = 'Failed — ' + (err.message || 'network error');
            appendLog(panel, action, '', false, err.message || String(err));
            failed = true;
            break;
        }
    }

    /* Final state. */
    if (failed) {
        panel.dataset.state = 'error';
        panel.querySelector('[data-bulk-status]').textContent =
            `Stopped after ${completed} successful migration${completed === 1 ? '' : 's'} — see the failed step's output below.`;
        panel.querySelector('[data-bulk-status-badge]').className = 'badge bg-danger';
        panel.querySelector('[data-bulk-status-badge]').textContent = 'Error';
    } else {
        panel.dataset.state = 'complete';
        panel.querySelector('[data-bulk-status]').textContent =
            `Complete — ${completed} migration${completed === 1 ? '' : 's'} ran successfully.`;
        panel.querySelector('[data-bulk-status-badge]').className = 'badge bg-success';
        panel.querySelector('[data-bulk-status-badge]').textContent = 'Complete';

        /* DON'T auto-redirect (#1200): a script can return STATUS: ok yet leave
           its probe still reporting pending (e.g. it logged "[warn] manual merge
           needed / skipped"). Yanking the page after 1.2s hid that output. Leave
           the panel + log on screen and offer a manual refresh — the curator
           expands the log, reads why anything's still pending, then reloads when
           ready (the pending/applied partition re-runs on reload). */
        addRefreshButton(panel);
    }

    triggerBtn.classList.remove('disabled');
    triggerBtn.removeAttribute('aria-disabled');
}

/**
 * Append a "Refresh dashboard" button to the panel so the curator can read the
 * output log before reloading (instead of being auto-redirected). Idempotent.
 */
function addRefreshButton(panel) {
    if (panel.querySelector('[data-bulk-refresh]')) return;
    const body = panel.querySelector('.card-body') || panel;
    const wrap = document.createElement('div');
    wrap.className = 'mt-3 d-flex align-items-center gap-2 flex-wrap';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.dataset.bulkRefresh = '';
    btn.className = 'btn btn-sm btn-primary';
    btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Refresh dashboard';
    btn.addEventListener('click', () => { window.location.href = ENDPOINT; });
    const note = document.createElement('span');
    note.className = 'text-muted small';
    note.textContent = 'Expand the log above to check nothing is still pending, then refresh to update the cards.';
    wrap.append(btn, note);
    body.appendChild(wrap);
}

/**
 * Run one migration via the text-format endpoint.
 *
 * ELI5: this is the one function that actually "presses the button" for a
 * single migration and reads back whether it worked.
 *
 * Detailed: exported (#2005) so the guided setup wizard (setup-wizard.js)
 * can drive the SAME migrations one at a time, in the SAME way, instead of
 * re-implementing its own copy of "fetch this URL, parse this envelope"
 * (CLAUDE.md rule #22 — delegate, never fork). Nothing about the function's
 * own behaviour changes; it is still called by `runSequence()` above exactly
 * as before. The wizard supplies its OWN pending-migration list read from
 * the very same `[data-bulk-runner-trigger]` attribute this file's own
 * `bootSetupBulkRunner()` reads, so both callers always agree on what
 * "pending" means.
 * @returns {Promise<{ok:boolean, output:string, elapsedMs:number, error?:string}>}
 */
export async function runOne(action) {
    const url = `${ENDPOINT}?action=${encodeURIComponent(action)}&format=text`;
    const res = await apiFetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/plain' },
    });
    const text = await res.text();
    return parseEnvelope(text, res.ok);
}

/**
 * Parse the server's `STATUS / ACTION / ... --- output` envelope.
 *
 * ELI5: turns the plain-text reply the server sends back into a normal
 * JavaScript object we can check ("did it work?", "what did it print?").
 *
 * Detailed: exported alongside `runOne()` (#2005) for the same reason —
 * `runOne()` already calls this internally, and the guided setup wizard
 * needs the identical parsing so a `STATUS: error` (including a 403
 * rendered in envelope form by the per-action entitlement gate) reads the
 * same way everywhere it is handled, rather than a second regex living in
 * a second file (rule #35 — cross-file agreement needs a shared function,
 * not a repeated pattern that could quietly drift).
 */
export function parseEnvelope(text, httpOk) {
    const sepIdx = text.indexOf('\n---\n');
    let header = '';
    let output = '';
    if (sepIdx === -1) {
        header = text;
        output = '';
    } else {
        header = text.slice(0, sepIdx);
        output = text.slice(sepIdx + 5);
    }
    const fields = {};
    header.split('\n').forEach(line => {
        const m = line.match(/^([A-Z_]+):\s*(.*)$/);
        if (m) fields[m[1]] = m[2];
    });
    const status = (fields.STATUS || '').toLowerCase();
    return {
        ok: httpOk && status === 'ok',
        output: output,
        elapsedMs: parseInt(fields.ELAPSED_MS || '0', 10) || 0,
        error: fields.ERROR || (httpOk ? '' : 'HTTP error'),
    };
}

/**
 * Lazily create the inline progress panel + return it.
 *
 * a11y audit A10/A11 (2026-08-30): `data-bulk-status` now carries
 * `aria-live="polite"` — it's the running "N / M" progress + final verdict
 * text, and without it a screen-reader user gets no announcement at all as
 * the run proceeds (the NEW setup wizard's sibling status paragraph,
 * `data-setup-wiz-run-status`, already had this). `data-bulk-log`'s `<pre>`
 * is a fixed-height, `overflow:auto` scroll region with no button/link
 * inside it — WCAG 2.1.1's standard "scrollable region with no focusable
 * content" trap, since a mouse-only `overflow:auto` box can't be scrolled
 * from the keyboard at all. `tabindex="0" role="region" aria-label="…"`
 * makes it a reachable, named, keyboard-scrollable landmark.
 */
function ensurePanel(scope) {
    let panel = scope.querySelector('[data-bulk-runner-panel]');
    if (panel) return panel;

    panel = document.createElement('section');
    panel.dataset.bulkRunnerPanel = '';
    panel.dataset.state = 'idle';
    panel.className = 'card bg-body-tertiary border-secondary mb-4';
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', 'Bulk migration progress');
    panel.innerHTML = `
        <div class="card-body">
            <h4 class="card-title d-flex align-items-center gap-2 mb-2">
                <span>Apply All Pending Migrations</span>
                <span class="badge bg-warning text-dark" data-bulk-status-badge>Running</span>
            </h4>
            <p class="text-muted small mb-3" data-bulk-status aria-live="polite">Preparing…</p>
            <div data-bulk-list class="mb-3"></div>
            <details>
                <summary class="text-muted small" style="cursor:pointer;">
                    Show migration output log
                </summary>
                <pre class="bg-black text-light small p-3 mt-2 mb-0"
                     data-bulk-log
                     tabindex="0" role="region" aria-label="Migration output log"
                     style="max-height:400px;overflow:auto;"></pre>
            </details>
        </div>
    `;

    /* Insert just below the page heading + intro paragraph, above
       the action cards. The setup-database template renders a
       `<div class="row g-3 mb-4">` for the cards; place the panel
       right before it for natural reading order. */
    const cards = scope.querySelector('.container-admin .row.g-3');
    if (cards && cards.parentNode) {
        cards.parentNode.insertBefore(panel, cards);
    } else {
        const main = scope.querySelector('.container-admin') || document.body;
        main.appendChild(panel);
    }
    return panel;
}

/**
 * Append a migration's captured output to the live log inside the
 * panel's <details> drawer.
 */
function appendLog(panel, action, output, ok, errorMsg) {
    const log = panel.querySelector('[data-bulk-log]');
    if (!log) return;
    const banner = ok
        ? `═══ ${action} — completed ═══\n`
        : `═══ ${action} — FAILED ═══\n${errorMsg ? '  ' + errorMsg + '\n' : ''}`;
    log.textContent += banner;
    if (output) {
        log.textContent += output;
        if (!output.endsWith('\n')) log.textContent += '\n';
    }
    log.textContent += '\n';
    /* Auto-scroll to the bottom on each append so the curator sees
       the latest chunk. */
    log.scrollTop = log.scrollHeight;
}

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
