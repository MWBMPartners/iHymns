<?php

declare(strict_types=1);

/**
 * iHymns — Guided "First-run environment setup" wizard: step panes (#2005, epic #2002)
 *
 * ELI5
 * ----
 * `/manage/setup-database` already has everything a brand-new install needs —
 * a button to create the database tables, a button to "Apply all pending
 * migrations" (small, safe database updates), and a big grid of individual
 * cards for each one. That grid is powerful but it can be a lot to take in
 * the very first time someone opens it. This file is the CONTENT of a
 * friendlier, step-by-step "wizard" laid on TOP of that same page — five
 * small screens (Environment → Apply updates → Starter data → Optional
 * extras → Check everything's ready) that walk a curator through the exact
 * same buttons in the sensible order, explaining each one in plain English
 * as it goes. It adds no new way to change the database — every button here
 * presses one of the SAME buttons the classic page already has.
 *
 * Detailed
 * --------
 * This partial supplies ONLY the wizard's step panes — the
 * `[data-wiz-progress]` trail placeholder plus the five
 * `[data-wiz-step]` `<section>`s the shared stepper (`js/modules/
 * admin-wizard.js`, #1992) walks through. The surrounding Bootstrap MODAL
 * SHELL (`<div class="modal fade" id="setupWizardModal">`, its header and
 * footer) is rendered directly in `manage/setup-database.php` itself, not
 * here — deliberately, so that page's own header trigger button and its
 * `ihymns_wizard_empty_state` launcher call (#1999) both sit in the SAME
 * file as the real `id="setupWizardModal"` element they point at. (The
 * standing guard `tests/php/test-wizard-empty-state.php` checks that
 * contract textually, per FILE, not by following `require`s — see that
 * page's own inline comment at the modal shell for the full reasoning.)
 * Because of that split, this file is `require`d from ONE place only: just
 * inside that modal's `.modal-body`, AFTER `setup-database.php` has already
 * computed the dashboard's own status variables ($dbStatus, $dbTables,
 * $pendingActions, $migrationCards, $migrationManual, $migrationDryRunnable,
 * $_pendingCardCount) — this file reads them directly rather than
 * recomputing anything, which is also why it must never be required a
 * second time or from anywhere else.
 *
 * Every button below either (a) navigates to an existing page URL
 * (`?reconfigure=1`) or (b) is wired, from `js/modules/setup-wizard.js`, to
 * call the EXACT SAME per-migration runner (`runOne()`, re-exported from
 * `js/modules/setup-bulk-runner.js`) the classic "Apply all" button already
 * uses (CLAUDE.md rule #22 — delegate, never re-implement). This file adds
 * NO server action of its own; it is markup only. It NEVER renders a
 * clickable link for a `'manual' => true` migration (the destructive /
 * hand-run ones, e.g. the LinesJson-retire drop) — those are listed as
 * plain text only, pointing the curator back at the classic dashboard
 * cards further down the page, exactly like the existing pending-migration
 * expander already does for them.
 *
 * @see appWeb/public_html/manage/setup-database.php        the modal shell + page this is required from
 * @see appWeb/public_html/js/modules/setup-wizard.js        the wiring for every button in this file
 * @see appWeb/public_html/js/modules/admin-wizard.js        the shared stepper framework (#1992)
 * @see appWeb/public_html/js/modules/setup-bulk-runner.js   runOne()/parseEnvelope() this wizard re-uses
 * @see appWeb/public_html/manage/external-link-types.php    the first shipped wizard this markup shape mirrors
 * @see tests/php/test-setup-wizard.php                      the standing guard for this file
 * @see https://getbootstrap.com/docs/5.3/components/modal/  Bootstrap modal component docs
 * @see #2005 (child of epic #2002)
 */

/* Prevent direct access — same convention as every other manage partial
   (mirrors wizard-empty-state.php / head-favicon.php). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* ELI5: work out, in plain words, what state the database connection is in
   right now, so Step 1 can show a plain "yes"/"not yet" instead of raw
   technical text.
   Detailed: `$dbStatus` (computed earlier in setup-database.php, #820) is
   one of `null` (no credentials / never attempted), the literal string
   `'connected'`, or `'error: <exception message>'`. Splitting the error
   text out here — once — means every step that wants to show "why isn't it
   connected?" reads the same plain-English-friendly tail, never the raw
   `'error: '`-prefixed string. */
$_wizDbConnected = ($dbStatus === 'connected');
$_wizDbErrorText = '';
if (is_string($dbStatus) && strncmp($dbStatus, 'error: ', 7) === 0) {
    $_wizDbErrorText = substr($dbStatus, 7);
}
$_wizHasTables = count($dbTables) > 0;

/* ELI5: which copy of the site is this — the live one, or a test/preview
   copy? Shown so an admin working across more than one copy always knows
   which one they're looking at.
   Detailed: DISPLAY ONLY — never used to build a file path (rule #41's
   channel-rename trap is about `require`/`include`, not a read-only label).
   This file lives at `manage/includes/`, so its own `__DIR__` is two levels
   below the docroot; `dirname(__DIR__, 2)` walks back up to it whatever it
   is actually named on this deploy (`public_html`, `public_html_dev`,
   `public_html_beta`, …) rather than assuming the un-renamed default. */
$_wizDocrootName = basename(dirname(__DIR__, 2));

/* ELI5: the list of database updates that need a person to run them by
   hand, because they either can't be undone or need a manual check first.
   Detailed: derived from the SAME `$pendingActions` / `$migrationManual`
   arrays setup-database.php already computed from the migration registry
   (#1235 P4/C6) — never a second hand-typed list (rule #34). This wizard
   never renders a clickable/`confirm=1` link for any of these; it only
   ever names them and points back at the classic dashboard card further
   down the page, exactly like the existing pending-migration expander. */
$_wizManualPending = array_values(array_filter(
    $pendingActions,
    static fn(string $slug): bool => !empty($migrationManual[$slug])
));

/* #2005 — Guided environment setup wizard: step panes. BEGIN */
?>
<div data-wiz-progress class="mb-3"></div>

<?php /* ===================================================================
         STEP 1 — Environment: is there a working database to build on?
         =================================================================== */ ?>
<section data-wiz-step data-wiz-label="Environment">
    <h3 data-wiz-heading class="h6 mb-3">1. Check your database connection</h3>
    <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
    <p class="text-secondary small">
        Before iHymns can store anything, it needs to know how to reach your
        database and have its basic tables already created. Here's where
        things stand right now:
    </p>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2">
            <span>Database login details saved</span>
            <?php if ($hasCredentials): ?>
                <span class="badge bg-success">Yes</span>
            <?php else: ?>
                <span class="badge bg-danger">Not yet</span>
            <?php endif; ?>
        </li>
        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <span>
                Can connect to the database
                <?php if (!$_wizDbConnected && $_wizDbErrorText !== ''): ?>
                    <span class="d-block text-muted small mt-1"><?= htmlspecialchars($_wizDbErrorText) ?></span>
                <?php endif; ?>
            </span>
            <?php if ($_wizDbConnected): ?>
                <span class="badge bg-success">Yes</span>
            <?php else: ?>
                <span class="badge bg-danger">Not yet</span>
            <?php endif; ?>
        </li>
        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2">
            <span data-setup-wiz-tables-label>Basic tables exist</span>
            <span data-setup-wiz-tables-badge class="badge <?= $_wizHasTables ? 'bg-success' : 'bg-warning text-dark' ?>">
                <?= $_wizHasTables ? 'Yes' : 'Not yet' ?>
            </span>
        </li>
        <li class="list-group-item bg-transparent small text-muted">
            Website folder: <code><?= htmlspecialchars($_wizDocrootName) ?></code>
            <span class="d-block">
                This is which copy of the site you're looking at right now — useful if you work
                across more than one (a test copy, a preview copy, and the live site).
            </span>
        </li>
    </ul>

    <?php if (!$_wizDbConnected): ?>
        <a href="?reconfigure=1" class="btn btn-primary">
            Set up database connection
        </a>
        <p class="text-muted small mt-2 mb-0">
            This takes you to the connection-details form further down this page. Fill it in, save
            it, then come back and open this guide again.
        </p>
    <?php elseif (!$_wizHasTables): ?>
        <button type="button" class="btn btn-primary" data-setup-wiz-install-btn>
            Create the basic tables
        </button>
        <p class="text-muted small mt-2 mb-0" data-setup-wiz-install-status aria-live="polite">
            Safe to press even if some tables already exist — it skips anything that's already there.
        </p>
    <?php else: ?>
        <p class="text-success small mb-0">Your database is connected and ready. Select Next to continue.</p>
    <?php endif; ?>
</section>

<?php /* ===================================================================
         STEP 2 — Apply updates: bring the database schema itself up to date.
         =================================================================== */ ?>
<section data-wiz-step data-wiz-label="Apply migrations" hidden>
    <h3 data-wiz-heading class="h6 mb-3">2. Bring the database up to date</h3>
    <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
    <p class="text-secondary small">
        iHymns is improved over time, and sometimes that means the database itself needs a small,
        automatic update too — we call these "migrations". Each one is safe to run more than once;
        anything already done is simply skipped.
    </p>

    <?php if ($_pendingCardCount > 0): ?>
        <p class="mb-2">
            <span class="badge bg-warning text-dark"><?= $_pendingCardCount ?> update<?= $_pendingCardCount === 1 ? '' : 's' ?> waiting</span>
        </p>
    <?php else: ?>
        <p class="mb-2"><span class="badge bg-success">Everything is already up to date</span></p>
    <?php endif; ?>

    <?php if ($_wizManualPending !== []): ?>
        <div class="alert alert-secondary py-2 small mb-3">
            <strong><?= count($_wizManualPending) ?></strong> update<?= count($_wizManualPending) === 1 ? '' : 's' ?>
            <?= count($_wizManualPending) === 1 ? 'is' : 'are' ?> being held back on purpose, because
            <?= count($_wizManualPending) === 1 ? 'it either can\'t' : 'they either can\'t' ?> be undone
            or need someone to check something first. This guide never runs those for you — find
            <?= count($_wizManualPending) === 1 ? 'it' : 'them' ?> in the card list further down this
            page and run <?= count($_wizManualPending) === 1 ? 'it' : 'them' ?> by hand when you're ready:
            <ul class="mb-0 mt-1">
                <?php foreach ($_wizManualPending as $_wizMp): ?>
                    <li>
                        <code><?= htmlspecialchars($_wizMp) ?></code>
                        <?php if (isset($migrationCards[$_wizMp]['title'])): ?>
                            — <?= htmlspecialchars(strip_tags((string)$migrationCards[$_wizMp]['title'])) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <button type="button" class="btn btn-primary" data-setup-wiz-run-btn>
        Bring it up to date
    </button>
    <p class="text-muted small mt-2" data-setup-wiz-run-status aria-live="polite">
        <?= $_pendingCardCount === 0
            ? 'Nothing to do — every update has already been applied. Safe to press anyway; it will simply finish straight away.'
            : 'Runs every waiting update in the right order, in its own short step. Safe to stop and re-run any time.' ?>
    </p>

    <div data-setup-wiz-run-rows class="mb-2"></div>

    <?php /* a11y audit A11 (2026-08-30) — a fixed-height, overflow:auto <pre>
             with no focusable content inside it cannot be scrolled from the
             keyboard at all (WCAG 2.1.1's standard "scrollable region"
             trap). tabindex="0" + role="region" + aria-label makes it a
             reachable, named, keyboard-scrollable landmark — mirrors the
             identical fix on setup-bulk-runner.js's own log <pre>. */ ?>
    <details class="mt-2" data-setup-wiz-run-log-wrap hidden>
        <summary class="text-muted small" style="cursor:pointer;">Show what each step printed</summary>
        <pre class="bg-black text-light small p-3 mt-2 mb-0" data-setup-wiz-run-log
             tabindex="0" role="region" aria-label="Migration output log"
             style="max-height:280px;overflow:auto;"></pre>
    </details>

    <p class="text-muted small mt-3 mb-0">
        A step can say it finished and yet the check that confirms it worked doesn't always update
        immediately — the "Check everything's ready" step at the end shows the true, up-to-the-minute
        picture.
    </p>
</section>

<?php /* ===================================================================
         STEP 3 — Baseline data: the starter lists iHymns ships with.
         =================================================================== */ ?>
<section data-wiz-step data-wiz-label="Baseline data" hidden>
    <h3 data-wiz-heading class="h6 mb-3">3. Starter lists it needs</h3>
    <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
    <p class="text-secondary small">
        Alongside the updates in the last step, iHymns also ships some ready-made reference lists it
        needs to work properly — things like song themes, the names of song parts (verse, chorus, and
        so on), Bible book names, and the outside websites it recognises when you paste a link. These
        are included in the SAME updates you just applied — there's nothing extra to run here.
    </p>
    <p class="mb-3">
        <?php if ($_pendingCardCount > 0): ?>
            <span class="badge bg-warning text-dark"><?= $_pendingCardCount ?> update<?= $_pendingCardCount === 1 ? '' : 's' ?> still waiting — go back to the previous step</span>
        <?php else: ?>
            <span class="badge bg-success">All starter lists are in place</span>
        <?php endif; ?>
    </p>

    <p class="text-secondary small mb-1">Once you have some songs, here's where to add them:</p>
    <ul class="small">
        <li>
            <strong>Restore from a backup</strong> — if you already have an iHymns backup file, use
            the "Restore" card further down this page.
        </li>
        <li>
            <strong>Import songs</strong> — the Song Editor's
            <a href="/manage/editor/import2.php">import tools</a> can bring in songs from common file
            formats.
        </li>
    </ul>
</section>

<?php /* ===================================================================
         STEP 4 — Optional extras: hand off to the "Connect a service" wizards.
         =================================================================== */ ?>
<section data-wiz-step data-wiz-label="Connect services" hidden>
    <h3 data-wiz-heading class="h6 mb-3">4. Optional: connect outside services</h3>
    <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
    <p class="text-secondary small">
        iHymns can also connect to a few outside services for extra features. None of this is
        required to keep using iHymns, and you can always set it up later.
    </p>
    <ul class="small">
        <li><strong>QR codes</strong> for sharing songs and joining a live service.</li>
        <li><strong>Spam protection</strong> on sign-up and sign-in forms.</li>
        <li><strong>Email delivery</strong>, plus a few other optional technical connections.</li>
    </ul>
    <p class="small">
        Each of these has its own short, guided "Connect" walkthrough on the
        <a href="/manage/configuration">Configuration</a> page — this wizard doesn't repeat that
        setup here, it just points you to it.
    </p>
    <!-- Sibling feature: the "Connect a service" wizards (#2003) live on
         /manage/configuration and already cover IntAppsAPI, CueRCode and
         CAPTCHA with their own guided steps — this pane deliberately only
         links there rather than re-implementing any of it (rule #22). -->
</section>

<?php /* ===================================================================
         STEP 5 — Verify: the honest, freshly-checked picture.
         =================================================================== */ ?>
<section data-wiz-step data-wiz-label="Verify" hidden>
    <h3 data-wiz-heading class="h6 mb-3">5. Check everything's ready</h3>
    <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>

    <?php if ($_pendingCardCount === 0 && $_wizDbConnected): ?>
        <div class="alert alert-success py-2 mb-3">
            Every automatic update has been applied — your database is up to date.
        </div>
        <p class="small">
            Where to go next:
        </p>
        <ul class="small">
            <li><a href="/manage/schema-audit">Database structure check</a> — a deeper check for anything unexpected.</li>
            <li>Or select <strong>Finish</strong> below to close this guide.</li>
        </ul>
    <?php else: ?>
        <p class="text-secondary small">Here's what's still waiting:</p>
        <?php if (!$_wizDbConnected): ?>
            <div class="alert alert-warning py-2 small mb-2">The database isn't connected — go back to Step 1.</div>
        <?php elseif ($pendingActions !== []): ?>
            <ul class="list-group list-group-flush mb-2">
                <?php foreach ($pendingActions as $_wizPa):
                    $_wizPaCard = $migrationCards[$_wizPa] ?? null;
                    if ($_wizPaCard === null) { continue; } /* no card = hidden from the grid, same as the classic expander */
                ?>
                    <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <span>
                            <code class="text-info"><?= htmlspecialchars($_wizPa) ?></code>
                            <span class="ms-2"><?= htmlspecialchars(strip_tags((string)$_wizPaCard['title'])) ?></span>
                        </span>
                        <?php if (!empty($migrationManual[$_wizPa])): ?>
                            <span class="badge bg-danger">run by hand — see the card below</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="text-muted small">
            This list is from when the page last loaded. Select "Check again" for the live answer —
            it reloads the page and reopens this guide right here.
        </p>
    <?php endif; ?>

    <a href="?wizard=verify" class="btn btn-outline-primary">
        Check again
    </a>
    <p class="text-muted small mt-2 mb-0">As of this page loading.</p>
</section>
<?php /* #2005 — Guided environment setup wizard: step panes. END */ ?>
