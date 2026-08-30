<?php

declare(strict_types=1);

/**
 * iHymns — Android/FireOS push bridge: token registry + a DORMANT sender
 * skeleton (API-coverage plan 2026-08-28 §4.1 C1 / §3 X2), no credentials
 * provisioned yet
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: `includes/apns.php` is the machinery for knocking on an iPhone's
 * door. This file is the SAME idea for Android and Amazon Fire tablets —
 * except Android/FireOS don't share one door. A normal Android phone (with
 * Google Play Services) is knocked on via Google's FCM service; an Amazon
 * Fire tablet has NO Google Play Services at all and is knocked on via
 * Amazon's own ADM service instead. This file remembers WHICH devices asked
 * to be knocked on (`fcm_register`/`fcm_unregister` in api.php, backed by
 * `tblPushTokens`) and gives a single place (`fcmSend()`) a FUTURE feature
 * will call to actually do the knocking. Nothing calls `fcmSend()` yet, and
 * even when something does, it refuses to send anything for THIS provider
 * until the owner pastes real credentials for it.
 *
 * DETAILED:
 * `tblPushTokens` ships live-dormant in the SAME change as this module (the
 * `push-tokens` migration card) — this file is its first and only consumer.
 * Every read/write is `pushTokensTableExists()`-gated so an un-migrated
 * docroot degrades to a clean "not available yet" 503 instead of a STRICT-mode
 * `mysqli_sql_exception` on a missing table (CLAUDE.md rule #19/#28), mirroring
 * `apnsTokensTableExists()` in includes/apns.php exactly.
 *
 * WHY ONE TABLE, ONE `Provider` COLUMN, NOT TWO TABLES (owner Q2 decision,
 * 2026-08-28): FCM (Google Firebase Cloud Messaging) and ADM (Amazon Device
 * Messaging) are two DIFFERENT wire protocols with two DIFFERENT credential
 * shapes (FCM: an HTTP v1 OAuth2 service-account / legacy server key; ADM: a
 * Login-With-Amazon `client_id`/`client_secret` pair exchanged for a bearer
 * token per send) — but from the REGISTRATION side of the API they are the
 * same shape: "remember an opaque token string for this authenticated user".
 * `Provider VARCHAR(16)` is the discriminator (rule #20 — a growable
 * app-validated vocabulary, never an ENUM: a THIRD provider some day is one
 * more string in `PUSH_TOKEN_PROVIDERS`, not a migration). `fcm_register`/
 * `fcm_unregister` in api.php deliberately keep the FCM-era action names (the
 * dominant/first-shipped provider) even though the endpoint pair also serves
 * ADM tokens — same "the family keeps its original name" precedent as
 * `apns_register` continuing to serve BOTH `kind=device` and
 * `kind=liveActivity` tokens.
 *
 * WHY THIS FILE DOES NOT ACTUALLY SEND ANYTHING (deliberate scope boundary,
 * NOT an oversight): the #1-titled task that produced this file is explicit —
 * "ACTUAL push sending is later work needing owner-provisioned credentials —
 * this ships the registration endpoints + an inert sender skeleton, nothing
 * that sends." Unlike `includes/apns.php` (which DOES implement Apple's real
 * HTTP/2 request end-to-end and is dormant only because the credential is
 * empty), `fcmSend()` below performs NO curl call, NO OAuth exchange, and NO
 * network I/O of any kind, in EITHER the unkeyed OR the keyed case — it always
 * returns a structured "not implemented yet" result. This is intentional: the
 * real FCM HTTP v1 request shape (a service-account JWT → OAuth2 access token
 * → `POST https://fcm.googleapis.com/v1/projects/<id>/messages:send`) and the
 * real ADM shape (LWA `client_id`+`client_secret` → bearer token →
 * `POST https://api.amazon.com/messaging/registrations/<id>/messages`) are
 * BOTH nontrivial protocols this change was never asked to implement — see
 * the task brief's own words above. `fcmConfig()`/`admConfig()` are still
 * fully real (so a future PR's `fcmSend()` rewrite has a working "is this
 * provider keyed?" gate to build on, and so `/manage/configuration` can grow
 * a status indicator without waiting for the sender), but the send path
 * itself is a stub on purpose.
 *
 * CREDENTIAL CUSTODY (mirrors apns.php's "`.p8` CUSTODY" section, and
 * cuercode_client.php's `cuercodeConfig()`): every credential lives in
 * `tblAppSettings`, encrypted at rest by `includes/secret_crypto.php`, read
 * transparently via `getAppSetting()`. `fcm_server_key`, `adm_client_id` and
 * `adm_client_secret` are registered in `secretSettingKeys()` in THIS change
 * (a zero-risk, purely-additive registration — see that function's docblock:
 * an unset/never-saved key is a no-op for every consumer of the list) so that
 * whenever a future admin-UI card gets built to paste real values, they are
 * encrypted at rest from the very first save. No admin-UI card ships in this
 * change (mirrors apns.php's own "NOT IN SCOPE" note) — until one exists,
 * `getAppSetting('fcm_server_key', '')` always reads empty, so `fcmConfigured()`
 * is always false.
 *
 * SECURITY:
 *   - The registration token itself (`Token`) is opaque, per-device,
 *     meaningless without the paired provider credential, and is NEVER
 *     logged — only `Provider`/`Platform`/user id, mirroring apns.php's
 *     "never log the push token" discipline (its file header, "SECURITY").
 *   - `tblPushTokens.UserId` is `NOT NULL` — unlike `tblApnsTokens` (which
 *     also serves anonymous presence-scoped Live-Activity tokens via a
 *     `SessionId` FK), an Android/FireOS push token in THIS design is always
 *     owned by an authenticated account; there is no anonymous flow here.
 *
 * @link https://firebase.google.com/docs/cloud-messaging/http-server-ref     FCM HTTP v1 API reference
 * @link https://firebase.google.com/docs/cloud-messaging/migrate-v1          Why HTTP v1 (OAuth2), not the deprecated legacy server-key API
 * @link https://developer.amazon.com/docs/adm/06-sending-a-message.html      ADM: sending a message
 * @link https://developer.amazon.com/docs/login-with-amazon/obtain-token-web-docs.html  LWA client_id/client_secret -> bearer token exchange
 * @see includes/apns.php            the Apple sibling this module's dormancy contract mirrors
 * @see includes/cuercode_client.php the cuercodeConfig()/cuercodeGenerate() dormant-until-keyed shape this module's fcmConfig()/fcmSend() mirror
 *
 * NOT IN SCOPE FOR THIS CHANGE (deliberately):
 *   - An admin-UI card on manage/configuration.php to paste the FCM service
 *     account / server key or the ADM client id+secret.
 *   - Any real HTTP call to Google or Amazon (see "WHY THIS FILE DOES NOT
 *     ACTUALLY SEND ANYTHING" above) — fcmSend() is a structural stub.
 *   - Any live trigger calling fcmSend() — nothing in this codebase does.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';   /* getAppSetting() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'secret_crypto.php'; /* transparent decrypt of fcm_server_key/adm_client_id/adm_client_secret inside getAppSetting() */

/** Closed vocabulary for `tblPushTokens.Provider` — VARCHAR, app-validated,
 *  never an ENUM (rule #20); matches the shipped column COMMENT exactly.
 *  'fcm' = Google Firebase Cloud Messaging (ordinary Android w/ Play Services).
 *  'adm' = Amazon Device Messaging (Fire OS tablets — no Google Play Services). */
const PUSH_TOKEN_PROVIDERS = ['fcm', 'adm'];

/**
 * Memoised INFORMATION_SCHEMA existence probe for `tblPushTokens`. Mirrors
 * `apnsTokensTableExists()` (includes/apns.php) — one memoised probe per
 * module, per the established per-module dormancy-gate convention (CLAUDE.md
 * rule #19/#28).
 */
function pushTokensTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPushTokens' LIMIT 1"
        );
        $st->execute();
        $exists = $st->get_result()->fetch_row() !== null;
        $st->close();
    } catch (\Throwable $_e) {
        $exists = false; /* un-migrated install → treat as absent → caller 503s */
    }
    return $exists;
}

/**
 * ELI5: do we have what we need to send an FCM push at all?
 * WHY: single resolution point — every caller sees the SAME complete-or-null
 * answer, mirroring `cuercodeConfig()`. Memoised per request.
 *
 * @return array{server_key:string}|null
 */
function fcmConfig(): ?array
{
    static $cached = false; /* false = unresolved; null = resolved-and-incomplete */
    if ($cached !== false) {
        return $cached;
    }
    $serverKey = trim((string)(getAppSetting('fcm_server_key', '') ?? ''));
    if ($serverKey === '') {
        return $cached = null;
    }
    return $cached = ['server_key' => $serverKey];
}

/** ELI5: is FCM set up (a key is saved)? WHY: cheap gate for a future sender/status panel. */
function fcmConfigured(): bool
{
    return fcmConfig() !== null;
}

/**
 * ELI5: do we have what we need to send an ADM push at all? ADM needs a PAIR
 * of credentials (Login-With-Amazon client id + secret), not one key, so
 * BOTH must be present.
 * WHY: single resolution point, mirroring fcmConfig(). Memoised per request.
 *
 * @return array{client_id:string,client_secret:string}|null
 */
function admConfig(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $clientId     = trim((string)(getAppSetting('adm_client_id', '') ?? ''));
    $clientSecret = trim((string)(getAppSetting('adm_client_secret', '') ?? ''));
    if ($clientId === '' || $clientSecret === '') {
        return $cached = null;
    }
    return $cached = ['client_id' => $clientId, 'client_secret' => $clientSecret];
}

/** ELI5: is ADM set up (both LWA credentials are saved)? */
function admConfigured(): bool
{
    return admConfig() !== null;
}

/**
 * Is EITHER provider this token belongs to actually configured?
 * WHY: a shared one-line gate for callers (e.g. a future prune job) that
 * don't want to duplicate the per-provider if/else.
 */
function pushProviderConfigured(string $provider): bool
{
    if ($provider === 'fcm') {
        return fcmConfigured();
    }
    if ($provider === 'adm') {
        return admConfigured();
    }
    return false;
}

/**
 * Send ONE push notification via FCM or ADM — DORMANT BY CONSTRUCTION, and
 * NOT YET IMPLEMENTED even once configured. See this file's header section
 * "WHY THIS FILE DOES NOT ACTUALLY SEND ANYTHING" for the full reasoning:
 * actual sending (the OAuth2/LWA token exchange + the real HTTP request to
 * Google/Amazon) is explicitly later work, gated on owner-provisioned
 * credentials that do not exist yet. Nothing in this codebase calls this
 * function yet; it exists as plumbing — a stable call shape a future PR
 * fills in — mirroring `apnsSend()`'s doc-block framing in includes/apns.php.
 *
 * NEVER THROWS. Every failure mode — invalid provider, not configured, or
 * (today, unconditionally) not yet implemented — is a structured return, the
 * same discipline `apnsSend()` and `cuercodeGenerate()` both keep.
 *
 * @param string $provider  'fcm' | 'adm'.
 * @param string $token     The opaque registration token this function would
 *                           push to (the SAME string `fcm_register` stored).
 * @param array  $payload   The notification payload — shape is provider- and
 *                           future-implementation-specific; unused today.
 * @return array{ok:bool,status:string,provider?:string}
 */
function fcmSend(string $provider, string $token, array $payload = []): array
{
    if (!in_array($provider, PUSH_TOKEN_PROVIDERS, true)) {
        return ['ok' => false, 'status' => 'invalid_provider'];
    }
    if (trim($token) === '') {
        return ['ok' => false, 'status' => 'invalid_token', 'provider' => $provider];
    }
    if (!pushProviderConfigured($provider)) {
        return ['ok' => false, 'status' => 'not_configured', 'provider' => $provider];
    }

    /* Reached only once an owner has pasted real credentials for this
       provider — and EVEN THEN this is a deliberate stub (see file header).
       $payload is intentionally unused until the real implementation lands;
       named here only so the eventual call shape is already stable. */
    unset($payload);
    return ['ok' => false, 'status' => 'not_implemented', 'provider' => $provider];
}
