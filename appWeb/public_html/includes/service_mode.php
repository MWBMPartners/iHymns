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
 */
function serviceMode_presenceCcliNumber(\mysqli $db, string $presenceToken, string $channel): ?string
{
    if (!preg_match('/^[A-Za-z0-9_\-]{43}$/', $presenceToken)) {
        return null;
    }
    /* Same trusted int constant, same interpolation pattern as resolveJoin(). */
    $freshness = (int) LIVE_SESSION_FRESHNESS_SECONDS;
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
                AND s.LastHeartbeatAt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$freshness} SECOND)
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
