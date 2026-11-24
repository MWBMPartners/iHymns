<?php

declare(strict_types=1);

/**
 * iHymns — API token device metadata + manage-devices helpers (#1409, Apple
 * Phase-2 PR-12)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: every "device" signed into iHymns (a phone, a TV, a browser tab) is
 * really just one row in `tblApiTokens`. This file lets that row ALSO
 * remember a friendly name / which platform / which app version / when it
 * was last used — so a "your devices" screen can list them and let someone
 * sign one out remotely, without ever showing the real secret bearer token
 * back to anyone (including the person asking).
 *
 * DETAILED: `tblApiTokens.DeviceName/Platform/AppVersion/LastSeenAt` shipped
 * live-dormant in #1511 (`appWeb/.sql/schema.sql` +
 * `migrate-apple-phase2-live-schema.php`) — this module is the FIRST
 * consumer. Every read/write here is
 * apiTokensDeviceMetaColumnsExist()-gated so an un-migrated docroot degrades
 * to "skip silently" (writers) or an empty result (readers) instead of a
 * STRICT-mode `mysqli_sql_exception` on a missing column (CLAUDE.md rule
 * #19/#28).
 *
 * DEVICE IDENTIFIER — WHY A HASH PREFIX, NEVER THE RAW TOKEN:
 * `tblApiTokens`'s PRIMARY KEY is the token's OWN sha256 hash (`Token`) —
 * there is no separate surrogate id column, and adding one would re-open a
 * table this codebase deliberately keeps as a one-pass shipped shape (rule
 * #20). A "device id" handed to the CLIENT is therefore a TRUNCATED PREFIX
 * of that hash (`API_TOKEN_DEVICE_ID_LENGTH` hex chars — 64 bits at the
 * default 16, astronomically collision-safe within one user's own handful
 * of tokens; even a same-user collision would only ever let them sign out
 * one of their OWN other devices, never cross a user boundary, because
 * every lookup this module does is ALSO scoped to `UserId = ?`). This is
 * NOT the raw bearer token: a hash cannot be reversed to recover it (SHA-256
 * preimage resistance), and presenting the hash back as if it WERE a bearer
 * credential fails outright — `getAuthenticatedUser()` re-hashes whatever is
 * presented in `Authorization: Bearer` and compares against the STORED
 * hash, so handing back the hash itself would need to be hashed AGAIN to
 * authenticate, which never matches. Never widen any response to the full
 * 64-char hash NOR to the raw token.
 *
 * @link https://www.php.net/manual/en/function.hash.php  hash('sha256', …)
 * @see includes/device_code.php  the sibling #1407 module this one mirrors
 *      the memoised-probe / dormancy conventions of
 */

/* #2137 review round 7 (the sixth independent review) — songRelocateIsTransactionFatal(),
   the ONE list of database errors that have already ended the caller's whole
   transaction (a deadlock, a lock wait timeout, MariaDB's 1020). Every catch in
   this file that a song save, the v2 editor, the works or songbooks admin page
   or an importer can reach from inside its transaction starts by passing those
   back to its caller (the full list is in DEV_NOTES.md). Loaded here, at the
   top, so that check can always be a catch's first line. It lives in
   transaction_fatal.php, which loads nothing else (song_relocate.php, its old
   home, also loads the database layer). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'transaction_fatal.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'account_enrol_keys.php';   /* PR 3 H4: the one revoke-everything door also cancels the keys */

/** How many leading hex chars of a token's sha256 identify it to the
 *  client — see the file header for the collision-safety reasoning. Chosen
 *  slightly above the existing `token_prefix` AUDIT-LOG convention (12
 *  chars, e.g. api.php's `logActivity(..., ['token_prefix' => substr(hash(...), 0, 12)])`
 *  calls) because THIS value doubles as a lookup key (device_signout), not
 *  just a human-readable debug label. */
const API_TOKEN_DEVICE_ID_LENGTH = 16;

/** Closed vocabulary for the OPTIONAL client-declared `platform` field —
 *  VARCHAR, app-validated here, never an ENUM (CLAUDE.md rule #20). Mirrors
 *  `ANALYTICS_INGEST_ALLOWED_PLATFORMS` (includes/analytics_ingest.php) so a
 *  device's `platform` value reads consistently with the analytics
 *  ingestion vocabulary used elsewhere in the app — not re-derived. */
const API_TOKEN_DEVICE_PLATFORMS = ['apple', 'android', 'web'];

/**
 * Memoised INFORMATION_SCHEMA existence probe — true only when ALL FOUR
 * #1409 columns are present. A partial apply (e.g. an interrupted migration
 * run) must never look "ready": this checks a COUNT(*) across all four
 * names rather than probing just one (mirrors the multi-object OR-probe
 * convention CLAUDE.md rule #19 describes for a migration registry probe,
 * applied here as an AND-across-columns check since every consumer in this
 * module needs the FULL set to behave correctly).
 */
function apiTokensDeviceMetaColumnsExist(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblApiTokens'
                AND COLUMN_NAME IN ('DeviceName', 'Platform', 'AppVersion', 'LastSeenAt')"
        );
        $st->execute();
        $count = (int)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
        $exists = ($count === 4);
    } catch (\Throwable $_e) {
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #2137 review round 7: never swallow an error that has ended the transaction */
        $exists = false; /* un-migrated install → treat as absent → callers degrade */
    }
    return $exists;
}

/* ======================================================================
 * THE ONE TOKEN MAKER (account/profile rewrite step C3, epic #2180)
 * ======================================================================
 *
 * ELI5
 * ----
 * Every way of signing in (password, new account, Sign in with Apple, a TV
 * pairing code, an emailed link, the admin-to-public bridge) ends by making
 * one new sign-in token. Six places each made one by hand, and only three of
 * them wrote the device's name, platform or app version. Now there is ONE
 * function that makes a sign-in token. Everything that records "how was this
 * sign-in made" and "which install is it" is added there once, so no route
 * can be forgotten, and a guard forbids any other place from inserting one.
 *
 * Detailed
 * --------
 *  - 32 random bytes shown as 64 hex characters (the raw token, returned once
 *    and never stored); only its sha256 is stored, as `tblApiTokens.Token`.
 *  - It expires 30 days after it is made (it slides forward on use: see
 *    getAuthenticatedUser()). The expiry time is handed back through the
 *    `$expiresAtTs` out-parameter so the caller can set the cookie to match.
 *  - `$meta` is the device details a client declared (`deviceName`, `platform`,
 *    `appVersion`; optionally `deviceInstallId`). `null` means "write no device
 *    details", for internal tokens and for the two routes whose caller writes
 *    them itself after its transaction. The web User-Agent auto-naming of
 *    apiTokenDeviceMetaStore() still applies whenever `$meta` is an array.
 *  - `$issuedVia` says how it was made (API_TOKEN_ISSUED_VIA). An unknown word
 *    is stored as NULL and logged, never allowed to fail a sign-in.
 *  - The new columns (IssuedVia, DeviceInstallId) come from the B1 migration and
 *    are only written when they exist, so an install that has not run it signs
 *    in exactly as before.
 *
 * @see tests/php/test-single-token-mint.php  the guard that bans any other INSERT
 */

/** How long a new sign-in token lives, in seconds (30 days). */
const API_TOKEN_LIFETIME_SECONDS = 30 * 86400;

/** Closed vocabulary for `tblApiTokens.IssuedVia`: how a sign-in was created. VARCHAR, never an ENUM (rule #20). */
const API_TOKEN_ISSUED_VIA = ['password', 'email', 'apple', 'device_code', 'cross_surface', 'enrol'];

/**
 * Do BOTH new sign-in columns (IssuedVia, DeviceInstallId) exist? Remembered per
 * connection. A partial apply never counts as ready (an AND across the columns).
 */
function apiTokensFoundationColumnsExist(\mysqli $db): bool
{
    static $memo = [];
    $key = spl_object_id($db);
    if (array_key_exists($key, $memo)) {
        return $memo[$key];
    }
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblApiTokens'
                AND COLUMN_NAME IN ('IssuedVia', 'DeviceInstallId')"
        );
        $st->execute();
        $count = (int)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
        return $memo[$key] = ($count === 2);
    } catch (\Throwable $_e) {
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        return $memo[$key] = false;
    }
}

/**
 * PURE: clean a client-declared install id, or null. 8 to 64 letters, digits
 * and dashes. Anything else is dropped (never a failed sign-in).
 */
function apiTokenCleanInstallId(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $t = trim($raw);
    return preg_match('/^[A-Za-z0-9\-]{8,64}$/', $t) ? $t : null;
}

/**
 * Make ONE sign-in token. THE only place that inserts into tblApiTokens.
 *
 * @param \mysqli     $db
 * @param int         $uid         the person being signed in (already verified)
 * @param ?array      $meta        device details, or null for none (see the header)
 * @param string      $issuedVia   one of API_TOKEN_ISSUED_VIA
 * @param int|null    $expiresAtTs OUT: the Unix time the token expires at
 * @param string|null $installId   the device's install id when the caller has it apart from `$meta`
 *                                 (the emailed-link route has no `$meta`; PR 3 step H1). Wins over
 *                                 `$meta['deviceInstallId']`. Pass it by NAME to skip `$expiresAtTs`:
 *                                 `apiTokenMint($db, $uid, null, 'email', installId: $id)`.
 * @return string the RAW token (64 hex characters). Returned once; only its hash is stored.
 */
function apiTokenMint(\mysqli $db, int $uid, ?array $meta, string $issuedVia, ?int &$expiresAtTs = null, ?string $installId = null): string
{
    $token       = bin2hex(random_bytes(32));
    $tokenHash   = hash('sha256', $token);
    $expiresAtTs = time() + API_TOKEN_LIFETIME_SECONDS;
    $expiresAt   = gmdate('Y-m-d H:i:s', $expiresAtTs);

    if (apiTokensFoundationColumnsExist($db)) {
        $via = in_array($issuedVia, API_TOKEN_ISSUED_VIA, true) ? $issuedVia : null;
        if ($via === null) {
            error_log('[api_tokens/mint] unknown issuedVia "' . $issuedVia . '" stored as NULL');
        }
        /* H1: an explicit install id wins; both go through the same cleaner, so a bad one is dropped, never a failed sign-in. */
        $installId = apiTokenCleanInstallId($installId ?? ($meta['deviceInstallId'] ?? null));
        $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt, IssuedVia, DeviceInstallId) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sisss', $tokenHash, $uid, $expiresAt, $via, $installId);
    } else {
        $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
        $stmt->bind_param('sis', $tokenHash, $uid, $expiresAt);
    }
    $stmt->execute();
    $stmt->close();

    /* H1: one device keeps one row. Only when the install id was recorded (so only when the columns exist). */
    if (isset($installId) && $installId !== null) {
        apiTokenReplaceSameInstall($db, $uid, $installId, $tokenHash);
    }

    if ($meta !== null) {
        apiTokenDeviceMetaStore(
            $db,
            $tokenHash,
            $meta['deviceName'] ?? null,
            $meta['platform'] ?? null,
            $meta['appVersion'] ?? null
        );
    }
    return $token;
}

/**
 * One device keeps one sign-in row (PR 3, step H1).
 *
 * ELI5: when an app signs in again on the same install (a second password sign-in, a swap of the
 * shared token for its own, an emailed link), the sign-in it made last time is no longer needed. This
 * deletes that older sign-in so the devices list shows ONE row per device, and hands the older
 * sign-in's notification registrations over to the new one so notifications keep arriving.
 *
 * Detailed:
 *  - Scoped by BOTH the person AND the install id. Another person who happens to hold the same install
 *    id is never touched (an install id is never trusted across people). A sign-in with NO install id
 *    (the shared older-app token) is never matched, because `DeviceInstallId = ?` cannot match NULL.
 *  - Runs inside the caller's transaction when there is one (the emailed-link route), so the new row
 *    and the removal of the old one stand or fall together.
 *  - The push rows are re-tied BEFORE the old rows go, and only when the push column exists.
 *  - No rotation on a timer: a lost reply would leave the device holding a dead token.
 *
 * @param string $newHash sha256 of the token just made (kept)
 * @return int how many older sign-ins were removed
 */
function apiTokenReplaceSameInstall(\mysqli $db, int $userId, string $installId, string $newHash): int
{
    if (!apiTokensFoundationColumnsExist($db)) {
        return 0;
    }
    $st = $db->prepare('SELECT Token FROM tblApiTokens WHERE UserId = ? AND DeviceInstallId = ? AND Token <> ?');
    $st->bind_param('iss', $userId, $installId, $newHash);
    $st->execute();
    $old = array_map(static fn($r) => (string)$r[0], $st->get_result()->fetch_all(MYSQLI_NUM));
    $st->close();
    if ($old === []) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($old), '?'));
    $types = str_repeat('s', count($old));
    /* Move the older sign-ins' notification registrations to the new sign-in first. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'apns.php';   /* apnsTokenHashReady() */
    if (apnsTokenHashReady($db)) {
        $mv = $db->prepare('UPDATE tblApnsTokens SET ApiTokenHash = ? WHERE UserId = ? AND ApiTokenHash IN (' . $marks . ')');
        $mv->bind_param('si' . $types, $newHash, $userId, ...$old);
        $mv->execute();
        $mv->close();
    }
    $del = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ? AND DeviceInstallId = ? AND Token IN (' . $marks . ')');
    $del->bind_param('is' . $types, $userId, $installId, ...$old);
    $del->execute();
    $removed = $del->affected_rows;
    $del->close();
    return $removed;
}

/* ======================================================================
 * THE ONE DOOR TO "END SIGN-INS" (PR 3 step H4, epic #2180)
 * ======================================================================
 *
 * ELI5
 * ----
 * Four places used to delete a person's sign-ins by hand (changing a password, resetting it,
 * switching the account off, and the new "sign out everywhere"). None of them knew about the
 * automatic sign-in keys or the notification registrations. Now there is ONE function for
 * "end everything" and ONE for "end these particular sign-ins", and a test forbids any other
 * place from doing it. The same door is used for signing one device out.
 *
 * Detailed
 * --------
 *  - A sign-in that has a recorded INSTALL ID is not deleted: it is ENDED (its expiry is set to
 *    a second ago, and the device's name, version and last-seen are blanked). The row stays as
 *    a small "this install was signed out" marker for API_TOKEN_ENDED_KEEP_DAYS days. That marker
 *    is what lets the server refuse to sign an install back in by its automatic key after it was
 *    signed out or its 30 days ran out (owner decision 2026-11-23, plan question B): see
 *    apiTokenInstallSignInEnded(). Nothing can sign in with an ended row (every check requires
 *    `ExpiresAt > now`), it is not listed on the devices page, and a NEW sign-in on the same install
 *    replaces it (apiTokenReplaceSameInstall()). A sign-in with no install id (the shared older-app
 *    sign-in, every old app) is deleted exactly as before.
 *  - Only the housekeeping sweep (api.php, step H7) removes ended markers, and only after they are
 *    API_TOKEN_ENDED_KEEP_DAYS old (the same length as an automatic sign-in key's life).
 */

/** How long an ended sign-in is remembered as "this install was signed out" (days). Same as a key's life. */
const API_TOKEN_ENDED_KEEP_DAYS = ACCOUNT_ENROL_KEY_LIFETIME_DAYS;

/** The expiry stamped on an ended sign-in: one second ago, so every `ExpiresAt > now` check refuses it. */
function apiTokenEndedStamp(): string
{
    return gmdate('Y-m-d H:i:s', time() - 1);
}

/**
 * End the person's sign-ins whose stored hash is in `$hashes` (a sign-out). Own-only: every
 * statement is filtered by the person's id. A sign-in with an install id is ended and kept as a
 * marker; one without is deleted. Ending a device's own sign-in also cancels the person's automatic
 * sign-in keys (see the comment at the UPDATE). Returns how many were ended or deleted.
 *
 * @param string[] $hashes full sha256 hashes (64 lowercase hex); anything else is ignored
 */
function apiTokenSignOutHashes(\mysqli $db, int $userId, array $hashes): int
{
    $hashes = array_values(array_unique(array_filter($hashes, static fn($h) => is_string($h) && preg_match('/^[a-f0-9]{64}$/', $h) === 1)));
    if ($hashes === []) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($hashes), '?'));
    $types = str_repeat('s', count($hashes));
    $n = 0;
    if (apiTokensFoundationColumnsExist($db)) {
        $now = gmdate('Y-m-d H:i:s');
        $ended = apiTokenEndedStamp();
        if (apiTokensDeviceMetaColumnsExist($db)) {
            $up = $db->prepare('UPDATE tblApiTokens SET ExpiresAt = ?, DeviceName = NULL, AppVersion = NULL, LastSeenAt = NULL
                                 WHERE UserId = ? AND DeviceInstallId IS NOT NULL AND ExpiresAt > ? AND Token IN (' . $marks . ')');
        } else {
            $up = $db->prepare('UPDATE tblApiTokens SET ExpiresAt = ?
                                 WHERE UserId = ? AND DeviceInstallId IS NOT NULL AND ExpiresAt > ? AND Token IN (' . $marks . ')');
        }
        $up->bind_param('sis' . $types, $ended, $userId, $now, ...$hashes);
        $up->execute();
        $endedDeviceSignIns = max(0, $up->affected_rows);
        $n += $endedDeviceSignIns;
        $up->close();
        /* Security review findings 3 and 4 (owner decision 2026-11-23): signing out a DEVICE's own sign-in also
           cancels the person's automatic sign-in keys. A key outlives the sign-out otherwise: a copy of it (a
           lost phone, a restored backup, a synced keychain) would still be able to make a fresh sign-in for the
           account. The person's other devices are NOT signed out; a device that is still signed in can simply
           make a new key for itself. Only a sign-in tied to a device counts (the shared older-app sign-in and a
           browser sign-in have no install id, so signing those out never touches the keys), which also keeps
           this dormant until a new app has made device sign-ins. Turning something off is never switched off,
           so this is not behind the per-site switch. Because every key that existed when the device was signed
           out is cancelled here, the "signed out" note the expiry sweep keeps cannot be outlived by one. */
        if ($endedDeviceSignIns > 0 && accountEnrolKeysReady($db)) {
            accountEnrolKeyRevokeAll($db, $userId, null);
        }
        /* Rows with an install id stay as ended markers (just ended above, or ended earlier); the rest go. */
        $del = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ? AND DeviceInstallId IS NULL AND Token IN (' . $marks . ')');
        $del->bind_param('i' . $types, $userId, ...$hashes);
        $del->execute();
        $n += $del->affected_rows;
        $del->close();
        return $n;
    }
    $del = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ? AND Token IN (' . $marks . ')');
    $del->bind_param('i' . $types, $userId, ...$hashes);
    $del->execute();
    $n = $del->affected_rows;
    $del->close();
    return $n;
}

/**
 * The housekeeping sweep for sign-in rows (PR 3, H5/H7). Returns how many rows it deleted.
 *
 * ELI5: expired sign-ins used to be deleted the moment they expired. A sign-in that belongs to one
 * device is now ALSO the small "this device was signed out / ran out" note that stops the device
 * signing itself back in with its automatic key (owner's rule, 2026-11-23). So those rows are kept
 * for API_TOKEN_ENDED_KEEP_DAYS days after they end, and only then deleted. Rows that belong to no
 * device (the shared older-app sign-in) go as soon as they expire, as before.
 *
 * Detailed: an install-bound row that ended at T is removed once T is older than the keep window;
 * the key's own life is the same length, so by then the key could not have been used anyway. The
 * old one-line sweep in cleanup.php (a standalone script that cannot load this file, rule #41)
 * mirrors this SQL; tests/php/test-device-signin-core.php checks the two agree.
 */
function apiTokenSweepExpired(\mysqli $db): int
{
    $now = gmdate('Y-m-d H:i:s');
    if (!apiTokensFoundationColumnsExist($db)) {
        $st = $db->prepare('DELETE FROM tblApiTokens WHERE ExpiresAt < ?');
        $st->bind_param('s', $now);
    } else {
        $oldest = gmdate('Y-m-d H:i:s', time() - API_TOKEN_ENDED_KEEP_DAYS * 86400);
        $st = $db->prepare('DELETE FROM tblApiTokens WHERE (DeviceInstallId IS NULL AND ExpiresAt < ?) OR (DeviceInstallId IS NOT NULL AND ExpiresAt < ?)');
        $st->bind_param('ss', $now, $oldest);
    }
    $st->execute();
    $n = max(0, $st->affected_rows);
    $st->close();
    return $n;
}

/**
 * Has this install had its sign-in ended (signed out, or run out after 30 days unused), with no
 * live sign-in since? True means: the person must sign in again on this install. A brand-new
 * install (no row at all) is false.
 *
 * @return bool true only when an ended marker exists for (person, install) and no live row does
 */
function apiTokenInstallSignInEnded(\mysqli $db, int $userId, string $installId): bool
{
    if (!apiTokensFoundationColumnsExist($db) || apiTokenCleanInstallId($installId) === null) {
        return false;
    }
    $now = gmdate('Y-m-d H:i:s');
    $st = $db->prepare(
        'SELECT SUM(ExpiresAt <= ?) AS ended, SUM(ExpiresAt > ?) AS live
           FROM tblApiTokens WHERE UserId = ? AND DeviceInstallId = ?'
    );
    $st->bind_param('ssis', $now, $now, $userId, $installId);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    return (int)($row[0] ?? 0) > 0 && (int)($row[1] ?? 0) === 0;
}

/**
 * END EVERYTHING for a person (PR 3 step H4). THE ONLY place that bulk-ends a person's sign-ins.
 *
 * Used by: "sign out everywhere", password change (keeping the current sign-in), password reset,
 * changing a password as an admin, and switching an account off. In ONE transaction it:
 *  1. ends every sign-in except `$keepHash` (see the section header: install-bound ones are kept as
 *     ended markers, others deleted);
 *  2. cancels the person's automatic sign-in keys (all platforms; never kept, even when a current
 *     sign-in is kept, because a key left alive would sign the other devices straight back in);
 *  3. removes the person's Apple push registrations: all of them when nothing is kept, otherwise only
 *     those tied to a DIFFERENT sign-in (a registration tied to no sign-in is left alone, because it
 *     may be the kept device's own). Web push subscriptions are not touched: they were never tied
 *     to a sign-in.
 * Each part is behind its own "is the table/column there" check, so an install that has not run the
 * foundation migration still ends its sign-ins exactly as before.
 *
 * @param ?string $keepHash sha256 of the one sign-in to keep (the caller's own), or null for none
 * @param string  $why      a short word saying why (e.g. 'password_change'); kept for callers' own audit lines
 * @return array{tokens:int, enrolKeys:int, push:int} how many of each were ended/cancelled/removed
 * @throws \Throwable the transaction is rolled back and the error passed on
 */
function apiTokenRevokeAllForUser(\mysqli $db, int $userId, ?string $keepHash, string $why): array
{
    $keep = ($keepHash !== null && preg_match('/^[a-f0-9]{64}$/', $keepHash) === 1) ? $keepHash : '';
    $out = ['tokens' => 0, 'enrolKeys' => 0, 'push' => 0];
    $db->begin_transaction();
    try {
        $now = gmdate('Y-m-d H:i:s');
        /* 1. the sign-ins. First count the live ones (the number the caller reports), then end them. */
        $c = $db->prepare('SELECT COUNT(*) FROM tblApiTokens WHERE UserId = ? AND ExpiresAt > ? AND Token <> ?');
        $c->bind_param('iss', $userId, $now, $keep);
        $c->execute();
        $out['tokens'] = (int)($c->get_result()->fetch_row()[0] ?? 0);
        $c->close();
        if (apiTokensFoundationColumnsExist($db)) {
            $ended = apiTokenEndedStamp();
            if (apiTokensDeviceMetaColumnsExist($db)) {
                $up = $db->prepare('UPDATE tblApiTokens SET ExpiresAt = ?, DeviceName = NULL, AppVersion = NULL, LastSeenAt = NULL
                                     WHERE UserId = ? AND DeviceInstallId IS NOT NULL AND ExpiresAt > ? AND Token <> ?');
            } else {
                $up = $db->prepare('UPDATE tblApiTokens SET ExpiresAt = ?
                                     WHERE UserId = ? AND DeviceInstallId IS NOT NULL AND ExpiresAt > ? AND Token <> ?');
            }
            $up->bind_param('siss', $ended, $userId, $now, $keep);
            $up->execute();
            $up->close();
            $del = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ? AND DeviceInstallId IS NULL AND Token <> ?');
            $del->bind_param('is', $userId, $keep);
            $del->execute();
            $del->close();
        } else {
            $del = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ? AND Token <> ?');
            $del->bind_param('is', $userId, $keep);
            $del->execute();
            $del->close();
        }
        /* 2. the automatic sign-in keys. */
        if (accountEnrolKeysReady($db)) {
            $out['enrolKeys'] = accountEnrolKeyRevokeAll($db, $userId, null);
        }
        /* 3. the Apple push registrations. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'apns.php';   /* apnsTokensTableExists(), apnsTokenHashReady() */
        if (apnsTokensTableExists($db)) {
            if ($keep === '') {
                $pd = $db->prepare('DELETE FROM tblApnsTokens WHERE UserId = ?');
                $pd->bind_param('i', $userId);
                $pd->execute();
                $out['push'] = max(0, $pd->affected_rows);
                $pd->close();
            } elseif (apnsTokenHashReady($db)) {
                $pd = $db->prepare('DELETE FROM tblApnsTokens WHERE UserId = ? AND ApiTokenHash IS NOT NULL AND ApiTokenHash <> ?');
                $pd->bind_param('is', $userId, $keep);
                $pd->execute();
                $out['push'] = max(0, $pd->affected_rows);
                $pd->close();
            }
        }
        $db->commit();
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_ignore) { /* best-effort */ }
        throw $e;
    }
    return $out;
}

/**
 * Should signing out THIS session also remove the person's Apple push
 * registrations? (account/profile rewrite A6, epic #2180)
 *
 * ELI5: a person can be signed in on a web browser AND on an iPhone at once.
 * Signing out of the browser used to delete the iPhone's notification
 * registration too, so the iPhone quietly stopped getting notifications. Now
 * the Apple push rows are removed only when the session being signed out could
 * be an Apple one.
 *
 * Detailed: the answer comes from the signed-out token's own `Platform`
 * (`apple | android | web`, NULL when nothing was declared). The shipped Apple
 * app declares no platform and sends a non-browser User-Agent, so its sessions
 * are stored with a NULL platform. So "unknown" must count as "might be Apple":
 * only a session KNOWN to be web or Android is left alone. Browser sign-ins are
 * always stored as 'web' (apiTokenWebDeviceFallback() fills it from the
 * User-Agent), so the browser fix still works; the cost is that a browser with
 * an unrecognised User-Agent signing out can drop an iPhone's registration,
 * which the iPhone re-registers the next time it opens. Step G4 later ties each
 * registration to its own session and replaces this rule. PURE: no database.
 */
function apiTokenSessionOwnsApplePush(?string $platform): bool
{
    return $platform !== 'web' && $platform !== 'android';
}

/**
 * Read the declared `Platform` of the session(s) whose token hash matches
 * `$tokenHashOrPrefix` BEFORE it is deleted (a deleted row cannot be asked).
 *
 * With `$prefix = false` the value is an exact token hash (sign-out of the
 * caller's own session); with `$prefix = true` it is the short device id used
 * by the device list, and only rows belonging to `$userId` are considered.
 * Returns null when the platform column is missing (un-migrated install), no
 * row matches, or the platform was never declared; callers then treat the
 * session as possibly Apple (see apiTokenSessionOwnsApplePush()). Every value is bound.
 */
function apiTokenSessionPlatform(\mysqli $db, int $userId, string $tokenHashOrPrefix, bool $prefix): ?string
{
    if (!apiTokensDeviceMetaColumnsExist($db)) {
        return null;
    }
    try {
        $sql = $prefix
            ? 'SELECT Platform FROM tblApiTokens WHERE UserId = ? AND Token LIKE CONCAT(?, "%") LIMIT 1'
            : 'SELECT Platform FROM tblApiTokens WHERE UserId = ? AND Token = ? LIMIT 1';
        $st = $db->prepare($sql);
        $st->bind_param('is', $userId, $tokenHashOrPrefix);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        $platform = is_string($row[0] ?? null) ? $row[0] : null;
        return $platform;
    } catch (\Throwable $_e) {
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        return null;
    }
}

/**
 * The full stored hashes of a person's sign-ins whose hash starts with a device id.
 *
 * ELI5: the devices list shows a short code for each sign-in (the first letters of its
 * stored hash). To sign one out cleanly, other things tied to that sign-in (its push
 * registration) need the FULL hash, and it must be read BEFORE the sign-in row is deleted.
 * Own-only: the person's id is in the WHERE. The prefix is checked to be lowercase hex.
 *
 * @return string[] full 64-character hashes (normally one)
 */
function apiTokenHashesForDevicePrefix(\mysqli $db, int $userId, string $devicePrefix): array
{
    if (!preg_match('/^[a-f0-9]{8,64}$/', $devicePrefix)) {
        return [];
    }
    $st = $db->prepare('SELECT Token FROM tblApiTokens WHERE UserId = ? AND Token LIKE CONCAT(?, "%") LIMIT 10');
    $st->bind_param('is', $userId, $devicePrefix);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_NUM);
    $st->close();
    return array_values(array_filter(array_map(static fn($r) => (string)$r[0], $rows), static fn($h) => preg_match('/^[a-f0-9]{64}$/', $h) === 1));
}

/**
 * Clean an OPTIONAL client-declared device-metadata triple from a decoded
 * sign-in request body. Every field is genuinely optional — an existing
 * native client that never sends them (every caller today) must sign in
 * byte-identically to before this addition, so malformed/absent input
 * degrades to null, NEVER a 400 on the sign-in itself. PURE (no DB, no
 * superglobals) — safe to unit test without a request.
 *
 * @param mixed $body The decoded JSON request body (or a non-array, e.g.
 *                     when json_decode() failed).
 * @return array{deviceName:?string,platform:?string,appVersion:?string,deviceInstallId:?string}
 */
function apiTokenDeviceMetaFromBody(mixed $body): array
{
    if (!is_array($body)) {
        $body = [];
    }

    $deviceName = apiTokenCleanDeviceName($body['deviceName'] ?? null);

    $platform = null;
    if (isset($body['platform']) && is_string($body['platform'])) {
        $candidate = strtolower(trim($body['platform']));
        $platform = in_array($candidate, API_TOKEN_DEVICE_PLATFORMS, true) ? $candidate : null;
    }

    $appVersion = null;
    if (isset($body['appVersion']) && is_string($body['appVersion'])) {
        $trimmed = trim($body['appVersion']);
        /* Loose version-string shape (digits/dots/dashes/letters, e.g.
           "1.4.2" or "1.4.2-beta.1") capped at the column's own VARCHAR(20)
           width — anything wildly outside that shape is dropped rather than
           truncated/garbled into the column. */
        $appVersion = ($trimmed !== '' && preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $trimmed)) ? $trimmed : null;
    }

    /* H1: the install id rides along with the other details. Anything malformed is dropped, never a failed sign-in. */
    return ['deviceName' => $deviceName, 'platform' => $platform, 'appVersion' => $appVersion,
            'deviceInstallId' => apiTokenCleanInstallId($body['deviceInstallId'] ?? null)];
}

/**
 * Trim + width-cap a client-supplied device name to the column's VARCHAR(120),
 * or null when it is absent/blank/non-string. PURE — the ONE place a device
 * name is normalised, shared by the sign-in body parser (above) and the
 * `device_rename` write path (api.php), so the two can never disagree on what
 * "" or an over-long name means (rule #35 — a mechanism, not two copies).
 *
 * @param mixed $raw
 * @return ?string A non-empty string ≤120 code points, or null (blank = clear).
 * @link https://www.php.net/manual/en/function.mb-substr.php
 */
function apiTokenCleanDeviceName(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $trimmed = trim($raw);
    return $trimmed !== '' ? mb_substr($trimmed, 0, 120) : null;
}

/**
 * Derive a FRIENDLY, display-only device label from a browser `User-Agent`
 * string — e.g. "Chrome on Windows", "Safari on iPhone" — or null when the UA
 * is not a recognised web browser (a native app with a bespoke UA, a bot, a
 * script). #1975: this is what stops a fresh WEB sign-in showing as "Unnamed
 * device" — the label is derived SERVER-SIDE from the request UA at token-issue
 * time, so it covers every web sign-in path in one place with no client change
 * and no schema column (the design chosen over a per-sign-in client field,
 * which would half-ship the moment one of the ~6 mint sites forgot it — rule
 * #33/#35). PURE (no superglobals) so it is a unit-testable truth table.
 *
 * Returns null — rather than guessing "web" — for an UNRECOGNISED UA, so a
 * native/other client that sent no `platform` is left exactly as it was
 * (Unnamed) instead of being mislabelled a browser; a native client that sends
 * its own name/platform overrides this entirely (see apiTokenWebDeviceFallback).
 *
 * The browser checks are ORDER-SENSITIVE: Chrome/Edge/Opera/Samsung UAs all
 * contain the literal "Safari/", and Edge/Opera contain "Chrome/", so the more
 * specific tokens are tested first. Display-only + escaped on render, so this
 * is never a security or auth decision — a wrong guess is only a cosmetic label.
 *
 * @param string $ua The raw `User-Agent` header.
 * @return ?string "Browser on OS", "Browser", or null when unrecognised.
 * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/User-Agent
 */
function apiTokenBrowserLabelFromUA(string $ua): ?string
{
    if ($ua === '') {
        return null;
    }

    /* OS family (best-effort; null when none matches). */
    $os = null;
    if (stripos($ua, 'iPhone') !== false)                                     { $os = 'iPhone'; }
    elseif (stripos($ua, 'iPad') !== false)                                   { $os = 'iPad'; }
    elseif (stripos($ua, 'Android') !== false)                                { $os = 'Android'; }
    elseif (stripos($ua, 'CrOS') !== false)                                   { $os = 'ChromeOS'; }
    elseif (stripos($ua, 'Windows NT') !== false)                             { $os = 'Windows'; }
    elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) { $os = 'Mac'; }
    elseif (stripos($ua, 'Linux') !== false)                                  { $os = 'Linux'; }

    /* Browser (order matters — see the doc-block). */
    $browser = null;
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'EdgiOS') !== false || stripos($ua, 'EdgA') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
        $browser = 'Opera';
    } elseif (stripos($ua, 'SamsungBrowser') !== false) {
        $browser = 'Samsung Internet';
    } elseif (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'CriOS') !== false || stripos($ua, 'Chrome/') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Safari/') !== false) {
        $browser = 'Safari';
    }

    if ($browser === null) {
        return null; /* not a recognised browser → caller must NOT claim 'web' */
    }
    return $os !== null ? ($browser . ' on ' . $os) : $browser;
}

/**
 * Apply the #1975 web auto-name fallback to a device-metadata pair. When the
 * client declared NO platform AND the request UA is a recognised browser, fill
 * in `platform = 'web'` and a friendly name derived from the UA; otherwise
 * return the inputs unchanged. PURE (UA passed in) so it is unit-testable.
 *
 * Precedence: an explicit client `platform` (native app, or a web client that
 * sent one) is ALWAYS respected — the fallback only ever fills a gap, never
 * overrides. An unrecognised UA leaves the pair untouched (stays Unnamed rather
 * than a wrong "web" guess).
 *
 * @return array{deviceName:?string,platform:?string}
 */
function apiTokenWebDeviceFallback(?string $deviceName, ?string $platform, string $ua): array
{
    if ($platform !== null) {
        return ['deviceName' => $deviceName, 'platform' => $platform];
    }
    $label = apiTokenBrowserLabelFromUA($ua);
    if ($label === null) {
        return ['deviceName' => $deviceName, 'platform' => null];
    }
    return ['deviceName' => $deviceName ?? $label, 'platform' => 'web'];
}

/**
 * Persist an OPTIONAL device-metadata triple against the token row a
 * sign-in flow (auth_login / auth_apple / completeEmailLogin / auth_register)
 * JUST inserted. No-ops silently — never throws, never blocks sign-in — both
 * when the columns aren't migrated yet AND when every field is null: a
 * sign-in must NEVER fail because of this best-effort metadata write, which
 * is why every caller invokes this AFTER its own token INSERT has already
 * committed (mirrors how `setAuthTokenCookie()` is called post-insert too).
 *
 * #1975: applies the web auto-name fallback (apiTokenWebDeviceFallback) reading
 * the request `User-Agent` FIRST, so a web sign-in that volunteered nothing is
 * still stored with `platform='web'` + a friendly name instead of falling
 * through to "Unnamed device". Doing it here — the ONE function every sign-in
 * mint funnels through — means no per-mint client change can be forgotten.
 *
 * @param string $tokenHash sha256 hex of the just-minted raw token — the
 *                           SAME value already written to
 *                           `tblApiTokens.Token` by the caller.
 */
function apiTokenDeviceMetaStore(\mysqli $db, string $tokenHash, ?string $deviceName, ?string $platform, ?string $appVersion): void
{
    /* #1975 — web auto-name fallback from the request UA, applied BEFORE the
       all-null short-circuit so a UA-derived web label is not discarded. */
    $ua = (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']))
        ? $_SERVER['HTTP_USER_AGENT'] : '';
    $fb = apiTokenWebDeviceFallback($deviceName, $platform, $ua);
    $deviceName = $fb['deviceName'];
    $platform   = $fb['platform'];

    if ($deviceName === null && $platform === null && $appVersion === null) {
        return;
    }
    if (!apiTokensDeviceMetaColumnsExist($db)) {
        return;
    }
    try {
        $stmt = $db->prepare(
            'UPDATE tblApiTokens SET DeviceName = ?, Platform = ?, AppVersion = ? WHERE Token = ?'
        );
        $stmt->bind_param('ssss', $deviceName, $platform, $appVersion, $tokenHash);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[api_tokens/deviceMetaStore] ' . $e->getMessage());
    }
}

/**
 * Truncated, display-and-lookup-safe identifier for a token row — see the
 * file header for why this is safe to hand to the client (never the raw
 * token, never the full hash).
 */
function apiTokenDeviceId(string $tokenHash): string
{
    return substr($tokenHash, 0, API_TOKEN_DEVICE_ID_LENGTH);
}

/**
 * The sign-in token a request put in its `Authorization: Bearer ...` header, or null. HEADER ONLY:
 * the `ihymns_auth` cookie is never looked at (PR 3 step H5).
 *
 * ELI5: a browser that is signed in carries a cookie automatically; a native app carries the token
 * in a header it adds itself. The actions that hand out a device's own sign-in must only ever answer
 * the second kind, so a web page (or an attacker's page riding the cookie) can never mint an app
 * sign-in. This is also the ONE place the "what a bearer token looks like" pattern lives
 * (`Bearer` + 64 hex characters), so apiTokenResolveBearerUser() and the device actions cannot
 * disagree about it. An automatic sign-in key (`ihek1_...`) can never match it.
 *
 * Reads `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION` (some CGI/FastCGI set-ups rename it).
 *
 * @return ?string the 64 lowercase-or-uppercase hex characters as sent, or null when absent/malformed
 */
function apiTokenBearerHeaderValue(): ?string
{
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $hdr, $m) === 1 ? $m[1] : null;
}

/**
 * Resolve an `Authorization: Bearer <token>` header (native curator apps —
 * `.claude/api-coverage-2026-08-28.md` §3 X1/X3, Batch 2) into a user row,
 * or null when no Bearer header is present or the token doesn't verify.
 *
 * ELI5: the /manage/ web pages prove who you are with a browser cookie; a
 * native app has no cookie jar, so it proves who it is with a bearer token
 * instead — this is the ONE function that checks what such a token means,
 * so every endpoint that wants to accept one calls this instead of
 * re-typing the query.
 *
 * DETAILED — THE ONE VERIFICATION CORE (CLAUDE.md rule #22): mirrors the
 * PUBLIC `api.php`'s `getAuthBearerToken()` regex (`/^Bearer\s+([a-f0-9]{64})$/i`,
 * header OR `REDIRECT_HTTP_AUTHORIZATION` for CGI/FastCGI setups that don't
 * forward `Authorization` under its own name) and `getAuthenticatedUser()`'s
 * verification query — SAME table (`tblApiTokens` JOINed to `tblUsers`), SAME
 * sha256-hash-then-compare, SAME `ExpiresAt > now` + `IsActive = 1` checks.
 * `api.php` is a dispatcher script that routes on `$_GET['action']` the
 * instant it is loaded, so nothing may `require` it as a library to reuse
 * that logic directly — this function is the shared extraction every OTHER
 * Bearer-capable endpoint (`manage/editor/api2.php`, the legacy
 * `manage/editor/api.php`, `manage/places-api.php`) delegates to instead of
 * each forking its own copy of the same SQL, which is exactly the "shared
 * module" the modularity rule (CLAUDE.md, top of file) requires once a
 * second consumer appears.
 *
 * Returned in the SAME lowercase-key shape `manage/includes/auth.php`'s
 * `getCurrentUser()` returns (`id`/`username`/`display_name`/`role`/
 * `email`), so a caller can drop the result straight into `$currentUser` and
 * every `hasRole()`/`userHasEntitlement()` call downstream keeps working
 * completely unchanged regardless of which auth path populated it.
 *
 * Deliberately STATELESS — does not touch `$_SESSION` (unlike the cookie
 * path's `adoptApiTokenSession()`) and does not slide the token's expiry
 * (unlike `getAuthenticatedUser()`'s own `slideAuthTokenExpiry()`, which
 * lives in the dispatcher for the same "not a library" reason above): a
 * Bearer request must never mutate ambient state a concurrent
 * cookie-authenticated request on the same server process could observe. A
 * Bearer client's token still gets its expiry slid whenever it calls the
 * public `api.php` (sign-in, song reads, …), which every such client
 * already does — so this is a scope-minimal omission, not a functional gap.
 *
 * @param \mysqli $db An already-open connection (getDbMysqli()).
 * @return array{id:int,username:string,display_name:?string,role:string,email:?string}|null
 * @link https://www.php.net/manual/en/function.hash-equals.php  (token compare pattern this mirrors — sha256 lookup, not a per-request hash_equals, matching getAuthenticatedUser()'s own approach)
 */
function apiTokenResolveBearerUser(\mysqli $db): ?array
{
    $token = apiTokenBearerHeaderValue();
    if ($token === null) {
        return null;
    }

    try {
        $hashedToken = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'SELECT u.Id AS id, u.Username AS username, u.DisplayName AS display_name,
                    u.Role AS role, u.Email AS email
               FROM tblApiTokens t
               JOIN tblUsers u ON u.Id = t.UserId
              WHERE t.Token = ? AND t.ExpiresAt > ? AND u.IsActive = 1
              LIMIT 1'
        );
        $stmt->bind_param('ss', $hashedToken, $now);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[api_tokens/resolveBearerUser] ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    return $row;
}
