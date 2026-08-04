<?php

declare(strict_types=1);

/**
 * iHymns — Let the restored `delete_songs` default actually take effect
 *          (#1695, epic #1692 stage 3)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Curators can delete songs again, now that deleting is undoable. The code
 * already says so — but the permissions screen remembers its own answer, and a
 * remembered answer beats the code. If this site still remembers the temporary
 * "admins only" rule we shipped while deletion was permanent, this forgets it,
 * so the new rule applies. If somebody has since chosen something different on
 * purpose, their choice is left exactly as it is.
 *
 * WHY A MIGRATION AT ALL — the half a code change cannot reach
 * ------------------------------------------------------------
 * `effectiveEntitlements()` (includes/entitlements.php) lays saved overrides
 * from `tblAppSettings.entitlements_overrides` on top of the shipped defaults,
 * and a stored key replaces its default OUTRIGHT. `saveEntitlementOverrides()`
 * writes the WHOLE map on every save, never a delta.
 *
 * So on any install where `/manage/entitlements` has ever been saved, EVERY key
 * carries an explicit stored value — including `delete_songs`. Widening the
 * default in PHP is then a **silent no-op**: the code says editor+, the row says
 * admin+, and the row wins. The feature reviews as correct, deploys cleanly, and
 * changes nothing for the operator. Nobody finds out until a curator says "I
 * still can't delete".
 *
 * That failure mode is not hypothetical here — it is exactly what #1590's
 * `migrate-entitlement-truthup.php` was written for, on this same setting, and
 * #1695's own scope did not mention it.
 *
 * ⚠️ WHY CONDITIONAL, AND WHY THAT MATTERS MORE THAN THE FIX
 * -----------------------------------------------------------
 * In the database, "the interim value we shipped in stage 1" and "the operator
 * deliberately locked deletion down to admins" are THE SAME BYTES. Nothing
 * records which one wrote them.
 *
 * A blanket clear would therefore hand song deletion to every curator on a site
 * that had explicitly decided otherwise — silently, as a side effect of an
 * upgrade. That is a worse outcome than the no-op it fixes, because it is both
 * invisible and privilege-WIDENING, and this codebase's rule is that the safe
 * direction wins when the data cannot tell you the intent.
 *
 * So the prune fires only when the stored value is exactly `['admin',
 * 'global_admin']` — the value stage 1 shipped. `['global_admin']`, a custom
 * set, or anything already widened is treated as intentional and preserved.
 * The cost: an operator who happened to choose precisely the interim value
 * keeps it, and must re-tick one box. That is the right trade.
 *
 * The comparison itself lives in the PURE, directly-tested
 * `entitlementOverridesPruneIfEquals()` (includes/entitlements.php) rather than
 * inline here, so the decision that carries all the risk is unit-testable
 * without a database — see tests/php/test-delete-songs-rewiden.php.
 *
 * IDEMPOTENT. Safe to run repeatedly: once the key is gone there is nothing to
 * prune, and the sentinel is an upsert.
 *
 * @see appWeb/public_html/includes/entitlements.php  effectiveEntitlements(), the pure helper
 * @see appWeb/.sql/migrate-entitlement-truthup.php   the #1590 precedent, same setting
 * @see .claude/plan-1695-stage3.md                   §3
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

/** The exact value #1692 stage 1 shipped. Only this is ours to remove. */
const MIG_REWIDEN_INTERIM_VALUE = ['admin', 'global_admin'];
const MIG_REWIDEN_KEY           = 'delete_songs';
const MIG_REWIDEN_SENTINEL      = 'delete_songs_rewiden_applied';

/** Emit a progress line in whichever context this is running. */
function _migRewiden_out(string $line): void
{
    echo (PHP_SAPI === 'cli' ? '' : '<div>') . htmlspecialchars($line, ENT_QUOTES)
       . (PHP_SAPI === 'cli' ? "\n" : "</div>\n");
}

/**
 * Do the work. Separated from the top-level so the migration runner and a test
 * can both drive it with an explicit connection.
 *
 * @return array{pruned:bool, reason:string}
 */
function migrateDeleteSongsRewiden(\mysqli $db): array
{
    /* Read the current overrides blob. Absent or empty is the ordinary case on
       an install that has never saved the permissions page — a clean no-op, not
       an error. */
    $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
    $key  = 'entitlements_overrides';
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    $raw = (string)($row[0] ?? '');
    if ($raw === '') {
        _migRewiden_out('No saved entitlement overrides — the shipped default applies already.');
        return ['pruned' => false, 'reason' => 'no-overrides-row'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        /* Corrupt or unexpected JSON. Do NOT rewrite it — a migration that
           "repairs" a blob it cannot parse is how an operator's whole
           permission set disappears. Report and leave it alone. */
        _migRewiden_out('Saved overrides are not valid JSON — left untouched. Review /manage/entitlements.');
        return ['pruned' => false, 'reason' => 'unparseable'];
    }

    [$next, $changed] = entitlementOverridesPruneIfEquals(
        $decoded,
        MIG_REWIDEN_KEY,
        MIG_REWIDEN_INTERIM_VALUE
    );

    if (!$changed) {
        $has = array_key_exists(MIG_REWIDEN_KEY, $decoded);
        _migRewiden_out($has
            ? 'A saved `delete_songs` value exists but is NOT the stage-1 interim — treated as a '
              . 'deliberate operator choice and preserved.'
            : 'No saved `delete_songs` override — the shipped default applies already.');
        return ['pruned' => false, 'reason' => $has ? 'operator-choice-preserved' : 'key-absent'];
    }

    $json = (string)json_encode($next, JSON_UNESCAPED_SLASHES);
    $upd  = $db->prepare('UPDATE tblAppSettings SET SettingValue = ? WHERE SettingKey = ?');
    $upd->bind_param('ss', $json, $key);
    $upd->execute();
    $upd->close();

    _migRewiden_out('Cleared the stale `delete_songs` override — curators can delete again (recoverably).');
    return ['pruned' => true, 'reason' => 'pruned'];
}

/**
 * Write the sentinel the setup-database probe reads.
 *
 * The probe cannot ask "is the key absent?", because re-saving
 * /manage/entitlements legitimately writes every key back — the card would flip
 * from applied to pending for the rest of time, which is the "pending counter
 * can never reach zero" failure rule #19 exists to prevent. The sentinel records
 * that the one-off prune HAPPENED, which is the thing that cannot un-happen.
 */
function migrateDeleteSongsRewidenSentinel(\mysqli $db): void
{
    $stmt = $db->prepare(
        'INSERT INTO tblAppSettings (SettingKey, SettingValue, Description)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
    );
    $k = MIG_REWIDEN_SENTINEL;
    $v = '1';
    $d = 'Sentinel (#1695): the stage-3 re-widen considered the saved delete_songs override. '
       . 'Nothing reads this value; it exists so the setup-database card can tell the one-off '
       . 'prune has run.';
    $stmt->bind_param('sss', $k, $v, $d);
    $stmt->execute();
    $stmt->close();
}

/* ---- entry point ------------------------------------------------------- */
/* Guarded so a test can require this file for its functions without running it
   (the same shape the other migrations use). */
if (!defined('IHYMNS_MIGRATION_NO_AUTORUN')) {
    $db = getDbMysqli();
    migrateDeleteSongsRewiden($db);
    migrateDeleteSongsRewidenSentinel($db);
    _migRewiden_out('Done.');
}
