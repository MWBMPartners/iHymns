<?php

declare(strict_types=1);

/**
 * iHymns — Drop the stale saved overrides for the three re-aimed entitlements
 *          (#1590, entitlement truth-up E1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The permissions screen has always let an operator tick boxes for "Delete
 * songs", "Bulk-edit songs" and "Back up database". Until now nothing in the
 * code ever LOOKED at those three boxes, so whatever they said was meaningless.
 * From this release the code does look at them — and on a site where somebody
 * once pressed Save on that screen, the meaningless old answer would suddenly
 * start being obeyed, quietly taking abilities away from curators who use them
 * every day. This migration forgets the three stale answers so the app falls
 * back to the corrected defaults that ship in the code.
 *
 * WHY IT IS NEEDED — the half of "no behaviour change" that a code change
 * cannot reach
 * -------------------------------------------------------------------------
 * `includes/entitlements.php` ships DEFAULTS; `effectiveEntitlements()` lays any
 * saved overrides from `tblAppSettings.entitlements_overrides` on top of them,
 * per key. And `saveEntitlementOverrides()` (manage/entitlements.php:~64) does
 * NOT write a delta — it writes the WHOLE map, every key, on every save. So on
 * any install where the entitlements page has ever been saved, all three keys
 * carry an explicit stored value that shadows the default completely.
 *
 * That is why aligning the defaults in code was necessary but not sufficient:
 *
 *   delete_songs      ⚠️ THE REASON FOR THIS ONE CHANGED — see #1692 stage 1.
 *                     Batch 6 established the editor APIs really admitted
 *                     editor+ all along, and aligned the code default down to
 *                     match. Establishing that prompted "is a delete
 *                     recoverable?", and it is not: a hard DELETE FROM tblSongs,
 *                     no soft-delete column, 38 of 41 FKs cascading including
 *                     fk_Revisions_Song — the revision history goes with the
 *                     song and only a database backup brings it back. The owner
 *                     chose to narrow it to admin+ until soft delete exists.
 *                     So pruning this key no longer PRESERVES a curator's
 *                     ability to delete — it ENFORCES the reduction, by clearing
 *                     any stored value (including a deliberate editor+ one) so
 *                     the admin-only default applies. An operator who disagrees
 *                     can re-tick `editor` at /manage/entitlements; that control
 *                     is real now, which is the point of Batch 6.
 *                     Stage 2 adds soft delete and this reverts to editor+.
 *   bulk_edit_songs   identical story (remediation plan §4.6 predicted this one).
 *   run_db_backup     code default WAS ['admin','global_admin'] while the only
 *                     page that can take a backup requires `run_db_install`
 *                     (global_admin). Cosmetic rather than dangerous — an admin
 *                     never reached the button — but the stored value keeps the
 *                     screen advertising a capability the page denies.
 *
 * Deleting the three keys from the stored JSON is precise: every OTHER key the
 * operator has set keeps its value, including deliberate choices for keys this
 * pass also wired (`edit_users`, `delete_users`, `change_user_roles`,
 * `assign_global_admin`, `run_db_migrate`, `run_db_restore`). Those are left
 * alone on purpose — their defaults did not move, so an operator's stored
 * setting for them is a real preference that should now start being honoured.
 * Only the three whose MEANING changed underfoot are reset.
 *
 * Nothing is lost that a person chose knowingly: before this release, a tick in
 * any of the three boxes had no effect whatsoever, so no stored value for them
 * can represent an observed preference. An operator who does want delete
 * restricted to admins can now say so on /manage/entitlements and, for the first
 * time, be obeyed.
 *
 * SAFE TO RUN
 * -----------
 *  - No DDL. One row of `tblAppSettings` is rewritten, plus one sentinel row.
 *  - Absent row / empty value / unparseable JSON → nothing to prune, still
 *    marks itself applied. An install that never saved the page is already
 *    correct by construction.
 *  - Idempotent: a second run finds the three keys gone and writes the same
 *    sentinel.
 *  - Not `manual` — it removes a privilege REMOVAL, so it is safe inside
 *    "Apply all pending" and wants to run early rather than after somebody
 *    reports being locked out.
 *
 * @migration-modifies tblAppSettings (prunes 3 keys from entitlements_overrides;
 *                      writes the entitlement_truthup_applied sentinel)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-entitlement-truthup.php
 *   Web:  /manage/setup-database -> "Entitlement truth-up — clear stale overrides (#1590)"
 *
 * @requires PHP 8.1+ with mysqli extension
 * @link https://www.php.net/manual/en/function.json-decode.php
 * @link https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    /* Run from the dashboard the page's own gate applies; run directly, this
       re-checks. Mirrors every other migrate-*.php in this directory. */
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migEntTruthup_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) { flush(); }
}

_migEntTruthup_out('Entitlement truth-up — clearing stale saved overrides (#1590)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* The three keys whose stored value must stop shadowing the corrected default.
   Kept here as ONE list so the probe's sentinel and this loop cannot disagree
   about what "applied" means (CLAUDE.md rule #35). */
const MIG_ENT_TRUTHUP_RESET_KEYS = ['delete_songs', 'bulk_edit_songs', 'run_db_backup'];

$overridesKey = 'entitlements_overrides';
$sentinelKey  = 'entitlement_truthup_applied';

/* mysqli runs under MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (see
   includes/db_mysql.php), so a failing statement THROWS — there is deliberately
   no `if (!$stmt->execute())` anywhere below, because that branch is dead code
   and reads as a handled error when it is not (CLAUDE.md red flag). */
$stmt = $mysqli->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
$stmt->bind_param('s', $overridesKey);
$stmt->execute();
$row = $stmt->get_result()->fetch_row();
$stmt->close();

$raw = (string)($row[0] ?? '');

if ($row === null) {
    _migEntTruthup_out('[skip] No entitlements_overrides row — this install has never saved '
                     . '/manage/entitlements, so the shipped defaults are already in force.');
} elseif ($raw === '') {
    _migEntTruthup_out('[skip] entitlements_overrides is empty — nothing to prune.');
} else {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        /* Unparseable JSON is already ignored by effectiveEntitlements() (it
           only merges when json_decode returns an array), so the defaults are
           in force and there is nothing to fix. Rewriting it would be an
           unrequested repair of data this migration does not understand. */
        _migEntTruthup_out('[skip] entitlements_overrides is not valid JSON — effectiveEntitlements() '
                         . 'already falls back to the defaults, so nothing is shadowed.');
    } else {
        $removed = [];
        foreach (MIG_ENT_TRUTHUP_RESET_KEYS as $k) {
            if (array_key_exists($k, $decoded)) {
                $was = is_array($decoded[$k]) ? implode('/', array_map('strval', $decoded[$k])) : '(non-list)';
                $removed[] = $k . ' [was: ' . ($was === '' ? '(nobody)' : $was) . ']';
                unset($decoded[$k]);
            }
        }

        if ($removed === []) {
            _migEntTruthup_out('[skip] None of the three keys is present in the saved overrides — '
                             . 'already pruned, or saved before they existed.');
        } else {
            $json = (string)json_encode($decoded, JSON_UNESCAPED_SLASHES);
            $upd  = $mysqli->prepare('UPDATE tblAppSettings SET SettingValue = ? WHERE SettingKey = ?');
            $upd->bind_param('ss', $json, $overridesKey);
            $upd->execute();
            $upd->close();
            foreach ($removed as $r) {
                _migEntTruthup_out('[upd ] dropped stale override for ' . $r . ' — now falls back to the shipped default.');
            }
        }
    }
}

/* Sentinel — the probe's evidence that this ran. A data migration has no schema
   to inspect afterwards, and "the three keys are absent" is NOT a usable probe:
   re-saving /manage/entitlements legitimately writes all three back, which would
   flip the card from applied to pending forever (rule #19's "the probe must be
   drivable to zero"). A sentinel records the EVENT, which is what actually
   happened once and cannot un-happen. */
$sentinelValue = '1';
$sentinelDesc  = 'Sentinel (#1590): the entitlement truth-up cleared the stale saved overrides for '
               . 'delete_songs / bulk_edit_songs / run_db_backup. Nothing reads this value; it exists '
               . 'so the setup-database card can tell that the one-off prune has run.';
$sen = $mysqli->prepare(
    'INSERT INTO tblAppSettings (SettingKey, SettingValue, Description)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
);
$sen->bind_param('sss', $sentinelKey, $sentinelValue, $sentinelDesc);
$sen->execute();
$sen->close();

_migEntTruthup_out('Entitlement truth-up finished (#1590).');
