<?php

declare(strict_types=1);

/**
 * profile_sync.php - the new per-person items table, and "has it been created here?"
 * ============================================================================
 * Account/profile rewrite (epic #2180). PR 2a added the readiness check; PR 2b
 * (step 2b-2) adds the sync core in this same file.
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
 * THE SYNC CORE (PR 2b, step 2b-2)
 * --------------------------------
 * ELI5: each small personal choice (a theme, a chord style, a key for one song) is
 * stored as ONE row, found by its section and key (never by its position in a list,
 * rule #51). A device sends only the choices it changed. The server checks each
 * against the one registry, keeps the ones that pass, hands back the ones that do
 * not with a reason, and stamps each accepted change with the next number in a
 * per-person counter (the "version"). A device reads back "everything above the
 * number I last saw". The ORDER changes REACH the server decides who wins; a device
 * clock is never looked at.
 *
 * Detailed:
 *  - profileSyncPlan() is PURE: given the rows now stored, the changes sent, the
 *    current highest version and the live count per section, it decides what to
 *    write and what to refuse. A test calls it directly (truth table).
 *  - profileSyncApplyLocked() does the I/O inside a transaction that already holds
 *    the person's row lock (the SAME lock the settings writer takes, A1): three
 *    reads (highest version, section counts, the touched rows), then at most 200
 *    upserts. profileSyncApply() wraps it with begin / lock / commit.
 *  - profileSyncPull() reads rows above a cursor in version order, 500 at a time.
 *  - Internal bookkeeping rows live in the section `_meta` with Version 0, so a
 *    pull (which asks for Version above the cursor) can never return them.
 *  - Every function that runs SQL on the table asks profileItemsReady() first.
 *
 * @see includes/profile_sync_gate.php  the on/off switch
 * @see tests/php/test-account-foundation-gating.php  the guard that every use of the table sits behind this check
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'profile_sync_gate.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'profile_registry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_sync.php'; /* userSyncLockPerson(): the per-person row lock (A1) */
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

/* ======================================================================
 * THE SYNC CORE (PR 2b, step 2b-2)
 * ====================================================================== */

/** The largest stored value for ONE item, in bytes of its JSON text. A bigger one is refused, never cut. */
const PROFILE_SYNC_MAX_VALUE_BYTES = 4096;

/** The most changes one request may carry. The endpoint answers 413 above this; the core refuses to run above it. */
const PROFILE_SYNC_MAX_CHANGES = 200;

/** How many changes one read returns. A reply with more waiting says `more: true`. */
const PROFILE_SYNC_PAGE_SIZE = 500;

/** The section for the server's own bookkeeping rows. Not in the registry, so a client can never write it. */
const PROFILE_SYNC_META_SECTION = '_meta';

/** The reasons a single change can be refused. Defined once; a client branches on these, never on a sentence (rule #35). */
const PROFILE_SYNC_REJECT_REASONS = ['unknown_key', 'device_only', 'not_profile_item', 'invalid_value', 'invalid_item_key', 'value_too_large', 'cap_reached'];

/** The platform names a writer may declare (the registry's list); anything else is stored as nothing. */
function profileSyncCleanPlatform(?string $platform): ?string
{
    return ($platform !== null && in_array($platform, PROFILE_PLATFORMS, true)) ? $platform : null;
}

/**
 * PURE: put a decoded JSON value into one canonical shape, so two values that mean the same compare equal.
 *
 * ELI5: MariaDB keeps JSON as plain text, so the same value can come back with its keys in another order or a
 * whole number written as 1.0. Before asking "did this change?" we tidy both sides the same way.
 *
 * @return mixed
 */
function profileSyncCanonical(mixed $v): mixed
{
    if (is_array($v)) {
        if (array_is_list($v)) {
            return array_map('profileSyncCanonical', $v);
        }
        ksort($v, SORT_STRING);
        return array_map('profileSyncCanonical', $v);
    }
    if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1.0e15) {
        return (int)$v;
    }
    return $v;
}

/** PURE: do two decoded values mean the same thing? (Order of object keys and 1 versus 1.0 do not matter.) */
function profileSyncSameValue(mixed $a, mixed $b): bool
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    return json_encode(profileSyncCanonical($a), $flags) === json_encode(profileSyncCanonical($b), $flags);
}

/**
 * PURE: the JSON text to store for a value. ONE encoder, so the size that is checked is the size that is stored.
 * An empty object must be stored as `{}` (PHP cannot tell an empty list from an empty object), by the entry's type.
 *
 * @param array<string,mixed> $entry the registry entry (for a map, the map entry: its item decides)
 */
function profileSyncEncodeValue(array $entry, mixed $value): string
{
    $spec = ($entry['type'] ?? '') === 'map' ? (array)($entry['item'] ?? []) : $entry;
    if ($value === [] && in_array($spec['type'] ?? '', ['json', 'map'], true)) {
        return '{}';
    }
    return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * The key used inside the plan for a (section, key) pair. A NUL byte cannot appear in either, so two pairs never share one.
 * Lower-cased because the table's text comparison ignores case (utf8mb4_unicode_ci): a row stored for `MP-1` IS the row for
 * `mp-1`, so the plan must see them as the same pair or it would count and compare the wrong thing.
 */
function profileSyncPairKey(string $section, string $key): string
{
    return strtolower($section) . "\0" . strtolower($key);
}

/**
 * PURE: decide what happens to each change. No database, no clock, no superglobals.
 *
 * ELI5: go through the changes in the order they arrived. Refuse the ones that break a rule (and say which rule),
 * skip the ones that would change nothing, and give each remaining one the next version number.
 *
 * @param array<string,array{deleted:bool,value:mixed}> $rows     what is stored now for the touched pairs, keyed by profileSyncPairKey()
 * @param list<mixed>                                  $changes  each `{section, key, op: 'set'|'delete', value}`
 * @param int                                          $maxVersion the highest version this person has now
 * @param ?string                                      $platform who is writing (web, apple, ...)
 * @param array<string,int>                            $counts   live (not deleted) item count per section, for the map sections touched
 * @return array{writes:list<array{section:string,key:string,json:?string,deleted:bool,version:int,platform:?string}>,rejected:list<array{section:string,key:string,reason:string}>,applied:int,unchanged:int,maxVersion:int}
 */
function profileSyncPlan(array $rows, array $changes, int $maxVersion, ?string $platform, array $counts): array
{
    $platform = profileSyncCleanPlatform($platform);
    $state    = $rows;      /* what each pair looks like as the batch is applied, in order */
    $writes   = [];         /* keyed by pair: a later change to the same pair replaces the earlier one */
    $rejected = [];
    $applied  = 0;
    $unchanged = 0;
    $version  = $maxVersion;

    foreach ($changes as $c) {
        $section = is_array($c) && is_string($c['section'] ?? null) ? $c['section'] : '';
        $key     = is_array($c) && is_string($c['key'] ?? null) ? $c['key'] : '';
        $echo = ['section' => mb_substr($section, 0, 40), 'key' => mb_substr($key, 0, 191)];
        $reject = static function (string $reason) use (&$rejected, $echo): void {
            $rejected[] = $echo + ['reason' => $reason];
        };
        $op = is_array($c) ? ($c['op'] ?? null) : null;

        if ($section === '' || $key === '') { $reject('unknown_key'); continue; }
        $entry = profileRegistryEntry($section, $key);
        if ($entry === null) { $reject('unknown_key'); continue; }
        if (($entry['scope'] ?? '') === 'device') { $reject('device_only'); continue; }
        if (($entry['sync'] ?? '') !== 'profile_sync' || ($entry['storage'] ?? '') !== 'profile_items') { $reject('not_profile_item'); continue; }
        $isMap = ($entry['type'] ?? '') === 'map';
        if ($isMap && !profileRegistryMapItemKeyValid($entry, $key)) { $reject('invalid_item_key'); continue; }
        if ($op !== 'set' && $op !== 'delete') { $reject('invalid_value'); continue; }

        $pair = profileSyncPairKey($section, $key);
        $now  = $state[$pair] ?? null;                       /* null = no row at all */
        $isLive = $now !== null && !$now['deleted'];

        if ($op === 'set') {
            $value = $c['value'] ?? null;
            if ($value === null) { $reject('invalid_value'); continue; }
            $json = profileSyncEncodeValue($entry, $value);
            if (strlen($json) > PROFILE_SYNC_MAX_VALUE_BYTES) { $reject('value_too_large'); continue; }
            if (!profileRegistryValidate($section, $key, $value)) { $reject('invalid_value'); continue; }
            if ($isLive && profileSyncSameValue($now['value'], $value)) { $unchanged++; continue; }   /* nothing changed: no write, no new version */
            if ($isMap && !$isLive) {
                /* A NEW item in a map section: refuse when the section is full. Nothing is ever evicted. */
                if (($counts[$section] ?? 0) >= (int)($entry['maxItems'] ?? 0)) { $reject('cap_reached'); continue; }
                $counts[$section] = ($counts[$section] ?? 0) + 1;
            }
            $version++;
            $state[$pair] = ['deleted' => false, 'value' => $value];
            $writes[$pair] = ['section' => $section, 'key' => $key, 'json' => $json, 'deleted' => false, 'version' => $version, 'platform' => $platform];
            $applied++;
            continue;
        }

        /* delete */
        if (!$isLive) { $unchanged++; continue; }             /* nothing there, or already a marker: nothing to do */
        if ($isMap) { $counts[$section] = max(0, ($counts[$section] ?? 0) - 1); }
        $version++;
        $state[$pair] = ['deleted' => true, 'value' => null];
        $writes[$pair] = ['section' => $section, 'key' => $key, 'json' => null, 'deleted' => true, 'version' => $version, 'platform' => $platform];
        $applied++;
    }
    return ['writes' => array_values($writes), 'rejected' => $rejected, 'applied' => $applied, 'unchanged' => $unchanged, 'maxVersion' => $version];
}

/** A clean list of `?` marks for an IN list (rule #5: every value is bound, never joined into the text). */
function profileSyncMarks(int $n): string
{
    return implode(',', array_fill(0, max(1, $n), '?'));
}

if (!function_exists('profileSyncApplyLocked')) {
    /**
     * Store a batch of changes. The caller ALREADY holds the person's row lock inside an open transaction.
     *
     * ELI5: read where the counter is and what is stored for the touched items, let the pure plan decide, then write the
     * accepted changes. Three reads, then at most 200 writes.
     *
     * @param \mysqli $db       live connection, inside a transaction, person row locked
     * @param int     $userId   the person
     * @param list<mixed> $changes at most PROFILE_SYNC_MAX_CHANGES entries
     * @param ?string $platform who is writing
     * @param ?string $channel  which site accepted it (production|beta|alpha); looked up when null
     * @return array{ready:bool,applied:int,unchanged:int,rejected:list<array{section:string,key:string,reason:string}>,maxVersion:int,writes:list<array<string,mixed>>}
     */
    function profileSyncApplyLocked(\mysqli $db, int $userId, array $changes, ?string $platform, ?string $channel = null): array
    {
        if (!profileItemsReady($db)) {
            return ['ready' => false, 'applied' => 0, 'unchanged' => 0, 'rejected' => [], 'maxVersion' => 0, 'writes' => []];
        }
        if (count($changes) > PROFILE_SYNC_MAX_CHANGES) {
            throw new \InvalidArgumentException('profileSyncApplyLocked: too many changes for one batch (the endpoint must refuse first)');
        }
        $changes = array_values($changes);

        /* Read 1: the highest version this person has. (The lock makes it safe to add one.) */
        $stmt = $db->prepare('SELECT COALESCE(MAX(Version), 0) FROM tblUserProfileItems WHERE UserId = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $maxVersion = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();

        /* Which (section, key) pairs and which map sections do the changes touch? Only well-formed strings can match a row. */
        $pairs = []; $mapSections = [];
        foreach ($changes as $c) {
            if (!is_array($c) || !is_string($c['section'] ?? null) || !is_string($c['key'] ?? null)) { continue; }
            $pairs[profileSyncPairKey($c['section'], $c['key'])] = [$c['section'], $c['key']];
            $e = profileRegistryEntry($c['section'], $c['key']);
            if ($e !== null && ($e['type'] ?? '') === 'map') { $mapSections[$c['section']] = true; }
        }

        /* Read 2: the live count of each touched map section. */
        $counts = [];
        if ($mapSections !== []) {
            $names = array_keys($mapSections);
            $stmt = $db->prepare(
                'SELECT Section, COUNT(*) FROM tblUserProfileItems
                  WHERE UserId = ? AND DeletedAt IS NULL AND Section IN (' . profileSyncMarks(count($names)) . ') GROUP BY Section'
            );
            $args = array_merge([$userId], $names);
            $stmt->bind_param('i' . str_repeat('s', count($names)), ...$args);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as $r) { $counts[(string)$r[0]] = (int)$r[1]; }
            $stmt->close();
        }

        /* Read 3: what is stored now for the touched pairs. */
        $rows = [];
        if ($pairs !== []) {
            $stmt = $db->prepare(
                'SELECT Section, ItemKey, ValueJson, DeletedAt FROM tblUserProfileItems
                  WHERE UserId = ? AND (Section, ItemKey) IN (' . implode(',', array_fill(0, count($pairs), '(?,?)')) . ')'
            );
            $args = [$userId];
            foreach ($pairs as $p) { $args[] = $p[0]; $args[] = $p[1]; }
            $stmt->bind_param('i' . str_repeat('ss', count($pairs)), ...$args);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as $r) {
                $rows[profileSyncPairKey((string)$r[0], (string)$r[1])] = [
                    'deleted' => $r[3] !== null,
                    'value'   => ($r[2] !== null && $r[3] === null) ? json_decode((string)$r[2], true) : null,
                ];
            }
            $stmt->close();
        }

        $plan = profileSyncPlan($rows, $changes, $maxVersion, $platform, $counts);

        if ($plan['writes'] !== []) {
            if ($channel === null && function_exists('ihymns_environment')) { $channel = ihymns_environment(); }
            $channel = ($channel !== null && in_array($channel, ['production', 'beta', 'alpha'], true)) ? $channel : null;
            $up = $db->prepare(
                'INSERT INTO tblUserProfileItems
                    (UserId, Section, ItemKey, ValueJson, Version, UpdatedAt, DeletedAt, WriterChannel, WriterPlatform)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3), CASE WHEN ? = 1 THEN UTC_TIMESTAMP(3) ELSE NULL END, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    ValueJson = VALUES(ValueJson), Version = VALUES(Version), UpdatedAt = VALUES(UpdatedAt),
                    DeletedAt = VALUES(DeletedAt), WriterChannel = VALUES(WriterChannel), WriterPlatform = VALUES(WriterPlatform)'
            );
            $section = ''; $key = ''; $json = null; $version = 0; $isDel = 0; $wplat = null;
            $up->bind_param('isssiiss', $userId, $section, $key, $json, $version, $isDel, $channel, $wplat);
            foreach ($plan['writes'] as $w) {
                $section = $w['section']; $key = $w['key']; $json = $w['json']; $version = $w['version'];
                $isDel = $w['deleted'] ? 1 : 0; $wplat = $w['platform'];
                $up->execute();
            }
            $up->close();
        }
        return ['ready' => true, 'applied' => $plan['applied'], 'unchanged' => $plan['unchanged'], 'rejected' => $plan['rejected'],
                'maxVersion' => $plan['maxVersion'], 'writes' => $plan['writes']];
    }
}

if (!function_exists('profileSyncApply')) {
    /**
     * Store a batch of changes for one person: begin, lock the person's row, apply, commit.
     *
     * ELI5: the one door for saving small personal items. Anyone else changing this person's
     * data at the same moment waits their turn, so no two writers ever pick the same version number.
     * Any database error rolls everything back and is re-thrown, so a failed save is never reported as saved.
     *
     * @param list<mixed> $changes at most PROFILE_SYNC_MAX_CHANGES entries
     * @return array{ready:bool,applied:int,unchanged:int,rejected:list<array{section:string,key:string,reason:string}>,maxVersion:int,writes:list<array<string,mixed>>}
     * @throws \Throwable
     */
    function profileSyncApply(\mysqli $db, int $userId, array $changes, ?string $platform, ?string $channel = null): array
    {
        if (!profileItemsReady($db)) {
            return ['ready' => false, 'applied' => 0, 'unchanged' => 0, 'rejected' => [], 'maxVersion' => 0, 'writes' => []];
        }
        $db->begin_transaction();
        try {
            userSyncLockPerson($db, $userId);
            $result = profileSyncApplyLocked($db, $userId, $changes, $platform, $channel);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $_r) { /* the connection is already gone */ }
            throw $e;
        }
    }
}

if (!function_exists('profileSyncPull')) {
    /**
     * Read the changes above a cursor, in version order, one page at a time.
     *
     * ELI5: "give me everything that happened after number N". The reply carries the number to ask
     * for next time, and `more: true` when there is another page waiting. A deleted item comes back as
     * `deleted: true` with no value, so another device learns it was removed. Bookkeeping rows
     * (section `_meta`) are never returned. A cursor ABOVE the person's highest version cannot be
     * right (a restored backup, an erased and re-created account), so the whole list is read from the
     * start and `resetCursor: true` tells the device to replace its number.
     *
     * @param \mysqli $db     live connection
     * @param int     $userId the person
     * @param int     $cursor the highest version the device has already seen (0 = nothing yet)
     * @param int     $limit  page size, 1..PROFILE_SYNC_PAGE_SIZE
     * @return array{ready:bool,cursor:int,more:bool,resetCursor:bool,changes:list<array{section:string,key:string,value:mixed,deleted:bool,version:int}>}
     */
    function profileSyncPull(\mysqli $db, int $userId, int $cursor, int $limit = PROFILE_SYNC_PAGE_SIZE): array
    {
        if (!profileItemsReady($db)) {
            return ['ready' => false, 'cursor' => max(0, $cursor), 'more' => false, 'resetCursor' => false, 'changes' => []];
        }
        $cursor = max(0, $cursor);
        $limit  = max(1, min(PROFILE_SYNC_PAGE_SIZE, $limit));

        $stmt = $db->prepare('SELECT COALESCE(MAX(Version), 0) FROM tblUserProfileItems WHERE UserId = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $max = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        $reset = $cursor > $max;
        if ($reset) { $cursor = 0; }

        $fetch = $limit + 1;                 /* one extra row tells us whether another page exists */
        $meta  = PROFILE_SYNC_META_SECTION;
        $stmt = $db->prepare(
            'SELECT Section, ItemKey, ValueJson, Version, DeletedAt FROM tblUserProfileItems
              WHERE UserId = ? AND Version > ? AND Section <> ? ORDER BY Version ASC LIMIT ?'
        );
        $stmt->bind_param('iisi', $userId, $cursor, $meta, $fetch);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
        $stmt->close();

        $more = count($rows) > $limit;
        if ($more) { array_pop($rows); }
        $changes = []; $last = $cursor;
        foreach ($rows as $r) {
            $deleted = $r[4] !== null;
            $changes[] = [
                'section' => (string)$r[0], 'key' => (string)$r[1],
                'value'   => ($deleted || $r[2] === null) ? null : json_decode((string)$r[2], true),
                'deleted' => $deleted, 'version' => (int)$r[3],
            ];
            $last = (int)$r[3];
        }
        return ['ready' => true, 'cursor' => $last, 'more' => $more, 'resetCursor' => $reset, 'changes' => $changes];
    }
}
