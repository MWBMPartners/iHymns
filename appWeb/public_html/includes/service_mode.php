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
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php';

/** Rotation horizon + grace for a join code (the current+previous window). */
const SERVICE_MODE_CODE_TTL_SECONDS   = 75;
/** Hard ceiling on any session / presence lifetime (matches the #1268 spine's 4h). */
const SERVICE_MODE_HARD_CEILING_HOURS = 4;
/** Crockford-ish base32 — no I/L/O/U/0/1 (matches live_follow_create). */
const SERVICE_MODE_CODE_ALPHABET      = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

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
 * Resolve a submitted join code to its LIVE session id, scoped to the venue +
 * channel (so two venues' identical codes never cross-validate). Accepts a
 * 'current' or 'previous' code whose ExpiresAt is still in the future and whose
 * session is active + fresh (LastHeartbeatAt within 90s). Returns the session
 * row (assoc) or null. The opaque-on-failure messaging is the caller's job.
 */
function serviceMode_resolveJoin(\mysqli $db, string $code, int $venueId, string $channel): ?array
{
    $stmt = $db->prepare(
        "SELECT s.Id, s.OrgId, s.VenueId, s.ScheduleId, s.OccurrenceDate, s.Channel
           FROM tblLiveFollowJoinCodes c
           JOIN tblLiveFollowSessions s ON s.Id = c.SessionId
          WHERE c.Code = ?
            AND c.Status IN ('current', 'previous')
            AND c.ExpiresAt > UTC_TIMESTAMP()
            AND s.VenueId = ?
            AND s.Channel = ?
            AND s.SessionKind = 'service'
            AND s.IsActive = 1
            AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)
          LIMIT 1"
    );
    $stmt->bind_param('sis', $code, $venueId, $channel);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
