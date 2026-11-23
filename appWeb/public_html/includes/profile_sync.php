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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_settings.php'; /* userSettingsMutateLocked(): the ONE writer of the old settings box (A1) */
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

/**
 * The most delete markers kept per person for ONE map section (a section with a row per item, like the key and capo per song).
 * A marker is what tells another device "this item was removed"; without a bound, set-then-delete over and over would grow the
 * table for ever. Past the bound the OLDEST markers are removed when a newer one is written. A device that has been away so long
 * it never saw a pruned removal keeps that one item until it is changed again (the per-person counter never goes back, so no
 * version number is reused). Sections with fixed keys cannot grow, so only map sections need this.
 */
const PROFILE_SYNC_MAX_MARKERS_PER_SECTION = 5000;

/** How many changes one read returns. A reply with more waiting says `more: true`. */
const PROFILE_SYNC_PAGE_SIZE = 500;

/** The section for the server's own bookkeeping rows. Not in the registry, so a client can never write it. */
const PROFILE_SYNC_META_SECTION = '_meta';

/** The reasons a single change can be refused. Defined once; a client branches on these, never on a sentence (rule #35). */
const PROFILE_SYNC_REJECT_REASONS = ['unknown_key', 'device_only', 'not_profile_item', 'invalid_value', 'invalid_item_key', 'value_too_large', 'cap_reached', 'not_opted_in'];

/** The recent-searches list (section, key). It may be stored ONLY while the person's opt-in option (below) is live and true (privacy, fix 1 of the 2b review). */
const PROFILE_SYNC_SEARCH_LIST = ['history', 'searches'];

/** The opt-in option "keep my recent searches in my account" (section, key). Off, or never set, means no searches are kept. */
const PROFILE_SYNC_SEARCH_OPTION = ['privacy', 'syncSearchHistory'];

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
    /* The recent-searches privacy rule (the server enforces it, whatever a client sends):
       the list is accepted only while the opt-in option is live and true, counting the changes earlier in THIS batch;
       and when the option ends the batch off (deleted, or set false) the list is deleted in the same batch. */
    $listPair   = profileSyncPairKey(PROFILE_SYNC_SEARCH_LIST[0], PROFILE_SYNC_SEARCH_LIST[1]);
    $optionPair = profileSyncPairKey(PROFILE_SYNC_SEARCH_OPTION[0], PROFILE_SYNC_SEARCH_OPTION[1]);
    $optionTouched = false;

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
            if ($pair === $listPair) {
                $opt = $state[$optionPair] ?? null;
                if ($opt === null || $opt['deleted'] || $opt['value'] !== true) { $reject('not_opted_in'); continue; }
            }
            if ($pair === $optionPair) { $optionTouched = true; }
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
        if ($pair === $optionPair) { $optionTouched = true; }
        if (!$isLive) { $unchanged++; continue; }             /* nothing there, or already a marker: nothing to do */
        if ($isMap) { $counts[$section] = max(0, ($counts[$section] ?? 0) - 1); }
        $version++;
        $state[$pair] = ['deleted' => true, 'value' => null];
        $writes[$pair] = ['section' => $section, 'key' => $key, 'json' => null, 'deleted' => true, 'version' => $version, 'platform' => $platform];
        $applied++;
    }

    /* The option ended this batch off: the account must hold no searches. The stored row becomes a delete marker
       (its value is cleared by the write), so every other device learns the list is gone. */
    if ($optionTouched) {
        $opt  = $state[$optionPair] ?? null;
        $list = $state[$listPair] ?? null;
        $on   = $opt !== null && !$opt['deleted'] && $opt['value'] === true;
        if (!$on && $list !== null && !$list['deleted']) {
            $version++;
            $state[$listPair] = ['deleted' => true, 'value' => null];
            $writes[$listPair] = ['section' => PROFILE_SYNC_SEARCH_LIST[0], 'key' => PROFILE_SYNC_SEARCH_LIST[1], 'json' => null, 'deleted' => true, 'version' => $version, 'platform' => $platform];
            $applied++;
        }
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
            if (profileSyncPairKey($c['section'], $c['key']) === profileSyncPairKey(PROFILE_SYNC_SEARCH_LIST[0], PROFILE_SYNC_SEARCH_LIST[1])
                || profileSyncPairKey($c['section'], $c['key']) === profileSyncPairKey(PROFILE_SYNC_SEARCH_OPTION[0], PROFILE_SYNC_SEARCH_OPTION[1])) {
                /* The searches rule needs BOTH rows, whichever of the two this change names. */
                $pairs[profileSyncPairKey(PROFILE_SYNC_SEARCH_LIST[0], PROFILE_SYNC_SEARCH_LIST[1])] = PROFILE_SYNC_SEARCH_LIST;
                $pairs[profileSyncPairKey(PROFILE_SYNC_SEARCH_OPTION[0], PROFILE_SYNC_SEARCH_OPTION[1])] = PROFILE_SYNC_SEARCH_OPTION;
            }
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

            /* Keep the delete markers of each touched map section bounded: remove the OLDEST past the limit (the newest, just written, stay). */
            $markerSections = [];
            foreach ($plan['writes'] as $w) {
                if (!$w['deleted']) { continue; }
                $e = profileRegistryEntry($w['section'], $w['key']);
                if ($e !== null && ($e['type'] ?? '') === 'map') { $markerSections[$w['section']] = true; }
            }
            foreach (array_keys($markerSections) as $sec) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUserProfileItems WHERE UserId = ? AND Section = ? AND DeletedAt IS NOT NULL');
                $stmt->bind_param('is', $userId, $sec);
                $stmt->execute();
                $markers = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();
                $extra = $markers - PROFILE_SYNC_MAX_MARKERS_PER_SECTION;
                if ($extra > 0) {
                    $stmt = $db->prepare('DELETE FROM tblUserProfileItems WHERE UserId = ? AND Section = ? AND DeletedAt IS NOT NULL ORDER BY Version ASC LIMIT ?');
                    $stmt->bind_param('isi', $userId, $sec, $extra);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        return ['ready' => true, 'applied' => $plan['applied'], 'unchanged' => $plan['unchanged'], 'rejected' => $plan['rejected'],
                'maxVersion' => $plan['maxVersion'], 'writes' => $plan['writes']];
    }
}

if (!function_exists('profileSyncApply')) {
    /**
     * Store a batch of changes for one person: begin, lock the person's row, bring in what old code wrote
     * to the old settings box, apply, copy the accepted display settings back into that box, commit.
     *
     * ELI5: the one door for saving small personal items. Anyone else changing this person's
     * data at the same moment waits their turn, so no two writers ever pick the same version number.
     * Any database error rolls everything back and is re-thrown, so a failed save is never reported as saved.
     * The old-settings-box steps (2b-4) are best effort: a trouble there is logged and never blocks the save.
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
            /* First bring in anything old code wrote to the old box since last time, so a change made by
               an old app is not lost and the new changes below are the later arrivals (they win). */
            profileSyncBridgeStep($db, $userId, static function () use ($db, $userId, $channel): void {
                profileSyncReconcileWebShelf($db, $userId, $channel);
            });
            $result = profileSyncApplyLocked($db, $userId, $changes, $platform, $channel);
            /* Then copy the accepted display settings into the old box, so old apps (and the live site, which
               still runs the older code) see them. */
            if ($result['writes'] !== []) {
                profileSyncBridgeStep($db, $userId, static function () use ($db, $userId, $result): void {
                    profileSyncMirrorToWebShelf($db, $userId, $result['writes']);
                });
            }
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

/* ======================================================================
 * THE REQUEST SHAPE (PR 2b, step 2b-3)
 * ====================================================================== */

/** The largest request body the endpoint reads, in bytes. A bigger one is a 413. */
const PROFILE_SYNC_MAX_BODY_BYTES = 65536;

/** The most `profile_sync` requests one signed-in person may make in a minute (generous: a device sends after a 1.5 second pause). */
const PROFILE_SYNC_RATE_PER_MINUTE = 120;

/**
 * PURE: read a cursor from a query string. Missing or empty means 0; digits only (at most 15), or it is not a cursor.
 *
 * @return ?int the cursor, or null when it is malformed (the endpoint answers 400)
 */
function profileSyncParseCursor(mixed $raw): ?int
{
    if ($raw === null || $raw === '') { return 0; }
    return (is_string($raw) && preg_match('/^[0-9]{1,15}$/D', $raw) === 1) ? (int)$raw : null;
}

/**
 * PURE: check and unpack the body of a `profile_sync` POST.
 *
 * ELI5: is the request well formed and not too big? The answer is a status the endpoint sends as it is
 * (400 invalid_request, 413 body_too_large, 413 too_many_changes) or 200 with the pieces. A client branches
 * on the status and the reason, never on a sentence (rule #35).
 *
 * @param ?string $raw the raw request body (at most PROFILE_SYNC_MAX_BODY_BYTES + 1 bytes were read)
 * @return array{status:int,reason:?string,cursor:int,changes:list<mixed>,platform:?string,schemaVersion:?int}
 */
function profileSyncParseRequest(?string $raw): array
{
    $fail = static fn(int $status, string $reason): array => ['status' => $status, 'reason' => $reason, 'cursor' => 0, 'changes' => [], 'platform' => null, 'schemaVersion' => null];
    if ($raw === null || $raw === '') { return $fail(400, 'invalid_request'); }
    if (strlen($raw) > PROFILE_SYNC_MAX_BODY_BYTES) { return $fail(413, 'body_too_large'); }
    $b = json_decode($raw, true);
    if (!is_array($b) || ($b !== [] && array_is_list($b))) { return $fail(400, 'invalid_request'); }

    $changes = [];
    if (array_key_exists('changes', $b)) {
        if (!is_array($b['changes']) || !array_is_list($b['changes'])) { return $fail(400, 'invalid_request'); }
        $changes = $b['changes'];
    }
    if (count($changes) > PROFILE_SYNC_MAX_CHANGES) { return $fail(413, 'too_many_changes'); }

    $cursor = 0;
    if (array_key_exists('cursor', $b)) {
        if (!is_int($b['cursor']) || $b['cursor'] < 0) { return $fail(400, 'invalid_request'); }
        $cursor = $b['cursor'];
    }
    $schemaVersion = null;
    if (array_key_exists('schemaVersion', $b)) {
        if (!is_int($b['schemaVersion']) || $b['schemaVersion'] < 0) { return $fail(400, 'invalid_request'); }
        $schemaVersion = $b['schemaVersion'];
    }
    $platform = null;
    if (array_key_exists('client', $b)) {
        if (!is_array($b['client']) || ($b['client'] !== [] && array_is_list($b['client']))) { return $fail(400, 'invalid_request'); }
        $platform = profileSyncCleanPlatform(is_string($b['client']['platform'] ?? null) ? $b['client']['platform'] : null);
    }
    return ['status' => 200, 'reason' => null, 'cursor' => $cursor, 'changes' => $changes, 'platform' => $platform, 'schemaVersion' => $schemaVersion];
}

/**
 * PURE: the success reply, in one place so the endpoint, its docs and its test cannot disagree.
 *
 * @param array<string,mixed> $pull    what profileSyncPull() returned
 * @param ?array<string,mixed> $apply  what profileSyncApply() returned (null for a read)
 * @return array<string,mixed>
 */
function profileSyncReply(array $pull, ?array $apply): array
{
    return [
        'ok'            => true,
        'cursor'        => (int)$pull['cursor'],
        'more'          => (bool)$pull['more'],
        'resetCursor'   => (bool)$pull['resetCursor'],
        'schemaVersion' => profileRegistryVersion(),
        'changes'       => $pull['changes'],
        'rejected'      => $apply['rejected'] ?? [],
        'applied'       => (int)($apply['applied'] ?? 0),
    ];
}

/** The body of a refusal because the switch is on but the database objects are not there yet. */
function profileSyncNotReadyBody(): array
{
    return [
        'error'  => 'This is not ready on this site yet.',
        'reason' => PROFILE_SYNC_NOT_READY_REASON,
    ];
}

/* ======================================================================
 * KEEPING THE OLD SETTINGS BOX AND THE PROFILE IN STEP (PR 2b, step 2b-4)
 * ======================================================================
 *
 * ELI5
 * ----
 * Until now the web kept its display settings (theme, text size, reading style...) in a shelf of the
 * account's settings box, `ihymns.web`. The live site keeps running that older code until this work is
 * promoted, and the Apple apps and iLyricsDB read that box too. So for a while BOTH places must tell the
 * same story. Two small rules keep them in step without either one ever deleting the other's data:
 *
 *   1. Old shelf -> profile ("lazy"). Whenever a profile_sync call is made, the server first looks at the
 *      shelf. If it has changed since the server last looked (it keeps a fingerprint), every value on it that
 *      differs from the profile is copied into the profile. A key that is MISSING from the shelf is never
 *      treated as "deleted": old apps only ever send the keys they have.
 *   2. Profile -> old shelf ("eager"). Each accepted display-setting write is copied onto the shelf at once,
 *      through the one locked writer of that box, and the fingerprint is refreshed, so the copy is not mistaken
 *      for a change by old code on the next look and bounced back.
 *
 * Detailed
 * --------
 *  - Only registry entries with `cookie: true` and a `legacyWebKey` travel through the shelf (the same fourteen
 *    the old settings page synced). The shelf holds TEXT; the registry holds real JSON types, so profileWebDecode()
 *    and profileWebEncode() convert. js/modules/profile-web-codec.js is the JavaScript twin; a test makes
 *    both give the same answer on the same samples (rule #35).
 *  - The fingerprint lives in `_meta/webShelfHash` (Version 0, so a pull never returns it).
 *  - Both steps run under the person's row lock (the same one the settings writer takes), inside the profile
 *    sync's own transaction. The box is changed ONLY through userSettingsMutateLocked() (the single-writer rule, A1).
 *  - A stale old app that pushes its whole shelf can still overwrite a newer value, exactly as it can today,
 *    until the new code is on every site. That is the documented cost of keeping old apps working.
 *  - Every step is best effort: a problem is logged and the save carries on (an error that ends the transaction
 *    is passed up, never swallowed).
 */

/** The shelf of the settings box the web has always used. The same text as USER_SETTINGS_NAMESPACE in js/modules/settings.js (a test keeps them equal). */
const PROFILE_SYNC_WEB_NAMESPACE = 'ihymns.web';

/** The bookkeeping row holding the fingerprint of the shelf as last seen. */
const PROFILE_SYNC_WEB_HASH_KEY = 'webShelfHash';

/**
 * The registry entries that travel through the old shelf, keyed by their old web storage key.
 *
 * @return array<string,array<string,mixed>>
 */
function profileWebEntries(): array
{
    $out = [];
    foreach (profileRegistry() as $e) {
        if (($e['cookie'] ?? false) === true && is_string($e['legacyWebKey'] ?? null) && ($e['sync'] ?? '') === 'profile_sync') {
            $out[$e['legacyWebKey']] = $e;
        }
    }
    return $out;
}

/**
 * PURE: turn the TEXT the old shelf holds into the registry's real value, or null when it is not a valid value.
 *
 * ELI5: the shelf stores everything as words ("true", "18", "{...}"). The profile stores real booleans, numbers
 * and objects. A true/false value is read from "true" or "1" (and false from "false" or "0"). Anything that does
 * not fit the registry's rules comes back null and is skipped, never stored.
 *
 * @param array<string,mixed> $entry a registry entry
 * @return mixed the value, or null (never a valid value)
 */
function profileWebDecode(array $entry, string $text): mixed
{
    $v = null;
    switch ($entry['type'] ?? '') {
        case 'bool':
            $v = ($text === 'true' || $text === '1') ? true : (($text === 'false' || $text === '0') ? false : null);
            break;
        case 'int':
            $v = preg_match('/^-?[0-9]{1,9}$/D', $text) === 1 ? (int)$text : null;
            break;
        case 'float':
            $v = preg_match('/^-?[0-9]{1,9}(\.[0-9]{1,9})?$/D', $text) === 1 ? (float)$text : null;
            break;
        case 'enum':
        case 'string':
            $v = $text !== '' ? $text : null;
            break;
        case 'json':
        case 'list':
            /* Decode once as plain objects, to tell `{}` (an object) from `[]` (a list): as arrays they are the
               same empty array, and an object entry must not accept a list nor the other way round. */
            $raw = json_decode($text);
            if (json_last_error() === JSON_ERROR_NONE) {
                $wantObject = ($entry['type'] ?? '') === 'json';
                if (($wantObject && $raw instanceof \stdClass) || (!$wantObject && is_array($raw))) {
                    $v = json_decode($text, true);
                }
            }
            break;
    }
    return ($v !== null && profileValidateSpec($entry, $v)) ? $v : null;
}

/**
 * PURE: turn a registry value into the TEXT the old shelf holds. The reverse of profileWebDecode().
 * A true/false value is written as "true" or "false"; a whole number as digits (2.0 as "2"); an object as
 * compact JSON (an empty one as "{}").
 *
 * @param array<string,mixed> $entry a registry entry
 */
function profileWebEncode(array $entry, mixed $value): string
{
    switch ($entry['type'] ?? '') {
        case 'bool':
            return $value === true ? 'true' : 'false';
        case 'int':
            return (string)(int)$value;
        case 'float':
            $s = (string)json_encode((float)$value);
            return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
        case 'json':
        case 'list':
            return profileSyncEncodeValue($entry, $value);
        default:
            return (string)$value;
    }
}

/** The text of one shelf value, or null when it is not a plain value (an array, null...). Old apps only ever store text. */
function profileWebShelfText(mixed $v): ?string
{
    if (is_string($v)) { return $v; }
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    if (is_int($v) || is_float($v)) { return (string)$v; }
    return null;
}

/**
 * PURE: a fingerprint of the part of the shelf this bridge cares about (only the registry's own keys, in a fixed order).
 *
 * @param array<string,mixed> $shelf the `ihymns.web` subtree
 */
function profileWebShelfHash(array $shelf): string
{
    $keys = array_keys(profileWebEntries());
    sort($keys, SORT_STRING);
    $o = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $shelf) && ($t = profileWebShelfText($shelf[$k])) !== null) { $o[$k] = $t; }
    }
    return hash('sha256', (string)json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** PURE: the `ihymns.web` shelf out of a whole settings box (`[]` when absent or not an object). @param array<string,mixed> $box @return array<string,mixed> */
function profileWebShelfOf(array $box): array
{
    $shelf = $box[PROFILE_SYNC_WEB_NAMESPACE] ?? [];
    return is_array($shelf) ? $shelf : [];
}

/**
 * PURE: which profile changes does a shelf stand for? One `set` per shelf value that is valid for its entry.
 * A key that is missing, empty or invalid yields NOTHING: this never produces a delete.
 *
 * @param array<string,mixed> $shelf
 * @return list<array{section:string,key:string,op:string,value:mixed}>
 */
function profileWebShelfToChanges(array $shelf): array
{
    $changes = [];
    foreach (profileWebEntries() as $legacy => $entry) {
        if (!array_key_exists($legacy, $shelf)) { continue; }
        $text = profileWebShelfText($shelf[$legacy]);
        if ($text === null) { continue; }
        $v = profileWebDecode($entry, $text);
        if ($v === null) { continue; }
        $changes[] = ['section' => $entry['section'], 'key' => $entry['key'], 'op' => 'set', 'value' => $v];
    }
    return $changes;
}

/**
 * PURE: the shelf after the accepted writes are copied onto it, and whether anything changed. A set becomes the value's
 * text; a delete becomes the empty text (an old app reads that as "reset to the default"). Only bridge entries are touched.
 *
 * @param array<string,mixed> $shelf
 * @param list<array<string,mixed>> $writes accepted writes from profileSyncPlan() (section, key, json, deleted)
 * @return array{shelf:array<string,mixed>,changed:bool}
 */
function profileWebShelfWithWrites(array $shelf, array $writes): array
{
    $byId = [];
    foreach (profileWebEntries() as $legacy => $entry) { $byId[$entry['id']] = [$legacy, $entry]; }
    $changed = false;
    foreach ($writes as $w) {
        $hit = $byId[($w['section'] ?? '') . '.' . ($w['key'] ?? '')] ?? null;
        if ($hit === null) { continue; }
        [$legacy, $entry] = $hit;
        $text = '';
        if (!($w['deleted'] ?? false) && is_string($w['json'] ?? null)) {
            $v = json_decode($w['json'], true);
            $text = profileWebEncode($entry, $v);
        }
        if (!array_key_exists($legacy, $shelf) || $shelf[$legacy] !== $text) {
            $shelf[$legacy] = $text;
            $changed = true;
        }
    }
    return ['shelf' => $shelf, 'changed' => $changed];
}

if (!function_exists('profileSyncBridgeStep')) {
    /**
     * Run one best-effort old-box step. A problem is logged and the caller carries on; an error that has ended the
     * caller's transaction is passed up (never swallowed), because carrying on after one would report a save that no
     * longer exists.
     */
    function profileSyncBridgeStep(\mysqli $db, int $userId, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            if (songRelocateIsTransactionFatal($e)) { throw $e; }
            error_log('[profile_sync] the old settings box step failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('profileSyncMetaRead')) {
    /** Read one bookkeeping value (section `_meta`), or null. @return mixed */
    function profileSyncMetaRead(\mysqli $db, int $userId, string $key): mixed
    {
        if (!profileItemsReady($db)) { return null; }
        $meta = PROFILE_SYNC_META_SECTION;
        $stmt = $db->prepare('SELECT ValueJson FROM tblUserProfileItems WHERE UserId = ? AND Section = ? AND ItemKey = ? LIMIT 1');
        $stmt->bind_param('iss', $userId, $meta, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return ($row === null || $row[0] === null) ? null : json_decode((string)$row[0], true);
    }
}

if (!function_exists('profileSyncMetaWrite')) {
    /**
     * Write one bookkeeping value (section `_meta`, Version 0, so a pull never returns it).
     * The caller holds the person's row lock.
     */
    function profileSyncMetaWrite(\mysqli $db, int $userId, string $key, mixed $value): void
    {
        if (!profileItemsReady($db)) { return; }
        $meta = PROFILE_SYNC_META_SECTION;
        $json = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare(
            'INSERT INTO tblUserProfileItems (UserId, Section, ItemKey, ValueJson, Version, UpdatedAt)
             VALUES (?, ?, ?, ?, 0, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE ValueJson = VALUES(ValueJson), UpdatedAt = VALUES(UpdatedAt), DeletedAt = NULL'
        );
        $stmt->bind_param('isss', $userId, $meta, $key, $json);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('profileSyncReconcileWebShelf')) {
    /**
     * Old shelf -> profile. The caller holds the person's row lock inside an open transaction.
     *
     * ELI5: if old code has written the old shelf since we last looked, copy each value that differs into the
     * profile. Never deletes. The first look at a person's shelf is the one-time import of what they had.
     *
     * @return array{ready:bool,looked:bool,imported:int}
     */
    function profileSyncReconcileWebShelf(\mysqli $db, int $userId, ?string $channel = null): array
    {
        if (!profileItemsReady($db)) { return ['ready' => false, 'looked' => false, 'imported' => 0]; }
        $shelf = profileWebShelfOf(userSettingsReadBox($db, $userId));
        $hash  = profileWebShelfHash($shelf);
        if (profileSyncMetaRead($db, $userId, PROFILE_SYNC_WEB_HASH_KEY) === $hash) {
            return ['ready' => true, 'looked' => false, 'imported' => 0];
        }
        $changes = profileWebShelfToChanges($shelf);
        $applied = 0;
        if ($changes !== []) {
            /* From the old web app: recorded as written by the web. Equal values are skipped by the plan. */
            $applied = profileSyncApplyLocked($db, $userId, $changes, 'web', $channel)['applied'];
        }
        profileSyncMetaWrite($db, $userId, PROFILE_SYNC_WEB_HASH_KEY, $hash);
        return ['ready' => true, 'looked' => true, 'imported' => $applied];
    }
}

if (!function_exists('profileSyncMirrorToWebShelf')) {
    /**
     * Profile -> old shelf. The caller holds the person's row lock inside an open transaction.
     *
     * ELI5: copy each accepted display-setting write onto the old shelf, then record the shelf's new fingerprint
     * so the next look does not mistake our own copy for a change by old code.
     *
     * @param list<array<string,mixed>> $writes accepted writes (as returned by profileSyncApplyLocked())
     * @return bool true when the box was changed
     */
    function profileSyncMirrorToWebShelf(\mysqli $db, int $userId, array $writes): bool
    {
        if (!profileItemsReady($db)) { return false; }
        $box   = userSettingsReadBox($db, $userId);
        $after = profileWebShelfWithWrites(profileWebShelfOf($box), $writes);
        if (!$after['changed']) { return false; }
        $ns = PROFILE_SYNC_WEB_NAMESPACE;
        $outcome = userSettingsMutateLocked($db, $userId, static function (array $current) use ($after, $ns) {
            $reason = userSettingsRejectReason($current, $ns, $after['shelf']);
            return $reason !== null ? $reason : userSettingsMergeNamespace($current, $ns, $after['shelf']);
        });
        if (!$outcome['ok']) {
            return false;   /* refused (too big): the box is unchanged, so the fingerprint stays as it was */
        }
        profileSyncMetaWrite($db, $userId, PROFILE_SYNC_WEB_HASH_KEY, profileWebShelfHash($after['shelf']));
        return true;
    }
}

if (!function_exists('profileSyncReconcileIfChanged')) {
    /**
     * For a read: bring in what old code wrote to the old shelf, taking the lock only when there is something new.
     *
     * ELI5: a device that only READS still needs to see a change an old app made. A quick look (no lock) compares
     * the shelf's fingerprint with the stored one; only when they differ does it lock the person and copy.
     *
     * @return bool true when the shelf was looked at under the lock
     * @throws \Throwable
     */
    function profileSyncReconcileIfChanged(\mysqli $db, int $userId, ?string $channel = null): bool
    {
        if (!profileItemsReady($db)) { return false; }
        $hash = profileWebShelfHash(profileWebShelfOf(userSettingsReadBox($db, $userId)));
        if (profileSyncMetaRead($db, $userId, PROFILE_SYNC_WEB_HASH_KEY) === $hash) { return false; }
        $db->begin_transaction();
        try {
            userSyncLockPerson($db, $userId);
            $r = profileSyncReconcileWebShelf($db, $userId, $channel);   /* looks again under the lock */
            $db->commit();
            return $r['looked'];
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $_r) { /* already gone */ }
            throw $e;
        }
    }
}
