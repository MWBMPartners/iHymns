<?php

declare(strict_types=1);

/**
 * iHymns — Service Mode helpers (#1335, epic #1323)
 *
 * Shared server logic for congregation "Service Mode": the projection page +
 * operator endpoints (Phase 2b), the congregant join/poll (2c) and the Phase-3
 * presence gate all funnel through here so the rules live in ONE place.
 *
 * Load-bearing invariants:
 *  - CHANNEL = the 3-docroot env discriminator (ihymns_environment()) — a
 *    session created on alpha/beta is NEVER joinable/gating on production.
 *    Every join/poll/gate/prune query MUST filter on it (the prod-stale class
 *    of bug). serviceMode_channel() is the single source.
 *  - CODES are Crockford base32 (no ambiguous glyphs), session-scoped unique,
 *    rotated on a current+previous+grace window so a just-before-rotation scan
 *    still validates.
 *  - The service-occurrence END is a LOCAL time in the venue's IANA tz; it is
 *    resolved to a UTC instant (DST-aware) and capped at a hard ceiling so a
 *    relayed code can never unlock for an all-day window.
 *  - Broadcast payload v2 (#1405): serviceMode_cleanState() is the ONE state
 *    allow-list for all THREE broadcast writers (live_follow_create/_update,
 *    service_broadcast) — a 4th writer reuses this, never re-forks it. The
 *    client-facing "stateVersion" concept IS the existing `revision` /
 *    `StateRevision` counter each endpoint already returns — no new field.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php';

/** Rotation horizon + grace for a join code (the current+previous window). */
const SERVICE_MODE_CODE_TTL_SECONDS   = 75;
/** Hard ceiling on any session / presence lifetime (matches the #1268 spine's 4h). */
const SERVICE_MODE_HARD_CEILING_HOURS = 4;
/**
 * Session-liveness freshness window (seconds). A live session whose
 * LastHeartbeatAt is older than this is treated as stale and skipped by every
 * join/poll. UNIFIED across BOTH live systems (#1386 alignment): #1268 Live
 * Follow (host song-page 30 s heartbeat + visibility/focus wake-beat) AND #1335
 * Service Mode (projection/leader 30 s code-rotate heartbeat + wake refresh).
 * 180 s = 6× the 30 s heartbeat, so a briefly-backgrounded/throttled broadcaster
 * tab survives one or two missed beats without dropping joins or followers.
 */
const LIVE_SESSION_FRESHNESS_SECONDS  = 180;
/** Crockford-ish base32 — no I/L/O/U/0/1 (matches live_follow_create). */
const SERVICE_MODE_CODE_ALPHABET      = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

/**
 * Broadcast payload v2 (#1405) — the closed `displayState` vocabulary. VARCHAR
 * app-validated against this array, never an ENUM (rule #20 — a value-add
 * would otherwise be a schema ALTER; this lives entirely inside the existing
 * StateJson blob, so growing it is a one-line PHP change, not a migration).
 *   'live'     - showing the current song/line normally.
 *   'blackout' - hide all content (blank screen); the direct successor to the
 *                legacy boolean `blank: true`.
 *   'logo'     - show the ministry/church logo instead of lyrics. Has NO
 *                legacy equivalent - a pre-#1405 client that only understands
 *                `blank` still needs to hide stale lyrics, so the bridge below
 *                degrades this to `blank: true` too (fail toward "hidden", not
 *                "stuck showing the wrong thing").
 */
const SERVICE_DISPLAY_STATES = ['live', 'blackout', 'logo'];

/**
 * Server-declared per-role poll cadence (#1406). Keys are tblAppSettings
 * overrides (a freeform key/value store - no migration needed to add a key);
 * the literal ints are the dormant-safe fallback while the setting is unset.
 * A projector polls faster (near-1s) than a congregant's phone (2.5s is
 * plenty for song-follow) because a stale TV/projector image is the most
 * visible failure in the room.
 */
const SERVICE_MODE_POLL_MS_CONGREGANT_KEY     = 'service_poll_interval_congregant_ms';
const SERVICE_MODE_POLL_MS_PROJECTOR_KEY      = 'service_poll_interval_projector_ms';
const SERVICE_MODE_POLL_MS_CONGREGANT_DEFAULT = 2500;
const SERVICE_MODE_POLL_MS_PROJECTOR_DEFAULT  = 1000;

/** Presence roles (#1406) - VARCHAR app-validated, never an ENUM (rule #20). */
const SERVICE_MODE_PRESENCE_ROLES = ['congregant', 'projector'];

/**
 * The environment channel a Service-Mode row belongs to ('alpha'|'beta'|
 * 'production'). The 3-docroot discriminator — stamped at create, filtered
 * everywhere so cross-env sessions never leak (the prod-stale class of bug).
 */
function serviceMode_channel(): string
{
    return ihymns_environment();
}

/** Generate a fresh ambiguity-free join code. */
function serviceMode_generateCode(int $len = 6): string
{
    $alphabet = SERVICE_MODE_CODE_ALPHABET;
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $len; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

/**
 * Broadcast payload v2 (#1405) — the ONE state allow-list shared by every
 * broadcast writer (#1268 `live_follow_create`/`live_follow_update`, #1335
 * `service_broadcast`). Extracted from the former per-file
 * `_liveFollowCleanState()` so a future 4th writer reuses this instead of
 * re-forking the allow-list (rule #26 / CLAUDE.md modularity rule). NEVER
 * stores raw client JSON — only the known broadcast hints survive, each
 * validated/clamped to a safe range. Returns a compact JSON string, or null
 * when nothing valid was sent.
 *
 * ELI5: the operator's browser/app sends "what should the screen show right
 * now" (song blanked? which line? a logo instead?) — this function is the
 * bouncer that only lets known-safe, known-shaped facts through before they
 * get saved and broadcast to every following device.
 *
 * DETAILED — the `blank`↔`displayState` bridge is LOAD-BEARING (#1405): pre-
 * #1405 clients only ever send/understand the boolean `blank`; the v2 payload
 * adds a richer three-state `displayState` (`live`/`blackout`/`logo`, see
 * SERVICE_DISPLAY_STATES). Writing only ONE of the two fields would silently
 * desync the two client generations — a v2 broadcaster setting `logo` would
 * leave a legacy follower's `blank` untouched (stale/wrong), and a legacy
 * broadcaster setting `blank: true` would leave a v2 follower's `displayState`
 * untouched (stuck on whatever it last was). So BOTH fields are always
 * (re)written together here, whichever direction the caller sent:
 *   - `displayState` present + valid → authoritative; `blank` is DERIVED as
 *     `displayState !== 'live'` (both `blackout` and the legacy-unaware
 *     `logo` degrade a legacy client to "hidden", never "stuck showing the
 *     wrong thing").
 *   - only `blank` present (legacy caller) → `displayState` is DERIVED as
 *     `blank ? 'blackout' : 'live'`.
 * `stateVersion` is intentionally NOT a new field here — see this file's
 * header doc-block; the existing `revision`/`StateRevision` counter already
 * IS that value for every reader.
 *
 * @param mixed $state Decoded JSON body's `state` value (or absent/non-array).
 * @return string|null Compact JSON, or null when there is nothing valid to store.
 */
function serviceMode_cleanState(mixed $state): ?string
{
    if (!is_array($state)) {
        return null;
    }
    $clean = [];

    $displayState = null;
    if (isset($state['displayState']) && is_string($state['displayState'])
        && in_array($state['displayState'], SERVICE_DISPLAY_STATES, true)) {
        $displayState = $state['displayState'];
    }
    $blank = array_key_exists('blank', $state) ? (bool)$state['blank'] : null;

    if ($displayState !== null) {
        $clean['displayState'] = $displayState;
        $clean['blank'] = ($displayState !== 'live');
    } elseif ($blank !== null) {
        $clean['blank'] = $blank;
        $clean['displayState'] = $blank ? 'blackout' : 'live';
    }

    /* #1405 — nullable, clamped 0-9999 (mirrors componentIndex's existing clamp). */
    if (array_key_exists('lineIndex', $state) && $state['lineIndex'] !== null && is_numeric($state['lineIndex'])) {
        $clean['lineIndex'] = max(0, min(9999, (int)$state['lineIndex']));
    }

    if (array_key_exists('scrollPct', $state) && is_numeric($state['scrollPct'])) {
        $clean['scrollPct'] = max(0.0, min(1.0, (float)$state['scrollPct']));
    }
    if (array_key_exists('transposeOffset', $state) && is_numeric($state['transposeOffset'])) {
        $clean['transposeOffset'] = max(-12, min(12, (int)$state['transposeOffset']));
    }

    if (!$clean) {
        return null;
    }
    return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Resolve a service occurrence's END as a UTC 'Y-m-d H:i:s' string.
 *
 * StartTime is a LOCAL time of day in the venue's IANA timezone; we add
 * DurationMins (DST-aware via DateTime) and convert to UTC — never DATE_ADD a
 * local TIME against UTC_TIMESTAMP() (which would shift by the server tz). The
 * result is capped at SERVICE_MODE_HARD_CEILING_HOURS from now so a relayed
 * code can't unlock for an all-day window.
 *
 * @param string $occurrenceDate 'Y-m-d' of this occurrence.
 * @param string $startTime      Local 'HH:MM' or 'HH:MM:SS'.
 * @param int    $durationMins   Service window length.
 * @param string $tz             Venue IANA tz (e.g. 'Europe/London'); '' → UTC.
 */
function serviceMode_occurrenceEndUtc(string $occurrenceDate, string $startTime, int $durationMins, string $tz): string
{
    $utc = new \DateTimeZone('UTC');
    try {
        $local = new \DateTimeZone($tz !== '' ? $tz : 'UTC');
    } catch (\Throwable $e) {
        $local = $utc;
    }

    $hhmmss = strlen($startTime) >= 8 ? substr($startTime, 0, 8) : substr($startTime, 0, 5);
    $fmt    = strlen($hhmmss) >= 8 ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
    $start  = \DateTime::createFromFormat($fmt, $occurrenceDate . ' ' . $hhmmss, $local);

    $ceiling = new \DateTime('now', $utc);
    $ceiling->modify('+' . SERVICE_MODE_HARD_CEILING_HOURS . ' hours');

    if ($start === false) {
        /* Unparseable schedule → fall back to the hard ceiling from now. */
        return $ceiling->format('Y-m-d H:i:s');
    }

    $end = clone $start;
    $end->modify('+' . max(1, $durationMins) . ' minutes');
    $end->setTimezone($utc);

    return ($end > $ceiling ? $ceiling : $end)->format('Y-m-d H:i:s');
}

/**
 * Transactionally mint the next rotating join code for a session:
 * previous → superseded, current → previous, insert a fresh 'current'. Returns
 * the new code. Locks the session's code rows (FOR UPDATE) so two near-
 * simultaneous rotates (projection setInterval + a reload) can't race to two
 * 'current' rows. Retries on the session-scoped (SessionId, Code) collision.
 *
 * @throws \mysqli_sql_exception|\RuntimeException on a real failure (caller rolls the request).
 */
function serviceMode_mintCode(\mysqli $db, int $sessionId): string
{
    $db->begin_transaction();
    try {
        /* Lock this session's code rows + read the high-water generation. */
        $lock = $db->prepare('SELECT COALESCE(MAX(Generation), 0) AS g FROM tblLiveFollowJoinCodes WHERE SessionId = ? FOR UPDATE');
        $lock->bind_param('i', $sessionId);
        $lock->execute();
        $gen = (int)($lock->get_result()->fetch_assoc()['g'] ?? 0) + 1;
        $lock->close();

        /* Age the window: previous → superseded, current → previous. */
        $sup = $db->prepare("UPDATE tblLiveFollowJoinCodes SET Status = 'superseded' WHERE SessionId = ? AND Status = 'previous'");
        $sup->bind_param('i', $sessionId);
        $sup->execute();
        $sup->close();
        $prev = $db->prepare("UPDATE tblLiveFollowJoinCodes SET Status = 'previous' WHERE SessionId = ? AND Status = 'current'");
        $prev->bind_param('i', $sessionId);
        $prev->execute();
        $prev->close();

        /* Insert a fresh current; retry on the (SessionId, Code) UNIQUE. */
        $ttl  = SERVICE_MODE_CODE_TTL_SECONDS;
        $code = '';
        $ins  = $db->prepare(
            "INSERT INTO tblLiveFollowJoinCodes (SessionId, Code, Generation, Status, IssuedAt, ExpiresAt)
             VALUES (?, ?, ?, 'current', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))"
        );
        $ins->bind_param('isii', $sessionId, $code, $gen, $ttl);
        $done = false;
        for ($attempt = 0; $attempt < 6 && !$done; $attempt++) {
            $code = serviceMode_generateCode(6);
            try {
                $ins->execute();
                $done = true;
            } catch (\mysqli_sql_exception $e) {
                if ($e->getCode() !== 1062) { throw $e; }
            }
        }
        $ins->close();
        if (!$done) { throw new \RuntimeException('Could not allocate a join code.'); }

        $db->commit();
        return $code;
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Resolve a submitted join code to its LIVE service session, within a channel.
 * A 'current' or 'previous' code whose ExpiresAt is still in the future and
 * whose session is active + fresh (LastHeartbeatAt within 90s) resolves.
 *
 * $venueId is OPTIONAL: a congregant typing a code off the screen doesn't know
 * the venue, so pass 0 to resolve by code + channel alone (codes are 6 Crockford
 * chars ≈ 1e9 space, so a code maps to ≤1 active session in practice; the
 * freshest wins on the rare clash). A QR deep-link can pass venueId for an exact
 * scope. Returns the session row (assoc) or null; opaque messaging is the
 * caller's job.
 */
function serviceMode_resolveJoin(\mysqli $db, string $code, int $venueId, string $channel): ?array
{
    /* Unified freshness window (was 90s — aligned to Live Follow's 180s, #1386).
       Trusted int constant, interpolated (not a bound value). */
    $freshness = (int) LIVE_SESSION_FRESHNESS_SECONDS;
    $stmt = $db->prepare(
        "SELECT s.Id, s.OrgId, s.VenueId, s.ScheduleId, s.OccurrenceDate, s.Channel
           FROM tblLiveFollowJoinCodes c
           JOIN tblLiveFollowSessions s ON s.Id = c.SessionId
          WHERE c.Code = ?
            AND c.Status IN ('current', 'previous')
            AND c.ExpiresAt > UTC_TIMESTAMP()
            AND (? = 0 OR s.VenueId = ?)
            AND s.Channel = ?
            AND s.SessionKind = 'service'
            AND s.IsActive = 1
            AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)
          ORDER BY s.LastHeartbeatAt DESC
          LIMIT 1"
    );
    $stmt->bind_param('siis', $code, $venueId, $venueId, $channel);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * #1406 — cached probe: does `tblServicePresence.Role` exist yet? Gates every
 * Role read/write so `service_join`/`service_poll` stay dormant-safe on an
 * un-migrated install (the column ships via the Phase-2 schema batch,
 * `migrate-apple-phase2-live-schema.php`). Mirrors the established
 * `tierCapsColumnExists()` (access_tier_validation.php) /
 * `creditPersonMembersTableExists()` (credit_people_helpers.php) pattern —
 * one INFORMATION_SCHEMA round-trip, memoised for the rest of the request.
 */
function serviceMode_presenceRoleColumnExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblServicePresence'
                AND COLUMN_NAME  = 'Role' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        /* Probe failure — degrade as "not migrated yet" (fail closed on the
           column, fail OPEN on the caller's behaviour: everyone is treated as
           'congregant', never blocked). */
        $cached = false;
    }
    return $cached;
}

/**
 * #1406 — resolve a presence token's declared Role for the per-role poll
 * budget. Fail-open to 'congregant' pre-migration, on any lookup error, or
 * for an unknown token (the caller's own gate — `service_poll`'s main SELECT
 * — is what actually rejects an unknown/expired token; this never widens
 * that check, it only picks which rate-limit bucket applies).
 */
function serviceMode_presenceRole(\mysqli $db, string $presenceToken): string
{
    if (!serviceMode_presenceRoleColumnExists($db)) {
        return 'congregant';
    }
    try {
        $stmt = $db->prepare('SELECT Role FROM tblServicePresence WHERE PresenceToken = ? LIMIT 1');
        $stmt->bind_param('s', $presenceToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $role = (string)($row['Role'] ?? '');
        return $role === 'projector' ? 'projector' : 'congregant';
    } catch (\Throwable $_e) {
        return 'congregant';
    }
}

/**
 * #1406 — validate a client-declared join role against the closed vocabulary.
 * Anything unrecognised (including absent) fails OPEN to 'congregant' —
 * never blocks a join over a role the client mis-typed.
 */
function serviceMode_cleanPresenceRole(mixed $role): string
{
    $normalised = is_string($role) ? strtolower(trim($role)) : '';
    return in_array($normalised, SERVICE_MODE_PRESENCE_ROLES, true) ? $normalised : 'congregant';
}

/**
 * #1406 — server-declared poll cadence in milliseconds for the given role.
 * Admin-tunable via tblAppSettings (SERVICE_MODE_POLL_MS_*_KEY); falls back to
 * the hardcoded default when the setting is absent, non-numeric, or
 * `getAppSetting()` itself isn't loaded (defensive — this file has no hard
 * dependency on includes/maintenance.php).
 */
function serviceMode_pollIntervalMs(string $role): int
{
    $projector = ($role === 'projector');
    $key       = $projector ? SERVICE_MODE_POLL_MS_PROJECTOR_KEY : SERVICE_MODE_POLL_MS_CONGREGANT_KEY;
    $default   = $projector ? SERVICE_MODE_POLL_MS_PROJECTOR_DEFAULT : SERVICE_MODE_POLL_MS_CONGREGANT_DEFAULT;
    if (!function_exists('getAppSetting')) {
        return $default;
    }
    $raw = getAppSetting($key, (string)$default);
    $ms  = (int)($raw ?? $default);
    return $ms > 0 ? $ms : $default;
}

/**
 * Phase 3 gate read (#1335): does this presence token entitle the holder to the
 * org's CCLI licence right now? Returns the org's CCLI LicenceNumber (a string;
 * may be '' if the org left it blank) when the token resolves to an ACTIVE,
 * unexpired presence on an ACTIVE service session whose org holds a LIVE 'ccli'
 * licence — else null. Channel-scoped (3-docroot guard). This is what lets a
 * present congregant pass a `require_licence: ccli` rule for the duration of the
 * service (and revoked the instant they leave / it expires / the org's licence
 * lapses). Caller (checkContentAccess) injects 'ccli' into the effective set
 * when this returns non-null; song.php uses the number for the CCL notice.
 *
 * Presence/session freshness compares UTC (those rows are UTC); the org licence
 * expiry uses NOW() to match the existing licence layer (licences.php).
 */
function serviceMode_presenceCcliNumber(\mysqli $db, string $presenceToken, string $channel): ?string
{
    if (!preg_match('/^[A-Za-z0-9_\-]{43}$/', $presenceToken)) {
        return null;
    }
    try {
        $stmt = $db->prepare(
            "SELECT o.LicenceNumber
               FROM tblServicePresence p
               JOIN tblLiveFollowSessions s ON s.Id = p.SessionId
               JOIN tblOrganisations o      ON o.Id = s.OrgId
              WHERE p.PresenceToken = ?
                AND p.IsActive = 1
                AND p.ExpiresAt > UTC_TIMESTAMP()
                AND p.Channel = ?
                AND s.IsActive = 1
                AND o.LicenceType = 'ccli'
                AND (o.LicenceExpiresAt IS NULL OR o.LicenceExpiresAt > NOW())
              LIMIT 1"
        );
        $stmt->bind_param('ss', $presenceToken, $channel);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        return (string)($row['LicenceNumber'] ?? '');
    } catch (\Throwable $e) {
        /* Optional tables absent / probe failure → no grant (fail closed). */
        error_log('[service_mode/presenceCcli] ' . $e->getMessage());
        return null;
    }
}
