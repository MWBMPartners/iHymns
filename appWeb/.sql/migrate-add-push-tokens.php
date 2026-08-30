<?php

declare(strict_types=1);

/**
 * iHymns — Android/FireOS push token registry (API-coverage plan
 * 2026-08-28 §4.1 C1 / §3 X2)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates the ONE dormant table that backs Android/FireOS push registration:
 * `tblPushTokens`. A `Provider VARCHAR(16)` discriminator (fcm | adm,
 * app-validated against `PUSH_TOKEN_PROVIDERS` in includes/fcm.php — VARCHAR,
 * never an ENUM, rule #20) lets ONE table serve both Google Firebase Cloud
 * Messaging (ordinary Android) and Amazon Device Messaging (Fire OS tablets,
 * which have no Google Play Services and so cannot use FCM at all) rather
 * than two near-identical tables.
 *
 * This is DISTINCT from the two push-adjacent tables that already exist:
 *   - tblApnsTokens        — Apple's push tokens (#1410), a separate provider
 *                            with a separate credential shape (ES256 JWT).
 *   - tblPushSubscriptions — Web Push (VAPID) subscriptions (#311), keyed by
 *                            a browser `endpoint` URL, not an opaque token.
 * tblPushTokens is neither of those — it is the FIRST Android/FireOS-native
 * push-token store, named to read naturally on its own rather than
 * overloading either existing table's shape onto a third-and-different wire
 * protocol.
 *
 * ENTIRELY DORMANT: nothing in this codebase calls includes/fcm.php's
 * fcmSend() yet, and even a future caller that does gets a guaranteed
 * `not_configured` (or, today, `not_implemented` — see fcm.php's file header)
 * no-op until an owner provisions real FCM/ADM credentials. Running this
 * migration on alpha/beta/production today changes nothing observable — it
 * only creates an empty table nothing yet reads from.
 *
 * The migration lands via the NORMAL (non-manual) migration card — creating
 * an empty, unused table is purely additive and carries none of the
 * destructive-drop risk that earns a migration the 'manual' => true /
 * confirm=1 gate (rule #19).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (table-existence guarded). Pure DDL, so no
 * shared include is required (rule #41: were one ever needed it would
 * resolve via IHYMNS_INCLUDES_DIR, never a hardcoded /public_html/ literal —
 * this migration needs neither, mirroring migrate-add-webhooks.php).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-push-tokens.php
 *   Web:  /manage/setup-database → "Android/FireOS push token registry" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migPushTok_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migPushTok_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migPushTok_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migPushTok_output("");
_migPushTok_output("=== iHymns — Android/FireOS push token registry (API-coverage C1) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migPushTok_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migPushTok_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migPushTok_tableExists($mysql, 'tblPushTokens')) {
        _migPushTok_output("  [SKIP] tblPushTokens already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblPushTokens (
                Id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Provider   VARCHAR(16)    NOT NULL DEFAULT 'fcm' COMMENT 'fcm | adm — app-validated, VARCHAR not ENUM (rule #20); FCM = Google Firebase Cloud Messaging (ordinary Android), ADM = Amazon Device Messaging (Fire OS — no Google Play Services)',
                UserId     INT UNSIGNED   NOT NULL COMMENT 'FK tblUsers — owning user (this endpoint is always authenticated; unlike tblApnsTokens there is no anonymous/presence-scoped token here)',
                Token      VARCHAR(191)   NOT NULL COMMENT 'Opaque FCM registration token or ADM registration id. VARCHAR(191) keeps the utf8mb4 UNIQUE index under the legacy 767-byte-per-column InnoDB limit (mirrors tblSongExternalIds.IdValue)',
                Platform   VARCHAR(20)    NULL DEFAULT NULL COMMENT 'Client-reported platform, e.g. android | fireos — optional, informational only, never gates anything',
                AppVersion VARCHAR(20)    NULL DEFAULT NULL COMMENT 'Client app version string at register/last-seen time, e.g. 1.4.2',
                CreatedAt  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                LastSeenAt TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Refreshed on every re-register (ON DUPLICATE KEY UPDATE) so a future sender can prune stale tokens',

                UNIQUE KEY uq_Provider_Token (Provider, Token),
                INDEX      idx_User (UserId),

                CONSTRAINT fk_PushTokens_User
                    FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Android/FireOS push registration tokens (API-coverage 2026-08-28 C1). Provider discriminates FCM vs ADM. Entirely dormant until includes/fcm.php is keyed AND a live trigger calls fcmSend() — neither is true yet.'"
        );
        _migPushTok_output("  [OK] Created tblPushTokens.");
    }

    _migPushTok_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migPushTok_output("ERROR: " . $e->getMessage());
}
