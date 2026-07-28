<?php

declare(strict_types=1);

/**
 * iHymns — Live Activities push bridge (#1429, Apple Phase-2 PR-16)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: on an iPhone, a "Live Activity" is the little card that sits on the
 * Lock Screen / Dynamic Island showing "what's happening right now" (think
 * a sports score or a delivery tracker). For iHymns that card shows the
 * song currently on-screen during a Live Follow or Service Mode broadcast.
 * Apple requires that card to be updated by a PUSH from our server — the
 * phone can't just poll for it in the background. This file is the ONE
 * place that turns "the operator just changed the song / ended the
 * session" into "tell Apple to update every phone showing that card".
 *
 * DETAILED: this module is the FIRST caller of `includes/apns.php`'s
 * `apnsSend()` (#1410, shipped dormant with no consumer). It is invoked by
 * `api.php`'s six broadcast/end case bodies (`live_follow_update`,
 * `service_broadcast`, `live_follow_leave`, `service_session_end`, and the
 * `live_follow_create` / `service_session_start` "supersede the old active
 * session" paths) via the single entry point `liveActivitySessionPush()`.
 *
 * DORMANCY (rule #19/#28 convention, mirrors `apns.php`'s own header): every
 * call is a verified no-op until BOTH (a) `tblApnsTokens` exists AND holds
 * at least one `Kind='liveActivity'` row for the session, AND (b)
 * `apnsConfigured()` is true (an admin has pasted a real Apple-issued APNs
 * key via #1429 C4's `manage/configuration.php` card). Absent either, this
 * returns immediately having done at most one cheap indexed SELECT — no
 * network call, no write. `liveActivitySessionPush()` ALSO never throws:
 * every call site in `api.php` runs it fire-and-forget, so a bug or a
 * transient Apple-side failure here must never turn a successful
 * broadcast/end write into a 500 for the operator. All failures — a
 * decode error, an unexpected DB shape, `apnsSend()` itself erroring — are
 * swallowed and `error_log()`-ed, exactly like `_apnsPruneToken()` and every
 * other best-effort helper in `apns.php` does.
 *
 * TWO KINDS OF FUNCTIONS IN THIS FILE:
 *   - PURE (no DB, no network, no superglobals): `liveActivityPush_
 *     resolveDisplayState()`, `liveActivityPush_contentState()`,
 *     `liveActivityPush_apsPayload()`. These build the exact JSON shapes
 *     Apple's push payload and the native `ActivityContentState` need —
 *     tested directly, with a byte-matched fixture, by
 *     `tests/php/test-apns-live-activity-push.php`
 *     (`appApple/Packages/iHymnsKit/Tests/Fixtures/
 *     live_activity_content_state.json` is the SAME file a Swift decode
 *     test reads, so the two sides can never silently drift apart).
 *   - THE HOOK: `liveActivitySessionPush()` — the only function `api.php`
 *     calls; everything else here is a private implementation detail of it.
 *
 * CONTENT-STATE SHAPE (mirrors the native `LiveBroadcastState.
 * resolvedDisplayState` — see the Swift side of #1429 for the counterpart):
 * `displayState` folds the LEGACY boolean `blank` and the #1405 v2
 * `displayState` string into ONE of `SERVICE_DISPLAY_STATES`
 * (`live`/`blackout`/`logo`) exactly the way `serviceMode_cleanState()`
 * already does for the broadcast WRITE path (`service_mode.php`) — this is
 * the READ-side mirror of that same fold, so a Live Activity never shows a
 * state the projector/follower screens disagree with.
 *
 * SECURITY / PRIVACY: `liveActivitySessionPush()` channel-scopes its own
 * session SELECT (`s.Channel = serviceMode_channel()`, rule #26) so an
 * alpha-only session can never push to a token that (somehow) named a
 * beta/production session id. It NEVER logs a push token (mirrors
 * `apns.php`'s own discipline) — only `Kind`/`ApnsEnv`/`SessionId` ever
 * reach `apnsSend()`'s own (token-free) breadcrumbing.
 *
 * @link https://developer.apple.com/documentation/activitykit/starting-and-updating-live-activities-with-activitykit-push-notifications  Apple's Live Activity push payload shape (aps.event/content-state/stale-date/dismissal-date)
 * @see includes/apns.php          apnsSend() / apnsConfigured() / apnsTokensTableExists() — the transport this hooks into
 * @see includes/service_mode.php  serviceMode_channel() / SERVICE_DISPLAY_STATES — the channel guard + display-state vocabulary this mirrors
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'apns.php';

/**
 * Fold a broadcast session's raw `StateJson` blob down to ONE of the three
 * `SERVICE_DISPLAY_STATES` — PURE (no DB, no network).
 *
 * ELI5: the operator's screen can be "showing the song", "blacked out", or
 * "showing the church logo" — this reads the session's saved state and
 * decides which of those three the Live Activity card should show right
 * now, even for an OLDER client that only ever wrote the legacy `blank`
 * boolean and never learned about the newer three-state field.
 *
 * DETAILED: mirrors `serviceMode_cleanState()`'s write-side `displayState`↔
 * `blank` bridge (service_mode.php) on the READ side. Preference order:
 * (1) a recognised `displayState` string wins outright; (2) else a present
 * `blank` boolean derives `blackout`/`live`; (3) else (absent/undecodable/
 * garbage JSON) defaults to `live` — fail toward "showing the last known
 * song", the least surprising default for a card nobody has touched yet.
 *
 * @param string|null $stateJson Raw `tblLiveFollowSessions.StateJson` (a
 *                                JSON object string, or null/empty).
 * @return string One of `SERVICE_DISPLAY_STATES` ('live'|'blackout'|'logo').
 */
function liveActivityPush_resolveDisplayState(?string $stateJson): string
{
    if ($stateJson === null || $stateJson === '') {
        return 'live';
    }
    $decoded = json_decode($stateJson, true);
    if (!is_array($decoded)) {
        return 'live';
    }

    if (isset($decoded['displayState']) && is_string($decoded['displayState'])
        && in_array($decoded['displayState'], SERVICE_DISPLAY_STATES, true)) {
        return $decoded['displayState'];
    }

    if (array_key_exists('blank', $decoded)) {
        return ((bool)$decoded['blank']) ? 'blackout' : 'live';
    }

    return 'live';
}

/**
 * Build the native `ActivityContentState` payload — PURE (no DB, no
 * network). Every field is OPTIONAL on the Swift side except `displayState`/
 * `revision`/`isLive`, so a null input is OMITTED entirely rather than
 * encoded as a JSON `null` — this keeps the wire shape identical to what a
 * hand-authored Swift `Codable` struct with optional `let` properties would
 * naturally decode (an absent key leaves the Swift optional `nil`; an
 * explicit JSON `null` would too, but omitting is the smaller payload over
 * a push notification's tight size budget).
 *
 * ELI5: this is "what should the little Lock Screen card say right now" —
 * which song, which line/verse, is it live or blacked out, and a revision
 * number so the phone can tell a stale push from a fresh one.
 *
 * @param string|null $songId          `tblLiveFollowSessions.CurrentSongId` (or null).
 * @param string|null $songTitle       The song's `tblSongs.Title` (or null — unknown song / none playing).
 * @param int|null    $componentIndex  `tblLiveFollowSessions.CurrentComponentIndex` (or null).
 * @param string|null $stateJson       Raw `StateJson` — folded via `liveActivityPush_resolveDisplayState()`.
 * @param int         $revision        `tblLiveFollowSessions.StateRevision` at push time.
 * @param bool        $isLive          False only for the terminal 'end' push.
 * @return array{songId?:string,songTitle?:string,componentIndex?:int,displayState:string,revision:int,isLive:bool}
 */
function liveActivityPush_contentState(
    ?string $songId,
    ?string $songTitle,
    ?int $componentIndex,
    ?string $stateJson,
    int $revision,
    bool $isLive
): array {
    $state = [];
    if ($songId !== null) {
        $state['songId'] = $songId;
    }
    if ($songTitle !== null) {
        $state['songTitle'] = $songTitle;
    }
    if ($componentIndex !== null) {
        $state['componentIndex'] = $componentIndex;
    }
    $state['displayState'] = liveActivityPush_resolveDisplayState($stateJson);
    $state['revision']     = $revision;
    $state['isLive']       = $isLive;
    return $state;
}

/**
 * Wrap a content-state array in Apple's `aps` push-payload envelope — PURE
 * (no DB, no network). See the Apple docs linked in this file's header for
 * the exact shape: `timestamp`/`event`/`content-state`/`stale-date` are
 * always present; `dismissal-date` is added ONLY for the terminal `'end'`
 * event (it tells the OS when to remove the card from the Lock Screen /
 * Dynamic Island — omitting it on an 'end' push would leave a stale card
 * on-screen until the OS's own timeout).
 *
 * ELI5: this is the envelope Apple's push server expects — "here's the
 * timestamp, whether this is an update or the final goodbye, the actual
 * card content, and (for the goodbye) when to make the card disappear".
 *
 * @param array       $contentState The `liveActivityPush_contentState()` output.
 * @param string      $event        'update' | 'end' — anything else THROWS.
 * @param int         $now          Injected unix time (testability, mirrors `_apnsBuildJwt()`'s `$now` param).
 * @param int|null    $dismissAt    Optional explicit dismissal unix time for 'end'; defaults to `$now`.
 * @return array{aps:array{timestamp:int,event:string,'content-state':array,'stale-date':int,'dismissal-date'?:int}}
 * @throws \InvalidArgumentException When `$event` isn't 'update' or 'end'.
 */
function liveActivityPush_apsPayload(array $contentState, string $event, int $now, ?int $dismissAt = null): array
{
    if (!in_array($event, ['update', 'end'], true)) {
        throw new \InvalidArgumentException(
            "liveActivityPush_apsPayload: unknown event '{$event}' — must be 'update' or 'end'."
        );
    }

    $aps = [
        'timestamp'      => $now,
        'event'          => $event,
        'content-state'  => $contentState,
        /* 180s stale-date mirrors LIVE_SESSION_FRESHNESS_SECONDS
           (service_mode.php) — the SAME "how long is a broadcast still
           considered fresh" window used everywhere else in Service Mode /
           Live Follow, so the Live Activity card goes visually stale on
           the exact same horizon a follower's poll would. */
        'stale-date'     => $now + LIVE_SESSION_FRESHNESS_SECONDS,
    ];
    if ($event === 'end') {
        $aps['dismissal-date'] = $dismissAt ?? $now;
    }

    return ['aps' => $aps];
}

/**
 * THE HOOK — tell every registered Live Activity token for one broadcast
 * session that its state changed (or that the session ended). This is the
 * ONLY function `api.php` calls; NEVER THROWS (every failure path —
 * missing table, no tokens, not configured, a decode error, an `apnsSend()`
 * failure — is swallowed and `error_log()`-ed, mirroring `apns.php`'s own
 * "best-effort, fire-and-forget" helpers like `_apnsPruneToken()`).
 *
 * ELI5: "the operator just changed the song (or ended the service) — go
 * knock on Apple's door for every phone that asked to be told about THIS
 * session, and if any of that fails for any reason, shrug and move on —
 * the operator's own save/end action must never fail because of this."
 *
 * DETAILED — gate order (cheapest/most-likely-to-short-circuit first):
 *   1. `tblApnsTokens` doesn't exist (un-migrated docroot) → return.
 *   2. No `Kind='liveActivity'` token rows for this session → return
 *      (the overwhelmingly common case: nobody has a Live Activity open).
 *   3. On `'end'`, delete every Live Activity token for this session HERE —
 *      BEFORE the `apnsConfigured()` gate below (#1429 Audit-B F3a fix: this
 *      cleanup used to run only after a successful send loop further down,
 *      gated behind `apnsConfigured()`, so an ended session's tokens were
 *      NEVER pruned on the overwhelmingly common un-configured docroot —
 *      the normal state today, before an admin pastes a real APNs key).
 *      The activity is over, nothing should ever push to it again (also
 *      matches the plain "dismiss = clean up" mental model, and keeps
 *      `tblApnsTokens` from accumulating one dead row per ended session per
 *      docroot) — this cleanup must happen regardless of configuration.
 *   4. `apnsConfigured()` is false (no admin-provisioned key yet, #1429 C4)
 *      → return. Checked AFTER the token SELECT + end-cleanup (not before)
 *      because those are cheaper and short-circuit/clean-up the common
 *      case without even resolving `apnsCredentials()`.
 *   5. Re-resolve the session itself, CHANNEL-SCOPED (rule #26) — a stale/
 *      wrong-channel/deleted session → return. For an `'update'` push
 *      specifically, an already-inactive session also short-circuits (an
 *      `'end'` push is expected to run against a JUST-deactivated row, so
 *      that check is skipped for `'end'`).
 *   6. Build the content-state + `aps` payload once (shared across every
 *      token this session has registered).
 *   7. Send to each token via `apnsSend()` (using the ALREADY-fetched
 *      in-memory `$tokens` array from step 2 — the row objects themselves,
 *      not a re-SELECT, so step 3's delete never races this send). A
 *      `not_configured` / `http2_unsupported` / `transport_unavailable` /
 *      `transport_error` status means EVERY subsequent send in this loop
 *      would fail for the same structural reason (not a per-token
 *      problem), so the loop `break`s early rather than retrying the same
 *      failure N times.
 *
 * @param \mysqli $db        Live DB handle.
 * @param int     $sessionId `tblLiveFollowSessions.Id` (host OR service kind — this hook is kind-agnostic).
 * @param string  $event     'update' (default) | 'end'.
 */
function liveActivitySessionPush(\mysqli $db, int $sessionId, string $event = 'update'): void
{
    try {
        if (!apnsTokensTableExists($db)) {
            return;
        }

        $tokStmt = $db->prepare(
            "SELECT PushToken, ApnsEnv FROM tblApnsTokens
              WHERE Kind = 'liveActivity' AND SessionId = ?
                AND (ExpiresAt IS NULL OR ExpiresAt > UTC_TIMESTAMP())
              ORDER BY Id
              LIMIT 25"
        );
        $tokStmt->bind_param('i', $sessionId);
        $tokStmt->execute();
        $tokens = $tokStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $tokStmt->close();
        if (!$tokens) {
            return;
        }

        /* #1429 Audit-B F3a — moved ABOVE the apnsConfigured() gate below
           (was previously only reached after a successful send loop,
           gated behind apnsConfigured() being true) so an ended session's
           tokens are cleaned up regardless of whether an admin has
           provisioned an APNs key yet — see this file's own "gate order"
           doc comment above for the full rationale. */
        if ($event === 'end') {
            $delStmt = $db->prepare("DELETE FROM tblApnsTokens WHERE Kind = 'liveActivity' AND SessionId = ?");
            $delStmt->bind_param('i', $sessionId);
            $delStmt->execute();
            $delStmt->close();
        }

        if (!apnsConfigured()) {
            return;
        }

        /* Channel-scoped re-resolve (rule #26) — a Live Activity token's
           scoping is carried TRANSITIVELY through SessionId (apns.php's
           file-header note), so THIS is where the channel filter actually
           applies. */
        $channel = serviceMode_channel();
        $sStmt = $db->prepare(
            'SELECT s.CurrentSongId, s.CurrentComponentIndex, s.StateJson, s.StateRevision, s.IsActive, t.Title
               FROM tblLiveFollowSessions s
               LEFT JOIN tblSongs t ON t.SongId = s.CurrentSongId
              WHERE s.Id = ? AND s.Channel = ?'
        );
        $sStmt->bind_param('is', $sessionId, $channel);
        $sStmt->execute();
        $session = $sStmt->get_result()->fetch_assoc();
        $sStmt->close();
        if (!$session) {
            return;
        }
        if ($event === 'update' && (int)$session['IsActive'] !== 1) {
            /* An 'end' push legitimately runs against a row the caller just
               deactivated — only 'update' requires IsActive=1. */
            return;
        }

        $isLive = ($event !== 'end');
        $contentState = liveActivityPush_contentState(
            $session['CurrentSongId'] !== null ? (string)$session['CurrentSongId'] : null,
            $session['Title'] !== null ? (string)$session['Title'] : null,
            $session['CurrentComponentIndex'] !== null ? (int)$session['CurrentComponentIndex'] : null,
            $session['StateJson'] !== null ? (string)$session['StateJson'] : null,
            (int)$session['StateRevision'],
            $isLive
        );
        $payload    = liveActivityPush_apsPayload($contentState, $event, time());
        $collapseId = 'las-' . $sessionId;

        foreach ($tokens as $tok) {
            $pushTokenHex = bin2hex((string)$tok['PushToken']);
            $apnsEnv      = (string)$tok['ApnsEnv'];
            $result = apnsSend($db, $pushTokenHex, $apnsEnv, 'liveActivity', $payload, $collapseId);
            $status = (string)($result['status'] ?? '');
            if (in_array($status, ['not_configured', 'http2_unsupported', 'transport_unavailable', 'transport_error'], true)) {
                /* Structural failure (not a per-token one) — retrying the
                   remaining tokens against the same broken transport/config
                   would just repeat the same failure N more times. */
                break;
            }
        }
    } catch (\Throwable $e) {
        /* NEVER let a push-bridge failure surface to the caller — every
           call site in api.php is fire-and-forget (see this file's header).
           Never logs the push token itself, mirroring apns.php's own
           breadcrumb discipline. */
        error_log('[live_activity_push] ' . $e->getMessage());
    }
}
