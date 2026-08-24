<?php

declare(strict_types=1);

/**
 * iHymns — Outbound-webhooks admin CRUD core (#1909)
 *
 * ELI5: the shared "business logic" behind the /manage/webhooks page — create a
 * subscription (validate the URL is safe + public, mint a signing secret), verify
 * the endpoint really is the partner's (a signed challenge it must echo back),
 * rotate/reveal the secret, pause/resume, send a test, and re-drive a failed
 * delivery. The page is just forms; ALL of this lives here so a future partner
 * API can reuse the exact same core (rule #22 / design §8.3).
 *
 * DETAIL:
 * -------
 * Side-effect-free to require. Every function returns a result array (never
 * throws for a validation failure); the page maps that to a flash + an
 * activity-log row. Security-critical bits: the SSRF gate is reused from
 * includes/webhooks.php (validated at SAVE and again at every DIAL), the signing
 * secret is server-minted + enc:v1-enveloped at rest, and verification requires
 * the endpoint to ECHO a fresh challenge — proving the registrant controls the
 * endpoint's RESPONSE, not merely that some URL returns 2xx (design §7.3).
 *
 * @see .claude/webhooks-1909-design.md §7 (security), §8.1 (admin surface)
 * @see includes/webhooks.php (the engine: dialer, sign, emit, drain)
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'webhooks.php';         /* engine: dialer, sign, gates, mint, secret custody */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'webhook_events.php';   /* registry + payload builders */

/* Valid subscription lifecycle states — the app-level allow-list for the VARCHAR
   Status column (rule #20). */
const WEBHOOK_SUB_STATUSES = ['pending_verification', 'active', 'paused', 'disabled_failing', 'revoked'];

/**
 * ELI5: is this partner URL safe + sane to register as a delivery target?
 * WHY: the SAVE-time half of the SSRF gate (design §6.5.2) — https only, default
 * port, a host that resolves to ONLY public addresses, and not one of our own
 * sites. Re-checked at every dial (DNS can change), but rejecting obvious junk
 * at save gives the admin an immediate, clear error.
 *
 * @return array{ok:bool, url?:string, host?:string, error?:string}
 */
function webhookValidateTargetUrl(string $url): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) {
        return ['ok' => false, 'error' => 'Target URL is required and must be at most 500 characters.'];
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return ['ok' => false, 'error' => 'Target URL must be an absolute URL with a host.'];
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host   = strtolower((string)$parts['host']);
    $allowLoopback = webhookAllowLoopback();
    $isLoopbackHost = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

    if ($scheme === 'https') {
        if ($port !== 443) {
            return ['ok' => false, 'error' => 'Only the default HTTPS port (443) is allowed.'];
        }
    } elseif ($scheme === 'http' && $allowLoopback && $isLoopbackHost) {
        /* local/test carve-out */
    } else {
        return ['ok' => false, 'error' => 'Target URL must use https://.'];
    }

    if (webhookIsSelfHost($host)) {
        return ['ok' => false, 'error' => 'Target URL may not point at this application.'];
    }

    /* Resolve + validate every address (skipped for the loopback test carve-out). */
    if (!($allowLoopback && $isLoopbackHost)) {
        $ips = _webhookResolveTargetIps($host);
        if (!$ips) {
            return ['ok' => false, 'error' => 'Target host does not resolve to any address.'];
        }
        foreach ($ips as $ip) {
            if (!webhookIpIsPublic($ip)) {
                return ['ok' => false, 'error' => 'Target host resolves to a private or reserved address.'];
            }
        }
    }
    return ['ok' => true, 'url' => $url, 'host' => $host];
}

/** ELI5: how many ACTIVE subscriptions already point at this host on this channel?
 *  WHY: the per-host cap that blunts using us as a DDoS multiplier (design §7.4). */
function webhookPerHostActiveCount(\mysqli $db, string $host, string $chan, int $excludeId = 0): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM tblWebhookSubscriptions
          WHERE Channel = ? AND TargetHost = ? AND Status = 'active' AND Id <> ?"
    );
    $stmt->bind_param('ssi', $chan, $host, $excludeId);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $n;
}

/**
 * ELI5: create a new subscription (a partner endpoint + which events it wants).
 * WHY: the ONE create path. Validates the URL (SSRF) + the event selectors, mints
 * a 192-bit secret (server-side only — never client-supplied), stores it
 * enc:v1-enveloped, and starts life 'pending_verification' (receives nothing
 * until the endpoint passes the challenge echo). Returns the plaintext secret
 * ONCE for display.
 *
 * @param array $in { label, target_url, events (space-separated), system_id? }
 * @return array{ok:bool, id?:int, secret?:string, error?:string}
 */
function webhookSubscriptionCreate(\mysqli $db, array $in, ?int $userId): array
{
    if (!webhookSchemaReady($db)) {
        return ['ok' => false, 'error' => 'Webhook tables are not migrated on this environment.'];
    }
    $label = trim((string)($in['label'] ?? ''));
    if ($label === '' || mb_strlen($label) > 120) {
        return ['ok' => false, 'error' => 'A label (1–120 characters) is required.'];
    }
    $urlCheck = webhookValidateTargetUrl((string)($in['target_url'] ?? ''));
    if (!$urlCheck['ok']) {
        return ['ok' => false, 'error' => $urlCheck['error']];
    }
    $events = trim((string)($in['events'] ?? ''));
    if (!webhookEventSelectorValid($events)) {
        return ['ok' => false, 'error' => 'Select at least one valid event (or a family wildcard / *).'];
    }

    $chan = ihymns_environment();
    if (webhookPerHostActiveCount($db, $urlCheck['host'], $chan) >= WEBHOOK_PER_HOST_SUB_CAP) {
        return ['ok' => false, 'error' => 'This host already has the maximum number of active subscriptions.'];
    }

    $secretPlain = webhookMintSecret();
    $secretStore = webhookSecretForStorage($secretPlain);
    $systemId    = isset($in['system_id']) && (int)$in['system_id'] > 0 ? (int)$in['system_id'] : null;

    $stmt = $db->prepare(
        "INSERT INTO tblWebhookSubscriptions
            (Channel, SystemId, Label, TargetUrl, TargetHost, Secret, Events, Status, CreatedBy)
         VALUES (?,?,?,?,?,?,?, 'pending_verification', ?)"
    );
    $stmt->bind_param(
        'sisssssi',
        $chan, $systemId, $label, $urlCheck['url'], $urlCheck['host'], $secretStore, $events, $userId
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    return ['ok' => true, 'id' => $id, 'secret' => $secretPlain];
}

/**
 * ELI5: edit an existing subscription's label / URL / events.
 * WHY: a URL change re-opens verification (the new endpoint must prove itself),
 * mirroring how a new subscription starts pending (design §7.3).
 *
 * @return array{ok:bool, url_changed?:bool, error?:string}
 */
function webhookSubscriptionUpdate(\mysqli $db, int $id, array $in, ?int $userId): array
{
    $row = webhookSubscriptionGet($db, $id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Subscription not found.'];
    }
    $label = trim((string)($in['label'] ?? ''));
    if ($label === '' || mb_strlen($label) > 120) {
        return ['ok' => false, 'error' => 'A label (1–120 characters) is required.'];
    }
    $urlCheck = webhookValidateTargetUrl((string)($in['target_url'] ?? ''));
    if (!$urlCheck['ok']) {
        return ['ok' => false, 'error' => $urlCheck['error']];
    }
    $events = trim((string)($in['events'] ?? ''));
    if (!webhookEventSelectorValid($events)) {
        return ['ok' => false, 'error' => 'Select at least one valid event.'];
    }
    $urlChanged = ($urlCheck['url'] !== (string)$row['TargetUrl']);

    if ($urlChanged) {
        $stmt = $db->prepare(
            "UPDATE tblWebhookSubscriptions
                SET Label = ?, TargetUrl = ?, TargetHost = ?, Events = ?,
                    Status = 'pending_verification', VerifiedAt = NULL
              WHERE Id = ?"
        );
        $stmt->bind_param('ssssi', $label, $urlCheck['url'], $urlCheck['host'], $events, $id);
    } else {
        $stmt = $db->prepare(
            "UPDATE tblWebhookSubscriptions SET Label = ?, Events = ? WHERE Id = ?"
        );
        $stmt->bind_param('ssi', $label, $events, $id);
    }
    $stmt->execute();
    $stmt->close();
    return ['ok' => true, 'url_changed' => $urlChanged];
}

/** ELI5: fetch one subscription row (or null). */
function webhookSubscriptionGet(\mysqli $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT * FROM tblWebhookSubscriptions WHERE Id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * ELI5: pause / resume / disable a subscription.
 * WHY: a paused/disabled subscription receives nothing; resuming requires a fresh
 * verification (its endpoint may have changed while off).
 *
 * @param string $status one of WEBHOOK_SUB_STATUSES.
 * @return array{ok:bool, error?:string}
 */
function webhookSubscriptionSetStatus(\mysqli $db, int $id, string $status): array
{
    if (!in_array($status, WEBHOOK_SUB_STATUSES, true)) {
        return ['ok' => false, 'error' => 'Invalid status.'];
    }
    /* Resuming a paused/disabled subscription re-opens verification. */
    if ($status === 'active') {
        $status = 'pending_verification';
    }
    $stmt = $db->prepare("UPDATE tblWebhookSubscriptions SET Status = ? WHERE Id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();

    /* Pausing/disabling cancels its queued deliveries. */
    if (in_array($status, ['paused', 'disabled_failing', 'revoked'], true)) {
        $c = $db->prepare(
            "UPDATE tblWebhookDeliveries SET Status = 'cancelled', ClaimToken = NULL
              WHERE SubscriptionId = ? AND Status IN ('pending','failed')"
        );
        $c->bind_param('i', $id);
        $c->execute();
        $c->close();
    }
    return ['ok' => true];
}

/**
 * ELI5: mint a NEW signing secret while keeping the old one working for 24h.
 * WHY: partners can roll over without a hard cutover — deliveries carry BOTH
 * signatures during the grace window, and the receiver accepts either (design
 * §7.1). Returns the new plaintext secret ONCE.
 *
 * @return array{ok:bool, secret?:string, error?:string}
 */
function webhookSubscriptionRotateSecret(\mysqli $db, int $id): array
{
    $row = webhookSubscriptionGet($db, $id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Subscription not found.'];
    }
    $newPlain  = webhookMintSecret();
    $newStore  = webhookSecretForStorage($newPlain);
    $oldStore  = (string)$row['Secret']; /* keep it enveloped as-is for the grace window */
    $graceEnds = gmdate('Y-m-d H:i:s', time() + WEBHOOK_ROTATION_GRACE_HOURS * 3600);

    $stmt = $db->prepare(
        "UPDATE tblWebhookSubscriptions
            SET Secret = ?, SecretPrevious = ?, SecretPreviousExpiresAt = ?
          WHERE Id = ?"
    );
    $stmt->bind_param('sssi', $newStore, $oldStore, $graceEnds, $id);
    $stmt->execute();
    $stmt->close();
    return ['ok' => true, 'secret' => $newPlain];
}

/** ELI5: reveal a subscription's current secret in clear text (entitlement-gated
 *  + activity-logged by the caller). WHY: we necessarily store it recoverably to
 *  sign, so hiding it would be theatre (design §7.2). */
function webhookSubscriptionRevealSecret(\mysqli $db, int $id): ?string
{
    $row = webhookSubscriptionGet($db, $id);
    if ($row === null) {
        return null;
    }
    return webhookSecretReveal((string)$row['Secret']);
}

/** ELI5: permanently delete a subscription (its deliveries CASCADE away). */
function webhookSubscriptionDelete(\mysqli $db, int $id): array
{
    $stmt = $db->prepare("DELETE FROM tblWebhookSubscriptions WHERE Id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    return ['ok' => $ok];
}

/**
 * ELI5: prove the partner really controls this endpoint by sending it a signed
 * secret challenge it must echo back.
 * WHY: the verification handshake (design §7.3). SYNCHRONOUS in the operator's
 * request (nothing stored). Echo-required (not any-2xx) is the anti-abuse control:
 * it proves control of the endpoint's RESPONSE, so the subscription can't be
 * pointed at an arbitrary third party that merely 200s and then sprayed with
 * signed traffic. On success ⇒ Status='active' + VerifiedAt.
 *
 * @return array{ok:bool, status?:int, error?:string}
 */
function webhookSendVerification(\mysqli $db, int $id): array
{
    $row = webhookSubscriptionGet($db, $id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Subscription not found.'];
    }
    $challenge = bin2hex(random_bytes(16)); /* 32 hex */
    $eventUid  = webhookMintEventUid();
    $chan      = ihymns_environment();
    $data      = webhookBuildPayload('webhook.verify', ['challenge' => $challenge]);
    $envelope  = webhookBuildEnvelope('webhook.verify', $eventUid, $chan, gmdate('Y-m-d\TH:i:s\Z'), $data);
    $rawBody   = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($rawBody === false) {
        return ['ok' => false, 'error' => 'Could not build the verification payload.'];
    }
    $ts     = time();
    $secret = (string)(webhookSecretReveal((string)$row['Secret']) ?? '');
    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'X-iHymns-Event-Id: ' . $eventUid,
        'X-iHymns-Event-Type: webhook.verify',
        'X-iHymns-Attempt: 1',
        'X-iHymns-Signature: ' . webhookSignatureHeader($rawBody, $ts, $secret, null, false),
    ];
    $resp = webhookHttpPost((string)$row['TargetUrl'], $rawBody, $headers);
    $ok   = $resp['status'] >= 200 && $resp['status'] < 300
            && $resp['body'] !== '' && strpos((string)$resp['body'], $challenge) !== false;

    if ($ok) {
        $stmt = $db->prepare(
            "UPDATE tblWebhookSubscriptions
                SET Status = 'active', VerifiedAt = UTC_TIMESTAMP(),
                    ConsecutiveFailures = 0, FailingSince = NULL
              WHERE Id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true, 'status' => (int)$resp['status']];
    }
    $err = $resp['err'] !== '' ? $resp['err']
         : ($resp['status'] < 200 || $resp['status'] >= 300 ? 'http_' . $resp['status'] : 'challenge_not_echoed');
    return ['ok' => false, 'status' => (int)$resp['status'], 'error' => $err];
}

/**
 * ELI5: enqueue a delivery of a given event to ONE specific subscription (used by
 * "Send test" and by a future targeted replay).
 * WHY: mirrors webhookEmit()/webhookFanOut() but for a single subscription — ONE
 * frozen ledger row + ONE delivery row. Best-effort, returns a result.
 *
 * @return array{ok:bool, delivery_id?:int, error?:string}
 */
function webhookEnqueueForSubscription(\mysqli $db, int $subId, string $type, array $facts, array $opts = []): array
{
    if (!webhookSchemaReady($db) || !webhookEventTypeValid($type)) {
        return ['ok' => false, 'error' => 'Not available.'];
    }
    $row = webhookSubscriptionGet($db, $subId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Subscription not found.'];
    }
    $chan       = ihymns_environment();
    $eventUid   = webhookMintEventUid();
    $data       = webhookBuildPayload($type, $facts);
    $envelope   = webhookBuildEnvelope($type, $eventUid, $chan, gmdate('Y-m-d\TH:i:s\Z'), $data);
    $payload    = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Could not build the payload.'];
    }
    [, $entityType] = IHYMNS_WEBHOOK_EVENTS[$type];
    $occDt     = gmdate('Y-m-d H:i:s');
    $expiresAt = gmdate('Y-m-d H:i:s', time() + WEBHOOK_RETENTION_DAYS * 86400);
    $source    = (string)($opts['source'] ?? 'admin');
    $emptyId   = '';

    $ev = $db->prepare(
        "INSERT INTO tblWebhookEvents
            (EventUid, Channel, EventType, EntityType, EntityId, PayloadJson, OccurredAt, Source, ExpiresAt)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $ev->bind_param('sssssssss', $eventUid, $chan, $type, $entityType, $emptyId, $payload, $occDt, $source, $expiresAt);
    $ev->execute();
    $eventId = (int)$db->insert_id;
    $ev->close();

    $now = gmdate('Y-m-d H:i:s');
    $de = $db->prepare(
        "INSERT INTO tblWebhookDeliveries
            (EventId, SubscriptionId, Channel, Status, AttemptCount, NextAttemptAt, ExpiresAt)
         VALUES (?,?,?, 'pending', 0, ?, ?)
         ON DUPLICATE KEY UPDATE Id = Id"
    );
    $de->bind_param('iisss', $eventId, $subId, $chan, $now, $expiresAt);
    $de->execute();
    $deliveryId = (int)$db->insert_id;
    $de->close();
    return ['ok' => true, 'delivery_id' => $deliveryId];
}

/**
 * ELI5: put a failed / dead / cancelled delivery back in the queue to try again
 * now.
 * WHY: the admin re-drive (design §8.1). Resets AttemptCount to 0 (history stays
 * in AttemptLogJson) and NextAttemptAt to now; only touches a non-live row.
 *
 * @return array{ok:bool, error?:string}
 */
function webhookDeliveryRedrive(\mysqli $db, int $deliveryId): array
{
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $db->prepare(
        "UPDATE tblWebhookDeliveries
            SET Status = 'pending', AttemptCount = 0, NextAttemptAt = ?, ClaimToken = NULL
          WHERE Id = ? AND Status IN ('failed','dead','cancelled')"
    );
    $stmt->bind_param('si', $now, $deliveryId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    return ['ok' => $ok];
}

/**
 * ELI5: fetch the subscription list for the admin table (with light denorm health
 * columns).
 * @return array<int,array>
 */
function webhookSubscriptionList(\mysqli $db): array
{
    $chan = ihymns_environment();
    $stmt = $db->prepare(
        "SELECT Id, Label, TargetHost, TargetUrl, Events, Status, ApiVersion,
                ConsecutiveFailures, LastSuccessAt, LastAttemptAt, VerifiedAt, CreatedAt
           FROM tblWebhookSubscriptions
          WHERE Channel = ?
          ORDER BY CreatedAt DESC"
    );
    $stmt->bind_param('s', $chan);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * ELI5: recent delivery rows (all, or for one subscription) for the triage log.
 * @return array<int,array>
 */
function webhookDeliveryLog(\mysqli $db, int $subId = 0, int $limit = 50): array
{
    $chan  = ihymns_environment();
    $limit = max(1, min(200, $limit));
    if ($subId > 0) {
        $stmt = $db->prepare(
            "SELECT d.Id, d.SubscriptionId, d.Status, d.AttemptCount, d.LastHttpStatus,
                    d.LastError, d.NextAttemptAt, d.LastAttemptAt, d.AttemptLogJson,
                    e.EventType, e.EventUid, e.OccurredAt
               FROM tblWebhookDeliveries d
               JOIN tblWebhookEvents e ON e.Id = d.EventId
              WHERE d.Channel = ? AND d.SubscriptionId = ?
              ORDER BY d.Id DESC LIMIT ?"
        );
        $stmt->bind_param('sii', $chan, $subId, $limit);
    } else {
        $stmt = $db->prepare(
            "SELECT d.Id, d.SubscriptionId, d.Status, d.AttemptCount, d.LastHttpStatus,
                    d.LastError, d.NextAttemptAt, d.LastAttemptAt, d.AttemptLogJson,
                    e.EventType, e.EventUid, e.OccurredAt
               FROM tblWebhookDeliveries d
               JOIN tblWebhookEvents e ON e.Id = d.EventId
              WHERE d.Channel = ?
              ORDER BY d.Id DESC LIMIT ?"
        );
        $stmt->bind_param('si', $chan, $limit);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * ELI5: the most recent ledger events (the "did it even fire?" view).
 * @return array<int,array>
 */
function webhookRecentEvents(\mysqli $db, int $limit = 25): array
{
    $chan  = ihymns_environment();
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare(
        "SELECT Id, EventUid, EventType, EntityType, EntityId, OccurredAt, Source
           FROM tblWebhookEvents
          WHERE Channel = ?
          ORDER BY Id DESC LIMIT ?"
    );
    $stmt->bind_param('si', $chan, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * ELI5: a small "is the queue healthy / is cron wired?" summary for the
 * configuration card.
 * @return array{due_now:int, oldest_due_age_secs:?int, last_drain_at:?string, active_subs:int}
 */
function webhookDrainHealth(\mysqli $db): array
{
    $chan = ihymns_environment();
    $out  = ['due_now' => 0, 'oldest_due_age_secs' => null, 'last_drain_at' => null, 'active_subs' => 0];
    if (!webhookSchemaReady($db)) {
        return $out;
    }
    $q = $db->prepare(
        "SELECT COUNT(*) AS n, MIN(NextAttemptAt) AS oldest
           FROM tblWebhookDeliveries
          WHERE Channel = ? AND Status IN ('pending','failed') AND NextAttemptAt <= UTC_TIMESTAMP()"
    );
    $q->bind_param('s', $chan);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    $out['due_now'] = (int)($r['n'] ?? 0);
    if (!empty($r['oldest'])) {
        $out['oldest_due_age_secs'] = max(0, time() - (int)strtotime((string)$r['oldest'] . ' UTC'));
    }
    $s = $db->prepare("SELECT COUNT(*) FROM tblWebhookSubscriptions WHERE Channel = ? AND Status = 'active'");
    $s->bind_param('s', $chan);
    $s->execute();
    $out['active_subs'] = (int)($s->get_result()->fetch_row()[0] ?? 0);
    $s->close();

    $last = getAppSetting(WEBHOOK_SETTING_LAST_DRAIN_AT, '');
    $out['last_drain_at'] = ($last !== '' && $last !== null) ? (string)$last : null;
    return $out;
}
