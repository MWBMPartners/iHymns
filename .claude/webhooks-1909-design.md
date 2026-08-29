# Partner-Event Webhooks Platform — Design (#1909)

> Status: **locked design for owner review — NO code written**. Fable-5 deep design pass,
> 2026-08-21, on branch `claude/ilyrics-identity-work-model`. Deliverable of the #1909
> scoping decision ("design pass before building — one-pass forward-looking schema per
> rule #20"). Implementation waves come AFTER the §B owner decisions are answered.

---

## 1. Goal

External systems (partner churches, sibling projects like iLyricsDB / MeedyaDL,
presentation-software integrators) **subscribe to iHymns events** — a song was
created/updated/deleted, a songbook changed, a set-list was shared, a service went
live — and receive **signed HTTP POST callbacks** with retry, dead-lettering, and an
admin surface to manage subscriptions and triage deliveries.

This closes the Phase-D gap in `.claude/api-platform-strategy.md` §3 ("Webhooks on
song/songbook changes") that #1909 records as never built: today `grep tblWebhook`
returns nothing, and the only "webhook" mentions in the tree are prose about the
Service-Mode external driver (`api.php:18961`, `includes/service_driver_keys.php`) —
inbound key-authed control, not outbound event delivery. Nothing exists to build on
except the surrounding platform (API keys, secret crypto, the SSRF client doctrine,
the migration registry), all of which this design reuses.

**Non-goals (v1):** partner self-serve subscription CRUD (deferred, see §8.3);
inbound webhooks (we already have key-authed endpoints); per-line/lyric-content
payloads (deliberately excluded — see §A.7); native-app event push (that is APNs /
web push, a different channel that already exists).

---

## 2. Verified conventions this design must follow (file anchors)

Each of these was read in-tree on 2026-08-21, not recalled from memory.

| Convention | Anchor | What we take from it |
|---|---|---|
| SSRF-hardened outbound HTTP client | `includes/cuercode_client.php` (`_cuercodeResolveUrl()`, aborting write-callback, `CURLOPT_FOLLOWLOCATION=false`, `CURLPROTO_HTTPS` pin, connect/total timeouts 2s/4s, fail-soft `null`), `includes/intapps_client.php` (`_intappsResolveUrl()`, same shape) | The dial discipline — **but both bind to a TRUSTED configured host; a webhook target is ATTACKER-CONTROLLED**, so this design adds DNS pre-resolution, private-range rejection and IP pinning on top (§6.5). |
| Outbound HMAC signing | `includes/intapps_client.php::intappsSign()` — `hash_hmac('sha256', $rawBody . '.' . $ts, $secret)` | Precedent that HMAC-over-body+timestamp is the house shape. We define our own **Stripe-compatible** construction (`$ts . '.' . $rawBody`, §7.1) because *we* are the contract-issuer here, and partner tooling expects the Stripe shape; `intappsSign()` follows a contract the *gateway* defined — this is not a fork of a shared module. |
| Secrets at rest | `includes/secret_crypto.php` — `secretEncrypt()`/`secretDecrypt()` self-describing `enc:v1:` envelope, legacy-plaintext bridge, `appSettingValueForStorage()` fail-closed ladder | Per-subscription signing secrets are stored under this envelope (§7.2). Note: `secretSettingKeys()` governs **tblAppSettings** writes only — a table column uses the engine directly, as documented in that registry's doc-block ("Reads are PREFIX-driven"). |
| API key infra | `includes/api_keys.php` (`apiKeyGenerate()` show-once, `_apiKeyTableExists()` memoized INFORMATION_SCHEMA gates, `apiKeyEnforceRateLimit()` fail-open fixed-window), `tblApiKeys` family in `schema.sql:863–3560` | The STRICT-safe gate pattern, the fixed-window counter pattern, the `Scope` VARCHAR + reserved-discriminator lessons (`tblApiKeyUsage.Scope` in the UNIQUE key), `ExpiresAt DATETIME` not TIMESTAMP. Future partner CRUD authenticates with a `webhook:manage` scope on the **existing** `tblApiKeys.Scope` — no new auth infra (§8.3). |
| No daemon; periodic work is opportunistic + capped | `includes/service_mode.php` — `serviceMode_retireExpiredCodes()` ("piggy-backed on a mint, rather than in a cron job: … no cron wired to the web app"), `serviceMode_pruneIdleQuickSessions()` (hard row cap, own try/catch, best-effort) called from `api.php:17389` | The drain design (§6) must not assume cron. Cron IS available to the operator (`.sql/cleanup.php`'s doc-block ships a crontab line) but is an **accelerator, never a dependency**. |
| Post-response background work | `manage/editor/api2.php` `import_zip` (~5530–5710): respond `{job_id, poll_url}`, `fastcgi_finish_request()`, then keep working; `manage/editor/api.php:1941` same | The "immediate first attempt" of a webhook delivery runs post-flush in the emitting request (§6.2) — an established precedent, not an invention. |
| Synchronous outbound is bounded or it doesn't ship | `includes/web_push.php::webPushBroadcast()` — "⚠️ SYNCHRONOUS AND BOUNDED. There is no job queue in this deployment… pretending otherwise would produce a request that times out halfway through with no record of where it stopped." | Exactly the failure the durable outbox (§5) prevents: every send is recorded BEFORE it is attempted. |
| Channel discipline (3 docroots, ONE MySQL) | `includes/environment.php::ihymns_environment()` → `'alpha'|'beta'|'production'`; rule #26's "an un-filtered query is the prod-stale class of cross-env leak" | `Channel` columns on subscriptions, events AND deliveries, filtered in **every** enqueue/claim/drain/list query (§4, §A.12). Dormancy gate is a channel allow-list, not a boolean (§9.1), mirroring `INTAPPS_SETTING_ENABLED_CHANNELS`. |
| Migration discipline | rule #19/#20/#41; registry entry shape at `manage/includes/migration-registry.php:2803` (`api-key-requests`), multi-object OR-probe at `:2833` (`shared-setlist-live-link`) | ONE migration + byte-identical `schema.sql` mirror + ONE registry entry with a 3-table OR-probe; `IHYMNS_INCLUDES_DIR` resolution for any shared include (§9.2). |
| Entitlements | `includes/entitlements.php::ENTITLEMENTS` (`'manage_api_keys' => ['global_admin']` at :155), `$ENTITLEMENT_LABELS` in `manage/entitlements.php`; the admin-links red-flag rule (page gate === nav-entry entitlement) | New `manage_webhooks` entitlement, global_admin, one line each place (§7.5). |
| Admin page shape | `manage/api-keys.php` — auth include, entitlement gate, `$activePage`, shared partials, `.admin-table-responsive`, `validateCsrfRequest()` (rule #29), activity-logged mutations | `/manage/webhooks` copies this page's skeleton (§8.1). |
| Audit trail | `includes/activity_log.php::logActivity()` — best-effort, never blocks, per-request flood cap, dotted `entity.verb` action keys (`song.soft_delete`, `service.session.start`, …) | Webhook admin mutations are activity-logged; the **event vocabulary deliberately mirrors the dotted action style** but is a SEPARATE registry (§3.1) — the reasons logActivity is NOT the emission funnel are argued in §5.3. |
| Stable public identity in payloads | `includes/song_public_id.php` (`tblSongs.PublicId`, location-independent IHUID), rule #27 (SongId = Abbr-Number) | Song payloads carry BOTH `song_id` and `public_id` so partners can key on the id that survives renumbering (§3.3). |
| Rate limiting | `includes/read_rate_limit.php::enforceReadRateLimit()` + `includes/rate_limit.php` — fail-open, existence-gated, SQL-side time | The drain endpoint and verification pings are rate-limited with the existing helpers, never a new counter fork (§7.4). |
| Existing dormant registry NOT to conflate | `tblExternalSystems` / `tbl*ExternalRefs` (`schema.sql:4959`) — identity **mapping/sync** registry (#1327), inbound-first | A webhook subscription is a *delivery* concern, not an identity mapping. We reserve an optional `SystemId` FK so a subscription CAN be associated with a registered external system, but the tables stay separate (§4.1, §A stress row S7). |
| API response conventions | `includes/api_envelope.php` (#1201/#1761 v2 `{ok, data}` / `{ok:false, error:{code,message}}`; status is the contract, rule #35) | The drain endpoint + any future partner CRUD answer in v2 envelope shapes; failure kinds are HTTP statuses, never prose (§6.4, §8.3). |

---

## 3. Event vocabulary, payload envelope, versioning

### 3.1 The central registry — `IHYMNS_WEBHOOK_EVENTS`

Location: **`includes/webhook_events.php`** — a NEW light, side-effect-free registry
module (constants + pure validators + payload builders), deliberately separate from
the delivery machinery in `includes/webhooks.php` so the admin page and the emit
call sites can load the vocabulary without pulling curl/claim/backoff code (the same
separation `print_template_schema.php` keeps from the renderer, rule #39).

Event type is a **growable vocabulary → VARCHAR app-validated against this map,
never an ENUM** (rule #20). Map shape (one line per event, the whole how-to for
adding one):

```php
/** type => [human label, entity type (activity-log vocabulary), family] */
const IHYMNS_WEBHOOK_EVENTS = [
    // Catalogue
    'song.created'               => ['Song created',                'song',     'catalogue'],
    'song.updated'               => ['Song updated',                'song',     'catalogue'],
    'song.deleted'               => ['Song deleted (soft)',         'song',     'catalogue'],
    'song.restored'              => ['Song restored',               'song',     'catalogue'],
    'songbook.created'           => ['Songbook created',            'songbook', 'catalogue'],
    'songbook.updated'           => ['Songbook updated',            'songbook', 'catalogue'],
    'songbook.deleted'           => ['Songbook deleted',            'songbook', 'catalogue'],
    'songbook.import_completed'  => ['Bulk import finished',        'songbook', 'catalogue'],
    // Sharing
    'setlist.shared'             => ['Set-list share link minted',  'setlist',  'sharing'],
    // Live
    'service.started'            => ['Service session started',     'service',  'live'],
    'service.ended'              => ['Service session ended',       'service',  'live'],
    // Platform (always deliverable; not user-subscribable content)
    'webhook.verify'             => ['Endpoint verification ping',  'webhook',  'platform'],
    'webhook.test'               => ['Operator test ping',          'webhook',  'platform'],
];
```

`family` groups the admin UI's checkbox list and carries the sensitivity divide
(`live` events reference org/venue context and are the first candidates for
org-scoping — §8.2). Near-term candidates, each ONE map line + one emit call, no
schema: `work.*`, `musician.*`, `publisher.*`, `tune.*`, `media.attached`,
`setlist.share_revoked`, `song.relocated` (the #1343 relocate family is exactly the
event partners keying on SongId need).

Subscription event selectors (`tblWebhookSubscriptions.Events`) are
**space-separated**, mirroring `tblApiKeys.Scope` exactly: an exact type
(`song.updated`), a family wildcard (`song.*`), or `*`. Validated on save against
the map (`webhookEventSelectorValid()`); matched at fan-out by
`webhookEventMatches($selectors, $type)` — one pure function, unit-tested, shared by
save-validation and fan-out (rule #35: one mechanism, not two lists).

### 3.2 The envelope (the JSON body every delivery POSTs)

```json
{
  "id": "evt_9f2c…(32 hex)",
  "type": "song.updated",
  "api_version": "1",
  "channel": "production",
  "occurred_at": "2026-08-21T12:34:56Z",
  "data": { …type-specific, see 3.3… }
}
```

- `id` — `evt_` + `bin2hex(random_bytes(16))` (house token shape, `apiKeyGenerate()`
  precedent). Globally unique; the receiver's **dedupe key** (delivery is
  at-least-once, §6.3).
- `api_version` — the envelope+payload contract version, `"1"`. Evolution rules:
  **additive changes (new keys) do NOT bump it**; a breaking reshape mints `"2"`,
  and the subscription's reserved `ApiVersion` column (§4.1) pins which one it
  receives. This is Stripe's model and it means v2 payloads are a code change, not
  a migration.
- `channel` — `ihymns_environment()` at emit time. Explicit in the body so a partner
  pointing a staging receiver at our alpha channel can assert it (§A.12).
- `occurred_at` — UTC ISO-8601, frozen at emit.
- `data` — the type-specific payload, **frozen at emit time** into
  `tblWebhookEvents.PayloadJson`. A redelivery re-sends the identical body (only the
  signature timestamp differs). Frozen-not-live is deliberate: an event says what
  happened *then*; a partner wanting current state re-fetches via the read API
  (documented in the receiver guide, §10).

**Delivery metadata lives in HEADERS, never the body** (the body must stay
byte-identical across attempts so receiver-side dedupe and our stored-payload model
hold):

```
Content-Type: application/json; charset=UTF-8
User-Agent: iHymns-Webhooks/1.0
X-iHymns-Event-Id: evt_9f2c…
X-iHymns-Event-Type: song.updated
X-iHymns-Delivery-Id: whd_<tblWebhookDeliveries.Id>
X-iHymns-Attempt: 3
X-iHymns-Signature: t=1774123456,v1=<hmac hex>[,v1=<hmac hex under previous secret>]
```

### 3.3 `data` payload principles + v1 shapes

**The one hard rule: identity and metadata, NEVER content.** No lyric lines, no
translations, no media URLs, no credentials, no capability tokens. A webhook that
carried lyrics would be an ungated content feed sitting beside the gating model —
the exact class of hole #1388 closed on `bulk_audio` ("stripping a payload hides an
affordance; it does not protect a file"). Partners fetch content through the read
API where gating, keys and rate limits apply. See §A.7.

```jsonc
// song.created / song.updated / song.deleted / song.restored
"data": {
  "song_id": "MP-1008",            // rule #27 primary id
  "public_id": "XK3TQ9WMPN",       // IHUID permalink id (null on un-migrated install)
  "songbook_abbr": "MP",
  "title": "Amazing Grace",
  "changed_fields": ["Title", "Copyright"],   // updated only; NAMES, never values
  "url": "https://…/song/MP-1008"
}

// songbook.* : { "abbr", "title", "is_official", "url" }
// songbook.import_completed : { "abbr", "songs_created", "songs_updated",
//                               "songs_skipped", "dry_run": false }
// setlist.shared : { "setlist_title", "scope": "view"|"edit",
//                    "owner_org_id": null|int }         // NEVER the token or URL (§A.8)
// service.started / service.ended : { "session_kind": "service", "org_id", "venue_id",
//                    "occurrence_date", "ended_reason": …(ended only) }
//                                                       // NEVER the join code (§A.8)
// webhook.verify : { "challenge": "<32 hex>" }
// webhook.test   : { "note": "Test delivery from /manage/webhooks" }
```

Payload builders live beside the registry in `webhook_events.php`
(`webhookBuildPayload(string $type, array $facts): array`) so there is ONE place
the shapes exist; emit call sites pass raw facts, never hand-rolled JSON (rule #35).

---

## 4. Schema — the one-pass DDL

Three tables, one migration (`appWeb/.sql/migrate-add-webhooks.php`), byte-identical
mirror in `schema.sql`, ONE `migration-registry.php` entry whose probe is the
multi-object OR over all three tables (rule #19). Everything additive, idempotent
(`CREATE TABLE IF NOT EXISTS`), and dormant — the tables do nothing until the §9
gate opens. All retry/TTL instants are `DATETIME` (UTC), never `TIMESTAMP`
(rule #20 / the tblApiKeyIdempotency stress-A3 lesson). All vocab columns VARCHAR.

### 4.1 `tblWebhookSubscriptions`

```sql
CREATE TABLE IF NOT EXISTS tblWebhookSubscriptions (
    Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    Channel       VARCHAR(20)   NOT NULL COMMENT 'alpha | beta | production — the docroot env this subscription belongs to (rule #26). EVERY query filters it; a subscription never receives another channel''s events',
    OrgId         INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Reserved (dormant in v1): org-scoped subscription — receives only events whose payload org matches. NULL = global (admin-created partner feed)',
    SystemId      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Optional link to the tblExternalSystems registry (#1327) so a subscription is associated with a registered external system; informational, never an auth path',
    Label         VARCHAR(120)  NOT NULL COMMENT 'Human label, e.g. "iLyricsDB sync"',
    TargetUrl     VARCHAR(500)  NOT NULL COMMENT 'https:// delivery endpoint (app-validated: https, default port, public host — includes/webhooks.php SSRF gate)',
    TargetHost    VARCHAR(190)  NOT NULL COMMENT 'Lowercased host derived from TargetUrl at save (never client-supplied) — per-host caps + abuse queries without URL parsing in SQL',
    Secret        VARCHAR(500)  NOT NULL COMMENT 'HMAC signing secret (whsec_…): enc:v1 envelope when secret encryption is active, else plaintext bridge (secret_crypto.php). Needed in clear to sign — never a hash',
    SecretPrevious VARCHAR(500) NULL DEFAULT NULL COMMENT 'Previous secret retained during rotation grace — deliveries carry a second v1= signature under it until SecretPreviousExpiresAt',
    SecretPreviousExpiresAt DATETIME NULL DEFAULT NULL COMMENT 'UTC end of the dual-signing rotation grace window; NULL = no rotation in flight',
    Events        VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Space-separated event selectors: exact type, family.* wildcard, or * — app-validated against IHYMNS_WEBHOOK_EVENTS (VARCHAR vocabulary, rule #20). Mirrors tblApiKeys.Scope',
    ApiVersion    VARCHAR(10)   NOT NULL DEFAULT '1' COMMENT 'Payload contract version this subscriber receives — a future breaking payload reshape mints "2" with no migration',
    HeadersJson   JSON          NULL DEFAULT NULL COMMENT 'Reserved (dormant in v1): extra request headers some receivers require, {name:value} app-validated against a header-name allow-list (never Authorization/Host/Content-*)',
    FilterJson    JSON          NULL DEFAULT NULL COMMENT 'Reserved (dormant in v1): finer-than-type delivery filters (e.g. {"songbooks":["MP"]}) — growable vocabulary as JSON, never new columns (rule #28 Capabilities precedent)',
    Status        VARCHAR(30)   NOT NULL DEFAULT 'pending_verification' COMMENT 'pending_verification | active | paused | disabled_failing | revoked — app-validated, VARCHAR not ENUM (rule #20)',
    VerifyToken   CHAR(64)      NULL DEFAULT NULL COMMENT 'Reserved: outstanding async-verification challenge hash (v1 verification is synchronous and stores nothing)',
    VerifiedAt    DATETIME      NULL DEFAULT NULL COMMENT 'UTC instant the endpoint last passed the challenge-echo handshake',
    ConsecutiveFailures INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dead deliveries in a row since the last success — drives the auto-disable ladder',
    FailingSince  DATETIME      NULL DEFAULT NULL COMMENT 'UTC start of the current consecutive-failure run; NULL when healthy',
    LastAttemptAt DATETIME      NULL DEFAULT NULL COMMENT 'Denorm for the admin list (rule #44: earns its read)',
    LastSuccessAt DATETIME      NULL DEFAULT NULL COMMENT 'Denorm for the admin list',
    CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id of the admin who created it',
    CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_ChannelStatus (Channel, Status),
    INDEX idx_TargetHost    (TargetHost),
    INDEX idx_Org           (OrgId),

    CONSTRAINT fk_WebhookSub_Org       FOREIGN KEY (OrgId)     REFERENCES tblOrganisations(Id)   ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_WebhookSub_System    FOREIGN KEY (SystemId)  REFERENCES tblExternalSystems(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_WebhookSub_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)           ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Outbound partner-webhook subscriptions (#1909). Secret is enc:v1-enveloped at rest; Events mirrors tblApiKeys.Scope; Channel walls the 3 docroots (rule #26).';
```

Deliberate NON-columns (rule #44): no `MaxAttempts`/`TimeoutSecs` config columns
(code constants — fewer knobs, no misconfigurable fail-soft bound, the
`CUERCODE_CURL_*` reasoning verbatim); no `DeliveryFormat` (JSON only, ever); no
UNIQUE on `TargetUrl` (two subscriptions to one endpoint with different event sets
are legitimate; the per-host abuse cap is an app-level count on `TargetHost`).

### 4.2 `tblWebhookEvents` — the durable event ledger (outbox)

```sql
CREATE TABLE IF NOT EXISTS tblWebhookEvents (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    EventUid    VARCHAR(40)   NOT NULL COMMENT 'evt_<32 hex> — the id in the envelope; the receiver''s dedupe key',
    Channel     VARCHAR(20)   NOT NULL COMMENT 'ihymns_environment() at emit (rule #26)',
    EventType   VARCHAR(60)   NOT NULL COMMENT 'Dotted type app-validated against IHYMNS_WEBHOOK_EVENTS (VARCHAR vocabulary, rule #20)',
    EntityType  VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'song | songbook | setlist | service | webhook — mirrors the activity-log entity vocabulary for admin filtering',
    EntityId    VARCHAR(190)  NOT NULL DEFAULT '' COMMENT 'The subject''s id as text (SongId, abbr, numeric id) — display/filtering, never a FK (subjects outlive/predate events)',
    PayloadJson MEDIUMTEXT    NOT NULL COMMENT 'The FROZEN full envelope-data JSON built at emit — redelivery is byte-identical (only the signature timestamp moves)',
    OccurredAt  DATETIME      NOT NULL COMMENT 'UTC emit instant (DATETIME not TIMESTAMP, rule #20)',
    ActorUserId INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Who performed the action — internal audit only, NEVER emitted in partner payloads',
    Source      VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Emitting funnel (editor_save | lyrics_ingest | bulk_import | api | admin) — provenance + the idempotency pair below',
    SourceRef   VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional natural idempotency ref from the funnel (e.g. ingest job id) — with Source, re-runs re-emit nothing; NULL rows coexist freely (rule #20)',
    ExpiresAt   DATETIME      NOT NULL COMMENT 'Retention TTL (emit + 30 days) — rows past this are prune-eligible',
    CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_EventUid  (EventUid),
    UNIQUE KEY uq_SourceRef (Source, SourceRef),
    INDEX idx_ChannelOccurred (Channel, OccurredAt),
    INDEX idx_Type            (EventType),
    INDEX idx_Entity          (EntityType, EntityId),
    INDEX idx_Expires         (ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Durable webhook event ledger / outbox (#1909): one frozen payload per event, fanned out to N tblWebhookDeliveries rows. (Source,SourceRef) UNIQUE = idempotent re-emission (rule #20).';
```

Why a separate ledger and not payload-per-delivery: fan-out to N subscriptions
shares ONE frozen payload row (no N copies of the JSON); the ledger is also the
admin "recent events" view and the substrate for a future partner
`GET /events`-style reconciliation read — both free once it exists.

### 4.3 `tblWebhookDeliveries` — per-subscription delivery state + dead-letter surface

One row per (event × subscription). Attempts mutate the row; the bounded per-attempt
history rides in `AttemptLogJson` (≤ MAX_ATTEMPTS entries of `{at, status, ms, err}`
— display-only triage data, capped by construction, so no per-attempt table).

```sql
CREATE TABLE IF NOT EXISTS tblWebhookDeliveries (
    Id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    EventId        BIGINT UNSIGNED NOT NULL COMMENT 'FK tblWebhookEvents.Id — the frozen payload',
    SubscriptionId INT UNSIGNED    NOT NULL COMMENT 'FK tblWebhookSubscriptions.Id',
    Channel        VARCHAR(20)     NOT NULL COMMENT 'Denorm of the subscription channel (app-derived) so the claim query is index-only with no join (rule #26: filtered in EVERY claim/drain)',
    Status         VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending | delivering | succeeded | failed | dead | cancelled — app-validated, VARCHAR not ENUM (rule #20). failed = scheduled for retry; dead = attempts exhausted (dead-letter); cancelled = subscription revoked/paused mid-queue',
    AttemptCount   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Attempts made since enqueue (reset to 0 by an admin re-drive; history stays in AttemptLogJson)',
    NextAttemptAt  DATETIME        NOT NULL COMMENT 'UTC due time — the claim predicate (DATETIME not TIMESTAMP, rule #20)',
    ClaimToken     CHAR(32)        NULL DEFAULT NULL COMMENT 'Lease held by the drain pass that claimed this row — two concurrent drains can never double-send',
    ClaimedAt      DATETIME        NULL DEFAULT NULL COMMENT 'UTC lease start; a delivering row older than the lease timeout is reclaimable (crashed worker recovery)',
    LastHttpStatus SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Last response status; 0 = transport error (DNS/TLS/timeout/refused)',
    LastError      VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Sanitised last failure detail for triage — never response bodies, never secrets',
    LastAttemptAt  DATETIME        NULL DEFAULT NULL,
    DeliveredAt    DATETIME        NULL DEFAULT NULL COMMENT 'UTC instant of the 2xx',
    AttemptLogJson JSON            NULL DEFAULT NULL COMMENT 'Bounded per-attempt history [{at,status,ms,err}] — capped at the attempt ceiling, display-only',
    ExpiresAt      DATETIME        NOT NULL COMMENT 'Retention TTL (enqueue + 30 days) — prune-eligible past this',
    CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Event_Subscription (EventId, SubscriptionId),
    INDEX idx_Due          (Channel, Status, NextAttemptAt),
    INDEX idx_Subscription (SubscriptionId, CreatedAt),
    INDEX idx_Expires      (ExpiresAt),

    CONSTRAINT fk_WebhookDel_Event FOREIGN KEY (EventId)        REFERENCES tblWebhookEvents(Id)        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_WebhookDel_Sub   FOREIGN KEY (SubscriptionId) REFERENCES tblWebhookSubscriptions(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-subscription webhook delivery queue, retry state and dead-letter surface (#1909). uq_Event_Subscription = fan-out idempotency; idx_Due = the drain claim predicate.';
```

`uq_Event_Subscription` makes fan-out idempotent (a re-run of the fan-out for an
event INSERT-IGNOREs into existing rows — the `tblOrganisationExternalRefs`
`(Source,SourceRef)` lesson applied to the fan-out join).

---

## 5. Emission — where events are born

### 5.1 The one emitter

`includes/webhooks.php::webhookEmit(string $type, array $facts, array $opts = []): void`

- Validates `$type` against the registry; builds the frozen payload via
  `webhookBuildPayload()`; inserts ONE `tblWebhookEvents` row; fans out ONE
  `tblWebhookDeliveries` row per matching **active** subscription on the current
  channel (`Status='active'`, selector match, `NextAttemptAt = now`).
- **Best-effort, never blocking, never throwing** — the entire body inside
  `try/catch (\Throwable)` → `error_log('[webhooks] …')`, exactly the
  `logActivity()` / #1907 medley-lockstep posture. A webhook hiccup must never fail
  a song save. Consequence stated honestly: emission is best-effort; **from the
  ledger onward** delivery is durable at-least-once. (The alternative — emitting
  inside each funnel's transaction — is rejected: the funnels are not uniformly
  transactional, and a webhook-table lock wait inside the save path is a new way
  for saves to fail.)
- **Fully gated** (§9.1): gate closed / tables absent / zero active subscriptions ⇒
  return before any insert. The zero-subscriptions short-circuit is a memoized
  per-request `COUNT(*)` on `(Channel, Status)` — the hot-path cost when idle is
  one indexed count per request *at most*, and zero once memoized.
- **Per-request emit cap** (`WEBHOOK_EMIT_PER_REQUEST_CAP = 200`, mirroring
  `IHYMNS_LOG_PER_REQUEST_CAP`) — a runaway loop cannot flood the ledger.

### 5.2 v1 emit call sites (each one line beside the existing `logActivity()`)

| Event | Funnel (verified in-tree) |
|---|---|
| `song.created` / `song.updated` | `manage/editor/save_song_core.php::editorSaveSongCore()` (BOTH api.php shim + api2 flow through it, rule #29) + `component_upsert`/`components_replace` (api2) coalesced per request + `lyrics_ingest` (api.php) |
| `song.deleted` / `song.restored` | `includes/song_soft_delete.php` funnels (the `song.soft_delete` logActivity sites) |
| `songbook.created/updated/deleted` | `manage/songbooks.php` handlers (the `songbook.create/edit/delete` logActivity sites) |
| `songbook.import_completed` | the bulk-import worker (`_bulkImport_processZip` completion + `import_file` non-dry-run completion) — bulk funnels emit ONE summary event, **never** per-song events (§A.9) |
| `setlist.shared` | `api.php` `case 'setlist_share'` (:2243), after the mint core succeeds |
| `service.started` / `service.ended` | `api.php` `service_session_start` (:18110) / `service_session_end` (:18253) + `serviceMode_pruneIdleQuickSessions()` auto-close (an auto-closed session still ended) |

Multiple same-request saves of one song may emit multiple `song.updated` events —
accepted (receivers dedupe on `changed_fields` semantics if they care; coalescing
is a receiver concern, not ours).

### 5.3 Rejected alternative: bridging off `logActivity()`

Tempting (it is already the ONE funnel every mutation calls) and rejected for four
reasons worth recording: (1) `logActivity()` is *contractually* best-effort with a
flood cap — silently lossy by design, wrong substrate for a delivery guarantee;
(2) its `$details` routinely contain before/after **values** we must never ship to
partners (§3.3's identity-not-content rule would need a per-action scrub map —
a second vocabulary); (3) its action vocabulary is freeform and internal
(`admin.musicians.…`) — coupling would freeze it; (4) audit logging and partner
notification are different products that would now fail together. The two stay
siblings at the call site, one line each.

---

## 6. Delivery — how a webhook actually leaves shared PHP hosting

### 6.1 The options, against this codebase's reality

Reality (verified): web-only operator; migrations web-run; **no daemon**; periodic
work is opportunistic-piggyback-with-hard-caps (`service_mode.php`); cron exists as
an operator OPTION (`.sql/cleanup.php` ships a crontab line) but nothing in the app
depends on it; `fastcgi_finish_request()` post-response work is an established
pattern (`api2.php` `import_zip`).

| Option | Mechanism | Verdict |
|---|---|---|
| **A. Synchronous in-request** | Send inside the save request before responding | ❌ N subscriptions × 5s timeout added to every save; a slow partner endpoint makes *our* editor slow; `webPushBroadcast()`'s own doc-block warns against exactly this un-recorded halfway-timeout shape. |
| **B. Pure opportunistic piggyback** | Queue; later requests drain a little each | Workable but delivery latency = site traffic; a quiet Tuesday night delays a `service.started` event until someone browses. Also taxes innocent requests unless post-flush. |
| **C. Pure external cron** | Queue; `webhook-drain` hit by cPanel cron / monitor every minute | Reliable + simple, but a hard dependency on deploy-time ops nothing else in the app has; un-configured = webhooks silently never deliver. |
| **D. Hybrid outbox (RECOMMENDED)** | Durable queue always; immediate first attempt post-flush in the emitting request; retries/backlog drained by a key-authed drain endpoint (cron/monitor) **and** a bounded post-flush piggyback as the no-cron fallback | Matches every house precedent; zero user-visible latency; first attempt is near-instant on FPM; cron is an accelerator, not a dependency. |

**Recommendation: D.** Details:

### 6.2 Immediate first attempt (post-flush)

After the emitting request's response is flushed — `fastcgi_finish_request()` when
available, the exact `api2.php:5702` shape — the same request calls
`webhookDrainPass($db, ['budget_ms' => 5000, 'max' => 3, 'prefer_event' => $eventId])`:
claim up to 3 due deliveries (its own first, then any other due retries), strict
wall-clock budget, then stop. **No FPM ⇒ no post-flush work at all** (never add
latency a user can feel); the backlog path picks it up.

### 6.3 Retry, backoff, dead-letter

- Schedule (code constant `WEBHOOK_RETRY_SCHEDULE`, seconds after the previous
  attempt): `[60, 300, 1800, 7200, 21600, 43200, 86400, 86400]` — 8 retries + the
  initial attempt ≈ **2.2 days** of coverage, ±20 % jitter so a dead endpoint's
  backlog doesn't thunder back in lock-step.
- Success = any **2xx**. Everything else (including 3xx — we never follow
  redirects) = failure: `AttemptCount++`, `Status='failed'`,
  `NextAttemptAt = now + schedule[n] ± jitter`, append to `AttemptLogJson`.
- `AttemptCount > count(schedule)` ⇒ `Status='dead'` (**dead-letter**): visible on
  the admin delivery log, re-drivable (§8.1). Subscription counters update
  (`ConsecutiveFailures`, `FailingSince`); a success resets both.
- **Auto-disable ladder**: `ConsecutiveFailures ≥ 20` AND `FailingSince` older than
  3 days ⇒ subscription `Status='disabled_failing'`; pending/failed deliveries →
  `cancelled`. Surfaced as a red badge on `/manage/webhooks`; re-enable is one
  click (+ fresh verification ping). Numbers are code constants.
- **At-least-once semantics, documented**: a timeout after the receiver processed
  the POST retries anyway; receivers dedupe on `X-iHymns-Event-Id` / `id`.
- Claim is race-safe without SELECT-FOR-UPDATE hot rows: one atomic
  `UPDATE … SET Status='delivering', ClaimToken=?, ClaimedAt=UTC_TIMESTAMP()
  WHERE Channel=? AND Status IN ('pending','failed') AND NextAttemptAt <= UTC_TIMESTAMP()
  ORDER BY NextAttemptAt LIMIT ?`, then `SELECT … WHERE ClaimToken=?`. A
  `delivering` row whose `ClaimedAt` is older than `WEBHOOK_CLAIM_LEASE_SECS`
  (600) is reclaimable — crashed-worker recovery.

### 6.4 The drain endpoint — `webhook-drain.php`

Standalone docroot script (the `qr.php`/`og-image.php` shape — never a new hot-path
branch in 20k-line `api.php`):

- `GET /webhook-drain.php?key=<drain key>` (also accepted as `X-Drain-Key`).
  Key = `webhook_drain_key` in `tblAppSettings`, registered in
  `secretSettingKeys()` (it IS an app-settings secret, unlike the per-subscription
  ones), generated on the `/manage/configuration` webhooks card, compared with
  `hash_equals()`. Wrong/absent key ⇒ **403 no body**. Gate closed / tables absent
  ⇒ **503 no body** (the `qr.php` degrade shape).
- Runs `webhookDrainPass()` with the cron budget (`max 25` deliveries,
  `budget 20s`), then one bounded prune pass (≤ 200 expired
  event/delivery/idempotency rows — the `serviceMode` §4.1 cap rationale), then
  answers v2-envelope JSON `{ok:true, data:{claimed, sent, failed, dead, pruned}}`
  — statuses are the contract (rule #35).
- Rate-limited via the existing `includes/rate_limit.php` fixed-window helper
  (fail-open) so a leaked key cannot be used to hammer the DB.
- Wired by the operator as cPanel cron (`curl -fsS https://…/webhook-drain.php?key=…`
  every minute — documented beside `.sql/cleanup.php`'s crontab line) or an
  UptimeRobot-style monitor. `.sql/cleanup.php` also gains the same expired-row
  prune for installs that already run it nightly.
- **No-cron fallback:** the §6.2 post-flush pass on emitting requests also drains
  up to 2 due *retries* beyond its own event, so a cron-less install still
  progresses its backlog whenever anything emits. Documented honestly: without
  cron, retry latency is traffic-shaped.

### 6.5 The outbound dialer — SSRF hardening beyond the house clients

`_webhookHttpPost(string $url, string $body, array $headers): array{status,ms,err}`
in `includes/webhooks.php`. Starts from the `cuercode_client.php` discipline and
adds what an **attacker-controlled** target demands:

1. **Scheme/port**: `https://` only, port 443 only (explicit other ports refused).
   One test carve-out mirroring `cuercode_allow_loopback`: `webhook_allow_loopback`
   = '1' permits `http://127.0.0.1|::1|localhost[:port]` so a local stub receiver
   can exist in tests — refused otherwise.
2. **Save-time AND dial-time validation** (DNS changes between the two): parse
   with `parse_url`; host must be a DNS name or literal IP; resolve **all** A/AAAA
   records; EVERY resolved address must pass `_webhookIpIsPublic()` — reject
   loopback (127/8, ::1), RFC1918 (10/8, 172.16/12, 192.168/16), link-local +
   cloud metadata (169.254/16, fe80::/10), CGNAT (100.64/10), ULA (fc00::/7),
   multicast/reserved (224/4, 240/4, ff00::/8, 0/8), and IPv4-mapped IPv6 forms
   (`::ffff:10.0.0.1`) — checked after normalisation via `inet_pton`. A pure
   function with a unit-tested truth table (rule #34: mutation-proven).
3. **Pin the dial to a validated IP** with `CURLOPT_RESOLVE` (`host:443:ip`) —
   defeats DNS rebinding between the check and the connect. TLS still verifies
   against the hostname (SNI + `CURLOPT_SSL_VERIFYHOST=2` are host-based, so
   pinning the IP does not weaken verification).
4. **Never follow redirects** (`CURLOPT_FOLLOWLOCATION=false` — a 3xx is a
   delivery failure, closing the classic redirect-to-internal bypass);
   `CURLPROTO_HTTPS` pin; `CURLOPT_SSL_VERIFYPEER=true`.
5. **Self-host block**: refuse a TargetHost equal to any of our own docroot hosts
   (from config) — no event loops through our own API.
6. **Response bound**: aborting write-callback capped at **64 KiB** (we need the
   status + the small verification echo, nothing more); house-band timeouts
   `WEBHOOK_CURL_CONNECT_TIMEOUT = 3`, `WEBHOOK_CURL_TIMEOUT = 5` (a partner
   endpoint is allowed to be slower than CueRCode, but a drain pass budget divides
   by this ceiling).
7. **Fail-soft**: every failure is a return value (`status:0, err:'…'`), never a
   throw — the caller records it as an attempt.

---

## 7. Security

### 7.1 HMAC signing + replay protection

- Header: `X-iHymns-Signature: t=<unix seconds>,v1=<hex hmac>` — Stripe's scheme
  verbatim, because partner ecosystems have verifiers for it. Signed string:
  `"$t.$rawBody"`; `hash_hmac('sha256', …, $secret)`. During a rotation grace
  window the header carries **two** `v1=` entries (current + `SecretPrevious`);
  receivers accept if ANY matches (documented).
- The timestamp inside the signed string is the **replay defence**: the receiver
  guide (§10) instructs partners to reject `|now - t| > 300s` and compare with a
  constant-time equality. Each retry re-signs with a fresh `t` over the identical
  body.
- One signing helper `webhookSign(string $rawBody, int $ts, string $secret): string`
  — the doc-block explicitly notes it deliberately differs from `intappsSign()`'s
  `body.ts` order because there WE consume the gateway's contract and here WE issue
  the contract (pre-empting a well-meaning "unify the two" refactor that would
  break every partner verifier).

### 7.2 Secret custody

- Minted server-side only: `whsec_` + `bin2hex(random_bytes(24))` (192-bit).
  Never client-supplied (a chosen secret enables cross-service signature-oracle
  games). **Reveal is allowed** (unlike API keys we necessarily store it
  recoverably to sign): a "Reveal secret" control gated `manage_webhooks`,
  activity-logged (`webhook.secret.reveal`) — honest, since the DB row holds it
  anyway; hiding it would be theatre.
- At rest: written through the `appSettingValueForStorage()` **ladder logic**
  (extracted reasoning, not the function — that one is tblAppSettings-specific):
  when `secret_encryption_active='1'`, `secretEncrypt()` or refuse the save
  (fail-closed — never silently store plaintext on an active-encryption install);
  otherwise plaintext bridge. Reads via `secretDecrypt()` (prefix-driven, handles
  both). `webhook_drain_key` (a tblAppSettings value) IS added to
  `secretSettingKeys()`.
- Never logged: `LastError`/`AttemptLogJson` carry status + curl error class only;
  the activity log records subscription ids, never secrets (the
  `activity_log.php` PRIVACY contract).
- Known gap, accepted + recorded: `secretRotateReencrypt()` re-wraps only
  tblAppSettings — a **master-key** rotation leaves webhook secrets under the old
  keyid until touched. Safe while the old keyid is retained in the keyset (decrypt
  reads the keyid from the envelope); a follow-up "re-wrap table secrets" card is
  filed at implementation time (§A.14).

### 7.3 Endpoint verification handshake

New/URL-changed subscriptions start `pending_verification` and receive nothing.
"Send verification ping" (auto-fired on create) POSTs a real signed
`webhook.verify` event whose `data.challenge` is 32 fresh hex chars; the endpoint
must answer **2xx with the challenge string contained in the (≤64 KiB) response
body**. Match ⇒ `active` + `VerifiedAt`. Synchronous in the operator's request —
nothing stored (`VerifyToken` stays a reserved column for a future async flow).

Echo-required (not GitHub's any-2xx ping) is the anti-abuse control: it proves the
registrant **controls the endpoint's response**, so a subscription cannot be
pointed at an arbitrary third-party URL that happens to 200, then used to spray it
with signed traffic. That matters little while creation is global_admin-only and a
lot the day org-admins can create subscriptions (§8.2) — designing it in now is
the one-pass discipline applied to behaviour.

### 7.4 Rate + size limits

- Envelope bodies are small by construction (identity + metadata; `PayloadJson`
  practical ceiling ~a few KiB — a defensive 256 KiB emit-time cap refuses
  oversized payload builds with an error_log).
- Per-drain-pass budgets (§6.3/§6.4) bound outbound volume;
  `WEBHOOK_MAX_SENDS_PER_PASS`/`per-host per-pass cap of 5` keep one dead-slow
  host from eating a whole pass's budget.
- Per-host subscription cap (default 5 active subscriptions per `TargetHost` per
  channel, app-validated at save) — blunts using us as a DDoS multiplier.
- The drain endpoint + verification ping are rate-limited through the existing
  `includes/rate_limit.php` helpers (fail-open, rule #28-C posture).

### 7.5 Who can create subscriptions

v1: new entitlement **`manage_webhooks` => `['global_admin']`** (one line in
`includes/entitlements.php` beside `manage_api_keys`, a label in
`manage/entitlements.php::$ENTITLEMENT_LABELS`, and the `/manage/webhooks` nav
entry in `admin-links.php` advertising the **same** check the page enforces — the
#1587 red-flag rule). Org-admin self-serve is the designed-for future (§8.2), not
v1: the `live` family payloads expose org/venue activity, and the abuse surface of
URL registration wants the verification + cap machinery proven first.

---

## 8. Surfaces

### 8.1 `/manage/webhooks` (admin, v1)

Skeleton copied from `manage/api-keys.php` (auth include → entitlement gate →
`$activePage` → shared partials; every mutation POSTs with
`validateCsrfRequest()`; every mutation `logActivity()`d under `webhook.*` keys).

- **Subscriptions list** — `.admin-table-responsive` + sortable headers +
  `data-col-priority` (#842/#844): Label, Target (host, truncated), Events,
  Status badge (incl. `disabled_failing` red), LastSuccessAt,
  ConsecutiveFailures.
- **Create / edit** — Label, https URL, event checkboxes grouped by family
  (rendered FROM `IHYMNS_WEBHOOK_EVENTS` — never a typed list), optional
  ExternalSystem link. On create: secret shown, verification ping auto-fired,
  result shown inline.
- **Per-subscription actions** — Verify (re-ping), Pause/Resume, **Rotate secret**
  (mints new, moves old to `SecretPrevious` with a 24h
  `SecretPreviousExpiresAt`, shows the new one), Reveal secret (logged), Send
  test (`webhook.test`), Delete (CASCADE — type-to-confirm, the §2 guard shape).
- **Delivery log** — recent `tblWebhookDeliveries` for a subscription (or all):
  event type, status, HTTP code, attempts, NextAttemptAt, expandable
  `AttemptLogJson`. **Re-drive** on `dead`/`failed`/`cancelled`: reset
  `Status='pending'`, `AttemptCount=0`, `NextAttemptAt=now` (history preserved in
  the log; actor recorded via `logActivity('webhook.delivery.redrive', …)` —
  rule #44: no redrive columns). **Replay event** fans a ledger event out to one
  subscription again (`uq_Event_Subscription` makes it idempotent if the row
  exists — the existing row is re-driven instead).
- **Recent events** — the `tblWebhookEvents` view (type/entity/time/payload
  preview), the debugging answer to "did the event even fire?".

`/manage/configuration` gains a **Webhooks card**: `webhooks_enabled_channels`,
`webhook_drain_key` (generate/regenerate), `webhook_allow_loopback` (test knob),
and drain-health readout (due-now count, oldest due age, last drain time — the
last stored in `tblAppSettings.webhook_last_drain_at` by the drain pass so
"cron isn't wired" is visible, not silent).

### 8.2 Org-scoped subscriptions (designed now, dormant in v1)

`OrgId` on the subscription + `FilterJson` carry the whole future: an org-admin
surface (mirroring `my-organisations.php`) minting subscriptions whose fan-out
predicate adds "event's org context = subscription.OrgId" for `live`/`sharing`
families and (via FilterJson `{"songbooks":[…]}`) catalogue slices. Fan-out
already resolves per-subscription; the predicate tightens with **no schema
change**. Entitlement then: a new `manage_org_webhooks` line — again no schema.

### 8.3 Partner API CRUD (deferred; nothing blocks it)

When partners self-manage: `webhook_subscription_*` actions in `api.php`
authenticated by the **existing** `apiKeyAuthorize($db, 'webhook:manage')` — scope
is a value in `tblApiKeys.Scope` (VARCHAR, no migration), rate-limited by the
existing `apiKeyEnforceRateLimit()`, answering v2 envelopes. The mint-response
truth rule (#40/#35) applies: the server may clamp events/URL and the client reads
back what was stored. Not in v1 because the human surface must prove the model
first (the api-platform-strategy Phase-A/B ordering logic).

---

## 9. Dormancy + rollout

### 9.1 The gate ladder (every layer independently sufficient)

1. **Setting**: `webhooks_enabled_channels` (CSV of `alpha|beta|production`,
   default **empty** = fully dormant). A channel allow-list, not a boolean,
   because tblAppSettings is shared by all three docroots (the
   `INTAPPS_SETTING_ENABLED_CHANNELS` precedent) — enables an alpha-only soak.
   `webhooksEnabled()` memoizes per request.
2. **Schema gates**: memoized INFORMATION_SCHEMA probes (the `_apiKeyTableExists()`
   pattern) before every touch — an un-migrated install (3 docroots, one MySQL,
   web-run migrations, STRICT mysqli) no-ops cleanly, never 500s (rule #28-C).
3. **Zero-subscription short-circuit**: no active subscriptions on this channel ⇒
   `webhookEmit()` returns before building anything.
4. **Fail-open everywhere**: every emit/drain/prune body is try/catch →
   `error_log`; webhooks can only ever lose a webhook, never break a save, a page,
   or the drain caller.

**Verified no-op**: with the setting empty, `webhookEmit()` performs zero DB
writes and `webhook-drain.php` answers 503 — asserted by a test that diffs
before/after DB state across a full emit call with the gate closed (the
content-gating "verified byte-identical" doctrine applied at our scale).

### 9.2 Migration + registry

`appWeb/.sql/migrate-add-webhooks.php`: the three §4 CREATEs, byte-identical to
the `schema.sql` mirror (incl. COMMENT text); no shared includes needed (pure
DDL) — if one ever is, the `IHYMNS_INCLUDES_DIR` resolution (rule #41), never a
`/public_html/` literal. ONE `migration-registry.php` entry:

```php
'webhooks' => [
    'script' => 'migrate-add-webhooks.php',
    'card' => [ /* title: 'Partner webhooks platform (#1909)' … */ ],
    /* Multi-object OR-probe (rule #19): pending until ALL THREE exist. */
    'probe' => static fn(\mysqli $db) =>
        !_migProbe_tableExists($db, 'tblWebhookSubscriptions')
        || !_migProbe_tableExists($db, 'tblWebhookEvents')
        || !_migProbe_tableExists($db, 'tblWebhookDeliveries'),
],
```

### 9.3 Commit phasing (one PR, atomic revertable commits)

1. **C1 — schema (dormant)**: migration + schema.sql mirror + registry entry.
   Nothing reads the tables yet.
2. **C2 — core modules + tests**: `includes/webhook_events.php` (registry,
   selector matcher, payload builders, envelope) + `includes/webhooks.php`
   (emit/fan-out, sign, SSRF dialer, claim/attempt/backoff, prune) + unit tests:
   the `_webhookIpIsPublic()` truth table, a signing test vector, selector
   matching, backoff schedule — each **mutation-proven** (rule #34: break it, see
   red, restore).
3. **C3 — emission wiring**: the §5.2 one-liners at the funnels. Behind the closed
   gate this commit is a verified no-op.
4. **C4 — delivery**: post-flush first attempt, `webhook-drain.php`, prune wiring
   (+ the `cleanup.php` addition).
5. **C5 — admin surface**: `/manage/webhooks`, `manage_webhooks` entitlement +
   label + `admin-links.php` entry, configuration card, activity-log keys.
6. **C6 — docs + guards**: api-docs.yaml (envelope + per-event `data` schemas as
   components, the drain endpoint, a `callbacks:`-style receiver contract);
   `wiki/Webhooks.md` + API-Reference cross-link; `manage/help.php` topic;
   CHANGELOG/ProjectBrief/README touch-ups; CI guard
   `tests/php/test-webhook-registry.php` — tree-derived (rule #34): every
   `webhookEmit('type'` literal in the tree exists in `IHYMNS_WEBHOOK_EVENTS`,
   every non-`platform` map entry has ≥1 emit call site, and the admin page
   renders events from the map (no typed list) — the `test-event-names.js`
   dispatcher/listener lockstep shape.

Rollout: merge → run the card on alpha → set `webhooks_enabled_channels=alpha` →
register a test subscription against a stub receiver (webhook.site-class or the
loopback stub) → soak: verify sign/retry/dead-letter/re-drive → beta → production.

---

## §A — Adversarial: abuse vectors, failure modes, second-migration stress

### Abuse vectors + mitigations

| # | Vector | Mitigation (all specified above) |
|---|---|---|
| A.1 | **SSRF** — target URL aimed at 169.254.169.254, RFC1918, localhost, our own DB host | https+443 only; DNS pre-resolve; every A/AAAA must pass the public-IP truth table incl. v4-mapped-v6; validated at save AND at dial (§6.5.2) |
| A.2 | **DNS rebinding** — public IP at save, internal at dial | Dial-time re-resolve + `CURLOPT_RESOLVE` pin to the validated IP (§6.5.3) |
| A.3 | **Redirect bounce** — endpoint 302s to an internal host | Redirects never followed; 3xx = failure (§6.5.4) |
| A.4 | **Slow-loris receiver** starves drains | 3s/5s timeouts; per-pass wall-clock budget; per-host per-pass cap; lease claim (§6.3, §7.4) |
| A.5 | **DDoS multiplication** — register a victim URL, hammer saves | global_admin-only creation (v1); challenge-echo proves endpoint control; per-host subscription cap; backoff collapses volume at a failing host (§7.3, §7.4) |
| A.6 | **Secret exfiltration** via logs/UI | Secrets never in LastError/AttemptLogJson/activity log; reveal entitlement-gated + logged; enc:v1 at rest (§7.2) |
| A.7 | **Content leak** — webhooks as an ungated lyrics/media feed beside the gating model (the #1388 class) | The identity-not-content payload rule; `changed_fields` = names only; builders centralised so a leak needs a reviewed change to ONE file; CI guard can grep builders for banned keys (`lyrics`, `lines`, media URLs) |
| A.8 | **Capability leak** — setlist share token / service join code in a payload | Explicitly excluded from the §3.3 shapes; the share token IS the capability (#40) and the join code IS proof-of-presence (#26) — neither ever leaves by webhook |
| A.9 | **Event storm** — 5k-song bulk import ⇒ 5k×N deliveries in one request | Bulk funnels emit ONE `songbook.import_completed`; per-request emit cap 200; fan-out rows are cheap but bounded anyway |
| A.10 | **Double-send** — concurrent drains (cron + post-flush overlap) | Atomic claim-by-UPDATE with ClaimToken; lease timeout for crashed claims; at-least-once documented, receiver dedupe by event id |
| A.11 | **Replay** against the receiver | `t` inside the signed string + documented 300s tolerance (§7.1) |
| A.12 | **Cross-channel leak** — alpha test events hitting a production partner | `Channel` on all three tables, filtered in EVERY fan-out/claim/list query (rule #26's prod-stale lesson); channel in the envelope so receivers can assert |
| A.13 | **DB bloat** — unbounded ledger/delivery growth | `ExpiresAt` stamped at insert (30d); pruned by the drain pass + `cleanup.php`; caps per pass |
| A.14 | **Master-key rotation strands table secrets** | Envelope carries keyid; old keys retained during overlap; follow-up "re-wrap table-held secrets" card filed at implementation (§7.2) |
| A.15 | **Drain-key leak** | Key grants only "spend our own budget faster"; rate-limited; regenerable on the configuration card; hash_equals compare |
| A.16 | **Loop** — subscription pointed at our own API | Self-host block list (§6.5.5) |

### Failure modes (and their honest behaviour)

- **Emit-time DB hiccup** → event lost (best-effort emission, §5.1), error_log'd.
  Documented: webhooks are notifications, not the system of record; partners
  reconcile via the read API.
- **Un-migrated docroot** → probes fail → clean no-op. Once one docroot runs the
  card, all three share the tables (one MySQL); a docroot running OLDER code
  simply doesn't emit new event types — deliveries only ever send stored payloads,
  so code skew cannot corrupt.
- **No cron configured** → retries progress only on emitting traffic (§6.4
  fallback); the configuration card's drain-health readout makes this VISIBLE
  (`webhook_last_drain_at`), not silent — the rule #30 silent-no-op lesson applied
  to ops.
- **Partner endpoint dead for a week** → 2.2-day retry window exhausts → `dead`
  rows + auto-disable + red badge; re-drive after they recover. Events older than
  retention are gone — stated in the receiver guide.
- **PHP dies mid-attempt** → `delivering` row with a stale lease → reclaimed after
  600s → retried (at-least-once holds).

### The second-migration stress test ("what would force an ALTER?")

| # | Foreseeable change | Absorbed by |
|---|---|---|
| S1 | New event type | One `IHYMNS_WEBHOOK_EVENTS` line — no schema |
| S2 | Breaking payload reshape | `api_version` envelope field + `ApiVersion` subscription column (already present) |
| S3 | Org-scoped / self-serve subscriptions | `OrgId` (present) + entitlement line |
| S4 | Finer delivery filters (per-songbook, language) | `FilterJson` (present; JSON growable vocabulary, the rule-#28 Capabilities precedent) |
| S5 | Receiver needs a custom header | `HeadersJson` (present, allow-listed names) |
| S6 | Secret rotation w/o partner hard-cutover | `SecretPrevious` + `SecretPreviousExpiresAt` (present) |
| S7 | Tie a subscription to a registered external system | `SystemId` FK (present); the registry itself stays #1327's |
| S8 | Partner API CRUD | `tblApiKeys.Scope` value `webhook:manage` — no schema |
| S9 | Async endpoint verification | `VerifyToken` reserved |
| S10 | New delivery status / subscription state | VARCHAR vocab + central map — no schema |
| S11 | Bigger payloads | MEDIUMTEXT (16 MB) already |
| S12 | Per-attempt forensic detail | `AttemptLogJson` bounded by the attempt ceiling; if a true per-attempt table is ever wanted it is a NEW additive table, not an ALTER |
| S13 | Idempotent emission from re-runnable funnels | `(Source, SourceRef)` UNIQUE (present) |
| S14 | Signed-secret algorithm upgrade | `v1=` is versioned in the header; a `v2=` is code-only |

Deliberately NOT reserved (rule #44's "no field the app acts on nothing for",
argued case-by-case): per-subscription retry tuning (code constants — one
fail-soft band for everyone), delivery formats other than JSON, a
`LastPingedAt`-style vanity column (the activity log answers it), event
priorities (the queue is small; ordering is `NextAttemptAt`).

---

## §B — Owner decisions surfaced by this design

Presented per the house decision shape. **None of these blocks C1/C2** (schema +
core are identical under every option); B1 should be answered before C4.

### B1 — Delivery mechanism on shared hosting (the big one)

- **The decision**: how retries/backlog get drained — cron-required, traffic-only,
  or the hybrid.
- **Why it's an owner call**: it decides whether *you* wire a cPanel cron /
  monitor per environment, and what delivery-latency promise we print in the
  partner docs.
- **Options**: (a) **Hybrid** (§6 as designed): near-instant first attempt,
  cron-accelerated retries, traffic-shaped fallback, drain-health visible on
  /manage. (b) Cron-required: simplest code, but un-wired cron = silently dead
  retries — the exact silent-failure class this repo keeps re-learning. (c)
  Traffic-only: zero ops, unbounded retry latency on quiet nights.
- **Recommendation**: **(a) Hybrid** — it is the only option that is both honest
  without ops and fast with them, and it matches every existing house precedent
  (post-flush work, opportunistic capped passes, cleanup.php's optional cron).
- **Need back**: "hybrid" / "cron-required" / "traffic-only".

### B2 — v1 event list

- **The decision**: confirm the §3.1 v1 vocabulary (song.*, songbook.* +
  import_completed, setlist.shared, service.started/ended) — or trim/extend.
- **Why**: which happenings partners may observe is a product/data-sharing
  question (service.* exposes org/venue liveness to a global subscriber).
- **Recommendation**: ship the list as designed; if any hesitation on `live`
  events, drop `service.*` from v1 — it is one map line + one emit line to add
  later, cost of deferral ≈ zero.
- **Need back**: "list as designed" or the trimmed set.

### B3 — `setlist.shared` payload minimality

- **The decision**: whether the payload may include the share **URL**.
- **Why**: the token IS the capability (rule #40); a webhook carrying it extends
  the trust boundary to every partner's logs.
- **Recommendation**: **no URL/token** (as designed) — partners are told a share
  happened, not handed the key. Trivially reversible per-subscription later via
  FilterJson-style opt-in if a real integration needs it.
- **Need back**: confirm "no token" (or name the integration that needs it).

### B4 — Who creates subscriptions in v1

- **The decision**: global_admin-only (`manage_webhooks`) vs also org-admin
  self-serve at launch.
- **Recommendation**: **global_admin-only** (as designed); the schema already
  carries org-scoping, so opening it later is an entitlement line + a fan-out
  predicate, not a migration. Verification-echo + caps should soak first.
- **Need back**: "global-only" / "include org-admin".

### B5 — Retention windows

- **The decision**: 30-day event/delivery retention (both), 24h rotation grace.
- **Why**: storage vs "re-drive last month's outage" forensics; pure constants.
- **Recommendation**: 30/30/24h as designed — defensible defaults, flagged
  trivially changeable (code constants, no migration).
- **Need back**: nothing unless you want different numbers.

---

## 10. Documentation deliverables (C6)

- **api-docs.yaml**: `components/schemas` for the envelope + each v1 `data`
  shape; the signature-verification contract (header format, tolerance, dual-sig
  rotation); `webhook-drain.php` as an operational endpoint; receiver
  expectations (2xx fast, then process async; dedupe by event id).
- **wiki/Webhooks.md**: partner-facing receiver guide (verify signature sample
  code, replay window, retry schedule, at-least-once, verification echo) +
  operator guide (enable channels, cron line beside cleanup.php's, triage
  playbook: recent events → delivery log → re-drive).
- **manage/help.php**: a `#webhooks` topic (operator-register-verify-triage).
- **CHANGELOG / ProjectBrief / api-platform-strategy.md**: mark Phase-D scoped by
  this doc; ProjectBrief's "STILL TODO: Phase-D webhooks" line updated to point
  here (a hand-maintained TODO adjacent to shipped code is the rule-#26 stale
  class — the pointer, not a status claim, is what goes there).

---

## Addendum (2026-08-29): #1987 — owner reversal of the §7.2 / A.6 "reveal is not theatre" call

§7.2 above and abuse-vector A.6 in §A both argued **Reveal is allowed** for the
webhook signing secret — reasoning that because the secret is necessarily
stored recoverably (it has to be, to sign outgoing requests), *hiding* it from
an admin who already controls the row would be theatre, not real protection.
That reasoning held for a while (`webhookSubscriptionRevealSecret()` shipped,
gated `manage_webhooks`, activity-logged as `webhook.secret.reveal`) but the
owner has since **reversed the decision** (#1987, 2026-08-29): the reveal
action decrypts and hands the CURRENT signing secret back to an admin session
on demand, and that is a genuine decrypt-to-response leak surface regardless
of who is nominally allowed to trigger it — the same class of risk API keys
never had, because a key's hash-at-rest design makes the plaintext
architecturally unrecoverable after mint. Webhook secrets cannot be hashed
(the server must recover the plaintext to compute the outgoing HMAC), so the
fix is not "hash it like a key" — it is **retire the reveal path outright**
and lean on the rotation story that already existed for exactly this recovery
case: `/manage/webhooks`'s "Rotate secret" mints a fresh secret (shown once,
exactly like create) and keeps the previous one dual-signing for the existing
`WEBHOOK_ROTATION_GRACE_HOURS` (24h) window, so an admin who lost the secret
loses nothing but a few seconds of asking the partner to note the new value.

**What changed, concretely:**
- `webhookSubscriptionRevealSecret()` (`includes/webhook_admin.php`) — **deleted**.
  `webhookSecretReveal()` (`includes/webhooks.php`, the decrypt PRIMITIVE this
  function called) is untouched — it is still the correct decrypt seam for the
  SIGNING path (`webhookSendVerification()` here, `_webhookAttemptDelivery()`
  in webhooks.php) and was never the thing #1987 objected to.
- `manage/webhooks.php` — the `reveal_secret` POST action and its "Reveal
  secret" button are **deleted**; the one-shot `$revealSecret` display panel
  stays exactly as it was, because create and rotate still need somewhere to
  show the new plaintext ONCE.
- `api.php` — **untouched**. §8.3/A19's API twin never ported `reveal_secret`
  in the first place (it was always page-only), so there was no API-side
  reveal action to retire.
- New standing guard: `tests/php/test-webhook-secret-show-once.php` — proves,
  tree-wide, that every `webhookSecretReveal(` call site's enclosing function
  is signing/verification-only (never a function whose body reaches
  `sendJson(`/`json_encode(`/`$revealSecret`/`htmlspecialchars(`), so a future
  decrypt-to-response path anywhere in the tree fails loud rather than
  reintroducing this leak by a different name.

§7.2's "Reveal is allowed... hiding it would be theatre" sentence and A.6's
"reveal entitlement-gated + logged" mitigation are **historical record of a
decision that was later reversed** — left as originally written above (this
addendum is appended, not a rewrite of history), but no longer the current
design. Do not resurrect a reveal-existing-secret action on the strength of
§7.2's argument without a fresh owner sign-off; #1987 already had that
conversation and the fresh secret was recoverable-by-rotation the whole time.
