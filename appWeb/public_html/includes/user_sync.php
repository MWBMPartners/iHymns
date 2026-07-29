<?php

declare(strict_types=1);

/**
 * user_sync.php — shared merge/replace sync guards for the per-user stores (#1649)
 * ============================================================================
 *
 * ONE implementation of the "which server rows may this replace-sync delete?"
 * decision, used by the three handlers in api.php that share an identical
 * merge/replace contract:
 *
 *   - case 'user_setlists_sync'  (cap 50,  tblUserSetlists.UpdatedAt)
 *   - case 'favorites_sync'      (cap 500, tblUserFavorites.CreatedAt)
 *   - case 'custom_tags_sync'    (cap 200, tblUserCustomTags.CreatedAt)
 *
 * Extracting the logic here rather than patching each handler is the CLAUDE.md
 * modularity rule: three handlers with the same logic get one helper, not three
 * copies that drift.
 *
 * ---------------------------------------------------------------------------
 * THE BUG THIS FIXES (#1649) — silent, permanent, user-visible data loss
 * ---------------------------------------------------------------------------
 *
 * ELI5: the app used to say "here is everything I have — delete anything else
 * you're holding". But it only ever sent the FIRST 50 (or 500, or 200) items.
 * So if you had 60 set lists, the server threw away 10 of them, forever.
 *
 * In detail: each handler capped the incoming payload with array_slice() and
 * THEN, in 'replace' mode, deleted every server row absent from that capped
 * list. The cap and the delete pass were reading the same truncated array, so
 * over-cap users silently lost the tail of their collection on the very next
 * per-edit auto-sync. Two amplifiers made it total rather than partial: the
 * offline drain wrote the truncated server response back over LOCAL storage,
 * and shareSetlist() could fire a 'replace' before the first reconcile had
 * hydrated the cache at all.
 *
 * TWO GUARDS, both enforced by userSyncDeletableIds():
 *
 *   (1) TRUNCATION SKIP. If the payload was capped, the client's list is BY
 *       DEFINITION not the whole truth, so "absent from the payload" no longer
 *       implies "the user deleted it". A truncated replace therefore deletes
 *       NOTHING — it degrades to a merge. This guard is unconditional and
 *       needs no client cooperation, which is what protects the existing
 *       native apps (Apple/Android) that will never send the new `since`
 *       field.
 *
 *   (2) SINCE WATERMARK. A client that knows when it last successfully
 *       absorbed a server response sends that timestamp back as `since`. A row
 *       created/updated server-side AFTER that moment cannot be something this
 *       client deliberately deleted — it is another device's write that this
 *       client has never seen. Those rows are kept. A client that sends no
 *       `since` gets exactly today's absence-only behaviour (back-compat).
 *
 * ---------------------------------------------------------------------------
 * WHY THE COMPARISON IS PHP-SIDE, LEXICOGRAPHIC, ON STRINGS
 * ---------------------------------------------------------------------------
 *
 * ELI5: we compare two clock readings that came from the same clock, written
 * the same way, so plain text comparison is enough — and it keeps the clock
 * out of the SQL.
 *
 * `since` is minted by userSyncNow() from the server's own `SELECT NOW()` and
 * handed to the client; the row timestamps come out of MySQL in the same
 * session, same time zone, same 'YYYY-MM-DD HH:MM:SS' format. Fixed-width
 * zero-padded ISO-ish datetimes sort identically under strcmp and under
 * chronological order, so `<` on the strings is exact. Doing it here rather
 * than in SQL means NO user-supplied value is ever interpolated into a
 * statement and no extra bound parameter is needed on the DELETE — the
 * existing bound DELETEs are untouched. (CLAUDE.md: every SQL value binds via
 * bind_param; the safest binding is the one you never have to write.)
 *
 * Framework-free apart from the one \mysqli read in userSyncNow(); no session
 * or superglobal access, so it is safe to require from the public API.
 *
 * @see appWeb/public_html/api.php  the three handlers that consume this
 * @see tests/php/test-user-sync-guard.php  the behavioural + structural lock
 * @link https://www.php.net/manual/en/function.array-slice.php
 * @link https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_now
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/* Direct access blocked so this can't be loaded as an endpoint. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('userSyncCap')) {
    /**
     * Apply a per-store size cap and REPORT whether it bit.
     *
     * ELI5: take the first N things, and remember whether we had to leave any
     * behind.
     *
     * The whole point is that `truncated` is computed from the PRE-slice count.
     * The #1649 bug was structurally possible because the handlers called
     * array_slice() inline and threw the original length away in the same
     * expression, so nothing downstream could tell a complete payload from a
     * clipped one. Returning both halves together makes the fact impossible to
     * lose.
     *
     * Order is preserved (array_slice keeps it) because "the first 50" must be
     * a stable, meaningful subset, not an arbitrary one.
     *
     * @param array $items The client's payload list (already JSON-decoded).
     * @param int   $cap   Max entries the store accepts.
     * @return array{items: array, truncated: bool}
     */
    function userSyncCap(array $items, int $cap): array
    {
        return [
            'items'     => array_slice($items, 0, $cap),
            'truncated' => count($items) > $cap,
        ];
    }
}

if (!function_exists('userSyncParseSince')) {
    /**
     * Validate a client-supplied `since` watermark, or reject it entirely.
     *
     * ELI5: only accept a date that looks exactly like the one we handed out;
     * anything else is treated as "the client didn't tell us".
     *
     * Accepts ONLY 'YYYY-MM-DD HH:MM:SS' or the ISO 'T'-separated spelling of
     * the same instant (some clients round-trip the value through a JS Date
     * and get the 'T' back). The 'T' form is normalised to a space so the
     * lexicographic comparison in userSyncDeletableIds() sees one canonical
     * shape — ' ' (0x20) sorts BELOW every digit while 'T' (0x54) sorts above
     * them, so mixing the two spellings would silently invert comparisons at
     * the date/time boundary.
     *
     * Everything else — null, '', garbage, a fractional-seconds variant, an
     * injection attempt — returns null, which makes the caller fall back to
     * today's absence-only deletion. Failing CLOSED to the legacy behaviour
     * (rather than, say, keeping everything) means a malformed value from a
     * buggy client can never be a silent no-op that leaves stale rows around.
     *
     * @param mixed $raw The `since` field straight off the decoded JSON body.
     * @return string|null Canonical 'YYYY-MM-DD HH:MM:SS', or null if unusable.
     */
    function userSyncParseSince($raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $s = trim($raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $s)) {
            return null;
        }
        /* Normalise the ISO 'T' separator to the MySQL space form. */
        return str_replace('T', ' ', $s);
    }
}

if (!function_exists('userSyncDeletableIds')) {
    /**
     * Decide which server rows a replace-sync is actually allowed to delete.
     *
     * ELI5: only delete something when the client sent its whole list AND the
     * thing is old enough that the client must have seen it before.
     *
     * The three refusals, in order of how much damage each prevents:
     *
     *   1. NOT REPLACE MODE → delete nothing. Merge is the additive first-login
     *      backfill; it has never deleted and must not start.
     *
     *   2. TRUNCATED PAYLOAD → delete nothing. This is guard (1) from the file
     *      header and the actual #1649 fix: an over-cap client's payload is not
     *      an authoritative statement about what exists, so its silences mean
     *      nothing. Degrading to a merge loses no data; the alternative loses
     *      it permanently.
     *
     *   3. ROW NEWER THAN `since` → keep it. Guard (2): the row was written
     *      after this client last absorbed a server response, so this client
     *      has literally never held it and cannot be asserting its deletion.
     *      This is the cross-device race — device B adds a set list while
     *      device A has a stale cache, then device A auto-syncs.
     *
     * Boundary: the comparison is strictly `<`, so a row whose timestamp EQUALS
     * `since` is KEPT. Second-resolution timestamps mean a row written in the
     * same second as the watermark is genuinely ambiguous, and the safe reading
     * of an ambiguous row is "don't destroy it".
     *
     * ACCEPTED TRADE-OFF — deletes are deliberately weaker than adds. Without
     * tombstones there is no way to distinguish "the user deleted this" from
     * "this client never saw it", so guard (3) can refuse a GENUINE deletion:
     * if another device re-syncs a row (refreshing its timestamp) after this
     * client's last absorb, this client's delete is declined and the row
     * reappears on its next reconcile. The user can simply delete it again —
     * once this client has absorbed a fresh watermark the delete sticks. That
     * is the correct way round: a deletion that needs repeating is an
     * annoyance, a collection that silently evaporates is #1649.
     *
     * Timestamps are cut to 19 chars before comparing so a driver that hands
     * back fractional seconds ('… 12:00:00.000000') still compares against the
     * 19-char watermark on equal terms rather than always reading as newer.
     *
     * @param array         $serverRows List of ['id' => string, 'ts' => string].
     * @param array         $payloadIds Ids the client actually sent this round.
     * @param string        $mode       'merge' | 'replace'.
     * @param bool          $truncated  Did the cap bite? (userSyncCap()['truncated'])
     * @param string|null   $since      Canonical watermark, or null for legacy behaviour.
     * @return string[] Ids safe to DELETE — possibly empty, never null.
     */
    function userSyncDeletableIds(
        array $serverRows,
        array $payloadIds,
        string $mode,
        bool $truncated,
        ?string $since
    ): array {
        /* Refusals 1 + 2 — nothing is deletable at all. */
        if ($mode !== 'replace' || $truncated) {
            return [];
        }

        /* O(1) membership instead of in_array()'s O(n) per row: a 500-favourite
           payload against 500 server rows is 250k comparisons otherwise. */
        $present = array_flip(array_map('strval', $payloadIds));

        $deletable = [];
        foreach ($serverRows as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id === '' || isset($present[$id])) {
                continue; /* Still in the client's list — obviously keep. */
            }
            if ($since !== null) {
                /* Refusal 3 — written after the client's last absorb. */
                $ts = substr((string)($row['ts'] ?? ''), 0, 19);
                if (!($ts < $since)) {
                    continue;
                }
            }
            $deletable[] = $id;
        }
        return $deletable;
    }
}

if (!function_exists('userSyncNow')) {
    /**
     * Mint the watermark the client will send back as `since` next time.
     *
     * ELI5: ask the database what time IT thinks it is, and hand that to the
     * client to quote back at us.
     *
     * It MUST be the database's clock, not PHP's time(). The row timestamps
     * this value is compared against are written by MySQL (DEFAULT
     * CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP), so any skew between the
     * PHP host and the DB host — or any difference in configured time zone —
     * would shift the watermark relative to the data and make guard (3) either
     * over-delete or under-delete. One clock, one frame of reference.
     *
     * Cut to 19 chars to match the format userSyncParseSince() accepts.
     *
     * KNOWN, BOUNDED CAVEAT (#1649). tblUserFavorites.CreatedAt and
     * tblUserCustomTags.CreatedAt are written by MySQL itself (DEFAULT
     * CURRENT_TIMESTAMP), so they share this watermark's frame exactly. But
     * tblUserSetlists.UpdatedAt is written by the APP — the setlists upsert
     * binds PHP's gmdate('c') — and neither PHP nor the mysqli session sets an
     * explicit time zone in this codebase. If the DB session runs ahead of UTC,
     * setlist rows can therefore read as slightly OLDER than a watermark minted
     * at the same instant, and guard (3) will let them be deleted.
     *
     * That failure mode is bounded to "exactly what happens today": deleting
     * absent rows regardless of age IS the pre-#1649 behaviour, so a skewed
     * clock degrades guard (3) to legacy and never does anything worse. Guard
     * (2), the truncation skip — which is the actual data-loss fix — does not
     * consult timestamps at all and is unaffected by any skew. Aligning the
     * setlist upsert onto the DB clock would tighten this, but it changes a
     * long-standing write path and belongs in its own change.
     *
     * mysqli runs under MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
     * (includes/db_mysql.php), so a failed query THROWS rather than returning
     * false — there is deliberately no `if (!$res)` dead branch here.
     *
     * @param \mysqli $db Live connection from getDbMysqli().
     * @return string 'YYYY-MM-DD HH:MM:SS' in the DB session's time zone.
     */
    function userSyncNow(\mysqli $db): string
    {
        return substr((string)$db->query('SELECT NOW()')->fetch_row()[0], 0, 19);
    }
}
