<?php

declare(strict_types=1);

/**
 * song_history_provenance.php - which views are real, and where they came from
 * ============================================================================
 * Account/profile rewrite, PR 2a (epic #2180), steps 2a-2 and 2a-6. Holds the
 * "do the new columns exist here?" check, what a new view is stamped with, the
 * one SQL fragment that says "only real views from the live site count", and
 * the rate limits for recording views (one place to change them).
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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php';       /* ihymns_environment(): which copy of the site recorded a view */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'profile_sync_gate.php'; /* profileSyncEnabledHere(): the per-site switch */

/* ---- the recording limits: ONE place to change them (owner decision 2026-11-23) ---- */

/** `song_view`: at most this many a minute per signed-in person, or else per address. */
const SONG_VIEW_RATE_PER_MINUTE = 60;
/** `song_view`: ...and at most this many a day. */
const SONG_VIEW_RATE_PER_DAY = 3000;
/**
 * `song_view` for someone who is NOT signed in and cannot be told apart from their neighbours:
 * the per-address limit is this many times higher. A whole congregation can sit behind ONE church
 * router (CLAUDE.md rule #26), so a limit sized for one person would count most of them out.
 */
const SONG_VIEW_ANON_ADDRESS_MULTIPLIER = 10;
/** `song_history_backfill`: at most this many an hour per person. */
const SONG_BACKFILL_RATE_PER_HOUR = 10;

/** The kinds of app that can say where a view came from. VARCHAR in the table (rule #20); checked here. */
const SONG_HISTORY_PLATFORMS = ['web', 'apple', 'android'];

if (!function_exists('songViewRatePlan')) {
    /**
     * PURE: which rate-limit buckets does ONE `song_view` call count against?
     *
     * ELI5: decide who "the caller" is, so a church full of phones behind one router is not
     * treated as one very busy person.
     *
     *   | Who                                              | Counted against                                   |
     *   |--------------------------------------------------|---------------------------------------------------|
     *   | a signed-in person                               | that person (60 a minute, 3,000 a day)            |
     *   | a Service Mode device with a LIVE presence token | that token (same numbers)                         |
     *   | a device that sent its own device id             | that device (same numbers) AND the address, at 10x |
     *   | anyone else                                      | the address, at 10x                               |
     *
     * The presence token only counts when the caller has checked it is live (`$presenceLive`): a
     * made-up token must not be a way round the address limit. A device id cannot be checked, so
     * it is always backed up by the (higher) address limit.
     *
     * @param ?int   $userId       the signed-in person's id, or null
     * @param string $presenceToken the Service Mode presence token the caller sent ('' if none)
     * @param bool   $presenceLive  true only when that token was checked and is live now
     * @param string $deviceId      the optional client device id ('' if none or malformed)
     * @param string $address       the remote address
     * @return array<int,array{scope:string,key:string,perMin:int,perDay:int}>
     */
    function songViewRatePlan(?int $userId, string $presenceToken, bool $presenceLive, string $deviceId, string $address): array
    {
        $own = static fn(string $key): array => [
            'scope' => 'song_view', 'key' => $key,
            'perMin' => SONG_VIEW_RATE_PER_MINUTE, 'perDay' => SONG_VIEW_RATE_PER_DAY,
        ];
        $addr = [
            'scope' => 'song_view_addr', 'key' => 'ip:' . substr($address, 0, 45),
            'perMin' => SONG_VIEW_RATE_PER_MINUTE * SONG_VIEW_ANON_ADDRESS_MULTIPLIER,
            'perDay' => SONG_VIEW_RATE_PER_DAY * SONG_VIEW_ANON_ADDRESS_MULTIPLIER,
        ];
        if ($userId !== null && $userId > 0) {
            return [$own('uid:' . $userId)];
        }
        if ($presenceLive && preg_match('/^[A-Za-z0-9_\-]{43}$/', $presenceToken)) {
            return [$own('pt:' . substr(hash('sha256', $presenceToken), 0, 24))];
        }
        if (preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $deviceId)) {
            return [$own('dev:' . substr(hash('sha256', $deviceId), 0, 24)), $addr];
        }
        return [$addr];
    }
}

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

if (!function_exists('songHistoryProvenanceValues')) {
    /**
     * What to stamp on a NEW view row: where it came from.
     *
     * ELI5: a real view is a person opening a song on a live site. We write down that
     * it is a real view ('view'), which copy of the site saw it, and which kind of app
     * sent it (only if the app said so, and only a kind we know).
     *
     * @param ?array $body the decoded request body (may carry `platform`)
     * @return array{source:string,channel:string,platform:?string}
     */
    function songHistoryProvenanceValues(?array $body): array
    {
        $platform = null;
        if (is_array($body) && isset($body['platform']) && is_string($body['platform'])) {
            $candidate = strtolower(trim($body['platform']));
            if (in_array($candidate, SONG_HISTORY_PLATFORMS, true)) {
                $platform = $candidate;
            }
        }
        return ['source' => 'view', 'channel' => ihymns_environment(), 'platform' => $platform];
    }
}

if (!function_exists('songHistoryCountableSqlFor')) {
    /**
     * PURE: the SQL condition that says "this view row counts as popularity".
     *
     * ELI5: when the new behaviour is on and the new columns exist, only a REAL view
     * (not one uploaded later from a device list) recorded by the LIVE site (or an old
     * row with no site recorded) counts. Otherwise every row counts, as before.
     * The text is built only from fixed words and a checked table alias, never from
     * request data.
     *
     * @param bool   $switchOn      the per-site switch is ticked here
     * @param bool   $columnsReady  the three new columns exist
     * @param string $alias         the table alias in the query ('' for none, else letters/digits/underscore)
     */
    function songHistoryCountableSqlFor(bool $switchOn, bool $columnsReady, string $alias = ''): string
    {
        if (!$switchOn || !$columnsReady) {
            return '1=1';
        }
        if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,30}$/', $alias)) {
            return '1=1'; /* a bad alias must never reach SQL */
        }
        $p = $alias === '' ? '' : $alias . '.';
        return "{$p}Source = 'view' AND ({$p}Channel = 'production' OR {$p}Channel IS NULL)";
    }
}

if (!function_exists('songHistoryCountableSql')) {
    /**
     * The condition above, resolved for THIS site and database. Use it in every query
     * that turns view rows into a COUNT that means popularity or licence reporting
     * ("most popular", the CCLI view count). Never in the person's own history.
     *
     * @param \mysqli $db    live connection
     * @param string  $alias table alias used in the query
     */
    function songHistoryCountableSql(\mysqli $db, string $alias = ''): string
    {
        if (!profileSyncEnabledHere()) {
            return '1=1';
        }
        return songHistoryCountableSqlFor(true, songHistoryProvenanceReady($db), $alias);
    }
}
