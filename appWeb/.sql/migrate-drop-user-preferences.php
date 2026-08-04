<?php

declare(strict_types=1);

/**
 * iHymns — DROP the superseded tblUserPreferences table (#1671 F5)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * `tblUserPreferences` (#310) was a per-user `PreferencesJson` blob written and read by
 * exactly two API actions — `user_preferences` and `user_preferences_sync`. Those two were
 * an un-namespaced DUPLICATE of `action=user_settings`, which stores the same concept on
 * `tblUsers.Settings`: same payload shape, same last-write-wins contract, a parallel table,
 * and **no caller anywhere in the first-party tree** (verified by file-walk across appWeb +
 * appApple + appAndroid — `rg` cannot see `appWeb/.sql/` and under-reports this class of
 * audit). Two stores for one concept is a data-integrity trap rather than a dormant
 * feature: whichever a future client picked, the other would hold stale truth forever with
 * nothing to say so.
 *
 * #1671 F5 kept `user_settings`, gave it a namespaced write contract so a second product
 * (iLyricsDB) can share the row without a key-collision convention, and deleted the
 * duplicate pair. This migration is the matching table drop.
 *
 * ⚠ DESTRUCTIVE — but self-protecting, in the same abort-don't-drop shape as
 * migrate-drop-song-chords.php and migrate-retire-component-lines-json.php's Stage 0:
 *
 *   1. It REFUSES to drop unless the live table is verifiably EMPTY. The endpoints had no
 *      first-party caller, but this script cannot see the database that analysis was run
 *      against. A non-zero row count means SOMETHING wrote here — most plausibly an
 *      out-of-repo API-key partner, which is the orphan guard's stated blind spot (it
 *      reports "no in-repo caller", never "unused"). Dropping then would silently discard
 *      a real user's real preferences, so the migration stops loudly instead. The check
 *      runs BEFORE the confirm gate, so even a confirmed run cannot destroy rows.
 *   2. It requires explicit confirmation — CLI `--confirm` / web `?confirm=1` — matching
 *      this folder's other destructive scripts. Without it the script prints what it WOULD
 *      do and exits having touched nothing.
 *   3. The registry entry is `'manual' => true`, so it is excluded from "Apply all pending"
 *      and from the pending counter.
 *
 * RECOVERY: none is needed for structure — `schema.sql` records the drop and the table has
 * no readers left. If step 1 ever finds rows, the recovery is not to drop but to migrate
 * that data into `tblUsers.Settings` under a namespace, which is precisely what the new
 * contract exists to make possible.
 *
 * Idempotent: guarded on the table still existing, so a re-run is a clean no-op.
 *
 * @migration-drops tblUserPreferences
 *
 * USAGE:
 *   CLI dry-run: php appWeb/.sql/migrate-drop-user-preferences.php
 *   CLI execute: php appWeb/.sql/migrate-drop-user-preferences.php --confirm
 *   Web:         /manage/setup-database.php?action=drop-user-preferences&confirm=1
 *
 * @see appWeb/public_html/includes/user_settings.php   (the surviving store)
 * @see .claude/remediation-plan-2026-07-30.md §4.5
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migDropUserPrefs_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/**
 * Table-existence probe — the idempotency guard.
 *
 * INFORMATION_SCHEMA rather than `SHOW TABLES LIKE`, and bound rather than
 * interpolated, matching migrate-drop-song-chords.php's local helper.
 */
function _migDropUserPrefs_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migDropUserPrefs_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

/* mysqli under STRICT: every failing statement THROWS. Nothing below checks a
   return value for `false` — that would be dead code (CLAUDE.md red flags). */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migDropUserPrefs_out('=== iHymns — DROP tblUserPreferences (#1671 F5) ===');

try {
    /* --- Idempotency: already gone? --- */
    if (!_migDropUserPrefs_tableExists($db, 'tblUserPreferences')) {
        _migDropUserPrefs_out('  [SKIP] tblUserPreferences already gone — nothing to do.');
        _migDropUserPrefs_out('Done (already dropped).');
        return;
    }

    /* --- Abort-don't-drop: refuse unless the live table is EMPTY. --- */
    $countRes = $db->query('SELECT COUNT(*) FROM tblUserPreferences');
    $rowCount = (int)$countRes->fetch_row()[0];
    $countRes->close();

    if ($rowCount > 0) {
        _migDropUserPrefs_out("  [REFUSE] tblUserPreferences has {$rowCount} row(s) on this install — NOT dropping.");
        _migDropUserPrefs_out('           The #1671 zero-caller analysis covers the FIRST-PARTY tree only. Rows here');
        _migDropUserPrefs_out('           mean something out-of-repo (an API-key partner, a script) used the removed');
        _migDropUserPrefs_out('           user_preferences_sync endpoint. Dropping would discard real preferences.');
        _migDropUserPrefs_out('           Investigate, then migrate the data into tblUsers.Settings under a namespace:');
        _migDropUserPrefs_out('             SELECT UserId, PreferencesJson FROM tblUserPreferences LIMIT 20;');
        return;
    }
    _migDropUserPrefs_out('  [OK] tblUserPreferences is empty (0 rows) — safe to drop.');

    /* --- Confirm gate (web ?confirm=1 / CLI --confirm). --- */
    $confirmed = false;
    if ($isCli) {
        foreach ($argv ?? [] as $arg) {
            if ($arg === '--confirm') { $confirmed = true; }
        }
    } else {
        $confirmed = (($_GET['confirm'] ?? '') === '1');
    }

    if (!$confirmed) {
        _migDropUserPrefs_out('  Would drop: tblUserPreferences (0 rows; #310, superseded by the namespaced');
        _migDropUserPrefs_out('  action=user_settings store on tblUsers.Settings — #1671 F5).');
        _migDropUserPrefs_out('');
        _migDropUserPrefs_out('DRY RUN — nothing has been changed.');
        _migDropUserPrefs_out('Re-run with --confirm (CLI) or ?confirm=1 (web) to proceed.');
        return;
    }

    /* --- Drop --- */
    $db->query('DROP TABLE tblUserPreferences');
    _migDropUserPrefs_out('  [OK] Dropped tblUserPreferences.');
    _migDropUserPrefs_out('');
    _migDropUserPrefs_out('Done.');
} catch (\Throwable $e) {
    _migDropUserPrefs_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
