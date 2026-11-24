<?php

declare(strict_types=1);

/**
 * account_enrol_keys.php - the "automatic sign-in key": the one core
 * ============================================================================
 * Account/profile rewrite, PR 3 step H3 (epic #2180, plan
 * `.claude/account-profile-pr3-plan.md` sections 2 and 3, design file
 * `.claude/analysis/per-device-sign-in-design.md`).
 *
 * ELI5
 * ----
 * Every Apple device gets its own sign-in. A person who owns several still wants a new device to
 * sign itself in. So, when they choose, the first device saves one small secret ("the key") in
 * their iCloud Keychain. A second device can hand that key to the server and get its OWN sign-in
 * back, and nothing else: the key cannot open any page and cannot call any normal action. This file
 * is the ONLY place that makes, checks, lists or cancels those keys. Only a hash of a key is stored.
 *
 * Detailed
 * --------
 *  - The key is `ihek1_` plus 64 hex characters. The prefix means it can never pass the normal
 *    sign-in token check (`^Bearer\s+([a-f0-9]{64})$`, which allows only 64 hex characters). It
 *    lives in its OWN table (`tblAccountEnrolKeys`), which `getAuthenticatedUser()` and
 *    `apiTokenResolveBearerUser()` never read. A source check in the tests keeps it that way.
 *  - It lasts 180 days and the 180 days restart each time it is swapped. A person and platform can
 *    hold at most 5 live keys; making a sixth removes the oldest.
 *  - It is cancelled by "sign out everywhere", "turn off automatic sign-in", any password change or
 *    reset, and switching the account off (all through apiTokenRevokeAllForUser(), step H4), and it
 *    is deleted when the account is erased (the table's CASCADE link).
 *  - A key is shown ONCE, by the call that makes it. Only its sha256 is stored (never the key, never
 *    shown again, never accepted as a sign-in token). A listing returns dates and platform only.
 *  - Every table access is behind accountEnrolKeysReady(): the table is created by the foundation
 *    migration (B1), which is run by hand, and mysqli is STRICT (a missing table would throw).
 *  - Platforms: only 'apple' today (`ACCOUNT_ENROL_KEY_PLATFORMS`). Android and the web are
 *    deliberately left out until their own secure-storage story exists.
 *  - Transactions: makers and the redeem step expect the CALLER to hold a transaction (the redeem
 *    uses FOR UPDATE, which only means something inside one). device_signin.php does that.
 *
 * @see includes/device_signin.php   the actions that call this file (step H5)
 * @see includes/api_tokens.php      apiTokenRevokeAllForUser() calls accountEnrolKeyRevokeAll()
 * @see tests/php/test-device-signin-core.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'transaction_fatal.php';

/** The visible start of every key. A normal sign-in token can never begin with it. */
const ACCOUNT_ENROL_KEY_PREFIX = 'ihek1_';

/** Which kinds of app may hold a key. VARCHAR in the table, never an ENUM (rule #20). */
const ACCOUNT_ENROL_KEY_PLATFORMS = ['apple'];

/** How long a key lasts, counted from now each time it is swapped (days). */
const ACCOUNT_ENROL_KEY_LIFETIME_DAYS = 180;

/** The most live keys one person may hold per platform; making another removes the oldest. */
const ACCOUNT_ENROL_KEY_MAX_LIVE = 5;

/**
 * Does the key table exist on THIS install? Remembered per connection. An error while asking means
 * "not there" (not remembered across connections), except an error that ENDS a transaction, which
 * is passed up. (tests/php/test-account-foundation-gating.php requires this check before any SQL
 * that names the table.)
 */
function accountEnrolKeysReady(\mysqli $db): bool
{
    static $memo = [];
    $id = spl_object_id($db);
    if (array_key_exists($id, $memo)) {
        return $memo[$id];
    }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblAccountEnrolKeys' LIMIT 1"
        );
        $st->execute();
        $ready = $st->get_result()->fetch_row() !== null;
        $st->close();
        return $memo[$id] = $ready;
    } catch (\Throwable $_e) {
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        return false;
    }
}

/** PURE: is this text shaped like one of our keys? (`ihek1_` + 64 lowercase hex). */
function accountEnrolKeyShapeOk(mixed $raw): bool
{
    return is_string($raw) && preg_match('/^' . ACCOUNT_ENROL_KEY_PREFIX . '[a-f0-9]{64}$/', $raw) === 1;
}

/** PURE: the only thing ever stored for a key. */
function accountEnrolKeyHash(string $key): string
{
    return hash('sha256', $key);
}

/**
 * Make a key for a person and platform. Returns the RAW key once (never stored) and when it ends.
 *
 * Keeps at most ACCOUNT_ENROL_KEY_MAX_LIVE live keys per person and platform: the oldest go first.
 * Locks the person's row so two makers at once cannot both pass the count (needs a transaction to
 * mean anything; autocommit is harmless).
 *
 * @return array{key:string, expiresAt:string} `expiresAt` is UTC, `Y-m-d H:i:s`
 * @throws \InvalidArgumentException for a platform that may not hold keys
 */
function accountEnrolKeyMint(\mysqli $db, int $userId, string $platform): array
{
    if (!in_array($platform, ACCOUNT_ENROL_KEY_PLATFORMS, true)) {
        throw new \InvalidArgumentException('enrol key platform not supported');
    }
    if (!accountEnrolKeysReady($db)) {
        throw new \RuntimeException('enrol key table not ready');   /* callers check readiness first and answer 409; this is the last line of defence */
    }
    $lock = $db->prepare('SELECT Id FROM tblUsers WHERE Id = ? FOR UPDATE');
    $lock->bind_param('i', $userId);
    $lock->execute();
    $lock->get_result()->fetch_row();
    $lock->close();

    $now = gmdate('Y-m-d H:i:s');
    /* The oldest live keys beyond the cap (leaving room for the one about to be added). */
    $st = $db->prepare(
        'SELECT KeyHash FROM tblAccountEnrolKeys
          WHERE UserId = ? AND Platform = ? AND RevokedAt IS NULL AND ExpiresAt > ?
          ORDER BY CreatedAt ASC, KeyHash ASC'
    );
    $st->bind_param('iss', $userId, $platform, $now);
    $st->execute();
    $live = array_map(static fn($r) => (string)$r[0], $st->get_result()->fetch_all(MYSQLI_NUM));
    $st->close();
    $surplus = count($live) - (ACCOUNT_ENROL_KEY_MAX_LIVE - 1);
    if ($surplus > 0) {
        $drop = array_slice($live, 0, $surplus);
        $marks = implode(',', array_fill(0, count($drop), '?'));
        $del = $db->prepare('DELETE FROM tblAccountEnrolKeys WHERE UserId = ? AND KeyHash IN (' . $marks . ')');
        $del->bind_param('i' . str_repeat('s', count($drop)), $userId, ...$drop);
        $del->execute();
        $del->close();
    }

    $key       = ACCOUNT_ENROL_KEY_PREFIX . bin2hex(random_bytes(32));
    $hash      = accountEnrolKeyHash($key);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + ACCOUNT_ENROL_KEY_LIFETIME_DAYS * 86400);
    $ins = $db->prepare('INSERT INTO tblAccountEnrolKeys (KeyHash, UserId, Platform, CreatedAt, ExpiresAt) VALUES (?, ?, ?, ?, ?)');
    $ins->bind_param('sisss', $hash, $userId, $platform, $now, $expiresAt);
    $ins->execute();
    $ins->close();
    return ['key' => $key, 'expiresAt' => $expiresAt];
}

/**
 * Check a presented key and, if it is good, use it: returns the person's id, or null for ANY
 * reason it cannot be used (wrong shape, unknown, expired, cancelled, a different platform, or an
 * account that is switched off). One answer on purpose: a caller must not be able to tell which.
 *
 * Must run inside a transaction: the row is read with FOR UPDATE so two swaps at once cannot both
 * extend it. On success it records the use and restarts the 180 days.
 */
function accountEnrolKeyRedeemLocked(\mysqli $db, mixed $key, string $platform): ?int
{
    if (!accountEnrolKeysReady($db)) {
        return null;
    }
    if (!accountEnrolKeyShapeOk($key) || !in_array($platform, ACCOUNT_ENROL_KEY_PLATFORMS, true)) {
        return null;
    }
    $hash = accountEnrolKeyHash((string)$key);
    $now  = gmdate('Y-m-d H:i:s');
    $st = $db->prepare(
        'SELECT k.UserId
           FROM tblAccountEnrolKeys k
           JOIN tblUsers u ON u.Id = k.UserId
          WHERE k.KeyHash = ? AND k.Platform = ? AND k.RevokedAt IS NULL AND k.ExpiresAt > ? AND u.IsActive = 1
          LIMIT 1 FOR UPDATE'
    );
    $st->bind_param('sss', $hash, $platform, $now);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    if ($row === null) {
        return null;
    }
    $newEnd = gmdate('Y-m-d H:i:s', time() + ACCOUNT_ENROL_KEY_LIFETIME_DAYS * 86400);
    $up = $db->prepare('UPDATE tblAccountEnrolKeys SET LastUsedAt = ?, ExpiresAt = ? WHERE KeyHash = ?');
    $up->bind_param('sss', $now, $newEnd, $hash);
    $up->execute();
    $up->close();
    return (int)$row[0];
}

/**
 * Cancel a person's keys (all platforms, or one). Cancelled keys are kept for a while (so the
 * list can say "turned off") and deleted by the housekeeping sweep (step H7). Returns how many
 * live keys were cancelled. ONLY apiTokenRevokeAllForUser() and the "turn off automatic sign-in"
 * action call this.
 */
function accountEnrolKeyRevokeAll(\mysqli $db, int $userId, ?string $platform = null): int
{
    if (!accountEnrolKeysReady($db)) {
        return 0;
    }
    $now = gmdate('Y-m-d H:i:s');
    if ($platform === null) {
        $st = $db->prepare('UPDATE tblAccountEnrolKeys SET RevokedAt = ? WHERE UserId = ? AND RevokedAt IS NULL');
        $st->bind_param('si', $now, $userId);
    } else {
        $st = $db->prepare('UPDATE tblAccountEnrolKeys SET RevokedAt = ? WHERE UserId = ? AND Platform = ? AND RevokedAt IS NULL');
        $st->bind_param('sis', $now, $userId, $platform);
    }
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return max(0, $n);
}

/**
 * What the devices list may show about a person's live keys: platform and dates only. NEVER the
 * key and never its hash.
 *
 * @return list<array{platform:string, createdAt:string, lastUsedAt:?string, expiresAt:string}>
 */
function accountEnrolKeysSummary(\mysqli $db, int $userId): array
{
    if (!accountEnrolKeysReady($db)) {
        return [];
    }
    $now = gmdate('Y-m-d H:i:s');
    $st = $db->prepare(
        'SELECT Platform, CreatedAt, LastUsedAt, ExpiresAt FROM tblAccountEnrolKeys
          WHERE UserId = ? AND RevokedAt IS NULL AND ExpiresAt > ?
          ORDER BY CreatedAt DESC LIMIT 20'
    );
    $st->bind_param('is', $userId, $now);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[] = [
            'platform'   => (string)$r['Platform'],
            'createdAt'  => (string)$r['CreatedAt'],
            'lastUsedAt' => $r['LastUsedAt'] !== null ? (string)$r['LastUsedAt'] : null,
            'expiresAt'  => (string)$r['ExpiresAt'],
        ];
    }
    $st->close();
    return $out;
}

/**
 * Housekeeping: delete keys that ended (ran out, or were cancelled) more than 30 days ago. A live
 * key is never touched. Returns how many rows went. (The standalone cleanup script mirrors this
 * SQL because it cannot load this file; the device sign-in test checks they agree.)
 */
function accountEnrolKeysSweep(\mysqli $db): int
{
    if (!accountEnrolKeysReady($db)) {
        return 0;
    }
    $cut = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $st = $db->prepare('DELETE FROM tblAccountEnrolKeys WHERE ExpiresAt < ? OR (RevokedAt IS NOT NULL AND RevokedAt < ?)');
    $st->bind_param('ss', $cut, $cut);
    $st->execute();
    $n = max(0, $st->affected_rows);
    $st->close();
    return $n;
}
