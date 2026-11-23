<?php

declare(strict_types=1);

/**
 * song_history_provenance.php - which views are real, and where they came from
 * ============================================================================
 * Account/profile rewrite, PR 2a (epic #2180). This step (2a-2) adds only the
 * "do the new columns exist here?" check; step 2a-6 adds the rest of the file
 * (what a view is stamped with, and which rows count as popularity).
 *
 * ELI5
 * ----
 * The foundation migration (B1) adds three columns to the song-view history:
 * where the view came from (Source), which copy of the site recorded it
 * (Channel) and which kind of app (Platform). The migration is run by hand and
 * the three copies of the site share one database, so code can be deployed
 * before the columns exist. Asking for a column that is not there makes the
 * database throw, which would blank a page. So every use of those columns first
 * asks `songHistoryProvenanceReady()`, and if the answer is no, behaves exactly
 * as it did before.
 *
 * @see tests/php/test-account-foundation-gating.php  the guard that every use sits behind this check
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'transaction_fatal.php'; /* songRelocateIsTransactionFatal() */

if (!function_exists('songHistoryProvenanceReady')) {
    /**
     * Do ALL THREE new `tblSongHistory` columns (Source, Channel, Platform) exist?
     *
     * A partial apply never counts as ready (an AND across the columns, rule #19).
     * Remembered for the request. An error while asking means "not ready" (not
     * remembered), except an error that ENDS a transaction, which is passed up.
     *
     * @param \mysqli $db a live connection from getDbMysqli()
     */
    function songHistoryProvenanceReady(\mysqli $db): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongHistory'
                    AND COLUMN_NAME IN ('Source', 'Channel', 'Platform')"
            );
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            return $ready = ($count === 3);
        } catch (\Throwable $_e) {
            if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
            return false;
        }
    }
}
