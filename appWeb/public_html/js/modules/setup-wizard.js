/* ==========================================================================
 *  setup-wizard.js — guided "First-run environment setup" wizard (#2005,
 *  epic #2002) for /manage/setup-database
 *
 *  ELI5
 *  ----
 *  The database-setup page already has everything a brand-new install
 *  needs — a button to create the tables, a button to apply every pending
 *  update, and a big grid of individual cards. This file drives a smaller,
 *  friendlier, step-by-step version of that SAME page: five small screens
 *  that press the SAME buttons in the sensible order, one at a time,
 *  explaining each one in plain English. It does not know how to change
 *  the database itself — it only presses buttons that already exist.
 *
 *  DETAILED — WHY THIS SHAPE (CLAUDE.md modularity rule + rule #22)
 *  ------------------------------------------------------------------------
 *  Every step's actual WORK is delegated to code that already exists and
 *  is already exercised by the classic dashboard:
 *    - the shared stepper           `./admin-wizard.js` (`createWizard`, #1992)
 *    - the per-migration transport  `./setup-bulk-runner.js` (`runOne()` /
 *                                     `parseEnvelope()`, exported for this
 *                                     file by #2005 — the SAME function the
 *                                     classic "Apply all" button's own
 *                                     `runSequence()` calls internally, so
 *                                     both callers agree on what a
 *                                     `STATUS: ok` / `STATUS: error`
 *                                     envelope means without a second
 *                                     parser anywhere)
 *  This file owns ZERO new server behaviour and constructs NO URL of its
 *  own beyond what `runOne()` already builds. It never drives a
 *  `'manual' => true` (destructive / hand-run-only) migration: the ONLY
 *  list of slugs it ever runs is the exact CSV the server already put on
 *  `[data-bulk-runner-trigger]`'s `data-pending-migrations` attribute
 *  (`setup-database.php`, #1235 P4/C6) — that attribute is built server-side
 *  from `$pendingActions` filtered by `empty($migrationManual[$slug])`, so
 *  a manual migration's slug simply never appears in the string this file
 *  reads. There is no second, JS-side list of "which migrations are safe"
 *  to get out of sync with the server's.
 *
 *  Mounted EXACTLY ONCE at page load (`bootSetupWizard()`, idempotent via a
 *  `dataset` boot flag — the SAME pattern `setup-bulk-runner.js`'s own
 *  `bootSetupBulkRunner()` uses) because the modal is server-rendered once,
 *  not per navigation.
 *
 *  @see appWeb/public_html/js/modules/admin-wizard.js          the shared stepper (#1992) — supplies ZERO stepper/focus/a11y logic of its own
 *  @see appWeb/public_html/js/modules/setup-bulk-runner.js     runOne()/parseEnvelope() this file re-uses; never re-implemented here
 *  @see appWeb/public_html/manage/setup-database.php            the modal shell (id="setupWizardModal") + page this boots on
 *  @see appWeb/public_html/manage/includes/setup-wizard-modal.php the step-pane markup this file wires up
 *  @see appWeb/public_html/manage/editor/v2/new-song-wizard.js  the closest analog for "separate file, not inline module" + boot-once shape
 *  @see https://getbootstrap.com/docs/5.3/components/modal/     Bootstrap Modal methods (getOrCreateInstance/show/hide) used below
 *  @see #2005 (child of epic #2002)
 * ========================================================================== */

import { createWizard } from './admin-wizard.js';
import { runOne } from './setup-bulk-runner.js';

const LAST_STEP = 4; // 0-based: Environment, Apply migrations, Baseline data, Connect services, Verify

/**
 * Boot the guided setup wizard. Idempotent — re-calling on the same DOM is
 * a safe no-op (mirrors `bootSetupBulkRunner()`'s own `dataset` flag).
 *
 * @param {ParentNode} [root=document]
 */
export function bootSetupWizard(root) {
    const scope = root || document;
    const modalEl = scope.querySelector('#setupWizardModal');
    if (!modalEl || modalEl.dataset.setupWizardBooted === '1') { return; }
    modalEl.dataset.setupWizardBooted = '1';

    const rootEl = modalEl.querySelector('#setupWizardRoot') || modalEl;

    /* ---- Step 1: "Create the basic tables" ----------------------------- */
    const installBtn    = modalEl.querySelector('[data-setup-wiz-install-btn]');
    const installStatus = modalEl.querySelector('[data-setup-wiz-install-status]');
    const tablesBadge   = modalEl.querySelector('[data-setup-wiz-tables-badge]');

    if (installBtn) {
        installBtn.addEventListener('click', () => {
            installBtn.disabled = true;
            if (installStatus) { installStatus.textContent = 'Creating the basic tables…'; }
            runOne('install').then((result) => {
                if (result.ok) {
                    if (installStatus) { installStatus.textContent = 'Done — the basic tables are ready. Select Next to continue.'; }
                    if (tablesBadge) {
                        tablesBadge.textContent = 'Yes';
                        tablesBadge.className = 'badge bg-success';
                    }
                    /* Button has done its one job — hide it rather than
                       leaving a "Create the basic tables" button sitting
                       under a "Yes" badge, which would read as
                       contradictory. */
                    installBtn.hidden = true;
                } else {
                    installBtn.disabled = false;
                    if (installStatus) {
                        installStatus.textContent = 'That didn’t work: ' + (result.error || 'unknown error') + '. You can try again.';
                    }
                }
            }).catch((err) => {
                installBtn.disabled = false;
                if (installStatus) { installStatus.textContent = 'Network error: ' + (err && err.message ? err.message : String(err)); }
            });
        });
    }

    /* ---- Step 2: "Bring it up to date" ---------------------------------
       D2a (owner default, ON): back up first — but only when the database
       already had at least one table at page load (`data-has-tables="1"`
       on the modal) — a virgin install has nothing to protect yet, and
       `install` (Step 1) only just ran. The classic no-JS "Apply all"
       path already backs up first (setup-database.php); the per-migration
       JS path never did — this wizard is the one place that gap is
       closed, additively, without touching that existing code at all. */
    const runBtn      = modalEl.querySelector('[data-setup-wiz-run-btn]');
    const runStatus   = modalEl.querySelector('[data-setup-wiz-run-status]');
    const runRows     = modalEl.querySelector('[data-setup-wiz-run-rows]');
    const runLogWrap  = modalEl.querySelector('[data-setup-wiz-run-log-wrap]');
    const runLog      = modalEl.querySelector('[data-setup-wiz-run-log]');
    let runInProgress = false;

    function appendRunLog(label, output, ok, errorMsg) {
        if (!runLog || !runLogWrap) { return; }
        runLogWrap.hidden = false;
        const banner = ok
            ? '═══ ' + label + ' — done ═══\n'
            : '═══ ' + label + ' — FAILED ═══\n' + (errorMsg ? '  ' + errorMsg + '\n' : '');
        runLog.textContent += banner;
        if (output) {
            runLog.textContent += output;
            if (!output.endsWith('\n')) { runLog.textContent += '\n'; }
        }
        runLog.textContent += '\n';
        runLog.scrollTop = runLog.scrollHeight;
    }

    function makeRunRow(label) {
        const row = document.createElement('div');
        row.className = 'd-flex align-items-start gap-2 py-1 border-bottom border-secondary small';
        row.innerHTML =
            '<span class="run-row-icon flex-shrink-0" aria-hidden="true" style="display:inline-block;width:1.25rem;text-align:center;">○</span>'
            + '<span class="flex-grow-1">' + escapeHtml(label) + '</span>'
            + '<span class="run-row-meta text-muted"></span>';
        return row;
    }

    /**
     * Run one step (a migration slug, or the 'backup' preamble) and update
     * its row + the shared log. Returns the same shape `runOne()` does.
     */
    function runStep(row, label, action) {
        const icon = row.querySelector('.run-row-icon');
        const meta = row.querySelector('.run-row-meta');
        icon.textContent = '⟳';
        icon.classList.add('text-warning');
        meta.textContent = 'Running…';
        return runOne(action).then((result) => {
            if (result.ok) {
                icon.textContent = '✓';
                icon.classList.remove('text-warning');
                icon.classList.add('text-success');
                meta.textContent = result.elapsedMs > 0 ? result.elapsedMs + ' ms' : '';
            } else {
                icon.textContent = '✗';
                icon.classList.remove('text-warning');
                icon.classList.add('text-danger');
                meta.textContent = result.error || 'Failed';
            }
            appendRunLog(label, result.output, result.ok, result.error);
            return result;
        }, (err) => {
            icon.textContent = '✗';
            icon.classList.remove('text-warning');
            icon.classList.add('text-danger');
            const msg = err && err.message ? err.message : String(err);
            meta.textContent = msg;
            appendRunLog(label, '', false, msg);
            return { ok: false, output: '', elapsedMs: 0, error: msg };
        });
    }

    function pendingMigrationSlugs() {
        /* THE single source of "what's pending, in what order" — the same
           attribute the classic "Apply all" button already reads
           (setup-database.php, #869), already excluding every
           'manual' => true slug at the server (#1235 P4/C6). This file
           never maintains a second copy of that list. */
        const trigger = document.querySelector('[data-bulk-runner-trigger]');
        const csv = trigger ? (trigger.dataset.pendingMigrations || '') : '';
        return csv.split(',').map((s) => s.trim()).filter(Boolean);
    }

    async function runSequence() {
        const slugs = pendingMigrationSlugs();
        if (runRows) { runRows.innerHTML = ''; }
        if (runLog) { runLog.textContent = ''; }
        if (runLogWrap) { runLogWrap.hidden = true; }

        if (slugs.length === 0) {
            if (runStatus) { runStatus.textContent = 'Nothing to do — every update has already been applied.'; }
            return;
        }

        runInProgress = true;
        if (runBtn) { runBtn.disabled = true; }

        /* D2a — backup preamble. Only when there was already something in
           the database worth protecting; a fresh install with zero tables
           (Step 1 may have JUST created them) has nothing to back up yet. */
        if (rootEl.dataset.hasTables === '1') {
            const backupRow = makeRunRow('Back up the database first');
            if (runRows) { runRows.appendChild(backupRow); }
            if (runStatus) { runStatus.textContent = 'Backing up the database before making any changes…'; }
            const backupResult = await runStep(backupRow, 'Back up the database first', 'backup');
            if (!backupResult.ok) {
                if (runStatus) {
                    runStatus.textContent = 'The backup didn’t complete: ' + (backupResult.error || 'unknown error') + '.';
                }
                /* Never proceed silently on a failed backup — surface an
                   explicit, operator-clicked "Skip and continue" rather
                   than either stopping the wizard cold or (worse)
                   ploughing ahead unbacked-up. Mirrors the no-JS bulk
                   path's own "abort on backup failure" behaviour, but
                   gives the operator a way through instead of a dead end. */
                await new Promise((resolve) => {
                    const skipBtn = document.createElement('button');
                    skipBtn.type = 'button';
                    skipBtn.className = 'btn btn-sm btn-outline-danger mt-2';
                    skipBtn.textContent = 'Skip backup and continue anyway';
                    skipBtn.addEventListener('click', () => {
                        skipBtn.remove();
                        resolve();
                    }, { once: true });
                    if (runRows) { runRows.appendChild(skipBtn); }
                });
            }
        }

        let completed = 0;
        let failed = false;
        for (const slug of slugs) {
            const row = makeRunRow(slug);
            if (runRows) { runRows.appendChild(row); }
            if (runStatus) { runStatus.textContent = 'Applying update ' + (completed + 1) + ' of ' + slugs.length + '…'; }
            const result = await runStep(row, slug, slug);
            if (result.ok) {
                completed++;
            } else {
                failed = true;
                break;
            }
        }

        runInProgress = false;
        if (runBtn) { runBtn.disabled = false; }
        if (runStatus) {
            runStatus.textContent = failed
                ? 'Stopped after ' + completed + ' update' + (completed === 1 ? '' : 's') + ' — see the failed step above for what happened.'
                : 'Done — ' + completed + ' update' + (completed === 1 ? '' : 's') + ' applied. '
                    + 'A step can say it worked before the check confirming it updates — the Verify step gives the true picture.';
        }
    }

    if (runBtn) {
        runBtn.addEventListener('click', () => {
            runSequence().catch((err) => {
                // eslint-disable-next-line no-console
                console.error('[setup-wizard] run failed:', err);
            });
        });
    }

    /* ---- the shared stepper --------------------------------------------- */
    const wizard = createWizard(rootEl, {
        host: 'bootstrap-modal',
        validateStep(index) {
            if (index === 0) {
                return rootEl.dataset.dbStatus === 'connected'
                    ? true
                    : 'Connect the database first — use “Set up database connection” above.';
            }
            if (index === 1 && runInProgress) {
                return 'Wait for the current run to finish first.';
            }
            return true;
        },
        onStepChange(_from, to) {
            const nextBtn = rootEl.querySelector('[data-wiz-next]');
            if (nextBtn) { nextBtn.textContent = (to === LAST_STEP) ? 'Finish' : 'Next'; }
        },
        onFinish() {
            const bs = (window.bootstrap && window.bootstrap.Modal)
                ? window.bootstrap.Modal.getInstance(modalEl)
                : null;
            if (bs) { bs.hide(); }
            /* Strip a `?wizard=verify` deep link from the address bar once
               the guide is closed, so a plain browser refresh afterwards
               doesn't unexpectedly reopen it (rule #33 — this page reads
               the param, so it also cleans up after honouring it). */
            if (window.location.search.includes('wizard=')) {
                const url = new URL(window.location.href);
                url.searchParams.delete('wizard');
                window.history.replaceState(null, '', url.pathname + url.search + url.hash);
            }
        },
    });

    /* ---- deep-link auto-open (D4) — `?wizard=verify` reopens the guide
       straight on the Verify step after the full-page reload that gives it
       fresh, live probe results. `goTo()` never validates and never
       navigates, so this cannot loop or get stuck. */
    if (rootEl.dataset.openStep === 'verify' && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        wizard.goTo(LAST_STEP);
    }
}

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
