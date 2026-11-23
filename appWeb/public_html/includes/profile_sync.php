<?php

declare(strict_types=1);

/**
 * profile_sync.php - the new per-person items table, and "has it been created here?"
 * ============================================================================
 * Account/profile rewrite (epic #2180). PR 2a only needs the readiness check;
 * the sync core itself arrives in PR 2b and will live in this same file.
 *
 * ELI5
 * ----
 * The foundation migration (B1) creates a new table, `tblUserProfileItems`.
 * Migrations are run by hand from the admin page, and the three copies of the
 * site share one database, so code can be deployed before the table exists.
 * With mysqli in STRICT mode, asking for a table that is not there THROWS and
 * would blank the page. So before anything touches the table it asks
 * `profileItemsReady()`, and if the answer is no it behaves exactly as it did
 * before this work existed.
 *
 * @see includes/profile_sync_gate.php  the on/off switch
 * @see tests/php/test-account-foundation-gating.php  the guard that every use of the table sits behind this check
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'profile_sync_gate.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'transaction_fatal.php'; /* songRelocateIsTransactionFatal(): errors that end a transaction must be passed up */

if (!function_exists('profileItemsReady')) {
    /**
     * Has the `tblUserProfileItems` table been created on this install?
     *
     * Remembered for the rest of the request (the answer cannot change mid-request).
     * An error while asking counts as "not ready" (fail safe) and is NOT remembered,
     * so a brief database hiccup does not switch the feature off for a whole request.
     * An error that ENDS a transaction is passed up, never swallowed (rule: songRelocateIsTransactionFatal).
     *
     * @param \mysqli $db a live connection from getDbMysqli()
     */
    function profileItemsReady(\mysqli $db): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserProfileItems' LIMIT 1"
            );
            $stmt->execute();
            $ready = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return $ready;
        } catch (\Throwable $_e) {
            if (songRelocateIsTransactionFatal($_e)) { throw $_e; } /* an error that ended a transaction is not "table missing" */
            return false;
        }
    }
}

if (!function_exists('profileSyncActiveHere')) {
    /**
     * Should THIS site use the new account behaviour right now? True only when the
     * channel switch is ticked AND the new table has been created.
     *
     * ELI5: both lights must be green. The switch is checked first, so while it is
     * off (the default) no database question is asked at all. Any database trouble
     * counts as "not active" - the previous behaviour carries on.
     *
     * @param ?\mysqli $db optional connection; one is opened only if the switch is on
     */
    function profileSyncActiveHere(?\mysqli $db = null): bool
    {
        if (!profileSyncEnabledHere()) {
            return false;
        }
        try {
            return profileItemsReady($db ?? getDbMysqli());
        } catch (\Throwable $_e) {
            return false;
        }
    }
}

if (!function_exists('profileItemRead')) {
    /**
     * Read ONE stored profile item's value (or null when there is none).
     *
     * ELI5: look up a single small saved choice for a person, for example the period they
     * picked for "Your most viewed". Returns null when the new table does not exist yet, when
     * the person never saved that item, or when it was removed (a delete marker). The caller
     * validates the value against the registry before trusting it.
     *
     * @param \mysqli $db     live connection
     * @param int     $userId the person
     * @param string  $section registry section, e.g. 'home'
     * @param string  $itemKey registry key, e.g. 'mostViewedPeriod'
     * @return mixed the decoded JSON value, or null
     */
    function profileItemRead(\mysqli $db, int $userId, string $section, string $itemKey): mixed
    {
        if (!profileItemsReady($db)) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT ValueJson FROM tblUserProfileItems
              WHERE UserId = ? AND Section = ? AND ItemKey = ? AND DeletedAt IS NULL LIMIT 1'
        );
        $stmt->bind_param('iss', $userId, $section, $itemKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row === null || $row[0] === null) {
            return null;
        }
        return json_decode((string)$row[0], true);
    }
}
