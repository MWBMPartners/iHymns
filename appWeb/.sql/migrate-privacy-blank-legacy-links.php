<?php

declare(strict_types=1);

/**
 * iHymns - Blank old personal links in searches and the audit log (B2, epic #2180)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Before step A4, every search a signed-in person made was saved next to their
 * account number; nothing ever used that. And the audit log of an account that
 * has since been erased still holds that person's internet address, browser and
 * country, and sometimes their username. This script removes those leftovers:
 *   1. every old search row stops naming a person (the search text stays);
 *   2. for accounts already erased, the audit rows lose the address, browser,
 *      referrer, country, proxy details, and `username` / `email` inside the
 *      details (the rows stay, so the trail of WHAT happened is kept).
 *
 * DESTRUCTIVE, RUN ONCE, MANUAL
 * -----------------------------
 * Registry `'manual' => true`: never part of "Apply all", never counted in the
 * pending counter. DRY RUN BY DEFAULT: a run without `?confirm=1` (web) or
 * `--confirm` (CLI) only reports how many rows it WOULD change. Work is done in
 * batches of 5,000 so a huge table never holds one long lock.
 *
 * OWNER ACTION, in this order:
 *   1. Step A4 must already be live on PRODUCTION, so no new links appear while
 *      this runs (otherwise it would be blanking a tap that is still dripping).
 *   2. Take a backup.
 *   3. Run it once as a dry run, read the counts, then run it again with
 *      confirm.
 * UNDO: only from the backup. There is no way to put a blanked address back.
 *
 * All the logic (which rows, which columns, the batch size, the probe) lives in
 * includes/privacy_legacy_blank.php, shared with the dashboard probe, so the
 * "pending" light and the run can never disagree. No schema change, so there is
 * no schema.sql edit.
 *
 * RULE #41: the shared include is resolved through IHYMNS_INCLUDES_DIR (defined
 * by the setup-database runner, which lives in the real docroot). The literal
 * `/public_html/includes` is only the fallback for a standalone CLI run.
 *
 * USAGE:
 *   Web (dry run): /manage/setup-database -> "Blank old personal links"
 *   Web (apply):   add &confirm=1 to the run URL
 *   CLI (dry run): php appWeb/.sql/migrate-privacy-blank-legacy-links.php
 *   CLI (apply):   php appWeb/.sql/migrate-privacy-blank-legacy-links.php --confirm
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/privacy_legacy_blank.php
 * @see .claude/account-profile-rewrite-plan.md  Phase B, step B2
 */

$isCli = (PHP_SAPI === 'cli');

if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}
$_incDir = defined('IHYMNS_INCLUDES_DIR') ? IHYMNS_INCLUDES_DIR : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/privacy_legacy_blank.php';

function _migPrivBlank_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migPrivBlank_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* CONFIRM: CLI uses --confirm, web uses ?confirm=1. Anything else is a DRY RUN. */
$confirm = $isCli ? in_array('--confirm', $argv ?? [], true) : (($_GET['confirm'] ?? '') === '1');
$dryRun = !$confirm;

_migPrivBlank_out('=== iHymns - Blank old personal links (B2, epic #2180) ===');
_migPrivBlank_out($dryRun ? 'Mode: DRY RUN (report only - pass ?confirm=1 / --confirm to apply)' : 'Mode: APPLY');
_migPrivBlank_out('');

try {
    /* ---- 1. searches ---- */
    if (!privacyLegacySearchReady($db)) {
        _migPrivBlank_out('  [SKIP] tblSearchQueries.UserId not present on this install.');
    } else {
        $pending = privacyLegacySearchPendingCount($db);
        _migPrivBlank_out("  Searches still naming a person: {$pending}");
        if (!$dryRun && $pending > 0) {
            $total = 0;
            while (($n = privacyLegacyBlankSearchBatch($db)) > 0) {
                $total += $n;
                _migPrivBlank_out("    ... unlinked {$total} so far");
            }
            _migPrivBlank_out("  [OK] Unlinked {$total} search row(s) from people.");
        }
    }

    /* ---- 2. audit log of erased accounts ---- */
    if (!privacyLegacyAuditReady($db)) {
        _migPrivBlank_out('  [SKIP] audit log or user status column not present on this install.');
    } else {
        $pending = privacyLegacyAuditPendingCount($db);
        _migPrivBlank_out("  Audit rows of erased accounts still holding personal details: {$pending}");
        if (!$dryRun && $pending > 0) {
            $total = 0;
            while (($n = privacyLegacyBlankAuditBatch($db)) > 0) {
                $total += $n;
                _migPrivBlank_out("    ... blanked {$total} so far");
            }
            _migPrivBlank_out("  [OK] Blanked {$total} audit row(s).");
        }
    }

    _migPrivBlank_out('');
    _migPrivBlank_out($dryRun
        ? 'Dry run complete - nothing was changed. Take a backup, then pass ?confirm=1 (web) or --confirm (CLI) to apply.'
        : 'Done.');
} catch (\Throwable $e) {
    _migPrivBlank_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
