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
 *  - CODES are Crockford base32 (no ambiguous glyphs), rotated on a
 *    current+previous+grace window so a just-before-rotation scan still
 *    validates, and — since #1621 — GLOBALLY UNIQUE while live, enforced by the
 *    database (`tblLiveFollowJoinCodes.ActiveCode`, a STORED generated column
 *    that mirrors `Code` only while `Status IN ('current','previous')`, under
 *    `UNIQUE KEY uq_ActiveCode`). The original `uq_Session_Code` was
 *    SESSION-scoped, so it only ever stopped a session colliding with ITSELF;
 *    two different organisations' concurrent services could hold the same code
 *    and a congregant would be silently routed to whichever had the freshest
 *    heartbeat — and, with it, that org's CCLI unlock (Phase 3 below). The
 *    generated column makes that collision impossible to store; resolveJoin()
 *    additionally REFUSES rather than guesses (defence in depth).
 *  - A LIVE code holds its slot in `uq_ActiveCode` purely by Status, because a
 *    generated column must be DETERMINISTIC — `UTC_TIMESTAMP()` is rejected
 *    (ER_GENERATED_COLUMN_FUNCTION_IS_NOT_ALLOWED), so expiry CANNOT be part of
 *    the expression. A row can therefore be un-joinable (past `ExpiresAt`) yet
 *    still occupying the namespace. Codes are consequently RETIRED (a Status
 *    transition — see below) on session end/supersede and, for the shut-laptop
 *    case, by the opportunistic serviceMode_retireExpiredCodes() below.
 *  - CODE-SPACE OCCUPANCY is bounded by CONCURRENT services, not by cumulative
 *    history — BECAUSE codes are released. That is the whole point of the
 *    retirement above, and the two facts must be read together: a code stops
 *    occupying the namespace the moment it leaves 'current'/'previous', and a
 *    session holds at most 2 live codes at a time (the current+previous
 *    window), so
 *          live codes ≈ 2 × services running AT ONCE.
 *    The alphabet is 30 characters (22 letters + 8 digits, ambiguous glyphs
 *    removed) at length 6 → 30^6 ≈ 7.29e8. Even at an implausible 100,000
 *    concurrent services worldwide that is ~200,000 live codes = 0.03%
 *    occupancy, so a mint collides ~0.03% of the time and all 6 retries failing
 *    is ~1e-21; occupancy only reaches 10% at roughly 36 MILLION concurrent
 *    services. So "lots of churches adopt this" is genuinely safe — but ONLY
 *    while codes are released. Skip the retirement and occupancy tracks
 *    cumulative sessions forever, and this entire argument collapses.
 *  - LENGTH HEADROOM, if real pressure ever arrives: `Code` (and the `ActiveCode`
 *    generated column mirroring it) is VARCHAR(12) while codes are 6 chars, and
 *    serviceMode_generateCode() takes the length as a parameter. Going to 7
 *    chars (2.2e10) or 8 (6.6e11) is a ONE-LINE change with NO migration. Do not
 *    make the length dynamic or negotiate it per session; the answer to scale
 *    pressure is "raise $len", not "redesign this".
 *  - RETIREMENT IS A FLAG, NEVER A DELETE. No code path in this project removes
 *    a tblLiveFollowJoinCodes row; retiring one only moves `Status` out of the
 *    live set, which nulls `ActiveCode` and releases the UNIQUE slot while the
 *    row (and the `Code` it carried) stays for audit/history. There is no
 *    privacy or retention driver here that would justify destroying it. If you
 *    are about to add a "tidy up old join codes" DELETE: don't.
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
/* licenceCcliQualifies() — the shared "is this a usable CCLI licence?" rule
   (#1668). Required so the Phase-3 unlock applies the SAME format criterion as
   includes/licences.php rather than growing its own. licences.php pulls in
   db_mysql.php + ccli_validator.php and depends on nothing here, so there is no
   require cycle. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licences.php';

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
 * #1621 — the join-code Status vocabulary. VARCHAR app-validated against these
 * maps, never an ENUM (rule #20 — 'expired' was added here as a one-line PHP
 * change precisely because the column is a VARCHAR; as an ENUM it would have
 * been an ALTER, i.e. the second migration rule #20 forbids).
 *
 *   'current'    — the code on the venue screen right now.
 *   'previous'   — the immediately-prior code, still honoured through the
 *                  rotation grace window so a scan started a second before a
 *                  rotate still validates.
 *   'superseded' — replaced by the NEXT code in normal rotation.
 *   'expired'    — retired because the SESSION ENDED (cleanly or by being
 *                  superseded at start), or because ExpiresAt passed with
 *                  nothing to supersede it (the shut-laptop case).
 *
 * ELI5: 'superseded' means "a newer code took over"; 'expired' means "the
 * service this belonged to is over". Both are dead; recording WHICH kind of
 * dead is the whole point — otherwise "why did this code stop working?" can
 * only be inferred, never read off the row.
 *
 * ONLY the LIVE values are mirrored into the `ActiveCode` generated column (a
 * POSITIVE list, deliberately), so any future retired-state value is
 * automatically excluded from the unique slot without touching the column
 * expression. `tblLiveFollowJoinCodes.ActiveCode` therefore carries the SAME
 * list in SQL — the one place this map cannot reach, since a generated column
 * is evaluated by MySQL. Changing SERVICE_MODE_CODE_LIVE_STATUSES means
 * changing that expression too, which is a migration; changing anything in the
 * RETIRED half is a PHP-only edit.
 *
 * Retiring a code is ALWAYS a Status transition. Nothing deletes these rows.
 */
const SERVICE_MODE_CODE_STATUSES      = ['current', 'previous', 'superseded', 'expired'];
const SERVICE_MODE_CODE_LIVE_STATUSES = ['current', 'previous'];

/**
 * #1621 — how far PAST `ExpiresAt` a live-but-dead code is left alone before the
 * opportunistic retirement below claims it. A code is already un-joinable the
 * instant `ExpiresAt` passes (every join/poll predicate compares it), so waiting
 * costs nothing behaviourally; the delay only buys clearance from clock skew
 * between app + DB hosts and from a rotate that is mid-flight. One full TTL is
 * generous and still releases the slot inside ~2.5 minutes.
 */
const SERVICE_MODE_CODE_RETIRE_GRACE_SECONDS = SERVICE_MODE_CODE_TTL_SECONDS;

/**
 * #1621 — hard cap on how many rows ONE opportunistic retirement pass may touch.
 * The pass piggy-backs on serviceMode_mintCode(), which runs every ~30 s per
 * live session, so it must never be able to turn a routine code rotate into a
 * long-running write. The realistic backlog is tiny (normal rotation already
 * retires everything except the LAST current+previous pair of each session, so
 * the leak is ~2 rows per session that ever ran), and a pass that hits the cap
 * simply finishes the job on the next rotate.
 */
const SERVICE_MODE_CODE_RETIRE_LIMIT = 500;

/**
 * #1621 — render SERVICE_MODE_CODE_LIVE_STATUSES as a SQL `IN (…)` value list,
 * so the "which statuses are live?" answer exists ONCE in PHP instead of being
 * re-typed into every predicate (CLAUDE.md red flag: "a hard-coded list … that
 * already exists in a central map").
 *
 * SAFETY: this is one of the two interpolations rule #5 explicitly permits —
 * hardcoded constants from PHP source, never a request value. There is nothing
 * to bind: the list is a compile-time constant and can never contain user
 * input. The two guards below make that structural rather than a matter of
 * trust — each value must be a member of the declared vocabulary AND a plain
 * lowercase word — so a careless future edit fails loudly here instead of
 * quietly becoming SQL.
 */
function serviceMode_codeLiveStatusSql(): string
{
    static $sql = null;
    if ($sql !== null) {
        return $sql;
    }
    $parts = [];
    foreach (SERVICE_MODE_CODE_LIVE_STATUSES as $status) {
        if (!in_array($status, SERVICE_MODE_CODE_STATUSES, true) || !preg_match('/^[a-z]{1,20}$/', $status)) {
            throw new \RuntimeException('Invalid join-code status literal: ' . var_export($status, true));
        }
        $parts[] = "'" . $status . "'";
    }
    $sql = implode(', ', $parts);
    return $sql;
}

/**
 * #1576 — floor applied to an AD-HOC service occurrence only (never to an
 * explicitly-scheduled one — see serviceMode_occurrenceEndUtc()'s doc-block).
 *
 * ELI5: if someone starts a service without picking a saved schedule, the
 * caller always fills in a fixed 10:00-for-90-minutes placeholder just so the
 * maths has SOME start/duration to work with — it isn't a real chosen time.
 * If "now" is already past that placeholder's end (e.g. it's 7pm), the session
 * would be born already-expired. This constant is the minimum number of
 * minutes an ad-hoc session is guaranteed to live from the moment it's
 * created, however late in the day it's started.
 *
 * DETAILED — chosen independently of the placeholder's own 90-minute duration
 * so the floor still does something sane even if that upstream constant ever
 * changes to something small; it is `max($durationMins, this)`, not a
 * replacement for the duration. 15 minutes is enough for an operator to
 * notice + restart if they really did mean to run a near-instant session.
 */
const SERVICE_MODE_ADHOC_MIN_MINUTES = 15;

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
 * #1576 — AD-HOC FLOOR. `$isAdHoc` marks a session started with no schedule
 * row (the caller — api.php's `service_session_start` — falls back to a
 * hardcoded '10:00:00'/90-minute placeholder in that case, rather than a real
 * chosen time). That placeholder has no relationship to when the operator is
 * actually starting the service, so for an evening service it can already be
 * in the past by the time duration is added — the computed $end would then be
 * SMALLER than $ceiling (which is always in the future), so the old
 * `$end > $ceiling ? $ceiling : $end` comparison let it through unflored and
 * every presence row born under it was already expired (#1576).
 *
 * ELI5: this is the difference between "the service is scheduled to run from
 * 10am to 11:30am" (a real time — if that's over, it's genuinely over) and
 * "someone just tapped Start with no time picked" (there is no real time to
 * be honest about, so we guarantee the ad-hoc session a minimum life from the
 * moment it's created instead of measuring it against a placeholder clock).
 *
 * DETAILED — the floor is intentionally scoped to `$isAdHoc` only. An
 * explicitly-scheduled occurrence (a real `tblOrgServiceSchedules` row) keeps
 * its honest, un-floored end: if an operator asks to (re)join a occurrence
 * whose scheduled window already passed, "expired" is the CORRECT answer, not
 * a bug — flooring it would let a stale schedule silently stay "live"
 * forever. See the commit body for the full floor-vs-scheduled reasoning.
 *
 * @param string $occurrenceDate 'Y-m-d' of this occurrence.
 * @param string $startTime      Local 'HH:MM' or 'HH:MM:SS'.
 * @param int    $durationMins   Service window length.
 * @param string $tz             Venue IANA tz (e.g. 'Europe/London'); '' → UTC.
 * @param bool   $isAdHoc        True when no schedule was picked (api.php's
 *                                hardcoded 10:00/90-minute placeholder path);
 *                                floors the result to at least
 *                                max($durationMins, SERVICE_MODE_ADHOC_MIN_MINUTES)
 *                                minutes from NOW. Default false preserves the
 *                                original honest-end behaviour for every other
 *                                (scheduled) caller.
 */
function serviceMode_occurrenceEndUtc(string $occurrenceDate, string $startTime, int $durationMins, string $tz, bool $isAdHoc = false): string
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

    $now     = new \DateTime('now', $utc);
    $ceiling = clone $now;
    $ceiling->modify('+' . SERVICE_MODE_HARD_CEILING_HOURS . ' hours');

    if ($start === false) {
        /* Unparseable schedule → fall back to the hard ceiling from now. */
        return $ceiling->format('Y-m-d H:i:s');
    }

    $end = clone $start;
    $end->modify('+' . max(1, $durationMins) . ' minutes');
    $end->setTimezone($utc);

    /* #1576 — ad-hoc floor: never born already-expired. A genuinely
       scheduled occurrence is left honest (no floor) — see the doc-block. */
    if ($isAdHoc && $end <= $now) {
        $floorMinutes = max($durationMins, SERVICE_MODE_ADHOC_MIN_MINUTES);
        $end = clone $now;
        $end->modify('+' . $floorMinutes . ' minutes');
    }

    return ($end > $ceiling ? $ceiling : $end)->format('Y-m-d H:i:s');
}

/**
 * #1621 — retire (NOT delete) every LIVE join code belonging to ONE session,
 * because that session has ended or been superseded. Returns the row count.
 *
 * ELI5: when a service finishes, the code on the screen has to stop working AND
 * hand its code back to the pool so another service can be given it later. This
 * flips those rows to "expired" — the rows themselves are kept, so you can still
 * look up later which code a past service used.
 *
 * DETAILED — before #1621 nothing did this: `service_session_end` cleared
 * `IsActive` on the session and revoked presence, but left the session's
 * `current` (+ `previous`) code rows sitting at a LIVE Status forever. That was
 * merely untidy while `uq_Session_Code` was session-scoped; with the global
 * `uq_ActiveCode` slot it means every ended service permanently holds a code,
 * and the unique index grows without bound. (To be clear about proportion: the
 * code space is 30^6 ≈ 7.3e8, so this was never an exhaustion risk — it is a
 * correctness + index-growth fix, and a future reader should not imagine the
 * sky was falling.)
 *
 * CHANNEL-FILTERED (rule #26) via a correlated EXISTS on the session's own
 * `Channel`: a request served by the alpha docroot must never write rows
 * belonging to a production session, even though the three docroots share ONE
 * MySQL. The EXISTS resolves by PRIMARY KEY (`s.Id = c.SessionId`), so the
 * filter costs one index lookup per candidate row. Selecting from a DIFFERENT
 * table while correlating on the updated table's column is allowed — the
 * ER_UPDATE_TABLE_USED restriction is on selecting FROM the table being updated.
 *
 * @param int    $sessionId The session whose codes are being retired.
 * @param string $channel   serviceMode_channel() of the CURRENT request.
 * @return int              Rows retired (0 is normal — e.g. a re-ended session).
 * @throws \mysqli_sql_exception The caller decides whether to roll back.
 */
function serviceMode_retireSessionCodes(\mysqli $db, int $sessionId, string $channel): int
{
    $live = serviceMode_codeLiveStatusSql();   /* constant-derived, see the helper */
    $stmt = $db->prepare(
        "UPDATE tblLiveFollowJoinCodes AS c
            SET c.Status = 'expired'
          WHERE c.SessionId = ?
            AND c.Status IN ({$live})
            AND EXISTS (
                  SELECT 1 FROM tblLiveFollowSessions s
                   WHERE s.Id = c.SessionId AND s.Channel = ?
                )"
    );
    $stmt->bind_param('is', $sessionId, $channel);
    $stmt->execute();
    $retired = $stmt->affected_rows;
    $stmt->close();
    return max(0, $retired);
}

/**
 * #1621 — retire (NOT delete) live codes whose `ExpiresAt` has passed, for the
 * sessions that never end cleanly: a shut laptop, a flat battery, a closed tab.
 *
 * ELI5: some services just stop — nobody presses "End". Their code is already
 * refused at the door (every join checks the expiry time), but the database
 * still thinks that code is "taken". This hands those codes back.
 *
 * WHY IT LIVES HERE, PIGGY-BACKED ON A MINT, rather than in a cron job: this
 * project runs on shared hosting and has no scheduler wired to the web app —
 * `appWeb/.sql/cleanup.php` is a CLI maintenance script an operator runs, not a
 * guaranteed heartbeat, and inventing a new cron dependency for ~2 rows per
 * ended service would be the heavier answer. serviceMode_mintCode() already
 * runs every ~30 s for every live session and already ages the rotation window,
 * so it is the natural place: while ANY service is running anywhere on this
 * channel, retirement keeps up on its own; when none is, there is nothing to
 * retire. Bounded by SERVICE_MODE_CODE_RETIRE_LIMIT so one rotate can never
 * become a full-table write.
 *
 * WHY IT IS NOT INSIDE THE MINT TRANSACTION: this statement touches rows owned
 * by OTHER sessions. Run inside serviceMode_mintCode()'s transaction it would
 * hold locks on those rows until commit, and two sessions rotating at the same
 * instant could each hold the row the other wants — a deadlock on a hot path.
 * It is therefore issued BEFORE `begin_transaction()`, as its own autocommit
 * statement, so its locks are released immediately.
 *
 * The `Status IN ('current','previous')` leading predicate is what makes this
 * cheap: `idx_Live_Expiry (Status, ExpiresAt)` turns it into two small index
 * ranges over exactly the live-and-expired rows, instead of walking every
 * long-retired row in `idx_Expiry` order. Deliberately no ORDER BY — the cap is
 * a safety valve, not a fairness policy, and any row missed by one pass is
 * picked up by the next.
 *
 * CHANNEL-FILTERED (rule #26), same correlated-EXISTS shape and same reasoning
 * as serviceMode_retireSessionCodes(). Consequence worth stating: alpha's dead
 * codes are retired by alpha traffic, production's by production traffic — an
 * env with no traffic holds its handful of slots until it next serves a request.
 * That is the correct trade; a cross-channel write from a request handler is the
 * leak class rule #26 exists to prevent.
 *
 * @param string $channel serviceMode_channel() of the CURRENT request.
 * @return int            Rows retired.
 * @throws \mysqli_sql_exception Callers treat this as best-effort — see mintCode().
 */
function serviceMode_retireExpiredCodes(\mysqli $db, string $channel): int
{
    /* Trusted int constants, interpolated (not bound) — same pattern + rationale
       as the $freshness interpolation in resolveJoin() below. MySQL allows LIMIT
       on a SINGLE-table UPDATE only, which is why the channel filter is a
       correlated EXISTS rather than a JOIN. */
    $grace = (int) SERVICE_MODE_CODE_RETIRE_GRACE_SECONDS;
    $cap   = (int) SERVICE_MODE_CODE_RETIRE_LIMIT;
    $live  = serviceMode_codeLiveStatusSql();
    $stmt  = $db->prepare(
        "UPDATE tblLiveFollowJoinCodes AS c
            SET c.Status = 'expired'
          WHERE c.Status IN ({$live})
            AND c.ExpiresAt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$grace} SECOND)
            AND EXISTS (
                  SELECT 1 FROM tblLiveFollowSessions s
                   WHERE s.Id = c.SessionId AND s.Channel = ?
                )
          LIMIT {$cap}"
    );
    $stmt->bind_param('s', $channel);
    $stmt->execute();
    $retired = $stmt->affected_rows;
    $stmt->close();
    return max(0, $retired);
}

/**
 * Transactionally mint the next rotating join code for a session:
 * previous → superseded, current → previous, insert a fresh 'current'. Returns
 * the new code. Locks the session's code rows (FOR UPDATE) so two near-
 * simultaneous rotates (projection setInterval + a reload) can't race to two
 * 'current' rows.
 *
 * #1621 — the retry-on-1062 loop now guards something real. It used to be able
 * to catch ONLY `uq_Session_Code`, i.e. a session colliding with its own past
 * code, which was never the risk worth handling; the dangerous collision — two
 * concurrent services on the same code — was not constrained at all. With
 * `uq_ActiveCode` in place a genuine cross-session clash raises 1062 here and is
 * simply regenerated. 6 attempts remains far more than enough: with a 30^6 ≈
 * 7.3e8 space, even an implausible 10,000 simultaneously-live codes give a
 * ~1.4e-5 chance per attempt, so all six failing is ~1e-29. The
 * RuntimeException below is a should-never-happen guard, not a design margin.
 *
 * @throws \mysqli_sql_exception|\RuntimeException on a real failure (caller rolls the request).
 */
function serviceMode_mintCode(\mysqli $db, int $sessionId): string
{
    /* #1621 — opportunistic expiry retirement, BEFORE the transaction opens so
       it never holds another session's row locks across our commit (see
       serviceMode_retireExpiredCodes()). Best-effort by design: a rotate is the
       operator's live screen refresh, and it must not fail because a housekeeping
       UPDATE did (e.g. lock-wait timeout under load, or the pre-#1621 schema on
       an un-migrated install where `idx_Live_Expiry` doesn't exist yet). */
    try {
        serviceMode_retireExpiredCodes($db, serviceMode_channel());
    } catch (\Throwable $_e) {
        error_log('[service_mode/retireExpiredCodes] ' . $_e->getMessage());
    }

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

        /* Insert a fresh current; retry on ANY 1062 — that is now both the
           session-scoped uq_Session_Code AND, since #1621, the global
           uq_ActiveCode (a genuine cross-session clash). Either way the fix is
           the same: pick another code and try again. */
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
 * whose session is active + fresh (LastHeartbeatAt within
 * LIVE_SESSION_FRESHNESS_SECONDS) resolves.
 *
 * $venueId is OPTIONAL: a congregant typing a code off the screen doesn't know
 * the venue, so pass 0 to resolve by code + channel alone. A QR deep-link can
 * pass a venueId for an exact scope.
 *
 * #1621 — AMBIGUITY IS REFUSED, NEVER GUESSED (defence in depth behind the
 * `uq_ActiveCode` global unique index that now makes it unstorable).
 *
 * ELI5: if a typed code somehow pointed at two different churches' services at
 * once, this used to quietly pick the one that had checked in most recently.
 * That could drop a congregant into a stranger's service. Now it refuses, and
 * the congregant is told to look at the screen for the current code — which is
 * exactly what they should do anyway, and costs them one rotation window.
 *
 * DETAILED — the old code-alone path was `ORDER BY s.LastHeartbeatAt DESC LIMIT
 * 1`: freshest-heartbeat wins. Two concurrent services holding the same code was
 * possible because `uq_Session_Code` is SESSION-scoped, and the consequence was
 * not merely a wrong song list — a presence token minted against the wrong org
 * carries that org's Phase-3 CCLI unlock (serviceMode_presenceCcliNumber()). So
 * the failure mode of guessing is a silent cross-organisation licence grant,
 * while the failure mode of refusing is a retry. We fail in the direction that
 * cannot leak. The query therefore reads LIMIT 2 and returns null if the two
 * rows are different sessions.
 *
 * The VENUE-SCOPED path is deliberately untouched: with a venueId supplied the
 * scope is already exact, and the LIMIT 2 / rows[0] shape returns precisely what
 * `LIMIT 1` returned before. (Two rows for the SAME session cannot occur — that
 * would need two live rows sharing one Code within one session, which
 * `uq_Session_Code` forbids — so the session-id comparison below is belt-and-
 * braces, not the load-bearing part.)
 *
 * @param string|null $failReason OUT: 'code_not_active' or 'ambiguous_code' when
 *                                null is returned, for the caller's server-side
 *                                breadcrumb ONLY. The user-facing message MUST
 *                                stay identical for both — distinguishing them
 *                                would tell a prober "this code exists twice".
 * @return array|null The session row (assoc), or null; opaque messaging is the
 *                    caller's job.
 */
function serviceMode_resolveJoin(\mysqli $db, string $code, int $venueId, string $channel, ?string &$failReason = null): ?array
{
    $failReason = null;
    /* Unified freshness window (was 90s — aligned to Live Follow's 180s, #1386).
       Trusted int constant, interpolated (not a bound value). */
    $freshness = (int) LIVE_SESSION_FRESHNESS_SECONDS;
    $live      = serviceMode_codeLiveStatusSql();
    $stmt = $db->prepare(
        "SELECT s.Id, s.OrgId, s.VenueId, s.ScheduleId, s.OccurrenceDate, s.Channel
           FROM tblLiveFollowJoinCodes c
           JOIN tblLiveFollowSessions s ON s.Id = c.SessionId
          WHERE c.Code = ?
            AND c.Status IN ({$live})
            AND c.ExpiresAt > UTC_TIMESTAMP()
            AND (? = 0 OR s.VenueId = ?)
            AND s.Channel = ?
            AND s.SessionKind = 'service'
            AND s.IsActive = 1
            AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)
          ORDER BY s.LastHeartbeatAt DESC
          LIMIT 2"
    );
    $stmt->bind_param('siis', $code, $venueId, $venueId, $channel);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();

    if (!$rows) {
        $failReason = 'code_not_active';
        return null;
    }
    /* Code-alone (typed) path only — a venue-scoped QR deep-link cannot be
       ambiguous, so it keeps its exact pre-#1621 behaviour. */
    if ($venueId === 0 && count($rows) > 1 && (int)$rows[0]['Id'] !== (int)$rows[1]['Id']) {
        $failReason = 'ambiguous_code';
        return null;
    }
    return $rows[0];
}

/**
 * #1792 — the ONE user-facing message shown when a join code is well-formed and
 * live, but on a DIFFERENT docroot than the one the congregant is on.
 *
 * ELI5: the three iHymns web addresses (the dev site, the preview site, the live
 * site) share one database, but each only "sees" the live sessions started on
 * itself — the Channel wall (rule #26). So the instinctive test — start the
 * session on your laptop's dev URL, join on your phone's live PWA — always fails
 * with "wrong code", which reads as "the feature is broken" when it is actually
 * the wall doing its job. This message turns that dead-end into the fix.
 *
 * DEFINED ONCE (rule #35 — a "keep these two in sync" comment is the bug, not
 * the fix): both `live_follow_join` and `service_join` reference this constant,
 * and `tests/php/test-live-follow-cross-channel.php` asserts they do, so the
 * wording can never drift between the two entry points. Environment-agnostic on
 * purpose — it never names WHICH other environment (that would be a needless
 * extra signal); "same web address" is the actionable instruction.
 */
const SERVICE_MODE_WRONG_CHANNEL_MESSAGE =
    'That code belongs to a different iHymns environment (for example the live '
    . 'site vs a preview). Make sure both devices are using the same web '
    . 'address, then try again.';

/**
 * #1792 — is this join code live on a channel OTHER than the caller's?
 *
 * ELI5: answers the narrow question "does this exact code belong to a DIFFERENT
 * iHymns web address right now?", so a failed join can say "you're on the wrong
 * address" (SERVICE_MODE_WRONG_CHANNEL_MESSAGE) instead of the opaque generic
 * error — the fix for the owner's recurring "can't test Go Live / Join Live"
 * (the desktop-on-dev + phone-on-www case is exactly this).
 *
 * DETAILED — why this does NOT re-open the anti-probe leak (rule #26 I6, the
 * opacity that keeps a prober from learning a CURRENT-channel session's
 * liveness): it returns true ONLY for a code already valid on ANOTHER channel,
 * i.e. the caller demonstrably holds a real code but is on the wrong docroot. It
 * reveals nothing about any session on the CURRENT channel (the thing the
 * opacity protects); it grants no access (that same code would join fine on the
 * correct address); it names no environment/org/venue; and every call sits
 * behind the SAME per-IP join rate limit as its caller (both join endpoints
 * budget the attempt), so it is not a new cheap oracle. A random code-guesser
 * effectively never lands on a code that is live somewhere else, and if one did,
 * the rate limit already bounds that just as it bounds any lucky guess.
 *
 * Covers BOTH live-session families sharing the #1268 spine — Quick / Live
 * Follow (`tblLiveFollowSessions.SessionCode`) and Service Mode
 * (`tblLiveFollowJoinCodes.Code`) — with the SAME active + heartbeat-fresh
 * predicates the real joins use, so a stale code elsewhere does NOT trigger the
 * hint (it falls through to the generic message, correctly).
 *
 * This is an EXISTENCE probe, deliberately NOT a second copy of
 * serviceMode_resolveJoin()'s venue/ambiguity disambiguation (rule #22) — the
 * hint needs only "does a joinable code exist on another channel", never which
 * session it is.
 *
 * @param string $currentChannel  serviceMode_channel() for THIS docroot.
 * @return bool  true iff a fresh, active session/code with this code exists on a
 *               channel != $currentChannel.
 */
function serviceMode_codeOnOtherChannel(\mysqli $db, string $code, string $currentChannel): bool
{
    if ($code === '') { return false; }
    /* Trusted int constant, interpolated (not a bound value) — mirrors
       serviceMode_resolveJoin()'s own freshness interpolation. */
    $freshness = (int) LIVE_SESSION_FRESHNESS_SECONDS;

    /* Quick / Live Follow: the code is the session's own SessionCode. Idle-fresh
       gated exactly like live_follow_join's resolve (#1770 C2), so an
       idle-closed session elsewhere doesn't produce a stale hint. */
    $idleFresh = serviceMode_idleFreshSql($db, 's');
    $q = $db->prepare(
        "SELECT 1
           FROM tblLiveFollowSessions s
          WHERE s.SessionCode = ?
            AND s.Channel <> ?
            AND s.IsActive = 1
            AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)" . $idleFresh . "
          LIMIT 1"
    );
    $q->bind_param('ss', $code, $currentChannel);
    $q->execute();
    $hit = (bool) $q->get_result()->fetch_row();
    $q->close();
    if ($hit) { return true; }

    /* Service Mode: the code is a rotating tblLiveFollowJoinCodes.Code. Same
       live-status + expiry + freshness predicates as serviceMode_resolveJoin(). */
    $live = serviceMode_codeLiveStatusSql();
    $q2 = $db->prepare(
        "SELECT 1
           FROM tblLiveFollowJoinCodes c
           JOIN tblLiveFollowSessions s ON s.Id = c.SessionId
          WHERE c.Code = ?
            AND c.Status IN ({$live})
            AND c.ExpiresAt > UTC_TIMESTAMP()
            AND s.Channel <> ?
            AND s.SessionKind = 'service'
            AND s.IsActive = 1
            AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)
          LIMIT 1"
    );
    $q2->bind_param('ss', $code, $currentChannel);
    $q2->execute();
    $hit2 = (bool) $q2->get_result()->fetch_row();
    $q2->close();
    return $hit2;
}

/**
 * #1406 — cached probe: does `tblServicePresence.Role` exist yet? Gates every
 * Role read/write so `service_join`/`service_poll` stay dormant-safe on an
 * un-migrated install (the column ships via the Phase-2 schema batch,
 * `migrate-apple-phase2-live-schema.php`). Mirrors the established
 * `tierCapsColumnExists()` (access_tier_validation.php) /
 * `musicianMembersTableExists()` (musician_helpers.php) pattern —
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
 * #1668 — cached probe: does the `tblOrganisationLicences` join table (#640)
 * exist on THIS install?
 *
 * ELI5: check the drawer is actually there before opening it, because opening
 * a drawer that does not exist doesn't give you nothing — it knocks the whole
 * cabinet over.
 *
 * WHY: mysqli runs under MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT
 * (db_mysql.php), so preparing a statement against a missing table THROWS. The
 * caller catches Throwable and returns null = "no CCLI grant", which means an
 * unguarded join would have silently REVOKED the working legacy-column unlock
 * on any install where migrate-organisation-licences.php has not been run.
 * Migrations here are web-run, never auto-applied, and three docroots share one
 * MySQL — so that install is a real possibility, not a hypothetical.
 *
 * Mirrors the established one-round-trip memoised pattern in this same file
 * (serviceMode_presenceRoleColumnExists) and across includes/.
 *
 * @param \mysqli $db Live connection.
 * @return bool true when the table exists; false on absence OR probe failure.
 * @link https://www.php.net/manual/en/mysqli-driver.report-mode.php
 */
function serviceMode_orgLicencesTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblOrganisationLicences' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        /* Probe failure — treat as "not migrated", i.e. fall back to the legacy
           query. That preserves today's behaviour exactly; it never widens the
           grant. */
        $cached = false;
    }
    return $cached;
}

/**
 * #1770 C3 — memoised probe: does `tblServicePresence` exist on THIS install?
 * Quick (host) live-follow already hard-depends on the SAME migration batch
 * for `tblLiveFollowSessions.Channel` (the #1405 side-finding fix,
 * api.php:17024) — this table shipped in that same #1335 Phase-2 batch (the
 * plan's documented "prerequisite quirk") — but the presence-minting POST
 * mode added here is new write traffic against it, so it gets its own
 * explicit existence probe rather than assuming the prerequisite silently
 * held. Mirrors serviceMode_orgLicencesTableExists() immediately above.
 */
function serviceMode_presenceTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblServicePresence' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
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
 *
 * SESSION LIVENESS (#1388). `s.IsActive = 1` alone is NOT proof the service is
 * still running — it is a flag an operator sets on start and clears on a clean
 * `service_session_end`. A browser tab closed mid-service, a laptop lid shut, a
 * dead battery: all leave `IsActive = 1` set forever, and with it a standing
 * CCLI unlock for every congregant who ever joined, until their presence row's
 * own ExpiresAt (the occurrence end) catches up.
 *
 * ELI5: "the projector said it started" is not the same as "the projector is
 * still there". We check it has said something recently, too.
 *
 * So this ALSO requires a heartbeat inside LIVE_SESSION_FRESHNESS_SECONDS, the
 * same predicate serviceMode_resolveJoin() already applies when MINTING presence
 * (see :356). Gate-on-read and gate-on-write must agree — a token that could not
 * be minted right now must not keep unlocking content right now.
 *
 * BOTH ORG LICENCE STORES (#1668). Until now this read ONLY the legacy
 * `tblOrganisations.LicenceType/LicenceNumber/LicenceExpiresAt` columns. Every
 * org-licence UI that actually ships — /manage/organisations,
 * /manage/my-organisations and the `org_admin_licence_add/_change/_remove` API
 * actions — writes `tblOrganisationLicences` (#640) instead, so a church that
 * entered its CCLI licence the only way the site offers got NO Phase-3 unlock
 * for its congregation. The query now accepts EITHER store, and prefers the
 * #640 row's LicenceNumber when it is non-empty (that is the record an operator
 * most recently touched). Same consolidation as licences.php branch (f).
 *
 * ⚠️ `tblOrganisationLicences` may not exist (migrations are web-run, never
 * auto-applied, and the three docroots share one MySQL). Under mysqli STRICT a
 * missing table THROWS, and the catch below turns every throw into "no grant" —
 * so adding the join unconditionally would have silently REVOKED the working
 * legacy-column unlock on any un-migrated install. Hence the memoised existence
 * probe and the two explicit query shapes below: the legacy string is preserved
 * byte-for-byte as the fallback.
 *
 * ⚠️ RULE #26: BOTH query shapes carry `p.Channel = ?` and the identical
 * presence → session → organisation join. An un-Channel-filtered query here is
 * the cross-env leak class — a presence token minted on one docroot must never
 * unlock content on another.
 *
 * #1770 C3 (req #3/#6) — HOST-LICENCE BRANCH. When the org-anchored branch
 * above finds no grant, a SECOND branch is tried: does the session's HOST
 * (not the session's org — Quick sessions have `OrgId` hardcoded NULL at
 * create, api.php:17019/17024, so the org branch's join can never match one
 * in the first place) hold a qualifying CCLI licence of their OWN — a
 * personal `CcliNumber`, or a licence from any organisation THEY belong to
 * (ancestry-aware)? This is what lets a Quick "follow my song" leader's
 * followers read gated lyrics with the CCL notice for the life of the
 * session, exactly like a Service-Mode congregant rides their venue org's
 * licence — reusing `getUserEffectiveLicences()` (includes/licences.php:228,
 * already required at the top of this file) rather than growing a second
 * licence resolver.
 *
 * ⚠️ LICENSING FLAG (recorded verbatim, owner-accepted 2026-08-05 per the
 * #1770 analysis §444-459): a Quick session has NO venue and therefore no
 * physical proof-of-presence — unlocking CCLI for anyone holding the join
 * code may exceed the letter of some licences' terms. The owner accepted
 * this basis; the mechanism binds as narrowly as the design allows: an
 * ACTIVE, heartbeat-fresh, IDLE-FRESH host session, an actively-held
 * (unexpired, un-revoked) presence token, live resolution of the host's
 * licence set on EVERY call (no snapshot — a lapsed/removed licence stops
 * unlocking mid-session), and revocation the instant the session ends
 * (leave / idle-prune / create-supersede all revoke presence — #1770 C3,
 * plan §11 landmine 4) or the follower leaves.
 *
 * ⚠️ Per plan §11 landmine 5: this HOST branch is deliberately SECOND and
 * UNCHANGED-FIRST — the org branch above (and its `o.IsActive = 1` owner
 * decision) is tried FIRST, exactly as before this commit, and the host
 * branch runs ONLY when it finds nothing. Reordering these would risk a
 * Quick host's OWN licence silently shadowing a legitimate Service-Mode org
 * grant on some future shared code path.
 */
function serviceMode_presenceCcliNumber(\mysqli $db, string $presenceToken, string $channel): ?string
{
    if (!preg_match('/^[A-Za-z0-9_\-]{43}$/', $presenceToken)) {
        return null;
    }
    /* Same trusted int constant, same interpolation pattern as resolveJoin(). */
    $freshness = (int) LIVE_SESSION_FRESHNESS_SECONDS;
    try {
        if (serviceMode_orgLicencesTableExists($db)) {
            /* BOTH stores. LEFT JOIN so a legacy-column-only org still matches;
               the WHERE's OR is what admits either side. COALESCE+NULLIF prefers
               the #640 row's number, falling back to the legacy column when the
               #640 row left it blank.

               `o.IsActive = 1` is on BOTH arms — owner decision, 2026-07-30:

                 "An inactive organisation, that has [a] CCLI license assigned to
                  it, should not unlock for users associated with that org,
                  seeing as the org is inactive/closed/siezed."

               ELI5: if the church has closed, its licence stops letting its
               people through.

               Detail: this was briefly left off both arms because the legacy
               query never had it and putting it on one arm only would make the
               same org unlock or not DEPENDING ON WHICH STORE its licence
               happened to sit in — undebuggable from an operator's seat. The
               answer was to add it to both, not to omit it from both. That also
               makes this agree with the main resolver, which already filters
               `o.IsActive = 1` in branches (e) and (f) of licences.php — so
               Service Mode and `checkContentAccess()` can no longer disagree
               about the same organisation (rule #35).

               A congregant of a closed org is NOT stranded: the resolver still
               unions a personal CCLI number, a licence from any OTHER active org
               they belong to, and any tier that does not gate copyrighted
               content behind CCLI at all. */
            $sql =
                "SELECT COALESCE(NULLIF(ol.LicenceNumber, ''), o.LicenceNumber) AS LicenceNumber
                   FROM tblServicePresence p
                   JOIN tblLiveFollowSessions s ON s.Id = p.SessionId
                   JOIN tblOrganisations o      ON o.Id = s.OrgId
                   LEFT JOIN tblOrganisationLicences ol
                          ON ol.OrganisationId = o.Id
                         AND ol.LicenceType = 'ccli'
                         AND ol.IsActive = 1
                         AND (ol.ExpiresAt IS NULL OR ol.ExpiresAt > NOW())
                  WHERE p.PresenceToken = ?
                    AND p.IsActive = 1
                    AND p.ExpiresAt > UTC_TIMESTAMP()
                    AND p.Channel = ?
                    AND s.IsActive = 1
                    AND o.IsActive = 1
                    AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)
                    AND (
                          ol.Id IS NOT NULL
                       OR (o.LicenceType = 'ccli'
                           AND (o.LicenceExpiresAt IS NULL OR o.LicenceExpiresAt > NOW()))
                        )
                  LIMIT 1";
        } else {
            /* Pre-#640 install — the legacy-only query. Carries the SAME
               `o.IsActive = 1` as the arm above (owner decision above): the
               whole point of that decision is that an org's liveness, not the
               store its licence sits in, determines whether it unlocks. */
            $sql =
                "SELECT o.LicenceNumber
                   FROM tblServicePresence p
                   JOIN tblLiveFollowSessions s ON s.Id = p.SessionId
                   JOIN tblOrganisations o      ON o.Id = s.OrgId
                  WHERE p.PresenceToken = ?
                    AND p.IsActive = 1
                    AND p.ExpiresAt > UTC_TIMESTAMP()
                    AND p.Channel = ?
                    AND s.IsActive = 1
                    AND o.IsActive = 1
                    AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)
                    AND o.LicenceType = 'ccli'
                    AND (o.LicenceExpiresAt IS NULL OR o.LicenceExpiresAt > NOW())
                  LIMIT 1";
        }
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ss', $presenceToken, $channel);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            $number = (string)($row['LicenceNumber'] ?? '');
            /* Apply the SAME format criterion as includes/licences.php
               (#1668) — in PHP, via the shared helper, rather than as extra
               SQL, so the CCLI format rule keeps living in exactly one place
               (validateCcliNumber()). A blank number still grants (an
               operator may legitimately leave the field empty and the row
               itself is the declaration); a number that is PRESENT but
               malformed does not. */
            if (licenceCcliQualifies($number, false)) {
                return $number;
            }
            /* Org row existed but failed the format check — fall through to
               the #1770 C3 host branch below rather than returning null
               immediately (a malformed org licence number should not hide a
               perfectly good personal one). */
        }

        /* #1770 C3 — host-licence branch (req #3/#6). See this function's
           doc-block for the full design + the licensing-flag note. Reached
           when the org-anchored branch above found nothing (the common case
           for a Quick session, whose OrgId is hardcoded NULL) or a
           malformed org number. Resolves the session's HostUserId — same
           presence-token validity window as the org branch, PLUS
           SessionKind='host', PLUS the #1770 C2 idle-freshness predicate
           (an idle-closed leader must stop unlocking content the same poll
           cycle the session itself goes stale in). serviceMode_idleFreshSql()
           is '' pre-#1770-C1-migration, so this predicate is a no-op on an
           un-migrated install — never a false denial. */
        $idleFreshSql = serviceMode_idleFreshSql($db, 's');
        $hostStmt = $db->prepare(
            "SELECT s.HostUserId
               FROM tblServicePresence p
               JOIN tblLiveFollowSessions s ON s.Id = p.SessionId
              WHERE p.PresenceToken = ?
                AND p.IsActive = 1
                AND p.ExpiresAt > UTC_TIMESTAMP()
                AND p.Channel = ?
                AND s.SessionKind = 'host'
                AND s.IsActive = 1
                AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)
                {$idleFreshSql}
              LIMIT 1"
        );
        $hostStmt->bind_param('ss', $presenceToken, $channel);
        $hostStmt->execute();
        $hostRow = $hostStmt->get_result()->fetch_assoc();
        $hostStmt->close();
        if ($hostRow === null || $hostRow['HostUserId'] === null) {
            return null;
        }
        $hostUserId = (int)$hostRow['HostUserId'];

        /* getUserEffectiveLicences() unions personal + every org the host
           belongs to (ancestry-aware) — includes/licences.php:228 already
           implements the owner's "user account OR one of their
           organisations" wording (analysis §444-459), so this is reuse, not
           a second resolver. Every entry it returns has ALREADY passed
           licenceCcliQualifies()/licenceOrgRowQualifies() internally; the
           explicit re-check below is defence-in-depth, mirroring the
           org-branch's own belt-and-braces re-check above. */
        foreach (getUserEffectiveLicences($hostUserId) as $licence) {
            if (($licence['type'] ?? '') !== 'ccli') {
                continue;
            }
            $hostNumber = (string)($licence['key'] ?? '');
            if (licenceCcliQualifies($hostNumber, false)) {
                return $hostNumber;
            }
        }
        return null;
    } catch (\Throwable $e) {
        /* Optional tables absent / probe failure → no grant (fail closed). */
        error_log('[service_mode/presenceCcli] ' . $e->getMessage());
        return null;
    }
}

/* =============================================================================
 * #1770 C2 — Leader-idle auto-close for Quick (host) sessions (req #2/#5).
 * =============================================================================
 * ELI5: today a Quick "follow my song" session never notices when the leader
 * has actually put the phone down — only a browser closing/crashing kills it,
 * and if that never happens the session (and, once #1770 C3's presence unlock
 * lands, any CCLI grant riding on it) can live forever. This section adds a
 * per-session "how long since a REAL person last drove this?" clock and a
 * cheap way to ask "has that clock run out?" so every place that reads or
 * writes a session can answer consistently.
 *
 * DETAILED — the whole family is additive + column-existence-gated
 * (`tblLiveFollowSessions.LastLeaderSeenAt` / `IdleTimeoutMins`, shipped
 * dormant by #1770 C1). `serviceMode_idleColumnsExist()` is the ONE probe
 * every helper below defers to (mirrors `serviceMode_presenceRoleColumnExists()`
 * a few hundred lines up) so an un-migrated docroot degrades to exactly
 * today's behaviour — no idle enforcement, no extra columns referenced, no
 * STRICT-mode throw. `serviceMode_idleFreshSql()` is the ONE SQL fragment
 * every consumer (create/update/heartbeat/join/poll/prune) appends — nobody
 * hand-types the `TIMESTAMPDIFF(...)` comparison a second time (CLAUDE.md
 * modularity rule; guard G2a in the plan polices this).
 *
 * WHY TWO TIMESTAMPS, NOT ONE. `LastHeartbeatAt` already exists and answers
 * "is the tab still open/backgrounded-but-alive?" (the 180s
 * LIVE_SESSION_FRESHNESS_SECONDS window above) — an automated beat sent every
 * ~30s keeps it fresh with zero human involvement. `LastLeaderSeenAt` answers
 * a DIFFERENT question: "did a human actually DO something recently?".
 * Conflating the two is the exact bug this feature exists to fix — an
 * abandoned-but-still-open tab would heartbeat forever and never be
 * recognised as abandoned. So `LastLeaderSeenAt` is bumped ONLY by genuine
 * interaction: session create, a real `live_follow_update` broadcast (the
 * host changed what's showing — driving IS interaction), or an explicit
 * client-declared `leaderActive:true` flag on the heartbeat (added so
 * reading/scrolling without navigating still counts, once the #1770 C5+
 * client starts sending it) — never the bare automated beat by itself.
 *
 * @see .claude/live-follow-1770-plan.md §3.1, §4.1, §4.2, §5
 * ============================================================================= */

/**
 * App-level fallback when `tblAppSettings.live_follow_idle_timeout_minutes`
 * is unset (the #1406 `SERVICE_MODE_POLL_MS_*` precedent — a freeform
 * tblAppSettings key needs no migration to add or to read).
 */
const LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY  = 'live_follow_idle_timeout_minutes';
const LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES  = 15;
/** `tblUsers.Settings` JSON root key (rule #28 "new prefs are JSON" posture —
 *  a personal preference gets a key, never a new tblUsers column). */
const LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY = 'liveIdleTimeoutMins';
/** §10 S5 — 5..240 minutes; 240 = the existing 4h `ExpiresAt` hard ceiling
 *  (SERVICE_MODE_HARD_CEILING_HOURS above), so "never" is structurally
 *  meaningless and needs no sentinel value. */
const LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES = 5;
const LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES = 240;
/** §4.1 — cap on one opportunistic prune pass, same rationale as
 *  SERVICE_MODE_CODE_RETIRE_LIMIT above (piggy-backed on a hot request path,
 *  must never turn a routine create/join into a long-running write). */
const LIVE_FOLLOW_IDLE_PRUNE_LIMIT = 20;

/**
 * #1770 — memoised probe: do BOTH idle columns exist on
 * `tblLiveFollowSessions` yet? Lockstep on purpose (the rule-#25 gate
 * discipline this codebase already uses for `lyricLinesMirrorPresent()` /
 * `…SyncReady()`) — the prune predicate needs `IdleTimeoutMins` and
 * `LastLeaderSeenAt` together, so a half-applied ALTER (which #1770 C1's
 * single migration transaction makes practically impossible, but a partial
 * manual patch could still produce) must not be treated as "ready".
 *
 * WHY: mysqli runs under MYSQLI_REPORT_STRICT (db_mysql.php), so selecting a
 * missing column THROWS rather than returning nothing — the #1228 lesson.
 * Every caller below goes through this probe rather than assuming the #1770
 * C1 migration has been RUN (schema.sql having the columns is not the same
 * as an install having applied them — migrations are web-run, never
 * auto-applied, rule #19).
 */
function serviceMode_idleColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblLiveFollowSessions'
                AND COLUMN_NAME IN ('LastLeaderSeenAt', 'IdleTimeoutMins')"
        );
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        $cached = ($count === 2);
    } catch (\Throwable $_e) {
        /* Probe failure — degrade as "not migrated yet" (fail closed on the
           columns, fail OPEN on caller behaviour: no idle enforcement,
           exactly today's posture). */
        $cached = false;
    }
    return $cached;
}

/**
 * #1770 — memoised probe: do BOTH org-layer idle columns exist on
 * `tblOrganisations` yet? Same lockstep rationale as
 * serviceMode_idleColumnsExist() — `EnforceIdleTimeout` is only meaningful
 * alongside `LiveIdleTimeoutMins`.
 */
function serviceMode_orgIdleColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblOrganisations'
                AND COLUMN_NAME IN ('LiveIdleTimeoutMins', 'EnforceIdleTimeout')"
        );
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        $cached = ($count === 2);
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * #1770 — THE one idle-freshness SQL fragment. Returns `''` (no-op) on an
 * un-migrated install; otherwise a leading- `AND (...)` clause that reads
 * TRUE for a session that is NOT idle-expired:
 *
 *   IdleTimeoutMins IS NULL           -- legacy row / service session / never stamped
 *   OR LastLeaderSeenAt IS NULL       -- stamped-column but not-yet-seen row
 *   OR TIMESTAMPDIFF(MINUTE, LastLeaderSeenAt, UTC_TIMESTAMP()) < IdleTimeoutMins
 *
 * NULL-SAFETY IS THE DORMANCY (plan §11 landmine 2): a `NOT NULL DEFAULT 15`
 * column would have retroactively idle-closed every in-flight session the
 * instant the migration ran. Both NULL branches read as "never idle" so a
 * legacy/service/un-stamped row is untouched.
 *
 * $alias is a HARDCODED CALL-SITE CONSTANT (never request input) naming
 * either a table alias ('s') or, for an alias-less single-table query, the
 * bare table name itself (MySQL accepts `tablename.column` there) — the
 * regex guard below is defence-in-depth mirroring
 * serviceMode_codeLiveStatusSql()'s treatment of its own trusted constants
 * (rule #5: the only legitimate SQL interpolations are hardcoded source
 * constants or allow-list-validated values — this is the former, checked
 * like the latter).
 *
 * DEVIATION FROM THE PLAN'S LITERAL SIGNATURE: the plan text (§4.1) writes
 * `serviceMode_idleFreshSql(string $alias = 's'): string` with no `$db`
 * parameter — but answering "un-migrated?" requires a live connection to
 * probe INFORMATION_SCHEMA (serviceMode_idleColumnsExist() takes `\mysqli`,
 * as every other existence probe in this file does), so `$db` is added as
 * the first parameter here. Every call site in this commit passes it.
 *
 * @param \mysqli $db
 * @param string  $alias Table alias or bare table name to qualify columns with.
 * @return string '' pre-migration, else ' AND (...)' ready to append to a WHERE
 *                clause or nest inside a boolean expression.
 */
function serviceMode_idleFreshSql(\mysqli $db, string $alias = 's'): string
{
    if (!serviceMode_idleColumnsExist($db)) {
        return '';
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $alias)) {
        throw new \RuntimeException('Invalid SQL alias literal: ' . var_export($alias, true));
    }
    return " AND ({$alias}.IdleTimeoutMins IS NULL OR {$alias}.LastLeaderSeenAt IS NULL"
         . " OR TIMESTAMPDIFF(MINUTE, {$alias}.LastLeaderSeenAt, UTC_TIMESTAMP()) < {$alias}.IdleTimeoutMins)";
}

/**
 * #1770 §5 — THE idle-timeout precedence resolver: app default → org layer →
 * user layer → an org's ENFORCED override, in that priority. Called ONCE, at
 * `live_follow_create`, and the result is STAMPED onto the new row
 * (`IdleTimeoutMins`) — every gate/prune predicate afterwards reads the
 * stamped column, never re-resolves this chain per-row (§3.1's "stamp-at-
 * create, not resolve-at-read" design: an admin changing the app default
 * mid-session affects the NEXT session, not running ones).
 *
 * ELI5: "how long can this leader go quiet before we close their session?" —
 * ask their church first (if the church has LOCKED an answer, that wins,
 * picking the strictest if they belong to several); otherwise ask the
 * leader's own preference; otherwise fall back to whatever their church
 * suggests as a default; otherwise use the site-wide default.
 *
 * PRECEDENCE (owner's formula, analysis §491): `enforced ?? (user ?? orgDefault ?? appDefault)`.
 *   1. App default   — `getAppSetting('live_follow_idle_timeout_minutes')`,
 *      falling back to LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES (15).
 *   2. Org layer      — every ACTIVE org the user belongs to
 *      (`tblOrganisationMembers` ⋈ `tblOrganisations WHERE IsActive = 1`)
 *      with a non-NULL `LiveIdleTimeoutMins`. Any org with
 *      `EnforceIdleTimeout = 1` contributes to $enforced (the MINIMUM such
 *      value wins outright across a multi-org user — most-restrictive,
 *      deterministic, fails toward safety, §10 S3); every other org
 *      contributes to $orgDefault (again the minimum).
 *   3. User layer     — `tblUsers.Settings` JSON root key
 *      `liveIdleTimeoutMins` (an int > 0; anything else is ignored).
 *   4. Resolution      — `$enforced ?? ($user ?? ($orgDefault ?? $appDefault))`,
 *      clamped to [LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES,
 *      LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES].
 *
 * Column-existence-gated at both layers (serviceMode_orgIdleColumnsExist(),
 * and the user layer simply reads a JSON key that is absent pre-#1770 UI —
 * absence is not an error, just "no preference set"). Every DB read here is
 * wrapped: a resolver failure must never break session creation, so any
 * \Throwable degrades that ONE layer to "not set" rather than propagating.
 *
 * @param \mysqli $db
 * @param int     $userId The host creating the session (>0 — Quick sessions
 *                         always have an authenticated HostUserId).
 * @return int Resolved minutes, clamped to the app-wide [5, 240] band.
 */
function serviceMode_resolveIdleTimeoutMins(\mysqli $db, int $userId): int
{
    /* --- 1. App default ---------------------------------------------- */
    $appDefault = LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES;
    if (function_exists('getAppSetting')) {
        $raw = getAppSetting(LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY, (string)$appDefault);
        if ($raw !== null && is_numeric($raw) && (int)$raw > 0) {
            $appDefault = (int)$raw;
        }
    }

    /* --- 2. Org layer (enforced + default), minimum-wins -------------- */
    $enforced   = null;
    $orgDefault = null;
    if ($userId > 0 && serviceMode_orgIdleColumnsExist($db)) {
        try {
            $stmt = $db->prepare(
                'SELECT o.LiveIdleTimeoutMins AS Mins, o.EnforceIdleTimeout AS Enforce
                   FROM tblOrganisationMembers m
                   JOIN tblOrganisations o ON o.Id = m.OrgId
                  WHERE m.UserId = ? AND o.IsActive = 1 AND o.LiveIdleTimeoutMins IS NOT NULL'
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $r) {
                $mins = (int)$r['Mins'];
                if ($mins <= 0) {
                    continue;
                }
                if ((int)$r['Enforce'] === 1) {
                    $enforced = ($enforced === null) ? $mins : min($enforced, $mins);
                } else {
                    $orgDefault = ($orgDefault === null) ? $mins : min($orgDefault, $mins);
                }
            }
        } catch (\Throwable $_e) {
            /* Fail-open: treat as "no org layer" — never break create. */
        }
    }

    /* --- 3. User layer -------------------------------------------------- */
    $userVal = null;
    if ($userId > 0) {
        try {
            $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $rawSettings = $row['Settings'] ?? null;
            $arr = (is_string($rawSettings) && $rawSettings !== '') ? json_decode($rawSettings, true) : null;
            if (is_array($arr) && isset($arr[LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY])
                && is_numeric($arr[LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY])) {
                $candidate = (int)$arr[LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY];
                if ($candidate > 0) {
                    $userVal = $candidate;
                }
            }
        } catch (\Throwable $_e) {
            /* Fail-open: treat as "no user preference set". */
        }
    }

    /* --- 4. Resolve + clamp --------------------------------------------- */
    $resolved = $enforced ?? ($userVal ?? ($orgDefault ?? $appDefault));
    return max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, $resolved));
}

/**
 * #1770 §4.1 — opportunistic, best-effort prune of Quick (host) sessions that
 * have gone idle-expired. Piggy-backed on `live_follow_create` /
 * `live_follow_join` (both run whenever Quick is in use at all; this host has
 * no cron wired to the web app — the SAME rationale as
 * serviceMode_retireExpiredCodes() above), capped at
 * LIVE_FOLLOW_IDLE_PRUNE_LIMIT so a routine create/join can never become a
 * long-running write, and CHANNEL-FILTERED (rule #26) so alpha traffic only
 * ever prunes alpha sessions.
 *
 * ELI5: every so often, while somebody is starting or joining a Quick
 * session anyway, take a quick look for any OTHER Quick sessions on this
 * environment whose leader clearly wandered off — and quietly close them.
 *
 * DETAILED — for each idle-expired `SessionKind = 'host'` row found:
 *   1. `IsActive = 0` (re-checked with `AND IsActive = 1` so two concurrent
 *      prune passes can't double-count one session).
 *   2. Revoke that session's `tblServicePresence` rows (`IsActive = 0` by
 *      `SessionId`) — the instant-stop half of the #1770 C3 host-CCLI
 *      mitigation. Harmless here in isolation (nothing mints Quick presence
 *      rows until C3 lands), but the prune must already do this so C3 does
 *      not have to touch this function again — see plan §11 landmine 4:
 *      "presence revocation must be on EVERY session-death path".
 *   3. `liveActivitySessionPush($db, $id, 'end')` (best-effort, mirrors
 *      every other session-end path in this file) so a native app's Live
 *      Activity doesn't sit stuck "live" forever.
 *
 * Wrapped in ONE try/catch, matching serviceMode_retireExpiredCodes()'s
 * posture: a prune failure (lock contention, a transient DB hiccup) must
 * NEVER fail the create/join it rides in on.
 *
 * @param \mysqli $db
 * @param string  $channel serviceMode_channel() of the CURRENT request.
 * @return int Sessions pruned (0 is normal).
 */
function serviceMode_pruneIdleQuickSessions(\mysqli $db, string $channel): int
{
    if (!serviceMode_idleColumnsExist($db)) {
        return 0;
    }
    try {
        $cap = (int) LIVE_FOLLOW_IDLE_PRUNE_LIMIT;
        $stmt = $db->prepare(
            "SELECT Id FROM tblLiveFollowSessions
              WHERE SessionKind = 'host' AND IsActive = 1 AND Channel = ?
                AND IdleTimeoutMins IS NOT NULL AND LastLeaderSeenAt IS NOT NULL
                AND TIMESTAMPDIFF(MINUTE, LastLeaderSeenAt, UTC_TIMESTAMP()) >= IdleTimeoutMins
              LIMIT {$cap}"
        );
        $stmt->bind_param('s', $channel);
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Id'));
        $stmt->close();

        if (!$ids) {
            return 0;
        }

        require_once __DIR__ . DIRECTORY_SEPARATOR . 'live_activity_push.php';

        $pruned = 0;
        foreach ($ids as $id) {
            $upd = $db->prepare("UPDATE tblLiveFollowSessions SET IsActive = 0 WHERE Id = ? AND IsActive = 1");
            $upd->bind_param('i', $id);
            $upd->execute();
            $changed = $upd->affected_rows;
            $upd->close();
            if ($changed <= 0) {
                continue; /* Already closed by a concurrent pass. */
            }
            $pruned++;

            $rev = $db->prepare('UPDATE tblServicePresence SET IsActive = 0 WHERE SessionId = ?');
            $rev->bind_param('i', $id);
            $rev->execute();
            $rev->close();

            if (function_exists('liveActivitySessionPush')) {
                liveActivitySessionPush($db, $id, 'end');
            }
        }
        return $pruned;
    } catch (\Throwable $e) {
        error_log('[service_mode/pruneIdleQuickSessions] ' . $e->getMessage());
        return 0;
    }
}

/* =============================================================================
 * #1770 C4 — ONE broadcast core + server-side section resolution (req #4/#7).
 * =============================================================================
 * ELI5: whatever tells iHymns "show THIS song, at THIS point" — the
 * Service-Projection console, a co-leader's phone, or now an external
 * presentation-app driver hitting `service_drive` — needs to write the SAME
 * three columns the SAME way every time, or two of those writers could
 * quietly disagree about what "broadcasting" means. This section pulls that
 * ONE write out of `service_broadcast` into a function so every future
 * writer calls it instead of copying it (rule #26 I4 / CLAUDE.md modularity
 * rule; plan guard G4 tree-derives every writer of these two columns and
 * asserts the set never grows beyond this core + `live_follow_update`'s own,
 * deliberately-separate, code-scoped writer).
 *
 * @see .claude/live-follow-1770-plan.md §4.4
 * ============================================================================= */

/**
 * THE ONE broadcast write. Sets `CurrentSongId` / `CurrentComponentIndex` /
 * `StateJson`, bumps `StateRevision`, touches `LastHeartbeatAt` (a broadcast
 * IS the operator's heartbeat — no separate beat needed the same instant),
 * pushes a Live Activity 'update', and returns the POST-write revision.
 *
 * Extracted verbatim from `service_broadcast` (api.php) — same SQL text,
 * same statement order — so the extraction is a fixture-diffed no-op for
 * every existing caller; `service_drive` (§4.5) is simply the SECOND caller.
 *
 * DELIBERATELY NOT the same function `live_follow_update` uses: that
 * endpoint's WHERE is `SessionCode + HostUserId` (not a bare `Id`) and it
 * ALSO rolls `ExpiresAt` — folding it in would change semantics for a
 * different session KIND. Noted as a possible future convergence, not
 * forced here (plan §4.4).
 *
 * @param \mysqli     $db
 * @param int         $sessionId
 * @param string|null $songId
 * @param int|null    $componentIndex
 * @param string|null $stateJson Pre-cleaned via serviceMode_cleanState() —
 *                                this function does NOT re-validate it.
 * @return int The StateRevision AFTER this write.
 */
function serviceMode_applyBroadcast(\mysqli $db, int $sessionId, ?string $songId, ?int $componentIndex, ?string $stateJson): int
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'live_activity_push.php';

    $upd = $db->prepare(
        'UPDATE tblLiveFollowSessions
            SET CurrentSongId = ?, CurrentComponentIndex = ?, StateJson = ?,
                StateRevision = StateRevision + 1, LastHeartbeatAt = UTC_TIMESTAMP()
          WHERE Id = ?'
    );
    $upd->bind_param('sisi', $songId, $componentIndex, $stateJson, $sessionId);
    $upd->execute();
    $upd->close();

    $rev = $db->prepare('SELECT StateRevision FROM tblLiveFollowSessions WHERE Id = ?');
    $rev->bind_param('i', $sessionId);
    $rev->execute();
    $revision = (int)($rev->get_result()->fetch_assoc()['StateRevision'] ?? 0);
    $rev->close();

    if (function_exists('liveActivitySessionPush')) {
        liveActivitySessionPush($db, $sessionId, 'update');
    }
    return $revision;
}

/**
 * #1770 — the closed section-label vocabulary `serviceMode_resolveSectionIndex()`
 * folds a free-text driver label through. VARCHAR-style app vocab (rule #20 —
 * a growable list, never an ENUM); every value here is a member of the SAME
 * component-type vocabulary the editor/importers already use
 * (`_BULK_IMPORT_IHYMNS_COMPONENT_TYPES`, includes/song_importers.php) so a
 * type this map resolves to always matches a real stored component `type`.
 * Single-letter aliases mirror the importers' own free-text parsers
 * (includes/song_importers.php's `_bulkImport_componentTypeFor`-family
 * helpers) rather than inventing a third shorthand convention.
 */
const SERVICE_MODE_SECTION_LABEL_MAP = [
    'v' => 'verse',       'verse'     => 'verse',
    'c' => 'chorus',      'chorus'    => 'chorus',
    'r' => 'refrain',     'refrain'   => 'refrain',
    'interlude'           => 'refrain',
    'vamp'                => 'refrain',
    'b' => 'bridge',      'bridge'    => 'bridge',
    'i' => 'intro',       'intro'     => 'intro',
    'o' => 'outro',       'outro'     => 'outro',
    't' => 'outro',       'tag'       => 'outro',
    'ending'              => 'outro', 'coda' => 'outro',
    'p' => 'pre-chorus',  'prechorus' => 'pre-chorus', 'pre-chorus' => 'pre-chorus',
];

/**
 * #1798 — PURE parse of a driver's free-text section label into a normalised
 * word-key + optional trailing number, tolerant of the SPACE form ("Pre Chorus
 * 2", which some presentation tools emit) that the old letters-only regex in
 * serviceMode_resolveSectionIndex() rejected outright.
 *
 * Extracted pure (no DB) so `tests/php/test-service-section-resolve.php` can
 * prove the parse without a song fixture (rule #34). resolveSectionIndex() is
 * the ONE caller; it matches `wordKey` against the song's stored component types
 * (exact first, coarse-fold fallback — see there), which is what lets a section
 * stored as `tag`/`coda`/`interlude`/`vamp` be addressed by its own name.
 *
 * @param string $sectionRef Free-text label from the driver payload.
 * @return array{word:string,wordKey:string,num:?int}|null null when unparseable.
 */
function serviceMode_sectionLabelParse(string $sectionRef): ?array
{
    $sectionRef = trim($sectionRef);
    if ($sectionRef === '') {
        return null;
    }
    /* Split an optional TRAILING integer from the label; the label itself may
       carry interior spaces/hyphens ("Pre Chorus", "pre-chorus"). */
    if (!preg_match('/^(.*?)\s*(\d*)\s*$/', $sectionRef, $m)) {
        return null;
    }
    $word = strtolower(trim($m[1]));
    $num  = ($m[2] !== '') ? (int)$m[2] : null;
    if ($word === '') {
        $word = 'verse'; /* documented default — a bare number means a verse */
    }
    /* Fold spaces + hyphens away so "Pre Chorus" ≡ "pre-chorus" ≡ "prechorus". */
    $wordKey = (string)preg_replace('/[\s\-]+/', '', $word);
    if ($wordKey === '') {
        return null;
    }
    return ['word' => $word, 'wordKey' => $wordKey, 'num' => $num];
}

/**
 * #1770 — memoised probe: does `tblSongs.ArrangementJson` (#892) exist on
 * THIS install? Mirrors `SongData::_hasArrangementColumn()`'s pattern
 * (private to that class — this is the standalone equivalent for
 * `service_mode.php`, which has no SongData instance to hand).
 */
function serviceMode_songsArrangementColumnExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongs'
                AND COLUMN_NAME  = 'ArrangementJson' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Project a song's components into RENDER order — the SAME order
 * `song.php` renders `.lyric-component` and `js/modules/service-broadcast.js`'s
 * `_projectSections()` computes client-side (#160 arrangement-aware
 * rendering): when `tblSongs.ArrangementJson` (#892) is present and
 * non-empty, render order = `arrangement.map(i => components[i])`,
 * dropping any out-of-range ordinal; otherwise the raw component order.
 * Column-existence-gated + fully wrapped — any failure degrades to the raw
 * (un-arranged) component order rather than throwing, since an
 * unresolvable section reference is meant to fall back to song-level only
 * (owner req #7), never to break the broadcast.
 *
 * @param array<int, array<string,mixed>> $components From
 *        lyricLinesAssembleComponents() — raw component order.
 * @return array<int, array<string,mixed>> Render-order-projected components.
 */
function serviceMode_projectArrangement(\mysqli $db, string $songId, array $components): array
{
    if (!$components || !serviceMode_songsArrangementColumnExists($db)) {
        return $components;
    }
    try {
        /* @disabled-visible: broadcaster gate-elsewhere exemption (#1765) —
           this is a metadata lookup (the running order) for a SongId the
           CALLER has already resolved + visibility/servability-checked via
           songVisibleSql()/songServableSql() before it was ever accepted
           into a broadcast (service_broadcast / service_drive both do this
           up front). A disabled/hidden song can never reach this line in
           the first place, so re-applying the predicate here would only add
           a redundant query, not change any outcome.
           @deleted-visible: same reasoning, one predicate over (#1694) — the
           same up-front songVisibleSql()/songServableSql() check the
           broadcaster gate already ran also excludes soft-deleted songs, so
           a deleted SongId can no more reach this lookup than a
           disabled-songbook one can. */
        $stmt = $db->prepare('SELECT ArrangementJson FROM tblSongs WHERE SongId = ?');
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $raw = $row['ArrangementJson'] ?? null;
        if ($raw === null || $raw === '') {
            return $components;
        }
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded) || !$decoded) {
            return $components;
        }
        $projected = [];
        foreach ($decoded as $ord) {
            if (!is_int($ord) && !(is_string($ord) && ctype_digit($ord))) {
                continue;
            }
            $idx = (int)$ord;
            if (isset($components[$idx])) {
                $projected[] = $components[$idx];
            }
        }
        /* An arrangement that resolves to NOTHING (every ordinal out of
           range — e.g. stale after a component delete) falls back to the
           raw order rather than handing the caller an empty list. */
        return $projected ?: $components;
    } catch (\Throwable $_e) {
        return $components;
    }
}

/**
 * #1770 §4.4 (req #7) — resolve an external driver's free-text section
 * label ("Verse 2" / "V2" / "Chorus" / "C" / "Bridge" / a bare "2") to the
 * 0-based RENDER-ORDER index the congregant client already scrolls to
 * (`service-follow.js`'s `_scrollToComponent`, nth-child over
 * `.lyric-component`) — i.e. exactly what `componentIndex` means everywhere
 * else in this file.
 *
 * ELI5: a presentation-app operator says "Verse 2" out loud (or a shim
 * sends that string) — this works out WHICH numbered slot on the
 * congregant's screen that actually is, accounting for the song's own
 * custom running order.
 *
 * PARSING (documented default, since the plan intentionally left this
 * underspecified — see the caller's own doc-block for the flagged
 * assumption): `/^([a-zA-Z\-]*)\s*(\d*)\s*$/` splits a leading word from a
 * trailing number. A BARE number ("2", no word) defaults to type
 * `'verse'` — the common case for a plain numbered slide deck and the same
 * default the free-text bulk importer already uses
 * (`song_importers.php`'s numbered-line parser, ~:3613). An unrecognised
 * word (fails `SERVICE_MODE_SECTION_LABEL_MAP`) is UNRESOLVABLE → null, per
 * owner req #7's "song-level stays the fallback" — never a guess.
 *
 * NUMBER MATCHING, in order: (1) an EXACT match against the component's own
 * stored `number` field; (2) failing that, the Nth (1-based) occurrence of
 * that TYPE in render order — because not every import populates `number`
 * consistently (a song with one unnumbered chorus commonly stores
 * `number = 0`), so a driver saying "Chorus 1" must still resolve against a
 * song whose only chorus is stored as `number = 0`. No number at all →
 * the FIRST occurrence of that type.
 *
 * @param \mysqli $db
 * @param string  $songId
 * @param string  $sectionRef Free-text label from the driver payload.
 * @return int|null 0-based render-order index, or null when unresolvable
 *                   (the caller applies song-level only — owner req #7).
 */
function serviceMode_resolveSectionIndex(\mysqli $db, string $songId, string $sectionRef): ?int
{
    $songId     = trim($songId);
    $sectionRef = trim($sectionRef);
    if ($songId === '' || $sectionRef === '') {
        return null;
    }
    /* #1798 — parse via the shared pure helper so the space form ("Pre Chorus
       2") is handled identically here and in the guard. Returns the folded
       word-key + optional trailing number, or null when unparseable. */
    $parsed = serviceMode_sectionLabelParse($sectionRef);
    if ($parsed === null) {
        return null;
    }
    $wordKey = $parsed['wordKey'];   /* lower-cased, spaces/hyphens stripped: "prechorus", "tag", "verse" */
    $num     = $parsed['num'];

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    try {
        $components = lyricLinesAssembleComponents($db, $songId);
    } catch (\Throwable $_e) {
        return null;
    }
    if (!$components) {
        return null;
    }
    $ordered = serviceMode_projectArrangement($db, $songId, $components);

    /* #1798 — two-pass type resolution (was single-pass, which folded the
       INCOMING label to a coarse type and matched that against the RAW stored
       type — so a component stored as `tag`/`coda`/`interlude`/`vamp` could
       never be addressed by its own name, since the map folds tag→outro etc.).
       PASS 1: exact stored-type match (own-name addressing — the fix). PASS 2:
       only if the label named no stored type directly, fall back to the coarse
       SERVICE_MODE_SECTION_LABEL_MAP alias fold, matching a component whose OWN
       type also folds to the same coarse bucket (so `o`/`outro`/`ending` still
       resolve, and a coarse alias can still reach a fine-grained section when no
       literal one exists). $normType strips spaces/hyphens so "pre-chorus" ≡
       "prechorus". */
    $normType = static fn(string $s): string => (string)preg_replace('/[\s\-]+/', '', strtolower($s));

    $ofType = [];
    foreach ($ordered as $idx => $c) {
        if ($normType((string)($c['type'] ?? '')) === $wordKey) {
            $ofType[] = ['idx' => (int)$idx, 'number' => (int)($c['number'] ?? 0)];
        }
    }
    if (!$ofType) {
        $coarse = SERVICE_MODE_SECTION_LABEL_MAP[$wordKey] ?? null;
        if ($coarse === null) {
            return null; /* unrecognised label AND not a stored type — unresolvable, not a guess */
        }
        foreach ($ordered as $idx => $c) {
            $ctype = $normType((string)($c['type'] ?? ''));
            if ($ctype === $normType($coarse) || (SERVICE_MODE_SECTION_LABEL_MAP[$ctype] ?? null) === $coarse) {
                $ofType[] = ['idx' => (int)$idx, 'number' => (int)($c['number'] ?? 0)];
            }
        }
    }
    if (!$ofType) {
        return null;
    }
    if ($num === null) {
        return $ofType[0]['idx'];
    }
    foreach ($ofType as $entry) {
        if ($entry['number'] === $num) {
            return $entry['idx'];
        }
    }
    if ($num >= 1 && $num <= count($ofType)) {
        return $ofType[$num - 1]['idx'];
    }
    return null;
}
