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

if (!function_exists('userSyncParseTimestamp')) {
    /**
     * Validate ANY client-supplied second-resolution timestamp, or reject it.
     *
     * ELI5: only accept a date written exactly the way we write them;
     * anything else is treated as "the client didn't tell us".
     *
     * Accepts ONLY 'YYYY-MM-DD HH:MM:SS' or the ISO 'T'-separated spelling of
     * the same instant (some clients round-trip the value through a JS Date
     * and get the 'T' back). The 'T' form is normalised to a space so every
     * lexicographic comparison in this file sees one canonical shape — ' '
     * (0x20) sorts BELOW every digit while 'T' (0x54) sorts above them, so
     * mixing the two spellings would silently invert comparisons at the
     * date/time boundary.
     *
     * Everything else — null, '', garbage, a fractional-seconds variant, an
     * injection attempt — returns null, and each caller documents what its own
     * null means. Note this is a SHAPE check, not a calendar check: MySQL
     * itself rejects an impossible date such as '2026-02-30 00:00:00' under
     * STRICT, so the column is the authority on validity and duplicating a
     * calendar parse here would only create a second opinion that could drift.
     *
     * This is the ONE parser (#1661). `userSyncParseSince()` below is a named
     * alias kept because the WATERMARK has its own meaning and its own
     * fallback story; both spellings must stay byte-identical, so they share
     * an implementation rather than a comment asking someone to keep two
     * regexes in step (CLAUDE.md rule #35).
     *
     * @param mixed $raw A timestamp field straight off the decoded JSON body.
     * @return string|null Canonical 'YYYY-MM-DD HH:MM:SS', or null if unusable.
     */
    function userSyncParseTimestamp($raw): ?string
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

if (!function_exists('userSyncParseSince')) {
    /**
     * Validate a client-supplied `since` watermark, or reject it entirely.
     *
     * ELI5: only accept a date that looks exactly like the one we handed out.
     *
     * A rejected watermark returns null, which makes the caller fall back to
     * today's absence-only deletion. Failing CLOSED to the legacy behaviour
     * (rather than, say, keeping everything) means a malformed value from a
     * buggy client can never be a silent no-op that leaves stale rows around.
     *
     * @param mixed $raw The `since` field straight off the decoded JSON body.
     * @return string|null Canonical 'YYYY-MM-DD HH:MM:SS', or null if unusable.
     */
    function userSyncParseSince($raw): ?string
    {
        return userSyncParseTimestamp($raw);
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
     * ---------------------------------------------------------------------
     * REFUSAL 0 (#1661) — $explicitProtocol RETIRES absence-based deletion
     * ---------------------------------------------------------------------
     *
     * ELI5: once a client can say "delete THIS one" out loud, we stop
     * guessing from what it didn't mention.
     *
     * A protocol-2 client (one that sends a `deleted` list — see
     * userSyncExplicitProtocol()) states its deletions explicitly and they are
     * recorded as tombstones. For such a client, silence carries NO meaning at
     * all, so every "accepted trade-off" below simply evaporates: there is no
     * inference left to get wrong. Passing true here is therefore the
     * strongest possible position, not a weaker one.
     *
     * LEGACY CLIENTS ARE UNTOUCHED. The native Apple/Android apps never send
     * `deleted`, so they pass false and keep exactly today's #1649-guarded
     * behaviour — guard (2) plus the `since` watermark. Breaking native sync
     * to fix a web-client bug would be a far worse regression than the one
     * being fixed, which is why this parameter exists rather than the absence
     * rule simply being deleted.
     *
     * @param array         $serverRows List of ['id' => string, 'ts' => string].
     * @param array         $payloadIds Ids the client actually sent this round.
     * @param string        $mode       'merge' | 'replace'.
     * @param bool          $truncated  Did the cap bite? (userSyncCap()['truncated'])
     * @param string|null   $since      Canonical watermark, or null for legacy behaviour.
     * @param bool          $explicitProtocol Client sent an explicit `deleted` list (#1661).
     * @return string[] Ids safe to DELETE — possibly empty, never null.
     */
    function userSyncDeletableIds(
        array $serverRows,
        array $payloadIds,
        string $mode,
        bool $truncated,
        ?string $since,
        bool $explicitProtocol = false
    ): array {
        /* Refusals 0 + 1 + 2 — nothing is deletable at all. */
        if ($explicitProtocol || $mode !== 'replace' || $truncated) {
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
     * It MUST be the database's clock, not PHP's time(). Most of the row
     * timestamps this value is compared against are written by MySQL itself, so
     * any skew between the PHP host and the DB host — or any difference in
     * configured time zone — would shift the watermark relative to the data and
     * make guard (3) either over-delete or under-delete. One clock, one frame
     * of reference.
     *
     * "Most", not "all" — see the caveat below. tblUserSetlists.UpdatedAt is the
     * exception, and it is worth being precise about WHY, because the column
     * declaration alone is misleading: schema.sql declares it
     * `ON UPDATE CURRENT_TIMESTAMP`, which reads like MySQL owns it. It does
     * not. The setlists upsert supplies an explicit value for that column (and
     * repeats it via `UpdatedAt = VALUES(UpdatedAt)` in the duplicate-key
     * branch), and an explicitly-supplied value suppresses ON UPDATE entirely.
     * So the declaration never fires for this write path. Do not "simplify" the
     * caveat below on the strength of reading the schema.
     *
     * Cut to 19 chars to match the format userSyncParseSince() accepts.
     *
     * ONE CLOCK (#1675, was the #1649 "bounded caveat" — now CLOSED).
     * tblUserFavorites.CreatedAt and tblUserCustomTags.CreatedAt are written by
     * MySQL itself (DEFAULT CURRENT_TIMESTAMP), so they share this watermark's
     * frame exactly. tblUserSetlists.UpdatedAt is written by the APP — and as
     * of #1675 the setlists upsert (api.php user_setlists_sync: $now =
     * userSyncNow($db)) and the collaborative/token write core
     * (setlistCollabPerformUpdate: UpdatedAt = NOW()) BOTH stamp from this same
     * DB session clock, so a stored setlist row and this watermark now live in
     * ONE frame. This is the alignment the caveat here used to reserve "for its
     * own change": without it, guard (3) and the #1675 per-row conflict guard
     * would be wrong by the DB session's UTC offset — on a behind-UTC session,
     * refusing every push forever (a bricked sync).
     *
     * TRANSITION: rows written BEFORE #1675 carry the old PHP-UTC stamp. On a
     * behind-UTC session those read up to |offset| in the future relative to
     * this clock; the #1675 conflict guard neutralises exactly that with a
     * future-stamp clamp (userSyncRowNewerThanWatermark's $slackSeconds — a
     * stamp materially past the DB's own now is treated as frame-poisoned and
     * degrades to today's last-writer-wins, never a refusal loop). Ahead-of-UTC
     * sessions degrade the other way (fewer conflicts). Either way the failure
     * mode of a skewed install is "the guard under-fires", never "sync bricks".
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

/* ==========================================================================
 * #1661 — SETLIST TOMBSTONES, NO CAP, AND OPTIONAL EXPIRY  (sync protocol 2)
 * ==========================================================================
 *
 * ELI5: instead of the server guessing "you didn't mention it, so you must
 * have thrown it away", the app now says out loud "I deleted THIS one", and
 * the server writes that down permanently. A set list can also be given an
 * optional use-by date; when it passes, the server deletes it in exactly the
 * same way, so there is only ever ONE idea of "gone".
 *
 * WHY THIS EXISTS. #1649 fixed the *symptom* — a 50-item cap silently ate the
 * tail of a big collection — by refusing to delete on a truncated payload.
 * But the underlying confusion survived: "absent from the payload" was still
 * doing the work of "the user deleted it", and those are different facts. The
 * owner's decision (#1661) removes the cap entirely and makes deletion
 * explicit, so truncation and deletion can never be conflated again:
 *
 *   - NO CAP. Over-size payloads are REJECTED with 413, never silently
 *     clipped. A rejection is loud, retryable and visible; a truncation is
 *     invisible and permanent. (userSyncMaxBodyBytes / userSyncBodyExceeds.)
 *   - EXPLICIT DELETES. `deleted: [id, …]` writes a tombstone row and removes
 *     the setlist. The PRESENCE of the key — even as `[]` — is what marks a
 *     protocol-2 client (userSyncExplicitProtocol).
 *   - ANTI-RESURRECTION. A tombstoned id is dead FOREVER; the upsert loop
 *     skips it. "Re-create" mints a fresh client id.
 *   - EXPIRY CONVERGES ON THE SAME MECHANISM. An expired setlist becomes a
 *     tombstone with Reason='expired', so one propagation path serves both.
 *
 * WHY ANTI-RESURRECTION IS DETERMINISTIC AND NOT A TIMESTAMP COMPARISON.
 * The obvious alternative — "resurrect if the incoming updatedAt is newer
 * than DeletedAt" — reads reasonable and is a trap. `updatedAt` on an
 * incoming setlist is a CLIENT clock: unset, wrong, deliberately altered, or
 * simply in another time zone. A device whose clock runs fast would resurrect
 * everything it had ever deleted; one running slow could never re-create
 * anything. A deterministic rule has no such failure mode, and its cost is
 * bounded and explainable: a genuine cross-device "delete then re-create
 * under the identical id" is refused. Both real paths avoid it — undo before
 * a sync never reaches the server at all, and the UI mints a new id on
 * create — so the case we consciously spend is a hand-crafted API call.
 *
 * ⚠️ EVERYTHING BELOW IS SCHEMA-GATED. The three docroots share ONE MySQL and
 * migrations are WEB-RUN, never auto-applied, so this code must run correctly
 * on an install where the migration has not been applied yet. mysqli runs
 * under MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so touching an absent
 * table THROWS rather than returning false (the #1228 white-screen class).
 * userSyncTombstonesReady() / userSyncExpiryReady() are the gates; an
 * un-migrated install degrades to "no tombstones, no expiry", which is
 * byte-identical to today's behaviour.
 *
 * @see appWeb/.sql/migrate-setlist-tombstones.php  the ONE migration
 * @link https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
 * ========================================================================== */

if (!function_exists('userSyncMaxBodyBytes')) {
    /**
     * The whole-request byte ceiling for an uncapped sync payload.
     *
     * ELI5: there is no limit on how many set lists you may have, but there
     * IS a limit on how big one message can be — and if you go over, we say
     * so instead of quietly cutting bits off.
     *
     * 4 MiB is deliberately generous: a set list is a name plus an array of
     * small song stubs, so 4 MiB is on the order of tens of thousands of
     * entries — far beyond any real collection, while still bounding what a
     * single request can make the server decode into PHP arrays. The number
     * is a FUNCTION rather than a const so the file stays double-include-safe
     * (the whole file guards on function_exists, and a bare `const` at file
     * scope would fatal on a second include).
     *
     * @return int Maximum accepted request-body size in bytes.
     */
    function userSyncMaxBodyBytes(): int
    {
        return 4 * 1024 * 1024;
    }
}

if (!function_exists('userSyncBodyExceeds')) {
    /**
     * Is this raw request body over the ceiling?
     *
     * ELI5: measure the message before we try to understand it.
     *
     * Measured on the RAW body, before json_decode(), because the point is to
     * bound the work done — deciding after decoding would already have paid
     * the cost. strlen() (not mb_strlen) is correct: this is a byte budget,
     * and the transport counts bytes.
     *
     * @param string   $rawBody The unparsed php://input contents.
     * @param int|null $max     Override for tests; defaults to userSyncMaxBodyBytes().
     * @return bool True when the caller must respond 413 instead of processing.
     */
    function userSyncBodyExceeds(string $rawBody, ?int $max = null): bool
    {
        return strlen($rawBody) > ($max ?? userSyncMaxBodyBytes());
    }
}

if (!function_exists('userSyncTombstoneReasons')) {
    /**
     * The tombstone `Reason` vocabulary — the ONE app-level allow-list.
     *
     * ELI5: the short list of ways a set list can end up gone.
     *
     * The column is VARCHAR, NOT ENUM (CLAUDE.md rule #20): a moderation-style
     * vocabulary that can grow must never make "add a value" mean "ship an
     * ALTER". Adding a reason is ONE entry here.
     *
     *   user    — the person deleted it on a device.
     *   expired — its optional ExpiresAt passed and the server converted it.
     *   admin   — reserved for a curator/support-initiated removal; no writer
     *             yet, declared so the vocabulary is complete at the point the
     *             storage was designed rather than after a second migration.
     *
     * @return string[] Accepted Reason values.
     */
    function userSyncTombstoneReasons(): array
    {
        return ['user', 'expired', 'admin'];
    }
}

if (!function_exists('userSyncTombstoneReason')) {
    /**
     * Coerce any input to a valid tombstone Reason.
     *
     * ELI5: if we don't recognise the word, just call it a normal delete.
     *
     * Falls back to 'user' rather than rejecting, because the REASON is
     * metadata — refusing the whole deletion over an unrecognised label would
     * turn a cosmetic problem into the data-loss-adjacent one of a delete that
     * silently didn't happen.
     *
     * @param mixed $raw Candidate reason.
     * @return string One of userSyncTombstoneReasons().
     */
    function userSyncTombstoneReason($raw): string
    {
        return (is_string($raw) && in_array($raw, userSyncTombstoneReasons(), true))
            ? $raw
            : 'user';
    }
}

if (!function_exists('userSyncExplicitProtocol')) {
    /**
     * Does this request body come from a protocol-2 (explicit-delete) client?
     *
     * ELI5: if the app mentioned a "deleted" list at all — even an empty one —
     * it knows how to tell us about deletions, so we stop inferring them.
     *
     * ⚠️ PRESENCE OF THE KEY, NOT A VERSION NUMBER. This is deliberate and is
     * the single most load-bearing decision in the protocol:
     *
     *   - A version field is a THIRD thing to keep in step with the two
     *     behaviours it selects; the key that carries the data is inherently
     *     in step with itself (CLAUDE.md rule #35 — a mechanism, not a note).
     *   - `deleted: []` is the common case (most syncs delete nothing) and it
     *     MUST still count, otherwise a client would flip between protocols
     *     depending on whether the user happened to delete something — the
     *     absence rule would silently switch back on for the quiet syncs,
     *     which is precisely the bug.
     *   - A client cannot accidentally opt in: no existing caller sends the
     *     key. The native apps' encoders emit a fixed set of fields.
     *
     * A key present but NOT an array (null, a string, a number from a buggy
     * client) still counts as protocol 2, and the delete list sanitises to
     * empty. That fails toward "delete nothing", which is the #1649-safe
     * direction; reading it as "legacy" would instead re-enable absence-based
     * deletion for a client that had just proved it is confused.
     *
     * @param mixed $body The decoded JSON request body.
     * @return bool True when absence-based deletion must be retired.
     */
    function userSyncExplicitProtocol($body): bool
    {
        return is_array($body) && array_key_exists('deleted', $body);
    }
}

if (!function_exists('userSyncSanitiseId')) {
    /**
     * Normalise one client-generated setlist id to the stored shape.
     *
     * ELI5: strip out anything that isn't a letter, digit, underscore or
     * hyphen — and if nothing usable is left, say so.
     *
     * The charset is EXACTLY what the setlists upsert has always applied, and
     * this function is where it now lives so the upsert and the tombstone
     * writer cannot drift onto two different ideas of the same id — a
     * mismatch there would mean tombstoning an id that no row uses, i.e. a
     * delete that appears to succeed and does nothing.
     *
     * @param mixed $raw The client's `id` value.
     * @return string The sanitised id, or '' when nothing usable remains.
     */
    function userSyncSanitiseId($raw): string
    {
        if (!is_string($raw) && !is_int($raw)) {
            return '';
        }
        return (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$raw);
    }
}

if (!function_exists('userSyncSanitiseDeletedIds')) {
    /**
     * Sanitise the `deleted` list into ids that can safely be tombstoned.
     *
     * ELI5: clean up the list of "please delete these", drop anything
     * nonsensical, and don't process the same id twice.
     *
     * THE LENGTH RULE. Ids longer than the column width are DROPPED, not
     * truncated. tblUserSetlists.SetlistId and the tombstone's SetlistId are
     * both VARCHAR(100), and MySQL runs STRICT here — so an over-length id
     * cannot possibly name an existing row (the insert that would have
     * created it would have thrown). Truncating instead would mint a tombstone
     * for a DIFFERENT id, which could kill an innocent setlist; dropping is
     * the only choice that cannot destroy the wrong thing. Inserting it
     * unmodified would throw and 500 the whole sync.
     *
     * De-duplication keeps the tombstone write loop honest about how many
     * rows it touched, and costs one array_flip.
     *
     * @param mixed $raw    The `deleted` field off the decoded body.
     * @param int   $maxLen Column width; ids longer than this are dropped.
     * @return string[] Zero or more distinct, storable ids.
     */
    function userSyncSanitiseDeletedIds($raw, int $maxLen = 100): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $candidate) {
            $id = userSyncSanitiseId($candidate);
            if ($id === '' || strlen($id) > $maxLen) {
                continue;
            }
            $out[$id] = true;   /* key-set de-dupes without an in_array scan */
        }
        return array_keys($out);
    }
}

if (!function_exists('userSyncExpiredIds')) {
    /**
     * Which of these setlists have passed their optional expiry?
     *
     * ELI5: anything whose use-by date has arrived is finished.
     *
     * PURE by design — the server still holds the authority (the caller passes
     * a timestamp read from the DATABASE clock, never PHP's), but the decision
     * itself is a function of two strings and is therefore testable with no
     * MySQL. That mirrors userSyncDeletableIds(), and for the same reason the
     * comparison is a plain string comparison: both sides are fixed-width
     * zero-padded 'YYYY-MM-DD HH:MM:SS', a format whose lexicographic order IS
     * its chronological order.
     *
     * BOUNDARY — `<=`, unlike the `<` in userSyncDeletableIds(). The asymmetry
     * is intentional and worth stating because a reader WILL notice it:
     *
     *   - The watermark comparison is about AMBIGUITY. A row written in the
     *     same second as the watermark may or may not have been seen, and the
     *     safe reading of "maybe" is "don't destroy".
     *   - Expiry is about an INSTRUCTION. The user said "this ends at 18:00";
     *     at 18:00:00 it has ended. There is nothing ambiguous to protect.
     *
     * A NULL / absent / unparseable ExpiresAt means "never expires" and is
     * skipped. Failing that way round matters: a malformed value must never
     * be read as "expired now", because that would delete a setlist the user
     * never asked to lose.
     *
     * @param array  $rows   List of ['id' => string, 'expiresAt' => ?string].
     * @param string $nowUtc DB-clock UTC reading, 'YYYY-MM-DD HH:MM:SS'.
     * @return string[] Ids whose expiry has passed.
     */
    function userSyncExpiredIds(array $rows, string $nowUtc): array
    {
        $expired = [];
        foreach ($rows as $row) {
            $id = userSyncSanitiseId($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $expiresAt = userSyncParseTimestamp($row['expiresAt'] ?? null);
            if ($expiresAt === null) {
                continue;   /* NULL / malformed = never expires */
            }
            if ($expiresAt <= $nowUtc) {
                $expired[] = $id;
            }
        }
        return $expired;
    }
}

if (!function_exists('userSyncUtcNow')) {
    /**
     * The database's UTC clock, as a 19-char string.
     *
     * ELI5: ask the database what time it is in UTC.
     *
     * WHY UTC_TIMESTAMP() AND NOT NOW(). These two clocks serve different
     * comparisons and must not be swapped:
     *
     *   - userSyncNow() (NOW()) is the WATERMARK clock. It is compared against
     *     row timestamps in the same session's frame, so the session time zone
     *     cancels out on both sides.
     *   - This is the EXPIRY clock. `ExpiresAt` is an absolute instant the
     *     CLIENT chose and converted to UTC before sending, so it has no
     *     session frame to cancel against. Comparing it to NOW() would make an
     *     expiry fire early or late by exactly the DB session's UTC offset —
     *     a silent, install-dependent hour or thirteen. UTC_TIMESTAMP() is
     *     invariant, so UTC-vs-UTC is exact on every install.
     *
     * @param \mysqli $db Live connection from getDbMysqli().
     * @return string 'YYYY-MM-DD HH:MM:SS' in UTC.
     */
    function userSyncUtcNow(\mysqli $db): string
    {
        return substr((string)$db->query('SELECT UTC_TIMESTAMP()')->fetch_row()[0], 0, 19);
    }
}

if (!function_exists('userSyncTombstonesReady')) {
    /**
     * Is the tombstone table present on THIS install?
     *
     * ELI5: check the new table exists before we try to use it.
     *
     * Migrations are web-run via /manage/setup-database and the three docroots
     * share one MySQL, so "it is in schema.sql" is not the same statement as
     * "it exists here". Under MYSQLI_REPORT_STRICT an absent table THROWS, so
     * an ungated read would white-screen setlist sync on an un-migrated
     * install — the #1228 class. Memoised per request because the answer
     * cannot change mid-request and the sync path asks several times.
     *
     * @param \mysqli $db Live connection.
     * @return bool True when tblUserSetlistTombstones exists.
     */
    function userSyncTombstonesReady(\mysqli $db): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserSetlistTombstones' LIMIT 1"
        );
        $stmt->execute();
        $ready = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ready;
    }
}

if (!function_exists('userSyncExpiryReady')) {
    /**
     * Is the optional-expiry column present on THIS install?
     *
     * ELI5: check the new "use-by date" column exists before we read it.
     *
     * Separate from userSyncTombstonesReady() even though ONE migration adds
     * both: the registry probe for that migration is a multi-object OR-probe
     * precisely because a partial apply is possible (a dropped connection
     * between the CREATE TABLE and the ALTER). Two independent gates degrade
     * gracefully in that state instead of assuming one implies the other.
     *
     * @param \mysqli $db Live connection.
     * @return bool True when tblUserSetlists.ExpiresAt exists.
     */
    function userSyncExpiryReady(\mysqli $db): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserSetlists'
                AND COLUMN_NAME = 'ExpiresAt' LIMIT 1"
        );
        $stmt->execute();
        $ready = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ready;
    }
}

if (!function_exists('userSyncTombstoneWrite')) {
    /**
     * Record one or more setlist ids as permanently deleted.
     *
     * ELI5: write down that these set lists are gone, so no device can bring
     * them back by accident.
     *
     * A tombstone is written EVEN WHEN NO ROW EXISTED. That is not sloppiness:
     * device A's delete may be racing device B's create of the same id, and
     * the tombstone is what makes the outcome deterministic regardless of
     * which request the database sees first — without it, a create landing
     * microseconds after a delete would survive on the server while device A
     * shows it gone.
     *
     * THE NO-OP ON DUPLICATE. `Reason = Reason` means "insert if absent, leave
     * the existing row completely alone", so the FIRST deletion's timestamp
     * and reason are preserved (an 'expired' tombstone is not relabelled
     * 'user' by a later client re-send). INSERT IGNORE would express the same
     * intent more briefly and is deliberately NOT used: it downgrades genuine
     * errors — including a data-too-long violation — to warnings, and a silent
     * truncation is the exact failure class this whole feature exists to
     * remove.
     *
     * @param \mysqli  $db        Live connection.
     * @param int      $userId    Owner.
     * @param string[] $ids       Sanitised setlist ids (see userSyncSanitiseDeletedIds()).
     * @param string   $deletedAt DB NOW() frame — must be comparable with `since`.
     * @param string   $reason    One of userSyncTombstoneReasons(); coerced if not.
     * @return int Number of ids processed (not rows changed).
     */
    function userSyncTombstoneWrite(
        \mysqli $db,
        int $userId,
        array $ids,
        string $deletedAt,
        string $reason
    ): int {
        if ($ids === [] || !userSyncTombstonesReady($db)) {
            return 0;
        }
        $reason = userSyncTombstoneReason($reason);

        $stmt = $db->prepare(
            'INSERT INTO tblUserSetlistTombstones (UserId, SetlistId, DeletedAt, Reason)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE Reason = Reason'
        );
        $setlistId = '';
        $stmt->bind_param('isss', $userId, $setlistId, $deletedAt, $reason);
        $n = 0;
        foreach ($ids as $setlistId) {
            $stmt->execute();
            $n++;
        }
        $stmt->close();
        return $n;
    }
}

if (!function_exists('userSyncTombstonedIds')) {
    /**
     * The set of ids this user may never resurrect, as an O(1) lookup.
     *
     * ELI5: the list of set lists that are gone for good, in a shape that is
     * fast to check one id against.
     *
     * Returned as a KEY set (id => true) rather than a list because the caller
     * asks "is this one dead?" once per incoming setlist; a list would make
     * that an in_array() scan per item.
     *
     * @param \mysqli $db     Live connection.
     * @param int     $userId Owner.
     * @return array<string,bool> Tombstoned ids as keys.
     */
    function userSyncTombstonedIds(\mysqli $db, int $userId): array
    {
        if (!userSyncTombstonesReady($db)) {
            return [];
        }
        $stmt = $db->prepare('SELECT SetlistId FROM tblUserSetlistTombstones WHERE UserId = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $set = [];
        foreach ($rows as $row) {
            $set[(string)$row['SetlistId']] = true;
        }
        return $set;
    }
}

if (!function_exists('userSyncTombstoneList')) {
    /**
     * The tombstones to hand back to the client so it can prune its cache.
     *
     * ELI5: tell the app which set lists have been deleted elsewhere, so it
     * can remove its own copies.
     *
     * FILTERED WITH `>=`, NOT `>`. The watermark says "I have absorbed
     * everything up to this instant". Timestamps here are second-resolution,
     * so a tombstone written in the SAME second as the watermark is
     * ambiguous — and the two possible mistakes are not symmetric:
     *
     *   - Re-sending a tombstone the client already applied costs nothing:
     *     pruning an id that is already absent is a no-op, and tombstones are
     *     permanent so the instruction can never become wrong.
     *   - MISSING one strands a deleted set list in that device's cache
     *     forever. It would be re-pushed on every sync, silently refused by
     *     anti-resurrection, and never appear in a response again — so
     *     nothing would ever tell the client to drop it.
     *
     * Choosing the harmless mistake is the same instinct as the `<` boundary
     * in userSyncDeletableIds(), pointed the other way because here the
     * destructive outcome is on the opposite side.
     *
     * BOUNDED GROWTH: one row per deletion per user, so this tracks how much
     * the user has deleted over time, not how much they own. A prune of
     * tombstones older than (say) a year is a one-line DELETE if it is ever
     * needed — deliberately NOT built, because a tombstone removed while some
     * rarely-opened device still holds the setlist would resurrect it.
     *
     * @param \mysqli     $db     Live connection.
     * @param int         $userId Owner.
     * @param string|null $since  Client watermark, or null for the full set.
     * @return array<int,array{id:string,deletedAt:string,reason:string}>
     */
    function userSyncTombstoneList(\mysqli $db, int $userId, ?string $since): array
    {
        if (!userSyncTombstonesReady($db)) {
            return [];
        }
        if ($since !== null) {
            $stmt = $db->prepare(
                'SELECT SetlistId, DeletedAt, Reason FROM tblUserSetlistTombstones
                  WHERE UserId = ? AND DeletedAt >= ? ORDER BY DeletedAt ASC'
            );
            $stmt->bind_param('is', $userId, $since);
        } else {
            $stmt = $db->prepare(
                'SELECT SetlistId, DeletedAt, Reason FROM tblUserSetlistTombstones
                  WHERE UserId = ? ORDER BY DeletedAt ASC'
            );
            $stmt->bind_param('i', $userId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(static fn(array $r): array => [
            'id'        => (string)$r['SetlistId'],
            'deletedAt' => (string)$r['DeletedAt'],
            'reason'    => (string)$r['Reason'],
        ], $rows);
    }
}

if (!function_exists('userSyncExpireSetlists')) {
    /**
     * Convert this user's expired setlists into tombstones. LAZY + SERVER-SIDE.
     *
     * ELI5: any set list whose use-by date has passed is deleted now, and
     * written down as deleted so every device removes its copy too.
     *
     * WHY LAZY (at read time) RATHER THAN A CRON / EVENT. There is no
     * scheduler in this deployment, and adding one for this would be a lot of
     * moving parts to make a set list disappear at a precise second nobody is
     * watching. Converting on the next read means a setlist is never SERVED
     * after its expiry, which is the property that actually matters; the row
     * merely lingers unread until then.
     *
     * WHY THE SERVER OWNS THE CONVERSION. Clients render the upcoming expiry
     * from `expiresAt` for display, but a device clock is not evidence — and
     * if each device decided independently, two devices with different clocks
     * would disagree about whether a set list still exists mid-service. One
     * authority, one answer.
     *
     * The DELETE re-states the expiry condition rather than trusting the ids
     * read a moment ago, so a concurrent request that cleared or extended
     * ExpiresAt in between cannot have its setlist deleted out from under it;
     * the tombstone is then written only for ids the DELETE actually removed.
     *
     * Gated on BOTH halves of the migration: with no ExpiresAt column there is
     * nothing to expire, and with no tombstone table an expiry could not be
     * propagated — deleting the row anyway would be an unannounced
     * disappearance, so the whole operation stands down instead.
     *
     * @param \mysqli $db     Live connection.
     * @param int     $userId Owner.
     * @return string[] Ids actually expired this call (usually empty).
     */
    function userSyncExpireSetlists(\mysqli $db, int $userId): array
    {
        if (!userSyncExpiryReady($db) || !userSyncTombstonesReady($db)) {
            return [];
        }

        $nowUtc = userSyncUtcNow($db);

        $stmt = $db->prepare(
            'SELECT SetlistId, ExpiresAt FROM tblUserSetlists
              WHERE UserId = ? AND ExpiresAt IS NOT NULL'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $candidates = userSyncExpiredIds(
            array_map(
                static fn(array $r): array => [
                    'id'        => $r['SetlistId'],
                    'expiresAt' => $r['ExpiresAt'],
                ],
                $rows
            ),
            $nowUtc
        );
        if ($candidates === []) {
            return [];
        }

        /* NOW() frame, not UTC: DeletedAt is compared against the `since`
           watermark, which userSyncNow() mints from NOW(). */
        $deletedAt = userSyncNow($db);

        $del = $db->prepare(
            'DELETE FROM tblUserSetlists
              WHERE UserId = ? AND SetlistId = ? AND ExpiresAt IS NOT NULL AND ExpiresAt <= ?'
        );
        $setlistId = '';
        $del->bind_param('iss', $userId, $setlistId, $nowUtc);
        $expired = [];
        foreach ($candidates as $setlistId) {
            $del->execute();
            if ($del->affected_rows > 0) {
                $expired[] = $setlistId;
            }
        }
        $del->close();

        userSyncTombstoneWrite($db, $userId, $expired, $deletedAt, 'expired');
        return $expired;
    }
}
