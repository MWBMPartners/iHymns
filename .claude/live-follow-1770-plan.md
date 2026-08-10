# #1770 — Live Follow / Service Mode UX rethink: implementation plan (Option A, owner-directed)

> **PLAN ONLY — no code changes yet.** Written 2026-08-10 (deep-planning pass) from a full read of
> the live tree; every `file:line` cite verified against the working copy this date.
> **Feeds:** the Sonnet build pass for #1770. **Reads first:** `.claude/live-follow-1770-analysis.md`
> (the deep analysis; its "Owner direction (2026-08-05)" §423–534 carries the SEVEN decided
> requirements — Option A is DECIDED, Option B and "keep-Quick-minimal C" are closed),
> `.claude/live-congregant-strategy.md`, CLAUDE.md rule #26.
> All web paths relative to `appWeb/public_html/` unless rooted.

---

## §0 Executive summary

1. **One migration batch, four objects** (rule #19/#20 one-pass): two columns on
   `tblLiveFollowSessions` (`LastLeaderSeenAt`, `IdleTimeoutMins` + one index), two columns on
   `tblOrganisations` (`LiveIdleTimeoutMins`, `EnforceIdleTimeout`), one NEW dormant table
   `tblServiceDriverKeys` (durable org-scoped credentials for ProPresenter-class external drivers).
   **Nothing else needs schema**: the app-default idle timeout is a `tblAppSettings` key (freeform
   store — the `SERVICE_MODE_POLL_MS_*` precedent, `service_mode.php:222-231`); the user idle value
   is a key in the existing `tblUsers.Settings` JSON (`schema.sql:934`, the #1671 F5 namespaced
   store); host-CCLI is resolved LIVE (nothing stored — narrower exposure by construction);
   **section granularity already exists end-to-end** as `CurrentComponentIndex`
   (`schema.sql:4090`) — a parallel `CurrentComponentId` was considered and REJECTED (§3.4);
   Quick-follower presence REUSES `tblServicePresence` (its org/venue/schedule/date columns are
   already NULL-able, `schema.sql:4142-4145`).
2. **Req #1 (persist across songs) is already built** — `hostCode` survives in sessionStorage
   (`live-follow.js:57`), `initSongPage()` re-broadcasts on every song render (:97-99), the
   follower re-resolves (`_applyFollowState` :344-361). The delta is a **visible host bar**
   (today the LIVE badge only exists on song pages, :389-400) + a GIRFT two-device verify.
3. **Req #2/#5 (configurable idle auto-close)**: `LastLeaderSeenAt` bumped only by GENUINE leader
   interaction (broadcast, create, console action, or a `leaderActive:true` flag on the 30 s
   heartbeat — the automated keepalive alone must NOT count, or an abandoned open tab lives
   forever); the resolved precedence value is **stamped on the session row at create**
   (`IdleTimeoutMins`) so every gate/prune is a self-contained SQL predicate with no per-row
   re-resolution. ONE resolver helper, ONE predicate builder, ONE opportunistic prune —
   all in `includes/service_mode.php` (rule #26: extend the core, never fork).
4. **Req #3/#6 (host-CCLI unlock for Quick followers)**: `live_follow_join` gains an additive POST
   mode that mints a real `tblServicePresence` row + 43-char token; the client sets the SAME
   `ihymns_sf_presence_token` cookie Service Mode uses; `serviceMode_presenceCcliNumber()`
   (`service_mode.php:900`) grows a host-licence branch (session `SessionKind='host'` → HostUserId
   → `getUserEffectiveLicences()`), so the ONE injection point in
   `content_access.php:416-422` is untouched and the `content_gating_enabled='0'` dormancy is
   inherited, not re-implemented. ⚠️ Licensing flag recorded (§10 S2): no venue = no
   proof-of-presence; owner accepted; the unlock is bound as narrowly as the mechanism allows.
5. **Req #4/#7 (external presentation-app driver at slide granularity)**: extract the broadcast
   write from `service_broadcast` (api.php:17806-17836) into the ONE core
   `serviceMode_applyBroadcast()`; add `service_drive` (API-key auth against
   `tblServiceDriverKeys`, per-KEY rate-limited, channel-filtered session resolution by venue)
   calling that SAME core; a server-side `serviceMode_resolveSectionIndex()` maps a shim's
   "Verse 2"/"Chorus" label to the render-order `componentIndex` the congregant client already
   scrolls to (`service-follow.js:230-268`). The ProPresenter protocol itself is a flagged SPIKE
   (§10 S1) — the endpoint + a generic webhook contract ship first so any automation works day one.
6. **8 commits, one PR to `alpha`** (§8). C1 is a pure-dormant no-op; C2–C4 are dormant/additive
   server (verified byte-identical un-migrated and with the flag off); C5–C6 client; C7 guards
   (mutation-proven, tree-derived); C8 docs. #1339/#1792 (live two-device verify, same-channel
   testability) is the coupled follow-up — planned around, not solved here.

---

## §1 Principles / invariants held throughout

These are restated because every one of them has a named regression class behind it:

- **I1 — Channel wall (rule #26).** Every NEW or CHANGED query that resolves/joins/polls/
  broadcasts/gates/prunes a session filters `Channel = serviceMode_channel()` (or the
  correlated-EXISTS shape, `service_mode.php:448-537`). PK-scoped follow-up writes after a
  channel-scoped resolve are the only exemption (the `service_broadcast` UPDATE-by-Id shape,
  api.php:17806-17814). New session-creating paths stamp Channel at insert. Durable
  CREDENTIALS (`tblServiceDriverKeys`) deliberately carry NO Channel — like `tblSharedSetlists`
  (setlist plan §3b), the wall applies to live sessions, not identities; every session
  RESOLUTION a key performs filters the serving docroot's channel, so an alpha request can only
  ever drive alpha sessions.
- **I2 — Rate limits per token/key for congregant-scale + machine paths, never per-IP**
  (rule #26; per-IP only for pre-auth probe caps and the failed-joins-only join budget,
  api.php:17840-17882). Bucket on a HASH of the token (`'tok:'+substr(hash('sha256',$t),0,24)`,
  api.php:18031), never the raw value (#1492). Do NOT migrate pollers to
  `enforceReadRateLimit()` (it has no explicit-key parameter yet — api.php:18021-18030).
- **I3 — CCLI dormancy survives untouched.** The ONLY unlock path stays
  `checkContentAccess()`'s presence branch (`content_access.php:416-422`); everything behind
  `content_gating_enabled='0'` + `require_licence:ccli` rows. Fail-closed try/catch (the
  `serviceMode_presenceCcliNumber` posture, :1000-1004). Quick sessions keep `OrgId`
  hardcoded NULL (api.php:17019) so the ORG branch's INNER JOIN fails closed — now a TESTED
  invariant (§9 G3). Never geolocation.
- **I4 — One helper core, one state allow-list, one broadcaster core.** Extend
  `includes/service_mode.php`; every broadcast writer uses `serviceMode_cleanState()`
  (:295); the new external driver goes through the SAME extracted write core as
  `service_broadcast`; the Quick console reuses `js/modules/service-broadcast.js` via its
  designed injection point (the `apiCall` ctor param, service-broadcast.js:62-64) — never a fork.
- **I5 — Dormant-safe on the 3-docroot shared MySQL** (rule #9/#28-C). Every new column/table
  read is behind a memoised INFORMATION_SCHEMA probe (the
  `serviceMode_presenceRoleColumnExists` pattern, service_mode.php:720-743); an un-migrated
  install degrades to today's behaviour byte-identically. mysqli STRICT throws — no
  false-checks.
- **I6 — Anti-probe opacity.** Join failures stay ONE user-facing message
  (api.php:17903-17926); idle-closed sessions are indistinguishable from ended ones to a
  follower (`active:false`) and to a joiner (the same 404 text).
- **I7 — Front-end rules**: real ES modules wired from the router (rule #30), fixed overlays
  tear down unconditionally first (rule #32 — the two existing banners at live-follow.js:423-453
  / service-follow.js:330-360 are the shape), `apiFetch` only (rule #31), event names in
  `constants.js` (#1581), state-changing AJAX under `X-Requested-With` (rule #29), QR only via
  `/qr.php` (rule #38), URL params honoured by their destination (rule #33).
- **I8 — Rotating-code discipline (#1621)** untouched: retirement is a Status flip, never a
  DELETE; every new end/supersede/prune path that deactivates a SERVICE session calls
  `serviceMode_retireSessionCodes()`. (Quick sessions have no join-code rows — nothing to retire.)
- **I9 — #1576 floor semantics untouched**: the ad-hoc floor applies only to placeholder-time
  service sessions; nothing here converts honest-scheduled into floored-ad-hoc.

---

## §2 Verified current-state anchors (the facts the plan builds on)

| Fact | Where |
|---|---|
| Quick session persists across songs already (sessionStorage host code, per-song rebroadcast, follower re-resolve) | live-follow.js:57, :97-99, :344-361 |
| Web host always broadcasts `componentIndex: 0` — the host UI is the section gap, not the protocol | live-follow.js:98, :145 |
| `CurrentComponentIndex` exists on the spine and flows through every endpoint + both followers | schema.sql:4090; api.php:17135-17136 (update), :17727-17728 + :17812 (service_broadcast), :18066 (poll); service-follow.js:230-268 |
| `serviceMode_cleanState()` is the ONE state allow-list for all three writers; `lineIndex` (0-9999) is already in the vocabulary | service_mode.php:258-333 |
| `service_broadcast` already accepts a delegated `controlToken` (#1408) — per-session, short-lived | api.php:17762-17770; includes/session_control_token.php |
| `tblServicePresence` service columns all NULL-able; `PresenceToken` CHAR(43) unique; `Role` gated by columnExists | schema.sql:4139-4162; api.php:17954-17974 |
| The Phase-3 injection point + cookie readers | content_access.php:416-422; song.php / song-media.php / audio-media.php / four api.php payload paths (analysis §1.5) |
| `getUserEffectiveLicences(?int $userId)` resolves personal `CcliNumber` + org licences (ancestry-aware); `licenceCcliQualifies()` is the ONE format rule | includes/licences.php:140, :228, :327 |
| `tblUsers.Settings` JSON is the ONE synced per-user pref store, namespaced writes (#1671 F5) | schema.sql:934; includes/user_settings.php; api.php:3832-3877 |
| `getAppSetting()/setAppSetting()` freeform key/value; admin UI on `manage/configuration.php` | includes/maintenance.php:60, :105 |
| `tblApiKeys` is admin-minted + site-wide (no OrgId) — wrong shape for a church-minted driver key; `apiKeyAuthorize()` shows the house key-auth discipline | schema.sql:858-875; includes/api_keys.php:113 |
| `ServiceBroadcaster` takes an injected `apiCall(action,{method,query,body})` — the designed transport seam | service-broadcast.js:62-74; mounted by service-projection.php:434 |
| Projection QR encodes `/?svc_code=<code>` and NOTHING reads it (standing rule-#33 violation, analysis P5) | service-projection.php:353-365 |
| Migration registry: ONE entry (script/card/probe), probes via `_migProbe_*` helpers | manage/includes/migration-registry.php:105-143 |
| Heartbeat extends `ExpiresAt` +4 h forever; freshness window 180 s; nothing measures leader INTERACTION | api.php:17209-17217; service_mode.php:94 |
| The house opportunistic-prune pattern (piggy-backed, capped, channel-filtered, best-effort) | serviceMode_retireExpiredCodes, service_mode.php:512-537, called from :566-570 |

---

## §3 Final one-pass schema (rule #19/#20)

**One migration**: `appWeb/.sql/migrate-live-follow-quick-capable.php` — idempotent, every ALTER
`columnExists`-gated (mysqli STRICT), byte-identical mirror into `appWeb/.sql/schema.sql`,
`@migration-adds` doctag PER column (multi-column ALTERs), ONE entry in
`manage/includes/migration-registry.php` (slug `live-follow-quick-capable`) with a multi-object
OR-probe. Everything dormant until code uses it.

### 3.1 `tblLiveFollowSessions` — idle machinery (2 columns + 1 index)

```sql
ALTER TABLE tblLiveFollowSessions
    ADD COLUMN LastLeaderSeenAt DATETIME NULL DEFAULT NULL
        COMMENT 'UTC instant of the last GENUINE leader interaction (broadcast/create/console action, or a leaderActive heartbeat) — NOT bumped by the automated 30s keepalive alone. NULL = pre-#1770 row or not yet stamped; idle enforcement skips it (#1770)',
    ADD COLUMN IdleTimeoutMins SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Resolved idle-timeout (minutes) stamped at session create from the app→org→user precedence chain (serviceMode_resolveIdleTimeoutMins). NULL = no idle enforcement (service sessions, legacy rows, un-migrated writers) (#1770)',
    ADD INDEX idx_Idle (SessionKind, IsActive, LastLeaderSeenAt);
```

Design notes:
- **Stamp-at-create, not resolve-at-read**: the prune/gate predicate becomes pure SQL
  (`TIMESTAMPDIFF(MINUTE, LastLeaderSeenAt, UTC_TIMESTAMP()) >= IdleTimeoutMins`) with no
  per-row user/org lookups. An admin changing the app default mid-session affects the NEXT
  session, not running ones — documented, acceptable, and what makes the mechanism cheap.
- **Two timestamps on purpose**: `LastHeartbeatAt` = "the tab is alive" (keeps joins/polls
  working, 180 s window); `LastLeaderSeenAt` = "a human is driving". Conflating them is the bug
  the owner is fixing — an open-but-abandoned tab heartbeats forever (api.php:17209-17217).
- Service sessions simply never get `IdleTimeoutMins` stamped (stays NULL) — if the owner later
  wants operator-idle close for services, that is a code-only change. ✓ no second migration.

### 3.2 `tblOrganisations` — the org layer of the precedence chain (2 columns)

```sql
ALTER TABLE tblOrganisations
    ADD COLUMN LiveIdleTimeoutMins SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Org override for the Quick-session leader-idle timeout (minutes). NULL = no override — the app default applies (#1770 req 5)',
    ADD COLUMN EnforceIdleTimeout TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = the org LOCKS LiveIdleTimeoutMins for its members (their personal value is ignored). Only meaningful when LiveIdleTimeoutMins is non-NULL (#1770 req 5)';
```

The owner explicitly specified these two columns (analysis §480-494) — they are the contract,
like the seven original tier-cap columns (rule #28). A generic `tblOrganisations` settings-JSON
was considered for forward-proofing and rejected: no other org-level live setting is foreseeable
(poll cadence is deliberately app-level, #1406), and the resolver reads through ONE helper
(§5) so a future re-homing is a resolver-only change.

### 3.3 `tblServiceDriverKeys` — NEW dormant table (external presentation-app credentials)

```sql
CREATE TABLE IF NOT EXISTS tblServiceDriverKeys (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    OrgId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrganisations — the org whose service sessions this key may drive. v1 mint path always sets it; app rule: exactly one of OrgId / OwnerUserId is non-NULL (#1770 req 4)',
    OwnerUserId INT UNSIGNED NULL DEFAULT NULL COMMENT 'RESERVED-DORMANT (rule #20): a future personal driver key for Quick sessions. No v1 code writes it (#1770 §10 S4)',
    VenueId     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrgVenues — optional narrowing to one venue; NULL = any venue of the org',
    KeyHash     CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the raw key — raw value never stored (tblSessionControlTokens / tblApiKeys discipline)',
    KeyPrefix   VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Non-secret leading chars for admin identification (mirrors tblApiKeys.KeyPrefix)',
    Label       VARCHAR(120) NOT NULL COMMENT 'Operator-facing name, e.g. "Sanctuary ProPresenter"',
    Scope       VARCHAR(40)  NOT NULL DEFAULT 'broadcast' COMMENT 'Granted capability — app-validated VARCHAR vocab, never ENUM (rule #20)',
    Protocol    VARCHAR(30)  NOT NULL DEFAULT 'generic' COMMENT 'Which shim family minted/uses it: generic | propresenter | openlp | … — display + diagnostics vocab, app-validated VARCHAR',
    IsActive    TINYINT(1)   NOT NULL DEFAULT 1,
    ExpiresAt   DATETIME     NULL DEFAULT NULL COMMENT 'Optional expiry (UTC), NULL = never. DATETIME not TIMESTAMP (rule #20 TTL convention)',
    RevokedAt   DATETIME     NULL DEFAULT NULL COMMENT 'Hard revocation instant (UTC); non-NULL = refused',
    LastUsedAt  DATETIME     NULL DEFAULT NULL,
    LastUsedIp  VARCHAR(45)  NULL DEFAULT NULL,
    CreatedBy   INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id of the minting org-admin',
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_KeyHash (KeyHash),
    INDEX idx_Org (OrgId, IsActive),
    CONSTRAINT fk_DriverKey_Org   FOREIGN KEY (OrgId)       REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_DriverKey_Venue FOREIGN KEY (VenueId)     REFERENCES tblOrgVenues(Id)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_DriverKey_User  FOREIGN KEY (OwnerUserId) REFERENCES tblUsers(Id)         ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_DriverKey_Creator FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)         ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Durable org-scoped credentials for external presentation-app drivers (ProPresenter/OpenLP shims) of Service-Mode sessions (#1770 req 4/7). Deliberately NO Channel column — the wall is enforced at session-resolution time (rule #26): every resolve filters the serving docroot''s channel.';
```

Why not reuse: `tblApiKeys` is site-admin-minted with no org scoping (schema.sql:858-875) — a
church key must only ever drive its OWN org's sessions and be mintable by an org-admin.
`tblSessionControlTokens` (#1408) is per-session + short-TTL — a static ProPresenter shim config
cannot re-mint per service. Both stay untouched; #1408 remains the "hand a co-leader my phone"
mechanism.

### 3.4 App + user layers, host-CCLI, section granularity — deliberately NO schema

- **App default**: `tblAppSettings` key `live_follow_idle_timeout_minutes` — freeform store, no
  migration (the `service_poll_interval_*_ms` precedent). Code fallback constant
  `LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES = 15` in service_mode.php.
- **User value**: `tblUsers.Settings` JSON key `liveIdleTimeoutMins` via the existing
  `user_settings` endpoint + `js/modules/settings.js` whitelist — the rule-#28 "new prefs are
  JSON" posture; a user column would be the one-column-per-feature red flag.
- **Host-CCLI**: resolved LIVE per gate read via `getUserEffectiveLicences()` — storing a
  snapshot at session start was considered and REJECTED: live resolution means a lapsed/removed
  licence stops unlocking mid-session (the narrowest binding, exactly what the owner's
  licensing-flag mitigation asks for), and it is how the Service-Mode branch already behaves
  ("revoked … the org's licence lapses", service_mode.php:855-856).
- **Section granularity**: `CurrentComponentIndex` (schema.sql:4090) is already stored, set by
  every writer, returned by every poll, and honoured by both follower clients (§2 table). A
  parallel `CurrentComponentId` (FK to `tblSongComponents`) was considered and REJECTED: two
  columns describing one fact is the rule-#35 drift hazard; the index is ephemeral broadcast
  state that self-corrects on the next write (unlike the durable per-line anchors of rule #21,
  where index-fragility matters); and followers locate sections by render order
  (`.lyric-component` nth, service-broadcast.js:20-24), which an Id would need converting BACK
  to. Line-level granularity already exists too (`StateJson.lineIndex`, service_mode.php:317-320).

### 3.5 Adversarial "what would force a SECOND migration?" stress

| Future tweak | Covered? |
|---|---|
| Idle timeout for SERVICE sessions (operator-idle) | ✓ code-only — stamp `IdleTimeoutMins` on service rows too |
| Different timeout per session kind / per org member role | ✓ resolver-only change; the stamped column doesn't care how the value was derived |
| "Never time out" option | ✓ app-level: the 4 h `ExpiresAt` ceiling already bounds everything; 240 = the ceiling (no sentinel column needed) |
| Personal (Quick) driver keys | ✓ `OwnerUserId` reserved dormant (§10 S4) |
| A second external protocol (OpenLP, FreeShow, Planning Center webhook) | ✓ `Protocol` VARCHAR vocab + the shim-over-one-contract design (§7); no schema |
| Driver key per-venue narrowing / several keys per org | ✓ `VenueId` nullable + non-unique `idx_Org` |
| Key rotation / expiry / audit | ✓ `ExpiresAt`/`RevokedAt`/`LastUsedAt`/`LastUsedIp` shipped dormant |
| Scope growth (e.g. `broadcast+end`) | ✓ VARCHAR `Scope`, app map |
| Section labels needing richer mapping (localised labels, PP group names) | ✓ app-level map in the resolver helper; no schema |
| Host-CCLI needing the CCL notice number per song | ✓ the gate helper already returns the number; song.php renders it (analysis §1.5) |
| Org wants to DISABLE Quick hosting for members | ✗ would be one new org column/flag — genuinely unforeseeable today, out of scope, recorded |

### 3.6 Registry entry (derives the four setup-database facets)

```php
'live-follow-quick-capable' => [
    'script' => 'migrate-live-follow-quick-capable.php',
    'card' => [
        'title'  => 'Live Follow: capable Quick sessions (#1770)',
        'body'   => 'Adds leader-idle auto-close columns to <code>tblLiveFollowSessions</code>, '
                  . 'org idle-timeout override columns to <code>tblOrganisations</code>, and the '
                  . '<code>tblServiceDriverKeys</code> table for ProPresenter-class external drivers. '
                  . 'Idempotent — safe to re-run.',
        'button' => 'Run Live Follow Capability Migration',
    ],
    'probe' => static fn(\mysqli $db) =>
           !_migProbe_tableExists($db, 'tblServiceDriverKeys')
        || !_migProbe_columnExists($db, 'tblLiveFollowSessions', 'LastLeaderSeenAt')
        || !_migProbe_columnExists($db, 'tblLiveFollowSessions', 'IdleTimeoutMins')
        || !_migProbe_columnExists($db, 'tblOrganisations', 'LiveIdleTimeoutMins')
        || !_migProbe_columnExists($db, 'tblOrganisations', 'EnforceIdleTimeout'),
],
```

A multi-object OR-probe so a partial apply never shows green (rule #19). Order: append at the
registry tail (no upstream dependency beyond the already-listed service-mode batch).

---

## §4 Server logic deltas (all additive + dormant)

All in `api.php` + `includes/service_mode.php` + a new `includes/service_driver_keys.php`
(the key-lifecycle concern, mirroring how `includes/session_control_token.php` sits beside the
core). Every new state-changing action inherits api.php's global `X-Requested-With` gate; the
key-minting endpoints additionally call `validateCsrfRequest()` (the
`service_control_token_mint` belt-and-braces, api.php:18122-18130).

### 4.1 Idle machinery (req #2/#5) — `service_mode.php`

- `serviceMode_idleColumnsExist(\mysqli $db): bool` — memoised INFORMATION_SCHEMA probe for
  BOTH session columns (lockstep, the rule-#25 gate discipline).
- `serviceMode_idleFreshSql(string $alias = 's'): string` — returns `''` when un-migrated, else
  the ONE predicate:
  `AND ({$alias}.IdleTimeoutMins IS NULL OR {$alias}.LastLeaderSeenAt IS NULL OR TIMESTAMPDIFF(MINUTE, {$alias}.LastLeaderSeenAt, UTC_TIMESTAMP()) < {$alias}.IdleTimeoutMins)`.
  NULL-safe = legacy/service rows unaffected. Every consumer (join/poll/update/heartbeat/gate)
  appends this helper's output — no hand-typed copies (guard G2).
- `serviceMode_resolveIdleTimeoutMins(\mysqli $db, int $userId): int` — §5, the ONE resolver.
- `serviceMode_pruneIdleQuickSessions(\mysqli $db, string $channel): int` — opportunistic,
  best-effort, capped (LIMIT ~20), channel-filtered:
  SELECT the ids of `SessionKind='host' AND IsActive=1 AND Channel=?` rows failing the idle
  predicate → per id: `IsActive=0`, revoke `tblServicePresence` rows (`IsActive=0` by SessionId
  — instant CCLI stop), `liveActivitySessionPush($db,$id,'end')`. Piggy-backed OUTSIDE any
  transaction (the `retireExpiredCodes` placement argument, service_mode.php:487-506) from
  `live_follow_create` and `live_follow_join` (both run whenever Quick is in use; no cron
  exists on this host). Wrapped try/catch + error_log — a prune failure never fails a join.

### 4.2 `live_follow_*` deltas (api.php:16995-17394)

- **`live_follow_create`** (:16995): resolve `serviceMode_resolveIdleTimeoutMins()`; when
  `serviceMode_idleColumnsExist()`, INSERT additionally stamps
  `LastLeaderSeenAt = UTC_TIMESTAMP(), IdleTimeoutMins = ?` (two INSERT shapes, the
  `service_join` Role-gate precedent api.php:17954-17974). Piggy-back the idle prune before the
  supersede. Response gains additive `idleTimeoutMins`.
- **`live_follow_update`** (:17113): SET gains `LastLeaderSeenAt = UTC_TIMESTAMP()` (gated —
  driving songs IS interaction); WHERE gains `serviceMode_idleFreshSql('tblLiveFollowSessions')`
  so a broadcast against an idle-expired session answers the existing 409 → the host client
  already tears down on 409 (live-follow.js:186-191). ✓ zero client change needed for the
  close-detection path.
- **`live_follow_heartbeat`** (:17196): body gains optional `leaderActive` (bool). When true +
  gated, SET adds `LastLeaderSeenAt = UTC_TIMESTAMP()`. The liveness re-check gains the idle
  predicate so an idle-closed session answers `ok:false` → the client already ends the host on
  `ok:false` (live-follow.js:214). NOTE the heartbeat's `ExpiresAt = +4h` rolling extension is
  untouched — idle-close now bounds what that extension used to leave unbounded.
- **`live_follow_join`** (:17271): WHERE gains the idle predicate. NEW additive POST mode:
  when `REQUEST_METHOD === 'POST'` and body carries `presenceDeviceId` (validated exactly as
  `service_join` does, :17888-17893), upsert a `tblServicePresence` row —
  `(SessionId, OrgId=NULL, VenueId=NULL, ScheduleId=NULL, OccurrenceDate=NULL, Channel,
  PresenceDeviceId, PresenceToken=43-char, Role='congregant', ExpiresAt=<session ExpiresAt>)`
  on the existing `uq_DeviceSession` upsert shape — and add `presenceToken` to the response.
  Gated on the presence TABLE existing (Live Follow already hard-depends on that same migration
  for `Channel` — the documented "prerequisite quirk"); GET path stays byte-identical for old
  clients. Rate limit unchanged (120/min/IP join cap, :17284 — pre-token, correct class).
- **`live_follow_poll`** (:17339): the `Fresh` expression gains the idle predicate → followers
  of an idle-expired session see `active:false` and leave cleanly (service parity).
- **`live_follow_leave`** (:17235): after deactivating, revoke the session's presence rows
  (table-existence-gated) — the instant-stop half of the owner's mitigation.

### 4.3 Host-CCLI unlock (req #3/#6) — `service_mode.php` + client cookie

- `serviceMode_presenceCcliNumber()` (:900) grows a SECOND branch: when the org-anchored query
  returns null, run the HOST-licence query — presence token (active, unexpired, channel) →
  session (`SessionKind='host'`, `IsActive=1`, heartbeat-fresh, **idle-fresh** via
  `serviceMode_idleFreshSql`) → `HostUserId` — then in PHP:
  `getUserEffectiveLicences($hostUserId)` filtered to type `'ccli'` (personal `CcliNumber` OR
  any active org's licence, ancestry-aware — includes/licences.php:228 already implements the
  owner's "user account OR one of their organisations" wording), number validated through the
  SAME `licenceCcliQualifies()` rule. Returns the number for the CCL notice; null otherwise.
  Fail-closed try/catch. The signature and the ONE call site
  (`content_access.php:416-422`) are untouched → dormancy (rule A), cookie plumbing
  (`song.php` / `song-media.php` / `audio-media.php` / 4 api.php payload paths) and the
  per-song CCL notice all inherit with ZERO changes.
- ⚠️ **Licensing flag (record verbatim in code doc-block + issue):** a Quick session has no
  venue and therefore NO proof-of-presence; unlocking CCLI for anyone holding the code may
  exceed the licence's terms. Owner accepted this (analysis §444-459). The mechanism binds as
  narrowly as possible: active session + heartbeat-fresh + idle-fresh + actively-held presence
  token, revoked the instant the session closes (end/idle-prune/leave all revoke presence) or
  the follower leaves; live licence resolution (no snapshot).
- **Client** (`live-follow.js`): `_doJoin` switches to POST with `presenceDeviceId` (reuse the
  `ihymns_sf_device` localStorage minting — extract `_deviceId()` from service-follow.js:99-110
  into a tiny shared util so the two followers share one device identity); on success with a
  `presenceToken`, set the SAME `ihymns_sf_presence_token` cookie (extract the cookie
  set/clear pair service-follow.js:365-373 into the same util); clear on `leaveFollow` and on
  `active:false`. A server without the new build simply returns no `presenceToken` → cookie
  never set → behaviour identical (back-compat by absence, the `followToken` precedent
  live-follow.js:266-270).

### 4.4 ONE broadcast core + section resolution (req #7)

- Extract from `service_broadcast` (:17806-17836) into
  `serviceMode_applyBroadcast(\mysqli $db, int $sessionId, ?string $songId, ?int $componentIndex, ?string $stateJson): int`
  — the UPDATE (CurrentSongId/CurrentComponentIndex/StateJson/StateRevision+1/LastHeartbeatAt),
  the revision re-read, `liveActivitySessionPush(…,'update')`. `service_broadcast` becomes
  gates + validation + core call — **fixture-diffed byte-identical** responses.
  (`live_follow_update` keeps its own code-scoped UPDATE: its WHERE is
  `SessionCode+HostUserId` and it also rolls `ExpiresAt` — folding it in would change
  semantics. It already shares the ONE state allow-list; noted as possible future convergence,
  not forced.)
- `serviceMode_resolveSectionIndex(\mysqli $db, string $songId, string $sectionRef): ?int` —
  maps `"Verse 2"|"V2"|"Chorus"|"C"|"Bridge"|"2"` → the 0-based **render-order** index.
  MUST project through the song's arrangement exactly as the congregant's page renders
  `.lyric-component` (the `_projectSections` contract, service-broadcast.js:20-24) — i.e. read
  components via the shared assembler (`lyricLinesAssembleComponents`, rule #25) + apply
  `ArrangementJson` server-side, then match `(type, number)` against a ONE-place label-fold map
  (VARCHAR-style app vocab: verse|chorus|refrain|bridge|intro|outro|tag + integer). Unresolvable
  → null → the caller applies song-level only (**song-level stays the fallback**, owner req #7).

### 4.5 `service_drive` — the external-driver endpoint (req #4)

New api.php action, POST only:

1. **Pre-auth per-IP probe cap** (mirrors `service_broadcast_probe`, :17717-17719 — the correct
   per-IP class): `service_drive_probe`, 300/min.
2. **Auth**: raw key from `Authorization: Bearer` / `X-API-Key` (reuse `apiKeyFromRequest()`’s
   header parsing shape) → SHA-256 → `serviceDriverKeyVerify($db, $raw): ?array` in
   `includes/service_driver_keys.php` (table-existence-gated → 503 "not available" un-migrated;
   checks `IsActive=1 AND RevokedAt IS NULL AND (ExpiresAt IS NULL OR ExpiresAt > UTC_TIMESTAMP())`;
   updates `LastUsedAt/LastUsedIp` best-effort). 401 + `WWW-Authenticate` on failure. NOT logged
   raw, ever.
3. **Rate limit per KEY** (I2): `'drvkey:' . substr(hash('sha256', $raw), 0, 24)`, 600/min
   (`checkRateLimit` + `recordRateLimitHit` pair — the #1636 paired-writer discipline).
4. **Session resolution** (every query channel-filtered): body `sessionId` (verify
   `SessionKind='service' AND Channel=? AND IsActive=1` AND `OrgId` = key's org AND, when the
   key has a `VenueId`, session venue matches) — else body `venueId` ?? key's `VenueId` →
   freshest active heartbeat-fresh service session for that venue on THIS channel. None → 404
   (one opaque message). This is what makes a shim config STATIC: it names a venue, never a
   session.
5. **Payload**: `{songId?, songRef?, sectionIndex?, sectionRef?, state?}`. `songId` validated
   via the same `songVisibleSql`/`songServableSql` check (:17795-17803); `songRef` (free-text
   title resolution) is **explicitly deferred to the protocol spike** (§10 S1) — v1 answers 422
   `{error:'songRef not supported yet'}` so shims fail loud, not silent. `sectionRef` resolved
   via §4.4's helper; `state` through `serviceMode_cleanState()` (I4).
6. **Write**: the ONE core `serviceMode_applyBroadcast()`. Response
   `{ok, sessionId, revision, sectionResolved}` (status codes are the contract, rule #35).
7. Breadcrumb: `logActivity('service.broadcast.song', …, ['via' => 'driver_key'])` extending the
   existing `via` vocabulary (:17832).

### 4.6 Driver-key lifecycle endpoints + admin surface

- `service_driver_key_mint` / `service_driver_key_revoke` / `service_driver_key_list` —
  POST/POST/GET; auth = authenticated + (`admin`/`global_admin` OR org-admin of the target org
  via `userIsOrgAdminOf`, the `service_session_start` gate shape :17438-17443);
  `validateCsrfRequest()` on the two writers; mint 10/hr/user; mint returns the RAW key ONCE
  (the `sessionControlToken_mint` discipline). All table-existence-gated (503 un-migrated).
- **UI**: a "Presentation-app control" card on `manage/service-projection.php` (the operator
  home): list keys (Label/Prefix/Protocol/LastUsedAt), mint (Label + optional venue + protocol
  select from the ONE PHP vocab map), revoke. Probe-gated "run the migration" card when the
  table is absent (the page's existing pattern :74-87).

### 4.7 Settings surfaces (req #5)

- **App**: `manage/configuration.php` gains the `live_follow_idle_timeout_minutes` field
  (numeric, 5–240, default 15) via the page's existing `setAppSetting` machinery.
- **Org**: `manage/organisations.php` (site admin) + `manage/my-organisations.php` (org admin)
  gain the two fields — column-existence-gated so un-migrated installs render without them.
- **User**: public Settings page + `js/modules/settings.js` whitelist gains
  `liveIdleTimeoutMins` (synced through the existing `user_settings` contract; root namespace,
  whole-blob legacy shape preserved). Copy notes "your organisation may enforce a value".

---

## §5 The idle-timeout resolver — ONE shared helper

`serviceMode_resolveIdleTimeoutMins(\mysqli $db, int $userId): int` in
`includes/service_mode.php`. THE only reader of all three layers (guard G2c):

1. **App default**: `getAppSetting('live_follow_idle_timeout_minutes')` →
   fallback `LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES` (15). (`getAppSetting` may be unloaded —
   the `serviceMode_pollIntervalMs` defensive shape :793-798.)
2. **Org layer** (column-gated; skipped un-migrated): the user's ACTIVE orgs
   (`tblOrganisationMembers ⋈ tblOrganisations WHERE o.IsActive = 1`) with
   `LiveIdleTimeoutMins IS NOT NULL`. If ANY has `EnforceIdleTimeout = 1` → **the minimum
   enforcing value wins outright** (most-restrictive across a multi-org user — deterministic,
   fails toward safety; §10 S3 records this default). Else remember the minimum non-null org
   value as the org default.
3. **User layer**: `tblUsers.Settings`→`liveIdleTimeoutMins` (int, validated).
4. **Resolution** (owner's formula, analysis §491):
   `enforced ?? (user ?? orgDefault ?? appDefault)` — then clamp 5–240 (240 min = the 4 h
   `ExpiresAt` hard ceiling, so "never" is structurally meaningless; §10 S5).

Called ONCE per session at `live_follow_create`; the result is stamped (§3.1). Not consulted by
any gate/prune (they read the stamped column). Reuses the existing per-user/per-org/per-app
precedence idea without forking any existing resolver (none of the existing ones — card layout,
language prefs — has an org layer, so this is the first three-layer instance; it becomes the
reference implementation).

---

## §6 Client deltas

All real ES modules per rule #30 (no fragment inline scripts anywhere in this plan — the two
admin pages own their `<head>` and keep their inline-module pattern).

1. **Host bar** (`live-follow.js`): while `hostCode` is set, show a fixed bottom bar (mirrors
   `_showFollowBanner`, rule-#32 shape: render function removes any existing instance first;
   shown from `init()` + every `initSongPage`): `LIVE · <code>` + **End** + **Console** +
   **Show code**. This makes req #1's persistence VISIBLE on every page and gives the host an
   end/console affordance off the song page. Idle-close surfacing: on heartbeat `ok:false` /
   update 409, toast "Your live session ended (closed or timed out after inactivity)" — one
   message, no oracle (I6).
2. **Leader-activity tracking** (`live-follow.js`): passive `pointerdown`/`keydown` listeners
   (installed only while hosting, removed on end) set a `_lastInteraction` stamp; `_beatNow()`
   sends `leaderActive: (_lastInteraction > lastBeatAt)`. Reading/scrolling for 20 minutes
   therefore counts as active; a genuinely abandoned tab does not.
3. **Quick console (optional — req #6)**: new `js/modules/live-host-console.js` importing
   `ServiceBroadcaster` and mounting it in a full-screen offcanvas/modal opened from the host
   bar. The injected `apiCall` is the ADAPTER (the module's designed seam,
   service-broadcast.js:62-74): it maps `service_broadcast` POSTs onto `live_follow_update`
   (`{code, songId, componentIndex, state}` + bearer auth) and passes `songs_index`/
   `song_detail` reads through unchanged. No fork of the console (I4). Never auto-opens —
   a bare "follow my current song" session stays valid with zero console interaction.
4. **Show-big-code view**: large code + QR `<img src="/qr.php?data=<joinUrl>&format=svg&size=512">`
   (rule #38 — 503 degrades to the typed code, the `renderQr` precedent
   service-projection.php:362-366). Join URL = `/?svc_code=<CODE>` — the SAME param the
   projection QR already emits, because the home join button already tries `service_join` then
   falls back to `live_follow_join` transparently (service-follow.js:141-153), so ONE param
   serves both worlds.
5. **`?svc_code=` reader (rule #33 — closes analysis P5)**: in `service-follow.js`'s `init()`
   (booted on every load, app.js:330-333): read the param, strip/validate with the existing
   fold, open the join prompt PRE-FILLED with one-tap confirm (Q3 default — never auto-join),
   then `history.replaceState` the param away. This un-deadens the projection QR emitted since
   #1339 AND serves the new Quick QR with the same ~20 lines.
6. **Settings UI** (§4.7 user layer) + org/admin form fields (§4.7).
7. **Shared util extraction**: `js/utils/presence-identity.js` — `deviceId()` +
   `setPresenceCookie()/clearPresenceCookie()` (lifted from service-follow.js:99-110/:365-373),
   consumed by both follower modules (modularity rule; no third copy).

---

## §7 The external-shim boundary (what ships vs the spike)

**Ships in #1770**: the stable internal contract — `service_drive` (venue-addressed,
key-authed, section-labels-or-index, song-level fallback) + key lifecycle + admin card + an
api-docs.yaml section with a **generic curl example** ("POST this JSON on every slide change").
Any automation (Companion, a Stream Deck script, a PP7 stage-display scraper) can drive iHymns
day one.

**Flagged spike (own analysis pass — file the issue, don't solve here)**: the
ProPresenter-specific shim. Open questions it owns: PP network-link vs stage-display vs
NDI/companion webhooks; where the shim RUNS (PP is desktop/no-cloud → an on-LAN bridge, per
`.claude/live-congregant-strategy.md` Phase 4); free-text slide/plan title → SongId mapping
(`songRef` — deliberately 422 until then, §4.5; candidate reuse: `lyricsIngest_resolveSong` +
`tblSongIdentityMap`); PP group-name → sectionRef vocabulary. Note `tests/test-propresenter-export.js`
exists — iHymns already EXPORTS to ProPresenter, so naming/format precedent lives in
`js/modules/export-ui.js`'s exporter. Defensible default meanwhile: the generic contract above.

---

## §8 Commit staging (ONE PR to `alpha`; each commit atomic + individually revertable)

| # | Commit | Contents | Verification (GIRFT — live-DB behavioural where it touches the DB) | Dormant? |
|---|---|---|---|---|
| C1 | `schema: one-pass Quick-capability batch (#1770)` | `migrate-live-follow-quick-capable.php` + byte-identical schema.sql mirror + ONE registry entry + `@migration-adds` doctags | `php -l`; `test-schema-coverage.php`; `test-migration-registry.php`; run the card twice on a scratch DB (idempotence); probe flips pending→applied | **Pure no-op** — nothing reads the objects |
| C2 | `live-follow: leader-idle auto-close + precedence resolver (#1770 req 2/5)` | §4.1 helpers + §5 resolver + §4.2 stamping/predicates/prune (NOT the presence/CCLI parts) + §4.7 the three settings surfaces | Un-migrated fixture: every `live_follow_*` response byte-identical. Migrated: create stamps resolved value; artificially age `LastLeaderSeenAt` → update 409s, heartbeat `ok:false`, poll `active:false`, join 404s, prune flips + pushes 'end'; resolver unit-tested pure over (enforce, org, user, app) matrix | Dormant on un-migrated; on migrated, visible only for NEW quick sessions |
| C3 | `live-follow: presence minting + host-CCLI gate branch (#1770 req 3/6)` | §4.2 join POST-mode + leave-revocation + §4.3 server branch | With `content_gating_enabled='0'`: **byte-identical no-op proof** (the `test-gating-noop.php` pattern). GET join byte-identical. POST join mints presence; flag on + `require_licence:ccli` fixture: follower of a CCLI-holding host sees gated lyrics + CCL notice with the host's number; host `leave`/idle-prune → next gate read refuses; `OrgId` stays NULL | **Entirely dormant** behind the gating flag |
| C4 | `service-mode: ONE broadcast core + external driver endpoint (#1770 req 4/7)` | §4.4 extraction + section resolver; §4.5 `service_drive`; §4.6 lifecycle endpoints + admin card; `includes/service_driver_keys.php` | `service_broadcast` fixture-diff pre/post extraction (byte-identical). Un-migrated: drive/mint → 503. Migrated: mint → drive by venueId advances a live session's song+section (verify follower scroll); wrong-org key → 404; revoked → 401; sectionRef "Verse 2" resolves against an arranged song; unresolvable ref → song-level + `sectionResolved:false`; per-key 429 | Dormant until a key is minted |
| C5 | `live-follow client: host bar, activity tracking, presence cookie (#1770 req 1/2/3)` | §6.1/2/7 + `_doJoin` POST upgrade | `node --check`; old-server back-compat (no `presenceToken` → no cookie); two-browser: host on song A → B, follower tracks (req #1 verify, scripted); idle toast on 409 | n/a (client) |
| C6 | `live-follow client: optional console, big-code QR, svc_code deep link (#1770 req 6/7)` | §6.3/4/5 | Console drives sections through `live_follow_update` (follower scrolls); QR degrades on `/qr.php` 503; scan → prefilled one-tap join; `test-fragment-inline-scripts.php` stays green | n/a |
| C7 | `guards: #1770 invariants (mutation-proven)` | §9 | Each guard broken-red-restored, documented in the commit body (rule #34) | n/a |
| C8 | `docs + consistency (#1770)` | api-docs.yaml (`service_drive`, key lifecycle, join POST-mode, `idleTimeoutMins`); `includes/pages/help.php` two topics; `help/live-follow.md`; `wiki/Live-Follow-&-Service-Mode.md`; CHANGELOG; `.claude/ProjectBrief.md` + CLAUDE.md rule-#26 note; issue updates with SHAs; file the §10 sub-decision issues + the S1 spike issue + the #1792 coupling note | Standing-tasks checklist | n/a |

Deploy note: C1's card must be RUN on each env before C2+ behaviour appears there — but C2–C4
are safe deployed first (probes). The CueRCode key (rule #38) remains a deploy-time dependency
for the two QR surfaces only; the typed code is the designed fallback.

---

## §9 Guards (rule #34 — tree-derived, mutation-proven, narrow)

- **G1 `tests/php/test-live-session-channel.php` — channel-filter presence.** Derive (regex over
  api.php + includes/service_mode.php + includes/service_driver_keys.php +
  includes/session_control_token.php) every SELECT that resolves `tblLiveFollowSessions` by
  anything other than a bare `Id = ?`, and every statement touching `tblServicePresence` /
  `tblLiveFollowJoinCodes` — assert each carries `Channel` (bound or correlated-EXISTS).
  PK-only follow-ups are structurally exempt (the derivation excludes them), NOT allowlisted by
  name. Mutation-prove: drop the Channel bind from one query → red.
- **G2 `tests/php/test-live-follow-idle.php`.** (a) the idle predicate exists ONCE — no
  `TIMESTAMPDIFF(…LastLeaderSeenAt…)` outside `serviceMode_idleFreshSql()`; (b) every consumer
  (join/poll/update/heartbeat/gate/prune — derived by grepping for the session-liveness
  freshness predicate they each already carry) also calls the idle helper; (c) the three
  precedence layers (`live_follow_idle_timeout_minutes`, `LiveIdleTimeoutMins`,
  `liveIdleTimeoutMins`) are each read by exactly ONE function
  (`serviceMode_resolveIdleTimeoutMins` — plus their settings-surface writers); (d) the pure
  resolver matrix test (enforce beats user; user beats org-default; org-default beats app;
  multi-org minimum; clamp).
- **G3 `tests/php/test-live-follow-host-ccli.php`.** (a) `live_follow_create` still inserts
  `OrgId` as a PHP-source NULL (assert the literal, the I3 invariant); (b) the host branch is
  reachable ONLY through `serviceMode_presenceCcliNumber()` (no second caller of the host-query
  helper; `content_access.php`'s presence branch remains the ONE injection point — assert no new
  `serviceMode_presence*CcliNumber` call sites outside it); (c) rides
  `test-gating-noop.php` for the flag-off byte-identity.
- **G4 one-broadcaster-core.** Tree-derive every `UPDATE tblLiveFollowSessions SET` statement
  writing `CurrentSongId` or `CurrentComponentIndex`; assert the set is exactly
  {`serviceMode_applyBroadcast`, `live_follow_update`'s legacy writer} — so `service_broadcast`,
  `service_drive`, and any future endpoint MUST route through the core. Mutation-prove with a
  planted inline UPDATE.
- **G5 rate-limit keying.** Assert `service_drive`'s `checkRateLimit` key derives from the key
  hash (`drvkey:` prefix) and `service_poll`/`live_follow_poll` keep their `tok:` buckets —
  extend the existing paired-writer conventions rather than a new scanner if one already
  covers check/record pairing.
- **G6 `svc_code` contract (rule #33, cross-language).** Grep the tree for `svc_code` emitters
  (service-projection.php + the new big-code view) AND readers (the service-follow.js reader);
  fail when an emitter exists with no reader. **This guard fails RED on today's tree** (emitter
  :359, zero readers) — its first run proves it can fail, then C6 turns it green.
- **Riding existing suites**: `test-schema-coverage`, `test-migration-registry`,
  `test-optional-table-probes`, `test-fragment-inline-scripts`, `test-event-names`,
  `test-csrf-same-origin`, `test-openapi-actions-exist` (new actions must be documented),
  PHP/JS syntax sweeps.

---

## §10 Owner sub-decisions (recommendation + default each; NONE block C1–C4)

- **S1 — ProPresenter protocol spike.** *Decision:* which PP integration surface the shim
  targets (network link / stage display / webhook via Companion) and where the LAN bridge runs.
  *Why owner:* product/deployment reality of the churches' AV setups. *Options:* spike now vs
  after the generic contract ships. *Recommendation:* ship §7's generic contract in this PR,
  run the spike as its own analysis issue — it cannot change the server contract (that is the
  point of the shim boundary). *Reply needed:* "spike now" / "spike later". **Blocks nothing.**
- **S2 — Host-CCLI media scope.** *Decision:* the unlock covers exactly what Service-Mode
  presence covers today (lyrics view + the same media gates the presence token already feeds
  through `contentGatingMediaAllowed`) — or lyrics-only for Quick. *Why owner:* licensing
  exposure (the no-venue flag, §4.3). *Recommendation:* same-as-Service-Mode (one mechanism, no
  second matrix — rule #28), given the owner already accepted the basis. *Reply:* "same" /
  "lyrics-only". **Blocks C3's scope choice only; default = same.**
- **S3 — Multi-org precedence detail.** Default implemented: any ENFORCING org wins with the
  MINIMUM enforcing value; else minimum non-null org value as the default layer. Trivially
  changeable (one pure function + its matrix test). **Blocks nothing.**
- **S4 — Personal driver keys for Quick sessions.** Default: NO in v1 (`OwnerUserId` shipped
  reserved-dormant, §3.3). *Recommendation:* revisit only if a venueless leader actually asks
  to wire ProPresenter to a Quick session. **Blocks nothing.**
- **S5 — Idle range / "never" option.** Default: 5–240 min, no "never" (the 4 h hard ceiling
  makes 240 the effective maximum anyway). **Blocks nothing.**
- **S6 — QR deep-link behaviour** (analysis Q3). Default: prefill + one-tap confirm, never
  auto-join (anti-probe + no join-on-prefetch). **Blocks nothing.**
- **S7 — User-facing vocabulary** (analysis Q4). Default for NEW UI copy: "**Quick live
  session**" vs "**Church service**" under one "Start a live session" umbrella; internals
  (`SessionKind`, endpoint names, `live_follow_*`) unchanged — the Catalogue→Collection
  copy-only precedent (rule #24/#945). **Blocks only copy strings.**

---

## §11 Landmines (restated for the builder — each has bitten before)

1. **Never relax the Channel wall to "fix" the two-device test** (analysis P4). The natural
   desktop-on-dev + phone-on-www smoke test ALWAYS fails by design. #1339/#1792 (the coupled
   follow-up — same-channel testability) is where that pain is addressed; both verify devices
   MUST be on ONE docroot. Do not touch the filters; do not add a channel-bypass flag.
2. **Idle predicate NULL-safety is the dormancy.** Legacy rows and service sessions have
   `IdleTimeoutMins NULL` — the predicate must pass them. A `NOT NULL DEFAULT 15` column would
   retroactively kill every in-flight session on migration day; the NULLs are load-bearing.
3. **The automated heartbeat must never bump `LastLeaderSeenAt` by itself** — only
   `leaderActive:true`, create, and update do. Bumping it on every beat re-creates the
   immortal-abandoned-tab bug with extra steps.
4. **Presence revocation must be on EVERY session-death path**: `live_follow_leave`, the idle
   prune, AND the create-supersede path (a host restarting supersedes the old session —
   :17055-17068 — its presence rows must be revoked there too, mirroring
   `service_session_end`'s transaction :17608-17633). Miss one and a token keeps unlocking
   until its `ExpiresAt`.
5. **`serviceMode_presenceCcliNumber`'s org branch must stay first and unchanged** — the host
   branch runs only on org-branch null, and the `o.IsActive = 1` owner decision (:914-936)
   must not be weakened by the new SQL.
6. **`service_drive` must not become a code-existence oracle**: one opaque 404 for
   wrong-org/wrong-venue/no-session/ended (I6), per-key limiting BEFORE any session lookup
   where possible, probe cap before auth.
7. **Do not assume `tblServicePollCounters` is live** — it isn't (analysis §1.4); rate limiting
   stays on `checkRateLimit`/`tblLoginAttempts`.
8. **The console adapter maps, never forks**: if `ServiceBroadcaster` needs a change to serve
   the Quick host, change the MODULE (both admin pages inherit), never copy it.
9. **`schema.sql` mirror byte-identical incl. COMMENT text** (rule #19) — the #1770 comments
   above are written once and pasted into both files.
10. **api-docs.yaml documents, guards enforce** (rule #35): the `X-Requested-With` requirement
    on the new POSTs and the 401/404/422/503 contracts go in BOTH.

---

## §12 What done looks like

All seven owner requirements demonstrably working on `alpha`: a leader goes live on one song,
wanders the whole app with the host bar showing, drives sections from the optional console or
just navigates; a follower joins by typed code or scanned QR and tracks song + section; the
session closes itself after the resolved idle window and every follower + the CCLI gate learn
within one poll; a CCLI-holding host's followers read gated lyrics with the CCL notice while
the flag experiment is on and see byte-identical behaviour while it is off; a curl loop drives
a church's projected service by venue name through a minted driver key; and the guards go red
when any of it is quietly forked. #1339's live two-device verify runs against THIS build
(both devices on one channel) before #1770 is declared fixed.
