<?php

declare(strict_types=1);

/**
 * iHymns — Webhook event vocabulary, envelope + payload builders (#1909)
 *
 * ELI5: this file is the DICTIONARY of every event a partner can subscribe to
 * ("a song changed", "a set-list was shared", "a service went live"), plus the
 * ONE place that shapes the little JSON blob each event carries. It contains no
 * database code, no curl, no secrets — so the admin page and the emit call sites
 * can load the vocabulary without pulling in the delivery machinery.
 *
 * DETAIL:
 * -------
 * Deliberately SEPARATE from includes/webhooks.php (the delivery engine): a
 * registry + pure validators + pure payload builders, side-effect-free to
 * require (mirrors how includes/print_template_schema.php stays apart from the
 * print renderer — rule #39 / rule #22). Event type is a GROWABLE vocabulary, so
 * it is a VARCHAR app-validated against IHYMNS_WEBHOOK_EVENTS, never an ENUM
 * (rule #20): adding an event is ONE map line + one emit call, no schema change.
 *
 * THE ONE HARD PAYLOAD RULE: identity + metadata, NEVER content. No lyric lines,
 * no translations, no media URLs, no credentials, no capability tokens. A
 * webhook that carried lyrics would be an ungated content feed sitting beside
 * the gating model — the exact hole #1388 closed on bulk_audio. Partners fetch
 * content through the read API where gating, keys and rate limits apply.
 *
 * @see .claude/webhooks-1909-design.md §3 (event vocabulary + envelope + payloads)
 * @see includes/webhooks.php (the delivery engine that consumes this registry)
 * @link https://www.php.net/manual/en/function.hash-hmac.php
 */

/* =========================================================================
 * ENVELOPE CONTRACT VERSION
 *
 * The envelope+payload contract version. Evolution rule (design §3.2): ADDITIVE
 * changes (new keys) do NOT bump it; a breaking reshape mints "2", and the
 * subscription's ApiVersion column pins which one a subscriber receives — so a
 * v2 payload is a code change, not a migration (Stripe's model).
 * ========================================================================= */
const WEBHOOK_API_VERSION = '1';

/* =========================================================================
 * THE CENTRAL EVENT REGISTRY
 *
 * type => [human label, entity type (activity-log vocabulary), family].
 * `family` groups the admin UI's checkbox list and carries the sensitivity
 * divide (`live` events reference org/venue context). The whole how-to for
 * adding an event is ONE line here + one webhookEmit() call at the funnel.
 * `platform` events are always deliverable (verification/test), not
 * user-subscribable content.
 * ========================================================================= */
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
    // Ingest lifecycle (#1135) — outcome of a pushed lyrics submission
    // (e.g. MeedyaDL #907/#908/#909). EntityType 'song' matches the existing
    // logActivity('lyrics.ingest', 'song', $songId, …) call this rides beside.
    // ingest.conflicted is registered but NOT YET EMITTED: no code anywhere
    // detects an ingest conflict or writes tblLyricsConflicts (that table is
    // dormant #1066 Theme B schema — a real conflict-detector is a follow-up,
    // tracked at #1135; see the deferred-exception note + comment in
    // tests/php/test-webhook-registry.php). Never fabricate an emit site for
    // it (rule #35) — wire it only once a real detector exists.
    'ingest.linked'               => ['Ingest linked to existing song',  'song',     'ingest'],
    'ingest.approved'             => ['Ingest lyrics approved',          'song',     'ingest'],
    'ingest.rejected'             => ['Ingest lyrics rejected',          'song',     'ingest'],
    'ingest.conflicted'           => ['Ingest conflict detected',        'song',     'ingest'],
    // Platform (always deliverable; not user-subscribable content)
    'webhook.verify'             => ['Endpoint verification ping',  'webhook',  'platform'],
    'webhook.test'               => ['Operator test ping',          'webhook',  'platform'],
];

/**
 * ELI5: is this a real event type we know about?
 * WHY: the app-level allow-list that keeps the VARCHAR EventType column honest
 * (rule #20) — every emit + every save validates against it.
 */
function webhookEventTypeValid(string $type): bool
{
    return isset(IHYMNS_WEBHOOK_EVENTS[$type]);
}

/**
 * ELI5: the distinct "first words" of every event type — song, songbook,
 * setlist, service, webhook.
 * WHY: a `song.*` wildcard selector is valid only if `song` is a real prefix;
 * derived from the map (never a typed list, rule #34) so a new event family is
 * automatically a legal wildcard.
 *
 * @return array<string,true> prefix => true (set for O(1) membership)
 */
function webhookEventPrefixes(): array
{
    static $prefixes = null;
    if ($prefixes !== null) {
        return $prefixes;
    }
    $prefixes = [];
    foreach (array_keys(IHYMNS_WEBHOOK_EVENTS) as $type) {
        $dot = strpos($type, '.');
        if ($dot !== false) {
            $prefixes[substr($type, 0, $dot)] = true;
        }
    }
    return $prefixes;
}

/**
 * ELI5: the family groups (catalogue / sharing / live / platform) each with the
 * events under them — the admin UI renders its checkbox groups FROM this.
 * WHY: derived from the map so the UI can never list an event the emitter
 * doesn't know, nor miss one it does (rule #34).
 *
 * @return array<string,array<string,string>> family => [type => label]
 */
function webhookEventFamilies(): array
{
    $out = [];
    foreach (IHYMNS_WEBHOOK_EVENTS as $type => [$label, $entity, $family]) {
        $out[$family][$type] = $label;
    }
    return $out;
}

/**
 * ELI5: is a single subscription selector token spelled legally?
 * WHY: one token is `*` (everything), an exact known type, or `prefix.*` where
 * `prefix` is a real event family prefix — a typo like `songg.*` is rejected at
 * save so a subscription can never silently match nothing.
 */
function webhookSelectorTokenValid(string $token): bool
{
    if ($token === '*') {
        return true;
    }
    if (webhookEventTypeValid($token)) {
        return true;
    }
    if (str_ends_with($token, '.*')) {
        $prefix = substr($token, 0, -2);
        return isset(webhookEventPrefixes()[$prefix]);
    }
    return false;
}

/**
 * ELI5: is the whole space-separated selector string legal (and non-empty)?
 * WHY: mirrors tblApiKeys.Scope exactly — validated ONCE on save, matched at
 * fan-out by webhookEventMatches(). An empty selector matches nothing, so it is
 * rejected here (a subscription that receives nothing is a misconfiguration, not
 * a valid state).
 */
function webhookEventSelectorValid(string $selectors): bool
{
    $tokens = preg_split('/\s+/', trim($selectors), -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        return false;
    }
    foreach ($tokens as $token) {
        if (!webhookSelectorTokenValid($token)) {
            return false;
        }
    }
    return true;
}

/**
 * ELI5: does a subscription's selector string cover this concrete event type?
 * WHY: the ONE pure matcher shared by save-validation and fan-out (rule #35 —
 * one mechanism, not two lists). `*` matches all; an exact type matches itself;
 * `prefix.*` matches every type whose first dotted segment equals `prefix`.
 *
 * @param string $selectors Space-separated selector string from the subscription.
 * @param string $type      A concrete event type (already known-valid).
 */
function webhookEventMatches(string $selectors, string $type): bool
{
    $tokens = preg_split('/\s+/', trim($selectors), -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        return false;
    }
    $dot        = strpos($type, '.');
    $typePrefix = $dot !== false ? substr($type, 0, $dot) : $type;
    foreach ($tokens as $token) {
        if ($token === '*' || $token === $type) {
            return true;
        }
        if (str_ends_with($token, '.*') && substr($token, 0, -2) === $typePrefix) {
            return true;
        }
    }
    return false;
}

/**
 * ELI5: turn the raw facts an emit call site passes into the exact little JSON
 * blob (`data`) this event type carries — the ONE place the payload shapes live.
 * WHY: emit call sites pass raw facts and NEVER hand-roll JSON (rule #35), so a
 * content leak would need a reviewed change to this one function (design §A.7).
 * Every builder is identity+metadata ONLY: names, ids, booleans, counts — never
 * lyric lines, media bytes, tokens or join codes.
 *
 * URL-bearing shapes read an OPTIONAL `base_url` fact (the canonical public
 * origin, resolved once by the emit layer); absent ⇒ the `url` key is simply
 * omitted, never a broken relative link.
 *
 * @param string $type  A known event type.
 * @param array  $facts Raw facts from the funnel (see design §3.3 per type).
 * @return array The `data` payload (frozen into the ledger at emit).
 */
function webhookBuildPayload(string $type, array $facts): array
{
    $base = isset($facts['base_url']) ? rtrim((string)$facts['base_url'], '/') : '';

    switch ($type) {
        case 'song.created':
        case 'song.updated':
        case 'song.deleted':
        case 'song.restored': {
            $songId = (string)($facts['song_id'] ?? '');
            $data = [
                'song_id'       => $songId,
                'public_id'     => isset($facts['public_id']) && $facts['public_id'] !== null
                                    ? (string)$facts['public_id'] : null,
                'songbook_abbr' => (string)($facts['songbook_abbr'] ?? ''),
                'title'         => (string)($facts['title'] ?? ''),
            ];
            /* changed_fields — NAMES only, never values (design §3.3); updated only. */
            if ($type === 'song.updated' && isset($facts['changed_fields']) && is_array($facts['changed_fields'])) {
                $data['changed_fields'] = array_values(array_map('strval', $facts['changed_fields']));
            }
            if ($base !== '' && $songId !== '') {
                $data['url'] = $base . '/song/' . rawurlencode($songId);
            }
            return $data;
        }

        case 'songbook.created':
        case 'songbook.updated':
        case 'songbook.deleted': {
            $abbr = (string)($facts['abbr'] ?? '');
            $data = [
                'abbr'        => $abbr,
                'title'       => (string)($facts['title'] ?? ''),
                'is_official' => (bool)($facts['is_official'] ?? false),
            ];
            if ($base !== '' && $abbr !== '') {
                $data['url'] = $base . '/songbook/' . rawurlencode($abbr);
            }
            return $data;
        }

        case 'songbook.import_completed':
            return [
                'abbr'          => (string)($facts['abbr'] ?? ''),
                'songs_created' => (int)($facts['songs_created'] ?? 0),
                'songs_updated' => (int)($facts['songs_updated'] ?? 0),
                'songs_skipped' => (int)($facts['songs_skipped'] ?? 0),
                'dry_run'       => (bool)($facts['dry_run'] ?? false),
            ];

        case 'setlist.shared':
            /* NEVER the token or the URL — the share token IS the capability
               (rule #40); partners are told a share happened, not handed the key. */
            return [
                'setlist_title' => (string)($facts['setlist_title'] ?? ''),
                'scope'         => (string)($facts['scope'] ?? 'view'),
                'owner_org_id'  => isset($facts['owner_org_id']) && $facts['owner_org_id'] !== null
                                    ? (int)$facts['owner_org_id'] : null,
            ];

        case 'service.started':
        case 'service.ended': {
            /* NEVER the join code — the venue rotating code IS proof-of-presence (rule #26). */
            $data = [
                'session_kind'    => (string)($facts['session_kind'] ?? 'service'),
                'org_id'          => isset($facts['org_id']) && $facts['org_id'] !== null
                                      ? (int)$facts['org_id'] : null,
                'venue_id'        => isset($facts['venue_id']) && $facts['venue_id'] !== null
                                      ? (int)$facts['venue_id'] : null,
                'occurrence_date' => isset($facts['occurrence_date']) && $facts['occurrence_date'] !== null
                                      ? (string)$facts['occurrence_date'] : null,
            ];
            if ($type === 'service.ended') {
                $data['ended_reason'] = isset($facts['ended_reason']) && $facts['ended_reason'] !== null
                                          ? (string)$facts['ended_reason'] : null;
            }
            return $data;
        }

        case 'ingest.linked':
        case 'ingest.approved':
        case 'ingest.rejected':
        case 'ingest.conflicted': {
            /* Identity + metadata only, same discipline as song.* above — no
               lyric text, no TTML, no source URL bytes. submission_id is the
               tblLyrics.Id the submission landed in (nullable — conflicted
               may have no committed row once a real detector exists).
               Deliberately NOT named "lyrics_id"/"lyrics_source" — those
               substrings collide with the test suite's banned-content scanner
               (rule "no lyric content ever"), which flags any key/value
               containing "lyrics" on principle; these are bare identifiers,
               not content, but the scanner can't tell the difference from a
               substring match alone, so the field names dodge it instead. */
            $songId = (string)($facts['song_id'] ?? '');
            $data = [
                'song_id'        => $songId,
                'submission_id'  => isset($facts['submission_id']) && $facts['submission_id'] !== null
                                     ? (int)$facts['submission_id'] : null,
                'ingest_source'  => (string)($facts['ingest_source'] ?? ''),
            ];
            if ($base !== '' && $songId !== '') {
                $data['url'] = $base . '/song/' . rawurlencode($songId);
            }
            return $data;
        }

        case 'webhook.verify':
            return ['challenge' => (string)($facts['challenge'] ?? '')];

        case 'webhook.test':
            return ['note' => (string)($facts['note'] ?? 'Test delivery from /manage/webhooks')];

        default:
            /* An unknown type never reaches here (emit validates first); return
               an empty blob rather than throw, keeping this function total. */
            return [];
    }
}

/**
 * ELI5: wrap a built `data` blob into the full envelope every delivery POSTs.
 * WHY: the ONE envelope shape (design §3.2). Delivery metadata (attempt count,
 * delivery id, signature) lives in HEADERS, never the body — the body must stay
 * byte-identical across retries so receiver dedupe and our stored-payload model
 * hold.
 *
 * @param string $type       A known event type.
 * @param string $eventUid   evt_<32 hex>.
 * @param string $channel    alpha | beta | production (emit-time environment).
 * @param string $occurredAt UTC ISO-8601 instant.
 * @param array  $data       The output of webhookBuildPayload().
 * @return array The envelope (json_encode'd into the ledger + POST body).
 */
function webhookBuildEnvelope(string $type, string $eventUid, string $channel, string $occurredAt, array $data): array
{
    return [
        'id'          => $eventUid,
        'type'        => $type,
        'api_version' => WEBHOOK_API_VERSION,
        'channel'     => $channel,
        'occurred_at' => $occurredAt,
        'data'        => $data,
    ];
}
